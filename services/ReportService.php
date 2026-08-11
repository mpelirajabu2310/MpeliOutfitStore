<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/SalesService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProfitService.php';
require_once __DIR__ . '/ExpenseService.php';
require_once __DIR__ . '/ReportPeriodHelper.php';

class ReportService extends BaseService
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

    public function getReportStats(?int $userId = null, bool $isOwner = false): array
    {
        $sellerFilter = $userId !== null && !$isOwner ? $userId : null;

        $dailySales = $this->sales->getDailySales($sellerFilter);
        $weeklySales = $this->sales->getWeeklySales($sellerFilter);
        $monthlySales = $this->sales->getMonthlySales($sellerFilter);

        $dailyProfit = null;
        $dailyBuyingCost = null;
        $monthlyProfit = null;
        $monthlyBuyingCost = null;
        $dailyExpenses = null;
        $monthlyExpenses = null;
        $dailyNetProfit = null;
        $monthlyNetProfit = null;
        $yearlyRevenue = null;
        $yearlyProfit = null;
        $yearlyBuyingCost = null;
        $yearlyExpenses = null;
        $yearlyNetProfit = null;
        $expenseCategoryBreakdown = [];

        if ($isOwner) {
            $dailyProfit = $this->profit->calculateDailyProfit();
            $dailyBuyingCost = $this->profit->calculateDailyBuyingCost();
            $monthlyProfit = $this->profit->calculateMonthlyProfit();
            $monthlyBuyingCost = $this->profit->calculateMonthlyBuyingCost();
            $dailyExpenses = $this->profit->calculateDailyExpenses();
            $monthlyExpenses = $this->profit->calculateMonthlyExpenses();
            $dailyNetProfit = $dailyProfit - $dailyExpenses;
            $monthlyNetProfit = $monthlyProfit - $monthlyExpenses;
            $yearlyRevenue = $this->profit->calculateYearlyRevenue();
            $yearlyProfit = $this->profit->calculateYearlyProfit();
            $yearlyBuyingCost = $this->profit->calculateYearlyBuyingCost();
            $yearlyExpenses = $this->profit->calculateYearlyExpenses();
            $yearlyNetProfit = $yearlyProfit - $yearlyExpenses;

            // Expense breakdown for current month
            $expenseCategoryBreakdown = $this->expense->getCategoryBreakdown();
        }

        $monthlyChart = $this->sales->getMonthlyChartData($sellerFilter);
        $bestSellers = $this->sales->getBestSellers($sellerFilter);

        return [
            'role' => $isOwner ? 'OWNER' : 'SELLER',
            'stats' => [
                'daily_sales' => $dailySales,
                'weekly_sales' => $weeklySales,
                'monthly_sales' => $monthlySales,
                'daily_profit' => $dailyProfit,
                'daily_buying_cost' => $dailyBuyingCost,
                'monthly_profit' => $monthlyProfit,
                'monthly_buying_cost' => $monthlyBuyingCost,
                'daily_expenses' => $dailyExpenses,
                'monthly_expenses' => $monthlyExpenses,
                'daily_net_profit' => $dailyNetProfit,
                'monthly_net_profit' => $monthlyNetProfit,
                'yearly_revenue' => $yearlyRevenue,
                'yearly_profit' => $yearlyProfit,
                'yearly_buying_cost' => $yearlyBuyingCost,
                'yearly_expenses' => $yearlyExpenses,
                'yearly_net_profit' => $yearlyNetProfit,
            ],
            'expense_categories' => $expenseCategoryBreakdown,
            'monthly_chart' => $monthlyChart,
            'best_sellers' => $bestSellers,
            'has_sales' => ($dailySales + $weeklySales + $monthlySales) > 0,
            'currency' => 'TSH',
        ];
    }

    /**
     * Single source of truth for all sales/financial figures. Both the
     * dashboards and the generated reports use this so values always match.
     */
    public function computePeriodFigures(?string $startDate, ?string $endDate, ?int $userId = null): array
    {
        $periodSales = $this->sales->getPeriodSales($startDate, $endDate, $userId);
        $revenue = (float)($periodSales['revenue'] ?? 0);
        $count = (int)($periodSales['count'] ?? 0);
        $grossProfit = (float)($periodSales['profit'] ?? 0);
        $buyingCost = $this->profit->calculatePeriodBuyingCost($startDate, $endDate, $userId);
        $expenses = $this->expense->getTotalExpenses($startDate, $endDate, $userId);
        $itemsSold = $this->sales->getItemsSold($userId, $startDate, $endDate);
        $discounts = $this->sales->getDiscountsGiven($userId, $startDate, $endDate);

        return [
            'sales' => $revenue,
            'revenue' => $revenue,
            'transactions' => $count,
            'items_sold' => $itemsSold,
            'avg_sale' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'buying_cost' => $buyingCost,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'net_profit' => $grossProfit - $expenses,
            'discounts' => $discounts,
        ];
    }

    /**
     * Period breakdown (today/week/month/year/total) for dashboards.
     */
    public function getDashboardAnalytics(?int $userId = null, bool $isOwner = false): array
    {
        $sellerFilter = $userId !== null && !$isOwner ? $userId : null;
        $periods = [];
        foreach (['today', 'week', 'month', 'year', 'total'] as $key) {
            $range = $this->resolveNamedPeriod($key);
            $figures = $this->computePeriodFigures($range['start'], $range['end'], $sellerFilter);
            $periods[$key] = $isOwner ? $figures : $this->sanitizeSellerFigures($figures);
        }

        return [
            'currency' => 'TSH',
            'role' => $isOwner ? 'OWNER' : 'SELLER',
            'periods' => $periods,
        ];
    }

    /**
     * Sellers must never see the business' cost / profit figures. Strips
     * margin-related keys so they can leak neither on the dashboard nor in
     * generated reports.
     */
    private function sanitizeSellerFigures(array $figures): array
    {
        foreach (['buying_cost', 'gross_profit', 'net_profit'] as $key) {
            unset($figures[$key]);
        }
        return $figures;
    }

    /**
     * Build a structured report used by the PDF and XLSX generators.
     *
     * Permissions are enforced here from the authenticated user object; the
     * request payload is never trusted for scope.
     */
    public function generateReport(array $options, array $user): array
    {
        $isOwner = ($user['role'] ?? '') === 'OWNER';
        $userId = $isOwner ? null : (int)($user['id'] ?? 0);

        $period = (string)($options['period'] ?? '');
        if (!ReportPeriodHelper::isValidPeriod($period)) {
            throw new InvalidArgumentException('Invalid report period.');
        }

        $range = ReportPeriodHelper::resolvePeriod(
            $period,
            (string)($options['start_date'] ?? '') !== '' ? (string)$options['start_date'] : null,
            (string)($options['end_date'] ?? '') !== '' ? (string)$options['end_date'] : null
        );

        $type = (string)($options['type'] ?? 'general');
        if (!in_array($type, ['general', 'custom'], true)) {
            throw new InvalidArgumentException('Invalid report type.');
        }

        if ($type === 'custom') {
            $requested = is_array($options['categories'] ?? null) ? array_map('strval', $options['categories']) : [];
            $categories = ReportPeriodHelper::filterCategories($user['role'] ?? 'SELLER', $requested);
            if (count($categories) === 0) {
                throw new InvalidArgumentException('No valid report categories selected.');
            }
        } else {
            $categories = ReportPeriodHelper::categoriesForRole($user['role'] ?? 'SELLER');
        }

        $store = $this->getStoreSettings();
        $summary = $this->computePeriodFigures($range['start'], $range['end'], $userId);
        if (!$isOwner) {
            $summary = $this->sanitizeSellerFigures($summary);
        }

        $sections = [];
        foreach ($categories as $category) {
            $section = $this->buildSection($category, $range['start'], $range['end'], $userId, $isOwner);
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        $title = $type === 'general' ? 'General Report' : ucwords(str_replace('_', ' ', implode(', ', $categories)));

        return [
            'meta' => [
                'store_name' => $store['shop_name'] ?? 'Mpeli Outfit Store',
                'address' => $store['address'] ?? '',
                'phone' => $store['phone'] ?? '',
                'currency' => $store['currency_code'] ?? 'TSH',
                'generated_by' => (string)($user['name'] ?? ''),
                'role' => $user['role'] ?? 'SELLER',
                'generated_at' => date('Y-m-d H:i:s'),
                'period_start' => $range['start'] ?? '',
                'period_end' => $range['end'] ?? '',
                'period' => $period,
                'title' => $title,
                'type' => $type,
                'categories' => $categories,
            ],
            'summary' => $summary,
            'sections' => $sections,
        ];
    }

    private function buildSection(string $category, ?string $start, ?string $end, ?int $userId, bool $isOwner): ?array
    {
        switch ($category) {
            case 'sales':
            case 'transactions':
                $showProfit = $isOwner;
                $rows = [];
                foreach ($this->sales->getSalesDetail($userId, $start, $end) as $s) {
                    $row = [
                        substr((string)$s['sale_date'], 0, 10),
                        (string)$s['receipt_number'],
                        (string)$s['customer_type'],
                        (int)$s['items_sold'],
                        (float)$s['total_amount'],
                    ];
                    if ($showProfit) {
                        $row[] = (float)$s['total_profit'];
                    }
                    $row[] = (string)$s['seller_name'];
                    $rows[] = $row;
                }
                $columns = [
                    ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                    ['label' => 'Receipt', 'align' => 'left', 'min' => 54, 'flex' => 0.9],
                    ['label' => 'Customer', 'align' => 'left', 'min' => 64, 'flex' => 2.2],
                    ['label' => 'Items', 'align' => 'right', 'min' => 36, 'flex' => 0.7],
                    ['label' => 'Revenue', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                ];
                if ($showProfit) {
                    $columns[] = ['label' => 'Profit', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6];
                }
                $columns[] = ['label' => 'Seller', 'align' => 'left', 'min' => 60, 'flex' => 1.6];
                return [
                    'key' => $category,
                    'title' => $category === 'transactions' ? 'Transactions' : 'Sales',
                    'columns' => $columns,
                    'rows' => $rows,
                ];

            case 'revenue':
                $rows = [];
                foreach ($this->sales->getDailySeries($userId, $start, $end) as $d) {
                    $rows[] = [
                        (string)$d['sale_day'],
                        (int)$d['transactions'],
                        (int)$d['items_sold'],
                        (float)$d['revenue'],
                    ];
                }
                return [
                    'key' => 'revenue',
                    'title' => 'Revenue',
                    'columns' => [
                        ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                        ['label' => 'Transactions', 'align' => 'right', 'min' => 44, 'flex' => 0.9],
                        ['label' => 'Items Sold', 'align' => 'right', 'min' => 44, 'flex' => 0.9],
                        ['label' => 'Revenue', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                    ],
                    'rows' => $rows,
                ];

            case 'expenses':
                $rows = [];
                foreach ($this->expense->getExpenseList($userId, $start, $end) as $e) {
                    $rows[] = [
                        (string)$e['expense_date'],
                        (string)$e['category'],
                        (string)($e['expense_name'] ?? ($e['description'] ?? '')),
                        (float)$e['amount'],
                        (string)$e['created_by_name'],
                    ];
                }
                $breakdown = [];
                foreach ($this->expense->getCategoryBreakdownByDateRange($start, $end, $userId) as $b) {
                    $breakdown[] = [(string)$b['category'], (float)$b['total']];
                }
                return [
                    'key' => 'expenses',
                    'title' => 'Expenses',
                    'columns' => [
                        ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                        ['label' => 'Category', 'align' => 'left', 'min' => 54, 'flex' => 1.8],
                        ['label' => 'Description', 'align' => 'left', 'min' => 72, 'flex' => 2.6],
                        ['label' => 'Amount', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Recorded By', 'align' => 'left', 'min' => 60, 'flex' => 1.4],
                    ],
                    'rows' => $rows,
                    'subsections' => [
                        [
                            'title' => 'Expenses by Category',
                            'columns' => [
                                ['label' => 'Category', 'align' => 'left', 'min' => 54, 'flex' => 1.8],
                                ['label' => 'Total', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                            ],
                            'rows' => $breakdown,
                        ],
                    ],
                ];

            case 'profit':
                if (!$isOwner) {
                    return null;
                }
                $dailyExpenses = [];
                foreach ($this->expense->getDailyTotals($userId, $start, $end) as $de) {
                    $dailyExpenses[(string)$de['expense_date']] = (float)$de['total'];
                }
                $rows = [];
                foreach ($this->sales->getDailySeries($userId, $start, $end) as $d) {
                    $exp = $dailyExpenses[(string)$d['sale_day']] ?? 0.0;
                    $rows[] = [
                        (string)$d['sale_day'],
                        (float)$d['revenue'],
                        (float)$d['profit'],
                        $exp,
                        round((float)$d['profit'] - $exp, 2),
                    ];
                }
                return [
                    'key' => 'profit',
                    'title' => 'Profit',
                    'columns' => [
                        ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                        ['label' => 'Revenue', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Gross Profit', 'align' => 'right', 'money' => true, 'min' => 80, 'flex' => 1.6],
                        ['label' => 'Expenses', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Net Profit', 'align' => 'right', 'money' => true, 'min' => 80, 'flex' => 1.6],
                    ],
                    'rows' => $rows,
                ];

            case 'purchases':
                $rows = [];
                foreach ($this->inventory->getPurchaseMovements($start, $end) as $m) {
                    $rows[] = [
                        (string)$m['created_at'],
                        (string)$m['product_name'],
                        (string)$m['movement_type'],
                        (int)$m['quantity_change'],
                        (string)($m['note'] ?? ''),
                        (string)($m['created_by_name'] ?? ''),
                    ];
                }
                return [
                    'key' => 'purchases',
                    'title' => 'Purchases / Stock In',
                    'columns' => [
                        ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                        ['label' => 'Product', 'align' => 'left', 'min' => 72, 'flex' => 2.6],
                        ['label' => 'Type', 'align' => 'left', 'min' => 48, 'flex' => 1.0],
                        ['label' => 'Qty', 'align' => 'right', 'min' => 36, 'flex' => 0.7],
                        ['label' => 'Note', 'align' => 'left', 'min' => 60, 'flex' => 2.0],
                        ['label' => 'By', 'align' => 'left', 'min' => 50, 'flex' => 1.0],
                    ],
                    'rows' => $rows,
                ];

            case 'inventory':
            case 'products':
                $rows = [];
                foreach ($this->inventory->getStockReportData() as $p) {
                    $rows[] = [
                        (string)$p['product_name'],
                        (string)$p['category_name'],
                        (int)$p['total_stock'],
                        (int)$p['reorder_level'],
                        (float)$p['buying_price'],
                        (float)$p['selling_price'],
                        (float)$p['profit_per_unit'],
                        (string)$p['stock_status'],
                    ];
                }
                return [
                    'key' => $category,
                    'title' => 'Inventory',
                    'columns' => [
                        ['label' => 'Product', 'align' => 'left', 'min' => 72, 'flex' => 2.6],
                        ['label' => 'Category', 'align' => 'left', 'min' => 54, 'flex' => 1.8],
                        ['label' => 'Stock', 'align' => 'right', 'min' => 36, 'flex' => 0.7],
                        ['label' => 'Reorder', 'align' => 'right', 'min' => 36, 'flex' => 0.7],
                        ['label' => 'Buying', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Selling', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Profit/Unit', 'align' => 'right', 'money' => true, 'min' => 80, 'flex' => 1.6],
                        ['label' => 'Status', 'align' => 'left', 'min' => 48, 'flex' => 1.0],
                    ],
                    'rows' => $rows,
                ];

            case 'stock_movement':
                $rows = [];
                foreach ($this->inventory->getInventoryMovements($start, $end) as $m) {
                    $size = $m['size_label'] ?? '';
                    $color = $m['color_label'] ?? '';
                    $variant = trim(($size !== '' ? $size : '') . ' ' . ($color !== '' ? $color : ''));
                    $rows[] = [
                        (string)$m['created_at'],
                        (string)$m['product_name'],
                        $variant,
                        (string)$m['movement_type'],
                        (int)$m['quantity_change'],
                        (string)($m['note'] ?? ''),
                        (string)($m['created_by_name'] ?? ''),
                    ];
                }
                return [
                    'key' => 'stock_movement',
                    'title' => 'Stock Movement',
                    'columns' => [
                        ['label' => 'Date', 'align' => 'left', 'min' => 60, 'flex' => 1.0],
                        ['label' => 'Product', 'align' => 'left', 'min' => 72, 'flex' => 2.6],
                        ['label' => 'Variant', 'align' => 'left', 'min' => 52, 'flex' => 1.2],
                        ['label' => 'Type', 'align' => 'left', 'min' => 48, 'flex' => 1.0],
                        ['label' => 'Qty', 'align' => 'right', 'min' => 36, 'flex' => 0.7],
                        ['label' => 'Note', 'align' => 'left', 'min' => 60, 'flex' => 2.0],
                        ['label' => 'By', 'align' => 'left', 'min' => 50, 'flex' => 1.0],
                    ],
                    'rows' => $rows,
                ];

            case 'seller_performance':
                if (!$isOwner) {
                    return null;
                }
                $rows = [];
                foreach ($this->sales->getSellerPerformance($start, $end) as $sp) {
                    $rows[] = [
                        (string)$sp['seller_name'],
                        (int)$sp['transactions'],
                        (int)$sp['items_sold'],
                        (float)$sp['revenue'],
                        (float)$sp['profit'],
                    ];
                }
                return [
                    'key' => 'seller_performance',
                    'title' => 'Seller Performance',
                    'columns' => [
                        ['label' => 'Seller', 'align' => 'left', 'min' => 64, 'flex' => 1.8],
                        ['label' => 'Transactions', 'align' => 'right', 'min' => 44, 'flex' => 0.9],
                        ['label' => 'Items Sold', 'align' => 'right', 'min' => 44, 'flex' => 0.9],
                        ['label' => 'Revenue', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                        ['label' => 'Profit', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                    ],
                    'rows' => $rows,
                ];

            case 'customers':
                if (!$isOwner) {
                    return null;
                }
                $rows = [];
                foreach ($this->sales->getCustomerSummary($start, $end) as $c) {
                    $rows[] = [
                        (string)$c['customer_name'],
                        (string)$c['customer_type'],
                        (string)($c['phone'] ?? ''),
                        (int)$c['transactions'],
                        (float)$c['revenue'],
                    ];
                }
                return [
                    'key' => 'customers',
                    'title' => 'Customers',
                    'columns' => [
                        ['label' => 'Customer', 'align' => 'left', 'min' => 64, 'flex' => 2.2],
                        ['label' => 'Type', 'align' => 'left', 'min' => 48, 'flex' => 1.0],
                        ['label' => 'Phone', 'align' => 'left', 'min' => 64, 'flex' => 1.2],
                        ['label' => 'Transactions', 'align' => 'right', 'min' => 44, 'flex' => 0.9],
                        ['label' => 'Revenue', 'align' => 'right', 'money' => true, 'min' => 72, 'flex' => 1.6],
                    ],
                    'rows' => $rows,
                ];

            default:
                return null;
        }
    }

    private function resolveNamedPeriod(string $key): array
    {
        switch ($key) {
            case 'today':
                return ReportPeriodHelper::resolvePeriod('today');
            case 'week':
                return ReportPeriodHelper::resolvePeriod('week');
            case 'month':
                return ReportPeriodHelper::resolvePeriod('month');
            case 'year':
                return ReportPeriodHelper::resolvePeriod('year');
            case 'total':
            default:
                return ['start' => null, 'end' => null];
        }
    }

    public function getStoreSettings(): array
    {
        $row = $this->db->query('SELECT * FROM shop_settings ORDER BY id LIMIT 1')->fetch();
        return $row ?: [];
    }

    public function generateFullReport(?string $startDate, ?string $endDate, string $generatedBy): array
    {
        $periodSales = $this->sales->getPeriodSales($startDate, $endDate);
        $periodRevenue = (float)($periodSales['revenue'] ?? 0);
        $periodProfit = (float)($periodSales['profit'] ?? 0);
        $periodBuyingCost = $this->profit->calculatePeriodBuyingCost($startDate, $endDate);
        $periodExpenses = $this->expense->getTotalExpenses($startDate, $endDate);
        $periodNetProfit = $periodProfit - $periodExpenses;
        $totalTransactions = (int)($periodSales['count'] ?? 0);

        // Total products sold in period
        $productsSold = 0;
        if ($startDate !== null && $endDate !== null) {
            $psStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(si.quantity), 0)
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE s.payment_status = 'paid'
                 AND s.sale_date >= :start_date AND s.sale_date < :end_date"
            );
            $psStmt->execute([
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ]);
            $productsSold = (int)$psStmt->fetchColumn();
        } else {
            $productsSold = (int)$this->db->query(
                "SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.payment_status = 'paid'"
            )->fetchColumn();
        }

        // Expense breakdown
        $expenseBreakdown = $this->expense->getCategoryBreakdownByDateRange($startDate, $endDate);

        $products = $this->db->query(
            'SELECT product_name, total_stock, buying_price, selling_price, profit_per_unit, stock_status
             FROM product_stock_summary ORDER BY product_name'
        )->fetchAll();

        $recentSales = [];
        if ($startDate !== null && $endDate !== null) {
            $recentSales = $this->sales->getSalesHistory(null, 100);
            $recentSales = array_filter($recentSales, function ($s) use ($startDate, $endDate) {
                $saleDate = substr($s['sale_date'] ?? '', 0, 10);
                return $saleDate >= $startDate && $saleDate <= $endDate;
            });
            $recentSales = array_values($recentSales);
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $generatedBy,
            'currency' => 'TSH',
            'period_start' => $startDate ?? '',
            'period_end' => $endDate ?? '',
            'low_stock_threshold' => $this->getLowStockThreshold(),
            'summary' => [
                'total_products' => $this->inventory->getTotalActiveProducts(),
                'total_sales' => $totalTransactions,
                'products_sold' => $productsSold,
                'period_revenue' => $periodRevenue,
                'period_buying_cost' => $periodBuyingCost,
                'period_profit' => $periodProfit,
                'period_expenses' => $periodExpenses,
                'period_net_profit' => $periodNetProfit,
            ],
            'expense_breakdown' => $expenseBreakdown,
            'products' => $products,
            'recent_sales' => $recentSales,
        ];
    }

    private function getLowStockThreshold(): int
    {
        $row = $this->db->query('SELECT low_stock_threshold FROM shop_settings ORDER BY id LIMIT 1')->fetch();
        return max(1, (int)($row['low_stock_threshold'] ?? 5));
    }
}
