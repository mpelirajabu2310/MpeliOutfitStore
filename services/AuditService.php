<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class AuditService extends BaseService
{
    /**
     * Insert an audit log record into the database.
     * All parameters come from the server side — never trust client input.
     */
    public function log(
        int    $userId,
        string $userName,
        string $userRole,
        string $action,
        string $module,
        string $description = '',
        ?string $entityType = null,
        ?int    $entityId   = null,
        ?array  $oldValues  = null,
        ?array  $newValues  = null,
        ?string $ipAddress  = null,
        ?string $userAgent  = null
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_logs
                    (user_id, user_name, user_role, action, module, description,
                     entity_type, entity_id, old_values, new_values, ip_address, user_agent)
                 VALUES
                    (:user_id, :user_name, :user_role, :action, :module, :description,
                     :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)'
            );
            $stmt->execute([
                'user_id'     => $userId,
                'user_name'   => $userName,
                'user_role'   => $userRole,
                'action'      => $action,
                'module'      => $module,
                'description' => $description !== '' ? $description : null,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                'new_values'  => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $ipAddress,
                'user_agent'  => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break the main operation
            error_log('[AuditService] log failed: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a paginated, filtered list of audit log entries.
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            // PDO native prepares (EMULATE_PREPARES=false) cannot reuse a named
            // placeholder multiple times, so bind the search term once per column.
            $search = '%' . $filters['search'] . '%';
            $searchTerms = [];
            foreach (['description', 'user_name', 'action', 'module'] as $i => $col) {
                $key = 'search' . $i;
                $searchTerms[] = "al.{$col} LIKE :{$key}";
                $params[$key] = $search;
            }
            $where[] = '(' . implode(' OR ', $searchTerms) . ')';
        }
        if (!empty($filters['id'])) {
            $where[] = 'al.id = :id';
            $params['id'] = (int)$filters['id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params['user_id'] = (int)$filters['user_id'];
        }
        if (!empty($filters['role'])) {
            $where[] = 'al.user_role = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['module'])) {
            $where[] = 'al.module = :module';
            $params['module'] = $filters['module'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs al {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch page — select only the columns needed for the list view.
        // Heavy fields (old_values / new_values JSON, full user_agent) are
        // fetched exclusively by getLogById() when the admin clicks "View",
        // keeping list responses small and fast.
        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT al.id, al.user_id, al.user_name, al.user_role, al.action,
                       al.module, al.description, al.entity_type, al.entity_id,
                       al.ip_address, al.created_at
                FROM audit_logs al
                {$whereSql}
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $logs = $stmt->fetchAll();

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Retrieve a single audit log entry by its primary key.
     *
     * Uses a prepared statement to prevent SQL injection and never trusts the
     * caller-supplied id for authorization — authorization is enforced by the
     * controller before this is called. Returns null when the record does not
     * exist so the controller can respond with "not found" instead of leaking
     * whether a record exists or not.
     */
    public function getLogById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $log = $stmt->fetch();
        if (!$log) {
            return null;
        }

        $log['old_values'] = $log['old_values'] ? json_decode($log['old_values'], true) : null;
        $log['new_values'] = $log['new_values'] ? json_decode($log['new_values'], true) : null;
        $log['entity_reference'] = $this->resolveEntityReference(
            (string)($log['entity_type'] ?? ''),
            isset($log['entity_id']) && $log['entity_id'] !== null ? (int)$log['entity_id'] : null
        );
        return $log;
    }

    /**
     * Resolve a human-readable name/reference for the affected entity where a
     * known, matching record exists. Best-effort only: any lookup failure
     * returns null so the audit detail view can render "Not available" instead
     * of ever breaking the page or leaking errors.
     */
    private function resolveEntityReference(string $entityType, ?int $entityId): ?string
    {
        if ($entityId === null || $entityType === '') {
            return null;
        }

        $lookups = [
            'product'    => ['products',    'id', 'product_name'],
            'products'   => ['products',    'id', 'product_name'],
            'sale'       => ['sales',       'id', 'receipt_number'],
            'sales'      => ['sales',       'id', 'receipt_number'],
            'expense'    => ['expenses',    'id', 'expense_name'],
            'expenses'   => ['expenses',    'id', 'expense_name'],
            'user'       => ['users',       'id', 'name'],
            'users'      => ['users',       'id', 'name'],
            'customer'   => ['customers',   'id', 'full_name'],
            'customers'  => ['customers',   'id', 'full_name'],
        ];

        if (!isset($lookups[$entityType])) {
            return null;
        }
        [$table, $idColumn, $labelColumn] = $lookups[$entityType];

        try {
            $stmt = $this->db->prepare(
                "SELECT {$labelColumn} AS label FROM {$table} WHERE {$idColumn} = :id LIMIT 1"
            );
            $stmt->execute(['id' => $entityId]);
            $row = $stmt->fetch();
            if (!$row || $row['label'] === null || trim((string)$row['label']) === '') {
                return null;
            }
            return (string)$row['label'];
        } catch (\Throwable $e) {
            error_log('[AuditService] entity reference lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get distinct user names for the filter dropdown.
     */
    public function getDistinctUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT user_id, user_name
             FROM audit_logs
             WHERE user_id IS NOT NULL
             ORDER BY user_name ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Get distinct modules for the filter dropdown.
     */
    public function getDistinctModules(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT module
             FROM audit_logs
             ORDER BY module ASC'
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get distinct actions for the filter dropdown.
     */
    public function getDistinctActions(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT action
             FROM audit_logs
             ORDER BY action ASC'
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get distinct entity types for the filter dropdown.
     */
    public function getDistinctEntityTypes(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT entity_type
             FROM audit_logs
             WHERE entity_type IS NOT NULL
             ORDER BY entity_type ASC'
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Check if the audit_logs table exists.
     */
    public function tableExists(): bool
    {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_logs'");
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
