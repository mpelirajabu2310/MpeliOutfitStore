<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseService.php';

class ExpenseService extends BaseService
{
    private array $CATEGORIES = ['Food', 'Transport', 'Rent', 'TRA', 'Electricity', 'Water', 'Salary', 'Maintenance', 'Other'];

    public function getValidCategories(): array
    {
        return $this->CATEGORIES;
    }

    public function addExpense(string $category, float $amount, string $expenseDate, ?string $expenseName = null, ?string $description = null, int $userId = 0): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO expenses (category, expense_name, description, amount, expense_date, created_by)
             VALUES (:category, :expense_name, :description, :amount, :expense_date, :created_by)'
        );
        $stmt->execute([
            'category' => $category,
            'expense_name' => $expenseName,
            'description' => $description,
            'amount' => $amount,
            'expense_date' => $expenseDate,
            'created_by' => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateExpense(int $id, array $data): void
    {
        $check = $this->db->prepare('SELECT id FROM expenses WHERE id = :id');
        $check->execute(['id' => $id]);
        if (!$check->fetch()) {
            throw new RuntimeException('Expense not found.');
        }

        $sets = [];
        $params = ['id' => $id];

        if (isset($data['category'])) {
            $sets[] = 'category = :category';
            $params['category'] = $data['category'];
        }
        if (array_key_exists('expense_name', $data)) {
            $sets[] = 'expense_name = :expense_name';
            $params['expense_name'] = $data['expense_name'];
        }
        if (array_key_exists('description', $data)) {
            $sets[] = 'description = :description';
            $params['description'] = $data['description'];
        }
        if (isset($data['amount'])) {
            $sets[] = 'amount = :amount';
            $params['amount'] = $data['amount'];
        }
        if (isset($data['expense_date'])) {
            $sets[] = 'expense_date = :expense_date';
            $params['expense_date'] = $data['expense_date'];
        }

        if (count($sets) === 0) {
            throw new RuntimeException('No fields to update.');
        }

        $this->db->prepare('UPDATE expenses SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    public function deleteExpense(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Expense not found.');
        }
    }

    public function getExpenseById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getAllExpenses(?int $userId = null, int $limit = 50): array
    {
        $sql = 'SELECT e.id, e.category, e.expense_name, e.description, e.amount, e.expense_date, e.created_at, u.name AS created_by_name
                FROM expenses e
                JOIN users u ON u.id = e.created_by';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE e.created_by = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY e.expense_date DESC, e.id DESC LIMIT ' . max(1, $limit);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getDailyTotal(?int $userId = null): float
    {
        return $this->aggregate('expense_date = CURDATE()', $userId);
    }

    public function getMonthlyTotal(?int $userId = null): float
    {
        return $this->aggregate('YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())', $userId);
    }

    public function getYearlyTotal(?int $userId = null): float
    {
        return $this->aggregate('YEAR(expense_date) = YEAR(CURDATE())', $userId);
    }

    public function getCategoryBreakdown(?int $userId = null): array
    {
        $sql = "SELECT category, COALESCE(SUM(amount), 0) AS total
                FROM expenses WHERE expense_date = CURDATE()";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND created_by = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' GROUP BY category ORDER BY total DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getCategoryBreakdownByDateRange(?string $startDate = null, ?string $endDate = null): array
    {
        $where = '';
        $params = [];
        if ($startDate !== null && $endDate !== null) {
            $where = ' WHERE expense_date >= :start_date AND expense_date < :end_date';
            $params = [
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ];
        }
        $stmt = $this->db->prepare(
            "SELECT category, COALESCE(SUM(amount), 0) AS total
             FROM expenses{$where}
             GROUP BY category ORDER BY total DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getTotalExpenses(?string $startDate = null, ?string $endDate = null): float
    {
        if ($startDate !== null && $endDate !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= :start_date AND expense_date < :end_date"
            );
            $stmt->execute([
                'start_date' => $startDate . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00',
            ]);
            return (float)$stmt->fetchColumn();
        }
        return (float)$this->db->query('SELECT COALESCE(SUM(amount), 0) FROM expenses')->fetchColumn();
    }

    private function aggregate(string $whereClause, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE {$whereClause}";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND created_by = :user_id';
            $params['user_id'] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
}
