<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
include '../includes/DecisionScoringService.php';
include '../includes/PartnerBusinessEconomicsService.php';
include '../includes/PartnerExpenseSyncService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$admin_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_user_id = $admin_user_id;
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

$expense_categories = ['Raw Materials', 'Inventory Procurement', 'Supplier Invoice', 'Labor', 'Delivery', 'Payroll', 'Platform Billing', 'Refunds', 'Utilities', 'Equipment', 'Permits', 'Marketing', 'Miscellaneous'];
$payment_methods = ['Cash', 'GCash', 'Bank Transfer', 'Credit Card'];
$dss_categories = ['inventory', 'staffing', 'production', 'pricing', 'marketing', 'logistics'];
$dss_priorities = ['critical', 'high', 'medium', 'low'];

function nMonth($v) {
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('Y-m', $v);
    return ($d && $d->format('Y-m') === $v) ? $v : date('Y-m');
}
function nDate($v) {
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : date('Y-m-d');
}
function nCategory($v, $list, $allow_empty = false) {
    $v = trim((string)$v);
    if ($allow_empty && $v === '') return '';
    return in_array($v, $list, true) ? $v : ($allow_empty ? '' : 'Miscellaneous');
}
function nPriority($v, $list) {
    $v = trim((string)$v);
    return in_array($v, $list, true) ? $v : 'medium';
}
function nDssCategory($v, $list) {
    $v = trim((string)$v);
    return in_array($v, $list, true) ? $v : 'pricing';
}
function nPay($v, $list) {
    $v = trim((string)$v);
    return in_array($v, $list, true) ? $v : 'Cash';
}
function dbTableExists($conn, $table) {
    try {
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 's', $table);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = $res && mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}
function dbColumnExists($conn, $table, $column) {
    try {
        $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = $res && mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}
function bindParamsDynamic($stmt, $types, array $params) {
    if ($types === '' || empty($params)) return true;
    $bind = [$stmt, $types];
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    return call_user_func_array('mysqli_stmt_bind_param', $bind);
}
function getExpenseOwnershipRecord($conn, int $expenseId, bool $hasRecordedBy, bool $hasSystemGenerated) {
    if ($expenseId <= 0 || !dbTableExists($conn, 'expenses')) {
        return null;
    }
    $recordedExpr = $hasRecordedBy ? 'recorded_by' : 'NULL AS recorded_by';
    $systemExpr = $hasSystemGenerated ? 'is_system_generated' : '0 AS is_system_generated';
    $sql = "SELECT {$recordedExpr}, {$systemExpr} FROM expenses WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $expenseId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}
function money($n) {
    return 'PHP ' . number_format((float)$n, 2);
}
function safeReceiptHref($path) {
    if (!is_string($path) || trim($path) === '') return null;
    $clean = str_replace('\\', '/', trim($path));
    $clean = ltrim($clean, '/');
    if (!preg_match('#^uploads/receipts/[A-Za-z0-9._-]+$#', $clean)) return null;
    return '../' . $clean;
}
function retUrl($m, $c = '') {
    $u = 'expenses.php?month=' . rawurlencode($m);
    if ($c !== '') $u .= '&category=' . rawurlencode($c);
    return $u;
}
function monthSum($conn, $sql, $y, $m) {
    try {
        $total = 0.0;
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $y, $m);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $total = (float)($row[array_key_first($row)] ?? 0);
            mysqli_stmt_close($stmt);
        }
        return $total;
    } catch (Throwable $e) {
        return 0.0;
    }
}
function rankPriority($p) {
    $map = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
    return $map[$p] ?? 1;
}
function chip($p) {
    if ($p === 'critical') return 'danger';
    if ($p === 'high') return 'warning';
    if ($p === 'medium') return 'info';
    return 'secondary';
}
function fallbackScore($d) {
    $conf = max(0, min(100, ((float)($d['confidence_level'] ?? 0.7)) * 100));
    $roi = max(0, min(100, ((float)($d['roi_estimate'] ?? 0)) / 2));
    $speed = max(20, min(100, 110 - ((int)($d['implementation_timeline'] ?? 7) * 4)));
    $risk = max(0, min(100, 100 - ((float)($d['risk_exposure'] ?? 50))));
    $s = ($conf * 0.3) + ($roi * 0.3) + ($speed * 0.2) + ($risk * 0.2);
    return ['total_score' => round($s, 2), 'rating' => ['label' => $s >= 75 ? 'Very Good' : ($s >= 60 ? 'Good' : 'Fair'), 'color' => $s >= 75 ? 'success' : 'info']];
}

$month = nMonth($_GET['month'] ?? date('Y-m'));
$category_filter = nCategory($_GET['category'] ?? '', $expense_categories, true);
$csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

$can_manage = true;
if (function_exists('hasPermission') && $admin_user_id > 0) {
    $can_manage = hasPermission($conn, $admin_user_id, 'expenses.manage') || hasPermission($conn, $admin_user_id, 'finance.manage');
    if ((string)($_SESSION['role_name'] ?? '') === 'super_admin') $can_manage = true;
}
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $admin_user_id;
if ($is_partner_owner_admin) {
    $can_manage = true;
}

$expenseSyncSummary = ['billing_synced' => 0, 'refunds_synced' => 0, 'procurement_synced' => 0, 'supplier_invoices_synced' => 0, 'schema_ready' => false];
$economicsSnapshot = null;
$economicsTrend = ['labels' => [], 'revenue' => [], 'booked_expenses' => [], 'open_commitments' => [], 'platform_costs' => []];
if ($seller_scope_id !== null) {
    $expenseSyncService = new PartnerExpenseSyncService($conn);
    $expenseSyncSummary = $expenseSyncService->syncPartnerMonth((int)$seller_scope_id, $month);
    $economicsService = new PartnerBusinessEconomicsService($conn);
    $economicsSnapshot = $economicsService->getSnapshot((int)$seller_scope_id, $month);
    $economicsTrend = $economicsService->getTrend((int)$seller_scope_id, 6);
}

$runtime_warnings = [];
$has_expenses_table = dbTableExists($conn, 'expenses');
$expense_columns = [
    'id' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'id'),
    'category' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'category'),
    'description' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'description'),
    'amount' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'amount'),
    'expense_date' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'expense_date'),
    'recorded_by' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'recorded_by'),
    'owner_user_id' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'owner_user_id'),
    'vendor' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'vendor'),
    'payment_method' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'payment_method'),
    'receipt_image' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'receipt_image'),
    'status' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'status'),
    'source_type' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'source_type'),
    'is_system_generated' => $has_expenses_table && dbColumnExists($conn, 'expenses', 'is_system_generated')
];
$expense_required = ['id', 'category', 'description', 'amount', 'expense_date'];
$expenses_schema_ready = $has_expenses_table;
foreach ($expense_required as $col) {
    if (!$expense_columns[$col]) {
        $expenses_schema_ready = false;
        break;
    }
}
if (!$has_expenses_table) {
    $runtime_warnings[] = 'Expenses table is missing. Run HR/DB migration checker before using this page.';
} elseif (!$expenses_schema_ready) {
    $runtime_warnings[] = 'Expenses table schema is incomplete (required columns are missing).';
}
$can_manage_expenses = $can_manage && $expenses_schema_ready;
$can_update_expense_status = $can_manage_expenses && ($expense_columns['status'] ?? false);

$has_users_table = dbTableExists($conn, 'users');
$users_has_id = $has_users_table && dbColumnExists($conn, 'users', 'id');
$users_has_full_name = $has_users_table && dbColumnExists($conn, 'users', 'full_name');
$can_join_users = $expenses_schema_ready && $expense_columns['recorded_by'] && $has_users_table && $users_has_id && $users_has_full_name;
$expense_select_sql = '';
if ($expenses_schema_ready) {
    $vendor_expr = $expense_columns['vendor'] ? 'e.vendor' : "''";
    $payment_expr = $expense_columns['payment_method'] ? 'e.payment_method' : "'Cash'";
    $receipt_expr = $expense_columns['receipt_image'] ? 'e.receipt_image' : 'NULL';
    $status_expr = $expense_columns['status'] ? 'e.status' : "'approved'";
    $source_expr = ($expense_columns['source_type'] ?? false) ? 'e.source_type' : "''";
    $system_expr = ($expense_columns['is_system_generated'] ?? false) ? 'e.is_system_generated' : '0';
    $full_name_expr = $can_join_users ? 'u.full_name' : "'System'";
    $join_users_sql = $can_join_users ? ' LEFT JOIN users u ON e.recorded_by=u.id' : '';
    $expense_select_sql = "SELECT e.id,e.category,e.description,e.amount,e.expense_date,{$vendor_expr} AS vendor,{$payment_expr} AS payment_method,{$receipt_expr} AS receipt_image,{$status_expr} AS status,{$source_expr} AS source_type,{$system_expr} AS is_system_generated,{$full_name_expr} AS full_name FROM expenses e{$join_users_sql}";
}

