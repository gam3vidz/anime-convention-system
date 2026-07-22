<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/schema.php';

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$database = getenv('TEST_DB_NAME') ?: 'delta_h_test';
$username = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: 'root';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// A new install must succeed, and re-running must be idempotent.
ensureSchema($pdo);
ensureSchema($pdo);

$expectedTables = [
    'guest_flights',
    'hotel_rooms',
    'incident_activity',
    'incident_evidence',
    'incidents',
    'missed_punch_requests',
    'shift_alert_deliveries',
    'shift_alert_rules',
    'shifts',
    'system_logs',
    'time_clock_entries',
    'user_availability',
    'user_shifts',
    'users',
    'vendor_hall_assignments',
    'volunteer_management_notes',
    'volunteer_management_profiles',
];
$actualTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($actualTables);
if ($actualTables !== $expectedTables) {
    fwrite(STDERR, 'Table mismatch. Expected ' . json_encode($expectedTables) . '; got ' . json_encode($actualTables) . "\n");
    exit(1);
}

$requiredUserColumns = [
    'id', 'name', 'email', 'role', 'rank', 'status', 'department',
    'availability_json', 'discord_id', 'shirt_picked_up', 'date_of_birth',
];
$userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredUserColumns as $column) {
    if (!in_array($column, $userColumns, true)) {
        fwrite(STDERR, "Missing users.{$column}\n");
        exit(1);
    }
}

$requiredShiftColumns = [
    'id', 'department', 'title', 'shift_day', 'shift_time', 'hours', 'capacity', 'note',
];
$shiftColumns = $pdo->query('SHOW COLUMNS FROM shifts')->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredShiftColumns as $column) {
    if (!in_array($column, $shiftColumns, true)) {
        fwrite(STDERR, "Missing shifts.{$column}\n");
        exit(1);
    }
}

if ((int)$pdo->query('SELECT COUNT(*) FROM shifts')->fetchColumn() !== 0) {
    fwrite(STDERR, "Fresh schema unexpectedly contains demo shifts.\n");
    exit(1);
}

$vendorHallColumns = $pdo->query('SHOW COLUMNS FROM vendor_hall_assignments')->fetchAll(PDO::FETCH_COLUMN);
$requiredVendorHallColumns = ['spot_code', 'vendor_name', 'notes', 'updated_by', 'created_at', 'updated_at'];
foreach ($requiredVendorHallColumns as $column) {
    if (!in_array($column, $vendorHallColumns, true)) {
        fwrite(STDERR, "Missing vendor_hall_assignments.{$column}\n");
        exit(1);
    }
}

if ((int)$pdo->query('SELECT COUNT(*) FROM vendor_hall_assignments')->fetchColumn() !== 0) {
    fwrite(STDERR, "Fresh schema unexpectedly contains seeded vendor assignments.\n");
    exit(1);
}

fwrite(STDOUT, "Fresh MariaDB schema integration test passed (17 tables, idempotent).\n");
