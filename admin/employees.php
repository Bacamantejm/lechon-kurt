<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/security.php';
include_once __DIR__ . '/auth.php';
include_once __DIR__ . '/hr_module_common.php';

checkAdminAccess();

// Self-heal normalized schema
if (function_exists('hrEnsureNormalizedPositionModel') && isset($conn) && $conn) {
    hrEnsureNormalizedPositionModel($conn);
}

// Enforce page-level permission
$admin_info = getAdminInfo($conn) ?: [];
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$partner_scope_owner_id = hrPartnerScopeOwnerUserId($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
if (!$is_partner_scoped_hr) {
    requirePermission('employees.view');
}

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('employeesRoleOwnerColumnExists')) {
    function employeesRoleOwnerColumnExists($conn) {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $res = mysqli_query($conn, "SHOW COLUMNS FROM roles LIKE 'owner_user_id'");
        $exists = $res && mysqli_num_rows($res) > 0;
        return $exists;
    }
}

if (!function_exists('employeesRoleInPartnerScope')) {
    function employeesRoleInPartnerScope($conn, $role_id, $owner_user_id) {
        $role_id = intval($role_id);
        $owner_user_id = intval($owner_user_id);
        if ($role_id <= 0 || $owner_user_id <= 0 || !employeesRoleOwnerColumnExists($conn)) {
            return false;
        }
        $stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE id = ? AND owner_user_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "ii", $role_id, $owner_user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('employeesFetchPositionRecord')) {
    function employeesFetchPositionRecord($conn, $position_id) {
        $position_id = intval($position_id);
        if ($position_id <= 0) {
            return null;
        }
        if (!function_exists('hrGetPositionById')) {
            return null;
        }
        return hrGetPositionById($conn, $position_id);
    }
}

if (!function_exists('employeesAllowedStatuses')) {
    function employeesAllowedStatuses() {
        return ['active', 'inactive', 'on_leave', 'terminated'];
    }
}

if (!function_exists('employeesSyncLinkedUserStatus')) {
    function employeesSyncLinkedUserStatus($conn, $user_id, $employee_status) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return;
        }

        $target_active = in_array($employee_status, ['terminated', 'inactive'], true) ? 0 : 1;
        $stmt = mysqli_prepare($conn, "UPDATE users SET is_active = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param($stmt, "ii", $target_active, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('employeesEnsureTurnoverRecord')) {
    function employeesEnsureTurnoverRecord($conn, $employee_id, $processed_by) {
        $employee_id = (int)$employee_id;
        $processed_by = (int)$processed_by;
        if ($employee_id <= 0 || !hrTableExists($conn, 'employee_turnover')) {
            return;
        }

        $today = date('Y-m-d');
        $check_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM employee_turnover
             WHERE employee_id = ?
               AND separation_type = 'termination'
               AND resignation_date = ?
               AND last_working_day = ?
             LIMIT 1"
        );
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "iss", $employee_id, $today, $today);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            $exists = mysqli_stmt_num_rows($check_stmt) > 0;
            mysqli_stmt_close($check_stmt);
            if ($exists) {
                return;
            }
        }

        $reason = 'Marked as terminated from Employees module';
        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO employee_turnover
             (employee_id, separation_type, resignation_date, last_working_day, notice_period_days, resignation_reason, processed_by)
             VALUES (?, 'termination', ?, ?, 0, ?, ?)"
        );
        if ($insert_stmt) {
            mysqli_stmt_bind_param($insert_stmt, "isssi", $employee_id, $today, $today, $reason, $processed_by);
            mysqli_stmt_execute($insert_stmt);
            mysqli_stmt_close($insert_stmt);
        }
    }
}

if (!function_exists('employeesRespond')) {
    function employeesRespond(bool $success, string $message, array $extra = []) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(array_merge([
                'success' => $success,
                'message' => $message
            ], $extra));
            exit();
        }
    }
}

