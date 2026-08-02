<?php
require_once __DIR__ . '/module_common.php';

$current_user_id = $current_admin_id;

function ownerTableExists($conn, $table_name) {
    $safe_name = mysqli_real_escape_string($conn, trim((string)$table_name));
    if ($safe_name === '') {
        return false;
    }
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_name}'");
    return $result && mysqli_num_rows($result) > 0;
}

function ownerColumnExists($conn, $table_name, $column_name) {
    static $column_cache = [];
    $table_name = trim((string)$table_name);
    $column_name = trim((string)$column_name);
    $cache_key = $table_name . '.' . $column_name;
    if ($table_name === '' || $column_name === '') {
        return false;
    }
    if (array_key_exists($cache_key, $column_cache)) {
        return $column_cache[$cache_key];
    }
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $column_cache[$cache_key] = ($result && mysqli_num_rows($result) > 0);
}

function ownerScalar($conn, $query, $default = 0) {
    $result = mysqli_query($conn, $query);
    if (!$result) {
        return $default;
    }
    $row = mysqli_fetch_row($result);
    return $row[0] ?? $default;
}

function ownerRows($conn, $query) {
    $result = mysqli_query($conn, $query);
    if (!$result) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function ownerApprovedPartnerExists($conn, $partner_user_id) {
    $partner_user_id = (int)$partner_user_id;
    if ($partner_user_id <= 0 || !ownerTableExists($conn, 'franchise_applications')) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT fa.user_id
         FROM franchise_applications fa
         WHERE fa.user_id = ?
           AND fa.status = 'approved'
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $partner_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$exists;
}

function ownerUpsertPartnerAccessStatus(
    $conn,
    $partner_user_id,
    $target_status,
    $control_note,
    $current_user_id,
    $has_user_control_notes,
    $has_user_control_at,
    $has_user_control_by,
    &$error_message
) {
    $partner_user_id = (int)$partner_user_id;
    $target_status = strtolower(trim((string)$target_status));
    $allowed_statuses = ['active', 'restricted', 'suspended', 'banned'];
    if ($partner_user_id <= 0 || !in_array($target_status, $allowed_statuses, true)) {
        $error_message = 'Invalid partner status request.';
        return false;
    }

    $is_active = in_array($target_status, ['active', 'restricted'], true) ? 1 : 0;
    $stored_note = trim((string)$control_note);
    $stored_note = $stored_note !== '' ? substr($stored_note, 0, 1000) : null;
    $restricted_at = $target_status === 'active' ? null : date('Y-m-d H:i:s');
    $restricted_by = $target_status === 'active' ? null : (int)$current_user_id;
    $remember_token = null;
    $remember_expires = null;

    $has_full_partner_control_schema = $has_user_control_notes && $has_user_control_at && $has_user_control_by;
    $update_sql = "UPDATE users
                   SET account_control_status = ?,
                       is_active = ?,
                       remember_token = ?,
                       remember_expires = ?";
    if ($has_full_partner_control_schema) {
        $update_sql .= ",
                       access_restriction_notes = ?,
                       access_restricted_at = ?,
                       access_restricted_by = ?";
    }
    $update_sql .= " WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    if (!$update_stmt) {
        $error_message = 'Unable to prepare partner account update.';
        return false;
    }

    if ($has_full_partner_control_schema) {
        mysqli_stmt_bind_param(
            $update_stmt,
            "sissssii",
            $target_status,
            $is_active,
            $remember_token,
            $remember_expires,
            $stored_note,
            $restricted_at,
            $restricted_by,
            $partner_user_id
        );
    } else {
        mysqli_stmt_bind_param(
            $update_stmt,
            "sissi",
            $target_status,
            $is_active,
            $remember_token,
            $remember_expires,
            $partner_user_id
        );
    }

    $update_ok = mysqli_stmt_execute($update_stmt);
    $update_error = mysqli_stmt_error($update_stmt);
    mysqli_stmt_close($update_stmt);
    if (!$update_ok) {
        $error_message = 'Unable to update partner account status: ' . $update_error;
        return false;
    }
    return true;
}

function ownerEnsurePartnerWarningsTable($conn) {
    if (ownerTableExists($conn, 'partner_warnings')) {
        return true;
    }

    $create_sql = "CREATE TABLE IF NOT EXISTS `partner_warnings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `partner_user_id` INT UNSIGNED NOT NULL,
        `warning_subject` VARCHAR(180) NOT NULL,
        `warning_message` TEXT NOT NULL,
        `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        `warning_status` ENUM('active','resolved') NOT NULL DEFAULT 'active',
        `issued_by` INT UNSIGNED NULL,
        `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `resolved_at` DATETIME NULL DEFAULT NULL,
        `resolved_by` INT UNSIGNED NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_partner_warning_partner_status` (`partner_user_id`, `warning_status`),
        KEY `idx_partner_warning_severity_status` (`severity`, `warning_status`),
        KEY `idx_partner_warning_issued_at` (`issued_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @mysqli_query($conn, $create_sql);
    return ownerTableExists($conn, 'partner_warnings');
}

