<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'notification_center.php';
$has_notifications = saTableExists($conn, 'notifications');
$has_users = saTableExists($conn, 'users');
$has_roles = saTableExists($conn, 'roles');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'send_notification') {
        if (!$has_notifications || !$has_users) {
            saSetFlash('danger', 'Notification sending is unavailable because required tables are missing.');
        } else {
            $delivery_scope = strtolower(trim((string)($_POST['delivery_scope'] ?? 'single')));
            $target_user_id = (int)($_POST['target_user_id'] ?? 0);
            $target_role_id = (int)($_POST['target_role_id'] ?? 0);
            $type = trim((string)($_POST['type'] ?? 'system_alert'));
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $related_type = trim((string)($_POST['related_type'] ?? ''));
            $related_id = (int)($_POST['related_id'] ?? 0);

            if ($title === '' || $message === '') {
                saSetFlash('danger', 'Please provide both title and message.');
            } else {
                $target_user_ids = [];
                if ($delivery_scope === 'all') {
                    $rows = saQueryRows($conn, "SELECT id FROM users WHERE is_active = 1");
                    foreach ($rows as $row) {
                        $target_user_ids[] = (int)$row['id'];
                    }
                } elseif ($delivery_scope === 'role') {
                    if ($target_role_id <= 0) {
                        saSetFlash('danger', 'Please select a role for role-based notification delivery.');
                    } else {
                        $rows = saQueryRows($conn, "SELECT id FROM users WHERE role_id = " . (int)$target_role_id . " AND is_active = 1");
                        foreach ($rows as $row) {
                            $target_user_ids[] = (int)$row['id'];
                        }
                    }
                } else {
                    if ($target_user_id <= 0) {
                        saSetFlash('danger', 'Please select a recipient user.');
                    } else {
                        $target_user_ids[] = $target_user_id;
                    }
                }

                if (empty($target_user_ids)) {
                    if (!isset($_SESSION['sa_flash']) || !is_array($_SESSION['sa_flash']) || empty($_SESSION['sa_flash'])) {
                        saSetFlash('warning', 'No target recipients were found for this notification.');
                    }
                } else {
                    $insert_stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, is_read, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())"
                    );

                    if (!$insert_stmt) {
                        saSetFlash('danger', 'Unable to prepare notification insert.');
                    } else {
                        $created = 0;
                        $safe_related_id = $related_id > 0 ? $related_id : null;
                        $safe_related_type = $related_type !== '' ? substr($related_type, 0, 50) : null;
                        $safe_type = substr($type !== '' ? $type : 'system_alert', 0, 50);
                        $safe_title = substr($title, 0, 255);

                        foreach ($target_user_ids as $recipient_user_id) {
                            $uid = (int)$recipient_user_id;
                            mysqli_stmt_bind_param($insert_stmt, "isssis", $uid, $safe_type, $safe_title, $message, $safe_related_id, $safe_related_type);
                            if (mysqli_stmt_execute($insert_stmt)) {
                                $created++;
                            }
                        }
                        mysqli_stmt_close($insert_stmt);

                        saSetFlash('success', "Notification sent to {$created} recipient(s).");
                        saLogAudit(
                            $conn,
                            $current_admin_id,
                            'NOTIFICATION_BROADCAST',
                            'super_admin_notification_center',
                            "Sent notification '{$safe_title}' to {$created} users using scope '{$delivery_scope}'."
                        );
                    }
                }
            }
        }
    } elseif ($action === 'mark_notification_status') {
        if (!$has_notifications) {
            saSetFlash('danger', 'Notification status update is unavailable.');
        } else {
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            $mark_read = (int)($_POST['mark_read'] ?? 0) === 1 ? 1 : 0;
            if ($notification_id <= 0) {
                saSetFlash('danger', 'Invalid notification selected.');
            } else {
                $update_stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                if ($update_stmt) {
                    mysqli_stmt_bind_param($update_stmt, "ii", $mark_read, $notification_id);
                    $ok = mysqli_stmt_execute($update_stmt);
                    mysqli_stmt_close($update_stmt);
                    if ($ok) {
                        saSetFlash('success', 'Notification status updated.');
                    } else {
                        saSetFlash('danger', 'Unable to update notification status.');
                    }
                } else {
                    saSetFlash('danger', 'Unable to prepare notification status update.');
                }
            }
        }
    } else {
        saSetFlash('warning', 'Unsupported notification action.');
    }

    header('Location: ' . $page_file);
    exit;
}

