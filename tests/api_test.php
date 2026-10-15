<?php
// Basic API tests (needs Apache, MySQL and the seed admin). Run: php tests\api_test.php http://localhost/waste-management/api

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');

require_once __DIR__ . '/TestHelpers.php';

$email = 'test_' . time() . '@example.com';
$good  = [
    'fname' => 'Juan', 'lname' => "Dela Cruz", 'email' => $email,
    'phone_number' => '09171234567', 'address' => '123 Sample St.', 'password' => 'Secret123',
];

echo "Auth API tests against $baseUrl\n";

$c = new Client($baseUrl);

echo "\nProtection\n";
[$s] = $c->request('GET', 'auth/me');
check('auth/me without login returns 401', $s === 401);
[$s] = $c->request('POST', 'auth/register', $good, false);
check('POST without CSRF token returns 403', $s === 403);
[$s] = $c->request('GET', 'nothing/here');
check('unknown endpoint returns 404', $s === 404);
[$s] = $c->request('GET', 'auth/login');
check('wrong method returns 405', $s === 405);

$c->fetchCsrf();
check('CSRF token is issued', is_string($c->csrf) && strlen($c->csrf) === 64);

echo "\nRegistration\n";
[$s, $j] = $c->request('POST', 'auth/register', ['fname' => '', 'email' => 'bad', 'password' => 'short']);
check('invalid input returns 422', $s === 422);
check('errors name the bad fields', isset($j['errors']['fname'], $j['errors']['email'], $j['errors']['password'], $j['errors']['address']));

[$s] = $c->request('POST', 'auth/register', $good + ['role' => 'admin']);
check('valid registration returns 201 (even with a sneaky role field)', $s === 201);

[$s, $j] = $c->request('POST', 'auth/register', $good);
check('duplicate email returns 409', $s === 409 && isset($j['errors']['email']));

echo "\nLogin\n";
[$s, $wrongPw]  = $c->request('POST', 'auth/login', ['email' => $email, 'password' => 'WrongPass1']);
[$s2, $noEmail] = $c->request('POST', 'auth/login', ['email' => "nobody_$email", 'password' => 'WrongPass1']);
check('wrong password returns 401', $s === 401);
check('unknown email gives the identical message', $s2 === 401 && $wrongPw['message'] === $noEmail['message']);

[$s] = $c->request('POST', 'auth/login', ['email' => "' OR '1'='1' -- $email", 'password' => "' OR '1'='1"]);
check('SQL injection attempt does not log in', $s === 401);

[$s] = $c->request('GET', 'auth/me');
check('still not logged in after failed attempts', $s === 401);

[$s, $j] = $c->request('POST', 'auth/login', ['email' => strtoupper($email), 'password' => 'Secret123']);
check('correct login returns 200 (email is case-insensitive)', $s === 200);
check('login never exposes the password hash', !str_contains(json_encode($j), 'password_hash'));
$c->csrf = $j['data']['csrf_token'] ?? null;

[$s, $j] = $c->request('GET', 'auth/me');
check('auth/me works after login', $s === 200 && $j['data']['user']['email'] === $email);
check('role is resident (role field in registration was ignored)', ($j['data']['user']['role'] ?? '') === 'resident');

[$s] = $c->request('POST', 'auth/logout');
check('logout returns 200', $s === 200);
[$s] = $c->request('GET', 'auth/me');
check('auth/me returns 401 after logout', $s === 401);

echo "\nAdmin account (from seed.sql)\n";
$admin = new Client($baseUrl);
$admin->fetchCsrf();
[$s, $j] = $admin->request('POST', 'auth/login', ['email' => 'admin@barangay.local', 'password' => 'Admin@12345']);
check('seed admin can log in', $s === 200 && ($j['data']['user']['role'] ?? '') === 'admin');

finishTests();
