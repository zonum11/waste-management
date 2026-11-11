<?php
// Collection schedules: residents view active ones, admins manage all.
class ScheduleController
{
    private const DAYS     = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    private const STATUSES = ['active', 'inactive'];

    private PDO $pdo;
    private ScheduleModel $schedules;
    private ZoneModel $zones;

    public function __construct(PDO $pdo)
    {
        $this->pdo       = $pdo;
        $this->schedules = new ScheduleModel($pdo);
        $this->zones     = new ZoneModel($pdo);
    }

    // GET schedules. Admins: optional ?zone_id= and ?status=. Residents: always only their own zone.
    public function index(): void
    {
        $user = Auth::requireLogin($this->pdo);

        $zoneId = isset($_GET['zone_id']) && ctype_digit((string) $_GET['zone_id']) ? (int) $_GET['zone_id'] : null;

        if ($user['role'] === 'admin') {
            $status = $_GET['status'] ?? null;
            $status = in_array($status, self::STATUSES, true) ? $status : null;
        } else {
            // Residents never see inactive schedules, and only their own zone's schedules.
            // The zone comes from their account, not from the URL, so it cannot be changed by editing the request.
            $status = 'active';
            if ($user['zone_id'] === null) {
                Response::success('OK', ['schedules' => []]);   // no zone chosen yet
            }
            $zoneId = (int) $user['zone_id'];
        }

        Response::success('OK', ['schedules' => $this->schedules->list($status, $zoneId)]);
    }

    // POST schedules (admin only).
    public function store(): void
    {
        Auth::requireAdmin($this->pdo);
        [$day, $time, $status, $zoneIds] = $this->readAndValidate(Request::jsonBody());

        $id = $this->schedules->create($day, $time, $status, $zoneIds);
        Response::created('Schedule added.', ['schedule' => $this->schedules->findById($id)]);
    }

    // PUT schedules/{id} (admin only).
    public function update(int $id): void
    {
        Auth::requireAdmin($this->pdo);
        $this->findOrFail($id);
        [$day, $time, $status, $zoneIds] = $this->readAndValidate(Request::jsonBody());

        $this->schedules->update($id, $day, $time, $status, $zoneIds);
        Response::success('Schedule updated.', ['schedule' => $this->schedules->findById($id)]);
    }

    // DELETE schedules/{id} (admin only).
    public function destroy(int $id): void
    {
        Auth::requireAdmin($this->pdo);
        $this->findOrFail($id);

        $this->schedules->delete($id);
        Response::success('Schedule deleted.');
    }

    // Used by store() and update(): validates the data or replies 422.
    private function readAndValidate(array $data): array
    {
        $v = new Validator();
        $day     = $v->oneOf($data, 'collect_day', 'collection day', self::DAYS);
        $time    = $v->time($data, 'collect_time', 'collection time');
        $status  = $v->oneOf($data, 'status', 'status', self::STATUSES, 'active');
        $zoneIds = $v->idList($data, 'zone_ids', 'zones');

        if (!$v->hasErrors() && !$this->zones->allExist($zoneIds)) {
            Response::error('Please correct the highlighted fields.', 422,
                ['zone_ids' => 'One or more selected zones do not exist.']);
        }
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        return [$day, $time, $status, $zoneIds];
    }

    private function findOrFail(int $id): array
    {
        $schedule = $this->schedules->findById($id);
        if ($schedule === null) {
            Response::error('Schedule not found.', 404);
        }
        return $schedule;
    }
}
