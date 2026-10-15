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
            'SELECT user_id, fname, lname, email, phone_number, address, role, password_hash
             FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, fname, lname, email, phone_number, address, role
             FROM users WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // No role parameter on purpose: public registration can only create residents.
    public function createResident(string $fname, string $lname, string $email,
                                   ?string $phone, string $address, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (fname, lname, email, phone_number, address, password_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$fname, $lname, $email, $phone, $address, $passwordHash]);
        return (int) $this->pdo->lastInsertId();
    }

    public function countResidents(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'resident'")->fetchColumn();
    }
}
