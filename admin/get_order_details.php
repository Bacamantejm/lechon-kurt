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

// Fetch logistics tracking and assigned rider details for delivery orders
$tracking_info = null;
if ($order['delivery_option'] === 'delivery') {
    $tracking_stmt = mysqli_prepare($conn, "
        SELECT lt.*, 
               r.rider_code, r.vehicle_type, r.vehicle_plate,
               COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), 'Assigned Rider') AS driver_display_name,
               COALESCE(NULLIF(lt.driver_phone, ''), u_rdr.phone, e_rdr.phone, '') AS driver_display_phone
        FROM logistics_tracking lt
        LEFT JOIN riders r ON lt.driver_id = r.id
        LEFT JOIN users u_rdr ON r.user_id = u_rdr.id
        LEFT JOIN employees e_rdr ON r.employee_id = e_rdr.id
        WHERE lt.order_id = ?
        LIMIT 1
    ");
    if ($tracking_stmt) {
        mysqli_stmt_bind_param($tracking_stmt, "i", $order_id);
        mysqli_stmt_execute($tracking_stmt);
        $t_res = mysqli_stmt_get_result($tracking_stmt);
        $tracking_info = mysqli_fetch_assoc($t_res);
        mysqli_stmt_close($tracking_stmt);
    }
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
            <?php if (!empty($order['latitude']) && !empty($order['longitude'])): ?>
                <p><strong>Coordinates:</strong> <?php echo htmlspecialchars($order['latitude'] . ', ' . $order['longitude']); ?></p>
            <?php endif; ?>
            <?php if (!empty($order['estimated_delivery_time'])): ?>
                <p><strong>Estimated Delivery:</strong> <?php echo date('M d, Y h:i A', strtotime($order['estimated_delivery_time'])); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($order['delivery_option'] === 'delivery'): ?>
        <div class="card mb-3 border-0 shadow-sm" style="border: 1px solid #eaecf0 !important; border-radius: 12px; overflow: hidden;">
            <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center" style="background: #ffffff; border-bottom: 1px solid #eaecf0;">
                <span class="fw-bold small" style="color: #101828;">
                    <i class="fas fa-motorcycle text-danger me-1"></i> Delivery &amp; Rider Handover
                </span>
                <?php if ($tracking_info): ?>
                    <span class="badge" style="background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; font-size: 11px;">
                        Status: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tracking_info['current_status']))); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-3">
                <?php if ($tracking_info && !empty($tracking_info['driver_id'])): ?>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6 small">
                            <span class="text-muted">Assigned Rider:</span> 
                            <strong><?php echo htmlspecialchars($tracking_info['driver_display_name']); ?></strong>
                            <?php if (!empty($tracking_info['rider_code'])): ?>
                                <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars($tracking_info['rider_code']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6 small">
                            <span class="text-muted">Rider Phone:</span>
                            <?php if (!empty($tracking_info['driver_display_phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($tracking_info['driver_display_phone']); ?>" class="text-success text-decoration-none fw-semibold">
                                    <i class="fas fa-phone-alt me-1"></i><?php echo htmlspecialchars($tracking_info['driver_display_phone']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($order['delivery_pin'])): ?>
                        <div class="d-flex align-items-center justify-content-between p-2 rounded mb-2" style="background: #fff8f8; border: 1px solid #fee4e2;">
                            <span class="small fw-bold text-danger"><i class="fas fa-key me-1"></i> Delivery PIN:</span>
                            <strong style="letter-spacing: 2px; font-size: 1.15rem; color: #b3261e;"><?php echo htmlspecialchars($order['delivery_pin']); ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($tracking_info['current_status'], ['picked_up', 'on_the_way', 'arriving', 'delivered'])): ?>
                        <div class="p-2 rounded text-center small fw-semibold" style="background: #ecfdf3; color: #027a48; border: 1px solid #abefc6;">
                            <i class="fas fa-check-circle me-1"></i> Handover Confirmed &bull; Picked up at <?php echo !empty($tracking_info['pickup_time']) ? date('M d, Y h:i A', strtotime($tracking_info['pickup_time'])) : date('h:i A'); ?>
                        </div>
                    <?php elseif ($tracking_info['current_status'] === 'arrived_at_restaurant'): ?>
                        <div class="p-3 rounded mb-2" style="background: #fffbeb; border: 1px solid #fedf89;">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div>
                                    <div class="fw-bold text-dark"><i class="fas fa-motorcycle text-warning me-1"></i> Rider Arrived at Store!</div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($tracking_info['driver_display_name']); ?> is waiting to pick up this order.</div>
                                </div>
                                <span class="badge" style="background:#fef08a; color:#854d0e; border:1px solid #fde047;">Waiting Handover</span>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success fw-bold flex-fill py-2" onclick="approveRiderHandover(<?php echo (int)$order['id']; ?>)">
                                    <i class="fas fa-check-circle me-1"></i> Approve Handover
                                </button>
                                <button type="button" class="btn btn-outline-danger fw-bold px-3 py-2" onclick="rejectRiderHandover(<?php echo (int)$order['id']; ?>)">
                                    <i class="fas fa-times-circle me-1"></i> Reject
                                </button>
                            </div>
                        </div>
                        <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Or Verify with PIN / Code
                            </label>
                            <div class="input-group">
                                <input type="text" id="detailHandoverCode" class="form-control fw-bold form-control-sm" placeholder="Enter PIN (<?php echo htmlspecialchars($order['delivery_pin'] ?? ''); ?>) or Rider Code" value="<?php echo htmlspecialchars($order['delivery_pin'] ?? ''); ?>">
                                <button type="button" class="btn btn-sm btn-outline-success fw-bold px-2" onclick="submitDetailHandover(<?php echo (int)$order['id']; ?>)">
                                    <i class="fas fa-check me-1"></i> Verify
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-3 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Enter Confirmation Code to Release Order
                            </label>
                            <div class="input-group mb-1">
                                <input type="text" id="detailHandoverCode" class="form-control fw-bold" placeholder="Enter PIN (<?php echo htmlspecialchars($order['delivery_pin'] ?? ''); ?>) or Rider Code" value="<?php echo htmlspecialchars($order['delivery_pin'] ?? ''); ?>">
                                <button type="button" class="btn btn-success fw-bold px-3" onclick="submitDetailHandover(<?php echo (int)$order['id']; ?>)">
                                    <i class="fas fa-check-circle me-1"></i> Confirm Pickup
                                </button>
                            </div>
                            <div class="text-muted" style="font-size: 11px;">
                                Confirming the handover will immediately notify the customer and advance the rider to customer delivery mode.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i> No rider has been assigned yet. When a rider accepts the order, you will be able to verify their code and confirm handover here.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $pod_path = $tracking_info['proof_of_delivery_path'] ?? '';
        if (empty($pod_path)) {
            $pod_chk = mysqli_query($conn, "SELECT photo_path FROM proof_of_delivery WHERE order_id = " . (int)$order['id'] . " ORDER BY id DESC LIMIT 1");
            if ($pod_chk && ($pod_r = mysqli_fetch_assoc($pod_chk))) {
                $pod_path = $pod_r['photo_path'];
            }
        }
        ?>
        <?php if (!empty($pod_path)): ?>
            <?php
            $pod_file = basename($pod_path);
            $pod_display_url = '../uploads/proof_of_delivery/' . $pod_file;
            ?>
            <div class="card mb-3 border-0 shadow-sm" style="border: 1px solid #eaecf0 !important; border-radius: 12px; overflow: hidden;">
                <div class="card-header bg-white py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center border-bottom">
                    <span><i class="fas fa-camera text-success me-1"></i> Proof of Delivery Photo</span>
                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6;"><i class="fas fa-check-circle"></i> Verified Delivered</span>
                </div>
                <div class="card-body p-3 text-center">
                    <a href="<?php echo htmlspecialchars($pod_display_url); ?>" target="_blank" title="Click to view full image">
                        <img src="<?php echo htmlspecialchars($pod_display_url); ?>" alt="Proof of Delivery" style="max-height: 240px; max-width: 100%; border-radius: 8px; border: 1px solid #eaecf0; object-fit: cover; box-shadow: 0 1px 3px rgba(16,24,40,0.06);" onerror="this.onerror=null;this.src='../assets/images/promo_lechon.jpg';">
                    </a>
                    <div class="mt-2 text-muted small">
                        <i class="fas fa-info-circle me-1"></i> Captured by rider upon handover to customer. Click image to open in full size.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
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
            <?php if (isset($order['platform_fee_amount']) && (float)$order['platform_fee_amount'] > 0): ?>
                <div class="summary-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #eaecf0; color: #475467;">
                    <span>Platform Fee (<?php echo htmlspecialchars($order['platform_fee_plan_name'] ?: 'Plan Rate'); ?> - <?php echo number_format((float)($order['platform_fee_rate_percent'] ?? 0), 2); ?>% + &#8369;<?php echo number_format((float)($order['platform_fee_flat'] ?? 0), 2); ?>):</span>
                    <span style="color: #b3261e;">-&#8369;<?php echo number_format((float)$order['platform_fee_amount'], 2); ?></span>
                </div>
                <div class="summary-row" style="font-weight: 600; color: #027a48;">
                    <span>Net Store Payout:</span>
                    <span>&#8369;<?php echo number_format((float)($order['net_seller_payout'] ?? ($partner_items_total - $order['platform_fee_amount'])), 2); ?></span>
                </div>
            <?php endif; ?>
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
            <?php if (isset($order['platform_fee_amount']) && ((float)$order['platform_fee_amount'] > 0 || (int)($order['seller_id'] ?? 0) > 0)): ?>
                <div class="summary-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #eaecf0; color: #475467;">
                    <span>Platform Fee (<?php echo htmlspecialchars($order['platform_fee_plan_name'] ?: 'Plan Rate'); ?> - <?php echo number_format((float)($order['platform_fee_rate_percent'] ?? 0), 2); ?>% + &#8369;<?php echo number_format((float)($order['platform_fee_flat'] ?? 0), 2); ?>):</span>
                    <span style="color: #b3261e;">-&#8369;<?php echo number_format((float)$order['platform_fee_amount'], 2); ?></span>
                </div>
                <div class="summary-row" style="font-weight: 600; color: #027a48;">
                    <span>Net Seller Payout:</span>
                    <span>&#8369;<?php echo number_format((float)($order['net_seller_payout'] ?? ($order['subtotal'] - $order['platform_fee_amount'])), 2); ?></span>
                </div>
            <?php endif; ?>
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
