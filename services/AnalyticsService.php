<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/SalesService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProfitService.php';
require_once __DIR__ . '/ExpenseService.php';

class AnalyticsService extends BaseService
{
    private SalesService $sales;
    private InventoryService $inventory;
    private ProfitService $profit;
    private ExpenseService $expense;

    public function __construct()
    {
        parent::__construct();
        $this->sales = new SalesService();
        $this->inventory = new InventoryService();
        $this->profit = new ProfitService();
        $this->expense = new ExpenseService();
    }

    // ── Date Range Resolution ──────────────────────────────────────────────

    public static function resolveDateRange(string $period, ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = new DateTimeImmutable('today');
        $start = null;
        $end = null;

        switch ($period) {
            case 'today':
                $start = $today->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'yesterday':
                $start = $today->modify('-1 day')->format('Y-m-d');
                $end = $today->modify('-1 day')->format('Y-m-d');
                break;
            case 'last_7_days':
                $start = $today->modify('-6 days')->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'last_30_days':
                $start = $today->modify('-29 days')->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'this_week':
                $start = $today->modify('monday this week')->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'last_week':
                $start = $today->modify('monday last week')->format('Y-m-d');
                $end = $today->modify('sunday last week')->format('Y-m-d');
                break;
            case 'this_month':
                $start = $today->format('Y-m-01');
                $end = $today->format('Y-m-d');
                break;
            case 'last_month':
                $start = $today->modify('first day of last month')->format('Y-m-d');
                $end = $today->modify('last day of last month')->format('Y-m-d');
                break;
            case 'this_year':
                $start = $today->format('Y-01-01');
                $end = $today->format('Y-m-d');
                break;
            case 'custom':
                $start = $customStart ?: $today->format('Y-m-d');
                $end = $customEnd ?: $today->format('Y-m-d');
                break;
            default:
                $start = $today->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function getPreviousRange(string $start, string $end): array
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $diff = $s->diff($e)->days + 1;
        $prevEnd = $s->modify('-1 day');
        $prevStart = $prevEnd->modify("-{$diff} days");
        return ['start' => $prevStart->format('Y-m-d'), 'end' => $prevEnd->format('Y-m-d')];
    }

    public static function isValidPeriod(string $period): bool
    {
        return in_array($period, [
            'today', 'yesterday', 'last_7_days', 'last_30_days',
            'this_week', 'last_week', 'this_month', 'last_month',
            'this_year', 'custom',
        ], true);
    }

    // ── Dashboard KPIs ─────────────────────────────────────────────────────

    public function getDashboardKPIs(string $start, string $end, ?int $userId = null): array
    {
        $revenue = $this->profit->calculatePeriodRevenue($start, $end);
        $buyingCost = $this->profit->calculatePeriodBuyingCost($start, $end, $userId);
        $grossProfit = $this->profit->calculateGrossProfit($start, $end, $userId);
        $expenses = $this->profit->calculatePeriodExpenses($start, $end, $userId);
        $netProfit = $grossProfit - $expenses;
        $salesCount = $this->sales->getSalesCount($userId, $start, $end);
        $itemsSold = $this->sales->getItemsSold($userId, $start, $end);
        $avgOrder = $this->sales->getAverageSaleValue($userId, $start, $end);
        $profitMargin = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0.0;
        $netProfitMargin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0.0;
        $distinctProducts = $this->getDistinctProductsSold($userId, $start, $end);
        $activeSellers = $this->getActiveSellersCount($start, $end);
        $discounts = $this->sales->getDiscountsGiven($userId, $start, $end);

        return [
            'revenue' => $revenue,
            'buying_cost' => $buyingCost,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'sales_count' => $salesCount,
            'items_sold' => $itemsSold,
            'avg_order_value' => $avgOrder,
            'profit_margin' => $profitMargin,
            'net_profit_margin' => $netProfitMargin,
            'distinct_products_sold' => $distinctProducts,
            'active_sellers' => $activeSellers,
            'discount_total' => $discounts,
            'currency' => 'TSH',
        ];
    }

    // ── Period Comparisons ──────────────────────────────────────────────────

    public function getComparison(string $period, ?int $userId = null): array
    {
        $range = self::resolveDateRange($period);
        $current = $this->getDashboardKPIs($range['start'], $range['end'], $userId);

        $prevRange = self::getPreviousRange($range['start'], $range['end']);
        $previous = $this->getDashboardKPIs($prevRange['start'], $prevRange['end'], $userId);

        return [
            'current' => $current,
            'previous' => $previous,
            'comparison' => $this->computeComparison($current, $previous),
        ];
    }

    private function computeComparison(array $current, array $previous): array
    {
        $keys = ['revenue', 'gross_profit', 'expenses', 'net_profit', 'sales_count', 'items_sold', 'avg_order_value'];
        $result = [];
        foreach ($keys as $key) {
            $cur = (float)($current[$key] ?? 0);
            $prev = (float)($previous[$key] ?? 0);
            if ($prev == 0 && $cur == 0) {
                $result[$key] = ['change' => 0, 'direction' => 'same', 'has_data' => false];
            } elseif ($prev == 0) {
                $result[$key] = ['change' => 0, 'direction' => 'same', 'has_data' => true, 'note' => 'no_previous'];
            } else {
                $change = round((($cur - $prev) / abs($prev)) * 100, 1);
                $result[$key] = [
                    'change' => $change,
                    'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'same'),
                    'has_data' => true,
                ];
            }
        }
        return $result;
    }

    // ── Sales Trend ─────────────────────────────────────────────────────────

    public function getSalesTrend(string $start, string $end, ?int $userId = null): array
    {
        $dailyData = $this->sales->getDailySeries($userId, $start, $end);
        $interval = $this->determineInterval($start, $end);

        if ($interval === 'weekly') {
            return $this->aggregateWeekly($dailyData);
        } elseif ($interval === 'monthly') {
            return $this->aggregateMonthly($dailyData);
        }
        return $dailyData;
    }

    public function getSalesTrendWithExpenses(string $start, string $end, ?int $userId = null): array
    {
        $dailySales = $this->sales->getDailySeries($userId, $start, $end);
        $dailyExpenses = [];
        foreach ($this->expense->getDailyTotals($userId, $start, $end) as $de) {
            $dailyExpenses[$de['expense_date']] = (float)$de['total'];
        }

        $result = [];
        foreach ($dailySales as $ds) {
            $day = $ds['sale_day'];
            $revenue = (float)$ds['revenue'];
            $profit = (float)$ds['profit'];
            $exp = $dailyExpenses[$day] ?? 0.0;
            $result[] = [
                'date' => $day,
                'revenue' => $revenue,
                'gross_profit' => $profit,
                'expenses' => $exp,
                'net_profit' => round($profit - $exp, 2),
                'transactions' => (int)$ds['transactions'],
                'items_sold' => (int)$ds['items_sold'],
            ];
        }
        return $result;
    }

    // ── Seller Performance ──────────────────────────────────────────────────

    public function getSellerPerformance(string $start, string $end): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id AS seller_id, u.name AS seller_name,
                    COUNT(s.id) AS transactions,
                    COALESCE(SUM(
                        (SELECT COALESCE(SUM(si2.quantity), 0) FROM sale_items si2 WHERE si2.sale_id = s.id)
                    ), 0) AS items_sold,
                    COALESCE(SUM(s.total_amount), 0) AS revenue,
                    COALESCE(SUM(s.total_profit), 0) AS gross_profit,
                    COALESCE(SUM(s.discount_amount), 0) AS discount_amount
             FROM sales s
             JOIN users u ON u.id = s.sold_by
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date
             GROUP BY u.id, u.name
             ORDER BY revenue DESC"
        );
        $stmt->execute([
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ]);
        $sellers = $stmt->fetchAll();

