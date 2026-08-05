<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../email_service.php';
require_once __DIR__ . '/hr_module_common.php';
require_once '../includes/partner_order_policy_helper.php';

checkAdminAccess();
popEnsurePolicySchema($conn);
$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$expected_admin_signature = trim((string)($admin_info['full_name'] ?? ''));
$normalized_expected_admin_signature = strtolower(preg_replace('/\s+/', ' ', $expected_admin_signature));
$admin_user_id = intval($_SESSION['user_id'] ?? 0);
$current_user_id = $admin_user_id;
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;
$partner_order_scope_exists_sql = '';
if ($seller_scope_id !== null) {
    $partner_order_scope_exists_sql = "EXISTS (
        SELECT 1
        FROM order_items oi_scope
        INNER JOIN products p_scope
            ON (oi_scope.product_id = p_scope.product_id COLLATE utf8mb4_general_ci OR oi_scope.product_id = CAST(p_scope.id AS CHAR) COLLATE utf8mb4_general_ci)
        WHERE oi_scope.order_id = o.id
          AND p_scope.seller_id = " . (int)$seller_scope_id . "
    )";
}
$can_manage_finance = true;
if (function_exists('hasPermission')) {
    $can_manage_finance = hasPermission($conn, $admin_user_id, 'finance.manage');
}
if ($is_partner_owner_admin) {
    $can_manage_finance = true;
}
if ((string)($_SESSION['role_name'] ?? '') === 'super_admin' || ((string)($_SESSION['user_type'] ?? '') === 'admin' && intval($_SESSION['role_id'] ?? 0) <= 0)) {
    $can_manage_finance = true;
}

if (!function_exists('financeEnforceManageAccess')) {
    function financeEnforceManageAccess($can_manage_finance, $is_ajax_request = false) {
        if ($can_manage_finance) {
            return;
        }
        if ($is_ajax_request) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have permission to manage this finance action.'
            ]);
            exit();
        }
        $_SESSION['error'] = 'You do not have permission to manage this finance action.';
        header('Location: finance.php');
        exit();
    }
}

$is_partner_finance_scope = ($seller_scope_id !== null);
$finance_scope_badge_class = $is_partner_finance_scope ? 'scope-badge-partner' : 'scope-badge-global';
$finance_scope_badge_icon = $is_partner_finance_scope ? 'fa-store' : 'fa-globe';
$finance_scope_badge_label = $is_partner_finance_scope ? 'Partner Finance Scope' : 'Global Finance Scope';
$finance_scope_badge_hint = $is_partner_finance_scope
    ? 'Processing is limited to your business partner queue.'
    : 'Processing covers shared global finance queues.';

