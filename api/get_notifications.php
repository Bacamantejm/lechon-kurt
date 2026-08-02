<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../admin/auth.php';
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

$tenant_scope_sql = '';
if (
    function_exists('isApprovedFranchiseSellerAccount')
    && function_exists('getFranchiseSellerScopeOwnerId')
    && function_exists('getFranchiseScopedOrderExistsSql')
    && function_exists('getFranchiseScopedPreOrderExistsSql')
    && isApprovedFranchiseSellerAccount($conn, (int)$user_id)
) {
    $seller_scope_id = (int)(getFranchiseSellerScopeOwnerId($conn, (int)$user_id) ?? 0);
    if ($seller_scope_id > 0) {
        $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_id, 'n_scope.related_id');
        $pre_order_scope_sql = getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_id, 'n_scope.related_id');
        $tenant_scope_sql = " AND (
            n_scope.related_type IS NULL
            OR n_scope.related_type = ''
            OR n_scope.related_type NOT IN ('order','pre_order')
            OR (n_scope.related_type = 'order' AND {$order_scope_sql})
            OR (n_scope.related_type = 'pre_order' AND {$pre_order_scope_sql})
        )";
    }
}

if ($action === 'get_unread') {
    $stmt = $conn->prepare("SELECT n_scope.id, n_scope.title, n_scope.message, n_scope.type, n_scope.related_id, n_scope.created_at
                            FROM notifications n_scope
                            WHERE n_scope.user_id = ? AND n_scope.is_read = 0{$tenant_scope_sql}
                            ORDER BY n_scope.created_at DESC
                            LIMIT 10");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['notifications' => $notifications]);
    $stmt->close();
} elseif ($action === 'count') {
    $stmt = $conn->prepare("SELECT COUNT(*) AS unread_count
                            FROM notifications n_scope
                            WHERE n_scope.user_id = ? AND n_scope.is_read = 0{$tenant_scope_sql}");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo json_encode(['unread_count' => intval($row['unread_count'] ?? 0)]);
    $stmt->close();
} elseif ($action === 'mark_read' && isset($_GET['id'])) {
    $notif_id = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE notifications n_scope
                            SET n_scope.is_read = 1
                            WHERE n_scope.id = ? AND n_scope.user_id = ?{$tenant_scope_sql}");
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    $stmt->close();
} elseif ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications n_scope
                            SET n_scope.is_read = 1
                            WHERE n_scope.user_id = ?{$tenant_scope_sql}");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    $stmt->close();
} else {
    echo json_encode(['error' => 'Invalid action']);
}

$conn->close();
?>