// Handle employee actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for all POST actions
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid CSRF token. Action aborted.';
        header("Location: employees.php");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'bulk_assign_department') {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.edit');
        }

        $employee_ids = $_POST['employee_ids'] ?? [];
        $department_id = intval($_POST['bulk_department_id'] ?? 0);

        if (!empty($employee_ids) && $department_id > 0) {
            // Ensure employee_ids are all integers
            $employee_ids = array_map('intval', $employee_ids);
            if ($is_partner_scoped_hr) {
                $employee_ids = array_values(array_filter($employee_ids, function ($emp_id) use ($conn) {
                    return hrEmployeeIdInScope($conn, $emp_id);
                }));
                if ($department_id > 0 && !hrDepartmentIdInScope($conn, $department_id)) {
                    $_SESSION['error'] = "Selected department is outside your partner HR scope.";
                    header("Location: employees.php");
                    exit();
                }
            }
            if (empty($employee_ids)) {
                $_SESSION['error'] = "No scoped employees selected for bulk assignment.";
                header("Location: employees.php");
                exit();
            }
            $ids_placeholder = implode(',', array_fill(0, count($employee_ids), '?'));
            
            $query = "UPDATE employees SET department_id = ? WHERE id IN ($ids_placeholder)";
            $stmt = mysqli_prepare($conn, $query);
            if (!$stmt) {
                $_SESSION['error'] = "Failed to prepare bulk assignment: " . mysqli_error($conn);
                header("Location: employees.php");
                exit();
            }

            $types = 'i' . str_repeat('i', count($employee_ids));
            $params_to_bind = array_merge([$department_id], $employee_ids);

            mysqli_stmt_bind_param($stmt, $types, ...$params_to_bind);
            
            if (mysqli_stmt_execute($stmt)) {
                require_once __DIR__ . '/../includes/rider_helper.php';
                foreach ($employee_ids as $eid) {
                    $emp_q = mysqli_query($conn, "SELECT user_id, email FROM employees WHERE id = " . (int)$eid . " LIMIT 1");
                    if ($emp_q && ($er = mysqli_fetch_assoc($emp_q))) {
                        $uid = !empty($er['user_id']) ? (int)$er['user_id'] : 0;
                        if (!$uid && !empty($er['email'])) {
                            $u_match = mysqli_query($conn, "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $er['email']) . "' LIMIT 1");
                            if ($u_match && ($ur = mysqli_fetch_assoc($u_match))) {
                                $uid = (int)$ur['id'];
                                mysqli_query($conn, "UPDATE employees SET user_id = $uid WHERE id = " . (int)$eid . " LIMIT 1");
                            }
                        }
                        if ($uid > 0) {
                            isDeliveryDriverUser($conn, $uid);
                        }
                    }
                }
                $_SESSION['success'] = count($employee_ids) . " employees assigned to the department.";
            } else {
                $_SESSION['error'] = "Failed to assign employees: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $_SESSION['error'] = "No employees or department selected for bulk assignment.";
        }
    }
    if (isset($_POST['reassign_user_role'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('users.edit');
        }
        $emp_id = intval($_POST['employee_id']);
        $new_role_id = intval($_POST['role_id']);
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $emp_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        if ($is_partner_scoped_hr && !employeesRoleInPartnerScope($conn, $new_role_id, $partner_scope_owner_id)) {
            $_SESSION['error'] = "Selected role is outside your partner scope.";
            header("Location: employees.php");
            exit();
        }

        $lookup = mysqli_query($conn, "SELECT user_id, first_name, last_name FROM employees WHERE id = $emp_id LIMIT 1");
        if ($lookup && ($row = mysqli_fetch_assoc($lookup)) && !empty($row['user_id'])) {
            $user_id_to_update = (int)$row['user_id'];
            $up_stmt = mysqli_prepare($conn, "UPDATE users SET role_id = ? WHERE id = ?");
            if ($up_stmt) {
                mysqli_stmt_bind_param($up_stmt, "ii", $new_role_id, $user_id_to_update);
                if (mysqli_stmt_execute($up_stmt)) {
                    $r_name_res = mysqli_query($conn, "SELECT name FROM roles WHERE id = $new_role_id LIMIT 1");
                    $r_name = ($r_name_res && $rn = mysqli_fetch_assoc($r_name_res)) ? (function_exists('getRoleDisplayName') ? getRoleDisplayName($rn['name']) : $rn['name']) : 'New Role';
                    $_SESSION['success'] = "Role updated to {$r_name} for " . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . " successfully!";
                } else {
                    $_SESSION['error'] = "Failed to update role: " . mysqli_error($conn);
                }
                mysqli_stmt_close($up_stmt);
            }
        } else {
            $_SESSION['error'] = "Employee does not have a linked user account.";
        }
        header("Location: employees.php");
        exit();
    }

    if (isset($_POST['link_user_account'])) {
        // Enforce action-level permission to create a user account
        if (!$is_partner_scoped_hr) {
            requirePermission('users.create');
        }

        $employee_id_to_link = intval($_POST['employee_id_to_link']);
        $user_role_id = intval($_POST['role_id']);
        $user_password = $_POST['password'];
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id_to_link)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        if ($is_partner_scoped_hr && !employeesRoleInPartnerScope($conn, $user_role_id, $partner_scope_owner_id)) {
            $_SESSION['error'] = "Selected role is outside your partner scope.";
            header("Location: employees.php");
            exit();
        }

        // Fetch employee details
        $emp_lookup_sql = "SELECT e.* FROM employees e WHERE e.id = ?";
        if ($is_partner_scoped_hr) {
            $emp_lookup_sql .= " AND {$employee_scope_sql}";
        }
        $emp_stmt = mysqli_prepare($conn, $emp_lookup_sql);
        if ($emp_stmt) {
            mysqli_stmt_bind_param($emp_stmt, "i", $employee_id_to_link);
            mysqli_stmt_execute($emp_stmt);
            $emp_result = mysqli_stmt_get_result($emp_stmt);
        } else {
            $emp_result = false;
        }

        if ($emp_result && mysqli_num_rows($emp_result) > 0) {
            $employee = mysqli_fetch_assoc($emp_result);
            $email = $employee['email'];
            $full_name = formatEmployeeFullName($employee['first_name'] ?? '', $employee['middle_initial'] ?? '', $employee['last_name'] ?? '', $employee['suffix'] ?? '');
            $phone = $employee['phone'];

            // Check if user account with this email already exists
            $user_check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            $has_existing_email = false;
            if ($user_check_stmt) {
                mysqli_stmt_bind_param($user_check_stmt, "s", $email);
                mysqli_stmt_execute($user_check_stmt);
                $user_check_result = mysqli_stmt_get_result($user_check_stmt);
                $has_existing_email = ($user_check_result && mysqli_num_rows($user_check_result) > 0);
                mysqli_stmt_close($user_check_stmt);
            }

            if ($has_existing_email) {
                $_SESSION['error'] = "An account with this email already exists. Cannot create a new one.";
            } elseif (empty($user_password) || empty($user_role_id)) {
                $_SESSION['error'] = "Password and Role are required.";
            } else {
                // Create user account
                $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);
                $has_u_middle = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'middle_name');
                $has_u_suffix = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'suffix');

                $stmt_user = false;
                if ($has_u_middle && $has_u_suffix) {
                    $user_query = "INSERT INTO users (full_name, middle_name, suffix, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        $emp_mi = $employee['middle_initial'] ?? '';
                        $emp_suf = $employee['suffix'] ?? '';
                        mysqli_stmt_bind_param($stmt_user, "ssssssi", $full_name, $emp_mi, $emp_suf, $email, $phone, $hashed_password, $user_role_id);
                    }
                }

                if (!$stmt_user) {
                    $user_query = "INSERT INTO users (full_name, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        mysqli_stmt_bind_param($stmt_user, "ssssi", $full_name, $email, $phone, $hashed_password, $user_role_id);
                    }
                }

                if (!$stmt_user) {
                    $_SESSION['error'] = "User account creation failed: " . mysqli_error($conn);
                    header("Location: employees.php");
                    exit();
                }
                
                if (mysqli_stmt_execute($stmt_user)) {
                    $new_user_id = mysqli_insert_id($conn);
                    if ($is_partner_scoped_hr) {
                        $link_ok = $partner_scope_owner_id > 0
                            && function_exists('linkPartnerManagedUser')
                            && linkPartnerManagedUser($conn, $partner_scope_owner_id, $new_user_id);
                        if (!$link_ok) {
                            mysqli_query($conn, "DELETE FROM users WHERE id = " . (int)$new_user_id . " LIMIT 1");
                            mysqli_stmt_close($stmt_user);
                            $_SESSION['error'] = "Unable to secure partner scope for the new user account. Please run Tenant Scope Migration and try again.";
                            header("Location: employees.php");
                            exit();
                        }
                    }
                    
                    // Link to employee record
                    $link_query = "UPDATE employees SET user_id = ? WHERE id = ?";
                    $stmt_link = mysqli_prepare($conn, $link_query);
                    if ($stmt_link) {
                        mysqli_stmt_bind_param($stmt_link, "ii", $new_user_id, $employee_id_to_link);
                        if (mysqli_stmt_execute($stmt_link)) {
                            require_once __DIR__ . '/../includes/rider_helper.php';
                            isDeliveryDriverUser($conn, $new_user_id);
                            $_SESSION['success'] = "User account created and linked to employee successfully.";
                        } else {
                            $_SESSION['error'] = "Account created, but failed to link to employee record.";
                        }
                        mysqli_stmt_close($stmt_link);
                    } else {
                        $_SESSION['error'] = "Account created, but failed to prepare link to employee record.";
                    }
                } else {
                    $_SESSION['error'] = "User account creation failed: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt_user);
            }
        } else {
            $_SESSION['error'] = "Employee not found.";
        }
    }

    if (isset($_POST['add_employee'])) {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.create');
        }

        $first_name = trim($_POST['first_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department_id = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? intval($_POST['department_id']) : 0;
        $position_id = intval($_POST['position_id'] ?? 0);
        $position_record = employeesFetchPositionRecord($conn, $position_id);
        if ($position_id > 0 && !$position_record) {
            $_SESSION['error'] = "Selected position was not found. Please choose a valid job position.";
            header("Location: employees.php");
            exit();
        }
        $position = $position_record['title'] ?? '';
        if ($department_id <= 0 && !empty($position_record['department_id'])) {
            $department_id = (int)$position_record['department_id'];
        }
        $hire_date = trim($_POST['hire_date'] ?? '');
        $employment_type = trim($_POST['employment_type'] ?? 'full_time');
        $employment_basis = trim($_POST['employment_basis'] ?? 'monthly');
        $base_salary_input = isset($_POST['base_salary']) && $_POST['base_salary'] !== '' ? floatval($_POST['base_salary']) : 0.00;

        $salary = 0.00;
        $daily_rate = 0.00;
        if ($employment_basis === 'monthly') {
            $salary = $base_salary_input;
        } else {
            $daily_rate = $base_salary_input;
        }
        $sss = trim($_POST['sss_number'] ?? '');
        $philhealth = trim($_POST['philhealth_number'] ?? '');
        $pagibig = trim($_POST['pagibig_number'] ?? '');
        $tin = trim($_POST['tin_number'] ?? '');
        $employee_id = 'EMP-' . date('Ymd') . '-' . rand(1000, 9999);

        // User account fields
        $create_user = isset($_POST['create_user']);
        $user_role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
        $user_password = $_POST['password'] ?? '';
        if ($is_partner_scoped_hr && $position_id > 0 && !hrPositionIdInScope($conn, $position_id)) {
            $_SESSION['error'] = "Selected position is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        if ($is_partner_scoped_hr && $department_id > 0 && !hrDepartmentIdInScope($conn, $department_id)) {
            $_SESSION['error'] = "Selected department is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        if ($is_partner_scoped_hr && $create_user && !employeesRoleInPartnerScope($conn, $user_role_id, $partner_scope_owner_id)) {
            $_SESSION['error'] = "Selected role is outside your partner scope.";
            header("Location: employees.php");
            exit();
        }

        // Check if email is already in employees table
        $emp_email_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE email = ? LIMIT 1");
        if ($emp_email_stmt) {
            mysqli_stmt_bind_param($emp_email_stmt, "s", $email);
            mysqli_stmt_execute($emp_email_stmt);
            $emp_email_res = mysqli_stmt_get_result($emp_email_stmt);
            if ($emp_email_res && mysqli_num_rows($emp_email_res) > 0) {
                $_SESSION['error'] = "An employee with email '{$email}' already exists.";
                mysqli_stmt_close($emp_email_stmt);
                header("Location: employees.php");
                exit();
            }
            mysqli_stmt_close($emp_email_stmt);
        }

        $user_check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        $user_check_has_rows = false;
        if ($user_check_stmt) {
            mysqli_stmt_bind_param($user_check_stmt, "s", $email);
            mysqli_stmt_execute($user_check_stmt);
            $user_check_result = mysqli_stmt_get_result($user_check_stmt);
            $user_check_has_rows = ($user_check_result && mysqli_num_rows($user_check_result) > 0);
            mysqli_stmt_close($user_check_stmt);
        }

        if ($create_user && (empty($user_password) || empty($user_role_id))) {
            $_SESSION['error'] = "Password and Role are required when creating a user account.";
        } elseif ($create_user && $user_check_has_rows) {
            $_SESSION['error'] = "Email is already registered to a user account.";
        } elseif ($first_name === '' || $last_name === '' || $email === '' || $phone === '') {
            $_SESSION['error'] = "Please fill in all required personal fields (First Name, Last Name, Email, and Phone).";
        } else { // Proceed with adding employee
            // Auto fallback defaults for optional attributes
            if ($department_id <= 0 && !empty($departments)) {
                $department_id = (int)$departments[0]['id'];
            }
            if ($position_id <= 0 && !empty($position_catalog['all'])) {
                $position_id = (int)$position_catalog['all'][0]['id'];
                $position = (string)$position_catalog['all'][0]['title'];
            }
            if ($position === '') {
                $position = 'Staff';
            }
            if ($hire_date === '') {
                $hire_date = date('Y-m-d');
            }
            if ($base_salary_input <= 0) {
                $base_salary_input = 0.00;
            }
            if ($employment_basis === 'monthly') {
                $salary = $base_salary_input;
                $daily_rate = 0.00;
            } else {
                $salary = 0.00;
                $daily_rate = $base_salary_input;
            }

            $user_id_for_employee = null; // Initialize user_id to null

            // If creating a user account, do it first to get the user_id
            if ($create_user) {
                $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);
                $full_name = formatEmployeeFullName($first_name, $middle_initial, $last_name, $suffix);

                if (function_exists('hrEnsureUserNameFields')) {
                    hrEnsureUserNameFields($conn);
                }

                $has_u_middle = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'middle_name');
                $has_u_suffix = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'suffix');

                $stmt_user = false;
                if ($has_u_middle && $has_u_suffix) {
                    $user_query = "INSERT INTO users (full_name, middle_name, suffix, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        mysqli_stmt_bind_param($stmt_user, "ssssssi", $full_name, $middle_initial, $suffix, $email, $phone, $hashed_password, $user_role_id);
                    }
                } elseif ($has_u_middle) {
                    $user_query = "INSERT INTO users (full_name, middle_name, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        mysqli_stmt_bind_param($stmt_user, "sssssi", $full_name, $middle_initial, $email, $phone, $hashed_password, $user_role_id);
                    }
                } elseif ($has_u_suffix) {
                    $user_query = "INSERT INTO users (full_name, suffix, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        mysqli_stmt_bind_param($stmt_user, "sssssi", $full_name, $suffix, $email, $phone, $hashed_password, $user_role_id);
                    }
                }

                if (!$stmt_user) {
                    $user_query = "INSERT INTO users (full_name, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, 'employee', ?, 1)";
                    $stmt_user = mysqli_prepare($conn, $user_query);
                    if ($stmt_user) {
                        mysqli_stmt_bind_param($stmt_user, "ssssi", $full_name, $email, $phone, $hashed_password, $user_role_id);
                    }
                }

                if (!$stmt_user) {
                    $_SESSION['error'] = "User account creation failed: " . mysqli_error($conn);
                    header("Location: employees.php");
                    exit();
                }

                if (mysqli_stmt_execute($stmt_user)) {
                    $user_id_for_employee = mysqli_insert_id($conn); // Get the ID of the newly created user
                    if ($is_partner_scoped_hr) {
                        $link_ok = $partner_scope_owner_id > 0
                            && function_exists('linkPartnerManagedUser')
                            && linkPartnerManagedUser($conn, $partner_scope_owner_id, $user_id_for_employee);
                        if (!$link_ok) {
                            mysqli_query($conn, "DELETE FROM users WHERE id = " . (int)$user_id_for_employee . " LIMIT 1");
                            mysqli_stmt_close($stmt_user);
                            $_SESSION['error'] = "Unable to secure partner scope for the new user account. Please run Tenant Scope Migration and try again.";
                            header("Location: employees.php");
                            exit();
                        }
                    }
                    $_SESSION['success'] = "User account created successfully.";
                } else {
                    $_SESSION['error'] = "User account creation failed: " . mysqli_error($conn);
                    // If user creation fails, don't add employee record
                    mysqli_stmt_close($stmt_user);
                    header("Location: employees.php");
                    exit();
                }
                mysqli_stmt_close($stmt_user);
            }

            // If user account wasn't created via checkbox, check if user with this email already exists
            if (!$create_user && $user_id_for_employee === null) {
                $user_check_stmt2 = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
                if ($user_check_stmt2) {
                    mysqli_stmt_bind_param($user_check_stmt2, "s", $email);
                    mysqli_stmt_execute($user_check_stmt2);
                    $u_res = mysqli_stmt_get_result($user_check_stmt2);
                    if ($u_row = mysqli_fetch_assoc($u_res)) {
                        $user_id_for_employee = (int)$u_row['id'];
                    }
                    mysqli_stmt_close($user_check_stmt2);
                }
            }

            // Handle optional photo upload
            $photo_db_path = null;
            if (!empty($_FILES['employee_photo']['name']) && $_FILES['employee_photo']['error'] === UPLOAD_ERR_OK) {
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $file_ext = strtolower(pathinfo($_FILES['employee_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, $allowed_exts)) {
                    $photo_filename = 'emp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                    $target_dir = dirname(__DIR__) . '/uploads/employees';
                    if (!is_dir($target_dir)) {
                        @mkdir($target_dir, 0777, true);
                    }
                    $target_dest = $target_dir . '/' . $photo_filename;
                    if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $target_dest)) {
                        $photo_db_path = 'uploads/employees/' . $photo_filename;
                    }
                }
            }

            // Now insert the employee record, linking safely with NULLIF for foreign keys
            $user_id_param = ($user_id_for_employee && (int)$user_id_for_employee > 0) ? (int)$user_id_for_employee : 0;
            $dept_id_param = ($department_id > 0) ? (int)$department_id : 0;
            $pos_id_param = ($position_id > 0) ? (int)$position_id : 0;

            $query = "INSERT INTO employees (employee_id, first_name, middle_initial, last_name, suffix, email, user_id, phone, sss_number, philhealth_number, pagibig_number, tin_number, department_id, position_id, position, hire_date, employment_type, employment_basis, salary, daily_rate, photo, status)
                      VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, 'active')";
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ssssssisssssiissssdds", $employee_id, $first_name, $middle_initial, $last_name, $suffix, $email, $user_id_param, $phone, $sss, $philhealth, $pagibig, $tin, $dept_id_param, $pos_id_param, $position, $hire_date, $employment_type, $employment_basis, $salary, $daily_rate, $photo_db_path);
            } else {
                // Dynamic fallback if optional columns are absent in database schema
                $all_cols = [
                    'employee_id' => ['s', $employee_id],
                    'first_name' => ['s', $first_name],
                    'middle_initial' => ['s', $middle_initial],
                    'last_name' => ['s', $last_name],
                    'suffix' => ['s', $suffix],
                    'email' => ['s', $email],
                    'user_id' => ['nullif', $user_id_param],
                    'phone' => ['s', $phone],
                    'sss_number' => ['s', $sss],
                    'philhealth_number' => ['s', $philhealth],
                    'pagibig_number' => ['s', $pagibig],
                    'tin_number' => ['s', $tin],
                    'department_id' => ['nullif', $dept_id_param],
                    'position_id' => ['nullif', $pos_id_param],
                    'position' => ['s', $position],
                    'hire_date' => ['s', $hire_date],
                    'employment_type' => ['s', $employment_type],
                    'employment_basis' => ['s', $employment_basis],
                    'salary' => ['d', $salary],
                    'daily_rate' => ['d', $daily_rate],
                    'photo' => ['s', $photo_db_path],
                    'status' => ['s', 'active']
                ];

                $ins_cols = [];
                $ins_ph = [];
                $ins_types = '';
                $ins_params = [];

                foreach ($all_cols as $c_name => $c_def) {
                    if (hrColumnExists($conn, 'employees', $c_name)) {
                        $ins_cols[] = "`$c_name`";
                        if ($c_def[0] === 'nullif') {
                            $ins_ph[] = 'NULLIF(?,0)';
                            $ins_types .= 'i';
                            $ins_params[] = (int)$c_def[1];
                        } else {
                            $ins_ph[] = '?';
                            $ins_types .= $c_def[0];
                            $ins_params[] = $c_def[1];
                        }
                    }
                }

                if (!empty($ins_cols)) {
                    $dyn_query = "INSERT INTO employees (" . implode(', ', $ins_cols) . ") VALUES (" . implode(', ', $ins_ph) . ")";
                    $stmt = mysqli_prepare($conn, $dyn_query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $ins_types, ...$ins_params);
                    }
                }
            }

            if ($stmt) {

                if (mysqli_stmt_execute($stmt)) {
                    $new_emp_pk = mysqli_insert_id($conn);
                    if ($user_id_for_employee && $user_id_for_employee > 0) {
                        if ($photo_db_path) {
                            mysqli_query($conn, "UPDATE users SET profile_image = '" . mysqli_real_escape_string($conn, $photo_db_path) . "' WHERE id = " . (int)$user_id_for_employee);
                        }
                        require_once __DIR__ . '/../includes/rider_helper.php';
                        isDeliveryDriverUser($conn, $user_id_for_employee);
                    }
                    $_SESSION['success'] = (isset($_SESSION['success']) ? $_SESSION['success'] . " Employee added successfully." : "Employee added successfully.");
                } else {
                    $_SESSION['error'] = "Error adding employee: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $_SESSION['error'] = "Error preparing employee insert: " . mysqli_error($conn);
            }
        }
    }

    if (isset($_POST['update_employee'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.edit');
        }

        $id = intval($_POST['employee_id']);
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department_id = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? intval($_POST['department_id']) : 0;
        $position_id = intval($_POST['position_id'] ?? 0);
        $position_record = employeesFetchPositionRecord($conn, $position_id);
        if ($position_id > 0 && !$position_record) {
            $_SESSION['error'] = "Selected position was not found. Please choose a valid job position.";
            header("Location: employees.php");
            exit();
        }
        $position = $position_record['title'] ?? '';
        if ($department_id <= 0 && !empty($position_record['department_id'])) {
            $department_id = (int)$position_record['department_id'];
        }
        if ($is_partner_scoped_hr && $position_id > 0 && !hrPositionIdInScope($conn, $position_id)) {
            $_SESSION['error'] = "Selected position is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        if ($is_partner_scoped_hr && $department_id > 0 && !hrDepartmentIdInScope($conn, $department_id)) {
            $_SESSION['error'] = "Selected department is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }
        $hire_date = trim($_POST['hire_date'] ?? '');
        $employment_type = trim($_POST['employment_type'] ?? 'full_time');
        $employment_basis = trim($_POST['employment_basis'] ?? 'monthly');
        $base_salary_input = isset($_POST['base_salary']) && $_POST['base_salary'] !== '' ? floatval($_POST['base_salary']) : 0.00;

        $salary = 0.00;
        $daily_rate = 0.00;
        if ($employment_basis === 'monthly') {
            $salary = $base_salary_input;
        } else {
            $daily_rate = $base_salary_input;
        }
        $sss = trim($_POST['sss_number'] ?? '');
        $philhealth = trim($_POST['philhealth_number'] ?? '');
        $pagibig = trim($_POST['pagibig_number'] ?? '');
        $tin = trim($_POST['tin_number'] ?? '');

        if ($first_name === '' || $last_name === '' || $email === '' || $phone === '') {
            $_SESSION['error'] = "Please fill in all required personal fields (First Name, Last Name, Email, and Phone).";
            header("Location: employees.php");
            exit();
        }

        if ($position === '') {
            $position = 'Staff';
        }
        if ($hire_date === '') {
            $hire_date = date('Y-m-d');
        }
        $dept_id_param = ($department_id > 0) ? (int)$department_id : 0;
        $pos_id_param = ($position_id > 0) ? (int)$position_id : 0;

        $query = "UPDATE employees SET first_name=?, middle_initial=?, last_name=?, suffix=?, email=?, phone=?, department_id=NULLIF(?,0), position_id=NULLIF(?,0), position=?, hire_date=?, employment_type=?, employment_basis=?, salary=?, daily_rate=?, sss_number=?, philhealth_number=?, pagibig_number=?, tin_number=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssssssiissssddssssi", $first_name, $middle_initial, $last_name, $suffix, $email, $phone, $dept_id_param, $pos_id_param, $position, $hire_date, $employment_type, $employment_basis, $salary, $daily_rate, $sss, $philhealth, $pagibig, $tin, $id);
        } else {
            // Dynamic fallback update for employees
            $all_upd = [
                'first_name' => ['s', $first_name],
                'middle_initial' => ['s', $middle_initial],
                'last_name' => ['s', $last_name],
                'suffix' => ['s', $suffix],
                'email' => ['s', $email],
                'phone' => ['s', $phone],
                'department_id' => ['nullif', $dept_id_param],
                'position_id' => ['nullif', $pos_id_param],
                'position' => ['s', $position],
                'hire_date' => ['s', $hire_date],
                'employment_type' => ['s', $employment_type],
                'employment_basis' => ['s', $employment_basis],
                'salary' => ['d', $salary],
                'daily_rate' => ['d', $daily_rate],
                'sss_number' => ['s', $sss],
                'philhealth_number' => ['s', $philhealth],
                'pagibig_number' => ['s', $pagibig],
                'tin_number' => ['s', $tin],
            ];
            $upd_sets = [];
            $upd_types = '';
            $upd_params = [];
            foreach ($all_upd as $col => $cfg) {
                if (hrColumnExists($conn, 'employees', $col)) {
                    if ($cfg[0] === 'nullif') {
                        $upd_sets[] = "`$col` = NULLIF(?,0)";
                        $upd_types .= 'i';
                        $upd_params[] = (int)$cfg[1];
                    } else {
                        $upd_sets[] = "`$col` = ?";
                        $upd_types .= $cfg[0];
                        $upd_params[] = $cfg[1];
                    }
                }
            }
            if (!empty($upd_sets)) {
                $upd_query = "UPDATE employees SET " . implode(', ', $upd_sets) . " WHERE id = ?";
                $upd_types .= 'i';
                $upd_params[] = $id;
                $stmt = mysqli_prepare($conn, $upd_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, $upd_types, ...$upd_params);
                }
            }
        }
        if (!$stmt) {
            $_SESSION['error'] = "Error preparing employee update: " . mysqli_error($conn);
            header("Location: employees.php");
            exit();
        }

        if (mysqli_stmt_execute($stmt)) {
            // Handle optional photo upload
            if (!empty($_FILES['employee_photo']['name']) && $_FILES['employee_photo']['error'] === UPLOAD_ERR_OK) {
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $file_ext = strtolower(pathinfo($_FILES['employee_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, $allowed_exts)) {
                    $photo_filename = 'emp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                    $target_dir = dirname(__DIR__) . '/uploads/employees';
                    if (!is_dir($target_dir)) {
                        @mkdir($target_dir, 0777, true);
                    }
                    $target_dest = $target_dir . '/' . $photo_filename;
                    if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $target_dest)) {
                        $photo_db_path = 'uploads/employees/' . $photo_filename;
                        $photo_stmt = mysqli_prepare($conn, "UPDATE employees SET photo = ? WHERE id = ?");
                        if ($photo_stmt) {
                            mysqli_stmt_bind_param($photo_stmt, "si", $photo_db_path, $id);
                            mysqli_stmt_execute($photo_stmt);
                            mysqli_stmt_close($photo_stmt);
                        }
                    }
                }
            }

            // Find or link user_id if employee has a user account with matching email
            $emp_u_chk = mysqli_query($conn, "SELECT user_id, email FROM employees WHERE id = " . (int)$id . " LIMIT 1");
            $sync_user_id = 0;
            if ($emp_u_chk && ($e_row = mysqli_fetch_assoc($emp_u_chk))) {
                if (!empty($e_row['user_id'])) {
                    $sync_user_id = (int)$e_row['user_id'];
                } elseif (!empty($e_row['email'])) {
                    $u_chk = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
                    if ($u_chk) {
                        mysqli_stmt_bind_param($u_chk, "s", $e_row['email']);
                        mysqli_stmt_execute($u_chk);
                        $u_res = mysqli_stmt_get_result($u_chk);
                        if ($u_r = mysqli_fetch_assoc($u_res)) {
                            $sync_user_id = (int)$u_r['id'];
                            mysqli_query($conn, "UPDATE employees SET user_id = $sync_user_id WHERE id = " . (int)$id . " LIMIT 1");
                        }
                        mysqli_stmt_close($u_chk);
                    }
                }
            }

            if ($sync_user_id > 0) {
                $full_name = formatEmployeeFullName($first_name, $middle_initial, $last_name, $suffix);
                $has_u_middle = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'middle_name');
                $has_u_suffix = function_exists('hrColumnExists') && hrColumnExists($conn, 'users', 'suffix');

                $u_upd = false;
                if ($has_u_middle && $has_u_suffix) {
                    $u_upd = mysqli_prepare($conn, "UPDATE users SET full_name = ?, middle_name = ?, suffix = ? WHERE id = ?");
                    if ($u_upd) {
                        mysqli_stmt_bind_param($u_upd, "sssi", $full_name, $middle_initial, $suffix, $sync_user_id);
                    }
                } elseif ($has_u_middle) {
                    $u_upd = mysqli_prepare($conn, "UPDATE users SET full_name = ?, middle_name = ? WHERE id = ?");
                    if ($u_upd) {
                        mysqli_stmt_bind_param($u_upd, "ssi", $full_name, $middle_initial, $sync_user_id);
                    }
                } elseif ($has_u_suffix) {
                    $u_upd = mysqli_prepare($conn, "UPDATE users SET full_name = ?, suffix = ? WHERE id = ?");
                    if ($u_upd) {
                        mysqli_stmt_bind_param($u_upd, "ssi", $full_name, $suffix, $sync_user_id);
                    }
                }

                if (!$u_upd) {
                    $u_upd = mysqli_prepare($conn, "UPDATE users SET full_name = ? WHERE id = ?");
                    if ($u_upd) {
                        mysqli_stmt_bind_param($u_upd, "si", $full_name, $sync_user_id);
                    }
                }

                if ($u_upd) {
                    mysqli_stmt_execute($u_upd);
                    mysqli_stmt_close($u_upd);
                }
            }

            if ($sync_user_id > 0) {
                require_once __DIR__ . '/../includes/rider_helper.php';
                isDeliveryDriverUser($conn, $sync_user_id);
            }

            $_SESSION['success'] = "Employee updated successfully.";
        } else {
            $_SESSION['error'] = "Error updating employee: " . mysqli_error($conn);
        }
        if ($stmt) mysqli_stmt_close($stmt);
    }

    if (isset($_POST['update_status'])) {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.edit');
        }

        $employee_id = intval($_POST['employee_id']);
        $new_status = hrSafeEnum(trim((string)($_POST['new_status'] ?? '')), employeesAllowedStatuses(), '');
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: employees.php");
            exit();
        }

        if ($employee_id <= 0 || $new_status === '') {
            $_SESSION['error'] = "Please select a valid employee status.";
            employeesRespond(false, $_SESSION['error']);
        } else {
            $context_stmt = mysqli_prepare($conn, "SELECT id, user_id, status FROM employees WHERE id = ? LIMIT 1");
            $employee_context = null;
            if ($context_stmt) {
                mysqli_stmt_bind_param($context_stmt, "i", $employee_id);
                mysqli_stmt_execute($context_stmt);
                $context_result = mysqli_stmt_get_result($context_stmt);
                $employee_context = $context_result ? mysqli_fetch_assoc($context_result) : null;
                mysqli_stmt_close($context_stmt);
            }

            if (!$employee_context) {
                $_SESSION['error'] = "Employee record was not found.";
                employeesRespond(false, $_SESSION['error']);
                header("Location: employees.php");
                exit();
            }

            if ((string)($employee_context['status'] ?? '') === $new_status) {
                $_SESSION['success'] = "Employee status is already set to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                employeesRespond(true, $_SESSION['success'], [
                    'new_status' => $new_status,
                    'status_label' => ucfirst(str_replace('_', ' ', $new_status)),
                    'status_badge_class' => 'badge-' . str_replace('_', '-', $new_status)
                ]);
                header("Location: employees.php");
                exit();
            }

            $query = "UPDATE employees SET status = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $new_status, $employee_id);
                if (mysqli_stmt_execute($stmt)) {
                    employeesSyncLinkedUserStatus($conn, (int)($employee_context['user_id'] ?? 0), $new_status);
                    if ($new_status === 'terminated') {
                        employeesEnsureTurnoverRecord($conn, $employee_id, (int)($_SESSION['user_id'] ?? 0));
                    }
                    $_SESSION['success'] = "Employee status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                    employeesRespond(true, $_SESSION['success'], [
                        'new_status' => $new_status,
                        'status_label' => ucfirst(str_replace('_', ' ', $new_status)),
                        'status_badge_class' => 'badge-' . str_replace('_', '-', $new_status),
                        'employee_id' => $employee_id
                    ]);
                } else {
                    $_SESSION['error'] = "Unable to update status: " . mysqli_error($conn);
                    employeesRespond(false, $_SESSION['error']);
                }
                mysqli_stmt_close($stmt);
            } else {
                $_SESSION['error'] = "Failed to prepare employee status update: " . mysqli_error($conn);
                employeesRespond(false, $_SESSION['error']);
            }
        }
    }
    // Redirect after POST action to prevent form resubmission
    header("Location: employees.php");
    exit();
}

