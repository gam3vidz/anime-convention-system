<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('delta_h_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1. Database Connection
$config = require __DIR__ . '/config.php';
$appTimezone = trim((string)($config['app_timezone'] ?? 'America/Chicago')) ?: 'America/Chicago';
try {
    date_default_timezone_set($appTimezone);
} catch (Throwable $e) {
    date_default_timezone_set('America/Chicago');
}
$host = $config['host'] ?? 'localhost';
$db   = $config['database'] ?? '';
$user = $config['username'] ?? '';
$pass = $config['password'] ?? '';
$charset = $config['charset'] ?? 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred.']);
    exit;
}

// 2. Helper Functions
function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $code = 400): void {
    out(['error' => $message], $code);
}

function requireCsrfToken(): void {
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        fail('Invalid or missing CSRF token.', 403);
    }
}

function logAction(PDO $pdo, ?array $actor, string $action, string $details = ''): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (actor_user_id, actor_name, action, details) VALUES (?,?,?,?)");
        $stmt->execute([
            $actor ? (int)($actor['id'] ?? 0) : null,
            $actor ? (string)($actor['name'] ?? $actor['email'] ?? 'System') : 'System',
            substr($action, 0, 80),
            substr($details, 0, 4000)
        ]);
    } catch (Throwable $e) {
        // Logging should never block scheduling work.
    }
}

function redirectToApp(string $query = ''): void {
    $base = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $url = ($base === '/' ? '' : $base) . '/index.html' . $query;
    header('Location: ' . $url);
    exit;
}

function currentDiscordRedirectUri(array $config): string {
    if (!empty($config['discord_redirect_uri'])) return (string)$config['discord_redirect_uri'];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/api/discord-callback.php';
}

function discordConfig(array $config): array {
    return [
        'clientId' => trim((string)($config['discord_client_id'] ?? '')),
        'clientSecret' => trim((string)($config['discord_client_secret'] ?? '')),
        'redirectUri' => currentDiscordRedirectUri($config),
        'guildId' => trim((string)($config['discord_guild_id'] ?? '')),
        'botToken' => trim((string)($config['discord_bot_token'] ?? '')),
        'volunteerRoleId' => trim((string)($config['discord_volunteer_role_id'] ?? '')),
        'coordinatorRoleId' => trim((string)($config['discord_coordinator_role_id'] ?? '')),
        'guestRelationsRoleId' => trim((string)($config['discord_guest_relations_role_id'] ?? '')),
        'safetyRoleId' => trim((string)($config['discord_safety_role_id'] ?? '')),
        'onDutyRoleId' => trim((string)($config['discord_on_duty_role_id'] ?? '')),
        'vendorHallRoleId' => trim((string)($config['discord_vendor_hall_role_id'] ?? ''))
    ];
}

function requireDiscordConfig(array $config): array {
    $discord = discordConfig($config);
    if ($discord['clientId'] === '' || $discord['clientSecret'] === '') {
        redirectToApp('?discord_error=' . rawurlencode('Discord login is not configured yet.'));
    }
    return $discord;
}

function getDiscordMemberRoles(array $discord, string $discordId): array {
    if ($discord['guildId'] === '' || $discord['botToken'] === '') {
        redirectToApp('?discord_error=' . rawurlencode('Discord server role checking is not configured yet.'));
    }

    $member = httpRequestJson(
        'https://discord.com/api/guilds/' . rawurlencode($discord['guildId']) . '/members/' . rawurlencode($discordId),
        null,
        ['Authorization: Bot ' . $discord['botToken']]
    );

    return array_map('strval', (array)($member['roles'] ?? []));
}

function addDiscordGuildMember(array $discord, string $discordId, string $accessToken): void {
    if ($discord['guildId'] === '' || $discord['botToken'] === '') {
        redirectToApp('?discord_error=' . rawurlencode('Discord auto-join is not configured yet.'));
    }

    httpRequestJson(
        'https://discord.com/api/guilds/' . rawurlencode($discord['guildId']) . '/members/' . rawurlencode($discordId),
        ['access_token' => $accessToken],
        ['Authorization: ' . implode('', ['Bear', 'er ']) . $discord['botToken']],
        'PUT',
        true
    );
}

function setDiscordOnDutyRole(array $discord, string $discordId, bool $onDuty): void {
    if ($discord['guildId'] === '' || $discord['botToken'] === '' || $discord['onDutyRoleId'] === '') {
        throw new RuntimeException('Discord On Duty role is not configured yet.');
    }
    if ($discordId === '') {
        throw new RuntimeException('This volunteer does not have a linked Discord account.');
    }

    $url = 'https://discord.com/api/guilds/' . rawurlencode($discord['guildId'])
        . '/members/' . rawurlencode($discordId)
        . '/roles/' . rawurlencode($discord['onDutyRoleId']);
    $authHeader = 'Authorization: ' . implode('', ['Bear', 'er ']) . $discord['botToken'];
    try {
        httpRequestJson($url, null, [$authHeader], $onDuty ? 'PUT' : 'DELETE');
    } catch (RuntimeException $error) {
        $verb = $onDuty ? 'assign' : 'remove';
        throw new RuntimeException('Discord could not ' . $verb . ' the On Duty role. Verify the bot has Manage Roles and its highest role sits above On Duty.');
    }
}

function sendDiscordDm(array $discord, string $discordId, string $message): void {
    if ($discord['botToken'] === '') throw new RuntimeException("Discord bot token is not configured.");
    $message = trim($message);
    if ($discordId === '') throw new RuntimeException("That volunteer does not have a linked Discord account.");
    if ($message === '') throw new RuntimeException("Enter a message to send.");
    if (strlen($message) > 1800) throw new RuntimeException("Keep Discord DMs under 1800 characters.");

    $channel = httpRequestJson(
        'https://discord.com/api/users/@me/channels',
        ['recipient_id' => $discordId],
        ['Authorization: Bot ' . $discord['botToken']],
        'POST',
        true
    );
    $channelId = (string)($channel['id'] ?? '');
    if ($channelId === '') fail("Discord did not create a DM channel.");

    httpRequestJson(
        'https://discord.com/api/channels/' . rawurlencode($channelId) . '/messages',
        ['content' => $message],
        ['Authorization: Bot ' . $discord['botToken']],
        'POST',
        true
    );
}

function discordRoleChecksEnabled(array $discord): bool {
    return $discord['volunteerRoleId'] !== ''
        || $discord['coordinatorRoleId'] !== ''
        || $discord['guestRelationsRoleId'] !== ''
        || $discord['safetyRoleId'] !== '';
}

function hasDiscordRole(array $roles, string $roleId): bool {
    return $roleId !== '' && in_array($roleId, $roles, true);
}

function userDiscordRoles(array $user): array {
    $roles = json_decode((string)($user['discord_roles_json'] ?? '[]'), true);
    return is_array($roles) ? array_map('strval', $roles) : [];
}

function isGuestRelationsRow(array $user, array $config): bool {
    $roleId = trim((string)($config['discord_guest_relations_role_id'] ?? ''));
    return $roleId !== '' && hasDiscordRole(userDiscordRoles($user), $roleId);
}

function requireGuestRelations(PDO $pdo, array $config): array {
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    if (!$currentId) fail("Please log in first.", 401);
    $user = getUserRow($pdo, $currentId);
    if (!$user || !isGuestRelationsRow($user, $config)) {
        fail("Guest Relations Discord role required.", 403);
    }
    return $user;
}

function isSafetyRow(array $user, array $config): bool {
    if (isFullAdminRow($user)) return true;
    $roleId = trim((string)($config['discord_safety_role_id'] ?? ''));
    if ($roleId !== '') {
        return hasDiscordRole(userDiscordRoles($user), $roleId);
    }

    // Department fallback keeps Safety usable while the Discord role id is being configured.
    return ($user['status'] ?? '') === 'approved'
        && (string)($user['department'] ?? '') === 'Safety';
}

function requireSafety(PDO $pdo, array $config): array {
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    if (!$currentId) fail("Please log in first.", 401);
    $user = getUserRow($pdo, $currentId);
    if (!$user || !isSafetyRow($user, $config)) {
        fail("Safety access required.", 403);
    }
    return $user;
}

function vendorHallSpotCodes(): array {
    $codes = [];
    foreach (['A', 'B', 'C', 'D'] as $section) {
        for ($n = 1; $n <= 12; $n++) {
            $codes[] = $section . $n;
        }
    }
    return $codes;
}

function isValidVendorHallSpot(string $spotCode): bool {
    return in_array($spotCode, vendorHallSpotCodes(), true);
}

function isVendorHallRow(array $user, array $config): bool {
    if (($user['status'] ?? '') !== 'approved') return false;
    if ((int)($user['blacklisted'] ?? 0) === 1) return false;
    if (isFullAdminRow($user)) return true;
    $roleId = trim((string)($config['discord_vendor_hall_role_id'] ?? ''));
    if ($roleId === '') return false; // Fail closed: no configured role means no non-admin access.
    return hasDiscordRole(userDiscordRoles($user), $roleId);
}

function requireVendorHall(PDO $pdo, array $config): array {
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    if (!$currentId) fail("Please log in first.", 401);
    $user = getUserRow($pdo, $currentId);
    if (!$user || !isVendorHallRow($user, $config)) {
        fail("Vendor Hall access required.", 403);
    }
    return $user;
}

