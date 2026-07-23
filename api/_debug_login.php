<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=clothing_shop_management;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

echo "=== SIMULATE EXACT LOGIN FLOW ===\n";

// Test with 'mpeli' user
$username = 'mpeli';
$password = 'Test1234'; // Try a test password

$stmt = $pdo->prepare(
    'SELECT id, name, username, email, password_hash, role, status
     FROM users
     WHERE username = :username AND status = "active"
     LIMIT 1'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user) {
    echo "ERROR: User '$username' not found!" . PHP_EOL;
    exit(1);
}

echo "User found: {$user['username']} (id={$user['id']})\n";
echo "Password hash length: " . strlen($user['password_hash']) . "\n";
echo "Password hash full: {$user['password_hash']}\n";
echo "Password hash type: " . gettype($user['password_hash']) . "\n";

// Test password_verify
$result = password_verify($password, (string)$user['password_hash']);
echo "password_verify('$password', hash) = " . ($result ? 'TRUE (login would succeed)' : 'FALSE (login would fail)') . "\n";

// Check if the hash is binary-corrupted
echo "Hash raw bytes (hex): " . bin2hex($user['password_hash']) . "\n";

// Test with empty password
echo "password_verify('', hash) = " . (password_verify('', $user['password_hash']) ? 'TRUE' : 'FALSE') . "\n";

// Create a fresh hash and test it
$newHash = password_hash('Test1234', PASSWORD_DEFAULT);
echo "\n=== SELF-TEST ===\n";
echo "Fresh hash: $newHash\n";
echo "password_verify('Test1234', fresh) = " . (password_verify('Test1234', $newHash) ? 'TRUE' : 'FALSE') . "\n";

// Check PHP version
echo "\n=== PHP INFO ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Bcrypt available: " . (defined('PASSWORD_BCRYPT') ? 'YES' : 'NO') . "\n";
