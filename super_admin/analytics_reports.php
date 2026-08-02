<?php
require_once __DIR__ . '/module_common.php';

$has_users = saTableExists($conn, 'users');
$has_orders = saTableExists($conn, 'orders');
$has_payments = saTableExists($conn, 'payments');
$has_activity_logs = saTableExists($conn, 'activity_logs');
$has_audit_logs = saTableExists($conn, 'audit_logs');
$has_order_items = saTableExists($conn, 'order_items');
$has_products = saTableExists($conn, 'products');

if (!function_exists('saMonthLabels')) {
    function saMonthLabels($months_back = 6) {
        $labels = [];
        $keys = [];
        $months_back = max(1, (int)$months_back);
        for ($i = $months_back - 1; $i >= 0; $i--) {
            $timestamp = strtotime("-{$i} month");
            $keys[] = date('Y-m', $timestamp);
            $labels[] = date('M Y', $timestamp);
        }
        return ['keys' => $keys, 'labels' => $labels];
    }
}

if (!function_exists('saDateLabels')) {
    function saDateLabels($days_back = 14) {
        $labels = [];
        $keys = [];
        $days_back = max(1, (int)$days_back);
        for ($i = $days_back - 1; $i >= 0; $i--) {
            $timestamp = strtotime("-{$i} day");
            $keys[] = date('Y-m-d', $timestamp);
            $labels[] = date('M d', $timestamp);
        }
        return ['keys' => $keys, 'labels' => $labels];
    }
}

