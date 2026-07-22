# MpeliOutFitStore Database Setup

## Quick Start

### Import the Database to MySQL

```bash
mysql -u root -p < database_clean.sql
```

Or use phpMyAdmin:
1. Go to `http://localhost/phpmyadmin`
2. Click "Import"
3. Select `database_clean.sql`
4. Click "Go"

## Database Information

**Database Name:** `clothing_shop_management`
**Character Set:** UTF-8 (utf8mb4)
**Collation:** utf8mb4_unicode_ci

## Tables Created (13 total)

| Table | Purpose |
|-------|---------|
| `users` | System users (OWNER, SELLER roles) |
| `shop_settings` | Shop configuration & settings |
| `categories` | Product categories |
| `sizes` | Clothing sizes (XS, S, M, L, XL, XXL, etc.) |
| `colors` | Available colors |
| `products` | Main product catalog |
| `product_variants` | Product size/color combinations |
| `inventory_movements` | Stock in/out tracking |
| `customers` | Customer information |
| `sales` | Sales transactions |
| `sale_items` | Items in each sale |
| `payments` | Payment details |
| `expenses` | Business expense tracking |
| `expense_categories` | Expense categories |

## Views Created (4 total)

| View | Purpose |
|------|---------|
| `product_stock_summary` | Real-time inventory status |
| `daily_sales_report` | Daily sales metrics |
| `monthly_profit_report` | Monthly P&L with expenses |
| `best_selling_products` | Top products analysis |

## Key Features

✓ **All data reset** - Database is completely empty and ready for use
✓ **Production ready** - Optimized indexes for performance
✓ **Foreign keys enabled** - Data integrity enforced
✓ **Views included** - Reports ready to query
✓ **Timestamps** - Auto-tracking of creation/update times
✓ **UTF-8 support** - Full multilingual support

## First Time Setup

After importing the database:

1. **Create Owner Account**
   - Go to `http://localhost/MpeliOutFitStore`
   - Click "Create Account"
   - Fill in details and select "OWNER" role
   - This will be your main admin account

2. **Add Default Data**
   - Add categories (T-Shirts, Pants, Dresses, etc.)
   - Add sizes (XS, S, M, L, XL, XXL)
   - Add colors (Red, Blue, Black, White, etc.)
   - Add products
   - Add initial inventory

3. **Configure Shop Settings**
   - Shop name, logo, address
   - Phone and email
   - Currency (default: TSH)
   - Receipt footer text

## Database Connection

Update your `api/db.php` if using a different database name/credentials:

```php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "clothing_shop_management";
```

## Backup & Recovery

### Create a Backup
```bash
mysqldump -u root -p clothing_shop_management > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Restore from Backup
```bash
mysql -u root -p clothing_shop_management < backup_file.sql
```

## Files Removed

The following temporary/migration files have been removed:
- ❌ `database.sql` (old version)
- ❌ `reset_data.sql` (data cleanup only)
- ❌ `migrate_simplify_products.sql` (legacy migration)

## Files Kept

- ✅ `database_clean.sql` - **MAIN DATABASE FILE** - Use this for import

---

**Last Updated:** Production Ready
**Status:** Ready for MySQL Import
