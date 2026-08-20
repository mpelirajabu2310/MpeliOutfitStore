<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_role($pdo, ['OWNER', 'SELLER']);

$saleIdRaw = $_GET['id'] ?? '';
$saleId = filter_var($saleIdRaw, FILTER_VALIDATE_INT);
if ($saleId === false || $saleId <= 0) {
    respond(['success' => false, 'message' => 'Invalid sale ID.'], 400);
}

require_once __DIR__ . '/../services/PermissionService.php';
PermissionService::requirePermission($user['role'], 'dashboard.view');

require_once __DIR__ . '/../services/SalesService.php';

$salesService = new SalesService();

$userId = $user['role'] === 'OWNER' ? null : (int)$user['id'];
$sale = $salesService->getSaleDetails($saleId, $userId);

if (!$sale) {
    respond(['success' => false, 'message' => 'Sale not found or you do not have permission to view it.'], 404);
}

respond([
    'success' => true,
    'sale' => $sale,
]);
