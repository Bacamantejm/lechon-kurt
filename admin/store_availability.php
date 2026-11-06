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

/**
 * Resolves the single canonical store belonging to this business partner,
 * repairing missing owner associations and cleaning duplicate records.
 */
function sahResolveSinglePartnerStore($conn, int $seller_scope_id, ?array $partner_info = null): ?array
{
    if (!($conn instanceof mysqli) || $seller_scope_id <= 0) {
        return null;
    }

    $email = trim((string)($partner_info['email'] ?? ''));
    $business_name = trim((string)($partner_info['business_name'] ?? ''));

    // 1. Direct owner match in store_locations
    $stores = [];
    $owner_res = @mysqli_query($conn, "SELECT * FROM store_locations WHERE owner_user_id = {$seller_scope_id} ORDER BY is_active DESC, store_id ASC");
    if ($owner_res) {
        while ($row = mysqli_fetch_assoc($owner_res)) {
            $stores[] = $row;
        }
    }

    // 2. Fallback search by email or business name if not yet linked
    if (empty($stores)) {
        $conditions = [];
        if ($email !== '') {
            $esc_email = mysqli_real_escape_string($conn, $email);
            $conditions[] = "email = '{$esc_email}'";
        }
        if ($business_name !== '') {
            $esc_bn = mysqli_real_escape_string($conn, $business_name);
            $conditions[] = "LOWER(TRIM(store_name)) = LOWER('{$esc_bn}')";
        }
        if (!empty($conditions)) {
            $cond_sql = implode(' OR ', $conditions);
            $alt_res = @mysqli_query($conn, "SELECT * FROM store_locations WHERE ({$cond_sql}) ORDER BY is_active DESC, store_id ASC");
            if ($alt_res) {
                while ($row = mysqli_fetch_assoc($alt_res)) {
                    $stores[] = $row;
                }
            }
        }
    }

    // 3. Deduplicate and ensure canonical store
    if (!empty($stores)) {
        $primary = $stores[0];
        $primary_id = (int)$primary['store_id'];

        if ((int)($primary['owner_user_id'] ?? 0) !== $seller_scope_id) {
            @mysqli_query($conn, "UPDATE store_locations SET owner_user_id = {$seller_scope_id} WHERE store_id = {$primary_id}");
            $primary['owner_user_id'] = $seller_scope_id;
        }

        // Clean duplicates: keep primary store and re-assign any dependent data
        if (count($stores) > 1) {
            for ($i = 1; $i < count($stores); $i++) {
                $dup_id = (int)$stores[$i]['store_id'];
                if ($dup_id !== $primary_id) {
                    if (sahTableExists($conn, 'orders')) {
                        if (sahColumnExists($conn, 'orders', 'store_id')) {
                            @mysqli_query($conn, "UPDATE orders SET store_id = {$primary_id} WHERE store_id = {$dup_id}");
                        }
                        if (sahColumnExists($conn, 'orders', 'pickup_location')) {
                            @mysqli_query($conn, "UPDATE orders SET pickup_location = {$primary_id} WHERE pickup_location = {$dup_id}");
                        }
                    }
                    if (sahTableExists($conn, 'riders') && sahColumnExists($conn, 'riders', 'store_id')) {
                        @mysqli_query($conn, "UPDATE riders SET store_id = {$primary_id} WHERE store_id = {$dup_id}");
                    }
                    if (sahTableExists($conn, 'employees') && sahColumnExists($conn, 'employees', 'store_id')) {
                        @mysqli_query($conn, "UPDATE employees SET store_id = {$primary_id} WHERE store_id = {$dup_id}");
                    }
                    @mysqli_query($conn, "DELETE FROM store_locations WHERE store_id = {$dup_id}");
                }
            }
        }

        if (function_exists('fixMojibakeText')) {
            $primary['store_name'] = fixMojibakeText((string)($primary['store_name'] ?? ''));
            $primary['address'] = fixMojibakeText((string)($primary['address'] ?? ''));
            $primary['city'] = fixMojibakeText((string)($primary['city'] ?? ''));
            $primary['province'] = fixMojibakeText((string)($primary['province'] ?? ''));
        }
        $primary['availability'] = sahResolveStoreAvailability($primary);
        return $primary;
    }

    // 4. Fallback: bootstrap from approved franchise application if available
    $bootstrap_stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM franchise_applications WHERE user_id = ? AND status = 'approved' ORDER BY reviewed_at DESC, id DESC LIMIT 1"
    );
    if ($bootstrap_stmt) {
        mysqli_stmt_bind_param($bootstrap_stmt, "i", $seller_scope_id);
        mysqli_stmt_execute($bootstrap_stmt);
        $bootstrap_res = mysqli_stmt_get_result($bootstrap_stmt);
        $approved_app = $bootstrap_res ? mysqli_fetch_assoc($bootstrap_res) : null;
        mysqli_stmt_close($bootstrap_stmt);
        if ($approved_app) {
            sahUpsertPartnerStoreLocation($conn, $approved_app);
            $q_new = @mysqli_query($conn, "SELECT * FROM store_locations WHERE owner_user_id = {$seller_scope_id} ORDER BY store_id DESC LIMIT 1");
            if ($q_new && ($new_row = mysqli_fetch_assoc($q_new))) {
                if (function_exists('fixMojibakeText')) {
                    $new_row['store_name'] = fixMojibakeText((string)($new_row['store_name'] ?? ''));
                    $new_row['address'] = fixMojibakeText((string)($new_row['address'] ?? ''));
                    $new_row['city'] = fixMojibakeText((string)($new_row['city'] ?? ''));
                    $new_row['province'] = fixMojibakeText((string)($new_row['province'] ?? ''));
                }
                $new_row['availability'] = sahResolveStoreAvailability($new_row);
                return $new_row;
            }
        }
    }

    // 5. Final fallback: create new store location for this partner
    $store_name = $business_name !== '' ? $business_name : trim((string)($partner_info['full_name'] ?? "Partner's Lechon"));
    $contact_phone = trim((string)($partner_info['phone'] ?? '09123456789'));
    $address = trim((string)($partner_info['address'] ?? 'City of Dasmariñas, Cavite'));
    $contact_email = $email !== '' ? $email : ('partner+' . $seller_scope_id . '@lechondelights.local');

    $esc_sname = mysqli_real_escape_string($conn, $store_name);
    $esc_addr = mysqli_real_escape_string($conn, $address);
    $esc_email = mysqli_real_escape_string($conn, $contact_email);
    $esc_phone = mysqli_real_escape_string($conn, $contact_phone);

    @mysqli_query(
        $conn,
        "INSERT INTO store_locations (owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active)
         VALUES ({$seller_scope_id}, '{$esc_sname}', '{$esc_addr}', 'City of Dasmariñas', 'Cavite', '{$esc_phone}', '{$esc_email}', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'open', 1)"
    );
    $new_id = (int)mysqli_insert_id($conn);
    $q_created = @mysqli_query($conn, "SELECT * FROM store_locations WHERE store_id = {$new_id} LIMIT 1");
    if ($q_created && ($row = mysqli_fetch_assoc($q_created))) {
        $row['availability'] = sahResolveStoreAvailability($row);
        return $row;
    }

    return null;
}

