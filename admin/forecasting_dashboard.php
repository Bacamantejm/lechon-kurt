<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DecisionScoringService.php';
include '../includes/AnomalyDetectionService.php';
include '../includes/SecurityService.php';
include '../includes/ForecastingEngine.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();

// Initialize services
$security = new SecurityService($conn);
$scoring_service = new DecisionScoringService($conn);
$anomaly_service = new AnomalyDetectionService($conn);
$forecasting_engine = new ForecastingEngine($conn);
$insights_service = new DSSInsightsService($conn);

// Get admin info
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$seller_scoped_orders_exists_sql = '';
$seller_scoped_preorders_exists_sql = '';
if ($seller_scope_id !== null) {
    $seller_scoped_orders_exists_sql = "EXISTS (
        SELECT 1
        FROM order_items oi_scope
        INNER JOIN products p_scope
            ON (oi_scope.product_id COLLATE utf8mb4_general_ci = p_scope.product_id COLLATE utf8mb4_general_ci OR oi_scope.product_id COLLATE utf8mb4_general_ci = CAST(p_scope.id AS CHAR) COLLATE utf8mb4_general_ci)
        WHERE oi_scope.order_id = o.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
    $seller_scoped_preorders_exists_sql = "EXISTS (
        SELECT 1
        FROM products p_scope
        WHERE p_scope.id = po.product_id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}

$requested_month = (int)($_GET['month'] ?? date('n'));
$requested_year = (int)($_GET['year'] ?? date('Y'));
$current_month = ($requested_month >= 1 && $requested_month <= 12) ? $requested_month : (int)date('n');
$current_year = ($requested_year >= ((int)date('Y') - 5) && $requested_year <= ((int)date('Y') + 1)) ? $requested_year : (int)date('Y');
$selected_period_start = sprintf('%04d-%02d-01', $current_year, $current_month);
$selected_period_next = date('Y-m-d', strtotime($selected_period_start . ' +1 month'));
$selected_period_end = date('Y-m-t', strtotime($selected_period_start));
$selected_period_label = date('F Y', strtotime($selected_period_start));
$period_start_ts = strtotime($selected_period_start . ' 00:00:00');
$period_end_ts = strtotime($selected_period_end . ' 23:59:59');
$chart_window_end = $period_start_ts > time() ? $period_end_ts : min($period_end_ts, time());
$chart_window_end_date = date('Y-m-d', $chart_window_end);
$chart_window_next = date('Y-m-d', strtotime($chart_window_end_date . ' +1 day'));
$chart_window_start = date('Y-m-d', strtotime($chart_window_end_date . ' -13 day'));
$period_days = max(1, (int)date('t', strtotime($selected_period_start)));

// ============================================================================
// SECTION 1: KEY PERFORMANCE INDICATORS (KPIs)
// ============================================================================

$kpis = [
    'forecast_accuracy' => null,
    'decision_implementation_rate' => null,
    'monthly_revenue' => 0,
    'monthly_expenses' => 0,
    'monthly_net_income' => 0,
    'completed_orders' => 0,
    'avg_order_value' => 0,
    'open_refund_liability' => 0,
    'open_refund_cases' => 0,
    'next_7_day_forecast' => 0,
    'forecast_variance' => 0,
    'pending_decisions' => 0,
    'critical_alerts' => 0,
    'system_health' => 'optimal',
    'data_freshness' => 'real-time',
    'inventory_pressure_count' => 0
];

// KPI: Pending Decisions
$pending_decisions_sql = "SELECT COUNT(*) as count FROM decisions_recommendations WHERE status = 'pending'" . ($seller_scope_id !== null ? " AND created_by = ?" : "");
$stmt = $conn->prepare($pending_decisions_sql);
if ($seller_scope_id !== null) {
    $stmt->bind_param("i", $seller_scope_id);
}
$stmt->execute();
$kpis['pending_decisions'] = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
$stmt->close();

// KPI: Forecast Accuracy
if ($seller_scope_id === null) {
    $stmt = $conn->prepare("SELECT AVG(accuracy_score) as avg_accuracy FROM forecast_accuracy_metrics WHERE evaluation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $acc_result = $stmt->get_result()->fetch_assoc();
    $kpis['forecast_accuracy'] = isset($acc_result['avg_accuracy']) && $acc_result['avg_accuracy'] !== null ? round((float)$acc_result['avg_accuracy'], 1) : null;
    $stmt->close();
}

// KPI: Implementation Rate
$implementation_sql = "SELECT (SUM(CASE WHEN status = 'implemented' THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN status IN ('implemented', 'rejected') THEN 1 ELSE 0 END), 0)) * 100 as rate
                       FROM decisions_recommendations
                       WHERE recommendation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . ($seller_scope_id !== null ? " AND created_by = ?" : "");
$stmt = $conn->prepare($implementation_sql);
if ($seller_scope_id !== null) {
    $stmt->bind_param("i", $seller_scope_id);
}
$stmt->execute();
$impl_result = $stmt->get_result()->fetch_assoc();
$kpis['decision_implementation_rate'] = isset($impl_result['rate']) && $impl_result['rate'] !== null ? round((float)$impl_result['rate'], 1) : null;
$stmt->close();

// KPI: Critical Alerts
$demand_anomalies = ['anomalies' => []];
$inventory_anomalies = ['inventory_anomalies' => []];
if ($seller_scope_id === null) {
    $demand_anomalies = $anomaly_service->detectDemandAnomalies(30);
    $inventory_anomalies = $anomaly_service->detectInventoryAnomalies();
}
$critical_count = 0;
if (isset($demand_anomalies['anomalies'])) {
    $critical_count += count(array_filter($demand_anomalies['anomalies'], fn($a) => $a['severity'] === 'CRITICAL'));
}
if (isset($inventory_anomalies['inventory_anomalies'])) {
    $critical_count += count(array_filter($inventory_anomalies['inventory_anomalies'], fn($a) => $a['severity'] === 'CRITICAL'));
}
$kpis['critical_alerts'] = $critical_count;

// KPI: Selected period net income (aligned with finance reporting)
$revenue_query = "SELECT SUM(o.total_amount) as monthly_revenue
                  FROM orders o
                  WHERE o.status IN ('delivered', 'completed')
                    AND o.is_archived = 0
                    AND o.created_at >= ?
                    AND o.created_at < ?" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
$stmt = mysqli_prepare($conn, $revenue_query);
mysqli_stmt_bind_param($stmt, "ss", $selected_period_start, $selected_period_next);
mysqli_stmt_execute($stmt);
$revenue_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$monthly_revenue = $revenue_result['monthly_revenue'] ?? 0;
mysqli_stmt_close($stmt);

$expenses_query = "SELECT SUM(amount) as monthly_ops
                   FROM expenses
                   WHERE expense_date >= ?
                     AND expense_date < ?" . ($seller_scope_id !== null ? " AND recorded_by = ?" : "");
$stmt = mysqli_prepare($conn, $expenses_query);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($stmt, "ssi", $selected_period_start, $selected_period_next, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($stmt, "ss", $selected_period_start, $selected_period_next);
}
mysqli_stmt_execute($stmt);
$expenses_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$monthly_expenses = $expenses_result['monthly_ops'] ?? 0;
mysqli_stmt_close($stmt);
$kpis['monthly_revenue'] = (float)$monthly_revenue;
$kpis['monthly_expenses'] = (float)$monthly_expenses;
$kpis['monthly_net_income'] = $monthly_revenue - $monthly_expenses;

$completed_orders_query = "SELECT COUNT(DISTINCT o.id) AS completed_orders,
                                  COALESCE(AVG(o.total_amount), 0) AS avg_order_value
                           FROM orders o
                           WHERE o.status IN ('delivered', 'completed')
                             AND o.is_archived = 0
                             AND o.created_at >= ?
                             AND o.created_at < ?" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
$stmt = mysqli_prepare($conn, $completed_orders_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ss", $selected_period_start, $selected_period_next);
    mysqli_stmt_execute($stmt);
    $completed_orders_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $kpis['completed_orders'] = (int)($completed_orders_result['completed_orders'] ?? 0);
    $kpis['avg_order_value'] = (float)($completed_orders_result['avg_order_value'] ?? 0);
    mysqli_stmt_close($stmt);
}

$refund_liability_query = "SELECT COUNT(*) AS open_refund_cases,
                                  COALESCE(SUM(r.refund_amount), 0) AS open_refund_liability
                           FROM refunds r
                           INNER JOIN cancellations c ON r.cancellation_id = c.id
                           INNER JOIN orders o ON c.order_id = o.id
                           WHERE r.refund_status IN ('Refund Pending', 'Refund Approved')" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
$stmt = mysqli_prepare($conn, $refund_liability_query);
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $refund_liability_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $kpis['open_refund_cases'] = (int)($refund_liability_result['open_refund_cases'] ?? 0);
    $kpis['open_refund_liability'] = (float)($refund_liability_result['open_refund_liability'] ?? 0);
    mysqli_stmt_close($stmt);
}

