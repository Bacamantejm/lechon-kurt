<?php
/**
 * Migration: Ensure additional_images column on products table.
 * Enables shop owners to upload multiple menu photos and update them without restrictions.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once dirname(__DIR__, 2) . '/includes/config.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "Database connection not available for migration.\n";
    exit(1);
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM `products` LIKE 'additional_images'");
if ($check && mysqli_num_rows($check) === 0) {
    $alter = mysqli_query($conn, "ALTER TABLE `products` ADD COLUMN `additional_images` TEXT NULL DEFAULT NULL COMMENT 'JSON array of additional product images' AFTER `image`");
    if ($alter) {
        echo "Successfully added `additional_images` column to `products` table.\n";
    } else {
        echo "Failed to add `additional_images` column: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Column `additional_images` already exists on `products` table.\n";
}
if ($check) {
    mysqli_free_result($check);
}
