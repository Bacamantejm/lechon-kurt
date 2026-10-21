<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

checkAdminAccess();

$current_admin_id = (int)($_SESSION['user_id'] ?? 0);
$current_operations_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$is_super_admin_user = $current_admin_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_admin_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
$current_role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$operations_scope_owner_id = 0;
$is_partner_scoped_admin = false;
$is_partner_owner_admin = false;
if (
    $current_admin_id > 0
    && function_exists('isApprovedFranchiseSellerAccount')
    && isApprovedFranchiseSellerAccount($conn, $current_admin_id)
    && function_exists('getFranchiseSellerScopeOwnerId')
) {
    $resolved_scope_owner_id = (int)(getFranchiseSellerScopeOwnerId($conn, $current_admin_id) ?? 0);
    if ($resolved_scope_owner_id > 0) {
        $operations_scope_owner_id = $resolved_scope_owner_id;
        $is_partner_scoped_admin = true;
        $is_partner_owner_admin = ($resolved_scope_owner_id === $current_admin_id);
    }
}

$operations_module_pages = [
    'operations_dashboard.php',
    'operations_dashboard_feed.php',
    'operations_incidents.php',
    'operations_user_business_control.php',
    'operations_content_moderation.php',
    'operations_decision_support.php',
    'operations_notifications.php',
    'operations_automation.php',
    'operations_logs_backups.php',
    'operations_team.php'
];
$is_operations_module_request = in_array($current_operations_page, $operations_module_pages, true);

$partner_allowed_operations_pages = [
    'operations_dashboard.php',
    'operations_dashboard_feed.php',
    'operations_incidents.php',
    'operations_user_business_control.php',
    'operations_content_moderation.php',
    'operations_decision_support.php',
    'operations_notifications.php',
    'operations_automation.php'
];
if (
    !$is_super_admin_user
    && $is_partner_scoped_admin
    && $is_operations_module_request
    && !in_array($current_operations_page, $partner_allowed_operations_pages, true)
) {
    denyAdminAccess('Access Denied: This operations page is reserved for system owner governance.');
}

$has_operations_access = false;
if ($current_admin_id > 0) {
    $has_operations_access = $is_partner_owner_admin || in_array($current_role_name, ['operational_manager', 'operations_staff'], true);
    if (!$has_operations_access && function_exists('hasPermission')) {
        $has_operations_access = hasPermission($conn, $current_admin_id, 'operations.view');
    }
    if (!$has_operations_access && function_exists('hasModuleAccess')) {
        $has_operations_access = hasModuleAccess($conn, $current_admin_id, 'operations');
    }
}

if (!$is_super_admin_user && !($is_operations_module_request && $has_operations_access)) {
    denyAdminAccess('Access Denied: You do not have permission to access this operations module.');
}

$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();

