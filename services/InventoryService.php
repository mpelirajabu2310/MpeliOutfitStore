<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class InventoryService extends BaseService
{
    public function getAvailableStock(int $variantId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(stock_quantity, 0) FROM product_variants WHERE id = :id');
        $stmt->execute(['id' => $variantId]);
        return (int)$stmt->fetchColumn();
    }

    public function checkStockAvailability(int $variantId, int $quantity): bool
    {
        return $this->getAvailableStock($variantId) >= $quantity;
    }

    public function decreaseStock(int $variantId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - :qty WHERE id = :variant_id AND stock_quantity >= :qty2');
        $stmt->execute(['qty' => $quantity, 'qty2' => $quantity, 'variant_id' => $variantId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Not enough stock for variant #' . $variantId);
        }
    }

    public function increaseStock(int $variantId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + :quantity WHERE id = :variant_id');
        $stmt->execute(['quantity' => $quantity, 'variant_id' => $variantId]);
    }

    public function getVariantWithProduct(int $variantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pv.id AS variant_id, p.id AS product_id, pv.stock_quantity, p.buying_price, p.selling_price, p.minimum_allowed_selling_price
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :variant_id AND p.status = \'active\'
             FOR UPDATE'
        );
        $stmt->execute(['variant_id' => $variantId]);
        return $stmt->fetch() ?: null;
    }

    public function recordMovement(int $variantId, string $type, int $quantityChange, ?string $referenceType = null, ?int $referenceId = null, ?string $note = null, ?int $userId = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_movements (variant_id, movement_type, quantity_change, reference_type, reference_id, note, created_by)
             VALUES (:variant_id, :movement_type, :quantity_change, :reference_type, :reference_id, :note, :created_by)'
        );
        $stmt->execute([
            'variant_id' => $variantId,
            'movement_type' => $type,
            'quantity_change' => $quantityChange,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    public function getLowStockCount(?int $threshold = null): int
    {
        if ($threshold === null) {
            $threshold = $this->getGlobalThreshold();
        }
        $collate = 'COLLATE utf8mb4_unicode_ci';
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM product_stock_summary WHERE stock_status {$collate} IN ('low_stock', 'out_of_stock')"
        )->fetchColumn();
    }

    public function getLowStockAlerts(?int $threshold = null): array
    {
        if ($threshold === null) {
            $threshold = $this->getGlobalThreshold();
        }
        $collate = 'COLLATE utf8mb4_unicode_ci';
        return $this->db->query(
            "SELECT product_name, total_stock, stock_status
             FROM product_stock_summary
             WHERE stock_status {$collate} IN ('low_stock', 'out_of_stock')
             ORDER BY total_stock ASC LIMIT 10"
        )->fetchAll();
    }

    public function getTotalStockValue(): int
    {
        return (int)$this->db->query(
            'SELECT COALESCE(SUM(pv.stock_quantity), 0)
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE p.status = \'active\''
        )->fetchColumn();
    }

    public function getStockByStatus(string $status, ?int $threshold = null): int
    {
        $allowed = ['low_stock', 'out_of_stock', 'in_stock'];
        if (!in_array($status, $allowed, true)) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM product_stock_summary WHERE stock_status = :status'
        );
        $stmt->execute(['status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    public function getAllStockSummary(): array
    {
        return $this->db->query(
            'SELECT product_name, total_stock, reorder_level, stock_status
             FROM product_stock_summary ORDER BY product_name ASC'
        )->fetchAll();
    }

    public function getLowStockItems(?int $limit = 12): array
    {
        $collate = 'COLLATE utf8mb4_unicode_ci';
        return $this->db->query(
            "SELECT product_name, total_stock, reorder_level, stock_status
             FROM product_stock_summary
             WHERE stock_status {$collate} = 'low_stock'
             ORDER BY total_stock ASC LIMIT {$limit}"
        )->fetchAll();
    }

    public function getOutOfStockItems(?int $limit = 12): array
    {
        $collate = 'COLLATE utf8mb4_unicode_ci';
        return $this->db->query(
            "SELECT product_name, total_stock, stock_status
             FROM product_stock_summary
             WHERE stock_status {$collate} = 'out_of_stock'
             ORDER BY product_name ASC LIMIT {$limit}"
        )->fetchAll();
    }

    public function getStockChartData(): array
    {
        return $this->db->query(
            'SELECT product_name, total_stock AS value
             FROM product_stock_summary
             ORDER BY total_stock DESC LIMIT 8'
        )->fetchAll();
    }

    public function getTotalActiveProducts(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
    }

    /**
     * Full stock catalog rows for reports (mirrors the product_stock_summary view).
     */
    public function getStockReportData(): array
    {
        return $this->db->query(
            'SELECT product_name, category_name, total_stock, reorder_level,
                    buying_price, selling_price, profit_per_unit, stock_status
             FROM product_stock_summary ORDER BY product_name ASC'
        )->fetchAll();
    }

    /**
     * Inventory movements within an inclusive date range (null = all time).
     */
    public function getInventoryMovements(?string $startDate = null, ?string $endDate = null, int $limit = 500): array
    {
        $sql = 'SELECT im.movement_type, im.quantity_change, im.reference_type, im.note,
                       im.created_at, p.product_name, sz.label AS size_label, cl.name AS color_label, u.name AS created_by_name
                FROM inventory_movements im
                JOIN product_variants pv ON pv.id = im.variant_id
                JOIN products p ON p.id = pv.product_id
                LEFT JOIN sizes sz ON sz.id = pv.size_id
                LEFT JOIN colors cl ON cl.id = pv.color_id
                LEFT JOIN users u ON u.id = im.created_by';
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $sql .= ' WHERE im.created_at >= :start_date AND im.created_at < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $sql .= ' ORDER BY im.created_at DESC LIMIT ' . max(1, min(2000, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Purchase-type movements within an inclusive date range (null = all time).
     */
    public function getPurchaseMovements(?string $startDate = null, ?string $endDate = null, int $limit = 500): array
    {
        $sql = "SELECT im.movement_type, im.quantity_change, im.reference_type, im.note,
                       im.created_at, p.product_name, u.name AS created_by_name
                FROM inventory_movements im
                JOIN product_variants pv ON pv.id = im.variant_id
                JOIN products p ON p.id = pv.product_id
                LEFT JOIN users u ON u.id = im.created_by
                WHERE im.movement_type IN ('stock_in', 'return', 'adjustment')";
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $sql .= ' AND im.created_at >= :start_date AND im.created_at < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $sql .= ' ORDER BY im.created_at DESC LIMIT ' . max(1, min(2000, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function getGlobalThreshold(): int
    {
        $row = $this->db->query('SELECT low_stock_threshold FROM shop_settings ORDER BY id LIMIT 1')->fetch();
        return max(1, (int)($row['low_stock_threshold'] ?? 5));
    }
}
