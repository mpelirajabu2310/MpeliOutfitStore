# Migration Notes — production_schema_update_20260915.sql

**Release:** 2026-09-15 (Reports detail records + Business Analysis chart fix + Reports/BI separation)
**Affected database:** live production database (Namecheap/cPanel `cpXXX_...` database — confirm the exact name before importing)
**Status:** REQUIRES MANUAL PRODUCTION-SCHEMA REVIEW BEFORE IMPORT

> The LIVE production schema could not be inspected directly from this
> development environment (no SSH/DB network access configured). This
> migration is therefore a **consolidated, idempotent safety net** based on:
>  1. the documented production schema `database/mpelioutfitstore_production_schema.sql` (2026-08-21),
>  2. the two prior migration files, and
>  3. everything the current application code actually queries.
>
> It is safe to run whether or not the prior 09-03 / 09-11 migrations were applied.
> When production already matches the documented schema + prior migrations, every
> statement is a no-op.

---

## 1. Statement-by-statement documentation

### SECTION 1 — Infrastructure tables (CREATE TABLE IF NOT EXISTS)

| # | Statement | Table(s) | Current production state | Required state | Reason | Existing rows changed? | Risk | Reversible | Manual approval required? |
|---|-----------|----------|--------------------------|----------------|--------|------------------------|------|------------|----------------------------|
| 1a | `CREATE TABLE IF NOT EXISTS audit_logs` | audit_logs | Missing if 09-03/09-11 not applied | Present | Audit trail for AuditService/api/audit.php | No | Low | Reverse = `DROP TABLE audit_logs` (destructive — approval needed) | Yes if it must be created; no-op otherwise |
| 1b | `CREATE TABLE IF NOT EXISTS backup_settings` + seed default config row if empty | backup_settings | Missing if 09-03/09-11 not applied | Present | Backup retention config for BackupService | No | Low | Reverse = `DROP TABLE backup_settings` (destructive) | Yes if created |
| 1c | `CREATE TABLE IF NOT EXISTS migration_history` | migration_history | Missing if 09-03/09-11 not applied | Present | Migration tracking for MigrationService | No | Low | Reverse = drop table | Yes if created |

Notes:
- The `backup_settings` INSERT is a **configuration** row (retention: daily 7 / weekly 4 / monthly 12 / full 3), seeded **only when the table is empty**. It is not business data and not copied from the local database. Same pattern as the already-committed 09-03/09-11 migrations.
- 1a / 1b / 1c were already shipped inside `production_schema_update_20260911.sql`. If that file was applied, these are no-ops.

### SECTION 2 — Defensive column / index / foreign-key guards (information_schema-guarded)

