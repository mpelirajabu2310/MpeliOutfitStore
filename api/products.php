<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';
$threshold = low_stock_threshold($pdo);

require_once __DIR__ . '/../services/ProductService.php';
$productService = new ProductService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $search = trim((string)($_GET['search'] ?? ''));
        $products = $productService->getAllProducts($search !== '' ? $search : null, $threshold, $user['role']);
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
    $minPrice = (float)($data['minimum_allowed_selling_price'] ?? 0);
    $stock = max(0, (int)($data['stock_quantity'] ?? 0));

    if ($name === '') {
        respond(['success' => false, 'message' => 'Product name is required.'], 422);
    }
    if ($buying <= 0) {
        respond(['success' => false, 'message' => 'Buying price must be greater than 0.'], 422);
    }
    if ($selling <= $buying) {
        respond(['success' => false, 'message' => 'Selling price must be greater than buying price.'], 422);
    }
    if ($minPrice < $buying || $minPrice > $selling) {
        respond(['success' => false, 'message' => 'Minimum allowed selling price cannot be lower than buying price or higher than selling price.'], 422);
    }
    if ($minPrice <= 0) {
        $minPrice = $buying;
    }

    // Case-insensitive duplicate check
    $existing = $productService->findDuplicateByName($name);
    if ($existing) {
        $existingId = (int)$existing['id'];
        $newStock = (int)$existing['current_stock'] + $stock;
        try {
            $productService->updateDuplicateProduct($existingId, $buying, $selling, $minPrice, $newStock, $threshold);
            respond(['success' => true, 'message' => 'Product already exists. Stock updated successfully.', 'product_id' => $existingId, 'updated' => true], 200);
        } catch (Throwable $exception) {
            error_log('[products] update error: ' . $exception->getMessage());
            respond(['success' => false, 'message' => 'Failed to update existing product.'], 500);
        }
    }

    try {
        $result = $productService->addProduct($name, $buying, $selling, $minPrice, $stock, $user['id'], $threshold);
        respond(['success' => true, 'message' => 'Product created successfully.', 'product_id' => $result['product_id']], 201);
    } catch (Throwable $exception) {
        error_log('[products] create error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Failed to create product.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_role($pdo, ['OWNER']);
    $data = read_json_body();
    $productId = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $buying = (float)($data['buying_price'] ?? 0);
    $selling = (float)($data['selling_price'] ?? 0);
    $minPrice = (float)($data['minimum_allowed_selling_price'] ?? 0);
    $stock = isset($data['stock_quantity']) ? max(0, (int)$data['stock_quantity']) : null;

    if ($productId <= 0 || $name === '') {
        respond(['success' => false, 'message' => 'Product id and name are required.'], 422);
    }
    if ($buying <= 0) {
        respond(['success' => false, 'message' => 'Buying price must be greater than 0.'], 422);
    }
    if ($selling <= $buying) {
        respond(['success' => false, 'message' => 'Selling price must be greater than buying price.'], 422);
    }
    if ($minPrice < $buying || $minPrice > $selling) {
        respond(['success' => false, 'message' => 'Minimum allowed selling price cannot be lower than buying price or higher than selling price.'], 422);
    }
    if ($minPrice <= 0) {
        $minPrice = $buying;
    }

    try {
        $productService->updateProduct($productId, $name, $buying, $selling, $minPrice);
        if ($stock !== null) {
            $variantId = $productService->getFirstVariantId($productId);
            if ($variantId !== null) {
                $productService->updateVariantStock($variantId, $stock, $threshold);
            }
        }
        respond(['success' => true, 'message' => 'Product updated successfully.']);
    } catch (Throwable $exception) {
        error_log('[products] update error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Failed to update product.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_role($pdo, ['OWNER']);
    $data = read_json_body();
    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        respond(['success' => false, 'message' => 'Product id is required.'], 422);
    }
    $productService->deleteProduct($productId);
    respond(['success' => true, 'message' => 'Product deleted successfully.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
