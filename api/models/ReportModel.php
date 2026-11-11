<?php
// Database queries for waste reports (reports, waste types and status history).
class ReportModel
{
    // Allowed values; they must match the ENUM lists in database/schema.sql.
    public const STATUSES    = ['pending', 'in_progress', 'resolved', 'rejected'];
    public const ISSUE_TYPES = ['missed_collection', 'improper_disposal', 'overflowing_garbage', 'other'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Saves report, photos, waste types and first history entry in one transaction.
    // $photoNames are the random file names already saved in the uploads folder.
    public function create(int $userId, int $zoneId, string $issueType, string $description,
                           array $photoNames, array $typeIds): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO waste_reports (user_id, zone_id, issue_type, issue_description)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $zoneId, $issueType, $description]);
            $reportId = (int) $this->pdo->lastInsertId();

            $photoStmt = $this->pdo->prepare('INSERT INTO report_photos (report_id, file_name, sort_order) VALUES (?, ?, ?)');
            foreach (array_values($photoNames) as $i => $name) {
                $photoStmt->execute([$reportId, $name, $i + 1]);
            }

            $link = $this->pdo->prepare('INSERT INTO report_waste_types (report_id, type_id) VALUES (?, ?)');
            foreach ($typeIds as $typeId) {
                $link->execute([$reportId, $typeId]);
            }

            $this->pdo->prepare(
                "INSERT INTO report_history (report_id, user_id, remarks, status) VALUES (?, ?, NULL, 'pending')"
            )->execute([$reportId, $userId]);

            $this->pdo->commit();
            return $reportId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Counts reports matching the filters (used to work out page numbers).
    public function count(array $filters): int
    {
        [$where, $params] = $this->filters($filters);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM waste_reports r $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // Number of reports per status, plus a 'total' key.
    public function countsByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->pdo->query('SELECT current_status, COUNT(*) AS n FROM waste_reports GROUP BY current_status') as $row) {
            $counts[$row['current_status']] = (int) $row['n'];
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    // One page of reports, newest first.
    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($filters);

        // (int) ensures only a plain number is placed in the SQL text.
        $sql = "SELECT r.report_id, r.issue_type, r.issue_description, r.current_status, r.date_reported,
                       CASE WHEN EXISTS (SELECT 1 FROM report_photos p WHERE p.report_id = r.report_id) THEN 1 ELSE 0 END AS has_photo,
                       z.zone_name, u.fname, u.lname
                FROM waste_reports r
                JOIN zones z ON z.zone_id = r.zone_id
                JOIN users u ON u.user_id = r.user_id
                $where
                ORDER BY r.date_reported DESC, r.report_id DESC
                LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // The waste types of this page's reports, fetched in one query (shown as "Streams Reported").
        $ids = array_map(fn($row) => (int) $row['report_id'], $rows);
        $types = array_fill_keys($ids, []);
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $typeStmt = $this->pdo->prepare(
                "SELECT rwt.report_id, wt.type_name
                 FROM report_waste_types rwt
                 JOIN waste_types wt ON wt.type_id = rwt.type_id
                 WHERE rwt.report_id IN ($marks)
                 ORDER BY wt.type_name"
            );
            $typeStmt->execute($ids);
            foreach ($typeStmt->fetchAll() as $type) {
                $types[(int) $type['report_id']][] = $type['type_name'];
            }
        }
        foreach ($rows as &$row) {
            $row['waste_type_names'] = $types[(int) $row['report_id']];
        }
        unset($row);
        return $rows;
    }

    // One full report (reporter, zone, waste types, history), or null if missing.
    public function findById(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.report_id, r.user_id, r.issue_type, r.issue_description,
                    r.current_status, r.date_reported,
                    z.zone_id, z.zone_name, z.barangay_name,
                    u.fname, u.lname, u.email, u.phone_number, u.address
             FROM waste_reports r
             JOIN zones z ON z.zone_id = r.zone_id
             JOIN users u ON u.user_id = r.user_id
             WHERE r.report_id = ?'
        );
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if ($report === false) {
            return null;
        }

        $types = $this->pdo->prepare(
            'SELECT wt.type_id, wt.type_name
             FROM report_waste_types rwt
             JOIN waste_types wt ON wt.type_id = rwt.type_id
             WHERE rwt.report_id = ?
             ORDER BY wt.type_name'
        );
        $types->execute([$reportId]);
        $report['waste_types'] = $types->fetchAll();

        $photos = $this->pdo->prepare('SELECT photo_id, file_name FROM report_photos WHERE report_id = ? ORDER BY sort_order, photo_id');
        $photos->execute([$reportId]);
        $report['photos'] = $photos->fetchAll();

        $history = $this->pdo->prepare(
            'SELECT h.status, h.remarks, h.updated_at, u.role AS updated_by_role
             FROM report_history h
             JOIN users u ON u.user_id = h.user_id
             WHERE h.report_id = ?
             ORDER BY h.updated_at, h.history_id'
        );
        $history->execute([$reportId]);
        $report['history'] = $history->fetchAll();

        return $report;
    }

    // File name of one photo of a report (null if that report has no such photo).
    // With $photoId null it returns the report's first photo.
    public function photoFile(int $reportId, ?int $photoId = null): ?string
    {
        if ($photoId === null) {
            $stmt = $this->pdo->prepare('SELECT file_name FROM report_photos WHERE report_id = ? ORDER BY sort_order, photo_id LIMIT 1');
            $stmt->execute([$reportId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT file_name FROM report_photos WHERE report_id = ? AND photo_id = ?');
            $stmt->execute([$reportId, $photoId]);
        }
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    // Changes the status and records it in the history in one transaction.
    public function updateStatus(int $reportId, int $adminId, string $status, ?string $remarks): void
    {
        $remarks = ($remarks === null || $remarks === '') ? null : $remarks;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE waste_reports SET current_status = ? WHERE report_id = ?')
                ->execute([$status, $reportId]);
            $this->pdo->prepare(
                'INSERT INTO report_history (report_id, user_id, remarks, status) VALUES (?, ?, ?, ?)'
            )->execute([$reportId, $adminId, $remarks, $status]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Builds the WHERE part; values go in $params so SQL cannot be injected.
    private function filters(array $filters): array
    {
        $where  = [];
        $params = [];

        if (isset($filters['user_id'])) {
            $where[]  = 'r.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (isset($filters['status'])) {
            $where[]  = 'r.current_status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['zone_id'])) {
            $where[]  = 'r.zone_id = ?';
            $params[] = $filters['zone_id'];
        }
        if (isset($filters['date_from'])) {
            $where[]  = 'r.date_reported >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (isset($filters['date_to'])) {
            // Include the whole end day: use "before the next day starts".
            $where[]  = 'r.date_reported < ?';
            $params[] = (new DateTime($filters['date_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
