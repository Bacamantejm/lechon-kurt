<?php
/**
 * Central Rider Portal API
 * Handles status toggles, geolocation broadcasts, order request polling,
 * delivery workflow progressions, COD collection recording, PIN verification, and issues.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

function jsonRes($data, int $code = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    jsonRes(['success' => false, 'message' => 'Unauthorized session.'], 401);
}

$user_id = (int)$_SESSION['user_id'];
$rider_id = (int)($_SESSION['rider_id'] ?? 0);

// Resolve rider profile with user/employee details
$rider = null;
$rider_where = ($rider_id > 0) ? "r.id = $rider_id" : "r.user_id = $user_id";
$r_q = mysqli_query($conn, "
    SELECT r.*,
           COALESCE(u.full_name, CONCAT(e.first_name, ' ', e.last_name), 'Rider') AS full_name,
           COALESCE(u.phone, e.phone, '') AS user_phone,
           u.email AS user_email
    FROM riders r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN employees e ON r.employee_id = e.id
    WHERE {$rider_where}
    LIMIT 1
");
if ($r_q) $rider = mysqli_fetch_assoc($r_q);

if (!$rider) {
    jsonRes(['success' => false, 'message' => 'No active rider profile associated with this account.'], 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper to compute straight-line distance in km
function distanceKm($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return null;
    $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round(6371 * $c, 1);
}

// -------------------------------------------------------------
// 1. TOGGLE DUTY STATUS (OFFLINE / ONLINE / BUSY)
// -------------------------------------------------------------
if ($action === 'toggle_status') {
    $new_status = strtolower(trim($_POST['status'] ?? ''));
    if (!in_array($new_status, ['offline', 'online', 'busy'], true)) {
        jsonRes(['success' => false, 'message' => 'Invalid status option.'], 400);
    }

    // If changing to offline, ensure rider has no ongoing delivery
    if ($new_status === 'offline') {
        $active_del = getRiderActiveDelivery((int)$rider['id'], (int)($rider['employee_id'] ?? 0));
        if ($active_del) {
            jsonRes([
                'success' => false,
                'message' => 'Cannot go offline while you have an active delivery in progress (Order #' . $active_del['order_number'] . ').'
            ], 400);
        }
    }

    $stmt = mysqli_prepare($conn, "UPDATE riders SET duty_status = ?, last_location_update = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $new_status, $rider['id']);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    jsonRes([
        'success' => $ok,
        'status' => $new_status,
        'message' => "Rider status switched to " . strtoupper($new_status)
    ]);
}

// -------------------------------------------------------------
// 2. UPDATE GEOLOCATION
// -------------------------------------------------------------
if ($action === 'update_location') {
    $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $acc = isset($_POST['accuracy']) ? (float)$_POST['accuracy'] : 0.0;

    if ($lat === null || $lng === null || $lat == 0 || $lng == 0) {
        jsonRes(['success' => false, 'message' => 'Invalid coordinates.'], 400);
    }

    // Update riders table
    $u_r = mysqli_prepare($conn, "UPDATE riders SET current_latitude = ?, current_longitude = ?, last_location_update = NOW() WHERE id = ?");
    if ($u_r) {
        mysqli_stmt_bind_param($u_r, "ddi", $lat, $lng, $rider['id']);
        mysqli_stmt_execute($u_r);
        mysqli_stmt_close($u_r);
    }

    // Update employees_geo_tracking if employee_id exists
    $emp_id = (int)($rider['employee_id'] ?? 0);
    if ($emp_id > 0) {
        mysqli_query($conn, "
            INSERT INTO employees_geo_tracking (employee_id, current_latitude, current_longitude, accuracy_meters, tracking_status, last_update)
            VALUES ($emp_id, $lat, $lng, $acc, 'active', NOW())
            ON DUPLICATE KEY UPDATE current_latitude = $lat, current_longitude = $lng, accuracy_meters = $acc, tracking_status = 'active', last_update = NOW()
        ");
    }

    // Also update any active delivery tracking row
    $del = getRiderActiveDelivery((int)$rider['id'], $emp_id);
    if ($del) {
        $tr_id = (int)$del['id'];
        mysqli_query($conn, "UPDATE logistics_tracking SET current_latitude = $lat, current_longitude = $lng, last_location_update = NOW() WHERE id = $tr_id");
    }

    jsonRes(['success' => true, 'latitude' => $lat, 'longitude' => $lng]);
}

// -------------------------------------------------------------
// 3. POLL AVAILABLE DELIVERY REQUESTS (Section 3)
// -------------------------------------------------------------
if ($action === 'poll_requests') {
    // Only online riders can receive requests (not offline or busy)
    if ($rider['duty_status'] !== 'online') {
        jsonRes(['success' => true, 'has_request' => false, 'reason' => 'Rider is ' . $rider['duty_status']]);
    }

    $rider_lat = (float)($rider['current_latitude'] ?? 14.3294);
    $rider_lng = (float)($rider['current_longitude'] ?? 120.9367);

    // Clean expired snoozes
    $snoozed_orders = $_SESSION['snoozed_orders'] ?? [];
    $now_ts = time();
    if (is_array($snoozed_orders)) {
        foreach ($snoozed_orders as $s_oid => $s_exp) {
            if ($now_ts > $s_exp) {
                unset($snoozed_orders[$s_oid]);
            }
        }
        $_SESSION['snoozed_orders'] = $snoozed_orders;
    } else {
        $_SESSION['snoozed_orders'] = [];
        $snoozed_orders = [];
    }

    // Query pending/preparing delivery orders not yet assigned to a driver
    $store_filter = "";
    if ($rider['rider_type'] === 'shop_rider' && !empty($rider['store_id'])) {
        $s_id = (int)$rider['store_id'];
        $store_filter = "AND o.pickup_location = $s_id";
    }

    $sql = "
        SELECT o.id AS order_id, o.order_number, o.customer_name, o.customer_phone,
               o.delivery_address, o.latitude AS customer_latitude, o.longitude AS customer_longitude,
               o.total_amount, o.delivery_fee, o.payment_method, o.pickup_location, o.created_at,
               sl.store_name, sl.address AS store_address, sl.city AS store_city,
               sl.latitude AS store_latitude, sl.longitude AS store_longitude,
               lt.id AS tracking_id, lt.driver_id, lt.current_status
        FROM orders o
        LEFT JOIN store_locations sl ON o.pickup_location = sl.store_id
        LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
        WHERE o.status IN ('confirmed', 'preparing')
          AND o.delivery_option = 'delivery'
          AND (lt.driver_id IS NULL OR lt.driver_id = 0 OR lt.current_status = 'pending')
          $store_filter
        ORDER BY o.created_at ASC
        LIMIT 20
    ";

    $res = mysqli_query($conn, $sql);
    $matching_request = null;
    $available_orders = [];

    if ($res && mysqli_num_rows($res) > 0) {
        while ($row = mysqli_fetch_assoc($res)) {
            $ord_id = (int)$row['order_id'];

            // Fallback store coordinates (Dasmariñas branch default)
            $store_lat = (float)($row['store_latitude'] ?? 14.3294);
            $store_lng = (float)($row['store_longitude'] ?? 120.9367);
            $cust_lat = !empty($row['customer_latitude']) ? (float)$row['customer_latitude'] : null;
            $cust_lng = !empty($row['customer_longitude']) ? (float)$row['customer_longitude'] : null;

            $pickup_dist = distanceKm($rider_lat, $rider_lng, $store_lat, $store_lng) ?? 2.1;
            $delivery_dist = distanceKm($store_lat, $store_lng, $cust_lat, $cust_lng) ?? 3.5;

            // Estimated Rider Earnings: delivery fee or base ₱50 + bonus
            $base_fee = max(50.00, (float)($row['delivery_fee'] ?? 50.00));
            $est_earnings = round($base_fee + max(0, ($delivery_dist - 3.0) * 10), 2);

            $payment_type = (stripos($row['payment_method'] ?? '', 'cod') !== false || stripos($row['payment_method'] ?? '', 'cash') !== false) ? 'COD' : 'ONLINE';

            $delivery_type_label = ($rider['rider_type'] === 'shop_rider') ? 'Shop Rider' : 'Platform / Partner Rider';

            $created_time_str = !empty($row['created_at']) ? date('g:i A', strtotime($row['created_at'])) : 'Just now';

            $item_data = [
                'order_id' => $ord_id,
                'tracking_id' => (int)($row['tracking_id'] ?? 0),
                'order_number' => $row['order_number'],
                'restaurant_name' => $row['store_name'] ?? 'Lechon Central Branch',
                'restaurant_address' => trim(($row['store_address'] ?? 'Emilio Aguinaldo Highway') . ', ' . ($row['store_city'] ?? 'Dasmariñas')),
                'customer_name' => $row['customer_name'] ?? 'Customer',
                'customer_address' => $row['delivery_address'] ?? 'Customer Delivery Address',
                'distance_to_restaurant_km' => $pickup_dist,
                'distance_restaurant_to_customer_km' => $delivery_dist,
                'estimated_delivery_time_mins' => max(15, round(($pickup_dist + $delivery_dist) * 4 + 10)),
                'estimated_earnings' => $est_earnings,
                'payment_method' => $payment_type,
                'order_amount' => (float)($row['total_amount'] ?? 0),
                'delivery_type' => $delivery_type_label,
                'created_time' => $created_time_str,
                'timeout_seconds' => 20
            ];

            // Always add to available pool of open orders
            $available_orders[] = $item_data;

            // For modal popup, pick first order not currently snoozed
            if ($matching_request === null && !isset($snoozed_orders[$ord_id])) {
                $matching_request = $item_data;
            }
        }
    }

    jsonRes([
        'success' => true,
        'has_request' => $matching_request !== null,
        'request' => $matching_request,
        'available_orders' => $available_orders,
        'total_available' => count($available_orders)
    ]);
}

// -------------------------------------------------------------
// 4. ACCEPT DELIVERY REQUEST
// -------------------------------------------------------------
if ($action === 'accept_delivery') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);

    if ($order_id <= 0) {
        jsonRes(['success' => false, 'message' => 'Invalid order ID.'], 400);
    }

    // Verify order is still available
    $chk_sql = "SELECT id, order_number, status, total_amount, delivery_fee FROM orders WHERE id = ? LIMIT 1";
    $c_stmt = mysqli_prepare($conn, $chk_sql);
    mysqli_stmt_bind_param($c_stmt, "i", $order_id);
    mysqli_stmt_execute($c_stmt);
    $ord = mysqli_fetch_assoc(mysqli_stmt_get_result($c_stmt));
    mysqli_stmt_close($c_stmt);

    if (!$ord) {
        jsonRes(['success' => false, 'message' => 'Order not found.'], 404);
    }

    // Check collision in logistics_tracking
    $driver_assignee = (int)$rider['id'];
    $emp_id = (int)($rider['employee_id'] ?? 0);
    $driver_name = trim($rider['full_name'] ?? ($rider['first_name'] . ' ' . $rider['last_name']));
    $driver_phone = trim($rider['user_phone'] ?? ($rider['phone'] ?? ''));
    $driver_vehicle = trim($rider['vehicle_type'] ?? 'Motorcycle');

    if ($tracking_id > 0) {
        $t_chk = mysqli_query($conn, "SELECT driver_id, current_status FROM logistics_tracking WHERE id = $tracking_id LIMIT 1");
        $t_row = mysqli_fetch_assoc($t_chk);
        if ($t_row && !empty($t_row['driver_id']) && $t_row['driver_id'] != $driver_assignee && $t_row['driver_id'] != $emp_id && in_array($t_row['current_status'], ['assigned', 'picked_up', 'on_the_way'])) {
            jsonRes(['success' => false, 'message' => 'Order was just accepted by another rider.'], 409);
        }
    }

    // Generate 4-digit Delivery PIN if not yet present
    $pin = sprintf("%04d", rand(1000, 9999));
    mysqli_query($conn, "UPDATE orders SET delivery_pin = '$pin', status = 'preparing' WHERE id = $order_id AND (delivery_pin IS NULL OR delivery_pin = '')");

    // Upsert logistics_tracking
    if ($tracking_id <= 0) {
        $find_tr = mysqli_query($conn, "SELECT id FROM logistics_tracking WHERE order_id = $order_id LIMIT 1");
        if ($find_tr && mysqli_num_rows($find_tr) > 0) {
            $tracking_id = (int)mysqli_fetch_row($find_tr)[0];
        }
    }

    if ($tracking_id > 0) {
        $upd_tr = mysqli_prepare($conn, "
            UPDATE logistics_tracking
            SET driver_id = ?, driver_name = ?, driver_phone = ?, driver_vehicle = ?,
                current_status = 'assigned', status_timestamp = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_tr, "isssi", $driver_assignee, $driver_name, $driver_phone, $driver_vehicle, $tracking_id);
        mysqli_stmt_execute($upd_tr);
        mysqli_stmt_close($upd_tr);
    } else {
        $ins_tr = mysqli_prepare($conn, "
            INSERT INTO logistics_tracking (order_id, tracking_number, driver_id, driver_name, driver_phone, driver_vehicle, current_status, status_timestamp, created_at, updated_at)
            VALUES (?, CONCAT('TRK-', ?), ?, ?, ?, ?, 'assigned', NOW(), NOW(), NOW())
        ");
        mysqli_stmt_bind_param($ins_tr, "iiisss", $order_id, $order_id, $driver_assignee, $driver_name, $driver_phone, $driver_vehicle);
        mysqli_stmt_execute($ins_tr);
        $tracking_id = mysqli_stmt_insert_id($ins_tr);
        mysqli_stmt_close($ins_tr);
    }

    // Set rider duty status to BUSY
    mysqli_query($conn, "UPDATE riders SET duty_status = 'busy', last_location_update = NOW() WHERE id = " . (int)$rider['id']);

    // Log history
    mysqli_query($conn, "INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp) VALUES ($tracking_id, 'assigned', 'Delivery accepted by rider', NOW())");

    // Push notification
    mysqli_query($conn, "INSERT INTO rider_notifications (rider_id, title, message, type, order_id) VALUES (" . (int)$rider['id'] . ", 'Delivery Accepted', 'You have accepted Order #{$ord['order_number']}. Proceed to the restaurant.', 'delivery_request', $order_id)");

    jsonRes([
        'success' => true,
        'message' => 'Delivery accepted!',
        'order_id' => $order_id,
        'tracking_id' => $tracking_id,
        'redirect' => 'active_delivery.php'
    ]);
}

// -------------------------------------------------------------
// 5. DECLINE / SNOOZE DELIVERY REQUEST POPUP
// -------------------------------------------------------------
if ($action === 'decline_delivery') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $snooze_seconds = intval($_POST['snooze_seconds'] ?? 25);
    if ($order_id > 0) {
        if (!isset($_SESSION['snoozed_orders']) || !is_array($_SESSION['snoozed_orders'])) {
            $_SESSION['snoozed_orders'] = [];
        }
        $_SESSION['snoozed_orders'][$order_id] = time() + max(10, $snooze_seconds);
    }
    jsonRes(['success' => true, 'message' => 'Delivery request snoozed from modal.', 'order_id' => $order_id]);
}

// -------------------------------------------------------------
// 6. ADVANCE DELIVERY PROGRESS STEP (Section 5)
// -------------------------------------------------------------
if ($action === 'advance_step') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $target_step = trim($_POST['step'] ?? '');

    $allowed_steps = [
        'going_to_restaurant',
        'arrived_at_restaurant',
        'picked_up',
        'going_to_customer',
        'arrived_at_customer',
        'delivered',
        'completed'
    ];

    if (!in_array($target_step, $allowed_steps, true)) {
        jsonRes(['success' => false, 'message' => 'Invalid delivery progression step.'], 400);
    }

    $map_status = [
        'going_to_restaurant' => 'assigned',
        'arrived_at_restaurant' => 'arrived_at_restaurant',
        'picked_up' => 'picked_up',
        'going_to_customer' => 'on_the_way',
        'arrived_at_customer' => 'arriving',
        'delivered' => 'delivered',
        'completed' => 'delivered'
    ];

    $db_status = $map_status[$target_step] ?? 'assigned';

    $upd = mysqli_prepare($conn, "UPDATE logistics_tracking SET current_status = ?, status_timestamp = NOW(), updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $db_status, $tracking_id);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    // Also update order status if delivered
    if ($target_step === 'delivered' || $target_step === 'completed') {
        mysqli_query($conn, "UPDATE orders SET status = 'delivered', actual_delivery_time = NOW() WHERE id = $order_id");
    }

    // Log history
    $step_desc = ucwords(str_replace('_', ' ', $target_step));
    mysqli_query($conn, "INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp) VALUES ($tracking_id, '$db_status', '$step_desc', NOW())");

    // If rider arrived at restaurant, notify the store owner immediately
    if ($target_step === 'arrived_at_restaurant') {
        $ord_q = mysqli_query($conn, "
            SELECT o.seller_id, o.order_number, o.pickup_location,
                   u.full_name AS rider_full_name, r.rider_code
            FROM orders o
            LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
            LEFT JOIN riders r ON lt.driver_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE o.id = $order_id
            LIMIT 1
        ");
        if ($ord_q && ($ord_info = mysqli_fetch_assoc($ord_q))) {
            $seller_id = (int)($ord_info['seller_id'] ?? 0);
            if ($seller_id <= 0 && !empty($ord_info['pickup_location'])) {
                $st_q = mysqli_query($conn, "SELECT user_id FROM users WHERE store_id = " . (int)$ord_info['pickup_location'] . " LIMIT 1");
                if ($st_q && ($st_row = mysqli_fetch_assoc($st_q))) {
                    $seller_id = (int)$st_row['user_id'];
                }
            }
            $r_label = !empty($ord_info['rider_full_name']) ? $ord_info['rider_full_name'] : 'Rider';
            if (!empty($ord_info['rider_code'])) $r_label .= " ({$ord_info['rider_code']})";
            if ($seller_id > 0) {
                createNotification($conn, $seller_id, 'rider_arrived', 'Rider Arrived at Store', "Rider {$r_label} has arrived at the store to pick up Order #{$ord_info['order_number']}. Please approve or reject handover.", $order_id, 'order');
            }
        }
    }

    jsonRes([
        'success' => true,
        'step' => $target_step,
        'current_status' => $db_status,
        'message' => "Progress updated: $step_desc"
    ]);
}

// -------------------------------------------------------------
// 7. CONFIRM RESTAURANT PICKUP (Section 7)
// -------------------------------------------------------------
if ($action === 'confirm_pickup') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $verification_input = trim($_POST['verification_code'] ?? '');

    // Check order number and delivery PIN matching
    $ord_q = mysqli_query($conn, "SELECT order_number, delivery_pin FROM orders WHERE id = $order_id LIMIT 1");
    $ord_row = mysqli_fetch_assoc($ord_q);
    $real_ord_num = $ord_row['order_number'] ?? '';
    $real_pin = (string)($ord_row['delivery_pin'] ?? '');

    if (!empty($verification_input)) {
        $clean_input = str_replace('#', '', strtoupper($verification_input));
        $clean_real = str_replace('#', '', strtoupper($real_ord_num));
        $clean_pin = strtoupper($real_pin);
        $matches_ord = ($clean_input === $clean_real || strpos($clean_real, $clean_input) !== false);
        $matches_pin = ($clean_pin !== '' && $clean_input === $clean_pin);
        if (!$matches_ord && !$matches_pin) {
            jsonRes(['success' => false, 'message' => 'Verification code does not match Order #' . $real_ord_num . ' or PIN'], 400);
        }
    }

    mysqli_query($conn, "UPDATE logistics_tracking SET current_status = 'picked_up', pickup_time = NOW(), updated_at = NOW() WHERE id = $tracking_id");
    mysqli_query($conn, "INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp) VALUES ($tracking_id, 'picked_up', 'Rider confirmed pickup from restaurant', NOW())");

    jsonRes(['success' => true, 'message' => 'Pickup confirmed! Now proceeding to customer drop-off.']);
}

// -------------------------------------------------------------
// 7.1 CHECK DELIVERY STATUS (Reactive Sync from Restaurant Handover)
// -------------------------------------------------------------
if ($action === 'check_delivery_status') {
    $tracking_id = intval($_GET['tracking_id'] ?? ($_POST['tracking_id'] ?? ($_REQUEST['tracking_id'] ?? 0)));
    $order_id = intval($_GET['order_id'] ?? ($_POST['order_id'] ?? ($_REQUEST['order_id'] ?? 0)));

    $where_parts = [];
    if ($tracking_id > 0) $where_parts[] = "id = $tracking_id";
    if ($order_id > 0) $where_parts[] = "order_id = $order_id";
    $where_sql = !empty($where_parts) ? implode(" OR ", $where_parts) : "1=0";

    $tr_q = mysqli_query($conn, "SELECT id, current_status, pickup_time FROM logistics_tracking WHERE {$where_sql} ORDER BY id DESC LIMIT 1");
    $tr_row = mysqli_fetch_assoc($tr_q);
    $st = $tr_row['current_status'] ?? 'pending';

    $tr_actual_id = (int)($tr_row['id'] ?? $tracking_id);
    $hist_q = mysqli_query($conn, "SELECT status, status_description FROM logistics_tracking_history WHERE tracking_id = {$tr_actual_id} ORDER BY id DESC LIMIT 1");
    $hist_row = mysqli_fetch_assoc($hist_q);
    $rejection_note = ($hist_row && $hist_row['status'] === 'handover_paused') ? $hist_row['status_description'] : null;

    jsonRes([
        'success' => true,
        'current_status' => $st,
        'is_picked_up' => in_array($st, ['picked_up', 'on_the_way', 'arriving', 'delivered']),
        'is_arrived_at_store' => ($st === 'arrived_at_restaurant'),
        'rejection_note' => $rejection_note
    ]);
}

// -------------------------------------------------------------
// 8. RECORD COD CASH PAYMENT (Section 9)
// -------------------------------------------------------------
if ($action === 'confirm_cod_payment') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $order_total = (float)($_POST['order_total'] ?? 0.0);
    $cash_received = (float)($_POST['cash_received'] ?? 0.0);

    if ($cash_received < $order_total) {
        jsonRes(['success' => false, 'message' => 'Cash received (₱' . number_format($cash_received, 2) . ') cannot be less than order total (₱' . number_format($order_total, 2) . ').'], 400);
    }

    $change_given = round($cash_received - $order_total, 2);

    // Record separate COD collection row
    $ins = mysqli_prepare($conn, "
        INSERT INTO cod_collections (order_id, tracking_id, rider_id, order_total, cash_received, change_given, remittance_status, collected_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $r_pk = (int)$rider['id'];
    mysqli_stmt_bind_param($ins, "iiiddd", $order_id, $tracking_id, $r_pk, $order_total, $cash_received, $change_given);
    $ok = mysqli_stmt_execute($ins);
    $cod_id = mysqli_stmt_insert_id($ins);
    mysqli_stmt_close($ins);

    // Update order payment status
    mysqli_query($conn, "UPDATE orders SET payment_status = 'paid' WHERE id = $order_id");

    jsonRes([
        'success' => $ok,
        'cod_id' => $cod_id,
        'order_total' => $order_total,
        'cash_received' => $cash_received,
        'change_given' => $change_given,
        'message' => 'COD payment of ₱' . number_format($cash_received, 2) . ' recorded. Change to give: ₱' . number_format($change_given, 2)
    ]);
}

// -------------------------------------------------------------
// 9. VERIFY 4-DIGIT DELIVERY PIN (Section 11)
// -------------------------------------------------------------
if ($action === 'verify_delivery_pin') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $entered_pin = trim($_POST['pin'] ?? '');

    $ord_q = mysqli_query($conn, "SELECT delivery_pin, customer_phone FROM orders WHERE id = $order_id LIMIT 1");
    $ord_row = mysqli_fetch_assoc($ord_q);

    $expected_pin = $ord_row['delivery_pin'] ?? '';
    if (empty($expected_pin) && !empty($ord_row['customer_phone'])) {
        $expected_pin = substr(preg_replace('/\D/', '', $ord_row['customer_phone']), -4);
    }
    if (empty($expected_pin)) {
        $expected_pin = '1234';
    }

    if ($entered_pin !== $expected_pin) {
        jsonRes(['success' => false, 'message' => 'Invalid delivery PIN. Please ask the customer for their 4-digit code.'], 400);
    }

    // Insert delivery proof record
    $r_pk = (int)$rider['id'];
    $r_name = trim($rider['full_name'] ?? '');
    mysqli_query($conn, "
        INSERT INTO delivery_proofs (order_id, tracking_id, rider_id, verification_type, delivery_pin, pin_verified, recipient_name, created_at)
        VALUES ($order_id, $tracking_id, $r_pk, 'pin', '$entered_pin', 1, '$r_name', NOW())
    ");

    jsonRes(['success' => true, 'message' => 'Customer Delivery PIN verified successfully!']);
}

// -------------------------------------------------------------
// 10. COMPLETE DELIVERY WITH PHOTO PROOF & CREDIT EARNINGS (Section 12)
// -------------------------------------------------------------
if ($action === 'complete_delivery') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $tracking_id = intval($_POST['tracking_id'] ?? 0);

    // Photo Proof of Delivery handling
    $proof_path = null;
    if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/proof_of_delivery/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }

        $tmp_file = $_FILES['proof_image']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $ext = 'jpg';
        }
        $filename = 'POD_' . $order_id . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($tmp_file, $dest)) {
            $proof_path = 'uploads/proof_of_delivery/' . $filename;
        }
    }

    if (!$proof_path) {
        // Fallback: check if existing proof_of_delivery_path already exists
        $check_tr = mysqli_query($conn, "SELECT proof_of_delivery_path FROM logistics_tracking WHERE id = $tracking_id LIMIT 1");
        $check_tr_row = mysqli_fetch_assoc($check_tr);
        $proof_path = $check_tr_row['proof_of_delivery_path'] ?? null;
    }

    if (!$proof_path) {
        jsonRes(['success' => false, 'message' => 'Please take or attach a Photo Proof of Delivery before completing the delivery.'], 400);
    }

    $ord_q = mysqli_query($conn, "SELECT order_number, user_id, total_amount, delivery_fee FROM orders WHERE id = $order_id LIMIT 1");
    $ord = mysqli_fetch_assoc($ord_q);

    $del_fee = max(50.00, (float)($ord['delivery_fee'] ?? 50.00));
    $bonus = 15.00; // Peak/completion incentive bonus
    $tip = (float)($_POST['tip'] ?? 0.0);
    $total_earnings = round($del_fee + $bonus + $tip, 2);

    $r_pk = (int)$rider['id'];

    // 1. Credit rider_earnings
    mysqli_query($conn, "
        INSERT INTO rider_earnings (order_id, tracking_id, rider_id, base_delivery_fee, distance_bonus, customer_tip, total_earnings, payout_status, earned_at)
        VALUES ($order_id, $tracking_id, $r_pk, $del_fee, $bonus, $tip, $total_earnings, 'credited', NOW())
    ");

    // 2. Mark logistics_tracking delivered with proof path
    $upd_tr = mysqli_prepare($conn, "
        UPDATE logistics_tracking 
        SET current_status = 'delivered', 
            delivery_time = NOW(), 
            proof_of_delivery_path = ?,
            proof_of_delivery_timestamp = NOW(),
            updated_at = NOW() 
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($upd_tr, "si", $proof_path, $tracking_id);
    mysqli_stmt_execute($upd_tr);
    mysqli_stmt_close($upd_tr);

    // 3. Record in proof_of_delivery table
    $lat = (float)($rider['current_latitude'] ?? 0.0);
    $lng = (float)($rider['current_longitude'] ?? 0.0);
    $ins_pod = mysqli_prepare($conn, "
        INSERT INTO proof_of_delivery (tracking_id, order_id, driver_id, photo_path, location_latitude, location_longitude, delivery_condition, delivery_time)
        VALUES (?, ?, ?, ?, ?, ?, 'good', NOW())
    ");
    if ($ins_pod) {
        mysqli_stmt_bind_param($ins_pod, "iiisdd", $tracking_id, $order_id, $r_pk, $proof_path, $lat, $lng);
        mysqli_stmt_execute($ins_pod);
        mysqli_stmt_close($ins_pod);
    }

    // 3.1 Also record in delivery_proofs table with photo verification
    $r_name = trim($rider['full_name'] ?? 'Rider');
    $ins_dp = mysqli_prepare($conn, "
        INSERT INTO delivery_proofs (order_id, tracking_id, rider_id, verification_type, photo_path, pin_verified, recipient_name, delivered_latitude, delivered_longitude, created_at)
        VALUES (?, ?, ?, 'photo', ?, 1, ?, ?, ?, NOW())
    ");
    if ($ins_dp) {
        mysqli_stmt_bind_param($ins_dp, "iiissdd", $order_id, $tracking_id, $r_pk, $proof_path, $r_name, $lat, $lng);
        mysqli_stmt_execute($ins_dp);
        mysqli_stmt_close($ins_dp);
    }

    // 4. Mark orders delivered
    mysqli_query($conn, "UPDATE orders SET status = 'delivered', has_proof_of_delivery = 1, actual_delivery_time = NOW(), updated_at = NOW() WHERE id = $order_id");

    // 5. Update rider delivery stats and duty status back to online
    mysqli_query($conn, "
        UPDATE riders 
        SET duty_status = 'online', 
            total_completed_deliveries = total_completed_deliveries + 1,
            last_location_update = NOW()
        WHERE id = $r_pk
    ");

    // 6. Notify rider
    mysqli_query($conn, "
        INSERT INTO rider_notifications (rider_id, title, message, type, order_id)
        VALUES ($r_pk, 'Earnings Credited', '₱{$total_earnings} credited to your earnings for Order #{$ord['order_number']}.', 'earnings', $order_id)
    ");

    // 7. Notify customer that order is delivered with photo proof
    if (!empty($ord['user_id'])) {
        createNotification($conn, (int)$ord['user_id'], 'order_delivered', 'Order Delivered!', "Your order #{$ord['order_number']} has been successfully delivered. Photo verification has been submitted by your rider.", $order_id, 'order');
    }

    // 7.1 Notify store owner dynamically (supports all existing and newly created shops)
    $seller_id = 0;
    $shop_q = mysqli_query($conn, "SELECT seller_id, pickup_location FROM orders WHERE id = $order_id LIMIT 1");
    if ($shop_q && ($shop_row = mysqli_fetch_assoc($shop_q))) {
        $seller_id = (int)($shop_row['seller_id'] ?? 0);
        if ($seller_id <= 0 && !empty($shop_row['pickup_location'])) {
            $st_q = mysqli_query($conn, "SELECT user_id FROM users WHERE store_id = " . (int)$shop_row['pickup_location'] . " LIMIT 1");
            if ($st_q && ($st_row = mysqli_fetch_assoc($st_q))) {
                $seller_id = (int)$st_row['user_id'];
            }
        }
    }
    if ($seller_id <= 0) {
        $prod_q = mysqli_query($conn, "SELECT p.seller_id FROM order_items oi JOIN products p ON (oi.product_id = p.product_id OR oi.product_id = p.id) WHERE oi.order_id = $order_id AND p.seller_id > 0 LIMIT 1");
        if ($prod_q && ($prod_row = mysqli_fetch_assoc($prod_q))) {
            $seller_id = (int)$prod_row['seller_id'];
        }
    }
    if ($seller_id > 0) {
        $r_name_label = trim($rider['full_name'] ?? 'Rider');
        if (!empty($rider['rider_code'])) $r_name_label .= " ({$rider['rider_code']})";
        createNotification($conn, $seller_id, 'order_delivered', 'Order Delivered with Photo Proof', "Rider {$r_name_label} has completed delivery of Order #{$ord['order_number']}. Photo proof of delivery has been submitted and is ready for your review.", $order_id, 'order');
    }

    // 8. Log history
    $safe_desc = mysqli_real_escape_string($conn, 'Order successfully delivered to customer with photo proof');
    $safe_proof = mysqli_real_escape_string($conn, $proof_path);
    mysqli_query($conn, "
        INSERT INTO logistics_tracking_history (tracking_id, status, status_description, proof_path, timestamp)
        VALUES ($tracking_id, 'delivered', '$safe_desc', '$safe_proof', NOW())
    ");

    jsonRes([
        'success' => true,
        'proof_path' => $proof_path,
        'message' => 'Delivery completed! Proof of delivery recorded and earnings credited.',
        'earnings' => [
            'order_number' => $ord['order_number'],
            'delivery_fee' => $del_fee,
            'bonus' => $bonus,
            'customer_tip' => $tip,
            'total_earnings' => $total_earnings,
            'completed_at' => date('h:i A')
        ]
    ]);
}

// -------------------------------------------------------------
// 11. SUBMIT SUPPORT TICKET / EXCEPTION (Section 18 & 20)
// -------------------------------------------------------------
if ($action === 'submit_issue') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $category = trim($_POST['category'] ?? 'other');
    $description = trim($_POST['description'] ?? '');
    $wait_mins = intval($_POST['waiting_time_minutes'] ?? 0);

    if (empty($description)) {
        jsonRes(['success' => false, 'message' => 'Please provide a description of the issue.'], 400);
    }

    $r_pk = (int)$rider['id'];
    $ins = mysqli_prepare($conn, "
        INSERT INTO rider_support_tickets (rider_id, order_id, issue_category, description, waiting_time_minutes, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'open', NOW())
    ");
    mysqli_stmt_bind_param($ins, "iisss", $r_pk, $order_id, $category, $description, $wait_mins);
    $ok = mysqli_stmt_execute($ins);
    $ticket_id = mysqli_stmt_insert_id($ins);
    mysqli_stmt_close($ins);

    // If cancellation requested
    if ($category === 'cancellation_request' && $order_id > 0) {
        mysqli_query($conn, "UPDATE orders SET cancellation_reason = CONCAT('Rider Report: ', '$description') WHERE id = $order_id");
    }

    jsonRes([
        'success' => $ok,
        'ticket_id' => $ticket_id,
        'message' => 'Support ticket #' . $ticket_id . ' logged. Fleet management has been alerted.'
    ]);
}

jsonRes(['success' => false, 'message' => 'Unknown action requested.'], 400);
