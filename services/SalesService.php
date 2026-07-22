<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ProfitService.php';

class SalesService extends BaseService
{
    private InventoryService $inventory;
    private ProfitService $profit;

    public function __construct()
    {
        parent::__construct();
        $this->inventory = new InventoryService();
        $this->profit = new ProfitService();
    }

    public function createSale(array $items, int $userId, string $paymentMethod = 'cash'): array
    {
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('At least one sale item is required.');
        }

        $this->db->beginTransaction();
        try {
            $receiptNumber = 'MM-' . date('Ymd-His') . '-' . random_int(100, 999);
            $subtotal = 0.0;
            $totalProfit = 0.0;
            $preparedItems = [];

            foreach ($items as $item) {
                $variantId = (int)($item['variant_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);
                $finalSellingPrice = isset($item['final_selling_price']) ? (float)$item['final_selling_price'] : null;
                $originalSellingPrice = isset($item['original_selling_price']) ? (float)$item['original_selling_price'] : null;

                if ($variantId <= 0 || $quantity <= 0) {
                    throw new RuntimeException('Invalid sale item.');
                }

                $variant = $this->inventory->getVariantWithProduct($variantId);
                if (!$variant) {
                    throw new RuntimeException('Product variant not found.');
                }
                if ((int)$variant['stock_quantity'] < $quantity) {
                    throw new RuntimeException('Not enough stock for one or more selected products.');
                }

                $effectivePrice = $finalSellingPrice ?? (float)$variant['selling_price'];
                $minPrice = (float)($variant['minimum_allowed_selling_price'] ?: $variant['buying_price']);

                if ($effectivePrice < $minPrice) {
                    throw new RuntimeException('The selling price is below the minimum allowed price for one or more selected products.');
                }
                if ($effectivePrice > (float)$variant['selling_price']) {
                    throw new RuntimeException('The selling price cannot exceed the listed price.');
                }

                $discountApplied = $effectivePrice < (float)$variant['selling_price'] ? 1 : 0;
                $lineTotal = $effectivePrice * $quantity;
                $lineProfit = ($effectivePrice - (float)$variant['buying_price']) * $quantity;
                $subtotal += $lineTotal;
                $totalProfit += $lineProfit;

                $preparedItems[] = [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'buying_price' => (float)$variant['buying_price'],
                    'selling_price' => $effectivePrice,
                    'original_selling_price' => $originalSellingPrice ?? (float)$variant['selling_price'],
                    'discount_applied' => $discountApplied,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ];
            }

            // Insert sale
            $sStmt = $this->db->prepare(
                'INSERT INTO sales (receipt_number, sold_by, subtotal, total_amount, total_profit, payment_status)
                 VALUES (:receipt_number, :sold_by, :subtotal, :total_amount, :total_profit, "paid")'
            );
            $sStmt->execute([
                'receipt_number' => $receiptNumber,
                'sold_by' => $userId,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'total_profit' => $totalProfit,
            ]);
            $saleId = (int)$this->db->lastInsertId();

            // Insert sale items, reduce stock, record movements
            $iStmt = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, variant_id, quantity, buying_price, selling_price, original_selling_price, discount_applied, line_total, line_profit)
                 VALUES (:sale_id, :variant_id, :quantity, :buying_price, :selling_price, :original_selling_price, :discount_applied, :line_total, :line_profit)'
            );
            foreach ($preparedItems as $pi) {
                $iStmt->execute([
                    'sale_id' => $saleId,
                    'variant_id' => $pi['variant_id'],
                    'quantity' => $pi['quantity'],
                    'buying_price' => $pi['buying_price'],
                    'selling_price' => $pi['selling_price'],
                    'original_selling_price' => $pi['original_selling_price'],
                    'discount_applied' => $pi['discount_applied'],
                    'line_total' => $pi['line_total'],
                    'line_profit' => $pi['line_profit'],
                ]);
                $this->inventory->decreaseStock($pi['variant_id'], $pi['quantity']);
                $this->inventory->recordMovement(
                    $pi['variant_id'], 'sale', -1 * $pi['quantity'],
                    'sale', $saleId, 'POS sale', $userId
                );
            }

            // Insert payment
            $pStmt = $this->db->prepare(
                'INSERT INTO payments (sale_id, payment_method, amount) VALUES (:sale_id, :payment_method, :amount)'
            );
            $pStmt->execute([
                'sale_id' => $saleId,
                'payment_method' => $paymentMethod,
                'amount' => $subtotal,
            ]);

            $this->db->commit();

            return [
                'sale_id' => $saleId,
                'receipt_number' => $receiptNumber,
                'total_amount' => $subtotal,
                'total_profit' => $totalProfit,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getDailySales(?int $userId = null): float
    {
        return $this->aggregateSales('DATE(sale_date) = CURDATE()', $userId);
    }

    public function getWeeklySales(?int $userId = null): float
    {
        return $this->aggregateSales('sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)', $userId);
    }

    public function getMonthlySales(?int $userId = null): float
    {
        return $this->aggregateSales('YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE())', $userId);
    }

    public function getYearlySales(?int $userId = null): float
    {
        return $this->aggregateSales('YEAR(sale_date) = YEAR(CURDATE())', $userId);
    }

    public function getSalesHistory(?int $userId = null, int $limit = 100): array
    {
        $sql = 'SELECT s.receipt_number, COALESCE(c.customer_type, "walk_in") AS customer_type,
                       s.total_amount, s.total_profit, s.payment_status, s.sale_date, u.name AS seller_name
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.id = s.sold_by
                WHERE s.payment_status = "paid"';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND s.sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY s.sale_date DESC LIMIT ' . max(1, $limit);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getTotalSalesCount(?int $userId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM sales WHERE payment_status = "paid"';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getDailyRevenue(?int $userId = null): float
    {
        return $this->aggregateSales('DATE(sale_date) = CURDATE()', $userId);
    }

    public function getRecentSales(int $limit = 8, ?int $userId = null): array
    {
        $sql = 'SELECT s.receipt_number, COALESCE(c.customer_type, "walk_in") AS customer_type,
                       s.total_amount, s.total_profit, s.payment_status, s.sale_date, u.name AS seller_name
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.id = s.sold_by
                WHERE s.payment_status = "paid"';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND s.sold_by = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY s.sale_date DESC LIMIT ' . max(1, $limit);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getRevenueChartData(string $sellerFilter = '', array $params = []): array
    {
        return $this->buildDailySeries('total_amount', $sellerFilter, $params);
    }

    public function getProfitChartData(): array
    {
        return $this->buildDailySeries('total_profit', '', []);
    }

    public function getMonthlyChartData(?int $userId = null): array
    {
        $sellerFilter = $userId !== null ? ' AND sold_by = :user_id' : '';
        $params = $userId !== null ? ['user_id' => $userId] : [];
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $chart[date('Y-m', strtotime("-{$i} months"))] = 0.0;
        }
        $sql = 'SELECT DATE_FORMAT(sale_date, "%Y-%m") AS report_month, COALESCE(SUM(total_amount), 0) AS revenue
                FROM sales WHERE payment_status = "paid"
                AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)' . $sellerFilter . '
                GROUP BY DATE_FORMAT(sale_date, "%Y-%m") ORDER BY report_month';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $chart[$row['report_month']] = (float)$row['revenue'];
        }
        $rows = [];
        foreach ($chart as $month => $revenue) {
            $rows[] = ['report_month' => $month, 'revenue' => $revenue];
        }
        return $rows;
    }

    public function getPeriodSales(?string $startDate, ?string $endDate): array
    {
        $dateFilter = '';
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $dateFilter = ' AND sale_date >= :start_date AND sale_date < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue, COALESCE(SUM(total_profit), 0) AS profit
             FROM sales WHERE payment_status = 'paid'{$dateFilter}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function getBestSellers(?int $userId = null): array
    {
        if ($userId !== null) {
            $stmt = $this->db->prepare(
                'SELECT p.product_name, c.name AS category_name,
                        SUM(si.quantity) AS units_sold,
                        SUM(si.line_total) AS revenue,
                        NULL AS profit
                 FROM sale_items si
                 JOIN product_variants pv ON pv.id = si.variant_id
                 JOIN products p ON p.id = pv.product_id
                 JOIN categories c ON c.id = p.category_id
                 JOIN sales s ON s.id = si.sale_id
                 WHERE s.payment_status = "paid" AND s.sold_by = :user_id
                 GROUP BY p.id, p.product_name, c.name
                 ORDER BY units_sold DESC LIMIT 8'
            );
            $stmt->execute(['user_id' => $userId]);
        } else {
            $stmt = $this->db->query(
                'SELECT product_name, category_name, units_sold, revenue, profit
                 FROM best_selling_products LIMIT 8'
            );
        }
        return $stmt->fetchAll();
    }

    private function aggregateSales(string $whereClause, ?int $userId = null): float
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

    private function buildDailySeries(string $valueColumn, string $sellerFilter, array $params): array
    {
        $allowedColumns = ['total_amount', 'total_profit'];
        if (!in_array($valueColumn, $allowedColumns, true)) {
            $valueColumn = 'total_amount';
        }
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-{$i} days"))] = 0.0;
        }
        $sql = "SELECT DATE(sale_date) AS sale_day, COALESCE(SUM({$valueColumn}), 0) AS value
                FROM sales WHERE payment_status = \"paid\"
                AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY){$sellerFilter}
                GROUP BY DATE(sale_date) ORDER BY sale_day";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $days[$row['sale_day']] = (float)$row['value'];
        }
        $series = [];
        $total = 0.0;
        foreach ($days as $day => $value) {
            $series[] = ['sale_day' => $day, 'value' => $value];
            $total += $value;
        }
        return [$series, $total];
    }
}
