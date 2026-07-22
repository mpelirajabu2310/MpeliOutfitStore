<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$checks = [];

// 1. Database connection check
try {
    $result = $pdo->query('SELECT 1')->fetchColumn();
    $checks['database_connection'] = $result === '1' ? 'OK' : 'FAILED';
} catch (Exception $e) {
    $checks['database_connection'] = 'ERROR: ' . $e->getMessage();
}

// 2. Check all required tables exist
$tables = ['users', 'products', 'product_variants', 'sales', 'shop_settings'];
foreach ($tables as $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
        $checks["table_$table"] = 'OK';
    } catch (Exception $e) {
        $checks["table_$table"] = 'MISSING';
    }
}

// 3. Check owner exists
try {
    $ownerExists = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "OWNER"')->fetchColumn();
    $checks['owner_exists'] = $ownerExists > 0 ? 'YES' : 'NO';
} catch (Exception $e) {
    $checks['owner_exists'] = 'ERROR';
}

// 4. Check general category exists
try {
    $catExists = (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE name = "General"')->fetchColumn();
    $checks['general_category'] = $catExists > 0 ? 'OK' : 'MISSING';
} catch (Exception $e) {
    $checks['general_category'] = 'ERROR';
}

// 5. Check shop settings exist
try {
    $settingsExist = (int)$pdo->query('SELECT COUNT(*) FROM shop_settings')->fetchColumn();
    $checks['shop_settings'] = $settingsExist > 0 ? 'OK' : 'MISSING';
} catch (Exception $e) {
    $checks['shop_settings'] = 'ERROR';
}

// 6. Session test
$_SESSION['test_key'] = 'test_value';
$checks['session_working'] = $_SESSION['test_key'] === 'test_value' ? 'OK' : 'FAILED';
unset($_SESSION['test_key']);

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks,
    'all_ok' => !in_array(false, array_map(function($v) { return strpos($v, 'OK') !== false || strpos($v, 'YES') !== false || strpos($v, 'NO') === 0; }, $checks))
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
