<?php
// Security tests: permissions, sessions, uploads, throttling, odd input. Run: php tests\security_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Security tests against $baseUrl\n";

function newResident(string $baseUrl, string $label): array
{
    $email = "test_sec_{$label}_" . time() . rand(100, 999) . '@example.com';
    $c = new Client($baseUrl);
    $c->fetchCsrf();
    $c->request('POST', 'auth/register', [
        'fname' => 'Test', 'lname' => ucfirst($label), 'email' => $email,
        'address' => '1 Test St.', 'password' => 'Secret123',
    ]);
    $c->login($email, 'Secret123');
    return [$c, $email];
}

[$resident, $residentEmail] = newResident($baseUrl, 'main');
$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');
$guest = new Client($baseUrl);
$guest->fetchCsrf();

$people = ['guest' => $guest, 'resident' => $resident, 'admin' => $admin];

echo "\nPermission matrix (every endpoint x every kind of visitor)\n";
// Columns: method, path, body, then expected status for guest, resident, admin.
$matrix = [
    ['GET',    'auth/me',               null,   401, 200, 200],
    ['GET',    'zones',                 null,   401, 200, 200],
    ['GET',    'waste-types',           null,   401, 200, 200],
    ['GET',    'schedules',             null,   401, 200, 200],
    ['POST',   'schedules',             [],     401, 403, 422],
    ['PUT',    'schedules/999999',      [],     401, 403, 404],
    ['DELETE', 'schedules/999999',      null,   401, 403, 404],
    ['GET',    'reports',               null,   401, 200, 200],
    ['POST',   'reports',               'form', 401, 422, 403],
    ['GET',    'reports/999999',        null,   401, 404, 404],
    ['PUT',    'reports/999999/status', [],     401, 403, 404],
    ['GET',    'reports/999999/photo',  null,   401, 404, 404],
    ['GET',    'dashboard',             null,   401, 403, 200],
];
foreach ($matrix as [$method, $path, $body, $expectGuest, $expectResident, $expectAdmin]) {
    foreach (['guest' => $expectGuest, 'resident' => $expectResident, 'admin' => $expectAdmin] as $who => $expected) {
        $client = $people[$who];
        $status = $body === 'form' ? $client->postForm($path, [])[0] : $client->request($method, $path, $body)[0];
        check("$who $method $path -> $expected", $status === $expected);
    }
}

echo "\nCSRF protection (requests that change data need the secret token)\n";
$changing = [
    ['POST', 'schedules', []], ['PUT', 'schedules/1', []], ['DELETE', 'schedules/1', null],
    ['PUT', 'reports/1/status', ['status' => 'resolved']], ['POST', 'auth/logout', []],
];
$realToken = $admin->csrf;
foreach ($changing as [$method, $path, $body]) {
    [$noToken] = $admin->request($method, $path, $body, false);
    $admin->csrf = str_repeat('a', 64);
    [$badToken] = $admin->request($method, $path, $body);
    $admin->csrf = $realToken;
    check("admin $method $path without token or with a wrong token -> 403", $noToken === 403 && $badToken === 403);
}
$realToken = $resident->csrf;
$resident->csrf = null;
[$s] = $resident->postForm('reports', ['zone_id' => 1, 'issue_type' => 'other', 'issue_description' => 'No token on this one.']);
$resident->csrf = $realToken;
check('resident POST reports (form with photo support) without token -> 403', $s === 403);
[$s] = $admin->request('GET', 'auth/me');
check('admin is still logged in after the refused logout', $s === 200);

echo "\nCannot act as someone else (mass assignment)\n";
[$s, $j] = $resident->postForm('reports', [
    'zone_id' => 1, 'issue_type' => 'other', 'issue_description' => 'Trying to sneak extra fields in.',
    'user_id' => 1, 'current_status' => 'resolved', 'status' => 'resolved', 'role' => 'admin',
]);
$id = $j['data']['report']['report_id'] ?? 0;
check('report with extra fields is accepted (extras ignored)', $s === 201);
check('the report is still Pending', ($j['data']['report']['status'] ?? '') === 'pending');
[, $j] = $admin->request('GET', "reports/$id");
check('the report belongs to the resident, not to user 1 (the admin)', ($j['data']['report']['reporter']['email'] ?? '') === $residentEmail);
[, $j] = $resident->request('GET', 'auth/me');
check('the resident is still a resident', ($j['data']['user']['role'] ?? '') === 'resident');

