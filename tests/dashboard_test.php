<?php
// Tests for the admin dashboard summary and report filters. Run: php tests\dashboard_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Dashboard API tests against $baseUrl\n";

$email = 'test_dash_' . time() . rand(100, 999) . '@example.com';
$resident = new Client($baseUrl);
$resident->fetchCsrf();
$resident->request('POST', 'auth/register', [
    'fname' => 'Test', 'lname' => 'Dash', 'email' => $email, 'address' => '1 Test St.', 'password' => 'Secret123',
]);
$resident->login($email, 'Secret123');

$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');
$guest = new Client($baseUrl);
$guest->fetchCsrf();

echo "\nAccess control\n";
[$s] = $guest->request('GET', 'dashboard');
check('guest cannot open the dashboard summary (401)', $s === 401);
[$s] = $resident->request('GET', 'dashboard');
check('resident cannot open the dashboard summary (403)', $s === 403);

echo "\nSummary numbers\n";
[$s, $j] = $admin->request('GET', 'dashboard');
check('admin gets the summary', $s === 200 && isset($j['data']['report_counts'], $j['data']['recent_reports']));
$before = $j['data']['report_counts'];
$c = $before;
check('total equals the sum of the four statuses', $c['total'] === $c['pending'] + $c['in_progress'] + $c['resolved'] + $c['rejected']);

[, $list] = $admin->request('GET', 'schedules?status=active');
check('active schedule count matches the schedule list', $j['data']['active_schedules'] === count($list['data']['schedules']));
check('resident count includes the test resident', $j['data']['residents'] >= 1);

$base = ['issue_type' => 'missed_collection', 'issue_description' => 'Dashboard test report.'];
[$s, $r] = $resident->postForm('reports', $base + ['zone_id' => 1]);
$id1 = $r['data']['report']['report_id'] ?? 0;
[, $r] = $resident->postForm('reports', $base + ['zone_id' => 2, 'issue_description' => 'Second dashboard test report.']);
$id2 = $r['data']['report']['report_id'] ?? 0;

[, $j] = $admin->request('GET', 'dashboard');
$after = $j['data']['report_counts'];
check('two new reports add 2 to pending and total', $after['pending'] === $before['pending'] + 2 && $after['total'] === $before['total'] + 2);
check('latest reports list has at most 5 entries, newest first',
    count($j['data']['recent_reports']) <= 5 && ($j['data']['recent_reports'][0]['report_id'] ?? 0) === $id2);
check('latest reports include the reporter name', ($j['data']['recent_reports'][0]['reporter_name'] ?? '') === 'Test Dash');

$admin->request('PUT', "reports/$id1/status", ['status' => 'in_progress']);
[, $j] = $admin->request('GET', 'dashboard');
$moved = $j['data']['report_counts'];
check('changing a status moves the count between tiles', $moved['pending'] === $after['pending'] - 1
    && $moved['in_progress'] === $after['in_progress'] + 1 && $moved['total'] === $after['total']);

echo "\nReport filters\n";
[, $j] = $admin->request('GET', 'reports?zone_id=2&per_page=50');
$ids = array_column($j['data']['reports'], 'report_id');
check('zone filter keeps only that zone', in_array($id2, $ids, true) && !in_array($id1, $ids, true));

[, $j] = $admin->request('GET', 'reports?date_from=2000-01-01&date_to=2999-12-31&per_page=50');
check('a very wide date range includes the new reports', in_array($id1, array_column($j['data']['reports'], 'report_id'), true));
[, $j] = $admin->request('GET', 'reports?date_from=2999-01-01');
check('date_from in the far future finds nothing', $j['data']['pagination']['total'] === 0);
[, $j] = $admin->request('GET', 'reports?date_to=2000-01-01');
check('date_to in the past finds nothing', $j['data']['pagination']['total'] === 0);
[, $all] = $admin->request('GET', 'reports?per_page=1');
[$s, $j] = $admin->request('GET', 'reports?date_from=not-a-date&date_to=2026-02-31&zone_id=abc&per_page=1');
check('invalid dates and zone are ignored, not an error', $s === 200 && $j['data']['pagination']['total'] === $all['data']['pagination']['total']);
[$s, $j] = $admin->request('GET', "reports?date_from=2000-01-01&status=in_progress&zone_id=1&per_page=50");
check('filters combine (status + zone + date)', $s === 200 && in_array($id1, array_column($j['data']['reports'], 'report_id'), true)
    && !in_array($id2, array_column($j['data']['reports'], 'report_id'), true));
[$s] = $admin->request('GET', "reports?zone_id=1%20OR%201=1");
check('SQL in a filter value does not break the query', $s === 200);

[, $j] = $resident->request('GET', 'reports?per_page=50');
$mine = array_column($j['data']['reports'], 'report_id');
check("residents' lists still contain only their own reports", count($mine) === 2 && in_array($id1, $mine, true) && in_array($id2, $mine, true));

finishTests();
