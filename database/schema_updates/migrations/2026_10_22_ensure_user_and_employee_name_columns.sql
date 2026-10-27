-- Migration: Ensure middle_name and suffix on users table, and middle_initial, suffix, and photo on employees table

-- Add middle_name and suffix to users if missing
SET @has_users_middle := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'middle_name');
SET @sql_users_middle := IF(@has_users_middle = 0, 'ALTER TABLE `users` ADD COLUMN `middle_name` VARCHAR(80) NULL DEFAULT NULL AFTER `full_name`', 'SELECT 1');
PREPARE stmt1 FROM @sql_users_middle;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @has_users_suffix := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'suffix');
SET @sql_users_suffix := IF(@has_users_suffix = 0, 'ALTER TABLE `users` ADD COLUMN `suffix` VARCHAR(20) NULL DEFAULT NULL AFTER `middle_name`', 'SELECT 1');
PREPARE stmt2 FROM @sql_users_suffix;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Add middle_initial, suffix, and photo to employees if missing
SET @has_emp_middle := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'middle_initial');
SET @sql_emp_middle := IF(@has_emp_middle = 0, 'ALTER TABLE `employees` ADD COLUMN `middle_initial` VARCHAR(10) NULL DEFAULT NULL AFTER `first_name`', 'SELECT 1');
PREPARE stmt3 FROM @sql_emp_middle;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @has_emp_suffix := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'suffix');
SET @sql_emp_suffix := IF(@has_emp_suffix = 0, 'ALTER TABLE `employees` ADD COLUMN `suffix` VARCHAR(20) NULL DEFAULT NULL AFTER `last_name`', 'SELECT 1');
PREPARE stmt4 FROM @sql_emp_suffix;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

SET @has_emp_photo := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'photo');
SET @sql_emp_photo := IF(@has_emp_photo = 0, 'ALTER TABLE `employees` ADD COLUMN `photo` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt5 FROM @sql_emp_photo;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;
