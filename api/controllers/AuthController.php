<?php
// Handles registration, login, logout, "who am I?", profile and password reset.
class AuthController
{
    private PDO $pdo;
    private UserModel $users;
    private const RESET_LIFETIME = 3600;   // seconds a reset link works

    public function __construct(PDO $pdo)
    {
        $this->pdo   = $pdo;
        $this->users = new UserModel($pdo);
    }

    public function csrf(): void
    {
        Response::success('OK', ['csrf_token' => Auth::csrfToken()]);
    }

    public function register(): void
    {
        $data = Request::jsonBody();
        $v = new Validator();

        $fname   = $v->name($data, 'fname', 'First name');
        $lname   = $v->name($data, 'lname', 'Last name');
        $email   = $v->email($data);
        $phone   = $v->phone($data);
        $zoneId  = $v->id($data, 'zone_id', 'address');   // the address dropdown sends the zone id
        $password = $v->newPassword($data);

        // The address text comes from the zones table, never from the browser.
        $zone = $zoneId > 0 ? (new ZoneModel($this->pdo))->findById($zoneId) : null;
        if ($zoneId > 0 && $zone === null) {
            $v->addError('zone_id', 'Choose a valid address.');
        }

        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        if ($this->users->findByEmail($email) !== null) {
            Response::error('Please correct the highlighted fields.', 409, ['email' => 'This email is already registered.']);
        }

        // Store only a one-way hash, never the password itself.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->users->createResident($fname, $lname, $email, $phone === '' ? null : $phone, ZoneModel::addressOf($zone), $zoneId, $hash);
        } catch (PDOException $e) {
            // Duplicate email at the same moment: the UNIQUE rule catches it.
            if ($e->getCode() === '23000') {
                Response::error('Please correct the highlighted fields.', 409, ['email' => 'This email is already registered.']);
            }
            throw $e;
        }

