<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $user = current_user($pdo);

    // If session has a stale user_id pointing to a deleted/nonexistent user, clear it
    if (!$user && !empty($_SESSION['user_id'])) {
        unset($_SESSION['user_id']);
    }

    $hasOwner = owner_exists($pdo);

    respond([
        'success' => true,
        'authenticated' => $user !== null,
        'owner_exists' => $hasOwner,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    error_log('[me] Error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Failed to check authentication.'], 500);
}
