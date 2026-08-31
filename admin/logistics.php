<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once '../includes/security.php';
require_once '../logistics_service.php';
require_once '../preorder_service.php';
require_once 'hr_module_common.php';
requirePermission('logistics.view');
$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));

$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$driver_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$driver_role_sql = hrLogisticsEmployeeSqlCondition('e', 'd', $conn);

$logistics = new LogisticsService($conn);
$preorder_service = new PreOrderService($conn);
$error = '';
$success = '';
$allowed_delivery_statuses = ['pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled'];
$allowed_preorder_statuses = ['pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled'];
$partner_logistics_scope_sql = '';
$partner_preorder_scope_sql = '';
$partner_product_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_product_scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', (int)$seller_scope_id);
    $partner_logistics_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id');
    $partner_preorder_scope_sql = " AND EXISTS (
        SELECT 1
        FROM products p_scope
        WHERE p_scope.id = po.product_id
          AND {$partner_product_scope_sql}
    )";
}

// --- NEW: Fetch available drivers ---
$available_drivers_query = "
    SELECT e.id, e.first_name, e.last_name, e.phone, e.vehicle_details
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN attendance a ON a.employee_id = e.id
        AND a.attendance_date = CURDATE()
        AND a.status IN ('present', 'late', 'half_day')
        AND (a.hr_status IS NULL OR a.hr_status <> 'rejected')
    WHERE e.status = 'active' 
    AND {$driver_role_sql}
    AND {$driver_scope_sql}
    AND a.id IS NOT NULL
    AND e.id NOT IN (
        SELECT lt.driver_id 
        FROM logistics_tracking lt 
        WHERE lt.driver_id IS NOT NULL 
        AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
    )
    ORDER BY e.first_name ASC
";
$available_drivers_result = mysqli_query($conn, $available_drivers_query);
$available_drivers = [];
if ($available_drivers_result) while($driver = mysqli_fetch_assoc($available_drivers_result)) $available_drivers[] = $driver;

// Handle Delivery Status Update (Fix for missing update_logistics.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery_status'])) {
    requirePermission('logistics.update');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $tracking_id = intval($_POST['tracking_id']);
    $new_status = trim($_POST['new_status']);

    if (empty($error) && $tracking_id > 0 && in_array($new_status, $allowed_delivery_statuses, true)) {
        if ($seller_scope_id !== null) {
            $scope_query = "SELECT lt.id
                            FROM logistics_tracking lt
                            WHERE lt.id = ? {$partner_logistics_scope_sql}
                            LIMIT 1";
            $scope_stmt = mysqli_prepare($conn, $scope_query);
            mysqli_stmt_bind_param($scope_stmt, "i", $tracking_id);
            mysqli_stmt_execute($scope_stmt);
            $scope_result = mysqli_stmt_get_result($scope_stmt);
            $scoped_tracking = $scope_result ? mysqli_fetch_assoc($scope_result) : null;
            mysqli_stmt_close($scope_stmt);

            if (!$scoped_tracking) {
                $error = "You can only update deliveries tied to your own store orders.";
            }
        }

        if (empty($error)) {
            $update_result = $logistics->updateTrackingStatus($tracking_id, $new_status, 'Updated by admin from logistics dashboard');
            if ($update_result['success']) {
                $success = "Delivery status updated successfully!";
            } else {
                $error = $update_result['message'] ?? "Failed to update delivery status.";
            }
        }
    } else {
        if (empty($error)) {
            $error = "Invalid delivery status update request.";
        }
    }
}

