<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=clothing_shop_management;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== USERS TABLE COLUMNS ===\n";
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | ' . $c['Null'] . ' | ' . ($c['Default'] ?? 'NULL') . PHP_EOL;
}

echo "\n=== ALL USERS (id, username, role, status, password_hash[0:20]) ===\n";
$users = $pdo->query('SELECT id, username, role, status, password_hash, last_login_at FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $hash = $u['password_hash'] ?? 'N/A';
    $hashPreview = substr($hash, 0, 30) . '...';
    echo "ID={$u['id']} | user={$u['username']} | role={$u['role']} | status={$u['status']} | hash=$hashPreview | last_login={$u['last_login_at']}" . PHP_EOL;
}

echo "\n=== VERIFY password_verify test ===\n";
foreach ($users as $u) {
    $hash = $u['password_hash'];
    // Test common passwords
    $tests = ['admin1234', 'password', '12345678', 'test1234', 'seller1234', 'password123'];
    foreach ($tests as $pw) {
        if (password_verify($pw, $hash)) {
            echo "FOUND: {$u['username']} -> password is: $pw" . PHP_EOL;
        }
    }
}

echo "\n=== PASSWORD HASH INFO ===\n";
foreach ($users as $u) {
    $algo = PASSWORD_DEFAULT;
    $hash = $u['password_hash'];
    $info = password_get_info($hash);
    echo "{$u['username']}: algo={$info['algo']} algoName={$info['algoName']} needRehash=" . (password_needs_rehash($hash, $algo) ? 'YES' : 'NO') . PHP_EOL;
}
