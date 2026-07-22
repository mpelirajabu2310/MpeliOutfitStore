<?php
declare(strict_types=1);

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = '127.0.0.1';
        $database = 'clothing_shop_management';
        $username = 'root';
        $password = '';
        $charset = 'utf8mb4';
        $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