// Handle Pre-order Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preorder_status'])) {
    requireAnyPermission(['preorders.edit', 'logistics.update']);
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    }

    $pre_order_id = intval($_POST['pre_order_id']);
    $new_status = trim($_POST['new_status']);
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if (empty($error) && $pre_order_id > 0 && in_array($new_status, $allowed_preorder_statuses, true)) {
        if ($seller_scope_id !== null) {
            $scope_query = "SELECT po.id
                            FROM pre_orders po
                            INNER JOIN products p_scope ON p_scope.id = po.product_id
                            WHERE po.id = ? AND {$partner_product_scope_sql}
                            LIMIT 1";
            $scope_stmt = mysqli_prepare($conn, $scope_query);
            mysqli_stmt_bind_param($scope_stmt, "i", $pre_order_id);
            mysqli_stmt_execute($scope_stmt);
            $scope_result = mysqli_stmt_get_result($scope_stmt);
            $scoped_preorder = $scope_result ? mysqli_fetch_assoc($scope_result) : null;
            mysqli_stmt_close($scope_stmt);

            if (!$scoped_preorder) {
                $error = "You can only update pre-orders for your own store.";
            }
        }

        if (empty($error)) {
            $update_result = $preorder_service->updatePreOrderStatus($pre_order_id, $new_status, $admin_notes);
            if ($update_result['success']) {
                $success = "Pre-order status updated successfully!";
            } else {
                $error = htmlspecialchars($update_result['message']);
            }
        }
    } else {
        if (empty($error)) {
            $error = "Invalid pre-order status update request.";
        }
    }
}

