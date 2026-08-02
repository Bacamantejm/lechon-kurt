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

// Fetch employee_id based on user_id
$stmt_emp = $conn->prepare("SELECT id FROM employees WHERE user_id = ?");
$stmt_emp->bind_param("i", $user_id);
$stmt_emp->execute();
$result_emp = $stmt_emp->get_result();
$employee_id = ($row_emp = $result_emp->fetch_assoc()) ? $row_emp['id'] : 0;
$stmt_emp->close();
?>

<!-- Top Navigation Bar -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="d-flex align-items-center">
            <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
            <div class="topbar-title">
                <h1>My Attendance</h1>
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
        <div class="d-flex justify-content-between align-items-center mb-3">    
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#attendanceForm" aria-expanded="false" aria-controls="attendanceForm">
                <i class="fas fa-plus me-1"></i> New Request
            </button>
        </div>
        <div class="collapse" id="attendanceForm">
            <div class="card card-body border-0" style="background-color: #f8f9fc;">
                <h5 class="card-title">Submit Manual Attendance Record</h5>
                <form action="process_attendance.php" method="POST" enctype="multipart/form-data">
                    <div class="row g-3">
                        <div class="form-group col-md-4">
                            <label for="attendance_date">Date</label>
                            <input type="date" name="attendance_date" id="attendance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" readonly required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="check_in_time">Time In</label>
                            <input type="time" name="check_in_time" id="check_in_time" class="form-control" min="10:00" max="12:00" required>
                            <small class="text-muted">Allowed: 10:00 AM - 12:00 PM</small>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="check_out_time">Time Out</label>
                            <input type="time" name="check_out_time" id="check_out_time" class="form-control">
                        </div>
                    </div>
                    <input type="hidden" name="latitude" id="attendance_latitude">
                    <input type="hidden" name="longitude" id="attendance_longitude">
                    <p class="small text-muted mt-2 mb-2" id="attendanceLocationStatus">
                        Verifying your location for work-location auto-approval...
                    </p>
                    <div class="form-group">
                        <label for="notes">Reason/Notes</label>
                        <textarea name="notes" id="notes" rows="3" class="form-control" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="proof">Proof/Attachment (Image files only)</label>
                        <input type="file" name="proof" id="proof" class="form-control-file" accept="image/jpeg,image/png,image/gif" required>
                        <small class="form-text text-muted">Required for manual submission. Max size: 5MB.</small>
                    </div>
                    <button type="submit" name="submit_attendance" class="btn btn-success"><i class="fas fa-check-circle mr-2"></i>Submit Request</button>
                </form>
            </div>
        </div>
    </div>

    <div class="recent-section">
        <h3>Attendance History</h3>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Hours Worked</th>
                        <th>Location</th>
                        <th>HR Status</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Corrected query to use `attendance_date` and handle time calculations properly.
                    $att_stmt = $conn->prepare("SELECT attendance_date, check_in_time, check_out_time, status, hr_status, latitude, longitude, ip_address, notes FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC, check_in_time DESC");
                    $att_stmt->bind_param("i", $employee_id);
                    $att_stmt->execute();
                    $att_result = $att_stmt->get_result();

                    if ($att_result->num_rows > 0) {
                        while ($row = $att_result->fetch_assoc()) {
                            $hours_worked = 'N/A';
                            if ($row['check_in_time'] && $row['check_out_time']) {
                                $check_in_dt = new DateTime($row['attendance_date'] . ' ' . $row['check_in_time']);
                                $check_out_dt = new DateTime($row['attendance_date'] . ' ' . $row['check_out_time']);
                                
                                // Handle overnight shifts
                                if ($check_out_dt < $check_in_dt) {
                                    $check_out_dt->modify('+1 day');
                                }
                                $interval = $check_in_dt->diff($check_out_dt);
                                $hours_worked = $interval->format('%h hours %i minutes');
                            }

                            $hr_status = !empty($row['hr_status']) ? ucfirst($row['hr_status']) : 'Pending';
                            $location_cell = "<span class='text-muted'>N/A</span>";
                            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                                $map_link = "https://www.google.com/maps?q=" . urlencode((string)$row['latitude'] . ',' . (string)$row['longitude']);
                                $location_cell = "<a href='" . htmlspecialchars($map_link) . "' target='_blank' title='" . htmlspecialchars((string)($row['ip_address'] ?? '')) . "'>View Map</a>";
                            }
                            $geo_notes_text = (string)($row['notes'] ?? '');
                            if (stripos($geo_notes_text, 'Geo-check passed:') !== false) {
                                $location_cell .= "<div class='small text-success'>Within work location</div>";
                            } elseif (stripos($geo_notes_text, 'Geo-check outside radius:') !== false) {
                                $location_cell .= "<div class='small text-danger'>Outside work location</div>";
                            } elseif (stripos($geo_notes_text, 'Geo-check:') !== false) {
                                $location_cell .= "<div class='small text-warning'>Location review needed</div>";
                            }
                            
                            echo "<tr>
                                <td>" . htmlspecialchars(date('M d, Y', strtotime($row['attendance_date']))) . "</td>
                                <td>" . ($row['check_in_time'] ? date('h:i A', strtotime($row['check_in_time'])) : 'N/A') . "</td>
                                <td>" . ($row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : 'N/A') . "</td>
                                <td>" . $hours_worked . "</td>
                                <td>" . $location_cell . "</td>
                                <td>" . htmlspecialchars($hr_status) . "</td>
                                <td>" . htmlspecialchars(ucfirst($row['status'])) . "</td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' class='text-center'>No attendance records found.</td></tr>";
                    }
                    $att_stmt->close();
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

    const attendanceLatInput = document.getElementById('attendance_latitude');
    const attendanceLngInput = document.getElementById('attendance_longitude');
    const attendanceLocationStatus = document.getElementById('attendanceLocationStatus');

    function setAttendanceLocationStatus(message, cssClass) {
        if (!attendanceLocationStatus) {
            return;
        }
        attendanceLocationStatus.textContent = message;
        attendanceLocationStatus.classList.remove('text-muted', 'text-danger', 'text-success', 'text-warning');
        attendanceLocationStatus.classList.add(cssClass);
    }

    if (attendanceLatInput && attendanceLngInput) {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    attendanceLatInput.value = String(position.coords.latitude);
                    attendanceLngInput.value = String(position.coords.longitude);
                    setAttendanceLocationStatus('Location captured. Attendance inside your work location radius can be auto-approved.', 'text-success');
                },
                function() {
                    setAttendanceLocationStatus('Location unavailable. Submission is still allowed but will require HR review.', 'text-warning');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
            );
        } else {
            setAttendanceLocationStatus('Geolocation is not supported on this device/browser. HR review will be required.', 'text-warning');
        }
    }
</script>

<?php
require_once 'footer.php';
?>
