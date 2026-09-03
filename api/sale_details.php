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

$paymentMethod = null;
$pStmt = $pdo->prepare(
    'SELECT payment_method FROM payments WHERE sale_id = :sale_id ORDER BY id ASC LIMIT 1'
);
$pStmt->execute(['sale_id' => $saleId]);
$pRow = $pStmt->fetch();
if ($pRow) {
    $paymentMethod = $pRow['payment_method'];
}
$sale['payment_method'] = $paymentMethod;

$customerName = null;
$customerPhone = null;
if (!empty($sale['customer_id'])) {
    $cStmt = $pdo->prepare(
        'SELECT full_name, phone FROM customers WHERE id = :cid LIMIT 1'
    );
    $cStmt->execute(['cid' => (int)$sale['customer_id']]);
    $cRow = $cStmt->fetch();
    if ($cRow) {
        $customerName = $cRow['full_name'] ?: null;
        $customerPhone = $cRow['phone'] ?: null;
    }
}
$sale['customer_name'] = $customerName;
$sale['customer_phone'] = $customerPhone;

unset($sale['customer_id'], $sale['sold_by']);

$settings = ensure_shop_settings($pdo);
$sale['shop'] = [
    'shop_name'     => $settings['shop_name'] ?? 'Mpeli Outfit Store',
    'logo_url'      => $settings['logo_url'] ?? '',
    'address'       => $settings['address'] ?? '',
    'phone'         => $settings['phone'] ?? '',
    'email'         => $settings['email'] ?? '',
    'currency_code' => $settings['currency_code'] ?? 'TSH',
    'receipt_footer' => $settings['receipt_footer'] ?? '',
];

respond([
    'success' => true,
    'sale' => $sale,
]);
