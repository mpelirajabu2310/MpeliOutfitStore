<?php
declare(strict_types=1);

// Suppress output of PHP notices/warnings that could break JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Global exception handler: ensures uncaught exceptions always return valid JSON
set_exception_handler(function (Throwable $e) {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Internal server error.'], JSON_UNESCAPED_UNICODE);
    exit;
});

// ─── Session Configuration ──────────────────────────────────────────────────
$sessionLifetime = 86400;
$idleTimeout = 900; // 15 minutes of inactivity
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($isSecure) {
    ini_set('session.cookie_secure', '1');
}

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Idle timeout: destroy session if no activity for $idleTimeout seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
    $oldUserId = $_SESSION['user_id'] ?? null;
    session_unset();
    session_destroy();
    session_start();
    if ($oldUserId) {
        log_activity((int)$oldUserId, 'session_timeout', 'Session expired due to inactivity');
    }
}
$_SESSION['last_activity'] = time();

// ─── Security Headers ───────────────────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\'; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src \'self\' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src \'self\' data:; connect-src \'self\';');
}

require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

// ─── CSRF Token Helpers ─────────────────────────────────────────────────────
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    // Refresh token every 30 minutes
    if ((time() - $_SESSION['csrf_token_time']) > 1800) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    // Token expires after 1 hour
    if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 3600) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token($token)) {
        respond(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page.'], 403);
    }
}

// ─── IP-Based Rate Limiting (file-backed) ───────────────────────────────────
function get_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($ip === '127.0.0.1' || $ip === '::1') {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $firstIp = trim(explode(',', $xff)[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                return $firstIp;
            }
        }
    }
    return $ip;
}

function _rate_limit_dir(): string
{
    $dir = __DIR__ . '/../logs/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function check_rate_limit(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $ip = get_client_ip();
    $file = _rate_limit_dir() . '/' . $key . '_' . md5($ip) . '.json';

    $data = ['attempts' => 0, 'window_start' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    // Reset window if expired
    if ($data['window_start'] === 0 || (time() - $data['window_start']) > $windowSeconds) {
        $data = ['attempts' => 1, 'window_start' => time()];
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    if ($data['attempts'] >= $maxAttempts) {
        return false;
    }

    $data['attempts']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function reset_rate_limit(string $key): void
{
    $ip = get_client_ip();
    $file = _rate_limit_dir() . '/' . $key . '_' . md5($ip) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

// ─── Activity Logging ───────────────────────────────────────────────────────
function log_activity(int $userId, string $event, string $details = '', string $status = 'success'): void
{
    $ip = get_client_ip();
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [user:$userId] [ip:$ip] [$event] [$status] $details" . PHP_EOL;
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    @file_put_contents($logDir . '/activity.log', $logLine, FILE_APPEND | LOCK_EX);
}

// ─── Core Auth Helpers ──────────────────────────────────────────────────────
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(array $payload, int $status = 200): void
{
    // Attach fresh CSRF token to every response if session is active
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
        $payload['csrf_token'] = generate_csrf_token();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, username, email, role, status
         FROM users
         WHERE id = :id AND status = "active"
         LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(PDO $pdo): array
{
    $user = current_user($pdo);
    if (!$user) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }

    return $user;
}

function require_role(PDO $pdo, array $roles): array
{
    $user = require_login($pdo);
    if (!in_array($user['role'], $roles, true)) {
        respond(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }

    return $user;
}

function owner_exists(PDO $pdo): bool
{
    return (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "OWNER"')->fetchColumn() > 0;
}

// Auto-migration: add pricing columns if missing
try {
    $pdo->exec('ALTER TABLE products ADD COLUMN minimum_allowed_selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER selling_price');
} catch (PDOException $e) {
    // Column already exists — ignore
}
try {
    $pdo->exec('ALTER TABLE sale_items ADD COLUMN original_selling_price DECIMAL(12,2) DEFAULT NULL AFTER selling_price');
} catch (PDOException $e) {
    // ignore
}
try {
    $pdo->exec('ALTER TABLE sale_items ADD COLUMN discount_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER original_selling_price');
} catch (PDOException $e) {
    // ignore
}

// Auto-migration: ensure expenses table has correct schema (runs once per schema version)
try {
    // Check if expenses table exists
    $st = $pdo->query('SELECT 1 FROM expenses LIMIT 1');
    $tableExists = true;
} catch (PDOException $e) {
    $tableExists = false;
}
$createTableSQL = 'CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    expense_name VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
if ($tableExists) {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM expenses')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('title', $cols, true)) {
            // Old schema detected: back up data and recreate
            $pdo->exec('RENAME TABLE expenses TO expenses_backup');
            $pdo->exec($createTableSQL);
        }
    } catch (PDOException $e) {
        // ignore
    }
} else {
    try {
        $pdo->exec($createTableSQL);
    } catch (PDOException $e) {
        // ignore
    }
}
// Clean up legacy expense_categories table (safe no-op if absent)
try {
    $pdo->exec('DROP TABLE IF EXISTS expense_categories');
} catch (PDOException $e) {
    // ignore
}

function ensure_shop_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM shop_settings ORDER BY id LIMIT 1')->fetch();
    if ($row) {
        return $row;
    }

    $pdo->exec(
        'INSERT INTO shop_settings (shop_name, currency_code, low_stock_threshold)
         VALUES ("Mpeli Outfit Store", "TSH", 5)'
    );

    return $pdo->query('SELECT * FROM shop_settings ORDER BY id LIMIT 1')->fetch();
}

function low_stock_threshold(PDO $pdo): int
{
    $settings = ensure_shop_settings($pdo);
    return max(1, (int)($settings['low_stock_threshold'] ?? 5));
}
