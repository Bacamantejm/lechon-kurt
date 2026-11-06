-- Migration: Update job positions into authentic lechon shop roles
-- Date: 2026-11-06

-- 1. Ensure required departments exist
INSERT IGNORE INTO `departments` (`department_name`, `description`) VALUES
('Kitchen & Roasting', 'Lechon roasting pit, charcoal fire management, and kitchen cooking operations'),
('Store Operations & Service', 'Front-of-house customer service, dining counter, POS cashiering, and pack-out'),
('Logistics & Delivery', 'Order fulfillment, packing, vehicle dispatch, and delivery riders'),
('Administration & Finance', 'Store bookkeeping, inventory management, and business administration');

-- 2. Insert or update the 4 primary lechon job positions
INSERT INTO `job_positions` (`position_title`, `department_id`, `description`, `requirements`, `salary_range_min`, `salary_range_max`, `employment_type`, `status`, `posted_date`)
SELECT 
    'Lechon Roaster / Litsonero',
    d.id,
    'Master pitmaster responsible for whole pig preparation, seasoning, skewer balancing, charcoal fire management, and ensuring signature crispy golden skin.',
    'Knowledge in traditional charcoal roasting, butchery, food sanitation standards, physical endurance in pit temperature.',
    15000.00,
    28000.00,
    'full_time',
    'open',
    CURDATE()
FROM `departments` d
WHERE d.department_name = 'Kitchen & Roasting'
LIMIT 1
ON DUPLICATE KEY UPDATE `status` = 'open';

INSERT INTO `job_positions` (`position_title`, `department_id`, `description`, `requirements`, `salary_range_min`, `salary_range_max`, `employment_type`, `status`, `posted_date`)
SELECT 
    'Service Crew',
    d.id,
    'Front-of-house store staff responsible for welcoming customers, taking counter orders, handling cashier POS transactions, order packaging, and store hygiene.',
    'Customer service passion, pleasant demeanor, punctuality, basic arithmetic and POS operating experience.',
    12000.00,
    18000.00,
    'full_time',
    'open',
    CURDATE()
FROM `departments` d
WHERE d.department_name = 'Store Operations & Service'
LIMIT 1
ON DUPLICATE KEY UPDATE `status` = 'open';

INSERT INTO `job_positions` (`position_title`, `department_id`, `description`, `requirements`, `salary_range_min`, `salary_range_max`, `employment_type`, `status`, `posted_date`)
SELECT 
    'Kitchen Helper',
    d.id,
    'Assists the roasting pitmaster and kitchen cook in food prep, chopping, vegetable trimming, skewer cleaning, charcoal handling, and kitchen sanitation.',
    'Basic knife skills, willingness to learn roasting and kitchen prep, strong hygiene habits, team player.',
    12000.00,
    17000.00,
    'full_time',
    'open',
    CURDATE()
FROM `departments` d
WHERE d.department_name = 'Kitchen & Roasting'
LIMIT 1
ON DUPLICATE KEY UPDATE `status` = 'open';

INSERT INTO `job_positions` (`position_title`, `department_id`, `description`, `requirements`, `salary_range_min`, `salary_range_max`, `employment_type`, `status`, `posted_date`)
SELECT 
    'Delivery Rider',
    d.id,
    'Dedicated delivery specialist ensuring hot, freshly-roasted whole lechons and catering food trays reach customers safely and punctually across Cavite.',
    'Valid driver license with motorcycle/vehicle restriction code, clean driving record, familiar with Cavite routes, GPS smartphone.',
    14000.00,
    22000.00,
    'full_time',
    'open',
    CURDATE()
FROM `departments` d
WHERE d.department_name = 'Logistics & Delivery'
LIMIT 1
ON DUPLICATE KEY UPDATE `status` = 'open';

-- 3. Remap existing employees referencing obsolete generic mock titles
UPDATE `employees`
SET `position` = 'Service Crew'
WHERE LOWER(TRIM(`position`)) IN ('software developer', 'project manager', 'staff', 'hourly staff', 'receptionist', 'cashier', 'asd', 'employee', 'receipt');

UPDATE `employees`
SET `position` = 'Delivery Rider'
WHERE LOWER(TRIM(`position`)) IN ('driver', 'delivery driver', 'rider');

UPDATE `employees`
SET `position` = 'Lechon Roaster / Litsonero'
WHERE LOWER(TRIM(`position`)) IN ('chef / lechonero', 'lechonero', 'roaster');

UPDATE `employees`
SET `position` = 'Kitchen Helper'
WHERE LOWER(TRIM(`position`)) IN ('assistant cook', 'helper');

-- 4. Delete obsolete generic mock positions
DELETE FROM `job_positions`
WHERE LOWER(TRIM(`position_title`)) IN ('software developer', 'project manager', 'staff', 'hourly staff', 'asd', 'employee', 'receipt', 'driver');
