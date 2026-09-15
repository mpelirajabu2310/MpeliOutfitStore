-- ============================================================
-- Mpeli Outfit Store — Production Schema Update
-- Date: 2026-09-15
-- Purpose: Release-alignment migration for the 2026-09-15
--          application release (Reports detail records, Business
--          Analysis chart fix, Reports/BI separation).
-- ============================================================
--
-- SAFETY GUARANTEES:
--   * NO existing production data is modified, deleted, or replaced.
--   * NO local/demo/test business data is inserted.
--   * The ONLY INSERT seeds a default CONFIGURATION row for the
--     backup_settings table when that table is empty — it is not
--     business data.
--   * EVERY structural statement is idempotent (safe to run multiple
--     times); columns/indexes/foreign keys are guarded so existing
--     objects are never re-created and never fail on re-run.
--   * No DROP DATABASE, DROP TABLE, TRUNCATE, or DELETE statements.
--
-- WHY THIS FILE EXISTS:
--   The previous migrations (production_migration_2026_09_03.sql and
--   database/migrations/production_schema_update_20260911.sql) may or
--   may not have been applied to the LIVE database. The LIVE schema
--   itself could NOT be inspected directly from the development
--   environment, so this file is a consolidated, idempotent safety net:
--     * It creates late-added infrastructure tables if absent.
--     * It adds (only if absent) the columns/indexes/foreign keys the
--       current application requires.
--     * It re-applies the two app-required views with their current
--       definitions so the application never breaks on stale views.
--   If production already matches the documented 2026-08-21 schema
--   plus the 09-03/09-11 migrations, every statement below is a NO-OP.
--
-- BEFORE RUNNING IN PRODUCTION:
--   1. Take a FRESH cPanel/hosting backup of the LIVE database.
--   2. Review this file and database/migrations/
--      production_schema_update_20260915_notes.md
--   3. Confirm the LIVE database name before importing.
--   4. Run in production only via an approved import step.
--   5. Verify every "migration_check" line prints OK.
--
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- SECTION 1 — INFRASTRUCTURE TABLES (idempotent)
-- Referenced by: services/AuditService.php, api/audit.php,
--                services/BackupService.php, services/MigrationService.php
-- ============================================================

