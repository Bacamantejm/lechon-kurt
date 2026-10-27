<?php
/**
 * AJAX Handler: Confirm Order Pickup Handover
 * Validates the confirmation code entered by the shop owner/admin
 * Updates logistics tracking status to 'picked_up' and advances the rider workflow
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

try {
    checkAdminAccess();
    requirePermission('orders.view');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        throw new Exception('Security validation failed. Please refresh the page and try again.');
    }

    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

    $order_id = intval($_POST['order_id'] ?? 0);
    $code_input = trim((string)($_POST['verification_code'] ?? ''));

    if ($order_id <= 0) {
        throw new Exception('Invalid order selected.');
    }

    if ($code_input === '') {
        throw new Exception('Please enter the verification code or delivery PIN.');
    }

    // Franchise seller scoping check
    if ($seller_scope_id !== null) {
        $scope_sql = "SELECT o.id FROM orders o WHERE o.id = ? AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id') . " LIMIT 1";
        $scope_stmt = mysqli_prepare($conn, $scope_sql);
        mysqli_stmt_bind_param($scope_stmt, "i", $order_id);
        mysqli_stmt_execute($scope_stmt);
        $scope_res = mysqli_stmt_get_result($scope_stmt);
        $allowed = $scope_res && mysqli_fetch_assoc($scope_res);
        mysqli_stmt_close($scope_stmt);

        if (!$allowed) {
            throw new Exception('Unauthorized: You can only confirm pickup for orders assigned to your store.');
        }
    }

    // Fetch order, tracking, and assigned rider details
    $ord_query = "
        SELECT o.id, o.order_number, o.status, o.user_id, o.delivery_pin, o.delivery_option,
               lt.id AS tracking_id, lt.current_status AS tracking_status, lt.driver_id, lt.driver_name,
               r.rider_code,
               u.full_name AS customer_name
        FROM orders o
        LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
        LEFT JOIN riders r ON lt.driver_id = r.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ";
    $ord_stmt = mysqli_prepare($conn, $ord_query);
    mysqli_stmt_bind_param($ord_stmt, "i", $order_id);
    mysqli_stmt_execute($ord_stmt);
    $ord_res = mysqli_stmt_get_result($ord_stmt);
    $order = mysqli_fetch_assoc($ord_res);
    mysqli_stmt_close($ord_stmt);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    // Prepare code matching candidates
    $clean_input = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code_input));
    $real_pin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($order['delivery_pin'] ?? '')));
    $real_ord_num = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($order['order_number'] ?? '')));
    $real_rider_code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($order['rider_code'] ?? '')));

    $is_valid = false;
    $matched_type = '';

    if ($real_pin !== '' && $clean_input === $real_pin) {
        $is_valid = true;
        $matched_type = 'Delivery PIN';
    } elseif ($real_rider_code !== '' && ($clean_input === $real_rider_code || strpos($real_rider_code, $clean_input) !== false)) {
        $is_valid = true;
        $matched_type = 'Rider ID Code';
    } elseif ($real_ord_num !== '' && ($clean_input === $real_ord_num || strpos($real_ord_num, $clean_input) !== false)) {
        $is_valid = true;
        $matched_type = 'Order Number';
    }

    if (!$is_valid) {
        throw new Exception('Verification code does not match. Please verify the Rider Code or 4-digit PIN (' . ($order['delivery_pin'] ?: 'N/A') . ').');
    }

    // Ensure logistics tracking record exists
    $tracking_id = (int)($order['tracking_id'] ?? 0);
    if ($tracking_id <= 0) {
        $ins_track = mysqli_prepare($conn, "
            INSERT INTO logistics_tracking (order_id, tracking_number, current_status, pickup_time, created_at, updated_at)
            VALUES (?, ?, 'picked_up', NOW(), NOW(), NOW())
        ");
        $gen_tr_num = 'TRK-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        mysqli_stmt_bind_param($ins_track, "is", $order_id, $gen_tr_num);
        mysqli_stmt_execute($ins_track);
        $tracking_id = mysqli_insert_id($conn);
        mysqli_stmt_close($ins_track);
    } else {
        $upd_track = mysqli_prepare($conn, "
            UPDATE logistics_tracking 
            SET current_status = 'picked_up', pickup_time = NOW(), updated_at = NOW() 
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_track, "i", $tracking_id);
        mysqli_stmt_execute($upd_track);
        mysqli_stmt_close($upd_track);
    }

    // Log tracking history
    $desc = "Store verified handover with {$matched_type} ({$code_input}). Order released to rider.";
    $hist_stmt = mysqli_prepare($conn, "
        INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp)
        VALUES (?, 'picked_up', ?, NOW())
    ");
    mysqli_stmt_bind_param($hist_stmt, "is", $tracking_id, $desc);
    mysqli_stmt_execute($hist_stmt);
    mysqli_stmt_close($hist_stmt);

    // Update order status to 'preparing' if currently 'pending' or 'confirmed'
    if (in_array($order['status'], ['pending', 'confirmed'])) {
        $upd_ord = mysqli_prepare($conn, "UPDATE orders SET status = 'preparing', updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd_ord, "i", $order_id);
        mysqli_stmt_execute($upd_ord);
        mysqli_stmt_close($upd_ord);
    }

    // Send customer notification
    if (!empty($order['user_id'])) {
        $driver_label = !empty($order['driver_name']) ? $order['driver_name'] : 'the delivery rider';
        $notif_title = 'Order Handed Over to Rider';
        $notif_msg = "Your order #{$order['order_number']} has been picked up by {$driver_label} and is now on the way to you!";
        createNotification($conn, (int)$order['user_id'], 'order_picked_up', $notif_title, $notif_msg, $order_id, 'order');
    }

    echo json_encode([
        'success' => true,
        'message' => "Order #{$order['order_number']} verified and released! Rider is now cleared to deliver.",
        'order_id' => $order_id,
        'tracking_id' => $tracking_id,
        'matched_type' => $matched_type
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
