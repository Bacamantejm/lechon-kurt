<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_payroll_table = hrTableExists($conn, 'payroll');
$has_payslips_table = hrTableExists($conn, 'payslips');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

// Calculate tax based on employee salary
function calculateBIRTax($gross_pay) {
    // Simplified BIR tax calculation for Philippines 2026
    $taxable_income = $gross_pay;
    if ($taxable_income <= 20833) {
        return 0;
    } elseif ($taxable_income <= 33333) {
        return ($taxable_income - 20833) * 0.05;
    } elseif ($taxable_income <= 66667) {
        return 625 + ($taxable_income - 33333) * 0.10;
    } elseif ($taxable_income <= 166667) {
        return 4041.67 + ($taxable_income - 66667) * 0.15;
    } else {
        return 19041.67 + ($taxable_income - 166667) * 0.20;
    }
}

// Get deduction rates
function getDeductionRates($conn) {
    $rates = [];
    if (!hrTableExists($conn, 'deduction_rates')) {
        return $rates;
    }

    $query = "SELECT * FROM deduction_rates WHERE active = 1 AND year = YEAR(CURDATE())";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rates[$row['rate_type']] = $row;
        }
    }
    return $rates;
}

// Handle payslip generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('payslip_generation.php');

    if (!$has_payroll_table || !$has_payslips_table) {
        $_SESSION['error'] = "Payroll/Payslip tables are not fully configured.";
        header("Location: payslip_generation.php");
        exit();
    }

    if (isset($_POST['generate_payslip'])) {
        $payroll_id = intval($_POST['payroll_id']);
        
        // Get payroll data
        $payroll_query = "SELECT p.*, e.id as emp_id, e.sss_number, e.philhealth_number, e.pagibig_number
                         FROM payroll p 
                         JOIN employees e ON p.employee_id = e.id 
                         WHERE p.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");
        $stmt = mysqli_prepare($conn, $payroll_query);
        mysqli_stmt_bind_param($stmt, "i", $payroll_id);
        mysqli_stmt_execute($stmt);
        $payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if ($payroll) {
            $exists_stmt = mysqli_prepare($conn, "SELECT id FROM payslips WHERE payroll_id = ? LIMIT 1");
            mysqli_stmt_bind_param($exists_stmt, "i", $payroll_id);
            mysqli_stmt_execute($exists_stmt);
            $exists_result = mysqli_stmt_get_result($exists_stmt);
            $already_generated = $exists_result && mysqli_num_rows($exists_result) > 0;
            mysqli_stmt_close($exists_stmt);

            if ($already_generated) {
                $_SESSION['error'] = "Payslip already generated for this payroll record.";
                header("Location: payslip_generation.php");
                exit();
            }

            $rates = getDeductionRates($conn);
            
            // Calculate deductions
            $base = $payroll['base_salary'];
            $sss_contrib = isset($rates['sss']) ? min($base * $rates['sss']['employee_rate'], $rates['sss']['salary_ceiling'] * $rates['sss']['employee_rate']) : 0;
            $philhealth_contrib = isset($rates['philhealth']) ? min($base * $rates['philhealth']['employee_rate'], $rates['philhealth']['salary_ceiling'] * $rates['philhealth']['employee_rate']) : 0;
            $pagibig_contrib = isset($rates['pagibig']) ? min($base * $rates['pagibig']['employee_rate'], $rates['pagibig']['salary_ceiling'] * $rates['pagibig']['employee_rate']) : 0;
            
            $gross_before_tax = $base + $payroll['overtime_pay'] + $payroll['bonuses'];
            $bir_tax = calculateBIRTax($gross_before_tax);
            
            $total_deductions = $sss_contrib + $philhealth_contrib + $pagibig_contrib + $bir_tax + $payroll['deductions'];
            $net_pay = $gross_before_tax - $total_deductions;
            
            $payslip_number = 'PS-' . date('Ymd') . '-' . str_pad($payroll['id'], 5, '0', STR_PAD_LEFT);
            
            $insert = "INSERT INTO payslips (employee_id, payroll_id, payslip_number, pay_period_start, pay_period_end, 
                       base_salary, overtime_hours, overtime_pay, bonuses, allowances, gross_pay,
                       sss_contribution, philhealth_contribution, pagibig_contribution, bir_tax, other_deductions,
                       total_deductions, net_pay, generated_at, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'generated')";
            
            $stmt = mysqli_prepare($conn, $insert);
            mysqli_stmt_bind_param($stmt, "iissddddddddddddd", 
                $payroll['employee_id'], $payroll_id, $payslip_number,
                $payroll['pay_period_start'], $payroll['pay_period_end'],
                $payroll['base_salary'], $payroll['overtime_hours'], $payroll['overtime_pay'],
                $payroll['bonuses'], $gross_before_tax,
                $sss_contrib, $philhealth_contrib, $pagibig_contrib, $bir_tax,
                $payroll['deductions'], $total_deductions, $net_pay
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Payslip generated successfully.";
            } else {
                $_SESSION['error'] = "Error generating payslip.";
            }
            mysqli_stmt_close($stmt);
        }

        if (!$payroll) {
            $_SESSION['error'] = "Payroll record is not available in your HR scope.";
        }

        header("Location: payslip_generation.php?month=" . urlencode((string)($_GET['month'] ?? date('n'))) . "&year=" . urlencode((string)($_GET['year'] ?? date('Y'))));
        exit();
    }
}

