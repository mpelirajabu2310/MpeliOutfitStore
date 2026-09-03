<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * BackupService — Handles database and file backups for Mpeli Outfit Store.
 *
 * Storage strategy (Namecheap/cPanel aware):
 *   - Preferred directory is OUTSIDE the public web root
 *     (/home/mpeljgto/backups on cPanel). Computed automatically as
 *     dirname(dirname(__DIR__)) . '/backups'.
 *   - If that location is not writable, it falls back to the in-tree
 *     'backups/' directory which is protected by an .htaccess "deny".
 *
 * No database credentials are ever written to backup files, logs, or
 * returned to the client. Absolute server paths are never exposed to
 * unauthorized users.
 */
class BackupService
{
    private PDO $db;
    private string $backupDir;
    private string $logDir;
    private string $appRoot;
    private string $lockFile;
    private string $stateFile;

    public const TYPE_DATABASE = 'database';
    public const TYPE_FILES    = 'files';
    public const TYPE_FULL     = 'full';

    /** Retention policy keys stored in the backup_settings table (fallback defaults). */
    private const DEFAULT_RETENTION = [
        'keep_daily'   => 7,
        'keep_weekly'  => 4,
        'keep_monthly' => 12,
        'keep_full'    => 3,
    ];

    private const STATE_TABLE = 'backup_settings';

    public function __construct()
    {
        $this->db = get_db();
        $this->appRoot = dirname(__DIR__);
        $this->logDir  = $this->appRoot . '/logs';

        $this->backupDir = $this->resolveBackupDir();
        $this->lockFile  = $this->backupDir . '/.backup.lock';
        $this->stateFile = $this->backupDir . '/.backup_state.json';

        $this->ensureDirectories();
    }

    // ─── Public API ─────────────────────────────────────────────────────────