$type_filter = trim((string)($_GET['type'] ?? 'all'));
$read_filter = trim((string)($_GET['read_status'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

$notifications = [];
if ($has_notifications) {
    $where = ["1=1"];
    if ($type_filter !== '' && $type_filter !== 'all') {
        $safe_type = mysqli_real_escape_string($conn, $type_filter);
        $where[] = "n.type = '{$safe_type}'";
    }
    if (in_array($read_filter, ['read', 'unread'], true)) {
        $where[] = $read_filter === 'read' ? "n.is_read = 1" : "n.is_read = 0";
    }
    if ($search !== '') {
        $safe_search = saEscapeLike($conn, strtolower($search));
        $where[] = "(LOWER(n.title) LIKE '%{$safe_search}%' ESCAPE '\\' OR LOWER(n.message) LIKE '%{$safe_search}%' ESCAPE '\\' OR LOWER(COALESCE(u.full_name, '')) LIKE '%{$safe_search}%' ESCAPE '\\')";
    }

    $notifications = saQueryRows(
        $conn,
        "SELECT n.id, n.user_id, n.type, n.title, n.message, n.related_id, n.related_type, n.is_read, n.created_at, n.updated_at,
                " . ($has_users ? "u.full_name AS recipient_name, u.email AS recipient_email" : "CONCAT('User #', n.user_id) AS recipient_name, '' AS recipient_email") . "
         FROM notifications n
         " . ($has_users ? "LEFT JOIN users u ON u.id = n.user_id" : "") . "
         WHERE " . implode(' AND ', $where) . "
         ORDER BY n.created_at DESC
         LIMIT 250"
    );
}

$notification_type_rows = [];
if ($has_notifications) {
    $notification_type_rows = saQueryRows(
        $conn,
        "SELECT type, COUNT(*) AS total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_total
         FROM notifications
         GROUP BY type
         ORDER BY total DESC"
    );
}

$total_notifications = $has_notifications ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM notifications", 0) : 0;
$unread_notifications = $has_notifications ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM notifications WHERE is_read = 0", 0) : 0;

$recipient_options = [];
if ($has_users) {
    $recipient_options = saQueryRows(
        $conn,
        "SELECT id, full_name, email
         FROM users
         WHERE is_active = 1
         ORDER BY full_name ASC
         LIMIT 150"
    );
}

$role_options = [];
if ($has_roles) {
    $role_options = saQueryRows(
        $conn,
        "SELECT id, name
         FROM roles
         WHERE is_active = 1
         ORDER BY name ASC"
    );
}

saRenderModuleHeader('Notification Center', 'Notification Center', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Notification Dashboard</h2>
            <p class="module-subtext">Manage in-system alerts and monitor notification read/unread trends.</p>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Notifications</span>
            <div class="metric-value"><?php echo number_format($total_notifications); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Unread Notifications</span>
            <div class="metric-value"><?php echo number_format($unread_notifications); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Read Notifications</span>
            <div class="metric-value"><?php echo number_format(max(0, $total_notifications - $unread_notifications)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Notification Types</span>
            <div class="metric-value"><?php echo number_format(count($notification_type_rows)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Send Notification</h3>
            <p class="module-subtext">Send targeted or broadcast in-system alerts. Email/SMS integration can be added on top of this flow.</p>
        </div>
    </div>
    <?php if (!$has_notifications || !$has_users): ?>
        <div class="note-box">Sending requires both `notifications` and `users` tables.</div>
    <?php else: ?>
        <form method="POST" class="module-form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="send_notification">

            <select name="delivery_scope" class="form-select">
                <option value="single">Single User</option>
                <option value="role">Role-Based</option>
                <option value="all">All Active Users</option>
            </select>
            <select name="target_user_id" class="form-select">
                <option value="">Select user (for Single User)</option>
                <?php foreach ($recipient_options as $recipient): ?>
                    <option value="<?php echo (int)$recipient['id']; ?>">
                        <?php echo htmlspecialchars((string)$recipient['full_name']); ?> (<?php echo htmlspecialchars((string)$recipient['email']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="target_role_id" class="form-select">
                <option value="0">Select role (for Role-Based)</option>
                <?php foreach ($role_options as $role): ?>
                    <option value="<?php echo (int)$role['id']; ?>"><?php echo htmlspecialchars((string)$role['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="type" class="form-control" placeholder="Type (e.g., approval_alert)" value="system_alert">
            <input type="text" name="title" class="form-control" placeholder="Notification title" required>
            <input type="text" name="message" class="form-control" placeholder="Notification message" required>
            <input type="text" name="related_type" class="form-control" placeholder="Related type (optional)">
            <input type="number" name="related_id" class="form-control" placeholder="Related ID (optional)">
            <button type="submit"
                    class="btn btn-primary"
                    data-sa-confirm="1"
                    data-sa-confirm-title="Send Notification?"
                    data-sa-confirm-text="This will deliver the notification to selected recipients based on the chosen scope."
                    data-sa-confirm-confirm-text="Yes, Send"
                    data-sa-confirm-confirm-color="#9f1239">
                <i class="fas fa-paper-plane"></i> Send Notification
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Notification Type Breakdown</h3>
            <p class="module-subtext">Quick distribution by type and unread volume.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table" style="min-width: 520px;">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Total</th>
                    <th>Unread</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($notification_type_rows)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No notification type data available.</td></tr>
                <?php else: ?>
                    <?php foreach ($notification_type_rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$row['type']); ?></td>
                            <td><?php echo number_format((int)($row['total'] ?? 0)); ?></td>
                            <td><?php echo number_format((int)($row['unread_total'] ?? 0)); ?></td>
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
            <h3>Notification History</h3>
            <p class="module-subtext">Audit all outgoing notifications and update read status when needed.</p>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <input type="text" name="search" class="form-control" placeholder="Search title, message, recipient..." value="<?php echo htmlspecialchars($search); ?>">
        <input type="text" name="type" class="form-control" placeholder="Type (or all)" value="<?php echo htmlspecialchars($type_filter); ?>">
        <select name="read_status" class="form-select">
            <option value="all" <?php echo $read_filter === 'all' ? 'selected' : ''; ?>>All Read States</option>
            <option value="unread" <?php echo $read_filter === 'unread' ? 'selected' : ''; ?>>Unread</option>
            <option value="read" <?php echo $read_filter === 'read' ? 'selected' : ''; ?>>Read</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    </form>

    <?php if (!$has_notifications): ?>
        <div class="note-box">`notifications` table is unavailable.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Recipient</th>
                        <th>Type</th>
                        <th>Title / Message</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notifications)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No notifications found for current filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php $is_read = (int)($notification['is_read'] ?? 0) === 1; ?>
                            <tr>
                                <td><?php echo htmlspecialchars(saFormatDateTime($notification['created_at'] ?? null)); ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string)($notification['recipient_name'] ?? ('User #' . (int)$notification['user_id']))); ?><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($notification['recipient_email'] ?? '')); ?></span>
                                </td>
                                <td><span class="status-chip chip-info"><?php echo htmlspecialchars((string)($notification['type'] ?? '')); ?></span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)($notification['title'] ?? '')); ?></strong><br>
                                    <span class="compact-text text-truncate-2"><?php echo htmlspecialchars((string)($notification['message'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <?php if ($is_read): ?>
                                        <span class="status-chip chip-success">Read</span>
                                    <?php else: ?>
                                        <span class="status-chip chip-warning">Unread</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="mark_notification_status">
                                        <input type="hidden" name="notification_id" value="<?php echo (int)$notification['id']; ?>">
                                        <input type="hidden" name="mark_read" value="<?php echo $is_read ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $is_read ? 'btn-outline-secondary' : 'btn-outline-success'; ?>">
                                            <?php echo $is_read ? 'Mark Unread' : 'Mark Read'; ?>
                                        </button>
                                    </form>
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
saRenderModuleFooter();
