<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_turnover_table = hrTableExists($conn, 'employee_turnover');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$year_filter = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$year_filter = ($year_filter >= intval(date('Y')) - 10 && $year_filter <= intval(date('Y')) + 1) ? $year_filter : intval(date('Y'));
$clearance_filter = hrSafeEnum($_GET['clearance'] ?? '', ['', 'pending', 'completed', 'pending_items'], '');

if (!$is_partner_scoped_hr) {
    requireAnyPermission(['employees.view', 'employees.edit']);
}

// Handle turnover actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('turnover.php');

    if (!$has_turnover_table) {
        $_SESSION['error'] = "Employee turnover table is not configured yet.";
        header("Location: turnover.php");
        exit();
    }

    if (isset($_POST['record_separation'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.edit');
        }
        $employee_id = intval($_POST['employee_id']);
        $separation_type = hrSafeEnum($_POST['separation_type'] ?? '', ['resignation', 'termination', 'retirement', 'contract_end'], '');
        $resignation_date = trim($_POST['resignation_date'] ?? '');
        $last_working_day = trim($_POST['last_working_day'] ?? '');
        $resignation_reason = trim($_POST['resignation_reason'] ?? '');
        $resignation_reason = $resignation_reason !== '' ? $resignation_reason : null;

        if ($employee_id <= 0 || $separation_type === '' || !hrIsValidDate($last_working_day)) {
            $_SESSION['error'] = "Please provide valid separation details.";
            header("Location: turnover.php");
            exit();
        }
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: turnover.php");
            exit();
        }
        if ($resignation_date === '') {
            $resignation_date = $last_working_day;
        }
        if (!hrIsValidDate($resignation_date) || $last_working_day < $resignation_date) {
            $_SESSION['error'] = "Invalid resignation or last working day.";
            header("Location: turnover.php");
            exit();
        }
        
        $insert = "INSERT INTO employee_turnover (employee_id, separation_type, resignation_date, last_working_day, notice_period_days, resignation_reason, processed_by)
                  VALUES (?, ?, ?, ?, DATEDIFF(?, ?), ?, ?)";
        $stmt = mysqli_prepare($conn, $insert);
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        mysqli_stmt_bind_param($stmt, "issssssi", $employee_id, $separation_type, $resignation_date, $last_working_day, $last_working_day, $resignation_date, $resignation_reason, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update employee status
            $update_emp = "UPDATE employees SET status = 'terminated' WHERE id = ?";
            $stmt2 = mysqli_prepare($conn, $update_emp);
            mysqli_stmt_bind_param($stmt2, "i", $employee_id);
            mysqli_stmt_execute($stmt2);
            mysqli_stmt_close($stmt2);
            
            $_SESSION['success'] = "Employee separation recorded successfully.";
        } else {
            $_SESSION['error'] = "Unable to record separation.";
        }
        mysqli_stmt_close($stmt);
    }
    
    if (isset($_POST['update_clearance'])) {
        if (!$is_partner_scoped_hr) {
            requirePermission('employees.edit');
        }
        $turnover_id = intval($_POST['turnover_id']);
        $clearance_status = hrSafeEnum($_POST['clearance_status'] ?? '', ['pending', 'completed', 'pending_items'], 'pending');
        $clearance_notes = trim($_POST['clearance_notes'] ?? '');
        if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'employee_turnover', $turnover_id, 'id', 'employee_id')) {
            $_SESSION['error'] = "Selected turnover record is outside your partner HR scope.";
            header("Location: turnover.php");
            exit();
        }
        
        $update = "UPDATE employee_turnover SET exit_clearance_status = ?, clearance_notes = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update);
        mysqli_stmt_bind_param($stmt, "ssi", $clearance_status, $clearance_notes, $turnover_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['success'] = "Clearance status updated.";
    }

    header("Location: turnover.php");
    exit();
}

$turnover_result = false;
if ($has_turnover_table) {
    $turnover_query = "SELECT et.*, e.first_name, e.last_name, e.employee_id, e.position, d.department_name
                      FROM employee_turnover et
                      JOIN employees e ON et.employee_id = e.id
                      LEFT JOIN departments d ON e.department_id = d.id
                      WHERE YEAR(et.created_at) = ?
                        AND (? = '' OR et.exit_clearance_status = ?)" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "") . "
                      ORDER BY et.created_at DESC";
    $turnover_stmt = mysqli_prepare($conn, $turnover_query);
    mysqli_stmt_bind_param($turnover_stmt, "iss", $year_filter, $clearance_filter, $clearance_filter);
    mysqli_stmt_execute($turnover_stmt);
    $turnover_result = mysqli_stmt_get_result($turnover_stmt);
    mysqli_stmt_close($turnover_stmt);
}

// Calculate turnover rate
$total_emp_sql = "SELECT COUNT(*) as count FROM employees e WHERE " . ($is_partner_scoped_hr ? $employee_scope_sql : '1=1');
$total_emp = mysqli_fetch_assoc(mysqli_query($conn, $total_emp_sql))['count'];
$separated_this_year = 0;
if ($has_turnover_table) {
    $sep_sql = "SELECT COUNT(*) as count
                FROM employee_turnover et
                JOIN employees e ON et.employee_id = e.id
                WHERE YEAR(et.created_at) = YEAR(CURDATE())" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
    $sep_query = mysqli_query($conn, $sep_sql);
    if ($sep_query) {
        $separated_this_year = (int)(mysqli_fetch_assoc($sep_query)['count'] ?? 0);
    }
}
$turnover_rate = $total_emp > 0 ? round(($separated_this_year / $total_emp) * 100, 2) : 0;

