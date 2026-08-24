<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';
include '../includes/PartnerBusinessEconomicsService.php';

checkAdminAccess();
requirePermission('mrp.view');
$admin_info = getAdminInfo($conn);
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $admin_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $admin_id) : null;
$scope_owner_id = $seller_scope_id !== null ? (int)$seller_scope_id : $admin_id;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $admin_id;
$can_manage_mrp = !function_exists('hasPermission') || hasPermission($conn, $admin_id, 'mrp.manage') || $is_partner_owner_admin || strtolower((string)($_SESSION['role_name'] ?? '')) === 'super_admin';
$can_manage_finance_control = !function_exists('hasPermission') || hasPermission($conn, $admin_id, 'finance.manage') || $is_partner_owner_admin || strtolower((string)($_SESSION['role_name'] ?? '')) === 'super_admin';
$partner_material_scope_sql = '';
$partner_pr_scope_sql = '';
$partner_po_scope_sql = '';
$partner_supplier_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_material_scope_sql = "EXISTS (
        SELECT 1
        FROM bill_of_materials bom_scope
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE bom_scope.material_id = m.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
    $partner_pr_scope_sql = "(pr.requested_by = " . (int)$seller_scope_id . " OR EXISTS (
        SELECT 1
        FROM purchase_requisition_items pri_scope
        INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = pri_scope.material_id
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE pri_scope.pr_id = pr.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    ))";
    $partner_po_scope_sql = "(po.created_by = " . (int)$seller_scope_id . " OR EXISTS (
        SELECT 1
        FROM purchase_order_items poi_scope
        INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = poi_scope.material_id
        INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
        WHERE poi_scope.purchase_order_id = po.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    ))";
    $partner_supplier_scope_sql = "EXISTS (
        SELECT 1
        FROM purchase_orders po_scope
        WHERE po_scope.supplier_id = s.id
          AND (po_scope.created_by = " . (int)$seller_scope_id . " OR EXISTS (
              SELECT 1
              FROM purchase_order_items poi_scope
              INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = poi_scope.material_id
              INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
              WHERE poi_scope.purchase_order_id = po_scope.id
                AND p_scope.seller_id = " . (int)$seller_scope_id . "
          ))
    )";
}

/**
 * Safe helper to keep DSS widgets non-blocking if optional tables are missing.
 */
function safeMrpDssCall($callback, $default) {
    try {
        return $callback();
    } catch (Throwable $e) {
        return $default;
    }
}

function mrpTableExists($conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $safe = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $cache[$table] = (bool)($result && mysqli_num_rows($result) > 0);
}

function mrpColumnExists($conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!mrpTableExists($conn, $table)) {
        return $cache[$key] = false;
    }
    $safeTable = mysqli_real_escape_string($conn, $table);
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
}

