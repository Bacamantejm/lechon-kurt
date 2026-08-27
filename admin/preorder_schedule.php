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
$page_title = 'Pickup Schedule & Slots';

$flash_success = '';
$flash_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $flash_success = 'Roasting schedule and pre-order availability rules saved successfully!';
        } else {
            $flash_error = 'Failed to update schedule settings. Please try again.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <style>
        .preorder-schedule-page {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8f9fa;
        }
        .schedule-shell {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .schedule-hero {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .schedule-hero h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            color: #101828;
            margin: 0 0 6px;
        }
        .schedule-hero p {
            color: #475467;
            font-size: 0.88rem;
            margin: 0;
        }
        .schedule-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            margin-bottom: 24px;
        }
        .schedule-section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #101828;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .day-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
            gap: 10px;
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
        .preview-calendar-wrap {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }
        .cal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            font-weight: 700;
            font-size: 0.95rem;
            color: #101828;
        }
        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 0.76rem;
            font-weight: 800;
            color: #667085;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-day-cell {
            border: 1px solid #eaecf0;
            border-radius: 8px;
            min-height: 56px;
            padding: 4px;
            font-size: 0.74rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cal-day-cell.available {
            background: #ecfdf3;
            border-color: #abefc6;
            color: #027a48;
            font-weight: 700;
        }
        .cal-day-cell.disabled {
            background: #f2f4f7;
            color: #98a2b3;
            text-decoration: line-through;
        }
        .cal-day-cell.blackout {
            background: #fff1f0;
            border-color: #fee4e2;
            color: #b3261e;
        }
    </style>
