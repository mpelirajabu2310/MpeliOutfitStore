<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_role($pdo, ['OWNER']);

require_once __DIR__ . '/../services/InventoryService.php';
$inventoryService = new InventoryService();

$threshold = low_stock_threshold($pdo);

$totalStock = $inventoryService->getTotalStockValue();
$lowStock = $inventoryService->getStockByStatus('low_stock', $threshold);
$outOfStock = $inventoryService->getStockByStatus('out_of_stock', $threshold);
$allProducts = $inventoryService->getAllStockSummary();
$lowStockItems = $inventoryService->getLowStockItems();
$outOfStockItems = $inventoryService->getOutOfStockItems();

respond([
    'success' => true,
    'low_stock_threshold' => $threshold,
    'stats' => [
        'total_stock' => $totalStock,
        'low_stock' => $lowStock,
        'out_of_stock' => $outOfStock,
    ],
    'all_products' => $allProducts,
    'low_stock_items' => $lowStockItems,
    'out_of_stock_items' => $outOfStockItems,
]);
