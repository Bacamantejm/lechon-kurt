<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/security.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once '../preorder_service.php';
requirePermission('preorders.view');

$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$allowed_preorder_statuses = ['pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled'];
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
}

// Handle AJAX Calendar Data Request
if (isset($_GET['ajax_calendar'])) {
    header('Content-Type: application/json');
    $req_month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
    $cal_status = isset($_GET['cal_status']) ? trim($_GET['cal_status']) : 'all';
    
    $start_date = $req_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $cal_where = ["po.preferred_pickup_date BETWEEN '{$start_date}' AND '{$end_date}'"];
    if ($cal_status !== 'all' && in_array($cal_status, $allowed_preorder_statuses, true)) {
        $escaped_status = mysqli_real_escape_string($conn, $cal_status);
        $cal_where[] = "po.reservation_status = '{$escaped_status}'";
    }
    
    $cal_where_sql = "WHERE " . implode(' AND ', $cal_where);
    
    $cal_query = "SELECT po.id, po.product_name, po.quantity, po.total_price, po.reservation_status, 
                         po.preferred_pickup_date, po.preferred_pickup_time, po.payment_type,
                         u.full_name, u.phone, u.email
                  FROM pre_orders po
                  JOIN users u ON po.user_id = u.id" .
                  ($seller_scope_id !== null ? " JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "") . "
                  $cal_where_sql
                  ORDER BY po.preferred_pickup_date ASC, po.preferred_pickup_time ASC, po.id ASC";
                  
    $cal_res = mysqli_query($conn, $cal_query);
    $events = [];
    $total_count = 0;
    
    if ($cal_res) {
        while ($row = mysqli_fetch_assoc($cal_res)) {
            $date_key = $row['preferred_pickup_date'];
            if (!$date_key || $date_key === '0000-00-00') {
                continue;
            }
            if (!isset($events[$date_key])) {
                $events[$date_key] = [];
            }
            
            $formatted_time = !empty($row['preferred_pickup_time']) ? date('g:i A', strtotime($row['preferred_pickup_time'])) : 'Anytime';
            
            $events[$date_key][] = [
                'id' => (int)$row['id'],
                'customer_name' => $row['full_name'],
                'customer_phone' => $row['phone'] ?? '',
                'customer_email' => $row['email'] ?? '',
                'product_name' => $row['product_name'],
                'quantity' => (int)$row['quantity'],
                'total_price' => (float)$row['total_price'],
                'total_formatted' => '₱' . number_format((float)$row['total_price'], 2),
                'status' => $row['reservation_status'],
                'status_label' => ucwords(str_replace('_', ' ', $row['reservation_status'])),
                'status_class' => 'badge-' . str_replace('_', '-', $row['reservation_status']),
                'pickup_date' => $row['preferred_pickup_date'],
                'pickup_time' => $formatted_time,
                'payment_type' => ucwords(str_replace('_', ' ', $row['payment_type'] ?? 'N/A'))
            ];
            $total_count++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'month' => $req_month,
        'month_label' => date('F Y', strtotime($start_date)),
        'total_count' => $total_count,
        'events' => $events
    ]);
    exit();
}

$preorder_service = new PreOrderService($conn);
$current_page = 'preorders'; // for sidebar active state

// Pagination settings
$records_per_page = 15;
$current_page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page_number < 1) {
    $current_page_number = 1;
}
$offset = ($current_page_number - 1) * $records_per_page;

// Get pre-order statistics
$preorder_stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN reservation_status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN reservation_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN reservation_status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM pre_orders po" . ($seller_scope_id !== null ? " INNER JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "");
$preorder_stats_result = mysqli_query($conn, $preorder_stats_query);
$preorder_stats = mysqli_fetch_assoc($preorder_stats_result);

// Get status filter
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'pending';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$pickup_date = isset($_GET['pickup_date']) ? $_GET['pickup_date'] : '';
if ($status_filter !== 'all' && !in_array($status_filter, $allowed_preorder_statuses, true)) {
    $status_filter = 'pending';
}
 
