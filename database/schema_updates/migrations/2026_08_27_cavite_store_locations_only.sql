-- Migration: Enforce Cavite store locations only
-- Purpose: Restrict pickup & delivery store locations strictly to Cavite scope

UPDATE store_locations
SET store_name = 'Dasmariñas Branch',
    address = 'Governor Drive, Sampaloc 1',
    city = 'Dasmariñas',
    province = 'Cavite',
    phone = '(046) 416-1234',
    email = 'dasmarinas@lechondelights.com',
    opening_hours = '8:00 AM - 10:00 PM',
    opening_time = '08:00:00',
    closing_time = '22:00:00',
    latitude = 14.32940000,
    longitude = 120.93670000,
    is_active = 1
WHERE store_id = 1;

UPDATE store_locations
SET store_name = 'Imus Branch',
    address = 'Nueno Avenue, Poblacion',
    city = 'Imus',
    province = 'Cavite',
    phone = '(046) 471-5678',
    email = 'imus@lechondelights.com',
    opening_hours = '8:00 AM - 10:00 PM',
    opening_time = '08:00:00',
    closing_time = '22:00:00',
    latitude = 14.42970000,
    longitude = 120.93670000,
    is_active = 1
WHERE store_id = 2;

UPDATE store_locations
SET store_name = 'General Trias Branch',
    address = 'Arnaldo Highway, San Francisco',
    city = 'General Trias',
    province = 'Cavite',
    phone = '(046) 509-9012',
    email = 'gentrias@lechondelights.com',
    opening_hours = '8:00 AM - 10:00 PM',
    opening_time = '08:00:00',
    closing_time = '22:00:00',
    latitude = 14.38690000,
    longitude = 120.88090000,
    is_active = 1
WHERE store_id = 3;

UPDATE store_locations
SET store_name = 'Bacoor Branch',
    address = 'Aguinaldo Highway, Talaba',
    city = 'Bacoor',
    province = 'Cavite',
    phone = '(046) 417-3456',
    email = 'bacoor@lechondelights.com',
    opening_hours = '8:00 AM - 10:00 PM',
    opening_time = '08:00:00',
    closing_time = '22:00:00',
    latitude = 14.46040000,
    longitude = 120.96340000,
    is_active = 1
WHERE store_id = 4;

UPDATE store_locations
SET store_name = 'Tagaytay Branch',
    address = 'Emilio Aguinaldo Hwy, Silang Junction South',
    city = 'Tagaytay',
    province = 'Cavite',
    phone = '(046) 483-7890',
    email = 'tagaytay@lechondelights.com',
    opening_hours = '8:00 AM - 10:00 PM',
    opening_time = '08:00:00',
    closing_time = '22:00:00',
    latitude = 14.11530000,
    longitude = 120.96210000,
    is_active = 1
WHERE store_id = 5;

UPDATE store_locations
SET store_name = 'Silang Branch',
    address = 'J.P. Rizal St, Poblacion',
    city = 'Silang',
    province = 'Cavite',
    phone = '(046) 414-9988',
    email = 'silang@lechondelights.com',
    opening_hours = '8:00 AM - 8:00 PM',
    opening_time = '08:00:00',
    closing_time = '20:00:00',
    latitude = 14.23070000,
    longitude = 120.97490000,
    is_active = 1
WHERE store_id = 6;

-- Deactivate any remaining store locations not in Cavite
UPDATE store_locations
SET is_active = 0
WHERE LOWER(province) NOT LIKE '%cavite%'
  AND LOWER(city) NOT LIKE '%cavite%'
  AND store_id NOT IN (1, 2, 3, 4, 5, 6);
