<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();

// Enforce page-level permission
$admin_info = getAdminInfo($conn);
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$partner_scope_owner_id = hrPartnerScopeOwnerUserId($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
hrEnsureNormalizedPositionModel($conn);
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

            $types = 'i' . str_repeat('i', count($employee_ids));
            $params_to_bind = array_merge([$department_id], $employee_ids);

            mysqli_stmt_bind_param($stmt, $types, ...$params_to_bind);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = count($employee_ids) . " employees assigned to the department.";
            } else {
                $_SESSION['error'] = "Failed to assign employees: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $_SESSION['error'] = "No employees or department selected for bulk assignment.";
        }
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
        mysqli_stmt_bind_param($emp_stmt, "i", $employee_id_to_link);
        mysqli_stmt_execute($emp_stmt);
        $emp_result = mysqli_stmt_get_result($emp_stmt);

        if ($emp_result && mysqli_num_rows($emp_result) > 0) {
            $employee = mysqli_fetch_assoc($emp_result);
            $email = $employee['email'];
            $full_name = $employee['first_name'] . ' ' . $employee['last_name'];
            $phone = $employee['phone'];

            // Check if user account with this email already exists
            $user_check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($user_check_stmt, "s", $email);
            mysqli_stmt_execute($user_check_stmt);
            $user_check_result = mysqli_stmt_get_result($user_check_stmt);

            if (mysqli_num_rows($user_check_result) > 0) {
                $_SESSION['error'] = "An account with this email already exists. Cannot create a new one.";
            } elseif (empty($user_password) || empty($user_role_id)) {
                $_SESSION['error'] = "Password and Role are required.";
            } else {
                // Create user account
                $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);
                $user_query = "INSERT INTO users (full_name, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, 'employee', ?, 1)";
                $stmt_user = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($stmt_user, "ssssi", $full_name, $email, $phone, $hashed_password, $user_role_id);
                
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
                    mysqli_stmt_bind_param($stmt_link, "ii", $new_user_id, $employee_id_to_link);
                    
                    if (mysqli_stmt_execute($stmt_link)) {
                        $_SESSION['success'] = "User account created and linked to employee successfully.";
                    } else {
                        $_SESSION['error'] = "Account created, but failed to link to employee record.";
                    }
                    mysqli_stmt_close($stmt_link);
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
        $last_name = trim($_POST['last_name'] ?? '');
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
        if (!empty($position_record['department_id'])) {
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

        $user_check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($user_check_stmt, "s", $email);
        mysqli_stmt_execute($user_check_stmt);
        $user_check_result = mysqli_stmt_get_result($user_check_stmt);

        if ($create_user && (empty($user_password) || empty($user_role_id))) {
            $_SESSION['error'] = "Password and Role are required when creating a user account.";
        } elseif ($create_user && mysqli_num_rows($user_check_result) > 0) {
            $_SESSION['error'] = "Email is already registered to a user account.";
        } elseif ($first_name === '' || $last_name === '' || $email === '' || $position_id <= 0 || $position === '' || $hire_date === '' || $base_salary_input <= 0) {
            $_SESSION['error'] = "Please fill in all required fields.";
        } else { // Proceed with adding employee
            $user_id_for_employee = null; // Initialize user_id to null

            // If creating a user account, do it first to get the user_id
            if ($create_user) {
                $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);
                $full_name = $first_name . ' ' . $last_name;
                // Changed user_type from 'admin' to 'employee'
                $user_query = "INSERT INTO users (full_name, email, phone, password, user_type, role_id, is_active) VALUES (?, ?, ?, ?, 'employee', ?, 1)";
                $stmt_user = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($stmt_user, "ssssi", $full_name, $email, $phone, $hashed_password, $user_role_id);
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
                            exit;
                        }
                    }
                    $_SESSION['success'] = "User account created successfully.";
                } else {
                    $_SESSION['error'] = "User account creation failed: " . mysqli_error($conn);
                    // If user creation fails, don't add employee record
                    mysqli_stmt_close($stmt_user);
                    header("Location: employees.php");
                    exit;
                }
                mysqli_stmt_close($stmt_user);
            }

            // Now insert the employee record, linking to the user_id if created
            $query = "INSERT INTO employees (employee_id, first_name, last_name, email, user_id, phone, sss_number, philhealth_number, pagibig_number, tin_number, department_id, position_id, position, hire_date, employment_type, employment_basis, salary, daily_rate, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, 'active')";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssisssssiissssdd", $employee_id, $first_name, $last_name, $email, $user_id_for_employee, $phone, $sss, $philhealth, $pagibig, $tin, $department_id, $position_id, $position, $hire_date, $employment_type, $employment_basis, $salary, $daily_rate);

            if ($stmt && mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = (isset($_SESSION['success']) ? $_SESSION['success'] . " Employee added successfully." : "Employee added successfully.");
            } else {
                $_SESSION['error'] = "Error adding employee: " . mysqli_error($conn);
            }
            if ($stmt) {
                mysqli_stmt_close($stmt);
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
        $last_name = trim($_POST['last_name'] ?? '');
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
        if (!empty($position_record['department_id'])) {
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

        if ($first_name === '' || $last_name === '' || $email === '' || $position_id <= 0 || $position === '' || $hire_date === '' || $base_salary_input <= 0) {
            $_SESSION['error'] = "Please fill in all required employee fields.";
            header("Location: employees.php");
            exit();
        }

        $query = "UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, department_id=NULLIF(?,0), position_id=?, position=?, hire_date=?, employment_type=?, employment_basis=?, salary=?, daily_rate=?, sss_number=?, philhealth_number=?, pagibig_number=?, tin_number=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssssiissssddssssi", $first_name, $last_name, $email, $phone, $department_id, $position_id, $position, $hire_date, $employment_type, $employment_basis, $salary, $daily_rate, $sss, $philhealth, $pagibig, $tin, $id);

        if ($stmt && mysqli_stmt_execute($stmt)) {
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
            mysqli_stmt_bind_param($stmt, "si", $new_status, $employee_id);

            if ($stmt && mysqli_stmt_execute($stmt)) {
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
                $_SESSION['error'] = "Unable to update status.";
                employeesRespond(false, $_SESSION['error']);
            }
            if ($stmt) {
                mysqli_stmt_close($stmt);
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
    $where_clauses[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)";
    $search_like = "%{$search}%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $param_types .= 'sss';
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

$employees_query = "SELECT e.*, d.department_name, u.user_type, u.email as user_email,
                           COALESCE(jp.position_title, e.position) AS position_label
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.id
                    LEFT JOIN job_positions jp ON jp.id = e.position_id
                    LEFT JOIN users u ON e.user_id = u.id
                    $where_clause
                    ORDER BY e.first_name ASC";

$employees_stmt = mysqli_prepare($conn, $employees_query);
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
}

// Centralized department and position catalogs.
$departments = hrFetchDepartmentCatalog($conn, $is_partner_scoped_hr ? $department_scope_sql : '1=1');
$position_catalog = hrFetchPositionCatalog(
    $conn,
    $is_partner_scoped_hr ? $department_scope_sql : '1=1',
    $is_partner_scoped_hr ? $employee_scope_sql : '1=1'
);

// Get roles for user creation
$roles_query_sql = "
    SELECT r.id, r.name, d.department_name
    FROM roles r
    LEFT JOIN departments d ON r.department_id = d.id
    WHERE r.name != 'super_admin'
";
if ($is_partner_scoped_hr) {
    if (employeesRoleOwnerColumnExists($conn) && $partner_scope_owner_id > 0) {
        $roles_query_sql .= " AND r.owner_user_id = " . (int)$partner_scope_owner_id;
    } else {
        $roles_query_sql .= " AND 1=0";
    }
}
$roles_query_sql .= " ORDER BY d.department_name, r.name";
$roles_query = mysqli_query($conn, $roles_query_sql);
$roles = [];
if ($roles_query) while ($r = mysqli_fetch_assoc($roles_query)) {
    $roles[] = $r;
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

        #addEmployeeModal .modal-body,
        #editEmployeeModal .modal-body {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }

        #addEmployeeModal .form-group,
        #editEmployeeModal .form-group {
            margin-bottom: 12px;
        }

        #addEmployeeModal .form-control,
        #addEmployeeModal .form-select,
        #editEmployeeModal .form-control,
        #editEmployeeModal .form-select {
            border-radius: 10px;
        }

        body.dark-mode #addEmployeeModal .modal-body,
        body.dark-mode #editEmployeeModal .modal-body {
            background: var(--card-bg-dark);
        }
    </style>
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Employees Management</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                
                <div class="section-header">
                    <h2>Employee List</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="fas fa-plus"></i> Add Employee
                    </button>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>

                <div class="card hr-filter-panel mb-3">
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
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Search</button>
                        </div>
                    </form>
                    </div>
                </div>
                
                <form method="POST" id="bulkActionForm">
                    <input type="hidden" name="action" id="bulkActionInput">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="d-flex gap-2 mb-3 align-items-center">
                        <select name="bulk_action" class="form-select" style="width: 200px;">
                            <option value="">Bulk Actions</option>
                            <option value="assign_department">Assign to Department</option>
                        </select>
                        <button type="button" id="applyBulkAction" class="btn btn-outline-primary">Apply</button>
                    </div>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="form-check-input" id="selectAll"></th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Salary</th>
                                <th>Status</th>
                                <th>User Account</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($employees_result && mysqli_num_rows($employees_result) > 0) {
                                while ($emp = mysqli_fetch_assoc($employees_result)) {
                                    $status_class = 'badge-' . str_replace('_', '-', $emp['status']);
                                    $dept_name = isset($emp['department_name']) ? $emp['department_name'] : 'Unassigned';
                                    $user_account_status = 'No Account';
                                    $user_account_class = 'badge-secondary';
                                    $employee_name_safe = htmlspecialchars(trim((string)($emp['first_name'] ?? '')) . ' ' . trim((string)($emp['last_name'] ?? '')));
                                    $employee_email_safe = htmlspecialchars((string)($emp['email'] ?? ''));
                                    $department_name_safe = htmlspecialchars((string)$dept_name);
                                    $position_label_safe = htmlspecialchars((string)($emp['position_label'] ?? ''));
                                    $employee_id_safe = htmlspecialchars((string)($emp['employee_id'] ?? ''));
                                    $employee_name_js = htmlspecialchars(json_encode(trim((string)($emp['first_name'] ?? '')) . ' ' . trim((string)($emp['last_name'] ?? '')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                    $employee_email_js = htmlspecialchars(json_encode((string)($emp['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                    if ($emp['user_id'] && $emp['user_type']) {
                                        $user_account_status = ucfirst($emp['user_type']);
                                        $user_account_class = 'badge-primary';
                                    }
                                    echo "
                                    <tr>
                                        <td><input type='checkbox' class='form-check-input employee-checkbox' name='employee_ids[]' value='{$emp['id']}'></td>
                                        <td><strong>{$employee_id_safe}</strong></td>
                                        <td>{$employee_name_safe}</td>
                                        <td>{$employee_email_safe}</td>
                                        <td>{$department_name_safe}</td>
                                        <td>{$position_label_safe}</td>
                                        <td>&#8369;" . number_format($emp['salary'] > 0 ? $emp['salary'] : $emp['daily_rate'], 2) . "</td>
                                        <td id='employeeStatusCell{$emp['id']}'><span class='status-badge $status_class'>" . ucfirst(str_replace('_', ' ', $emp['status'])) . "</span></td>
                                        <td><span class='status-badge $user_account_class'>$user_account_status</span></td>
                                        <td>
                                            <button type='button' class='btn-icon' data-bs-toggle='modal' data-bs-target='#employeeModal' onclick='loadEmployeeDetails({$emp['id']})' title='View Details'>
                                                <i class='fas fa-eye'></i>
                                            </button>
                                            <button type='button' class='btn-icon' onclick='editEmployee({$emp['id']})' title='Edit Employee'>
                                                <i class='fas fa-edit'></i>
                                            </button>
                                            <a href='attendance.php?employee_id={$emp['id']}&date_from=" . date('Y-m-d') . "&date_to=" . date('Y-m-d') . "' class='btn-icon' title='View Attendance'>
                                                <i class='fas fa-calendar-check'></i>
                                            </a>
                                            <a href='schedules.php?employee_id={$emp['id']}' class='btn-icon' title='View Schedules'>
                                                <i class='fas fa-clock'></i>
                                            </a>
                                            <a href='leave_requests.php?employee_id={$emp['id']}' class='btn-icon' title='View Leave Requests'>
                                                <i class='fas fa-calendar-minus'></i>
                                            </a>
                                            <a href='payroll.php?employee_id={$emp['id']}' class='btn-icon' title='Run Payroll'>
                                                <i class='fas fa-money-check-alt'></i>
                                            </a>
                                            <form method='POST' id='statusForm{$emp['id']}' style='display:inline;'>
                                                <input type='hidden' name='employee_id' value='{$emp['id']}'>
                                                <input type='hidden' name='csrf_token' value='{$_SESSION['csrf_token']}'>
                                                <select name='new_status' class='form-select-sm' data-current-status='{$emp['status']}' onchange='confirmStatusChange(this, {$emp['id']})'>
                                                    <option value=''>Change Status</option>
                                                    <option value='active'" . ($emp['status'] === 'active' ? " selected" : "") . ">Active</option>
                                                    <option value='inactive'" . ($emp['status'] === 'inactive' ? " selected" : "") . ">Inactive</option>
                                                    <option value='on_leave'" . ($emp['status'] === 'on_leave' ? " selected" : "") . ">On Leave</option>
                                                    <option value='terminated'" . ($emp['status'] === 'terminated' ? " selected" : "") . ">Terminated</option>
                                                </select>
                                                <input type='hidden' name='update_status' value='1'>
                                            </form>";
                                    if (!$emp['user_id']) {
                                        echo "<button type='button' class='btn-icon' onclick='openLinkUserModal({$emp['id']}, {$employee_name_js}, {$employee_email_js})' title='Link User Account'>
                                                  <i class='fas fa-user-plus'></i>
                                              </button>";
                                    }
                                    echo "</td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='10' class='text-center text-muted'>No employees found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Employee Modal -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Government IDs (Optional)</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="text" name="sss_number" class="form-control form-control-sm" placeholder="SSS Number">
                                </div>
                                <div class="col-6">
                                    <input type="text" name="philhealth_number" class="form-control form-control-sm" placeholder="PhilHealth">
                                </div>
                                <div class="col-6">
                                    <input type="text" name="pagibig_number" class="form-control form-control-sm" placeholder="Pag-IBIG">
                                </div>
                                <div class="col-6">
                                    <input type="text" name="tin_number" class="form-control form-control-sm" placeholder="TIN">
                                </div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Department</label>
                                    <select name="department_id" id="add_department_id" class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo (int)($dept['id'] ?? 0); ?>">
                                                <?php echo htmlspecialchars((string)($dept['department_name'] ?? '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Position *</label>
                                    <select name="position_id" id="add_position_id" class="form-select" required>
                                        <option value="">Select Position</option>
                                    </select>
                                    <small class="text-muted">Positions are centralized from Recruitment (`job_positions`).</small>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Hire Date *</label>
                            <input type="date" name="hire_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Employment Type</label>
                            <select name="employment_type" class="form-select">
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Employment Basis</label>
                                    <select name="employment_basis" class="form-select">
                                        <option value="monthly">Monthly</option>
                                        <option value="daily">Daily</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Base Salary/Rate (&#8369;)</label>
                                    <input type="number" name="base_salary" class="form-control" step="0.01" required placeholder="e.g. 20000 or 570">
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="createUserCheck" name="create_user" onchange="toggleUserFields()">
                            <label class="form-check-label" for="createUserCheck">Create Login Account</label>
                        </div>
                        
                        <div id="userFields" style="display:none; background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <div class="form-group mb-2">
                                <label>System Role *</label>
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
                            <div class="form-group">
                                <label>Password *</label>
                                <input type="password" name="password" class="form-control" placeholder="Set login password">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_employee" value="1" class="btn btn-primary">Add Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_employee" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="employee_id" id="edit_employee_id">
                        
                        <div class="form-group"><label>First Name *</label><input type="text" name="first_name" id="edit_first_name" class="form-control" required></div>
                        <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" id="edit_last_name" class="form-control" required></div>
                        <div class="form-group"><label>Email *</label><input type="email" name="email" id="edit_email" class="form-control" required></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                        
                        <div class="form-group">
                            <label>Government IDs</label>
                            <div class="row g-2">
                                <div class="col-6"><input type="text" name="sss_number" id="edit_sss" class="form-control form-control-sm" placeholder="SSS"></div>
                                <div class="col-6"><input type="text" name="philhealth_number" id="edit_philhealth" class="form-control form-control-sm" placeholder="PhilHealth"></div>
                                <div class="col-6"><input type="text" name="pagibig_number" id="edit_pagibig" class="form-control form-control-sm" placeholder="Pag-IBIG"></div>
                                <div class="col-6"><input type="text" name="tin_number" id="edit_tin" class="form-control form-control-sm" placeholder="TIN"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Department</label>
                            <select name="department_id" id="edit_department_id" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int)($dept['id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string)($dept['department_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Position *</label>
                            <select name="position_id" id="edit_position_id" class="form-select" required>
                                <option value="">Select Position</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Hire Date *</label><input type="date" name="hire_date" id="edit_hire_date" class="form-control" required></div>
                        
                        <div class="form-group"><label>Employment Type</label>
                            <select name="employment_type" id="edit_employment_type" class="form-select">
                                <option value="full_time">Full Time</option><option value="part_time">Part Time</option><option value="contract">Contract</option><option value="temporary">Temporary</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-6"><label>Basis</label><select name="employment_basis" id="edit_employment_basis" class="form-select"><option value="monthly">Monthly</option><option value="daily">Daily</option></select></div>
                            <div class="col-6"><label>Base Rate (&#8369;)</label><input type="number" name="base_salary" id="edit_base_salary" class="form-control" step="0.01" required></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Employee</button>
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
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['error']; ?>'
            });
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
                    $('#edit_last_name').val(data.last_name);
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

        document.addEventListener('DOMContentLoaded', function() {
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


