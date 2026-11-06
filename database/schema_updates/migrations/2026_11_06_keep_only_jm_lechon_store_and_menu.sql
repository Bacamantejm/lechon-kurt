-- Migration: Remove all sample stores and menu items except JM Lechon shop
-- Date: 2026-11-06

START TRANSACTION;

-- 1. Identify JM Lechon user id
SET @jm_user_id := (SELECT id FROM users WHERE email = 'jm@gmail.com' OR business_name = 'jm lechon' ORDER BY (email = 'jm@gmail.com') DESC, id ASC LIMIT 1);

-- 2. Ensure JM Lechon store exists in store_locations as store_id 7
INSERT INTO store_locations (store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, latitude, longitude, is_active)
VALUES (7, @jm_user_id, 'jm lechon', 'Blk 42 lot 32 50st metrogate, Salawag, City of Dasmariñas, Cavite (Partnership Scope), CALABARZON', 'City of Dasmariñas', 'Cavite (Partnership Scope)', '09055657350', 'jm@gmail.com', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.3468000, 120.9712000, 1)
ON DUPLICATE KEY UPDATE store_name = 'jm lechon', is_active = 1, owner_user_id = COALESCE(owner_user_id, @jm_user_id);

SET @jm_store_id := (SELECT store_id FROM store_locations WHERE owner_user_id = @jm_user_id OR store_name LIKE '%jm lechon%' OR email = 'jm@gmail.com' ORDER BY (store_id = 7) DESC, store_id ASC LIMIT 1);

-- 3. Re-align orders, riders, pre_orders to JM Lechon store
UPDATE orders SET store_id = @jm_store_id WHERE store_id IS NOT NULL AND store_id <> @jm_store_id;
UPDATE orders SET pickup_location = @jm_store_id WHERE pickup_location IS NOT NULL AND pickup_location <> @jm_store_id;
UPDATE riders SET store_id = @jm_store_id WHERE store_id IS NOT NULL AND store_id <> @jm_store_id;
UPDATE pre_orders SET pickup_location = @jm_store_id WHERE pickup_location IS NOT NULL AND pickup_location <> @jm_store_id;

-- 4. Delete all other stores from store_locations
DELETE FROM store_locations WHERE store_id <> @jm_store_id;

-- 5. Delete sample branch manager user accounts
DELETE FROM users 
WHERE email IN ('dasma@lechondelights.com', 'bacoor@lechondelights.com', 'imus@lechondelights.com', 'tagaytay@lechondelights.com', 'jannasantos@gmail.com', 'justinehero033@gmail.com')
   OR business_name IN ('Dasmariñas Central Branch', 'Bacoor Express Branch', 'Imus Heritage Branch', 'Tagaytay Ridge Branch', 'Janna Restaurant', 'justine business');

-- 6. Clean shop_preorder_schedules
DELETE FROM shop_preorder_schedules 
WHERE seller_id <> @jm_store_id AND (seller_id <> @jm_user_id OR @jm_user_id IS NULL);

-- 7. Clean Products / Menu:
-- Delete test/mock/joke items
DELETE FROM products 
WHERE name LIKE '%panis%' OR name LIKE '%tae%' OR name LIKE '%Lydia%' OR name LIKE '%Linda%';

-- Assign genuine lechon menu items to JM Lechon shop
UPDATE products 
SET seller_id = @jm_user_id, is_active = 1, is_archived = 0 
WHERE id IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 22, 23, 24, 25, 26, 27) 
   OR seller_id IS NULL 
   OR seller_id = 1;

-- Delete any remaining products that do not belong to JM Lechon
DELETE FROM products 
WHERE (@jm_user_id IS NOT NULL AND @jm_user_id > 0) AND seller_id <> @jm_user_id;

-- 8. Clean invalid cart items
DELETE FROM cart 
WHERE product_id NOT IN (SELECT id FROM products);

COMMIT;
