<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';
$threshold = low_stock_threshold($pdo);
$sellerFilter = $isOwner ? '' : ' AND sold_by = :user_id';
$params = $isOwner ? [] : ['user_id' => $user['id']];

$totalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();

$salesCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sales WHERE payment_status = "paid"' . $sellerFilter
);
$salesCountStmt->execute($params);
$totalSales = (int)$salesCountStmt->fetchColumn();

$dailyStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) FROM sales
     WHERE payment_status = "paid" AND DATE(sale_date) = CURDATE()' . $sellerFilter
);
$dailyStmt->execute($params);
$dailyRevenue = (float)$dailyStmt->fetchColumn();

$dailyProfit = null;
$monthlyProfit = null;
if ($isOwner) {
    $dailyProfitStmt = $pdo->query(
        'SELECT COALESCE(SUM(total_profit), 0) FROM sales
         WHERE payment_status = "paid" AND DATE(sale_date) = CURDATE()'
    );
    $dailyProfit = (float)$dailyProfitStmt->fetchColumn();
    $monthlyProfit = (float)$pdo->query(
        'SELECT COALESCE(SUM(total_profit), 0) FROM sales
         WHERE payment_status = "paid"
         AND YEAR(sale_date) = YEAR(CURDATE())
         AND MONTH(sale_date) = MONTH(CURDATE())'
    )->fetchColumn();
}

$collate = 'COLLATE utf8mb4_unicode_ci';

$lowStockItems = (int)$pdo->query(
    "SELECT COUNT(*) FROM product_stock_summary WHERE stock_status {$collate} IN ('low_stock', 'out_of_stock')"
)->fetchColumn();

$stockAlerts = $pdo->query(
    "SELECT product_name, total_stock, stock_status
     FROM product_stock_summary
     WHERE stock_status {$collate} IN ('low_stock', 'out_of_stock')
     ORDER BY total_stock ASC
     LIMIT 10"
)->fetchAll();

$recentSql = 'SELECT s.receipt_number, COALESCE(c.customer_type, "walk_in") AS customer_type,
                     s.total_amount, s.total_profit, s.payment_status, s.sale_date, u.name AS seller_name
              FROM sales s
              LEFT JOIN customers c ON c.id = s.customer_id
              JOIN users u ON u.id = s.sold_by
              WHERE s.payment_status = "paid"';
if (!$isOwner) {
    $recentSql .= ' AND s.sold_by = :user_id';
}
$recentSql .= ' ORDER BY s.sale_date DESC LIMIT 8';
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($params);
$recentSales = $recentStmt->fetchAll();

if (!$isOwner) {
    foreach ($recentSales as &$sale) {
        $sale['total_profit'] = null;
    }
}

function build_daily_series(PDO $pdo, string $valueColumn, string $sellerFilter, array $params): array
{
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $days[$day] = 0.0;
    }

    $sql = "SELECT DATE(sale_date) AS sale_day, COALESCE(SUM({$valueColumn}), 0) AS value
            FROM sales
            WHERE payment_status = \"paid\"
            AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY){$sellerFilter}
            GROUP BY DATE(sale_date)
            ORDER BY sale_day";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $days[$row['sale_day']] = (float)$row['value'];
    }

    $series = [];
    $total = 0.0;
    foreach ($days as $saleDay => $value) {
        $series[] = ['sale_day' => $saleDay, 'value' => $value];
        $total += $value;
    }

    return [$series, $total];
}

[$revenueChart, $revenueTotal] = build_daily_series($pdo, 'total_amount', $sellerFilter, $params);
$profitChart = [];
$profitTotal = 0.0;
if ($isOwner) {
    [$profitChart, $profitTotal] = build_daily_series($pdo, 'total_profit', '', []);
}

$stockChart = $pdo->query(
    'SELECT product_name, total_stock AS value
     FROM product_stock_summary
     ORDER BY total_stock DESC
     LIMIT 8'
)->fetchAll();

respond([
    'success' => true,
    'role' => $user['role'],
    'currency' => 'TSH',
    'low_stock_threshold' => $threshold,
    'stats' => [
        'total_products' => $totalProducts,
        'total_sales' => $totalSales,
        'daily_revenue' => $dailyRevenue,
        'daily_profit' => $dailyProfit,
        'monthly_profit' => $monthlyProfit,
        'low_stock_items' => $lowStockItems,
    ],
    'stock_alerts' => $stockAlerts,
    'recent_sales' => $recentSales,
    'revenue_chart' => $revenueChart,
    'profit_chart' => $profitChart,
    'stock_chart' => $stockChart,
    'has_revenue_chart' => $revenueTotal > 0,
    'has_profit_chart' => $profitTotal > 0,
    'has_stock_chart' => count($stockChart) > 0,
]);
