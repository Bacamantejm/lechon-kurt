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
    <title>Store Availability - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .availability-shell { padding: 24px; }
        .availability-hero, .availability-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
        }
        .availability-hero { padding: 22px; margin-bottom: 18px; }
        .availability-hero h2 { margin: 0; font-size: 1.28rem; font-weight: 700; color: #0f172a; }
        .availability-hero p { margin: 10px 0 0; color: #64748b; }
        .availability-grid { display: grid; gap: 18px; }
        .availability-card { padding: 20px; }
        .availability-head { display: flex; justify-content: space-between; gap: 16px; align-items: start; margin-bottom: 18px; }
        .availability-title { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
        .availability-sub { margin: 6px 0 0; color: #64748b; font-size: .92rem; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            font-weight: 700;
            font-size: .88rem;
        }
        .status-pill.open { background: #dcfce7; color: #166534; }
        .status-pill.away { background: #fef3c7; color: #92400e; }
        .status-pill.closed { background: #fee2e2; color: #991b1b; }
        .status-banner {
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: .92rem;
        }
        .status-banner.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-banner.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .availability-note {
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #bfdbfe;
            background: linear-gradient(135deg, #eff6ff, #f8fafc);
            color: #1d4ed8;
            margin-bottom: 18px;
        }
        .availability-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .availability-form-grid .full-span { grid-column: 1 / -1; }
        .mode-grid, .day-grid {
            display: grid;
            gap: 12px;
        }
        .mode-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .mode-card, .day-card {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #f8fafc;
            padding: 14px;
        }
        .mode-card label, .day-card label {
            display: flex;
            gap: 10px;
            align-items: start;
            margin: 0;
            cursor: pointer;
        }
        .day-grid { grid-template-columns: repeat(7, minmax(0, 1fr)); }
        .day-card { padding: 10px; text-align: center; }
        .availability-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .helper-copy { color: #64748b; font-size: .86rem; margin-top: 6px; }
        @media (max-width: 920px) {
            .availability-form-grid,
            .mode-grid { grid-template-columns: 1fr; }
            .day-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .availability-shell { padding: 16px; }
            .availability-head { flex-direction: column; }
            .day-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
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

        <div class="availability-shell">
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="status-banner success"><?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="status-banner error"><?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <section class="availability-hero">
                <h2>Flexible Open / Away / Closed controls for <?php echo htmlspecialchars($shop_name); ?></h2>
                <p>Use manual mode when you need a quick on/off style override, or use schedule mode so the shop badge follows your dedicated business hours automatically.</p>
            </section>

            <div class="availability-grid">
                <?php foreach ($stores as $store): ?>
                    <?php
                        $availability = $store['availability'] ?? sahResolveStoreAvailability($store);
                        $selected_days = array_map('intval', array_filter(explode(',', (string)($store['operating_days'] ?? '1,2,3,4,5,6,7'))));
                    ?>
                    <section class="availability-card">
                        <div class="availability-head">
                            <div>
                                <h3 class="availability-title"><?php echo htmlspecialchars((string)($store['store_name'] ?? 'Store')); ?></h3>
                                <p class="availability-sub"><?php echo htmlspecialchars((string)($store['address'] ?? 'Store address not set')); ?></p>
                                <p class="availability-sub"><?php echo htmlspecialchars(trim((string)(($store['city'] ?? '') . ', ' . ($store['province'] ?? ''))), ENT_QUOTES); ?></p>
                            </div>
                            <span class="status-pill <?php echo htmlspecialchars((string)($availability['class'] ?? 'closed')); ?>">
                                <i class="fas fa-circle"></i>
                                <?php echo htmlspecialchars((string)($availability['label'] ?? 'Closed')); ?>
                            </span>
                        </div>

                        <div class="availability-note">
                            <strong>Customer-facing preview:</strong>
                            <?php echo htmlspecialchars((string)($availability['note'] ?? 'Store status unavailable.')); ?>
                            <?php if (!empty($availability['schedule'])): ?>
                                <div class="helper-copy">Schedule shown in directory: <?php echo htmlspecialchars((string)$availability['schedule']); ?></div>
                            <?php endif; ?>
                        </div>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="store_id" value="<?php echo (int)($store['store_id'] ?? 0); ?>">

                            <div class="availability-form-grid">
                                <div class="full-span">
                                    <div class="mode-card">
                                        <label>
                                            <input type="checkbox" name="is_active" value="1" <?php echo (int)($store['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                            <div>
                                                <strong>Show this store in the public directory</strong>
                                                <span class="helper-copy">Turn this off if you want to hide the shop completely. Leave it on if you only want the badge to show Open, Away, or Closed.</span>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <div class="full-span">
                                    <label class="form-label">Availability Mode</label>
                                    <div class="mode-grid">
                                        <div class="mode-card">
                                            <label>
                                                <input type="radio" name="availability_mode" value="schedule" <?php echo ($store['availability_mode'] ?? 'schedule') === 'schedule' ? 'checked' : ''; ?>>
                                                <div>
                                                    <strong>Schedule mode</strong>
                                                    <span class="helper-copy">Store shows Open or Closed based on its dedicated time and selected days.</span>
                                                </div>
                                            </label>
                                        </div>
                                        <div class="mode-card">
                                            <label>
                                                <input type="radio" name="availability_mode" value="manual" <?php echo ($store['availability_mode'] ?? 'schedule') === 'manual' ? 'checked' : ''; ?>>
                                                <div>
                                                    <strong>Manual mode</strong>
                                                    <span class="helper-copy">Use this like a quick switch whenever your team wants to set Open, Away, or Closed manually.</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label">Manual Status</label>
                                    <select name="manual_status" class="form-select">
                                        <option value="open" <?php echo ($store['manual_status'] ?? 'closed') === 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="away" <?php echo ($store['manual_status'] ?? 'closed') === 'away' ? 'selected' : ''; ?>>Away</option>
                                        <option value="closed" <?php echo ($store['manual_status'] ?? 'closed') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label">Current Hours Summary</label>
                                    <div class="form-control" style="min-height:48px;background:#f8fafc;"><?php echo htmlspecialchars((string)($store['opening_hours'] ?? 'Daily | 8:00 AM - 8:00 PM')); ?></div>
                                </div>

                                <div>
                                    <label class="form-label">Opening Time</label>
                                    <input type="time" name="opening_time" class="form-control" value="<?php echo htmlspecialchars(substr((string)($store['opening_time'] ?? '08:00:00'), 0, 5)); ?>">
                                </div>

                                <div>
                                    <label class="form-label">Closing Time</label>
                                    <input type="time" name="closing_time" class="form-control" value="<?php echo htmlspecialchars(substr((string)($store['closing_time'] ?? '20:00:00'), 0, 5)); ?>">
                                </div>

                                <div class="full-span">
                                    <label class="form-label">Operating Days</label>
                                    <div class="day-grid">
                                        <?php foreach ($day_labels as $day_number => $day_label): ?>
                                            <div class="day-card">
                                                <label>
                                                    <input type="checkbox" name="operating_days[]" value="<?php echo (int)$day_number; ?>" <?php echo in_array($day_number, $selected_days, true) ? 'checked' : ''; ?>>
                                                    <span><?php echo htmlspecialchars($day_label); ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="helper-copy">Schedule mode checks both the selected days and the opening / closing time. Manual mode ignores the schedule until you switch back.</div>
                                </div>
                            </div>

                            <div class="availability-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Availability
                                </button>
                                <button type="submit" name="immediate_toggle" value="open" class="btn btn-success">
                                    <i class="fas fa-bolt"></i> Open Now
                                </button>
                                <button type="submit" name="immediate_toggle" value="closed" class="btn btn-danger">
                                    <i class="fas fa-power-off"></i> Close Now
                                </button>
                                <a href="../locations.php?search=<?php echo urlencode((string)($store['store_name'] ?? '')); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-store"></i> View in Directory
                                </a>
                            </div>
                            <div class="helper-copy">Instant buttons switch to Manual mode immediately. Your schedule settings stay saved so you can switch back anytime.</div>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
