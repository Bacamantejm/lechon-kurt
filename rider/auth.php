<?php
/**
 * Rider Portal Authentication Guard & Helpers
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

function checkRiderAccess(): array {
    global $conn;

    if (!isset($_SESSION['user_id'])) {
        $curr_url = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header("Location: login.php?redirect=" . urlencode($curr_url));
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];

    // Query rider profile joined with user and employee details
    $sql = "
        SELECT r.*, 
               u.full_name, u.email, u.phone AS user_phone, u.profile_image, u.user_type,
               e.first_name, e.last_name, e.phone AS emp_phone, e.vehicle_details AS emp_vehicle,
               sl.store_name, sl.address AS store_address, sl.city AS store_city
        FROM riders r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN store_locations sl ON r.store_id = sl.store_id
        WHERE r.user_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("System error: Unable to verify rider access.");
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rider = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$rider) {
        // Check if user is an employee with driver position, and auto-register into riders table
        $emp_chk = mysqli_prepare($conn, "SELECT id, first_name, last_name, vehicle_details FROM employees WHERE user_id = ? AND (position LIKE '%driver%' OR position LIKE '%rider%' OR vehicle_details IS NOT NULL) LIMIT 1");
        if ($emp_chk) {
            mysqli_stmt_bind_param($emp_chk, "i", $user_id);
            mysqli_stmt_execute($emp_chk);
            $emp_res = mysqli_stmt_get_result($emp_chk);
            if ($emp_row = mysqli_fetch_assoc($emp_res)) {
                $e_id = (int)$emp_row['id'];
                $r_code = 'RDR-' . str_pad($e_id, 4, '0', STR_PAD_LEFT);
                $v_type = !empty($emp_row['vehicle_details']) ? $emp_row['vehicle_details'] : 'Motorcycle';
                mysqli_query($conn, "INSERT INTO riders (user_id, employee_id, rider_code, rider_type, vehicle_type, verification_status, duty_status, rating) VALUES ($user_id, $e_id, '$r_code', 'platform_rider', '$v_type', 'verified', 'online', 5.00)");
                
                // Retry fetch
                return checkRiderAccess();
            }
            mysqli_stmt_close($emp_chk);
        }

        // Not a registered rider
        session_destroy();
        header("Location: login.php?error=" . urlencode("Access denied. No active delivery rider profile found for this account."));
        exit;
    }

    // Check verification status
    if ($rider['verification_status'] === 'pending') {
        header("Location: login.php?msg=" . urlencode("Your rider account is pending verification by the platform administrator."));
        exit;
    } elseif ($rider['verification_status'] === 'rejected') {
        header("Location: login.php?error=" . urlencode("Your rider application has been rejected or suspended. Please contact support."));
        exit;
    }

    // Set convenience session indicators
    $_SESSION['is_driver'] = true;
    $_SESSION['rider_id'] = (int)$rider['id'];
    $_SESSION['rider_code'] = $rider['rider_code'];
    $_SESSION['rider_name'] = !empty($rider['full_name']) ? $rider['full_name'] : trim($rider['first_name'] . ' ' . $rider['last_name']);

    return $rider;
}

function getRiderActiveDelivery(int $rider_id, ?int $employee_id = null): ?array {
    global $conn;

    $driver_clause = "lt.driver_id = ?";
    $params = [$rider_id];
    $types = "i";

    if ($employee_id && $employee_id > 0) {
        $driver_clause = "(lt.driver_id = ? OR lt.driver_id = ?)";
        $params = [$rider_id, $employee_id];
        $types = "ii";
    }

    $sql = "
        SELECT lt.*,
               o.order_number, o.customer_name, o.customer_phone, o.customer_email,
               o.delivery_address, o.latitude AS customer_latitude, o.longitude AS customer_longitude,
               o.delivery_instructions, o.special_instructions, o.total_amount, o.delivery_fee,
               o.payment_method, o.payment_status, o.delivery_pin, o.pickup_location,
               sl.store_name, sl.address AS store_address, sl.city AS store_city,
               sl.latitude AS store_latitude, sl.longitude AS store_longitude
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id
        LEFT JOIN store_locations sl ON o.pickup_location = sl.store_id
        WHERE $driver_clause
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
        ORDER BY lt.updated_at DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $active = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    return $active ?: null;
}

function formatRiderPeso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
