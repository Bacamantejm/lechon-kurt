<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/store_availability_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;
$can_manage_store_availability = $is_partner_owner_admin
    || (function_exists('hasPermission') && (hasPermission($conn, $current_user_id, 'orders.edit') || hasPermission($conn, $current_user_id, 'products.edit')));

if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: Store availability is only available to approved business partner shops.');
}
if (!$can_manage_store_availability) {
    denyAdminAccess('Access denied: Your assigned role does not include store availability access.');
}

sahEnsureStoreLocationAvailabilitySchema($conn);

$bootstrap_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM franchise_applications
     WHERE user_id = ? AND status = 'approved'
     ORDER BY reviewed_at DESC, id DESC
     LIMIT 1"
);
if ($bootstrap_stmt) {
    mysqli_stmt_bind_param($bootstrap_stmt, "i", $seller_scope_id);
    mysqli_stmt_execute($bootstrap_stmt);
    $bootstrap_result = mysqli_stmt_get_result($bootstrap_stmt);
    $approved_application = $bootstrap_result ? mysqli_fetch_assoc($bootstrap_result) : null;
    mysqli_stmt_close($bootstrap_stmt);
    if ($approved_application) {
        sahUpsertPartnerStoreLocation($conn, $approved_application);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: store_availability.php');
        exit;
    }

    $store_id = (int)($_POST['store_id'] ?? 0);
    $availability_mode = in_array($_POST['availability_mode'] ?? '', ['schedule', 'manual'], true) ? (string)$_POST['availability_mode'] : 'schedule';
    $manual_status = in_array($_POST['manual_status'] ?? '', ['open', 'away', 'closed'], true) ? (string)$_POST['manual_status'] : 'closed';
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $immediate_toggle = strtolower(trim((string)($_POST['immediate_toggle'] ?? '')));
    $opening_time = trim((string)($_POST['opening_time'] ?? '08:00'));
    $closing_time = trim((string)($_POST['closing_time'] ?? '20:00'));

    if (in_array($immediate_toggle, ['open', 'closed'], true)) {
        $availability_mode = 'manual';
        $manual_status = $immediate_toggle;
        $is_active = 1;
    }

    $owned_stores = sahFetchPartnerStoreLocations($conn, (int)$seller_scope_id);
    $owned_store_ids = array_map(static function ($row) {
        return (int)($row['store_id'] ?? 0);
    }, $owned_stores);

    if ($store_id <= 0 || !in_array($store_id, $owned_store_ids, true)) {
        $_SESSION['error'] = 'Selected store could not be found for this business partner.';
        header('Location: store_availability.php');
        exit;
    }

    $selected_store = null;
    foreach ($owned_stores as $owned_store) {
        if ((int)($owned_store['store_id'] ?? 0) === $store_id) {
            $selected_store = $owned_store;
            break;
        }
    }

    $fallback_opening_time = substr((string)($selected_store['opening_time'] ?? '08:00:00'), 0, 5);
    $fallback_closing_time = substr((string)($selected_store['closing_time'] ?? '20:00:00'), 0, 5);
    $fallback_operating_days = (string)($selected_store['operating_days'] ?? '1,2,3,4,5,6,7');

    $is_valid_hhmm = static function (string $value): bool {
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            return false;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $value, 2));
        return $hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59;
    };

    if (!$is_valid_hhmm($opening_time)) {
        $opening_time = $fallback_opening_time;
    }
    if (!$is_valid_hhmm($closing_time)) {
        $closing_time = $fallback_closing_time;
    }

    $posted_operating_days = $_POST['operating_days'] ?? null;
    if (is_array($posted_operating_days) && !empty($posted_operating_days)) {
        $operating_days = sahNormalizeOperatingDays($posted_operating_days);
    } else {
        if ($availability_mode === 'schedule') {
            $_SESSION['error'] = 'Please select at least one operating day for schedule mode.';
            header('Location: store_availability.php');
            exit;
        }
        $operating_days = sahNormalizeOperatingDays($fallback_operating_days);
    }

    $opening_hours = sahBuildOpeningHours($opening_time . ':00', $closing_time . ':00', $operating_days);

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE store_locations
         SET is_active = ?, availability_mode = ?, manual_status = ?, opening_time = ?, closing_time = ?,
             operating_days = ?, opening_hours = ?
         WHERE store_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        $_SESSION['error'] = 'Unable to prepare the store availability update.';
        header('Location: store_availability.php');
        exit;
    }

    $opening_time_sql = $opening_time . ':00';
    $closing_time_sql = $closing_time . ':00';
    mysqli_stmt_bind_param(
        $stmt,
        "issssssi",
        $is_active,
        $availability_mode,
        $manual_status,
        $opening_time_sql,
        $closing_time_sql,
        $operating_days,
        $opening_hours,
        $store_id
    );
    $ok = mysqli_stmt_execute($stmt);
    $error = trim((string)mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    if ($ok) {
        if ($immediate_toggle === 'open') {
            $_SESSION['success'] = 'Store opened immediately. Manual mode is now active.';
        } elseif ($immediate_toggle === 'closed') {
            $_SESSION['success'] = 'Store closed immediately. Manual mode is now active.';
        } else {
            $_SESSION['success'] = 'Store availability saved successfully. Customers will now see the updated badge in the directory.';
        }
    } else {
        $_SESSION['error'] = $error !== '' ? $error : 'Unable to save store availability right now.';
    }

    header('Location: store_availability.php');
    exit;
}

