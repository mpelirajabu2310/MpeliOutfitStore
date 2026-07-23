<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

require_once __DIR__ . '/../services/PermissionService.php';

$owner = require_role($pdo, ['OWNER']);
PermissionService::requirePermission($owner['role'], 'users.create');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, name, username, email, role, status, created_at
         FROM users
         ORDER BY created_at DESC'
    );
    respond(['success' => true, 'users' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

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
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        respond(['success' => false, 'message' => 'Password must contain at least one letter and one number.'], 422);
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

        $newUserId = (int)$pdo->lastInsertId();
        log_activity((int)$owner['id'], 'user_created', "Created user: $username (role: $role, id: $newUserId)");

        respond(['success' => true, 'message' => 'User created successfully.'], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            respond(['success' => false, 'message' => 'Username or email already exists.'], 409);
        }
        error_log('[users] create error: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Failed to create user.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_csrf();

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
    if (strlen($name) > 100) {
        respond(['success' => false, 'message' => 'Name must be 100 characters or fewer.'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid email format.'], 422);
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

    log_activity((int)$owner['id'], 'user_updated', "Updated user ID: $id");

    respond(['success' => true, 'message' => 'User updated successfully.']);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
