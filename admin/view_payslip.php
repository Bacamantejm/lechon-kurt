<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

if (!isset($_GET['payroll_id'])) {
    die("Invalid Request");
}

$payroll_id = intval($_GET['payroll_id']);

// Fetch Payroll, Employee, and Payslip Data
$query = "SELECT p.*, p.deductions as other_deductions_total, e.first_name, e.last_name, e.employee_id as emp_code, e.position, e.department_id, e.employment_basis, e.tin_number,
          d.department_name, ps.payslip_number, ps.generated_at, ps.sss_contribution, ps.philhealth_contribution, ps.pagibig_contribution, ps.bir_tax, ps.total_deductions as ps_total_deductions
          FROM payroll p
          JOIN employees e ON p.employee_id = e.id
          LEFT JOIN departments d ON e.department_id = d.id
          LEFT JOIN payslips ps ON p.id = ps.payroll_id
          WHERE p.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $payroll_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);

if (!$data) die("Payslip not found.");

$other_deductions_breakdown = json_decode($data['other_deductions_breakdown'] ?? '[]', true);

// Determine values to display (Prefer stored payslip data, fallback to calculation if previewing)
if (!empty($data['payslip_number'])) {
    // Use stored values for data integrity
    $sss = $data['sss_contribution'];
    $philhealth = $data['philhealth_contribution'];
    $pagibig = $data['pagibig_contribution'];
    $tax = $data['bir_tax'];
    $total_deductions = $data['ps_total_deductions'] ?? $data['other_deductions_total'];
} else {
    // Fallback calculation (only for records before payslip generation)
    $base_salary = $data['base_salary'];
    $sss = min($data['gross_pay'] * 0.045, 1350); 
    $ph_base = max(10000, min($data['gross_pay'], 100000));
    $philhealth = $ph_base * 0.025; // Simplified
    $pagibig = min($data['gross_pay'] * 0.02, 200);
    
    // Simplified tax calc for fallback
    $tax = 0; // Placeholder for fallback
    $total_deductions = $sss + $philhealth + $pagibig + $tax + $data['other_deductions_total'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?php echo htmlspecialchars($data['payslip_number']); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .payslip-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-top: 5px solid #c62828;
            border-radius: 8px;
        }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #333; font-size: 24px; }
        .header h2 { margin: 5px 0; color: #666; font-size: 16px; font-weight: 300; letter-spacing: 2px; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
        .info-row { margin-bottom: 8px; font-size: 14px; }
        .label { font-weight: 600; color: #555; display: inline-block; width: 120px; }
        
        .financials { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .section-title { 
            border-bottom: 2px solid #c62828; 
            padding-bottom: 5px; 
            margin-bottom: 15px; 
            font-weight: 600; 
            text-transform: uppercase; 
            font-size: 16px;
            color: #c62828;
        }
        
        .line-item { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .total-row { 
            display: flex; 
            justify-content: space-between; 
            margin-top: 15px; 
            padding-top: 10px; 
            border-top: 1px solid #ccc; 
            font-weight: 600; 
            font-size: 15px;
        }
        
        .net-pay-section {
            margin-top: 30px;
            background: #e8f5e9;
            padding: 20px;
            text-align: center;
            border-radius: 6px;
        }
        .net-pay-label { font-size: 16px; text-transform: uppercase; font-weight: 600; color: #155724; }
        .net-pay-amount { font-size: 32px; font-weight: 700; color: #155724; }
        
        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #888; }
        .print-btn { 
            display: block; 
            width: 200px; 
            margin: 20px auto; 
            padding: 12px; 
            background: #c62828; 
            color: #fff; 
            text-align: center; 
            text-decoration: none; 
            cursor: pointer;
            border-radius: 5px;
            border: none;
            font-weight: 600;
        }
        body.dark-mode { background: #1a1a1a; color: #e0e0e0; }
        body.dark-mode .payslip-container { background: #2d2d2d; border-top-color: #c62828; color: #e0e0e0; }
        body.dark-mode .header h1, body.dark-mode .header h2, body.dark-mode .label { color: #e0e0e0; }
        body.dark-mode .section-title { border-bottom-color: #c62828; }
        body.dark-mode .total-row { border-top-color: #404040; }
        body.dark-mode .net-pay-section { background: #1c3a24; }
        body.dark-mode .net-pay-label, body.dark-mode .net-pay-amount { color: #a5d6a7; }
        body.dark-mode .footer { color: #aaa; }
        body.dark-mode .print-btn { background: #e53935; }

        .theme-toggler {
            position: fixed; top: 10px; right: 10px; background: #fff; border: 1px solid #ddd;
            width: 40px; height: 40px; border-radius: 50%; cursor: pointer;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .payslip-container { box-shadow: none; border: none; }
            .print-btn, .theme-toggler { display: none; }
        }
    </style>
</head>
<body>

    <div class="payslip-container">
        <div class="header">
            <h1>LECHON DELIGHTS</h1>
            <h2>OFFICIAL PAYSLIP</h2>
            <p><?php echo date('F d, Y', strtotime($data['generated_at'] ?? date('Y-m-d'))); ?></p>
        </div>

        <div class="info-grid">
            <div>
                <div class="info-row"><span class="label">Employee Name:</span> <?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></div>
                <div class="info-row"><span class="label">Employee ID:</span> <?php echo htmlspecialchars($data['emp_code']); ?></div>
                <div class="info-row"><span class="label">Position:</span> <?php echo htmlspecialchars($data['position']); ?></div>
                <div class="info-row"><span class="label">Department:</span> <?php echo htmlspecialchars($data['department_name'] ?? 'General'); ?></div>
            </div>
            <div>
                <div class="info-row"><span class="label">Payroll Period:</span></div>
                <div style="padding-left: 120px;"><?php echo date('M d, Y', strtotime($data['pay_period_start'])); ?> to <?php echo date('M d, Y', strtotime($data['pay_period_end'])); ?></div>
                <div class="info-row" style="margin-top: 8px;"><span class="label">Payslip #:</span> <?php echo htmlspecialchars($data['payslip_number'] ?? '---'); ?></div>
                <div class="info-row"><span class="label">TIN:</span> <?php echo htmlspecialchars($data['tin_number'] ?? 'N/A'); ?></div>
            </div>
        </div>

        <div class="financials">
            <!-- Earnings -->
            <div>
                <div class="section-title">Earnings</div>
                <div class="line-item">
                    <span>Base Salary</span>
                    <span><?php echo number_format($data['base_salary'], 2); ?></span>
                </div>
                <div class="line-item">
                    <span>Overtime Pay</span>
                    <span>+ <?php echo number_format($data['overtime_pay'], 2); ?></span>
                </div>
                <div class="line-item">
                    <span>Holiday Pay</span>
                    <span>+ <?php echo number_format($data['holiday_pay'], 2); ?></span>
                </div>
                <div class="line-item">
                    <span>Allowances/Bonus</span>
                    <span>+ <?php echo number_format($data['bonuses'] + $data['allowances'], 2); ?></span>
                </div>
                <div class="total-row">
                    <span>GROSS EARNINGS</span>
                    <span>₱<?php echo number_format($data['gross_pay'], 2); ?></span>
                </div>
            </div>

            <!-- Deductions -->
            <div>
                <div class="section-title">Deductions</div>
                <div class="line-item">
                    <span>SSS Contribution</span>
                    <span>- <?php echo number_format($sss, 2); ?></span>
                </div>
                <div class="line-item">
                    <span>PhilHealth</span>
                    <span>- <?php echo number_format($philhealth, 2); ?></span>
                </div>
                <div class="line-item">
                    <span>Pag-IBIG</span>
                    <span>- <?php echo number_format($pagibig, 2); ?></span>
                </div>
                <div class="line-item">
                    <span>Withholding Tax</span>
                    <span>- <?php echo number_format($tax, 2); ?></span>
                </div>
                <?php if (isset($data['late_deductions']) && $data['late_deductions'] > 0): ?>
                    <div class="line-item">
                        <span>Late Deductions</span>
                        <span>- <?php echo number_format($data['late_deductions'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($other_deductions_breakdown)): ?>
                    <?php foreach($other_deductions_breakdown as $deduction): ?>
                        <div class="line-item">
                            <span><?php echo htmlspecialchars($deduction['description']); ?></span>
                            <span>- <?php echo number_format($deduction['amount'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="total-row">
                    <span>TOTAL DEDUCTIONS</span>
                    <span>(₱<?php echo number_format($total_deductions, 2); ?>)</span>
                </div>
            </div>
        </div>

        <div class="net-pay-section">
            <div class="net-pay-label">Net Pay</div>
            <div class="net-pay-amount">₱<?php echo number_format($data['net_pay'], 2); ?></div>
        </div>

        <div class="footer">
            <p>This is a computer-generated document and does not require a signature.</p>
            <p>Lechon Delights • 123 Main St, Manila • (02) 8123-4567</p>
        </div>
    </div>

    <button class="theme-toggler" id="themeToggler" title="Toggle Theme"><i class="fas fa-moon"></i></button>
    <a href="#" onclick="window.print()" class="print-btn">Print Payslip</a>

<script>
    const themeToggler = document.getElementById('themeToggler');
    const body = document.body;
    const icon = themeToggler.querySelector('i');

    if (localStorage.getItem('theme') === 'dark') {
        body.classList.add('dark-mode');
        icon.className = 'fas fa-sun';
    }

    themeToggler.addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        const isDark = body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    });
</script>
</body>
</html>
