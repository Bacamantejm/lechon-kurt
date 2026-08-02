                                <?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_leave_balance_table = hrTableExists($conn, 'leave_balance');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

// Handle leave balance actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('leave_balance.php');

    if (!$has_leave_balance_table) {
        $_SESSION['error'] = "Leave balance table is not configured yet.";
        header("Location: leave_balance.php");
        exit();
    }

    if (isset($_POST['update_balance'])) {
        $employee_id = intval($_POST['employee_id']);
        $leave_type = hrSafeEnum($_POST['leave_type'] ?? '', ['sick', 'vacation', 'personal', 'maternity', 'paternity', 'emergency'], '');
        $year = intval($_POST['year']);
        $initial_balance = floatval($_POST['initial_balance']);

        if ($employee_id <= 0 || $leave_type === '' || $year < date('Y') - 10 || $year > date('Y') + 1 || $initial_balance < 0) {
            $_SESSION['error'] = "Invalid leave balance input.";
        } elseif ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
        } else {
            $check = "SELECT id FROM leave_balance WHERE employee_id = ? AND leave_type = ? AND year = ?";
            $stmt = mysqli_prepare($conn, $check);
            mysqli_stmt_bind_param($stmt, "isi", $employee_id, $leave_type, $year);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $exists = $result && mysqli_num_rows($result) > 0;
            mysqli_stmt_close($stmt);

            if ($exists) {
                $update = "UPDATE leave_balance SET initial_balance = ?, balance_remaining = ? WHERE employee_id = ? AND leave_type = ? AND year = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "ddisi", $initial_balance, $initial_balance, $employee_id, $leave_type, $year);
            } else {
                $insert = "INSERT INTO leave_balance (employee_id, leave_type, year, initial_balance, balance_remaining, used_days) VALUES (?, ?, ?, ?, ?, 0)";
                $stmt = mysqli_prepare($conn, $insert);
                mysqli_stmt_bind_param($stmt, "isidd", $employee_id, $leave_type, $year, $initial_balance, $initial_balance);
            }

            if ($stmt && mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Leave balance updated successfully.";
            } else {
                $_SESSION['error'] = "Error updating leave balance.";
            }
            if ($stmt) {
                mysqli_stmt_close($stmt);
            }
        }

        header("Location: leave_balance.php?year={$year}");
        exit();
    }
}

$current_year = intval(date('Y'));
$year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
if ($year < $current_year - 10 || $year > $current_year + 1) {
    $year = $current_year;
}
$search = trim($_GET['search'] ?? '');
$search_like = '%' . $search . '%';

$query = $has_leave_balance_table
    ? "SELECT e.*, GROUP_CONCAT(CONCAT(lb.leave_type, ':', lb.balance_remaining) SEPARATOR '|') AS leave_balances
       FROM employees e
       LEFT JOIN leave_balance lb ON e.id = lb.employee_id AND lb.year = ?
       WHERE e.status = 'active'
         AND " . ($is_partner_scoped_hr ? $employee_scope_sql : '1=1') . "
         AND (? = '' OR e.first_name LIKE ? OR e.last_name LIKE ?)
       GROUP BY e.id
       ORDER BY e.first_name ASC"
    : "SELECT e.*, NULL AS leave_balances
       FROM employees e
       WHERE e.status = 'active'
         AND " . ($is_partner_scoped_hr ? $employee_scope_sql : '1=1') . "
         AND (? = '' OR e.first_name LIKE ? OR e.last_name LIKE ?)
       ORDER BY e.first_name ASC";
$stmt = mysqli_prepare($conn, $query);
if ($has_leave_balance_table) {
    mysqli_stmt_bind_param($stmt, "isss", $year, $search, $search_like, $search_like);
} else {
    mysqli_stmt_bind_param($stmt, "sss", $search, $search_like, $search_like);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Balance - HR Management</title>
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
                    <h1>Leave Balance Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (!$has_leave_balance_table): ?>
                    <div class="alert alert-warning">
                        <strong>Notice:</strong> `leave_balance` table is missing. You can still view employees, but leave balances cannot be saved until the table is created.
                    </div>
                <?php endif; ?>
                
                <div class="section-header">
                    <h2>Employee Leave Balances</h2>
                    <a href="leave_requests.php" class="btn btn-secondary"><i class="fas fa-calendar-minus"></i> Leave Requests</a>
                </div>

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label mb-1">Search Employee</label>
                                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name keyword..." class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Year</label>
                                <select name="year" id="yearFilter" class="form-select">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y == $year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                                <a href="leave_balance.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th>Employee ID</th>
                                <th>Sick Leave</th>
                                <th>Vacation</th>
                                <th>Personal</th>
                                <th>Maternity</th>
                                <th>Paternity</th>
                                <th>Emergency</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $leave_types = ['sick', 'vacation', 'personal', 'maternity', 'paternity', 'emergency'];
                            
                            if (mysqli_num_rows($result) > 0) {
                                while ($emp = mysqli_fetch_assoc($result)) {
                                    $balances = [];
                                    if ($emp['leave_balances']) {
                                        foreach (explode('|', $emp['leave_balances']) as $bal) {
                                            list($type, $amount) = explode(':', $bal);
                                            $balances[$type] = $amount;
                                        }
                                    }
                                    
                                    echo "<tr>
                                        <td><strong>" . htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) . "</strong></td>
                                        <td>" . htmlspecialchars($emp['employee_id']) . "</td>";
                                    
                                    foreach ($leave_types as $type) {
                                        $bal = isset($balances[$type]) ? number_format($balances[$type], 2) : '0.00';
                                        echo "<td>$bal days</td>";
                                    }
                                    
                                    echo "<td>
                                        " . ($has_leave_balance_table
                                            ? "<button class='btn-icon' data-bs-toggle='modal' data-bs-target='#updateBalanceModal' onclick='editBalance({$emp['id']}, \"$year\")' title='Edit'><i class='fas fa-edit'></i></button>"
                                            : "<button class='btn-icon' disabled title='Leave balance table missing'><i class='fas fa-ban'></i></button>") . "
                                        <a class='btn-icon' href='leave_requests.php?employee_id={$emp['id']}' title='View Leave Requests'>
                                            <i class='fas fa-calendar-minus'></i>
                                        </a>
                                    </td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='9' class='text-center text-muted'>No employees found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Balance Modal -->
    <div class="modal fade" id="updateBalanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Leave Balance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" id="modalEmployeeId" name="employee_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Year</label>
                                <input type="number" name="year" class="form-control" value="<?php echo $year; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Leave Type</label>
                                <select name="leave_type" class="form-control" required>
                                    <option value="">Select Leave Type</option>
                                    <option value="sick">Sick Leave</option>
                                    <option value="vacation">Vacation Leave</option>
                                    <option value="personal">Personal Leave</option>
                                    <option value="maternity">Maternity Leave</option>
                                    <option value="paternity">Paternity Leave</option>
                                    <option value="emergency">Emergency Leave</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Initial Balance (days)</label>
                            <input type="number" name="initial_balance" class="form-control" step="0.25" value="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_balance" class="btn btn-primary">Update Balance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function editBalance(empId, year) {
            document.getElementById('modalEmployeeId').value = empId;
        }
    </script>
</body>
</html>


