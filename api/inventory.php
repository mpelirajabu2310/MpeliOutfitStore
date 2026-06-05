<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_role($pdo, ['OWNER']);
$threshold = low_stock_threshold($pdo);

$totalStock = (int)$pdo->query(
    'SELECT COALESCE(SUM(pv.stock_quantity), 0)
     FROM product_variants pv
     JOIN products p ON p.id = pv.product_id
     WHERE p.status = "active"'
)->fetchColumn();

$collate = 'COLLATE utf8mb4_unicode_ci';

$lowStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM product_stock_summary WHERE stock_status {$collate} = 'low_stock'"
)->fetchColumn();

$outOfStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM product_stock_summary WHERE stock_status {$collate} = 'out_of_stock'"
)->fetchColumn();

$allProducts = $pdo->query(
    'SELECT product_name, total_stock, reorder_level, stock_status
     FROM product_stock_summary
     ORDER BY product_name ASC'
)->fetchAll();

$lowStockItems = $pdo->query(
    "SELECT product_name, total_stock, reorder_level, stock_status
     FROM product_stock_summary
     WHERE stock_status {$collate} = 'low_stock'
     ORDER BY total_stock ASC
     LIMIT 12"
)->fetchAll();

$outOfStockItems = $pdo->query(
    "SELECT product_name, total_stock, stock_status
     FROM product_stock_summary
     WHERE stock_status {$collate} = 'out_of_stock'
     ORDER BY product_name ASC
     LIMIT 12"
)->fetchAll();

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