$stores = sahFetchPartnerStoreLocations($conn, (int)$seller_scope_id);
$partner_info = function_exists('getUserInfo') ? getUserInfo($conn, (int)$seller_scope_id) : null;
$shop_name = trim((string)($partner_info['business_name'] ?? $partner_info['full_name'] ?? 'Your shop'));
$day_labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Availability - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Store Availability Modern UI Styles */
        .store-avail-page-wrap {
            padding-bottom: 140px;
        }

        /* Hero Header */
        .store-hero-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .store-hero-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .store-avatar-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #fff1f0;
            color: #b3261e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            border: 1px solid #fee4e2;
        }

        .store-hero-info h2 {
            font-size: 17px;
            font-weight: 700;
            color: #101828;
            margin: 0;
        }

        .store-hero-info p {
            font-size: 13px;
            color: #667085;
            margin: 3px 0 0 0;
        }

        /* Store Config Card */
        .store-config-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .store-card-header {
            padding: 18px 24px;
            border-bottom: 1px solid #eaecf0;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        .store-identity {
            min-width: 0;
        }

        .store-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .store-name-text {
            font-size: 16px;
            font-weight: 700;
            color: #101828;
        }

        .store-address-text {
            font-size: 12.5px;
            color: #667085;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .store-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .store-status-pill.open {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .store-status-pill.away {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
        }

        .store-status-pill.closed {
            background: #fee4e2;
            color: #b3261e;
            border: 1px solid #fecdca;
        }

        .store-instant-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-power-switch {
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            padding: 7px 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            border: none;
        }

        .btn-power-switch.open {
            background: #027a48;
            color: #ffffff;
        }

        .btn-power-switch.open:hover {
            background: #05603a;
        }

        .btn-power-switch.close {
            background: #b3261e;
            color: #ffffff;
        }

        .btn-power-switch.close:hover {
            background: #981b15;
        }

        .btn-view-directory {
            background: #ffffff;
            color: #344054;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 7px 14px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-view-directory:hover {
            background: #f8f9fa;
            color: #101828;
        }

        .store-config-body {
            padding: 24px;
        }

        /* Fieldset Groups */
        .config-fieldset {
            background: #fbfcfd;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .config-fieldset-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f2f4f7;
        }

        .fieldset-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1d2939;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .fieldset-badge {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
        }

        /* Segmented Mode Selector */
        .segmented-mode-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            background: #f2f4f7;
            padding: 4px;
            border-radius: 10px;
        }

        .mode-segment-opt {
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            background: transparent;
            color: #475467;
            transition: all 0.15s ease;
            user-select: none;
        }

        .mode-segment-opt i {
            font-size: 18px;
        }

        .mode-segment-opt strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.2;
        }

        .mode-segment-opt small {
            display: block;
            font-size: 11px;
            color: #667085;
            margin-top: 2px;
        }

        .mode-segment-opt.active {
            background: #ffffff;
            color: #b3261e;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.1);
        }

        .mode-segment-opt.active small {
            color: #475467;
        }

        /* Manual Status Grid */
        .manual-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .manual-opt {
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 9px 12px;
            text-align: center;
            cursor: pointer;
            background: #ffffff;
            color: #344054;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            user-select: none;
        }

        .manual-opt.open.active {
            background: #ecfdf3;
            border-color: #abefc6;
            color: #027a48;
        }

        .manual-opt.away.active {
            background: #fffaeb;
            border-color: #fedf89;
            color: #b54708;
        }

        .manual-opt.closed.active {
            background: #fee4e2;
            border-color: #fecdca;
            color: #b3261e;
        }

        /* Operating Days Pills */
        .quick-days-presets {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-day-preset {
            background: #ffffff;
            border: 1px solid #d0d5dd;
            color: #475467;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.12s ease;
        }

        .btn-day-preset:hover {
            background: #fff1f0;
            border-color: #fee4e2;
            color: #b3261e;
        }

        .day-pills-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }

        .day-pill-label {
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 8px 4px;
            text-align: center;
            cursor: pointer;
            background: #ffffff;
            color: #475467;
            font-size: 12.5px;
            font-weight: 700;
            transition: all 0.15s ease;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .day-pill-label input {
            display: none;
        }

        .day-pill-label.active {
            background: #b3261e;
            border-color: #b3261e;
            color: #ffffff;
        }

        /* Schedule Summary Box */
        .schedule-summary-box {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 12.5px;
        }

        .summary-label {
            color: #667085;
            font-weight: 500;
        }

        .summary-value {
            color: #101828;
            font-weight: 700;
            font-size: 13px;
        }

        /* Customer Live Preview Box */
        .customer-preview-box {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            position: sticky;
            top: 90px;
        }

        .preview-box-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f2f4f7;
        }

        .preview-box-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #344054;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .store-preview-mockup {
            border: 1px solid #eaecf0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);
            background: #ffffff;
            margin-bottom: 16px;
        }

        .mockup-header-banner {
            background: #f8f9fa;
            border-bottom: 1px solid #eaecf0;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .mockup-store-avatar {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #fff1f0;
            color: #b3261e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .mockup-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
        }

        .mockup-status-pill.open {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .mockup-status-pill.away {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
        }

        .mockup-status-pill.closed {
            background: #fee4e2;
            color: #b3261e;
            border: 1px solid #fecdca;
        }

        .mockup-body {
            padding: 16px;
        }

        .mockup-store-name {
            font-size: 14px;
            font-weight: 700;
            color: #101828;
            margin: 0 0 4px 0;
        }

        .mockup-address {
            font-size: 11.5px;
            color: #667085;
            margin: 0 0 10px 0;
            line-height: 1.4;
        }

        .mockup-schedule-row {
            font-size: 11.5px;
            color: #344054;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }

        .mockup-note-box {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 11px;
            color: #475467;
            line-height: 1.4;
        }

        .preview-tips-box {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 11.5px;
        }

        .tips-title {
            font-weight: 700;
            color: #101828;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }

        .tips-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 6px;
            color: #667085;
        }

        .tips-list li {
            position: relative;
            padding-left: 14px;
        }

        .tips-list li::before {
            content: "•";
            position: absolute;
            left: 0;
            color: #b3261e;
            font-weight: bold;
        }

        /* Form Controls Compact */
        .compact-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 5px;
            display: block;
        }

        .compact-input-group {
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
            padding: 8px 12px;
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 38px;
        }

        .compact-input-group .form-control {
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            height: 38px;
            font-size: 13px;
            padding: 8px 12px;
            color: #101828;
            background: #ffffff;
        }

        .compact-input-group .form-control:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            outline: none;
        }

        .form-check-modern {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            padding-left: 0;
            margin: 0;
        }

        .form-check-modern .form-check-input {
            width: 38px;
            height: 20px;
            cursor: pointer;
            margin: 0;
            float: none;
        }

        .form-check-modern .form-check-input:checked {
            background-color: #b3261e;
            border-color: #b3261e;
        }

        .btn-builder-primary {
            background: #b3261e;
            color: #ffffff;
            border: 1px solid #b3261e;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn-builder-primary:hover {
            background: #981b15;
            color: #ffffff;
            border-color: #981b15;
        }

        /* Dark Mode Overrides */
        body.dark-mode .store-hero-card,
        body.dark-mode .store-config-card,
        body.dark-mode .store-card-header,
        body.dark-mode .customer-preview-box,
        body.dark-mode .store-preview-mockup {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .store-hero-info h2,
        body.dark-mode .store-name-text,
        body.dark-mode .fieldset-title,
        body.dark-mode .mockup-store-name,
        body.dark-mode .tips-title,
        body.dark-mode .compact-label,
        body.dark-mode .summary-value {
            color: #f8fafc !important;
        }

        body.dark-mode .config-fieldset,
        body.dark-mode .mockup-header-banner,
        body.dark-mode .mockup-note-box,
        body.dark-mode .preview-tips-box,
        body.dark-mode .schedule-summary-box {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        body.dark-mode .segmented-mode-selector {
            background: #334155 !important;
        }

        body.dark-mode .mode-segment-opt {
            color: #cbd5e1 !important;
        }

        body.dark-mode .mode-segment-opt.active {
            background: #1e293b !important;
            color: #f87171 !important;
        }

        body.dark-mode .manual-opt,
        body.dark-mode .day-pill-label,
        body.dark-mode .btn-view-directory,
        body.dark-mode .btn-day-preset {
            background: #1e293b !important;
            border-color: #475569 !important;
            color: #cbd5e1 !important;
        }

        body.dark-mode .compact-input-group .form-control {
            background: #1e293b !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .compact-input-group .input-addon {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #cbd5e1 !important;
        }

        @media (max-width: 768px) {
            .day-pills-row {
                grid-template-columns: repeat(4, 1fr);
            }
            .segmented-mode-selector {
                grid-template-columns: 1fr;
            }
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
                <h1>Store Availability</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-main store-avail-page-wrap">
            <!-- Flash Message Alerts -->
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Top Hero Card -->
            <div class="store-hero-card">
                <div class="store-hero-info">
                    <div class="store-avatar-icon"><i class="fas fa-store"></i></div>
                    <div>
                        <h2>Store Availability &amp; Operating Hours</h2>
                        <p>Configure automated business hours, immediate open/closed overrides, and customer directory presence for <strong><?php echo htmlspecialchars($shop_name); ?></strong>.</p>
                    </div>
                </div>
            </div>

            <!-- Stores Configuration Grid -->
            <div class="stores-config-grid">
                <?php foreach ($stores as $store): ?>
                    <?php
                        $availability = $store['availability'] ?? sahResolveStoreAvailability($store);
                        $is_open_now = !empty($availability['is_open']);
                        $current_mode = $store['availability_mode'] ?? 'schedule';
                        $current_manual = $store['manual_status'] ?? 'closed';
                        $selected_days = array_map('intval', array_filter(explode(',', (string)($store['operating_days'] ?? '1,2,3,4,5,6,7'))));
                        $store_id = (int)($store['store_id'] ?? 0);
                        $opening_time_raw = substr((string)($store['opening_time'] ?? '08:00:00'), 0, 5);
                        $closing_time_raw = substr((string)($store['closing_time'] ?? '20:00:00'), 0, 5);
                    ?>
                    <div class="store-config-card" id="storeCard<?php echo $store_id; ?>">
                        <!-- Store Top Header -->
                        <div class="store-card-header">
                            <div class="store-identity">
                                <div class="store-title-row">
                                    <span class="store-name-text"><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Store')); ?></span>
                                    <?php if ($is_open_now): ?>
                                        <span class="store-status-pill open" id="topPill<?php echo $store_id; ?>"><i class="fas fa-circle-dot"></i> Open for Orders</span>
                                    <?php elseif ($current_manual === 'away'): ?>
                                        <span class="store-status-pill away" id="topPill<?php echo $store_id; ?>"><i class="fas fa-circle-pause"></i> Away / Paused</span>
                                    <?php else: ?>
                                        <span class="store-status-pill closed" id="topPill<?php echo $store_id; ?>"><i class="fas fa-circle-xmark"></i> Closed</span>
                                    <?php endif; ?>
                                </div>
                                <div class="store-address-text">
                                    <i class="fas fa-location-dot text-danger me-1"></i>
                                    <?php echo htmlspecialchars((string)($store['address'] ?? 'Store address not set')); ?>
                                    <?php if (!empty($store['city'])): ?>
                                        • <?php echo htmlspecialchars((string)$store['city']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Instant Power Switch Buttons -->
                            <div class="store-instant-actions">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">
                                    <?php if ($is_open_now): ?>
                                        <button type="submit" name="immediate_toggle" value="closed" class="btn-power-switch close" title="Instantly close store to customer orders">
                                            <i class="fas fa-power-off"></i> Close Store Now
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="immediate_toggle" value="open" class="btn-power-switch open" title="Instantly open store to customer orders">
                                            <i class="fas fa-bolt"></i> Open Store Now
                                        </button>
                                    <?php endif; ?>
                                </form>
                                <a href="../locations.php?search=<?php echo urlencode((string)($store['store_name'] ?? '')); ?>" target="_blank" class="btn-view-directory" title="View store in customer directory">
                                    <i class="fas fa-arrow-up-right-from-square"></i> Directory
                                </a>
                            </div>
                        </div>

                        <!-- Form Configuration -->
                        <form method="post" class="store-config-body" id="storeConfigForm<?php echo $store_id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">
                            <input type="hidden" name="availability_mode" id="availMode<?php echo $store_id; ?>" value="<?php echo htmlspecialchars($current_mode); ?>">
                            <input type="hidden" name="manual_status" id="manualStatus<?php echo $store_id; ?>" value="<?php echo htmlspecialchars($current_manual); ?>">

                            <div class="row g-4">
                                <!-- Left Column: Form Controls -->
                                <div class="col-lg-7 col-xl-8">
                                    <!-- Block 1: Operating Mode & Override -->
                                    <div class="config-fieldset">
                                        <div class="config-fieldset-head">
                                            <span class="fieldset-title"><i class="fas fa-sliders text-danger"></i> 1. Operating Mode &amp; Override</span>
                                            <span class="fieldset-badge">Mode</span>
                                        </div>

                                        <!-- Segmented Mode Selector -->
                                        <div class="segmented-mode-selector mb-3">
                                            <div class="mode-segment-opt <?php echo $current_mode === 'schedule' ? 'active' : ''; ?>" id="optModeSchedule<?php echo $store_id; ?>" onclick="setStoreMode(<?php echo $store_id; ?>, 'schedule')">
                                                <i class="fas fa-calendar-check"></i>
                                                <div>
                                                    <strong>Automatic Schedule Mode</strong>
                                                    <small>Opens &amp; closes automatically on regular hours</small>
                                                </div>
                                            </div>
                                            <div class="mode-segment-opt <?php echo $current_mode === 'manual' ? 'active' : ''; ?>" id="optModeManual<?php echo $store_id; ?>" onclick="setStoreMode(<?php echo $store_id; ?>, 'manual')">
                                                <i class="fas fa-hand-pointer"></i>
                                                <div>
                                                    <strong>Manual Override Mode</strong>
                                                    <small>Keep store Open, Away, or Closed manually</small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Manual Status Buttons -->
                                        <div class="manual-status-selector mb-3" id="manualStatusRow<?php echo $store_id; ?>" style="<?php echo $current_mode === 'manual' ? '' : 'opacity: 0.45; pointer-events: none;'; ?>">
                                            <label class="compact-label mb-2">Manual Override State:</label>
                                            <div class="manual-status-grid">
                                                <div class="manual-opt open <?php echo $current_manual === 'open' ? 'active' : ''; ?>" id="manOptOpen<?php echo $store_id; ?>" onclick="setManualStatus(<?php echo $store_id; ?>, 'open')">
                                                    <i class="fas fa-circle-check"></i>
                                                    <span>Open for Orders</span>
                                                </div>
                                                <div class="manual-opt away <?php echo $current_manual === 'away' ? 'active' : ''; ?>" id="manOptAway<?php echo $store_id; ?>" onclick="setManualStatus(<?php echo $store_id; ?>, 'away')">
                                                    <i class="fas fa-coffee"></i>
                                                    <span>Away / Paused</span>
                                                </div>
                                                <div class="manual-opt closed <?php echo $current_manual === 'closed' ? 'active' : ''; ?>" id="manOptClosed<?php echo $store_id; ?>" onclick="setManualStatus(<?php echo $store_id; ?>, 'closed')">
                                                    <i class="fas fa-circle-xmark"></i>
                                                    <span>Store Closed</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Directory Visibility Switch -->
                                        <div class="pt-2 border-top">
                                            <div class="form-check form-switch form-check-modern">
                                                <input class="form-check-input" type="checkbox" role="switch" id="isActive<?php echo $store_id; ?>" name="is_active" value="1" <?php echo (int)($store['is_active'] ?? 1) === 1 ? 'checked' : ''; ?> onchange="updateStorePreview(<?php echo $store_id; ?>)">
                                                <label class="form-check-label ms-2 fw-semibold text-dark" for="isActive<?php echo $store_id; ?>">
                                                    Show this branch in customer marketplace directory &amp; search
                                                </label>
                                            </div>
                                            <small class="text-muted d-block mt-1 ps-4" style="font-size: 11.5px;">Leave enabled so customers can discover this shop and browse products.</small>
                                        </div>
                                    </div>

                                    <!-- Block 2: Hours & Schedule -->
                                    <div class="config-fieldset">
                                        <div class="config-fieldset-head">
                                            <span class="fieldset-title"><i class="fas fa-business-time text-danger"></i> 2. Operating Hours &amp; Schedule</span>
                                            <span class="fieldset-badge">Schedule</span>
                                        </div>

                                        <div class="row g-3 mb-3">
                                            <div class="col-sm-6">
                                                <label class="compact-label" for="openTime<?php echo $store_id; ?>">Daily Opening Time</label>
                                                <div class="compact-input-group">
                                                    <span class="input-addon"><i class="fas fa-sun text-warning"></i></span>
                                                    <input type="time" name="opening_time" id="openTime<?php echo $store_id; ?>" class="form-control" value="<?php echo htmlspecialchars($opening_time_raw); ?>" onchange="updateStorePreview(<?php echo $store_id; ?>)">
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="compact-label" for="closeTime<?php echo $store_id; ?>">Daily Closing Time</label>
                                                <div class="compact-input-group">
                                                    <span class="input-addon"><i class="fas fa-moon text-primary"></i></span>
                                                    <input type="time" name="closing_time" id="closeTime<?php echo $store_id; ?>" class="form-control" value="<?php echo htmlspecialchars($closing_time_raw); ?>" onchange="updateStorePreview(<?php echo $store_id; ?>)">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Operating Days -->
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                                <label class="compact-label mb-0">Operating Days</label>
                                                <div class="quick-days-presets">
                                                    <button type="button" class="btn-day-preset" onclick="setDayPreset(<?php echo $store_id; ?>, 'daily')">Daily (7 Days)</button>
                                                    <button type="button" class="btn-day-preset" onclick="setDayPreset(<?php echo $store_id; ?>, 'weekdays')">Weekdays (Mon-Fri)</button>
                                                    <button type="button" class="btn-day-preset" onclick="setDayPreset(<?php echo $store_id; ?>, 'weekends')">Weekends (Sat-Sun)</button>
                                                </div>
                                            </div>

                                            <div class="day-pills-row" id="dayPillsRow<?php echo $store_id; ?>">
                                                <?php foreach ($day_labels as $day_number => $day_label): ?>
                                                    <?php $is_day_selected = in_array($day_number, $selected_days, true); ?>
                                                    <label class="day-pill-label <?php echo $is_day_selected ? 'active' : ''; ?>" id="dayPill<?php echo $store_id; ?>_<?php echo $day_number; ?>">
                                                        <input type="checkbox" name="operating_days[]" value="<?php echo $day_number; ?>" <?php echo $is_day_selected ? 'checked' : ''; ?> onchange="toggleDayPill(<?php echo $store_id; ?>, <?php echo $day_number; ?>)">
                                                        <span><?php echo $day_label; ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <!-- Schedule Summary -->
                                        <div class="schedule-summary-box">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fas fa-clock text-primary"></i>
                                                <span class="summary-label">Public Directory Display:</span>
                                            </div>
                                            <strong class="summary-value" id="scheduleDisplay<?php echo $store_id; ?>">
                                                <?php echo htmlspecialchars((string)($store['opening_hours'] ?? 'Daily | 8:00 AM - 8:00 PM')); ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <!-- Save Actions -->
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn-builder-primary">
                                            <i class="fas fa-save me-1"></i> Save Availability Settings
                                        </button>
                                    </div>
                                </div>

                                <!-- Right Column: Customer Live Preview -->
                                <div class="col-lg-5 col-xl-4">
                                    <div class="customer-preview-box">
                                        <div class="preview-box-head">
                                            <span class="preview-box-title"><i class="fas fa-eye text-danger"></i> Customer Live Preview</span>
                                            <span class="badge text-bg-light border" id="previewBadgeMode<?php echo $store_id; ?>"><?php echo ucfirst($current_mode); ?> Mode</span>
                                        </div>

                                        <!-- Mockup Card -->
                                        <div class="store-preview-mockup">
                                            <div class="mockup-header-banner">
                                                <div class="mockup-store-avatar"><i class="fas fa-shop"></i></div>
                                                <span class="mockup-status-pill <?php echo $is_open_now ? 'open' : ($current_manual === 'away' ? 'away' : 'closed'); ?>" id="mockupStatusPill<?php echo $store_id; ?>">
                                                    <i class="fas fa-circle-dot me-1"></i> <span id="mockupStatusText<?php echo $store_id; ?>"><?php echo $is_open_now ? 'Open now' : ($current_manual === 'away' ? 'Away' : 'Closed now'); ?></span>
                                                </span>
                                            </div>
                                            <div class="mockup-body">
                                                <h4 class="mockup-store-name"><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Store')); ?></h4>
                                                <p class="mockup-address"><?php echo htmlspecialchars((string)($store['address'] ?? 'Store address not set')); ?></p>
                                                
                                                <div class="mockup-schedule-row">
                                                    <i class="fas fa-clock text-muted"></i>
                                                    <span id="mockupScheduleText<?php echo $store_id; ?>"><?php echo htmlspecialchars((string)($availability['schedule'] ?? 'Daily | 8:00 AM - 8:00 PM')); ?></span>
                                                </div>

                                                <div class="mockup-note-box" id="mockupNoteBox<?php echo $store_id; ?>">
                                                    <i class="fas fa-circle-info me-1 text-primary"></i>
                                                    <span id="mockupNoteText<?php echo $store_id; ?>"><?php echo htmlspecialchars((string)($availability['note'] ?? '')); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Pro Tips -->
                                        <div class="preview-tips-box">
                                            <div class="tips-title"><i class="fas fa-lightbulb text-warning me-1"></i> Operations Guide</div>
                                            <ul class="tips-list">
                                                <li><strong>Schedule Mode:</strong> Automatically marks store Open and Closed based on your local Philippine business hours.</li>
                                                <li><strong>Manual Override:</strong> Use this during inventory counts, restocks, or events to control ordering instantly.</li>
                                                <li><strong>Customer Experience:</strong> When Closed, customers can browse menu items and place advance scheduled pre-orders.</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function formatTime12h(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    let h = parseInt(parts[0], 10);
    const m = parts[1] || '00';
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    h = h ? h : 12;
    return `${h}:${m} ${ampm}`;
}

const dayNames = {1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun'};

function setStoreMode(storeId, mode) {
    document.getElementById('availMode' + storeId).value = mode;
    const optSchedule = document.getElementById('optModeSchedule' + storeId);
    const optManual = document.getElementById('optModeManual' + storeId);
    const manualRow = document.getElementById('manualStatusRow' + storeId);
    const badgeMode = document.getElementById('previewBadgeMode' + storeId);

    if (mode === 'schedule') {
        optSchedule.classList.add('active');
        optManual.classList.remove('active');
        manualRow.style.opacity = '0.45';
        manualRow.style.pointerEvents = 'none';
        badgeMode.textContent = 'Schedule Mode';
    } else {
        optSchedule.classList.remove('active');
        optManual.classList.add('active');
        manualRow.style.opacity = '1';
        manualRow.style.pointerEvents = 'auto';
        badgeMode.textContent = 'Manual Mode';
    }
    updateStorePreview(storeId);
}

function setManualStatus(storeId, status) {
    document.getElementById('manualStatus' + storeId).value = status;
    const opts = ['open', 'away', 'closed'];
    opts.forEach(opt => {
        const el = document.getElementById('manOpt' + opt.charAt(0).toUpperCase() + opt.slice(1) + storeId);
        if (el) {
            el.classList.toggle('active', opt === status);
        }
    });
    updateStorePreview(storeId);
}

function toggleDayPill(storeId, dayNum) {
    const pill = document.getElementById('dayPill' + storeId + '_' + dayNum);
    const input = pill.querySelector('input');
    pill.classList.toggle('active', input.checked);
    updateStorePreview(storeId);
}

function setDayPreset(storeId, preset) {
    const row = document.getElementById('dayPillsRow' + storeId);
    const checkboxes = row.querySelectorAll('input[type="checkbox"]');
    
    checkboxes.forEach(cb => {
        const day = parseInt(cb.value, 10);
        let check = false;
        if (preset === 'daily') {
            check = true;
        } else if (preset === 'weekdays') {
            check = (day >= 1 && day <= 5);
        } else if (preset === 'weekends') {
            check = (day === 6 || day === 7);
        }
        cb.checked = check;
        const pill = document.getElementById('dayPill' + storeId + '_' + day);
        if (pill) pill.classList.toggle('active', check);
    });
    updateStorePreview(storeId);
}

function updateStorePreview(storeId) {
    const mode = document.getElementById('availMode' + storeId).value;
    const manualStatus = document.getElementById('manualStatus' + storeId).value;
    const isActive = document.getElementById('isActive' + storeId).checked;
    const openTime = document.getElementById('openTime' + storeId).value || '08:00';
    const closeTime = document.getElementById('closeTime' + storeId).value || '20:00';

    // Collect days
    const row = document.getElementById('dayPillsRow' + storeId);
    const checkedDays = Array.from(row.querySelectorAll('input[type="checkbox"]:checked')).map(cb => parseInt(cb.value, 10)).sort();

    // Days summary
    let daysLabel = 'Daily';
    if (checkedDays.length === 7) {
        daysLabel = 'Daily';
    } else if (checkedDays.length === 5 && checkedDays.join(',') === '1,2,3,4,5') {
        daysLabel = 'Mon - Fri';
    } else if (checkedDays.length === 2 && checkedDays.join(',') === '6,7') {
        daysLabel = 'Sat - Sun';
    } else if (checkedDays.length > 0) {
        daysLabel = checkedDays.map(d => dayNames[d]).join(', ');
    } else {
        daysLabel = 'No operating days';
    }

    const scheduleFormatted = `${daysLabel} | ${formatTime12h(openTime)} - ${formatTime12h(closeTime)}`;
    document.getElementById('scheduleDisplay' + storeId).textContent = scheduleFormatted;
    document.getElementById('mockupScheduleText' + storeId).textContent = scheduleFormatted;

    // Resolve preview status
    let statusClass = 'closed';
    let statusLabel = 'Closed now';
    let noteText = 'Store is currently unavailable.';

    if (!isActive) {
        statusClass = 'closed';
        statusLabel = 'Hidden';
        noteText = 'Store is hidden from the customer marketplace directory.';
    } else if (mode === 'manual') {
        if (manualStatus === 'open') {
            statusClass = 'open';
            statusLabel = 'Open now';
            noteText = 'Store manually marked as open. Ready to accept orders.';
        } else if (manualStatus === 'away') {
            statusClass = 'away';
            statusLabel = 'Away / Busy';
            noteText = 'Store is temporarily away from live operations.';
        } else {
            statusClass = 'closed';
            statusLabel = 'Closed now';
            noteText = 'Store is manually marked as closed for now.';
        }
    } else {
        // Schedule mode check against current local time
        const now = new Date();
        const currentIsoDay = now.getDay() === 0 ? 7 : now.getDay();
        const currentMinutes = now.getHours() * 60 + now.getMinutes();

        const [oH, oM] = openTime.split(':').map(Number);
        const [cH, cM] = closeTime.split(':').map(Number);
        const openMin = oH * 60 + oM;
        const closeMin = cH * 60 + cM;

        const isDayAllowed = checkedDays.includes(currentIsoDay);
        const isTimeWithin = (closeMin > openMin)
            ? (currentMinutes >= openMin && currentMinutes <= closeMin)
            : (currentMinutes >= openMin || currentMinutes <= closeMin);

        if (isDayAllowed && isTimeWithin) {
            statusClass = 'open';
            statusLabel = 'Open now';
            noteText = 'Open now based on store business schedule.';
        } else {
            statusClass = 'closed';
            statusLabel = 'Closed now';
            noteText = isDayAllowed ? 'Closed right now based on store hours.' : 'Store is closed today (not a scheduled operating day).';
        }
    }

    // Update Mockup Pill
    const mockupPill = document.getElementById('mockupStatusPill' + storeId);
    mockupPill.className = 'mockup-status-pill ' + statusClass;
    document.getElementById('mockupStatusText' + storeId).textContent = statusLabel;
    document.getElementById('mockupNoteText' + storeId).textContent = noteText;
}

document.addEventListener('DOMContentLoaded', () => {
    // Initialize preview for all stores
    document.querySelectorAll('.store-config-card').forEach(card => {
        const idMatch = card.id.match(/\d+/);
        if (idMatch) {
            updateStorePreview(parseInt(idMatch[0], 10));
        }
    });
});
</script>
</body>
</html>