<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Dar_es_Salaam');

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Production: set these environment variables on your hosting.
        // Development: defaults work with XAMPP out of the box.
        $host     = getenv('DB_HOST') ?: '127.0.0.1';
        $database = getenv('DB_NAME') ?: 'clothing_shop_management';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASS') ?: '';

        $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
