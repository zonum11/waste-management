<?php
// Database queries for the zones table.
class ZoneModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Every zone, with its full address text for the Register page dropdown.
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT zone_id, zone_name, barangay_name, description FROM zones ORDER BY zone_name'
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['zone_id'] = (int) $row['zone_id'];
            $row['address'] = self::addressOf($row);
        }
        return $rows;
    }

    public function findById(int $zoneId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT zone_id, zone_name, barangay_name, description FROM zones WHERE zone_id = ?');
        $stmt->execute([$zoneId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // "Barangay Pogi Zone 1, Sitio Merete": the address residents pick when they register.
    public static function addressOf(array $zone): string
    {
        $address = $zone['barangay_name'] . ' ' . $zone['zone_name'];
        $sitio   = trim((string) ($zone['description'] ?? ''));
        return $sitio === '' ? $address : $address . ', ' . $sitio;
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
