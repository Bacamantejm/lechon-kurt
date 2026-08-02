<?php
/**
 * AJAX Handler: Update Delivery Status
 * Updates logistics tracking status and notifies customer
 * 
 * POST Parameters:
 * - tracking_id: ID from logistics_tracking table
 * - status: New status (pending, assigned, picked_up, on_the_way, arriving, delivered, failed, cancelled)
 * - description: Optional status description
 * - latitude: Optional GPS latitude
 * - longitude: Optional GPS longitude
 */

session_start();
require_once '../admin/auth.php';
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../logistics_service.php';

checkAdminAccess();
requirePermission('logistics.update');

header('Content-Type: application/json');

try {
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

    if (isset($_POST['csrf_token']) && !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Invalid request token. Please refresh and try again.');
    }

    // Validate input
    if (empty($_POST['tracking_id']) || empty($_POST['status'])) {
        throw new Exception('Missing tracking_id or status');
    }
    
    $tracking_id = intval($_POST['tracking_id']);
    $status = trim($_POST['status']);
    $description = trim($_POST['description'] ?? '');
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    
    // Validate status
    $valid_statuses = ['pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception('Invalid status: ' . $status);
    }
    
    $logistics = new LogisticsService($conn);
    
    // Get current tracking to verify it exists
    $tracking = $logistics->getTracking($tracking_id);
    if (!$tracking) {
        throw new Exception('Tracking not found');
    }

    if ($seller_scope_id !== null) {
        $scope_sql = "SELECT lt.id
                      FROM logistics_tracking lt
                      WHERE lt.id = ?
                        AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id') . "
                      LIMIT 1";
        $scope_stmt = mysqli_prepare($conn, $scope_sql);
        mysqli_stmt_bind_param($scope_stmt, "i", $tracking_id);
        mysqli_stmt_execute($scope_stmt);
        $scope_result = mysqli_stmt_get_result($scope_stmt);
        $is_allowed = $scope_result && mysqli_fetch_assoc($scope_result);
        mysqli_stmt_close($scope_stmt);

        if (!$is_allowed) {
            throw new Exception('You can only update deliveries tied to your own store orders.');
        }
    }
    
    // Update status
    $result = $logistics->updateTrackingStatus(
        $tracking_id,
        $status,
        $description,
        $latitude,
        $longitude
    );
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Delivery status updated successfully',
            'tracking_id' => $tracking_id,
            'new_status' => $status
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to update status');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>
