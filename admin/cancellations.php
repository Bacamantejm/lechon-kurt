<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once '../includes/partner_order_policy_helper.php';
requirePermission('orders.view');
popEnsurePolicySchema($conn);

$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_cancellation_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_cancellation_scope_sql = "c.order_id IS NOT NULL AND EXISTS (
        SELECT 1
        FROM order_items oi_scope
        INNER JOIN products p_scope
            ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
        WHERE oi_scope.order_id = c.order_id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}
// This page is monitoring-focused.
// Final finance decisions (approve/reject/complete) are handled in admin/finance.php.

// Get statistics for cards
$current_month = date('m');
$current_year = date('Y');

// 1. Pending Refunds
$pending_refunds_query = "SELECT COUNT(*) as count
                          FROM refunds r
                          INNER JOIN cancellations c ON c.id = r.cancellation_id
                          WHERE r.refund_status = 'Refund Pending'" . ($seller_scope_id !== null ? " AND {$partner_cancellation_scope_sql}" : "");
$pending_refunds_result = mysqli_query($conn, $pending_refunds_query);
$pending_refunds_count = mysqli_fetch_assoc($pending_refunds_result)['count'] ?? 0;

// 2. Total Refunded this Month
$refunded_month_query = "SELECT SUM(r.refund_amount) as total
                         FROM refunds r
                         INNER JOIN cancellations c ON c.id = r.cancellation_id
                         WHERE r.refund_status = 'Refund Approved'
                           AND MONTH(r.processed_date) = $current_month
                           AND YEAR(r.processed_date) = $current_year" . ($seller_scope_id !== null ? " AND {$partner_cancellation_scope_sql}" : "");
$refunded_month_result = mysqli_query($conn, $refunded_month_query);
$refunded_this_month = mysqli_fetch_assoc($refunded_month_result)['total'] ?? 0;

// 3. Total Cancellations This Month
$cancellations_month_query = "SELECT COUNT(*) as count
                              FROM cancellations c
                              WHERE MONTH(c.cancellation_date) = $current_month
                                AND YEAR(c.cancellation_date) = $current_year" . ($seller_scope_id !== null ? " AND {$partner_cancellation_scope_sql}" : "");
$cancellations_month_result = mysqli_query($conn, $cancellations_month_query);
$cancellations_this_month = mysqli_fetch_assoc($cancellations_month_result)['count'] ?? 0;

// 4. Total Refunded (All Time) - Approved refunds only
$total_refunded_query = "SELECT SUM(r.refund_amount) as total
                         FROM refunds r
                         INNER JOIN cancellations c ON c.id = r.cancellation_id
                         WHERE r.refund_status = 'Refund Approved'" . ($seller_scope_id !== null ? " AND {$partner_cancellation_scope_sql}" : "");
$total_refunded_result = mysqli_query($conn, $total_refunded_query);
$total_refunded = mysqli_fetch_assoc($total_refunded_result)['total'] ?? 0;

// Pagination settings
$records_per_page = 15;
$current_page_number = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page_number - 1) * $records_per_page;

// Filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$cancellation_status_filter = isset($_GET['cancellation_status']) ? $_GET['cancellation_status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_clauses = [];
if ($seller_scope_id !== null) {
    $where_clauses[] = $partner_cancellation_scope_sql;
}
if ($status_filter) {
    $where_clauses[] = "r.refund_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if ($cancellation_status_filter) {
    $where_clauses[] = "c.status = '" . mysqli_real_escape_string($conn, $cancellation_status_filter) . "'";
}
if ($search) {
    $where_clauses[] = "(u.full_name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR c.order_id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR c.reservation_id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
}
$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM cancellations c LEFT JOIN refunds r ON c.id = r.cancellation_id LEFT JOIN users u ON c.user_id = u.id $where_sql";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch Cancellations & Refunds
$query = "
    SELECT 
        c.id as cancellation_id,
        o.status as order_status,
        c.cancellation_date,
        c.reason,
        c.other_reason_text,
        c.status as cancellation_status,
        c.rejection_reason,
        c.order_id,
        c.reservation_id,
        c.service_request_id,
        o.order_number,
        u.full_name,
        r.id as refund_id,
        r.refund_amount,
        r.refund_status,
        r.refund_reason,
        r.customer_evidence_path
    FROM cancellations c
    LEFT JOIN refunds r ON c.id = r.cancellation_id
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN orders o ON c.order_id = o.id
    $where_sql
    ORDER BY c.cancellation_date DESC
    LIMIT $records_per_page OFFSET $offset