$partner_info = function_exists('getUserInfo') ? getUserInfo($conn, (int)$seller_scope_id) : null;

// Handle Store Availability Form Save & Toggles
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: store_availability.php');
        exit;
    }

    $selected_store = sahResolveSinglePartnerStore($conn, (int)$seller_scope_id, $partner_info);
    if (!$selected_store) {
        $_SESSION['error'] = 'Unable to resolve store location for this business partner.';
        header('Location: store_availability.php');
        exit;
    }

    $store_id = (int)$selected_store['store_id'];
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

    // Process operating days selected by shop owner (1 = Mon, 7 = Sun)
    $posted_days = $_POST['operating_days'] ?? null;
    $operating_days_arr = [];
    if (is_array($posted_days)) {
        foreach ($posted_days as $day_val) {
            $day_int = (int)$day_val;
            if ($day_int >= 1 && $day_int <= 7 && !in_array($day_int, $operating_days_arr, true)) {
                $operating_days_arr[] = $day_int;
            }
        }
    }
    sort($operating_days_arr);
    $operating_days = !empty($operating_days_arr) ? implode(',', $operating_days_arr) : '';

    $opening_hours = ($operating_days !== '')
        ? sahBuildOpeningHours($opening_time . ':00', $closing_time . ':00', $operating_days)
        : 'Closed on all days';

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE store_locations
         SET availability_mode = ?,
             manual_status = ?,
             opening_time = ?,
             closing_time = ?,
             operating_days = ?,
             opening_hours = ?,
             is_active = ?,
             updated_at = NOW()
         WHERE store_id = ?"
    );

    if (!$stmt) {
        $_SESSION['error'] = 'Database update preparation failed.';
        header('Location: store_availability.php');
        exit;
    }

    $opening_time_db = $opening_time . ':00';
    $closing_time_db = $closing_time . ':00';

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssii',
        $availability_mode,
        $manual_status,
        $opening_time_db,
        $closing_time_db,
        $operating_days,
        $opening_hours,
        $is_active,
        $store_id
    );

    $ok = mysqli_stmt_execute($stmt);
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    if ($ok) {
        if ($immediate_toggle === 'open') {
            $_SESSION['success'] = 'Store opened immediately. Manual mode is now active.';
        } elseif ($immediate_toggle === 'closed') {
            $_SESSION['success'] = 'Store closed immediately. Manual mode is now active.';
        } else {
            $_SESSION['success'] = 'Store availability and operating schedule saved successfully.';
        }
    } else {
        $_SESSION['error'] = $error !== '' ? $error : 'Unable to save store availability right now.';
    }

    header('Location: store_availability.php');
    exit;
}

