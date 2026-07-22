-- Schema only: no sample products, sales, customers, or users.
-- Create the first OWNER account from the app login screen.
DROP DATABASE IF EXISTS clothing_shop_management;

CREATE DATABASE clothing_shop_management
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE clothing_shop_management;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(160) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('OWNER', 'SELLER') NOT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  last_login_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE shop_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_name VARCHAR(160),
  logo_url VARCHAR(500),
  address VARCHAR(255),
  phone VARCHAR(40),
  email VARCHAR(160),
  currency_code CHAR(3) NOT NULL DEFAULT 'TSH',
  low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
  dark_mode_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  receipt_footer VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sizes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(30) NOT NULL UNIQUE
);

CREATE TABLE colors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  hex_code CHAR(7)
);

CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  product_name VARCHAR(160) NOT NULL,
  buying_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('active', 'inactive', 'discontinued') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_product_prices CHECK (selling_price >= 0 AND buying_price >= 0)
);

CREATE TABLE product_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  size_id BIGINT UNSIGNED,
  color_id BIGINT UNSIGNED,
  barcode VARCHAR(120) UNIQUE,
  stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  reorder_level INT UNSIGNED NOT NULL DEFAULT 10,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variant_product_size_color (product_id, size_id, color_id),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_variants_size FOREIGN KEY (size_id) REFERENCES sizes(id),
  CONSTRAINT fk_variants_color FOREIGN KEY (color_id) REFERENCES colors(id)
);

CREATE TABLE inventory_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('stock_in', 'sale', 'return', 'adjustment', 'damage', 'transfer') NOT NULL,
  quantity_change INT NOT NULL,
  reference_type VARCHAR(50),
  reference_id BIGINT UNSIGNED,
  note VARCHAR(255),
  created_by BIGINT UNSIGNED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
  CONSTRAINT fk_inventory_created_by FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_type ENUM('walk_in', 'vip', 'staff', 'other') NOT NULL DEFAULT 'walk_in',
  full_name VARCHAR(120),
  phone VARCHAR(40),
  email VARCHAR(160),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(60) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED,
  sold_by BIGINT UNSIGNED NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('pending', 'paid', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
  sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_sales_sold_by FOREIGN KEY (sold_by) REFERENCES users(id)
);

CREATE TABLE sale_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  buying_price DECIMAL(12,2) NOT NULL,
  selling_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  line_profit DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_sale_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
);

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  payment_method ENUM('cash', 'card', 'mobile_money', 'bank_transfer', 'other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  transaction_reference VARCHAR(120),
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
);

CREATE TABLE expense_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method ENUM('cash', 'card', 'mobile_money', 'bank_transfer', 'other') DEFAULT 'cash',
  note VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE INDEX idx_users_role_status ON users(role, status);
CREATE INDEX idx_products_name ON products(product_name);
CREATE INDEX idx_sales_date_status ON sales(sale_date, payment_status);
CREATE INDEX idx_expenses_date ON expenses(expense_date);
CREATE INDEX idx_inventory_variant_date ON inventory_movements(variant_id, created_at);
CREATE INDEX idx_sale_items_variant ON sale_items(variant_id);

CREATE VIEW product_stock_summary AS
SELECT
  p.id AS product_id,
  p.product_name,
  c.name AS category_name,
  COALESCE(SUM(pv.stock_quantity), 0) AS total_stock,
  COALESCE(MIN(pv.reorder_level), 5) AS reorder_level,
  p.buying_price,
  p.selling_price,
  (p.selling_price - p.buying_price) AS profit_per_unit,
  CASE
    WHEN COALESCE(SUM(pv.stock_quantity), 0) = 0 THEN 'out_of_stock'
    WHEN COALESCE(SUM(pv.stock_quantity), 0) <= COALESCE(MIN(pv.reorder_level), 5) THEN 'low_stock'
    ELSE 'in_stock'
  END AS stock_status
FROM products p
JOIN categories c ON c.id = p.category_id
LEFT JOIN product_variants pv ON pv.product_id = p.id
WHERE p.status = 'active'
GROUP BY p.id, p.product_name, c.name, p.buying_price, p.selling_price;

CREATE VIEW daily_sales_report AS
SELECT
  DATE(sale_date) AS report_date,
  COUNT(*) AS total_sales,
  SUM(total_amount) AS revenue,
  SUM(total_profit) AS profit
FROM sales
WHERE payment_status = 'paid'
GROUP BY DATE(sale_date);

CREATE VIEW monthly_profit_report AS
SELECT
  DATE_FORMAT(sale_date, '%Y-%m') AS report_month,
  SUM(total_amount) AS revenue,
  SUM(total_profit) AS gross_profit,
  COALESCE((
    SELECT SUM(e.amount)
    FROM expenses e
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(s.sale_date, '%Y-%m')
  ), 0) AS expenses,
  SUM(total_profit) - COALESCE((
    SELECT SUM(e.amount)
    FROM expenses e
    WHERE DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(s.sale_date, '%Y-%m')
  ), 0) AS net_profit
FROM sales s
WHERE payment_status = 'paid'
GROUP BY DATE_FORMAT(sale_date, '%Y-%m');

CREATE VIEW best_selling_products AS
SELECT
  p.id AS product_id,
  p.product_name,
  c.name AS category_name,
  SUM(si.quantity) AS units_sold,
  SUM(si.line_total) AS revenue,
  SUM(si.line_profit) AS profit
FROM sale_items si
JOIN product_variants pv ON pv.id = si.variant_id
JOIN products p ON p.id = pv.product_id
JOIN categories c ON c.id = p.category_id
JOIN sales s ON s.id = si.sale_id
WHERE s.payment_status = 'paid'
GROUP BY p.id, p.product_name, c.name
ORDER BY units_sold DESC;
