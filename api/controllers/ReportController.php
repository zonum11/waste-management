<?php
// Waste reports: residents submit and see their own, admins see all and set status.
class ReportController
{
    private PDO $pdo;
    private ReportModel $reports;

    public function __construct(PDO $pdo)
    {
        $this->pdo     = $pdo;
        $this->reports = new ReportModel($pdo);
    }

    // GET reports, with optional filters and paging.
    public function index(): void
    {
        $user    = Auth::requireLogin($this->pdo);
        $isAdmin = $user['role'] === 'admin';

        $filters = [];
        if (!$isAdmin) {
            // Residents only ever see their own reports.
            $filters['user_id'] = (int) $user['user_id'];        }
        $status = $_GET['status'] ?? null;
        if (in_array($status, ReportModel::STATUSES, true)) {
            $filters['status'] = $status;
        }
        if (isset($_GET['zone_id']) && ctype_digit((string) $_GET['zone_id']) && (int) $_GET['zone_id'] > 0) {
            $filters['zone_id'] = (int) $_GET['zone_id'];
        }
        // Missing or invalid dates are ignored.
        foreach (['date_from', 'date_to'] as $key) {
            $date = $this->dateParam($key);
            if ($date !== null) {
                $filters[$key] = $date;
            }
        }

        $perPage = isset($_GET['per_page']) && ctype_digit((string) $_GET['per_page'])
            ? min(50, max(1, (int) $_GET['per_page'])) : 10;
        $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;

        $total = $this->reports->count($filters);
        $rows  = $this->reports->list($filters, $perPage, ($page - 1) * $perPage);

        $items = [];
        foreach ($rows as $row) {
            $item = [
                'report_id'         => (int) $row['report_id'],
                'issue_type'        => $row['issue_type'],
                'issue_description' => $row['issue_description'],
                'status'            => $row['current_status'],
                'date_reported'     => $row['date_reported'],
                'has_photo'         => (bool) $row['has_photo'],
                'zone_name'         => $row['zone_name'],
            ];
            if ($isAdmin) {
                $item['reporter_name'] = $row['fname'] . ' ' . $row['lname'];
            }
            $items[] = $item;
        }

        Response::success('OK', [
            'reports'    => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show(int $id): void
    {
        $user   = Auth::requireLogin($this->pdo);
        $report = $this->findVisible($id, $user);
        Response::success('OK', ['report' => $this->present($report, $user['role'] === 'admin')]);
    }

    // POST reports (residents only); sent as a multipart form because of the photo.
    public function store(): void
    {
        $user = Auth::requireLogin($this->pdo);
        if ($user['role'] !== 'resident') {
            Response::error('Only residents can submit reports.', 403);
        }

        $data = Request::formData();        $v = new Validator();
        $zoneId       = $v->id($data, 'zone_id', 'zone');
        $issueType    = $v->oneOf($data, 'issue_type', 'issue type', ReportModel::ISSUE_TYPES);
        $description  = $v->longText($data, 'issue_description', 'Description', 10, 1000);
        $wasteTypeIds = $v->idList($data, 'waste_type_ids', 'waste types', false);

        $file = $_FILES['photo'] ?? null;
        $photoError = PhotoUpload::validate($file);
        if ($photoError !== null) {
            $v->addError('photo', $photoError);
        }

        if (!$v->hasErrors()) {
            if (!(new ZoneModel($this->pdo))->allExist([$zoneId])) {
                $v->addError('zone_id', 'Choose a valid zone.');
            }
            if (!(new WasteTypeModel($this->pdo))->allExist($wasteTypeIds)) {
                $v->addError('waste_type_ids', 'One or more waste types do not exist.');
            }
        }
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        // Save the photo only when all else is valid; delete it if the database fails.
        $photoName = PhotoUpload::hasFile($file) ? PhotoUpload::save($file) : null;
        try {
            $id = $this->reports->create((int) $user['user_id'], $zoneId, $issueType, $description, $photoName, $wasteTypeIds);
        } catch (Throwable $e) {
            if ($photoName !== null) {
                PhotoUpload::delete($photoName);
            }
            throw $e;
        }

        Response::created('Report submitted. Thank you!', ['report' => $this->present($this->reports->findById($id), false)]);
    }

    // PUT reports/{id}/status (admin only).
    public function updateStatus(int $id): void
    {
        $admin  = Auth::requireAdmin($this->pdo);
        $report = $this->reports->findById($id);
        if ($report === null) {
            Response::error('Report not found.', 404);
        }

        $data = Request::jsonBody();
        $v = new Validator();
        $status  = $v->oneOf($data, 'status', 'status', ReportModel::STATUSES);
        $remarks = $v->optionalText($data, 'remarks', 'Remarks', 500);

        if (!$v->hasErrors() && $status === $report['current_status']) {
            $v->addError('status', 'The report already has this status.');
        }
        if (!$v->hasErrors() && $status === 'rejected' && $remarks === '') {
            $v->addError('remarks', 'Please give a reason when rejecting a report.');
        }
        if ($v->hasErrors()) {
            Response::error('Please correct the highlighted fields.', 422, $v->errors());
        }

        $this->reports->updateStatus($id, (int) $admin['user_id'], $status, $remarks);
        Response::success('Status updated.', ['report' => $this->present($this->reports->findById($id), true)]);
    }

    // GET reports/{id}/photo: sends the image only if the user may see the report.
    public function photo(int $id): void
    {
        $user   = Auth::requireLogin($this->pdo);
        $report = $this->findVisible($id, $user);

        $path = $report['photo'] === null ? null : PhotoUpload::pathFor($report['photo']);
        if ($path === null || !is_file($path)) {
            Response::error('Photo not found.', 404);
        }

        header('Content-Type: ' . PhotoUpload::contentType($report['photo']));
        // Sandbox stops a web page disguised as an image from running scripts.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    // Returns a YYYY-MM-DD value only if it is a real calendar date.
    private function dateParam(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
    }

    // Others' reports give "not found", so residents cannot learn which ids exist.
    private function findVisible(int $id, array $user): array
    {
        $report = $this->reports->findById($id);
        $allowed = $report !== null && ($user['role'] === 'admin' || (int) $report['user_id'] === (int) $user['user_id']);
        if (!$allowed) {
            Response::error('Report not found.', 404);
        }
        return $report;
    }

    // Shapes a report for the browser; reporter contact details go to admins only.
    private function present(array $r, bool $forAdmin): array
    {
        $history = [];
        foreach ($r['history'] as $h) {
            $history[] = [
                'status'     => $h['status'],
                'remarks'    => $h['remarks'],
                'updated_at' => $h['updated_at'],
                // Admin names are hidden from residents.
                'updated_by' => $h['updated_by_role'] === 'admin' ? 'Barangay staff' : ($forAdmin ? 'Resident' : 'You'),
            ];
        }

        $out = [
            'report_id'         => (int) $r['report_id'],
            'issue_type'        => $r['issue_type'],
            'issue_description' => $r['issue_description'],
            'status'            => $r['current_status'],
            'date_reported'     => $r['date_reported'],
            'has_photo'         => $r['photo'] !== null,
            'zone'              => ['zone_id' => (int) $r['zone_id'], 'zone_name' => $r['zone_name'], 'barangay_name' => $r['barangay_name']],
            'waste_types'       => array_map(fn($t) => ['type_id' => (int) $t['type_id'], 'type_name' => $t['type_name']], $r['waste_types']),
            'history'           => $history,
        ];

        if ($forAdmin) {
            $out['reporter'] = [
                'name'         => $r['fname'] . ' ' . $r['lname'],
                'email'        => $r['email'],
                'phone_number' => $r['phone_number'],
                'address'      => $r['address'],
            ];
        }
        return $out;
    }
}
