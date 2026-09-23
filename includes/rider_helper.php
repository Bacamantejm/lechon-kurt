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

        // 1. Fast check on session values (ONLY if checking the currently logged-in user)
        $is_current_session_user = !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id;
        if ($is_current_session_user) {
            $session_role = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
            if (in_array($session_role, ['super_admin', 'business_owner'], true)) {
                return false;
            }
            if (in_array($session_role, ['driver', 'rider', 'delivery_rider', 'dept_delivery_riders'], true)) {
                return true;
            }

            $session_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
            if (in_array($session_type, ['driver', 'rider'], true)) {
                return true;
            }

            if (!empty($_SESSION['is_driver'])) {
                return true;
            }
        }

        if (!$conn || !($conn instanceof mysqli)) {
            global $conn;
        }

        if (!$conn || !($conn instanceof mysqli)) {
            return false;
        }

        // Exclude super admins from DB check
        $role_chk = mysqli_query($conn, "SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = $user_id LIMIT 1");
        if ($role_chk && mysqli_num_rows($role_chk) > 0) {
            $r_name = strtolower(trim((string)mysqli_fetch_row($role_chk)[0]));
            if (in_array($r_name, ['super_admin', 'business_owner'], true)) {
                return false;
            }
        }

        // 2. Check riders table directly
        $r_chk = mysqli_query($conn, "SELECT id, verification_status FROM riders WHERE user_id = $user_id LIMIT 1");
        if ($r_chk && mysqli_num_rows($r_chk) > 0) {
            $r_row = mysqli_fetch_assoc($r_chk);
            if (($r_row['verification_status'] ?? '') === 'verified') {
                $_SESSION['is_driver'] = true;
                return true;
            }
        }

        // 3. Check roles assigned in DB for the user
        $role_sql = "
            SELECT r.name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? 
            LIMIT 1
        ";
        if ($stmt = mysqli_prepare($conn, $role_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $r_name = strtolower(trim((string)$row['name']));
                if (in_array($r_name, ['driver', 'rider', 'delivery_rider', 'dept_delivery_riders'], true)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['is_driver'] = true;
                    return true;
                }
            }
            mysqli_stmt_close($stmt);
        }

        // 3. Check employees table for vehicle details, delivery department, or logistics module permissions
        $emp_sql = "
            SELECT e.id, e.vehicle_details, d.department_name, e.position_id
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE e.user_id = ? AND e.status = 'active'
            LIMIT 1
        ";
        if ($stmt = mysqli_prepare($conn, $emp_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $dept = strtolower(trim((string)($row['department_name'] ?? '')));
                $has_vehicle = !empty($row['vehicle_details']);
                if (strpos($dept, 'deliver') !== false || strpos($dept, 'logistics') !== false || $has_vehicle) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['is_driver'] = true;
                    return true;
                }

                $pos_id = (int)($row['position_id'] ?? 0);
                if ($pos_id > 0) {
                    $pma_sql = "SELECT 1 FROM hr_position_module_access WHERE position_id = ? AND module_key = 'employee.logistics' AND is_enabled = 1 LIMIT 1";
                    if ($p_stmt = mysqli_prepare($conn, $pma_sql)) {
                        mysqli_stmt_bind_param($p_stmt, "i", $pos_id);
                        mysqli_stmt_execute($p_stmt);
                        mysqli_stmt_store_result($p_stmt);
                        if (mysqli_stmt_num_rows($p_stmt) > 0) {
                            mysqli_stmt_close($p_stmt);
                            mysqli_stmt_close($stmt);
                            $_SESSION['is_driver'] = true;
                            return true;
                        }
                        mysqli_stmt_close($p_stmt);
                    }
                }
            }
            mysqli_stmt_close($stmt);
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

