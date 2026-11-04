-- Migration: Rider Store Isolation & Tenant Shop Binding
-- Description: Ensures riders only view and accept delivery orders from the store branch that hired them.
-- Date: 2026-11-04

-- 1. Add store_id to employees table if missing
ALTER TABLE `employees` ADD COLUMN `store_id` INT NULL AFTER `department_id`;
ALTER TABLE `employees` ADD INDEX `idx_employees_store_id` (`store_id`);

-- 2. Add index to riders.store_id
ALTER TABLE `riders` ADD INDEX `idx_riders_store_id` (`store_id`);

-- 3. Populate employees.store_id based on department managers and partner user links
UPDATE `employees` e
JOIN `departments` d ON e.department_id = d.id
JOIN `store_locations` sl ON d.manager_id = sl.owner_user_id
SET e.store_id = sl.store_id
WHERE e.store_id IS NULL OR e.store_id = 0;

UPDATE `employees` e
JOIN `partner_user_links` pul ON e.user_id = pul.managed_user_id
JOIN `store_locations` sl ON pul.owner_user_id = sl.owner_user_id
SET e.store_id = sl.store_id
WHERE e.store_id IS NULL OR e.store_id = 0;

UPDATE `employees` SET `store_id` = 1 WHERE `store_id` IS NULL OR `store_id` = 0;

-- 4. Update riders: link to store_id and enforce shop_rider
UPDATE `riders` r
JOIN `partner_user_links` pul ON r.user_id = pul.managed_user_id
JOIN `store_locations` sl ON pul.owner_user_id = sl.owner_user_id
SET r.store_id = sl.store_id, r.rider_type = 'shop_rider'
WHERE r.store_id IS NULL OR r.store_id = 0;

UPDATE `riders` r
JOIN `employees` e ON (r.employee_id = e.id OR r.user_id = e.user_id)
SET r.store_id = e.store_id, r.rider_type = 'shop_rider'
WHERE (r.store_id IS NULL OR r.store_id = 0) AND e.store_id > 0;

-- Known riders mapping
UPDATE `riders` SET `store_id` = 6, `rider_type` = 'shop_rider' WHERE `id` IN (3, 4);
UPDATE `employees` SET `store_id` = 6 WHERE `id` IN (20, 21);

UPDATE `riders` SET `store_id` = 7, `rider_type` = 'shop_rider' WHERE `id` = 15;
UPDATE `employees` SET `store_id` = 7 WHERE `id` = 27;

UPDATE `riders` SET `store_id` = 1, `rider_type` = 'shop_rider' WHERE `id` IN (1, 2, 5);
UPDATE `employees` SET `store_id` = 1 WHERE `id` IN (11, 18, 19);

INSERT IGNORE INTO `partner_user_links` (`owner_user_id`, `managed_user_id`, `created_at`) VALUES (42, 18, NOW()), (42, 29, NOW()), (42, 30, NOW());

UPDATE `riders` SET `store_id` = 1, `rider_type` = 'shop_rider' WHERE `store_id` IS NULL OR `store_id` = 0;
