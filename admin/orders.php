<?php
session_start();
include '../includes/config.php';
include '../includes/security.php';
include 'auth.php';

checkAdminAccess();
requirePermission('orders.view');
if (function_exists('ensureOrdersTableSchema')) {
    ensureOrdersTableSchema($conn);
}
$admin_info = getAdminInfo($conn) ?: [];
$csrf_token = generateCSRFToken();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$seller_scoped_orders_exists = '';
$partner_order_amount_sql = '';
if ($seller_scope_id !== null) {
    $seller_scoped_orders_exists = getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
    $partner_seller_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
    $partner_order_amount_sql = ",
        (
            SELECT COALESCE(SUM(oi_scope.total), 0)
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (
                    oi_scope.product_id COLLATE utf8mb4_general_ci = p_scope.product_id COLLATE utf8mb4_general_ci
                    OR oi_scope.product_id COLLATE utf8mb4_general_ci = CAST(p_scope.id AS CHAR) COLLATE utf8mb4_general_ci
                    OR CAST(oi_scope.product_id AS UNSIGNED) = p_scope.id
                )
            WHERE oi_scope.order_id = o.id
              AND {$partner_seller_scope_sql}
        ) AS scoped_total_amount";
}

$allowed_order_statuses = ['pending', 'confirmed', 'preparing', 'delivered', 'cancelled'];
$allowed_order_transitions = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['preparing', 'cancelled'],
    'preparing' => ['delivered', 'cancelled'],
    'cancellation_requested' => ['confirmed', 'cancelled'],
    'delivered' => [],
    'cancelled' => []
];

// Pagination settings
$records_per_page = 15;
$current_page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page_number < 1) {
    $current_page_number = 1;
}
$offset = ($current_page_number - 1) * $records_per_page;

$has_archived_col = function_exists('appColumnExists') ? appColumnExists($conn, 'orders', 'is_archived') : adminAuthColumnExists($conn, 'orders', 'is_archived');
$archived_clause = $has_archived_col ? "o.is_archived = 0" : "1=1";

// Get statistics for orders
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN status = 'cancellation_requested' THEN 1 ELSE 0 END) as cancellation_requested,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders o WHERE {$archived_clause}" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
$stats_result = mysqli_query($conn, $stats_query);
$order_stats = ($stats_result instanceof mysqli_result) ? (mysqli_fetch_assoc($stats_result) ?: []) : [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'preparing' => 0,
    'delivered' => 0,
    'cancellation_requested' => 0,
    'cancelled' => 0
];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    requirePermission('orders.edit');

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh the page and try again.';
        $redirect_query = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
        header('Location: orders.php' . $redirect_query);
        exit;
    }

    $order_id = intval($_POST['order_id']);
    $new_status = trim($_POST['new_status']);

    if (in_array($new_status, $allowed_order_statuses, true)) {
        // Get order details including user_id/current status
        $order_query = "SELECT o.id, o.user_id, o.order_number, o.total_amount, o.payment_status, o.status, o.delivery_option
                        FROM orders o
                        WHERE o.id = ?" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
        $order_stmt = mysqli_prepare($conn, $order_query);
        mysqli_stmt_bind_param($order_stmt, "i", $order_id);
        mysqli_stmt_execute($order_stmt);
        $order_result = mysqli_stmt_get_result($order_stmt);
        $order = mysqli_fetch_assoc($order_result);
        mysqli_stmt_close($order_stmt);

        $status_updated = false;
        if (!$order) {
            $_SESSION['error'] = 'Order record not found.';
        } else {
            $current_status = (string)($order['status'] ?? '');
            $allowed_next = $allowed_order_transitions[$current_status] ?? [];

            if ($current_status === $new_status) {
                $_SESSION['success'] = 'Order is already in the selected status.';
            } elseif (!in_array($new_status, $allowed_next, true)) {
                $_SESSION['error'] = 'Invalid status transition from ' . str_replace('_', ' ', $current_status) . ' to ' . str_replace('_', ' ', $new_status) . '.';
            } else {
                mysqli_begin_transaction($conn);
                try {
                    // If admin is cancelling, create/update cancellation + refund records without duplicates.
                    if ($new_status === 'cancelled') {
                        $reason = 'Admin Action';
                        $cancellation_id = 0;

                        $existing_c_stmt = mysqli_prepare($conn, "SELECT id, status FROM cancellations WHERE order_id = ? AND status IN ('Requested','Cancelled') ORDER BY id DESC LIMIT 1");
                        mysqli_stmt_bind_param($existing_c_stmt, "i", $order_id);
                        mysqli_stmt_execute($existing_c_stmt);
                        $existing_c_result = mysqli_stmt_get_result($existing_c_stmt);
                        $existing_cancellation = mysqli_fetch_assoc($existing_c_result);
                        mysqli_stmt_close($existing_c_stmt);

                        if ($existing_cancellation) {
                            $cancellation_id = intval($existing_cancellation['id']);
                            if (($existing_cancellation['status'] ?? '') !== 'Cancelled') {
                                $update_c_stmt = mysqli_prepare($conn, "UPDATE cancellations SET status = 'Cancelled', reason = ?, cancellation_date = NOW(), updated_at = NOW() WHERE id = ?");
                                mysqli_stmt_bind_param($update_c_stmt, "si", $reason, $cancellation_id);
                                mysqli_stmt_execute($update_c_stmt);
                                mysqli_stmt_close($update_c_stmt);
                            }
                        } else {
                            $cancellation_status = 'Cancelled';
                            $c_stmt = mysqli_prepare($conn, "INSERT INTO cancellations (user_id, order_id, reason, status, cancellation_date) VALUES (?, ?, ?, ?, NOW())");
                            mysqli_stmt_bind_param($c_stmt, "iiss", $order['user_id'], $order_id, $reason, $cancellation_status);
                            mysqli_stmt_execute($c_stmt);
                            $cancellation_id = mysqli_insert_id($conn);
                            mysqli_stmt_close($c_stmt);
                        }

                        // Create refund request only when needed and only once per cancellation.
                        if (($order['payment_status'] === 'paid' || $order['payment_status'] === 'partial') && $cancellation_id > 0) {
                            $refund_check_stmt = mysqli_prepare($conn, "SELECT id FROM refunds WHERE cancellation_id = ? LIMIT 1");
                            mysqli_stmt_bind_param($refund_check_stmt, "i", $cancellation_id);
                            mysqli_stmt_execute($refund_check_stmt);
                            $refund_check_result = mysqli_stmt_get_result($refund_check_stmt);
                            $existing_refund = mysqli_fetch_assoc($refund_check_result);
                            mysqli_stmt_close($refund_check_stmt);

                            if (!$existing_refund) {
                                $r_stmt = mysqli_prepare($conn, "INSERT INTO refunds (cancellation_id, refund_amount, refund_status) VALUES (?, ?, 'Refund Pending')");
                                mysqli_stmt_bind_param($r_stmt, "id", $cancellation_id, $order['total_amount']);
                                mysqli_stmt_execute($r_stmt);
                                mysqli_stmt_close($r_stmt);
                            }
                        }
                    }

                    // Update order status
                    $update_query = "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "si", $new_status, $order_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    // Keep logistics in sync for terminal states.
                    if (($order['delivery_option'] ?? '') === 'delivery' && in_array($new_status, ['cancelled', 'delivered'], true)) {
                        $logistics_status = $new_status === 'cancelled' ? 'cancelled' : 'delivered';
                        $sync_stmt = mysqli_prepare($conn, "UPDATE logistics_tracking SET current_status = ?, updated_at = NOW() WHERE order_id = ? AND current_status NOT IN ('delivered','cancelled','failed')");
                        mysqli_stmt_bind_param($sync_stmt, "si", $logistics_status, $order_id);
                        mysqli_stmt_execute($sync_stmt);
                        mysqli_stmt_close($sync_stmt);
                    }

                    // Auto-settle commission and wallet ledger on delivered
                    if ($new_status === 'delivered') {
                        require_once dirname(__DIR__) . '/includes/CommissionAndWalletService.php';
                        $walletService = CommissionAndWalletService::getInstance($conn);
                        $walletService->recordOrUpdateSplit((int)$order_id, true);
                    }

                    mysqli_commit($conn);
                    $status_updated = true;
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = "Error during status update: " . $e->getMessage();
                }
            }
        }

        // Create notification for customer
        if (!empty($status_updated) && $order && isset($order['user_id'])) {
            $status_messages = [
                'confirmed' => 'Your order #' . htmlspecialchars($order['order_number']) . ' has been confirmed and will be prepared soon!',
                'preparing' => 'Your order #' . htmlspecialchars($order['order_number']) . ' is now being prepared.',
                'delivered' => 'Your order #' . htmlspecialchars($order['order_number']) . ' has been delivered. Thank you for your purchase!',
                'cancelled' => 'Your order #' . htmlspecialchars($order['order_number']) . ' has been cancelled.',
                'pending' => 'Your order #' . htmlspecialchars($order['order_number']) . ' is pending confirmation.'
            ];
            
            $status_titles = [
                'confirmed' => 'Order Confirmed',
                'preparing' => 'Order Being Prepared',
                'delivered' => 'Order Delivered',
                'cancelled' => 'Order Cancelled',
                'pending' => 'Order Pending'
            ];
            
            $notif_type = 'order_' . $new_status;
            $notif_title = $status_titles[$new_status] ?? 'Order Status Updated';
            $notif_message = $status_messages[$new_status] ?? 'Your order status has been updated.';
            
            createNotification($conn, $order['user_id'], $notif_type, $notif_title, $notif_message, $order_id, 'order');
        }

        if (!isset($_SESSION['error']) && !isset($_SESSION['success'])) {
            $_SESSION['success'] = "Order status updated successfully.";
        }
    } else {
        $_SESSION['error'] = 'Invalid status selected.';
    }

    $return_query = !empty($_POST['return_query']) ? trim($_POST['return_query']) : (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '');
    $redirect_url = 'orders.php' . ($return_query ? ('?' . ltrim($return_query, '?')) : '');
    header('Location: ' . $redirect_url);
    exit;
}

