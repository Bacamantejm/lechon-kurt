<?php
session_start();
include 'auth.php';
include '../includes/config.php';
require_once '../includes/partner_voucher_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? (int)getFranchiseSellerScopeOwnerId($conn, $current_user_id) : 0;
$is_full_admin = !$is_partner_scoped_admin;

pvEnsureVoucherSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    $code = pvNormalizeVoucherCode($_POST['code'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $discount_type = strtolower(trim((string)($_POST['discount_type'] ?? 'fixed')));
    $discount_value = round((float)($_POST['discount_value'] ?? 0), 2);
    $min_order_amount = max(0, round((float)($_POST['min_order_amount'] ?? 0), 2));
    $max_discount_amount = round((float)($_POST['max_discount_amount'] ?? 0), 2);
    $start_at = trim((string)($_POST['start_at'] ?? ''));
    $end_at = trim((string)($_POST['end_at'] ?? ''));
    $usage_limit = max(0, (int)($_POST['usage_limit'] ?? 0));
    $per_user_limit = max(1, (int)($_POST['per_user_limit'] ?? 1));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (strlen($code) < 3) {
        $errors[] = 'Voucher code must be at least 3 characters.';
    }
    if ($name === '') {
        $errors[] = 'Voucher name is required.';
    }
    if (!in_array($discount_type, ['percent', 'fixed'], true)) {
        $errors[] = 'Invalid discount type selected.';
    }
    if ($discount_value <= 0) {
        $errors[] = 'Discount value must be greater than zero.';
    }
    if ($discount_type === 'percent' && $discount_value > 100) {
        $errors[] = 'Percentage discount cannot exceed 100%.';
    }
    if ($max_discount_amount < 0) {
        $errors[] = 'Maximum discount amount cannot be negative.';
    }

    $start_db = '';
    $end_db = '';
    if ($start_at !== '') {
        $start_ts = strtotime($start_at);
        if ($start_ts === false) {
            $errors[] = 'Invalid voucher start date.';
        } else {
            $start_db = date('Y-m-d H:i:s', $start_ts);
        }
    }
    if ($end_at !== '') {
        $end_ts = strtotime($end_at);
        if ($end_ts === false) {
            $errors[] = 'Invalid voucher end date.';
        } else {
            $end_db = date('Y-m-d H:i:s', $end_ts);
        }
    }
    if ($start_db !== '' && $end_db !== '' && strtotime($end_db) < strtotime($start_db)) {
        $errors[] = 'Voucher end date must be after start date.';
    }

    $target_seller_id = $seller_scope_id;
    if ($is_full_admin) {
        $target_seller_id = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
    }

    if (empty($errors)) {
        $insert_sql = "INSERT INTO partner_vouchers
            (seller_id, code, name, description, discount_type, discount_value, min_order_amount, max_discount_amount, start_at, end_at, usage_limit, per_user_limit, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, NOW(), NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        if ($insert_stmt) {
            $max_discount_for_sql = $max_discount_amount > 0 ? $max_discount_amount : 0;
            mysqli_stmt_bind_param(
                $insert_stmt,
                "issssdddssiii",
                $target_seller_id,
                $code,
                $name,
                $description,
                $discount_type,
                $discount_value,
                $min_order_amount,
                $max_discount_for_sql,
                $start_db,
                $end_db,
                $usage_limit,
                $per_user_limit,
                $is_active
            );

            if (mysqli_stmt_execute($insert_stmt)) {
                $_SESSION['success'] = 'Voucher created successfully.';
            } else {
                $_SESSION['error'] = (mysqli_errno($conn) === 1062)
                    ? 'Voucher code already exists for this shop/platform.'
                    : ('Failed to create voucher: ' . mysqli_error($conn));
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $_SESSION['error'] = 'Failed to prepare voucher insert query.';
        }
    } else {
        $_SESSION['error'] = implode("\n", $errors);
    }

    header('Location: vouchers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_voucher'])) {
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    if ($voucher_id > 0) {
        if ($is_full_admin) {
            $toggle_sql = "UPDATE partner_vouchers
                           SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW()
                           WHERE id = ?";
            $toggle_stmt = mysqli_prepare($conn, $toggle_sql);
            if ($toggle_stmt) {
                mysqli_stmt_bind_param($toggle_stmt, "i", $voucher_id);
                mysqli_stmt_execute($toggle_stmt);
                $affected_rows = mysqli_stmt_affected_rows($toggle_stmt);
                $_SESSION[($affected_rows > 0) ? 'success' : 'error'] =
                    ($affected_rows > 0) ? 'Voucher status updated.' : 'Voucher not found.';
                mysqli_stmt_close($toggle_stmt);
            }
        } else {
            $toggle_sql = "UPDATE partner_vouchers
                           SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW()
                           WHERE id = ? AND seller_id = ?";
            $toggle_stmt = mysqli_prepare($conn, $toggle_sql);
            if ($toggle_stmt) {
                mysqli_stmt_bind_param($toggle_stmt, "ii", $voucher_id, $seller_scope_id);
                mysqli_stmt_execute($toggle_stmt);
                $affected_rows = mysqli_stmt_affected_rows($toggle_stmt);
                $_SESSION[($affected_rows > 0) ? 'success' : 'error'] =
                    ($affected_rows > 0) ? 'Voucher status updated.' : 'Voucher not found or unauthorized.';
                mysqli_stmt_close($toggle_stmt);
            }
        }
    } else {
        $_SESSION['error'] = 'Invalid voucher selection.';
    }

    header('Location: vouchers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher'])) {
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    if ($voucher_id > 0) {
        if ($is_full_admin) {
            @mysqli_query($conn, "DELETE FROM partner_voucher_redemptions WHERE voucher_id = {$voucher_id}");
            $del_stmt = mysqli_prepare($conn, "DELETE FROM partner_vouchers WHERE id = ?");
            if ($del_stmt) {
                mysqli_stmt_bind_param($del_stmt, "i", $voucher_id);
                mysqli_stmt_execute($del_stmt);
                $_SESSION['success'] = 'Voucher deleted permanently.';
                mysqli_stmt_close($del_stmt);
            }
        } else {
            @mysqli_query($conn, "DELETE FROM partner_voucher_redemptions WHERE voucher_id = {$voucher_id} AND seller_id = {$seller_scope_id}");
            $del_stmt = mysqli_prepare($conn, "DELETE FROM partner_vouchers WHERE id = ? AND seller_id = ?");
            if ($del_stmt) {
                mysqli_stmt_bind_param($del_stmt, "ii", $voucher_id, $seller_scope_id);
                mysqli_stmt_execute($del_stmt);
                $_SESSION['success'] = 'Voucher deleted permanently.';
                mysqli_stmt_close($del_stmt);
            }
        }
    } else {
        $_SESSION['error'] = 'Invalid voucher selection.';
    }

    header('Location: vouchers.php');
    exit();
}

$vouchers = [];
$total_redemptions = 0;
if ($is_full_admin) {
    $list_query = "SELECT pv.*, COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name, 'Platform Voucher') AS store_name 
                   FROM partner_vouchers pv 
                   LEFT JOIN users u ON pv.seller_id = u.id 
                   ORDER BY pv.created_at DESC";
    $list_result = mysqli_query($conn, $list_query);
    if ($list_result) {
        while ($row = mysqli_fetch_assoc($list_result)) {
            $vouchers[] = $row;
            $total_redemptions += (int)($row['usage_count'] ?? 0);
        }
        mysqli_free_result($list_result);
    }
} else {
    $list_stmt = mysqli_prepare($conn, "SELECT pv.*, 'My Store' AS store_name FROM partner_vouchers pv WHERE pv.seller_id = ? ORDER BY pv.created_at DESC");
    if ($list_stmt) {
        mysqli_stmt_bind_param($list_stmt, "i", $seller_scope_id);
        mysqli_stmt_execute($list_stmt);
        $list_result = mysqli_stmt_get_result($list_stmt);
        if ($list_result) {
            while ($row = mysqli_fetch_assoc($list_result)) {
                $vouchers[] = $row;
                $total_redemptions += (int)($row['usage_count'] ?? 0);
            }
            mysqli_free_result($list_result);
        }
        mysqli_stmt_close($list_stmt);
    }
}

// Compute summary counts
$active_vouchers_count = 0;
$platform_vouchers_count = 0;
foreach ($vouchers as $v) {
    if ((int)($v['is_active'] ?? 0) === 1) {
        $active_vouchers_count++;
    }
    if ((int)($v['seller_id'] ?? 0) === 0) {
        $platform_vouchers_count++;
    }
}

// Fetch stores list for full admin selection
$admin_store_options = [];
if ($is_full_admin) {
    $store_opt_res = mysqli_query($conn, "SELECT id, COALESCE(NULLIF(TRIM(business_name), ''), full_name, 'Shop') AS store_name FROM users WHERE user_type = 'admin' OR account_type = 'organization' ORDER BY store_name ASC");
    if ($store_opt_res) {
        while ($so = mysqli_fetch_assoc($store_opt_res)) {
            $admin_store_options[] = $so;
        }
        mysqli_free_result($store_opt_res);
    }
}

$flash_success = $_SESSION['success'] ?? null;
$flash_error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers &amp; Discounts - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Modern Vouchers & Discounts UI Styles */
        .vouchers-page-wrap {
            padding-bottom: 140px;
        }

        /* Metric Cards Grid */
        .vouchers-metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .voucher-stat-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .voucher-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
        }

        .voucher-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .voucher-stat-icon.red {
            background: #fff1f0;
            color: #b3261e;
        }

        .voucher-stat-icon.green {
            background: #ecfdf3;
            color: #027a48;
        }

        .voucher-stat-icon.blue {
            background: #eff8ff;
            color: #175cd3;
        }

        .voucher-stat-icon.amber {
            background: #fffaeb;
            color: #b54708;
        }

        .voucher-stat-info .stat-val {
            font-size: 20px;
            font-weight: 700;
            color: #101828;
            line-height: 1.2;
        }

        .voucher-stat-info .stat-lbl {
            font-size: 12px;
            color: #667085;
            font-weight: 500;
        }

        /* Builder Card Layout */
        .voucher-builder-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .voucher-builder-head {
            padding: 16px 20px;
            border-bottom: 1px solid #eaecf0;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .voucher-builder-head .title-group h2 {
            font-size: 16px;
            font-weight: 700;
            color: #101828;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .voucher-builder-head .title-group h2 i {
            color: #b3261e;
            font-size: 16px;
        }

        .voucher-builder-head .title-group p {
            font-size: 12.5px;
            color: #667085;
            margin: 2px 0 0 0;
        }

        .voucher-builder-body {
            padding: 20px;
        }

        /* Form Step Groups */
        .form-step-group {
            background: #fbfcfd;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }

        .form-step-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f2f4f7;
        }

        .form-step-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1d2939;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-step-title i {
            color: #b3261e;
            font-size: 14px;
        }

        .step-num-pill {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            letter-spacing: 0.02em;
        }

        /* Compact Controls */
        .compact-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .compact-label .req {
            color: #b3261e;
            font-weight: 700;
            margin-left: 2px;
        }

        .compact-input-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .compact-input-group .input-addon {
            background: #f2f4f7;
            border: 1px solid #d0d5dd;
            border-right: none;
            color: #475467;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 12px;
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 38px;
            flex-shrink: 0;
        }

        .compact-input-group .form-control,
        .compact-input-group .form-select {
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            font-size: 13px;
            padding: 8px 12px;
            height: 38px;
            color: #101828;
            background: #ffffff;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
        }

        .compact-input-group.has-addon .form-control,
        .compact-input-group.has-addon .form-select,
        .compact-input-group .input-addon + .form-control,
        .compact-input-group .input-addon + .form-select {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .compact-input-group .form-control:focus,
        .compact-input-group .form-select:focus,
        textarea.compact-textarea:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            outline: none;
        }

        .compact-input-group .btn-inline-action {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            z-index: 3;
        }

        .compact-input-group .btn-inline-action:hover {
            background: #b3261e;
            color: #ffffff;
            border-color: #b3261e;
        }

        textarea.compact-textarea {
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            font-size: 13px;
            padding: 8px 12px;
            color: #101828;
            background: #ffffff;
            width: 100%;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
            resize: vertical;
        }

        /* Discount Type Segmented Selector */
        .discount-type-segmented {
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #f2f4f7;
            padding: 4px;
            border-radius: 8px;
            gap: 4px;
            margin-bottom: 14px;
        }

        .discount-type-option {
            cursor: pointer;
            text-align: center;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #475467;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            user-select: none;
        }

        .discount-type-option:hover {
            color: #101828;
        }

        .discount-type-option.active {
            background: #ffffff;
            color: #b3261e;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.1);
        }

        /* Preset Chips */
        .quick-preset-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }

        .preset-chip {
            background: #ffffff;
            border: 1px solid #d0d5dd;
            color: #344054;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.12s ease;
            line-height: 1.4;
        }

        .preset-chip:hover {
            background: #fff1f0;
            border-color: #fee4e2;
            color: #b3261e;
        }

        /* Live Preview Card */
        .live-preview-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            padding: 18px 20px;
            position: sticky;
            top: 90px;
        }

        .live-preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f2f4f7;
        }

        .live-preview-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #344054;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ticket-preview-box {
            background: #ffffff;
            border: 2px dashed #d0d5dd;
            border-radius: 12px;
            padding: 18px;
            position: relative;
            box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);
            transition: all 0.2s ease;
        }

        .ticket-badge-pill {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
            margin-bottom: 8px;
        }

        .ticket-discount-amount {
            font-size: 28px;
            font-weight: 800;
            color: #b3261e;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .ticket-campaign-name {
            font-size: 13.5px;
            font-weight: 600;
            color: #101828;
            margin-bottom: 12px;
        }

        .ticket-code-display {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 7px 12px;
            font-family: monospace;
            font-size: 14px;
            font-weight: 700;
            color: #101828;
            letter-spacing: 1.2px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .ticket-conditions-list {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 12px;
            color: #667085;
            display: grid;
            gap: 6px;
        }

        .ticket-conditions-list li {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ticket-conditions-list li i {
            color: #98a2b3;
            font-size: 12px;
            width: 14px;
            text-align: center;
        }

        /* Buttons & Actions */
        .builder-actions-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 16px;
            border-top: 1px solid #eaecf0;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-builder-primary {
            background: #b3261e;
            color: #ffffff;
            border: 1px solid #b3261e;
            border-radius: 8px;
            padding: 9px 22px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-builder-primary:hover {
            background: #981b15;
            border-color: #981b15;
            color: #ffffff;
        }

        .btn-builder-secondary {
            background: #ffffff;
            color: #344054;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-builder-secondary:hover {
            background: #f8f9fa;
            color: #101828;
        }

        /* Modern Switch */
        .form-check-modern {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            padding-left: 0;
            margin-bottom: 0;
        }

        .form-check-modern .form-check-input {
            width: 38px;
            height: 20px;
            cursor: pointer;
            margin-top: 0;
            float: none;
        }

        .form-check-modern .form-check-input:checked {
            background-color: #b3261e;
            border-color: #b3261e;
        }

        /* Manage Table Styling */
        .vouchers-table-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            overflow: hidden;
        }

        .code-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-weight: 700;
            font-size: 12.5px;
            color: #101828;
        }

        .copy-code-btn {
            background: none;
            border: none;
            color: #98a2b3;
            cursor: pointer;
            padding: 0;
            font-size: 11px;
            transition: color 0.15s ease;
        }

        .copy-code-btn:hover {
            color: #b3261e;
        }

        .status-badge-active {
            display: inline-block;
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .status-badge-inactive {
            display: inline-block;
            background: #f2f4f7;
            color: #475467;
            border: 1px solid #eaecf0;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
        }

        /* Dark Mode Overrides */
        body.dark-mode .voucher-stat-card,
        body.dark-mode .voucher-builder-card,
        body.dark-mode .vouchers-table-card,
        body.dark-mode .live-preview-card,
        body.dark-mode .voucher-builder-head {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .voucher-stat-info .stat-val,
        body.dark-mode .voucher-builder-head .title-group h2,
        body.dark-mode .form-step-title,
        body.dark-mode .compact-label,
        body.dark-mode .ticket-campaign-name {
            color: #f8fafc !important;
        }

        body.dark-mode .form-step-group {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        body.dark-mode .compact-input-group .form-control,
        body.dark-mode .compact-input-group .form-select,
        body.dark-mode textarea.compact-textarea {
            background: #1e293b !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .compact-input-group .input-addon {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #cbd5e1 !important;
        }

        body.dark-mode .discount-type-segmented {
            background: #334155 !important;
        }

        body.dark-mode .discount-type-option {
            color: #cbd5e1 !important;
        }

        body.dark-mode .discount-type-option.active {
            background: #1e293b !important;
            color: #f87171 !important;
        }

        body.dark-mode .ticket-preview-box {
            background: #0f172a !important;
            border-color: #475569 !important;
        }

        body.dark-mode .ticket-code-display,
        body.dark-mode .code-pill {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .preset-chip,
        body.dark-mode .btn-builder-secondary {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #cbd5e1 !important;
        }

        body.dark-mode .table thead th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-color: #334155 !important;
        }

        body.dark-mode .table tbody tr {
            border-color: #334155 !important;
        }
    </style>
</head>
<body class="admin-polish">
<div class="admin-container">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div class="topbar-content">
                <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                <h1>Vouchers &amp; Discounts</h1>
                <div class="admin-profile">
                    <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner Admin')); ?></span>
                    <i class="fas fa-user-circle"></i>
                </div>
            </div>
        </div>

        <div class="admin-main vouchers-page-wrap">
            <!-- 1. Top Metrics Strip -->
            <div class="vouchers-metric-grid">
                <div class="voucher-stat-card">
                    <div class="voucher-stat-icon red"><i class="fas fa-ticket-alt"></i></div>
                    <div class="voucher-stat-info">
                        <div class="stat-val"><?php echo number_format(count($vouchers)); ?></div>
                        <div class="stat-lbl">Total Vouchers</div>
                    </div>
                </div>
                <div class="voucher-stat-card">
                    <div class="voucher-stat-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="voucher-stat-info">
                        <div class="stat-val"><?php echo number_format($active_vouchers_count); ?></div>
                        <div class="stat-lbl">Active Promotions</div>
                    </div>
                </div>
                <div class="voucher-stat-card">
                    <div class="voucher-stat-icon blue"><i class="fas fa-receipt"></i></div>
                    <div class="voucher-stat-info">
                        <div class="stat-val"><?php echo number_format($total_redemptions); ?></div>
                        <div class="stat-lbl">Total Redemptions</div>
                    </div>
                </div>
                <div class="voucher-stat-card">
                    <div class="voucher-stat-icon amber"><i class="fas fa-store"></i></div>
                    <div class="voucher-stat-info">
                        <div class="stat-val"><?php echo $is_full_admin ? number_format($platform_vouchers_count) . ' Platform' : 'Store Exclusive'; ?></div>
                        <div class="stat-lbl"><?php echo $is_full_admin ? 'Platform-Wide Vouchers' : 'Store Specific Vouchers'; ?></div>
                    </div>
                </div>
            </div>

            <!-- 2. Interactive Voucher Builder & Live Preview -->
            <div class="row g-4 mb-4">
                <!-- Left Form Column -->
                <div class="col-xl-8 col-lg-7">
                    <div class="voucher-builder-card" id="voucherBuilderCard">
                        <div class="voucher-builder-head">
                            <div class="title-group">
                                <h2><i class="fas fa-tags"></i> Create Discount Voucher</h2>
                                <p>Configure promotional discount codes, validity windows, and checkout rules.</p>
                            </div>
                            <button type="button" class="btn-builder-secondary btn-sm" id="toggleCollapseBtn" onclick="toggleBuilderCollapse()">
                                <i class="fas fa-chevron-up" id="toggleCollapseIcon"></i> <span id="toggleCollapseText">Collapse Form</span>
                            </button>
                        </div>
                        
                        <div id="builderFormWrapper">
                            <form method="POST" id="createVoucherForm" class="voucher-builder-body">
                                <input type="hidden" name="create_voucher" value="1">
                                <input type="hidden" name="discount_type" id="inputDiscountType" value="percent">

                                <!-- Step 1: Promotion Details -->
                                <div class="form-step-group">
                                    <div class="form-step-header">
                                        <span class="form-step-title"><i class="fas fa-id-card"></i> 1. Promotion Identity &amp; Scope</span>
                                        <span class="step-num-pill">Required</span>
                                    </div>

                                    <?php if ($is_full_admin): ?>
                                    <div class="mb-3">
                                        <label class="compact-label" for="inputSellerId">
                                            <span>Voucher Scope / Target Store</span>
                                            <span class="req">*</span>
                                        </label>
                                        <div class="compact-input-group">
                                            <span class="input-addon"><i class="fas fa-store"></i></span>
                                            <select class="form-select" name="seller_id" id="inputSellerId" required onchange="updateLivePreview()">
                                                <option value="0" data-store="All Stores">Platform-Wide (Applicable to All Stores)</option>
                                                <?php foreach ($admin_store_options as $store): ?>
                                                    <option value="<?php echo (int)$store['id']; ?>" data-store="<?php echo htmlspecialchars($store['store_name']); ?>">
                                                        Store: <?php echo htmlspecialchars($store['store_name']); ?> (ID: <?php echo (int)$store['id']; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="compact-label" for="inputVoucherCode">
                                                <span>Voucher Code</span>
                                                <span class="req">*</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-barcode"></i></span>
                                                <input type="text" class="form-control text-uppercase font-monospace" name="code" id="inputVoucherCode" maxlength="60" placeholder="e.g. SUMMER20" required autocomplete="off" oninput="updateLivePreview()">
                                                <button type="button" class="btn-inline-action" onclick="generateVoucherCode()" title="Auto-generate promo code">
                                                    <i class="fas fa-wand-magic-sparkles"></i> Generate
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="compact-label" for="inputVoucherName">
                                                <span>Campaign / Display Name</span>
                                                <span class="req">*</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-tag"></i></span>
                                                <input type="text" class="form-control" name="name" id="inputVoucherName" maxlength="120" placeholder="e.g. Summer Promo 20% Off" required oninput="updateLivePreview()">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="compact-label" for="inputVoucherDesc">
                                                <span>Terms / Conditions Description <span class="text-muted fw-normal">(Optional)</span></span>
                                            </label>
                                            <textarea class="compact-textarea" name="description" id="inputVoucherDesc" rows="2" placeholder="Brief note or conditions for this discount voucher..." oninput="updateLivePreview()"></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 2: Discount Mechanism -->
                                <div class="form-step-group">
                                    <div class="form-step-header">
                                        <span class="form-step-title"><i class="fas fa-percent"></i> 2. Discount Rules &amp; Calculation</span>
                                        <span class="step-num-pill">Pricing</span>
                                    </div>

                                    <label class="compact-label mb-2">Discount Calculation Type</label>
                                    <div class="discount-type-segmented mb-3">
                                        <div class="discount-type-option active" id="optPercent" onclick="setDiscountType('percent')">
                                            <i class="fas fa-percent"></i>
                                            <span>Percentage Discount (%)</span>
                                        </div>
                                        <div class="discount-type-option" id="optFixed" onclick="setDiscountType('fixed')">
                                            <i class="fas fa-peso-sign"></i>
                                            <span>Fixed Amount (₱ PHP)</span>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="compact-label" for="inputDiscountValue">
                                                <span id="discountValueLabel">Discount Value (%)</span>
                                                <span class="req">*</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon" id="discountValueAddon">%</span>
                                                <input type="number" class="form-control" name="discount_value" id="inputDiscountValue" min="0.01" step="0.01" placeholder="e.g. 20" required oninput="updateLivePreview()">
                                            </div>
                                            <!-- Quick Preset Chips -->
                                            <div class="quick-preset-chips" id="quickDiscountChips">
                                                <!-- Dynamic via JS -->
                                            </div>
                                        </div>
                                        <div class="col-md-4" id="maxDiscountCol">
                                            <label class="compact-label" for="inputMaxDiscount">
                                                <span>Max Discount Cap (₱)</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon">₱</span>
                                                <input type="number" class="form-control" name="max_discount_amount" id="inputMaxDiscount" min="0" step="0.01" value="0" placeholder="0 = Unlimited" oninput="updateLivePreview()">
                                            </div>
                                            <small class="text-muted mt-1 d-block" style="font-size: 11px;">0 for no upper limit</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="compact-label" for="inputMinOrder">
                                                <span>Minimum Order Spend (₱)</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon">₱</span>
                                                <input type="number" class="form-control" name="min_order_amount" id="inputMinOrder" min="0" step="0.01" value="0" placeholder="0 = None" oninput="updateLivePreview()">
                                            </div>
                                            <small class="text-muted mt-1 d-block" style="font-size: 11px;">Minimum cart subtotal</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 3: Usage Limits & Expiration -->
                                <div class="form-step-group">
                                    <div class="form-step-header">
                                        <span class="form-step-title"><i class="fas fa-clock"></i> 3. Usage Limits &amp; Validity Period</span>
                                        <span class="step-num-pill">Schedule</span>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6 col-sm-6">
                                            <label class="compact-label" for="inputUsageLimit">
                                                <span>Total Redemptions Limit</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-users"></i></span>
                                                <input type="number" class="form-control" name="usage_limit" id="inputUsageLimit" min="0" step="1" value="0" placeholder="0 = Unlimited" oninput="updateLivePreview()">
                                            </div>
                                            <small class="text-muted mt-1 d-block" style="font-size: 11px;">Total times claimable across customers (0 for unlimited)</small>
                                        </div>
                                        <div class="col-md-6 col-sm-6">
                                            <label class="compact-label" for="inputPerUserLimit">
                                                <span>Per Customer Limit</span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-user-check"></i></span>
                                                <input type="number" class="form-control" name="per_user_limit" id="inputPerUserLimit" min="1" step="1" value="1" oninput="updateLivePreview()">
                                            </div>
                                            <small class="text-muted mt-1 d-block" style="font-size: 11px;">Maximum redemptions allowed per customer</small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="compact-label" for="inputStartAt">
                                                <span>Valid From <span class="text-muted fw-normal">(Optional)</span></span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-calendar-plus"></i></span>
                                                <input type="datetime-local" class="form-control" name="start_at" id="inputStartAt" oninput="updateLivePreview()">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="compact-label" for="inputEndAt">
                                                <span>Valid Until <span class="text-muted fw-normal">(Optional)</span></span>
                                            </label>
                                            <div class="compact-input-group">
                                                <span class="input-addon"><i class="fas fa-calendar-minus"></i></span>
                                                <input type="datetime-local" class="form-control" name="end_at" id="inputEndAt" oninput="updateLivePreview()">
                                            </div>
                                            <div class="quick-preset-chips mt-1">
                                                <button type="button" class="preset-chip" onclick="setExpiryDays(7)">+7 Days</button>
                                                <button type="button" class="preset-chip" onclick="setExpiryDays(14)">+14 Days</button>
                                                <button type="button" class="preset-chip" onclick="setExpiryDays(30)">+30 Days</button>
                                                <button type="button" class="preset-chip" onclick="clearExpiry()">No Expiry</button>
                                            </div>
                                        </div>
                                        <div class="col-12 mt-3 pt-2 border-top">
                                            <div class="form-check form-switch form-check-modern">
                                                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" checked onchange="updateLivePreview()">
                                                <label class="form-check-label fw-semibold text-dark ms-2" for="is_active">
                                                    Publish &amp; activate voucher immediately upon creation
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="builder-actions-row">
                                    <button type="reset" class="btn-builder-secondary" onclick="resetFormToDefaults()">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                    <button type="submit" class="btn-builder-primary">
                                        <i class="fas fa-save"></i> Save &amp; Publish Voucher
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Live Interactive Voucher Preview -->
                <div class="col-xl-4 col-lg-5">
                    <div class="live-preview-card">
                        <div class="live-preview-header">
                            <span class="live-preview-title"><i class="fas fa-eye text-danger"></i> Customer Live Preview</span>
                            <span class="badge text-bg-light border" id="previewScopeBadge">Platform-Wide</span>
                        </div>

                        <!-- Ticket Box -->
                        <div class="ticket-preview-box">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="ticket-badge-pill" id="previewStatusPill">
                                    <i class="fas fa-circle-check me-1"></i> Active Promo
                                </span>
                                <small class="text-muted fw-semibold" id="previewStoreName">All Stores</small>
                            </div>
                            
                            <div class="ticket-discount-amount" id="previewDiscountText">20% OFF</div>
                            <div class="ticket-campaign-name" id="previewCampaignName">Summer Promo 20% Off</div>

                            <div class="ticket-code-display">
                                <span id="previewCodeText">SUMMER20</span>
                                <button type="button" class="copy-code-btn" title="Copy Promo Code" onclick="copyPreviewCode()">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>

                            <ul class="ticket-conditions-list">
                                <li><i class="fas fa-shopping-bag"></i> <span id="previewMinSpend">No minimum spend required</span></li>
                                <li id="previewMaxCapItem"><i class="fas fa-shield-alt"></i> <span id="previewMaxCap">No maximum discount cap</span></li>
                                <li><i class="fas fa-calendar-alt"></i> <span id="previewValidity">Valid anytime (No expiration)</span></li>
                                <li><i class="fas fa-user-check"></i> <span id="previewPerUser">1 use per customer</span></li>
                            </ul>
                        </div>

                        <!-- Pro Guidelines -->
                        <div class="mt-4 p-3 bg-light rounded-3 border" style="font-size: 12px;">
                            <div class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                                <i class="fas fa-lightbulb text-warning"></i> Best Practices
                            </div>
                            <div class="text-muted d-grid gap-2">
                                <div><i class="fas fa-check text-success me-1"></i> <strong>Percentage:</strong> Excellent for driving higher basket size (e.g. 15% off orders over ₱800).</div>
                                <div><i class="fas fa-check text-success me-1"></i> <strong>Fixed Amount:</strong> Strongest for acquiring new customers (e.g. ₱50 off first purchase).</div>
                                <div><i class="fas fa-check text-success me-1"></i> <strong>Expiry Dates:</strong> Setting a 7–14 day expiration increases conversion urgency.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Manage Existing Vouchers Table -->
            <div class="vouchers-table-card">
                <div class="voucher-builder-head">
                    <div class="title-group">
                        <h2><i class="fas fa-list-check"></i> Manage Vouchers &amp; Discounts</h2>
                        <p>View existing campaigns, monitor usage, or toggle active status.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="max-width: 260px;">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="voucherTableSearch" class="form-control border-start-0" placeholder="Filter voucher codes...">
                        </div>
                    </div>
                </div>

                <div class="table-responsive p-0">
                    <table class="table table-hover align-middle mb-0" id="vouchersTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Voucher Code &amp; Name</th>
                                <th>Scope</th>
                                <th>Discount</th>
                                <th>Min. Spend</th>
                                <th>Usage / Limit</th>
                                <th>Validity Window</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($vouchers)): foreach ($vouchers as $v): ?>
                            <?php
                                $store_badge = ((int)$v['seller_id'] === 0)
                                    ? '<span class="badge text-bg-primary" style="font-size:0.7rem; font-weight:600;">Platform-Wide</span>'
                                    : ('<span class="badge text-bg-secondary" style="font-size:0.7rem; font-weight:600;">' . htmlspecialchars((string)($v['store_name'] ?? 'Store')) . '</span>');
                                
                                $is_percent = strtolower((string)$v['discount_type']) === 'percent';
                                $discount_val_formatted = rtrim(rtrim(number_format((float)$v['discount_value'], 2), '0'), '.');
                                $discount = $is_percent ? ($discount_val_formatted . '%') : ('₱' . number_format((float)$v['discount_value'], 2));
                                
                                if ((float)$v['max_discount_amount'] > 0) { 
                                    $discount .= ' <small class="text-muted d-block" style="font-size:0.75rem;">(Cap ₱' . number_format((float)$v['max_discount_amount'], 2) . ')</small>'; 
                                }
                                
                                $usage_limit = (int)$v['usage_limit'];
                                $usage = $usage_limit > 0 ? ((int)$v['usage_count'] . ' / ' . $usage_limit) : ((int)$v['usage_count'] . ' used');
                                
                                $validity = '<span class="text-muted" style="font-size:0.8rem;">No expiration</span>';
                                if (!empty($v['start_at']) || !empty($v['end_at'])) {
                                    $start_txt = !empty($v['start_at']) ? date('M j, Y g:i A', strtotime((string)$v['start_at'])) : 'Anytime';
                                    $end_txt = !empty($v['end_at']) ? date('M j, Y g:i A', strtotime((string)$v['end_at'])) : 'No expiry';
                                    $validity = '<div style="font-size:0.8rem;"><strong>' . $start_txt . '</strong><br><span class="text-muted">to</span> ' . $end_txt . '</div>';
                                }
                                
                                $active = (int)$v['is_active'] === 1;
                                $form_id = 'toggleVoucherForm' . (int)$v['id'];
                                $search_haystack = strtolower($v['code'] . ' ' . $v['name'] . ' ' . ($v['store_name'] ?? ''));
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($search_haystack); ?>">
                                <td class="ps-4">
                                    <div class="code-pill">
                                        <span><?php echo htmlspecialchars((string)$v['code']); ?></span>
                                        <button type="button" class="copy-code-btn" data-code="<?php echo htmlspecialchars((string)$v['code']); ?>" onclick="copyCode(this)" title="Copy Code">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <div class="fw-semibold text-dark mt-1" style="font-size: 13px;">
                                        <?php echo htmlspecialchars((string)$v['name']); ?>
                                    </div>
                                    <?php if (!empty($v['description'])): ?>
                                        <small class="text-muted d-block" style="font-size: 11.5px;"><?php echo htmlspecialchars((string)$v['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $store_badge; ?></td>
                                <td><span class="fw-bold text-dark"><?php echo $discount; ?></span></td>
                                <td>
                                    <?php if ((float)$v['min_order_amount'] > 0): ?>
                                        <span>₱<?php echo number_format((float)$v['min_order_amount'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-light border" style="font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($usage); ?>
                                    </span>
                                </td>
                                <td><?php echo $validity; ?></td>
                                <td>
                                    <?php if ($active): ?>
                                        <span class="status-badge-active"><i class="fas fa-circle-check me-1"></i> Active</span>
                                    <?php else: ?>
                                        <span class="status-badge-inactive"><i class="fas fa-circle-xmark me-1"></i> Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" id="<?php echo htmlspecialchars($form_id); ?>" class="d-inline">
                                        <input type="hidden" name="toggle_voucher" value="1">
                                        <input type="hidden" name="voucher_id" value="<?php echo (int)$v['id']; ?>">
                                        <button type="button" class="btn btn-sm <?php echo $active ? 'btn-outline-warning' : 'btn-outline-success'; ?>" data-form-id="<?php echo htmlspecialchars($form_id); ?>" data-code="<?php echo htmlspecialchars((string)$v['code']); ?>" data-is-active="<?php echo $active ? '1' : '0'; ?>" onclick="confirmVoucherToggle(this)" style="font-size: 12px; font-weight: 600; padding: 4px 10px;">
                                            <i class="fas <?php echo $active ? 'fa-pause' : 'fa-play'; ?> me-1"></i>
                                            <?php echo $active ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" id="del_<?php echo htmlspecialchars($form_id); ?>" class="d-inline ms-1">
                                        <input type="hidden" name="delete_voucher" value="1">
                                        <input type="hidden" name="voucher_id" value="<?php echo (int)$v['id']; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-form-id="del_<?php echo htmlspecialchars($form_id); ?>" data-code="<?php echo htmlspecialchars((string)$v['code']); ?>" onclick="confirmVoucherDelete(this)" style="font-size: 12px; font-weight: 600; padding: 4px 10px;">
                                            <i class="fas fa-trash-alt me-1"></i>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-ticket-alt fa-3x mb-3 text-secondary opacity-50"></i>
                                    <p class="mb-0 fw-semibold">No promotional vouchers created yet.</p>
                                    <small>Use the form above to launch your first promotional discount!</small>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const flashSuccess = <?php echo json_encode($flash_success, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const flashError = <?php echo json_encode($flash_error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
if (flashSuccess) {
    Swal.fire({ icon: 'success', title: 'Success', text: flashSuccess, timer: 2200, showConfirmButton: false });
} else if (flashError) {
    Swal.fire({ icon: 'error', title: 'Action failed', text: flashError });
}

// Discount Presets configuration
const percentPresets = [10, 15, 20, 25, 50];
const fixedPresets = [50, 100, 150, 200, 500];

function renderDiscountChips(type) {
    const container = document.getElementById('quickDiscountChips');
    if (!container) return;
    container.innerHTML = '';
    const presets = type === 'percent' ? percentPresets : fixedPresets;
    presets.forEach(val => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preset-chip';
        btn.textContent = type === 'percent' ? `${val}%` : `₱${val}`;
        btn.onclick = () => {
            document.getElementById('inputDiscountValue').value = val;
            updateLivePreview();
        };
        container.appendChild(btn);
    });
}

function setDiscountType(type) {
    const inputType = document.getElementById('inputDiscountType');
    const optPercent = document.getElementById('optPercent');
    const optFixed = document.getElementById('optFixed');
    const addon = document.getElementById('discountValueAddon');
    const label = document.getElementById('discountValueLabel');
    const maxDiscountCol = document.getElementById('maxDiscountCol');
    const maxDiscountInput = document.getElementById('inputMaxDiscount');
    const previewMaxCapItem = document.getElementById('previewMaxCapItem');

    if (type === 'percent') {
        inputType.value = 'percent';
        optPercent.classList.add('active');
        optFixed.classList.remove('active');
        addon.textContent = '%';
        label.textContent = 'Discount Value (%)';
        maxDiscountCol.style.opacity = '1';
        maxDiscountCol.style.pointerEvents = 'auto';
        maxDiscountInput.removeAttribute('disabled');
        if (previewMaxCapItem) previewMaxCapItem.style.display = 'flex';
    } else {
        inputType.value = 'fixed';
        optPercent.classList.remove('active');
        optFixed.classList.add('active');
        addon.textContent = '₱';
        label.textContent = 'Discount Amount (₱)';
        maxDiscountCol.style.opacity = '0.4';
        maxDiscountCol.style.pointerEvents = 'none';
        maxDiscountInput.setAttribute('disabled', 'disabled');
        if (previewMaxCapItem) previewMaxCapItem.style.display = 'none';
    }
    renderDiscountChips(type);
    updateLivePreview();
}

function generateVoucherCode() {
    const prefixes = ['LECHON', 'SUMMER', 'CRISPY', 'FEAST', 'TASTY', 'PROMO', 'PARTY', 'SPECIAL'];
    const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
    const num = Math.floor(10 + Math.random() * 90);
    const code = prefix + num;
    const codeInput = document.getElementById('inputVoucherCode');
    codeInput.value = code;
    updateLivePreview();
}

function setExpiryDays(days) {
    const now = new Date();
    now.setDate(now.getDate() + days);
    // Format to YYYY-MM-DDTHH:MM
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const date = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('inputEndAt').value = `${year}-${month}-${date}T${hours}:${minutes}`;
    updateLivePreview();
}

function clearExpiry() {
    document.getElementById('inputEndAt').value = '';
    updateLivePreview();
}

function updateLivePreview() {
    const codeVal = (document.getElementById('inputVoucherCode').value || 'SUMMER20').trim().toUpperCase();
    const nameVal = (document.getElementById('inputVoucherName').value || 'Summer Promo 20% Off').trim();
    const typeVal = document.getElementById('inputDiscountType').value;
    const numVal = parseFloat(document.getElementById('inputDiscountValue').value) || 20;
    const minVal = parseFloat(document.getElementById('inputMinOrder').value) || 0;
    const maxVal = parseFloat(document.getElementById('inputMaxDiscount').value) || 0;
    const perUser = parseInt(document.getElementById('inputPerUserLimit').value, 10) || 1;
    const isActive = document.getElementById('is_active').checked;
    const endAtVal = document.getElementById('inputEndAt').value;
    
    // Scope name
    const sellerSelect = document.getElementById('inputSellerId');
    let scopeName = 'Platform-Wide';
    let storeName = 'All Stores';
    if (sellerSelect) {
        const selectedOpt = sellerSelect.options[sellerSelect.selectedIndex];
        if (selectedOpt) {
            storeName = selectedOpt.getAttribute('data-store') || 'All Stores';
            scopeName = (selectedOpt.value === '0') ? 'Platform-Wide' : 'Store Specific';
        }
    }

    // Update Preview DOM
    document.getElementById('previewCodeText').textContent = codeVal;
    document.getElementById('previewCampaignName').textContent = nameVal;
    document.getElementById('previewStoreName').textContent = storeName;
    document.getElementById('previewScopeBadge').textContent = scopeName;

    // Discount Amount display
    if (typeVal === 'percent') {
        document.getElementById('previewDiscountText').textContent = `${numVal}% OFF`;
    } else {
        document.getElementById('previewDiscountText').textContent = `₱${numVal.toFixed(2)} OFF`;
    }

    // Min spend
    document.getElementById('previewMinSpend').textContent = (minVal > 0)
        ? `Min. spend ₱${minVal.toLocaleString('en-US', {minimumFractionDigits: 2})}`
        : 'No minimum spend required';

    // Max cap
    const maxCapEl = document.getElementById('previewMaxCap');
    if (maxCapEl) {
        maxCapEl.textContent = (maxVal > 0 && typeVal === 'percent')
            ? `Capped at ₱${maxVal.toLocaleString('en-US', {minimumFractionDigits: 2})}`
            : 'No maximum discount cap';
    }

    // Per user
    document.getElementById('previewPerUser').textContent = `${perUser} redemption${perUser > 1 ? 's' : ''} per customer`;

    // Status Pill
    const statusPill = document.getElementById('previewStatusPill');
    if (isActive) {
        statusPill.className = 'ticket-badge-pill';
        statusPill.innerHTML = '<i class="fas fa-circle-check me-1"></i> Active Promo';
        statusPill.style.background = '#ecfdf3';
        statusPill.style.color = '#027a48';
        statusPill.style.borderColor = '#abefc6';
    } else {
        statusPill.className = 'ticket-badge-pill';
        statusPill.innerHTML = '<i class="fas fa-circle-pause me-1"></i> Inactive (Draft)';
        statusPill.style.background = '#f2f4f7';
        statusPill.style.color = '#475467';
        statusPill.style.borderColor = '#eaecf0';
    }

    // Expiry
    const validityEl = document.getElementById('previewValidity');
    if (endAtVal) {
        const d = new Date(endAtVal);
        validityEl.textContent = 'Expires on ' + d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } else {
        validityEl.textContent = 'Valid anytime (No expiration)';
    }
}

function copyPreviewCode() {
    const code = document.getElementById('previewCodeText').textContent;
    navigator.clipboard.writeText(code).then(() => {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Copied: ' + code,
            showConfirmButton: false,
            timer: 1500
        });
    });
}

function copyCode(btn) {
    const code = btn.getAttribute('data-code');
    if (!code) return;
    navigator.clipboard.writeText(code).then(() => {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Copied ' + code,
            showConfirmButton: false,
            timer: 1500
        });
    });
}

function resetFormToDefaults() {
    setTimeout(() => {
        setDiscountType('percent');
        document.getElementById('inputDiscountValue').value = 20;
        document.getElementById('inputMaxDiscount').value = 0;
        document.getElementById('inputMinOrder').value = 0;
        document.getElementById('inputUsageLimit').value = 0;
        document.getElementById('inputPerUserLimit').value = 1;
        document.getElementById('is_active').checked = true;
        updateLivePreview();
    }, 50);
}

function toggleBuilderCollapse() {
    const wrapper = document.getElementById('builderFormWrapper');
    const icon = document.getElementById('toggleCollapseIcon');
    const text = document.getElementById('toggleCollapseText');
    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        icon.className = 'fas fa-chevron-up';
        text.textContent = 'Collapse Form';
        localStorage.setItem('admin_voucher_builder_open', '1');
    } else {
        wrapper.style.display = 'none';
        icon.className = 'fas fa-chevron-down';
        text.textContent = 'Open Form';
        localStorage.setItem('admin_voucher_builder_open', '0');
    }
}

// Table search filter
const searchInput = document.getElementById('voucherTableSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#vouchersTable tbody tr[data-search]');
        rows.forEach(row => {
            const haystack = row.getAttribute('data-search') || '';
            row.style.display = haystack.includes(query) ? '' : 'none';
        });
    });
}

