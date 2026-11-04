<?php
/**
 * Migration: Rider Store Isolation & Tenant Shop Binding
 * Description: Ensures riders only view and accept delivery orders from the store branch that hired them.
 * Date: 2026-11-04
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting Rider Store Isolation Migration...\n";

// 1. Add store_id to employees table if missing
$chk_emp_store = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE 'store_id'");
if ($chk_emp_store && mysqli_num_rows($chk_emp_store) === 0) {
    echo "Adding store_id to employees table...\n";
    @mysqli_query($conn, "ALTER TABLE employees ADD COLUMN store_id INT NULL AFTER department_id");
    @mysqli_query($conn, "ALTER TABLE employees ADD INDEX idx_employees_store_id (store_id)");
}

// 2. Add index on riders.store_id if missing
$chk_r_idx = mysqli_query($conn, "SHOW INDEX FROM riders WHERE Key_name = 'idx_riders_store_id'");
if ($chk_r_idx && mysqli_num_rows($chk_r_idx) === 0) {
    @mysqli_query($conn, "ALTER TABLE riders ADD INDEX idx_riders_store_id (store_id)");
}

// 3. Populate employees.store_id based on department managers and partner user links
@mysqli_query($conn, "
    UPDATE employees e
    JOIN departments d ON e.department_id = d.id
    JOIN store_locations sl ON d.manager_id = sl.owner_user_id
    SET e.store_id = sl.store_id
    WHERE e.store_id IS NULL OR e.store_id = 0
");

@mysqli_query($conn, "
    UPDATE employees e
    JOIN partner_user_links pul ON e.user_id = pul.managed_user_id
    JOIN store_locations sl ON pul.owner_user_id = sl.owner_user_id
    SET e.store_id = sl.store_id
    WHERE e.store_id IS NULL OR e.store_id = 0
");

// 4. Fallback employees to Store 1 (Dasmariñas Branch) if still unassigned
@mysqli_query($conn, "UPDATE employees SET store_id = 1 WHERE store_id IS NULL OR store_id = 0");

// 5. Update riders: link to store_id and enforce shop_rider
// First from partner_user_links
@mysqli_query($conn, "
    UPDATE riders r
    JOIN partner_user_links pul ON r.user_id = pul.managed_user_id
    JOIN store_locations sl ON pul.owner_user_id = sl.owner_user_id
    SET r.store_id = sl.store_id, r.rider_type = 'shop_rider'
    WHERE r.store_id IS NULL OR r.store_id = 0
");

// Second from linked employee store_id
@mysqli_query($conn, "
    UPDATE riders r
    JOIN employees e ON (r.employee_id = e.id OR r.user_id = e.user_id)
    SET r.store_id = e.store_id, r.rider_type = 'shop_rider'
    WHERE (r.store_id IS NULL OR r.store_id = 0) AND e.store_id > 0
");

// Explicit mapping for verified known riders
// Dasmariñas Branch (Store 6, owner 31): riders 3 and 4
@mysqli_query($conn, "UPDATE riders SET store_id = 6, rider_type = 'shop_rider' WHERE id IN (3, 4)");
@mysqli_query($conn, "UPDATE employees SET store_id = 6 WHERE id IN (20, 21)");

// jm lechon (Store 7, owner 38): rider 15 (employee 27)
@mysqli_query($conn, "UPDATE riders SET store_id = 7, rider_type = 'shop_rider' WHERE id = 15");
@mysqli_query($conn, "UPDATE employees SET store_id = 7 WHERE id = 27");

// Dasmariñas Main Branch (Store 1, owner 42): riders 1, 2, 5
@mysqli_query($conn, "UPDATE riders SET store_id = 1, rider_type = 'shop_rider' WHERE id IN (1, 2, 5)");
@mysqli_query($conn, "UPDATE employees SET store_id = 1 WHERE id IN (11, 18, 19)");

// Ensure partner_user_links for Store 1 riders
@mysqli_query($conn, "INSERT IGNORE INTO partner_user_links (owner_user_id, managed_user_id, created_at) VALUES (42, 18, NOW()), (42, 29, NOW()), (42, 30, NOW())");

// 6. Default any remaining riders with null store_id to Store 1
@mysqli_query($conn, "UPDATE riders SET store_id = 1, rider_type = 'shop_rider' WHERE store_id IS NULL OR store_id = 0");

echo "Migration finished successfully.\n";
