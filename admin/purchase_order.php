<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $admin_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $admin_id) : null;
$scope_owner_id = $seller_scope_id !== null ? (int)$seller_scope_id : $admin_id;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $admin_id;
$can_manage_finance_control = !function_exists('hasPermission') || hasPermission($conn, $admin_id, 'finance.manage') || $is_partner_owner_admin || strtolower((string)($_SESSION['role_name'] ?? '')) === 'super_admin';

function poTableExists($conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $safe = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $cache[$table] = (bool)($result && mysqli_num_rows($result) > 0);
}

function poColumnExists($conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    if (!poTableExists($conn, $table)) return $cache[$key] = false;
    $safeTable = mysqli_real_escape_string($conn, $table);
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
}

function poPartnerPrScopeSql(int $sellerScopeId, string $prAlias = 'pr'): string {
    return "({$prAlias}.requested_by = {$sellerScopeId} OR EXISTS (
        SELECT 1
        FROM purchase_requisition_items pri_scope
        INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = pri_scope.material_id
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE pri_scope.pr_id = {$prAlias}.id
          AND p_scope.seller_id = {$sellerScopeId}
    ))";
}

function poPartnerPoScopeSql(int $sellerScopeId, string $poAlias = 'po'): string {
    return "({$poAlias}.created_by = {$sellerScopeId} OR EXISTS (
        SELECT 1
        FROM purchase_order_items poi_scope
        INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = poi_scope.material_id
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE poi_scope.purchase_order_id = {$poAlias}.id
          AND p_scope.seller_id = {$sellerScopeId}
    ))";
}

function poPartnerMaterialScopeSql(int $sellerScopeId, string $materialAlias = 'm'): string {
    return "EXISTS (
        SELECT 1
        FROM bill_of_materials bom_scope
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE bom_scope.material_id = {$materialAlias}.id
          AND p_scope.seller_id = {$sellerScopeId}
    )";
}

function poGetApprovedBudgetSummary($conn, int $ownerUserId, string $budgetDate, string $poScopeSql = '', int $excludePoId = 0): array {
    $summary = ['approved_total' => 0.0, 'used_total' => 0.0, 'remaining_total' => 0.0];
    if (poTableExists($conn, 'procurement_budget_requests')) {
        $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount_approved), 0) AS approved_total
                                       FROM procurement_budget_requests
                                       WHERE owner_user_id = ?
                                         AND budget_date = ?
                                         AND status = 'approved'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $ownerUserId, $budgetDate);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
            mysqli_stmt_close($stmt);
            $summary['approved_total'] = (float)($row['approved_total'] ?? 0);
        }
    }
    if (poTableExists($conn, 'purchase_orders')) {
        $sql = "SELECT COALESCE(SUM(po.total_amount), 0) AS used_total
                FROM purchase_orders po
                WHERE po.order_date = ?
                  AND po.status IN ('ordered', 'partially_received', 'completed')";
        if ($poScopeSql !== '') $sql .= " AND {$poScopeSql}";
        if ($excludePoId > 0) $sql .= " AND po.id <> " . (int)$excludePoId;
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $budgetDate);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
            mysqli_stmt_close($stmt);
            $summary['used_total'] = (float)($row['used_total'] ?? 0);
        }
    }
    $summary['remaining_total'] = round($summary['approved_total'] - $summary['used_total'], 2);
    return $summary;
}

$po_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$pr_id = isset($_GET['pr_id']) ? intval($_GET['pr_id']) : 0;
$partner_pr_scope_sql = $seller_scope_id !== null ? poPartnerPrScopeSql((int)$seller_scope_id, 'pr') : '';
$partner_po_scope_sql = $seller_scope_id !== null ? poPartnerPoScopeSql((int)$seller_scope_id, 'po') : '';
$partner_material_scope_sql = $seller_scope_id !== null ? poPartnerMaterialScopeSql((int)$seller_scope_id, 'm') : '';

$po = null;
$po_items = [];
$suppliers = [];
$materials = [];

