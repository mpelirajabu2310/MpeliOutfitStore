<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (owner_exists($pdo)) {
    respond(['success' => false, 'message' => 'Owner account already exists. Ask the owner to create employee accounts.'], 403);
}

// CSRF not required for first-owner registration (no session auth yet)

// IP-based rate limiting: 3 attempts per 5 minutes
if (!check_rate_limit('register', 3, 300)) {
    $ip = get_client_ip();
    log_activity(0, 'register_blocked', "IP: $ip — too many attempts", 'blocked');
    respond(['success' => false, 'message' => 'Too many registration attempts. Try again in 5 minutes.'], 429);
}

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
$username = trim((string)($data['username'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($name === '' || $username === '' || $password === '') {
    respond(['success' => false, 'message' => 'Name, username, and password are required.'], 422);
}

if (strlen($name) > 100) {
    respond(['success' => false, 'message' => 'Name must be 100 characters or fewer.'], 422);
}
if (strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    respond(['success' => false, 'message' => 'Username must be 50 characters or fewer and contain only letters, numbers, and underscores.'], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'message' => 'Invalid email format.'], 422);
}
if (strlen($email) > 100) {
    respond(['success' => false, 'message' => 'Email must be 100 characters or fewer.'], 422);
}

if (strlen($password) < 8) {
    respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
}

// Password strength: require at least one letter and one number
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    respond(['success' => false, 'message' => 'Password must contain at least one letter and one number.'], 422);
}

reset_rate_limit('register');

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password_hash, role, status)
         VALUES (:name, :username, :email, :password_hash, "OWNER", "active")'
    );
    $stmt->execute([
        'name' => $name,
        'username' => $username,
        'email' => $email !== '' ? $email : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $newUserId = (int)$pdo->lastInsertId();
    log_activity($newUserId, 'owner_registered', "Username: $username");

    respond(['success' => true, 'message' => 'Owner account created. You can now log in.'], 201);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        respond(['success' => false, 'message' => 'Username or email already exists.'], 409);
    }
    error_log('[register] error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to create owner account.'], 500);
}
