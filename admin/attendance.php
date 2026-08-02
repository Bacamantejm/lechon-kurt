<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
$attendance_geofence_radius_meters = 250.0;

if (!function_exists('attendanceResolveWorkStoreLocation')) {
    function attendanceResolveWorkStoreLocation($conn, $employee_user_id) {
        $employee_user_id = intval($employee_user_id);
        if (!$conn || $employee_user_id <= 0) {
            return null;
        }
        if (!function_exists('adminAuthTableExists') || !adminAuthTableExists($conn, 'store_locations')) {
            return null;
        }
        if (function_exists('adminAuthColumnExists')) {
            $required_cols = ['store_id', 'store_name', 'email', 'latitude', 'longitude', 'is_active'];
            foreach ($required_cols as $required_col) {
                if (!adminAuthColumnExists($conn, 'store_locations', $required_col)) {
                    return null;
                }
            }
        }

        $owner_user_id = 0;
        if (function_exists('getFranchiseSellerScopeOwnerId')) {
            $owner_user_id = (int)(getFranchiseSellerScopeOwnerId($conn, $employee_user_id) ?? 0);
        }
        $lookup_user_id = $owner_user_id > 0 ? $owner_user_id : $employee_user_id;

        $user_stmt = mysqli_prepare($conn, "SELECT id, email, business_name FROM users WHERE id = ? LIMIT 1");
        if (!$user_stmt) {
            return null;
        }
        mysqli_stmt_bind_param($user_stmt, "i", $lookup_user_id);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        $user_row = $user_result ? mysqli_fetch_assoc($user_result) : null;
        mysqli_stmt_close($user_stmt);

        if (!$user_row) {
            return null;
        }

        $email = trim((string)($user_row['email'] ?? ''));
        $fallback_franchise_email = $owner_user_id > 0 ? ('franchise+' . $owner_user_id . '@lechondelights.local') : '';
        $business_name = trim((string)($user_row['business_name'] ?? ''));

        $store_query = "SELECT store_id, store_name, latitude, longitude
                        FROM store_locations
                        WHERE is_active = 1
                          AND latitude IS NOT NULL
                          AND longitude IS NOT NULL
                          AND (
                              LOWER(email) = LOWER(?)
                              OR LOWER(email) = LOWER(?)
                              OR LOWER(store_name) = LOWER(?)
                          )
                        ORDER BY
                            CASE
                                WHEN LOWER(email) = LOWER(?) THEN 1
                                WHEN LOWER(email) = LOWER(?) THEN 2
                                WHEN LOWER(store_name) = LOWER(?) THEN 3
                                ELSE 4
                            END,
                            store_id DESC
                        LIMIT 1";
        $store_stmt = mysqli_prepare($conn, $store_query);
        if ($store_stmt) {
            mysqli_stmt_bind_param(
                $store_stmt,
                "ssssss",
                $email,
                $fallback_franchise_email,
                $business_name,
                $email,
                $fallback_franchise_email,
                $business_name
            );
            mysqli_stmt_execute($store_stmt);
            $store_result = mysqli_stmt_get_result($store_stmt);
            $store_row = $store_result ? mysqli_fetch_assoc($store_result) : null;
            mysqli_stmt_close($store_stmt);
            if ($store_row) {
                return [
                    'store_id' => (int)$store_row['store_id'],
                    'store_name' => (string)$store_row['store_name'],
                    'latitude' => (float)$store_row['latitude'],
                    'longitude' => (float)$store_row['longitude']
                ];
            }
        }

        // For non-partner staff, fall back to primary active store coordinates.
        if ($owner_user_id <= 0) {
            $fallback_stmt = mysqli_prepare(
                $conn,
                "SELECT store_id, store_name, latitude, longitude
                 FROM store_locations
                 WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
                 ORDER BY store_id ASC
                 LIMIT 1"
            );
            if ($fallback_stmt) {
                mysqli_stmt_execute($fallback_stmt);
                $fallback_result = mysqli_stmt_get_result($fallback_stmt);
                $fallback_row = $fallback_result ? mysqli_fetch_assoc($fallback_result) : null;
                mysqli_stmt_close($fallback_stmt);
                if ($fallback_row) {
                    return [
                        'store_id' => (int)$fallback_row['store_id'],
                        'store_name' => (string)$fallback_row['store_name'],
                        'latitude' => (float)$fallback_row['latitude'],
                        'longitude' => (float)$fallback_row['longitude']
                    ];
                }
            }
        }

        return null;
    }
}