$active_emp_sql = "SELECT COUNT(*) as count FROM employees e WHERE e.status = 'active'" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
$active_emp = mysqli_query($conn, $active_emp_sql);
$active_count = mysqli_fetch_assoc($active_emp)['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Turnover - HR Management</title>
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
                    <h1>Employee Turnover Management</h1>
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

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label mb-1">Year</label>
                                <select name="year" class="form-select">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == $year_filter ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Clearance</label>
                                <select name="clearance" class="form-select">
                                    <option value="" <?php echo $clearance_filter === '' ? 'selected' : ''; ?>>All</option>
                                    <option value="pending" <?php echo $clearance_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="completed" <?php echo $clearance_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="pending_items" <?php echo $clearance_filter === 'pending_items' ? 'selected' : ''; ?>>Pending Items</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex gap-2 justify-content-md-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                                <a href="turnover.php" class="btn btn-secondary">Reset</a>
                                <a href="recruitment.php" class="btn btn-secondary"><i class="fas fa-briefcase"></i> Recruitment</a>
                                <a href="hr_reports.php?type=turnover&year=<?php echo $year_filter; ?>" class="btn btn-secondary"><i class="fas fa-chart-bar"></i> Reports</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="dashboard-grid" style="margin-bottom: 30px;">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $active_count; ?></h3>
                            <p>Active Employees</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $separated_this_year; ?></h3>
                            <p>Separated (This Year)</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo $turnover_rate; ?>%</h3>
                            <p>Turnover Rate (Annual)</p>
                        </div>
                    </div>
                </div>
                
                <div class="section-header">
                    <h2>Separation Records</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordSeparationModal">
                        <i class="fas fa-plus"></i> Record Separation
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Employee ID</th>
                                <th>Department</th>
                                <th>Separation Type</th>
                                <th>Last Working Day</th>
                                <th>Clearance Status</th>
                                <th>Rehire Eligible</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!$has_turnover_table) {
                                echo "<tr><td colspan='8' class='text-center text-muted'>`employee_turnover` table is missing. Please run HR turnover migrations.</td></tr>";
                            } elseif ($turnover_result && mysqli_num_rows($turnover_result) > 0) {
                                while ($turn = mysqli_fetch_assoc($turnover_result)) {
                                    $clearance_status = hrSafeEnum($turn['exit_clearance_status'], ['pending', 'completed', 'pending_items'], 'pending');
                                    $clearance_class = 'badge-' . str_replace('_', '-', $clearance_status);
                                    $rehire_class = 'badge-' . ($turn['rehire_eligible'] == 'yes' ? 'success' : ($turn['rehire_eligible'] == 'no' ? 'danger' : 'warning'));
                                    $department_name = !empty($turn['department_name']) ? $turn['department_name'] : 'N/A';
                                    
                                    echo "
                                    <tr>
                                        <td><strong>" . htmlspecialchars($turn['first_name'] . ' ' . $turn['last_name']) . "</strong></td>
                                        <td>" . htmlspecialchars($turn['employee_id']) . "</td>
                                        <td>" . htmlspecialchars($department_name) . "</td>
                                        <td>" . ucfirst(str_replace('_', ' ', $turn['separation_type'])) . "</td>
                                        <td>" . date('M d, Y', strtotime($turn['last_working_day'])) . "</td>
                                        <td><span class='status-badge $clearance_class'>" . ucfirst(str_replace('_', ' ', $clearance_status)) . "</span></td>
                                        <td><span class='badge $rehire_class'>" . ucfirst(htmlspecialchars((string)$turn['rehire_eligible'])) . "</span></td>
                                        <td>
                                            <button class='btn-icon' data-bs-toggle='modal' data-bs-target='#updateClearanceModal' onclick='editClearance({$turn['id']})' title='Update Clearance'>
                                                <i class='fas fa-edit'></i>
                                            </button>
                                        </td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='8' class='text-center text-muted'>No separation records</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Record Separation Modal -->
    <div class="modal fade" id="recordSeparationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Employee Separation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label>Employee</label>
                            <select name="employee_id" class="form-control" required>
                                <option value="">Select Employee</option>
                                <?php
                                $active_employees_sql = "SELECT e.id, e.first_name, e.last_name FROM employees e WHERE e.status = 'active'" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "") . " ORDER BY e.first_name";
                                $active_employees = mysqli_query($conn, $active_employees_sql);
                                while ($emp = mysqli_fetch_assoc($active_employees)) {
                                    echo "<option value='{$emp['id']}'>{$emp['first_name']} {$emp['last_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label>Separation Type</label>
                            <select name="separation_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="resignation">Resignation</option>
                                <option value="termination">Termination</option>
                                <option value="retirement">Retirement</option>
                                <option value="contract_end">Contract End</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Resignation Date</label>
                                <input type="date" name="resignation_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Last Working Day</label>
                                <input type="date" name="last_working_day" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Reason for Resignation/Termination</label>
                            <textarea name="resignation_reason" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="record_separation" class="btn btn-primary">Record Separation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Clearance Modal -->
    <div class="modal fade" id="updateClearanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Exit Clearance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" id="turnoverIdInput" name="turnover_id">
                        
                        <div class="mb-3">
                            <label>Clearance Status</label>
                            <select name="clearance_status" class="form-control" required>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                                <option value="pending_items">Pending Items</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label>Clearance Notes</label>
                            <textarea name="clearance_notes" class="form-control" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_clearance" class="btn btn-primary">Update Clearance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function editClearance(turnoverId) {
            document.getElementById('turnoverIdInput').value = turnoverId;
        }
    </script>
</body>
</html>


