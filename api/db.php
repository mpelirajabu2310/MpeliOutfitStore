<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Global exception handler — prevent stack traces in production
set_exception_handler(static function (Throwable $e): void {
    error_log('[FATAL] Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.'], JSON_THROW_ON_ERROR);
    exit;
});

// Prevent PHP from showing errors in the response
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

// ─── Session Configuration ──────────────────────────────────────────────────
$sessionLifetime = 86400;
$idleTimeout = 180; // 3 minutes of inactivity (matches the client idle timer)
$warningDuration = 60; // 1 minute warning countdown before automatic logout
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

// Idle timeout: destroy the session if there has been no genuine user activity
// for $idleTimeout seconds.
//
// Background requests (chart refreshes, dashboard auto-updates, AJAX polling)
// send the "X-Background: 1" header and must NOT reset the idle timer, so they
// never keep an otherwise idle session alive.
$isBackgroundRequest = isset($_SERVER['HTTP_X_BACKGROUND']) && $_SERVER['HTTP_X_BACKGROUND'] === '1';

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
    $oldUserId = $_SESSION['user_id'] ?? null;
    session_unset();
    session_destroy();
    session_start();
    if ($oldUserId) {
        audit_log((int)$oldUserId, 'session_timeout', 'Session expired due to inactivity', 'warning', [
            'module' => 'auth',
            'description' => 'Session expired due to inactivity',
            'entity_type' => 'user',
            'entity_id' => (int)$oldUserId,
        ]);
    }
}