if (!function_exists('attendanceDistanceMeters')) {
    function attendanceDistanceMeters($lat1, $lon1, $lat2, $lon2) {
        $earth_radius_m = 6371000.0;
        $d_lat = deg2rad($lat2 - $lat1);
        $d_lon = deg2rad($lon2 - $lon1);
        $a = sin($d_lat / 2) * sin($d_lat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lon / 2) * sin($d_lon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius_m * $c;
    }
}

if (!function_exists('attendanceEvaluateGeofenceDecision')) {
    function attendanceEvaluateGeofenceDecision($conn, $employee_user_id, $latitude, $longitude, $radius_meters = 250.0) {
        $has_valid_coords = is_numeric($latitude) && is_numeric($longitude);
        if (!$has_valid_coords) {
            return [
                'hr_status' => 'pending',
                'geo_note' => 'Geo-check: device location unavailable. Pending HR review.'
            ];
        }

        $store = attendanceResolveWorkStoreLocation($conn, $employee_user_id);
        if (!$store) {
            return [
                'hr_status' => 'pending',
                'geo_note' => 'Geo-check: work location coordinates are not configured. Pending HR review.'
            ];
        }

        $distance_m = attendanceDistanceMeters(
            (float)$latitude,
            (float)$longitude,
            (float)$store['latitude'],
            (float)$store['longitude']
        );
        $distance_label = number_format($distance_m, 1);
        $radius_label = number_format((float)$radius_meters, 0);

        if ($distance_m <= $radius_meters) {
            return [
                'hr_status' => 'approved',
                'geo_note' => 'Geo-check passed: ' . $distance_label . 'm from ' . $store['store_name'] . ' (radius ' . $radius_label . 'm).'
            ];
        }

        return [
            'hr_status' => 'pending',
            'geo_note' => 'Geo-check outside radius: ' . $distance_label . 'm from ' . $store['store_name'] . ' (radius ' . $radius_label . 'm). Pending HR review.'
        ];
    }
}

// Handle HR Approval/Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hr_status'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $hr_status = $_POST['hr_status'];
    if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'attendance', $attendance_id, 'id', 'employee_id')) {
        $_SESSION['error'] = "Selected attendance record is outside your partner HR scope.";
        header("Location: attendance.php");
        exit();
    }
    
    if ($hr_status === 'rejected') {
        $query = "UPDATE attendance SET hr_status = ?, status = 'absent' WHERE id = ?";
    } else if ($hr_status === 'approved') {
        $query = "UPDATE attendance SET hr_status = ?, status = 'present' WHERE id = ?";
    } else {
        $query = "UPDATE attendance SET hr_status = ? WHERE id = ?";
    }


    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "si", $hr_status, $attendance_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['success'] = "Attendance marked as " . ucfirst($hr_status) . ($hr_status === 'rejected' ? " and set to Absent" : "");

        // --- ADD NOTIFICATION LOGIC ---
        // Get employee's user_id
        $get_user_id_query = "SELECT e.user_id, a.attendance_date FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE a.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
        $user_stmt = mysqli_prepare($conn, $get_user_id_query);
        mysqli_stmt_bind_param($user_stmt, "i", $attendance_id);
        mysqli_stmt_execute($user_stmt);
        $user_res = mysqli_stmt_get_result($user_stmt);
        if($user_row = mysqli_fetch_assoc($user_res)) {
            $employee_user_id = $user_row['user_id'];
            $att_date = date('M d, Y', strtotime($user_row['attendance_date']));
            if ($employee_user_id) {
                $title = "Attendance Request Update";
                $message = "Your manual attendance request for $att_date has been " . ucfirst($hr_status) . ".";
                $type = "attendance_" . $hr_status;
                $related_id = $attendance_id;
                $related_type = "attendance";

                $notif_query = "INSERT INTO notifications (user_id, type, title, message, related_id, related_type) VALUES (?, ?, ?, ?, ?, ?)";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "isssis", $employee_user_id, $type, $title, $message, $related_id, $related_type);
                mysqli_stmt_execute($notif_stmt);
            }
        }
        // --- END NOTIFICATION LOGIC ---
    } else {
        $_SESSION['error'] = "Error updating status: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}

// Handle Time Clock Actions (Kiosk Mode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $emp_id_input = trim($_POST['employee_id_input']);
    $lat = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : null;
    $long = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : null;
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Find employee
    $emp_query = "SELECT id, first_name, last_name, user_id FROM employees WHERE employee_id = ? AND status = 'active'";
    if ($is_partner_scoped_hr) {
        $emp_query = "SELECT e.id, e.first_name, e.last_name, e.user_id FROM employees e WHERE e.employee_id = ? AND e.status = 'active' AND {$employee_scope_sql}";
    }
    $stmt = mysqli_prepare($conn, $emp_query);
    mysqli_stmt_bind_param($stmt, "s", $emp_id_input);
    mysqli_stmt_execute($stmt);
    $emp_result = mysqli_stmt_get_result($stmt);
    $employee = mysqli_fetch_assoc($emp_result);
    
    if ($employee) {
        $eid = $employee['id'];
        $today = date('Y-m-d');
        $now = date('H:i:s');
        
        // Get Schedule for Late/OT calculation
        $sched_query = "SELECT start_time, end_time FROM schedules WHERE employee_id = ? AND schedule_date = ?";
        $sched_stmt = mysqli_prepare($conn, $sched_query);
        mysqli_stmt_bind_param($sched_stmt, "is", $eid, $today);
        mysqli_stmt_execute($sched_stmt);
        $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($sched_stmt));
        
        // Check existing attendance
        $att_query = "SELECT id, check_in_time, break_start, break_end, check_out_time FROM attendance WHERE employee_id = ? AND attendance_date = ?";
        $att_stmt = mysqli_prepare($conn, $att_query);
        mysqli_stmt_bind_param($att_stmt, "is", $eid, $today);
        mysqli_stmt_execute($att_stmt);
        $attendance = mysqli_fetch_assoc(mysqli_stmt_get_result($att_stmt));
        
        if ($action === 'clock_in') {
            if (!$attendance) {
                // Calculate Late
                $late_mins = 0;
                // Use schedule if exists, otherwise default to 10:00 AM
                $work_start_time = ($schedule) ? $schedule['start_time'] : '10:00:00';
                $sched_start = strtotime($today . ' ' . $work_start_time);
                $actual_start = strtotime($today . ' ' . $now);
                $grace_period = 15 * 60; // 15 mins grace
                if ($actual_start > ($sched_start + $grace_period)) {
                    $late_mins = round(($actual_start - $sched_start) / 60);
                }

                $geo_decision = attendanceEvaluateGeofenceDecision(
                    $conn,
                    intval($employee['user_id'] ?? 0),
                    $lat,
                    $long,
                    $attendance_geofence_radius_meters
                );
                $auto_hr_status = $geo_decision['hr_status'] ?? 'pending';
                $geo_note = trim((string)($geo_decision['geo_note'] ?? ''));
                
                $ins = "INSERT INTO attendance (employee_id, attendance_date, check_in_time, status, latitude, longitude, ip_address, late_minutes, hr_status, notes) VALUES (?, ?, ?, 'present', ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $ins);
                mysqli_stmt_bind_param($stmt, "issddsiss", $eid, $today, $now, $lat, $long, $ip, $late_mins, $auto_hr_status, $geo_note);
                if(mysqli_stmt_execute($stmt)) {
                    if ($auto_hr_status === 'approved') {
                        $_SESSION['success'] = "Clocked IN: {$employee['first_name']} (location verified and auto-approved).";
                    } else {
                        $_SESSION['success'] = "Clocked IN: {$employee['first_name']} (location check logged; pending HR review).";
                    }
                }
            } else {
                $_SESSION['error'] = "Already clocked in today.";
            }
        } elseif ($action === 'break_start') {
            if ($attendance && !$attendance['break_start']) {
                mysqli_query($conn, "UPDATE attendance SET break_start = '$now' WHERE id = {$attendance['id']}");
                $_SESSION['success'] = "Break Started: {$employee['first_name']}";
            }
        } elseif ($action === 'break_end') {
            if ($attendance && $attendance['break_start'] && !$attendance['break_end']) {
                mysqli_query($conn, "UPDATE attendance SET break_end = '$now' WHERE id = {$attendance['id']}");
                $_SESSION['success'] = "Break Ended: {$employee['first_name']}";
            }
        } elseif ($action === 'clock_out') {
            if ($attendance && !$attendance['check_out_time']) {
                // Calculate OT
                $ot_hours = 0;
                // Use schedule if exists, otherwise default to 7:00 PM (19:00)
                $work_end_time = ($schedule) ? $schedule['end_time'] : '19:00:00';
                $sched_end = strtotime($today . ' ' . $work_end_time);
                $actual_end = strtotime($today . ' ' . $now);
                if ($actual_end > $sched_end) {
                    $ot_hours = round(($actual_end - $sched_end) / 3600, 2);
                }
                
                $upd = "UPDATE attendance SET check_out_time = ?, overtime_hours = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $upd);
                mysqli_stmt_bind_param($stmt, "sdi", $now, $ot_hours, $attendance['id']);
                if(mysqli_stmt_execute($stmt)) $_SESSION['success'] = "Clocked OUT: {$employee['first_name']}";
            }
        }
    } else {
        $_SESSION['error'] = "Employee ID not found.";
    }
}

