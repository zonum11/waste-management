<?php
// Provides the numbers for the admin dashboard.
class DashboardController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function summary(): void
    {
        Auth::requireAdmin($this->pdo);

        $reports = new ReportModel($this->pdo);

        // The 5 newest reports for the "Latest reports" table.
        $recent = [];
        foreach ($reports->list([], 5, 0) as $row) {
            $recent[] = [
                'report_id'     => (int) $row['report_id'],
                'issue_type'    => $row['issue_type'],
                'zone_name'     => $row['zone_name'],
                'reporter_name' => $row['fname'] . ' ' . $row['lname'],
                'status'        => $row['current_status'],
                'date_reported' => $row['date_reported'],
            ];
        }

        Response::success('OK', [
            'report_counts'    => $reports->countsByStatus(),
            'recent_reports'   => $recent,
            'active_schedules' => (new ScheduleModel($this->pdo))->countActive(),
            'residents'        => (new UserModel($this->pdo))->countResidents(),
        ]);
    }
}
