<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/PromotionService.php';
require_once __DIR__ . '/../services/ImageService.php';

PermissionService::requirePermission($user['role'], 'promotions.manage');

$promotionService = new PromotionService();

$promotionImageConfig = [
    'allowed_extensions' => ['jpg', 'jpeg', 'png'],
    'allowed_mimes' => ['image/jpeg', 'image/png'],
    'max_file_size_bytes' => 2 * 1024 * 1024,
    'max_dimension' => 1000,
    'output_format' => function_exists('imagewebp') ? 'webp' : 'jpg',
    'webp_quality' => 82,
    'jpeg_quality' => 85,
    'upload_dir' => __DIR__ . '/../uploads/promotions',
    'upload_url_base' => 'uploads/promotions',
];
$imageService = new ImageService($promotionImageConfig);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
    $data = $isMultipart ? $_POST : read_json_body();
    $promotionId = (int)($data['promotion_id'] ?? 0);

    if ($promotionId <= 0) {
        respond(['success' => false, 'message' => 'Promotion id is required.'], 422);
    }
    if (!$promotionService->getPromotion($promotionId)) {
        respond(['success' => false, 'message' => 'Promotion not found.'], 404);
    }

    $removeImage = $isMultipart
        ? (($data['remove_image'] ?? '') === '1')
        : (($data['remove_image'] ?? false) === true || ($data['action'] ?? '') === 'remove');

    if ($removeImage) {
        $oldPath = $promotionService->getPromotionImage($promotionId);
        $promotionService->setPromotionImage($promotionId, null);
        $imageService->removeImageFile($oldPath);
        log_activity((int)$user['id'], 'promotion_image_removed', "Promotion ID: {$promotionId}");
        respond(['success' => true, 'message' => 'Promotion image removed.']);
    }

    if (!$isMultipart || !isset($_FILES['promotion_image']) || (int)($_FILES['promotion_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respond(['success' => false, 'message' => 'No image file was provided.'], 422);
    }

    try {
        $newPath = $imageService->processUploadedImage($_FILES['promotion_image'], $promotionId);
    } catch (RuntimeException $exception) {
        respond(['success' => false, 'message' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        error_log('[promotion_image] process error: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Unable to process image.'], 500);
    }

    $oldPath = $promotionService->getPromotionImage($promotionId);
    $promotionService->setPromotionImage($promotionId, $newPath);
    $imageService->removeImageFile($oldPath);

    log_activity((int)$user['id'], 'promotion_image_updated', "Promotion ID: {$promotionId}, Image: {$newPath}");
    respond(['success' => true, 'message' => 'Promotion image updated.', 'image_path' => $newPath]);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
