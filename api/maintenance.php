<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/../services/SystemHealthService.php';

$user = require_role($pdo, ['OWNER']);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $healthService = new SystemHealthService();
    $info = $healthService->getMaintenanceInfo();
    respond(['success' => true, 'maintenance' => $info]);
}

if ($method === 'POST' || $method === 'PUT') {
    require_csrf();
    $data = read_json_body();
    $enable = $data['enable'] ?? false;
    $message = trim((string)($data['message'] ?? 'System is under maintenance. Please try again later.'));

    if (strlen($message) > 500) {
        respond(['success' => false, 'message' => 'Maintenance message too long (max 500 characters).'], 422);
    }

    $healthService = new SystemHealthService();
    if ($enable) {
        $healthService->enableMaintenanceMode($message);
    } else {
        $healthService->disableMaintenanceMode();
    }

    log_activity((int)$user['id'], 'maintenance_' . ($enable ? 'enabled' : 'disabled'), "Message: $message");
    respond(['success' => true, 'maintenance' => $healthService->getMaintenanceInfo()]);
}

if ($method === 'DELETE') {
    require_csrf();
    $healthService = new SystemHealthService();
    $healthService->disableMaintenanceMode();
    log_activity((int)$user['id'], 'maintenance_disabled', 'Disabled via DELETE');
    respond(['success' => true, 'maintenance' => $healthService->getMaintenanceInfo()]);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
