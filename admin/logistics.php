<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once '../includes/security.php';
require_once '../logistics_service.php';
require_once '../preorder_service.php';
require_once 'hr_module_common.php';
requirePermission('logistics.view');
$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));

$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_order_scope_sql = '';
$partner_logistics_scope_sql = '';
$partner_preorder_scope_sql = '';
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
    $partner_order_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
    $partner_logistics_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id');
    $partner_preorder_scope_sql = " AND EXISTS (
        SELECT 1
        FROM products p_scope
        WHERE p_scope.id = po.product_id
          AND {$partner_product_scope_sql}
    )";
}
$driver_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$driver_role_sql = hrLogisticsEmployeeSqlCondition('e', 'd', $conn);

// Detect if logged-in account is an employee / driver
$current_driver = null;
$driver_chk_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, phone, vehicle_details FROM employees WHERE user_id = ? AND status = 'active' LIMIT 1");
if ($driver_chk_stmt) {
    mysqli_stmt_bind_param($driver_chk_stmt, "i", $current_user_id);
    mysqli_stmt_execute($driver_chk_stmt);
    $driver_res = mysqli_stmt_get_result($driver_chk_stmt);
    if ($driver_res && mysqli_num_rows($driver_res) > 0) {
        $current_driver = mysqli_fetch_assoc($driver_res);
    }
    mysqli_stmt_close($driver_chk_stmt);
}

$rider_display_name = $current_driver ? trim($current_driver['first_name'] . ' ' . $current_driver['last_name']) : ($admin_info['full_name'] ?? 'Rider');
$current_driver_id = (int)($current_driver['id'] ?? 0);

// Fetch Rider's Current Active Mission (if any)
$active_mission = null;
if ($current_driver_id > 0) {
    $act_stmt = mysqli_prepare($conn, "
        SELECT lt.id as tracking_id, lt.order_id, lt.current_status, lt.estimated_delivery,
               lt.current_latitude as driver_lat, lt.current_longitude as driver_lng,
               o.order_number, o.customer_name, o.customer_phone, o.delivery_address,
               o.latitude as customer_latitude, o.longitude as customer_longitude,
               o.total_amount, o.delivery_fee, o.special_instructions
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id AND o.delivery_option = 'delivery'
        WHERE lt.driver_id = ? 
          AND o.status NOT IN ('cancelled', 'completed')
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
          {$partner_order_scope_sql}
        ORDER BY lt.updated_at DESC
        LIMIT 1
    ");
    if ($act_stmt) {
        mysqli_stmt_bind_param($act_stmt, "i", $current_driver_id);
        mysqli_stmt_execute($act_stmt);
        $act_res = mysqli_stmt_get_result($act_stmt);
        if ($act_res && mysqli_num_rows($act_res) > 0) {
            $active_mission = mysqli_fetch_assoc($act_res);
        }
        mysqli_stmt_close($act_stmt);
    }
}

// Fallback for admin: if admin testing, fetch the most recent active order as active mission
if (!$active_mission) {
    $act_admin_res = mysqli_query($conn, "
        SELECT lt.id as tracking_id, lt.order_id, lt.current_status, lt.estimated_delivery,
               lt.current_latitude as driver_lat, lt.current_longitude as driver_lng,
               o.order_number, o.customer_name, o.customer_phone, o.delivery_address,
               o.latitude as customer_latitude, o.longitude as customer_longitude,
               o.total_amount, o.delivery_fee, o.special_instructions
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id AND o.delivery_option = 'delivery'
        WHERE o.status NOT IN ('cancelled', 'completed')
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
          {$partner_order_scope_sql}
        ORDER BY lt.updated_at DESC
        LIMIT 1
    ");
    if ($act_admin_res && mysqli_num_rows($act_admin_res) > 0) {
        $active_mission = mysqli_fetch_assoc($act_admin_res);
    }
}

// Distance Calculation Helper for Rider Feed
if (!function_exists('calculateRiderRouteDistanceKm')) {
    function calculateRiderRouteDistanceKm($lat1, $lon1, $lat2, $lon2) {
        if (!is_numeric($lat1) || !is_numeric($lon1) || !is_numeric($lat2) || !is_numeric($lon2)) return null;
        $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
        if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) return null;
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $dist = $earthRadius * $c;
        return ($dist > 0.05 && $dist < 500) ? round($dist, 1) : null;
    }
}

$stores_by_id = [];
$default_store = null;
$store_res = mysqli_query($conn, "SELECT store_id, store_name, address, city, latitude, longitude, is_active, owner_user_id FROM store_locations WHERE is_active = 1 ORDER BY store_id ASC");
if ($store_res) {
    while ($s_row = mysqli_fetch_assoc($store_res)) {
        $stores_by_id[(int)$s_row['store_id']] = $s_row;
        if ($seller_scope_id !== null && (int)($s_row['owner_user_id'] ?? 0) === (int)$seller_scope_id) {
            $default_store = $s_row;
        } elseif (!$default_store) {
            $default_store = $s_row;
        }
    }
}

// Quick Rider Feed Queries
// 1. Available Deliveries: strictly pending food orders awaiting a driver, excluding cancelled/completed
$available_delivery_orders = [];
$avail_res = mysqli_query($conn, "
    SELECT lt.id as tracking_id, o.id as order_id, COALESCE(lt.current_status, 'pending') as current_status,
           lt.pickup_time, lt.estimated_delivery,
           o.order_number, o.customer_name, o.customer_phone, o.delivery_address,
           o.latitude as customer_latitude, o.longitude as customer_longitude,
           o.total_amount, o.delivery_fee, o.special_instructions, o.created_at,
           o.pickup_location,
           sl.store_name, sl.address as store_address, sl.latitude as store_latitude, sl.longitude as store_longitude
    FROM orders o
    LEFT JOIN logistics_tracking lt ON o.id = lt.order_id
    LEFT JOIN store_locations sl ON sl.store_id = o.pickup_location
    WHERE o.delivery_option = 'delivery'
      AND o.status NOT IN ('cancelled', 'completed', 'delivered')
      AND (lt.current_status IS NULL OR lt.current_status = 'pending' OR lt.driver_id IS NULL OR lt.driver_id = 0)
      {$partner_order_scope_sql}
    ORDER BY o.created_at DESC
    LIMIT 24
");
if ($avail_res) {
    while ($r = mysqli_fetch_assoc($avail_res)) $available_delivery_orders[] = $r;
}

// 2. Active Deliveries: in-transit missions assigned to rider or active
$my_active_delivery_orders = [];
if ($current_driver_id > 0) {
    $my_act_stmt = mysqli_prepare($conn, "
        SELECT lt.id as tracking_id, o.id as order_id, lt.current_status, lt.pickup_time, lt.estimated_delivery,
               o.order_number, o.customer_name, o.customer_phone, o.delivery_address, o.delivery_pin,
               o.latitude as customer_latitude, o.longitude as customer_longitude,
               o.total_amount, o.delivery_fee, o.special_instructions
        FROM orders o
        JOIN logistics_tracking lt ON o.id = lt.order_id
        WHERE o.delivery_option = 'delivery'
          AND o.status NOT IN ('cancelled', 'completed', 'delivered')
          AND lt.driver_id = ?
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
          {$partner_order_scope_sql}
        ORDER BY lt.updated_at DESC
    ");
    if ($my_act_stmt) {
        mysqli_stmt_bind_param($my_act_stmt, "i", $current_driver_id);
        mysqli_stmt_execute($my_act_stmt);
        $my_act_res = mysqli_stmt_get_result($my_act_stmt);
        while ($r = mysqli_fetch_assoc($my_act_res)) $my_active_delivery_orders[] = $r;
        mysqli_stmt_close($my_act_stmt);
    }
} else {
    // If admin, show all active in transit
    $my_act_res = mysqli_query($conn, "
        SELECT lt.id as tracking_id, o.id as order_id, lt.current_status, lt.pickup_time, lt.estimated_delivery,
               o.order_number, o.customer_name, o.customer_phone, o.delivery_address, o.delivery_pin,
               o.latitude as customer_latitude, o.longitude as customer_longitude,
               o.total_amount, o.delivery_fee, o.special_instructions
        FROM orders o
        JOIN logistics_tracking lt ON o.id = lt.order_id
        WHERE o.delivery_option = 'delivery'
          AND o.status NOT IN ('cancelled', 'completed', 'delivered')
          AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
          {$partner_order_scope_sql}
        ORDER BY lt.updated_at DESC
        LIMIT 18
    ");
    if ($my_act_res) {
        while ($r = mysqli_fetch_assoc($my_act_res)) $my_active_delivery_orders[] = $r;
    }
}

// 3. Cancelled Deliveries: strictly separated from available and active
$cancelled_delivery_orders = [];
$canc_res = mysqli_query($conn, "
    SELECT lt.id as tracking_id, o.id as order_id, COALESCE(lt.current_status, o.status) as current_status,
           lt.pickup_time, lt.estimated_delivery,
           o.order_number, o.customer_name, o.customer_phone, o.delivery_address,
           o.latitude as customer_latitude, o.longitude as customer_longitude,
           o.total_amount, o.delivery_fee, o.special_instructions, o.created_at, o.updated_at
    FROM orders o
    LEFT JOIN logistics_tracking lt ON o.id = lt.order_id
    WHERE o.delivery_option = 'delivery'
      AND (o.status = 'cancelled' OR lt.current_status IN ('cancelled', 'failed'))
      {$partner_order_scope_sql}
    ORDER BY o.updated_at DESC
    LIMIT 20
");
if ($canc_res) {
    while ($r = mysqli_fetch_assoc($canc_res)) $cancelled_delivery_orders[] = $r;
}

$logistics = new LogisticsService($conn);
$preorder_service = new PreOrderService($conn);
$error = '';
$success = '';
$allowed_delivery_statuses = ['pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled'];
$allowed_preorder_statuses = ['pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled'];

// --- NEW: Fetch available drivers ---
$available_drivers_query = "
    SELECT e.id, e.first_name, e.last_name, e.phone, e.vehicle_details
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN attendance a ON a.employee_id = e.id
        AND a.attendance_date = CURDATE()
        AND a.status IN ('present', 'late', 'half_day')
        AND (a.hr_status IS NULL OR a.hr_status <> 'rejected')
    WHERE e.status = 'active' 
    AND {$driver_role_sql}
    AND {$driver_scope_sql}
    AND a.id IS NOT NULL
    AND e.id NOT IN (
        SELECT lt.driver_id 
        FROM logistics_tracking lt 
        WHERE lt.driver_id IS NOT NULL 
        AND lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving')
    )
    ORDER BY e.first_name ASC
";
$available_drivers_result = mysqli_query($conn, $available_drivers_query);
$available_drivers = [];
if ($available_drivers_result) while($driver = mysqli_fetch_assoc($available_drivers_result)) $available_drivers[] = $driver;

// Handle COD Remittance Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_cod_id'])) {
    if (validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $v_id = (int)$_POST['verify_cod_id'];
        mysqli_query($conn, "UPDATE cod_collections SET remittance_status = 'verified', remitted_at = NOW() WHERE id = $v_id");
        $success = 'COD Remittance verified successfully.';
    } else {
        $error = 'Invalid security token verifying remittance.';
    }
}

// Handle Rider Support Ticket Resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket_id'])) {
    if (validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $t_id = (int)$_POST['reply_ticket_id'];
        $reply = mysqli_real_escape_string($conn, trim($_POST['admin_response'] ?? ''));
        $t_status = mysqli_real_escape_string($conn, trim($_POST['ticket_status'] ?? 'resolved'));
        mysqli_query($conn, "UPDATE rider_support_tickets SET admin_response = '$reply', status = '$t_status', updated_at = NOW() WHERE id = $t_id");
        $success = 'Rider support ticket updated successfully.';
    } else {
        $error = 'Invalid security token updating support ticket.';
    }
}

// Fetch Fleet Riders for Admin Tab
$fleet_riders = [];
$fleet_res = mysqli_query($conn, "
    SELECT r.*, u.full_name, u.phone AS user_phone, u.email, sl.store_name
    FROM riders r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN store_locations sl ON r.store_id = sl.store_id
    ORDER BY FIELD(r.duty_status, 'busy', 'online', 'offline'), r.rating DESC
");
if ($fleet_res) {
    while ($fr = mysqli_fetch_assoc($fleet_res)) $fleet_riders[] = $fr;
}

// Fetch COD Collections for Admin
$admin_cod_collections = [];
$admin_cod_res = mysqli_query($conn, "
    SELECT cc.*, o.order_number, r.rider_code, u.full_name AS rider_name
    FROM cod_collections cc
    JOIN orders o ON cc.order_id = o.id
    JOIN riders r ON cc.rider_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE 1=1 {$partner_order_scope_sql}
    ORDER BY cc.collected_at DESC
    LIMIT 30
");
if ($admin_cod_res) {
    while ($cr = mysqli_fetch_assoc($admin_cod_res)) $admin_cod_collections[] = $cr;
}

// Fetch Rider Support Tickets for Admin
$admin_tickets = [];
$admin_t_scope_sql = $seller_scope_id !== null ? " WHERE (t.order_id IS NULL OR " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 't.order_id') . ")" : "";
$admin_t_res = mysqli_query($conn, "
    SELECT t.*, r.rider_code, u.full_name AS rider_name, o.order_number
    FROM rider_support_tickets t
    JOIN riders r ON t.rider_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN orders o ON t.order_id = o.id
    {$admin_t_scope_sql}
    ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.created_at DESC
    LIMIT 30
");
if ($admin_t_res) {
    while ($tr = mysqli_fetch_assoc($admin_t_res)) $admin_tickets[] = $tr;
}

// Handle Delivery Status Update (Fix for missing update_logistics.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery_status'])) {
    requirePermission('logistics.update');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');

    if (empty($error) && ($tracking_id > 0 || $order_id > 0) && in_array($new_status, $allowed_delivery_statuses, true)) {
        if ($tracking_id <= 0 && $order_id > 0) {
            $t_chk = mysqli_query($conn, "SELECT id FROM logistics_tracking WHERE order_id = " . (int)$order_id . " LIMIT 1");
            if ($t_chk && ($t_row = mysqli_fetch_assoc($t_chk))) {
                $tracking_id = (int)$t_row['id'];
            } else {
                $init_res = $logistics->createTrackingRecord($order_id, 1);
                if (!empty($init_res['success']) && !empty($init_res['tracking_id'])) {
                    $tracking_id = (int)$init_res['tracking_id'];
                }
            }
        }

        if ($seller_scope_id !== null && $tracking_id > 0) {
            $scope_query = "SELECT lt.id
                            FROM logistics_tracking lt
                            WHERE lt.id = ? {$partner_logistics_scope_sql}
                            LIMIT 1";
            $scope_stmt = mysqli_prepare($conn, $scope_query);
            mysqli_stmt_bind_param($scope_stmt, "i", $tracking_id);
            mysqli_stmt_execute($scope_stmt);
            $scope_result = mysqli_stmt_get_result($scope_stmt);
            $scoped_tracking = $scope_result ? mysqli_fetch_assoc($scope_result) : null;
            mysqli_stmt_close($scope_stmt);

            if (!$scoped_tracking) {
                $error = "You can only update deliveries tied to your own store orders.";
            }
        }

        if (empty($error)) {
            if ($tracking_id > 0) {
                $update_result = $logistics->updateTrackingStatus($tracking_id, $new_status, 'Updated from logistics portal');
                if ($update_result['success']) {
                    $success = "Delivery status updated successfully!";
                } else {
                    $error = $update_result['message'] ?? "Failed to update delivery status.";
                }
            } elseif ($order_id > 0) {
                $order_status_val = ($new_status === 'delivered') ? 'completed' : (($new_status === 'cancelled') ? 'cancelled' : 'out_for_delivery');
                mysqli_query($conn, "UPDATE orders SET status = '" . mysqli_real_escape_string($conn, $order_status_val) . "', updated_at = NOW() WHERE id = " . (int)$order_id);
                $success = "Order status updated successfully!";
            }
        }
    } else {
        if (empty($error)) {
            $error = "Invalid delivery status update request.";
        }
    }
}

// Handle Pre-order Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preorder_status'])) {
    requireAnyPermission(['preorders.edit', 'logistics.update']);
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $pre_order_id = intval($_POST['pre_order_id']);
    $new_status = trim($_POST['new_status']);
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if (empty($error) && $pre_order_id > 0 && in_array($new_status, $allowed_preorder_statuses, true)) {
        if ($seller_scope_id !== null) {
            $scope_query = "SELECT po.id
                            FROM pre_orders po
                            INNER JOIN products p_scope ON p_scope.id = po.product_id
                            WHERE po.id = ? AND {$partner_product_scope_sql}
                            LIMIT 1";
            $scope_stmt = mysqli_prepare($conn, $scope_query);
            mysqli_stmt_bind_param($scope_stmt, "i", $pre_order_id);
            mysqli_stmt_execute($scope_stmt);
            $scope_result = mysqli_stmt_get_result($scope_stmt);
            $scoped_preorder = $scope_result ? mysqli_fetch_assoc($scope_result) : null;
            mysqli_stmt_close($scope_stmt);

            if (!$scoped_preorder) {
                $error = "You can only update pre-orders for your own store.";
            }
        }

        if (empty($error)) {
            $update_result = $preorder_service->updatePreOrderStatus($pre_order_id, $new_status, $admin_notes);
            if ($update_result['success']) {
                $success = "Pre-order status updated successfully!";
            } else {
                $error = htmlspecialchars($update_result['message']);
            }
        }
    } else {
        if (empty($error)) {
            $error = "Invalid pre-order status update request.";
        }
    }
}

// Pagination settings
$records_per_page = 20;
$current_page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page_number < 1) {
    $current_page_number = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Leaflet Mapping Library CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        /* Leaflet Logistics Map Styles */
        #logisticsMap {
            height: 70vh;
            width: 100%;
            border-radius: 12px;
            border: 1px solid #eaecf0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            z-index: 1;
        }
        body.dark-mode #logisticsMap {
            border-color: #27303f !important;
        }
        .driver-leaflet-marker {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            background: #b3261e;
            color: #ffffff;
            border-radius: 50%;
            border: 3px solid #ffffff;
            box-shadow: 0 4px 14px rgba(179,38,30,0.45);
            font-size: 1rem;
            position: relative;
        }
        .driver-leaflet-marker::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 2px solid #b3261e;
            animation: driverPulse 1.8s infinite;
        }
        @keyframes driverPulse {
            0% { transform: scale(0.9); opacity: 0.9; }
            100% { transform: scale(1.45); opacity: 0; }
        }
        .customer-leaflet-marker {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: #1e293b;
            color: #38bdf8;
            border-radius: 12px;
            border: 2px solid #ffffff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            font-size: 1rem;
        }
        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            padding: 4px;
        }
        body.dark-mode .leaflet-popup-content-wrapper,
        body.dark-mode .leaflet-popup-tip {
            background-color: #1e2029 !important;
            color: #e2e8f0 !important;
        }

        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }
        
        body.dark-mode .table-responsive {
             background-color: var(--card-bg-dark) !important;
             border-color: var(--border-color-dark) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination {
            --bs-pagination-color: #c62828;
            --bs-pagination-hover-color: #a71c1c;
            --bs-pagination-active-bg: #c62828;
            --bs-pagination-active-border-color: #c62828;
        }
        body.dark-mode .pagination {
            --bs-pagination-bg: var(--card-bg-dark);
            --bs-pagination-border-color: var(--border-color-dark);
            --bs-pagination-hover-bg: #3d3d3d;
        }
        /* Delivery Rider Portal Theme Styles */
        .rider-profile-hero {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.05);
            transition: all 0.2s ease;
        }
        body.dark-mode .rider-profile-hero {
            background: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }
        .rider-avatar-bubble {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: #b3261e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #ffffff;
            position: relative;
            box-shadow: 0 4px 12px rgba(179, 38, 30, 0.25);
        }
        .rider-online-status-dot {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 15px;
            height: 15px;
            background: #12b76a;
            border: 2px solid #ffffff;
            border-radius: 50%;
        }
        .active-mission-card {
            background: #ffffff;
            border: 2px solid #fee4e2 !important;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(179, 38, 30, 0.08);
            position: relative;
            overflow: hidden;
        }
        body.dark-mode .active-mission-card {
            background: #252836 !important;
            border-color: #7f1d1d !important;
        }
        .active-mission-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #fff1f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        body.dark-mode .active-mission-icon {
            background: #3f1a1d !important;
        }
        .rider-nav-pills {
            display: flex;
            gap: 8px;
            padding: 6px;
            background: #eaecf0;
            border-radius: 12px;
            margin-bottom: 24px;
            overflow-x: auto;
        }
        body.dark-mode .rider-nav-pills {
            background: #262934 !important;
        }
        .rider-nav-pills .nav-link {
            border: none;
            border-radius: 9px;
            padding: 10px 18px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #475467;
            background: transparent;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        body.dark-mode .rider-nav-pills .nav-link {
            color: #94a3b8;
        }
        .rider-nav-pills .nav-link.active {
            background: #ffffff;
            color: #b3261e;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.1);
        }
        body.dark-mode .rider-nav-pills .nav-link.active {
            background: #1e293b !important;
            color: #f87171 !important;
        }
        .rider-order-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 18px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        body.dark-mode .rider-order-card {
            background: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }
        .rider-order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(16, 24, 40, 0.08);
        }
        .rider-map-hud {
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            z-index: 999;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(8px);
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            padding: 12px 18px;
            pointer-events: auto;
        }
        body.dark-mode .rider-map-hud {
            background: rgba(30, 32, 41, 0.95) !important;
            border-color: #334155 !important;
            color: #f1f5f9 !important;
        }
        .hud-stat-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .hud-stat-val {
            font-size: 16px;
            font-weight: 700;
            color: #101828;
            line-height: 1.2;
        }
        body.dark-mode .hud-stat-val {
            color: #f8fafc !important;
        }
    </style>
