-- Migration: Remove all sample stores and menu items for JM Lechon dry-run
-- Date: 2026-11-06

START TRANSACTION;

-- 1. Identify JM Lechon user id
SET @jm_user_id := (SELECT id FROM users WHERE email = 'jm@gmail.com' OR business_name = 'jm lechon' ORDER BY (email = 'jm@gmail.com') DESC, id ASC LIMIT 1);

-- 2. Ensure JM Lechon store exists in store_locations as store_id 7
INSERT INTO store_locations (store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, latitude, longitude, is_active)
VALUES (7, @jm_user_id, 'jm lechon', 'Blk 42 lot 32 50st metrogate, Salawag, City of Dasmariñas, Cavite (Partnership Scope), CALABARZON', 'City of Dasmariñas', 'Cavite (Partnership Scope)', '09055657350', 'jm@gmail.com', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 14.3468000, 120.9712000, 1)
ON DUPLICATE KEY UPDATE store_name = 'jm lechon', is_active = 1, owner_user_id = COALESCE(owner_user_id, @jm_user_id);

SET @jm_store_id := (SELECT store_id FROM store_locations WHERE owner_user_id = @jm_user_id OR store_name = 'jm lechon' OR email = 'jm@gmail.com' ORDER BY (store_id = 7) DESC, store_id ASC LIMIT 1);

-- 3. Re-align orders, riders, pre_orders from sample stores (1..6) to JM Lechon store
UPDATE orders SET store_id = @jm_store_id WHERE store_id IN (1, 2, 3, 4, 5, 6);
UPDATE orders SET pickup_location = @jm_store_id WHERE pickup_location IN (1, 2, 3, 4, 5, 6);
UPDATE riders SET store_id = @jm_store_id WHERE store_id IN (1, 2, 3, 4, 5, 6);
UPDATE pre_orders SET pickup_location = @jm_store_id WHERE pickup_location IN (1, 2, 3, 4, 5, 6);

-- 4. Delete sample stores from store_locations
DELETE FROM store_locations 
WHERE store_id IN (1, 2, 3, 4, 5, 6)
   OR store_name IN ('Dasmariñas Branch', 'Dasmariñas Central Branch', 'Bacoor Branch', 'Bacoor Express Branch', 'Imus Branch', 'Imus Heritage Branch', 'General Trias Branch', 'Tagaytay Branch', 'Tagaytay Ridge Branch', 'Silang Branch', 'Janna Restaurant', 'Janna Restaurant (Gen. Trias)', 'justine business', 'justine business (Silang Depot)', 'Lydias', 'Linda')
   OR email IN ('dasma@lechondelights.com', 'bacoor@lechondelights.com', 'imus@lechondelights.com', 'tagaytay@lechondelights.com', 'gentrias@lechondelights.com', 'silang@lechondelights.com', 'jannasantos@gmail.com', 'justinehero033@gmail.com', 'useraccount@gmail.com', 'localone@gmail.com');

-- 5. Delete sample franchise applications and documents
DELETE FROM franchise_documents 
WHERE application_id IN (
    SELECT id FROM franchise_applications 
    WHERE business_name IN ('justine business', 'Janna Restaurant', 'Lydias', 'Linda', 'ako budoy')
       OR contact_email IN ('jannasantos@gmail.com', 'justinehero033@gmail.com', 'justinehero03@gmail.com')
);

DELETE FROM franchise_applications 
WHERE business_name IN ('justine business', 'Janna Restaurant', 'Lydias', 'Linda', 'ako budoy')
   OR contact_email IN ('jannasantos@gmail.com', 'justinehero033@gmail.com', 'justinehero03@gmail.com');

-- 6. Deactivate & clean sample users
UPDATE users 
SET account_type = 'individual', is_active = 0, business_name = NULL
WHERE email IN ('dasma@lechondelights.com', 'bacoor@lechondelights.com', 'imus@lechondelights.com', 'tagaytay@lechondelights.com', 'gentrias@lechondelights.com', 'silang@lechondelights.com', 'jannasantos@gmail.com', 'justinehero033@gmail.com', 'useraccount@gmail.com', 'localone@gmail.com')
   OR business_name IN ('Dasmariñas Central Branch', 'Bacoor Express Branch', 'Imus Heritage Branch', 'Tagaytay Ridge Branch', 'Janna Restaurant', 'justine business', 'Lydias', 'Linda');

DELETE FROM users 
WHERE email IN ('dasma@lechondelights.com', 'bacoor@lechondelights.com', 'imus@lechondelights.com', 'tagaytay@lechondelights.com', 'gentrias@lechondelights.com', 'silang@lechondelights.com', 'jannasantos@gmail.com', 'justinehero033@gmail.com');

-- 7. Clean shop_preorder_schedules for sample stores
DELETE FROM shop_preorder_schedules WHERE seller_id IN (1, 2, 3, 4, 5, 6);

-- 8. Clean Products / Menu:
DELETE FROM products WHERE name LIKE '%panis%' OR name LIKE '%tae%' OR name LIKE '%Lydia%' OR name LIKE '%Linda%';

-- Assign genuine initial lechon menu items to JM Lechon shop
UPDATE products 
SET seller_id = @jm_user_id, is_active = 1, is_archived = 0 
WHERE (id IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 22, 23, 24, 25, 26, 27) OR seller_id IS NULL OR seller_id = 1)
  AND (seller_id IS NULL OR seller_id NOT IN (SELECT id FROM users WHERE account_type = 'organization' AND id <> @jm_user_id));

-- 9. Clean invalid cart items
DELETE FROM cart WHERE product_id NOT IN (SELECT id FROM products);

COMMIT;
