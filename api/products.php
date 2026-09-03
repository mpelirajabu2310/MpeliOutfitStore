<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';
$threshold = low_stock_threshold($pdo);

require_once __DIR__ . '/../services/ProductService.php';
require_once __DIR__ . '/../services/PermissionService.php';
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
    PermissionService::requirePermission($user['role'], 'products.create');
    require_csrf();

    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
    $data = $isMultipart ? $_POST : read_json_body();
    $imageFile = null;
    if ($isMultipart && isset($_FILES['product_image']) && is_array($_FILES['product_image']) && (int)($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imageFile = $_FILES['product_image'];
    }

    $name = trim((string)($data['name'] ?? ''));
    $buying = (float)($data['buying_price'] ?? 0);
    $selling = (float)($data['selling_price'] ?? 0);
    $minPrice = (float)($data['minimum_allowed_selling_price'] ?? 0);
    $stock = max(0, (int)($data['stock_quantity'] ?? 0));

    if ($name === '') {
        respond(['success' => false, 'message' => 'Product name is required.'], 422);
    }
    if (strlen($name) > 255) {
        respond(['success' => false, 'message' => 'Product name must be 255 characters or fewer.'], 422);
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
            audit_log((int)$user['id'], 'product_stock_updated', "Product: {$name} (duplicate merge), Stock: {$newStock}", 'success', [
                'module' => 'products',
                'description' => "Duplicate product merged: {$name}, stock updated to {$newStock}",
                'entity_type' => 'product',
                'entity_id' => $existingId,
                'new_values' => ['buying_price' => $buying, 'selling_price' => $selling, 'stock_quantity' => $newStock],
            ]);
            respond(['success' => true, 'message' => 'Product already exists. Stock updated successfully.', 'product_id' => $existingId, 'updated' => true], 200);
        } catch (Throwable $exception) {
            error_log('[products] update error: ' . $exception->getMessage());
            respond(['success' => false, 'message' => 'Failed to update existing product.'], 500);
        }
    }

    try {
        $result = $productService->addProduct($name, $buying, $selling, $minPrice, $stock, $user['id'], $threshold);

        $imageError = null;
        if ($imageFile !== null) {
            try {
                require_once __DIR__ . '/../services/ImageService.php';
                $imageService = new ImageService();
                $imagePath = $imageService->processUploadedImage($imageFile, (int)$result['product_id']);
                $productService->setProductImage((int)$result['product_id'], $imagePath);
            } catch (RuntimeException $exception) {
                $imageError = $exception->getMessage();
                error_log('[products] image error: ' . $exception->getMessage());
            }
        }

        $payload = ['success' => true, 'message' => 'Product created successfully.', 'product_id' => $result['product_id']];
        if ($imageError !== null) {
            $payload['image_error'] = $imageError;
        }
        audit_log((int)$user['id'], 'product_created', "Product: {$name}, ID: {$result['product_id']}", 'success', [
            'module' => 'products',
            'description' => "Product created: {$name}",
            'entity_type' => 'product',
            'entity_id' => (int)$result['product_id'],
            'new_values' => [
                'product_name' => $name,
                'buying_price' => $buying,
                'selling_price' => $selling,
                'minimum_allowed_selling_price' => $minPrice,
                'stock_quantity' => $stock,
            ],
        ]);
        respond($payload, 201);
    } catch (Throwable $exception) {
        error_log('[products] create error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Failed to create product.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    PermissionService::requirePermission($user['role'], 'products.update');
    require_csrf();
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
    if (strlen($name) > 255) {
        respond(['success' => false, 'message' => 'Product name must be 255 characters or fewer.'], 422);
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
        $oldProduct = $productService->getProductById($productId);
        $productService->updateProduct($productId, $name, $buying, $selling, $minPrice);
        if ($stock !== null) {
            $variantId = $productService->getFirstVariantId($productId);
            if ($variantId !== null) {
                $productService->updateVariantStock($variantId, $stock, $threshold);
            }
        }
        $oldValues = $oldProduct ? [
            'product_name' => $oldProduct['product_name'] ?? $oldProduct['name'] ?? '',
            'buying_price' => (float)($oldProduct['buying_price'] ?? 0),
            'selling_price' => (float)($oldProduct['selling_price'] ?? 0),
            'minimum_allowed_selling_price' => (float)($oldProduct['minimum_allowed_selling_price'] ?? 0),
        ] : null;
        audit_log((int)$user['id'], 'product_updated', "Product ID: {$productId}, Name: {$name}", 'success', [
            'module' => 'products',
            'description' => "Product updated: {$name}",
            'entity_type' => 'product',
            'entity_id' => $productId,
            'old_values' => $oldValues,
            'new_values' => [
                'product_name' => $name,
                'buying_price' => $buying,
                'selling_price' => $selling,
                'minimum_allowed_selling_price' => $minPrice,
            ],
        ]);
        respond(['success' => true, 'message' => 'Product updated successfully.']);
    } catch (Throwable $exception) {
        error_log('[products] update error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Failed to update product.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    PermissionService::requirePermission($user['role'], 'products.delete');
    require_csrf();
    $data = read_json_body();
    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        respond(['success' => false, 'message' => 'Product id is required.'], 422);
    }
    $productService->deleteProduct($productId);
    audit_log((int)$user['id'], 'product_deleted', "Product ID: {$productId}", 'success', [
        'module' => 'products',
        'description' => "Product deleted (ID: {$productId})",
        'entity_type' => 'product',
        'entity_id' => $productId,
    ]);
    respond(['success' => true, 'message' => 'Product deleted successfully.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
