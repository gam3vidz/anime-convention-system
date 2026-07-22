<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$configPath = __DIR__ . '/../api/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing api/config.php. Copy api/config.example.php and provide deployment secrets first.\n");
    exit(1);
}

$config = require $configPath;
require_once __DIR__ . '/../api/schema.php';

$host = (string)($config['host'] ?? 'localhost');
$database = (string)($config['database'] ?? '');
$username = (string)($config['username'] ?? '');
$password = (string)($config['password'] ?? '');
$charset = (string)($config['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset={$charset}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    ensureSchema($pdo);
    fwrite(STDOUT, "Database schema migration completed successfully.\n");
} catch (Throwable $error) {
    error_log('Database migration failed: ' . $error->getMessage());
    fwrite(STDERR, "Database schema migration failed. Check the server error log.\n");
    exit(1);
}
