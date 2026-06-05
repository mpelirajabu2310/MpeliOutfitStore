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
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => false,
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
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$host = '127.0.0.1';
$database = 'clothing_shop_management';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    error_log('[db] Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.',
        'detail' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

function general_category_id(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => 'General']);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    $insert = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
    $insert->execute(['name' => 'General']);

    return (int)$pdo->lastInsertId();
}
