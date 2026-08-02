<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_user_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
if ($is_super_admin_user && basename($_SERVER['PHP_SELF'] ?? '') === 'index.php') {
    header('Location: ../super_admin/super_admin_dashboard.php');
    exit;
}
$expenses_has_recorded_by = false;
$expenses_recorded_by_check = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'recorded_by'");
if ($expenses_recorded_by_check && mysqli_num_rows($expenses_recorded_by_check) > 0) {
    $expenses_has_recorded_by = true;
}

$dss_overview = [
    'total_revenue' => 0,
    'total_expenses' => 0,
    'net_income' => 0,
    'net_margin' => 0,
    'records_analyzed' => 0
];
$dss_trend = ['forecast_accuracy' => 0, 'mape' => 0];
$dss_forecast_summary = ['predicted_revenue' => 0, 'avg_confidence' => 0];
$dss_inventory_pressure = [];
$dss_event_insights = ['upcoming_events' => [], 'high_impact_events' => 0];
$dss_decision_brief = [];
$dss_top_products = [];

if ($seller_scope_id === null) {
    $insights_service = new DSSInsightsService($conn);
    $dss_overview = $insights_service->getOverviewMetrics(30);
    $dss_trend = $insights_service->getRevenueTrend(14);
    $dss_forecast_summary = $insights_service->getForecastingSummary(7);
    $dss_inventory_pressure = $insights_service->getInventoryPressure(7, 6);
    $dss_event_insights = $insights_service->getEventImpactInsights(45);
    $dss_decision_brief = $insights_service->generateDecisionBrief(30);
    $dss_top_products = $insights_service->getTopProducts(30, 5);
} else {
    $seller_scope_expr = "
        EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
            WHERE oi_scope.order_id = o.id
              AND p_scope.seller_id = " . (int)$seller_scope_id . "
        )";

    $overview_query = "SELECT
                           COALESCE(SUM(o.total_amount), 0) AS total_revenue,
                           COUNT(*) AS records_analyzed
                       FROM orders o
                       WHERE o.is_archived = 0
                         AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')
                         AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         AND {$seller_scope_expr}";
    $overview_result = mysqli_query($conn, $overview_query);
    $overview_row = $overview_result ? mysqli_fetch_assoc($overview_result) : null;
    $dss_overview['total_revenue'] = (float)($overview_row['total_revenue'] ?? 0);
    $dss_overview['records_analyzed'] = (int)($overview_row['records_analyzed'] ?? 0);
    $dss_overview['net_income'] = $dss_overview['total_revenue'];
    $dss_overview['net_margin'] = $dss_overview['total_revenue'] > 0 ? 100 : 0;

    $dss_top_query = "SELECT p.name AS product_name, COALESCE(SUM(oi.quantity), 0) AS quantity
                      FROM products p
                      LEFT JOIN order_items oi
                        ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                      LEFT JOIN orders o
                        ON oi.order_id = o.id
                       AND o.is_archived = 0
                       AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')
                       AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      WHERE p.seller_id = " . (int)$seller_scope_id . "
                        AND p.is_archived = 0
                      GROUP BY p.id, p.name
                      ORDER BY quantity DESC
                      LIMIT 5";
    $dss_top_result = mysqli_query($conn, $dss_top_query);
    if ($dss_top_result) {
        while ($top_row = mysqli_fetch_assoc($dss_top_result)) {
            $dss_top_products[] = $top_row;
        }
    }

    $dss_inventory_query = "SELECT p.name AS product_name,
                                   COALESCE(i.current_stock, 0) AS stock,
                                   0 AS forecast_demand,
                                   CASE
                                       WHEN COALESCE(i.current_stock, 0) <= 0 THEN 'critical'
                                       WHEN COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 'high'
                                       ELSE 'low'
                                   END AS severity
                            FROM products p
                            LEFT JOIN inventory i
                              ON p.id = i.product_id
                             AND i.inventory_date = CURDATE()
                             AND i.is_archived = 0
                            WHERE p.seller_id = " . (int)$seller_scope_id . "
                              AND p.is_archived = 0
                            ORDER BY
                              CASE
                                  WHEN COALESCE(i.current_stock, 0) <= 0 THEN 1
                                  WHEN COALESCE(i.current_stock, 0) <= COALESCE(i.min_stock_level, 5) THEN 2
                                  ELSE 3
                              END,
                              p.name
                            LIMIT 6";
    $dss_inventory_result = mysqli_query($conn, $dss_inventory_query);
    if ($dss_inventory_result) {
        while ($inv_row = mysqli_fetch_assoc($dss_inventory_result)) {
            $dss_inventory_pressure[] = $inv_row;
        }
    }

    $dss_decision_brief[] = [
        'headline' => 'Store Scope Enabled',
        'action' => 'Dashboard data is filtered to your store products only.',
        'priority' => 'medium'
    ];
}