// Handle Manual Attendance Addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attendance'])) {
    $employee_id = intval($_POST['employee_id']);
    $date = $_POST['attendance_date'];
    $status = $_POST['status'];
    $time_in = !empty($_POST['time_in']) ? $_POST['time_in'] : null;
    $time_out = !empty($_POST['time_out']) ? $_POST['time_out'] : null;
    $notes = trim($_POST['notes']);

    if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
        $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
    } else {
        // Check if record exists
        $check_query = "SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "is", $employee_id, $date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {
            $_SESSION['error'] = "Attendance record already exists for this employee on this date.";
        } else {
            $query = "INSERT INTO attendance (employee_id, attendance_date, check_in_time, check_out_time, status, notes) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "isssss", $employee_id, $date, $time_in, $time_out, $status, $notes);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Attendance record added successfully.";
            } else {
                $_SESSION['error'] = "Error adding attendance: " . mysqli_error($conn);
            }
        }
    }
}

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$dept_filter = isset($_GET['department']) ? intval($_GET['department']) : 0;
$employee_filter = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

// Fetch employees for dropdown
$employees_list = [];
$employees_sql = "SELECT e.id, e.first_name, e.last_name, e.employee_id FROM employees e WHERE e.status = 'active'";
if ($is_partner_scoped_hr) {
    $employees_sql .= " AND {$employee_scope_sql}";
}
$employees_sql .= " ORDER BY e.first_name";
$emp_res = mysqli_query($conn, $employees_sql);
if ($emp_res) {
    while($row = mysqli_fetch_assoc($emp_res)) {
        $employees_list[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - HR Management</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Attendance Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="section-header">
                    <h2>Attendance Management</h2>
                    <div class="d-flex gap-2">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#timeClockModal">
                            <i class="fas fa-clock"></i> Launch Time Clock
                        </button>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                            <i class="fas fa-plus"></i> Add Record
                        </button>
                        <button class="btn btn-outline-primary" onclick="exportTableToCSV('attendance.csv')">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>
                
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <?php
                    $stats_where = "attendance_date BETWEEN '$date_from' AND '$date_to'";
                    if ($is_partner_scoped_hr) {
                        $stats_where .= " AND employee_id IN (SELECT e.id FROM employees e WHERE {$employee_scope_sql})";
                    }
                    if ($dept_filter > 0) {
                        $stats_where .= " AND employee_id IN (SELECT id FROM employees WHERE department_id = {$dept_filter})";
                    }
                    if ($employee_filter > 0) {
                        $stats_where .= " AND employee_id = {$employee_filter}";
                    }
                    $stats_query = "SELECT 
                        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
                        COUNT(CASE WHEN late_minutes > 0 THEN 1 END) as late,
                        COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as on_leave
                        FROM attendance WHERE $stats_where";
                    $stats = mysqli_fetch_assoc(mysqli_query($conn, $stats_query));
                    ?>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5><?php echo $stats['present']; ?></h5>
                                <small>Present</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h5><?php echo $stats['late']; ?></h5>
                                <small>Late Arrivals</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h5><?php echo $stats['absent']; ?></h5>
                                <small>Absent</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5><?php echo $stats['on_leave']; ?></h5>
                                <small>On Leave</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card hr-filter-panel mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Department</label>
                                <select name="department" class="form-select">
                                    <option value="0">All Departments</option>
                                    <?php
                                    $dept_filter_sql = "SELECT * FROM departments d";
                                    if ($is_partner_scoped_hr) {
                                        $dept_filter_sql .= " WHERE {$department_scope_sql}";
                                    }
                                    $depts = mysqli_query($conn, $dept_filter_sql);
                                    while($d = mysqli_fetch_assoc($depts)) {
                                        $sel = ((int)$dept_filter === (int)$d['id']) ? 'selected' : '';
                                        echo "<option value='{$d['id']}' $sel>{$d['department_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Employee</label>
                                <select name="employee_id" class="form-select">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees_list as $emp_opt): ?>
                                        <option value="<?php echo (int)$emp_opt['id']; ?>" <?php echo ((int)$employee_filter === (int)$emp_opt['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp_opt['first_name'] . ' ' . $emp_opt['last_name'] . ' (' . $emp_opt['employee_id'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Filter Records</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Schedule</th>
                                <th>In / Out</th>
                                <th>Break</th>
                                <th>Late/OT</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>HR Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $where = "WHERE a.attendance_date BETWEEN '$date_from' AND '$date_to'";
                            if ($dept_filter > 0) $where .= " AND e.department_id = {$dept_filter}";
                            if ($employee_filter > 0) $where .= " AND e.id = {$employee_filter}";
                            if ($is_partner_scoped_hr) $where .= " AND {$employee_scope_sql}";

                            $attendance = mysqli_query($conn, "
                                SELECT a.*, e.id as employee_db_id, e.employee_id, e.first_name, e.last_name, 
                                s.start_time as sched_start, s.end_time as sched_end
                                FROM attendance a
                                JOIN employees e ON a.employee_id = e.id
                                LEFT JOIN schedules s ON e.id = s.employee_id AND a.attendance_date = s.schedule_date
                                $where
                                ORDER BY a.attendance_date DESC, a.check_in_time DESC
                            ");
                            
                            if (mysqli_num_rows($attendance) > 0) {
                                while ($att = mysqli_fetch_assoc($attendance)) {
                                    $status_class = 'badge-' . str_replace('_', '-', $att['status']);
                                    $check_in = $att['check_in_time'] ? date('H:i', strtotime($att['check_in_time'])) : '-';
                                    $check_out = $att['check_out_time'] ? date('H:i', strtotime($att['check_out_time'])) : '-';
                                    $break = ($att['break_start'] && $att['break_end']) ? 
                                        date('H:i', strtotime($att['break_start'])) . ' - ' . date('H:i', strtotime($att['break_end'])) : '-';
                                    
                                    $sched = ($att['sched_start']) ? 
                                        date('H:i', strtotime($att['sched_start'])) . ' - ' . date('H:i', strtotime($att['sched_end'])) : 'No Sched';

                                    $late_display = $att['late_minutes'] > 0 ? "<span class='text-danger'>{$att['late_minutes']}m Late</span>" : "On Time";
                                    $ot_display = $att['overtime_hours'] > 0 ? "<br><span class='text-success'>+{$att['overtime_hours']}h OT</span>" : "";
                                    
                                    $location_link = ($att['latitude'] && $att['longitude']) ? 
                                        "<a href='https://www.google.com/maps?q={$att['latitude']},{$att['longitude']}' target='_blank' title='{$att['ip_address']}'><i class='fas fa-map-marker-alt text-danger'></i> View</a>" : 
                                        "<span class='text-muted'>-</span>";

                                    $geo_notes_text = (string)($att['notes'] ?? '');
                                    if (stripos($geo_notes_text, 'Geo-check passed:') !== false) {
                                        $location_link .= "<div class='small text-success mt-1'>Within work location</div>";
                                    } elseif (stripos($geo_notes_text, 'Geo-check outside radius:') !== false) {
                                        $location_link .= "<div class='small text-danger mt-1'>Outside work location</div>";
                                    } elseif (stripos($geo_notes_text, 'Geo-check:') !== false) {
                                        $location_link .= "<div class='small text-warning mt-1'>Location review needed</div>";
                                    }
                                    
                                    $hr_status = isset($att['hr_status']) ? $att['hr_status'] : 'pending';
                                    $hr_badge_class = match($hr_status) {
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        default => 'warning text-dark'
                                    };
                                    $hr_status_display = "<span class='badge bg-$hr_badge_class'>" . ucfirst($hr_status) . "</span>";

                                    $actions = "<form method='POST' style='display:inline-block;'>
                                            <input type='hidden' name='attendance_id' value='{$att['id']}'>
                                            <input type='hidden' name='update_hr_status' value='1'>
                                            <button type='submit' name='hr_status' value='approved' class='btn btn-sm btn-success py-0 px-1' title='Approve'><i class='fas fa-check'></i></button>
                                            <button type='submit' name='hr_status' value='rejected' class='btn btn-sm btn-danger py-0 px-1' title='Reject'><i class='fas fa-times'></i></button>
                                        </form>";

                                    echo "
                                    <tr>
                                        <td>" . date('M d', strtotime($att['attendance_date'])) . "</td>
                                        <td>{$att['employee_id']}</td><td><a href='#' data-bs-toggle='modal' data-bs-target='#employeeDetailsModal' onclick='loadEmployeeDetails({$att['employee_db_id']})'>{$att['first_name']} {$att['last_name']}</a></td>
                                        <td><small>$sched</small></td>
                                        <td>
                                            <div class='text-success'><i class='fas fa-sign-in-alt'></i> $check_in</div>
                                            <div class='text-danger'><i class='fas fa-sign-out-alt'></i> $check_out</div>
                                        </td>
                                        <td>$break</td>
                                        <td><small>$late_display $ot_display</small></td>
                                        <td><span class='status-badge $status_class'>" . ucfirst(str_replace('_', ' ', $att['status'])) . "</span></td>
                                        <td>$location_link</td>
                                        <td>$hr_status_display</td>
                                        <td>$actions</td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='11' class='text-center text-muted'>No attendance records for selected filters</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Time Clock Modal -->
    <div class="modal fade" id="timeClockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-clock"></i> Digital Time Clock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <h2 id="clockDisplay" class="mb-4 fw-bold">00:00:00</h2>
                    <p class="text-muted mb-2"><i class="fas fa-map-marker-alt"></i> Location Tracking Enabled</p>
                    <p class="small text-muted mb-4">Clock-ins inside the configured store radius are auto-approved.</p>
                    
                    <form method="POST" id="clockForm">
                        <input type="hidden" name="latitude" id="lat">
                        <input type="hidden" name="longitude" id="long">
                        
                        <div class="form-group mb-4">
                            <input type="text" name="employee_id_input" class="form-control form-control-lg text-center" placeholder="Scan or Enter Employee ID" required autocomplete="off">
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="clock_in" class="btn btn-success btn-lg">
                                <i class="fas fa-sign-in-alt"></i> CLOCK IN
                            </button>
                            <div class="row g-2">
                                <div class="col">
                                    <button type="submit" name="action" value="break_start" class="btn btn-warning w-100">
                                        <i class="fas fa-coffee"></i> Start Break
                                    </button>
                                </div>
                                <div class="col">
                                    <button type="submit" name="action" value="break_end" class="btn btn-info w-100">
                                        <i class="fas fa-play"></i> End Break
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="action" value="clock_out" class="btn btn-danger btn-lg">
                                <i class="fas fa-sign-out-alt"></i> CLOCK OUT
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Employee Details Modal -->
    <div class="modal fade" id="employeeDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Employee Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="employeeDetailsContent">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function loadEmployeeDetails(employeeId) {
            $('#employeeDetailsContent').html('<div class="text-center p-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
            $.get('get_employee_details.php', { id: employeeId }, function(data) {
                $('#employeeDetailsContent').html(data);
            });
        }

        // Live Clock
        function updateClock() {
            const now = new Date();
            document.getElementById('clockDisplay').innerText = now.toLocaleTimeString();
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Geolocation
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                document.getElementById('lat').value = position.coords.latitude;
                document.getElementById('long').value = position.coords.longitude;
            });
        }

        // Export CSV
        function exportTableToCSV(filename) {
            var csv = [];
            var rows = document.querySelectorAll("table tr");
            for (var i = 0; i < rows.length; i++) {
                var row = [], cols = rows[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) 
                    row.push('"' + cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim() + '"');
                csv.push(row.join(","));
            }
            var downloadLink = document.createElement("a");
            var blob = new Blob([csv.join("\n")], { type: "text/csv" });
            var url = URL.createObjectURL(blob);
            downloadLink.href = url;
            downloadLink.download = filename;
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        function toggleTimeInputs() {
            const status = document.getElementById('attendanceStatus').value;
            const timeInputs = document.getElementById('timeInputs');
            if (status === 'absent' || status === 'on_leave') {
                timeInputs.style.display = 'none';
            } else {
                timeInputs.style.display = 'block';
            }
        }
</script>
</body>
</html>
