-- Migration: Auto-repair corrupted mojibake character encoding (Peso Γé▒50, Dasmari├▒a, ñ, etc.)
-- Date: 2026-11-04

-- 1. Store Locations
UPDATE store_locations SET store_name = 'Dasmariñas Branch' WHERE (store_id = 1 OR store_name LIKE 'Dasmari%as%' OR store_name LIKE 'Dasmari%a%' OR store_name LIKE '%├%' OR store_name LIKE '%Ã%') AND store_name <> 'Dasmariñas Branch';
UPDATE store_locations SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%' OR city LIKE '%├%' OR city LIKE '%Ã%') AND city <> 'Dasmariñas';
UPDATE store_locations SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%';

-- 2. Users (Branch Managers & Addresses)
UPDATE users SET full_name = 'Dasmariñas Branch Manager' WHERE email = 'dasma@lechondelights.com' AND full_name <> 'Dasmariñas Branch Manager';
UPDATE users SET business_name = 'Dasmariñas Central Branch' WHERE email = 'dasma@lechondelights.com' AND business_name <> 'Dasmariñas Central Branch';
UPDATE users SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%';

-- 3. Partner Vouchers (Replace Γé▒50, â‚±, ré50 with clean ₱50)
UPDATE partner_vouchers SET name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), 'ré50', '₱50'), 'ré25', '₱25') WHERE name LIKE '%Γé▒%' OR name LIKE '%â‚%' OR name LIKE '%ré%';
UPDATE partner_vouchers SET description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), 'ré50', '₱50'), 'ré25', '₱25') WHERE description LIKE '%Γé▒%' OR description LIKE '%â‚%' OR description LIKE '%ré%';
UPDATE partner_vouchers SET name = 'New User Welcome ₱100 OFF', description = 'Get ₱100 flat discount on your first order.' WHERE code = 'WELCOME100';
UPDATE partner_vouchers SET name = '₱50 OFF Crispy Lechon Belly', description = 'Save ₱50 on lechon belly rolls and meal boxes.' WHERE code = 'BELLY50';
UPDATE partner_vouchers SET name = '₱25 OFF Daily Special', description = 'Direct ₱25 discount on lechon meals and extra rice packages.' WHERE code = 'DELIGHT25';
UPDATE partner_vouchers SET name = '₱50 OFF Justine Specialty', description = 'Enjoy ₱50 flat store discount on all specialty lechon packages.' WHERE code = 'JUSTINE50';
UPDATE partner_vouchers SET name = '15% OFF Celebration Pre-order', description = 'Save 15% on advance celebrations up to ₱300.' WHERE code = 'LECHON15';

-- 4. Partner Advertisements
UPDATE partner_advertisements SET title = REPLACE(REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱'), description = REPLACE(REPLACE(REPLACE(REPLACE(description, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱'), 'â‚', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR description LIKE '%Γé▒%' OR description LIKE '%â‚%';

-- 5. Notifications
UPDATE rider_notifications SET title = REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), message = REPLACE(REPLACE(REPLACE(message, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR message LIKE '%Γé▒%' OR message LIKE '%â‚%';
UPDATE notifications SET title = REPLACE(REPLACE(title, 'Γé▒', '₱'), 'â‚±', '₱'), message = REPLACE(REPLACE(REPLACE(message, 'Ã¢â€šÂ±', '₱'), 'Γé▒', '₱'), 'â‚±', '₱') WHERE title LIKE '%Γé▒%' OR title LIKE '%â‚%' OR message LIKE '%Γé▒%' OR message LIKE '%â‚%';

-- 6. User Saved Addresses
UPDATE user_saved_addresses SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%';
UPDATE user_saved_addresses SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas';

-- 7. Franchise Applications
UPDATE franchise_applications SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ'), 'Dasmari├▒a', 'Dasmariña') WHERE address LIKE '%├%' OR address LIKE '%Ã%';
UPDATE franchise_applications SET city_name = 'City of Dasmariñas' WHERE (city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%') AND city_name <> 'City of Dasmariñas';