echo "\nSessions and cookies\n";
[$status, $headers] = rawHttp("$baseUrl/auth/csrf");
check('session cookie is HttpOnly', hasHeader($headers, 'Set-Cookie', 'HttpOnly'));
check('session cookie has SameSite=Lax', hasHeader($headers, 'Set-Cookie', 'SameSite=Lax'));

$fixed = bin2hex(random_bytes(16));   // fake session id the server must not adopt
[$status, $headers] = rawHttp("$baseUrl/auth/csrf", 'GET', ["Cookie: WASTESESSID=$fixed"]);
check('a session id invented by an attacker is NOT adopted (strict mode)',
    hasHeader($headers, 'Set-Cookie', 'WASTESESSID=') && !hasHeader($headers, 'Set-Cookie', $fixed));

$c = new Client($baseUrl);
$c->fetchCsrf();
$before = $c->sessionId();
$c->login($residentEmail, 'Secret123');
$after = $c->sessionId();
check('the session id changes at login (no session fixation)', $before !== null && $after !== null && $before !== $after);

$old = $c->sessionId();
$c->request('POST', 'auth/logout', []);
[$status] = rawHttp("$baseUrl/auth/me", 'GET', ["Cookie: WASTESESSID=$old"]);
check('after logout the old session cookie no longer works (401)', $status === 401);

echo "\nSecurity headers\n";
[, $headers] = rawHttp("$baseUrl/zones");
check('X-Content-Type-Options: nosniff', hasHeader($headers, 'X-Content-Type-Options', 'nosniff'));
check('X-Frame-Options: DENY', hasHeader($headers, 'X-Frame-Options', 'DENY'));
check('Referrer-Policy is set', hasHeader($headers, 'Referrer-Policy', 'no-referrer'));
check('Content-Security-Policy blocks everything for API replies', hasHeader($headers, 'Content-Security-Policy', "default-src 'none'"));
check('Cache-Control: no-store (personal data is never cached)', hasHeader($headers, 'Cache-Control', 'no-store'));
[$status] = rawHttp("$baseUrl/zones", 'OPTIONS');
check('unsupported HTTP method gets 405', $status === 405);
[$status, $headers] = rawHttp("$baseUrl/zones", 'GET', ['X-Forwarded-Proto: https']);
check('behind an HTTPS proxy: HSTS header is added', hasHeader($headers, 'Strict-Transport-Security'));

echo "\nStrange input\n";
[$s] = $resident->request('POST', 'auth/login', ['email' => 'a@b.co', 'password' => str_repeat('x', 100000)]);
check('a 100 KB JSON body is refused (413)', $s === 413);
[$s] = $resident->sendRaw('POST', 'auth/login', '{this is not json');
check('broken JSON is refused (400)', $s === 400);
[$s] = $resident->sendRaw('POST', 'auth/login', '"just a string"');
check('JSON that is not an object is refused (400)', $s === 400);
[$s, $j] = $resident->postForm('reports', ['zone_id' => 1, 'issue_type' => 'other', 'issue_description' => "Broken text \xC3\x28 here ok"]);
check('text that is not valid UTF-8 is refused (400)', $s === 400);
[$s, , $body] = $resident->getRaw('reports');
check('the report list still works afterwards (valid JSON, not empty)', $s === 200 && json_decode($body, true) !== null);
[$s] = $resident->request('GET', 'reports/99999999999999999999');
check('a huge report number is just "not found" (404), not a crash', $s === 404);
[$s] = $resident->getRaw('reports/1%2F..%2F..%2Fapi/photo');
check('path tricks in the photo address do not work (404)', $s === 404);
[$s, $j] = $resident->request('GET', 'reports?status=pending%27%20OR%201=1&page=-5&per_page=99999&zone_id=1;DROP');
check('hostile filter values are ignored, not an error', $s === 200 && $j['data']['pagination']['per_page'] === 50 && $j['data']['pagination']['page'] === 1);

