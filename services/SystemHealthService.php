<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class SystemHealthService
{
    private PDO $db;
    private array $checks = [];
    private bool $allPassed = true;

    private const REQUIRED_TABLES = [
        'users', 'products', 'sales', 'sale_items',
        'expenses', 'shop_settings', 'inventory_movements',
    ];

    private const REQUIRED_VIEWS = [
        'product_stock_summary',
    ];

    private const REQUIRED_COLUMNS = [
        'users' => ['id', 'name', 'username', 'email', 'password_hash', 'role', 'status'],
        'products' => ['id', 'product_name', 'buying_price', 'selling_price', 'minimum_allowed_selling_price'],
        'sales' => ['id', 'receipt_number', 'total_amount', 'total_profit', 'sold_by', 'payment_status', 'sale_date'],
        'sale_items' => ['id', 'sale_id', 'variant_id', 'quantity', 'buying_price', 'selling_price'],
        'expenses' => ['id', 'category', 'amount', 'expense_date', 'created_by'],
        'shop_settings' => ['id', 'shop_name', 'currency_code'],
    ];

    private const REQUIRED_DIRS = [
        'logs',
        'logs/ratelimit',
        'locales',
        'assets/images',
    ];

    private const REQUIRED_FILES = [
        'config/database.php',
        'api/db.php',
        'api/login.php',
        'api/me.php',
        'assets/js/script.js',
        'assets/css/styles.css',
        'locales/en.json',
    ];

    public function __construct()
    {
        $this->db = get_db();
    }

    // ─── Full Startup Check (Parts 20–25) ────────────────────────────────────

    public function runFullStartupCheck(): array
    {
        $this->checks = [];
        $this->allPassed = true;

        $this->checkDatabaseConnection();
        $this->checkRequiredTables();
        $this->checkRequiredColumns();
        $this->checkRequiredViews();
        $this->checkRequiredDirectories();
        $this->checkRequiredFiles();
        $this->checkSessionConfiguration();
        $this->checkMaintenanceMode();
        $this->checkPHPVersion();
        $this->checkLogWritable();
        $this->checkRateLimitDirWritable();

        $result = [
            'healthy' => $this->allPassed,
            'checks' => $this->checks,
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
        ];

        $this->writeDiagnosticLog($result);

        return $result;
    }

    // ─── Database Health (Part 20) ──────────────────────────────────────────

    public function checkDatabaseConnection(): bool
    {
        try {
            $stmt = $this->db->query('SELECT 1');
            $stmt->fetch();
            $this->addCheck('db_connection', 'Database Connection', 'ok', 'Connected to MySQL successfully.');
            return true;
        } catch (PDOException $e) {
            $this->addCheck('db_connection', 'Database Connection', 'critical', 'Cannot connect to database: ' . $e->getMessage());
            return false;
        }
    }

    public function checkRequiredTables(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            try {
                $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            } catch (PDOException $e) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            $this->addCheck('db_tables', 'Required Tables', 'ok', count(self::REQUIRED_TABLES) . ' tables verified.');
            return true;
        }

        $this->addCheck('db_tables', 'Required Tables', 'critical', 'Missing tables: ' . implode(', ', $missing));
        return false;
    }

    public function checkRequiredColumns(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($columns as $col) {
                    if (!in_array($col, $cols, true)) {
                        $missing[] = "{$table}.{$col}";
                    }
                }
            } catch (PDOException $e) {
                $missing[] = "{$table}.*";
            }
        }

        if (empty($missing)) {
            $this->addCheck('db_columns', 'Required Columns', 'ok', 'All required columns present.');
            return true;
        }

        $this->addCheck('db_columns', 'Required Columns', 'warning', 'Missing columns: ' . implode(', ', $missing));
        return false;
    }

    public function checkRequiredViews(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_VIEWS as $view) {
            try {
                $this->db->query("SELECT 1 FROM {$view} LIMIT 1");
            } catch (PDOException $e) {
                $missing[] = $view;
            }
        }

        if (empty($missing)) {
            $this->addCheck('db_views', 'Required Views', 'ok', count(self::REQUIRED_VIEWS) . ' views verified.');
            return true;
        }

        $this->addCheck('db_views', 'Required Views', 'warning', 'Missing views: ' . implode(', ', $missing));
        return false;
    }

    // ─── Configuration Validation (Part 21) ─────────────────────────────────

    public function checkPHPVersion(): bool
    {
        $required = '8.0';
        if (version_compare(PHP_VERSION, $required, '>=')) {
            $this->addCheck('php_version', 'PHP Version', 'ok', 'PHP ' . PHP_VERSION . ' (requires >= ' . $required . ')');
            return true;
        }

        $this->addCheck('php_version', 'PHP Version', 'critical', 'PHP ' . PHP_VERSION . ' is below minimum ' . $required);
        return false;
    }

    public function checkSessionConfiguration(): bool
    {
        $issues = [];
        $strictMode = @ini_get('session.use_strict_mode');
        if ($strictMode !== '1') {
            $issues[] = 'strict_mode not enabled (will be set at runtime by db.php)';
        }
        $useCookies = @ini_get('session.use_only_cookies');
        if ($useCookies !== '1') {
            $issues[] = 'use_only_cookies not enabled (will be set at runtime by db.php)';
        }

        if (empty($issues)) {
            $this->addCheck('session_config', 'Session Configuration', 'ok', 'Session security settings verified.');
            return true;
        }

        $this->addCheck('session_config', 'Session Configuration', 'ok', 'Session settings configured at runtime by db.php: ' . implode('; ', $issues));
        return true;
    }

    // ─── Health Monitor (Part 22) ───────────────────────────────────────────

    public function checkRequiredDirectories(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_DIRS as $dir) {
            $fullPath = dirname(__DIR__) . '/' . $dir;
            if (!is_dir($fullPath)) {
                $missing[] = $dir;
            }
        }

        if (empty($missing)) {
            $this->addCheck('dirs', 'Required Directories', 'ok', count(self::REQUIRED_DIRS) . ' directories verified.');
            return true;
        }

        $this->addCheck('dirs', 'Required Directories', 'warning', 'Missing directories: ' . implode(', ', $missing));
        return false;
    }

    public function checkRequiredFiles(): bool
    {
        $missing = [];
        foreach (self::REQUIRED_FILES as $file) {
            $fullPath = dirname(__DIR__) . '/' . $file;
            if (!is_file($fullPath)) {
                $missing[] = $file;
            }
        }

        if (empty($missing)) {
            $this->addCheck('files', 'Required Files', 'ok', count(self::REQUIRED_FILES) . ' files verified.');
            return true;
        }

        $this->addCheck('files', 'Required Files', 'critical', 'Missing files: ' . implode(', ', $missing));
        return false;
    }

    public function checkLogWritable(): bool
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            $this->addCheck('log_writable', 'Logs Directory', 'warning', 'logs/ directory does not exist.');
            return false;
        }
        if (!is_writable($logDir)) {
            $this->addCheck('log_writable', 'Logs Directory', 'warning', 'logs/ directory is not writable.');
            return false;
        }
        $this->addCheck('log_writable', 'Logs Directory', 'ok', 'logs/ is writable.');
        return true;
    }

    public function checkRateLimitDirWritable(): bool
    {
        $rlDir = dirname(__DIR__) . '/logs/ratelimit';
        if (!is_dir($rlDir)) {
            $this->addCheck('ratelimit_dir', 'Rate Limit Directory', 'warning', 'logs/ratelimit/ directory does not exist (will be created on first request).');
            return false;
        }
        if (!is_writable($rlDir)) {
            $this->addCheck('ratelimit_dir', 'Rate Limit Directory', 'warning', 'logs/ratelimit/ is not writable.');
            return false;
        }
        $this->addCheck('ratelimit_dir', 'Rate Limit Directory', 'ok', 'logs/ratelimit/ is writable.');
        return true;
    }

    // ─── Maintenance Mode (Part 23) ─────────────────────────────────────────

    public function checkMaintenanceMode(): bool
    {
        $flagFile = dirname(__DIR__) . '/logs/.maintenance';
        if (!is_file($flagFile)) {
            $this->addCheck('maintenance', 'Maintenance Mode', 'ok', 'System is in normal mode.');
            return false;
        }

        $maintenanceData = @json_decode(@file_get_contents($flagFile), true);
        $message = $maintenanceData['message'] ?? 'System is under maintenance.';
        $allowedRoles = $maintenanceData['allowed_roles'] ?? ['OWNER'];

        $this->addCheck('maintenance', 'Maintenance Mode', 'warning', $message);
        return true;
    }

    public function isMaintenanceMode(): bool
    {
        return is_file(dirname(__DIR__) . '/logs/.maintenance');
    }

    public function getMaintenanceInfo(): array
    {
        $flagFile = dirname(__DIR__) . '/logs/.maintenance';
        if (!is_file($flagFile)) {
            return ['active' => false];
        }
        $data = @json_decode(@file_get_contents($flagFile), true) ?: [];
        return [
            'active' => true,
            'message' => $data['message'] ?? 'System is under maintenance.',
            'allowed_roles' => $data['allowed_roles'] ?? ['OWNER'],
        ];
    }

    public function enableMaintenanceMode(string $message = 'System is under maintenance. Please try again later.'): void
    {
        $flagFile = dirname(__DIR__) . '/logs/.maintenance';
        $data = json_encode([
            'message' => $message,
            'allowed_roles' => ['OWNER'],
            'enabled_at' => date('Y-m-d H:i:s'),
        ]);
        @file_put_contents($flagFile, $data, LOCK_EX);
    }

    public function disableMaintenanceMode(): void
    {
        $flagFile = dirname(__DIR__) . '/logs/.maintenance';
        if (is_file($flagFile)) {
            @unlink($flagFile);
        }
    }

    // ─── Self-Diagnostic Report (Part 24) ───────────────────────────────────

    private function writeDiagnosticLog(array $result): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        $status = $result['healthy'] ? 'PASS' : 'FAIL';
        $summary = "[{$result['timestamp']}] [health_check] [$status] PHP {$result['php_version']}" . PHP_EOL;

        foreach ($result['checks'] as $check) {
            $severity = strtoupper($check['severity']);
            $summary .= "  [{$severity}] {$check['label']}: {$check['detail']}" . PHP_EOL;
        }

        @file_put_contents($logDir . '/health.log', $summary, FILE_APPEND | LOCK_EX);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function addCheck(string $id, string $label, string $severity, string $detail): void
    {
        $this->checks[] = [
            'id' => $id,
            'label' => $label,
            'severity' => $severity,
            'detail' => $detail,
        ];

        if ($severity === 'critical') {
            $this->allPassed = false;
        }
    }

    public function isHealthy(): bool
    {
        $result = $this->runFullStartupCheck();
        return $result['healthy'];
    }
}
