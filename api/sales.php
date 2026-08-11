<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_role($pdo, ['OWNER', 'SELLER']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/SalesService.php';
PermissionService::requirePermission($user['role'], 'sales.create');

require_csrf();

$data = read_json_body();
$items = $data['items'] ?? [];
if (!is_array($items)) {
    respond(['success' => false, 'message' => 'Items must be an array.'], 422);
}
$paymentMethod = (string)($data['payment_method'] ?? 'cash');
if (!in_array($paymentMethod, ['cash', 'mobile_money', 'card'], true)) {
    respond(['success' => false, 'message' => 'Invalid payment method.'], 422);
}
$requestId = (string)($data['request_id'] ?? '');
if ($requestId !== '' && (strlen($requestId) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId))) {
    respond(['success' => false, 'message' => 'Invalid request_id.'], 422);
}
$bulkDiscountPercent = null;
if (isset($data['bulk_discount_percent']) && $data['bulk_discount_percent'] !== null && $data['bulk_discount_percent'] !== '') {
    $bulkDiscountPercent = (float)$data['bulk_discount_percent'];
    if ($bulkDiscountPercent <= 0 || $bulkDiscountPercent > SalesService::MAX_BULK_DISCOUNT_PERCENT) {
        respond(['success' => false, 'message' => 'Bulk discount percentage is outside the allowed range.'], 422);
    }
}

$salesService = new SalesService();

try {
    $result = $salesService->createSale($items, $user['id'], $paymentMethod, $requestId ?: null, $bulkDiscountPercent);

    log_activity((int)$user['id'], 'sale_completed', "Receipt: {$result['receipt_number']}, Amount: {$result['total_amount']}");

    respond([
        'success' => true,
        'message' => 'Sale completed successfully.',
        'receipt_number' => $result['receipt_number'],
        'total_amount' => $result['total_amount'],
        'total_profit' => $user['role'] === 'OWNER' ? $result['total_profit'] : null,
    ], 201);
} catch (Throwable $exception) {
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'An internal error occurred while processing the sale.';
    $statusCode = $exception instanceof RuntimeException ? 422 : 500;
    error_log('[sales] ' . ($statusCode === 500 ? $exception->getMessage() : 'validation: ' . $exception->getMessage()));
    respond(['success' => false, 'message' => $message], $statusCode);
}