echo "\nPhoto upload safety\n";
$tmp = sys_get_temp_dir();
$svg = "$tmp/test_svg_" . getmypid() . '.png';        // SVG with a script, named like a PNG
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$html = "$tmp/test_html_" . getmypid() . '.jpg';       // web page named like a JPEG
file_put_contents($html, '<html><script>alert(1)</script></html>');
$png = "$tmp/test_ok_" . getmypid() . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$form = ['zone_id' => 1, 'issue_type' => 'other', 'issue_description' => 'Photo safety test report.'];
[$s] = $resident->postForm('reports', $form, $svg, 'x.png');
check('an SVG (can contain scripts) is refused even if named .png', $s === 422);
[$s] = $resident->postForm('reports', $form, $html, 'x.jpg');
check('an HTML file named .jpg is refused', $s === 422);
[$s, $j] = $resident->postForm('reports', $form, $png, '../../evil.php');
check('a real image with a dangerous file name is accepted but renamed', $s === 201);
$photoId = $j['data']['report']['report_id'] ?? 0;
[$s, $type, , $headers] = $resident->getRaw("reports/$photoId/photo");
check('the photo is served as an image', $s === 200 && str_starts_with($type, 'image/png'));
check('the photo reply cannot be re-interpreted as a web page (nosniff + CSP)',
    hasHeader($headers, 'X-Content-Type-Options', 'nosniff') && hasHeader($headers, 'Content-Security-Policy'));
foreach ([$svg, $html, $png] as $f) {
    @unlink($f);
}

echo "\nLogin throttling (5 wrong passwords in 15 minutes lock that email on this computer)\n";
[$victim, $victimEmail] = newResident($baseUrl, 'victim');
$t = new Client($baseUrl);
$t->fetchCsrf();
$codes = [];
for ($i = 1; $i <= 5; $i++) {
    $codes[] = $t->request('POST', 'auth/login', ['email' => $victimEmail, 'password' => "WrongPass$i"])[0];
}
check('the first 5 wrong passwords get a normal 401', $codes === [401, 401, 401, 401, 401]);
[$s, $j, $headers] = $t->sendRaw('POST', 'auth/login', json_encode(['email' => $victimEmail, 'password' => 'Secret123']));
check('the 6th attempt is refused (429) even with the CORRECT password', $s === 429 && str_contains($j['message'] ?? '', 'Too many'));
check('the reply says how long to wait (Retry-After)', hasHeader($headers, 'Retry-After'));
[$s] = $t->request('GET', 'auth/me');
check('and nobody got logged in', $s === 401);

$other = new Client($baseUrl);
check('a different account is not affected (admin can log in)', $other->login('admin@barangay.local', 'Admin@12345') === 200);

$ghost = "test_sec_ghost_" . time() . rand(100, 999) . '@example.com';
$g = new Client($baseUrl);
$g->fetchCsrf();
for ($i = 1; $i <= 5; $i++) {
    $g->request('POST', 'auth/login', ['email' => $ghost, 'password' => 'Whatever1']);
}
[$s] = $g->request('POST', 'auth/login', ['email' => $ghost, 'password' => 'Whatever1']);
check('an email that does not exist is limited in the same way (nothing is revealed)', $s === 429);

[$second, $secondEmail] = newResident($baseUrl, 'forgetful');
$f = new Client($baseUrl);
$f->fetchCsrf();
for ($i = 1; $i <= 3; $i++) {
    $f->request('POST', 'auth/login', ['email' => $secondEmail, 'password' => "Oops$i"]);
}
check('3 mistakes then the right password still works', $f->login($secondEmail, 'Secret123') === 200);
$f->request('POST', 'auth/logout', []);
$f->fetchCsrf();
$codes = [];
for ($i = 1; $i <= 4; $i++) {
    $codes[] = $f->request('POST', 'auth/login', ['email' => $secondEmail, 'password' => "Oops$i"])[0];
}
check('a correct login resets the counter (4 more mistakes are still allowed)', $codes === [401, 401, 401, 401]);

finishTests();
