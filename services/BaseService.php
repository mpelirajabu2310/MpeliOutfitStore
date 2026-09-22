<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/timezone_helpers.php';

class BaseService
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = get_db();
    }
}
