-- Migration: Comprehensive repair for corrupted letter encoding (Dasmari├▒as, Dasmari├▒a, ├▒, ├░, ├æ, Ã±, Ã‘, Γé▒, â‚±, etc.)
-- Date: 2026-11-06

-- 1. Orders Table (Delivery addresses, Customer Names, Shipping/Billing)
UPDATE orders 
SET delivery_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(delivery_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), 'DasmariÃ±as', 'Dasmariñas'), 'DasmariÃ±a', 'Dasmariñas'), 'Dasmari├æas', 'Dasmariñas'), 'DasmariÃ‘as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ'), 'Ã©', 'é')
WHERE delivery_address LIKE '%├%' OR delivery_address LIKE '%Ã%';

UPDATE orders 
SET customer_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ')
WHERE customer_name LIKE '%├%' OR customer_name LIKE '%Ã%';

UPDATE orders 
SET shipping_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(shipping_address, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ')
WHERE shipping_address LIKE '%├%' OR shipping_address LIKE '%Ã%';

UPDATE orders 
SET billing_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(billing_address, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ')
WHERE billing_address LIKE '%├%' OR billing_address LIKE '%Ã%';

-- 2. Logistics Tracking
UPDATE logistics_tracking 
SET delivery_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(delivery_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), 'DasmariÃ±as', 'Dasmariñas'), 'DasmariÃ±a', 'Dasmariñas'), 'Dasmari├æas', 'Dasmariñas'), 'DasmariÃ‘as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ'), 'Ã©', 'é')
WHERE delivery_address LIKE '%├%' OR delivery_address LIKE '%Ã%';

UPDATE logistics_tracking 
SET customer_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ')
WHERE customer_name LIKE '%├%' OR customer_name LIKE '%Ã%';

UPDATE logistics_tracking 
SET driver_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(driver_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ')
WHERE driver_name LIKE '%├%' OR driver_name LIKE '%Ã%';

-- 3. Store Locations
UPDATE store_locations 
SET store_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(store_name, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE store_name LIKE '%├%' OR store_name LIKE '%Ã%';

UPDATE store_locations 
SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE store_locations 
SET city = 'Dasmariñas' 
WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas';

-- 4. User Saved Addresses
UPDATE user_saved_addresses 
SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE user_saved_addresses 
SET street_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(street_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE street_address LIKE '%├%' OR street_address LIKE '%Ã%';

UPDATE user_saved_addresses 
SET city = 'Dasmariñas' 
WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas';

UPDATE user_saved_addresses 
SET city_name = 'Dasmariñas' 
WHERE (city_name LIKE 'Dasmari%as%' OR city_name LIKE 'Dasmari%a%' OR city_name LIKE '%Dasmari%') AND city_name <> 'Dasmariñas';

-- 5. Users
UPDATE users 
SET full_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(full_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ'), 'Ã‘', 'Ñ')
WHERE full_name LIKE '%├%' OR full_name LIKE '%Ã%';

UPDATE users 
SET business_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(business_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ'), 'Ã‘', 'Ñ')
WHERE business_name LIKE '%├%' OR business_name LIKE '%Ã%';

UPDATE users 
SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

-- 6. Franchise Applications
UPDATE franchise_applications 
SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE franchise_applications 
SET business_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(business_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ')
WHERE business_address LIKE '%├%' OR business_address LIKE '%Ã%';

UPDATE franchise_applications 
SET city_name = 'City of Dasmariñas' 
WHERE (city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%') AND city_name <> 'City of Dasmariñas';
