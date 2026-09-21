<?php
/**
 * Super Admin Alerts & Notifications API Endpoint
 * Handles distinct data feeds for:
 * 1. Alerts: Suspicious changes, security/audit logs, system anomalies, partner warnings, client feedback & complaints.
 * 2. Notifications: Platform transactions, franchise submissions, user signups, and system broadcasts.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$is_super_admin = false;
if (function_exists('isSuperAdmin')) {
    $is_super_admin = isSuperAdmin($conn, $current_user_id);
} else {
    $is_super_admin = strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin';
}

if (!$is_super_admin) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Super Admin access required']);
    exit;
}

function saCheckTableExists($conn, string $tableName): bool {
    static $cache = [];
    $tableName = trim($tableName);
    if ($tableName === '' || !$conn) return false;
    if (array_key_exists($tableName, $cache)) return $cache[$tableName];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
    $exists = ($res && mysqli_num_rows($res) > 0);
    if ($res instanceof mysqli_result) mysqli_free_result($res);
    return $cache[$tableName] = $exists;
}

function saCheckColumnExists($conn, string $tableName, string $columnName): bool {
    static $cache = [];
    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!saCheckTableExists($conn, $tableName)) return $cache[$key] = false;
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $safeCol = mysqli_real_escape_string($conn, $columnName);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    $exists = ($res && mysqli_num_rows($res) > 0);
    if ($res instanceof mysqli_result) mysqli_free_result($res);
    return $cache[$key] = $exists;
}

function saTimeElapsedString($datetime, $full = false): string {
    if (!$datetime) return 'recently';
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $weeks = floor($diff->d / 7);
        $days = $diff->d - ($weeks * 7);

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'min',
            's' => 'sec',
        ];
        foreach ($string as $k => &$v) {
            if ($k === 'w') {
                $val = $weeks;
            } elseif ($k === 'd') {
                $val = $days;
            } else {
                $val = $diff->$k;
            }

            if ($val) {
                $v = $val . ' ' . $v . ($val > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    } catch (Exception $e) {
        return 'recently';
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? 'get_all')));

// -------------------------------------------------------------
// 1. Fetching Alerts Feed (Security Logs, Suspicious Changes, Feedback & Complaints)
// -------------------------------------------------------------
function fetchSuperAdminAlerts($conn): array {
    $alerts = [];
    $has_users = saCheckTableExists($conn, 'users');

    // A. Client Complaints & Feedback from chat_conversations
    if (saCheckTableExists($conn, 'chat_conversations')) {
        $complaint_sql = "
            SELECT c.id, c.customer_id, c.subject, c.status, c.priority, c.is_escalated, c.created_at, c.last_message_time,
                   " . ($has_users ? "u.full_name AS customer_name, u.email AS customer_email" : "'' AS customer_name, '' AS customer_email") . "
            FROM chat_conversations c
            " . ($has_users ? "LEFT JOIN users u ON u.id = c.customer_id" : "") . "
            WHERE (c.conversation_type = 'complaint' 
               OR c.subject LIKE '%complaint%' 
               OR c.subject LIKE '%[BUSINESS]%' 
               OR c.subject LIKE '%[ABUSE]%' 
               OR c.is_escalated = 1)
              AND c.status IN ('open', 'in_progress')
            ORDER BY (c.priority = 'urgent') DESC, (c.priority = 'high') DESC, c.updated_at DESC
            LIMIT 15
        ";
        $res = mysqli_query($conn, $complaint_sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $priority = strtolower((string)($row['priority'] ?? 'medium'));
                $severity = ($priority === 'urgent' || (int)$row['is_escalated'] === 1) ? 'critical' : ($priority === 'high' ? 'high' : 'medium');
                $customer = !empty($row['customer_name']) ? $row['customer_name'] : 'Customer #' . $row['customer_id'];
                
                $alerts[] = [
                    'id' => 'complaint_' . $row['id'],
                    'category' => 'complaint',
                    'category_label' => 'Shop Complaint',
                    'icon' => 'fa-comments-dollar',
                    'title' => (string)($row['subject'] ?: 'Customer Complaint'),
                    'message' => "Filed by {$customer} (Status: " . ucfirst($row['status']) . ")",
                    'severity' => $severity,
                    'status' => (string)$row['status'],
                    'created_at' => (string)$row['created_at'],
                    'time_ago' => saTimeElapsedString($row['created_at']),
                    'link' => 'reports_complaints.php?status=' . urlencode($row['status']) . '&search=' . urlencode((string)$row['id'])
                ];
            }
            mysqli_free_result($res);
        }
    }

    // B. Suspicious Changes & Security/Audit Logs from audit_logs
    if (saCheckTableExists($conn, 'audit_logs')) {
        $audit_sql = "
            SELECT a.id, a.user_id, a.action, a.module, a.description, a.ip_address, a.created_at,
                   " . ($has_users ? "u.full_name AS actor_name" : "'' AS actor_name") . "
            FROM audit_logs a
            " . ($has_users ? "LEFT JOIN users u ON u.id = a.user_id" : "") . "
            WHERE a.action IN (
                'USER_ROLE_ASSIGNED', 'ROLE_UPDATED', 'ROLE_CREATED', 'USER_ROLE_UPDATED',
                'PASSWORD_CHANGED', 'SECURITY_UPDATE', 'PERMISSION_CHANGED', 'ADMIN_AUTH_FAILED',
                'ACCOUNT_RESTRICTED', 'SETTINGS_OVERRIDE'
            ) OR a.action LIKE '%ROLE%' OR a.action LIKE '%SECURITY%' OR a.action LIKE '%PASSWORD%'
            ORDER BY a.created_at DESC
            LIMIT 15
        ";
        $res = mysqli_query($conn, $audit_sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $action_name = str_replace('_', ' ', (string)$row['action']);
                $actor = !empty($row['actor_name']) ? $row['actor_name'] : ($row['user_id'] ? 'User #' . $row['user_id'] : 'System');
                $alerts[] = [
                    'id' => 'audit_' . $row['id'],
                    'category' => 'security',
                    'category_label' => 'Security Log',
                    'icon' => 'fa-user-shield',
                    'title' => ucwords(strtolower($action_name)),
                    'message' => (string)($row['description'] ?: "Performed by {$actor}"),
                    'severity' => (strpos($row['action'], 'FAILED') !== false || strpos($row['action'], 'RESTRICTED') !== false) ? 'critical' : 'high',
                    'status' => 'logged',
                    'created_at' => (string)$row['created_at'],
                    'time_ago' => saTimeElapsedString($row['created_at']),
                    'link' => 'activity_logs.php?source=audit&action=' . urlencode((string)$row['action'])
                ];
            }
            mysqli_free_result($res);
        }
    }

    // C. System Anomaly Alerts from anomaly_alerts
    if (saCheckTableExists($conn, 'anomaly_alerts')) {
        $anomaly_sql = "
            SELECT alert_id, alert_type, alert_level, description, created_at
            FROM anomaly_alerts
            WHERE resolved_at IS NULL
            ORDER BY (alert_level = 'CRITICAL') DESC, (alert_level = 'HIGH') DESC, created_at DESC
            LIMIT 10
        ";
        $res = mysqli_query($conn, $anomaly_sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $level = strtolower((string)($row['alert_level'] ?? 'medium'));
                $alerts[] = [
                    'id' => 'anomaly_' . $row['alert_id'],
                    'category' => 'anomaly',
                    'category_label' => 'System Anomaly',
                    'icon' => 'fa-triangle-exclamation',
                    'title' => ucwords(str_replace('_', ' ', (string)$row['alert_type'])),
                    'message' => (string)$row['description'],
                    'severity' => $level,
                    'status' => 'unresolved',
                    'created_at' => (string)$row['created_at'],
                    'time_ago' => saTimeElapsedString($row['created_at']),
                    'link' => 'system_monitoring.php'
                ];
            }
            mysqli_free_result($res);
        }
    }

    // D. Operational Alerts & Incidents
    if (saCheckTableExists($conn, 'operational_incidents')) {
        $inc_sql = "
            SELECT id, incident_code, category, severity, title, description, status, detected_at
            FROM operational_incidents
            WHERE status IN ('open', 'investigating')
            ORDER BY (severity = 'critical') DESC, (severity = 'high') DESC, detected_at DESC
            LIMIT 10
        ";
        $res = mysqli_query($conn, $inc_sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $alerts[] = [
                    'id' => 'incident_' . $row['id'],
                    'category' => 'incident',
                    'category_label' => 'Ops Incident',
                    'icon' => 'fa-triangle-exclamation',
                    'title' => (string)$row['title'],
                    'message' => (string)$row['description'],
                    'severity' => strtolower((string)$row['severity']),
                    'status' => (string)$row['status'],
                    'created_at' => (string)$row['detected_at'],
                    'time_ago' => saTimeElapsedString($row['detected_at']),
                    'link' => 'operations_incidents.php'
                ];
            }
            mysqli_free_result($res);
        }
    }

    // E. Partner Warnings
    if (saCheckTableExists($conn, 'partner_warnings')) {
        $warn_sql = "
            SELECT pw.id, pw.partner_user_id, pw.warning_subject, pw.warning_message, pw.severity, pw.issued_at,
                   " . ($has_users ? "u.full_name AS partner_name" : "'' AS partner_name") . "
            FROM partner_warnings pw
            " . ($has_users ? "LEFT JOIN users u ON u.id = pw.partner_user_id" : "") . "
            WHERE pw.warning_status = 'active'
            ORDER BY pw.issued_at DESC
            LIMIT 8
        ";
        $res = mysqli_query($conn, $warn_sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $partner = !empty($row['partner_name']) ? $row['partner_name'] : 'Partner #' . $row['partner_user_id'];
                $alerts[] = [
                    'id' => 'warn_' . $row['id'],
                    'category' => 'warning',
                    'category_label' => 'Partner Warning',
                    'icon' => 'fa-store-slash',
                    'title' => (string)$row['warning_subject'],
                    'message' => "Issued for {$partner}: " . (string)$row['warning_message'],
                    'severity' => strtolower((string)$row['severity']),
                    'status' => 'active',
                    'created_at' => (string)$row['issued_at'],
                    'time_ago' => saTimeElapsedString($row['issued_at']),
                    'link' => 'user_business_management.php'
                ];
            }
            mysqli_free_result($res);
        }
    }

    // Sort combined alerts by timestamp descending
    usort($alerts, static function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
    });

    return $alerts;
}

// -------------------------------------------------------------
// 2. Fetching Standard Notifications (Franchise, Orders, Announcements)
// -------------------------------------------------------------
function fetchSuperAdminNotifications($conn, int $userId): array {
    $notifications = [];
    if (!saCheckTableExists($conn, 'notifications')) {
        return $notifications;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, user_id, type, title, message, related_id, related_type, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 25"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $row['time_ago'] = saTimeElapsedString($row['created_at']);
            
            // Generate appropriate action link
            $rel_type = strtolower(trim((string)($row['related_type'] ?? '')));
            $rel_id = (int)($row['related_id'] ?? 0);
            $type = strtolower(trim((string)($row['type'] ?? '')));

            if ($rel_type === 'franchise_application' || strpos($type, 'franchise') !== false) {
                $row['link'] = 'franchise_applications.php' . ($rel_id > 0 ? '?search=' . $rel_id : '');
            } elseif ($rel_type === 'order' || strpos($type, 'order') !== false) {
                $row['link'] = 'transactions_financial.php';
            } elseif ($rel_type === 'user') {
                $row['link'] = 'user_business_management.php';
            } else {
                $row['link'] = 'notification_center.php';
            }

            $notifications[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    return $notifications;
}

// -------------------------------------------------------------
// API Actions Dispatcher
// -------------------------------------------------------------
if ($action === 'count') {
    $all_alerts = fetchSuperAdminAlerts($conn);
    $active_alerts_count = count(array_filter($all_alerts, static function($a) {
        return in_array($a['severity'], ['critical', 'high', 'medium'], true);
    }));

    $unread_notifs_count = 0;
    if (saCheckTableExists($conn, 'notifications')) {
        $cstmt = mysqli_prepare($conn, "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($cstmt) {
            mysqli_stmt_bind_param($cstmt, "i", $current_user_id);
            mysqli_stmt_execute($cstmt);
            $cres = mysqli_stmt_get_result($cstmt);
            $crow = mysqli_fetch_assoc($cres);
            $unread_notifs_count = (int)($crow['unread_count'] ?? 0);
            mysqli_stmt_close($cstmt);
        }
    }

    echo json_encode([
        'success' => true,
        'alerts_count' => $active_alerts_count,
        'notifications_count' => $unread_notifs_count
    ]);
    exit;
}

if ($action === 'get_alerts') {
    $filter_category = strtolower(trim((string)($_GET['category'] ?? 'all')));
    $alerts = fetchSuperAdminAlerts($conn);

    if ($filter_category !== 'all') {
        $alerts = array_values(array_filter($alerts, static function($a) use ($filter_category) {
            if ($filter_category === 'security') return in_array($a['category'], ['security', 'anomaly'], true);
            if ($filter_category === 'complaints') return in_array($a['category'], ['complaint', 'warning'], true);
            return $a['category'] === $filter_category;
        }));
    }

    echo json_encode([
        'success' => true,
        'total' => count($alerts),
        'alerts' => array_slice($alerts, 0, 20)
    ]);
    exit;
}

if ($action === 'get_notifications') {
    $notifications = fetchSuperAdminNotifications($conn, $current_user_id);
    $unread_count = count(array_filter($notifications, static function($n) {
        return (int)$n['is_read'] === 0;
    }));

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'notifications' => $notifications
    ]);
    exit;
}

if ($action === 'mark_notification_read') {
    $notif_id = (int)($_POST['id'] ?? 0);
    if ($notif_id > 0 && saCheckTableExists($conn, 'notifications')) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $notif_id, $current_user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_all_notifications_read') {
    if (saCheckTableExists($conn, 'notifications')) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $current_user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Default action: return both for fast initial hydration
$alerts = fetchSuperAdminAlerts($conn);
$notifications = fetchSuperAdminNotifications($conn, $current_user_id);
$unread_notifs = count(array_filter($notifications, static function($n) { return (int)$n['is_read'] === 0; }));

echo json_encode([
    'success' => true,
    'alerts' => array_slice($alerts, 0, 20),
    'alerts_count' => count($alerts),
    'notifications' => $notifications,
    'notifications_count' => $unread_notifs
]);
