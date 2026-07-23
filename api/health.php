<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SystemHealthService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $healthService = new SystemHealthService();
    $result = $healthService->runFullStartupCheck();

    http_response_code($result['healthy'] ? 200 : 503);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[health] Error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'healthy' => false,
        'checks' => [[
            'id' => 'fatal',
            'label' => 'System Error',
            'severity' => 'critical',
            'detail' => 'A critical error occurred during health check.',
        ]],
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
    ], JSON_UNESCAPED_UNICODE);
}
