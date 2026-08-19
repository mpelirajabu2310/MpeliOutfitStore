-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: clothing_shop_management
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `clothing_shop_management`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `clothing_shop_management` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `clothing_shop_management`;

--
-- Temporary table structure for view `best_selling_products`
--

DROP TABLE IF EXISTS `best_selling_products`;
/*!50001 DROP VIEW IF EXISTS `best_selling_products`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `best_selling_products` AS SELECT
 1 AS `product_id`,
  1 AS `product_name`,
  1 AS `category_name`,
  1 AS `units_sold`,
  1 AS `revenue`,
  1 AS `profit` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'General',NULL,'2026-07-26 20:56:03');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `colors`
--

DROP TABLE IF EXISTS `colors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `colors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `hex_code` char(7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `colors`
--

LOCK TABLES `colors` WRITE;
/*!40000 ALTER TABLE `colors` DISABLE KEYS */;
/*!40000 ALTER TABLE `colors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_type` enum('walk_in','vip','staff','other') NOT NULL DEFAULT 'walk_in',
  `full_name` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `daily_sales_report`
--

DROP TABLE IF EXISTS `daily_sales_report`;
/*!50001 DROP VIEW IF EXISTS `daily_sales_report`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `daily_sales_report` AS SELECT
 1 AS `report_date`,
  1 AS `total_sales`,
  1 AS `revenue`,
  1 AS `profit` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
INSERT INTO `expenses` VALUES (2,'Rent','f86fa9b6-c25d-4bf7-837c-366614542ef6',NULL,'60000',50000.00,NULL,'2026-08-10',1,NULL,NULL,'2026-08-10 17:29:07'),(3,'Transport','29854035-dd15-4545-830e-b00f11c26b81',NULL,'gv',7000.00,NULL,'2026-08-10',1,NULL,NULL,'2026-08-10 17:29:35'),(4,'Food','561ce074-74db-404e-b5dd-9149d91d8846',NULL,NULL,777.00,NULL,'2026-08-18',1,NULL,NULL,'2026-08-10 17:29:49'),(5,'Food','a76b4443-f17a-4653-b0d3-fabe1affce89',NULL,'gv',212.00,NULL,'2026-08-10',1,NULL,NULL,'2026-08-10 17:41:36'),(6,'Transport','846e54d1-50e1-4166-a3f9-3e5937536cd1',NULL,'option',2000.00,NULL,'2026-08-10',3,NULL,NULL,'2026-08-10 19:11:28');
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_movements`
--

DROP TABLE IF EXISTS `inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_movements`
--

LOCK TABLES `inventory_movements` WRITE;
/*!40000 ALTER TABLE `inventory_movements` DISABLE KEYS */;
INSERT INTO `inventory_movements` VALUES (1,2,'sale',-1,'sale',1,'POS sale',1,'2026-07-26 20:56:03'),(2,2,'sale',-2,'sale',2,'POS sale',1,'2026-08-10 16:33:56'),(3,2,'sale',-2,'sale',3,'POS sale',1,'2026-08-10 16:33:56'),(4,1,'sale',-5,'sale',4,'POS sale',1,'2026-08-10 16:34:03'),(5,2,'sale',-1,'sale',5,'POS sale',1,'2026-08-10 16:34:07'),(6,2,'sale',-2,'sale',7,'POS sale',1,'2026-08-10 17:28:00'),(7,2,'sale',-1,'sale',8,'POS sale',1,'2026-08-10 17:28:06'),(8,1,'sale',-1,'sale',9,'POS sale',1,'2026-08-10 17:28:13'),(9,2,'sale',-1,'sale',10,'POS sale',1,'2026-08-10 17:28:17'),(10,2,'sale',-1,'sale',11,'POS sale',1,'2026-08-10 17:28:25'),(11,2,'sale',-1,'sale',12,'POS sale',1,'2026-08-10 17:41:04'),(12,1,'sale',-1,'sale',12,'POS sale',1,'2026-08-10 17:41:04'),(13,4,'sale',-1,'sale',13,'POS sale',3,'2026-08-10 22:44:41'),(14,3,'sale',-2,'sale',13,'POS sale',3,'2026-08-10 22:44:41'),(15,1,'sale',-1,'sale',13,'POS sale',3,'2026-08-10 22:44:41'),(16,3,'sale',-1,'sale',14,'POS sale',1,'2026-08-10 22:50:52'),(17,1,'sale',-1,'sale',14,'POS sale',1,'2026-08-10 22:50:52');
/*!40000 ALTER TABLE `inventory_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migration_history`
--

DROP TABLE IF EXISTS `migration_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migration_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(255) NOT NULL,
  `direction` enum('up','down') NOT NULL DEFAULT 'up',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration_id` (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migration_history`
--

LOCK TABLES `migration_history` WRITE;
/*!40000 ALTER TABLE `migration_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `migration_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `monthly_profit_report`
--

DROP TABLE IF EXISTS `monthly_profit_report`;
/*!50001 DROP VIEW IF EXISTS `monthly_profit_report`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `monthly_profit_report` AS SELECT
 1 AS `report_month`,
  1 AS `revenue`,
  1 AS `gross_profit`,
  1 AS `expenses`,
  1 AS `net_profit` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,'cash',30000.00,NULL,'2026-07-26 23:56:03'),(2,2,'cash',60000.00,NULL,'2026-08-10 19:33:56'),(3,3,'cash',60000.00,NULL,'2026-08-10 19:33:56'),(4,4,'cash',225000.00,NULL,'2026-08-10 19:34:03'),(5,5,'cash',30000.00,NULL,'2026-08-10 19:34:07'),(6,7,'cash',60000.00,NULL,'2026-08-10 20:28:00'),(7,8,'cash',30000.00,NULL,'2026-08-10 20:28:06'),(8,9,'cash',45000.00,NULL,'2026-08-10 20:28:13'),(9,10,'cash',30000.00,NULL,'2026-08-10 20:28:17'),(10,11,'cash',30000.00,NULL,'2026-08-10 20:28:25'),(11,12,'cash',75000.00,NULL,'2026-08-10 20:41:04'),(12,13,'cash',111500.00,NULL,'2026-08-11 01:44:41'),(13,14,'cash',63500.00,NULL,'2026-08-11 01:50:52');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `product_stock_summary`
--

DROP TABLE IF EXISTS `product_stock_summary`;
/*!50001 DROP VIEW IF EXISTS `product_stock_summary`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `product_stock_summary` AS SELECT
 1 AS `product_id`,
  1 AS `product_name`,
  1 AS `category_name`,
  1 AS `total_stock`,
  1 AS `reorder_level`,
  1 AS `buying_price`,
  1 AS `selling_price`,
  1 AS `profit_per_unit`,
  1 AS `stock_status` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (1,1,NULL,NULL,NULL,97,5,'2026-07-26 20:56:03','2026-08-10 22:50:52'),(2,2,NULL,NULL,NULL,199,5,'2026-07-26 20:56:03','2026-08-10 17:41:04'),(3,3,NULL,NULL,NULL,47,5,'2026-08-10 18:29:03','2026-08-10 22:50:52'),(4,4,NULL,NULL,NULL,49,5,'2026-08-10 18:32:01','2026-08-10 22:44:41');
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,'Classic Suit','uploads/products/p1_5a5f82b3.webp',25000.00,45000.00,30000.00,'active',1,'2026-07-26 20:56:03','2026-08-10 18:31:11'),(2,1,'Summer Dress','uploads/products/p2_0bf89382.webp',15000.00,30000.00,18000.00,'active',1,'2026-07-26 20:56:03','2026-08-10 18:30:43'),(3,1,'Shati','uploads/products/p3_5cd6454b.webp',15000.00,25000.00,23000.00,'active',1,'2026-08-10 18:29:03','2026-08-10 18:30:12'),(4,1,'Shati La Mikono Mirefu','uploads/products/p4_8cdfafd1.webp',17000.00,27000.00,25000.00,'active',1,'2026-08-10 18:32:01','2026-08-10 18:32:01');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotion_products`
--

DROP TABLE IF EXISTS `promotion_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotion_products`
--

LOCK TABLES `promotion_products` WRITE;
/*!40000 ALTER TABLE `promotion_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `promotion_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotions`
--

DROP TABLE IF EXISTS `promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotions`
--

LOCK TABLES `promotions` WRITE;
/*!40000 ALTER TABLE `promotions` DISABLE KEYS */;
INSERT INTO `promotions` VALUES (1,'MWISHO WA MWEZI','hii inatolewa mwishoni mwa mwezi',10.00,'2026-08-11','00:00:00','2026-08-11','11:59:00','inactive',1,1,'2026-08-10 22:49:52','2026-08-11 08:32:49'),(2,'SIKUKUU YA EID','hii ni kwaajiri ya siku ya sikukuu ya eid',15.00,'2026-10-11','11:30:00','2026-10-11','00:30:00','active',1,1,'2026-08-11 08:31:10','2026-08-11 08:32:38');
/*!40000 ALTER TABLE `promotions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
INSERT INTO `sale_items` VALUES (1,1,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-07-26 20:56:03'),(2,2,2,2,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,60000.00,30000.00,'2026-08-10 16:33:56'),(3,3,2,2,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,60000.00,30000.00,'2026-08-10 16:33:56'),(4,4,1,5,25000.00,45000.00,45000.00,0,'normal',NULL,NULL,225000.00,100000.00,'2026-08-10 16:34:03'),(5,5,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-08-10 16:34:07'),(6,7,2,2,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,60000.00,30000.00,'2026-08-10 17:28:00'),(7,8,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-08-10 17:28:06'),(8,9,1,1,25000.00,45000.00,45000.00,0,'normal',NULL,NULL,45000.00,20000.00,'2026-08-10 17:28:13'),(9,10,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-08-10 17:28:17'),(10,11,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-08-10 17:28:25'),(11,12,2,1,15000.00,30000.00,30000.00,0,'normal',NULL,NULL,30000.00,15000.00,'2026-08-10 17:41:04'),(12,12,1,1,25000.00,45000.00,45000.00,0,'normal',NULL,NULL,45000.00,20000.00,'2026-08-10 17:41:04'),(13,13,4,1,17000.00,25000.00,27000.00,1,'bulk_discount',NULL,10.00,25000.00,8000.00,'2026-08-10 22:44:41'),(14,13,3,2,15000.00,23000.00,25000.00,1,'bulk_discount',NULL,10.00,46000.00,16000.00,'2026-08-10 22:44:41'),(15,13,1,1,25000.00,40500.00,45000.00,1,'bulk_discount',NULL,10.00,40500.00,15500.00,'2026-08-10 22:44:41'),(16,14,3,1,15000.00,23000.00,25000.00,1,'promotion',1,NULL,23000.00,8000.00,'2026-08-10 22:50:52'),(17,14,1,1,25000.00,40500.00,45000.00,1,'promotion',1,NULL,40500.00,15500.00,'2026-08-10 22:50:52');
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'MM-20260726-225603-879',NULL,NULL,1,30000.00,0.00,NULL,0.00,30000.00,15000.00,'paid','2026-07-26 23:56:03','2026-07-26 20:56:03','2026-07-26 20:56:03'),(2,'MM-20260810-183356-921',NULL,NULL,1,60000.00,0.00,NULL,0.00,60000.00,30000.00,'paid','2026-08-10 19:33:56','2026-08-10 16:33:56','2026-08-10 16:33:56'),(3,'MM-20260810-183356-436',NULL,NULL,1,60000.00,0.00,NULL,0.00,60000.00,30000.00,'paid','2026-08-10 19:33:56','2026-08-10 16:33:56','2026-08-10 16:33:56'),(4,'MM-20260810-183403-761',NULL,NULL,1,225000.00,0.00,NULL,0.00,225000.00,100000.00,'paid','2026-08-10 19:34:03','2026-08-10 16:34:03','2026-08-10 16:34:03'),(5,'MM-20260810-183407-172',NULL,NULL,1,30000.00,0.00,NULL,0.00,30000.00,15000.00,'paid','2026-08-10 19:34:07','2026-08-10 16:34:07','2026-08-10 16:34:07'),(7,'MM-20260810-192800-365','0fa1d43c-6318-49c4-878d-74347d13a9b1',NULL,1,60000.00,0.00,NULL,0.00,60000.00,30000.00,'paid','2026-08-10 20:28:00','2026-08-10 17:28:00','2026-08-10 17:28:00'),(8,'MM-20260810-192806-830','8ec54276-2cdc-4e35-8e9c-e01e8e4365e3',NULL,1,30000.00,0.00,NULL,0.00,30000.00,15000.00,'paid','2026-08-10 20:28:06','2026-08-10 17:28:06','2026-08-10 17:28:06'),(9,'MM-20260810-192813-937','da88c44c-59ec-447a-933f-2b7db4091c8e',NULL,1,45000.00,0.00,NULL,0.00,45000.00,20000.00,'paid','2026-08-10 20:28:13','2026-08-10 17:28:13','2026-08-10 17:28:13'),(10,'MM-20260810-192817-673','daf83660-ba4a-488d-8363-bd6d0cf0f4ec',NULL,1,30000.00,0.00,NULL,0.00,30000.00,15000.00,'paid','2026-08-10 20:28:17','2026-08-10 17:28:17','2026-08-10 17:28:17'),(11,'MM-20260810-192825-771','32005ccb-665e-407b-8ab5-71e2fbf17267',NULL,1,30000.00,0.00,NULL,0.00,30000.00,15000.00,'paid','2026-08-10 20:28:25','2026-08-10 17:28:25','2026-08-10 17:28:25'),(12,'MM-20260810-194104-701','39963d84-9c31-4dee-b9f2-6d2600fe7c99',NULL,1,75000.00,0.00,NULL,0.00,75000.00,35000.00,'paid','2026-08-10 20:41:04','2026-08-10 17:41:04','2026-08-10 17:41:04'),(13,'MM-20260811-004441-393','67ced335-642d-4014-b5f6-eb83e51a9b31',NULL,3,111500.00,10500.00,10.00,0.00,111500.00,39500.00,'paid','2026-08-11 01:44:41','2026-08-10 22:44:41','2026-08-10 22:44:41'),(14,'MM-20260811-005052-875','a95aac18-0c41-4b32-a4dc-db6cf27b0900',NULL,1,63500.00,6500.00,NULL,0.00,63500.00,23500.00,'paid','2026-08-11 01:50:52','2026-08-10 22:50:52','2026-08-10 22:50:52');
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_settings`
--

DROP TABLE IF EXISTS `shop_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_settings`
--

LOCK TABLES `shop_settings` WRITE;
/*!40000 ALTER TABLE `shop_settings` DISABLE KEYS */;
INSERT INTO `shop_settings` VALUES (1,'Mpeli Outfit Store',NULL,NULL,NULL,'admin@mpelioutfitstore.com','TSH',5,0,NULL,'2026-07-26 20:56:03','2026-08-10 22:53:35');
/*!40000 ALTER TABLE `shop_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sizes`
--

DROP TABLE IF EXISTS `sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sizes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sizes`
--

LOCK TABLES `sizes` WRITE;
/*!40000 ALTER TABLE `sizes` DISABLE KEYS */;
/*!40000 ALTER TABLE `sizes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System Admin','admin','admin@mpelioutfitstore.com','$2y$10$ED6iGOxfPO8AgwwmtdD12OvAJjZ9ySp/ZgM2toHGUKR3E.SkPkB4y','OWNER','active','2026-08-11 11:27:36','2026-07-26 20:56:02','2026-08-11 08:27:36'),(2,'Seller One','seller1','seller1@mpelioutfitstore.com','$2y$10$MOM21/8LiVoBt6NCfWdHPOS4bAqPg.LfvuukD7uz2xcLT93XdHkV2','SELLER','active','2026-07-26 23:56:04','2026-07-26 20:56:03','2026-07-26 20:56:04'),(3,'Seller Two','Seller2','mpelirajabu231022@gmail.com','$2y$10$.UMr/1coTKEQeA8xBQlUCO3P3ciEgLBYozIlNbWMEz5w87tQ7s78y','SELLER','active','2026-08-11 01:51:10','2026-08-10 16:31:00','2026-08-10 22:51:10');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'clothing_shop_management'
--

--
-- Dumping routines for database 'clothing_shop_management'
--

--
-- Current Database: `clothing_shop_management`
--

USE `clothing_shop_management`;

--
-- Final view structure for view `best_selling_products`
--

/*!50001 DROP VIEW IF EXISTS `best_selling_products`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `best_selling_products` AS select `p`.`id` AS `product_id`,`p`.`product_name` AS `product_name`,`c`.`name` AS `category_name`,sum(`si`.`quantity`) AS `units_sold`,sum(`si`.`line_total`) AS `revenue`,sum(`si`.`line_profit`) AS `profit` from ((((`sale_items` `si` join `product_variants` `pv` on(`pv`.`id` = `si`.`variant_id`)) join `products` `p` on(`p`.`id` = `pv`.`product_id`)) join `categories` `c` on(`c`.`id` = `p`.`category_id`)) join `sales` `s` on(`s`.`id` = `si`.`sale_id`)) where `s`.`payment_status` = 'paid' group by `p`.`id`,`p`.`product_name`,`c`.`name` order by sum(`si`.`quantity`) desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `daily_sales_report`
--

/*!50001 DROP VIEW IF EXISTS `daily_sales_report`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `daily_sales_report` AS select cast(`sales`.`sale_date` as date) AS `report_date`,count(0) AS `total_sales`,sum(`sales`.`total_amount`) AS `revenue`,sum(`sales`.`total_profit`) AS `profit` from `sales` where `sales`.`payment_status` = 'paid' group by cast(`sales`.`sale_date` as date) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `monthly_profit_report`
--

/*!50001 DROP VIEW IF EXISTS `monthly_profit_report`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `monthly_profit_report` AS select date_format(`s`.`sale_date`,'%Y-%m') AS `report_month`,sum(`s`.`total_amount`) AS `revenue`,sum(`s`.`total_profit`) AS `gross_profit`,coalesce((select sum(`e`.`amount`) from `expenses` `e` where date_format(`e`.`expense_date`,'%Y-%m') = date_format(`s`.`sale_date`,'%Y-%m')),0) AS `expenses`,sum(`s`.`total_profit`) - coalesce((select sum(`e`.`amount`) from `expenses` `e` where date_format(`e`.`expense_date`,'%Y-%m') = date_format(`s`.`sale_date`,'%Y-%m')),0) AS `net_profit` from `sales` `s` where `s`.`payment_status` = 'paid' group by date_format(`s`.`sale_date`,'%Y-%m') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `product_stock_summary`
--

/*!50001 DROP VIEW IF EXISTS `product_stock_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `product_stock_summary` AS select `p`.`id` AS `product_id`,`p`.`product_name` AS `product_name`,`c`.`name` AS `category_name`,coalesce(sum(`pv`.`stock_quantity`),0) AS `total_stock`,coalesce(min(`pv`.`reorder_level`),5) AS `reorder_level`,`p`.`buying_price` AS `buying_price`,`p`.`selling_price` AS `selling_price`,`p`.`selling_price` - `p`.`buying_price` AS `profit_per_unit`,case when coalesce(sum(`pv`.`stock_quantity`),0) = 0 then 'out_of_stock' when coalesce(sum(`pv`.`stock_quantity`),0) <= coalesce(min(`pv`.`reorder_level`),5) then 'low_stock' else 'in_stock' end AS `stock_status` from ((`products` `p` join `categories` `c` on(`c`.`id` = `p`.`category_id`)) left join `product_variants` `pv` on(`pv`.`product_id` = `p`.`id`)) where `p`.`status` = 'active' group by `p`.`id`,`p`.`product_name`,`c`.`name`,`p`.`buying_price`,`p`.`selling_price` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-11 12:53:23
