<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../includes/OrderCancellationRefundService.php';

header('Content-Type: application/json');

checkAdminAccess();
requirePermission('orders.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$scope_sql = ($seller_scope_id !== null)
    ? (" AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id'))
    : '';

$stmt = mysqli_prepare($conn, "SELECT o.id, o.order_number, o.status, o.total_amount, o.cancellation_reason FROM orders o WHERE o.id = ?{$scope_sql} LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to validate order access.']);
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$order = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$order) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Order not found or outside your store scope.']);
    exit;
}

if (strtolower(trim((string)($order['status'] ?? ''))) !== 'cancelled') {
    echo json_encode(['success' => false, 'message' => 'Only cancelled orders are eligible for the 50% PayMongo cancellation refund.']);
    exit;
}

$reason = trim((string)($order['cancellation_reason'] ?? 'Order cancelled - 50% PayMongo refund'));
$result = OrderCancellationRefundService::processFiftyPercentPayMongoRefund(
    $conn,
    $order_id,
    $current_user_id,
    $reason,
    true
);

echo json_encode($result);
exit;
