-- Migration: Fix mojibake characters in store_locations, users, and franchise_applications
-- Date: 2026-10-21

UPDATE store_locations
SET store_name = 'Dasmariñas Branch'
WHERE (store_id = 1 OR store_name LIKE 'Dasmari%as%' OR store_name LIKE '%├%' OR store_name LIKE '%Ã%')
  AND store_name <> 'Dasmariñas Branch';

UPDATE store_locations
SET city = 'Dasmariñas'
WHERE (city LIKE 'Dasmari%as%' OR city LIKE '%Dasmari%')
  AND city <> 'Dasmariñas';

UPDATE store_locations
SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE users
SET full_name = 'Dasmariñas Branch Manager',
    business_name = 'Dasmariñas Central Branch'
WHERE email = 'dasma@lechondelights.com';

UPDATE users
SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE users
SET full_name = REPLACE(REPLACE(REPLACE(REPLACE(full_name, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ')
WHERE full_name LIKE '%├%' OR full_name LIKE '%Ã%';

UPDATE franchise_applications
SET address = REPLACE(REPLACE(REPLACE(REPLACE(address, '├░', 'ñ'), '├▒', 'ñ'), 'Ã±', 'ñ'), 'Γö£ΓûÆ', 'ñ')
WHERE address LIKE '%├%' OR address LIKE '%Ã%';

UPDATE franchise_applications
SET city_name = 'City of Dasmariñas'
WHERE city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%';
