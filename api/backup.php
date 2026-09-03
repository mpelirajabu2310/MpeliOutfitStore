<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/BackupService.php';
require_once __DIR__ . '/../services/PermissionService.php';

$owner = require_role($pdo, ['OWNER']);
PermissionService::requirePermission($owner['role'], 'backup.manage');

$backupService = new BackupService();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $backups = $backupService->listBackups();
    $status  = $backupService->getStatus();

    respond([
        'success'  => true,
        'backups'  => $backups,
        'status'   => $status,
        'retention'=> $backupService->getRetentionSettings(),
    ]);
}

if ($method === 'POST') {
    require_csrf();
    $data   = read_json_body();
    $action = trim((string)($data['action'] ?? ''));

    switch ($action) {
        case 'create':
            $type   = trim((string)($data['type'] ?? 'database'));
            $source = trim((string)($data['source'] ?? 'manual'));
            $result = $backupService->createBackup($type, $source);

            audit_log((int)$owner['id'], 'backup_created', "Type: {$type}, File: " . ($result['filename'] ?? 'n/a'), $result['success'] ? 'success' : 'error', [
                'module'     => 'system',
                'description' => ($result['success'] ? 'Backup created' : 'Backup failed') . " ({$type})",
                'entity_type' => 'backup',
                'new_values'  => [
                    'type'     => $type,
                    'filename' => $result['filename'] ?? '',
                    'size'     => $result['size'] ?? 0,
                    'success'  => $result['success'] ?? false,
                ],
            ]);

            if (!$result['success']) {
                respond(['success' => false, 'message' => $result['message'] ?? 'Backup failed.'], 500);
            }
            respond(['success' => true, 'backup' => $result]);

        case 'download':
            $filename = trim((string)($data['filename'] ?? ''));
            $path = $backupService->getDownloadPath($filename);
            if ($path === null) {
                audit_log((int)$owner['id'], 'backup_download', "Filename: {$filename}, Status: denied (invalid path)", 'error', [
                    'module' => 'system',
                    'description' => 'Backup download attempt blocked (invalid file path)',
                    'entity_type' => 'backup',
                ]);
                respond(['success' => false, 'message' => 'Invalid backup file.'], 400);
            }

            audit_log((int)$owner['id'], 'backup_download', "Filename: {$filename}", 'success', [
                'module' => 'system',
                'description' => 'Backup downloaded',
                'entity_type' => 'backup',
                'new_values' => ['filename' => $filename],
            ]);

            // Stream the file to the client with safe headers.
            $size = @filesize($path) ?: 0;
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($path);
            exit;

        case 'delete':
            $filename = trim((string)($data['filename'] ?? ''));
            $result = $backupService->deleteBackup($filename);
            audit_log((int)$owner['id'], 'backup_deleted', "Filename: {$filename}, Success: " . ($result['success'] ? 'yes' : 'no'), $result['success'] ? 'success' : 'error', [
                'module' => 'system',
                'description' => 'Backup deleted',
                'entity_type' => 'backup',
                'new_values' => ['filename' => $filename, 'success' => $result['success']],
            ]);
            respond($result);

        case 'validate':
            $filename = trim((string)($data['filename'] ?? ''));
            $result = $backupService->validateBackup($filename);
            respond(['success' => true, 'validation' => $result]);

        case 'retention':
            $result = $backupService->updateRetention($data['settings'] ?? []);
            if ($result['success']) {
                audit_log((int)$owner['id'], 'retention_updated', 'Backup retention settings updated', 'success', [
                    'module' => 'system',
                    'description' => 'Backup retention policy updated',
                    'entity_type' => 'backup',
                    'new_values' => $result['settings'],
                ]);
            }
            respond($result);

        case 'cleanup':
            $result = $backupService->applyRetention('cleanup');
            audit_log((int)$owner['id'], 'retention_cleanup', 'Retention cleanup performed. Deleted: ' . count($result['deleted'] ?? []), count($result['deleted'] ?? []) ? 'success' : 'warning', [
                'module' => 'system',
                'description' => 'Retention cleanup performed',
                'entity_type' => 'backup',
                'new_values' => $result,
            ]);
            respond(['success' => true, 'result' => $result]);

        case 'restore':
            // Two-step restore requiring explicit confirmation + safety backup.
            $filename  = trim((string)($data['filename'] ?? ''));
            $confirmed = (bool)($data['confirmed'] ?? false);

            $result = $backupService->restoreDatabase($filename, $confirmed);

            if (isset($result['requires_confirmation']) && $result['requires_confirmation']) {
                audit_log((int)$owner['id'], 'restore_initiated', "Filename: {$filename}, Status: awaiting-confirmation", 'warning', [
                    'module' => 'system',
                    'description' => 'Restore initiated (needs confirmation)',
                    'entity_type' => 'backup',
                    'new_values' => ['filename' => $filename],
                ]);
                respond(['success' => false, 'requires_confirmation' => true, 'message' => $result['message']], 422);
            }

            audit_log((int)$owner['id'], $result['success'] ? 'restore_completed' : 'restore_failed', "Filename: {$filename}", $result['success'] ? 'success' : 'error', [
                'module' => 'system',
                'description' => $result['success'] ? 'Database restored from backup' : 'Database restore failed',
                'entity_type' => 'backup',
                'new_values' => ['filename' => $filename, 'success' => $result['success']],
            ]);

            if ($result['success']) {
                // Re-seed the default shop settings if the table is now empty
                try {
                    ensure_shop_settings($pdo);
                } catch (Throwable $e) {
                }
            }

            respond($result);

        default:
            respond(['success' => false, 'message' => 'Invalid action. Use: create, download, delete, validate, retention, cleanup, restore.'], 422);
    }
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
