<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/AnomalyDetectionService.php';
include '../includes/ForecastingEngine.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();
// If you have a specific permission for viewing reports, you can enforce it here.
// For example: requireAdminPermission($conn, 'reports.view'); 

$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
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

// --- 1. DEFINE DATE RANGE (LAST 7 DAYS) ---
$range_days = isset($_GET['range']) ? max(7, min(90, (int)$_GET['range'])) : 7;
$date_to = date('Y-m-d');
$date_from = date('Y-m-d', strtotime('-' . ($range_days - 1) . ' days'));
$report_period = date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to));

// --- 2. FETCH KPIS FOR THE WEEK ---
$kpis = [
    'weekly_net_income' => 0,
    'weekly_revenue' => 0,
    'weekly_expenses' => 0,
    'new_critical_alerts' => 0,
    'decisions_implemented' => 0,
    'implementation_rate' => 0,
];

// Weekly Revenue
$weekly_revenue_sql = "SELECT SUM(o.total_amount) as weekly_revenue
                       FROM orders o
                       WHERE o.status IN ('delivered', 'completed')
                         AND o.created_at BETWEEN ? AND ?" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
$rev_stmt = $conn->prepare($weekly_revenue_sql);
$rev_stmt->bind_param("ss", $date_from, $date_to);
$rev_stmt->execute();
$kpis['weekly_revenue'] = $rev_stmt->get_result()->fetch_assoc()['weekly_revenue'] ?? 0;
$rev_stmt->close();

// Weekly Expenses
$weekly_expense_sql = "SELECT SUM(amount) as weekly_expenses FROM expenses WHERE expense_date BETWEEN ? AND ?" . ($seller_scope_id !== null ? " AND recorded_by = ?" : "");
$exp_stmt = $conn->prepare($weekly_expense_sql);
if ($seller_scope_id !== null) {
    $exp_stmt->bind_param("ssi", $date_from, $date_to, $seller_scope_id);
} else {
    $exp_stmt->bind_param("ss", $date_from, $date_to);
}
$exp_stmt->execute();
$kpis['weekly_expenses'] = $exp_stmt->get_result()->fetch_assoc()['weekly_expenses'] ?? 0;
$exp_stmt->close();

// Weekly Net Income
$kpis['weekly_net_income'] = $kpis['weekly_revenue'] - $kpis['weekly_expenses'];

// Decisions Implemented & Rate
$implemented_sql = "SELECT 
    SUM(CASE WHEN status = 'implemented' THEN 1 ELSE 0 END) as implemented_count,
    COUNT(*) as total_resolved
    FROM decisions_recommendations 
    WHERE status IN ('implemented', 'rejected') AND updated_at BETWEEN ? AND ?" . ($seller_scope_id !== null ? " AND created_by = ?" : "");
$dec_stmt = $conn->prepare($implemented_sql);
if ($seller_scope_id !== null) {
    $dec_stmt->bind_param("ssi", $date_from, $date_to, $seller_scope_id);
} else {
    $dec_stmt->bind_param("ss", $date_from, $date_to);
}
$dec_stmt->execute();
$dec_result = $dec_stmt->get_result()->fetch_assoc();
$kpis['decisions_implemented'] = $dec_result['implemented_count'] ?? 0;
$kpis['implementation_rate'] = ($dec_result['total_resolved'] > 0) ? round(($dec_result['implemented_count'] / $dec_result['total_resolved']) * 100, 1) : 0;
$dec_stmt->close();

// --- 3. FETCH ANOMALIES FOR THE WEEK ---
$anomaly_service = new AnomalyDetectionService($conn);
$demand_anomalies = ['anomalies' => []];
$inventory_anomalies = ['inventory_anomalies' => []];
$staffing_anomalies = ['staffing_anomalies' => []];
if ($seller_scope_id === null) {
    $demand_anomalies = $anomaly_service->detectDemandAnomalies(7);
    $inventory_anomalies = $anomaly_service->detectInventoryAnomalies();
    $staffing_anomalies = $anomaly_service->detectStaffingAnomalies();
}

