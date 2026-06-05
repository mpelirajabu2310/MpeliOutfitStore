<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

// Destroy any old session data to prevent stale state
// session_destroy();  // Don't destroy, just clear user ID if needed
if (isset($_GET['clear'])) {
    session_destroy();
    session_start();
}

try {
    $user = current_user($pdo);

    // If session has a stale user_id pointing to a deleted/nonexistent user, clear it
    if (!$user && !empty($_SESSION['user_id'])) {
        error_log('[me] Clearing stale user_id ' . $_SESSION['user_id'] . ' from session ' . session_id());
        unset($_SESSION['user_id']);
    }

    $hasOwner = owner_exists($pdo);
    error_log('[me] Session ' . session_id() . ' — authenticated: ' . ($user ? 'yes (ID:' . $user['id'] . ')' : 'no') . ', owner_exists: ' . ($hasOwner ? 'yes' : 'no'));

    respond([
        'success' => true,
        'authenticated' => $user !== null,
        'owner_exists' => $hasOwner,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Failed to check authentication.'], 500);
}
