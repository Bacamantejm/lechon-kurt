-- =======================================================
-- SCHEMA UPDATE: RIDER DASHBOARD & PLATFORM / SHOP DELIVERY
-- Tables: riders, cod_collections, rider_earnings, 
--         delivery_proofs, rider_notifications, rider_support_tickets
-- =======================================================

CREATE TABLE IF NOT EXISTS `riders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `employee_id` INT NULL,
    `rider_code` VARCHAR(50) NOT NULL UNIQUE,
    `rider_type` ENUM('shop_rider', 'platform_rider') NOT NULL DEFAULT 'platform_rider',
    `store_id` INT NULL,
    `vehicle_type` VARCHAR(50) NOT NULL DEFAULT 'Motorcycle',
    `vehicle_plate` VARCHAR(30) NULL,
    `license_number` VARCHAR(50) NULL,
    `verification_status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'verified',
    `duty_status` ENUM('offline', 'online', 'busy') NOT NULL DEFAULT 'offline',
    `current_latitude` DECIMAL(10,8) NULL,
    `current_longitude` DECIMAL(11,8) NULL,
    `last_location_update` DATETIME NULL,
    `rating` DECIMAL(3,2) NOT NULL DEFAULT 5.00,
    `total_completed_deliveries` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_duty_status` (`duty_status`),
    INDEX `idx_rider_type` (`rider_type`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_verification` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cod_collections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `tracking_id` INT NULL,
    `rider_id` INT NOT NULL,
    `order_total` DECIMAL(10,2) NOT NULL,
    `cash_received` DECIMAL(10,2) NOT NULL,
    `change_given` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `remitted_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `remittance_status` ENUM('pending', 'remitted', 'verified') NOT NULL DEFAULT 'pending',
    `remittance_reference` VARCHAR(100) NULL,
    `remitted_at` DATETIME NULL,
    `collected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` TEXT NULL,
    INDEX `idx_cod_order` (`order_id`),
    INDEX `idx_cod_rider` (`rider_id`),
    INDEX `idx_cod_status` (`remittance_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_earnings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `tracking_id` INT NULL,
    `rider_id` INT NOT NULL,
    `base_delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    `distance_bonus` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `customer_tip` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_earnings` DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    `payout_status` ENUM('pending', 'credited', 'paid_out') NOT NULL DEFAULT 'credited',
    `earned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_earn_order` (`order_id`),
    INDEX `idx_earn_rider` (`rider_id`),
    INDEX `idx_earn_date` (`earned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_proofs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `tracking_id` INT NULL,
    `rider_id` INT NOT NULL,
    `verification_type` ENUM('pin', 'photo', 'signature', 'qr') NOT NULL DEFAULT 'pin',
    `delivery_pin` VARCHAR(10) NULL,
    `pin_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `photo_path` VARCHAR(255) NULL,
    `signature_path` VARCHAR(255) NULL,
    `recipient_name` VARCHAR(100) NULL,
    `delivery_notes` TEXT NULL,
    `delivered_latitude` DECIMAL(10,8) NULL,
    `delivered_longitude` DECIMAL(11,8) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_proof_order` (`order_id`),
    INDEX `idx_proof_rider` (`rider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rider_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('delivery_request', 'order_ready', 'instruction_update', 'earnings', 'cod_remittance', 'system') NOT NULL DEFAULT 'system',
    `order_id` INT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notif_rider` (`rider_id`),
    INDEX `idx_notif_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_payouts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rider_id` INT NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL,
    `payout_status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    `payment_reference` VARCHAR(100) NULL,
    `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payout_rider` (`rider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_support_tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rider_id` INT NOT NULL,
    `order_id` INT NULL,
    `issue_category` ENUM('customer_unavailable', 'restaurant_problem', 'wrong_address', 'damaged_food', 'vehicle_problem', 'emergency', 'payment_problem', 'cod_problem', 'other') NOT NULL,
    `description` TEXT NOT NULL,
    `status` ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    `admin_response` TEXT NULL,
    `waiting_time_minutes` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ticket_rider` (`rider_id`),
    INDEX `idx_ticket_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