function vendorHallAssignments(PDO $pdo): array {
    $rows = $pdo->query("SELECT a.spot_code, a.vendor_name, a.notes, a.updated_by, a.updated_at,
            u.name AS updated_by_name
        FROM vendor_hall_assignments a
        LEFT JOIN users u ON u.id = a.updated_by
        ORDER BY a.spot_code")->fetchAll(PDO::FETCH_ASSOC);
    $assignments = [];
    foreach ($rows as $row) {
        $assignments[] = [
            'spotCode' => (string)$row['spot_code'],
            'vendorName' => (string)$row['vendor_name'],
            'notes' => (string)($row['notes'] ?? ''),
            'updatedBy' => $row['updated_by'] !== null ? (int)$row['updated_by'] : null,
            'updatedByName' => (string)($row['updated_by_name'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $assignments;
}

function saveVendorHallAssignment(PDO $pdo, array $user, array $input): string {
    $spot = strtoupper(trim((string)($input['spotCode'] ?? '')));
    if (!isValidVendorHallSpot($spot)) fail("Choose a valid Vendor Hall position.");
    $vendorName = trim((string)($input['vendorName'] ?? ''));
    if ($vendorName === '') fail("Enter a vendor name before saving.");
    $vendorName = cleanManagementText($vendorName, 160);
    $notes = cleanManagementText($input['notes'] ?? '', 2000);
    $stmt = $pdo->prepare("INSERT INTO vendor_hall_assignments (spot_code, vendor_name, notes, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE vendor_name = VALUES(vendor_name), notes = VALUES(notes), updated_by = VALUES(updated_by)");
    $stmt->execute([$spot, $vendorName, $notes, (int)$user['id']]);
    return $spot;
}

function clearVendorHallAssignment(PDO $pdo, array $input): string {
    $spot = strtoupper(trim((string)($input['spotCode'] ?? '')));
    if (!isValidVendorHallSpot($spot)) fail("Choose a valid Vendor Hall position.");
    $pdo->prepare("DELETE FROM vendor_hall_assignments WHERE spot_code = ?")->execute([$spot]);
    return $spot;
}

function enforceDiscordRoles(array $discord, array $roles, array $user): void {
    if ($discord['volunteerRoleId'] !== '' && !hasDiscordRole($roles, $discord['volunteerRoleId'])) {
        redirectToApp('?discord_error=' . rawurlencode('Access denied: you need the Volunteer role in Discord.'));
    }

    if (isManagerRow($user) && $discord['coordinatorRoleId'] !== '' && !hasDiscordRole($roles, $discord['coordinatorRoleId'])) {
        redirectToApp('?discord_error=' . rawurlencode('Access denied: management accounts need the Coordinator role in Discord.'));
    }
}

function httpRequestJson(string $url, ?array $postFields = null, array $headers = [], string $method = 'GET', bool $jsonBody = false): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Discord login.');
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($postFields !== null) {
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody ? json_encode($postFields) : http_build_query($postFields));
        $headers[] = $jsonBody ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        $message = $error ?: 'Discord request failed.';
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && !empty($decoded['message'])) {
            $message = (string)$decoded['message'];
        }
        $requestHost = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $isDiscordRequest = $requestHost === 'discord.com' || str_ends_with($requestHost, '.discord.com');
        if ($status === 401 && $isDiscordRequest) {
            $message = 'Discord rejected the bot token. Regenerate the bot token and update config.php.';
        } elseif ($status === 403 && $isDiscordRequest) {
            $message = str_contains($url, '/members/') && $method === 'PUT'
                ? 'Discord blocked auto-join. Make sure the bot is installed and has Create Invite permission.'
                : 'Discord blocked the member role lookup. Make sure the bot is installed in the server and Server Members Intent is enabled.';
        } elseif ($status === 404 && $isDiscordRequest && str_contains($url, '/members/')) {
            $message = 'Discord could not find this user in the configured server. They may need to join the server first.';
        } elseif (in_array($status, [401, 403], true) && !$isDiscordRequest) {
            $message = $message !== 'Discord request failed.'
                ? $message
                : 'The flight provider rejected the request. Check the flight API key, account status, quota, and plan access.';
        }
        throw new RuntimeException($message . ' HTTP ' . $status . '.');
    }
    if (trim((string)$raw) === '') return [];
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Discord returned an invalid response.');
    return $data;
}

function isFullAdminRow(array $user): bool {
    return ($user['role'] ?? '') === 'admin' || ($user['rank'] ?? '') === 'Admin';
}

function isManagerRow(array $user): bool {
    return isFullAdminRow($user) || ($user['role'] ?? '') === 'manager' || in_array($user['rank'] ?? '', ['Admin', 'Coordinator'], true);
}

function getUserRow(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function getUserByDiscordId(PDO $pdo, string $discordId): ?array {
    if ($discordId === '') return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE discord_id = ? LIMIT 1");
    $stmt->execute([$discordId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function lookupClockUser(PDO $pdo, string $lookup): ?array {
    $lookup = trim($lookup);
    if ($lookup === '') return null;
    $like = '%' . $lookup . '%';
    $stmt = $pdo->prepare("SELECT * FROM users
        WHERE LOWER(email) = LOWER(?) OR LOWER(name) = LOWER(?) OR email LIKE ? OR name LIKE ?
        ORDER BY status = 'approved' DESC, name ASC
        LIMIT 1");
    $stmt->execute([$lookup, $lookup, $like, $like]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function canManageDepartment(array $manager, string $department): bool {
    if (isFullAdminRow($manager)) return true;
    return $department !== '' && $department === (string)($manager['department'] ?? '');
}

function requireManager(PDO $pdo): array {
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    if (!$currentId) fail("Please log in first.", 401);

    $user = getUserRow($pdo, $currentId);
    if (!$user || !isManagerRow($user)) {
        fail("Management access required.", 403);
    }

    return $user;
}

function getAppState(PDO $pdo, ?int $userId): array {
    $shifts = $pdo->query("SELECT * FROM shifts")->fetchAll(PDO::FETCH_ASSOC);
    $users = $pdo->query("SELECT * FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $hotelRooms = $pdo->query("SELECT * FROM hotel_rooms ORDER BY room_name")->fetchAll(PDO::FETCH_ASSOC);
    $assignments = $pdo->query("SELECT user_id, shift_id FROM user_shifts")->fetchAll(PDO::FETCH_ASSOC);
    $availabilityRows = $pdo->query("SELECT user_id, available_day, hour_slot FROM user_availability")->fetchAll(PDO::FETCH_ASSOC);

    $userShifts = [];
    foreach ($assignments as $row) {
        $userShifts[(int)$row['user_id']][] = $row['shift_id'];
    }

    $userAvailability = [];
    foreach ($availabilityRows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($userAvailability[$uid])) {
            $userAvailability[$uid] = emptyAvailability();
        }
        $day = $row['available_day'];
        if (isset($userAvailability[$uid][$day])) {
            $userAvailability[$uid][$day][] = $row['hour_slot'];
        }
    }

    $currentUser = null;
    foreach ($users as &$u) {
        unset($u['password_hash']);
        $uid = (int)$u['id'];
        $u['shiftIds'] = $userShifts[$uid] ?? [];
        $u['availability'] = normalizeAvailabilityRanges($userAvailability[$uid] ?? []);
        $u['blacklisted'] = (bool)($u['blacklisted'] ?? false);
        $u['clockedIn'] = (bool)($u['clocked_in'] ?? false);
        $u['clockedAt'] = $u['clocked_at'] ?? "";
        $u['wedLoadout'] = (bool)($u['wed_loadout'] ?? false);
        $u['sunLoadout'] = (bool)($u['sun_loadout'] ?? false);
        $u['hotelCheckedIn'] = (bool)($u['hotel_checked_in'] ?? false);
        $u['shirtPickedUp'] = (bool)($u['shirt_picked_up'] ?? false);
        $u['shirtPickedUpAt'] = $u['shirt_picked_up_at'] ?? "";
        $u['shirtPickedUpBy'] = isset($u['shirt_picked_up_by']) ? (int)$u['shirt_picked_up_by'] : null;
        $u['passwordResetRequested'] = (bool)($u['password_reset_requested'] ?? false);
        $u['passwordResetRequestedAt'] = $u['password_reset_requested_at'] ?? "";
        $u['canGuestRelations'] = isGuestRelationsRow($u, $GLOBALS['config'] ?? []);
        $u['canSafety'] = isSafetyRow($u, $GLOBALS['config'] ?? []);
        $u['canVendorHall'] = isVendorHallRow($u, $GLOBALS['config'] ?? []);

        if ($userId !== null && $uid === $userId) {
            $currentUser = $u;
        }
    }

    $visibleUsers = [];
    $visibleShifts = [];
    if ($currentUser) {
        if (isFullAdminRow($currentUser)) {
            $visibleUsers = $users;
            $visibleShifts = $shifts;
        } elseif (isManagerRow($currentUser)) {
            $dept = (string)($currentUser['department'] ?? '');
            $visibleUsers = array_values(array_filter($users, function(array $user) use ($dept, $currentUser): bool {
                $userDept = (string)($user['department'] ?: ($user['applied_department'] ?? ''));
                return $userDept === $dept || (string)$user['id'] === (string)$currentUser['id'];
            }));
            $visibleShifts = array_values(array_filter($shifts, fn(array $shift): bool => (string)($shift['department'] ?? '') === $dept));
        } else {
            $dept = (string)($currentUser['department'] ?: ($currentUser['applied_department'] ?? ''));
            $visibleUsers = [$currentUser];
            $visibleShifts = array_values(array_filter($shifts, fn(array $shift): bool => $dept === '' || (string)($shift['department'] ?? '') === $dept));
        }
    }

    $logs = [];
    if ($currentUser && isManagerRow($currentUser)) {
        $logs = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC, id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);

        $visibleIds = array_values(array_filter(array_map(fn(array $row): int => (int)($row['id'] ?? 0), $visibleUsers)));
        if ($visibleIds) {
            $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
            $profileStmt = $pdo->prepare("SELECT p.*, editor.name AS updated_by_name
                FROM volunteer_management_profiles p
                LEFT JOIN users editor ON editor.id = p.updated_by
                WHERE p.user_id IN ($placeholders)");
            $profileStmt->execute($visibleIds);
            $profilesByUser = [];
            foreach ($profileStmt->fetchAll(PDO::FETCH_ASSOC) as $profile) {
                $profilesByUser[(int)$profile['user_id']] = $profile;
            }

            $noteStmt = $pdo->prepare("SELECT n.*, author.name AS created_by_name
                FROM volunteer_management_notes n
                LEFT JOIN users author ON author.id = n.created_by
                WHERE n.user_id IN ($placeholders)
                ORDER BY n.created_at DESC, n.id DESC LIMIT 1500");
            $noteStmt->execute($visibleIds);
            $notesByUser = [];
            foreach ($noteStmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
                $notesByUser[(int)$note['user_id']][] = $note;
            }

            $clockStmt = $pdo->prepare("SELECT id, user_id, clock_in_at, clock_out_at, source, note
                FROM time_clock_entries
                WHERE user_id IN ($placeholders)
                ORDER BY clock_in_at DESC, id DESC
                LIMIT 2000");
            $clockStmt->execute($visibleIds);
            $clockEntriesByUser = [];
            foreach ($clockStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $clockEntriesByUser[(int)$entry['user_id']][] = $entry;
            }

            foreach ($visibleUsers as &$visibleUser) {
                $visibleId = (int)$visibleUser['id'];
                $visibleUser['managementProfile'] = $profilesByUser[$visibleId] ?? null;
                $visibleUser['managementNotes'] = $notesByUser[$visibleId] ?? [];
                $visibleUser['timeClockEntries'] = $clockEntriesByUser[$visibleId] ?? [];
            }
            unset($visibleUser);
        }
    }

    $guestFlights = [];
    $pickupStaff = [];
    if ($currentUser && isGuestRelationsRow($currentUser, $GLOBALS['config'] ?? [])) {
        $guestFlights = $pdo->query("SELECT gf.*, assignee.name AS assigned_user_name
            FROM guest_flights gf
            LEFT JOIN users assignee ON assignee.id = gf.assigned_user_id
            ORDER BY gf.flight_date ASC, gf.guest_name ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $pickupCandidates = $pdo->query("SELECT id, name, department, discord_id, discord_roles_json
            FROM users
            WHERE status = 'approved' AND blacklisted = 0 AND discord_id <> ''
            ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $pickupStaff = array_values(array_filter($pickupCandidates, fn(array $candidate): bool =>
            isGuestRelationsRow($candidate, $GLOBALS['config'] ?? [])
        ));
        foreach ($pickupStaff as &$pickupPerson) {
            unset($pickupPerson['discord_roles_json']);
        }
        unset($pickupPerson);
    }

    $incidents = [];
    if ($currentUser && isSafetyRow($currentUser, $GLOBALS['config'] ?? [])) {
        $incidents = $pdo->query("SELECT i.*, creator.name AS created_by_name, editor.name AS updated_by_name
            FROM incidents i
            LEFT JOIN users creator ON creator.id = i.created_by
            LEFT JOIN users editor ON editor.id = i.updated_by
            ORDER BY i.occurred_at DESC, i.id DESC
            LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $evidenceRows = $pdo->query("SELECT e.*, uploader.name AS uploaded_by_name
            FROM incident_evidence e
            LEFT JOIN users uploader ON uploader.id = e.uploaded_by
            ORDER BY e.created_at DESC, e.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $activityRows = $pdo->query("SELECT * FROM incident_activity ORDER BY created_at DESC, id DESC LIMIT 1500")->fetchAll(PDO::FETCH_ASSOC);
        $evidenceByIncident = [];
        $activityByIncident = [];
        foreach ($evidenceRows as $evidence) {
            $evidenceByIncident[(int)$evidence['incident_id']][] = $evidence;
        }
        foreach ($activityRows as $activity) {
            $activityByIncident[(int)$activity['incident_id']][] = $activity;
        }
        foreach ($incidents as &$incident) {
            $incidentId = (int)$incident['id'];
            $incident['medical_attention'] = (bool)$incident['medical_attention'];
            $incident['police_contacted'] = (bool)$incident['police_contacted'];
            $incident['evidence'] = $evidenceByIncident[$incidentId] ?? [];
            $incident['activity'] = $activityByIncident[$incidentId] ?? [];
        }
        unset($incident);
    }

    $alertRules = [];
    $alertDeliveries = [];
    if ($currentUser && isManagerRow($currentUser)) {
        $params = [];
        $where = '';
        if (!isFullAdminRow($currentUser)) {
            $where = ' WHERE s.department = ?';
            $params[] = (string)($currentUser['department'] ?? '');
        }
        $stmt = $pdo->prepare("SELECT r.*, s.title AS shift_title, s.department, s.shift_day, s.shift_time
            FROM shift_alert_rules r
            INNER JOIN shifts s ON s.id = r.shift_id" . $where . "
            ORDER BY r.shift_date ASC, s.shift_time ASC");
        $stmt->execute($params);
        $alertRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($alertRules as &$rule) {
            $recipientIds = json_decode((string)($rule['recipient_user_ids_json'] ?? '[]'), true);
            $rule['recipientUserIds'] = is_array($recipientIds) ? array_values(array_map('intval', $recipientIds)) : [];
            $rule['enabled'] = (bool)$rule['enabled'];
        }
        unset($rule);

        $deliveryWhere = '';
        $deliveryParams = [];
        if (!isFullAdminRow($currentUser)) {
            $deliveryWhere = ' WHERE s.department = ?';
            $deliveryParams[] = (string)($currentUser['department'] ?? '');
        }
        $stmt = $pdo->prepare("SELECT d.*, s.title AS shift_title, s.department,
                volunteer.name AS volunteer_name, recipient.name AS recipient_name
            FROM shift_alert_deliveries d
            INNER JOIN shifts s ON s.id = d.shift_id
            LEFT JOIN users volunteer ON volunteer.id = d.volunteer_user_id
            LEFT JOIN users recipient ON recipient.id = d.recipient_user_id" . $deliveryWhere . "
            ORDER BY d.updated_at DESC, d.id DESC LIMIT 150");
        $stmt->execute($deliveryParams);
        $alertDeliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $vendorHallAssignments = [];
    if ($currentUser && isVendorHallRow($currentUser, $GLOBALS['config'] ?? [])) {
        $vendorHallAssignments = vendorHallAssignments($pdo);
    }

    return [
        'csrfToken' => (string)($_SESSION['csrf_token'] ?? ''),
        'user' => $currentUser,
        'volunteers' => $visibleUsers,
        'shifts' => $visibleShifts,
        'hotelRooms' => $hotelRooms,
        'logs' => $logs,
        'guestFlights' => $guestFlights,
        'pickupStaff' => $pickupStaff,
        'incidents' => $incidents,
        'alertRules' => $alertRules,
        'alertDeliveries' => $alertDeliveries,
        'vendorHallAssignments' => $vendorHallAssignments
    ];
}

function emptyAvailability(): array {
    return ['Wednesday' => null, 'Thursday' => null, 'Friday' => null, 'Saturday' => null, 'Sunday' => null, 'Monday' => null];
}

function isValidAvailabilityTime(string $time): bool {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) return false;
    return true;
}

function availabilityTimeMinutes(string $time): int {
    [$hour, $minute] = array_map('intval', explode(':', $time, 2));
    return ($hour * 60) + $minute;
}

function availabilityTimeLabel(int $minutes): string {
    $minutes = max(0, min(1439, $minutes));
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function legacyAvailabilityMinute(string $value): ?int {
    $value = trim($value);
    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $value, $match)) return null;
    $hour = (int)$match[1];
    $minute = (int)($match[2] ?? 0);
    $period = strtoupper($match[3]);
    if ($hour < 1 || $hour > 12 || $minute > 59) return null;
    if ($period === 'AM' && $hour === 12) $hour = 0;
    if ($period === 'PM' && $hour !== 12) $hour += 12;
    return ($hour * 60) + $minute;
}

function legacyAvailabilityRange(array $values): ?array {
    $starts = [];
    $ends = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        if ($value === 'all-day') return ['allDay' => true, 'start' => '00:00', 'end' => '23:59'];
        if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $value, $range)
            && isValidAvailabilityTime($range[1]) && isValidAvailabilityTime($range[2])) {
            $starts[] = availabilityTimeMinutes($range[1]);
            $ends[] = availabilityTimeMinutes($range[2]);
            continue;
        }
        $minute = legacyAvailabilityMinute($value);
        if ($minute !== null) {
            $starts[] = $minute;
            $ends[] = min(1439, $minute + 60);
        }
    }
    if (!$starts || !$ends) return null;
    $start = min($starts);
    $end = max($ends);
    if ($end <= $start) return null;
    return ['allDay' => false, 'start' => availabilityTimeLabel($start), 'end' => availabilityTimeLabel($end)];
}

function normalizeAvailabilityRanges(array $availability): array {
    $clean = emptyAvailability();
    foreach (array_keys($clean) as $day) {
        $entry = $availability[$day] ?? null;
        if (is_array($entry) && array_key_exists('allDay', $entry)) {
            if (!empty($entry['allDay'])) {
                $clean[$day] = ['allDay' => true, 'start' => '00:00', 'end' => '23:59'];
                continue;
            }
            $start = trim((string)($entry['start'] ?? ''));
            $end = trim((string)($entry['end'] ?? ''));
            if (isValidAvailabilityTime($start) && isValidAvailabilityTime($end)
                && availabilityTimeMinutes($end) > availabilityTimeMinutes($start)) {
                $clean[$day] = ['allDay' => false, 'start' => $start, 'end' => $end];
            }
            continue;
        }
        if (is_array($entry)) {
            $clean[$day] = legacyAvailabilityRange($entry);
        }
    }
    return $clean;
}

function saveAvailability(PDO $pdo, int $userId, array $availability): void {
    $clean = normalizeAvailabilityRanges($availability);
    $pdo->prepare("DELETE FROM user_availability WHERE user_id = ?")->execute([$userId]);
    $insert = $pdo->prepare("INSERT INTO user_availability (user_id, available_day, hour_slot) VALUES (?, ?, ?)");
    foreach ($clean as $day => $range) {
        if (!$range) continue;
        $slot = !empty($range['allDay']) ? 'all-day' : $range['start'] . '-' . $range['end'];
        $insert->execute([$userId, $day, $slot]);
    }
    $stmt = $pdo->prepare("UPDATE users SET availability_json = ? WHERE id = ?");
    $stmt->execute([json_encode($clean), $userId]);
}

function saveSchedule(PDO $pdo, int $userId, array $shiftIds): void {
    $shiftIds = array_values(array_unique(array_filter($shiftIds, 'is_string')));
    $user = getUserRow($pdo, $userId);
    if (!$user) fail("Volunteer not found.");
    $userDept = (string)($user['department'] ?: ($user['applied_department'] ?? ''));
    $allowed = [];
    foreach ($pdo->query("SELECT id, department, capacity FROM shifts")->fetchAll(PDO::FETCH_ASSOC) as $shift) {
        if ($userDept !== '' && (string)($shift['department'] ?? '') !== $userDept) {
            continue;
        }
        $allowed[$shift['id']] = (int)$shift['capacity'];
    }

    foreach ($shiftIds as $shiftId) {
        if (!isset($allowed[$shiftId])) {
            fail("Unknown shift selected.");
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_shifts WHERE shift_id = ? AND user_id <> ?");
        $stmt->execute([$shiftId, $userId]);
        if ((int)$stmt->fetchColumn() >= $allowed[$shiftId]) {
            fail("One of those shifts is now full. Refresh and pick another shift.");
        }
    }

    $pdo->prepare("DELETE FROM user_shifts WHERE user_id = ?")->execute([$userId]);
    $insert = $pdo->prepare("INSERT IGNORE INTO user_shifts (user_id, shift_id) VALUES (?, ?)");
    foreach ($shiftIds as $shiftId) {
        $insert->execute([$userId, $shiftId]);
    }
}

function assignShift(PDO $pdo, array $manager, int $userId, string $shiftId, bool $override): void {
    if ($userId <= 0 || $shiftId === '') fail("Missing assignment details.");

    $stmt = $pdo->prepare("SELECT capacity, department FROM shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) fail("Shift not found.");
    if (!canManageDepartment($manager, (string)($shift['department'] ?? ''))) fail("You can only manage shifts in your department.", 403);
    $capacity = (int)($shift['capacity'] ?? 0);

    $stmt = $pdo->prepare("SELECT id, status, blacklisted, department FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) fail("Volunteer not found.");
    if (!canManageDepartment($manager, (string)($target['department'] ?? ''))) fail("You can only assign volunteers in your department.", 403);
    if (($target['status'] ?? '') !== 'approved' || (int)($target['blacklisted'] ?? 0) === 1) {
        fail("Only approved, active volunteers can be assigned.");
    }

    if (!$override) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_shifts WHERE shift_id = ? AND user_id <> ?");
        $stmt->execute([$shiftId, $userId]);
        if ((int)$stmt->fetchColumn() >= $capacity) {
            fail("This shift is full. Use override to assign anyway.");
        }
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO user_shifts (user_id, shift_id) VALUES (?, ?)");
    $stmt->execute([$userId, $shiftId]);
}

function revokeShift(PDO $pdo, array $manager, int $userId, string $shiftId): void {
    if ($userId <= 0 || $shiftId === '') fail("Missing assignment details.");
    $stmt = $pdo->prepare("SELECT department FROM shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $department = $stmt->fetchColumn();
    if ($department === false) fail("Shift not found.");
    if (!canManageDepartment($manager, (string)$department)) fail("You can only manage shifts in your department.", 403);
    $stmt = $pdo->prepare("DELETE FROM user_shifts WHERE user_id = ? AND shift_id = ?");
    $stmt->execute([$userId, $shiftId]);
}

function deleteShift(PDO $pdo, array $manager, string $shiftId): void {
    if ($shiftId === '') fail("Missing shift id.");

    $stmt = $pdo->prepare("SELECT id, department FROM shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) fail("Shift not found.");
    if (!canManageDepartment($manager, (string)($shift['department'] ?? ''))) fail("You can only delete shifts in your department.", 403);

    $pdo->prepare("DELETE FROM user_shifts WHERE shift_id = ?")->execute([$shiftId]);
    $pdo->prepare("DELETE FROM shifts WHERE id = ?")->execute([$shiftId]);
}

function cleanShiftImportRows(PDO $pdo, array $manager, array $rows): array {
    $validDays = ['Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Monday'];
    $clean = [];
    $errors = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        $rowNumber = (int)($row['rowNumber'] ?? ($index + 2));
        $department = trim((string)($row['department'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        $day = trim((string)($row['day'] ?? ''));
        $time = trim((string)($row['time'] ?? ''));
        $hours = (float)($row['hours'] ?? 0);
        $capacity = (int)($row['capacity'] ?? 0);
        $note = cleanShiftNote((string)($row['note'] ?? ''));

        if ($department === '' && $title === '' && $day === '' && $time === '') continue;
        if (!canManageDepartment($manager, $department)) {
            $errors[] = "Row " . $rowNumber . ": you can only import shifts for your department.";
            continue;
        }
        if ($title === '') {
            $errors[] = "Row " . $rowNumber . ": missing shift title.";
            continue;
        }
        if (!in_array($day, $validDays, true)) {
            $errors[] = "Row " . $rowNumber . ": invalid day.";
            continue;
        }
        if ($time === '' || !preg_match('/^\d{1,2}(?::\d{2})?\s*(AM|PM)\s*-\s*\d{1,2}(?::\d{2})?\s*(AM|PM)$/i', $time)) {
            $errors[] = "Row " . $rowNumber . ": time must look like 8:00 AM - 12:00 PM.";
            continue;
        }
        if ($hours <= 0) {
            $errors[] = "Row " . $rowNumber . ": hours must be greater than 0.";
            continue;
        }
        if ($capacity <= 0) {
            $errors[] = "Row " . $rowNumber . ": capacity must be greater than 0.";
            continue;
        }

        $clean[] = [
            'department' => $department,
            'title' => $title,
            'day' => $day,
            'time' => $time,
            'hours' => $hours,
            'capacity' => $capacity,
            'note' => $note
        ];
    }

    return ['rows' => $clean, 'errors' => $errors];
}

function cleanShiftNote(string $note): string {
    $note = trim(preg_replace('/\s+/', ' ', $note) ?? '');
    return substr($note, 0, 4000);
}

function importShifts(PDO $pdo, array $manager, array $rows): array {
    if (count($rows) > 500) fail("Import 500 shifts or fewer at a time.");
    $result = cleanShiftImportRows($pdo, $manager, $rows);
    $cleanRows = $result['rows'];

    $stmt = $pdo->prepare("INSERT INTO shifts (id, department, title, shift_day, shift_time, hours, capacity, note) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($cleanRows as $row) {
        $stmt->execute([
            'shift-' . substr(md5(uniqid('', true)), 0, 8),
            $row['department'],
            $row['title'],
            $row['day'],
            $row['time'],
            $row['hours'],
            $row['capacity'],
            $row['note']
        ]);
    }

    return ['count' => count($cleanRows), 'errors' => $result['errors']];
}

function createHotelRoom(PDO $pdo, string $roomName, int $capacity, string $gender): void {
    $roomName = trim($roomName);
    $gender = trim($gender);
    if ($roomName === '') fail("Enter a room name.");
    if ($capacity <= 0) fail("Room capacity must be at least 1.");
    if (!in_array($gender, ['', 'Female', 'Male', 'Other'], true)) fail("Invalid room type.");

    $stmt = $pdo->prepare("INSERT INTO hotel_rooms (room_name, capacity, gender) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), gender = VALUES(gender)");
    $stmt->execute([$roomName, $capacity, $gender]);
}

function lookupFlightStatus(array $config, string $flightNumber, string $flightDate): array {
    $key = trim((string)($config['flight_api_key'] ?? ''));
    $url = trim((string)($config['flight_api_url'] ?? ''));
    if ($key === '' || $url === '') {
        return ['flight_status' => 'Saved - flight API not configured'];
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    $requestUrl = $url . $separator . http_build_query([
        'access_key' => $key,
        'flight_iata' => strtoupper($flightNumber),
        'flight_date' => $flightDate
    ]);

    try {
        $data = httpRequestJson($requestUrl);
        $flight = (array)($data['data'][0] ?? []);
        if (!$flight) return ['flight_status' => 'No live flight match found', 'raw_json' => json_encode($data)];
        return [
            'flight_status' => (string)($flight['flight_status'] ?? 'Tracked'),
            'airline' => (string)($flight['airline']['name'] ?? ''),
            'departure_airport' => (string)($flight['departure']['airport'] ?? ''),
            'arrival_airport' => (string)($flight['arrival']['airport'] ?? ''),
            'scheduled_departure' => (string)($flight['departure']['scheduled'] ?? ''),
            'scheduled_arrival' => (string)($flight['arrival']['scheduled'] ?? ''),
            'raw_json' => json_encode($flight)
        ];
    } catch (Throwable $e) {
        error_log('Flight status lookup failed: ' . $e->getMessage());
        return ['flight_status' => 'Flight status lookup is temporarily unavailable.'];
    }
}

function flightPickupMessage(array $flight, string $reason): string {
    $flightNumber = trim((string)($flight['flight_number'] ?? ''));
    $flightDate = trim((string)($flight['flight_date'] ?? ''));
    $status = trim((string)($flight['flight_status'] ?? 'Saved'));
    $arrival = trim((string)($flight['scheduled_arrival'] ?? ''));
    $route = implode(' -> ', array_filter([
        trim((string)($flight['departure_airport'] ?? '')),
        trim((string)($flight['arrival_airport'] ?? ''))
    ]));

    $parts = [
        $reason,
        'Flight: ' . $flightNumber . ($flightDate !== '' ? ' on ' . $flightDate : ''),
        'Status: ' . ($status !== '' ? $status : 'Saved')
    ];
    if ($arrival !== '') $parts[] = 'Scheduled arrival: ' . $arrival;
    if ($route !== '') $parts[] = 'Route: ' . $route;
    $parts[] = 'Sign in to the Guest Relations portal for private guest details.';
    return implode("\n", $parts);
}

function notifyAssignedPickup(PDO $pdo, array $config, int $flightId, string $reason, bool $onlyWhenChanged = false): bool {
    $stmt = $pdo->prepare("SELECT gf.*, u.discord_id
        FROM guest_flights gf
        LEFT JOIN users u ON u.id = gf.assigned_user_id
        WHERE gf.id = ? LIMIT 1");
    $stmt->execute([$flightId]);
    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$flight || empty($flight['assigned_user_id']) || trim((string)($flight['discord_id'] ?? '')) === '') return false;

    $status = substr((string)($flight['flight_status'] ?? ''), 0, 255);
    $arrival = substr((string)($flight['scheduled_arrival'] ?? ''), 0, 80);
    if ($onlyWhenChanged
        && hash_equals((string)($flight['last_notified_status'] ?? ''), $status)
        && hash_equals((string)($flight['last_notified_arrival'] ?? ''), $arrival)) {
        return false;
    }

    try {
        sendDiscordDm(discordConfig($config), (string)$flight['discord_id'], flightPickupMessage($flight, $reason));
        $update = $pdo->prepare("UPDATE guest_flights
            SET last_notified_status = ?, last_notified_arrival = ?, notification_error = ''
            WHERE id = ?");
        $update->execute([$status, $arrival, $flightId]);
        return true;
    } catch (Throwable $e) {
        $error = substr($e->getMessage(), 0, 255);
        $pdo->prepare("UPDATE guest_flights SET notification_error = ? WHERE id = ?")->execute([$error, $flightId]);
        return false;
    }
}

function saveGuestFlight(PDO $pdo, array $config, array $actor, array $input): void {
    $guestName = trim((string)($input['guestName'] ?? ''));
    $confirmationNumber = strtoupper(trim((string)($input['confirmationNumber'] ?? '')));
    $flightNumber = strtoupper(trim((string)($input['flightNumber'] ?? '')));
    $flightDate = trim((string)($input['flightDate'] ?? ''));
    $assignedUserId = (int)($input['assignedUserId'] ?? 0);
    if ($guestName === '') fail("Enter the guest name.");
    if ($confirmationNumber === '') fail("Enter the airline confirmation number.");
    if (strlen($confirmationNumber) > 80) fail("Confirmation number must be 80 characters or fewer.");
    if ($flightNumber === '') fail("Enter the flight number.");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $flightDate)) fail("Choose a valid flight date.");
    if ($assignedUserId <= 0) fail("Choose the person assigned to this pickup.");
    $assignee = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'approved' AND blacklisted = 0 AND discord_id <> ''");
    $assignee->execute([$assignedUserId]);
    $assigneeRow = $assignee->fetch(PDO::FETCH_ASSOC);
    if (!$assigneeRow || !isGuestRelationsRow($assigneeRow, $config)) {
        fail("Choose an active person with the Guest Relations Discord role.");
    }

    $status = lookupFlightStatus($config, $flightNumber, $flightDate);
    $stmt = $pdo->prepare("INSERT INTO guest_flights
        (guest_name, confirmation_number, flight_number, flight_date, flight_status, airline, departure_airport, arrival_airport, scheduled_departure, scheduled_arrival, assigned_user_id, raw_json, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $guestName,
        $confirmationNumber,
        $flightNumber,
        $flightDate,
        substr((string)($status['flight_status'] ?? ''), 0, 255),
        $status['airline'] ?? '',
        $status['departure_airport'] ?? '',
        $status['arrival_airport'] ?? '',
        $status['scheduled_departure'] ?? '',
        $status['scheduled_arrival'] ?? '',
        $assignedUserId,
        $status['raw_json'] ?? null,
        (int)($actor['id'] ?? 0)
    ]);
    notifyAssignedPickup($pdo, $config, (int)$pdo->lastInsertId(), 'You have been assigned an airport pickup.');
}

function refreshGuestFlights(PDO $pdo, array $config): array {
    $rows = $pdo->query("SELECT id, flight_number, flight_date FROM guest_flights
        WHERE flight_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
        ORDER BY flight_date ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    $notified = 0;
    $stmt = $pdo->prepare("UPDATE guest_flights SET flight_status = ?, airline = ?, departure_airport = ?,
        arrival_airport = ?, scheduled_departure = ?, scheduled_arrival = ?, raw_json = ? WHERE id = ?");
    foreach ($rows as $row) {
        $status = lookupFlightStatus($config, (string)$row['flight_number'], (string)$row['flight_date']);
        $stmt->execute([
            substr((string)($status['flight_status'] ?? ''), 0, 255),
            $status['airline'] ?? '',
            $status['departure_airport'] ?? '',
            $status['arrival_airport'] ?? '',
            $status['scheduled_departure'] ?? '',
            $status['scheduled_arrival'] ?? '',
            $status['raw_json'] ?? null,
            (int)$row['id']
        ]);
        $updated++;
        if (notifyAssignedPickup($pdo, $config, (int)$row['id'], 'Your assigned pickup flight has changed.', true)) $notified++;
    }
    return ['updated' => $updated, 'notified' => $notified];
}

function updateGuestFlightAssignee(PDO $pdo, array $config, int $flightId, int $assignedUserId): void {
    if ($flightId <= 0 || $assignedUserId <= 0) fail("Choose a valid flight and pickup person.");
    $assignee = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'approved' AND blacklisted = 0 AND discord_id <> ''");
    $assignee->execute([$assignedUserId]);
    $assigneeRow = $assignee->fetch(PDO::FETCH_ASSOC);
    if (!$assigneeRow || !isGuestRelationsRow($assigneeRow, $config)) {
        fail("Choose an active person with the Guest Relations Discord role.");
    }
    $stmt = $pdo->prepare("UPDATE guest_flights SET assigned_user_id = ?, last_notified_status = '',
        last_notified_arrival = '', notification_error = '' WHERE id = ?");
    $stmt->execute([$assignedUserId, $flightId]);
    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM guest_flights WHERE id = ?");
        $exists->execute([$flightId]);
        if (!(int)$exists->fetchColumn()) fail("Flight not found.", 404);
    }
    notifyAssignedPickup($pdo, $config, $flightId, 'You have been assigned an airport pickup.');
}

function requireManageableVolunteer(PDO $pdo, array $manager, int $userId): array {
    $target = getUserRow($pdo, $userId);
    if (!$target) fail("Volunteer not found.", 404);
    if (isFullAdminRow($target) && !isFullAdminRow($manager)) {
        fail("Only Admin can manage another Admin account.", 403);
    }
    $targetDept = (string)($target['department'] ?: ($target['applied_department'] ?? ''));
    if (!canManageDepartment($manager, $targetDept)) {
        fail("You can only manage volunteer profiles in your department.", 403);
    }
    return $target;
}

function cleanManagementText(mixed $value, int $limit = 4000): string {
    $text = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

function saveVolunteerManagementProfile(PDO $pdo, array $manager, array $input): array {
    $userId = (int)($input['userId'] ?? 0);
    $target = requireManageableVolunteer($pdo, $manager, $userId);
    $recommendations = ['Undecided', 'Strongly invite back', 'Invite back', 'Invite with coaching', 'Do not invite back'];
    $recommendation = trim((string)($input['recommendation'] ?? 'Undecided'));
    if (!in_array($recommendation, $recommendations, true)) fail("Choose a valid next-year recommendation.");
    $stmt = $pdo->prepare("INSERT INTO volunteer_management_profiles
        (user_id, strengths, growth_areas, next_year_recommendation, private_summary, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE strengths = VALUES(strengths), growth_areas = VALUES(growth_areas),
        next_year_recommendation = VALUES(next_year_recommendation), private_summary = VALUES(private_summary),
        updated_by = VALUES(updated_by)");
    $stmt->execute([
        $userId,
        cleanManagementText($input['strengths'] ?? ''),
        cleanManagementText($input['growthAreas'] ?? ''),
        $recommendation,
        cleanManagementText($input['privateSummary'] ?? ''),
        (int)$manager['id']
    ]);
    return $target;
}

function addVolunteerManagementNote(PDO $pdo, array $manager, array $input): array {
    $userId = (int)($input['userId'] ?? 0);
    $target = requireManageableVolunteer($pdo, $manager, $userId);
    $types = ['General', 'Strength', 'Coaching', 'Attendance', 'Leadership follow-up', 'Incident follow-up'];
    $type = trim((string)($input['noteType'] ?? 'General'));
    if (!in_array($type, $types, true)) fail("Choose a valid note type.");
    $year = (int)($input['eventYear'] ?? date('Y'));
    if ($year < 2020 || $year > 2100) fail("Choose a valid event year.");
    $text = cleanManagementText($input['noteText'] ?? '');
    if ($text === '') fail("Enter a note before saving.");
    $stmt = $pdo->prepare("INSERT INTO volunteer_management_notes
        (user_id, note_type, event_year, note_text, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $type, $year, $text, (int)$manager['id']]);
    return $target;
}

function recordIncidentActivity(PDO $pdo, int $incidentId, array $actor, string $action, string $details = ''): void {
    $stmt = $pdo->prepare("INSERT INTO incident_activity (incident_id, actor_user_id, actor_name, action, details) VALUES (?,?,?,?,?)");
    $stmt->execute([
        $incidentId,
        (int)($actor['id'] ?? 0) ?: null,
        substr((string)($actor['name'] ?? $actor['email'] ?? 'Safety'), 0, 160),
        substr($action, 0, 80),
        substr($details, 0, 4000)
    ]);
}

function normalizeIncidentInput(array $input): array {
    $types = ['Medical', 'Injury', 'Security', 'Lost child', 'Harassment', 'Property damage', 'Crowd / line', 'Other'];
    $severities = ['Low', 'Moderate', 'High', 'Critical'];
    $statuses = ['Open', 'Investigating', 'Monitoring', 'Resolved', 'Closed'];

    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $occurredRaw = trim((string)($input['occurredAt'] ?? ''));
    if ($title === '') fail("Enter an incident title.");
    if ($description === '') fail("Describe what happened.");
    if ($occurredRaw === '') fail("Enter when the incident occurred.");
    try {
        $occurredAt = (new DateTimeImmutable($occurredRaw))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        fail("Enter a valid incident date and time.");
    }

    $type = (string)($input['incidentType'] ?? 'Other');
    $severity = (string)($input['severity'] ?? 'Low');
    $status = (string)($input['status'] ?? 'Open');
    if (!in_array($type, $types, true)) $type = 'Other';
    if (!in_array($severity, $severities, true)) $severity = 'Low';
    if (!in_array($status, $statuses, true)) $status = 'Open';

    return [
        'title' => substr($title, 0, 180),
        'incident_type' => $type,
        'severity' => $severity,
        'status' => $status,
        'occurred_at' => $occurredAt,
        'location' => substr(trim((string)($input['location'] ?? '')), 0, 180),
        'description' => substr($description, 0, 30000),
        'actions_taken' => substr(trim((string)($input['actionsTaken'] ?? '')), 0, 30000),
        'people_involved' => substr(trim((string)($input['peopleInvolved'] ?? '')), 0, 30000),
        'witnesses' => substr(trim((string)($input['witnesses'] ?? '')), 0, 30000),
        'medical_attention' => !empty($input['medicalAttention']) ? 1 : 0,
        'police_contacted' => !empty($input['policeContacted']) ? 1 : 0
    ];
}

function saveIncident(PDO $pdo, array $actor, array $input): int {
    $incidentId = (int)($input['id'] ?? 0);
    $clean = normalizeIncidentInput($input);
    if ($incidentId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ?");
        $stmt->execute([$incidentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) fail("Incident not found.", 404);

        $changed = [];
        foreach ($clean as $field => $value) {
            if ((string)($existing[$field] ?? '') !== (string)$value) $changed[] = str_replace('_', ' ', $field);
        }
        $stmt = $pdo->prepare("UPDATE incidents SET title=?, incident_type=?, severity=?, status=?, occurred_at=?, location=?,
            description=?, actions_taken=?, people_involved=?, witnesses=?, medical_attention=?, police_contacted=?, updated_by=? WHERE id=?");
        $stmt->execute([
            $clean['title'], $clean['incident_type'], $clean['severity'], $clean['status'], $clean['occurred_at'], $clean['location'],
            $clean['description'], $clean['actions_taken'], $clean['people_involved'], $clean['witnesses'], $clean['medical_attention'],
            $clean['police_contacted'], (int)$actor['id'], $incidentId
        ]);
        recordIncidentActivity($pdo, $incidentId, $actor, 'Incident updated', $changed ? 'Changed: ' . implode(', ', $changed) . '.' : 'Saved without field changes.');
        return $incidentId;
    }

    $incidentNumber = 'DH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare("INSERT INTO incidents (incident_number, title, incident_type, severity, status, occurred_at, location,
        description, actions_taken, people_involved, witnesses, medical_attention, police_contacted, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $incidentNumber, $clean['title'], $clean['incident_type'], $clean['severity'], $clean['status'], $clean['occurred_at'],
        $clean['location'], $clean['description'], $clean['actions_taken'], $clean['people_involved'], $clean['witnesses'],
        $clean['medical_attention'], $clean['police_contacted'], (int)$actor['id'], (int)$actor['id']
    ]);
    $incidentId = (int)$pdo->lastInsertId();
    recordIncidentActivity($pdo, $incidentId, $actor, 'Incident created', $incidentNumber . ' created with ' . $clean['severity'] . ' severity.');
    return $incidentId;
}

function requireIncident(PDO $pdo, int $incidentId): array {
    if ($incidentId <= 0) fail("Choose an incident first.");
    $stmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ?");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) fail("Incident not found.", 404);
    return $incident;
}

function addIncidentUrlEvidence(PDO $pdo, array $actor, array $input): int {
    $incidentId = (int)($input['incidentId'] ?? 0);
    $incident = requireIncident($pdo, $incidentId);
    $url = trim((string)($input['url'] ?? ''));
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) fail("Enter a valid evidence URL.");
    $parts = parse_url($url);
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') fail("Evidence links must use HTTPS.");
    $caption = substr(trim((string)($input['caption'] ?? '')), 0, 500);
    $stmt = $pdo->prepare("INSERT INTO incident_evidence (incident_id, evidence_type, source_url, caption, uploaded_by) VALUES (?,'external',?,?,?)");
    $stmt->execute([$incidentId, $url, $caption, (int)$actor['id']]);
    recordIncidentActivity($pdo, $incidentId, $actor, 'Evidence link attached', ($caption ?: 'External evidence') . ' attached to ' . $incident['incident_number'] . '.');
    return (int)$pdo->lastInsertId();
}

function uploadIncidentEvidence(PDO $pdo, array $actor): int {
    $incidentId = (int)($_POST['incidentId'] ?? 0);
    $incident = requireIncident($pdo, $incidentId);
    if (empty($_FILES['evidence']) || !is_array($_FILES['evidence'])) fail("Choose a photo or PDF to upload.");
    $file = $_FILES['evidence'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail("The evidence upload did not complete.");
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) fail("Evidence files must be 12 MB or smaller.");

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf'
    ];
    if (!isset($extensions[$mime])) fail("Upload a JPG, PNG, WebP, GIF, or PDF evidence file.");

    $uploadDirectory = __DIR__ . '/uploads/incidents';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        fail("The evidence storage directory could not be created.", 500);
    }
    $storedName = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    $destination = $uploadDirectory . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $destination)) fail("The evidence file could not be stored.", 500);

    $originalName = trim((string)($file['name'] ?? 'evidence.' . $extensions[$mime]));
    $originalName = substr(preg_replace('/[^A-Za-z0-9._ -]+/', '_', $originalName) ?: 'evidence.' . $extensions[$mime], 0, 255);
    $caption = substr(trim((string)($_POST['caption'] ?? '')), 0, 500);
    $relativePath = 'uploads/incidents/' . $storedName;
    $stmt = $pdo->prepare("INSERT INTO incident_evidence (incident_id, evidence_type, file_name, file_path, mime_type, size_bytes, caption, uploaded_by)
        VALUES (?,'upload',?,?,?,?,?,?)");
    $stmt->execute([$incidentId, $originalName, $relativePath, $mime, $size, $caption, (int)$actor['id']]);
    recordIncidentActivity($pdo, $incidentId, $actor, 'Evidence uploaded', $originalName . ' attached to ' . $incident['incident_number'] . '.');
    return (int)$pdo->lastInsertId();
}

function evidenceStoragePath(string $relativePath): ?string {
    $storageRoot = realpath(__DIR__ . '/uploads/incidents');
    $fullPath = realpath(__DIR__ . '/' . ltrim($relativePath, '/'));
    if ($storageRoot === false || $fullPath === false) return null;
    return str_starts_with($fullPath, $storageRoot . DIRECTORY_SEPARATOR) ? $fullPath : null;
}

function removeIncidentEvidence(PDO $pdo, array $actor, int $evidenceId): void {
    $stmt = $pdo->prepare("SELECT e.*, i.incident_number FROM incident_evidence e INNER JOIN incidents i ON i.id = e.incident_id WHERE e.id = ?");
    $stmt->execute([$evidenceId]);
    $evidence = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evidence) fail("Evidence attachment not found.", 404);
    if (($evidence['evidence_type'] ?? '') === 'upload') {
        $path = evidenceStoragePath((string)$evidence['file_path']);
        if ($path && is_file($path)) @unlink($path);
    }
    $pdo->prepare("DELETE FROM incident_evidence WHERE id = ?")->execute([$evidenceId]);
    recordIncidentActivity($pdo, (int)$evidence['incident_id'], $actor, 'Evidence removed', (string)($evidence['file_name'] ?: $evidence['caption'] ?: 'Attachment') . ' removed.');
}

function streamIncidentEvidence(PDO $pdo, int $evidenceId): void {
    $stmt = $pdo->prepare("SELECT * FROM incident_evidence WHERE id = ? AND evidence_type = 'upload'");
    $stmt->execute([$evidenceId]);
    $evidence = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evidence) fail("Evidence file not found.", 404);
    $path = evidenceStoragePath((string)$evidence['file_path']);
    if (!$path || !is_file($path)) fail("Evidence file is missing from storage.", 404);

    $fileName = str_replace(["\r", "\n", '"'], '', (string)($evidence['file_name'] ?: basename($path)));
    header('Content-Type: ' . ((string)$evidence['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function saveShiftAlertRule(PDO $pdo, array $manager, array $input): int {
    $shiftId = trim((string)($input['shiftId'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) fail("Choose a valid shift.");
    if (!canManageDepartment($manager, (string)$shift['department'])) fail("You can only configure alerts for your department.", 403);

    $date = trim((string)($input['shiftDate'] ?? ''));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) fail("Choose the actual event date for this shift.");
    if (!empty($shift['shift_day']) && $parsedDate->format('l') !== (string)$shift['shift_day']) {
        fail("That date is a " . $parsedDate->format('l') . ", but this shift is labeled " . (string)$shift['shift_day'] . ".");
    }
    $grace = (int)($input['graceMinutes'] ?? 5);
    if ($grace < 1 || $grace > 60) fail("Grace period must be between 1 and 60 minutes.");

    $recipientIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['recipientUserIds'] ?? [])), fn(int $id): bool => $id > 0)));
    if (!$recipientIds) fail("Choose at least one Discord alert recipient.");
    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $recipientStmt = $pdo->prepare("SELECT id, name, department, discord_id FROM users WHERE id IN ($placeholders) AND status = 'approved' AND blacklisted = 0");
    $recipientStmt->execute($recipientIds);
    $recipients = $recipientStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($recipients) !== count($recipientIds)) fail("One or more alert recipients are unavailable.");
    foreach ($recipients as $recipient) {
        $recipientDept = (string)($recipient['department'] ?? '');
        if (!isFullAdminRow($manager) && $recipientDept !== (string)$manager['department']) {
            fail("Department heads can only select recipients in their department.", 403);
        }
        if (trim((string)($recipient['discord_id'] ?? '')) === '') {
            fail((string)$recipient['name'] . " has not linked Discord yet.");
        }
    }

    $template = trim((string)($input['messageTemplate'] ?? ''));
    if (strlen($template) > 1800) fail("Keep the alert message under 1800 characters.");
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $json = json_encode($recipientIds);
    $stmt = $pdo->prepare("INSERT INTO shift_alert_rules (shift_id, shift_date, grace_minutes, enabled, recipient_user_ids_json, message_template, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE shift_date=VALUES(shift_date), grace_minutes=VALUES(grace_minutes), enabled=VALUES(enabled),
            recipient_user_ids_json=VALUES(recipient_user_ids_json), message_template=VALUES(message_template), updated_by=VALUES(updated_by)");
    $stmt->execute([$shiftId, $date, $grace, $enabled, $json, $template, (int)$manager['id'], (int)$manager['id']]);
    $idStmt = $pdo->prepare("SELECT id FROM shift_alert_rules WHERE shift_id = ?");
    $idStmt->execute([$shiftId]);
    return (int)$idStmt->fetchColumn();
}

function shiftStartDateTime(string $date, string $timeRange): ?DateTimeImmutable {
    $startText = trim((string)(preg_split('/\s*-\s*/', $timeRange, 2)[0] ?? ''));
    if ($startText === '') return null;
    $value = strtoupper($date . ' ' . $startText);
    foreach (['!Y-m-d G:i', '!Y-m-d g:i A', '!Y-m-d g A'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed instanceof DateTimeImmutable) return $parsed;
    }
    return null;
}

function shiftAlertMessage(array $rule, array $volunteer): string {
    $template = trim((string)($rule['message_template'] ?? ''));
    if ($template === '') {
        $template = "🚨 Missed shift check-in: {volunteer} has not checked in for {shift} ({department}) on {date} at {time}. Grace period: {grace} minutes.";
    }
    $message = strtr($template, [
        '{volunteer}' => (string)($volunteer['name'] ?? 'Volunteer'),
        '{shift}' => (string)($rule['shift_title'] ?? 'scheduled shift'),
        '{department}' => (string)($rule['department'] ?? ''),
        '{date}' => (string)($rule['shift_date'] ?? ''),
        '{day}' => (string)($rule['shift_day'] ?? ''),
        '{time}' => (string)($rule['shift_time'] ?? ''),
        '{grace}' => (string)($rule['grace_minutes'] ?? 5)
    ]);
    return function_exists('mb_substr') ? mb_substr($message, 0, 1800) : substr($message, 0, 1800);
}

function volunteerCheckedInForShift(PDO $pdo, array $volunteer, DateTimeImmutable $shiftStart, DateTimeImmutable $now): bool {
    if (!empty($volunteer['clocked_in'])) return true;
    $windowStart = $shiftStart->modify('-12 hours')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM time_clock_entries
        WHERE user_id = ? AND clock_in_at BETWEEN ? AND ?
        AND (clock_out_at IS NULL OR clock_out_at >= ?)");
    $stmt->execute([
        (int)$volunteer['id'],
        $windowStart,
        $now->format('Y-m-d H:i:s'),
        $shiftStart->format('Y-m-d H:i:s')
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function processShiftAlerts(PDO $pdo, array $config): array {
    $discord = discordConfig($config);
    $now = new DateTimeImmutable('now');
    $rules = $pdo->query("SELECT r.*, s.title AS shift_title, s.department, s.shift_day, s.shift_time
        FROM shift_alert_rules r INNER JOIN shifts s ON s.id = r.shift_id WHERE r.enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
    $result = ['rulesDue' => 0, 'missingVolunteers' => 0, 'sent' => 0, 'failed' => 0, 'skippedCheckedIn' => 0];

    foreach ($rules as $rule) {
        $shiftStart = shiftStartDateTime((string)$rule['shift_date'], (string)$rule['shift_time']);
        if (!$shiftStart) continue;
        $grace = max(1, min(60, (int)$rule['grace_minutes']));
        $alertAt = $shiftStart->modify('+' . $grace . ' minutes');
        if ($now < $alertAt || $now > $alertAt->modify('+90 minutes')) continue;
        $result['rulesDue']++;

        $stmt = $pdo->prepare("SELECT u.* FROM user_shifts us INNER JOIN users u ON u.id = us.user_id
            WHERE us.shift_id = ? AND u.status = 'approved' AND u.blacklisted = 0");
        $stmt->execute([(string)$rule['shift_id']]);
        $volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recipientIds = json_decode((string)($rule['recipient_user_ids_json'] ?? '[]'), true);
        $recipientIds = is_array($recipientIds) ? array_values(array_unique(array_map('intval', $recipientIds))) : [];

        foreach ($volunteers as $volunteer) {
            if (volunteerCheckedInForShift($pdo, $volunteer, $shiftStart, $now)) {
                $result['skippedCheckedIn']++;
                continue;
            }
            $result['missingVolunteers']++;
            $message = shiftAlertMessage($rule, $volunteer);
            foreach ($recipientIds as $recipientId) {
                $recipientStmt = $pdo->prepare("SELECT id, name, discord_id FROM users WHERE id = ? AND status = 'approved' AND blacklisted = 0");
                $recipientStmt->execute([$recipientId]);
                $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                if (!$recipient) continue;

                $deliveryStmt = $pdo->prepare("SELECT * FROM shift_alert_deliveries
                    WHERE rule_id=? AND shift_date=? AND volunteer_user_id=? AND recipient_user_id=?");
                $deliveryStmt->execute([(int)$rule['id'], (string)$rule['shift_date'], (int)$volunteer['id'], $recipientId]);
                $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);
                if ($delivery && ($delivery['status'] ?? '') === 'sent') continue;
                if ($delivery && (int)($delivery['attempts'] ?? 0) >= 3) continue;
                if ($delivery && ($delivery['status'] ?? '') === 'pending') continue;

                if ($delivery) {
                    $retry = $pdo->prepare("UPDATE shift_alert_deliveries SET status='pending', attempts=attempts+1, message=?, last_error=''
                        WHERE id=? AND status='failed' AND attempts < 3");
                    $retry->execute([$message, (int)$delivery['id']]);
                    if ($retry->rowCount() === 0) continue;
                    $deliveryId = (int)$delivery['id'];
                } else {
                    $insert = $pdo->prepare("INSERT IGNORE INTO shift_alert_deliveries
                        (rule_id, shift_id, shift_date, volunteer_user_id, recipient_user_id, status, attempts, message)
                        VALUES (?,?,?,?,?,'pending',1,?)");
                    $insert->execute([(int)$rule['id'], (string)$rule['shift_id'], (string)$rule['shift_date'], (int)$volunteer['id'], $recipientId, $message]);
                    if ($insert->rowCount() === 0) continue;
                    $deliveryId = (int)$pdo->lastInsertId();
                }

                try {
                    sendDiscordDm($discord, (string)($recipient['discord_id'] ?? ''), $message);
                    $pdo->prepare("UPDATE shift_alert_deliveries SET status='sent', sent_at=NOW(), last_error='' WHERE id=?")->execute([$deliveryId]);
                    $result['sent']++;
                } catch (Throwable $e) {
                    $error = substr($e->getMessage(), 0, 1000);
                    $pdo->prepare("UPDATE shift_alert_deliveries SET status='failed', last_error=? WHERE id=?")->execute([$error, $deliveryId]);
                    $result['failed']++;
                }
            }
        }
    }

    return $result;
}

function authorizeShiftAlertProcessor(PDO $pdo, array $config): ?array {
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentId > 0) {
        $user = getUserRow($pdo, $currentId);
        if ($user && isManagerRow($user)) return $user;
    }
    $expected = trim((string)($config['shift_alert_cron_secret'] ?? ''));
    $provided = trim((string)($_SERVER['HTTP_X_DELTA_H_ALERT_KEY'] ?? ''));
    if ($expected !== '' && strlen($expected) >= 16 && $provided !== '' && hash_equals($expected, $provided)) return null;
    fail("Alert processor authorization required.", 403);
}

function formatClockDuration(int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) return $hours . 'h ' . $minutes . 'm';
    return $minutes . 'm';
}

function clockTotals(PDO $pdo, int $userId): array {
    $now = time();
    $stmt = $pdo->prepare("SELECT clock_in_at, clock_out_at FROM time_clock_entries WHERE user_id = ? ORDER BY clock_in_at ASC");
    $stmt->execute([$userId]);
    $total = 0;
    $openSeconds = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $start = strtotime((string)$entry['clock_in_at']);
        if (!$start) continue;
        $end = !empty($entry['clock_out_at']) ? strtotime((string)$entry['clock_out_at']) : $now;
        if (!$end || $end < $start) continue;
        $seconds = $end - $start;
        $total += $seconds;
        if (empty($entry['clock_out_at'])) $openSeconds += $seconds;
    }
    return ['totalSeconds' => $total, 'openSeconds' => $openSeconds];
}

function setClockStatus(PDO $pdo, array $user, bool $clockedIn, string $source, ?array $actor = null): array {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) fail("Volunteer not found.");
    if (($user['status'] ?? '') !== 'approved' || (int)($user['blacklisted'] ?? 0) === 1) {
        fail("This volunteer is not approved for clock-in.");
    }

    $name = (string)($user['name'] ?? 'Volunteer');
    $isClockedIn = (bool)($user['clocked_in'] ?? false);
    if ($clockedIn) {
        if ($isClockedIn) {
            $totals = clockTotals($pdo, $userId);
            return ['message' => $name . ' is already clocked in. Current session: ' . formatClockDuration((int)$totals['openSeconds']) . '.'];
        }
        $pdo->prepare("UPDATE users SET clocked_in = 1, clocked_at = NOW() WHERE id = ?")->execute([$userId]);
        $pdo->prepare("INSERT INTO time_clock_entries (user_id, clock_in_at, source) VALUES (?, NOW(), ?)")->execute([$userId, $source]);
        logAction($pdo, $actor ?: $user, 'clock_in', $name . ' clocked in from ' . $source . '.');
        return ['message' => $name . ' clocked in.'];
    }

    if (!$isClockedIn) {
        return ['message' => $name . ' is not clocked in.'];
    }
    $stmt = $pdo->prepare("SELECT id, clock_in_at FROM time_clock_entries WHERE user_id = ? AND clock_out_at IS NULL ORDER BY clock_in_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($open) {
        $pdo->prepare("UPDATE time_clock_entries SET clock_out_at = NOW() WHERE id = ?")->execute([(int)$open['id']]);
    }
    $pdo->prepare("UPDATE users SET clocked_in = 0, clocked_at = NULL WHERE id = ?")->execute([$userId]);
    logAction($pdo, $actor ?: $user, 'clock_out', $name . ' clocked out from ' . $source . '.');

    $totals = clockTotals($pdo, $userId);
    return ['message' => $name . ' clocked out. Total recorded time: ' . formatClockDuration((int)$totals['totalSeconds']) . '.'];
}

function setDiscordClockStatus(PDO $pdo, array $discord, array $user, bool $clockedIn): array {
    $wasClockedIn = (bool)($user['clocked_in'] ?? false);
    $roleSynchronized = false;
    try {
        // The Discord role transition happens before the database transaction so
        // a role-permission failure cannot create a false attendance entry.
        setDiscordOnDutyRole($discord, (string)($user['discord_id'] ?? ''), $clockedIn);
        $roleSynchronized = true;
        $pdo->beginTransaction();
        $result = setClockStatus($pdo, $user, $clockedIn, 'discord', $user);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Restore the prior Discord role state if the DB transition could not land.
        if ($roleSynchronized && $wasClockedIn !== $clockedIn) {
            try {
                setDiscordOnDutyRole($discord, (string)($user['discord_id'] ?? ''), $wasClockedIn);
            } catch (Throwable $rollbackError) {
                error_log('Discord On Duty role compensation failed: ' . $rollbackError->getMessage());
            }
        }
        throw $error;
    }
}

function createMissedPunchRequest(PDO $pdo, array $user, string $source): string {
    $stmt = $pdo->prepare("INSERT INTO missed_punch_requests (user_id, discord_id, request_note) VALUES (?, ?, ?)");
    $stmt->execute([(int)$user['id'], (string)($user['discord_id'] ?? ''), 'Requested from ' . $source]);
    logAction($pdo, $user, 'missed_punch_request', (string)($user['name'] ?? 'Volunteer') . ' requested a missed punch correction.');
    return 'Missed punch request sent. A coordinator can review it in the activity log.';
}

function discordInteractionResponse(int $type, array $data = []): void {
    $response = ['type' => $type];
    if ($data) $response['data'] = $data;
    out($response);
}

function discordInteractionMessage(string $content, bool $ephemeral = true, array $extra = []): void {
    $data = array_merge(['content' => $content], $extra);
    if ($ephemeral) $data['flags'] = 64;
    discordInteractionResponse(4, $data);
}

function verifyDiscordInteractionSignature(array $config, string $rawBody): void {
    $publicKey = trim((string)($config['discord_public_key'] ?? ''));
    $signature = (string)($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '');
    if ($publicKey === '' || $signature === '' || $timestamp === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
        http_response_code(401);
        echo 'Discord interaction verification is not configured.';
        exit;
    }
    $signatureBytes = @hex2bin($signature);
    $publicKeyBytes = @hex2bin($publicKey);
    if ($signatureBytes === false || $publicKeyBytes === false ||
        !sodium_crypto_sign_verify_detached($signatureBytes, $timestamp . $rawBody, $publicKeyBytes)) {
        http_response_code(401);
        echo 'Invalid request signature.';
        exit;
    }
}

function interactionDiscordId(array $payload): string {
    return (string)($payload['member']['user']['id'] ?? $payload['user']['id'] ?? '');
}

function interactionRoles(array $payload): array {
    return array_map('strval', (array)($payload['member']['roles'] ?? []));
}

function interactionHasRole(array $payload, string $roleId): bool {
    return $roleId === '' || in_array($roleId, interactionRoles($payload), true);
}

function requireClockInteractionUser(PDO $pdo, array $discord, array $payload): array {
    if (!interactionHasRole($payload, $discord['volunteerRoleId'])) {
        discordInteractionMessage('You need the Delta H Volunteer role before clocking in.');
    }
    $discordId = interactionDiscordId($payload);
    $user = getUserByDiscordId($pdo, $discordId);
    if (!$user) {
        discordInteractionMessage('I could not find your linked Delta H account. Log into the website with Discord first.');
    }
    if (($user['status'] ?? '') !== 'approved' || (int)($user['blacklisted'] ?? 0) === 1) {
        discordInteractionMessage('Your Delta H account is not approved for clock-in yet.');
    }
    return $user;
}

function clockPanelData(): array {
    return [
        'embeds' => [[
            'title' => 'Clock into Delta H',
            'description' => 'Use these buttons to clock in, clock out, request a missed punch correction, or view your recorded time. This updates the online Delta H Scheduling System.',
            'color' => 1016185
        ]],
        'components' => [[
            'type' => 1,
            'components' => [
                ['type' => 2, 'style' => 3, 'custom_id' => 'delta_clock_in', 'label' => 'Clock in'],
                ['type' => 2, 'style' => 2, 'custom_id' => 'delta_missed_punch', 'label' => 'Missed punch'],
                ['type' => 2, 'style' => 4, 'custom_id' => 'delta_clock_out', 'label' => 'Clock out'],
                ['type' => 2, 'style' => 1, 'custom_id' => 'delta_view_time', 'label' => 'View your time']
            ]
        ]]
    ];
}

function discordApplicationCommands(): array {
    return [
        ['name' => 'clockin', 'description' => 'Clock into Delta H.'],
        ['name' => 'clockout', 'description' => 'Clock out of Delta H.'],
        ['name' => 'time', 'description' => 'View your Delta H recorded time.'],
        ['name' => 'clock-panel', 'description' => 'Post the Delta H clock-in button panel in this channel.']
    ];
}

function registerDiscordCommands(array $discord): array {
    if ($discord['clientId'] === '' || $discord['guildId'] === '' || $discord['botToken'] === '') {
        fail("Discord client ID, server ID, and bot token are required.");
    }
    return httpRequestJson(
        'https://discord.com/api/v10/applications/' . rawurlencode($discord['clientId']) . '/guilds/' . rawurlencode($discord['guildId']) . '/commands',
        discordApplicationCommands(),
        ['Authorization: Bot ' . $discord['botToken']],
        'PUT',
        true
    );
}

function handleDiscordInteraction(PDO $pdo, array $config, string $rawBody, array $payload): void {
    verifyDiscordInteractionSignature($config, $rawBody);
    $discord = discordConfig($config);
    $type = (int)($payload['type'] ?? 0);

    if ($type === 1) {
        discordInteractionResponse(1);
    }

    if ($type === 2) {
        $name = (string)($payload['data']['name'] ?? '');
        if ($name === 'clock-panel') {
            if (!interactionHasRole($payload, $discord['coordinatorRoleId'])) {
                discordInteractionMessage('Coordinator role required to post the clock panel.');
            }
            discordInteractionResponse(4, clockPanelData());
        }

        if (in_array($name, ['clockin', 'clockout', 'time'], true)) {
            $user = requireClockInteractionUser($pdo, $discord, $payload);
            if ($name === 'time') {
                $totals = clockTotals($pdo, (int)$user['id']);
                $message = 'Recorded time: ' . formatClockDuration((int)$totals['totalSeconds']);
                if ((int)$totals['openSeconds'] > 0) {
                    $message .= ' Current session: ' . formatClockDuration((int)$totals['openSeconds']) . '.';
                }
                discordInteractionMessage($message);
            }
            $result = setDiscordClockStatus($pdo, $discord, $user, $name === 'clockin');
            discordInteractionMessage($result['message']);
        }

        discordInteractionMessage('Unknown Delta H command.');
    }

    if ($type === 3) {
        $customId = (string)($payload['data']['custom_id'] ?? '');
        $user = requireClockInteractionUser($pdo, $discord, $payload);
        if ($customId === 'delta_clock_in' || $customId === 'delta_clock_out') {
            $result = setDiscordClockStatus($pdo, $discord, $user, $customId === 'delta_clock_in');
            discordInteractionMessage($result['message']);
        }
        if ($customId === 'delta_view_time') {
            $totals = clockTotals($pdo, (int)$user['id']);
            $message = 'Recorded time: ' . formatClockDuration((int)$totals['totalSeconds']);
            if ((int)$totals['openSeconds'] > 0) {
                $message .= ' Current session: ' . formatClockDuration((int)$totals['openSeconds']) . '.';
            }
            discordInteractionMessage($message);
        }
        if ($customId === 'delta_missed_punch') {
            discordInteractionMessage(createMissedPunchRequest($pdo, $user, 'discord'));
        }
        discordInteractionMessage('Unknown clock button.');
    }

    discordInteractionMessage('Unsupported Discord interaction.');
}


// 3. API Routing
$action = $_GET['action'] ?? '';
$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$getActions = ['session', 'discord_login', 'discord_callback', 'incident_evidence'];
$externalPostActions = ['discord_interactions'];
$cronActions = ['process_shift_alerts', 'process_flight_updates'];
$expectedCronSecret = trim((string)($config['shift_alert_cron_secret'] ?? ''));
$providedCronSecret = trim((string)($_SERVER['HTTP_X_DELTA_H_ALERT_KEY'] ?? ''));
$validCronSecret = in_array($action, $cronActions, true)
    && strlen($expectedCronSecret) >= 16
    && $providedCronSecret !== ''
    && hash_equals($expectedCronSecret, $providedCronSecret);

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    fail('Method not allowed.', 405);
}
if ($requestMethod === 'GET' && !in_array($action, $getActions, true)) {
    fail('This action requires POST.', 405);
}
if ($requestMethod === 'POST'
    && !in_array($action, $externalPostActions, true)
    && !$validCronSecret) {
    requireCsrfToken();
}

// Centrally revoke blacklisted sessions before any protected routing or state exposure.
// A blacklisted account is force-denied on its very next request even with a live PHP session.
$blacklistExemptActions = ['discord_login', 'discord_callback', 'discord_interactions', 'logout'];
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
if ($sessionUserId > 0 && !in_array($action, $blacklistExemptActions, true) && !$validCronSecret) {
    $sessionUser = getUserRow($pdo, $sessionUserId);
    if ($sessionUser && (int)($sessionUser['blacklisted'] ?? 0) === 1) {
        logAction($pdo, $sessionUser, 'blacklisted_session_revoked', 'Denied and destroyed a blacklisted account session.');
        $_SESSION = [];
        session_destroy();
        fail('Access denied: this account has been blocked.', 403);
    }
}

try {
    switch ($action) {
        case 'discord_interactions':
            handleDiscordInteraction($pdo, $config, $rawInput, $input);
            break;

        case 'discord_register_commands':
            $manager = requireManager($pdo);
            if (!isFullAdminRow($manager)) fail("Only Admin can register Discord commands.", 403);
            $commands = registerDiscordCommands(discordConfig($config));
            logAction($pdo, $manager, 'discord_register_commands', 'Registered Delta H Discord clock commands.');
            out(['success' => true, 'commands' => $commands]);
            break;

        case 'session':
            out(getAppState($pdo, $_SESSION['user_id'] ?? null));
            break;

        case 'incident_evidence':
            requireSafety($pdo, $config);
            streamIncidentEvidence($pdo, (int)($_GET['id'] ?? 0));
            break;

        case 'discord_login':
            $discord = requireDiscordConfig($config);
            $state = bin2hex(random_bytes(16));
            $_SESSION['discord_oauth_state'] = $state;
            $params = [
                'client_id' => $discord['clientId'],
                'redirect_uri' => $discord['redirectUri'],
                'response_type' => 'code',
                'scope' => 'identify email guilds.join guilds.members.read',
                'state' => $state,
                'prompt' => 'consent'
            ];
            header('Location: https://discord.com/api/oauth2/authorize?' . http_build_query($params));
            exit;

        case 'discord_callback':
            $discord = requireDiscordConfig($config);
            $state = (string)($_GET['state'] ?? '');
            $code = (string)($_GET['code'] ?? '');
            if ($state === '' || $state !== (string)($_SESSION['discord_oauth_state'] ?? '')) {
                redirectToApp('?discord_error=' . rawurlencode('Discord login state did not match.'));
            }
            unset($_SESSION['discord_oauth_state']);
            if ($code === '') {
                redirectToApp('?discord_error=' . rawurlencode('Discord did not return a login code.'));
            }

            $token = httpRequestJson('https://discord.com/api/oauth2/token', [
                'client_id' => $discord['clientId'],
                'client_secret' => $discord['clientSecret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $discord['redirectUri']
            ]);
            $accessToken = (string)($token['access_token'] ?? '');
            if ($accessToken === '') redirectToApp('?discord_error=' . rawurlencode('Discord token was empty.'));

            $discordUser = httpRequestJson('https://discord.com/api/users/@me', null, [
                'Authorization: Bearer ' . $accessToken
            ]);
            $discordId = (string)($discordUser['id'] ?? '');
            $discordEmail = strtolower(trim((string)($discordUser['email'] ?? '')));
            $discordEmailVerified = !empty($discordUser['verified']);
            $discordName = trim((string)($discordUser['global_name'] ?? $discordUser['username'] ?? 'Discord User'));
            $discordUsername = trim((string)($discordUser['username'] ?? ''));
            $discordAvatar = trim((string)($discordUser['avatar'] ?? ''));
            if ($discordId === '') redirectToApp('?discord_error=' . rawurlencode('Discord profile did not include an id.'));
            if (!$discordEmailVerified || $discordEmail === '') {
                $discordEmail = 'discord-' . $discordId . '@discord.local';
            }

            try {
                addDiscordGuildMember($discord, $discordId, $accessToken);
            } catch (Throwable $e) {
                error_log('Discord auto-join failed: ' . $e->getMessage());
            }

            $discordRoles = [];
            if (discordRoleChecksEnabled($discord)) {
                try {
                    $discordRoles = getDiscordMemberRoles($discord, $discordId);
                } catch (Throwable $e) {
                    error_log('Discord role lookup failed: ' . $e->getMessage());
                    redirectToApp('?discord_error=' . rawurlencode('Discord role lookup is temporarily unavailable. Please try again in a few minutes.'));
                }
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE discord_id = ? OR (? = 1 AND discord_id = '' AND LOWER(email) = ?) LIMIT 1");
            $stmt->execute([$discordId, $discordEmailVerified ? 1 : 0, $discordEmail]);
            $matched = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$matched) {
                enforceDiscordRoles($discord, $discordRoles, ['role' => 'volunteer', 'rank' => 'Volunteer']);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, discord, discord_id, discord_username, discord_avatar, discord_roles_json, role, status) VALUES (?,?,?,?,?,?,?,?,'volunteer','pending')");
                $stmt->execute([
                    $discordName,
                    $discordEmail,
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    $discordUsername,
                    $discordId,
                    $discordUsername,
                    $discordAvatar,
                    json_encode($discordRoles)
                ]);
                $newUserId = (int)$pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                logAction($pdo, null, 'discord_signup_pending', $discordName . ' created a pending Discord signup.');
                redirectToApp('?discord_application=needed');
            }

            if ((int)($matched['blacklisted'] ?? 0) === 1) {
                redirectToApp('?discord_error=' . rawurlencode('Access denied: account pending or blocked.'));
            }
            enforceDiscordRoles($discord, $discordRoles, $matched);

            $stmt = $pdo->prepare("UPDATE users SET discord_id = ?, discord = ?, discord_username = ?, discord_avatar = ?, discord_roles_json = ? WHERE id = ?");
            $stmt->execute([$discordId, $discordUsername, $discordUsername, $discordAvatar, json_encode($discordRoles), (int)$matched['id']]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$matched['id'];
            if (($matched['status'] ?? '') !== 'approved') {
                logAction($pdo, $matched, 'discord_application_resume', 'Returned to pending Discord application.');
                redirectToApp('?discord_application=needed');
            }
            logAction($pdo, $matched, 'discord_login', 'Logged in with Discord.');
            redirectToApp('?discord_login=ok');

        case 'update_user':
            $manager = requireManager($pdo);

            $targetId = (int)($input['id'] ?? 0);
            $target = getUserRow($pdo, $targetId);
            if (!$target) fail("Volunteer not found.");
            $targetDept = (string)($target['department'] ?: ($target['applied_department'] ?? ''));
            if (!canManageDepartment($manager, $targetDept)) fail("You can only manage volunteers in your department.", 403);

            $updates = [];
            $params = [];

            if (isset($input['status'])) { $updates[] = "status = ?"; $params[] = $input['status']; }
            if (isset($input['rank'])) {
                if (!isFullAdminRow($manager)) fail("Only Admin can change ranks.", 403);
                $rank = trim((string)$input['rank']);
                $updates[] = "rank = ?"; $params[] = $rank;
                $updates[] = "role = ?";
                $params[] = strtolower($rank) === 'admin' ? 'admin' : (strtolower($rank) === 'coordinator' ? 'manager' : 'volunteer');
            }
            if (isset($input['department'])) {
                if (!isFullAdminRow($manager)) fail("Only Admin can change departments.", 403);
                $updates[] = "department = ?"; $params[] = $input['department'];
            }
            if (isset($input['gender'])) {
                $gender = trim((string)$input['gender']);
                if (!in_array($gender, ['', 'Female', 'Male', 'Other', 'Prefer not to say'], true)) fail("Invalid gender value.");
                $updates[] = "gender = ?"; $params[] = $gender;
            }
            if (isset($input['hotel_needed'])) {
                $hotelNeeded = (string)$input['hotel_needed'] === 'Yes' ? 'Yes' : 'No';
                $updates[] = "hotel_needed = ?"; $params[] = $hotelNeeded;
            }
            if (isset($input['hotel_room'])) {
                $updates[] = "hotel_room = ?"; $params[] = trim((string)$input['hotel_room']);
            }
            if (isset($input['hotel_checked_in'])) {
                $updates[] = "hotel_checked_in = ?"; $params[] = !empty($input['hotel_checked_in']) && $input['hotel_checked_in'] !== '0' ? 1 : 0;
            }

            if (!empty($updates)) {
                $params[] = $targetId;
                $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                logAction($pdo, $manager, 'update_user', 'Updated ' . (string)($target['name'] ?? 'volunteer') . ': ' . implode(', ', array_keys($input)));
            }
            out(getAppState($pdo, $_SESSION['user_id'] ?? null));
            break;

        case 'update_shirt_pickup':
            $manager = requireManager($pdo);
            $targetId = (int)($input['userId'] ?? 0);
            $target = getUserRow($pdo, $targetId);
            if (!$target) fail("Volunteer not found.");

            $targetDept = (string)($target['department'] ?: ($target['applied_department'] ?? ''));
            if (!canManageDepartment($manager, $targetDept)) {
                fail("You can only manage volunteers in your department.", 403);
            }

            if (!array_key_exists('pickedUp', $input)) fail("Choose a T-shirt pickup status.");
            $pickedUp = filter_var($input['pickedUp'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $pdo->prepare("UPDATE users
                SET shirt_picked_up = ?,
                    shirt_picked_up_at = IF(? = 1, NOW(), NULL),
                    shirt_picked_up_by = IF(? = 1, ?, NULL)
                WHERE id = ?")
                ->execute([$pickedUp, $pickedUp, $pickedUp, (int)$manager['id'], $targetId]);

            $shirtSize = trim((string)($target['shirt_size'] ?? '')) ?: 'size not recorded';
            $action = $pickedUp ? 'shirt_pickup' : 'shirt_pickup_reverted';
            $detail = ($pickedUp ? 'Marked T-shirt picked up for ' : 'Reopened T-shirt pickup for ')
                . (string)($target['name'] ?? 'volunteer') . ' (' . $shirtSize . ')';
            logAction($pdo, $manager, $action, $detail);
            out(getAppState($pdo, $_SESSION['user_id'] ?? null));
            break;

        case 'save_volunteer_management_profile':
            $manager = requireManager($pdo);
            $target = saveVolunteerManagementProfile($pdo, $manager, $input);
            logAction($pdo, $manager, 'save_volunteer_management_profile', 'Updated the internal management profile for ' . (string)($target['name'] ?? 'volunteer') . '.');
            out(getAppState($pdo, (int)$manager['id']));
            break;

        case 'add_volunteer_management_note':
            $manager = requireManager($pdo);
            $target = addVolunteerManagementNote($pdo, $manager, $input);
            logAction($pdo, $manager, 'add_volunteer_management_note', 'Added an internal management note for ' . (string)($target['name'] ?? 'volunteer') . '.');
            out(getAppState($pdo, (int)$manager['id']));
            break;

        case 'set_user_blacklist':
            $manager = requireManager($pdo);
            $targetId = (int)($input['userId'] ?? 0);
            $target = requireManageableVolunteer($pdo, $manager, $targetId);
            $blacklist = filter_var($input['blacklisted'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($blacklist === 1 && $targetId === (int)$manager['id']) {
                fail("You cannot blacklist your own account.", 400);
            }
            $pdo->prepare("UPDATE users SET blacklisted = ? WHERE id = ?")->execute([$blacklist, $targetId]);
            $blacklistAction = $blacklist ? 'user_blacklisted' : 'user_restored';
            $blacklistDetail = ($blacklist ? 'Blacklisted access for ' : 'Restored access for ')
                . (string)($target['name'] ?? 'volunteer') . '.';
            logAction($pdo, $manager, $blacklistAction, $blacklistDetail);
            out(getAppState($pdo, (int)$manager['id']));
            break;

        case 'save_vendor_hall_assignment':
            $vendorUser = requireVendorHall($pdo, $config);
            $vendorSpot = saveVendorHallAssignment($pdo, $vendorUser, $input);
            logAction($pdo, $vendorUser, 'vendor_hall_save', 'Saved the Vendor Hall assignment for position ' . $vendorSpot . '.');
            out(getAppState($pdo, (int)$vendorUser['id']));
            break;

        case 'clear_vendor_hall_assignment':
            $vendorUser = requireVendorHall($pdo, $config);
            $vendorSpot = clearVendorHallAssignment($pdo, $input);
            logAction($pdo, $vendorUser, 'vendor_hall_clear', 'Cleared the Vendor Hall assignment for position ' . $vendorSpot . '.');
            out(getAppState($pdo, (int)$vendorUser['id']));
            break;

        case 'save_application':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in with Discord first.", 401);
            $userRow = getUserRow($pdo, $currentId);
            if (!$userRow) fail("Volunteer account not found.", 404);
            if ((int)($userRow['blacklisted'] ?? 0) === 1) fail("This account is blocked.", 403);

            $availability = normalizeAvailabilityRanges((array)($input['availability'] ?? []));
            $hasAvailability = count(array_filter($availability)) > 0;
            if (!$hasAvailability) fail("Choose All day or a valid start and end time for at least one day.");

            $validApplicationDays = [
                'Wednesday - Truck Loading',
                'Thursday - Load in',
                'Friday',
                'Saturday',
                'Sunday',
                'Sunday Load-out',
                'Monday Truck Unpacking'
            ];
            $datesAvailable = array_values(array_unique(array_filter((array)($input['datesAvailable'] ?? []), fn($day) => in_array((string)$day, $validApplicationDays, true))));
            if (!$datesAvailable) fail("Choose at least one available volunteer day.");

            $dateOfBirth = trim((string)($input['dateOfBirth'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) fail("Enter a valid date of birth.");
            [$year, $month, $day] = array_map('intval', explode('-', $dateOfBirth));
            if (!checkdate($month, $day, $year)) fail("Enter a valid date of birth.");

            $hotelNeeded = (string)($input['hotelNeeded'] ?? '') === 'Yes' ? 'Yes' : 'No';
            $gender = trim((string)($input['gender'] ?? ''));
            if (!in_array($gender, ['Female', 'Male', 'Other', 'Prefer not to say'], true)) fail("Choose a gender option.");

            $discordHandle = substr(trim((string)($input['discord'] ?? '')), 0, 120);
            $shirtSize = substr(trim((string)($input['shirtSize'] ?? '')), 0, 10);
            $department = substr(trim((string)($input['appliedDepartment'] ?? '')), 0, 100);
            $requestedHours = substr(trim((string)($input['requestedHours'] ?? '')), 0, 40);
            $allergies = substr(trim((string)($input['allergies'] ?? '')), 0, 255);
            if ($discordHandle === '' || $shirtSize === '' || $department === '' || $requestedHours === '' || $allergies === '') {
                fail("Complete all required application fields.");
            }

            $previousExperience = substr(trim((string)($input['previousExperience'] ?? '')), 0, 4000);
            $skills = substr(trim((string)($input['skills'] ?? '')), 0, 4000);
            $additionalNotes = substr(trim((string)($input['additionalNotes'] ?? '')), 0, 4000);
            $buddyRequest = substr($additionalNotes, 0, 255);
            $status = (string)($userRow['status'] ?? 'pending') === 'approved' ? 'approved' : 'pending';

            $stmt = $pdo->prepare("UPDATE users SET
                discord = ?,
                date_of_birth = ?,
                shirt_size = ?,
                applied_department = ?,
                dates_available = ?,
                requested_hours = ?,
                hotel_needed = ?,
                gender = ?,
                allergies = ?,
                previous_experience = ?,
                skills = ?,
                additional_notes = ?,
                buddy_request = ?,
                friend = ?,
                wed_loadout = ?,
                sun_loadout = ?,
                application_submitted_at = NOW(),
                status = ?
                WHERE id = ?");
            $stmt->execute([
                $discordHandle,
                $dateOfBirth,
                $shirtSize,
                $department,
                implode(', ', $datesAvailable),
                $requestedHours,
                $hotelNeeded,
                $gender,
                $allergies,
                $previousExperience,
                $skills,
                $additionalNotes,
                $buddyRequest,
                $buddyRequest,
                !empty($input['wedLoadout']) ? 1 : 0,
                !empty($input['sunLoadout']) ? 1 : 0,
                $status,
                $currentId
            ]);
            saveAvailability($pdo, $currentId, $availability);
            logAction($pdo, getUserRow($pdo, $currentId), 'save_application', 'Submitted or updated volunteer application for ' . $department . '.');
            out(getAppState($pdo, $currentId));
            break;

        case 'create_shift':
            $manager = requireManager($pdo);
            $department = (string)($input['department'] ?? '');
            if (!canManageDepartment($manager, $department)) fail("You can only create shifts in your department.", 403);

            $stmt = $pdo->prepare("INSERT INTO shifts (id, department, title, shift_day, shift_time, hours, capacity, note) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                'shift-'.substr(md5(uniqid()), 0, 8),
                $department,
                $input['title'] ?? '',
                $input['day'] ?? '',
                $input['time'] ?? '',
                (float)($input['hours'] ?? 0),
                (int)($input['capacity'] ?? 0),
                cleanShiftNote((string)($input['note'] ?? ''))
            ]);
            logAction($pdo, $manager, 'create_shift', 'Created ' . (string)($input['title'] ?? 'shift') . ' for ' . $department . '.');
            out(getAppState($pdo, $_SESSION['user_id'] ?? null));
            break;

        case 'import_shifts':
            $manager = requireManager($pdo);
            $importResult = importShifts($pdo, $manager, (array)($input['shifts'] ?? []));
            logAction($pdo, $manager, 'import_shifts', 'Imported ' . (int)$importResult['count'] . ' shifts; skipped ' . count($importResult['errors']) . ' rows.');
            $state = getAppState($pdo, (int)$manager['id']);
            $state['importedCount'] = $importResult['count'];
            $state['importErrors'] = $importResult['errors'];
            out($state);
            break;

        case 'create_hotel_room':
            $manager = requireManager($pdo);
            createHotelRoom($pdo, (string)($input['roomName'] ?? ''), (int)($input['capacity'] ?? 4), (string)($input['gender'] ?? ''));
            logAction($pdo, $manager, 'create_hotel_room', 'Created/updated hotel room ' . (string)($input['roomName'] ?? '') . '.');
            out(getAppState($pdo, (int)$manager['id']));
            break;

        case 'assign_shift':
            $manager = requireManager($pdo);
            $currentId = (int)$manager['id'];
            $targetId = (int)($input['userId'] ?? 0);
            $shiftId = (string)($input['shiftId'] ?? '');
            $assignAction = (string)($input['assignAction'] ?? 'assign');

            if ($assignAction === 'revoke') {
                revokeShift($pdo, $manager, $targetId, $shiftId);
                logAction($pdo, $manager, 'revoke_shift', 'Revoked shift ' . $shiftId . ' from user ' . $targetId . '.');
            } else {
                assignShift($pdo, $manager, $targetId, $shiftId, !empty($input['override']));
                logAction($pdo, $manager, 'assign_shift', 'Assigned shift ' . $shiftId . ' to user ' . $targetId . (!empty($input['override']) ? ' with override.' : '.'));
            }
            out(getAppState($pdo, $currentId));
            break;

        case 'delete_shift':
            $manager = requireManager($pdo);
            $currentId = (int)$manager['id'];
            deleteShift($pdo, $manager, (string)($input['shiftId'] ?? ''));
            logAction($pdo, $manager, 'delete_shift', 'Deleted shift ' . (string)($input['shiftId'] ?? '') . '.');
            out(getAppState($pdo, $currentId));
            break;

        case 'send_discord_dm':
            $manager = requireManager($pdo);
            $targetId = (int)($input['userId'] ?? 0);
            $target = getUserRow($pdo, $targetId);
            if (!$target) fail("Volunteer not found.");
            $targetDept = (string)($target['department'] ?: ($target['applied_department'] ?? ''));
            if (!canManageDepartment($manager, $targetDept)) fail("You can only message volunteers in your department.", 403);
            sendDiscordDm(discordConfig($config), (string)($target['discord_id'] ?? ''), (string)($input['message'] ?? ''));
            logAction($pdo, $manager, 'send_discord_dm', 'Sent Discord DM to ' . (string)($target['name'] ?? 'volunteer') . '.');
            out(getAppState($pdo, (int)$manager['id']));
            break;

        case 'save_shift_alert_rule':
            $manager = requireManager($pdo);
            $ruleId = saveShiftAlertRule($pdo, $manager, $input);
            logAction($pdo, $manager, 'save_shift_alert_rule', 'Saved missed check-in alert rule ' . $ruleId . ' for shift ' . (string)($input['shiftId'] ?? '') . '.');
            $state = getAppState($pdo, (int)$manager['id']);
            $state['savedAlertRuleId'] = $ruleId;
            out($state);
            break;

        case 'process_shift_alerts':
            $processor = authorizeShiftAlertProcessor($pdo, $config);
            $processResult = processShiftAlerts($pdo, $config);
            if ($processor) {
                logAction($pdo, $processor, 'process_shift_alerts', 'Alert scan sent ' . $processResult['sent'] . ' DMs and recorded ' . $processResult['failed'] . ' failures.');
                $state = getAppState($pdo, (int)$processor['id']);
                $state['alertProcessResult'] = $processResult;
                out($state);
            }
            logAction($pdo, null, 'process_shift_alerts_cron', 'Alert scan sent ' . $processResult['sent'] . ' DMs and recorded ' . $processResult['failed'] . ' failures.');
            out(['success' => true, 'alertProcessResult' => $processResult]);
            break;

        case 'save_guest_flight':
            $guestUser = requireGuestRelations($pdo, $config);
            saveGuestFlight($pdo, $config, $guestUser, $input);
            logAction($pdo, $guestUser, 'save_guest_flight', 'Saved flight ' . (string)($input['flightNumber'] ?? '') . ' for ' . (string)($input['guestName'] ?? '') . '.');
            out(getAppState($pdo, (int)$guestUser['id']));
            break;

        case 'refresh_guest_flights':
            $guestUser = requireGuestRelations($pdo, $config);
            $result = refreshGuestFlights($pdo, $config);
            logAction($pdo, $guestUser, 'refresh_guest_flights', 'Refreshed ' . $result['updated'] . ' flights and sent ' . $result['notified'] . ' pickup updates.');
            $state = getAppState($pdo, (int)$guestUser['id']);
            $state['flightRefreshResult'] = $result;
            out($state);
            break;

        case 'update_guest_flight_assignee':
            $guestUser = requireGuestRelations($pdo, $config);
            $flightId = (int)($input['flightId'] ?? 0);
            $assignedUserId = (int)($input['assignedUserId'] ?? 0);
            updateGuestFlightAssignee($pdo, $config, $flightId, $assignedUserId);
            logAction($pdo, $guestUser, 'update_guest_flight_assignee', 'Updated the pickup assignment for flight record ' . $flightId . '.');
            out(getAppState($pdo, (int)$guestUser['id']));
            break;

        case 'process_flight_updates':
            $processor = authorizeShiftAlertProcessor($pdo, $config);
            $result = refreshGuestFlights($pdo, $config);
            if ($processor) {
                logAction($pdo, $processor, 'process_flight_updates', 'Refreshed ' . $result['updated'] . ' flights and sent ' . $result['notified'] . ' pickup updates.');
                $state = getAppState($pdo, (int)$processor['id']);
                $state['flightRefreshResult'] = $result;
                out($state);
            }
            logAction($pdo, null, 'process_flight_updates_cron', 'Refreshed ' . $result['updated'] . ' flights and sent ' . $result['notified'] . ' pickup updates.');
            out(['success' => true, 'flightRefreshResult' => $result]);
            break;

        case 'save_incident':
            $safetyUser = requireSafety($pdo, $config);
            $incidentId = saveIncident($pdo, $safetyUser, $input);
            logAction($pdo, $safetyUser, 'save_incident', 'Saved Safety incident ' . $incidentId . '.');
            $state = getAppState($pdo, (int)$safetyUser['id']);
            $state['savedIncidentId'] = $incidentId;
            out($state);
            break;

        case 'add_incident_evidence_url':
            $safetyUser = requireSafety($pdo, $config);
            $evidenceId = addIncidentUrlEvidence($pdo, $safetyUser, $input);
            logAction($pdo, $safetyUser, 'attach_incident_evidence_url', 'Attached evidence link ' . $evidenceId . '.');
            out(getAppState($pdo, (int)$safetyUser['id']));
            break;

        case 'upload_incident_evidence':
            $safetyUser = requireSafety($pdo, $config);
            $evidenceId = uploadIncidentEvidence($pdo, $safetyUser);
            logAction($pdo, $safetyUser, 'upload_incident_evidence', 'Uploaded incident evidence ' . $evidenceId . '.');
            out(getAppState($pdo, (int)$safetyUser['id']));
            break;

        case 'remove_incident_evidence':
            $safetyUser = requireSafety($pdo, $config);
            removeIncidentEvidence($pdo, $safetyUser, (int)($input['evidenceId'] ?? 0));
            logAction($pdo, $safetyUser, 'remove_incident_evidence', 'Removed incident evidence ' . (int)($input['evidenceId'] ?? 0) . '.');
            out(getAppState($pdo, (int)$safetyUser['id']));
            break;

        case 'save_availability':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in first.", 401);
            saveAvailability($pdo, $currentId, (array)($input['availability'] ?? []));
            logAction($pdo, getUserRow($pdo, $currentId), 'save_availability', 'Updated availability.');
            out(getAppState($pdo, $currentId));
            break;

        case 'save_preferences':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in first.", 401);
            $buddy = trim((string)($input['buddyRequest'] ?? ''));
            $carpool = trim((string)($input['carpoolRequest'] ?? ''));
            $stmt = $pdo->prepare("UPDATE users SET buddy_request = ?, carpool_request = ?, friend = ? WHERE id = ?");
            $stmt->execute([$buddy, $carpool, $buddy, $currentId]);
            logAction($pdo, getUserRow($pdo, $currentId), 'save_preferences', 'Updated buddy/carpool preferences.');
            out(getAppState($pdo, $currentId));
            break;

        case 'save_schedule':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in first.", 401);
            saveSchedule($pdo, $currentId, (array)($input['shiftIds'] ?? []));
            logAction($pdo, getUserRow($pdo, $currentId), 'save_schedule', 'Saved schedule selections.');
            out(getAppState($pdo, $currentId));
            break;

        case 'clock':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in first.", 401);
            $clockedIn = !empty($input['clockedIn']);
            $adminMode = !empty($input['adminMode']);

            if ($adminMode) {
                $manager = requireManager($pdo);
                $target = lookupClockUser($pdo, (string)($input['lookup'] ?? ''));
                if (!$target) fail("Volunteer not found.");
                $targetDept = (string)($target['department'] ?: ($target['applied_department'] ?? ''));
                if (!canManageDepartment($manager, $targetDept)) fail("You can only clock volunteers in your department.", 403);
                setClockStatus($pdo, $target, $clockedIn, 'web-admin', $manager);
                out(getAppState($pdo, (int)$manager['id']));
            }

            $currentUser = getUserRow($pdo, $currentId);
            if (!$currentUser) fail("Please log in first.", 401);
            setClockStatus($pdo, $currentUser, $clockedIn, 'web', $currentUser);
            out(getAppState($pdo, $currentId));
            break;

        case 'update_profile_photo':
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if (!$currentId) fail("Please log in first.", 401);

            $photo = (string)($input['profilePhoto'] ?? '');
            if ($photo !== '' && !preg_match('/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/=]+$/', $photo)) {
                fail("Use a PNG, JPG, or WebP image.");
            }
            if (strlen($photo) > 900000) {
                fail("Image is too large. Please choose a smaller photo.");
            }

            $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            $stmt->execute([$photo, $currentId]);
            out(getAppState($pdo, $currentId));
            break;

        case 'logout':
            session_destroy();
            out(['success' => true]);
            break;

        default:
            fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('API action ' . $action . ' failed: ' . $e->getMessage());
    fail('An internal error occurred.', 500);
}
