<?php
// Lists waste types (read-only, loaded from seed.sql) for the report form.
class WasteTypeController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function index(): void
    {
        Auth::requireLogin($this->pdo);
        Response::success('OK', ['waste_types' => (new WasteTypeModel($this->pdo))->all()]);
    }
}
