<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// The client sends a heartbeat only when it detects genuine user activity
// (mouse, keyboard, touch, scroll) or when the user resumes their session
// ("Continue Session"). Background polling never calls this endpoint, so an
// idle session expires server-side even while charts/dashboards keep polling.
if (!empty($_SESSION['user_id'])) {
    touch_session_activity();
    respond([
        'success' => true,
        'authenticated' => true,
        'server_time' => time(),
    ]);
}

// Session already gone (expired, logged out in another tab, etc.). Return
// success so the client can detect it and force a graceful logout.
respond([
    'success' => true,
    'authenticated' => false,
    'server_time' => time(),
]);
