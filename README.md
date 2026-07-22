# Clothing Shop Management System

Frontend: HTML, CSS, JavaScript  
Backend: PHP API  
Database: MySQL

## XAMPP Setup

1. Start XAMPP.
2. Start Apache and MySQL.
3. Put this project folder inside:

```text
C:\xampp\htdocs\MpeliOutFitStore
```

4. Import the database schema (empty, no sample data):

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\MpeliOutFitStore\database.sql
```

If your root password is empty, omit `-p`. If you have a password, add `-p` and enter it when prompted.

5. Open the system in your browser:

```text
http://localhost/MpeliOutFitStore/
```

6. On first visit, create the **OWNER** account (no users exist yet). Then sign in and add products, employees, and sales manually.

## Reset data (keep schema)

To clear all rows and start fresh without re-importing the schema:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\MpeliOutFitStore\reset_data.sql
```

You will need to create a new OWNER account after running this script.

## Database Connection

The MySQL connection is configured in:

```text
api\db.php
```

Default XAMPP settings:

```php
$host = '127.0.0.1';
$database = 'clothing_shop_management';
$username = 'root';
$password = '';
```

If your MySQL root user has a password, update `$password`.

## Roles

| Role | Access |
|------|--------|
| **OWNER** | Full access: products, sales, reports, profit, expenses, user management, settings |
| **SELLER** | POS sales, read-only products; no settings, expenses, or user management |

Employees are created by the OWNER from **Users** in the dashboard.

## API Endpoints

```text
api/login.php
api/logout.php
api/me.php
api/register_owner.php
api/dashboard.php
api/reports.php
api/inventory.php
api/products.php
api/sales.php
api/expenses.php
api/users.php
api/settings.php
api/generate_report.php
```

## Currency

All amounts display as **TSH** (Tanzania Shilling).

## Upgrade existing database

If you already imported an older schema with SKU/image columns:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\MpeliOutFitStore\migrate_simplify_products.sql
```

## Languages

The interface supports English and Swahili.

Translation files:

```text
locales/en.json
locales/sw.json
```

The selected language is saved in the browser with `localStorage`.
