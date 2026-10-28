-- Migration: Add business_banner column to users table for store cover / hero background picture

SET @has_users_banner := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'business_banner');
SET @sql_users_banner := IF(@has_users_banner = 0, 'ALTER TABLE `users` ADD COLUMN `business_banner` VARCHAR(255) NULL DEFAULT NULL AFTER `business_logo`', 'SELECT 1');
PREPARE stmt_banner FROM @sql_users_banner;
EXECUTE stmt_banner;
DEALLOCATE PREPARE stmt_banner;
