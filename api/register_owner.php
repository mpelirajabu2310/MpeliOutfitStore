<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (owner_exists($pdo)) {
    respond(['success' => false, 'message' => 'Owner account already exists. Ask the owner to create employee accounts.'], 403);
}

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
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        respond(['success' => false, 'message' => 'Username or email already exists.'], 409);
    }
    respond(['success' => false, 'message' => 'Failed to create owner account.'], 500);
}
