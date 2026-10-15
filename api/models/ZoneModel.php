<?php
// Database queries for the zones table.
class ZoneModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT zone_id, zone_name, barangay_name FROM zones ORDER BY zone_name'
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['zone_id'] = (int) $row['zone_id'];
        }
        return $rows;
    }

    public function allExist(array $zoneIds): bool
    {
        if (!$zoneIds) {
            return false;
        }
        // One "?" per id; only placeholders enter the SQL text (SQL injection safe).
        $placeholders = implode(', ', array_fill(0, count($zoneIds), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM zones WHERE zone_id IN ($placeholders)");
        $stmt->execute($zoneIds);
        return (int) $stmt->fetchColumn() === count($zoneIds);
    }
}