$critical_inventory_count = count(array_filter($dss_inventory_pressure, function ($item) {
    return in_array($item['severity'], ['critical', 'high'], true);
}));
$upcoming_event_count = count($dss_event_insights['upcoming_events'] ?? []);
$high_impact_event_count = (int)($dss_event_insights['high_impact_events'] ?? 0);

$pending_decisions = 0;
$implemented_30d = 0;
if ($seller_scope_id === null) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'decisions_recommendations'");
    if ($table_check && mysqli_num_rows($table_check) > 0) {
        $pending_result = mysqli_query($conn, "SELECT COUNT(*) AS c FROM decisions_recommendations WHERE status = 'pending'");
        if ($pending_result) {
            $row = mysqli_fetch_assoc($pending_result);
            $pending_decisions = (int)($row['c'] ?? 0);
        }
        $implemented_result = mysqli_query($conn, "SELECT COUNT(*) AS c FROM decisions_recommendations WHERE status = 'implemented' AND recommendation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        if ($implemented_result) {
            $row = mysqli_fetch_assoc($implemented_result);
            $implemented_30d = (int)($row['c'] ?? 0);
        }
    }
}

$seller_scoped_orders_exists = '';
if ($seller_scope_id !== null) {
    $seller_scoped_orders_exists = "
        EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
            WHERE oi_scope.order_id = o.id
              AND p_scope.seller_id = " . (int)$seller_scope_id . "
        )";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        body.dark-mode .recent-section,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td {
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

        body.dark-mode .text-muted {
            color: #b0b0b0 !important;
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

        /* Dashboard topbar alignment fix */
        .admin-topbar .topbar-content {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
        }

        .admin-topbar .topbar-right {
            margin-left: auto;
            display: flex !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
            gap: 10px;
            white-space: nowrap;
        }

        .admin-topbar .topbar-right .admin-header-actions {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 8px;
        }

        .admin-topbar .topbar-right .theme-toggler {
            margin: 0 !important;
            flex: 0 0 auto;
        }

        .dashboard-hub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .hub-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            background: #fff;
            height: 100%;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
        }

        .hub-card h6 {
            margin: 0 0 8px 0;
            font-weight: 700;
            color: #1f2937;
        }

        .hub-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.2;
            margin-bottom: 6px;
        }

        .hub-sub {
            font-size: 0.82rem;
            color: #6b7280;
            margin-bottom: 10px;
        }

        .hub-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .hub-badges .badge {
            font-size: 0.72rem;
            font-weight: 600;
        }

        .hub-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hub-section-card .card-header {
            border-bottom: 1px solid #eef2f7;
        }

        .insight-list-item {
            padding: 10px 0;
            border-bottom: 1px solid #eef2f7;
        }

        .insight-list-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        body.dark-mode .hub-card {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .hub-card h6,
        body.dark-mode .hub-value {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .hub-sub {
            color: #b0b0b0 !important;
        }

        .franchise-priority-card {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: stretch;
            gap: 16px;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 4px;
            border: 1px solid #f0d3d9;
            background: linear-gradient(135deg, #fff5f8 0%, #fff 100%);
            box-shadow: 0 8px 24px rgba(194, 24, 91, 0.08);
        }

        .franchise-priority-card.all-clear {
            border-color: #c9ead7;
            background: linear-gradient(135deg, #f2fff7 0%, #fff 100%);
            box-shadow: 0 8px 24px rgba(20, 128, 61, 0.08);
        }

        .franchise-priority-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }

        .franchise-priority-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #c2185b;
            color: #fff;
            flex: 0 0 auto;
            box-shadow: 0 8px 16px rgba(194, 24, 91, 0.28);
        }

        .franchise-priority-card.all-clear .franchise-priority-icon {
            background: #15803d;
            box-shadow: 0 8px 16px rgba(21, 128, 61, 0.25);
        }

        .franchise-priority-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .franchise-pending-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            background: #ffe5ef;
            color: #9f1239;
            border: 1px solid #f8b4cc;
        }

        .franchise-priority-card.all-clear .franchise-pending-pill {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .franchise-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e11d48;
            animation: franchisePulse 1.3s infinite;
        }

        .franchise-priority-card.all-clear .franchise-pulse-dot {
            background: #16a34a;
            animation: none;
        }

        @keyframes franchisePulse {
            0% { transform: scale(1); opacity: 1; }
            70% { transform: scale(1.7); opacity: 0.2; }
            100% { transform: scale(1); opacity: 1; }
        }

        .franchise-priority-sub {
            margin: 6px 0 0;
            color: #6b7280;
            font-size: 0.86rem;
        }

        .franchise-priority-list {
            margin: 10px 0 0;
            padding-left: 18px;
            color: #4b5563;
            font-size: 0.82rem;
        }

        .franchise-priority-list li {
            margin-bottom: 4px;
        }

        .franchise-priority-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            align-self: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .franchise-priority-actions .btn {
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.82rem;
            padding: 7px 12px;
        }

        @media (max-width: 992px) {
            .franchise-priority-card {
                flex-direction: column;
                gap: 12px;
            }

            .franchise-priority-actions {
                justify-content: flex-start;
            }
        }

        body.dark-mode .franchise-priority-card {
            border-color: #5f2f43 !important;
            background: linear-gradient(135deg, #3f2330 0%, #2d2d2d 100%) !important;
            box-shadow: none !important;
        }

        body.dark-mode .franchise-priority-card.all-clear {
            border-color: #2f5a43 !important;
            background: linear-gradient(135deg, #1f3a2e 0%, #2d2d2d 100%) !important;
        }

        body.dark-mode .franchise-priority-title,
        body.dark-mode .franchise-priority-sub,
        body.dark-mode .franchise-priority-list {
            color: #e0e0e0 !important;
        }
    </style>
</head>
<body>
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <!-- Top Navigation Bar -->
            <div class="admin-topbar">
                
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Dashboard</h1>
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
            
            <!-- Main Content -->
            <div class="admin-main">
                <div class="dashboard-header">
                    <h2>Welcome, <?php echo htmlspecialchars($admin_info['full_name']); ?></h2>
                    <p>Monitor your business metrics and manage operations</p>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <?php
                    $current_month = date('m');
                    $current_year = date('Y');

                    // Get today's orders
                    $today_query = "SELECT COUNT(*) as count, SUM(total_amount) as total
                                    FROM orders o
                                    WHERE DATE(o.created_at) = CURDATE()
                                      AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
                    $today_result = mysqli_query($conn, $today_query);
                    $today_stats = mysqli_fetch_assoc($today_result);
                    
                    // Get total revenue (this month)
                    $revenue_query = "SELECT SUM(o.total_amount) as total
                                      FROM orders o
                                      WHERE MONTH(o.created_at) = $current_month
                                        AND YEAR(o.created_at) = $current_year
                                        AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed')
                                        AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "");
                    $revenue_result = mysqli_query($conn, $revenue_query);
                    $revenue_stats = mysqli_fetch_assoc($revenue_result);
                    $monthly_revenue = $revenue_stats['total'] ?? 0;

                    // Get total expenses (this month)
                    $expenses_query = "SELECT SUM(amount) as total
                                       FROM expenses
                                       WHERE MONTH(expense_date) = $current_month
                                         AND YEAR(expense_date) = $current_year";
                    if ($seller_scope_id !== null) {
                        $expenses_query .= $expenses_has_recorded_by
                            ? (" AND recorded_by = " . (int)$seller_scope_id)
                            : " AND 1 = 0";
                    }
                    $expenses_result = mysqli_query($conn, $expenses_query);
                    $expenses_stats = mysqli_fetch_assoc($expenses_result);
                    $monthly_expenses = $expenses_stats['total'] ?? 0;

                    // Net Income
                    $net_income = $monthly_revenue - $monthly_expenses;
                    
                    $franchise_stats = ['count' => 0];
                    $franchise_pending_count = 0;
                    $franchise_recent_pending = [];
                    if ($is_super_admin_user) {
                        // Only super admin can review business applications.
                        $franchise_query = "SELECT COUNT(*) as count FROM franchise_applications WHERE status = 'pending'";
                        $franchise_result = mysqli_query($conn, $franchise_query);
                        $franchise_stats = $franchise_result ? mysqli_fetch_assoc($franchise_result) : ['count' => 0];
                        $franchise_pending_count = (int)($franchise_stats['count'] ?? 0);

                        if ($franchise_pending_count > 0) {
                            $franchise_recent_query = "SELECT fa.id, fa.application_number, fa.business_name, fa.created_at, u.full_name
                                                       FROM franchise_applications fa
                                                       LEFT JOIN users u ON u.id = fa.user_id
                                                       WHERE fa.status = 'pending'
                                                       ORDER BY fa.created_at DESC
                                                       LIMIT 3";
                            $franchise_recent_result = mysqli_query($conn, $franchise_recent_query);
                            if ($franchise_recent_result) {
                                while ($pending_row = mysqli_fetch_assoc($franchise_recent_result)) {
                                    $franchise_recent_pending[] = $pending_row;
                                }
                            }
                        }
                    }

                    // Get active deliveries
                    $logistics_query = $seller_scope_id === null
                        ? "SELECT COUNT(*) as count FROM logistics_tracking WHERE current_status IN ('pending', 'assigned', 'picked_up', 'on_the_way', 'arriving')"
                        : "SELECT COUNT(*) as count
                           FROM logistics_tracking lt
                           INNER JOIN orders o ON o.id = lt.order_id
                           WHERE lt.current_status IN ('pending', 'assigned', 'picked_up', 'on_the_way', 'arriving')
                             AND {$seller_scoped_orders_exists}";
                    $logistics_result = mysqli_query($conn, $logistics_query);
                    $logistics_stats = mysqli_fetch_assoc($logistics_result);

                    // Get pending pre-orders
                    $preorder_query = $seller_scope_id === null
                        ? "SELECT COUNT(*) as count FROM pre_orders WHERE reservation_status = 'pending'"
                        : "SELECT 0 as count";
                    $preorder_result = mysqli_query($conn, $preorder_query);
                    $preorder_stats = mysqli_fetch_assoc($preorder_result);

                    // Get low stock inventory
                    $inventory_query = "SELECT COUNT(*) as count
                                        FROM inventory i
                                        INNER JOIN products p ON p.id = i.product_id
                                        WHERE i.current_stock <= i.min_stock_level" . ($seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "");
                    $inventory_result = mysqli_query($conn, $inventory_query);
                    $inventory_stats = mysqli_fetch_assoc($inventory_result);

                    // Get active products
                    $products_query = "SELECT COUNT(*) as count
                                       FROM products
                                       WHERE is_active = 1
                                         AND is_archived = 0" . ($seller_scope_id !== null ? " AND seller_id = " . (int)$seller_scope_id : "");
                    $products_result = mysqli_query($conn, $products_query);
                    $products_stats = mysqli_fetch_assoc($products_result);

                    // Get Daily Trend (Last 7 Days)
                    $daily_trend_query = "SELECT DATE(o.created_at) as date, COUNT(*) as count, SUM(o.total_amount) as total 
                                          FROM orders o
                                          WHERE DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                                            AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "") . "
                                          GROUP BY DATE(o.created_at)";
                    $daily_trend_result = mysqli_query($conn, $daily_trend_query);
                    
                    $chart_dates = [];
                    $chart_orders = [];
                    $chart_revenue = [];
                    
                    for ($i = 6; $i >= 0; $i--) {
                        $d = date('Y-m-d', strtotime("-$i days"));
                        $chart_dates[$d] = date('M d', strtotime($d));
                        $chart_orders[$d] = 0;
                        $chart_revenue[$d] = 0;
                    }
                    
                    while ($row = mysqli_fetch_assoc($daily_trend_result)) {
                        $chart_orders[$row['date']] = $row['count'];
                        $chart_revenue[$row['date']] = $row['total'];
                    }

                    // Get Monthly Revenue Trend
                    $monthly_trend_query = "SELECT MONTH(o.created_at) as month, SUM(o.total_amount) as total 
                                            FROM orders o
                                            WHERE YEAR(o.created_at) = YEAR(CURDATE()) 
                                              AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed') 
                                              AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "") . "
                                            GROUP BY MONTH(o.created_at)";
                    $monthly_trend_result = mysqli_query($conn, $monthly_trend_query);
                    
                    $monthly_data = array_fill(1, 12, 0);
                    while ($row = mysqli_fetch_assoc($monthly_trend_result)) {
                        $monthly_data[$row['month']] = $row['total'];
                    }

                    // Get Top 5 Products (Last 7 Days)
                    $top_products_7days_query = "SELECT oi.product_name, SUM(oi.quantity) as total_qty 
                                                 FROM order_items oi 
                                                 JOIN orders o ON oi.order_id = o.id
                                                 LEFT JOIN products p
                                                   ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                                                 WHERE o.is_archived = 0 
                                                   AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed') 
                                                   AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . ($seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "") . "
                                                 GROUP BY oi.product_name 
                                                 ORDER BY total_qty DESC 
                                                 LIMIT 5";
                    $top_products_7days_result = mysqli_query($conn, $top_products_7days_query);

                    // Get Top 5 Products (This Month)
                    $top_products_month_query = "SELECT oi.product_name, SUM(oi.quantity) as total_qty 
                                                 FROM order_items oi 
                                                 JOIN orders o ON oi.order_id = o.id
                                                 LEFT JOIN products p
                                                   ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                                                 WHERE o.is_archived = 0 
                                                   AND o.status IN ('confirmed', 'preparing', 'delivered', 'completed') 
                                                   AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())" . ($seller_scope_id !== null ? " AND p.seller_id = " . (int)$seller_scope_id : "") . "
                                                 GROUP BY oi.product_name 
                                                 ORDER BY total_qty DESC 
                                                 LIMIT 5";
                    $top_products_month_result = mysqli_query($conn, $top_products_month_query);
                    ?>

                    <?php if ($is_super_admin_user): ?>
                        <!-- Franchise Priority Card -->
                        <div class="franchise-priority-card fade-in-up <?php echo $franchise_pending_count > 0 ? 'has-pending' : 'all-clear'; ?>">
                            <div class="franchise-priority-main">
                                <div class="franchise-priority-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div>
                                    <h4 class="franchise-priority-title">
                                        Franchise Application Queue
                                        <span class="franchise-pending-pill">
                                            <span class="franchise-pulse-dot"></span>
                                            <?php echo $franchise_pending_count; ?> Pending
                                        </span>
                                    </h4>

                                    <?php if ($franchise_pending_count > 0): ?>
                                        <p class="franchise-priority-sub">
                                            New partner applications require review. Process pending submissions to keep onboarding fast.
                                        </p>
                                        <?php if (!empty($franchise_recent_pending)): ?>
                                            <ul class="franchise-priority-list">
                                                <?php foreach ($franchise_recent_pending as $pending_item): ?>
                                                    <li>
                                                        <strong><?php echo htmlspecialchars($pending_item['application_number']); ?></strong>
                                                        - <?php echo htmlspecialchars($pending_item['business_name'] ?: ($pending_item['full_name'] ?? 'Applicant')); ?>
                                                        (<?php echo date('M d, g:i a', strtotime($pending_item['created_at'])); ?>)
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="franchise-priority-sub">No pending franchise applications right now. Great job staying updated.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="franchise-priority-actions">
                                <a href="../super_admin/franchise_applications.php?status=pending" class="btn btn-danger">
                                    <i class="fas fa-eye"></i> Review Pending
                                </a>
                                <a href="../super_admin/franchise_applications.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-list"></i> View All
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                     
                    <!-- Order Management -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #e3f2fd;">
                            <i class="fas fa-shopping-cart" style="color: #1976d2;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $today_stats['count'] ?? 0; ?>">0</h3>
                            <p>Orders Today</p>
                        </div>
                        <a href="orders.php" class="stat-value" style="color: #1976d2;">Manage</a>
                    </div>
                    
                    <!-- Financial Overview -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #e8f5e9;">
                            <i class="fas fa-coins" style="color: #388e3c;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $monthly_revenue; ?>" data-format-currency="true">₱0.00</h3>
                            <p>Revenue (Month)</p>
                        </div>
                        <a href="finance.php" class="stat-value" style="color: #388e3c;">View</a>
                    </div>
                    
                    <!-- Expenses -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #ffebee;">
                            <i class="fas fa-file-invoice-dollar" style="color: #d32f2f;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $monthly_expenses; ?>" data-format-currency="true">₱0.00</h3>
                            <p>Expenses (Month)</p>
                        </div>
                        <a href="expenses.php" class="stat-value" style="color: #d32f2f;">Manage</a>
                    </div>
                    
                    <!-- Delivery Overview -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #e0f7fa;">
                            <i class="fas fa-truck" style="color: #006064;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $logistics_stats['count'] ?? 0; ?>">0</h3>
                            <p>Active Deliveries</p>
                        </div>
                        <a href="logistics.php" class="stat-value" style="color: #006064;">Track</a>
                    </div>
                    
                    <!-- Preorder Management -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #fff8e1;">
                            <i class="fas fa-calendar-check" style="color: #ff6f00;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $preorder_stats['count'] ?? 0; ?>">0</h3>
                            <p>Pending Pre-orders</p>
                        </div>
                        <a href="preorders.php" class="stat-value" style="color: #ff6f00;">Review</a>
                    </div>

                    <!-- Inventory Management -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #fff3e0;">
                            <i class="fas fa-boxes" style="color: #e65100;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $inventory_stats['count'] ?? 0; ?>">0</h3>
                            <p>Low Stock Items</p>
                        </div>
                        <a href="inventory.php" class="stat-value" style="color: #e65100;">Check</a>
                    </div>

                    <!-- Product Management -->
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon" style="background-color: #f3e5f5;">
                            <i class="fas fa-box-open" style="color: #7b1fa2;"></i>
                        </div>
                        <div class="stat-content">
                            <h3 data-count="<?php echo $products_stats['count'] ?? 0; ?>">0</h3>
                            <p>Active Products</p>
                        </div>
                        <a href="products.php" class="stat-value" style="color: #7b1fa2;">Edit</a>
                    </div>

                    <?php if ($is_super_admin_user): ?>
                        <!-- Business Applications -->
                        <div class="stat-card fade-in-up">
                            <div class="stat-icon" style="background-color: #fce4ec;">
                                <i class="fas fa-file-contract" style="color: #c2185b;"></i>
                            </div>
                            <div class="stat-content">
                                <h3 data-count="<?php echo $franchise_stats['count'] ?? 0; ?>">0</h3>
                                <p>Franchise Applications</p>
                            </div>
                            <a href="../super_admin/franchise_applications.php" class="stat-value" style="color: #c2185b;">Process</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card h-100 fade-in-up">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Revenue & Analytics Trend (Last 7 Days)</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="dailyTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100 fade-in-up">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Monthly Revenue (<?php echo date('Y'); ?>)</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="monthlyRevenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Selling Products -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card h-100 fade-in-up">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Top Selling Products (Last 7 Days)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Product</th>
                                                <th class="text-end pe-4">Units Sold</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($top_products_7days_result) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($top_products_7days_result)): ?>
                                                    <tr>
                                                        <td class="ps-4"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                                        <td class="text-end pe-4"><strong><?php echo $row['total_qty']; ?></strong></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="2" class="text-center text-muted py-3">No sales in the last 7 days</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 fade-in-up">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Top Selling Products (This Month)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Product</th>
                                                <th class="text-end pe-4">Units Sold</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($top_products_month_result) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($top_products_month_result)): ?>
                                                    <tr>
                                                        <td class="ps-4"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                                        <td class="text-end pe-4"><strong><?php echo $row['total_qty']; ?></strong></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="2" class="text-center text-muted py-3">No sales this month</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unified Dashboard Center -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card fade-in-up hub-section-card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1"><i class="fas fa-layer-group text-primary"></i> All Dashboards Center</h5>
                                    <p class="text-muted mb-0">Forecasting, DSS Reports, Statistics, and Events in one admin view.</p>
                                </div>
                                <a href="dss_reports.php" class="btn btn-outline-primary btn-sm">Open Full DSS Report</a>
                            </div>
                            <div class="card-body">
                                <div class="dashboard-hub-grid mb-4">
                                    <div class="hub-card">
                                        <h6><i class="fas fa-chart-line text-primary"></i> Forecasting Dashboard</h6>
                                        <div class="hub-value"><?= number_format($dss_trend['forecast_accuracy'], 1) ?>%</div>
                                        <div class="hub-sub">Forecast accuracy (last 14 days)</div>
                                        <div class="hub-badges">
                                            <span class="badge bg-light text-dark">MAPE <?= number_format($dss_trend['mape'], 1) ?>%</span>
                                            <span class="badge bg-warning text-dark"><?= (int)$pending_decisions ?> Pending Decisions</span>
                                        </div>
                                        <div class="hub-actions">
                                            <a href="forecasting_dashboard.php" class="btn btn-sm btn-outline-primary">Open Forecasting</a>
                                        </div>
                                    </div>

                                    <div class="hub-card">
                                        <h6><i class="fas fa-brain text-info"></i> DSS Reports</h6>
                                        <div class="hub-value">PHP <?= number_format($dss_overview['net_income'], 0) ?></div>
                                        <div class="hub-sub">30-day net income insight</div>
                                        <div class="hub-badges">
                                            <span class="badge bg-info"><?= number_format($dss_overview['records_analyzed']) ?> Records</span>
                                            <span class="badge bg-success"><?= (int)$implemented_30d ?> Implemented (30d)</span>
                                        </div>
                                        <div class="hub-actions">
                                            <a href="dss_reports.php?range=30" class="btn btn-sm btn-outline-info">Open DSS Reports</a>
                                        </div>
                                    </div>

                                    <div class="hub-card">
                                        <h6><i class="fas fa-chart-pie text-success"></i> Statistics Dashboard</h6>
                                        <div class="hub-value">PHP <?= number_format($dss_overview['total_revenue'], 0) ?></div>
                                        <div class="hub-sub">30-day total revenue</div>
                                        <div class="hub-badges">
                                            <span class="badge bg-light text-dark">Margin <?= number_format($dss_overview['net_margin'], 1) ?>%</span>
                                            <span class="badge bg-primary"><?= count($dss_top_products) ?> Top Products Tracked</span>
                                        </div>
                                        <div class="hub-actions">
                                            <a href="statistics.php" class="btn btn-sm btn-outline-success">Open Statistics</a>
                                        </div>
                                    </div>

                                    <div class="hub-card">
                                        <h6><i class="fas fa-calendar-alt text-danger"></i> Events Dashboard</h6>
                                        <div class="hub-value"><?= $upcoming_event_count ?></div>
                                        <div class="hub-sub">Upcoming active events (45 days)</div>
                                        <div class="hub-badges">
                                            <span class="badge bg-danger"><?= $high_impact_event_count ?> High Impact</span>
                                            <span class="badge bg-<?= $critical_inventory_count > 0 ? 'warning text-dark' : 'success' ?>">
                                                <?= $critical_inventory_count ?> Critical Stock Risks
                                            </span>
                                        </div>
                                        <div class="hub-actions">
                                            <a href="events.php" class="btn btn-sm btn-outline-danger">Open Events</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-lg-6">
                                        <div class="border rounded-3 p-3 h-100">
                                            <h6 class="mb-2"><i class="fas fa-lightbulb text-warning"></i> Decision Support Highlights</h6>
                                            <?php foreach (array_slice($dss_decision_brief, 0, 3) as $brief): ?>
                                                <div class="insight-list-item">
                                                    <div class="d-flex justify-content-between">
                                                        <strong><?= htmlspecialchars($brief['headline']) ?></strong>
                                                        <span class="badge bg-<?= $brief['priority'] === 'critical' ? 'danger' : ($brief['priority'] === 'high' ? 'warning text-dark' : 'info') ?>">
                                                            <?= strtoupper($brief['priority']) ?>
                                                        </span>
                                                    </div>
                                                    <p class="mb-0 text-muted small"><?= htmlspecialchars($brief['action']) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="border rounded-3 p-3 h-100">
                                            <h6 class="mb-2"><i class="fas fa-boxes-stacked text-secondary"></i> Inventory & Forecast Snapshot</h6>
                                            <p class="mb-2 small text-muted">
                                                Next 7-day forecast revenue:
                                                <strong>PHP <?= number_format($dss_forecast_summary['predicted_revenue'], 2) ?></strong>
                                                | Confidence: <strong><?= number_format($dss_forecast_summary['avg_confidence'], 1) ?>%</strong>
                                            </p>
                                            <?php if (empty($dss_inventory_pressure)): ?>
                                                <p class="mb-0 text-success small">No immediate inventory pressure detected.</p>
                                            <?php else: ?>
                                                <?php foreach (array_slice($dss_inventory_pressure, 0, 3) as $inv): ?>
                                                    <div class="insight-list-item">
                                                        <div class="d-flex justify-content-between">
                                                            <strong><?= htmlspecialchars($inv['product_name']) ?></strong>
                                                            <span class="badge bg-<?= $inv['severity'] === 'critical' ? 'danger' : ($inv['severity'] === 'high' ? 'warning text-dark' : ($inv['severity'] === 'medium' ? 'info' : 'secondary')) ?>">
                                                                <?= strtoupper($inv['severity']) ?>
                                                            </span>
                                                        </div>
                                                        <p class="mb-0 text-muted small">Stock <?= (int)$inv['stock'] ?> | Demand <?= number_format($inv['forecast_demand'], 1) ?></p>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <a href="forecasting_dashboard.php" class="btn btn-sm btn-outline-primary">Forecasting</a>
                                    <a href="dss_reports.php" class="btn btn-sm btn-outline-info">DSS Reports</a>
                                    <a href="statistics.php" class="btn btn-sm btn-outline-success">Statistics</a>
                                    <a href="events.php" class="btn btn-sm btn-outline-danger">Events</a>
                                    <a href="hr_reports.php" class="btn btn-sm btn-outline-secondary">HR Reports</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Orders Section -->
                <div class="recent-section fade-in-up">
                    <h3>Recent Orders</h3>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $orders_query = "SELECT o.id, o.order_number, o.customer_name, o.total_amount, o.status, o.created_at
                                                 FROM orders o
                                                 WHERE o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists}" : "") . "
                                                 ORDER BY o.created_at DESC
                                                 LIMIT 10";
                                $orders_result = mysqli_query($conn, $orders_query);
                                
                                while ($order = mysqli_fetch_assoc($orders_result)) {
                                    $status_class = 'badge-' . str_replace(' ', '-', $order['status']);
                                    echo "
                                    <tr>
                                        <td><strong>{$order['order_number']}</strong></td>
                                        <td>{$order['customer_name']}</td>
                                        <td>" . date('M d, Y', strtotime($order['created_at'])) . "</td>
                                        <td>₱" . number_format($order['total_amount'], 2) . "</td>
                                        <td><span class='status-badge $status_class'>{$order['status']}</span></td>
                                        <td>
                                            <a href='orders.php?edit={$order['id']}' class='btn-icon' title='View'>
                                                <i class='fas fa-eye'></i>
                                            </a>
                                        </td>
                                    </tr>
                                    ";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="orders.php" class="view-all-link">View All Orders →</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
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

        // Daily Trend Chart
        const dailyCtx = document.getElementById('dailyTrendChart').getContext('2d');
        const dailyGradient = dailyCtx.createLinearGradient(0, 0, 0, 400);
        dailyGradient.addColorStop(0, 'rgba(56, 142, 60, 0.5)');
        dailyGradient.addColorStop(1, 'rgba(56, 142, 60, 0)');

        const dailyGradient2 = dailyCtx.createLinearGradient(0, 0, 0, 400);
        dailyGradient2.addColorStop(0, 'rgba(25, 118, 210, 0.5)');
        dailyGradient2.addColorStop(1, 'rgba(25, 118, 210, 0)');

        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_values($chart_dates)); ?>,
                datasets: [
                    {
                        label: 'Revenue (₱)',
                        data: <?php echo json_encode(array_values($chart_revenue)); ?>,
                        borderColor: 'var(--success-color)',
                        backgroundColor: dailyGradient,
                        yAxisID: 'y',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Orders',
                        data: <?php echo json_encode(array_values($chart_orders)); ?>,
                        borderColor: 'var(--info-color)',
                        backgroundColor: dailyGradient2,
                        yAxisID: 'y1',
                        tension: 0.4,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Revenue' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Orders' }
                    }
                }
            }
        });

        // Monthly Revenue Chart
        const monthlyCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue (₱)',
                    data: <?php echo json_encode(array_values($monthly_data)); ?>,
                    backgroundColor: 'var(--primary-color)',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // --- Driver Status Widget ---
        function updateDriverStatusWidget() {
            const widgetContainer = document.getElementById('driverStatusWidget');
            if (!widgetContainer) return;

            fetch('ajax_get_driver_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        widgetContainer.innerHTML = '<div class="text-center text-danger p-3">Error loading driver status.</div>';
                        return;
                    }

                    let onDeliveryHtml = `
                        <h6><i class="fas fa-route text-warning"></i> On Delivery (${data.on_delivery.length})</h6>
                        <div class="list-group">`;
                    if (data.on_delivery.length === 0) {
                        onDeliveryHtml += '<div class="list-group-item text-muted">No drivers currently on delivery.</div>';
                    } else {
                        data.on_delivery.forEach(driver => {
                            const statusText = driver.current_status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                            onDeliveryHtml += `
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${escapeHtml(driver.first_name)} ${escapeHtml(driver.last_name)}</strong>
                                        <small class="d-block text-muted">Order #${escapeHtml(driver.order_number)}</small>
                                    </div>
                                    <span class="badge bg-warning text-dark">${escapeHtml(statusText)}</span>
                                </div>`;
                        });
                    }
                    onDeliveryHtml += '</div>';

                    let availableHtml = `
                        <h6><i class="fas fa-user-check text-success"></i> Available (${data.available.length})</h6>
                        <div class="list-group">`;
                    if (data.available.length === 0) {
                        availableHtml += '<div class="list-group-item text-muted">No drivers currently available.</div>';
                    } else {
                        data.available.forEach(driver => {
                            availableHtml += `
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <strong>${escapeHtml(driver.first_name)} ${escapeHtml(driver.last_name)}</strong>
                                    <span class="badge bg-success">Available</span>
                                </div>`;
                        });
                    }
                    availableHtml += '</div>';

                    widgetContainer.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">${onDeliveryHtml}</div>
                            <div class="col-md-6">${availableHtml}</div>
                        </div>`;
                })
                .catch(error => {
                    console.error('Error fetching driver status:', error);
                    widgetContainer.innerHTML = '<div class="text-center text-danger p-3">Failed to load driver status.</div>';
                });
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = {
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Initial load and periodic refresh for the driver widget
        document.addEventListener('DOMContentLoaded', function() {
            updateDriverStatusWidget();
            setInterval(updateDriverStatusWidget, 30000); // Refresh every 30 seconds
        });
    </script>
</body>
</html>
