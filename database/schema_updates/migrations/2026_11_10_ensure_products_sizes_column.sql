-- Migration: Ensure sizes column exists on products table
-- Purpose: Enable shop owners to define weight-based and size-based pricing options with custom images
-- Date: 2026-11-10

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'sizes'
);

SET @stmt = IF(
    @col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `sizes` TEXT NULL DEFAULT NULL COMMENT \'JSON array of weight and size pricing options\' AFTER `image`',
    'SELECT \'Column sizes already exists on products table\' AS status'
);

PREPARE alter_if_not_exists FROM @stmt;
EXECUTE alter_if_not_exists;
DEALLOCATE PREPARE alter_if_not_exists;
