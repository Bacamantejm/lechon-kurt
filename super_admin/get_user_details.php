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

<div class="user-details">
    <div class="user-header">
        <div class="user-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
            <?php $user_is_active = (int)($user['is_active'] ?? 0) === 1; ?>
            <span class="status-chip <?php echo $user_is_active ? 'chip-success' : 'chip-danger'; ?>">
                <?php echo $user_is_active ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
    </div>
    
    <div class="user-info-grid">
        <div class="info-item">
            <label>Phone</label>
            <p><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></p>
        </div>
        <div class="info-item">
            <label>Account Type</label>
            <p><?php echo htmlspecialchars(ucfirst((string)($user['account_type'] ?? 'unknown'))); ?></p>
        </div>
        <div class="info-item">
            <label>Address</label>
            <p><?php echo htmlspecialchars($user['address'] ?? 'Not provided'); ?></p>
        </div>
        <div class="info-item">
            <label>Joined</label>
            <p><?php echo htmlspecialchars(saFormatDateTime($user['created_at'] ?? null, 'M d, Y', 'N/A')); ?></p>
        </div>
    </div>
    
    <div class="user-stats">
        <h6>Order Statistics</h6>
        <div class="stats-row">
            <span>Total Orders:</span>
            <strong><?php echo $orders_stats['count'] ?? 0; ?></strong>
        </div>
        <div class="stats-row">
            <span>Total Spent:</span>
            <strong>PHP <?php echo number_format((float)($orders_stats['total'] ?? 0), 2); ?></strong>
        </div>
    </div>
    
    <?php if ($user['business_name']): ?>
        <div class="business-info">
            <h6>Business Information</h6>
            <div class="info-item">
                <label>Business Name</label>
                <p><?php echo htmlspecialchars($user['business_name']); ?></p>
            </div>
            <div class="info-item">
                <label>Business Type</label>
                <p><?php echo htmlspecialchars($user['business_type'] ?? 'Not specified'); ?></p>
            </div>
            <div class="info-item">
                <label>Tax ID</label>
                <p><?php echo htmlspecialchars($user['tax_id'] ?? 'Not provided'); ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
