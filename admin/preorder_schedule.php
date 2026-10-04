<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/auth.php';
checkAdminAccess();
require_once __DIR__ . '/../includes/preorder_schedule_helper.php';
requirePermission('preorders.view');

$admin_info = getAdminInfo($conn);
$csrf_token = generateCSRFToken();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : 0;
$target_seller_id = ($seller_scope_id > 0) ? (int)$seller_scope_id : 0;

$current_page = 'preorder_schedule.php';
$page_title = 'Pickup Schedule & Calendar Map';

// -------------------------------------------------------------
// AJAX API Endpoint: Fetch Date Summary
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_date_details') {
    header('Content-Type: application/json');
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.']);
        exit;
    }
    $summary = posGetDateSummary($conn, $target_seller_id, $date);
    echo json_encode(['success' => true, 'data' => $summary]);
    exit;
}

// -------------------------------------------------------------
// AJAX API Endpoint: Update Date Schedule
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_date_schedule') {
    header('Content-Type: application/json');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
        exit;
    }

    $date = trim((string)($_POST['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.']);
        exit;
    }

    $status = trim((string)($_POST['status'] ?? '')); // 'open', 'blocked', or ''
    $custom_capacity = isset($_POST['custom_capacity']) ? (int)$_POST['custom_capacity'] : 0;
    $use_custom_cap = isset($_POST['use_custom_capacity']) && $_POST['use_custom_capacity'] === '1';

    $settings = [
        'status' => $status
    ];
    if ($use_custom_cap && $custom_capacity > 0) {
        $settings['max_orders_per_day'] = $custom_capacity;
    } else {
        $settings['max_orders_per_day'] = 0; // Clears custom override
    }

    $ok = posUpdateDateSchedule($conn, $target_seller_id, $date, $settings);
    if ($ok) {
        $updated_summary = posGetDateSummary($conn, $target_seller_id, $date);
        echo json_encode([
            'success' => true,
            'message' => 'Schedule for ' . date('F j, Y', strtotime($date)) . ' updated successfully.',
            'data' => $updated_summary
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save date schedule changes.']);
    }
    exit;
}

// -------------------------------------------------------------
// Standard Form Submission: Store Global Schedule Rules
// -------------------------------------------------------------
$flash_success = '';
$flash_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_store_rules'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $flash_error = 'Invalid security token. Please refresh and try again.';
    } else {
        $lead_time_days = max(0, min(14, (int)($_POST['lead_time_days'] ?? 1)));
        $cutoff_time = trim((string)($_POST['cutoff_time'] ?? '18:00'));
        $max_advance_days = max(7, min(90, (int)($_POST['max_advance_days'] ?? 30)));
        
        $operating_days_arr = $_POST['operating_days'] ?? ['1','2','3','4','5','6','7'];
        if (!is_array($operating_days_arr) || empty($operating_days_arr)) {
            $operating_days_arr = ['1','2','3','4','5','6','7'];
        }
        $operating_days = implode(',', array_map('strval', $operating_days_arr));

        $slot_start_time = trim((string)($_POST['slot_start_time'] ?? '08:00'));
        $slot_end_time = trim((string)($_POST['slot_end_time'] ?? '20:00'));
        $slot_interval_minutes = (int)($_POST['slot_interval_minutes'] ?? 60);
        $max_orders_per_slot = max(1, min(50, (int)($_POST['max_orders_per_slot'] ?? 3)));
        $max_orders_per_day = max(1, min(200, (int)($_POST['max_orders_per_day'] ?? 15)));
        $blackout_dates = trim((string)($_POST['blackout_dates'] ?? ''));

        $save_data = [
            'lead_time_days' => $lead_time_days,
            'cutoff_time' => $cutoff_time,
            'max_advance_days' => $max_advance_days,
            'operating_days' => $operating_days,
            'slot_start_time' => $slot_start_time,
            'slot_end_time' => $slot_end_time,
            'slot_interval_minutes' => $slot_interval_minutes,
            'max_orders_per_slot' => $max_orders_per_slot,
            'max_orders_per_day' => $max_orders_per_day,
            'blackout_dates' => $blackout_dates,
            'is_active' => 1
        ];

        if (posSaveSellerSchedule($conn, $target_seller_id, $save_data)) {
            $flash_success = 'Store schedule defaults saved successfully!';
        } else {
            $flash_error = 'Failed to update schedule rules. Please try again.';
        }
    }
}

$schedule = posGetSellerSchedule($conn, $target_seller_id);
$operating_days_arr = explode(',', (string)$schedule['operating_days']);

// Resolve shop / branch name
$shop_display_name = 'Main Store (HQ)';
if ($target_seller_id > 0) {
    $u_res = mysqli_query($conn, "SELECT COALESCE(NULLIF(TRIM(business_name), ''), full_name) as bname FROM users WHERE id = {$target_seller_id} LIMIT 1");
    if ($u_row = mysqli_fetch_assoc($u_res)) {
        $shop_display_name = $u_row['bname'];
    }
}

