<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_payroll_table = hrTableExists($conn, 'payroll');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

// Handle Payroll Submission
$selected_employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
if ($is_partner_scoped_hr && $selected_employee_id > 0 && !hrEmployeeIdInScope($conn, $selected_employee_id)) {
    $selected_employee_id = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bulk_payroll'])) {
    hrEnforcePostCsrf('payroll.php');

    if (!$has_payroll_table) {
        $_SESSION['error'] = "Payroll table is not configured yet.";
        header("Location: payroll.php");
        exit();
    }

    $payrolls_to_submit = $_POST['payrolls'] ?? [];
    $pay_period_start = $_POST['pay_period_start'] ?? '';
    $pay_period_end = $_POST['pay_period_end'] ?? '';
    $submitted_count = 0;

    if (!hrIsValidDate($pay_period_start) || !hrIsValidDate($pay_period_end) || $pay_period_start > $pay_period_end) {
        $_SESSION['error'] = "Invalid pay period dates.";
        header("Location: payroll.php");
        exit();
    }

    $insert_sql = "INSERT INTO payroll (
                    employee_id, base_salary, overtime_hours, overtime_pay, bonuses,
                    gross_pay, deductions, net_pay, pay_period_start, pay_period_end,
                    late_deductions, other_deductions_breakdown, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);

    foreach ($payrolls_to_submit as $payroll_json) {
        $p = json_decode($payroll_json, true);
        if (!is_array($p)) {
            continue;
        }

        $employee_id = intval($p['employee_id'] ?? 0);
        $base_salary = floatval($p['base_salary'] ?? 0);
        $overtime_hours = floatval($p['overtime_hours'] ?? 0);
        $overtime_pay = floatval($p['details']['overtime_pay'] ?? 0);
        $bonuses = floatval($p['bonuses'] ?? 0);
        $gross_pay = floatval($p['gross_pay'] ?? 0);
        $total_deductions = floatval($p['total_deductions'] ?? 0);
        $net_pay = floatval($p['net_pay'] ?? 0);
        $late_deduction = floatval($p['details']['late_deduction'] ?? 0);

        if ($employee_id <= 0 || $gross_pay < 0 || $total_deductions < 0 || $net_pay < 0) {
            continue;
        }
        if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
            continue;
        }

        $deductions_json = json_encode($p['deduction_breakdown'] ?? []);
        mysqli_stmt_bind_param(
            $insert_stmt,
            "idddddddssds",
            $employee_id,
            $base_salary,
            $overtime_hours,
            $overtime_pay,
            $bonuses,
            $gross_pay,
            $total_deductions,
            $net_pay,
            $pay_period_start,
            $pay_period_end,
            $late_deduction,
            $deductions_json
        );

        if (mysqli_stmt_execute($insert_stmt)) {
            $submitted_count++;
        }
    }
    if ($insert_stmt) {
        mysqli_stmt_close($insert_stmt);
    }

    if ($submitted_count > 0) {
        $_SESSION['success'] = "$submitted_count payrolls have been submitted to Finance for approval.";
    } else {
        $_SESSION['error'] = "No valid payrolls were submitted.";
    }
    header("Location: payroll.php");
    exit();
}

// Fetch all employees
$employees_query = "SELECT e.id, e.first_name, e.last_name, e.position, e.department_id, e.salary, e.daily_rate
                    FROM employees e
                    WHERE e.status = 'active'" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "") . "
                    ORDER BY e.first_name ASC";
