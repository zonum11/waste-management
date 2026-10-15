<?php
// Tests for submitting reports, photo upload, privacy and status. Run: php tests\report_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Report API tests against $baseUrl\n";

// Temporary files for the upload tests.
$tmp = sys_get_temp_dir();
$png = "$tmp/test_photo_" . getmypid() . '.png';       // valid 1x1 PNG
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$fakeImage = "$tmp/test_fake_" . getmypid() . '.jpg';   // text pretending to be a JPEG
file_put_contents($fakeImage, '<?php echo "not an image"; ?>');
$gif = "$tmp/test_gif_" . getmypid() . '.gif';           // valid image, but a type we do not accept
file_put_contents($gif, base64_decode('R0lGODlhAQABAAAAACw='));
$big = "$tmp/test_big_" . getmypid() . '.png';           // over the 2 MB limit
file_put_contents($big, file_get_contents($png) . str_repeat('0', 3 * 1024 * 1024));

function makeResident(string $baseUrl, string $label): Client
{
    $email = "test_{$label}_" . time() . rand(100, 999) . '@example.com';
    $c = new Client($baseUrl);
    $c->fetchCsrf();
    $c->request('POST', 'auth/register', [
        'fname' => 'Test', 'lname' => ucfirst($label), 'email' => $email,
        'address' => '1 Test St.', 'password' => 'Secret123',
    ]);
    $c->login($email, 'Secret123');
    return $c;
}

$alice = makeResident($baseUrl, 'alice');
$bob   = makeResident($baseUrl, 'bob');
$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');
$guest = new Client($baseUrl);
$guest->fetchCsrf();

$valid = ['zone_id' => 1, 'issue_type' => 'missed_collection', 'issue_description' => 'Garbage was not collected on Monday.'];

echo "\nAccess control\n";
[$s] = $guest->request('GET', 'reports');
check('guest cannot list reports (401)', $s === 401);
[$s] = $guest->request('GET', 'waste-types');
check('guest cannot list waste types (401)', $s === 401);
[$s] = $admin->postForm('reports', $valid);
check('admin cannot submit a report (403)', $s === 403);
[$s, $j] = $alice->request('GET', 'waste-types');
check('resident can list waste types', $s === 200 && count($j['data']['waste_types']) >= 4);
$types = array_column($j['data']['waste_types'], 'type_id');

echo "\nValidation\n";
[$s, $j] = $alice->postForm('reports', []);
check('empty form returns 422 naming the required fields', $s === 422 && isset($j['errors']['zone_id'], $j['errors']['issue_type'], $j['errors']['issue_description']));
[$s, $j] = $alice->postForm('reports', ['issue_description' => 'too short'] + $valid);
check('description under 10 characters is rejected', $s === 422 && isset($j['errors']['issue_description']));
[$s, $j] = $alice->postForm('reports', ['zone_id' => 99999] + $valid);
check('unknown zone is rejected', $s === 422 && isset($j['errors']['zone_id']));
[$s, $j] = $alice->postForm('reports', ['issue_type' => 'invented'] + $valid);
check('unknown issue type is rejected', $s === 422 && isset($j['errors']['issue_type']));
[$s, $j] = $alice->postForm('reports', $valid + ['waste_type_ids' => [99999]]);
check('unknown waste type is rejected', $s === 422 && isset($j['errors']['waste_type_ids']));
[$s, $j] = $alice->postForm('reports', $valid, $fakeImage, 'evil.jpg');
check('a PHP/text file renamed .jpg is rejected', $s === 422 && isset($j['errors']['photo']));
[$s, $j] = $alice->postForm('reports', $valid, $gif, 'x.gif');
check('a GIF (type not allowed) is rejected', $s === 422 && isset($j['errors']['photo']));
[$s, $j] = $alice->postForm('reports', $valid, $big, 'big.png');
check('a photo over 2 MB is rejected', in_array($s, [413, 422], true));

echo "\nSubmitting reports\n";
[$s, $j] = $alice->postForm('reports', $valid + ['waste_type_ids' => [$types[0], $types[1]]]);
check('resident submits a report without photo (201)', $s === 201);
$r1 = $j['data']['report'] ?? [];
check('new report is pending with 2 waste types and 1 history entry',
    ($r1['status'] ?? '') === 'pending' && count($r1['waste_types'] ?? []) === 2 && count($r1['history'] ?? []) === 1);
check('resident response contains no reporter contact details', !isset($r1['reporter']));

[$s, $j] = $alice->postForm('reports', ['issue_type' => 'overflowing_garbage', 'zone_id' => 2,
    'issue_description' => "'); DROP TABLE users; -- <script>alert(1)</script>"], $png, 'my photo (1).png');
check('resident submits a report with a photo (201)', $s === 201 && ($j['data']['report']['has_photo'] ?? false) === true);
$r2 = $j['data']['report'] ?? [];
check('SQL/HTML text is stored as plain text', ($r2['issue_description'] ?? '') === "'); DROP TABLE users; -- <script>alert(1)</script>");
[$s] = $alice->request('GET', 'auth/me');
check('users table survived the injection attempt', $s === 200);

[$s, $j] = $bob->postForm('reports', ['zone_id' => 3, 'issue_type' => 'improper_disposal', 'issue_description' => 'Bob reports illegal dumping.']);
check('second resident submits a report', $s === 201);
$bobReport = $j['data']['report']['report_id'] ?? 0;