function ensureFinanceSignatureAuditTable($conn) {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    $create_sql = "
        CREATE TABLE IF NOT EXISTS finance_signature_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id INT(11) NOT NULL,
            signed_by VARCHAR(100) NOT NULL,
            signature_input VARCHAR(150) NOT NULL,
            signature_image_path VARCHAR(255) DEFAULT NULL,
            action_key VARCHAR(50) NOT NULL,
            action_type ENUM('approve','reject') NOT NULL,
            entity_type ENUM('refund','cancellation','payroll') NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            decision_note TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_user_id (admin_user_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_action_type (action_type),
            KEY idx_signed_at (signed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $ok = mysqli_query($conn, $create_sql);
    if (!$ok) {
        return false;
    }

    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM finance_signature_audit_log LIKE 'signature_image_path'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        if (!mysqli_query($conn, "ALTER TABLE finance_signature_audit_log ADD COLUMN signature_image_path VARCHAR(255) DEFAULT NULL AFTER signature_input")) {
            return false;
        }
    }

    $initialized = true;
    return true;
}

function financeWriteSignatureAudit($conn, $admin_user_id, $signed_by, $signature_input, $action_key, $action_type, $entity_type, $entity_id, $decision_note = '', $signature_image_path = '') {
    if (!ensureFinanceSignatureAuditTable($conn)) {
        return false;
    }

    $signed_by = function_exists('mb_substr') ? mb_substr((string)$signed_by, 0, 100) : substr((string)$signed_by, 0, 100);
    $signature_input = function_exists('mb_substr') ? mb_substr((string)$signature_input, 0, 150) : substr((string)$signature_input, 0, 150);
    $signature_image_path = function_exists('mb_substr') ? mb_substr((string)$signature_image_path, 0, 255) : substr((string)$signature_image_path, 0, 255);
    $action_key = function_exists('mb_substr') ? mb_substr((string)$action_key, 0, 50) : substr((string)$action_key, 0, 50);
    $entity_type = function_exists('mb_substr') ? mb_substr((string)$entity_type, 0, 20) : substr((string)$entity_type, 0, 20);
    $decision_note = function_exists('mb_substr') ? mb_substr((string)$decision_note, 0, 2000) : substr((string)$decision_note, 0, 2000);
    $ip_address = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = mysqli_prepare($conn, "INSERT INTO finance_signature_audit_log (admin_user_id, signed_by, signature_input, signature_image_path, action_key, action_type, entity_type, entity_id, decision_note, ip_address, user_agent, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "issssssisss",
        $admin_user_id,
        $signed_by,
        $signature_input,
        $signature_image_path,
        $action_key,
        $action_type,
        $entity_type,
        $entity_id,
        $decision_note,
        $ip_address,
        $user_agent
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function ensureRefundPayoutColumns($conn) {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    $alter_map = [
        'payout_channel' => "ALTER TABLE refunds ADD COLUMN payout_channel VARCHAR(40) DEFAULT NULL AFTER remarks",
        'payout_reference' => "ALTER TABLE refunds ADD COLUMN payout_reference VARCHAR(120) DEFAULT NULL AFTER payout_channel",
        'payout_account_name' => "ALTER TABLE refunds ADD COLUMN payout_account_name VARCHAR(120) DEFAULT NULL AFTER payout_reference",
        'payout_account_masked' => "ALTER TABLE refunds ADD COLUMN payout_account_masked VARCHAR(80) DEFAULT NULL AFTER payout_account_name",
        'payout_proof' => "ALTER TABLE refunds ADD COLUMN payout_proof VARCHAR(255) DEFAULT NULL AFTER payout_account_masked",
        'payout_finance_signature' => "ALTER TABLE refunds ADD COLUMN payout_finance_signature VARCHAR(255) DEFAULT NULL AFTER payout_proof",
        'payout_sent_at' => "ALTER TABLE refunds ADD COLUMN payout_sent_at DATETIME DEFAULT NULL AFTER payout_finance_signature"
    ];

    foreach ($alter_map as $column_name => $alter_sql) {
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM refunds LIKE '{$safe_column}'");
        if ($column_check && mysqli_num_rows($column_check) > 0) {
            continue;
        }
        if (!mysqli_query($conn, $alter_sql)) {
            error_log("Finance refund payout column init failed for {$column_name}: " . mysqli_error($conn));
            return false;
        }
    }

    $initialized = true;
    return true;
}

function financeTableExists($conn, $table_name) {
    static $cache = [];

    $table_name = strtolower(trim((string)$table_name));
    if ($table_name === '') {
        return false;
    }
    if (array_key_exists($table_name, $cache)) {
        return $cache[$table_name];
    }

    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    $exists = $res && mysqli_num_rows($res) > 0;
    $cache[$table_name] = $exists;

    return $exists;
}

function financeColumnExists($conn, $table_name, $column_name) {
    static $cache = [];

    $table_name = strtolower(trim((string)$table_name));
    $column_name = strtolower(trim((string)$column_name));
    if ($table_name === '' || $column_name === '') {
        return false;
    }

    $key = $table_name . ':' . $column_name;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!preg_match('/^[a-z0-9_]+$/', $table_name)) {
        $cache[$key] = false;
        return false;
    }

    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table_name}` LIKE '{$safe_column}'");
    $exists = $res && mysqli_num_rows($res) > 0;
    $cache[$key] = $exists;

    return $exists;
}

function financeParseEnumValues($column_type) {
    $column_type = trim((string)$column_type);
    if (stripos($column_type, 'enum(') !== 0) {
        return [];
    }

    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $column_type, $matches)) {
        return [];
    }

    return array_map(static function ($value) {
        return stripslashes((string)$value);
    }, $matches[1]);
}

function financeResolvePayrollDecisionStatuses($conn) {
    static $resolved_map = null;
    if ($resolved_map !== null) {
        return $resolved_map;
    }

    $resolved_map = [
        'approve' => 'approved',
        'reject' => 'rejected'
    ];

    if (!financeTableExists($conn, 'payroll') || !financeColumnExists($conn, 'payroll', 'status')) {
        return $resolved_map;
    }

    $status_col_res = mysqli_query($conn, "SHOW COLUMNS FROM payroll LIKE 'status'");
    $status_col = $status_col_res ? mysqli_fetch_assoc($status_col_res) : null;
    $enum_values = financeParseEnumValues($status_col['Type'] ?? '');
    if (empty($enum_values)) {
        return $resolved_map;
    }

    $value_map = [];
    foreach ($enum_values as $enum_value) {
        $value_map[strtolower($enum_value)] = $enum_value;
    }

    $approve_candidates = ['approved', 'processed', 'paid'];
    $reject_candidates = ['rejected', 'cancelled', 'declined'];

    $resolved_map['approve'] = '';
    foreach ($approve_candidates as $candidate) {
        if (isset($value_map[$candidate])) {
            $resolved_map['approve'] = $value_map[$candidate];
            break;
        }
    }

    $resolved_map['reject'] = '';
    foreach ($reject_candidates as $candidate) {
        if (isset($value_map[$candidate])) {
            $resolved_map['reject'] = $value_map[$candidate];
            break;
        }
    }

    return $resolved_map;
}

function ensurePayrollDecisionSchema($conn) {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    if (!financeTableExists($conn, 'payroll')) {
        return false;
    }

    if (!financeColumnExists($conn, 'payroll', 'status')) {
        if (!mysqli_query($conn, "ALTER TABLE payroll ADD COLUMN status VARCHAR(40) DEFAULT 'pending' AFTER payment_proof_path")) {
            return false;
        }
    }

    $status_col_res = mysqli_query($conn, "SHOW COLUMNS FROM payroll LIKE 'status'");
    $status_col = $status_col_res ? mysqli_fetch_assoc($status_col_res) : null;
    $enum_values = financeParseEnumValues($status_col['Type'] ?? '');
    if (!empty($enum_values)) {
        $normalized = array_map('strtolower', $enum_values);
        $required_values = ['pending', 'approved', 'rejected'];
        $updated_values = $enum_values;
        $has_changes = false;

        foreach ($required_values as $required) {
            if (!in_array($required, $normalized, true)) {
                $updated_values[] = $required;
                $has_changes = true;
            }
        }

        if ($has_changes) {
            $escaped_values = [];
            foreach ($updated_values as $status_value) {
                $escaped_values[] = "'" . mysqli_real_escape_string($conn, (string)$status_value) . "'";
            }
            $status_default = (string)($status_col['Default'] ?? '');
            if ($status_default === '' || !in_array($status_default, $updated_values, true)) {
                $status_default = 'pending';
            }
            $null_sql = strtoupper((string)($status_col['Null'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
            $default_sql = "DEFAULT '" . mysqli_real_escape_string($conn, $status_default) . "'";

            $modify_sql = "ALTER TABLE payroll MODIFY COLUMN status ENUM(" . implode(',', $escaped_values) . ") {$null_sql} {$default_sql}";
            if (!mysqli_query($conn, $modify_sql)) {
                return false;
            }
        }
    }

    $alter_map = [
        'approved_by' => "ALTER TABLE payroll ADD COLUMN approved_by INT(11) DEFAULT NULL COMMENT 'ID of admin who approved/rejected' AFTER status",
        'approved_at' => "ALTER TABLE payroll ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by",
        'rejection_reason' => "ALTER TABLE payroll ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approved_at",
        'notes' => "ALTER TABLE payroll ADD COLUMN notes TEXT DEFAULT NULL AFTER rejection_reason"
    ];

    foreach ($alter_map as $column_name => $alter_sql) {
        if (financeColumnExists($conn, 'payroll', $column_name)) {
            continue;
        }
        if (!mysqli_query($conn, $alter_sql)) {
            return false;
        }
    }

    $initialized = true;
    return true;
}

function ensurePayrollExpenseColumns($conn) {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    if (!financeTableExists($conn, 'expenses')) {
        return false;
    }

    $alter_map = [
        'status' => "ALTER TABLE expenses ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER receipt_image",
        'recorded_by' => "ALTER TABLE expenses ADD COLUMN recorded_by INT(11) DEFAULT NULL AFTER expense_date"
    ];

    foreach ($alter_map as $column_name => $alter_sql) {
        if (financeColumnExists($conn, 'expenses', $column_name)) {
            continue;
        }
        if (!mysqli_query($conn, $alter_sql)) {
            return false;
        }
    }

    $initialized = true;
    return true;
}

function financeFormatPayoutChannelLabel($channel) {
    $channel = strtolower(trim((string)$channel));
    $map = [
        'bank_transfer' => 'Bank Transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'cash_pickup' => 'Cash Pickup',
        'card_reversal' => 'Card Reversal',
        'other' => 'Other'
    ];
    return $map[$channel] ?? ucwords(str_replace('_', ' ', $channel));
}

function financeIniSizeToBytes($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $bytes = (int)$value;
    switch ($unit) {
        case 'g':
            $bytes *= 1024;
            // no break
        case 'm':
            $bytes *= 1024;
            // no break
        case 'k':
            $bytes *= 1024;
            break;
    }
    return max(0, $bytes);
}

function financeGetUploadLimits() {
    static $limits = null;
    if ($limits !== null) {
        return $limits;
    }

    $default_single = 5 * 1024 * 1024;
    $upload_max = financeIniSizeToBytes(ini_get('upload_max_filesize'));
    $post_max = financeIniSizeToBytes(ini_get('post_max_size'));
    $memory_limit = financeIniSizeToBytes(ini_get('memory_limit'));

    if ($upload_max <= 0) {
        $upload_max = $default_single;
    }
    if ($post_max <= 0) {
        $post_max = 8 * 1024 * 1024;
    }

    $single_file_limit = min($default_single, $upload_max, (int)floor($post_max * 0.45));
    $single_file_limit = max(1024 * 1024, $single_file_limit);
    $total_limit = max(2 * 1024 * 1024, $post_max - (512 * 1024));

    $limits = [
        'single_file' => $single_file_limit,
        'total' => $total_limit,
        'upload_max' => $upload_max,
        'post_max' => $post_max,
        'memory_limit' => $memory_limit
    ];
    return $limits;
}

function financeUploadRefundImage($file, $prefix) {
    return financeUploadFinanceImage($file, $prefix, 'refund_payouts');
}

function financeUploadSignatureImage($file, $prefix) {
    return financeUploadFinanceImage($file, $prefix, 'finance_signatures');
}

function financeUploadFinanceImage($file, $prefix, $subdir = 'refund_payouts') {
    $upload_limits = financeGetUploadLimits();
    $validation = validateFileUpload($file, ['image/jpeg', 'image/png', 'image/webp'], $upload_limits['single_file']);
    if (empty($validation['valid'])) {
        throw new Exception($validation['errors'][0] ?? 'Invalid file upload.');
    }

    $safe_subdir = trim(str_replace(['..', '\\'], ['', '/'], (string)$subdir), '/');
    if ($safe_subdir === '') {
        $safe_subdir = 'refund_payouts';
    }

    $upload_dir = __DIR__ . '/../uploads/' . $safe_subdir . '/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        throw new Exception('Unable to prepare upload directory for refund files.');
    }

    $mime = (string)($validation['mime_type'] ?? '');
    $ext_map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $extension = $ext_map[$mime] ?? 'jpg';
    $file_name = sprintf('%s_%s_%s.%s', $prefix, date('YmdHis'), bin2hex(random_bytes(5)), $extension);
    $target_path = $upload_dir . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception('Failed to save uploaded file.');
    }

    return 'uploads/' . $safe_subdir . '/' . $file_name;
}

function financeProcessRefundAction($conn, $refund_id, $action, $admin_user_id, $remarks = '', $payout_data = [], $seller_scope_id = null) {
    $allowed_actions = ['approve_refund', 'reject_refund', 'complete_refund'];
    if (!in_array($action, $allowed_actions, true)) {
        $_SESSION['error'] = "Invalid refund action.";
        return false;
    }

    if ($action === 'reject_refund' && $remarks === '') {
        $_SESSION['error'] = "Please provide a reason when rejecting a refund.";
        return false;
    }

    if (!ensureRefundPayoutColumns($conn)) {
        $_SESSION['error'] = "Refund payout setup is unavailable. Please contact administrator.";
        return false;
    }

    $payout_channel = trim((string)($payout_data['channel'] ?? ''));
    $payout_reference = trim((string)($payout_data['reference'] ?? ''));
    $payout_account_name = trim((string)($payout_data['account_name'] ?? ''));
    $payout_account_masked = trim((string)($payout_data['account_masked'] ?? ''));
    $payout_proof = trim((string)($payout_data['proof'] ?? ''));
    $payout_finance_signature = trim((string)($payout_data['finance_signature'] ?? ''));

    $payout_channel = function_exists('mb_substr') ? mb_substr($payout_channel, 0, 40) : substr($payout_channel, 0, 40);
    $payout_reference = function_exists('mb_substr') ? mb_substr($payout_reference, 0, 120) : substr($payout_reference, 0, 120);
    $payout_account_name = function_exists('mb_substr') ? mb_substr($payout_account_name, 0, 120) : substr($payout_account_name, 0, 120);
    $payout_account_masked = function_exists('mb_substr') ? mb_substr($payout_account_masked, 0, 80) : substr($payout_account_masked, 0, 80);
    $payout_proof = function_exists('mb_substr') ? mb_substr($payout_proof, 0, 255) : substr($payout_proof, 0, 255);
    $payout_finance_signature = function_exists('mb_substr') ? mb_substr($payout_finance_signature, 0, 255) : substr($payout_finance_signature, 0, 255);

    mysqli_begin_transaction($conn);
    try {
        $partner_scope_join = '';
        $partner_scope_where = '';
        if ($seller_scope_id !== null) {
            $partner_scope_join = " JOIN orders o ON o.id = c.order_id ";
            $partner_scope_where = " AND EXISTS (
                SELECT 1
                FROM order_items oi_scope
                INNER JOIN products p_scope
                    ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
                WHERE oi_scope.order_id = o.id
                  AND p_scope.seller_id = " . (int)$seller_scope_id . "
            )";
        }
        $info_query = "SELECT r.id, r.refund_status, c.user_id, c.order_id, c.reservation_id, c.service_request_id
                       FROM refunds r
                       JOIN cancellations c ON r.cancellation_id = c.id
                       {$partner_scope_join}
                       WHERE r.id = ? {$partner_scope_where}
                       FOR UPDATE";
        $info_stmt = mysqli_prepare($conn, $info_query);
        mysqli_stmt_bind_param($info_stmt, "i", $refund_id);
        mysqli_stmt_execute($info_stmt);
        $info_res = mysqli_stmt_get_result($info_stmt);
        $info = mysqli_fetch_assoc($info_res);
        mysqli_stmt_close($info_stmt);

        if (!$info) {
            throw new Exception("Refund request not found.");
        }

        $current_status = $info['refund_status'];
        if ($action === 'approve_refund' && $current_status !== 'Refund Pending') {
            throw new Exception("Only pending refunds can be approved.");
        }
        if ($action === 'reject_refund' && !in_array($current_status, ['Refund Pending', 'Refund Approved'], true)) {
            throw new Exception("This refund cannot be rejected in its current state.");
        }
        if ($action === 'complete_refund' && $current_status !== 'Refund Approved') {
            throw new Exception("Only approved refunds can be marked as completed.");
        }
        if ($action === 'complete_refund') {
            if ($payout_channel === '' || $payout_reference === '') {
                throw new Exception("Payout channel and transaction reference are required to complete refund.");
            }
            if ($payout_proof === '' || $payout_finance_signature === '') {
                throw new Exception("Proof of transaction photo and finance signature photo are required.");
            }
            $allowed_channels = ['bank_transfer', 'gcash', 'maya', 'cash_pickup', 'card_reversal', 'other'];
            if (!in_array($payout_channel, $allowed_channels, true)) {
                throw new Exception("Invalid payout channel selected.");
            }
        }

        $new_status = $action === 'approve_refund'
            ? 'Refund Approved'
            : ($action === 'reject_refund' ? 'Refund Rejected' : 'Refund Completed');

        if ($action === 'complete_refund') {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE refunds
                 SET refund_status = ?, processed_by = ?, processed_date = NOW(), remarks = ?,
                     payout_channel = ?, payout_reference = ?, payout_account_name = ?, payout_account_masked = ?,
                     payout_proof = ?, payout_finance_signature = ?, payout_sent_at = NOW()
                 WHERE id = ?"
            );
            mysqli_stmt_bind_param(
                $stmt,
                "sisssssssi",
                $new_status,
                $admin_user_id,
                $remarks,
                $payout_channel,
                $payout_reference,
                $payout_account_name,
                $payout_account_masked,
                $payout_proof,
                $payout_finance_signature,
                $refund_id
            );
        } elseif ($action === 'reject_refund') {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE refunds
                 SET refund_status = ?, processed_by = ?, processed_date = NOW(), remarks = ?,
                     payout_channel = NULL, payout_reference = NULL, payout_account_name = NULL, payout_account_masked = NULL,
                     payout_proof = NULL, payout_finance_signature = NULL, payout_sent_at = NULL
                 WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "sisi", $new_status, $admin_user_id, $remarks, $refund_id);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE refunds SET refund_status = ?, processed_by = ?, processed_date = NOW(), remarks = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sisi", $new_status, $admin_user_id, $remarks, $refund_id);
        }
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to update refund status.");
        }
        mysqli_stmt_close($stmt);

        if (!empty($info['order_id']) && in_array($new_status, ['Refund Approved', 'Refund Completed'], true)) {
            $order_id = intval($info['order_id']);
            $stmt = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'cancelled' WHERE id = ? AND payment_status IN ('paid','partial','pending')");
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($info['user_id']) {
            $notif_title = "Refund Update";
            $ref_id = $info['order_id'] ?: ($info['reservation_id'] ?: ($info['service_request_id'] ?: 0));
            $ref_type = $info['order_id'] ? 'Order' : ($info['reservation_id'] ? 'Pre-Order' : 'Service Request');
            $ref_type_link = $info['order_id'] ? 'order' : ($info['reservation_id'] ? 'pre_order' : 'service_request');
            $notif_msg = "Your refund request for $ref_type #" . $ref_id . " is now " . strtoupper(str_replace('Refund ', '', $new_status)) . ".";
            if ($new_status === 'Refund Completed' && $payout_channel !== '' && $payout_reference !== '') {
                $notif_msg .= " Payout via " . financeFormatPayoutChannelLabel($payout_channel) . " (Ref: " . $payout_reference . ").";
            }
            if ($remarks !== '') {
                $notif_msg .= " Remarks: " . $remarks;
            }
            createNotification($conn, $info['user_id'], 'refund_update', $notif_title, $notif_msg, $ref_id, $ref_type_link);
        }

        mysqli_commit($conn);
        $_SESSION['success'] = "Refund request updated successfully.";
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Refund decision failed: " . $e->getMessage();
        return false;
    }
}

function financeApproveCancellation($conn, $cancellation_id, $details) {
    if (!in_array($details['order_status'], ['pending', 'confirmed', 'cancellation_requested'], true)) {
        $_SESSION['error'] = "Cannot approve cancellation. Order is already in process.";
        return false;
    }

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "UPDATE cancellations SET status = 'Cancelled', rejection_reason = NULL WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $cancellation_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE orders SET status = 'cancelled' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $details['order_id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (in_array($details['payment_status'], ['paid', 'partial'], true)) {
            $check_stmt = mysqli_prepare($conn, "SELECT id FROM refunds WHERE cancellation_id = ? LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, "i", $cancellation_id);
            mysqli_stmt_execute($check_stmt);
            $existing_refund = mysqli_stmt_get_result($check_stmt)->fetch_assoc();
            mysqli_stmt_close($check_stmt);

            if (!$existing_refund) {
                $stmt = mysqli_prepare($conn, "INSERT INTO refunds (cancellation_id, refund_amount, refund_status) VALUES (?, ?, 'Refund Pending')");
                mysqli_stmt_bind_param($stmt, "id", $cancellation_id, $details['total_amount']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $stmt = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'cancelled' WHERE id = ? AND payment_status IN ('paid','partial','pending')");
            mysqli_stmt_bind_param($stmt, "i", $details['order_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        mysqli_commit($conn);
        $_SESSION['success'] = "Cancellation approved. Refund routing has been updated if payment exists.";

        if (!empty($details['user_id']) && function_exists('createNotification')) {
            $notif_title = "Cancellation Approved";
            $notif_msg = "Your cancellation request for Order #" . $details['order_number'] . " has been approved.";
            createNotification($conn, (int)$details['user_id'], 'cancellation_update', $notif_title, $notif_msg, $details['order_id'], 'order');
        }

        try {
            $emailService = new EmailService($conn);
            $emailService->sendCancellationStatusUpdate(
                $details['email'],
                $details['full_name'],
                $details['order_number'],
                'approved'
            );
        } catch (Exception $e) {
            error_log("Finance cancellation approval email failed for order #{$details['order_id']}: " . $e->getMessage());
        }
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Cancellation approval failed: " . $e->getMessage();
        return false;
    }
}

function financeRejectCancellation($conn, $cancellation_id, $details, $rejection_reason) {
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "UPDATE cancellations SET status = 'Rejected', rejection_reason = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $rejection_reason, $cancellation_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE orders SET status = 'confirmed' WHERE id = ? AND status = 'cancellation_requested'");
        mysqli_stmt_bind_param($stmt, "i", $details['order_id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        $_SESSION['success'] = "Cancellation rejected.";

        if (!empty($details['user_id']) && function_exists('createNotification')) {
            $notif_title = "Cancellation Rejected";
            $notif_msg = "Your cancellation request for Order #" . $details['order_number'] . " was rejected. Reason: " . $rejection_reason;
            createNotification($conn, (int)$details['user_id'], 'cancellation_update', $notif_title, $notif_msg, $details['order_id'], 'order');
        }

        try {
            $emailService = new EmailService($conn);
            $emailService->sendCancellationStatusUpdate(
                $details['email'],
                $details['full_name'],
                $details['order_number'],
                'rejected',
                $rejection_reason
            );
        } catch (Exception $e) {
            error_log("Finance cancellation rejection email failed for order #{$details['order_id']}: " . $e->getMessage());
        }
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Cancellation rejection failed: " . $e->getMessage();
        return false;
    }
}

if (!ensureRefundPayoutColumns($conn)) {
    error_log('Finance: unable to ensure refund payout columns at bootstrap.');
}
if (!ensureFinanceSignatureAuditTable($conn)) {
    error_log('Finance: unable to ensure signature audit table at bootstrap.');
}

function financeIsAjaxRequest() {
    $requested_with = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept_header = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requested_with === 'xmlhttprequest' || strpos($accept_header, 'application/json') !== false;
}

function financeDecisionRespondAndExit($is_ajax, $redirect_url = "finance.php#decision-center") {
    if ($is_ajax) {
        $success_msg = trim((string)($_SESSION['success'] ?? ''));
        $error_msg = trim((string)($_SESSION['error'] ?? ''));
        $ok = $success_msg !== '' && $error_msg === '';
        $message = $ok ? $success_msg : ($error_msg !== '' ? $error_msg : 'Request failed.');

        unset($_SESSION['success'], $_SESSION['error']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $ok,
            'message' => $message
        ]);
        exit();
    }

    header("Location: {$redirect_url}");
    exit();
}

function financeBytesToHuman($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024 * 1024), 1) . 'GB';
    }
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . 'MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . 'KB';
    }
    return $bytes . 'B';
}

$is_ajax_request = financeIsAjaxRequest();
$incoming_finance_action = trim((string)($_POST['finance_decision_action'] ?? $_GET['finance_decision_action'] ?? ''));

// Handle oversized multipart requests where PHP drops $_POST/$_FILES before normal action handling.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax_request && strtolower((string)($_SERVER['HTTP_X_FINANCE_ACTION'] ?? '')) === 'complete_refund' && $incoming_finance_action === '') {
    $limits = financeGetUploadLimits();
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max_total_human = financeBytesToHuman($limits['total']);
    $msg = $content_length > 0
        ? "Upload payload is too large for server limits. Please use smaller files (combined around {$max_total_human} or less)."
        : 'Request payload is missing. Please try again.';

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incoming_finance_action !== '') {
    if ($incoming_finance_action === 'complete_refund' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $_SESSION['error'] = 'Upload payload is too large for server limits. Please upload smaller files and try again.';
        financeDecisionRespondAndExit($is_ajax_request);
    }

    financeEnforceManageAccess($can_manage_finance, $is_ajax_request);
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        financeDecisionRespondAndExit($is_ajax_request);
    }

    $decision_action = $incoming_finance_action;
    $decision_notes = trim($_POST['decision_notes'] ?? '');
    $decision_notes = function_exists('mb_substr') ? mb_substr($decision_notes, 0, 500) : substr($decision_notes, 0, 500);
    $decision_signature = trim((string)($_POST['decision_signature'] ?? 'image_signature'));
    $admin_id = $admin_user_id;
    $signed_by_name = $expected_admin_signature !== '' ? $expected_admin_signature : 'Finance Admin';
    $decision_signature_image_path = '';
    $decision_signature_actions = ['approve_refund', 'reject_refund', 'approve_cancellation', 'reject_cancellation'];

    if (in_array($decision_action, $decision_signature_actions, true)) {
        try {
            $decision_sig_file = $_FILES['decision_signature_file'] ?? null;
            if (!$decision_sig_file || (int)($decision_sig_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Finance signature image is required for approve/reject actions.');
            }
            $decision_signature_image_path = financeUploadSignatureImage($decision_sig_file, 'finance_decision_' . $decision_action . '_' . $admin_id);
        } catch (Exception $sigEx) {
            $_SESSION['error'] = $sigEx->getMessage();
            financeDecisionRespondAndExit($is_ajax_request);
        }
    }

    $ok = false;
    if (in_array($decision_action, ['approve_refund', 'reject_refund', 'complete_refund'], true)) {
        $refund_id = intval($_POST['refund_id'] ?? 0);
        $payout_data = [
            'channel' => trim((string)($_POST['payout_channel'] ?? '')),
            'reference' => trim((string)($_POST['payout_reference'] ?? '')),
            'account_name' => trim((string)($_POST['payout_account_name'] ?? '')),
            'account_masked' => trim((string)($_POST['payout_account_masked'] ?? '')),
            'proof' => trim((string)($_POST['payout_proof'] ?? '')),
            'finance_signature' => trim((string)($_POST['payout_finance_signature'] ?? ''))
        ];
        if ($refund_id <= 0) {
            $_SESSION['error'] = 'Invalid refund request.';
        } else {
            if ($decision_action === 'complete_refund') {
                $uploaded_paths = [];
                try {
                    $upload_limits = financeGetUploadLimits();
                    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                    if ($content_length > $upload_limits['post_max'] && $upload_limits['post_max'] > 0) {
                        throw new Exception('Upload payload exceeded server POST limit. Please upload smaller files.');
                    }

                    $proof_file = $_FILES['payout_proof_file'] ?? null;
                    $signature_file = $_FILES['finance_signature_file'] ?? null;

                    if (!$proof_file || (int)($proof_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        throw new Exception('Please upload the refund transaction proof photo.');
                    }
                    if (!$signature_file || (int)($signature_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        throw new Exception('Please upload the finance signature photo.');
                    }
                    $combined_size = (int)($proof_file['size'] ?? 0) + (int)($signature_file['size'] ?? 0);
                    if ($combined_size > $upload_limits['total']) {
                        throw new Exception('Combined upload is too large. Please reduce file sizes and try again.');
                    }

                    $payout_data['proof'] = financeUploadRefundImage($proof_file, 'refund_proof_' . $refund_id);
                    $uploaded_paths[] = $payout_data['proof'];
                    $payout_data['finance_signature'] = financeUploadRefundImage($signature_file, 'finance_signature_' . $refund_id);
                    $uploaded_paths[] = $payout_data['finance_signature'];
                } catch (Exception $uploadEx) {
                    foreach ($uploaded_paths as $saved_path) {
                        $full_path = __DIR__ . '/../' . ltrim((string)$saved_path, '/\\');
                        if (is_file($full_path)) {
                            @unlink($full_path);
                        }
                    }
                    $_SESSION['error'] = $uploadEx->getMessage();
                    financeDecisionRespondAndExit($is_ajax_request);
                }
            }

            $ok = financeProcessRefundAction($conn, $refund_id, $decision_action, $admin_id, $decision_notes, $payout_data, $seller_scope_id);
            if ($ok && in_array($decision_action, ['approve_refund', 'reject_refund'], true)) {
                $action_type = strpos($decision_action, 'approve_') === 0 ? 'approve' : 'reject';
                financeWriteSignatureAudit(
                    $conn,
                    $admin_id,
                    $signed_by_name,
                    $decision_signature,
                    $decision_action,
                    $action_type,
                    'refund',
                    $refund_id,
                    $decision_notes,
                    $decision_signature_image_path
                );
            }
        }
    } elseif (in_array($decision_action, ['approve_cancellation', 'reject_cancellation'], true)) {
        $cancellation_id = intval($_POST['cancellation_id'] ?? 0);
        if ($cancellation_id <= 0) {
            $_SESSION['error'] = 'Invalid cancellation request.';
        } else {
            $details_sql = "SELECT c.order_id, c.user_id, o.status AS order_status, o.total_amount, o.payment_status, o.order_number, u.email, u.full_name
                            FROM cancellations c
                            JOIN orders o ON c.order_id = o.id
                            JOIN users u ON c.user_id = u.id
                            WHERE c.id = ?";
            if ($seller_scope_id !== null) {
                $details_sql .= " AND EXISTS (
                    SELECT 1
                    FROM order_items oi_scope
                    INNER JOIN products p_scope
                        ON (oi_scope.product_id = p_scope.product_id OR oi_scope.product_id = CAST(p_scope.id AS CHAR))
                    WHERE oi_scope.order_id = o.id
                      AND p_scope.seller_id = ?
                )";
            }
            $details_stmt = mysqli_prepare($conn, $details_sql);
            if ($seller_scope_id !== null) {
                mysqli_stmt_bind_param($details_stmt, "ii", $cancellation_id, $seller_scope_id);
            } else {
                mysqli_stmt_bind_param($details_stmt, "i", $cancellation_id);
            }
            mysqli_stmt_execute($details_stmt);
            $details_result = mysqli_stmt_get_result($details_stmt);
            $details = mysqli_fetch_assoc($details_result);
            mysqli_stmt_close($details_stmt);

            if (!$details) {
                $_SESSION['error'] = 'Cancellation request not found.';
            } elseif ($decision_action === 'approve_cancellation') {
                $ok = financeApproveCancellation($conn, $cancellation_id, $details);
                if ($ok) {
                    financeWriteSignatureAudit(
                        $conn,
                        $admin_id,
                        $signed_by_name,
                        $decision_signature,
                        $decision_action,
                        'approve',
                        'cancellation',
                        $cancellation_id,
                        $decision_notes,
                        $decision_signature_image_path
                    );
                }
            } else {
                if ($decision_notes === '') {
                    $decision_notes = 'No reason provided.';
                }
                $ok = financeRejectCancellation($conn, $cancellation_id, $details, $decision_notes);
                if ($ok) {
                    financeWriteSignatureAudit(
                        $conn,
                        $admin_id,
                        $signed_by_name,
                        $decision_signature,
                        $decision_action,
                        'reject',
                        'cancellation',
                        $cancellation_id,
                        $decision_notes,
                        $decision_signature_image_path
                    );
                }
            }
        }
    } else {
        $_SESSION['error'] = 'Invalid finance decision action.';
    }

    if (!$ok && $decision_signature_image_path !== '') {
        $sig_full_path = __DIR__ . '/../' . ltrim($decision_signature_image_path, '/\\');
        if (is_file($sig_full_path)) {
            @unlink($sig_full_path);
        }
    }

    financeDecisionRespondAndExit($is_ajax_request);
}

function financeNormalizeIdList($raw_ids) {
    $candidates = [];
    if (is_array($raw_ids)) {
        $candidates = $raw_ids;
    } elseif ($raw_ids !== null && $raw_ids !== '') {
        $candidates = preg_split('/[,\s]+/', (string)$raw_ids, -1, PREG_SPLIT_NO_EMPTY);
    }

    $unique = [];
    foreach ($candidates as $candidate) {
        $id = (int)$candidate;
        if ($id > 0) {
            $unique[$id] = $id;
        }
    }
    return array_values($unique);
}

function financeProcessPayrollDecision($conn, $payroll_id, $action, $admin_id, $payroll_signature, $payroll_signature_path, $signed_by_name, $decision_notes = '') {
    $payroll_id = (int)$payroll_id;
    $action = strtolower(trim((string)$action));
    $decision_notes = trim((string)$decision_notes);
    $decision_notes = function_exists('mb_substr') ? mb_substr($decision_notes, 0, 500) : substr($decision_notes, 0, 500);

    if ($payroll_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid payroll record id.'];
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        return ['ok' => false, 'message' => 'Invalid payroll action.'];
    }
    if ($action === 'reject' && $decision_notes === '') {
        $decision_notes = 'No reason provided.';
    }
    if (!ensurePayrollDecisionSchema($conn)) {
        return ['ok' => false, 'message' => 'Payroll decision schema setup failed. Please run database migration and try again.'];
    }

    $status_map = financeResolvePayrollDecisionStatuses($conn);
    $approved_status = trim((string)($status_map['approve'] ?? ''));
    $rejected_status = trim((string)($status_map['reject'] ?? ''));
    if ($action === 'approve' && $approved_status === '') {
        return ['ok' => false, 'message' => 'Payroll status configuration does not support approval.'];
    }
    if ($action === 'reject' && $rejected_status === '') {
        return ['ok' => false, 'message' => 'Payroll status configuration does not support rejection.'];
    }

    mysqli_begin_transaction($conn);
    try {
        $pr_stmt = mysqli_prepare($conn, "SELECT p.*, e.first_name, e.last_name, e.employee_id as emp_code, e.user_id FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.id = ? FOR UPDATE");
        if (!$pr_stmt) {
            throw new Exception('Unable to prepare payroll query.');
        }
        mysqli_stmt_bind_param($pr_stmt, "i", $payroll_id);
        if (!mysqli_stmt_execute($pr_stmt)) {
            throw new Exception('Failed to load payroll record.');
        }
        $payroll_details = mysqli_fetch_assoc(mysqli_stmt_get_result($pr_stmt));
        mysqli_stmt_close($pr_stmt);

        if (!$payroll_details) {
            throw new Exception('Payroll record not found.');
        }

        if (function_exists('hrCanManageEmployeeUserIdInScope')
            && !hrCanManageEmployeeUserIdInScope($conn, (int)($payroll_details['user_id'] ?? 0))) {
            throw new Exception('This payroll record is outside your finance scope.');
        }

        $current_status = strtolower(trim((string)($payroll_details['status'] ?? '')));
        $pending_statuses = ['', 'pending', 'submitted', 'for_approval', 'for approval'];
        if (!in_array($current_status, $pending_statuses, true)) {
            throw new Exception('Payroll #' . $payroll_id . ' is already ' . ($current_status !== '' ? $current_status : 'processed') . '.');
        }

        if ($action === 'approve') {
            $approval_note = $decision_notes !== '' ? $decision_notes : 'Approved in finance module.';
            $update_stmt = mysqli_prepare($conn, "UPDATE payroll SET status = ?, rejection_reason = NULL, notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Unable to prepare payroll approval update.');
            }
            mysqli_stmt_bind_param($update_stmt, "ssii", $approved_status, $approval_note, $admin_id, $payroll_id);
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception('Failed to mark payroll as approved.');
            }
            mysqli_stmt_close($update_stmt);

            $payslip_id = 0;
            $existing_payslip_stmt = mysqli_prepare($conn, "SELECT id FROM payslips WHERE payroll_id = ? LIMIT 1");
            if ($existing_payslip_stmt) {
                mysqli_stmt_bind_param($existing_payslip_stmt, "i", $payroll_id);
                mysqli_stmt_execute($existing_payslip_stmt);
                $existing_payslip = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_payslip_stmt));
                mysqli_stmt_close($existing_payslip_stmt);
                $payslip_id = (int)($existing_payslip['id'] ?? 0);
            }

            if ($payslip_id <= 0) {
                $payslip_number = 'PS-' . date('Ymd') . '-' . str_pad((string)($payroll_details['emp_code'] ?? $payroll_details['employee_id']), 4, '0', STR_PAD_LEFT) . '-' . $payroll_id;
                $sss = (float)$payroll_details['gross_pay'] * 0.045;
                $philhealth = (float)$payroll_details['gross_pay'] * 0.02;
                $pagibig = min((float)$payroll_details['gross_pay'] * 0.02, 100);
                $bir_tax = 0;

                $payslip_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO payslips (employee_id, payroll_id, payslip_number, pay_period_start, pay_period_end, base_salary, overtime_hours, overtime_pay, bonuses, gross_pay, sss_contribution, philhealth_contribution, pagibig_contribution, bir_tax, other_deductions, total_deductions, net_pay, status, generated_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'generated', NOW(), NOW())"
                );
                if (!$payslip_stmt) {
                    throw new Exception('Unable to prepare payslip generation.');
                }
                mysqli_stmt_bind_param(
                    $payslip_stmt,
                    "iisssdddddddddddd",
                    $payroll_details['employee_id'],
                    $payroll_id,
                    $payslip_number,
                    $payroll_details['pay_period_start'],
                    $payroll_details['pay_period_end'],
                    $payroll_details['base_salary'],
                    $payroll_details['overtime_hours'],
                    $payroll_details['overtime_pay'],
                    $payroll_details['bonuses'],
                    $payroll_details['gross_pay'],
                    $sss,
                    $philhealth,
                    $pagibig,
                    $bir_tax,
                    $payroll_details['late_deductions'],
                    $payroll_details['deductions'],
                    $payroll_details['net_pay']
                );
                if (!mysqli_stmt_execute($payslip_stmt)) {
                    throw new Exception('Failed to generate payslip.');
                }
                $payslip_id = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($payslip_stmt);
            }

            $expense_notice = '';
            if (financeTableExists($conn, 'expenses')) {
                if (!ensurePayrollExpenseColumns($conn)) {
                    throw new Exception('Unable to ensure expense schema for payroll approval.');
                }

                $expense_desc = "Payroll for " . $payroll_details['first_name'] . " " . $payroll_details['last_name'] . " (" . date('M d, Y', strtotime($payroll_details['pay_period_start'])) . " to " . date('M d, Y', strtotime($payroll_details['pay_period_end'])) . ")";
                $expense_amount = (float)$payroll_details['gross_pay'];
                $expense_date = date('Y-m-d');
                $exp_stmt = mysqli_prepare($conn, "INSERT INTO expenses (category, description, amount, expense_date, recorded_by, status) VALUES ('Payroll', ?, ?, ?, ?, 'approved')");
                if (!$exp_stmt) {
                    throw new Exception('Unable to prepare payroll expense recording.');
                }
                mysqli_stmt_bind_param($exp_stmt, "sdsi", $expense_desc, $expense_amount, $expense_date, $admin_id);
                if (!mysqli_stmt_execute($exp_stmt)) {
                    throw new Exception('Failed to record payroll expense.');
                }
                mysqli_stmt_close($exp_stmt);
            } else {
                $expense_notice = ' Expense entry was skipped because the expenses table is unavailable.';
                error_log('Finance payroll approval skipped expense record: missing expenses table for payroll #' . $payroll_id);
            }

            if (!empty($payroll_details['user_id']) && function_exists('createNotification')) {
                $notif_title = "Payslip Available";
                $notif_msg = "Your payslip for the period " . date('M d', strtotime($payroll_details['pay_period_start'])) . " - " . date('M d, Y', strtotime($payroll_details['pay_period_end'])) . " has been generated.";
                createNotification($conn, (int)$payroll_details['user_id'], 'payslip_generated', $notif_title, $notif_msg, $payslip_id > 0 ? $payslip_id : $payroll_id, 'payslip');
            }

            mysqli_commit($conn);
            financeWriteSignatureAudit(
                $conn,
                $admin_id,
                $signed_by_name,
                $payroll_signature,
                'approve_payroll',
                'approve',
                'payroll',
                $payroll_id,
                $approval_note,
                $payroll_signature_path
            );

            return ['ok' => true, 'message' => 'Payroll #' . $payroll_id . ' approved successfully.' . $expense_notice];
        }

        $rejection_reason = $decision_notes;
        $update_stmt = mysqli_prepare($conn, "UPDATE payroll SET status = ?, rejection_reason = ?, notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception('Unable to prepare payroll rejection update.');
        }
        mysqli_stmt_bind_param($update_stmt, "sssii", $rejected_status, $rejection_reason, $rejection_reason, $admin_id, $payroll_id);
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Failed to reject payroll.');
        }
        mysqli_stmt_close($update_stmt);

        if (!empty($payroll_details['user_id']) && function_exists('createNotification')) {
            $notif_title = "Payroll Rejected";
            $notif_msg = "Your payroll for " . date('M d', strtotime($payroll_details['pay_period_start'])) . " - " . date('M d, Y', strtotime($payroll_details['pay_period_end'])) . " was rejected. Reason: " . $rejection_reason;
            createNotification($conn, (int)$payroll_details['user_id'], 'payroll_rejected', $notif_title, $notif_msg, $payroll_id, 'payroll');
        }

        mysqli_commit($conn);
        financeWriteSignatureAudit(
            $conn,
            $admin_id,
            $signed_by_name,
            $payroll_signature,
            'reject_payroll',
            'reject',
            'payroll',
            $payroll_id,
            $rejection_reason,
            $payroll_signature_path
        );

        return ['ok' => true, 'message' => 'Payroll #' . $payroll_id . ' rejected successfully.'];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

// Handle Payroll Approval/Rejection (single + bulk)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['payroll_action']) || isset($_POST['bulk_payroll_action']))) {
    $is_payroll_ajax = financeIsAjaxRequest();
    financeEnforceManageAccess($can_manage_finance, $is_payroll_ajax);

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        financeDecisionRespondAndExit($is_payroll_ajax, 'finance.php');
    }

    $is_bulk_action = isset($_POST['bulk_payroll_action']);
    $action = trim((string)($is_bulk_action ? ($_POST['bulk_payroll_action'] ?? '') : ($_POST['payroll_action'] ?? '')));
    if (!in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['error'] = 'Invalid payroll action.';
        financeDecisionRespondAndExit($is_payroll_ajax, 'finance.php');
    }

    $payroll_signature = trim((string)($_POST['payroll_signature'] ?? 'image_signature'));
    $payroll_signature_path = '';
    try {
        $payroll_sig_file = $_FILES['payroll_signature_file'] ?? null;
        if (!$payroll_sig_file || (int)($payroll_sig_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Finance signature image is required for payroll approve/reject.');
        }
        $signature_target_id = $is_bulk_action ? 'bulk' : (string)((int)($_POST['payroll_id'] ?? 0));
        $payroll_signature_path = financeUploadSignatureImage($payroll_sig_file, 'payroll_' . $action . '_' . $signature_target_id . '_' . $admin_user_id);
    } catch (Exception $payrollSigEx) {
        $_SESSION['error'] = $payrollSigEx->getMessage();
        financeDecisionRespondAndExit($is_payroll_ajax, 'finance.php');
    }

    $admin_id = $admin_user_id;
    $signed_by_name = $expected_admin_signature !== '' ? $expected_admin_signature : $payroll_signature;
    $decision_notes = trim((string)($is_bulk_action ? ($_POST['bulk_rejection_reason'] ?? '') : ($_POST['payroll_notes'] ?? $_POST['rejection_reason'] ?? '')));
    $payroll_ids = $is_bulk_action
        ? financeNormalizeIdList($_POST['payroll_ids'] ?? [])
        : financeNormalizeIdList([(int)($_POST['payroll_id'] ?? 0)]);

    if (empty($payroll_ids)) {
        if ($payroll_signature_path !== '') {
            $sig_full_path = __DIR__ . '/../' . ltrim($payroll_signature_path, '/\\');
            if (is_file($sig_full_path)) {
                @unlink($sig_full_path);
            }
        }
        $_SESSION['error'] = 'No payroll records selected.';
        financeDecisionRespondAndExit($is_payroll_ajax, 'finance.php');
    }

    $success_count = 0;
    $failure_messages = [];
    foreach ($payroll_ids as $current_payroll_id) {
        if (function_exists('hrPayrollIdInScope') && !hrPayrollIdInScope($conn, (int)$current_payroll_id)) {
            $failure_messages[] = 'Payroll #' . (int)$current_payroll_id . ': Not allowed for your finance scope.';
            continue;
        }

        $result = financeProcessPayrollDecision(
            $conn,
            (int)$current_payroll_id,
            $action,
            $admin_id,
            $payroll_signature,
            $payroll_signature_path,
            $signed_by_name,
            $decision_notes
        );
        if (!empty($result['ok'])) {
            $success_count++;
        } else {
            $failure_messages[] = 'Payroll #' . (int)$current_payroll_id . ': ' . ($result['message'] ?? 'Failed to process payroll action.');
        }
    }

    if ($success_count <= 0 && $payroll_signature_path !== '') {
        $payroll_sig_full_path = __DIR__ . '/../' . ltrim($payroll_signature_path, '/\\');
        if (is_file($payroll_sig_full_path)) {
            @unlink($payroll_sig_full_path);
        }
    }

    $failure_count = count($failure_messages);
    if ($success_count > 0 && $failure_count === 0) {
        $_SESSION['success'] = $is_bulk_action
            ? "Bulk payroll {$action} completed for {$success_count} record(s)."
            : "Payroll {$action} action completed successfully.";
    } elseif ($success_count > 0) {
        $_SESSION['success'] = "Payroll {$action} completed for {$success_count} record(s). {$failure_count} record(s) skipped.";
    } else {
        $_SESSION['error'] = "Payroll {$action} failed. " . ($failure_messages[0] ?? 'Please try again.');
    }

    financeDecisionRespondAndExit($is_payroll_ajax, 'finance.php');
}

// Financial Calculations with Filters
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$current_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// --- OPTIMIZATION: Calculate date ranges once in PHP to make queries SARGable (able to use indexes) ---
$month_start_date = date('Y-m-01', strtotime("{$current_year}-{$current_month}-01"));
$next_month_start_date = date('Y-m-d', strtotime($month_start_date . ' +1 month'));
$month_end_date = date('Y-m-t', strtotime($month_start_date)); // For DATE columns

$year_start_date = "{$current_year}-01-01";
$next_year_start_date = ($current_year + 1) . "-01-01";

// 1. Total Revenue for the selected month
$revenue_query = "SELECT SUM(o.total_amount) as monthly_revenue FROM orders o WHERE o.status IN ('delivered', 'completed') AND o.is_archived = 0 AND o.created_at >= ? AND o.created_at < ?" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$stmt = mysqli_prepare($conn, $revenue_query);
mysqli_stmt_bind_param($stmt, "ss", $month_start_date, $next_month_start_date);
mysqli_stmt_execute($stmt);
$revenue_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// 2. Total Payroll Expenses for the selected month (tenant-scoped).
$payroll_result = ['monthly_payroll' => 0];
$payroll_query = "SELECT p.gross_pay, e.user_id
                  FROM payroll p
                  INNER JOIN employees e ON e.id = p.employee_id
                  WHERE p.pay_period_end BETWEEN ? AND ?";
$stmt = mysqli_prepare($conn, $payroll_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ss", $month_start_date, $month_end_date);
    mysqli_stmt_execute($stmt);
    $payroll_res = mysqli_stmt_get_result($stmt);
    $payroll_sum = 0.0;
    while ($payroll_res && ($payroll_row = mysqli_fetch_assoc($payroll_res))) {
        $can_include = true;
        if (function_exists('hrCanManageEmployeeUserIdInScope')) {
            $can_include = hrCanManageEmployeeUserIdInScope($conn, (int)($payroll_row['user_id'] ?? 0));
        }
        if ($can_include) {
            $payroll_sum += (float)($payroll_row['gross_pay'] ?? 0);
        }
    }
    mysqli_stmt_close($stmt);
    $payroll_result['monthly_payroll'] = $payroll_sum;
}

// 3. Operational Expenses for the selected month
$expenses_query = "SELECT SUM(amount) as monthly_ops FROM expenses WHERE expense_date >= ? AND expense_date < ?" . ($seller_scope_id !== null ? " AND recorded_by = ?" : "");
$stmt = mysqli_prepare($conn, $expenses_query);
if ($seller_scope_id !== null) {
    mysqli_stmt_bind_param($stmt, "ssi", $month_start_date, $next_month_start_date, $seller_scope_id);
} else {
    mysqli_stmt_bind_param($stmt, "ss", $month_start_date, $next_month_start_date);
}
mysqli_stmt_execute($stmt);
$expenses_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$monthly_revenue = $revenue_result['monthly_revenue'] ?? 0;
$monthly_payroll_expense = $payroll_result['monthly_payroll'] ?? 0;
$monthly_ops_expense = $expenses_result['monthly_ops'] ?? 0;
$monthly_expenses = $monthly_payroll_expense + $monthly_ops_expense;
$monthly_net = $monthly_revenue - $monthly_expenses;

// Monthly Trend Data for the selected year
$trend_query = "
    SELECT 
        MONTH(o.created_at) as m, 
        SUM(o.total_amount) as revenue 
    FROM orders o
    WHERE o.status IN ('delivered', 'completed') AND o.is_archived = 0 AND o.created_at >= ? AND o.created_at < ?" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "") . "
    GROUP BY MONTH(o.created_at)";
$stmt = mysqli_prepare($conn, $trend_query);
mysqli_stmt_bind_param($stmt, "ss", $year_start_date, $next_year_start_date);
mysqli_stmt_execute($stmt);
$trend_res = mysqli_stmt_get_result($stmt);
$monthly_data = array_fill(1, 12, 0);
while($row = mysqli_fetch_assoc($trend_res)) {
    $monthly_data[$row['m']] = $row['revenue'];
}
mysqli_stmt_close($stmt);

// Finance decision center metrics (real-life operations queue)
$pending_cancellations_count = 0;
$pending_refunds_count = 0;
$approved_refunds_count = 0;
$overdue_requests_count = 0;
$pending_payout_total = 0.0;

$metric_query = "SELECT COUNT(*) AS c
                 FROM cancellations c
                 INNER JOIN orders o ON c.order_id = o.id
                 WHERE c.status = 'Requested'" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$metric_res = mysqli_query($conn, $metric_query);
if ($metric_res) {
    $pending_cancellations_count = (int)(mysqli_fetch_assoc($metric_res)['c'] ?? 0);
}

$metric_query = "SELECT COUNT(*) AS c
                 FROM refunds r
                 INNER JOIN cancellations c ON r.cancellation_id = c.id
                 INNER JOIN orders o ON c.order_id = o.id
                 WHERE r.refund_status = 'Refund Pending'" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$metric_res = mysqli_query($conn, $metric_query);
if ($metric_res) {
    $pending_refunds_count = (int)(mysqli_fetch_assoc($metric_res)['c'] ?? 0);
}

$metric_query = "SELECT COUNT(*) AS c
                 FROM refunds r
                 INNER JOIN cancellations c ON r.cancellation_id = c.id
                 INNER JOIN orders o ON c.order_id = o.id
                 WHERE r.refund_status = 'Refund Approved'" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$metric_res = mysqli_query($conn, $metric_query);
if ($metric_res) {
    $approved_refunds_count = (int)(mysqli_fetch_assoc($metric_res)['c'] ?? 0);
}

$metric_query = "SELECT COALESCE(SUM(r.refund_amount), 0) AS total
                 FROM refunds r
                 INNER JOIN cancellations c ON r.cancellation_id = c.id
                 INNER JOIN orders o ON c.order_id = o.id
                 WHERE r.refund_status IN ('Refund Pending', 'Refund Approved')" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$metric_res = mysqli_query($conn, $metric_query);
if ($metric_res) {
    $pending_payout_total = (float)(mysqli_fetch_assoc($metric_res)['total'] ?? 0);
}

$metric_query = "SELECT COUNT(*) AS c
                 FROM cancellations c
                 INNER JOIN orders o ON c.order_id = o.id
                 WHERE c.status = 'Requested'
                   AND c.cancellation_date < DATE_SUB(NOW(), INTERVAL 48 HOUR)" . ($seller_scope_id !== null ? " AND {$partner_order_scope_exists_sql}" : "");
$metric_res = mysqli_query($conn, $metric_query);
if ($metric_res) {
    $overdue_requests_count = (int)(mysqli_fetch_assoc($metric_res)['c'] ?? 0);
}

$decision_status_filter = trim($_GET['decision_status'] ?? 'open');
$decision_search = trim($_GET['decision_search'] ?? '');
$decision_where = [];

if ($decision_status_filter === 'waiting_cancellation') {
    $decision_where[] = "c.status = 'Requested'";
} elseif ($decision_status_filter === 'waiting_refund') {
    $decision_where[] = "r.refund_status = 'Refund Pending'";
} elseif ($decision_status_filter === 'payout_queue') {
    $decision_where[] = "r.refund_status = 'Refund Approved'";
} elseif ($decision_status_filter === 'closed') {
    $decision_where[] = "(c.status = 'Rejected' OR r.refund_status IN ('Refund Rejected', 'Refund Completed'))";
} else {
    $decision_status_filter = 'open';
    $decision_where[] = "(c.status = 'Requested' OR r.refund_status IN ('Refund Pending', 'Refund Approved'))";
}

if ($decision_search !== '') {
    $safe_decision_search = mysqli_real_escape_string($conn, $decision_search);
    $decision_where[] = "(u.full_name LIKE '%{$safe_decision_search}%' OR o.order_number LIKE '%{$safe_decision_search}%' OR c.id LIKE '%{$safe_decision_search}%')";
}
if ($seller_scope_id !== null) {
    $decision_where[] = $partner_order_scope_exists_sql;
}

$decision_where_sql = !empty($decision_where) ? ('WHERE ' . implode(' AND ', $decision_where)) : '';
$decision_query = "
    SELECT
        c.id AS cancellation_id,
        c.cancellation_date,
        c.status AS cancellation_status,
        c.reason,
        c.other_reason_text,
        c.rejection_reason,
        c.order_id,
        c.reservation_id,
        c.service_request_id,
        u.full_name,
        o.order_number,
        o.status AS order_status,
        o.payment_status,
        o.total_amount,
        r.id AS refund_id,
        r.refund_amount,
        r.refund_status,
        r.refund_reason,
        r.customer_evidence_path,
        r.processed_date,
        r.payout_channel,
        r.payout_reference,
        r.payout_account_name,
        r.payout_account_masked,
        r.payout_proof,
        r.payout_finance_signature,
        r.payout_sent_at
    FROM cancellations c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN orders o ON c.order_id = o.id
    LEFT JOIN refunds r ON r.cancellation_id = c.id
    $decision_where_sql
    ORDER BY
        CASE
            WHEN c.status = 'Requested' THEN 1
            WHEN r.refund_status = 'Refund Pending' THEN 2
            WHEN r.refund_status = 'Refund Approved' THEN 3
            ELSE 4
        END,
        c.cancellation_date ASC
    LIMIT 25
";
$decision_result = mysqli_query($conn, $decision_query);

$signature_audit_rows = [];
$signature_audit_grouped = [
    'refund' => [],
    'cancellation' => [],
    'payroll' => []
];
$audit_entity_filter = strtolower(trim($_GET['audit_entity'] ?? 'all'));
if (!in_array($audit_entity_filter, ['all', 'refund', 'cancellation', 'payroll'], true)) {
    $audit_entity_filter = 'all';
}
$audit_action_filter = strtolower(trim($_GET['audit_action'] ?? 'all'));
if (!in_array($audit_action_filter, ['all', 'approve', 'reject'], true)) {
    $audit_action_filter = 'all';
}
$audit_search = trim($_GET['audit_search'] ?? '');
$audit_limit = 60;
$signature_audit_total = 0;

$signature_audit_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'finance_signature_audit_log'");
if ($signature_audit_table_check && mysqli_num_rows($signature_audit_table_check) > 0) {
    $audit_where = [];
    if ($audit_entity_filter !== 'all') {
        $safe_entity = mysqli_real_escape_string($conn, $audit_entity_filter);
        $audit_where[] = "fsa.entity_type = '{$safe_entity}'";
    }
    if ($audit_action_filter !== 'all') {
        $safe_action = mysqli_real_escape_string($conn, $audit_action_filter);
        $audit_where[] = "fsa.action_type = '{$safe_action}'";
    }
    if ($audit_search !== '') {
        $safe_audit_search = mysqli_real_escape_string($conn, $audit_search);
        $audit_where[] = "(u.full_name LIKE '%{$safe_audit_search}%' OR fsa.signed_by LIKE '%{$safe_audit_search}%' OR fsa.action_key LIKE '%{$safe_audit_search}%' OR CAST(fsa.entity_id AS CHAR) LIKE '%{$safe_audit_search}%')";
    }
    if ($seller_scope_id !== null) {
        $audit_where[] = "fsa.admin_user_id = " . (int)$admin_user_id;
    }

    $audit_where_sql = !empty($audit_where) ? ('WHERE ' . implode(' AND ', $audit_where)) : '';
    $audit_query = "
        SELECT
            fsa.id,
            fsa.admin_user_id,
            fsa.signed_by,
            fsa.action_key,
            fsa.action_type,
            fsa.entity_type,
            fsa.entity_id,
            fsa.decision_note,
            fsa.signature_image_path,
            fsa.signed_at,
            fsa.ip_address,
            u.full_name AS admin_name
        FROM finance_signature_audit_log fsa
        LEFT JOIN users u ON u.id = fsa.admin_user_id
        {$audit_where_sql}
        ORDER BY fsa.signed_at DESC, fsa.id DESC
        LIMIT {$audit_limit}
    ";
    $audit_result = mysqli_query($conn, $audit_query);
    if ($audit_result) {
        while ($audit_row = mysqli_fetch_assoc($audit_result)) {
            $signature_audit_rows[] = $audit_row;
            $entity_key = strtolower((string)($audit_row['entity_type'] ?? ''));
            if (isset($signature_audit_grouped[$entity_key])) {
                $signature_audit_grouped[$entity_key][] = $audit_row;
            }
        }
    }
}
$signature_audit_total = count($signature_audit_rows);

$audit_meta = [
    'refund' => ['label' => 'Refund Signatures', 'icon' => 'fa-rotate-left'],
    'cancellation' => ['label' => 'Cancellation Signatures', 'icon' => 'fa-ban'],
    'payroll' => ['label' => 'Payroll Signatures', 'icon' => 'fa-file-invoice-dollar']
];
$audit_base_params = [
    'month' => (int)$current_month,
    'year' => (int)$current_year
];
if ($decision_status_filter !== '') {
    $audit_base_params['decision_status'] = $decision_status_filter;
}
if ($decision_search !== '') {
    $audit_base_params['decision_search'] = $decision_search;
}
$audit_reset_url = 'finance.php?' . http_build_query($audit_base_params) . '#decision-center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1 {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin-right: 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .finance-scope-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
            border: 1px solid transparent;
        }

        .scope-badge-partner {
            background: #ecfeff;
            color: #0f766e;
            border-color: #99f6e4;
        }

        .scope-badge-global {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .finance-scope-hint {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 6px;
        }

        .payroll-header-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-mode .finance-scope-badge.scope-badge-partner {
            background: #134e4a;
            color: #ccfbf1;
            border-color: #0f766e;
        }

        body.dark-mode .finance-scope-badge.scope-badge-global {
            background: #1e3a8a;
            color: #dbeafe;
            border-color: #1d4ed8;
        }

        body.dark-mode .finance-scope-hint {
            color: #cbd5e1;
        }

        .payroll-bulk-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .payroll-bulk-info {
            font-size: 0.9rem;
            color: #374151;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-mode .payroll-bulk-toolbar {
            background: #1f2937;
            border-color: var(--border-color-dark);
        }

        body.dark-mode .payroll-bulk-info {
            color: #e5e7eb;
        }

        .finance-intro-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 18px;
            background:
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 30%),
                radial-gradient(circle at left bottom, rgba(16, 185, 129, 0.10), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        .finance-intro-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(300px, 0.9fr);
            gap: 18px;
            align-items: start;
        }

        .finance-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 10px;
        }

        .finance-intro-title {
            margin: 0 0 10px;
            font-size: 1.8rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.15;
        }

        .finance-intro-text {
            color: #475569;
            max-width: 900px;
            font-size: 0.95rem;
        }

        .finance-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .finance-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #ffffff;
            border: 1px solid #dbeafe;
            color: #1e3a8a;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .finance-workflow-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .finance-workflow-step {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            background: rgba(255,255,255,0.96);
        }

        .finance-workflow-step strong {
            display: block;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .finance-workflow-step span {
            color: #64748b;
            font-size: 0.88rem;
        }

        .finance-side-box {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            background: rgba(255,255,255,0.96);
            margin-bottom: 12px;
        }

        .finance-side-box strong {
            display: block;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .finance-side-box p {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
        }

        .finance-nav-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .finance-nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 12px 14px;
            border: 1px solid #dbeafe;
            border-radius: 14px;
            background: #fff;
            color: #1d4ed8;
            font-weight: 700;
            text-decoration: none;
        }

        .finance-nav-link:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .finance-section-note {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 6px;
            margin-bottom: 0;
        }

        .finance-filter-card .card-body {
            padding: 18px;
        }

        .finance-filter-title {
            font-size: 1rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }

        .finance-filter-note {
            color: #64748b;
            font-size: 0.88rem;
            margin-bottom: 14px;
        }

        .section-anchor-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .decision-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .decision-kpi-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            background: #fff;
            height: 100%;
        }

        .decision-kpi-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .decision-kpi-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.2;
        }

        .risk-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .risk-chip.high {
            background: #fef2f2;
            color: #b91c1c;
        }

        .risk-chip.medium {
            background: #fff7ed;
            color: #c2410c;
        }

        .risk-chip.low {
            background: #ecfdf3;
            color: #027a48;
        }

        .decision-note {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 0;
        }

        .audit-toolbar {
            border-bottom: 1px solid #e5e7eb;
            padding: 14px 16px;
            background: #f8fafc;
        }

        .audit-entity-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin: 14px 16px;
            overflow: hidden;
            background: #fff;
        }

        .audit-entity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .audit-entity-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #374151;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .audit-count-badge {
            background: #eef2ff;
            color: #4338ca;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        body.dark-mode .decision-kpi-card {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .finance-intro-card,
        body.dark-mode .finance-workflow-step,
        body.dark-mode .finance-side-box,
        body.dark-mode .finance-nav-link {
            background: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .finance-kicker {
            background: #1e3a8a;
            color: #dbeafe;
        }

        body.dark-mode .finance-intro-title,
        body.dark-mode .finance-workflow-step strong,
        body.dark-mode .finance-side-box strong,
        body.dark-mode .finance-filter-title {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .finance-intro-text,
        body.dark-mode .finance-workflow-step span,
        body.dark-mode .finance-side-box p,
        body.dark-mode .finance-filter-note,
        body.dark-mode .finance-section-note {
            color: #cbd5e1 !important;
        }

        body.dark-mode .finance-chip {
            background: #1f2937;
            border-color: #334155;
            color: #dbeafe;
        }

        body.dark-mode .decision-kpi-label,
        body.dark-mode .decision-note {
            color: #b0b0b0 !important;
        }

        body.dark-mode .decision-kpi-value {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .audit-toolbar,
        body.dark-mode .audit-entity-header {
            background: #1f2937 !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .audit-entity-card {
            background: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .audit-entity-title {
            color: #f3f4f6 !important;
        }

        body.dark-mode .audit-count-badge {
            background: #374151;
            color: #e5e7eb;
        }

        .refund-proof-box {
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            background: #f8fafc;
            min-height: 230px;
            position: relative;
            overflow: hidden;
        }

        .refund-proof-placeholder {
            min-height: 230px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            gap: 8px;
            cursor: pointer;
            padding: 14px;
            text-align: center;
        }

        .refund-proof-preview-media {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            display: none;
            background: #111827;
        }

        .refund-signature-box {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            overflow: hidden;
        }

        .refund-signature-canvas {
            width: 100%;
            height: 170px;
            display: block;
            cursor: crosshair;
            background: #ffffff;
        }

        body.dark-mode .refund-proof-box {
            background: #1f2937;
            border-color: #4b5563;
        }

        body.dark-mode .refund-proof-placeholder {
            color: #d1d5db;
        }

        body.dark-mode .refund-signature-box {
            border-color: #4b5563;
        }

        .refund-swal-popup {
            width: min(940px, 96vw) !important;
        }

        .refund-modal-layout {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 14px;
            margin-top: 10px;
        }

        .refund-modal-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f9fafb;
            padding: 12px;
        }

        .refund-modal-title {
            font-weight: 700;
            font-size: 0.92rem;
            color: #374151;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .refund-modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .refund-modal-grid .full {
            grid-column: 1 / -1;
        }

        .refund-modal-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 10px 0;
        }

        .refund-modal-grid .swal2-input,
        .refund-modal-grid .swal2-select,
        .refund-modal-grid .swal2-textarea,
        .refund-modal-card .swal2-input,
        .refund-modal-card .swal2-select,
        .refund-modal-card .swal2-textarea {
            width: 100% !important;
            margin: 0 !important;
        }

        body.dark-mode .refund-modal-card {
            background: #1f2937;
            border-color: #374151;
        }

        body.dark-mode .refund-modal-title {
            color: #f3f4f6;
        }

        body.dark-mode .refund-modal-divider {
            background: #374151;
        }

        @media (max-width: 900px) {
            .refund-modal-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .finance-intro-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Finance Management</h1>
                    <span class="finance-scope-badge <?php echo htmlspecialchars($finance_scope_badge_class); ?>">
                        <i class="fas <?php echo htmlspecialchars($finance_scope_badge_icon); ?>"></i>
                        <?php echo htmlspecialchars($finance_scope_badge_label); ?>
                    </span>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto; margin-right: 15px;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <div class="finance-intro-card">
                    <div class="finance-intro-grid">
                        <div>
                            <span class="finance-kicker"><i class="fas fa-compass"></i> Finance Workflow Guide</span>
                            <h2 class="finance-intro-title">Use Finance for approvals, payout control, and monthly performance review.</h2>
                            <p class="finance-intro-text">This module is the approval desk for payroll, cancellations, and refunds. It also gives business owners and finance staff a monthly picture of revenue, expenses, and net results. The simplest workflow is to clear approval queues first, verify the audit trail second, and read the financial overview last.</p>
                            <div class="finance-chip-row">
                                <span class="finance-chip"><i class="fas fa-wallet"></i> Finance approves money-out decisions</span>
                                <span class="finance-chip"><i class="fas fa-file-signature"></i> Every approval or rejection is logged</span>
                                <span class="finance-chip"><i class="fas fa-chart-line"></i> Reports use the selected month and year</span>
                            </div>
                            <div class="finance-workflow-grid">
                                <div class="finance-workflow-step">
                                    <strong>1. Set reporting period</strong>
                                    <span>Choose the month and year first so all totals and charts match the period you want to review.</span>
                                </div>
                                <div class="finance-workflow-step">
                                    <strong>2. Clear approval queues</strong>
                                    <span>Review payroll, then move to cancellation and refund decisions so pending cash obligations do not pile up.</span>
                                </div>
                                <div class="finance-workflow-step">
                                    <strong>3. Confirm accountability</strong>
                                    <span>Use the signature audit trail to verify who approved, rejected, or completed each finance action.</span>
                                </div>
                                <div class="finance-workflow-step">
                                    <strong>4. Review business health</strong>
                                    <span>Check revenue, expenses, and monthly net income after the action queues are already up to date.</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="finance-side-box">
                                <strong>Who should use this page?</strong>
                                <p>Business owners can oversee the whole finance workflow. Finance employees can review records, but only users with <code>finance.manage</code> can approve or reject money-related actions.</p>
                            </div>
                            <div class="finance-side-box">
                                <strong>What belongs here?</strong>
                                <p>Payroll approval, cancellation/refund approval, payout completion, signature audit review, and monthly revenue-versus-expense reporting all belong in Finance.</p>
                            </div>
                            <div class="finance-side-box">
                                <strong>Quick navigation</strong>
                                <div class="finance-nav-links">
                                    <a href="#finance-period" class="finance-nav-link">Period Filter <i class="fas fa-arrow-right"></i></a>
                                    <a href="#payroll-queue" class="finance-nav-link">Payroll Queue <i class="fas fa-arrow-right"></i></a>
                                    <a href="#decision-center" class="finance-nav-link">Decision Center <i class="fas fa-arrow-right"></i></a>
                                    <a href="#audit-trail" class="finance-nav-link">Audit Trail <i class="fas fa-arrow-right"></i></a>
                                    <a href="#financial-overview" class="finance-nav-link">Financial Overview <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="card mb-4 finance-filter-card" id="finance-period">
                    <div class="card-body">
                        <div class="finance-filter-title">1. Set the reporting period</div>
                        <div class="finance-filter-note">Choose the month and year first. The finance overview, charts, and expense totals below will follow this selected period.</div>
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label>Month</label>
                                <select name="month" class="form-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($m == $current_month) ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Year</label>
                                <select name="year" class="form-select">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y == $current_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Payroll for Approval Section -->
                <div class="card mb-4" id="payroll-queue">
                    <div class="card-header bg-warning text-dark">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h5 class="mb-0 payroll-header-title">
                                <i class="fas fa-exclamation-triangle"></i> Payroll for Approval
                                <span class="finance-scope-badge <?php echo htmlspecialchars($finance_scope_badge_class); ?>">
                                    <i class="fas <?php echo htmlspecialchars($finance_scope_badge_icon); ?>"></i>
                                    <?php echo htmlspecialchars($finance_scope_badge_label); ?>
                                </span>
                                </h5>
                                <p class="finance-section-note">2. Review payroll requests here before salaries are finalized. Approving a payroll record also books it into expenses so the monthly finance view stays accurate.</p>
                            </div>
                            <span class="finance-scope-hint"><?php echo htmlspecialchars($finance_scope_badge_hint); ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="payroll-bulk-toolbar">
                            <div class="payroll-bulk-info">
                                <i class="fas fa-layer-group"></i>
                                <span><strong id="selectedPayrollCount">0</strong> selected</span>
                                <?php if (!$can_manage_finance): ?>
                                    <span class="decision-note ms-2">View only. Users need <code>finance.manage</code> to approve or reject payroll.</span>
                                <?php endif; ?>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Payroll bulk actions">
                                <button type="button" id="bulkApprovePayrollBtn" class="btn btn-success" onclick="triggerBulkPayrollAction('approve')" disabled <?= $can_manage_finance ? '' : 'title="finance.manage permission required"' ?>>
                                    <i class="fas fa-check"></i> Bulk Approve
                                </button>
                                <button type="button" id="bulkRejectPayrollBtn" class="btn btn-danger" onclick="triggerBulkPayrollAction('reject')" disabled <?= $can_manage_finance ? '' : 'title="finance.manage permission required"' ?>>
                                    <i class="fas fa-times"></i> Bulk Reject
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="admin-table mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 42px;">
                                            <input type="checkbox" class="form-check-input" id="selectAllPayrollRows" title="Select all pending payroll rows" <?= $can_manage_finance ? '' : 'disabled' ?>>
                                        </th>
                                        <th>Pay Period</th>
                                        <th>Employee</th>
                                        <th>Net Pay</th>
                                        <th>Generated On</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $approval_query = "SELECT p.id, p.pay_period_start, p.pay_period_end, p.net_pay, p.created_at, e.first_name, e.last_name, e.user_id
                                                       FROM payroll p
                                                       JOIN employees e ON p.employee_id = e.id
                                                       WHERE LOWER(TRIM(COALESCE(p.status, ''))) IN ('', 'pending', 'submitted', 'for_approval', 'for approval')
                                                       ORDER BY p.created_at DESC";
                                    $approval_result = mysqli_query($conn, $approval_query);
                                    $visible_payroll_rows = [];
                                    if ($approval_result) {
                                        while ($row = mysqli_fetch_assoc($approval_result)) {
                                            $can_view = true;
                                            if (function_exists('hrCanManageEmployeeUserIdInScope')) {
                                                $can_view = hrCanManageEmployeeUserIdInScope($conn, (int)($row['user_id'] ?? 0));
                                            }
                                            if ($can_view) {
                                                $visible_payroll_rows[] = $row;
                                            }
                                        }
                                    }
                                    if (!empty($visible_payroll_rows)) {
                                        foreach ($visible_payroll_rows as $row) {
                                            $payroll_id = (int)$row['id'];
                                            $checkbox_disabled = $can_manage_finance ? '' : ' disabled';
                                            $review_label = $can_manage_finance ? 'Review & Action' : 'Review';
                                            $review_title = $can_manage_finance
                                                ? 'Review payroll and submit approval decision'
                                                : 'View payroll details';
                                            echo "<tr>";
                                            echo "<td><input type='checkbox' class='form-check-input payroll-select' value='{$payroll_id}'{$checkbox_disabled}></td>";
                                            echo "<td>" . date('M d', strtotime($row['pay_period_start'])) . " - " . date('M d, Y', strtotime($row['pay_period_end'])) . "</td>";
                                            echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                                            echo "<td><strong>₱" . number_format($row['net_pay'], 2) . "</strong></td>";
                                            echo "<td>" . date('M d, Y', strtotime($row['created_at'])) . "</td>";
                                            echo "<td><button class='btn btn-sm btn-primary' title='" . htmlspecialchars($review_title, ENT_QUOTES) . "' onclick='reviewPayroll({$payroll_id})'>{$review_label}</button></td>";
                                            echo "</tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='6' class='text-center text-muted'>No payrolls awaiting approval.</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card mb-4" id="decision-center">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1"><i class="fas fa-scale-balanced text-danger"></i> Refund & Cancellation Decision Center</h5>
                            <p class="decision-note">Finance owns final approval/rejection to align compliance, cashflow, and customer fairness.</p>
                            <p class="finance-section-note">3. Use this queue after payroll. Review the request context, confirm refund details, then approve, reject, or complete payout based on store policy and finance controls.</p>
                        </div>
                        <a href="cancellations.php" class="btn btn-outline-secondary btn-sm">Open Monitoring Page</a>
                    </div>
                    <div class="card-body">
                        <div class="decision-kpi-grid mb-3">
                            <div class="decision-kpi-card">
                                <div class="decision-kpi-label">Pending Cancellation Requests</div>
                                <div class="decision-kpi-value"><?= number_format($pending_cancellations_count) ?></div>
                            </div>
                            <div class="decision-kpi-card">
                                <div class="decision-kpi-label">Pending Refund Reviews</div>
                                <div class="decision-kpi-value"><?= number_format($pending_refunds_count) ?></div>
                            </div>
                            <div class="decision-kpi-card">
                                <div class="decision-kpi-label">Approved Awaiting Payout</div>
                                <div class="decision-kpi-value"><?= number_format($approved_refunds_count) ?></div>
                            </div>
                            <div class="decision-kpi-card">
                                <div class="decision-kpi-label">Refund Liability (Open)</div>
                                <div class="decision-kpi-value">PHP <?= number_format($pending_payout_total, 2) ?></div>
                                <p class="decision-note">Overdue requests (&gt;48h): <strong><?= number_format($overdue_requests_count) ?></strong></p>
                            </div>
                        </div>

                        <form method="GET" class="row g-2 mb-3">
                            <input type="hidden" name="month" value="<?= (int)$current_month ?>">
                            <input type="hidden" name="year" value="<?= (int)$current_year ?>">
                            <div class="col-md-3">
                                <select name="decision_status" class="form-select" onchange="this.form.submit()">
                                    <option value="open" <?= $decision_status_filter === 'open' ? 'selected' : '' ?>>Open Decision Queue</option>
                                    <option value="waiting_cancellation" <?= $decision_status_filter === 'waiting_cancellation' ? 'selected' : '' ?>>Waiting Cancellation Review</option>
                                    <option value="waiting_refund" <?= $decision_status_filter === 'waiting_refund' ? 'selected' : '' ?>>Waiting Refund Approval</option>
                                    <option value="payout_queue" <?= $decision_status_filter === 'payout_queue' ? 'selected' : '' ?>>Approved for Payout</option>
                                    <option value="closed" <?= $decision_status_filter === 'closed' ? 'selected' : '' ?>>Closed Decisions</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <input type="text" name="decision_search" class="form-control" placeholder="Search customer / order / request id" value="<?= htmlspecialchars($decision_search) ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="admin-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Reference</th>
                                        <th>Request Context</th>
                                        <th>Refund Context</th>
                                        <th>Risk</th>
                                        <th>Finance Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($decision_result && mysqli_num_rows($decision_result) > 0): ?>
                                        <?php while ($drow = mysqli_fetch_assoc($decision_result)): ?>
                                            <?php
                                            $hours_waiting = max(0, (time() - strtotime($drow['cancellation_date'])) / 3600);
                                            $is_paid_order = in_array(strtolower((string)$drow['payment_status']), ['paid', 'partial'], true);
                                            $risk_level = 'low';
                                            if (($drow['cancellation_status'] === 'Requested' && $hours_waiting >= 48) || $drow['refund_status'] === 'Refund Approved') {
                                                $risk_level = 'high';
                                            } elseif ($drow['refund_status'] === 'Refund Pending' || ($drow['cancellation_status'] === 'Requested' && $is_paid_order)) {
                                                $risk_level = 'medium';
                                            }
                                            $risk_label = strtoupper($risk_level);
                                            $can_approve_cancellation = !empty($drow['order_id']) && in_array($drow['order_status'], ['pending', 'confirmed', 'cancellation_requested'], true);
                                            ?>
                                            <tr>
                                                <td>
                                                    <?= date('M d, Y h:i A', strtotime($drow['cancellation_date'])) ?>
                                                    <div class="decision-note"><?= number_format($hours_waiting, 1) ?>h waiting</div>
                                                </td>
                                                <td><?= htmlspecialchars($drow['full_name'] ?? 'Unknown') ?></td>
                                                <td>
                                                    <?php if (!empty($drow['order_number'])): ?>
                                                        <a href="orders.php?search=<?= urlencode($drow['order_number']) ?>"><?= htmlspecialchars($drow['order_number']) ?></a>
                                                    <?php elseif (!empty($drow['reservation_id'])): ?>
                                                        Pre-Order #<?= (int)$drow['reservation_id'] ?>
                                                    <?php elseif (!empty($drow['service_request_id'])): ?>
                                                        Service #<?= (int)$drow['service_request_id'] ?>
                                                    <?php else: ?>
                                                        Request #<?= (int)$drow['cancellation_id'] ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div><strong>Status:</strong> <?= htmlspecialchars($drow['cancellation_status']) ?></div>
                                                    <div class="decision-note"><?= htmlspecialchars($drow['reason'] ?? '-') ?></div>
                                                    <?php if (!empty($drow['other_reason_text'])): ?>
                                                        <div class="decision-note">Details: <?= htmlspecialchars((string)$drow['other_reason_text']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($drow['refund_reason'])): ?>
                                                        <div class="decision-note">Refund reason: <?= htmlspecialchars((string)$drow['refund_reason']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($drow['order_status'])): ?>
                                                        <div class="decision-note">Order: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $drow['order_status']))) ?> | Payment: <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$drow['payment_status']))) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($drow['rejection_reason'])): ?>
                                                        <div class="decision-note text-danger">Reason: <?= htmlspecialchars($drow['rejection_reason']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($drow['refund_id'])): ?>
                                                        <div><strong>PHP <?= number_format((float)$drow['refund_amount'], 2) ?></strong></div>
                                                        <div class="decision-note"><?= htmlspecialchars($drow['refund_status']) ?></div>
                                                        <?php if (!empty($drow['processed_date'])): ?>
                                                            <div class="decision-note">Processed: <?= date('M d, Y', strtotime($drow['processed_date'])) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($drow['customer_evidence_path'])): ?>
                                                            <div class="decision-note mt-1">
                                                                <a href="../<?= htmlspecialchars((string)$drow['customer_evidence_path']) ?>" target="_blank" class="btn btn-sm btn-outline-warning me-1"><i class="fas fa-image"></i> Customer Proof</a>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($drow['payout_reference'])): ?>
                                                            <div class="decision-note">
                                                                Payout: <?= htmlspecialchars(financeFormatPayoutChannelLabel((string)$drow['payout_channel'])) ?>
                                                                | Ref: <?= htmlspecialchars((string)$drow['payout_reference']) ?>
                                                            </div>
                                                            <?php if (!empty($drow['payout_account_masked'])): ?>
                                                                <div class="decision-note">Account: <?= htmlspecialchars((string)$drow['payout_account_masked']) ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($drow['payout_sent_at'])): ?>
                                                                <div class="decision-note">Sent: <?= date('M d, Y h:i A', strtotime($drow['payout_sent_at'])) ?></div>
                                                            <?php endif; ?>
                                                            <div class="decision-note mt-1">
                                                                <?php if (!empty($drow['payout_proof'])): ?>
                                                                    <a href="../<?= htmlspecialchars((string)$drow['payout_proof']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-1"><i class="fas fa-image"></i> Proof</a>
                                                                <?php endif; ?>
                                                                <?php if (!empty($drow['payout_finance_signature'])): ?>
                                                                    <a href="../<?= htmlspecialchars((string)$drow['payout_finance_signature']) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-signature"></i> Signature</a>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="decision-note">No refund record yet</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="risk-chip <?= $risk_level ?>"><?= $risk_label ?></span></td>
                                                <td>
                                                    <?php if (!$can_manage_finance): ?>
                                                        <span class="decision-note">View only. Users need <code>finance.manage</code> to take finance actions.</span>
                                                    <?php elseif ($drow['cancellation_status'] === 'Requested' && !empty($drow['order_id'])): ?>
                                                            <form method="POST" id="cxlDecision<?= (int)$drow['cancellation_id'] ?>" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <input type="hidden" name="finance_decision_action" value="">
                                                                <input type="hidden" name="cancellation_id" value="<?= (int)$drow['cancellation_id'] ?>">
                                                                <input type="hidden" name="decision_notes" value="">
                                                                <input type="hidden" name="decision_signature" value="">
                                                                <button type="button" class="btn btn-sm btn-success mb-1" <?= $can_approve_cancellation ? '' : 'disabled' ?> onclick="submitFinanceDecision('cxlDecision<?= (int)$drow['cancellation_id'] ?>', 'approve_cancellation', false, 'Approve cancellation request?')">Approve</button>
                                                                <button type="button" class="btn btn-sm btn-outline-danger mb-1" onclick="submitFinanceDecision('cxlDecision<?= (int)$drow['cancellation_id'] ?>', 'reject_cancellation', true, 'Reject cancellation request?')">Reject</button>
                                                            </form>
                                                    <?php elseif (!empty($drow['refund_id']) && in_array($drow['refund_status'], ['Refund Pending', 'Refund Approved'], true)): ?>
                                                            <form method="POST" id="refundDecision<?= (int)$drow['refund_id'] ?>" class="d-inline" enctype="multipart/form-data">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <input type="hidden" name="finance_decision_action" value="">
                                                                <input type="hidden" name="refund_id" value="<?= (int)$drow['refund_id'] ?>">
                                                                <input type="hidden" name="decision_notes" value="">
                                                                <input type="hidden" name="decision_signature" value="">
                                                                <input type="hidden" name="payout_channel" value="">
                                                                <input type="hidden" name="payout_reference" value="">
                                                                <input type="hidden" name="payout_account_name" value="">
                                                                <input type="hidden" name="payout_account_masked" value="">
                                                                <input type="hidden" name="payout_proof" value="">
                                                                <input type="hidden" name="payout_finance_signature" value="">
                                                                <?php if ($drow['refund_status'] === 'Refund Pending'): ?>
                                                                    <button type="button" class="btn btn-sm btn-success mb-1" onclick="submitFinanceDecision('refundDecision<?= (int)$drow['refund_id'] ?>', 'approve_refund', false, 'Approve refund request?')">Approve</button>
                                                                <?php endif; ?>
                                                                <?php if (in_array($drow['refund_status'], ['Refund Pending', 'Refund Approved'], true)): ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger mb-1" onclick="submitFinanceDecision('refundDecision<?= (int)$drow['refund_id'] ?>', 'reject_refund', true, 'Reject refund request?')">Reject</button>
                                                                <?php endif; ?>
                                                                <?php if ($drow['refund_status'] === 'Refund Approved'): ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-primary mb-1" onclick="submitFinanceDecision('refundDecision<?= (int)$drow['refund_id'] ?>', 'complete_refund', false, 'Mark refund as completed?', '<?= htmlspecialchars(number_format((float)$drow['refund_amount'], 2), ENT_QUOTES) ?>')">Complete</button>
                                                                <?php endif; ?>
                                                            </form>
                                                    <?php else: ?>
                                                        <span class="decision-note">No pending finance action</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">No decision records found for this filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card mb-4" id="audit-trail">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0"><i class="fas fa-signature text-secondary"></i> Recent Signature Audit Trail</h6>
                            <p class="finance-section-note">4. This is the accountability layer. Use it to confirm which finance user signed each approval or rejection and when it happened.</p>
                        </div>
                        <span class="decision-note">Approve/Reject records organized by entity</span>
                    </div>
                    <div class="audit-toolbar">
                        <form method="GET" class="row g-2 align-items-end">
                            <input type="hidden" name="month" value="<?= (int)$current_month ?>">
                            <input type="hidden" name="year" value="<?= (int)$current_year ?>">
                            <?php if ($decision_status_filter !== ''): ?>
                                <input type="hidden" name="decision_status" value="<?= htmlspecialchars($decision_status_filter) ?>">
                            <?php endif; ?>
                            <?php if ($decision_search !== ''): ?>
                                <input type="hidden" name="decision_search" value="<?= htmlspecialchars($decision_search) ?>">
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Entity</label>
                                <select name="audit_entity" class="form-select">
                                    <option value="all" <?= $audit_entity_filter === 'all' ? 'selected' : '' ?>>All Entities</option>
                                    <option value="refund" <?= $audit_entity_filter === 'refund' ? 'selected' : '' ?>>Refund</option>
                                    <option value="cancellation" <?= $audit_entity_filter === 'cancellation' ? 'selected' : '' ?>>Cancellation</option>
                                    <option value="payroll" <?= $audit_entity_filter === 'payroll' ? 'selected' : '' ?>>Payroll</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label mb-1">Action</label>
                                <select name="audit_action" class="form-select">
                                    <option value="all" <?= $audit_action_filter === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="approve" <?= $audit_action_filter === 'approve' ? 'selected' : '' ?>>Approve</option>
                                    <option value="reject" <?= $audit_action_filter === 'reject' ? 'selected' : '' ?>>Reject</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label mb-1">Search</label>
                                <input type="text" name="audit_search" class="form-control" value="<?= htmlspecialchars($audit_search) ?>" placeholder="Search signer, action key, or record #">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                                <a href="<?= htmlspecialchars($audit_reset_url) ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                        <div class="decision-note mt-2">Showing <?= number_format($signature_audit_total) ?> audit record(s).</div>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        foreach ($audit_meta as $entity_key => $entity_meta):
                            if ($audit_entity_filter !== 'all' && $audit_entity_filter !== $entity_key) {
                                continue;
                            }
                            $entity_rows = $signature_audit_grouped[$entity_key] ?? [];
                        ?>
                        <div class="audit-entity-card">
                            <div class="audit-entity-header">
                                <div class="audit-entity-title">
                                    <i class="fas <?= htmlspecialchars($entity_meta['icon']) ?>"></i>
                                    <?= htmlspecialchars($entity_meta['label']) ?>
                                </div>
                                <span class="audit-count-badge"><?= number_format(count($entity_rows)) ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="admin-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Signed At</th>
                                            <th>Signed By</th>
                                            <th>Action</th>
                                            <th>Record</th>
                                            <th>Signature</th>
                                            <th>Note</th>
                                            <th>IP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($entity_rows)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No records for this entity under current filters.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($entity_rows as $audit): ?>
                                                <tr>
                                                    <td><?= date('M d, Y h:i A', strtotime($audit['signed_at'])) ?></td>
                                                    <td><?= htmlspecialchars($audit['admin_name'] ?: $audit['signed_by']) ?></td>
                                                    <td>
                                                        <span class="risk-chip <?= $audit['action_type'] === 'approve' ? 'low' : 'high' ?>">
                                                            <?= strtoupper($audit['action_type']) ?>
                                                        </span>
                                                        <div class="decision-note"><?= htmlspecialchars($audit['action_key']) ?></div>
                                                    </td>
                                                    <td>#<?= (int)$audit['entity_id'] ?></td>
                                                    <td>
                                                        <?php if (!empty($audit['signature_image_path'])): ?>
                                                            <a href="../<?= htmlspecialchars((string)$audit['signature_image_path']) ?>" target="_blank" class="btn btn-sm btn-outline-dark">
                                                                <i class="fas fa-signature"></i> View
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="decision-note">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="decision-note"><?= htmlspecialchars($audit['decision_note'] ?: '-') ?></td>
                                                    <td class="decision-note"><?= htmlspecialchars($audit['ip_address'] ?: '-') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section-header" id="financial-overview">
                    <h2>Financial Overview (<?php echo date('F', mktime(0,0,0,$current_month,1)) . ' ' . $current_year; ?>)</h2>
                    <button class="btn btn-outline-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
                <p class="finance-section-note mb-3">5. Finish here. Once approvals and payout decisions are updated, this overview gives the clearest monthly picture of revenue, expenses, and net income for the selected period.</p>

                <!-- Financial Cards -->
                <div class="dashboard-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e3f2fd;">
                            <i class="fas fa-coins text-primary"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₱<?php echo number_format($monthly_revenue, 2); ?></h3>
                            <p>Monthly Revenue</p>
                            <small class="text-muted"><?php echo date('F Y', mktime(0,0,0,$current_month,1, $current_year)); ?></small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ffebee;">
                            <i class="fas fa-file-invoice-dollar text-danger"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₱<?php echo number_format($monthly_expenses, 2); ?></h3>
                            <p>Monthly Expenses</p>
                            <small class="text-muted">Payroll + Operational</small>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #e8f5e9;">
                            <i class="fas fa-chart-line text-success"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₱<?php echo number_format($monthly_net, 2); ?></h3>
                            <p>Monthly Net Income</p>
                            <small class="<?php echo $monthly_net >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $monthly_net >= 0 ? 'Profit' : 'Loss'; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Revenue Trend</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Expense Breakdown (<?php echo date('F', mktime(0,0,0,$current_month,1)); ?>)</h5>
                            </div>
                            <div class="card-body d-flex align-items-center justify-content-center">
                                <canvas id="expenseChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll Review Modal -->
    <div class="modal fade" id="payrollReviewModal" tabindex="-1" aria-labelledby="payrollReviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="payrollReviewModalLabel">Review Payroll</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="payrollReviewDetails">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode(array_values($monthly_data)); ?>,
                    borderColor: '#2196F3',
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        const expenseCtx = document.getElementById('expenseChart').getContext('2d');
        new Chart(expenseCtx, {
            type: 'doughnut',
            data: {
                labels: ['Payroll', 'Operational'],
                datasets: [{
                    data: [<?php echo $monthly_payroll_expense; ?>, <?php echo $monthly_ops_expense; ?>],
                    backgroundColor: ['#FF6384', '#36A2EB']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: { display: false }
                }
            }
        });

        function financeEscapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        async function reviewPayroll(payrollId) {
            const detailsEl = document.getElementById('payrollReviewDetails');
            const modalEl = document.getElementById('payrollReviewModal');
            if (!detailsEl || !modalEl) {
                return;
            }

            detailsEl.innerHTML = '<div class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            const payrollModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            payrollModal.show();

            try {
                const response = await fetch(`get_payroll_details_for_approval.php?id=${encodeURIComponent(payrollId)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html,application/json'
                    }
                });

                const contentType = String(response.headers.get('content-type') || '').toLowerCase();
                if (contentType.includes('application/json')) {
                    let payload = null;
                    try {
                        payload = await response.json();
                    } catch (parseError) {
                        payload = null;
                    }
                    throw new Error(payload?.message || 'Unable to load payroll details.');
                }

                const html = await response.text();
                if (!response.ok) {
                    const plainMessage = String(html || '')
                        .replace(/<[^>]*>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();
                    throw new Error(plainMessage || 'Unable to load payroll details.');
                }
                detailsEl.innerHTML = html;
            } catch (error) {
                const message = error?.message || 'Unable to load payroll details.';
                detailsEl.innerHTML = `<div class="alert alert-danger mb-0">${financeEscapeHtml(message)}</div>`;
                console.error('Payroll review load error:', error);
            }
        }

        const financeCanManage = <?php echo $can_manage_finance ? 'true' : 'false'; ?>;
        const expectedAdminSignature = <?php echo json_encode($expected_admin_signature); ?>;
        const financeCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const financeUploadLimits = <?php echo json_encode(financeGetUploadLimits()); ?>;
        const financeSingleUploadLimit = Number(financeUploadLimits?.single_file || (5 * 1024 * 1024));
        const financeTotalUploadLimit = Number(financeUploadLimits?.total || (8 * 1024 * 1024));

        function formatBytes(bytes) {
            const value = Number(bytes || 0);
            if (value >= 1024 * 1024 * 1024) return `${(value / (1024 * 1024 * 1024)).toFixed(1)}GB`;
            if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)}MB`;
            if (value >= 1024) return `${(value / 1024).toFixed(1)}KB`;
            return `${value}B`;
        }

        function normalizeSignature(value) {
            return (value || '').toString().trim().toLowerCase().replace(/\s+/g, ' ');
        }

        function getSelectedPayrollIds() {
            return Array.from(document.querySelectorAll('.payroll-select:checked'))
                .map((el) => Number(el.value))
                .filter((id) => Number.isInteger(id) && id > 0);
        }

        function syncPayrollBulkControls() {
            const selectedIds = getSelectedPayrollIds();
            const countEl = document.getElementById('selectedPayrollCount');
            const approveBtn = document.getElementById('bulkApprovePayrollBtn');
            const rejectBtn = document.getElementById('bulkRejectPayrollBtn');
            const selectAllEl = document.getElementById('selectAllPayrollRows');
            const rowCheckboxes = Array.from(document.querySelectorAll('.payroll-select'));

            if (countEl) countEl.textContent = String(selectedIds.length);
            if (approveBtn) approveBtn.disabled = !financeCanManage || selectedIds.length === 0;
            if (rejectBtn) rejectBtn.disabled = !financeCanManage || selectedIds.length === 0;

            if (selectAllEl) {
                if (!financeCanManage) {
                    selectAllEl.checked = false;
                    selectAllEl.indeterminate = false;
                    selectAllEl.disabled = true;
                    return;
                }
                if (rowCheckboxes.length === 0) {
                    selectAllEl.checked = false;
                    selectAllEl.indeterminate = false;
                } else {
                    selectAllEl.checked = selectedIds.length === rowCheckboxes.length;
                    selectAllEl.indeterminate = selectedIds.length > 0 && selectedIds.length < rowCheckboxes.length;
                }
            }
        }

        function initPayrollBulkSelection() {
            const selectAllEl = document.getElementById('selectAllPayrollRows');
            const rowCheckboxes = Array.from(document.querySelectorAll('.payroll-select'));

            if (!financeCanManage) {
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = false;
                    checkbox.disabled = true;
                });
            }

            if (selectAllEl) {
                selectAllEl.addEventListener('change', () => {
                    rowCheckboxes.forEach((checkbox) => {
                        checkbox.checked = selectAllEl.checked;
                    });
                    syncPayrollBulkControls();
                });
            }

            rowCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', syncPayrollBulkControls);
            });

            syncPayrollBulkControls();
        }

        initPayrollBulkSelection();

        function handlePayrollAction(action, payrollId) {
            if (!financeCanManage) {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Required',
                    text: 'You need finance.manage permission to approve or reject payroll.'
                });
                return;
            }

            const isReject = action === 'reject';
            const modalTitle = isReject ? 'Reject Payroll' : 'Approve Payroll';
            const confirmColor = isReject ? '#dc3545' : '#28a745';
            const guidanceText = isReject
                ? 'Add reason/notes and finance signature before rejecting.'
                : 'This action approves payroll and records finance entries.';

            let payrollSignatureCanvasRef = null;
            let payrollSignatureHasInk = false;
            let payrollUploadedSignatureFile = null;

            Swal.fire({
                title: modalTitle,
                html: `
                    <p class="text-muted small mb-2">${guidanceText}</p>
                    <label class="form-label mb-1">Notes ${isReject ? '(optional)' : '(optional)'}</label>
                    <textarea id="swalPayrollNotes" class="swal2-textarea" placeholder="${isReject ? 'Reason for rejection...' : 'Optional payroll approval notes...'}"></textarea>
                    <div class="refund-modal-card mt-2">
                        <div class="refund-modal-title"><i class="fas fa-signature"></i> Finance Signature <span class="text-danger">*</span></div>
                        <div class="refund-signature-box">
                            <canvas id="swalPayrollSignaturePad" class="refund-signature-canvas" width="760" height="180"></canvas>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalClearPayrollSignature">Clear Signature</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalUploadPayrollSignature"><i class="fas fa-upload"></i> Upload Signature Photo</button>
                        </div>
                        <input type="file" id="swalPayrollSignatureFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <div class="form-text mt-1" id="swalPayrollSignatureStatus">Draw signature or upload signature photo.</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: isReject ? 'Reject' : 'Approve',
                confirmButtonColor: confirmColor,
                focusConfirm: false,
                didOpen: () => {
                    const signatureCanvas = document.getElementById('swalPayrollSignaturePad');
                    const clearSignatureBtn = document.getElementById('swalClearPayrollSignature');
                    const uploadSignatureBtn = document.getElementById('swalUploadPayrollSignature');
                    const signatureFileInput = document.getElementById('swalPayrollSignatureFile');
                    const signatureStatusText = document.getElementById('swalPayrollSignatureStatus');

                    payrollSignatureCanvasRef = signatureCanvas;
                    if (signatureCanvas) {
                        const sigCtx = signatureCanvas.getContext('2d');
                        sigCtx.lineWidth = 2.2;
                        sigCtx.lineCap = 'round';
                        sigCtx.lineJoin = 'round';
                        sigCtx.strokeStyle = '#111827';

                        let drawing = false;
                        const getPoint = (event) => {
                            const rect = signatureCanvas.getBoundingClientRect();
                            const evt = event.touches ? event.touches[0] : event;
                            const scaleX = signatureCanvas.width / rect.width;
                            const scaleY = signatureCanvas.height / rect.height;
                            return {
                                x: (evt.clientX - rect.left) * scaleX,
                                y: (evt.clientY - rect.top) * scaleY
                            };
                        };

                        const startDraw = (event) => {
                            event.preventDefault();
                            drawing = true;
                            const p = getPoint(event);
                            sigCtx.beginPath();
                            sigCtx.moveTo(p.x, p.y);
                        };

                        const draw = (event) => {
                            if (!drawing) return;
                            event.preventDefault();
                            const p = getPoint(event);
                            sigCtx.lineTo(p.x, p.y);
                            sigCtx.stroke();
                            payrollSignatureHasInk = true;
                            payrollUploadedSignatureFile = null;
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature captured.';
                        };

                        const endDraw = () => {
                            drawing = false;
                        };

                        signatureCanvas.addEventListener('mousedown', startDraw);
                        signatureCanvas.addEventListener('mousemove', draw);
                        signatureCanvas.addEventListener('mouseup', endDraw);
                        signatureCanvas.addEventListener('mouseleave', endDraw);
                        signatureCanvas.addEventListener('touchstart', startDraw, { passive: false });
                        signatureCanvas.addEventListener('touchmove', draw, { passive: false });
                        signatureCanvas.addEventListener('touchend', endDraw);
                    }

                    if (clearSignatureBtn) {
                        clearSignatureBtn.addEventListener('click', () => {
                            if (!payrollSignatureCanvasRef) return;
                            const sigCtx = payrollSignatureCanvasRef.getContext('2d');
                            sigCtx.clearRect(0, 0, payrollSignatureCanvasRef.width, payrollSignatureCanvasRef.height);
                            payrollSignatureHasInk = false;
                            payrollUploadedSignatureFile = null;
                            if (signatureFileInput) signatureFileInput.value = '';
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature cleared.';
                        });
                    }

                    if (uploadSignatureBtn) {
                        uploadSignatureBtn.addEventListener('click', () => signatureFileInput?.click());
                    }
                    if (signatureFileInput) {
                        signatureFileInput.addEventListener('change', (e) => {
                            const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                            if (!file) return;
                            payrollUploadedSignatureFile = file;
                            payrollSignatureHasInk = false;
                            if (payrollSignatureCanvasRef) {
                                const sigCtx = payrollSignatureCanvasRef.getContext('2d');
                                sigCtx.clearRect(0, 0, payrollSignatureCanvasRef.width, payrollSignatureCanvasRef.height);
                            }
                            if (signatureStatusText) signatureStatusText.textContent = `Uploaded signature file: ${file.name}`;
                        });
                    }
                },
                preConfirm: async () => {
                    const notes = (document.getElementById('swalPayrollNotes')?.value || '').trim();
                    let signatureFile = payrollUploadedSignatureFile;
                    if (!signatureFile && payrollSignatureCanvasRef && payrollSignatureHasInk) {
                        const signatureBlob = await new Promise((resolve) => payrollSignatureCanvasRef.toBlob(resolve, 'image/png'));
                        if (signatureBlob) {
                            signatureFile = new File([signatureBlob], `payroll_signature_${Date.now()}.png`, { type: 'image/png' });
                        }
                    }
                    if (!signatureFile) {
                        Swal.showValidationMessage('Please draw or upload finance signature.');
                        return false;
                    }
                    if (!signatureFile.type.startsWith('image/')) {
                        Swal.showValidationMessage('Signature file must be an image (JPG, PNG, or WEBP).');
                        return false;
                    }
                    if (signatureFile.size > financeSingleUploadLimit) {
                        Swal.showValidationMessage(`Signature image must be ${formatBytes(financeSingleUploadLimit)} or below.`);
                        return false;
                    }
                    return { notes, signatureFile };
                }
            }).then((result) => {
                if (!result.isConfirmed || !result.value) return;
                submitPayrollActionWithSignature(action, payrollId, result.value.notes || '', result.value.signatureFile);
            });
        }

        async function submitPayrollActionWithSignature(action, payrollId, notes, signatureFile) {
            if (!financeCanManage) {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Required',
                    text: 'You need finance.manage permission to approve or reject payroll.'
                });
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', financeCsrfToken);
            formData.append('payroll_action', action);
            formData.append('payroll_id', payrollId);
            formData.append('payroll_notes', notes || '');
            formData.append('rejection_reason', action === 'reject' ? (notes || '') : '');
            formData.append('payroll_signature', 'image_signature');
            if (signatureFile) {
                formData.append('payroll_signature_file', signatureFile);
            }

            Swal.fire({
                title: action === 'reject' ? 'Rejecting payroll...' : 'Approving payroll...',
                text: 'Please wait while we upload signature and process this request.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const response = await fetch('finance.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const rawText = await response.text();
                let payload = null;
                try {
                    payload = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload?.message || 'Failed to process payroll action.');
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: payload.message || 'Payroll action completed.',
                    timer: 1600,
                    showConfirmButton: false
                });
                window.location.href = 'finance.php';
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: error?.message || 'Unable to process payroll action.'
                });
            }
        }

        function triggerBulkPayrollAction(action) {
            if (!financeCanManage) {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Required',
                    text: 'You need finance.manage permission to run bulk payroll actions.'
                });
                return;
            }

            const payrollIds = getSelectedPayrollIds();
            if (payrollIds.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Payroll Selected',
                    text: 'Select one or more pending payroll rows first.'
                });
                return;
            }

            const isReject = action === 'reject';
            const title = isReject
                ? `Reject ${payrollIds.length} Payroll Record(s)`
                : `Approve ${payrollIds.length} Payroll Record(s)`;
            const confirmText = isReject ? 'Reject Selected' : 'Approve Selected';
            let bulkSignatureCanvasRef = null;
            let bulkSignatureHasInk = false;
            let bulkUploadedSignatureFile = null;

            Swal.fire({
                title,
                html: `
                    <p class="text-muted small mb-2">
                        ${isReject ? 'Add rejection reason and finance signature to continue.' : 'Provide finance signature to approve selected payroll records.'}
                    </p>
                    <label class="form-label mb-1">Notes ${isReject ? '<span class="text-danger">*</span>' : '(optional)'}</label>
                    <textarea id="swalBulkPayrollNotes" class="swal2-textarea" placeholder="${isReject ? 'Reason for rejection...' : 'Optional approval notes...'}"></textarea>
                    <div class="refund-modal-card mt-2">
                        <div class="refund-modal-title"><i class="fas fa-signature"></i> Finance Signature <span class="text-danger">*</span></div>
                        <div class="refund-signature-box">
                            <canvas id="swalBulkPayrollSignaturePad" class="refund-signature-canvas" width="760" height="180"></canvas>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalClearBulkPayrollSignature">Clear Signature</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalUploadBulkPayrollSignature"><i class="fas fa-upload"></i> Upload Signature Photo</button>
                        </div>
                        <input type="file" id="swalBulkPayrollSignatureFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <div class="form-text mt-1" id="swalBulkPayrollSignatureStatus">Draw signature or upload signature photo.</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: confirmText,
                confirmButtonColor: isReject ? '#dc3545' : '#198754',
                focusConfirm: false,
                customClass: { popup: 'refund-swal-popup' },
                didOpen: () => {
                    const signatureCanvas = document.getElementById('swalBulkPayrollSignaturePad');
                    const clearSignatureBtn = document.getElementById('swalClearBulkPayrollSignature');
                    const uploadSignatureBtn = document.getElementById('swalUploadBulkPayrollSignature');
                    const signatureFileInput = document.getElementById('swalBulkPayrollSignatureFile');
                    const signatureStatusText = document.getElementById('swalBulkPayrollSignatureStatus');

                    bulkSignatureCanvasRef = signatureCanvas;
                    if (signatureCanvas) {
                        const sigCtx = signatureCanvas.getContext('2d');
                        sigCtx.lineWidth = 2.2;
                        sigCtx.lineCap = 'round';
                        sigCtx.lineJoin = 'round';
                        sigCtx.strokeStyle = '#111827';

                        let drawing = false;
                        const getPoint = (event) => {
                            const rect = signatureCanvas.getBoundingClientRect();
                            const evt = event.touches ? event.touches[0] : event;
                            const scaleX = signatureCanvas.width / rect.width;
                            const scaleY = signatureCanvas.height / rect.height;
                            return {
                                x: (evt.clientX - rect.left) * scaleX,
                                y: (evt.clientY - rect.top) * scaleY
                            };
                        };

                        const startDraw = (event) => {
                            event.preventDefault();
                            drawing = true;
                            const p = getPoint(event);
                            sigCtx.beginPath();
                            sigCtx.moveTo(p.x, p.y);
                        };

                        const draw = (event) => {
                            if (!drawing) return;
                            event.preventDefault();
                            const p = getPoint(event);
                            sigCtx.lineTo(p.x, p.y);
                            sigCtx.stroke();
                            bulkSignatureHasInk = true;
                            bulkUploadedSignatureFile = null;
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature captured.';
                        };

                        const endDraw = () => {
                            drawing = false;
                        };

                        signatureCanvas.addEventListener('mousedown', startDraw);
                        signatureCanvas.addEventListener('mousemove', draw);
                        signatureCanvas.addEventListener('mouseup', endDraw);
                        signatureCanvas.addEventListener('mouseleave', endDraw);
                        signatureCanvas.addEventListener('touchstart', startDraw, { passive: false });
                        signatureCanvas.addEventListener('touchmove', draw, { passive: false });
                        signatureCanvas.addEventListener('touchend', endDraw);
                    }

                    if (clearSignatureBtn) {
                        clearSignatureBtn.addEventListener('click', () => {
                            if (!bulkSignatureCanvasRef) return;
                            const sigCtx = bulkSignatureCanvasRef.getContext('2d');
                            sigCtx.clearRect(0, 0, bulkSignatureCanvasRef.width, bulkSignatureCanvasRef.height);
                            bulkSignatureHasInk = false;
                            bulkUploadedSignatureFile = null;
                            if (signatureFileInput) signatureFileInput.value = '';
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature cleared.';
                        });
                    }

                    if (uploadSignatureBtn) {
                        uploadSignatureBtn.addEventListener('click', () => signatureFileInput?.click());
                    }
                    if (signatureFileInput) {
                        signatureFileInput.addEventListener('change', (e) => {
                            const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                            if (!file) return;
                            bulkUploadedSignatureFile = file;
                            bulkSignatureHasInk = false;
                            if (bulkSignatureCanvasRef) {
                                const sigCtx = bulkSignatureCanvasRef.getContext('2d');
                                sigCtx.clearRect(0, 0, bulkSignatureCanvasRef.width, bulkSignatureCanvasRef.height);
                            }
                            if (signatureStatusText) signatureStatusText.textContent = `Uploaded signature file: ${file.name}`;
                        });
                    }
                },
                preConfirm: async () => {
                    const notes = (document.getElementById('swalBulkPayrollNotes')?.value || '').trim();
                    let signatureFile = bulkUploadedSignatureFile;
                    if (!signatureFile && bulkSignatureCanvasRef && bulkSignatureHasInk) {
                        const signatureBlob = await new Promise((resolve) => bulkSignatureCanvasRef.toBlob(resolve, 'image/png'));
                        if (signatureBlob) {
                            signatureFile = new File([signatureBlob], `bulk_payroll_signature_${Date.now()}.png`, { type: 'image/png' });
                        }
                    }

                    if (isReject && notes === '') {
                        Swal.showValidationMessage('Reason is required when rejecting payroll.');
                        return false;
                    }
                    if (!signatureFile) {
                        Swal.showValidationMessage('Please draw or upload finance signature.');
                        return false;
                    }
                    if (!signatureFile.type.startsWith('image/')) {
                        Swal.showValidationMessage('Signature file must be an image (JPG, PNG, or WEBP).');
                        return false;
                    }
                    if (signatureFile.size > financeSingleUploadLimit) {
                        Swal.showValidationMessage(`Signature image must be ${formatBytes(financeSingleUploadLimit)} or below.`);
                        return false;
                    }

                    return { notes, signatureFile };
                }
            }).then((result) => {
                if (!result.isConfirmed || !result.value) return;
                submitBulkPayrollActionWithSignature(action, payrollIds, result.value.notes || '', result.value.signatureFile);
            });
        }

        async function submitBulkPayrollActionWithSignature(action, payrollIds, notes, signatureFile) {
            if (!financeCanManage) {
                Swal.fire({
                    icon: 'error',
                    title: 'Permission Required',
                    text: 'You need finance.manage permission to run bulk payroll actions.'
                });
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', financeCsrfToken);
            formData.append('bulk_payroll_action', action);
            formData.append('bulk_rejection_reason', notes || '');
            formData.append('payroll_signature', 'image_signature');
            payrollIds.forEach((id) => formData.append('payroll_ids[]', String(id)));
            if (signatureFile) {
                formData.append('payroll_signature_file', signatureFile);
            }

            Swal.fire({
                title: action === 'reject' ? 'Rejecting selected payroll...' : 'Approving selected payroll...',
                text: 'Please wait while we process the selected payroll records.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const response = await fetch('finance.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const rawText = await response.text();
                let payload = null;
                try {
                    payload = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload?.message || 'Failed to process bulk payroll action.');
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Bulk Action Completed',
                    text: payload.message || 'Selected payroll records were processed.',
                    timer: 1800,
                    showConfirmButton: false
                });
                window.location.href = 'finance.php';
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Bulk Action Failed',
                    text: error?.message || 'Unable to process selected payroll records.'
                });
            }
        }

        function submitFinanceDecision(formId, action, requireReason, titleText, refundAmountText = '') {
            const form = document.getElementById(formId);
            if (!form) return;

            const requiresSignature = action.includes('approve') || action.includes('reject');
            const isCompleteRefund = action === 'complete_refund';
            const requiresDecisionSignature = requiresSignature && !isCompleteRefund;
            let proofCameraStream = null;
            let capturedProofBlob = null;
            let uploadedProofFile = null;
            let uploadedSignatureFile = null;
            let signatureCanvasRef = null;
            let signatureHasInk = false;
            let decisionSignatureCanvasRef = null;
            let decisionSignatureHasInk = false;
            let uploadedDecisionSignatureFile = null;

            const stopProofCamera = () => {
                if (proofCameraStream) {
                    proofCameraStream.getTracks().forEach((track) => track.stop());
                    proofCameraStream = null;
                }
                const video = document.getElementById('swalRefundProofVideo');
                if (video) {
                    video.pause();
                    video.srcObject = null;
                    video.style.display = 'none';
                }
                const startBtn = document.getElementById('swalStartProofCamera');
                const captureBtn = document.getElementById('swalCaptureProofPhoto');
                const stopBtn = document.getElementById('swalStopProofCamera');
                if (startBtn) startBtn.style.display = 'inline-block';
                if (captureBtn) captureBtn.style.display = 'none';
                if (stopBtn) stopBtn.style.display = 'none';
            };

            const notesPlaceholder = requireReason
                ? 'Reason is required for this action...'
                : (isCompleteRefund ? 'Optional payout notes (bank used, internal memo, etc)...' : 'Optional internal/customer-facing remarks...');
            const completeRefundIntro = isCompleteRefund
                ? `<p class="text-muted small mb-2">Record payout details before marking this refund as completed.${refundAmountText ? ` Amount: <strong>PHP ${refundAmountText}</strong>.` : ''}</p>`
                : '';
            const decisionSignatureHtml = requiresDecisionSignature ? `
                <div class="refund-modal-card mt-2">
                    <div class="refund-modal-title"><i class="fas fa-signature"></i> Finance Signature <span class="text-danger">*</span></div>
                    <div class="refund-signature-box">
                        <canvas id="swalDecisionSignaturePad" class="refund-signature-canvas" width="760" height="180"></canvas>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="swalClearDecisionSignature">Clear Signature</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="swalUploadDecisionSignature"><i class="fas fa-upload"></i> Upload Signature Photo</button>
                    </div>
                    <input type="file" id="swalDecisionSignatureFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    <div class="form-text mt-1" id="swalDecisionSignatureStatus">Draw signature or upload signature photo.</div>
                </div>
            ` : '';
            const payoutFieldsHtml = isCompleteRefund ? `
                <div class="refund-modal-layout">
                    <div class="refund-modal-card">
                        <div class="refund-modal-title"><i class="fas fa-image"></i> Proof of Transaction Photo <span class="text-danger">*</span></div>
                        <div class="refund-proof-box">
                            <div id="swalRefundProofPlaceholder" class="refund-proof-placeholder">
                                <i class="fas fa-camera fa-2x"></i>
                                <div>Click to capture from camera or upload</div>
                            </div>
                            <video id="swalRefundProofVideo" class="refund-proof-preview-media" autoplay playsinline></video>
                            <img id="swalRefundProofPreview" class="refund-proof-preview-media" alt="Proof preview">
                        </div>
                        <div class="btn-group w-100 mt-2" role="group">
                            <button type="button" class="btn btn-outline-primary" id="swalStartProofCamera"><i class="fas fa-video"></i> Start Camera</button>
                            <button type="button" class="btn btn-outline-primary" id="swalCaptureProofPhoto" style="display:none;"><i class="fas fa-camera"></i> Capture</button>
                            <button type="button" class="btn btn-outline-danger" id="swalStopProofCamera" style="display:none;"><i class="fas fa-stop"></i> Stop</button>
                            <button type="button" class="btn btn-outline-secondary" id="swalUploadProofPhoto"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                        <input type="file" id="swalPayoutProofFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <div class="form-text mt-1">Capture or upload a clear proof of refund transaction.</div>
                    </div>
                    <div class="refund-modal-card">
                        <div class="refund-modal-title"><i class="fas fa-money-check-alt"></i> Payout Details</div>
                        <div class="refund-modal-grid">
                            <div class="full">
                                <label class="form-label mb-1">Payout Channel <span class="text-danger">*</span></label>
                                <select id="swalPayoutChannel" class="swal2-select">
                                    <option value="">Select payout channel</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="cash_pickup">Cash Pickup</option>
                                    <option value="card_reversal">Card Reversal</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="full">
                                <label class="form-label mb-1">Transaction Reference <span class="text-danger">*</span></label>
                                <input id="swalPayoutReference" class="swal2-input" placeholder="Ex: TXN-8475123">
                            </div>
                            <div class="full">
                                <label class="form-label mb-1">Receiver Account Name</label>
                                <input id="swalPayoutAccountName" class="swal2-input" placeholder="Ex: Juan Dela Cruz">
                            </div>
                            <div class="full">
                                <label class="form-label mb-1">Receiver Account (Masked)</label>
                                <input id="swalPayoutAccountMasked" class="swal2-input" placeholder="Ex: GCash 09****1234">
                            </div>
                        </div>
                        <div class="refund-modal-divider"></div>
                        <div class="refund-modal-title"><i class="fas fa-signature"></i> Finance Signature <span class="text-danger">*</span></div>
                        <div class="refund-signature-box">
                            <canvas id="swalFinanceSignaturePad" class="refund-signature-canvas" width="760" height="180"></canvas>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalClearFinanceSignature">Clear Signature</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="swalUploadFinanceSignature"><i class="fas fa-upload"></i> Upload Signature Photo</button>
                        </div>
                        <input type="file" id="swalFinanceSignatureFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <div class="form-text mt-1" id="swalSignatureStatusText">Draw signature or upload signature photo.</div>
                    </div>
                </div>
            ` : '';

            Swal.fire({
                title: titleText || 'Proceed with finance decision?',
                html: `
                    ${completeRefundIntro}
                    <div class="${isCompleteRefund ? 'refund-modal-card' : ''}">
                        <label class="form-label mb-1">Finance Notes</label>
                        <textarea id="swalFinanceNotes" class="swal2-textarea" placeholder="${notesPlaceholder}"></textarea>
                    </div>
                    ${decisionSignatureHtml}
                    ${payoutFieldsHtml}
                `,
                showCancelButton: true,
                confirmButtonText: 'Confirm',
                confirmButtonColor: action.includes('reject') ? '#dc3545' : (isCompleteRefund ? '#0d6efd' : '#198754'),
                focusConfirm: false,
                customClass: (isCompleteRefund || requiresDecisionSignature) ? { popup: 'refund-swal-popup' } : {},
                didOpen: () => {
                    if (requiresDecisionSignature) {
                        const signatureCanvas = document.getElementById('swalDecisionSignaturePad');
                        const clearSignatureBtn = document.getElementById('swalClearDecisionSignature');
                        const uploadSignatureBtn = document.getElementById('swalUploadDecisionSignature');
                        const signatureFileInput = document.getElementById('swalDecisionSignatureFile');
                        const signatureStatusText = document.getElementById('swalDecisionSignatureStatus');

                        decisionSignatureCanvasRef = signatureCanvas;
                        if (signatureCanvas) {
                            const sigCtx = signatureCanvas.getContext('2d');
                            sigCtx.lineWidth = 2.2;
                            sigCtx.lineCap = 'round';
                            sigCtx.lineJoin = 'round';
                            sigCtx.strokeStyle = '#111827';

                            let drawing = false;
                            const getPoint = (event) => {
                                const rect = signatureCanvas.getBoundingClientRect();
                                const evt = event.touches ? event.touches[0] : event;
                                const scaleX = signatureCanvas.width / rect.width;
                                const scaleY = signatureCanvas.height / rect.height;
                                return {
                                    x: (evt.clientX - rect.left) * scaleX,
                                    y: (evt.clientY - rect.top) * scaleY
                                };
                            };

                            const startDraw = (event) => {
                                event.preventDefault();
                                drawing = true;
                                const p = getPoint(event);
                                sigCtx.beginPath();
                                sigCtx.moveTo(p.x, p.y);
                            };

                            const draw = (event) => {
                                if (!drawing) return;
                                event.preventDefault();
                                const p = getPoint(event);
                                sigCtx.lineTo(p.x, p.y);
                                sigCtx.stroke();
                                decisionSignatureHasInk = true;
                                uploadedDecisionSignatureFile = null;
                                if (signatureStatusText) signatureStatusText.textContent = 'Signature captured.';
                            };

                            const endDraw = () => {
                                drawing = false;
                            };

                            signatureCanvas.addEventListener('mousedown', startDraw);
                            signatureCanvas.addEventListener('mousemove', draw);
                            signatureCanvas.addEventListener('mouseup', endDraw);
                            signatureCanvas.addEventListener('mouseleave', endDraw);
                            signatureCanvas.addEventListener('touchstart', startDraw, { passive: false });
                            signatureCanvas.addEventListener('touchmove', draw, { passive: false });
                            signatureCanvas.addEventListener('touchend', endDraw);
                        }

                        if (clearSignatureBtn) {
                            clearSignatureBtn.addEventListener('click', () => {
                                if (!decisionSignatureCanvasRef) return;
                                const sigCtx = decisionSignatureCanvasRef.getContext('2d');
                                sigCtx.clearRect(0, 0, decisionSignatureCanvasRef.width, decisionSignatureCanvasRef.height);
                                decisionSignatureHasInk = false;
                                uploadedDecisionSignatureFile = null;
                                if (signatureFileInput) signatureFileInput.value = '';
                                if (signatureStatusText) signatureStatusText.textContent = 'Signature cleared.';
                            });
                        }

                        if (uploadSignatureBtn) {
                            uploadSignatureBtn.addEventListener('click', () => signatureFileInput?.click());
                        }
                        if (signatureFileInput) {
                            signatureFileInput.addEventListener('change', (e) => {
                                const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                                if (!file) return;
                                uploadedDecisionSignatureFile = file;
                                decisionSignatureHasInk = false;
                                if (decisionSignatureCanvasRef) {
                                    const sigCtx = decisionSignatureCanvasRef.getContext('2d');
                                    sigCtx.clearRect(0, 0, decisionSignatureCanvasRef.width, decisionSignatureCanvasRef.height);
                                }
                                if (signatureStatusText) signatureStatusText.textContent = `Uploaded signature file: ${file.name}`;
                            });
                        }
                    }

                    if (!isCompleteRefund) {
                        return;
                    }

                    const proofPlaceholder = document.getElementById('swalRefundProofPlaceholder');
                    const proofVideo = document.getElementById('swalRefundProofVideo');
                    const proofPreview = document.getElementById('swalRefundProofPreview');
                    const proofFileInput = document.getElementById('swalPayoutProofFile');
                    const startCameraBtn = document.getElementById('swalStartProofCamera');
                    const capturePhotoBtn = document.getElementById('swalCaptureProofPhoto');
                    const stopCameraBtn = document.getElementById('swalStopProofCamera');
                    const uploadProofBtn = document.getElementById('swalUploadProofPhoto');
                    const signatureCanvas = document.getElementById('swalFinanceSignaturePad');
                    const clearSignatureBtn = document.getElementById('swalClearFinanceSignature');
                    const uploadSignatureBtn = document.getElementById('swalUploadFinanceSignature');
                    const signatureFileInput = document.getElementById('swalFinanceSignatureFile');
                    const signatureStatusText = document.getElementById('swalSignatureStatusText');

                    signatureCanvasRef = signatureCanvas;

                    const setProofPreview = (src) => {
                        if (!proofPreview) return;
                        proofPreview.src = src;
                        proofPreview.style.display = 'block';
                        if (proofPlaceholder) proofPlaceholder.style.display = 'none';
                        if (proofVideo) proofVideo.style.display = 'none';
                    };

                    if (proofPlaceholder) {
                        proofPlaceholder.addEventListener('click', () => proofFileInput?.click());
                    }
                    if (uploadProofBtn) {
                        uploadProofBtn.addEventListener('click', () => proofFileInput?.click());
                    }
                    if (proofFileInput) {
                        proofFileInput.addEventListener('change', (e) => {
                            const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                            if (!file) return;
                            uploadedProofFile = file;
                            capturedProofBlob = null;
                            stopProofCamera();
                            const url = URL.createObjectURL(file);
                            setProofPreview(url);
                        });
                    }

                    if (startCameraBtn) {
                        startCameraBtn.addEventListener('click', async () => {
                            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                                Swal.showValidationMessage('Camera is not available in this browser.');
                                return;
                            }
                            try {
                                proofCameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                                if (proofVideo) {
                                    proofVideo.srcObject = proofCameraStream;
                                    proofVideo.style.display = 'block';
                                }
                                if (proofPlaceholder) proofPlaceholder.style.display = 'none';
                                if (proofPreview) proofPreview.style.display = 'none';
                                startCameraBtn.style.display = 'none';
                                if (capturePhotoBtn) capturePhotoBtn.style.display = 'inline-block';
                                if (stopCameraBtn) stopCameraBtn.style.display = 'inline-block';
                            } catch (error) {
                                Swal.showValidationMessage('Unable to access camera. Please upload a photo instead.');
                            }
                        });
                    }

                    if (capturePhotoBtn) {
                        capturePhotoBtn.addEventListener('click', async () => {
                            if (!proofVideo || !proofCameraStream) return;
                            const tempCanvas = document.createElement('canvas');
                            tempCanvas.width = proofVideo.videoWidth || 1280;
                            tempCanvas.height = proofVideo.videoHeight || 720;
                            const tempCtx = tempCanvas.getContext('2d');
                            tempCtx.drawImage(proofVideo, 0, 0, tempCanvas.width, tempCanvas.height);
                            const blob = await new Promise((resolve) => tempCanvas.toBlob(resolve, 'image/jpeg', 0.92));
                            if (!blob) {
                                Swal.showValidationMessage('Failed to capture photo. Please try again.');
                                return;
                            }
                            capturedProofBlob = blob;
                            uploadedProofFile = null;
                            setProofPreview(URL.createObjectURL(blob));
                            capturePhotoBtn.innerHTML = '<i class="fas fa-check"></i> Captured';
                            capturePhotoBtn.classList.add('btn-success');
                        });
                    }

                    if (stopCameraBtn) {
                        stopCameraBtn.addEventListener('click', () => {
                            stopProofCamera();
                            if (proofPlaceholder && (!proofPreview || proofPreview.style.display === 'none')) {
                                proofPlaceholder.style.display = 'flex';
                            }
                        });
                    }

                    if (signatureCanvas) {
                        const sigCtx = signatureCanvas.getContext('2d');
                        sigCtx.lineWidth = 2.2;
                        sigCtx.lineCap = 'round';
                        sigCtx.lineJoin = 'round';
                        sigCtx.strokeStyle = '#111827';

                        let drawing = false;
                        const getPoint = (event) => {
                            const rect = signatureCanvas.getBoundingClientRect();
                            const evt = event.touches ? event.touches[0] : event;
                            const scaleX = signatureCanvas.width / rect.width;
                            const scaleY = signatureCanvas.height / rect.height;
                            return {
                                x: (evt.clientX - rect.left) * scaleX,
                                y: (evt.clientY - rect.top) * scaleY
                            };
                        };

                        const startDraw = (event) => {
                            event.preventDefault();
                            drawing = true;
                            const p = getPoint(event);
                            sigCtx.beginPath();
                            sigCtx.moveTo(p.x, p.y);
                        };

                        const draw = (event) => {
                            if (!drawing) return;
                            event.preventDefault();
                            const p = getPoint(event);
                            sigCtx.lineTo(p.x, p.y);
                            sigCtx.stroke();
                            signatureHasInk = true;
                            uploadedSignatureFile = null;
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature captured.';
                        };

                        const endDraw = () => {
                            drawing = false;
                        };

                        signatureCanvas.addEventListener('mousedown', startDraw);
                        signatureCanvas.addEventListener('mousemove', draw);
                        signatureCanvas.addEventListener('mouseup', endDraw);
                        signatureCanvas.addEventListener('mouseleave', endDraw);
                        signatureCanvas.addEventListener('touchstart', startDraw, { passive: false });
                        signatureCanvas.addEventListener('touchmove', draw, { passive: false });
                        signatureCanvas.addEventListener('touchend', endDraw);
                    }

                    if (clearSignatureBtn) {
                        clearSignatureBtn.addEventListener('click', () => {
                            if (!signatureCanvasRef) return;
                            const sigCtx = signatureCanvasRef.getContext('2d');
                            sigCtx.clearRect(0, 0, signatureCanvasRef.width, signatureCanvasRef.height);
                            signatureHasInk = false;
                            uploadedSignatureFile = null;
                            if (signatureFileInput) signatureFileInput.value = '';
                            if (signatureStatusText) signatureStatusText.textContent = 'Signature cleared.';
                        });
                    }

                    if (uploadSignatureBtn) {
                        uploadSignatureBtn.addEventListener('click', () => signatureFileInput?.click());
                    }

                    if (signatureFileInput) {
                        signatureFileInput.addEventListener('change', (e) => {
                            const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                            if (!file) return;
                            uploadedSignatureFile = file;
                            if (signatureCanvasRef) {
                                const sigCtx = signatureCanvasRef.getContext('2d');
                                sigCtx.clearRect(0, 0, signatureCanvasRef.width, signatureCanvasRef.height);
                            }
                            signatureHasInk = false;
                            if (signatureStatusText) {
                                signatureStatusText.textContent = `Uploaded signature file: ${file.name}`;
                            }
                        });
                    }
                },
                willClose: () => {
                    stopProofCamera();
                },
                preConfirm: async () => {
                    const notes = (document.getElementById('swalFinanceNotes')?.value || '').trim();
                    const payoutChannel = (document.getElementById('swalPayoutChannel')?.value || '').trim();
                    const payoutReference = (document.getElementById('swalPayoutReference')?.value || '').trim();
                    const payoutAccountName = (document.getElementById('swalPayoutAccountName')?.value || '').trim();
                    const payoutAccountMasked = (document.getElementById('swalPayoutAccountMasked')?.value || '').trim();

                    if (requireReason && !notes) {
                        Swal.showValidationMessage('Please provide a reason for rejection.');
                        return false;
                    }

                    if (isCompleteRefund) {
                        if (!payoutChannel) {
                            Swal.showValidationMessage('Please select a payout channel.');
                            return false;
                        }
                        if (!payoutReference) {
                            Swal.showValidationMessage('Transaction reference is required.');
                            return false;
                        }
                        let payoutProofFile = uploadedProofFile;
                        if (!payoutProofFile && capturedProofBlob) {
                            payoutProofFile = new File([capturedProofBlob], `refund_proof_${Date.now()}.jpg`, { type: capturedProofBlob.type || 'image/jpeg' });
                        }
                        if (!payoutProofFile) {
                            Swal.showValidationMessage('Please capture or upload the proof of transaction photo.');
                            return false;
                        }
                        let financeSignatureFile = uploadedSignatureFile;
                        if (!financeSignatureFile && signatureCanvasRef && signatureHasInk) {
                            const signatureBlob = await new Promise((resolve) => signatureCanvasRef.toBlob(resolve, 'image/png'));
                            if (signatureBlob) {
                                financeSignatureFile = new File([signatureBlob], `finance_signature_${Date.now()}.png`, { type: 'image/png' });
                            }
                        }
                        if (!financeSignatureFile) {
                            Swal.showValidationMessage('Please draw or upload the finance signature.');
                            return false;
                        }
                        const combinedSize = Number(payoutProofFile.size || 0) + Number(financeSignatureFile.size || 0);
                        if (!payoutProofFile.type.startsWith('image/')) {
                            Swal.showValidationMessage('Proof file must be an image (JPG, PNG, or WEBP).');
                            return false;
                        }
                        if (!financeSignatureFile.type.startsWith('image/')) {
                            Swal.showValidationMessage('Signature file must be an image (JPG, PNG, or WEBP).');
                            return false;
                        }
                        if (payoutProofFile.size > financeSingleUploadLimit || financeSignatureFile.size > financeSingleUploadLimit) {
                            Swal.showValidationMessage(`Each uploaded image must be ${formatBytes(financeSingleUploadLimit)} or below.`);
                            return false;
                        }
                        if (combinedSize > financeTotalUploadLimit) {
                            Swal.showValidationMessage(`Combined upload is too large. Keep total around ${formatBytes(financeTotalUploadLimit)} or less.`);
                            return false;
                        }
                        return {
                            notes,
                            signature: 'image_signature',
                            payoutChannel,
                            payoutReference,
                            payoutAccountName,
                            payoutAccountMasked,
                            payoutProofFile,
                            financeSignatureFile
                        };
                    }

                    let decisionSignatureFile = null;
                    if (requiresDecisionSignature) {
                        decisionSignatureFile = uploadedDecisionSignatureFile;
                        if (!decisionSignatureFile && decisionSignatureCanvasRef && decisionSignatureHasInk) {
                            const signatureBlob = await new Promise((resolve) => decisionSignatureCanvasRef.toBlob(resolve, 'image/png'));
                            if (signatureBlob) {
                                decisionSignatureFile = new File([signatureBlob], `finance_decision_signature_${Date.now()}.png`, { type: 'image/png' });
                            }
                        }
                        if (!decisionSignatureFile) {
                            Swal.showValidationMessage('Please draw or upload the finance signature.');
                            return false;
                        }
                        if (!decisionSignatureFile.type.startsWith('image/')) {
                            Swal.showValidationMessage('Signature file must be an image (JPG, PNG, or WEBP).');
                            return false;
                        }
                        if (decisionSignatureFile.size > financeSingleUploadLimit) {
                            Swal.showValidationMessage(`Signature image must be ${formatBytes(financeSingleUploadLimit)} or below.`);
                            return false;
                        }
                    }
                    return {
                        notes,
                        signature: 'image_signature',
                        payoutChannel,
                        payoutReference,
                        payoutAccountName,
                        payoutAccountMasked,
                        payoutProofFile: null,
                        financeSignatureFile: null,
                        decisionSignatureFile
                    };
                }
            }).then((result) => {
                if (!result.isConfirmed || !result.value) return;
                form.querySelector('input[name="finance_decision_action"]').value = action;
                form.querySelector('input[name="decision_notes"]').value = result.value.notes || '';
                const signatureInput = form.querySelector('input[name="decision_signature"]');
                if (signatureInput) signatureInput.value = result.value.signature || '';
                const payoutChannelInput = form.querySelector('input[name="payout_channel"]');
                if (payoutChannelInput) payoutChannelInput.value = result.value.payoutChannel || '';
                const payoutReferenceInput = form.querySelector('input[name="payout_reference"]');
                if (payoutReferenceInput) payoutReferenceInput.value = result.value.payoutReference || '';
                const payoutAccountNameInput = form.querySelector('input[name="payout_account_name"]');
                if (payoutAccountNameInput) payoutAccountNameInput.value = result.value.payoutAccountName || '';
                const payoutAccountMaskedInput = form.querySelector('input[name="payout_account_masked"]');
                if (payoutAccountMaskedInput) payoutAccountMaskedInput.value = result.value.payoutAccountMasked || '';
                if (isCompleteRefund) {
                    submitCompleteRefundWithFiles(form, result.value);
                    return;
                }
                if (requiresDecisionSignature) {
                    submitFinanceDecisionWithSignature(form, action, result.value);
                    return;
                }
                form.submit();
            });
        }

        async function submitFinanceDecisionWithSignature(form, action, values) {
            const formData = new FormData();
            formData.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            formData.append('finance_decision_action', action);
            formData.append('decision_notes', values.notes || '');
            formData.append('decision_signature', 'image_signature');
            const cancellationId = form.querySelector('input[name="cancellation_id"]')?.value || '';
            const refundId = form.querySelector('input[name="refund_id"]')?.value || '';
            if (cancellationId) formData.append('cancellation_id', cancellationId);
            if (refundId) formData.append('refund_id', refundId);
            if (values.decisionSignatureFile) {
                formData.append('decision_signature_file', values.decisionSignatureFile);
            }

            Swal.fire({
                title: 'Submitting decision...',
                text: 'Uploading signature and processing finance decision...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const response = await fetch(`finance.php?finance_decision_action=${encodeURIComponent(action)}`, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Finance-Action': action,
                        'Accept': 'application/json'
                    }
                });

                const rawText = await response.text();
                let payload = null;
                try {
                    payload = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload?.message || 'Failed to process finance decision.');
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Decision Saved',
                    text: payload.message || 'Finance decision has been recorded.',
                    timer: 1500,
                    showConfirmButton: false
                });
                window.location.href = 'finance.php#decision-center';
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: error?.message || 'Unable to process finance decision.'
                });
            }
        }

        async function submitCompleteRefundWithFiles(form, values) {
            const formData = new FormData();
            formData.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            formData.append('finance_decision_action', 'complete_refund');
            formData.append('refund_id', form.querySelector('input[name="refund_id"]')?.value || '');
            formData.append('decision_notes', values.notes || '');
            formData.append('decision_signature', values.signature || '');
            formData.append('payout_channel', values.payoutChannel || '');
            formData.append('payout_reference', values.payoutReference || '');
            formData.append('payout_account_name', values.payoutAccountName || '');
            formData.append('payout_account_masked', values.payoutAccountMasked || '');
            if (values.payoutProofFile) {
                formData.append('payout_proof_file', values.payoutProofFile);
            }
            if (values.financeSignatureFile) {
                formData.append('finance_signature_file', values.financeSignatureFile);
            }

            Swal.fire({
                title: 'Submitting refund payout...',
                text: 'Uploading proof and processing refund completion...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            let timeoutId = null;
            try {
                const controller = new AbortController();
                timeoutId = setTimeout(() => controller.abort(), 60000);
                const response = await fetch('finance.php?finance_decision_action=complete_refund', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Finance-Action': 'complete_refund',
                        'Accept': 'application/json'
                    },
                    signal: controller.signal
                });
                clearTimeout(timeoutId);

                const rawText = await response.text();
                let payload = null;
                try {
                    payload = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    if (payload?.message) {
                        throw new Error(payload.message);
                    }
                    const plainSnippet = (rawText || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                    throw new Error(plainSnippet || 'Failed to submit refund payout.');
                }

                await Swal.fire({
                    icon: 'success',
                    title: 'Refund Completed',
                    text: payload.message || 'Refund payout has been completed.',
                    timer: 1800,
                    showConfirmButton: false
                });
                window.location.href = 'finance.php#decision-center';
            } catch (error) {
                const message = error?.name === 'AbortError'
                    ? 'Request timed out. Please check your connection and try again.'
                    : (error?.message || 'Unable to submit complete refund action.');
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: message
                });
            } finally {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
            }
        }

        <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: <?= json_encode($_SESSION['success']) ?>,
            timer: 2400,
            showConfirmButton: false
        });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: <?= json_encode($_SESSION['error']) ?>
        });
        <?php unset($_SESSION['error']); endif; ?>
    </script>
</body>
</html>
