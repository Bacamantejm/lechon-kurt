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

                <!-- Filter Bar -->
                <div class="section-header">
                    <h2>All Pre-Orders</h2>
                    <form method="GET" class="filter-form">
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
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
