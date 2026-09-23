<?php
session_start();
include '../includes/config.php';
include '../includes/security.php';
include 'auth.php';

checkAdminAccess();
requirePermission('orders.view');
$admin_info = getAdminInfo($conn);
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

// Get statistics for orders
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders o WHERE o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
$stats_result = mysqli_query($conn, $stats_query);
$order_stats = $stats_result ? (mysqli_fetch_assoc($stats_result) ?: []) : [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'preparing' => 0,
    'delivered' => 0,
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

    $redirect_query = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
    header('Location: orders.php' . $redirect_query);
    exit;
}

// Get filter
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$allowed_filter_statuses = ['pending', 'confirmed', 'preparing', 'delivered', 'cancelled', 'cancellation_requested'];
if ($status_filter !== '' && !in_array($status_filter, $allowed_filter_statuses, true)) {
    $status_filter = '';
}

// Build query
$where_clauses = ["o.is_archived = 0"];
if ($seller_scope_id !== null) {
    $where_clauses[] = $seller_scoped_orders_exists;
}
if ($status_filter) {
    $where_clauses[] = "o.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if ($search) {
    $clean_search = mysqli_real_escape_string($conn, trim($search));
    $id_search = preg_replace('/[^0-9]/', '', $search);
    $id_clause = ($id_search !== '') ? " OR o.id = " . intval($id_search) : "";
    $where_clauses[] = "(o.order_number LIKE '%{$clean_search}%' 
        OR o.customer_name LIKE '%{$clean_search}%' 
        OR o.customer_email LIKE '%{$clean_search}%' 
        OR o.customer_phone LIKE '%{$clean_search}%'
        {$id_clause})";
}
if ($date_from) {
    $where_clauses[] = "DATE(o.created_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if ($date_to) {
    $where_clauses[] = "DATE(o.created_at) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

$where_clause = implode(" AND ", $where_clauses);

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM orders o WHERE $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

$orders_query = "SELECT o.*{$partner_order_amount_sql},
    lt.id AS tracking_id,
    lt.current_status AS tracking_status,
    COALESCE(lt.proof_of_delivery_path, (SELECT photo_path FROM proof_of_delivery WHERE order_id = o.id ORDER BY id DESC LIMIT 1), '') AS proof_photo,
    COALESCE(NULLIF(lt.driver_name, ''), u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), '') AS driver_name,
    r.rider_code
FROM orders o
LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
LEFT JOIN riders r ON lt.driver_id = r.id
LEFT JOIN users u_rdr ON r.user_id = u_rdr.id
LEFT JOIN employees e_rdr ON r.employee_id = e_rdr.id
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
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $order_stats['total'] ?? 0; ?>">0</h3>
                            <p>Total Orders</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon orange">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $order_stats['pending'] ?? 0; ?>">0</h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon cyan">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $order_stats['preparing'] ?? 0; ?>">0</h3>
                            <p>Preparing</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $order_stats['delivered'] ?? 0; ?>">0</h3>
                            <p>Delivered</p>
                        </div>
                    </div>
                </div>

                <div class="section-header">
                    <h2>All Orders</h2>
                    <form method="GET" class="filter-form">
                        <input type="text" name="search" placeholder="Search orders..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>" title="From Date">
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>" title="To Date">
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="preparing" <?php echo $status_filter === 'preparing' ? 'selected' : ''; ?>>Preparing</option>
                            <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancellation_requested" <?php echo $status_filter === 'cancellation_requested' ? 'selected' : ''; ?>>Cancellation Requested</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    </form>
                </div>
                
                <div class="table-responsive fade-in-up">
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
                            if (mysqli_num_rows($orders_result) > 0) {
                                while ($order = mysqli_fetch_assoc($orders_result)) { 
                                    $status_class = match($order['status']) {
                                        'pending' => 'badge-warning',
                                        'confirmed' => 'badge-info',
                                        'preparing' => 'badge-primary',
                                        'delivered' => 'badge-success',
                                        'cancelled', 'failed' => 'badge-danger',
                                        'cancellation_requested' => 'badge-danger',
                                        default => 'badge-secondary'
                                    };
                                    $status_options_html = "<option value=''>Change Status</option>";
                                    $next_statuses = $allowed_order_transitions[$order['status']] ?? [];
                                    $status_select_disabled = empty($next_statuses) ? "disabled" : "";
                                    $scoped_amt = ($seller_scope_id !== null) ? (float)($order['scoped_total_amount'] ?? 0) : 0;
                                    $display_amount = ($scoped_amt > 0) ? $scoped_amt : (float)($order['total_amount'] ?? 0);
                                    foreach ($next_statuses as $next_status) {
                                        $status_options_html .= "<option value='{$next_status}'>" . ucwords(str_replace('_', ' ', $next_status)) . "</option>";
                                    }

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

                    <!-- Pagination -->
                    <div class="pagination-container">
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <?php if ($current_page_number > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number - 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page_number < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number + 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div class="modal fade" id="orderModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetails">
                    <!-- Loaded via JS -->
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
                    document.getElementById('statusForm' + orderId).submit();
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



