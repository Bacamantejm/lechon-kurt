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
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
$scope_user_ids = hrScopedUserIds($conn);
if (!$is_partner_scoped_hr) {
    requirePermission('departments.view');
}

if (!function_exists('departmentsRoleOwnerColumnExists')) {
    function departmentsRoleOwnerColumnExists($conn) {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $res = mysqli_query($conn, "SHOW COLUMNS FROM roles LIKE 'owner_user_id'");
        $exists = $res && mysqli_num_rows($res) > 0;
        return $exists;
    }
}

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle department actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid CSRF token. Action aborted.';
        header("Location: departments.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_department') {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('departments.create');
        }

        $department_name = trim($_POST['department_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $manager_id = isset($_POST['manager_id']) && $_POST['manager_id'] !== '' ? intval($_POST['manager_id']) : 0;
        if ($is_partner_scoped_hr) {
            if ($manager_id > 0 && !in_array($manager_id, $scope_user_ids, true)) {
                $_SESSION['error'] = "Selected manager is outside your partner HR scope.";
                header("Location: departments.php");
                exit();
            }
            if ($manager_id <= 0) {
                $manager_id = $partner_scope_owner_id;
            }
        }

        if ($department_name === '') {
            $_SESSION['error'] = "Department name is required.";
        } else {
            $insert_sql = "INSERT INTO departments (department_name, description, manager_id) VALUES (?, ?, NULLIF(?,0))";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "ssi", $department_name, $description, $manager_id);

            if ($insert_stmt && mysqli_stmt_execute($insert_stmt)) {
                $new_dept_id = mysqli_insert_id($conn);

                // Also create a role for this department
                $role_name = 'dept_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($department_name)));
                $role_desc = 'Role for members of the ' . $department_name . ' department.';
                $role_level = 20; // Standard employee-level role

                $role_sql = ($is_partner_scoped_hr && departmentsRoleOwnerColumnExists($conn))
                    ? "INSERT INTO roles (name, description, level, department_id, owner_user_id) VALUES (?, ?, ?, ?, ?)"
                    : "INSERT INTO roles (name, description, level, department_id) VALUES (?, ?, ?, ?)";
                $role_stmt = mysqli_prepare($conn, $role_sql);
                if ($is_partner_scoped_hr && departmentsRoleOwnerColumnExists($conn)) {
                    mysqli_stmt_bind_param($role_stmt, "ssiii", $role_name, $role_desc, $role_level, $new_dept_id, $partner_scope_owner_id);
                } else {
                    mysqli_stmt_bind_param($role_stmt, "ssii", $role_name, $role_desc, $role_level, $new_dept_id);
                }
                if (mysqli_stmt_execute($role_stmt)) {
                    $_SESSION['success'] = "Department and associated role created.";
                }
                mysqli_stmt_close($role_stmt);
            } else {
                $_SESSION['error'] = "Unable to create department: " . mysqli_error($conn);
            }
            if ($insert_stmt) {
                mysqli_stmt_close($insert_stmt);
            }
        }
    }

    if ($action === 'edit_department') {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('departments.edit');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        $department_name = trim($_POST['department_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $manager_id = isset($_POST['manager_id']) && $_POST['manager_id'] !== '' ? intval($_POST['manager_id']) : 0;
        if ($is_partner_scoped_hr && !hrDepartmentIdInScope($conn, $dept_id)) {
            $_SESSION['error'] = "Selected department is outside your partner HR scope.";
            header("Location: departments.php");
            exit();
        }
        if ($is_partner_scoped_hr && $manager_id > 0 && !in_array($manager_id, $scope_user_ids, true)) {
            $_SESSION['error'] = "Selected manager is outside your partner HR scope.";
            header("Location: departments.php");
            exit();
        }

        if ($dept_id === 0 || $department_name === '') {
            $_SESSION['error'] = "Missing department details.";
        } else {
            $update_sql = "UPDATE departments SET department_name = ?, description = ?, manager_id = NULLIF(?,0) WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ssii", $department_name, $description, $manager_id, $dept_id);

            if ($update_stmt && mysqli_stmt_execute($update_stmt)) {
                // Also update the corresponding role
                $role_name = 'dept_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($department_name)));
                $role_desc = 'Role for members of the ' . $department_name . ' department.';

                $role_update_sql = "UPDATE roles SET name = ?, description = ? WHERE department_id = ?" . (($is_partner_scoped_hr && departmentsRoleOwnerColumnExists($conn)) ? " AND owner_user_id = " . (int)$partner_scope_owner_id : "");
                $role_stmt = mysqli_prepare($conn, $role_update_sql);
                mysqli_stmt_bind_param($role_stmt, "ssi", $role_name, $role_desc, $dept_id);
                if (mysqli_stmt_execute($role_stmt)) {
                    $_SESSION['success'] = "Department and associated role updated.";
                }
                mysqli_stmt_close($role_stmt);
            } else {
                $_SESSION['error'] = "Unable to update department: " . mysqli_error($conn);
            }
            if ($update_stmt) {
                mysqli_stmt_close($update_stmt);
            }
        }
    }

    if ($action === 'delete_department') {
        // Enforce action-level permission
        if (!$is_partner_scoped_hr) {
            requirePermission('departments.delete');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        if ($dept_id === 0) {
            $_SESSION['error'] = "Invalid department selected.";
        } elseif ($is_partner_scoped_hr && !hrDepartmentIdInScope($conn, $dept_id)) {
            $_SESSION['error'] = "Selected department is outside your partner HR scope.";
        } else {
            // Check for employees in this department before deleting
            $check_sql = "SELECT COUNT(*) as emp_count FROM employees WHERE department_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_sql);
            mysqli_stmt_bind_param($check_stmt, "i", $dept_id);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            $row = mysqli_fetch_assoc($result);
            mysqli_stmt_close($check_stmt);

            if ($row['emp_count'] > 0) {
                $_SESSION['error'] = "Cannot delete department. It has " . $row['emp_count'] . " employee(s) assigned. Please reassign them first.";
            } else {
                $delete_sql = "DELETE FROM departments WHERE id = ?";
                $delete_stmt = mysqli_prepare($conn, $delete_sql);
                mysqli_stmt_bind_param($delete_stmt, "i", $dept_id);

                if ($delete_stmt && mysqli_stmt_execute($delete_stmt)) {
                    $_SESSION['success'] = "Department deleted.";
                } else {
                    $_SESSION['error'] = "Unable to delete department: " . mysqli_error($conn);
                }
                if ($delete_stmt) {
                    mysqli_stmt_close($delete_stmt);
                }
            }
        }
    }
    // Redirect after POST action to prevent form resubmission
    header("Location: departments.php");
    exit();
}

// Fetch managers for dropdowns
$managers = [];
$manager_query_sql = "SELECT id, full_name FROM users WHERE user_type IN ('admin', 'employee') AND is_active = 1";
if ($is_partner_scoped_hr) {
    $scope_ids = [];
    foreach ($scope_user_ids as $scope_uid) {
        $scope_uid = intval($scope_uid);
        if ($scope_uid > 0) {
            $scope_ids[] = $scope_uid;
        }
    }
    if (!empty($scope_ids)) {
        $manager_query_sql .= " AND id IN (" . implode(',', $scope_ids) . ")";
    } else {
        $manager_query_sql .= " AND 1=0";
    }
}
$manager_query_sql .= " ORDER BY full_name";
$manager_query = mysqli_query($conn, $manager_query_sql);
if ($manager_query) {
    while ($manager = mysqli_fetch_assoc($manager_query)) {
        $managers[] = $manager;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - HR Management</title>
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
                    <h1>Departments Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">

                <div class="section-header">
                    <h2>Departments</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="fas fa-plus"></i> Add Department
                    </button>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Department Name</th>
                                <th>Description</th>
                                <th>Manager</th>
                                <th>Employees</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $departments_sql = "
                                SELECT d.*, u.full_name, COUNT(e.id) as emp_count
                                FROM departments d
                                LEFT JOIN users u ON d.manager_id = u.id
                                LEFT JOIN employees e ON d.id = e.department_id
                                " . ($is_partner_scoped_hr ? "WHERE {$department_scope_sql}" : "") . "
                                GROUP BY d.id
                                ORDER BY d.department_name
                            ";
                            $departments = mysqli_query($conn, $departments_sql);

                            if ($departments && mysqli_num_rows($departments) > 0) {
                                while ($dept = mysqli_fetch_assoc($departments)) {
                                    $dept_desc = isset($dept['description']) ? $dept['description'] : '-';
                                    $dept_manager = isset($dept['full_name']) ? $dept['full_name'] : 'Unassigned';
                                    $dept_name_safe = htmlspecialchars(addslashes($dept['department_name']), ENT_QUOTES);
                                    $dept_desc_safe = htmlspecialchars(addslashes($dept_desc), ENT_QUOTES);
                                    $manager_id = isset($dept['manager_id']) ? intval($dept['manager_id']) : 0;
                                    echo "
                                    <tr>
                                        <td><strong>{$dept['department_name']}</strong></td>
                                        <td>$dept_desc</td>
                                        <td>$dept_manager</td>
                                        <td><a href='employees.php?department={$dept['id']}' class='text-decoration-none'><span class='badge bg-primary'>{$dept['emp_count']}</span></a></td>
                                        <td>
                                            <a href='attendance.php?department={$dept['id']}&date_from=" . date('Y-m-d') . "&date_to=" . date('Y-m-d') . "' class='btn-icon' title='View Attendance'>
                                                <i class='fas fa-calendar-check'></i>
                                            </a>
                                            <a href='schedules.php?department_id={$dept['id']}' class='btn-icon' title='View Schedules'>
                                                <i class='fas fa-clock'></i>
                                            </a>
                                            <a href='leave_requests.php?department_id={$dept['id']}' class='btn-icon' title='View Leave Requests'>
                                                <i class='fas fa-calendar-minus'></i>
                                            </a>
                                            <button class='btn-icon' onclick=\"editDepartment({$dept['id']}, '{$dept_name_safe}', '{$dept_desc_safe}', {$manager_id})\" title='Edit'>
                                                <i class='fas fa-edit'></i>
                                            </button>
                                            <button class='btn-icon' onclick='deleteDepartment({$dept['id']})' title='Delete'>
                                                <i class='fas fa-trash'></i>
                                            </button>
                                        </td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center text-muted'>No departments found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Department Modal -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_department">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group mb-3">
                            <label>Department Name *</label>
                            <input type="text" name="department_name" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group mb-3">
                            <label>Manager</label>
                            <select name="manager_id" class="form-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?php echo $manager['id']; ?>"><?php echo htmlspecialchars($manager['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div class="modal fade" id="editDepartmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_department">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="dept_id" id="editDeptId">
                        <div class="form-group mb-3">
                            <label>Department Name *</label>
                            <input type="text" name="department_name" id="editDepartmentName" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label>Description</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group mb-3">
                            <label>Manager</label>
                            <select name="manager_id" id="editManagerId" class="form-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?php echo $manager['id']; ?>"><?php echo htmlspecialchars($manager['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteDepartmentForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_department">
        <input type="hidden" name="dept_id" id="deleteDeptId">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    </form>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: "<?php echo addslashes($_SESSION['success']); ?>",
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: "<?php echo addslashes($_SESSION['error']); ?>"
            });
        <?php unset($_SESSION['error']); endif; ?>

        function editDepartment(id, name, description, managerId) {
            $('#editDeptId').val(id);
            $('#editDepartmentName').val(name);
            $('#editDescription').val(description !== '-' ? description : '');
            $('#editManagerId').val(managerId);
            var editModal = new bootstrap.Modal(document.getElementById('editDepartmentModal'));
            editModal.show();
        }

        function deleteDepartment(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "Delete this department? This cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteDeptId').value = id;
                    document.getElementById('deleteDepartmentForm').submit();
                }
            })
        }
    </script>
</body>
</html>


