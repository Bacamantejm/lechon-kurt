-- ============================================================================
-- Cavite Sample Stores & Franchise Branches Seed
-- ============================================================================
-- Purpose:
--   Seeds all 6 official Cavite stores into `store_locations`, creates
--   their store owner/manager user accounts in `users`, and links their
--   operating roasting schedules in `shop_preorder_schedules`.
-- ============================================================================

START TRANSACTION;

-- 1. Ensure `store_locations` table exists
CREATE TABLE IF NOT EXISTS `store_locations` (
    `store_id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner_user_id` INT NULL,
    `store_name` VARCHAR(180) NOT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(120) DEFAULT NULL,
    `province` VARCHAR(120) DEFAULT 'Cavite',
    `phone` VARCHAR(60) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `opening_hours` VARCHAR(120) DEFAULT NULL,
    `opening_time` TIME DEFAULT '08:00:00',
    `closing_time` TIME DEFAULT '20:00:00',
    `operating_days` VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
    `availability_mode` ENUM('schedule','manual') NOT NULL DEFAULT 'schedule',
    `manual_status` ENUM('open','away','closed') NOT NULL DEFAULT 'closed',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_owner_user_id` (`owner_user_id`),
    KEY `idx_store_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Insert or update Branch Manager & Owner Accounts (Password: password123)
-- Hash for 'password123': $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
SET @pw_hash := '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- Dasmariñas Central Branch Manager
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('dasma@lechondelights.com', @pw_hash, 'Dasmariñas Branch Manager', 'Dasmariñas Central Branch', 'admin', 2, 'Emilio Aguinaldo Highway, Zone IV, Dasmariñas, Cavite', '0917-123-4567', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'Dasmariñas Branch Manager', `business_name` = 'Dasmariñas Central Branch', `address` = 'Emilio Aguinaldo Highway, Zone IV, Dasmariñas, Cavite', `is_active` = 1;

-- Bacoor Express Branch Manager
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('bacoor@lechondelights.com', @pw_hash, 'Bacoor Branch Manager', 'Bacoor Express Branch', 'admin', 2, 'Tirona Highway, Habay I, Bacoor, Cavite', '0917-234-5678', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'Bacoor Branch Manager', `business_name` = 'Bacoor Express Branch', `address` = 'Tirona Highway, Habay I, Bacoor, Cavite', `is_active` = 1;

-- Imus Heritage Branch Manager
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('imus@lechondelights.com', @pw_hash, 'Imus Branch Manager', 'Imus Heritage Branch', 'admin', 2, 'Nueno Avenue, Poblacion, Imus, Cavite', '0917-345-6789', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'Imus Branch Manager', `business_name` = 'Imus Heritage Branch', `address` = 'Nueno Avenue, Poblacion, Imus, Cavite', `is_active` = 1;

-- Tagaytay Ridge Branch Manager
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('tagaytay@lechondelights.com', @pw_hash, 'Tagaytay Branch Manager', 'Tagaytay Ridge Branch', 'admin', 2, 'Tagaytay-Nasugbu Highway, Maharlika West, Tagaytay, Cavite', '0917-456-7890', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'Tagaytay Branch Manager', `business_name` = 'Tagaytay Ridge Branch', `address` = 'Tagaytay-Nasugbu Highway, Maharlika West, Tagaytay, Cavite', `is_active` = 1;

-- Janna Restaurant Owner (General Trias)
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('jannasantos@gmail.com', @pw_hash, 'Janna Santos', 'Janna Restaurant', 'admin', 2, 'Governor\'s Drive, Manggahan, General Trias, Cavite 4107', '0991-747-1286', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'Janna Santos', `business_name` = 'Janna Restaurant', `address` = 'Governor\'s Drive, Manggahan, General Trias, Cavite 4107', `is_active` = 1;

-- Justine Business Owner (Silang)
INSERT INTO `users` (`email`, `password`, `full_name`, `business_name`, `user_type`, `role_id`, `address`, `phone`, `is_active`, `account_control_status`)
VALUES ('justinehero033@gmail.com', @pw_hash, 'justine santos', 'justine business', 'admin', 2, 'J.P. Rizal St., Biga I, Silang, Cavite 4118', '0991-747-1283', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = 'justine santos', `business_name` = 'justine business', `address` = 'J.P. Rizal St., Biga I, Silang, Cavite 4118', `is_active` = 1;

-- 3. Upsert the 6 Cavite Stores in `store_locations`
-- Store 1: Dasmariñas
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (1, (SELECT `id` FROM `users` WHERE `email` = 'dasma@lechondelights.com' LIMIT 1), 'Dasmariñas Central Branch', 'Emilio Aguinaldo Highway, Zone IV', 'Dasmariñas', 'Cavite', '0917-123-4567', 'dasma@lechondelights.com', '8:00 AM - 10:00 PM', '08:00:00', '22:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.3294000, 120.9367000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

-- Store 2: Bacoor
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (2, (SELECT `id` FROM `users` WHERE `email` = 'bacoor@lechondelights.com' LIMIT 1), 'Bacoor Express Branch', 'Tirona Highway, Habay I', 'Bacoor', 'Cavite', '0917-234-5678', 'bacoor@lechondelights.com', '8:00 AM - 10:00 PM', '08:00:00', '22:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.4445000, 120.9439000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

-- Store 3: Imus
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (3, (SELECT `id` FROM `users` WHERE `email` = 'imus@lechondelights.com' LIMIT 1), 'Imus Heritage Branch', 'Nueno Avenue, Poblacion', 'Imus', 'Cavite', '0917-345-6789', 'imus@lechondelights.com', '8:00 AM - 10:00 PM', '08:00:00', '22:00:00', '1,2,3,4,5,6,7', 'manual', 'open', 14.4296000, 120.9367000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

-- Store 4: Tagaytay
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (4, (SELECT `id` FROM `users` WHERE `email` = 'tagaytay@lechondelights.com' LIMIT 1), 'Tagaytay Ridge Branch', 'Tagaytay-Nasugbu Highway, Maharlika West', 'Tagaytay', 'Cavite', '0917-456-7890', 'tagaytay@lechondelights.com', '8:00 AM - 9:00 PM', '08:00:00', '21:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.1153000, 120.9621000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

-- Store 5: General Trias (Janna Restaurant)
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (5, (SELECT `id` FROM `users` WHERE `email` = 'jannasantos@gmail.com' LIMIT 1), 'Janna Restaurant (Gen. Trias)', 'Governor\'s Drive, Manggahan', 'General Trias', 'Cavite', '0991-747-1286', 'jannasantos@gmail.com', '8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.2818000, 120.8800000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

-- Store 6: Silang (justine business)
INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`)
VALUES (6, (SELECT `id` FROM `users` WHERE `email` = 'justinehero033@gmail.com' LIMIT 1), 'justine business (Silang Depot)', 'J.P. Rizal St., Biga I', 'Silang', 'Cavite', '0991-747-1283', 'justinehero033@gmail.com', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.2307000, 120.9749000, 1)
ON DUPLICATE KEY UPDATE `store_name` = VALUES(`store_name`), `address` = VALUES(`address`), `city` = VALUES(`city`), `province` = VALUES(`province`), `phone` = VALUES(`phone`), `email` = VALUES(`email`), `opening_hours` = VALUES(`opening_hours`), `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`), `is_active` = 1;

COMMIT;