// Helper to generate preserved filter URLs
function getOrdersFilterUrl($new_params = []) {
    $params = array_merge($_GET, $new_params);
    if (!isset($new_params['page'])) {
        unset($params['page']);
    }
    $filtered = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $filtered[$k] = $v;
        }
    }
    return 'orders.php' . (!empty($filtered) ? ('?' . http_build_query($filtered)) : '');
}

// Get filter inputs
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$delivery_filter = isset($_GET['delivery_option']) ? trim($_GET['delivery_option']) : '';
$payment_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';

$allowed_filter_statuses = ['pending', 'confirmed', 'preparing', 'delivered', 'cancelled', 'cancellation_requested'];
if ($status_filter !== '' && !in_array($status_filter, $allowed_filter_statuses, true)) {
    $status_filter = '';
}

if ($delivery_filter !== '' && !in_array($delivery_filter, ['delivery', 'pickup'], true)) {
    $delivery_filter = '';
}

if ($payment_filter !== '' && !in_array($payment_filter, ['pending', 'paid', 'failed'], true)) {
    $payment_filter = '';
}

// Swap dates if date_from is greater than date_to
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $tmp_date = $date_from;
    $date_from = $date_to;
    $date_to = $tmp_date;
}

// Build query
$where_clauses = [$archived_clause];
if ($seller_scope_id !== null) {
    $where_clauses[] = $seller_scoped_orders_exists;
}
if ($status_filter !== '') {
    $where_clauses[] = "o.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if ($delivery_filter !== '') {
    $where_clauses[] = "o.delivery_option = '" . mysqli_real_escape_string($conn, $delivery_filter) . "'";
}
if ($payment_filter !== '') {
    $where_clauses[] = "o.payment_status = '" . mysqli_real_escape_string($conn, $payment_filter) . "'";
}
if ($search !== '') {
    $clean_search = mysqli_real_escape_string($conn, $search);
    $clean_num = mysqli_real_escape_string($conn, ltrim($search, '#'));
    $id_search = preg_replace('/[^0-9]/', '', $search);
    $id_clause = ($id_search !== '') ? " OR o.id = " . intval($id_search) : "";
    $where_clauses[] = "(o.order_number LIKE '%{$clean_search}%' 
        OR o.order_number LIKE '%{$clean_num}%'
        OR o.customer_name LIKE '%{$clean_search}%' 
        OR o.customer_email LIKE '%{$clean_search}%' 
        OR o.customer_phone LIKE '%{$clean_search}%' 
        OR o.delivery_address LIKE '%{$clean_search}%'
        {$id_clause})";
}
if ($date_from !== '') {
    $where_clauses[] = "DATE(o.created_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if ($date_to !== '') {
    $where_clauses[] = "DATE(o.created_at) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

$where_clause = implode(" AND ", $where_clauses);
$is_filtered = ($status_filter !== '' || $search !== '' || $date_from !== '' || $date_to !== '' || $delivery_filter !== '' || $payment_filter !== '');
$current_query_string = $_SERVER['QUERY_STRING'] ?? '';

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM orders o WHERE $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = 0;
if ($count_result instanceof mysqli_result) {
    $c_row = mysqli_fetch_assoc($count_result);
    $total_records = (int)($c_row['total'] ?? 0);
}
$total_pages = ceil(max(1, $total_records) / $records_per_page);

$has_admin_pod_table = adminAuthTableExists($conn, 'proof_of_delivery');
$proof_photo_expr = $has_admin_pod_table
    ? "COALESCE(lt.proof_of_delivery_path, (SELECT photo_path FROM proof_of_delivery WHERE order_id = o.id ORDER BY id DESC LIMIT 1), '')"
    : "COALESCE(lt.proof_of_delivery_path, '')";

$has_lt = adminAuthTableExists($conn, 'logistics_tracking');
$has_riders = adminAuthTableExists($conn, 'riders');
$has_users = adminAuthTableExists($conn, 'users');
$has_employees = adminAuthTableExists($conn, 'employees');

$join_sql = "";
if ($has_lt) {
    $join_sql .= " LEFT JOIN logistics_tracking lt ON lt.order_id = o.id";
    if ($has_riders) {
        $join_sql .= " LEFT JOIN riders r ON lt.driver_id = r.id";
        if ($has_users) {
            $join_sql .= " LEFT JOIN users u_rdr ON r.user_id = u_rdr.id";
        }
        if ($has_employees) {
            $join_sql .= " LEFT JOIN employees e_rdr ON r.employee_id = e_rdr.id";
        }
    }
}

$driver_name_expr = "''";
if ($has_lt && $has_riders && $has_users && $has_employees) {
    $driver_name_expr = "COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), '')";
} elseif ($has_lt && $has_riders && $has_users) {
    $driver_name_expr = "COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, '')";
} elseif ($has_lt) {
    $driver_name_expr = "COALESCE(NULLIF(lt.driver_name, ''), '')";
}

$tracking_id_expr = $has_lt ? "lt.id" : "NULL";
$tracking_status_expr = $has_lt ? "lt.current_status" : "''";
$rider_code_expr = ($has_lt && $has_riders) ? "r.rider_code" : "''";

$orders_query = "SELECT o.*{$partner_order_amount_sql},
    {$tracking_id_expr} AS tracking_id,
    {$tracking_status_expr} AS tracking_status,
    {$proof_photo_expr} AS proof_photo,
    {$driver_name_expr} AS driver_name,
    {$rider_code_expr} AS rider_code
FROM orders o
{$join_sql}
WHERE $where_clause
ORDER BY o.created_at DESC
LIMIT $records_per_page OFFSET $offset";
$orders_result = mysqli_query($conn, $orders_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
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
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }
        
        body.dark-mode .table-responsive {
             background-color: var(--card-bg-dark) !important;
             border-color: var(--border-color-dark) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination {
            --bs-pagination-color: #c62828;
            --bs-pagination-hover-color: #a71c1c;
            --bs-pagination-active-bg: #c62828;
            --bs-pagination-active-border-color: #c62828;
        }
        body.dark-mode .pagination {
            --bs-pagination-bg: var(--card-bg-dark);
            --bs-pagination-border-color: var(--border-color-dark);
            --bs-pagination-hover-bg: #3d3d3d;
        }

        /* Clickable Stat Cards */
        .stat-card-link {
            text-decoration: none !important;
            color: inherit !important;
            display: block;
        }
        .stat-card-link:hover .stat-card {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
        }
        .stat-card.active-stat {
            border: 2px solid #b3261e !important;
            background: #fff8f8 !important;
        }
        body.dark-mode .stat-card.active-stat {
            border-color: #e53935 !important;
            background: #332222 !important;
        }

        /* Order Filter Panel */
        .order-filter-panel {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }
        body.dark-mode .order-filter-panel {
            background: var(--card-bg-dark);
            border-color: var(--border-color-dark);
        }

        /* Status Pills Navigation */
        .status-pill-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 12px;
            margin-bottom: 16px;
            border-bottom: 1px solid #f2f4f7;
            scrollbar-width: thin;
        }
        body.dark-mode .status-pill-nav {
            border-bottom-color: var(--border-color-dark);
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: #475467;
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            text-decoration: none !important;
            white-space: nowrap;
            transition: all 0.15s ease-in-out;
        }
        .status-pill:hover {
            color: #101828;
            background: #f2f4f7;
            border-color: #d0d5dd;
        }
        .status-pill.active {
            background: #b3261e;
            color: #ffffff !important;
            border-color: #b3261e;
            font-weight: 600;
        }
        .status-pill-count {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 10px;
            background: #eaecf0;
            color: #344054;
        }
        .status-pill.active .status-pill-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        body.dark-mode .status-pill {
            background: #252525;
            border-color: var(--border-color-dark);
            color: #b0b0b0;
        }
        body.dark-mode .status-pill:hover {
            background: #333333;
            color: #ffffff;
        }
        body.dark-mode .status-pill.active {
            background: #b3261e;
            color: #ffffff !important;
            border-color: #b3261e;
        }
        body.dark-mode .status-pill-count {
            background: #3a3a3a;
            color: #e0e0e0;
        }

        /* Filter Controls Row */
        .filter-controls-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        .filter-search-wrap {
            position: relative;
            flex: 1 1 240px;
            min-width: 200px;
        }
        .filter-search-wrap .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #98a2b3;
            font-size: 13px;
            pointer-events: none;
        }
        .filter-search-wrap .filter-control {
            padding-left: 34px !important;
        }
        .filter-control {
            width: 100%;
            height: 38px;
            padding: 6px 12px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-size: 13px;
            color: #101828;
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .filter-control:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
        }
        body.dark-mode .filter-control {
            background: var(--card-bg-dark);
            border-color: var(--border-color-dark);
            color: var(--text-color-dark);
        }
        .filter-date-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-label {
            font-size: 12px;
            font-weight: 500;
            color: #475467;
            white-space: nowrap;
            margin: 0;
        }
        body.dark-mode .filter-label {
            color: #b0b0b0;
        }
        .filter-date-group .filter-control {
            width: 135px;
        }
        .filter-select-group .filter-control {
            width: 145px;
            cursor: pointer;
        }
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }
        .btn-filter-apply {
            height: 38px;
            padding: 0 16px;
            background: #b3261e;
            color: #ffffff;
            border: 1px solid #b3261e;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease-in-out;
        }
        .btn-filter-apply:hover {
            background: #981b15;
            border-color: #981b15;
            color: #ffffff;
        }
        .btn-filter-reset {
            height: 38px;
            padding: 0 14px;
            background: #ffffff;
            color: #344054;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease-in-out;
        }
        .btn-filter-reset:hover {
            background: #f8f9fa;
            border-color: #98a2b3;
            color: #101828;
        }
        body.dark-mode .btn-filter-reset {
            background: #252525;
            border-color: var(--border-color-dark);
            color: #e0e0e0;
        }
        body.dark-mode .btn-filter-reset:hover {
            background: #333333;
        }

        /* Active Filter Chips */
        .active-filter-tags {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px dashed #eaecf0;
        }
        body.dark-mode .active-filter-tags {
            border-top-color: var(--border-color-dark);
        }
        .active-filters-label {
            font-size: 12px;
            font-weight: 600;
            color: #667085;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        body.dark-mode .active-filters-label {
            color: #98a2b3;
        }
        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 12px;
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
        }
        body.dark-mode .filter-tag {
            background: #352323;
            border-color: #552727;
            color: #ff8a80;
        }
        .filter-tag-remove {
            color: #b3261e;
            text-decoration: none !important;
            font-size: 15px;
            line-height: 1;
            font-weight: bold;
            opacity: 0.7;
            transition: opacity 0.15s;
        }
        .filter-tag-remove:hover {
            opacity: 1;
            color: #7f1d1d;
        }
        body.dark-mode .filter-tag-remove {
            color: #ff8a80;
        }
        .filter-tag-clear-all {
            font-size: 12px;
            color: #667085;
            text-decoration: underline;
            margin-left: 4px;
            cursor: pointer;
        }
        .filter-tag-clear-all:hover {
            color: #b3261e;
        }
        body.dark-mode .filter-tag-clear-all {
            color: #98a2b3;
        }
    
        /* Orders Management - Compact Cards View */
        .orders-view-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding: 0 2px;
        }
        .orders-count-indicator {
            font-size: 13px;
            color: #475467;
        }
        body.dark-mode .orders-count-indicator {
            color: #98a2b3;
        }
        .orders-view-switcher {
            display: inline-flex;
            align-items: center;
            background: #f2f4f7;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            padding: 3px;
            gap: 2px;
        }
        body.dark-mode .orders-view-switcher {
            background: #252525;
            border-color: var(--border-color-dark);
        }
        .btn-view-toggle {
            border: none;
            background: transparent;
            color: #667085;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .btn-view-toggle:hover {
            color: #101828;
        }
        .btn-view-toggle.active {
            background: #ffffff;
            color: #101828;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
        }
        body.dark-mode .btn-view-toggle.active {
            background: var(--card-bg-dark);
            color: #f8fafc;
        }

        /* Orders Cards Grid */
        .orders-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 576px) {
            .orders-card-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        .order-item-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s ease, border-color 0.18s ease;
            position: relative;
        }
        .order-item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 24, 40, 0.08);
            border-color: #d0d5dd;
        }
        body.dark-mode .order-item-card {
            background: var(--card-bg-dark);
            border-color: var(--border-color-dark);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        body.dark-mode .order-item-card:hover {
            border-color: #475569;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }

        /* Card Head */
        .order-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f2f4f7;
            margin-bottom: 12px;
        }
        body.dark-mode .order-card-head {
            border-bottom-color: var(--border-color-dark);
        }

        .order-card-num {
            font-size: 14px;
            font-weight: 800;
            color: #101828;
            letter-spacing: -0.2px;
        }
        body.dark-mode .order-card-num {
            color: #f8fafc;
        }

        .order-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            letter-spacing: 0.02em;
        }
        .order-type-badge.delivery {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
        }
        .order-type-badge.pickup {
            background: #eff8ff;
            color: #175cd3;
            border: 1px solid #b2ddff;
        }
        body.dark-mode .order-type-badge.delivery {
            background: rgba(179, 38, 30, 0.2);
            border-color: rgba(179, 38, 30, 0.4);
            color: #ff8a80;
        }
        body.dark-mode .order-type-badge.pickup {
            background: rgba(23, 92, 211, 0.2);
            border-color: rgba(23, 92, 211, 0.4);
            color: #82b1ff;
        }

        /* Card Body */
        .order-card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        .order-customer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .order-customer-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #1d2939;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .order-customer-name i {
            color: #667085;
            font-size: 14px;
        }
        body.dark-mode .order-customer-name {
            color: #f1f5f9;
        }

        .order-customer-phone {
            font-size: 11px;
            font-weight: 600;
            color: #475467;
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            padding: 2px 7px;
            border-radius: 6px;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .order-customer-phone:hover {
            color: #b3261e;
            background: #fffbfa;
            border-color: #fecdca;
        }
        body.dark-mode .order-customer-phone {
            background: #262626;
            border-color: var(--border-color-dark);
            color: #cbd5e1;
        }

        .order-meta-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: #475467;
        }
        body.dark-mode .order-meta-info {
            color: #94a3b8;
        }
        .order-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .order-meta-item i {
            width: 14px;
            text-align: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* Card Summary Block */
        .order-card-summary {
            background: #f8fafc;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        body.dark-mode .order-card-summary {
            background: #182234;
            border-color: var(--border-color-dark);
        }

        .order-summary-label {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #667085;
        }
        body.dark-mode .order-summary-label {
            color: #94a3b8;
        }
        .order-summary-amount {
            font-size: 1.15rem;
            font-weight: 800;
            color: #101828;
            line-height: 1.2;
        }
        body.dark-mode .order-summary-amount {
            color: #f8fafc;
        }

        .order-summary-date {
            font-size: 11px;
            color: #667085;
            display: flex;
            align-items: center;
            gap: 4px;
            justify-content: flex-end;
            margin-bottom: 3px;
        }
        body.dark-mode .order-summary-date {
            color: #94a3b8;
        }

        .order-pay-pill {
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            background: #ffffff;
            border: 1px solid #d0d5dd;
            color: #344054;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        body.dark-mode .order-pay-pill {
            background: #252525;
            border-color: var(--border-color-dark);
            color: #e2e8f0;
        }

        /* Card Actions Footer */
        .order-card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 10px;
            border-top: 1px solid #f2f4f7;
        }
        body.dark-mode .order-card-actions {
            border-top-color: var(--border-color-dark);
        }

        .order-action-buttons {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn-card-action {
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: #344054;
            background: #ffffff;
            border: 1px solid #d0d5dd;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none !important;
        }
        .btn-card-action:hover {
            color: #101828;
            background: #f8f9fa;
            border-color: #98a2b3;
            transform: translateY(-1px);
        }
        body.dark-mode .btn-card-action {
            background: #252525;
            border-color: var(--border-color-dark);
            color: #e0e0e0;
        }
        body.dark-mode .btn-card-action:hover {
            background: #333333;
            color: #ffffff;
        }

        .order-status-wrap {
            min-width: 130px;
            flex: 1 1 auto;
            max-width: 160px;
        }
        .order-card-status-select {
            width: 100%;
            height: 32px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            padding: 2px 8px;
            cursor: pointer;
            background: #ffffff;
            color: #344054;
            transition: border-color 0.15s ease;
        }
        .order-card-status-select:focus {
            border-color: #b3261e;
            outline: none;
            box-shadow: 0 0 0 2px rgba(179, 38, 30, 0.1);
        }
        body.dark-mode .order-card-status-select {
            background: var(--card-bg-dark);
            border-color: var(--border-color-dark);
            color: var(--text-color-dark);
        }
    </style>
</head>
<body class="admin-polish orders-page">
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Orders Management</h1>
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                            <i class="fas fa-moon"></i>
                        </button>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"></div>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name'] ?? ($_SESSION['full_name'] ?? 'Administrator')); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => ''])); ?>" class="stat-card-link" title="Filter by All Orders">
                        <div class="stat-card fade-in-up <?php echo ($status_filter === '') ? 'active-stat' : ''; ?>">
                            <div class="stat-icon blue">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div class="stat-content">
                                <h3 data-count="<?php echo $order_stats['total'] ?? 0; ?>">0</h3>
                                <p>Total Orders</p>
                            </div>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'pending'])); ?>" class="stat-card-link" title="Filter by Pending Orders">
                        <div class="stat-card fade-in-up <?php echo ($status_filter === 'pending') ? 'active-stat' : ''; ?>">
                            <div class="stat-icon orange">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-content">
                                <h3 data-count="<?php echo $order_stats['pending'] ?? 0; ?>">0</h3>
                                <p>Pending</p>
                            </div>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'preparing'])); ?>" class="stat-card-link" title="Filter by Preparing Orders">
                        <div class="stat-card fade-in-up <?php echo ($status_filter === 'preparing') ? 'active-stat' : ''; ?>">
                            <div class="stat-icon cyan">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="stat-content">
                                <h3 data-count="<?php echo $order_stats['preparing'] ?? 0; ?>">0</h3>
                                <p>Preparing</p>
                            </div>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'delivered'])); ?>" class="stat-card-link" title="Filter by Delivered Orders">
                        <div class="stat-card fade-in-up <?php echo ($status_filter === 'delivered') ? 'active-stat' : ''; ?>">
                            <div class="stat-icon green">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 data-count="<?php echo $order_stats['delivered'] ?? 0; ?>">0</h3>
                                <p>Delivered</p>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="order-filter-panel fade-in-up">
                    <!-- Status Pills Navigation -->
                    <div class="status-pill-nav">
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => ''])); ?>" class="status-pill <?php echo ($status_filter === '') ? 'active' : ''; ?>">
                            <span>All Orders</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['total'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'pending'])); ?>" class="status-pill <?php echo ($status_filter === 'pending') ? 'active' : ''; ?>">
                            <span>Pending</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['pending'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'confirmed'])); ?>" class="status-pill <?php echo ($status_filter === 'confirmed') ? 'active' : ''; ?>">
                            <span>Confirmed</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['confirmed'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'preparing'])); ?>" class="status-pill <?php echo ($status_filter === 'preparing') ? 'active' : ''; ?>">
                            <span>Preparing</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['preparing'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'delivered'])); ?>" class="status-pill <?php echo ($status_filter === 'delivered') ? 'active' : ''; ?>">
                            <span>Delivered</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['delivered'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'cancellation_requested'])); ?>" class="status-pill <?php echo ($status_filter === 'cancellation_requested') ? 'active' : ''; ?>">
                            <span>Cancellation Requested</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['cancellation_requested'] ?? 0); ?></span>
                        </a>
                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => 'cancelled'])); ?>" class="status-pill <?php echo ($status_filter === 'cancelled') ? 'active' : ''; ?>">
                            <span>Cancelled</span>
                            <span class="status-pill-count"><?php echo (int)($order_stats['cancelled'] ?? 0); ?></span>
                        </a>
                    </div>

                    <!-- Filter Form -->
                    <form method="GET" action="orders.php" class="order-filter-form">
                        <?php if ($status_filter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <?php endif; ?>

                        <div class="filter-controls-row">
                            <div class="filter-search-wrap">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" placeholder="Search order #, customer, phone, address..." value="<?php echo htmlspecialchars($search); ?>" class="filter-control search-input">
                            </div>

                            <div class="filter-date-group">
                                <label class="filter-label">From:</label>
                                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="filter-control date-input" title="From Date">
                            </div>

                            <div class="filter-date-group">
                                <label class="filter-label">To:</label>
                                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="filter-control date-input" title="To Date">
                            </div>

                            <div class="filter-select-group">
                                <select name="delivery_option" class="filter-control filter-select">
                                    <option value="">All Delivery</option>
                                    <option value="delivery" <?php echo ($delivery_filter === 'delivery') ? 'selected' : ''; ?>>Delivery</option>
                                    <option value="pickup" <?php echo ($delivery_filter === 'pickup') ? 'selected' : ''; ?>>Pickup</option>
                                </select>
                            </div>

                            <div class="filter-select-group">
                                <select name="payment_status" class="filter-control filter-select">
                                    <option value="">All Payments</option>
                                    <option value="paid" <?php echo ($payment_filter === 'paid') ? 'selected' : ''; ?>>Paid</option>
                                    <option value="pending" <?php echo ($payment_filter === 'pending') ? 'selected' : ''; ?>>Pending Payment</option>
                                    <option value="failed" <?php echo ($payment_filter === 'failed') ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn-filter-apply"><i class="fas fa-filter"></i> Apply</button>
                                <?php if ($is_filtered): ?>
                                    <a href="orders.php" class="btn-filter-reset" title="Clear all filters"><i class="fas fa-times-circle"></i> Clear</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_filtered): ?>
                            <div class="active-filter-tags">
                                <span class="active-filters-label"><i class="fas fa-sliders-h"></i> Active Filters:</span>
                                <?php if ($status_filter !== ''): ?>
                                    <span class="filter-tag">
                                        Status: <strong><?php echo ucwords(str_replace('_', ' ', $status_filter)); ?></strong>
                                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['status' => ''])); ?>" class="filter-tag-remove" title="Remove status filter">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($search !== ''): ?>
                                    <span class="filter-tag">
                                        Search: <strong>"<?php echo htmlspecialchars($search); ?>"</strong>
                                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['search' => ''])); ?>" class="filter-tag-remove" title="Remove search filter">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($date_from !== '' || $date_to !== ''): ?>
                                    <span class="filter-tag">
                                        Date: <strong><?php echo htmlspecialchars($date_from ?: 'Any'); ?> &rarr; <?php echo htmlspecialchars($date_to ?: 'Present'); ?></strong>
                                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['date_from' => '', 'date_to' => ''])); ?>" class="filter-tag-remove" title="Remove date filter">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($delivery_filter !== ''): ?>
                                    <span class="filter-tag">
                                        Delivery: <strong><?php echo ucfirst($delivery_filter); ?></strong>
                                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['delivery_option' => ''])); ?>" class="filter-tag-remove" title="Remove delivery filter">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($payment_filter !== ''): ?>
                                    <span class="filter-tag">
                                        Payment: <strong><?php echo ucfirst($payment_filter); ?></strong>
                                        <a href="<?php echo htmlspecialchars(getOrdersFilterUrl(['payment_status' => ''])); ?>" class="filter-tag-remove" title="Remove payment filter">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <a href="orders.php" class="filter-tag-clear-all">Reset All</a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                                <?php
                // Fetch all orders into array for dual Card and Table views
                $orders_list = [];
                if ($orders_result instanceof mysqli_result && mysqli_num_rows($orders_result) > 0) {
                    while ($order_row = mysqli_fetch_assoc($orders_result)) {
                        if (function_exists('fixMojibakeText')) {
                            if (isset($order_row['delivery_address'])) $order_row['delivery_address'] = fixMojibakeText($order_row['delivery_address']);
                            if (isset($order_row['customer_name'])) $order_row['customer_name'] = fixMojibakeText($order_row['customer_name']);
                        }
                        $orders_list[] = $order_row;
                    }
                }
                ?>

                <!-- View Switcher Toolbar -->
                <div class="orders-view-toolbar">
                    <div class="orders-count-indicator">
                        Showing <strong><?php echo count($orders_list); ?></strong> of <strong><?php echo (int)$total_records; ?></strong> orders
                    </div>
                    <div class="orders-view-switcher">
                        <button type="button" class="btn-view-toggle active" id="viewToggleCard" onclick="switchOrdersView('card')" title="Card View">
                            <i class="fas fa-th-large"></i> <span>Cards</span>
                        </button>
                        <button type="button" class="btn-view-toggle" id="viewToggleTable" onclick="switchOrdersView('table')" title="Table View">
                            <i class="fas fa-table-list"></i> <span>Table</span>
                        </button>
                    </div>
                </div>

                <!-- 1. COMPACT CARD VIEW (Default) -->
                <div id="ordersCardViewContainer" class="orders-card-grid fade-in-up">
                    <?php if (!empty($orders_list)): ?>
                        <?php foreach ($orders_list as $order): 
                            $status_class = match($order['status']) {
                                'pending' => 'badge-warning',
                                'confirmed' => 'badge-info',
                                'preparing' => 'badge-primary',
                                'delivered' => 'badge-success',
                                'cancelled', 'failed' => 'badge-danger',
                                'cancellation_requested' => 'badge-danger',
                                default => 'badge-secondary'
                            };
                            $next_statuses = $allowed_order_transitions[$order['status']] ?? [];
                            $status_select_disabled = empty($next_statuses) ? "disabled" : "";
                            $status_options_html = "<option value=''>Change Status</option>";
                            foreach ($next_statuses as $next_status) {
                                $status_options_html .= "<option value='{$next_status}'>" . ucwords(str_replace('_', ' ', $next_status)) . "</option>";
                            }

                            $scoped_amt = ($seller_scope_id !== null) ? (float)($order['scoped_total_amount'] ?? 0) : 0;
                            $display_amount = ($scoped_amt > 0) ? $scoped_amt : (float)($order['total_amount'] ?? 0);
                            $is_delivery = ($order['delivery_option'] === 'delivery');
                            $tracking_status = $order['tracking_status'] ?? '';
                            $phone_clean = preg_replace('/[^0-9+]/', '', (string)($order['customer_phone'] ?? ''));

                            $handover_btn = '';
                            if ($is_delivery) {
                                if (in_array($tracking_status, ['picked_up', 'on_the_way', 'arriving', 'delivered'])) {
                                    $handover_btn = "<span class='badge' style='background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:11px;' title='Food handed over to rider'><i class='fas fa-check'></i> Handed Over</span>";
                                } elseif ($tracking_status === 'arrived_at_restaurant') {
                                    $handover_btn = "<button class='btn btn-sm btn-success fw-bold' type='button' style='font-size:11.5px; padding:3px 8px; border-radius:6px;' onclick=\"approveRiderHandover({$order['id']})\" title='Rider is at store! Approve handover now'>
                                        <i class='fas fa-check'></i> Approve
                                    </button>
                                    <button class='btn btn-sm btn-outline-danger fw-bold' type='button' style='font-size:11.5px; padding:3px 8px; border-radius:6px;' onclick=\"rejectRiderHandover({$order['id']})\" title='Reject or pause handover'>
                                        <i class='fas fa-times'></i> Reject
                                    </button>";
                                } elseif (!in_array($order['status'], ['delivered', 'cancelled', 'failed'])) {
                                    $safe_ord_num = htmlspecialchars(addslashes($order['order_number']));
                                    $safe_pin = htmlspecialchars(addslashes($order['delivery_pin'] ?? ''));
                                    $safe_driver = htmlspecialchars(addslashes(!empty($order['driver_name']) ? $order['driver_name'] : 'Assigned Rider'));
                                    $safe_rider_code = htmlspecialchars(addslashes($order['rider_code'] ?? ''));
                                    $handover_btn = "<button class='btn btn-sm btn-outline-success' type='button' style='font-size:11.5px; padding:3px 8px; border-radius:6px; font-weight:600;' onclick=\"openHandoverModal({$order['id']}, '{$safe_ord_num}', '{$safe_pin}', '{$safe_driver}', '{$safe_rider_code}')\" title='Enter Handover Confirmation Code'>
                                        <i class='fas fa-handshake'></i> Handover
                                    </button>";
                                }
                            }

                            $proof_btn = '';
                            if (!empty($order['proof_photo'])) {
                                $safe_proof = htmlspecialchars(addslashes($order['proof_photo']));
                                $proof_btn = "<button class='btn-card-action text-success' type='button' onclick=\"viewProofModal('{$safe_proof}')\" title='View Proof of Delivery Photo'>
                                    <i class='fas fa-camera'></i> Proof
                                </button>";
                            }
                        ?>
                            <div class="order-item-card" data-order-id="<?php echo $order['id']; ?>">
                                <!-- Card Header -->
                                <div class="order-card-head">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="order-card-num">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                                        <span class="order-type-badge <?php echo $is_delivery ? 'delivery' : 'pickup'; ?>">
                                            <i class="fas <?php echo $is_delivery ? 'fa-motorcycle' : 'fa-store'; ?>"></i>
                                            <?php echo ucfirst($order['delivery_option']); ?>
                                        </span>
                                    </div>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $order['status'])); ?>
                                    </span>
                                </div>

                                <!-- Card Body: Customer & Routing -->
                                <div class="order-card-body">
                                    <div class="order-customer-row">
                                        <div class="order-customer-name" title="<?php echo htmlspecialchars($order['customer_name']); ?>">
                                            <i class="fas fa-user-circle"></i>
                                            <span class="text-truncate"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                        </div>
                                        <?php if (!empty($order['customer_phone']) && $order['customer_phone'] !== 'N/A'): ?>
                                            <a href="tel:<?php echo htmlspecialchars($phone_clean); ?>" class="order-customer-phone" title="Call Customer">
                                                <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($order['customer_phone']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="order-meta-info">
                                        <?php if (!empty($order['customer_email'])): ?>
                                            <div class="order-meta-item text-truncate" title="<?php echo htmlspecialchars($order['customer_email']); ?>">
                                                <i class="far fa-envelope text-muted"></i>
                                                <span class="text-truncate"><?php echo htmlspecialchars($order['customer_email']); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_delivery && !empty($order['delivery_address'])): ?>
                                            <div class="order-meta-item text-truncate" title="<?php echo htmlspecialchars($order['delivery_address']); ?>">
                                                <i class="fas fa-map-marker-alt text-danger"></i>
                                                <span class="text-truncate"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_delivery && !empty($order['driver_name'])): ?>
                                            <div class="order-meta-item text-truncate" style="color: #175cd3;">
                                                <i class="fas fa-biking"></i>
                                                <span class="text-truncate">Rider: <strong><?php echo htmlspecialchars($order['driver_name']); ?></strong><?php echo !empty($order['rider_code']) ? ' (' . htmlspecialchars($order['rider_code']) . ')' : ''; ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Card Summary (Amount + Date + Payment) -->
                                <div class="order-card-summary">
                                    <div>
                                        <span class="order-summary-label">Total Amount</span>
                                        <div class="order-summary-amount">&#8369;<?php echo number_format($display_amount, 2); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="order-summary-date">
                                            <i class="far fa-clock"></i>
                                            <span><?php echo date('M d, Y • h:i A', strtotime($order['created_at'])); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-end gap-1 mt-1">
                                            <span class="order-pay-pill">
                                                <?php echo strtoupper(htmlspecialchars($order['payment_method'] ?? 'COD')); ?>
                                                <?php if (!empty($order['payment_status']) && in_array(strtolower($order['payment_status']), ['paid', 'completed'], true)): ?>
                                                    <span class="text-success"><i class="fas fa-check-circle"></i> Paid</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Actions -->
                                <div class="order-card-actions">
                                    <div class="order-action-buttons">
                                        <?php echo $handover_btn; ?>
                                        <?php echo $proof_btn; ?>
                                        <button type="button" class="btn-card-action" data-bs-toggle="modal" data-bs-target="#orderModal" onclick="loadOrderDetails(<?php echo $order['id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i> Details
                                        </button>
                                        <button type="button" class="btn-card-action" onclick="window.open('print_order_receipt.php?id=<?php echo $order['id']; ?>&print=1', '_blank')" title="Print Receipt">
                                            <i class="fas fa-print"></i> Print
                                        </button>
                                    </div>

                                    <?php if (!empty($next_statuses)): ?>
                                        <div class="order-status-wrap">
                                            <form method="POST" id="statusFormCard<?php echo $order['id']; ?>" class="m-0">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($current_query_string, ENT_QUOTES); ?>">
                                                <select name="new_status" class="order-card-status-select" onchange="confirmStatusChange(this, <?php echo $order['id']; ?>)">
                                                    <?php echo $status_options_html; ?>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5 w-100" style="grid-column: 1 / -1; background: #ffffff; border: 1px dashed #eaecf0; border-radius: 14px;">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: #f8f9fa; color: #98a2b3; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 10px;">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h6 class="fw-bold mb-1" style="color: #101828;">No orders found</h6>
                            <p class="text-muted small mb-0">Try adjusting your filters, date range, or search keywords.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 2. TRADITIONAL TABLE VIEW (Optional Toggle) -->
                <div id="ordersTableViewContainer" class="table-responsive fade-in-up" style="display: none;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Delivery</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!empty($orders_list)) {
                                foreach ($orders_list as $order) { 
                                    $status_class = match($order['status']) {
                                        'pending' => 'badge-warning',
                                        'confirmed' => 'badge-info',
                                        'preparing' => 'badge-primary',
                                        'delivered' => 'badge-success',
                                        'cancelled', 'failed' => 'badge-danger',
                                        'cancellation_requested' => 'badge-danger',
                                        default => 'badge-secondary'
                                    };
                                    $next_statuses = $allowed_order_transitions[$order['status']] ?? [];
                                    $status_select_disabled = empty($next_statuses) ? "disabled" : "";
                                    $status_options_html = "<option value=''>Change Status</option>";
                                    foreach ($next_statuses as $next_status) {
                                        $status_options_html .= "<option value='{$next_status}'>" . ucwords(str_replace('_', ' ', $next_status)) . "</option>";
                                    }

                                    $scoped_amt = ($seller_scope_id !== null) ? (float)($order['scoped_total_amount'] ?? 0) : 0;
                                    $display_amount = ($scoped_amt > 0) ? $scoped_amt : (float)($order['total_amount'] ?? 0);
                                    $is_delivery = ($order['delivery_option'] === 'delivery');
                                    $tracking_status = $order['tracking_status'] ?? '';

                                    $handover_btn = '';
                                    if ($is_delivery) {
                                        if (in_array($tracking_status, ['picked_up', 'on_the_way', 'arriving', 'delivered'])) {
                                            $handover_btn = "<span class='badge' style='background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:11px; margin-right:4px;' title='Food handed over to rider'><i class='fas fa-check'></i> Handed Over</span>";
                                        } elseif ($tracking_status === 'arrived_at_restaurant') {
                                            $handover_btn = "<button class='btn btn-sm btn-success fw-bold' type='button' style='font-size:11.5px; padding:3px 8px; margin-right:4px; border-radius:6px;' onclick=\"approveRiderHandover({$order['id']})\" title='Rider is at store! Approve handover now'>
                                                <i class='fas fa-check'></i> Approve
                                            </button>
                                            <button class='btn btn-sm btn-outline-danger fw-bold' type='button' style='font-size:11.5px; padding:3px 8px; margin-right:4px; border-radius:6px;' onclick=\"rejectRiderHandover({$order['id']})\" title='Reject or pause handover'>
                                                <i class='fas fa-times'></i> Reject
                                            </button>";
                                        } elseif (!in_array($order['status'], ['delivered', 'cancelled', 'failed'])) {
                                            $safe_ord_num = htmlspecialchars(addslashes($order['order_number']));
                                            $safe_pin = htmlspecialchars(addslashes($order['delivery_pin'] ?? ''));
                                            $safe_driver = htmlspecialchars(addslashes(!empty($order['driver_name']) ? $order['driver_name'] : 'Assigned Rider'));
                                            $safe_rider_code = htmlspecialchars(addslashes($order['rider_code'] ?? ''));
                                            $handover_btn = "<button class='btn btn-sm btn-outline-success' type='button' style='font-size:11.5px; padding:3px 8px; margin-right:4px; border-radius:6px; font-weight:600;' onclick=\"openHandoverModal({$order['id']}, '{$safe_ord_num}', '{$safe_pin}', '{$safe_driver}', '{$safe_rider_code}')\" title='Enter Handover Confirmation Code'>
                                                <i class='fas fa-handshake'></i> Handover Code
                                            </button>";
                                        }
                                    }

                                    $proof_btn = '';
                                    if (!empty($order['proof_photo'])) {
                                        $safe_proof = htmlspecialchars(addslashes($order['proof_photo']));
                                        $proof_btn = "<button class='btn-icon' type='button' style='color:#027a48; margin-right:4px;' onclick=\"viewProofModal('{$safe_proof}')\" title='View Proof of Delivery Photo'>
                                            <i class='fas fa-camera'></i>
                                        </button>";
                                    }

                                    echo "
                                    <tr>
                                        <td><strong>{$order['order_number']}</strong></td>
                                        <td>{$order['customer_name']}</td>
                                        <td>{$order['customer_email']}</td>
                                        <td>&#8369;" . number_format($display_amount, 2) . "</td>
                                        <td><span class='status-badge $status_class'>" . ucwords(str_replace('_', ' ', $order['status'])) . "</span></td>
                                        <td>" . ucfirst($order['delivery_option']) . "</td>
                                        <td>" . date('M d, Y', strtotime($order['created_at'])) . "</td>
                                        <td>
                                            {$handover_btn}
                                            {$proof_btn}
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#orderModal' onclick='loadOrderDetails({$order['id']})' title='View Details'>
                                                <i class='fas fa-eye'></i>
                                            </button>
                                            <button class='btn-icon' type='button' onclick='window.open(&quot;print_order_receipt.php?id={$order['id']}&print=1&quot;, &quot;_blank&quot;)' title='Print Receipt'>
                                                <i class='fas fa-print'></i>
                                            </button>
                                            <form method='POST' id='statusForm{$order['id']}' style='display:inline;'>
                                                <input type='hidden' name='order_id' value='{$order['id']}'>
                                                <input type='hidden' name='csrf_token' value='{$csrf_token}'>
                                                <input type='hidden' name='return_query' value='" . htmlspecialchars($current_query_string, ENT_QUOTES) . "'>
                                                <select name='new_status' class='form-select-sm' {$status_select_disabled} onchange='confirmStatusChange(this, {$order['id']})'>
                                                    {$status_options_html}
                                                </select>
                                                <input type='hidden' name='update_status' value='1'>
                                            </form>
                                        </td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='8' class='text-center text-muted'>No orders found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
<!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-container">
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <?php if ($current_page_number > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars(getOrdersFilterUrl(['page' => $current_page_number - 1])); ?>">&laquo; Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(getOrdersFilterUrl(['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page_number < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars(getOrdersFilterUrl(['page' => $current_page_number + 1])); ?>">Next &raquo;</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div class="modal fade modern-form-modal" id="orderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <h5 class="modal-title">Order Details</h5>
                        <p class="modal-subtitle">Customer information, dispatch status, line items, and totals.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="orderDetails">
                    <!-- Loaded via JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pickup Handover Verification Modal -->
    <div class="modal fade" id="pickupHandoverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid #eaecf0; box-shadow: 0 10px 25px rgba(0,0,0,0.08); overflow: hidden;">
                <div class="modal-header border-bottom py-3" style="background: #ffffff;">
                    <h5 class="modal-title fw-bold mb-0" style="color: #101828; font-size: 1.05rem;">
                        <i class="fas fa-handshake text-success me-2"></i> Confirm Rider Pickup Handover
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="handoverOrderId" value="">

                    <div class="p-3 rounded mb-3" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small">Order Number:</span>
                            <strong class="text-dark" id="modalHandoverOrderNum"></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small">Assigned Rider:</span>
                            <span class="fw-semibold text-dark" id="modalHandoverRiderName">Assigned Rider</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">Order Delivery PIN:</span>
                            <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size: 13px;" id="modalHandoverPinBadge">----</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Enter Confirmation Code or Delivery PIN
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-shield-alt text-muted"></i></span>
                            <input type="text" id="handoverCodeInput" class="form-control form-control-lg fw-bold text-center" placeholder="e.g. 7794 or RDR-0011" style="letter-spacing: 2px;">
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted" style="font-size: 11.5px;">Ask the rider for their PIN or Rider ID code.</small>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" style="font-size: 11.5px; color: #b3261e;" onclick="autofillExpectedPin()">
                                <i class="fas fa-magic me-1"></i> Auto-fill PIN
                            </button>
                        </div>
                    </div>

                    <button type="button" class="btn w-100 py-2 fw-bold text-white" id="confirmHandoverBtn" style="background: #027a48; border-color: #027a48; border-radius: 8px;" onclick="submitPickupHandover()">
                        <i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['error']; ?>'
            });
        <?php unset($_SESSION['error']); endif; ?>

        function loadOrderDetails(orderId) {
            $.ajax({
                url: 'get_order_details.php',
                type: 'GET',
                data: { id: orderId },
                success: function(response) {
                    $('#orderDetails').html(response);
                }
            });
        }

        let currentExpectedPin = '';

        function openHandoverModal(orderId, orderNum, pin, riderName, riderCode) {
            document.getElementById('handoverOrderId').value = orderId;
            document.getElementById('modalHandoverOrderNum').textContent = '#' + orderNum;
            
            let riderLabel = riderName || 'Assigned Rider';
            if (riderCode) {
                riderLabel += ' (' + riderCode + ')';
            }
            document.getElementById('modalHandoverRiderName').textContent = riderLabel;
            
            currentExpectedPin = pin || '';
            document.getElementById('modalHandoverPinBadge').textContent = pin ? pin : 'None';
            
            const input = document.getElementById('handoverCodeInput');
            input.value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('pickupHandoverModal'));
            modal.show();
            setTimeout(() => input.focus(), 400);
        }

        function autofillExpectedPin() {
            if (currentExpectedPin) {
                document.getElementById('handoverCodeInput').value = currentExpectedPin;
            }
        }

        function submitPickupHandover() {
            const orderId = document.getElementById('handoverOrderId').value;
            const code = document.getElementById('handoverCodeInput').value.trim();
            const btn = document.getElementById('confirmHandoverBtn');
            
            if (!code) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Code Required',
                    text: 'Please enter the delivery PIN, rider code, or order number to verify handover.'
                });
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Verifying...';
            
            const formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('verification_code', code);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            
            fetch('ajax_confirm_pickup.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider';
                
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('pickupHandoverModal')).hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Pickup Confirmed!',
                        text: data.message,
                        confirmButtonColor: '#027a48'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Verification Failed',
                        text: data.message || 'Invalid verification code.'
                    });
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Confirm Handover & Dispatch Rider';
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Unable to communicate with the server. Please try again.'
                });
            });
        }

        function submitDetailHandover(orderId) {
            const input = document.getElementById('detailHandoverCode');
            const code = input ? input.value.trim() : '';
            if (!code) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Code Required',
                    text: 'Please enter the verification code or delivery PIN.'
                });
                return;
            }
            
            const formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('verification_code', code);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            
            fetch('ajax_confirm_pickup.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const modalEl = document.getElementById('orderModal');
                        const modalInstance = bootstrap.Modal.getInstance(modalEl);
                        if (modalInstance) modalInstance.hide();
                        Swal.fire({
                            icon: 'success',
                            title: 'Pickup Confirmed!',
                            text: data.message,
                            confirmButtonColor: '#027a48'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Verification Failed',
                            text: data.message || 'Invalid verification code.'
                        });
                    }
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Failed to connect to server.'
                    });
                });
        }

                function switchOrdersView(view) {
            const cardContainer = document.getElementById('ordersCardViewContainer');
            const tableContainer = document.getElementById('ordersTableViewContainer');
            const btnCard = document.getElementById('viewToggleCard');
            const btnTable = document.getElementById('viewToggleTable');

            if (view === 'table') {
                if (cardContainer) cardContainer.style.display = 'none';
                if (tableContainer) tableContainer.style.display = 'block';
                if (btnCard) btnCard.classList.remove('active');
                if (btnTable) btnTable.classList.add('active');
                try { localStorage.setItem('admin_orders_view', 'table'); } catch(e){}
            } else {
                if (cardContainer) cardContainer.style.display = 'grid';
                if (tableContainer) tableContainer.style.display = 'none';
                if (btnCard) btnCard.classList.add('active');
                if (btnTable) btnTable.classList.remove('active');
                try { localStorage.setItem('admin_orders_view', 'card'); } catch(e){}
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            let savedView = 'card';
            try {
                savedView = localStorage.getItem('admin_orders_view') || 'card';
            } catch(e){}
            switchOrdersView(savedView);
        });

        function confirmStatusChange(selectElement, orderId) {
            if (!selectElement.value) return;
            
            Swal.fire({
                title: 'Update Order Status?',
                text: "Change status to " + selectElement.value + "? Customer will be notified.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, update it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    if (selectElement.form) { selectElement.form.submit(); } else if (document.getElementById('statusForm' + orderId)) { document.getElementById('statusForm' + orderId).submit(); }
                } else {
                    selectElement.value = ''; // Reset selection
                }
            })
        }

        function approveRiderHandover(orderId) {
            Swal.fire({
                title: 'Approve Rider Handover?',
                text: 'Confirm that you are handing over the package to the arrived rider. The rider will be cleared to proceed to the customer.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#027a48',
                cancelButtonColor: '#667085',
                confirmButtonText: '<i class="fas fa-check"></i> Yes, Approve & Dispatch',
                cancelButtonText: 'Cancel'
            }).then((res) => {
                if (!res.isConfirmed) return;

                const formData = new FormData();
                formData.append('order_id', orderId);
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');

                fetch('ajax_handover_action.php?action=approve', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Handover Approved!',
                            text: data.message,
                            confirmButtonColor: '#027a48'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Action Failed',
                            text: data.message || 'Failed to approve handover.'
                        });
                    }
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Could not connect to server.'
                    });
                });
            });
        }

        function rejectRiderHandover(orderId) {
            Swal.fire({
                title: 'Reject / Pause Handover?',
                text: 'Provide a reason for the rider (e.g. food still cooking, missing item):',
                input: 'text',
                inputPlaceholder: 'e.g. Order still cooking, please wait 5-10 minutes',
                inputValue: 'Order is still being prepared. Please wait 5-10 minutes.',
                showCancelButton: true,
                confirmButtonColor: '#b3261e',
                cancelButtonColor: '#667085',
                confirmButtonText: 'Submit Rejection / Note',
                cancelButtonText: 'Cancel',
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'Please enter a brief note for the rider.';
                    }
                }
            }).then((res) => {
                if (!res.isConfirmed) return;

                const formData = new FormData();
                formData.append('order_id', orderId);
                formData.append('reason', res.value.trim());
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');

                fetch('ajax_handover_action.php?action=reject', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Rider Notified',
                            text: data.message,
                            confirmButtonColor: '#b3261e'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Action Failed',
                            text: data.message || 'Failed to submit rejection.'
                        });
                    }
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Network Error',
                        text: 'Could not connect to server.'
                    });
                });
            });
        }

        function viewProofModal(imageUrl) {
            if (!imageUrl) return;
            let cleanUrl = imageUrl;
            if (!cleanUrl.startsWith('http') && !cleanUrl.startsWith('/')) {
                const fname = cleanUrl.split('/').pop();
                cleanUrl = '../uploads/proof_of_delivery/' + fname;
            }
            Swal.fire({
                title: 'Proof of Delivery Photo',
                html: `<div style="text-align:center;">
                    <img src="${cleanUrl}" alt="Proof of Delivery" style="max-height: 400px; max-width: 100%; border-radius: 8px; border: 1px solid #eaecf0; margin-bottom: 8px;" class="img-fluid" onerror="this.onerror=null;this.src='../assets/images/promo_lechon.jpg';">
                    <div style="font-size: 12px; color: #667085;"><i class="fas fa-camera me-1"></i> Captured by rider upon delivery to customer.</div>
                </div>`,
                showCloseButton: true,
                showConfirmButton: true,
                confirmButtonText: '<i class="fas fa-external-link-alt me-1"></i> Open Original',
                confirmButtonColor: '#b3261e',
                showCancelButton: true,
                cancelButtonText: 'Close'
            }).then((res) => {
                if (res.isConfirmed) {
                    window.open(cleanUrl, '_blank');
                }
            });
        }

        // Real-time polling for riders arriving at store
        let lastPromptedArrivalOrder = null;
        function checkRiderArrivals() {
            fetch('ajax_handover_action.php?action=check_arrivals')
                .then(r => r.json())
                .then(data => {
                    if (data && data.success && data.has_arrivals && data.arrivals.length > 0) {
                        const firstArrival = data.arrivals[0];
                        if (lastPromptedArrivalOrder !== firstArrival.order_id && !Swal.isVisible()) {
                            lastPromptedArrivalOrder = firstArrival.order_id;
                            Swal.fire({
                                title: 'Rider Arrived at Store!',
                                html: `<div style="text-align:left; font-size:14px; line-height:1.6;">
                                    <div><strong>Order:</strong> #${escapeHtmlAdmin(firstArrival.order_number)}</div>
                                    <div><strong>Rider:</strong> ${escapeHtmlAdmin(firstArrival.driver_name)}</div>
                                    <div><strong>Phone:</strong> ${escapeHtmlAdmin(firstArrival.driver_phone || 'N/A')}</div>
                                    <div class="mt-2 text-muted">The rider is at your branch waiting for the food package. Ready for handover?</div>
                                </div>`,
                                icon: 'info',
                                showCancelButton: true,
                                confirmButtonColor: '#027a48',
                                cancelButtonColor: '#b3261e',
                                confirmButtonText: '<i class="fas fa-check-circle me-1"></i> Approve Handover',
                                cancelButtonText: '<i class="fas fa-times-circle me-1"></i> Reject / Wait',
                                allowOutsideClick: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    approveRiderHandover(firstArrival.order_id);
                                } else if (result.dismiss === Swal.DismissReason.cancel) {
                                    rejectRiderHandover(firstArrival.order_id);
                                }
                            });
                        }
                    }
                })
                .catch(() => {});
        }

        function escapeHtmlAdmin(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        setInterval(checkRiderArrivals, 6000);
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(checkRiderArrivals, 2000);
        });
    </script>
</body>
</html>



