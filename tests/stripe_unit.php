<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/stripe.php';

$failures = 0;
$checks = 0;

function check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$config = [
    'stripe_currency' => 'usd',
    'stripe_ticket_catalog' => [
        'weekend' => ['name' => 'Weekend Pass', 'price_cents' => 6500, 'description' => 'Three-day admission'],
        'vip' => ['name' => 'VIP Pass', 'price_cents' => 14500, 'description' => 'Weekend admission plus VIP benefits'],
    ],
];

$catalog = stripeTicketCatalog($config);
check(isset($catalog['weekend']), 'configured weekend SKU is available');
check($catalog['weekend']['price_cents'] === 6500, 'catalog keeps server-owned integer price');
check($catalog['weekend']['currency'] === 'usd', 'catalog uses configured currency');
check(!array_key_exists('secret', $catalog['weekend']), 'public catalog has no secret fields');

$items = stripeNormalizeCheckoutItems([
    ['sku' => 'weekend', 'quantity' => 2, 'price_cents' => 1],
    ['sku' => 'vip', 'quantity' => 1, 'price_cents' => 1],
], $catalog);
check(count($items) === 2, 'two valid SKUs normalize');
check($items[0]['price_cents'] === 6500, 'client price is ignored in favor of server catalog');
check($items[0]['quantity'] === 2, 'valid quantity is retained');
check(stripeCheckoutTotal($items) === 27500, 'checkout total is calculated from server prices');

$rejectedUnknown = false;
try {
    stripeNormalizeCheckoutItems([['sku' => 'made-up', 'quantity' => 1]], $catalog);
} catch (InvalidArgumentException $e) {
    $rejectedUnknown = true;
}
check($rejectedUnknown, 'unknown SKU is rejected');

$rejectedQuantity = false;
try {
    stripeNormalizeCheckoutItems([['sku' => 'weekend', 'quantity' => 11]], $catalog);
} catch (InvalidArgumentException $e) {
    $rejectedQuantity = true;
}
check($rejectedQuantity, 'quantity above the per-order cap is rejected');

$payload = '{"id":"evt_test","type":"checkout.session.completed"}';
$secret = 'whsec_test_secret';
$timestamp = 1720000000;
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
$header = "t={$timestamp},v1={$signature}";
check(stripeVerifyWebhookSignature($payload, $header, $secret, $timestamp + 120), 'valid current webhook signature passes');
check(!stripeVerifyWebhookSignature($payload . 'x', $header, $secret, $timestamp + 120), 'tampered payload fails signature verification');
check(!stripeVerifyWebhookSignature($payload, $header, $secret, $timestamp + 600), 'stale webhook timestamp fails');
check(!stripeVerifyWebhookSignature($payload, 't=bad,v1=bad', $secret, $timestamp), 'malformed signature header fails closed');

$ticketCode = stripeGenerateTicketCode();
check((bool)preg_match('/^DH-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $ticketCode), 'ticket code has the documented high-entropy display format');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} Stripe checks failed.\n");
    exit(1);
}

echo "{$checks} Stripe checks passed.\n";
