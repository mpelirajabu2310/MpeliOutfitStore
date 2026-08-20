# Mpeli OutFit Store — Production Deployment Guide

A production-ready PHP/MySQL point-of-sale and inventory management system for clothing shops.

---

## Server Requirements

| Requirement | Minimum |
|-------------|---------|
| **PHP** | 8.0+ |
| **MySQL** | 5.7+ / MariaDB 10.3+ |
| **Web Server** | Apache with `mod_rewrite` (nginx supported with config) |
| **PHP Extensions** | `pdo_mysql`, `gd`, `mbstring`, `json`, `session`, `zlib` |
| **PHP Settings** | `upload_max_filesize = 2M`, `post_max_size = 8M`, `memory_limit = 128M` |

---

## cPanel / Namecheap Deployment

### Step 1 — Upload Files

Upload the entire project to your hosting `public_html` directory.

Using **cPanel File Manager**:
1. Compress the project folder into a `.zip` file
2. Upload the `.zip` to `public_html/`
3. Extract the archive inside `public_html/`

Using **FTP** (FileZilla, etc.):
1. Connect to your hosting via FTP
2. Upload all files directly into `public_html/`

### Step 2 — Create Database

1. Log into cPanel → **MySQL Databases**
2. Create a new database: `clothing_shop_management`
3. Create a new database user with a **strong password**
4. Add the user to the database with **ALL PRIVILEGES**

### Step 3 — Import Schema

1. cPanel → **phpMyAdmin**
2. Select your `clothing_shop_management` database
3. Click **Import** tab
4. Upload `database/mpelioutfitstore_production_schema.sql`
5. Click **Go** to execute

### Step 4 — Configure Database Connection

Edit `config/database.php` — the file supports environment variables:

**Option A — Environment variables (recommended for production):**

Set these in cPanel → **PHP Variables** or via `.htaccess`:
```
DB_HOST=127.0.0.1
DB_NAME=your_cpanel_username_clothing_shop_management
DB_USER=your_cpanel_username_your_db_user
DB_PASS=your_strong_password
```

**Option B — Edit config/database.php directly:**

Change the defaults on lines 11–14:
```php
$host     = getenv('DB_HOST') ?: '127.0.0.1';
$database = getenv('DB_NAME') ?: 'clothing_shop_management';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
```

### Step 5 — Set Directory Permissions

These directories must be writable by the web server:
```
logs/              → 755
logs/ratelimit/    → 755
uploads/           → 755
uploads/products/  → 755
```

### Step 6 — Enable SSL

After installing an SSL certificate (cPanel → **SSL/TLS**):
1. Uncomment the HTTPS redirect in `.htaccess` (lines 6–8):
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Step 7 — Open Your Site

Visit `https://yourdomain.com` in a browser. The application will prompt you to create the first **OWNER** account.

---

## Local Development (XAMPP)

1. Clone/copy the project into `C:\xampp\htdocs\MpeliOutFitStore\`
2. Start Apache and MySQL from XAMPP Control Panel
3. The default `config/database.php` already works with XAMPP defaults (`root`/no password)
4. Import `database/mpelioutfitstore_production_schema.sql` via phpMyAdmin
5. Open `http://localhost/MpeliOutFitStore/`

---

## Project Structure

```
/
├── index.php                    Main SPA entry point
├── .htaccess                    Apache security, caching, compression
├── .cpanel.yml                  cPanel deployment config
├── config/
│   ├── database.php             Database connection (PDO, env-overridable)
│   ├── uploads.php              Upload limits, allowed formats
│   └── .htaccess                Blocks web access to config/
├── api/                         REST API endpoints (JSON responses)
│   ├── db.php                   Session, CSRF, auth, rate limiting, exception handler
│   ├── login.php                Authentication
│   ├── logout.php               Session destruction
│   ├── me.php                   Current user + health check
│   ├── register_owner.php       First OWNER account creation
│   ├── recover_owner.php        Password recovery (token-based)
│   ├── reset_password.php       Change password
│   ├── products.php             Product CRUD
│   ├── product_image.php        Image upload/optimization
│   ├── sales.php                Sale transactions (POS)
│   ├── sale_details.php         Individual sale details
│   ├── expenses.php             Expense tracking
│   ├── inventory.php            Stock management
│   ├── promotions.php           Promotion management
│   ├── reports.php              Report statistics
│   ├── generate_report.php      PDF/XLSX report export
│   ├── dashboard.php            Dashboard summary
│   ├── users.php                User management
│   ├── settings.php             Shop settings
│   ├── health.php               System health check
│   ├── heartbeat.php            Session keepalive
│   ├── maintenance.php          Maintenance mode toggle
│   └── backup.php               Database backup/restore
├── services/                    Business logic layer (16 service classes)
│   ├── BaseService.php          Abstract PDO base
│   ├── ProductService.php       Product CRUD + stock
│   ├── SalesService.php         Sale transactions
│   ├── ExpenseService.php       Expense CRUD
│   ├── ProfitService.php        All profit calculations
│   ├── InventoryService.php     Stock management
│   ├── PromotionService.php     Discount/promotion engine
│   ├── DashboardService.php     Dashboard assembly
│   ├── ReportService.php        Report data generation
│   ├── ReportPeriodHelper.php   Period resolution
│   ├── PdfReportService.php     PDF generation (GD-based)
│   ├── ExcelReportService.php   XLSX generation (custom ZIP/OOXML)
│   ├── ImageService.php         Image upload, resize, WebP optimization
│   ├── PermissionService.php    Role-permission matrix
│   ├── MigrationService.php     Database migration framework
│   └── SystemHealthService.php  Health checks + maintenance
├── assets/
│   ├── css/styles.css           All styles + responsive design
│   ├── js/script.js             Application logic
│   └── images/                  Logo, favicons, login background
├── database/
│   ├── clothing_shop_management.sql        Full schema
│   └── mpelioutfitstore_production_schema.sql  Clean production schema
├── locales/                     Translation files
│   ├── en.json                  English (454 keys)
│   └── sw.json                  Swahili
├── uploads/                     User-uploaded content (blocked from script execution)
│   └── products/                Product images (WebP optimized)
├── logs/                        Application logs (blocked from web)
└── _dev/                        Development tools (blocked from web, excluded from deploy)
```

