<?php
/**
 * Attendance & Geofenced Time Clock Service
 * Handles Time In / Time Out, Geofencing validation against shop location,
 * and enforcement of duty state for riders and employees.
 */

if (!function_exists('attTableExists')) {
    function attTableExists($conn, $table) {
        static $cache = [];
        $table = trim((string)$table);
        if (!$conn || $table === '') return false;
        if (isset($cache[$table])) return $cache[$table];
        $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        return $cache[$table] = ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('attDistanceMeters')) {
    function attDistanceMeters($lat1, $lon1, $lat2, $lon2) {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return null;
        $earth_radius_m = 6371000.0;
        $d_lat = deg2rad((float)$lat2 - (float)$lat1);
        $d_lon = deg2rad((float)$lon2 - (float)$lon1);
        $a = sin($d_lat / 2) * sin($d_lat / 2)
            + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) * sin($d_lon / 2) * sin($d_lon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius_m * $c;
    }
}

class AttendanceService {

    /**
     * Resolves or auto-creates an employee record for a user (including riders)
     */
    public static function resolveEmployeeId($conn, $user_id, $role_title = 'Staff', $store_id = 0) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$conn) return 0;

        // 1. Check existing employee by user_id
        $stmt = mysqli_prepare($conn, "SELECT id, store_id FROM employees WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $emp = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if ($emp) {
                // Update store_id if missing and store_id provided
                if ($store_id > 0 && empty($emp['store_id'])) {
                    mysqli_query($conn, "UPDATE employees SET store_id = $store_id WHERE id = " . (int)$emp['id'] . " LIMIT 1");
                }
                return (int)$emp['id'];
            }
        }

        // 2. Fetch user information
        $u_stmt = mysqli_prepare($conn, "SELECT full_name, email, phone, user_type FROM users WHERE id = ? LIMIT 1");
        if (!$u_stmt) return 0;
        mysqli_stmt_bind_param($u_stmt, "i", $user_id);
        mysqli_stmt_execute($u_stmt);
        $u_res = mysqli_stmt_get_result($u_stmt);
        $user = mysqli_fetch_assoc($u_res);
        mysqli_stmt_close($u_stmt);
        if (!$user) return 0;

        $full_name = trim((string)($user['full_name'] ?? 'Staff Member'));
        $parts = explode(' ', $full_name);
        $first_name = array_shift($parts);
        $last_name = !empty($parts) ? implode(' ', $parts) : 'Employee';
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            $email = 'user' . $user_id . '@lechondelights.local';
        }
        $phone = trim((string)($user['phone'] ?? ''));
        $emp_code = 'EMP-' . date('Ymd') . '-' . rand(1000, 9999);

        // Check if employee with same email already exists and link
        $em_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE email = ? AND (user_id IS NULL OR user_id = 0) LIMIT 1");
        if ($em_stmt) {
            mysqli_stmt_bind_param($em_stmt, "s", $email);
            mysqli_stmt_execute($em_stmt);
            $em_res = mysqli_stmt_get_result($em_stmt);
            $matched = mysqli_fetch_assoc($em_res);
            mysqli_stmt_close($em_stmt);
            if ($matched) {
                $eid = (int)$matched['id'];
                mysqli_query($conn, "UPDATE employees SET user_id = $user_id" . ($store_id > 0 ? ", store_id = $store_id" : "") . " WHERE id = $eid LIMIT 1");
                return $eid;
            }
        }

        // Create new employee record
        $ins_stmt = mysqli_prepare($conn, "
            INSERT INTO employees (user_id, employee_id, first_name, last_name, email, phone, position, hire_date, status, store_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', ?)
        ");
        if ($ins_stmt) {
            mysqli_stmt_bind_param($ins_stmt, "issssssi", $user_id, $emp_code, $first_name, $last_name, $email, $phone, $role_title, $store_id);
            mysqli_stmt_execute($ins_stmt);
            $new_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($ins_stmt);
            return $new_id;
        }

        return 0;
    }

    /**
     * Resolves the work store location (coordinates, store_name, store_id) for a user
     */
    public static function resolveWorkStore($conn, $user_id, $employee_id = 0) {
        $user_id = (int)$user_id;
        $employee_id = (int)$employee_id;
        if (!$conn || $user_id <= 0) return null;

        // 1. If user is a rider, check riders.store_id
        if (attTableExists($conn, 'riders')) {
            $r_stmt = mysqli_prepare($conn, "SELECT r.store_id, sl.store_name, sl.address, sl.city, sl.latitude, sl.longitude 
                                             FROM riders r 
                                             LEFT JOIN store_locations sl ON r.store_id = sl.store_id 
                                             WHERE r.user_id = ? LIMIT 1");
            if ($r_stmt) {
                mysqli_stmt_bind_param($r_stmt, "i", $user_id);
                mysqli_stmt_execute($r_stmt);
                $r_res = mysqli_stmt_get_result($r_stmt);
                $r_row = mysqli_fetch_assoc($r_res);
                mysqli_stmt_close($r_stmt);
                if ($r_row && !empty($r_row['latitude']) && !empty($r_row['longitude'])) {
                    return [
                        'store_id' => (int)$r_row['store_id'],
                        'store_name' => (string)($r_row['store_name'] ?: 'Store Branch'),
                        'address' => (string)($r_row['address'] ?? ''),
                        'city' => (string)($r_row['city'] ?? ''),
                        'latitude' => (float)$r_row['latitude'],
                        'longitude' => (float)$r_row['longitude']
                    ];
                }
            }
        }

        // 2. Check employees.store_id
        if ($employee_id > 0 && attTableExists($conn, 'employees')) {
            $e_stmt = mysqli_prepare($conn, "SELECT e.store_id, sl.store_name, sl.address, sl.city, sl.latitude, sl.longitude 
                                             FROM employees e 
                                             LEFT JOIN store_locations sl ON e.store_id = sl.store_id 
                                             WHERE e.id = ? LIMIT 1");
            if ($e_stmt) {
                mysqli_stmt_bind_param($e_stmt, "i", $employee_id);
                mysqli_stmt_execute($e_stmt);
                $e_res = mysqli_stmt_get_result($e_stmt);
                $e_row = mysqli_fetch_assoc($e_res);
                mysqli_stmt_close($e_stmt);
                if ($e_row && !empty($e_row['latitude']) && !empty($e_row['longitude'])) {
                    return [
                        'store_id' => (int)$e_row['store_id'],
                        'store_name' => (string)($e_row['store_name'] ?: 'Store Branch'),
                        'address' => (string)($e_row['address'] ?? ''),
                        'city' => (string)($e_row['city'] ?? ''),
                        'latitude' => (float)$e_row['latitude'],
                        'longitude' => (float)$e_row['longitude']
                    ];
                }
            }
        }

        // 3. Check partner_user_links -> store_locations by owner_user_id
        if (attTableExists($conn, 'partner_user_links')) {
            $p_stmt = mysqli_prepare($conn, "SELECT sl.store_id, sl.store_name, sl.address, sl.city, sl.latitude, sl.longitude 
                                             FROM partner_user_links pul 
                                             JOIN store_locations sl ON pul.owner_user_id = sl.owner_user_id 
                                             WHERE pul.managed_user_id = ? AND sl.is_active = 1 AND sl.latitude IS NOT NULL 
                                             LIMIT 1");
            if ($p_stmt) {
                mysqli_stmt_bind_param($p_stmt, "i", $user_id);
                mysqli_stmt_execute($p_stmt);
                $p_res = mysqli_stmt_get_result($p_stmt);
                $p_row = mysqli_fetch_assoc($p_res);
                mysqli_stmt_close($p_stmt);
                if ($p_row && !empty($p_row['latitude']) && !empty($p_row['longitude'])) {
                    return [
                        'store_id' => (int)$p_row['store_id'],
                        'store_name' => (string)$p_row['store_name'],
                        'address' => (string)($p_row['address'] ?? ''),
                        'city' => (string)($p_row['city'] ?? ''),
                        'latitude' => (float)$p_row['latitude'],
                        'longitude' => (float)$p_row['longitude']
                    ];
                }
            }
        }

        // 4. Fallback to primary active store
        $fb_res = mysqli_query($conn, "SELECT store_id, store_name, address, city, latitude, longitude 
                                      FROM store_locations 
                                      WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL 
                                      ORDER BY store_id ASC LIMIT 1");
        if ($fb_res && ($fb_row = mysqli_fetch_assoc($fb_res))) {
            return [
                'store_id' => (int)$fb_row['store_id'],
                'store_name' => (string)$fb_row['store_name'],
                'address' => (string)($fb_row['address'] ?? ''),
                'city' => (string)($fb_row['city'] ?? ''),
                'latitude' => (float)$fb_row['latitude'],
                'longitude' => (float)$fb_row['longitude']
            ];
        }

        return null;
    }

    /**
     * Gets today's attendance record for an employee / user
     */
    public static function getTodayAttendance($conn, $user_id, $employee_id = 0) {
        $user_id = (int)$user_id;
        $employee_id = (int)$employee_id;
        $today = date('Y-m-d');

        if ($employee_id <= 0 && $user_id > 0) {
            $employee_id = self::resolveEmployeeId($conn, $user_id);
        }

        if ($employee_id <= 0) {
            return [
                'has_record' => false,
                'status' => 'not_timed_in',
                'employee_id' => 0,
                'attendance_id' => 0,
                'check_in_time' => null,
                'check_out_time' => null,
                'hr_status' => 'pending',
                'store' => self::resolveWorkStore($conn, $user_id, $employee_id)
            ];
        }

        $stmt = mysqli_prepare($conn, "
            SELECT id, attendance_date, check_in_time, check_out_time, status, notes, latitude, longitude, hr_status 
            FROM attendance 
            WHERE employee_id = ? AND attendance_date = ? 
            LIMIT 1
        ");

        $record = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "is", $employee_id, $today);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $record = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }

        $store = self::resolveWorkStore($conn, $user_id, $employee_id);

        if (!$record || empty($record['check_in_time'])) {
            return [
                'has_record' => false,
                'status' => 'not_timed_in',
                'employee_id' => $employee_id,
                'attendance_id' => (int)($record['id'] ?? 0),
                'check_in_time' => null,
                'check_out_time' => null,
                'hr_status' => 'pending',
                'store' => $store
            ];
        }

        $is_timed_out = !empty($record['check_out_time']);
        $shift_status = $is_timed_out ? 'timed_out' : 'timed_in';

        return [
            'has_record' => true,
            'status' => $shift_status,
            'employee_id' => $employee_id,
            'attendance_id' => (int)$record['id'],
            'check_in_time' => $record['check_in_time'],
            'check_in_formatted' => date('g:i A', strtotime($record['check_in_time'])),
            'check_out_time' => $record['check_out_time'],
            'check_out_formatted' => $is_timed_out ? date('g:i A', strtotime($record['check_out_time'])) : null,
            'hr_status' => $record['hr_status'] ?? 'pending',
            'latitude' => $record['latitude'],
            'longitude' => $record['longitude'],
            'notes' => $record['notes'],
            'store' => $store
        ];
    }

    /**
     * Executes Time In (Clock In) with geofence validation against shop location
     */
    public static function clockIn($conn, $user_id, $lat, $lon, $ip_address = '', $radius_meters = 350.0) {
        $user_id = (int)$user_id;
        $today = date('Y-m-d');
        $now = date('H:i:s');
        $lat = ($lat !== null && is_numeric($lat)) ? (float)$lat : null;
        $lon = ($lon !== null && is_numeric($lon)) ? (float)$lon : null;

        // Check if user is rider
        $is_rider = false;
        $rider_id = 0;
        if (attTableExists($conn, 'riders')) {
            $r_stmt = mysqli_prepare($conn, "SELECT id, store_id, employee_id FROM riders WHERE user_id = ? LIMIT 1");
            if ($r_stmt) {
                mysqli_stmt_bind_param($r_stmt, "i", $user_id);
                mysqli_stmt_execute($r_stmt);
                $r_res = mysqli_stmt_get_result($r_stmt);
                $rider_row = mysqli_fetch_assoc($r_res);
                mysqli_stmt_close($r_stmt);
                if ($rider_row) {
                    $is_rider = true;
                    $rider_id = (int)$rider_row['id'];
                }
            }
        }

        $employee_id = self::resolveEmployeeId($conn, $user_id, $is_rider ? 'Delivery Rider' : 'Staff');
        if ($employee_id <= 0) {
            return ['success' => false, 'message' => 'Unable to resolve or link employee profile for this account.'];
        }

        // Link rider to employee_id if not yet linked
        if ($is_rider && $rider_id > 0) {
            mysqli_query($conn, "UPDATE riders SET employee_id = $employee_id WHERE id = $rider_id AND (employee_id IS NULL OR employee_id = 0) LIMIT 1");
        }

        // Check if already timed in today
        $existing = self::getTodayAttendance($conn, $user_id, $employee_id);
        if ($existing['status'] === 'timed_in') {
            return [
                'success' => true,
                'status' => 'timed_in',
                'message' => 'You are already clocked in for today.',
                'check_in_time' => $existing['check_in_formatted'],
                'is_near_shop' => true,
                'hr_status' => $existing['hr_status']
            ];
        }

        if ($existing['status'] === 'timed_out') {
            return [
                'success' => false,
                'status' => 'timed_out',
                'message' => 'You have already completed and timed out your shift for today.'
            ];
        }

        // Resolve store location
        $store = self::resolveWorkStore($conn, $user_id, $employee_id);
        $distance_m = null;
        $is_near_shop = false;
        $store_name = $store ? $store['store_name'] : 'Shop Branch';

        if ($lat !== null && $lon !== null && $store) {
            $distance_m = attDistanceMeters($lat, $lon, $store['latitude'], $store['longitude']);
            if ($distance_m !== null && $distance_m <= $radius_meters) {
                $is_near_shop = true;
            }
        }

        // Build note and status
        if ($is_near_shop) {
            $dist_label = round($distance_m);
            $hr_status = 'approved';
            $geo_note = "Location verified: within {$dist_label}m of {$store_name} (radius {$radius_meters}m). Auto-accepted.";
        } elseif ($distance_m !== null) {
            $dist_km = number_format($distance_m / 1000, 2);
            $dist_m = round($distance_m);
            $dist_text = ($distance_m >= 1000) ? "{$dist_km}km" : "{$dist_m}m";
            $hr_status = 'pending';
            $geo_note = "Geo-check outside radius: {$dist_text} from {$store_name} (radius {$radius_meters}m). Pending HR review.";
        } else {
            $hr_status = 'pending';
            $geo_note = "Geo-check: device location coordinates not available. Pending HR review.";
        }

        // Insert or update attendance record
        if (!empty($existing['attendance_id'])) {
            $att_id = (int)$existing['attendance_id'];
            $upd = mysqli_prepare($conn, "
                UPDATE attendance 
                SET check_in_time = ?, status = 'present', latitude = ?, longitude = ?, ip_address = ?, hr_status = ?, notes = ?
                WHERE id = ?
            ");
            if ($upd) {
                mysqli_stmt_bind_param($upd, "sddsssi", $now, $lat, $lon, $ip_address, $hr_status, $geo_note, $att_id);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }
        } else {
            $ins = mysqli_prepare($conn, "
                INSERT INTO attendance (employee_id, attendance_date, check_in_time, status, latitude, longitude, ip_address, hr_status, notes)
                VALUES (?, ?, ?, 'present', ?, ?, ?, ?, ?)
            ");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "issddssss", $employee_id, $today, $now, $lat, $lon, $ip_address, $hr_status, $geo_note);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // If rider: set duty status to online and record location
        if ($is_rider && $rider_id > 0) {
            $r_upd = mysqli_prepare($conn, "
                UPDATE riders 
                SET duty_status = 'online', current_latitude = COALESCE(?, current_latitude), current_longitude = COALESCE(?, current_longitude), last_location_update = NOW()
                WHERE id = ?
            ");
            if ($r_upd) {
                mysqli_stmt_bind_param($r_upd, "ddi", $lat, $lon, $rider_id);
                mysqli_stmt_execute($r_upd);
                mysqli_stmt_close($r_upd);
            }
        }

        // Log into employees_geo_tracking if available
        if (attTableExists($conn, 'employees_geo_tracking') && $lat !== null && $lon !== null) {
            $track_stmt = mysqli_prepare($conn, "
                INSERT INTO employees_geo_tracking (employee_id, current_latitude, current_longitude, tracking_status, last_update)
                VALUES (?, ?, ?, 'active', NOW())
            ");
            if ($track_stmt) {
                mysqli_stmt_bind_param($track_stmt, "idd", $employee_id, $lat, $lon);
                mysqli_stmt_execute($track_stmt);
                mysqli_stmt_close($track_stmt);
            }
        }

        $check_in_formatted = date('g:i A', strtotime($now));
        $resp_msg = $is_near_shop
            ? "Time In successful! Work location verified at {$store_name}. You are now on duty."
            : ($distance_m !== null 
                ? "Time In recorded ({$dist_text} from {$store_name}). Logged for shop owner review." 
                : "Time In recorded. Location unavailable, logged for shop owner review.");

        return [
            'success' => true,
            'status' => 'timed_in',
            'is_near_shop' => $is_near_shop,
            'distance_meters' => $distance_m,
            'hr_status' => $hr_status,
            'check_in_time' => $check_in_formatted,
            'store_name' => $store_name,
            'message' => $resp_msg
        ];
    }

    /**
     * Executes Time Out (Clock Out)
     */
    public static function clockOut($conn, $user_id, $lat = null, $lon = null, $ip_address = '') {
        $user_id = (int)$user_id;
        $today = date('Y-m-d');
        $now = date('H:i:s');

        $employee_id = self::resolveEmployeeId($conn, $user_id);
        if ($employee_id <= 0) {
            return ['success' => false, 'message' => 'Employee profile not found.'];
        }

        $existing = self::getTodayAttendance($conn, $user_id, $employee_id);
        if ($existing['status'] === 'not_timed_in') {
            return ['success' => false, 'message' => 'You cannot Time Out without timing in first.'];
        }
        if ($existing['status'] === 'timed_out') {
            return ['success' => true, 'message' => 'You have already timed out today.', 'check_out_time' => $existing['check_out_formatted']];
        }

        $att_id = (int)$existing['attendance_id'];
        $upd = mysqli_prepare($conn, "UPDATE attendance SET check_out_time = ? WHERE id = ?");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "si", $now, $att_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        // If rider: set duty status to offline
        if (attTableExists($conn, 'riders')) {
            $r_upd = mysqli_prepare($conn, "UPDATE riders SET duty_status = 'offline', last_location_update = NOW() WHERE user_id = ?");
            if ($r_upd) {
                mysqli_stmt_bind_param($r_upd, "i", $user_id);
                mysqli_stmt_execute($r_upd);
                mysqli_stmt_close($r_upd);
            }
        }

        // Log into employees_geo_tracking if available
        if (attTableExists($conn, 'employees_geo_tracking') && $lat !== null && $lon !== null) {
            $track_stmt = mysqli_prepare($conn, "
                INSERT INTO employees_geo_tracking (employee_id, current_latitude, current_longitude, tracking_status, last_update)
                VALUES (?, ?, ?, 'inactive', NOW())
            ");
            if ($track_stmt) {
                mysqli_stmt_bind_param($track_stmt, "idd", $employee_id, $lat, $lon);
                mysqli_stmt_execute($track_stmt);
                mysqli_stmt_close($track_stmt);
            }
        }

        return [
            'success' => true,
            'status' => 'timed_out',
            'check_out_time' => date('g:i A', strtotime($now)),
            'message' => 'Time Out successful! You are now off duty and cannot accept further orders.'
        ];
    }

    /**
     * Checks if a rider is permitted to accept orders (must be Timed In and not Timed Out)
     */
    public static function canRiderAcceptOrders($conn, $rider_id, $user_id = 0) {
        $rider_id = (int)$rider_id;
        $user_id = (int)$user_id;

        if ($user_id <= 0 && $rider_id > 0 && attTableExists($conn, 'riders')) {
            $stmt = mysqli_prepare($conn, "SELECT user_id, employee_id, duty_status FROM riders WHERE id = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $rider_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $r = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);
                if ($r) {
                    $user_id = (int)$r['user_id'];
                    if (($r['duty_status'] ?? '') === 'offline') {
                        return ['allowed' => false, 'reason' => 'Rider is currently set to Offline.'];
                    }
                }
            }
        }

        if ($user_id <= 0) {
            return ['allowed' => false, 'reason' => 'Invalid rider credentials.'];
        }

        $att = self::getTodayAttendance($conn, $user_id);
        if ($att['status'] === 'not_timed_in') {
            return ['allowed' => false, 'reason' => 'You must Time In on your dashboard before you can accept delivery orders.'];
        }
        if ($att['status'] === 'timed_out') {
            return ['allowed' => false, 'reason' => 'You have Timed Out for today. You cannot accept new orders while off duty.'];
        }

        return ['allowed' => true, 'reason' => 'Active shift verified.'];
    }
}