// --- ACTION HANDLING (POST REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $current_po_id = intval($_POST['po_id'] ?? $po_id);

    // Create a new Purchase Order header
    if ($action === 'create_po') {
        $supplier_id = intval($_POST['supplier_id']);
        $order_date = $_POST['order_date'];
        $expected_date = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
        $notes = trim($_POST['notes']);
        $linked_pr_id = !empty($_POST['pr_id']) ? intval($_POST['pr_id']) : null;
        $po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        if ($seller_scope_id !== null && $linked_pr_id > 0) {
            $scope_stmt = mysqli_prepare($conn, "SELECT id FROM purchase_requisitions pr WHERE pr.id = ? AND {$partner_pr_scope_sql} LIMIT 1");
            if ($scope_stmt) {
                mysqli_stmt_bind_param($scope_stmt, 'i', $linked_pr_id);
                mysqli_stmt_execute($scope_stmt);
                $linked_pr = mysqli_fetch_assoc(mysqli_stmt_get_result($scope_stmt)) ?: null;
                mysqli_stmt_close($scope_stmt);
                if (!$linked_pr) {
                    $_SESSION['error'] = "Selected requisition is not inside your procurement scope.";
                    header("Location: mrp.php?tab=requisitions");
                    exit;
                }
            }
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_delivery_date, notes, created_by, status, pr_id) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)");
        mysqli_stmt_bind_param($stmt, "sisssii", $po_number, $supplier_id, $order_date, $expected_date, $notes, $admin_id, $linked_pr_id);
        mysqli_stmt_execute($stmt);
        $new_po_id = mysqli_insert_id($conn);

        // If created from PR, copy items
        if ($linked_pr_id) {
            $hasEstimatedCost = poColumnExists($conn, 'purchase_requisition_items', 'estimated_cost');
            $pr_items = mysqli_query($conn, "SELECT pri.material_id, pri.quantity_requested" . ($hasEstimatedCost ? ", pri.estimated_cost" : "") . ", COALESCE(m.cost_per_unit, 0) AS material_unit_cost
                                             FROM purchase_requisition_items pri
                                             LEFT JOIN materials m ON m.id = pri.material_id
                                             WHERE pri.pr_id = $linked_pr_id");
            $po_item_stmt = mysqli_prepare($conn, "INSERT INTO purchase_order_items (purchase_order_id, material_id, quantity_ordered, unit_cost) VALUES (?, ?, ?, ?)");
            while($item = mysqli_fetch_assoc($pr_items)) {
                $qtyRequested = (float)($item['quantity_requested'] ?? 0);
                $unitCost = (float)($item['material_unit_cost'] ?? 0);
                if ($hasEstimatedCost && $qtyRequested > 0 && (float)($item['estimated_cost'] ?? 0) > 0) {
                    $unitCost = round(((float)$item['estimated_cost']) / $qtyRequested, 2);
                }
                mysqli_stmt_bind_param($po_item_stmt, "iidd", $new_po_id, $item['material_id'], $qtyRequested, $unitCost);
                mysqli_stmt_execute($po_item_stmt);
            }
            // Update PR status
            mysqli_query($conn, "UPDATE purchase_requisitions SET status = 'po_created' WHERE id = $linked_pr_id");
        }

        $_SESSION['success'] = "Purchase Order #$po_number created. You can now add items.";
        header("Location: purchase_order.php?id=$new_po_id");
        exit;
    }

    // Add an item to the PO
    if ($action === 'add_item' && $current_po_id > 0) {
        $material_id = intval($_POST['material_id']);
        $quantity = floatval($_POST['quantity']);
        $unit_cost = floatval($_POST['unit_cost']);

        if ($material_id > 0 && $quantity > 0) {
            $stmt = mysqli_prepare($conn, "INSERT INTO purchase_order_items (purchase_order_id, material_id, quantity_ordered, unit_cost) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iidd", $current_po_id, $material_id, $quantity, $unit_cost);
            mysqli_stmt_execute($stmt);
            recalculatePOTotal($conn, $current_po_id);
            $_SESSION['success'] = "Item added successfully.";
        } else {
            $_SESSION['error'] = "Invalid material or quantity.";
        }
        header("Location: purchase_order.php?id=$current_po_id");
        exit;
    }

    // Update existing items on the PO
    if ($action === 'update_items' && $current_po_id > 0) {
        $item_ids = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $costs = $_POST['cost'] ?? [];
        $delete_items = $_POST['delete_item'] ?? [];

        mysqli_begin_transaction($conn);
        try {
            foreach ($item_ids as $index => $item_id) {
                if (in_array($item_id, $delete_items)) {
                    $stmt = mysqli_prepare($conn, "DELETE FROM purchase_order_items WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $item_id);
                    mysqli_stmt_execute($stmt);
                } else {
                    $quantity = floatval($quantities[$index]);
                    $cost = floatval($costs[$index]);
                    $stmt = mysqli_prepare($conn, "UPDATE purchase_order_items SET quantity_ordered = ?, unit_cost = ? WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, "ddi", $quantity, $cost, $item_id);
                    mysqli_stmt_execute($stmt);
                }
            }
            recalculatePOTotal($conn, $current_po_id);
            mysqli_commit($conn);
            $_SESSION['success'] = "Purchase Order items updated.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error'] = "Error updating items: " . $e->getMessage();
        }
        header("Location: purchase_order.php?id=$current_po_id");
        exit;
    }
    
    // Update PO header details
    if ($action === 'update_po_details' && $current_po_id > 0) {
        $supplier_id = intval($_POST['supplier_id']);
        $order_date = $_POST['order_date'];
        $expected_date = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
        $notes = trim($_POST['notes']);
        $status = trim($_POST['status']);

        if ($seller_scope_id !== null) {
            $scope_stmt = mysqli_prepare($conn, "SELECT id FROM purchase_orders po WHERE po.id = ? AND {$partner_po_scope_sql} LIMIT 1");
            if ($scope_stmt) {
                mysqli_stmt_bind_param($scope_stmt, 'i', $current_po_id);
                mysqli_stmt_execute($scope_stmt);
                $po_in_scope = mysqli_fetch_assoc(mysqli_stmt_get_result($scope_stmt)) ?: null;
                mysqli_stmt_close($scope_stmt);
                if (!$po_in_scope) {
                    $_SESSION['error'] = "Purchase order not found in your procurement scope.";
                    header("Location: mrp.php?tab=purchase_orders");
                    exit;
                }
            }
        }

        if (in_array($status, ['ordered', 'partially_received', 'completed'], true)) {
            $po_total_stmt = mysqli_prepare($conn, "SELECT total_amount FROM purchase_orders WHERE id = ? LIMIT 1");
            $po_total = 0.0;
            if ($po_total_stmt) {
                mysqli_stmt_bind_param($po_total_stmt, 'i', $current_po_id);
                mysqli_stmt_execute($po_total_stmt);
                $po_total_row = mysqli_fetch_assoc(mysqli_stmt_get_result($po_total_stmt)) ?: [];
                mysqli_stmt_close($po_total_stmt);
                $po_total = (float)($po_total_row['total_amount'] ?? 0);
            }
            $budgetSummary = poGetApprovedBudgetSummary($conn, $scope_owner_id, $order_date, $seller_scope_id !== null ? $partner_po_scope_sql : '', $current_po_id);
            if ($budgetSummary['approved_total'] <= 0) {
                $_SESSION['error'] = "Daily procurement budget for " . date('M d, Y', strtotime($order_date)) . " must be approved by finance before this PO can move out of draft.";
                header("Location: purchase_order.php?id=$current_po_id");
                exit;
            }
            if ($po_total > max(0, $budgetSummary['remaining_total'])) {
                $_SESSION['error'] = "Approved daily procurement budget remaining is PHP " . number_format(max(0, $budgetSummary['remaining_total']), 2) . ". Reduce PO total or secure more finance-approved budget.";
                header("Location: purchase_order.php?id=$current_po_id");
                exit;
            }
        }

        $updateSql = "UPDATE purchase_orders" . ($seller_scope_id !== null ? " po" : "") . " SET supplier_id=?, order_date=?, expected_delivery_date=?, notes=?, status=? WHERE id=?" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : "");
        $stmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($stmt, "issssi", $supplier_id, $order_date, $expected_date, $notes, $status, $current_po_id);
        mysqli_stmt_execute($stmt);
        $_SESSION['success'] = "PO details updated.";
        header("Location: purchase_order.php?id=$current_po_id");
        exit;
    }
}

