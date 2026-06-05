<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_role($pdo, ['OWNER']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $summary = [
        'today' => (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = CURDATE()')->fetchColumn(),
        'month' => (float)$pdo->query(
            'SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())'
        )->fetchColumn(),
    ];

    $recent = $pdo->query(
        'SELECT e.id, e.title, e.amount, e.expense_date, ec.name AS category
         FROM expenses e
         JOIN expense_categories ec ON ec.id = e.category_id
         ORDER BY e.expense_date DESC, e.id DESC
         LIMIT 10'
    )->fetchAll();

    respond(['success' => true, 'summary' => $summary, 'expenses' => $recent]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $title = trim((string)($data['title'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $expenseDate = (string)($data['expense_date'] ?? date('Y-m-d'));

    if ($title === '' || $category === '' || $amount <= 0) {
        respond(['success' => false, 'message' => 'Title, category, and amount are required.'], 422);
    }

    $categoryStmt = $pdo->prepare('SELECT id FROM expense_categories WHERE name = :name LIMIT 1');
    $categoryStmt->execute(['name' => $category]);
    $categoryId = $categoryStmt->fetchColumn();

    if (!$categoryId) {
        $insertCategory = $pdo->prepare('INSERT INTO expense_categories (name) VALUES (:name)');
        $insertCategory->execute(['name' => $category]);
        $categoryId = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO expenses (category_id, recorded_by, title, amount, expense_date, payment_method, note)
         VALUES (:category_id, :recorded_by, :title, :amount, :expense_date, :payment_method, :note)'
    );
    $stmt->execute([
        'category_id' => $categoryId,
        'recorded_by' => $user['id'],
        'title' => $title,
        'amount' => $amount,
        'expense_date' => $expenseDate,
        'payment_method' => $data['payment_method'] ?? 'cash',
        'note' => $data['note'] ?? null,
    ]);

    respond(['success' => true, 'message' => 'Expense recorded successfully.'], 201);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
