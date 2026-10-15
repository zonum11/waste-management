<?php
// Handles registration, login, logout and "who am I?".
class AuthController
{
    private PDO $pdo;
    private UserModel $users;

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
        $address = $v->address($data);
        $password = $v->newPassword($data);

        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        if ($this->users->findByEmail($email) !== null) {
            Response::error('Please correct the highlighted fields.', 409, ['email' => 'This email is already registered.']);
        }

        // Store only a one-way hash, never the password itself.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->users->createResident($fname, $lname, $email, $phone === '' ? null : $phone, $address, $hash);
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

    // Only the fields the browser is allowed to see (no password hash).
    private function publicUser(array $user): array
    {
        return [
            'user_id' => (int) $user['user_id'],
            'fname'   => $user['fname'],
            'lname'   => $user['lname'],
            'email'   => $user['email'],
            'role'    => $user['role'],
        ];
    }
}
