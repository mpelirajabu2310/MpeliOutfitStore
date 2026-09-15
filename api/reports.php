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

// Optional inclusive date range for the detail tables below. The summary
// figures stay fixed (today/week/month/year), matching the card labels.
$startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';
if ($startDate !== '' || $endDate !== '') {
    $isDate = static fn(string $v): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    if ($startDate === '' || $endDate === '' || !$isDate($startDate) || !$isDate($endDate)) {
        respond(['success' => false, 'message' => 'Invalid date range.'], 400);
    }
    if ($startDate > $endDate) {
        respond(['success' => false, 'message' => 'Start date must be on or before end date.'], 400);
    }
}

$stats = $reportService->getReportStats($sellerId, $isOwner);
$stats['success'] = true;
$stats['analytics'] = $reportService->getDashboardAnalytics($sellerId, $isOwner);
$stats['details'] = $reportService->getReportDetails(
    $sellerId,
    $isOwner,
    $startDate !== '' ? $startDate : null,
    $endDate !== '' ? $endDate : null
);
$stats['permissions'] = PermissionService::getPermissions($user['role']);
respond($stats);
