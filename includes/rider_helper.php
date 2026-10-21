<?php
/**
 * Delivery Rider & Driver Navigation Helper
 * Detects driver/rider accounts and manages navigation contexts to ensure
 * drivers stay strictly within the delivery logistics portal.
 */

if (!function_exists('isDeliveryDriverUser')) {
    function isDeliveryDriverUser($conn = null, $user_id = null) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if ($user_id === null) {
            $user_id = (int)($_SESSION['user_id'] ?? 0);
        } else {
            $user_id = (int)$user_id;
        }

        if ($user_id <= 0) {
            return false;
        }

        if (!$conn || !($conn instanceof mysqli)) {
            global $conn;
        }

        if (!$conn || !($conn instanceof mysqli)) {
            return false;
        }

        // 1. Fetch user data and joined role/department
        $user_sql = "
            SELECT u.id, u.email, u.user_type, u.role_id,
                   r.name AS role_name, r.description AS role_desc,
                   rd.department_name AS role_dept_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN departments rd ON r.department_id = rd.id
            WHERE u.id = ?
            LIMIT 1
        ";
        $user_data = null;
        if ($u_stmt = mysqli_prepare($conn, $user_sql)) {
            mysqli_stmt_bind_param($u_stmt, "i", $user_id);
            mysqli_stmt_execute($u_stmt);
            $u_res = mysqli_stmt_get_result($u_stmt);
            $user_data = mysqli_fetch_assoc($u_res);
            mysqli_stmt_close($u_stmt);
        }

        if (!$user_data) {
            return false;
        }

        // Super admins and business owners should never be forced to rider portal
        $r_name = strtolower(trim((string)($user_data['role_name'] ?? '')));
        if (in_array($r_name, ['super_admin', 'business_owner'], true)) {
            return false;
        }

        $is_driver = false;
        $matched_emp = null;
        $driver_keywords = ['driver', 'rider', 'delivery', 'logistics', 'courier'];

        // 2. Check user's direct role, user type, or role description
        $u_type = strtolower(trim((string)($user_data['user_type'] ?? '')));
        $r_desc = strtolower(trim((string)($user_data['role_desc'] ?? '')));
        $rd_name = strtolower(trim((string)($user_data['role_dept_name'] ?? '')));

        if (in_array($u_type, ['driver', 'rider'], true)) {
            $is_driver = true;
        }

        foreach ($driver_keywords as $kw) {
            if ($r_name !== '' && strpos($r_name, $kw) !== false) {
                $is_driver = true;
                break;
            }
            if ($r_desc !== '' && strpos($r_desc, $kw) !== false) {
                $is_driver = true;
                break;
            }
            if ($rd_name !== '' && strpos($rd_name, $kw) !== false) {
                $is_driver = true;
                break;
            }
        }

        // 3. Check employees table (linked by user_id OR matching email)
        $user_email = trim((string)($user_data['email'] ?? ''));
        $emp_sql = "
            SELECT e.id, e.user_id, e.first_name, e.last_name, e.email, e.position, e.vehicle_details,
                   d.department_name, e.position_id, jp.position_title AS job_title
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN job_positions jp ON e.position_id = jp.id
            WHERE (e.user_id = ? OR (e.email = ? AND e.email IS NOT NULL AND e.email != ''))
              AND e.status = 'active'
            ORDER BY (e.user_id = ?) DESC, e.id DESC
            LIMIT 1
        ";
        if ($emp_stmt = mysqli_prepare($conn, $emp_sql)) {
            mysqli_stmt_bind_param($emp_stmt, "isi", $user_id, $user_email, $user_id);
            mysqli_stmt_execute($emp_stmt);
            $emp_res = mysqli_stmt_get_result($emp_stmt);
            if ($emp_row = mysqli_fetch_assoc($emp_res)) {
                $matched_emp = $emp_row;

                // Auto-link user_id on employee if unlinked or mismatched
                if ((int)($emp_row['user_id'] ?? 0) !== $user_id) {
                    $upd_stmt = mysqli_prepare($conn, "UPDATE employees SET user_id = ? WHERE id = ?");
                    if ($upd_stmt) {
                        $emp_pk = (int)$emp_row['id'];
                        mysqli_stmt_bind_param($upd_stmt, "ii", $user_id, $emp_pk);
                        mysqli_stmt_execute($upd_stmt);
                        mysqli_stmt_close($upd_stmt);
                        $matched_emp['user_id'] = $user_id;
                    }
                }

                $dept_name = strtolower(trim((string)($emp_row['department_name'] ?? '')));
                $pos_name = strtolower(trim((string)($emp_row['position'] ?? '')));
                $job_title = strtolower(trim((string)($emp_row['job_title'] ?? '')));
                $has_vehicle = !empty($emp_row['vehicle_details']);

                foreach ($driver_keywords as $kw) {
                    if ($dept_name !== '' && strpos($dept_name, $kw) !== false) {
                        $is_driver = true;
                        break;
                    }
                    if ($pos_name !== '' && strpos($pos_name, $kw) !== false) {
                        $is_driver = true;
                        break;
                    }
                    if ($job_title !== '' && strpos($job_title, $kw) !== false) {
                        $is_driver = true;
                        break;
                    }
                }

                if ($has_vehicle) {
                    $is_driver = true;
                }

                // Check position logistics module access
                $pos_id = (int)($emp_row['position_id'] ?? 0);
                if ($pos_id > 0) {
                    $pma_sql = "SELECT 1 FROM hr_position_module_access WHERE position_id = ? AND module_key = 'employee.logistics' AND is_enabled = 1 LIMIT 1";
                    if ($p_stmt = mysqli_prepare($conn, $pma_sql)) {
                        mysqli_stmt_bind_param($p_stmt, "i", $pos_id);
                        mysqli_stmt_execute($p_stmt);
                        mysqli_stmt_store_result($p_stmt);
                        if (mysqli_stmt_num_rows($p_stmt) > 0) {
                            $is_driver = true;
                        }
                        mysqli_stmt_close($p_stmt);
                    }
                }
            }
            mysqli_stmt_close($emp_stmt);
        }

        // 4. Check riders table directly
        $r_chk = mysqli_query($conn, "SELECT id, verification_status FROM riders WHERE user_id = $user_id LIMIT 1");
        if ($r_chk && mysqli_num_rows($r_chk) > 0) {
            $r_row = mysqli_fetch_assoc($r_chk);
            if (($r_row['verification_status'] ?? '') === 'verified') {
                $is_driver = true;
            }
        }

        // 5. If identified as driver, ensure active verified profile in riders table
        if ($is_driver) {
            $emp_id_val = !empty($matched_emp['id']) ? (int)$matched_emp['id'] : "NULL";
            $r_code = 'RDR-' . str_pad((string)$user_id, 4, '0', STR_PAD_LEFT);
            $v_type = !empty($matched_emp['vehicle_details']) ? mysqli_real_escape_string($conn, $matched_emp['vehicle_details']) : 'Motorcycle';

            // Insert or ensure verified
            $rider_sync_sql = "
                INSERT INTO riders (user_id, employee_id, rider_code, rider_type, vehicle_type, verification_status, duty_status, rating)
                VALUES ($user_id, $emp_id_val, '$r_code', 'shop_rider', '$v_type', 'verified', 'online', 5.00)
                ON DUPLICATE KEY UPDATE 
                    verification_status = 'verified',
                    employee_id = COALESCE(riders.employee_id, VALUES(employee_id))
            ";
            mysqli_query($conn, $rider_sync_sql);

            // Set session indicators
            $_SESSION['is_driver'] = true;
            $r_fetch = mysqli_query($conn, "SELECT id, rider_code FROM riders WHERE user_id = $user_id LIMIT 1");
            if ($r_fetch && ($r_data = mysqli_fetch_assoc($r_fetch))) {
                $_SESSION['rider_id'] = (int)$r_data['id'];
                $_SESSION['rider_code'] = $r_data['rider_code'];
            }

            return true;
        }

        return false;
    }
}

