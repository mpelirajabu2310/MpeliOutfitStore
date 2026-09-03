<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$action = (string)($input['action'] ?? '');

// ─── STEP 1: Verify Identity ────────────────────────────────────────────────
if ($action === 'verify') {
    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));

    if ($username === '' || $email === '') {
        respond(['success' => false, 'message' => 'Username and email are required.'], 422);
    }

    if (strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        respond(['success' => false, 'message' => 'Invalid username format.'], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid email format.'], 422);
    }

    // Rate limit: 5 verify attempts per 5 minutes
    if (!check_rate_limit('recovery_verify', 5, 300)) {
        $ip = get_client_ip();
        audit_log(0, 'recovery_verify_blocked', "IP: $ip — too many verify attempts", 'blocked', [
            'module' => 'auth',
            'description' => "IP: {$ip} blocked due to too many recovery verify attempts",
        ]);
        respond(['success' => false, 'message' => 'Too many attempts. Try again in 5 minutes.'], 429);
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email FROM users WHERE username = :username AND email = :email AND status = \'active\' LIMIT 1'
    );
    $stmt->execute(['username' => $username, 'email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        audit_log(0, 'recovery_verify_failed', "Username: $username, Email: $email", 'failure', [
            'module' => 'auth',
            'description' => "Recovery verify failed for username: {$username}",
        ]);
        respond(['success' => false, 'message' => 'No account found with that username and email.'], 404);
    }

    // Generate recovery token and store in session
    $token = bin2hex(random_bytes(32));
    $_SESSION['recovery_token'] = $token;
    $_SESSION['recovery_user_id'] = (int)$user['id'];
    $_SESSION['recovery_token_time'] = time();

    reset_rate_limit('recovery_verify');
    audit_log((int)$user['id'], 'recovery_verified', "Username: $username", 'success', [
        'module' => 'auth',
        'description' => "Recovery identity verified for: {$username}",
        'entity_type' => 'user',
        'entity_id' => (int)$user['id'],
    ]);

    respond([
        'success' => true,
        'message' => 'Identity verified. You can now set a new password.',
        'token' => $token,
    ]);
}

// ─── STEP 2: Reset Password ─────────────────────────────────────────────────
if ($action === 'reset') {
    $token = (string)($input['token'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    // Validate token
    if ($token === '' || empty($_SESSION['recovery_token']) || empty($_SESSION['recovery_user_id'])) {
        respond(['success' => false, 'message' => 'No recovery session found. Please verify your identity first.'], 400);
    }

    if (!hash_equals($_SESSION['recovery_token'], $token)) {
        respond(['success' => false, 'message' => 'Invalid recovery token. Please verify your identity again.'], 400);
    }

    // Token expires after 10 minutes
    if ((time() - ($_SESSION['recovery_token_time'] ?? 0)) > 600) {
        unset($_SESSION['recovery_token'], $_SESSION['recovery_user_id'], $_SESSION['recovery_token_time']);
        respond(['success' => false, 'message' => 'Verification expired. Please verify your identity again.'], 400);
    }

    $userId = (int)$_SESSION['recovery_user_id'];

    // Validate passwords
    if ($newPassword === '' || $confirmPassword === '') {
        respond(['success' => false, 'message' => 'New password and confirmation are required.'], 422);
    }

    if ($newPassword !== $confirmPassword) {
        respond(['success' => false, 'message' => 'Passwords do not match.'], 422);
    }

    if (strlen($newPassword) < 8) {
        respond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
    }

    if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        respond(['success' => false, 'message' => 'Password must contain at least one letter and one number.'], 422);
    }

    // Check that new password is different from current
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $record = $stmt->fetch();

    if (!$record) {
        respond(['success' => false, 'message' => 'User account not found.'], 404);
    }

    if (password_verify($newPassword, (string)$record['password_hash'])) {
        respond(['success' => false, 'message' => 'New password must be different from the current password.'], 422);
    }

    // Update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $update->execute(['hash' => $newHash, 'id' => $userId]);

    // Verify the hash was stored correctly
    if (!password_verify($newPassword, $newHash)) {
        respond(['success' => false, 'message' => 'Internal error: password hash verification failed.'], 500);
    }

    // Clear recovery session data
    unset($_SESSION['recovery_token'], $_SESSION['recovery_user_id'], $_SESSION['recovery_token_time']);

    // Clear rate limits for this client IP (scoped — do NOT wipe the whole
    // rate-limit directory, which would reset every user/IP's counters and
    // defeat brute-force protection for everyone.)
    reset_rate_limit('recovery_verify');
    reset_rate_limit('recovery_reset');
    reset_rate_limit('login');
    reset_rate_limit('register');

    audit_log($userId, 'recovery_password_reset', 'Password reset via recovery', 'success', [
        'module' => 'auth',
        'description' => 'Password reset via account recovery',
        'entity_type' => 'user',
        'entity_id' => $userId,
    ]);

    respond([
        'success' => true,
        'message' => 'Password reset successfully. You can now log in with your new password.',
    ]);
}

respond(['success' => false, 'message' => 'Invalid action.'], 422);