</head>
<body class="admin-polish preorder-schedule-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Pickup Schedule &amp; Slots</h1>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Store Owner')); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="schedule-shell">
                <div class="schedule-hero">
                    <div>
                        <h2><i class="fas fa-calendar-alt text-danger me-2"></i><?php echo htmlspecialchars($shop_display_name); ?> — Pick-up Schedule</h2>
                        <p>Configure your roasting lead times, daily order limits, operating days, and pickup time windows.</p>
                    </div>
                    <div>
                        <a href="preorders.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Pre-Orders List
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

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="row g-4">
                        <!-- Left Column: Settings Form -->
                        <div class="col-lg-7">
                            <!-- 1. Lead Time & Cutoff Rules -->
                            <div class="schedule-card">
                                <h5 class="schedule-section-title">
                                    <i class="fas fa-stopwatch text-danger"></i> 1. Advance Booking Lead Time &amp; Cutoff Rule
                                </h5>
                                
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Minimum Advance Notice (Lead Time)</label>
                                        <div class="input-group">
                                            <input type="number" name="lead_time_days" class="form-control" value="<?php echo (int)$schedule['lead_time_days']; ?>" min="0" max="14" required>
                                            <span class="input-group-text">Day(s)</span>
                                        </div>
                                        <small class="text-muted">Set to <code>1</code> for 24-hr advance notice, or <code>2</code> for 48-hr.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Daily Order Cutoff Time</label>
                                        <input type="time" name="cutoff_time" class="form-control" value="<?php echo htmlspecialchars(substr($schedule['cutoff_time'], 0, 5)); ?>" required>
                                        <small class="text-muted">Orders placed after this time automatically push calendar availability by +1 day.</small>
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

                            <!-- 2. Roasting Operating Days -->
                            <div class="schedule-card">
                                <h5 class="schedule-section-title">
                                    <i class="fas fa-calendar-week text-primary"></i> 2. Operating Roasting Days
                                </h5>
                                <p class="text-muted small mb-3">Uncheck days when your roasting pit or branch does not accept pre-order pickups.</p>
                                
                                <div class="day-checkbox-grid">
                                    <?php 
                                    $days_map = [
                                        '1' => 'Monday',
                                        '2' => 'Tuesday',
                                        '3' => 'Wednesday',
                                        '4' => 'Thursday',
                                        '5' => 'Friday',
                                        '6' => 'Saturday',
                                        '7' => 'Sunday'
                                    ];
                                    foreach ($days_map as $val => $name): 
                                        $is_checked = in_array($val, $operating_days_arr, true);
                                    ?>
                                        <label class="day-toggle-card">
                                            <input type="checkbox" name="operating_days[]" value="<?php echo $val; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                                            <span><?php echo $name; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- 3. Time Slots & Roasting Capacity -->
                            <div class="schedule-card">
                                <h5 class="schedule-section-title">
                                    <i class="fas fa-fire text-warning"></i> 3. Pickup Time Slots &amp; Roasting Capacity
                                </h5>
                                
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
                                        <label class="form-label fw-semibold">Max Lechon Orders Per Time Slot</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-boxes-stacked"></i></span>
                                            <input type="number" name="max_orders_per_slot" class="form-control" value="<?php echo (int)$schedule['max_orders_per_slot']; ?>" min="1" max="50" required>
                                            <span class="input-group-text">orders / slot</span>
                                        </div>
                                        <small class="text-muted">Prevents overbooking the pit in a single hour.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Max Total Orders Per Day</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-calendar-day"></i></span>
                                            <input type="number" name="max_orders_per_day" class="form-control" value="<?php echo (int)$schedule['max_orders_per_day']; ?>" min="1" max="200" required>
                                            <span class="input-group-text">orders / day</span>
                                        </div>
                                        <small class="text-muted">Total daily capacity across all time slots.</small>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Blackout Dates / Holidays -->
                            <div class="schedule-card">
                                <h5 class="schedule-section-title">
                                    <i class="fas fa-ban text-danger"></i> 4. Blocked / Holiday Dates
                                </h5>
                                <label class="form-label fw-semibold">Specific Blackout Dates (YYYY-MM-DD format)</label>
                                <input type="text" name="blackout_dates" class="form-control" value="<?php echo htmlspecialchars((string)$schedule['blackout_dates']); ?>" placeholder="e.g. 2026-12-25, 2026-01-01">
                                <small class="text-muted">Separate multiple dates with commas. These dates will be disabled in the customer calendar.</small>
                            </div>

                            <div class="mb-4">
                                <button type="submit" class="btn btn-primary btn-lg px-5 py-3 fw-bold" style="background: #b3261e; border-color: #b3261e; border-radius: 12px;">
                                    <i class="fas fa-save me-2"></i> Save Pre-Order Schedule Settings
                                </button>
                            </div>
                        </div>

                        <!-- Right Column: Live Rolling Calendar Preview -->
                        <div class="col-lg-5">
                            <div class="preview-calendar-wrap sticky-top" style="top: 20px;">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h5 class="fw-bold mb-0" style="font-family:'Outfit',sans-serif; color: #101828;"><i class="fas fa-calendar-check text-success me-2"></i>Customer Calendar Preview</h5>
                                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; border-radius:6px; font-weight:700;">Rolling Auto-Update</span>
                                </div>
                                <p class="text-muted small mb-3">This shows how your pre-order calendar dynamically computes available pick-up dates for customers based on your rules.</p>

                                <?php 
                                $cal_preview = posGetCalendarAvailability($conn, $target_seller_id);
                                ?>
                                <div class="cal-head">
                                    <span><?php echo htmlspecialchars($cal_preview['month_title']); ?></span>
                                    <small class="text-muted">Earliest: <?php echo date('M d, Y', strtotime($cal_preview['min_booking_date'])); ?></small>
                                </div>

                                <div class="cal-weekdays">
                                    <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
                                </div>

                                <div class="cal-grid">
                                    <?php 
                                    // Blank leading cells
                                    for ($pad = 1; $pad < $cal_preview['first_day_weekday']; $pad++) {
                                        echo '<div class="cal-day-cell text-muted" style="opacity: 0.3;">-</div>';
                                    }
                                    foreach ($cal_preview['days'] as $day_data): 
                                        $cell_class = $day_data['available'] ? 'available' : ($day_data['status'] === 'blackout' ? 'blackout' : 'disabled');
                                    ?>
                                        <div class="cal-day-cell <?php echo $cell_class; ?>" title="<?php echo htmlspecialchars($day_data['status_reason']); ?>">
                                            <div class="fw-bold"><?php echo $day_data['day']; ?></div>
                                            <?php if ($day_data['available']): ?>
                                                <div style="font-size: 0.62rem; line-height: 1;">Open (<?php echo $day_data['remaining_capacity']; ?> left)</div>
                                            <?php else: ?>
                                                <div style="font-size: 0.62rem; line-height: 1; opacity: 0.8;"><?php echo ($day_data['status'] === 'lead_time_cutoff') ? 'Cutoff' : (($day_data['status'] === 'closed_weekday') ? 'Closed' : 'Full'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mt-4 pt-3 border-top small text-muted">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="display:inline-block; width:12px; height:12px; background:#ecfdf3; border:1px solid #abefc6; border-radius:3px;"></span>
                                        <span><strong>Available Date:</strong> Open for pick-up reservation</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="display:inline-block; width:12px; height:12px; background:#f2f4f7; border:1px solid #d0d5dd; border-radius:3px;"></span>
                                        <span><strong>Disabled Date:</strong> Lead time cutoff or closed weekday</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="display:inline-block; width:12px; height:12px; background:#fff1f0; border:1px solid #fee4e2; border-radius:3px;"></span>
                                        <span><strong>Blackout / Full:</strong> Holiday or maximum capacity reached</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebarToggler = document.getElementById('sidebarToggler');
        const adminContainer = document.querySelector('.admin-container');
        if (sidebarToggler && adminContainer) {
            sidebarToggler.addEventListener('click', function() {
                adminContainer.classList.toggle('sidebar-collapsed');
            });
        }
    </script>
</body>
</html>
