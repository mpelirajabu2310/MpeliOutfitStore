-- ============================================================
-- Mpeli Outfit Store — Production Schema Update
-- Date: 2026-09-11
-- Purpose: Safely add the ONLY schema objects the current
--          application code requires that the documented
--          production schema (2026-08-21) does not contain,
--          while preserving every existing production record.
-- ============================================================
--
-- SAFETY GUARANTEES:
--   * NO existing production data is modified, deleted, or replaced.
--   * NO local/demo/test business data is inserted.
--   * The ONLY INSERT seeds a default CONFIGURATION row for the new
--     backup_settings table when that table is empty — it is not
--     business data.
--   * ALL statements are idempotent (safe to run multiple times).
--   * No DROP DATABASE, DROP TABLE, TRUNCATE, or DELETE statements.
--
-- AUTHORITATIVE SOURCE:
--   Definitions in Section 1 match the local development database
--   (clothing_shop_management) which is the baseline the current
--   application code is developed and tested against.
--
-- WHAT THIS MIGRATION DOES (required changes only):
--   1. CREATE audit_logs      (application audit-trail table)
--   2. CREATE backup_settings (backup retention configuration table)
--      + seed default retention config row only if the table is empty
--
-- DEFENSIVE SECTION (Section 2):
--   Conditional (information_schema-guarded) safety nets for columns,
--   indexes and foreign keys the application requires. They are NO-OPs
--   when the objects already exist, so the file is safe to run on any
--   older production schema.
--
-- BEFORE RUNNING IN PRODUCTION:
--   1. Take a FRESH cPanel/hosting database backup of the LIVE
--      database. Do NOT skip this step.
--   2. Test the file in staging first.
--   3. Run on the LIVE database, e.g.:
--      mysql -u YOUR_USER -p YOUR_DBNAME < production_schema_update_20260911.sql
--   4. Verify every "migration_check" line prints OK.
--
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- SECTION 1 — REQUIRED OBJECTS (application requires these;
--              neither the 2026-08-21 production schema file nor
--              the local production replica contains them)
-- ============================================================

-- ------------------------------------------------------------
-- 1a. audit_logs
-- Purpose: persistent user-action audit trail.
-- Referenced by: api/db.php (_write_audit_db/audit_log),
--                services/AuditService.php, api/audit.php.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 1b. backup_settings
-- Purpose: backup retention policy configuration consumed by
--         services/BackupService.php (which also creates this
--         table dynamically as a fallback).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `backup_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `keep_daily` int(10) unsigned NOT NULL DEFAULT 7,
  `keep_weekly` int(10) unsigned NOT NULL DEFAULT 4,
  `keep_monthly` int(10) unsigned NOT NULL DEFAULT 12,
  `keep_full` int(10) unsigned NOT NULL DEFAULT 3,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the default retention CONFIGURATION only if the table is
-- empty. This is one configuration row — NOT business data and NOT
-- copied from local.
INSERT INTO `backup_settings` (`keep_daily`, `keep_weekly`, `keep_monthly`, `keep_full`)
SELECT 7, 4, 12, 3
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `backup_settings`);

-- ============================================================
-- SECTION 2 — DEFENSIVE SAFETY NETS (conditional)
-- These add columns/indexes/foreign keys the application requires
-- in case the LIVE production database predates the 2026-08-21
-- schema. Each statement is a NO-OP when the object already exists.
-- Existing rows are never rewritten.
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

-- 2c. sales.bulk_discount_percent
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

-- 2e. sale_items promotion/pricing columns (promotions feature)
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

-- 2f. migration_history (referenced by MigrationService; also created
--     dynamically by it, but ensuring existence keeps tooling usable
--     even before a migration run)
CREATE TABLE IF NOT EXISTS `migration_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(255) NOT NULL,
  `direction` enum('up','down') NOT NULL DEFAULT 'up',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_id` (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SECTION 3 — VERIFICATION (read-only checks; run after apply)
-- ============================================================

-- Required tables
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: audit_logs table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: backup_settings table') AS migration_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_settings';

-- Required base tables (must already exist in production)
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

-- Required views (if any reports MISSING below, the app needs the
-- CREATE OR REPLACE VIEW definitions from
-- database/mpelioutfitstore_production_schema.sql applied first)
SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: product_stock_summary view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_stock_summary';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: best_selling_products view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'best_selling_products';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: daily_sales_report view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_sales_report';

SELECT IF(COUNT(*) > 0, 'OK', 'MISSING: monthly_profit_report view') AS migration_check
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_profit_report';

-- ============================================================
-- ROLLBACK (do NOT execute now — see report Section 8)
-- ============================================================
-- FORWARD:
--   CREATE TABLE audit_logs ...          (Section 1a)
--   CREATE TABLE backup_settings ...     (Section 1b)
--
-- ROLLBACK:
--   DROP TABLE audit_logs;
--   DROP TABLE backup_settings;
--   -- WARNING: DROP destroys any audit rows / retention config
--   -- written AFTER this migration. Roll back only immediately after
--   -- applying and before the app records new entries. Recorded here
--   -- for completeness ONLY — the production team must decide before
--   -- any rollback is executed.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- MIGRATION COMPLETE
-- ============================================================
-- Summary:
--   - audit_logs and backup_settings created if absent
--   - one default backup retention CONFIG row seeded only if empty
--   - defensive column/index/FK additions are NO-OPs on the
--     2026-08-21 production schema (already present)
--   - migration_history ensured present
--   - required table/view presence verified (read-only)
--
-- No production record was modified, deleted, replaced, or copied.
-- No local business data was inserted.
-- ============================================================