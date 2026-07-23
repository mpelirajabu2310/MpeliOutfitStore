<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class MigrationService
{
    private PDO $db;
    private string $backupDir;
    private string $logDir;

    private const REQUIRED_TABLES = [
        'users', 'products', 'product_variants', 'categories',
        'sales', 'sale_items', 'payments', 'customers',
        'expenses', 'shop_settings', 'inventory_movements',
    ];

    private const MIGRATION_HISTORY_TABLE = 'migration_history';

    public function __construct()
    {
        $this->db = get_db();
        $this->backupDir = dirname(__DIR__) . '/backups';
        $this->logDir = dirname(__DIR__) . '/logs';
        $this->ensureDirectories();
    }

    // ─── Backup ──────────────────────────────────────────────────────────────

    public function createBackup(string $reason = 'pre_migration'): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $this->backupDir . "/backup_{$reason}_{$timestamp}.sql";

        $tables = $this->getAllTables();
        $sql = "-- MpeliOutFitStore Database Backup\n";
        $sql .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Reason: {$reason}\n";
        $sql .= "-- Tables: " . count($tables) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $isView = $this->isView($table);
            $sql .= "-- " . ($isView ? "View" : "Table") . ": {$table}\n";
            $dropType = $isView ? 'VIEW' : 'TABLE';
            $sql .= "DROP {$dropType} IF EXISTS `{$table}`;\n";

            $createStmt = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch();
            if ($createStmt) {
                $ddl = $createStmt['Create Table'] ?? $createStmt['Create View'] ?? '';
                if ($ddl !== '') {
                    $sql .= $ddl . ";\n\n";
                }
            }

            if (!$isView) {
                $rows = $this->db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
                if (!empty($rows)) {
                    $cols = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
                    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
                    foreach ($rows as $row) {
                        $values = array_map(function ($v) {
                            if ($v === null) return 'NULL';
                            return $this->db->quote((string)$v);
                        }, $row);
                        $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        @file_put_contents($backupFile, $sql, LOCK_EX);

        $size = @filesize($backupFile) ?: 0;
        $this->logMigration('backup_created', "Backup: {$backupFile} ({$size} bytes, {$reason})");

        return [
            'file' => $backupFile,
            'size' => $size,
            'tables' => count($tables),
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function listBackups(): array
    {
        $backups = [];
        $files = glob($this->backupDir . '/backup_*.sql');
        if ($files) {
            rsort($files);
            foreach ($files as $file) {
                $backups[] = [
                    'file' => $file,
                    'filename' => basename($file),
                    'size' => @filesize($file) ?: 0,
                    'created' => date('Y-m-d H:i:s', @filemtime($file) ?: 0),
                ];
            }
        }
        return $backups;
    }

    // ─── Analyze ─────────────────────────────────────────────────────────────

    public function analyzeDatabase(): array
    {
        $tables = $this->getAllTables();
        $analysis = [];

        foreach ($tables as $table) {
            $row = $this->db->query("SELECT COUNT(*) AS row_count FROM `{$table}`")->fetch();
            $engine = $this->db->query("SHOW TABLE STATUS LIKE '{$table}'")->fetch();
            $analysis[$table] = [
                'rows' => (int)($row['row_count'] ?? 0),
                'engine' => $engine['Engine'] ?? 'unknown',
                'collation' => $engine['Collation'] ?? 'unknown',
                'size_bytes' => (int)($engine['Data_length'] ?? 0),
            ];
        }

        return $analysis;
    }

    public function identifyAffectedRecords(string $fieldName, string $table): array
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) AS total FROM `{$table}` WHERE `{$fieldName}` IS NULL");
            $total = (int)$stmt->fetchColumn();

            $stmt2 = $this->db->query("SELECT COUNT(*) AS total FROM `{$table}` WHERE `{$fieldName}` IS NOT NULL");
            $withValue = (int)$stmt2->fetchColumn();

            return [
                'table' => $table,
                'field' => $fieldName,
                'total_records' => $total + $withValue,
                'records_without_value' => $total,
                'records_with_value' => $withValue,
            ];
        } catch (PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Safe Migration ──────────────────────────────────────────────────────

    public function runMigration(string $migrationId, callable $upCallback, callable $downCallback = null): array
    {
        if ($this->isMigrationRun($migrationId)) {
            return ['success' => true, 'message' => "Migration '{$migrationId}' already applied.", 'skipped' => true];
        }

        $backup = $this->createBackup("pre_{$migrationId}");

        $this->db->beginTransaction();
        try {
            $upCallback($this->db);
            $this->recordMigration($migrationId, 'up');
            $this->db->commit();

            $this->logMigration('migration_applied', "Migration: {$migrationId}");

            return [
                'success' => true,
                'message' => "Migration '{$migrationId}' applied successfully.",
                'backup' => $backup['file'],
                'skipped' => false,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->logMigration('migration_failed', "Migration: {$migrationId}, Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => "Migration '{$migrationId}' failed: {$e->getMessage()}",
                'backup' => $backup['file'],
            ];
        }
    }

    public function rollbackMigration(string $migrationId, callable $downCallback): array
    {
        if (!$this->isMigrationRun($migrationId)) {
            return ['success' => false, 'message' => "Migration '{$migrationId}' was never applied."];
        }

        $backup = $this->createBackup("pre_rollback_{$migrationId}");

        $this->db->beginTransaction();
        try {
            $downCallback($this->db);
            $this->recordMigration($migrationId, 'down');
            $this->db->commit();

            $this->logMigration('migration_rolled_back', "Migration: {$migrationId}");

            return [
                'success' => true,
                'message' => "Migration '{$migrationId}' rolled back successfully.",
                'backup' => $backup['file'],
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->logMigration('rollback_failed', "Migration: {$migrationId}, Error: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => "Rollback '{$migrationId}' failed: {$e->getMessage()}",
                'backup' => $backup['file'],
            ];
        }
    }

    // ─── Historical Data Assignment ──────────────────────────────────────────

    public function assignLegacyRecords(string $table, string $ownerField, int $ownerId): array
    {
        $stmt = $this->db->prepare(
            "UPDATE `{$table}` SET `{$ownerField}` = :owner_id WHERE `{$ownerField}` IS NULL"
        );
        $stmt->execute(['owner_id' => $ownerId]);
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->logMigration('legacy_assignment', "{$affected} records in {$table} assigned to owner ID {$ownerId}");
        }

        return [
            'table' => $table,
            'field' => $ownerField,
            'records_affected' => $affected,
            'assigned_to' => $ownerId,
        ];
    }

    // ─── Migration History ───────────────────────────────────────────────────

    public function getMigrationHistory(): array
    {
        $this->ensureMigrationTable();
        try {
            $stmt = $this->db->query(
                "SELECT * FROM " . self::MIGRATION_HISTORY_TABLE . " ORDER BY applied_at DESC LIMIT 50"
            );
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function ensureMigrationTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::MIGRATION_HISTORY_TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_id VARCHAR(255) NOT NULL UNIQUE,
                direction ENUM("up", "down") NOT NULL DEFAULT "up",
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function isMigrationRun(string $migrationId): bool
    {
        $this->ensureMigrationTable();
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM " . self::MIGRATION_HISTORY_TABLE . " WHERE migration_id = :id AND direction = 'up'"
        );
        $stmt->execute(['id' => $migrationId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function recordMigration(string $migrationId, string $direction): void
    {
        $this->ensureMigrationTable();
        $stmt = $this->db->prepare(
            "INSERT INTO " . self::MIGRATION_HISTORY_TABLE . " (migration_id, direction) VALUES (:id, :dir)"
        );
        $stmt->execute(['id' => $migrationId, 'dir' => $direction]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getAllTables(): array
    {
        $tables = [];
        $rows = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    private function isView(string $name): bool
    {
        $stmt = $this->db->prepare("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name");
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row['TABLE_TYPE'] ?? '') === 'VIEW';
    }

    private function ensureDirectories(): void
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0750, true);
        }
        $htaccess = $this->backupDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
    }

    private function logMigration(string $event, string $details): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [migration] [{$event}] {$details}" . PHP_EOL;
        @file_put_contents($this->logDir . '/migration.log', $logLine, FILE_APPEND | LOCK_EX);
    }
}
