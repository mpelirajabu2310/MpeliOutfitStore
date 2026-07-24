-- MpeliOutFitStore Database Backup
-- Created: 2026-07-24 17:57:00
-- Reason: e2e_test
-- Tables: 18

SET FOREIGN_KEY_CHECKS = 0;

-- View: best_selling_products
DROP VIEW IF EXISTS `best_selling_products`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `best_selling_products` AS select `p`.`id` AS `product_id`,`p`.`product_name` AS `product_name`,`c`.`name` AS `category_name`,sum(`si`.`quantity`) AS `units_sold`,sum(`si`.`line_total`) AS `revenue`,sum(`si`.`line_profit`) AS `profit` from ((((`sale_items` `si` join `product_variants` `pv` on(`pv`.`id` = `si`.`variant_id`)) join `products` `p` on(`p`.`id` = `pv`.`product_id`)) join `categories` `c` on(`c`.`id` = `p`.`category_id`)) join `sales` `s` on(`s`.`id` = `si`.`sale_id`)) where `s`.`payment_status` = 'paid' group by `p`.`id`,`p`.`product_name`,`c`.`name` order by sum(`si`.`quantity`) desc;

-- Table: categories
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'General', NULL, '2026-07-24 18:57:00');

-- Table: colors
DROP TABLE IF EXISTS `colors`;
CREATE TABLE `colors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `hex_code` char(7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: customers
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_type` enum('walk_in','vip','staff','other') NOT NULL DEFAULT 'walk_in',
  `full_name` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View: daily_sales_report
DROP VIEW IF EXISTS `daily_sales_report`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `daily_sales_report` AS select cast(`sales`.`sale_date` as date) AS `report_date`,count(0) AS `total_sales`,sum(`sales`.`total_amount`) AS `revenue`,sum(`sales`.`total_profit`) AS `profit` from `sales` where `sales`.`payment_status` = 'paid' group by cast(`sales`.`sale_date` as date);

-- Table: expenses
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(50) NOT NULL,
  `expense_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `expense_date` date NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `expenses` (`id`, `category`, `expense_name`, `description`, `amount`, `expense_date`, `created_by`, `created_at`) VALUES ('1', 'Food', NULL, 'E2E test expense', '5000.00', '2026-07-24', '1', '2026-07-24 18:57:00');

-- Table: inventory_movements
DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE `inventory_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variant_id` bigint(20) unsigned NOT NULL,
  `movement_type` enum('stock_in','sale','return','adjustment','damage','transfer') NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_inventory_created_by` (`created_by`),
  KEY `idx_inventory_variant_date` (`variant_id`,`created_at`),
  CONSTRAINT `fk_inventory_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_inventory_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: migration_history
DROP TABLE IF EXISTS `migration_history`;
CREATE TABLE `migration_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(255) NOT NULL,
  `direction` enum('up','down') NOT NULL DEFAULT 'up',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_id` (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View: monthly_profit_report
DROP VIEW IF EXISTS `monthly_profit_report`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `monthly_profit_report` AS select date_format(`s`.`sale_date`,'%Y-%m') AS `report_month`,sum(`s`.`total_amount`) AS `revenue`,sum(`s`.`total_profit`) AS `gross_profit`,coalesce((select sum(`e`.`amount`) from `expenses` `e` where date_format(`e`.`expense_date`,'%Y-%m') = date_format(`s`.`sale_date`,'%Y-%m')),0) AS `expenses`,sum(`s`.`total_profit`) - coalesce((select sum(`e`.`amount`) from `expenses` `e` where date_format(`e`.`expense_date`,'%Y-%m') = date_format(`s`.`sale_date`,'%Y-%m')),0) AS `net_profit` from `sales` `s` where `s`.`payment_status` = 'paid' group by date_format(`s`.`sale_date`,'%Y-%m');

-- Table: payments
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `payment_method` enum('cash','card','mobile_money','bank_transfer','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `transaction_reference` varchar(120) DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payments_sale` (`sale_id`),
  CONSTRAINT `fk_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View: product_stock_summary
DROP VIEW IF EXISTS `product_stock_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `product_stock_summary` AS select `p`.`id` AS `product_id`,`p`.`product_name` AS `product_name`,`c`.`name` AS `category_name`,coalesce(sum(`pv`.`stock_quantity`),0) AS `total_stock`,coalesce(min(`pv`.`reorder_level`),5) AS `reorder_level`,`p`.`buying_price` AS `buying_price`,`p`.`selling_price` AS `selling_price`,`p`.`selling_price` - `p`.`buying_price` AS `profit_per_unit`,case when coalesce(sum(`pv`.`stock_quantity`),0) = 0 then 'out_of_stock' when coalesce(sum(`pv`.`stock_quantity`),0) <= coalesce(min(`pv`.`reorder_level`),5) then 'low_stock' else 'in_stock' end AS `stock_status` from ((`products` `p` join `categories` `c` on(`c`.`id` = `p`.`category_id`)) left join `product_variants` `pv` on(`pv`.`product_id` = `p`.`id`)) where `p`.`status` = 'active' group by `p`.`id`,`p`.`product_name`,`c`.`name`,`p`.`buying_price`,`p`.`selling_price`;

