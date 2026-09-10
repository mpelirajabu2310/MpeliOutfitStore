<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../services/BackupService.php';
require_once __DIR__ . '/../services/SystemResetService.php';
require_once __DIR__ . '/../services/PermissionService.php';

// System reset is restricted to the OWNER via both role and fine-grained RBAC.
$owner = require_role($pdo, ['OWNER']);
PermissionService::requirePermission($owner['role'], 'system.reset');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_csrf();

$data = read_json_body();
$confirmation = trim((string)($data['confirmation'] ?? ''));

// Explicit typed-phrase confirmation gate.
if (!hash_equals('RESET SYSTEM', strtoupper($confirmation))) {
    audit_log((int)$owner['id'], 'system_reset_attempt', 'System reset blocked: confirmation phrase did not match', 'warning', [
        'module' => 'system',
        'description' => 'System reset attempt blocked (invalid confirmation phrase)',
        'entity_type' => 'system',
    ]);
    respond(['success' => false, 'message' => 'Invalid confirmation phrase. Reset aborted.'], 422);
}

// Re-check the owner in the database (defense in depth) and confirm they are
// using the live authenticated session.
$freshOwner = current_user($pdo);
if ($freshOwner === null || $freshOwner['role'] !== 'OWNER') {
    respond(['success' => false, 'message' => 'Your session is no longer valid.'], 403);
}

// ─── Concurrency + duplicate/refresh guard (file-backed) ─────────────────────
// Prevents two concurrent resets from racing and stops a refresh/retry from
// running the destructive reset a second time immediately after completion.
$lockDir = __DIR__ . '/../logs';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0750, true);
}
$lockFile    = $lockDir . '/system_reset.lock';
$stateFile   = $lockDir . '/system_reset_state.json';

// Completion cooldown: refuse a second reset within the last 90 seconds.
if (is_file($stateFile)) {
    $state = json_decode((string)@file_get_contents($stateFile), true) ?: [];
    if (!empty($state['last_completed_at'])) {
        if ((time() - (int)$state['last_completed_at']) < 90) {
            respond(['success' => false, 'message' => 'A system reset was just completed. If you need to reset again, please wait a moment.'], 429);
        }
    }
}

// Concurrent operation guard: only one reset may run at a time.
if (is_file($lockFile)) {
    $age = time() - @filemtime($lockFile);
    if ($age < 300) {
        respond(['success' => false, 'message' => 'Another operation is already in progress. Please wait.'], 429);
    }
    @unlink($lockFile);
}
@file_put_contents($lockFile, (string)time(), LOCK_EX);

try {
    $status   = 200;
    $response = [
        'success' => true,
        'message' => 'System data has been reset successfully. User accounts were preserved.',
    ];

    // ─── Step 1: backup BEFORE any destructive action ────────────────────────
    $backupService = new BackupService();
    $backup = $backupService->createBackup(BackupService::TYPE_FULL, 'system_reset');
    if (!$backup['success']) {
        $status = 500;
        $response = [
            'success' => false,
            'message' => 'Safety backup failed. Reset aborted: ' . ($backup['message'] ?? 'unknown error'),
        ];
    } else {
        // ─── Step 2: perform the reset ───────────────────────────────────────
        $resetService = new SystemResetService($pdo);
        $result = $resetService->reset();

        // ─── Step 3: audit with per-table removed counts + server-side IP ────
        @file_put_contents($stateFile, json_encode(['last_completed_at' => time()]), LOCK_EX);

        audit_log((int)$owner['id'], 'system_reset_completed', 'Business data reset completed', 'success', [
            'module' => 'system',
            'description' => 'System data reset completed',
            'entity_type' => 'system',
            'new_values' => [
                'backup'  => $backup['filename'] ?? '',
                'removed' => $result['removed'],
                'images_deleted' => $result['images_deleted'],
            ],
        ]);

        $response = [
            'success' => true,
            'removed' => $result['removed'],
            'images_deleted' => $result['images_deleted'],
            'backup'  => ['filename' => $backup['filename'] ?? ''],
            'message' => 'System data has been reset successfully. User accounts were preserved.',
        ];
    }
} catch (Throwable $e) {
    // Never leak a partially-executed reset as success; always report JSON.
    error_log('[system_reset] reset aborted: ' . $e->getMessage());
    $status = 500;
    $response = ['success' => false, 'message' => 'System reset could not be completed.'];
} finally {
    // NOTE: respond() below is called AFTER this block on purpose. Calling exit
    // (which respond() does) inside the try block would skip this cleanup —
    // PHP does not execute a finally block when the try ends via exit, which
    // left a stale system_reset.lock behind after every completed reset.
    @unlink($lockFile);
}

respond($response, $status);