// Resolve the single store for display
$store = sahResolveSinglePartnerStore($conn, (int)$seller_scope_id, $partner_info);
$shop_name = trim((string)($store['store_name'] ?? $partner_info['business_name'] ?? $partner_info['full_name'] ?? 'Your shop'));

$availability = $store['availability'] ?? ($store ? sahResolveStoreAvailability($store) : ['is_open' => false, 'schedule' => 'Daily | 8:00 AM - 8:00 PM', 'note' => '']);
$is_open_now = !empty($availability['is_open']);
$current_mode = $store['availability_mode'] ?? 'schedule';
$current_manual = $store['manual_status'] ?? 'closed';
$selected_days = ($store && !empty($store['operating_days']))
    ? array_map('intval', array_filter(explode(',', (string)$store['operating_days'])))
    : [1, 2, 3, 4, 5, 6, 7];
$store_id = (int)($store['store_id'] ?? 0);
$opening_time_raw = substr((string)($store['opening_time'] ?? '08:00:00'), 0, 5);
$closing_time_raw = substr((string)($store['closing_time'] ?? '20:00:00'), 0, 5);

$day_labels = [
    1 => ['short' => 'Mon', 'full' => 'Monday'],
    2 => ['short' => 'Tue', 'full' => 'Tuesday'],
    3 => ['short' => 'Wed', 'full' => 'Wednesday'],
    4 => ['short' => 'Thu', 'full' => 'Thursday'],
    5 => ['short' => 'Fri', 'full' => 'Friday'],
    6 => ['short' => 'Sat', 'full' => 'Saturday'],
    7 => ['short' => 'Sun', 'full' => 'Sunday']
];
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
            font-size: 17px;
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
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .store-status-pill.open {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .store-status-pill.closed {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
        }

        .store-status-pill.away {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
        }

        .store-instant-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-power-switch {
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
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
            border: 1px solid #d0d5dd;
            color: #344054;
            border-radius: 8px;
            padding: 8px 14px;
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
            border-color: #98a2b3;
        }

        .store-config-body {
            padding: 24px;
        }

        /* Fieldsets */
        .config-fieldset {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .config-fieldset-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-bottom: 1px solid #f2f4f7;
            padding-bottom: 10px;
        }

        .fieldset-title {
            font-size: 14px;
            font-weight: 700;
            color: #101828;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .fieldset-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            background: #f2f4f7;
            color: #475467;
        }

        /* Segmented Mode Selector */
        .segmented-mode-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            background: #f8f9fa;
            padding: 6px;
            border-radius: 10px;
            border: 1px solid #eaecf0;
        }

        .mode-segment-opt {
            padding: 12px 14px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.15s ease;
            color: #475467;
        }

        .mode-segment-opt i {
            font-size: 18px;
        }

        .mode-segment-opt strong {
            display: block;
            font-size: 13.5px;
            color: #101828;
        }

        .mode-segment-opt small {
            display: block;
            font-size: 11.5px;
            color: #667085;
            margin-top: 1px;
        }

        .mode-segment-opt.active {
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
            border: 1px solid #eaecf0;
            color: #b3261e;
        }

        .mode-segment-opt.active strong {
            color: #b3261e;
        }

        /* Manual Status Buttons */
        .manual-status-selector {
            transition: opacity 0.2s ease;
        }

        .manual-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .manual-opt {
            background: #ffffff;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 600;
            color: #475467;
            transition: all 0.15s ease;
        }

        .manual-opt:hover {
            border-color: #98a2b3;
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
            background: #fff1f0;
            border-color: #fee4e2;
            color: #b3261e;
        }

        /* Operating Days Interactive Cards */
        .quick-days-presets {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-day-preset {
            background: #ffffff;
            border: 1px solid #d0d5dd;
            color: #344054;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.12s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-day-preset:hover {
            background: #fff1f0;
            border-color: #fee4e2;
            color: #b3261e;
        }

        .day-pills-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        @media (max-width: 991px) {
            .day-pills-row {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 576px) {
            .day-pills-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .day-card-toggle {
            border-radius: 10px;
            padding: 12px 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            background: #ffffff;
            border: 1px solid #eaecf0;
        }

        .day-card-toggle:hover {
            transform: translateY(-1px);
        }

        .day-card-toggle.is-open {
            background: #ffffff;
            border: 2px solid #12b76a;
            box-shadow: 0 1px 4px rgba(18, 183, 106, 0.15);
        }

        .day-card-toggle.is-closed {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            opacity: 0.9;
        }

        .day-card-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #101828;
        }

        .day-card-toggle.is-closed .day-card-name {
            color: #667085;
        }

        .day-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .day-status-pill.pill-open {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .day-status-pill.pill-open .status-closed-text {
            display: none;
        }

        .day-status-pill.pill-closed {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
        }

        .day-status-pill.pill-closed .status-open-text {
            display: none;
        }

        /* Schedule Summary Box */
        .schedule-summary-box {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .summary-label {
            font-size: 12px;
            color: #475467;
            font-weight: 600;
        }

        .summary-value {
            font-size: 13px;
            color: #101828;
            font-weight: 700;
        }

        /* Compact Inputs */
        .compact-label {
            font-size: 12px;
            font-weight: 600;
            color: #344054;
            display: block;
            margin-bottom: 5px;
        }

        .compact-input-group {
            display: flex;
            align-items: center;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
        }

        .compact-input-group:focus-within {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
        }

        .compact-input-group .input-addon {
            padding: 8px 12px;
            background: #f8f9fa;
            border-right: 1px solid #eaecf0;
            font-size: 13px;
        }

        .compact-input-group .form-control {
            border: none;
            padding: 8px 12px;
            font-size: 13px;
            outline: none;
            box-shadow: none;
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
            padding: 10px 24px;
            font-size: 13.5px;
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

        /* Customer Live Preview */
        .customer-preview-box {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 20px;
        }

        .preview-box-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-bottom: 1px solid #f2f4f7;
            padding-bottom: 10px;
        }

        .preview-box-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #101828;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .store-preview-mockup {
            border: 1px solid #eaecf0;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.05);
            margin-bottom: 16px;
        }

        .mockup-header-banner {
            padding: 14px 16px;
            background: #f8f9fa;
            border-bottom: 1px solid #eaecf0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .mockup-store-avatar {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #fff1f0;
            color: #b3261e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            border: 1px solid #fee4e2;
        }

        .mockup-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
        }

        .mockup-status-pill.open {
            background: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .mockup-status-pill.closed {
            background: #fff1f0;
            color: #b3261e;
            border: 1px solid #fee4e2;
        }

        .mockup-status-pill.away {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
        }

        .mockup-body {
            padding: 16px;
        }

        .mockup-store-name {
            font-size: 15px;
            font-weight: 700;
            color: #101828;
            margin: 0 0 4px 0;
        }

        .mockup-address {
            font-size: 12px;
            color: #667085;
            margin: 0 0 12px 0;
            line-height: 1.4;
        }

        .mockup-schedule-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 10px;
        }

        .mockup-note-box {
            background: #eff8ff;
            border: 1px solid #b2ddff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 11.5px;
            color: #175cd3;
            line-height: 1.4;
        }

        .preview-tips-box {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            padding: 14px;
        }

        .tips-title {
            font-size: 12px;
            font-weight: 700;
            color: #101828;
            margin-bottom: 8px;
        }

        .tips-list {
            margin: 0;
            padding-left: 18px;
            font-size: 11.5px;
            color: #475467;
            line-height: 1.5;
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

            <!-- Single Canonical Store Configuration Card (No Loops!) -->
            <div class="store-config-card" id="storeCard<?php echo $store_id; ?>">
                <!-- Store Top Header -->
                <div class="store-card-header">
                    <div class="store-identity">
                        <div class="store-title-row">
                            <span class="store-name-text"><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Store')); ?></span>
                            <?php if ($is_open_now): ?>
                                <span class="store-status-pill open" id="topPill"><i class="fas fa-circle-dot"></i> Open for Orders</span>
                            <?php elseif ($current_manual === 'away'): ?>
                                <span class="store-status-pill away" id="topPill"><i class="fas fa-circle-pause"></i> Away / Paused</span>
                            <?php else: ?>
                                <span class="store-status-pill closed" id="topPill"><i class="fas fa-circle-xmark"></i> Closed</span>
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

                    <!-- Instant Power Switch Button -->
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

                <!-- Main Form Configuration -->
                <form method="post" class="store-config-body" id="storeConfigForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">
                    <input type="hidden" name="availability_mode" id="availMode" value="<?php echo htmlspecialchars($current_mode); ?>">
                    <input type="hidden" name="manual_status" id="manualStatus" value="<?php echo htmlspecialchars($current_manual); ?>">

                    <div class="row g-4">
                        <!-- Left Column: Settings -->
                        <div class="col-lg-7 col-xl-8">
                            <!-- Block 1: Operating Mode & Override -->
                            <div class="config-fieldset">
                                <div class="config-fieldset-head">
                                    <span class="fieldset-title"><i class="fas fa-sliders text-danger"></i> 1. Operating Mode &amp; Override</span>
                                    <span class="fieldset-badge">Mode</span>
                                </div>

                                <!-- Segmented Mode Selector -->
                                <div class="segmented-mode-selector mb-3">
                                    <div class="mode-segment-opt <?php echo $current_mode === 'schedule' ? 'active' : ''; ?>" id="optModeSchedule" onclick="setStoreMode('schedule')">
                                        <i class="fas fa-calendar-check"></i>
                                        <div>
                                            <strong>Automatic Schedule Mode</strong>
                                            <small>Opens &amp; closes automatically on regular hours</small>
                                        </div>
                                    </div>
                                    <div class="mode-segment-opt <?php echo $current_mode === 'manual' ? 'active' : ''; ?>" id="optModeManual" onclick="setStoreMode('manual')">
                                        <i class="fas fa-hand-pointer"></i>
                                        <div>
                                            <strong>Manual Override Mode</strong>
                                            <small>Keep store Open, Away, or Closed manually</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Manual Status Buttons -->
                                <div class="manual-status-selector mb-3" id="manualStatusRow" style="<?php echo $current_mode === 'manual' ? '' : 'opacity: 0.45; pointer-events: none;'; ?>">
                                    <label class="compact-label mb-2">Manual Override State:</label>
                                    <div class="manual-status-grid">
                                        <div class="manual-opt open <?php echo $current_manual === 'open' ? 'active' : ''; ?>" id="manOptOpen" onclick="setManualStatus('open')">
                                            <i class="fas fa-circle-check"></i>
                                            <span>Open for Orders</span>
                                        </div>
                                        <div class="manual-opt away <?php echo $current_manual === 'away' ? 'active' : ''; ?>" id="manOptAway" onclick="setManualStatus('away')">
                                            <i class="fas fa-coffee"></i>
                                            <span>Away / Paused</span>
                                        </div>
                                        <div class="manual-opt closed <?php echo $current_manual === 'closed' ? 'active' : ''; ?>" id="manOptClosed" onclick="setManualStatus('closed')">
                                            <i class="fas fa-circle-xmark"></i>
                                            <span>Store Closed</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Directory Visibility Switch -->
                                <div class="pt-2 border-top">
                                    <div class="form-check form-switch form-check-modern">
                                        <input class="form-check-input" type="checkbox" role="switch" id="isActive" name="is_active" value="1" <?php echo (int)($store['is_active'] ?? 1) === 1 ? 'checked' : ''; ?> onchange="updateStorePreview()">
                                        <label class="form-check-label ms-2 fw-semibold text-dark" for="isActive">
                                            Show this branch in customer marketplace directory &amp; search
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1 ps-4" style="font-size: 11.5px;">Leave enabled so customers can discover this shop and browse products.</small>
                                </div>
                            </div>

                            <!-- Block 2: Operating Hours & Schedule -->
                            <div class="config-fieldset">
                                <div class="config-fieldset-head">
                                    <span class="fieldset-title"><i class="fas fa-business-time text-danger"></i> 2. Operating Hours &amp; Schedule</span>
                                    <span class="fieldset-badge">Schedule</span>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <label class="compact-label" for="openTime">Daily Opening Time</label>
                                        <div class="compact-input-group">
                                            <span class="input-addon"><i class="fas fa-sun text-warning"></i></span>
                                            <input type="time" name="opening_time" id="openTime" class="form-control" value="<?php echo htmlspecialchars($opening_time_raw); ?>" onchange="updateStorePreview()">
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="compact-label" for="closeTime">Daily Closing Time</label>
                                        <div class="compact-input-group">
                                            <span class="input-addon"><i class="fas fa-moon text-primary"></i></span>
                                            <input type="time" name="closing_time" id="closeTime" class="form-control" value="<?php echo htmlspecialchars($closing_time_raw); ?>" onchange="updateStorePreview()">
                                        </div>
                                    </div>
                                </div>

                                <!-- Operating Days: Clear Closed vs Open Toggle -->
                                <div class="mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                        <div>
                                            <label class="compact-label mb-0 fw-bold">Operating Days (Choose Open or Closed per day):</label>
                                            <small class="text-muted d-block" style="font-size: 11.5px;">Click any day to toggle between <strong class="text-success">Open</strong> and <strong class="text-danger">Closed</strong>.</small>
                                        </div>
                                        <div class="quick-days-presets">
                                            <button type="button" class="btn-day-preset" onclick="setDayPreset('daily')"><i class="fas fa-calendar-days"></i> Open Daily (7 Days)</button>
                                            <button type="button" class="btn-day-preset" onclick="setDayPreset('weekdays')"><i class="fas fa-briefcase"></i> Weekdays Only (Mon-Fri)</button>
                                            <button type="button" class="btn-day-preset" onclick="setDayPreset('weekends')"><i class="fas fa-champagne-glasses"></i> Weekends Only (Sat-Sun)</button>
                                        </div>
                                    </div>

                                    <div class="day-pills-row" id="dayPillsRow">
                                        <?php foreach ($day_labels as $day_number => $d_info): ?>
                                            <?php $is_day_selected = in_array($day_number, $selected_days, true); ?>
                                            <div class="day-card-toggle <?php echo $is_day_selected ? 'is-open' : 'is-closed'; ?>" id="dayCard_<?php echo $day_number; ?>" onclick="toggleDayCard(<?php echo $day_number; ?>)">
                                                <input type="checkbox" name="operating_days[]" id="dayInput_<?php echo $day_number; ?>" value="<?php echo $day_number; ?>" <?php echo $is_day_selected ? 'checked' : ''; ?> style="display:none;">
                                                <div class="day-card-name"><?php echo $d_info['short']; ?></div>
                                                <div class="day-status-pill <?php echo $is_day_selected ? 'pill-open' : 'pill-closed'; ?>" id="dayPillStatus_<?php echo $day_number; ?>">
                                                    <span class="status-open-text"><i class="fas fa-check"></i> Open</span>
                                                    <span class="status-closed-text"><i class="fas fa-times"></i> Closed</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Schedule Summary -->
                                <div class="schedule-summary-box">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-calendar-check text-primary"></i>
                                        <span class="summary-label">Public Directory Display:</span>
                                    </div>
                                    <strong class="summary-value" id="scheduleDisplay">
                                        <?php echo htmlspecialchars((string)($store['opening_hours'] ?? 'Daily | 8:00 AM - 8:00 PM')); ?>
                                    </strong>
                                </div>
                            </div>

                            <!-- Save CTA Button -->
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
                                    <span class="badge text-bg-light border" id="previewBadgeMode"><?php echo ucfirst($current_mode); ?> Mode</span>
                                </div>

                                <!-- Mockup Card -->
                                <div class="store-preview-mockup">
                                    <div class="mockup-header-banner">
                                        <div class="mockup-store-avatar"><i class="fas fa-shop"></i></div>
                                        <span class="mockup-status-pill <?php echo $is_open_now ? 'open' : ($current_manual === 'away' ? 'away' : 'closed'); ?>" id="mockupStatusPill">
                                            <i class="fas fa-circle-dot me-1"></i> <span id="mockupStatusText"><?php echo $is_open_now ? 'Open now' : ($current_manual === 'away' ? 'Away' : 'Closed now'); ?></span>
                                        </span>
                                    </div>
                                    <div class="mockup-body">
                                        <h4 class="mockup-store-name"><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Store')); ?></h4>
                                        <p class="mockup-address"><?php echo htmlspecialchars((string)($store['address'] ?? 'Store address not set')); ?></p>
                                        
                                        <div class="mockup-schedule-row">
                                            <i class="fas fa-clock text-muted"></i>
                                            <span id="mockupScheduleText"><?php echo htmlspecialchars((string)($availability['schedule'] ?? 'Daily | 8:00 AM - 8:00 PM')); ?></span>
                                        </div>

                                        <div class="mockup-note-box" id="mockupNoteBox">
                                            <i class="fas fa-circle-info me-1 text-primary"></i>
                                            <span id="mockupNoteText"><?php echo htmlspecialchars((string)($availability['note'] ?? '')); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Operations Guide -->
                                <div class="preview-tips-box">
                                    <div class="tips-title"><i class="fas fa-lightbulb text-warning me-1"></i> Operations Guide</div>
                                    <ul class="tips-list">
                                        <li><strong>Schedule Mode:</strong> Automatically marks store Open and Closed based on your local business hours and selected days.</li>
                                        <li><strong>Operating Days:</strong> Mark any day as Closed (Rest Day). On closed days, customers see the closed notice and can place advance pre-orders.</li>
                                        <li><strong>Manual Override:</strong> Use this during inventory counts, restocks, or emergencies to open or close instantly.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
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

function setStoreMode(mode) {
    document.getElementById('availMode').value = mode;
    const optSchedule = document.getElementById('optModeSchedule');
    const optManual = document.getElementById('optModeManual');
    const manualRow = document.getElementById('manualStatusRow');
    const badgeMode = document.getElementById('previewBadgeMode');

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
    updateStorePreview();
}

function setManualStatus(status) {
    document.getElementById('manualStatus').value = status;
    const opts = ['open', 'away', 'closed'];
    opts.forEach(opt => {
        const el = document.getElementById('manOpt' + opt.charAt(0).toUpperCase() + opt.slice(1));
        if (el) {
            el.classList.toggle('active', opt === status);
        }
    });
    updateStorePreview();
}

function toggleDayCard(dayNum) {
    const input = document.getElementById('dayInput_' + dayNum);
    const card = document.getElementById('dayCard_' + dayNum);
    const pill = document.getElementById('dayPillStatus_' + dayNum);

    input.checked = !input.checked;

    if (input.checked) {
        card.classList.remove('is-closed');
        card.classList.add('is-open');
        pill.classList.remove('pill-closed');
        pill.classList.add('pill-open');
    } else {
        card.classList.remove('is-open');
        card.classList.add('is-closed');
        pill.classList.remove('pill-open');
        pill.classList.add('pill-closed');
    }
    updateStorePreview();
}

function setDayPreset(preset) {
    for (let day = 1; day <= 7; day++) {
        let isOpen = false;
        if (preset === 'daily') {
            isOpen = true;
        } else if (preset === 'weekdays') {
            isOpen = (day >= 1 && day <= 5);
        } else if (preset === 'weekends') {
            isOpen = (day === 6 || day === 7);
        }

        const input = document.getElementById('dayInput_' + day);
        const card = document.getElementById('dayCard_' + day);
        const pill = document.getElementById('dayPillStatus_' + day);

        if (input && card && pill) {
            input.checked = isOpen;
            if (isOpen) {
                card.classList.remove('is-closed');
                card.classList.add('is-open');
                pill.classList.remove('pill-closed');
                pill.classList.add('pill-open');
            } else {
                card.classList.remove('is-open');
                card.classList.add('is-closed');
                pill.classList.remove('pill-open');
                pill.classList.add('pill-closed');
            }
        }
    }
    updateStorePreview();
}

function updateStorePreview() {
    const modeEl = document.getElementById('availMode');
    const manualStatusEl = document.getElementById('manualStatus');
    const isActiveEl = document.getElementById('isActive');
    const openTimeEl = document.getElementById('openTime');
    const closeTimeEl = document.getElementById('closeTime');

    if (!modeEl) return;

    const mode = modeEl.value;
    const manualStatus = manualStatusEl.value;
    const isActive = isActiveEl ? isActiveEl.checked : true;
    const openTime = openTimeEl.value || '08:00';
    const closeTime = closeTimeEl.value || '20:00';

    // Collect open and closed days
    const openDays = [];
    const closedDays = [];
    for (let day = 1; day <= 7; day++) {
        const input = document.getElementById('dayInput_' + day);
        if (input && input.checked) {
            openDays.push(day);
        } else {
            closedDays.push(day);
        }
    }

    // Days summary text
    let daysLabel = 'Daily';
    if (openDays.length === 7) {
        daysLabel = 'Daily';
    } else if (openDays.length === 5 && openDays.join(',') === '1,2,3,4,5') {
        daysLabel = 'Mon - Fri';
    } else if (openDays.length === 2 && openDays.join(',') === '6,7') {
        daysLabel = 'Sat - Sun';
    } else if (openDays.length > 0) {
        daysLabel = openDays.map(d => dayNames[d]).join(', ');
    } else {
        daysLabel = 'Closed All Week';
    }

    let closedSummary = '';
    if (closedDays.length > 0 && openDays.length > 0) {
        closedSummary = ' • Closed: ' + closedDays.map(d => dayNames[d]).join(', ');
    }

    const scheduleFormatted = (openDays.length > 0)
        ? `${daysLabel} | ${formatTime12h(openTime)} - ${formatTime12h(closeTime)}${closedSummary}`
        : 'Closed on all days (No operating days selected)';

    const schedDisp = document.getElementById('scheduleDisplay');
    const mockSched = document.getElementById('mockupScheduleText');
    if (schedDisp) schedDisp.textContent = scheduleFormatted;
    if (mockSched) mockSched.textContent = scheduleFormatted;

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
        // Schedule mode check against current local time in Philippines
        const now = new Date();
        const currentIsoDay = now.getDay() === 0 ? 7 : now.getDay();
        const currentMinutes = now.getHours() * 60 + now.getMinutes();

        const [oH, oM] = openTime.split(':').map(Number);
        const [cH, cM] = closeTime.split(':').map(Number);
        const openMin = oH * 60 + oM;
        const closeMin = cH * 60 + cM;

        const isDayAllowed = openDays.includes(currentIsoDay);
        const isTimeWithin = (closeMin > openMin)
            ? (currentMinutes >= openMin && currentMinutes <= closeMin)
            : (currentMinutes >= openMin || currentMinutes <= closeMin);

        if (isDayAllowed && isTimeWithin) {
            statusClass = 'open';
            statusLabel = 'Open now';
            noteText = 'Open now based on store business schedule.';
        } else if (!isDayAllowed) {
            statusClass = 'closed';
            statusLabel = 'Closed today';
            noteText = 'Store is closed today (' + dayNames[currentIsoDay] + '). Scheduled rest day.';
        } else {
            statusClass = 'closed';
            statusLabel = 'Closed now';
            noteText = 'Closed right now based on operating hours (' + formatTime12h(openTime) + ' - ' + formatTime12h(closeTime) + ').';
        }
    }

    const mockupPill = document.getElementById('mockupStatusPill');
    const mockupText = document.getElementById('mockupStatusText');
    const mockupNote = document.getElementById('mockupNoteText');
    const topPill = document.getElementById('topPill');

    if (mockupPill) mockupPill.className = 'mockup-status-pill ' + statusClass;
    if (mockupText) mockupText.textContent = statusLabel;
    if (mockupNote) mockupNote.textContent = noteText;
    if (topPill) {
        topPill.className = 'store-status-pill ' + statusClass;
        topPill.innerHTML = (statusClass === 'open')
            ? '<i class="fas fa-circle-dot"></i> Open for Orders'
            : (statusClass === 'away' ? '<i class="fas fa-circle-pause"></i> Away / Paused' : '<i class="fas fa-circle-xmark"></i> Closed');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateStorePreview();
});

// SweetAlert2 Session Messages
<?php if (isset($_SESSION['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: <?php echo json_encode((string)$_SESSION['success']); ?>,
        timer: 2500,
        showConfirmButton: false
    });
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: <?php echo json_encode((string)$_SESSION['error']); ?>
    });
<?php unset($_SESSION['error']); endif; ?>
</script>
</body>
</html>
