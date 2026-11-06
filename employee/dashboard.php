<?php
require_once 'session_check.php';
require_once 'header.php';

// Fetch employee name, role, department, and employee record
$stmt = $conn->prepare("
    SELECT u.full_name, u.email, u.profile_image, u.user_type,
           r.name AS role_name, r.description AS role_description,
           e.id AS employee_id, e.employee_id AS emp_code, e.position, e.hire_date,
           d.department_name
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN employees e ON e.user_id = u.id 
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

$role_slug = strtolower(trim((string)($employee['role_name'] ?? '')));
$role_title = function_exists('getRoleDisplayName') ? getRoleDisplayName($role_slug) : ucwords(str_replace('_', ' ', $role_slug ?: 'Staff'));
$role_title = preg_replace('/\s*\(.*\)\s*/', '', (string)$role_title);

$employee_id = $employee['employee_id'] ?? 0;
require_once __DIR__ . '/../includes/attendance_service.php';
$today_attendance = AttendanceService::getTodayAttendance($conn, $user_id, $employee_id);
$shift_status = $today_attendance['status']; // 'not_timed_in', 'timed_in', 'timed_out'
$assigned_store = $today_attendance['store'];
$store_display_name = $assigned_store['store_name'] ?? 'Store Branch';
$attendance_count = 0;
$payslip_count = 0;
$pending_leave_count = 0;
$approved_leave_count = 0;
$leave_request_count = 0;
$recent_leave_requests = [];

if ($employee_id) {
    $att_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM attendance WHERE employee_id = ?");
    $att_stmt->bind_param("i", $employee_id);
    $att_stmt->execute();
    $att_row = $att_stmt->get_result()->fetch_assoc();
    $attendance_count = (int)($att_row['total'] ?? 0);
    $att_stmt->close();

    $pay_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payslips WHERE employee_id = ?");
    $pay_stmt->bind_param("i", $employee_id);
    $pay_stmt->execute();
    $pay_row = $pay_stmt->get_result()->fetch_assoc();
    $payslip_count = (int)($pay_row['total'] ?? 0);
    $pay_stmt->close();

    $leave_status_stmt = $conn->prepare(
        "SELECT status, COUNT(*) AS total FROM leave_requests WHERE employee_id = ? GROUP BY status"
    );
    $leave_status_stmt->bind_param("i", $employee_id);
    $leave_status_stmt->execute();
    $leave_status_result = $leave_status_stmt->get_result();
    while ($row = $leave_status_result->fetch_assoc()) {
        $status = strtolower(trim($row['status']));
        $count = (int)($row['total'] ?? 0);
        if ($status === 'pending') {
            $pending_leave_count = $count;
        } elseif ($status === 'approved') {
            $approved_leave_count = $count;
        }
        $leave_request_count += $count;
    }
    $leave_status_stmt->close();

    $recent_stmt = $conn->prepare(
        "SELECT start_date, end_date, status, created_at FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    $recent_stmt->bind_param("i", $employee_id);
    $recent_stmt->execute();
    $recent_leave_requests = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}
?>

<!-- Top Navigation Bar -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="d-flex align-items-center">
            <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
            <div class="topbar-title">
                <h1>Dashboard</h1>
            </div>
        </div>

        <div class="topbar-right d-flex align-items-center">
            <div class="notification-wrapper">
                <button class="theme-toggler" id="notificationBell" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="badge bg-danger" id="notificationCount"></span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <a href="#" id="markAllRead">Mark all as read</a>
                    </div>
                    <div class="notification-list" id="notificationList"><div class="text-center p-3">Loading...</div></div>
                    <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
                </div>
            </div>
            <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>
            <div class="date-display" id="currentDate" style="color: #666; font-size: 0.9rem; margin-right: 15px;"></div>
            <div class="admin-profile d-flex align-items-center gap-2">
                <div class="text-end d-none d-sm-block" style="line-height: 1.2;">
                    <span class="fw-bold d-block text-dark" style="font-size: 13px;"><?php echo htmlspecialchars($employee['full_name'] ?? 'Team Member'); ?></span>
                    <span class="badge" style="background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; font-size: 10px; padding: 2px 7px; border-radius: 10px; font-weight: 600;">
                        <i class="fas fa-shield-alt me-1" style="font-size:8px;"></i>Role: <?php echo htmlspecialchars($role_title); ?>
                    </span>
                </div>
                <div style="width:38px; height:38px; border-radius:50%; background:#b3261e; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:14px; box-shadow: 0 2px 6px rgba(179, 38, 30, 0.25);">
                    <?php echo strtoupper(substr($employee['full_name'] ?? 'E', 0, 1)); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="admin-main">
    <div class="dashboard-header d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <h2 class="mb-0">Welcome back, <?php echo htmlspecialchars($employee['full_name'] ?? 'Team Member'); ?>!</h2>
                <span class="badge" style="background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; font-size: 13px; font-weight: 700; padding: 4px 12px; border-radius: 20px;">
                    <i class="fas fa-user-tag me-1"></i> Role: <?php echo htmlspecialchars($role_title); ?>
                </span>
            </div>
            <p class="text-muted mb-0">
                Active Session &bull; Logged in as <strong><?php echo htmlspecialchars($role_title); ?></strong>
                <?php if (!empty($employee['department_name'])): ?>
                    &bull; Department: <strong><?php echo htmlspecialchars($employee['department_name']); ?></strong>
                <?php endif; ?>
                &bull; Quick access to attendance, payslips, leave requests, and work updates.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="attendance.php" class="quick-chip-link primary"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="payslips.php" class="quick-chip-link success"><i class="fas fa-file-invoice-dollar"></i> Payslips</a>
            <a href="leave_request.php" class="quick-chip-link warning"><i class="fas fa-envelope-open-text"></i> Request Leave</a>
            <?php if (!empty($is_driver)): ?>
                <a href="logistics.php" class="quick-chip-link info"><i class="fas fa-truck"></i> Deliveries</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendance Shift Time Clock & Geofencing Card -->
    <?php
    $card_border = match($shift_status) {
        'timed_in' => '#abefc6',
        'on_break' => '#fedf89',
        'pending_approval' => '#fedf89',
        'timed_out' => '#b2ddff',
        default => '#fedf89'
    };
    $card_bg = match($shift_status) {
        'timed_in' => '#f6fef9',
        'on_break' => '#fffcf5',
        'pending_approval' => '#fffcf5',
        'timed_out' => '#f8fbff',
        default => '#fffcf5'
    };
    $icon_bg = match($shift_status) {
        'timed_in' => '#ecfdf3',
        'on_break' => '#fffaeb',
        'pending_approval' => '#fffaeb',
        'timed_out' => '#eff8ff',
        default => '#fffaeb'
    };
    $icon_color = match($shift_status) {
        'timed_in' => '#027a48',
        'on_break' => '#b54708',
        'pending_approval' => '#b54708',
        'timed_out' => '#175cd3',
        default => '#b54708'
    };
    $icon_class = match($shift_status) {
        'timed_in' => 'fas fa-business-time',
        'on_break' => 'fas fa-coffee',
        'pending_approval' => 'fas fa-hourglass-half',
        'timed_out' => 'fas fa-check-double',
        default => 'fas fa-clock'
    };
    ?>
    <div class="card mb-4" style="border-radius: 12px; border: 1px solid <?php echo $card_border; ?>; background: <?php echo $card_bg; ?>; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div class="d-flex align-items-start align-items-md-center gap-3">
                    <div style="width: 46px; height: 46px; border-radius: 12px; background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="<?php echo $icon_class; ?>"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h5 class="mb-0 fw-bold" style="color: #101828; font-size: 1.1rem;">
                                <?php if ($shift_status === 'not_timed_in'): ?>
                                    Shift Attendance Required
                                <?php elseif ($shift_status === 'timed_in'): ?>
                                    Active Shift &bull; On Duty
                                <?php elseif ($shift_status === 'on_break'): ?>
                                    On Break &bull; Shift Paused
                                <?php elseif ($shift_status === 'pending_approval'): ?>
                                    2nd Check-in &bull; Awaiting Owner Approval
                                <?php else: ?>
                                    Shift Completed for Today
                                <?php endif; ?>
                            </h5>
                            <?php if ($shift_status === 'not_timed_in'): ?>
                                <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;">
                                    Not Timed In
                                </span>
                            <?php elseif ($shift_status === 'timed_in'): ?>
                                <?php if (($today_attendance['hr_status'] ?? '') === 'approved'): ?>
                                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:11px; font-weight:700;">
                                        <i class="fas fa-check-circle me-1"></i> Verified Location
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;">
                                        <i class="fas fa-hourglass-half me-1"></i> Pending HR Review
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($today_attendance['late_minutes'])): ?>
                                    <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px; font-weight:700;">
                                        <i class="fas fa-clock me-1"></i> Late (<?php echo (int)$today_attendance['late_minutes']; ?>m)
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($shift_status === 'on_break'): ?>
                                <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;">
                                    <i class="fas fa-pause-circle me-1"></i> On Break
                                </span>
                            <?php elseif ($shift_status === 'pending_approval'): ?>
                                <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-size:11px; font-weight:700;">
                                    <i class="fas fa-hourglass-half me-1"></i> Approval Pending
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:11px; font-weight:700;">
                                    Timed Out
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mb-0" style="font-size: 13px;">
                            <?php if ($shift_status === 'not_timed_in'): ?>
                                Click Time In to begin your shift. Your proximity to <strong><?php echo htmlspecialchars($store_display_name); ?></strong> will verify your attendance.
                                <?php if (!empty($today_attendance['schedule']['start_formatted'])): ?>
                                    &bull; Shift: <strong><?php echo htmlspecialchars($today_attendance['schedule']['start_formatted']); ?> - <?php echo htmlspecialchars($today_attendance['schedule']['end_formatted']); ?></strong>
                                <?php endif; ?>
                            <?php elseif ($shift_status === 'timed_in'): ?>
                                Clocked in at <strong><?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?></strong> &bull; Work Store: <strong><?php echo htmlspecialchars($store_display_name); ?></strong>. Remember to take your break or time out when ending shift.
                            <?php elseif ($shift_status === 'on_break'): ?>
                                Break started at <strong><?php echo htmlspecialchars($today_attendance['break_start_formatted'] ?? '-'); ?></strong> &bull; Elapsed: <strong id="liveBreakTimer"><?php echo (int)($today_attendance['break_elapsed_minutes'] ?? 0); ?> mins</strong> (Max 1 hour).
                            <?php elseif ($shift_status === 'pending_approval'): ?>
                                Re-entry clocked in at <strong><?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?></strong>. Waiting for your shop owner to approve your 2nd check-in.
                            <?php else: ?>
                                Shift completed! In: <strong><?php echo htmlspecialchars($today_attendance['check_in_formatted'] ?? '-'); ?></strong> &bull; Out: <strong><?php echo htmlspecialchars($today_attendance['check_out_formatted'] ?? '-'); ?></strong>.
                            <?php endif; ?>
                        </p>

                        <?php if ($shift_status === 'on_break'): ?>
                            <div id="breakWarningBanner" class="alert mt-2 mb-0 py-2 px-3 align-items-center gap-2" style="<?php echo (($today_attendance['break_elapsed_minutes'] ?? 0) >= 45) ? 'display:flex;' : 'display:none;'; ?> background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; border-radius: 8px; font-size: 12.5px;">
                                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                                <span><strong>Break Warning:</strong> Break time is nearing the 1-hour maximum limit (<span id="warnBreakMinutes"><?php echo (int)($today_attendance['break_elapsed_minutes'] ?? 0); ?></span> mins elapsed). Please click Continue Shift soon.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
                    <?php if ($shift_status === 'not_timed_in'): ?>
                        <button type="button" class="btn text-white px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('clock_in')" style="background: #b3261e; border: none; border-radius: 10px; font-size: 13.5px; box-shadow: 0 1px 3px rgba(179,38,30,0.3);">
                            <i class="fas fa-sign-in-alt"></i> Time In (Verify Store GPS)
                        </button>
                    <?php elseif ($shift_status === 'timed_in'): ?>
                        <button type="button" class="btn px-3 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('break_start')" style="background: #fffaeb; color: #b54708; border: 1px solid #fedf89; border-radius: 10px; font-size: 13.5px;">
                            <i class="fas fa-coffee"></i> Break Time
                        </button>
                        <button type="button" class="btn btn-outline-danger px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('clock_out')" style="border-radius: 10px; font-size: 13.5px;">
                            <i class="fas fa-sign-out-alt"></i> Time Out (End Shift)
                        </button>
                    <?php elseif ($shift_status === 'on_break'): ?>
                        <button type="button" class="btn text-white px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('break_end')" style="background: #027a48; border: none; border-radius: 10px; font-size: 13.5px; box-shadow: 0 1px 3px rgba(2,122,72,0.3);">
                            <i class="fas fa-play"></i> Continue Shift
                        </button>
                    <?php elseif ($shift_status === 'pending_approval'): ?>
                        <span class="badge py-2 px-3" style="background: #fffaeb; color: #b54708; border: 1px solid #fedf89; font-size: 12px; border-radius: 8px;">
                            <i class="fas fa-hourglass-half me-1"></i> Awaiting Owner Approval
                        </span>
                    <?php else: ?>
                        <button type="button" class="btn text-white px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('reenter')" style="background: #b3261e; border: none; border-radius: 10px; font-size: 13.5px; box-shadow: 0 1px 3px rgba(179,38,30,0.3);">
                            <i class="fas fa-sign-in-alt"></i> Check In Again
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="stats-grid">
        <a href="attendance.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #fff5f2; color: #b3261e;">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($attendance_count); ?></h3>
                <p>Attendance Records</p>
            </div>
            <div class="stat-value" style="color: #b3261e;">View History <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="payslips.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #f0fdf4; color: #15803d;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($payslip_count); ?></h3>
                <p>Available Payslips</p>
            </div>
            <div class="stat-value" style="color: #15803d;">Review <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="leave_request.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #fff8ef; color: #ef6b2e;">
                <i class="fas fa-plane-departure"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($leave_request_count); ?></h3>
                <p>Total Leave Requests</p>
            </div>
            <div class="stat-value" style="color: #ef6b2e;">Apply <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="leave_request.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #fefce8; color: #854d0e;">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($pending_leave_count); ?></h3>
                <p>Pending Requests</p>
            </div>
            <div class="stat-value" style="color: #854d0e;">Follow Up <i class="fas fa-arrow-right"></i></div>
        </a>

        <?php if (!empty($is_driver)): ?>
        <a href="logistics.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #f0f9ff; color: #0369a1;">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-content">
                <h3>Driver</h3>
                <p>Logistics Access</p>
            </div>
            <div class="stat-value" style="color: #0369a1;">Open Route <i class="fas fa-arrow-right"></i></div>
        </a>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="recent-section fade-in-up">
                <h3><i class="fas fa-clock-rotate-left" style="color: #ef6b2e;"></i> Recent Leave Requests</h3>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!empty($recent_leave_requests)) {
                                foreach ($recent_leave_requests as $row) {
                                    $status_class = 'badge-info';
                                    if (strtolower($row['status']) === 'approved') $status_class = 'badge-approved';
                                    if (strtolower($row['status']) === 'rejected') $status_class = 'badge-rejected';
                                    if (strtolower($row['status']) === 'pending') $status_class = 'badge-pending';

                                    echo "<tr>
                                        <td><i class='far fa-calendar' style='color:#7b6d64; margin-right:6px;'></i>" . htmlspecialchars(date('M d, Y', strtotime($row['start_date']))) . "</td>
                                        <td><i class='far fa-calendar' style='color:#7b6d64; margin-right:6px;'></i>" . htmlspecialchars(date('M d, Y', strtotime($row['end_date']))) . "</td>
                                        <td><span class='status-badge " . $status_class . "'>" . htmlspecialchars(ucfirst($row['status'])) . "</span></td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center py-4 text-muted'>No leave requests found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px;">
                    <a href="leave_request.php" style="color: #b3261e; font-weight: 700; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px;">
                        Submit a new leave request <i class="fas fa-arrow-right" style="font-size: 0.8rem;"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="recent-section fade-in-up">
                <h3><i class="fas fa-user-gear" style="color: #b3261e;"></i> Employee Work Center</h3>
                <div class="d-flex flex-column gap-3">
                    <a href="leave_request.php" class="stat-card" style="padding: 18px; border: 1px solid #efddcd; background: #fffdfb;">
                        <div class="stat-content">
                            <h3 style="font-size: 1.5rem; color: #15803d;"><?php echo number_format($approved_leave_count); ?></h3>
                            <p style="font-size: 0.84rem;">Approved Requests</p>
                        </div>
                        <div class="stat-value" style="color: #15803d; font-size: 0.82rem; margin-top: 10px;">Review <i class="fas fa-arrow-right"></i></div>
                    </a>

                    <a href="attendance.php" class="stat-card" style="padding: 18px; border: 1px solid #efddcd; background: #fffdfb;">
                        <div class="stat-content">
                            <h3 style="font-size: 1.5rem; color: #b3261e;"><?php echo number_format(max(0, $attendance_count - 0)); ?></h3>
                            <p style="font-size: 0.84rem;">Attendance Entries</p>
                        </div>
                        <div class="stat-value" style="color: #b3261e; font-size: 0.82rem; margin-top: 10px;">Manage <i class="fas fa-arrow-right"></i></div>
                    </a>

                    <a href="payslips.php" class="stat-card" style="padding: 18px; border: 1px solid #efddcd; background: #fffdfb;">
                        <div class="stat-content">
                            <h3 style="font-size: 1.5rem; color: #854d0e;"><?php echo number_format($payslip_count); ?></h3>
                            <p style="font-size: 0.84rem;">Payslips Issued</p>
                        </div>
                        <div class="stat-value" style="color: #854d0e; font-size: 0.82rem; margin-top: 10px;">Open <i class="fas fa-arrow-right"></i></div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.quick-chip-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 0.84rem;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.25s ease;
    border: 1px solid transparent;
}

.quick-chip-link.primary {
    background: #fff5f2;
    color: #b3261e;
    border-color: #fecdd3;
}
.quick-chip-link.primary:hover {
    background: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
}

.quick-chip-link.success {
    background: #f0fdf4;
    color: #15803d;
    border-color: #bbf7d0;
}
.quick-chip-link.success:hover {
    background: #15803d;
    color: #ffffff;
    border-color: #15803d;
}

.quick-chip-link.warning {
    background: #fff8ef;
    color: #ef6b2e;
    border-color: #efddcd;
}
.quick-chip-link.warning:hover {
    background: #ef6b2e;
    color: #ffffff;
    border-color: #ef6b2e;
}

.quick-chip-link.info {
    background: #f0f9ff;
    color: #0369a1;
    border-color: #bae6fd;
}
.quick-chip-link.info:hover {
    background: #0369a1;
    color: #ffffff;
    border-color: #0369a1;
}
</style>

<script>
    let currentShiftStatus = <?php echo json_encode($shift_status); ?>;

    let currentShiftStatus = <?php echo json_encode($shift_status); ?>;
    let breakElapsedSeconds = <?php echo (int)($today_attendance['break_elapsed_seconds'] ?? 0); ?>;

    if (currentShiftStatus === 'on_break') {
        setInterval(() => {
            breakElapsedSeconds++;
            const mins = Math.floor(breakElapsedSeconds / 60);
            const liveTimer = document.getElementById('liveBreakTimer');
            if (liveTimer) liveTimer.innerText = mins + ' mins';
            
            const warnBanner = document.getElementById('breakWarningBanner');
            const warnMinutes = document.getElementById('warnBreakMinutes');
            if (warnBanner && warnMinutes) {
                if (mins >= 45) {
                    warnBanner.style.display = 'flex';
                    warnMinutes.innerText = mins;
                }
            }
        }, 1000);
    }

    function employeeTimeClock(action) {
        if (action === 'clock_out') {
            Swal.fire({
                title: 'End Shift / Time Out?',
                text: 'Are you sure you want to clock out for today?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#b3261e',
                cancelButtonColor: '#667085',
                confirmButtonText: 'Yes, Time Out',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    executeEmployeeTimeClock('clock_out', null, null);
                }
            });
            return;
        }

        if (action === 'break_start') {
            Swal.fire({
                title: 'Start Break Time?',
                text: 'Your shift will be paused. Note: Break time has a maximum limit of 1 hour.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f79009',
                cancelButtonColor: '#667085',
                confirmButtonText: 'Start Break',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    executeEmployeeTimeClock('break_start', null, null);
                }
            });
            return;
        }

        if (action === 'break_end') {
            Swal.fire({
                title: 'Continue Shift?',
                text: 'Ready to finish your break and resume work?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#027a48',
                cancelButtonColor: '#667085',
                confirmButtonText: 'Continue Shift',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    executeEmployeeTimeClock('break_end', null, null);
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
                    acquireLocationAndExecute('clock_in');
                }
            });
            return;
        }

        acquireLocationAndExecute('clock_in');
    }

    function acquireLocationAndExecute(action) {
        Swal.fire({
            title: 'Verifying Store Location...',
            text: 'Acquiring GPS coordinates to verify store proximity...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        if (!navigator.geolocation) {
            executeEmployeeTimeClock(action, null, null);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                executeEmployeeTimeClock(action, pos.coords.latitude, pos.coords.longitude);
            },
            (err) => {
                console.warn('Geolocation error:', err);
                executeEmployeeTimeClock(action, null, null);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    function executeEmployeeTimeClock(action, lat, lon) {
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

    // Theme Toggler Logic
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
</script>

<?php
require_once 'footer.php';
?>
