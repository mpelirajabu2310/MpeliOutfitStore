<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Must be authenticated to change password
$user = require_login($pdo);

// CSRF protection required
require_csrf();

$data = read_json_body();
$currentPassword = (string)($data['current_password'] ?? '');
$newPassword = (string)($data['new_password'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    respond(['success' => false, 'message' => 'Current password and new password are required.'], 422);
}

// Verify current password first
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $user['id']]);
$record = $stmt->fetch();

if (!$record || !password_verify($currentPassword, (string)$record['password_hash'])) {
    log_activity((int)$user['id'], 'password_change_failed', 'Current password incorrect', 'failure');
    respond(['success' => false, 'message' => 'Current password is incorrect.'], 401);
}

if (strlen($newPassword) < 8) {
    respond(['success' => false, 'message' => 'New password must be at least 8 characters.'], 422);
}

// Password strength: require at least one letter and one number
if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    respond(['success' => false, 'message' => 'New password must contain at least one letter and one number.'], 422);
}

// Prevent reuse of current password
if (password_verify($newPassword, (string)$record['password_hash'])) {
    respond(['success' => false, 'message' => 'New password must be different from the current password.'], 422);
}

$update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
$update->execute([
    'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => $user['id'],
]);

// Regenerate session after password change
session_regenerate_id(true);

log_activity((int)$user['id'], 'password_changed');

respond([
    'success' => true,
    'message' => 'Password changed successfully.',
]);