// Get filter
$search = isset($_GET['search']) ? $_GET['search'] : ''; // Search term
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$where_clauses = [];
$params = [];
$param_types = '';

if ($search !== '') {
    $where_clauses[] = "(e.first_name LIKE ? OR e.middle_initial LIKE ? OR e.last_name LIKE ? OR e.suffix LIKE ? OR e.email LIKE ? OR CONCAT_WS(' ', e.first_name, e.middle_initial, e.last_name, e.suffix) LIKE ?)";
    $search_like = "%{$search}%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $param_types .= 'ssssss';
}
if ($department_filter !== '') {
    $where_clauses[] = "e.department_id = ?";
    $params[] = intval($department_filter);
    $param_types .= 'i';
}
if ($status_filter !== '') {
    $where_clauses[] = "e.status = ?"; // Add status filter
    $params[] = $status_filter;
    $param_types .= 's';
}
if ($is_partner_scoped_hr) {
    $where_clauses[] = $employee_scope_sql;
}

$where_clause = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

$has_dept = hrTableExists($conn, 'departments');
$has_jp = hrTableExists($conn, 'job_positions');
$has_pos_col = hrColumnExists($conn, 'employees', 'position_id');

$dept_col = $has_dept ? "d.department_name" : "'' AS department_name";
$dept_join = $has_dept ? "LEFT JOIN departments d ON e.department_id = d.id" : "";
$jp_col = ($has_jp && $has_pos_col) ? "COALESCE(jp.position_title, e.position) AS position_label" : "COALESCE(e.position, 'Staff') AS position_label";
$jp_join = ($has_jp && $has_pos_col) ? "LEFT JOIN job_positions jp ON jp.id = e.position_id" : "";