---

## Roles & Permissions

| Role | Access |
|------|--------|
| **OWNER** | Full access: products, sales, reports, profit, expenses, promotions, inventory, user management, settings, backup |
| **SELLER** | POS sales, read-only products; no settings, expenses, reports, or user management |

---

## API Endpoints

All endpoints return JSON. State-changing requests require `X-CSRF-Token` header.

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `api/login.php` | POST | — | Authenticate user |
| `api/logout.php` | POST | User | Destroy session |
| `api/me.php` | GET | User | Current user + health status |
| `api/register_owner.php` | POST | — | Create first OWNER |
| `api/recover_owner.php` | POST | — | Password recovery |
| `api/reset_password.php` | POST | User | Change password |
| `api/products.php` | GET/POST/PUT/DELETE | User | Product management |
| `api/product_image.php` | POST/DELETE | User | Image upload/remove |
| `api/sales.php` | GET/POST | User | Sale transactions |
| `api/sale_details.php` | GET | User | Individual sale details |
| `api/expenses.php` | GET/POST/PUT/DELETE | User | Expense tracking |
| `api/inventory.php` | GET | Owner | Stock levels |
| `api/promotions.php` | GET/POST/PUT/DELETE | Owner | Promotion management |
| `api/reports.php` | GET | Owner | Report statistics |
| `api/generate_report.php` | GET | Owner | Export reports (PDF/XLSX) |
| `api/dashboard.php` | GET | User | Dashboard summary |
| `api/users.php` | GET/POST/PUT/DELETE | Owner | User management |
| `api/settings.php` | GET/PUT | Owner | Shop settings |
| `api/health.php` | GET | — | System health check |
| `api/heartbeat.php` | POST | User | Session keepalive |
| `api/maintenance.php` | POST | Owner | Toggle maintenance mode |
| `api/backup.php` | POST | Owner | Database backup/restore |

---

## Currency

All amounts are displayed in **TSH** (Tanzanian Shillings).

## Languages

The interface supports **English** and **Swahili**. Selected language is saved in browser `localStorage`.

---

## Security Features

- Session-based authentication with configurable idle timeout (3 min default)
- CSRF token protection on all state-changing requests
- IP-based rate limiting on login/recovery/registration endpoints
- Security headers: CSP, X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy
- Global exception handler (no stack traces in production)
- Input validation and parameterized SQL queries (PDO prepared statements)
- Password hashing with `password_hash()` (bcrypt)
- Activity logging for audit trail
- File upload validation (MIME type, extension, dimensions, file size)
- Script execution blocked in uploads/ and sensitive directories via `.htaccess`
- Config directory protected from web access

---

## Common Errors & Solutions

| Error | Solution |
|-------|----------|
| **Blank page / 500 error** | Check `logs/activity.log`. Ensure `config/database.php` has correct credentials. |
| **"An internal error occurred"** | PHP fatal error. Check error log. Verify all PHP extensions are installed. |
| **Images not uploading** | Verify `uploads/products/` is writable (755). Check PHP `upload_max_filesize`. |
| **PDF/XLSX export fails** | Ensure `gd` extension is installed and enabled. |
| **CSRF token mismatch** | Clear browser cookies and refresh the page. |
| **Session expires immediately** | Ensure `logs/` and `logs/ratelimit/` directories exist and are writable. |
| **Styles not loading** | Clear browser cache. Verify `assets/css/styles.css` exists. |
| **Charts not rendering** | Verify `assets/js/script.js` loads. Check browser console for errors. |