</head>
<body class="admin-polish logistics-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler" title="Toggle Navigation"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-motorcycle text-danger me-2"></i>Rider Delivery Portal</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">

                <!-- Rider Profile Hero Banner -->
                <div class="rider-profile-hero p-3 p-md-4 mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rider-avatar-bubble">
                                <i class="fas fa-motorcycle"></i>
                                <span class="rider-online-status-dot" title="Online & Active"></span>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h3 class="mb-0 fw-bold" style="color: #101828; font-size: 1.25rem;"><?php echo htmlspecialchars($rider_display_name); ?></h3>
                                    <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size: 11px;">
                                        <i class="fas fa-shield-alt me-1"></i> Delivery Driver
                                    </span>
                                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size: 11px;">
                                        <i class="fas fa-circle me-1" style="font-size: 8px;"></i> On Duty
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-id-badge me-1"></i> <?php echo $current_driver_id ? 'Driver ID #' . $current_driver_id : 'Fleet Associate'; ?> &bull; 
                                    <i class="fas fa-biking me-1 ms-1"></i> <?php echo htmlspecialchars($current_driver['vehicle_details'] ?? 'Motorcycle Delivery Fleet'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
                            <button type="button" class="btn btn-sm" id="quickGpsToggleBtn" onclick="toggleGpsBroadcast()" style="background:#b3261e; color:#ffffff; font-weight:600; border-radius:8px; padding: 7px 14px;">
                                <i class="fas fa-location-arrow me-1"></i> <span id="quickGpsText">Live GPS Streaming</span>
                            </button>
                            <a href="#mapView" data-bs-toggle="tab" class="btn btn-sm btn-outline-secondary" style="border-radius:8px; padding: 7px 14px;">
                                <i class="fas fa-map-marked-alt me-1"></i> View Route Map
                            </a>
                            <a href="../rider/index.php" target="_blank" class="btn btn-sm btn-dark" style="border-radius:8px; padding: 7px 14px; font-weight:600;">
                                <i class="fas fa-mobile-alt me-1 text-danger"></i> Rider Portal
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($active_mission): ?>
                <script>
                window.deliveryOrdersMap = window.deliveryOrdersMap || {};
                window.deliveryOrdersMap[<?= (int)$active_mission['order_id']; ?>] = <?= json_encode([
                    'order_id' => (int)$active_mission['order_id'],
                    'tracking_id' => (int)($active_mission['tracking_id'] ?? 0),
                    'order_number' => $active_mission['order_number'] ?? '',
                    'customer_name' => $active_mission['customer_name'] ?? 'Customer',
                    'customer_phone' => $active_mission['customer_phone'] ?? '',
                    'address' => $active_mission['delivery_address'] ?? '',
                    'lat' => $active_mission['customer_latitude'] ?? null,
                    'lng' => $active_mission['customer_longitude'] ?? null,
                    'status' => $active_mission['current_status'] ?? ''
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                </script>
                <!-- Pinned Active Mission Card (Grab/Shopee Style) -->
                <div class="active-mission-card p-3 p-md-4 mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between pb-3 mb-3 border-bottom border-danger-subtle gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size: 12px; font-weight: 700; padding: 6px 12px;">
                                <i class="fas fa-bolt me-1 text-danger"></i> ACTIVE DELIVERY IN PROGRESS
                            </span>
                            <span class="text-muted small">Assigned Mission</span>
                        </div>
                        <div class="fw-bold fs-5 text-danger">
                            Order #<?php echo htmlspecialchars($active_mission['order_number']); ?>
                        </div>
                    </div>

                    <div class="row g-3 align-items-center">
                        <div class="col-lg-5">
                            <div class="d-flex align-items-start gap-3">
                                <div class="active-mission-icon">
                                    <i class="fas fa-user-circle text-danger"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($active_mission['customer_name'] ?? 'Customer'); ?></h5>
                                    <?php if (!empty($active_mission['customer_phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($active_mission['customer_phone']); ?>" class="btn btn-sm btn-success mt-1" style="font-size: 12px; padding: 3px 10px; border-radius: 8px;">
                                            <i class="fas fa-phone-alt me-1"></i> Call <?php echo htmlspecialchars($active_mission['customer_phone']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="small text-muted mb-1"><i class="fas fa-map-marker-alt text-danger me-1"></i> <strong>Drop-off Destination:</strong></div>
                            <div class="fw-semibold text-dark text-truncate" style="max-width: 100%; font-size: 13.5px;" title="<?php echo htmlspecialchars($active_mission['delivery_address']); ?>">
                                <?php echo htmlspecialchars($active_mission['delivery_address']); ?>
                            </div>
                            <?php if (!empty($active_mission['special_instructions'])): ?>
                                <div class="small text-muted fst-italic mt-1">Note: <?php echo htmlspecialchars($active_mission['special_instructions']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-3 text-lg-end">
                            <div class="d-flex flex-column gap-2">
                                <button type="button" class="btn btn-danger w-100" style="background:#b3261e; border-color:#b3261e; font-weight:600;" onclick="triggerFollowRoute(<?= (int)$active_mission['order_id']; ?>)">
                                    <i class="fas fa-route me-1"></i> Follow Route on Map
                                </button>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm flex-fill" onclick="openRiderChat(<?= (int)$active_mission['order_id']; ?>, '<?= htmlspecialchars(addslashes($active_mission['order_number'])); ?>', '<?= htmlspecialchars(addslashes($active_mission['customer_name'] ?? 'Customer')); ?>', '<?= htmlspecialchars(addslashes($active_mission['customer_phone'] ?? '')); ?>')">
                                        <i class="fas fa-comments me-1"></i> Chat
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" onclick="openDeliveryStatusModal(<?= (int)$active_mission['tracking_id']; ?>, '<?= htmlspecialchars($active_mission['current_status']); ?>', <?= (int)$active_mission['order_id']; ?>)">
                                        <i class="fas fa-edit me-1"></i> Status
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rider Navigation Pills -->
                <div class="rider-nav-pills" role="tablist">
                    <a class="nav-link active" id="tabLinkAvailable" data-bs-toggle="tab" data-bs-target="#availableTab" href="#availableTab">
                        <i class="fas fa-box-open me-2 text-danger"></i>Available Deliveries 
                        <span class="badge rounded-pill bg-danger ms-1"><?php echo count($available_delivery_orders); ?></span>
                    </a>
                    <a class="nav-link" id="tabLinkActive" data-bs-toggle="tab" data-bs-target="#activeTab" href="#activeTab">
                        <i class="fas fa-motorcycle me-2 text-primary"></i>On-Going Deliveries 
                        <span class="badge rounded-pill bg-secondary ms-1"><?php echo count($my_active_delivery_orders); ?></span>
                    </a>
                    <a class="nav-link" id="tabLinkCancelled" data-bs-toggle="tab" data-bs-target="#cancelledTab" href="#cancelledTab">
                        <i class="fas fa-ban me-2 text-danger"></i>Cancelled Deliveries 
                        <span class="badge rounded-pill bg-danger ms-1" style="background:#fee4e2 !important; color:#b3261e !important;"><?php echo count($cancelled_delivery_orders); ?></span>
                    </a>
                    <a class="nav-link" id="tabLinkMap" data-bs-toggle="tab" data-bs-target="#mapView" href="#mapView">
                        <i class="fas fa-map-marked-alt me-2 text-success"></i>Live Route Map & GPS
                    </a>
                    <a class="nav-link" id="tabLinkTable" data-bs-toggle="tab" data-bs-target="#tableView" href="#tableView">
                        <i class="fas fa-history me-2 text-muted"></i>All Deliveries History
                    </a>
                    <a class="nav-link" id="tabLinkFleet" data-bs-toggle="tab" data-bs-target="#fleetView" href="#fleetView">
                        <i class="fas fa-users-cog me-2 text-info"></i>Fleet Riders & COD 
                        <span class="badge rounded-pill bg-dark ms-1"><?php echo count($fleet_riders); ?></span>
                    </a>
                </div>

                <div class="tab-content">
                    <!-- Tab 1: Available Orders -->
                    <div class="tab-pane fade show active" id="availableTab">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold mb-0" style="color:#101828; font-size:1.15rem;">
                                <i class="fas fa-boxes text-danger me-2"></i>Orders Ready for Delivery
                            </h4>
                            <span class="text-muted small">Only showing customer delivery orders</span>
                        </div>

                        <?php if (empty($available_delivery_orders)): ?>
                            <div class="card p-5 text-center border-0 shadow-sm rounded-4">
                                <div class="mb-3">
                                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark">No Pending Delivery Orders</h5>
                                <p class="text-muted small mb-0">You're all caught up! New customer orders with delivery will automatically appear here in real time.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mb-4">
                                <?php foreach ($available_delivery_orders as $ord): 
                                    // 1. Resolve Store Details
                                    $store_name = $ord['store_name'] ?? '';
                                    $store_address = $ord['store_address'] ?? '';
                                    $store_lat = $ord['store_latitude'] ?? null;
                                    $store_lng = $ord['store_longitude'] ?? null;

                                    if (empty($store_name)) {
                                        if (!empty($ord['pickup_location']) && isset($stores_by_id[(int)$ord['pickup_location']])) {
                                            $s_info = $stores_by_id[(int)$ord['pickup_location']];
                                            $store_name = $s_info['store_name'];
                                            $store_address = $s_info['address'] . ', ' . $s_info['city'];
                                            $store_lat = $s_info['latitude'];
                                            $store_lng = $s_info['longitude'];
                                        } elseif (!empty($ord['special_instructions']) && preg_match('/fulfillment store:\s*([^|]+)/i', $ord['special_instructions'], $m_st)) {
                                            $store_name = trim($m_st[1]);
                                            foreach ($stores_by_id as $s_info) {
                                                if (stripos($s_info['store_name'], $store_name) !== false) {
                                                    $store_address = $s_info['address'] . ', ' . $s_info['city'];
                                                    $store_lat = $s_info['latitude'];
                                                    $store_lng = $s_info['longitude'];
                                                    break;
                                                }
                                            }
                                        }
                                        if (empty($store_name) && $default_store) {
                                            $store_name = $default_store['store_name'];
                                            $store_address = $default_store['address'] . ', ' . $default_store['city'];
                                            $store_lat = $default_store['latitude'];
                                            $store_lng = $default_store['longitude'];
                                        }
                                    }

                                    // 2. Resolve Distance between Customer Drop-off and Shop Pick-up
                                    $calculated_km = calculateRiderRouteDistanceKm($store_lat, $store_lng, $ord['customer_latitude'], $ord['customer_longitude']);
                                    if ($calculated_km !== null) {
                                        $distance_label = $calculated_km . ' km';
                                    } elseif (!empty($ord['special_instructions']) && preg_match('/Delivery Distance:\s*([\d\.,]+)\s*km/i', $ord['special_instructions'], $m_dist)) {
                                        $parsed_dist = (float)str_replace(',', '', $m_dist[1]);
                                        if ($parsed_dist > 0 && $parsed_dist < 500) {
                                            $distance_label = round($parsed_dist, 1) . ' km';
                                        } else {
                                            $distance_label = 'Nearby (~2.5 km)';
                                        }
                                    } else {
                                        $distance_label = 'Nearby (~2.5 km)';
                                    }
                                ?>
                                    <script>
                                    window.deliveryOrdersMap = window.deliveryOrdersMap || {};
                                    window.deliveryOrdersMap[<?= (int)$ord['order_id']; ?>] = <?= json_encode([
                                        'order_id' => (int)$ord['order_id'],
                                        'tracking_id' => (int)($ord['tracking_id'] ?? 0),
                                        'order_number' => $ord['order_number'] ?? '',
                                        'customer_name' => $ord['customer_name'] ?? 'Customer',
                                        'customer_phone' => $ord['customer_phone'] ?? '',
                                        'address' => $ord['delivery_address'] ?? '',
                                        'lat' => $ord['customer_latitude'] ?? null,
                                        'lng' => $ord['customer_longitude'] ?? null,
                                        'store_name' => $store_name,
                                        'store_lat' => $store_lat,
                                        'store_lng' => $store_lng,
                                        'distance' => $distance_label,
                                        'status' => $ord['current_status'] ?? 'pending'
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                                    </script>
                                    <div class="col-md-6 col-lg-4" id="order-card-col-<?= (int)$ord['order_id']; ?>">
                                        <div class="rider-order-card" data-order-id="<?= (int)$ord['order_id']; ?>" style="border: 1px solid #eaecf0; border-radius: 14px; padding: 18px; background: #ffffff; box-shadow: 0 1px 3px rgba(16,24,40,0.04); display:flex; flex-direction:column; justify-content:space-between;">
                                            <div>
                                                <!-- Order Header Badge -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11.5px; font-weight:700;">
                                                        #<?php echo htmlspecialchars($ord['order_number']); ?>
                                                    </span>
                                                    <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10.5px; font-weight:700;">
                                                        Ready for Pickup
                                                    </span>
                                                </div>

                                                <!-- Pricing Summary: Order Amount + Delivery Fee -->
                                                <div class="d-flex justify-content-between align-items-center p-2 rounded mb-3" style="background:#f8f9fa; border:1px solid #eaecf0;">
                                                    <div>
                                                        <span class="text-muted d-block" style="font-size:10.5px; text-transform:uppercase; font-weight:700; letter-spacing:0.04em;">Order Amount</span>
                                                        <strong class="text-dark" style="font-size:1.05rem;">₱<?php echo number_format($ord['total_amount'] ?? 0, 2); ?></strong>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="text-muted d-block" style="font-size:10.5px; text-transform:uppercase; font-weight:700; letter-spacing:0.04em;">Delivery Fee</span>
                                                        <strong style="color:#027a48; font-size:1.05rem;">₱<?php echo number_format($ord['delivery_fee'] ?? 0, 2); ?></strong>
                                                    </div>
                                                </div>

                                                <!-- Route Details: Pick-up Shop, Drop-off Customer & Distance -->
                                                <div class="mb-3">
                                                    <!-- Shop Pick-up -->
                                                    <div class="p-2 rounded mb-2" style="background:#ffffff; border:1px solid #eaecf0;">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <span class="small fw-bold text-dark">
                                                                <i class="fas fa-store text-danger me-1"></i> Pick-up Store
                                                            </span>
                                                            <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:10.5px; font-weight:700;">
                                                                <i class="fas fa-road me-1"></i><?php echo htmlspecialchars($distance_label); ?>
                                                            </span>
                                                        </div>
                                                        <div class="small fw-semibold text-dark text-truncate"><?php echo htmlspecialchars($store_name); ?></div>
                                                        <div class="text-muted small text-truncate" style="font-size:11.5px;" title="<?php echo htmlspecialchars($store_address); ?>">
                                                            <?php echo htmlspecialchars($store_address); ?>
                                                        </div>
                                                    </div>

                                                    <!-- Customer Drop-off -->
                                                    <div class="p-2 rounded" style="background:#ffffff; border:1px solid #eaecf0;">
                                                        <div class="small fw-bold text-dark mb-1">
                                                            <i class="fas fa-map-marker-alt text-success me-1"></i> Customer Drop-off
                                                        </div>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="small fw-semibold text-dark text-truncate"><?php echo htmlspecialchars($ord['customer_name'] ?? 'Customer'); ?></span>
                                                            <?php if (!empty($ord['customer_phone'])): ?>
                                                                <a href="tel:<?php echo htmlspecialchars($ord['customer_phone']); ?>" class="small text-success text-decoration-none fw-semibold">
                                                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($ord['customer_phone']); ?>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-muted small text-truncate mt-1" style="font-size:11.5px;" title="<?php echo htmlspecialchars($ord['delivery_address'] ?? 'Customer Address'); ?>">
                                                            <?php echo htmlspecialchars($ord['delivery_address'] ?? 'Customer Address'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div class="pt-2 border-top d-flex gap-2 mt-2">
                                                <button type="button" class="btn btn-primary flex-fill accept-delivery-btn" style="background:#b3261e; border-color:#b3261e; font-weight:700; padding:9px 12px; border-radius:8px;" onclick="triggerAcceptDelivery(<?= (int)$ord['order_id']; ?>)">
                                                    <i class="fas fa-motorcycle me-1"></i> Accept Delivery
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="triggerFollowRoute(<?= (int)$ord['order_id']; ?>)" title="Preview Delivery Location">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 2: My Deliveries (On-Going) -->
                    <div class="tab-pane fade" id="activeTab">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold mb-0" style="color:#101828; font-size:1.15rem;">
                                <i class="fas fa-motorcycle text-primary me-2"></i>On-Going Deliveries
                            </h4>
                            <span class="text-muted small">Deliveries currently active and in-transit</span>
                        </div>

                        <?php if (empty($my_active_delivery_orders)): ?>
                            <div class="card p-5 text-center border-0 shadow-sm rounded-4">
                                <div class="mb-3">
                                    <i class="fas fa-clipboard-check text-muted" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark">No Active Deliveries</h5>
                                <p class="text-muted small mb-0">You do not have any active delivery runs right now. Switch to the <strong>Available Deliveries</strong> tab to accept orders.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mb-4">
                                <?php foreach ($my_active_delivery_orders as $myOrd): ?>
                                    <script>
                                    window.deliveryOrdersMap = window.deliveryOrdersMap || {};
                                    window.deliveryOrdersMap[<?= (int)$myOrd['order_id']; ?>] = <?= json_encode([
                                        'order_id' => (int)$myOrd['order_id'],
                                        'tracking_id' => (int)($myOrd['tracking_id'] ?? 0),
                                        'order_number' => $myOrd['order_number'] ?? '',
                                        'customer_name' => $myOrd['customer_name'] ?? 'Customer',
                                        'customer_phone' => $myOrd['customer_phone'] ?? '',
                                        'address' => $myOrd['delivery_address'] ?? '',
                                        'lat' => $myOrd['customer_latitude'] ?? null,
                                        'lng' => $myOrd['customer_longitude'] ?? null,
                                        'status' => $myOrd['current_status'] ?? 'assigned'
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                                    </script>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="rider-order-card">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px;">
                                                            #<?php echo htmlspecialchars($myOrd['order_number']); ?>
                                                        </span>
                                                        <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:10px; text-transform:capitalize;">
                                                            <?php echo htmlspecialchars(str_replace('_', ' ', $myOrd['current_status'])); ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="fw-bold text-dark">₱<?php echo number_format($myOrd['total_amount'] ?? 0, 2); ?></div>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <div class="fw-semibold text-dark mb-1">
                                                        <i class="fas fa-user me-1 text-muted"></i> <?php echo htmlspecialchars($myOrd['customer_name'] ?? 'Customer'); ?>
                                                    </div>
                                                    <?php if (!empty($myOrd['customer_phone'])): ?>
                                                        <div class="small mb-1">
                                                            <a href="tel:<?php echo htmlspecialchars($myOrd['customer_phone']); ?>" class="text-success text-decoration-none">
                                                                <i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($myOrd['customer_phone']); ?>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="text-muted small text-truncate" style="max-width: 100%;" title="<?php echo htmlspecialchars($myOrd['delivery_address'] ?? 'No address'); ?>">
                                                        <i class="fas fa-map-marker-alt text-danger me-1"></i> <?php echo htmlspecialchars($myOrd['delivery_address'] ?? 'Destination'); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="pt-2 border-top d-flex gap-2">
                                                <?php if ($myOrd['current_status'] === 'arrived_at_restaurant'): ?>
                                                    <button type="button" class="btn btn-success btn-sm fw-bold" onclick="approveRiderHandover(<?= (int)$myOrd['order_id']; ?>)" title="Rider arrived at store! Approve handover">
                                                        <i class="fas fa-check me-1"></i> Approve
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold" onclick="rejectRiderHandover(<?= (int)$myOrd['order_id']; ?>)" title="Reject / Pause Handover">
                                                        <i class="fas fa-times me-1"></i> Reject
                                                    </button>
                                                <?php elseif ($myOrd['current_status'] === 'assigned'): ?>
                                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="openHandoverModal(<?= (int)$myOrd['order_id']; ?>, '<?= htmlspecialchars(addslashes($myOrd['order_number'])); ?>', '<?= htmlspecialchars(addslashes($myOrd['delivery_pin'] ?? '')); ?>', '<?= htmlspecialchars(addslashes($myOrd['customer_name'])); ?>', '')" title="Confirm Rider Pickup Handover">
                                                        <i class="fas fa-handshake me-1"></i> Handover
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-danger btn-sm flex-fill" style="background:#b3261e; border-color:#b3261e; font-weight:600;" onclick="triggerFollowRoute(<?= (int)$myOrd['order_id']; ?>)">
                                                    <i class="fas fa-route me-1"></i> Route
                                                </button>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="openRiderChat(<?= (int)$myOrd['order_id']; ?>, '<?= htmlspecialchars(addslashes($myOrd['order_number'])); ?>', '<?= htmlspecialchars(addslashes($myOrd['customer_name'])); ?>', '<?= htmlspecialchars(addslashes($myOrd['customer_phone'] ?? '')); ?>')" title="Chat with Customer">
                                                    <i class="fas fa-comments"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openDeliveryStatusModal(<?= (int)$myOrd['tracking_id']; ?>, '<?= htmlspecialchars($myOrd['current_status']); ?>', <?= (int)$myOrd['order_id']; ?>)" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab: Cancelled Deliveries -->
                    <div class="tab-pane fade" id="cancelledTab">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold mb-0" style="color:#101828; font-size:1.15rem;">
                                <i class="fas fa-ban text-danger me-2"></i>Cancelled Deliveries
                            </h4>
                            <span class="text-muted small">Orders that were cancelled</span>
                        </div>

                        <?php if (empty($cancelled_delivery_orders)): ?>
                            <div class="card p-5 text-center border-0 shadow-sm rounded-4">
                                <div class="mb-3">
                                    <i class="fas fa-check-circle text-muted" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark">No Cancelled Deliveries</h5>
                                <p class="text-muted small mb-0">There are no cancelled delivery orders on record.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mb-4">
                                <?php foreach ($cancelled_delivery_orders as $cancOrd): ?>
                                    <script>
                                    window.deliveryOrdersMap = window.deliveryOrdersMap || {};
                                    window.deliveryOrdersMap[<?= (int)$cancOrd['order_id']; ?>] = <?= json_encode([
                                        'order_id' => (int)$cancOrd['order_id'],
                                        'tracking_id' => (int)($cancOrd['tracking_id'] ?? 0),
                                        'order_number' => $cancOrd['order_number'] ?? '',
                                        'customer_name' => $cancOrd['customer_name'] ?? 'Customer',
                                        'customer_phone' => $cancOrd['customer_phone'] ?? '',
                                        'address' => $cancOrd['delivery_address'] ?? '',
                                        'lat' => $cancOrd['customer_latitude'] ?? null,
                                        'lng' => $cancOrd['customer_longitude'] ?? null,
                                        'status' => $cancOrd['current_status'] ?? 'cancelled'
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                                    </script>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="rider-order-card" style="border: 1px solid #fee4e2; background: #fffcfc;">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px;">
                                                            #<?php echo htmlspecialchars($cancOrd['order_number']); ?>
                                                        </span>
                                                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:10px;">
                                                            Cancelled
                                                        </span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="fw-bold text-muted text-decoration-line-through">₱<?php echo number_format($cancOrd['total_amount'] ?? 0, 2); ?></div>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <div class="fw-semibold text-dark mb-1">
                                                        <i class="fas fa-user me-1 text-muted"></i> <?php echo htmlspecialchars($cancOrd['customer_name'] ?? 'Customer'); ?>
                                                    </div>
                                                    <?php if (!empty($cancOrd['customer_phone'])): ?>
                                                        <div class="small mb-1">
                                                            <a href="tel:<?php echo htmlspecialchars($cancOrd['customer_phone']); ?>" class="text-muted text-decoration-none">
                                                                <i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($cancOrd['customer_phone']); ?>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="text-muted small text-truncate" style="max-width: 100%;" title="<?php echo htmlspecialchars($cancOrd['delivery_address'] ?? 'No address'); ?>">
                                                        <i class="fas fa-map-marker-alt text-muted me-1"></i> <?php echo htmlspecialchars($cancOrd['delivery_address'] ?? 'Cancelled destination'); ?>
                                                    </div>
                                                    <div class="text-muted small mt-1">
                                                        <i class="fas fa-clock me-1"></i> <?php echo date('M d, Y h:i A', strtotime($cancOrd['updated_at'] ?? $cancOrd['created_at'] ?? 'now')); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="pt-2 border-top d-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="triggerFollowRoute(<?= (int)$cancOrd['order_id']; ?>)">
                                                    <i class="fas fa-map-marker-alt me-1"></i> View Destination
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="openRiderChat(<?= (int)$cancOrd['order_id']; ?>, '<?= htmlspecialchars(addslashes($cancOrd['order_number'])); ?>', '<?= htmlspecialchars(addslashes($cancOrd['customer_name'])); ?>', '<?= htmlspecialchars(addslashes($cancOrd['customer_phone'] ?? '')); ?>')" title="View Chat History">
                                                    <i class="fas fa-comments"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 3: Table View (History & Details) -->
                    <div class="tab-pane fade" id="tableView">
                
                <div class="section-header">
                    <h2>Delivery Overview</h2>
                    <?php if ($seller_scope_id === null): ?>
                        <a href="logistics_settings.php" class="btn btn-outline-primary">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Stats Cards -->
                <div class="dashboard-grid mb-4">
                    <?php
                    // Get statistics
                    $stats_queries = [
                        'pending' => "SELECT COUNT(*) as count FROM logistics_tracking lt JOIN orders o ON lt.order_id = o.id WHERE lt.current_status = 'pending' AND o.delivery_option = 'delivery'" . $partner_logistics_scope_sql,
                        'on_the_way' => "SELECT COUNT(*) as count FROM logistics_tracking lt JOIN orders o ON lt.order_id = o.id WHERE lt.current_status IN ('assigned', 'arrived_at_restaurant', 'picked_up', 'on_the_way', 'arriving') AND o.delivery_option = 'delivery'" . $partner_logistics_scope_sql,
                        'delivered' => "SELECT COUNT(*) as count FROM logistics_tracking lt JOIN orders o ON lt.order_id = o.id WHERE lt.current_status = 'delivered' AND o.delivery_option = 'delivery'" . $partner_logistics_scope_sql,
                        'cancelled' => "SELECT COUNT(*) as count FROM logistics_tracking lt JOIN orders o ON lt.order_id = o.id WHERE lt.current_status = 'cancelled' AND o.delivery_option = 'delivery'" . $partner_logistics_scope_sql
                    ];
                    
                    $stats = [];
                    foreach ($stats_queries as $key => $query) {
                        $result = mysqli_query($conn, $query);
                        if ($result) {
                            $row = mysqli_fetch_assoc($result);
                            $stats[$key] = $row['count'];
                        }
                    }
                    ?>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff3cd;">
                            <i class="fas fa-clock text-warning"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['pending'] ?? 0; ?></h3>
                            <p>Pending</p>
                            <small class="text-muted">Waiting for pickup</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e3f2fd;">
                            <i class="fas fa-truck text-primary"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['on_the_way'] ?? 0; ?></h3>
                            <p>In Transit</p>
                            <small class="text-muted">Out for delivery</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d1e7dd;">
                            <i class="fas fa-check-circle text-success"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['delivered'] ?? 0; ?></h3>
                            <p>Delivered</p>
                            <small class="text-muted">Successfully delivered</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #f8d7da;">
                            <i class="fas fa-times-circle text-danger"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['cancelled'] ?? 0; ?></h3>
                            <p>Cancelled</p>
                            <small class="text-muted">Cancelled deliveries</small>
                        </div>
                    </div>
                </div>
                

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" id="filterForm" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="statusFilter" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="assigned">Assigned</option>
                                    <option value="picked_up">Picked Up</option>
                                    <option value="on_the_way">On the Way</option>
                                    <option value="arriving">Arriving</option>
                                    <option value="delivered">Delivered</option>
                                    <option value="failed">Failed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Provider</label>
                                <select name="provider" id="providerFilter" class="form-select">
                                    <option value="">All Providers</option>
                                    <option value="1">In-House Delivery</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" id="dateFromFilter" class="form-control">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" id="dateToFilter" class="form-control">
                            </div>

                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Deliveries Table -->
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Delivery Destination</th>
                                <th>Driver</th>
                                <th>Status</th>
                                <th>Est. Delivery</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sanitize inputs
                            $allowed_status_filters = array_unique(array_merge($allowed_delivery_statuses, $allowed_preorder_statuses));
                            $status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
                            if ($status_filter !== '' && !in_array($status_filter, $allowed_status_filters, true)) {
                                $status_filter = '';
                            }

                            $provider_filter = isset($_GET['provider']) ? intval($_GET['provider']) : 0;
                            if ($provider_filter <= 0) {
                                $provider_filter = '';
                            }

                            $date_from = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
                            $date_to = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
                            if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                                $date_from = '';
                            }
                            if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                                $date_to = '';
                            }

                            $offset = ($current_page_number - 1) * $records_per_page;

                            // Build WHERE clauses - strictly delivery only
                            $where_orders = "o.delivery_option = 'delivery'" . $partner_logistics_scope_sql;
                            $where_preorders = "po.delivery_method = 'delivery'" . $partner_preorder_scope_sql;
                            
                            if ($status_filter) {
                                $where_orders .= " AND lt.current_status = '$status_filter'";
                                $where_preorders .= " AND po.reservation_status = '$status_filter'";
                            }
                            
                            if ($provider_filter) {
                                $where_orders .= " AND lt.logistics_provider_id = " . intval($provider_filter);
                                if ((int)$provider_filter !== 1) {
                                    $where_preorders .= " AND 0=1"; 
                                }
                            }
                            
                            if ($date_from) {
                                $where_orders .= " AND DATE(lt.created_at) >= '$date_from'";
                                $where_preorders .= " AND DATE(po.created_at) >= '$date_from'";
                            }
                            
                            if ($date_to) {
                                $where_orders .= " AND DATE(lt.created_at) <= '$date_to'";
                                $where_preorders .= " AND DATE(po.created_at) <= '$date_to'";
                            }
                            
                            // Count total records for pagination
                            $count_query = "
                                SELECT SUM(total) as total_records FROM (
                                    SELECT COUNT(*) as total 
                                    FROM logistics_tracking lt
                                    JOIN orders o ON lt.order_id = o.id
                                    WHERE $where_orders
                                    
                                    UNION ALL
                                    
                                    SELECT COUNT(*) as total 
                                    FROM pre_orders po
                                    WHERE $where_preorders
                                ) as combined_counts
                            ";
                            $count_result = mysqli_query($conn, $count_query);
                            $total_records = mysqli_fetch_assoc($count_result)['total_records'] ?? 0;
                            $total_pages = max(1, ceil($total_records / $records_per_page));

                            // Main query: only delivery orders
                            $limit_for_sub = $offset + $records_per_page;
                            
                            $query = "
                            (SELECT 
                                    lt.id as id,
                                    lt.order_id as order_id,
                                    o.order_number as ref_number,
                                    COALESCE(NULLIF(TRIM(o.customer_name), ''), u.full_name, 'Customer') as customer_name,
                                    COALESCE(NULLIF(TRIM(o.customer_phone), ''), u.phone, '') as customer_phone,
                                    o.delivery_address,
                                    o.latitude as customer_latitude,
                                    o.longitude as customer_longitude,
                                    o.special_instructions,
                                    'Order' as type,
                                    'Delivery' as delivery_type,
                                    lp.provider_name,
                                    lt.driver_id,
                                    COALESCE(NULLIF(TRIM(lt.driver_name), ''), 'Unassigned') as driver_name,
                                    lt.current_status as status,
                                    lt.pickup_time,
                                    lt.estimated_delivery,
                                    lt.proof_of_delivery_path,
                                    lt.created_at,
                                    'logistics' as source
                                FROM logistics_tracking lt
                                JOIN orders o ON lt.order_id = o.id
                                LEFT JOIN users u ON o.user_id = u.id
                                LEFT JOIN logistics_providers lp ON lt.logistics_provider_id = lp.id
                                WHERE $where_orders
                                ORDER BY lt.created_at DESC
                                LIMIT $limit_for_sub)
                            
                                UNION ALL

                                (SELECT 
                                    po.id as id,
                                    NULL as order_id,
                                    CONCAT('PO-', po.id) as ref_number,
                                    u.full_name as customer_name,
                                    u.phone as customer_phone,
                                    po.delivery_address,
                                    NULL as customer_latitude,
                                    NULL as customer_longitude,
                                    po.special_instructions,
                                    'Pre-Order' as type,
                                    'Delivery' as delivery_type,
                                    'In-House' as provider_name,
                                    NULL as driver_id,
                                    'Unassigned' as driver_name,
                                    po.reservation_status as status,
                                    CONCAT(po.preferred_pickup_date, ' ', po.preferred_pickup_time) as pickup_time,
                                    NULL as estimated_delivery,
                                    NULL as proof_of_delivery_path,
                                    po.created_at,
                                    'preorder' as source
                                FROM pre_orders po
                                LEFT JOIN users u ON po.user_id = u.id
                                WHERE $where_preorders
                                ORDER BY po.created_at DESC
                                LIMIT $limit_for_sub)

                                ORDER BY created_at DESC
                                LIMIT $records_per_page OFFSET $offset
                            ";
                            
                            $result = mysqli_query($conn, $query);
                            
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $status_class = 'badge-' . ($row['status'] == 'delivered' || $row['status'] == 'completed' || $row['status'] == 'confirmed' ? 'success' : ($row['status'] == 'cancelled' || $row['status'] == 'failed' ? 'danger' : 'warning'));
                                    $pickup_time = $row['pickup_time'] ? date('M d, H:i', strtotime($row['pickup_time'])) : '-';
                                    $est_delivery = $row['estimated_delivery'] ? date('M d, H:i', strtotime($row['estimated_delivery'])) : '-';
                                    
                                    $customer_payload = [
                                        'tracking_id' => (int)$row['id'],
                                        'order_id' => (int)($row['order_id'] ?? 0),
                                        'order_number' => $row['ref_number'],
                                        'customer_name' => $row['customer_name'] ?? 'Customer',
                                        'customer_phone' => $row['customer_phone'] ?? '',
                                        'address' => $row['delivery_address'] ?? '',
                                        'lat' => $row['customer_latitude'] !== null ? (float)$row['customer_latitude'] : null,
                                        'lng' => $row['customer_longitude'] !== null ? (float)$row['customer_longitude'] : null,
                                        'driver_id' => $row['driver_id'] ? (int)$row['driver_id'] : null,
                                        'driver_name' => $row['driver_name'] ?? 'Unassigned',
                                        'status' => $row['status']
                                    ];
                                    ?>
                                    <tr id="logistics-row-<?php echo $row['source'] . '-' . $row['id']; ?>">
                                        <td>
                                            <strong>#<?php echo htmlspecialchars($row['ref_number']); ?></strong>
                                            <?php if ($row['type'] === 'Pre-Order'): ?>
                                                <span class="badge bg-warning text-dark ms-1">Pre-Order</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></div>
                                            <?php if (!empty($row['customer_phone'])): ?>
                                                <small class="text-muted"><a href="tel:<?php echo htmlspecialchars($row['customer_phone']); ?>" class="text-decoration-none text-muted"><i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($row['customer_phone']); ?></a></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 260px;">
                                            <div class="small text-truncate" title="<?php echo htmlspecialchars($row['delivery_address'] ?? 'No address'); ?>">
                                                <i class="fas fa-map-marker-alt text-danger me-1"></i><?php echo htmlspecialchars($row['delivery_address'] ?? 'No address specified'); ?>
                                            </div>
                                            <?php if (!empty($row['customer_latitude']) || !empty($row['delivery_address'])): ?>
                                                <a href="javascript:void(0)" class="small text-primary text-decoration-none d-inline-block mt-1" onclick='focusCustomerOnMap(<?php echo htmlspecialchars(json_encode($customer_payload), ENT_QUOTES, "UTF-8"); ?>)'>
                                                    <i class="fas fa-crosshairs me-1"></i>View on Map
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="driver-name">
                                            <?php if ($row['driver_name'] === 'Unassigned'): ?>
                                                <span class="badge bg-light text-secondary border">Unassigned</span>
                                            <?php else: ?>
                                                <span class="fw-semibold text-dark"><i class="fas fa-motorcycle text-danger me-1"></i><?php echo htmlspecialchars($row['driver_name']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo ucwords(str_replace('_', ' ', $row['status'])); ?></span>
                                            <?php if ($row['proof_of_delivery_path']): ?>
                                                <a href="../uploads/<?php echo htmlspecialchars($row['proof_of_delivery_path']); ?>" target="_blank" class="d-block small mt-1"><i class="fas fa-camera"></i> View Proof</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $est_delivery; ?></td>
                                        <td class="actions-cell">
                                            <?php if ($row['source'] === 'logistics'): ?>
                                                <?php if ($row['status'] === 'pending' || $row['driver_name'] === 'Unassigned'): ?>
                                                    <button type="button" class="btn btn-sm btn-success accept-delivery-btn me-1" onclick='acceptDelivery(<?php echo (int)$row['id']; ?>, <?php echo htmlspecialchars(json_encode($customer_payload), ENT_QUOTES, "UTF-8"); ?>)' title="Accept Delivery">
                                                        <i class="fas fa-motorcycle me-1"></i>Accept
                                                    </button>
                                                    <button class="btn-icon" title="Assign Driver" onclick="openAssignDriverModal(<?php echo (int)$row['id']; ?>, <?php echo (int)($row['order_id'] ?? 0); ?>)">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>
                                                <?php elseif (in_array($row['status'], ['assigned', 'picked_up', 'on_the_way', 'arriving'], true)): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger me-1" onclick='focusCustomerOnMap(<?php echo htmlspecialchars(json_encode($customer_payload), ENT_QUOTES, "UTF-8"); ?>)' title="View Customer Location on Map">
                                                        <i class="fas fa-map-marker-alt me-1"></i>Location
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn-icon" title="View Details" onclick="viewDeliveryDetails(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn-icon" title="Update Status" onclick="editDeliveryStatus(<?php echo $row['id']; ?>, '<?php echo $row['status']; ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if (!in_array($row['status'], ['delivered', 'cancelled', 'failed'])): ?>
                                                    <button onclick="cancelDelivery(<?php echo $row['id']; ?>)" class="btn-icon btn-icon-danger" title="Cancel">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: // Pre-order actions ?>
                                                <button class="btn-icon" onclick="showUpdatePreOrderStatus(<?php echo $row['id']; ?>)" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-icon" data-bs-toggle="modal" data-bs-target="#preorderDetailsModal" onclick="loadPreOrderDetails(<?php echo $row['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fas fa-truck-loading fa-2x mb-2 d-block text-secondary"></i>
                                        No delivery orders found matching your criteria.
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div class="pagination-container">
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <?php if ($current_page_number > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number - 1; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page_number < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number + 1; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
                    </div>
                    <div class="tab-pane fade" id="mapView" style="position: relative;">
                        <!-- Floating Rider Map HUD (Real-time Route Details) -->
                        <div class="rider-map-hud" id="riderMapHud" style="display: none; position: relative; margin-bottom: 16px;">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="active-mission-icon">
                                        <i class="fas fa-motorcycle text-danger"></i>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:11px;">
                                                <i class="fas fa-route me-1"></i> ACTIVE NAVIGATION ROUTE
                                            </span>
                                            <strong class="text-dark" id="hudOrderNumber">#ORD</strong>
                                        </div>
                                        <div class="text-truncate mt-1" style="max-width: 320px; font-size: 13px; color: #475467;" id="hudCustomerAddress">
                                            <i class="fas fa-map-marker-alt text-danger me-1"></i> Destination drop-off
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-4 flex-wrap ms-auto">
                                    <div class="text-center">
                                        <div class="hud-stat-label">Road Distance</div>
                                        <div class="hud-stat-val text-danger" id="hudDistance">Calculating...</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="hud-stat-label">Estimated Time</div>
                                        <div class="hud-stat-val text-success" id="hudEta">Calculating...</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="centerOnDriver()" title="Center on My Location">
                                            <i class="fas fa-crosshairs me-1"></i> Rider
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="centerOnCustomer()" title="Center on Customer Drop-off">
                                            <i class="fas fa-flag-checkered me-1"></i> Drop-off
                                        </button>
                                        <a href="#" id="hudExternalMapsBtn" target="_blank" rel="noopener" class="btn btn-sm btn-primary" style="background:#b3261e; border-color:#b3261e;">
                                            <i class="fas fa-directions me-1"></i> Google Maps
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 p-3 bg-white border rounded-3 shadow-sm" style="border-color: #eaecf0 !important;">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge" id="gpsLiveBadge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; padding: 6px 10px; font-size: 13px;">
                                    <i class="fas fa-satellite-dish me-1"></i> Live Moving Tracker Active
                                </span>
                                <span class="text-muted small" id="gpsLastPing">Auto-refreshing every 3.5s</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fetchDriverLocations(true)">
                                    <i class="fas fa-sync-alt me-1"></i> Refresh Map
                                </button>
                                <button type="button" class="btn btn-sm" id="toggleGpsBroadcastBtn" onclick="toggleGpsBroadcast()" style="background:#b3261e; color:#ffffff;">
                                    <i class="fas fa-location-arrow me-1"></i> <span id="gpsBroadcastText">Broadcast Device GPS</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="fitAllMapBounds()">
                                    <i class="fas fa-compress-arrows-alt me-1"></i> Center All
                                </button>
                            </div>
                        </div>
                        <div id="logisticsMap" style="height: 70vh; width: 100%; border-radius: 12px; border: 1px solid #eaecf0; box-shadow: 0 2px 10px rgba(0,0,0,0.06);"></div>
                    </div>

                    <!-- Tab 5: Fleet Riders, COD Remittances & Support Tickets -->
                    <div class="tab-pane fade" id="fleetView">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold mb-0" style="color:#101828; font-size:1.15rem;">
                                <i class="fas fa-users-cog text-primary me-2"></i>Delivery Fleet & Cash Management
                            </h4>
                            <a href="../rider/login.php" target="_blank" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-external-link-alt me-1"></i> Open Rider App View
                            </a>
                        </div>

                        <!-- 1. Active Fleet Riders -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1px solid #eaecf0 !important;">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><i class="fas fa-motorcycle text-danger me-2"></i>Registered Delivery Riders (<?php echo count($fleet_riders); ?>)</h6>
                                <span class="badge bg-light text-dark">Shop & Platform Drivers</span>
                            </div>
                            <div class="table-responsive">
                                <table class="admin-table align-middle mb-0" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Rider</th>
                                            <th>Contact / Vehicle</th>
                                            <th>Fleet Type</th>
                                            <th>Duty Status</th>
                                            <th>Rating</th>
                                            <th>Deliveries</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($fleet_riders)): foreach ($fleet_riders as $fr): 
                                            $duty_badge = 'bg-secondary';
                                            if ($fr['duty_status'] === 'online') $duty_badge = 'bg-success';
                                            elseif ($fr['duty_status'] === 'busy') $duty_badge = 'bg-danger';
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($fr['full_name']); ?></strong>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($fr['rider_code']); ?></div>
                                                </td>
                                                <td>
                                                    <div><i class="fas fa-phone-alt text-muted me-1"></i><?php echo htmlspecialchars($fr['user_phone'] ?? 'N/A'); ?></div>
                                                    <div class="small text-muted"><i class="fas fa-biking text-muted me-1"></i><?php echo htmlspecialchars($fr['vehicle_type']); ?> <?php echo !empty($fr['vehicle_plate']) ? '(' . htmlspecialchars($fr['vehicle_plate']) . ')' : ''; ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff;">
                                                        <?php echo ($fr['rider_type'] === 'shop_rider') ? 'Shop: ' . htmlspecialchars($fr['store_name'] ?? 'Branch') : 'Platform Partner'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $duty_badge; ?>" style="text-transform: uppercase;">
                                                        <?php echo htmlspecialchars($fr['duty_status']); ?>
                                                    </span>
                                                    <?php if (!empty($fr['last_location_update'])): ?>
                                                        <div class="text-muted" style="font-size: 10px;">Ping: <?php echo date('h:i A', strtotime($fr['last_location_update'])); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><i class="fas fa-star text-warning me-1"></i><?php echo number_format((float)$fr['rating'], 1); ?></span>
                                                </td>
                                                <td>
                                                    <strong class="text-dark"><?php echo (int)$fr['total_completed_deliveries']; ?></strong> completed
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">No registered riders found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- 2. COD Remittance Verification -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1px solid #eaecf0 !important;">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><i class="fas fa-money-bill-wave text-success me-2"></i>COD Cash Remittances</h6>
                                <span class="badge bg-warning text-dark">Pending Cash Handover</span>
                            </div>
                            <div class="table-responsive">
                                <table class="admin-table align-middle mb-0" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Rider</th>
                                            <th>Collected Amount</th>
                                            <th>Remittance Status</th>
                                            <th>Deposit Ref</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($admin_cod_collections)): foreach ($admin_cod_collections as $cc): ?>
                                            <tr>
                                                <td><strong>#<?php echo htmlspecialchars($cc['order_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($cc['rider_name']); ?> (<?php echo htmlspecialchars($cc['rider_code']); ?>)</td>
                                                <td><strong class="text-success">₱<?php echo number_format($cc['cash_received'], 2); ?></strong></td>
                                                <td>
                                                    <span class="badge <?php echo $cc['remittance_status'] === 'verified' ? 'bg-success' : ($cc['remittance_status'] === 'remitted' ? 'bg-info' : 'bg-warning text-dark'); ?>">
                                                        <?php echo strtoupper($cc['remittance_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($cc['remittance_reference'] ?? 'Not remitted yet'); ?></td>
                                                <td>
                                                    <?php if ($cc['remittance_status'] !== 'verified'): ?>
                                                        <form method="POST" action="logistics.php#fleetView" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="verify_cod_id" value="<?php echo (int)$cc['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-success" style="font-size: 11px;">
                                                                <i class="fas fa-check-circle me-1"></i> Approve Remittance
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-success small fw-semibold"><i class="fas fa-check-double me-1"></i> Verified</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">No COD transactions recorded yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- 3. Rider Support & Exception Tickets -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1px solid #eaecf0 !important;">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><i class="fas fa-headset text-danger me-2"></i>Rider Road Exception & Support Tickets</h6>
                                <span class="badge bg-danger">Emergency Desk</span>
                            </div>
                            <div class="table-responsive">
                                <table class="admin-table align-middle mb-0" style="font-size: 13px;">
                                    <thead>
                                        <tr>
                                            <th>Ticket #</th>
                                            <th>Rider</th>
                                            <th>Category</th>
                                            <th>Issue Details</th>
                                            <th>Status</th>
                                            <th>Respond</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($admin_tickets)): foreach ($admin_tickets as $t): ?>
                                            <tr>
                                                <td><strong>#<?php echo $t['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($t['rider_name']); ?></td>
                                                <td><span class="badge bg-light text-dark border"><?php echo ucwords(str_replace('_', ' ', $t['issue_category'])); ?></span></td>
                                                <td style="max-width: 280px;">
                                                    <div><?php echo htmlspecialchars($t['description']); ?></div>
                                                    <?php if (!empty($t['admin_response'])): ?>
                                                        <small class="text-primary d-block mt-1"><strong>Reply:</strong> <?php echo htmlspecialchars($t['admin_response']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $t['status'] === 'resolved' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                        <?php echo strtoupper($t['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" style="font-size: 11px;" onclick="promptTicketReply(<?php echo (int)$t['id']; ?>)">
                                                        <i class="fas fa-reply me-1"></i> Respond
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">No open support tickets.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delivery Details Modal (NEW) -->
    <div class="modal fade" id="deliveryDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delivery Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="deliveryDetailsContent">
                    <div class="text-center p-3">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Delivery Status Modal (NEW) -->
    <div class="modal fade" id="deliveryStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Delivery Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="updateDeliveryStatusForm">
                        <input type="hidden" name="update_delivery_status" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="tracking_id" id="editDeliveryId">
                        <input type="hidden" name="order_id" id="editDeliveryOrderId" value="0">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="new_status" id="editDeliveryStatus" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="assigned">Assigned</option>
                                <option value="picked_up">Picked Up</option>
                                <option value="on_the_way">On the Way</option>
                                <option value="arriving">Arriving</option>
                                <option value="delivered">Delivered</option>
                                <option value="failed">Failed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="updateDeliveryStatusForm" class="btn btn-primary" style="background:#b3261e; border-color:#b3261e;">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rider Chat Modal with Customer (NEW) -->
    <div class="modal fade" id="riderChatModal" tabindex="-1" aria-labelledby="riderChatModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid #eaecf0; box-shadow: 0 10px 30px rgba(0,0,0,0.12); overflow: hidden;">
                <div class="modal-header" style="background: #101828; color: #ffffff; padding: 14px 18px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #ffffff;">
                            <i class="fas fa-comments"></i>
                        </div>
                        <div>
                            <h6 class="modal-title mb-0 fw-bold" id="riderChatCustomerName" style="color: #ffffff; font-size: 15px;">Customer Chat</h6>
                            <small class="text-light" style="opacity: 0.8; font-size: 11px;" id="riderChatOrderSubtitle">Order #</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="#" id="riderChatPhoneBtn" class="btn btn-sm btn-success" style="padding: 4px 10px; font-size: 12px; border-radius: 8px;">
                            <i class="fas fa-phone-alt me-1"></i> Call
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-3" style="background: #f8f9fa; height: 380px; display: flex; flex-direction: column;">
                    <div id="riderChatMessages" style="flex: 1; overflow-y: auto; padding: 8px 4px; display: flex; flex-direction: column; gap: 8px;">
                        <div class="text-center text-muted small py-4">
                            <i class="fas fa-spinner fa-spin me-1"></i> Loading messages...
                        </div>
                    </div>
                    <div class="pt-2">
                        <form id="riderChatForm" onsubmit="sendRiderChatMessage(event)" class="d-flex gap-2">
                            <input type="text" id="riderChatInput" class="form-control" placeholder="Type a message to customer..." autocomplete="off" style="border-radius: 10px; font-size: 13.5px;" required>
                            <button type="submit" class="btn" style="background: #b3261e; color: #ffffff; border-radius: 10px; font-weight: 600; padding: 0 16px;">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Pre-Order Status Modal -->
    <div class="modal fade" id="preorderStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Pre-Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="updatePreOrderStatusForm">
                        <input type="hidden" id="preOrderId" name="pre_order_id">
                        <input type="hidden" name="update_preorder_status" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label class="form-label"><strong>New Status</strong></label>
                            <select name="new_status" class="form-select" required>
                                <option value="">-- Select Status --</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="in_preparation">In Preparation</option>
                                <option value="ready_for_pickup">Ready for Pickup</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea name="admin_notes" class="form-control" rows="4" placeholder="Add any notes about this status change..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="updatePreOrderStatusForm" class="btn btn-primary">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pre-Order Details Modal -->
    <div class="modal fade" id="preorderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pre-Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="preOrderDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Driver Modal -->
    <div class="modal fade" id="assignDriverModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Driver to Delivery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="assignDriverForm">
                    <div class="modal-body">
                        <input type="hidden" name="tracking_id" id="assignTrackingId">
                        <input type="hidden" id="assignOrderId">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label for="driverSelect" class="form-label">Select Driver</label>
                            <select name="employee_id" id="driverSelect" class="form-select" required>
                                <option value="">-- Loading eligible riders... --</option>
                            </select>
                            <div class="form-text">Only riders with attendance on this order's delivery date are shown.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign Driver</button>
                    </div>
                </form>
            </div>
        </div>
    <!-- Pickup Handover Verification Modal -->
    <div class="modal fade" id="pickupHandoverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid #eaecf0; box-shadow: 0 10px 25px rgba(0,0,0,0.08); overflow: hidden;">
                <div class="modal-header border-bottom py-3" style="background: #ffffff;">
                    <h5 class="modal-title fw-bold mb-0" style="color: #101828; font-size: 1.05rem;">
                        <i class="fas fa-handshake text-success me-2"></i> Confirm Rider Pickup Handover
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="handoverOrderId" value="">

                    <div class="p-3 rounded mb-3" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small">Order Number:</span>
                            <strong class="text-dark" id="modalHandoverOrderNum"></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small">Customer Name:</span>
                            <span class="fw-semibold text-dark" id="modalHandoverCustName">Customer</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">Order Delivery PIN:</span>
                            <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size: 13px;" id="modalHandoverPinBadge">----</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Enter Confirmation Code or Delivery PIN
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-shield-alt text-muted"></i></span>
                            <input type="text" id="handoverCodeInput" class="form-control form-control-lg fw-bold text-center" placeholder="e.g. 7794 or RDR-0011" style="letter-spacing: 2px;">
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted" style="font-size: 11.5px;">Ask the rider for their PIN or Rider ID code.</small>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" style="font-size: 11.5px; color: #b3261e;" onclick="autofillExpectedPin()">
                                <i class="fas fa-magic me-1"></i> Auto-fill PIN
                            </button>
                        </div>
                    </div>

                    <button type="button" class="btn w-100 py-2 fw-bold text-white" id="confirmHandoverBtn" style="background: #027a48; border-color: #027a48; border-radius: 8px;" onclick="submitPickupHandover()">
                        <i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Theme Toggler
    const themeToggler = document.getElementById('themeToggler');
    const body = document.body;
    const icon = themeToggler ? themeToggler.querySelector('i') : null;
    const logisticsCsrfToken = <?php echo json_encode($csrf_token); ?>;

    if (themeToggler && icon) {
        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });
    }

// Session Messages
<?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: '<?php echo htmlspecialchars($success); ?>',
        timer: 2000,
        showConfirmButton: false
    });
<?php endif; ?>

<?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?php echo htmlspecialchars($error); ?>'
    });
<?php endif; ?>

function cancelDelivery(trackingId) {
    Swal.fire({
        title: 'Cancel Delivery?',
        text: "Are you sure you want to cancel this delivery?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('cancel_delivery.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: 'tracking_id=' + trackingId + '&reason=Admin cancelled&csrf_token=' + encodeURIComponent(logisticsCsrfToken)
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', 'Delivery cancelled successfully.', 'success');
                    const row = document.getElementById('logistics-row-logistics-' + trackingId);
                    if (row) {
                        row.querySelector('.status-badge').textContent = 'Cancelled';
                        row.querySelector('.actions-cell').innerHTML = '<span class="text-muted">-</span>';
                    }
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
            }
    })
}

const preorderStatusModal = new bootstrap.Modal(document.getElementById('preorderStatusModal'));

function showUpdatePreOrderStatus(id) {
    document.getElementById('preOrderId').value = id;
    preorderStatusModal.show();
}

function viewDeliveryDetails(id) {
    var modal = new bootstrap.Modal(document.getElementById('deliveryDetailsModal'));
    modal.show();
    $('#deliveryDetailsContent').html('<div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div></div>');
    $.get('get_logistics_details.php?id=' + id, function(data) {
        $('#deliveryDetailsContent').html(data);
    }).fail(function() {
        $('#deliveryDetailsContent').html('<div class="alert alert-danger">Failed to load details.</div>');
    });
}

function editDeliveryStatus(id, currentStatus, orderId = 0) {
    $('#editDeliveryId').val(id || 0);
    $('#editDeliveryOrderId').val(orderId || 0);
    $('#editDeliveryStatus').val(currentStatus);
    var modalEl = document.getElementById('deliveryStatusModal');
    if (modalEl) {
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

window.openDeliveryStatusModal = function(id, currentStatus, orderId = 0) {
    editDeliveryStatus(id, currentStatus, orderId);
};

function loadPreOrderDetails(id) {
    const container = document.getElementById('preOrderDetails');
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading details...</p></div>';
    
    fetch('get_preorder_details.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            container.innerHTML = html;
        });
}

const assignDriverModal = new bootstrap.Modal(document.getElementById('assignDriverModal'));
function loadEligibleDrivers(orderId) {
    const driverSelect = document.getElementById('driverSelect');
    if (!driverSelect) return Promise.resolve();

    if (!orderId) {
        driverSelect.innerHTML = '<option value="">-- Invalid order --</option>';
        return Promise.resolve();
    }

    driverSelect.innerHTML = '<option value="">Loading eligible riders...</option>';
    return fetch(`../api/get_available_drivers.php?order_id=${encodeURIComponent(orderId)}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (!Array.isArray(data)) {
            const err = (data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to load available drivers.';
            throw new Error(err);
        }

        if (data.length === 0) {
            driverSelect.innerHTML = '<option value="">-- No eligible riders with attendance for this delivery date --</option>';
            return;
        }

        driverSelect.innerHTML = '<option value="">-- Choose a rider --</option>';
        data.forEach(driver => {
            const option = document.createElement('option');
            option.value = String(driver.id);
            option.textContent = `${driver.first_name} ${driver.last_name} (${(driver.distance_km || 0).toFixed(1)} km, Rating ${(driver.avg_rating || 0).toFixed(1)})`;
            driverSelect.appendChild(option);
        });
    })
    .catch(error => {
        driverSelect.innerHTML = '<option value="">-- Unable to load riders --</option>';
        Swal.fire('Error', error.message || 'Failed to load eligible riders.', 'error');
    });
}

function openAssignDriverModal(trackingId, orderId) {
    document.getElementById('assignTrackingId').value = trackingId;
    document.getElementById('assignOrderId').value = orderId || '';
    loadEligibleDrivers(orderId).finally(() => {
        assignDriverModal.show();
    });
}

document.getElementById('assignDriverForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const trackingId = formData.get('tracking_id');

    fetch('assign_driver.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const assignModal = bootstrap.Modal.getInstance(document.getElementById('assignDriverModal'));
        if (assignModal) assignModal.hide();

        if (data.success) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message, showConfirmButton: false, timer: 2000 });

            const row = document.getElementById('logistics-row-logistics-' + trackingId);
            if (row) {
                row.querySelector('.driver-name').textContent = data.driver_name;
                const statusCell = row.querySelector('.status-badge');
                statusCell.className = 'status-badge badge-warning';
                statusCell.textContent = 'Assigned';
                
                // Keep button to allow re-assignment
                // const assignBtn = row.querySelector('button[onclick*="openAssignDriverModal"]');
                // if (assignBtn) assignBtn.remove();
            }
            
            // Reload page to update "Available Drivers" widget and sync state
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(error => Swal.fire('Error', 'An unexpected error occurred.', 'error'));
});

document.getElementById('updatePreOrderStatusForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const preOrderId = formData.get('pre_order_id');

    fetch('ajax_update_preorder.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('preorderStatusModal'));
        if (modal) modal.hide();
        Swal.fire({ toast: true, position: 'top-end', icon: data.success ? 'success' : 'error', title: data.message, showConfirmButton: false, timer: 2000 });
        if (data.success) {
            const row = document.getElementById('logistics-row-preorder-' + preOrderId);
            if (row) row.querySelector('.status-badge').textContent = data.new_status;
        }
    });
});
</script>
<!-- Leaflet JS Library CDN -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
let logisticsMap = null;
const activeDriverMarkers = new Map();
const activeCustomerMarkers = new Map();
const activeRoutePolylines = new Map();
let locationRefreshTimer = null;
let hasAutoFittedMap = false;

// GPS Broadcasting State
let gpsWatcherId = null;
let isGpsBroadcasting = false;
let lastGpsLat = null;
let lastGpsLng = null;
let lastGpsTime = 0;

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function parseCoordinate(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatTimestamp(value) {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'N/A';
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function computeDistanceKm(a, b) {
    if (!a || !b) return null;
    const toRad = (deg) => deg * (Math.PI / 180);
    const lat1 = toRad(a.lat);
    const lon1 = toRad(a.lng);
    const lat2 = toRad(b.lat);
    const lon2 = toRad(b.lng);
    const dLat = lat2 - lat1;
    const dLon = lon2 - lon1;
    const h = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    const earthRadiusKm = 6371;
    return earthRadiusKm * c;
}

function formatEta(estimatedDelivery, distanceKm = null) {
    if (estimatedDelivery) {
        const etaDate = new Date(estimatedDelivery);
        if (!Number.isNaN(etaDate.getTime())) {
            const diffMs = etaDate.getTime() - Date.now();
            const diffMins = Math.round(diffMs / 60000);
            if (diffMins > 0) {
                return `${diffMins} min`;
            }
            return 'Arriving soon';
        }
    }

    if (distanceKm !== null) {
        const estimatedMins = Math.max(3, Math.round((distanceKm / 20) * 60));
        return `~${estimatedMins} min`;
    }

    return 'Calculating...';
}

function initLogisticsMap() {
    if (typeof L === 'undefined') {
        console.warn("Leaflet library not loaded yet.");
        return;
    }

    const mapContainer = document.getElementById('logisticsMap');
    if (!mapContainer) return;

    if (logisticsMap) {
        setTimeout(() => logisticsMap.invalidateSize(), 200);
        return;
    }

    // Default center on Cavite/Metro Manila
    logisticsMap = L.map('logisticsMap', {
        center: [14.3294, 120.9367],
        zoom: 12
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
    }).addTo(logisticsMap);

    fetchDriverLocations();

    // Smooth real-time update every 3.5 seconds
    if (!locationRefreshTimer) {
        locationRefreshTimer = setInterval(() => fetchDriverLocations(false), 3500);
    }
}

function fetchDriverLocations(isManualRefresh = false) {
    if (!logisticsMap || typeof L === 'undefined') return;

    fetch('get_driver_locations.php')
        .then(response => response.json())
        .then(data => {
            if (!(data.success && Array.isArray(data.locations))) {
                return;
            }

            const currentTrackingIds = new Set();
            const currentDriverIds = new Set();
            const boundsPoints = [];

            data.locations.forEach(loc => {
                const driverLat = parseCoordinate(loc.current_latitude);
                const driverLng = parseCoordinate(loc.current_longitude);
                if (driverLat === null || driverLng === null) return;

                boundsPoints.push([driverLat, driverLng]);
                const driverId = loc.driver_id || ('driver_' + loc.tracking_id);
                currentDriverIds.add(driverId);
                currentTrackingIds.add(loc.tracking_id);

                const statusLabel = (loc.current_status || '').toString().replace(/_/g, ' ') || 'assigned';
                const customerLat = parseCoordinate(loc.customer_latitude);
                const customerLng = parseCoordinate(loc.customer_longitude);
                const hasCustomerCoords = customerLat !== null && customerLng !== null;

                if (hasCustomerCoords) {
                    boundsPoints.push([customerLat, customerLng]);
                }

                const distanceKm = hasCustomerCoords ? computeDistanceKm({lat: driverLat, lng: driverLng}, {lat: customerLat, lng: customerLng}) : null;
                const etaLabel = formatEta(loc.estimated_delivery, distanceKm);
                const distanceLabel = distanceKm !== null ? `${distanceKm.toFixed(2)} km` : 'N/A';

                const driverPopupContent = `
                    <div style="font-family: inherit; font-size: 13px; line-height: 1.5; min-width: 220px; max-width: 280px;">
                        <div style="font-weight: 700; color: #b3261e; font-size: 15px; margin-bottom: 6px;">
                            <i class="fas fa-motorcycle me-1"></i> ${escapeHtml(loc.driver_name || 'Driver')}
                        </div>
                        <div style="margin-bottom: 3px;"><strong>Order:</strong> #${escapeHtml(loc.order_number || 'N/A')}</div>
                        <div style="margin-bottom: 3px;"><strong>Status:</strong> <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; text-transform:capitalize; font-size: 11px;">${escapeHtml(statusLabel)}</span></div>
                        <div style="margin-bottom: 3px;"><strong>ETA:</strong> ${escapeHtml(etaLabel)}</div>
                        <div style="margin-bottom: 3px;"><strong>Distance:</strong> ${escapeHtml(distanceLabel)}</div>
                        <div style="font-size: 12px; color: #475467; margin-top: 6px; border-top: 1px solid #e2e8f0; padding-top: 4px;">
                            <strong>Destination:</strong> ${escapeHtml(loc.delivery_address || 'N/A')}
                        </div>
                        <div style="font-size: 11px; color: #94a3b8; margin-top: 4px;">Updated: ${escapeHtml(formatTimestamp(loc.last_location_update))}</div>
                    </div>`;

                // Update or create driver marker smoothly in-place
                if (activeDriverMarkers.has(driverId)) {
                    const existingMarker = activeDriverMarkers.get(driverId);
                    existingMarker.setLatLng([driverLat, driverLng]);
                    existingMarker.setPopupContent(driverPopupContent);
                } else {
                    const driverIcon = L.divIcon({
                        className: 'custom-leaflet-driver',
                        html: `<div class="driver-leaflet-marker" title="${escapeHtml(loc.driver_name || 'Driver')}"><i class="fas fa-motorcycle"></i></div>`,
                        iconSize: [38, 38],
                        iconAnchor: [19, 19]
                    });
                    const newMarker = L.marker([driverLat, driverLng], { icon: driverIcon }).addTo(logisticsMap);
                    newMarker.bindPopup(driverPopupContent);
                    activeDriverMarkers.set(driverId, newMarker);
                }

                // Update or create customer destination marker
                const customerKey = 'cust_' + loc.tracking_id;
                if (hasCustomerCoords) {
                    const customerPopupContent = `
                        <div style="font-family: inherit; font-size: 13px; line-height: 1.5; min-width: 230px; max-width: 290px;">
                            <div style="font-weight: 700; color: #1e293b; font-size: 15px; margin-bottom: 6px;">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i> Customer Drop-off
                            </div>
                            <div style="margin-bottom: 3px;"><strong>Order:</strong> #${escapeHtml(loc.order_number || 'N/A')}</div>
                            <div style="margin-bottom: 3px;"><strong>Customer:</strong> ${escapeHtml(loc.customer_name || 'Customer')}</div>
                            ${loc.customer_phone ? `<div style="margin-bottom: 3px;"><strong>Phone:</strong> <a href="tel:${escapeHtml(loc.customer_phone)}" class="text-decoration-none"><i class="fas fa-phone-alt me-1"></i>${escapeHtml(loc.customer_phone)}</a></div>` : ''}
                            <div style="font-size: 12px; color: #475467; margin-top: 6px; border-top: 1px solid #e2e8f0; padding-top: 4px;">
                                <strong>Address:</strong> ${escapeHtml(loc.delivery_address || 'N/A')}
                            </div>
                            <div class="mt-2 pt-2 border-top">
                                <a href="https://www.google.com/maps/dir/?api=1&destination=${customerLat},${customerLng}" target="_blank" rel="noopener" class="btn btn-sm btn-primary w-100" style="background:#b3261e; border-color:#b3261e; font-size: 11px;">
                                    <i class="fas fa-directions me-1"></i> Navigate via Google Maps
                                </a>
                            </div>
                        </div>`;

                    if (activeCustomerMarkers.has(customerKey)) {
                        const existingCustMarker = activeCustomerMarkers.get(customerKey);
                        existingCustMarker.setLatLng([customerLat, customerLng]);
                        existingCustMarker.setPopupContent(customerPopupContent);
                    } else {
                        const customerIcon = L.divIcon({
                            className: 'custom-leaflet-pin',
                            html: `<div class="customer-leaflet-marker" title="Customer: ${escapeHtml(loc.order_number || '')}"><i class="fas fa-map-marker-alt"></i></div>`,
                            iconSize: [36, 36],
                            iconAnchor: [18, 36]
                        });
                        const newCustMarker = L.marker([customerLat, customerLng], { icon: customerIcon }).addTo(logisticsMap);
                        newCustMarker.bindPopup(customerPopupContent);
                        activeCustomerMarkers.set(customerKey, newCustMarker);
                    }

                    // Update or create Route Polyline
                    const polylineKey = 'route_' + loc.tracking_id;
                    if (activeRoutePolylines.has(polylineKey)) {
                        const line = activeRoutePolylines.get(polylineKey);
                        line.setLatLngs([[driverLat, driverLng], [customerLat, customerLng]]);
                    } else {
                        const routeLine = L.polyline([[driverLat, driverLng], [customerLat, customerLng]], {
                            color: '#b3261e',
                            weight: 3,
                            opacity: 0.85,
                            dashArray: '6, 8'
                        }).addTo(logisticsMap);
                        activeRoutePolylines.set(polylineKey, routeLine);
                    }
                }
            });

            // Clean up removed markers
            activeDriverMarkers.forEach((marker, dId) => {
                if (!currentDriverIds.has(dId)) {
                    logisticsMap.removeLayer(marker);
                    activeDriverMarkers.delete(dId);
                }
            });
            activeCustomerMarkers.forEach((marker, cKey) => {
                const trId = parseInt(cKey.replace('cust_', ''));
                if (trId && !currentTrackingIds.has(trId)) {
                    logisticsMap.removeLayer(marker);
                    activeCustomerMarkers.delete(cKey);
                }
            });
            activeRoutePolylines.forEach((line, pKey) => {
                const trId = parseInt(pKey.replace('route_', ''));
                if (trId && !currentTrackingIds.has(trId)) {
                    logisticsMap.removeLayer(line);
                    activeRoutePolylines.delete(pKey);
                }
            });

            const pingLabel = document.getElementById('gpsLastPing');
            if (pingLabel && !isGpsBroadcasting) {
                pingLabel.textContent = `Auto-refresh: ${new Date().toLocaleTimeString()} (${data.locations.length} active delivery in transit)`;
            }

            if (boundsPoints.length > 0 && (!hasAutoFittedMap || isManualRefresh)) {
                logisticsMap.fitBounds(L.latLngBounds(boundsPoints), { padding: [40, 40], maxZoom: 15 });
                hasAutoFittedMap = true;
            } else if (boundsPoints.length === 0 && !hasAutoFittedMap) {
                logisticsMap.setView([14.3294, 120.9367], 11);
                hasAutoFittedMap = true;
            }
        })
        .catch(err => console.debug("Driver locations refresh failed:", err));
}

function fitAllMapBounds() {
    hasAutoFittedMap = false;
    fetchDriverLocations(true);
}

function triggerAcceptDelivery(orderId) {
    const info = window.deliveryOrdersMap ? window.deliveryOrdersMap[orderId] : null;
    if (info) {
        acceptDelivery(info.tracking_id || 0, info);
    } else {
        acceptDelivery(0, { order_id: orderId });
    }
}

function triggerFollowRoute(orderId) {
    const info = window.deliveryOrdersMap ? window.deliveryOrdersMap[orderId] : null;
    if (info) {
        focusCustomerOnMap(info);
    } else {
        console.warn("Order not found in deliveryOrdersMap:", orderId);
    }
}

// Accept Delivery Function
function acceptDelivery(trackingId, info) {
    const orderRef = info?.order_number || ('#' + (trackingId || info?.order_id));
    Swal.fire({
        title: 'Accept Delivery?',
        html: `Are you ready to take charge of <strong>Order ${escapeHtml(orderRef)}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-motorcycle me-1"></i> Yes, Accept Delivery'
    }).then((result) => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Accepting Delivery...',
            text: 'Assigning order and fetching customer dropoff location...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const formData = new FormData();
        formData.append('tracking_id', trackingId || 0);
        formData.append('order_id', info?.order_id || 0);
        formData.append('csrf_token', logisticsCsrfToken);

        fetch('ajax_accept_delivery.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(r => {
            if (!r.ok) {
                return r.text().then(text => {
                    let msg = `Server returned status ${r.status}`;
                    try {
                        const parsed = JSON.parse(text);
                        if (parsed && parsed.message) msg = parsed.message;
                    } catch (e) {
                        const clean = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 160);
                        if (clean) msg = clean;
                    }
                    throw new Error(msg);
                });
            }
            return r.json();
        })
        .then(data => {
            if (data && data.success) {
                const acceptedOrderId = data.order_id || info?.order_id;
                const acceptedTrackingId = data.tracking_id || trackingId;

                // Update row in table immediately if present
                const row = document.getElementById('logistics-row-logistics-' + acceptedTrackingId);
                if (row) {
                    const driverCell = row.querySelector('.driver-name');
                    if (driverCell) {
                        driverCell.innerHTML = `<span class="fw-semibold text-dark"><i class="fas fa-motorcycle text-danger me-1"></i>${escapeHtml(data.driver_name)}</span>`;
                    }

                    const statusCell = row.querySelector('.status-badge');
                    if (statusCell) {
                        statusCell.className = 'status-badge badge-warning';
                        statusCell.textContent = 'Assigned';
                    }
                }

                // Remove card from Available Deliveries feed immediately
                if (acceptedOrderId) {
                    const cardCol = document.getElementById('order-card-col-' + acceptedOrderId);
                    if (cardCol) {
                        cardCol.remove();
                    }
                }

                // Update tab counters
                const availBadge = document.querySelector('#tabLinkAvailable .badge');
                if (availBadge) {
                    const count = parseInt(availBadge.textContent.trim(), 10) || 0;
                    if (count > 0) availBadge.textContent = count - 1;
                }
                const activeBadge = document.querySelector('#tabLinkActive .badge');
                if (activeBadge) {
                    const activeCount = parseInt(activeBadge.textContent.trim(), 10) || 0;
                    activeBadge.textContent = activeCount + 1;
                }

                // Start device GPS broadcast immediately
                if (typeof startDriverRealtimeGps === 'function') {
                    startDriverRealtimeGps();
                }

                const custLoc = data.customer_location || {};
                const storeLoc = data.store_location || {};
                const riderLat = data.driver_latitude ?? lastGpsLat;
                const riderLng = data.driver_longitude ?? lastGpsLng;

                const targetCustomerInfo = {
                    ...info,
                    order_id: acceptedOrderId,
                    tracking_id: acceptedTrackingId,
                    order_number: custLoc.order_number || info?.order_number || ('#' + acceptedOrderId),
                    customer_name: custLoc.customer_name || info?.customer_name || 'Customer',
                    customer_phone: custLoc.customer_phone || info?.customer_phone || '',
                    address: custLoc.delivery_address || info?.address || info?.delivery_address || '',
                    lat: custLoc.latitude !== null ? custLoc.latitude : info?.lat,
                    lng: custLoc.longitude !== null ? custLoc.longitude : info?.lng,
                    driver_lat: riderLat,
                    driver_lng: riderLng,
                    driver_name: data.driver_name,
                    status: 'assigned'
                };

                // Close loading modal
                if (typeof Swal.close === 'function') {
                    Swal.close();
                }

                // Show brief confirmation toast
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                    icon: 'success',
                    title: `Order #${escapeHtml(targetCustomerInfo.order_number)} accepted! Opening customer location...`
                });

                // DIRECTLY SWITCH TO MAP AND SHOW CUSTOMER LOCATION SAFELY
                try {
                    focusCustomerOnMap(targetCustomerInfo);
                } catch (mapErr) {
                    console.warn('Map focus error:', mapErr);
                }

                // Update background map markers
                if (typeof fetchDriverLocations === 'function') {
                    fetchDriverLocations();
                }
            } else {
                Swal.fire('Error', (data && data.message) ? data.message : 'Failed to accept delivery.', 'error');
            }
        })
        .catch(err => {
            console.error('Accept delivery failed:', err);
            Swal.fire('Error', (err && err.message) ? err.message : 'Unable to accept delivery due to a network or server error.', 'error');
        });
    });
}

// Delivery Status Modal Control
function openDeliveryStatusModal(trackingId, currentStatus, orderId) {
    const idEl = document.getElementById('editDeliveryId');
    const orderIdEl = document.getElementById('editDeliveryOrderId');
    const statusEl = document.getElementById('editDeliveryStatus');
    if (idEl) idEl.value = trackingId || 0;
    if (orderIdEl) orderIdEl.value = orderId || 0;
    if (statusEl && currentStatus) statusEl.value = currentStatus;
    const modalEl = document.getElementById('deliveryStatusModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function editDeliveryStatus(trackingId, currentStatus) {
    openDeliveryStatusModal(trackingId, currentStatus, 0);
}

// Rider Chat with Customer
let currentChatOrderId = 0;
let riderChatPollInterval = null;

function openRiderChat(orderId, orderNumber, customerName, customerPhone) {
    currentChatOrderId = orderId;
    const nameEl = document.getElementById('riderChatCustomerName');
    const subEl = document.getElementById('riderChatOrderSubtitle');
    const phoneBtn = document.getElementById('riderChatPhoneBtn');

    if (nameEl) nameEl.textContent = customerName || 'Customer';
    if (subEl) subEl.textContent = 'Order #' + (orderNumber || orderId);

    if (phoneBtn) {
        if (customerPhone) {
            phoneBtn.href = 'tel:' + customerPhone;
            phoneBtn.style.display = 'inline-block';
        } else {
            phoneBtn.style.display = 'none';
        }
    }

    const modalEl = document.getElementById('riderChatModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    loadRiderChatMessages();

    if (riderChatPollInterval) clearInterval(riderChatPollInterval);
    riderChatPollInterval = setInterval(loadRiderChatMessages, 3000);
}

document.getElementById('riderChatModal')?.addEventListener('hidden.bs.modal', () => {
    if (riderChatPollInterval) {
        clearInterval(riderChatPollInterval);
        riderChatPollInterval = null;
    }
});

function loadRiderChatMessages() {
    if (!currentChatOrderId) return;
    fetch(`../api/delivery_chat.php?action=get_messages&order_id=${currentChatOrderId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && Array.isArray(data.messages)) {
                renderRiderChatMessages(data.messages);
            }
        })
        .catch(err => console.debug('Chat poll error:', err));
}

function renderRiderChatMessages(messages) {
    const container = document.getElementById('riderChatMessages');
    if (!container) return;
    if (messages.length === 0) {
        container.innerHTML = `<div class="text-center text-muted small py-4"><i class="fas fa-comments text-muted mb-2" style="font-size: 24px; display:block;"></i>No messages yet. Send a message to the customer!</div>`;
        return;
    }

    const isScrolledToBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;

    let html = '';
    messages.forEach(msg => {
        const isRider = (msg.sender_type === 'driver');
        const bubbleBg = isRider ? '#b3261e' : '#ffffff';
        const textColor = isRider ? '#ffffff' : '#101828';
        const align = isRider ? 'align-self-end' : 'align-self-start';
        const border = isRider ? 'none' : '1px solid #eaecf0';
        const senderLabel = isRider ? 'You' : (msg.sender_name || 'Customer');

        html += `
            <div class="${align}" style="max-width: 80%;">
                <div style="font-size: 10px; color: #667085; margin-bottom: 2px; ${isRider ? 'text-align: right;' : ''}">${escapeHtml(senderLabel)} &bull; ${escapeHtml(msg.formatted_time || '')}</div>
                <div style="background: ${bubbleBg}; color: ${textColor}; border: ${border}; border-radius: 12px; padding: 8px 12px; font-size: 13px; word-break: break-word; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    ${escapeHtml(msg.message)}
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
    if (isScrolledToBottom) {
        container.scrollTop = container.scrollHeight;
    }
}

function sendRiderChatMessage(e) {
    e.preventDefault();
    if (!currentChatOrderId) return;
    const input = document.getElementById('riderChatInput');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('order_id', currentChatOrderId);
    formData.append('message', msg);

    fetch('../api/delivery_chat.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadRiderChatMessages();
        } else {
            Swal.fire('Error', data.message || 'Failed to send message', 'error');
        }
    })
    .catch(err => {
        console.error(err);
    });
}

// Navigation state variables for route following
let activeFollowPolyline = null;
let activeFollowCustomerMarker = null;
let currentFollowMission = null;
let lastRouteFetchTime = 0;
let lastRiderRouteLat = null;
let lastRiderRouteLng = null;

function centerOnDriver() {
    if (!logisticsMap) return;
    if (lastGpsLat !== null && lastGpsLng !== null) {
        logisticsMap.setView([lastGpsLat, lastGpsLng], 16, { animate: true });
    } else if (lastRiderRouteLat !== null && lastRiderRouteLng !== null) {
        logisticsMap.setView([lastRiderRouteLat, lastRiderRouteLng], 16, { animate: true });
    }
}

function centerOnCustomer() {
    if (!logisticsMap || !currentFollowMission) return;
    if (currentFollowMission.destLat && currentFollowMission.destLng) {
        logisticsMap.setView([currentFollowMission.destLat, currentFollowMission.destLng], 16, { animate: true });
    }
}

function activateMapTab() {
    const mapTabBtn = document.getElementById('tabLinkMap') || document.querySelector('a[href="#mapView"]');
    if (mapTabBtn) {
        try {
            mapTabBtn.click();
        } catch (e) {
            console.warn('Tab click error:', e);
        }
    }
    // Guarantee active classes are toggled without relying on bootstrap event listeners
    document.querySelectorAll('.rider-nav-pills .nav-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-content .tab-pane').forEach(p => p.classList.remove('show', 'active'));
    if (mapTabBtn) mapTabBtn.classList.add('active');
    const mapPane = document.getElementById('mapView');
    if (mapPane) mapPane.classList.add('show', 'active');
}

// View Customer Location & Draw Route on Map
function focusCustomerOnMap(info) {
    if (!info) return;

    try {
        activateMapTab();
    } catch (tabErr) {
        console.warn('Tab activation error:', tabErr);
    }

    if (!logisticsMap) {
        initLogisticsMap();
    }

    setTimeout(() => {
        try {
            if (!logisticsMap) initLogisticsMap();
            if (!logisticsMap) return;
            logisticsMap.invalidateSize();

            const lat = parseCoordinate(info.lat ?? info.customer_latitude);
            const lng = parseCoordinate(info.lng ?? info.customer_longitude);

            if (lat !== null && lng !== null) {
                drawFollowingRoute(lastGpsLat, lastGpsLng, lat, lng, info);
            } else if ((info.address && info.address.trim() !== '') || (info.delivery_address && info.delivery_address.trim() !== '')) {
                geocodeAndZoomCustomer(info);
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Customer Location',
                    text: 'No GPS coordinates or delivery address provided for this order yet.',
                    confirmButtonColor: '#b3261e'
                });
            }
        } catch (err) {
            console.error('Error drawing customer route on map:', err);
        }
    }, 300);
}

// Draw dynamic navigation route that follows the rider to the customer address
function drawFollowingRoute(driverLat, driverLng, destLat, destLng, info) {
    if (!info) return;
    if (!logisticsMap) initLogisticsMap();
    if (!logisticsMap) return;

    destLat = parseCoordinate(destLat ?? info.lat ?? info.customer_latitude);
    destLng = parseCoordinate(destLng ?? info.lng ?? info.customer_longitude);
    driverLat = parseCoordinate(driverLat ?? lastGpsLat);
    driverLng = parseCoordinate(driverLng ?? lastGpsLng);

    if (destLat === null || destLng === null) {
        if (info.address && info.address.trim() !== '') {
            geocodeAndZoomCustomer(info);
        }
        return;
    }

    // If rider coordinates are not yet available, fallback nearby or center
    if (driverLat === null || driverLng === null) {
        if (lastGpsLat !== null && lastGpsLng !== null) {
            driverLat = lastGpsLat;
            driverLng = lastGpsLng;
        } else {
            driverLat = destLat - 0.012;
            driverLng = destLng - 0.010;
        }
    }

    lastRiderRouteLat = driverLat;
    lastRiderRouteLng = driverLng;

    currentFollowMission = {
        order_number: info.order_number || '',
        customer_name: info.customer_name || 'Customer',
        customer_phone: info.customer_phone || '',
        address: info.address || info.delivery_address || '',
        destLat: destLat,
        destLng: destLng,
        tracking_id: info.tracking_id || 0
    };

    // Update Floating HUD
    const hud = document.getElementById('riderMapHud');
    if (hud) {
        hud.style.display = 'block';
        const numEl = document.getElementById('hudOrderNumber');
        const addrEl = document.getElementById('hudCustomerAddress');
        const extBtn = document.getElementById('hudExternalMapsBtn');
        if (numEl) numEl.textContent = '#' + (info.order_number || '');
        if (addrEl) addrEl.innerHTML = `<i class="fas fa-map-marker-alt text-danger me-1"></i> ${escapeHtml(info.address || info.delivery_address || 'Customer Drop-off')}`;
        if (extBtn) extBtn.href = `https://www.google.com/maps/dir/?api=1&origin=${driverLat},${driverLng}&destination=${destLat},${destLng}`;
    }

    // Place or update customer drop-off marker
    const custPopup = `
        <div style="font-family: inherit; font-size: 13px; line-height: 1.5; min-width: 230px;">
            <div style="font-weight: 700; color: #b3261e; font-size: 14px; margin-bottom: 4px;">
                <i class="fas fa-flag-checkered text-danger me-1"></i> Customer Drop-off
            </div>
            <div><strong>Order:</strong> #${escapeHtml(info.order_number || 'N/A')}</div>
            <div><strong>Customer:</strong> ${escapeHtml(info.customer_name || 'Customer')}</div>
            ${info.customer_phone ? `<div><strong>Phone:</strong> <a href="tel:${escapeHtml(info.customer_phone)}" class="text-decoration-none"><i class="fas fa-phone-alt me-1"></i>${escapeHtml(info.customer_phone)}</a></div>` : ''}
            <div style="font-size: 12px; color: #475467; margin-top: 4px; border-top: 1px solid #e2e8f0; padding-top: 4px;">
                <strong>Address:</strong> ${escapeHtml(info.address || info.delivery_address || '')}
            </div>
            <div class="mt-2 pt-2 border-top">
                <a href="https://www.google.com/maps/dir/?api=1&destination=${destLat},${destLng}" target="_blank" rel="noopener" class="btn btn-sm btn-primary w-100" style="background:#b3261e; border-color:#b3261e; font-size: 11px;">
                    <i class="fas fa-directions me-1"></i> Open Google Maps Nav
                </a>
            </div>
        </div>
    `;

    if (activeFollowCustomerMarker) {
        activeFollowCustomerMarker.setLatLng([destLat, destLng]);
        activeFollowCustomerMarker.setPopupContent(custPopup);
        activeFollowCustomerMarker.openPopup();
    } else {
        const destIcon = L.divIcon({
            className: 'custom-leaflet-pin',
            html: `<div class="customer-leaflet-marker" style="background:#101828; color:#ffffff; border-color:#ffffff;" title="Customer Drop-off"><i class="fas fa-flag-checkered"></i></div>`,
            iconSize: [38, 38],
            iconAnchor: [19, 38]
        });
        activeFollowCustomerMarker = L.marker([destLat, destLng], { icon: destIcon }).addTo(logisticsMap);
        activeFollowCustomerMarker.bindPopup(custPopup);
        activeFollowCustomerMarker.openPopup();
    }

    // Query OSRM Driving Route
    fetchOsrmDrivingRoute(driverLat, driverLng, destLat, destLng);
}

function fetchOsrmDrivingRoute(driverLat, driverLng, destLat, destLng) {
    if (!logisticsMap) return;

    lastRouteFetchTime = Date.now();
    const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${driverLng},${driverLat};${destLng},${destLat}?overview=full&geometries=geojson`;

    fetch(osrmUrl)
        .then(r => r.json())
        .then(data => {
            let latLngs = [];
            let distanceMeters = 0;
            let durationSeconds = 0;

            if (data && data.code === 'Ok' && data.routes && data.routes.length > 0) {
                const route = data.routes[0];
                distanceMeters = route.distance || 0;
                durationSeconds = route.duration || 0;
                const coords = route.geometry.coordinates; // [lng, lat]
                latLngs = coords.map(pt => [pt[1], pt[0]]);
            } else {
                latLngs = [[driverLat, driverLng], [destLat, destLng]];
                distanceMeters = computeDistanceKm({lat: driverLat, lng: driverLng}, {lat: destLat, lng: destLng}) * 1000;
                durationSeconds = (distanceMeters / 1000 / 25) * 3600;
            }

            renderFollowPolyline(latLngs, distanceMeters, durationSeconds);
        })
        .catch(err => {
            console.warn("OSRM routing unavailable, using direct path:", err);
            const latLngs = [[driverLat, driverLng], [destLat, destLng]];
            const distanceMeters = computeDistanceKm({lat: driverLat, lng: driverLng}, {lat: destLat, lng: destLng}) * 1000;
            const durationSeconds = (distanceMeters / 1000 / 25) * 3600;
            renderFollowPolyline(latLngs, distanceMeters, durationSeconds);
        });
}

function renderFollowPolyline(latLngs, distanceMeters, durationSeconds) {
    if (!logisticsMap) return;

    if (activeFollowPolyline) {
        activeFollowPolyline.setLatLngs(latLngs);
    } else {
        activeFollowPolyline = L.polyline(latLngs, {
            color: '#b3261e',
            weight: 6,
            opacity: 0.92,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(logisticsMap);
    }

    // Update HUD metrics
    const km = (distanceMeters / 1000).toFixed(1);
    const mins = Math.max(1, Math.round(durationSeconds / 60));
    const distEl = document.getElementById('hudDistance');
    const etaEl = document.getElementById('hudEta');
    if (distEl) distEl.textContent = `${km} km`;
    if (etaEl) etaEl.textContent = `${mins} mins`;

    // Fit bounds smoothly to view both rider and customer
    if (latLngs.length > 0) {
        logisticsMap.fitBounds(L.latLngBounds(latLngs), { padding: [55, 55], maxZoom: 16 });
    }
}

// Update the navigation line dynamically as the rider moves in real-time
function updateRiderMovingRoute(newLat, newLng) {
    if (!currentFollowMission || !currentFollowMission.destLat || !currentFollowMission.destLng) return;

    const destLat = currentFollowMission.destLat;
    const destLng = currentFollowMission.destLng;
    const now = Date.now();

    const movedMeters = (lastRiderRouteLat !== null && lastRiderRouteLng !== null)
        ? computeDistanceKm({lat: lastRiderRouteLat, lng: lastRiderRouteLng}, {lat: newLat, lng: newLng}) * 1000
        : 999;

    lastRiderRouteLat = newLat;
    lastRiderRouteLng = newLng;

    // Recalculate full OSRM road geometry if rider moved > 25 meters or every 15 seconds
    if (movedMeters >= 25 || (now - lastRouteFetchTime) >= 15000) {
        fetchOsrmDrivingRoute(newLat, newLng, destLat, destLng);
    } else if (activeFollowPolyline) {
        // Immediate smooth micro-update: re-anchor line start point to current rider coords
        const currentPoints = activeFollowPolyline.getLatLngs();
        if (Array.isArray(currentPoints) && currentPoints.length > 0) {
            currentPoints[0] = L.latLng(newLat, newLng);
            activeFollowPolyline.setLatLngs(currentPoints);

            const distKm = computeDistanceKm({lat: newLat, lng: newLng}, {lat: destLat, lng: destLng});
            const distEl = document.getElementById('hudDistance');
            if (distEl) distEl.textContent = `${distKm.toFixed(1)} km`;
        }
    }
}

function geocodeAndZoomCustomer(info) {
    const rawAddress = String(info.address || info.delivery_address || '').trim();
    if (!rawAddress) return;

    const cleanAddress = rawAddress.replace(/[^\w\s,.-]/g, '').trim();
    if (!cleanAddress) return;

    // Show indicator on HUD
    const hud = document.getElementById('riderMapHud');
    if (hud) {
        hud.style.display = 'block';
        const numEl = document.getElementById('hudOrderNumber');
        const addrEl = document.getElementById('hudCustomerAddress');
        if (numEl) numEl.textContent = '#' + (info.order_number || '');
        if (addrEl) addrEl.innerHTML = `<i class="fas fa-spinner fa-spin text-danger me-1"></i> Resolving address: ${escapeHtml(rawAddress)}...`;
    }

    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(cleanAddress)}&limit=1`)
        .then(r => r.json())
        .then(results => {
            if (results && results.length > 0) {
                const lat = parseFloat(results[0].lat);
                const lng = parseFloat(results[0].lon);
                info.lat = lat;
                info.lng = lng;
                drawFollowingRoute(lastGpsLat, lastGpsLng, lat, lng, info);
            } else {
                if (hud) {
                    const addrEl = document.getElementById('hudCustomerAddress');
                    if (addrEl) addrEl.innerHTML = `<i class="fas fa-map-marker-alt text-danger me-1"></i> ${escapeHtml(rawAddress)}`;
                }
                Swal.fire({
                    title: 'Customer Address',
                    html: `
                        <p class="mb-2"><strong>Address:</strong> ${escapeHtml(rawAddress)}</p>
                        <p class="small text-muted mb-0">Exact coordinates not resolved automatically. Would you like to search in Google Maps?</p>
                    `,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: '#b3261e',
                    confirmButtonText: '<i class="fas fa-external-link-alt me-1"></i> Open Google Maps'
                }).then(res => {
                    if (res && res.isConfirmed) {
                        window.open(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(rawAddress)}`, '_blank');
                    }
                });
            }
        })
        .catch(err => {
            console.error('Geocoding error:', err);
            if (hud) {
                const addrEl = document.getElementById('hudCustomerAddress');
                if (addrEl) addrEl.innerHTML = `<i class="fas fa-map-marker-alt text-danger me-1"></i> ${escapeHtml(rawAddress)}`;
            }
            window.open(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(rawAddress)}`, '_blank');
        });
}

// Real-time GPS Broadcasting from Device
function toggleGpsBroadcast() {
    if (isGpsBroadcasting) {
        stopDriverRealtimeGps();
    } else {
        startDriverRealtimeGps();
    }
}

function startDriverRealtimeGps() {
    if (!navigator.geolocation) {
        Swal.fire('GPS Not Supported', 'Your browser does not support geolocation.', 'warning');
        return;
    }

    if (gpsWatcherId !== null) return;

    const btn = document.getElementById('toggleGpsBroadcastBtn');
    const textSpan = document.getElementById('gpsBroadcastText');
    const quickBtn = document.getElementById('quickGpsToggleBtn');
    const quickText = document.getElementById('quickGpsText');

    isGpsBroadcasting = true;
    if (btn) {
        btn.style.background = '#027a48';
        btn.style.borderColor = '#027a48';
    }
    if (textSpan) {
        textSpan.innerHTML = '<i class="fas fa-satellite-dish fa-spin me-1"></i> Broadcasting GPS';
    }
    if (quickBtn) {
        quickBtn.style.background = '#027a48';
        quickBtn.style.borderColor = '#027a48';
    }
    if (quickText) {
        quickText.innerHTML = '<i class="fas fa-satellite-dish fa-spin me-1"></i> Live GPS Broadcasting';
    }

    const options = {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 1000
    };

    gpsWatcherId = navigator.geolocation.watchPosition(
        (position) => {
            const { latitude, longitude, accuracy } = position.coords;
            const now = Date.now();

            const distMeters = (lastGpsLat !== null && lastGpsLng !== null)
                ? computeDistanceKm({lat: lastGpsLat, lng: lastGpsLng}, {lat: latitude, lng: longitude}) * 1000
                : 999;

            if (distMeters >= 4 || (now - lastGpsTime) >= 3500) {
                lastGpsLat = latitude;
                lastGpsLng = longitude;
                lastGpsTime = now;

                // Dynamically update the active route line following the rider
                updateRiderMovingRoute(latitude, longitude);

                const params = new URLSearchParams();
                params.append('latitude', latitude);
                params.append('longitude', longitude);
                params.append('accuracy', accuracy || 0);

                fetch('../api/update_driver_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(r => r.json())
                .then(data => {
                    const pingLabel = document.getElementById('gpsLastPing');
                    if (pingLabel) {
                        pingLabel.textContent = `Broadcasting GPS live (${new Date().toLocaleTimeString()})`;
                    }
                    fetchDriverLocations();
                })
                .catch(err => console.debug('GPS post error:', err));
            }
        },
        (error) => {
            console.warn('Geolocation error:', error.message);
        },
        options
    );
}

function stopDriverRealtimeGps() {
    if (gpsWatcherId !== null) {
        navigator.geolocation.clearWatch(gpsWatcherId);
        gpsWatcherId = null;
    }
    isGpsBroadcasting = false;
    const btn = document.getElementById('toggleGpsBroadcastBtn');
    const textSpan = document.getElementById('gpsBroadcastText');
    const quickBtn = document.getElementById('quickGpsToggleBtn');
    const quickText = document.getElementById('quickGpsText');

    if (btn) {
        btn.style.background = '#b3261e';
        btn.style.borderColor = '#b3261e';
    }
    if (textSpan) {
        textSpan.innerHTML = '<i class="fas fa-location-arrow me-1"></i> Broadcast Device GPS';
    }
    if (quickBtn) {
        quickBtn.style.background = '#b3261e';
        quickBtn.style.borderColor = '#b3261e';
    }
    if (quickText) {
        quickText.innerHTML = '<i class="fas fa-location-arrow me-1"></i> Live GPS Streaming';
    }
}

// Auto-start GPS broadcasting if logged in as a driver
<?php if ($current_driver): ?>
document.addEventListener('DOMContentLoaded', () => {
    startDriverRealtimeGps();
});
<?php endif; ?>

<?php if ($active_mission && !empty($active_mission['customer_latitude']) && !empty($active_mission['customer_longitude'])): ?>
window.initialActiveMission = <?php echo json_encode([
    'order_number' => $active_mission['order_number'],
    'customer_name' => $active_mission['customer_name'],
    'customer_phone' => $active_mission['customer_phone'],
    'address' => $active_mission['delivery_address'],
    'lat' => (float)$active_mission['customer_latitude'],
    'lng' => (float)$active_mission['customer_longitude'],
    'driver_lat' => !empty($active_mission['driver_lat']) ? (float)$active_mission['driver_lat'] : null,
    'driver_lng' => !empty($active_mission['driver_lng']) ? (float)$active_mission['driver_lng'] : null,
    'tracking_id' => (int)$active_mission['tracking_id']
]); ?>;
<?php endif; ?>

const mapViewTabTrigger = document.querySelector('a[data-bs-toggle="tab"][href="#mapView"]');
if (mapViewTabTrigger) {
    mapViewTabTrigger.addEventListener('shown.bs.tab', () => {
        if (!logisticsMap) {
            initLogisticsMap();
        } else {
            setTimeout(() => {
                logisticsMap.invalidateSize();
                fetchDriverLocations();
                if (window.initialActiveMission && !activeFollowPolyline) {
                    drawFollowingRoute(
                        window.initialActiveMission.driver_lat,
                        window.initialActiveMission.driver_lng,
                        window.initialActiveMission.lat,
                        window.initialActiveMission.lng,
                        window.initialActiveMission
                    );
                }
            }, 200);
        }
    });
}

// Auto-switch to tab if page hash is present (e.g. #activeTab, #mapView)
if (window.location.hash) {
    const hashTrigger = document.querySelector(`a[data-bs-toggle="tab"][href="${window.location.hash}"]`);
    if (hashTrigger) {
        try {
            hashTrigger.click();
        } catch (e) {
            console.warn('Hash trigger click error:', e);
        }
    }
}

function promptTicketReply(ticketId) {
    Swal.fire({
        title: 'Respond to Ticket #' + ticketId,
        input: 'textarea',
        inputLabel: 'Dispatch Response / Resolution',
        inputPlaceholder: 'Type response message for the rider...',
        showCancelButton: true,
        confirmButtonText: 'Send Response & Resolve',
        confirmButtonColor: '#027a48',
        cancelButtonColor: '#6c757d',
        preConfirm: (text) => {
            if (!text || !text.trim()) {
                Swal.showValidationMessage('Response text cannot be empty.');
            }
            return text;
        }
    }).then((res) => {
        if (res.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'logistics.php#fleetView';

            const csrfIn = document.createElement('input');
            csrfIn.type = 'hidden';
            csrfIn.name = 'csrf_token';
            csrfIn.value = logisticsCsrfToken;
            form.appendChild(csrfIn);

            const tIn = document.createElement('input');
            tIn.type = 'hidden';
            tIn.name = 'reply_ticket_id';
            tIn.value = ticketId;
            form.appendChild(tIn);

            const rIn = document.createElement('input');
            rIn.type = 'hidden';
            rIn.name = 'admin_response';
            rIn.value = res.value;
            form.appendChild(rIn);

            const sIn = document.createElement('input');
            sIn.type = 'hidden';
            sIn.name = 'ticket_status';
            sIn.value = 'resolved';
            form.appendChild(sIn);

            document.body.appendChild(form);
            form.submit();
        }
    });
}

let currentHandoverExpectedPin = '';

function openHandoverModal(orderId, orderNum, pin, custName, riderCode) {
    document.getElementById('handoverOrderId').value = orderId;
    document.getElementById('modalHandoverOrderNum').textContent = '#' + orderNum;
    document.getElementById('modalHandoverCustName').textContent = custName || 'Customer';
    
    currentHandoverExpectedPin = pin || '';
    document.getElementById('modalHandoverPinBadge').textContent = pin ? pin : 'None';
    
    const input = document.getElementById('handoverCodeInput');
    input.value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('pickupHandoverModal'));
    modal.show();
    setTimeout(() => input.focus(), 400);
}

function autofillExpectedPin() {
    if (currentHandoverExpectedPin) {
        document.getElementById('handoverCodeInput').value = currentHandoverExpectedPin;
    }
}

function submitPickupHandover() {
    const orderId = document.getElementById('handoverOrderId').value;
    const code = document.getElementById('handoverCodeInput').value.trim();
    const btn = document.getElementById('confirmHandoverBtn');
    
    if (!code) {
        Swal.fire({
            icon: 'warning',
            title: 'Code Required',
            text: 'Please enter the delivery PIN, rider code, or order number to verify handover.'
        });
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Verifying...';
    
    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('verification_code', code);
    formData.append('csrf_token', logisticsCsrfToken);
    
    fetch('ajax_confirm_pickup.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider';
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('pickupHandoverModal')).hide();
            Swal.fire({
                icon: 'success',
                title: 'Pickup Confirmed!',
                text: data.message,
                confirmButtonColor: '#027a48'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Verification Failed',
                text: data.message || 'Invalid verification code.'
            });
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider';
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Unable to communicate with the server. Please try again.'
        });
    });
}

function approveRiderHandover(orderId) {
    Swal.fire({
        title: 'Approve Rider Handover?',
        text: 'Confirm that you are handing over the package to the arrived rider. The rider will be cleared to proceed to the customer.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#027a48',
        cancelButtonColor: '#667085',
        confirmButtonText: '<i class="fas fa-check"></i> Yes, Approve & Dispatch',
        cancelButtonText: 'Cancel'
    }).then((res) => {
        if (!res.isConfirmed) return;

        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('csrf_token', logisticsCsrfToken);

        fetch('ajax_handover_action.php?action=approve', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Handover Approved!',
                    text: data.message,
                    confirmButtonColor: '#027a48'
                }).then(() => location.reload());
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: data.message || 'Failed to approve handover.'
                });
            }
        })
        .catch(() => {
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Could not connect to server.'
            });
        });
    });
}

function rejectRiderHandover(orderId) {
    Swal.fire({
        title: 'Reject / Pause Handover?',
        text: 'Provide a reason for the rider (e.g. food still cooking, missing item):',
        input: 'text',
        inputPlaceholder: 'e.g. Order still cooking, please wait 5-10 minutes',
        inputValue: 'Order is still being prepared. Please wait 5-10 minutes.',
        showCancelButton: true,
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#667085',
        confirmButtonText: 'Submit Rejection / Note',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (!value || !value.trim()) {
                return 'Please enter a brief note for the rider.';
            }
        }
    }).then((res) => {
        if (!res.isConfirmed) return;

        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('reason', res.value.trim());
        formData.append('csrf_token', logisticsCsrfToken);

        fetch('ajax_handover_action.php?action=reject', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'info',
                    title: 'Rider Notified',
                    text: data.message,
                    confirmButtonColor: '#b3261e'
                }).then(() => location.reload());
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: data.message || 'Failed to submit rejection.'
                });
            }
        })
        .catch(() => {
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Could not connect to server.'
            });
        });
    });
}
</script>
</body>
</html>

