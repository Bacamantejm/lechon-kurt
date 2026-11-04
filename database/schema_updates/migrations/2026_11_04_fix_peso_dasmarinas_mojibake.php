<?php
/**
 * Migration: Auto-repair corrupted mojibake character encoding (Peso Γé▒50, Dasmari├▒a, ñ, etc.)
 * Date: 2026-11-04
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting mojibake repair migration...\n";

// 1. Store Locations
@mysqli_query($conn, "UPDATE store_locations SET store_name = 'Dasmariñas Branch' WHERE (store_id = 1 OR store_name LIKE 'Dasmari%as%' OR store_name LIKE 'Dasmari%a%' OR store_name LIKE '%├%' OR store_name LIKE '%Ã%') AND store_name <> 'Dasmariñas Branch'");
@mysqli_query($conn, "UPDATE store_locations SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%' OR city LIKE '%├%' OR city LIKE '%Ã%') AND city <> 'Dasmariñas'");
@mysqli_query($conn, "UPDATE store_locations SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
echo "Repaired store_locations.\n";

// 2. Users (Branch Managers & Addresses)
@mysqli_query($conn, "UPDATE users SET full_name = 'Dasmariñas Branch Manager' WHERE email = 'dasma@lechondelights.com' AND full_name <> 'Dasmariñas Branch Manager'");
@mysqli_query($conn, "UPDATE users SET business_name = 'Dasmariñas Central Branch' WHERE email = 'dasma@lechondelights.com' AND business_name <> 'Dasmariñas Central Branch'");
@mysqli_query($conn, "UPDATE users SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
echo "Repaired users.\n";

// 3. Partner Vouchers (Replace Γé▒50, â‚±, ré50 with clean ₱50)
@mysqli_query($conn, "UPDATE partner_vouchers SET name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), 'ré50', '₱50'), 'ré25', '₱25') WHERE name LIKE '%Γé▒%' OR name LIKE '%â‚%' OR name LIKE '%ré%'");
@mysqli_query($conn, "UPDATE partner_vouchers SET description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), 'ré50', '₱50'), 'ré25', '₱25') WHERE description LIKE '%Γé▒%' OR description LIKE '%â‚%' OR description LIKE '%ré%'");
@mysqli_query($conn, "UPDATE partner_vouchers SET name = 'New User Welcome ₱100 OFF', description = 'Get ₱100 flat discount on your first order.' WHERE code = 'WELCOME100'");
@mysqli_query($conn, "UPDATE partner_vouchers SET name = '₱50 OFF Crispy Lechon Belly', description = 'Save ₱50 on lechon belly rolls and meal boxes.' WHERE code = 'BELLY50'");
@mysqli_query($conn, "UPDATE partner_vouchers SET name = '₱25 OFF Daily Special', description = 'Direct ₱25 discount on lechon meals and extra rice packages.' WHERE code = 'DELIGHT25'");
@mysqli_query($conn, "UPDATE partner_vouchers SET name = '₱50 OFF Justine Specialty', description = 'Enjoy ₱50 flat store discount on all specialty lechon packages.' WHERE code = 'JUSTINE50'");
@mysqli_query($conn, "UPDATE partner_vouchers SET name = '15% OFF Celebration Pre-order', description = 'Save 15% on advance celebrations up to ₱300.' WHERE code = 'LECHON15'");
echo "Repaired partner_vouchers.\n";

// 4. Partner Advertisements
@mysqli_query($conn, "UPDATE partner_advertisements SET title = REPLACE(REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), description = REPLACE(REPLACE(REPLACE(REPLACE(description, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR description LIKE '%Γé▒%' OR description LIKE '%â‚%'");
echo "Repaired partner_advertisements.\n";

// 5. Notifications
@mysqli_query($conn, "UPDATE rider_notifications SET title = REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), message = REPLACE(REPLACE(REPLACE(message, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR message LIKE '%Γé▒%' OR message LIKE '%â‚%'");
@mysqli_query($conn, "UPDATE notifications SET title = REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), message = REPLACE(REPLACE(REPLACE(message, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR message LIKE '%Γé▒%' OR message LIKE '%â‚%'");
echo "Repaired notifications.\n";

// 6. User Saved Addresses
@mysqli_query($conn, "UPDATE user_saved_addresses SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
@mysqli_query($conn, "UPDATE user_saved_addresses SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas'");
echo "Repaired user_saved_addresses.\n";

// 7. Franchise Applications
@mysqli_query($conn, "UPDATE franchise_applications SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
@mysqli_query($conn, "UPDATE franchise_applications SET city_name = 'City of Dasmariñas' WHERE (city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%') AND city_name <> 'City of Dasmariñas'");
echo "Repaired franchise_applications.\n";

echo "Mojibake character repair migration complete!\n";
