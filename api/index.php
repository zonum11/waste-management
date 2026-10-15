<?php
// Single entry point of the API: sends each request to the right controller method.
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

// Load class files automatically when first used.
spl_autoload_register(function (string $class): void {
    foreach (['helpers', 'models', 'controllers'] as $folder) {
        $file = __DIR__ . "/$folder/$class.php";
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Never print PHP errors: they would break the JSON and leak details.
ini_set('display_errors', '0');

// Last resort: log the real error, show the user a safe message.
set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $debug = false;
    try {
        $debug = (bool) (loadConfig()['debug'] ?? false);
    } catch (Throwable $ignored) {
    }
    Response::error($debug ? $e->getMessage() : 'Something went wrong. Please try again later.', 500);
});

// Security headers: no content sniffing, no caching of personal data, no framing.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
// The API only returns data, so it may load no scripts, styles or images.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (Auth::isHttps()) {
    // Tell browsers to always use HTTPS for this site.
    header('Strict-Transport-Security: max-age=31536000');
}

// Routes: [HTTP method, URL pattern, controller, method]. {id} matches a number.
$routes = [
    ['GET',    'auth/csrf',      'AuthController', 'csrf'],
    ['GET',    'auth/me',        'AuthController', 'me'],
    ['POST',   'auth/register',  'AuthController', 'register'],
    ['POST',   'auth/login',     'AuthController', 'login'],
    ['POST',   'auth/logout',    'AuthController', 'logout'],

    ['GET',    'zones',          'ZoneController', 'index'],

    ['GET',    'waste-types',    'WasteTypeController', 'index'],

    ['GET',    'reports',              'ReportController', 'index'],
    ['POST',   'reports',              'ReportController', 'store'],
    ['GET',    'reports/{id}',         'ReportController', 'show'],
    ['PUT',    'reports/{id}/status',  'ReportController', 'updateStatus'],
    ['GET',    'reports/{id}/photo',   'ReportController', 'photo'],

    ['GET',    'dashboard',      'DashboardController', 'summary'],

    ['GET',    'schedules',      'ScheduleController', 'index'],
    ['POST',   'schedules',      'ScheduleController', 'store'],
    ['PUT',    'schedules/{id}', 'ScheduleController', 'update'],
    ['DELETE', 'schedules/{id}', 'ScheduleController', 'destroy'],
];

// The endpoint is everything after "/api/" in the URL.
$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$position = strpos($path, '/api/');
$endpoint = $position === false ? '' : trim(substr($path, $position + 5), '/');
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Find the route matching both URL and HTTP method.
$match       = null;
$pathMatched = false;foreach ($routes as [$routeMethod, $pattern, $controllerName, $action]) {
    $regex = '#^' . str_replace('\{id\}', '(\d+)', preg_quote($pattern, '#')) . '$#';
    if (preg_match($regex, $endpoint, $found)) {
        $pathMatched = true;
        if ($routeMethod === $method) {
            $match = [$controllerName, $action, array_map('intval', array_slice($found, 1))];
            break;
        }
    }
}

if ($match === null) {
    Response::error($pathMatched ? 'Method not allowed.' : 'Endpoint not found.', $pathMatched ? 405 : 404);
}

Auth::startSession();

// Every request that changes data must carry the CSRF token.
if ($method !== 'GET') {
    Auth::verifyCsrf();
}

[$controllerName, $action, $params] = $match;
$controller = new $controllerName(getConnection());
$controller->$action(...$params);
