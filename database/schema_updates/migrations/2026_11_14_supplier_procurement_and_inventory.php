<?php
/**
 * Migration: Supplier Procurement & Ingredient Inventory Automation
 * Date: 2026-11-14
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting migration: Supplier Procurement & Ingredient Inventory Automation...\n";

// Helper to check column existence
function migrationEnsureColumn($conn, $table, $column, $definition) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if ($res && mysqli_num_rows($res) === 0) {
        mysqli_query($conn, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "Added column {$column} to {$table}.\n";
    }
}

// 1. Ensure columns on suppliers table
migrationEnsureColumn($conn, 'suppliers', 'user_id', 'INT NULL AFTER `id`');
migrationEnsureColumn($conn, 'suppliers', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `address`');

// 2. store_material_inventory
$sql1 = "CREATE TABLE IF NOT EXISTS `store_material_inventory` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
if (mysqli_query($conn, $sql1)) {
    echo "Checked store_material_inventory table.\n";
}

// 3. supplier_catalog
$sql2 = "CREATE TABLE IF NOT EXISTS `supplier_catalog` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
if (mysqli_query($conn, $sql2)) {
    echo "Checked supplier_catalog table.\n";
}

// 4. supplier_orders
$sql3 = "CREATE TABLE IF NOT EXISTS `supplier_orders` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
if (mysqli_query($conn, $sql3)) {
    echo "Checked supplier_orders table.\n";
}

// 5. supplier_order_items
$sql4 = "CREATE TABLE IF NOT EXISTS `supplier_order_items` (
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
  KEY `idx_material_id` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
if (mysqli_query($conn, $sql4)) {
    echo "Checked supplier_order_items table.\n";
}

// 6. supplier_order_status_logs
$sql5 = "CREATE TABLE IF NOT EXISTS `supplier_order_status_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `order_id` INT NOT NULL,
  `previous_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `action_by_user_id` INT NOT NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_log` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
if (mysqli_query($conn, $sql5)) {
    echo "Checked supplier_order_status_logs table.\n";
}

echo "Supplier procurement migration script ready.\n";
