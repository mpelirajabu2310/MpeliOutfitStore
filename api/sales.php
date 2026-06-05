<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_role($pdo, ['OWNER', 'SELLER']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$data = read_json_body();
$items = $data['items'] ?? [];
$paymentMethod = (string)($data['payment_method'] ?? 'cash');

if (!is_array($items) || count($items) === 0) {
    respond(['success' => false, 'message' => 'At least one sale item is required.'], 422);
}

$pdo->beginTransaction();

try {
    $receiptNumber = 'MM-' . date('Ymd-His') . '-' . random_int(100, 999);
    $subtotal = 0.0;
    $profit = 0.0;
    $preparedItems = [];

    foreach ($items as $item) {
        $variantId = (int)($item['variant_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);

        if ($variantId <= 0 || $quantity <= 0) {
            throw new RuntimeException('Invalid sale item.');
        }

        $stmt = $pdo->prepare(
            'SELECT pv.id AS variant_id, pv.stock_quantity, p.buying_price, p.selling_price
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :variant_id AND p.status = "active"
             FOR UPDATE'
        );
        $stmt->execute(['variant_id' => $variantId]);
        $variant = $stmt->fetch();

        if (!$variant) {
            throw new RuntimeException('Product variant not found.');
        }
        if ((int)$variant['stock_quantity'] < $quantity) {
            throw new RuntimeException('Not enough stock for one or more selected products.');
        }

        $lineTotal = (float)$variant['selling_price'] * $quantity;
        $lineProfit = ((float)$variant['selling_price'] - (float)$variant['buying_price']) * $quantity;
        $subtotal += $lineTotal;
        $profit += $lineProfit;

        $preparedItems[] = [
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'buying_price' => (float)$variant['buying_price'],
            'selling_price' => (float)$variant['selling_price'],
            'line_total' => $lineTotal,
            'line_profit' => $lineProfit,
        ];
    }

    $saleStmt = $pdo->prepare(
        'INSERT INTO sales (receipt_number, sold_by, subtotal, total_amount, total_profit, payment_status)
         VALUES (:receipt_number, :sold_by, :subtotal, :total_amount, :total_profit, "paid")'
    );
    $saleStmt->execute([
        'receipt_number' => $receiptNumber,
        'sold_by' => $user['id'],
        'subtotal' => $subtotal,
        'total_amount' => $subtotal,
        'total_profit' => $profit,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, variant_id, quantity, buying_price, selling_price, line_total, line_profit)
         VALUES (:sale_id, :variant_id, :quantity, :buying_price, :selling_price, :line_total, :line_profit)'
    );
    $stockStmt = $pdo->prepare(
        'UPDATE product_variants SET stock_quantity = stock_quantity - :quantity WHERE id = :variant_id'
    );
    $movementStmt = $pdo->prepare(
        'INSERT INTO inventory_movements (variant_id, movement_type, quantity_change, reference_type, reference_id, note, created_by)
         VALUES (:variant_id, "sale", :quantity_change, "sale", :sale_id, "POS sale", :created_by)'
    );

    foreach ($preparedItems as $item) {
        $itemStmt->execute([
            'sale_id' => $saleId,
            'variant_id' => $item['variant_id'],
            'quantity' => $item['quantity'],
            'buying_price' => $item['buying_price'],
            'selling_price' => $item['selling_price'],
            'line_total' => $item['line_total'],
            'line_profit' => $item['line_profit'],
        ]);
        $stockStmt->execute([
            'quantity' => $item['quantity'],
            'variant_id' => $item['variant_id'],
        ]);
        $movementStmt->execute([
            'variant_id' => $item['variant_id'],
            'quantity_change' => -1 * $item['quantity'],
            'sale_id' => $saleId,
            'created_by' => $user['id'],
        ]);
    }

    $paymentStmt = $pdo->prepare(
        'INSERT INTO payments (sale_id, payment_method, amount)
         VALUES (:sale_id, :payment_method, :amount)'
    );
    $paymentStmt->execute([
        'sale_id' => $saleId,
        'payment_method' => $paymentMethod,
        'amount' => $subtotal,
    ]);

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Sale completed successfully.',
        'receipt_number' => $receiptNumber,
        'total_amount' => $subtotal,
        'total_profit' => $user['role'] === 'OWNER' ? $profit : null,
    ], 201);
} catch (Throwable $exception) {
    $pdo->rollBack();
    respond(['success' => false, 'message' => $exception->getMessage()], 500);
}
