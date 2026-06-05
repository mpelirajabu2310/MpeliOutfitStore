<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Clear session regardless of auth state (best-effort logout)
$_SESSION = [];
if (session_id()) {
    session_destroy();
}

// Also clear the session cookie
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

error_log('[logout] Session destroyed: ' . session_id());
respond(['success' => true, 'message' => 'Logged out successfully.']);