// Only genuine user requests (everything except background polling) extend the
// server-side idle window.
if (!$isBackgroundRequest) {
    $_SESSION['last_activity'] = time();
}

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
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src \'self\' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src \'self\' data:; connect-src \'self\';');
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
// Client IP detection strategy (secure by default):
//
// The audit trail records whatever IP is actually visible to the server. On
// plain shared hosting (e.g. this Namecheap/cPanel setup without a reverse
// proxy) REMOTE_ADDR is the true public/client IP seen by the web server, and
// forwarding headers like X-Forwarded-For can be *forged by any client*, so
// they are NOT trusted unless a trusted proxy/CDN is explicitly enabled.
//
// When the site is placed behind a trusted reverse proxy or CDN (for example
// Cloudflare, which sets CF-Connecting-IP / X-Forwarded-For), set the
// TRUSTED_PROXY=1 environment variable in cPanel (or .env) so those headers
// are honored. With TRUSTED_PROXY unset/0, the raw server-side address wins.
//
// Local development note: when requests come from the local machine
// (127.0.0.1/::1) there is no real proxy, but XAMPP always sees the loopback
// address, so for local testing only we fall back to the first valid
// X-Forwarded-For entry. This never affects production because production
// REMOTE_ADDR is a real public/client IP, never loopback.
function get_client_ip(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $trustedProxy = (getenv('TRUSTED_PROXY') ?: '0') === '1';
    $isLoopback = $remoteAddr === '127.0.0.1' || $remoteAddr === '::1';

    // 1) Explicitly configured trusted proxy / CDN (e.g. Cloudflare).
    if ($trustedProxy) {
        foreach (['CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $value = $_SERVER[$header] ?? '';
            if ($value === '') continue;
            $candidate = trim(explode(',', $value)[0]);
            $candidate = filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($candidate !== false) {
                // Strip IPv4-mapped IPv6 (::ffff:1.2.3.4) for readability.
                if (str_starts_with($candidate, '::ffff:')) $candidate = substr($candidate, 7);
                return $candidate;
            }
        }
    }

    // 2) Local/development fallback only — allows a browser dev setup where the
    //    load balancer or a local proxy (e.g. Apache) is the only hop.
    if ($isLoopback) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $firstIp = trim(explode(',', $xff)[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $firstIp;
            }
        }
    }

    // 3) Default and correct behavior: the actual address the server received.
    if ($remoteAddr !== '') {
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (str_starts_with($remoteAddr, '::ffff:')) return substr($remoteAddr, 7);
            return $remoteAddr;
        }
        // Loopback or NAT ranges are still valid client addresses in some
        // setups — record them rather than inventing something.
        return $remoteAddr;
    }

    return '0.0.0.0';
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
    $role = $_SESSION['user_role'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $logLine = "[$timestamp] [user:$userId] [role:$role] [ip:$ip] [method:$method] [uri:$uri] [$event] [$status] $details" . PHP_EOL;
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    @file_put_contents($logDir . '/activity.log', $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Write an entry to the audit_logs database table.
 * Called by the new audit_log() convenience function below.
 * Extracts authenticated user info server-side from the session/user lookup.
 */
function _write_audit_db(
    ?int    $userId,
    string $action,
    string $module,
    string $description = '',
    ?string $entityType = null,
    ?int    $entityId   = null,
    ?array  $oldValues  = null,
    ?array  $newValues  = null
): void {
    try {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        // Do not insert if the table doesn't exist yet
        static $tableExists = null;
        if ($tableExists === null) {
            $check = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
            $tableExists = $check && $check->rowCount() > 0;
        }
        if (!$tableExists) {
            return;
        }

        // Prefer the explicitly-passed user id; fall back to session when available
        $userId = $userId ?? ($_SESSION['user_id'] ?? 0);
        $userName = '';
        $userRole = $_SESSION['user_role'] ?? '';
        $ip       = get_client_ip();
        $agent    = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($userId > 0) {
            $uStmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = :id LIMIT 1');
            $uStmt->execute(['id' => $userId]);
            $uRow = $uStmt->fetch();
            if ($uRow) {
                $userName = $uRow['name'];
                $userRole = $uRow['role'];
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (user_id, user_name, user_role, action, module, description,
                 entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES
                (:user_id, :user_name, :user_role, :action, :module, :description,
                 :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)'
        );
        $stmt->execute([
            'user_id'     => $userId > 0 ? $userId : null,
            'user_name'   => $userName !== '' ? $userName : null,
            'user_role'   => $userRole !== '' ? $userRole : null,
            'action'      => $action,
            'module'      => $module,
            'description' => $description !== '' ? $description : null,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values'  => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => $ip,
            'user_agent'  => mb_substr($agent, 0, 512),
        ]);
    } catch (\Throwable $e) {
        error_log('[audit_db] write failed: ' . $e->getMessage());
    }
}

/**
 * Convenience: log an action to both the file-based activity log AND
 * the database audit_logs table.
 *
 * The optional $auditParams array supports:
 *   'module'       => string (required)
 *   'description'  => string
 *   'entity_type'  => string
 *   'entity_id'    => int
 *   'old_values'   => array
 *   'new_values'   => array
 */
function audit_log(
    int    $userId,
    string $action,
    string $details = '',
    string $status  = 'success',
    array  $auditParams = []
): void {
    // 1. Always write to the file-based log (backwards compatible)
    log_activity($userId, $action, $details, $status);

    // 2. Also write to the database audit_logs table
    $module = $auditParams['module'] ?? _infer_module_from_action($action);
    _write_audit_db(
        userId:      $userId,
        action:      $action,
        module:      $module,
        description: $auditParams['description'] ?? $details,
        entityType:  $auditParams['entity_type'] ?? null,
        entityId:    $auditParams['entity_id'] ?? null,
        oldValues:   $auditParams['old_values'] ?? null,
        newValues:   $auditParams['new_values'] ?? null,
    );
}

/**
 * Infer the module from the action name prefix.
 */
function _infer_module_from_action(string $action): string
{
    $prefixes = [
        'product'     => 'products',
        'sale'        => 'sales',
        'expense'     => 'expenses',
        'promotion'   => 'promotions',
        'user'        => 'users',
        'login'       => 'auth',
        'logout'      => 'auth',
        'session'     => 'auth',
        'password'    => 'auth',
        'recovery'    => 'auth',
        'owner'       => 'auth',
        'report'      => 'reports',
        'settings'    => 'settings',
        'maintenance' => 'system',
        'backup'      => 'system',
        'inventory'   => 'inventory',
    ];
    foreach ($prefixes as $prefix => $module) {
        if (str_starts_with($action, $prefix)) {
            return $module;
        }
    }
    return 'system';
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

/**
 * Marks the current request as genuine user activity, refreshing the
 * server-side idle window. Used by the heartbeat endpoint when the client
 * detects real interaction (mouse, keyboard, touch, scroll).
 */
function touch_session_activity(): void
{
    $_SESSION['last_activity'] = time();
}

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, username, email, role, status
         FROM users
         WHERE id = :id AND status = \'active\'
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

function require_ownership(PDO $pdo, int $resourceUserId, ?array $user = null): array
{
    if ($user === null) {
        $user = require_login($pdo);
    }
    if ($user['role'] !== 'OWNER' && (int)$user['id'] !== $resourceUserId) {
        respond(['success' => false, 'message' => 'You can only access your own data.'], 403);
    }
    return $user;
}

function owner_exists(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'OWNER'")->fetchColumn() > 0;
}

function ensure_shop_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM shop_settings ORDER BY id LIMIT 1')->fetch();
    if ($row) {
        return $row;
    }

    $pdo->exec(
        'INSERT INTO shop_settings (shop_name, currency_code, low_stock_threshold)
         VALUES (\'Mpeli Outfit Store\', \'TSH\', 5)'
    );

    return $pdo->query('SELECT * FROM shop_settings ORDER BY id LIMIT 1')->fetch();
}

function low_stock_threshold(PDO $pdo): int
{
    $settings = ensure_shop_settings($pdo);
    return max(1, (int)($settings['low_stock_threshold'] ?? 5));
}

/**
 * Check and claim an idempotency key to prevent duplicate submissions.
 * Returns true if this is a new request (allowed to proceed).
 * Returns false if the key was already processed (caller should return cached response).
 * When returning false, the cached response is sent and the script exits.
 */
function check_idempotency(string $key): bool
{
    if ($key === '') {
        return true;
    }

    $key = 'idem_' . preg_replace('/[^a-f0-9\-]/i', '', $key);
    $now = time();
    $ttl = 120;

    if (!isset($_SESSION['_idempotency'])) {
        $_SESSION['_idempotency'] = [];
    }

    // Purge expired entries
    foreach ($_SESSION['_idempotency'] as $k => $entry) {
        if ($entry['ts'] < $now - $ttl) {
            unset($_SESSION['_idempotency'][$k]);
        }
    }

    if (isset($_SESSION['_idempotency'][$key])) {
        $cached = $_SESSION['_idempotency'][$key];
        http_response_code($cached['status']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($cached['body'], JSON_THROW_ON_ERROR);
        exit;
    }

    $_SESSION['_idempotency'][$key] = ['ts' => $now];
    return true;
}

/**
 * Store the response for an idempotency key so duplicate requests return the same result.
 */
function store_idempotency_response(string $key, int $status, array $body): void
{
    if ($key === '') {
        return;
    }

    $key = 'idem_' . preg_replace('/[^a-f0-9\-]/i', '', $key);
    if (!isset($_SESSION['_idempotency'])) {
        $_SESSION['_idempotency'] = [];
    }
    $_SESSION['_idempotency'][$key] = [
        'ts'    => time(),
        'status'=> $status,
        'body'  => $body,
    ];
}
