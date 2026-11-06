<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';

checkAdminAccess();
requirePermission('preorders.view');

if (!isset($_GET['id'])) {
    die("<div class='p-3 text-danger fw-bold'><i class='fas fa-exclamation-circle me-1'></i> Invalid order reference.</div>");
}

$id = intval($_GET['id']);
$source = trim($_GET['source'] ?? '');
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_product_scope_sql = '';
$seller_scoped_orders_exists = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
    $seller_scoped_orders_exists = getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
}

$order_data = null;
$items = [];
$order_source = '';

// If source is explicitly 'order' or if not specified, attempt order lookup first if source == 'order'
if ($source === 'order') {
    $ord_query = "SELECT o.*, sl.store_name, sl.address AS store_address, sl.city AS store_city, sl.province AS store_province, sl.phone AS store_phone
                  FROM orders o
                  LEFT JOIN store_locations sl ON (sl.store_id = o.pickup_location OR sl.store_name = o.pickup_location OR sl.owner_user_id = o.seller_id" . ($seller_scope_id !== null ? " OR sl.owner_user_id = {$seller_scope_id}" : "") . ")
                  WHERE o.id = ?" . ($seller_scope_id !== null ? " AND (o.seller_id = {$seller_scope_id} OR sl.owner_user_id = {$seller_scope_id} OR {$seller_scoped_orders_exists})" : "") . "
                  LIMIT 1";
    $stmt = mysqli_prepare($conn, $ord_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order_data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if ($order_data) {
        $order_source = 'order';
        $items_stmt = mysqli_prepare($conn, "SELECT oi.* FROM order_items oi WHERE oi.order_id = ?");
        if ($items_stmt) {
            mysqli_stmt_bind_param($items_stmt, "i", $id);
            mysqli_stmt_execute($items_stmt);
            $items_res = mysqli_stmt_get_result($items_stmt);
            while ($item_row = mysqli_fetch_assoc($items_res)) {
                $items[] = $item_row;
            }
            mysqli_stmt_close($items_stmt);
        }
    }
}

// Fallback or explicit 'pre_order' lookup
if (!$order_data) {
    $po_query = "SELECT po.*, u.full_name, u.email, u.phone,
                        sl.store_name, sl.address AS store_address, sl.city AS store_city, sl.province AS store_province, sl.phone AS store_phone
                 FROM pre_orders po 
                 JOIN users u ON po.user_id = u.id
                 LEFT JOIN products p ON po.product_id = p.id
                 LEFT JOIN store_locations sl ON sl.owner_user_id = p.seller_id" .
                 ($seller_scope_id !== null ? " JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "") . "
                 WHERE po.id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $po_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order_data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if ($order_data) {
        $order_source = 'pre_order';
        $items[] = [
            'product_name' => $order_data['product_name'],
            'quantity' => (int)$order_data['quantity'],
            'price' => (float)$order_data['unit_price'],
            'total' => (float)$order_data['total_price']
        ];
    }
}

// If still not found and source was empty, check orders
if (!$order_data && $source === '') {
    $ord_query = "SELECT o.*, sl.store_name, sl.address AS store_address, sl.city AS store_city, sl.province AS store_province, sl.phone AS store_phone
                  FROM orders o
                  LEFT JOIN store_locations sl ON (sl.store_id = o.pickup_location OR sl.store_name = o.pickup_location OR sl.owner_user_id = o.seller_id" . ($seller_scope_id !== null ? " OR sl.owner_user_id = {$seller_scope_id}" : "") . ")
                  WHERE o.id = ?" . ($seller_scope_id !== null ? " AND (o.seller_id = {$seller_scope_id} OR sl.owner_user_id = {$seller_scope_id} OR {$seller_scoped_orders_exists})" : "") . "
                  LIMIT 1";
    $stmt = mysqli_prepare($conn, $ord_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order_data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if ($order_data) {
        $order_source = 'order';
        $items_stmt = mysqli_prepare($conn, "SELECT oi.* FROM order_items oi WHERE oi.order_id = ?");
        if ($items_stmt) {
            mysqli_stmt_bind_param($items_stmt, "i", $id);
            mysqli_stmt_execute($items_stmt);
            $items_res = mysqli_stmt_get_result($items_stmt);
            while ($item_row = mysqli_fetch_assoc($items_res)) {
                $items[] = $item_row;
            }
            mysqli_stmt_close($items_stmt);
        }
    }
}

if (!$order_data) {
    die("<div class='p-4 text-center text-danger'><i class='fas fa-exclamation-triangle fa-2x mb-2'></i><p class='mb-0 fw-bold'>Pick-up order not found or you lack permission to view this order.</p></div>");
}

// Normalize fields
if ($order_source === 'order') {
    $order_id = (int)$order_data['id'];
    $display_order_id = htmlspecialchars($order_data['order_number'] ?: ('#ORD-' . $order_id));
    $customer_name = htmlspecialchars($order_data['customer_name'] ?? 'N/A');
    $customer_email = htmlspecialchars($order_data['customer_email'] ?? 'N/A');
    $customer_phone = htmlspecialchars($order_data['customer_phone'] ?? 'N/A');
    $pickup_date = $order_data['delivery_date'] ?? '';
    $pickup_time = $order_data['delivery_time'] ?? '';
    $raw_status = $order_data['status'] ?? 'pending';
    
    // Status mapping
    $status_map = [
        'pending' => 'pending',
        'confirmed' => 'confirmed',
        'preparing' => 'in_preparation',
        'ready_for_pickup' => 'ready_for_pickup',
        'delivered' => 'completed',
        'cancelled' => 'cancelled'
    ];
    $norm_status = $status_map[$raw_status] ?? $raw_status;
    $status_label = ucwords(str_replace('_', ' ', $norm_status));
    
    $subtotal = floatval($order_data['subtotal'] ?? 0);
    $total_amount = floatval($order_data['total_amount'] ?? 0);
    $vat_amount = round(max(0, $total_amount - $subtotal), 2);
    $payment_method = ucwords(str_replace('_', ' ', $order_data['payment_method'] ?? 'COD'));
    $payment_status = ucfirst($order_data['payment_status'] ?? 'pending');
    $downpayment_amount = floatval($order_data['downpayment_amount'] ?? 0);
    $remaining_balance = floatval($order_data['remaining_balance'] ?? 0);
    $remaining_amount = $remaining_balance;
    $special_instructions = trim((string)($order_data['special_instructions'] ?? ''));
    $admin_notes = '';
    $store_name = htmlspecialchars($order_data['store_name'] ?? '');
    if (!$store_name && $seller_scope_id !== null) {
        $sl_res = mysqli_query($conn, "SELECT store_name, address, city, phone FROM store_locations WHERE owner_user_id = {$seller_scope_id} ORDER BY is_active DESC LIMIT 1");
        if ($sl_res && ($sl_row = mysqli_fetch_assoc($sl_res))) {
            $store_name = htmlspecialchars($sl_row['store_name'] ?? 'Robin\'s lechon');
            if (empty($order_data['store_address'])) {
                $order_data['store_address'] = $sl_row['address'] ?? '';
                $order_data['store_city'] = $sl_row['city'] ?? '';
            }
            if (empty($order_data['store_phone'])) {
                $order_data['store_phone'] = $sl_row['phone'] ?? '';
            }
        }
    }
    if (!$store_name) {
        $store_name = 'Main Store';
    }
    $store_address = htmlspecialchars(trim(($order_data['store_address'] ?? '') . ' ' . ($order_data['store_city'] ?? '')));
    $store_phone = htmlspecialchars($order_data['store_phone'] ?? '');
    $print_url = "print_order_receipt.php?id={$order_id}&print=1";
} else {
    // Pre-order
    $order_id = (int)$order_data['id'];
    $display_order_id = '#PO-' . $order_id;
    $customer_name = htmlspecialchars($order_data['full_name'] ?? 'N/A');
    $customer_email = htmlspecialchars($order_data['email'] ?? 'N/A');
    $customer_phone = htmlspecialchars($order_data['phone'] ?? 'N/A');
    $pickup_date = $order_data['preferred_pickup_date'] ?? '';
    $pickup_time = $order_data['preferred_pickup_time'] ?? '';
    $norm_status = $order_data['reservation_status'] ?? 'pending';
    $status_label = ucwords(str_replace('_', ' ', $norm_status));
    
    $subtotal = floatval($order_data['unit_price']) * intval($order_data['quantity']);
    $total_amount = floatval($order_data['total_price']);
    $vat_amount = round(max(0, $total_amount - $subtotal), 2);
    $payment_method = ucwords(str_replace('_', ' ', $order_data['payment_type'] ?? 'full_payment'));
    $payment_status = ucfirst($order_data['final_payment_status'] ?? 'pending');
    $downpayment_amount = floatval($order_data['downpayment_amount'] ?? 0);
    $remaining_amount = floatval($order_data['remaining_amount'] ?? 0);
    $special_instructions = trim((string)($order_data['special_instructions'] ?? ''));
    $admin_notes = trim((string)($order_data['admin_notes'] ?? ''));
    $store_name = htmlspecialchars($order_data['pickup_location'] ?: ($order_data['store_name'] ?: 'Main Store'));
    $store_address = htmlspecialchars(trim(($order_data['store_address'] ?? '') . ' ' . ($order_data['store_city'] ?? '')));
    $store_phone = htmlspecialchars($order_data['store_phone'] ?? '');
    $print_url = "print_preorder_receipt.php?id={$order_id}&print=1";
}

$formatted_pickup_date = ($pickup_date && $pickup_date !== '0000-00-00') ? date('l, F j, Y', strtotime($pickup_date)) : 'To be confirmed';
$formatted_pickup_time = !empty($pickup_time) ? htmlspecialchars($pickup_time) : 'Store Business Hours';

// Status badge styling class
$status_badge_classes = [
    'pending' => 'background:#fffaeb; color:#b54708; border:1px solid #fedf89;',
    'confirmed' => 'background:#eff8ff; color:#175cd3; border:1px solid #b2ddff;',
    'in_preparation' => 'background:#fdf2fa; color:#c11574; border:1px solid #fce7f6;',
    'ready_for_pickup' => 'background:#f0fdf9; color:#0e9384; border:1px solid #ccfbe7;',
    'completed' => 'background:#ecfdf3; color:#027a48; border:1px solid #abefc6;',
    'cancelled' => 'background:#fff1f0; color:#b3261e; border:1px solid #fee4e2;'
];
$badge_style = $status_badge_classes[$norm_status] ?? 'background:#f2f4f7; color:#344054; border:1px solid #d0d5dd;';
?>

<div class="pickup-summary-modal-wrap">
    <!-- Header Banner -->
    <div class="pickup-summary-header d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-3 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h4 class="mb-0 fw-bold text-dark"><?php echo $display_order_id; ?></h4>
                <span class="badge" style="background:#fef3f2; color:#b3261e; border:1px solid #fee4e2; font-weight:600;">
                    <i class="fas fa-store me-1"></i> Pick-up Order
                </span>
            </div>
            <div class="text-muted small">
                Source: <?php echo ($order_source === 'order') ? 'Direct Customer Checkout' : 'Advance Pre-Order Booking'; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge px-3 py-2 fw-semibold" style="<?php echo $badge_style; ?> font-size: 0.85rem; border-radius: 20px;">
                <i class="fas fa-circle me-1" style="font-size: 0.5rem; vertical-align: middle;"></i> <?php echo $status_label; ?>
            </span>
            <a href="<?php echo $print_url; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-print me-1"></i> Print Receipt
            </a>
        </div>
    </div>

    <!-- Pickup Schedule & Branch Highlight Box -->
    <div class="card mb-3 border" style="background-color: #fffbfa; border-color: #fecdca !important; border-radius: 12px;">
        <div class="card-body p-3">
            <div class="row g-3">
                <div class="col-sm-6 col-md-4">
                    <span class="text-muted small d-block mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                        <i class="fas fa-calendar-day text-danger me-1"></i> Pickup Date
                    </span>
                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo $formatted_pickup_date; ?></strong>
                </div>
                <div class="col-sm-6 col-md-4">
                    <span class="text-muted small d-block mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                        <i class="fas fa-clock text-danger me-1"></i> Preferred Time
                    </span>
                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo $formatted_pickup_time; ?></strong>
                </div>
                <div class="col-sm-12 col-md-4">
                    <span class="text-muted small d-block mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                        <i class="fas fa-map-marker-alt text-danger me-1"></i> Pick-up Location
                    </span>
                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo $store_name; ?></strong>
                    <?php if ($store_address): ?>
                        <small class="text-muted d-block text-truncate" title="<?php echo $store_address; ?>"><?php echo $store_address; ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Information -->
    <div class="card mb-3 border-0" style="background: #ffffff; border: 1px solid #eaecf0 !important; border-radius: 12px;">
        <div class="card-body p-3">
            <h6 class="fw-bold text-dark mb-2 pb-2 border-bottom" style="font-size: 0.9rem;">
                <i class="fas fa-user-check text-muted me-1"></i> Customer Contact Information
            </h6>
            <div class="row g-2 pt-1">
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Full Name</span>
                    <strong class="text-dark"><?php echo $customer_name; ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Phone Number</span>
                    <div class="d-flex align-items-center gap-2">
                        <strong class="text-dark"><?php echo $customer_phone; ?></strong>
                        <?php if ($customer_phone && $customer_phone !== 'N/A'): ?>
                            <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $customer_phone); ?>" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 0.75rem;">
                                <i class="fas fa-phone-alt me-1"></i> Call
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <span class="text-muted small d-block">Email Address</span>
                    <span class="text-secondary"><?php echo $customer_email; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items Breakdown -->
    <div class="card mb-3 border-0" style="background: #ffffff; border: 1px solid #eaecf0 !important; border-radius: 12px;">
        <div class="card-body p-0">
            <div class="p-3 border-bottom">
                <h6 class="fw-bold text-dark mb-0" style="font-size: 0.9rem;">
                    <i class="fas fa-utensils text-muted me-1"></i> Ordered Lechon Items Breakdown
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size: 0.88rem;">
                    <thead style="background: #f8f9fa; color: #475467;">
                        <tr>
                            <th class="ps-3 py-2">Item Description</th>
                            <th class="text-center py-2">Qty</th>
                            <th class="text-end py-2">Unit Price</th>
                            <th class="text-end pe-3 py-2">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-3 py-2">
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <?php if (!empty($item['size'])): ?>
                                            <small class="text-muted">Size: <?php echo htmlspecialchars($item['size']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($item['addons'])): ?>
                                            <small class="text-muted d-block">Add-ons: <?php echo htmlspecialchars($item['addons']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center py-2">
                                        <span class="badge bg-light text-dark border"><?php echo (int)$item['quantity']; ?></span>
                                    </td>
                                    <td class="text-end py-2">&#8369;<?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                                    <td class="text-end pe-3 py-2 fw-semibold">&#8369;<?php echo number_format((float)($item['total'] ?? (($item['price'] ?? 0) * ($item['quantity'] ?? 1))), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">No individual items recorded.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Financial & Payment Summary -->
    <div class="card mb-3 border-0" style="background: #ffffff; border: 1px solid #eaecf0 !important; border-radius: 12px;">
        <div class="card-body p-3">
            <h6 class="fw-bold text-dark mb-2 pb-2 border-bottom" style="font-size: 0.9rem;">
                <i class="fas fa-receipt text-muted me-1"></i> Payment & Financial Summary
            </h6>
            <div class="row g-2">
                <div class="col-sm-6">
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted small">Subtotal:</span>
                        <span class="fw-medium text-dark">&#8369;<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted small">VAT (12%):</span>
                        <span class="fw-medium text-dark">&#8369;<?php echo number_format($vat_amount, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted small">Pick-up Fee:</span>
                        <span class="text-success fw-medium">&#8369;0.00 (Free)</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-top mt-1">
                        <strong class="text-dark">Total Order Amount:</strong>
                        <strong class="text-danger" style="font-size: 1.15rem; color: #b3261e !important;">&#8369;<?php echo number_format($total_amount, 2); ?></strong>
                    </div>
                </div>
                <div class="col-sm-6 ps-sm-4 border-start-sm">
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted small">Payment Method:</span>
                        <span class="fw-semibold text-dark"><?php echo $payment_method; ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted small">Payment Status:</span>
                        <span class="badge <?php echo in_array(strtolower($payment_status), ['paid', 'completed']) ? 'bg-success' : 'bg-warning text-dark'; ?>">
                            <?php echo $payment_status; ?>
                        </span>
                    </div>
                    <?php if ($downpayment_amount > 0): ?>
                        <div class="d-flex justify-content-between py-1 border-top mt-1">
                            <span class="text-muted small">Downpayment Paid:</span>
                            <span class="text-success fw-semibold">&#8369;<?php echo number_format($downpayment_amount, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span class="text-muted small">Remaining to Collect:</span>
                            <span class="text-danger fw-bold">&#8369;<?php echo number_format($remaining_amount ?: ($total_amount - $downpayment_amount), 2); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Special Instructions & Admin Notes -->
    <?php if ($special_instructions || $admin_notes): ?>
        <div class="card mb-3 border-0" style="background: #ffffff; border: 1px solid #eaecf0 !important; border-radius: 12px;">
            <div class="card-body p-3">
                <?php if ($special_instructions): ?>
                    <div class="mb-2">
                        <span class="text-muted small d-block fw-semibold mb-1">
                            <i class="fas fa-comment-alt text-muted me-1"></i> Customer Special Instructions:
                        </span>
                        <div class="p-2 bg-light rounded text-dark small">
                            <?php echo nl2br(htmlspecialchars($special_instructions)); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($admin_notes): ?>
                    <div>
                        <span class="text-warning small d-block fw-semibold mb-1">
                            <i class="fas fa-sticky-note text-warning me-1"></i> Store Processing Remarks:
                        </span>
                        <div class="p-2 bg-warning-subtle rounded text-dark small">
                            <?php echo nl2br(htmlspecialchars($admin_notes)); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Footer Actions -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3 border-top mt-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i> Close
        </button>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showUpdateStatus(<?php echo $order_id; ?>, '<?php echo $order_source; ?>', '<?php echo $norm_status; ?>')">
                <i class="fas fa-edit me-1"></i> Update Status
            </button>
            <a href="<?php echo $print_url; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-danger" style="background-color: #b3261e; border-color: #b3261e;">
                <i class="fas fa-print me-1"></i> Print Receipt
            </a>
        </div>
    </div>
</div>