// Count new critical alerts for the KPI
$critical_count = 0;
if (!empty($demand_anomalies['anomalies'])) {
    $critical_count += count(array_filter($demand_anomalies['anomalies'], fn($a) => $a['severity'] === 'CRITICAL'));
}
if (!empty($inventory_anomalies['inventory_anomalies'])) {
    $critical_count += count(array_filter($inventory_anomalies['inventory_anomalies'], fn($a) => $a['severity'] === 'CRITICAL'));
}
$kpis['new_critical_alerts'] = $critical_count;

// --- 4. SHARED DSS INSIGHTS ---
$insights_service = new DSSInsightsService($conn);
if ($seller_scope_id === null) {
    $dss_overview = $insights_service->getOverviewMetrics($range_days);
    $dss_trend = $insights_service->getRevenueTrend(min(14, $range_days));
    $dss_forecast_summary = $insights_service->getForecastingSummary(7);
    $dss_inventory_pressure = $insights_service->getInventoryPressure(7, 6);
    $dss_event_insights = $insights_service->getEventImpactInsights(45);
    $dss_decision_brief = $insights_service->generateDecisionBrief(max(14, $range_days));

    // Use unified totals for report KPIs (orders + preorders + approved expenses)
    $kpis['weekly_revenue'] = $dss_overview['total_revenue'];
    $kpis['weekly_expenses'] = $dss_overview['approved_expenses'];
    $kpis['weekly_net_income'] = $dss_overview['net_income'];
} else {
    $dss_overview = [
        'total_revenue' => (float)$kpis['weekly_revenue'],
        'approved_expenses' => (float)$kpis['weekly_expenses'],
        'net_income' => (float)$kpis['weekly_net_income'],
        'records_analyzed' => 0
    ];
    $dss_trend = ['mape' => 0, 'labels' => [], 'actual_revenue' => [], 'forecast_revenue' => [], 'actual_orders' => []];
    $dss_forecast_summary = ['predicted_revenue' => (float)$kpis['weekly_revenue'], 'avg_confidence' => 70];
    $dss_inventory_pressure = [];
    $dss_event_insights = ['high_impact_events' => 0, 'upcoming_events' => []];
    $dss_decision_brief = [];

    $analysis_stmt = $conn->prepare("SELECT COUNT(DISTINCT o.id) as records_analyzed, COALESCE(SUM(o.total_amount),0) as total_revenue
                                     FROM orders o
                                     WHERE o.created_at BETWEEN ? AND ?
                                       AND o.status IN ('delivered','completed')
                                       AND o.is_archived = 0
                                       AND {$seller_scoped_orders_exists_sql}");
    if ($analysis_stmt) {
        $analysis_stmt->bind_param("ss", $date_from, $date_to);
        $analysis_stmt->execute();
        $analysis_row = $analysis_stmt->get_result()->fetch_assoc() ?: [];
        $analysis_stmt->close();
        $dss_overview['records_analyzed'] = (int)($analysis_row['records_analyzed'] ?? 0);
        $dss_overview['total_revenue'] = (float)($analysis_row['total_revenue'] ?? 0);
        $dss_overview['approved_expenses'] = (float)$kpis['weekly_expenses'];
        $dss_overview['net_income'] = $dss_overview['total_revenue'] - $dss_overview['approved_expenses'];
        $kpis['weekly_revenue'] = $dss_overview['total_revenue'];
        $kpis['weekly_expenses'] = $dss_overview['approved_expenses'];
        $kpis['weekly_net_income'] = $dss_overview['net_income'];
    }

    $brief_days = max(14, (int)$range_days);
    $brief_stmt = $conn->prepare("SELECT decision_category, priority, recommendation_text
                                  FROM decisions_recommendations
                                  WHERE recommendation_date >= DATE_SUB(CURDATE(), INTERVAL {$brief_days} DAY)
                                    AND created_by = ?
                                  ORDER BY FIELD(priority, 'critical', 'high', 'medium', 'low'), recommendation_date DESC
                                  LIMIT 6");
    if ($brief_stmt) {
        $brief_stmt->bind_param("i", $seller_scope_id);
        $brief_stmt->execute();
        $brief_result = $brief_stmt->get_result();
        while ($brief_row = $brief_result->fetch_assoc()) {
            $text = trim((string)$brief_row['recommendation_text']);
            $dss_decision_brief[] = [
                'headline' => $text !== '' ? (function_exists('mb_substr') ? mb_substr($text, 0, 90) : substr($text, 0, 90)) : 'Store recommendation',
                'priority' => (string)($brief_row['priority'] ?? 'medium'),
                'rationale' => 'Generated for your store.',
                'action' => $text !== '' ? $text : 'Review store-level recommendation.',
                'expected_outcome' => 'Improve operational performance.'
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
}

// --- 5. FETCH IMPLEMENTED RECOMMENDATIONS FOR THE WEEK ---
$implemented_recs = [];
$implemented_recs_sql = "SELECT * FROM decisions_recommendations WHERE status = 'implemented' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . ($seller_scope_id !== null ? " AND created_by = ?" : "") . " ORDER BY updated_at DESC";
$rec_stmt = $conn->prepare($implemented_recs_sql);
if ($seller_scope_id !== null) {
    $rec_stmt->bind_param("i", $seller_scope_id);
}
$rec_stmt->execute();
$result = $rec_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $implemented_recs[] = $row;
}
$rec_stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly DSS Report - Lechon Delights</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Animations from orders.php style */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideInUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .fade-in-up {
            animation: slideInUp 0.5s ease-in-out forwards;
            opacity: 0;
        }
        .dashboard-grid .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .dashboard-grid .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .dashboard-grid .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .dashboard-grid .stat-card:nth-child(4) { animation-delay: 0.4s; }
        body {
            background-color: #f1f5f9;
        }
        .report-container {
            background-color: #fff;
            padding: 30px;
            margin: 20px auto;
            max-width: 900px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            border-bottom: 2px solid #c62828;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .report-header h1 {
            color: #c62828;
            margin: 0;
        }
        .report-header p {
            color: #666;
            margin: 5px 0 0 0;
        }
        .report-section {
            margin-bottom: 40px;
        }
        .report-section h2 {
            font-size: 1.5rem;
            color: #1e3c72;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .anomaly-card {
            background: #fff9e6;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 6px;
        }
        .anomaly-card.critical {
            background: #ffe6e6;
            border-left-color: #e74c3c;
        }
        .recommendation-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .recommendation-item:last-child {
            border-bottom: none;
        }
        .print-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 100;
        }

        @media print {
            body {
                background-color: #fff;
            }
            .admin-container {
                display: block;
            }
            .admin-sidebar, .admin-topbar, .print-button {
                display: none;
            }
            .admin-content {
                margin-left: 0;
            }
            .report-container {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                padding: 0;
            }
            .anomaly-card {
                background-color: #fff9e6 !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact;
            }
            .anomaly-card.critical {
                background-color: #ffe6e6 !important;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact;
            }
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
                    <h1>DSS Intelligence Report</h1>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-main p-4">
                <div class="report-container">
                    <div class="report-header">
                        <h1>Decision Support Executive Summary</h1>
                        <p>Report for period: <strong><?php echo $report_period; ?></strong></p>
                        <p class="text-muted small">Generated on: <?php echo date('M d, Y H:i:s'); ?></p>
                        <div class="mt-3">
                            <a href="?range=7" class="btn btn-sm <?= $range_days === 7 ? 'btn-danger' : 'btn-outline-danger' ?>">7 Days</a>
                            <a href="?range=14" class="btn btn-sm <?= $range_days === 14 ? 'btn-danger' : 'btn-outline-danger' ?>">14 Days</a>
                            <a href="?range=30" class="btn btn-sm <?= $range_days === 30 ? 'btn-danger' : 'btn-outline-danger' ?>">30 Days</a>
                            <a href="?range=60" class="btn btn-sm <?= $range_days === 60 ? 'btn-danger' : 'btn-outline-danger' ?>">60 Days</a>
                        </div>
                    </div>

                    <!-- KPI Section -->
                    <div class="report-section">
                        <h2 class="fade-in-up"><i class="fas fa-tachometer-alt"></i> Key Performance Indicators</h2>
                        <div class="dashboard-grid">
                            <div class="stat-card fade-in-up">
                                <div class="stat-icon" style="background: #e8f5e9;"><i class="fas fa-dollar-sign text-success"></i></div>
                                <div class="stat-content">
                                    <h3>PHP <?= number_format($kpis['weekly_net_income'], 2) ?></h3>
                                    <p>Weekly Net Income</p>
                                </div>
                            </div>
                            <div class="stat-card fade-in-up">
                                <div class="stat-icon" style="background: #e3f2fd;"><i class="fas fa-check-circle text-primary"></i></div>
                                <div class="stat-content">
                                    <h3><?= $kpis['decisions_implemented'] ?></h3>
                                    <p>Decisions Implemented</p>
                                </div>
                            </div>
                            <div class="stat-card fade-in-up">
                                <div class="stat-icon" style="background: #e0f7fa;"><i class="fas fa-chart-pie text-info"></i></div>
                                <div class="stat-content">
                                    <h3><?= $kpis['implementation_rate'] ?>%</h3>
                                    <p>Implementation Rate</p>
                                </div>
                            </div>
                            <div class="stat-card fade-in-up">
                                <div class="stat-icon" style="background: #ffebee;"><i class="fas fa-exclamation-triangle text-danger"></i></div>
                                <div class="stat-content">
                                    <h3><?= $kpis['new_critical_alerts'] ?></h3>
                                    <p>New Critical Alerts</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card fade-in-up mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Forecast Reliability & Planning Window</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 border rounded">
                                        <div class="text-muted small">MAPE (Lower is Better)</div>
                                        <h4 class="mb-0"><?= number_format($dss_trend['mape'], 2) ?>%</h4>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 border rounded">
                                        <div class="text-muted small">Next 7-Day Revenue Forecast</div>
                                        <h4 class="mb-0">PHP <?= number_format($dss_forecast_summary['predicted_revenue'], 2) ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 border rounded">
                                        <div class="text-muted small">Records Analyzed</div>
                                        <h4 class="mb-0"><?= number_format($dss_overview['records_analyzed']) ?></h4>
                                    </div>
                                </div>
                            </div>
                            <p class="small text-muted mb-0">
                                Combined analysis includes orders, pre-orders, events, forecasts, and inventory indicators to save analysis time and improve planning decisions.
                            </p>
                        </div>
                    </div>

                    <div class="card fade-in-up mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-brain"></i> Actionable Decision Brief</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($dss_decision_brief as $brief): ?>
                                <div class="recommendation-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <strong><?= htmlspecialchars($brief['headline']) ?></strong>
                                        <span class="badge bg-<?= $brief['priority'] === 'critical' ? 'danger' : ($brief['priority'] === 'high' ? 'warning' : 'info') ?>">
                                            <?= strtoupper($brief['priority']) ?>
                                        </span>
                                    </div>
                                    <p class="mb-1 text-muted small"><?= htmlspecialchars($brief['rationale']) ?></p>
                                    <p class="mb-1"><strong>Action:</strong> <?= htmlspecialchars($brief['action']) ?></p>
                                    <small class="text-success"><strong>Expected:</strong> <?= htmlspecialchars($brief['expected_outcome']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card fade-in-up mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-boxes-stacked"></i> Inventory Pressure Snapshot (Next 7 Days)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($dss_inventory_pressure)): ?>
                                <div class="alert alert-success mb-0">No major inventory pressure detected for the next 7 days.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Stock</th>
                                                <th>Forecast Demand</th>
                                                <th>Coverage (Days)</th>
                                                <th>Risk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dss_inventory_pressure as $item): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                    <td><?= (int)$item['stock'] ?></td>
                                                    <td><?= number_format($item['forecast_demand'], 1) ?></td>
                                                    <td><?= $item['coverage_days'] === null ? 'N/A' : number_format($item['coverage_days'], 1) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $item['severity'] === 'critical' ? 'danger' : ($item['severity'] === 'high' ? 'warning' : ($item['severity'] === 'medium' ? 'info' : 'secondary')) ?>">
                                                            <?= strtoupper($item['severity']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="small text-muted mt-2">
                                Upcoming high-impact events in 45 days: <strong><?= (int)$dss_event_insights['high_impact_events'] ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Anomalies Section -->
                    <div class="card fade-in-up">
                        <div class="card-header">
                            <h5><i class="fas fa-exclamation-triangle"></i> Anomalies Detected This Week</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($demand_anomalies['anomalies']) && empty($inventory_anomalies['inventory_anomalies']) && empty($staffing_anomalies['staffing_anomalies'])): ?>
                                <div class="alert alert-success">No significant anomalies detected in the past week.</div>
                            <?php else: ?>
                                <?php if (!empty($demand_anomalies['anomalies'])): ?>
                                    <h6>Demand Anomalies</h6>
                                    <?php foreach ($demand_anomalies['anomalies'] as $anomaly): ?>
                                        <div class="anomaly-card <?= strtolower($anomaly['severity']) === 'critical' ? 'critical' : '' ?>">
                                            <strong>[<?= $anomaly['severity'] ?>] Demand <?= $anomaly['type'] ?> on <?= $anomaly['date'] ?>:</strong> <?= $anomaly['description'] ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (!empty($inventory_anomalies['inventory_anomalies'])): ?>
                                    <h6 class="mt-4">Inventory Alerts</h6>
                                    <?php foreach ($inventory_anomalies['inventory_anomalies'] as $inv_issue): ?>
                                        <div class="anomaly-card <?= strtolower($inv_issue['severity']) === 'critical' ? 'critical' : '' ?>">
                                            <strong>[<?= $inv_issue['severity'] ?>] <?= $inv_issue['product_name'] ?>:</strong> <?= $inv_issue['action'] ?? $inv_issue['recommendation'] ?? 'Monitor stock' ?> (Current: <?= $inv_issue['current_stock'] ?>)
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if (!empty($staffing_anomalies['staffing_anomalies'])): ?>
                                    <h6 class="mt-4">Staffing Alerts</h6>
                                    <?php foreach ($staffing_anomalies['staffing_anomalies'] as $staff_issue): ?>
                                        <div class="anomaly-card">
                                            <strong>[<?= $staff_issue['severity'] ?>] <?= $staff_issue['issue_type'] ?>:</strong> <?= $staff_issue['recommendation'] ?> (Need: <?= $staff_issue['staff_needed'] ?>, Have: <?= $staff_issue['current_staff'] ?>)
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Implemented Recommendations Section -->
                    <div class="card fade-in-up mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-check-circle"></i> Implemented Recommendations</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($implemented_recs)): ?>
                                <div class="alert alert-info">No recommendations were marked as implemented this week.</div>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($implemented_recs as $rec): ?>
                                        <div class="list-group-item recommendation-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h5 class="mb-1 text-success"><?= ucfirst($rec['decision_category']) ?></h5>
                                                <small>Implemented on: <?= date('M d, Y', strtotime($rec['updated_at'])) ?></small>
                                            </div>
                                            <p class="mb-1"><?= htmlspecialchars($rec['recommendation_text']) ?></p>
                                            <small class="text-muted">Priority: <?= ucfirst($rec['priority']) ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-danger print-button" onclick="window.print()">
        <i class="fas fa-print"></i> Print Report
    </button>

    <script src="admin.js"></script>
</body>
</html>
