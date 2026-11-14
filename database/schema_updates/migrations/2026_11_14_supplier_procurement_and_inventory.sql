-- Migration: Supplier Procurement & Ingredient Inventory Automation
-- Date: 2026-11-14

-- 1. Ensure user_id column exists on suppliers table
ALTER TABLE `suppliers` 
ADD COLUMN IF NOT EXISTS `user_id` INT NULL AFTER `id`,
ADD COLUMN IF NOT EXISTS `store_id` INT NULL AFTER `user_id`,
ADD COLUMN IF NOT EXISTS `created_by` INT NULL AFTER `store_id`,
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `address`,
ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) NOT NULL DEFAULT 'active' AFTER `is_active`;

-- 2. Store-level material inventory (supports multi-store/franchisee ingredient stock)
CREATE TABLE IF NOT EXISTS `store_material_inventory` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `store_id` INT NOT NULL,
  `material_id` INT NOT NULL,
  `current_stock` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  `unit` VARCHAR(20) NOT NULL,
  `last_restocked_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_store_material` (`store_id`, `material_id`),
  KEY `idx_material` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Supplier Wholesale Catalog
CREATE TABLE IF NOT EXISTS `supplier_catalog` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `supplier_id` INT NOT NULL,
  `material_id` INT NOT NULL,
  `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `packaging_unit` VARCHAR(50) NOT NULL DEFAULT 'kg',
  `min_order_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `lead_time_hours` INT NOT NULL DEFAULT 24,
  `is_available` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_supplier_material` (`supplier_id`, `material_id`),
  KEY `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Supplier Refill Requests / Orders Header
CREATE TABLE IF NOT EXISTS `supplier_orders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `order_code` VARCHAR(50) NOT NULL UNIQUE,
  `supplier_id` INT NOT NULL,
  `store_id` INT NOT NULL,
  `requested_by` INT NOT NULL,
  `status` ENUM('pending', 'modified', 'accepted', 'processing', 'packed', 'in_transit', 'completed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  `urgency_level` ENUM('normal', 'urgent', 'critical') NOT NULL DEFAULT 'normal',
  `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('pending', 'partial', 'paid') NOT NULL DEFAULT 'pending',
  `store_notes` TEXT NULL,
  `supplier_notes` TEXT NULL,
  `requested_delivery_date` DATE NULL,
  `dispatched_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier_status` (`supplier_id`, `status`),
  KEY `idx_store_status` (`store_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Supplier Order Line Items (with locked historical pricing)
CREATE TABLE IF NOT EXISTS `supplier_order_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `order_id` INT NOT NULL,
  `material_id` INT NOT NULL,
  `quantity_requested` DECIMAL(10,2) NOT NULL,
  `quantity_supplied` DECIMAL(10,2) NOT NULL,
  `unit` VARCHAR(20) NOT NULL,
  `locked_unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_modified` TINYINT(1) NOT NULL DEFAULT 0,
  `modification_reason` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_material_id` (`material_id`),
  CONSTRAINT `fk_supp_order_items` FOREIGN KEY (`order_id`) REFERENCES `supplier_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Supplier Order Status Audit Trail
CREATE TABLE IF NOT EXISTS `supplier_order_status_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `order_id` INT NOT NULL,
  `previous_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `action_by_user_id` INT NOT NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_log` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