-- Table: product_variants
DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE `product_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `size_id` bigint(20) unsigned DEFAULT NULL,
  `color_id` bigint(20) unsigned DEFAULT NULL,
  `barcode` varchar(120) DEFAULT NULL,
  `stock_quantity` int(10) unsigned NOT NULL DEFAULT 0,
  `reorder_level` int(10) unsigned NOT NULL DEFAULT 10,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  UNIQUE KEY `uq_variant_product_size_color` (`product_id`,`size_id`,`color_id`),
  KEY `fk_variants_size` (`size_id`),
  KEY `fk_variants_color` (`color_id`),
  CONSTRAINT `fk_variants_color` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_variants_size` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_variants` (`id`, `product_id`, `size_id`, `color_id`, `barcode`, `stock_quantity`, `reorder_level`, `created_at`, `updated_at`) VALUES ('1', '1', NULL, NULL, NULL, '10', '5', '2026-07-24 18:57:00', '2026-07-24 18:57:00');
INSERT INTO `product_variants` (`id`, `product_id`, `size_id`, `color_id`, `barcode`, `stock_quantity`, `reorder_level`, `created_at`, `updated_at`) VALUES ('2', '2', NULL, NULL, NULL, '20', '5', '2026-07-24 18:57:00', '2026-07-24 18:57:00');

-- Table: products
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `product_name` varchar(160) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `minimum_allowed_selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_products_category` (`category_id`),
  KEY `fk_products_created_by` (`created_by`),
  KEY `idx_products_name` (`product_name`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_product_prices` CHECK (`selling_price` >= 0 and `buying_price` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`id`, `category_id`, `product_name`, `buying_price`, `selling_price`, `minimum_allowed_selling_price`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '1', 'Classic Suit', '25000.00', '45000.00', '30000.00', 'active', '1', '2026-07-24 18:57:00', '2026-07-24 18:57:00');
INSERT INTO `products` (`id`, `category_id`, `product_name`, `buying_price`, `selling_price`, `minimum_allowed_selling_price`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('2', '1', 'Summer Dress', '15000.00', '30000.00', '18000.00', 'active', '1', '2026-07-24 18:57:00', '2026-07-24 18:57:00');

-- Table: sale_items
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `variant_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `buying_price` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `original_selling_price` decimal(12,2) DEFAULT NULL,
  `discount_applied` tinyint(1) NOT NULL DEFAULT 0,
  `line_total` decimal(12,2) NOT NULL,
  `line_profit` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sale_items_sale` (`sale_id`),
  KEY `idx_sale_items_variant` (`variant_id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sales
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(60) NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sold_by` bigint(20) unsigned NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `fk_sales_customer` (`customer_id`),
  KEY `fk_sales_sold_by` (`sold_by`),
  KEY `idx_sales_date_status` (`sale_date`,`payment_status`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sales_sold_by` FOREIGN KEY (`sold_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: shop_settings
DROP TABLE IF EXISTS `shop_settings`;
CREATE TABLE `shop_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shop_name` varchar(160) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'TSH',
  `low_stock_threshold` int(10) unsigned NOT NULL DEFAULT 5,
  `dark_mode_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `receipt_footer` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `shop_settings` (`id`, `shop_name`, `logo_url`, `address`, `phone`, `email`, `currency_code`, `low_stock_threshold`, `dark_mode_enabled`, `receipt_footer`, `created_at`, `updated_at`) VALUES ('1', 'Mpeli Outfit Store', NULL, NULL, NULL, NULL, 'TSH', '5', '0', NULL, '2026-07-24 18:57:00', '2026-07-24 18:57:00');

-- Table: sizes
DROP TABLE IF EXISTS `sizes`;
CREATE TABLE `sizes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('OWNER','SELLER') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role_status` (`role`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password_hash`, `role`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES ('1', 'System Admin', 'mpeli', 'admin@test.com', '$2y$10$2uQdefmMRIUc/wl2Lqa6fe6.53yVqIr8Vj83IK.h2ivsq8PXKg9ei', 'OWNER', 'active', '2026-07-24 18:56:59', '2026-07-24 18:56:59', '2026-07-24 18:56:59');
INSERT INTO `users` (`id`, `name`, `username`, `email`, `password_hash`, `role`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES ('2', 'Ikramu', 'Ikramu', 'ikramu@test.com', '$2y$10$Fl7lrnDvIkkXBtASQ4lACesKwJ5wXg/ul0BWpS/jcilqFp61IQ69G', 'SELLER', 'active', NULL, '2026-07-24 18:57:00', '2026-07-24 18:57:00');

SET FOREIGN_KEY_CHECKS = 1;
