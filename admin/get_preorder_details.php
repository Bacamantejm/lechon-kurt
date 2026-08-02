<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
requirePermission('preorders.view');

if (!isset($_GET['id'])) {
    die("Invalid pre-order");
}

$preorder_id = intval($_GET['id']);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
}

// Get pre-order details
$query = "SELECT po.*, u.full_name, u.email, u.phone 
          FROM pre_orders po 
          JOIN users u ON po.user_id = u.id" .
          ($seller_scope_id !== null ? " JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "") . "
          WHERE po.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $preorder_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$preorder = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$preorder) {
    die("Pre-order not found");
}

$preorder_subtotal = floatval($preorder['unit_price']) * intval($preorder['quantity']);
$preorder_total = floatval($preorder['total_price']);
if ($preorder_total + 0.01 < $preorder_subtotal) {
    $preorder_subtotal = $preorder_total;
}
$preorder_vat = round(max(0, $preorder_total - $preorder_subtotal), 2);
?>

<div class="order-details">
    <div class="order-header">
        <div>
            <h4>Pre-Order #<?php echo htmlspecialchars($preorder['id']); ?></h4>
            <a href="print_preorder_receipt.php?id=<?php echo (int)$preorder_id; ?>&print=1" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary mt-2">
                <i class="fas fa-print"></i> Print Receipt
            </a>
        </div>
        <span class="status-badge badge-<?php echo str_replace('_', '-', $preorder['reservation_status']); ?>"><?php echo ucwords(str_replace('_', ' ', $preorder['reservation_status'])); ?></span>
    </div>
    
    <div class="order-info-grid">
        <div class="info-section">
            <h6>Customer Information</h6>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($preorder['full_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($preorder['email']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($preorder['phone']); ?></p>
        </div>
        
        <div class="info-section">
            <h6>Reservation Details</h6>
            <p><strong>Product:</strong> <?php echo htmlspecialchars($preorder['product_name']); ?></p>
            <p><strong>Quantity:</strong> <?php echo $preorder['quantity']; ?></p>
            <p><strong>Pickup Date:</strong> <?php 
                $date_str = $preorder['preferred_pickup_date'];
                echo ($date_str && $date_str != '0000-00-00') ? date('M d, Y', strtotime($date_str)) : 'N/A'; 
            ?></p>
            <p><strong>Pickup Time:</strong> <?php echo htmlspecialchars($preorder['preferred_pickup_time']); ?></p>
        </div>
    </div>
    
    <div class="order-summary">
        <div class="summary-row">
            <span>Subtotal:</span>
            <span>&#8369;<?php echo number_format($preorder_subtotal, 2); ?></span>
        </div>
        <div class="summary-row">
            <span>VAT (12%):</span>
            <span>&#8369;<?php echo number_format($preorder_vat, 2); ?></span>
        </div>
        <div class="summary-row">
            <span>Total Price:</span>
            <span>&#8369;<?php echo number_format($preorder_total, 2); ?></span>
        </div>
        <div class="summary-row">
            <span>Payment Type:</span>
            <span><?php echo ucwords(str_replace('_', ' ', $preorder['payment_type'])); ?></span>
        </div>
        <?php if ($preorder['payment_type'] === 'downpayment'): ?>
            <div class="summary-row">
                <span>Downpayment (<?php echo $preorder['downpayment_status']; ?>):</span>
                <span>&#8369;<?php echo number_format($preorder['downpayment_amount'], 2); ?></span>
            </div>
            <div class="summary-row">
                <span>Remaining Balance (<?php echo $preorder['final_payment_status']; ?>):</span>
                <span>&#8369;<?php echo number_format($preorder['remaining_amount'], 2); ?></span>
            </div>
            <div class="summary-row">
                <span>Refund Rule:</span>
                <span class="text-danger">Downpayment is non-refundable.</span>
            </div>
        <?php else: ?>
             <div class="summary-row">
                <span>Full Payment Status:</span>
                <span><?php echo ucfirst($preorder['final_payment_status']); ?></span>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($preorder['special_instructions']): ?>
        <div class="special-instructions">
            <h6>Special Instructions</h6>
            <p><?php echo nl2br(htmlspecialchars($preorder['special_instructions'])); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($preorder['admin_notes']): ?>
        <div class="special-instructions mt-3" style="background-color: #fff9e6; border-color: #ffc107;">
            <h6 style="color: #856404;">Admin Notes</h6>
            <p><?php echo nl2br(htmlspecialchars($preorder['admin_notes'])); ?></p>
        </div>
    <?php endif; ?>
</div>

