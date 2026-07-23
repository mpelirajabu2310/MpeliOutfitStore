<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';

require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../services/PermissionService.php';

if ($isOwner) {
    PermissionService::requirePermission($user['role'], 'reports.view');
} else {
    PermissionService::requirePermission($user['role'], 'reports.view_own');
}

$reportService = new ReportService();

$sellerId = $isOwner ? null : $user['id'];

$stats = $reportService->getReportStats($sellerId, $isOwner);
$stats['success'] = true;
$stats['permissions'] = PermissionService::getPermissions($user['role']);
respond($stats);
