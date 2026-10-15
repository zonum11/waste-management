<?php
// Database queries for the read-only waste_types list.
class WasteTypeModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT type_id, type_name, description FROM waste_types ORDER BY type_name'
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['type_id'] = (int) $row['type_id'];
        }
        return $rows;
    }

    // True if every id is a real waste type (an empty list is fine).
    public function allExist(array $typeIds): bool
    {
        if (!$typeIds) {
            return true;
        }
        $placeholders = implode(', ', array_fill(0, count($typeIds), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM waste_types WHERE type_id IN ($placeholders)");
        $stmt->execute($typeIds);
        return (int) $stmt->fetchColumn() === count($typeIds);
    }
}
