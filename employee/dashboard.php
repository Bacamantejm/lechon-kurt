<?php
require_once 'session_check.php';
require_once 'header.php';

// Fetch employee name from users table and employee record ID
$stmt = $conn->prepare("SELECT u.full_name, e.id AS employee_id FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

$employee_id = $employee['employee_id'] ?? 0;
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
            <div class="admin-profile">
                <span><?php echo htmlspecialchars($employee['full_name'] ?? 'Team Member'); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="admin-main">
    <div class="dashboard-header d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2>Welcome back, <?php echo htmlspecialchars($employee['full_name'] ?? 'Team Member'); ?>!</h2>
            <p>Quick access to attendance, payslips, leave requests, and work updates.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="attendance.php" class="btn btn-outline-primary btn-sm">Attendance</a>
            <a href="payslips.php" class="btn btn-outline-success btn-sm">Payslips</a>
            <a href="leave_request.php" class="btn btn-outline-warning btn-sm">Request Leave</a>
            <?php if (!empty($is_driver)): ?>
                <a href="logistics.php" class="btn btn-outline-info btn-sm">Deliveries</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="stats-grid">
        <a href="attendance.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #e3f2fd;">
                <i class="fas fa-calendar-check" style="color: #1976d2;"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($attendance_count); ?></h3>
                <p>Attendance Records</p>
            </div>
            <div class="stat-value">View History <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="payslips.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #e8f5e9;">
                <i class="fas fa-file-invoice-dollar" style="color: #388e3c;"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($payslip_count); ?></h3>
                <p>Available Payslips</p>
            </div>
            <div class="stat-value" style="color: #388e3c;">Review <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="leave_request.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #fff3e0;">
                <i class="fas fa-plane-departure" style="color: #f57c00;"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($leave_request_count); ?></h3>
                <p>Total Leave Requests</p>
            </div>
            <div class="stat-value" style="color: #f57c00;">Apply <i class="fas fa-arrow-right"></i></div>
        </a>

        <a href="leave_request.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #fce4ec;">
                <i class="fas fa-hourglass-half" style="color: #c2185b;"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo number_format($pending_leave_count); ?></h3>
                <p>Pending Requests</p>
            </div>
            <div class="stat-value" style="color: #c2185b;">Follow Up <i class="fas fa-arrow-right"></i></div>
        </a>

        <?php if (!empty($is_driver)): ?>
        <a href="logistics.php" class="stat-card fade-in-up">
            <div class="stat-icon" style="background-color: #e0f7fa;">
                <i class="fas fa-truck" style="color: #006064;"></i>
            </div>
            <div class="stat-content">
                <h3>Driver</h3>
                <p>Logistics Access</p>
            </div>
            <div class="stat-value" style="color: #006064;">Open Route <i class="fas fa-arrow-right"></i></div>
        </a>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="recent-section fade-in-up">
                <h3>Recent Leave Requests</h3>
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
                                        <td>" . htmlspecialchars(date('M d, Y', strtotime($row['start_date']))) . "</td>
                                        <td>" . htmlspecialchars(date('M d, Y', strtotime($row['end_date']))) . "</td>
                                        <td><span class='status-badge " . $status_class . "'>" . htmlspecialchars(ucfirst($row['status'])) . "</span></td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>No leave requests found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <a href="leave_request.php" class="view-all-link">Submit a new leave request →</a>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="recent-section fade-in-up">
                <h3>Employee Work Center</h3>
                <div class="d-flex flex-column gap-3">
                    <div class="stat-card" style="padding: 20px;">
                        <div class="stat-content">
                            <h3><?php echo number_format($approved_leave_count); ?></h3>
                            <p>Approved Requests</p>
                        </div>
                        <div class="stat-value" style="color: #388e3c;">Review <i class="fas fa-arrow-right"></i></div>
                    </div>

                    <div class="stat-card" style="padding: 20px; background: #f8fafc;">
                        <div class="stat-content">
                            <h3><?php echo number_format(max(0, $attendance_count - 0)); ?></h3>
                            <p>Attendance Entries</p>
                        </div>
                        <div class="stat-value">Manage <i class="fas fa-arrow-right"></i></div>
                    </div>

                    <div class="stat-card" style="padding: 20px; background: #fff7e6;">
                        <div class="stat-content">
                            <h3><?php echo number_format($payslip_count); ?></h3>
                            <p>Payslips Issued</p>
                        </div>
                        <div class="stat-value" style="color: #388e3c;">Open <i class="fas fa-arrow-right"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