// KPI: System Health
$health = ['data_fresh' => true, 'forecasts_fresh' => true, 'data_freshness_hours' => 0];
if ($seller_scope_id === null) {
    $health = $forecasting_engine->getSystemHealth();
}
$kpis['system_health'] = ($health['data_fresh'] && $health['forecasts_fresh']) ? 'optimal' : 'needs_attention';
$kpis['data_freshness'] = $seller_scope_id === null
    ? (round($health['data_freshness_hours'], 1) . ' hours ago')
    : 'tenant-scoped';

// Unified DSS insights across operations, forecasting, and planning
$dss_trend = [
    'labels' => [],
    'actual_revenue' => [],
    'forecast_revenue' => [],
    'actual_orders' => [],
    'mape' => 0,
    'forecast_accuracy' => 0
];
$dss_overview = [
    'total_revenue' => 0.0,
    'approved_expenses' => 0.0,
    'net_income' => 0.0,
    'net_margin' => 0.0,
    'records_analyzed' => 0
];
$dss_forecast_summary = [
    'predicted_revenue' => 0.0,
    'avg_confidence' => 0.0
];
$dss_inventory_pressure = $seller_scope_id === null ? $insights_service->getInventoryPressure(7, 5) : [];
$dss_event_insights = $seller_scope_id === null ? $insights_service->getEventImpactInsights(45) : ['high_impact_events' => 0, 'upcoming_events' => []];
$dss_decision_brief = [];

$trend_query = "SELECT DATE(o.created_at) as day_label,
                       COUNT(DISTINCT o.id) as orders_count,
                       COALESCE(SUM(o.total_amount),0) as revenue
                FROM orders o
                WHERE o.created_at >= ?
                  AND o.created_at < ?
                  AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "") . "
                GROUP BY DATE(o.created_at)
                ORDER BY day_label ASC";
$trend_stmt = $conn->prepare($trend_query);
if ($trend_stmt) {
    $trend_stmt->bind_param("ss", $chart_window_start, $chart_window_next);
    $trend_stmt->execute();
    $trend_result = $trend_stmt->get_result();
    $trendMap = [];
    while ($trend_row = $trend_result->fetch_assoc()) {
        $trendMap[(string)$trend_row['day_label']] = [
            'revenue' => (float)$trend_row['revenue'],
            'orders_count' => (int)$trend_row['orders_count']
        ];
    }
    $trend_stmt->close();

    $rollingActuals = [];
    $absolutePercentErrors = [];
    $windowDays = max(1, (int)round((strtotime($chart_window_next) - strtotime($chart_window_start)) / 86400));
    for ($dayOffset = $windowDays - 1; $dayOffset >= 0; $dayOffset--) {
        $dateKey = date('Y-m-d', strtotime($chart_window_end_date . " -{$dayOffset} day"));
        $actualRevenue = (float)($trendMap[$dateKey]['revenue'] ?? 0);
        $actualOrders = (int)($trendMap[$dateKey]['orders_count'] ?? 0);
        $historyWindow = array_slice($rollingActuals, -3);
        $forecastRevenue = $historyWindow === [] ? $actualRevenue : (array_sum($historyWindow) / count($historyWindow));

        $dss_trend['labels'][] = date('M d', strtotime($dateKey));
        $dss_trend['actual_revenue'][] = round($actualRevenue, 2);
        $dss_trend['forecast_revenue'][] = round($forecastRevenue, 2);
        $dss_trend['actual_orders'][] = $actualOrders;

        if ($actualRevenue > 0) {
            $absolutePercentErrors[] = abs(($actualRevenue - $forecastRevenue) / $actualRevenue) * 100;
        }
        $rollingActuals[] = $actualRevenue;
    }

    if ($absolutePercentErrors !== []) {
        $dss_trend['mape'] = round(array_sum($absolutePercentErrors) / count($absolutePercentErrors), 2);
        $dss_trend['forecast_accuracy'] = round(max(0, 100 - $dss_trend['mape']), 1);
    }
}

$range_revenue = 0.0;
foreach ($dss_trend['actual_revenue'] as $amount) {
    $range_revenue += (float)$amount;
}
$range_days = max(1, count($dss_trend['labels']));
$daily_avg = $range_revenue / $range_days;

