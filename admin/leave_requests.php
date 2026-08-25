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
$employee_filter = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$department_filter = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$allowed_leave_status = ['pending', 'approved', 'rejected', 'cancelled'];
if ($status_filter !== '' && !in_array($status_filter, $allowed_leave_status, true)) {
    $status_filter = '';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!$is_partner_scoped_hr) {
    requireAnyPermission(['leave.view', 'leave.approve']);
}

// Handle leave request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid CSRF token. Action aborted.';
        header("Location: leave_requests.php");
        exit();
    }

    if (isset($_POST['approve_leave'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('leave.approve');
        }
        $leave_id = intval($_POST['leave_id']);
        if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'leave_requests', $leave_id, 'id', 'employee_id')) {
            $_SESSION['error'] = "Selected leave request is outside your partner HR scope.";
            header("Location: leave_requests.php");
            exit();
        }
        $query = "UPDATE leave_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $leave_id);
        if (mysqli_stmt_execute($stmt)) {
            // --- NOTIFICATION ---
            $get_user_query = "SELECT e.user_id, lr.start_date FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE lr.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
            $user_stmt = mysqli_prepare($conn, $get_user_query);
            mysqli_stmt_bind_param($user_stmt, "i", $leave_id);
            mysqli_stmt_execute($user_stmt);
            $user_res = mysqli_stmt_get_result($user_stmt);
            if($user_row = mysqli_fetch_assoc($user_res)) {
                if ($user_row['user_id']) {
                    createNotification($conn, $user_row['user_id'], 'leave_approved', 'Leave Request Approved', 'Your leave request starting '.date('M d, Y', strtotime($user_row['start_date'])).' has been approved.', $leave_id, 'leave');
                }
            }
            // --- END NOTIFICATION ---
            $_SESSION['success'] = "Leave request approved.";
        }
        mysqli_stmt_close($stmt);
    }
    
    if (isset($_POST['reject_leave'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('leave.approve');
        }
        $leave_id = intval($_POST['leave_id']);
        $notes = $_POST['review_notes'] ?? '';
        if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'leave_requests', $leave_id, 'id', 'employee_id')) {
            $_SESSION['error'] = "Selected leave request is outside your partner HR scope.";
            header("Location: leave_requests.php");
            exit();
        }
        $query = "UPDATE leave_requests SET status = 'rejected', reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "isi", $_SESSION['user_id'], $notes, $leave_id);
        if (mysqli_stmt_execute($stmt)) {
            // --- NOTIFICATION ---
            $get_user_query = "SELECT e.user_id, lr.start_date FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE lr.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
            $user_stmt = mysqli_prepare($conn, $get_user_query);
            mysqli_stmt_bind_param($user_stmt, "i", $leave_id);
            mysqli_stmt_execute($user_stmt);
            $user_res = mysqli_stmt_get_result($user_stmt);
            if($user_row = mysqli_fetch_assoc($user_res)) {
                if ($user_row['user_id']) {
                    createNotification($conn, $user_row['user_id'], 'leave_rejected', 'Leave Request Rejected', 'Your leave request starting '.date('M d, Y', strtotime($user_row['start_date'])).' has been rejected. Reason: '.$notes, $leave_id, 'leave');
                }
            }
            // --- END NOTIFICATION ---
            $_SESSION['success'] = "Leave request rejected.";
        }
        mysqli_stmt_close($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Location: leave_requests.php");
    exit();
}