// Pagination settings
$records_per_page = 20;
$current_page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page_number < 1) {
    $current_page_number = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Management - Admin Dashboard</title>
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
<body class="admin-polish logistics-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Logistics Management</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">

                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tableView">Table View</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#mapView">Map View</a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tableView">
                
                <div class="section-header">
                    <h2>Delivery Overview</h2>
                    <?php if ($seller_scope_id === null): ?>
                        <a href="logistics_settings.php" class="btn btn-outline-primary">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Stats Cards -->
                <div class="dashboard-grid mb-4">
                    <?php
                    // Get statistics
                    $stats_queries = [
                        'pending' => "SELECT COUNT(*) as count FROM logistics_tracking lt WHERE lt.current_status = 'pending'" . $partner_logistics_scope_sql,
                        'on_the_way' => "SELECT COUNT(*) as count FROM logistics_tracking lt WHERE lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')" . $partner_logistics_scope_sql,
                        'delivered' => "SELECT COUNT(*) as count FROM logistics_tracking lt WHERE lt.current_status = 'delivered'" . $partner_logistics_scope_sql,
                        'cancelled' => "SELECT COUNT(*) as count FROM logistics_tracking lt WHERE lt.current_status = 'cancelled'" . $partner_logistics_scope_sql
                    ];
                    
                    $stats = [];
                    foreach ($stats_queries as $key => $query) {
                        $result = mysqli_query($conn, $query);
                        if ($result) {
                            $row = mysqli_fetch_assoc($result);
                            $stats[$key] = $row['count'];
                        }
                    }
                    ?>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff3cd;">
                            <i class="fas fa-clock text-warning"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['pending'] ?? 0; ?></h3>
                            <p>Pending</p>
                            <small class="text-muted">Waiting for pickup</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e3f2fd;">
                            <i class="fas fa-truck text-primary"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['on_the_way'] ?? 0; ?></h3>
                            <p>In Transit</p>
                            <small class="text-muted">Out for delivery</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d1e7dd;">
                            <i class="fas fa-check-circle text-success"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['delivered'] ?? 0; ?></h3>
                            <p>Delivered</p>
                            <small class="text-muted">Successfully delivered</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #f8d7da;">
                            <i class="fas fa-times-circle text-danger"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $stats['cancelled'] ?? 0; ?></h3>
                            <p>Cancelled</p>
                            <small class="text-muted">Cancelled deliveries</small>
                        </div>
                    </div>
                </div>
                
                <!-- Available Drivers -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-clock"></i> Available Drivers</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($available_drivers)): ?>
                            <p class="text-muted text-center">No drivers are currently available.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach($available_drivers as $driver): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0"><?= htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']) ?></h6>
                                            <small class="text-muted"><?= htmlspecialchars($driver['vehicle_details'] ?? 'No vehicle info') ?></small>
                                        </div>
                                        <span class="badge bg-success rounded-pill">Available</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" id="filterForm" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="statusFilter" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="assigned">Assigned</option>
                                    <option value="picked_up">Picked Up</option>
                                    <option value="on_the_way">On the Way</option>
                                    <option value="arriving">Arriving</option>
                                    <option value="delivered">Delivered</option>
                                    <option value="failed">Failed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Provider</label>
                                <select name="provider" id="providerFilter" class="form-select">
                                    <option value="">All Providers</option>
                                    <option value="1">In-House Delivery</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" id="dateFromFilter" class="form-control">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" id="dateToFilter" class="form-control">
                            </div>

                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Deliveries Table -->
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Type</th>
                                <th>Provider</th>
                                <th>Driver</th>
                                <th>Status</th>
                                <th>Pickup Time</th>
                                <th>Est. Delivery</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sanitize inputs
                            $allowed_status_filters = array_unique(array_merge($allowed_delivery_statuses, $allowed_preorder_statuses));
                            $status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
                            if ($status_filter !== '' && !in_array($status_filter, $allowed_status_filters, true)) {
                                $status_filter = '';
                            }

                            $provider_filter = isset($_GET['provider']) ? intval($_GET['provider']) : 0;
                            if ($provider_filter <= 0) {
                                $provider_filter = '';
                            }

                            $date_from = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
                            $date_to = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
                            if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                                $date_from = '';
                            }
                            if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                                $date_to = '';
                            }

                            $offset = ($current_page_number - 1) * $records_per_page;

                            // Build WHERE clauses
                            $where_orders = "1=1" . $partner_logistics_scope_sql;
                            $where_preorders = "1=1" . $partner_preorder_scope_sql;
                            
                            if ($status_filter) {
                                $where_orders .= " AND lt.current_status = '$status_filter'";
                                $where_preorders .= " AND po.reservation_status = '$status_filter'";
                            }
                            
                            if ($provider_filter) {
                                $where_orders .= " AND lt.logistics_provider_id = " . intval($provider_filter);
                                // Pre-orders are assumed In-House (1) for now, hide if filtering for others
                                if ((int)$provider_filter !== 1) {
                                    $where_preorders .= " AND 0=1"; 
                                }
                            }
                            
                            if ($date_from) {
                                $where_orders .= " AND DATE(lt.created_at) >= '$date_from'";
                                $where_preorders .= " AND DATE(po.created_at) >= '$date_from'";
                            }
                            
                            if ($date_to) {
                                $where_orders .= " AND DATE(lt.created_at) <= '$date_to'";
                                $where_preorders .= " AND DATE(po.created_at) <= '$date_to'";
                            }
                            
                            // Count total records for pagination
                            $count_query = "
                                SELECT SUM(total) as total_records FROM (
                                    SELECT COUNT(*) as total 
                                    FROM logistics_tracking lt
                                    WHERE $where_orders
                                    
                                    UNION ALL
                                    
                                    SELECT COUNT(*) as total 
                                    FROM pre_orders po
                                    WHERE $where_preorders
                                ) as combined_counts
                            ";
                            $count_result = mysqli_query($conn, $count_query);
                            $total_records = mysqli_fetch_assoc($count_result)['total_records'] ?? 0;
                            $total_pages = ceil($total_records / $records_per_page);

                            // Main query with optimization: push down LIMIT/ORDER BY
                            $limit_for_sub = $offset + $records_per_page;
                            
                            $query = "
                            (SELECT 
                                    lt.id as id,
                                    lt.order_id as order_id,
                                    o.order_number as ref_number,
                                    u.full_name as customer_name,
                                    'Order' as type,
                                    o.delivery_option as delivery_type,
                                    lp.provider_name,
                                    COALESCE(lt.driver_name, 'Unassigned') as driver_name,
                                    lt.current_status as status,
                                    lt.pickup_time,
                                    lt.estimated_delivery,
                                    lt.proof_of_delivery_path,
                                    lt.created_at,
                                    'logistics' as source
                                FROM logistics_tracking lt
                                LEFT JOIN orders o ON lt.order_id = o.id
                                LEFT JOIN users u ON o.user_id = u.id
                                LEFT JOIN logistics_providers lp ON lt.logistics_provider_id = lp.id
                                WHERE $where_orders
                                ORDER BY lt.created_at DESC
                                LIMIT $limit_for_sub)
                            
                                UNION ALL

                                (SELECT 
                                    po.id as id,
                                    NULL as order_id,
                                    CONCAT('PO-', po.id) as ref_number,
                                    u.full_name as customer_name,
                                    'Pre-Order' as type,
                                    'Pickup' as delivery_type,
                                    'In-House' as provider_name,
                                    'Unassigned' as driver_name,
                                    po.reservation_status as status,
                                    CONCAT(po.preferred_pickup_date, ' ', po.preferred_pickup_time) as pickup_time,
                                    NULL as estimated_delivery,
                                    NULL as proof_of_delivery_path,
                                    po.created_at,
                                    'preorder' as source
                                FROM pre_orders po
                                LEFT JOIN users u ON po.user_id = u.id
                                WHERE $where_preorders
                                ORDER BY po.created_at DESC
                                LIMIT $limit_for_sub)

                                ORDER BY created_at DESC
                                LIMIT $records_per_page OFFSET $offset
                            ";
                            
                            $result = mysqli_query($conn, $query);
                            
                            if ($result) {
                                
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $status_class = 'badge-' . ($row['status'] == 'delivered' || $row['status'] == 'completed' || $row['status'] == 'confirmed' ? 'success' : ($row['status'] == 'cancelled' || $row['status'] == 'failed' ? 'danger' : 'warning'));
                                    $pickup_time = $row['pickup_time'] ? date('M d, H:i', strtotime($row['pickup_time'])) : '-';
                                    $est_delivery = $row['estimated_delivery'] ? date('M d, H:i', strtotime($row['estimated_delivery'])) : '-';
                                    $delivery_type = ucfirst($row['delivery_type'] ?? 'Delivery');
                                    $type_badge = $row['type'] === 'Pre-Order' ? 'bg-warning text-dark' : ($delivery_type === 'Pickup' ? 'bg-info' : 'bg-primary');
                                    
                                    // Display provider only if delivery
                                    $provider_display = (stripos($delivery_type, 'delivery') !== false) ? htmlspecialchars($row['provider_name'] ?? 'N/A') : '-';
                                    ?>
                                    <tr id="logistics-row-<?php echo $row['source'] . '-' . $row['id']; ?>">
                                        <td><strong><?php echo htmlspecialchars($row['ref_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                                        <td><span class="badge <?php echo $type_badge; ?>"><?php echo $row['type']; ?></span></td>
                                        <td><?php echo $provider_display; ?></td>
                                        <td class="driver-name"><?php echo htmlspecialchars($row['driver_name'] ?? 'Unassigned'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo ucwords(str_replace('_', ' ', $row['status'])); ?></span>
                                            <?php if ($row['proof_of_delivery_path']): ?>
                                                <!-- FIXED: Added ../uploads/ prefix to fix 404 error -->
                                                <a href="../uploads/<?php echo htmlspecialchars($row['proof_of_delivery_path']); ?>" target="_blank" class="d-block small mt-1"><i class="fas fa-camera"></i> View Proof</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $pickup_time; ?></td>
                                        <td><?php echo $est_delivery; ?></td>
                                        <td class="actions-cell">
                                            <?php if ($row['source'] === 'logistics'): ?>
                                            <!-- IMPROVED: Use Modal instead of page redirect -->
                                            <button type="button" class="btn-icon" title="View Details" onclick="viewDeliveryDetails(<?php echo $row['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($row['status'] === 'pending' || $row['status'] === 'assigned'): ?>
                                                <button class="btn-icon" title="Assign Driver" onclick="openAssignDriverModal(<?php echo (int)$row['id']; ?>, <?php echo (int)($row['order_id'] ?? 0); ?>)"><i class="fas fa-user-plus"></i></button>
                                            <?php endif; ?>
                                            <!-- IMPROVED: Use Modal for smoother updates -->
                                            <button type="button" class="btn-icon" title="Update Status" onclick="editDeliveryStatus(<?php echo $row['id']; ?>, '<?php echo $row['status']; ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (!in_array($row['status'], ['delivered', 'cancelled', 'failed'])): ?>
                                            <button onclick="cancelDelivery(<?php echo $row['id']; ?>)" class="btn-icon btn-icon-danger" title="Cancel">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php else: // Pre-order actions ?>
                                            <button class="btn-icon" onclick="showUpdatePreOrderStatus(<?php echo $row['id']; ?>)" title="Update Status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon" data-bs-toggle="modal" data-bs-target="#preorderDetailsModal" onclick="loadPreOrderDetails(<?php echo $row['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
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
                                        <a class="page-link" href="?page=<?php echo $current_page_number - 1; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page_number < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $current_page_number + 1; ?>&status=<?php echo urlencode($status_filter); ?>&provider=<?php echo urlencode($provider_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
                    </div>
                    <div class="tab-pane fade" id="mapView">
                        <div id="logisticsMap" style="height: 70vh; width: 100%; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delivery Details Modal (NEW) -->
    <div class="modal fade" id="deliveryDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delivery Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="deliveryDetailsContent">
                    <div class="text-center p-3">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Delivery Status Modal (NEW) -->
    <div class="modal fade" id="deliveryStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Delivery Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="updateDeliveryStatusForm">
                        <input type="hidden" name="update_delivery_status" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="tracking_id" id="editDeliveryId">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="new_status" id="editDeliveryStatus" class="form-select" required>
                                <option value="pending">Pending</option>
                                <option value="assigned">Assigned</option>
                                <option value="picked_up">Picked Up</option>
                                <option value="on_the_way">On the Way</option>
                                <option value="arriving">Arriving</option>
                                <option value="delivered">Delivered</option>
                                <option value="failed">Failed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="updateDeliveryStatusForm" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Pre-Order Status Modal -->
    <div class="modal fade" id="preorderStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Pre-Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="updatePreOrderStatusForm">
                        <input type="hidden" id="preOrderId" name="pre_order_id">
                        <input type="hidden" name="update_preorder_status" value="1">
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
                    <button type="submit" form="updatePreOrderStatusForm" class="btn btn-primary">Update Status</button>
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

    <!-- Assign Driver Modal -->
    <div class="modal fade" id="assignDriverModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Driver to Delivery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="assignDriverForm">
                    <div class="modal-body">
                        <input type="hidden" name="tracking_id" id="assignTrackingId">
                        <input type="hidden" id="assignOrderId">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label for="driverSelect" class="form-label">Select Driver</label>
                            <select name="employee_id" id="driverSelect" class="form-select" required>
                                <option value="">-- Loading eligible riders... --</option>
                            </select>
                            <div class="form-text">Only riders with attendance on this order's delivery date are shown.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign Driver</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Theme Toggler
    const themeToggler = document.getElementById('themeToggler');
    const body = document.body;
    const icon = themeToggler.querySelector('i');
    const logisticsCsrfToken = <?php echo json_encode($csrf_token); ?>;

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
<?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: '<?php echo htmlspecialchars($success); ?>',
        timer: 2000,
        showConfirmButton: false
    });
<?php endif; ?>

<?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?php echo htmlspecialchars($error); ?>'
    });
