-- ============================================================
-- Stockora POS Pro — Complete MySQL Database Schema
-- Version : 2.0.0
-- Engine  : InnoDB
-- Charset : utf8mb4 / utf8mb4_unicode_ci
-- ============================================================
-- Usage:
--   1. Create the database (run once):
--      mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS stockora
--             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   2. Import this file:
--      mysql -u root -p stockora < database.sql
--
-- Default super-admin login:
--   Email   : admin@stockora.com
--   Password: admin123
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ============================================================
-- 1. ADMINS  (platform super-admins)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       VARCHAR(50)  NOT NULL DEFAULT 'superadmin',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. SHOPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `shops` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `owner_name` VARCHAR(255) NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `phone`      VARCHAR(50)  DEFAULT NULL,
  `address`    TEXT         DEFAULT NULL,
  `logo`       VARCHAR(500) DEFAULT NULL,
  `city`       VARCHAR(100) DEFAULT NULL,
  `status`     ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `notes`      TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shops_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. USERS  (shop staff: owners, cashiers, managers)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `shop_id`    INT(11)      NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('owner','cashier','manager') NOT NULL DEFAULT 'owner',
  `status`     ENUM('active','inactive')         NOT NULL DEFAULT 'active',
  `last_login` DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_shop` (`shop_id`),
  CONSTRAINT `fk_users_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. SUBSCRIPTION PLANS
