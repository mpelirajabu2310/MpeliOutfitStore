<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class BaseService
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = get_db();
    }
}
