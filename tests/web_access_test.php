<?php
// Checks .htaccess rules and security headers (needs real Apache). Run: php tests\web_access_test.php http://localhost/waste-management
require_once __DIR__ . '/TestHelpers.php';

$site = rtrim($argv[1] ?? 'http://localhost/waste-management', '/');
echo "Web access tests against $site\n";

echo "\nPrivate files and folders must NOT be reachable from a browser (403 Forbidden or 404 Not Found)\n";
$private = [
    'database/schema.sql', 'database/seed.sql',
    'api/config/config.php', 'api/config/config.example.php', 'api/config/database.php',
    'api/models/UserModel.php', 'api/helpers/Auth.php', 'api/controllers/AuthController.php',
    'tests/api_test.php', 'tools/set-admin-password.php',
    'storage/', 'uploads/', 'uploads/.gitkeep',
];
foreach ($private as $path) {
    [$status] = rawHttp("$site/$path");
    check("/$path is blocked", in_array($status, [403, 404], true));
}

echo "\nThe pages must be reachable and carry their security headers\n";
[$status] = rawHttp("$site/");
check('the site address opens (redirect page)', $status === 200);
[$status, $headers] = rawHttp("$site/public/login.html");
check('login page opens', $status === 200);
check('login page: X-Content-Type-Options', hasHeader($headers, 'X-Content-Type-Options', 'nosniff'));
check('login page: X-Frame-Options', hasHeader($headers, 'X-Frame-Options', 'DENY'));
check('login page: Content-Security-Policy forbids inline scripts', hasHeader($headers, 'Content-Security-Policy', "script-src 'self'")
    && !hasHeader($headers, 'Content-Security-Policy', 'unsafe-inline'));
[$status, $headers] = rawHttp("$site/public/js/api.js");
check('JavaScript files are served too', $status === 200);
[$status] = rawHttp("$site/api/zones");
check('the API is reachable through the .htaccess rewrite (401 = "log in first")', $status === 401);

finishTests();
