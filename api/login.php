<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$data = read_json_body();
$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($username === '' || $password === '') {
    respond(['success' => false, 'message' => 'Username and password are required.'], 422);
}

// Input validation: username format
if (strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    respond(['success' => false, 'message' => 'Invalid username format.'], 422);
}

// IP-based rate limiting: 5 attempts per 5 minutes
if (!check_rate_limit('login', 5, 300)) {
    $ip = get_client_ip();
    log_activity(0, 'login_blocked', "IP: $ip — too many attempts", 'blocked');
    respond(['success' => false, 'message' => 'Too many login attempts. Try again in 5 minutes.'], 429);
}

$stmt = $pdo->prepare(
    'SELECT id, name, username, email, password_hash, role, status
     FROM users
     WHERE username = :username AND status = "active"
     LIMIT 1'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

// Use constant-time comparison for password hash verification
if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    log_activity($user['id'] ?? 0, 'login_failed', "Username: $username", 'failure');
    respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
}

// Reset rate limiter on success
reset_rate_limit('login');

// Maintenance mode: block non-OWNER logins
if ($user['role'] !== 'OWNER') {
    require_once __DIR__ . '/../services/SystemHealthService.php';
    $healthService = new SystemHealthService();
    if ($healthService->isMaintenanceMode()) {
        log_activity((int)$user['id'], 'login_blocked_maintenance', "Role: {$user['role']}");
        respond(['success' => false, 'message' => 'System is under maintenance. Please try again later.'], 503);
    }
}

// Set session data, then regenerate ID to prevent session fixation
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['last_activity'] = time();
$_SESSION['login_ip'] = get_client_ip();
session_regenerate_id(true);

log_activity((int)$user['id'], 'login_success', "Role: {$user['role']}");

$update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
$update->execute(['id' => $user['id']]);

unset($user['password_hash']);

respond([
    'success' => true,
    'message' => 'Login successful.',
    'user' => [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'status' => $user['status'],
    ],
], 200);