        $rank = 0;
        foreach ($sellers as &$seller) {
            $rank++;
            $seller['rank'] = $rank;
            $revenue = (float)$seller['revenue'];
            $profit = (float)$seller['gross_profit'];
            $transactions = (int)$seller['transactions'];
            $itemsSold = (int)$seller['items_sold'];
            $avgOrder = $transactions > 0 ? round($revenue / $transactions, 2) : 0.0;
            $avgItems = $transactions > 0 ? round($itemsSold / $transactions, 1) : 0.0;
            $profitMargin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;
            $discountRate = $revenue > 0 ? round(((float)$seller['discount_amount'] / ($revenue + (float)$seller['discount_amount'])) * 100, 1) : 0.0;

            $seller['avg_order_value'] = $avgOrder;
            $seller['avg_items_per_sale'] = $avgItems;
            $seller['profit_margin'] = $profitMargin;
            $seller['discount_rate'] = $discountRate;
            $seller['buying_cost'] = round($revenue - $profit, 2);
        }
        unset($seller);

        return $sellers;
    }

    public function getSellerTrend(int $sellerId, string $start, string $end): array
    {
        $dailyData = $this->sales->getDailySeries($sellerId, $start, $end);
        $dailyExpenses = [];
        foreach ($this->expense->getDailyTotals($sellerId, $start, $end) as $de) {
            $dailyExpenses[$de['expense_date']] = (float)$de['total'];
        }

        $result = [];
        foreach ($dailyData as $ds) {
            $day = $ds['sale_day'];
            $revenue = (float)$ds['revenue'];
            $profit = (float)$ds['profit'];
            $exp = $dailyExpenses[$day] ?? 0.0;
            $result[] = [
                'date' => $day,
                'revenue' => $revenue,
                'gross_profit' => $profit,
                'expenses' => $exp,
                'net_profit' => round($profit - $exp, 2),
                'transactions' => (int)$ds['transactions'],
                'items_sold' => (int)$ds['items_sold'],
            ];
        }
        return $result;
    }

    // ── Product Performance ─────────────────────────────────────────────────

    public function getProductPerformance(string $start, string $end, ?int $userId = null): array
    {
        $dateFilter = '';
        $params = [
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ];
        if ($userId !== null) {
            $dateFilter = ' AND s.sold_by = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = $this->db->prepare(
            "SELECT p.id AS product_id, p.product_name, c.name AS category_name,
                    COALESCE(SUM(si.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(si.line_total), 0) AS revenue,
                    COALESCE(SUM(si.quantity * si.buying_price), 0) AS buying_cost,
                    COALESCE(SUM(si.line_profit), 0) AS gross_profit,
                    SUM(CASE WHEN si.discount_applied = 1 THEN (si.original_selling_price - si.selling_price) * si.quantity ELSE 0 END) AS discount_amount,
                    SUM(CASE WHEN si.discount_applied = 1 THEN 1 ELSE 0 END) AS discounted_items,
                    COUNT(DISTINCT s.id) AS sales_frequency,
                    p.buying_price AS current_buying_price,
                    p.selling_price AS current_selling_price
             FROM sale_items si
             JOIN product_variants pv ON pv.id = si.variant_id
             JOIN products p ON p.id = pv.product_id
             JOIN categories c ON c.id = p.category_id
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date
             {$dateFilter}
             GROUP BY p.id, p.product_name, c.name, p.buying_price, p.selling_price
             ORDER BY revenue DESC"
        );
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        $threshold = $this->getLowStockThreshold();
        foreach ($products as &$product) {
            $revenue = (float)$product['revenue'];
            $cost = (float)$product['buying_cost'];
            $profit = (float)$product['gross_profit'];
            $qty = (int)$product['quantity_sold'];
            $salesFreq = (int)$product['sales_frequency'];
            $product['profit_margin'] = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;
            $product['avg_selling_price'] = $qty > 0 ? round($revenue / $qty, 2) : 0.0;
            $product['discount_rate'] = $revenue > 0 ? round(((float)$product['discount_amount'] / ($revenue + (float)$product['discount_amount'])) * 100, 1) : 0.0;

            // Get current stock
            $stockStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(pv.stock_quantity), 0) AS total_stock
                 FROM product_variants pv WHERE pv.product_id = :pid"
            );
            $stockStmt->execute(['pid' => $product['product_id']]);
            $totalStock = (int)$stockStmt->fetchColumn();
            $product['current_stock'] = $totalStock;
            $product['stock_status'] = $totalStock === 0 ? 'out_of_stock' : ($totalStock <= $threshold ? 'low_stock' : 'in_stock');
        }
        unset($product);

        return $products;
    }

    public function getProductTrend(int $productId, string $start, string $end): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(s.sale_date) AS sale_day,
                    COALESCE(SUM(si.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(si.line_total), 0) AS revenue,
                    COALESCE(SUM(si.line_profit), 0) AS gross_profit,
                    COALESCE(SUM((si.original_selling_price - si.selling_price) * si.quantity), 0) AS discount_amount
             FROM sale_items si
             JOIN product_variants pv ON pv.id = si.variant_id
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'
             AND pv.product_id = :product_id
             AND s.sale_date >= :start_date AND s.sale_date < :end_date
             GROUP BY DATE(s.sale_date)
             ORDER BY sale_day"
        );
        $stmt->execute([
            'product_id' => $productId,
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ]);
        return $stmt->fetchAll();
    }

    // ── Rankings ────────────────────────────────────────────────────────────

    public function getTopProductsByQuantity(string $start, string $end, int $limit = 10): array
    {
        return $this->getProductRanking($start, $end, 'quantity_sold', $limit);
    }

    public function getTopProductsByRevenue(string $start, string $end, int $limit = 10): array
    {
        return $this->getProductRanking($start, $end, 'revenue', $limit);
    }

    public function getTopProductsByProfit(string $start, string $end, int $limit = 10): array
    {
        return $this->getProductRanking($start, $end, 'gross_profit', $limit);
    }

    private function getProductRanking(string $start, string $end, string $sortBy, int $limit): array
    {
        $allowedSorts = ['quantity_sold', 'revenue', 'gross_profit'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'revenue';
        }

        $stmt = $this->db->prepare(
            "SELECT p.id AS product_id, p.product_name, c.name AS category_name,
                    COALESCE(SUM(si.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(si.line_total), 0) AS revenue,
                    COALESCE(SUM(si.line_profit), 0) AS gross_profit,
                    p.selling_price AS current_selling_price
             FROM sale_items si
             JOIN product_variants pv ON pv.id = si.variant_id
             JOIN products p ON p.id = pv.product_id
             JOIN categories c ON c.id = p.category_id
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date
             GROUP BY p.id, p.product_name, c.name, p.selling_price
             ORDER BY {$sortBy} DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ]);
        return $stmt->fetchAll();
    }

    // ── Product Categories ──────────────────────────────────────────────────

    public function getProductCategories(string $start, string $end): array
    {
        $allProducts = $this->getProductPerformance($start, $end);
        $threshold = $this->getLowStockThreshold();

        $bestSellers = [];
        $mostProfitable = [];
        $highRevenueLowProfit = [];
        $slowMoving = [];
        $outOfStock = [];
        $lowStock = [];

        // All active products
        $allActive = $this->db->query(
            "SELECT p.id, p.product_name, p.status FROM products p WHERE p.status = 'active'"
        )->fetchAll();

        $soldProductIds = [];
        foreach ($allProducts as $p) {
            $pid = $p['product_id'];
            $soldProductIds[] = $pid;
            $revenue = (float)$p['revenue'];
            $profit = (float)$p['gross_profit'];
            $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
            $qty = (int)$p['quantity_sold'];

            if ($qty >= 5) $bestSellers[] = $p;
            if ($profit >= 10000) $mostProfitable[] = $p;
            if ($revenue >= 50000 && $margin < 20) $highRevenueLowProfit[] = $p;

            if ($p['current_stock'] === 0) $outOfStock[] = $p;
            elseif ($p['current_stock'] <= $threshold) $lowStock[] = $p;
        }

        // Slow moving = products with very few or no sales in period
        foreach ($allActive as $ap) {
            if (!in_array($ap['id'], $soldProductIds, true)) {
                $slowMoving[] = [
                    'product_id' => $ap['id'],
                    'product_name' => $ap['product_name'],
                    'quantity_sold' => 0,
                    'revenue' => 0,
                    'gross_profit' => 0,
                ];
            }
        }

        return [
            'best_sellers' => $bestSellers,
            'most_profitable' => $mostProfitable,
            'high_revenue_low_profit' => $highRevenueLowProfit,
            'slow_moving' => $slowMoving,
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
        ];
    }

    // ── Expense Impact ──────────────────────────────────────────────────────

    public function getExpenseImpact(string $start, string $end, ?int $userId = null): array
    {
        $revenue = $this->profit->calculatePeriodRevenue($start, $end);
        $grossProfit = $this->profit->calculateGrossProfit($start, $end, $userId);
        $expenses = $this->profit->calculatePeriodExpenses($start, $end, $userId);
        $netProfit = $grossProfit - $expenses;
        $breakdown = $this->expense->getCategoryBreakdownByDateRange($start, $end, $userId);

        return [
            'revenue' => $revenue,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'expense_breakdown' => $breakdown,
        ];
    }

    // ── Discount Analysis ───────────────────────────────────────────────────

    public function getDiscountAnalysis(string $start, string $end, ?int $userId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(si.quantity), 0) AS total_items_sold,
                COALESCE(SUM(CASE WHEN si.discount_applied = 1 THEN si.quantity ELSE 0 END), 0) AS discounted_items,
                COALESCE(SUM(CASE WHEN si.discount_applied = 1 THEN (si.original_selling_price * si.quantity) ELSE (si.selling_price * si.quantity) END), 0) AS revenue_before_discount,
                COALESCE(SUM(si.line_total), 0) AS actual_revenue,
                COALESCE(SUM(CASE WHEN si.discount_applied = 1 THEN (si.original_selling_price - si.selling_price) * si.quantity ELSE 0 END), 0) AS total_discount_amount,
                COUNT(DISTINCT s.id) AS total_sales,
                COUNT(DISTINCT CASE WHEN si.discount_applied = 1 THEN s.id END) AS discounted_sales
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date"
        );
        $params = [
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ];
        if ($userId !== null) {
            $stmt = $this->db->prepare(
                $stmt->queryString . ' AND s.sold_by = :user_id'
            );
            $params['user_id'] = $userId;
        }
        $stmt->execute($params);
        $result = $stmt->fetch();

        $totalDiscount = (float)$result['total_discount_amount'];
        $revBefore = (float)$result['revenue_before_discount'];
        $discountPct = $revBefore > 0 ? round(($totalDiscount / $revBefore) * 100, 1) : 0.0;

        return [
            'total_items_sold' => (int)$result['total_items_sold'],
            'discounted_items' => (int)$result['discounted_items'],
            'revenue_before_discount' => $revBefore,
            'actual_revenue' => (float)$result['actual_revenue'],
            'total_discount_amount' => $totalDiscount,
            'discount_percentage' => $discountPct,
            'total_sales' => (int)$result['total_sales'],
            'discounted_sales' => (int)$result['discounted_sales'],
        ];
    }

    // ── Promotion Performance ───────────────────────────────────────────────

    public function getPromotionPerformance(string $start, string $end): array
    {
        $stmt = $this->db->prepare(
            "SELECT pr.id AS promotion_id, pr.name AS promotion_name, pr.percentage,
                    COUNT(DISTINCT s.id) AS sales_count,
                    COALESCE(SUM(si.quantity), 0) AS items_sold,
                    COALESCE(SUM(si.line_total), 0) AS revenue,
                    COALESCE(SUM(si.line_profit), 0) AS gross_profit,
                    COALESCE(SUM((si.original_selling_price - si.selling_price) * si.quantity), 0) AS discount_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN promotions pr ON pr.id = si.promotion_id
             WHERE s.payment_status = 'paid'
             AND si.pricing_type = 'promotion'
             AND si.promotion_id IS NOT NULL
             AND s.sale_date >= :start_date AND s.sale_date < :end_date
             GROUP BY pr.id, pr.name, pr.percentage
             ORDER BY revenue DESC"
        );
        $stmt->execute([
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ]);
        return $stmt->fetchAll();
    }

    // ── Daily Summary ───────────────────────────────────────────────────────

    public function getDailySummary(?int $userId = null): array
    {
        $start = date('Y-m-d');
        $end = date('Y-m-d');
        $kpis = $this->getDashboardKPIs($start, $end, $userId);

        $topProduct = null;
        $topSeller = null;

        if ($userId === null) {
            $tp = $this->db->query(
                "SELECT p.product_name, COALESCE(SUM(si.quantity), 0) AS qty
                 FROM sale_items si
                 JOIN product_variants pv ON pv.id = si.variant_id
                 JOIN products p ON p.id = pv.product_id
                 JOIN sales s ON s.id = si.sale_id
                 WHERE s.payment_status = 'paid' AND DATE(s.sale_date) = CURDATE()
                 GROUP BY p.id, p.product_name
                 ORDER BY qty DESC LIMIT 1"
            )->fetch();
            $topProduct = $tp ?: null;

            $ts = $this->db->query(
                "SELECT u.name AS seller_name, COALESCE(SUM(s.total_amount), 0) AS revenue
                 FROM sales s
                 JOIN users u ON u.id = s.sold_by
                 WHERE s.payment_status = 'paid' AND DATE(s.sale_date) = CURDATE()
                 GROUP BY u.id, u.name
                 ORDER BY revenue DESC LIMIT 1"
            )->fetch();
            $topSeller = $ts ?: null;
        }

        return [
            'kpis' => $kpis,
            'top_product' => $topProduct,
            'top_seller' => $topSeller,
        ];
    }

    // ── Business Insights ───────────────────────────────────────────────────

    public function getInsights(string $start, string $end, ?int $userId = null): array
    {
        $insights = [];
        $range = self::resolveDateRange($start === $end ? 'today' : 'custom', $start, $end);
        $kpis = $this->getDashboardKPIs($start, $end, $userId);

        $prevRange = self::getPreviousRange($start, $end);
        $prevKpis = $this->getDashboardKPIs($prevRange['start'], $prevRange['end'], $userId);

        // Revenue change
        if ((float)$prevKpis['revenue'] > 0) {
            $revChange = round((($kpis['revenue'] - $prevKpis['revenue']) / $prevKpis['revenue']) * 100, 1);
            if (abs($revChange) >= 5) {
                $dir = $revChange > 0 ? 'increased' : 'decreased';
                $insights[] = [
                    'type' => $revChange > 0 ? 'positive' : 'negative',
                    'text' => "Revenue {$dir} by " . abs($revChange) . "% compared with the previous period.",
                ];
            }
        }

        // Top seller
        $sellers = $this->getSellerPerformance($start, $end);
        if (!empty($sellers)) {
            $top = $sellers[0];
            $insights[] = [
                'type' => 'info',
                'text' => "Seller \"{$top['seller_name']}\" generated the highest revenue (TSh " . number_format((float)$top['revenue']) . ").",
            ];
        }

        // Top product by profit
        $topProfitProducts = $this->getTopProductsByProfit($start, $end, 1);
        if (!empty($topProfitProducts)) {
            $tp = $topProfitProducts[0];
            $insights[] = [
                'type' => 'info',
                'text' => "Product \"{$tp['product_name']}\" generated the highest profit (TSh " . number_format((float)$tp['gross_profit']) . ").",
            ];
        }

        // Expense increase
        if ((float)$prevKpis['expenses'] > 0 && $kpis['expenses'] > 0) {
            $expChange = round((($kpis['expenses'] - $prevKpis['expenses']) / $prevKpis['expenses']) * 100, 1);
            if ($expChange >= 20) {
                $insights[] = [
                    'type' => 'warning',
                    'text' => "Expenses increased by " . abs($expChange) . "% compared with the previous period.",
                ];
            }
        }

        // Profit margin decline
        if ((float)$prevKpis['revenue'] > 0 && (float)$kpis['revenue'] > 0) {
            $prevMargin = (float)$prevKpis['profit_margin'];
            $curMargin = (float)$kpis['profit_margin'];
            if ($prevMargin > $curMargin && ($prevMargin - $curMargin) >= 5) {
                $insights[] = [
                    'type' => 'warning',
                    'text' => "Profit margin declined from {$prevMargin}% to {$curMargin}%.",
                ];
            }
        }

        // Slow moving products
        $categories = $this->getProductCategories($start, $end);
        $slowCount = count($categories['slow_moving']);
        if ($slowCount > 0) {
            $insights[] = [
                'type' => 'warning',
                'text' => "{$slowCount} product(s) had very low or no sales during this period.",
            ];
        }

        // Out of stock
        $outCount = count($categories['out_of_stock']);
        if ($outCount > 0) {
            $insights[] = [
                'type' => 'negative',
                'text' => "{$outCount} product(s) are currently out of stock.",
            ];
        }

        return $insights;
    }

    // ── Growth Analysis ─────────────────────────────────────────────────────

    public function getGrowthAnalysis(string $period, ?int $userId = null): array
    {
        $range = self::resolveDateRange($period);
        $current = $this->getDashboardKPIs($range['start'], $range['end'], $userId);
        $prevRange = self::getPreviousRange($range['start'], $range['end']);
        $previous = $this->getDashboardKPIs($prevRange['start'], $prevRange['end'], $userId);

        $metrics = ['revenue', 'gross_profit', 'sales_count', 'items_sold'];
        $growth = [];
        foreach ($metrics as $m) {
            $cur = (float)$current[$m];
            $prev = (float)$previous[$m];
            if ($prev == 0 && $cur == 0) {
                $growth[$m] = ['value' => 0, 'has_data' => false];
            } elseif ($prev == 0) {
                $growth[$m] = ['value' => 0, 'has_data' => true, 'note' => 'no_previous'];
            } else {
                $growth[$m] = [
                    'value' => round((($cur - $prev) / abs($prev)) * 100, 1),
                    'has_data' => true,
                ];
            }
        }

        return [
            'current_period' => $range,
            'previous_period' => $prevRange,
            'current' => $current,
            'previous' => $previous,
            'growth' => $growth,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function getDistinctProductsSold(?int $userId, string $start, string $end): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT pv.product_id)
             FROM sale_items si
             JOIN product_variants pv ON pv.id = si.variant_id
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date"
        );
        $params = [
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ];
        if ($userId !== null) {
            $stmt = $this->db->prepare($stmt->queryString . ' AND s.sold_by = :user_id');
            $params['user_id'] = $userId;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function getActiveSellersCount(string $start, string $end): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT s.sold_by)
             FROM sales s
             WHERE s.payment_status = 'paid'
             AND s.sale_date >= :start_date AND s.sale_date < :end_date"
        );
        $stmt->execute([
            'start_date' => $start . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00',
        ]);
        return (int)$stmt->fetchColumn();
    }

    private function determineInterval(string $start, string $end): string
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $days = $s->diff($e)->days + 1;
        if ($days <= 31) return 'daily';
        if ($days <= 90) return 'weekly';
        return 'monthly';
    }

    private function aggregateWeekly(array $dailyData): array
    {
        $weeks = [];
        foreach ($dailyData as $row) {
            $day = $row['sale_day'];
            $weekStart = (new DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d');
            if (!isset($weeks[$weekStart])) {
                $weeks[$weekStart] = [
                    'sale_day' => $weekStart,
                    'revenue' => 0,
                    'profit' => 0,
                    'transactions' => 0,
                    'items_sold' => 0,
                ];
            }
            $weeks[$weekStart]['revenue'] += (float)$row['revenue'];
            $weeks[$weekStart]['profit'] += (float)$row['profit'];
            $weeks[$weekStart]['transactions'] += (int)$row['transactions'];
            $weeks[$weekStart]['items_sold'] += (int)$row['items_sold'];
        }
        return array_values($weeks);
    }

    private function aggregateMonthly(array $dailyData): array
    {
        $months = [];
        foreach ($dailyData as $row) {
            $month = substr($row['sale_day'], 0, 7);
            if (!isset($months[$month])) {
                $months[$month] = [
                    'sale_day' => $month,
                    'revenue' => 0,
                    'profit' => 0,
                    'transactions' => 0,
                    'items_sold' => 0,
                ];
            }
            $months[$month]['revenue'] += (float)$row['revenue'];
            $months[$month]['profit'] += (float)$row['profit'];
            $months[$month]['transactions'] += (int)$row['transactions'];
            $months[$month]['items_sold'] += (int)$row['items_sold'];
        }
        return array_values($months);
    }

    private function getLowStockThreshold(): int
    {
        $row = $this->db->query('SELECT low_stock_threshold FROM shop_settings ORDER BY id LIMIT 1')->fetch();
        return max(1, (int)($row['low_stock_threshold'] ?? 5));
    }
}
