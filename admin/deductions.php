<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();

// Enforce page-level permission
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_deductions_table = hrTableExists($conn, 'employee_deductions');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
if (!$is_partner_scoped_hr) {
    requirePermission('deductions.view');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('deductions.php');

    if (!$has_deductions_table) {
        $_SESSION['error'] = "Deductions table is not configured yet.";
        header("Location: deductions.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // All POST actions on this page require manage permission
    if (!$is_partner_scoped_hr) {
        requirePermission('deductions.manage');
    }

    if ($action === 'save_deduction') {
        $id = intval($_POST['deduction_id'] ?? 0);
        $employee_id = intval($_POST['employee_id']);
        $type = hrSafeEnum($_POST['deduction_type'] ?? '', ['loan', 'cash_advance', 'other'], 'other');
        $description = trim($_POST['description'] ?? '');
        $amount = floatval($_POST['amount_per_payroll']);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status = hrSafeEnum($_POST['status'] ?? '', ['active', 'inactive', 'completed'], 'active');

        if ($employee_id <= 0 || $description === '' || $amount < 0 || !hrIsValidDate($start_date) || ($end_date !== null && !hrIsValidDate($end_date))) {
            $_SESSION['error'] = "Please provide valid deduction details.";
            header("Location: deductions.php");
            exit();
        }
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
            header("Location: deductions.php");
            exit();
        }

        if ($id > 0) {
            if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'employee_deductions', $id, 'id', 'employee_id')) {
                $_SESSION['error'] = "Selected deduction is outside your partner HR scope.";
                header("Location: deductions.php");
                exit();
            }
            // Update
            $stmt = $conn->prepare("UPDATE employee_deductions SET employee_id=?, deduction_type=?, description=?, amount_per_payroll=?, start_date=?, end_date=?, status=? WHERE id=?");
            $stmt->bind_param("issdsssi", $employee_id, $type, $description, $amount, $start_date, $end_date, $status, $id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO employee_deductions (employee_id, deduction_type, description, amount_per_payroll, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issdsss", $employee_id, $type, $description, $amount, $start_date, $end_date, $status);
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = "Deduction saved successfully.";
        } else {
            $_SESSION['error'] = "Unable to save deduction.";
        }
        $stmt->close();
    }

    if ($action === 'delete_deduction') {
        $id = intval($_POST['deduction_id']);
        if ($is_partner_scoped_hr && !hrRecordIdInEmployeeScope($conn, 'employee_deductions', $id, 'id', 'employee_id')) {
            $_SESSION['error'] = "Selected deduction is outside your partner HR scope.";
            header("Location: deductions.php");
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM employee_deductions WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Deduction deleted.";
        } else {
            $_SESSION['error'] = "Error deleting deduction.";
        }
        $stmt->close();
    }
    header("Location: deductions.php");
    exit();
}

// New: Filter by employee
$employee_filter_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
if ($is_partner_scoped_hr && $employee_filter_id > 0 && !hrEmployeeIdInScope($conn, $employee_filter_id)) {
    $employee_filter_id = 0;
}

// New: Fetch summary data
$summary_query = "
    SELECT
        e.id as employee_id,
        e.first_name,
        e.last_name,
        SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) as active_deductions_count,
        SUM(CASE WHEN d.status = 'active' THEN d.amount_per_payroll ELSE 0 END) as total_per_payroll,
        SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed_deductions_count
    FROM
        employees e
    JOIN
        employee_deductions d ON e.id = d.employee_id
    GROUP BY
        e.id, e.first_name, e.last_name
    ORDER BY
        e.first_name, e.last_name;
";
if ($is_partner_scoped_hr) {
    $summary_query = "
        SELECT
            e.id as employee_id,
            e.first_name,
            e.last_name,
            SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) as active_deductions_count,
            SUM(CASE WHEN d.status = 'active' THEN d.amount_per_payroll ELSE 0 END) as total_per_payroll,
            SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed_deductions_count
        FROM
            employees e
        JOIN
            employee_deductions d ON e.id = d.employee_id
        WHERE {$employee_scope_sql}
        GROUP BY
            e.id, e.first_name, e.last_name
        ORDER BY
            e.first_name, e.last_name
    ";
}
$summary_result = $has_deductions_table ? mysqli_query($conn, $summary_query) : false;

