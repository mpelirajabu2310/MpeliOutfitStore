<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../services/SystemHealthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $healthService = new SystemHealthService();
    $health = $healthService->runFullStartupCheck();

    if (!$health['healthy']) {
        respond([
            'success' => false,
            'healthy' => false,
            'message' => 'System health check failed. The application cannot start.',
            'checks' => $health['checks'],
        ], 503);
    }

    $maintenance = $healthService->getMaintenanceInfo();

    $user = current_user($pdo);

    if (!$user && !empty($_SESSION['user_id'])) {
        unset($_SESSION['user_id']);
    }

    $hasOwner = owner_exists($pdo);

    respond([
        'success' => true,
        'healthy' => true,
        'maintenance' => $maintenance,
        'authenticated' => $user !== null,
        'owner_exists' => $hasOwner,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    error_log('[me] Error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to check authentication.'], 500);
}
