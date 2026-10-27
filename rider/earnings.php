<?php
$page_title = 'Rider Earnings';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
require_once __DIR__ . '/header.php';

$rider_pk = (int)$rider['id'];

// Filter range: today, week, month, custom
$filter = $_GET['filter'] ?? 'today';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$date_where = "DATE(re.earned_at) = CURDATE()";
if ($filter === 'week') {
    $date_where = "YEARWEEK(re.earned_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($filter === 'month') {
    $date_where = "MONTH(re.earned_at) = MONTH(CURDATE()) AND YEAR(re.earned_at) = YEAR(CURDATE())";
} elseif ($filter === 'custom' && !empty($start_date) && !empty($end_date)) {
    $safe_start = mysqli_real_escape_string($conn, $start_date);
    $safe_end = mysqli_real_escape_string($conn, $end_date);
    $date_where = "DATE(re.earned_at) BETWEEN '$safe_start' AND '$safe_end'";
}

// 1. Overall Aggregates
$agg_res = mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(earned_at) = CURDATE() THEN total_earnings ELSE 0 END), 0) AS today_earn,
        COALESCE(SUM(CASE WHEN YEARWEEK(earned_at, 1) = YEARWEEK(CURDATE(), 1) THEN total_earnings ELSE 0 END), 0) AS week_earn,
        COALESCE(SUM(CASE WHEN MONTH(earned_at) = MONTH(CURDATE()) AND YEAR(earned_at) = YEAR(CURDATE()) THEN total_earnings ELSE 0 END), 0) AS month_earn
    FROM rider_earnings
    WHERE rider_id = $rider_pk
");
$aggs = mysqli_fetch_assoc($agg_res);

// 2. Filtered Transactions
$trans_sql = "
    SELECT re.*, o.order_number, o.payment_method
    FROM rider_earnings re
    JOIN orders o ON re.order_id = o.id
    WHERE re.rider_id = $rider_pk
      AND $date_where
    ORDER BY re.earned_at DESC
";
$trans_res = mysqli_query($conn, $trans_sql);
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-wallet text-danger me-2"></i>Rider Earnings</h5>
        <span class="badge" style="background:#ecfdf3; color:#027a48; font-weight:700;">Credited to Wallet</span>
    </div>

    <!-- Earnings Highlights Cards (Section 13) -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="rider-card p-3 text-center h-100">
                <span class="text-muted d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Today</span>
                <strong style="color: #027a48; font-size: 1.35rem;">₱<?php echo number_format($aggs['today_earn'], 2); ?></strong>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="rider-card p-3 text-center h-100">
                <span class="text-muted d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">This Week</span>
                <strong style="color: #175cd3; font-size: 1.35rem;">₱<?php echo number_format($aggs['week_earn'], 2); ?></strong>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="rider-card p-3 text-center h-100">
                <span class="text-muted d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">This Month</span>
                <strong style="color: #b3261e; font-size: 1.35rem;">₱<?php echo number_format($aggs['month_earn'], 2); ?></strong>
            </div>
        </div>
    </div>

    <!-- Filter Buttons -->
    <div class="d-flex gap-1 mb-3 overflow-auto pb-1" style="white-space: nowrap;">
        <a href="earnings.php?filter=today" class="btn btn-sm <?php echo $filter === 'today' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="border-radius: 20px; font-size: 12px; font-weight: 600;">Today</a>
        <a href="earnings.php?filter=week" class="btn btn-sm <?php echo $filter === 'week' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="border-radius: 20px; font-size: 12px; font-weight: 600;">This Week</a>
        <a href="earnings.php?filter=month" class="btn btn-sm <?php echo $filter === 'month' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="border-radius: 20px; font-size: 12px; font-weight: 600;">This Month</a>
        <button type="button" class="btn btn-sm <?php echo $filter === 'custom' ? 'btn-danger' : 'btn-outline-secondary'; ?>" onclick="document.getElementById('customDateFilter').classList.toggle('d-none')" style="border-radius: 20px; font-size: 12px; font-weight: 600;">
            <i class="fas fa-calendar-alt me-1"></i> Custom Date
        </button>
    </div>

    <!-- Custom Date Filter Accordion -->
    <div id="customDateFilter" class="rider-card mb-3 p-3 <?php echo $filter === 'custom' ? '' : 'd-none'; ?>">
        <form method="GET" action="earnings.php" class="row g-2">
            <input type="hidden" name="filter" value="custom">
            <div class="col-6">
                <label class="form-label small mb-1">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>" required>
            </div>
            <div class="col-6">
                <label class="form-label small mb-1">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>" required>
            </div>
            <div class="col-12 mt-2">
                <button type="submit" class="btn btn-sm btn-dark w-100">Apply Date Range</button>
            </div>
        </form>
    </div>

    <!-- Transactions List (Section 13 Table) -->
    <div class="rider-card p-0" style="overflow: hidden;">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold" style="font-size: 13px;">Earnings History</h6>
            <span class="text-muted" style="font-size: 11px;"><?php echo mysqli_num_rows($trans_res); ?> payouts</span>
        </div>

        <?php if ($trans_res && mysqli_num_rows($trans_res) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 12px;">
                    <thead style="background:#f8f9fa;">
                        <tr>
                            <th class="ps-3">Date / Order</th>
                            <th class="text-end">Fee</th>
                            <th class="text-end">Bonus</th>
                            <th class="text-end">Tip</th>
                            <th class="text-end pe-3">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($trans_res)): ?>
                            <tr>
                                <td class="ps-3">
                                    <strong class="text-dark">#<?php echo htmlspecialchars($row['order_number']); ?></strong>
                                    <div class="text-muted" style="font-size: 10.5px;"><?php echo date('M d, h:i A', strtotime($row['earned_at'])); ?></div>
                                </td>
                                <td class="text-end">₱<?php echo number_format($row['base_delivery_fee'], 2); ?></td>
                                <td class="text-end text-muted">₱<?php echo number_format($row['distance_bonus'], 2); ?></td>
                                <td class="text-end text-muted">₱<?php echo number_format($row['customer_tip'], 2); ?></td>
                                <td class="text-end pe-3 fw-bold" style="color:#027a48;">
                                    ₱<?php echo number_format($row['total_earnings'], 2); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-4 text-center text-muted small">
                <i class="fas fa-receipt fa-2x mb-2 text-muted"></i>
                <div>No earnings recorded for this selected time period.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