// Fetch data for display
$employees = mysqli_query($conn, "SELECT e.id, e.first_name, e.last_name FROM employees e WHERE e.status = 'active'" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "") . " ORDER BY e.first_name");
$deductions_sql = "SELECT d.*, e.first_name, e.last_name FROM employee_deductions d JOIN employees e ON d.employee_id = e.id";
$deduction_where = [];
if ($employee_filter_id > 0) {
    $deduction_where[] = "d.employee_id = " . $employee_filter_id;
}
if ($is_partner_scoped_hr) {
    $deduction_where[] = $employee_scope_sql;
}
if (!empty($deduction_where)) {
    $deductions_sql .= " WHERE " . implode(' AND ', $deduction_where);
}
$deductions_sql .= " ORDER BY d.created_at DESC";
$deductions = $has_deductions_table ? mysqli_query($conn, $deductions_sql) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deductions Management - HR</title>
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
                    <h1>Deductions Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="section-header">
                    <h2>Deductions Overview</h2>
                    <div class="d-flex gap-2">
                        <a href="payroll.php" class="btn btn-secondary"><i class="fas fa-money-check-alt"></i> Payroll</a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deductionModal" onclick="resetForm()">
                            <i class="fas fa-plus"></i> Add Deduction
                        </button>
                    </div>
                </div>

                <div class="card hr-filter-panel mb-4">
                    <div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <div>
                            <strong>Tip:</strong> Keep active deductions updated so payroll drafts remain accurate.
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="payslip_generation.php" class="btn btn-secondary btn-sm"><i class="fas fa-file-invoice-dollar"></i> Payslips</a>
                            <a href="hr_reports.php?type=payroll" class="btn btn-secondary btn-sm"><i class="fas fa-chart-bar"></i> Payroll Reports</a>
                        </div>
                    </div>
                </div>

                <!-- Summary Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Deduction Summary Per Employee
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Active Deductions</th>
                                        <th>Total / Payroll</th>
                                        <th>Completed Deductions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$has_deductions_table): ?>
                                        <tr><td colspan="4" class="text-center text-muted">`employee_deductions` table is missing.</td></tr>
                                    <?php elseif ($summary_result && mysqli_num_rows($summary_result) > 0): ?>
                                        <?php while($summary = mysqli_fetch_assoc($summary_result)): ?>
                                            <tr>
                                                <td><a href="?employee_id=<?php echo $summary['employee_id']; ?>"><?php echo htmlspecialchars($summary['first_name'] . ' ' . $summary['last_name']); ?></a></td>
                                                <td><?php echo (int)$summary['active_deductions_count']; ?></td>
                                                <td>&#8369;<?php echo number_format((float)$summary['total_per_payroll'], 2); ?></td>
                                                <td><?php echo (int)$summary['completed_deductions_count']; ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">No deduction summaries available.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="section-header">
                    <h2><?php echo $employee_filter_id > 0 ? 'Filtered Deduction List' : 'All Deductions'; ?></h2>
                    <?php if ($employee_filter_id > 0): ?>
                        <a href="deductions.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear Filter</a>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Description</th>
                                <th>Amount/Payroll</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$has_deductions_table): ?>
                                <tr><td colspan="6" class="text-center text-muted">`employee_deductions` table is missing.</td></tr>
                            <?php elseif ($deductions && mysqli_num_rows($deductions) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($deductions)): ?>
                                    <?php $row_status = in_array($row['status'], ['active', 'inactive', 'completed'], true) ? $row['status'] : 'inactive'; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['description']); ?></strong><br>
                                            <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $row['deduction_type'])); ?></small>
                                        </td>
                                        <td>&#8369;<?php echo number_format((float)$row['amount_per_payroll'], 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['start_date'])); ?> - <?php echo $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : 'Ongoing'; ?></td>
                                        <td><span class="status-badge badge-<?php echo $row_status; ?>"><?php echo ucfirst($row_status); ?></span></td>
                                        <td>
                                            <button class="btn-icon" onclick='editDeduction(<?php echo json_encode($row); ?>)'><i class="fas fa-edit"></i></button>
                                            <form method="POST" style="display:inline;" data-sw-confirm="1" data-sw-confirm-title="Delete deduction?" data-sw-confirm-text="This deduction record will be permanently removed." data-sw-confirm-confirm-text="Yes, delete">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="delete_deduction">
                                                <input type="hidden" name="deduction_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn-icon btn-icon-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No deductions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Deduction Modal -->
    <div class="modal fade" id="deductionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Add Deduction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="save_deduction">
                        <input type="hidden" name="deduction_id" id="deduction_id">
                        <div class="mb-3">
                            <label>Employee</label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <?php if ($employees): ?>
                                    <?php mysqli_data_seek($employees, 0); while($e = mysqli_fetch_assoc($employees)): ?>
                                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Deduction Type</label>
                            <select name="deduction_type" id="deduction_type" class="form-select" required>
                                <option value="loan">Loan</option>
                                <option value="cash_advance">Cash Advance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <input type="text" name="description" id="description" class="form-control" placeholder="e.g., SSS Loan, Rice Advance" required>
                        </div>
                        <div class="mb-3">
                            <label>Amount per Payroll (&#8369;)</label>
                            <input type="number" name="amount_per_payroll" id="amount_per_payroll" class="form-control" step="0.01" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Start Date</label>
                                <input type="date" name="start_date" id="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>End Date (Optional)</label>
                                <input type="date" name="end_date" id="end_date" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Status</label>
                            <select name="status" id="status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Deduction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function resetForm() {
        $('#modalTitle').text('Add Deduction');
        $('#deduction_id').val('');
        $('#deductionModal form')[0].reset();
    }

    function editDeduction(data) {
        $('#modalTitle').text('Edit Deduction');
        $('#deduction_id').val(data.id);
        $('#employee_id').val(data.employee_id);
        $('#deduction_type').val(data.deduction_type);
        $('#description').val(data.description);
        $('#amount_per_payroll').val(data.amount_per_payroll);
        $('#start_date').val(data.start_date);
        $('#end_date').val(data.end_date);
        $('#status').val(data.status);
        new bootstrap.Modal(document.getElementById('deductionModal')).show();
    }

    // SweetAlert2 for Session Messages
    <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($_SESSION['success']); ?>',
            timer: 2000,
            showConfirmButton: false
        });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Oops...',
            text: '<?php echo addslashes($_SESSION['error']); ?>'
        });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</script>
</body>
</html>



