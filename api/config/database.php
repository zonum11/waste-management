<?php
// Database connection using PDO (prepared statements protect against SQL injection).

require_once __DIR__ . '/config_loader.php';

function getConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $db = loadConfig()['db'];

    // An optional 'dsn' entry is only used by automated tests.
    $dsn = $db['dsn'] ?? "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

    try {
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['password'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Log the real reason, but give callers a safe message.
        error_log('Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Could not connect to the database.');
    }

    // Show and save every time in Philippine time (UTC+8, no daylight saving), whatever time zone the
    // hosting server uses. Without this, an online server in another country saves the wrong hour.
    if (str_starts_with($dsn, 'mysql:')) {
        $pdo->exec("SET time_zone = '+08:00'");
    }

    return $pdo;
}
