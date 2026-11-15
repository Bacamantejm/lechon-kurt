<?php
/**
 * supplier/request_details.php
 * Supplier Request Review, Dynamic Quoting/Modification, & Automated Restock Fulfillment.
 */

$page_title = "Review Refill Request | Supplier Portal";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/IngredientStockManager.php';

$order_id = intval($_GET['id'] ?? 0);
if ($order_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

if (!function_exists('formatQtyVal')) {
    function formatQtyVal($num) {
        $f = floatval($num);
        if ($f == intval($f)) {
            return number_format($f, 0);
        }
        return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    }
}

// 1. Fetch Order Header
$ord_stmt = mysqli_prepare($conn, "
    SELECT so.*, 
           COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(u.full_name), ''), 'Franchise Store') AS store_name,
           u.full_name AS owner_name,
           u.phone AS store_phone,
           u.email AS store_email,
           u.address AS store_address
    FROM supplier_orders so
    LEFT JOIN users u ON u.id = so.store_id
    WHERE so.id = ? AND so.supplier_id = ?
    LIMIT 1
");

if (!$ord_stmt) {
    die("Database prepare error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($ord_stmt, "ii", $order_id, $current_supplier_id);
mysqli_stmt_execute($ord_stmt);
$ord_res = mysqli_stmt_get_result($ord_stmt);
$order = mysqli_fetch_assoc($ord_res);
mysqli_stmt_close($ord_stmt);

if (!$order) {
    $_SESSION['error'] = "Procurement request not found or does not belong to your vendor account.";
    header("Location: dashboard.php");
    exit;
}

// 2. Handle Action POSTs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    // ACTION: Send Quoted Invoice to Finance Department (Supplier inputs prices and quantities)
    if ($action === 'send_invoice_to_finance' || $action === 'modify_and_send_finance' || $action === 'modify_accept') {
        $mod_items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
        $supplier_notes = trim((string)($_POST['supplier_notes'] ?? ''));
        $delivery_fee = max(0.0, floatval($_POST['delivery_fee'] ?? 0));

        $new_subtotal = 0.0;
        if (!empty($mod_items)) {
            $upd_item_stmt = mysqli_prepare($conn, "
                UPDATE supplier_order_items 
                SET quantity_supplied = ?, 
                    locked_unit_price = ?, 
                    line_total = ?, 
                    is_modified = ?, 
                    modification_reason = ? 
                WHERE id = ? AND order_id = ?
            ");

            if ($upd_item_stmt) {
                foreach ($mod_items as $item_id => $i_data) {
                    $i_id = intval($item_id);
                    $qty_supplied = max(0.0, floatval($i_data['quantity_supplied'] ?? 0));
                    $unit_price = max(0.0, floatval($i_data['locked_unit_price'] ?? 0));
                    $orig_qty = max(0.0, floatval($i_data['quantity_requested'] ?? 0));
                    $reason = trim((string)($i_data['modification_reason'] ?? ''));

                    $is_mod = (abs($qty_supplied - $orig_qty) > 0.0001) ? 1 : 0;
                    $line_tot = round($qty_supplied * $unit_price, 2);
                    $new_subtotal += $line_tot;

                    mysqli_stmt_bind_param($upd_item_stmt, "dddisii", $qty_supplied, $unit_price, $line_tot, $is_mod, $reason, $i_id, $order_id);
                    mysqli_stmt_execute($upd_item_stmt);
                }
                mysqli_stmt_close($upd_item_stmt);
            }
        } else {
            $it_calc = mysqli_query($conn, "SELECT line_total FROM supplier_order_items WHERE order_id = $order_id");
            while ($it_calc && ($row = mysqli_fetch_assoc($it_calc))) {
                $new_subtotal += floatval($row['line_total']);
            }
        }

        $new_grand_total = round($new_subtotal + $delivery_fee, 2);

        // Update Order totals & notes
        $upd_ord = mysqli_prepare($conn, "
            UPDATE supplier_orders 
            SET subtotal_amount = ?,
                delivery_fee = ?,
                total_amount = ?,
                supplier_notes = ?,
                updated_at = NOW()
            WHERE id = ? AND supplier_id = ?
        ");
        if ($upd_ord) {
            mysqli_stmt_bind_param($upd_ord, "dddsii", $new_subtotal, $delivery_fee, $new_grand_total, $supplier_notes, $order_id, $current_supplier_id);
            mysqli_stmt_execute($upd_ord);
            mysqli_stmt_close($upd_ord);
        }

        // Keep supplier catalog updated with vendor's wholesale price for future orders
        if (!empty($mod_items)) {
            $cat_sync = mysqli_prepare($conn, "
                INSERT INTO supplier_catalog (supplier_id, material_id, wholesale_price, packaging_unit, is_available, updated_at)
                VALUES (?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE wholesale_price = VALUES(wholesale_price), updated_at = NOW()
            ");
            if ($cat_sync) {
                foreach ($mod_items as $item_id => $i_data) {
                    $i_id = intval($item_id);
                    $unit_price = max(0.0, floatval($i_data['locked_unit_price'] ?? 0));
                    $m_res = mysqli_query($conn, "SELECT material_id, unit FROM supplier_order_items WHERE id = $i_id LIMIT 1");
                    if ($m_res && ($mr = mysqli_fetch_assoc($m_res))) {
                        $mat_id = intval($mr['material_id']);
                        $unit_str = $mr['unit'];
                        if ($mat_id > 0 && $unit_price > 0) {
                            mysqli_stmt_bind_param($cat_sync, "iids", $current_supplier_id, $mat_id, $unit_price, $unit_str);
                            mysqli_stmt_execute($cat_sync);
                        }
                    }
                }
                mysqli_stmt_close($cat_sync);
            }
        }

        if ($new_grand_total <= 0) {
            $_SESSION['error'] = "Please specify a unit price greater than ₱0.00 for the ingredients before sending invoice to Finance.";
            header("Location: request_details.php?id=$order_id");
            exit;
        }

        $submitted = IngredientStockManager::submitSupplierInvoiceToFinance($conn, $order_id, (int)$_SESSION['user_id'], $supplier_notes);
        if ($submitted) {
            $_SESSION['success'] = "Invoice for refill request {$order['order_code']} (Total: ₱" . number_format($new_grand_total, 2) . ") has been submitted to Finance Department for budget approval!";
        } else {
            $_SESSION['error'] = "Could not submit invoice to Finance Department. Please try again.";
        }
        header("Location: request_details.php?id=$order_id");
        exit;
    }

    // ACTION: Proceed to Pack & Dispatch Delivery (Allowed once Finance approves)
    if ($action === 'proceed_dispatch' || $action === 'proceed') {
        $upd_st = mysqli_prepare($conn, "UPDATE supplier_orders SET status = 'in_transit', dispatched_at = NOW(), updated_at = NOW() WHERE id = ? AND supplier_id = ?");
        if ($upd_st) {
            mysqli_stmt_bind_param($upd_st, "ii", $order_id, $current_supplier_id);
            mysqli_stmt_execute($upd_st);
            mysqli_stmt_close($upd_st);

            // Audit log
            mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                                 VALUES ($order_id, '{$order['status']}', 'in_transit', {$_SESSION['user_id']}, 'Supplier proceeded with delivery dispatch following Finance approval.')");

            // Notification to Store Owner
            $n_msg = "Your procurement order {$order['order_code']} has been dispatched by the supplier and is now in transit!";
            mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                                 VALUES ({$order['store_id']}, 'supplier_order_dispatched', 'Procurement In Transit', '$n_msg', 0, NOW())");

            $_SESSION['success'] = "Order {$order['order_code']} dispatched! Delivery is now in transit to the store.";
        }
        header("Location: request_details.php?id=$order_id");
        exit;
    }

    // ACTION: Reject Request
    if ($action === 'reject') {
        $reject_reason = trim((string)($_POST['reject_reason'] ?? 'Unavailable'));
        $rej_stmt = mysqli_prepare($conn, "
            UPDATE supplier_orders 
            SET status = 'rejected', 
                supplier_notes = ?, 
                updated_at = NOW() 
            WHERE id = ? AND supplier_id = ?
        ");
        if ($rej_stmt) {
            mysqli_stmt_bind_param($rej_stmt, "sii", $reject_reason, $order_id, $current_supplier_id);
            mysqli_stmt_execute($rej_stmt);
            mysqli_stmt_close($rej_stmt);

            // Audit log
            mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                                 VALUES ($order_id, '{$order['status']}', 'rejected', {$_SESSION['user_id']}, 'Rejected: $reject_reason')");

            // Notification
            $n_msg = "Your refill request {$order['order_code']} was rejected by the supplier. Reason: $reject_reason";
            mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                                 VALUES ({$order['store_id']}, 'supplier_order_rejected', 'Refill Request Rejected', '$n_msg', 0, NOW())");

            $_SESSION['success'] = "Refill request {$order['order_code']} marked as rejected.";
        }
        header("Location: request_details.php?id=$order_id");
        exit;
    }

    // ACTION: Transition Status (Processing -> Packed -> In Transit)
    if (in_array($action, ['processing', 'packed', 'in_transit'])) {
        $dispatched_sql = ($action === 'in_transit') ? ", dispatched_at = NOW()" : "";
        $upd_st = mysqli_prepare($conn, "UPDATE supplier_orders SET status = ?, updated_at = NOW() $dispatched_sql WHERE id = ? AND supplier_id = ?");
        if ($upd_st) {
            mysqli_stmt_bind_param($upd_st, "sii", $action, $order_id, $current_supplier_id);
            mysqli_stmt_execute($upd_st);
            mysqli_stmt_close($upd_st);

            // Audit log
            mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                                 VALUES ($order_id, '{$order['status']}', '$action', {$_SESSION['user_id']}, 'Status updated to $action')");

            // Notification
            $readable_status = ucfirst(str_replace('_', ' ', $action));
            $n_msg = "Your refill order {$order['order_code']} is now $readable_status.";
            mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                                 VALUES ({$order['store_id']}, 'supplier_order_status', 'Refill Order Update: $readable_status', '$n_msg', 0, NOW())");

            $_SESSION['success'] = "Order status transitioned to $readable_status.";
        }
        header("Location: request_details.php?id=$order_id");
        exit;
    }

    // ACTION: Mark Completed / Delivered -> AUTOMATIC RESTOCKING
    if ($action === 'complete') {
        // Fetch order items to restock
        $fetch_items_q = mysqli_prepare($conn, "SELECT material_id, quantity_supplied, unit FROM supplier_order_items WHERE order_id = ?");
        $items_to_restock = [];
        if ($fetch_items_q) {
            mysqli_stmt_bind_param($fetch_items_q, "i", $order_id);
            mysqli_stmt_execute($fetch_items_q);
            $fi_res = mysqli_stmt_get_result($fetch_items_q);
            while ($fi = mysqli_fetch_assoc($fi_res)) {
                $items_to_restock[] = $fi;
            }
            mysqli_stmt_close($fetch_items_q);
        }

        // 1. Mark order completed
        $comp_stmt = mysqli_prepare($conn, "UPDATE supplier_orders SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ? AND supplier_id = ?");
        if ($comp_stmt) {
            mysqli_stmt_bind_param($comp_stmt, "ii", $order_id, $current_supplier_id);
            mysqli_stmt_execute($comp_stmt);
            mysqli_stmt_close($comp_stmt);
        }

        // 2. Trigger automatic inventory restock and menu reactivation
        IngredientStockManager::restockIngredients($conn, $order['store_id'], $items_to_restock, $order['order_code']);

        // 3. Audit log
        mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                             VALUES ($order_id, '{$order['status']}', 'completed', {$_SESSION['user_id']}, 'Delivered & completed. Inventory automatically restocked.')");

        // 4. Shop owner notification
        $n_title = "Delivery Complete & Stock Restocked!";
        $n_msg = "Procurement order {$order['order_code']} has been marked delivered. Your ingredient stock levels have been automatically updated and applicable menu items reactivated!";
        mysqli_query($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) 
                             VALUES ({$order['store_id']}, 'supplier_delivery_completed', '$n_title', '$n_msg', 0, NOW())");

        $_SESSION['success'] = "Order {$order['order_code']} marked as Completed & Delivered. Shop owner inventory has been automatically restocked!";
        header("Location: request_details.php?id=$order_id");
        exit;
    }
}

// 3. Fetch Order Items
$items_stmt = mysqli_prepare($conn, "
    SELECT soi.*, m.name AS material_name, m.unit AS default_unit
    FROM supplier_order_items soi
    INNER JOIN materials m ON m.id = soi.material_id
    WHERE soi.order_id = ?
    ORDER BY soi.id ASC
");
$order_items = [];
if ($items_stmt) {
    mysqli_stmt_bind_param($items_stmt, "i", $order_id);
    mysqli_stmt_execute($items_stmt);
    $items_res = mysqli_stmt_get_result($items_stmt);
    while ($it = mysqli_fetch_assoc($items_res)) {
        $order_items[] = $it;
    }
    mysqli_stmt_close($items_stmt);
}

// 4. Fetch Order Audit Status Logs
$logs_stmt = mysqli_prepare($conn, "
    SELECT osl.*, u.full_name AS actor_name
    FROM supplier_order_status_logs osl
    LEFT JOIN users u ON u.id = osl.action_by_user_id
    WHERE osl.order_id = ?
    ORDER BY osl.created_at ASC
");
$status_logs = [];
if ($logs_stmt) {
    mysqli_stmt_bind_param($logs_stmt, "i", $order_id);
    mysqli_stmt_execute($logs_stmt);
    $l_res = mysqli_stmt_get_result($logs_stmt);
    while ($lg = mysqli_fetch_assoc($l_res)) {
        $status_logs[] = $lg;
    }
    mysqli_stmt_close($logs_stmt);
}
?>

<div class="container-fluid px-4">
    <!-- Breadcrumb & Back Link -->
    <div class="mb-3">
        <a href="dashboard.php" class="text-decoration-none fw-semibold" style="color:#667085;">
            <i class="fas fa-arrow-left me-1"></i> Back to Incoming Requests
        </a>
    </div>

    <!-- Alert / Toast Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success d-flex align-items-center justify-content-between mb-4" style="background:#ecfdf3; border:1px solid #abefc6; color:#027a48; border-radius:10px;">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Request Header Banner -->
    <div class="system-card mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h3 style="font-family:'Outfit',sans-serif; font-weight:800; margin:0; color:#101828;">
                        <?php echo htmlspecialchars($order['order_code']); ?>
                    </h3>
                    <span class="status-badge status-<?php echo htmlspecialchars($order['status']); ?>">
                        <?php echo htmlspecialchars(str_replace('_', ' ', $order['status'])); ?>
                    </span>
                    <span class="urgency-badge urgency-<?php echo htmlspecialchars($order['urgency_level']); ?>">
                        <?php echo htmlspecialchars($order['urgency_level']); ?> Priority
                    </span>
                </div>
                <div style="font-size:0.88rem; color:#667085;">
                    Submitted on <?php echo date('F d, Y \a\t h:i A', strtotime($order['created_at'])); ?>
                </div>
            </div>

            <!-- Action Buttons based on status -->
            <div class="d-flex flex-wrap gap-2">
                <?php if ($order['status'] === 'pending'): ?>
                    <button type="button" class="btn btn-system-outline text-danger border-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <i class="fas fa-times-circle me-1"></i> Reject
                    </button>
                    <button type="submit" form="supplierPricingForm" class="btn btn-system-primary">
                        <i class="fas fa-file-invoice-dollar me-1"></i> Send Invoice to Finance Department
                    </button>
                <?php elseif ($order['status'] === 'awaiting_finance_approval'): ?>
                    <span class="badge py-2 px-3" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:0.88rem;">
                        <i class="fas fa-hourglass-half me-1"></i> Awaiting Finance Department Approval
                    </span>
                <?php elseif ($order['status'] === 'finance_approved'): ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge py-2 px-3" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:0.88rem;">
                            <i class="fas fa-check-circle me-1"></i> Finance Approved (₱<?php echo number_format($order['total_amount'], 2); ?>)
                        </span>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="proceed_dispatch">
                            <button type="submit" class="btn btn-system-primary" style="background:#b3261e; border-color:#b3261e; font-weight:700;">
                                <i class="fas fa-truck-fast me-1"></i> Proceed: Pack &amp; Dispatch Delivery
                            </button>
                        </form>
                    </div>
                <?php elseif ($order['status'] === 'in_transit'): ?>
                    <span class="badge py-2 px-3" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:0.88rem;">
                        <i class="fas fa-truck me-1"></i> In Transit to Store
                    </span>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Confirm delivery of this procurement order? Note: The store owner can also confirm receipt directly on their end to update stocks.');">
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="btn btn-outline-success btn-sm fw-bold">
                            <i class="fas fa-check-double"></i> Mark Delivered
                        </button>
                    </form>
                <?php elseif ($order['status'] === 'completed'): ?>
                    <span class="badge bg-success py-2 px-3" style="font-size:0.88rem;">
                        <i class="fas fa-check-circle me-1"></i> Fulfilled &amp; Restocked by Store
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Contextual Workflow Alert Banners -->
    <?php if ($order['status'] === 'awaiting_finance_approval'): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="background:#fffaeb; border:1px solid #fedf89; color:#b54708; border-radius:12px;">
            <i class="fas fa-hourglass-half fa-2x"></i>
            <div>
                <div class="fw-bold" style="font-size:1rem;">Invoice Under Finance Department Review</div>
                <div class="small">The procurement invoice for <strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong> has been transmitted to the Finance Department. Once the finance team approves the transaction, the <strong>Proceed</strong> button will activate here so you can pack and dispatch the goods.</div>
            </div>
        </div>
    <?php elseif ($order['status'] === 'finance_approved'): ?>
        <div class="alert alert-success d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4" style="background:#ecfdf3; border:1px solid #abefc6; color:#027a48; border-radius:12px;">
            <div class="d-flex align-items-center gap-3">
                <i class="fas fa-check-circle fa-2x"></i>
                <div>
                    <div class="fw-bold" style="font-size:1rem;">Finance Department Approved the Transaction!</div>
                    <div class="small">Budget allocation of <strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong> was authorized<?php echo !empty($order['finance_notes']) ? ' (' . htmlspecialchars($order['finance_notes']) . ')' : ''; ?>. Click <strong>Proceed</strong> below to begin packing and dispatch delivery.</div>
                </div>
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="proceed_dispatch">
                <button type="submit" class="btn btn-danger btn-lg" style="background:#b3261e; border-color:#b3261e; font-weight:700; white-space:nowrap;">
                    <i class="fas fa-truck-fast me-1"></i> Proceed to Dispatch
                </button>
            </form>
        </div>
    <?php elseif ($order['status'] === 'in_transit'): ?>
        <div class="alert alert-info d-flex align-items-center gap-3 mb-4" style="background:#eff8ff; border:1px solid #b2ddff; color:#175cd3; border-radius:12px;">
            <i class="fas fa-truck fa-2x"></i>
            <div>
                <div class="fw-bold" style="font-size:1rem;">Delivery In Transit</div>
                <div class="small">Shipment dispatched. The franchise store owner (<?php echo htmlspecialchars($order['store_name']); ?>) will inspect the physical shipment and click <strong>Confirm Received</strong> upon arrival, which automatically increments ingredient inventory and finalizes the invoice.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php $is_pending = ($order['status'] === 'pending'); ?>
    <div class="row g-4">
        <!-- Left: Itemized Checklist & Details -->
        <div class="col-lg-8">
            <?php if ($is_pending): ?>
                <form method="POST" id="supplierPricingForm">
                    <input type="hidden" name="action" value="send_invoice_to_finance">
            <?php endif; ?>

            <div class="system-card mb-4">
                <div class="system-card-header">
                    <h5 class="system-card-title">
                        <i class="fas fa-clipboard-list text-danger"></i> Itemized Procurement Checklist
                    </h5>
                    <span style="font-size:0.85rem; color:#667085;">
                        <?php echo count($order_items); ?> line items
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr style="border-bottom: 2px solid #eaecf0; color:#475467; font-size:0.82rem; text-transform:uppercase;">
                                <th>Ingredient</th>
                                <th class="text-center" style="width:120px;">Requested Qty</th>
                                <th class="text-center" style="width:160px;">Supplied Qty</th>
                                <th class="text-end" style="width:170px;">Wholesale Unit Price</th>
                                <th class="text-end" style="width:130px;">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_items as $item): 
                                $it_id = (int)$item['id'];
                                $req_qty = floatval($item['quantity_requested']);
                                $sup_qty = floatval($item['quantity_supplied']);
                                $unit_price = floatval($item['locked_unit_price']);
                                $line_tot = floatval($item['line_total']);
                                $unit = trim((string)$item['unit']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark item-mat-name" style="font-size:0.95rem;">
                                            <?php echo htmlspecialchars($item['material_name']); ?>
                                        </div>
                                        <?php if ($is_pending): ?>
                                            <input type="hidden" name="items[<?php echo $it_id; ?>][quantity_requested]" value="<?php echo htmlspecialchars((string)$req_qty); ?>">
                                        <?php endif; ?>
                                        <?php if (!empty($item['is_modified']) && !$is_pending): ?>
                                            <span class="badge bg-warning text-dark mt-1" style="font-size:0.75rem;">
                                                <i class="fas fa-pen"></i> Adjusted: <?php echo htmlspecialchars($item['modification_reason'] ?? ''); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center align-middle">
                                        <div class="fw-bold text-dark" style="font-size:1.05rem;"><?php echo formatQtyVal($req_qty); ?></div>
                                        <span class="badge" style="background:#f2f4f7; color:#475467; border:1px solid #eaecf0; font-size:0.75rem; font-weight:500;">
                                            <?php echo htmlspecialchars($unit); ?>
                                        </span>
                                    </td>
                                    <td class="text-center align-middle">
                                        <?php if ($is_pending): ?>
                                            <div class="input-group input-group-sm mx-auto" style="max-width: 140px;">
                                                <input type="number" step="any" min="0" 
                                                       name="items[<?php echo $it_id; ?>][quantity_supplied]" 
                                                       class="form-control text-center fw-bold item-supplied-qty" 
                                                       data-item-id="<?php echo $it_id; ?>"
                                                       value="<?php echo formatQtyVal($sup_qty > 0 ? $sup_qty : $req_qty); ?>" 
                                                       required>
                                                <span class="input-group-text bg-light text-muted small px-2"><?php echo htmlspecialchars($unit); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="fw-bold text-dark" style="font-size:1.05rem;"><?php echo formatQtyVal($sup_qty); ?></div>
                                            <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:0.75rem; font-weight:500;">
                                                <?php echo htmlspecialchars($unit); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end align-middle">
                                        <?php if ($is_pending): ?>
                                            <div class="d-flex flex-column align-items-end">
                                                <div class="input-group input-group-sm" style="max-width: 150px;">
                                                    <span class="input-group-text fw-bold text-muted bg-light">&#8369;</span>
                                                    <input type="number" step="0.01" min="0.01" 
                                                           name="items[<?php echo $it_id; ?>][locked_unit_price]" 
                                                           class="form-control text-end fw-bold item-unit-price" 
                                                           data-item-id="<?php echo $it_id; ?>"
                                                           placeholder="0.00"
                                                           value="<?php echo $unit_price > 0 ? number_format($unit_price, 2, '.', '') : ''; ?>" 
                                                           required>
                                                </div>
                                                <small class="text-muted mt-1" style="font-size:0.75rem;">per <?php echo htmlspecialchars($unit); ?></small>
                                            </div>
                                        <?php else: ?>
                                            <div class="fw-bold text-dark" style="font-size:0.95rem;">
                                                &#8369;<?php echo number_format($unit_price, 2); ?>
                                            </div>
                                            <small class="text-muted" style="font-size:0.75rem;">per <?php echo htmlspecialchars($unit); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end align-middle">
                                        <div class="fw-bold text-dark item-line-total-val" id="line_total_<?php echo $it_id; ?>" style="font-size:1rem;">
                                            &#8369;<?php echo number_format($line_tot, 2); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 2px solid #eaecf0;">
                                <td colspan="4" class="text-end fw-semibold text-secondary">Subtotal:</td>
                                <td class="text-end fw-bold text-dark fs-6">
                                    &#8369;<span id="display_subtotal"><?php echo number_format($order['subtotal_amount'], 2); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-semibold text-secondary align-middle">Delivery / Logistics Fee:</td>
                                <td class="text-end align-middle">
                                    <?php if ($is_pending): ?>
                                        <div class="input-group input-group-sm ms-auto" style="max-width: 140px;">
                                            <span class="input-group-text fw-bold text-muted bg-light">&#8369;</span>
                                            <input type="number" step="0.01" min="0" name="delivery_fee" id="supplier_delivery_fee" class="form-control text-end fw-bold" value="<?php echo number_format($order['delivery_fee'], 2, '.', ''); ?>">
                                        </div>
                                    <?php else: ?>
                                        <span class="fw-bold text-dark">&#8369;<?php echo number_format($order['delivery_fee'], 2); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="background:#f8f9fa;">
                                <td colspan="4" class="text-end fw-bold align-middle" style="font-size:1.05rem; color:#101828;">Grand Total:</td>
                                <td class="text-end fw-bold align-middle" style="font-size:1.2rem; color:#b3261e;">
                                    &#8369;<span id="display_grand_total"><?php echo number_format($order['total_amount'], 2); ?></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Notes & Instructions -->
            <div class="system-card mb-4">
                <div class="system-card-header">
                    <h5 class="system-card-title">
                        <i class="fas fa-comment-dots text-secondary"></i> Order Notes &amp; Logistics Instructions
                    </h5>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Store Owner Special Instructions:</label>
                        <div class="p-3 bg-light rounded border text-secondary" style="min-height: 80px; font-size:0.9rem;">
                            <?php echo !empty($order['store_notes']) ? nl2br(htmlspecialchars($order['store_notes'])) : '<em>No special instructions provided.</em>'; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Supplier Response Notes:</label>
                        <?php if ($is_pending): ?>
                            <textarea name="supplier_notes" id="supplier_notes" class="form-control" rows="3" placeholder="e.g. Deliveries scheduled for tomorrow morning. Wholesale rates locked."><?php echo htmlspecialchars($order['supplier_notes'] ?? ''); ?></textarea>
                        <?php else: ?>
                            <div class="p-3 bg-light rounded border text-secondary" style="min-height: 80px; font-size:0.9rem;">
                                <?php echo !empty($order['supplier_notes']) ? nl2br(htmlspecialchars($order['supplier_notes'])) : '<em>No vendor notes attached.</em>'; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($is_pending): ?>
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4 pt-3 border-top">
                        <div class="text-muted small">
                            <i class="fas fa-shield-alt text-success me-1"></i> Submitting will calculate the total and route this invoice directly to Finance Department for budget clearance.
                        </div>
                        <button type="submit" class="btn btn-system-primary px-4 py-2" style="font-weight:700;">
                            <i class="fas fa-file-invoice-dollar me-2"></i> Send Invoice to Finance Department
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($is_pending): ?>
                </form>
            <?php endif; ?>
        </div>

        <!-- Right: Store Profile & Timeline -->
        <div class="col-lg-4">
            <!-- Store Profile Card -->
            <div class="system-card mb-4">
                <div class="system-card-header">
                    <h5 class="system-card-title" style="font-size:1.1rem;">
                        <i class="fas fa-store text-danger"></i> Store Details
                    </h5>
                </div>
                <div class="mb-3">
                    <div style="font-weight:700; color:#101828; font-size:1.05rem;">
                        <?php echo htmlspecialchars($order['store_name']); ?>
                    </div>
                    <div style="font-size:0.85rem; color:#667085;">
                        Owner: <?php echo htmlspecialchars($order['owner_name']); ?>
                    </div>
                </div>
                <div class="mb-2" style="font-size:0.88rem; color:#475467;">
                    <i class="fas fa-phone me-2 text-secondary"></i>
                    <?php echo htmlspecialchars($order['store_phone'] ?: 'N/A'); ?>
                </div>
                <div class="mb-2" style="font-size:0.88rem; color:#475467;">
                    <i class="fas fa-envelope me-2 text-secondary"></i>
                    <?php echo htmlspecialchars($order['store_email'] ?: 'N/A'); ?>
                </div>
                <div style="font-size:0.88rem; color:#475467;">
                    <i class="fas fa-location-dot me-2 text-secondary"></i>
                    <?php echo htmlspecialchars($order['store_address'] ?: 'Branch location on file'); ?>
                </div>
                <hr style="border-color:#eaecf0;">
                <div>
                    <label class="small text-muted fw-bold">Requested Delivery Date:</label>
                    <div style="font-weight:700; color:#101828;">
                        <?php echo !empty($order['requested_delivery_date']) ? date('F d, Y', strtotime($order['requested_delivery_date'])) : 'Standard Fulfillment'; ?>
                    </div>
                </div>
            </div>

            <!-- Status Transition Audit Trail -->
            <div class="system-card">
                <div class="system-card-header">
                    <h5 class="system-card-title" style="font-size:1.1rem;">
                        <i class="fas fa-timeline text-primary"></i> Status Audit Trail
                    </h5>
                </div>
                <?php if (empty($status_logs)): ?>
                    <p class="text-muted small">No status logs recorded yet.</p>
                <?php else: ?>
                    <div class="timeline" style="border-left: 2px solid #eaecf0; margin-left: 8px; padding-left: 16px;">
                        <?php foreach ($status_logs as $lg): ?>
                            <div class="mb-3 position-relative">
                                <div style="position:absolute; left:-23px; top:2px; width:12px; height:12px; border-radius:50%; background:#b3261e; border:2px solid #ffffff;"></div>
                                <div style="font-weight:700; color:#101828; font-size:0.88rem; text-transform:capitalize;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $lg['new_status'])); ?>
                                </div>
                                <div style="font-size:0.78rem; color:#667085;">
                                    <?php echo date('M d, Y h:i A', strtotime($lg['created_at'])); ?> &bull; <?php echo htmlspecialchars($lg['actor_name'] ?: 'System'); ?>
                                </div>
                                <?php if (!empty($lg['notes'])): ?>
                                    <div style="font-size:0.82rem; color:#475467; margin-top:2px;">
                                        <?php echo htmlspecialchars($lg['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Reject Request -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="rejectModalLabel">Reject Refill Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Please indicate why you are unable to fulfill this request so the store owner can adjust or re-order.
                    </p>
                    <div class="mb-3">
                        <label for="reject_reason" class="form-label fw-bold">Reason for Rejection *</label>
                        <textarea name="reject_reason" id="reject_reason" rows="3" class="form-control" placeholder="e.g., Raw material temporarily unavailable from slaughterhouse" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function recalcTotals() {
        let subtotal = 0;
        document.querySelectorAll('.item-unit-price').forEach(function(pInput) {
            const itemId = pInput.getAttribute('data-item-id');
            const qInput = document.querySelector('.item-supplied-qty[data-item-id="' + itemId + '"]');
            const qty = parseFloat(qInput ? qInput.value : 0) || 0;
            const price = parseFloat(pInput.value) || 0;
            const lineTot = Math.round(qty * price * 100) / 100;
            subtotal += lineTot;

            const lineEl = document.getElementById('line_total_' + itemId);
            if (lineEl) {
                lineEl.textContent = '₱' + lineTot.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        });

        const feeInput = document.getElementById('supplier_delivery_fee');
        const deliveryFee = parseFloat(feeInput ? feeInput.value : 0) || 0;
        const grandTotal = Math.round((subtotal + deliveryFee) * 100) / 100;

        const subEl = document.getElementById('display_subtotal');
        if (subEl) {
            subEl.textContent = subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        const grandEl = document.getElementById('display_grand_total');
        if (grandEl) {
            grandEl.textContent = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    document.querySelectorAll('.item-unit-price, .item-supplied-qty').forEach(function(el) {
        el.addEventListener('input', recalcTotals);
    });

    const feeEl = document.getElementById('supplier_delivery_fee');
    if (feeEl) {
        feeEl.addEventListener('input', recalcTotals);
    }

    const formEl = document.getElementById('supplierPricingForm');
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            let zeroFound = false;
            let zeroName = '';
            document.querySelectorAll('.item-unit-price').forEach(function(input) {
                const val = parseFloat(input.value) || 0;
                if (val <= 0) {
                    zeroFound = true;
                    input.classList.add('is-invalid');
                    const row = input.closest('tr');
                    const nameEl = row ? row.querySelector('.item-mat-name') : null;
                    if (!zeroName && nameEl) {
                        zeroName = nameEl.textContent.trim();
                    }
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (zeroFound) {
                e.preventDefault();
                alert('Please enter a wholesale unit price for all ingredients' + (zeroName ? ' (e.g. ' + zeroName + ')' : '') + ' before sending the invoice to Finance.');
                return false;
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
