<?php
require_once 'session_check.php';
require_once 'header.php';

// Fetch employee name from users table for topbar
$stmt_user = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$employee_user = $result_user->fetch_assoc();
$stmt_user->close();
?>

<!-- Top Navigation Bar -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="d-flex align-items-center">
            <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
            <div class="topbar-title">
                <h1>Request Leave</h1>
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
                <span><?php echo htmlspecialchars($employee_user['full_name']); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<div class="admin-main">

    <div class="recent-section">
        <h3>New Leave Request</h3>
        <form action="process_leave_request.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="leave_type">Leave Type</label>
                <select name="leave_type" id="leave_type" class="form-control" required>
                    <option value="">-- Select Leave Type --</option>
                    <?php
                    $types_result = $conn->query("SELECT name FROM leave_types WHERE is_active = 1");
                    while ($type = $types_result->fetch_assoc()) {
                        echo "<option value='" . htmlspecialchars($type['name']) . "'>" . htmlspecialchars($type['name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="row g-3">
                <div class="form-group col-md-6">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                </div>
            </div>
            <div class="form-group">
                <label for="reason">Reason</label>
                <textarea name="reason" id="reason" rows="4" class="form-control" required></textarea>
            </div>
            <div class="form-group">
                <label for="proof">Proof/Attachment (Image files only)</label>
                <input type="file" name="proof" id="proof" class="form-control-file" accept="image/jpeg,image/png,image/gif">
                <small class="form-text text-muted">Optional. Required for sick leaves. Max size: 5MB.</small>
            </div>
            <button type="submit" name="submit_leave" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Request</button>
        </form>
    </div>

    <!-- Recent Leave Requests -->
    <div class="recent-section">
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
                    // Fetch last 5 leave requests
                    $leave_stmt = $conn->prepare("SELECT start_date, end_date, status FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
                    $leave_stmt->bind_param("i", $employee_id);
                    $leave_stmt->execute();
                    $leave_result = $leave_stmt->get_result();
                    if ($leave_result->num_rows > 0) {
                        while ($row = $leave_result->fetch_assoc()) {
                            $status_class = 'badge-info';
                            if ($row['status'] == 'approved') $status_class = 'badge-approved';
                            if ($row['status'] == 'rejected') $status_class = 'badge-rejected';
                            if ($row['status'] == 'pending') $status_class = 'badge-pending';
                            
                            echo "<tr>
                                <td>" . htmlspecialchars(date('M d, Y', strtotime($row['start_date']))) . "</td>
                                <td>" . htmlspecialchars(date('M d, Y', strtotime($row['end_date']))) . "</td>
                                <td><span class='status-badge " . $status_class . "'>" . htmlspecialchars(ucfirst($row['status'])) . "</span></td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='3' class='text-center'>No leave requests found.</td></tr>";
                    }
                    $leave_stmt->close();
                    ?>
                </tbody>
            </table>
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