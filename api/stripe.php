<?php
declare(strict_types=1);

/**
 * Return the server-owned ticket catalog in a shape that is safe to expose.
 *
 * Invalid or disabled catalog rows are ignored so a single deployment typo
 * does not take down the public event page. Checkout still fails closed for
 * any SKU that is not present in this normalized catalog.
 */
function stripeTicketCatalog(array $config): array {
    $currency = strtolower(trim((string)($config['stripe_currency'] ?? 'usd')));
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
        $currency = 'usd';
    }

    $configured = $config['stripe_ticket_catalog'] ?? [];
    if (!is_array($configured)) return [];

    $catalog = [];
    foreach ($configured as $sku => $ticket) {
        if (!is_string($sku) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $sku) || !is_array($ticket)) {
            continue;
        }
        if (array_key_exists('enabled', $ticket) && !$ticket['enabled']) continue;

        $name = trim((string)($ticket['name'] ?? ''));
        $description = trim((string)($ticket['description'] ?? ''));
        $priceCents = filter_var($ticket['price_cents'] ?? null, FILTER_VALIDATE_INT);
        $maxQuantity = filter_var($ticket['max_quantity'] ?? 10, FILTER_VALIDATE_INT);
        if ($name === '' || $priceCents === false || $priceCents < 50 || $priceCents > 100000000) {
            continue;
        }
        if ($maxQuantity === false || $maxQuantity < 1 || $maxQuantity > 10) {
            $maxQuantity = 10;
        }

        $catalog[$sku] = [
            'sku' => $sku,
            'name' => substr($name, 0, 160),
            'description' => substr($description, 0, 500),
            'price_cents' => (int)$priceCents,
            'currency' => $currency,
            'max_quantity' => (int)$maxQuantity,
        ];
    }
    return $catalog;
}

/**
 * Normalize browser-selected SKUs against the server catalog.
 *
 * Any client-supplied price, name, currency, metadata, or redirect fields are
 * intentionally discarded.
 */
function stripeNormalizeCheckoutItems(array $requestedItems, array $catalog): array {
    if (!$requestedItems || count($requestedItems) > 5) {
        throw new InvalidArgumentException('Choose between one and five ticket types.');
    }

    $quantities = [];
    foreach ($requestedItems as $requested) {
        if (!is_array($requested)) {
            throw new InvalidArgumentException('Invalid ticket selection.');
        }
        $sku = trim((string)($requested['sku'] ?? ''));
        if ($sku === '' || !isset($catalog[$sku])) {
            throw new InvalidArgumentException('That ticket type is not available.');
        }
        $quantity = filter_var($requested['quantity'] ?? null, FILTER_VALIDATE_INT);
        $maximum = min(10, (int)($catalog[$sku]['max_quantity'] ?? 10));
        if ($quantity === false || $quantity < 1 || $quantity > $maximum) {
            throw new InvalidArgumentException("Choose between 1 and {$maximum} tickets.");
        }
        $quantities[$sku] = ($quantities[$sku] ?? 0) + (int)$quantity;
        if ($quantities[$sku] > $maximum) {
            throw new InvalidArgumentException("Choose between 1 and {$maximum} tickets.");
        }
    }

    if (array_sum($quantities) > 10) {
        throw new InvalidArgumentException('A checkout may contain at most 10 tickets.');
    }

    $items = [];
    foreach ($quantities as $sku => $quantity) {
        $serverTicket = $catalog[$sku];
        $items[] = [
            'sku' => $sku,
            'name' => (string)$serverTicket['name'],
            'description' => (string)($serverTicket['description'] ?? ''),
            'price_cents' => (int)$serverTicket['price_cents'],
            'currency' => (string)$serverTicket['currency'],
            'quantity' => $quantity,
        ];
    }
    return $items;
}

function stripeCheckoutTotal(array $items): int {
    $total = 0;
    foreach ($items as $item) {
        $price = (int)($item['price_cents'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($price < 0 || $quantity < 0 || ($quantity > 0 && $price > intdiv(PHP_INT_MAX, $quantity))) {
            throw new InvalidArgumentException('Invalid checkout total.');
        }
        $lineTotal = $price * $quantity;
        if ($lineTotal > PHP_INT_MAX - $total) {
            throw new InvalidArgumentException('Invalid checkout total.');
        }
        $total += $lineTotal;
    }
    return $total;
}

/**
 * Verify Stripe's signed raw webhook payload with a five-minute tolerance.
 */
function stripeVerifyWebhookSignature(
    string $payload,
    string $signatureHeader,
    string $secret,
    ?int $now = null,
    int $toleranceSeconds = 300
): bool {
    if ($secret === '' || $signatureHeader === '' || $toleranceSeconds < 0) return false;

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if ($pair[0] === 't' && preg_match('/^\d{1,12}$/', $pair[1])) {
            $timestamp = (int)$pair[1];
        } elseif ($pair[0] === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $pair[1])) {
            $signatures[] = strtolower($pair[1]);
        }
    }
    if ($timestamp === null || !$signatures) return false;

    $currentTime = $now ?? time();
    if (abs($currentTime - $timestamp) > $toleranceSeconds) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $candidate) {
        if (hash_equals($expected, $candidate)) return true;
    }
    return false;
}

/**
 * Generate a display-friendly code with about 62 bits of random entropy.
 */
function stripeGenerateTicketCode(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $random = '';
    for ($index = 0; $index < 12; $index++) {
        $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'DH-' . substr($random, 0, 4) . '-' . substr($random, 4, 4) . '-' . substr($random, 8, 4);
}

/**
 * Call Stripe's form-encoded HTTPS API without requiring Composer.
 */
function stripeApiRequest(
    string $secretKey,
    string $path,
    array $fields,
    ?string $idempotencyKey = null
): array {
    if (!str_starts_with($secretKey, 'sk_test_')) {
        throw new RuntimeException('Ticket sales are not configured for Stripe test mode.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Stripe Checkout.');
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($response === false) {
        throw new RuntimeException('Stripe Checkout is temporarily unavailable: ' . $curlError);
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        $stripeMessage = is_array($decoded) ? trim((string)($decoded['error']['message'] ?? '')) : '';
        throw new RuntimeException('Stripe Checkout request failed' . ($stripeMessage !== '' ? ': ' . $stripeMessage : '.'));
    }
    return $decoded;
}