<?php endif; ?>

function cancelDelivery(trackingId) {
    Swal.fire({
        title: 'Cancel Delivery?',
        text: "Are you sure you want to cancel this delivery?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('cancel_delivery.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: 'tracking_id=' + trackingId + '&reason=Admin cancelled&csrf_token=' + encodeURIComponent(logisticsCsrfToken)
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', 'Delivery cancelled successfully.', 'success');
                    const row = document.getElementById('logistics-row-logistics-' + trackingId);
                    if (row) {
                        row.querySelector('.status-badge').textContent = 'Cancelled';
                        row.querySelector('.actions-cell').innerHTML = '<span class="text-muted">-</span>';
                    }
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
            }
    })
}

const preorderStatusModal = new bootstrap.Modal(document.getElementById('preorderStatusModal'));

function showUpdatePreOrderStatus(id) {
    document.getElementById('preOrderId').value = id;
    preorderStatusModal.show();
}

function viewDeliveryDetails(id) {
    var modal = new bootstrap.Modal(document.getElementById('deliveryDetailsModal'));
    modal.show();
    $('#deliveryDetailsContent').html('<div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div></div>');
    $.get('get_logistics_details.php?id=' + id, function(data) {
        $('#deliveryDetailsContent').html(data);
    }).fail(function() {
        $('#deliveryDetailsContent').html('<div class="alert alert-danger">Failed to load details.</div>');
    });
}

