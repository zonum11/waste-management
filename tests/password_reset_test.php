<?php
// Tests for "Forgot password?". Run: php tests\password_reset_test.php http://localhost/waste-management/api
// The emailed token cannot be read back (only its hash is stored), so this test
// saves a link of its own straight into the password_resets table.
// The API saves and compares dates in Philippine time, so this test must write them the same way.
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/TestHelpers.php';
require_once __DIR__ . '/../api/config/database.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Password reset API tests against $baseUrl\n";

$suffix = time() . random_int(100, 999);
$email  = "test_reset_$suffix@example.com";
$pdo    = getConnection();

$c = new Client($baseUrl);
$c->fetchCsrf();
$c->request('POST', 'auth/register', ['fname' => 'Test', 'lname' => 'Reset', 'email' => $email, 'zone_id' => 1, 'password' => 'Secret123']);
$userId = (int) $pdo->query("SELECT user_id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();

function saveToken(PDO $pdo, int $userId, string $token, int $secondsFromNow): void
{
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + $secondsFromNow)]);
}

echo "\nAsking for a link\n";
[$s, $j] = $c->request('POST', 'auth/forgot-password', ['email' => $email]);
$known = $j['message'] ?? '';
check('a registered email gets the standard reply (200)', $s === 200);
$rows = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id = $userId")->fetchColumn();
check('one link is saved for the resident', $rows === 1);
[$s, $j] = $c->request('POST', 'auth/forgot-password', ['email' => "nobody_$suffix@example.com"]);
check('an unknown email gets exactly the same reply (no account leak)', $s === 200 && ($j['message'] ?? '') === $known);
[$s, $j] = $c->request('POST', 'auth/forgot-password', ['email' => 'not-an-email']);
check('a badly written email is rejected (422)', $s === 422);
[$s] = $c->request('POST', 'auth/forgot-password', ['email' => $email], false);
check('a request without the CSRF token is refused (403)', $s === 403);
$stored = (string) $pdo->query("SELECT token_hash FROM password_resets WHERE user_id = $userId")->fetchColumn();
check('only a 64-character hash of the token is stored', strlen($stored) === 64);

echo "\nUsing a link\n";
$token = bin2hex(random_bytes(32));
saveToken($pdo, $userId, $token, 3600);
[$s, $j] = $c->request('POST', 'auth/reset-password', ['token' => $token, 'password' => 'short']);
check('a weak new password is rejected (422)', $s === 422 && isset($j['errors']['password']));
[$s] = $c->request('POST', 'auth/reset-password', ['token' => bin2hex(random_bytes(32)), 'password' => 'NewSecret123']);
check('a wrong token is rejected (400)', $s === 400);
[$s] = $c->request('POST', 'auth/reset-password', ['token' => 'abc', 'password' => 'NewSecret123']);
check('a malformed token is rejected (400)', $s === 400);
[$s] = $c->request('POST', 'auth/reset-password', ['token' => $token, 'password' => 'NewSecret123']);
check('the correct token changes the password (200)', $s === 200);
$old = new Client($baseUrl); $old->fetchCsrf();
check('the old password no longer works', $old->login($email, 'Secret123') === 401);
$new = new Client($baseUrl); $new->fetchCsrf();
check('the new password works', $new->login($email, 'NewSecret123') === 200);
[$s] = $c->request('POST', 'auth/reset-password', ['token' => $token, 'password' => 'Another123']);
check('the same link cannot be used twice (400)', $s === 400);

echo "\nExpired link\n";
$expired = bin2hex(random_bytes(32));
saveToken($pdo, $userId, $expired, -60);
[$s] = $c->request('POST', 'auth/reset-password', ['token' => $expired, 'password' => 'Another123']);
check('an expired link is rejected (400)', $s === 400);

echo "\nAdmin accounts\n";
$before = (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
$c->request('POST', 'auth/forgot-password', ['email' => 'admin@barangay.local']);
$after = (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
check('no reset link is made for the admin account', $after === $before);

$pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
finishTests();
