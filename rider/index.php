<?php
$page_title = 'Rider Dashboard';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/header.php';

$rider_pk = (int)$rider['id'];
$emp_id = (int)($rider['employee_id'] ?? 0);
$rider_user_id = (int)($_SESSION['user_id'] ?? $rider['user_id'] ?? 0);
$today_attendance = AttendanceService::getTodayAttendance($conn, $rider_user_id, $emp_id);
$shift_status = $today_attendance['status']; // 'not_timed_in', 'timed_in', 'timed_out'
$assigned_store = $today_attendance['store'];
$store_display_name = $assigned_store['store_name'] ?? ($rider['store_name'] ?? 'Store Branch');

$rider_pk = (int)$rider['id'];
$emp_id = (int)($rider['employee_id'] ?? 0);

// Today's Earnings
$today_earn_res = mysqli_query($conn, "
    SELECT COALESCE(SUM(total_earnings), 0) AS today_earnings, COUNT(*) AS today_deliveries
    FROM rider_earnings
    WHERE rider_id = $rider_pk
      AND DATE(earned_at) = CURDATE()
");
$today_stats = mysqli_fetch_assoc($today_earn_res);
$today_earnings = (float)($today_stats['today_earnings'] ?? 0.0);
$today_deliveries = (int)($today_stats['today_deliveries'] ?? 0);

// Active Delivery Check
$active_delivery = getRiderActiveDelivery($rider_pk, $emp_id);

// Recent Completed Deliveries for Dashboard Feed
$recent_deliveries = [];
$recent_res = mysqli_query($conn, "
    SELECT re.total_earnings, re.earned_at, o.order_number, o.customer_name, o.delivery_address
    FROM rider_earnings re
    LEFT JOIN orders o ON re.order_id = o.id
    WHERE re.rider_id = $rider_pk
    ORDER BY re.id DESC
    LIMIT 4
");
if ($recent_res) {
    while ($r = mysqli_fetch_assoc($recent_res)) {
        $recent_deliveries[] = $r;
    }
}

// Recent Customer Reviews for this rider
$rider_reviews = [];
$rev_query = mysqli_query($conn, "
    SELECT dr.rating, dr.comment, dr.created_at, o.order_number, u.full_name AS customer_name
    FROM delivery_reviews dr
    JOIN logistics_tracking lt ON dr.order_id = lt.order_id
    JOIN orders o ON dr.order_id = o.id
    LEFT JOIN users u ON dr.user_id = u.id
    WHERE lt.driver_id = $rider_pk
    ORDER BY dr.id DESC
    LIMIT 3
");
if ($rev_query) {
    while ($rev = mysqli_fetch_assoc($rev_query)) {
        $rider_reviews[] = $rev;
    }
}

// Available Unassigned Orders Pool (Open Deliveries - strictly scoped to hiring store)
$initial_available_orders = [];
$hiring_store_id = (int)($rider['store_id'] ?? 0);
$hiring_owner_id = (int)($rider['store_owner_id'] ?? 0);

if ($hiring_store_id > 0) {
    if ($hiring_owner_id > 0) {
        $avail_store_filter = "AND (o.pickup_location = $hiring_store_id OR o.store_id = $hiring_store_id OR (o.seller_id IS NOT NULL AND o.seller_id = $hiring_owner_id))";
    } else {
        $avail_store_filter = "AND (o.pickup_location = $hiring_store_id OR o.store_id = $hiring_store_id)";
    }
} else {
    // Rider has no store assigned - must not see orders from any store
    $avail_store_filter = "AND 1 = 0";
}

$init_avail_sql = "
    SELECT o.id AS order_id, o.order_number, o.customer_name, o.customer_phone,
           o.delivery_address, o.latitude AS customer_latitude, o.longitude AS customer_longitude,
           o.total_amount, o.delivery_fee, o.payment_method, o.pickup_location, o.store_id, o.seller_id, o.created_at,
           sl.store_name, sl.address AS store_address, sl.city AS store_city,
           lt.id AS tracking_id
    FROM orders o
    LEFT JOIN store_locations sl ON (o.pickup_location = sl.store_id OR o.store_id = sl.store_id)
    LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
    WHERE o.status IN ('confirmed', 'preparing')
      AND o.delivery_option = 'delivery'
      AND (lt.driver_id IS NULL OR lt.driver_id = 0 OR lt.current_status = 'pending')
      $avail_store_filter
    ORDER BY o.created_at ASC
    LIMIT 20
";
$init_avail_res = mysqli_query($conn, $init_avail_sql);
if ($init_avail_res) {
    while ($ao = mysqli_fetch_assoc($init_avail_res)) {
        $initial_available_orders[] = $ao;
    }
}
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="row g-3 g-lg-4">
        <!-- Left Column: Profile, Duty Switch, Today Stats, Active Mission, Shortcuts -->
        <div class="col-12 col-lg-5 col-xl-4">
            <!-- Shift Attendance Time Clock Card -->
            <?php if ($shift_status === 'not_timed_in'): ?>
                <div class="rider-card mb-3" style="border-color: rgba(254, 223, 137, 0.7); background: rgba(255, 252, 245, 0.85);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #fffaeb; color: #b54708; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                    Shift Attendance Required
                                </h6>
                                <span class="text-muted" style="font-size: 11px;">
                                    Time In required before accepting orders
                                </span>
                            </div>
                        </div>
                        <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10.5px; font-weight:700;">
                            Not Timed In
                        </span>
                    </div>
                    <div class="p-2 rounded mb-3" style="background:#ffffff; border: 1px solid #eaecf0; font-size: 11.5px;">
                        <div class="text-muted mb-1">
                            <i class="fas fa-store text-danger me-1"></i> Store: <strong><?php echo htmlspecialchars($store_display_name); ?></strong>
                        </div>
                        <?php if (!empty($today_attendance['schedule']['start_formatted'])): ?>
                            <div class="text-muted mb-1">
                                <i class="fas fa-calendar-day text-primary me-1"></i> Shift: <strong><?php echo htmlspecialchars($today_attendance['schedule']['start_formatted']); ?> - <?php echo htmlspecialchars($today_attendance['schedule']['end_formatted']); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="text-muted">
                            <i class="fas fa-location-crosshairs text-primary me-1"></i> Proximity: <strong>Within 350m auto-accepts shift</strong>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary w-100 py-2 fw-bold d-flex align-items-center justify-content-center gap-2" id="riderClockBtn" onclick="riderTimeClock('clock_in')" style="background: #b3261e; border: none; border-radius: 10px; font-size: 13px;">
                        <i class="fas fa-sign-in-alt"></i> Time In (Verify Store GPS)
                    </button>
                </div>
            <?php elseif ($shift_status === 'timed_in'): ?>
                <div class="rider-card mb-3" style="border-color: rgba(171, 239, 198, 0.7); background: rgba(246, 254, 249, 0.85);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #ecfdf3; color: #027a48; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                <i class="fas fa-business-time"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                    Active Shift &bull; On Duty
                                </h6>
                                <span class="text-muted" style="font-size: 11px;">
                                    Clocked in at <?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?>
                                </span>
                            </div>
                        </div>
                        <?php if (($today_attendance['hr_status'] ?? '') === 'approved'): ?>
                            <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:10.5px; font-weight:700;">
                                <i class="fas fa-check-circle me-1"></i> Verified
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10.5px; font-weight:700;">
                                <i class="fas fa-hourglass-half me-1"></i> Pending HR
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 rounded mb-2" style="background:#ffffff; border: 1px solid #eaecf0; font-size: 11.5px;">
                        <div class="d-flex justify-content-between text-muted mb-1">
                            <span><i class="fas fa-store text-danger me-1"></i> Store:</span>
                            <strong class="text-dark"><?php echo htmlspecialchars($store_display_name); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between text-muted">
                            <span><i class="fas fa-clock text-primary me-1"></i> Time In:</span>
                            <strong class="text-success"><?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?></strong>
                        </div>
                        <?php if (!empty($today_attendance['late_minutes'])): ?>
                            <div class="d-flex justify-content-between text-danger mt-1">
                                <span><i class="fas fa-exclamation-circle me-1"></i> Notice:</span>
                                <strong>Late (<?php echo (int)$today_attendance['late_minutes']; ?>m)</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn w-50 py-2 fw-bold d-flex align-items-center justify-content-center gap-1" onclick="riderTimeClock('break_start')" style="background: #fffaeb; color: #b54708; border: 1px solid #fedf89; border-radius: 10px; font-size: 12.5px;">
                            <i class="fas fa-coffee"></i> Break
                        </button>
                        <button type="button" class="btn btn-outline-danger w-50 py-2 fw-bold d-flex align-items-center justify-content-center gap-1" onclick="riderTimeClock('clock_out')" style="border-radius: 10px; font-size: 12.5px;">
                            <i class="fas fa-sign-out-alt"></i> Time Out
                        </button>
                    </div>
                </div>
            <?php elseif ($shift_status === 'on_break'): ?>
                <div class="rider-card mb-3" style="border-color: rgba(254, 223, 137, 0.7); background: rgba(255, 252, 245, 0.85);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #fffaeb; color: #b54708; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                <i class="fas fa-coffee"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                    On Break &bull; Shift Paused
                                </h6>
                                <span class="text-muted" style="font-size: 11px;">
                                    Elapsed: <strong id="riderBreakTimer"><?php echo (int)($today_attendance['break_elapsed_minutes'] ?? 0); ?> mins</strong>
                                </span>
                            </div>
                        </div>
                        <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10.5px; font-weight:700;">
                            <i class="fas fa-pause-circle me-1"></i> On Break
                        </span>
                    </div>

                    <div id="riderBreakWarning" class="alert mt-1 mb-2 py-2 px-2 align-items-center gap-2" style="<?php echo (($today_attendance['break_elapsed_minutes'] ?? 0) >= 45) ? 'display:flex;' : 'display:none;'; ?> background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; border-radius: 8px; font-size: 11.5px;">
                        <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                        <span>Break time nearing 1-hour limit (<span id="riderWarnBreakMins"><?php echo (int)($today_attendance['break_elapsed_minutes'] ?? 0); ?></span>m elapsed). Please resume shift soon.</span>
                    </div>

                    <div class="p-2 rounded mb-2" style="background:#ffffff; border: 1px solid #eaecf0; font-size: 11.5px; color: #475467;">
                        <i class="fas fa-info-circle text-primary me-1"></i> Order acceptance is locked while on break.
                    </div>
                    <button type="button" class="btn text-white w-100 py-2 fw-bold d-flex align-items-center justify-content-center gap-2" onclick="riderTimeClock('break_end')" style="background: #027a48; border: none; border-radius: 10px; font-size: 13px;">
                        <i class="fas fa-play"></i> Continue Shift
                    </button>
                </div>
            <?php elseif ($shift_status === 'pending_approval'): ?>
                <div class="rider-card mb-3" style="border-color: rgba(254, 223, 137, 0.7); background: rgba(255, 252, 245, 0.85);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #fffaeb; color: #b54708; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                    2nd Check-in &bull; Pending
                                </h6>
                                <span class="text-muted" style="font-size: 11px;">
                                    In: <?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?>
                                </span>
                            </div>
                        </div>
                        <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:10.5px; font-weight:700;">
                            Awaiting Approval
                        </span>
                    </div>
                    <div class="p-2 rounded mb-2" style="background:#ffffff; border: 1px solid #eaecf0; font-size: 11.5px; color: #475467;">
                        <i class="fas fa-lock text-warning me-1"></i> You requested a 2nd check-in. The shop owner must approve your re-entry before you can accept orders.
                    </div>
                    <span class="badge w-100 py-2" style="background: #eaecf0; color: #475467; border-radius: 8px; font-size: 12px;">
                        <i class="fas fa-clock me-1"></i> Approval Pending
                    </span>
                </div>
            <?php else: ?>
                <div class="rider-card mb-3" style="border-color: rgba(178, 221, 255, 0.7); background: rgba(248, 251, 255, 0.85);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #eff8ff; color: #175cd3; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                    Shift Completed Today
                                </h6>
                                <span class="text-muted" style="font-size: 11px;">
                                    In: <?php echo htmlspecialchars($today_attendance['check_in_formatted'] ?? '-'); ?> &bull; Out: <?php echo htmlspecialchars($today_attendance['check_out_formatted'] ?? '-'); ?>
                                </span>
                            </div>
                        </div>
                        <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:10.5px; font-weight:700;">
                            Timed Out
                        </span>
                    </div>
                    <div class="p-2 rounded mb-2" style="background:#ffffff; border: 1px solid #eaecf0; font-size: 11.5px; color: #475467;">
                        <i class="fas fa-info-circle text-primary me-1"></i> Shift finished. Order acceptance is locked while off duty.
                    </div>
                    <button type="button" class="btn text-white w-100 py-2 fw-bold d-flex align-items-center justify-content-center gap-2" onclick="riderTimeClock('reenter')" style="background: #b3261e; border: none; border-radius: 10px; font-size: 13px;">
                        <i class="fas fa-sign-in-alt"></i> Check In Again
                    </button>
                    <small class="text-muted d-block text-center mt-1" style="font-size: 10.5px;">Requires shop owner approval</small>
                </div>
            <?php endif; ?>

            <!-- Rider Profile Hero Card -->
            <div class="rider-card mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div style="position: relative;">
                            <?php 
                                $rider_display_name = !empty($rider['rider_name']) ? $rider['rider_name'] : (!empty($_SESSION['rider_name']) ? $_SESSION['rider_name'] : 'Delivery Rider');
                            ?>
                            <?php if (!empty($rider['profile_image'])): ?>
                                <img src="../<?php echo htmlspecialchars($rider['profile_image']); ?>" alt="Rider Photo" style="width: 54px; height: 54px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-neutral);">
                            <?php else: ?>
                                <div style="width: 54px; height: 54px; border-radius: 50%; background: #fee4e2; color: var(--primary-red); display:flex; align-items:center; justify-content:center; font-size: 24px; font-weight: 800;">
                                    <?php echo strtoupper(substr($rider_display_name, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span class="pulse-dot <?php echo htmlspecialchars($rider['duty_status']); ?>" style="position: absolute; bottom: 2px; right: 2px; border: 2px solid #ffffff;"></span>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold" style="color: var(--primary-ink); font-size: 1.15rem; line-height: 1.25;">
                                <?php echo htmlspecialchars($rider_display_name); ?>
                            </h5>
                            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:10.5px; font-weight:700;">
                                    <?php echo htmlspecialchars($rider['rider_code']); ?>
                                </span>
                                <span class="badge" style="background:#f2f4f7; color:#344054; font-size:10.5px;">
                                    <i class="fas fa-star text-warning me-1"></i><?php echo number_format((float)($rider['rating'] ?? 5.0), 1); ?>
                                </span>
                                <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:10.5px;">
                                    <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($rider['store_name'] ?? 'Dasmariñas Branch'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <a href="profile.php" class="btn btn-sm btn-light p-2" style="border-radius: 10px; border: 1px solid var(--border-neutral);" title="Rider Settings">
                        <i class="fas fa-cog text-muted"></i>
                    </a>
                </div>

                <!-- 3-State Duty Switch (Section 2) -->
                <div class="duty-switch-container">
                    <button type="button" class="duty-switch-btn offline <?php echo $rider['duty_status'] === 'offline' ? 'active' : ''; ?> <?php echo $active_delivery ? 'opacity-50' : ''; ?>" <?php echo $active_delivery ? 'title="Cannot switch offline while active delivery is in progress"' : ''; ?> onclick="setDutyStatus('offline')">
                        <i class="fas fa-power-off"></i> Offline
                    </button>
                    <button type="button" class="duty-switch-btn online <?php echo $rider['duty_status'] === 'online' ? 'active' : ''; ?>" onclick="setDutyStatus('online')">
                        <i class="fas fa-satellite-dish"></i> Online
                    </button>
                    <button type="button" class="duty-switch-btn busy <?php echo $rider['duty_status'] === 'busy' ? 'active' : ''; ?>" onclick="setDutyStatus('busy')">
                        <i class="fas fa-biking"></i> Busy
                    </button>
                </div>

                <!-- Today Metrics Grid -->
                <div class="row g-2 text-center pt-2 border-top">
                    <div class="col-4">
                        <span class="text-muted d-block" style="font-size: 10.5px; font-weight: 700; text-transform: uppercase;">Today's Earn</span>
                        <strong style="color: #027a48; font-size: 1.1rem;">₱<?php echo number_format($today_earnings, 2); ?></strong>
                    </div>
                    <div class="col-4" style="border-left: 1px solid var(--border-neutral); border-right: 1px solid var(--border-neutral);">
                        <span class="text-muted d-block" style="font-size: 10.5px; font-weight: 700; text-transform: uppercase;">Completed</span>
                        <strong class="text-dark" style="font-size: 1.1rem;"><?php echo $today_deliveries; ?> orders</strong>
                    </div>
                    <div class="col-4">
                        <span class="text-muted d-block" style="font-size: 10.5px; font-weight: 700; text-transform: uppercase;">Vehicle</span>
                        <strong class="text-truncate d-block" style="font-size: 0.85rem; color: #344054;" title="<?php echo htmlspecialchars($rider['vehicle_type']); ?>">
                            <?php echo htmlspecialchars($rider['vehicle_type'] ?? 'Motorcycle'); ?>
                        </strong>
                    </div>
                </div>
            </div>

            <!-- Active Delivery Banner if in progress -->
            <?php if ($active_delivery): ?>
                <div class="rider-card mb-3" style="background: rgba(255, 248, 248, 0.88); border-color: rgba(254, 228, 226, 0.8);">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge" style="background:#b3261e; color:#ffffff; font-weight:700; font-size:11px;">
                            <i class="fas fa-bolt me-1"></i> ACTIVE MISSION IN PROGRESS
                        </span>
                        <span class="fw-bold" style="color: var(--primary-red); font-size: 13px;">
                            #<?php echo htmlspecialchars($active_delivery['order_number']); ?>
                        </span>
                    </div>
                    <div class="small text-muted mb-1"><i class="fas fa-user text-danger me-1"></i> <?php echo htmlspecialchars($active_delivery['customer_name'] ?? 'Customer'); ?></div>
                    <div class="small fw-semibold text-dark text-truncate mb-3"><i class="fas fa-map-marker-alt text-danger me-1"></i> <?php echo htmlspecialchars($active_delivery['delivery_address']); ?></div>
                    <a href="active_delivery.php" class="btn-rider-primary" style="padding: 10px 16px; font-size: 13.5px;">
                        <i class="fas fa-arrow-right me-1"></i> Return to Live Delivery HUD
                    </a>
                </div>
            <?php endif; ?>

            <!-- Desktop Shortcuts (hidden on mobile, visible on >= 992px) -->
            <div class="rider-card mb-3 d-none d-lg-block">
                <h6 class="fw-bold mb-2 text-muted text-uppercase" style="font-size: 11px;">Quick Actions</h6>
                <div class="d-grid gap-2">
                    <a href="wallet.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-between p-2" style="border-radius: 10px; font-size: 12.5px;">
                        <span><i class="fas fa-money-bill-wave text-success me-2"></i> Remit COD Cash</span>
                        <i class="fas fa-chevron-right text-muted small"></i>
                    </a>
                    <a href="earnings.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-between p-2" style="border-radius: 10px; font-size: 12.5px;">
                        <span><i class="fas fa-wallet text-primary me-2"></i> View Earnings Breakdown</span>
                        <i class="fas fa-chevron-right text-muted small"></i>
                    </a>
                    <a href="history.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-between p-2" style="border-radius: 10px; font-size: 12.5px;">
                        <span><i class="fas fa-history text-secondary me-2"></i> Delivery Trip History</span>
                        <i class="fas fa-chevron-right text-muted small"></i>
                    </a>
                    <a href="support.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-between p-2" style="border-radius: 10px; font-size: 12.5px;">
                        <span><i class="fas fa-headset text-danger me-2"></i> Report Incident / Support</span>
                        <i class="fas fa-chevron-right text-muted small"></i>
                    </a>
                </div>
            </div>

            <!-- Customer Rating & Reviews Card -->
            <div class="rider-card mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-bold mb-0 text-muted text-uppercase" style="font-size: 11px;">
                        <i class="fas fa-star text-warning me-1"></i> Customer Ratings
                    </h6>
                    <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;">
                        ★ <?php echo number_format((float)($rider['rating'] ?? 5.0), 1); ?> / 5.0
                    </span>
                </div>

                <?php if (!empty($rider_reviews)): ?>
                    <div class="d-flex flex-column gap-2 mt-2">
                        <?php foreach ($rider_reviews as $r_rev): ?>
                            <div class="p-2 rounded" style="background: #f8fafc; border: 1px solid var(--border-neutral); font-size: 11.5px;">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="fw-bold" style="color: var(--primary-ink);">
                                        #<?php echo htmlspecialchars($r_rev['order_number'] ?? ''); ?>
                                    </span>
                                    <span class="text-warning">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="<?php echo $s <= (int)$r_rev['rating'] ? 'fas' : 'far'; ?> fa-star" style="font-size: 10px;"></i>
                                        <?php endfor; ?>
                                    </span>
                                </div>
                                <?php if (!empty($r_rev['comment'])): ?>
                                    <div class="text-muted" style="font-style: italic; line-height: 1.3;">
                                        "<?php echo htmlspecialchars($r_rev['comment']); ?>"
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted" style="font-size: 10.5px;">Customer gave a <?php echo (int)$r_rev['rating']; ?>-star rating.</div>
                                <?php endif; ?>
                                <div class="text-muted mt-1" style="font-size: 9.5px;">
                                    <?php echo date('M d, Y', strtotime($r_rev['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="small text-muted mb-0 mt-1" style="font-size: 11.5px;">
                        No customer reviews yet. Deliver orders on time with great service to receive 5-star ratings!
                    </p>
                <?php endif; ?>
            </div>

            <!-- Quick Action / Status Tips -->
            <div class="rider-card mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: #eff8ff; color: #175cd3; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div style="flex: 1;">
                        <div class="fw-bold" style="font-size: 12.5px;">Auto-Dispatch Active</div>
                        <div class="text-muted" style="font-size: 11px;">Keep status <strong>ONLINE</strong> to receive nearby orders. Incoming delivery requests will trigger a notification chime and 20-second acceptance card.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Available Delivery Jobs Pool + Live GPS Route Radar Map + Recent Trips Feed -->
        <div class="col-12 col-lg-7 col-xl-8">
            <!-- Available Delivery Requests (Open Pool Waiting for Rider) -->
            <div class="rider-card mb-3" id="availableOrdersSection">
                <div class="d-flex align-items-center justify-content-between pb-2 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #fff1f0; color: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                            <i class="fas fa-boxes-stacked"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold" style="font-size: 13.5px; color: var(--primary-ink);">
                                Available Deliveries Nearby
                            </h6>
                            <span class="text-muted" style="font-size: 11px;">
                                Orders ready for pickup. If you missed the acceptance timer, you can still accept here.
                            </span>
                        </div>
                    </div>
                    <div>
                        <span class="badge" id="availableOrdersCountBadge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size: 11px; font-weight: 700;">
                            <span id="availCountVal"><?php echo count($initial_available_orders); ?></span> Available
                        </span>
                    </div>
                </div>

                <!-- Available Orders List Container -->
                <div id="availableOrdersContainer" class="d-flex flex-column gap-2 pt-2">
                    <?php if (!empty($initial_available_orders)): ?>
                        <?php foreach ($initial_available_orders as $ord): 
                            $store_name = $ord['store_name'] ?? 'Lechon Central Branch';
                            $ord_id = (int)$ord['order_id'];
                            $tracking_id = (int)($ord['tracking_id'] ?? 0);
                            $payment_type = (stripos($ord['payment_method'] ?? '', 'cod') !== false || stripos($ord['payment_method'] ?? '', 'cash') !== false) ? 'COD' : 'ONLINE';
                            $base_fee = max(50.00, (float)($ord['delivery_fee'] ?? 50.00));
                            $est_earnings = round($base_fee, 2);
                            $created_time_str = !empty($ord['created_at']) ? date('g:i A', strtotime($ord['created_at'])) : 'Just now';
                        ?>
                            <div class="p-3 rounded-4 border rider-glass-inner" style="box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);" id="availOrderCard_<?php echo $ord_id; ?>">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-weight:700; font-size:11px;">
                                            #<?php echo htmlspecialchars($ord['order_number']); ?>
                                        </span>
                                        <span class="badge" style="background:#f2f4f7; color:#344054; font-size:10px;">
                                            <i class="fas fa-clock me-1 text-muted"></i><?php echo $created_time_str; ?>
                                        </span>
                                        <span class="badge" style="background:<?php echo $payment_type === 'COD' ? '#fffaeb' : '#eff8ff'; ?>; color:<?php echo $payment_type === 'COD' ? '#b54708' : '#175cd3'; ?>; font-size:10px;">
                                            <?php echo $payment_type; ?> ₱<?php echo number_format((float)($ord['total_amount'] ?? 0), 2); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <strong style="color: #027a48; font-size: 1.05rem;">+₱<?php echo number_format($est_earnings, 2); ?></strong>
                                    </div>
                                </div>

                                <div class="row g-2 mb-2" style="font-size: 11.5px;">
                                    <div class="col-12 col-md-6 text-truncate text-muted">
                                        <i class="fas fa-store text-danger me-1"></i>
                                        <strong><?php echo htmlspecialchars($store_name); ?></strong>
                                    </div>
                                    <div class="col-12 col-md-6 text-truncate text-muted">
                                        <i class="fas fa-location-dot text-danger me-1"></i>
                                        <span><?php echo htmlspecialchars($ord['delivery_address'] ?? 'Customer Address'); ?></span>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-end gap-2 pt-2 border-top">
                                    <button type="button" class="btn btn-sm text-white px-3 py-1" onclick="acceptOrderDirectly(<?php echo $ord_id; ?>, <?php echo $tracking_id; ?>, this)" style="background: #b3261e; font-size: 11.5px; border-radius: 8px; font-weight: 700;">
                                        <i class="fas fa-check-circle me-1"></i> Accept Order
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-3 text-muted" id="availOrdersEmptyPlaceholder" style="border: 1px dashed var(--border-neutral); border-radius: 10px; background: #fafafa;">
                            <i class="fas fa-satellite-dish me-1 text-muted opacity-75"></i>
                            <span style="font-size: 11.5px;">No available orders right now for <strong><?php echo htmlspecialchars($rider['store_name'] ?? 'your assigned branch'); ?></strong>. Stay <strong>ONLINE</strong> to receive new delivery orders.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Live Position & Hotspot Map Card -->
            <div class="rider-card p-0 mb-3" style="overflow: hidden;">
                <div class="p-3 d-flex align-items-center justify-content-between border-bottom">
                    <div>
                        <h6 class="mb-0 fw-bold" style="font-size: 13px;"><i class="fas fa-location-arrow text-danger me-1"></i> Live Rider GPS Map</h6>
                        <span class="text-muted" style="font-size: 11px;" id="gpsStatusText">Finding current coordinates...</span>
                    </div>
                    <button type="button" class="rider-glass-pill py-1 px-2.5" onclick="recenterMap()" style="font-size: 11px;">
                        <i class="fas fa-crosshairs text-danger me-1"></i> Re-center
                    </button>
                </div>
                <div id="riderHomeMap" style="height: 380px; width: 100%; z-index: 1;"></div>
            </div>

            <!-- Recent Delivery Trips Table / Feed -->
            <div class="rider-card mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-bold" style="font-size: 13px;">
                        <i class="fas fa-clock-rotate-left text-muted me-1"></i> Recent Completed Trips
                    </h6>
                    <a href="history.php" class="text-danger fw-bold small text-decoration-none">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>

                <?php if (!empty($recent_deliveries)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_deliveries as $item): ?>
                            <div class="list-group-item px-0 py-2 border-bottom d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fw-bold" style="font-size: 12.5px; color: var(--primary-ink);">
                                        #<?php echo htmlspecialchars($item['order_number'] ?? 'Order'); ?>
                                    </div>
                                    <div class="text-muted small text-truncate" style="max-width: 250px; font-size: 11px;">
                                        <?php echo htmlspecialchars($item['customer_name'] ?? ''); ?> • <?php echo htmlspecialchars($item['delivery_address'] ?? ''); ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 10px;">
                                        <?php echo date('M d, g:i A', strtotime($item['earned_at'])); ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-success" style="font-size: 13px;">+₱<?php echo number_format((float)$item['total_earnings'], 2); ?></strong>
                                    <div><span class="badge" style="background:#ecfdf3; color:#027a48; font-size: 9.5px;">Credited</span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-box-open fa-2x mb-2 text-muted opacity-50"></i>
                        <p class="small mb-0">No completed deliveries yet today. Switch to <strong>ONLINE</strong> to receive orders!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DELIVERY REQUEST MODAL / OVERLAY (Section 3)                   -->
<!-- ============================================================== -->
<div class="modal fade" id="deliveryRequestModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered px-3">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 24px; overflow: hidden; background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.9) !important;">
            <!-- Header with Countdown Header -->
            <div class="p-3 text-white text-center position-relative" style="background: #b3261e;">
                <span class="badge rounded-pill bg-white text-danger px-3 py-2 fw-bold" style="font-size: 12px; letter-spacing: 0.05em;">
                    <i class="fas fa-bell me-1 fa-spin"></i> NEW DELIVERY REQUEST
                </span>
                <div class="mt-2 text-white-50 small" id="reqDeliveryType">Platform / Partner Rider</div>
                <div class="position-absolute end-0 top-50 translate-middle-y me-3 text-center">
                    <div style="width: 44px; height: 44px; border: 3px solid #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 800;">
                        <span id="countdownVal">20</span>
                    </div>
                </div>
            </div>

            <div class="modal-body p-3">
                <!-- Earnings & Payment Highlights -->
                <div class="d-flex justify-content-between align-items-center p-3 rounded mb-3" style="background:#f8f9fa; border: 1px solid var(--border-neutral);">
                    <div>
                        <span class="text-muted d-block small" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Estimated Earnings</span>
                        <strong style="color: #027a48; font-size: 1.4rem;" id="reqEarnings">₱0.00</strong>
                    </div>
                    <div class="text-end">
                        <span class="badge" id="reqPaymentBadge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size: 11px; font-weight: 700;">
                            COD ₱0.00
                        </span>
                        <div class="text-muted mt-1" style="font-size: 11px;" id="reqEstTime">~25 mins delivery</div>
                    </div>
                </div>

                <!-- Route: Restaurant Pickup -->
                <div class="p-2 px-3 rounded mb-2" style="background:#ffffff; border: 1px solid var(--border-neutral);">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-dark"><i class="fas fa-store text-danger me-1"></i> Pick-up Restaurant</span>
                        <span class="badge" style="background:#eff8ff; color:#175cd3; font-size: 10px;" id="reqPickupDist">0.0 km away</span>
                    </div>
                    <div class="fw-bold text-dark" style="font-size: 13.5px;" id="reqStoreName">Restaurant</div>
                    <div class="small text-muted text-truncate" id="reqStoreAddr">Address</div>
                </div>

                <!-- Route: Customer Drop-off -->
                <div class="p-2 px-3 rounded mb-3" style="background:#ffffff; border: 1px solid var(--border-neutral);">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-dark"><i class="fas fa-map-marker-alt text-danger me-1"></i> Customer Drop-off</span>
                        <span class="badge" style="background:#eff8ff; color:#175cd3; font-size: 10px;" id="reqDeliveryDist">0.0 km</span>
                    </div>
                    <div class="fw-bold text-dark" style="font-size: 13.5px;" id="reqCustomerName">Customer</div>
                    <div class="small text-muted text-truncate" id="reqCustomerAddr">Drop-off Address</div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary w-50 py-3 fw-bold" onclick="declineCurrentRequest()" style="border-radius: 12px; font-size: 13px;">
                        <i class="fas fa-times me-1"></i> Decline
                    </button>
                    <button type="button" class="btn btn-success w-50 py-3 fw-bold" id="acceptDeliveryBtn" onclick="acceptCurrentRequest()" style="background:#027a48; border-color:#027a48; border-radius: 12px; font-size: 13px;">
                        <i class="fas fa-check-circle me-1"></i> Accept Delivery
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentShiftStatus = <?php echo json_encode($shift_status); ?>;
let riderBreakElapsedSeconds = <?php echo (int)($today_attendance['break_elapsed_seconds'] ?? 0); ?>;

if (currentShiftStatus === 'on_break') {
    setInterval(() => {
        riderBreakElapsedSeconds++;
        const mins = Math.floor(riderBreakElapsedSeconds / 60);
        const timerEl = document.getElementById('riderBreakTimer');
        if (timerEl) timerEl.innerText = mins + ' mins';

        const warnBanner = document.getElementById('riderBreakWarning');
        const warnMins = document.getElementById('riderWarnBreakMins');
        if (warnBanner && warnMins) {
            if (mins >= 45) {
                warnBanner.style.display = 'flex';
                warnMins.innerText = mins;
            }
        }
    }, 1000);
}

function riderTimeClock(action) {
    if (action === 'clock_out') {
        Swal.fire({
            title: 'End Shift / Time Out?',
            text: 'Are you sure you want to clock out? Once timed out, you cannot accept new delivery orders until approved to check in again.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#667085',
            confirmButtonText: 'Yes, Time Out',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                executeTimeClock('clock_out', null, null);
            }
        });
        return;
    }

    if (action === 'break_start') {
        Swal.fire({
            title: 'Start Break Time?',
            text: 'Your shift will pause and order acceptance will be locked. Note: Break time has a 1-hour maximum limit.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f79009',
            cancelButtonColor: '#667085',
            confirmButtonText: 'Start Break',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                executeTimeClock('break_start', null, null);
            }
        });
        return;
    }

    if (action === 'break_end') {
        Swal.fire({
            title: 'Continue Shift?',
            text: 'Resume your shift and re-enable delivery order acceptance?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#027a48',
            cancelButtonColor: '#667085',
            confirmButtonText: 'Continue Shift',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                executeTimeClock('break_end', null, null);
            }
        });
        return;
    }

    if (action === 'reenter') {
        Swal.fire({
            title: 'Check In Again?',
            text: 'You have already timed out today. Checking in again requires approval from your shop owner.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#b3261e',
            cancelButtonColor: '#667085',
            confirmButtonText: 'Request Check-In',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                acquireRiderLocationAndExecute('clock_in');
            }
        });
        return;
    }

    acquireRiderLocationAndExecute('clock_in');
}

function acquireRiderLocationAndExecute(action) {
    Swal.fire({
        title: 'Verifying Location...',
        text: 'Acquiring GPS coordinates to verify store proximity...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    if (!navigator.geolocation) {
        executeTimeClock(action, null, null);
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            executeTimeClock(action, pos.coords.latitude, pos.coords.longitude);
        },
        (err) => {
            console.warn('Geolocation error:', err);
            executeTimeClock(action, null, null);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

function executeTimeClock(action, lat, lon) {
    const fd = new FormData();
    fd.append('action', action);
    if (lat !== null && lat !== undefined) fd.append('latitude', lat);
    if (lon !== null && lon !== undefined) fd.append('longitude', lon);

    fetch('../ajax_time_clock.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            let alertTitle = 'Time In Recorded';
            if (action === 'clock_out') alertTitle = 'Time Out Recorded';
            else if (action === 'break_start') alertTitle = 'Break Started';
            else if (action === 'break_end') alertTitle = 'Shift Resumed';

            Swal.fire({
                icon: (data.is_near_shop || action.includes('break') || action === 'clock_out') ? 'success' : 'info',
                title: alertTitle,
                text: data.message,
                confirmButtonColor: '#b3261e'
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Notice',
                text: data.message || 'Action could not be completed.',
                confirmButtonColor: '#b3261e'
            });
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Failed to communicate with time clock service.', 'error');
    });
}

let homeMap = null;
let riderMarker = null;
let currentRiderLat = <?php echo json_encode($rider['current_latitude'] ? (float)$rider['current_latitude'] : 14.3294); ?>;
let currentRiderLng = <?php echo json_encode($rider['current_longitude'] ? (float)$rider['current_longitude'] : 120.9367); ?>;
let activePollTimer = null;
let activeCountdownTimer = null;
let currentRequestData = null;
let requestModal = null;

// Audio Ping
function playRequestSound() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        osc.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.15); // A5
        gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.4);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.4);
    } catch (e) {
        console.debug('Audio error:', e);
    }
}

function initHomeMap() {
    if (typeof L === 'undefined') return;
    const container = document.getElementById('riderHomeMap');
    if (!container) return;

    homeMap = L.map('riderHomeMap', {
        center: [currentRiderLat, currentRiderLng],
        zoom: 14,
        zoomControl: false
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(homeMap);

    const riderIcon = L.divIcon({
        className: 'custom-pin-rider',
        html: `<div class="rider-custom-pin pin-rider"><i class="fas fa-motorcycle"></i></div>`,
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });

    riderMarker = L.marker([currentRiderLat, currentRiderLng], { icon: riderIcon, draggable: false }).addTo(homeMap);
    riderMarker.bindPopup("<strong>You are here</strong>").openPopup();
}

function recenterMap() {
    if (homeMap && riderMarker) {
        const statEl = document.getElementById('gpsStatusText');
        if (statEl) {
            statEl.innerHTML = `<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i> Acquiring high-accuracy GPS...</span>`;
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const acc = Math.round(pos.coords.accuracy || 0);
                    updateRiderPositionOnMap(lat, lng, acc);
                    if (typeof sendRiderLocationPing === 'function') {
                        sendRiderLocationPing(lat, lng, acc);
                    }
                    if (homeMap) {
                        homeMap.setView([lat, lng], 17, { animate: true });
                    }
                    if (statEl) {
                        const accLabel = acc > 0 ? ` (±${acc}m accuracy)` : '';
                        statEl.innerHTML = `<span class="text-success"><i class="fas fa-crosshairs me-1"></i> Location Locked${accLabel}</span>`;
                    }
                },
                (err) => {
                    console.warn('GPS location lock failed:', err);
                    if (statEl) {
                        statEl.innerHTML = `<span class="text-warning"><i class="fas fa-triangle-exclamation me-1"></i> GPS: ${err.message || 'Signal weak'}</span>`;
                    }
                    if (homeMap && riderMarker) {
                        homeMap.setView(riderMarker.getLatLng(), 15, { animate: true });
                    }
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        } else {
            homeMap.setView(riderMarker.getLatLng(), 15, { animate: true });
        }
    }
}

let hasCenteredHomeMap = false;
let accuracyCircle = null;
function updateRiderPositionOnMap(lat, lng, acc = null) {
    if (!lat || !lng) return;
    currentRiderLat = parseFloat(lat);
    currentRiderLng = parseFloat(lng);
    if (riderMarker) {
        riderMarker.setLatLng([currentRiderLat, currentRiderLng]);
    }
    if (homeMap) {
        if (!hasCenteredHomeMap) {
            hasCenteredHomeMap = true;
            homeMap.setView([currentRiderLat, currentRiderLng], 15);
        } else {
            homeMap.panTo([currentRiderLat, currentRiderLng], { animate: true });
        }
    }
    const statEl = document.getElementById('gpsStatusText');
    if (statEl) {
        if (acc && acc > 0 && homeMap) {
            if (!accuracyCircle) {
                accuracyCircle = L.circle([currentRiderLat, currentRiderLng], {
                    radius: acc,
                    color: '#b3261e',
                    fillColor: '#b3261e',
                    fillOpacity: 0.12,
                    weight: 1
                }).addTo(homeMap);
            } else {
                accuracyCircle.setLatLng([currentRiderLat, currentRiderLng]);
                accuracyCircle.setRadius(acc);
            }
        }
        const accInfo = acc ? ` (±${Math.round(acc)}m)` : '';
        statEl.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i> GPS Active${accInfo} (${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})})</span>`;
    }
}

function setDutyStatus(targetStatus) {
    if (targetStatus === 'online' || targetStatus === 'busy') {
        if (currentShiftStatus === 'not_timed_in') {
            Swal.fire({
                icon: 'warning',
                title: 'Time In Required',
                text: 'Please click "Time In (Verify Store GPS)" above before switching to Online.',
                confirmButtonColor: '#b3261e'
            });
            return;
        }
        if (currentShiftStatus === 'timed_out') {
            Swal.fire({
                icon: 'info',
                title: 'Shift Completed',
                text: 'You have timed out for today. You cannot switch to Online while off duty.',
                confirmButtonColor: '#b3261e'
            });
            return;
        }
    }
    <?php if ($active_delivery): ?>
    if (targetStatus === 'offline') {
        Swal.fire({
            icon: 'warning',
            title: 'Active Mission in Progress',
            text: 'Cannot switch to offline while Order #<?php echo htmlspecialchars($active_delivery['order_number']); ?> is in progress. Please complete or update your current delivery first.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }
    <?php endif; ?>

    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('status', targetStatus);

    fetch('api_rider.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.duty-switch-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = document.querySelector(`.duty-switch-btn.${targetStatus}`);
            if (activeBtn) activeBtn.classList.add('active');

            Swal.fire({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                icon: targetStatus === 'offline' ? 'info' : 'success',
                title: data.message
            });

            if (targetStatus === 'online') {
                broadcastRiderLocation(updateRiderPositionOnMap);
                startRequestPolling();
            } else {
                stopRequestPolling();
            }
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Notice',
                text: data.message || 'Failed to update duty status',
                confirmButtonColor: '#b3261e'
            });
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Network error changing status', 'error');
    });
}

function startRequestPolling() {
    if (activePollTimer) clearInterval(activePollTimer);
    pollForIncomingRequests();
    activePollTimer = setInterval(pollForIncomingRequests, 4000);
}

function stopRequestPolling() {
    if (activePollTimer) {
        clearInterval(activePollTimer);
        activePollTimer = null;
    }
}

let availableOrdersCache = [];

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function pollForIncomingRequests() {
    fetch('api_rider.php?action=poll_requests')
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;

            // 1. Render all available open orders in the pool
            if (Array.isArray(data.available_orders)) {
                availableOrdersCache = data.available_orders;
                renderAvailableOrders(data.available_orders);
            }

            // 2. Only trigger modal popup if modal is not currently open
            if (currentRequestData === null && data.has_request && data.request) {
                showDeliveryRequest(data.request);
            }
        })
        .catch(e => console.debug('Polling error:', e));
}

function renderAvailableOrders(orders) {
    const container = document.getElementById('availableOrdersContainer');
    const countBadge = document.getElementById('availCountVal');
    if (!container) return;

    if (countBadge) {
        countBadge.textContent = orders.length;
    }

    if (orders.length === 0) {
        container.innerHTML = `
            <div class="text-center py-3 text-muted" id="availOrdersEmptyPlaceholder" style="border: 1px dashed var(--border-neutral); border-radius: 10px; background: #fafafa;">
                <i class="fas fa-satellite-dish me-1 text-muted opacity-75"></i>
                <span style="font-size: 11.5px;">No available orders right now. Stay <strong>ONLINE</strong> to receive new delivery orders.</span>
            </div>
        `;
        return;
    }

    let html = '';
    orders.forEach((ord, index) => {
        const isCOD = (ord.payment_method === 'COD');
        const payBadgeBg = isCOD ? '#fffaeb' : '#eff8ff';
        const payBadgeColor = isCOD ? '#b54708' : '#175cd3';
        const storeName = ord.restaurant_name || 'Lechon Central Branch';
        const custAddr = ord.customer_address || 'Customer Delivery Address';

        html += `
            <div class="p-3 rounded-4 border rider-glass-inner" style="box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);" id="availOrderCard_${ord.order_id}">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-weight:700; font-size:11px;">
                            #${escapeHtml(ord.order_number)}
                        </span>
                        <span class="badge" style="background:#f2f4f7; color:#344054; font-size:10px;">
                            <i class="fas fa-clock me-1 text-muted"></i>${escapeHtml(ord.created_time || 'Just now')}
                        </span>
                        <span class="badge" style="background:${payBadgeBg}; color:${payBadgeColor}; font-size:10px;">
                            ${escapeHtml(ord.payment_method)} ₱${Number(ord.order_amount).toFixed(2)}
                        </span>
                        <span class="badge" style="background:#eff8ff; color:#175cd3; font-size:10px;">
                            ${escapeHtml(ord.delivery_type || 'Platform Rider')}
                        </span>
                    </div>
                    <div>
                        <strong style="color: #027a48; font-size: 1.05rem;">+₱${Number(ord.estimated_earnings).toFixed(2)}</strong>
                    </div>
                </div>

                <div class="row g-2 mb-2" style="font-size: 11.5px;">
                    <div class="col-12 col-md-6 text-truncate text-muted">
                        <i class="fas fa-store text-danger me-1"></i>
                        <strong>${escapeHtml(storeName)}</strong>
                        <span class="small text-muted ms-1">(${ord.distance_to_restaurant_km} km)</span>
                    </div>
                    <div class="col-12 col-md-6 text-truncate text-muted">
                        <i class="fas fa-location-dot text-danger me-1"></i>
                        <span>${escapeHtml(custAddr)}</span>
                        <span class="small text-muted ms-1">(${ord.distance_restaurant_to_customer_km} km)</span>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-end gap-2 pt-2 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-1" onclick="openOrderReview(${index})" style="font-size: 11.5px; border-radius: 8px;">
                        <i class="fas fa-eye me-1"></i> Review
                    </button>
                    <button type="button" class="btn btn-sm text-white px-3 py-1" onclick="acceptOrderDirectly(${ord.order_id}, ${ord.tracking_id}, this)" style="background: #b3261e; font-size: 11.5px; border-radius: 8px; font-weight: 700;">
                        <i class="fas fa-check-circle me-1"></i> Accept Order
                    </button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function openOrderReview(orderIndex) {
    if (availableOrdersCache && availableOrdersCache[orderIndex]) {
        showDeliveryRequest(availableOrdersCache[orderIndex]);
    }
}

function acceptOrderDirectly(orderId, trackingId, btn) {
    if (currentShiftStatus === 'not_timed_in') {
        Swal.fire({
            icon: 'warning',
            title: 'Time In Required',
            text: 'You must Time In before you can accept delivery orders.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }
    if (currentShiftStatus === 'timed_out') {
        Swal.fire({
            icon: 'info',
            title: 'Off Duty',
            text: 'You have timed out for today and cannot accept delivery orders.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }
    if (activeCountdownTimer) clearInterval(activeCountdownTimer);
    if (requestModal) requestModal.hide();
    currentRequestData = null;

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Accepting...';
    }

    const formData = new FormData();
    formData.append('action', 'accept_delivery');
    formData.append('order_id', orderId);
    formData.append('tracking_id', trackingId);

    fetch('api_rider.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect || 'active_delivery.php';
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Order Unavailable',
                text: data.message || 'Another rider has already accepted this order.',
                confirmButtonColor: '#b3261e'
            });
            pollForIncomingRequests();
        }
    })
    .catch(e => {
        console.error(e);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Accept Order';
        }
        Swal.fire('Error', 'Network error accepting delivery order.', 'error');
    });
}

function showDeliveryRequest(req) {
    currentRequestData = req;
    playRequestSound();

    document.getElementById('reqDeliveryType').textContent = req.delivery_type;
    document.getElementById('reqEarnings').textContent = '₱' + Number(req.estimated_earnings).toFixed(2);
    document.getElementById('reqPaymentBadge').textContent = `${req.payment_method} ₱${Number(req.order_amount).toFixed(2)}`;
    document.getElementById('reqEstTime').textContent = `~${req.estimated_delivery_time_mins} mins delivery`;
    document.getElementById('reqPickupDist').textContent = `${req.distance_to_restaurant_km} km away`;
    document.getElementById('reqStoreName').textContent = req.restaurant_name;
    document.getElementById('reqStoreAddr').textContent = req.restaurant_address;
    document.getElementById('reqDeliveryDist').textContent = `${req.distance_restaurant_to_customer_km} km`;
    document.getElementById('reqCustomerName').textContent = req.customer_name;
    document.getElementById('reqCustomerAddr').textContent = req.customer_address;

    let timeLeft = req.timeout_seconds || 20;
    const countEl = document.getElementById('countdownVal');
    countEl.textContent = timeLeft;

    if (!requestModal) {
        requestModal = new bootstrap.Modal(document.getElementById('deliveryRequestModal'));
    }
    requestModal.show();

    if (activeCountdownTimer) clearInterval(activeCountdownTimer);
    activeCountdownTimer = setInterval(() => {
        timeLeft--;
        countEl.textContent = timeLeft;
        if (timeLeft <= 0) {
            clearInterval(activeCountdownTimer);
            declineCurrentRequest(true);
        }
    }, 1000);
}

function acceptCurrentRequest() {
    if (currentShiftStatus === 'not_timed_in') {
        Swal.fire({
            icon: 'warning',
            title: 'Time In Required',
            text: 'You must Time In before you can accept delivery orders.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }
    if (currentShiftStatus === 'timed_out') {
        Swal.fire({
            icon: 'info',
            title: 'Off Duty',
            text: 'You have timed out for today and cannot accept delivery orders.',
            confirmButtonColor: '#b3261e'
        });
        return;
    }
    if (!currentRequestData) return;
    if (activeCountdownTimer) clearInterval(activeCountdownTimer);

    const btn = document.getElementById('acceptDeliveryBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Accepting...';

    const formData = new FormData();
    formData.append('action', 'accept_delivery');
    formData.append('order_id', currentRequestData.order_id);
    formData.append('tracking_id', currentRequestData.tracking_id);

    fetch('api_rider.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
            if (requestModal) requestModal.hide();
            window.location.href = data.redirect || 'active_delivery.php';
        } else {
            Swal.fire('Order Unavailable', data.message || 'Unable to accept this delivery.', 'warning');
            if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
            if (requestModal) requestModal.hide();
            currentRequestData = null;
        }
    })
    .catch(e => {
        console.error(e);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Accept Delivery';
    });
}

function declineCurrentRequest(isAutoTimeout = false) {
    if (activeCountdownTimer) clearInterval(activeCountdownTimer);
    if (!currentRequestData) {
        if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
        if (requestModal) requestModal.hide();
        return;
    }

    const orderId = currentRequestData.order_id;
    currentRequestData = null;
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    if (requestModal) requestModal.hide();

    const formData = new FormData();
    formData.append('action', 'decline_delivery');
    formData.append('order_id', orderId);
    formData.append('snooze_seconds', 25);
    fetch('api_rider.php', { method: 'POST', body: formData })
        .then(() => {
            // Immediately refresh list so order remains visible in available pool
            pollForIncomingRequests();
        });

    if (isAutoTimeout) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3500,
            icon: 'info',
            title: 'Order is still available in the list below if you wish to accept it.'
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const reqModalEl = document.getElementById('deliveryRequestModal');
    if (reqModalEl) {
        reqModalEl.addEventListener('hide.bs.modal', () => {
            if (document.activeElement && reqModalEl.contains(document.activeElement)) {
                document.activeElement.blur();
            }
        });
    }

    initHomeMap();
    broadcastRiderLocation(updateRiderPositionOnMap);

    // Initial check of available orders
    pollForIncomingRequests();

    <?php if ($rider['duty_status'] === 'online'): ?>
    startRequestPolling();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
