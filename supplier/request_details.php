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

    // ACTION: Send Quoted Invoice to Finance Department
    if ($action === 'send_invoice_to_finance') {
        $supplier_notes = trim((string)($_POST['supplier_notes'] ?? ''));
        $submitted = IngredientStockManager::submitSupplierInvoiceToFinance($conn, $order_id, (int)$_SESSION['user_id'], $supplier_notes);
        if ($submitted) {
            $_SESSION['success'] = "Invoice for refill request {$order['order_code']} has been submitted to the Finance Department for budget approval!";
        } else {
            $_SESSION['error'] = "Could not submit invoice to Finance Department. Please try again.";
        }
        header("Location: request_details.php?id=$order_id");
        exit;
    }

    // ACTION: Modify Quantities/Pricing & Send Invoice to Finance
    if ($action === 'modify_and_send_finance' || $action === 'modify_accept') {
        $mod_items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
        $supplier_notes = trim((string)($_POST['supplier_notes'] ?? ''));
        $delivery_fee = floatval($_POST['delivery_fee'] ?? $order['delivery_fee']);

        $new_subtotal = 0.0;
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
                $qty_supplied = floatval($i_data['quantity_supplied'] ?? 0);
                $unit_price = floatval($i_data['locked_unit_price'] ?? 0);
                $orig_qty = floatval($i_data['quantity_requested'] ?? 0);
                $reason = trim((string)($i_data['modification_reason'] ?? ''));

                $is_mod = ($qty_supplied != $orig_qty) ? 1 : 0;
                $line_tot = $qty_supplied * $unit_price;
                $new_subtotal += $line_tot;

                mysqli_stmt_bind_param($upd_item_stmt, "dddisii", $qty_supplied, $unit_price, $line_tot, $is_mod, $reason, $i_id, $order_id);
                mysqli_stmt_execute($upd_item_stmt);
            }
            mysqli_stmt_close($upd_item_stmt);
        }

        $new_grand_total = $new_subtotal + $delivery_fee;

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
            mysqli_stmt_bind_param($upd_ord, "dddssi", $new_subtotal, $delivery_fee, $new_grand_total, $supplier_notes, $order_id, $current_supplier_id);
            mysqli_stmt_execute($upd_ord);
            mysqli_stmt_close($upd_ord);
        }

        // Submit revised invoice to Finance Department
        IngredientStockManager::submitSupplierInvoiceToFinance($conn, $order_id, (int)$_SESSION['user_id'], $supplier_notes);
        $_SESSION['success'] = "Refill quote {$order['order_code']} modified and invoice submitted to Finance Department for approval (Total: ₱" . number_format($new_grand_total, 2) . ")!";

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
                        <i class="fas fa-times-circle"></i> Reject
                    </button>
                    <button type="button" class="btn btn-system-outline" data-bs-toggle="modal" data-bs-target="#modifyModal">
                        <i class="fas fa-sliders"></i> Modify Quote &amp; Send Invoice
                    </button>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="send_invoice_to_finance">
                        <button type="submit" class="btn btn-system-primary">
                            <i class="fas fa-file-invoice-dollar"></i> Send Invoice to Finance Department
                        </button>
                    </form>
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

    <div class="row g-4">
        <!-- Left: Itemized Checklist & Details -->
        <div class="col-lg-8">
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
                                <th class="text-center">Requested Qty</th>
                                <th class="text-center">Supplied Qty</th>
                                <th class="text-end">Locked Unit Price</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700; color:#101828;">
                                            <?php echo htmlspecialchars($item['material_name']); ?>
                                        </div>
                                        <?php if (!empty($item['is_modified'])): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:0.75rem;">
                                                <i class="fas fa-pen"></i> Modified: <?php echo htmlspecialchars($item['modification_reason'] ?? 'Qty adjusted'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="font-weight:600; color:#344054;">
                                        <?php echo number_format($item['quantity_requested'], 2) . ' ' . htmlspecialchars($item['unit']); ?>
                                    </td>
                                    <td class="text-center" style="font-weight:700; color:#101828;">
                                        <?php echo number_format($item['quantity_supplied'], 2) . ' ' . htmlspecialchars($item['unit']); ?>
                                    </td>
                                    <td class="text-end" style="color:#475467;">
                                        ₱<?php echo number_format($item['locked_unit_price'], 2); ?> / <?php echo htmlspecialchars($item['unit']); ?>
                                    </td>
                                    <td class="text-end" style="font-weight:700; color:#101828;">
                                        ₱<?php echo number_format($item['line_total'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 2px solid #eaecf0;">
                                <td colspan="4" class="text-end fw-semibold">Subtotal:</td>
                                <td class="text-end fw-bold">₱<?php echo number_format($order['subtotal_amount'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-semibold">Delivery / Logistics Fee:</td>
                                <td class="text-end fw-bold">₱<?php echo number_format($order['delivery_fee'], 2); ?></td>
                            </tr>
                            <tr style="background:#f8f9fa;">
                                <td colspan="4" class="text-end fw-bold" style="font-size:1.05rem; color:#101828;">Grand Total:</td>
                                <td class="text-end fw-bold" style="font-size:1.15rem; color:#b3261e;">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Notes & Instructions -->
            <div class="system-card">
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
                        <div class="p-3 bg-light rounded border text-secondary" style="min-height: 80px; font-size:0.9rem;">
                            <?php echo !empty($order['supplier_notes']) ? nl2br(htmlspecialchars($order['supplier_notes'])) : '<em>No vendor notes attached.</em>'; ?>
                        </div>
                    </div>
                </div>
            </div>
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

<!-- Modal: Modify & Send Invoice to Finance -->
<div class="modal fade" id="modifyModal" tabindex="-1" aria-labelledby="modifyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="modify_and_send_finance">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modifyModalLabel">Modify Quantities, Pricing &amp; Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Adjust supplied quantities or locked unit prices below. Submitting will generate and route the updated invoice directly to the Finance Department for budget approval.
                    </p>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr class="table-light small text-uppercase">
                                    <th>Ingredient</th>
                                    <th>Req. Qty</th>
                                    <th>Supplied Qty</th>
                                    <th>Unit Price (₱)</th>
                                    <th>Reason for Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $it): 
                                    $it_id = (int)$it['id'];
                                ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($it['material_name']); ?>
                                            <input type="hidden" name="items[<?php echo $it_id; ?>][quantity_requested]" value="<?php echo htmlspecialchars((string)$it['quantity_requested']); ?>">
                                        </td>
                                        <td>
                                            <?php echo number_format($it['quantity_requested'], 2) . ' ' . htmlspecialchars($it['unit']); ?>
                                        </td>
                                        <td style="max-width:110px;">
                                            <input type="number" step="0.01" min="0" 
                                                   name="items[<?php echo $it_id; ?>][quantity_supplied]" 
                                                   value="<?php echo htmlspecialchars((string)$it['quantity_supplied']); ?>" 
                                                   class="form-control form-control-sm text-end" required>
                                        </td>
                                        <td style="max-width:120px;">
                                            <input type="number" step="0.01" min="0" 
                                                   name="items[<?php echo $it_id; ?>][locked_unit_price]" 
                                                   value="<?php echo htmlspecialchars(number_format($it['locked_unit_price'], 2, '.', '')); ?>" 
                                                   class="form-control form-control-sm text-end" required>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="items[<?php echo $it_id; ?>][modification_reason]" 
                                                   value="<?php echo htmlspecialchars($it['modification_reason'] ?? ''); ?>" 
                                                   placeholder="e.g. Partial batch" 
                                                   class="form-control form-control-sm">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="delivery_fee" class="form-label fw-bold">Logistics / Delivery Fee (₱)</label>
                            <input type="number" step="0.01" min="0" name="delivery_fee" id="delivery_fee" value="<?php echo htmlspecialchars(number_format($order['delivery_fee'], 2, '.', '')); ?>" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_notes" class="form-label fw-bold">Notes to Shop Owner</label>
                            <input type="text" name="supplier_notes" id="supplier_notes" value="<?php echo htmlspecialchars($order['supplier_notes'] ?? ''); ?>" placeholder="e.g. Balance arriving Thursday" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-system-primary">
                        <i class="fas fa-file-invoice-dollar me-1"></i> Save Changes &amp; Send Invoice to Finance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
