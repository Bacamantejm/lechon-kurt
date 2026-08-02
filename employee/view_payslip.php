<?php
require_once 'session_check.php';
require_once 'header.php';

// Fetch employee name from users table for topbar
$stmt_user = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$employee_user = $result_user->fetch_assoc();
$stmt_user->close();
?>

<!-- Top Navigation Bar -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="topbar-title">
            <h1>Payslip Details</h1>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <div class="notification-wrapper">
                <button class="theme-toggler" id="notificationBell" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="badge bg-danger" id="notificationCount"></span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <a href="#" id="markAllRead">Mark all as read</a>
                    </div>
                    <div class="notification-list" id="notificationList"><div class="text-center p-3">Loading...</div></div>
                    <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
                </div>
            </div>
            <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>
            <div class="admin-profile">
                <span><?php echo htmlspecialchars($employee_user['full_name']); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<div class="admin-main">
<?php
if (!isset($_GET['id'])) {
    echo "<div class='alert alert-danger'>No payslip ID provided.</div>";
    require_once 'footer.php';
    exit();
}

$payslip_id = intval($_GET['id']);

// Fetch payslip details with employee information, ensuring it belongs to the logged-in employee
$stmt = $conn->prepare("
    SELECT ps.*, e.first_name, e.last_name, e.employee_id as emp_code, d.department_name, pr.payment_method, pr.payment_date
    FROM payslips ps
    JOIN employees e ON ps.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN payroll pr ON ps.payroll_id = pr.id
    WHERE ps.id = ? AND ps.employee_id = ?
");
$stmt->bind_param("ii", $payslip_id, $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<div class='dashboard-header'><h2>Payslip Not Found</h2></div><div class='alert alert-danger'>The requested payslip could not be found or you do not have permission to view it.</div>";
} else {
    $payslip = $result->fetch_assoc();
    ?>
    <div class="payslip-container">
        <div class="payslip-header-section">
            <div class="company-info">
                <h2>LECHON COMPANY</h2>
                <p>OFFICIAL PAYSLIP</p>
            </div>
            <div class="payslip-info">
                <p><strong>Payslip Number:</strong> <?php echo htmlspecialchars($payslip['payslip_number']); ?></p>
                <p><strong>Generated Date:</strong> <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?></p>
            </div>
        </div>

        <div class="employee-info-section">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Employee Name:</strong> <?php echo htmlspecialchars($payslip['first_name'] . ' ' . $payslip['last_name']); ?></p>
                    <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($payslip['emp_code']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Department:</strong> <?php echo htmlspecialchars($payslip['department_name'] ?? 'N/A'); ?></p>
                    <p><strong>Pay Period:</strong> <?php echo date('M d, Y', strtotime($payslip['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($payslip['pay_period_end'])); ?></p>
                </div>
            </div>
        </div>

        <div class="payslip-details-section">
            <div class="row">
                <div class="col-md-6">
                    <div class="earnings-section">
                        <h4>EARNINGS</h4>
                        <table class="payslip-table">
                            <tr>
                                <td>Base Salary</td>
                                <td class="amount">₱<?php echo number_format($payslip['base_salary'], 2); ?></td>
                            </tr>
                            <?php if ($payslip['overtime_hours'] > 0): ?>
                            <tr>
                                <td>Overtime (<?php echo number_format($payslip['overtime_hours'], 2); ?> hrs)</td>
                                <td class="amount">₱<?php echo number_format($payslip['overtime_pay'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['bonuses'] > 0): ?>
                            <tr>
                                <td>Bonuses</td>
                                <td class="amount">₱<?php echo number_format($payslip['bonuses'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['allowances'] > 0): ?>
                            <tr>
                                <td>Allowances</td>
                                <td class="amount">₱<?php echo number_format($payslip['allowances'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td><strong>GROSS PAY</strong></td>
                                <td class="amount"><strong>₱<?php echo number_format($payslip['gross_pay'], 2); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="deductions-section">
                        <h4>DEDUCTIONS</h4>
                        <table class="payslip-table">
                            <?php if ($payslip['sss_contribution'] > 0): ?>
                            <tr>
                                <td>SSS Contribution</td>
                                <td class="amount">₱<?php echo number_format($payslip['sss_contribution'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['philhealth_contribution'] > 0): ?>
                            <tr>
                                <td>PhilHealth Contribution</td>
                                <td class="amount">₱<?php echo number_format($payslip['philhealth_contribution'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['pagibig_contribution'] > 0): ?>
                            <tr>
                                <td>Pag-IBIG Contribution</td>
                                <td class="amount">₱<?php echo number_format($payslip['pagibig_contribution'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['bir_tax'] > 0): ?>
                            <tr>
                                <td>Withholding Tax (BIR)</td>
                                <td class="amount">₱<?php echo number_format($payslip['bir_tax'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payslip['other_deductions'] > 0): ?>
                            <tr>
                                <td>Other Deductions</td>
                                <td class="amount">₱<?php echo number_format($payslip['other_deductions'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td><strong>TOTAL DEDUCTIONS</strong></td>
                                <td class="amount"><strong>₱<?php echo number_format($payslip['total_deductions'], 2); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="net-pay-section">
            <div class="net-pay-box">
                <h3>NET PAY</h3>
                <h2>₱<?php echo number_format($payslip['net_pay'], 2); ?></h2>
            </div>
        </div>

        <div class="payment-info-section">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Payment Method:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payslip['payment_method'] ?? 'N/A'))); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Payment Date:</strong> <?php echo $payslip['payment_date'] ? date('M d, Y', strtotime($payslip['payment_date'])) : 'Pending'; ?></p>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Payslip</button>
            <a href="payslips.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    <style>
        .payslip-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 900px;
            margin: 0 auto;
        }

        .payslip-header-section {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .company-info h2 {
            margin: 0;
            color: #d32f2f;
            font-size: 28px;
        }

        .company-info p {
            margin: 5px 0;
            color: #666;
        }

        .payslip-info {
            margin-top: 15px;
        }

        .employee-info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .employee-info-section p {
            margin: 5px 0;
        }

        .earnings-section, .deductions-section {
            padding: 15px;
        }

        .earnings-section h4 {
            color: #28a745;
            border-bottom: 2px solid #28a745;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .deductions-section h4 {
            color: #d32f2f;
            border-bottom: 2px solid #d32f2f;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .payslip-table {
            width: 100%;
            margin-bottom: 10px;
        }

        .payslip-table tr {
            border-bottom: 1px solid #eee;
        }

        .payslip-table td {
            padding: 8px 0;
        }

        .payslip-table .amount {
            text-align: right;
            font-weight: 500;
        }

        .payslip-table .total-row {
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }

        .payslip-table .total-row td {
            padding: 12px 0;
            font-size: 16px;
        }

        .net-pay-section {
            margin: 30px 0;
        }

        .net-pay-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 25px;
            border-radius: 8px;
        }

        .net-pay-box h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 400;
        }

        .net-pay-box h2 {
            margin: 10px 0 0 0;
            font-size: 36px;
            font-weight: bold;
        }

        .payment-info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .action-buttons {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }

        .action-buttons .btn {
            margin: 0 10px;
        }

        /* Dark Mode Styles */
        body.dark-mode .payslip-container {
            background: #2d2d2d;
            color: #e0e0e0;
        }

        body.dark-mode .payslip-header-section {
            border-bottom-color: #555;
        }

        body.dark-mode .employee-info-section,
        body.dark-mode .payment-info-section {
            background: #1a1a1a;
        }

        body.dark-mode .payslip-table tr {
            border-bottom-color: #404040;
        }

        body.dark-mode .payslip-table .total-row {
            border-top-color: #555;
            border-bottom-color: #555;
        }

        /* Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            
            .payslip-container, .payslip-container * {
                visibility: visible;
            }
            
            .payslip-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
            }
            
            .action-buttons {
                display: none;
            }
            
            .admin-topbar, .admin-sidebar {
                display: none;
            }
        }
    </style>
    <?php
}
$stmt->close();
?>
</div>

<script>
    // Theme Toggler Logic
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
</script>

<?php
require_once 'footer.php';
?>