<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$admin_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
$employee_filter = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$department_filter = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$schedule_date_filter = trim($_GET['schedule_date'] ?? '');
if ($schedule_date_filter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedule_date_filter)) {
    $schedule_date_filter = '';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!$is_partner_scoped_hr) {
    requireAnyPermission(['attendance.manage', 'hr.view']);
}

// Handle schedule actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid CSRF token. Action aborted.';
        header("Location: schedules.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_schedule') {
        if (!$is_partner_scoped_hr) {
            requirePermission('attendance.manage');
        }
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $schedule_date = trim($_POST['schedule_date'] ?? '');
        $shift_type = hrSafeEnum(trim((string)($_POST['shift_type'] ?? 'regular')), ['regular', 'morning', 'afternoon', 'night', 'custom'], 'regular');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');

        if ($employee_id === 0 || !hrIsValidDate($schedule_date) || !preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $_SESSION['error'] = "Please fill in all required fields.";
        } elseif ($end_time <= $start_time) {
            $_SESSION['error'] = "End time must be later than start time.";
        } elseif ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
        } else {
            $insert_sql = "INSERT INTO schedules (employee_id, schedule_date, shift_type, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "issssi", $employee_id, $schedule_date, $shift_type, $start_time, $end_time, $admin_user_id);

            if ($insert_stmt && mysqli_stmt_execute($insert_stmt)) {
                $_SESSION['success'] = "Schedule created.";
            } else {
                $_SESSION['error'] = "Unable to create schedule.";
            }
            if ($insert_stmt) {
                mysqli_stmt_close($insert_stmt);
            }
        }
    }

    if ($action === 'edit_schedule') {
        if (!$is_partner_scoped_hr) {
            requirePermission('attendance.manage');
        }
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $schedule_date = trim($_POST['schedule_date'] ?? '');
        $shift_type = hrSafeEnum(trim((string)($_POST['shift_type'] ?? 'regular')), ['regular', 'morning', 'afternoon', 'night', 'custom'], 'regular');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');

        if ($schedule_id === 0 || $employee_id === 0 || !hrIsValidDate($schedule_date) || !preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $_SESSION['error'] = "Missing schedule details.";
        } elseif ($end_time <= $start_time) {
            $_SESSION['error'] = "End time must be later than start time.";
        } elseif ($is_partner_scoped_hr && (!hrRecordIdInEmployeeScope($conn, 'schedules', $schedule_id, 'id', 'employee_id') || !hrEmployeeIdInScope($conn, $employee_id))) {
            $_SESSION['error'] = "Selected schedule is outside your partner HR scope.";
        } else {
            $update_sql = "UPDATE schedules SET employee_id = ?, schedule_date = ?, shift_type = ?, start_time = ?, end_time = ? WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "issssi", $employee_id, $schedule_date, $shift_type, $start_time, $end_time, $schedule_id);

            if ($update_stmt && mysqli_stmt_execute($update_stmt)) {
                $_SESSION['success'] = "Schedule updated.";
            } else {
                $_SESSION['error'] = "Unable to update schedule.";
            }
            if ($update_stmt) {
                mysqli_stmt_close($update_stmt);
            }
        }
    }

    if ($action === 'delete_schedule') {
        if (!$is_partner_scoped_hr) {
            requirePermission('attendance.manage');
        }
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        if ($schedule_id === 0) {
            $_SESSION['error'] = "Invalid schedule selected.";
        } elseif ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'schedules', $schedule_id, 'id', 'employee_id')) {
            $_SESSION['error'] = "Selected schedule is outside your partner HR scope.";
        } else {
            $delete_sql = "DELETE FROM schedules WHERE id = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            mysqli_stmt_bind_param($delete_stmt, "i", $schedule_id);

            if ($delete_stmt && mysqli_stmt_execute($delete_stmt)) {
                $_SESSION['success'] = "Schedule deleted.";
            } else {
                $_SESSION['error'] = "Unable to delete schedule.";
            }
            if ($delete_stmt) {
                mysqli_stmt_close($delete_stmt);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Location: schedules.php");
    exit();
}

// Fetch employees for dropdowns
$employees = [];
$employees_query_sql = "SELECT e.id, e.employee_id, e.first_name, e.last_name FROM employees e WHERE e.status = 'active'";
if ($is_partner_scoped_hr) {
    $employees_query_sql .= " AND {$employee_scope_sql}";
}
$employees_query_sql .= " ORDER BY e.first_name, e.last_name";
$employees_query = mysqli_query($conn, $employees_query_sql);
if ($employees_query) {
    while ($emp = mysqli_fetch_assoc($employees_query)) {
        $employees[] = $emp;
    }
}

$departments = [];
$dept_query_sql = "SELECT id, department_name FROM departments d";
if ($is_partner_scoped_hr) {
    $dept_query_sql .= " WHERE {$department_scope_sql}";
}
$dept_query_sql .= " ORDER BY department_name";
$dept_query = mysqli_query($conn, $dept_query_sql);
if ($dept_query) {
    while ($dept = mysqli_fetch_assoc($dept_query)) {
        $departments[] = $dept;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedules - HR Management</title>
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
                    <h1>Schedule Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">

                <div class="section-header">
                    <h2>Work Schedules</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                        <i class="fas fa-plus"></i> Create Schedule
                    </button>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>

                <div class="card hr-filter-panel mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Employee</label>
                                <select name="employee_id" class="form-select">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($employee_filter === (int)$emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Department</label>
                                <select name="department_id" class="form-select">
                                    <option value="0">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo (int)$dept['id']; ?>" <?php echo ($department_filter === (int)$dept['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Schedule Date</label>
                                <input type="date" name="schedule_date" class="form-control" value="<?php echo htmlspecialchars($schedule_date_filter); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Schedule Date</th>
                                <th>Shift Type</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $schedule_where = ["s.schedule_date >= CURDATE()"];
                            if ($employee_filter > 0) {
                                $schedule_where[] = "s.employee_id = {$employee_filter}";
                            }
                            if ($department_filter > 0) {
                                $schedule_where[] = "e.department_id = {$department_filter}";
                            }
                            if ($schedule_date_filter !== '') {
                                $safe_schedule_date = mysqli_real_escape_string($conn, $schedule_date_filter);
                                $schedule_where[] = "s.schedule_date = '{$safe_schedule_date}'";
                            }
                            if ($is_partner_scoped_hr) {
                                $schedule_where[] = $employee_scope_sql;
                            }
                            $schedule_where_sql = "WHERE " . implode(" AND ", $schedule_where);

                            $schedules = mysqli_query($conn, "
                                SELECT s.*, e.first_name, e.last_name, u.full_name as created_by_name
                                FROM schedules s
                                JOIN employees e ON s.employee_id = e.id
                                LEFT JOIN users u ON s.created_by = u.id
                                $schedule_where_sql
                                ORDER BY s.schedule_date, s.start_time
                                LIMIT 50
                            ");
                            
                            if ($schedules && mysqli_num_rows($schedules) > 0) {
                                while ($sched = mysqli_fetch_assoc($schedules)) {
                                    $sched_creator = isset($sched['created_by_name']) ? $sched['created_by_name'] : '-';
                                    $schedule_date = date('M d, Y', strtotime($sched['schedule_date']));
                                    $shift_label = ucfirst(str_replace('_', ' ', $sched['shift_type']));
                                    echo "
                                    <tr>
                                        <td><a href='attendance.php?employee_id={$sched['employee_id']}&date_from={$sched['schedule_date']}&date_to={$sched['schedule_date']}' class='text-decoration-none'><strong>{$sched['first_name']} {$sched['last_name']}</strong></a></td>
                                        <td>{$schedule_date}</td>
                                        <td><span class='badge bg-info'>{$shift_label}</span></td>
                                        <td>{$sched['start_time']}</td>
                                        <td>{$sched['end_time']}</td>
                                        <td>$sched_creator</td>
                                        <td>
                                            <button class='btn-icon' title='Edit' onclick=\"editSchedule({$sched['id']}, {$sched['employee_id']}, '{$sched['schedule_date']}', '{$sched['shift_type']}', '{$sched['start_time']}', '{$sched['end_time']}')\">
                                                <i class='fas fa-edit'></i>
                                            </button>
                                            <button class='btn-icon' title='Delete' onclick='deleteSchedule({$sched['id']})'>
                                                <i class='fas fa-trash'></i>
                                            </button>
                                            <a href='leave_requests.php?employee_id={$sched['employee_id']}' class='btn-icon' title='View Leave Requests'>
                                                <i class='fas fa-calendar-minus'></i>
                                            </a>
                                        </td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='7' class='text-center text-muted'>No schedules found for selected filters</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Schedule Modal -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_schedule">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group mb-3">
                            <label>Employee *</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label>Date *</label>
                            <input type="date" name="schedule_date" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label>Shift Type</label>
                            <select name="shift_type" class="form-select">
                                <option value="regular">Regular</option>
                                <option value="morning">Morning</option>
                                <option value="afternoon">Afternoon</option>
                                <option value="night">Night</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col">
                                <label>Start Time *</label>
                                <input type="time" name="start_time" class="form-control" required>
                            </div>
                            <div class="col">
                                <label>End Time *</label>
                                <input type="time" name="end_time" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div class="modal fade" id="editScheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_schedule">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="schedule_id" id="editScheduleId">
                        <div class="form-group mb-3">
                            <label>Employee *</label>
                            <select name="employee_id" id="editEmployeeId" class="form-select" required>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label>Date *</label>
                            <input type="date" name="schedule_date" id="editScheduleDate" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label>Shift Type</label>
                            <select name="shift_type" id="editShiftType" class="form-select">
                                <option value="regular">Regular</option>
                                <option value="morning">Morning</option>
                                <option value="afternoon">Afternoon</option>
                                <option value="night">Night</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col">
                                <label>Start Time *</label>
                                <input type="time" name="start_time" id="editStartTime" class="form-control" required>
                            </div>
                            <div class="col">
                                <label>End Time *</label>
                                <input type="time" name="end_time" id="editEndTime" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteScheduleForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_schedule">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="schedule_id" id="deleteScheduleId">
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

        function editSchedule(id, employeeId, date, shiftType, startTime, endTime) {
            $('#editScheduleId').val(id);
            $('#editEmployeeId').val(employeeId);
            $('#editScheduleDate').val(date);
            $('#editShiftType').val(shiftType);
            $('#editStartTime').val(startTime);
            $('#editEndTime').val(endTime);
            var modal = new bootstrap.Modal(document.getElementById('editScheduleModal'));
            modal.show();
        }

        function deleteSchedule(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "Delete this schedule?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteScheduleId').value = id;
                    document.getElementById('deleteScheduleForm').submit();
                }
            })
        }
    </script>
</body>
</html>


