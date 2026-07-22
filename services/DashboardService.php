<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/SalesService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProfitService.php';

class DashboardService extends BaseService
{
    private SalesService $sales;
    private InventoryService $inventory;
    private ProfitService $profit;

    public function __construct()
    {
        parent::__construct();
        $this->sales = new SalesService();
        $this->inventory = new InventoryService();
        $this->profit = new ProfitService();
    }

    public function getDashboardSummary(?int $userId = null, bool $isOwner = false): array
    {
        $threshold = $this->getLowStockThreshold();
        $sellerFilter = $userId !== null && !$isOwner ? $userId : null;

        $totalProducts = $this->inventory->getTotalActiveProducts();
        $totalSales = $this->sales->getTotalSalesCount($sellerFilter);
        $dailyRevenue = $this->sales->getDailyRevenue($sellerFilter);

        $dailyProfit = null;
        $dailyBuyingCost = null;
        $monthlyProfit = null;
        $monthlyBuyingCost = null;
        $yearlyProfit = null;
        $yearlyBuyingCost = null;
        $yearlyRevenue = null;
        $yearlyExpenses = null;
        $yearlyNetProfit = null;
        $dailyExpenses = null;
        $monthlyExpenses = null;
        $dailyNetProfit = null;
        $monthlyNetProfit = null;

        if ($isOwner) {
            $dailyProfit = $this->profit->calculateDailyProfit();
            $dailyBuyingCost = $this->profit->calculateDailyBuyingCost();
            $dailyRevenue_ = $this->profit->calculateDailyRevenue();
            $monthlyProfit = $this->profit->calculateMonthlyProfit();
            $monthlyBuyingCost = $this->profit->calculateMonthlyBuyingCost();
            $yearlyProfit = $this->profit->calculateYearlyProfit();
            $yearlyBuyingCost = $this->profit->calculateYearlyBuyingCost();
            $yearlyRevenue = $this->profit->calculateYearlyRevenue();
            $dailyExpenses = $this->profit->calculateDailyExpenses();
            $monthlyExpenses = $this->profit->calculateMonthlyExpenses();
            $yearlyExpenses = $this->profit->calculateYearlyExpenses();
            $dailyNetProfit = $dailyProfit - $dailyExpenses;
            $monthlyNetProfit = $monthlyProfit - $monthlyExpenses;
            $yearlyNetProfit = $yearlyProfit - $yearlyExpenses;
        }

        $lowStockItems = $this->inventory->getLowStockCount($threshold);
        $stockAlerts = $this->inventory->getLowStockAlerts($threshold);

        $recentSales = $this->sales->getRecentSales(8, $sellerFilter);
        if (!$isOwner) {
            foreach ($recentSales as &$sale) {
                $sale['total_profit'] = null;
            }
        }

        [$revenueChart, $revenueTotal] = $this->sales->getRevenueChartData($sellerFilter !== null ? ' AND sold_by = :user_id' : '', $sellerFilter !== null ? ['user_id' => $sellerFilter] : []);
        $profitChart = [];
        $profitTotal = 0.0;
        if ($isOwner) {
            [$profitChart, $profitTotal] = $this->sales->getProfitChartData();
        }

        $stockChart = $this->inventory->getStockChartData();

        return [
            'role' => $isOwner ? 'OWNER' : 'SELLER',
            'currency' => 'TSH',
            'low_stock_threshold' => $threshold,
            'stats' => [
                'total_products' => $totalProducts,
                'total_sales' => $totalSales,
                'daily_revenue' => $dailyRevenue,
                'daily_profit' => $dailyProfit,
                'daily_buying_cost' => $dailyBuyingCost,
                'monthly_profit' => $monthlyProfit,
                'monthly_buying_cost' => $monthlyBuyingCost,
                'yearly_profit' => $yearlyProfit,
                'yearly_buying_cost' => $yearlyBuyingCost,
                'yearly_revenue' => $yearlyRevenue,
                'yearly_expenses' => $yearlyExpenses,
                'yearly_net_profit' => $yearlyNetProfit,
                'daily_expenses' => $dailyExpenses,
                'monthly_expenses' => $monthlyExpenses,
                'daily_net_profit' => $dailyNetProfit,
                'monthly_net_profit' => $monthlyNetProfit,
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
        ];
    }

    private function getLowStockThreshold(): int
    {
        $row = $this->db->query('SELECT low_stock_threshold FROM shop_settings ORDER BY id LIMIT 1')->fetch();
        return max(1, (int)($row['low_stock_threshold'] ?? 5));
    }
}
