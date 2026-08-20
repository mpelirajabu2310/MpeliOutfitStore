-- MpeliOutFitStore Database Backup
-- Created: 2026-08-20 13:53:41
-- Reason: pre_add_promotion_image_path
-- Tables: 22

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

INSERT INTO `categories` (`id`, `name`, `description`, `created_at`) VALUES ('1', 'General', NULL, '2026-08-19 12:19:56');

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
  `idempotency_key` varchar(64) DEFAULT NULL,
  `expense_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `previous_amount` decimal(12,2) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `edited_by` bigint(20) unsigned DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_expenses_idempotency` (`idempotency_key`),
  KEY `created_by` (`created_by`),
  KEY `edited_by` (`edited_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_movements` (`id`, `variant_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `note`, `created_by`, `created_at`) VALUES ('2', '1', 'sale', '-2', 'sale', '5', 'POS sale', '1', '2026-08-20 13:25:39');
INSERT INTO `inventory_movements` (`id`, `variant_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `note`, `created_by`, `created_at`) VALUES ('3', '2', 'sale', '-1', 'sale', '6', 'POS sale', '1', '2026-08-20 14:02:54');
INSERT INTO `inventory_movements` (`id`, `variant_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `note`, `created_by`, `created_at`) VALUES ('4', '1', 'sale', '-1', 'sale', '6', 'POS sale', '1', '2026-08-20 14:02:54');
INSERT INTO `inventory_movements` (`id`, `variant_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `note`, `created_by`, `created_at`) VALUES ('5', '2', 'sale', '-2', 'sale', '7', 'POS sale', '1', '2026-08-20 14:03:35');
INSERT INTO `inventory_movements` (`id`, `variant_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `note`, `created_by`, `created_at`) VALUES ('6', '1', 'sale', '-1', 'sale', '7', 'POS sale', '1', '2026-08-20 14:03:35');

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

-- Table: order_items
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `variant_id` bigint(20) unsigned NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: orders
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','preparing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  KEY `status` (`status`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payments` (`id`, `sale_id`, `payment_method`, `amount`, `transaction_reference`, `paid_at`) VALUES ('2', '5', 'cash', '4000.00', NULL, '2026-08-20 13:25:39');
INSERT INTO `payments` (`id`, `sale_id`, `payment_method`, `amount`, `transaction_reference`, `paid_at`) VALUES ('3', '6', 'cash', '22000.00', NULL, '2026-08-20 14:02:54');
INSERT INTO `payments` (`id`, `sale_id`, `payment_method`, `amount`, `transaction_reference`, `paid_at`) VALUES ('4', '7', 'cash', '42000.00', NULL, '2026-08-20 14:03:35');

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

INSERT INTO `product_variants` (`id`, `product_id`, `size_id`, `color_id`, `barcode`, `stock_quantity`, `reorder_level`, `created_at`, `updated_at`) VALUES ('1', '1', NULL, NULL, NULL, '196', '5', '2026-08-19 12:19:56', '2026-08-20 14:03:35');
INSERT INTO `product_variants` (`id`, `product_id`, `size_id`, `color_id`, `barcode`, `stock_quantity`, `reorder_level`, `created_at`, `updated_at`) VALUES ('2', '2', NULL, NULL, NULL, '12', '5', '2026-08-20 13:41:10', '2026-08-20 14:03:35');

-- Table: products
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `product_name` varchar(160) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
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

INSERT INTO `products` (`id`, `category_id`, `product_name`, `image_path`, `buying_price`, `selling_price`, `minimum_allowed_selling_price`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('1', '1', 'Shati', 'uploads/products/p1_ad0c503b.webp', '1000.00', '2000.00', '1800.00', 'active', '1', '2026-08-19 12:19:56', '2026-08-19 12:19:57');
INSERT INTO `products` (`id`, `category_id`, `product_name`, `image_path`, `buying_price`, `selling_price`, `minimum_allowed_selling_price`, `status`, `created_by`, `created_at`, `updated_at`) VALUES ('2', '1', 'guchi', 'uploads/products/p2_27ab292b.webp', '15000.00', '20000.00', '19000.00', 'active', '1', '2026-08-20 13:41:10', '2026-08-20 13:41:11');

-- Table: promotion_products
DROP TABLE IF EXISTS `promotion_products`;
CREATE TABLE `promotion_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promotion_product` (`promotion_id`,`product_id`),
  KEY `fk_promo_products_product` (`product_id`),
  CONSTRAINT `fk_promo_products_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promo_products_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: promotions
DROP TABLE IF EXISTS `promotions`;
CREATE TABLE `promotions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `start_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_date` date NOT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('draft','active','inactive') NOT NULL DEFAULT 'draft',
  `all_products` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_promotions_status_dates` (`status`,`start_date`,`end_date`),
  KEY `fk_promotions_created_by` (`created_by`),
  CONSTRAINT `fk_promotions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_promotion_percentage` CHECK (`percentage` > 0 and `percentage` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `pricing_type` enum('normal','promotion','bulk_discount','existing_discount') NOT NULL DEFAULT 'normal',
  `promotion_id` bigint(20) unsigned DEFAULT NULL,
  `bulk_discount_percent` decimal(5,2) DEFAULT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `line_profit` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sale_items_sale` (`sale_id`),
  KEY `idx_sale_items_variant` (`variant_id`),
  KEY `idx_sale_items_promotion` (`promotion_id`),
  CONSTRAINT `fk_sale_items_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sale_items` (`id`, `sale_id`, `variant_id`, `quantity`, `buying_price`, `selling_price`, `original_selling_price`, `discount_applied`, `pricing_type`, `promotion_id`, `bulk_discount_percent`, `line_total`, `line_profit`, `created_at`) VALUES ('2', '5', '1', '2', '1000.00', '2000.00', '2000.00', '0', 'normal', NULL, NULL, '4000.00', '2000.00', '2026-08-20 13:25:39');
INSERT INTO `sale_items` (`id`, `sale_id`, `variant_id`, `quantity`, `buying_price`, `selling_price`, `original_selling_price`, `discount_applied`, `pricing_type`, `promotion_id`, `bulk_discount_percent`, `line_total`, `line_profit`, `created_at`) VALUES ('3', '6', '2', '1', '15000.00', '20000.00', '20000.00', '0', 'normal', NULL, NULL, '20000.00', '5000.00', '2026-08-20 14:02:54');
INSERT INTO `sale_items` (`id`, `sale_id`, `variant_id`, `quantity`, `buying_price`, `selling_price`, `original_selling_price`, `discount_applied`, `pricing_type`, `promotion_id`, `bulk_discount_percent`, `line_total`, `line_profit`, `created_at`) VALUES ('4', '6', '1', '1', '1000.00', '2000.00', '2000.00', '0', 'normal', NULL, NULL, '2000.00', '1000.00', '2026-08-20 14:02:54');
INSERT INTO `sale_items` (`id`, `sale_id`, `variant_id`, `quantity`, `buying_price`, `selling_price`, `original_selling_price`, `discount_applied`, `pricing_type`, `promotion_id`, `bulk_discount_percent`, `line_total`, `line_profit`, `created_at`) VALUES ('5', '7', '2', '2', '15000.00', '20000.00', '20000.00', '0', 'normal', NULL, NULL, '40000.00', '10000.00', '2026-08-20 14:03:35');
INSERT INTO `sale_items` (`id`, `sale_id`, `variant_id`, `quantity`, `buying_price`, `selling_price`, `original_selling_price`, `discount_applied`, `pricing_type`, `promotion_id`, `bulk_discount_percent`, `line_total`, `line_profit`, `created_at`) VALUES ('6', '7', '1', '1', '1000.00', '2000.00', '2000.00', '0', 'normal', NULL, NULL, '2000.00', '1000.00', '2026-08-20 14:03:35');

-- Table: sales
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(60) NOT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sold_by` bigint(20) unsigned NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bulk_discount_percent` decimal(5,2) DEFAULT NULL,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  UNIQUE KEY `idx_sales_idempotency` (`idempotency_key`),
  KEY `fk_sales_customer` (`customer_id`),
  KEY `fk_sales_sold_by` (`sold_by`),
  KEY `idx_sales_date_status` (`sale_date`,`payment_status`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_sales_sold_by` FOREIGN KEY (`sold_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sales` (`id`, `receipt_number`, `idempotency_key`, `customer_id`, `sold_by`, `subtotal`, `discount_amount`, `bulk_discount_percent`, `tax_amount`, `total_amount`, `total_profit`, `payment_status`, `sale_date`, `created_at`, `updated_at`) VALUES ('5', 'MM-20260820-122539-545', '1bf45831-a1a8-4d64-b7ee-423e6758140a', NULL, '1', '4000.00', '0.00', NULL, '0.00', '4000.00', '2000.00', 'paid', '2026-08-20 13:25:39', '2026-08-20 13:25:39', '2026-08-20 13:25:39');
INSERT INTO `sales` (`id`, `receipt_number`, `idempotency_key`, `customer_id`, `sold_by`, `subtotal`, `discount_amount`, `bulk_discount_percent`, `tax_amount`, `total_amount`, `total_profit`, `payment_status`, `sale_date`, `created_at`, `updated_at`) VALUES ('6', 'MM-20260820-130254-881', 'dfef7344-fc42-498f-b805-62f89bf614a8', NULL, '1', '22000.00', '0.00', NULL, '0.00', '22000.00', '6000.00', 'paid', '2026-08-20 14:02:54', '2026-08-20 14:02:54', '2026-08-20 14:02:54');
INSERT INTO `sales` (`id`, `receipt_number`, `idempotency_key`, `customer_id`, `sold_by`, `subtotal`, `discount_amount`, `bulk_discount_percent`, `tax_amount`, `total_amount`, `total_profit`, `payment_status`, `sale_date`, `created_at`, `updated_at`) VALUES ('7', 'MM-20260820-130335-411', '697b547e-49fc-45f4-8d0c-5c1980348e21', NULL, '1', '42000.00', '0.00', NULL, '0.00', '42000.00', '11000.00', 'paid', '2026-08-20 14:03:35', '2026-08-20 14:03:35', '2026-08-20 14:03:35');

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

INSERT INTO `shop_settings` (`id`, `shop_name`, `logo_url`, `address`, `phone`, `email`, `currency_code`, `low_stock_threshold`, `dark_mode_enabled`, `receipt_footer`, `created_at`, `updated_at`) VALUES ('1', 'THURAIYA COLLECTION', NULL, 'THURAIYA COLLECTION', NULL, 'mpelirajabu2310@gmail.com', 'TSH', '5', '0', NULL, '2026-07-26 23:56:03', '2026-08-20 13:31:44');

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password_hash`, `role`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES ('1', 'RAJABU YUSUFU MPELI', 'mpelirajabu', 'mpelirajabu2310@gmail.com', '$2y$10$bp7EzgdbkrQRrEdUyPYDjOmYkA/BVRvQT76JkaK6ZPpO1dmz9VpSy', 'OWNER', 'active', '2026-08-20 14:26:16', '2026-08-11 13:33:07', '2026-08-20 14:26:16');

SET FOREIGN_KEY_CHECKS = 1;
