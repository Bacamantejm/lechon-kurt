<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/OrderCancellationRefundService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    checkAdminAccess();
    requirePermission('orders.edit');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
        exit;
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit;
    }

    $preferred_method = strtolower(trim((string)($_POST['payment_method'] ?? 'all')));
    if (!in_array($preferred_method, ['all', 'gcash', 'paymaya'], true)) {
        $preferred_method = 'all';
    }

    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

    $scope_sql = ($seller_scope_id !== null)
        ? (" AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id'))
        : '';

    $stmt = mysqli_prepare($conn, "SELECT o.* FROM orders o WHERE o.id = ?{$scope_sql} LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to validate order access: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $order = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$order) {
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Order not found or outside your store scope.']);
        exit;
    }

    if (strtolower(trim((string)($order['status'] ?? ''))) !== 'cancelled') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Only cancelled orders are eligible for the 50% PayMongo cancellation refund.']);
        exit;
    }

    $reason = trim((string)($order['cancellation_reason'] ?? 'Order cancelled - 50% PayMongo refund'));
    $result = OrderCancellationRefundService::processFiftyPercentPayMongoRefund(
        $conn,
        $order_id,
        $current_user_id,
        $reason,
        true,
        $preferred_method
    );

    ob_clean();
    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('ajax_process_order_refund error: ' . $e->getMessage());
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Refund processing error: ' . $e->getMessage()
    ]);
    exit;
}