function mrpEnsureControlSchema($conn): void {
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS procurement_budget_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT NOT NULL,
            budget_date DATE NOT NULL,
            amount_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_approved DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            requested_by INT NOT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            finance_notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_budget_owner_date (owner_user_id, budget_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS supplier_payment_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT NOT NULL,
            owner_user_id INT NOT NULL,
            payment_date DATE NOT NULL,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(60) NOT NULL DEFAULT 'Cash',
            payment_reference VARCHAR(120) NULL,
            notes TEXT NULL,
            recorded_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_supplier_payment_po (purchase_order_id),
            KEY idx_supplier_payment_owner_date (owner_user_id, payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mrpGenerateProductCode(mysqli $conn): string {
    do {
        try {
            $candidate = 'prod-' . bin2hex(random_bytes(3));
        } catch (Throwable $e) {
            $candidate = 'prod-' . substr(str_replace('.', '', uniqid('', true)), -6);
        }
        $check_stmt = mysqli_prepare($conn, "SELECT 1 FROM products WHERE product_id = ? LIMIT 1");
        if (!$check_stmt) {
            return $candidate;
        }
        mysqli_stmt_bind_param($check_stmt, "s", $candidate);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        $exists = mysqli_stmt_num_rows($check_stmt) > 0;
        mysqli_stmt_close($check_stmt);
    } while ($exists);

    return $candidate;
}

function mrpGetApprovedBudgetSummary($conn, int $ownerUserId, string $budgetDate, string $poScopeSql = ''): array {
    $summary = [
        'approved_total' => 0.0,
        'used_total' => 0.0,
        'remaining_total' => 0.0
    ];
    if (!mrpTableExists($conn, 'procurement_budget_requests')) {
        return $summary;
    }

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

    if (mrpTableExists($conn, 'purchase_orders') && mrpColumnExists($conn, 'purchase_orders', 'order_date') && mrpColumnExists($conn, 'purchase_orders', 'total_amount')) {
        $usedSql = "SELECT COALESCE(SUM(po.total_amount), 0) AS used_total
                    FROM purchase_orders po
                    WHERE po.order_date = ?
                      AND po.status IN ('ordered', 'partially_received', 'completed')";
        if ($poScopeSql !== '') {
            $usedSql .= " AND {$poScopeSql}";
        } else {
            $usedSql .= " AND po.created_by = " . (int)$ownerUserId;
        }
        $stmt = mysqli_prepare($conn, $usedSql);
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

function mrpResolveSalesWindowDays($rawValue): int {
    $allowed = [7, 14, 30, 60];
    $value = (int)$rawValue;
    return in_array($value, $allowed, true) ? $value : 7;
}

function mrpGetSalesDrivenMaterialNeeds(mysqli $conn, ?int $sellerScopeId, string $partnerMaterialScopeSql, string $poUsageScopeSql, int $windowDays, int $limit = 30): array {
    $windowDays = mrpResolveSalesWindowDays($windowDays);
    $limit = max(5, min(200, (int)$limit));

    $salesScopeFilterSql = $sellerScopeId !== null ? " AND p.seller_id = " . (int)$sellerScopeId : "";
    $salesProductJoinSql = "(oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR) OR CAST(oi.product_id AS UNSIGNED) = p.id)";
    $salesStatusSql = "o.is_archived = 0 AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')";
    $salesMaterialScopeSql = ($sellerScopeId !== null && $partnerMaterialScopeSql !== '') ? " AND ({$partnerMaterialScopeSql})" : "";

    $sql = "SELECT
            m.id,
            m.name,
            m.unit,
            m.current_stock,
            m.min_level,
            m.cost_per_unit,
            COALESCE(consw.qty_window, 0) AS demand_window,
            COALESCE(cons30.qty_30d, 0) AS demand_30d,
            COALESCE(open_po.incoming_qty, 0) AS incoming_qty
        FROM materials m
        LEFT JOIN (
            SELECT
                bom.material_id,
                COALESCE(SUM(oi.quantity * bom.quantity_needed), 0) AS qty_window
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN products p ON {$salesProductJoinSql}
            INNER JOIN bill_of_materials bom ON bom.product_id = p.id
            WHERE {$salesStatusSql}
              AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL {$windowDays} DAY)
              {$salesScopeFilterSql}
            GROUP BY bom.material_id
        ) consw ON consw.material_id = m.id
        LEFT JOIN (
            SELECT
                bom.material_id,
                COALESCE(SUM(oi.quantity * bom.quantity_needed), 0) AS qty_30d
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN products p ON {$salesProductJoinSql}
            INNER JOIN bill_of_materials bom ON bom.product_id = p.id
            WHERE {$salesStatusSql}
              AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              {$salesScopeFilterSql}
            GROUP BY bom.material_id
        ) cons30 ON cons30.material_id = m.id
        LEFT JOIN (
            SELECT
                poi.material_id,
                COALESCE(SUM(GREATEST(COALESCE(poi.quantity_ordered, 0) - COALESCE(poi.quantity_received, 0), 0)), 0) AS incoming_qty
            FROM purchase_order_items poi
            INNER JOIN purchase_orders po_usage ON po_usage.id = poi.purchase_order_id
            WHERE po_usage.status IN ('ordered', 'partially_received')
              {$poUsageScopeSql}
            GROUP BY poi.material_id
        ) open_po ON open_po.material_id = m.id
        WHERE (COALESCE(consw.qty_window, 0) > 0 OR COALESCE(cons30.qty_30d, 0) > 0 OR m.current_stock <= m.min_level)
          {$salesMaterialScopeSql}
        ORDER BY COALESCE(consw.qty_window, 0) DESC, COALESCE(cons30.qty_30d, 0) DESC, m.name ASC
        LIMIT {$limit}";

    $rows = [];
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return $rows;
    }

    while ($needRow = mysqli_fetch_assoc($result)) {
        $demandWindow = (float)($needRow['demand_window'] ?? 0);
        $demand30d = (float)($needRow['demand_30d'] ?? 0);
        $avgDailySalesUse = $demand30d > 0 ? ($demand30d / 30) : 0.0;
        $stockAvailable = (float)($needRow['current_stock'] ?? 0) + (float)($needRow['incoming_qty'] ?? 0);
        $targetWindow = max($demandWindow, $avgDailySalesUse * $windowDays);
        $buyQtyWindow = round(max(0, $targetWindow + (float)($needRow['min_level'] ?? 0) - $stockAvailable), 2);
        $buyCostWindow = round($buyQtyWindow * (float)($needRow['cost_per_unit'] ?? 0), 2);
        $daysCoverSales = $avgDailySalesUse > 0 ? ($stockAvailable / $avgDailySalesUse) : null;
        $needPriority = $buyQtyWindow > 0
            ? (($stockAvailable <= 0 || ($daysCoverSales !== null && $daysCoverSales < 3)) ? 'critical' : 'high')
            : 'stable';

        $needRow['avg_daily_sales_use'] = $avgDailySalesUse;
        $needRow['stock_available'] = $stockAvailable;
        $needRow['target_window'] = $targetWindow;
        $needRow['buy_qty_window'] = $buyQtyWindow;
        $needRow['buy_cost_window'] = $buyCostWindow;
        $needRow['days_cover_sales'] = $daysCoverSales;
        $needRow['need_priority'] = $needPriority;
        $rows[] = $needRow;
    }

    return $rows;
}

mrpEnsureControlSchema($conn);

// --- ACTION HANDLING (POST REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $mrp_manage_actions = ['create_pr', 'approve_pr', 'reject_pr', 'save_supplier', 'receive_stock', 'submit_budget_request', 'quick_add_material', 'quick_add_product', 'quick_add_ingredient', 'quick_update_ingredient', 'quick_delete_ingredient', 'create_sales_pr_draft'];
    $finance_control_actions = ['approve_budget_request', 'reject_budget_request', 'record_supplier_payment'];
    if (in_array($action, $mrp_manage_actions, true) && !$can_manage_mrp) {
        $_SESSION['error'] = 'You do not have permission to manage procurement workflow actions.';
        header("Location: mrp.php");
        exit;
    }
    if (in_array($action, $finance_control_actions, true) && !$can_manage_finance_control) {
        $_SESSION['error'] = 'Finance approval is required for this procurement control action.';
        header("Location: mrp.php?tab=budget_payments");
        exit;
    }

    if ($action === 'submit_budget_request') {
        $budget_date = trim((string)($_POST['budget_date'] ?? date('Y-m-d')));
        $amount_requested = round((float)($_POST['amount_requested'] ?? 0), 2);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $budget_date) || $amount_requested <= 0) {
            $_SESSION['error'] = 'Please provide a valid budget date and requested amount.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO procurement_budget_requests
                (owner_user_id, budget_date, amount_requested, amount_approved, notes, status, requested_by)
                VALUES (?, ?, ?, 0.00, ?, 'pending', ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'isdsi', $scope_owner_id, $budget_date, $amount_requested, $notes, $admin_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['success'] = 'Daily procurement budget request submitted for finance review.';
            } else {
                $_SESSION['error'] = 'Unable to submit the procurement budget request.';
            }
        }
        header("Location: mrp.php?tab=budget_payments");
        exit;
    }

    if ($action === 'approve_budget_request' || $action === 'reject_budget_request') {
        $budget_request_id = (int)($_POST['budget_request_id'] ?? 0);
        $approved_amount = round((float)($_POST['amount_approved'] ?? 0), 2);
        $finance_notes = trim((string)($_POST['finance_notes'] ?? ''));
        $status_to_set = $action === 'approve_budget_request' ? 'approved' : 'rejected';
        if ($budget_request_id <= 0) {
            $_SESSION['error'] = 'Invalid budget request selected.';
        } elseif ($status_to_set === 'approved' && $approved_amount <= 0) {
            $_SESSION['error'] = 'Approved budget amount must be greater than zero.';
        } else {
            $status_sql = "UPDATE procurement_budget_requests
                           SET status = ?, amount_approved = ?, finance_notes = ?, approved_by = ?, approved_at = NOW()
                           WHERE id = ? AND owner_user_id = ?";
            $stmt = mysqli_prepare($conn, $status_sql);
            if ($stmt) {
                $final_amount = $status_to_set === 'approved' ? $approved_amount : 0.00;
                mysqli_stmt_bind_param($stmt, 'sdsiii', $status_to_set, $final_amount, $finance_notes, $admin_id, $budget_request_id, $scope_owner_id);
                mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION[$affected > 0 ? 'success' : 'error'] = $affected > 0
                    ? ($status_to_set === 'approved' ? 'Budget request approved.' : 'Budget request rejected.')
                    : 'Budget request was not found for your shop scope.';
            } else {
                $_SESSION['error'] = 'Unable to update the budget request.';
            }
        }
        header("Location: mrp.php?tab=budget_payments");
        exit;
    }

    if ($action === 'record_supplier_payment') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $payment_date = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $amount_paid = round((float)($_POST['amount_paid'] ?? 0), 2);
        $payment_method = trim((string)($_POST['payment_method'] ?? 'Cash'));
        $payment_reference = trim((string)($_POST['payment_reference'] ?? ''));
        $notes = trim((string)($_POST['payment_notes'] ?? ''));
        if ($po_id <= 0 || $amount_paid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            $_SESSION['error'] = 'Please complete the supplier payment form with a valid amount and payment date.';
        } else {
            $po_scope_check_sql = "SELECT po.id
                                   FROM purchase_orders po
                                   WHERE po.id = ? " . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : "");
            $stmt = mysqli_prepare($conn, $po_scope_check_sql);
            $po_exists = false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $po_id);
                mysqli_stmt_execute($stmt);
                $po_exists = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
            }
            if (!$po_exists) {
                $_SESSION['error'] = 'Purchase order not found in your procurement scope.';
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO supplier_payment_records
                    (purchase_order_id, owner_user_id, payment_date, amount_paid, payment_method, payment_reference, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iisdsssi', $po_id, $scope_owner_id, $payment_date, $amount_paid, $payment_method, $payment_reference, $notes, $admin_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $_SESSION['success'] = 'Supplier payment recorded successfully.';
                } else {
                    $_SESSION['error'] = 'Unable to record the supplier payment.';
                }
            }
        }
        header("Location: mrp.php?tab=budget_payments");
        exit;
    }

    if ($action === 'quick_add_material') {
        $name = trim((string)($_POST['setup_material_name'] ?? ''));
        $unit = trim((string)($_POST['setup_material_unit'] ?? ''));
        $current_stock = round((float)($_POST['setup_material_current_stock'] ?? 0), 2);
        $min_level = round((float)($_POST['setup_material_min_level'] ?? 0), 2);
        $cost_per_unit = round((float)($_POST['setup_material_cost_per_unit'] ?? 0), 2);

        if ($name === '' || $unit === '') {
            $_SESSION['error'] = 'Material name and unit are required.';
        } elseif ($current_stock < 0 || $min_level < 0 || $cost_per_unit < 0) {
            $_SESSION['error'] = 'Material stock, minimum level, and cost must not be negative.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO materials (name, unit, current_stock, min_level, cost_per_unit) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ssddd", $name, $unit, $current_stock, $min_level, $cost_per_unit);
                if (mysqli_stmt_execute($stmt)) {
                    $new_material_id = (int)mysqli_insert_id($conn);
                    if (!isset($_SESSION['mrp_recent_material_ids']) || !is_array($_SESSION['mrp_recent_material_ids'])) {
                        $_SESSION['mrp_recent_material_ids'] = [];
                    }
                    $_SESSION['mrp_recent_material_ids'][] = $new_material_id;
                    $_SESSION['mrp_recent_material_ids'] = array_slice(array_values(array_unique(array_map('intval', $_SESSION['mrp_recent_material_ids']))), -30);
                    $_SESSION['success'] = 'Material added. You can now map it as an ingredient below.';
                } else {
                    $_SESSION['error'] = 'Unable to save material. Please try again.';
                }
                mysqli_stmt_close($stmt);
            } else {
                $_SESSION['error'] = 'Unable to add material right now.';
            }
        }
        header("Location: mrp.php?tab=low_stock#setup-inputs");
        exit;
    }

    if ($action === 'quick_add_product') {
        $name = trim((string)($_POST['setup_product_name'] ?? ''));
        $category = trim((string)($_POST['setup_product_category'] ?? ''));
        $price = round((float)($_POST['setup_product_price'] ?? 0), 2);
        $description = trim((string)($_POST['setup_product_description'] ?? ''));

        if ($name === '' || $category === '') {
            $_SESSION['error'] = 'Product name and category are required.';
        } elseif ($price <= 0) {
            $_SESSION['error'] = 'Product price must be greater than zero.';
        } else {
            $product_code = mrpGenerateProductCode($conn);
            if ($seller_scope_id !== null) {
                $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, lead_time_hours, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 24, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "isssds", $seller_scope_id, $product_code, $name, $category, $price, $description);
                }
            } else {
                $insert_query = "INSERT INTO products (product_id, name, category, price, description, lead_time_hours, is_active, created_at) VALUES (?, ?, ?, ?, ?, 24, 1, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "sssds", $product_code, $name, $category, $price, $description);
                }
            }
            if (isset($stmt) && $stmt) {
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['success'] = 'Product added. You can now map ingredients to it.';
                } else {
                    $_SESSION['error'] = 'Unable to save product. Please try again.';
                }
                mysqli_stmt_close($stmt);
            } else {
                $_SESSION['error'] = 'Unable to add product right now.';
            }
        }
        header("Location: mrp.php?tab=low_stock#setup-inputs");
        exit;
    }

    if ($action === 'quick_add_ingredient') {
        $product_id = (int)($_POST['setup_ingredient_product_id'] ?? 0);
        $material_id = (int)($_POST['setup_ingredient_material_id'] ?? 0);
        $quantity_needed = round((float)($_POST['setup_ingredient_qty'] ?? 0), 2);
        $recent_material_ids = array_values(array_unique(array_map('intval', (array)($_SESSION['mrp_recent_material_ids'] ?? []))));

        if ($product_id <= 0 || $material_id <= 0 || $quantity_needed <= 0) {
            $_SESSION['error'] = 'Select product, material, and valid ingredient quantity.';
            header("Location: mrp.php?tab=low_stock#setup-inputs");
            exit;
        }

        $product_scope_sql = "SELECT id FROM products WHERE id = ? " . ($seller_scope_id !== null ? "AND seller_id = ? " : "") . "LIMIT 1";
        $stmt = mysqli_prepare($conn, $product_scope_sql);
        $product_allowed = false;
        if ($stmt) {
            if ($seller_scope_id !== null) {
                mysqli_stmt_bind_param($stmt, 'ii', $product_id, $seller_scope_id);
            } else {
                mysqli_stmt_bind_param($stmt, 'i', $product_id);
            }
            mysqli_stmt_execute($stmt);
            $product_allowed = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }
        if (!$product_allowed) {
            $_SESSION['error'] = 'Selected product is outside your tenant scope.';
            header("Location: mrp.php?tab=low_stock#setup-inputs");
            exit;
        }

        $material_allowed = false;
        if ($seller_scope_id !== null) {
            if (in_array($material_id, $recent_material_ids, true)) {
                $material_allowed = true;
            } else {
                $material_scope_check_sql = "SELECT m.id FROM materials m WHERE m.id = ? AND {$partner_material_scope_sql} LIMIT 1";
                $stmt = mysqli_prepare($conn, $material_scope_check_sql);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $material_id);
                    mysqli_stmt_execute($stmt);
                    $material_allowed = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                    mysqli_stmt_close($stmt);
                }
            }
        } else {
            $material_scope_check_sql = "SELECT id FROM materials WHERE id = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $material_scope_check_sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $material_id);
                mysqli_stmt_execute($stmt);
                $material_allowed = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
            }
        }

        if (!$material_allowed) {
            $_SESSION['error'] = 'Selected material is outside your current setup scope. Add it first in this page.';
            header("Location: mrp.php?tab=low_stock#setup-inputs");
            exit;
        }

        $exists_stmt = mysqli_prepare($conn, "SELECT id FROM bill_of_materials WHERE product_id = ? AND material_id = ? LIMIT 1");
        $existing_bom_id = 0;
        if ($exists_stmt) {
            mysqli_stmt_bind_param($exists_stmt, 'ii', $product_id, $material_id);
            mysqli_stmt_execute($exists_stmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($exists_stmt)) ?: [];
            $existing_bom_id = (int)($existing['id'] ?? 0);
            mysqli_stmt_close($exists_stmt);
        }

        if ($existing_bom_id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE bill_of_materials SET quantity_needed = ? WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "di", $quantity_needed, $existing_bom_id);
                $saved = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION[$saved ? 'success' : 'error'] = $saved ? 'Ingredient quantity updated for this product.' : 'Unable to update ingredient quantity.';
            } else {
                $_SESSION['error'] = 'Unable to update ingredient quantity.';
            }
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO bill_of_materials (product_id, material_id, quantity_needed) VALUES (?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "iid", $product_id, $material_id, $quantity_needed);
                $saved = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION[$saved ? 'success' : 'error'] = $saved ? 'Ingredient linked successfully.' : 'Unable to link ingredient right now.';
            } else {
                $_SESSION['error'] = 'Unable to link ingredient right now.';
            }
        }
        header("Location: mrp.php?tab=low_stock#setup-inputs");
        exit;
    }

    if ($action === 'quick_update_ingredient') {
        $bom_id = (int)($_POST['setup_bom_id'] ?? 0);
        $quantity_needed = round((float)($_POST['setup_ingredient_qty_edit'] ?? 0), 2);
        if ($bom_id <= 0 || $quantity_needed <= 0) {
            $_SESSION['error'] = 'Please enter a valid ingredient quantity.';
            header("Location: mrp.php?tab=low_stock#setup-inputs");
            exit;
        }

        if ($seller_scope_id !== null) {
            $stmt = mysqli_prepare($conn, "UPDATE bill_of_materials bom
                                           INNER JOIN products p ON p.id = bom.product_id
                                           SET bom.quantity_needed = ?
                                           WHERE bom.id = ? AND p.seller_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "dii", $quantity_needed, $bom_id, $seller_scope_id);
            }
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE bill_of_materials SET quantity_needed = ? WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "di", $quantity_needed, $bom_id);
            }
        }

        if (isset($stmt) && $stmt) {
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION[$affected > 0 ? 'success' : 'error'] = $affected > 0
                ? 'Ingredient quantity updated.'
                : 'Ingredient mapping was not found in your store scope.';
        } else {
            $_SESSION['error'] = 'Unable to update this ingredient mapping.';
        }
        header("Location: mrp.php?tab=low_stock#setup-inputs");
        exit;
    }

    if ($action === 'quick_delete_ingredient') {
        $bom_id = (int)($_POST['setup_bom_id'] ?? 0);
        if ($bom_id <= 0) {
            $_SESSION['error'] = 'Invalid ingredient mapping selected.';
            header("Location: mrp.php?tab=low_stock#setup-inputs");
            exit;
        }

        if ($seller_scope_id !== null) {
            $stmt = mysqli_prepare($conn, "DELETE bom
                                           FROM bill_of_materials bom
                                           INNER JOIN products p ON p.id = bom.product_id
                                           WHERE bom.id = ? AND p.seller_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ii", $bom_id, $seller_scope_id);
            }
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM bill_of_materials WHERE id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $bom_id);
            }
        }

        if (isset($stmt) && $stmt) {
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION[$affected > 0 ? 'success' : 'error'] = $affected > 0
                ? 'Ingredient mapping removed.'
                : 'Ingredient mapping was not found in your store scope.';
        } else {
            $_SESSION['error'] = 'Unable to remove this ingredient mapping.';
        }
        header("Location: mrp.php?tab=low_stock#setup-inputs");
        exit;
    }

    if ($action === 'create_sales_pr_draft') {
        $sales_window_days_post = mrpResolveSalesWindowDays($_POST['sales_window'] ?? 7);
        $post_po_usage_scope_sql = $seller_scope_id !== null ? str_replace('po.', 'po_usage.', $partner_po_scope_sql) : '';
        $candidate_rows = mrpGetSalesDrivenMaterialNeeds(
            $conn,
            $seller_scope_id !== null ? (int)$seller_scope_id : null,
            $partner_material_scope_sql,
            $post_po_usage_scope_sql,
            $sales_window_days_post,
            80
        );
        $candidate_rows = array_values(array_filter($candidate_rows, function ($row) {
            return (float)($row['buy_qty_window'] ?? 0) > 0;
        }));

        if (empty($candidate_rows)) {
            $_SESSION['error'] = "No sales-driven ingredient shortages found for the selected {$sales_window_days_post}-day window.";
            header("Location: mrp.php?tab=low_stock&sales_window={$sales_window_days_post}#setup-inputs");
            exit;
        }

        $request_date = date('Y-m-d');
        $notes = "Auto-generated from sales-driven material planner ({$sales_window_days_post}-day window).";
        $pr_number = 'PR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $has_pr_estimated_cost = mrpColumnExists($conn, 'purchase_requisition_items', 'estimated_cost');

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO purchase_requisitions (pr_number, requested_by, request_date, notes, status) VALUES (?, ?, ?, ?, 'pending')");
            if (!$stmt) {
                throw new Exception('Unable to create sales-driven requisition header.');
            }
            mysqli_stmt_bind_param($stmt, "siss", $pr_number, $admin_id, $request_date, $notes);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Unable to create sales-driven requisition.');
            }
            $pr_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $item_stmt = mysqli_prepare(
                $conn,
                $has_pr_estimated_cost
                    ? "INSERT INTO purchase_requisition_items (pr_id, material_id, quantity_requested, estimated_cost) VALUES (?, ?, ?, ?)"
                    : "INSERT INTO purchase_requisition_items (pr_id, material_id, quantity_requested) VALUES (?, ?, ?)"
            );
            if (!$item_stmt) {
                throw new Exception('Unable to prepare sales-driven requisition item insertion.');
            }

            $added_items = 0;
            foreach ($candidate_rows as $candidate_row) {
                $material_id = (int)($candidate_row['id'] ?? 0);
                $quantity_requested = (float)($candidate_row['buy_qty_window'] ?? 0);
                $estimated_cost = round((float)($candidate_row['buy_cost_window'] ?? 0), 2);
                if ($material_id <= 0 || $quantity_requested <= 0) {
                    continue;
                }
                if ($has_pr_estimated_cost) {
                    mysqli_stmt_bind_param($item_stmt, "iidd", $pr_id, $material_id, $quantity_requested, $estimated_cost);
                } else {
                    mysqli_stmt_bind_param($item_stmt, "iid", $pr_id, $material_id, $quantity_requested);
                }
                mysqli_stmt_execute($item_stmt);
                $added_items++;
            }
            mysqli_stmt_close($item_stmt);

            if ($added_items <= 0) {
                throw new Exception("No valid sales-driven materials were inserted into the requisition.");
            }

            mysqli_commit($conn);
            $_SESSION['success'] = "Sales-driven draft requisition {$pr_number} created with {$added_items} item(s).";
            header("Location: mrp.php?tab=requisitions");
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $_SESSION['error'] = 'Unable to generate sales-driven requisition: ' . $e->getMessage();
            header("Location: mrp.php?tab=low_stock&sales_window={$sales_window_days_post}#setup-inputs");
            exit;
        }
    }

    // --- 1. Purchase Requisition Actions ---
    if ($action === 'create_pr') {
        $request_date = date('Y-m-d');
        $notes = trim($_POST['notes']);
        $pr_number = 'PR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $has_pr_estimated_cost = mrpColumnExists($conn, 'purchase_requisition_items', 'estimated_cost');
        
        // Create PR Header
        $stmt = mysqli_prepare($conn, "INSERT INTO purchase_requisitions (pr_number, requested_by, request_date, notes, status) VALUES (?, ?, ?, ?, 'pending')");
        mysqli_stmt_bind_param($stmt, "siss", $pr_number, $admin_id, $request_date, $notes);
        
        if (mysqli_stmt_execute($stmt)) {
            $pr_id = mysqli_insert_id($conn);
            
            // Add Items
            $material_ids = $_POST['material_id'];
            $quantities = $_POST['quantity'];
            
            $item_stmt = mysqli_prepare(
                $conn,
                $has_pr_estimated_cost
                    ? "INSERT INTO purchase_requisition_items (pr_id, material_id, quantity_requested, estimated_cost) VALUES (?, ?, ?, ?)"
                    : "INSERT INTO purchase_requisition_items (pr_id, material_id, quantity_requested) VALUES (?, ?, ?)"
            );
            $added_items = 0;
            foreach ($material_ids as $index => $mid) {
                $mid = (int)$mid;
                $qty = floatval($quantities[$index] ?? 0);
                if ($mid > 0 && $qty > 0) {
                    if ($seller_scope_id !== null) {
                        $material_scope_check = mysqli_query($conn, "
                            SELECT m.id
                            FROM materials m
                            WHERE m.id = {$mid}
                              AND {$partner_material_scope_sql}
                            LIMIT 1
                        ");
                        if (!$material_scope_check || !mysqli_fetch_assoc($material_scope_check)) {
                            continue;
                        }
                    }
                    $estimated_cost = 0.0;
                    $material_cost_stmt = mysqli_prepare($conn, "SELECT cost_per_unit FROM materials WHERE id = ? LIMIT 1");
                    if ($material_cost_stmt) {
                        mysqli_stmt_bind_param($material_cost_stmt, 'i', $mid);
                        mysqli_stmt_execute($material_cost_stmt);
                        $material_row = mysqli_fetch_assoc(mysqli_stmt_get_result($material_cost_stmt)) ?: [];
                        mysqli_stmt_close($material_cost_stmt);
                        $estimated_cost = round($qty * (float)($material_row['cost_per_unit'] ?? 0), 2);
                    }
                    if ($has_pr_estimated_cost) {
                        mysqli_stmt_bind_param($item_stmt, "iidd", $pr_id, $mid, $qty, $estimated_cost);
                    } else {
                        mysqli_stmt_bind_param($item_stmt, "iid", $pr_id, $mid, $qty);
                    }
                    mysqli_stmt_execute($item_stmt);
                    $added_items++;
                }
            }
            if ($added_items === 0) {
                mysqli_query($conn, "DELETE FROM purchase_requisitions WHERE id = " . (int)$pr_id . " LIMIT 1");
                $_SESSION['error'] = "No valid scoped materials were selected for this requisition.";
                header("Location: mrp.php?tab=requisitions");
                exit;
            }
            $_SESSION['success'] = "Purchase Requisition #$pr_number created successfully.";
        } else {
            $_SESSION['error'] = "Error creating PR: " . mysqli_error($conn);
        }
        header("Location: mrp.php?tab=requisitions");
        exit;
    }

    if ($action === 'approve_pr') {
        $pr_id = intval($_POST['pr_id']);
        // In a real system, check if user has 'manager' role here
        $approve_sql = "UPDATE purchase_requisitions pr
                        SET pr.status = 'approved', pr.approved_by = ?, pr.approval_date = NOW()
                        WHERE pr.id = ?" . ($seller_scope_id !== null ? " AND {$partner_pr_scope_sql}" : "");
        $stmt = mysqli_prepare($conn, $approve_sql);
        mysqli_stmt_bind_param($stmt, "ii", $admin_id, $pr_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) <= 0) {
            $_SESSION['error'] = "Requisition not found or not within your store scope.";
            mysqli_stmt_close($stmt);
            header("Location: mrp.php?tab=requisitions");
            exit;
        }
        mysqli_stmt_close($stmt);
        $_SESSION['success'] = "Requisition approved. Ready for PO creation.";
        header("Location: mrp.php?tab=requisitions");
        exit;
    }

    if ($action === 'reject_pr') {
        $pr_id = intval($_POST['pr_id']);
        $reject_sql = "UPDATE purchase_requisitions pr
                       SET pr.status = 'rejected', pr.approved_by = ?, pr.approval_date = NOW()
                       WHERE pr.id = ?" . ($seller_scope_id !== null ? " AND {$partner_pr_scope_sql}" : "");
        $stmt = mysqli_prepare($conn, $reject_sql);
        mysqli_stmt_bind_param($stmt, "ii", $admin_id, $pr_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) <= 0) {
            $_SESSION['error'] = "Requisition not found or not within your store scope.";
            mysqli_stmt_close($stmt);
            header("Location: mrp.php?tab=requisitions");
            exit;
        }
        mysqli_stmt_close($stmt);
        $_SESSION['success'] = "Requisition rejected.";
        header("Location: mrp.php?tab=requisitions");
        exit;
    }

    // Supplier Actions
    if ($action === 'save_supplier') {
        if ($seller_scope_id !== null) {
            $_SESSION['error'] = "Supplier profile management is only available to the system owner.";
            header("Location: mrp.php?tab=suppliers");
            exit;
        }
        $id = intval($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name']);
        $contact = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE suppliers SET name=?, contact_person=?, email=?, phone=?, address=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $contact, $email, $phone, $address, $id);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $contact, $email, $phone, $address);
        }
        mysqli_stmt_execute($stmt);
        $_SESSION['success'] = "Supplier saved successfully.";
        header("Location: mrp.php?tab=suppliers");
        exit;
    }

    // Receive Stock Action
    if ($action === 'receive_stock') {
        $po_id = intval($_POST['po_id']);
        $quantities = $_POST['quantity_received'];
        $item_ids = $_POST['item_id'];

        if ($seller_scope_id !== null) {
            $po_scope_check = mysqli_query($conn, "
                SELECT po.id
                FROM purchase_orders po
                WHERE po.id = {$po_id}
                  AND {$partner_po_scope_sql}
                LIMIT 1
            ");
            if (!$po_scope_check || !mysqli_fetch_assoc($po_scope_check)) {
                $_SESSION['error'] = "Purchase order is not within your store scope.";
                header("Location: mrp.php?tab=purchase_orders");
                exit;
            }
        }

        mysqli_begin_transaction($conn);
        try {
            foreach ($item_ids as $index => $item_id) {
                $qty_received = floatval($quantities[$index]);
                if ($qty_received > 0) {
                    // Get item details
                    $item_res = mysqli_query($conn, "SELECT * FROM purchase_order_items WHERE id = " . (int)$item_id . " AND purchase_order_id = " . (int)$po_id);
                    $item = mysqli_fetch_assoc($item_res);
                    if (!$item) {
                        continue;
                    }
                    if ($seller_scope_id !== null) {
                        $material_scope_check = mysqli_query($conn, "
                            SELECT m.id
                            FROM materials m
                            WHERE m.id = " . (int)$item['material_id'] . "
                              AND {$partner_material_scope_sql}
                            LIMIT 1
                        ");
                        if (!$material_scope_check || !mysqli_fetch_assoc($material_scope_check)) {
                            continue;
                        }
                    }

                    // Update PO item
                    mysqli_query($conn, "UPDATE purchase_order_items SET quantity_received = quantity_received + {$qty_received} WHERE id = " . (int)$item_id);

                    // Update material stock (Inventory Logbook)
                    // Check if inventory record exists for today, if not create, else update
                    $today = date('Y-m-d');
                    $mat_id = $item['material_id'];
                    
                    // Update master stock in materials table
                    mysqli_query($conn, "UPDATE materials SET current_stock = current_stock + $qty_received WHERE id = $mat_id");
                    
                    // Update daily inventory tracking
                    $check_inv = mysqli_query($conn, "SELECT id FROM inventory WHERE product_id = $mat_id AND inventory_date = '$today'");
                    if (mysqli_num_rows($check_inv) > 0) {
                        mysqli_query($conn, "UPDATE inventory SET current_stock = current_stock + $qty_received WHERE product_id = $mat_id AND inventory_date = '$today'");
                    } else {
                        // Get min level
                        $mat_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT min_level FROM materials WHERE id = $mat_id"));
                        $min = $mat_info['min_level'];
                        mysqli_query($conn, "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level) VALUES ($mat_id, '$today', $qty_received, $min)");
                    }

                    // Log in inventory history
                    $log_stmt = mysqli_prepare($conn, "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, notes, admin_id) VALUES (?, 'received', ?, ?, ?)");
                    $notes = "Delivery Received (PO #$po_id)";
                    mysqli_stmt_bind_param($log_stmt, "idsi", $item['material_id'], $qty_received, $notes, $admin_id);
                    mysqli_stmt_execute($log_stmt);
                }
            }

            // Check if PO is fully received and update status
            $check_res = mysqli_query($conn, "SELECT SUM(quantity_ordered) as ordered, SUM(quantity_received) as received FROM purchase_order_items WHERE purchase_order_id = $po_id");
            $totals = mysqli_fetch_assoc($check_res);
            if ($totals['received'] >= $totals['ordered']) {
                mysqli_query($conn, "UPDATE purchase_orders SET status = 'completed' WHERE id = $po_id");
            } else {
                mysqli_query($conn, "UPDATE purchase_orders SET status = 'partially_received' WHERE id = $po_id");
            }

            mysqli_commit($conn);
            $_SESSION['success'] = "Items inspected and accepted. Inventory updated.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error'] = "Error receiving stock: " . $e->getMessage();
        }
        header("Location: mrp.php?tab=purchase_orders");
        exit;
    }
}

// Fetch Materials for PR Modal
$materials_res = mysqli_query($conn, "SELECT m.id, m.name, m.unit FROM materials m" . ($seller_scope_id !== null ? " WHERE {$partner_material_scope_sql}" : "") . " ORDER BY m.name");
$materials_list = [];
while($m = mysqli_fetch_assoc($materials_res)) $materials_list[] = $m;
$mrp_recent_material_ids = array_values(array_unique(array_map('intval', (array)($_SESSION['mrp_recent_material_ids'] ?? []))));

$setup_products = [];
$setup_products_sql = "SELECT p.id, p.name, p.category
                       FROM products p
                       WHERE COALESCE(p.is_archived, 0) = 0"
                       . ($seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "") . "
                       ORDER BY p.name ASC
                       LIMIT 120";
$setup_products_res = mysqli_query($conn, $setup_products_sql);
if ($setup_products_res) {
    while ($row = mysqli_fetch_assoc($setup_products_res)) {
        $setup_products[] = $row;
    }
}

$setup_materials = [];
if ($seller_scope_id !== null) {
    $setup_scope_filters = ["{$partner_material_scope_sql}"];
    if (!empty($mrp_recent_material_ids)) {
        $safe_recent_material_ids = implode(',', array_map('intval', $mrp_recent_material_ids));
        $setup_scope_filters[] = "m.id IN ({$safe_recent_material_ids})";
    }
    $setup_materials_sql = "SELECT m.id, m.name, m.unit, m.cost_per_unit
                            FROM materials m
                            WHERE (" . implode(' OR ', $setup_scope_filters) . ")
                            ORDER BY m.name ASC
                            LIMIT 200";
} else {
    $setup_materials_sql = "SELECT m.id, m.name, m.unit, m.cost_per_unit
                            FROM materials m
                            ORDER BY m.name ASC
                            LIMIT 200";
}
$setup_materials_res = mysqli_query($conn, $setup_materials_sql);
if ($setup_materials_res) {
    while ($row = mysqli_fetch_assoc($setup_materials_res)) {
        $setup_materials[] = $row;
    }
}

$setup_ingredient_rows = [];
$setup_ingredient_sql = "SELECT bom.id, p.name AS product_name, m.name AS material_name, bom.quantity_needed, m.unit
                         FROM bill_of_materials bom
                         INNER JOIN products p ON p.id = bom.product_id
                         INNER JOIN materials m ON m.id = bom.material_id
                         WHERE COALESCE(p.is_archived, 0) = 0"
                         . ($seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "") . "
                         ORDER BY bom.id DESC
                         LIMIT 12";
$setup_ingredient_res = mysqli_query($conn, $setup_ingredient_sql);
if ($setup_ingredient_res) {
    while ($row = mysqli_fetch_assoc($setup_ingredient_res)) {
        $setup_ingredient_rows[] = $row;
    }
}

// Procurement and planning KPIs for DSS-aided decision making.
$mrp_kpis = [
    'pending_pr' => 0,
    'approved_pr' => 0,
    'open_po' => 0,
    'awaiting_delivery_po' => 0,
    'partially_received_po' => 0,
    'critical_materials' => 0
];

$pending_pr_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM purchase_requisitions pr WHERE pr.status = 'pending'" . ($seller_scope_id !== null ? " AND {$partner_pr_scope_sql}" : ""));
if ($pending_pr_result && ($row = mysqli_fetch_assoc($pending_pr_result))) {
    $mrp_kpis['pending_pr'] = (int)$row['count'];
}

$approved_pr_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM purchase_requisitions pr WHERE pr.status = 'approved'" . ($seller_scope_id !== null ? " AND {$partner_pr_scope_sql}" : ""));
if ($approved_pr_result && ($row = mysqli_fetch_assoc($approved_pr_result))) {
    $mrp_kpis['approved_pr'] = (int)$row['count'];
}

$open_po_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM purchase_orders po WHERE po.status IN ('pending', 'ordered', 'partially_received')" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : ""));
if ($open_po_result && ($row = mysqli_fetch_assoc($open_po_result))) {
    $mrp_kpis['open_po'] = (int)$row['count'];
}

$awaiting_delivery_po_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM purchase_orders po WHERE po.status IN ('pending', 'ordered')" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : ""));
if ($awaiting_delivery_po_result && ($row = mysqli_fetch_assoc($awaiting_delivery_po_result))) {
    $mrp_kpis['awaiting_delivery_po'] = (int)$row['count'];
}

$partial_po_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM purchase_orders po WHERE po.status = 'partially_received'" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : ""));
if ($partial_po_result && ($row = mysqli_fetch_assoc($partial_po_result))) {
    $mrp_kpis['partially_received_po'] = (int)$row['count'];
}

$critical_material_result = mysqli_query($conn, "SELECT COUNT(*) AS count FROM materials m WHERE m.current_stock <= m.min_level" . ($seller_scope_id !== null ? " AND {$partner_material_scope_sql}" : ""));
if ($critical_material_result && ($row = mysqli_fetch_assoc($critical_material_result))) {
    $mrp_kpis['critical_materials'] = (int)$row['count'];
}

$material_shortages = [];
$shortage_result = mysqli_query($conn, "
    SELECT m.id, m.name, m.unit, m.current_stock, m.min_level, GREATEST(m.min_level - m.current_stock, 0) AS shortage
    FROM materials m
    WHERE m.current_stock <= m.min_level" . ($seller_scope_id !== null ? "
      AND {$partner_material_scope_sql}" : "") . "
    ORDER BY shortage DESC, name ASC
    LIMIT 6
");
if ($shortage_result) {
    while ($row = mysqli_fetch_assoc($shortage_result)) {
        $material_shortages[] = $row;
    }
}

$insights_service = new DSSInsightsService($conn);
$forecast_summary = safeMrpDssCall(function () use ($insights_service) {
    return $insights_service->getForecastingSummary(7);
}, ['predicted_orders' => 0, 'avg_confidence' => 0]);

$decision_brief = safeMrpDssCall(function () use ($insights_service) {
    return $insights_service->generateDecisionBrief(21);
}, []);

$partner_cost_snapshot = null;
if ($seller_scope_id !== null) {
    $economicsService = new PartnerBusinessEconomicsService($conn);
    $partner_cost_snapshot = $economicsService->getSnapshot((int)$seller_scope_id, date('Y-m'));
}

$today_budget_summary = mrpGetApprovedBudgetSummary($conn, $scope_owner_id, date('Y-m-d'), $seller_scope_id !== null ? $partner_po_scope_sql : '');
$budget_requests = [];
$budget_request_query = "SELECT b.*
                         FROM procurement_budget_requests b
                         WHERE b.owner_user_id = " . (int)$scope_owner_id . "
                         ORDER BY b.budget_date DESC, b.created_at DESC
                         LIMIT 10";
$budget_request_result = mysqli_query($conn, $budget_request_query);
if ($budget_request_result) {
    while ($row = mysqli_fetch_assoc($budget_request_result)) {
        $budget_requests[] = $row;
    }
}
$pending_budget_count = 0;
foreach ($budget_requests as $budget_request_row) {
    if (($budget_request_row['status'] ?? '') === 'pending') {
        $pending_budget_count++;
    }
}

$supplier_payment_rows = [];
$supplier_payment_map = [];
$payment_kpis = ['paid_this_month' => 0.0, 'outstanding_total' => 0.0, 'unpaid_po_count' => 0];
$payment_sql = "SELECT po.id, po.po_number, po.order_date, po.total_amount, po.status,
                       COALESCE(s.name, 'N/A') AS supplier_name,
                       COALESCE(pay.total_paid, 0) AS total_paid,
                       pay.last_payment_date
                FROM purchase_orders po
                LEFT JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN (
                    SELECT purchase_order_id, SUM(amount_paid) AS total_paid, MAX(payment_date) AS last_payment_date
                    FROM supplier_payment_records
                    GROUP BY purchase_order_id
                ) pay ON pay.purchase_order_id = po.id
                WHERE po.status <> 'cancelled'" . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : "") . "
                ORDER BY po.order_date DESC, po.id DESC
                LIMIT 12";
$payment_result = mysqli_query($conn, $payment_sql);
if ($payment_result) {
    while ($row = mysqli_fetch_assoc($payment_result)) {
        $row['outstanding_amount'] = max(0, (float)($row['total_amount'] ?? 0) - (float)($row['total_paid'] ?? 0));
        $supplier_payment_rows[] = $row;
        $supplier_payment_map[(int)$row['id']] = $row;
        $payment_kpis['outstanding_total'] += (float)$row['outstanding_amount'];
        if ($row['outstanding_amount'] > 0 && in_array((string)($row['status'] ?? ''), ['ordered', 'partially_received', 'completed'], true)) {
            $payment_kpis['unpaid_po_count']++;
        }
    }
}
$paid_this_month_sql = "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
                        FROM supplier_payment_records
                        WHERE owner_user_id = ?
                          AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                          AND payment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)";
$stmt = mysqli_prepare($conn, $paid_this_month_sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $scope_owner_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);
    $payment_kpis['paid_this_month'] = (float)($row['total_paid'] ?? 0);
}