function saTableExists($conn, $table_name) {
    static $cache = [];
    $table_name = trim((string)$table_name);
    if ($table_name === '') {
        return false;
    }
    if (array_key_exists($table_name, $cache)) {
        return $cache[$table_name];
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    return $cache[$table_name] = ($result && mysqli_num_rows($result) > 0);
}

function saColumnExists($conn, $table_name, $column_name) {
    static $cache = [];
    $table_name = trim((string)$table_name);
    $column_name = trim((string)$column_name);
    $cache_key = $table_name . '.' . $column_name;

    if ($table_name === '' || $column_name === '') {
        return false;
    }
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }
    if (!saTableExists($conn, $table_name)) {
        return $cache[$cache_key] = false;
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $cache[$cache_key] = ($result && mysqli_num_rows($result) > 0);
}

function saQueryRows($conn, $query) {
    $runQuery = function () use ($conn, $query) {
        return mysqli_query($conn, $query);
    };

    try {
        $result = $runQuery();
    } catch (mysqli_sql_exception $e) {
        $message = (string)$e->getMessage();
        if (stripos($message, 'Illegal mix of collations') !== false) {
            // Retry once after forcing a consistent connection collation.
            @mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            @mysqli_query($conn, "SET collation_connection = 'utf8mb4_unicode_ci'");
            try {
                $result = $runQuery();
            } catch (mysqli_sql_exception $retry_exception) {
                error_log('saQueryRows SQL error (retry): ' . $retry_exception->getMessage());
                return [];
            }
        } else {
            error_log('saQueryRows SQL error: ' . $message);
            return [];
        }
    }
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function saQueryScalar($conn, $query, $default = 0) {
    $runQuery = function () use ($conn, $query) {
        return mysqli_query($conn, $query);
    };

    try {
        $result = $runQuery();
    } catch (mysqli_sql_exception $e) {
        $message = (string)$e->getMessage();
        if (stripos($message, 'Illegal mix of collations') !== false) {
            @mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            @mysqli_query($conn, "SET collation_connection = 'utf8mb4_unicode_ci'");
            try {
                $result = $runQuery();
            } catch (mysqli_sql_exception $retry_exception) {
                error_log('saQueryScalar SQL error (retry): ' . $retry_exception->getMessage());
                return $default;
            }
        } else {
            error_log('saQueryScalar SQL error: ' . $message);
            return $default;
        }
    }
    if (!$result) {
        return $default;
    }

    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    return $row[0] ?? $default;
}

function saEscapeLike($conn, $value) {
    $safe = mysqli_real_escape_string($conn, (string)$value);
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $safe);
}

function saSetFlash($type, $message) {
    if (!isset($_SESSION['sa_flash']) || !is_array($_SESSION['sa_flash'])) {
        $_SESSION['sa_flash'] = [];
    }
    $_SESSION['sa_flash'][] = [
        'type' => in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info',
        'message' => (string)$message
    ];
}

function saPullFlash() {
    $messages = (isset($_SESSION['sa_flash']) && is_array($_SESSION['sa_flash'])) ? $_SESSION['sa_flash'] : [];
    unset($_SESSION['sa_flash']);
    return $messages;
}

function saRequireValidCsrf($token, $redirect_url) {
    if (!validateCSRFToken($token)) {
        saSetFlash('danger', 'Invalid request token. Please refresh and try again.');
        header('Location: ' . $redirect_url);
        exit;
    }
}

function saLogAudit($conn, $user_id, $action, $module, $description) {
    if (!saTableExists($conn, 'audit_logs')) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $sql = "INSERT INTO audit_logs (user_id, action, module, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return;
    }

    $uid = (int)$user_id;
    $action = substr(trim((string)$action), 0, 100);
    $module = substr(trim((string)$module), 0, 50);
    $description = (string)$description;
    mysqli_stmt_bind_param($stmt, "isssss", $uid, $action, $module, $description, $ip, $user_agent);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function saFormatDateTime($value, $format = 'M d, Y h:i A', $fallback = '-') {
    $text = trim((string)$value);
    if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($text);
    if ($timestamp === false) {
        return $fallback;
    }

    return date($format, $timestamp);
}

function saFormatCurrency($amount) {
    return 'PHP ' . number_format((float)$amount, 2);
}

function saOutputCsv($filename, array $headers, array $rows) {
    $safe_filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)$filename);
    if ($safe_filename === '') {
        $safe_filename = 'export.csv';
    }
    if (stripos($safe_filename, '.csv') === false) {
        $safe_filename .= '.csv';
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function saRenderModuleHeader($page_title, $page_heading, $admin_info) {
    $safe_title = htmlspecialchars((string)$page_title, ENT_QUOTES, 'UTF-8');
    $safe_heading = htmlspecialchars((string)$page_heading, ENT_QUOTES, 'UTF-8');
    $safe_admin_name = htmlspecialchars((string)($admin_info['full_name'] ?? 'Super Admin'), ENT_QUOTES, 'UTF-8');
    $flash_messages = saPullFlash();
    $flash_payload = json_encode(
        $flash_messages,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    if ($flash_payload === false) {
        $flash_payload = '[]';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="../admin/">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $safe_title; ?> - Super Admin</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="../super_admin/modules.css">
    <style>
        .sa-topbar-action-wrap { position:relative; margin-right:8px; }
        .sa-topbar-action-btn { position:relative; width:38px; height:38px; border:1px solid #d0d5dd; border-radius:9px; background:#fff; color:#344054; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:all 0.2s ease; }
        .sa-topbar-action-btn:hover, .sa-topbar-action-btn.is-active { background:#fff1f0; color:#b3261e; border-color:#fda29b; }
        .sa-alert-btn:hover, .sa-alert-btn.is-active { background:#fff1f0; color:#b3261e; border-color:#fda29b; }
        .sa-action-badge { position:absolute; top:-5px; right:-5px; min-width:18px; height:18px; padding:0 4px; border-radius:99px; font-size:10px; font-weight:800; display:none; align-items:center; justify-content:center; color:#fff; border:1px solid #fff; }
        .sa-alert-badge { background:#b3261e; }
        .sa-notification-badge { background:#175cd3; }
        .sa-dropdown-panel { position:absolute; top:calc(100% + 10px); right:0; z-index:2000; width:350px; height:460px; max-height:calc(100vh - 90px); display:none; flex-direction:column; overflow:hidden; background:#fff; border:1px solid #e4e7ec; border-radius:12px; box-shadow:0 14px 32px rgba(16,24,40,.14); }
        .sa-dropdown-panel.show { display:flex; }
        .sa-dropdown-head { flex:0 0 auto; display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid #eaecf0; font-weight:800; font-size:0.9rem; color:#101828; }
        .sa-dropdown-head-link { font-size:0.78rem; font-weight:700; color:#b3261e; text-decoration:none; cursor:pointer; background:none; border:none; padding:0; }
        .sa-dropdown-head-link:hover { color:#981b15; text-decoration:underline; }
        .sa-filter-bar { flex:0 0 auto; display:flex; gap:6px; padding:8px 12px; background:#f8f9fa; border-bottom:1px solid #eaecf0; overflow-x:auto; }
        .sa-filter-pill { border:1px solid #d0d5dd; background:#fff; color:#475467; font-size:0.74rem; font-weight:700; padding:3px 10px; border-radius:20px; cursor:pointer; white-space:nowrap; transition:all 0.15s ease; }
        .sa-filter-pill:hover { background:#f2f4f7; color:#1d2939; }
        .sa-filter-pill.active { background:#b3261e; color:#fff; border-color:#b3261e; }
        .sa-dropdown-list { flex:1 1 auto; min-height:0; overflow-y:auto; }
        .sa-item { display:block; padding:11px 13px; border-bottom:1px solid #eaecf0; color:#344054; text-decoration:none; transition:background 0.15s ease; }
        .sa-item:hover { background:#f8f9fa; }
        .sa-item.unread { background:#eff8ff; }
        .sa-item-header { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:4px; }
        .sa-tag { font-size:0.68rem; font-weight:800; letter-spacing:0.03em; padding:2px 7px; border-radius:6px; text-transform:uppercase; }
        .sa-tag.security { background:#f4f3ff; color:#5925dc; border:1px solid #d9d6fe; }
        .sa-tag.complaint { background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; }
        .sa-tag.anomaly { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
        .sa-tag.warning { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
        .sa-tag.incident { background:#fef3f2; color:#b42318; border:1px solid #fee4e2; }
        .sa-tag.approved { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
        .sa-tag.rejected { background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; }
        .sa-tag.incomplete { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
        .sa-tag.default { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }
        .sa-item-title { font-size:0.83rem; font-weight:700; color:#101828; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .sa-item-desc { margin-top:3px; font-size:0.79rem; line-height:1.38; color:#475467; word-break:break-word; }
        .sa-item-time { display:block; margin-top:5px; color:#98a2b3; font-size:0.71rem; font-weight:600; }
        .sa-dropdown-pages { flex:0 0 auto; display:flex; align-items:center; justify-content:space-between; padding:8px 12px; border-top:1px solid #eaecf0; background:#f8f9fa; }
        .sa-dropdown-page-btn { width:28px; height:28px; border:1px solid #d0d5dd; border-radius:7px; background:#fff; cursor:pointer; color:#344054; display:inline-flex; align-items:center; justify-content:center; }
        .sa-dropdown-page-btn:disabled { opacity:.35; cursor:not-allowed; }
        .sa-dropdown-page-label { font-size:.72rem; font-weight:800; color:#667085; }
        body.dark-mode .sa-topbar-action-btn { background:#1e293b; color:#f8fafc; border-color:#475569; }
        body.dark-mode .sa-dropdown-panel { background:#1e293b; border-color:#475569; }
        body.dark-mode .sa-dropdown-head, body.dark-mode .sa-item { border-color:#334155; color:#f8fafc; }
        body.dark-mode .sa-dropdown-head { color:#f8fafc; }
        body.dark-mode .sa-item-title { color:#f8fafc; }
        body.dark-mode .sa-item:hover { background:#334155; }
        body.dark-mode .sa-item.unread { background:#1e3a5f; }
        body.dark-mode .sa-filter-bar, body.dark-mode .sa-dropdown-pages { background:#0f172a; border-color:#334155; }
        body.dark-mode .sa-filter-pill { background:#1e293b; border-color:#475569; color:#cbd5e1; }
        body.dark-mode .sa-filter-pill.active { background:#b3261e; color:#fff; border-color:#b3261e; }
        body.dark-mode .sa-item-desc, body.dark-mode .sa-item-time, body.dark-mode .sa-dropdown-page-label { color:#94a3b8; }
        @media (max-width:560px) { .sa-dropdown-panel { width:min(320px, calc(100vw - 20px)); right:-60px; } }
    </style>
</head>
<body>
    <div class="page-loader"><div class="spinner"></div></div>
    <div class="admin-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1><?php echo $safe_heading; ?></h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"></div>
                        
                        <!-- 1. System & Shop Alerts -->
                        <div class="sa-topbar-action-wrap sa-alert-wrap">
                            <button type="button" class="sa-topbar-action-btn sa-alert-btn" id="saAlertBtn" aria-label="Open Security and Shop Alerts" title="Security & Shop Alerts">
                                <i class="fas fa-triangle-exclamation"></i><span class="sa-action-badge sa-alert-badge" id="saAlertBadge">0</span>
                            </button>
                            <div class="sa-dropdown-panel sa-alert-dropdown" id="saAlertDropdown">
                                <div class="sa-dropdown-head">
                                    <span>Security & Shop Alerts</span>
                                    <a href="../super_admin/reports_complaints.php" class="sa-dropdown-head-link">View All</a>
                                </div>
                                <div class="sa-filter-bar" id="saAlertFilters">
                                    <button type="button" class="sa-filter-pill active" data-filter="all">All</button>
                                    <button type="button" class="sa-filter-pill" data-filter="security">Security & Logs</button>
                                    <button type="button" class="sa-filter-pill" data-filter="complaints">Complaints & Reports</button>
                                </div>
                                <div class="sa-dropdown-list" id="saAlertList"><div class="p-3 text-muted">Loading alerts...</div></div>
                                <div class="sa-dropdown-pages" id="saAlertPages" hidden>
                                    <button type="button" class="sa-dropdown-page-btn" id="saAlertPrev" aria-label="Previous alerts"><i class="fas fa-chevron-left"></i></button>
                                    <span class="sa-dropdown-page-label" id="saAlertPageLabel">1 / 1</span>
                                    <button type="button" class="sa-dropdown-page-btn" id="saAlertNext" aria-label="Next alerts"><i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Platform Notifications -->
                        <div class="sa-topbar-action-wrap sa-notification-wrap">
                            <button type="button" class="sa-topbar-action-btn sa-notification-btn" id="saNotificationBtn" aria-label="Open Notifications" title="Platform Notifications">
                                <i class="fas fa-bell"></i><span class="sa-action-badge sa-notification-badge" id="saNotificationBadge">0</span>
                            </button>
                            <div class="sa-dropdown-panel sa-notification-dropdown" id="saNotificationDropdown">
                                <div class="sa-dropdown-head">
                                    <span>Notifications</span>
                                    <button type="button" class="sa-dropdown-head-link" id="saMarkAllNotifsRead">Mark all read</button>
                                </div>
                                <div class="sa-dropdown-list" id="saNotificationList"><div class="p-3 text-muted">Loading notifications...</div></div>
                                <div class="sa-dropdown-pages" id="saNotificationPages" hidden>
                                    <button type="button" class="sa-dropdown-page-btn" id="saNotificationPrev" aria-label="Previous notifications"><i class="fas fa-chevron-left"></i></button>
                                    <span class="sa-dropdown-page-label" id="saNotificationPageLabel">1 / 1</span>
                                    <button type="button" class="sa-dropdown-page-btn" id="saNotificationNext" aria-label="Next notifications"><i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="admin-profile">
                            <span><?php echo $safe_admin_name; ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <script>
                    window.__saFlashMessages = <?php echo $flash_payload; ?>;
                </script>
    <?php
}

function saRenderModuleFooter($extra_scripts = '') {
    ?>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
    <script>
        (function () {
            // Endpoints
            const alertsEndpoint = '../super_admin/get_alerts.php';
            const escapeHtml = (val) => String(val ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);

            // -------------------------------------------------------------
            // 1. Alerts Logic
            // -------------------------------------------------------------
            const alertBtn = document.getElementById('saAlertBtn');
            const alertDropdown = document.getElementById('saAlertDropdown');
            const alertBadge = document.getElementById('saAlertBadge');
            const alertList = document.getElementById('saAlertList');
            const alertPages = document.getElementById('saAlertPages');
            const alertPrev = document.getElementById('saAlertPrev');
            const alertNext = document.getElementById('saAlertNext');
            const alertPageLabel = document.getElementById('saAlertPageLabel');
            const alertFilters = document.getElementById('saAlertFilters');

            let rawAlerts = [];
            let filteredAlerts = [];
            let alertPage = 0;
            const alertPageSize = 4;
            let currentFilter = 'all';

            function filterAndRenderAlerts() {
                if (currentFilter === 'all') {
                    filteredAlerts = rawAlerts;
                } else if (currentFilter === 'security') {
                    filteredAlerts = rawAlerts.filter(a => a.category === 'security' || a.category === 'anomaly' || a.category === 'incident');
                } else if (currentFilter === 'complaints') {
                    filteredAlerts = rawAlerts.filter(a => a.category === 'complaint' || a.category === 'warning');
                }
                const totalAlertPages = Math.max(1, Math.ceil(filteredAlerts.length / alertPageSize));
                alertPage = Math.min(Math.max(0, alertPage), totalAlertPages - 1);
                alertList.innerHTML = '';

                if (!filteredAlerts.length) {
                    alertList.innerHTML = '<div class="p-4 text-center text-muted" style="font-size:0.83rem;"><i class="fas fa-check-circle text-success me-1"></i> No active alerts found</div>';
                    if (alertPages) alertPages.hidden = true;
                    return;
                }

                const pageItems = filteredAlerts.slice(alertPage * alertPageSize, (alertPage + 1) * alertPageSize);
                pageItems.forEach(item => {
                    const el = document.createElement('a');
                    el.className = 'sa-item';
                    el.href = item.link || '#';
                    const tagClass = item.category ? item.category : 'default';
                    el.innerHTML = `
                        <div class="sa-item-header">
                            <span class="sa-tag ${tagClass}">${escapeHtml(item.category_label || item.category || 'Alert')}</span>
                            <span class="sa-tag ${item.severity === 'critical' ? 'rejected' : (item.severity === 'high' ? 'incomplete' : 'default')}">${escapeHtml(item.severity || 'info')}</span>
                        </div>
                        <div class="sa-item-title"><i class="fas ${escapeHtml(item.icon || 'fa-triangle-exclamation')} me-1 text-muted"></i> ${escapeHtml(item.title)}</div>
                        <div class="sa-item-desc">${escapeHtml(item.message)}</div>
                        <time class="sa-item-time">${escapeHtml(item.time_ago || '')}</time>
                    `;
                    alertList.appendChild(el);
                });

                if (alertPages) alertPages.hidden = totalAlertPages <= 1;
                if (alertPageLabel) alertPageLabel.textContent = (alertPage + 1) + ' / ' + totalAlertPages;
                if (alertPrev) alertPrev.disabled = alertPage === 0;
                if (alertNext) alertNext.disabled = alertPage >= totalAlertPages - 1;
            }

            function loadAlerts() {
                fetch(alertsEndpoint + '?action=get_alerts', { credentials: 'same-origin' })
                    .then(res => res.json())
                    .then(data => {
                        rawAlerts = Array.isArray(data.alerts) ? data.alerts : [];
                        const criticalOrHigh = rawAlerts.filter(a => ['critical', 'high', 'medium'].includes(a.severity)).length;
                        if (alertBadge) {
                            alertBadge.textContent = criticalOrHigh > 99 ? '99+' : String(criticalOrHigh);
                            alertBadge.style.display = criticalOrHigh > 0 ? 'inline-flex' : 'none';
                        }
                        filterAndRenderAlerts();
                    })
                    .catch(() => {
                        if (alertList) alertList.innerHTML = '<div class="p-3 text-muted">Alerts unavailable</div>';
                    });
            }

            if (alertFilters) {
                alertFilters.querySelectorAll('.sa-filter-pill').forEach(pill => {
                    pill.addEventListener('click', (e) => {
                        e.stopPropagation();
                        alertFilters.querySelectorAll('.sa-filter-pill').forEach(p => p.classList.remove('active'));
                        pill.classList.add('active');
                        currentFilter = pill.getAttribute('data-filter') || 'all';
                        alertPage = 0;
                        filterAndRenderAlerts();
                    });
                });
            }

            if (alertBtn && alertDropdown) {
                alertBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (notifDropdown) {
                        notifDropdown.classList.remove('show');
                        if (notifBtn) notifBtn.classList.remove('is-active');
                    }
                    alertDropdown.classList.toggle('show');
                    alertBtn.classList.toggle('is-active', alertDropdown.classList.contains('show'));
                    if (alertDropdown.classList.contains('show')) loadAlerts();
                });
            }
            if (alertPrev) {
                alertPrev.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (alertPage > 0) { alertPage--; filterAndRenderAlerts(); }
                });
            }
            if (alertNext) {
                alertNext.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const totalAlertPages = Math.max(1, Math.ceil(filteredAlerts.length / alertPageSize));
                    if (alertPage < totalAlertPages - 1) { alertPage++; filterAndRenderAlerts(); }
                });
            }

            // -------------------------------------------------------------
            // 2. Notifications Logic
            // -------------------------------------------------------------
            const notifBtn = document.getElementById('saNotificationBtn');
            const notifDropdown = document.getElementById('saNotificationDropdown');
            const notifBadge = document.getElementById('saNotificationBadge');
            const notifList = document.getElementById('saNotificationList');
            const notifPages = document.getElementById('saNotificationPages');
            const notifPrev = document.getElementById('saNotificationPrev');
            const notifNext = document.getElementById('saNotificationNext');
            const notifPageLabel = document.getElementById('saNotificationPageLabel');
            const markAllBtn = document.getElementById('saMarkAllNotifsRead');

            let notifications = [];
            let notifPage = 0;
            const notifPageSize = 4;

            function renderNotifications() {
                const totalPages = Math.max(1, Math.ceil(notifications.length / notifPageSize));
                notifPage = Math.min(Math.max(0, notifPage), totalPages - 1);
                notifList.innerHTML = '';

                if (!notifications.length) {
                    notifList.innerHTML = '<div class="p-4 text-center text-muted" style="font-size:0.83rem;"><i class="fas fa-bell-slash text-muted me-1"></i> No notifications</div>';
                    if (notifPages) notifPages.hidden = true;
                    return;
                }

                const pageItems = notifications.slice(notifPage * notifPageSize, (notifPage + 1) * notifPageSize);
                pageItems.forEach((notification) => {
                    const rawType = String(notification.type || '').toLowerCase();
                    const match = rawType.match(/franchise_(approved|rejected|incomplete)/);
                    const statusTag = match ? match[1] : (notification.related_type || 'update');
                    const isUnread = Number(notification.is_read) === 0;

                    const item = document.createElement('a');
                    item.className = `sa-item ${isUnread ? 'unread' : ''}`;
                    item.href = notification.link || '#';
                    item.innerHTML = `
                        <div class="sa-item-header">
                            <span class="sa-tag ${statusTag}">${escapeHtml(statusTag.toUpperCase())}</span>
                            ${isUnread ? '<span class="sa-tag complaint" style="font-size:0.6rem; padding:1px 5px;">NEW</span>' : ''}
                        </div>
                        <div class="sa-item-title"><i class="fas fa-bell me-1 text-muted"></i> ${escapeHtml(notification.title || 'Platform Notification')}</div>
                        <div class="sa-item-desc">${escapeHtml(notification.message || '')}</div>
                        <time class="sa-item-time">${escapeHtml(notification.time_ago || '')}</time>
                    `;
                    item.addEventListener('click', () => {
                        if (isUnread) {
                            const form = new FormData();
                            form.append('id', notification.id);
                            fetch(alertsEndpoint + '?action=mark_notification_read', { method: 'POST', body: form }).catch(() => {});
                        }
                    });
                    notifList.appendChild(item);
                });

                if (notifPages) notifPages.hidden = totalPages <= 1;
                if (notifPageLabel) notifPageLabel.textContent = (notifPage + 1) + ' / ' + totalPages;
                if (notifPrev) notifPrev.disabled = notifPage === 0;
                if (notifNext) notifNext.disabled = notifPage >= totalPages - 1;
            }

            function loadNotifications() {
                fetch(alertsEndpoint + '?action=get_notifications', { credentials: 'same-origin' })
                    .then(res => res.json())
                    .then(data => {
                        notifications = Array.isArray(data.notifications) ? data.notifications : [];
                        const unread = Number(data.unread_count ?? notifications.filter(n => Number(n.is_read) === 0).length);
                        if (notifBadge) {
                            notifBadge.textContent = unread > 99 ? '99+' : String(unread);
                            notifBadge.style.display = unread > 0 ? 'inline-flex' : 'none';
                        }
                        renderNotifications();
                    })
                    .catch(() => {
                        if (notifList) notifList.innerHTML = '<div class="p-3 text-muted">Notifications unavailable</div>';
                    });
            }

            if (notifBtn && notifDropdown) {
                notifBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (alertDropdown) {
                        alertDropdown.classList.remove('show');
                        if (alertBtn) alertBtn.classList.remove('is-active');
                    }
                    notifDropdown.classList.toggle('show');
                    notifBtn.classList.toggle('is-active', notifDropdown.classList.contains('show'));
                    if (notifDropdown.classList.contains('show')) loadNotifications();
                });
            }
            if (notifPrev) {
                notifPrev.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (notifPage > 0) { notifPage--; renderNotifications(); }
                });
            }
            if (notifNext) {
                notifNext.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const totalPages = Math.max(1, Math.ceil(notifications.length / notifPageSize));
                    if (notifPage < totalPages - 1) { notifPage++; renderNotifications(); }
                });
            }
            if (markAllBtn) {
                markAllBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    fetch(alertsEndpoint + '?action=mark_all_notifications_read', { method: 'POST' })
                        .then(() => loadNotifications())
                        .catch(() => {});
                });
            }

            // Close on outside click
            document.addEventListener('click', (event) => {
                if (alertDropdown && !alertDropdown.contains(event.target) && event.target !== alertBtn) {
                    alertDropdown.classList.remove('show');
                    if (alertBtn) alertBtn.classList.remove('is-active');
                }
                if (notifDropdown && !notifDropdown.contains(event.target) && event.target !== notifBtn) {
                    notifDropdown.classList.remove('show');
                    if (notifBtn) notifBtn.classList.remove('is-active');
                }
            });

            // Initial counts & periodic sync
            function refreshAllCounts() {
                fetch(alertsEndpoint + '?action=count', { credentials: 'same-origin' })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success) {
                            if (alertBadge) {
                                alertBadge.textContent = data.alerts_count > 99 ? '99+' : String(data.alerts_count);
                                alertBadge.style.display = data.alerts_count > 0 ? 'inline-flex' : 'none';
                            }
                            if (notifBadge) {
                                notifBadge.textContent = data.notifications_count > 99 ? '99+' : String(data.notifications_count);
                                notifBadge.style.display = data.notifications_count > 0 ? 'inline-flex' : 'none';
                            }
                        }
                    }).catch(() => {});
            }

            loadAlerts();
            loadNotifications();
            refreshAllCounts();
            window.setInterval(refreshAllCounts, 30000);
        })();
    </script>
    <script>
        (function() {
            const themeToggler = document.getElementById('themeToggler');
            if (!themeToggler) return;
            const body = document.body;
            const icon = themeToggler.querySelector('i');

            if (localStorage.getItem('theme') === 'dark') {
                body.classList.add('dark-mode');
                if (icon) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                }
            }

            themeToggler.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                if (icon) {
                    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                }
            });
        })();

        (function() {
            const rawMessages = window.__saFlashMessages;
            if (!window.Swal || !Array.isArray(rawMessages) || rawMessages.length === 0) {
                return;
            }

            const iconMap = {
                success: 'success',
                danger: 'error',
                warning: 'warning',
                info: 'info'
            };

            const titleMap = {
                success: 'Success',
                danger: 'Error',
                warning: 'Warning',
                info: 'Info'
            };

            let queue = Promise.resolve();
            rawMessages.forEach((entry) => {
                const type = String(entry && entry.type ? entry.type : 'info').toLowerCase();
                const message = String(entry && entry.message ? entry.message : '').trim();
                if (!message) {
                    return;
                }

                queue = queue.then(() => Swal.fire({
                    icon: iconMap[type] || 'info',
                    title: titleMap[type] || 'Notice',
                    text: message,
                    confirmButtonColor: '#9f1239'
                }));
            });
        })();

        (function() {
            if (!window.Swal) {
                return;
            }

            const getFormFieldValue = (form, fieldName, preferLabel) => {
                if (!(form instanceof HTMLFormElement) || !fieldName) {
                    return '';
                }

                const field = form.elements.namedItem(fieldName);
                if (!field) {
                    return '';
                }

                if (field instanceof RadioNodeList) {
                    const selectedValue = field.value || '';
                    if (!preferLabel) {
                        return selectedValue;
                    }
                    const selectedInput = Array.from(field).find((item) => item && item.checked);
                    if (!selectedInput) {
                        return selectedValue;
                    }
                    const radioId = selectedInput.id || '';
                    const label = radioId ? form.querySelector('label[for="' + CSS.escape(radioId) + '"]') : null;
                    return (label && label.textContent ? label.textContent : selectedValue).trim();
                }

                if (field instanceof HTMLSelectElement) {
                    if (!preferLabel) {
                        return String(field.value || '').trim();
                    }
                    const selectedOption = field.options[field.selectedIndex];
                    return String(selectedOption ? selectedOption.text : field.value || '').trim();
                }

                if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                    return String(field.value || '').trim();
                }

                return '';
            };

            const resolveConfirmTemplate = (template, form) => {
                const rawTemplate = String(template || '');
                if (rawTemplate === '') {
                    return '';
                }

                return rawTemplate.replace(/\{(field|field_label):([^}]+)\}/g, (match, mode, fieldName) => {
                    const resolved = getFormFieldValue(form, String(fieldName || '').trim(), mode === 'field_label');
                    return resolved !== '' ? resolved : '';
                }).replace(/\s+/g, ' ').trim();
            };

            const readConfirmConfig = (form, submitter) => {
                const submitterData = submitter && submitter.dataset ? submitter.dataset : null;
                const formData = form && form.dataset ? form.dataset : null;
                const source = (submitterData && submitterData.saConfirm === '1') ? submitterData : formData;

                if (!source || source.saConfirm !== '1') {
                    return null;
                }

                return {
                    title: resolveConfirmTemplate(source.saConfirmTitleTemplate || source.saConfirmTitle, form) || 'Confirm Action',
                    text: resolveConfirmTemplate(source.saConfirmTextTemplate || source.saConfirmText, form) || 'Are you sure you want to continue?',
                    icon: source.saConfirmIcon || 'warning',
                    confirmText: resolveConfirmTemplate(source.saConfirmConfirmTextTemplate || source.saConfirmConfirmText, form) || 'Yes, Continue',
                    cancelText: resolveConfirmTemplate(source.saConfirmCancelTextTemplate || source.saConfirmCancelText, form) || 'Cancel',
                    confirmColor: source.saConfirmConfirmColor || '#9f1239',
                    cancelColor: source.saConfirmCancelColor || '#64748b'
                };
            };

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                if (form.dataset.saConfirmSubmitting === '1') {
                    form.dataset.saConfirmSubmitting = '';
                    return;
                }

                const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
                const config = readConfirmConfig(form, submitter);
                if (!config) {
                    return;
                }

                event.preventDefault();
                Swal.fire({
                    title: config.title,
                    text: config.text,
                    icon: config.icon,
                    showCancelButton: true,
                    confirmButtonText: config.confirmText,
                    cancelButtonText: config.cancelText,
                    confirmButtonColor: config.confirmColor,
                    cancelButtonColor: config.cancelColor
                }).then((result) => {
                    if (!result.isConfirmed) {
                        return;
                    }

                    form.dataset.saConfirmSubmitting = '1';
                    if (submitter && typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter);
                    } else {
                        form.submit();
                    }
                });
            }, true);
        })();
    </script>
    <?php if ($extra_scripts !== ''): ?>
        <?php echo $extra_scripts; ?>
    <?php endif; ?>
</body>
</html>
    <?php
}
