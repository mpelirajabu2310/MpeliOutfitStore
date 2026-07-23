<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=clothing_shop_management;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

echo "=== EXACT QUERY FROM login.php ===\n";
$username = 'mpeli';
$stmt = $pdo->prepare(
    'SELECT id, name, username, email, password_hash, role, status
     FROM users
     WHERE username = :username AND status = "active"
     LIMIT 1'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();
echo "Found: " . ($user ? "YES - {$user['username']}" : "NO") . "\n";

echo "\n=== TEST login.php USERNAME VALIDATION ===\n";
$testNames = ['mpeli', 'Ikramu', 'admin', 'MP', 'mpeli '];
foreach ($testNames as $name) {
    $valid = strlen($name) <= 50 && preg_match('/^[a-zA-Z0-9_]+$/', $name);
    echo "'$name' => " . ($valid ? 'PASS' : 'FAIL') . "\n";
}

echo "\n=== CHECK IF mpeli CAN PASSWORD_VERIFY WITH ANY COMMON PASSWORD ===\n";
$hash = $user['password_hash'];
$commonPasswords = [
    'admin', 'admin123', 'admin1234', 'password', 'mpeli', 'Mpeli',
    'password123', '12345678', 'qwerty', 'letmein', 'welcome',
    'admin@123', 'Admin1234', 'Admin@123', 'Pass1234', 'Mpel1234',
    'mpeli1234', 'Mpel1234!', 'admin!', 'Admin123!', 'admin@1234',
    'Mpeli@1234', 'mpeli@1234', 'Admin@1234', 'owner', 'owner1234',
    'test', 'test1234', 'seller1234', 'Seller1234'
];
$found = false;
foreach ($commonPasswords as $pw) {
    if (password_verify($pw, $hash)) {
        echo "FOUND PASSWORD: '$pw'\n";
        $found = true;
        break;
    }
}
if (!$found) {
    echo "No common password matched. Hash needs re-creation.\n";
}

echo "\n=== RE-HASH mpeli WITH 'admin1234' ===\n";
$newHash = password_hash('admin1234', PASSWORD_DEFAULT);
$verify = password_verify('admin1234', $newHash);
echo "New hash: $newHash\n";
echo "Verify: " . ($verify ? 'OK' : 'FAIL') . "\n";

// Actually update the password
$stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = 1");
$stmt->execute(['hash' => $newHash]);
echo "Password updated for mpeli -> 'admin1234'\n";

// Verify it reads back correctly
$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = 1");
$stmt->execute();
$row = $stmt->fetch();
$verified = password_verify('admin1234', $row['password_hash']);
echo "Read back and verify: " . ($verified ? 'PASS' : 'FAIL') . "\n";

echo "\n=== RE-HASH Ikramu WITH 'seller1234' ===\n";
$newHash2 = password_hash('seller1234', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = 2");
$stmt->execute(['hash' => $newHash2]);
$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = 2");
$stmt->execute();
$row2 = $stmt->fetch();
$verified2 = password_verify('seller1234', $row2['password_hash']);
echo "Ikramu -> 'seller1234': " . ($verified2 ? 'PASS' : 'FAIL') . "\n";

echo "\n=== CLEAR ALL RATE LIMITS ===\n";
$rlDir = __DIR__ . '/../logs/ratelimit';
if (is_dir($rlDir)) {
    $files = glob($rlDir . '/*.json');
    foreach ($files as $f) {
        unlink($f);
        echo "Deleted: " . basename($f) . "\n";
    }
}
echo "Rate limits cleared.\n";
