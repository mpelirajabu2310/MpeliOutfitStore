<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/PromotionService.php';

class SalesService extends BaseService
{
    public const MAX_BULK_DISCOUNT_PERCENT = 20;

    private InventoryService $inventory;
    private PromotionService $promotions;

    public function __construct()
    {
        parent::__construct();
        $this->inventory = new InventoryService();
        $this->promotions = new PromotionService();
    }

    public function createSale(array $items, int $userId, string $paymentMethod = 'cash', ?string $requestId = null, ?float $bulkDiscountPercent = null): array
    {
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('At least one sale item is required.');
        }

        if ($bulkDiscountPercent !== null) {
            if ($bulkDiscountPercent <= 0 || $bulkDiscountPercent > self::MAX_BULK_DISCOUNT_PERCENT) {
                throw new RuntimeException('Bulk discount percentage is outside the allowed range.');
            }
        }

        // Bulk discount requires 3+ total items in the cart.
        // Quantity of the same product counts toward the total (e.g. 3 x one product qualifies).
        $totalQuantity = array_sum(array_map(
            static fn(array $item): int => max(0, (int)($item['quantity'] ?? 0)),
            $items
        ));
        $bulkActive = $bulkDiscountPercent !== null && $totalQuantity >= 3;

        $this->db->beginTransaction();
        try {
            if ($requestId !== null && $requestId !== '') {
                $existing = $this->findSaleByRequestId($requestId);
                if ($existing) {
                    $this->db->commit();
                    return $existing;
                }
            }

            $receiptNumber = 'MM-' . date('Ymd-His') . '-' . random_int(100, 999);
            $subtotal = 0.0;
            $totalProfit = 0.0;
            $totalDiscount = 0.0;
            $preparedItems = [];

            foreach ($items as $item) {
                $variantId = (int)($item['variant_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);
                $finalSellingPrice = isset($item['final_selling_price']) ? (float)$item['final_selling_price'] : null;
                $clientPricingType = (string)($item['pricing_type'] ?? '');

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

                $listPrice = (float)$variant['selling_price'];
                $minPrice = (float)($variant['minimum_allowed_selling_price'] ?: $variant['buying_price']);

                // ── Determine the pricing mechanism (server-authoritative) ──
                $pricingType = 'normal';
                $promotionId = null;
                $lineBulkPercent = null;
                $effectivePrice = $listPrice;

                // 1) Explicit manual seller-set price (existing discount feature).
                //    Backwards compatible: legacy clients omit pricing_type but send a
                //    final_selling_price lower than the list price.
                $isManualOverride = $clientPricingType === 'existing_discount'
                    || ($clientPricingType === '' && $finalSellingPrice !== null && $finalSellingPrice < $listPrice);

                if ($isManualOverride) {
                    $effectivePrice = $finalSellingPrice ?? $listPrice;
                    if ($effectivePrice < $minPrice) {
                        throw new RuntimeException('The selling price is below the minimum allowed price for one or more selected products.');
                    }
                    if ($effectivePrice > $listPrice) {
                        throw new RuntimeException('The selling price cannot exceed the listed price.');
                    }
                    $pricingType = 'existing_discount';
                } else {
                    // 2) Active admin promotion (never below minimum allowed price).
                    $promo = $this->promotions->getActivePromotionForProduct((int)$variant['product_id']);
                    if ($promo) {
                        $promoPrice = $this->promotions->getPromotionPrice($listPrice, (float)$promo['percentage'], $minPrice);
                        if ($promoPrice < $listPrice) {
                            $effectivePrice = $promoPrice;
                            $pricingType = 'promotion';
                            $promotionId = (int)$promo['id'];
                        }
                    }

                    // 3) Bulk customer discount (whole cart, 3+ total items).
                    //    Does not stack with promotions or manual overrides.
                    if ($bulkActive && $pricingType === 'normal') {
                        $bulkPrice = round($listPrice * (100.0 - $bulkDiscountPercent) / 100.0, 2);
                        $bulkPrice = max($minPrice, $bulkPrice);
                        if ($bulkPrice < $listPrice) {
                            $effectivePrice = $bulkPrice;
                            $pricingType = 'bulk_discount';
                            $lineBulkPercent = $bulkDiscountPercent;
                        }
                    }
                }

                $discountApplied = $effectivePrice < $listPrice ? 1 : 0;
                $lineTotal = $effectivePrice * $quantity;
                $lineProfit = ($effectivePrice - (float)$variant['buying_price']) * $quantity;
                $subtotal += $lineTotal;
                $totalProfit += $lineProfit;
                if ($listPrice > $effectivePrice) {
                    $totalDiscount += ($listPrice - $effectivePrice) * $quantity;
                }

                $preparedItems[] = [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'buying_price' => (float)$variant['buying_price'],
                    'selling_price' => $effectivePrice,
                    'original_selling_price' => $listPrice,
                    'discount_applied' => $discountApplied,
                    'pricing_type' => $pricingType,
                    'promotion_id' => $promotionId,
                    'bulk_discount_percent' => $lineBulkPercent,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ];
            }

            // Insert sale
            $sStmt = $this->db->prepare(
                'INSERT INTO sales (receipt_number, sold_by, subtotal, discount_amount, bulk_discount_percent, total_amount, total_profit, payment_status, idempotency_key)
                 VALUES (:receipt_number, :sold_by, :subtotal, :discount_amount, :bulk_discount_percent, :total_amount, :total_profit, "paid", :idempotency_key)'
            );
            try {
                $sStmt->execute([
                    'receipt_number' => $receiptNumber,
                    'sold_by' => $userId,
                    'subtotal' => $subtotal,
                    'discount_amount' => round($totalDiscount, 2),
                    'bulk_discount_percent' => $bulkActive ? $bulkDiscountPercent : null,
                    'total_amount' => $subtotal,
                    'total_profit' => $totalProfit,
                    'idempotency_key' => $requestId,
                ]);
            } catch (PDOException $e) {
                if ($requestId !== null && $requestId !== '' && str_starts_with((string)$e->getCode(), '23')) {
                    $this->db->rollBack();
                    $this->db->beginTransaction();
                    $existing = $this->findSaleByRequestId($requestId);
                    if ($existing) {
                        $this->db->commit();
                        return $existing;
                    }
                }
                throw $e;
            }
            $saleId = (int)$this->db->lastInsertId();

            // Insert sale items, reduce stock, record movements
            $iStmt = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, variant_id, quantity, buying_price, selling_price, original_selling_price, discount_applied, pricing_type, promotion_id, bulk_discount_percent, line_total, line_profit)
                 VALUES (:sale_id, :variant_id, :quantity, :buying_price, :selling_price, :original_selling_price, :discount_applied, :pricing_type, :promotion_id, :bulk_discount_percent, :line_total, :line_profit)'
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
                    'pricing_type' => $pi['pricing_type'],
                    'promotion_id' => $pi['promotion_id'],
                    'bulk_discount_percent' => $pi['bulk_discount_percent'],
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

    public function getTotalSalesRevenue(?int $userId = null): float
    {
        return $this->aggregateSales('1 = 1', $userId);
    }

    /**
     * Number of paid sales within an inclusive date range (null = all time).
     */
    public function getSalesCount(?int $userId = null, ?string $startDate = null, ?string $endDate = null): int
    {
        return (int)$this->getPeriodAggregate('COUNT(*)', $userId, $startDate, $endDate)->fetchColumn();
    }

    /**
     * Total items (units) sold within an inclusive date range (null = all time).
     */
    public function getItemsSold(?int $userId = null, ?string $startDate = null, ?string $endDate = null): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(si.quantity), 0)
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = "paid"' . $this->userSql('s.sold_by', $userId) . $this->dateSql('s.sale_date', $startDate, $endDate)
        );
        $stmt->execute($this->userParams($userId, $this->dateParams($startDate, $endDate)));
        return (int)$stmt->fetchColumn();
    }

    /**
     * Average sale value (revenue / transactions). Returns 0.0 when no sales.
     */
    public function getAverageSaleValue(?int $userId = null, ?string $startDate = null, ?string $endDate = null): float
    {
        $count = $this->getSalesCount($userId, $startDate, $endDate);
        if ($count <= 0) {
            return 0.0;
        }
        $revenue = (float)$this->getPeriodAggregate('COALESCE(SUM(total_amount), 0)', $userId, $startDate, $endDate)->fetchColumn();
        return round($revenue / $count, 2);
    }

    /**
     * Total discount given within a range: the difference between the listed
     * selling price and the effective selling price, per unit sold.
     */
    public function getDiscountsGiven(?int $userId = null, ?string $startDate = null, ?string $endDate = null): float
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM((si.original_selling_price - si.selling_price) * si.quantity), 0)
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.payment_status = "paid"
             AND si.discount_applied = 1' . $this->userSql('s.sold_by', $userId) . $this->dateSql('s.sale_date', $startDate, $endDate)
        );
        $stmt->execute($this->userParams($userId, $this->dateParams($startDate, $endDate)));
        return (float)$stmt->fetchColumn();
    }

    /**
     * Per-sale transaction rows for reports (inclusive date range).
     */
    public function getSalesDetail(?int $userId = null, ?string $startDate = null, ?string $endDate = null, int $limit = 500): array
    {
        $sql = 'SELECT s.receipt_number,
                       COALESCE(c.customer_type, "walk_in") AS customer_type,
                       s.total_amount, s.total_profit, s.sale_date,
                       u.name AS seller_name,
                       (SELECT COALESCE(SUM(si2.quantity), 0) FROM sale_items si2 WHERE si2.sale_id = s.id) AS items_sold
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                JOIN users u ON u.id = s.sold_by
                WHERE s.payment_status = "paid"'
                . $this->userSql('s.sold_by', $userId)
                . $this->dateSql('s.sale_date', $startDate, $endDate);
        $sql .= ' ORDER BY s.sale_date DESC LIMIT ' . max(1, min(2000, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->userParams($userId, $this->dateParams($startDate, $endDate)));
        return $stmt->fetchAll();
    }

    /**
     * Revenue + profit grouped by day (inclusive date range).
     */
    public function getDailySeries(?int $userId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT DATE(s.sale_date) AS sale_day,
                    COALESCE(SUM(s.total_amount), 0) AS revenue,
                    COALESCE(SUM(s.total_profit), 0) AS profit,
                    COUNT(*) AS transactions,
                    COALESCE(SUM((SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id)), 0) AS items_sold
             FROM sales s
             WHERE s.payment_status = "paid"'
             . $this->userSql('s.sold_by', $userId)
             . $this->dateSql('s.sale_date', $startDate, $endDate)
             . ' GROUP BY DATE(s.sale_date) ORDER BY sale_day'
        );
        $stmt->execute($this->userParams($userId, $this->dateParams($startDate, $endDate)));
        return $stmt->fetchAll();
    }

    /**
     * Per-seller performance aggregates (OWNER only). userId is ignored for owners.
     */
    public function getSellerPerformance(?string $startDate = null, ?string $endDate = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.name AS seller_name,
                    COUNT(s.id) AS transactions,
                    COALESCE(SUM((SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id)), 0) AS items_sold,
                    COALESCE(SUM(s.total_amount), 0) AS revenue,
                    COALESCE(SUM(s.total_profit), 0) AS profit
             FROM sales s
             JOIN users u ON u.id = s.sold_by
             WHERE s.payment_status = "paid"' . $this->dateSql('s.sale_date', $startDate, $endDate)
             . ' GROUP BY u.id, u.name ORDER BY revenue DESC'
        );
        $stmt->execute($this->dateParams($startDate, $endDate));
        return $stmt->fetchAll();
    }

    /**
     * Customer purchase summary (OWNER only).
     */
    public function getCustomerSummary(?string $startDate = null, ?string $endDate = null, int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.customer_type,
                    COALESCE(NULLIF(TRIM(c.full_name), ""), "Walk-in / Unknown") AS customer_name,
                    c.phone,
                    COUNT(s.id) AS transactions,
                    COALESCE(SUM(s.total_amount), 0) AS revenue
             FROM customers c
             LEFT JOIN sales s ON s.customer_id = c.id AND s.payment_status = "paid"' . $this->dateSql('s.sale_date', $startDate, $endDate)
             . ' GROUP BY c.id, c.customer_type, c.full_name, c.phone ORDER BY revenue DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute($this->dateParams($startDate, $endDate));
        return $stmt->fetchAll();
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

    public function findSaleByRequestId(string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, receipt_number, total_amount, total_profit
             FROM sales WHERE idempotency_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $requestId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'sale_id' => (int)$row['id'],
            'receipt_number' => $row['receipt_number'],
            'total_amount' => (float)$row['total_amount'],
            'total_profit' => (float)$row['total_profit'],
        ];
    }

    public function getDailyRevenue(?int $userId = null): float
    {
        return $this->aggregateSales('DATE(sale_date) = CURDATE()', $userId);
    }

    public function getRecentSales(int $limit = 8, ?int $userId = null): array
    {
        $sql = 'SELECT s.id AS sale_id, s.receipt_number, COALESCE(c.customer_type, "walk_in") AS customer_type,
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

    public function getSaleDetails(int $saleId, ?int $userId = null): ?array
    {
        $sql = 'SELECT s.id AS sale_id, s.receipt_number, s.subtotal, s.discount_amount,
                       s.bulk_discount_percent, s.total_amount, s.total_profit, s.payment_status,
                       s.sale_date, u.name AS seller_name, s.sold_by,
                       COALESCE(c.customer_type, "walk_in") AS customer_type
                FROM sales s
                JOIN users u ON u.id = s.sold_by
                LEFT JOIN customers c ON c.id = s.customer_id
                WHERE s.id = :sale_id AND s.payment_status = "paid"';
        $params = ['sale_id' => $saleId];

        if ($userId !== null) {
            $sql .= ' AND s.sold_by = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $sale = $stmt->fetch();

        if (!$sale) {
            return null;
        }

        $itemsSql = 'SELECT si.id AS item_id, si.quantity, si.buying_price, si.selling_price,
                            si.original_selling_price, si.discount_applied, si.pricing_type,
                            si.bulk_discount_percent, si.line_total, si.line_profit,
                            p.product_name,
                            sz.label AS size_label,
                            cl.name AS color_label
                     FROM sale_items si
                     JOIN product_variants pv ON pv.id = si.variant_id
                     JOIN products p ON p.id = pv.product_id
                     LEFT JOIN sizes sz ON sz.id = pv.size_id
                     LEFT JOIN colors cl ON cl.id = pv.color_id
                     WHERE si.sale_id = :sale_id
                     ORDER BY si.id ASC';
        $itemsStmt = $this->db->prepare($itemsSql);
        $itemsStmt->execute(['sale_id' => $saleId]);
        $sale['items'] = $itemsStmt->fetchAll();

        return $sale;
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

    public function getPeriodSales(?string $startDate, ?string $endDate, ?int $userId = null): array
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
             . ($userId !== null ? ' AND sold_by = :user_id' : '')
        );
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }
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

    private function getPeriodAggregate(string $aggregate, ?int $userId = null, ?string $startDate = null, ?string $endDate = null): PDOStatement
    {
        $stmt = $this->db->prepare(
            'SELECT ' . $aggregate . ' FROM sales WHERE payment_status = "paid"'
            . $this->userSql('sold_by', $userId)
            . $this->dateSql('sale_date', $startDate, $endDate)
        );
        $stmt->execute($this->userParams($userId, $this->dateParams($startDate, $endDate)));
        return $stmt;
    }

    /**
     * Inclusive date range SQL fragment. Uses `>= :start 00:00:00 AND < end+1day`.
     */
    private function dateSql(string $column, ?string $startDate = null, ?string $endDate = null): string
    {
        if ($startDate === null || $endDate === null) {
            return '';
        }
        return ' AND ' . $column . ' >= :start_date AND ' . $column . ' < :end_date';
    }

    private function dateParams(?string $startDate = null, ?string $endDate = null): array
    {
        if ($startDate === null || $endDate === null) {
            return [];
        }
        return [
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
        ];
    }

    private function userSql(string $column, ?int $userId = null): string
    {
        return $userId !== null ? ' AND ' . $column . ' = :user_id' : '';
    }

    private function userParams(?int $userId = null, array $params = []): array
    {
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }
        return $params;
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
