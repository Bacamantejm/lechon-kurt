<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once '../includes/security.php';
require_once '../preorder_service.php';
requireAnyPermission(['preorders.edit', 'logistics.update']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['update_preorder_status'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.']);
    exit;
}

$preorder_service = new PreOrderService($conn);
$pre_order_id = intval($_POST['pre_order_id']);
$new_status = trim($_POST['new_status']);
$admin_notes = trim($_POST['admin_notes'] ?? '');
$allowed_statuses = ['pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled'];
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
}

if ($pre_order_id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status update request.']);
    exit;
}

if ($seller_scope_id !== null) {
    $scope_check_query = "SELECT po.id
                          FROM pre_orders po
                          INNER JOIN products p_scope ON p_scope.id = po.product_id
                          WHERE po.id = ? AND {$partner_product_scope_sql}
                          LIMIT 1";
    $scope_check_stmt = mysqli_prepare($conn, $scope_check_query);
    mysqli_stmt_bind_param($scope_check_stmt, "i", $pre_order_id);
    mysqli_stmt_execute($scope_check_stmt);
    $scope_result = mysqli_stmt_get_result($scope_check_stmt);
    $scoped_preorder = $scope_result ? mysqli_fetch_assoc($scope_result) : null;
    mysqli_stmt_close($scope_check_stmt);

    if (!$scoped_preorder) {
        echo json_encode(['success' => false, 'message' => 'You can only update pre-orders for your own store.']);
        exit;
    }
}

$update_result = $preorder_service->updatePreOrderStatus($pre_order_id, $new_status, $admin_notes);

if ($update_result['success']) {
    echo json_encode([
        'success' => true, 
        'message' => (string)($update_result['message'] ?? 'Pre-order status updated!'),
        'new_status' => ucwords(str_replace('_', ' ', $new_status))
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => $update_result['message'] ?? 'An error occurred.'
    ]);
}
exit;