$order_status_scope = "'confirmed', 'preparing', 'delivered', 'completed'";
$current_month_label = date('F Y');
$platform_commission_percent = 10.00;
$platform_commission_rate = $platform_commission_percent / 100;
$has_users = ownerTableExists($conn, 'users');
$has_orders = ownerTableExists($conn, 'orders');
$has_order_items = ownerTableExists($conn, 'order_items');
$has_products = ownerTableExists($conn, 'products');
$has_commission_rules = ownerTableExists($conn, 'commission_rules');
$has_partner_settlements = ownerTableExists($conn, 'partner_settlements');
$has_product_reviews = ownerTableExists($conn, 'product_reviews');
$has_delivery_reviews = ownerTableExists($conn, 'delivery_reviews');
$has_employees = ownerTableExists($conn, 'employees');
$has_expenses = ownerTableExists($conn, 'expenses');
$has_logistics = ownerTableExists($conn, 'logistics_tracking');
$has_applications = ownerTableExists($conn, 'franchise_applications');
$has_cancellations = ownerTableExists($conn, 'cancellations');
$has_refunds = ownerTableExists($conn, 'refunds');
$has_roles = ownerTableExists($conn, 'roles');
$has_permissions = ownerTableExists($conn, 'permissions');
$has_store_locations = ownerTableExists($conn, 'store_locations');
$has_user_control_status = ownerColumnExists($conn, 'users', 'account_control_status');
$has_user_control_notes = ownerColumnExists($conn, 'users', 'access_restriction_notes');
$has_user_control_at = ownerColumnExists($conn, 'users', 'access_restricted_at');
$has_user_control_by = ownerColumnExists($conn, 'users', 'access_restricted_by');
$has_partner_warnings = ownerEnsurePartnerWarningsTable($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['partner_warning_action'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!$has_partner_warnings) {
            $_SESSION['error'] = 'Partner warning module is unavailable. Run Tenant Scope Migration and try again.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $target_partner_user_id = (int)($_POST['partner_user_id'] ?? 0);
        $warning_subject = trim((string)($_POST['warning_subject'] ?? ''));
        $warning_message = trim((string)($_POST['warning_message'] ?? ''));
        $warning_severity = strtolower(trim((string)($_POST['warning_severity'] ?? 'medium')));
        $warning_followup_status = strtolower(trim((string)($_POST['warning_followup_status'] ?? 'none')));
        $warn_and_restrict = isset($_POST['warn_and_restrict']) && (string)($_POST['warn_and_restrict']) === '1';
        if ($warn_and_restrict && $warning_followup_status === 'none') {
            $warning_followup_status = 'restricted';
        }
        $allowed_severity = ['low', 'medium', 'high', 'critical'];
        $allowed_followup_status = ['none', 'restricted', 'suspended'];

        if ($warning_subject === '') {
            $warning_subject = 'Platform Policy Violation Notice';
        }
        $warning_subject = substr($warning_subject, 0, 180);
        $warning_message = substr($warning_message, 0, 2000);

        if (
            $target_partner_user_id <= 0
            || !in_array($warning_severity, $allowed_severity, true)
            || !in_array($warning_followup_status, $allowed_followup_status, true)
            || strlen($warning_message) < 10
        ) {
            $_SESSION['error'] = 'Invalid warning request. Please include a clear violation reason (at least 10 characters).';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!ownerApprovedPartnerExists($conn, $target_partner_user_id)) {
            $_SESSION['error'] = 'Selected account is not an approved business partner.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO partner_warnings
             (partner_user_id, warning_subject, warning_message, severity, warning_status, issued_by, issued_at)
             VALUES (?, ?, ?, ?, 'active', ?, NOW())"
        );
        if (!$insert_stmt) {
            $_SESSION['error'] = 'Unable to create warning notice.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        mysqli_stmt_bind_param(
            $insert_stmt,
            "isssi",
            $target_partner_user_id,
            $warning_subject,
            $warning_message,
            $warning_severity,
            $current_user_id
        );
        $insert_ok = mysqli_stmt_execute($insert_stmt);
        $insert_error = mysqli_stmt_error($insert_stmt);
        mysqli_stmt_close($insert_stmt);

        if (!$insert_ok) {
            $_SESSION['error'] = 'Unable to issue warning notice: ' . $insert_error;
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $status_note = $warning_subject . ': ' . substr($warning_message, 0, 400);
        if ($warning_followup_status !== 'none' && $has_user_control_status) {
            $status_update_error = '';
            $status_update_ok = ownerUpsertPartnerAccessStatus(
                $conn,
                $target_partner_user_id,
                $warning_followup_status,
                $status_note,
                $current_user_id,
                $has_user_control_notes,
                $has_user_control_at,
                $has_user_control_by,
                $status_update_error
            );
            if ($status_update_ok) {
                $_SESSION['success'] = 'Warning issued and partner account was set to ' . ucfirst($warning_followup_status) . '.';
            } else {
                $_SESSION['error'] = 'Warning was saved, but follow-up status update failed: ' . $status_update_error;
            }
        } elseif ($warning_followup_status !== 'none' && !$has_user_control_status) {
            $_SESSION['success'] = 'Warning issued successfully. Follow-up status change could not be applied because partner account controls are not yet enabled.';
        } else {
            $_SESSION['success'] = 'Warning issued successfully for business partner.';
        }

        header('Location: super_admin_dashboard.php');
        exit;
    }

    if (isset($_POST['partner_warning_resolve_action'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!$has_partner_warnings) {
            $_SESSION['error'] = 'Partner warning module is unavailable. Run Tenant Scope Migration and try again.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $target_partner_user_id = (int)($_POST['partner_user_id'] ?? 0);
        $warning_id = (int)($_POST['warning_id'] ?? 0);

        if ($target_partner_user_id <= 0 || $warning_id <= 0) {
            $_SESSION['error'] = 'Invalid warning resolution request.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!ownerApprovedPartnerExists($conn, $target_partner_user_id)) {
            $_SESSION['error'] = 'Selected account is not an approved business partner.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $resolve_stmt = mysqli_prepare(
            $conn,
            "UPDATE partner_warnings
             SET warning_status = 'resolved',
                 resolved_at = NOW(),
                 resolved_by = ?
             WHERE id = ?
               AND partner_user_id = ?
               AND warning_status = 'active'
             LIMIT 1"
        );
        if (!$resolve_stmt) {
            $_SESSION['error'] = 'Unable to prepare warning resolution action.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        mysqli_stmt_bind_param($resolve_stmt, "iii", $current_user_id, $warning_id, $target_partner_user_id);
        $resolve_ok = mysqli_stmt_execute($resolve_stmt);
        $resolve_error = mysqli_stmt_error($resolve_stmt);
        $resolve_count = mysqli_stmt_affected_rows($resolve_stmt);
        mysqli_stmt_close($resolve_stmt);

        if (!$resolve_ok) {
            $_SESSION['error'] = 'Failed to resolve selected warning: ' . $resolve_error;
        } elseif ($resolve_count > 0) {
            $_SESSION['success'] = 'Selected warning notice was resolved successfully.';
        } else {
            $_SESSION['error'] = 'No active warning matched the selected record. It may already be resolved.';
        }

        header('Location: super_admin_dashboard.php');
        exit;
    }

    if (isset($_POST['partner_control_action'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!$has_user_control_status) {
            $_SESSION['error'] = 'Partner account controls are not ready yet. Run Tenant Scope Migration first.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $target_partner_user_id = (int)($_POST['partner_user_id'] ?? 0);
        $target_status = strtolower(trim((string)($_POST['target_status'] ?? 'active')));
        $control_note = trim((string)($_POST['control_note'] ?? ''));
        $allowed_statuses = ['active', 'restricted', 'suspended', 'banned'];

        if ($target_partner_user_id <= 0 || !in_array($target_status, $allowed_statuses, true)) {
            $_SESSION['error'] = 'Invalid partner control request.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        if (!ownerApprovedPartnerExists($conn, $target_partner_user_id)) {
            $_SESSION['error'] = 'Selected account is not an approved business partner.';
            header('Location: super_admin_dashboard.php');
            exit;
        }

        $update_error = '';
        $update_ok = ownerUpsertPartnerAccessStatus(
            $conn,
            $target_partner_user_id,
            $target_status,
            $control_note,
            $current_user_id,
            $has_user_control_notes,
            $has_user_control_at,
            $has_user_control_by,
            $update_error
        );

        if ($update_ok) {
            $status_message = 'Partner account status updated to ' . ucfirst($target_status) . '.';
            if ($has_partner_warnings && $target_status === 'active') {
                $resolved_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE partner_warnings
                     SET warning_status = 'resolved',
                         resolved_at = NOW(),
                         resolved_by = ?
                     WHERE partner_user_id = ?
                       AND warning_status = 'active'"
                );
                if ($resolved_stmt) {
                    mysqli_stmt_bind_param($resolved_stmt, "ii", $current_user_id, $target_partner_user_id);
                    mysqli_stmt_execute($resolved_stmt);
                    $resolved_count = mysqli_stmt_affected_rows($resolved_stmt);
                    mysqli_stmt_close($resolved_stmt);
                    if ($resolved_count > 0) {
                        $status_message .= ' Cleared ' . number_format($resolved_count) . ' active warning(s).';
                    }
                }
            }
            $_SESSION['success'] = $status_message;
        } else {
            $_SESSION['error'] = $update_error;
        }

        header('Location: super_admin_dashboard.php');
        exit;
    }
}

$total_customers = $has_users ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM users WHERE user_type = 'customer'", 0) : 0;
$total_admins = $has_users ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM users WHERE user_type = 'admin'", 0) : 0;
$total_employees = $has_employees ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM employees", 0) : 0;
$approved_partners = $has_applications ? (int)ownerScalar($conn, "SELECT COUNT(DISTINCT user_id) FROM franchise_applications WHERE status = 'approved'", 0) : 0;
$pending_applications = $has_applications ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM franchise_applications WHERE status = 'pending'", 0) : 0;
$store_locations = $has_store_locations ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM store_locations WHERE is_active = 1", 0) : 0;
$today_orders = $has_orders ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE() AND is_archived = 0", 0) : 0;
$monthly_revenue = $has_orders ? (float)ownerScalar($conn, "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status IN ({$order_status_scope}) AND is_archived = 0", 0) : 0;
$monthly_expenses = $has_expenses ? (float)ownerScalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())", 0) : 0;
$net_income = $monthly_revenue - $monthly_expenses;
$low_stock_items = ($has_products && ownerTableExists($conn, 'inventory'))
    ? (int)ownerScalar($conn, "SELECT COUNT(DISTINCT i.product_id) FROM inventory i INNER JOIN products p ON p.id = i.product_id WHERE i.is_archived = 0 AND p.is_archived = 0 AND i.current_stock <= i.min_stock_level", 0)
    : 0;
$active_deliveries = $has_logistics ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM logistics_tracking WHERE current_status IN ('pending', 'assigned', 'picked_up', 'on_the_way', 'arriving')", 0) : 0;
$roles_count = $has_roles ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM roles WHERE is_active = 1", 0) : 0;
$permissions_count = $has_permissions ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM permissions", 0) : 0;
$partner_sales_this_month = 0.0;
$platform_commission_total = 0.0;
$partner_net_revenue_total = 0.0;
$avg_business_rating = 0.0;
$commission_metrics_are_actual = false;

if ($has_commission_rules) {
    $active_global_commission = ownerScalar(
        $conn,
        "SELECT commission_percent
         FROM commission_rules
         WHERE is_active = 1
           AND scope_type = 'global'
         ORDER BY effective_from DESC, id DESC
         LIMIT 1",
        $platform_commission_percent
    );
    $platform_commission_percent = (float)$active_global_commission;
    $platform_commission_rate = $platform_commission_percent / 100;
}

if ($has_applications && $has_orders && $has_order_items && $has_products) {
    $partner_sales_this_month = (float)ownerScalar(
        $conn,
        "SELECT COALESCE(SUM(oi.total), 0)
         FROM order_items oi
         INNER JOIN products p
            ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
         INNER JOIN orders o ON o.id = oi.order_id
         INNER JOIN franchise_applications fa ON fa.user_id = p.seller_id AND fa.status = 'approved'
         WHERE o.is_archived = 0
           AND o.status IN ({$order_status_scope})
           AND MONTH(o.created_at) = MONTH(CURDATE())
           AND YEAR(o.created_at) = YEAR(CURDATE())",
        0
    );
    $platform_commission_total = $partner_sales_this_month * $platform_commission_rate;
    $partner_net_revenue_total = $partner_sales_this_month - $platform_commission_total;
}

if ($has_partner_settlements) {
    $actual_settlement_rows = (int)ownerScalar(
        $conn,
        "SELECT COUNT(*)
         FROM partner_settlements
         WHERE settlement_status IN ('generated', 'approved', 'paid')",
        0
    );
    if ($actual_settlement_rows > 0) {
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        $settlement_month_query = "
            SELECT
                COALESCE(SUM(gross_sales), 0) AS gross_sales,
                COALESCE(SUM(commission_amount), 0) AS commission_amount,
                COALESCE(SUM(partner_payout_amount), 0) AS partner_payout_amount
            FROM partner_settlements
            WHERE settlement_status IN ('generated', 'approved', 'paid')
              AND period_end >= '{$month_start}'
              AND period_start <= '{$month_end}'
        ";
        $settlement_month_result = mysqli_query($conn, $settlement_month_query);
        $settlement_month_row = $settlement_month_result ? mysqli_fetch_assoc($settlement_month_result) : null;
        $partner_sales_this_month = (float)($settlement_month_row['gross_sales'] ?? $partner_sales_this_month);
        $platform_commission_total = (float)($settlement_month_row['commission_amount'] ?? $platform_commission_total);
        $partner_net_revenue_total = (float)($settlement_month_row['partner_payout_amount'] ?? $partner_net_revenue_total);
        $commission_metrics_are_actual = true;
    }
}

$recent_applications = ownerTableExists($conn, 'franchise_applications')
    ? ownerRows($conn, "SELECT fa.application_number, COALESCE(NULLIF(fa.business_name, ''), NULLIF(u.business_name, ''), u.full_name, 'Business Partner') AS business_name, COALESCE(u.full_name, 'Unknown Applicant') AS owner_name, fa.status, fa.created_at FROM franchise_applications fa LEFT JOIN users u ON u.id = fa.user_id ORDER BY fa.created_at DESC LIMIT 5")
    : [];

$business_performance_rows = [];
if ($has_applications && $has_users) {
    $partner_control_select_sql = ",
                'active' AS account_control_status,
                '' AS access_restriction_notes";
    if ($has_user_control_status) {
        $partner_control_select_sql = ",
                COALESCE(NULLIF(u.account_control_status, ''), 'active') AS account_control_status,
                " . ($has_user_control_notes ? "COALESCE(u.access_restriction_notes, '')" : "''") . " AS access_restriction_notes";
    }
    $settlement_select_sql = ",
                0 AS settlement_commission_amount,
                0 AS settlement_partner_payout,
                0 AS settlement_gross_sales,
                NULL AS last_paid_at,
                0 AS settlement_count";
    $settlement_join_sql = "";

    if ($has_partner_settlements) {
        $settlement_select_sql = ",
                COALESCE(settlements.total_commission_amount, 0) AS settlement_commission_amount,
                COALESCE(settlements.total_partner_payout, 0) AS settlement_partner_payout,
                COALESCE(settlements.total_settlement_gross, 0) AS settlement_gross_sales,
                settlements.last_paid_at,
                COALESCE(settlements.settlement_count, 0) AS settlement_count";
        $settlement_join_sql = "
         LEFT JOIN (
            SELECT
                partner_user_id,
                COUNT(*) AS settlement_count,
                COALESCE(SUM(gross_sales), 0) AS total_settlement_gross,
                COALESCE(SUM(commission_amount), 0) AS total_commission_amount,
                COALESCE(SUM(partner_payout_amount), 0) AS total_partner_payout,
                MAX(paid_at) AS last_paid_at
            FROM partner_settlements
            WHERE settlement_status IN ('generated', 'approved', 'paid')
            GROUP BY partner_user_id
         ) settlements ON settlements.partner_user_id = u.id";
    }

    $business_performance_rows = ownerRows(
        $conn,
        "SELECT u.id AS partner_user_id,
                COALESCE(NULLIF(fa.business_name, ''), NULLIF(u.business_name, ''), u.full_name, 'Partner Store') AS store_name,
                COALESCE(sales.order_count, 0) AS order_count,
                COALESCE(sales.delivered_orders, 0) AS delivered_orders,
                COALESCE(sales.gross_revenue, 0) AS gross_revenue,
                sales.last_order_at,
                COALESCE(ratings.avg_product_rating, 0) AS avg_product_rating,
                COALESCE(ratings.review_count, 0) AS review_count,
                COALESCE(delivery.avg_delivery_rating, 0) AS avg_delivery_rating,
                COALESCE(delivery.delivery_review_count, 0) AS delivery_review_count,
                COALESCE(ops.cancellations_count, 0) AS cancellations_count,
                COALESCE(ops.refunded_total, 0) AS refunded_total
                {$partner_control_select_sql}
                {$settlement_select_sql}
         FROM franchise_applications fa
         INNER JOIN users u ON u.id = fa.user_id
         LEFT JOIN (
            SELECT p.seller_id,
                   COUNT(DISTINCT o.id) AS order_count,
                   COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) AS delivered_orders,
                   COALESCE(SUM(oi.total), 0) AS gross_revenue,
                   MAX(o.created_at) AS last_order_at
            FROM products p
            INNER JOIN order_items oi
                ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE p.seller_id IS NOT NULL
              AND p.is_archived = 0
              AND o.is_archived = 0
              AND o.status IN ({$order_status_scope})
            GROUP BY p.seller_id
         ) sales ON sales.seller_id = u.id
         LEFT JOIN (
            SELECT p.seller_id,
                   ROUND(AVG(CASE WHEN pr.is_approved = 1 THEN pr.rating END), 2) AS avg_product_rating,
                   SUM(CASE WHEN pr.is_approved = 1 THEN 1 ELSE 0 END) AS review_count
            FROM products p
            LEFT JOIN product_reviews pr ON pr.product_id = p.id
            WHERE p.seller_id IS NOT NULL
              AND p.is_archived = 0
            GROUP BY p.seller_id
         ) ratings ON ratings.seller_id = u.id
         LEFT JOIN (
            SELECT seller_orders.seller_id,
                   ROUND(AVG(dr.rating), 2) AS avg_delivery_rating,
                   COUNT(DISTINCT dr.id) AS delivery_review_count
            FROM (
                SELECT DISTINCT p.seller_id, oi.order_id
                FROM products p
                INNER JOIN order_items oi
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                WHERE p.seller_id IS NOT NULL
            ) seller_orders
            INNER JOIN delivery_reviews dr ON dr.order_id = seller_orders.order_id
            GROUP BY seller_orders.seller_id
         ) delivery ON delivery.seller_id = u.id
         LEFT JOIN (
            SELECT seller_orders.seller_id,
                   COUNT(DISTINCT c.id) AS cancellations_count,
                   COALESCE(SUM(CASE WHEN r.refund_status IN ('Refund Approved', 'Refund Completed') THEN r.refund_amount ELSE 0 END), 0) AS refunded_total
            FROM (
                SELECT DISTINCT p.seller_id, oi.order_id
                FROM products p
                INNER JOIN order_items oi
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                WHERE p.seller_id IS NOT NULL
            ) seller_orders
            LEFT JOIN cancellations c ON c.order_id = seller_orders.order_id
            LEFT JOIN refunds r ON r.cancellation_id = c.id
            GROUP BY seller_orders.seller_id
         ) ops ON ops.seller_id = u.id
         {$settlement_join_sql}
         WHERE fa.status = 'approved'
         GROUP BY u.id, store_name, sales.order_count, sales.delivered_orders, sales.gross_revenue, sales.last_order_at, ratings.avg_product_rating, ratings.review_count, delivery.avg_delivery_rating, delivery.delivery_review_count, ops.cancellations_count, ops.refunded_total, account_control_status, access_restriction_notes, settlement_commission_amount, settlement_partner_payout, settlement_gross_sales, last_paid_at, settlement_count
         ORDER BY gross_revenue DESC, store_name ASC"
    );
}

$partner_warning_summary_map = [];
$partner_warning_options_map = [];
$recent_partner_warning_feed = [];
$partners_with_active_warnings = 0;
$active_partner_warnings_total = 0;
$high_severity_partner_warnings = 0;
if ($has_partner_warnings) {
    $warning_store_name_expr = "CONCAT('Partner #', pw.partner_user_id) AS store_name";
    $warning_issuer_expr = "'System Owner' AS issued_by_name";
    $warning_join_sql = "";

    if ($has_users && $has_applications) {
        $warning_store_name_expr = "COALESCE(NULLIF(fa.business_name, ''), NULLIF(partner_u.business_name, ''), partner_u.full_name, CONCAT('Partner #', pw.partner_user_id)) AS store_name";
        $warning_issuer_expr = "COALESCE(issuer_u.full_name, 'System Owner') AS issued_by_name";
        $warning_join_sql = "
            LEFT JOIN users partner_u ON partner_u.id = pw.partner_user_id
            LEFT JOIN (
                SELECT user_id, MAX(NULLIF(business_name, '')) AS business_name
                FROM franchise_applications
                WHERE status = 'approved'
                GROUP BY user_id
            ) fa ON fa.user_id = pw.partner_user_id
            LEFT JOIN users issuer_u ON issuer_u.id = pw.issued_by";
    } elseif ($has_users) {
        $warning_store_name_expr = "COALESCE(NULLIF(partner_u.business_name, ''), partner_u.full_name, CONCAT('Partner #', pw.partner_user_id)) AS store_name";
        $warning_issuer_expr = "COALESCE(issuer_u.full_name, 'System Owner') AS issued_by_name";
        $warning_join_sql = "
            LEFT JOIN users partner_u ON partner_u.id = pw.partner_user_id
            LEFT JOIN users issuer_u ON issuer_u.id = pw.issued_by";
    } elseif ($has_applications) {
        $warning_store_name_expr = "COALESCE(NULLIF(fa.business_name, ''), CONCAT('Partner #', pw.partner_user_id)) AS store_name";
        $warning_join_sql = "
            LEFT JOIN (
                SELECT user_id, MAX(NULLIF(business_name, '')) AS business_name
                FROM franchise_applications
                WHERE status = 'approved'
                GROUP BY user_id
            ) fa ON fa.user_id = pw.partner_user_id";
    }

    $warning_rows = ownerRows(
        $conn,
        "SELECT
            pw.id,
            pw.partner_user_id,
            pw.warning_subject,
            pw.warning_message,
            pw.severity,
            pw.warning_status,
            pw.issued_at,
            {$warning_store_name_expr},
            {$warning_issuer_expr}
         FROM partner_warnings pw
         {$warning_join_sql}
         WHERE pw.warning_status = 'active'
         ORDER BY pw.issued_at DESC
         LIMIT 300"
    );

    foreach ($warning_rows as $warning_row) {
        $partner_id = (int)($warning_row['partner_user_id'] ?? 0);
        if ($partner_id <= 0) {
            continue;
        }

        if (!isset($partner_warning_summary_map[$partner_id])) {
            $partner_warning_summary_map[$partner_id] = [
                'active_warning_count' => 0,
                'high_warning_count' => 0,
                'latest_warning_subject' => '',
                'latest_warning_message' => '',
                'latest_warning_severity' => 'medium',
                'latest_warning_issued_at' => '',
                'latest_warning_store_name' => (string)($warning_row['store_name'] ?? 'Partner Store')
            ];
        }

        $severity = strtolower(trim((string)($warning_row['severity'] ?? 'medium')));
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $severity = 'medium';
        }

        $partner_warning_summary_map[$partner_id]['active_warning_count']++;
        $active_partner_warnings_total++;
        if (in_array($severity, ['high', 'critical'], true)) {
            $partner_warning_summary_map[$partner_id]['high_warning_count']++;
            $high_severity_partner_warnings++;
        }

        if ($partner_warning_summary_map[$partner_id]['latest_warning_subject'] === '') {
            $partner_warning_summary_map[$partner_id]['latest_warning_subject'] = (string)($warning_row['warning_subject'] ?? 'Policy Warning');
            $partner_warning_summary_map[$partner_id]['latest_warning_message'] = (string)($warning_row['warning_message'] ?? '');
            $partner_warning_summary_map[$partner_id]['latest_warning_severity'] = $severity;
            $partner_warning_summary_map[$partner_id]['latest_warning_issued_at'] = (string)($warning_row['issued_at'] ?? '');
        }

        if (!isset($partner_warning_options_map[$partner_id])) {
            $partner_warning_options_map[$partner_id] = [];
        }
        if (count($partner_warning_options_map[$partner_id]) < 40) {
            $partner_warning_options_map[$partner_id][] = [
                'id' => (int)($warning_row['id'] ?? 0),
                'subject' => (string)($warning_row['warning_subject'] ?? 'Policy Warning'),
                'severity' => $severity,
                'issued_at' => (string)($warning_row['issued_at'] ?? '')
            ];
        }

        if (count($recent_partner_warning_feed) < 8) {
            $recent_partner_warning_feed[] = $warning_row;
        }
    }

    $partners_with_active_warnings = count($partner_warning_summary_map);
}

$recent_customer_feedback = [];
if ($has_applications && $has_users && $has_products && ($has_product_reviews || $has_delivery_reviews)) {
    $feedback_union_parts = [];

    if ($has_product_reviews) {
        $feedback_union_parts[] = "
            SELECT CONVERT(COALESCE(NULLIF(fa.business_name, ''), NULLIF(owner_u.business_name, ''), owner_u.full_name, 'Partner Store') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS store_name,
                   CONVERT(COALESCE(customer_u.full_name, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS customer_name,
                   pr.rating AS rating,
                   CONVERT(COALESCE(pr.comment, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS comment,
                   pr.created_at AS created_at,
                   CONVERT('Store Feedback' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback_type
            FROM product_reviews pr
            INNER JOIN products p ON p.id = pr.product_id
            INNER JOIN franchise_applications fa ON fa.user_id = p.seller_id AND fa.status = 'approved'
            LEFT JOIN users owner_u ON owner_u.id = fa.user_id
            LEFT JOIN users customer_u ON customer_u.id = pr.user_id
            WHERE pr.is_approved = 1";
    }

    if ($has_delivery_reviews && $has_order_items) {
        $feedback_union_parts[] = "
            SELECT CONVERT(seller_orders.store_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS store_name,
                   CONVERT(COALESCE(customer_u.full_name, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS customer_name,
                   dr.rating AS rating,
                   CONVERT(COALESCE(dr.comment, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS comment,
                   dr.created_at AS created_at,
                   CONVERT('Delivery Feedback' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback_type
            FROM delivery_reviews dr
            INNER JOIN (
                SELECT DISTINCT oi.order_id,
                       p.seller_id,
                       CONVERT(COALESCE(NULLIF(fa.business_name, ''), NULLIF(owner_u.business_name, ''), owner_u.full_name, 'Partner Store') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS store_name
                FROM order_items oi
                INNER JOIN products p
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                INNER JOIN franchise_applications fa ON fa.user_id = p.seller_id AND fa.status = 'approved'
                LEFT JOIN users owner_u ON owner_u.id = fa.user_id
            ) seller_orders ON seller_orders.order_id = dr.order_id
            LEFT JOIN users customer_u ON customer_u.id = dr.user_id";
    }

    if (!empty($feedback_union_parts)) {
        $recent_customer_feedback = ownerRows(
            $conn,
            "SELECT *
             FROM (" . implode(" UNION ALL ", $feedback_union_parts) . ") feedback
             ORDER BY created_at DESC
             LIMIT 8"
        );
    }
}

if (!empty($business_performance_rows)) {
    $rating_total = 0.0;
    $rating_count = 0;
    foreach ($business_performance_rows as &$business_row) {
        $product_rating = (float)($business_row['avg_product_rating'] ?? 0);
        $delivery_rating = (float)($business_row['avg_delivery_rating'] ?? 0);
        $available_ratings = [];
        if ($product_rating > 0) {
            $available_ratings[] = $product_rating;
        }
        if ($delivery_rating > 0) {
            $available_ratings[] = $delivery_rating;
        }
        $business_rating = !empty($available_ratings) ? array_sum($available_ratings) / count($available_ratings) : 0;
        $gross_revenue = (float)($business_row['gross_revenue'] ?? 0);
        $settlement_count = (int)($business_row['settlement_count'] ?? 0);
        $actual_settlement_gross = (float)($business_row['settlement_gross_sales'] ?? 0);
        $actual_commission = (float)($business_row['settlement_commission_amount'] ?? 0);
        $actual_partner_payout = (float)($business_row['settlement_partner_payout'] ?? 0);
        $business_row['business_rating'] = $business_rating;
        $business_row['uses_actual_settlement'] = $settlement_count > 0;
        $business_row['display_gross_revenue'] = $settlement_count > 0 && $actual_settlement_gross > 0 ? $actual_settlement_gross : $gross_revenue;
        $business_row['commission_total'] = $settlement_count > 0 ? $actual_commission : ($gross_revenue * $platform_commission_rate);
        $business_row['partner_net_revenue'] = $settlement_count > 0 ? $actual_partner_payout : ($gross_revenue - ($gross_revenue * $platform_commission_rate));
        $business_row['delivery_rate'] = (int)($business_row['order_count'] ?? 0) > 0
            ? (((int)$business_row['delivered_orders']) / max(1, (int)$business_row['order_count'])) * 100
            : 0;

        $partner_id = (int)($business_row['partner_user_id'] ?? 0);
        $warning_meta = $partner_warning_summary_map[$partner_id] ?? null;
        $business_row['active_warning_count'] = (int)($warning_meta['active_warning_count'] ?? 0);
        $business_row['high_warning_count'] = (int)($warning_meta['high_warning_count'] ?? 0);
        $business_row['latest_warning_subject'] = (string)($warning_meta['latest_warning_subject'] ?? '');
        $business_row['latest_warning_message'] = (string)($warning_meta['latest_warning_message'] ?? '');
        $business_row['latest_warning_severity'] = (string)($warning_meta['latest_warning_severity'] ?? 'medium');
        $business_row['latest_warning_issued_at'] = (string)($warning_meta['latest_warning_issued_at'] ?? '');
        $business_row['active_warning_options'] = $partner_warning_options_map[$partner_id] ?? [];

        if ($business_rating > 0) {
            $rating_total += $business_rating;
            $rating_count++;
        }
    }
    unset($business_row);
    $avg_business_rating = $rating_count > 0 ? $rating_total / $rating_count : 0.0;
}

$leaderboard_range = strtolower(trim((string)($_GET['leaderboard_range'] ?? 'month')));
$leaderboard_range_options = [
    'week' => 'This Week',
    'month' => 'This Month',
    'all' => 'All-time'
];
if (!isset($leaderboard_range_options[$leaderboard_range])) {
    $leaderboard_range = 'month';
}
$leaderboard_range_label = $leaderboard_range_options[$leaderboard_range];

$leaderboard_order_date_filter_sql = '';
$leaderboard_review_date_filter_sql = '';
$leaderboard_delivery_date_filter_sql = '';
if ($leaderboard_range === 'week') {
    $leaderboard_order_date_filter_sql = " AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $leaderboard_review_date_filter_sql = " AND pr.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $leaderboard_delivery_date_filter_sql = " AND dr.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($leaderboard_range === 'month') {
    $leaderboard_order_date_filter_sql = " AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
    $leaderboard_review_date_filter_sql = " AND MONTH(pr.created_at) = MONTH(CURDATE()) AND YEAR(pr.created_at) = YEAR(CURDATE())";
    $leaderboard_delivery_date_filter_sql = " AND MONTH(dr.created_at) = MONTH(CURDATE()) AND YEAR(dr.created_at) = YEAR(CURDATE())";
}

$leaderboard_source_rows = [];
if ($has_applications && $has_users) {
    $leaderboard_sales_select_sql = ",
                0 AS order_count,
                0 AS delivered_orders,
                0 AS gross_revenue";
    $leaderboard_sales_join_sql = "";
    if ($has_orders && $has_order_items && $has_products) {
        $leaderboard_sales_select_sql = ",
                COALESCE(sales.order_count, 0) AS order_count,
                COALESCE(sales.delivered_orders, 0) AS delivered_orders,
                COALESCE(sales.gross_revenue, 0) AS gross_revenue";
        $leaderboard_sales_join_sql = "
         LEFT JOIN (
            SELECT p.seller_id,
                   COUNT(DISTINCT o.id) AS order_count,
                   COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) AS delivered_orders,
                   COALESCE(SUM(oi.total), 0) AS gross_revenue
            FROM products p
            INNER JOIN order_items oi
                ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE p.seller_id IS NOT NULL
              AND p.is_archived = 0
              AND o.is_archived = 0
              AND o.status IN ({$order_status_scope})
              {$leaderboard_order_date_filter_sql}
            GROUP BY p.seller_id
         ) sales ON sales.seller_id = u.id";
    }

    $leaderboard_ratings_select_sql = ",
                0 AS avg_product_rating,
                0 AS review_count";
    $leaderboard_ratings_join_sql = "";
    if ($has_products && $has_product_reviews) {
        $leaderboard_ratings_select_sql = ",
                COALESCE(ratings.avg_product_rating, 0) AS avg_product_rating,
                COALESCE(ratings.review_count, 0) AS review_count";
        $leaderboard_ratings_join_sql = "
         LEFT JOIN (
            SELECT p.seller_id,
                   ROUND(AVG(CASE WHEN pr.is_approved = 1 THEN pr.rating END), 2) AS avg_product_rating,
                   SUM(CASE WHEN pr.is_approved = 1 THEN 1 ELSE 0 END) AS review_count
            FROM products p
            LEFT JOIN product_reviews pr ON pr.product_id = p.id
            WHERE p.seller_id IS NOT NULL
              AND p.is_archived = 0
              {$leaderboard_review_date_filter_sql}
            GROUP BY p.seller_id
         ) ratings ON ratings.seller_id = u.id";
    }

    $leaderboard_delivery_select_sql = ",
                0 AS avg_delivery_rating,
                0 AS delivery_review_count";
    $leaderboard_delivery_join_sql = "";
    if ($has_products && $has_order_items && $has_delivery_reviews) {
        $leaderboard_delivery_select_sql = ",
                COALESCE(delivery.avg_delivery_rating, 0) AS avg_delivery_rating,
                COALESCE(delivery.delivery_review_count, 0) AS delivery_review_count";
        $leaderboard_delivery_join_sql = "
         LEFT JOIN (
            SELECT seller_orders.seller_id,
                   ROUND(AVG(dr.rating), 2) AS avg_delivery_rating,
                   COUNT(DISTINCT dr.id) AS delivery_review_count
            FROM (
                SELECT DISTINCT p.seller_id, oi.order_id
                FROM products p
                INNER JOIN order_items oi
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                WHERE p.seller_id IS NOT NULL
                  AND p.is_archived = 0
            ) seller_orders
            INNER JOIN delivery_reviews dr ON dr.order_id = seller_orders.order_id
            WHERE 1=1
              {$leaderboard_delivery_date_filter_sql}
            GROUP BY seller_orders.seller_id
         ) delivery ON delivery.seller_id = u.id";
    }

    $leaderboard_source_rows = ownerRows(
        $conn,
        "SELECT u.id AS partner_user_id,
                COALESCE(NULLIF(fa.business_name, ''), NULLIF(u.business_name, ''), u.full_name, 'Partner Store') AS store_name
                {$leaderboard_sales_select_sql}
                {$leaderboard_ratings_select_sql}
                {$leaderboard_delivery_select_sql}
         FROM franchise_applications fa
         INNER JOIN users u ON u.id = fa.user_id
         {$leaderboard_sales_join_sql}
         {$leaderboard_ratings_join_sql}
         {$leaderboard_delivery_join_sql}
         WHERE fa.status = 'approved'
         GROUP BY u.id, store_name, order_count, delivered_orders, gross_revenue, avg_product_rating, review_count, avg_delivery_rating, delivery_review_count
         ORDER BY store_name ASC"
    );
}

foreach ($leaderboard_source_rows as &$leaderboard_row) {
    $product_rating = (float)($leaderboard_row['avg_product_rating'] ?? 0);
    $delivery_rating = (float)($leaderboard_row['avg_delivery_rating'] ?? 0);
    $rating_values = [];
    if ($product_rating > 0) {
        $rating_values[] = $product_rating;
    }
    if ($delivery_rating > 0) {
        $rating_values[] = $delivery_rating;
    }
    $leaderboard_row['business_rating'] = !empty($rating_values) ? array_sum($rating_values) / count($rating_values) : 0;
    $leaderboard_row['delivery_rate'] = (int)($leaderboard_row['order_count'] ?? 0) > 0
        ? (((int)($leaderboard_row['delivered_orders'] ?? 0)) / max(1, (int)($leaderboard_row['order_count'] ?? 0))) * 100
        : 0;
    $leaderboard_row['display_gross_revenue'] = (float)($leaderboard_row['gross_revenue'] ?? 0);
}
unset($leaderboard_row);

$top_store_limit = 10;
$top_rated_stores = array_values(array_filter($leaderboard_source_rows, static function ($row) {
    return (float)($row['business_rating'] ?? 0) > 0;
}));
usort($top_rated_stores, static function ($a, $b) {
    $rating_a = (float)($a['business_rating'] ?? 0);
    $rating_b = (float)($b['business_rating'] ?? 0);
    if ($rating_a !== $rating_b) {
        return ($rating_a < $rating_b) ? 1 : -1;
    }

    $reviews_a = (int)($a['review_count'] ?? 0) + (int)($a['delivery_review_count'] ?? 0);
    $reviews_b = (int)($b['review_count'] ?? 0) + (int)($b['delivery_review_count'] ?? 0);
    if ($reviews_a !== $reviews_b) {
        return ($reviews_a < $reviews_b) ? 1 : -1;
    }

    return strcmp((string)($a['store_name'] ?? ''), (string)($b['store_name'] ?? ''));
});
$top_rated_stores = array_slice($top_rated_stores, 0, $top_store_limit);

$top_selling_stores = array_values(array_filter($leaderboard_source_rows, static function ($row) {
    return (float)($row['display_gross_revenue'] ?? 0) > 0;
}));
usort($top_selling_stores, static function ($a, $b) {
    $sales_a = (float)($a['display_gross_revenue'] ?? 0);
    $sales_b = (float)($b['display_gross_revenue'] ?? 0);
    if ($sales_a !== $sales_b) {
        return ($sales_a < $sales_b) ? 1 : -1;
    }

    $orders_a = (int)($a['order_count'] ?? 0);
    $orders_b = (int)($b['order_count'] ?? 0);
    if ($orders_a !== $orders_b) {
        return ($orders_a < $orders_b) ? 1 : -1;
    }

    return strcmp((string)($a['store_name'] ?? ''), (string)($b['store_name'] ?? ''));
});
$top_selling_stores = array_slice($top_selling_stores, 0, $top_store_limit);

$recent_orders = ownerTableExists($conn, 'orders')
    ? ownerRows($conn, "SELECT order_number, customer_name, total_amount, status, created_at FROM orders WHERE is_archived = 0 ORDER BY created_at DESC LIMIT 5")
    : [];

$has_chat_conversations = ownerTableExists($conn, 'chat_conversations');
$has_chat_conversation_type_col = ownerColumnExists($conn, 'chat_conversations', 'conversation_type');
$has_chat_subject_col = ownerColumnExists($conn, 'chat_conversations', 'subject');
$has_chat_status_col = ownerColumnExists($conn, 'chat_conversations', 'status');
$has_chat_priority_col = ownerColumnExists($conn, 'chat_conversations', 'priority');
$has_chat_is_escalated_col = ownerColumnExists($conn, 'chat_conversations', 'is_escalated');
$has_chat_created_at_col = ownerColumnExists($conn, 'chat_conversations', 'created_at');
$has_chat_updated_at_col = ownerColumnExists($conn, 'chat_conversations', 'updated_at');
$has_chat_last_message_time_col = ownerColumnExists($conn, 'chat_conversations', 'last_message_time');
$has_chat_customer_id_col = ownerColumnExists($conn, 'chat_conversations', 'customer_id');

$complaint_detection_conditions = [];
if ($has_chat_conversation_type_col) {
    $complaint_detection_conditions[] = "conversation_type = 'complaint'";
}
if ($has_chat_subject_col) {
    $complaint_detection_conditions[] = "subject LIKE '%complaint%'";
}
if ($has_chat_is_escalated_col) {
    $complaint_detection_conditions[] = "is_escalated = 1";
}
$complaint_scope_sql = !empty($complaint_detection_conditions)
    ? '(' . implode(' OR ', $complaint_detection_conditions) . ')'
    : '0 = 1';
$open_complaints_filter = $has_chat_status_col
    ? " AND status IN ('open', 'in_progress')"
    : '';

$open_complaints_count = $has_chat_conversations
    ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM chat_conversations WHERE {$complaint_scope_sql}{$open_complaints_filter}", 0)
    : 0;
$urgent_complaints_count = ($has_chat_conversations && $has_chat_priority_col)
    ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM chat_conversations WHERE {$complaint_scope_sql}{$open_complaints_filter} AND priority IN ('high', 'urgent')", 0)
    : 0;
$escalated_complaints_count = ($has_chat_conversations && $has_chat_is_escalated_col)
    ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM chat_conversations WHERE {$complaint_scope_sql}{$open_complaints_filter} AND is_escalated = 1", 0)
    : 0;

$complaint_age_reference_expr = '';
if ($has_chat_last_message_time_col && $has_chat_created_at_col) {
    $complaint_age_reference_expr = "COALESCE(last_message_time, created_at)";
} elseif ($has_chat_updated_at_col) {
    $complaint_age_reference_expr = 'updated_at';
} elseif ($has_chat_created_at_col) {
    $complaint_age_reference_expr = 'created_at';
}
$average_complaint_age_hours = 0.0;
$longest_open_complaint_hours = 0;
if ($has_chat_conversations && $complaint_age_reference_expr !== '') {
    $average_complaint_age_hours = (float)ownerScalar(
        $conn,
        "SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, {$complaint_age_reference_expr}, NOW())), 0)
         FROM chat_conversations
         WHERE {$complaint_scope_sql}{$open_complaints_filter}",
        0
    );
    $longest_open_complaint_hours = (int)ownerScalar(
        $conn,
        "SELECT COALESCE(MAX(TIMESTAMPDIFF(HOUR, {$complaint_age_reference_expr}, NOW())), 0)
         FROM chat_conversations
         WHERE {$complaint_scope_sql}{$open_complaints_filter}",
        0
    );
}

$recent_open_complaints = [];
if ($has_chat_conversations) {
    $complaint_select_fields = [
        "cc.id",
        $has_chat_subject_col ? "COALESCE(NULLIF(TRIM(cc.subject), ''), 'Complaint Thread') AS subject" : "'Complaint Thread' AS subject",
        $has_chat_status_col ? "COALESCE(NULLIF(cc.status, ''), 'open') AS status" : "'open' AS status",
        $has_chat_priority_col ? "COALESCE(NULLIF(cc.priority, ''), 'medium') AS priority" : "'medium' AS priority",
        $has_chat_is_escalated_col ? "COALESCE(cc.is_escalated, 0) AS is_escalated" : "0 AS is_escalated",
        $has_chat_created_at_col ? "cc.created_at" : "NOW() AS created_at",
        $has_chat_updated_at_col ? "cc.updated_at" : ($has_chat_created_at_col ? "cc.created_at AS updated_at" : "NOW() AS updated_at"),
    ];
    $complaint_join_users_sql = '';
    if ($has_users && $has_chat_customer_id_col) {
        $complaint_select_fields[] = "COALESCE(cu.full_name, 'Unknown User') AS customer_name";
        $complaint_select_fields[] = "COALESCE(cu.email, '') AS customer_email";
        $complaint_join_users_sql = "LEFT JOIN users cu ON cu.id = cc.customer_id";
    } else {
        $complaint_select_fields[] = "'Unknown User' AS customer_name";
        $complaint_select_fields[] = "'' AS customer_email";
    }

    $complaint_order_parts = [];
    if ($has_chat_status_col) {
        $complaint_order_parts[] = "FIELD(cc.status, 'open', 'in_progress', 'resolved', 'closed')";
    }
    if ($has_chat_priority_col) {
        $complaint_order_parts[] = "FIELD(cc.priority, 'urgent', 'high', 'medium', 'low')";
    }
    if ($has_chat_is_escalated_col) {
        $complaint_order_parts[] = "cc.is_escalated DESC";
    }
    if ($has_chat_updated_at_col) {
        $complaint_order_parts[] = "cc.updated_at DESC";
    } elseif ($has_chat_created_at_col) {
        $complaint_order_parts[] = "cc.created_at DESC";
    } else {
        $complaint_order_parts[] = "cc.id DESC";
    }

    $recent_open_complaints = ownerRows(
        $conn,
        "SELECT " . implode(",\n                ", $complaint_select_fields) . "
         FROM chat_conversations cc
         {$complaint_join_users_sql}
         WHERE {$complaint_scope_sql}" . ($has_chat_status_col ? " AND cc.status IN ('open', 'in_progress')" : "") . "
         ORDER BY " . implode(", ", $complaint_order_parts) . "
         LIMIT 6"
    );
}

$pending_refunds_count = ($has_refunds && ownerColumnExists($conn, 'refunds', 'refund_status'))
    ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM refunds WHERE refund_status IN ('Refund Pending', 'Refund Requested', 'pending', 'requested')", 0)
    : 0;
$pending_cancellations_count = ($has_cancellations && ownerColumnExists($conn, 'cancellations', 'status'))
    ? (int)ownerScalar($conn, "SELECT COUNT(*) FROM cancellations WHERE status IN ('Requested', 'Pending', 'cancellation_requested')", 0)
    : 0;

$restricted_partner_count = 0;
$suspended_partner_count = 0;
$banned_partner_count = 0;
if (!empty($business_performance_rows)) {
    foreach ($business_performance_rows as $business) {
        $status_value = strtolower(trim((string)($business['account_control_status'] ?? 'active')));
        if ($status_value === 'restricted') {
            $restricted_partner_count++;
        } elseif ($status_value === 'suspended') {
            $suspended_partner_count++;
        } elseif ($status_value === 'banned') {
            $banned_partner_count++;
        }
    }
}

$owner_module_health_cards = [
    [
        'label' => 'Complaints Desk',
        'href' => '../super_admin/reports_complaints.php',
        'metric' => number_format($open_complaints_count),
        'subtitle' => 'Open complaints',
        'detail' => number_format($urgent_complaints_count) . ' urgent | ' . number_format($escalated_complaints_count) . ' escalated',
        'severity' => ($urgent_complaints_count > 0 || $escalated_complaints_count > 0) ? 'critical' : (($open_complaints_count > 0) ? 'watch' : 'healthy'),
        'icon' => 'fa-triangle-exclamation'
    ],
    [
        'label' => 'Business Applications',
        'href' => '../super_admin/franchise_applications.php',
        'metric' => number_format($pending_applications),
        'subtitle' => 'Pending approvals',
        'detail' => number_format($approved_partners) . ' approved partners',
        'severity' => $pending_applications >= 10 ? 'critical' : ($pending_applications > 0 ? 'watch' : 'healthy'),
        'icon' => 'fa-file-signature'
    ],
    [
        'label' => 'Refunds & Cancellations',
        'href' => '../super_admin/transactions_financial.php',
        'metric' => number_format($pending_refunds_count + $pending_cancellations_count),
        'subtitle' => 'Pending financial cases',
        'detail' => number_format($pending_refunds_count) . ' refunds | ' . number_format($pending_cancellations_count) . ' cancellations',
        'severity' => ($pending_refunds_count + $pending_cancellations_count) >= 20 ? 'critical' : (($pending_refunds_count + $pending_cancellations_count) > 0 ? 'watch' : 'healthy'),
        'icon' => 'fa-rotate-left'
    ],
    [
        'label' => 'Security Access Control',
        'href' => '../super_admin/security_access_control.php',
        'metric' => number_format($restricted_partner_count + $suspended_partner_count + $banned_partner_count),
        'subtitle' => 'Accounts under control',
        'detail' => number_format($restricted_partner_count) . ' restricted | ' . number_format($suspended_partner_count + $banned_partner_count) . ' suspended/banned',
        'severity' => ($suspended_partner_count + $banned_partner_count) > 0 ? 'critical' : ($restricted_partner_count > 0 ? 'watch' : 'healthy'),
        'icon' => 'fa-user-shield'
    ],
    [
        'label' => 'Partner Violations',
        'href' => 'super_admin_dashboard.php#partnerWarningsPanel',
        'metric' => number_format($active_partner_warnings_total),
        'subtitle' => 'Active warning notices',
        'detail' => number_format($partners_with_active_warnings) . ' partners | ' . number_format($high_severity_partner_warnings) . ' high/critical',
        'severity' => $high_severity_partner_warnings > 0 ? 'critical' : ($active_partner_warnings_total > 0 ? 'watch' : 'healthy'),
        'icon' => 'fa-gavel'
    ],
    [
        'label' => 'System Monitoring',
        'href' => '../super_admin/system_monitoring.php',
        'metric' => number_format($active_deliveries),
        'subtitle' => 'Active deliveries now',
        'detail' => number_format($low_stock_items) . ' low-stock items',
        'severity' => $low_stock_items >= 30 ? 'critical' : ($low_stock_items > 0 ? 'watch' : 'healthy'),
        'icon' => 'fa-server'
    ],
    [
        'label' => 'RBAC Governance',
        'href' => 'rbac_management.php',
        'metric' => number_format($roles_count),
        'subtitle' => 'Active roles',
        'detail' => number_format($permissions_count) . ' permissions configured',
        'severity' => $roles_count === 0 || $permissions_count === 0 ? 'critical' : 'healthy',
        'icon' => 'fa-lock'
    ]
];

$quick_actions = [
    ['label' => 'User & Business', 'href' => '../super_admin/user_business_management.php', 'icon' => 'fa-users-cog', 'meta' => 'Accounts + approvals'],
    ['label' => 'Users', 'href' => '../super_admin/users.php', 'icon' => 'fa-users', 'meta' => number_format($total_customers) . ' customers'],
    ['label' => 'Business Apps', 'href' => '../super_admin/franchise_applications.php', 'icon' => 'fa-file-contract', 'meta' => number_format($pending_applications) . ' pending'],
    ['label' => 'Business Monitor', 'href' => '../super_admin/business_monitoring.php', 'icon' => 'fa-store', 'meta' => 'Profiles + performance'],
    ['label' => 'Analytics', 'href' => '../super_admin/analytics_reports.php', 'icon' => 'fa-chart-line', 'meta' => 'Platform trends'],
    ['label' => 'Complaints', 'href' => '../super_admin/reports_complaints.php', 'icon' => 'fa-triangle-exclamation', 'meta' => number_format($open_complaints_count) . ' open'],
    ['label' => 'Security', 'href' => '../super_admin/security_access_control.php', 'icon' => 'fa-user-shield', 'meta' => 'Access controls'],
    ['label' => 'Activity Logs', 'href' => '../super_admin/activity_logs.php', 'icon' => 'fa-clipboard-list', 'meta' => 'Audit trails'],
    ['label' => 'System', 'href' => '../super_admin/system_monitoring.php', 'icon' => 'fa-server', 'meta' => 'Health + uptime'],
    ['label' => 'Notifications', 'href' => '../super_admin/notification_center.php', 'icon' => 'fa-bell', 'meta' => 'Alerts center'],
    ['label' => 'Transactions', 'href' => '../super_admin/transactions_financial.php', 'icon' => 'fa-coins', 'meta' => 'Financial monitoring'],
    ['label' => 'Monetization', 'href' => '../super_admin/platform_monetization.php', 'icon' => 'fa-sack-dollar', 'meta' => 'Revenue controls'],
    ['label' => 'Ops Dashboard', 'href' => '../super_admin/operations_dashboard.php', 'icon' => 'fa-gauge-high', 'meta' => 'Live operations'],
    ['label' => 'Ops Incidents', 'href' => '../super_admin/operations_incidents.php', 'icon' => 'fa-triangle-exclamation', 'meta' => 'Incident response'],
    ['label' => 'Ops User Control', 'href' => '../super_admin/operations_user_business_control.php', 'icon' => 'fa-users-gear', 'meta' => 'Tenant interventions'],
    ['label' => 'Ops Moderation', 'href' => '../super_admin/operations_content_moderation.php', 'icon' => 'fa-shield-check', 'meta' => 'Content safety'],
    ['label' => 'Ops Decisions', 'href' => '../super_admin/operations_decision_support.php', 'icon' => 'fa-chart-pie', 'meta' => 'Decision support'],
    ['label' => 'Ops Notices', 'href' => '../super_admin/operations_notifications.php', 'icon' => 'fa-bullhorn', 'meta' => 'Broadcast tools'],
    ['label' => 'Ops Automation', 'href' => '../super_admin/operations_automation.php', 'icon' => 'fa-robot', 'meta' => 'Workflow automation'],
    ['label' => 'Ops Logs & Backup', 'href' => '../super_admin/operations_logs_backups.php', 'icon' => 'fa-database', 'meta' => 'Recovery + logs'],
    ['label' => 'Ops Team', 'href' => '../super_admin/operations_team.php', 'icon' => 'fa-user-shield', 'meta' => 'Ops team roles'],
    ['label' => 'Forecasting', 'href' => '../admin/forecasting_dashboard.php', 'icon' => 'fa-brain', 'meta' => 'Demand forecasting'],
    ['label' => 'DSS Reports', 'href' => '../admin/dss_reports.php', 'icon' => 'fa-file-invoice', 'meta' => 'Decision reports'],
    ['label' => 'Statistics', 'href' => '../admin/statistics.php', 'icon' => 'fa-chart-bar', 'meta' => 'KPI dashboards'],
    ['label' => 'Events', 'href' => '../admin/events.php', 'icon' => 'fa-calendar-alt', 'meta' => 'Business events'],
    ['label' => 'RBAC', 'href' => 'rbac_management.php', 'icon' => 'fa-lock', 'meta' => number_format($roles_count) . ' roles'],
    ['label' => 'Tenant Tools', 'href' => 'tenant_scope_migration.php', 'icon' => 'fa-diagram-project', 'meta' => 'Owner maintenance']
];

$dashboard_flash_messages = [];
if (isset($_SESSION['success'])) {
    $dashboard_flash_messages[] = ['type' => 'success', 'message' => (string)$_SESSION['success']];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $dashboard_flash_messages[] = ['type' => 'error', 'message' => (string)$_SESSION['error']];
    unset($_SESSION['error']);
}
if (isset($_SESSION['warning'])) {
    $dashboard_flash_messages[] = ['type' => 'warning', 'message' => (string)$_SESSION['warning']];
    unset($_SESSION['warning']);
}
$dashboard_flash_payload = json_encode(
    $dashboard_flash_messages,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
if ($dashboard_flash_payload === false) {
    $dashboard_flash_payload = '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="../admin/">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Lechon Delights</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-topbar .topbar-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: nowrap;
        }

        .admin-topbar .topbar-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0;
            padding: 5px;
        }

        .super-owner-page {
            display: grid;
            gap: 18px;
        }

        .owner-hero {
            border-radius: 20px;
            padding: 24px;
            color: #fff;
            background: linear-gradient(135deg, #7f1d1d 0%, #9f1239 50%, #c2410c 100%);
            box-shadow: 0 18px 40px rgba(127, 29, 29, 0.18);
        }

        .owner-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 12px;
        }

        .owner-hero h2 {
            margin: 0 0 10px 0;
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            font-weight: 800;
        }

        .owner-hero p {
            margin: 0;
            max-width: 820px;
            color: rgba(255, 255, 255, 0.92);
        }

        .owner-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .owner-hero-meta span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.14);
            font-weight: 600;
        }

        .owner-metric-grid,
        .owner-action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .owner-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .owner-business-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
        }

        .owner-business-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
            display: grid;
            gap: 14px;
        }

        .owner-business-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .owner-business-head h4 {
            margin: 0 0 4px 0;
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }

        .owner-business-head small {
            color: #64748b;
        }

        .owner-badge-inline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #fff1f2;
            color: #9f1239;
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .owner-business-stats,
        .owner-business-finance {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .owner-business-stats div,
        .owner-business-finance div {
            padding: 10px 12px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: grid;
            gap: 4px;
        }

        .owner-business-stats span,
        .owner-business-finance span {
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .owner-business-stats strong,
        .owner-business-finance strong {
            font-size: 0.96rem;
            color: #0f172a;
        }

        .owner-card,
        .owner-section {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .owner-card {
            padding: 18px;
            display: grid;
            gap: 6px;
        }

        .owner-card strong {
            font-size: 1.9rem;
            line-height: 1.1;
            color: #0f172a;
        }

        .owner-card span {
            font-weight: 700;
            color: #334155;
        }

        .owner-card small,
        .owner-section-head p,
        .owner-stack small {
            color: #64748b;
        }

        .owner-section {
            padding: 18px;
        }

        .owner-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .owner-section-head h3 {
            margin: 0;
            font-size: 1.08rem;
            font-weight: 800;
            color: #0f172a;
        }

        .owner-section-head p {
            margin: 4px 0 0;
        }

        .owner-action {
            display: block;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .owner-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
            color: inherit;
        }

        .owner-action i {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            margin-bottom: 10px;
            background: #fff1f2;
            color: #9f1239;
        }

        .owner-action strong {
            display: block;
            margin-bottom: 4px;
        }

        .owner-action small {
            color: #64748b;
        }

        .owner-list {
            display: grid;
            gap: 10px;
        }

        .owner-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .owner-rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            padding: 2px 8px;
            margin-right: 8px;
            border-radius: 999px;
            background: #fff1f2;
            color: #9f1239;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
        }

        .owner-stack {
            align-items: flex-start;
        }

        .owner-stack div {
            display: grid;
            gap: 4px;
        }

        .owner-link {
            color: #9f1239;
            text-decoration: none;
            font-weight: 700;
        }

        .owner-range-filter {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .owner-range-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .owner-range-pill:hover {
            border-color: #fca5a5;
            color: #9f1239;
            background: #fff1f2;
        }

        .owner-range-pill.active {
            border-color: #be123c;
            background: #9f1239;
            color: #fff;
        }

        .owner-empty {
            padding: 18px;
            border-radius: 14px;
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            color: #64748b;
            text-align: center;
        }

        .owner-note {
            margin-top: -4px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 0.9rem;
        }

        .owner-alert {
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 0.92rem;
            border: 1px solid transparent;
        }

        .owner-alert.success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .owner-alert.error {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #9f1239;
        }

        .owner-control-form {
            display: grid;
            gap: 10px;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
        }

        .owner-control-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .owner-control-form label {
            display: block;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .owner-control-form select,
        .owner-control-form input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 0.9rem;
            background: #fff;
        }

        .owner-control-submit {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            background: #9f1239;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .owner-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .owner-status-pill.active { background: #dcfce7; color: #166534; }
        .owner-status-pill.restricted { background: #fef3c7; color: #92400e; }
        .owner-status-pill.suspended { background: #fde68a; color: #92400e; }
        .owner-status-pill.banned { background: #fee2e2; color: #b91c1c; }
        .owner-warning-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .owner-warning-chip.watch { background: #fef3c7; color: #92400e; }
        .owner-warning-chip.critical { background: #fee2e2; color: #b91c1c; }
        .owner-warning-chip.healthy { background: #dcfce7; color: #166534; }

        .owner-warning-form {
            display: grid;
            gap: 10px;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
        }

        .owner-warning-grid {
            display: grid;
            grid-template-columns: 1fr 160px;
            gap: 10px;
        }

        .owner-warning-form label {
            display: block;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .owner-warning-form input,
        .owner-warning-form select,
        .owner-warning-form textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 0.9rem;
            background: #fff;
        }

        .owner-warning-form textarea {
            min-height: 72px;
            resize: vertical;
        }

        .owner-warning-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .owner-warning-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 600;
        }

        .owner-warning-check input {
            width: auto;
            margin: 0;
        }

        .owner-warning-submit {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            background: #b91c1c;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .owner-resolve-submit {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            background: #166534;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .owner-priority-grid,
        .owner-module-health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .owner-priority-card {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 12px;
            display: grid;
            gap: 4px;
        }

        .owner-priority-card span {
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
        }

        .owner-priority-card strong {
            font-size: 1.35rem;
            color: #0f172a;
            line-height: 1.05;
        }

        .owner-priority-card small {
            color: #64748b;
        }

        .owner-priority-card.critical {
            border-color: #fecdd3;
            background: #fff1f2;
        }

        .owner-priority-card.critical strong {
            color: #9f1239;
        }

        .owner-priority-card.watch {
            border-color: #fde68a;
            background: #fffbeb;
        }

        .owner-priority-card.watch strong {
            color: #92400e;
        }

        .owner-priority-card.healthy {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .owner-priority-card.healthy strong {
            color: #166534;
        }

        .owner-queue-list {
            display: grid;
            gap: 10px;
        }

        .owner-queue-item {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 12px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .owner-queue-item b {
            color: #0f172a;
            display: block;
            margin-bottom: 4px;
        }

        .owner-queue-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 0.78rem;
            color: #64748b;
        }

        .owner-queue-meta i {
            font-size: 0.72rem;
            opacity: 0.8;
        }

        .owner-queue-time {
            white-space: nowrap;
            font-size: 0.76rem;
            color: #64748b;
            padding-top: 2px;
        }

        .owner-priority-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .owner-priority-pill.urgent { background: #fee2e2; color: #b91c1c; }
        .owner-priority-pill.critical { background: #fecaca; color: #991b1b; }
        .owner-priority-pill.high { background: #ffedd5; color: #9a3412; }
        .owner-priority-pill.medium { background: #fef3c7; color: #92400e; }
        .owner-priority-pill.low { background: #e0f2fe; color: #075985; }

        .owner-module-card {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 14px;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
            display: grid;
            gap: 8px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .owner-module-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.08);
            color: inherit;
        }

        .owner-module-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .owner-module-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
        }

        .owner-module-title i {
            color: #9f1239;
        }

        .owner-module-metric {
            font-size: 1.22rem;
            font-weight: 800;
            color: #0f172a;
        }

        .owner-module-subtitle {
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
        }

        .owner-module-detail {
            font-size: 0.78rem;
            color: #64748b;
        }

        .owner-module-state {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .owner-module-state.healthy { background: #dcfce7; color: #166534; }
        .owner-module-state.watch { background: #fef3c7; color: #92400e; }
        .owner-module-state.critical { background: #fee2e2; color: #b91c1c; }

        body.dark-mode {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-main,
        body.dark-mode .admin-topbar,
        body.dark-mode .owner-card,
        body.dark-mode .owner-section {
            background: #111827 !important;
            color: #e2e8f0 !important;
            border-color: #374151 !important;
        }

        body.dark-mode .owner-card strong,
        body.dark-mode .owner-card span,
        body.dark-mode .owner-section-head h3,
        body.dark-mode .owner-action strong,
        body.dark-mode .owner-list-item b,
        body.dark-mode .owner-business-head h4,
        body.dark-mode .owner-business-stats strong,
        body.dark-mode .owner-business-finance strong {
            color: #f8fafc !important;
        }

        body.dark-mode .owner-card small,
        body.dark-mode .owner-section-head p,
        body.dark-mode .owner-action small,
        body.dark-mode .owner-stack small,
        body.dark-mode .theme-toggler {
            color: #cbd5e1 !important;
        }

        body.dark-mode .owner-action,
        body.dark-mode .owner-list-item,
        body.dark-mode .owner-empty,
        body.dark-mode .owner-business-card,
        body.dark-mode .owner-business-stats div,
        body.dark-mode .owner-business-finance div,
        body.dark-mode .owner-note,
        body.dark-mode .owner-control-form select,
        body.dark-mode .owner-control-form input,
        body.dark-mode .owner-alert {
            background: #1f2937 !important;
            border-color: #374151 !important;
        }

        body.dark-mode .owner-business-head small,
        body.dark-mode .owner-business-stats span,
        body.dark-mode .owner-business-finance span,
        body.dark-mode .owner-badge-inline,
        body.dark-mode .owner-control-form label,
        body.dark-mode .owner-warning-form label,
        body.dark-mode .owner-warning-check {
            color: #cbd5e1 !important;
        }

        body.dark-mode .owner-rank-badge {
            background: #7f1d1d !important;
            color: #fecaca !important;
        }

        body.dark-mode .owner-range-pill {
            background: #1f2937 !important;
            border-color: #374151 !important;
            color: #cbd5e1 !important;
        }

        body.dark-mode .owner-range-pill.active {
            background: #be123c !important;
            border-color: #f43f5e !important;
            color: #fff !important;
        }

        body.dark-mode .owner-priority-card,
        body.dark-mode .owner-module-card,
        body.dark-mode .owner-queue-item {
            background: #1f2937 !important;
            border-color: #374151 !important;
        }

        body.dark-mode .owner-warning-form input,
        body.dark-mode .owner-warning-form select,
        body.dark-mode .owner-warning-form textarea {
            background: #111827 !important;
            border-color: #374151 !important;
            color: #e2e8f0 !important;
        }

        body.dark-mode .owner-priority-card strong,
        body.dark-mode .owner-module-title,
        body.dark-mode .owner-module-metric,
        body.dark-mode .owner-queue-item b {
            color: #f8fafc !important;
        }

        body.dark-mode .owner-priority-card small,
        body.dark-mode .owner-priority-card span,
        body.dark-mode .owner-queue-meta,
        body.dark-mode .owner-queue-time,
        body.dark-mode .owner-module-subtitle,
        body.dark-mode .owner-module-detail {
            color: #cbd5e1 !important;
        }

        @media (max-width: 960px) {
            .owner-two-col {
                grid-template-columns: 1fr;
            }
            .owner-control-grid {
                grid-template-columns: 1fr;
            }
            .owner-warning-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page-loader"><div class="spinner"></div></div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Owner Dashboard</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"></div>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name'] ?? 'System Owner'); ?></span>
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-main super-owner-page">
                <div class="owner-hero">
                    <span class="owner-badge"><i class="fas fa-crown"></i> System Owner Control Center</span>
                    <h2>Full oversight for your integrated platform</h2>
                    <p>This dashboard is exclusive to your super admin account and keeps users, partner onboarding, system governance, and platform operations in one place.</p>
                    <div class="owner-hero-meta">
                        <span><i class="fas fa-store"></i> <?php echo number_format($approved_partners); ?> partners</span>
                        <span><i class="fas fa-hourglass-half"></i> <?php echo number_format($pending_applications); ?> pending apps</span>
                        <span><i class="fas fa-hand-holding-dollar"></i> PHP <?php echo number_format($partner_sales_this_month, 2); ?> partner sales in <?php echo htmlspecialchars($current_month_label); ?></span>
                        <span><i class="fas fa-percent"></i> <?php echo number_format($platform_commission_percent, 2); ?>% commission rate</span>
                    </div>
                </div>

                <div class="owner-metric-grid">
                    <div class="owner-card"><strong><?php echo number_format($pending_applications); ?></strong><span>Pending business applications</span><small>Owner-only approval queue</small></div>
                    <div class="owner-card"><strong><?php echo number_format($approved_partners); ?></strong><span>Approved partners</span><small>Active tenant businesses</small></div>
                    <div class="owner-card"><strong><?php echo number_format($total_customers); ?></strong><span>Customer accounts</span><small>Platform-wide registered users</small></div>
                    <div class="owner-card"><strong><?php echo number_format($total_admins); ?></strong><span>Admin accounts</span><small><?php echo number_format($total_employees); ?> employee records</small></div>
                    <div class="owner-card"><strong><?php echo number_format($active_partner_warnings_total); ?></strong><span>Active partner warnings</span><small><?php echo number_format($partners_with_active_warnings); ?> partner stores flagged</small></div>
                    <div class="owner-card"><strong><?php echo $avg_business_rating > 0 ? number_format($avg_business_rating, 2) : '0.00'; ?></strong><span>Average business rating</span><small>From store and delivery feedback</small></div>
                    <div class="owner-card"><strong>PHP <?php echo number_format($partner_sales_this_month, 2); ?></strong><span>Partner sales this month</span><small>Gross revenue generated by approved businesses</small></div>
                    <div class="owner-card"><strong>PHP <?php echo number_format($platform_commission_total, 2); ?></strong><span><?php echo $commission_metrics_are_actual ? 'Actual platform commission' : 'Estimated platform commission'; ?></span><small>Based on <?php echo number_format($platform_commission_percent, 2); ?>% commission rate</small></div>
                    <div class="owner-card"><strong>PHP <?php echo number_format($partner_net_revenue_total, 2); ?></strong><span><?php echo $commission_metrics_are_actual ? 'Actual partner payout total' : 'Estimated partner net revenue'; ?></span><small>Gross sales less platform commission</small></div>
                    <div class="owner-card"><strong>PHP <?php echo number_format($monthly_revenue, 2); ?></strong><span>Platform revenue this month</span><small>Net income PHP <?php echo number_format($net_income, 2); ?></small></div>
                    <div class="owner-card"><strong><?php echo number_format($today_orders); ?></strong><span>Orders today</span><small><?php echo number_format($active_deliveries); ?> active deliveries</small></div>
                </div>
                <div class="owner-note">
                    <?php if ($commission_metrics_are_actual): ?>
                        Commission and partner payout figures are now coming from real `partner_settlements` records using the configured commission rules. No partner product list is shown in this owner dashboard.
                    <?php else: ?>
                        Commission and partner revenue figures are still estimated from approved partner order sales using a <?php echo number_format($platform_commission_percent, 2); ?>% commission rate. Run Tenant Scope Migration once to create and backfill real settlement records.
                    <?php endif; ?>
                </div>
                <?php if (!$has_user_control_status): ?>
                    <div class="owner-note">
                        Partner suspension, ban, and restriction controls will appear here after you run <a href="tenant_scope_migration.php" class="owner-link">Tenant Scope Migration</a>.
                    </div>
                <?php endif; ?>

                <div class="owner-section">
                    <div class="owner-section-head">
                        <div>
                            <h3>Owner Attention Center</h3>
                            <p>Prioritized complaint handling queue so urgent customer issues are resolved faster.</p>
                        </div>
                        <a href="../super_admin/reports_complaints.php" class="owner-link">Open complaints desk</a>
                    </div>
                    <?php
                        $open_backlog_severity = $open_complaints_count >= 20 ? 'critical' : ($open_complaints_count > 0 ? 'watch' : 'healthy');
                        $urgent_backlog_severity = $urgent_complaints_count > 0 ? 'critical' : 'healthy';
                        $escalated_backlog_severity = $escalated_complaints_count > 0 ? 'critical' : 'healthy';
                        $average_age_severity = $average_complaint_age_hours >= 48 ? 'critical' : ($average_complaint_age_hours >= 12 ? 'watch' : 'healthy');
                        $long_wait_severity = $longest_open_complaint_hours >= 72 ? 'critical' : ($longest_open_complaint_hours >= 24 ? 'watch' : 'healthy');
                    ?>
                    <div class="owner-priority-grid">
                        <div class="owner-priority-card <?php echo htmlspecialchars($open_backlog_severity); ?>">
                            <span>Open Backlog</span>
                            <strong><?php echo number_format($open_complaints_count); ?></strong>
                            <small>Active complaint threads</small>
                        </div>
                        <div class="owner-priority-card <?php echo htmlspecialchars($urgent_backlog_severity); ?>">
                            <span>Urgent Cases</span>
                            <strong><?php echo number_format($urgent_complaints_count); ?></strong>
                            <small>High/urgent priority issues</small>
                        </div>
                        <div class="owner-priority-card <?php echo htmlspecialchars($escalated_backlog_severity); ?>">
                            <span>Escalated</span>
                            <strong><?php echo number_format($escalated_complaints_count); ?></strong>
                            <small>Needs owner supervision</small>
                        </div>
                        <div class="owner-priority-card <?php echo htmlspecialchars($average_age_severity); ?>">
                            <span>Average Open Age</span>
                            <strong><?php echo number_format($average_complaint_age_hours, 1); ?>h</strong>
                            <small>How long complaints stay open</small>
                        </div>
                        <div class="owner-priority-card <?php echo htmlspecialchars($long_wait_severity); ?>">
                            <span>Longest Waiting</span>
                            <strong><?php echo number_format($longest_open_complaint_hours); ?>h</strong>
                            <small>Oldest unresolved complaint</small>
                        </div>
                    </div>

                    <?php if ($has_chat_conversations): ?>
                        <?php if (!empty($recent_open_complaints)): ?>
                            <div class="owner-queue-list">
                                <?php foreach ($recent_open_complaints as $complaint): ?>
                                    <?php
                                        $priority_value = strtolower(trim((string)($complaint['priority'] ?? 'medium')));
                                        if (!in_array($priority_value, ['low', 'medium', 'high', 'urgent'], true)) {
                                            $priority_value = 'medium';
                                        }
                                        $status_value = strtolower(trim((string)($complaint['status'] ?? 'open')));
                                        $status_label = ucwords(str_replace('_', ' ', $status_value));
                                        $is_escalated = (int)($complaint['is_escalated'] ?? 0) === 1;
                                        $customer_name = trim((string)($complaint['customer_name'] ?? 'Unknown User'));
                                        $customer_email = trim((string)($complaint['customer_email'] ?? ''));
                                        $subject_text = trim((string)($complaint['subject'] ?? 'Complaint Thread'));
                                        $reference_time = !empty($complaint['updated_at']) ? (string)$complaint['updated_at'] : (string)($complaint['created_at'] ?? '');
                                        $reference_time_label = $reference_time !== '' ? date('M d, Y h:i A', strtotime($reference_time)) : 'N/A';
                                        $complaint_link = '../super_admin/reports_complaints.php';
                                        if (isset($complaint['id'])) {
                                            $complaint_link .= '?conversation_id=' . urlencode((string)$complaint['id']);
                                        }
                                    ?>
                                    <div class="owner-queue-item">
                                        <div>
                                            <b><?php echo htmlspecialchars($subject_text !== '' ? $subject_text : 'Complaint Thread'); ?></b>
                                            <div class="owner-queue-meta">
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($customer_name !== '' ? $customer_name : 'Unknown User'); ?></span>
                                                <?php if ($customer_email !== ''): ?>
                                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($customer_email); ?></span>
                                                <?php endif; ?>
                                                <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($status_label); ?></span>
                                                <span class="owner-priority-pill <?php echo htmlspecialchars($priority_value); ?>"><?php echo htmlspecialchars(strtoupper($priority_value)); ?></span>
                                                <?php if ($is_escalated): ?>
                                                    <span class="owner-priority-pill urgent">Escalated</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="owner-queue-time">
                                            <div><?php echo htmlspecialchars($reference_time_label); ?></div>
                                            <a href="<?php echo htmlspecialchars($complaint_link); ?>" class="owner-link">Open</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No open complaint conversations found right now.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="owner-note">
                            Complaint queue metrics are unavailable because <code>chat_conversations</code> is missing. Re-run chat module migration to enable owner-level complaint analytics.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="owner-section">
                    <div class="owner-section-head">
                        <div>
                            <h3>Module Health & Queue Signals</h3>
                            <p>Operational health snapshot for each owner-controlled module.</p>
                        </div>
                    </div>
                    <div class="owner-module-health-grid">
                        <?php foreach ($owner_module_health_cards as $module_card): ?>
                            <?php
                                $module_severity = strtolower((string)($module_card['severity'] ?? 'watch'));
                                if (!in_array($module_severity, ['healthy', 'watch', 'critical'], true)) {
                                    $module_severity = 'watch';
                                }
                            ?>
                            <a class="owner-module-card" href="<?php echo htmlspecialchars((string)($module_card['href'] ?? '#')); ?>">
                                <div class="owner-module-top">
                                    <span class="owner-module-title">
                                        <i class="fas <?php echo htmlspecialchars((string)($module_card['icon'] ?? 'fa-circle-info')); ?>"></i>
                                        <?php echo htmlspecialchars((string)($module_card['label'] ?? 'Module')); ?>
                                    </span>
                                    <span class="owner-module-state <?php echo htmlspecialchars($module_severity); ?>"><?php echo htmlspecialchars(strtoupper($module_severity)); ?></span>
                                </div>
                                <div class="owner-module-metric"><?php echo htmlspecialchars((string)($module_card['metric'] ?? '0')); ?></div>
                                <div class="owner-module-subtitle"><?php echo htmlspecialchars((string)($module_card['subtitle'] ?? '')); ?></div>
                                <div class="owner-module-detail"><?php echo htmlspecialchars((string)($module_card['detail'] ?? '')); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="owner-section" id="partnerWarningsPanel">
                    <div class="owner-section-head">
                        <div>
                            <h3>Partner Violations Desk</h3>
                            <p>Issue warning notices for policy violations and monitor unresolved partner compliance cases.</p>
                        </div>
                        <a href="../super_admin/security_access_control.php" class="owner-link">Security module</a>
                    </div>

                    <?php if ($has_partner_warnings): ?>
                        <div class="owner-priority-grid">
                            <div class="owner-priority-card <?php echo $active_partner_warnings_total > 0 ? 'watch' : 'healthy'; ?>">
                                <span>Active Warnings</span>
                                <strong><?php echo number_format($active_partner_warnings_total); ?></strong>
                                <small>Open warning notices to partners</small>
                            </div>
                            <div class="owner-priority-card <?php echo $high_severity_partner_warnings > 0 ? 'critical' : 'healthy'; ?>">
                                <span>High Severity</span>
                                <strong><?php echo number_format($high_severity_partner_warnings); ?></strong>
                                <small>High and critical violations</small>
                            </div>
                            <div class="owner-priority-card <?php echo $partners_with_active_warnings > 0 ? 'watch' : 'healthy'; ?>">
                                <span>Flagged Partners</span>
                                <strong><?php echo number_format($partners_with_active_warnings); ?></strong>
                                <small>Distinct partner shops warned</small>
                            </div>
                        </div>

                        <?php if (!empty($recent_partner_warning_feed)): ?>
                            <div class="owner-list">
                                <?php foreach ($recent_partner_warning_feed as $warning_item): ?>
                                    <?php
                                        $warning_severity = strtolower(trim((string)($warning_item['severity'] ?? 'medium')));
                                        if (!in_array($warning_severity, ['low', 'medium', 'high', 'critical'], true)) {
                                            $warning_severity = 'medium';
                                        }
                                        $warning_subject = trim((string)($warning_item['warning_subject'] ?? 'Policy Warning'));
                                        $warning_message = trim((string)($warning_item['warning_message'] ?? ''));
                                        $warning_store = trim((string)($warning_item['store_name'] ?? 'Partner Store'));
                                        $warning_issuer = trim((string)($warning_item['issued_by_name'] ?? 'System Owner'));
                                        $warning_issued_at = !empty($warning_item['issued_at']) ? date('M d, Y h:i A', strtotime((string)$warning_item['issued_at'])) : 'N/A';
                                        $severity_pill_class = $warning_severity === 'critical' ? 'critical' : $warning_severity;
                                    ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><?php echo htmlspecialchars($warning_store !== '' ? $warning_store : 'Partner Store'); ?>: <?php echo htmlspecialchars($warning_subject !== '' ? $warning_subject : 'Policy Warning'); ?></strong>
                                            <small><?php echo htmlspecialchars($warning_message !== '' ? $warning_message : 'No warning details recorded.'); ?></small>
                                            <small>Issued by <?php echo htmlspecialchars($warning_issuer !== '' ? $warning_issuer : 'System Owner'); ?> on <?php echo htmlspecialchars($warning_issued_at); ?></small>
                                        </div>
                                        <span class="owner-priority-pill <?php echo htmlspecialchars($severity_pill_class); ?>">
                                            <?php echo htmlspecialchars(strtoupper($warning_severity)); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No active partner warning notices right now.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="owner-note">
                            Partner warning records are not available yet. Re-run Tenant Scope Migration to provision owner enforcement tools.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="owner-section">
                    <div class="owner-section-head">
                        <div>
                            <h3>Owner Quick Controls</h3>
                            <p>Direct links for the modules that should remain under your authority.</p>
                        </div>
                    </div>
                    <div class="owner-action-grid">
                        <?php foreach ($quick_actions as $action): ?>
                            <a class="owner-action" href="<?php echo htmlspecialchars($action['href']); ?>">
                                <i class="fas <?php echo htmlspecialchars($action['icon']); ?>"></i>
                                <strong><?php echo htmlspecialchars($action['label']); ?></strong>
                                <small><?php echo htmlspecialchars($action['meta']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="owner-section" id="storeLeaderboards">
                    <div class="owner-section-head">
                        <div>
                            <h3>Store Leaderboard Filters</h3>
                            <p>Showing ranked stores for <strong><?php echo htmlspecialchars($leaderboard_range_label); ?></strong>.</p>
                        </div>
                        <div class="owner-range-filter">
                            <?php foreach ($leaderboard_range_options as $range_key => $range_label): ?>
                                <?php $is_active_range = $leaderboard_range === $range_key; ?>
                                <a class="owner-range-pill <?php echo $is_active_range ? 'active' : ''; ?>" href="../super_admin/super_admin_dashboard.php?leaderboard_range=<?php echo urlencode($range_key); ?>#storeLeaderboards">
                                    <?php echo htmlspecialchars($range_label); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="owner-two-col">
                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Top Rated Stores</h3>
                                <p>Highest-rated partner stores based on combined store and delivery feedback in <?php echo htmlspecialchars($leaderboard_range_label); ?>.</p>
                            </div>
                            <a href="#businessPerformanceBoard" class="owner-link">View full board</a>
                        </div>
                        <?php if (!empty($top_rated_stores)): ?>
                            <div class="owner-list">
                                <?php foreach ($top_rated_stores as $index => $store): ?>
                                    <?php
                                        $rank = $index + 1;
                                        $rating_value = (float)($store['business_rating'] ?? 0);
                                        $store_reviews = (int)($store['review_count'] ?? 0);
                                        $delivery_reviews = (int)($store['delivery_review_count'] ?? 0);
                                        $total_reviews = $store_reviews + $delivery_reviews;
                                    ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><span class="owner-rank-badge">#<?php echo number_format($rank); ?></span><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Partner Store')); ?></strong>
                                            <small>
                                                <?php echo number_format($total_reviews); ?> reviews
                                                | <?php echo number_format((int)($store['order_count'] ?? 0)); ?> orders
                                                | <?php echo number_format((float)($store['delivery_rate'] ?? 0), 1); ?>% delivery success
                                            </small>
                                        </div>
                                        <b><?php echo number_format($rating_value, 2); ?>/5</b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No store ratings are available for <?php echo htmlspecialchars($leaderboard_range_label); ?>.</div>
                        <?php endif; ?>
                    </div>

                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Top Selling Stores</h3>
                                <p>Highest grossing partner stores based on approved business sales in <?php echo htmlspecialchars($leaderboard_range_label); ?>.</p>
                            </div>
                            <a href="#businessPerformanceBoard" class="owner-link">View full board</a>
                        </div>
                        <?php if (!empty($top_selling_stores)): ?>
                            <div class="owner-list">
                                <?php foreach ($top_selling_stores as $index => $store): ?>
                                    <?php
                                        $rank = $index + 1;
                                        $gross_sales = (float)($store['display_gross_revenue'] ?? 0);
                                        $rating_value = (float)($store['business_rating'] ?? 0);
                                    ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><span class="owner-rank-badge">#<?php echo number_format($rank); ?></span><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Partner Store')); ?></strong>
                                            <small>
                                                <?php echo number_format((int)($store['order_count'] ?? 0)); ?> orders
                                                | <?php echo number_format((int)($store['delivered_orders'] ?? 0)); ?> delivered
                                                | Rating <?php echo $rating_value > 0 ? number_format($rating_value, 2) . '/5' : 'N/A'; ?>
                                            </small>
                                        </div>
                                        <b>PHP <?php echo number_format($gross_sales, 2); ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No partner sales data is available for <?php echo htmlspecialchars($leaderboard_range_label); ?>.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="owner-two-col">
                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Platform Watchlist</h3>
                                <p>Important owner-level signals across the whole system.</p>
                            </div>
                        </div>
                        <div class="owner-list">
                            <div class="owner-list-item"><span>Pending partner approvals</span><b><?php echo number_format($pending_applications); ?></b></div>
                            <div class="owner-list-item"><span>Low stock items across stores</span><b><?php echo number_format($low_stock_items); ?></b></div>
                            <div class="owner-list-item"><span>Active store locations</span><b><?php echo number_format($store_locations); ?></b></div>
                            <div class="owner-list-item"><span><?php echo $commission_metrics_are_actual ? 'Actual platform commission' : 'Estimated platform commission'; ?></span><b>PHP <?php echo number_format($platform_commission_total, 2); ?></b></div>
                            <div class="owner-list-item"><span>RBAC permissions configured</span><b><?php echo number_format($permissions_count); ?></b></div>
                        </div>
                    </div>

                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Recent Business Applications</h3>
                                <p>Latest onboarding records visible only to your owner dashboard.</p>
                            </div>
                            <a href="../super_admin/franchise_applications.php" class="owner-link">View all</a>
                        </div>
                        <?php if (!empty($recent_applications)): ?>
                            <div class="owner-list">
                                <?php foreach ($recent_applications as $application): ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><?php echo htmlspecialchars($application['application_number'] ?? 'N/A'); ?></strong>
                                            <small><?php echo htmlspecialchars($application['business_name'] ?? 'Business Partner'); ?> by <?php echo htmlspecialchars($application['owner_name'] ?? 'Unknown'); ?></small>
                                        </div>
                                        <b><?php echo htmlspecialchars(ucfirst((string)($application['status'] ?? 'unknown'))); ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No business applications found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="owner-section" id="businessPerformanceBoard">
                    <div class="owner-section-head">
                        <div>
                            <h3>Business Performance Board</h3>
                            <p>Strategic performance view for each registered business without exposing their product catalog.</p>
                        </div>
                    </div>
                    <?php if (!empty($business_performance_rows)): ?>
                        <div class="owner-business-grid">
                            <?php foreach ($business_performance_rows as $business): ?>
                                <?php
                                    $active_warning_count = (int)($business['active_warning_count'] ?? 0);
                                    $high_warning_count = (int)($business['high_warning_count'] ?? 0);
                                    $warning_chip_class = $active_warning_count === 0
                                        ? 'healthy'
                                        : ($high_warning_count > 0 ? 'critical' : 'watch');
                                    $latest_warning_subject = trim((string)($business['latest_warning_subject'] ?? ''));
                                    $latest_warning_message = trim((string)($business['latest_warning_message'] ?? ''));
                                    $latest_warning_severity = strtolower(trim((string)($business['latest_warning_severity'] ?? 'medium')));
                                    if (!in_array($latest_warning_severity, ['low', 'medium', 'high', 'critical'], true)) {
                                        $latest_warning_severity = 'medium';
                                    }
                                    $latest_warning_date = !empty($business['latest_warning_issued_at'])
                                        ? date('M d, Y h:i A', strtotime((string)$business['latest_warning_issued_at']))
                                        : '';
                                    $active_warning_options = (isset($business['active_warning_options']) && is_array($business['active_warning_options']))
                                        ? $business['active_warning_options']
                                        : [];
                                ?>
                                <div class="owner-business-card">
                                    <div class="owner-business-head">
                                        <div>
                                            <h4><?php echo htmlspecialchars($business['store_name'] ?? 'Partner Store'); ?></h4>
                                            <small>
                                                <?php if (!empty($business['last_order_at'])): ?>
                                                    Last order <?php echo date('M d, Y h:i A', strtotime($business['last_order_at'])); ?>
                                                <?php else: ?>
                                                    No orders yet
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div style="display:grid;gap:8px;justify-items:end;">
                                            <span class="owner-badge-inline"><?php echo ($business['business_rating'] ?? 0) > 0 ? number_format((float)$business['business_rating'], 2) . '/5' : 'No rating'; ?></span>
                                            <span class="owner-status-pill <?php echo htmlspecialchars(strtolower((string)($business['account_control_status'] ?? 'active'))); ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)($business['account_control_status'] ?? 'active'))); ?>
                                            </span>
                                            <span class="owner-warning-chip <?php echo htmlspecialchars($warning_chip_class); ?>">
                                                <?php echo number_format($active_warning_count); ?> warning<?php echo $active_warning_count === 1 ? '' : 's'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="owner-business-stats">
                                        <div><span>Orders</span><strong><?php echo number_format((int)($business['order_count'] ?? 0)); ?></strong></div>
                                        <div><span>Delivered</span><strong><?php echo number_format((int)($business['delivered_orders'] ?? 0)); ?></strong></div>
                                        <div><span>Delivery Rate</span><strong><?php echo number_format((float)($business['delivery_rate'] ?? 0), 1); ?>%</strong></div>
                                        <div><span>Store Reviews</span><strong><?php echo number_format((int)($business['review_count'] ?? 0)); ?></strong></div>
                                        <div><span>Delivery Reviews</span><strong><?php echo number_format((int)($business['delivery_review_count'] ?? 0)); ?></strong></div>
                                        <div><span>Cancellations</span><strong><?php echo number_format((int)($business['cancellations_count'] ?? 0)); ?></strong></div>
                                    </div>
                                    <div class="owner-business-finance">
                                        <div><span>Gross Revenue</span><strong>PHP <?php echo number_format((float)($business['display_gross_revenue'] ?? 0), 2); ?></strong></div>
                                        <div><span><?php echo !empty($business['uses_actual_settlement']) ? 'Commission' : 'Est. Commission'; ?></span><strong>PHP <?php echo number_format((float)($business['commission_total'] ?? 0), 2); ?></strong></div>
                                        <div><span>Partner Net</span><strong>PHP <?php echo number_format((float)($business['partner_net_revenue'] ?? 0), 2); ?></strong></div>
                                        <div><span>Refunded</span><strong>PHP <?php echo number_format((float)($business['refunded_total'] ?? 0), 2); ?></strong></div>
                                    </div>
                                    <?php if (!empty(trim((string)($business['access_restriction_notes'] ?? '')))): ?>
                                        <div class="owner-note" style="margin-top:0;">
                                            Control note: <?php echo htmlspecialchars((string)$business['access_restriction_notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($active_warning_count > 0): ?>
                                        <div class="owner-note" style="margin-top:0;">
                                            Latest warning:
                                            <?php if ($latest_warning_subject !== ''): ?>
                                                <strong><?php echo htmlspecialchars($latest_warning_subject); ?></strong>
                                            <?php else: ?>
                                                <strong>Policy violation notice</strong>
                                            <?php endif; ?>
                                            <span class="owner-priority-pill <?php echo htmlspecialchars($latest_warning_severity === 'critical' ? 'critical' : $latest_warning_severity); ?>" style="margin-left:8px;">
                                                <?php echo htmlspecialchars(strtoupper($latest_warning_severity)); ?>
                                            </span>
                                            <?php if ($latest_warning_date !== ''): ?>
                                                <div style="margin-top:6px;"><?php echo htmlspecialchars($latest_warning_date); ?></div>
                                            <?php endif; ?>
                                            <?php if ($latest_warning_message !== ''): ?>
                                                <div style="margin-top:4px;"><?php echo htmlspecialchars($latest_warning_message); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($has_partner_warnings && $active_warning_count > 0 && !empty($active_warning_options)): ?>
                                        <form method="post" class="owner-warning-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="partner_user_id" value="<?php echo (int)($business['partner_user_id'] ?? 0); ?>">
                                            <input type="hidden" name="partner_warning_resolve_action" value="1">
                                            <div class="owner-warning-actions">
                                                <div style="min-width:260px;flex:1;">
                                                    <label for="warning_id_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Resolve Selected Warning</label>
                                                    <select id="warning_id_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" name="warning_id" required>
                                                        <?php foreach ($active_warning_options as $warning_option): ?>
                                                            <?php
                                                                $opt_id = (int)($warning_option['id'] ?? 0);
                                                                if ($opt_id <= 0) {
                                                                    continue;
                                                                }
                                                                $opt_severity = strtolower(trim((string)($warning_option['severity'] ?? 'medium')));
                                                                if (!in_array($opt_severity, ['low', 'medium', 'high', 'critical'], true)) {
                                                                    $opt_severity = 'medium';
                                                                }
                                                                $opt_subject = trim((string)($warning_option['subject'] ?? 'Policy Warning'));
                                                                $opt_issued_at = !empty($warning_option['issued_at'])
                                                                    ? date('M d, Y h:i A', strtotime((string)$warning_option['issued_at']))
                                                                    : 'N/A';
                                                                $option_label = '[' . strtoupper($opt_severity) . '] ' . ($opt_subject !== '' ? $opt_subject : 'Policy Warning') . ' | ' . $opt_issued_at;
                                                            ?>
                                                            <option value="<?php echo $opt_id; ?>"><?php echo htmlspecialchars($option_label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="submit"
                                                        class="owner-resolve-submit"
                                                        data-sa-confirm="1"
                                                        data-sa-confirm-title-template="Resolve Warning?"
                                                        data-sa-confirm-text-template="This will resolve: {field_label:warning_id}"
                                                        data-sa-confirm-confirm-text="Yes, Resolve Warning"
                                                        data-sa-confirm-confirm-color="#166534">
                                                    Resolve Warning
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($has_user_control_status): ?>
                                        <form method="post" class="owner-control-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="partner_user_id" value="<?php echo (int)($business['partner_user_id'] ?? 0); ?>">
                                            <input type="hidden" name="partner_control_action" value="1">
                                            <div class="owner-control-grid">
                                                <div>
                                                    <label for="target_status_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Partner Access</label>
                                                    <select id="target_status_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" name="target_status">
                                                        <?php $selected_status = strtolower((string)($business['account_control_status'] ?? 'active')); ?>
                                                        <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="restricted" <?php echo $selected_status === 'restricted' ? 'selected' : ''; ?>>Restricted</option>
                                                        <option value="suspended" <?php echo $selected_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                                        <option value="banned" <?php echo $selected_status === 'banned' ? 'selected' : ''; ?>>Banned</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="control_note_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Note</label>
                                                    <input id="control_note_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" type="text" name="control_note" value="<?php echo htmlspecialchars((string)($business['access_restriction_notes'] ?? '')); ?>" placeholder="Reason or internal note">
                                                </div>
                                                <div>
                                                    <button type="submit"
                                                            class="owner-control-submit"
                                                            data-sa-confirm="1"
                                                            data-sa-confirm-title-template="Set Partner Access to {field_label:target_status}?"
                                                            data-sa-confirm-text-template="This will update this partner account access status to {field_label:target_status} immediately."
                                                            data-sa-confirm-confirm-text-template="Yes, Set to {field_label:target_status}"
                                                            data-sa-confirm-confirm-color="#9f1239">
                                                        Apply
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($has_partner_warnings): ?>
                                        <form method="post" class="owner-warning-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="partner_user_id" value="<?php echo (int)($business['partner_user_id'] ?? 0); ?>">
                                            <input type="hidden" name="partner_warning_action" value="1">
                                            <div class="owner-warning-grid">
                                                <div>
                                                    <label for="warning_subject_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Warning Subject</label>
                                                    <input id="warning_subject_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" type="text" name="warning_subject" maxlength="180" placeholder="Violation title (e.g. Late fulfillment, abusive conduct)">
                                                </div>
                                                <div>
                                                    <label for="warning_severity_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Severity</label>
                                                    <select id="warning_severity_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" name="warning_severity">
                                                        <option value="low">Low</option>
                                                        <option value="medium" selected>Medium</option>
                                                        <option value="high">High</option>
                                                        <option value="critical">Critical</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div>
                                                <label for="warning_message_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Violation Details</label>
                                                <textarea id="warning_message_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" name="warning_message" maxlength="2000" required placeholder="Explain the violation and required corrective action."></textarea>
                                            </div>
                                            <div class="owner-warning-actions">
                                                <div style="min-width:220px;">
                                                    <label for="warning_followup_status_<?php echo (int)($business['partner_user_id'] ?? 0); ?>">Follow-up Action</label>
                                                    <select id="warning_followup_status_<?php echo (int)($business['partner_user_id'] ?? 0); ?>" name="warning_followup_status">
                                                        <option value="none" selected>No account status change</option>
                                                        <option value="restricted">Warn + Restrict account</option>
                                                        <option value="suspended">Warn + Suspend account</option>
                                                    </select>
                                                </div>
                                                <button type="submit"
                                                        class="owner-warning-submit"
                                                        data-sa-confirm="1"
                                                        data-sa-confirm-title="Issue Partner Warning?"
                                                        data-sa-confirm-text-template="Severity: {field_label:warning_severity}. Follow-up: {field_label:warning_followup_status}."
                                                        data-sa-confirm-confirm-text-template="Yes, Issue {field_label:warning_severity} Warning"
                                                        data-sa-confirm-confirm-color="#b91c1c">
                                                    Issue Warning
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="owner-empty">No registered business performance data is available yet.</div>
                    <?php endif; ?>
                </div>

                <div class="owner-two-col">
                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Customer Feedback by Business</h3>
                                <p>Recent customer sentiment tied to each business and their operations.</p>
                            </div>
                        </div>
                        <?php if (!empty($recent_customer_feedback)): ?>
                            <div class="owner-list">
                                <?php foreach ($recent_customer_feedback as $feedback): ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><?php echo htmlspecialchars($feedback['store_name'] ?? 'Partner Store'); ?></strong>
                                            <small><?php echo htmlspecialchars($feedback['customer_name'] ?? 'Anonymous Customer'); ?>, <?php echo htmlspecialchars($feedback['feedback_type'] ?? 'Feedback'); ?>, <?php echo !empty($feedback['created_at']) ? date('M d, Y h:i A', strtotime($feedback['created_at'])) : 'N/A'; ?></small>
                                            <small><?php echo htmlspecialchars(trim((string)($feedback['comment'] ?? '')) !== '' ? (string)$feedback['comment'] : 'No written comment provided.'); ?></small>
                                        </div>
                                        <b><?php echo number_format((float)($feedback['rating'] ?? 0), 1); ?>/5</b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No customer feedback for partner businesses yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="owner-section">
                        <div class="owner-section-head">
                            <div>
                                <h3>Latest Orders</h3>
                                <p>Recent order stream across the whole platform.</p>
                            </div>
                        </div>
                        <?php if (!empty($recent_orders)): ?>
                            <div class="owner-list">
                                <?php foreach ($recent_orders as $order): ?>
                                    <div class="owner-list-item owner-stack">
                                        <div>
                                            <strong><?php echo htmlspecialchars($order['order_number'] ?? 'N/A'); ?></strong>
                                            <small><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?>, <?php echo !empty($order['created_at']) ? date('M d, Y h:i A', strtotime($order['created_at'])) : 'N/A'; ?></small>
                                        </div>
                                        <b>PHP <?php echo number_format((float)($order['total_amount'] ?? 0), 2); ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="owner-empty">No orders found yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
    <script>
        const dashboardFlashMessages = <?php echo $dashboard_flash_payload; ?>;
        const body = document.body;
        const themeToggler = document.getElementById('themeToggler');
        const themeIcon = themeToggler ? themeToggler.querySelector('i') : null;
        const dateDisplay = document.getElementById('currentDate');

        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            if (themeIcon) {
                themeIcon.className = 'fas fa-sun';
            }
        }

        if (themeToggler) {
            themeToggler.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const dark = body.classList.contains('dark-mode');
                localStorage.setItem('theme', dark ? 'dark' : 'light');
                if (themeIcon) {
                    themeIcon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
                }
            });
        }

        if (dateDisplay) {
            dateDisplay.textContent = new Date().toLocaleString([], {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        }

        if (window.Swal && Array.isArray(dashboardFlashMessages) && dashboardFlashMessages.length > 0) {
            let queue = Promise.resolve();
            dashboardFlashMessages.forEach((entry) => {
                const type = String(entry && entry.type ? entry.type : 'info').toLowerCase();
                const message = String(entry && entry.message ? entry.message : '').trim();
                if (!message) {
                    return;
                }
                const icon = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
                const title = icon === 'success'
                    ? 'Success'
                    : (icon === 'error' ? 'Error' : (icon === 'warning' ? 'Warning' : 'Notice'));

                queue = queue.then(() => Swal.fire({
                    icon,
                    title,
                    text: message,
                    confirmButtonColor: '#9f1239'
                }));
            });
        }

        if (window.Swal) {
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
        }
    </script>
</body>
</html>