$current_year = intval(date('Y'));
$search_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
$search_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
$search_month = ($search_month >= 1 && $search_month <= 12) ? $search_month : intval(date('n'));
$search_year = ($search_year >= $current_year - 5 && $search_year <= $current_year + 1) ? $search_year : $current_year;

$where = "WHERE MONTH(p.pay_period_end) = $search_month AND YEAR(p.pay_period_end) = $search_year";
if ($is_partner_scoped_hr) {
    $where .= " AND {$employee_scope_sql}";
}

$result = false;
if ($has_payroll_table && $has_payslips_table) {
    $query = "SELECT p.*, e.first_name, e.last_name, e.employee_id, 
              COUNT(ps.id) as payslip_count
              FROM payroll p
              JOIN employees e ON p.employee_id = e.id
              LEFT JOIN payslips ps ON p.id = ps.payroll_id
              $where
              GROUP BY p.id
              ORDER BY p.pay_period_end DESC";
    $result = mysqli_query($conn, $query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip Generation - HR Management</title>
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
                    <h1>Payslip Generation</h1>
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
                
                <div class="section-header">
                    <h2>Generate & Manage Payslips</h2>
                    <a href="payroll.php" class="btn btn-secondary"><i class="fas fa-money-check-alt"></i> Open Payroll</a>
                </div>

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label mb-1">Month</label>
                                <select id="monthFilter" class="form-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($m == $search_month) ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Year</label>
                                <select id="yearFilter" class="form-select">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y == $search_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex flex-wrap gap-2 justify-content-md-end">
                                <a href="deductions.php" class="btn btn-secondary btn-sm"><i class="fas fa-hand-holding-usd"></i> Deductions</a>
                                <a href="hr_reports.php?type=payroll&month=<?php echo $search_month; ?>&year=<?php echo $search_year; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-chart-bar"></i> Payroll Report</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Employee ID</th>
                                <th>Pay Period</th>
                                <th>Base Salary</th>
                                <th>Gross Pay</th>
                                <th>Net Pay</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$has_payroll_table || !$has_payslips_table): ?>
                                <tr><td colspan="8" class="text-center text-muted">Payroll/Payslip tables are missing. Please run the HR payroll migrations first.</td></tr>
                            <?php elseif ($result && mysqli_num_rows($result) > 0): ?>
                                <?php while ($pay = mysqli_fetch_assoc($result)): ?>
                                    <?php
                                    $payslip_status = $pay['payslip_count'] > 0 ? 'Generated' : 'Pending';
                                    $status_badge = $pay['payslip_count'] > 0 ? 'bg-success' : 'bg-warning';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($pay['employee_id']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($pay['pay_period_start'])) . " - " . date('M d, Y', strtotime($pay['pay_period_end'])); ?></td>
                                        <td>&#8369;<?php echo number_format((float)$pay['base_salary'], 2); ?></td>
                                        <td>&#8369;<?php echo number_format((float)$pay['gross_pay'], 2); ?></td>
                                        <td><strong>&#8369;<?php echo number_format((float)$pay['net_pay'], 2); ?></strong></td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $payslip_status; ?></span></td>
                                        <td>
                                            <?php if ((int)$pay['payslip_count'] === 0): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="payroll_id" value="<?php echo (int)$pay['id']; ?>">
                                                    <button type="submit" name="generate_payslip" class="btn-icon" title="Generate Payslip">
                                                        <i class="fas fa-file-pdf"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn-icon" onclick="viewPayslip(<?php echo (int)$pay['id']; ?>)" title="View Payslip">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn-icon" onclick="emailPayslip(<?php echo (int)$pay['id']; ?>)" title="Email to Employee">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted">No payroll records found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Filter handlers
        document.getElementById('monthFilter').addEventListener('change', function() {
            const month = this.value;
            const year = document.getElementById('yearFilter').value;
            window.location.href = 'payslip_generation.php?month=' + month + '&year=' + year;
        });
        
        document.getElementById('yearFilter').addEventListener('change', function() {
            const year = this.value;
            const month = document.getElementById('monthFilter').value;
            window.location.href = 'payslip_generation.php?month=' + month + '&year=' + year;
        });
        
        function viewPayslip(payrollId) {
            window.open('view_payslip.php?payroll_id=' + payrollId, '_blank');
        }

        async function emailPayslip(payrollId) {
            const confirmed = window.swalConfirmAction
                ? await window.swalConfirmAction({
                    title: 'Send payslip by email?',
                    text: 'The employee will receive this payslip in their email inbox.',
                    icon: 'question',
                    confirmButtonText: 'Yes, send email'
                })
                : (typeof Swal !== 'undefined'
                    ? (await Swal.fire({
                        title: 'Send payslip by email?',
                        text: 'The employee will receive this payslip in their email inbox.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, send email',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33'
                    })).isConfirmed
                    : false);
            if(confirmed) {
                window.location.href = 'send_payslip.php?payroll_id=' + payrollId;
            }
        }
    </script>
</body>
</html>



