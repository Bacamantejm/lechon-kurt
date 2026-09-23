<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/config.php';
require_once 'auth.php';
checkAdminAccess();
require_once '../includes/security.php';

// Clear any accidental output prior to JSON emission
if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json; charset=UTF-8');

function sendJsonAndExit($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonAndExit(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$csrf_token = $_POST['csrf_token'] ?? '';
$session_csrf = $_SESSION['csrf_token'] ?? '';
$csrf_valid = validateCSRFToken($csrf_token);

if (!$csrf_valid && !empty($session_csrf) && !empty($csrf_token) && hash_equals($session_csrf, $csrf_token)) {
    $_SESSION['csrf_token'] = $session_csrf;
    $_SESSION['csrf_token_time'] = time();
    $csrf_valid = true;
}

// If session is fully authenticated backoffice user / driver
if (!$csrf_valid && !empty($_SESSION['user_id']) && !empty($_SESSION['has_backoffice_access'])) {
    $csrf_valid = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

if (!$csrf_valid) {
    sendJsonAndExit(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], 403);
}

$tracking_id = intval($_POST['tracking_id'] ?? 0);
$order_id = intval($_POST['order_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);

if ($tracking_id <= 0 && $order_id <= 0) {
    sendJsonAndExit(['success' => false, 'message' => 'Invalid order or delivery parameters.'], 400);
}

try {
    // 1. Resolve Driver Info from Logged-in User
    $employee = null;
    $emp_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, phone, vehicle_details FROM employees WHERE user_id = ? AND status = 'active' LIMIT 1");
    if ($emp_stmt) {
        mysqli_stmt_bind_param($emp_stmt, "i", $current_user_id);
        mysqli_stmt_execute($emp_stmt);
        $emp_res = mysqli_stmt_get_result($emp_stmt);
        $employee = mysqli_fetch_assoc($emp_res);
        mysqli_stmt_close($emp_stmt);
    }

    if (!$employee && isset($_POST['employee_id']) && intval($_POST['employee_id']) > 0) {
        $target_emp_id = intval($_POST['employee_id']);
        $t_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, phone, vehicle_details FROM employees WHERE id = ? LIMIT 1");
        if ($t_stmt) {
            mysqli_stmt_bind_param($t_stmt, "i", $target_emp_id);
            mysqli_stmt_execute($t_stmt);
            $t_res = mysqli_stmt_get_result($t_stmt);
            $employee = mysqli_fetch_assoc($t_res);
            mysqli_stmt_close($t_stmt);
        }
    }

    if (!$employee) {
        $find_d = mysqli_query($conn, "SELECT id, first_name, last_name, phone, vehicle_details FROM employees WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if ($find_d && mysqli_num_rows($find_d) > 0) {
            $employee = mysqli_fetch_assoc($find_d);
        }
    }

    if (!$employee) {
        $u_stmt = mysqli_prepare($conn, "SELECT id, full_name, phone FROM users WHERE id = ? LIMIT 1");
        if ($u_stmt) {
            mysqli_stmt_bind_param($u_stmt, "i", $current_user_id);
            mysqli_stmt_execute($u_stmt);
            $u_res = mysqli_stmt_get_result($u_stmt);
            $u_row = mysqli_fetch_assoc($u_res);
            mysqli_stmt_close($u_stmt);
        }

        $driver_id = 0;
        $driver_name = !empty($u_row['full_name']) ? trim($u_row['full_name']) : 'Rider';
        $driver_phone = !empty($u_row['phone']) ? trim($u_row['phone']) : '';
        $driver_vehicle = 'Motorcycle';
    } else {
        $driver_id = (int)$employee['id'];
        $driver_name = trim($employee['first_name'] . ' ' . $employee['last_name']);
        $driver_phone = trim((string)($employee['phone'] ?? ''));
        $driver_vehicle = trim((string)($employee['vehicle_details'] ?? 'Motorcycle'));
    }

    // Check if driver has existing coordinates in employees_geo_tracking
    $seed_lat = null;
    $seed_lng = null;
    if ($driver_id > 0) {
        $geo_stmt = mysqli_prepare($conn, "SELECT current_latitude, current_longitude FROM employees_geo_tracking WHERE employee_id = ? LIMIT 1");
        if ($geo_stmt) {
            mysqli_stmt_bind_param($geo_stmt, "i", $driver_id);
            mysqli_stmt_execute($geo_stmt);
            $geo_res = mysqli_stmt_get_result($geo_stmt);
            if ($geo_row = mysqli_fetch_assoc($geo_res)) {
                $seed_lat = $geo_row['current_latitude'];
                $seed_lng = $geo_row['current_longitude'];
            }
            mysqli_stmt_close($geo_stmt);
        }
    }

    // 2. Locate or create logistics tracking record
    if ($tracking_id <= 0 && $order_id > 0) {
        $find_tr = mysqli_prepare($conn, "SELECT id, driver_id, current_status FROM logistics_tracking WHERE order_id = ? LIMIT 1");
        if ($find_tr) {
            mysqli_stmt_bind_param($find_tr, "i", $order_id);
            mysqli_stmt_execute($find_tr);
            $res_tr = mysqli_stmt_get_result($find_tr);
            if ($row_tr = mysqli_fetch_assoc($res_tr)) {
                $tracking_id = (int)$row_tr['id'];
            }
            mysqli_stmt_close($find_tr);
        }
    }

    if ($tracking_id <= 0 && $order_id > 0) {
        // Create new tracking record for this order
        $new_tr = mysqli_prepare($conn, "
            INSERT INTO logistics_tracking (
                order_id, driver_id, driver_name, driver_phone, driver_vehicle,
                current_status, status_timestamp, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'assigned', NOW(), NOW(), NOW())
        ");
        if ($new_tr) {
            mysqli_stmt_bind_param($new_tr, "iisss", $order_id, $driver_id, $driver_name, $driver_phone, $driver_vehicle);
            mysqli_stmt_execute($new_tr);
            $tracking_id = mysqli_stmt_insert_id($new_tr);
            mysqli_stmt_close($new_tr);
        }
    }

    // 3. Fetch delivery & customer info
    $chk_stmt = mysqli_prepare($conn, "
        SELECT lt.id, lt.order_id, lt.current_status, lt.driver_id, lt.driver_name,
               o.order_number, o.customer_name, o.customer_phone, o.delivery_address, o.latitude, o.longitude,
               o.delivery_date, o.delivery_time, o.pickup_location, o.special_instructions
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id
        WHERE lt.id = ?
        LIMIT 1
    ");
    if (!$chk_stmt) {
        sendJsonAndExit(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)], 500);
    }
    mysqli_stmt_bind_param($chk_stmt, "i", $tracking_id);
    mysqli_stmt_execute($chk_stmt);
    $chk_res = mysqli_stmt_get_result($chk_stmt);
    $delivery = mysqli_fetch_assoc($chk_res);
    mysqli_stmt_close($chk_stmt);

    if (!$delivery) {
        sendJsonAndExit(['success' => false, 'message' => 'Delivery tracking record not found for Order #' . $order_id], 404);
    }

    if (in_array($delivery['current_status'], ['delivered', 'cancelled', 'failed'], true)) {
        sendJsonAndExit(['success' => false, 'message' => 'This delivery is already ' . $delivery['current_status'] . '.'], 400);
    }

    // Check if order was already taken by someone else
    if (!empty($delivery['driver_id']) && $delivery['driver_id'] != $driver_id && in_array($delivery['current_status'], ['assigned', 'picked_up', 'on_the_way', 'arriving'], true)) {
        sendJsonAndExit([
            'success' => false,
            'message' => 'This order has already been accepted by another driver (' . ($delivery['driver_name'] ?? 'Driver') . ').'
        ], 400);
    }

    // 4. Update logistics_tracking
    if ($seed_lat !== null && $seed_lng !== null) {
        $upd_stmt = mysqli_prepare($conn, "
            UPDATE logistics_tracking
            SET driver_id = ?,
                driver_name = ?,
                driver_phone = ?,
                driver_vehicle = ?,
                current_latitude = ?,
                current_longitude = ?,
                last_location_update = NOW(),
                current_status = 'assigned',
                status_timestamp = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_stmt, "isssddi", $driver_id, $driver_name, $driver_phone, $driver_vehicle, $seed_lat, $seed_lng, $tracking_id);
    } else {
        $upd_stmt = mysqli_prepare($conn, "
            UPDATE logistics_tracking
            SET driver_id = ?,
                driver_name = ?,
                driver_phone = ?,
                driver_vehicle = ?,
                current_status = 'assigned',
                status_timestamp = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_stmt, "isssi", $driver_id, $driver_name, $driver_phone, $driver_vehicle, $tracking_id);
    }

    $success = mysqli_stmt_execute($upd_stmt);
    mysqli_stmt_close($upd_stmt);

    if (!$success) {
        sendJsonAndExit(['success' => false, 'message' => 'Failed to assign delivery: ' . mysqli_error($conn)], 500);
    }

    // 5. Ensure order status is in preparing or confirmed (DO NOT prematurely set to out_for_delivery)
    if (!empty($delivery['order_id'])) {
        $ord_stmt = mysqli_prepare($conn, "UPDATE orders SET status = 'preparing' WHERE id = ? AND status IN ('pending', 'confirmed')");
        if ($ord_stmt) {
            mysqli_stmt_bind_param($ord_stmt, "i", $delivery['order_id']);
            mysqli_stmt_execute($ord_stmt);
            mysqli_stmt_close($ord_stmt);
        }
    }

    // 6. Resolve Store Location for Pickup
    $store_info = null;
    if (!empty($delivery['pickup_location'])) {
        $st_stmt = mysqli_prepare($conn, "SELECT store_id, store_name, address, city, latitude, longitude FROM store_locations WHERE store_id = ? LIMIT 1");
        if ($st_stmt) {
            $pk_id = (int)$delivery['pickup_location'];
            mysqli_stmt_bind_param($st_stmt, "i", $pk_id);
            mysqli_stmt_execute($st_stmt);
            $st_res = mysqli_stmt_get_result($st_stmt);
            $store_info = mysqli_fetch_assoc($st_res);
            mysqli_stmt_close($st_stmt);
        }
    }

    sendJsonAndExit([
        'success' => true,
        'message' => 'Delivery accepted! Proceed to pickup location.',
        'tracking_id' => $tracking_id,
        'order_id' => (int)$delivery['order_id'],
        'driver_id' => $driver_id,
        'driver_name' => $driver_name,
        'driver_latitude' => $seed_lat !== null ? (float)$seed_lat : null,
        'driver_longitude' => $seed_lng !== null ? (float)$seed_lng : null,
        'customer_location' => [
            'order_number' => $delivery['order_number'],
            'customer_name' => $delivery['customer_name'],
            'customer_phone' => $delivery['customer_phone'],
            'delivery_address' => $delivery['delivery_address'],
            'latitude' => $delivery['latitude'] !== null ? (float)$delivery['latitude'] : null,
            'longitude' => $delivery['longitude'] !== null ? (float)$delivery['longitude'] : null
        ],
        'store_location' => $store_info ? [
            'store_name' => $store_info['store_name'],
            'address' => $store_info['address'] . ', ' . $store_info['city'],
            'latitude' => $store_info['latitude'] !== null ? (float)$store_info['latitude'] : null,
            'longitude' => $store_info['longitude'] !== null ? (float)$store_info['longitude'] : null
        ] : null
    ]);

} catch (Throwable $e) {
    sendJsonAndExit([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}