";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellations & Refunds | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles from orders.php */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode { background-color: var(--bg-color-dark) !important; color: var(--text-color-dark) !important; }
        body.dark-mode .admin-content, body.dark-mode .admin-container { background-color: var(--bg-color-dark) !important; }
        body.dark-mode .admin-topbar, body.dark-mode .stat-card, body.dark-mode .card, body.dark-mode .card-header, body.dark-mode .card-body, body.dark-mode .admin-table, body.dark-mode .admin-table th, body.dark-mode .admin-table td, body.dark-mode .modal-content, body.dark-mode .modal-header, body.dark-mode .modal-footer, body.dark-mode .form-control, body.dark-mode .form-select { background-color: var(--card-bg-dark) !important; color: var(--text-color-dark) !important; border-color: var(--border-color-dark) !important; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode h4, body.dark-mode h5, body.dark-mode h6, body.dark-mode strong, body.dark-mode b, body.dark-mode .admin-topbar h1, body.dark-mode label, body.dark-mode .modal-title { color: var(--text-color-dark) !important; }
        body.dark-mode .text-muted, body.dark-mode .small { color: #b0b0b0 !important; }
        body.dark-mode .table-responsive { background-color: var(--card-bg-dark) !important; border-color: var(--border-color-dark) !important; }
        .theme-toggler { background: none; border: none; color: #666; font-size: 1.2rem; cursor: pointer; margin: 0 15px; padding: 5px; transition: color 0.3s; }
        body.dark-mode .theme-toggler { color: #ffc107; }
        .pagination-container { display: flex; justify-content: center; margin-top: 20px; }
        .pagination { --bs-pagination-color: #c62828; --bs-pagination-hover-color: #a71c1c; --bs-pagination-active-bg: #c62828; --bs-pagination-active-border-color: #c62828; }
        body.dark-mode .pagination { --bs-pagination-bg: var(--card-bg-dark); --bs-pagination-border-color: var(--border-color-dark); --bs-pagination-hover-bg: #3d3d3d; }
    </style>
</head>
<body class="admin-polish cancellations-page">
    <div class="page-loader"><div class="spinner"></div></div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Cancellations & Refunds</h1>
                    <div class="topbar-right">
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                            <i class="fas fa-moon"></i>
                        </button>
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
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $pending_refunds_count ?? 0; ?>">0</h3>
                            <p>Pending Refunds</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green">
                            <i class="fas fa-hand-holding-dollar"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $refunded_this_month ?? 0; ?>" data-format-currency="true">₱0.00</h3>
                            <p>Refunded This Month</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon red">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $cancellations_this_month ?? 0; ?>">0</h3>
                            <p>Cancellations This Month</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon purple">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $total_refunded ?? 0; ?>" data-format-currency="true">₱0.00</h3>
                            <p>Total Refunded (All Time)</p>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong>Workflow Update:</strong> This page is for monitoring requests.
                        All approve/reject/complete decisions are now handled in Finance Decision Center.
                    </div>
                    <?php if ($seller_scope_id === null): ?>
                        <a href="finance.php#decision-center" class="btn btn-sm btn-primary">Open Finance Decision Center</a>
                    <?php else: ?>
                        <span class="badge bg-secondary">Finance Team Handles Final Decisions</span>
                    <?php endif; ?>
                </div>

                <div class="section-header">
                    <h2>Cancellations & Refunds Monitoring</h2>
                    <form method="GET" class="filter-form">
                        <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All Refund Statuses</option>
                            <option value="Refund Pending" <?php echo $status_filter === 'Refund Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Refund Approved" <?php echo $status_filter === 'Refund Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Refund Completed" <?php echo $status_filter === 'Refund Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Refund Rejected" <?php echo $status_filter === 'Refund Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                        <select name="cancellation_status" class="form-select" onchange="this.form.submit()">
                            <option value="">All Request Statuses</option>
                            <option value="Requested" <?php echo $cancellation_status_filter === 'Requested' ? 'selected' : ''; ?>>Requested</option>
                            <option value="Cancelled" <?php echo $cancellation_status_filter === 'Cancelled' ? 'selected' : ''; ?>>Approved/Cancelled</option>
                            <option value="Rejected" <?php echo $cancellation_status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </form>
                </div>
                
                <div class="table-responsive fade-in-up">
                    <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Reference</th>
                                    <th>Reason</th>
                                    <th>Request Status</th>
                                    <th>Order Status</th>
                                    <th>Refund Amount</th>
                                    <th>Refund Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = mysqli_fetch_assoc($result)): 
                                    $refund_status_class = '';
                                    if ($row['refund_status'] == 'Refund Approved') $refund_status_class = 'badge-success';
                                    elseif ($row['refund_status'] == 'Refund Rejected') $refund_status_class = 'badge-danger';
                                    elseif ($row['refund_status'] == 'Refund Pending') $refund_status_class = 'badge-warning';
                                    elseif ($row['refund_status'] == 'Refund Completed') $refund_status_class = 'badge-info';
                                ?>
                                <tr class="<?php echo $row['cancellation_status'] === 'Requested' ? 'table-warning' : ''; ?>">
                                    <td>#<?php echo $row['cancellation_id']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['cancellation_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td>
                                        <?php if($row['order_number']): ?>
                                            <a href="orders.php?search=<?php echo $row['order_number']; ?>"><?php echo $row['order_number']; ?></a>
                                        <?php elseif($row['reservation_id']): ?>
                                            <span>Pre-Order #<?php echo (int)$row['reservation_id']; ?></span>
                                        <?php elseif($row['service_request_id']): ?>
                                            <span>Service #<?php echo (int)$row['service_request_id']; ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['reason']); ?>
                                        <?php if($row['other_reason_text']) echo "<br><small class='text-muted'>".htmlspecialchars($row['other_reason_text'])."</small>"; ?>
                                        <?php if (!empty($row['refund_reason'])): ?>
                                            <div class="mt-2">
                                                <strong class="small text-warning">Refund Reason:</strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars((string)$row['refund_reason']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['customer_evidence_path'])): ?>
                                            <div class="mt-2">
                                                <a href="../<?php echo htmlspecialchars((string)$row['customer_evidence_path']); ?>" target="_blank" class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-image"></i> View Proof
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if($row['rejection_reason']): ?>
                                            <div class="mt-2 pt-2 border-top">
                                                <strong class="text-danger small">Rejection Reason:</strong><br>
                                                <small class="text-muted fst-italic">"<?php echo htmlspecialchars($row['rejection_reason']); ?>"</small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-badge badge-<?php echo strtolower($row['cancellation_status']); ?>"><?php echo $row['cancellation_status']; ?></span></td>
                                    <td>
                                        <?php if (!empty($row['order_status'])): ?>
                                            <span class="status-badge badge-<?php echo str_replace('_', '-', $row['order_status']); ?>"><?php echo ucwords(str_replace('_', '-', $row['order_status'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $row['refund_amount'] ? '₱'.number_format($row['refund_amount'], 2) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php if($row['refund_status']): ?>
                                            <span class="status-badge <?php echo $refund_status_class; ?>">
                                                <?php echo $row['refund_status']; ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (
                                            $row['cancellation_status'] === 'Requested' ||
                                            ($row['refund_id'] && in_array($row['refund_status'], ['Refund Pending', 'Refund Approved'], true))
                                        ): ?>
                                            <?php if ($seller_scope_id === null): ?>
                                                <a href="finance.php?decision_search=<?= urlencode((string)$row['cancellation_id']) ?>#decision-center" class="btn btn-sm btn-outline-primary">
                                                    Send to Finance
                                                </a>
                                                <small class="d-block text-muted mt-1">Final approval/rejection is handled by Finance.</small>
                                            <?php else: ?>
                                                <span class="text-muted">Submitted to Finance queue</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                </div>

                <!-- Pagination -->
                <div class="pagination-container">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($current_page_number > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $current_page_number - 1; ?>&status=<?php echo urlencode($status_filter); ?>&cancellation_status=<?php echo urlencode($cancellation_status_filter); ?>&search=<?php echo urlencode($search); ?>">Previous</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo ($i == $current_page_number) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&cancellation_status=<?php echo urlencode($cancellation_status_filter); ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>
                            <?php if ($current_page_number < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $current_page_number + 1; ?>&status=<?php echo urlencode($status_filter); ?>&cancellation_status=<?php echo urlencode($cancellation_status_filter); ?>&search=<?php echo urlencode($search); ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
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

        // Add some styles for the new statuses
        const style = document.createElement('style');
        style.innerHTML = `.status-badge.badge-requested { background-color: #fff3cd; color: #856404; } .status-badge.badge-cancellation_requested { background-color: #f8d7da; color: #721c24; } .status-badge.badge-info { background-color: #d1ecf1; color: #0c5460; }`;
        document.head.appendChild(style);

        // Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({ icon: 'success', title: 'Success', text: <?php echo json_encode($_SESSION['success']); ?>, timer: 2000, showConfirmButton: false });
        <?php unset($_SESSION['success']); endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({ icon: 'error', title: 'Error', text: <?php echo json_encode($_SESSION['error']); ?> });
        <?php unset($_SESSION['error']); endif; ?>

        // Finance decisions are now handled in admin/finance.php (Decision Center).
    </script>
</body>
</html>
