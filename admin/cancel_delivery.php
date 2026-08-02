<?php
/**
 * AJAX Handler: Cancel Delivery
 * Cancels a pending or in-progress delivery
 * 
 * POST Parameters:
 * - tracking_id: ID from logistics_tracking table
 * - reason: Cancellation reason (required)
 * - refund_amount: Optional refund amount for customer
 */

session_start();
require_once '../admin/auth.php';
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../logistics_service.php';

checkAdminAccess();
requirePermission('logistics.update');

header('Content-Type: application/json');

function ensureLogisticsIssuesTable($conn) {
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $create_sql = "
        CREATE TABLE IF NOT EXISTS logistics_issues (
            id INT(11) NOT NULL AUTO_INCREMENT,
            tracking_id INT(11) NOT NULL,
            issue_type VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tracking_id (tracking_id),
            KEY idx_issue_type (issue_type),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!mysqli_query($conn, $create_sql)) {
        return false;
    }

    $ensured = true;
    return true;
}

try {
    $external_sync_warnings = [];
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

    // Validate input
    if (empty($_POST['tracking_id']) || empty($_POST['reason'])) {
        throw new Exception('Missing tracking_id or reason');
    }

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid request token. Please refresh and try again.');
    }
    
    $tracking_id = intval($_POST['tracking_id']);
    $reason = trim($_POST['reason']);
    $refund_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;
    
    if (strlen($reason) < 5) {
        throw new Exception('Cancellation reason must be at least 5 characters');
    }
    
    $logistics = new LogisticsService($conn);
    
    // Get current tracking
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
            throw new Exception('You can only cancel deliveries for your own store orders.');
        }
    }
    
    // Check if cancellable
    $non_cancellable = ['cancelled', 'delivered', 'failed'];
    if (in_array($tracking['current_status'], $non_cancellable)) {
        throw new Exception('Cannot cancel a delivery that is already ' . $tracking['current_status']);
    }
    
    // Update status to cancelled
    $result = $logistics->updateTrackingStatus(
        $tracking_id,
        'cancelled',
        'Cancellation Reason: ' . $reason
    );
    
    if ($result['success']) {
        // Log cancellation issue if the table is available; do not block main flow.
        if (ensureLogisticsIssuesTable($conn)) {
            $log_query = "INSERT INTO logistics_issues (tracking_id, issue_type, description, created_at) 
                          VALUES (?, 'cancellation', ?, NOW())";
            $stmt = mysqli_prepare($conn, $log_query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'is', $tracking_id, $reason);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        
        // If this is a third-party delivery, notify the platform
        if ($tracking['logistics_provider_id'] == 2) {
            // FoodPanda
            $external_id = $tracking['external_order_id'] ?? null;
            $foodpanda_file = dirname(__DIR__) . '/foodpanda_integration.php';
            if ($external_id && file_exists($foodpanda_file)) {
                require_once $foodpanda_file;
                if (class_exists('FoodPandaIntegration')) {
                    $foodpanda = new FoodPandaIntegration($conn);
                    $foodpanda->cancelOrder($external_id, $reason);
                } else {
                    $external_sync_warnings[] = 'FoodPanda integration class is unavailable.';
                }
            } elseif ($external_id) {
                $external_sync_warnings[] = 'FoodPanda integration file is missing; local cancellation still completed.';
            }
        } elseif ($tracking['logistics_provider_id'] == 3) {
            // GrabFood
            $external_id = $tracking['external_order_id'] ?? null;
            $grabfood_file = dirname(__DIR__) . '/grabfood_integration.php';
            if ($external_id && file_exists($grabfood_file)) {
                require_once $grabfood_file;
                if (class_exists('GrabFoodIntegration')) {
                    $grabfood = new GrabFoodIntegration($conn);
                    $grabfood->cancelDelivery($external_id, $reason);
                } else {
                    $external_sync_warnings[] = 'GrabFood integration class is unavailable.';
                }
            } elseif ($external_id) {
                $external_sync_warnings[] = 'GrabFood integration file is missing; local cancellation still completed.';
            }
        }
        
        // Update order status in orders table (align with current schema).
        $order_update = "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status NOT IN ('delivered','cancelled')";
        $stmt2 = mysqli_prepare($conn, $order_update);
        mysqli_stmt_bind_param($stmt2, 'i', $tracking['order_id']);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);
        
        echo json_encode([
            'success' => true,
            'message' => 'Delivery cancelled successfully',
            'tracking_id' => $tracking_id,
            'reason' => $reason,
            'order_id' => $tracking['order_id'],
            'warnings' => $external_sync_warnings
        ]);
    } else {
        throw new Exception($result['message'] ?? 'Failed to cancel delivery');
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
