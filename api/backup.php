<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/PermissionService.php';

$user = require_role($pdo, ['OWNER']);
PermissionService::requirePermission($user['role'], 'backup.manage');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $migrationService = new MigrationService();
    $backups = $migrationService->listBackups();
    $history = $migrationService->getMigrationHistory();
    $analysis = $migrationService->analyzeDatabase();

    respond([
        'success' => true,
        'backups' => $backups,
        'migration_history' => $history,
        'analysis' => $analysis,
    ]);
}

if ($method === 'POST') {
    require_csrf();
    $data = read_json_body();
    $action = $data['action'] ?? '';

    $migrationService = new MigrationService();

    if ($action === 'backup') {
        $reason = trim((string)($data['reason'] ?? 'manual'));
        $result = $migrationService->createBackup($reason);
        if (isset($result['file'])) {
            log_activity((int)$user['id'], 'backup_created', "File: {$result['file']}, Size: {$result['size']}");
        }
        respond(['success' => true, 'backup' => $result]);
    }

    if ($action === 'analyze') {
        $analysis = $migrationService->analyzeDatabase();
        respond(['success' => true, 'analysis' => $analysis]);
    }

    if ($action === 'assign_legacy') {
        $table = trim((string)($data['table'] ?? ''));
        $field = trim((string)($data['field'] ?? ''));
        $ownerId = (int)($data['owner_id'] ?? 0);

        if ($table === '' || $field === '' || $ownerId <= 0) {
            respond(['success' => false, 'message' => 'Table, field, and owner_id are required.'], 422);
        }

        $result = $migrationService->assignLegacyRecords($table, $field, $ownerId);
        log_activity((int)$user['id'], 'legacy_records_assigned', "Table: {$table}, Field: {$field}, Owner: {$ownerId}, Affected: {$result['records_affected']}");
        respond(['success' => true, 'result' => $result]);
    }

    respond(['success' => false, 'message' => 'Invalid action. Use: backup, analyze, assign_legacy.'], 422);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
