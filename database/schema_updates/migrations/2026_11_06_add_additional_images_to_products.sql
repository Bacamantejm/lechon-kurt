-- Migration: Add additional_images column to products table
-- Purpose: Enable shop owners to upload multiple images for menu items and update them multiple times
-- Date: 2026-11-06

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'additional_images'
);

SET @stmt = IF(
    @col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `additional_images` TEXT NULL DEFAULT NULL COMMENT \'JSON array of additional product images\' AFTER `image`',
    'SELECT \'Column additional_images already exists on products table\' AS status'
);

PREPARE alter_if_not_exists FROM @stmt;
EXECUTE alter_if_not_exists;
DEALLOCATE PREPARE alter_if_not_exists;