$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export !== '') {
    if ($export === 'users') {
        if (!$has_users) {
            saSetFlash('warning', 'Users export is unavailable because `users` table is missing.');
            header('Location: analytics_reports.php');
            exit;
        }
        $rows = saQueryRows(
            $conn,
            "SELECT id, full_name, email, user_type, account_type, business_name, is_active, created_at, last_login
             FROM users
             ORDER BY created_at DESC"
        );
        $csv_rows = [];
        foreach ($rows as $row) {
            $csv_rows[] = [
                $row['id'] ?? '',
                $row['full_name'] ?? '',
                $row['email'] ?? '',
                $row['user_type'] ?? '',
                $row['account_type'] ?? '',
                $row['business_name'] ?? '',
                (int)($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive',
                $row['created_at'] ?? '',
                $row['last_login'] ?? ''
            ];
        }
        saOutputCsv(
            'super_admin_users_export.csv',
            ['ID', 'Full Name', 'Email', 'User Type', 'Account Type', 'Business Name', 'Status', 'Created At', 'Last Login'],
            $csv_rows
        );
    }

    if ($export === 'transactions') {
        if (!$has_payments) {
            saSetFlash('warning', 'Transaction export is unavailable because `payments` table is missing.');
            header('Location: analytics_reports.php');
            exit;
        }
        $join_orders = $has_orders ? "LEFT JOIN orders o ON o.id = p.order_id" : "";
        $rows = saQueryRows(
            $conn,
            "SELECT p.id, p.order_id, p.payment_type, p.amount, p.payment_method, p.status, p.transaction_id, p.created_at,
                    " . ($has_orders ? "o.order_number, o.customer_name, o.total_amount" : "NULL AS order_number, NULL AS customer_name, NULL AS total_amount") . "
             FROM payments p
             {$join_orders}
             ORDER BY p.created_at DESC"
        );
        $csv_rows = [];
        foreach ($rows as $row) {
            $csv_rows[] = [
                $row['id'] ?? '',
                $row['order_id'] ?? '',
                $row['order_number'] ?? '',
                $row['customer_name'] ?? '',
                $row['payment_type'] ?? '',
                $row['payment_method'] ?? '',
                $row['status'] ?? '',
                $row['amount'] ?? '',
                $row['total_amount'] ?? '',
                $row['transaction_id'] ?? '',
                $row['created_at'] ?? ''
            ];
        }
        saOutputCsv(
            'super_admin_transactions_export.csv',
            ['Payment ID', 'Order ID', 'Order Number', 'Customer', 'Payment Type', 'Method', 'Status', 'Amount', 'Order Total', 'Transaction ID', 'Created At'],
            $csv_rows
        );
    }

    if ($export === 'activity') {
        if (!$has_activity_logs && !$has_audit_logs) {
            saSetFlash('warning', 'Activity export is unavailable because log tables are missing.');
            header('Location: analytics_reports.php');
            exit;
        }
        $queries = [];
        if ($has_audit_logs) {
            $queries[] = "SELECT CONVERT('audit' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                                 id,
                                 user_id,
                                 CONVERT(action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                                 CONVERT(module USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                                 CONVERT(description USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                                 CONVERT(ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                                 created_at
                          FROM audit_logs";
        }
        if ($has_activity_logs) {
            $queries[] = "SELECT CONVERT('activity' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                                 id,
                                 user_id,
                                 CONVERT(action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                                 CONVERT(entity_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                                 CONVERT(CAST(details AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                                 CONVERT(ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                                 created_at
                          FROM activity_logs";
        }
        $rows = saQueryRows($conn, implode(' UNION ALL ', $queries) . " ORDER BY created_at DESC");
        $csv_rows = [];
        foreach ($rows as $row) {
            $csv_rows[] = [
                $row['source'] ?? '',
                $row['id'] ?? '',
                $row['user_id'] ?? '',
                $row['action'] ?? '',
                $row['context'] ?? '',
                $row['details'] ?? '',
                $row['ip_address'] ?? '',
                $row['created_at'] ?? ''
            ];
        }
        saOutputCsv(
            'super_admin_activity_export.csv',
            ['Source', 'Log ID', 'User ID', 'Action', 'Context', 'Details', 'IP Address', 'Created At'],
            $csv_rows
        );
    }

    saSetFlash('warning', 'Unknown export request.');
    header('Location: analytics_reports.php');
    exit;
}

$month_map = saMonthLabels(6);
$month_keys = $month_map['keys'];
$month_labels = $month_map['labels'];

$users_monthly_map = array_fill_keys($month_keys, 0);
if ($has_users) {
    $rows = saQueryRows(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
         FROM users
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(created_at, '%Y-%m')
         ORDER BY ym ASC"
    );
    foreach ($rows as $row) {
        $key = (string)($row['ym'] ?? '');
        if (array_key_exists($key, $users_monthly_map)) {
            $users_monthly_map[$key] = (int)($row['total'] ?? 0);
        }
    }
}
$users_monthly_values = array_values($users_monthly_map);

$transactions_monthly_count_map = array_fill_keys($month_keys, 0);
$transactions_monthly_revenue_map = array_fill_keys($month_keys, 0.0);
if ($has_payments) {
    $rows = saQueryRows(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COUNT(*) AS total_count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount
         FROM payments
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(created_at, '%Y-%m')
         ORDER BY ym ASC"
    );
    foreach ($rows as $row) {
        $key = (string)($row['ym'] ?? '');
        if (array_key_exists($key, $transactions_monthly_count_map)) {
            $transactions_monthly_count_map[$key] = (int)($row['total_count'] ?? 0);
            $transactions_monthly_revenue_map[$key] = (float)($row['paid_amount'] ?? 0);
        }
    }
}

$transactions_monthly_count = array_values($transactions_monthly_count_map);
$transactions_monthly_revenue = array_values($transactions_monthly_revenue_map);

$daily_map = saDateLabels(14);
$daily_keys = $daily_map['keys'];
$daily_labels = $daily_map['labels'];
$daily_activity_map = array_fill_keys($daily_keys, 0);

if ($has_activity_logs || $has_audit_logs) {
    $union_parts = [];
    if ($has_audit_logs) {
        $union_parts[] = "SELECT DATE(created_at) AS day_key, COUNT(*) AS total
                          FROM audit_logs
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                          GROUP BY DATE(created_at)";
    }
    if ($has_activity_logs) {
        $union_parts[] = "SELECT DATE(created_at) AS day_key, COUNT(*) AS total
                          FROM activity_logs
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                          GROUP BY DATE(created_at)";
    }

    if (!empty($union_parts)) {
        $rows = saQueryRows(
            $conn,
            "SELECT day_key, SUM(total) AS total
             FROM (" . implode(' UNION ALL ', $union_parts) . ") usage_by_day
             GROUP BY day_key
             ORDER BY day_key ASC"
        );
        foreach ($rows as $row) {
            $key = (string)($row['day_key'] ?? '');
            if (array_key_exists($key, $daily_activity_map)) {
                $daily_activity_map[$key] = (int)($row['total'] ?? 0);
            }
        }
    }
}
$daily_activity_values = array_values($daily_activity_map);

$total_users = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users", 0) : 0;
$total_transactions = $has_payments ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM payments", 0) : 0;
$paid_revenue = $has_payments ? (float)saQueryScalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'", 0) : 0.0;
$log_volume = ($has_audit_logs ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM audit_logs", 0) : 0)
    + ($has_activity_logs ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM activity_logs", 0) : 0);

$peak_hour_label = 'N/A';
if ($has_activity_logs || $has_audit_logs) {
    $hour_queries = [];
    if ($has_audit_logs) {
        $hour_queries[] = "SELECT HOUR(created_at) AS hour_bucket, COUNT(*) AS total FROM audit_logs GROUP BY HOUR(created_at)";
    }
    if ($has_activity_logs) {
        $hour_queries[] = "SELECT HOUR(created_at) AS hour_bucket, COUNT(*) AS total FROM activity_logs GROUP BY HOUR(created_at)";
    }
    $rows = saQueryRows(
        $conn,
        "SELECT hour_bucket, SUM(total) AS total
         FROM (" . implode(' UNION ALL ', $hour_queries) . ") usage_hours
         GROUP BY hour_bucket
         ORDER BY total DESC
         LIMIT 1"
    );
    if (!empty($rows) && $rows[0]['hour_bucket'] !== null) {
        $hour = (int)$rows[0]['hour_bucket'];
        $peak_hour_label = date('g:00 A', strtotime(sprintf('%02d:00:00', $hour)));
    }
}

$top_businesses = [];
if ($has_order_items && $has_products && $has_users && saColumnExists($conn, 'products', 'seller_id') && saColumnExists($conn, 'products', 'id')) {
    $join_on = "oi.product_id = CAST(p.id AS CHAR)";
    if (saColumnExists($conn, 'products', 'product_id')) {
        $join_on .= " OR (p.product_id IS NOT NULL AND p.product_id <> '' AND oi.product_id = p.product_id)";
    }
    $has_order_archived = $has_orders && saColumnExists($conn, 'orders', 'is_archived');
    $archived_clause = $has_orders && $has_order_archived ? "AND o.is_archived = 0" : "";
    $top_businesses = saQueryRows(
        $conn,
        "SELECT p.seller_id,
                COALESCE(NULLIF(u.business_name, ''), u.full_name, CONCAT('Business #', p.seller_id)) AS business_name,
                COUNT(DISTINCT oi.order_id) AS orders,
                COALESCE(SUM(oi.total), 0) AS sales
         FROM order_items oi
         INNER JOIN products p ON ({$join_on})
         INNER JOIN users u ON u.id = p.seller_id
         " . ($has_orders ? "LEFT JOIN orders o ON o.id = oi.order_id" : "") . "
         WHERE p.seller_id IS NOT NULL {$archived_clause}
         GROUP BY p.seller_id, business_name
         ORDER BY sales DESC
         LIMIT 6"
    );
}

saRenderModuleHeader('Analytics & Reports', 'Analytics & Reports', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Analytics Overview</h2>
            <p class="module-subtext">Use real-time trends and exports to support platform-level decisions.</p>
        </div>
        <div class="module-inline-actions">
            <a href="../super_admin/analytics_reports.php?export=users" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv"></i> Export Users</a>
            <a href="../super_admin/analytics_reports.php?export=transactions" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv"></i> Export Transactions</a>
            <a href="../super_admin/analytics_reports.php?export=activity" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv"></i> Export Activity</a>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Users</span>
            <div class="metric-value"><?php echo number_format($total_users); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Total Transactions</span>
            <div class="metric-value"><?php echo number_format($total_transactions); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Paid Revenue</span>
            <div class="metric-value"><?php echo htmlspecialchars(saFormatCurrency($paid_revenue)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Peak Usage Time</span>
            <div class="metric-value"><?php echo htmlspecialchars($peak_hour_label); ?></div>
            <span class="compact-text"><?php echo number_format($log_volume); ?> total log events</span>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Usage and Transaction Trends</h3>
            <p class="module-subtext">Users, transaction volume, and activity over time.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="metric-card" style="height: 300px;">
                <canvas id="usersTrendChart"></canvas>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="metric-card" style="height: 300px;">
                <canvas id="transactionsTrendChart"></canvas>
            </div>
        </div>
        <div class="col-12">
            <div class="metric-card" style="height: 320px;">
                <canvas id="activityTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Top Active Businesses</h3>
            <p class="module-subtext">Businesses with highest recorded sales and order interactions.</p>
        </div>
    </div>
    <?php if (empty($top_businesses)): ?>
        <div class="note-box">No business sales metrics are currently available. This requires `products.seller_id` and `order_items` records.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table" style="min-width: 620px;">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Orders</th>
                        <th>Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_businesses as $business): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($business['business_name'] ?? 'Unnamed')); ?></td>
                            <td><?php echo number_format((int)($business['orders'] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(saFormatCurrency($business['sales'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extra_scripts .= '<script>';
$extra_scripts .= 'const monthLabels = ' . json_encode($month_labels) . ';';
$extra_scripts .= 'const usersMonthly = ' . json_encode($users_monthly_values) . ';';
$extra_scripts .= 'const txMonthlyCount = ' . json_encode($transactions_monthly_count) . ';';
$extra_scripts .= 'const txMonthlyRevenue = ' . json_encode($transactions_monthly_revenue) . ';';
$extra_scripts .= 'const dailyLabels = ' . json_encode($daily_labels) . ';';
$extra_scripts .= 'const dailyActivity = ' . json_encode($daily_activity_values) . ';';
$extra_scripts .= <<<JS
const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

const usersCtx = document.getElementById('usersTrendChart');
if (usersCtx) {
    new Chart(usersCtx, {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'New Users',
                data: usersMonthly,
                backgroundColor: '#2563eb'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true } },
            scales: { y: { beginAtZero: true } }
        }
    });
}

const txCtx = document.getElementById('transactionsTrendChart');
if (txCtx) {
    new Chart(txCtx, {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: 'Transaction Count',
                    data: txMonthlyCount,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.12)',
                    yAxisID: 'y',
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'Paid Revenue',
                    data: txMonthlyRevenue,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.12)',
                    yAxisID: 'y1',
                    tension: 0.35,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left' },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: {
                        callback: (val) => peso.format(Number(val || 0))
                    }
                }
            }
        }
    });
}

const activityCtx = document.getElementById('activityTrendChart');
if (activityCtx) {
    new Chart(activityCtx, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Activity Events',
                data: dailyActivity,
                borderColor: '#7c3aed',
                backgroundColor: 'rgba(124, 58, 237, 0.15)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
    });
}
JS;
$extra_scripts .= '</script>';

saRenderModuleFooter($extra_scripts);