if (!function_exists('isRiderSessionActive')) {
    function isRiderSessionActive($conn = null) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (isset($_GET['from']) && $_GET['from'] === 'logistics') {
            $_SESSION['from_logistics'] = true;
            return true;
        }

        if (!empty($_SESSION['from_logistics'])) {
            return true;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'logistics.php') !== false) {
            $_SESSION['from_logistics'] = true;
            return true;
        }

        if (!$conn || !($conn instanceof mysqli)) {
            global $conn;
        }

        if ($conn && !empty($_SESSION['user_id'])) {
            return isDeliveryDriverUser($conn, (int)$_SESSION['user_id']);
        }

        return false;
    }
}

if (!function_exists('ensureRiderRedirectsOnlyToPortal')) {
    function ensureRiderRedirectsOnlyToPortal($conn = null, $path_prefix = '') {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            return;
        }
        if (!$conn || !($conn instanceof mysqli)) {
            global $conn;
        }
        $uid = (int)$_SESSION['user_id'];
        if ($uid > 0 && isDeliveryDriverUser($conn, $uid)) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            // Only redirect if not already in rider portal, auth logout, or delivery API
            if (strpos($uri, '/rider/') === false && 
                strpos($uri, 'api_rider.php') === false && 
                strpos($uri, 'api/delivery_chat.php') === false && 
                strpos($uri, 'logout.php') === false) {
                header("Location: " . $path_prefix . "rider/index.php");
                exit();
            }
        }
    }
}

