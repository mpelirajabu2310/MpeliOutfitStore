<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class ActivityLogService extends BaseService
{
    public function log(int $userId, string $role, string $action, string $module, ?int $recordId = null, ?string $details = null): void
    {
        // Future use: insert into an activity_logs table
        // For now, log to error_log for visibility
        error_log("[activity] User #{$userId} ({$role}) {$action} {$module}" . ($recordId ? " #{$recordId}" : "") . ($details ? " — {$details}" : ""));
    }

    public function getLogs(?int $userId = null, int $limit = 50): array
    {
        // Future use: query activity_logs table
        return [];
    }
}
