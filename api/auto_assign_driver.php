<?php
/**
 * API Endpoint: Auto-Assign Driver
 */
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
require_once '../EnhancedLogisticsService.php';

checkAdminAccess();
requirePermission('logistics.assign');

header('Content-Type: application/json');

$order_id = intval($_POST['order_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

if (!$order_id) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Order ID required']));
}

try {
    // Get order details
    $partner_order_scope_sql = '';
    if ($seller_scope_id !== null) {
        $partner_order_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
    }

    $order_query = "SELECT o.*, o.delivery_date FROM orders o WHERE o.id = ? {$partner_order_scope_sql}";
    $order_stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($order_stmt, "i", $order_id);
    mysqli_stmt_execute($order_stmt);
    $order_result = mysqli_stmt_get_result($order_stmt);
    $order = mysqli_fetch_assoc($order_result);
    mysqli_stmt_close($order_stmt);
    
    if (!$order) {
        throw new Exception($seller_scope_id !== null ? "Order not found in your tenant scope" : "Order not found");
    }
    
    // Use enhanced logistics service
    $logistics_service = new EnhancedLogisticsService($conn);
    
    // Get coordinates if available
    $latitude = null;
    $longitude = null;
    if ($order['customer_coordinates']) {
        $coords = json_decode($order['customer_coordinates'], true);
        if ($coords && isset($coords['latitude'], $coords['longitude'])) {
            $latitude = $coords['latitude'];
            $longitude = $coords['longitude'];
        }
    }
    
    // Auto-assign driver
    $result = $logistics_service->autoAssignDriver(
        $order_id,
        $order['delivery_address'],
        $latitude,
        $longitude,
        EnhancedLogisticsService::ASSIGNMENT_ALGORITHM_HYBRID,
        $order['delivery_date'] ?? null
    );
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Driver assigned successfully',
            'driver_id' => $result['driver_id'],
            'driver_name' => $result['driver_name'],
            'tracking_id' => $result['tracking_id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
