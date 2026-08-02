<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';
include '../includes/PartnerBusinessEconomicsService.php';
include '../includes/PartnerExpenseSyncService.php';
require_once '../includes/partner_dashboard_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$insights_service = new DSSInsightsService($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_economics_snapshot = null;
$partner_economics_trend = ['labels' => [], 'revenue' => [], 'booked_expenses' => [], 'open_commitments' => [], 'platform_costs' => []];
$partner_expense_sync = ['billing_synced' => 0, 'refunds_synced' => 0, 'procurement_synced' => 0, 'supplier_invoices_synced' => 0];
$seller_scoped_orders_exists_sql = '';
if ($seller_scope_id !== null) {
    $seller_scoped_orders_exists_sql = "EXISTS (
        SELECT 1
        FROM order_items oi_scope
        INNER JOIN products p_scope
            ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
        WHERE oi_scope.order_id = o.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}

$conversion_window_days = 30;
$conversion_metrics = [
    'views' => 0,
    'purchases' => 0,
    'rate' => 0.0
];

if (pdhEnsureProductViewEventsSchema($conn)) {
    $views_sql = "SELECT COUNT(*) AS total_views
                  FROM product_view_events pve
                  WHERE pve.view_date >= DATE_SUB(CURDATE(), INTERVAL {$conversion_window_days} DAY)";
    if ($seller_scope_id !== null) {
        $views_sql .= " AND pve.seller_id = ?";
    }
    $views_stmt = mysqli_prepare($conn, $views_sql);
    if ($views_stmt) {
        if ($seller_scope_id !== null) {
            mysqli_stmt_bind_param($views_stmt, "i", $seller_scope_id);
        }
        mysqli_stmt_execute($views_stmt);
        $views_result = mysqli_stmt_get_result($views_stmt);
        $views_row = $views_result ? mysqli_fetch_assoc($views_result) : null;
        $conversion_metrics['views'] = (int)($views_row['total_views'] ?? 0);
        mysqli_stmt_close($views_stmt);
    }

    $purchases_sql = "SELECT COUNT(DISTINCT o.id) AS total_purchases
                      FROM orders o
                      WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL {$conversion_window_days} DAY)
                        AND o.status IN ('delivered', 'completed')
                        AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
    $purchases_result = mysqli_query($conn, $purchases_sql);
    if ($purchases_result) {
        $purchases_row = mysqli_fetch_assoc($purchases_result);
        $conversion_metrics['purchases'] = (int)($purchases_row['total_purchases'] ?? 0);
        mysqli_free_result($purchases_result);
    }
}

if ($conversion_metrics['views'] > 0) {
    $conversion_metrics['rate'] = ($conversion_metrics['purchases'] / $conversion_metrics['views']) * 100;
}

// Get various statistics
// Daily orders for the last 7 days
$daily_query = "SELECT DATE(o.created_at) as date, COUNT(*) as count, SUM(o.total_amount) as total
                FROM orders o
                WHERE DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                GROUP BY DATE(o.created_at)
                ORDER BY date DESC";
$daily_result = mysqli_query($conn, $daily_query);

// Product sales
$sales_query = "SELECT oi.product_name, SUM(oi.quantity) as quantity, SUM(oi.total) as total
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE MONTH(o.created_at) = MONTH(CURDATE())
                  AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                GROUP BY oi.product_id
                ORDER BY quantity DESC
                LIMIT 10";
$sales_result = mysqli_query($conn, $sales_query);

// Payment methods
$payment_query = "SELECT o.payment_method, COUNT(*) as count, SUM(o.total_amount) as total
                  FROM orders o
                  WHERE MONTH(o.created_at) = MONTH(CURDATE())
                    AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                  GROUP BY o.payment_method";
$payment_result = mysqli_query($conn, $payment_query);

// Order status breakdown
$status_query = "SELECT o.status, COUNT(*) as count
                 FROM orders o
                 WHERE o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                 GROUP BY o.status";
$status_result = mysqli_query($conn, $status_query);

// Monthly revenue
$monthly_query = "SELECT DATE_FORMAT(o.created_at, '%b %Y') as month, SUM(o.total_amount) as total
                  FROM orders o
                  WHERE YEAR(o.created_at) = YEAR(CURDATE())
                    AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                  GROUP BY YEAR(o.created_at), MONTH(o.created_at)";
