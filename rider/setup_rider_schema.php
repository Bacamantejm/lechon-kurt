<?php
/**
 * Rider Portal Schema Migration & Seeder
 */
require_once __DIR__ . '/../includes/config.php';

echo "Running Rider System Database Migrations...\n";

$sql_file = __DIR__ . '/../database/schema_updates/create_rider_system_tables.sql';
if (!file_exists($sql_file)) {
    die("SQL file not found: $sql_file\n");
}

$sql_content = file_get_contents($sql_file);
$statements = array_filter(array_map('trim', explode(';', $sql_content)));

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    if (mysqli_query($conn, $stmt)) {
        echo "SUCCESS: Statement executed.\n";
    } else {
        echo "ERROR executing statement: " . mysqli_error($conn) . "\n";
    }
}

// Ensure orders has a delivery_pin column for customer verification
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM `orders` LIKE 'delivery_pin'");
if (mysqli_num_rows($col_check) === 0) {
    $alt = mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `delivery_pin` VARCHAR(10) NULL AFTER `payment_status`");
    echo $alt ? "Added delivery_pin column to orders table.\n" : "Failed to add delivery_pin: " . mysqli_error($conn) . "\n";
}

// Seed existing drivers from employees into riders table
$emp_drivers = mysqli_query($conn, "
    SELECT e.id AS employee_id, e.user_id, e.employee_id AS emp_code, e.first_name, e.last_name, e.phone, e.vehicle_details, u.id AS user_pk
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.position LIKE '%driver%' OR e.position LIKE '%rider%' OR e.vehicle_details IS NOT NULL
");

if ($emp_drivers && mysqli_num_rows($emp_drivers) > 0) {
    while ($emp = mysqli_fetch_assoc($emp_drivers)) {
        $u_id = (int)$emp['user_id'];
        $emp_id = (int)$emp['employee_id'];
        $r_code = 'RDR-' . str_pad($emp_id, 4, '0', STR_PAD_LEFT);
        $v_type = !empty($emp['vehicle_details']) ? $emp['vehicle_details'] : 'Motorcycle';

        // Check if already in riders
        $chk = mysqli_query($conn, "SELECT id FROM riders WHERE user_id = $u_id LIMIT 1");
        if (mysqli_num_rows($chk) === 0) {
            $ins = mysqli_query($conn, "
                INSERT INTO riders (user_id, employee_id, rider_code, rider_type, vehicle_type, verification_status, duty_status, rating, total_completed_deliveries)
                VALUES ($u_id, $emp_id, '$r_code', 'platform_rider', '$v_type', 'verified', 'online', 4.90, 15)
            ");
            if ($ins) {
                echo "Seeded rider: {$emp['first_name']} {$emp['last_name']} ($r_code)\n";
            } else {
                echo "Failed to seed rider for user $u_id: " . mysqli_error($conn) . "\n";
            }
        } else {
            echo "Rider already exists for user $u_id.\n";
        }
    }
}

// Also check Justine Santos (user_id 18)
$u18 = mysqli_query($conn, "SELECT id, full_name, email, phone FROM users WHERE id = 18 LIMIT 1");
if ($u18 && mysqli_num_rows($u18) > 0) {
    $row18 = mysqli_fetch_assoc($u18);
    $chk18 = mysqli_query($conn, "SELECT id FROM riders WHERE user_id = 18 LIMIT 1");
    if (mysqli_num_rows($chk18) === 0) {
        mysqli_query($conn, "
            INSERT INTO riders (user_id, employee_id, rider_code, rider_type, vehicle_type, verification_status, duty_status, rating, total_completed_deliveries)
            VALUES (18, 11, 'RDR-0011', 'platform_rider', 'Honda Click 125i', 'verified', 'online', 4.95, 28)
        ");
        echo "Seeded rider for Justine Santos (user_id 18, RDR-0011)\n";
    }
}

echo "Rider system migration completed successfully.\n";
