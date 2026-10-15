<?php
// Speed check: average of 20 requests must be under 500 ms. Run: php tests\performance_test.php http://localhost/waste-management/api
require_once __DIR__ . '/TestHelpers.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost/waste-management/api', '/');
$rounds  = 20;
$limitMs = 500;

echo "Speed check against $baseUrl ($rounds requests each)\n\n";

$admin = new Client($baseUrl);
$admin->login('admin@barangay.local', 'Admin@12345');
$resident = new Client($baseUrl);

// Use any existing report for the detail request.
[, $list] = $admin->request('GET', 'reports?per_page=1');
$someReport = $list['data']['reports'][0]['report_id'] ?? null;
$total      = $list['data']['pagination']['total'] ?? 0;

$requests = [
    'zones list'                  => ['GET', 'zones'],
    'schedules list'              => ['GET', 'schedules'],
    'dashboard summary'           => ['GET', 'dashboard'],
    'reports list (50 per page)'  => ['GET', 'reports?per_page=50'],
    'reports list with filters'   => ['GET', 'reports?status=pending&zone_id=1&date_from=2000-01-01'],
];
if ($someReport !== null) {
    $requests['one report with history'] = ['GET', "reports/$someReport"];
}

printf("  %-30s %8s %8s %8s\n", 'Request', 'average', 'slowest', 'result');
foreach ($requests as $label => [$method, $path]) {
    $times = [];
    for ($i = 0; $i < $rounds; $i++) {
        $start = microtime(true);
        [$status] = $admin->request($method, $path);
        $times[] = (microtime(true) - $start) * 1000;
        if ($status !== 200) {
            break;
        }
    }
    $average = array_sum($times) / count($times);
    $slowest = max($times);
    $ok = $status === 200 && $average < $limitMs;
    printf("  %-30s %6.0f ms %6.0f ms %8s\n", $label, $average, $slowest, $ok ? 'PASS' : 'FAIL');
    check("$label: average under $limitMs ms", $ok);
}

echo "\nThe database currently holds $total report(s). Speed is checked again whenever you add more.\n";
finishTests();
