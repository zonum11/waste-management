<?php
// Database queries for collection schedules and their zones.
class ScheduleModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Schedules ordered Monday to Sunday, then by time; filters are optional.
    public function list(?string $status = null, ?int $zoneId = null): array
    {
        $where  = [];
        $params = [];

        if ($status !== null) {
            $where[]  = 'cs.status = ?';
            $params[] = $status;
        }
        if ($zoneId !== null) {
            // Picks which schedules to show but keeps all their zones.
            $where[]  = 'cs.sched_id IN (SELECT sched_id FROM schedule_zones WHERE zone_id = ?)';
            $params[] = $zoneId;
        }

        return $this->fetchGrouped($where, $params);
    }

    public function findById(int $schedId): ?array
    {
        $rows = $this->fetchGrouped(['cs.sched_id = ?'], [$schedId]);
        return $rows[0] ?? null;
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM collection_schedule WHERE status = 'active'")->fetchColumn();
    }

    public function create(string $day, string $time, string $status, array $zoneIds): int
    {
        $this->pdo->beginTransaction();        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO collection_schedule (collect_day, collect_time, status) VALUES (?, ?, ?)'
            );
            $stmt->execute([$day, $time, $status]);
            $schedId = (int) $this->pdo->lastInsertId();

            $this->saveZones($schedId, $zoneIds);
            $this->pdo->commit();
            return $schedId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $schedId, string $day, string $time, string $status, array $zoneIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE collection_schedule SET collect_day = ?, collect_time = ?, status = ? WHERE sched_id = ?'
            );
            $stmt->execute([$day, $time, $status, $schedId]);

            // Replace the zone list: delete old links, add new ones.
            $this->pdo->prepare('DELETE FROM schedule_zones WHERE sched_id = ?')->execute([$schedId]);
            $this->saveZones($schedId, $zoneIds);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $schedId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM schedule_zones WHERE sched_id = ?')->execute([$schedId]);
            $this->pdo->prepare('DELETE FROM collection_schedule WHERE sched_id = ?')->execute([$schedId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function saveZones(int $schedId, array $zoneIds): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schedule_zones (sched_id, zone_id) VALUES (?, ?)');
        foreach ($zoneIds as $zoneId) {
            $stmt->execute([$schedId, $zoneId]);
        }
    }

    // Joins schedules with zones, then groups the rows into one entry per schedule.
    private function fetchGrouped(array $where, array $params): array
    {
        // CASE gives each day a number so Monday sorts first.
        $sql = "SELECT cs.sched_id, cs.collect_day, cs.collect_time, cs.status,
                       z.zone_id, z.zone_name, z.barangay_name
                FROM collection_schedule cs
                LEFT JOIN schedule_zones sz ON sz.sched_id = cs.sched_id
                LEFT JOIN zones z ON z.zone_id = sz.zone_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY CASE cs.collect_day
                      WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                      WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                      ELSE 7 END,
                  cs.collect_time, cs.sched_id, z.zone_name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $schedules = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['sched_id'];
            if (!isset($schedules[$id])) {
                $schedules[$id] = [
                    'sched_id'     => $id,
                    'collect_day'  => $row['collect_day'],
                    'collect_time' => substr($row['collect_time'], 0, 5),                    'status'       => $row['status'],
                    'zones'        => [],
                ];
            }
            if ($row['zone_id'] !== null) {
                $schedules[$id]['zones'][] = [
                    'zone_id'       => (int) $row['zone_id'],
                    'zone_name'     => $row['zone_name'],
                    'barangay_name' => $row['barangay_name'],
                ];
            }
        }
        return array_values($schedules);
    }
}
