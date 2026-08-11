<?php
declare(strict_types=1);

/**
 * Shared report period + category rules.
 *
 * Keeps dashboard analytics and generated reports on exactly the same
 * date ranges and category whitelists, so values can never diverge.
 */
class ReportPeriodHelper
{
    public const PERIODS = ['today', 'week', 'month', 'year', 'custom'];

    public const OWNER_CATEGORIES = [
        'sales',
        'revenue',
        'expenses',
        'profit',
        'purchases',
        'inventory',
        'stock_movement',
        'seller_performance',
        'customers',
        'transactions',
        'products',
    ];

    public const SELLER_CATEGORIES = [
        'sales',
        'revenue',
        'expenses',
        'transactions',
    ];

    public static function resolvePeriod(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $today = new DateTimeImmutable('today');
        $start = null;
        $end = null;

        switch ($period) {
            case 'today':
                $start = $today->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'week':
                // Rolling 7 days (matches SalesService::getWeeklySales).
                $start = $today->modify('-6 days')->format('Y-m-d');
                $end = $today->format('Y-m-d');
                break;
            case 'month':
                $start = $today->format('Y-m-01');
                $end = $today->format('Y-m-d');
                break;
            case 'year':
                $start = $today->format('Y-01-01');
                $end = $today->format('Y-m-d');
                break;
            case 'custom':
                if ($startDate === null || $endDate === null) {
                    throw new InvalidArgumentException('Custom date range requires both start and end dates.');
                }
                $start = $startDate;
                $end = $endDate;
                break;
            default:
                throw new InvalidArgumentException('Invalid report period.');
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function categoriesForRole(string $role): array
    {
        return $role === 'OWNER' ? self::OWNER_CATEGORIES : self::SELLER_CATEGORIES;
    }

    public static function filterCategories(string $role, array $categories): array
    {
        $allowed = self::categoriesForRole($role);
        $filtered = array_values(array_intersect($allowed, $categories));
        return array_values(array_unique($filtered));
    }

    public static function isValidPeriod(string $period): bool
    {
        return in_array($period, self::PERIODS, true);
    }
}