$partner_pr_usage_scope_sql = $seller_scope_id !== null ? str_replace('pr.', 'pr_usage.', $partner_pr_scope_sql) : '';
$partner_po_usage_scope_sql = $seller_scope_id !== null ? str_replace('po.', 'po_usage.', $partner_po_scope_sql) : '';
$partner_po_fill_scope_sql = $seller_scope_id !== null ? str_replace('po.', 'po_fill.', $partner_po_scope_sql) : '';
$partner_po_cost_scope_sql = $seller_scope_id !== null ? str_replace('po.', 'po_cost.', $partner_po_scope_sql) : '';
$partner_po_pref_scope_sql = $seller_scope_id !== null ? str_replace('po.', 'po_pref.', $partner_po_scope_sql) : '';

$procurement_health = [
    'overdue_delivery_po' => 0,
    'avg_lead_days' => 0.0,
    'fill_rate_30d' => 0.0,
    'at_risk_materials' => 0
];

$overdue_po_sql = "SELECT COUNT(*) AS count
                   FROM purchase_orders po
                   WHERE po.status IN ('ordered', 'partially_received')
                     AND po.expected_delivery_date IS NOT NULL
                     AND po.expected_delivery_date < CURDATE()"
                   . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : "");
$overdue_po_result = mysqli_query($conn, $overdue_po_sql);
if ($overdue_po_result && ($row = mysqli_fetch_assoc($overdue_po_result))) {
    $procurement_health['overdue_delivery_po'] = (int)($row['count'] ?? 0);
}

