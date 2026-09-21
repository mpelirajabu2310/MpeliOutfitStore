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

        // Tie the MySQL session clock to East Africa time (same as the PHP
        // timezone above: Africa/Dar_es_Salaam, UTC+3, no DST) so NOW() /
        // CURRENT_TIMESTAMP writes and TIMESTAMP reads always agree with PHP.
        // Session-scoped: no server-wide config change, no data mutation.
        // DATETIME values are still returned verbatim (no double conversion),
        // while TIMESTAMP values are converted exactly once by MySQL.
        $pdo->exec("SET time_zone = '+03:00'");
    }
    return $pdo;
}

/**
 * Backup configuration — NON-SECRET settings only.
 *
 * IMPORTANT: No database credentials or secrets live here. These values simply
 * tune how automated backups behave. Environment variables (e.g. set in a
 * cron wrapper on Namecheap cPanel) can override the defaults.
 *
 * Returns:
 *   'enabled'                  Whether scheduled backups are enabled.
 *   'scheduled_db_command'     Absolute path override for mysqldump (if known).
 *   'preferred_storage_dir'    Optional override for the backup directory.
 */
function get_backup_config(): array
{
    return [
        'enabled'               => getenv('BACKUP_ENABLED') !== '0',
        'scheduled_db_command'  => getenv('MYSQLDUMP_PATH') ?: '',
        'preferred_storage_dir' => getenv('BACKUP_DIR') ?: '',
    ];
}