// -------------------------------------------------------------
// Calendar Month Navigation
// -------------------------------------------------------------
$req_month = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $req_month)) {
    $req_month = '';
}

$cal_data = posGetCalendarAvailability($conn, $target_seller_id, $req_month);
$current_view_month = $cal_data['current_month'];
$first_day_ts = strtotime($current_view_month . '-01');
$prev_month_url = '?month=' . date('Y-m', strtotime('-1 month', $first_day_ts));
$next_month_url = '?month=' . date('Y-m', strtotime('+1 month', $first_day_ts));
$today_month_url = '?month=' . date('Y-m');

$days_map = [
    '1' => 'Monday',
    '2' => 'Tuesday',
    '3' => 'Wednesday',
    '4' => 'Thursday',
    '5' => 'Friday',
    '6' => 'Saturday',
    '7' => 'Sunday'
];
$active_days_count = count(array_filter($operating_days_arr));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <style>
        .schedule-map-page {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8f9fa;
        }
        .schedule-shell {
            padding: 24px;
            max-width: 1440px;
            margin: 0 auto;
        }
        .schedule-hero {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .schedule-hero h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 800;
            color: #101828;
            margin: 0 0 6px;
        }
        .schedule-hero p {
            color: #475467;
            font-size: 0.9rem;
            margin: 0;
        }

        /* KPI Quick Overview Bar */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .kpi-info small {
            display: block;
            color: #667085;
            font-size: 0.76rem;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.4px;
        }
        .kpi-info strong {
            font-size: 0.96rem;
            color: #101828;
            font-weight: 800;
        }

        /* Main Calendar Card */
        .calendar-main-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            margin-bottom: 30px;
        }
        .calendar-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eaecf0;
            margin-bottom: 20px;
        }
        .cal-nav-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cal-month-heading {
            font-family: 'Outfit', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            color: #101828;
            margin: 0;
            min-width: 220px;
            text-align: center;
        }
        .legend-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .legend-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .legend-chip.open { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .legend-chip.blocked { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
        .legend-chip.cutoff { background: #f2f4f7; color: #475467; border: 1px solid #d0d5dd; }
        .legend-chip.booked { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }

        /* Calendar Grid */
        .calendar-week-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 8px;
            text-align: center;
        }
        .weekday-name {
            font-size: 0.78rem;
            font-weight: 800;
            color: #667085;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 8px 0;
        }
        .calendar-cells-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        /* Day Cell Styles */
        .date-tile {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            min-height: 110px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            cursor: pointer;
            transition: all 0.18s ease-in-out;
            user-select: none;
        }
        .date-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(16, 24, 40, 0.08);
            border-color: #b3261e !important;
            z-index: 2;
        }
        .date-tile.empty-cell {
            background: #fbfcfd;
            border: 1px dashed #e4e7ec;
            cursor: default;
            opacity: 0.5;
        }
        .date-tile.empty-cell:hover {
            transform: none;
            box-shadow: none;
            border-color: #e4e7ec !important;
        }

        /* Status Variations */
        .date-tile.tile-open {
            border-color: #abefc6;
            background: #f9fefb;
        }
        .date-tile.tile-open:hover {
            border-color: #027a48 !important;
        }
        .date-tile.tile-blocked {
            border-color: #fecdca;
            background: #fffafa;
        }
        .date-tile.tile-cutoff,
        .date-tile.tile-closed,
        .date-tile.tile-past {
            border-color: #eaecf0;
            background: #f8f9fa;
        }
        .date-tile.tile-full {
            border-color: #fedf89;
            background: #fffaeb;
        }

        .tile-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .tile-day-num {
            font-size: 1.05rem;
            font-weight: 800;
            color: #101828;
            line-height: 1;
        }
        .tile-orders-badge {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 12px;
            background: #b3261e;
            color: #ffffff;
        }
        .tile-custom-badge {
            font-size: 0.62rem;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 4px;
            background: #eff8ff;
            color: #175cd3;
            border: 1px solid #b2ddff;
        }

        .tile-body {
            margin: 6px 0;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            width: 100%;
            justify-content: center;
            text-align: center;
        }
        .status-pill.pill-open { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .status-pill.pill-blocked { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
        .status-pill.pill-cutoff { background: #f2f4f7; color: #667085; border: 1px solid #d0d5dd; }
        .status-pill.pill-closed { background: #f2f4f7; color: #667085; border: 1px solid #d0d5dd; }
        .status-pill.pill-full { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }

        .tile-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.68rem;
            color: #667085;
        }
        .tile-click-hint {
            color: #b3261e;
            font-weight: 700;
            opacity: 0;
            transition: opacity 0.15s;
        }
        .date-tile:hover .tile-click-hint {
            opacity: 1;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
            border: 1px solid #eaecf0;
            box-shadow: 0 12px 30px rgba(16, 24, 40, 0.15);
        }
        .modal-header {
            border-bottom: 1px solid #eaecf0;
            padding: 18px 24px;
        }
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            color: #101828;
            font-size: 1.25rem;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 1px solid #eaecf0;
            padding: 16px 24px;
        }

        /* Status Picker Toggle Cards */
        .status-picker-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .status-choice-card {
            border: 2px solid #eaecf0;
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            transition: all 0.18s;
            text-align: center;
            background: #ffffff;
        }
        .status-choice-card:hover {
            border-color: #d0d5dd;
            background: #fbfcfd;
        }
        .status-choice-card.selected-open {
            border-color: #027a48;
            background: #ecfdf3;
            color: #027a48;
        }
        .status-choice-card.selected-blocked {
            border-color: #b3261e;
            background: #fff1f0;
            color: #b3261e;
        }
        .status-choice-card input[type="radio"] {
            display: none;
        }

        .day-toggle-card {
            border: 1px solid #d0d5dd;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: #ffffff;
            font-size: 0.86rem;
            user-select: none;
        }
        .day-toggle-card:hover {
            border-color: #b3261e;
            background: #fffafa;
        }
        .day-toggle-card:has(input:checked) {
            border-color: #b3261e;
            background: #fff1f0;
            color: #b3261e;
            font-weight: 700;
        }
        .day-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
            gap: 10px;
        }

        .slots-pills-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            max-height: 160px;
            overflow-y: auto;
            padding: 4px;
        }
        .slot-pill-badge {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 0.76rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .slot-pill-badge.booked {
            background: #fff1f0;
            border-color: #fecdca;
            color: #b3261e;
            font-weight: 700;
        }

        @media (max-width: 991px) {
            .calendar-cells-grid,
            .calendar-week-header {
                gap: 4px;
            }
            .date-tile {
                min-height: 85px;
                padding: 6px;
            }
            .tile-day-num {
                font-size: 0.9rem;
            }
            .status-pill {
                font-size: 0.65rem;
                padding: 2px 4px;
            }
            .tile-footer {
                display: none;
            }
        }
    </style>
</head>
<body class="admin-polish schedule-map-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Pickup Schedule &amp; Calendar Map</h1>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Store Owner')); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="schedule-shell">
                <!-- Hero Header -->
                <div class="schedule-hero">
                    <div>
                        <h2><i class="fas fa-calendar-alt text-danger me-2"></i><?php echo htmlspecialchars($shop_display_name); ?> — Pick-up Schedule</h2>
                        <p>Click any specific date directly on the calendar below to toggle availability, block holidays, or adjust daily roasting capacity.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#defaultRulesModal" style="border-radius: 10px; font-weight: 600;">
                            <i class="fas fa-sliders me-1"></i> Store Schedule Rules
                        </button>
                        <a href="preorders.php" class="btn btn-outline-secondary" style="border-radius: 10px; font-weight: 600;">
                            <i class="fas fa-list me-1"></i> Pre-Orders List
                        </a>
                    </div>
                </div>

                <?php if ($flash_success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background:#ecfdf3; border-color:#abefc6; color:#027a48; border-radius:12px;">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($flash_success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($flash_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background:#fff1f0; border-color:#fee4e2; color:#b3261e; border-radius:12px;">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($flash_error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Store KPI Quick Status Bar -->
                <div class="kpi-row">
                    <div class="kpi-card">
                        <div class="kpi-icon" style="background:#fff1f0; color:#b3261e;">
                            <i class="fas fa-stopwatch"></i>
                        </div>
                        <div class="kpi-info">
                            <small>Advance Booking Notice</small>
                            <strong><?php echo (int)$schedule['lead_time_days']; ?> Day(s) Notice</strong>
                            <div style="font-size: 0.72rem; color:#667085;">Daily Cutoff: <?php echo date('g:i A', strtotime($schedule['cutoff_time'])); ?></div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon" style="background:#ecfdf3; color:#027a48;">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="kpi-info">
                            <small>Active Roasting Days</small>
                            <strong><?php echo $active_days_count; ?> Days / Week</strong>
                            <div style="font-size: 0.72rem; color:#667085;">Rolling window: <?php echo (int)$schedule['max_advance_days']; ?> days ahead</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon" style="background:#eff8ff; color:#175cd3;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="kpi-info">
                            <small>Pick-Up Hours</small>
                            <strong><?php echo date('g:i A', strtotime($schedule['slot_start_time'])); ?> - <?php echo date('g:i A', strtotime($schedule['slot_end_time'])); ?></strong>
                            <div style="font-size: 0.72rem; color:#667085;">Interval: Every <?php echo (int)$schedule['slot_interval_minutes']; ?> mins</div>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon" style="background:#fffaeb; color:#b54708;">
                            <i class="fas fa-boxes-stacked"></i>
                        </div>
                        <div class="kpi-info">
                            <small>Roasting Capacity</small>
                            <strong><?php echo (int)$schedule['max_orders_per_day']; ?> Orders / Day</strong>
                            <div style="font-size: 0.72rem; color:#667085;">Max <?php echo (int)$schedule['max_orders_per_slot']; ?> per slot</div>
                        </div>
                    </div>
                </div>

                <!-- Main Calendar Map Interface -->
                <div class="calendar-main-card">
                    <div class="calendar-header-bar">
                        <div class="cal-nav-group">
                            <a href="<?php echo htmlspecialchars($prev_month_url); ?>" class="btn btn-outline-secondary btn-sm" title="Previous Month" style="border-radius: 8px;">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <h3 class="cal-month-heading"><?php echo htmlspecialchars($cal_data['month_title']); ?></h3>
                            <a href="<?php echo htmlspecialchars($next_month_url); ?>" class="btn btn-outline-secondary btn-sm" title="Next Month" style="border-radius: 8px;">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <a href="<?php echo htmlspecialchars($today_month_url); ?>" class="btn btn-light btn-sm fw-bold border ms-2" style="border-radius: 8px;">
                                Today
                            </a>
                        </div>

                        <div class="legend-row">
                            <span class="legend-chip open"><i class="fas fa-check-circle"></i> Open (Pick-up Available)</span>
                            <span class="legend-chip blocked"><i class="fas fa-ban"></i> Blocked / Holiday</span>
                            <span class="legend-chip cutoff"><i class="fas fa-clock"></i> Cutoff / Closed Weekday</span>
                            <span class="legend-chip booked"><i class="fas fa-list"></i> Pre-Orders Booked</span>
                        </div>
                    </div>

                    <!-- Weekday Headers -->
                    <div class="calendar-week-header">
                        <div class="weekday-name">Mon</div>
                        <div class="weekday-name">Tue</div>
                        <div class="weekday-name">Wed</div>
                        <div class="weekday-name">Thu</div>
                        <div class="weekday-name">Fri</div>
                        <div class="weekday-name">Sat</div>
                        <div class="weekday-name">Sun</div>
                    </div>

                    <!-- Day Cells Grid -->
                    <div class="calendar-cells-grid">
                        <?php 
                        // Empty leading offset cells
                        for ($pad = 1; $pad < $cal_data['first_day_weekday']; $pad++) {
                            echo '<div class="date-tile empty-cell"></div>';
                        }

                        // Active month days
                        foreach ($cal_data['days'] as $day_data): 
                            $date_str = $day_data['date'];
                            $is_avail = $day_data['available'];
                            $status = $day_data['status'];
                            $booked = (int)$day_data['booked_count'];
                            $capacity = (int)$day_data['max_daily_capacity'];
                            $remaining = (int)$day_data['remaining_capacity'];

                            $tile_class = 'tile-open';
                            $pill_class = 'pill-open';
                            $pill_text = 'Open';
                            $pill_icon = 'fa-check';

                            if ($status === 'blackout') {
                                $tile_class = 'tile-blocked';
                                $pill_class = 'pill-blocked';
                                $pill_text = 'Blocked';
                                $pill_icon = 'fa-ban';
                            } elseif ($status === 'fully_booked') {
                                $tile_class = 'tile-full';
                                $pill_class = 'pill-full';
                                $pill_text = 'Full Capacity';
                                $pill_icon = 'fa-fire';
                            } elseif ($status === 'lead_time_cutoff') {
                                $tile_class = 'tile-cutoff';
                                $pill_class = 'pill-cutoff';
                                $pill_text = 'Notice Cutoff';
                                $pill_icon = 'fa-hourglass-half';
                            } elseif ($status === 'closed_weekday') {
                                $tile_class = 'tile-closed';
                                $pill_class = 'pill-closed';
                                $pill_text = 'Closed (' . $day_data['day_name'] . ')';
                                $pill_icon = 'fa-times';
                            } elseif ($status === 'past') {
                                $tile_class = 'tile-past';
                                $pill_class = 'pill-closed';
                                $pill_text = 'Past';
                                $pill_icon = 'fa-calendar-xmark';
                            } else {
                                $pill_text = ($remaining > 0) ? "Open ({$remaining} left)" : 'Open';
                            }
                        ?>
                            <div class="date-tile <?php echo $tile_class; ?>" 
                                 data-date="<?php echo $date_str; ?>" 
                                 onclick="handleDateCellClick('<?php echo $date_str; ?>')">
                                <div class="tile-top">
                                    <span class="tile-day-num"><?php echo $day_data['day']; ?></span>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if (!empty($day_data['has_custom_capacity'])): ?>
                                            <span class="tile-custom-badge" title="Custom capacity limit set for this day">
                                                <i class="fas fa-tag"></i> <?php echo $capacity; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($booked > 0): ?>
                                            <span class="tile-orders-badge" title="<?php echo $booked; ?> pre-orders booked">
                                                <?php echo $booked; ?> <?php echo $booked === 1 ? 'Order' : 'Orders'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="tile-body">
                                    <div class="status-pill <?php echo $pill_class; ?>">
                                        <i class="fas <?php echo $pill_icon; ?> me-1"></i> <?php echo htmlspecialchars($pill_text); ?>
                                    </div>
                                </div>

                                <div class="tile-footer">
                                    <span><?php echo $booked; ?> / <?php echo $capacity; ?> booked</span>
                                    <span class="tile-click-hint"><i class="fas fa-edit me-1"></i>Edit</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- Date Schedule Editor Modal                                -->
    <!-- ========================================================= -->
    <div class="modal fade" id="dateEditorModal" tabindex="-1" aria-labelledby="dateModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="dateModalTitle">
                            <i class="fas fa-calendar-day text-danger me-2"></i>
                            <span id="modalDateHeading">Date Schedule</span>
                        </h5>
                        <small class="text-muted" id="modalDateSubtitle">Configure pickup availability and daily limits</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <!-- Loading Spinner Indicator -->
                    <div id="dateModalLoading" class="text-center py-5">
                        <div class="spinner-border text-danger" role="status"></div>
                        <div class="text-muted mt-2 fw-semibold">Loading date schedule...</div>
                    </div>

                    <!-- Modal Interactive Form -->
                    <div id="dateModalFormWrap" style="display: none;">
                        <input type="hidden" id="editDateValue" value="">

                        <!-- Status Selector Radio Cards -->
                        <div class="mb-4">
                            <label class="form-label fw-bold" style="color: #101828;">Date Pick-up Status</label>
                            <div class="status-picker-grid">
                                <label class="status-choice-card" id="choiceCardOpen">
                                    <input type="radio" name="modal_date_status" value="open" id="radioStatusOpen">
                                    <div class="fw-bold fs-6 mb-1"><i class="fas fa-check-circle me-1"></i> Open for Pick-Up</div>
                                    <small style="font-size: 0.76rem; display: block; opacity: 0.85;">Customers can book reservations for this day</small>
                                </label>

                                <label class="status-choice-card" id="choiceCardBlocked">
                                    <input type="radio" name="modal_date_status" value="blocked" id="radioStatusBlocked">
                                    <div class="fw-bold fs-6 mb-1"><i class="fas fa-ban me-1"></i> Block This Date</div>
                                    <small style="font-size: 0.76rem; display: block; opacity: 0.85;">Closed for holiday, maintenance, or day off</small>
                                </label>
                            </div>
                        </div>

                        <!-- Capacity Override -->
                        <div class="schedule-card mb-4" style="background:#f8f9fa; border:1px solid #eaecf0; border-radius:12px; padding:16px;">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="toggleCustomCapacity">
                                <label class="form-check-label fw-bold" for="toggleCustomCapacity">
                                    Set Custom Roasting Capacity for this Specific Date
                                </label>
                            </div>
                            <p class="text-muted small mb-2">Override your store's default daily limit (<span id="modalDefaultDailyText"><?php echo (int)$schedule['max_orders_per_day']; ?> orders/day</span>) for special occasions, fiestas, or reduced capacity days.</p>
                            
                            <div id="customCapacityWrap" style="display: none;" class="mt-3">
                                <div class="row align-items-center g-2">
                                    <div class="col-sm-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-boxes-stacked"></i></span>
                                            <input type="number" id="modalCustomCapacityInput" class="form-control" min="1" max="200" placeholder="e.g. 25">
                                            <span class="input-group-text">orders / day</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <small class="text-muted">Total orders permitted across all pickup slots on this date.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pre-Orders on This Date -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-bold mb-0" style="color: #101828;">
                                    <i class="fas fa-receipt text-danger me-1"></i> Pre-Orders Booked for this Date
                                </label>
                                <span class="badge" id="modalOrdersCountBadge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; border-radius:6px;">0 Orders</span>
                            </div>

                            <div id="modalOrdersListContainer" style="max-height: 180px; overflow-y: auto;">
                                <!-- Dynamic orders loaded via JS -->
                            </div>
                        </div>

                        <!-- Time Slots Breakdown -->
                        <div>
                            <label class="form-label fw-bold mb-2" style="color: #101828;">
                                <i class="fas fa-clock text-warning me-1"></i> Pickup Time Slots
                            </label>
                            <div class="slots-pills-wrap" id="modalSlotsContainer">
                                <!-- Dynamic slots loaded via JS -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="btnSaveDateSchedule" style="background:#b3261e; border-color:#b3261e; border-radius:10px; font-weight:700;">
                            <i class="fas fa-save me-1"></i> Save Date Schedule
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- Store Default Schedule Rules Modal                        -->
    <!-- ========================================================= -->
    <div class="modal fade" id="defaultRulesModal" tabindex="-1" aria-labelledby="defaultRulesModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="save_store_rules" value="1">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="defaultRulesModalTitle">
                                <i class="fas fa-sliders text-danger me-2"></i> Store Schedule Rules &amp; Defaults
                            </h5>
                            <small class="text-muted">Configure default lead times, operating days, and daily roasting capacity</small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <!-- 1. Lead Time & Cutoff Rules -->
                        <div class="schedule-card mb-3" style="background:#ffffff; border:1px solid #eaecf0; border-radius:12px; padding:18px;">
                            <h6 class="fw-bold mb-3" style="color:#101828;">
                                <i class="fas fa-stopwatch text-danger me-1"></i> 1. Booking Notice &amp; Cutoff Rule
                            </h6>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Minimum Advance Notice (Lead Time)</label>
                                    <div class="input-group">
                                        <input type="number" name="lead_time_days" class="form-control" value="<?php echo (int)$schedule['lead_time_days']; ?>" min="0" max="14" required>
                                        <span class="input-group-text">Day(s)</span>
                                    </div>
                                    <small class="text-muted">Set to <code>1</code> for 24-hr notice, or <code>2</code> for 48-hr.</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Daily Order Cutoff Time</label>
                                    <input type="time" name="cutoff_time" class="form-control" value="<?php echo htmlspecialchars(substr($schedule['cutoff_time'], 0, 5)); ?>" required>
                                    <small class="text-muted">Orders placed after this time advance availability by +1 day.</small>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Maximum Days in Advance</label>
                                    <div class="input-group">
                                        <input type="number" name="max_advance_days" class="form-control" value="<?php echo (int)$schedule['max_advance_days']; ?>" min="7" max="90" required>
                                        <span class="input-group-text">Days</span>
                                    </div>
                                    <small class="text-muted">Rolling calendar window (e.g. up to 30 or 60 days ahead).</small>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Operating Days -->
                        <div class="schedule-card mb-3" style="background:#ffffff; border:1px solid #eaecf0; border-radius:12px; padding:18px;">
                            <h6 class="fw-bold mb-2" style="color:#101828;">
                                <i class="fas fa-calendar-week text-primary me-1"></i> 2. Operating Roasting Days
                            </h6>
                            <p class="text-muted small mb-3">Uncheck days when your roasting pit does not accept pre-order pickups.</p>
                            
                            <div class="day-checkbox-grid">
                                <?php foreach ($days_map as $val => $name): 
                                    $is_checked = in_array($val, $operating_days_arr, true);
                                ?>
                                    <label class="day-toggle-card">
                                        <input type="checkbox" name="operating_days[]" value="<?php echo $val; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                                        <span><?php echo $name; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 3. Pickup Time Slots & Roasting Capacity -->
                        <div class="schedule-card mb-3" style="background:#ffffff; border:1px solid #eaecf0; border-radius:12px; padding:18px;">
                            <h6 class="fw-bold mb-3" style="color:#101828;">
                                <i class="fas fa-fire text-warning me-1"></i> 3. Pickup Hours &amp; Capacity
                            </h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">First Pickup Time</label>
                                    <input type="time" name="slot_start_time" class="form-control" value="<?php echo htmlspecialchars(substr($schedule['slot_start_time'], 0, 5)); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Last Pickup Time</label>
                                    <input type="time" name="slot_end_time" class="form-control" value="<?php echo htmlspecialchars(substr($schedule['slot_end_time'], 0, 5)); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Slot Interval</label>
                                    <select name="slot_interval_minutes" class="form-select">
                                        <option value="30" <?php echo (int)$schedule['slot_interval_minutes'] === 30 ? 'selected' : ''; ?>>Every 30 mins</option>
                                        <option value="60" <?php echo (int)$schedule['slot_interval_minutes'] === 60 ? 'selected' : ''; ?>>Every 1 hour (Default)</option>
                                        <option value="90" <?php echo (int)$schedule['slot_interval_minutes'] === 90 ? 'selected' : ''; ?>>Every 1.5 hours</option>
                                        <option value="120" <?php echo (int)$schedule['slot_interval_minutes'] === 120 ? 'selected' : ''; ?>>Every 2 hours</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Max Orders Per Time Slot</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-boxes-stacked"></i></span>
                                        <input type="number" name="max_orders_per_slot" class="form-control" value="<?php echo (int)$schedule['max_orders_per_slot']; ?>" min="1" max="50" required>
                                        <span class="input-group-text">orders / slot</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Max Total Orders Per Day</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar-day"></i></span>
                                        <input type="number" name="max_orders_per_day" class="form-control" value="<?php echo (int)$schedule['max_orders_per_day']; ?>" min="1" max="200" required>
                                        <span class="input-group-text">orders / day</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Global Blackout Dates Input -->
                        <div class="schedule-card" style="background:#ffffff; border:1px solid #eaecf0; border-radius:12px; padding:18px;">
                            <h6 class="fw-bold mb-2" style="color:#101828;">
                                <i class="fas fa-ban text-danger me-1"></i> 4. Blocked Holiday Dates
                            </h6>
                            <input type="text" name="blackout_dates" class="form-control" value="<?php echo htmlspecialchars((string)$schedule['blackout_dates']); ?>" placeholder="e.g. 2026-12-25, 2026-01-01">
                            <small class="text-muted">Separate multiple dates with commas. You can also block or open dates directly on the calendar.</small>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold" style="background:#b3261e; border-color:#b3261e; border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Store Schedule Rules
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/sweetalert2.all.min.js"></script>
    <script>
        const CSRF_TOKEN = '<?php echo htmlspecialchars($csrf_token); ?>';
        let dateEditorModal = null;

        document.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('dateEditorModal');
            if (modalEl) {
                dateEditorModal = new bootstrap.Modal(modalEl);
            }

            const sidebarToggler = document.getElementById('sidebarToggler');
            const adminContainer = document.querySelector('.admin-container');
            if (sidebarToggler && adminContainer) {
                sidebarToggler.addEventListener('click', function() {
                    adminContainer.classList.toggle('sidebar-collapsed');
                });
            }

            // Status Radio Card Visual Selection
            const choiceOpen = document.getElementById('choiceCardOpen');
            const choiceBlocked = document.getElementById('choiceCardBlocked');
            const radioOpen = document.getElementById('radioStatusOpen');
            const radioBlocked = document.getElementById('radioStatusBlocked');

            function syncStatusCardStyles() {
                if (radioOpen.checked) {
                    choiceOpen.classList.add('selected-open');
                    choiceBlocked.classList.remove('selected-blocked');
                } else if (radioBlocked.checked) {
                    choiceBlocked.classList.add('selected-blocked');
                    choiceOpen.classList.remove('selected-open');
                } else {
                    choiceOpen.classList.remove('selected-open');
                    choiceBlocked.classList.remove('selected-blocked');
                }
            }

            if (radioOpen && radioBlocked) {
                radioOpen.addEventListener('change', syncStatusCardStyles);
                radioBlocked.addEventListener('change', syncStatusCardStyles);
            }

            // Custom Capacity Toggle
            const toggleCustomCap = document.getElementById('toggleCustomCapacity');
            const customCapWrap = document.getElementById('customCapacityWrap');
            if (toggleCustomCap && customCapWrap) {
                toggleCustomCap.addEventListener('change', function() {
                    customCapWrap.style.display = this.checked ? 'block' : 'none';
                });
            }

            // Save Date Schedule Button Handler
            const btnSaveDate = document.getElementById('btnSaveDateSchedule');
            if (btnSaveDate) {
                btnSaveDate.addEventListener('click', submitDateScheduleChanges);
            }
        });

        // -------------------------------------------------------------
        // Handle Clicking Any Specific Date on Calendar
        // -------------------------------------------------------------
        function handleDateCellClick(dateStr) {
            if (!dateEditorModal || !dateStr) return;

            const loading = document.getElementById('dateModalLoading');
            const formWrap = document.getElementById('dateModalFormWrap');
            const heading = document.getElementById('modalDateHeading');
            const subtitle = document.getElementById('modalDateSubtitle');
            const editDateVal = document.getElementById('editDateValue');

            loading.style.display = 'block';
            formWrap.style.display = 'none';
            heading.textContent = 'Loading...';
            subtitle.textContent = dateStr;
            editDateVal.value = dateStr;

            dateEditorModal.show();

            fetch(`preorder_schedule.php?action=get_date_details&date=${encodeURIComponent(dateStr)}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.data) {
                        throw new Error(data.message || 'Unable to load date schedule.');
                    }
                    populateDateModal(data.data);
                    loading.style.display = 'none';
                    formWrap.style.display = 'block';
                })
                .catch(err => {
                    loading.innerHTML = `
                        <div class="text-danger py-4">
                            <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                            <div>${err.message || 'Failed to load date details.'}</div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-3" data-bs-dismiss="modal">Close</button>
                        </div>
                    `;
                });
        }

        // -------------------------------------------------------------
        // Populate Date Editor Modal with Data
        // -------------------------------------------------------------
        function populateDateModal(info) {
            document.getElementById('modalDateHeading').textContent = info.formatted_date || info.date;
            document.getElementById('modalDateSubtitle').textContent = info.is_blocked 
                ? 'Status: Blocked / Holiday (Pickups Disabled)' 
                : 'Status: Open for Customer Pick-Up Reservations';

            const radioOpen = document.getElementById('radioStatusOpen');
            const radioBlocked = document.getElementById('radioStatusBlocked');
            const choiceOpen = document.getElementById('choiceCardOpen');
            const choiceBlocked = document.getElementById('choiceCardBlocked');

            if (info.is_blocked) {
                radioBlocked.checked = true;
                choiceBlocked.classList.add('selected-blocked');
                choiceOpen.classList.remove('selected-open');
            } else {
                radioOpen.checked = true;
                choiceOpen.classList.add('selected-open');
                choiceBlocked.classList.remove('selected-blocked');
            }

            // Custom Capacity
            const toggleCustomCap = document.getElementById('toggleCustomCapacity');
            const customCapWrap = document.getElementById('customCapacityWrap');
            const capInput = document.getElementById('modalCustomCapacityInput');
            const defaultCapText = document.getElementById('modalDefaultDailyText');

            defaultCapText.textContent = `${info.default_max_daily} orders/day`;

            if (info.has_custom_capacity && info.custom_capacity > 0) {
                toggleCustomCap.checked = true;
                customCapWrap.style.display = 'block';
                capInput.value = info.custom_capacity;
            } else {
                toggleCustomCap.checked = false;
                customCapWrap.style.display = 'none';
                capInput.value = info.max_daily_capacity || info.default_max_daily;
            }

            // Pre-Orders List
            const ordersBadge = document.getElementById('modalOrdersCountBadge');
            const ordersListWrap = document.getElementById('modalOrdersListContainer');
            ordersBadge.textContent = `${info.booked_count} Order(s)`;

            if (info.orders && info.orders.length > 0) {
                let html = '<div class="list-group list-group-flush border rounded-3">';
                info.orders.forEach(order => {
                    const statusClass = (order.reservation_status === 'confirmed' || order.reservation_status === 'completed') ? 'bg-success' : 'bg-warning text-dark';
                    html += `
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                            <div>
                                <div class="fw-bold" style="font-size:0.86rem; color:#101828;">
                                    ${escapeHtml(order.customer_name)}
                                    <span class="text-muted ms-1" style="font-size:0.75rem;">${escapeHtml(order.customer_phone || '')}</span>
                                </div>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    ${order.quantity}x ${escapeHtml(order.product_name)} • ₱${parseFloat(order.total_price).toLocaleString('en-US', {minimumFractionDigits:2})}
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge ${statusClass} mb-1" style="font-size:0.68rem; text-transform:uppercase;">${escapeHtml(order.reservation_status)}</span>
                                <div style="font-size:0.75rem; font-weight:700; color:#475467;"><i class="fas fa-clock text-muted me-1"></i>${escapeHtml(order.preferred_pickup_time)}</div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                ordersListWrap.innerHTML = html;
            } else {
                ordersListWrap.innerHTML = `
                    <div class="text-center py-3 px-2 border rounded-3 bg-light text-muted" style="font-size:0.82rem;">
                        <i class="fas fa-clipboard-check text-success me-1"></i> No pre-orders placed for this date yet.
                    </div>
                `;
            }

            // Slots Breakdown
            const slotsWrap = document.getElementById('modalSlotsContainer');
            if (info.time_slots && info.time_slots.length > 0) {
                let slotsHtml = '';
                info.time_slots.forEach(slot => {
                    const isBooked = slot.booked_count > 0;
                    const badgeClass = isBooked ? 'booked' : '';
                    slotsHtml += `
                        <div class="slot-pill-badge ${badgeClass}" title="${slot.display_label}">
                            <span>${slot.time_value}</span>
                            <span class="badge ${isBooked ? 'bg-danger' : 'bg-light text-muted border'}" style="font-size:0.65rem;">
                                ${slot.booked_count}/${slot.max_capacity}
                            </span>
                        </div>
                    `;
                });
                slotsWrap.innerHTML = slotsHtml;
            } else {
                slotsWrap.innerHTML = '<span class="text-muted small">No time slots configured.</span>';
            }
        }

        // -------------------------------------------------------------
        // Submit Date Schedule Changes
        // -------------------------------------------------------------
        function submitDateScheduleChanges() {
            const dateStr = document.getElementById('editDateValue').value;
            if (!dateStr) return;

            const radioOpen = document.getElementById('radioStatusOpen');
            const radioBlocked = document.getElementById('radioStatusBlocked');
            const statusVal = radioBlocked.checked ? 'blocked' : 'open';

            const toggleCustomCap = document.getElementById('toggleCustomCapacity');
            const capInput = document.getElementById('modalCustomCapacityInput');
            const useCustom = toggleCustomCap.checked ? '1' : '0';
            const customCapVal = toggleCustomCap.checked ? parseInt(capInput.value, 10) || 0 : 0;

            const btnSave = document.getElementById('btnSaveDateSchedule');
            const origHtml = btnSave.innerHTML;
            btnSave.disabled = true;
            btnSave.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            const formData = new FormData();
            formData.append('action', 'update_date_schedule');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('date', dateStr);
            formData.append('status', statusVal);
            formData.append('use_custom_capacity', useCustom);
            formData.append('custom_capacity', customCapVal);

            fetch('preorder_schedule.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnSave.disabled = false;
                btnSave.innerHTML = origHtml;

                if (!data.success) {
                    throw new Error(data.message || 'Failed to save changes.');
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Schedule Updated',
                        text: data.message,
                        timer: 1600,
                        showConfirmButton: false
                    });
                }

                if (dateEditorModal) {
                    dateEditorModal.hide();
                }

                // Refresh the calendar view smoothly
                setTimeout(() => {
                    window.location.reload();
                }, 900);
            })
            .catch(err => {
                btnSave.disabled = false;
                btnSave.innerHTML = origHtml;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: err.message,
                        confirmButtonColor: '#b3261e'
                    });
                } else {
                    alert(err.message);
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>
