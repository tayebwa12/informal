<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$host = env('DB_HOST', 'localhost');
$db   = env('DB_NAME', '');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
$cs   = env('DB_CHARSET', 'utf8mb4');

if ($db === '') {
    http_response_code(500);
    exit("DB_NAME missing in .env");
}

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset={$cs}",
        $user,
        $pass,
        $options
    );
} catch (Throwable $e) {
    http_response_code(500);
    if (APP_DEBUG) exit("Database error: " . $e->getMessage());
    exit("Database connection failed.");
}