$overview_query = "SELECT COUNT(DISTINCT o.id) as records_analyzed,
                          COALESCE(SUM(o.total_amount),0) as total_revenue
                   FROM orders o
                   WHERE o.created_at >= ?
                     AND o.created_at < ?
                     AND o.status IN ('delivered','completed')
                     AND o.is_archived = 0" . ($seller_scope_id !== null ? " AND {$seller_scoped_orders_exists_sql}" : "");
$overview_stmt = $conn->prepare($overview_query);
$overview_row = ['records_analyzed' => 0, 'total_revenue' => 0];
if ($overview_stmt) {
    $overview_stmt->bind_param("ss", $selected_period_start, $selected_period_next);
    $overview_stmt->execute();
    $overview_row = $overview_stmt->get_result()->fetch_assoc() ?: $overview_row;
    $overview_stmt->close();
}

$expense_query = "SELECT COALESCE(SUM(amount),0) as approved_expenses
                  FROM expenses
                  WHERE expense_date >= ?
                    AND expense_date < ?" . ($seller_scope_id !== null ? " AND recorded_by = ?" : "");
$expense_total = 0.0;
$expense_stmt = $conn->prepare($expense_query);
if ($expense_stmt) {
    if ($seller_scope_id !== null) {
        $expense_stmt->bind_param("ssi", $selected_period_start, $selected_period_next, $seller_scope_id);
    } else {
        $expense_stmt->bind_param("ss", $selected_period_start, $selected_period_next);
    }
    $expense_stmt->execute();
    $expense_total = (float)($expense_stmt->get_result()->fetch_assoc()['approved_expenses'] ?? 0);
    $expense_stmt->close();
}

$selected_revenue = (float)($overview_row['total_revenue'] ?? 0);
$selected_net_income = $selected_revenue - $expense_total;
$dss_overview = [
    'total_revenue' => $selected_revenue,
    'approved_expenses' => $expense_total,
    'net_income' => $selected_net_income,
    'net_margin' => $selected_revenue > 0 ? (($selected_net_income / $selected_revenue) * 100) : 0,
    'records_analyzed' => (int)($overview_row['records_analyzed'] ?? 0)
];
$dss_forecast_summary = [
    'predicted_revenue' => $daily_avg * 7,
    'avg_confidence' => !empty($dss_trend['labels']) ? max(0, min(100, 100 - (float)($dss_trend['mape'] ?? 25))) : 0
];

$decision_brief_sql = "SELECT decision_category, priority, recommendation_text
                       FROM decisions_recommendations
                       WHERE recommendation_date >= ?
                         AND recommendation_date < ?" . ($seller_scope_id !== null ? " AND created_by = ?" : "") . "
                       ORDER BY FIELD(priority, 'critical', 'high', 'medium', 'low'), recommendation_date DESC
                       LIMIT 3";
$decision_brief_stmt = $conn->prepare($decision_brief_sql);
if ($decision_brief_stmt) {
    if ($seller_scope_id !== null) {
        $decision_brief_stmt->bind_param("ssi", $selected_period_start, $selected_period_next, $seller_scope_id);
    } else {
        $decision_brief_stmt->bind_param("ss", $selected_period_start, $selected_period_next);
    }
    $decision_brief_stmt->execute();
    $decision_result = $decision_brief_stmt->get_result();
    while ($decision_row = $decision_result->fetch_assoc()) {
        $recommendation_text = trim((string)$decision_row['recommendation_text']);
        $dss_decision_brief[] = [
            'category' => (string)($decision_row['decision_category'] ?? 'operations'),
            'priority' => (string)($decision_row['priority'] ?? 'medium'),
            'headline' => $recommendation_text !== '' ? (function_exists('mb_substr') ? mb_substr($recommendation_text, 0, 90) : substr($recommendation_text, 0, 90)) : 'Operational recommendation',
            'rationale' => $seller_scope_id === null ? 'Generated from platform forecasting activity.' : 'Generated from your store activity.',
            'action' => $recommendation_text !== '' ? $recommendation_text : 'Review this recommendation with your operations team.',
            'expected_outcome' => $seller_scope_id === null ? 'Improve platform-wide planning accuracy.' : 'Improve store-level performance.'
        ];
    }
    $decision_brief_stmt->close();
}

if (!empty($dss_trend['forecast_accuracy']) && $dss_trend['forecast_accuracy'] > 0) {
    $kpis['forecast_accuracy'] = round($dss_trend['forecast_accuracy'], 1);
}
$kpis['next_7_day_forecast'] = (float)($dss_forecast_summary['predicted_revenue'] ?? 0);

// ============================================================================
// SECTION 2: TOP RECOMMENDATIONS WITH SCORES
// ============================================================================

$recommendations = [];
$recommendations_sql = "SELECT * FROM decisions_recommendations WHERE status = 'pending'" . ($seller_scope_id !== null ? " AND created_by = ?" : "") . " ORDER BY FIELD(priority, 'critical', 'high', 'medium', 'low') ASC, recommendation_date DESC LIMIT 5";
$stmt = $conn->prepare($recommendations_sql);
if ($seller_scope_id !== null) {
    $stmt->bind_param("i", $seller_scope_id);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recommendations[] = $row;
}
$stmt->close();

// Score each recommendation
$scored_recommendations = [];
foreach ($recommendations as $rec) {
    $confidence_raw = isset($rec['confidence_score']) ? (float)$rec['confidence_score'] : 75;
    $confidence_level = $confidence_raw > 1 ? max(0, min(1, $confidence_raw / 100)) : max(0, min(1, $confidence_raw));

    $expected_impact = isset($rec['expected_impact_value']) ? abs((float)$rec['expected_impact_value']) : 0;
    $expected_benefit = max(1000, $expected_impact);
    $expected_cost = max(500, $expected_benefit * 0.35);
    $roi_estimate = $expected_cost > 0 ? (($expected_benefit - $expected_cost) / $expected_cost) * 100 : 0;

    $timeline_days = 7;
    if (!empty($rec['action_start_date']) && !empty($rec['action_end_date'])) {
        $timeline_days = max(1, (int)round((strtotime($rec['action_end_date']) - strtotime($rec['action_start_date'])) / 86400));
    }

    $risk_by_priority = [
        'critical' => 75,
        'high' => 60,
        'medium' => 45,
        'low' => 30
    ];

    $priority_focus = array_values(array_unique(array_map(function ($brief_item) {
        return $brief_item['category'];
    }, $dss_decision_brief)));
    if (empty($priority_focus)) {
        $priority_focus = ['inventory', 'production', 'logistics'];
    }

    $decision_data = [
        'confidence_level' => $confidence_level,
        'forecast_variance' => abs($dss_trend['mape'] ?? 15),
        'expected_cost' => $expected_cost,
        'expected_benefit' => $expected_benefit,
        'roi_estimate' => $roi_estimate,
        'implementation_timeline' => $timeline_days,
        'risk_exposure' => $risk_by_priority[$rec['priority']] ?? 45,
        'market_volatility' => min(90, max(10, abs($dss_trend['mape'] ?? 20))),
        'dependency_risk' => min(80, max(10, (int)round($timeline_days * 4))),
        'category' => $rec['decision_category'],
        'business_priorities' => $priority_focus
    ];
    
    $score = $scoring_service->scoreDecision($decision_data);
    $rec['score'] = $score['total_score'];
    $rec['rating'] = $score['rating'];
    $rec['score_class'] = $rec['score'] >= 75 ? 'excellent' : ($rec['score'] >= 60 ? 'good' : 'fair');
    $rec['recommendation_text_short'] = substr($rec['recommendation_text'], 0, 100) . '...';
    $scored_recommendations[] = $rec;
}
usort($scored_recommendations, fn($a, $b) => $b['score'] <=> $a['score']);