-- ============================================================
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `original_price` DECIMAL(10,2) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `features` TEXT DEFAULT NULL,
  `trial_days` INT(11) NOT NULL DEFAULT 0,
  `offer_valid_months` INT(11) DEFAULT NULL,
  `badge_text` VARCHAR(100) DEFAULT NULL,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_plans_name` (`name`),
  KEY `idx_subscription_plans_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. SUBSCRIPTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`        INT(11)       NOT NULL,
  `plan_name`      VARCHAR(100)  NOT NULL DEFAULT 'Monthly',
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 10000.00,
  `months`         INT(11)       NOT NULL DEFAULT 1,
  `start_date`     DATE          NOT NULL,
  `end_date`       DATE          NOT NULL,
  `status`         ENUM('active','expired','pending','cancelled') NOT NULL DEFAULT 'active',
  `payment_method` VARCHAR(50)   NOT NULL DEFAULT 'cash',
  `reference_no`   VARCHAR(255)  DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_by`     INT(11)       DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscriptions_shop`   (`shop_id`),
  KEY `idx_subscriptions_status` (`status`),
  KEY `idx_subscriptions_end`    (`end_date`),
  KEY `fk_sub_admin`             (`created_by`),
  CONSTRAINT `fk_sub_shop`  FOREIGN KEY (`shop_id`)    REFERENCES `shops`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. PAYMENTS  (subscription payments)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`         INT(11)       NOT NULL,
  `subscription_id` INT(11)       DEFAULT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `payment_date`    DATE          NOT NULL,
  `payment_method`  VARCHAR(50)   NOT NULL DEFAULT 'cash',
  `reference_no`    VARCHAR(255)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `status`          ENUM('completed','pending','failed') NOT NULL DEFAULT 'completed',
  `created_by`      INT(11)       DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_shop`         (`shop_id`),
  KEY `idx_payments_subscription` (`subscription_id`),
  KEY `idx_payments_date`         (`payment_date`),
  KEY `fk_pay_admin`              (`created_by`),
  CONSTRAINT `fk_pay_shop`  FOREIGN KEY (`shop_id`)         REFERENCES `shops`         (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_sub`   FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`created_by`)      REFERENCES `admins`        (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. CATEGORIES
-- ============================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `shop_id`     INT(11)      NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'active',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_category` (`shop_id`, `name`),
  KEY `idx_categories_shop` (`shop_id`),
  CONSTRAINT `fk_cat_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`         INT(11)       NOT NULL,
  `category_id`     INT(11)       DEFAULT NULL,
  `name`            VARCHAR(255)  NOT NULL,
  `barcode`         VARCHAR(100)  DEFAULT NULL,
  `sku`             VARCHAR(100)  DEFAULT NULL,
  `company_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `retail_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_quantity`  INT(11)       NOT NULL DEFAULT 0,
  `min_stock_alert` INT(11)       NOT NULL DEFAULT 5,
  `unit`            VARCHAR(50)   NOT NULL DEFAULT 'pcs',
  `description`     TEXT          DEFAULT NULL,
  `image`           VARCHAR(500)  DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_shop`     (`shop_id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_barcode`  (`barcode`),
  KEY `idx_products_sku`      (`sku`),
  CONSTRAINT `fk_prod_shop` FOREIGN KEY (`shop_id`)     REFERENCES `shops`      (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_cat`  FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CUSTOMERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`          INT(11)       NOT NULL,
  `name`             VARCHAR(255)  NOT NULL,
  `phone`            VARCHAR(50)   DEFAULT NULL,
  `email`            VARCHAR(255)  DEFAULT NULL,
  `address`          TEXT          DEFAULT NULL,
  `credit_limit`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `outstanding_dues` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_purchases`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `visit_count`      INT(11)       NOT NULL DEFAULT 0,
  `last_payment`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_payment_date` DATE         DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_shop`  (`shop_id`),
  KEY `idx_customers_phone` (`phone`),
  CONSTRAINT `fk_cust_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. CUSTOMER CREDIT  (credit / debit ledger)
-- ============================================================
CREATE TABLE IF NOT EXISTS `customer_credit` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`     INT(11)       NOT NULL,
  `customer_id` INT(11)       NOT NULL,
  `amount`      DECIMAL(10,2) NOT NULL,
  `type`        VARCHAR(20)   NOT NULL DEFAULT 'credit',
  `description` TEXT          DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cc_shop`     (`shop_id`),
  KEY `idx_cc_customer` (`customer_id`),
  CONSTRAINT `fk_cc_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`     (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cc_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. CUSTOMER PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `customer_payments` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`        INT(11)       NOT NULL,
  `customer_id`    INT(11)       NOT NULL,
  `sale_id`        INT(11)       DEFAULT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `payment_date`   DATE          NOT NULL,
  `payment_method` VARCHAR(50)   NOT NULL DEFAULT 'cash',
  `payment_type`   VARCHAR(20)   NOT NULL DEFAULT 'payment',
  `reference_no`   VARCHAR(255)  DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_by`     INT(11)       DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cp_shop`     (`shop_id`),
  KEY `idx_cp_customer` (`customer_id`),
  KEY `idx_cp_sale`     (`sale_id`),
  CONSTRAINT `fk_cp_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`     (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_sale`     FOREIGN KEY (`sale_id`)     REFERENCES `sales`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. BULK BUYERS  (wholesale clients)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bulk_buyers` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`             INT(11)       NOT NULL,
  `name`                VARCHAR(255)  NOT NULL,
  `business_name`       VARCHAR(255)  DEFAULT NULL,
  `phone`               VARCHAR(50)   DEFAULT NULL,
  `email`               VARCHAR(255)  DEFAULT NULL,
  `address`             TEXT          DEFAULT NULL,
  `city`                VARCHAR(100)  DEFAULT NULL,
  `credit_limit`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_qty_wholesale`   INT(11)       NOT NULL DEFAULT 0,
  `wholesale_discount`  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `outstanding_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_purchases`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes`               TEXT          DEFAULT NULL,
  `status`              VARCHAR(20)   NOT NULL DEFAULT 'active',
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bb_shop` (`shop_id`),
  CONSTRAINT `fk_bb_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. SALES
-- ============================================================
CREATE TABLE IF NOT EXISTS `sales` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`        INT(11)       NOT NULL,
  `invoice_no`     VARCHAR(100)  NOT NULL,
  `sale_type`      ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  `customer_id`    INT(11)       DEFAULT NULL,
  `buyer_id`       INT(11)       DEFAULT NULL,
  `customer_name`  VARCHAR(255)  DEFAULT NULL,
  `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_type`  ENUM('amount','percent') NOT NULL DEFAULT 'amount',
  `tax`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `grand_total`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','card','online','credit') NOT NULL DEFAULT 'cash',
  `payment_status` ENUM('paid','partial','credit')       NOT NULL DEFAULT 'paid',
  `notes`          TEXT          DEFAULT NULL,
  `cashier_id`     INT(11)       DEFAULT NULL,
  `sale_date`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_shop`     (`shop_id`),
  KEY `idx_sales_date`     (`sale_date`),
  KEY `idx_sales_customer` (`customer_id`),
  KEY `idx_sales_buyer`    (`buyer_id`),
  KEY `idx_sales_cashier`  (`cashier_id`),
  CONSTRAINT `fk_sales_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`       (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_buyer`    FOREIGN KEY (`buyer_id`)    REFERENCES `bulk_buyers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_cashier`  FOREIGN KEY (`cashier_id`)  REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. SALE ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `sale_id`      INT(11)       NOT NULL,
  `product_id`   INT(11)       NOT NULL,
  `product_name` VARCHAR(255)  NOT NULL,
  `quantity`     INT(11)       NOT NULL,
  `unit_price`   DECIMAL(10,2) NOT NULL,
  `company_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_price`  DECIMAL(10,2) NOT NULL,
  `profit`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_si_sale`    (`sale_id`),
  KEY `idx_si_product` (`product_id`),
  CONSTRAINT `fk_si_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. STOCK MOVEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id`              INT(11)     NOT NULL AUTO_INCREMENT,
  `shop_id`         INT(11)     NOT NULL,
  `product_id`      INT(11)     NOT NULL,
  `movement_type`   ENUM('purchase','sale','adjustment','return') NOT NULL,
  `quantity`        INT(11)     NOT NULL,
  `before_quantity` INT(11)     DEFAULT NULL,
  `after_quantity`  INT(11)     DEFAULT NULL,
  `reference_id`    INT(11)     DEFAULT NULL,
  `notes`           TEXT        DEFAULT NULL,
  `created_by`      INT(11)     DEFAULT NULL,
  `created_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_shop`    (`shop_id`),
  KEY `idx_sm_product` (`product_id`),
  CONSTRAINT `fk_sm_shop`    FOREIGN KEY (`shop_id`)    REFERENCES `shops`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FEATURE USAGE + CUSTOMER RETURNS
-- ============================================================
CREATE TABLE IF NOT EXISTS `shop_feature_usage` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `shop_id` INT(11) NOT NULL,
  `feature_key` VARCHAR(50) NOT NULL,
  `first_used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `use_count` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feature_shop` (`shop_id`,`feature_key`),
  KEY `idx_feature_key` (`feature_key`),
  CONSTRAINT `fk_feature_usage_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_returns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `shop_id` INT(11) NOT NULL,
  `sale_id` INT(11) NOT NULL,
  `customer_name` VARCHAR(255) DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,
  `refund_method` VARCHAR(30) NOT NULL DEFAULT 'cash',
  `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'completed',
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_returns_shop` (`shop_id`),
  KEY `idx_returns_sale` (`sale_id`),
  CONSTRAINT `fk_returns_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_returns_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_return_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `return_id` INT(11) NOT NULL,
  `sale_item_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `refund_amount` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return_items_return` (`return_id`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `customer_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`),
  CONSTRAINT `fk_return_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. PURCHASES  (stock purchase / supplier invoices)
-- ============================================================
CREATE TABLE IF NOT EXISTS `purchases` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `purchase_invoice_id` INT(11) DEFAULT NULL,
  `shop_id`       INT(11)       NOT NULL,
  `product_id`    INT(11)       NOT NULL,
  `supplier_id`   INT(11)       DEFAULT NULL,
  `supplier_name` VARCHAR(255)  DEFAULT NULL,
  `quantity`      INT(11)       NOT NULL,
  `unit_price`    DECIMAL(10,2) NOT NULL,
  `total_amount`  DECIMAL(10,2) NOT NULL,
  `amount_paid`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_date` DATE          NOT NULL,
  `invoice_no`    VARCHAR(100)  DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `created_by`    INT(11)       DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pur_shop`    (`shop_id`),
  KEY `idx_pur_product` (`product_id`),
  KEY `idx_pur_invoice` (`purchase_invoice_id`),
  KEY `idx_pur_supplier` (`supplier_id`),
  KEY `idx_pur_date`    (`purchase_date`),
  CONSTRAINT `fk_pur_shop`    FOREIGN KEY (`shop_id`)    REFERENCES `shops`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15A. SUPPLIERS & SUPPLIER PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `shop_id` INT(11) NOT NULL,
  `name` VARCHAR(255) NOT NULL, `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL, `address` TEXT DEFAULT NULL,
  `opening_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_supplier_shop_name` (`shop_id`,`name`),
  KEY `idx_supplier_shop` (`shop_id`),
  CONSTRAINT `fk_supplier_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `shop_id` INT(11) NOT NULL, `supplier_id` INT(11) NOT NULL,
  `supplier_name` VARCHAR(255) NOT NULL, `invoice_no` VARCHAR(100) NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL, `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_date` DATE NOT NULL, `notes` TEXT DEFAULT NULL, `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_purchase_invoice_shop_no` (`shop_id`,`invoice_no`),
  KEY `idx_purchase_invoice_supplier` (`supplier_id`), KEY `idx_purchase_invoice_date` (`purchase_date`),
  CONSTRAINT `fk_purchase_invoice_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_invoice_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `shop_id` INT(11) NOT NULL, `supplier_id` INT(11) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL, `payment_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash', `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL, `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_supplier_payment_shop` (`shop_id`), KEY `idx_supplier_payment_supplier` (`supplier_id`),
  CONSTRAINT `fk_supplier_payment_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_payment_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. EXPENSES
-- ============================================================
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`        INT(11)       NOT NULL,
  `category`       VARCHAR(100)  NOT NULL DEFAULT 'General',
  `description`    VARCHAR(500)  NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `expense_date`   DATE          NOT NULL,
  `payment_method` VARCHAR(50)   NOT NULL DEFAULT 'cash',
  `reference_no`   VARCHAR(255)  DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_by`     INT(11)       DEFAULT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exp_shop` (`shop_id`),
  KEY `idx_exp_date` (`expense_date`),
  CONSTRAINT `fk_exp_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. DAILY TARGETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `daily_targets` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`         INT(11)       NOT NULL,
  `target_date`     DATE          NOT NULL,
  `target_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `achieved_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dt_shop_date` (`shop_id`, `target_date`),
  KEY `idx_dt_shop` (`shop_id`),
  CONSTRAINT `fk_dt_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. ANNOUNCEMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(255) NOT NULL,
  `message`    TEXT         NOT NULL,
  `type`       VARCHAR(50)  NOT NULL DEFAULT 'info',
  `status`     VARCHAR(20)  NOT NULL DEFAULT 'active',
  `shop_id`    INT(11)      DEFAULT NULL,
  `created_by` INT(11)      DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_shop`   (`shop_id`),
  KEY `idx_ann_status` (`status`),
  CONSTRAINT `fk_ann_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. SETTINGS  (per-shop key/value configuration)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `shop_id`       INT(11)      DEFAULT NULL,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_shop_key` (`shop_id`, `setting_key`),
  KEY `idx_settings_shop` (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. ONLINE ORDERS  (orders submitted from public storefront)
-- ============================================================
CREATE TABLE IF NOT EXISTS `online_orders` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `shop_id`          INT(11)       NOT NULL,
  `order_number`     VARCHAR(100)  NOT NULL,
  `customer_name`    VARCHAR(255)  NOT NULL,
  `customer_phone`   VARCHAR(50)   DEFAULT NULL,
  `customer_address` TEXT          DEFAULT NULL,
  `customer_note`    TEXT          DEFAULT NULL,
  `items`            LONGTEXT      NOT NULL,
  `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`   VARCHAR(50)   NOT NULL DEFAULT 'cod',
  `source`           VARCHAR(50)   NOT NULL DEFAULT 'store',
  `status`           VARCHAR(30)   NOT NULL DEFAULT 'pending',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_online_orders_number` (`order_number`),
  KEY `idx_online_orders_shop` (`shop_id`),
  KEY `idx_online_orders_status` (`status`),
  KEY `idx_online_orders_created` (`created_at`),
  CONSTRAINT `fk_online_orders_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. IMPORT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `import_logs` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `shop_id`       INT(11)      NOT NULL,
  `file_name`     VARCHAR(255) NOT NULL,
  `import_type`   VARCHAR(50)  NOT NULL DEFAULT 'products',
  `total_rows`    INT(11)      NOT NULL DEFAULT 0,
  `success_rows`  INT(11)      NOT NULL DEFAULT 0,
  `failed_rows`   INT(11)      NOT NULL DEFAULT 0,
  `error_details` TEXT         DEFAULT NULL,
  `imported_by`   INT(11)      DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_il_shop` (`shop_id`),
  CONSTRAINT `fk_il_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. EXPORT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `export_logs` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `shop_id`      INT(11)      NOT NULL,
  `export_type`  VARCHAR(100) NOT NULL,
  `file_name`    VARCHAR(255) DEFAULT NULL,
  `filters_used` TEXT         DEFAULT NULL,
  `row_count`    INT(11)      NOT NULL DEFAULT 0,
  `exported_by`  INT(11)      DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_el_shop` (`shop_id`),
  CONSTRAINT `fk_el_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Re-enable foreign-key checks
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DEFAULT DATA — Super Admin
-- Password : admin123  (bcrypt hash)
-- ============================================================
INSERT INTO `admins` (`id`, `name`, `email`, `password`, `role`)
VALUES (1, 'Super Admin', 'admin@stockora.com',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'superadmin')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
