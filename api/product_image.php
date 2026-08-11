<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/ProductService.php';
require_once __DIR__ . '/../services/ImageService.php';

PermissionService::requirePermission($user['role'], 'products.update');

$productService = new ProductService();
$imageService = new ImageService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
    $data = $isMultipart ? $_POST : read_json_body();
    $productId = (int)($data['product_id'] ?? 0);

    if ($productId <= 0) {
        respond(['success' => false, 'message' => 'Product id is required.'], 422);
    }
    if (!$productService->getProductById($productId)) {
        respond(['success' => false, 'message' => 'Product not found.'], 404);
    }

    $removeImage = $isMultipart
        ? (($data['remove_image'] ?? '') === '1')
        : (($data['remove_image'] ?? false) === true || ($data['action'] ?? '') === 'remove');

    if ($removeImage) {
        $oldPath = $productService->getProductImage($productId);
        $productService->setProductImage($productId, null);
        $imageService->removeImageFile($oldPath);
        log_activity((int)$user['id'], 'product_image_removed', "Product ID: {$productId}");
        respond(['success' => true, 'message' => 'Product image removed.']);
    }

    if (!$isMultipart || !isset($_FILES['product_image']) || (int)($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respond(['success' => false, 'message' => 'No image file was provided.'], 422);
    }

    try {
        $newPath = $imageService->processUploadedImage($_FILES['product_image'], $productId);
    } catch (RuntimeException $exception) {
        respond(['success' => false, 'message' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        error_log('[product_image] process error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Unable to process image.'], 500);
    }

    $oldPath = $productService->getProductImage($productId);
    $productService->setProductImage($productId, $newPath);
    $imageService->removeImageFile($oldPath);

    log_activity((int)$user['id'], 'product_image_updated', "Product ID: {$productId}, Image: {$newPath}");
    respond(['success' => true, 'message' => 'Product image updated.', 'image_path' => $newPath]);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
