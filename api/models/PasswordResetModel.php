<?php
// Stores "Forgot password?" links. Only a hash of each token is saved.
class PasswordResetModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Replaces any older link of this user with a new one.
    public function create(int $userId, string $tokenHash, int $lifetimeSeconds): void
    {
        $this->deleteForUser($userId);
        $stmt = $this->pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $tokenHash, date('Y-m-d H:i:s', time() + $lifetimeSeconds)]);
    }

    // The user id behind a valid, unexpired token, or null.
    public function findUserId(string $tokenHash): ?int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > ?');
        $stmt->execute([$tokenHash, date('Y-m-d H:i:s')]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function deleteForUser(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    }

    // Housekeeping: removes links that have run out.
    public function deleteExpired(): void
    {
        $this->pdo->prepare('DELETE FROM password_resets WHERE expires_at <= ?')->execute([date('Y-m-d H:i:s')]);
    }
}
