<?php
// Lists zones (read-only, loaded from seed.sql) for dropdowns and checkboxes.
class ZoneController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function index(): void
    {
        Auth::requireLogin($this->pdo);
        Response::success('OK', ['zones' => (new ZoneModel($this->pdo))->all()]);
    }
}
