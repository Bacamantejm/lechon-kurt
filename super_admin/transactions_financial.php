<?php
require_once __DIR__ . '/module_common.php';

$has_payments = saTableExists($conn, 'payments');
$has_orders = saTableExists($conn, 'orders');
$has_users = saTableExists($conn, 'users');

if ($has_payments && strtolower(trim((string)($_GET['export'] ?? ''))) === 'csv') {
    $join_orders = $has_orders ? "LEFT JOIN orders o ON o.id = p.order_id" : "";
    $join_users = ($has_orders && $has_users) ? "LEFT JOIN users u ON u.id = o.user_id" : "";

    $rows = saQueryRows(
        $conn,
        "SELECT p.id, p.order_id, p.payment_type, p.amount, p.payment_method, p.status, p.transaction_id, p.created_at, p.paid_at,
                " . ($has_orders ? "o.order_number, o.total_amount, o.payment_status, o.status AS order_status" : "NULL AS order_number, NULL AS total_amount, NULL AS payment_status, NULL AS order_status") . ",
                " . (($has_orders && $has_users) ? "COALESCE(u.full_name, o.customer_name, '') AS customer_name" : ($has_orders ? "COALESCE(o.customer_name, '') AS customer_name" : "'' AS customer_name")) . "
         FROM payments p
         {$join_orders}
         {$join_users}
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
            $row['payment_status'] ?? '',
            $row['order_status'] ?? '',
            $row['transaction_id'] ?? '',
            $row['created_at'] ?? '',
            $row['paid_at'] ?? ''
        ];
    }

    saOutputCsv(
        'super_admin_transactions_financial.csv',
        ['Payment ID', 'Order ID', 'Order Number', 'Customer', 'Payment Type', 'Method', 'Payment Status', 'Amount', 'Order Total', 'Order Payment Status', 'Order Status', 'Transaction ID', 'Created At', 'Paid At'],
        $csv_rows
    );
}

$total_transactions = 0;
$paid_transactions = 0;
$pending_transactions = 0;
$failed_transactions = 0;
$paid_amount_total = 0.0;
$pending_amount_total = 0.0;
$failed_amount_total = 0.0;

if ($has_payments) {
    $total_transactions = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM payments", 0);
    $paid_transactions = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM payments WHERE status = 'paid'", 0);
    $pending_transactions = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM payments WHERE status IN ('pending', 'processing')", 0);
    $failed_transactions = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM payments WHERE status IN ('failed', 'cancelled')", 0);

    $paid_amount_total = (float)saQueryScalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'", 0);
    $pending_amount_total = (float)saQueryScalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status IN ('pending', 'processing')", 0);
    $failed_amount_total = (float)saQueryScalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status IN ('failed', 'cancelled')", 0);
}

$status_breakdown = [];
if ($has_payments) {
    $status_breakdown = saQueryRows(
        $conn,
        "SELECT status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount_total
         FROM payments
         GROUP BY status
         ORDER BY total DESC"
    );
}

$method_breakdown = [];
if ($has_payments) {
    $method_breakdown = saQueryRows(
        $conn,
        "SELECT payment_method, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount_total
         FROM payments
         GROUP BY payment_method
         ORDER BY amount_total DESC"
    );
}

$month_keys = [];
$month_labels = [];
$monthly_revenue = [];
for ($i = 5; $i >= 0; $i--) {
    $month_keys[] = date('Y-m', strtotime("-{$i} month"));
    $month_labels[] = date('M Y', strtotime("-{$i} month"));
}
$monthly_map = array_fill_keys($month_keys, 0.0);
if ($has_payments) {
    $rows = saQueryRows(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount
         FROM payments
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(created_at, '%Y-%m')
         ORDER BY ym ASC"
    );
    foreach ($rows as $row) {
        $key = (string)($row['ym'] ?? '');
        if (array_key_exists($key, $monthly_map)) {
            $monthly_map[$key] = (float)($row['paid_amount'] ?? 0);
        }
    }
}
$monthly_revenue = array_values($monthly_map);

$transactions = [];
if ($has_payments) {
    $join_orders = $has_orders ? "LEFT JOIN orders o ON o.id = p.order_id" : "";
    $join_users = ($has_orders && $has_users) ? "LEFT JOIN users u ON u.id = o.user_id" : "";
    $transactions = saQueryRows(
        $conn,
        "SELECT p.id, p.order_id, p.payment_type, p.amount, p.payment_method, p.status, p.transaction_id, p.created_at, p.paid_at,
                " . ($has_orders ? "o.order_number, o.total_amount, o.status AS order_status, o.payment_status" : "NULL AS order_number, NULL AS total_amount, NULL AS order_status, NULL AS payment_status") . ",
                " . (($has_orders && $has_users) ? "COALESCE(u.full_name, o.customer_name, '') AS customer_name" : ($has_orders ? "COALESCE(o.customer_name, '') AS customer_name" : "'' AS customer_name")) . "
         FROM payments p
         {$join_orders}
         {$join_users}
         ORDER BY p.created_at DESC
         LIMIT 250"
    );
}

