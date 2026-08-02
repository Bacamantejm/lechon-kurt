<?php
session_start();
require_once 'includes/config.php';

$preorder_id = isset($_GET['preorder_id']) ? intval($_GET['preorder_id']) : 0;

if (!$preorder_id) {
    header("Location: index.php?error=invalid_order");
    exit;
}

// Get preorder details
$query = "SELECT * FROM pre_orders WHERE id = ? AND user_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $preorder_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$preorder = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$preorder) {
    header("Location: index.php?error=order_not_found");
    exit;
}

$session_id = trim((string)($preorder['paymongo_session_id'] ?? ''));
$linked_ids = [$preorder_id];
if ($session_id !== '') {
    $linked_query = "SELECT id FROM pre_orders WHERE user_id = ? AND paymongo_session_id = ?";
    $linked_stmt = mysqli_prepare($conn, $linked_query);
    mysqli_stmt_bind_param($linked_stmt, "is", $_SESSION['user_id'], $session_id);
    mysqli_stmt_execute($linked_stmt);
    $linked_result = mysqli_stmt_get_result($linked_stmt);
    $linked_ids = [];
    while ($linked_result && ($linked_row = mysqli_fetch_assoc($linked_result))) {
        $linked_ids[] = (int)($linked_row['id'] ?? 0);
    }
    mysqli_stmt_close($linked_stmt);
}
$linked_ids = array_values(array_filter($linked_ids));
if (empty($linked_ids)) {
    $linked_ids = [$preorder_id];
}

$placeholders = implode(',', array_fill(0, count($linked_ids), '?'));
$types = str_repeat('i', count($linked_ids));
$update_query = "UPDATE pre_orders
                 SET reservation_status = 'cancelled',
                     cancellation_reason = 'Payment not completed',
                     cancelled_at = NOW()
                 WHERE id IN ({$placeholders})";
$update_stmt = mysqli_prepare($conn, $update_query);
if ($update_stmt) {
    $bind_values = $linked_ids;
    $bind_refs = [];
    foreach ($bind_values as $k => $v) {
        $bind_refs[$k] = &$bind_values[$k];
    }
    array_unshift($bind_refs, $types);
    call_user_func_array([$update_stmt, 'bind_param'], $bind_refs);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
}

if (function_exists('createNotification')) {
    $customer_msg = "Payment was cancelled for Pre-Order transaction #{$preorder_id}.";
    createNotification($conn, (int)$_SESSION['user_id'], 'preorder_payment_cancelled', 'Pre-Order Payment Cancelled', $customer_msg, (int)$preorder_id, 'pre_order');

    if (function_exists('getAdminUserIds')) {
        $admin_ids = getAdminUserIds($conn);
        $admin_msg = "Customer cancelled payment for Pre-Order transaction #{$preorder_id}.";
        foreach ($admin_ids as $admin_id) {
            createNotification($conn, (int)$admin_id, 'preorder_payment_cancelled', 'Pre-Order Payment Cancelled', $admin_msg, (int)$preorder_id, 'pre_order');
        }
    }
}

$current_page = 'preorder-cancel';
$page_title = "Pre-Order Cancelled | Lechon Delights";
include 'includes/header.php';
?>

<div style="max-width: 900px; margin: 60px auto; padding: 20px;">
    <div style="background: white; border-radius: 15px; padding: 40px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); text-align: center;">
        <div style="font-size: 4rem; color: #ff9800; margin-bottom: 20px;">
            <i class="fas fa-times-circle"></i>
        </div>
        
        <h1 style="color: #333; margin-bottom: 10px;">Pre-Order Cancelled</h1>
        <p style="color: #666; font-size: 1.1rem; margin-bottom: 30px;">Your payment was not completed. The pre-order has been cancelled.</p>
        
        <div style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 5px solid #ff9800; margin-bottom: 30px; text-align: left;">
            <p style="margin: 0; color: #856404;">
                <strong>Pre-Order #<?php echo str_pad($preorder['id'], 6, '0', STR_PAD_LEFT); ?></strong><br>
                Product: <?php echo htmlspecialchars($preorder['product_name']); ?><br>
                This order was not completed and has been cancelled.
            </p>
        </div>
        
        <p style="color: #666; margin-bottom: 20px;">
            You can try placing a new pre-order or contact our support team if you need assistance.
        </p>
        
        <div style="display: flex; gap: 15px; justify-content: center;">
            <a href="preorder.php" class="btn" style="display: inline-block; padding: 12px 30px; background: #c62828; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.3s ease;">
                Try Again
            </a>
            <a href="index.php" class="btn" style="display: inline-block; padding: 12px 30px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 8px; font-weight: 600; border: 2px solid #e0e0e0; transition: all 0.3s ease;">
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
