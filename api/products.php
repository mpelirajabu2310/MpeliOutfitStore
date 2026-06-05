<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);
$threshold = low_stock_threshold($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $search = trim((string)($_GET['search'] ?? ''));

        $sql = 'SELECT
                  p.id,
                  MIN(pv.id) AS variant_id,
                  p.product_name AS name,
                  p.buying_price AS buying,
                  p.selling_price AS selling,
                  COALESCE(SUM(pv.stock_quantity), 0) AS stock,
                  COALESCE(MIN(pv.reorder_level), :threshold) AS reorder_level
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id
                WHERE p.status = "active"';

        $params = ['threshold' => $threshold];
        if ($search !== '') {
            $sql .= ' AND p.product_name LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $sql .= ' GROUP BY p.id, p.product_name, p.buying_price, p.selling_price
                  ORDER BY p.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        foreach ($products as &$product) {
            $stock = (int)$product['stock'];
            $product['profit_per_unit'] = (float)$product['selling'] - (float)$product['buying'];
            $product['stock_status'] = $stock === 0 ? 'out_of_stock' : ($stock <= $threshold ? 'low_stock' : 'in_stock');
            if ($user['role'] !== 'OWNER') {
                $product['buying'] = null;
                $product['profit_per_unit'] = null;
            }
        }

        respond(['success' => true, 'products' => $products, 'low_stock_threshold' => $threshold]);
    } catch (Throwable $e) {
        error_log('[products.php] GET error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to load products.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_role($pdo, ['OWNER']);
    $data = read_json_body();

    $name = trim((string)($data['name'] ?? ''));
    $buying = (float)($data['buying_price'] ?? 0);
    $selling = (float)($data['selling_price'] ?? 0);
    $stock = max(0, (int)($data['stock_quantity'] ?? 0));

    if ($name === '') {
        respond(['success' => false, 'message' => 'Product name is required.'], 422);
    }

    // Case-insensitive duplicate check
    $existingStmt = $pdo->prepare(
        'SELECT p.id, COALESCE(SUM(pv.stock_quantity), 0) AS current_stock
         FROM products p
         LEFT JOIN product_variants pv ON pv.product_id = p.id
         WHERE LOWER(p.product_name) = LOWER(:name) AND p.status = "active"
         GROUP BY p.id
         LIMIT 1'
    );
    $existingStmt->execute(['name' => $name]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        // Product exists — update stock instead of duplicating
        $existingId = (int)$existing['id'];
        $newStock = (int)$existing['current_stock'] + $stock;

        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare(
                'UPDATE products SET buying_price = :buying_price, selling_price = :selling_price WHERE id = :id'
            );
            $updateStmt->execute(['id' => $existingId, 'buying_price' => $buying, 'selling_price' => $selling]);

            $variantStmt = $pdo->prepare(
                'UPDATE product_variants SET stock_quantity = :stock, reorder_level = :reorder_level WHERE product_id = :product_id'
            );
            $variantStmt->execute([
                'product_id' => $existingId,
                'stock' => $newStock,
                'reorder_level' => $threshold,
            ]);

            $pdo->commit();
            respond(['success' => true, 'message' => 'Product already exists. Stock updated successfully.', 'product_id' => $existingId, 'updated' => true], 200);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    // Product does not exist — create new
    $pdo->beginTransaction();
    try {
        $productStmt = $pdo->prepare(
            'INSERT INTO products (category_id, product_name, buying_price, selling_price, created_by)
             VALUES (:category_id, :product_name, :buying_price, :selling_price, :created_by)'
        );
        $productStmt->execute([
            'category_id' => general_category_id($pdo),
            'product_name' => $name,
            'buying_price' => $buying,
            'selling_price' => $selling,
            'created_by' => $user['id'],
        ]);

        $productId = $pdo->lastInsertId();
        $variantStmt = $pdo->prepare(
            'INSERT INTO product_variants (product_id, stock_quantity, reorder_level)
             VALUES (:product_id, :stock_quantity, :reorder_level)'
        );
        $variantStmt->execute([
            'product_id' => $productId,
            'stock_quantity' => $stock,
            'reorder_level' => $threshold,
        ]);

        $pdo->commit();
        respond(['success' => true, 'message' => 'Product created successfully.', 'product_id' => (int)$productId], 201);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => 'Failed to create product: ' . $exception->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_role($pdo, ['OWNER']);
    $data = read_json_body();
    $productId = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $buying = (float)($data['buying_price'] ?? 0);
    $selling = (float)($data['selling_price'] ?? 0);
    $stock = isset($data['stock_quantity']) ? max(0, (int)$data['stock_quantity']) : null;

    if ($productId <= 0 || $name === '') {
        respond(['success' => false, 'message' => 'Product id and name are required.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE products
             SET product_name = :name, buying_price = :buying_price, selling_price = :selling_price
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $productId,
            'name' => $name,
            'buying_price' => $buying,
            'selling_price' => $selling,
        ]);

        if ($stock !== null) {
            $variantId = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = :product_id ORDER BY id ASC LIMIT 1');
            $variantId->execute(['product_id' => $productId]);
            $variant = $variantId->fetchColumn();
            if ($variant) {
                $stockStmt = $pdo->prepare(
                    'UPDATE product_variants SET stock_quantity = :stock, reorder_level = :reorder_level WHERE id = :id'
                );
                $stockStmt->execute([
                    'stock' => $stock,
                    'reorder_level' => $threshold,
                    'id' => $variant,
                ]);
            }
        }

        $pdo->commit();
        respond(['success' => true, 'message' => 'Product updated successfully.']);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        respond(['success' => false, 'message' => $exception->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_role($pdo, ['OWNER']);
    $data = read_json_body();
    $productId = (int)($data['id'] ?? 0);

    if ($productId <= 0) {
        respond(['success' => false, 'message' => 'Product id is required.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE products SET status = "inactive" WHERE id = :id');
    $stmt->execute(['id' => $productId]);

    respond(['success' => true, 'message' => 'Product deleted successfully.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