$monthly_result = mysqli_query($conn, $monthly_query);

// Unified DSS analytics (orders + pre-orders + forecasts + events + inventory)
if ($seller_scope_id === null) {
    $dss_overview = $insights_service->getOverviewMetrics(30);
    $dss_trend = $insights_service->getRevenueTrend(14);
    $dss_top_products = $insights_service->getTopProducts(30, 10);
    $dss_inventory_pressure = $insights_service->getInventoryPressure(7, 6);
    $dss_forecast_summary = $insights_service->getForecastingSummary(7);
    $dss_decision_brief = $insights_service->generateDecisionBrief(30);
    $dss_event_insights = $insights_service->getEventImpactInsights(45);
} else {
    $expenseSyncService = new PartnerExpenseSyncService($conn);
    $partner_expense_sync = $expenseSyncService->syncPartnerMonth((int)$seller_scope_id, date('Y-m'));
    $economicsService = new PartnerBusinessEconomicsService($conn);
    $partner_economics_snapshot = $economicsService->getSnapshot((int)$seller_scope_id, date('Y-m'));
    $partner_economics_trend = $economicsService->getTrend((int)$seller_scope_id, 6);
    $dss_overview = ['total_revenue' => 0, 'net_income' => 0, 'records_analyzed' => 0];
    $dss_trend = ['labels' => [], 'actual_orders' => [], 'forecast_accuracy' => 0];
    $dss_top_products = [];
    $dss_inventory_pressure = [];
    $dss_forecast_summary = ['predicted_revenue' => 0];
    $dss_decision_brief = [];
    $dss_event_insights = ['high_impact_events' => 0];

    $overview_stmt = $conn->prepare("SELECT COUNT(DISTINCT o.id) as records_analyzed, COALESCE(SUM(o.total_amount),0) as total_revenue
                                     FROM orders o
                                     WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                       AND o.status IN ('delivered','completed')
                                       AND o.is_archived = 0
                                       AND {$seller_scoped_orders_exists_sql}");
    if ($overview_stmt) {
        $overview_stmt->execute();
        $overview_row = $overview_stmt->get_result()->fetch_assoc() ?: [];
        $dss_overview['records_analyzed'] = (int)($overview_row['records_analyzed'] ?? 0);
        $dss_overview['total_revenue'] = (float)($overview_row['total_revenue'] ?? 0);
        $overview_stmt->close();
    }

    $approved_expenses = (float)($partner_economics_snapshot['booked']['approved_expenses'] ?? 0);
    $dss_overview['approved_expenses'] = $approved_expenses;
    $dss_overview['net_income'] = (float)($partner_economics_snapshot['positions']['net_after_platform_paid'] ?? ($dss_overview['total_revenue'] - $approved_expenses));

    $trend_stmt = $conn->prepare("SELECT DATE(o.created_at) as day_label, COUNT(DISTINCT o.id) as orders_count
                                  FROM orders o
                                  WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                                    AND o.is_archived = 0
                                    AND {$seller_scoped_orders_exists_sql}
                                  GROUP BY DATE(o.created_at)
                                  ORDER BY day_label ASC");
    if ($trend_stmt) {
        $trend_stmt->execute();
        $trend_result = $trend_stmt->get_result();
        while ($trend_row = $trend_result->fetch_assoc()) {
            $dss_trend['labels'][] = date('M d', strtotime((string)$trend_row['day_label']));
            $dss_trend['actual_orders'][] = (int)$trend_row['orders_count'];
        }
        $trend_stmt->close();
        if (!empty($dss_trend['labels'])) {
            $dss_trend['forecast_accuracy'] = 100;
        }
    }

    $top_stmt = $conn->prepare("SELECT oi.product_name, SUM(oi.quantity) as quantity, COALESCE(SUM(oi.total),0) as revenue
                                FROM order_items oi
                                INNER JOIN orders o ON oi.order_id = o.id
                                WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                  AND o.is_archived = 0
                                  AND {$seller_scoped_orders_exists_sql}
                                GROUP BY oi.product_id, oi.product_name
                                ORDER BY quantity DESC
                                LIMIT 10");
    if ($top_stmt) {
        $top_stmt->execute();
        $top_result = $top_stmt->get_result();
        while ($top_row = $top_result->fetch_assoc()) {
            $dss_top_products[] = [
                'product_name' => (string)$top_row['product_name'],
                'quantity' => (int)$top_row['quantity'],
                'revenue' => (float)$top_row['revenue']
            ];
        }
        $top_stmt->close();
    }

    $brief_stmt = $conn->prepare("SELECT priority, recommendation_text
                                  FROM decisions_recommendations
                                  WHERE recommendation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                    AND created_by = ?
                                  ORDER BY FIELD(priority, 'critical','high','medium','low'), recommendation_date DESC
                                  LIMIT 6");
    if ($brief_stmt) {
        $brief_stmt->bind_param("i", $seller_scope_id);
        $brief_stmt->execute();
        $brief_result = $brief_stmt->get_result();
        while ($brief_row = $brief_result->fetch_assoc()) {
            $text = trim((string)$brief_row['recommendation_text']);
            $dss_decision_brief[] = [
                'headline' => $text !== '' ? (function_exists('mb_substr') ? mb_substr($text, 0, 90) : substr($text, 0, 90)) : 'Store recommendation',
                'action' => $text !== '' ? $text : 'Review store-level recommendation.',
                'priority' => (string)($brief_row['priority'] ?? 'medium')
            ];
        }
        $brief_stmt->close();
    }

    $events_stmt = $conn->prepare("SELECT COUNT(*) as high_impact_events
                                   FROM business_events
                                   WHERE is_active = 1
                                     AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 45 DAY)
                                     AND impact_multiplier >= 1.2");
    if ($events_stmt) {
        $events_stmt->execute();
        $dss_event_insights['high_impact_events'] = (int)($events_stmt->get_result()->fetch_assoc()['high_impact_events'] ?? 0);
        $events_stmt->close();
    }

    $dss_forecast_summary['predicted_revenue'] = ($dss_overview['total_revenue'] / 30) * 7;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistics - Admin Dashboard</title>
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
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .stat-box,
        body.dark-mode .chart-container {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode .stat-box h5,
        body.dark-mode .chart-container h5 {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .status-list li,
        body.dark-mode .payment-list li {
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .payment-list strong {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
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
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Business Statistics</h1>
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
                <h2>Analytics & Reports</h2>
                <?php if ($partner_economics_snapshot): ?>
                <div class="card mb-4">
                    <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
                        <div>
                            <strong>Partner cost sync is live</strong>
                            <div class="small text-muted">
                                Billing synced: <?php echo number_format((int)($partner_expense_sync['billing_synced'] ?? 0)); ?> |
                                Refunds synced: <?php echo number_format((int)($partner_expense_sync['refunds_synced'] ?? 0)); ?> |
                                Procurement synced: <?php echo number_format((int)($partner_expense_sync['procurement_synced'] ?? 0)); ?> |
                                Supplier invoices synced: <?php echo number_format((int)($partner_expense_sync['supplier_invoices_synced'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="small text-muted">
                            Fully loaded position: <strong>PHP <?= number_format((float)($partner_economics_snapshot['positions']['fully_loaded_position'] ?? 0), 2) ?></strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="stats-grid mb-4">
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green"><i class="fas fa-coins"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format($dss_overview['total_revenue'], 0) ?></h3>
                            <p>30-Day Total Revenue</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue"><i class="fas fa-wallet"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format($dss_overview['net_income'], 0) ?></h3>
                            <p><?php echo $partner_economics_snapshot ? 'Net After Platform Costs' : '30-Day Net Income'; ?></p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon orange"><i class="fas fa-bullseye"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format($dss_trend['forecast_accuracy'], 1) ?>%</h3>
                            <p>Forecast Accuracy</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon purple"><i class="fas fa-database"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format($dss_overview['records_analyzed']) ?></h3>
                            <p>Records Analyzed</p>
                        </div>
                    </div>
                </div>

                <div class="stats-grid mb-4">
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue"><i class="fas fa-eye"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format((int)$conversion_metrics['views']) ?></h3>
                            <p>Product Views (Last <?= (int)$conversion_window_days ?> Days)</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green"><i class="fas fa-cart-shopping"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format((int)$conversion_metrics['purchases']) ?></h3>
                            <p>Completed Purchases (Last <?= (int)$conversion_window_days ?> Days)</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon orange"><i class="fas fa-percent"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format((float)$conversion_metrics['rate'], 2) ?>%</h3>
                            <p>Views to Purchases Conversion</p>
                        </div>
                    </div>
                </div>

                <?php if ($partner_economics_snapshot): ?>
                <div class="stats-grid mb-4">
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue"><i class="fas fa-wallet"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)($partner_economics_snapshot['booked']['approved_expenses'] ?? 0), 0) ?></h3>
                            <p>Booked Expenses</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon orange"><i class="fas fa-dolly"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)($partner_economics_snapshot['procurement']['open_commitments'] ?? 0), 0) ?></h3>
                            <p>Open Procurement</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon purple"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)(($partner_economics_snapshot['platform']['due_total'] ?? 0) + ($partner_economics_snapshot['platform']['overdue_total'] ?? 0)), 0) ?></h3>
                            <p>Platform Billing Due</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green"><i class="fas fa-undo"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)(($partner_economics_snapshot['refunds']['pending_total'] ?? 0) + ($partner_economics_snapshot['refunds']['approved_total'] ?? 0)), 0) ?></h3>
                            <p>Refund Exposure</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($partner_economics_snapshot): ?>
                <div class="stats-grid mb-4">
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon blue"><i class="fas fa-calendar-alt"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['projected_month_end_spend'] ?? 0), 0) ?></h3>
                            <p>Projected Month-End Spend</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon green"><i class="fas fa-shield-dollar"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['projected_month_end_profit'] ?? 0), 0) ?></h3>
                            <p>Projected Month-End Profit</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon orange"><i class="fas fa-triangle-exclamation"></i></div>
                        <div class="stat-content">
                            <h3>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['profit_at_risk'] ?? 0), 0) ?></h3>
                            <p>Profit At Risk</p>
                        </div>
                    </div>
                    <div class="stat-card fade-in-up">
                        <div class="stat-icon purple"><i class="fas fa-boxes-stacked"></i></div>
                        <div class="stat-content">
                            <h3><?= number_format((int)($partner_economics_snapshot['forecast']['reorder_risk_count'] ?? 0)) ?></h3>
                            <p>Reorder Risk Materials</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Charts Section -->
                <div class="charts-grid">
                    <!-- Daily Orders Chart -->
                    <div class="chart-container">
                        <h5><?php echo $partner_economics_snapshot ? 'Revenue vs Cost Load (Last 6 Months)' : 'Orders (Last 14 Days)'; ?></h5>
                        <canvas id="dailyChart"></canvas>
                    </div>
                    
                    <!-- Monthly Revenue Chart -->
                    <div class="chart-container">
                        <h5><?php echo $partner_economics_snapshot ? 'Procurement & Platform Pressure' : 'Monthly Revenue'; ?></h5>
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <?php if ($partner_economics_snapshot): ?>
                <div class="stats-grid mt-4">
                    <div class="stat-box">
                        <h5>Cash Position Summary</h5>
                        <ul class="status-list">
                            <li>
                                <span>Booked Net Income</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['positions']['booked_net_income'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Fully Loaded Position</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['positions']['fully_loaded_position'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Pending Payroll Pipeline</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['pipeline']['pending_payroll'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Platform Costs Already Paid</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['platform']['paid_total'] ?? 0), 2) ?></strong>
                            </li>
                        </ul>
                    </div>

                    <div class="stat-box">
                        <h5>Expense Forecast</h5>
                        <ul class="status-list">
                            <li>
                                <span>Projected Revenue</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['projected_month_end_revenue'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Projected Spend</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['projected_month_end_spend'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Projected Profit</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['projected_month_end_profit'] ?? 0), 2) ?></strong>
                            </li>
                            <li>
                                <span>Profit At Risk</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['forecast']['profit_at_risk'] ?? 0), 2) ?></strong>
                            </li>
                        </ul>
                    </div>

                    <div class="stat-box">
                        <h5>Expense Management Focus</h5>
                        <ul class="status-list">
                            <li>
                                <span>Expense Ratio</span>
                                <strong><?= number_format((float)($partner_economics_snapshot['positions']['expense_ratio'] ?? 0), 1) ?>%</strong>
                            </li>
                            <li>
                                <span>Payroll Ratio</span>
                                <strong><?= number_format((float)($partner_economics_snapshot['positions']['payroll_ratio'] ?? 0), 1) ?>%</strong>
                            </li>
                            <li>
                                <span>Procurement Orders Open</span>
                                <strong><?= number_format((int)($partner_economics_snapshot['procurement']['open_count'] ?? 0)) ?></strong>
                            </li>
                            <li>
                                <span>Open Refund Cases</span>
                                <strong><?= number_format((int)($partner_economics_snapshot['refunds']['open_cases'] ?? 0)) ?></strong>
                            </li>
                            <li>
                                <span>Supplier Invoice Cost</span>
                                <strong>PHP <?= number_format((float)($partner_economics_snapshot['procurement']['supplier_invoice_total'] ?? 0), 2) ?></strong>
                            </li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Tables Section -->
                <div class="stats-section">
                    <h3>Top Products (Orders + Pre-Orders, Last 30 Days)</h3>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Quantity Sold</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dss_top_products)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No product performance data available.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($dss_top_products as $product): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                                            <td><?= (int)$product['quantity'] ?></td>
                                            <td>PHP <?= number_format($product['revenue'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <!-- Order Status Breakdown -->
                    <div class="stat-box">
                        <h5>Order Status Breakdown</h5>
                        <ul class="status-list">
                            <?php
                            while ($status = mysqli_fetch_assoc($status_result)) {
                                echo "
                                <li>
                                    <span>{$status['status']}</span>
                                    <strong>{$status['count']}</strong>
                                </li>
                                ";
                            }
                            ?>
                        </ul>
                    </div>
                    
                    <!-- Payment Methods -->
                    <div class="stat-box">
                        <h5>Payment Methods Used</h5>
                        <ul class="payment-list">
                            <?php
                            while ($payment = mysqli_fetch_assoc($payment_result)) {
                                echo "
                                <li>
                                    <span>" . htmlspecialchars($payment['payment_method']) . "</span>
                                    <div>
                                        <strong>{$payment['count']} orders</strong>
                                        <p>PHP " . number_format($payment['total'], 2) . "</p>
                                    </div>
                                </li>
                                ";
                            }
                            ?>
                        </ul>
                    </div>
                </div>

                <div class="stats-grid mt-4">
                    <div class="stat-box">
                        <h5>Inventory Pressure (Next 7 Days)</h5>
                        <?php if (empty($dss_inventory_pressure)): ?>
                            <p class="text-muted mb-0">No high inventory pressure detected.</p>
                        <?php else: ?>
                            <ul class="status-list">
                                <?php foreach ($dss_inventory_pressure as $item): ?>
                                    <li>
                                        <div>
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                            <p class="mb-0 small text-muted">Stock <?= (int)$item['stock'] ?> | Demand <?= number_format($item['forecast_demand'], 1) ?></p>
                                        </div>
                                        <span class="badge bg-<?= $item['severity'] === 'critical' ? 'danger' : ($item['severity'] === 'high' ? 'warning' : ($item['severity'] === 'medium' ? 'info' : 'secondary')) ?>">
                                            <?= strtoupper($item['severity']) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="stat-box">
                        <h5>Decision Support Highlights</h5>
                        <ul class="status-list">
                            <?php foreach ($dss_decision_brief as $brief): ?>
                                <li>
                                    <div>
                                        <strong><?= htmlspecialchars($brief['headline']) ?></strong>
                                        <p class="mb-0 small text-muted"><?= htmlspecialchars($brief['action']) ?></p>
                                    </div>
                                    <span class="badge bg-<?= $brief['priority'] === 'critical' ? 'danger' : ($brief['priority'] === 'high' ? 'warning' : 'info') ?>">
                                        <?= strtoupper($brief['priority']) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="small text-muted mb-0">High-impact upcoming events (45 days): <strong><?= (int)$dss_event_insights['high_impact_events'] ?></strong></p>
                        <p class="small text-muted mb-0">7-day forecasted revenue: <strong>PHP <?= number_format($dss_forecast_summary['predicted_revenue'], 2) ?></strong></p>
                    </div>
                </div>

                <?php if ($partner_economics_snapshot): ?>
                <div class="stats-grid mt-4">
                    <div class="stat-box">
                        <h5>Cost Source Breakdown</h5>
                        <ul class="status-list">
                            <?php foreach (($partner_economics_snapshot['cost_sources'] ?? []) as $costSource): ?>
                                <li>
                                    <div>
                                        <strong><?= htmlspecialchars((string)($costSource['label'] ?? 'Cost Source')) ?></strong>
                                        <p class="mb-0 small text-muted"><?= htmlspecialchars((string)($costSource['note'] ?? '')) ?></p>
                                    </div>
                                    <strong>PHP <?= number_format((float)($costSource['amount'] ?? 0), 2) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="stat-box">
                        <h5>Forecast Recommendations</h5>
                        <ul class="status-list">
                            <?php foreach (($partner_economics_snapshot['forecast_recommendations'] ?? $partner_economics_snapshot['recommendations'] ?? []) as $recommendation): ?>
                                <li>
                                    <div>
                                        <strong><?= htmlspecialchars((string)($recommendation['headline'] ?? 'Recommendation')) ?></strong>
                                        <p class="mb-0 small text-muted"><?= htmlspecialchars((string)($recommendation['action'] ?? '')) ?></p>
                                        <p class="mb-0 small text-muted"><?= htmlspecialchars((string)($recommendation['detail'] ?? '')) ?></p>
                                    </div>
                                    <span class="badge bg-<?= ($recommendation['priority'] ?? '') === 'critical' ? 'danger' : (($recommendation['priority'] ?? '') === 'high' ? 'warning' : 'info') ?>">
                                        <?= strtoupper((string)($recommendation['priority'] ?? 'info')) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
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

        const isPartnerEconomicsMode = <?php echo $partner_economics_snapshot ? 'true' : 'false'; ?>;
        const currencyTick = (value) => 'PHP ' + Number(value || 0).toLocaleString();

        // Daily / partner economics chart
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyData = <?php
            if ($partner_economics_snapshot) {
                echo json_encode([
                    'labels' => ($partner_economics_trend['labels'] ?? []),
                    'revenue' => ($partner_economics_trend['revenue'] ?? []),
                    'booked_expenses' => ($partner_economics_trend['booked_expenses'] ?? []),
                    'platform_costs' => ($partner_economics_trend['platform_costs'] ?? [])
                ]);
            } else {
                echo json_encode([
                    'labels' => ($dss_trend['labels'] ?? []),
                    'counts' => ($dss_trend['actual_orders'] ?? [])
                ]);
            }
        ?>;

        new Chart(dailyCtx, isPartnerEconomicsMode ? {
            type: 'line',
            data: {
                labels: dailyData.labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: dailyData.revenue,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.12)',
                        tension: 0.35,
                        fill: false
                    },
                    {
                        label: 'Booked Expenses',
                        data: dailyData.booked_expenses,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.10)',
                        tension: 0.35,
                        fill: false
                    },
                    {
                        label: 'Platform Cost Load',
                        data: dailyData.platform_costs,
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.10)',
                        tension: 0.35,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: currencyTick }
                    }
                }
            }
        } : {
            type: 'line',
            data: {
                labels: dailyData.labels,
                datasets: [{
                    label: 'Orders',
                    data: dailyData.counts,
                    borderColor: '#1976d2',
                    backgroundColor: 'rgba(25, 118, 210, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } }
            }
        });
        
        // Monthly / partner pressure chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyData = <?php
            if ($partner_economics_snapshot) {
                echo json_encode([
                    'labels' => ($partner_economics_trend['labels'] ?? []),
                    'open_commitments' => ($partner_economics_trend['open_commitments'] ?? []),
                    'platform_costs' => ($partner_economics_trend['platform_costs'] ?? [])
                ]);
            } else {
                $months = [];
                $revenues = [];
                mysqli_data_seek($monthly_result, 0);
                while ($row = mysqli_fetch_assoc($monthly_result)) {
                    $months[] = $row['month'];
                    $revenues[] = $row['total'];
                }
                echo json_encode(['months' => $months, 'revenues' => $revenues]);
            }
        ?>;
        
        new Chart(monthlyCtx, isPartnerEconomicsMode ? {
            type: 'bar',
            data: {
                labels: monthlyData.labels,
                datasets: [
                    {
                        label: 'Open Procurement',
                        data: monthlyData.open_commitments,
                        backgroundColor: '#0ea5e9'
                    },
                    {
                        label: 'Platform Cost Load',
                        data: monthlyData.platform_costs,
                        backgroundColor: '#f97316'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: currencyTick }
                    }
                }
            }
        } : {
            type: 'bar',
            data: {
                labels: monthlyData.months,
                datasets: [{
                    label: 'Revenue (PHP)',
                    data: monthlyData.revenues,
                    backgroundColor: '#388e3c'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } }
            }
        });
    </script>
</body>
</html>

