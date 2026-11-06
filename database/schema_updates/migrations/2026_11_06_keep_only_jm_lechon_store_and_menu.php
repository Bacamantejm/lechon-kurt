<?php
/**
 * Migration: Remove all sample stores and menu items except JM Lechon shop
 * Date: 2026-11-06
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting migration: retain exclusively JM Lechon store and genuine lechon menu...\n";

if (function_exists('ensureOnlyJmLechonStoreAndMenu')) {
    ensureOnlyJmLechonStoreAndMenu($conn);
    echo "Executed ensureOnlyJmLechonStoreAndMenu successfully.\n";
}

echo "Migration completed successfully!\n";
