<?php
$page_title = 'Delivery History';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
$rider_pk = (int)$rider['id'];
$emp_id = (int)($rider['employee_id'] ?? 0);

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');

$where = "(lt.driver_id = $rider_pk";
if ($emp_id > 0) {
    $where .= " OR lt.driver_id = $emp_id";
}
$where .= ")";

if ($status_filter !== 'all') {
    $safe_status = mysqli_real_escape_string($conn, $status_filter);
    $where .= " AND lt.current_status = '$safe_status'";
}

if (!empty($search)) {
    $safe_s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (o.order_number LIKE '%$safe_s%' OR o.customer_name LIKE '%$safe_s%' OR o.delivery_address LIKE '%$safe_s%')";
}

$history_q = mysqli_query($conn, "
    SELECT lt.*, o.order_number, o.customer_name, o.delivery_address, o.total_amount, o.payment_method,
           sl.store_name, re.total_earnings
    FROM logistics_tracking lt
    JOIN orders o ON lt.order_id = o.id
    LEFT JOIN store_locations sl ON o.pickup_location = sl.store_id
    LEFT JOIN rider_earnings re ON re.order_id = o.id AND re.rider_id = $rider_pk
    WHERE $where
    ORDER BY lt.updated_at DESC
    LIMIT 60
");

require_once __DIR__ . '/header.php';
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-history text-danger me-2"></i>Delivery History</h5>
        <span class="badge" style="background:#f2f4f7; color:#344054;"><?php echo mysqli_num_rows($history_q); ?> deliveries</span>
    </div>

    <!-- Search & Filter Controls -->
    <div class="rider-card p-3 mb-3">
        <form method="GET" action="history.php" class="row g-2">
            <div class="col-8 col-md-9">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search order #, customer, area..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-4 col-md-3">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
        </form>
    </div>

    <!-- Deliveries Cards Feed (Section 15) -->
    <?php if ($history_q && mysqli_num_rows($history_q) > 0): ?>
        <div class="row g-3">
            <?php while ($row = mysqli_fetch_assoc($history_q)): 
                $status_class = 'badge-success';
                if ($row['current_status'] === 'cancelled' || $row['current_status'] === 'failed') $status_class = 'badge-danger';
                elseif ($row['current_status'] === 'assigned' || $row['current_status'] === 'picked_up') $status_class = 'badge-warning';
            ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="rider-card mb-0 p-3 h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold" style="color: var(--primary-red); font-size: 13.5px;">
                                    #<?php echo htmlspecialchars($row['order_number']); ?>
                                </span>
                                <span class="badge rounded-pill <?php echo $row['current_status'] === 'delivered' ? 'bg-success' : 'bg-secondary'; ?>" style="font-size: 10px; text-transform: uppercase;">
                                    <?php echo htmlspecialchars($row['current_status']); ?>
                                </span>
                            </div>

                            <div class="small fw-semibold text-dark mb-1">
                                <i class="fas fa-store text-danger me-1"></i><?php echo htmlspecialchars($row['store_name'] ?? 'Shop Pickup'); ?>
                            </div>

                            <div class="small text-muted text-truncate mb-2" title="<?php echo htmlspecialchars($row['delivery_address']); ?>">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i><?php echo htmlspecialchars($row['delivery_address'] ?? 'Customer Drop-off'); ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2" style="font-size: 11.5px;">
                            <div>
                                <span class="text-muted"><?php echo date('M d, Y h:i A', strtotime($row['updated_at'])); ?></span>
                                <span class="badge ms-1" style="background:#f2f4f7; color:#344054;"><?php echo strtoupper($row['payment_method'] ?? 'COD'); ?></span>
                            </div>
                            <div>
                                <strong style="color: #027a48; font-size: 13px;">
                                    ₱<?php echo number_format((float)($row['total_earnings'] ?? 50.00), 2); ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="rider-card p-4 text-center text-muted small">
            <i class="fas fa-box-open fa-2x mb-2 text-muted"></i>
            <div>No delivery records found matching your filters.</div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