$avg_lead_sql = "SELECT AVG(GREATEST(DATEDIFF(po.expected_delivery_date, po.order_date), 0)) AS avg_lead_days
                 FROM purchase_orders po
                 WHERE po.expected_delivery_date IS NOT NULL
                   AND po.order_date IS NOT NULL
                   AND po.status <> 'cancelled'"
                 . ($seller_scope_id !== null ? " AND {$partner_po_scope_sql}" : "");
$avg_lead_result = mysqli_query($conn, $avg_lead_sql);
if ($avg_lead_result && ($row = mysqli_fetch_assoc($avg_lead_result))) {
    $procurement_health['avg_lead_days'] = (float)($row['avg_lead_days'] ?? 0);
}

$fill_rate_sql = "SELECT
                    COALESCE(SUM(COALESCE(poi.quantity_received, 0)), 0) AS qty_received,
                    COALESCE(SUM(COALESCE(poi.quantity_ordered, 0)), 0) AS qty_ordered
                  FROM purchase_order_items poi
                  INNER JOIN purchase_orders po_fill ON po_fill.id = poi.purchase_order_id
                  WHERE po_fill.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND po_fill.status IN ('ordered', 'partially_received', 'completed')"
                  . ($seller_scope_id !== null ? " AND {$partner_po_fill_scope_sql}" : "");
$fill_rate_result = mysqli_query($conn, $fill_rate_sql);
if ($fill_rate_result && ($row = mysqli_fetch_assoc($fill_rate_result))) {
    $ordered_qty_30d = (float)($row['qty_ordered'] ?? 0);
    $received_qty_30d = (float)($row['qty_received'] ?? 0);
    $procurement_health['fill_rate_30d'] = $ordered_qty_30d > 0
        ? round(($received_qty_30d / $ordered_qty_30d) * 100, 1)
        : 0.0;
}

$material_planner_rows = [];
$material_planner_summary = [
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'estimated_reorder_cost' => 0.0
];
$planner_scope_material = $seller_scope_id !== null ? " AND {$partner_material_scope_sql}" : "";
$planner_scope_pr_usage = $seller_scope_id !== null ? " AND {$partner_pr_usage_scope_sql}" : "";
$planner_scope_po_usage = $seller_scope_id !== null ? " AND {$partner_po_usage_scope_sql}" : "";
$planner_scope_po_cost = $seller_scope_id !== null ? " AND {$partner_po_cost_scope_sql}" : "";
$planner_scope_po_pref = $seller_scope_id !== null ? " AND {$partner_po_pref_scope_sql}" : "";

$material_planner_sql = "
    SELECT
        m.id,
        m.name,
        m.unit,
        m.current_stock,
        m.min_level,
        m.cost_per_unit,
        COALESCE(usage_30.avg_daily_usage_30d, 0) AS avg_daily_usage_30d,
        COALESCE(open_po.incoming_qty, 0) AS incoming_qty,
        open_po.next_expected_delivery,
        COALESCE(cost_agg.avg_unit_cost, m.cost_per_unit) AS avg_unit_cost,
        cost_agg.last_order_date,
        (
            SELECT COALESCE(s_pref.name, 'N/A')
            FROM purchase_order_items poi_pref
            INNER JOIN purchase_orders po_pref ON po_pref.id = poi_pref.purchase_order_id
            LEFT JOIN suppliers s_pref ON s_pref.id = po_pref.supplier_id
            WHERE poi_pref.material_id = m.id
              AND po_pref.status <> 'cancelled'
              {$planner_scope_po_pref}
            GROUP BY po_pref.supplier_id, s_pref.name
            ORDER BY SUM(COALESCE(poi_pref.quantity_ordered, 0)) DESC, MAX(po_pref.order_date) DESC
            LIMIT 1
        ) AS preferred_supplier
    FROM materials m
    LEFT JOIN (
        SELECT
            pri.material_id,
            COALESCE(SUM(COALESCE(pri.quantity_requested, 0)) / 30, 0) AS avg_daily_usage_30d
        FROM purchase_requisition_items pri
        INNER JOIN purchase_requisitions pr_usage ON pr_usage.id = pri.pr_id
        WHERE pr_usage.request_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND pr_usage.status IN ('approved', 'po_created')
          {$planner_scope_pr_usage}
        GROUP BY pri.material_id
    ) usage_30 ON usage_30.material_id = m.id
    LEFT JOIN (
        SELECT
            poi.material_id,
            COALESCE(SUM(GREATEST(COALESCE(poi.quantity_ordered, 0) - COALESCE(poi.quantity_received, 0), 0)), 0) AS incoming_qty,
            MIN(CASE
                    WHEN po_usage.expected_delivery_date IS NOT NULL
                         AND po_usage.expected_delivery_date >= CURDATE()
                    THEN po_usage.expected_delivery_date
                    ELSE NULL
                END) AS next_expected_delivery
        FROM purchase_order_items poi
        INNER JOIN purchase_orders po_usage ON po_usage.id = poi.purchase_order_id
        WHERE po_usage.status IN ('ordered', 'partially_received')
          {$planner_scope_po_usage}
        GROUP BY poi.material_id
    ) open_po ON open_po.material_id = m.id
    LEFT JOIN (
        SELECT
            poi.material_id,
            AVG(NULLIF(COALESCE(poi.unit_cost, 0), 0)) AS avg_unit_cost,
            MAX(po_cost.order_date) AS last_order_date
        FROM purchase_order_items poi
        INNER JOIN purchase_orders po_cost ON po_cost.id = poi.purchase_order_id
        WHERE po_cost.status <> 'cancelled'
          {$planner_scope_po_cost}
        GROUP BY poi.material_id
    ) cost_agg ON cost_agg.material_id = m.id
    WHERE m.current_stock <= (m.min_level * 1.50)
      {$planner_scope_material}
    ORDER BY (m.min_level - m.current_stock) DESC, m.name ASC
    LIMIT 20
";
$material_planner_result = mysqli_query($conn, $material_planner_sql);
$default_lead_days = (int)max(2, round((float)($procurement_health['avg_lead_days'] > 0 ? $procurement_health['avg_lead_days'] : 3)));
$today_timestamp = strtotime(date('Y-m-d'));
if ($material_planner_result) {
    while ($planner_row = mysqli_fetch_assoc($material_planner_result)) {
        $current_stock = (float)($planner_row['current_stock'] ?? 0);
        $minimum_stock = (float)($planner_row['min_level'] ?? 0);
        $incoming_qty = (float)($planner_row['incoming_qty'] ?? 0);
        $avg_daily_usage = max(0, (float)($planner_row['avg_daily_usage_30d'] ?? 0));
        $lead_days_for_row = $default_lead_days;
        $next_eta = trim((string)($planner_row['next_expected_delivery'] ?? ''));
        if ($next_eta !== '') {
            $eta_timestamp = strtotime($next_eta);
            if ($eta_timestamp !== false && $eta_timestamp >= $today_timestamp) {
                $lead_days_for_row = max(1, (int)ceil(($eta_timestamp - $today_timestamp) / 86400));
            }
        }

        $review_period_days = 2;
        $safety_days = 2;
        if ($avg_daily_usage > 0) {
            $target_stock = max($minimum_stock * 1.20, $avg_daily_usage * ($lead_days_for_row + $review_period_days + $safety_days));
            $days_cover = $current_stock / $avg_daily_usage;
        } else {
            $target_stock = max($minimum_stock * 1.30, $minimum_stock + max(0, $minimum_stock - $current_stock));
            $days_cover = null;
        }
        $suggested_qty = round(max(0, $target_stock - ($current_stock + $incoming_qty)), 2);
        $avg_unit_cost = (float)($planner_row['avg_unit_cost'] ?? 0);
        if ($avg_unit_cost <= 0) {
            $avg_unit_cost = (float)($planner_row['cost_per_unit'] ?? 0);
        }
        $estimated_reorder_cost = round($suggested_qty * $avg_unit_cost, 2);

        $urgency_key = 'low';
        if ($current_stock <= 0) {
            $urgency_key = 'critical';
        } elseif ($days_cover !== null && $days_cover <= max(1, $lead_days_for_row)) {
            $urgency_key = 'critical';
        } elseif (($current_stock + $incoming_qty) < $minimum_stock) {
            $urgency_key = 'high';
        } elseif ($days_cover !== null && $days_cover <= ($lead_days_for_row + 2)) {
            $urgency_key = 'medium';
        } elseif ($current_stock <= $minimum_stock) {
            $urgency_key = 'medium';
        }

        $urgency_label = match ($urgency_key) {
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            default => 'Low'
        };
        $days_cover_label = $days_cover === null ? 'No demand history' : number_format($days_cover, 1) . ' day(s)';

        $planner_row['lead_days_for_row'] = $lead_days_for_row;
        $planner_row['target_stock'] = round($target_stock, 2);
        $planner_row['days_cover'] = $days_cover;
        $planner_row['days_cover_label'] = $days_cover_label;
        $planner_row['suggested_qty'] = $suggested_qty;
        $planner_row['estimated_reorder_cost'] = $estimated_reorder_cost;
        $planner_row['urgency_key'] = $urgency_key;
        $planner_row['urgency_label'] = $urgency_label;
        $material_planner_rows[] = $planner_row;

        if (isset($material_planner_summary[$urgency_key])) {
            $material_planner_summary[$urgency_key]++;
        }
        $material_planner_summary['estimated_reorder_cost'] += $estimated_reorder_cost;
    }
}
$procurement_health['at_risk_materials'] = (int)$material_planner_summary['critical'] + (int)$material_planner_summary['high'];
$finance_budget_ready_today = (float)($today_budget_summary['approved_total'] ?? 0) > 0;
$finance_budget_remaining_today = max(0, (float)($today_budget_summary['remaining_total'] ?? 0));
$finance_budget_gate_open = $finance_budget_ready_today && $finance_budget_remaining_today > 0;
$finance_budget_gate_reason = !$finance_budget_ready_today
    ? 'Finance budget approval for today is required before creating PO actions.'
    : ($finance_budget_remaining_today <= 0 ? 'Approved budget is exhausted today. Request additional budget first.' : '');
$sales_window_options = [7, 14, 30, 60];
$sales_window_days = mrpResolveSalesWindowDays($_GET['sales_window'] ?? 7);

$sales_scope_filter_sql = $seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "";
$sales_product_join_sql = "(oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR) OR CAST(oi.product_id AS UNSIGNED) = p.id)";
$sales_status_sql = "o.is_archived = 0 AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')";
$sales_group_product_expr = "COALESCE(NULLIF(TRIM(p.name), ''), oi.product_name)";

$sales_summary_7d = ['orders' => 0, 'items' => 0, 'revenue' => 0.0];
$sales_summary_30d = ['orders' => 0, 'items' => 0, 'revenue' => 0.0];
$top_products_7d = [];
$top_products_30d = [];
$best_seller = null;
$sales_material_needs = [];
$sales_material_need_summary = [
    'materials_with_demand' => 0,
    'urgent_buy_count' => 0,
    'estimated_buy_cost_window' => 0.0
];

$sales_summary_7d_sql = "SELECT
        COUNT(DISTINCT o.id) AS orders_count,
        COALESCE(SUM(oi.quantity), 0) AS total_items,
        COALESCE(SUM(oi.total), 0) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON {$sales_product_join_sql}
    WHERE {$sales_status_sql}
      AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      {$sales_scope_filter_sql}";
$sales_summary_7d_result = mysqli_query($conn, $sales_summary_7d_sql);
if ($sales_summary_7d_result && ($row = mysqli_fetch_assoc($sales_summary_7d_result))) {
    $sales_summary_7d = [
        'orders' => (int)($row['orders_count'] ?? 0),
        'items' => (int)($row['total_items'] ?? 0),
        'revenue' => (float)($row['total_revenue'] ?? 0)
    ];
}

$sales_summary_30d_sql = "SELECT
        COUNT(DISTINCT o.id) AS orders_count,
        COALESCE(SUM(oi.quantity), 0) AS total_items,
        COALESCE(SUM(oi.total), 0) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON {$sales_product_join_sql}
    WHERE {$sales_status_sql}
      AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      {$sales_scope_filter_sql}";
$sales_summary_30d_result = mysqli_query($conn, $sales_summary_30d_sql);
if ($sales_summary_30d_result && ($row = mysqli_fetch_assoc($sales_summary_30d_result))) {
    $sales_summary_30d = [
        'orders' => (int)($row['orders_count'] ?? 0),
        'items' => (int)($row['total_items'] ?? 0),
        'revenue' => (float)($row['total_revenue'] ?? 0)
    ];
}

$top_products_7d_sql = "SELECT
        {$sales_group_product_expr} AS product_name,
        COALESCE(SUM(oi.quantity), 0) AS total_qty,
        COALESCE(SUM(oi.total), 0) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON {$sales_product_join_sql}
    WHERE {$sales_status_sql}
      AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      {$sales_scope_filter_sql}
    GROUP BY {$sales_group_product_expr}
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 5";
$top_products_7d_result = mysqli_query($conn, $top_products_7d_sql);
if ($top_products_7d_result) {
    while ($row = mysqli_fetch_assoc($top_products_7d_result)) {
        $top_products_7d[] = $row;
    }
}

$top_products_30d_sql = "SELECT
        {$sales_group_product_expr} AS product_name,
        COALESCE(SUM(oi.quantity), 0) AS total_qty,
        COALESCE(SUM(oi.total), 0) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON {$sales_product_join_sql}
    WHERE {$sales_status_sql}
      AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      {$sales_scope_filter_sql}
    GROUP BY {$sales_group_product_expr}
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 5";
$top_products_30d_result = mysqli_query($conn, $top_products_30d_sql);
if ($top_products_30d_result) {
    while ($row = mysqli_fetch_assoc($top_products_30d_result)) {
        $top_products_30d[] = $row;
    }
}

$best_seller_sql = "SELECT
        {$sales_group_product_expr} AS product_name,
        COALESCE(SUM(oi.quantity), 0) AS total_qty,
        COALESCE(SUM(oi.total), 0) AS total_revenue
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON {$sales_product_join_sql}
    WHERE {$sales_status_sql}
      {$sales_scope_filter_sql}
    GROUP BY {$sales_group_product_expr}
    ORDER BY total_qty DESC, total_revenue DESC
    LIMIT 1";
$best_seller_result = mysqli_query($conn, $best_seller_sql);
if ($best_seller_result) {
    $best_seller = mysqli_fetch_assoc($best_seller_result) ?: null;
}