$has_orders_table = dbTableExists($conn, 'orders');
$has_orders_created_at = $has_orders_table && dbColumnExists($conn, 'orders', 'created_at');
$has_orders_status = $has_orders_table && dbColumnExists($conn, 'orders', 'status');
$has_orders_archived = $has_orders_table && dbColumnExists($conn, 'orders', 'is_archived');
$orders_sum_col = null;
if ($has_orders_table && dbColumnExists($conn, 'orders', 'total_amount')) {
    $orders_sum_col = 'total_amount';
} elseif ($has_orders_table && dbColumnExists($conn, 'orders', 'total_price')) {
    $orders_sum_col = 'total_price';
}

$has_pre_orders_table = dbTableExists($conn, 'pre_orders');
$has_pre_orders_created_at = $has_pre_orders_table && dbColumnExists($conn, 'pre_orders', 'created_at');
$has_pre_orders_status = $has_pre_orders_table && dbColumnExists($conn, 'pre_orders', 'reservation_status');
$pre_orders_sum_col = null;
if ($has_pre_orders_table && dbColumnExists($conn, 'pre_orders', 'total_price')) {
    $pre_orders_sum_col = 'total_price';
} elseif ($has_pre_orders_table && dbColumnExists($conn, 'pre_orders', 'total_amount')) {
    $pre_orders_sum_col = 'total_amount';
}

$partner_expense_scope_sql = '';
if ($seller_scope_id !== null) {
    if ($expense_columns['owner_user_id']) {
        $partner_expense_scope_sql = " AND COALESCE(e.owner_user_id, e.recorded_by) = " . (int)$seller_scope_id;
    } elseif ($expense_columns['recorded_by']) {
        $partner_expense_scope_sql = " AND e.recorded_by = " . (int)$seller_scope_id;
    } else {
        $partner_expense_scope_sql = " AND 1=0";
        $runtime_warnings[] = 'Partner expense scoping requires expenses.recorded_by or expenses.owner_user_id; expense records are read-only until schema is updated.';
    }
}

$partner_orders_scope_exists_sql = '';
if ($seller_scope_id !== null) {
    $partner_orders_scope_exists_sql = " AND EXISTS (
        SELECT 1
        FROM order_items oi_scope
        INNER JOIN products p_scope
            ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
        WHERE oi_scope.order_id = o.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}

$partner_preorders_scope_exists_sql = '';
if ($seller_scope_id !== null) {
    $partner_preorders_scope_exists_sql = " AND EXISTS (
        SELECT 1
        FROM products p_scope
        WHERE p_scope.id = pre_orders.product_id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}

