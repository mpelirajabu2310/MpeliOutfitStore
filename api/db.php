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

// Start session with consistent configuration
$sessionLifetime = 86400; // 24 hours
$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
// Extend session lifetime on each request
if (session_status() === PHP_SESSION_ACTIVE) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

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
    // Clean any accidental output before sending JSON
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
