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

require_once __DIR__ . '/../includes/attendance_service.php';
$today_attendance = AttendanceService::getTodayAttendance($conn, $user_id, $employee_id);
$shift_status = $today_attendance['status']; // 'not_timed_in', 'timed_in', 'timed_out'
$assigned_store = $today_attendance['store'];
$store_display_name = $assigned_store['store_name'] ?? 'Store Branch';
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

    <!-- Attendance Shift Time Clock & Geofencing Card -->
    <div class="card mb-4" style="border-radius: 12px; border: 1px solid <?php echo $shift_status === 'timed_in' ? '#abefc6' : ($shift_status === 'timed_out' ? '#b2ddff' : '#fedf89'); ?>; background: <?php echo $shift_status === 'timed_in' ? '#f6fef9' : ($shift_status === 'timed_out' ? '#f8fbff' : '#fffcf5'); ?>; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 46px; height: 46px; border-radius: 12px; background: <?php echo $shift_status === 'timed_in' ? '#ecfdf3' : ($shift_status === 'timed_out' ? '#eff8ff' : '#fffaeb'); ?>; color: <?php echo $shift_status === 'timed_in' ? '#027a48' : ($shift_status === 'timed_out' ? '#175cd3' : '#b54708'); ?>; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <i class="<?php echo $shift_status === 'timed_in' ? 'fas fa-business-time' : ($shift_status === 'timed_out' ? 'fas fa-check-double' : 'fas fa-clock'); ?>"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h5 class="mb-0 fw-bold" style="color: #101828; font-size: 1.1rem;">
                                <?php if ($shift_status === 'not_timed_in'): ?>
                                    Shift Attendance Required
                                <?php elseif ($shift_status === 'timed_in'): ?>
                                    Active Shift &bull; On Duty
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
                            <?php else: ?>
                                <span class="badge" style="background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; font-size:11px; font-weight:700;">
                                    Timed Out
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mb-0" style="font-size: 13px;">
                            <?php if ($shift_status === 'not_timed_in'): ?>
                                Clock in using store geofencing. Your GPS proximity to <strong><?php echo htmlspecialchars($store_display_name); ?></strong> will automatically verify your attendance.
                            <?php elseif ($shift_status === 'timed_in'): ?>
                                Clocked in at <strong><?php echo htmlspecialchars($today_attendance['check_in_formatted']); ?></strong> &bull; Work Store: <strong><?php echo htmlspecialchars($store_display_name); ?></strong>. Remember to clock out when ending your shift.
                            <?php else: ?>
                                Shift completed! In: <strong><?php echo htmlspecialchars($today_attendance['check_in_formatted'] ?? '-'); ?></strong> &bull; Out: <strong><?php echo htmlspecialchars($today_attendance['check_out_formatted'] ?? '-'); ?></strong>.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if ($shift_status === 'not_timed_in'): ?>
                        <button type="button" class="btn text-white px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('clock_in')" style="background: #b3261e; border: none; border-radius: 10px; font-size: 13.5px; box-shadow: 0 1px 3px rgba(179,38,30,0.3);">
                            <i class="fas fa-sign-in-alt"></i> Time In (Verify Store GPS)
                        </button>
                    <?php elseif ($shift_status === 'timed_in'): ?>
                        <button type="button" class="btn btn-outline-danger px-4 py-2 fw-bold d-inline-flex align-items-center gap-2" onclick="employeeTimeClock('clock_out')" style="border-radius: 10px; font-size: 13.5px;">
                            <i class="fas fa-sign-out-alt"></i> Time Out (End Shift)
                        </button>
                    <?php else: ?>
                        <span class="badge py-2 px-3" style="background: #eaecf0; color: #475467; font-size: 12px; border-radius: 8px;">
                            <i class="fas fa-lock me-1"></i> Shift Closed
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

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

    let currentShiftStatus = <?php echo json_encode($shift_status); ?>;

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

        // action === 'clock_in'
        Swal.fire({
            title: 'Verifying Store Location...',
            text: 'Acquiring GPS coordinates to verify store proximity...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        if (!navigator.geolocation) {
            executeEmployeeTimeClock('clock_in', null, null);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                executeEmployeeTimeClock('clock_in', pos.coords.latitude, pos.coords.longitude);
            },
            (err) => {
                console.warn('Geolocation error:', err);
                executeEmployeeTimeClock('clock_in', null, null);
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
                Swal.fire({
                    icon: data.is_near_shop ? 'success' : 'info',
                    title: action === 'clock_in' ? 'Time In Recorded' : 'Time Out Recorded',
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
