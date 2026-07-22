-- Run on an existing database to remove SKU/image and set TSH currency.
-- Fresh installs should use database.sql instead.

USE clothing_shop_management;

UPDATE shop_settings SET currency_code = 'TSH' WHERE currency_code = 'USD';
UPDATE shop_settings SET low_stock_threshold = 5 WHERE low_stock_threshold > 5;

ALTER TABLE products DROP COLUMN sku;
ALTER TABLE products DROP COLUMN image_url;
ALTER TABLE products DROP COLUMN description;

DROP VIEW IF EXISTS product_stock_summary;
DROP VIEW IF EXISTS daily_sales_report;
DROP VIEW IF EXISTS monthly_profit_report;
DROP VIEW IF EXISTS best_selling_products;

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
