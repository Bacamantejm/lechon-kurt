<?php
require_once __DIR__ . '/module_common.php';

if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid user reference.</div>';
    exit;
}

if (!saTableExists($conn, 'users')) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Users table is unavailable.</div>';
    exit;
}

$user_id = (int)$_GET['id'];

// Get user details
$user_query = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
if (!$stmt) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Unable to fetch user details right now.</div>';
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo '<div class="alert alert-warning">User not found.</div>';
    exit;
}

// Get user orders count
$orders_stats = ['count' => 0, 'total' => 0];
if (saTableExists($conn, 'orders')) {
    $orders_query = "SELECT COUNT(*) as count, SUM(total_amount) as total FROM orders WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $orders_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $orders_result = mysqli_stmt_get_result($stmt);
        $orders_stats = $orders_result ? mysqli_fetch_assoc($orders_result) : $orders_stats;
        mysqli_stmt_close($stmt);
    }
}
?>

<div class="user-details-modern">
    <!-- Section 1: Customer Profile Overview -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-user-circle"></i> Profile Overview</span>
            <?php $user_is_active = (int)($user['is_active'] ?? 0) === 1; ?>
            <span class="<?php echo $user_is_active ? 'form-req-pill' : 'form-opt-pill'; ?>">
                <?php echo $user_is_active ? 'Active Account' : 'Suspended'; ?>
            </span>
        </div>
        
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width: 52px; height: 52px; border-radius: 12px; background: #fff1f0; color: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 26px; border: 1px solid #fee4e2;">
                <i class="fas fa-user"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-0" style="color: #101828; font-size: 16px;"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                <span class="text-muted" style="font-size: 13px;"><?php echo htmlspecialchars($user['email']); ?></span>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-sm-6">
                <div class="p-2 rounded bg-light border">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">PHONE NUMBER</span>
                    <strong style="font-size: 13px; color: #101828;"><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></strong>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="p-2 rounded bg-light border">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">ACCOUNT TYPE</span>
                    <strong style="font-size: 13px; color: #101828;"><?php echo htmlspecialchars(ucfirst((string)($user['account_type'] ?? 'Individual'))); ?></strong>
                </div>
            </div>
            <div class="col-12">
                <div class="p-2 rounded bg-light border">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">DELIVERY ADDRESS</span>
                    <span style="font-size: 13px; color: #101828;"><?php echo htmlspecialchars($user['address'] ?? 'Not provided'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Order Activity & Financials -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-chart-line"></i> Transaction Stats</span>
            <span class="form-opt-pill">Financials</span>
        </div>
        <div class="row g-2">
            <div class="col-6">
                <div class="p-2 rounded bg-light border text-center">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">COMPLETED ORDERS</span>
                    <strong style="font-size: 18px; color: #101828;"><?php echo number_format((int)($orders_stats['count'] ?? 0)); ?></strong>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded bg-light border text-center">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">TOTAL SPENT</span>
                    <strong style="font-size: 18px; color: #b3261e;">₱<?php echo number_format((float)($orders_stats['total'] ?? 0), 2); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($user['business_name'])): ?>
    <!-- Section 3: Commercial Details -->
    <div class="form-section-card">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-building"></i> Commercial Entity</span>
            <span class="form-opt-pill">Enterprise</span>
        </div>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="form-group-modern">
                    <label class="form-label-modern">Registered Business Name</label>
                    <div class="form-input-wrap">
                        <i class="fas fa-store form-input-icon"></i>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['business_name']); ?>" disabled>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-group-modern">
                    <label class="form-label-modern">Tax Identification Number</label>
                    <div class="form-input-wrap">
                        <i class="fas fa-id-card form-input-icon"></i>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['tax_id'] ?? 'N/A'); ?>" disabled>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

