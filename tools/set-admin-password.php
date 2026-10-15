<?php
// Command-line tool that changes an existing admin account's password.
// Refuse to run in a browser: this must only be used from the command line.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require_once __DIR__ . '/../api/config/database.php';
require_once __DIR__ . '/../api/helpers/Response.php';
require_once __DIR__ . '/../api/helpers/Validator.php';

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    fwrite(STDERR, "Usage: php tools/set-admin-password.php <admin-email>\n");
    exit(1);
}

echo "New password for $email\n";
echo "(at least 8 characters with a letter and a number; what you type will be visible): ";
$password = rtrim((string) fgets(STDIN), "\r\n");

// Same rules as registration, so admin passwords are never weaker.
$validator = new Validator();
$validator->newPassword(['password' => $password]);
if ($validator->hasErrors()) {
    fwrite(STDERR, $validator->errors()['password'] . "\n");
    exit(1);
}

try {
    $stmt = getConnection()->prepare("UPDATE users SET password_hash = ? WHERE email = ? AND role = 'admin'");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not update the password: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($stmt->rowCount() === 0) {
    fwrite(STDERR, "No admin account with the email $email was found. Nothing was changed.\n");
    exit(1);
}

echo "Password updated. Use it the next time you log in.\n";
