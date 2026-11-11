<?php
// Tests for the Profile pop-up (name, email, phone, password). Run: php tests\profile_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Profile API tests against $baseUrl\n";

$suffix = time() . random_int(100, 999);
$email  = "test_profile_$suffix@example.com";
$other  = "test_profile_other_$suffix@example.com";
$newEmail = "test_profile_new_$suffix@example.com";

function makeResident(string $baseUrl, string $email): Client
{
    $c = new Client($baseUrl);
    $c->fetchCsrf();
    $c->request('POST', 'auth/register', [
        'fname' => 'Test', 'lname' => 'Profile', 'email' => $email,
        'address' => '1 Old Street', 'zone_id' => 1, 'password' => 'Secret123',
    ]);
    $c->login($email, 'Secret123');
    return $c;
}

$resident = makeResident($baseUrl, $email);
$another  = makeResident($baseUrl, $other);

echo "\nAddress\n";
[$s, $j] = $resident->request('GET', 'auth/me');
check('the address comes from the chosen zone, not from the text sent by the browser',
    $s === 200 && ($j['data']['user']['address'] ?? '') === 'Barangay Pogi Zone 1, Sitio Merete');

$zone4Email = 'test_zone4_' . time() . '@example.com';
$zone4 = new Client($baseUrl);
$zone4->fetchCsrf();
[$s] = $zone4->request('POST', 'auth/register', [
    'fname' => 'Test', 'lname' => 'FourthZone', 'email' => $zone4Email, 'zone_id' => 4, 'password' => 'Secret123',
]);
check('registering needs only the address choice (no typed address)', $s === 201);
$zone4->login($zone4Email, 'Secret123');
[$s, $j] = $zone4->request('GET', 'auth/me');
check('a Zone 4 resident gets the Zone 4 address and zone', ($j['data']['user']['address'] ?? '') === 'Barangay Pogi Zone 4, Sitio Daguison'
    && ($j['data']['user']['zone']['zone_id'] ?? 0) === 4);
$noZone = new Client($baseUrl);
$noZone->fetchCsrf();
[$s, $j] = $noZone->request('POST', 'auth/register', ['fname' => 'No', 'lname' => 'Zone', 'email' => 'test_nozone_' . time() . '@example.com', 'password' => 'Secret123']);
check('registering without choosing an address is rejected (422)', $s === 422 && isset($j['errors']['zone_id']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => $email]);
check('an address sent to the profile is ignored (the address cannot be edited)', $s === 200
    && ($j['data']['user']['address'] ?? '') === 'Barangay Pogi Zone 1, Sitio Merete');
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => strtoupper($email)]);
check('same email in capital letters is not treated as a change', $s === 200);

echo "\nEmail\n";
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => 'not-an-email']);
check('invalid email is rejected (422)', $s === 422 && isset($j['errors']['email']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => $newEmail]);
check('changing the email needs the current password (422)', $s === 422 && isset($j['errors']['current_password']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => $newEmail, 'current_password' => 'WrongPass1']);
check('wrong current password is rejected (422)', $s === 422 && isset($j['errors']['current_password']));
[$s, $j] = $resident->request('GET', 'auth/me');
check('the email did not change after a wrong password', ($j['data']['user']['email'] ?? '') === $email);
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => $other, 'current_password' => 'Secret123']);
check('an email used by another resident is rejected (409)', $s === 409 && isset($j['errors']['email']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => ['x']]);
check('an email sent as a list is rejected (422)', $s === 422);
[$s, $j] = $resident->request('PUT', 'auth/profile', ['email' => $newEmail, 'current_password' => 'Secret123']);
check('email changes with the correct password (200)', $s === 200 && ($j['data']['user']['email'] ?? '') === $newEmail);

$fresh = new Client($baseUrl);
$fresh->fetchCsrf();
check('login works with the new email', $fresh->login($newEmail, 'Secret123') === 200);
$old = new Client($baseUrl);
$old->fetchCsrf();
check('login no longer works with the old email', $old->login($email, 'Secret123') === 401);

echo "\nName, phone and password\n";
[$s, $j] = $resident->request('PUT', 'auth/profile', ['fname' => 'Maria', 'lname' => 'Santos', 'phone_number' => '0917 123 4567']);
check('name and phone can change without a password', $s === 200 && ($j['data']['user']['fname'] ?? '') === 'Maria'
    && ($j['data']['user']['lname'] ?? '') === 'Santos' && ($j['data']['user']['phone_number'] ?? '') === '0917 123 4567');
check('fields that were not sent keep their value (address, email, zone)', ($j['data']['user']['address'] ?? '') === 'Barangay Pogi Zone 1, Sitio Merete'
    && ($j['data']['user']['email'] ?? '') === $newEmail && ($j['data']['user']['zone']['zone_id'] ?? 0) === 1);
[$s, $j] = $resident->request('PUT', 'auth/profile', ['phone_number' => '']);
check('an empty phone number clears it', $s === 200 && array_key_exists('phone_number', $j['data']['user']) && $j['data']['user']['phone_number'] === null);
[$s, $j] = $resident->request('PUT', 'auth/profile', ['phone_number' => 'call me maybe']);
check('a bad phone number is rejected (422)', $s === 422 && isset($j['errors']['phone_number']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['fname' => '', 'lname' => '<b>']);
check('an empty first name is rejected (422)', $s === 422 && isset($j['errors']['fname']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['zone_id' => 3]);
check('a zone sent to the profile is ignored', $s === 200 && ($j['data']['user']['zone']['zone_id'] ?? 0) === 1);

[$s, $j] = $resident->request('PUT', 'auth/profile', ['new_password' => 'NewSecret456']);
check('a new password needs the current password (422)', $s === 422 && isset($j['errors']['current_password']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['new_password' => 'NewSecret456', 'current_password' => 'WrongPass1']);
check('a wrong current password is rejected (422)', $s === 422 && isset($j['errors']['current_password']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['new_password' => 'short', 'current_password' => 'Secret123']);
check('a weak new password is rejected (422)', $s === 422 && isset($j['errors']['new_password']));
[$s, $j] = $resident->request('PUT', 'auth/profile', ['new_password' => 'NewSecret456', 'current_password' => 'Secret123']);
check('the password changes with the correct current password (200)', $s === 200);
[$s] = $resident->request('GET', 'auth/me');
check('the resident stays logged in after changing the password', $s === 200);
$viaNew = new Client($baseUrl);
$viaNew->fetchCsrf();
check('login works with the new password', $viaNew->login($newEmail, 'NewSecret456') === 200);
$viaOld = new Client($baseUrl);
$viaOld->fetchCsrf();
check('login no longer works with the old password', $viaOld->login($newEmail, 'Secret123') === 401);

echo "\nWho may use it\n";
$guest = new Client($baseUrl);
$guest->fetchCsrf();
[$s] = $guest->request('PUT', 'auth/profile', ['email' => $newEmail]);
check('a guest cannot edit a profile (401)', $s === 401);
$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');
[$s] = $admin->request('PUT', 'auth/profile', ['email' => 'admin@barangay.local']);
check('an admin cannot use the resident profile (403)', $s === 403);
[$s, $j] = $another->request('GET', 'auth/me');
check('another resident\'s account is untouched', ($j['data']['user']['email'] ?? '') === $other && ($j['data']['user']['address'] ?? '') === 'Barangay Pogi Zone 1, Sitio Merete');
[$s] = $resident->request('PUT', 'auth/profile', ['email' => $newEmail], false);
check('a request without the CSRF token is refused (403)', $s === 403);

finishTests();
