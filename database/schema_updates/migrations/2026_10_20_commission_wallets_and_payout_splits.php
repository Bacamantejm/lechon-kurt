<?php
/**
 * Migration: Commission Splitting, Store Wallets, and Rider Remittance Tracking
 * Date: 2026-10-20
 * 
 * Tables created / altered:
 * - orders: adds store_id, rider_id
 * - order_payout_splits
 * - store_wallets
 * - store_transactions
 * - rider_wallets
 * - rider_transactions
 */

$root_path = dirname(__DIR__, 3);
require_once $root_path . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

echo "=== Running Commission & Store Wallet Schema Migration ===\n";

// Helper functions for safe alterations
function migrationTableExists(mysqli $conn, string $table): bool {
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function migrationColumnExists(mysqli $conn, string $table, string $column): bool {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `" . mysqli_real_escape_string($conn, $table) . "` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function migrationIndexExists(mysqli $conn, string $table, string $index): bool {
    $res = mysqli_query($conn, "SHOW INDEX FROM `" . mysqli_real_escape_string($conn, $table) . "` WHERE Key_name = '" . mysqli_real_escape_string($conn, $index) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

// 1. Add store_id and rider_id to orders table if missing
if (!migrationColumnExists($conn, 'orders', 'store_id')) {
    mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `store_id` INT NULL AFTER `seller_id`");
    echo "Added store_id to orders.\n";
}
if (!migrationIndexExists($conn, 'orders', 'idx_orders_store_id')) {
    mysqli_query($conn, "ALTER TABLE `orders` ADD INDEX `idx_orders_store_id` (`store_id`)");
    echo "Added index idx_orders_store_id on orders.\n";
}

if (!migrationColumnExists($conn, 'orders', 'rider_id')) {
    mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `rider_id` INT NULL AFTER `store_id`");
    echo "Added rider_id to orders.\n";
}
if (!migrationIndexExists($conn, 'orders', 'idx_orders_rider_id')) {
    mysqli_query($conn, "ALTER TABLE `orders` ADD INDEX `idx_orders_rider_id` (`rider_id`)");
    echo "Added index idx_orders_rider_id on orders.\n";
}

// Backfill store_id on orders from pickup_location or seller_id
mysqli_query($conn, "
    UPDATE orders o
    LEFT JOIN store_locations sl ON sl.owner_user_id = o.seller_id
    SET o.store_id = COALESCE(NULLIF(o.pickup_location, 0), sl.store_id, 1)
    WHERE o.store_id IS NULL OR o.store_id = 0
");
echo "Backfilled orders.store_id.\n";

// Backfill rider_id on orders from logistics_tracking
if (migrationTableExists($conn, 'logistics_tracking') && migrationColumnExists($conn, 'logistics_tracking', 'driver_id')) {
    mysqli_query($conn, "
        UPDATE orders o
        JOIN logistics_tracking lt ON lt.order_id = o.id
        SET o.rider_id = lt.driver_id
        WHERE (o.rider_id IS NULL OR o.rider_id = 0) AND lt.driver_id IS NOT NULL AND lt.driver_id > 0
    ");
    echo "Backfilled orders.rider_id from logistics_tracking.\n";
}

// 2. Create order_payout_splits table
$sql_splits = "
CREATE TABLE IF NOT EXISTS `order_payout_splits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `store_id` INT NOT NULL,
    `rider_id` INT NULL,
    `food_subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    `platform_commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_service_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `platform_cut` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `store_net_cut` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `base_delivery_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `distance_surcharge` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `customer_tip` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `delivery_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `rider_net_cut` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_bill` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cod', 'online') NOT NULL DEFAULT 'cod',
    `split_status` ENUM('pending', 'settled', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `settled_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_order_payout_split` (`order_id`, `store_id`),
    KEY `idx_split_store_id` (`store_id`, `created_at`),
    KEY `idx_split_rider_id` (`rider_id`),
    KEY `idx_split_status` (`split_status`),
    KEY `idx_split_payment_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysqli_query($conn, $sql_splits)) {
    echo "Table order_payout_splits verified/created.\n";
} else {
    echo "Error creating order_payout_splits: " . mysqli_error($conn) . "\n";
}

// 3. Create store_wallets table
$sql_wallets = "
CREATE TABLE IF NOT EXISTS `store_wallets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT NOT NULL,
    `available_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pending_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_lifetime_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_withdrawn` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `last_payout_at` DATETIME NULL,
    `payout_method` VARCHAR(50) NOT NULL DEFAULT 'gcash',
    `payout_account_number` VARCHAR(100) NULL,
    `payout_account_name` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_store_wallet_store` (`store_id`),
    KEY `idx_store_wallet_balance` (`available_balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysqli_query($conn, $sql_wallets)) {
    echo "Table store_wallets verified/created.\n";
} else {
    echo "Error creating store_wallets: " . mysqli_error($conn) . "\n";
}

// 4. Create store_transactions table
$sql_store_tx = "
CREATE TABLE IF NOT EXISTS `store_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT NOT NULL,
    `order_id` INT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `type` ENUM('order_credit', 'cashout_debit', 'fee_deduction', 'adjustment') NOT NULL,
    `reference_no` VARCHAR(100) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `description` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_store_tx_store` (`store_id`, `created_at`),
    KEY `idx_store_tx_order` (`order_id`),
    KEY `idx_store_tx_ref` (`reference_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysqli_query($conn, $sql_store_tx)) {
    echo "Table store_transactions verified/created.\n";
} else {
    echo "Error creating store_transactions: " . mysqli_error($conn) . "\n";
}

// 5. Create rider_wallets table
$sql_rider_wallets = "
CREATE TABLE IF NOT EXISTS `rider_wallets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rider_id` INT NOT NULL,
    `available_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `remittance_debt` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `safety_ceiling` DECIMAL(12,2) NOT NULL DEFAULT 3000.00,
    `status` ENUM('active', 'blocked_remittance_exceeded', 'suspended') NOT NULL DEFAULT 'active',
    `last_remitted_at` DATETIME NULL,
    `total_lifetime_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_rider_wallet_rider` (`rider_id`),
    KEY `idx_rider_wallet_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysqli_query($conn, $sql_rider_wallets)) {
    echo "Table rider_wallets verified/created.\n";
} else {
    echo "Error creating rider_wallets: " . mysqli_error($conn) . "\n";
}

// 6. Create rider_transactions table
$sql_rider_tx = "
CREATE TABLE IF NOT EXISTS `rider_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rider_id` INT NOT NULL,
    `order_id` INT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `type` ENUM('order_credit', 'remittance_payment', 'cod_debt_incurred', 'cashout_debit') NOT NULL,
    `reference_no` VARCHAR(100) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `debt_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rider_tx_rider` (`rider_id`, `created_at`),
    KEY `idx_rider_tx_order` (`order_id`),
    KEY `idx_rider_tx_ref` (`reference_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (mysqli_query($conn, $sql_rider_tx)) {
    echo "Table rider_transactions verified/created.\n";
} else {
    echo "Error creating rider_transactions: " . mysqli_error($conn) . "\n";
}

// 7. Seed store_wallets for all existing store_locations
mysqli_query($conn, "
    INSERT INTO store_wallets (store_id, available_balance, pending_balance, total_lifetime_earnings)
    SELECT sl.store_id, 0.00, 0.00, 0.00
    FROM store_locations sl
    LEFT JOIN store_wallets sw ON sw.store_id = sl.store_id
    WHERE sw.id IS NULL
");
echo "Seeded store_wallets for all store_locations.\n";

// 8. Seed rider_wallets for all existing riders
mysqli_query($conn, "
    INSERT INTO rider_wallets (rider_id, available_balance, remittance_debt, safety_ceiling, status)
    SELECT r.id, 0.00, 0.00, 3000.00, 'active'
    FROM riders r
    LEFT JOIN rider_wallets rw ON rw.rider_id = r.id
    WHERE rw.id IS NULL
");
echo "Seeded rider_wallets for all riders.\n";

echo "=== Migration Finished Successfully ===\n";
