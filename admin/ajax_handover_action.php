<?php
/**
 * AJAX Handler: Handover Action (Approve / Reject Pickup & Arrival Polling)
 * Enables shop owner to approve/reject riders who have arrived at the store
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

    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

    $action = trim($_POST['action'] ?? ($_GET['action'] ?? ''));

    // -------------------------------------------------------------
    // 1. POLL ARRIVED RIDERS AT STORE (For Real-time Alert)
    // -------------------------------------------------------------
    if ($action === 'check_arrivals') {
        $where_clauses = [
            "o.is_archived = 0",
            "o.delivery_option = 'delivery'",
            "o.status NOT IN ('delivered', 'cancelled', 'failed')",
            "lt.current_status = 'arrived_at_restaurant'"
        ];

        if ($seller_scope_id !== null) {
            $where_clauses[] = getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
        }

        $where_sql = implode(' AND ', $where_clauses);
        $query = "
            SELECT o.id AS order_id, o.order_number, o.customer_name, o.delivery_pin,
                   lt.id AS tracking_id, lt.status_timestamp, lt.updated_at,
                   COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), 'Assigned Rider') AS driver_name,
                   COALESCE(NULLIF(lt.driver_phone, ''), u_rdr.phone, e_rdr.phone, '') AS driver_phone,
                   r.rider_code, r.vehicle_type
            FROM orders o
            JOIN logistics_tracking lt ON lt.order_id = o.id
            LEFT JOIN riders r ON lt.driver_id = r.id
            LEFT JOIN users u_rdr ON r.user_id = u_rdr.id
            LEFT JOIN employees e_rdr ON r.employee_id = e_rdr.id
            WHERE {$where_sql}
            ORDER BY lt.updated_at DESC
        ";

        $res = mysqli_query($conn, $query);
        $arrivals = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $arrivals[] = [
                    'order_id' => (int)$row['order_id'],
                    'order_number' => $row['order_number'],
                    'customer_name' => $row['customer_name'],
                    'delivery_pin' => $row['delivery_pin'],
                    'tracking_id' => (int)$row['tracking_id'],
                    'driver_name' => $row['driver_name'],
                    'driver_phone' => $row['driver_phone'],
                    'rider_code' => $row['rider_code'] ?? '',
                    'vehicle_type' => $row['vehicle_type'] ?? 'Motorcycle',
                    'arrived_at' => !empty($row['updated_at']) ? date('h:i A', strtotime($row['updated_at'])) : date('h:i A')
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'count' => count($arrivals),
            'arrivals' => $arrivals
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // POST ACTIONS REQUIRE CSRF TOKEN & ORDER ID
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        throw new Exception('Security token expired. Please refresh the page and try again.');
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        throw new Exception('Invalid order specified.');
    }

    // Franchise scoping check
    if ($seller_scope_id !== null) {
        $scope_sql = "SELECT o.id FROM orders o WHERE o.id = ? AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id') . " LIMIT 1";
        $scope_stmt = mysqli_prepare($conn, $scope_sql);
        mysqli_stmt_bind_param($scope_stmt, "i", $order_id);
        mysqli_stmt_execute($scope_stmt);
        $scope_res = mysqli_stmt_get_result($scope_stmt);
        $allowed = $scope_res && mysqli_fetch_assoc($scope_res);
        mysqli_stmt_close($scope_stmt);

        if (!$allowed) {
            throw new Exception('Unauthorized: You can only manage orders belonging to your store.');
        }
    }

    // Fetch order and tracking record
    $ord_query = "
        SELECT o.id, o.order_number, o.status, o.user_id, o.delivery_pin,
               lt.id AS tracking_id, lt.current_status AS tracking_status, lt.driver_id,
               COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), 'Assigned Rider') AS driver_name,
               COALESCE(NULLIF(lt.driver_phone, ''), u_rdr.phone, e_rdr.phone, '') AS driver_phone,
               r.rider_code
        FROM orders o
        LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
        LEFT JOIN riders r ON lt.driver_id = r.id
        LEFT JOIN users u_rdr ON r.user_id = u_rdr.id
        LEFT JOIN employees e_rdr ON r.employee_id = e_rdr.id
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

    $tracking_id = (int)($order['tracking_id'] ?? 0);
    if ($tracking_id <= 0) {
        throw new Exception('No active logistics tracking entry found for this order.');
    }

    // -------------------------------------------------------------
    // 2. APPROVE HANDOVER
    // -------------------------------------------------------------
    if ($action === 'approve') {
        // Update tracking to picked_up
        $upd_tr = mysqli_prepare($conn, "
            UPDATE logistics_tracking 
            SET current_status = 'picked_up', pickup_time = NOW(), updated_at = NOW() 
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_tr, "i", $tracking_id);
        mysqli_stmt_execute($upd_tr);
        mysqli_stmt_close($upd_tr);

        // Record tracking history
        $driver_label = !empty($order['driver_name']) ? $order['driver_name'] : 'Rider';
        $desc = "Store approved order handover to {$driver_label}. Order released for customer delivery.";
        $hist = mysqli_prepare($conn, "
            INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp)
            VALUES (?, 'picked_up', ?, NOW())
        ");
        mysqli_stmt_bind_param($hist, "is", $tracking_id, $desc);
        mysqli_stmt_execute($hist);
        mysqli_stmt_close($hist);

        // Update orders.status to preparing if pending/confirmed
        if (in_array($order['status'], ['pending', 'confirmed'])) {
            $upd_o = mysqli_prepare($conn, "UPDATE orders SET status = 'preparing', updated_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd_o, "i", $order_id);
            mysqli_stmt_execute($upd_o);
            mysqli_stmt_close($upd_o);
        }

        // Notify customer with driver info
        if (!empty($order['user_id'])) {
            $notif_title = 'Order Handed Over to Rider';
            $notif_msg = "Your order #{$order['order_number']} has been picked up by {$driver_label} and is now on the way to your delivery address!";
            createNotification($conn, (int)$order['user_id'], 'order_picked_up', $notif_title, $notif_msg, $order_id, 'order');
        }

        echo json_encode([
            'success' => true,
            'message' => "Order #{$order['order_number']} approved! {$driver_label} is now en route to the customer.",
            'order_id' => $order_id,
            'new_status' => 'picked_up'
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 3. REJECT / PAUSE HANDOVER
    // -------------------------------------------------------------
    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $reason = 'Food is still being prepared by kitchen staff. Please wait a few moments.';
        }

        // Reset tracking back to assigned
        $upd_tr = mysqli_prepare($conn, "
            UPDATE logistics_tracking 
            SET current_status = 'assigned', updated_at = NOW() 
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd_tr, "i", $tracking_id);
        mysqli_stmt_execute($upd_tr);
        mysqli_stmt_close($upd_tr);

        // Log rejection in tracking history with reason
        $desc = "Pickup temporarily paused by store: " . $reason;
        $hist = mysqli_prepare($conn, "
            INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp)
            VALUES (?, 'handover_paused', ?, NOW())
        ");
        mysqli_stmt_bind_param($hist, "is", $tracking_id, $desc);
        mysqli_stmt_execute($hist);
        mysqli_stmt_close($hist);

        echo json_encode([
            'success' => true,
            'message' => "Pickup paused. Rider has been notified: '{$reason}'",
            'order_id' => $order_id,
            'new_status' => 'assigned'
        ]);
        exit;
    }

    throw new Exception('Unknown action requested.');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
