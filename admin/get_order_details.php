<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
requirePermission('orders.view');

if (!isset($_GET['id'])) {
    die("Invalid order");
}

$order_id = intval($_GET['id']);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$seller_scoped_orders_exists = '';
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $seller_scoped_orders_exists = getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p.seller_id', (int)$seller_scope_id);
}

// Get order details (store-scoped for partner admins)
$order_query = "SELECT o.* FROM orders o WHERE o.id = ?" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
$stmt = mysqli_prepare($conn, $order_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$order_result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($order_result);
mysqli_stmt_close($stmt);

if (!$order) {
    die("Order not found");
}

$vat_rate = 0.12;
$computed_vat = round(floatval($order['subtotal']) * $vat_rate, 2);
$voucher_discount = (float)($order['voucher_discount'] ?? 0);
$computed_total_with_vat = floatval($order['subtotal']) + floatval($order['delivery_fee']) + $computed_vat - $voucher_discount;
$vat_amount = (abs(floatval($order['total_amount']) - $computed_total_with_vat) < 0.02)
    ? $computed_vat
    : round(max(0, floatval($order['total_amount']) + $voucher_discount - floatval($order['subtotal']) - floatval($order['delivery_fee'])), 2);

// Get order items (store-scoped for partner admins)
$items_query = "SELECT oi.*
                FROM order_items oi" . ($seller_scope_id !== null ? " INNER JOIN products p ON (
                    oi.product_id = p.product_id
                    OR oi.product_id = CAST(p.id AS CHAR)
                    OR CAST(oi.product_id AS UNSIGNED) = p.id
                )" : "") . "
                WHERE oi.order_id = ?" . ($seller_scope_id !== null ? " AND {$partner_product_scope_sql}" : "");
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$items = [];
$partner_items_total = 0.0;
while ($items_result && ($item_row = mysqli_fetch_assoc($items_result))) {
    $items[] = $item_row;
    $partner_items_total += (float)($item_row['total'] ?? 0);
}

// Get payment info (global admins only)
$payment = null;
if ($seller_scope_id === null) {
    $payment_query = "SELECT * FROM payments WHERE order_id = ?";
    $stmt = mysqli_prepare($conn, $payment_query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $payment_result = mysqli_stmt_get_result($stmt);
    $payment = mysqli_fetch_assoc($payment_result);
    mysqli_stmt_close($stmt);
}
?>

<div class="order-details">
    <div class="order-header">
        <div>
            <h4><?php echo htmlspecialchars($order['order_number']); ?></h4>
            <a href="print_order_receipt.php?id=<?php echo (int)$order_id; ?>&print=1" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary mt-2">
                <i class="fas fa-print"></i> Print Receipt
            </a>
        </div>
        <span class="status-badge badge-<?php echo str_replace(' ', '-', $order['status']); ?>"><?php echo $order['status']; ?></span>
    </div>
    
    <div class="order-info-grid">
        <div class="info-section">
            <h6>Customer Information</h6>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($order['customer_email']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
        </div>
        
        <div class="info-section">
            <h6>Delivery Information</h6>
            <p><strong>Type:</strong> <?php echo ucfirst($order['delivery_option']); ?></p>
            <p><strong>Address:</strong> <?php echo htmlspecialchars($order['delivery_address']); ?></p>
            <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['delivery_date'])); ?></p>
            <p><strong>Time:</strong> <?php echo $order['delivery_time'] ?? 'Not specified'; ?></p>
        </div>
    </div>
    
    <div class="order-items">
        <h6>Order Items<?php echo $seller_scope_id !== null ? ' (Your Store)' : ''; ?></h6>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo (int)$item['quantity']; ?></td>
                        <td>&#8369;<?php echo number_format((float)$item['price'], 2); ?></td>
                        <td>&#8369;<?php echo number_format((float)$item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No scoped items found for this order.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="order-summary">
        <?php if ($seller_scope_id !== null): ?>
            <div class="summary-row">
                <span>Store Items Count:</span>
                <span><?php echo count($items); ?></span>
            </div>
            <div class="summary-row total">
                <span>Store Items Total:</span>
                <span>&#8369;<?php echo number_format($partner_items_total, 2); ?></span>
            </div>
        <?php else: ?>
            <div class="summary-row">
                <span>Subtotal:</span>
                <span>&#8369;<?php echo number_format((float)$order['subtotal'], 2); ?></span>
            </div>
            <div class="summary-row">
                <span>Delivery Fee:</span>
                <span>&#8369;<?php echo number_format((float)$order['delivery_fee'], 2); ?></span>
            </div>
            <?php if ($voucher_discount > 0): ?>
                <div class="summary-row">
                    <span>Voucher Discount:</span>
                    <span>-&#8369;<?php echo number_format($voucher_discount, 2); ?></span>
                </div>
            <?php endif; ?>
            <div class="summary-row">
                <span>VAT (12%):</span>
                <span>&#8369;<?php echo number_format($vat_amount, 2); ?></span>
            </div>
            <div class="summary-row total">
                <span>Total Amount:</span>
                <span>&#8369;<?php echo number_format((float)$order['total_amount'], 2); ?></span>
            </div>
            <?php if ($payment): ?>
                <div class="summary-row">
                    <span>Payment Method:</span>
                    <span><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                </div>
                <div class="summary-row">
                    <span>Payment Status:</span>
                    <span class="status-badge badge-<?php echo $order['payment_status']; ?>"><?php echo $order['payment_status']; ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php if ($order['special_instructions']): ?>
        <div class="special-instructions">
            <h6>Special Instructions</h6>
            <p><?php echo nl2br(htmlspecialchars($order['special_instructions'])); ?></p>
        </div>
    <?php endif; ?>
</div>