echo "\nPhotos\n";
[$s, $type, $body] = $alice->getRaw('reports/' . $r2['report_id'] . '/photo');
check('owner can view the photo (image/png)', $s === 200 && str_starts_with($type, 'image/png') && str_starts_with($body, "\x89PNG"));
[$s] = $bob->getRaw('reports/' . $r2['report_id'] . '/photo');
check("another resident cannot view the photo (404)", $s === 404);
[$s] = $guest->getRaw('reports/' . $r2['report_id'] . '/photo');
check('guest cannot view the photo (401)', $s === 401);
[$s] = $admin->getRaw('reports/' . $r2['report_id'] . '/photo');
check('admin can view the photo', $s === 200);
[$s] = $alice->getRaw('reports/' . $r1['report_id'] . '/photo');
check('report without photo returns 404 for /photo', $s === 404);

echo "\nPrivacy and listing\n";
[$s, $j] = $alice->request('GET', 'reports');
$aliceIds = array_column($j['data']['reports'], 'report_id');
check("resident's list holds only their own reports", $s === 200 && in_array($r1['report_id'], $aliceIds, true)
    && in_array($r2['report_id'], $aliceIds, true) && !in_array($bobReport, $aliceIds, true));
check('resident list does not expose reporter names', !isset($j['data']['reports'][0]['reporter_name']));
check('list is newest first', $aliceIds === [$r2['report_id'], $r1['report_id']]);

[$s, $j] = $alice->request('GET', 'reports?per_page=1&page=2');
check('pagination works', $s === 200 && count($j['data']['reports']) === 1 && $j['data']['pagination']['total'] === 2
    && $j['data']['pagination']['total_pages'] === 2 && $j['data']['reports'][0]['report_id'] === $r1['report_id']);

[$s, $j] = $admin->request('GET', 'reports?per_page=50');
$adminIds = array_column($j['data']['reports'], 'report_id');
check('admin list includes reports from different residents', in_array($r1['report_id'], $adminIds, true) && in_array($bobReport, $adminIds, true));
check('admin list shows reporter names', isset($j['data']['reports'][0]['reporter_name']));

[$s] = $bob->request('GET', 'reports/' . $r1['report_id']);
check("resident cannot open someone else's report (404)", $s === 404);
[$s, $j] = $alice->request('GET', 'reports/' . $r1['report_id']);
check('owner can open their report', $s === 200 && $j['data']['report']['report_id'] === $r1['report_id']);
[$s, $j] = $admin->request('GET', 'reports/' . $r1['report_id']);
check('admin sees the reporter contact details', $s === 200 && ($j['data']['report']['reporter']['email'] ?? '') !== '');
[$s] = $alice->request('GET', 'reports/999999');
check('missing report returns 404', $s === 404);

echo "\nStatus updates (admin)\n";
$id = $r1['report_id'];
[$s] = $alice->request('PUT', "reports/$id/status", ['status' => 'resolved']);
check('resident cannot change a status (403)', $s === 403);
[$s] = $guest->request('PUT', "reports/$id/status", ['status' => 'resolved']);
check('guest cannot change a status (401)', $s === 401);
[$s, $j] = $admin->request('PUT', "reports/$id/status", ['status' => 'finished']);
check('invalid status is rejected (422)', $s === 422 && isset($j['errors']['status']));
[$s, $j] = $admin->request('PUT', "reports/$id/status", ['status' => 'pending']);
check('setting the same status is rejected (422)', $s === 422 && isset($j['errors']['status']));
[$s, $j] = $admin->request('PUT', "reports/$id/status", ['status' => 'rejected']);
check('rejecting without a reason is refused (422)', $s === 422 && isset($j['errors']['remarks']));
[$s] = $admin->request('PUT', 'reports/999999/status', ['status' => 'resolved']);
check('updating a missing report returns 404', $s === 404);

[$s, $j] = $admin->request('PUT', "reports/$id/status", ['status' => 'in_progress', 'remarks' => 'Truck scheduled for tomorrow.']);
check('admin sets in_progress with a remark', $s === 200 && $j['data']['report']['status'] === 'in_progress' && count($j['data']['report']['history']) === 2);
[$s, $j] = $admin->request('PUT', "reports/$id/status", ['status' => 'resolved']);
check('admin sets resolved', $s === 200 && count($j['data']['report']['history']) === 3);

[$s, $j] = $alice->request('GET', "reports/$id");
$history = $j['data']['report']['history'] ?? [];
check('resident sees the new status and the full history trail',
    ($j['data']['report']['status'] ?? '') === 'resolved'
    && array_column($history, 'status') === ['pending', 'in_progress', 'resolved']);
check('history remark is visible to the resident', ($history[1]['remarks'] ?? '') === 'Truck scheduled for tomorrow.');
check('history shows "You" and "Barangay staff" (no admin name)', ($history[0]['updated_by'] ?? '') === 'You'
    && ($history[1]['updated_by'] ?? '') === 'Barangay staff');

[, $j] = $admin->request('GET', 'reports?status=resolved&per_page=50');
$resolvedIds = array_column($j['data']['reports'], 'report_id');
check('admin status filter finds the resolved report only', in_array($id, $resolvedIds, true) && !in_array($bobReport, $resolvedIds, true));

[$s, $j] = $admin->request('PUT', "reports/$bobReport/status", ['status' => 'rejected', 'remarks' => 'Outside barangay boundary.']);
check('admin can reject with a reason', $s === 200 && $j['data']['report']['status'] === 'rejected');

foreach ([$png, $fakeImage, $gif, $big] as $f) {
    @unlink($f);
}
finishTests();
