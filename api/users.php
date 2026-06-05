<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$owner = require_role($pdo, ['OWNER']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, name, username, email, role, status, created_at
         FROM users
         ORDER BY created_at DESC'
    );
    respond(['success' => true, 'users' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $name = trim((string)($data['name'] ?? ''));
    $username = trim((string)($data['username'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $role = strtoupper((string)($data['role'] ?? 'SELLER'));

    if ($name === '' || $username === '' || $password === '') {
        respond(['success' => false, 'message' => 'Name, username, and password are required.'], 422);
    }
    if (!in_array($role, ['OWNER', 'SELLER'], true)) {
        respond(['success' => false, 'message' => 'Invalid role.'], 422);
    }
    if (strlen($password) < 8) {
        respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash, role, status)
             VALUES (:name, :username, :email, :password_hash, :role, "active")'
        );
        $stmt->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        respond(['success' => true, 'message' => 'User created successfully.'], 201);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            respond(['success' => false, 'message' => 'Username or email already exists.'], 409);
        }
        respond(['success' => false, 'message' => 'Failed to create user.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = read_json_body();
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $role = strtoupper((string)($data['role'] ?? 'SELLER'));
    $status = strtolower((string)($data['status'] ?? 'active'));
    $password = (string)($data['password'] ?? '');

    if ($id <= 0 || $name === '') {
        respond(['success' => false, 'message' => 'User id and name are required.'], 422);
    }
    if (!in_array($role, ['OWNER', 'SELLER'], true) || !in_array($status, ['active', 'inactive'], true)) {
        respond(['success' => false, 'message' => 'Invalid role or status.'], 422);
    }

    $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $targetStmt->execute(['id' => $id]);
    $target = $targetStmt->fetch();
    if (!$target) {
        respond(['success' => false, 'message' => 'User not found.'], 404);
    }

    if ((int)$target['id'] === (int)$owner['id'] && $status === 'inactive') {
        respond(['success' => false, 'message' => 'You cannot disable your own account.'], 422);
    }

    if ($target['role'] === 'OWNER' && $role === 'SELLER') {
        $ownerCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "OWNER" AND status = "active"')->fetchColumn();
        if ($ownerCount <= 1) {
            respond(['success' => false, 'message' => 'At least one active OWNER account is required.'], 422);
        }
    }

    $sql = 'UPDATE users SET name = :name, email = :email, role = :role, status = :status';
    $params = [
        'id' => $id,
        'name' => $name,
        'email' => $email !== '' ? $email : null,
        'role' => $role,
        'status' => $status,
    ];

    if ($password !== '') {
        if (strlen($password) < 8) {
            respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
        }
        $sql .= ', password_hash = :password_hash';
        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond(['success' => true, 'message' => 'User updated successfully.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
