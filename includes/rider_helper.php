<?php
/**
 * Delivery Rider & Driver Navigation Helper
 * Detects driver/rider accounts and manages navigation contexts to ensure
 * drivers stay strictly within the delivery logistics portal.
 */

if (!function_exists('isDeliveryDriverUser')) {
    function isDeliveryDriverUser($conn = null, $user_id = null, $explicit_store_id = null) {
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
        $is_employee_account = false;
        $is_delivery_employee = false;

        // 2. Check employees table (linked by user_id OR matching email)
        $user_email = trim((string)($user_data['email'] ?? ''));
        $emp_sql = "
            SELECT e.id, e.user_id, e.store_id, e.department_id, e.first_name, e.last_name, e.email, e.position, e.vehicle_details,
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
                $is_employee_account = true;

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
                $pos_name  = strtolower(trim((string)($emp_row['position'] ?? '')));
                $job_title = strtolower(trim((string)($emp_row['job_title'] ?? '')));

                // Precise keywords that strictly represent a delivery driver/courier
                $driver_title_keywords = ['driver', 'rider', 'courier'];

                // Department check: department explicitly contains 'delivery', 'rider', or 'courier'
                $is_dept_delivery = false;
                if ($dept_name !== '' && (strpos($dept_name, 'delivery') !== false || strpos($dept_name, 'rider') !== false || strpos($dept_name, 'courier') !== false)) {
                    $is_dept_delivery = true;
                }

                // Position check: position or job title explicitly contains 'driver', 'rider', or 'courier'
                $is_pos_delivery = false;
                foreach ($driver_title_keywords as $kw) {
                    if ($pos_name !== '' && strpos($pos_name, $kw) !== false) {
                        $is_pos_delivery = true;
                        break;
                    }
                    if ($job_title !== '' && strpos($job_title, $kw) !== false) {
                        $is_pos_delivery = true;
                        break;
                    }
                }

                if ($is_dept_delivery || $is_pos_delivery) {
                    $is_delivery_employee = true;
                }
            }
            mysqli_stmt_close($emp_stmt);
        }

        // If user is a verified employee in a non-delivery department (e.g. Finance, Kitchen, HR, Cashier),
        // they must NEVER be classified as a delivery rider. Auto-heal any errant rider records.
        if ($is_employee_account && !$is_delivery_employee) {
            $emp_id_val = (int)($matched_emp['id'] ?? 0);
            $clean_riders_sql = "DELETE FROM riders WHERE user_id = " . (int)$user_id;
            if ($emp_id_val > 0) {
                $clean_riders_sql .= " OR employee_id = " . $emp_id_val;
            }
            @mysqli_query($conn, $clean_riders_sql);

            // If the user's role was defaulted to role 7 (driver), heal it to a safe non-driver role
            if ((int)($user_data['role_id'] ?? 0) === 7 || $r_name === 'driver') {
                $emp_dept_id = (int)($matched_emp['department_id'] ?? 0);
                $healed_role_id = 0;
                if ($emp_dept_id > 0) {
                    $r_dept_chk = mysqli_query($conn, "SELECT id FROM roles WHERE department_id = $emp_dept_id AND name NOT IN ('super_admin', 'business_owner', 'driver', 'dept_delivery_riders') LIMIT 1");
                    if ($r_dept_chk && ($rd_row = mysqli_fetch_assoc($r_dept_chk))) {
                        $healed_role_id = (int)$rd_row['id'];
                    }
                }
                if ($healed_role_id <= 0) {
                    $r_staff_chk = mysqli_query($conn, "SELECT id FROM roles WHERE name IN ('operational_staff', 'employee', 'staff', 'viewer') AND name NOT IN ('driver', 'dept_delivery_riders') ORDER BY FIELD(name, 'operational_staff', 'employee', 'staff', 'viewer') LIMIT 1");
                    if ($r_staff_chk && ($rs_row = mysqli_fetch_assoc($r_staff_chk))) {
                        $healed_role_id = (int)$rs_row['id'];
                    } else {
                        $healed_role_id = 8; // fallback to viewer
                    }
                }
                if ($healed_role_id > 0) {
                    @mysqli_query($conn, "UPDATE users SET role_id = $healed_role_id WHERE id = " . (int)$user_id);
                }
            }

            // Clear session rider flags
            if (isset($_SESSION['is_driver'])) { unset($_SESSION['is_driver']); }
            if (isset($_SESSION['rider_id'])) { unset($_SESSION['rider_id']); }
            if (isset($_SESSION['rider_code'])) { unset($_SESSION['rider_code']); }

            return false;
        }

        if ($is_delivery_employee) {
            $is_driver = true;
        } else {
            // 3. For non-employee users (e.g. standalone/freelance rider accounts), check direct role & user type
            $u_type = strtolower(trim((string)($user_data['user_type'] ?? '')));
            if (in_array($u_type, ['driver', 'rider'], true)) {
                $is_driver = true;
            }

            if (in_array($r_name, ['driver', 'rider', 'delivery_driver', 'dept_delivery_riders'], true)) {
                $is_driver = true;
            }

            // 4. Check riders table directly for verified external riders
            if (!$is_driver) {
                $r_chk = mysqli_query($conn, "SELECT id, verification_status FROM riders WHERE user_id = $user_id LIMIT 1");
                if ($r_chk && mysqli_num_rows($r_chk) > 0) {
                    $r_row = mysqli_fetch_assoc($r_chk);
                    if (($r_row['verification_status'] ?? '') === 'verified') {
                        $is_driver = true;
                    }
                }
            }
        }

        // 5. If identified as driver, ensure active verified profile in riders table
        if ($is_driver) {
            $emp_id_val = !empty($matched_emp['id']) ? (int)$matched_emp['id'] : "NULL";
            $r_code = 'RDR-' . str_pad((string)$user_id, 4, '0', STR_PAD_LEFT);
            $v_type = !empty($matched_emp['vehicle_details']) ? mysqli_real_escape_string($conn, $matched_emp['vehicle_details']) : 'Motorcycle';

            // Resolve hiring store branch strictly from authoritative tenant ownership (NEVER fall back to another shop)
            $store_id_val = 0;
            $store_owner_id_val = 0;
            $has_authoritative_store = false;

            if ($explicit_store_id !== null && (int)$explicit_store_id > 0) {
                $store_id_val = (int)$explicit_store_id;
                $has_authoritative_store = true;
            }

            // Check partner_user_links first for hiring owner
            $pul_owner_id = 0;
            $pul_store_id = 0;
            $pul_q = @mysqli_query($conn, "
                SELECT pul.owner_user_id, sl.store_id
                FROM partner_user_links pul
                LEFT JOIN store_locations sl ON pul.owner_user_id = sl.owner_user_id
                WHERE pul.managed_user_id = $user_id
                ORDER BY sl.store_id ASC
                LIMIT 1
            ");
            if ($pul_q && ($pul_row = mysqli_fetch_assoc($pul_q))) {
                $pul_owner_id = (int)($pul_row['owner_user_id'] ?? 0);
                $pul_store_id = (int)($pul_row['store_id'] ?? 0);
            }

            if ($store_id_val <= 0 && !empty($matched_emp['store_id']) && (int)$matched_emp['store_id'] > 0) {
                $emp_sid = (int)$matched_emp['store_id'];
                // Ensure matched_emp.store_id does not conflict with explicit partner_user_links owner
                if ($pul_owner_id > 0) {
                    $chk_ow = @mysqli_query($conn, "SELECT owner_user_id FROM store_locations WHERE store_id = {$emp_sid} LIMIT 1");
                    $chk_ow_row = $chk_ow ? mysqli_fetch_assoc($chk_ow) : null;
                    if ($chk_ow_row && (int)($chk_ow_row['owner_user_id'] ?? 0) === $pul_owner_id) {
                        $store_id_val = $emp_sid;
                        $store_owner_id_val = $pul_owner_id;
                        $has_authoritative_store = true;
                    } elseif ($pul_store_id > 0) {
                        $store_id_val = $pul_store_id;
                        $store_owner_id_val = $pul_owner_id;
                        $has_authoritative_store = true;
                    }
                } else {
                    $store_id_val = $emp_sid;
                    $has_authoritative_store = true;
                }
            }

            if ($store_id_val <= 0 && $pul_store_id > 0) {
                $store_id_val = $pul_store_id;
                $store_owner_id_val = $pul_owner_id;
                $has_authoritative_store = true;
            }

            // Check role ownership (roles.owner_user_id)
            if ($store_id_val <= 0) {
                $role_ow_q = @mysqli_query($conn, "
                    SELECT r.owner_user_id, sl.store_id
                    FROM users u
                    INNER JOIN roles r ON r.id = u.role_id
                    LEFT JOIN store_locations sl ON sl.owner_user_id = r.owner_user_id
                    WHERE u.id = $user_id AND r.owner_user_id IS NOT NULL AND r.owner_user_id > 0
                    ORDER BY sl.store_id ASC
                    LIMIT 1
                ");
                if ($role_ow_q && ($role_ow_row = mysqli_fetch_assoc($role_ow_q))) {
                    $store_owner_id_val = (int)($role_ow_row['owner_user_id'] ?? 0);
                    if (!empty($role_ow_row['store_id'])) {
                        $store_id_val = (int)$role_ow_row['store_id'];
                        $has_authoritative_store = true;
                    }
                }
            }

            // Check department manager
            if ($store_id_val <= 0 && !empty($matched_emp['department_id'])) {
                $dept_q = @mysqli_query($conn, "
                    SELECT sl.store_id, sl.owner_user_id
                    FROM departments d
                    JOIN store_locations sl ON d.manager_id = sl.owner_user_id
                    WHERE d.id = " . (int)$matched_emp['department_id'] . "
                    ORDER BY sl.store_id ASC
                    LIMIT 1
                ");
                if ($dept_q && ($dept_row = mysqli_fetch_assoc($dept_q))) {
                    $store_id_val = (int)$dept_row['store_id'];
                    $store_owner_id_val = (int)($dept_row['owner_user_id'] ?? 0);
                    $has_authoritative_store = true;
                }
            }

            // Resolve owner_user_id for the resolved store_id
            if ($store_id_val > 0 && $store_owner_id_val <= 0) {
                $ow_q = @mysqli_query($conn, "SELECT owner_user_id FROM store_locations WHERE store_id = {$store_id_val} LIMIT 1");
                if ($ow_q && ($ow_r = mysqli_fetch_assoc($ow_q))) {
                    $store_owner_id_val = (int)($ow_r['owner_user_id'] ?? 0);
                }
            }
            if ($store_owner_id_val <= 0 && $pul_owner_id > 0) {
                $store_owner_id_val = $pul_owner_id;
            }

            $s_sql_val = $store_id_val > 0 ? $store_id_val : "NULL";
            $owner_sql_val = $store_owner_id_val > 0 ? $store_owner_id_val : "NULL";

            $has_store_owner_col = false;
            $col_chk = @mysqli_query($conn, "SHOW COLUMNS FROM `riders` LIKE 'store_owner_id'");
            if ($col_chk && mysqli_num_rows($col_chk) > 0) {
                $has_store_owner_col = true;
            }

            $store_update_expr = $has_authoritative_store
                ? "VALUES(store_id)"
                : "COALESCE(riders.store_id, VALUES(store_id))";

            if ($has_store_owner_col) {
                $owner_update_expr = ($store_owner_id_val > 0)
                    ? "VALUES(store_owner_id)"
                    : "COALESCE(riders.store_owner_id, VALUES(store_owner_id))";
                $rider_sync_sql = "
                    INSERT INTO riders (user_id, employee_id, rider_code, rider_type, store_id, store_owner_id, vehicle_type, verification_status, duty_status, rating)
                    VALUES ($user_id, $emp_id_val, '$r_code', 'shop_rider', $s_sql_val, $owner_sql_val, '$v_type', 'verified', 'online', 5.00)
                    ON DUPLICATE KEY UPDATE
                        verification_status = 'verified',
                        rider_type = 'shop_rider',
                        store_id = {$store_update_expr},
                        store_owner_id = {$owner_update_expr},
                        employee_id = COALESCE(riders.employee_id, VALUES(employee_id))
                ";
            } else {
                $rider_sync_sql = "
                    INSERT INTO riders (user_id, employee_id, rider_code, rider_type, store_id, vehicle_type, verification_status, duty_status, rating)
                    VALUES ($user_id, $emp_id_val, '$r_code', 'shop_rider', $s_sql_val, '$v_type', 'verified', 'online', 5.00)
                    ON DUPLICATE KEY UPDATE
                        verification_status = 'verified',
                        rider_type = 'shop_rider',
                        store_id = {$store_update_expr},
                        employee_id = COALESCE(riders.employee_id, VALUES(employee_id))
                ";
            }
            mysqli_query($conn, $rider_sync_sql);

            // Set session indicators
            $_SESSION['is_driver'] = true;
            $_SESSION['store_id'] = $store_id_val;
            $_SESSION['store_owner_id'] = $store_owner_id_val;
            $r_fetch = mysqli_query($conn, "SELECT id, rider_code, store_id FROM riders WHERE user_id = $user_id LIMIT 1");
            if ($r_fetch && ($r_data = mysqli_fetch_assoc($r_fetch))) {
                $_SESSION['rider_id'] = (int)$r_data['id'];
                $_SESSION['rider_code'] = $r_data['rider_code'];
                if (!empty($r_data['store_id'])) {
                    $_SESSION['store_id'] = (int)$r_data['store_id'];
                }
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