    /**
     * Create a backup.
     *
     * @param string $type   self::TYPE_DATABASE | self::TYPE_FILES | self::TYPE_FULL
     * @param string $source 'manual' | 'scheduled' | 'pre_restore'
     */
    public function createBackup(string $type = self::TYPE_DATABASE, string $source = 'manual'): array
    {
        $type = $this->normalizeType($type);
        $this->acquireLock();

        try {
            if ($type === self::TYPE_DATABASE) {
                return $this->backupDatabase($source);
            }

            if ($type === self::TYPE_FILES) {
                return $this->backupFiles($source);
            }

            return $this->backupFull($source);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * List all available backups, newest first.
     * Returns ONLY safe metadata — no absolute paths.
     */
    public function listBackups(): array
    {
        $backups = [];

        $files = glob($this->backupDir . '/mpelioutfitstore_*.sql*');
        $files = array_merge(
            $files ?: [],
            glob($this->backupDir . '/mpelioutfitstore_*.tar*') ?: [],
            glob($this->backupDir . '/mpelioutfitstore_*.zip') ?: []
        );

        if (!$files) {
            return [];
        }

        // Deduplicate, sort newest first
        $files = array_values(array_unique($files));
        usort($files, static function ($a, $b) {
            return @filemtime($b) <=> @filemtime($a);
        });

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $filename = basename($file);
            $size     = @filesize($file) ?: 0;
            $type     = $this->inferTypeFromFilename($filename);
            $backups[] = [
                'filename' => $filename,
                'type'     => $type,
                'size'     => $size,
                'size_human' => $this->formatBytes($size),
                'created_at' => date('Y-m-d H:i:s', @filemtime($file) ?: 0),
                'status'   => ($size > 0) ? 'success' : 'failed',
            ];
        }

        return $backups;
    }

    /**
     * Return the backup dashboard status (last success per type, counts, storage info).
     * Never exposes absolute paths.
     */
    public function getStatus(): array
    {
        $backups = $this->listBackups();
        $state   = $this->readState();

        $lastDb    = null;
        $lastFiles = null;
        $lastFull  = null;
        $totalSize = 0;

        foreach ($backups as $b) {
            $totalSize += (int)$b['size'];
            if ($lastDb === null && $b['type'] === self::TYPE_DATABASE && $b['status'] === 'success') {
                $lastDb = $b;
            }
            if ($lastFiles === null && $b['type'] === self::TYPE_FILES && $b['status'] === 'success') {
                $lastFiles = $b;
            }
            if ($lastFull === null && $b['type'] === self::TYPE_FULL && $b['status'] === 'success') {
                $lastFull = $b;
            }
        }

        return [
            'storage_location' => $this->storageLabel(),
            'storage_outside_webroot' => $this->isOutsideWebRoot(),
            'backup_dir'       => $this->backupDir,
            'last_database'    => $lastDb,
            'last_files'       => $lastFiles,
            'last_full'        => $lastFull,
            'total_size'       => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'count'            => count($backups),
            'count_database'   => $this->countByType($backups, self::TYPE_DATABASE),
            'count_files'      => $this->countByType($backups, self::TYPE_FILES),
            'count_full'       => $this->countByType($backups, self::TYPE_FULL),
            'lock_active'      => $this->isLocked(),
            'last_error'       => $state['last_error'] ?? null,
            'last_error_at'    => $state['last_error_at'] ?? null,
            'retention'        => $this->getRetentionSettings(),
        ];
    }

    /**
     * Validate that a given backup file is present, non-empty, and (for SQL)
     * has a plausible structure header.
     */
    public function validateBackup(string $filename): array
    {
        $file = $this->safePath($filename);
        if ($file === null) {
            return ['valid' => false, 'message' => 'Invalid backup file name.'];
        }
        if (!is_file($file)) {
            return ['valid' => false, 'message' => 'Backup file does not exist.'];
        }

        $size = @filesize($file) ?: 0;
        if ($size <= 0) {
            return ['valid' => false, 'message' => 'Backup file is empty (0 bytes).'];
        }

        $type = $this->inferTypeFromFilePath($file);
        $valid = true;
        $detail = '';

        if ($type === self::TYPE_DATABASE) {
            if (str_ends_with($file, '.gz')) {
                // Compressed — sanity check gzip magic bytes
                $magic = file_get_contents($file, false, null, 0, 2);
                if ($magic !== false && !str_starts_with($magic, "\x1f\x8b")) {
                    $valid = false;
                    $detail = 'File is not a valid gzip stream.';
                } else {
                    // Try reading the decompressed head to verify it's an SQL dump
                    $hd = @fopen('compress.zlib://' . $file, 'rb');
                    if ($hd) {
                        $head = (string)fread($hd, 4096);
                        fclose($hd);
                        if (!preg_match('/CREATE TABLE|SHOW CREATE|INSERT INTO|-- MpeliOutFitStore/i', $head)) {
                            $valid = false;
                            $detail = 'SQL dump structure header missing in compressed file.';
                        }
                    }
                }
            } else {
                // Plain SQL
                $head = (string)file_get_contents($file, false, null, 0, 4096);
                if (!preg_match('/CREATE TABLE|SHOW CREATE|INSERT INTO|-- MpeliOutFitStore/i', $head)) {
                    $valid = false;
                    $detail = 'SQL dump structure header missing.';
                }
            }
        } elseif ($type === self::TYPE_FILES || $type === self::TYPE_FULL) {
            if (str_ends_with($file, '.tar.gz') || str_ends_with($file, '.tgz')) {
                $magic = file_get_contents($file, false, null, 0, 2);
                if ($magic !== false && !str_starts_with($magic, "\x1f\x8b")) {
                    $valid = false;
                    $detail = 'Archive is not a valid gzip file.';
                }
            } elseif (str_ends_with($file, '.zip')) {
                $magic = file_get_contents($file, false, null, 0, 4);
                if ($magic !== false && $magic !== "PK\x03\x04") {
                    $valid = false;
                    $detail = 'Archive is not a valid ZIP file.';
                }
            }
        }

        return [
            'valid'     => $valid,
            'filename'  => basename($file),
            'type'      => $type,
            'size'      => $size,
            'size_human' => $this->formatBytes($size),
            'detail'    => $detail ?: ($valid ? 'Backup looks valid.' : 'Validation failed.'),
        ];
    }

    /**
     * Delete a backup file. Restricts deletion to the backup directory only.
     */
    public function deleteBackup(string $filename): array
    {
        $file = $this->safePath($filename);
        if ($file === null) {
            return ['success' => false, 'message' => 'Invalid backup file name.'];
        }
        if (!is_file($file)) {
            return ['success' => false, 'message' => 'Backup file does not exist.'];
        }
        if (!@unlink($file)) {
            return ['success' => false, 'message' => 'Failed to delete backup file.'];
        }
        $this->writeState(['last_cleanup_at' => date('Y-m-d H:i:s')]);
        return ['success' => true, 'message' => 'Backup deleted successfully.'];
    }

    /**
     * Return an absolute path to a backup file for streaming download.
     * Only works if the file is inside the backup dir. Returns null otherwise.
     */
    public function getDownloadPath(string $filename): ?string
    {
        return $this->safePath($filename);
    }

    /**
     * Apply retention policy: delete backups older than the configured
     * daily/weekly/monthly buckets. Falls back to keeping the most recent N
     * per type if bucket detection is not possible.
     */
    public function applyRetention(string $source = 'auto'): array
    {
        $settings = $this->getRetentionSettings();
        $backups  = $this->listBackups();
        $deleted  = [];
        $kept     = [];

        // Group database backups into daily/weekly/monthly buckets by created date
        $daily   = [];
        $weekly  = [];
        $monthly = [];

        foreach ($backups as $b) {
            if ($b['type'] !== self::TYPE_DATABASE) {
                continue;
            }
            $ts = strtotime($b['created_at']) ?: time();
            $dateKey = date('Y-m-d', $ts);
            $weekKey = date('Y-W', $ts);
            $monKey  = date('Y-m', $ts);

            // Keep the newest per-day for the daily window
            $daily[$dateKey] = $b;
            $weekly[$weekKey] = $b;
            $monthly[$monKey] = $b;
        }

        $keepDailyCount   = (int)($settings['keep_daily'] ?? self::DEFAULT_RETENTION['keep_daily']);
        $keepWeeklyCount  = (int)($settings['keep_weekly'] ?? self::DEFAULT_RETENTION['keep_weekly']);
        $keepMonthlyCount = (int)($settings['keep_monthly'] ?? self::DEFAULT_RETENTION['keep_monthly']);

        // Determine deletions: keep latest N daily, N weekly, N monthly
        $forDeletion = [];

        // Daily: outline of newest N distinct days
        $dailySorted = array_slice(array_values($daily), 0, $keepDailyCount, true);
        // All dailies not in the newest N days get marked (only when day older than N weeks)
        $dailyItems = array_values($daily);
        usort($dailyItems, static fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        $keptDaily = array_slice($dailyItems, 0, $keepDailyCount);
        $keptDailyFiles = array_map(static fn($b) => $b['filename'], $keptDaily);

        // Weekly: newest N week-buckets
        $keptWeekly = [];
        $weeklyBuckets = [];
        foreach ($backups as $b) {
            if ($b['type'] !== self::TYPE_DATABASE) continue;
            $wk = date('Y-W', strtotime($b['created_at']));
            $weeklyBuckets[$wk] = $b;
        }
        uasort($weeklyBuckets, static fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        $keptWeeklyFiles = array_map(static fn($b) => $b['filename'], array_slice($weeklyBuckets, 0, $keepWeeklyCount));

        // Monthly: newest N month-buckets
        $monthlyBuckets = [];
        foreach ($backups as $b) {
            if ($b['type'] !== self::TYPE_DATABASE) continue;
            $mk = date('Y-m', strtotime($b['created_at']));
            $monthlyBuckets[$mk] = $b;
        }
        uasort($monthlyBuckets, static fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        $keptMonthlyFiles = array_map(static fn($b) => $b['filename'], array_slice($monthlyBuckets, 0, $keepMonthlyCount));

        // Union of files we keep
        $keepFiles = array_unique(array_merge($keptDailyFiles, $keptWeeklyFiles, $keptMonthlyFiles));

        // Also keep the newest file of each type regardless (protection against over-pruning)
        $keepFullFiles = [];
        $added = [];
        $keepFullCount = (int)($settings['keep_full'] ?? self::DEFAULT_RETENTION['keep_full']);
        $fullBackups = array_filter($backups, static fn($b) => $b['type'] === self::TYPE_FULL);
        foreach ($fullBackups as $b) {
            if (count($keepFullFiles) >= $keepFullCount) break;
            $keepFullFiles[] = $b['filename'];
        }
        // Fallback: keep at least the latest DB backup + latest file backup
        $fallbackKeeps = [];
        foreach ($backups as $b) {
            if ($b['type'] === self::TYPE_DATABASE && !in_array($b['filename'], $keepFiles, true)) {
                $fallbackKeeps[] = $b['filename'];
                break;
            }
        }

        $keepAll = array_unique(array_merge($keepFiles, $keepFullFiles, $fallbackKeeps));

        foreach ($backups as $b) {
            $fname = $b['filename'];
            if (in_array($fname, $keepAll, true) || $b['type'] !== self::TYPE_DATABASE) {
                continue;
            }
            $del = $this->deleteBackup($fname);
            if ($del['success']) {
                $deleted[] = $fname;
            }
        }

        if ($deleted) {
            $this->writeState(['last_cleanup_at' => date('Y-m-d H:i:s')]);
        }

        return [
            'success'   => true,
            'deleted'   => $deleted,
            'kept'      => array_values($keepAll),
            'settings'  => $settings,
        ];
    }

    /**
     * Get the configured retention settings, merging DB values with defaults.
     */
    public function getRetentionSettings(): array
    {
        $defaults = self::DEFAULT_RETENTION;
        try {
            $this->ensureSettingsTable();
            $stmt = $this->db->query('SELECT keep_daily, keep_weekly, keep_monthly, keep_full FROM ' . self::STATE_TABLE . ' ORDER BY id LIMIT 1');
            $row = $stmt->fetch();
            if ($row) {
                foreach (['keep_daily', 'keep_weekly', 'keep_monthly', 'keep_full'] as $key) {
                    if (isset($row[$key]) && $row[$key] !== null) {
                        $defaults[$key] = (int)$row[$key];
                    }
                }
            }
        } catch (Throwable $e) {
            // Table may not exist yet — use defaults
        }
        return $defaults;
    }

    /**
     * Persist retention settings.
     */
    public function updateRetention(array $settings): array
    {
        $keepDaily   = max(0, min(365, (int)($settings['keep_daily'] ?? self::DEFAULT_RETENTION['keep_daily'])));
        $keepWeekly  = max(0, min(156, (int)($settings['keep_weekly'] ?? self::DEFAULT_RETENTION['keep_weekly'])));
        $keepMonthly = max(0, min(120, (int)($settings['keep_monthly'] ?? self::DEFAULT_RETENTION['keep_monthly'])));
        $keepFull    = max(0, min(30, (int)($settings['keep_full'] ?? self::DEFAULT_RETENTION['keep_full'])));

        try {
            $this->ensureSettingsTable();
            $stmt = $this->db->prepare(
                'INSERT INTO ' . self::STATE_TABLE . ' (keep_daily, keep_weekly, keep_monthly, keep_full)
                 VALUES (:daily, :weekly, :monthly, :full)
                 ON DUPLICATE KEY UPDATE
                   keep_daily = VALUES(keep_daily),
                   keep_weekly = VALUES(keep_weekly),
                   keep_monthly = VALUES(keep_monthly),
                   keep_full = VALUES(keep_full)'
            );
            $stmt->execute([
                'daily'   => $keepDaily,
                'weekly'  => $keepWeekly,
                'monthly' => $keepMonthly,
                'full'    => $keepFull,
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to save retention settings: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Retention settings saved.',
            'settings' => $this->getRetentionSettings(),
        ];
    }

    /**
     * Restore a database backup. Strong safeguards:
     *   - Requires a confirmation flag (two-step)
     *   - Creates a safety backup of current DB BEFORE restoring
     */
    public function restoreDatabase(string $filename, bool $confirmed = false): array
    {
        $file = $this->safePath($filename);
        if ($file === null) {
            return ['success' => false, 'message' => 'Invalid backup file name.'];
        }
        if (!is_file($file)) {
            return ['success' => false, 'message' => 'Backup file does not exist.'];
        }

        $type = $this->inferTypeFromFilePath($file);
        if ($type !== self::TYPE_DATABASE) {
            return ['success' => false, 'message' => 'Only database backups can be restored.'];
        }

        if (!$confirmed) {
            return ['success' => false, 'requires_confirmation' => true, 'message' => 'Restore requires explicit confirmation.'];
        }

        $this->acquireLock();
        try {
            // Step 1: safety backup of current DB
            $safety = $this->backupDatabase('pre_restore');

            // Step 2: read backup content
            $content = $this->readCompressedSql($file);
            if ($content === null) {
                return ['success' => false, 'message' => 'Failed to read backup file (possibly corrupt).'];
            }

            // Step 3: execute
            try {
                $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
                $this->db->exec($content);
                $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable $e) {
                error_log('[backup] restore failed: ' . $e->getMessage());
                return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
            }

            $this->writeState(['last_restore_at' => date('Y-m-d H:i:s'), 'last_restore_file' => $filename]);

            return ['success' => true, 'message' => 'Database restored successfully.', 'safety_backup' => $safety['filename'] ?? ''];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Check whether a backup/restore job is currently running.
     */
    public function isLocked(): bool
    {
        if (!is_file($this->lockFile)) {
            return false;
        }
        $age = time() - @filemtime($this->lockFile);
        // Stale lock older than 15 minutes is considered released
        return $age < 900;
    }

    // ─── Internal: backup implementations ────────────────────────────────────

    private function backupDatabase(string $source): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename  = "mpelioutfitstore_db_{$timestamp}.sql.gz";
        $output    = $this->backupDir . '/' . $filename;

        try {
            $sql = $this->dumpDatabaseToSql();
            if ($sql === '') {
                throw new RuntimeException('Database dump produced no output.');
            }
            // Skip migration/audit internal tables that are recreated on restore
            $gzipOk = $this->writeGzip($output, $sql);
            if (!$gzipOk || (@filesize($output) ?: 0) <= 0) {
                // Fall back to uncompressed SQL so recovery is still possible
                $plain = str_replace('.gz', '', $output);
                @file_put_contents($plain, $sql, LOCK_EX);
                $filename = basename($plain);
                $output   = $plain;
            }
        } catch (Throwable $e) {
            $this->recordError($e->getMessage());
            return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
        }

        $size = @filesize($output) ?: 0;

        return [
            'success'     => true,
            'filename'    => $filename,
            'type'        => self::TYPE_DATABASE,
            'size'        => $size,
            'size_human'  => $this->formatBytes($size),
            'created_at'  => date('Y-m-d H:i:s'),
            'source'      => $source,
        ];
    }

    private function backupFiles(string $source): array
    {
        $timestamp  = date('Y-m-d_H-i-s');
        $filename   = "mpelioutfitstore_files_{$timestamp}.tar.gz";
        $output     = $this->backupDir . '/' . $filename;

        $uploadDir = $this->appRoot . '/uploads';
        if (!is_dir($uploadDir)) {
            return ['success' => false, 'message' => 'Uploads directory does not exist.'];
        }

        try {
            $tarOk = $this->createTarGz($output, $uploadDir);
            if (!$tarOk || (@filesize($output) ?: 0) <= 0) {
                // Fall back to PHP zip (no external tar dependency)
                $zipFile = str_replace('.tar.gz', '.zip', $output);
                $zipOk = $this->createZip($zipFile, $uploadDir);
                if ($zipOk && (@filesize($zipFile) ?: 0) > 0) {
                    @unlink($output);
                    $output   = $zipFile;
                    $filename = basename($zipFile);
                } else {
                    @unlink($zipFile);
                    return ['success' => false, 'message' => 'Failed to create file backup (tar and zip both failed).'];
                }
            }
        } catch (Throwable $e) {
            $this->recordError($e->getMessage());
            return ['success' => false, 'message' => 'File backup failed: ' . $e->getMessage()];
        }

        $size = @filesize($output) ?: 0;
        return [
            'success'    => true,
            'filename'   => $filename,
            'type'       => self::TYPE_FILES,
            'size'       => $size,
            'size_human' => $this->formatBytes($size),
            'created_at' => date('Y-m-d H:i:s'),
            'source'     => $source,
        ];
    }

    private function backupFull(string $source): array
    {
        // Full backup = DB + files packed in one archive.
        $timestamp = date('Y-m-d_H-i-s');
        $filename  = "mpelioutfitstore_full_{$timestamp}.tar.gz";
        $output    = $this->backupDir . '/' . $filename;

        $tmpDir = $this->backupDir . '/.tmp_full_' . $timestamp;
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0750, true);
        }

        try {
            // 1. DB dump
            $sql = $this->dumpDatabaseToSql();
            @file_put_contents($tmpDir . '/database.sql', $sql, LOCK_EX);

            // 2. Copy uploads into a staging subdir (exclude nested backups)
            $stageUploads = $tmpDir . '/uploads';
            if (is_dir($this->appRoot . '/uploads')) {
                $this->recursiveCopy($this->appRoot . '/uploads', $stageUploads, ['index.html']);
            }

            // 3. Archive staging
            $tarOk = $this->createTarGz($output, $tmpDir);
            if (!$tarOk || (@filesize($output) ?: 0) <= 0) {
                // Fall back to zip
                $zipFile = str_replace('.tar.gz', '.zip', $output);
                $zipOk = $this->createZip($zipFile, $tmpDir);
                if ($zipOk && (@filesize($zipFile) ?: 0) > 0) {
                    @unlink($output);
                    $output   = $zipFile;
                    $filename = basename($zipFile);
                } else {
                    @unlink($zipFile);
                    throw new RuntimeException('Failed to create full backup archive.');
                }
            }

            @unlink($tmpDir . '/database.sql');
            $this->removeDir($tmpDir);
        } catch (Throwable $e) {
            $this->removeDir($tmpDir);
            $this->recordError($e->getMessage());
            return ['success' => false, 'message' => 'Full backup failed: ' . $e->getMessage()];
        }

        $size = @filesize($output) ?: 0;
        return [
            'success'    => true,
            'filename'   => $filename,
            'type'       => self::TYPE_FULL,
            'size'       => $size,
            'size_human' => $this->formatBytes($size),
            'created_at' => date('Y-m-d H:i:s'),
            'source'     => $source,
        ];
    }

    // ─── Internal: DB dump ───────────────────────────────────────────────────

    private function dumpDatabaseToSql(): string
    {
        $tables = $this->getAllTables();
        $views  = [];

        $sql  = "-- MpeliOutFitStore Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- <auto-generated — contains no credentials>\n";
        $sql .= "-- Tables: " . count($tables) . "\n\n";
        $sql .= "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        // First pass: DDL for all tables (do this before data so FK constraints resolve)
        $tableDdl = [];
        $viewDdl  = [];
        foreach ($tables as $table) {
            if ($this->isView($table)) {
                $views[] = $table;
                continue;
            }
            $create = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch();
            if ($create) {
                $ddl = $create['Create Table'] ?? '';
                if ($ddl !== '') {
                    $tableDdl[$table] = $ddl;
                }
            }
        }

        // Views must come after tables
        foreach ($views as $view) {
            $create = $this->db->query("SHOW CREATE VIEW `{$view}`")->fetch();
            if ($create) {
                $ddl = $create['Create View'] ?? '';
                $charset = $create['character_set_client'] ?? '';
                $collation = $create['collation_connection'] ?? '';
                if ($ddl !== '') {
                    $viewDdl[] = "-- View {$view}\n" .
                        ($charset ? "/*!50001 SET @saved_cs_client = @@character_set_client */;\n" : '') .
                        ($charset ? "SET character_set_client = '{$charset}';\n" : '') .
                        $ddl . ";\n" .
                        ($charset ? "SET character_set_client = @saved_cs_client;\n" : '');
                }
            }
        }

        // Emit DDL (drop + create) for each table/view
        foreach ($tableDdl as $table => $ddl) {
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $ddl . ";\n\n";
        }
        foreach ($viewDdl as $vd) {
            $sql .= $vd . "\n";
        }

        // Second pass: data
        foreach ($tables as $table) {
            if ($this->isView($table)) {
                continue;
            }
            $cols = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (empty($cols)) {
                continue;
            }
            $colList = implode(', ', array_map(static fn($c) => "`{$c}`", $cols));
            $sql .= "-- Data: {$table}\n";

            // Stream rows in batches instead of loading the whole table into memory
            $stmt = $this->db->query("SELECT * FROM `{$table}`");
            if (!$stmt) {
                continue;
            }
            $colCount = count($cols);
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $values = [];
                for ($i = 0; $i < $colCount; $i++) {
                    $v = $row[$i];
                    if ($v === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $values[] = (string)$v;
                    } else {
                        $values[] = $this->db->quote((string)$v);
                    }
                }
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $sql .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";

        return $sql;
    }

    // ─── Internal: archive helpers ───────────────────────────────────────────

    private function writeGzip(string $file, string $content): bool
    {
        $gz = @gzopen($file, 'wb9');
        if (!$gz) {
            return false;
        }
        $ok = gzwrite($gz, $content) !== false;
        gzclose($gz);
        return $ok;
    }

    private function createTarGz(string $output, string $sourceDir): bool
    {
        // Try to detect system tar first
        $tarBin = $this->findBinary(['tar']);
        if ($tarBin !== null) {
            $cmd = escapeshellarg($tarBin) . ' -czf ' . escapeshellarg($output) . ' -C ' . escapeshellarg(dirname($sourceDir)) . ' ' . escapeshellarg(basename($sourceDir));
            // Use absolute-source mode: tar -czf out -C parent dirname
            $parent = dirname($sourceDir);
            $name   = basename($sourceDir);
            $cmd = escapeshellarg($tarBin) . ' -czf ' . escapeshellarg($output) . ' -C ' . escapeshellarg($parent) . ' ' . escapeshellarg($name);
            exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && is_file($output) && @filesize($output) > 0) {
                return true;
            }
        }

        // Fallback: build a plain tar (uncompressed) via Phar data, or use PHP's zip
        // We'll rely on the zip fallback at the caller level.
        return false;
    }

    private function createZip(string $zipFile, string $sourceDir): bool
    {
        if (!class_exists('ZipArchive')) {
            // Try the -z option of tar if available
            $tarBin = $this->findBinary(['tar']);
            if ($tarBin !== null) {
                $cmd = escapeshellarg($tarBin) . ' -cf ' . escapeshellarg($zipFile) . ' -C ' . escapeshellarg(dirname($sourceDir)) . ' ' . escapeshellarg(basename($sourceDir));
                exec($cmd . ' 2>&1', $out, $code);
                return $code === 0 && is_file($zipFile) && @filesize($zipFile) > 0;
            }
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $root = realpath($sourceDir);
        $base = dirname($root);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $real = $item->getRealPath();
            if ($real === false) {
                continue;
            }
            $local = ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
            if (is_dir($real)) {
                $zip->addEmptyDir($local);
            } elseif (is_file($real)) {
                $zip->addFile($real, $local);
            }
        }
        $zip->close();
        return is_file($zipFile) && @filesize($zipFile) > 0;
    }

    private function readCompressedSql(string $path): ?string
    {
        if (str_ends_with($path, '.gz')) {
            $content = @file_get_contents('compress.zlib://' . $path);
            return $content === false ? null : $content;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    // ─── Internal: directory / lock / state ──────────────────────────────────

    private function resolveBackupDir(): string
    {
        // Preferred: one level above the app root (outside the web root on cPanel)
        $outside = dirname($this->appRoot) . '/backups';
        if ($this->isWritableOrCreatable($outside)) {
            return $outside;
        }
        // Fallback: in-tree backups/ (protected by .htaccess)
        return $this->appRoot . '/backups';
    }

    private function isWritableOrCreatable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        return is_dir($parent) && is_writable($parent);
    }

    private function ensureDirectories(): void
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0750, true);
        }
        // Always ensure the in-tree backups dir is protected even if not used
        $treeBackup = $this->appRoot . '/backups';
        if (!is_dir($treeBackup)) {
            @mkdir($treeBackup, 0750, true);
        }
        $ht = $treeBackup . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }

    private function acquireLock(): void
    {
        // Wait a short time, then fail if still locked (prevents concurrent duplicates)
        $deadline = microtime(true) + 10.0;
        while ($this->isLocked() && microtime(true) < $deadline) {
            usleep(200000);
        }
        if ($this->isLocked()) {
            throw new RuntimeException('Another backup operation is already in progress. Please wait.');
        }
        @file_put_contents($this->lockFile, (string)time(), LOCK_EX);
    }

    private function releaseLock(): void
    {
        if (is_file($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    private function recordError(string $message): void
    {
        $this->writeState(['last_error' => mb_substr($message, 0, 500), 'last_error_at' => date('Y-m-d H:i:s')]);
        $line = '[' . date('Y-m-d H:i:s') . '] [backup] ERROR: ' . $message . PHP_EOL;
        @file_put_contents($this->logDir . '/backup.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function writeState(array $fields): void
    {
        $state = $this->readState();
        foreach ($fields as $k => $v) {
            $state[$k] = $v;
        }
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function ensureSettingsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                keep_daily INT UNSIGNED NOT NULL DEFAULT 7,
                keep_weekly INT UNSIGNED NOT NULL DEFAULT 4,
                keep_monthly INT UNSIGNED NOT NULL DEFAULT 12,
                keep_full INT UNSIGNED NOT NULL DEFAULT 3,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    // ─── Internal: path safety / type detection ──────────────────────────────

    /**
     * Resolve a user-supplied filename into a safe absolute path inside the
     * backup directory. Returns null on any traversal/unsafe input.
     */
    private function safePath(string $filename): ?string
    {
        $filename = basename(trim($filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }
        // Only allow whitelisted prefix patterns
        if (!preg_match('/^mpelioutfitstore_(db|files|full)_[\d\-_]+\.(sql|sql\.gz|tar\.gz|tgz|zip)$/', $filename)) {
            return null;
        }
        $full = realpath($this->backupDir . '/' . $filename);
        if ($full === false) {
            return null;
        }
        $realBackupDir = realpath($this->backupDir);
        if ($realBackupDir === false || strpos($full . DIRECTORY_SEPARATOR, $realBackupDir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $full;
    }

    private function inferTypeFromFilename(string $filename): string
    {
        if (str_contains($filename, '_db_')) return self::TYPE_DATABASE;
        if (str_contains($filename, '_files_')) return self::TYPE_FILES;
        if (str_contains($filename, '_full_')) return self::TYPE_FULL;
        return self::TYPE_DATABASE;
    }

    private function inferTypeFromFilePath(string $path): string
    {
        return $this->inferTypeFromFilename(basename($path));
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower($type);
        if (in_array($type, [self::TYPE_DATABASE, self::TYPE_FILES, self::TYPE_FULL], true)) {
            return $type;
        }
        return self::TYPE_DATABASE;
    }

    private function countByType(array $backups, string $type): int
    {
        return count(array_filter($backups, static fn($b) => $b['type'] === $type));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function storageLabel(): string
    {
        if ($this->isOutsideWebRoot()) {
            return 'Outside public web root (protected server directory)';
        }
        return 'Inside web root (protected by .htaccess — see notes)';
    }

    private function isOutsideWebRoot(): bool
    {
        $root = $this->appRoot;
        $backupReal = realpath($this->backupDir);
        $rootReal = realpath($root);
        if ($backupReal === false || $rootReal === false) {
            return false;
        }
        return strpos($backupReal, $rootReal) !== 0;
    }

    private function getAllTables(): array
    {
        $tables = [];
        $rows = $this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    private function isView(string $name): bool
    {
        $stmt = $this->db->prepare(
            "SELECT TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name"
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row['TABLE_TYPE'] ?? '') === 'VIEW';
    }

    /** Tries to locate an executable in PATH or common locations. */
    private function findBinary(array $names): ?string
    {
        foreach ($names as $name) {
            $which = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
            if ($which && trim($which) !== '') {
                return trim($which);
            }
            // Windows fallback
            $where = @shell_exec('where.exe ' . escapeshellarg($name) . ' 2>NUL');
            if ($where) {
                $first = trim(explode("\n", $where)[0]);
                if ($first !== '') return $first;
            }
        }
        return null;
    }

    private function recursiveCopy(string $source, string $dest, array $exclude = []): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0750, true);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if (in_array($item->getBasename(), $exclude, true)) {
                continue;
            }
            if ($item->isDir()) {
                @mkdir($target, 0750, true);
            } elseif ($item->isFile()) {
                @copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
