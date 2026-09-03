<?php
/**
 * Scheduled backup runner for Mpeli OutFit Store.
 *
 * IMPORTANT: stand-alone script. Works no matter where the file lives,
 * as long as it can find the application root via an environment variable
 * (APP_ROOT) or by walking up from its own location to the folder containing
 * config/database.php.
 *
 * Recommended deployment: COPY this file to the server's BACKUP directory
 * (e.g. /home/mpeljgto/backups/cron_backup.php) which is OUTSIDE public_html,
 * and set APP_ROOT=/home/mpeljgto/public_html in the cron command.
 *
 * Namecheap cPanel cron examples:
 *
 *   # Daily database backup (02:15)
 *   15 2 * * * /usr/local/bin/php -d auto_prepend_file= /home/mpeljgto/backups/cron_backup.php daily
 *
 *   # Weekly full backup (Sunday 03:15)
 *   15 3 * * 0 /usr/local/bin/php -d auto_prepend_file= /home/mpeljgto/backups/cron_backup.php weekly
 *
 *   # Monthly long-term database backup (1st 04:15)
 *   15 4 1 * * /usr/local/bin/php -d auto_prepend_file= /home/mpeljgto/backups/cron_backup.php monthly
 *
 * If APP_ROOT env is set, prefix the command with: APP_ROOT=/home/mpeljgto/public_html
 * (cPanel /bin/bash cron allows env assignment, e.g.:
 *   APP_ROOT=/home/mpeljgto/public_html /usr/local/bin/php .../cron_backup.php daily )
 *
 * Usage:
 *   php cron_backup.php daily|weekly|monthly|cleanup
 *
 * Does NOT require a logged-in web session. It authenticates to MySQL purely
 * through the same config/database.php env vars used by the web app, so no
 * credentials are embedded here.
 */

declare(strict_types=1);

error_reporting(E_ERROR | E_PARSE);
date_default_timezone_set('Africa/Dar_es_Salaam');

// Resolve the application root:
//   1. Explicit env override
//   2. Walk up from this file looking for config/database.php
function resolve_app_root(): string
{
    $env = getenv('APP_ROOT');
    if ($env && is_file($env . '/config/database.php')) {
        return rtrim($env, '/\\');
    }

    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (is_file($dir . '/config/database.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return '';
}

$root = resolve_app_root();
if ($root === '') {
    fwrite(STDERR, "ERROR: Could not locate the application root (config/database.php). Set APP_ROOT.\n");
    exit(1);
}

require $root . '/config/database.php';
require_once $root . '/services/BackupService.php';

// We run outside the web request context; the respond()/audit_log helpers
// won't function, so we implement a minimal local audit writer.

function cron_log(string $message): void
{
    global $root;
    $logDir = $root . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [scheduler] ' . $message . PHP_EOL;
    @file_put_contents($logDir . '/backup.log', $line, FILE_APPEND | LOCK_EX);
}

$type = strtolower((string)($argv[1] ?? 'daily'));

if (!in_array($type, ['daily', 'weekly', 'monthly', 'cleanup'], true)) {
    cron_log("Invalid cron argument: {$type}. Use daily|weekly|monthly|cleanup.");
    exit(1);
}

try {
    $svc = new BackupService();

    if ($type === 'daily') {
        $result = $svc->createBackup(BackupService::TYPE_DATABASE, 'scheduled_daily');
        cron_log('daily database backup: ' . ($result['success'] ? 'OK ' . ($result['filename'] ?? '') : 'FAILED ' . ($result['message'] ?? '')));
        if (!$result['success']) {
            exit(1);
        }
    } elseif ($type === 'weekly') {
        $result = $svc->createBackup(BackupService::TYPE_FULL, 'scheduled_weekly');
        cron_log('weekly full backup: ' . ($result['success'] ? 'OK ' . ($result['filename'] ?? '') : 'FAILED ' . ($result['message'] ?? '')));
        if (!$result['success']) {
            exit(1);
        }
    } elseif ($type === 'monthly') {
        $result = $svc->createBackup(BackupService::TYPE_DATABASE, 'scheduled_monthly');
        cron_log('monthly database backup: ' . ($result['success'] ? 'OK ' . ($result['filename'] ?? '') : 'FAILED ' . ($result['message'] ?? '')));
        if (!$result['success']) {
            exit(1);
        }
    }

    // Always run retention cleanup after scheduled backups
    $cleanup = $svc->applyRetention('scheduled');
    cron_log('retention cleanup: kept ' . count($cleanup['kept'] ?? []) . ', deleted ' . count($cleanup['deleted'] ?? []));

    echo "OK\n";
    exit(0);
} catch (Throwable $e) {
    cron_log('scheduler error: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
