# Mpeli OutFit Store — Clothing Shop Management System

A production-ready PHP/MySQL point-of-sale and inventory management system for clothing shops.

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` enabled

## Installation

1. Upload all project files to your hosting `public_html` directory (or XAMPP `htdocs`).

2. Create a MySQL database and import the schema:

```bash
mysql -u root -p your_database < database/clothing_shop_management.sql
```

3. Edit the database configuration:

```php
// config/database.php
$host     = '127.0.0.1';
$database = 'your_database_name';
$username = 'your_mysql_user';
$password = 'your_mysql_password';
```

4. Set directory permissions:

```
logs/          → 755 (writable)
logs/ratelimit/ → 755 (writable)
locales/       → 755
assets/        → 755
config/        → 644
services/      → 644
api/           → 644
```

5. Open your site in a browser and create the OWNER account on first visit.

## Project Structure

```
/
├── index.php              Main application entry point
├── .htaccess              Apache security and caching rules
├── config/
│   └── database.php       Database connection (PDO singleton)
├── api/                   REST API endpoints (all JSON responses)
│   ├── db.php             Session, CSRF, auth helpers, rate limiting
│   ├── login.php          User authentication
│   ├── logout.php         Session destruction
│   ├── me.php             Current user + health check
│   ├── register_owner.php First OWNER account creation
│   ├── recover_owner.php  Password recovery (token-based)
│   ├── reset_password.php Change password
│   ├── products.php       Product CRUD
│   ├── sales.php          Sale transactions (POS)
│   ├── expenses.php       Expense tracking
│   ├── inventory.php      Stock management
│   ├── reports.php        Report statistics
│   ├── generate_report.php CSV/JSON report export
│   ├── dashboard.php      Dashboard summary
│   ├── users.php          User management
│   ├── settings.php       Shop settings
│   ├── health.php         System health check
│   ├── maintenance.php    Maintenance mode toggle
│   └── backup.php         Database backup/restore
├── services/              Business logic layer
│   ├── BaseService.php    Abstract base with PDO
│   ├── ProductService.php Product CRUD + stock
│   ├── SalesService.php   Sale transactions
│   ├── ExpenseService.php Expense CRUD
│   ├── ProfitService.php  All profit calculations
│   ├── InventoryService.php Stock management
│   ├── DashboardService.php Dashboard assembly
│   ├── ReportService.php  Report generation
│   ├── PermissionService.php Role-permission matrix
│   ├── MigrationService.php Database migration
│   └── SystemHealthService.php Health checks + maintenance
├── assets/
│   ├── css/styles.css     All styles + responsive design
│   ├── js/script.js       Application logic
│   └── images/            Logo and background images
├── database/
│   └── clothing_shop_management.sql  Schema (no sample data)
├── locales/               Translation files
│   ├── en.json            English
│   └── sw.json            Swahili
├── logs/                  Application logs (blocked from web)
└── _dev/                  Development tools (blocked from web)
```

## Roles

| Role | Access |
|------|--------|
| **OWNER** | Full access: products, sales, reports, profit, expenses, user management, settings |
| **SELLER** | POS sales, read-only products; no settings, expenses, or user management |

## API Endpoints

All API endpoints return JSON. Include `X-CSRF-Token` header for state-changing requests.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/login.php` | POST | Authenticate user |
| `api/logout.php` | POST | Destroy session |
| `api/me.php` | GET | Current user + health status |
| `api/register_owner.php` | POST | Create first OWNER |
| `api/products.php` | GET/POST/PUT/DELETE | Product management |
| `api/sales.php` | GET/POST | Sale transactions |
| `api/expenses.php` | GET/POST/PUT/DELETE | Expense tracking |
| `api/inventory.php` | GET | Stock levels |
| `api/reports.php` | GET | Report statistics |
| `api/generate_report.php` | GET | Export reports (CSV/JSON) |
| `api/dashboard.php` | GET | Dashboard summary |
| `api/users.php` | GET/POST/PUT/DELETE | User management |
| `api/settings.php` | GET/PUT | Shop settings |
| `api/health.php` | GET | System health check |
| `api/maintenance.php` | POST | Toggle maintenance mode |
| `api/backup.php` | POST | Database backup/restore |

## Currency

All amounts are displayed in **TSH** (Tanzanian Shilling).

## Languages

The interface supports English and Swahili. Selected language is saved in browser `localStorage`.

## Security Features

- Session-based authentication with idle timeout
- CSRF token protection on all state-changing requests
- IP-based rate limiting on login/recovery endpoints
- Security headers (CSP, X-Frame-Options, etc.)
- Input validation and SQL injection prevention
- Password hashing with `password_hash()`
- Activity logging for audit trail
- Health check system for startup validation
- Maintenance mode for controlled downtime