$employees_result = mysqli_query($conn, $employees_query);
$employees = array();
if ($employees_result && mysqli_num_rows($employees_result) > 0) {
    while ($row = mysqli_fetch_assoc($employees_result)) {
        $employees[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        body.dark-mode .admin-table td {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1 {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small {
            color: #b0b0b0 !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin-right: 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }

        .nav-tabs .nav-link.active {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
            color: #c62828 !important;
        }
    </style>
</head>
<body class="hr-theme">
    <div class="page-loader"><div class="spinner"></div></div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Payroll Management</h1>
                    <div class="topbar-right">
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                            <i class="fas fa-moon"></i>
                        </button>
                        <div class="date-display" id="currentDate"></div>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <div class="card hr-filter-panel mb-4">
                    <div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <div>
                            <strong>Payroll Workflow:</strong>
                            Draft payroll here, then Finance approves and generates final payslips.
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="deductions.php" class="btn btn-secondary btn-sm"><i class="fas fa-hand-holding-usd"></i> Deductions</a>
                            <a href="payslip_generation.php" class="btn btn-secondary btn-sm"><i class="fas fa-file-invoice-dollar"></i> Payslips</a>
                            <a href="hr_reports.php?type=payroll" class="btn btn-secondary btn-sm"><i class="fas fa-chart-bar"></i> Payroll Reports</a>
                        </div>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-4" id="payrollTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="run-payroll-tab" data-bs-toggle="tab" data-bs-target="#runPayroll" type="button" role="tab">Run Payroll</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#payrollHistory" type="button" role="tab">Payroll History</button>
                    </li>
                </ul>

                <div class="tab-content" id="payrollTabContent">
                    <!-- Run Payroll Tab -->
                    <div class="tab-pane fade show active" id="runPayroll" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5>Step 1: Select Period and Employees</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label for="pay_period_start" class="form-label">Pay Period Start</label>
                                        <input type="date" id="pay_period_start" class="form-control" value="<?= date('Y-m-01') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="pay_period_end" class="form-label">Pay Period End</label>
                                        <input type="date" id="pay_period_end" class="form-control" value="<?= date('Y-m-t') ?>">
                                    </div>
                                    <div class="col-md-4 position-relative">
                                        <label for="employee_select" class="form-label d-flex justify-content-between">
                                            <span>Select Employees</span>
                                            <a href="#" id="selectAllEmployees" class="small text-decoration-none">Select All</a>
                                        </label>
                                        <select id="employee_select" class="form-select" multiple size="3">
                                            <?php foreach($employees as $emp): ?>
                                                <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id == 0 || $selected_employee_id == $emp['id']) ? 'selected' : '' ?>><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Hold Ctrl/Cmd to select multiple.</small>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button id="generateDraftBtn" class="btn btn-primary w-100"><i class="fas fa-cogs"></i> Generate Draft Payroll</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="draftSection" class="mt-4" style="display: none;">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Step 2: Review Draft and Submit</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="submitPayrollForm">
                                        <input type="hidden" name="submit_bulk_payroll" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="pay_period_start" id="form_pay_period_start">
                                        <input type="hidden" name="pay_period_end" id="form_pay_period_end">
                                        <div class="table-responsive">
                                            <table class="admin-table">
                                                <thead>
                                                    <tr>
                                                        <th><input type="checkbox" class="form-check-input" id="selectAllDrafts" checked></th>
                                                        <th>Employee</th>
                                                        <th class="text-end">Gross Pay</th>
                                                        <th class="text-end">Deductions</th>
                                                        <th class="text-end">Net Pay</th>
                                                        <th>Details</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="draftTableBody">
                                                    <!-- Drafts will be populated by JS -->
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="mt-3">
                                            <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane"></i> Submit Selected for Approval</button>
                                            <button type="button" id="discardDraftBtn" class="btn btn-secondary">Discard Draft</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payroll History Tab -->
                    <div class="tab-pane fade" id="payrollHistory" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5>Processed Payrolls</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover admin-table">
                                        <thead>
                                            <tr>
                                                <th>Employee</th>
                                                <th>Pay Period</th>
                                                <th>Net Pay</th>
                                                <th>Status</th>
                                                <th>Date Processed</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            if (!$has_payroll_table) {
                                                echo "<tr><td colspan='6' class='text-center text-muted'>`payroll` table is missing. Configure payroll tables first.</td></tr>";
                                            } else {
                                            $processed_query = "SELECT p.*, e.first_name, e.last_name
                                                                FROM payroll p
                                                                JOIN employees e ON p.employee_id = e.id" . ($is_partner_scoped_hr ? " WHERE {$employee_scope_sql}" : "") . "
                                                                ORDER BY p.created_at DESC
                                                                LIMIT 100";
                                            $processed_result = mysqli_query($conn, $processed_query);
                                            if ($processed_result && mysqli_num_rows($processed_result) > 0) {
                                                while ($row = mysqli_fetch_assoc($processed_result)) {
                                                    $status_raw = strtolower((string)$row['status']);
                                                    $status_class = match($status_raw) {
                                                        'approved', 'paid' => 'badge-success',
                                                        'pending' => 'badge-warning',
                                                        'rejected' => 'badge-danger',
                                                        default => 'badge-secondary'
                                                    };
                                                    $full_name = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
                                                    $status_label = htmlspecialchars(ucfirst($status_raw));
                                                    echo "<tr>
                                                        <td><strong>{$full_name}</strong></td>
                                                        <td>" . date('M d, Y', strtotime($row['pay_period_start'])) . " - " . date('M d, Y', strtotime($row['pay_period_end'])) . "</td>
                                                        <td><strong>PHP " . number_format($row['net_pay'], 2) . "</strong></td>
                                                        <td><span class='status-badge {$status_class}'>{$status_label}</span></td>
                                                        <td>" . date('M d, Y H:i', strtotime($row['created_at'])) . "</td>
                                                        <td><a href='view_payslip.php?payroll_id={$row['id']}' target='_blank' class='btn-icon' title='View Payslip'><i class='fas fa-eye'></i></a></td>
                                                    </tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='6' class='text-center text-muted'>No processed payrolls yet</td></tr>";
                                            }
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
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

        // SweetAlert2 for session messages and actions
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo addslashes($_SESSION['success']); ?>',
                timer: 3000,
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
        
        $('#selectAllEmployees').on('click', function(e) {
            e.preventDefault();
            const select = $('#employee_select');
            const allOptions = select.find('option');
            const allSelected = allOptions.length > 0 && allOptions.length === select.find('option:selected').length;

            if (allSelected) {
                allOptions.prop('selected', false);
                $(this).text('Select All');
            } else {
                allOptions.prop('selected', true);
                $(this).text('Deselect All');
            }
        });

        const employees = <?= json_encode($employees) ?>;

        $('#generateDraftBtn').on('click', function() {
            const btn = $(this);
            const originalText = btn.html();
            btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...').prop('disabled', true);

            const employeeIds = $('#employee_select').val();

            if (!employeeIds || employeeIds.length === 0) {
                Swal.fire('No Employees Selected', 'Please select at least one employee to run payroll for.', 'warning');
                btn.html(originalText).prop('disabled', false);
                return;
            }

            $.ajax({
                url: 'ajax_payroll.php',
                type: 'POST',
                data: {
                    employee_ids: employeeIds,
                    start_date: $('#pay_period_start').val(),
                    end_date: $('#pay_period_end').val(),
                    csrf_token: <?php echo json_encode($csrf_token); ?>
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const draftTableBody = $('#draftTableBody');
                        draftTableBody.empty();
                        response.data.forEach(p => {
                            const details = p.details;
                            let detailsHtml = `Reg Pay: PHP ${details.regular_pay.toFixed(2)}<br>OT Pay: PHP ${details.overtime_pay.toFixed(2)}<br>Late Ded: -PHP ${details.late_deduction.toFixed(2)}`;
                            if (p.deduction_breakdown && p.deduction_breakdown.length > 0) {
                                p.deduction_breakdown.forEach(d => {
                                    detailsHtml += `<br><span class="text-danger small">${escapeHtml(d.description)}: -PHP ${d.amount.toFixed(2)}</span>`;
                                });
                            }
                            const row = `
                                <tr data-employee-id="${p.employee_id}">
                                    <td><input type="checkbox" class="form-check-input draft-checkbox" name="payrolls[]" value='${JSON.stringify(p)}' checked></td>
                                    <td>${p.full_name}</td>
                                    <td class="text-end gross-pay">PHP ${p.gross_pay.toFixed(2)}</td>
                                    <td class="text-end total-deductions">PHP ${p.total_deductions.toFixed(2)}</td>
                                    <td class="text-end net-pay"><strong>PHP ${p.net_pay.toFixed(2)}</strong></td>
                                    <td>
                                        <small class="payroll-details">${detailsHtml}</small>
                                        <div class="input-group input-group-sm mt-2" style="max-width: 200px;">
                                            <span class="input-group-text">Bonus PHP </span>
                                            <input type="number" class="form-control bonus-input" value="0" step="50" min="0">
                                        </div>
                                    </td>
                                </tr>`;
                            draftTableBody.append(row);
                        });
                        $('#draftSection').slideDown();
                        $('#form_pay_period_start').val($('#pay_period_start').val());
                        $('#form_pay_period_end').val($('#pay_period_end').val());
                    } else {
                        Swal.fire('Error', response.message || 'Failed to generate draft.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'An unexpected error occurred.', 'error');
                },
                complete: function() {
                    btn.html(originalText).prop('disabled', false);
                }
            });
        });

        function escapeHtml(text) {
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        $('#draftTableBody').on('input', '.bonus-input', function() {
            const row = $(this).closest('tr');
            const checkbox = row.find('.draft-checkbox');
            let payrollData = JSON.parse(checkbox.val());

            const bonus = parseFloat($(this).val()) || 0;

            // Recalculate pays
            const originalGross = payrollData.details.regular_pay + payrollData.details.overtime_pay;
            const newGross = originalGross + bonus;
            const newNet = newGross - payrollData.total_deductions;

            // Update the UI
            row.find('.gross-pay').text('PHP ' + newGross.toFixed(2));
            row.find('.net-pay').html('<strong>PHP ' + newNet.toFixed(2) + '</strong>');

            // Update the data stored in the checkbox value
            payrollData.bonuses = bonus;
            payrollData.gross_pay = newGross;
            payrollData.net_pay = newNet;
            checkbox.val(JSON.stringify(payrollData));
        });

        $('#discardDraftBtn').on('click', function() {
            $('#draftSection').slideUp(function() {
                $('#draftTableBody').empty();
            });
        });

        $('#selectAllDrafts').on('change', function() {
            $('.draft-checkbox').prop('checked', $(this).prop('checked'));
        });

        $('#submitPayrollForm').on('submit', function(e) {
            e.preventDefault();
            const selectedCount = $('.draft-checkbox:checked').length;
            if (selectedCount === 0) {
                Swal.fire('No Selection', 'Please select at least one payroll to submit.', 'warning');
                return;
            }

            Swal.fire({
                title: `Submit ${selectedCount} Payrolls?`,
                text: "The selected payrolls will be sent to Finance for approval.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Submit',
                confirmButtonColor: '#28a745'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Uncheck the ones not selected so they don't get submitted
                    $('.draft-checkbox:not(:checked)').prop('disabled', true);
                    e.target.submit();
                }
            });
        });
    </script>
</body>
</html>


