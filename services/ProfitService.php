<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class ProfitService extends BaseService
{
    // ── Gross Profit (total_profit from sales = revenue - buying cost) ──

    public function calculateDailyProfit(?int $userId = null): float
    {
        return $this->sumProfit('DATE(sale_date) = CURDATE()', $userId);
    }

    public function calculateMonthlyProfit(?int $userId = null): float
    {
        return $this->sumProfit('YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE())', $userId);
    }

    public function calculateYearlyProfit(?int $userId = null): float
    {
        return $this->sumProfit('YEAR(sale_date) = YEAR(CURDATE())', $userId);
    }

    public function calculateGrossProfit(?string $startDate = null, ?string $endDate = null): float
    {
        return $this->sumProfitWithDateRange($startDate, $endDate);
    }

    // ── Buying Cost ──

    public function calculateDailyBuyingCost(?int $userId = null): float
    {
        return $this->sumBuyingCost('DATE(s.sale_date) = CURDATE()', $userId);
    }

    public function calculateMonthlyBuyingCost(?int $userId = null): float
    {
        return $this->sumBuyingCost('YEAR(s.sale_date) = YEAR(CURDATE()) AND MONTH(s.sale_date) = MONTH(CURDATE())', $userId);
    }

    public function calculateYearlyBuyingCost(?int $userId = null): float
    {
        return $this->sumBuyingCost('YEAR(s.sale_date) = YEAR(CURDATE())', $userId);
    }

    public function calculatePeriodBuyingCost(?string $startDate = null, ?string $endDate = null): float
    {
        $where = '';
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $where = ' AND s.sale_date >= :start_date AND s.sale_date < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(si.quantity * si.buying_price), 0)
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = 'paid'{$where}"
        );
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    // ── Revenue (total_amount) ──

    public function calculateDailyRevenue(?int $userId = null): float
    {
        return $this->sumRevenue('DATE(sale_date) = CURDATE()', $userId);
    }

    public function calculateMonthlyRevenue(?int $userId = null): float
    {
        return $this->sumRevenue('YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE())', $userId);
    }

    public function calculateYearlyRevenue(?int $userId = null): float
    {
        return $this->sumRevenue('YEAR(sale_date) = YEAR(CURDATE())', $userId);
    }

    public function calculatePeriodRevenue(?string $startDate = null, ?string $endDate = null): float
    {
        if ($startDate !== null && $endDate !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_status = 'paid' AND sale_date >= :start_date AND sale_date < :end_date"
            );
            $stmt->execute([
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ]);
            return (float)$stmt->fetchColumn();
        }
        return (float)$this->db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_status = 'paid'")->fetchColumn();
    }

    // ── Expenses ──

    public function calculateDailyExpenses(?int $userId = null): float
    {
        return $this->sumExpenses('expense_date = CURDATE()', $userId);
    }

    public function calculateMonthlyExpenses(?int $userId = null): float
    {
        return $this->sumExpenses('YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())', $userId);
    }

    public function calculateYearlyExpenses(?int $userId = null): float
    {
        return $this->sumExpenses('YEAR(expense_date) = YEAR(CURDATE())', $userId);
    }

    public function calculateTotalExpenses(?string $startDate = null, ?string $endDate = null): float
    {
        if ($startDate !== null && $endDate !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= :start_date AND expense_date < :end_date"
            );
            $stmt->execute([
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ]);
            return (float)$stmt->fetchColumn();
        }
        return (float)$this->db->query('SELECT COALESCE(SUM(amount), 0) FROM expenses')->fetchColumn();
    }

    public function getExpenseCategoryBreakdown(?string $startDate = null, ?string $endDate = null): array
    {
        $where = '';
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $where = ' WHERE expense_date >= :start_date AND expense_date < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $stmt = $this->db->prepare(
            "SELECT category, COALESCE(SUM(amount), 0) AS total
             FROM expenses{$where}
             GROUP BY category ORDER BY total DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Net Profit ──

    public function calculateDailyNetProfit(?int $userId = null): float
    {
        return $this->calculateDailyProfit($userId) - $this->calculateDailyExpenses($userId);
    }

    public function calculateMonthlyNetProfit(?int $userId = null): float
    {
        return $this->calculateMonthlyProfit($userId) - $this->calculateMonthlyExpenses($userId);
    }

    public function calculateYearlyNetProfit(?int $userId = null): float
    {
        return $this->calculateYearlyProfit($userId) - $this->calculateYearlyExpenses($userId);
    }

    public function calculatePeriodNetProfit(?string $startDate, ?string $endDate): float
    {
        return $this->calculateGrossProfit($startDate, $endDate) - $this->calculateTotalExpenses($startDate, $endDate);
    }

    public function calculateBuyingCost(?int $userId = null): float
    {
        return $this->calculateMonthlyBuyingCost($userId);
    }

    // ── Private helpers ──

    private function sumProfit(string $whereClause, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(total_profit), 0) FROM sales WHERE payment_status = 'paid' AND {$whereClause}";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    private function sumBuyingCost(string $whereClause, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(si.quantity * si.buying_price), 0)
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                WHERE s.payment_status = 'paid' AND {$whereClause}";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND s.sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    private function sumRevenue(string $whereClause, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_status = 'paid' AND {$whereClause}";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    private function sumExpenses(string $whereClause, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE {$whereClause}";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND created_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    private function sumProfitWithDateRange(?string $startDate, ?string $endDate): float
    {
        if ($startDate !== null && $endDate !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(total_profit), 0) FROM sales WHERE payment_status = 'paid' AND sale_date >= :start_date AND sale_date < :end_date"
            );
            $stmt->execute([
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ]);
            return (float)$stmt->fetchColumn();
        }
        return (float)$this->db->query("SELECT COALESCE(SUM(total_profit), 0) FROM sales WHERE payment_status = 'paid'")->fetchColumn();
    }
}
