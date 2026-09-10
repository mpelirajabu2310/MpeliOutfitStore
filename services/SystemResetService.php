<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * SystemResetService — Factory reset of business/operational data for
 * Mpeli Outfit Store.
 *
 * SAFETY CONTRACT (enforced here and by the API layer):
 *   - User accounts, roles, permissions, system config and DB schema are
 *     ALWAYS preserved. Users are only ever deleted through the existing
 *     user-management system.
 *   - Business tables are cleared in foreign-key-safe order inside a single
 *     transaction; AUTO_INCREMENT counters are reset for business tables only.
 *   - A full database backup must be taken (by the caller) BEFORE the reset.
 *   - Product image files under uploads/products/ are removed (referenced
 *     files plus any orphans), excluding the reserved index.html/.htaccess.
 *
 * FH-safe delete order (children before parents):
 *   sale_items, payments, sales, order_items, orders, inventory_movements,
 *   promotion_products, promotions, product_variants, products, expenses,
 *   customers, categories.
 *
 * Preserved tables: users, audit_logs, shop_settings, backup_settings,
 * migration_history (and all views, which reference preserved tables).
 */
class SystemResetService
{
    private PDO $db;

    /** Business tables cleared, in FK-safe delete order. */
    private const BUSINESS_TABLES = [
        'sale_items',
        'payments',
        'sales',
        'order_items',
        'orders',
        'inventory_movements',
        'promotion_products',
        'promotions',
        'product_variants',
        'products',
        'expenses',
        'customers',
        'categories',
    ];

    /** Tables that must never be touched by a business reset. */
    private const PRESERVED_TABLES = [
        'users',
        'audit_logs',
        'shop_settings',
        'backup_settings',
        'migration_history',
    ];

    /** Files under uploads/products/ that are always kept. */
    private const RESERVED_FILES = ['index.html', '.htaccess'];

    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?? get_db();
    }

    /**
     * Perform a full business-data reset.
     *
     * Assumes a successful backup was already created. Returns summary with
     * per-table row counts that were removed.
     *
     * The DATA clearing is atomic and rollback-safe: all DELETE statements run
     * inside a single transaction. AUTO_INCREMENT counters are reset AFTER the
     * commit because MySQL implicitly commits the open transaction on ALTER
     * TABLE — doing it earlier would silently break transactional isolation.
     *
     * @return array{success: bool, removed: array<string,int>, images_deleted: int, message: string}
     */
    public function reset(): array
    {
        $removed = [];

        // Record counts before clearing so we can report what was removed.
        foreach (self::BUSINESS_TABLES as $table) {
            if ($this->tableExists($table)) {
                $removed[$table] = (int)$this->db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            } else {
                $removed[$table] = 0;
            }
        }

        // Collect product image paths BEFORE wiping products/images DB refs.
        $imagePaths = $this->collectProductImages();

        // ── Atomic data clearing (rollback-safe) ──────────────────────────────
        $this->db->beginTransaction();
        try {
            foreach (self::BUSINESS_TABLES as $table) {
                if ($this->tableExists($table)) {
                    $this->db->exec("DELETE FROM `{$table}`");
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[system_reset] data clearing failed: ' . $e->getMessage());
            throw new RuntimeException('System reset failed: ' . $e->getMessage());
        }

        // ── Business counter reset (post-commit; idempotent & safe) ──────────
        // ALTER TABLE implicitly commits an open transaction in MySQL, so this
        // intentionally runs after the data transaction has committed. Failures
        // here are non-fatal: the data is already cleared and the counters are
        // only cosmetic/prevention. Any remaining rows tied to a counter are
        // irrelevant because the tables are empty.
        foreach (self::BUSINESS_TABLES as $table) {
            if ($this->tableExists($table)) {
                try {
                    $this->db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                } catch (Throwable $e) {
                    error_log('[system_reset] AUTO_INCREMENT reset skipped for ' . $table . ': ' . $e->getMessage());
                }
            }
        }

        // Files are removed only after the DB transaction committed, so a DB
        // failure never orphans/loses files.
        $imagesDeleted = $this->cleanupProductImages($imagePaths);

        return [
            'success'        => true,
            'removed'        => $removed,
            'images_deleted' => $imagesDeleted,
            'message'        => 'System data reset completed successfully.',
        ];
    }

    /**
     * Return the list of business tables that will be cleared (informational).
     */
    public function businessTables(): array
    {
        return array_values(self::BUSINESS_TABLES);
    }

    private function collectProductImages(): array
    {
        $paths = [];
        if (!$this->tableExists('products')) {
            return $paths;
        }
        $stmt = $this->db->query("SELECT image_path FROM products WHERE image_path IS NOT NULL AND image_path <> ''");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($rows as $pathRow) {
            if (is_string($pathRow) && $pathRow !== '') {
                $paths[] = $pathRow;
            }
        }
        return $paths;
    }

    private function cleanupProductImages(array $dbPaths): int
    {
        $dir = __DIR__ . '/../uploads/products';
        if (!is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        $toDelete = [];

        // 1) Files explicitly referenced by products.image_path.
        foreach ($dbPaths as $rel) {
            $name = basename((string)$rel);
            if ($name === '' || in_array($name, self::RESERVED_FILES, true)) {
                continue;
            }
            $abs = $dir . DIRECTORY_SEPARATOR . $name;
            $resolved = realpath($abs);
            $dirReal = realpath($dir);
            if ($resolved !== false && $dirReal !== false && strpos($resolved, $dirReal) === 0) {
                $toDelete[$resolved] = true;
            }
        }

        // 2) Also sweep the directory for any leftover business files, keeping
        //    only the reserved index.html/.htaccess.
        $files = glob($dir . '/*');
        foreach ($files ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            if (in_array(basename($f), self::RESERVED_FILES, true)) {
                continue;
            }
            $toDelete[$f] = true;
        }

        foreach (array_keys($toDelete) as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name"
        );
        $stmt->execute(['name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
