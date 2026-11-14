<?php
/**
 * Migration runner: Procurement Finance & Self-Procurement Flow
 */

require_once __DIR__ . '/../../../includes/config.php';

if (!$conn) {
    echo "DB connection unavailable.\n";
    exit(1);
}

function ensureColumn($conn, $table, $column, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && mysqli_num_rows($res) === 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $def");
        echo "Added column $table.$column\n";
    }
}

// 1. Columns for supplier_orders
ensureColumn($conn, 'supplier_orders', 'procurement_type', "ENUM('supplier', 'self_procured') NOT NULL DEFAULT 'supplier' AFTER `store_id`");
ensureColumn($conn, 'supplier_orders', 'vendor_name', "VARCHAR(255) NULL AFTER `procurement_type`");
ensureColumn($conn, 'supplier_orders', 'receipt_image', "VARCHAR(255) NULL AFTER `vendor_name`");
ensureColumn($conn, 'supplier_orders', 'invoice_number', "VARCHAR(100) NULL AFTER `receipt_image`");
ensureColumn($conn, 'supplier_orders', 'finance_status', "ENUM('not_required', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `payment_status`");
ensureColumn($conn, 'supplier_orders', 'finance_approved_by', "INT NULL AFTER `finance_status`");
ensureColumn($conn, 'supplier_orders', 'finance_approved_at', "DATETIME NULL AFTER `finance_approved_by`");
ensureColumn($conn, 'supplier_orders', 'finance_notes', "TEXT NULL AFTER `finance_approved_at`");
ensureColumn($conn, 'supplier_orders', 'received_by_store_at', "DATETIME NULL AFTER `completed_at`");

// 2. Modify status enum on supplier_orders
mysqli_query($conn, "
    ALTER TABLE `supplier_orders` 
    MODIFY COLUMN `status` ENUM(
        'pending', 
        'awaiting_finance_approval', 
        'finance_approved', 
        'modified', 
        'accepted', 
        'processing', 
        'packed', 
        'in_transit', 
        'delivered', 
        'completed', 
        'rejected', 
        'cancelled'
    ) NOT NULL DEFAULT 'pending'
");

// 3. Create procurement_shopping_lists table
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `procurement_shopping_lists` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `store_id` INT NOT NULL,
      `title` VARCHAR(150) NOT NULL DEFAULT 'Market Shopping List',
      `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
      `items_json` JSON NOT NULL,
      `target_budget` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `actual_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `notes` TEXT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_store_shopping` (`store_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

echo "Procurement Finance and Self-Procurement migration applied successfully.\n";
