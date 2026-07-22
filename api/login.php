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

// Rate limiting: prevent brute-force attacks
$attempts = (int)($_SESSION['login_attempts'] ?? 0);
$lastAttempt = (int)($_SESSION['login_last_attempt'] ?? 0);
if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
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

if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['login_last_attempt'] = time();
    respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
}

// Reset rate limiter on success
unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

// Set session data first, THEN regenerate ID to ensure data is preserved
$_SESSION['user_id'] = (int)$user['id'];
session_regenerate_id(true);
error_log('[login] User (ID:' . $user['id'] . ') logged in. Session: ' . session_id());

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