-- 1a. audit_logs (persistent user-action audit trail)
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `user_role` varchar(20) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_id` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_module` (`module`),
  KEY `idx_audit_created_at` (`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_user_action` (`user_id`,`action`),
  KEY `idx_audit_module_created` (`module`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. backup_settings (backup retention policy configuration)
CREATE TABLE IF NOT EXISTS `backup_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `keep_daily` int(10) unsigned NOT NULL DEFAULT 7,
  `keep_weekly` int(10) unsigned NOT NULL DEFAULT 4,
  `keep_monthly` int(10) unsigned NOT NULL DEFAULT 12,
  `keep_full` int(10) unsigned NOT NULL DEFAULT 3,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the default retention CONFIGURATION only if the table is empty.
-- This is one configuration row — NOT business data and NOT copied
-- from the local database.
INSERT INTO `backup_settings` (`keep_daily`, `keep_weekly`, `keep_monthly`, `keep_full`)
SELECT 7, 4, 12, 3
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `backup_settings`);

-- 1c. migration_history (tracked by services/MigrationService.php)
CREATE TABLE IF NOT EXISTS `migration_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(255) NOT NULL,
  `direction` enum('up','down') NOT NULL DEFAULT 'up',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_id` (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SECTION 2 — DEFENSIVE COLUMN / INDEX / FOREIGN-KEY GUARDS
-- No-ops when the objects already exist. Existing rows are never
-- rewritten. All new columns are NULLABLE (or have a safe DEFAULT)
-- so existing production rows remain valid without invented values.
-- ============================================================

-- 2a. products.image_path (product image feature)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_path');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `products` ADD COLUMN `image_path` varchar(255) DEFAULT NULL AFTER `product_name`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. sales.idempotency_key (prevents duplicate sales)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'idempotency_key');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `sales` ADD COLUMN `idempotency_key` varchar(64) DEFAULT NULL AFTER `receipt_number`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND INDEX_NAME = 'idx_sales_idempotency');
SET @ddl := IF(@col_exists = 0 AND @idx_exists = 0,
  'ALTER TABLE `sales` ADD UNIQUE KEY `idx_sales_idempotency` (`idempotency_key`)',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2c. sales.bulk_discount_percent (bulk cart discount)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'bulk_discount_percent');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `sales` ADD COLUMN `bulk_discount_percent` decimal(5,2) DEFAULT NULL AFTER `discount_amount`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2d. expenses.idempotency_key (prevents duplicate expenses)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'idempotency_key');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `expenses` ADD COLUMN `idempotency_key` varchar(64) DEFAULT NULL AFTER `category`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_expenses_idempotency');
SET @ddl := IF(@col_exists = 0 AND @idx_exists = 0,
  'ALTER TABLE `expenses` ADD UNIQUE KEY `idx_expenses_idempotency` (`idempotency_key`)',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2e. sale_items pricing columns (promotions / bulk discount tracking)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'pricing_type');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `sale_items` ADD COLUMN `pricing_type` enum(''normal'',''promotion'',''bulk_discount'',''existing_discount'') NOT NULL DEFAULT ''normal'' AFTER `discount_applied`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'promotion_id');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `sale_items` ADD COLUMN `promotion_id` bigint(20) unsigned DEFAULT NULL AFTER `pricing_type`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'bulk_discount_percent');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `sale_items` ADD COLUMN `bulk_discount_percent` decimal(5,2) DEFAULT NULL AFTER `promotion_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND INDEX_NAME = 'idx_sale_items_promotion');
SET @ddl := IF(@idx_exists = 0,
  'ALTER TABLE `sale_items` ADD INDEX `idx_sale_items_promotion` (`promotion_id`)',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND CONSTRAINT_NAME = 'fk_sale_items_promotion');
SET @ddl := IF(@fk_exists = 0,
  'ALTER TABLE `sale_items` ADD CONSTRAINT `fk_sale_items_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- SECTION 3 — APP-REQUIRED VIEWS (CREATE OR REPLACE = idempotent)
-- product_stock_summary is queried by InventoryService,
-- ReportService and SystemHealthService. best_selling_products is
-- queried by SalesService. Re-applying identical definitions
-- repairs stale/missing views and never affects data.
-- ============================================================

CREATE OR REPLACE VIEW `product_stock_summary` AS
SELECT
  `p`.`id` AS `product_id`,
  `p`.`product_name` AS `product_name`,
  `c`.`name` AS `category_name`,
  coalesce(sum(`pv`.`stock_quantity`),0) AS `total_stock`,
  coalesce(min(`pv`.`reorder_level`),5) AS `reorder_level`,
  `p`.`buying_price` AS `buying_price`,
  `p`.`selling_price` AS `selling_price`,
  `p`.`selling_price` - `p`.`buying_price` AS `profit_per_unit`,
  CASE
    WHEN coalesce(sum(`pv`.`stock_quantity`),0) = 0 THEN 'out_of_stock'
    WHEN coalesce(sum(`pv`.`stock_quantity`),0) <= coalesce(min(`pv`.`reorder_level`),5) THEN 'low_stock'
    ELSE 'in_stock'
  END AS `stock_status`
FROM ((`products` `p`
  JOIN `categories` `c` ON `c`.`id` = `p`.`category_id`)
  LEFT JOIN `product_variants` `pv` ON `pv`.`product_id` = `p`.`id`)
WHERE `p`.`status` = 'active'
GROUP BY `p`.`id`,`p`.`product_name`,`c`.`name`,`p`.`buying_price`,`p`.`selling_price`;

CREATE OR REPLACE VIEW `best_selling_products` AS
SELECT
  `p`.`id` AS `product_id`,
  `p`.`product_name` AS `product_name`,
  `c`.`name` AS `category_name`,
  sum(`si`.`quantity`) AS `units_sold`,
  sum(`si`.`line_total`) AS `revenue`,
  sum(`si`.`line_profit`) AS `profit`
FROM ((((`sale_items` `si`
  JOIN `product_variants` `pv` ON `pv`.`id` = `si`.`variant_id`)
  JOIN `products` `p` ON `p`.`id` = `pv`.`product_id`)
  JOIN `categories` `c` ON `c`.`id` = `p`.`category_id`)
  JOIN `sales` `s` ON `s`.`id` = `si`.`sale_id`)
WHERE `s`.`payment_status` = 'paid'
GROUP BY `p`.`id`,`p`.`product_name`,`c`.`name`
ORDER BY sum(`si`.`quantity`) DESC;

-- ============================================================
-- SECTION 4 — READ-ONLY VERIFICATION (run after apply)
-- ============================================================

-- Infrastructure tables
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: audit_logs table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: backup_settings table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_settings';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: migration_history table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_history';

-- Core feature tables (must already exist in production)
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: promotions table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: promotion_products table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotion_products';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: customers table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: inventory_movements table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_movements';

-- Required views
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: product_stock_summary view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_stock_summary';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: best_selling_products view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'best_selling_products';

-- Defensive columns required by the current application
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: sales.idempotency_key') AS migration_check
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'idempotency_key';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: expenses.idempotency_key') AS migration_check
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'idempotency_key';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: sale_items.pricing_type') AS migration_check
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'pricing_type';

-- ============================================================
-- ROLLBACK (do NOT execute now — see the notes document)
-- ============================================================
-- FORWARD (this file): only ADDs guarded, idempotent objects.
-- ROLLBACK is not required for a no-op run. If infrastructure
-- tables were created by this file and the application must be
-- rolled back, DROP TABLE audit_logs / backup_settings / migration_history
-- (recorded here ONLY for completeness — roll back only with explicit
-- approval and an immediate backup).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- MIGRATION COMPLETE
-- ============================================================
-- Summary:
--   - audit_logs / backup_settings / migration_history ensured present
--   - one default backup retention CONFIG row seeded only if empty
--   - app-required columns/indexes/FKs added only if absent (nullable
--     or safe DEFAULT; existing rows unchanged)
--   - product_stock_summary and best_selling_products views reconciled
--   - all required objects verified (read-only)
--
-- No production record was modified, deleted, replaced, or copied.
-- No local business data was inserted.
-- ============================================================