// ============================================================================
// SECTION 3: CHARTS
// ============================================================================

// Chart 1: Revenue vs Forecast (Last 14 days)
$combined_chart_data = [
    'labels' => $dss_trend['labels'] ?? [],
    'actual' => $dss_trend['actual_revenue'] ?? [],
    'forecast' => $dss_trend['forecast_revenue'] ?? []
];

$latest_index = !empty($combined_chart_data['actual']) ? count($combined_chart_data['actual']) - 1 : -1;
$yesterday_actual = $latest_index >= 0 ? (float)$combined_chart_data['actual'][$latest_index] : 0;
$yesterday_forecast = $latest_index >= 0 ? (float)$combined_chart_data['forecast'][$latest_index] : 0;
$variance_percentage = 0;
if ($yesterday_forecast > 0) {
    $variance_percentage = (($yesterday_actual - $yesterday_forecast) / $yesterday_forecast) * 100;
}
$kpis['forecast_variance'] = round((float)$variance_percentage, 1);

// Chart 2: Product Demand
$product_demand_data = ['labels' => [], 'data' => []];
$product_demand_sql = "SELECT p.name, SUM(pdf.predicted_quantity) as total_demand
                       FROM product_demand_forecasts pdf
                       JOIN products p ON pdf.product_id = p.id
                       WHERE pdf.forecast_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)" . ($seller_scope_id !== null ? " AND p.seller_id = ?" : "") . "
                       GROUP BY p.name
                       ORDER BY total_demand DESC
                       LIMIT 5";
$stmt = $conn->prepare($product_demand_sql);
if ($seller_scope_id !== null) {
    $stmt->bind_param("i", $seller_scope_id);
}
$stmt->execute();
$product_demand_result = $stmt->get_result();
while ($row = $product_demand_result->fetch_assoc()) {
    $product_demand_data['labels'][] = $row['name'];
    $product_demand_data['data'][] = $row['total_demand'];
}
$stmt->close();
$kpis['inventory_pressure_count'] = is_array($dss_inventory_pressure) ? count($dss_inventory_pressure) : 0;
$inventory_critical_count = 0;
if (!empty($dss_inventory_pressure) && is_array($dss_inventory_pressure)) {
    $inventory_critical_count = count(array_filter($dss_inventory_pressure, static function ($item) {
        $severity = strtolower(trim((string)($item['severity'] ?? '')));
        return in_array($severity, ['critical', 'high'], true);
    }));
}
$kpis['critical_alerts'] += $inventory_critical_count;

function forecastingColumnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = mysqli_real_escape_string($conn, $table);
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && mysqli_num_rows($result) > 0;
}

function forecastingFormatCurrencyCompact($value): string
{
    $value = (float)$value;
    $abs = abs($value);
    if ($abs >= 1000000) {
        return 'PHP ' . number_format($value / 1000000, 2) . 'M';
    }
    if ($abs >= 1000) {
        return 'PHP ' . number_format($value / 1000, 1) . 'K';
    }
    return 'PHP ' . number_format($value, 2);
}

$preorder_count = 0;
$preorder_revenue = 0.0;
$preOrdersTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'pre_orders'");
if ($preOrdersTableCheck && mysqli_num_rows($preOrdersTableCheck) > 0) {
    $preorderSumColumn = forecastingColumnExists($conn, 'pre_orders', 'total_price') ? 'total_price' : (forecastingColumnExists($conn, 'pre_orders', 'total_amount') ? 'total_amount' : '');
    if ($preorderSumColumn !== '') {
        $preorderQuery = "SELECT COUNT(*) AS preorder_count,
                                 COALESCE(SUM(po.{$preorderSumColumn}), 0) AS preorder_revenue
                          FROM pre_orders po
                          WHERE po.created_at >= ?
                            AND po.created_at < ?
                            AND po.reservation_status <> 'cancelled'" . ($seller_scope_id !== null ? " AND {$seller_scoped_preorders_exists_sql}" : "");
        $preorderStmt = $conn->prepare($preorderQuery);
        if ($preorderStmt) {
            $preorderStmt->bind_param("ss", $selected_period_start, $selected_period_next);
            $preorderStmt->execute();
            $preorderRow = $preorderStmt->get_result()->fetch_assoc() ?: [];
            $preorder_count = (int)($preorderRow['preorder_count'] ?? 0);
            $preorder_revenue = (float)($preorderRow['preorder_revenue'] ?? 0);
            $preorderStmt->close();
        }
    }
}

$orders_forecast_units = round(($kpis['completed_orders'] / max(1, $period_days)) * 7);
$preorders_forecast_units = round(($preorder_count / max(1, $period_days)) * 7);
$refund_case_projection = round(($kpis['open_refund_cases'] / max(1, $period_days)) * 7, 1);
$category_forecasts = [
    [
        'title' => 'Orders',
        'icon' => 'fa-bag-shopping',
        'accent' => 'primary',
        'actual_label' => 'Completed orders',
        'actual_value' => number_format($kpis['completed_orders']),
        'secondary_label' => 'Revenue',
        'secondary_value' => forecastingFormatCurrencyCompact($kpis['monthly_revenue']),
        'forecast_label' => 'Projected next 7 days',
        'forecast_value' => number_format((float)$orders_forecast_units) . ' orders'
    ],
    [
        'title' => 'Pre-Orders',
        'icon' => 'fa-calendar-check',
        'accent' => 'success',
        'actual_label' => 'Active pre-orders',
        'actual_value' => number_format($preorder_count),
        'secondary_label' => 'Pre-order revenue',
        'secondary_value' => forecastingFormatCurrencyCompact($preorder_revenue),
        'forecast_label' => 'Projected next 7 days',
        'forecast_value' => number_format((float)$preorders_forecast_units) . ' pre-orders'
    ],
    [
        'title' => 'Refund Pressure',
        'icon' => 'fa-rotate-left',
        'accent' => 'danger',
        'actual_label' => 'Open refund cases',
        'actual_value' => number_format($kpis['open_refund_cases']),
        'secondary_label' => 'Liability',
        'secondary_value' => forecastingFormatCurrencyCompact($kpis['open_refund_liability']),
        'forecast_label' => 'Projected next 7 days',
        'forecast_value' => number_format($refund_case_projection, 1) . ' case(s)'
    ],
    [
        'title' => 'Inventory Risk',
        'icon' => 'fa-boxes-stacked',
        'accent' => 'warning',
        'actual_label' => 'Pressure items',
        'actual_value' => number_format($kpis['inventory_pressure_count']),
        'secondary_label' => 'Critical alerts',
        'secondary_value' => number_format($kpis['critical_alerts']),
        'forecast_label' => 'Watch next 7 days',
        'forecast_value' => number_format($kpis['inventory_pressure_count']) . ' tracked item(s)'
    ]
];