saRenderModuleHeader('Transactions & Financial', 'Transactions & Financial', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Financial Monitoring Overview</h2>
            <p class="module-subtext">Track payments, transaction health, and revenue trends for platform governance.</p>
        </div>
        <a href="../super_admin/transactions_financial.php?export=csv" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="../super_admin/platform_monetization.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-sack-dollar"></i> Monetization
        </a>
    </div>

    <?php if (!$has_payments): ?>
        <div class="note-box">`payments` table is unavailable. Financial analytics cannot be computed.</div>
    <?php else: ?>
        <div class="metrics-grid">
            <div class="metric-card">
                <span class="metric-label">Total Transactions</span>
                <div class="metric-value"><?php echo number_format($total_transactions); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Paid Revenue</span>
                <div class="metric-value"><?php echo htmlspecialchars(saFormatCurrency($paid_amount_total)); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Pending Amount</span>
                <div class="metric-value"><?php echo htmlspecialchars(saFormatCurrency($pending_amount_total)); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Failed/Cancelled Amount</span>
                <div class="metric-value"><?php echo htmlspecialchars(saFormatCurrency($failed_amount_total)); ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Revenue and Payment Trends</h3>
            <p class="module-subtext">Monthly paid revenue and payment status distribution.</p>
        </div>
    </div>
    <?php if (!$has_payments): ?>
        <div class="note-box">Payment trend charts are unavailable because `payments` table is missing.</div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="metric-card" style="height: 300px;">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="metric-card" style="height: 300px;">
                    <canvas id="paymentStatusChart"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Payment Method Breakdown</h3>
            <p class="module-subtext">Visibility into preferred payment methods and their collected volume.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table" style="min-width: 560px;">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th>Transactions</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($method_breakdown)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No payment method data available.</td></tr>
                <?php else: ?>
                    <?php foreach ($method_breakdown as $method): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($method['payment_method'] ?? 'unknown')); ?></td>
                            <td><?php echo number_format((int)($method['total'] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(saFormatCurrency($method['amount_total'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Transaction History</h3>
            <p class="module-subtext">Detailed payment records for auditing and reconciliation.</p>
        </div>
    </div>
    <?php if (!$has_payments): ?>
        <div class="note-box">Transaction history is unavailable because `payments` table is missing.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Created</th>
                        <th>Payment</th>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No transactions found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $txn): ?>
                            <?php
                                $status = strtolower((string)($txn['status'] ?? 'pending'));
                                $chip_class = 'chip-muted';
                                if ($status === 'paid') {
                                    $chip_class = 'chip-success';
                                } elseif ($status === 'pending' || $status === 'processing') {
                                    $chip_class = 'chip-warning';
                                } elseif ($status === 'failed' || $status === 'cancelled') {
                                    $chip_class = 'chip-danger';
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(saFormatDateTime($txn['created_at'] ?? null)); ?></td>
                                <td>
                                    <strong>#<?php echo (int)$txn['id']; ?></strong><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($txn['payment_type'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars((string)($txn['order_number'] ?? ('Order #' . (int)$txn['order_id']))); ?><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($txn['order_status'] ?? '')); ?> / <?php echo htmlspecialchars((string)($txn['payment_status'] ?? '')); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string)($txn['customer_name'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($txn['payment_method'] ?? '-')); ?></td>
                                <td><span class="status-chip <?php echo $chip_class; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                <td>
                                    <?php echo htmlspecialchars(saFormatCurrency($txn['amount'] ?? 0)); ?><br>
                                    <span class="compact-text">Txn: <?php echo htmlspecialchars((string)($txn['transaction_id'] ?? 'N/A')); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
$status_labels = [];
$status_counts = [];
foreach ($status_breakdown as $status_row) {
    $status_labels[] = ucfirst((string)($status_row['status'] ?? 'unknown'));
    $status_counts[] = (int)($status_row['total'] ?? 0);
}

$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extra_scripts .= '<script>';
$extra_scripts .= 'const revenueLabels = ' . json_encode($month_labels) . ';';
$extra_scripts .= 'const revenueValues = ' . json_encode($monthly_revenue) . ';';
$extra_scripts .= 'const paymentStatusLabels = ' . json_encode($status_labels) . ';';
$extra_scripts .= 'const paymentStatusCounts = ' . json_encode($status_counts) . ';';
$extra_scripts .= <<<JS
const pesoFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

const revenueChart = document.getElementById('revenueTrendChart');
if (revenueChart) {
    new Chart(revenueChart, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Paid Revenue',
                data: revenueValues,
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22, 163, 74, 0.14)',
                tension: 0.35,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => pesoFormatter.format(Number(value || 0))
                    }
                }
            }
        }
    });
}

const statusChart = document.getElementById('paymentStatusChart');
if (statusChart) {
    new Chart(statusChart, {
        type: 'doughnut',
        data: {
            labels: paymentStatusLabels,
            datasets: [{
                data: paymentStatusCounts,
                backgroundColor: ['#16a34a', '#f59e0b', '#ef4444', '#0ea5e9', '#64748b']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}
JS;
$extra_scripts .= '</script>';

saRenderModuleFooter($extra_scripts);
