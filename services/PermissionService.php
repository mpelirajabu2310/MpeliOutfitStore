<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class PermissionService
{
    private const PERMISSIONS = [
        'OWNER' => [
            'dashboard.view',
            'dashboard.view_financials',
            'dashboard.view_charts',
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'sales.view_all',
            'sales.create',
            'sales.view_profit',
            'inventory.view',
            'inventory.manage',
            'reports.view',
            'reports.generate',
            'reports.view_financials',
            'expenses.view_all',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
            'users.view',
            'users.create',
            'users.update',
            'users.disable',
            'settings.view',
            'settings.update',
            'maintenance.manage',
            'backup.manage',
            'migration.run',
        ],
        'SELLER' => [
            'dashboard.view',
            'products.view',
            'sales.create',
            'sales.view_own',
            'reports.view_own',
            'expenses.create',
            'expenses.view_own',
        ],
    ];

    public static function getPermissions(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }

    public static function hasPermission(string $role, string $permission): bool
    {
        $perms = self::getPermissions($role);
        return in_array($permission, $perms, true);
    }

    public static function requirePermission(string $role, string $permission): void
    {
        if (!self::hasPermission($role, $permission)) {
            respond(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
    }

    public static function requireAnyPermission(string $role, array $permissions): void
    {
        foreach ($permissions as $perm) {
            if (self::hasPermission($role, $perm)) {
                return;
            }
        }
        respond(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }

    public static function canViewFinancials(string $role): bool
    {
        return self::hasPermission($role, 'dashboard.view_financials');
    }

    public static function canViewAllSales(string $role): bool
    {
        return self::hasPermission($role, 'sales.view_all');
    }

    public static function canViewOwnSales(string $role): bool
    {
        return self::hasPermission($role, 'sales.view_own');
    }

    public static function canManageProducts(string $role): bool
    {
        return self::hasPermission($role, 'products.create');
    }

    public static function canViewProfit(string $role): bool
    {
        return self::hasPermission($role, 'sales.view_profit');
    }

    public static function canManageUsers(string $role): bool
    {
        return self::hasPermission($role, 'users.create');
    }

    public static function canManageSettings(string $role): bool
    {
        return self::hasPermission($role, 'settings.update');
    }

    public static function canManageExpenses(string $role): bool
    {
        return self::hasPermission($role, 'expenses.update');
    }

    public static function getSellerPermissions(): array
    {
        return self::PERMISSIONS['SELLER'] ?? [];
    }

    public static function getOwnerPermissions(): array
    {
        return self::PERMISSIONS['OWNER'] ?? [];
    }
}
