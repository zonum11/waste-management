<?php
// Lists zones (read-only, loaded from seed.sql) for dropdowns and checkboxes. Zone names are not private.
class ZoneController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function index(): void
    {
        // Open to everyone on purpose: the Register page needs it before anyone is logged in.
        Response::success('OK', ['zones' => (new ZoneModel($this->pdo))->all()]);
    }
}
