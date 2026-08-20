<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

/**
 * Admin-defined promotions applied on top of the normal selling price.
 *
 * Pricing rules enforced here (and re-checked in SalesService):
 *  - active promotion price = selling_price - (selling_price * percentage / 100)
 *  - the promotion price is floored at the product's minimum_allowed_selling_price
 *  - a promotion is seller-visible only when status = 'active' AND now is within
 *    its start/end window (start_time/end_time optional, default 00:00:00 / 23:59:59)
 */
class PromotionService extends BaseService
{
    private const STATUS_ALLOWED = ['draft', 'active', 'inactive'];

    public function createPromotion(int $userId, array $data): int
    {
        $payload = $this->validatePayload($data, true);
        $productIds = $this->validateProductIds($data['product_ids'] ?? [], (bool)$payload['all_products']);
        $imagePath = !empty($data['image_path']) ? trim($data['image_path']) : null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO promotions (name, description, percentage, start_date, start_time, end_date, end_time, status, all_products, image_path, created_by)
                 VALUES (:name, :description, :percentage, :start_date, :start_time, :end_date, :end_time, :status, :all_products, :image_path, :created_by)'
            );
            $stmt->execute([
                'name' => $payload['name'],
                'description' => $payload['description'],
                'percentage' => $payload['percentage'],
                'start_date' => $payload['start_date'],
                'start_time' => $payload['start_time'],
                'end_date' => $payload['end_date'],
                'end_time' => $payload['end_time'],
                'status' => 'draft',
                'all_products' => (int)$payload['all_products'],
                'image_path' => $imagePath,
                'created_by' => $userId,
            ]);
            $promotionId = (int)$this->db->lastInsertId();

            $this->insertPromotionProducts($promotionId, $productIds);