$variance_abs = abs((float)$kpis['forecast_variance']);
$variance_severity = 'low';
$variance_title = 'Variance is within a healthy range';
$variance_message = 'Actual revenue is staying close to the forecast baseline, so no major intervention is needed beyond regular monitoring.';
$variance_actions = [
    'Keep monitoring daily order movement and inventory pressure.',
    'Continue using the current staffing and replenishment pattern.',
    'Review major events only if sales pattern changes suddenly.'
];
if ($variance_abs >= 20) {
    $variance_severity = 'high';
    $variance_title = 'Variance is high and needs owner attention';
    $variance_message = 'Your latest actual revenue moved far away from the forecast baseline. This usually means demand, stock flow, pricing, or fulfillment changed faster than expected.';
    $variance_actions = [
        'Inspect top-selling products and confirm they were in stock during the variance period.',
        'Check refund and cancellation pressure to see if operational issues are suppressing revenue.',
        'Adjust staffing, procurement, or promotions before the next 7-day forecast window.'
    ];
} elseif ($variance_abs >= 10) {
    $variance_severity = 'medium';
    $variance_title = 'Variance is noticeable';
    $variance_message = 'Revenue is drifting away from the forecast enough to justify a closer operational review before the gap widens.';
    $variance_actions = [
        'Review daily order volume and compare it against current promotions or seasonal events.',
        'Inspect low-stock and high-pressure inventory items before they affect tomorrow sales.',
        'Check whether pending refunds or service issues are dragging the realized revenue down.'
    ];
}

function forecastingFormatPercent(?float $value, int $decimals = 1): string
{
    if ($value === null) {
        return 'No data';
    }
    return number_format((float)$value, $decimals) . '%';
}

