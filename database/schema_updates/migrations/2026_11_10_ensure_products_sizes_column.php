<?php
/**
 * Migration: Ensure sizes column exists on products table.
 * Enables shop owners to define weight-based pricing options and upload tier images.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once dirname(__DIR__, 2) . '/includes/config.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "Database connection not available for migration.\n";
    exit(1);
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM `products` LIKE 'sizes'");
if ($check && mysqli_num_rows($check) === 0) {
    $alter = mysqli_query($conn, "ALTER TABLE `products` ADD COLUMN `sizes` TEXT NULL DEFAULT NULL COMMENT 'JSON array of weight and size pricing options' AFTER `image`");
    if ($alter) {
        echo "Successfully added `sizes` column to `products` table.\n";
    } else {
        echo "Failed to add `sizes` column: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Column `sizes` already exists on `products` table.\n";
}
if ($check) {
    mysqli_free_result($check);
}
