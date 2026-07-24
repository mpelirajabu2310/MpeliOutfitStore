<?php
/**
 * Part 7 + 10: Full new installation flow test (corrected)
 */

$cookie = tempnam(sys_get_temp_dir(), 'fresh');
$base = 'http://localhost/MpeliOutFitStore';

function api($url, $method = 'GET', $data = null, $cookie = '', $csrf = '') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }
    $headers = ['Content-Type: application/json'];
    if ($csrf) $headers[] = "X-CSRF-Token: $csrf";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['status' => 0, 'data' => null, 'error' => $err];
    return ['status' => $code, 'data' => json_decode($r, true)];
}

$pass = 0; $fail = 0;
function check($name, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  PASS: $name" . ($detail ? " ($detail)" : '') . "\n"; }
    else { $fail++; echo "  FAIL: $name" . ($detail ? " ($detail)" : '') . "\n"; }
}

echo "==============================================\n";
echo "  NEW INSTALLATION FLOW TEST\n";
echo "==============================================\n\n";

// ── CLEANUP: Ensure clean state ────────────────────────────
echo "--- CLEANUP: Truncating all data ---\n";
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$truncateOrder = ['sale_items','payments','sales','inventory_movements','expenses','product_variants','products','categories','colors','sizes','customers','users','shop_settings','migration_history'];
foreach ($truncateOrder as $table) {
    try {
        $typeCheck = $pdo->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch();
        if (($typeCheck['TABLE_TYPE'] ?? '') === 'VIEW') continue;
        $pdo->exec("TRUNCATE TABLE `$table`");
    } catch (PDOException $e) { /* skip */ }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
@unlink(__DIR__ . '/../logs/ratelimit');
echo "  Done.\n\n";

// STEP 1: Open system — should show setup
echo "--- STEP 1: System Startup (no admin) ---\n";
$r = api("$base/api/me.php");
check("me.php returns 200", $r['status'] === 200);
check("System is healthy", $r['data']['healthy'] === true);
check("No owner exists", $r['data']['owner_exists'] === false);
check("Not authenticated", $r['data']['authenticated'] === false);
check("No maintenance mode", ($r['data']['maintenance']['active'] ?? false) === false);
echo "\n";

// STEP 2: Create Admin via registration
echo "--- STEP 2: Create First Administrator ---\n";
$r = api("$base/api/register_owner.php", 'POST', [
    'name' => 'System Admin',
    'username' => 'admin',
    'email' => 'admin@mpelioutfitstore.com',
    'password' => 'Admin1234!'
], $cookie);
check("Registration returns 201", $r['status'] === 201);
check("Registration success", $r['data']['success'] === true);

// Verify owner now exists
$r = api("$base/api/me.php");
check("Owner now exists", $r['data']['owner_exists'] === true);
echo "\n";

// STEP 3: Login as Admin
echo "--- STEP 3: Login as Administrator ---\n";
$r = api("$base/api/login.php", 'POST', ['username' => 'admin', 'password' => 'Admin1234!'], $cookie);
check("Login returns 200", $r['status'] === 200);
check("Login success", $r['data']['success'] === true);
check("Role is OWNER", $r['data']['user']['role'] === 'OWNER');
$csrf = $r['data']['csrf_token'] ?? '';
check("CSRF token present", !empty($csrf));

// Verify authenticated via me.php
$r = api("$base/api/me.php", 'GET', null, $cookie);
check("Authenticated via me.php", $r['data']['authenticated'] === true);
check("User is SYSTEM ADMIN", $r['data']['user']['name'] === 'System Admin');
echo "\n";

// Check dashboard shows zeros
echo "--- Dashboard shows zeros ---\n";
$r = api("$base/api/dashboard.php", 'GET', null, $cookie);
check("Dashboard returns 200", $r['status'] === 200);
$stats = $r['data']['stats'] ?? [];
check("Total products = 0", ($stats['total_products'] ?? -1) == 0);
check("Total sales = 0", ($stats['total_sales'] ?? -1) == 0);
check("Daily revenue = 0", ($stats['daily_revenue'] ?? -1) == 0);
check("Daily expenses = 0", ($stats['daily_expenses'] ?? -1) == 0 || ($stats['daily_expenses'] ?? null) === null);
echo "\n";

// STEP 4: Create a Seller
echo "--- STEP 4: Create Seller ---\n";
$r = api("$base/api/users.php", 'POST', [
    'name' => 'Seller One',
    'username' => 'seller1',
    'email' => 'seller1@mpelioutfitstore.com',
    'password' => 'Seller1234!',
    'role' => 'SELLER'
], $cookie, $csrf);
check("Seller creation returns 200/201", $r['status'] === 200 || $r['status'] === 201);
check("Seller created", $r['data']['success'] === true);
echo "\n";

// STEP 5: Add Products
echo "--- STEP 5: Add Products ---\n";
$r = api("$base/api/products.php", 'POST', [
    'name' => 'Classic Suit',
    'buying_price' => 25000,
    'selling_price' => 45000,
    'minimum_allowed_selling_price' => 30000,
    'stock_quantity' => 10
], $cookie, $csrf);
check("Product 1 created", $r['data']['success'] === true);

$r = api("$base/api/products.php", 'POST', [
    'name' => 'Summer Dress',
    'buying_price' => 15000,
    'selling_price' => 30000,
    'minimum_allowed_selling_price' => 18000,
    'stock_quantity' => 20
], $cookie, $csrf);
check("Product 2 created", $r['data']['success'] === true);

$r = api("$base/api/products.php", 'GET', null, $cookie);
$products = $r['data']['products'] ?? [];
check("Products list has 2 items", count($products) === 2);
echo "\n";

// STEP 6: Complete Sale (using variant_id + actual selling price from product list)
echo "--- STEP 6: Complete Sale ---\n";
if (!empty($products)) {
    $firstProduct = $products[0];
    $variantId = $firstProduct['variant_id'] ?? 0;
    $sellingPrice = (float)($firstProduct['selling'] ?? $firstProduct['selling_price'] ?? 0);
    check("First product has variant_id", $variantId > 0, "variant_id=$variantId");
    check("First product has selling price", $sellingPrice > 0, "price=$sellingPrice");

    $r = api("$base/api/sales.php", 'POST', [
        'items' => [['variant_id' => $variantId, 'quantity' => 1, 'final_selling_price' => $sellingPrice]],
        'payment_method' => 'cash'
    ], $cookie, $csrf);
    check("Sale completed", $r['data']['success'] === true, $r['data']['message'] ?? '');

    // Verify dashboard updated
    $r = api("$base/api/dashboard.php", 'GET', null, $cookie);
    $stats = $r['data']['stats'] ?? [];
    check("Dashboard total_sales = 1", ($stats['total_sales'] ?? 0) == 1);
    check("Dashboard daily_revenue > 0", ($stats['daily_revenue'] ?? 0) > 0);
}
echo "\n";

// STEP 7: Logout and verify seller isolation
echo "--- STEP 7: Logout and Verify Seller Isolation ---\n";
$r = api("$base/api/logout.php", 'POST', [], $cookie);
check("Logout works", $r['status'] === 200);

$r = api("$base/api/login.php", 'POST', ['username' => 'seller1', 'password' => 'Seller1234!'], $cookie);
check("Seller login works", $r['data']['success'] === true);
$csrf = $r['data']['csrf_token'] ?? '';

$r = api("$base/api/dashboard.php", 'GET', null, $cookie);
check("Seller profit = NULL", ($r['data']['stats']['daily_profit'] ?? null) === null);
check("Seller sees no owner expenses", ($r['data']['stats']['total_expenses'] ?? null) === null);
echo "\n";

echo "==============================================\n";
echo "  RESULTS: {$pass} passed, {$fail} failed\n";
echo "==============================================\n";

unlink($cookie);
