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
