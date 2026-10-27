<?php
/**
 * Migration: Auto-repair corrupted mojibake character encoding in store locations, users, and applications
 * Date: 2026-10-21
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

// 1. Fix store_locations
@mysqli_query($conn, "UPDATE store_locations SET store_name = 'Dasmariñas Branch' WHERE (store_id = 1 OR store_name LIKE 'Dasmari%as%' OR store_name LIKE '%├%' OR store_name LIKE '%Ã%') AND store_name <> 'Dasmariñas Branch'");
@mysqli_query($conn, "UPDATE store_locations SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas'");
@mysqli_query($conn, "UPDATE store_locations SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
echo "Repaired store_locations encoding.\n";

// 2. Fix users
@mysqli_query($conn, "UPDATE users SET full_name = 'Dasmariñas Branch Manager' WHERE email = 'dasma@lechondelights.com'");
@mysqli_query($conn, "UPDATE users SET business_name = 'Dasmariñas Central Branch' WHERE email = 'dasma@lechondelights.com'");
@mysqli_query($conn, "UPDATE users SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
@mysqli_query($conn, "UPDATE users SET full_name = REPLACE(REPLACE(REPLACE(REPLACE(full_name, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ') WHERE full_name LIKE '%├%' OR full_name LIKE '%Ã%'");
echo "Repaired users encoding.\n";

// 3. Fix franchise_applications
@mysqli_query($conn, "UPDATE franchise_applications SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
@mysqli_query($conn, "UPDATE franchise_applications SET city_name = 'City of Dasmariñas' WHERE city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%'");
echo "Repaired franchise_applications encoding.\n";

echo "Mojibake character repair migration complete!\n";
