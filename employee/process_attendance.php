<?php
require_once 'session_check.php';

if (!function_exists('employeeAttendanceTableExists')) {
    function employeeAttendanceTableExists($conn, $table_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        if (!$conn || $table_name === '') {
            return false;
        }
        if (array_key_exists($table_name, $cache)) {
            return $cache[$table_name];
        }
        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
        return $cache[$table_name] = ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('employeeAttendanceColumnExists')) {
    function employeeAttendanceColumnExists($conn, $table_name, $column_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        $column_name = trim((string)$column_name);
        $key = $table_name . '.' . $column_name;

        if (!$conn || $table_name === '' || $column_name === '') {
            return false;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!employeeAttendanceTableExists($conn, $table_name)) {
            return $cache[$key] = false;
        }
        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $safe_col = mysqli_real_escape_string($conn, $column_name);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_col}'");
        return $cache[$key] = ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('employeeAttendanceDistanceMeters')) {
    function employeeAttendanceDistanceMeters($lat1, $lon1, $lat2, $lon2) {
        $earth_radius_m = 6371000.0;
        $d_lat = deg2rad($lat2 - $lat1);
        $d_lon = deg2rad($lon2 - $lon1);
        $a = sin($d_lat / 2) * sin($d_lat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lon / 2) * sin($d_lon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius_m * $c;
    }
}

if (!function_exists('employeeResolveWorkStoreLocation')) {
    function employeeResolveWorkStoreLocation($conn, $employee_user_id) {
        $employee_user_id = intval($employee_user_id);
        if (!$conn || $employee_user_id <= 0 || !employeeAttendanceTableExists($conn, 'store_locations')) {
            return null;
        }

        $required_cols = ['store_id', 'store_name', 'email', 'latitude', 'longitude', 'is_active'];
        foreach ($required_cols as $required_col) {
            if (!employeeAttendanceColumnExists($conn, 'store_locations', $required_col)) {
                return null;
            }
        }

        $owner_user_id = 0;
        if (employeeAttendanceTableExists($conn, 'partner_user_links')
            && employeeAttendanceColumnExists($conn, 'partner_user_links', 'owner_user_id')
            && employeeAttendanceColumnExists($conn, 'partner_user_links', 'managed_user_id')) {
            $stmt = mysqli_prepare($conn, "SELECT owner_user_id FROM partner_user_links WHERE managed_user_id = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $employee_user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if (!empty($row['owner_user_id'])) {
                    $owner_user_id = (int)$row['owner_user_id'];
                }
            }
        }

        if ($owner_user_id <= 0
            && employeeAttendanceColumnExists($conn, 'users', 'role_id')
            && employeeAttendanceColumnExists($conn, 'roles', 'owner_user_id')) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT r.owner_user_id
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ?
                   AND r.owner_user_id IS NOT NULL
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $employee_user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if (!empty($row['owner_user_id'])) {
                    $owner_user_id = (int)$row['owner_user_id'];
                }
            }
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
        $franchise_email = $owner_user_id > 0 ? ('franchise+' . $owner_user_id . '@lechondelights.local') : '';
        $business_name = trim((string)($user_row['business_name'] ?? ''));

        $store_stmt = mysqli_prepare(
            $conn,
            "SELECT store_id, store_name, latitude, longitude
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
             LIMIT 1"
        );
        if ($store_stmt) {
            mysqli_stmt_bind_param($store_stmt, "ssssss", $email, $franchise_email, $business_name, $email, $franchise_email, $business_name);
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

        if ($owner_user_id <= 0) {
            $fallback_stmt = mysqli_prepare(
                $conn,
                "SELECT store_id, store_name, latitude, longitude
                 FROM store_locations
                 WHERE is_active = 1
                   AND latitude IS NOT NULL
                   AND longitude IS NOT NULL
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

if (!function_exists('employeeEvaluateGeofenceDecision')) {
    function employeeEvaluateGeofenceDecision($conn, $employee_user_id, $latitude, $longitude, $radius_meters = 250.0) {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return [
                'hr_status' => 'pending',
                'geo_note' => 'Geo-check: device location unavailable. Pending HR review.'
            ];
        }

        $store = employeeResolveWorkStoreLocation($conn, $employee_user_id);
        if (!$store) {
            return [
                'hr_status' => 'pending',
                'geo_note' => 'Geo-check: work location coordinates are not configured. Pending HR review.'
            ];
        }

        $distance_m = employeeAttendanceDistanceMeters(
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

// --- FIX START ---
// Check if employee_id is null, which means the user is logged in but not linked to an employee record
if ($employee_id === null) {
    $_SESSION['message'] = "Error: Your user account is not linked to an employee record. Please contact HR.";
    $_SESSION['msg_type'] = "danger";
    header("Location: attendance.php");
    exit();
}
// --- FIX END ---

if (isset($_POST['submit_attendance'])) {
    $attendance_geofence_radius_meters = 250.0;
    $attendance_date = $_POST['attendance_date'];
    $check_in_time = $_POST['check_in_time'];
    $check_out_time = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] : NULL;
    $notes = $_POST['notes'];
    $proof_path = NULL;
    $latitude = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? floatval($_POST['latitude']) : null;
    $longitude = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? floatval($_POST['longitude']) : null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // Basic validation
    if (empty($attendance_date) || empty($check_in_time) || empty($notes)) {
        $_SESSION['message'] = "Date, Time In, and Reason/Notes are required.";
        $_SESSION['msg_type'] = "danger";
        header("Location: attendance.php");
        exit();
    }

    // Handle file upload
    if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
        $target_dir = "../uploads/attendance_proofs/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file = $_FILES['proof'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_ext, $allowed_ext)) {
            if ($file['size'] <= 5 * 1024 * 1024) { // 5MB limit
                $new_file_name = "proof_att_" . $employee_id . "_" . time() . "." . $file_ext;
                $target_file = $target_dir . $new_file_name;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $proof_path = $target_file;
                } else {
                    $_SESSION['message'] = "Failed to upload proof file.";
                    $_SESSION['msg_type'] = "danger";
                    header("Location: attendance.php");
                    exit();
                }
            } else {
                $_SESSION['message'] = "File is too large. Maximum size is 5MB.";
                $_SESSION['msg_type'] = "danger";
                header("Location: attendance.php");
                exit();
            }
        } else {
            $_SESSION['message'] = "Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.";
            $_SESSION['msg_type'] = "danger";
            header("Location: attendance.php");
            exit();
        }
    } else {
        $_SESSION['message'] = "Proof of image is required for manual submission.";
        $_SESSION['msg_type'] = "danger";
        header("Location: attendance.php");
        exit();
    }

    // Since `attendance` table has no proof_path, we append it to notes.
    $full_notes = "Manual Submission Reason: " . $notes;
    if ($proof_path) {
        $full_notes .= "\nProof Path: " . $proof_path;
    }

    $geo_decision = employeeEvaluateGeofenceDecision(
        $conn,
        (int)$user_id,
        $latitude,
        $longitude,
        $attendance_geofence_radius_meters
    );
    $auto_hr_status = (string)($geo_decision['hr_status'] ?? 'pending');
    $geo_note = trim((string)($geo_decision['geo_note'] ?? ''));
    if ($geo_note !== '') {
        $full_notes .= "\n" . $geo_note;
    }
    
    // The status should ideally be 'pending_approval'. Since this status doesn't exist,
    // we'll insert it as 'present' for now. An admin should verify this manual submission.
    $status = 'present';

    // Check if attendance for this date already exists to avoid duplicates.
    $check_stmt = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $check_stmt->bind_param("is", $employee_id, $attendance_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $_SESSION['message'] = "An attendance record for this date already exists. Please contact HR to amend it.";
        $_SESSION['msg_type'] = "warning";
        header("Location: attendance.php");
        exit();
    }
    $check_stmt->close();

    // Insert into database
    $latitude_param = $latitude !== null ? (string)$latitude : '';
    $longitude_param = $longitude !== null ? (string)$longitude : '';
    $stmt = $conn->prepare(
        "INSERT INTO attendance (
            employee_id, attendance_date, check_in_time, check_out_time, status, notes, latitude, longitude, ip_address, hr_status
        ) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)"
    );
    $stmt->bind_param(
        "isssssssss",
        $employee_id,
        $attendance_date,
        $check_in_time,
        $check_out_time,
        $status,
        $full_notes,
        $latitude_param,
        $longitude_param,
        $ip_address,
        $auto_hr_status
    );

    if ($stmt->execute()) {
        if ($auto_hr_status === 'approved') {
            $_SESSION['message'] = "Attendance correction submitted and auto-approved by work-location validation.";
        } else {
            $_SESSION['message'] = "Attendance correction request submitted successfully. Location check is recorded and pending HR review.";
        }
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error submitting request: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();

} else {
    $_SESSION['message'] = "Invalid request.";
    $_SESSION['msg_type'] = "danger";
}

header("Location: attendance.php");
exit();
?>