$sales_material_needs = mrpGetSalesDrivenMaterialNeeds(
    $conn,
    $seller_scope_id !== null ? (int)$seller_scope_id : null,
    $partner_material_scope_sql,
    $planner_scope_po_usage,
    $sales_window_days,
    30
);
foreach ($sales_material_needs as $need_row) {
    $demand_window = (float)($need_row['demand_window'] ?? 0);
    $demand_30d = (float)($need_row['demand_30d'] ?? 0);
    if ($demand_window > 0 || $demand_30d > 0) {
        $sales_material_need_summary['materials_with_demand']++;
    }
    if ((float)($need_row['buy_qty_window'] ?? 0) > 0) {
        $sales_material_need_summary['urgent_buy_count']++;
        $sales_material_need_summary['estimated_buy_cost_window'] += (float)($need_row['buy_cost_window'] ?? 0);
    }
}
$sales_material_need_summary['estimated_buy_cost_window'] = round((float)$sales_material_need_summary['estimated_buy_cost_window'], 2);
$sales_budget_gap_window = max(0, $sales_material_need_summary['estimated_buy_cost_window'] - $finance_budget_remaining_today);

$procurement_actions = [];
if ($today_budget_summary['approved_total'] <= 0) {
    $procurement_actions[] = "Submit and approve a daily procurement budget before converting draft POs into ordered commitments.";
}
if ($today_budget_summary['remaining_total'] < 0) {
    $procurement_actions[] = "Today's procurement commitments are already above approved budget by PHP " . number_format(abs($today_budget_summary['remaining_total']), 2) . ".";
}
if ($mrp_kpis['critical_materials'] > 0) {
    $procurement_actions[] = "Fast-track PR approval for {$mrp_kpis['critical_materials']} low-stock material(s).";
}
if ($mrp_kpis['partially_received_po'] > 0) {
    $procurement_actions[] = "Follow up {$mrp_kpis['partially_received_po']} partially received PO(s) to prevent production delays.";
}
if (($procurement_health['overdue_delivery_po'] ?? 0) > 0) {
    $procurement_actions[] = "Escalate {$procurement_health['overdue_delivery_po']} overdue supplier delivery PO(s) and trigger backup sourcing.";
}
if (($procurement_health['fill_rate_30d'] ?? 0) > 0 && $procurement_health['fill_rate_30d'] < 90) {
    $procurement_actions[] = "30-day receiving fill rate is {$procurement_health['fill_rate_30d']}%. Tighten receiving validation and supplier SLAs.";
}
if (($procurement_health['at_risk_materials'] ?? 0) > 0) {
    $procurement_actions[] = "Prioritize {$procurement_health['at_risk_materials']} at-risk material(s) from the planning board before next service window.";
}
if ($sales_material_need_summary['urgent_buy_count'] > 0) {
    $procurement_actions[] = "Sales-driven material planner flagged {$sales_material_need_summary['urgent_buy_count']} material(s) that need buying for the next {$sales_window_days} days.";
}
if ($sales_budget_gap_window > 0) {
    $procurement_actions[] = "Projected {$sales_window_days}-day ingredient buy need is above today's remaining budget by PHP " . number_format($sales_budget_gap_window, 2) . ".";
}
if (($forecast_summary['predicted_orders'] ?? 0) > 0 && ($forecast_summary['avg_confidence'] ?? 0) >= 70) {
    $forecast_orders = number_format((float)$forecast_summary['predicted_orders'], 0);
    $procurement_actions[] = "Plan supply for about {$forecast_orders} forecasted orders over the next 7 days.";
}
if (($payment_kpis['unpaid_po_count'] ?? 0) > 0) {
    $procurement_actions[] = "Review {$payment_kpis['unpaid_po_count']} unpaid supplier PO(s) so receiving and supplier trust stay healthy.";
}
if ($partner_cost_snapshot && !empty($partner_cost_snapshot['recommendations'])) {
    foreach (array_slice($partner_cost_snapshot['recommendations'], 0, 2) as $costRecommendation) {
        $procurement_actions[] = (string)($costRecommendation['headline'] ?? 'Cost recommendation') . ': ' . (string)($costRecommendation['action'] ?? 'Review and act.');
    }
}
if (empty($procurement_actions)) {
    $procurement_actions[] = "Procurement flow is stable. Keep weekly review cadence and monitor deliveries.";
}

$next_approved_pr_id = 0;
$next_approved_pr_result = mysqli_query($conn, "SELECT pr.id FROM purchase_requisitions pr WHERE pr.status = 'approved'" . ($seller_scope_id !== null ? " AND {$partner_pr_scope_sql}" : "") . " ORDER BY pr.created_at ASC LIMIT 1");
if ($next_approved_pr_result && ($row = mysqli_fetch_assoc($next_approved_pr_result))) {
    $next_approved_pr_id = (int)$row['id'];
}
$budget_step_count = $today_budget_summary['approved_total'] > 0 ? 0 : max(1, $pending_budget_count);
$supplier_payment_step_count = (int)($payment_kpis['unpaid_po_count'] ?? 0);

// Guided workflow setup for smoother step-by-step processing.
$workflow_steps = [
    [
        'key' => 'identify_needs',
        'title' => 'Identify Needs',
        'icon' => 'fas fa-search',
        'description' => 'Review low-stock materials and shortages.',
        'count' => $mrp_kpis['critical_materials'],
        'count_label' => 'material(s) low',
        'tab' => 'low_stock',
        'action_url' => '?tab=low_stock',
        'action_label' => 'Review Needs'
    ],
    [
        'key' => 'create_pr',
        'title' => 'Create Requisition',
        'icon' => 'fas fa-file-alt',
        'description' => 'Create PR for needed materials.',
        'count' => $mrp_kpis['critical_materials'],
        'count_label' => 'need PR action',
        'tab' => 'requisitions',
        'action_url' => '?tab=requisitions&open_pr=1',
        'action_label' => 'Create PR'
    ],
    [
        'key' => 'approve_budget',
        'title' => 'Approve Daily Budget',
        'icon' => 'fas fa-wallet',
        'description' => 'Finance approves the day budget before purchase commitments go live.',
        'count' => $budget_step_count,
        'count_label' => $today_budget_summary['approved_total'] > 0 ? 'budget approved today' : 'budget action needed',
        'tab' => 'budget_payments',
        'action_url' => '?tab=budget_payments',
        'action_label' => 'Manage Budget'
    ],
    [
        'key' => 'approve_pr',
        'title' => 'Approve PR',
        'icon' => 'fas fa-user-check',
        'description' => 'Approve or reject pending requisitions.',
        'count' => $mrp_kpis['pending_pr'],
        'count_label' => 'pending approval',
        'tab' => 'requisitions',
        'action_url' => '?tab=requisitions',
        'action_label' => 'Review Pending PR'
    ],
    [
        'key' => 'create_po',
        'title' => 'Create PO',
        'icon' => 'fas fa-shopping-cart',
        'description' => 'Convert approved PR into purchase orders.',
        'count' => $mrp_kpis['approved_pr'],
        'count_label' => 'approved PR ready',
        'tab' => 'requisitions',
        'action_url' => !$finance_budget_gate_open
            ? '?tab=budget_payments'
            : (($seller_scope_id === null && $next_approved_pr_id > 0) ? ('purchase_order.php?pr_id=' . $next_approved_pr_id) : '?tab=requisitions'),
        'action_label' => $finance_budget_gate_open ? 'Generate PO' : 'Approve Budget First'
    ],
    [
        'key' => 'delivery_followup',
        'title' => 'Track Delivery',
        'icon' => 'fas fa-truck',
        'description' => 'Monitor supplier deliveries in transit.',
        'count' => $mrp_kpis['awaiting_delivery_po'],
        'count_label' => 'PO awaiting delivery',
        'tab' => 'purchase_orders',
        'action_url' => '?tab=purchase_orders',
        'action_label' => 'Track Delivery'
    ],
    [
        'key' => 'inspect_receive',
        'title' => 'Inspect & Receive',
        'icon' => 'fas fa-clipboard-check',
        'description' => 'Inspect arrivals and receive stock.',
        'count' => $mrp_kpis['open_po'],
        'count_label' => 'PO to inspect/receive',
        'tab' => 'purchase_orders',
        'action_url' => '?tab=purchase_orders&focus=inspect',
        'action_label' => 'Open Receiving'
    ],
    [
        'key' => 'supplier_payment',
        'title' => 'Pay Supplier',
        'icon' => 'fas fa-hand-holding-dollar',
        'description' => 'Record supplier payments after delivery and invoice validation.',
        'count' => $supplier_payment_step_count,
        'count_label' => 'PO with supplier balance',
        'tab' => 'budget_payments',
        'action_url' => '?tab=budget_payments',
        'action_label' => 'Record Payment'
    ]
];

$next_workflow_step = null;
foreach ($workflow_steps as $step) {
    if ((int)$step['count'] > 0) {
        $next_workflow_step = $step;
        break;
    }
}

foreach ($workflow_steps as &$step) {
    if ((int)$step['count'] <= 0) {
        $step['state'] = 'done';
    } elseif ($next_workflow_step && $step['key'] === $next_workflow_step['key']) {
        $step['state'] = 'current';
    } else {
        $step['state'] = 'pending';
    }
}
unset($step);

$simple_flow_steps = [
    [
        'key' => 'need_plan',
        'title' => '1. Check Needs',
        'description' => 'Review low stock and suggested reorder quantities.',
        'count' => max((int)$mrp_kpis['critical_materials'], (int)($procurement_health['at_risk_materials'] ?? 0)),
        'count_label' => 'material(s) to review',
        'tab' => 'low_stock',
        'action_url' => '?tab=low_stock',
        'action_label' => 'Open Needs Planner'
    ],
    [
        'key' => 'requisition',
        'title' => '2. Requisition',
        'description' => 'Create PR, then approve pending requests.',
        'count' => (int)$mrp_kpis['pending_pr'] + (int)$mrp_kpis['approved_pr'],
        'count_label' => 'PR task(s)',
        'tab' => 'requisitions',
        'action_url' => '?tab=requisitions',
        'action_label' => 'Open Requisitions'
    ],
    [
        'key' => 'receiving',
        'title' => '3. Receive Deliveries',
        'description' => 'Track PO, inspect and receive stock.',
        'count' => (int)$mrp_kpis['open_po'],
        'count_label' => 'PO task(s)',
        'tab' => 'purchase_orders',
        'action_url' => '?tab=purchase_orders&focus=inspect',
        'action_label' => 'Open Receiving Queue'
    ],
    [
        'key' => 'payments',
        'title' => '4. Pay Suppliers',
        'description' => 'Record outstanding supplier payments.',
        'count' => (int)($payment_kpis['unpaid_po_count'] ?? 0),
        'count_label' => 'payment task(s)',
        'tab' => 'budget_payments',
        'action_url' => '?tab=budget_payments',
        'action_label' => 'Open Payments'
    ]
];

$next_simple_flow_step = null;
foreach ($simple_flow_steps as $simple_step) {
    if ((int)$simple_step['count'] > 0) {
        $next_simple_flow_step = $simple_step;
        break;
    }
}
foreach ($simple_flow_steps as &$simple_step) {
    if ((int)$simple_step['count'] <= 0) {
        $simple_step['state'] = 'done';
    } elseif ($next_simple_flow_step && $simple_step['key'] === $next_simple_flow_step['key']) {
        $simple_step['state'] = 'current';
    } else {
        $simple_step['state'] = 'pending';
    }
}
unset($simple_step);

$valid_tabs = ['requisitions', 'purchase_orders', 'low_stock', 'suppliers', 'budget_payments'];
$requested_tab = $_GET['tab'] ?? '';
if (!in_array($requested_tab, $valid_tabs, true)) {
    $requested_tab = '';
}

if ($requested_tab !== '') {
    $tab = $requested_tab;
} elseif ($next_workflow_step) {
    $tab = $next_workflow_step['tab'];
} else {
    $tab = 'requisitions';
}