// Form Submission Confirmation
const createVoucherForm = document.getElementById('createVoucherForm');
if (createVoucherForm) {
    createVoucherForm.addEventListener('submit', function (event) {
        if (this.dataset.confirmed === '1') return;
        event.preventDefault();
        const code = (document.getElementById('inputVoucherCode').value || '').trim();
        Swal.fire({
            title: 'Publish Voucher?',
            text: `Save and activate promotional voucher ${code}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, save voucher',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                this.dataset.confirmed = '1';
                this.submit();
            }
        });
    });
}

// Toggle Voucher Confirmation
function confirmVoucherToggle(button) {
    const formId = button.getAttribute('data-form-id');
    const isActive = button.getAttribute('data-is-active') === '1';
    const code = button.getAttribute('data-code') || 'voucher';
    const action = isActive ? 'deactivate' : 'activate';
    Swal.fire({
        title: (isActive ? 'Deactivate' : 'Activate') + ' voucher?',
        text: 'Do you want to ' + action + ' ' + code + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, ' + action + ' it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: isActive ? '#dc2626' : '#16a34a',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.getElementById(formId);
            if (form) form.submit();
        }
    });
}

// Delete Voucher Confirmation
function confirmVoucherDelete(button) {
    const formId = button.getAttribute('data-form-id');
    const code = button.getAttribute('data-code') || 'voucher';
    Swal.fire({
        title: 'Delete voucher?',
        text: 'Are you sure you want to permanently delete ' + code + '? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b3261e',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.getElementById(formId);
            if (form) form.submit();
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    setDiscountType('percent');
    renderDiscountChips('percent');
    updateLivePreview();

    // Check saved collapse state
    if (localStorage.getItem('admin_voucher_builder_open') === '0') {
        const wrapper = document.getElementById('builderFormWrapper');
        const icon = document.getElementById('toggleCollapseIcon');
        const text = document.getElementById('toggleCollapseText');
        if (wrapper) wrapper.style.display = 'none';
        if (icon) icon.className = 'fas fa-chevron-down';
        if (text) text.textContent = 'Open Form';
    }
});
</script>
</body>
</html>