<?php
// Database queries for the users table, using "?" placeholders against SQL injection.
class UserModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Includes password_hash for login; never send this row to the browser.
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.user_id, u.fname, u.lname, u.email, u.phone_number, u.address, u.role, u.password_hash,
                    u.zone_id, z.zone_name
             FROM users u LEFT JOIN zones z ON z.zone_id = u.zone_id
             WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.user_id, u.fname, u.lname, u.email, u.phone_number, u.address, u.role,
                    u.zone_id, z.zone_name
             FROM users u LEFT JOIN zones z ON z.zone_id = u.zone_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // No role parameter on purpose: public registration can only create residents.
    public function createResident(string $fname, string $lname, string $email,
                                   ?string $phone, string $address, int $zoneId, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (fname, lname, email, phone_number, address, zone_id, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$fname, $lname, $email, $phone, $address, $zoneId, $passwordHash]);
        return (int) $this->pdo->lastInsertId();
    }

    // Saves a resident's own details (not the address or zone). The password hash is changed only when one is given.
    public function updateProfile(int $userId, string $fname, string $lname, string $email,
                                  ?string $phone, ?string $passwordHash = null): void
    {
        $sql    = 'UPDATE users SET fname = ?, lname = ?, email = ?, phone_number = ?';
        $params = [$fname, $lname, $email, $phone];
        if ($passwordHash !== null) {
            $sql     .= ', password_hash = ?';
            $params[] = $passwordHash;
        }
        $stmt = $this->pdo->prepare($sql . ' WHERE user_id = ?');
        $stmt->execute([...$params, $userId]);
    }

    public function countResidents(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'resident'")->fetchColumn();
    }

    // Sets a new password hash (used by the password-reset link).
    public function updatePassword(int $userId, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$passwordHash, $userId]);
    }
}