$open_pr_modal = (isset($_GET['open_pr']) && $_GET['open_pr'] === '1');
$focus_receiving_queue = (isset($_GET['focus']) && $_GET['focus'] === 'inspect');
$flash_success = $_SESSION['success'] ?? null;
$flash_error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement - Lechon Delights</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .nav-tabs .nav-link.active { color: #c62828; border-color: #c62828 #c62828 #fff; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 12px; color: #666; }
        .step { text-align: center; position: relative; flex: 1; }
        .step.active { color: #c62828; font-weight: bold; }
        .step i { font-size: 20px; display: block; margin-bottom: 5px; }
        .insight-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .insight-card { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 12px 14px; }
        .insight-label { color: #6c757d; font-size: 12px; margin-bottom: 6px; }
        .insight-value { font-size: 22px; font-weight: 700; color: #1f2937; }
        .decision-panel { position: relative; background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%); border: 1px solid #e8edf3; border-radius: 16px; padding: 16px; margin-bottom: 18px; overflow: hidden; }
        .decision-panel::before { content: ""; position: absolute; inset: 0 auto auto 0; width: 100%; height: 4px; background: linear-gradient(90deg, #c62828, #ef4444); }
        .decision-panel .decision-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .decision-panel .decision-actions .btn { border-radius: 999px; font-weight: 600; padding: 0.35rem 0.75rem; }
        .decision-panel ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
        .decision-panel li { margin: 0; background: #fff7f7; border: 1px solid #fecaca; border-radius: 10px; padding: 10px 12px 10px 34px; line-height: 1.45; position: relative; }
        .decision-panel li::before { content: "\f135"; font-family: "Font Awesome 6 Free", "Font Awesome 5 Free"; font-weight: 900; position: absolute; left: 12px; top: 10px; color: #dc2626; font-size: 0.78rem; }
        .workflow-panel { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 14px; margin-bottom: 18px; }
        .workflow-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 10px; }
        .workflow-step-card { border: 1px solid #e9ecef; border-radius: 10px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 8px; min-height: 170px; }
        .workflow-step-card.current { border-color: #c62828; box-shadow: 0 0 0 2px rgba(198, 40, 40, 0.08); }
        .workflow-step-card.done { border-color: #28a745; background: #f7fff9; }
        .workflow-step-card.pending { border-color: #f1f3f5; }
        .workflow-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .workflow-step-title { font-weight: 700; margin: 0; font-size: 14px; }
        .workflow-step-desc { margin: 0; font-size: 12px; color: #6c757d; }
        .workflow-step-meta { font-size: 12px; color: #495057; }
        .workflow-badge { font-size: 11px; padding: 4px 8px; border-radius: 999px; display: inline-block; }
        .workflow-badge.done { background: #d4edda; color: #155724; }
        .workflow-badge.current { background: #fde2e2; color: #9f1d1d; }
        .workflow-badge.pending { background: #f1f3f5; color: #495057; }
        .workflow-focus { box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.25) !important; border-radius: 8px; animation: workflowPulse 1.1s ease-in-out infinite alternate; }
        @keyframes workflowPulse { from { transform: scale(1); } to { transform: scale(1.04); } }
        .planner-hint { font-size: 12px; color: #6b7280; margin: 0 0 10px; }
        .urgency-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.2px; }
        .urgency-badge.critical { background: #fee2e2; color: #991b1b; }
        .urgency-badge.high { background: #ffedd5; color: #9a3412; }
        .urgency-badge.medium { background: #fef3c7; color: #92400e; }
        .urgency-badge.low { background: #dcfce7; color: #166534; }
        .coverage-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 600; }
        .simple-flow-panel { border: 1px solid #dbe4ef; border-radius: 14px; background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
        .simple-flow-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-top: 10px; }
        .simple-step-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 8px; min-height: 170px; }
        .simple-step-card.current { border-color: #c62828; box-shadow: 0 0 0 2px rgba(198, 40, 40, 0.12); }
        .simple-step-card.done { border-color: #16a34a; background: #f7fff8; }
        .simple-step-card .title { font-weight: 700; font-size: 14px; color: #0f172a; margin: 0; }
        .simple-step-card .desc { font-size: 12px; color: #64748b; margin: 0; }
        .simple-step-card .meta { font-size: 12px; color: #334155; }
        .simple-state { font-size: 11px; border-radius: 999px; padding: 4px 8px; width: fit-content; }
        .simple-state.current { background: #fee2e2; color: #9f1239; }
        .simple-state.pending { background: #e2e8f0; color: #334155; }
        .simple-state.done { background: #dcfce7; color: #166534; }
        /* MRP page styling enhancements */
        .mrp-page .insight-card {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.05);
        }

        .mrp-page .insight-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #c62828, #ef5350);
        }

        .mrp-page .decision-panel,
        .mrp-page .workflow-panel {
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.06);
        }

        .mrp-page .workflow-step-card {
            border-width: 1px;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .mrp-page .workflow-step-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.12);
        }

        .mrp-page .nav-tabs {
            border-bottom: 1px solid #e8edf3;
            margin-bottom: 14px !important;
            gap: 6px;
        }

        .mrp-page .nav-tabs .nav-link {
            border-radius: 10px 10px 0 0;
            font-weight: 600;
            color: #475569;
            border: 1px solid transparent;
        }

        .mrp-page .nav-tabs .nav-link.active {
            background: #fff;
            color: #111827;
            border-color: #e8edf3 #e8edf3 #fff;
        }

        .mrp-page .tab-content {
            background: #fff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.05);
            padding: 16px;
        }

        .mrp-page .tab-pane h4 {
            font-weight: 700;
            color: #0f172a;
        }

        body.dark-mode .mrp-page .decision-panel {
            background: linear-gradient(180deg, #2d2d2d 0%, #252525 100%) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .mrp-page .decision-panel li {
            background: rgba(220, 38, 38, 0.1);
            border-color: #7f1d1d;
        }

        body.dark-mode .mrp-page .decision-panel li::before {
            color: #f87171;
        }

        .mrp-page .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: 12px;
        }

        .mrp-page .admin-table thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #e8edf3;
        }

        .mrp-page .admin-table th,
        .mrp-page .admin-table td {
            padding: 11px 12px;
            vertical-align: middle;
        }

        .mrp-page .admin-table tbody tr:nth-child(even) {
            background: #fcfdff;
        }

        .mrp-page .admin-table tbody tr:hover {
            background: #f7fafc;
        }

        .mrp-page .btn-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbe4ef;
            background: #fff;
            color: #334155;
            transition: all 0.2s ease;
        }

        .mrp-page .btn-icon:hover {
            transform: translateY(-1px);
            color: #c62828;
            border-color: #e3b4b4;
        }
        .setup-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .setup-card { border: 1px solid #e8edf3; border-radius: 12px; padding: 12px; background: #fff; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04); }
        .setup-card h6 { margin-bottom: 8px; font-weight: 700; color: #111827; }
        .setup-card .form-label { font-size: 12px; margin-bottom: 4px; color: #475569; }
        .setup-card .form-control, .setup-card .form-select { font-size: 13px; }
        .setup-card .help { font-size: 11px; color: #6b7280; margin-bottom: 8px; }
        .setup-summary { border: 1px solid #e8edf3; border-radius: 12px; overflow: hidden; }
        .setup-summary .title { background: #f8fafc; padding: 10px 12px; border-bottom: 1px solid #e8edf3; font-weight: 600; color: #0f172a; }
        .setup-inline-form { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .setup-inline-form .form-control { max-width: 110px; }
        .setup-inline-actions { display: flex; align-items: center; gap: 6px; }
        .sales-wire-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .sales-wire-card { border: 1px solid #e8edf3; border-radius: 12px; padding: 12px; background: #fff; }
        .sales-wire-card h6 { font-weight: 700; margin-bottom: 8px; color: #0f172a; }
        .sales-wire-list { margin: 0; padding-left: 18px; font-size: 13px; color: #334155; }
        .sales-wire-list li { margin-bottom: 6px; }
        .budget-chip { display: inline-block; border-radius: 999px; padding: 4px 8px; font-size: 11px; font-weight: 700; }
        .budget-chip.ok { background: #dcfce7; color: #166534; }
        .budget-chip.warn { background: #fee2e2; color: #991b1b; }
        .need-priority-badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 700; }
        .need-priority-badge.critical { background: #fee2e2; color: #991b1b; }
        .need-priority-badge.high { background: #ffedd5; color: #9a3412; }
        .need-priority-badge.stable { background: #dcfce7; color: #166534; }

        @media (max-width: 768px) {
            .sales-wire-grid { grid-template-columns: 1fr; }
            .mrp-page .workflow-grid { grid-template-columns: 1fr; }
            .mrp-page .tab-content { padding: 12px; }
            .mrp-page .tab-pane .d-flex.justify-content-between { gap: 8px; align-items: flex-start !important; }
        }
        @media (max-width: 768px) {
            .workflow-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="admin-polish mrp-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Procurement</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php if ($flash_success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars((string)$flash_success); ?></div>
                <?php endif; ?>
                <?php if ($flash_error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars((string)$flash_error); ?></div>
                <?php endif; ?>

                <div class="workflow-panel simple-flow-panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas fa-map-signs text-danger me-1"></i> Procurement Quick Flow</h5>
                        <?php if ($next_simple_flow_step): ?>
                            <a href="<?php echo htmlspecialchars($next_simple_flow_step['action_url']); ?>" class="btn btn-sm btn-primary">
                                Do This Now: <?php echo htmlspecialchars($next_simple_flow_step['title']); ?>
                            </a>
                        <?php else: ?>
                            <span class="badge bg-success">No urgent tasks right now</span>
                        <?php endif; ?>
                    </div>
                    <p class="small text-muted mb-2">Follow this order to avoid confusion: <strong>Needs -> Requisition -> Receiving -> Payment</strong>. Supplier list is optional support.</p>
                    <div class="simple-flow-grid">
                        <?php foreach ($simple_flow_steps as $simple_step): ?>
                            <div class="simple-step-card <?php echo htmlspecialchars($simple_step['state']); ?>">
                                <span class="simple-state <?php echo htmlspecialchars($simple_step['state']); ?>"><?php echo strtoupper($simple_step['state']); ?></span>
                                <p class="title"><?php echo htmlspecialchars($simple_step['title']); ?></p>
                                <p class="desc"><?php echo htmlspecialchars($simple_step['description']); ?></p>
                                <div class="meta"><strong><?php echo number_format((int)$simple_step['count']); ?></strong> <?php echo htmlspecialchars($simple_step['count_label']); ?></div>
                                <a href="<?php echo htmlspecialchars($simple_step['action_url']); ?>" class="btn btn-sm <?php echo $simple_step['state'] === 'current' ? 'btn-primary' : 'btn-outline-secondary'; ?> mt-auto">
                                    <?php echo htmlspecialchars($simple_step['action_label']); ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
<div class="small text-muted mb-2">Process order: left to right. Finish one step before moving to the next.</div>
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'low_stock' ? 'active' : ''; ?>" href="?tab=low_stock">1. Needs Planner</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'requisitions' ? 'active' : ''; ?>" href="?tab=requisitions">2. Requisitions</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'purchase_orders' ? 'active' : ''; ?>" href="?tab=purchase_orders">3. Delivery & Receiving</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'budget_payments' ? 'active' : ''; ?>" href="?tab=budget_payments">4. Budget & Payments</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'suppliers' ? 'active' : ''; ?>" href="?tab=suppliers">Optional: Suppliers</a>
                    </li>
                </ul>

                <div class="tab-content">
                    
                    <!-- Purchase Requisitions Tab -->
                    <div class="tab-pane fade <?php echo $tab === 'requisitions' ? 'show active' : ''; ?>" id="requisitions">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Purchase Requisitions (PR)</h4>
                            <div class="d-flex gap-2 align-items-center">
                                <small class="text-muted">Use PR to request materials first. Budget approval is checked before orders are committed.</small>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#prModal" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>><i class="fas fa-plus"></i> Create Requisition</button>
                            </div>
                        </div>
                        <div class="alert alert-light border mb-3">
                            <strong>Step 2:</strong> Create requisitions for needed materials, then approve pending PR so they can be converted into Purchase Orders.
                            <?php if (!$finance_budget_gate_open): ?>
                                <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($finance_budget_gate_reason); ?></div>
                            <?php endif; ?>
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>PR Number</th>
                                    <th>Date</th>
                                    <th>Requested By</th>
                                    <th>Estimated Total</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $pr_query = mysqli_query($conn, "SELECT pr.*, u.full_name,
                                    COALESCE((
                                        SELECT SUM(COALESCE(pri.estimated_cost, 0))
                                        FROM purchase_requisition_items pri
                                        WHERE pri.pr_id = pr.id
                                    ), 0) AS estimated_total
                                    FROM purchase_requisitions pr
                                    LEFT JOIN users u ON pr.requested_by = u.id" . ($seller_scope_id !== null ? " WHERE {$partner_pr_scope_sql}" : "") . "
                                    ORDER BY pr.created_at DESC");
                                if(mysqli_num_rows($pr_query) > 0):
                                    while($pr = mysqli_fetch_assoc($pr_query)):
                                        $status_color = match($pr['status']) {
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'po_created' => 'info',
                                            default => 'secondary'
                                        };
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pr['pr_number']); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($pr['request_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($pr['full_name']); ?></td>
                                    <td>₱<?php echo number_format((float)($pr['estimated_total'] ?? 0), 2); ?></td>
                                    <td><span class="badge bg-<?php echo $status_color; ?>"><?php echo strtoupper(str_replace('_', ' ', $pr['status'])); ?></span></td>
                                    <td>
                                        <?php if($pr['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="pr_id" value="<?php echo $pr['id']; ?>">
                                                <button type="submit" name="action" value="approve_pr" class="btn btn-sm btn-success" title="Approve"><i class="fas fa-check"></i></button>
                                                <button type="submit" name="action" value="reject_pr" class="btn btn-sm btn-danger" title="Reject"><i class="fas fa-times"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if($pr['status'] === 'approved'): ?>
                                            <?php if ($finance_budget_gate_open): ?>
                                                <a href="purchase_order.php?pr_id=<?php echo $pr['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-contract"></i> Create PO</a>
                                            <?php else: ?>
                                                <a href="mrp.php?tab=budget_payments" class="btn btn-sm btn-outline-danger"><i class="fas fa-wallet"></i> Budget First</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="6" class="text-center text-muted">No requisitions found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Purchase Orders Tab -->
                    <div class="tab-pane fade <?php echo $tab === 'purchase_orders' ? 'show active' : ''; ?>" id="purchase_orders">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Purchase Orders (PO)</h4>
                            <div class="d-flex gap-2 align-items-center">
                                <small class="text-muted">Move a PO from draft to ordered only after finance-approved daily budget is available.</small>
                                <?php if ($can_manage_mrp): ?>
                                    <?php if ($finance_budget_gate_open): ?>
                                        <a href="purchase_order.php" class="btn btn-outline-primary"><i class="fas fa-plus"></i> Direct PO</a>
                                    <?php else: ?>
                                        <a href="mrp.php?tab=budget_payments" class="btn btn-outline-danger"><i class="fas fa-wallet"></i> Budget Approval Needed</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="alert alert-light border mb-3">
                            <strong>Step 3:</strong> Track ordered POs, inspect deliveries, and receive stock to update inventory accurately.
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Outstanding</th>
                                    <th>Payment Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $po_query = mysqli_query($conn, "SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id" . ($seller_scope_id !== null ? " WHERE {$partner_po_scope_sql}" : "") . " ORDER BY po.order_date DESC");
                                if(mysqli_num_rows($po_query) > 0):
                                    while($po = mysqli_fetch_assoc($po_query)):
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($po['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($po['order_date'])); ?></td>
                                    <td>&#8369;<?php echo number_format($po['total_amount'], 2); ?></td>
                                    <?php $poPayment = $supplier_payment_map[(int)$po['id']] ?? ['total_paid' => 0, 'outstanding_amount' => (float)($po['total_amount'] ?? 0)]; ?>
                                    <td>&#8369;<?php echo number_format((float)($poPayment['total_paid'] ?? 0), 2); ?></td>
                                    <td>&#8369;<?php echo number_format((float)($poPayment['outstanding_amount'] ?? 0), 2); ?></td>
                                    <td>
                                        <?php
                                            $paidTotal = (float)($poPayment['total_paid'] ?? 0);
                                            $outstandingTotal = (float)($poPayment['outstanding_amount'] ?? 0);
                                            $paymentBadge = $paidTotal <= 0 ? 'secondary' : ($outstandingTotal > 0 ? 'warning' : 'success');
                                            $paymentLabel = $paidTotal <= 0 ? 'UNPAID' : ($outstandingTotal > 0 ? 'PARTIAL' : 'PAID');
                                        ?>
                                        <span class="badge bg-<?php echo $paymentBadge; ?>"><?php echo $paymentLabel; ?></span>
                                    </td>
                                    <td><span class="status-badge badge-<?php echo str_replace('_', '-', $po['status']); ?>"><?php echo ucwords(str_replace('_', ' ', $po['status'])); ?></span></td>
                                    <td>
                                        <a href="purchase_order.php?id=<?php echo $po['id']; ?>" class="btn-icon" title="View/Edit"><i class="fas fa-eye"></i></a>
                                        <?php if ($po['status'] !== 'completed' && $po['status'] !== 'cancelled'): ?>
                                        <button class="btn-icon" title="Inspect & Receive" data-bs-toggle="modal" data-bs-target="#receiveStockModal" onclick="loadReceiveModal(<?php echo $po['id']; ?>)"><i class="fas fa-box-open"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_manage_finance_control && ((float)($poPayment['outstanding_amount'] ?? 0) > 0) && in_array((string)$po['status'], ['ordered', 'partially_received', 'completed'], true)): ?>
                                            <button class="btn-icon" title="Record Supplier Payment" onclick='openSupplierPaymentModal(<?php echo json_encode([
                                                'id' => (int)$po['id'],
                                                'po_number' => (string)$po['po_number'],
                                                'supplier_name' => (string)($po['supplier_name'] ?? 'N/A'),
                                                'outstanding_amount' => (float)($poPayment['outstanding_amount'] ?? 0)
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'><i class="fas fa-hand-holding-dollar"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted">No purchase orders found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Low Stock Tab -->
                    <div class="tab-pane fade <?php echo $tab === 'low_stock' ? 'show active' : ''; ?>" id="low_stock">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Inventory Needs (Low Stock)</h4>
                            <?php if ($seller_scope_id === null): ?>
                                <a href="materials.php" class="btn btn-outline-primary"><i class="fas fa-boxes"></i> Manage All Materials</a>
                            <?php endif; ?>
                        </div>
                        <div id="setup-inputs" class="mb-3">
                            <div class="alert alert-warning border mb-3">
                                <strong>Quick Setup:</strong> Add your material, create a product, then link ingredient quantity per product.
                            </div>
                            <div class="setup-grid">
                                <div class="setup-card">
                                    <h6><i class="fas fa-box-open text-danger"></i> Add Material</h6>
                                    <p class="help">Define stock unit and costing so requisition estimates are accurate.</p>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="quick_add_material">
                                        <div class="mb-2">
                                            <label class="form-label">Material Name</label>
                                            <input type="text" name="setup_material_name" class="form-control" required>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label">Unit</label>
                                                <input type="text" name="setup_material_unit" class="form-control" placeholder="kg, pcs, L" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Cost / Unit</label>
                                                <input type="number" name="setup_material_cost_per_unit" class="form-control" step="0.01" min="0" value="0">
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <div class="col-6">
                                                <label class="form-label">Current Stock</label>
                                                <input type="number" name="setup_material_current_stock" class="form-control" step="0.01" min="0" value="0">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Min Level</label>
                                                <input type="number" name="setup_material_min_level" class="form-control" step="0.01" min="0" value="10">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary mt-3" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>>
                                            Save Material
                                        </button>
                                    </form>
                                </div>

                                <div class="setup-card">
                                    <h6><i class="fas fa-utensils text-danger"></i> Add Product</h6>
                                    <p class="help">Quick-create a menu item. You can add image and advanced details later in Products.</p>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="quick_add_product">
                                        <div class="mb-2">
                                            <label class="form-label">Product Name</label>
                                            <input type="text" name="setup_product_name" class="form-control" required>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-7">
                                                <label class="form-label">Category</label>
                                                <input type="text" name="setup_product_category" class="form-control" placeholder="Whole Lechon, Platters, Sides" required>
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label">Price (PHP)</label>
                                                <input type="number" name="setup_product_price" class="form-control" step="0.01" min="0.01" required>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label">Description (Optional)</label>
                                            <textarea name="setup_product_description" class="form-control" rows="2"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary mt-3" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>>
                                            Save Product
                                        </button>
                                    </form>
                                </div>

                                <div class="setup-card">
                                    <h6><i class="fas fa-link text-danger"></i> Link Ingredient (BOM)</h6>
                                    <p class="help">Connect one product to one material with quantity needed per order/unit.</p>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="quick_add_ingredient">
                                        <div class="mb-2">
                                            <label class="form-label">Product</label>
                                            <select name="setup_ingredient_product_id" class="form-select" required>
                                                <option value="">-- Select Product --</option>
                                                <?php foreach ($setup_products as $setup_product): ?>
                                                    <option value="<?php echo (int)$setup_product['id']; ?>">
                                                        <?php echo htmlspecialchars((string)$setup_product['name']); ?> (<?php echo htmlspecialchars((string)($setup_product['category'] ?? 'N/A')); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Material</label>
                                            <select name="setup_ingredient_material_id" class="form-select" required>
                                                <option value="">-- Select Material --</option>
                                                <?php foreach ($setup_materials as $setup_material): ?>
                                                    <option value="<?php echo (int)$setup_material['id']; ?>">
                                                        <?php echo htmlspecialchars((string)$setup_material['name']); ?> (<?php echo htmlspecialchars((string)$setup_material['unit']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Quantity Needed</label>
                                            <input type="number" name="setup_ingredient_qty" class="form-control" step="0.01" min="0.01" placeholder="e.g. 0.35" required>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary mt-2" <?php echo ($can_manage_mrp && !empty($setup_products) && !empty($setup_materials)) ? '' : 'disabled'; ?>>
                                            Save Ingredient Link
                                        </button>
                                        <?php if (empty($setup_products)): ?>
                                            <div class="small text-danger mt-2">Create at least 1 product first.</div>
                                        <?php elseif (empty($setup_materials)): ?>
                                            <div class="small text-danger mt-2">Add at least 1 material first.</div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                            <div class="setup-summary">
                                <div class="title">Latest Ingredient Mappings</div>
                                <div class="table-responsive">
                                    <table class="admin-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Material</th>
                                                <th>Qty Needed</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($setup_ingredient_rows)): ?>
                                                <?php foreach ($setup_ingredient_rows as $setup_ingredient): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars((string)$setup_ingredient['product_name']); ?></td>
                                                        <td><?php echo htmlspecialchars((string)$setup_ingredient['material_name']); ?></td>
                                                        <td><?php echo number_format((float)($setup_ingredient['quantity_needed'] ?? 0), 2) . ' ' . htmlspecialchars((string)($setup_ingredient['unit'] ?? '')); ?></td>
                                                        <td>
                                                            <div class="setup-inline-actions">
                                                                <form method="POST" class="setup-inline-form">
                                                                    <input type="hidden" name="action" value="quick_update_ingredient">
                                                                    <input type="hidden" name="setup_bom_id" value="<?php echo (int)$setup_ingredient['id']; ?>">
                                                                    <input type="number" name="setup_ingredient_qty_edit" class="form-control form-control-sm" step="0.01" min="0.01" value="<?php echo number_format((float)($setup_ingredient['quantity_needed'] ?? 0), 2, '.', ''); ?>" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>>
                                                                    <button type="submit" class="btn btn-sm btn-outline-primary" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>>
                                                                        Save
                                                                    </button>
                                                                </form>
                                                                <form method="POST" onsubmit="return confirm('Remove this ingredient mapping?');">
                                                                    <input type="hidden" name="action" value="quick_delete_ingredient">
                                                                    <input type="hidden" name="setup_bom_id" value="<?php echo (int)$setup_ingredient['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?php echo $can_manage_mrp ? '' : 'disabled'; ?>>
                                                                        Delete
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="4" class="text-center text-muted">No ingredient mapping yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="sales-wire-grid">
                            <div class="sales-wire-card">
                                <h6><i class="fas fa-chart-line text-danger me-1"></i> Sales Signals (7D / 30D / Best Seller)</h6>
                                <div class="insight-grid mb-2">
                                    <div class="insight-card">
                                        <div class="insight-label">Orders (7D)</div>
                                        <div class="insight-value"><?php echo number_format((int)($sales_summary_7d['orders'] ?? 0)); ?></div>
                                    </div>
                                    <div class="insight-card">
                                        <div class="insight-label">Revenue (7D)</div>
                                        <div class="insight-value">&#8369;<?php echo number_format((float)($sales_summary_7d['revenue'] ?? 0), 0); ?></div>
                                    </div>
                                    <div class="insight-card">
                                        <div class="insight-label">Revenue (30D)</div>
                                        <div class="insight-value">&#8369;<?php echo number_format((float)($sales_summary_30d['revenue'] ?? 0), 0); ?></div>
                                    </div>
                                    <div class="insight-card">
                                        <div class="insight-label">Best Seller</div>
                                        <div class="insight-value" style="font-size:14px; line-height:1.3;">
                                            <?php if ($best_seller): ?>
                                                <?php echo htmlspecialchars((string)($best_seller['product_name'] ?? 'N/A')); ?><br>
                                                <small class="text-muted"><?php echo number_format((float)($best_seller['total_qty'] ?? 0)); ?> sold</small>
                                            <?php else: ?>
                                                <span class="text-muted">No sales yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="border rounded p-2">
                                            <div class="small fw-bold mb-1">Top Products (Last 7 Days)</div>
                                            <?php if (!empty($top_products_7d)): ?>
                                                <ol class="sales-wire-list mb-0">
                                                    <?php foreach ($top_products_7d as $tp7): ?>
                                                        <li>
                                                            <?php echo htmlspecialchars((string)($tp7['product_name'] ?? 'N/A')); ?>
                                                            <span class="text-muted">(<?php echo number_format((float)($tp7['total_qty'] ?? 0)); ?> sold)</span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <div class="small text-muted">No 7-day sales data yet.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border rounded p-2">
                                            <div class="small fw-bold mb-1">Top Products (Last 30 Days)</div>
                                            <?php if (!empty($top_products_30d)): ?>
                                                <ol class="sales-wire-list mb-0">
                                                    <?php foreach ($top_products_30d as $tp30): ?>
                                                        <li>
                                                            <?php echo htmlspecialchars((string)($tp30['product_name'] ?? 'N/A')); ?>
                                                            <span class="text-muted">(<?php echo number_format((float)($tp30['total_qty'] ?? 0)); ?> sold)</span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <div class="small text-muted">No 30-day sales data yet.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="sales-wire-card">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <h6 class="mb-0"><i class="fas fa-warehouse text-danger me-1"></i> Ingredients Needed To Buy (Sales-Driven)</h6>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <form method="GET" class="d-flex align-items-center gap-2">
                                            <input type="hidden" name="tab" value="low_stock">
                                            <label class="small text-muted mb-0" for="sales_window">Window</label>
                                            <select id="sales_window" name="sales_window" class="form-select form-select-sm" style="min-width: 110px;">
                                                <?php foreach ($sales_window_options as $sales_window_option): ?>
                                                    <option value="<?php echo (int)$sales_window_option; ?>" <?php echo (int)$sales_window_days === (int)$sales_window_option ? 'selected' : ''; ?>>
                                                        <?php echo (int)$sales_window_option; ?> days
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="create_sales_pr_draft">
                                            <input type="hidden" name="sales_window" value="<?php echo (int)$sales_window_days; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" <?php echo ($can_manage_mrp && (int)($sales_material_need_summary['urgent_buy_count'] ?? 0) > 0) ? '' : 'disabled'; ?>>
                                                <i class="fas fa-file-alt"></i> Create Draft PR
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <div class="small text-muted">
                                        Based on product sales + BOM usage + current stock + incoming PO.
                                    </div>
                                    <span class="budget-chip <?php echo $sales_budget_gap_window > 0 ? 'warn' : 'ok'; ?>">
                                        <?php if ($sales_budget_gap_window > 0): ?>
                                            Budget gap: &#8369;<?php echo number_format((float)$sales_budget_gap_window, 2); ?>
                                        <?php else: ?>
                                            Budget aligned for <?php echo (int)$sales_window_days; ?>-day need
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="small mb-2">
                                    <strong><?php echo (int)$sales_window_days; ?>-day buy estimate:</strong> &#8369;<?php echo number_format((float)($sales_material_need_summary['estimated_buy_cost_window'] ?? 0), 2); ?>
                                    <span class="text-muted">| Remaining approved budget today: &#8369;<?php echo number_format((float)$finance_budget_remaining_today, 2); ?></span>
                                </div>
                                <div class="table-responsive">
                                    <table class="admin-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Material</th>
                                                <th>Sales Use <?php echo (int)$sales_window_days; ?>D</th>
                                                <th>Stock + Incoming</th>
                                                <th>Need Buy (<?php echo (int)$sales_window_days; ?>D)</th>
                                                <th>Est. Cost</th>
                                                <th>Priority</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($sales_material_needs)): ?>
                                                <?php foreach (array_slice($sales_material_needs, 0, 10) as $sales_need): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars((string)$sales_need['name']); ?></td>
                                                        <td><?php echo number_format((float)($sales_need['demand_window'] ?? 0), 2) . ' ' . htmlspecialchars((string)($sales_need['unit'] ?? '')); ?></td>
                                                        <td>
                                                            <?php echo number_format((float)($sales_need['stock_available'] ?? 0), 2) . ' ' . htmlspecialchars((string)($sales_need['unit'] ?? '')); ?>
                                                            <div class="small text-muted">On hand: <?php echo number_format((float)($sales_need['current_stock'] ?? 0), 2); ?> | Incoming: <?php echo number_format((float)($sales_need['incoming_qty'] ?? 0), 2); ?></div>
                                                        </td>
                                                        <td><strong><?php echo number_format((float)($sales_need['buy_qty_window'] ?? 0), 2) . ' ' . htmlspecialchars((string)($sales_need['unit'] ?? '')); ?></strong></td>
                                                        <td>&#8369;<?php echo number_format((float)($sales_need['buy_cost_window'] ?? 0), 2); ?></td>
                                                        <td>
                                                            <span class="need-priority-badge <?php echo htmlspecialchars((string)($sales_need['need_priority'] ?? 'stable')); ?>">
                                                                <?php echo strtoupper((string)($sales_need['need_priority'] ?? 'stable')); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6" class="text-center text-muted">No sales-driven ingredient demand detected yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-light border mb-3">
                            <strong>Step 1:</strong> Start here daily. Review urgency, suggested reorder quantity, then create PR directly.
                        </div>
                        <p class="planner-hint">
                            Planner signal = stock position + 30-day requisition demand + open PO pipeline + supplier lead-time context.
                        </p>
                        <div class="insight-grid">
                            <div class="insight-card">
                                <div class="insight-label">Critical Materials</div>
                                <div class="insight-value"><?php echo number_format((int)($material_planner_summary['critical'] ?? 0)); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">High Risk Materials</div>
                                <div class="insight-value"><?php echo number_format((int)($material_planner_summary['high'] ?? 0)); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">At Risk Total</div>
                                <div class="insight-value"><?php echo number_format((int)($procurement_health['at_risk_materials'] ?? 0)); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">Projected Reorder Cost</div>
                                <div class="insight-value">₱<?php echo number_format((float)($material_planner_summary['estimated_reorder_cost'] ?? 0), 0); ?></div>
                            </div>
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th>Stock vs Min</th>
                                    <th>Incoming PO</th>
                                    <th>30D Daily Usage</th>
                                    <th>Days Cover</th>
                                    <th>Supplier Signal</th>
                                    <th>Suggested Reorder</th>
                                    <th>Urgency</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($material_planner_rows)): ?>
                                    <?php foreach ($material_planner_rows as $plan): ?>
                                        <?php
                                            $plan_unit = (string)($plan['unit'] ?? '');
                                            $plan_suggested_qty = (float)($plan['suggested_qty'] ?? 0);
                                            $plan_avg_daily = (float)($plan['avg_daily_usage_30d'] ?? 0);
                                            $plan_next_eta = trim((string)($plan['next_expected_delivery'] ?? ''));
                                            $plan_supplier_label = trim((string)($plan['preferred_supplier'] ?? 'N/A'));
                                            $plan_supplier_meta = $plan_next_eta !== ''
                                                ? ('Next ETA ' . date('M d, Y', strtotime($plan_next_eta)))
                                                : 'No incoming ETA';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars((string)$plan['name']); ?></strong>
                                            </td>
                                            <td>
                                                <div class="small text-danger fw-bold"><?php echo number_format((float)($plan['current_stock'] ?? 0), 2) . ' ' . htmlspecialchars($plan_unit); ?></div>
                                                <div class="small text-muted">Min: <?php echo number_format((float)($plan['min_level'] ?? 0), 2) . ' ' . htmlspecialchars($plan_unit); ?></div>
                                            </td>
                                            <td>
                                                <div><?php echo number_format((float)($plan['incoming_qty'] ?? 0), 2) . ' ' . htmlspecialchars($plan_unit); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($plan_supplier_meta); ?></div>
                                            </td>
                                            <td><?php echo number_format($plan_avg_daily, 2) . ' ' . htmlspecialchars($plan_unit) . '/day'; ?></td>
                                            <td><span class="coverage-badge"><?php echo htmlspecialchars((string)($plan['days_cover_label'] ?? 'No demand history')); ?></span></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($plan_supplier_label !== '' ? $plan_supplier_label : 'N/A'); ?></div>
                                                <div class="small text-muted">Avg cost: ₱<?php echo number_format((float)($plan['avg_unit_cost'] ?? 0), 2); ?>/<?php echo htmlspecialchars($plan_unit); ?></div>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($plan_suggested_qty, 2) . ' ' . htmlspecialchars($plan_unit); ?></strong>
                                                <div class="small text-muted">~ ₱<?php echo number_format((float)($plan['estimated_reorder_cost'] ?? 0), 2); ?></div>
                                            </td>
                                            <td>
                                                <span class="urgency-badge <?php echo htmlspecialchars((string)($plan['urgency_key'] ?? 'low')); ?>">
                                                    <?php echo htmlspecialchars((string)($plan['urgency_label'] ?? 'Low')); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button
                                                    class="btn btn-sm btn-warning"
                                                    onclick="openPRModalWithItem(<?php echo (int)$plan['id']; ?>, <?php echo number_format($plan_suggested_qty > 0 ? $plan_suggested_qty : max(0.01, (float)($plan['min_level'] ?? 0)), 2, '.', ''); ?>)"
                                                    <?php echo (!$can_manage_mrp || $plan_suggested_qty <= 0) ? 'disabled' : ''; ?>
                                                >
                                                    <i class="fas fa-file-alt"></i> Create PR
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="9" class="text-center text-muted">No immediate material planning risks detected.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="tab-pane fade <?php echo $tab === 'budget_payments' ? 'show active' : ''; ?>" id="budget_payments">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Budget & Supplier Payments</h4>
                            <small class="text-muted">Finance gate for daily procurement spending and supplier settlement tracking.</small>
                        </div>
                        <div class="alert alert-light border mb-3">
                            <strong>Step 4:</strong> Approve daily procurement budget first, then record supplier payments for completed/received POs.
                        </div>

                        <div class="insight-grid">
                            <div class="insight-card">
                                <div class="insight-label">Approved Budget Today</div>
                                <div class="insight-value">₱<?php echo number_format((float)($today_budget_summary['approved_total'] ?? 0), 0); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">Used Budget Today</div>
                                <div class="insight-value">₱<?php echo number_format((float)($today_budget_summary['used_total'] ?? 0), 0); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">Remaining Budget Today</div>
                                <div class="insight-value">₱<?php echo number_format((float)($today_budget_summary['remaining_total'] ?? 0), 0); ?></div>
                            </div>
                            <div class="insight-card">
                                <div class="insight-label">Pending Budget Requests</div>
                                <div class="insight-value"><?php echo number_format((int)$pending_budget_count); ?></div>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-lg-5">
                                <div class="card h-100">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Submit Daily Procurement Budget</h5>
                                    </div>
                                    <div class="card-body">
                                        <p class="small text-muted">This is the amount procurement is allowed to commit for the day. Finance or the business owner should approve it before POs move to ordered status.</p>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="submit_budget_request">
                                            <div class="mb-3">
                                                <label class="form-label">Budget Date</label>
                                                <input type="date" name="budget_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Requested Amount</label>
                                                <input type="number" name="amount_requested" class="form-control" step="0.01" min="0.01" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Purpose / Notes</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Daily meat restock, charcoal, sauces, emergency raw materials"></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Budget Request</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="card h-100">
                                    <div class="card-header bg-white">
                                        <h5 class="mb-0">Recent Budget Requests</h5>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="admin-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Requested</th>
                                                        <th>Approved</th>
                                                        <th>Status</th>
                                                        <th>Notes</th>
                                                        <th>Finance Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!empty($budget_requests)): ?>
                                                        <?php foreach ($budget_requests as $budget_request): ?>
                                                            <tr>
                                                                <td><?php echo date('M d, Y', strtotime((string)$budget_request['budget_date'])); ?></td>
                                                                <td>₱<?php echo number_format((float)($budget_request['amount_requested'] ?? 0), 2); ?></td>
                                                                <td>₱<?php echo number_format((float)($budget_request['amount_approved'] ?? 0), 2); ?></td>
                                                                <td>
                                                                    <?php $budget_badge = ($budget_request['status'] ?? '') === 'approved' ? 'success' : (($budget_request['status'] ?? '') === 'rejected' ? 'danger' : 'warning'); ?>
                                                                    <span class="badge bg-<?php echo $budget_badge; ?>"><?php echo strtoupper((string)($budget_request['status'] ?? 'pending')); ?></span>
                                                                </td>
                                                                <td class="small text-muted"><?php echo htmlspecialchars((string)($budget_request['notes'] ?? '')); ?></td>
                                                                <td>
                                                                    <?php if (($budget_request['status'] ?? '') === 'pending' && $can_manage_finance_control): ?>
                                                                        <button type="button" class="btn btn-sm btn-success mb-1" onclick='openBudgetDecisionModal(<?php echo json_encode([
                                                                            'id' => (int)$budget_request['id'],
                                                                            'budget_date' => (string)$budget_request['budget_date'],
                                                                            'amount_requested' => (float)($budget_request['amount_requested'] ?? 0),
                                                                            'notes' => (string)($budget_request['notes'] ?? '')
                                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "approve")'>
                                                                            <i class="fas fa-check"></i>
                                                                        </button>
                                                                        <button type="button" class="btn btn-sm btn-danger" onclick='openBudgetDecisionModal(<?php echo json_encode([
                                                                            'id' => (int)$budget_request['id'],
                                                                            'budget_date' => (string)$budget_request['budget_date'],
                                                                            'amount_requested' => (float)($budget_request['amount_requested'] ?? 0),
                                                                            'notes' => (string)($budget_request['notes'] ?? '')
                                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "reject")'>
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">Reviewed</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="6" class="text-center text-muted">No budget requests yet.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Supplier Payment Tracker</h5>
                                <small class="text-muted">Record what has already been paid to each supplier PO.</small>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="admin-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>PO Number</th>
                                                <th>Supplier</th>
                                                <th>PO Total</th>
                                                <th>Paid</th>
                                                <th>Outstanding</th>
                                                <th>Last Payment</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($supplier_payment_rows)): ?>
                                                <?php foreach ($supplier_payment_rows as $paymentRow): ?>
                                                    <?php
                                                        $paidToSupplier = (float)($paymentRow['total_paid'] ?? 0);
                                                        $outstandingToSupplier = (float)($paymentRow['outstanding_amount'] ?? 0);
                                                        $paymentStatus = $paidToSupplier <= 0 ? 'Unpaid' : ($outstandingToSupplier > 0 ? 'Partial' : 'Paid');
                                                        $paymentStatusBadge = $paidToSupplier <= 0 ? 'secondary' : ($outstandingToSupplier > 0 ? 'warning' : 'success');
                                                    ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars((string)$paymentRow['po_number']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars((string)$paymentRow['supplier_name']); ?></td>
                                                        <td>₱<?php echo number_format((float)($paymentRow['total_amount'] ?? 0), 2); ?></td>
                                                        <td>₱<?php echo number_format($paidToSupplier, 2); ?></td>
                                                        <td>₱<?php echo number_format($outstandingToSupplier, 2); ?></td>
                                                        <td><?php echo !empty($paymentRow['last_payment_date']) ? date('M d, Y', strtotime((string)$paymentRow['last_payment_date'])) : '-'; ?></td>
                                                        <td><span class="badge bg-<?php echo $paymentStatusBadge; ?>"><?php echo strtoupper($paymentStatus); ?></span></td>
                                                        <td>
                                                            <?php if ($can_manage_finance_control && $outstandingToSupplier > 0): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='openSupplierPaymentModal(<?php echo json_encode([
                                                                    'id' => (int)$paymentRow['id'],
                                                                    'po_number' => (string)$paymentRow['po_number'],
                                                                    'supplier_name' => (string)$paymentRow['supplier_name'],
                                                                    'outstanding_amount' => $outstandingToSupplier
                                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                                    Record Payment
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted small">Settled</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="8" class="text-center text-muted">No purchase orders available for supplier payment tracking.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Suppliers Tab -->
                    <div class="tab-pane fade <?php echo $tab === 'suppliers' ? 'show active' : ''; ?>" id="suppliers">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Suppliers</h4>
                            <?php if ($seller_scope_id === null): ?>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="resetSupplierForm()"><i class="fas fa-plus"></i> Add Supplier</button>
                            <?php endif; ?>
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $suppliers_query = mysqli_query($conn, "SELECT s.* FROM suppliers s" . ($seller_scope_id !== null ? " WHERE {$partner_supplier_scope_sql}" : "") . " ORDER BY s.name");
                                if(mysqli_num_rows($suppliers_query) > 0):
                                    while($sup = mysqli_fetch_assoc($suppliers_query)):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sup['name']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['email']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['phone']); ?></td>
                                    <td>
                                        <?php if ($seller_scope_id === null): ?>
                                            <button class="btn-icon" title="Edit" onclick='editSupplier(<?php echo json_encode($sup); ?>)'><i class="fas fa-edit"></i></button>
                                        <?php else: ?>
                                            <span class="text-muted">Read-only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">No suppliers found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PR Modal -->
    <div class="modal fade" id="prModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Purchase Requisition</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_pr">
                        <div class="mb-3">
                            <label>Notes / Purpose</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Weekly restocking for kitchen"></textarea>
                            <small class="text-muted">Estimated cost is computed from your material cost per unit so finance can review the day budget against actual need.</small>
                        </div>
                        <h6>Items Needed</h6>
                        <table class="table table-sm" id="prItemsTable">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th width="150">Quantity</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody id="prItemsBody">
                                <tr>
                                    <td>
                                        <select name="material_id[]" class="form-select" required>
                                            <option value="">-- Select Material --</option>
                                            <?php foreach($materials_list as $m): ?>
                                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']) . ' (' . $m['unit'] . ')'; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control" step="0.01" required></td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-times"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm btn-secondary" id="addPrItemRow"><i class="fas fa-plus"></i> Add Item</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Submit Requisition</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Supplier Modal -->
    <div class="modal fade" id="supplierModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="supplierModalTitle">Add Supplier</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_supplier">
                        <input type="hidden" name="supplier_id" id="supplier_id">
                        <div class="mb-3"><label>Name</label><input type="text" name="name" id="s_name" class="form-control" required></div>
                        <div class="mb-3"><label>Contact Person</label><input type="text" name="contact_person" id="s_contact" class="form-control"></div>
                        <div class="mb-3"><label>Email</label><input type="email" name="email" id="s_email" class="form-control"></div>
                        <div class="mb-3"><label>Phone</label><input type="text" name="phone" id="s_phone" class="form-control"></div>
                        <div class="mb-3"><label>Address</label><textarea name="address" id="s_address" class="form-control" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="budgetDecisionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="budgetDecisionForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="budgetDecisionTitle">Finance Budget Review</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="budgetDecisionAction" value="approve_budget_request">
                        <input type="hidden" name="budget_request_id" id="budget_request_id">
                        <div class="mb-2 small text-muted" id="budgetDecisionMeta"></div>
                        <div class="mb-3">
                            <label class="form-label">Approved Amount</label>
                            <input type="number" name="amount_approved" id="amount_approved" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Finance Notes</label>
                            <textarea name="finance_notes" id="finance_notes" class="form-control" rows="3" placeholder="Optional finance explanation or revision note"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="budgetDecisionSubmit">Save Decision</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="supplierPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="supplierPaymentForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Supplier Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_supplier_payment">
                        <input type="hidden" name="po_id" id="payment_po_id">
                        <div class="small text-muted mb-3" id="supplierPaymentMeta"></div>
                        <div class="mb-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount Paid</label>
                            <input type="number" name="amount_paid" id="payment_amount_paid" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="GCash">GCash</option>
                                <option value="Check">Check</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="payment_reference" class="form-control" maxlength="120" placeholder="Optional bank/check/reference number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="payment_notes" class="form-control" rows="3" placeholder="Optional supplier payment note"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Receive Stock Modal -->
    <div class="modal fade" id="receiveStockModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Inspect & Receive Delivery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="receiveStockBody">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script>
        const flashSuccess = <?php echo json_encode($flash_success); ?>;
        const flashError = <?php echo json_encode($flash_error); ?>;
        if (flashSuccess) {
            Swal.fire({ icon: 'success', title: 'Success', text: flashSuccess, timer: 2400, showConfirmButton: false });
        }
        if (flashError) {
            Swal.fire({ icon: 'error', title: 'Attention Needed', text: flashError });
        }

        function resetSupplierForm() {
            document.getElementById('supplierModalTitle').innerText = 'Add Supplier';
            document.getElementById('supplier_id').value = '';
            document.getElementById('s_name').value = '';
            document.getElementById('s_contact').value = '';
            document.getElementById('s_email').value = '';
            document.getElementById('s_phone').value = '';
            document.getElementById('s_address').value = '';
        }

        function editSupplier(data) {
            document.getElementById('supplierModalTitle').innerText = 'Edit Supplier';
            document.getElementById('supplier_id').value = data.id;
            document.getElementById('s_name').value = data.name;
            document.getElementById('s_contact').value = data.contact_person;
            document.getElementById('s_email').value = data.email;
            document.getElementById('s_phone').value = data.phone;
            document.getElementById('s_address').value = data.address;
            new bootstrap.Modal(document.getElementById('supplierModal')).show();
        }

        function loadReceiveModal(poId) {
            $('#receiveStockBody').html('<p>Loading...</p>');
            $.get('get_po_details.php?id=' + poId + '&view=receive', function(data) {
                $('#receiveStockBody').html(data);
            });
        }

        function openBudgetDecisionModal(data, mode) {
            document.getElementById('budget_request_id').value = data.id || '';
            document.getElementById('budgetDecisionAction').value = mode === 'reject' ? 'reject_budget_request' : 'approve_budget_request';
            document.getElementById('budgetDecisionTitle').innerText = mode === 'reject' ? 'Reject Budget Request' : 'Approve Budget Request';
            document.getElementById('budgetDecisionSubmit').innerText = mode === 'reject' ? 'Reject Request' : 'Approve Budget';
            document.getElementById('budgetDecisionSubmit').className = mode === 'reject' ? 'btn btn-danger' : 'btn btn-success';
            document.getElementById('budgetDecisionMeta').innerText = `Date: ${data.budget_date || ''} | Requested: PHP ${Number(data.amount_requested || 0).toFixed(2)}`;
            document.getElementById('amount_approved').value = Number(data.amount_requested || 0).toFixed(2);
            document.getElementById('amount_approved').readOnly = mode === 'reject';
            if (mode === 'reject') {
                document.getElementById('amount_approved').value = '0.00';
            }
            document.getElementById('finance_notes').value = data.notes || '';
            new bootstrap.Modal(document.getElementById('budgetDecisionModal')).show();
        }

        function openSupplierPaymentModal(data) {
            document.getElementById('payment_po_id').value = data.id || '';
            document.getElementById('payment_amount_paid').value = Number(data.outstanding_amount || 0).toFixed(2);
            document.getElementById('supplierPaymentMeta').innerText = `${data.po_number || ''} | ${data.supplier_name || ''} | Outstanding: PHP ${Number(data.outstanding_amount || 0).toFixed(2)}`;
            new bootstrap.Modal(document.getElementById('supplierPaymentModal')).show();
        }

        // PR Modal Logic
        const prModal = new bootstrap.Modal(document.getElementById('prModal'));
        const shouldOpenPrModal = <?php echo $open_pr_modal ? 'true' : 'false'; ?>;
        const shouldFocusReceivingQueue = <?php echo $focus_receiving_queue ? 'true' : 'false'; ?>;

        if (shouldOpenPrModal) {
            prModal.show();
        }

        if (shouldFocusReceivingQueue) {
            const firstReceiveBtn = document.querySelector('#purchase_orders button[onclick^="loadReceiveModal"]');
            if (firstReceiveBtn) {
                firstReceiveBtn.classList.add('workflow-focus');
                firstReceiveBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        
        function openPRModalWithItem(materialId, suggestedQty = 1) {
            prModal.show();
            // Auto-select the item and suggest requisition quantity based on shortage.
            const firstRow = document.querySelector('#prItemsBody tr');
            if (!firstRow) return;

            const firstSelect = firstRow.querySelector('select');
            const firstQty = firstRow.querySelector('input[name="quantity[]"]');

            if (firstSelect) firstSelect.value = materialId;
            if (firstQty) firstQty.value = (Number(suggestedQty) > 0 ? Number(suggestedQty) : 1);
        }

        document.getElementById('addPrItemRow').addEventListener('click', function() {
            const row = document.querySelector('#prItemsBody tr').cloneNode(true);
            row.querySelector('input').value = '';
            document.getElementById('prItemsBody').appendChild(row);
        });

        document.getElementById('prItemsBody').addEventListener('click', function(e) {
            if(e.target.closest('.remove-row')) {
                if(document.querySelectorAll('#prItemsBody tr').length > 1) {
                    e.target.closest('tr').remove();
                }
            }
        });
    </script>
</body>
</html>