$has_dss_table = dbTableExists($conn, 'decisions_recommendations');
$dss_required_cols = ['decision_category', 'recommendation_text', 'priority', 'confidence_score', 'recommendation_date', 'status', 'expected_impact', 'created_by'];
$dss_queue_ready = $has_dss_table;
foreach ($dss_required_cols as $col) {
    if (!$has_dss_table || !dbColumnExists($conn, 'decisions_recommendations', $col)) {
        $dss_queue_ready = false;
        break;
    }
}
$can_queue_dss = $can_manage && $dss_queue_ready;
if (!$dss_queue_ready) {
    $runtime_warnings[] = 'DSS queue table/columns are missing. Queue action is disabled until migration is complete.';
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $em = nMonth($_GET['month'] ?? date('Y-m'));
    $ec = nCategory($_GET['category'] ?? '', $expense_categories, true);
    $ey = (int)substr($em, 0, 4);
    $eo = (int)substr($em, 5, 2);
    if (!$expenses_schema_ready) {
        $_SESSION['error'] = 'Export is unavailable because expenses table/schema is incomplete.';
        header('Location: ' . retUrl($em, $ec));
        exit;
    }
    if (!$can_manage) {
        $_SESSION['error'] = 'You do not have permission to export expenses.';
        header('Location: ' . retUrl($em, $ec));
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="expenses_report_' . $em . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Category', 'Vendor', 'Description', 'Amount', 'Payment Method', 'Status', 'Recorded By']);
    try {
        if ($ec !== '') {
            $q = $expense_select_sql . " WHERE YEAR(e.expense_date)=? AND MONTH(e.expense_date)=? AND e.category=?" . $partner_expense_scope_sql . " ORDER BY e.expense_date DESC,e.id DESC";
            $s = mysqli_prepare($conn, $q);
            if ($s) {
                mysqli_stmt_bind_param($s, 'iis', $ey, $eo, $ec);
                mysqli_stmt_execute($s);
                $r = mysqli_stmt_get_result($s);
                while ($row = mysqli_fetch_assoc($r)) fputcsv($out, [$row['expense_date'], $row['category'], $row['vendor'], $row['description'], $row['amount'], $row['payment_method'], $row['status'], $row['full_name']]);
                mysqli_stmt_close($s);
            }
        } else {
            $q = $expense_select_sql . " WHERE YEAR(e.expense_date)=? AND MONTH(e.expense_date)=?" . $partner_expense_scope_sql . " ORDER BY e.expense_date DESC,e.id DESC";
            $s = mysqli_prepare($conn, $q);
            if ($s) {
                mysqli_stmt_bind_param($s, 'ii', $ey, $eo);
                mysqli_stmt_execute($s);
                $r = mysqli_stmt_get_result($s);
                while ($row = mysqli_fetch_assoc($r)) fputcsv($out, [$row['expense_date'], $row['category'], $row['vendor'], $row['description'], $row['amount'], $row['payment_method'], $row['status'], $row['full_name']]);
                mysqli_stmt_close($s);
            }
        }
    } catch (Throwable $e) {
        // Keep CSV valid even if a runtime DB exception happens mid-export.
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rm = nMonth($_POST['return_month'] ?? $month);
    $rc = nCategory($_POST['return_category'] ?? $category_filter, $expense_categories, true);
    $redirect = retUrl($rm, $rc);

    if (function_exists('validateCSRFToken') && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: ' . $redirect);
        exit;
    }
    if (!$can_manage) {
        $_SESSION['error'] = 'You do not have permission to manage expenses.';
        header('Location: ' . $redirect);
        exit;
    }
    $expense_action_requested = isset($_POST['add_expense']) || isset($_POST['update_status']) || isset($_POST['delete_expense']);
    if ($expense_action_requested && !$expenses_schema_ready) {
        $_SESSION['error'] = 'Expenses table/schema is incomplete. Please run DB migration checker first.';
        header('Location: ' . $redirect);
        exit;
    }

    if (isset($_POST['add_expense'])) {
        $cat = nCategory($_POST['category'] ?? '', $expense_categories, false);
        $desc = trim((string)($_POST['description'] ?? ''));
        $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 1000) : substr($desc, 0, 1000);
        $amount = (float)($_POST['amount'] ?? 0);
        $edate = nDate($_POST['expense_date'] ?? date('Y-m-d'));
        $vendor = trim((string)($_POST['vendor'] ?? ''));
        $vendor = function_exists('mb_substr') ? mb_substr($vendor, 0, 100) : substr($vendor, 0, 100);
        $pay = nPay($_POST['payment_method'] ?? 'Cash', $payment_methods);

        if ($amount <= 0) {
            $_SESSION['error'] = 'Amount must be greater than zero.';
            header('Location: ' . $redirect);
            exit;
        }
        if ($seller_scope_id !== null && !($expense_columns['recorded_by'] ?? false) && !($expense_columns['owner_user_id'] ?? false)) {
            $_SESSION['error'] = 'Expense ownership column is missing; partner accounts cannot create expenses until schema is updated.';
            header('Location: ' . $redirect);
            exit;
        }

        $receipt = null;
        $upload_err = (int)($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE);
        if (isset($_FILES['receipt']) && $upload_err === UPLOAD_ERR_OK) {
            $dir = '../uploads/receipts/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $_SESSION['error'] = 'Unable to create receipt upload directory.';
                header('Location: ' . $redirect);
                exit;
            }
            $tmp = $_FILES['receipt']['tmp_name'];
            $name = (string)($_FILES['receipt']['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = (int)($_FILES['receipt']['size'] ?? 0);
            $okExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            if (!is_uploaded_file($tmp)) {
                $_SESSION['error'] = 'Invalid receipt upload source.';
                header('Location: ' . $redirect);
                exit;
            }
            if (!in_array($ext, $okExt, true)) {
                $_SESSION['error'] = 'Receipt type not allowed.';
                header('Location: ' . $redirect);
                exit;
            }
            if ($size > (5 * 1024 * 1024)) {
                $_SESSION['error'] = 'Receipt file too large (max 5MB).';
                header('Location: ' . $redirect);
                exit;
            }
            if ($ext !== 'pdf' && !@getimagesize($tmp)) {
                $_SESSION['error'] = 'Invalid receipt image.';
                header('Location: ' . $redirect);
                exit;
            }
            if ($ext === 'pdf' && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $tmp) : '';
                if ($finfo) finfo_close($finfo);
                if (!in_array((string)$mime, ['application/pdf', 'application/x-pdf'], true)) {
                    $_SESSION['error'] = 'Invalid receipt PDF file.';
                    header('Location: ' . $redirect);
                    exit;
                }
            }
            $new = uniqid('rcpt_', true) . '.' . $ext;
            if (move_uploaded_file($tmp, $dir . $new)) {
                $receipt = 'uploads/receipts/' . $new;
            } else {
                $_SESSION['error'] = 'Failed to store receipt file.';
                header('Location: ' . $redirect);
                exit;
            }
        } elseif (isset($_FILES['receipt']) && $upload_err !== UPLOAD_ERR_NO_FILE) {
            $_SESSION['error'] = 'Receipt upload failed. Please try again.';
            header('Location: ' . $redirect);
            exit;
        }

        $insert_cols = [];
        $insert_vals = [];
        $types = '';
        $params = [];
        $col_defs = [
            ['name' => 'category', 'type' => 's', 'value' => $cat, 'required' => true],
            ['name' => 'description', 'type' => 's', 'value' => $desc, 'required' => true],
            ['name' => 'amount', 'type' => 'd', 'value' => $amount, 'required' => true],
            ['name' => 'expense_date', 'type' => 's', 'value' => $edate, 'required' => true],
            ['name' => 'recorded_by', 'type' => 'i', 'value' => $admin_user_id, 'required' => false],
            ['name' => 'owner_user_id', 'type' => 'i', 'value' => $seller_scope_id !== null ? (int)$seller_scope_id : $admin_user_id, 'required' => false],
            ['name' => 'vendor', 'type' => 's', 'value' => $vendor, 'required' => false],
            ['name' => 'payment_method', 'type' => 's', 'value' => $pay, 'required' => false],
            ['name' => 'receipt_image', 'type' => 's', 'value' => $receipt, 'required' => false],
            ['name' => 'status', 'type' => 's', 'value' => 'approved', 'required' => false]
        ];
        foreach ($col_defs as $def) {
            if (!($expense_columns[$def['name']] ?? false)) {
                if ($def['required']) {
                    $_SESSION['error'] = 'Expenses table is missing required column: ' . $def['name'];
                    header('Location: ' . $redirect);
                    exit;
                }
                continue;
            }
            $insert_cols[] = $def['name'];
            $insert_vals[] = '?';
            $types .= $def['type'];
            $params[] = $def['value'];
        }
        $q = 'INSERT INTO expenses (' . implode(',', $insert_cols) . ') VALUES (' . implode(',', $insert_vals) . ')';
        try {
            $s = mysqli_prepare($conn, $q);
            if ($s) {
                bindParamsDynamic($s, $types, $params);
                if (mysqli_stmt_execute($s)) {
                    $_SESSION['success'] = 'Expense recorded successfully.';
                } else {
                    $_SESSION['error'] = 'Error recording expense.';
                }
                mysqli_stmt_close($s);
            } else {
                $_SESSION['error'] = 'Failed to prepare add expense query.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Unable to record expense due to schema mismatch.';
        }

        header('Location: ' . $redirect);
        exit;
    }

    if (isset($_POST['update_status'])) {
        $id = (int)($_POST['expense_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        if ($id <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
            $_SESSION['error'] = 'Invalid status update request.';
        } elseif (($expense_columns['is_system_generated'] ?? false) && (($ownershipRow = getExpenseOwnershipRecord($conn, $id, (bool)($expense_columns['recorded_by'] ?? false), true)) !== null) && !empty($ownershipRow['is_system_generated'])) {
            $_SESSION['error'] = 'System-synced expenses cannot be manually approved or rejected.';
        } elseif ($seller_scope_id !== null && !($expense_columns['recorded_by'] ?? false) && !($expense_columns['owner_user_id'] ?? false)) {
            $_SESSION['error'] = 'Expense ownership column is missing; status update is unavailable for partner accounts.';
        } elseif (!($expense_columns['status'] ?? false)) {
            $_SESSION['error'] = 'Status updates are unavailable because the status column is missing.';
        } else {
            try {
                $status_scope_sql = '';
                if ($seller_scope_id !== null) {
                    $status_scope_sql = ($expense_columns['owner_user_id'] ?? false)
                        ? ' AND COALESCE(owner_user_id, recorded_by)=?'
                        : ' AND recorded_by=?';
                }
                $status_sql = 'UPDATE expenses SET status=? WHERE id=?' . $status_scope_sql;
                $s = mysqli_prepare($conn, $status_sql);
                if ($s) {
                    if ($seller_scope_id !== null) {
                        mysqli_stmt_bind_param($s, 'sii', $status, $id, $seller_scope_id);
                    } else {
                        mysqli_stmt_bind_param($s, 'si', $status, $id);
                    }
                    if (mysqli_stmt_execute($s)) {
                        $_SESSION[mysqli_stmt_affected_rows($s) > 0 ? 'success' : 'error'] = mysqli_stmt_affected_rows($s) > 0
                            ? 'Expense status updated.'
                            : 'Expense record not found in your scope.';
                    } else {
                        $_SESSION['error'] = 'Failed to update status.';
                    }
                    mysqli_stmt_close($s);
                } else {
                    $_SESSION['error'] = 'Failed to prepare status update.';
                }
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Failed to update expense status.';
            }
        }
        header('Location: ' . $redirect);
        exit;
    }

    if (isset($_POST['delete_expense'])) {
        $id = (int)($_POST['expense_id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid delete request.';
        } elseif (($expense_columns['is_system_generated'] ?? false) && (($ownershipRow = getExpenseOwnershipRecord($conn, $id, (bool)($expense_columns['recorded_by'] ?? false), true)) !== null) && !empty($ownershipRow['is_system_generated'])) {
            $_SESSION['error'] = 'System-synced expenses cannot be deleted manually.';
        } elseif ($seller_scope_id !== null && !($expense_columns['recorded_by'] ?? false) && !($expense_columns['owner_user_id'] ?? false)) {
            $_SESSION['error'] = 'Expense ownership column is missing; delete action is unavailable for partner accounts.';
        } else {
            try {
                $delete_scope_sql = '';
                if ($seller_scope_id !== null) {
                    $delete_scope_sql = ($expense_columns['owner_user_id'] ?? false)
                        ? ' AND COALESCE(owner_user_id, recorded_by)=?'
                        : ' AND recorded_by=?';
                }
                $delete_sql = 'DELETE FROM expenses WHERE id=?' . $delete_scope_sql;
                $s = mysqli_prepare($conn, $delete_sql);
                if ($s) {
                    if ($seller_scope_id !== null) {
                        mysqli_stmt_bind_param($s, 'ii', $id, $seller_scope_id);
                    } else {
                        mysqli_stmt_bind_param($s, 'i', $id);
                    }
                    if (mysqli_stmt_execute($s)) {
                        $_SESSION[mysqli_stmt_affected_rows($s) > 0 ? 'success' : 'error'] = mysqli_stmt_affected_rows($s) > 0
                            ? 'Expense deleted successfully.'
                            : 'Expense record not found in your scope.';
                    } else {
                        $_SESSION['error'] = 'Error deleting expense.';
                    }
                    mysqli_stmt_close($s);
                } else {
                    $_SESSION['error'] = 'Failed to prepare delete request.';
                }
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Error deleting expense.';
            }
        }
        header('Location: ' . $redirect);
        exit;
    }

    if (isset($_POST['save_dss_recommendation'])) {
        $dc = nDssCategory($_POST['decision_category'] ?? 'pricing', $dss_categories);
        $pr = nPriority($_POST['priority'] ?? 'medium', $dss_priorities);
        $txt = trim((string)($_POST['recommendation_text'] ?? ''));
        $impact = trim((string)($_POST['expected_outcome'] ?? ''));
        $conf = max(0, min(100, (float)($_POST['confidence_score'] ?? 0)));
        $txt = function_exists('mb_substr') ? mb_substr($txt, 0, 1500) : substr($txt, 0, 1500);
        $impact = function_exists('mb_substr') ? mb_substr($impact, 0, 100) : substr($impact, 0, 100);

        if ($txt === '') {
            $_SESSION['error'] = 'Recommendation text is required.';
            header('Location: ' . $redirect . '#dss-panel');
            exit;
        }
        if (!$dss_queue_ready) {
            $_SESSION['error'] = 'DSS queue table/schema is not ready yet.';
            header('Location: ' . $redirect . '#dss-panel');
            exit;
        }

        try {
            $s = mysqli_prepare($conn, 'INSERT INTO decisions_recommendations (decision_category,recommendation_text,priority,confidence_score,recommendation_date,status,expected_impact,created_by) VALUES (?,?,?, ?,CURDATE(),"pending",?,?)');
            if ($s) {
                mysqli_stmt_bind_param($s, 'sssdsi', $dc, $txt, $pr, $conf, $impact, $admin_user_id);
                if (mysqli_stmt_execute($s)) $_SESSION['success'] = 'DSS recommendation queued.'; else $_SESSION['error'] = 'Unable to queue DSS recommendation.';
                mysqli_stmt_close($s);
            } else {
                $_SESSION['error'] = 'DSS queue table is not available.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Unable to queue DSS recommendation.';
        }
        header('Location: ' . $redirect . '#dss-panel');
        exit;
    }
}

$year = (int)substr($month, 0, 4);
$mon = (int)substr($month, 5, 2);
$month_start = $month . '-01';
$days_in_month = (int)date('t', strtotime($month_start));
$days_elapsed = ($month === date('Y-m')) ? (int)date('j') : $days_in_month;

$expenses = [];
if ($expenses_schema_ready) {
    try {
        if ($category_filter !== '') {
            $q = $expense_select_sql . " WHERE YEAR(e.expense_date)=? AND MONTH(e.expense_date)=? AND e.category=?" . $partner_expense_scope_sql . " ORDER BY e.expense_date DESC,e.id DESC";
            $s = mysqli_prepare($conn, $q);
            if ($s) {
                mysqli_stmt_bind_param($s, 'iis', $year, $mon, $category_filter);
                mysqli_stmt_execute($s);
                $r = mysqli_stmt_get_result($s);
                while ($row = mysqli_fetch_assoc($r)) $expenses[] = $row;
                mysqli_stmt_close($s);
            }
        } else {
            $q = $expense_select_sql . " WHERE YEAR(e.expense_date)=? AND MONTH(e.expense_date)=?" . $partner_expense_scope_sql . " ORDER BY e.expense_date DESC,e.id DESC";
            $s = mysqli_prepare($conn, $q);
            if ($s) {
                mysqli_stmt_bind_param($s, 'ii', $year, $mon);
                mysqli_stmt_execute($s);
                $r = mysqli_stmt_get_result($s);
                while ($row = mysqli_fetch_assoc($r)) $expenses[] = $row;
                mysqli_stmt_close($s);
            }
        }
    } catch (Throwable $e) {
        $runtime_warnings[] = 'Unable to load expense records for the selected period.';
    }
}

$total = 0.0; $approved = 0.0; $pending = 0.0; $largest = 0.0;
$status_counts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
$cat_all = array_fill_keys($expense_categories, 0.0);
$cat_approved = array_fill_keys($expense_categories, 0.0);
$daily = [];
for ($i = 1; $i <= $days_in_month; $i++) $daily[$i] = 0.0;
foreach ($expenses as $e) {
    $amt = (float)($e['amount'] ?? 0);
    $st = strtolower((string)($e['status'] ?? 'pending'));
    if (!in_array($st, ['approved', 'pending', 'rejected'], true)) $st = 'pending';
    $cat = (string)($e['category'] ?? 'Miscellaneous');
    $day = (int)date('j', strtotime((string)($e['expense_date'] ?? '')));
    if ($day < 1 || $day > $days_in_month) $day = 1;
    $total += $amt;
    $largest = max($largest, $amt);
    if (!isset($cat_all[$cat])) $cat_all[$cat] = 0.0;
    $cat_all[$cat] += $amt;
    $daily[$day] = ($daily[$day] ?? 0) + $amt;
    if (!isset($status_counts[$st])) $status_counts[$st] = 0;
    $status_counts[$st]++;
    if ($st === 'approved') {
        $approved += $amt;
        if (!isset($cat_approved[$cat])) $cat_approved[$cat] = 0.0;
        $cat_approved[$cat] += $amt;
    } elseif ($st === 'pending') {
        $pending += $amt;
    }
}

$count = count($expenses);
$avg = $count > 0 ? ($total / $count) : 0.0;

$cat_labels = []; $cat_vals = [];
foreach ($cat_all as $k => $v) {
    if ($v > 0) { $cat_labels[] = $k; $cat_vals[] = round($v, 2); }
}
if (empty($cat_labels)) { $cat_labels = ['No Data']; $cat_vals = [1]; }
ksort($daily);
$trend_labels = []; $trend_vals = []; $nz = [];
foreach ($daily as $d => $v) { $trend_labels[] = str_pad((string)$d, 2, '0', STR_PAD_LEFT); $trend_vals[] = round($v, 2); if ($v > 0) $nz[] = $v; }
$mean = 0.0; $std = 0.0;
if (!empty($nz)) {
    $mean = array_sum($nz) / count($nz);
    $var = 0.0; foreach ($nz as $v) $var += pow($v - $mean, 2);
    $std = sqrt($var / count($nz));
}
$spikes = [];
if ($std > 0) {
    $th = $mean + (1.8 * $std);
    foreach ($daily as $d => $v) if ($v > $th) $spikes[] = ['day' => $d, 'amount' => $v];
}
$spikes = array_slice($spikes, 0, 3);

$order_rev = 0.0;
if ($has_orders_table && $has_orders_created_at && $orders_sum_col !== null) {
    $order_sql = "SELECT COALESCE(SUM(o.{$orders_sum_col}),0) AS t FROM orders o WHERE YEAR(o.created_at)=? AND MONTH(o.created_at)=?";
    if ($has_orders_status) $order_sql .= " AND o.status IN ('delivered','completed')";
    if ($has_orders_archived) $order_sql .= " AND COALESCE(o.is_archived,0)=0";
    if ($seller_scope_id !== null) $order_sql .= $partner_orders_scope_exists_sql;
    $order_rev = monthSum($conn, $order_sql, $year, $mon);
}
$pre_rev = 0.0;
if ($has_pre_orders_table && $has_pre_orders_created_at && $pre_orders_sum_col !== null) {
    $pre_sql = "SELECT COALESCE(SUM({$pre_orders_sum_col}),0) AS t FROM pre_orders WHERE YEAR(created_at)=? AND MONTH(created_at)=?";
    if ($has_pre_orders_status) $pre_sql .= " AND reservation_status!='cancelled'";
    if ($seller_scope_id !== null) $pre_sql .= $partner_preorders_scope_exists_sql;
    $pre_rev = monthSum($conn, $pre_sql, $year, $mon);
}
$revenue = $order_rev + $pre_rev;
$net = $revenue - $approved;
$ratio = $revenue > 0 ? ($approved / $revenue) * 100 : ($approved > 0 ? 100 : 0);
$margin = $revenue > 0 ? ($net / $revenue) * 100 : 0;
$proj_spend = $days_elapsed > 0 ? ($approved / $days_elapsed) * $days_in_month : $approved;
$proj_rev = $days_elapsed > 0 ? ($revenue / $days_elapsed) * $days_in_month : $revenue;
$prev_key = date('Y-m', strtotime($month_start . ' -1 month'));
$prev_y = (int)substr($prev_key, 0, 4);
$prev_m = (int)substr($prev_key, 5, 2);
$prev_sql = "SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE YEAR(expense_date)=? AND MONTH(expense_date)=?";
if ($expense_columns['status'] ?? false) $prev_sql .= " AND status='approved'";
if ($seller_scope_id !== null) {
    if ($expense_columns['owner_user_id'] ?? false) {
        $prev_sql .= " AND COALESCE(owner_user_id, recorded_by)=" . (int)$seller_scope_id;
    } elseif ($expense_columns['recorded_by'] ?? false) {
        $prev_sql .= " AND recorded_by=" . (int)$seller_scope_id;
    }
}
$prev_approved = monthSum($conn, $prev_sql, $prev_y, $prev_m);
$change = $prev_approved > 0 ? (($approved - $prev_approved) / $prev_approved) * 100 : ($approved > 0 ? 100 : 0);
$top_cat = 'N/A'; $top_amt = 0.0;
foreach ($cat_approved as $k => $v) { if ($v > $top_amt) { $top_amt = $v; $top_cat = $k; } }
$top_share = $approved > 0 ? ($top_amt / $approved) * 100 : 0;

$recs = [];
$conf = max(0.55, min(0.95, 0.55 + min(0.35, (($status_counts['approved'] ?? 0) / 50))));
if ($ratio >= 70) {
    $save = max(5000, $approved * 0.10);
    $recs[] = ['decision_category' => 'pricing', 'priority' => 'critical', 'headline' => 'Expense pressure is above safe range', 'rationale' => 'Approved expenses are ' . number_format($ratio, 1) . '% of revenue.', 'action' => 'Run a 14-day margin recovery: freeze non-essential spend and renegotiate top vendor lines.', 'expected_outcome' => 'Potential savings: ' . money($save), 'expected_cost' => max(1200, $save * 0.18), 'expected_benefit' => $save, 'implementation_timeline' => 5, 'risk_exposure' => 58, 'market_volatility' => min(85, max(20, abs($change))), 'dependency_risk' => 42, 'confidence_level' => min(0.95, $conf + 0.08)];
}
if ($proj_rev > 0 && $proj_spend > ($proj_rev * 0.85)) {
    $save = max(3000, $proj_spend - ($proj_rev * 0.80));
    $recs[] = ['decision_category' => 'production', 'priority' => 'high', 'headline' => 'Month-end overspend risk detected', 'rationale' => 'Projected spend is ' . money($proj_spend) . ' vs projected revenue ' . money($proj_rev) . '.', 'action' => 'Switch to demand-linked purchasing and enable medium/large expense pre-approval for the rest of the month.', 'expected_outcome' => 'Avoided overspend: ' . money($save), 'expected_cost' => max(900, $save * 0.15), 'expected_benefit' => $save, 'implementation_timeline' => 4, 'risk_exposure' => 45, 'market_volatility' => min(80, max(18, abs($change))), 'dependency_risk' => 30, 'confidence_level' => $conf];
}
if ($top_share >= 45 && $approved > 0) {
    $save = max(2500, $approved * 0.06);
    $cat_map = ['Payroll' => 'staffing', 'Labor' => 'staffing', 'Raw Materials' => 'inventory', 'Delivery' => 'logistics', 'Marketing' => 'marketing', 'Equipment' => 'production', 'Utilities' => 'production', 'Permits' => 'logistics', 'Miscellaneous' => 'pricing'];
    $recs[] = ['decision_category' => $cat_map[$top_cat] ?? 'pricing', 'priority' => 'high', 'headline' => 'Expense concentration is too high', 'rationale' => $top_cat . ' is ' . number_format($top_share, 1) . '% of approved spend.', 'action' => 'Set weekly budget caps and vendor-level variance alerts for this category.', 'expected_outcome' => 'Expected containment impact: ' . money($save), 'expected_cost' => max(600, $save * 0.12), 'expected_benefit' => $save, 'implementation_timeline' => 7, 'risk_exposure' => 36, 'market_volatility' => min(70, max(12, abs($change))), 'dependency_risk' => 28, 'confidence_level' => $conf];
}
if (empty($recs)) {
    $recs[] = ['decision_category' => 'pricing', 'priority' => 'low', 'headline' => 'Current expense posture is stable', 'rationale' => 'Expense ratio and trend are inside acceptable range.', 'action' => 'Maintain weekly review cadence and anomaly checks.', 'expected_outcome' => 'Sustained cost control with low operational risk.', 'expected_cost' => 300, 'expected_benefit' => 1000, 'implementation_timeline' => 7, 'risk_exposure' => 20, 'market_volatility' => 15, 'dependency_risk' => 18, 'confidence_level' => $conf];
}

$scorer = new DecisionScoringService($conn);
foreach ($recs as $i => $rec) {
    $roi = (($rec['expected_benefit'] - max(1, $rec['expected_cost'])) / max(1, $rec['expected_cost'])) * 100;
    $d = ['confidence_level' => $rec['confidence_level'], 'forecast_variance' => min(95, max(8, abs($change))), 'expected_cost' => $rec['expected_cost'], 'expected_benefit' => $rec['expected_benefit'], 'roi_estimate' => $roi, 'implementation_timeline' => $rec['implementation_timeline'], 'risk_exposure' => $rec['risk_exposure'], 'market_volatility' => $rec['market_volatility'], 'dependency_risk' => $rec['dependency_risk'], 'category' => $rec['decision_category'], 'business_priorities' => ['pricing', 'inventory', 'staffing']];
    try { $score = $scorer->scoreDecision($d); } catch (Throwable $e) { $score = fallbackScore($d); }
    $recs[$i]['score'] = (float)($score['total_score'] ?? 0);
    $recs[$i]['rating'] = $score['rating']['label'] ?? 'Good';
    $recs[$i]['score_color'] = $score['rating']['color'] ?? 'info';
    $recs[$i]['confidence_score'] = round($rec['confidence_level'] * 100, 2);
    $recs[$i]['recommendation_text'] = $rec['headline'] . ' Action: ' . $rec['action'];
}
usort($recs, function ($a, $b) { $pc = rankPriority($b['priority']) <=> rankPriority($a['priority']); return $pc !== 0 ? $pc : ($b['score'] <=> $a['score']); });

$scenarios = [
    ['name' => 'Cost Lockdown', 'summary' => 'Freeze discretionary spend and renegotiate top vendors immediately.', 'decision_category' => 'pricing', 'expected_cost' => max(1, $approved * 0.03), 'expected_benefit' => max(6000, $approved * 0.13), 'implementation_timeline' => 5, 'risk_exposure' => 52, 'dependency_risk' => 44, 'confidence_level' => min(0.95, $conf + 0.04), 'market_volatility' => min(85, max(15, abs($change))), 'tradeoff' => 'Highest savings potential with tighter operations.'],
    ['name' => 'Balanced Optimization', 'summary' => 'Keep growth spend, enforce budget caps and approval guardrails.', 'decision_category' => 'inventory', 'expected_cost' => max(1, $approved * 0.05), 'expected_benefit' => max(7000, $approved * 0.16), 'implementation_timeline' => 9, 'risk_exposure' => 34, 'dependency_risk' => 30, 'confidence_level' => $conf, 'market_volatility' => max(20, min(90, abs($change) - 8)), 'tradeoff' => 'Balanced control with moderate rollout effort.'],
    ['name' => 'Growth-First Spend', 'summary' => 'Maintain strong marketing and production push with margin floor monitoring.', 'decision_category' => 'marketing', 'expected_cost' => max(1, $approved * 0.08), 'expected_benefit' => max(8500, $approved * 0.18), 'implementation_timeline' => 14, 'risk_exposure' => 60, 'dependency_risk' => 50, 'confidence_level' => max(0.55, $conf - 0.07), 'market_volatility' => min(90, max(20, abs($change) + 10)), 'tradeoff' => 'Supports upside but exposes margin risk.']
];
foreach ($scenarios as $i => $opt) {
    $roi = (($opt['expected_benefit'] - $opt['expected_cost']) / $opt['expected_cost']) * 100;
    $d = ['confidence_level' => $opt['confidence_level'], 'forecast_variance' => min(95, max(8, abs($change))), 'expected_cost' => $opt['expected_cost'], 'expected_benefit' => $opt['expected_benefit'], 'roi_estimate' => $roi, 'implementation_timeline' => $opt['implementation_timeline'], 'risk_exposure' => $opt['risk_exposure'], 'market_volatility' => $opt['market_volatility'], 'dependency_risk' => $opt['dependency_risk'], 'category' => $opt['decision_category'], 'business_priorities' => ['pricing', 'inventory', 'staffing']];
    try { $score = $scorer->scoreDecision($d); } catch (Throwable $e) { $score = fallbackScore($d); }
    $scenarios[$i]['roi_estimate'] = $roi;
    $scenarios[$i]['score'] = (float)($score['total_score'] ?? 0);
    $scenarios[$i]['score_color'] = $score['rating']['color'] ?? 'info';
    $scenarios[$i]['projected_net_income'] = $net + $opt['expected_benefit'] - $opt['expected_cost'];
}
usort($scenarios, fn($a, $b) => $b['score'] <=> $a['score']);
$best = $scenarios[0] ?? null;

$month_label = date('F Y', strtotime($month_start));
$export_url = '?export=csv&month=' . rawurlencode($month) . ($category_filter !== '' ? '&category=' . rawurlencode($category_filter) : '');
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses DSS - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1 {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small,
        body.dark-mode .decision-note,
        body.dark-mode .decision-kpi-label {
            color: #b0b0b0 !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin-right: 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .decision-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }

        .decision-kpi-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            background: #fff;
            height: 100%;
        }

        .decision-kpi-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .decision-kpi-value {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
            margin-bottom: 6px;
        }

        .risk-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .risk-chip.high { background: #fef2f2; color: #b91c1c; }
        .risk-chip.medium { background: #fff7ed; color: #c2410c; }
        .risk-chip.low { background: #ecfdf3; color: #027a48; }

        .decision-note {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 0;
        }

        .scenario-best {
            border-left: 4px solid #16a34a;
        }

        .trend-chart-wrap {
            position: relative;
            height: 300px;
            min-height: 300px;
        }

        .pie-chart-wrap {
            position: relative;
            width: 100%;
            height: 260px;
            min-height: 260px;
        }

        body.dark-mode .decision-kpi-card {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .decision-kpi-value {
            color: var(--text-color-dark) !important;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Expenses</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto; margin-right: 15px;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name'] ?? 'Admin'); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <?php if (!empty($runtime_warnings)): ?>
                    <div class="alert alert-warning mb-4">
                        <?php foreach (array_unique($runtime_warnings) as $warn): ?>
                            <div><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars($warn); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label>Month</label>
                                <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($month); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>Category</label>
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($expense_categories as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $category_filter === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <small class="text-muted">Records: <strong><?php echo number_format($count); ?></strong> | Avg: <strong><?php echo money($avg); ?></strong></small>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="section-header">
                    <h2>Expense Overview (<?php echo htmlspecialchars($month_label); ?>)</h2>
                    <div class="d-flex gap-2">
                        <?php if ($can_manage_expenses): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal"><i class="fas fa-plus"></i> Add Expense</button>
                        <?php endif; ?>
                        <?php if ($expenses_schema_ready && $can_manage): ?>
                            <a href="<?php echo htmlspecialchars($export_url); ?>" class="btn btn-success"><i class="fas fa-file-export"></i> Export CSV</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($seller_scope_id !== null && $economicsSnapshot): ?>
                    <div class="card mb-4">
                        <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
                            <div>
                                <strong>System Expense Sync</strong>
                                <div class="text-muted small">
                                    Platform billing: <?php echo number_format((int)($expenseSyncSummary['billing_synced'] ?? 0)); ?> |
                                    Refunds: <?php echo number_format((int)($expenseSyncSummary['refunds_synced'] ?? 0)); ?> |
                                    Procurement: <?php echo number_format((int)($expenseSyncSummary['procurement_synced'] ?? 0)); ?> |
                                    Supplier invoices: <?php echo number_format((int)($expenseSyncSummary['supplier_invoices_synced'] ?? 0)); ?>
                                </div>
                            </div>
                            <div class="text-muted small">
                                Fully loaded position: <strong><?php echo money($economicsSnapshot['positions']['fully_loaded_position'] ?? 0); ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="dashboard-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffebee;"><i class="fas fa-wallet text-danger"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money($approved); ?></h3>
                            <p>Approved Expenses</p>
                            <small class="text-muted">All logged: <?php echo money($total); ?></small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e3f2fd;"><i class="fas fa-coins text-primary"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money($revenue); ?></h3>
                            <p>Monthly Revenue</p>
                            <small class="text-muted">Orders + pre-orders</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e8f5e9;"><i class="fas fa-chart-line text-success"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money($net); ?></h3>
                            <p>Net Income</p>
                            <small class="<?php echo $net >= 0 ? 'text-success' : 'text-danger'; ?>">Margin: <?php echo number_format($margin, 1); ?>%</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff7ed;"><i class="fas fa-hourglass-half text-warning"></i></div>
                        <div class="stat-content">
                            <h3><?php echo number_format($status_counts['pending'] ?? 0); ?></h3>
                            <p>Pending Approvals</p>
                            <small class="text-muted"><?php echo money($pending); ?> pending value</small>
                        </div>
                    </div>
                </div>

                <?php if ($seller_scope_id !== null && $economicsSnapshot): ?>
                <div class="dashboard-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #eef2ff;"><i class="fas fa-file-invoice-dollar text-primary"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money(($economicsSnapshot['platform']['paid_total'] ?? 0) + ($economicsSnapshot['platform']['due_total'] ?? 0) + ($economicsSnapshot['platform']['overdue_total'] ?? 0)); ?></h3>
                            <p>Platform Cost Load</p>
                            <small class="text-muted">Paid + due + overdue partner billing</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #eff6ff;"><i class="fas fa-dolly text-info"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money($economicsSnapshot['procurement']['open_commitments'] ?? 0); ?></h3>
                            <p>Open Procurement</p>
                            <small class="text-muted"><?php echo number_format((int)($economicsSnapshot['procurement']['open_count'] ?? 0)); ?> purchase orders in motion</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fef2f2;"><i class="fas fa-undo text-danger"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money(($economicsSnapshot['refunds']['pending_total'] ?? 0) + ($economicsSnapshot['refunds']['approved_total'] ?? 0)); ?></h3>
                            <p>Refund Exposure</p>
                            <small class="text-muted"><?php echo number_format((int)($economicsSnapshot['refunds']['open_cases'] ?? 0)); ?> open cases</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ecfdf5;"><i class="fas fa-users text-success"></i></div>
                        <div class="stat-content">
                            <h3><?php echo money(($economicsSnapshot['booked']['payroll_expenses'] ?? 0) + ($economicsSnapshot['pipeline']['pending_payroll'] ?? 0)); ?></h3>
                            <p>Payroll Load</p>
                            <small class="text-muted">Booked + pending payroll pipeline</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($seller_scope_id !== null && $economicsSnapshot): ?>
                <div class="row mb-4">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-chart-area text-danger"></i> Expense Forecast</h5>
                                <small class="text-muted">
                                    Pace: <?php echo number_format((int)($economicsSnapshot['forecast']['days_elapsed'] ?? 0)); ?>/<?php echo number_format((int)($economicsSnapshot['forecast']['days_in_month'] ?? 0)); ?> days
                                </small>
                            </div>
                            <div class="card-body">
                                <div class="dashboard-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background: #eff6ff;"><i class="fas fa-calendar-alt text-primary"></i></div>
                                        <div class="stat-content">
                                            <h3><?php echo money($economicsSnapshot['forecast']['projected_month_end_spend'] ?? 0); ?></h3>
                                            <p>Projected Month-End Spend</p>
                                            <small class="text-muted">Booked pace + open obligations</small>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background: #ecfdf5;"><i class="fas fa-shield-dollar text-success"></i></div>
                                        <div class="stat-content">
                                            <h3><?php echo money($economicsSnapshot['forecast']['projected_month_end_profit'] ?? 0); ?></h3>
                                            <p>Projected Month-End Profit</p>
                                            <small class="<?php echo (($economicsSnapshot['forecast']['projected_month_end_profit'] ?? 0) >= 0) ? 'text-success' : 'text-danger'; ?>">
                                                Against projected revenue of <?php echo money($economicsSnapshot['forecast']['projected_month_end_revenue'] ?? 0); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background: #fef2f2;"><i class="fas fa-triangle-exclamation text-danger"></i></div>
                                        <div class="stat-content">
                                            <h3><?php echo money($economicsSnapshot['forecast']['profit_at_risk'] ?? 0); ?></h3>
                                            <p>Profit At Risk</p>
                                            <small class="text-muted">Gap between booked and fully loaded position</small>
                                        </div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon" style="background: #fff7ed;"><i class="fas fa-boxes-stacked text-warning"></i></div>
                                        <div class="stat-content">
                                            <h3><?php echo number_format((int)($economicsSnapshot['forecast']['reorder_risk_count'] ?? 0)); ?></h3>
                                            <p>Reorder Risk Materials</p>
                                            <small class="text-muted"><?php echo number_format((float)($economicsSnapshot['forecast']['reorder_risk_units'] ?? 0), 2); ?> units below minimum</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($seller_scope_id !== null && $economicsSnapshot): ?>
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-layer-group text-danger"></i> Cost Source Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach (($economicsSnapshot['cost_sources'] ?? []) as $costSource): ?>
                                        <div class="list-group-item px-0 d-flex justify-content-between align-items-start border-0 border-bottom">
                                            <div class="pe-3">
                                                <strong><?php echo htmlspecialchars((string)($costSource['label'] ?? 'Cost Source')); ?></strong>
                                                <div class="small text-muted"><?php echo htmlspecialchars((string)($costSource['note'] ?? '')); ?></div>
                                            </div>
                                            <span class="fw-semibold text-danger"><?php echo money((float)($costSource['amount'] ?? 0)); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-brain text-danger"></i> Forecast Recommendations</h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach (($economicsSnapshot['forecast_recommendations'] ?? $economicsSnapshot['recommendations'] ?? []) as $recommendation): ?>
                                        <?php
                                            $priority = (string)($recommendation['priority'] ?? 'low');
                                            $priorityBadge = $priority === 'critical' ? 'danger' : ($priority === 'high' ? 'warning' : ($priority === 'medium' ? 'info' : 'secondary'));
                                        ?>
                                        <div class="list-group-item px-0 border-0 border-bottom">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <strong><?php echo htmlspecialchars((string)($recommendation['headline'] ?? 'Recommendation')); ?></strong>
                                                <span class="badge bg-<?php echo htmlspecialchars($priorityBadge); ?>"><?php echo htmlspecialchars(strtoupper($priority)); ?></span>
                                            </div>
                                            <div class="small mt-2"><?php echo htmlspecialchars((string)($recommendation['action'] ?? 'Review this cost signal.')); ?></div>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars((string)($recommendation['detail'] ?? '')); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row mt-4 mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Daily Expense Trend</h5>
                                <small class="text-muted">Largest expense: <?php echo money($largest); ?></small>
                            </div>
                            <div class="card-body">
                                <div class="trend-chart-wrap">
                                    <canvas id="trendChart"></canvas>
                                </div>
                                <?php if (!empty($spikes)): ?>
                                    <div class="small text-danger mt-2">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Spikes on:
                                        <?php $sx=[]; foreach($spikes as $s){$sx[] = str_pad((string)$s['day'],2,'0',STR_PAD_LEFT) . ' (' . money($s['amount']) . ')';} echo htmlspecialchars(implode(', ',$sx)); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-muted mt-2">No unusual spend spikes detected.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-header bg-white"><h5 class="mb-0">Expense Breakdown</h5></div>
                            <div class="card-body d-flex align-items-center justify-content-center flex-column">
                                <div class="pie-chart-wrap">
                                    <canvas id="categoryChart"></canvas>
                                </div>
                                <small class="text-muted mt-2">Top category: <strong><?php echo htmlspecialchars($top_cat); ?></strong> (<?php echo number_format($top_share, 1); ?>%)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4" id="dss-panel">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-brain text-danger"></i> Expense DSS Recommendations</h5>
                    </div>
                    <div class="card-body">
                        <div class="decision-kpi-grid">
                            <?php foreach ($recs as $rec): ?>
                                <?php $risk_level = $rec['priority'] === 'critical' || $rec['priority'] === 'high' ? 'high' : ($rec['priority'] === 'medium' ? 'medium' : 'low'); ?>
                                <div class="decision-kpi-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="risk-chip <?= $risk_level ?>"><?= strtoupper(htmlspecialchars($rec['priority'])) ?></span>
                                        <span class="badge bg-<?php echo htmlspecialchars($rec['score_color']); ?>"><?php echo number_format((float)$rec['score'], 1); ?></span>
                                    </div>
                                    <div class="decision-kpi-label"><?php echo htmlspecialchars($rec['decision_category']); ?></div>
                                    <div class="decision-kpi-value"><?php echo htmlspecialchars($rec['headline']); ?></div>
                                    <p class="decision-note mt-2"><?php echo htmlspecialchars($rec['rationale']); ?></p>
                                    <p class="mb-1 mt-2"><strong>Action:</strong> <?php echo htmlspecialchars($rec['action']); ?></p>
                                    <small class="text-success d-block mb-2"><strong>Expected:</strong> <?php echo htmlspecialchars($rec['expected_outcome']); ?></small>
                                    <small class="decision-note d-block mb-2">Confidence: <?php echo number_format((float)$rec['confidence_score'], 1); ?>%</small>
                                    <?php if ($can_queue_dss): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="return_month" value="<?php echo htmlspecialchars($month); ?>"><input type="hidden" name="return_category" value="<?php echo htmlspecialchars($category_filter); ?>"><input type="hidden" name="save_dss_recommendation" value="1"><input type="hidden" name="decision_category" value="<?php echo htmlspecialchars($rec['decision_category']); ?>"><input type="hidden" name="priority" value="<?php echo htmlspecialchars($rec['priority']); ?>"><input type="hidden" name="confidence_score" value="<?php echo htmlspecialchars(number_format((float)$rec['confidence_score'], 2, '.', '')); ?>"><input type="hidden" name="recommendation_text" value="<?php echo htmlspecialchars($rec['recommendation_text']); ?>"><input type="hidden" name="expected_outcome" value="<?php echo htmlspecialchars($rec['expected_outcome']); ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmSaveRecommendation(this.form)"><i class="fas fa-plus-circle"></i> Queue in DSS</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-sitemap text-danger"></i> Strategy Simulator</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($best): ?><p class="decision-note mb-3">Recommended strategy: <strong><?php echo htmlspecialchars($best['name']); ?></strong></p><?php endif; ?>
                        <div class="decision-kpi-grid">
                            <?php foreach ($scenarios as $i => $o): ?>
                                <div class="decision-kpi-card <?= $i === 0 ? 'scenario-best' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <strong><?php echo htmlspecialchars($o['name']); ?></strong>
                                        <span class="badge bg-<?php echo htmlspecialchars($o['score_color']); ?>"><?php echo number_format((float)$o['score'], 1); ?></span>
                                    </div>
                                    <p class="decision-note mb-2"><?php echo htmlspecialchars($o['summary']); ?></p>
                                    <div class="decision-note">ROI: <?php echo number_format((float)$o['roi_estimate'], 1); ?>%</div>
                                    <div class="decision-note">Projected Net: <?php echo money($o['projected_net_income']); ?></div>
                                    <div class="decision-note mt-1"><?php echo htmlspecialchars($o['tradeoff']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Expense Records</h5>
                        <small class="text-muted"><?php echo htmlspecialchars($month_label); ?><?php echo $category_filter !== '' ? ' - ' . htmlspecialchars($category_filter) : ''; ?></small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="admin-table mb-0">
                                <thead><tr><th>Date</th><th>Category</th><th>Source</th><th>Vendor</th><th>Description</th><th>Amount</th><th>Payment</th><th>Status</th><th>Receipt</th><th>Recorded By</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php if (!empty($expenses)): foreach ($expenses as $e): $st = strtolower((string)($e['status'] ?? 'pending')); $badge = $st === 'approved' ? 'success' : ($st === 'rejected' ? 'danger' : 'warning'); ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($e['expense_date'])); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($e['category']); ?></span></td>
                                        <td>
                                            <?php if (!empty($e['is_system_generated'])): ?>
                                                <span class="badge bg-dark"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($e['source_type'] ?? 'system')))); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark">Manual</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($e['vendor'] ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($e['description'] ?: '-'); ?></td>
                                        <td class="text-danger"><?php echo money($e['amount']); ?></td>
                                        <td><?php echo htmlspecialchars($e['payment_method'] ?: '-'); ?></td>
                                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                                        <td>
                                            <?php $receipt_href = safeReceiptHref($e['receipt_image'] ?? null); ?>
                                            <?php if ($receipt_href): ?>
                                                <a href="<?php echo htmlspecialchars($receipt_href); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-image"></i></a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($e['full_name'] ?? 'System'); ?></td>
                                        <td>
                                            <?php if ($can_manage_expenses): ?>
                                                <?php if (!empty($e['is_system_generated'])): ?>
                                                    <span class="text-muted small">System-synced</span>
                                                <?php else: ?>
                                                <?php if ($can_update_expense_status && $st === 'pending'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="return_month" value="<?php echo htmlspecialchars($month); ?>"><input type="hidden" name="return_category" value="<?php echo htmlspecialchars($category_filter); ?>"><input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>"><input type="hidden" name="update_status" value="1"><input type="hidden" name="status" value="">
                                                        <button type="button" class="btn-icon text-success" data-status="approved" onclick="confirmStatusUpdate(this)" title="Approve"><i class="fas fa-check"></i></button>
                                                        <button type="button" class="btn-icon text-danger" data-status="rejected" onclick="confirmStatusUpdate(this)" title="Reject"><i class="fas fa-times"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" id="deleteForm<?php echo (int)$e['id']; ?>" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="return_month" value="<?php echo htmlspecialchars($month); ?>"><input type="hidden" name="return_category" value="<?php echo htmlspecialchars($category_filter); ?>"><input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>"><input type="hidden" name="delete_expense" value="1">
                                                    <button type="button" class="btn-icon btn-icon-danger" title="Delete" onclick="confirmDeleteExpense('deleteForm<?php echo (int)$e['id']; ?>')"><i class="fas fa-trash"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            <?php else: ?><span class="text-muted small">View only</span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="11" class="text-center text-muted py-4">No expense records found for this filter.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php if ($can_manage_expenses): ?>
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus-circle text-danger"></i> Record New Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form method="POST" enctype="multipart/form-data" id="addExpenseForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="return_month" value="<?php echo htmlspecialchars($month); ?>"><input type="hidden" name="return_category" value="<?php echo htmlspecialchars($category_filter); ?>"><input type="hidden" name="add_expense" value="1">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Category</label><select name="category" class="form-select" required><?php foreach ($expense_categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label">Payment Method</label><select name="payment_method" class="form-select"><?php foreach ($payment_methods as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Vendor / Supplier</label><input type="text" name="vendor" class="form-control" maxlength="100" placeholder="e.g. Prime Pork Supplier"></div>
                        <div class="col-md-6"><label class="form-label">Amount (PHP)</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3" maxlength="1000" placeholder="Optional details for audit trail"></textarea></div>
                        <div class="col-12"><label class="form-label">Receipt (Optional)</label><input type="file" name="receipt" class="form-control" accept="image/*,application/pdf"><small class="text-muted">Max file size: 5MB</small></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Save Expense</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const t=document.getElementById('themeToggler');const b=document.body;const ic=t?t.querySelector('i'):null;
if(localStorage.getItem('theme')==='dark'){b.classList.add('dark-mode');if(ic){ic.classList.remove('fa-moon');ic.classList.add('fa-sun');}}
if(t){t.addEventListener('click',()=>{b.classList.toggle('dark-mode');const d=b.classList.contains('dark-mode');localStorage.setItem('theme',d?'dark':'light');if(ic)ic.className=d?'fas fa-sun':'fas fa-moon';});}
const ok=<?php echo json_encode($success); ?>;const err=<?php echo json_encode($error); ?>;
if(ok)Swal.fire({icon:'success',title:'Success',text:ok,timer:2300,showConfirmButton:false});
if(err)Swal.fire({icon:'error',title:'Error',text:err});
function confirmDeleteExpense(id){Swal.fire({icon:'warning',title:'Delete expense record?',text:'This action cannot be undone.',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Delete'}).then(r=>{if(r.isConfirmed){const f=document.getElementById(id);if(f)f.submit();}});}
function confirmStatusUpdate(btn){const f=btn.closest('form');if(!f)return;const st=btn.getAttribute('data-status');const ap=st==='approved';Swal.fire({icon:ap?'question':'warning',title:ap?'Approve this expense?':'Reject this expense?',text:ap?'The expense will be marked as approved.':'The expense will be marked as rejected.',showCancelButton:true,confirmButtonColor:ap?'#16a34a':'#dc2626',confirmButtonText:ap?'Approve':'Reject'}).then(r=>{if(r.isConfirmed){const sf=f.querySelector('input[name="status"]');if(sf)sf.value=st;f.submit();}});}
function confirmSaveRecommendation(form){Swal.fire({icon:'info',title:'Queue this DSS recommendation?',text:'This will send the recommendation to the DSS queue as pending.',showCancelButton:true,confirmButtonColor:'#b91c1c',confirmButtonText:'Queue Recommendation'}).then(r=>{if(r.isConfirmed)form.submit();});}
const add=document.getElementById('addExpenseForm');if(add){add.addEventListener('submit',function(e){if(this.dataset.confirmed==='1')return;e.preventDefault();Swal.fire({icon:'question',title:'Save this expense entry?',text:'Please confirm details before submit.',showCancelButton:true,confirmButtonColor:'#b91c1c',confirmButtonText:'Save Expense'}).then(r=>{if(r.isConfirmed){this.dataset.confirmed='1';this.submit();}});});}
const peso=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP',maximumFractionDigits:2});
const cL=<?php echo json_encode($cat_labels); ?>; const cV=<?php echo json_encode($cat_vals); ?>;
const cCtx=document.getElementById('categoryChart'); if(cCtx){new Chart(cCtx,{type:'doughnut',data:{labels:cL,datasets:[{data:cV,backgroundColor:['#ef4444','#f97316','#f59e0b','#10b981','#3b82f6','#6366f1','#8b5cf6','#ec4899','#64748b'],borderColor:'#fff',borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},tooltip:{callbacks:{label:(ctx)=>`${ctx.label}: ${peso.format(Number(ctx.raw||0))}`}}}}});}
const tL=<?php echo json_encode($trend_labels); ?>; const tV=<?php echo json_encode($trend_vals); ?>;
const tCtx=document.getElementById('trendChart'); if(tCtx){new Chart(tCtx,{type:'line',data:{labels:tL,datasets:[{label:'Daily Expense',data:tV,borderColor:'#dc2626',backgroundColor:'rgba(220,38,38,.12)',fill:true,tension:.35,borderWidth:2.2,pointRadius:2,pointHoverRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:(ctx)=>` ${peso.format(Number(ctx.raw||0))}`}}},scales:{y:{beginAtZero:true,ticks:{callback:(v)=>'PHP '+Number(v).toLocaleString()}},x:{title:{display:true,text:'Day of Month'}}}}});}
</script>
</body>
</html>