$employees_query = "SELECT e.*, {$dept_col}, u.user_type, u.email as user_email, u.profile_image as user_profile_image,
                           r.name as system_role_name, r.id as system_role_id,
                           {$jp_col}
                    FROM employees e
                    {$dept_join}
                    {$jp_join}
                    LEFT JOIN users u ON e.user_id = u.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    $where_clause
                    ORDER BY e.first_name ASC";

$employees_list = [];
try {
    $employees_stmt = @mysqli_prepare($conn, $employees_query);
    if ($employees_stmt === false) {
        $_SESSION['error'] = "Unable to load employees.";
        $employees_result = false;
    } else {
        if (count($params) > 0) {
            $bind_params = [$employees_stmt, $param_types];
            foreach ($params as $key => $value) {
                $bind_params[] = &$params[$key];
            }
            call_user_func_array('mysqli_stmt_bind_param', $bind_params);
        }

        mysqli_stmt_execute($employees_stmt);
        $employees_result = mysqli_stmt_get_result($employees_stmt);
        if ($employees_result) {
            while ($emp_row = mysqli_fetch_assoc($employees_result)) {
                $employees_list[] = $emp_row;
            }
        }
        mysqli_stmt_close($employees_stmt);
    }
} catch (Throwable $e) {
    error_log("Error loading employees query: " . $e->getMessage());
    $employees_list = [];
}
$total_employees_count = count($employees_list);

if (!function_exists('getEmployeeAvatarGradient')) {
    function getEmployeeAvatarGradient($name) {
        $gradients = [
            'linear-gradient(135deg, #f43f5e, #fb7185)',
            'linear-gradient(135deg, #6366f1, #818cf8)',
            'linear-gradient(135deg, #0ea5e9, #38bdf8)',
            'linear-gradient(135deg, #10b981, #34d399)',
            'linear-gradient(135deg, #f59e0b, #fbbf24)',
            'linear-gradient(135deg, #8b5cf6, #a78bfa)',
            'linear-gradient(135deg, #ec4899, #f472b6)',
            'linear-gradient(135deg, #14b8a6, #2dd4bf)',
            'linear-gradient(135deg, #3b82f6, #60a5fa)',
            'linear-gradient(135deg, #d97706, #f59e0b)'
        ];
        $idx = abs(crc32((string)$name)) % count($gradients);
        return $gradients[$idx];
    }
}

// Centralized department and position catalogs.
$departments = hrFetchDepartmentCatalog($conn, $is_partner_scoped_hr ? $department_scope_sql : '1=1');
$position_catalog = hrFetchPositionCatalog(
    $conn,
    $is_partner_scoped_hr ? $department_scope_sql : '1=1',
    $is_partner_scoped_hr ? $employee_scope_sql : '1=1'
);

