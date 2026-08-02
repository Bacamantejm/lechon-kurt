<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';

checkAdminAccess();
requirePermission('logistics.view');

$tracking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$partner_tracking_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_tracking_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id');
}
if ($tracking_id <= 0) {
    echo "<div class='alert alert-danger'>Invalid tracking reference.</div>";
    exit;
}

$details_sql = "
    SELECT
        lt.*,
        o.order_number,
        o.customer_name,
        o.customer_phone,
        o.delivery_address,
        o.total_amount,
        lp.provider_name,
        dm.method_name
    FROM logistics_tracking lt
    LEFT JOIN orders o ON lt.order_id = o.id
    LEFT JOIN logistics_providers lp ON lt.logistics_provider_id = lp.id
    LEFT JOIN delivery_methods dm ON lt.delivery_method_id = dm.id
    WHERE lt.id = ? {$partner_tracking_scope_sql}
    LIMIT 1
";
$details_stmt = mysqli_prepare($conn, $details_sql);
mysqli_stmt_bind_param($details_stmt, "i", $tracking_id);
mysqli_stmt_execute($details_stmt);
$details_result = mysqli_stmt_get_result($details_stmt);
$tracking = mysqli_fetch_assoc($details_result);
mysqli_stmt_close($details_stmt);

if (!$tracking) {
    echo "<div class='alert alert-warning'>No logistics record found for this delivery.</div>";
    exit;
}

$eta_label = 'N/A';
if (!empty($tracking['estimated_delivery'])) {
    $eta_seconds = strtotime((string)$tracking['estimated_delivery']) - time();
    if ($eta_seconds > 0) {
        $eta_label = max(1, (int)ceil($eta_seconds / 60)) . ' min';
    } else {
        $eta_label = 'Arriving soon';
    }
}

$history_sql = "
    SELECT status, status_description, timestamp
    FROM logistics_tracking_history
    WHERE tracking_id = ?
    ORDER BY timestamp DESC
    LIMIT 30
";
$history_stmt = mysqli_prepare($conn, $history_sql);
mysqli_stmt_bind_param($history_stmt, "i", $tracking_id);
mysqli_stmt_execute($history_stmt);
$history_result = mysqli_stmt_get_result($history_stmt);
?>

<div class="row g-3">
    <div class="col-md-6">
        <h6 class="mb-2">Delivery Summary</h6>
        <table class="table table-sm table-bordered">
            <tr><th style="width: 45%;">Tracking ID</th><td>#<?php echo intval($tracking['id']); ?></td></tr>
            <tr><th>Order Number</th><td><?php echo htmlspecialchars($tracking['order_number'] ?? 'N/A'); ?></td></tr>
            <tr><th>Current Status</th><td><?php echo ucwords(str_replace('_', ' ', $tracking['current_status'] ?? 'pending')); ?></td></tr>
            <tr><th>Provider</th><td><?php echo htmlspecialchars($tracking['provider_name'] ?? 'In-House'); ?></td></tr>
            <tr><th>Method</th><td><?php echo htmlspecialchars($tracking['method_name'] ?? 'Delivery'); ?></td></tr>
            <tr><th>ETA</th><td><?php echo htmlspecialchars($eta_label); ?></td></tr>
            <tr><th>Estimated Delivery</th><td><?php echo !empty($tracking['estimated_delivery']) ? date('M d, Y h:i A', strtotime($tracking['estimated_delivery'])) : 'N/A'; ?></td></tr>
            <tr><th>Cost</th><td><?php echo isset($tracking['cost']) ? 'PHP ' . number_format((float)$tracking['cost'], 2) : 'N/A'; ?></td></tr>
        </table>
    </div>

    <div class="col-md-6">
        <h6 class="mb-2">Customer & Driver</h6>
        <table class="table table-sm table-bordered">
            <tr><th style="width: 45%;">Customer</th><td><?php echo htmlspecialchars($tracking['customer_name'] ?? 'N/A'); ?></td></tr>
            <tr><th>Phone</th><td><?php echo htmlspecialchars($tracking['customer_phone'] ?? 'N/A'); ?></td></tr>
            <tr><th>Address</th><td><?php echo htmlspecialchars($tracking['delivery_address'] ?? 'N/A'); ?></td></tr>
            <tr><th>Driver</th><td><?php echo htmlspecialchars($tracking['driver_name'] ?? 'Unassigned'); ?></td></tr>
            <tr><th>Driver Phone</th><td><?php echo htmlspecialchars($tracking['driver_phone'] ?? 'N/A'); ?></td></tr>
            <tr><th>Vehicle</th><td><?php echo htmlspecialchars($tracking['driver_vehicle'] ?? 'N/A'); ?></td></tr>
            <tr><th>Order Amount</th><td><?php echo isset($tracking['total_amount']) ? 'PHP ' . number_format((float)$tracking['total_amount'], 2) : 'N/A'; ?></td></tr>
        </table>
    </div>
</div>

<?php if (!empty($tracking['special_instructions'])): ?>
    <div class="alert alert-info">
        <strong>Special Instructions:</strong><br>
        <?php echo nl2br(htmlspecialchars($tracking['special_instructions'])); ?>
    </div>
<?php endif; ?>

<?php if (!empty($tracking['proof_of_delivery_path'])): ?>
    <div class="mb-3">
        <a href="../uploads/<?php echo htmlspecialchars($tracking['proof_of_delivery_path']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-camera"></i> View Proof of Delivery
        </a>
    </div>
<?php endif; ?>

<h6 class="mb-2">Status Timeline</h6>
<?php if (mysqli_num_rows($history_result) === 0): ?>
    <p class="text-muted mb-0">No tracking history available yet.</p>
<?php else: ?>
    <ul class="list-group">
        <?php while ($history = mysqli_fetch_assoc($history_result)): ?>
            <li class="list-group-item">
                <div class="d-flex justify-content-between">
                    <strong><?php echo ucwords(str_replace('_', ' ', $history['status'] ?? 'updated')); ?></strong>
                    <small class="text-muted"><?php echo !empty($history['timestamp']) ? date('M d, Y h:i A', strtotime($history['timestamp'])) : ''; ?></small>
                </div>
                <?php if (!empty($history['status_description'])): ?>
                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($history['status_description']); ?></div>
                <?php endif; ?>
            </li>
        <?php endwhile; ?>
    </ul>
<?php endif; ?>
