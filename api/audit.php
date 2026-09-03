<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_role($pdo, ['OWNER']);

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/AuditService.php';

PermissionService::requirePermission($user['role'], 'audit.view');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$auditService = new AuditService();

if (!$auditService->tableExists()) {
    respond(['success' => false, 'message' => 'Audit log table does not exist. Please run the database migration.'], 500);
}

// ─── Single audit log detail (admin clicks "View") ─────────────────────────
// Securely fetch ONLY the requested record via a prepared statement. The
// OWNER role + audit.view permission checks above apply to this branch as
// well, so authorization is enforced on the server. A missing record returns
// a generic 404 so we never leak whether an id exists.
if (isset($_GET['detail'])) {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        respond(['success' => false, 'message' => 'Invalid audit log reference.'], 400);
    }
    $log = $auditService->getLogById($id);
    if (!$log) {
        respond(['success' => false, 'message' => 'Audit log entry not found.'], 404);
    }
    respond(['success' => true, 'log' => $log]);
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));

$filters = [];
if (!empty($_GET['id']))       $filters['id']       = (int)$_GET['id'];
if (!empty($_GET['search']))    $filters['search']      = trim((string)$_GET['search']);
if (!empty($_GET['user_id']))   $filters['user_id']     = (int)$_GET['user_id'];
if (!empty($_GET['role']))      $filters['role']        = trim((string)$_GET['role']);
if (!empty($_GET['module']))    $filters['module']       = trim((string)$_GET['module']);
if (!empty($_GET['action']))    $filters['action']       = trim((string)$_GET['action']);
if (!empty($_GET['entity_type'])) $filters['entity_type'] = trim((string)$_GET['entity_type']);
if (!empty($_GET['date_from'])) $filters['date_from']   = trim((string)$_GET['date_from']);
if (!empty($_GET['date_to']))   $filters['date_to']     = trim((string)$_GET['date_to']);

try {
    $result = $auditService->getLogs($filters, $page, $perPage);
    $users = $auditService->getDistinctUsers();
    $modules = $auditService->getDistinctModules();
    $actions = $auditService->getDistinctActions();
    $entityTypes = $auditService->getDistinctEntityTypes();

    respond([
        'success'      => true,
        'logs'         => $result['logs'],
        'total'        => $result['total'],
        'page'         => $result['page'],
        'per_page'     => $result['per_page'],
        'total_pages'  => $result['total_pages'],
        'filter_options' => [
            'users'       => $users,
            'modules'     => $modules,
            'actions'     => $actions,
            'entity_types' => $entityTypes,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[audit.php] GET error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to load audit logs.'], 500);
}