// ============================================================================
// SECTION 4: UPCOMING EVENTS
// ============================================================================
$stmt = $conn->prepare("SELECT event_name, event_date FROM business_events WHERE event_date >= CURDATE() AND is_active = 1 ORDER BY event_date ASC LIMIT 1");
$stmt->execute();
$upcoming_event_result = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forecasting Dashboard - Lechon Delights</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --primary: #b91c1c;
            --secondary: #1d4ed8;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #f8f9fa;
            --dark: #343a40;
            --text-muted: #6c757d;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
            --border-color: #e9ecef;
        }
        
        body {
            background-color: #f1f5f9;
        }

        .forecast-hero {
            background:
                radial-gradient(circle at top right, rgba(29, 78, 216, 0.12), transparent 28%),
                radial-gradient(circle at left bottom, rgba(185, 28, 28, 0.10), transparent 28%),
                linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            padding: 24px;
            margin-bottom: 1.5rem;
        }

        .forecast-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(300px, 0.9fr);
            gap: 18px;
            align-items: start;
        }

        .forecast-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #fee2e2;
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 10px;
        }

        .forecast-hero-title {
            margin: 0 0 10px;
            font-size: 1.8rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
        }

        .forecast-hero-text {
            color: #475569;
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        .forecast-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .forecast-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #dbeafe;
            color: #1e3a8a;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .forecast-side-box {
            background: rgba(255,255,255,0.96);
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }

        .forecast-side-box strong {
            display: block;
            margin-bottom: 6px;
            color: #111827;
        }

        .forecast-side-box span {
            color: #64748b;
            font-size: 0.88rem;
        }

        .forecast-filter-card,
        .variance-playbook-card,
        .category-forecast-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }

        .category-forecast-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .category-forecast-item {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
            height: 100%;
        }

        .category-forecast-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .category-forecast-title {
            font-weight: 700;
            color: #111827;
        }

        .category-forecast-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
        }

        .variance-playbook-card ul {
            margin: 0;
            padding-left: 18px;
            color: #475569;
        }

        .variance-playbook-card li {
            margin-bottom: 8px;
        }

        .kpi-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .kpi-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
        }
        .kpi-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        .kpi-note {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }
        .card-header {
            background-color: var(--light);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem;
            font-weight: 600;
        }
        .card-header .nav-tabs {
            margin-bottom: -1rem;
            border-bottom: 0;
        }
        .card-header .nav-link {
            border: 0;
            color: var(--text-muted);
            font-weight: 500;
        }
        .card-header .nav-link.active {
            color: var(--primary);
            background-color: transparent;
            border-bottom: 3px solid var(--primary);
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .status-optimal { background-color: #27ae60; }
        .status-warning { background-color: #f39c12; }

        .recommendation-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .recommendation-item:last-child { border-bottom: none; }
        .rec-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        .rec-icon.critical { background-color: var(--danger); }
        .rec-icon.high { background-color: var(--warning); }
        .rec-icon.medium { background-color: var(--info); }
        .rec-icon.low { background-color: var(--success); }

        .score-badge {
            display: inline-block;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
        }
        .score-badge.excellent { background: linear-gradient(135deg, var(--success), #229954); }
        .score-badge.good { background: linear-gradient(135deg, var(--info), #2980b9); }
        .score-badge.fair { background: linear-gradient(135deg, var(--warning), #e67e22); }

        .chart-container {
            position: relative;
            height: 350px;
        }

        .action-btn {
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            border: none;
            color: white;
            font-size: 0.8rem;
        }
        .btn-implement { background-color: var(--success); }
        .btn-reject { background-color: var(--danger); }

        .theme-toggler {
            background: none; border: none; color: #666; font-size: 1.2rem;
            cursor: pointer; margin: 0 15px; padding: 5px; transition: color 0.3s;
        }

        /* Dark Mode */
        body.dark-mode { background-color: #1a1a1a; color: #e0e0e0; }
        body.dark-mode .admin-content { background-color: #1a1a1a !important; }
        body.dark-mode .card, body.dark-mode .kpi-card { background-color: #2d2d2d; border-color: #404040; }
        body.dark-mode .forecast-hero,
        body.dark-mode .forecast-side-box,
        body.dark-mode .forecast-chip,
        body.dark-mode .forecast-filter-card,
        body.dark-mode .variance-playbook-card,
        body.dark-mode .category-forecast-card,
        body.dark-mode .category-forecast-item { background-color: #2d2d2d; border-color: #404040; }
        body.dark-mode .card-header { background-color: #333; border-color: #404040; }
        body.dark-mode .kpi-value, body.dark-mode .card-header, body.dark-mode h5, body.dark-mode h6 { color: #e0e0e0; }
        body.dark-mode .forecast-hero-title { color: #e0e0e0; }
        body.dark-mode .forecast-hero-text,
        body.dark-mode .forecast-side-box span,
        body.dark-mode .kpi-note,
        body.dark-mode .variance-playbook-card ul { color: #b0b0b0 !important; }
        body.dark-mode .forecast-kicker { background: #3f1d1d; color: #fecaca; }
        body.dark-mode .kpi-label, body.dark-mode .text-muted, body.dark-mode .small { color: #b0b0b0 !important; }
        body.dark-mode .recommendation-item { border-color: #404040; }
        body.dark-mode .nav-link { color: #b0b0b0; }
        body.dark-mode .nav-link.active { color: var(--primary); }
        body.dark-mode .theme-toggler { color: #ffc107; }
        body.dark-mode .category-forecast-title,
        body.dark-mode .category-forecast-value { color: #e0e0e0; }
        @media (max-width: 992px) {
            .forecast-hero-grid {
                grid-template-columns: 1fr;
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
                    <h1>Forecasting & DSS Dashboard</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-main p-4">
                <div class="forecast-hero fade-in-up">
                    <div class="forecast-hero-grid">
                        <div>
                            <span class="forecast-kicker"><i class="fas fa-chart-line"></i> Forecasting Workflow</span>
                            <h2 class="forecast-hero-title">Read future demand using your real shop transactions, expenses, refunds, inventory pressure, and recommendation history.</h2>
                            <p class="forecast-hero-text">This dashboard is now aligned to your system data. The KPI cards below are based on actual orders, booked expenses, refund liability, forecast variance, and live inventory pressure instead of placeholder values. Use it to compare current performance, upcoming revenue expectations, and where your store or platform needs attention next.</p>
                            <div class="forecast-chip-row">
                                <span class="forecast-chip"><i class="fas fa-cash-register"></i> Orders and revenue</span>
                                <span class="forecast-chip"><i class="fas fa-file-invoice-dollar"></i> Expenses and net income</span>
                                <span class="forecast-chip"><i class="fas fa-rotate-left"></i> Refund pressure</span>
                                <span class="forecast-chip"><i class="fas fa-boxes-stacked"></i> Inventory demand</span>
                            </div>
                        </div>
                        <div>
                            <div class="forecast-side-box">
                                <strong>How to use this page</strong>
                                <span>Start with the KPI row for current health, then review recommendations, compare forecast versus actual revenue, and finish with inventory pressure for the next 7 days.</span>
                            </div>
                            <div class="forecast-side-box">
                                <strong>Current reporting scope</strong>
                                <span><?= $seller_scope_id !== null ? 'Business partner scope: only your shop data is included in these numbers.' : 'Platform scope: this dashboard is aggregating system-wide forecasting activity.' ?></span>
                            </div>
                            <div class="forecast-side-box">
                                <strong>Data freshness</strong>
                                <span><?= htmlspecialchars((string)$kpis['data_freshness']); ?> with <?= $kpis['system_health'] === 'optimal' ? 'healthy' : 'attention-needed' ?> system status.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="forecast-filter-card p-3 mb-4 fade-in-up">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Month</label>
                            <select name="month" class="form-select">
                                <?php for ($month = 1; $month <= 12; $month++): ?>
                                    <option value="<?= $month ?>" <?= $month === (int)$current_month ? 'selected' : '' ?>>
                                        <?= date('F', mktime(0, 0, 0, $month, 1)) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                <?php for ($year = (int)date('Y'); $year >= ((int)date('Y') - 4); $year--): ?>
                                    <option value="<?= $year ?>" <?= $year === (int)$current_year ? 'selected' : '' ?>><?= $year ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Apply Reporting Period</button>
                        </div>
                        <div class="col-12">
                            <div class="small text-muted">Current reporting period: <strong><?= htmlspecialchars($selected_period_label) ?></strong>. KPI cards, selected-period totals, and category forecasts below follow this filter.</div>
                        </div>
                    </form>
                </div>

                <!-- KPI Row -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #dbeafe; color: var(--secondary);"><i class="fas fa-coins"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['monthly_revenue']) ?></div>
                                <div class="kpi-label">Selected Period Revenue</div>
                                <div class="kpi-note">Completed and delivered orders in <?= htmlspecialchars($selected_period_label) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #fee2e2; color: var(--danger);"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['monthly_expenses']) ?></div>
                                <div class="kpi-label">Selected Period Expenses</div>
                                <div class="kpi-note">Expense records posted in <?= htmlspecialchars($selected_period_label) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #e8f5e9; color: var(--success);"><i class="fas fa-wallet"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['monthly_net_income']) ?></div>
                                <div class="kpi-label">Selected Period Net Income</div>
                                <div class="kpi-note"><?= $kpis['monthly_net_income'] >= 0 ? 'Revenue is ahead of booked costs' : 'Costs are ahead of current revenue' ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #fef3c7; color: var(--warning);"><i class="fas fa-bag-shopping"></i></div>
                            <div>
                                <div class="kpi-value"><?= number_format($kpis['completed_orders']) ?></div>
                                <div class="kpi-label">Completed Orders</div>
                                <div class="kpi-note">Delivered and completed orders in <?= htmlspecialchars($selected_period_label) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #ede9fe; color: #6d28d9;"><i class="fas fa-receipt"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['avg_order_value']) ?></div>
                                <div class="kpi-label">Average Order Value</div>
                                <div class="kpi-note">Average completed ticket size in <?= htmlspecialchars($selected_period_label) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #fee2e2; color: var(--danger);"><i class="fas fa-rotate-left"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['open_refund_liability']) ?></div>
                                <div class="kpi-label">Open Refund Liability</div>
                                <div class="kpi-note"><?= number_format($kpis['open_refund_cases']) ?> refund case(s) still unresolved</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #d1fae5; color: var(--success);"><i class="fas fa-calendar-week"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatCurrencyCompact($kpis['next_7_day_forecast']) ?></div>
                                <div class="kpi-label">Next 7-Day Revenue Forecast</div>
                                <div class="kpi-note">Projected demand based on recent transaction trend</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #fef3c7; color: var(--warning);"><i class="fas fa-chart-line"></i></div>
                            <div>
                                <div class="kpi-value"><?= number_format($kpis['forecast_variance'], 1) ?>%</div>
                                <div class="kpi-label">Latest Forecast Variance</div>
                                <div class="kpi-note">Difference between latest actual revenue and forecast baseline</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #e0f2fe; color: var(--info);"><i class="fas fa-bullseye"></i></div>
                            <div>
                                <div class="kpi-value"><?= forecastingFormatPercent($kpis['forecast_accuracy']) ?></div>
                                <div class="kpi-label">Forecast Accuracy</div>
                                <div class="kpi-note">Measured from recorded forecast evaluations or moving-average baseline</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="kpi-card fade-in-up h-100">
                            <div class="kpi-icon" style="background: #eef2ff; color: #4338ca;"><i class="fas fa-boxes-stacked"></i></div>
                            <div>
                                <div class="kpi-value"><?= number_format($kpis['inventory_pressure_count']) ?></div>
                                <div class="kpi-label">Inventory Pressure Items</div>
                                <div class="kpi-note"><?= number_format($kpis['pending_decisions']) ?> pending recommendation(s) and <?= number_format($kpis['critical_alerts']) ?> critical alert(s)</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-8">
                        <div class="category-forecast-card fade-in-up p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="mb-1"><i class="fas fa-layer-group me-2"></i>Category Forecast Panel</h5>
                                    <p class="text-muted small mb-0">Compare the current reporting period with the next 7-day outlook across orders, pre-orders, refund pressure, and inventory risk.</p>
                                </div>
                            </div>
                            <div class="category-forecast-grid">
                                <?php foreach ($category_forecasts as $panel): ?>
                                    <div class="category-forecast-item">
                                        <div class="category-forecast-top">
                                            <div class="category-forecast-title"><?= htmlspecialchars($panel['title']) ?></div>
                                            <span class="badge bg-<?= htmlspecialchars($panel['accent']) ?>"><i class="fas <?= htmlspecialchars($panel['icon']) ?>"></i></span>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($panel['actual_label']) ?></div>
                                        <div class="category-forecast-value"><?= htmlspecialchars($panel['actual_value']) ?></div>
                                        <div class="small text-muted mt-2"><?= htmlspecialchars($panel['secondary_label']) ?></div>
                                        <div class="fw-semibold"><?= htmlspecialchars($panel['secondary_value']) ?></div>
                                        <hr>
                                        <div class="small text-muted"><?= htmlspecialchars($panel['forecast_label']) ?></div>
                                        <div class="fw-semibold text-primary"><?= htmlspecialchars($panel['forecast_value']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="variance-playbook-card fade-in-up p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="mb-1"><i class="fas fa-triangle-exclamation me-2"></i>Variance Action Guide</h5>
                                    <p class="text-muted small mb-0">Owner-facing explanation for what to do when realized revenue drifts away from forecast.</p>
                                </div>
                                <span class="badge bg-<?= $variance_severity === 'high' ? 'danger' : ($variance_severity === 'medium' ? 'warning text-dark' : 'success') ?>">
                                    <?= strtoupper($variance_severity) ?>
                                </span>
                            </div>
                            <div class="mb-2 fw-bold"><?= htmlspecialchars($variance_title) ?></div>
                            <p class="text-muted small"><?= htmlspecialchars($variance_message) ?></p>
                            <ul>
                                <?php foreach ($variance_actions as $variance_action): ?>
                                    <li><?= htmlspecialchars($variance_action) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="small text-muted mt-3">Current variance: <strong><?= number_format($kpis['forecast_variance'], 1) ?>%</strong></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card fade-in-up h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5><i class="fas fa-lightbulb me-2"></i>Recommended Actions</h5>
                                <a href="dss_reports.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($scored_recommendations)): ?>
                                    <div class="alert alert-success text-center">No pending recommendations. The system is optimized.</div>
                                <?php else: ?>
                                    <?php foreach (array_slice($scored_recommendations, 0, 5) as $rec): ?>
                                        <div class="recommendation-item" id="rec-row-<?php echo $rec['recommendation_id']; ?>">
                                            <div class="rec-icon <?= strtolower($rec['priority']) ?>"><i class="fas fa-cogs"></i></div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between">
                                                    <h6 class="mb-1">
                                                        <?= ucfirst($rec['decision_category']) ?>
                                                        <span class="badge bg-secondary"><?= ucfirst($rec['priority']) ?></span>
                                                    </h6>
                                                    <span class="score-badge <?= $rec['score_class'] ?>">
                                                        Score: <?= round($rec['score'], 1) ?>
                                                    </span>
                                                </div>
                                                <p class="mb-1 text-muted small"><?= substr($rec['recommendation_text'], 0, 180) ?>...</p>
                                                <div class="mt-2">
                                                    <button class="action-btn btn-implement" onclick="handleRecommendation(<?php echo $rec['recommendation_id']; ?>, 'implemented')"><i class="fas fa-check"></i> Implement</button>
                                                    <button class="action-btn btn-reject" onclick="handleRecommendation(<?php echo $rec['recommendation_id']; ?>, 'rejected')"><i class="fas fa-times"></i> Reject</button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card fade-in-up h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-brain me-2"></i>Decision Brief</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($dss_decision_brief)): ?>
                                    <div class="alert alert-light border mb-0">No decision brief is available yet for the current scope. Once more recommendations and forecasting records are generated, this panel will summarize the most important next actions.</div>
                                <?php else: ?>
                                    <?php foreach ($dss_decision_brief as $brief): ?>
                                        <div class="mb-3 pb-2 border-bottom">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <strong><?= htmlspecialchars($brief['headline']) ?></strong>
                                                <span class="badge bg-<?= $brief['priority'] === 'critical' ? 'danger' : ($brief['priority'] === 'high' ? 'warning text-dark' : 'info') ?>">
                                                    <?= strtoupper($brief['priority']) ?>
                                                </span>
                                            </div>
                                            <p class="small text-muted mb-1"><?= htmlspecialchars($brief['rationale']) ?></p>
                                            <p class="small mb-1"><strong>Action:</strong> <?= htmlspecialchars($brief['action']) ?></p>
                                            <p class="small text-success mb-0"><strong>Expected:</strong> <?= htmlspecialchars($brief['expected_outcome']) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-lg-8">
                        <div class="card fade-in-up">
                            <div class="card-header">
                                <ul class="nav nav-tabs card-header-tabs" id="analytics-tabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="revenue-tab" data-bs-toggle="tab" data-bs-target="#revenue" type="button" role="tab">Revenue vs Forecast</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="demand-tab" data-bs-toggle="tab" data-bs-target="#demand" type="button" role="tab">Product Demand</button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="analytics-tabs-content">
                                    <div class="tab-pane fade show active" id="revenue" role="tabpanel">
                                        <div class="chart-container">
                                            <canvas id="revenueForecastChart"></canvas>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="demand" role="tabpanel">
                                        <div class="chart-container">
                                            <canvas id="productDemandChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card fade-in-up mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Forecast Snapshot</h6>
                                        <p class="mb-0 text-muted small">Data freshness: <?= $kpis['data_freshness'] ?></p>
                                    </div>
                                    <div class="text-end">
                                        <span class="status-indicator status-<?= $kpis['system_health'] === 'optimal' ? 'optimal' : 'warning' ?>"></span>
                                        <span class="fw-bold"><?= ucfirst($kpis['system_health']) ?></span>
                                    </div>
                                </div>
                                <hr>
                                <div class="small">
                                    <div class="d-flex justify-content-between mb-1"><span><?= htmlspecialchars($selected_period_label) ?> Revenue</span><strong>PHP <?= number_format($dss_overview['total_revenue'], 2) ?></strong></div>
                                    <div class="d-flex justify-content-between mb-1"><span>Records Analyzed</span><strong><?= number_format($dss_overview['records_analyzed']) ?></strong></div>
                                    <div class="d-flex justify-content-between mb-1"><span>Net Margin</span><strong><?= number_format($dss_overview['net_margin'], 1) ?>%</strong></div>
                                    <div class="d-flex justify-content-between mb-1"><span>Next 7-day Forecast</span><strong>PHP <?= number_format($dss_forecast_summary['predicted_revenue'], 2) ?></strong></div>
                                    <div class="d-flex justify-content-between"><span>Avg Forecast Confidence</span><strong><?= number_format($dss_forecast_summary['avg_confidence'], 1) ?>%</strong></div>
                                </div>
                                <?php if ($upcoming_event_result): ?>
                                    <hr>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Upcoming Event</h6>
                                            <p class="mb-0 text-muted small"><?= htmlspecialchars($upcoming_event_result['event_name']) ?></p>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold"><?= date('M d', strtotime($upcoming_event_result['event_date'])) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card fade-in-up">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-boxes-stacked me-2"></i>Inventory Pressure (7 Days)</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($dss_inventory_pressure)): ?>
                                    <div class="alert alert-success mb-0 small">Inventory pressure is low. No urgent stock action needed.</div>
                                <?php else: ?>
                                    <?php foreach ($dss_inventory_pressure as $inv): ?>
                                        <?php
                                        $severity_class = 'secondary';
                                        if ($inv['severity'] === 'critical') { $severity_class = 'danger'; }
                                        elseif ($inv['severity'] === 'high') { $severity_class = 'warning'; }
                                        elseif ($inv['severity'] === 'medium') { $severity_class = 'info'; }
                                        ?>
                                        <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                                            <div>
                                                <strong class="small"><?= htmlspecialchars($inv['product_name']) ?></strong>
                                                <div class="text-muted small">Stock: <?= $inv['stock'] ?> | Demand: <?= number_format($inv['forecast_demand'], 1) ?></div>
                                            </div>
                                            <span class="badge bg-<?= $severity_class ?>"><?= strtoupper($inv['severity']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="small text-muted mt-2">
                                    High-impact upcoming events: <strong><?= (int)$dss_event_insights['high_impact_events'] ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4 text-center text-muted small">
                    <small>Last updated: <?= date('M d, Y H:i:s') ?> | System Status: <span class="status-indicator status-<?= $kpis['system_health'] === 'optimal' ? 'optimal' : 'warning' ?>"></span> <?= ucfirst($kpis['system_health']) ?></small>
                </div>
            </div>
        </div>
    </div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="admin.js"></script>
<script>
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
$(document).ready(function() {
    // Chart.js configurations
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' } }
    };

    // Revenue vs Forecast Chart
    const revenueCtx = document.getElementById('revenueForecastChart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($combined_chart_data['labels']); ?>,
            datasets: [{
                label: 'Forecasted Revenue',
                data: <?php echo json_encode($combined_chart_data['forecast']); ?>,
                backgroundColor: 'rgba(118, 75, 162, 0.1)',
                borderColor: 'rgba(118, 75, 162, 1)',
                borderWidth: 2,
                borderDash: [5, 5],
                pointRadius: 0,
                fill: false,
                tension: 0.4
            },{
                label: 'Actual Revenue',
                data: <?php echo json_encode($combined_chart_data['actual']); ?>,
                backgroundColor: 'rgba(25, 135, 84, 0.2)',
                borderColor: 'rgba(25, 135, 84, 1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: chartOptions
    });

    // Product Demand Chart
    const productCtx = document.getElementById('productDemandChart').getContext('2d');
    new Chart(productCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($product_demand_data['labels'] ?? []); ?>,
            datasets: [{
                label: 'Predicted Units',
                data: <?php echo json_encode($product_demand_data['data'] ?? []); ?>,
                backgroundColor: 'rgba(102, 126, 234, 0.8)',
                borderColor: 'rgba(102, 126, 234, 1)',
                borderWidth: 1
            }]
        },
        options: { 
            ...chartOptions, 
            indexAxis: 'y',
            plugins: { legend: { display: false } }
        }
    });
});

function handleRecommendation(id, status) {
    const actionText = status === 'implemented' ? 'implement' : 'reject';
    const confirmButtonColor = status === 'implemented' ? '#28a745' : '#dc3545';

    Swal.fire({
        title: `Are you sure you want to ${actionText} this recommendation?`,
        text: "This action will be recorded.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: confirmButtonColor,
        cancelButtonColor: '#6c757d',
        confirmButtonText: `Yes, ${actionText} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'update_recommendation_status.php',
                type: 'POST',
                data: {
                    recommendation_id: id,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire(
                            'Success!',
                            `Recommendation has been marked as ${status}.`,
                            'success'
                        );
                        $('#rec-row-' + id).slideUp(500, function() {
                            $(this).remove();
                            if ($('.recommendation-item').length === 0) {
                                $('.card-body').html('<div class="alert alert-success text-center">No pending recommendations. The system is optimized!</div>');
                            }
                        });
                    } else {
                        Swal.fire(
                            'Error!',
                            response.message || 'An unknown error occurred.',
                            'error'
                        );
                    }
                },
                error: function() {
                    Swal.fire(
                        'Error!',
                        'Could not connect to the server.',
                        'error'
                    );
                }
            });
        }
    });
}
</script>
</body>
</html>
