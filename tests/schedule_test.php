<?php
// Tests for the zones and collection schedule API. Run: php tests\schedule_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
echo "Schedule API tests against $baseUrl\n";

$email = 'test_sched_' . time() . '@example.com';
$resident = new Client($baseUrl);
$resident->fetchCsrf();
$resident->request('POST', 'auth/register', [
    'fname' => 'Test', 'lname' => 'Resident', 'email' => $email,
    'address' => '1 Test St.', 'password' => 'Secret123',
]);
$resident->login($email, 'Secret123');

$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');

$guest = new Client($baseUrl);
$guest->fetchCsrf();

echo "\nAccess control\n";
[$s] = $guest->request('GET', 'schedules');
check('guest cannot list schedules (401)', $s === 401);
[$s] = $guest->request('GET', 'zones');
check('guest cannot list zones (401)', $s === 401);

$payload = ['collect_day' => 'Thursday', 'collect_time' => '08:30', 'status' => 'active', 'zone_ids' => [1, 2]];
[$s] = $resident->request('POST', 'schedules', $payload);
check('resident cannot create a schedule (403)', $s === 403);
[$s] = $resident->request('PUT', 'schedules/1', $payload);
check('resident cannot edit a schedule (403)', $s === 403);
[$s] = $resident->request('DELETE', 'schedules/1');
check('resident cannot delete a schedule (403)', $s === 403);
[$s] = $guest->request('POST', 'schedules', $payload);
check('guest cannot create a schedule (401)', $s === 401);

echo "\nZones\n";
[$s, $j] = $resident->request('GET', 'zones');
check('resident can list zones', $s === 200 && count($j['data']['zones']) >= 3);
$zoneId = $j['data']['zones'][0]['zone_id'] ?? 0;

echo "\nValidation (admin)\n";
[$s, $j] = $admin->request('POST', 'schedules', ['collect_day' => 'Funday', 'collect_time' => '25:99', 'zone_ids' => []]);
check('bad input returns 422', $s === 422);
check('errors name the bad fields', isset($j['errors']['collect_day'], $j['errors']['collect_time'], $j['errors']['zone_ids']));
[$s, $j] = $admin->request('POST', 'schedules', ['collect_day' => 'Monday', 'collect_time' => '07:00', 'zone_ids' => [99999]]);
check('unknown zone is rejected (422)', $s === 422 && isset($j['errors']['zone_ids']));
[$s] = $admin->request('POST', 'schedules', ['collect_day' => 'Monday', 'collect_time' => '07:00', 'zone_ids' => ["1; DROP TABLE zones"]]);
check('non-numeric zone id is rejected (422)', $s === 422);

echo "\nCreate, read, update, delete (admin)\n";
[$s, $j] = $admin->request('POST', 'schedules', $payload);
check('admin creates a schedule (201)', $s === 201);
$id = $j['data']['schedule']['sched_id'] ?? 0;
check('created schedule has both zones and a 24h time', count($j['data']['schedule']['zones'] ?? []) === 2
    && ($j['data']['schedule']['collect_time'] ?? '') === '08:30');

[$s, $j] = $resident->request('GET', 'schedules');
$ids = array_column($j['data']['schedules'] ?? [], 'sched_id');
check('resident sees the new active schedule', $s === 200 && in_array($id, $ids, true));

[$s, $j] = $resident->request('GET', 'schedules?zone_id=' . $zoneId);
$allCoverZone = true;
foreach ($j['data']['schedules'] as $sch) {
    $allCoverZone = $allCoverZone && in_array($zoneId, array_column($sch['zones'], 'zone_id'), true);
}
check('zone filter returns only schedules covering that zone', $s === 200 && $allCoverZone && count($j['data']['schedules']) > 0);

$days = array_column(($admin->request('GET', 'schedules')[1]['data']['schedules'] ?? []), 'collect_day');
$order = array_flip(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
$sorted = $days;
usort($sorted, fn($a, $b) => $order[$a] <=> $order[$b]);
check('schedules are ordered Monday to Sunday', $days === $sorted);

[$s, $j] = $admin->request('PUT', "schedules/$id", ['collect_day' => 'Friday', 'collect_time' => '09:15', 'status' => 'inactive', 'zone_ids' => [3]]);
check('admin updates the schedule', $s === 200 && $j['data']['schedule']['collect_day'] === 'Friday'
    && count($j['data']['schedule']['zones']) === 1);

[, $j] = $resident->request('GET', 'schedules');
check('resident no longer sees the inactive schedule', !in_array($id, array_column($j['data']['schedules'], 'sched_id'), true));
[, $j] = $admin->request('GET', 'schedules');
check('admin still sees the inactive schedule', in_array($id, array_column($j['data']['schedules'], 'sched_id'), true));
[, $j] = $admin->request('GET', 'schedules?status=active');
check('admin status filter works', !in_array($id, array_column($j['data']['schedules'], 'sched_id'), true));

[$s] = $admin->request('PUT', 'schedules/999999', $payload);
check('editing a missing schedule returns 404', $s === 404);

[$s] = $admin->request('DELETE', "schedules/$id");
check('admin deletes the schedule', $s === 200);
[$s] = $admin->request('DELETE', "schedules/$id");
check('deleting it again returns 404', $s === 404);
[$s] = $admin->request('GET', 'schedules/abc');
check('non-numeric id is not a valid endpoint (404)', $s === 404);

finishTests();
