<?php
require_once __DIR__ . '/module_common.php';

$has_users = saTableExists($conn, 'users');
$has_apps = saTableExists($conn, 'franchise_applications');
$has_orders = saTableExists($conn, 'orders');
$has_order_items = saTableExists($conn, 'order_items');
$has_products = saTableExists($conn, 'products');
$has_chat_conversations = saTableExists($conn, 'chat_conversations');
$has_audit_logs = saTableExists($conn, 'audit_logs');
$has_activity_logs = saTableExists($conn, 'activity_logs');

if (!function_exists('saBusinessChipClass')) {
    function saBusinessChipClass($status) {
        $status = strtolower(trim((string)$status));
        if (in_array($status, ['approved', 'active', 'enabled'], true)) {
            return 'chip-success';
        }
        if (in_array($status, ['pending', 'in_progress'], true)) {
            return 'chip-warning';
        }
        if (in_array($status, ['rejected', 'inactive', 'disabled', 'banned', 'suspended'], true)) {
            return 'chip-danger';
        }
        return 'chip-muted';
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$verification_filter = strtolower(trim((string)($_GET['verification'] ?? 'all')));

$profiles = [];
if ($has_users) {
    $where = ["u.account_type = 'organization'"];
    if ($search !== '') {
        $safe_search = saEscapeLike($conn, $search);
        $where[] = "(u.full_name LIKE '%{$safe_search}%' ESCAPE '\\' OR u.email LIKE '%{$safe_search}%' ESCAPE '\\' OR u.business_name LIKE '%{$safe_search}%' ESCAPE '\\')";
    }
    if ($has_apps && in_array($verification_filter, ['approved', 'pending', 'rejected', 'unverified'], true)) {
        if ($verification_filter === 'unverified') {
            $where[] = "(fa_latest.status IS NULL OR fa_latest.status = '')";
        } else {
            $safe_verification = mysqli_real_escape_string($conn, $verification_filter);
            $where[] = "fa_latest.status = '{$safe_verification}'";
        }
    }

    $join_latest_app = $has_apps
        ? "LEFT JOIN franchise_applications fa_latest ON fa_latest.id = (
                SELECT fa2.id
                FROM franchise_applications fa2
                WHERE fa2.user_id = u.id
                ORDER BY fa2.created_at DESC, fa2.id DESC
                LIMIT 1
            )"
        : "";

    $verification_column = $has_apps
        ? "COALESCE(fa_latest.status, 'unverified') AS verification_status,
           fa_latest.created_at AS verification_created_at,
           fa_latest.reviewed_at AS verification_reviewed_at"
        : "'unverified' AS verification_status, NULL AS verification_created_at, NULL AS verification_reviewed_at";

    $profiles = saQueryRows(
        $conn,
        "SELECT u.id, u.full_name AS owner_name, u.email, u.business_name, u.user_type, u.is_active, u.created_at, u.last_login,
                {$verification_column}
         FROM users u
         {$join_latest_app}
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.created_at DESC
         LIMIT 300"
    );
}

$performance_map = [];
$has_products_seller_id = $has_products && saColumnExists($conn, 'products', 'seller_id');
$has_products_id = $has_products && saColumnExists($conn, 'products', 'id');
$has_products_product_id = $has_products && saColumnExists($conn, 'products', 'product_id');
$has_orders_archived = $has_orders && saColumnExists($conn, 'orders', 'is_archived');

if ($has_order_items && $has_orders && $has_products_seller_id && $has_products_id) {
    $join_on = "oi.product_id = CAST(p.id AS CHAR)";
    if ($has_products_product_id) {
        $join_on .= " OR (p.product_id IS NOT NULL AND p.product_id <> '' AND oi.product_id = p.product_id)";
    }

    $archived_clause = $has_orders_archived ? "AND o.is_archived = 0" : "";
    $rows = saQueryRows(
        $conn,
        "SELECT p.seller_id AS business_user_id,
                COUNT(DISTINCT oi.order_id) AS order_count,
                COALESCE(SUM(oi.total), 0) AS total_sales,
                MAX(o.created_at) AS last_order_at
         FROM order_items oi
         INNER JOIN products p ON ({$join_on})
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE p.seller_id IS NOT NULL
           {$archived_clause}
         GROUP BY p.seller_id"
    );

    foreach ($rows as $row) {
        $performance_map[(int)$row['business_user_id']] = [
            'order_count' => (int)($row['order_count'] ?? 0),
            'total_sales' => (float)($row['total_sales'] ?? 0),
            'last_order_at' => $row['last_order_at'] ?? null
        ];
    }
} elseif ($has_orders) {
    $archived_clause = $has_orders_archived ? "WHERE o.is_archived = 0" : "";
    $rows = saQueryRows(
        $conn,
        "SELECT o.user_id AS business_user_id,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount), 0) AS total_sales,
                MAX(o.created_at) AS last_order_at
         FROM orders o
         {$archived_clause}
         GROUP BY o.user_id"
    );
    foreach ($rows as $row) {
        $performance_map[(int)$row['business_user_id']] = [
            'order_count' => (int)($row['order_count'] ?? 0),
            'total_sales' => (float)($row['total_sales'] ?? 0),
            'last_order_at' => $row['last_order_at'] ?? null
        ];
    }
}

$interaction_map = [];
if ($has_chat_conversations) {
    $interactions = saQueryRows(
        $conn,
        "SELECT customer_id AS business_user_id,
                COUNT(*) AS interaction_count,
                MAX(updated_at) AS last_interaction_at
         FROM chat_conversations
         GROUP BY customer_id"
    );
    foreach ($interactions as $interaction) {
        $interaction_map[(int)$interaction['business_user_id']] = [
            'interaction_count' => (int)($interaction['interaction_count'] ?? 0),
            'last_interaction_at' => $interaction['last_interaction_at'] ?? null
        ];
    }
}

$total_businesses = count($profiles);
$verified_count = 0;
$pending_count = 0;
$inactive_count = 0;

foreach ($profiles as &$profile) {
    $business_id = (int)$profile['id'];
    $verification = strtolower(trim((string)($profile['verification_status'] ?? 'unverified')));
    if ($verification === '') {
        $verification = 'unverified';
    }
    $profile['verification_status'] = $verification;

    if ($verification === 'approved') {
        $verified_count++;
    } elseif ($verification === 'pending') {
        $pending_count++;
    }
    if ((int)($profile['is_active'] ?? 0) !== 1) {
        $inactive_count++;
    }

    $profile['order_count'] = (int)($performance_map[$business_id]['order_count'] ?? 0);
    $profile['total_sales'] = (float)($performance_map[$business_id]['total_sales'] ?? 0);
    $profile['last_order_at'] = $performance_map[$business_id]['last_order_at'] ?? null;

    $profile['interaction_count'] = (int)($interaction_map[$business_id]['interaction_count'] ?? 0);
    $profile['last_interaction_at'] = $interaction_map[$business_id]['last_interaction_at'] ?? null;
}
unset($profile);

usort($profiles, function ($a, $b) {
    $sales_a = (float)($a['total_sales'] ?? 0);
    $sales_b = (float)($b['total_sales'] ?? 0);
    if ($sales_a === $sales_b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    }
    return $sales_a < $sales_b ? 1 : -1;
});

$top_businesses = array_slice($profiles, 0, 8);
$recent_activity = [];
if ($has_audit_logs || $has_activity_logs) {
    $queries = [];
    if ($has_audit_logs) {
        $queries[] = "SELECT CONVERT('audit' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                             a.id,
                             CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                             CONVERT(a.module USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                             CONVERT(a.description USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                             CONVERT(a.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                             a.created_at,
                             CONVERT(COALESCE(u.full_name, CONCAT('User #', a.user_id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name
                      FROM audit_logs a
                      LEFT JOIN users u ON u.id = a.user_id
                      WHERE a.module IN ('users', 'admin', 'super_admin_user_business')
                         OR a.description LIKE '%business%'
                         OR a.description LIKE '%franchise%'";
    }
    if ($has_activity_logs) {
        $queries[] = "SELECT CONVERT('activity' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                             al.id,
                             CONVERT(al.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                             CONVERT(al.entity_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                             CONVERT(CAST(al.details AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                             CONVERT(al.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                             al.created_at,
                             CONVERT(COALESCE(u.full_name, CONCAT('User #', al.user_id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name
                      FROM activity_logs al
                      LEFT JOIN users u ON u.id = al.user_id
                      WHERE al.entity_type IN ('business', 'franchise_application', 'user', 'users')
                         OR al.action LIKE '%BUSINESS%'
                         OR al.action LIKE '%FRANCHISE%'";
    }

    if (!empty($queries)) {
        $union_sql = implode(' UNION ALL ', $queries) . " ORDER BY created_at DESC LIMIT 80";
        $recent_activity = saQueryRows($conn, $union_sql);
    }
}

saRenderModuleHeader('Business Monitoring', 'Business Monitoring', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Business Monitoring Overview</h2>
            <p class="module-subtext">Track business profiles, verification progress, activity, and performance indicators.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Registered Businesses</span>
            <div class="metric-value"><?php echo number_format($total_businesses); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Verified Businesses</span>
            <div class="metric-value"><?php echo number_format($verified_count); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pending Verification</span>
            <div class="metric-value"><?php echo number_format($pending_count); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Inactive Accounts</span>
            <div class="metric-value"><?php echo number_format($inactive_count); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Business Profiles and Verification</h3>
            <p class="module-subtext">View owner identity, current account status, and verification state.</p>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <input type="text" name="search" class="form-control" placeholder="Search business or owner..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="verification" class="form-select">
            <option value="all" <?php echo $verification_filter === 'all' ? 'selected' : ''; ?>>All Verification States</option>
            <option value="approved" <?php echo $verification_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="pending" <?php echo $verification_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="rejected" <?php echo $verification_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            <option value="unverified" <?php echo $verification_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    </form>

    <?php if (!$has_users): ?>
        <div class="note-box">`users` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Business Profile</th>
                        <th>Owner</th>
                        <th>Verification</th>
                        <th>Account</th>
                        <th>Created</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($profiles)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No business profile records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($profiles as $profile): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)($profile['business_name'] ?: 'Unnamed Business')); ?></strong><br>
                                    <span class="compact-text">Business User ID: <?php echo (int)$profile['id']; ?></span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars((string)($profile['owner_name'] ?? 'Unknown')); ?><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($profile['email'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <span class="status-chip <?php echo saBusinessChipClass($profile['verification_status'] ?? 'unverified'); ?>">
                                        <?php echo htmlspecialchars(ucfirst((string)$profile['verification_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int)($profile['is_active'] ?? 0) === 1): ?>
                                        <span class="status-chip chip-success">Active</span>
                                    <?php else: ?>
                                        <span class="status-chip chip-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($profile['created_at'] ?? null, 'M d, Y')); ?></td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($profile['last_login'] ?? null, 'M d, Y h:i A', 'Never')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Business Performance Metrics</h3>
            <p class="module-subtext">Sales, request volume, and interactions per business account.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Total Sales</th>
                    <th>Orders</th>
                    <th>Interactions</th>
                    <th>Last Order</th>
                    <th>Last Interaction</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_businesses)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No measurable business activity yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($top_businesses as $business): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)($business['business_name'] ?: 'Unnamed Business')); ?></strong><br>
                                <span class="compact-text"><?php echo htmlspecialchars((string)($business['owner_name'] ?? 'Unknown Owner')); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(saFormatCurrency($business['total_sales'] ?? 0)); ?></td>
                            <td><?php echo number_format((int)($business['order_count'] ?? 0)); ?></td>
                            <td><?php echo number_format((int)($business['interaction_count'] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(saFormatDateTime($business['last_order_at'] ?? null, 'M d, Y h:i A', '-')); ?></td>
                            <td><?php echo htmlspecialchars(saFormatDateTime($business['last_interaction_at'] ?? null, 'M d, Y h:i A', '-')); ?></td>
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
            <h3>Business Activity Logs</h3>
            <p class="module-subtext">Owner-level audit trail for franchise and business-related actions.</p>
        </div>
    </div>
    <?php if (!$has_audit_logs && !$has_activity_logs): ?>
        <div class="note-box">No audit/activity log tables available (`audit_logs`, `activity_logs`).</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Source</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Context</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_activity)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No business activity logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(saFormatDateTime($log['created_at'] ?? null)); ?></td>
                                <td><span class="status-chip chip-info"><?php echo htmlspecialchars(ucfirst((string)$log['source'])); ?></span></td>
                                <td><?php echo htmlspecialchars((string)($log['actor_name'] ?? 'Unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['action'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['context'] ?? '-')); ?></td>
                                <td class="text-truncate-2"><?php echo htmlspecialchars((string)($log['details'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
saRenderModuleFooter();