// Get roles for user creation
$has_dept_table = hrTableExists($conn, 'departments');
$has_role_dept_col = hrColumnExists($conn, 'roles', 'department_id');
if ($has_dept_table && $has_role_dept_col) {
    $roles_query_sql = "
        SELECT r.id, r.name, d.department_name
        FROM roles r
        LEFT JOIN departments d ON r.department_id = d.id
        WHERE r.name != 'super_admin'
    ";
} else {
    $roles_query_sql = "
        SELECT r.id, r.name, '' AS department_name
        FROM roles r
        WHERE r.name != 'super_admin'
    ";
}
if ($is_partner_scoped_hr) {
    if (employeesRoleOwnerColumnExists($conn) && $partner_scope_owner_id > 0) {
        $roles_query_sql .= " AND r.owner_user_id = " . (int)$partner_scope_owner_id;
    } else {
        $roles_query_sql .= " AND 1=0";
    }
}
$roles_query_sql .= " ORDER BY " . ($has_dept_table && $has_role_dept_col ? "d.department_name, " : "") . "r.name";
$roles_query = @mysqli_query($conn, $roles_query_sql);
$roles = [];
if ($roles_query instanceof mysqli_result) {
    while ($r = mysqli_fetch_assoc($roles_query)) {
        $roles[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - HR Management</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        /* Modern Employee Modal UI/UX Tokens */
        .emp-modal .modal-content {
            border: 1px solid #eaecf0;
            border-radius: 16px;
            box-shadow: 0 20px 45px rgba(16, 24, 40, 0.12);
            overflow: hidden;
            background: #ffffff;
        }

        .emp-modal .modal-header {
            padding: 20px 24px;
            background: #ffffff;
            border-bottom: 1px solid #eaecf0;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .emp-modal .modal-header-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #fff1f0;
            color: #b3261e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            border: 1px solid #fee4e2;
        }

        .emp-modal .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #101828;
            margin: 0;
            line-height: 1.3;
        }

        .emp-modal .modal-subtitle {
            font-size: 13px;
            color: #475467;
            margin: 3px 0 0;
            line-height: 1.4;
        }

        .emp-modal .btn-close {
            margin-left: auto;
            background-color: #f2f4f7;
            border-radius: 8px;
            opacity: 0.7;
            transition: all 0.18s ease;
            padding: 8px;
        }

        .emp-modal .btn-close:hover {
            opacity: 1;
            background-color: #fee4e2;
        }

        .emp-modal .modal-body {
            padding: 20px 24px;
            background: #f8f9fa;
            max-height: calc(85vh - 140px);
            overflow-y: auto;
        }

        /* Form Section Cards */
        .emp-section-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }

        .emp-section-card:last-child {
            margin-bottom: 0;
        }

        .emp-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f2f4f7;
        }

        .emp-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1d2939;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .emp-section-title i {
            color: #b3261e;
            font-size: 14px;
        }

        .emp-req-pill {
            font-size: 11px;
            font-weight: 600;
            color: #b3261e;
            background: #fff1f0;
            border: 1px solid #fee4e2;
            padding: 2px 8px;
            border-radius: 6px;
            letter-spacing: 0.02em;
        }

        .emp-form-group {
            margin-bottom: 0;
        }

        .emp-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 6px;
        }

        .emp-req-star {
            color: #b3261e !important;
            font-weight: 700;
            margin-left: 2px;
        }

        .emp-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .emp-input-icon {
            position: absolute;
            left: 14px;
            color: #98a2b3;
            font-size: 14px;
            pointer-events: none;
            z-index: 2;
        }

        .emp-input-wrap .form-control,
        .emp-input-wrap .form-select {
            padding-left: 40px !important;
        }

        .emp-modal .form-control,
        .emp-modal .form-select {
            border: 1px solid #d0d5dd;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13.5px;
            color: #101828;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
            transition: all 0.2s ease;
        }

        .emp-modal .form-control:focus,
        .emp-modal .form-select:focus {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            outline: none;
        }

        .emp-modal .form-control::placeholder {
            color: #98a2b3;
            font-size: 13px;
        }

        .emp-helper-text {
            font-size: 12px;
            color: #667085;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* User Account Toggle Card */
        .emp-toggle-card {
            background: #f8f9fa;
            border: 1px solid #eaecf0;
            border-radius: 12px;
            padding: 16px 18px;
            transition: all 0.2s ease;
        }

        .emp-toggle-card:hover {
            border-color: #d0d5dd;
        }

        .emp-toggle-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }

        .emp-toggle-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .emp-toggle-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #eaecf0;
            color: #b3261e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .emp-toggle-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #101828;
            margin: 0;
        }

        .emp-toggle-desc {
            font-size: 12px;
            color: #667085;
            margin: 2px 0 0;
        }

        .emp-switch-input {
            width: 44px;
            height: 24px;
            cursor: pointer;
        }

        /* Modal Footer */
        .emp-modal .modal-footer {
            padding: 16px 24px;
            background: #ffffff;
            border-top: 1px solid #eaecf0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .emp-btn-cancel {
            background: #ffffff;
            border: 1px solid #d0d5dd;
            color: #344054;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.18s ease;
        }

        .emp-btn-cancel:hover {
            background: #f8f9fa;
            border-color: #98a2b3;
            color: #1d2939;
        }

        .emp-btn-primary {
            background: #b3261e;
            border: 1px solid #b3261e;
            color: #ffffff;
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 1px 3px rgba(179, 38, 30, 0.2);
            transition: all 0.18s ease;
        }

        .emp-btn-primary:hover {
            background: #981b15;
            border-color: #981b15;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(179, 38, 30, 0.25);
        }

        /* Dark Mode Tokens */
        body.dark-mode .emp-modal .modal-content {
            background: #181d26 !important;
            border-color: #27303f !important;
        }

        body.dark-mode .emp-modal .modal-header,
        body.dark-mode .emp-modal .modal-footer {
            background: #181d26 !important;
            border-color: #27303f !important;
        }

        body.dark-mode .emp-modal .modal-body {
            background: #0f1319 !important;
        }

        body.dark-mode .emp-modal .modal-title {
            color: #f8fafc !important;
        }

        body.dark-mode .emp-modal .modal-subtitle {
            color: #94a3b8 !important;
        }

        body.dark-mode .emp-modal .modal-header-icon {
            background: #2a1818 !important;
            border-color: #4c1d1d !important;
            color: #ff6b6b !important;
        }

        body.dark-mode .emp-section-card {
            background: #181d26 !important;
            border-color: #27303f !important;
        }

        body.dark-mode .emp-section-head {
            border-bottom-color: #27303f !important;
        }

        body.dark-mode .emp-section-title {
            color: #f1f5f9 !important;
        }

        body.dark-mode .emp-section-title i {
            color: #ff6b6b !important;
        }

        body.dark-mode .emp-label {
            color: #cbd5e1 !important;
        }

        body.dark-mode .emp-modal .form-control,
        body.dark-mode .emp-modal .form-select {
            background: #222936 !important;
            border-color: #2e3848 !important;
            color: #f8fafc !important;
        }

        body.dark-mode .emp-modal .form-control:focus,
        body.dark-mode .emp-modal .form-select:focus {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2) !important;
        }

        body.dark-mode .emp-modal .form-control::placeholder {
            color: #64748b !important;
        }

        body.dark-mode .emp-toggle-card {
            background: #13171f !important;
            border-color: #27303f !important;
        }

        body.dark-mode .emp-toggle-icon {
            background: #1e2430 !important;
            border-color: #2e3848 !important;
            color: #ff6b6b !important;
        }

        body.dark-mode .emp-toggle-title {
            color: #f8fafc !important;
        }

        body.dark-mode .emp-toggle-desc {
            color: #94a3b8 !important;
        }

        body.dark-mode .emp-btn-cancel {
            background: #222936 !important;
            border-color: #2e3848 !important;
            color: #e2e8f0 !important;
        }

        body.dark-mode .emp-btn-cancel:hover {
            background: #2a3444 !important;
        }

        /* =========================================================
           TIIMI-STYLE MODERN EMPLOYEE CARD DIRECTORY STYLES
           ========================================================= */
        .emp-directory-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 20px;
        }

        .emp-directory-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #101828;
            margin: 0;
            letter-spacing: -0.02em;
        }

        body.dark-mode .emp-directory-title {
            color: #f8fafc;
        }

        .emp-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (min-width: 1200px) {
            .emp-card-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .emp-card {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 16px;
            padding: 18px 16px 14px 16px;
            position: relative;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04), 0 1px 2px rgba(16, 24, 40, 0.02);
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .emp-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -4px rgba(16, 24, 40, 0.08), 0 4px 6px -2px rgba(16, 24, 40, 0.03);
            border-color: #d0d5dd;
        }

        body.dark-mode .emp-card {
            background: #181d26 !important;
            border-color: #27303f !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
        }

        body.dark-mode .emp-card:hover {
            border-color: #3b4758 !important;
            box-shadow: 0 12px 24px -4px rgba(0, 0, 0, 0.4);
        }

        .emp-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            position: relative;
        }

        .emp-card-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .emp-card-check {
            width: 18px;
            height: 18px;
            cursor: pointer;
            border-radius: 4px;
            border: 1.5px solid #d0d5dd;
        }

        .emp-card-badge-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .emp-status-pill {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            line-height: 1.2;
        }

        .emp-status-pill.active {
            background: #eefcf4;
            color: #12b76a;
            border: 1px solid #d1fadf;
        }

        .emp-status-pill.inactive {
            background: #fef3f2;
            color: #f04438;
            border: 1px solid #fee4e2;
        }

        .emp-status-pill.on_leave {
            background: #fffaeb;
            color: #b54708;
            border: 1px solid #fedf89;
        }

        .emp-status-pill.terminated {
            background: #f2f4f7;
            color: #344054;
            border: 1px solid #eaecf0;
        }

        .emp-more-btn {
            background: transparent;
            border: none;
            color: #98a2b3;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            padding: 0;
        }

        .emp-more-btn:hover {
            background: #f2f4f7;
            color: #344054;
        }

        body.dark-mode .emp-more-btn:hover {
            background: #27303f;
            color: #f8fafc;
        }

        .emp-avatar-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            margin-bottom: 10px;
        }

        .emp-avatar-circle {
            width: 72px;
            height: 72px;
            border-radius: 18px; /* NodoHR squircle shape */
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            flex-shrink: 0;
        }

        body.dark-mode .emp-avatar-circle {
            border-color: #27303f;
        }

        .emp-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .emp-avatar-initials {
            font-size: 26px;
            font-weight: 800;
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            user-select: none;
        }

        .emp-new-hire-tag {
            background: #0f172a;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            margin-top: -10px;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .emp-card-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #101828;
            text-align: center;
            margin: 4px 0 2px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        body.dark-mode .emp-card-name {
            color: #f8fafc;
        }

        .emp-card-role {
            font-size: 12px;
            color: #175cd3; /* NodoHR brand blue */
            text-align: center;
            font-weight: 600;
            margin-bottom: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        body.dark-mode .emp-card-role {
            color: #94a3b8;
        }

        .emp-meta-container {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-top: 1px solid #f2f4f7;
            border-bottom: 1px solid #f2f4f7;
            margin-bottom: 12px;
        }

        body.dark-mode .emp-meta-container {
            background: #13171f !important;
            border-color: #27303f !important;
        }

        .emp-meta-col {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .emp-meta-label {
            font-size: 11px;
            color: #667085;
            margin-bottom: 2px;
            text-transform: capitalize;
            font-weight: 500;
        }

        body.dark-mode .emp-meta-label {
            color: #94a3b8;
        }

        .emp-meta-val {
            font-size: 12.5px;
            font-weight: 600;
            color: #1d2939;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        body.dark-mode .emp-meta-val {
            color: #e2e8f0;
        }

        .emp-contact-list {
            margin-bottom: 10px;
            flex-grow: 1;
        }

        .emp-contact-item {
            display: flex;
            align-items: center;
            font-size: 12px;
            color: #475467;
            margin-bottom: 5px;
            overflow: hidden;
        }

        body.dark-mode .emp-contact-item {
            color: #94a3b8;
        }

        .emp-contact-item i {
            width: 14px;
            margin-right: 8px;
            color: #98a2b3;
            flex-shrink: 0;
            text-align: center;
        }

        .emp-contact-item span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .emp-account-strip {
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid #f2f4f7;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        body.dark-mode .emp-account-strip {
            border-top-color: #27303f;
        }

        .emp-role-tag {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .emp-role-tag.linked {
            background: #eff8ff;
            color: #175cd3;
            border: 1px solid #b2ddff;
        }

        body.dark-mode .emp-role-tag.linked {
            background: #172554;
            color: #93c5fd;
            border-color: #1e3a8a;
        }

        .emp-role-tag.unlinked {
            background: #f8f9fa;
            color: #667085;
            border: 1px dashed #d0d5dd;
            cursor: pointer;
        }

        body.dark-mode .emp-role-tag.unlinked {
            background: #1e2430;
            color: #94a3b8;
            border-color: #3b4758;
        }

        .emp-role-tag.unlinked:hover {
            color: #b3261e;
            border-color: #b3261e;
        }
    </style>
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Employees Management</h1>
                    <div class="topbar-right">
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                            <i class="fas fa-moon"></i>
                        </button>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name'] ?? ($_SESSION['full_name'] ?? 'Administrator')); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert" style="border-radius: 10px; font-weight: 500;">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string)$_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert" style="border-radius: 10px; font-weight: 500;">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string)$_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="emp-directory-toolbar">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h2 class="emp-directory-title"><?php echo $total_employees_count; ?> Employees</h2>
                        <?php
                        $recent_hire_count = 0;
                        $now_ts = time();
                        foreach ($employees_list as $e_check) {
                            if (!empty($e_check['hire_date']) && ($now_ts - strtotime($e_check['hire_date'])) <= (45 * 86400)) {
                                $recent_hire_count++;
                            }
                        }
                        if ($recent_hire_count > 0):
                        ?>
                            <span class="badge" style="background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 20px; border: 1px solid #cbd5e1;">
                                +<?php echo $recent_hire_count; ?> New Hire
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <!-- Filter Toggle Button -->
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleFilterBtn" onclick="toggleHrFilters()" style="background: #fff; border: 1px solid #d0d5dd; color: #344054; border-radius: 8px; font-weight: 600; padding: 7px 14px;">
                            <i class="fas fa-filter me-1" style="color: #667085;"></i> Filter
                            <?php if ($search !== '' || $department_filter !== '' || $status_filter !== ''): ?>
                                <span class="badge bg-danger rounded-pill ms-1" style="font-size: 9px;">Active</span>
                            <?php endif; ?>
                        </button>

                        <!-- Selected Counter Badge -->
                        <div id="selectionBadge" class="d-none align-items-center gap-2 px-3 py-1 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0; font-size: 13px; font-weight: 600; color: #344054;">
                            <span id="selectedCountNumber">0</span> Selected
                            <button type="button" class="btn btn-sm p-0 text-muted ms-1" onclick="clearSelectedEmployees()" title="Clear Selection">
                                <i class="fas fa-times-circle"></i>
                            </button>
                        </div>

                        <!-- View Switcher (Cards / Table) -->
                        <div class="btn-group" role="group" aria-label="View switch" style="border: 1px solid #d0d5dd; border-radius: 8px; overflow: hidden; background: #fff;">
                            <button type="button" class="btn btn-sm" id="viewModeCards" onclick="switchEmployeeView('cards')" title="Card Grid View" style="padding: 7px 13px; border: none; background: #f2f4f7; color: #101828;">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button type="button" class="btn btn-sm" id="viewModeTable" onclick="switchEmployeeView('table')" title="Table Spreadsheet View" style="padding: 7px 13px; border: none; background: transparent; color: #667085;">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>

                        <!-- Add Employee Primary CTA -->
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEmployeeModal" style="background: #b3261e; border-color: #b3261e; border-radius: 8px; font-weight: 600; padding: 7px 16px;">
                            <i class="fas fa-plus me-1"></i> Add Employee
                        </button>
                    </div>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>

                <!-- Filter Panel -->
                <div class="card hr-filter-panel mb-3 <?php echo ($search === '' && $department_filter === '' && $status_filter === '') ? 'd-none' : ''; ?>" id="hrFilterPanel">
                    <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <select name="department" class="form-select" onchange="this.form.submit()">
                                <option value="">All Departments</option>
                                <?php
                                foreach ($departments as $dept) {
                                    $dept_id = (int)($dept['id'] ?? 0);
                                    $dept_name = htmlspecialchars((string)($dept['department_name'] ?? ''));
                                    $selected = ((string)$department_filter === (string)$dept_id) ? 'selected' : '';
                                    echo "<option value=\"{$dept_id}\" {$selected}>{$dept_name}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="on_leave" <?php echo $status_filter === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                <option value="terminated" <?php echo $status_filter === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100" style="background: #b3261e; border-color: #b3261e;"><i class="fas fa-search"></i> Filter</button>
                            <?php if ($search !== '' || $department_filter !== '' || $status_filter !== ''): ?>
                                <a href="employees.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fas fa-undo"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                    </div>
                </div>
                
                <form method="POST" id="bulkActionForm">
                    <input type="hidden" name="action" id="bulkActionInput">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <!-- Bulk Action Floating Bar -->
                    <div id="bulkActionBar" class="d-none align-items-center gap-2 mb-3 p-2 px-3 rounded" style="background: #eff8ff; border: 1px solid #b2ddff;">
                        <span class="text-primary fw-semibold" style="font-size: 13px;"><i class="fas fa-check-square me-1"></i> Bulk Action:</span>
                        <select name="bulk_action" class="form-select form-select-sm" style="width: 220px; border-radius: 6px;">
                            <option value="">Select Action</option>
                            <option value="assign_department">Assign to Department</option>
                        </select>
                        <button type="button" id="applyBulkAction" class="btn btn-primary btn-sm" style="background: #b3261e; border-color: #b3261e; border-radius: 6px; font-weight: 600;">Apply</button>
                    </div>

                    <!-- ==========================================
                         CARDS GRID VIEW (Matching Tiimi Mockup)
                         ========================================== -->
                    <div id="employeesCardGrid" class="emp-card-grid">
                        <?php if (empty($employees_list)): ?>
                            <div class="col-12 text-center py-5" style="grid-column: 1 / -1; background: #ffffff; border-radius: 16px; border: 1px dashed #d0d5dd;">
                                <i class="fas fa-users-slash fa-3x mb-3 text-muted" style="opacity: 0.5;"></i>
                                <h5 class="fw-bold text-dark">No employees found</h5>
                                <p class="text-muted mb-0">Try adjusting your filters or click "+ Add Employee" to create a new profile.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($employees_list as $emp): 
                                $emp_id = (int)$emp['id'];
                                $emp_status = $emp['status'] ?? 'active';
                                $status_pill_class = match($emp_status) {
                                    'active' => 'active',
                                    'inactive' => 'inactive',
                                    'on_leave' => 'on_leave',
                                    'terminated' => 'terminated',
                                    default => 'active'
                                };
                                $status_label = ucwords(str_replace('_', ' ', $emp_status));

                                // Name & Position
                                $full_name = formatEmployeeFullName($emp['first_name'] ?? '', $emp['middle_initial'] ?? '', $emp['last_name'] ?? '', $emp['suffix'] ?? '');
                                $full_name_safe = htmlspecialchars($full_name);
                                $position_title = !empty($emp['position_label']) ? $emp['position_label'] : (!empty($emp['position']) ? $emp['position'] : 'Staff');
                                $department_title = !empty($emp['department_name']) ? $emp['department_name'] : 'Unassigned';

                                // Hire Date formatting (d/m/Y like reference screenshot)
                                $hire_date_raw = $emp['hire_date'] ?? '';
                                $hire_date_formatted = !empty($hire_date_raw) ? date('d M Y', strtotime($hire_date_raw)) : 'N/A';
                                $is_new_hire = (!empty($hire_date_raw) && (time() - strtotime($hire_date_raw)) <= (45 * 86400));

                                // Photo / Avatar
                                $photo_path = !empty($emp['photo']) ? '../' . ltrim($emp['photo'], '/') : (!empty($emp['user_profile_image']) ? '../' . ltrim($emp['user_profile_image'], '/') : '');
                                $has_real_photo = !empty($photo_path) && file_exists(dirname(__DIR__) . '/' . ltrim($photo_path, '../'));
                                $avatar_gradient = getEmployeeAvatarGradient($full_name);
                                $initials = strtoupper(substr($emp['first_name'] ?? 'E', 0, 1) . substr($emp['last_name'] ?? '', 0, 1));

                                // Contact info
                                $email_clean = htmlspecialchars((string)($emp['email'] ?? ''));
                                $phone_clean = htmlspecialchars((string)($emp['phone'] ?? ''));

                                // System Role Info
                                $has_user_account = !empty($emp['user_id']);
                                $system_role_name = !empty($emp['system_role_name']) ? getRoleDisplayName($emp['system_role_name']) : (!empty($emp['user_type']) ? ucfirst($emp['user_type']) : '');
                                $system_role_name = preg_replace('/\s*\(.*\)\s*/', '', (string)$system_role_name);

                                $emp_name_js = htmlspecialchars(json_encode($full_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                $emp_email_js = htmlspecialchars(json_encode((string)($emp['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                $system_role_id = (int)($emp['system_role_id'] ?? 0);
                            ?>
                            <div class="emp-card" id="empCard_<?php echo $emp_id; ?>">
                                <div class="emp-card-header">
                                    <div class="emp-card-header-left">
                                        <input type="checkbox" class="form-check-input emp-card-check employee-checkbox" name="employee_ids[]" value="<?php echo $emp_id; ?>" onchange="updateSelectedCount()">
                                        <span class="emp-status-pill <?php echo $status_pill_class; ?>" id="cardStatusPill_<?php echo $emp_id; ?>">
                                            <?php echo $status_label; ?>
                                        </span>
                                    </div>
                                    <div class="dropdown">
                                            <button type="button" class="emp-more-btn" data-bs-toggle="dropdown" aria-expanded="false" title="More Actions">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 10px; border: 1px solid #eaecf0; font-size: 13px; min-width: 175px;">
                                                <li>
                                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="loadEmployeeDetails(<?php echo $emp_id; ?>)" data-bs-toggle="modal" data-bs-target="#employeeModal">
                                                        <i class="fas fa-eye text-muted me-2"></i> View Profile
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="editEmployee(<?php echo $emp_id; ?>)">
                                                        <i class="fas fa-edit text-muted me-2"></i> Edit Details
                                                    </a>
                                                </li>
                                                <?php if ($has_user_account): ?>
                                                <li>
                                                    <a class="dropdown-item py-2 text-primary" href="javascript:void(0)" onclick="openReassignRoleModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $system_role_id; ?>)">
                                                        <i class="fas fa-user-shield me-2"></i> Reassign Role
                                                    </a>
                                                </li>
                                                <?php else: ?>
                                                <li>
                                                    <a class="dropdown-item py-2 text-primary" href="javascript:void(0)" onclick="openLinkUserModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $emp_email_js; ?>)">
                                                        <i class="fas fa-user-plus me-2"></i> Create Login
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li>
                                                    <a class="dropdown-item py-1 text-muted" href="attendance.php?employee_id=<?php echo $emp_id; ?>">
                                                        <i class="fas fa-calendar-check me-2"></i> Attendance
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item py-1 text-muted" href="schedules.php?employee_id=<?php echo $emp_id; ?>">
                                                        <i class="fas fa-clock me-2"></i> Schedules
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item py-1 text-muted" href="payroll.php?employee_id=<?php echo $emp_id; ?>">
                                                        <i class="fas fa-money-check-alt me-2"></i> Payroll
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Circular Avatar Section -->
                                <div class="emp-avatar-section">
                                    <div class="emp-avatar-circle" style="<?php echo !$has_real_photo ? 'background: ' . $avatar_gradient . ';' : ''; ?>">
                                        <?php if ($has_real_photo): ?>
                                            <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="<?php echo $full_name_safe; ?>" class="emp-avatar-img">
                                        <?php else: ?>
                                            <span class="emp-avatar-initials"><?php echo htmlspecialchars($initials); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($is_new_hire): ?>
                                        <span class="emp-new-hire-tag"><i class="fas fa-sparkles"></i> 1 New hire</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Full Name & Position Title -->
                                <h3 class="emp-card-name" title="<?php echo $full_name_safe; ?>" onclick="loadEmployeeDetails(<?php echo $emp_id; ?>)" data-bs-toggle="modal" data-bs-target="#employeeModal" style="cursor: pointer;">
                                    <?php echo $full_name_safe; ?>
                                </h3>
                                <div class="emp-card-role" title="<?php echo htmlspecialchars($position_title); ?>">
                                    <?php echo htmlspecialchars($position_title); ?>
                                </div>

                                <!-- 2-Column Rounded Box (Department & Date Hired) -->
                                <div class="emp-meta-container">
                                    <div class="emp-meta-col">
                                        <span class="emp-meta-label">Department</span>
                                        <span class="emp-meta-val" title="<?php echo htmlspecialchars($department_title); ?>">
                                            <?php echo htmlspecialchars($department_title); ?>
                                        </span>
                                    </div>
                                    <div class="emp-meta-col" style="text-align: right;">
                                        <span class="emp-meta-label">Hired Date</span>
                                        <span class="emp-meta-val"><?php echo $hire_date_formatted; ?></span>
                                    </div>
                                </div>

                                <!-- Contact Info Rows -->
                                <div class="emp-contact-list">
                                    <div class="emp-contact-item" title="<?php echo $email_clean; ?>">
                                        <i class="far fa-envelope"></i>
                                        <span><?php echo $email_clean !== '' ? $email_clean : 'No email'; ?></span>
                                    </div>
                                    <div class="emp-contact-item" title="<?php echo $phone_clean; ?>">
                                        <i class="fas fa-phone-alt"></i>
                                        <span><?php echo $phone_clean !== '' ? $phone_clean : 'No phone'; ?></span>
                                    </div>
                                </div>

                                <!-- User Account Role Strip -->
                                <div class="emp-account-strip">
                                    <?php if ($has_user_account): ?>
                                        <span class="emp-role-tag linked" title="Assigned Role: <?php echo htmlspecialchars($system_role_name); ?> (Click to reassign)" onclick="openReassignRoleModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $system_role_id; ?>)">
                                            <i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars($system_role_name); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="emp-role-tag unlinked" title="Click to assign system role & create login" onclick="openLinkUserModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $emp_email_js; ?>)">
                                            <i class="fas fa-plus-circle"></i> Assign Role
                                        </span>
                                    <?php endif; ?>
                                    
                                    <form method="POST" id="statusForm<?php echo $emp_id; ?>" style="display:inline; margin: 0;">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp_id; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <select name="new_status" class="form-select form-select-sm" data-current-status="<?php echo $emp_status; ?>" onchange="confirmStatusChange(this, <?php echo $emp_id; ?>)" style="font-size: 11px; padding: 2px 6px; width: auto; border-radius: 6px; border-color: #eaecf0;">
                                            <option value="">Status</option>
                                            <option value="active" <?php echo $emp_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $emp_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="on_leave" <?php echo $emp_status === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                            <option value="terminated" <?php echo $emp_status === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- ==========================================
                         TABLE VIEW (Alternative Spreadsheet View)
                         ========================================== -->
                    <div id="employeesTableView" class="table-responsive d-none">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAllEmployees(this)"></th>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Department</th>
                                    <th>Contact</th>
                                    <th>Date Hired</th>
                                    <th>Status</th>
                                    <th>Assigned Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees_list)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No employees found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($employees_list as $emp): 
                                        $emp_id = (int)$emp['id'];
                                        $emp_status = $emp['status'] ?? 'active';
                                        $status_class = 'badge-' . str_replace('_', '-', $emp_status);
                                        $full_name = formatEmployeeFullName($emp['first_name'] ?? '', $emp['middle_initial'] ?? '', $emp['last_name'] ?? '', $emp['suffix'] ?? '');
                                        $full_name_safe = htmlspecialchars($full_name);
                                        $position_title = !empty($emp['position_label']) ? $emp['position_label'] : (!empty($emp['position']) ? $emp['position'] : 'Staff');
                                        $dept_name = !empty($emp['department_name']) ? $emp['department_name'] : 'Unassigned';
                                        
                                        $photo_path = !empty($emp['photo']) ? '../' . ltrim($emp['photo'], '/') : (!empty($emp['user_profile_image']) ? '../' . ltrim($emp['user_profile_image'], '/') : '');
                                        $has_real_photo = !empty($photo_path) && file_exists(dirname(__DIR__) . '/' . ltrim($photo_path, '../'));
                                        $avatar_gradient = getEmployeeAvatarGradient($full_name);
                                        $initials = strtoupper(substr($emp['first_name'] ?? 'E', 0, 1) . substr($emp['last_name'] ?? '', 0, 1));
                                        
                                        $has_user_account = !empty($emp['user_id']);
                                        $system_role_name = !empty($emp['system_role_name']) ? getRoleDisplayName($emp['system_role_name']) : (!empty($emp['user_type']) ? ucfirst($emp['user_type']) : '');
                                        $system_role_name = preg_replace('/\s*\(.*\)\s*/', '', (string)$system_role_name);
                                        $system_role_id = (int)($emp['system_role_id'] ?? 0);
                                        $emp_name_js = htmlspecialchars(json_encode($full_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                        $emp_email_js = htmlspecialchars(json_encode((string)($emp['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input employee-checkbox" name="employee_ids[]" value="<?php echo $emp_id; ?>" onchange="updateSelectedCount()"></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; <?php echo !$has_real_photo ? 'background: ' . $avatar_gradient . ';' : ''; ?>">
                                                    <?php if ($has_real_photo): ?>
                                                        <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="<?php echo $full_name_safe; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                    <?php else: ?>
                                                        <span style="color: #fff; font-weight: 700; font-size: 13px;"><?php echo htmlspecialchars($initials); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark" style="font-size: 13.5px;"><?php echo $full_name_safe; ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars((string)($emp['employee_id'] ?? '')); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($position_title); ?></td>
                                        <td><?php echo htmlspecialchars($dept_name); ?></td>
                                        <td>
                                            <div style="font-size: 12px;"><?php echo htmlspecialchars((string)($emp['email'] ?? '')); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string)($emp['phone'] ?? '')); ?></small>
                                        </td>
                                        <td><?php echo !empty($emp['hire_date']) ? date('d/m/Y', strtotime($emp['hire_date'])) : 'N/A'; ?></td>
                                        <td id="employeeStatusCell<?php echo $emp_id; ?>">
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo ucwords(str_replace('_', ' ', $emp_status)); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($has_user_account): ?>
                                                <span class="emp-role-tag linked" onclick="openReassignRoleModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $system_role_id; ?>)">
                                                    <i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars($system_role_name); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="emp-role-tag unlinked" onclick="openLinkUserModal(<?php echo $emp_id; ?>, <?php echo $emp_name_js; ?>, <?php echo $emp_email_js; ?>)">
                                                    <i class="fas fa-plus-circle"></i> Assign Role
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn-icon" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="loadEmployeeDetails(<?php echo $emp_id; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn-icon" onclick="editEmployee(<?php echo $emp_id; ?>)" title="Edit Employee">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="attendance.php?employee_id=<?php echo $emp_id; ?>&date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn-icon" title="View Attendance">
                                                    <i class="fas fa-calendar-check"></i>
                                                </a>
                                                <a href="payroll.php?employee_id=<?php echo $emp_id; ?>" class="btn-icon" title="Run Payroll">
                                                    <i class="fas fa-money-check-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Employee Modal -->
    <div class="modal fade emp-modal" id="addEmployeeModal" tabindex="-1" aria-labelledby="addEmployeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" id="addEmployeeModalLabel">Add New Employee</h5>
                        <p class="modal-subtitle">Complete all required employee credentials, mandatory government IDs, and payroll details.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Section 1: Personal & Contact Information -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-user"></i> Personal & Contact Info</span>
                                <span class="emp-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employee Photo <span class="text-muted fw-normal" style="font-size: 11px;">(Optional &bull; JPG, PNG, WebP &bull; Max 5MB)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-camera emp-input-icon"></i>
                                            <input type="file" name="employee_photo" class="form-control" accept="image/jpeg,image/png,image/webp,image/jpg">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="emp-form-group">
                                        <label class="emp-label">First Name <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user emp-input-icon"></i>
                                            <input type="text" name="first_name" class="form-control" placeholder="e.g. Juan" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="emp-form-group">
                                        <label class="emp-label">M.I. <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-signature emp-input-icon"></i>
                                            <input type="text" name="middle_initial" class="form-control" placeholder="e.g. D." maxlength="10">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Last Name <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user emp-input-icon"></i>
                                            <input type="text" name="last_name" class="form-control" placeholder="e.g. Dela Cruz" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Suffix <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-tag emp-input-icon"></i>
                                            <input type="text" name="suffix" class="form-control" placeholder="e.g. Jr." maxlength="20">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Email Address <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-envelope emp-input-icon"></i>
                                            <input type="email" name="email" class="form-control" placeholder="juan.delacruz@company.com" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Phone Number <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-phone-alt emp-input-icon"></i>
                                            <input type="text" name="phone" class="form-control" placeholder="0917-123-4567" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Government Compliance IDs -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-id-card"></i> Government Compliance IDs</span>
                                <span class="emp-req-pill" style="background: #f1f5f9; color: #475467; border: 1px solid #cbd5e1;">Optional</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">SSS Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-shield-alt emp-input-icon"></i>
                                            <input type="text" name="sss_number" class="form-control" placeholder="XX-XXXXXXX-X">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">PhilHealth Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-heartbeat emp-input-icon"></i>
                                            <input type="text" name="philhealth_number" class="form-control" placeholder="XX-XXXXXXXXX-X">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Pag-IBIG Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-home emp-input-icon"></i>
                                            <input type="text" name="pagibig_number" class="form-control" placeholder="XXXX-XXXX-XXXX">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">TIN (Tax Identification) <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-file-invoice-dollar emp-input-icon"></i>
                                            <input type="text" name="tin_number" class="form-control" placeholder="XXX-XXX-XXX-XXX">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3: Department, Position & Compensation -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-briefcase"></i> Position & Compensation</span>
                                <span class="emp-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Department <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-building emp-input-icon"></i>
                                            <select name="department_id" id="add_department_id" class="form-select" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo (int)($dept['id'] ?? 0); ?>">
                                                        <?php echo htmlspecialchars((string)($dept['department_name'] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Position <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user-tag emp-input-icon"></i>
                                            <select name="position_id" id="add_position_id" class="form-select" required>
                                                <option value="">Select Position</option>
                                            </select>
                                        </div>
                                        <div class="emp-helper-text">
                                            <i class="fas fa-info-circle"></i> Synced from Recruitment (`job_positions`).
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Hire Date <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-calendar-alt emp-input-icon"></i>
                                            <input type="date" name="hire_date" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employment Type <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-clock emp-input-icon"></i>
                                            <select name="employment_type" class="form-select" required>
                                                <option value="full_time">Full Time</option>
                                                <option value="part_time">Part Time</option>
                                                <option value="contract">Contract</option>
                                                <option value="temporary">Temporary</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employment Basis <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-sliders-h emp-input-icon"></i>
                                            <select name="employment_basis" class="form-select" required>
                                                <option value="monthly">Monthly</option>
                                                <option value="daily">Daily</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Base Salary/Rate (&#8369;) <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-money-bill-wave emp-input-icon"></i>
                                            <input type="number" name="base_salary" class="form-control" step="0.01" min="0" required placeholder="e.g. 20000.00 or 570.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 4: Login Account (Optional Toggle) -->
                        <div class="emp-section-card">
                            <div class="emp-toggle-card">
                                <div class="emp-toggle-header" onclick="const chk = document.getElementById('createUserCheck'); chk.checked = !chk.checked; toggleUserFields();">
                                    <div class="emp-toggle-info">
                                        <div class="emp-toggle-icon">
                                            <i class="fas fa-user-shield"></i>
                                        </div>
                                        <div>
                                            <h6 class="emp-toggle-title">Create Login Account</h6>
                                            <p class="emp-toggle-desc">Enable system access credentials and dashboard permissions for this employee.</p>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0" onclick="event.stopPropagation()">
                                        <input type="checkbox" class="form-check-input emp-switch-input" id="createUserCheck" name="create_user" onchange="toggleUserFields()">
                                    </div>
                                </div>
                                
                                <div id="userFields" style="display:none; margin-top: 16px; padding-top: 16px; border-top: 1px solid #eaecf0;">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="emp-form-group">
                                                <label class="emp-label">System Role <span class="emp-req-star">*</span></label>
                                                <div class="emp-input-wrap">
                                                    <i class="fas fa-user-shield emp-input-icon"></i>
                                                    <select name="role_id" class="form-select">
                                                        <option value="">Select Role</option>
                                                        <?php foreach ($roles as $role): ?>
                                                            <?php
                                                                $display_name = $role['department_name'] 
                                                                    ? htmlspecialchars($role['department_name']) . ' (Dept. Role)' 
                                                                    : getRoleDisplayName($role['name']);
                                                            ?>
                                                            <option value="<?php echo $role['id']; ?>"><?php echo $display_name; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="emp-form-group">
                                                <label class="emp-label">Password <span class="emp-req-star">*</span></label>
                                                <div class="emp-input-wrap">
                                                    <i class="fas fa-lock emp-input-icon"></i>
                                                    <input type="password" name="password" class="form-control" placeholder="Set login password">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="emp-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_employee" value="1" class="emp-btn-primary">
                            <i class="fas fa-plus"></i> Add Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Employee Modal -->
    <div class="modal fade emp-modal" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" id="editEmployeeModalLabel">Edit Employee</h5>
                        <p class="modal-subtitle">Update staff records, mandatory government IDs, and compensation structure.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="update_employee" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="employee_id" id="edit_employee_id">
                        
                        <!-- Section 1: Personal & Contact Information -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-user"></i> Personal & Contact Info</span>
                                <span class="emp-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employee Photo <span class="text-muted fw-normal" style="font-size: 11px;">(Optional &bull; JPG, PNG, WebP)</span></label>
                                        <div class="d-flex align-items-center gap-3">
                                            <div id="edit_photo_preview_wrap" style="width: 52px; height: 52px; border-radius: 50%; overflow: hidden; border: 2px solid #eaecf0; flex-shrink: 0; background: #f2f4f7; display: flex; align-items: center; justify-content: center;">
                                                <img id="edit_photo_preview" src="" alt="Photo" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                                                <i id="edit_photo_placeholder" class="fas fa-user text-muted" style="font-size: 22px;"></i>
                                            </div>
                                            <div class="emp-input-wrap flex-grow-1">
                                                <i class="fas fa-camera emp-input-icon"></i>
                                                <input type="file" name="employee_photo" class="form-control" accept="image/jpeg,image/png,image/webp,image/jpg" onchange="previewEditEmployeePhoto(this)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="emp-form-group">
                                        <label class="emp-label">First Name <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user emp-input-icon"></i>
                                            <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="emp-form-group">
                                        <label class="emp-label">M.I. <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-signature emp-input-icon"></i>
                                            <input type="text" name="middle_initial" id="edit_middle_initial" class="form-control" placeholder="e.g. D." maxlength="10">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Last Name <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user emp-input-icon"></i>
                                            <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Suffix <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-tag emp-input-icon"></i>
                                            <input type="text" name="suffix" id="edit_suffix" class="form-control" placeholder="e.g. Jr." maxlength="20">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Email Address <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-envelope emp-input-icon"></i>
                                            <input type="email" name="email" id="edit_email" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Phone Number <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-phone-alt emp-input-icon"></i>
                                            <input type="text" name="phone" id="edit_phone" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Government Compliance IDs -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-id-card"></i> Government Compliance IDs</span>
                                <span class="emp-req-pill" style="background: #f1f5f9; color: #475467; border: 1px solid #cbd5e1;">Optional</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">SSS Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-shield-alt emp-input-icon"></i>
                                            <input type="text" name="sss_number" id="edit_sss" class="form-control" placeholder="XX-XXXXXXX-X">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">PhilHealth Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-heartbeat emp-input-icon"></i>
                                            <input type="text" name="philhealth_number" id="edit_philhealth" class="form-control" placeholder="XX-XXXXXXXXX-X">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Pag-IBIG Number <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-home emp-input-icon"></i>
                                            <input type="text" name="pagibig_number" id="edit_pagibig" class="form-control" placeholder="XXXX-XXXX-XXXX">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">TIN (Tax Identification) <span class="text-muted fw-normal" style="font-size: 11px;">(Optional)</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-file-invoice-dollar emp-input-icon"></i>
                                            <input type="text" name="tin_number" id="edit_tin" class="form-control" placeholder="XXX-XXX-XXX-XXX">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3: Department, Position & Compensation -->
                        <div class="emp-section-card">
                            <div class="emp-section-head">
                                <span class="emp-section-title"><i class="fas fa-briefcase"></i> Position & Compensation</span>
                                <span class="emp-req-pill">Required</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Department <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-building emp-input-icon"></i>
                                            <select name="department_id" id="edit_department_id" class="form-select" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo (int)($dept['id'] ?? 0); ?>">
                                                        <?php echo htmlspecialchars((string)($dept['department_name'] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Position <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-user-tag emp-input-icon"></i>
                                            <select name="position_id" id="edit_position_id" class="form-select" required>
                                                <option value="">Select Position</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Hire Date <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-calendar-alt emp-input-icon"></i>
                                            <input type="date" name="hire_date" id="edit_hire_date" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employment Type <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-clock emp-input-icon"></i>
                                            <select name="employment_type" id="edit_employment_type" class="form-select" required>
                                                <option value="full_time">Full Time</option>
                                                <option value="part_time">Part Time</option>
                                                <option value="contract">Contract</option>
                                                <option value="temporary">Temporary</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Employment Basis <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-sliders-h emp-input-icon"></i>
                                            <select name="employment_basis" id="edit_employment_basis" class="form-select" required>
                                                <option value="monthly">Monthly</option>
                                                <option value="daily">Daily</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emp-form-group">
                                        <label class="emp-label">Base Salary/Rate (&#8369;) <span class="emp-req-star">*</span></label>
                                        <div class="emp-input-wrap">
                                            <i class="fas fa-money-bill-wave emp-input-icon"></i>
                                            <input type="number" name="base_salary" id="edit_base_salary" class="form-control" step="0.01" min="0" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="emp-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="emp-btn-primary">
                            <i class="fas fa-save"></i> Update Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Employee Details Modal -->
    <div class="modal fade" id="employeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Employee Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="employeeDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Link User Modal -->
    <div class="modal fade" id="linkUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Link/Create User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="link_user_account" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="employee_id_to_link" id="linkEmployeeId">
                        
                        <p>For Employee: <strong id="linkEmployeeName"></strong></p>
                        <p>Email: <strong id="linkEmployeeEmail"></strong></p>
                        
                        <hr>
                        
                        <div class="form-group mb-2">
                            <label>System Role *</label>
                            <select name="role_id" class="form-select" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <?php
                                        $display_name = $role['department_name'] 
                                            ? htmlspecialchars($role['department_name']) . ' (Dept. Role)' 
                                            : getRoleDisplayName($role['name']);
                                    ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo $display_name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" class="form-control" placeholder="Set login password" required>
                        </div>
                        <small class="text-muted">This will create a new user account with the employee's email and link it to their employee record.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create & Link Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reassign System Role Modal -->
    <div class="modal fade emp-modal" id="reassignRoleModal" tabindex="-1" aria-labelledby="reassignRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <h5 class="modal-title" id="reassignRoleModalLabel">Assign / Change System Role</h5>
                        <p class="modal-subtitle">Update system access permissions for this staff member.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="reassign_user_role">
                    <input type="hidden" name="employee_id" id="reassign_employee_id">
                    <div class="modal-body" style="padding: 24px; background: #ffffff;">
                        <div class="mb-3">
                            <label class="emp-label">Employee</label>
                            <input type="text" id="reassign_employee_name" class="form-control" readonly style="background: #f8f9fa; font-weight: 600;">
                        </div>
                        <div class="mb-3">
                            <label class="emp-label">Select System Role <span class="emp-req-star">*</span></label>
                            <select name="new_role_id" id="reassign_role_id" class="form-select" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $r_item): 
                                    $r_disp = !empty($r_item['department_name']) 
                                        ? getRoleDisplayName($r_item['name']) . " (" . $r_item['department_name'] . ")"
                                        : getRoleDisplayName($r_item['name']);
                                ?>
                                    <option value="<?php echo (int)$r_item['id']; ?>"><?php echo htmlspecialchars($r_disp); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="emp-helper-text mt-2" style="font-size: 12px; color: #475467;">
                                <i class="fas fa-info-circle text-primary"></i> Once assigned, the employee's dashboard and topbar will immediately reflect their assigned role (e.g. <strong>Role: Finance Manager</strong>).
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="emp-btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="emp-btn-primary">
                            <i class="fas fa-check"></i> Save Role Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
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

        // Session Messages
        <?php if (!empty($_SESSION['success'])): ?>
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: <?php echo json_encode((string)$_SESSION['success']); ?>,
                    timer: 2500,
                    showConfirmButton: false
                });
            }
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: <?php echo json_encode((string)$_SESSION['error']); ?>
                });
            }
        <?php unset($_SESSION['error']); endif; ?>

        const positionCatalogByDepartment = <?php echo json_encode($position_catalog['by_department'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const positionCatalogAll = <?php echo json_encode($position_catalog['all'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function getPositionsForDepartment(departmentId) {
            const normalizedDepartmentId = String(departmentId || '');
            const scoped = positionCatalogByDepartment[normalizedDepartmentId] || [];
            const fallback = positionCatalogByDepartment['0'] || [];
            const merged = [...scoped, ...fallback, ...positionCatalogAll];
            const byId = new Map();
            merged.forEach((position) => {
                if (!position || !position.id) {
                    return;
                }
                byId.set(String(position.id), position);
            });
            return Array.from(byId.values()).sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function syncPositionSelect(mode) {
            const departmentEl = document.getElementById(`${mode}_department_id`);
            const selectEl = document.getElementById(`${mode}_position_id`);
            if (!departmentEl || !selectEl) {
                return;
            }

            const currentValue = selectEl.value;
            const options = getPositionsForDepartment(departmentEl.value);
            const optionHtml = ['<option value="">Select Position</option>']
                .concat(options.map((position) => `<option value="${position.id}" data-department-id="${position.department_id || 0}">${escapeHtml(position.title)}</option>`))
                .join('');
            selectEl.innerHTML = optionHtml;

            if (currentValue && options.some((position) => String(position.id) === String(currentValue))) {
                selectEl.value = currentValue;
            } else {
                selectEl.value = '';
            }
        }

        function setPositionSelection(mode, positionId) {
            const selectEl = document.getElementById(`${mode}_position_id`);
            if (!selectEl) {
                return;
            }

            const normalizedPositionId = Number(positionId || 0);
            if (normalizedPositionId <= 0) {
                selectEl.value = '';
                return;
            }

            const optionExists = Array.from(selectEl.options).some((option) => Number(option.value || 0) === normalizedPositionId);
            if (optionExists) {
                selectEl.value = String(normalizedPositionId);
            }
        }

        function bindPositionControls(mode) {
            const departmentEl = document.getElementById(`${mode}_department_id`);
            const selectEl = document.getElementById(`${mode}_position_id`);
            if (!departmentEl || !selectEl) {
                return;
            }

            departmentEl.addEventListener('change', () => syncPositionSelect(mode));
            syncPositionSelect(mode);
        }

        bindPositionControls('add');
        bindPositionControls('edit');

        function loadEmployeeDetails(employeeId) {
            $.ajax({
                url: 'get_employee_details.php',
                type: 'GET',
                data: { id: employeeId },
                success: function(response) {
                    $('#employeeDetails').html(response);
                },
                error: function() {
                    $('#employeeDetails').html('<p class="text-danger">Error loading employee details</p>');
                }
            });
        }

        function previewEditEmployeePhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#edit_photo_preview').attr('src', e.target.result).show();
                    $('#edit_photo_placeholder').hide();
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function editEmployee(employeeId) {
            $.ajax({
                url: 'get_employee_details.php',
                type: 'GET',
                data: { id: employeeId, json: 1 },
                dataType: 'json',
                success: function(data) {
                    if (!data) return;
                    
                    $('#edit_employee_id').val(data.id);
                    $('#edit_first_name').val(data.first_name);
                    $('#edit_middle_initial').val(data.middle_initial || '');
                    $('#edit_last_name').val(data.last_name);
                    $('#edit_suffix').val(data.suffix || '');
                    $('#edit_email').val(data.email);
                    $('#edit_phone').val(data.phone);
                    $('#edit_sss').val(data.sss_number);
                    $('#edit_philhealth').val(data.philhealth_number);
                    $('#edit_pagibig').val(data.pagibig_number);
                    $('#edit_tin').val(data.tin_number);
                    $('#edit_department_id').val(data.department_id);
                    syncPositionSelect('edit');
                    setPositionSelection('edit', data.position_id);
                    $('#edit_hire_date').val(data.hire_date);
                    $('#edit_employment_type').val(data.employment_type);
                    $('#edit_employment_basis').val(data.employment_basis);
                    
                    let rate = data.employment_basis === 'monthly' ? data.salary : data.daily_rate;
                    $('#edit_base_salary').val(rate);

                    // Photo preview
                    if (data.photo) {
                        $('#edit_photo_preview').attr('src', '../' + data.photo.replace(/^\/+/, '')).show();
                        $('#edit_photo_placeholder').hide();
                    } else if (data.user_profile_image) {
                        $('#edit_photo_preview').attr('src', '../' + data.user_profile_image.replace(/^\/+/, '')).show();
                        $('#edit_photo_placeholder').hide();
                    } else {
                        $('#edit_photo_preview').attr('src', '').hide();
                        $('#edit_photo_placeholder').show();
                    }
                    
                    var editModal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
                    editModal.show();
                },
                error: function() {
                    Swal.fire('Error', 'Could not fetch employee details', 'error');
                }
            });
        }

        function confirmStatusChange(selectElement, empId) {
            if (!selectElement.value) return;
            const previousStatus = selectElement.getAttribute('data-current-status') || '';
            const nextStatus = selectElement.value;
            const form = document.getElementById('statusForm' + empId);
            
            Swal.fire({
                title: 'Update Status?',
                text: "Change employee status to " + nextStatus.replace('_', ' ') + "?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, update it!',
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    try {
                        const response = await fetch('employees.php', {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        if (!data.success) {
                            throw new Error(data.message || 'Unable to update employee status.');
                        }
                        return data;
                    } catch (error) {
                        Swal.showValidationMessage(error.message || 'Unable to update employee status.');
                        return false;
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    const statusCell = document.getElementById('employeeStatusCell' + empId);
                    if (statusCell) {
                        statusCell.innerHTML = "<span class='status-badge " + result.value.status_badge_class + "'>" + result.value.status_label + "</span>";
                    }
                    selectElement.setAttribute('data-current-status', result.value.new_status);
                    selectElement.value = result.value.new_status;
                    Swal.fire({
                        title: 'Updated',
                        text: result.value.message || 'Employee status updated successfully.',
                        icon: 'success',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    selectElement.value = previousStatus;
                }
            })
        }

        function toggleUserFields() {
            const isChecked = document.getElementById('createUserCheck').checked;
            document.getElementById('userFields').style.display = isChecked ? 'block' : 'none';
            const inputs = document.getElementById('userFields').querySelectorAll('input, select');
            inputs.forEach(input => input.required = isChecked);
        }

        function openLinkUserModal(employeeId, employeeName, employeeEmail) {
            document.getElementById('linkEmployeeId').value = employeeId;
            document.getElementById('linkEmployeeName').textContent = employeeName;
            document.getElementById('linkEmployeeEmail').textContent = employeeEmail;
            var linkModal = new bootstrap.Modal(document.getElementById('linkUserModal'));
            linkModal.show();
        }

        function openReassignRoleModal(employeeId, employeeName, currentRoleId) {
            document.getElementById('reassign_employee_id').value = employeeId;
            document.getElementById('reassign_employee_name').value = employeeName;
            const roleSelect = document.getElementById('reassign_role_id');
            if (roleSelect) {
                roleSelect.value = currentRoleId || '';
            }
            var reassignModal = new bootstrap.Modal(document.getElementById('reassignRoleModal'));
            reassignModal.show();
        }

        function switchEmployeeView(mode) {
            const cardGrid = document.getElementById('employeesCardGrid');
            const tableView = document.getElementById('employeesTableView');
            const btnCards = document.getElementById('viewModeCards');
            const btnTable = document.getElementById('viewModeTable');

            if (!cardGrid || !tableView) return;

            if (mode === 'table') {
                cardGrid.classList.add('d-none');
                tableView.classList.remove('d-none');
                if (btnCards) {
                    btnCards.style.background = 'transparent';
                    btnCards.style.color = '#667085';
                }
                if (btnTable) {
                    btnTable.style.background = '#f2f4f7';
                    btnTable.style.color = '#101828';
                }
                localStorage.setItem('employee_directory_view', 'table');
            } else {
                cardGrid.classList.remove('d-none');
                tableView.classList.add('d-none');
                if (btnCards) {
                    btnCards.style.background = '#f2f4f7';
                    btnCards.style.color = '#101828';
                }
                if (btnTable) {
                    btnTable.style.background = 'transparent';
                    btnTable.style.color = '#667085';
                }
                localStorage.setItem('employee_directory_view', 'cards');
            }
        }

        function toggleHrFilters() {
            const panel = document.getElementById('hrFilterPanel');
            if (panel) {
                panel.classList.toggle('d-none');
            }
        }

        function updateSelectedCount() {
            const checkedCount = document.querySelectorAll('.employee-checkbox:checked').length;
            const badge = document.getElementById('selectionBadge');
            const countNum = document.getElementById('selectedCountNumber');
            const bulkBar = document.getElementById('bulkActionBar');

            if (countNum) countNum.textContent = checkedCount;

            if (checkedCount > 0) {
                if (badge) {
                    badge.classList.remove('d-none');
                    badge.classList.add('d-flex');
                }
                if (bulkBar) {
                    bulkBar.classList.remove('d-none');
                    bulkBar.classList.add('d-flex');
                }
            } else {
                if (badge) {
                    badge.classList.add('d-none');
                    badge.classList.remove('d-flex');
                }
                if (bulkBar) {
                    bulkBar.classList.add('d-none');
                    bulkBar.classList.remove('d-flex');
                }
            }
        }

        function toggleSelectAllEmployees(source) {
            const checkboxes = document.querySelectorAll('.employee-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
            updateSelectedCount();
        }

        function clearSelectedEmployees() {
            const checkboxes = document.querySelectorAll('.employee-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            const selectAll = document.getElementById('selectAll');
            if (selectAll) selectAll.checked = false;
            updateSelectedCount();
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Restore active view (cards or table)
            const savedView = localStorage.getItem('employee_directory_view') || 'cards';
            switchEmployeeView(savedView);

            <?php if (!empty($_SESSION['success'])): ?>
            Swal.fire({
                title: 'Success',
                text: <?php echo json_encode((string)$_SESSION['success']); ?>,
                icon: 'success',
                confirmButtonColor: '#3085d6'
            });
            <?php unset($_SESSION['success']); endif; ?>

            <?php if (!empty($_SESSION['error'])): ?>
            Swal.fire({
                title: 'Action Needed',
                text: <?php echo json_encode((string)$_SESSION['error']); ?>,
                icon: 'error',
                confirmButtonColor: '#d33'
            });
            <?php unset($_SESSION['error']); endif; ?>
        });

        // Bulk Actions
        $('#selectAll').on('click', function() {
            $('.employee-checkbox').prop('checked', this.checked);
            updateSelectedCount();
        });

        $('#applyBulkAction').on('click', function() {
            const action = $('select[name="bulk_action"]').val();
            const selectedIds = $('.employee-checkbox:checked').map(function() {
                return this.value;
            }).get();

            if (selectedIds.length === 0) {
                Swal.fire('No Selection', 'Please select at least one employee.', 'warning');
                return;
            }

            if (action === 'assign_department') {
                $('#selectedCount').text(selectedIds.length);
                var assignModal = new bootstrap.Modal(document.getElementById('assignDepartmentModal'));
                assignModal.show();
            } else {
                Swal.fire('No Action', 'Please select a bulk action to perform.', 'info');
            }
        });

        $('#confirmAssignDepartment').on('click', function() {
            const departmentId = $('#bulkDepartmentId').val();
            if (!departmentId) {
                Swal.fire('No Department', 'Please select a department.', 'warning');
                return;
            }
            $('#bulkActionInput').val('bulk_assign_department');
            
            $('<input>').attr({ type: 'hidden', name: 'bulk_department_id', value: departmentId }).appendTo('#bulkActionForm');
            
            $('#bulkActionForm').submit();
        });
    </script>
</body>
</html>


