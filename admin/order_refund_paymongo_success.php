<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';
require_once '../includes/OrderCancellationRefundService.php';

checkAdminAccess();
requirePermission('orders.edit');

$order_id = (int)($_GET['order_id'] ?? 0);
$refund_id = (int)($_GET['refund_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);

if ($order_id <= 0 || $refund_id <= 0) {
    $_SESSION['error'] = 'Invalid PayMongo refund callback parameters.';
    header('Location: orders.php');
    exit;
}

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

if ($seller_scope_id !== null) {
    $scope_stmt = mysqli_prepare(
        $conn,
        "SELECT o.id FROM orders o WHERE o.id = ? AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id') . " LIMIT 1"
    );
    $scoped = null;
    if ($scope_stmt) {
        mysqli_stmt_bind_param($scope_stmt, "i", $order_id);
        mysqli_stmt_execute($scope_stmt);
        $scope_res = mysqli_stmt_get_result($scope_stmt);
        $scoped = $scope_res ? mysqli_fetch_assoc($scope_res) : null;
        mysqli_stmt_close($scope_stmt);
    }
    if (!$scoped) {
        $_SESSION['error'] = 'Access denied: order is outside your store scope.';
        header('Location: orders.php');
        exit;
    }
}

$result = OrderCancellationRefundService::completePayMongoCheckoutRefund($conn, $order_id, $refund_id, $current_user_id);
if (!empty($result['success'])) {
    $_SESSION['success'] = $result['message'] ?? '50% PayMongo refund completed successfully.';
} else {
    $_SESSION['error'] = $result['message'] ?? 'Failed to verify PayMongo refund completion.';
}

header('Location: orders.php?status=cancelled');
exit;
