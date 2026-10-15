<?php
// Handles login sessions, CSRF tokens and role checks.
class Auth
{
    private const IDLE_TIMEOUT_SECONDS = 1800;
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // A host proxy may handle HTTPS and report it in this header.
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function startSession(): void
    {
        // Refuse session ids we did not create and ids sent in the URL (session fixation).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name('WASTESESSID');
        // Cookie flags: HTTPS-only, hidden from JavaScript (XSS), limited cross-site (CSRF).
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Log out sessions that have been idle too long.
        $now = time();
        if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > self::IDLE_TIMEOUT_SECONDS) {
            self::clearSession();
            session_start();
        }
        $_SESSION['last_activity'] = $now;
    }

    // CSRF protection: our pages send this secret token in a header.
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // Constant-time comparison, so timing cannot leak the token.
        if (!is_string($sent) || $sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            Response::error('Invalid or missing security token. Refresh the page and try again.', 403);
        }
    }

    public static function login(array $user): void
    {
        // A new session id after login prevents session fixation.
        session_regenerate_id(true);        $_SESSION['user_id']    = (int) $user['user_id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        self::clearSession();
    }

    public static function currentUser(PDO $pdo): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return (new UserModel($pdo))->findById((int) $_SESSION['user_id']);
    }

    public static function requireLogin(PDO $pdo): array
    {
        $user = self::currentUser($pdo);
        if ($user === null) {
            Response::error('Please log in to continue.', 401);
        }
        return $user;
    }

    public static function requireAdmin(PDO $pdo): array
    {
        $user = self::requireLogin($pdo);
        if ($user['role'] !== 'admin') {
            Response::error('You do not have permission to do that.', 403);
        }
        return $user;
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
