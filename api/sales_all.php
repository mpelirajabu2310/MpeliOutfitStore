<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_role($pdo, ['OWNER', 'SELLER']);

require_once __DIR__ . '/../services/PermissionService.php';
PermissionService::requirePermission($user['role'], 'dashboard.view');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
$userId  = $user['role'] === 'OWNER' ? null : (int)$user['id'];

require_once __DIR__ . '/../services/SalesService.php';

$svc = new SalesService();
$result = $svc->getSalesPaginated($page, $perPage, $userId);

$isOwner = $user['role'] === 'OWNER';
if (!$isOwner) {
    foreach ($result['sales'] as &$sale) {
        $sale['total_profit'] = null;
    }
}

respond([
    'success'    => true,
    'sales'      => $result['sales'],
    'total'      => $result['total'],
    'page'       => $result['page'],
    'per_page'   => $result['per_page'],
    'total_pages'=> $result['total_pages'],
]);