            $this->db->commit();
            return $promotionId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updatePromotion(int $promotionId, array $data): void
    {
        $existing = $this->getPromotion($promotionId);
        if (!$existing) {
            throw new RuntimeException('Promotion not found.');
        }
        if ($existing['status'] === 'active') {
            throw new RuntimeException('Deactivate this promotion before editing it.');
        }

        $payload = $this->validatePayload($data, false);
        $productIds = $this->validateProductIds($data['product_ids'] ?? [], (bool)$payload['all_products']);

        $this->db->beginTransaction();
        try {
            $imagePath = array_key_exists('image_path', $data) ? ($data['image_path'] !== null ? trim($data['image_path']) : null) : $existing['image_path'];
            $stmt = $this->db->prepare(
                'UPDATE promotions
                 SET name = :name, description = :description, percentage = :percentage,
                     start_date = :start_date, start_time = :start_time,
                     end_date = :end_date, end_time = :end_time, all_products = :all_products,
                     image_path = :image_path
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $payload['name'],
                'description' => $payload['description'],
                'percentage' => $payload['percentage'],
                'start_date' => $payload['start_date'],
                'start_time' => $payload['start_time'],
                'end_date' => $payload['end_date'],
                'end_time' => $payload['end_time'],
                'all_products' => (int)$payload['all_products'],
                'image_path' => $imagePath,
                'id' => $promotionId,
            ]);

            $this->db->exec('DELETE FROM promotion_products WHERE promotion_id = ' . (int)$promotionId);
            $this->insertPromotionProducts($promotionId, $productIds);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $promotionId, string $status): void
    {
        if (!in_array($status, self::STATUS_ALLOWED, true)) {
            throw new RuntimeException('Invalid promotion status.');
        }
        $existing = $this->getPromotion($promotionId);
        if (!$existing) {
            throw new RuntimeException('Promotion not found.');
        }

        if ($status === 'active' && $existing['end_date'] < date('Y-m-d')) {
            throw new RuntimeException('This promotion has already ended and cannot be activated.');
        }
        // Optional end time on the same day
        if ($status === 'active'
            && $existing['end_date'] === date('Y-m-d')
            && $existing['end_time'] !== null
            && $existing['end_time'] < date('H:i:s')) {
            throw new RuntimeException('This promotion has already ended and cannot be activated.');
        }

        $stmt = $this->db->prepare('UPDATE promotions SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $promotionId]);
    }

    public function deletePromotion(int $promotionId): void
    {
        $imagePath = $this->getPromotionImage($promotionId);
        $stmt = $this->db->prepare('DELETE FROM promotions WHERE id = :id');
        $stmt->execute(['id' => $promotionId]);
        if ($imagePath !== null) {
            $imageService = new ImageService();
            $imageService->removeImageFile($imagePath);
        }
    }

    public function setPromotionImage(int $promotionId, ?string $imagePath): void
    {
        $stmt = $this->db->prepare('UPDATE promotions SET image_path = :image_path WHERE id = :id');
        $stmt->execute(['image_path' => $imagePath, 'id' => $promotionId]);
    }

    public function getPromotionImage(int $promotionId): ?string
    {
        $stmt = $this->db->prepare('SELECT image_path FROM promotions WHERE id = :id');
        $stmt->execute(['id' => $promotionId]);
        $path = $stmt->fetchColumn();
        return ($path !== false && $path !== null && $path !== '') ? (string)$path : null;
    }

    public function getPromotion(int $promotionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.name AS created_by_name
             FROM promotions p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $promotionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['effective_state'] = $this->getEffectiveState($row);
        $row['product_ids'] = $this->getPromotionProductIds($promotionId);
        $row['product_names'] = $this->getPromotionProductNames($promotionId);
        return $row;
    }

    /**
     * Admin listing: all promotions with their effective state and products.
     */
    public function listPromotions(): array
    {
        $promos = $this->db->query(
            'SELECT p.*, u.name AS created_by_name
             FROM promotions p
             LEFT JOIN users u ON u.id = p.created_by
             ORDER BY p.created_at DESC'
        )->fetchAll();

        foreach ($promos as &$promo) {
            $promo['effective_state'] = $this->getEffectiveState($promo);
            $promo['product_ids'] = $this->getPromotionProductIds((int)$promo['id']);
            $promo['product_names'] = $this->getPromotionProductNames((int)$promo['id']);
        }
        return $promos;
    }

    /**
     * Seller-facing listing: only promotions that are live right now.
     * all_products promotions carry empty product_ids (they cover everything).
     */
    public function getActivePromotionsForSeller(): array
    {
        $promos = $this->db->query(
            'SELECT id, name, percentage, all_products
             FROM promotions
             WHERE status = "active"
               AND (start_date < CURDATE()
                    OR (start_date = CURDATE() AND (start_time IS NULL OR start_time <= CURTIME())))
               AND (end_date > CURDATE()
                    OR (end_date = CURDATE() AND (end_time IS NULL OR end_time >= CURTIME())))
             ORDER BY id ASC'
        )->fetchAll();

        $result = [];
        $targeted = [];
        foreach ($promos as $promo) {
            if ((int)$promo['all_products'] === 1) {
                $result[] = [
                    'id' => (int)$promo['id'],
                    'name' => $promo['name'],
                    'percentage' => $promo['percentage'],
                    'all_products' => 1,
                    'product_ids' => [],
                ];
                continue;
            }
            $targeted[] = (int)$promo['id'];
        }

        if (!empty($targeted)) {
            $ids = implode(',', array_map('intval', $targeted));
            $rows = $this->db->query(
                "SELECT promotion_id, product_id FROM promotion_products WHERE promotion_id IN ({$ids})"
            )->fetchAll();
            $byPromo = [];
            foreach ($rows as $row) {
                $byPromo[(int)$row['promotion_id']][] = (int)$row['product_id'];
            }
            foreach ($promos as $promo) {
                if ((int)$promo['all_products'] === 1) {
                    continue;
                }
                $pid = (int)$promo['id'];
                $result[] = [
                    'id' => $pid,
                    'name' => $promo['name'],
                    'percentage' => $promo['percentage'],
                    'all_products' => 0,
                    'product_ids' => $byPromo[$pid] ?? [],
                ];
            }
        }

        return $result;
    }

    /**
     * Active promotion covering a product, if any. Deterministic (first by id).
     */
    public function getActivePromotionForProduct(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.name, p.percentage
             FROM promotions p
             WHERE p.status = "active"
               AND (p.start_date < CURDATE()
                    OR (p.start_date = CURDATE() AND (p.start_time IS NULL OR p.start_time <= CURTIME())))
               AND (p.end_date > CURDATE()
                    OR (p.end_date = CURDATE() AND (p.end_time IS NULL OR p.end_time >= CURTIME())))
               AND (p.all_products = 1 OR EXISTS (
                     SELECT 1 FROM promotion_products pp
                     WHERE pp.promotion_id = p.id AND pp.product_id = :product_id
                   ))
             ORDER BY p.id ASC LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * promo price = selling_price * (100 - percentage) / 100, rounded to 2 dp
     * and floored at the minimum allowed selling price (never below it).
     */
    public function getPromotionPrice(float $sellingPrice, float $percentage, float $minPrice): float
    {
        $discounted = round($sellingPrice * (100.0 - $percentage) / 100.0, 2);
        return max($minPrice, $discounted);
    }

    public function getEffectiveState(array $promo): string
    {
        $status = (string)($promo['status'] ?? 'draft');
        if ($status !== 'active') {
            return $status;
        }

        $startDate = (string)($promo['start_date'] ?? '');
        $endDate = (string)($promo['end_date'] ?? '');
        $startTime = (string)($promo['start_time'] ?? '');
        $endTime = (string)($promo['end_time'] ?? '');

        $start = $startDate !== '' ? $startDate . ' ' . ($startTime !== '' ? $startTime : '00:00:00') : '';
        $end = $endDate !== '' ? $endDate . ' ' . ($endTime !== '' ? $endTime : '23:59:59') : '';
        $now = date('Y-m-d H:i:s');

        if ($start !== '' && $now < $start) {
            return 'scheduled';
        }
        if ($end !== '' && $now > $end) {
            return 'expired';
        }
        return 'active';
    }

    private function insertPromotionProducts(int $promotionId, array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO promotion_products (promotion_id, product_id) VALUES (:promotion_id, :product_id)'
        );
        foreach ($productIds as $productId) {
            $stmt->execute(['promotion_id' => $promotionId, 'product_id' => $productId]);
        }
    }

    private function getPromotionProductIds(int $promotionId): array
    {
        $stmt = $this->db->prepare('SELECT product_id FROM promotion_products WHERE promotion_id = :promotion_id');
        $stmt->execute(['promotion_id' => $promotionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function getPromotionProductNames(int $promotionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.product_name
             FROM promotion_products pp
             JOIN products p ON p.id = pp.product_id
             WHERE pp.promotion_id = :promotion_id
             ORDER BY p.product_name ASC'
        );
        $stmt->execute(['promotion_id' => $promotionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function validatePayload(array $data, bool $isCreate): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Promotion name is required.');
        }
        if (mb_strlen($name) > 160) {
            throw new RuntimeException('Promotion name must be 160 characters or fewer.');
        }

        $description = trim((string)($data['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new RuntimeException('Promotion description must be 500 characters or fewer.');
        }

        $percentage = (float)($data['percentage'] ?? 0);
        if ($percentage <= 0 || $percentage > 100) {
            throw new RuntimeException('Discount percentage must be greater than 0 and no more than 100.');
        }

        $startDate = (string)($data['start_date'] ?? '');
        $endDate = (string)($data['end_date'] ?? '');
        $this->assertDate($startDate, 'start date');
        $this->assertDate($endDate, 'end date');
        if ($endDate < $startDate) {
            throw new RuntimeException('End date cannot be before start date.');
        }

        $startTime = $this->normalizeTime((string)($data['start_time'] ?? ''));
        $endTime = $this->normalizeTime((string)($data['end_time'] ?? ''));

        $allProducts = (bool)($data['all_products'] ?? false);

        return [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'percentage' => $percentage,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
            'all_products' => $allProducts,
        ];
    }

    private function validateProductIds($productIds, bool $allProducts): array
    {
        if (!is_array($productIds)) {
            $productIds = [];
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($productIds, fn($v) => is_numeric($v)))));
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (!$allProducts && count($ids) === 0) {
            throw new RuntimeException('Select at least one product for this promotion.');
        }
        if (count($ids) > 500) {
            throw new RuntimeException('Too many products selected for one promotion.');
        }

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id FROM products WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_diff($ids, $found);
            if (!empty($missing)) {
                throw new RuntimeException('One or more selected products do not exist.');
            }
        }

        return $ids;
    }

    private function assertDate(string $value, string $label): void
    {
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException('Invalid ' . $label . '. Use YYYY-MM-DD.');
        }
        $ts = strtotime($value);
        if ($ts === false || date('Y-m-d', $ts) !== $value) {
            throw new RuntimeException('Invalid ' . $label . '. Use YYYY-MM-DD.');
        }
    }

    private function normalizeTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            throw new RuntimeException('Invalid time. Use HH:MM.');
        }
        $normalized = date('H:i:s', strtotime($value));
        if ($normalized === false) {
            throw new RuntimeException('Invalid time.');
        }
        return $normalized;
    }
}
