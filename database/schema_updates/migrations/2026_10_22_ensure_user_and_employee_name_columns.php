<?php
/**
 * Migration Runner: Ensure middle_name and suffix on users table,
 * and middle_initial, suffix, and photo on employees table.
 */
require_once dirname(__DIR__, 3) . '/includes/config.php';
require_once dirname(__DIR__, 3) . '/admin/hr_module_common.php';

echo "Running Migration: 2026_10_22_ensure_user_and_employee_name_columns...\n";

if (!$conn) {
    die("Error: No database connection available.\n");
}

$u_mid = hrColumnExists($conn, 'users', 'middle_name', true);
if (!$u_mid) {
    $res = mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `middle_name` VARCHAR(80) NULL DEFAULT NULL AFTER `full_name`");
    echo $res ? "[OK] Added middle_name to users\n" : "[SKIP/ERR] users.middle_name: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] users.middle_name\n";
}

$u_suf = hrColumnExists($conn, 'users', 'suffix', true);
if (!$u_suf) {
    $res = mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `suffix` VARCHAR(20) NULL DEFAULT NULL AFTER `middle_name`");
    echo $res ? "[OK] Added suffix to users\n" : "[SKIP/ERR] users.suffix: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] users.suffix\n";
}

$e_mid = hrColumnExists($conn, 'employees', 'middle_initial', true);
if (!$e_mid) {
    $res = mysqli_query($conn, "ALTER TABLE `employees` ADD COLUMN `middle_initial` VARCHAR(10) NULL DEFAULT NULL AFTER `first_name`");
    echo $res ? "[OK] Added middle_initial to employees\n" : "[SKIP/ERR] employees.middle_initial: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] employees.middle_initial\n";
}

$e_suf = hrColumnExists($conn, 'employees', 'suffix', true);
if (!$e_suf) {
    $res = mysqli_query($conn, "ALTER TABLE `employees` ADD COLUMN `suffix` VARCHAR(20) NULL DEFAULT NULL AFTER `last_name`");
    echo $res ? "[OK] Added suffix to employees\n" : "[SKIP/ERR] employees.suffix: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] employees.suffix\n";
}

$e_pho = hrColumnExists($conn, 'employees', 'photo', true);
if (!$e_pho) {
    $res = mysqli_query($conn, "ALTER TABLE `employees` ADD COLUMN `photo` VARCHAR(255) NULL DEFAULT NULL");
    echo $res ? "[OK] Added photo to employees\n" : "[SKIP/ERR] employees.photo: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] employees.photo\n";
}

echo "Migration completed.\n";