// Build WHERE clause for both queries
$where_clauses = [];
if ($status_filter && $status_filter !== 'all') {
    $where_clauses[] = "po.reservation_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $search_int = intval($search);
    $where_clauses[] = "(po.product_name LIKE '%$search_term%' OR u.full_name LIKE '%$search_term%' OR po.id = $search_int)";
}
if ($pickup_date) {
    $where_clauses[] = "po.preferred_pickup_date = '" . mysqli_real_escape_string($conn, $pickup_date) . "'";
}
$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total
                FROM pre_orders po
                JOIN users u ON po.user_id = u.id" .
                ($seller_scope_id !== null ? " JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "") .
                " $where_sql";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get pre-orders for the current page
$query = "SELECT po.*, u.email, u.full_name FROM pre_orders po 
          JOIN users u ON po.user_id = u.id" .
          ($seller_scope_id !== null ? " JOIN products p_scope ON p_scope.id = po.product_id AND {$partner_product_scope_sql}" : "") . "
          $where_sql
          ORDER BY po.preferred_pickup_time ASC, po.created_at ASC
          LIMIT $records_per_page OFFSET $offset";
$result = mysqli_query($conn, $query);

// Handle status updates
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    requirePermission('preorders.edit');

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header("Location: preorders.php?status=" . urlencode($status_filter) . "&search=" . urlencode($search) . "&pickup_date=" . urlencode($pickup_date));
        exit();
    }

    $pre_order_id = intval($_POST['pre_order_id']);
    $new_status = trim($_POST['new_status']);
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if ($pre_order_id <= 0 || !in_array($new_status, $allowed_preorder_statuses, true)) {
        $update_result = ['success' => false, 'message' => 'Invalid pre-order status update request.'];
    } elseif ($seller_scope_id !== null) {
        $scope_check_query = "SELECT po.id
                              FROM pre_orders po
                              INNER JOIN products p_scope ON p_scope.id = po.product_id
                              WHERE po.id = ? AND {$partner_product_scope_sql}
                              LIMIT 1";
        $scope_check_stmt = mysqli_prepare($conn, $scope_check_query);
        mysqli_stmt_bind_param($scope_check_stmt, "i", $pre_order_id);
        mysqli_stmt_execute($scope_check_stmt);
        $scope_check_result = mysqli_stmt_get_result($scope_check_stmt);
        $scoped_preorder = $scope_check_result ? mysqli_fetch_assoc($scope_check_result) : null;
        mysqli_stmt_close($scope_check_stmt);

        if (!$scoped_preorder) {
            $update_result = ['success' => false, 'message' => 'You can only update pre-orders for your own store.'];
        } else {
            $update_result = $preorder_service->updatePreOrderStatus($pre_order_id, $new_status, $admin_notes);
        }
    } else {
        $update_result = $preorder_service->updatePreOrderStatus($pre_order_id, $new_status, $admin_notes);
    }
    
    if ($update_result['success']) {
        $message = "<div class='alert alert-success'>" . htmlspecialchars((string)($update_result['message'] ?? 'Status updated successfully!')) . "</div>";
    } else {
        $message = "<div class='alert alert-danger'>" . htmlspecialchars($update_result['message']) . "</div>";
    }
    // To show the message with SweetAlert2
    if ($update_result['success']) {
        $_SESSION['success'] = (string)($update_result['message'] ?? 'Status updated successfully!');
    } else {
        $_SESSION['error'] = htmlspecialchars($update_result['message']);
    }
    // Redirect to clear POST data
    header("Location: preorders.php?status=" . urlencode($status_filter) . "&search=" . urlencode($search) . "&pickup_date=" . urlencode($pickup_date));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-Order Management | Admin</title>
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

        /* Pre-Order Calendar System Styles */
        .view-switch-btn {
            border: 1px solid #d0d5dd;
            background-color: #ffffff;
            color: #344054;
            font-weight: 500;
            font-size: 0.85rem;
            padding: 0.45rem 0.9rem;
            transition: all 0.2s ease;
        }
        .view-switch-btn:hover {
            background-color: #f8f9fa;
            color: #101828;
        }
        .view-switch-btn.active {
            background-color: #b3261e !important;
            border-color: #b3261e !important;
            color: #ffffff !important;
            box-shadow: 0 1px 2px rgba(179, 38, 30, 0.2);
        }
        body.dark-mode .view-switch-btn {
            background-color: #2d2d2d;
            border-color: #404040;
            color: #e0e0e0;
        }
        body.dark-mode .view-switch-btn.active {
            background-color: #b3261e !important;
            border-color: #b3261e !important;
            color: #ffffff !important;
        }

        .calendar-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        body.dark-mode .calendar-card {
            background: #2d2d2d;
            border-color: #404040;
        }

        .calendar-header-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf0;
        }
        body.dark-mode .calendar-header-toolbar {
            border-color: #404040;
        }

        .cal-nav-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .cal-nav-btn {
            border: 1px solid #d0d5dd;
            background: #ffffff;
            color: #344054;
            border-radius: 8px;
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cal-nav-btn:hover {
            background: #f8f9fa;
            border-color: #b3261e;
            color: #b3261e;
        }
        body.dark-mode .cal-nav-btn {
            background: #232323;
            border-color: #444;
            color: #e0e0e0;
        }
        body.dark-mode .cal-nav-btn:hover {
            background: #333;
            border-color: #b3261e;
            color: #ff8080;
        }

        .cal-month-heading {
            font-size: 1.35rem;
            font-weight: 700;
            color: #101828;
            min-width: 175px;
            text-align: center;
            margin: 0;
        }
        body.dark-mode .cal-month-heading {
            color: #ffffff;
        }

        .cal-grid-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #667085;
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 8px 8px 0 0;
            padding: 0.65rem 0;
        }
        body.dark-mode .cal-grid-header {
            background: #232323;
            border-color: #404040;
            color: #98a2b3;
        }

        .cal-grid-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border-left: 1px solid #eaecf0;
            border-bottom: 1px solid #eaecf0;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        body.dark-mode .cal-grid-days {
            border-color: #404040;
        }

        .cal-day-cell {
            min-height: 120px;
            background: #ffffff;
            border-right: 1px solid #eaecf0;
            border-top: 1px solid #eaecf0;
            padding: 6px 8px;
            display: flex;
            flex-direction: column;
            position: relative;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        body.dark-mode .cal-day-cell {
            background: #2d2d2d;
            border-color: #404040;
        }

        .cal-day-cell:hover {
            background-color: #fcfcfd;
        }
        body.dark-mode .cal-day-cell:hover {
            background-color: #353535;
        }

        .cal-day-cell.other-month {
            background-color: #fafafa;
            opacity: 0.55;
            cursor: default;
        }
        body.dark-mode .cal-day-cell.other-month {
            background-color: #242424;
        }

        .cal-day-cell.is-today {
            background-color: #fffbfa;
            border-color: #fecdca;
        }
        body.dark-mode .cal-day-cell.is-today {
            background-color: #3a2626;
            border-color: #7a271a;
        }

        .cal-day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .cal-day-num {
            font-size: 0.82rem;
            font-weight: 600;
            color: #344054;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        body.dark-mode .cal-day-num {
            color: #d0d5dd;
        }

        .cal-day-cell.is-today .cal-day-num {
            background-color: #b3261e;
            color: #ffffff;
            font-weight: 700;
        }

        .cal-day-badge {
            font-size: 0.68rem;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
            background: #f2f4f7;
            color: #475467;
        }
        body.dark-mode .cal-day-badge {
            background: #404040;
            color: #d0d5dd;
        }

        .cal-orders-list {
            display: flex;
            flex-direction: column;
            gap: 3px;
            flex-grow: 1;
            overflow: hidden;
        }

        .cal-chip {
            font-size: 0.72rem;
            line-height: 1.25;
            padding: 3px 6px;
            border-radius: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 5px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform 0.1s ease, box-shadow 0.1s ease;
        }
        .cal-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .cal-chip-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Status-specific chip colors */
        .cal-chip-pending {
            background: #fff8eb;
            color: #b54708;
            border-color: #fedf89;
        }
        .cal-chip-pending .cal-chip-dot { background: #b54708; }

        .cal-chip-confirmed {
            background: #eff8ff;
            color: #175cd3;
            border-color: #b2ddff;
        }
        .cal-chip-confirmed .cal-chip-dot { background: #175cd3; }

        .cal-chip-in_preparation {
            background: #fdf2fa;
            color: #c11574;
            border-color: #fce7f6;
        }
        .cal-chip-in_preparation .cal-chip-dot { background: #c11574; }

        .cal-chip-ready_for_pickup {
            background: #f0fdf9;
            color: #0e9384;
            border-color: #ccfbe7;
        }
        .cal-chip-ready_for_pickup .cal-chip-dot { background: #0e9384; }

        .cal-chip-completed {
            background: #ecfdf3;
            color: #027a48;
            border-color: #abefc6;
        }
        .cal-chip-completed .cal-chip-dot { background: #027a48; }

        .cal-chip-cancelled {
            background: #fef3f2;
            color: #b42318;
            border-color: #fee4e2;
        }
        .cal-chip-cancelled .cal-chip-dot { background: #b42318; }

        /* Dark mode chip variations */
        body.dark-mode .cal-chip-pending { background: #3b2811; color: #fedf89; border-color: #634316; }
        body.dark-mode .cal-chip-confirmed { background: #102a45; color: #b2ddff; border-color: #19416e; }
        body.dark-mode .cal-chip-in_preparation { background: #3c142b; color: #fce7f6; border-color: #68224b; }
        body.dark-mode .cal-chip-ready_for_pickup { background: #0c332d; color: #99f6e0; border-color: #14594e; }
        body.dark-mode .cal-chip-completed { background: #113423; color: #a6f4c5; border-color: #195837; }
        body.dark-mode .cal-chip-cancelled { background: #3c1917; color: #fecdca; border-color: #652a26; }

        .cal-more-link {
            font-size: 0.7rem;
            font-weight: 600;
            color: #b3261e;
            text-align: right;
            margin-top: 2px;
            cursor: pointer;
        }
        .cal-more-link:hover {
            text-decoration: underline;
        }

        .cal-legend-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid #eaecf0;
            font-size: 0.8rem;
            color: #475467;
        }
        body.dark-mode .cal-legend-bar {
            border-color: #404040;
            color: #b0b0b0;
        }
        .cal-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body class="admin-polish preorders-page">
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Pre-Order Management</h1>
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
                        <div class="stat-icon orange">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $preorder_stats['total'] ?? 0; ?>">0</h3>
                            <p>Total Pre-Orders</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon yellow">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $preorder_stats['pending'] ?? 0; ?>">0</h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue">
                            <i class="fas fa-thumbs-up"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $preorder_stats['confirmed'] ?? 0; ?>">0</h3>
                            <p>Confirmed</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $preorder_stats['completed'] ?? 0; ?>">0</h3>
                            <p>Completed</p>
                        </div>
                    </div>
                </div>

                <!-- Section Header with View Mode Switcher -->
                <div class="section-header d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <h2 class="m-0">Pre-Orders</h2>
                        <div class="btn-group" role="group" aria-label="Preorder View Selection">
                            <button type="button" class="btn btn-sm view-switch-btn active" id="btnTableView" onclick="switchPreorderView('table')">
                                <i class="fas fa-list me-1"></i> Table View
                            </button>
                            <button type="button" class="btn btn-sm view-switch-btn" id="btnCalendarView" onclick="switchPreorderView('calendar')">
                                <i class="fas fa-calendar-alt me-1"></i> Calendar View
                            </button>
                        </div>
                    </div>
                    <div id="tableFiltersWrapper">
                        <form method="GET" class="filter-form m-0">
                            <input type="text" name="search" placeholder="Search by order ID, product, or customer..." 
                                   value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                            <input type="date" name="pickup_date" class="form-control" value="<?php echo htmlspecialchars($pickup_date); ?>" title="Pickup Date" onchange="this.form.submit()">
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="in_preparation" <?php echo $status_filter === 'in_preparation' ? 'selected' : ''; ?>>In Preparation</option>
                                <option value="ready_for_pickup" <?php echo $status_filter === 'ready_for_pickup' ? 'selected' : ''; ?>>Ready</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                </div>

                <!-- TABLE VIEW CONTAINER -->
                <div id="tableViewContainer">
                
                <!-- Pre-Orders List -->
                <div class="table-responsive fade-in-up">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Pickup Date</th>
                                <th>Total Price</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php
                    if (mysqli_num_rows($result) > 0) {
                        while ($preorder = mysqli_fetch_assoc($result)) {
                            $status_class = 'badge-' . str_replace('_', '-', $preorder['reservation_status']);
                            ?>
                            <tr>
                                <td><strong>#<?php echo $preorder['id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($preorder['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($preorder['product_name']); ?></td>
                                <td>
                                    <?php 
                                    $date_str = $preorder['preferred_pickup_date'];
                                    $time_str = $preorder['preferred_pickup_time'];
                                    echo ($date_str && $date_str != '0000-00-00') ? date('M d, Y', strtotime($date_str)) . ' @ ' . htmlspecialchars($time_str) : 'N/A'; 
                                    ?>
                                </td>
                                <td>₱<?php echo number_format($preorder['total_price'], 2); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', $preorder['payment_type'])); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucwords(str_replace('_', ' ', $preorder['reservation_status'])); ?></span></td>
                                <td>
                                    <button class="btn-icon" onclick="showUpdateStatus(<?php echo $preorder['id']; ?>)" title="Update Status">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon" data-bs-toggle="modal" data-bs-target="#preorderDetailsModal" onclick="loadPreOrderDetails(<?php echo $preorder['id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-icon" type="button" onclick="window.open('print_preorder_receipt.php?id=<?php echo (int)$preorder['id']; ?>&print=1', '_blank')" title="Print Receipt">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='8' class='text-center text-muted'>No pre-orders found.</td></tr>";
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
                                        <a class="page-link" href="?page=<?php echo $current_page_number - 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&pickup_date=<?php echo urlencode($pickup_date); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&pickup_date=<?php echo urlencode($pickup_date); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page_number < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number + 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>&pickup_date=<?php echo urlencode($pickup_date); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div><!-- /#tableViewContainer -->

                <!-- CALENDAR VIEW CONTAINER -->
                <div id="calendarViewContainer" style="display: none;">
                    <div class="calendar-card fade-in-up">
                        <div class="calendar-header-toolbar">
                            <div class="cal-nav-group">
                                <button type="button" class="cal-nav-btn" id="calPrevBtn" onclick="navigateCalendarMonth(-1)" title="Previous Month">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h3 class="cal-month-heading" id="calMonthTitle"><?php echo date('F Y'); ?></h3>
                                <button type="button" class="cal-nav-btn" id="calNextBtn" onclick="navigateCalendarMonth(1)" title="Next Month">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                                <button type="button" class="cal-nav-btn ms-2" id="calTodayBtn" onclick="goToCalendarToday()">
                                    Today
                                </button>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                                <label for="calStatusSelect" class="small text-muted mb-0 d-none d-sm-inline">Status:</label>
                                <select id="calStatusSelect" class="form-select form-select-sm" style="width: auto;" onchange="loadCalendarPreorders()">
                                    <option value="all">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="in_preparation">In Preparation</option>
                                    <option value="ready_for_pickup">Ready for Pickup</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                <span class="badge" style="background-color: #fee4e2; color: #b3261e; font-size: 0.85rem; padding: 0.5rem 0.75rem;" id="calTotalCountBadge">
                                    <i class="fas fa-calendar-check me-1"></i> <span id="calTotalOrdersCount">0</span> Booked
                                </span>
                            </div>
                        </div>

                        <!-- Calendar Grid -->
                        <div class="cal-grid-header">
                            <div>Sun</div>
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div>Sat</div>
                        </div>
                        <div class="cal-grid-days" id="calGridDaysContainer">
                            <!-- Rendered via JavaScript -->
                        </div>

                        <!-- Calendar Status Legend -->
                        <div class="cal-legend-bar">
                            <span class="fw-semibold text-secondary">Legend:</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#b54708;"></span> Pending</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#175cd3;"></span> Confirmed</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#c11574;"></span> In Preparation</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#0e9384;"></span> Ready for Pickup</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#027a48;"></span> Completed</span>
                            <span class="cal-legend-item"><span class="cal-chip-dot" style="background:#b42318;"></span> Cancelled</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Pre-Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="updateStatusForm">
                        <input type="hidden" id="preOrderId" name="pre_order_id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label class="form-label"><strong>New Status</strong></label>
                            <select name="new_status" class="form-select" required>
                                <option value="">-- Select Status --</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="in_preparation">In Preparation</option>
                                <option value="ready_for_pickup">Ready for Pickup</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea name="admin_notes" class="form-control" rows="4" placeholder="Add any notes about this status change..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" form="updateStatusForm" class="btn btn-primary">Update Status</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Pre-Order Details Modal -->
    <div class="modal fade" id="preorderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pre-Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="preOrderDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Day Pre-Orders Breakdown Modal -->
    <div class="modal fade" id="dayOrdersModal" tabindex="-1" aria-labelledby="dayOrdersModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="dayOrdersModalTitle">Pre-Orders Scheduled</h5>
                        <div class="text-muted small" id="dayOrdersModalSubtitle">Viewing bookings for selected date</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive mb-0">
                        <table class="admin-table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Pickup Time</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="dayOrdersModalBody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo $_SESSION['success']; ?>', timer: 2000, showConfirmButton: false });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo $_SESSION['error']; ?>' });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        const statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
        const dayOrdersModal = new bootstrap.Modal(document.getElementById('dayOrdersModal'));
        const preorderModal = new bootstrap.Modal(document.getElementById('preorderDetailsModal'));

        function showUpdateStatus(preOrderId) {
            document.getElementById('preOrderId').value = preOrderId;
            statusModal.show();
        }

        function loadPreOrderDetails(id) {
            const container = document.getElementById('preOrderDetails');
            container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading details...</p></div>';
            
            fetch('get_preorder_details.php?id=' + id)
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                });
        }

        // ==========================================
        // Interactive Pre-Orders Calendar Controller
        // ==========================================
        const initialDate = new Date();
        let calCurrentYear = initialDate.getFullYear();
        let calCurrentMonth = initialDate.getMonth() + 1; // 1 to 12
        let calendarEventsData = {};

        function switchPreorderView(viewMode) {
            const tableView = document.getElementById('tableViewContainer');
            const calView = document.getElementById('calendarViewContainer');
            const tableFilters = document.getElementById('tableFiltersWrapper');
            const btnTable = document.getElementById('btnTableView');
            const btnCal = document.getElementById('btnCalendarView');

            if (viewMode === 'calendar') {
                tableView.style.display = 'none';
                tableFilters.style.display = 'none';
                calView.style.display = 'block';
                btnTable.classList.remove('active');
                btnCal.classList.add('active');
                localStorage.setItem('preorders_active_view', 'calendar');
                loadCalendarPreorders();
            } else {
                calView.style.display = 'none';
                tableView.style.display = 'block';
                tableFilters.style.display = 'block';
                btnCal.classList.remove('active');
                btnTable.classList.add('active');
                localStorage.setItem('preorders_active_view', 'table');
            }
        }

        function navigateCalendarMonth(delta) {
            calCurrentMonth += delta;
            if (calCurrentMonth > 12) {
                calCurrentMonth = 1;
                calCurrentYear++;
            } else if (calCurrentMonth < 1) {
                calCurrentMonth = 12;
                calCurrentYear--;
            }
            loadCalendarPreorders();
        }

        function goToCalendarToday() {
            const today = new Date();
            calCurrentYear = today.getFullYear();
            calCurrentMonth = today.getMonth() + 1;
            loadCalendarPreorders();
        }

        function loadCalendarPreorders() {
            const monthStr = calCurrentYear + '-' + String(calCurrentMonth).padStart(2, '0');
            const statusFilter = document.getElementById('calStatusSelect') ? document.getElementById('calStatusSelect').value : 'all';
            const container = document.getElementById('calGridDaysContainer');
            
            container.innerHTML = '<div style="grid-column: 1 / -1; padding: 40px; text-align: center;"><div class="spinner-border text-danger" role="status"></div><div class="mt-2 text-muted small">Loading pre-orders calendar...</div></div>';

            fetch(`preorders.php?ajax_calendar=1&month=${encodeURIComponent(monthStr)}&cal_status=${encodeURIComponent(statusFilter)}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        document.getElementById('calMonthTitle').textContent = data.month_label;
                        document.getElementById('calTotalOrdersCount').textContent = data.total_count;
                        calendarEventsData = data.events || {};
                        renderCalendarGrid(calCurrentYear, calCurrentMonth, calendarEventsData);
                    } else {
                        container.innerHTML = '<div style="grid-column: 1 / -1; padding: 30px; text-align: center; color: #b42318;">Failed to load calendar events.</div>';
                    }
                })
                .catch(err => {
                    console.error('Error loading calendar preorders:', err);
                    container.innerHTML = '<div style="grid-column: 1 / -1; padding: 30px; text-align: center; color: #b42318;">Network error loading calendar.</div>';
                });
        }

        function renderCalendarGrid(year, month, events) {
            const container = document.getElementById('calGridDaysContainer');
            container.innerHTML = '';

            const firstDayIndex = new Date(year, month - 1, 1).getDay(); // 0 (Sun) to 6 (Sat)
            const daysInMonth = new Date(year, month, 0).getDate();
            const daysInPrevMonth = new Date(year, month - 1, 0).getDate();

            const totalSlots = (firstDayIndex + daysInMonth > 35) ? 42 : 35;
            const now = new Date();
            const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

            // Render Previous Month Padding Days
            for (let i = firstDayIndex - 1; i >= 0; i--) {
                const dayNum = daysInPrevMonth - i;
                const cell = document.createElement('div');
                cell.className = 'cal-day-cell other-month';
                cell.innerHTML = `<div class="cal-day-header"><span class="cal-day-num">${dayNum}</span></div>`;
                container.appendChild(cell);
            }

            // Render Current Month Days
            for (let day = 1; day <= daysInMonth; day++) {
                const dateKey = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dayOrders = events[dateKey] || [];
                const isToday = (dateKey === todayStr);

                const cell = document.createElement('div');
                cell.className = 'cal-day-cell' + (isToday ? ' is-today' : '');

                let ordersHtml = '';
                const maxDisplay = 2;
                const displayOrders = dayOrders.slice(0, maxDisplay);

                displayOrders.forEach(order => {
                    const statusClass = 'cal-chip-' + order.status;
                    ordersHtml += `
                        <div class="cal-chip ${statusClass}" title="${escapeHtml(order.customer_name)} - ${escapeHtml(order.product_name)} (${order.pickup_time})" onclick="handleChipClick(event, ${order.id})">
                            <span class="cal-chip-dot"></span>
                            <span class="fw-semibold">${escapeHtml(order.pickup_time)}</span>
                            <span class="text-truncate">${escapeHtml(order.product_name)}</span>
                        </div>
                    `;
                });

                if (dayOrders.length > maxDisplay) {
                    const remaining = dayOrders.length - maxDisplay;
                    ordersHtml += `<div class="cal-more-link" onclick="handleMoreClick(event, '${dateKey}')">+${remaining} more</div>`;
                }

                const badgeHtml = dayOrders.length > 0 
                    ? `<span class="cal-day-badge">${dayOrders.length}</span>` 
                    : '';

                cell.innerHTML = `
                    <div class="cal-day-header">
                        <span class="cal-day-num">${day}</span>
                        ${badgeHtml}
                    </div>
                    <div class="cal-orders-list">
                        ${ordersHtml}
                    </div>
                `;

                if (dayOrders.length > 0) {
                    cell.addEventListener('click', () => {
                        openDayBreakdownModal(dateKey);
                    });
                }

                container.appendChild(cell);
            }

            // Render Next Month Padding Days
            const remainingSlots = totalSlots - (firstDayIndex + daysInMonth);
            for (let nextDay = 1; nextDay <= remainingSlots; nextDay++) {
                const cell = document.createElement('div');
                cell.className = 'cal-day-cell other-month';
                cell.innerHTML = `<div class="cal-day-header"><span class="cal-day-num">${nextDay}</span></div>`;
                container.appendChild(cell);
            }
        }

        function handleChipClick(e, orderId) {
            e.stopPropagation();
            loadPreOrderDetails(orderId);
            preorderModal.show();
        }

        function handleMoreClick(e, dateKey) {
            e.stopPropagation();
            openDayBreakdownModal(dateKey);
        }

        function openDayBreakdownModal(dateKey) {
            const dayOrders = calendarEventsData[dateKey] || [];
            if (!dayOrders.length) return;

            const dateObj = new Date(dateKey + 'T00:00:00');
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const formattedDate = dateObj.toLocaleDateString('en-US', options);

            document.getElementById('dayOrdersModalTitle').textContent = `Pre-Orders for ${formattedDate}`;
            document.getElementById('dayOrdersModalSubtitle').textContent = `${dayOrders.length} booked order${dayOrders.length > 1 ? 's' : ''} scheduled`;

            const tbody = document.getElementById('dayOrdersModalBody');
            tbody.innerHTML = '';

            dayOrders.forEach(order => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>#${order.id}</strong></td>
                    <td>
                        <span class="text-nowrap"><i class="far fa-clock me-1 text-muted"></i>${escapeHtml(order.pickup_time)}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">${escapeHtml(order.customer_name)}</div>
                        <small class="text-muted">${escapeHtml(order.customer_phone || order.customer_email || 'No contact')}</small>
                    </td>
                    <td>
                        <div>${escapeHtml(order.product_name)}</div>
                        <small class="text-muted">Qty: ${order.quantity}</small>
                    </td>
                    <td class="fw-semibold">${order.total_formatted}</td>
                    <td>
                        <span class="status-badge ${order.status_class}">${order.status_label}</span>
                    </td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn-icon" onclick="openDetailsFromDayModal(${order.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button type="button" class="btn-icon" onclick="openStatusFromDayModal(${order.id})" title="Update Status">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn-icon" onclick="window.open('print_preorder_receipt.php?id=${order.id}&print=1', '_blank')" title="Print Receipt">
                            <i class="fas fa-print"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            dayOrdersModal.show();
        }

        function openDetailsFromDayModal(id) {
            dayOrdersModal.hide();
            setTimeout(() => {
                loadPreOrderDetails(id);
                preorderModal.show();
            }, 300);
        }

        function openStatusFromDayModal(id) {
            dayOrdersModal.hide();
            setTimeout(() => {
                showUpdateStatus(id);
            }, 300);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Initialize active view preference
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const viewPref = urlParams.get('view') || localStorage.getItem('preorders_active_view');
            if (viewPref === 'calendar') {
                switchPreorderView('calendar');
            }
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
