<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Log before destroying session
$userId = $_SESSION['user_id'] ?? 0;
if ($userId) {
    log_activity((int)$userId, 'logout');
}

// Clear session regardless of auth state (best-effort logout)
$_SESSION = [];
if (session_id()) {
    session_destroy();
}

// Also clear the session cookie
if (ini_get('session.use_cookies')) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

respond(['success' => true, 'message' => 'Logged out successfully.']);