// --- DATA FETCHING (GET REQUESTS) ---
if ($po_id > 0) {
    // Edit mode: Fetch existing PO
    $stmt = mysqli_prepare($conn, "SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id WHERE po.id = ?" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : ""));
    mysqli_stmt_bind_param($stmt, "i", $po_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $po = mysqli_fetch_assoc($result);

    if (!$po) {
        $_SESSION['error'] = "Purchase Order not found.";
        header("Location: mrp.php?tab=purchase_orders");
        exit;
    }

    // Fetch PO items
    $items_stmt = mysqli_prepare($conn, "SELECT poi.*, m.name as material_name, m.unit FROM purchase_order_items poi JOIN materials m ON poi.material_id = m.id WHERE poi.purchase_order_id = ?" . ($seller_scope_id !== null ? " AND {$partner_material_scope_sql}" : "") . " ORDER BY m.name");
    mysqli_stmt_bind_param($items_stmt, "i", $po_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    while ($row = mysqli_fetch_assoc($items_result)) {
        $po_items[] = $row;
    }
}

// Fetch suppliers and materials for dropdowns
$suppliers_res = mysqli_query($conn, "SELECT id, name FROM suppliers ORDER BY name");
while ($row = mysqli_fetch_assoc($suppliers_res)) $suppliers[] = $row;

$materials_res = mysqli_query($conn, "SELECT id, name, unit, cost_per_unit FROM materials m" . ($seller_scope_id !== null ? " WHERE {$partner_material_scope_sql}" : "") . " ORDER BY name");
while ($row = mysqli_fetch_assoc($materials_res)) $materials[] = $row;

$po_budget_summary = ['approved_total' => 0.0, 'used_total' => 0.0, 'remaining_total' => 0.0];
if ($po && !empty($po['order_date'])) {
    $po_budget_summary = poGetApprovedBudgetSummary($conn, $scope_owner_id, (string)$po['order_date'], $seller_scope_id !== null ? $partner_po_scope_sql : '', (int)$po_id);
}

// Helper function to recalculate PO total
function recalculatePOTotal($conn, $po_id) {
    $total_query = "SELECT SUM(quantity_ordered * unit_cost) as total FROM purchase_order_items WHERE purchase_order_id = ?";
    $stmt = mysqli_prepare($conn, $total_query);
    mysqli_stmt_bind_param($stmt, "i", $po_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $total = mysqli_fetch_assoc($result)['total'] ?? 0.00;

    $update_stmt = mysqli_prepare($conn, "UPDATE purchase_orders SET total_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($update_stmt, "di", $total, $po_id);
    mysqli_stmt_execute($update_stmt);
}

$page_title = $po_id > 0 ? "Edit PO #" . htmlspecialchars($po['po_number']) : "Create Purchase Order";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Lechon Delights</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1><?php echo $page_title; ?></h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <div class="mb-3">
                    <a href="mrp.php?tab=purchase_orders" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Procurement</a>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <?php if ($po_id > 0 && $po): // EDIT/VIEW MODE ?>
                <div class="alert alert-info">
                    Finance control: a PO can only move from draft into an active commitment when the daily approved procurement budget is enough.
                    Approved today: <strong>PHP <?php echo number_format((float)($po_budget_summary['approved_total'] ?? 0), 2); ?></strong>,
                    Remaining before this PO: <strong>PHP <?php echo number_format((float)($po_budget_summary['remaining_total'] ?? 0), 2); ?></strong>.
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_po_details">
                    <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5>PO Details</h5>
                            <span class="status-badge badge-<?php echo str_replace('_', '-', $po['status']); ?>"><?php echo ucwords(str_replace('_', ' ', $po['status'])); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label>Supplier</label>
                                    <select name="supplier_id" class="form-select" required>
                                        <?php foreach($suppliers as $sup): ?>
                                            <option value="<?php echo $sup['id']; ?>" <?php echo ($po['supplier_id'] == $sup['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sup['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Order Date</label>
                                    <input type="date" name="order_date" class="form-control" value="<?php echo $po['order_date']; ?>" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Expected Delivery</label>
                                    <input type="date" name="expected_delivery_date" class="form-control" value="<?php echo $po['expected_delivery_date']; ?>">
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="1"><?php echo htmlspecialchars($po['notes']); ?></textarea>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Status</label>
                                    <select name="status" class="form-select">
                                        <option value="draft" <?php echo $po['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="ordered" <?php echo $po['status'] == 'ordered' ? 'selected' : ''; ?>>Ordered</option>
                                        <option value="partially_received" <?php echo $po['status'] == 'partially_received' ? 'selected' : ''; ?>>Partially Received</option>
                                        <option value="completed" <?php echo $po['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $po['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Details</button>
                        </div>
                    </div>
                </form>

                <div class="card mb-4">
                    <div class="card-header"><h5>Items on this Purchase Order</h5></div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_items">
                            <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th style="width: 120px;">Quantity</th>
                                        <th style="width: 120px;">Unit Cost</th>
                                        <th style="width: 150px;">Line Total</th>
                                        <th style="width: 50px;">Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($po_items)): ?>
                                        <tr><td colspan="5" class="text-center text-muted">No items added yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($po_items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['material_name']); ?> (<?php echo $item['unit']; ?>)</td>
                                            <td><input type="number" name="quantity[]" class="form-control form-control-sm" value="<?php echo $item['quantity_ordered']; ?>" step="0.01"></td>
                                            <td><input type="number" name="cost[]" class="form-control form-control-sm" value="<?php echo $item['unit_cost']; ?>" step="0.01"></td>
                                            <td>₱<?php echo number_format($item['quantity_ordered'] * $item['unit_cost'], 2); ?></td>
                                            <td class="text-center">
                                                <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                                                <input type="checkbox" name="delete_item[]" value="<?php echo $item['id']; ?>" class="form-check-input">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <?php if(!empty($po_items)): ?>
                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-success">Update Items</button>
                                <div class="fw-bold fs-5">Total: ₱<?php echo number_format($po['total_amount'], 2); ?></div>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5>Add New Item</h5></div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="add_item">
                            <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-5">
                                    <label>Material</label>
                                    <select name="material_id" id="materialSelect" class="form-select" required>
                                        <option value="">-- Select Material --</option>
                                        <?php foreach($materials as $mat): ?>
                                            <option value="<?php echo $mat['id']; ?>" data-cost="<?php echo $mat['cost_per_unit']; ?>"><?php echo htmlspecialchars($mat['name']); ?> (<?php echo $mat['unit']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label>Quantity</label>
                                    <input type="number" name="quantity" class="form-control" step="0.01" required>
                                </div>
                                <div class="col-md-3">
                                    <label>Unit Cost (₱)</label>
                                    <input type="number" name="unit_cost" id="unitCost" class="form-control" step="0.01" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100">Add Item</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php else: // CREATE MODE ?>
                <div class="card">
                    <div class="card-header"><h5>Create New Purchase Order</h5></div>
                    <div class="card-body">
                        <div class="alert alert-info">Create the PO in draft first, finalize quantities and costs, then move it to <strong>Ordered</strong> only after the day budget is finance-approved.</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="create_po">
                            <?php if($pr_id > 0): ?>
                                <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">
                                <div class="alert alert-info">Creating PO from Requisition #<?php echo $pr_id; ?>. Items will be copied automatically.</div>
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Supplier *</label>
                                    <select name="supplier_id" class="form-select" required>
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach($suppliers as $sup): ?>
                                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Order Date *</label>
                                    <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Expected Delivery</label>
                                    <input type="date" name="expected_delivery_date" class="form-control">
                                </div>
                                <div class="col-12 mb-3">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Add any notes for this PO..."></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create PO & Add Items</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const materialSelect = document.getElementById('materialSelect');
            const unitCostInput = document.getElementById('unitCost');

            if (materialSelect) {
                materialSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const cost = selectedOption.getAttribute('data-cost');
                    if (unitCostInput) {
                        unitCostInput.value = cost || '';
                    }
                });
            }
        });
    </script>
</body>
</html>
