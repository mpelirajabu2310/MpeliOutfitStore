<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';
$sellerFilter = $isOwner ? '' : ' AND sold_by = :user_id';
$params = $isOwner ? [] : ['user_id' => $user['id']];

$dailyStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) FROM sales
     WHERE payment_status = "paid" AND DATE(sale_date) = CURDATE()' . $sellerFilter
);
$dailyStmt->execute($params);
$dailySales = (float)$dailyStmt->fetchColumn();

$weeklyStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) FROM sales
     WHERE payment_status = "paid"
     AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)' . $sellerFilter
);
$weeklyStmt->execute($params);
$weeklySales = (float)$weeklyStmt->fetchColumn();

$monthlyStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount), 0) FROM sales
     WHERE payment_status = "paid"
     AND YEAR(sale_date) = YEAR(CURDATE())
     AND MONTH(sale_date) = MONTH(CURDATE())' . $sellerFilter
);
$monthlyStmt->execute($params);
$monthlySales = (float)$monthlyStmt->fetchColumn();

$monthlyProfit = null;
$dailyProfit = null;
if ($isOwner) {
    $dailyProfit = (float)$pdo->query(
        'SELECT COALESCE(SUM(total_profit), 0) FROM sales
         WHERE payment_status = "paid" AND DATE(sale_date) = CURDATE()'
    )->fetchColumn();
    $monthlyProfit = (float)$pdo->query(
        'SELECT COALESCE(SUM(total_profit), 0) FROM sales
         WHERE payment_status = "paid"
         AND YEAR(sale_date) = YEAR(CURDATE())
         AND MONTH(sale_date) = MONTH(CURDATE())'
    )->fetchColumn();
}

$monthlyChart = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-{$i} months"));
    $monthlyChart[$monthKey] = 0.0;
}

$chartSql = 'SELECT DATE_FORMAT(sale_date, "%Y-%m") AS report_month, COALESCE(SUM(total_amount), 0) AS revenue
             FROM sales
             WHERE payment_status = "paid"
             AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)' . $sellerFilter . '
             GROUP BY DATE_FORMAT(sale_date, "%Y-%m")
             ORDER BY report_month';
$chartStmt = $pdo->prepare($chartSql);
$chartStmt->execute($params);
foreach ($chartStmt->fetchAll() as $row) {
    $monthlyChart[$row['report_month']] = (float)$row['revenue'];
}

$chartRows = [];
foreach ($monthlyChart as $month => $revenue) {
    $chartRows[] = ['report_month' => $month, 'revenue' => $revenue];
}

$bestSellers = [];
if ($isOwner) {
    $bestStmt = $pdo->query(
        'SELECT product_name, category_name, units_sold, revenue, profit
         FROM best_selling_products
         LIMIT 8'
    );
    $bestSellers = $bestStmt->fetchAll();
} else {
    $bestStmt = $pdo->prepare(
        'SELECT p.product_name, c.name AS category_name,
                SUM(si.quantity) AS units_sold,
                SUM(si.line_total) AS revenue
         FROM sale_items si
         JOIN product_variants pv ON pv.id = si.variant_id
         JOIN products p ON p.id = pv.product_id
         JOIN categories c ON c.id = p.category_id
         JOIN sales s ON s.id = si.sale_id
         WHERE s.payment_status = "paid" AND s.sold_by = :user_id
         GROUP BY p.id, p.product_name, c.name
         ORDER BY units_sold DESC
         LIMIT 8'
    );
    $bestStmt->execute(['user_id' => $user['id']]);
    $bestSellers = $bestStmt->fetchAll();
}

respond([
    'success' => true,
    'role' => $user['role'],
    'stats' => [
        'daily_sales' => $dailySales,
        'weekly_sales' => $weeklySales,
        'monthly_sales' => $monthlySales,
        'daily_profit' => $dailyProfit,
        'monthly_profit' => $monthlyProfit,
    ],
    'monthly_chart' => $chartRows,
    'best_sellers' => $bestSellers,
    'has_sales' => ($dailySales + $weeklySales + $monthlySales) > 0,
    'currency' => 'TSH',
]);