function editDeliveryStatus(id, currentStatus) {
    $('#editDeliveryId').val(id);
    $('#editDeliveryStatus').val(currentStatus);
    var modal = new bootstrap.Modal(document.getElementById('deliveryStatusModal'));
    modal.show();
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

const assignDriverModal = new bootstrap.Modal(document.getElementById('assignDriverModal'));
function loadEligibleDrivers(orderId) {
    const driverSelect = document.getElementById('driverSelect');
    if (!driverSelect) return Promise.resolve();

    if (!orderId) {
        driverSelect.innerHTML = '<option value="">-- Invalid order --</option>';
        return Promise.resolve();
    }

    driverSelect.innerHTML = '<option value="">Loading eligible riders...</option>';
    return fetch(`../api/get_available_drivers.php?order_id=${encodeURIComponent(orderId)}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (!Array.isArray(data)) {
            const err = (data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to load available drivers.';
            throw new Error(err);
        }

        if (data.length === 0) {
            driverSelect.innerHTML = '<option value="">-- No eligible riders with attendance for this delivery date --</option>';
            return;
        }

        driverSelect.innerHTML = '<option value="">-- Choose a rider --</option>';
        data.forEach(driver => {
            const option = document.createElement('option');
            option.value = String(driver.id);
            option.textContent = `${driver.first_name} ${driver.last_name} (${(driver.distance_km || 0).toFixed(1)} km, Rating ${(driver.avg_rating || 0).toFixed(1)})`;
            driverSelect.appendChild(option);
        });
    })
    .catch(error => {
        driverSelect.innerHTML = '<option value="">-- Unable to load riders --</option>';
        Swal.fire('Error', error.message || 'Failed to load eligible riders.', 'error');
    });
}

function openAssignDriverModal(trackingId, orderId) {
    document.getElementById('assignTrackingId').value = trackingId;
    document.getElementById('assignOrderId').value = orderId || '';
    loadEligibleDrivers(orderId).finally(() => {
        assignDriverModal.show();
    });
}

document.getElementById('assignDriverForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const trackingId = formData.get('tracking_id');

    fetch('assign_driver.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const assignModal = bootstrap.Modal.getInstance(document.getElementById('assignDriverModal'));
        if (assignModal) assignModal.hide();

        if (data.success) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message, showConfirmButton: false, timer: 2000 });

            const row = document.getElementById('logistics-row-logistics-' + trackingId);
            if (row) {
                row.querySelector('.driver-name').textContent = data.driver_name;
                const statusCell = row.querySelector('.status-badge');
                statusCell.className = 'status-badge badge-warning';
                statusCell.textContent = 'Assigned';
                
                // Keep button to allow re-assignment
                // const assignBtn = row.querySelector('button[onclick*="openAssignDriverModal"]');
                // if (assignBtn) assignBtn.remove();
            }
            
            // Reload page to update "Available Drivers" widget and sync state
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(error => Swal.fire('Error', 'An unexpected error occurred.', 'error'));
});

document.getElementById('updatePreOrderStatusForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const preOrderId = formData.get('pre_order_id');

    fetch('ajax_update_preorder.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('preorderStatusModal'));
        if (modal) modal.hide();
        Swal.fire({ toast: true, position: 'top-end', icon: data.success ? 'success' : 'error', title: data.message, showConfirmButton: false, timer: 2000 });
        if (data.success) {
            const row = document.getElementById('logistics-row-preorder-' + preOrderId);
            if (row) row.querySelector('.status-badge').textContent = data.new_status;
        }
    });
});
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($google_maps_api_key); ?>&libraries=places&loading=async&callback=onGoogleMapsApiLoaded" async defer></script>
<script>
let logisticsMap;
let driverMarkers = [];
let customerMarkers = [];
let routePolylines = [];
let infoWindow;
let googleMapsReady = false;
let locationRefreshTimer = null;
let hasAutoFittedMap = false;

function onGoogleMapsApiLoaded() {
    googleMapsReady = true;
}

function getInitials(name) {
    if (!name) return '?';
    const parts = name.split(' ').filter(Boolean);
    if (parts.length > 1) {
        return (parts[0][0] + (parts[parts.length - 1][0] || '')).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
}

function createMarkerIcon(initials, color = '#c62828') {
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
            <circle cx="20" cy="20" r="18" fill="${color}" stroke="#fff" stroke-width="2"/>
            <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="16" font-family="Arial, sans-serif" font-weight="bold">${initials}</text>
        </svg>`;
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function createCustomerMarkerIcon() {
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 42 42">
            <path d="M21 3c-7.2 0-13 5.8-13 13 0 10 13 23 13 23s13-13 13-23c0-7.2-5.8-13-13-13z" fill="#0d6efd" stroke="#fff" stroke-width="2"/>
            <circle cx="21" cy="16" r="5" fill="#fff"/>
        </svg>`;
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function parseCoordinate(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatTimestamp(value) {
    if (!value) return 'N/A';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'N/A';
    return date.toLocaleString();
}

function computeDistanceKm(a, b) {
    if (!a || !b) return null;
    const toRad = (deg) => deg * (Math.PI / 180);
    const lat1 = toRad(a.lat);
    const lon1 = toRad(a.lng);
    const lat2 = toRad(b.lat);
    const lon2 = toRad(b.lng);
    const dLat = lat2 - lat1;
    const dLon = lon2 - lon1;
    const h = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    const earthRadiusKm = 6371;
    return earthRadiusKm * c;
}

function formatEta(estimatedDelivery, distanceKm = null) {
    if (estimatedDelivery) {
        const etaDate = new Date(estimatedDelivery);
        if (!Number.isNaN(etaDate.getTime())) {
            const diffMs = etaDate.getTime() - Date.now();
            const diffMins = Math.round(diffMs / 60000);
            if (diffMins > 0) {
                return `${diffMins} min`;
            }
            return 'Arriving soon';
        }
    }

    if (distanceKm !== null) {
        // Fallback estimate at ~20 km/h city average for two-wheeler delivery.
        const estimatedMins = Math.max(3, Math.round((distanceKm / 20) * 60));
        return `~${estimatedMins} min`;
    }

    return 'N/A';
}

function initLogisticsMap() {
    if (!googleMapsReady || typeof google === 'undefined' || typeof google.maps === 'undefined') {
        console.warn("Google Maps API not loaded yet.");
        return;
    }
    logisticsMap = new google.maps.Map(document.getElementById('logisticsMap'), {
        center: { lat: 14.3294, lng: 120.9367 }, // Cavite
        zoom: 11,
        mapTypeControl: false,
        streetViewControl: false,
    });

    infoWindow = new google.maps.InfoWindow();

    fetchDriverLocations();
    if (!locationRefreshTimer) {
        locationRefreshTimer = setInterval(fetchDriverLocations, 15000); // Refresh every 15 seconds
    }
}

function clearMapOverlays() {
    driverMarkers.forEach(marker => marker.setMap(null));
    customerMarkers.forEach(marker => marker.setMap(null));
    routePolylines.forEach(line => line.setMap(null));
    driverMarkers = [];
    customerMarkers = [];
    routePolylines = [];
}

function fetchDriverLocations() {
    fetch('get_driver_locations.php')
        .then(response => response.json())
        .then(data => {
            if (!(data.success && Array.isArray(data.locations))) {
                return;
            }

            clearMapOverlays();

            const bounds = new google.maps.LatLngBounds();
            let hasAnyPoint = false;

            data.locations.forEach(loc => {
                const driverLat = parseCoordinate(loc.current_latitude);
                const driverLng = parseCoordinate(loc.current_longitude);
                if (driverLat === null || driverLng === null) {
                    return;
                }

                const driverPosition = { lat: driverLat, lng: driverLng };
                const initials = getInitials(loc.driver_name);
                const driverMarker = new google.maps.Marker({
                    position: driverPosition,
                    map: logisticsMap,
                    title: loc.driver_name || 'Driver',
                    icon: { url: createMarkerIcon(initials, '#c62828') }
                });

                driverMarkers.push(driverMarker);
                bounds.extend(driverPosition);
                hasAnyPoint = true;

                const customerLat = parseCoordinate(loc.customer_latitude);
                const customerLng = parseCoordinate(loc.customer_longitude);
                const hasCustomerCoordinates = customerLat !== null && customerLng !== null;
                const customerPosition = hasCustomerCoordinates ? { lat: customerLat, lng: customerLng } : null;

                let customerMarker = null;
                if (hasCustomerCoordinates) {
                    customerMarker = new google.maps.Marker({
                        position: customerPosition,
                        map: logisticsMap,
                        title: `Customer (${loc.order_number || 'N/A'})`,
                        icon: { url: createCustomerMarkerIcon() }
                    });
                    customerMarkers.push(customerMarker);
                    bounds.extend(customerPosition);

                    const routeLine = new google.maps.Polyline({
                        path: [driverPosition, customerPosition],
                        geodesic: true,
                        strokeColor: '#ff8f00',
                        strokeOpacity: 0.9,
                        strokeWeight: 3,
                        icons: [{
                            icon: {
                                path: 'M 0,-1 0,1',
                                strokeOpacity: 1,
                                scale: 3
                            },
                            offset: '0',
                            repeat: '12px'
                        }]
                    });
                    routeLine.setMap(logisticsMap);
                    routePolylines.push(routeLine);
                }

                const statusLabel = (loc.current_status || '').toString().replace(/_/g, ' ') || 'assigned';
                const distanceKm = hasCustomerCoordinates ? computeDistanceKm(driverPosition, customerPosition) : null;
                const etaLabel = formatEta(loc.estimated_delivery, distanceKm);
                const dropOffLabel = loc.estimated_delivery ? formatTimestamp(loc.estimated_delivery) : 'N/A';
                const distanceLabel = distanceKm !== null ? `${distanceKm.toFixed(2)} km` : 'N/A';
                const infoHtml = `
                    <div style="font-family: Arial, sans-serif; font-size: 14px; max-width: 290px;">
                        <h6 style="margin:0 0 6px 0; color:#c62828;">${loc.driver_name || 'Driver'}</h6>
                        <div><strong>Order:</strong> #${loc.order_number || 'N/A'}</div>
                        <div><strong>Status:</strong> ${statusLabel}</div>
                        <div><strong>ETA:</strong> ${etaLabel}</div>
                        <div><strong>Drop-off Time:</strong> ${dropOffLabel}</div>
                        <div><strong>Distance to Customer:</strong> ${distanceLabel}</div>
                        <div style="font-size:12px; color:#666; margin-top:4px;"><strong>Destination:</strong> ${loc.delivery_address || 'N/A'}</div>
                        <div style="font-size:11px; color:#999; margin-top:5px;">Last update: ${formatTimestamp(loc.last_location_update)}</div>
                    </div>`;

                const openInfo = (anchor) => {
                    infoWindow.setContent(infoHtml);
                    infoWindow.open(logisticsMap, anchor);
                };

                driverMarker.addListener('click', () => openInfo(driverMarker));
                if (customerMarker) {
                    customerMarker.addListener('click', () => openInfo(customerMarker));
                }
            });

            if (hasAnyPoint && (!hasAutoFittedMap || logisticsMap.getZoom() <= 6)) {
                logisticsMap.fitBounds(bounds, 60);
                hasAutoFittedMap = true;
            } else if (!hasAnyPoint) {
                logisticsMap.setCenter({ lat: 14.5995, lng: 120.9842 });
                logisticsMap.setZoom(11);
            }
        }).catch(err => console.error("Failed to fetch driver locations:", err));
}

function waitForGoogleMapsThenInit(attempts = 0) {
    if (googleMapsReady && typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
        if (!logisticsMap) initLogisticsMap();
        return;
    }

    if (attempts > 20) {
        const mapContainer = document.getElementById('logisticsMap');
        if (mapContainer) {
            mapContainer.innerHTML = '<div class="alert alert-warning">Map is taking longer than expected to load. Please disable ad blockers for this page or refresh.</div>';
        }
        return;
    }

    setTimeout(() => waitForGoogleMapsThenInit(attempts + 1), 250);
}

const mapViewTabTrigger = document.querySelector('a[data-bs-toggle="tab"][href="#mapView"]');
if (mapViewTabTrigger) {
    mapViewTabTrigger.addEventListener('shown.bs.tab', () => {
        waitForGoogleMapsThenInit();
    });
}
</script>
</body>
</html>

