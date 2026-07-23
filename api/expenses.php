<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);
$isOwner = $user['role'] === 'OWNER';

require_once __DIR__ . '/../services/ExpenseService.php';
require_once __DIR__ . '/../services/PermissionService.php';
$expenseService = new ExpenseService();
$CATEGORIES = $expenseService->getValidCategories();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = $isOwner ? null : $user['id'];
    $dailyExpenses = $expenseService->getDailyTotal($userId);
    $monthlyExpenses = $expenseService->getMonthlyTotal($userId);
    $todayCategories = $expenseService->getCategoryBreakdown($userId);
    $expenses = $expenseService->getAllExpenses($userId);

    respond([
        'success' => true,
        'role' => $user['role'],
        'categories' => $CATEGORIES,
        'summary' => [
            'today' => $dailyExpenses,
            'month' => $monthlyExpenses,
        ],
        'today_categories' => $todayCategories,
        'expenses' => $expenses,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    PermissionService::requirePermission($user['role'], 'expenses.create');
    require_csrf();
    $data = read_json_body();
    $category = trim((string)($data['category'] ?? ''));
    $expenseName = trim((string)($data['expense_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $expenseDate = (string)($data['expense_date'] ?? date('Y-m-d'));

    if ($category === '' || $amount <= 0) {
        respond(['success' => false, 'message' => 'Expense amount must be greater than zero.'], 422);
    }
    if (!in_array($category, $CATEGORIES, true)) {
        respond(['success' => false, 'message' => 'Invalid expense category.'], 422);
    }
    if ($category === 'Other' && $expenseName === '') {
        respond(['success' => false, 'message' => 'Expense name is required when category is Other.'], 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        respond(['success' => false, 'message' => 'Invalid expense_date format. Use YYYY-MM-DD.'], 422);
    }
    if (strlen($expenseName) > 255) {
        respond(['success' => false, 'message' => 'Expense name must be 255 characters or fewer.'], 422);
    }

    $expenseService->addExpense(
        $category,
        $amount,
        $expenseDate,
        $category === 'Other' ? $expenseName : null,
        $description ?: null,
        $user['id']
    );

    log_activity((int)$user['id'], 'expense_created', "Category: {$category}, Amount: {$amount}, Date: {$expenseDate}");
    respond(['success' => true, 'message' => 'Expense recorded successfully.'], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    PermissionService::requirePermission($user['role'], 'expenses.update');
    require_csrf();

    $data = read_json_body();
    $id = (int)($data['id'] ?? 0);
    $category = trim((string)($data['category'] ?? ''));
    $expenseName = trim((string)($data['expense_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $expenseDate = (string)($data['expense_date'] ?? '');

    if ($id <= 0) {
        respond(['success' => false, 'message' => 'Expense ID is required.'], 422);
    }

    $expense = $expenseService->getExpenseById($id);
    if (!$expense) {
        respond(['success' => false, 'message' => 'Expense not found.'], 404);
    }
    require_ownership($pdo, (int)$expense['created_by'], $user);

    if ($category !== '' && !in_array($category, $CATEGORIES, true)) {
        respond(['success' => false, 'message' => 'Invalid expense category.'], 422);
    }
    if ($category === 'Other' && $expenseName === '') {
        respond(['success' => false, 'message' => 'Expense name is required when category is Other.'], 422);
    }
    if ($amount <= 0) {
        respond(['success' => false, 'message' => 'Expense amount must be greater than zero.'], 422);
    }
    if ($expenseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        respond(['success' => false, 'message' => 'Invalid expense_date format. Use YYYY-MM-DD.'], 422);
    }
    if (strlen($expenseName) > 255) {
        respond(['success' => false, 'message' => 'Expense name must be 255 characters or fewer.'], 422);
    }

    $updateData = [];
    if ($category !== '') {
        $updateData['category'] = $category;
        $updateData['expense_name'] = $category === 'Other' ? $expenseName : null;
    }
    if ($description !== '') {
        $updateData['description'] = $description;
    }
    if ($amount > 0) {
        $updateData['amount'] = $amount;
    }
    if ($expenseDate !== '') {
        $updateData['expense_date'] = $expenseDate;
    }

    try {
        $expenseService->updateExpense($id, $updateData);
        log_activity((int)$user['id'], 'expense_updated', "Expense ID: {$id}");
        respond(['success' => true, 'message' => 'Expense updated successfully.']);
    } catch (RuntimeException $e) {
        respond(['success' => false, 'message' => $e->getMessage()], 404);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    PermissionService::requirePermission($user['role'], 'expenses.delete');
    require_csrf();

    $data = read_json_body();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'Expense ID is required.'], 422);
    }

    $expense = $expenseService->getExpenseById($id);
    if (!$expense) {
        respond(['success' => false, 'message' => 'Expense not found.'], 404);
    }
    require_ownership($pdo, (int)$expense['created_by'], $user);

    try {
        $expenseService->deleteExpense($id);
        log_activity((int)$user['id'], 'expense_deleted', "Expense ID: {$id}");
        respond(['success' => true, 'message' => 'Expense deleted successfully.']);
    } catch (RuntimeException $e) {
        respond(['success' => false, 'message' => $e->getMessage()], 404);
    }
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