| # | Statement (conditional) | Table.Column | Current production state | Required state | Reason | Existing rows changed? | Risk | Reversible | Manual approval required? |
|---|-------------------------|--------------|--------------------------|----------------|--------|------------------------|------|------------|----------------------------|
| 2a | `ADD COLUMN image_path varchar(255) NULL` | products.image_path | Missing on pre-08-21 schemas | Present, nullable | Product image feature | No — nullable | Low | `ALTER TABLE products DROP COLUMN image_path` | No (no-op when present) |
| 2b | `ADD COLUMN idempotency_key varchar(64) NULL` + `UNIQUE KEY idx_sales_idempotency` | sales.idempotency_key | Missing on pre-08-21 schemas | Present, nullable | Duplicate-sale prevention (createSale request dedup) | No | Low–Med (UNIQUE on nullable col; NULLs don't collide) | Drop column/index | No |
| 2c | `ADD COLUMN bulk_discount_percent decimal(5,2) NULL` | sales.bulk_discount_percent | Missing on pre-08-21 schemas | Present, nullable | Bulk cart discount tracking | No | Low | Drop column | No |
| 2d | `ADD COLUMN idempotency_key varchar(64) NULL` + `UNIQUE KEY idx_expenses_idempotency` | expenses.idempotency_key | Missing on pre-08-21 schemas | Present, nullable | Duplicate-expense prevention | No | Low–Med | Drop column/index | No |
| 2e | `ADD COLUMN pricing_type ENUM(...) NOT NULL DEFAULT 'normal'` | sale_items.pricing_type | Missing on pre-08-21 schemas | Present, default 'normal' | Pricing mechanism tracking (promotion / bulk / manual) | No — NOT NULL with safe DEFAULT | Low | Drop column | No |
| 2f | `ADD COLUMN promotion_id bigint NULL` + `INDEX idx_sale_items_promotion` + `FK fk_sale_items_promotion ON DELETE SET NULL` | sale_items.promotion_id | Missing on pre-08-21 schemas | Present, nullable FK | Promotion lineage per line item | No — nullable, FK SET NULL never rewrites | Low–Med | Drop FK, index, column | No |

Notes:
- All new columns are **NULLABLE or have a safe DEFAULT** so existing production rows stay valid without inventing values.
- The guarded pattern (`information_schema` check + `SET @ddl := IF(...)`) is a **no-op when the object already exists**, so re-running is safe.

### SECTION 3 — App-required views (CREATE OR REPLACE VIEW)

| # | View | Current production state | Required state | Reason | Existing rows changed? | Risk | Reversible | Manual approval? |
|---|------|--------------------------|----------------|--------|------------------------|------|------------|------------------|
| 3a | `product_stock_summary` | Present (doc schema) or stale | Matches current definition | Queried by InventoryService, ReportService, SystemHealthService (columns `total_stock`, `reorder_level`, `profit_per_unit`, `stock_status`) | No — views hold no data | Low | `CREATE OR REPLACE VIEW` with the old definition | No |
| 3b | `best_selling_products` | Present (doc schema) or stale | Matches current definition | Queried by SalesService | No | Low | Same | No |

Note: `daily_sales_report` and `monthly_profit_report` views are **not** referenced by current application code. They are left **unchanged** (preserved). The migration only reconciles the two views the code actually reads.

### SECTION 4 — Read-only verification queries
Twelve `SELECT ... AS migration_check` statements return `OK` when required tables/columns/views exist. They modify nothing.

---

## 2. What this migration deliberately does NOT do

- No `DROP DATABASE` / `DROP TABLE` / `TRUNCATE` / `DELETE` / mass `UPDATE`.
- No `INSERT` of products, sales, expenses, users, customers, or inventory.
- No local database dump, no replacement of live data.
- Does **not** add the local-only `orders` / `order_items` tables or the unreferenced
  `expenses.previous_amount` / `edited_by` / `edited_at` columns — the application does
  not use them, so they are intentionally **not** deployed to production.

## 3. Existing-production-row handling

No statement writes or rewrites existing rows. The one write is the `backup_settings`
configuration seed that runs **only on an empty table**.
If any future release needs a business-derived value for existing rows, that requires a
separate documented, business-approved plan — nothing like that is included here.

## 4. Idempotency

Every statement is idempotent. The file was validated by applying it **twice** to a
scratch database built from the documented production schema: both runs printed
`OK` for all 12 verification checks and no errors were raised.

## 5. Recommended verification → import → post-check sequence (manual)

1. **Backup (MANDATORY):** create a fresh backup of the LIVE database via
   cPanel → Backup / phpMyAdmin export. Verify the backup file exists and is non-empty.
2. **Review:** confirm the LIVE database name and that this file + notes describe only
   approved schema changes.
3. **Pre-check (read-only):** with a read-only DB user, diff the live schema against
   Section 4 expectations; record which objects/columns are already present.
4. **Import (approved step):**
   `mysql -u USER -p DBNAME < production_schema_update_20260915.sql`
   (adjust for the hosting mysql client path).
5. **Post-check (read-only):** confirm every `migration_check` prints `OK`;
   verify Sales Trend / Profit Trend charts and Reports detail tables load real data.
6. **Monitor:** review hosting PHP/MySQL error logs for 24 h.

## 6. Rollback

- A no-op run needs no rollback.
- If 1a/1b/1c actually created tables and rollback is truly required: drop those tables
  (destructive — counts/configuration written afterwards would be lost). Recorded here for
  completeness only; do **not** roll back without explicit approval.
- Columns/indexes from Section 2 can be dropped individually via `ALTER TABLE ... DROP ...`
  if a feature must be disabled.