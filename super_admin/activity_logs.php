<?php
require_once __DIR__ . '/module_common.php';

$has_audit_logs = saTableExists($conn, 'audit_logs');
$has_activity_logs = saTableExists($conn, 'activity_logs');
$has_users = saTableExists($conn, 'users');

$source_filter = strtolower(trim((string)($_GET['source'] ?? 'all')));
$action_filter = trim((string)($_GET['action'] ?? ''));
$actor_filter = trim((string)($_GET['actor'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = '';
}

$logs = [];
if ($has_audit_logs || $has_activity_logs) {
    $parts = [];

    if ($has_audit_logs && in_array($source_filter, ['all', 'audit'], true)) {
        $audit_where = ["1=1"];
        if ($action_filter !== '') {
            $safe_action = saEscapeLike($conn, strtolower($action_filter));
            $audit_where[] = "LOWER(a.action) LIKE '%{$safe_action}%' ESCAPE '\\'";
        }
        if ($actor_filter !== '') {
            $safe_actor = saEscapeLike($conn, strtolower($actor_filter));
            if ($has_users) {
                $audit_where[] = "(LOWER(COALESCE(u.full_name, '')) LIKE '%{$safe_actor}%' ESCAPE '\\' OR LOWER(COALESCE(u.email, '')) LIKE '%{$safe_actor}%' ESCAPE '\\')";
            } else {
                $audit_where[] = "CAST(a.user_id AS CHAR) LIKE '%" . mysqli_real_escape_string($conn, $actor_filter) . "%'";
            }
        }
        if ($date_from !== '') {
            $audit_where[] = "a.created_at >= '" . mysqli_real_escape_string($conn, $date_from . ' 00:00:00') . "'";
        }
        if ($date_to !== '') {
            $audit_where[] = "a.created_at <= '" . mysqli_real_escape_string($conn, $date_to . ' 23:59:59') . "'";
        }

        $parts[] = "SELECT CONVERT('audit' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS log_source,
                           a.id,
                           a.user_id,
                           CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                           CONVERT(a.module USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                           CONVERT(a.description USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                           CONVERT(a.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                           CONVERT(a.user_agent USING utf8mb4) COLLATE utf8mb4_unicode_ci AS user_agent,
                           a.created_at,
                           " . ($has_users
                                ? "CONVERT(COALESCE(u.full_name, CONCAT('User #', a.user_id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name,
                                   CONVERT(COALESCE(u.email, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_email"
                                : "CONVERT(CONCAT('User #', a.user_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name,
                                   CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_email") . "
                    FROM audit_logs a
                    " . ($has_users ? "LEFT JOIN users u ON u.id = a.user_id" : "") . "
                    WHERE " . implode(' AND ', $audit_where);
    }

    if ($has_activity_logs && in_array($source_filter, ['all', 'activity'], true)) {
        $activity_where = ["1=1"];
        if ($action_filter !== '') {
            $safe_action = saEscapeLike($conn, strtolower($action_filter));
            $activity_where[] = "LOWER(al.action) LIKE '%{$safe_action}%' ESCAPE '\\'";
        }
        if ($actor_filter !== '') {
            $safe_actor = saEscapeLike($conn, strtolower($actor_filter));
            if ($has_users) {
                $activity_where[] = "(LOWER(COALESCE(u.full_name, '')) LIKE '%{$safe_actor}%' ESCAPE '\\' OR LOWER(COALESCE(u.email, '')) LIKE '%{$safe_actor}%' ESCAPE '\\')";
            } else {
                $activity_where[] = "CAST(al.user_id AS CHAR) LIKE '%" . mysqli_real_escape_string($conn, $actor_filter) . "%'";
            }
        }
        if ($date_from !== '') {
            $activity_where[] = "al.created_at >= '" . mysqli_real_escape_string($conn, $date_from . ' 00:00:00') . "'";
        }
        if ($date_to !== '') {
            $activity_where[] = "al.created_at <= '" . mysqli_real_escape_string($conn, $date_to . ' 23:59:59') . "'";
        }

        $parts[] = "SELECT CONVERT('activity' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS log_source,
                           al.id,
                           al.user_id,
                           CONVERT(al.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                           CONVERT(al.entity_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                           CONVERT(CAST(al.details AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                           CONVERT(al.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                           CONVERT(al.user_agent USING utf8mb4) COLLATE utf8mb4_unicode_ci AS user_agent,
                           al.created_at,
                           " . ($has_users
                                ? "CONVERT(COALESCE(u.full_name, CONCAT('User #', al.user_id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name,
                                   CONVERT(COALESCE(u.email, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_email"
                                : "CONVERT(CONCAT('User #', al.user_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_name,
                                   CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS actor_email") . "
                    FROM activity_logs al
                    " . ($has_users ? "LEFT JOIN users u ON u.id = al.user_id" : "") . "
                    WHERE " . implode(' AND ', $activity_where);
    }

    if (!empty($parts)) {
        $logs = saQueryRows(
            $conn,
            "SELECT * FROM (" . implode(' UNION ALL ', $parts) . ") log_union
             ORDER BY created_at DESC
             LIMIT 400"
        );
    }
}

$total_logs = count($logs);
$login_events = 0;
$admin_events = 0;
$unique_actors = [];

foreach ($logs as $entry) {
    $action = strtolower((string)($entry['action'] ?? ''));
    $context = strtolower((string)($entry['context'] ?? ''));

    if (strpos($action, 'login') !== false || strpos($action, 'logout') !== false || strpos($action, 'auth') !== false) {
        $login_events++;
    }
    if (strpos($context, 'admin') !== false || strpos($action, 'role') !== false || strpos($action, 'permission') !== false) {
        $admin_events++;
    }

    $actor_name = trim((string)($entry['actor_name'] ?? ''));
    if ($actor_name !== '') {
        $unique_actors[$actor_name] = true;
    }
}

saRenderModuleHeader('Activity Logs', 'Activity Logs', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Activity Log Summary</h2>
            <p class="module-subtext">Audit all actions across users, admins, and system entities for accountability.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Log Entries (filtered)</span>
            <div class="metric-value"><?php echo number_format($total_logs); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Login / Auth Events</span>
            <div class="metric-value"><?php echo number_format($login_events); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Admin-Level Events</span>
            <div class="metric-value"><?php echo number_format($admin_events); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Unique Actors</span>
            <div class="metric-value"><?php echo number_format(count($unique_actors)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Filter Logs</h3>
            <p class="module-subtext">Narrow down entries by source, actor, action, and date range.</p>
        </div>
    </div>
    <form method="GET" class="module-form-grid">
        <select name="source" class="form-select">
            <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Sources</option>
            <option value="audit" <?php echo $source_filter === 'audit' ? 'selected' : ''; ?>>Audit Logs</option>
            <option value="activity" <?php echo $source_filter === 'activity' ? 'selected' : ''; ?>>Activity Logs</option>
        </select>
        <input type="text" name="action" class="form-control" placeholder="Action contains..." value="<?php echo htmlspecialchars($action_filter); ?>">
        <input type="text" name="actor" class="form-control" placeholder="Actor name/email..." value="<?php echo htmlspecialchars($actor_filter); ?>">
        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Unified Activity Log Stream</h3>
            <p class="module-subtext">Combined audit and activity logs, sorted by latest first.</p>
        </div>
    </div>
    <?php if (!$has_audit_logs && !$has_activity_logs): ?>
        <div class="note-box">No log tables available (`audit_logs`, `activity_logs`).</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Source</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Context</th>
                        <th>IP</th>
                        <th>User Agent</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No log records found for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(saFormatDateTime($log['created_at'] ?? null)); ?></td>
                                <td><span class="status-chip chip-info"><?php echo htmlspecialchars(ucfirst((string)$log['log_source'])); ?></span></td>
                                <td>
                                    <?php echo htmlspecialchars((string)($log['actor_name'] ?? 'Unknown')); ?><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($log['actor_email'] ?? '')); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string)($log['action'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['context'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['ip_address'] ?? '-')); ?></td>
                                <td class="text-truncate-2"><?php echo htmlspecialchars((string)($log['user_agent'] ?? '-')); ?></td>
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
