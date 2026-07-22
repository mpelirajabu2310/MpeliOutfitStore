<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_role($pdo, ['OWNER', 'SELLER']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$data = read_json_body();
$items = $data['items'] ?? [];
$paymentMethod = (string)($data['payment_method'] ?? 'cash');

require_once __DIR__ . '/../services/SalesService.php';
$salesService = new SalesService();

try {
    $result = $salesService->createSale($items, $user['id'], $paymentMethod);

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
