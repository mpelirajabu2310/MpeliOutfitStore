<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';

require_once __DIR__ . '/../services/DashboardService.php';
require_once __DIR__ . '/../services/PermissionService.php';

PermissionService::requirePermission($user['role'], 'dashboard.view');

$dashboardService = new DashboardService();

$sellerId = $isOwner ? null : $user['id'];

$summary = $dashboardService->getDashboardSummary($sellerId, $isOwner);
$summary['success'] = true;
$summary['permissions'] = PermissionService::getPermissions($user['role']);
respond($summary);