$employees = [];
$employees_query_sql = "SELECT e.id, e.first_name, e.last_name, e.employee_id FROM employees e WHERE e.status = 'active'";
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
$departments_query_sql = "SELECT id, department_name FROM departments d";
if ($is_partner_scoped_hr) {
    $departments_query_sql .= " WHERE {$department_scope_sql}";
}
$departments_query_sql .= " ORDER BY department_name";
$departments_query = mysqli_query($conn, $departments_query_sql);
if ($departments_query) {
    while ($dept = mysqli_fetch_assoc($departments_query)) {
        $departments[] = $dept;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests - HR Management</title>
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
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Leave Requests</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                
                <div class="section-header">
                    <h2>Leave Requests</h2>
                </div>

                <?php include 'hr_workspace_nav.php'; ?>

                <div class="card hr-filter-panel mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Employee</label>
                                <select name="employee_id" class="form-select">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($employee_filter === (int)$emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')'); ?>
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
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo ($status_filter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo ($status_filter === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">Filter Requests</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Date Range</th>
                                <th>Days</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $leave_where = [];
                            if ($employee_filter > 0) {
                                $leave_where[] = "lr.employee_id = {$employee_filter}";
                            }
                            if ($department_filter > 0) {
                                $leave_where[] = "e.department_id = {$department_filter}";
                            }
                            if ($status_filter !== '') {
                                $safe_status = mysqli_real_escape_string($conn, $status_filter);
                                $leave_where[] = "lr.status = '{$safe_status}'";
                            }
                            if ($is_partner_scoped_hr) {
                                $leave_where[] = $employee_scope_sql;
                            }
                            $leave_where_sql = count($leave_where) > 0 ? "WHERE " . implode(" AND ", $leave_where) : "";

                            $leaves = mysqli_query($conn, "
                                SELECT lr.*, e.first_name, e.last_name,
                                DATEDIFF(lr.end_date, lr.start_date) + 1 as days
                                FROM leave_requests lr
                                JOIN employees e ON lr.employee_id = e.id
                                $leave_where_sql
                                ORDER BY lr.created_at DESC
                            ");
                            
                            if ($leaves && mysqli_num_rows($leaves) > 0) {
                                while ($leave = mysqli_fetch_assoc($leaves)) {
                                    $status_class = 'badge-' . $leave['status'];
                                    echo "
                                    <tr>
                                        <td><strong>{$leave['first_name']} {$leave['last_name']}</strong></td>
                                        <td>" . ucfirst(str_replace('_', ' ', $leave['leave_type'])) . "</td>
                                        <td>" . date('M d', strtotime($leave['start_date'])) . " - " . date('M d, Y', strtotime($leave['end_date'])) . "</td>
                                        <td>{$leave['days']} days</td>
                                        <td>" . substr($leave['reason'], 0, 30) . "...</td>
                                        <td><span class='status-badge $status_class'>" . ucfirst($leave['status']) . "</span></td>
                                        <td>
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#leaveModal' onclick='loadLeaveDetails({$leave['id']})' title='View Details'>
                                                <i class='fas fa-eye'></i>
                                            </button>
                                            <a href='schedules.php?employee_id={$leave['employee_id']}&schedule_date={$leave['start_date']}' class='btn-icon' title='Open Employee Schedule'>
                                                <i class='fas fa-clock'></i>
                                            </a>";
                                    
                                    if ($leave['status'] === 'pending') {
                                        echo "
                                            <button class='btn-icon' onclick='approveLeave({$leave['id']})' title='Approve'>
                                                <i class='fas fa-check' style='color: green;'></i>
                                            </button>
                                            <button class='btn-icon' onclick='rejectLeave({$leave['id']})' title='Reject'>
                                                <i class='fas fa-times' style='color: red;'></i>
                                            </button>";
                                    }
                                    echo "</td></tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7' class='text-center text-muted'>No leave requests for selected filters</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Leave Details Modal -->
    <div class="modal fade" id="leaveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Leave Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="leaveDetails">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>
    
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

        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

        function loadLeaveDetails(leaveId) {
            $.ajax({
                url: 'get_leave_details.php',
                type: 'GET',
                data: { id: leaveId },
                success: function(response) {
                    $('#leaveDetails').html(response);
                }
            });
        }
        
        function approveLeave(leaveId) {
            Swal.fire({
                title: 'Approve Leave?',
                text: "Are you sure you want to approve this request?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, approve it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('<form method="POST">' +
                      '<input type="hidden" name="leave_id" value="' + leaveId + '">' +
                      '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                      '<input type="hidden" name="approve_leave" value="1">' +
                      '</form>').appendTo('body').submit();
                }
            })
        }
        
        function rejectLeave(leaveId) {
            Swal.fire({
                title: 'Reject Leave',
                input: 'textarea',
                inputLabel: 'Reason for rejection',
                inputPlaceholder: 'Enter your notes here...',
                showCancelButton: true,
                confirmButtonText: 'Reject',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('<form method="POST">' +
                      '<input type="hidden" name="leave_id" value="' + leaveId + '">' +
                      '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                      '<input type="hidden" name="review_notes" value="' + result.value + '">' +
                      '<input type="hidden" name="reject_leave" value="1">' +
                      '</form>').appendTo('body').submit();
                }
            })
        }
    </script>
</body>
</html>


