<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$attempts = (int)($_SESSION['reset_attempts'] ?? 0);
$lastAttempt = (int)($_SESSION['reset_last_attempt'] ?? 0);
if ($attempts >= 5 && (time() - $lastAttempt) < 300) {
    respond(['success' => false, 'message' => 'Too many reset attempts. Try again in 5 minutes.'], 429);
}
$_SESSION['reset_attempts'] = $attempts + 1;
$_SESSION['reset_last_attempt'] = time();

$data = read_json_body();
$username = trim((string)($data['username'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($username === '' || $email === '' || $password === '') {
    respond(['success' => false, 'message' => 'Username, email, and password are required.'], 422);
}
if (strlen($username) > 50) {
    respond(['success' => false, 'message' => 'Invalid username.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'message' => 'Invalid email format.'], 422);
}
if (strlen($password) < 8) {
    respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
}

$stmt = $pdo->prepare(
    'SELECT id, email FROM users WHERE username = :username AND status = "active" LIMIT 1'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user || strtolower((string)$user['email']) !== strtolower($email)) {
    respond(['success' => false, 'message' => 'Username and email do not match our records.'], 422);
}

$update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
$update->execute([
    'hash' => password_hash($password, PASSWORD_DEFAULT),
    'id' => $user['id'],
]);

unset($_SESSION['reset_attempts'], $_SESSION['reset_last_attempt']);
unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

respond([
    'success' => true,
    'message' => 'Password reset successfully. You can now log in.',
    'username' => $username,
]);
