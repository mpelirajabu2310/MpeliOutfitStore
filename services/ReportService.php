<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/SalesService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProfitService.php';
require_once __DIR__ . '/ExpenseService.php';

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
