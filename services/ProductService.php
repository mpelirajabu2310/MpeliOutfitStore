<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class ProductService extends BaseService
{
    public function addProduct(string $name, float $buyingPrice, float $sellingPrice, float $minPrice, int $stock, int $userId, int $threshold = 5): array
    {
        $categoryId = $this->generalCategoryId();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO products (category_id, product_name, buying_price, selling_price, minimum_allowed_selling_price, created_by)
                 VALUES (:category_id, :product_name, :buying_price, :selling_price, :min_price, :created_by)'
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'product_name' => $name,
                'buying_price' => $buyingPrice,
                'selling_price' => $sellingPrice,
                'min_price' => $minPrice,
                'created_by' => $userId,
            ]);
            $productId = (int)$this->db->lastInsertId();

            $vStmt = $this->db->prepare(
                'INSERT INTO product_variants (product_id, stock_quantity, reorder_level)
                 VALUES (:product_id, :stock_quantity, :reorder_level)'
            );
            $vStmt->execute([
                'product_id' => $productId,
                'stock_quantity' => $stock,
                'reorder_level' => $threshold,
            ]);

            $this->db->commit();
            return ['product_id' => $productId, 'created' => true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateDuplicateProduct(int $existingId, float $buyingPrice, float $sellingPrice, float $minPrice, int $newStock, int $threshold): void
    {
        $this->db->beginTransaction();
        try {
            $uStmt = $this->db->prepare(
                'UPDATE products SET buying_price = :buying_price, selling_price = :selling_price, minimum_allowed_selling_price = :min_price WHERE id = :id'
            );
            $uStmt->execute(['id' => $existingId, 'buying_price' => $buyingPrice, 'selling_price' => $sellingPrice, 'min_price' => $minPrice]);

            $vStmt = $this->db->prepare(
                'UPDATE product_variants SET stock_quantity = :stock, reorder_level = :reorder_level WHERE product_id = :product_id'
            );
            $vStmt->execute(['product_id' => $existingId, 'stock' => $newStock, 'reorder_level' => $threshold]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateProduct(int $id, string $name, float $buyingPrice, float $sellingPrice, float $minPrice): void
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET product_name = :name, buying_price = :buying_price, selling_price = :selling_price, minimum_allowed_selling_price = :min_price WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'name' => $name, 'buying_price' => $buyingPrice, 'selling_price' => $sellingPrice, 'min_price' => $minPrice]);
    }

    public function deleteProduct(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE products SET status = "inactive" WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function getProductById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.product_name, p.buying_price, p.selling_price, p.minimum_allowed_selling_price, p.status
             FROM products p WHERE p.id = :id AND p.status = "active" LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findDuplicateByName(string $name): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, COALESCE(SUM(pv.stock_quantity), 0) AS current_stock
             FROM products p
             LEFT JOIN product_variants pv ON pv.product_id = p.id
             WHERE LOWER(p.product_name) = LOWER(:name) AND p.status = "active"
             GROUP BY p.id LIMIT 1'
        );
        $stmt->execute(['name' => $name]);
        return $stmt->fetch() ?: null;
    }

    public function getAllProducts(?string $search = null, int $threshold = 5, string $role = 'SELLER'): array
    {
        $sql = 'SELECT
                  p.id,
                  MIN(pv.id) AS variant_id,
                  p.product_name AS name,
                  p.buying_price AS buying,
                  p.selling_price AS selling,
                  p.minimum_allowed_selling_price AS min_price,
                  COALESCE(SUM(pv.stock_quantity), 0) AS stock,
                  COALESCE(MIN(pv.reorder_level), :threshold) AS reorder_level
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                WHERE p.status = "active"';

        $params = ['threshold' => $threshold];
        if ($search !== null && $search !== '') {
            $sql .= ' AND p.product_name LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $sql .= ' GROUP BY p.id, p.product_name, p.buying_price, p.selling_price, p.minimum_allowed_selling_price
                  ORDER BY p.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        foreach ($products as &$product) {
            $stock = (int)$product['stock'];
            $product['profit_per_unit'] = (float)$product['selling'] - (float)$product['buying'];
            $product['stock_status'] = $stock === 0 ? 'out_of_stock' : ($stock <= $threshold ? 'low_stock' : 'in_stock');
            if ($role !== 'OWNER') {
                $product['buying'] = null;
                $product['profit_per_unit'] = null;
            }
        }

        return $products;
    }

    public function updateVariantStock(int $variantId, int $stock, int $threshold): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_variants SET stock_quantity = :stock, reorder_level = :reorder_level WHERE id = :id'
        );
        $stmt->execute(['stock' => $stock, 'reorder_level' => $threshold, 'id' => $variantId]);
    }

    public function getFirstVariantId(int $productId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM product_variants WHERE product_id = :product_id ORDER BY id ASC LIMIT 1');
        $stmt->execute(['product_id' => $productId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private function generalCategoryId(): int
    {
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => 'General']);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $this->db->exec('INSERT INTO categories (name) VALUES ("General")');
        return (int)$this->db->lastInsertId();
    }
}
