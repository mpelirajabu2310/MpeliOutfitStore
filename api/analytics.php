<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';

require_once __DIR__ . '/../services/AnalyticsService.php';
require_once __DIR__ . '/../services/PermissionService.php';

PermissionService::requirePermission($user['role'], 'analytics.view');

$analytics = new AnalyticsService();
$action = $_GET['action'] ?? 'dashboard';
$period = $_GET['period'] ?? 'today';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;
$sellerIdParam = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : null;
$productIdParam = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

if (!AnalyticsService::isValidPeriod($period)) {
    respond(['success' => false, 'message' => 'Invalid period.'], 400);
}

$range = AnalyticsService::resolveDateRange($period, $customStart, $customEnd);
$start = $range['start'];
$end = $range['end'];

$sellerFilter = !$isOwner ? (int)$user['id'] : ($sellerIdParam);

// Enforce: sellers can only see their own data
if (!$isOwner) {
    $sellerFilter = (int)$user['id'];
}

switch ($action) {
    case 'dashboard':
        $kpis = $analytics->getDashboardKPIs($start, $end, $sellerFilter);
        $comparison = $analytics->getComparison($period, $sellerFilter);
        $dailySummary = null;
        $insights = null;
        if ($isOwner) {
            $dailySummary = $analytics->getDailySummary();
            $insights = $analytics->getInsights($start, $end);
        }
        respond([
            'success' => true,
            'kpis' => $kpis,
            'comparison' => $comparison,
            'daily_summary' => $dailySummary,
            'insights' => $insights,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
            'currency' => 'TSH',
        ]);
        break;

    case 'sales_trend':
        $trend = $analytics->getSalesTrend($start, $end, $sellerFilter);
        respond([
            'success' => true,
            'trend' => $trend,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'profit_trend':
        $trend = $analytics->getSalesTrendWithExpenses($start, $end, $sellerFilter);
        respond([
            'success' => true,
            'trend' => $trend,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'seller_performance':
        if (!$isOwner) {
            respond(['success' => false, 'message' => 'Access denied.'], 403);
        }
        $performance = $analytics->getSellerPerformance($start, $end);
        respond([
            'success' => true,
            'sellers' => $performance,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'seller_trend':
        if (!$isOwner && $sellerFilter !== (int)$user['id']) {
            respond(['success' => false, 'message' => 'Access denied.'], 403);
        }
        if ($sellerFilter === null) {
            respond(['success' => false, 'message' => 'Seller ID required.'], 400);
        }
        $trend = $analytics->getSellerTrend((int)$sellerFilter, $start, $end);
        respond([
            'success' => true,
            'trend' => $trend,
            'seller_id' => $sellerFilter,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'product_performance':
        $products = $analytics->getProductPerformance($start, $end, $sellerFilter);
        respond([
            'success' => true,
            'products' => $products,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'product_trend':
        if ($productIdParam === null) {
            respond(['success' => false, 'message' => 'Product ID required.'], 400);
        }
        $trend = $analytics->getProductTrend($productIdParam, $start, $end);
        respond([
            'success' => true,
            'trend' => $trend,
            'product_id' => $productIdParam,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'product_rankings':
        $sortBy = $_GET['sort'] ?? 'revenue';
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
        if ($sortBy === 'quantity') {
            $products = $analytics->getTopProductsByQuantity($start, $end, $limit);
        } elseif ($sortBy === 'profit') {
            $products = $analytics->getTopProductsByProfit($start, $end, $limit);
        } else {
            $products = $analytics->getTopProductsByRevenue($start, $end, $limit);
        }
        respond([
            'success' => true,
            'products' => $products,
            'sort' => $sortBy,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'product_categories':
        $categories = $analytics->getProductCategories($start, $end);
        respond([
            'success' => true,
            'categories' => $categories,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'expense_impact':
        if (!$isOwner) {
            respond(['success' => false, 'message' => 'Access denied.'], 403);
        }
        $impact = $analytics->getExpenseImpact($start, $end);
        respond([
            'success' => true,
            'impact' => $impact,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'discount_analysis':
        $discounts = $analytics->getDiscountAnalysis($start, $end, $sellerFilter);
        respond([
            'success' => true,
            'discounts' => $discounts,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'promotion_performance':
        if (!$isOwner) {
            respond(['success' => false, 'message' => 'Access denied.'], 403);
        }
        $promos = $analytics->getPromotionPerformance($start, $end);
        respond([
            'success' => true,
            'promotions' => $promos,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'daily_summary':
        $summary = $analytics->getDailySummary($sellerFilter);
        respond([
            'success' => true,
            'summary' => $summary,
            'currency' => 'TSH',
        ]);
        break;

    case 'insights':
        $insights = $analytics->getInsights($start, $end, $sellerFilter);
        respond([
            'success' => true,
            'insights' => $insights,
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        break;

    case 'growth':
        $growth = $analytics->getGrowthAnalysis($period, $sellerFilter);
        respond([
            'success' => true,
            'growth' => $growth,
            'period' => $period,
        ]);
        break;

    default:
        respond(['success' => false, 'message' => 'Invalid action.'], 400);
        break;
}
