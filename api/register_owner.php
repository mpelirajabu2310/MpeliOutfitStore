<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (owner_exists($pdo)) {
    respond(['success' => false, 'message' => 'Owner account already exists. Ask the owner to create employee accounts.'], 403);
}

// Rate limiting
$attempts = (int)($_SESSION['register_attempts'] ?? 0);
$lastAttempt = (int)($_SESSION['register_last_attempt'] ?? 0);
if ($attempts >= 3 && (time() - $lastAttempt) < 300) {
    respond(['success' => false, 'message' => 'Too many registration attempts. Try again in 5 minutes.'], 429);
}
$_SESSION['register_attempts'] = $attempts + 1;
$_SESSION['register_last_attempt'] = time();

$data = read_json_body();
$name = trim((string)($data['name'] ?? ''));
$username = trim((string)($data['username'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($name === '' || $username === '' || $password === '') {
    respond(['success' => false, 'message' => 'Name, username, and password are required.'], 422);
}

if (strlen($password) < 8) {
    respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
}

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

    respond(['success' => true, 'message' => 'Owner account created. You can now log in.'], 201);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        respond(['success' => false, 'message' => 'Username or email already exists.'], 409);
    }
    error_log('[register] error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to create owner account.'], 500);
}
