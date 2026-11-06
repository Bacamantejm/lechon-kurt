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
} else {
    echo "ensureOnlyJmLechonStoreAndMenu not found, executing inline...\n";
    // Inline execution
    $jm_user_id = 0;
    $u_res = mysqli_query($conn, "SELECT id FROM users WHERE email = 'jm@gmail.com' OR business_name = 'jm lechon' ORDER BY (email = 'jm@gmail.com') DESC, id ASC LIMIT 1");
    if ($u_res && ($u_row = mysqli_fetch_assoc($u_res))) {
        $jm_user_id = (int)$u_row['id'];
    }

    $jm_store_id = 0;
    $s_res = mysqli_query($conn, "SELECT store_id FROM store_locations WHERE " . ($jm_user_id > 0 ? "owner_user_id = {$jm_user_id} OR " : "") . "store_name LIKE '%jm lechon%' OR email = 'jm@gmail.com' ORDER BY store_id ASC LIMIT 1");
    if ($s_res && ($s_row = mysqli_fetch_assoc($s_res))) {
        $jm_store_id = (int)$s_row['store_id'];
    }

    if ($jm_store_id <= 0) {
        $owner_sql = $jm_user_id > 0 ? (int)$jm_user_id : "NULL";
        mysqli_query(
            $conn,
            "INSERT INTO store_locations (store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, latitude, longitude, is_active)
             VALUES (7, {$owner_sql}, 'jm lechon', 'Blk 42 lot 32 50st metrogate, Salawag, City of Dasmariñas, Cavite (Partnership Scope), CALABARZON', 'City of Dasmariñas', 'Cavite (Partnership Scope)', '09055657350', 'jm@gmail.com', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.3468000, 120.9712000, 1)
             ON DUPLICATE KEY UPDATE store_name = 'jm lechon', is_active = 1"
        );
        $jm_store_id = 7;
    }

    mysqli_query($conn, "UPDATE orders SET store_id = {$jm_store_id} WHERE store_id IS NOT NULL AND store_id <> {$jm_store_id}");
    mysqli_query($conn, "UPDATE orders SET pickup_location = {$jm_store_id} WHERE pickup_location IS NOT NULL AND pickup_location <> {$jm_store_id}");
    mysqli_query($conn, "UPDATE riders SET store_id = {$jm_store_id} WHERE store_id IS NOT NULL AND store_id <> {$jm_store_id}");
    mysqli_query($conn, "UPDATE pre_orders SET pickup_location = {$jm_store_id} WHERE pickup_location IS NOT NULL AND pickup_location <> {$jm_store_id}");

    mysqli_query($conn, "DELETE FROM store_locations WHERE store_id <> {$jm_store_id}");
    mysqli_query($conn, "DELETE FROM users WHERE email IN ('dasma@lechondelights.com', 'bacoor@lechondelights.com', 'imus@lechondelights.com', 'tagaytay@lechondelights.com', 'jannasantos@gmail.com', 'justinehero033@gmail.com') OR business_name IN ('Dasmariñas Central Branch', 'Bacoor Express Branch', 'Imus Heritage Branch', 'Tagaytay Ridge Branch', 'Janna Restaurant', 'justine business')");
    
    mysqli_query($conn, "DELETE FROM products WHERE name LIKE '%panis%' OR name LIKE '%tae%' OR name LIKE '%Lydia%' OR name LIKE '%Linda%'");
    if ($jm_user_id > 0) {
        mysqli_query($conn, "UPDATE products SET seller_id = {$jm_user_id}, is_active = 1, is_archived = 0 WHERE id IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 22, 23, 24, 25, 26, 27) OR seller_id IS NULL OR seller_id = 1");
        mysqli_query($conn, "DELETE FROM products WHERE seller_id <> {$jm_user_id}");
    }
}

echo "Migration completed successfully!\n";
