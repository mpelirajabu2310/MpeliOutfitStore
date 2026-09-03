<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/PromotionService.php';
$promotionService = new PromotionService();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if ($user['role'] === 'OWNER') {
        PermissionService::requirePermission($user['role'], 'promotions.manage');
        try {
            if (($_GET['active'] ?? '') === '1') {
                respond(['success' => true, 'promotions' => $promotionService->getActivePromotionsForSeller()]);
            }
            respond(['success' => true, 'promotions' => $promotionService->listPromotions()]);
        } catch (Throwable $e) {
            error_log('[promotions] GET owner error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to load promotions.'], 500);
        }
    }

    PermissionService::requirePermission($user['role'], 'promotions.view');
    try {
        respond(['success' => true, 'promotions' => $promotionService->getActivePromotionsForSeller()]);
    } catch (Throwable $e) {
        error_log('[promotions] GET seller error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to load promotions.'], 500);
    }
}

PermissionService::requirePermission($user['role'], 'promotions.manage');
require_csrf();

$data = read_json_body();

if ($method === 'POST') {
    try {
        $promotionId = $promotionService->createPromotion((int)$user['id'], $data);
        audit_log((int)$user['id'], 'promotion_created', "Promotion ID: {$promotionId}", 'success', [
            'module' => 'promotions',
            'description' => "Promotion created: " . ($data['name'] ?? "ID {$promotionId}"),
            'entity_type' => 'promotion',
            'entity_id' => $promotionId,
            'new_values' => ['name' => $data['name'] ?? '', 'percentage' => $data['percentage'] ?? null],
        ]);
        respond(['success' => true, 'message' => 'Promotion created successfully.', 'promotion_id' => $promotionId], 201);
    } catch (Throwable $exception) {
        $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Failed to create promotion.';
        $statusCode = $exception instanceof RuntimeException ? 422 : 500;
        error_log('[promotions] create: ' . $exception->getMessage());
        respond(['success' => false, 'message' => $message], $statusCode);
    }
}

if ($method === 'PUT') {
    $promotionId = (int)($data['id'] ?? 0);
    if ($promotionId <= 0) {
        respond(['success' => false, 'message' => 'Promotion id is required.'], 422);
    }

    try {
        if (($data['action'] ?? '') === 'set_status') {
            $status = (string)($data['status'] ?? '');
            $promotionService->setStatus($promotionId, $status);
            audit_log((int)$user['id'], 'promotion_status_changed', "Promotion ID: {$promotionId}, Status: {$status}", 'success', [
                'module' => 'promotions',
                'description' => "Promotion status changed to {$status} (ID: {$promotionId})",
                'entity_type' => 'promotion',
                'entity_id' => $promotionId,
                'new_values' => ['status' => $status],
            ]);
        } else {
            $promotionService->updatePromotion($promotionId, $data);
            audit_log((int)$user['id'], 'promotion_updated', "Promotion ID: {$promotionId}", 'success', [
                'module' => 'promotions',
                'description' => "Promotion updated (ID: {$promotionId})",
                'entity_type' => 'promotion',
                'entity_id' => $promotionId,
                'new_values' => $data,
            ]);
        }
        respond(['success' => true, 'message' => 'Promotion updated successfully.']);
    } catch (Throwable $exception) {
        $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Failed to update promotion.';
        $statusCode = $exception instanceof RuntimeException ? 422 : 500;
        error_log('[promotions] update: ' . $exception->getMessage());
        respond(['success' => false, 'message' => $message], $statusCode);
    }
}

if ($method === 'DELETE') {
    $promotionId = (int)($data['id'] ?? 0);
    if ($promotionId <= 0) {
        respond(['success' => false, 'message' => 'Promotion id is required.'], 422);
    }
    try {
        $promotionService->deletePromotion($promotionId);
        audit_log((int)$user['id'], 'promotion_deleted', "Promotion ID: {$promotionId}", 'success', [
            'module' => 'promotions',
            'description' => "Promotion deleted (ID: {$promotionId})",
            'entity_type' => 'promotion',
            'entity_id' => $promotionId,
        ]);
        respond(['success' => true, 'message' => 'Promotion deleted successfully.']);
    } catch (Throwable $exception) {
        error_log('[promotions] delete: ' . $exception->getMessage());
        respond(['success' => false, 'message' => 'Failed to delete promotion.'], 500);
    }
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
