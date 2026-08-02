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
        <div class="topbar-title">
            <h1>All Notifications</h1>
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
            <div class="admin-profile">
                <span><?php echo htmlspecialchars($employee_user['full_name']); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<div class="admin-main">
    <div class="recent-section">
        <h3>Notification History</h3>
        <div class="list-group">
            <?php
            $notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
            $notif_stmt->bind_param("i", $user_id);
            $notif_stmt->execute();
            $notif_result = $notif_stmt->get_result();

            if ($notif_result->num_rows > 0) {
                while ($row = $notif_result->fetch_assoc()) {
                    $is_read_class = $row['is_read'] ? '' : 'list-group-item-info';
                    $type = $row['type'];
                    $icon = '';
                    $icon_class = 'fas fa-info-circle text-secondary'; // default

                    if (str_contains($type, 'payroll') || str_contains($type, 'payslip')) {
                        if (str_contains($type, 'rejected')) $icon_class = 'fas fa-file-invoice-dollar text-danger';
                        else $icon_class = 'fas fa-file-invoice-dollar text-success';
                    } elseif (str_contains($type, 'attendance')) {
                        if (str_contains($type, 'rejected')) $icon_class = 'fas fa-calendar-times text-danger';
                        elseif (str_contains($type, 'approved')) $icon_class = 'fas fa-calendar-check text-success';
                        else $icon_class = 'fas fa-calendar-alt text-primary';
                    } elseif (str_contains($type, 'leave')) {
                        if (str_contains($type, 'rejected')) $icon_class = 'fas fa-envelope text-danger';
                        elseif (str_contains($type, 'approved')) $icon_class = 'fas fa-envelope-open-text text-success';
                        else $icon_class = 'fas fa-envelope-open-text text-warning';
                    }
                    
                    $icon = "<i class='{$icon_class} fa-fw me-3'></i>";
                    
                    echo "<div class='list-group-item list-group-item-action d-flex justify-content-between align-items-start {$is_read_class}'>";
                    echo "    <div class='d-flex'>";
                    echo "        {$icon}";
                    echo "        <div class='ms-2 me-auto'>";
                    echo "            <div class='fw-bold'>".htmlspecialchars($row['title'])."</div>";
                    echo "            ".htmlspecialchars($row['message']);
                    echo "        </div>";
                    echo "    </div>";
                    echo "    <small class='text-muted'>" . date('M d, Y h:i A', strtotime($row['created_at'])) . "</small>";
                    echo "</div>";
                }
            } else {
                echo "<p class='text-center p-4'>No notifications found.</p>";
            }
            $notif_stmt->close();
            ?>
        </div>
    </div>
</div>

<script>
    // Theme Toggler Logic (same as other pages)
    const themeToggler = document.getElementById('themeToggler');
    const body = document.body;
    const icon = themeToggler.querySelector('i');
    if (localStorage.getItem('theme') === 'dark') { body.classList.add('dark-mode'); icon.className = 'fas fa-sun'; }
    themeToggler.addEventListener('click', () => { body.classList.toggle('dark-mode'); const isDark = body.classList.contains('dark-mode'); localStorage.setItem('theme', isDark ? 'dark' : 'light'); icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon'; });
</script>

<?php
require_once 'footer.php';
?>