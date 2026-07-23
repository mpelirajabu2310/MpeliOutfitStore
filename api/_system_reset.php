<?php
/**
 * COMPLETE SYSTEM RESET — MpeliOutFitStore
 * 
 * Part 1: Backup
 * Part 2-5: Clear all data
 * Part 6: Clean uploaded files
 * Part 8: Clear sessions/temp files
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';

$pdo = get_db();
$baseDir = dirname(__DIR__);
$backupDir = $baseDir . '/backups/pre_reset_' . date('Y-m-d_H-i-s');

echo "==============================================\n";
echo "  MPELI OUTFIT STORE — COMPLETE RESET\n";
echo "==============================================\n\n";

// ── PART 1: CREATE DATABASE BACKUP ──────────────────────
echo "[Part 1] Creating database backup...\n";

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
    file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
$sql = "-- MpeliOutFitStore Full Reset Backup\n";
$sql .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- Tables: " . count($tables) . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $row) {
    $table = $row[0];
    $sql .= "-- Table: {$table}\n";
    
    // Check if it's a view
    $typeCheck = $pdo->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch();
    if (($typeCheck['TABLE_TYPE'] ?? '') === 'VIEW') {
        $createView = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $ddl = $createView['Create View'] ?? '';
        if ($ddl) {
            $sql .= "DROP VIEW IF EXISTS `{$table}`;\n";
            $sql .= $ddl . ";\n\n";
        }
        continue;
    }
    
    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
    if ($createStmt) {
        $ddl = $createStmt['Create Table'] ?? '';
        if ($ddl) {
            $sql .= $ddl . ";\n\n";
        }
    }
    
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
    if (!empty($rows)) {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        foreach ($rows as $row_data) {
            $values = array_map(function ($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return $pdo->quote((string)$v);
            }, $row_data);
            $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }
}

$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

$backupFile = $backupDir . '/full_reset_backup.sql';
file_put_contents($backupFile, $sql);
$size = filesize($backupFile);
echo "  Backup saved: {$backupFile}\n";
echo "  Size: {$size} bytes\n\n";

// ── PART 2-5: CLEAR ALL DATA ────────────────────────────
echo "[Part 2-5] Clearing all data from tables...\n";

// Disable foreign key checks for clean truncation
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$truncateOrder = [
    'sale_items',
    'payments',
    'sales',
    'inventory_movements',
    'expenses',
    'product_variants',
    'products',
    'categories',
    'colors',
    'sizes',
    'customers',
    'users',
    'shop_settings',
    'migration_history',
    'best_selling_products',
    'daily_sales_report',
    'monthly_profit_report',
    'product_stock_summary',
];

foreach ($truncateOrder as $table) {
    try {
        // Check type first
        $typeCheck = $pdo->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch();
        if (($typeCheck['TABLE_TYPE'] ?? '') === 'VIEW') {
            echo "  SKIP (view): {$table}\n";
            continue;
        }
        $pdo->exec("TRUNCATE TABLE `{$table}`");
        echo "  CLEARED: {$table}\n";
    } catch (PDOException $e) {
        echo "  ERROR clearing {$table}: {$e->getMessage()}\n";
    }
}

// Also clear any auto-increment counters
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\n";

// ── PART 3: RESET USER MANAGEMENT ───────────────────────
echo "[Part 3] Verifying users table is empty...\n";
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
echo "  Users count: {$userCount}\n";
if ($userCount === 0) {
    echo "  OK — No administrator exists. Setup wizard will appear.\n\n";
} else {
    echo "  WARNING: Users still exist! Forcing delete...\n";
    $pdo->exec('DELETE FROM users');
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "  Users count after force delete: {$userCount}\n\n";
}

// ── PART 6: CLEAN UPLOADED FILES ────────────────────────
echo "[Part 6] Cleaning uploaded files...\n";

// Product images directory
$imageDirs = [
    $baseDir . '/uploads/products',
    $baseDir . '/uploads/avatars',
    $baseDir . '/uploads',
];

foreach ($imageDirs as $dir) {
    if (!is_dir($dir)) {
        echo "  SKIP (not exists): {$dir}\n";
        continue;
    }
    $files = glob($dir . '/*');
    $removed = 0;
    foreach ($files as $file) {
        if (is_file($file) && basename($file) !== '.htaccess') {
            unlink($file);
            $removed++;
        }
    }
    echo "  CLEANED: {$dir} ({$removed} files removed)\n";
}

// ── PART 8: CLEAR SESSIONS AND TEMP FILES ────────────────
echo "\n[Part 8] Clearing sessions and temp files...\n";

// Clear rate limit files
$ratelimitDir = $baseDir . '/logs/ratelimit';
if (is_dir($ratelimitDir)) {
    $files = glob($ratelimitDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    echo "  CLEARED: rate limit files\n";
}

// Clear activity log
$activityLog = $baseDir . '/logs/activity.log';
if (is_file($activityLog)) {
    file_put_contents($activityLog, '');
    echo "  CLEARED: activity.log\n";
}

// Clear health log
$healthLog = $baseDir . '/logs/health.log';
if (is_file($healthLog)) {
    file_put_contents($healthLog, '');
    echo "  CLEARED: health.log\n";
}

// Clear migration log
$migrationLog = $baseDir . '/logs/migration.log';
if (is_file($migrationLog)) {
    file_put_contents($migrationLog, '');
    echo "  CLEARED: migration.log\n";
}

// Clear maintenance flag
$maintenanceFlag = $baseDir . '/logs/.maintenance';
if (is_file($maintenanceFlag)) {
    unlink($maintenanceFlag);
    echo "  REMOVED: maintenance flag\n";
}

// Clear PHP session files
$sessionPath = sys_get_temp_dir();
$sessionFiles = glob($sessionPath . '/sess_*');
foreach ($sessionFiles as $file) {
    unlink($file);
}
echo "  CLEARED: session files\n";

// Clear any old backup files from E2E tests (keep our backup)
$oldBackups = glob($baseDir . '/backups/backup_*');
foreach ($oldBackups as $file) {
    unlink($file);
}
echo "  CLEARED: old test backups\n";

// ── PART 9: VALIDATION ──────────────────────────────────
echo "\n[Part 9] Validating reset...\n";
echo str_repeat("-", 45) . "\n";

$allZero = true;
$checks = [
    'users',
    'products',
    'categories',
    'product_variants',
    'sales',
    'sale_items',
    'payments',
    'expenses',
    'customers',
    'inventory_movements',
    'colors',
    'sizes',
    'shop_settings',
];

foreach ($checks as $table) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $status = $count === 0 ? 'OK' : 'FAIL';
        if ($count !== 0) $allZero = false;
        printf("  %-25s = %3d  [%s]\n", $table, $count, $status);
    } catch (PDOException $e) {
        printf("  %-25s ERROR\n", $table);
    }
}

echo str_repeat("-", 45) . "\n";
if ($allZero) {
    echo "\n  ALL TABLES EMPTY — RESET SUCCESSFUL\n\n";
} else {
    echo "\n  WARNING: Some tables still have data!\n\n";
}

// Check owner count
$ownerCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "OWNER"')->fetchColumn();
echo "  Owner accounts: {$ownerCount}\n";
echo "  System should show: First Administrator Setup\n";
echo "\n==============================================\n";
echo "  RESET COMPLETE\n";
echo "==============================================\n";
echo "  Backup: {$backupFile}\n";
echo "  Next: Refresh browser to see setup wizard.\n";