        Response::created('Registration successful. You can now log in.');
    }

    public function login(): void
    {
        $data = Request::jsonBody();
        $email    = strtolower(is_string($data['email'] ?? null) ? trim($data['email']) : '');
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';

        if ($email === '' || $password === '') {
            Response::error('Enter your email and password.', 422);
        }

        // If locked out, refuse before checking the password at all.
        $throttle = new LoginThrottle($email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $wait = $throttle->secondsToWait();
        if ($wait > 0) {
            header('Retry-After: ' . $wait);
            Response::error('Too many failed login attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).', 429);
        }

        $user = $this->users->findByEmail($email);

        // Always verify (dummy hash if no user) so timing hides which emails exist.
        $hash = $user['password_hash'] ?? '$2y$10$YSFpE3nnF39iDUhTBniYwerW1qz1Xws3Z61FMgTpshin4HIkInhjK';
        $passwordOk = password_verify($password, $hash);

        if ($user === null || !$passwordOk) {
            $throttle->recordFailure();
            // Same message for unknown email and wrong password, on purpose.
            Response::error('Invalid email or password.', 401);
        }

        $throttle->clear();
        Auth::login($user);

        Response::success('Login successful.', [
            'user'       => $this->publicUser($user),
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function logout(): void
    {
        Auth::logout();
        Response::success('You have been logged out.');
    }

    public function me(): void
    {
        $user = Auth::requireLogin($this->pdo);
        Response::success('OK', ['user' => $this->publicUser($user)]);
    }

    // PUT auth/profile - a resident edits their own details. Send only the fields to change:
    // fname, lname, email, phone_number, new_password (+ current_password).
    // The address and zone come from registration and cannot be changed here.
    // The email is the login name, so changing the email or the password needs the current password.
    public function updateProfile(): void
    {
        $user = Auth::requireLogin($this->pdo);
        if ($user['role'] !== 'resident') {
            Response::error('Only residents can edit their profile here.', 403);
        }

        $data = Request::jsonBody();
        $v    = new Validator();

        // A field that is not sent keeps its saved value.
        $fname   = array_key_exists('fname', $data) ? $v->name($data, 'fname', 'First name') : $user['fname'];
        $lname   = array_key_exists('lname', $data) ? $v->name($data, 'lname', 'Last name') : $user['lname'];
        $email   = array_key_exists('email', $data) ? $v->email($data) : strtolower($user['email']);
        $phone   = array_key_exists('phone_number', $data) ? $v->phone($data) : (string) $user['phone_number'];

        // An empty new_password means "keep my password".
        $newPassword     = is_string($data['new_password'] ?? null) ? $data['new_password'] : '';
        $passwordChanged = $newPassword !== '';
        if ($passwordChanged) {
            $v->newPassword($data, 'new_password');
        }

        $emailChanged    = $email !== strtolower($user['email']);
        $currentPassword = is_string($data['current_password'] ?? null) ? $data['current_password'] : '';

        if (!$v->hasErrors() && ($emailChanged || $passwordChanged) && $currentPassword === '') {
            $v->addError('current_password', 'Enter your current password to change your email or password.');
        }
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        if ($emailChanged || $passwordChanged) {
            // Wrong passwords here count towards the same lock-out as the login page.
            $throttle = new LoginThrottle(strtolower($user['email']), $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $wait = $throttle->secondsToWait();
            if ($wait > 0) {
                header('Retry-After: ' . $wait);
                Response::error('Too many failed attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).', 429);
            }

            $account = $this->users->findByEmail($user['email']);
            if ($account === null || !password_verify($currentPassword, $account['password_hash'])) {
                $throttle->recordFailure();
                Response::error('Please correct the highlighted fields.', 422, ['current_password' => 'Current password is incorrect.']);
            }
            $throttle->clear();
        }

        if ($emailChanged && $this->users->findByEmail($email) !== null) {
            Response::error('Please correct the highlighted fields.', 409, ['email' => 'This email is already registered.']);
        }

        try {
            $this->users->updateProfile(
                (int) $user['user_id'], $fname, $lname, $email,
                $phone === '' ? null : $phone,
                $passwordChanged ? password_hash($newPassword, PASSWORD_DEFAULT) : null
            );
        } catch (PDOException $e) {
            // Someone else took the email at the same moment: the UNIQUE rule catches it.
            if ($e->getCode() === '23000') {
                Response::error('Please correct the highlighted fields.', 409, ['email' => 'This email is already registered.']);
            }
            throw $e;
        }

        if ($passwordChanged) {
            session_regenerate_id(true);   // a new password starts a fresh session id
        }

        Response::success('Profile saved.', ['user' => $this->publicUser($this->users->findById((int) $user['user_id']))]);
    }

    // PUT auth/password: a logged-in resident sets a new password (new_password + confirm_password).
    // The password is checked by the same rules as on the Register page and saved only as a hash.
    public function changePassword(): void
    {
        $user = Auth::requireLogin($this->pdo);
        if ($user['role'] !== 'resident') {
            Response::error('Only residents can change their password here.', 403);
        }

        $data = Request::jsonBody();
        $v    = new Validator();

        $newPassword     = $v->newPassword($data, 'new_password');
        $confirmPassword = is_string($data['confirm_password'] ?? null) ? $data['confirm_password'] : '';
        if (!isset($v->errors()['new_password']) && !hash_equals($newPassword, $confirmPassword)) {
            $v->addError('confirm_password', 'The two passwords do not match.');
        }
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        $userId = (int) $user['user_id'];
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        (new PasswordResetModel($this->pdo))->deleteForUser($userId);   // an old emailed reset link stops working
        session_regenerate_id(true);                                    // a new password starts a fresh session id

        Response::success('Password changed.');
    }

    // POST auth/forgot-password - emails a reset link (valid for 1 hour) to a resident.
    // The reply is always the same, so nobody can use this page to find out which emails are registered.
    public function forgotPassword(): void
    {
        $data = Request::jsonBody();
        $v    = new Validator();
        $email = $v->email($data);
        if ($v->hasErrors() || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Enter a valid email address.', 422, ['email' => 'Enter a valid email address.']);
        }

        // Limit requests per email and device (the same 5-in-15-minutes rule as login).
        $throttle = new LoginThrottle('reset|' . $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $wait = $throttle->secondsToWait();
        if ($wait > 0) {
            header('Retry-After: ' . $wait);
            Response::error('Too many requests. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).', 429);
        }
        $throttle->recordFailure();

        $user = $this->users->findByEmail($email);
        if ($user !== null && $user['role'] === 'resident') {
            $resets = new PasswordResetModel($this->pdo);
            $resets->deleteExpired();

            $token = bin2hex(random_bytes(32));
            $resets->create((int) $user['user_id'], hash('sha256', $token), self::RESET_LIFETIME);

            $link = $this->siteUrl() . '/reset-password.html?token=' . $token;
            $body = "Hello {$user['fname']},\n\n"
                  . "We received a request to reset your password. Open this link to choose a new one (it works for 1 hour):\n\n"
                  . "$link\n\n"
                  . "If you did not ask for this, ignore this email. Your password stays the same.\n";
            $sent = Mailer::send($user['email'], 'Reset your password', $body);

            if (!$sent) {
                error_log('Password reset email could not be sent to a resident. Check the hosting mail settings.');
            }
            if (!empty(loadConfig()['debug'])) {
                // Local testing without a mail server: the link is written to the PHP error log.
                error_log('DEBUG password reset link: ' . $link);
            }
        }

        Response::success('If that email is registered, a reset link has been sent. Check your inbox and spam folder.');
    }

    // POST auth/reset-password - sets a new password using the emailed token.
    public function resetPassword(): void
    {
        $data  = Request::jsonBody();
        $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $v     = new Validator();
        $v->newPassword($data);
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        $resets = new PasswordResetModel($this->pdo);
        $userId = preg_match('/^[a-f0-9]{64}$/', $token) ? $resets->findUserId(hash('sha256', $token)) : null;
        if ($userId === null) {
            Response::error('This reset link is invalid or has expired. Please request a new one.', 400);
        }

        $this->users->updatePassword($userId, password_hash($data['password'], PASSWORD_DEFAULT));
        $resets->deleteForUser($userId);   // the link works only once

        // A successful reset also lifts any login lock-out for that account.
        $user = $this->users->findById($userId);
        if ($user !== null) {
            (new LoginThrottle(strtolower($user['email']), $_SERVER['REMOTE_ADDR'] ?? 'unknown'))->clear();
        }

        Response::success('Your password has been changed. You can now log in.');
    }

    // The public address of the site, for links in emails. Set app_url in config.php to be safe;
    // otherwise it is built from the current request.
    private function siteUrl(): string
    {
        $configured = loadConfig()['app_url'] ?? '';
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = Auth::isHttps() ? 'https' : 'http';
        $host   = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base   = substr($path, 0, (int) strpos($path, '/api/'));
        return "$scheme://$host$base/public";
    }

    // Only the fields the browser is allowed to see (no password hash).
    private function publicUser(array $user): array
    {
        return [
            'user_id' => (int) $user['user_id'],
            'fname'   => $user['fname'],
            'lname'   => $user['lname'],
            'email'   => $user['email'],
            'phone_number' => $user['phone_number'],
            'address' => $user['address'],
            'role'    => $user['role'],
            // null for admins and for old accounts that never had a zone.
            'zone'    => $user['zone_id'] === null ? null : ['zone_id' => (int) $user['zone_id'], 'zone_name' => $user['zone_name']],
        ];
    }
}
