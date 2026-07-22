<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$owner = require_role($pdo, ['OWNER']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = ensure_shop_settings($pdo);
    respond([
        'success' => true,
        'shop' => [
            'shop_name' => $settings['shop_name'] ?? '',
            'address' => $settings['address'] ?? '',
            'phone' => $settings['phone'] ?? '',
            'email' => $settings['email'] ?? '',
            'currency_code' => $settings['currency_code'] ?? 'TSH',
            'low_stock_threshold' => (int)($settings['low_stock_threshold'] ?? 5),
            'dark_mode_enabled' => (bool)($settings['dark_mode_enabled'] ?? false),
            'receipt_footer' => $settings['receipt_footer'] ?? '',
        ],
        'admin' => [
            'name' => $owner['name'],
            'email' => $owner['email'] ?? '',
            'username' => $owner['username'],
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = read_json_body();
    $settings = ensure_shop_settings($pdo);
    $settingsId = (int)$settings['id'];

    $shopName = trim((string)($data['shop_name'] ?? $settings['shop_name'] ?? ''));
    $address = trim((string)($data['address'] ?? $settings['address'] ?? ''));
    $phone = trim((string)($data['phone'] ?? $settings['phone'] ?? ''));
    $email = trim((string)($data['shop_email'] ?? $settings['email'] ?? ''));
    $threshold = max(1, (int)($data['low_stock_threshold'] ?? $settings['low_stock_threshold'] ?? 5));
    $darkMode = !empty($data['dark_mode_enabled']);
    $receiptFooter = trim((string)($data['receipt_footer'] ?? $settings['receipt_footer'] ?? ''));

    $adminName = trim((string)($data['admin_name'] ?? $owner['name']));
    $adminEmail = trim((string)($data['admin_email'] ?? $owner['email'] ?? ''));
    $adminPassword = (string)($data['admin_password'] ?? '');

    if ($adminName === '') {
        respond(['success' => false, 'message' => 'Admin name is required.'], 422);
    }
    if (strlen($shopName) > 100) {
        respond(['success' => false, 'message' => 'Shop name must be 100 characters or fewer.'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid shop email format.'], 422);
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid admin email format.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $shopStmt = $pdo->prepare(
            'UPDATE shop_settings
             SET shop_name = :shop_name,
                 address = :address,
                 phone = :phone,
                 email = :email,
                 currency_code = "TSH",
                 low_stock_threshold = :low_stock_threshold,
                 dark_mode_enabled = :dark_mode_enabled,
                 receipt_footer = :receipt_footer
             WHERE id = :id'
        );
        $shopStmt->execute([
            'id' => $settingsId,
            'shop_name' => $shopName !== '' ? $shopName : null,
            'address' => $address !== '' ? $address : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'low_stock_threshold' => $threshold,
            'dark_mode_enabled' => $darkMode ? 1 : 0,
            'receipt_footer' => $receiptFooter !== '' ? $receiptFooter : null,
        ]);

        $pdo->prepare(
            'UPDATE product_variants pv
             JOIN products p ON p.id = pv.product_id
             SET pv.reorder_level = :threshold
             WHERE p.status = "active"'
        )->execute(['threshold' => $threshold]);

        $userSql = 'UPDATE users SET name = :name, email = :email';
        $userParams = [
            'id' => $owner['id'],
            'name' => $adminName,
            'email' => $adminEmail !== '' ? $adminEmail : null,
        ];

        if ($adminPassword !== '') {
            if (strlen($adminPassword) < 8) {
                throw new RuntimeException('Password must be at least 8 characters.');
            }
            $userSql .= ', password_hash = :password_hash';
            $userParams['password_hash'] = password_hash($adminPassword, PASSWORD_DEFAULT);
        }

        $userSql .= ' WHERE id = :id';
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute($userParams);

        $pdo->commit();
        respond(['success' => true, 'message' => 'Settings saved successfully.']);
    } catch (Throwable $exception) {
        $pdo->rollBack();
        $message = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Failed to save settings.';
        error_log('[settings] ' . $exception->getMessage());
        respond(['success' => false, 'message' => $message], $exception instanceof RuntimeException ? 422 : 500);
    }
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
