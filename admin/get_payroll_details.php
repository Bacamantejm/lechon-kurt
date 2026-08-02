<?php
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();

if (!isset($_GET['id'])) {
    die("Invalid payroll record");
}

$payroll_id = intval($_GET['id']);

$query = "SELECT p.*, e.first_name, e.last_name, e.employee_id
          FROM payroll p
          JOIN employees e ON p.employee_id = e.id
          WHERE p.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $payroll_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$payroll = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$payroll) {
    die("Payroll record not found");
}
?>

<div class="payroll-details">
    <div class="payroll-header">
        <h5><?php echo htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']); ?></h5>
        <span class="employee-id">ID: <?php echo htmlspecialchars($payroll['employee_id']); ?></span>
    </div>
    
    <div class="payroll-period">
        <strong>Pay Period:</strong> <?php echo date('M d, Y', strtotime($payroll['pay_period_start'])); ?> - <?php echo date('M d, Y', strtotime($payroll['pay_period_end'])); ?>
    </div>
    
    <div class="payroll-breakdown">
        <table class="payroll-table">
            <tr>
                <td>Base Salary</td>
                <td class="amount">₱<?php echo number_format($payroll['base_salary'], 2); ?></td>
            </tr>
            <tr>
                <td>Overtime Hours</td>
                <td class="amount"><?php echo $payroll['overtime_hours']; ?> hrs</td>
            </tr>
            <tr>
                <td>Overtime Pay</td>
                <td class="amount">₱<?php echo number_format($payroll['overtime_pay'], 2); ?></td>
            </tr>
            <tr>
                <td>Bonuses</td>
                <td class="amount">₱<?php echo number_format($payroll['bonuses'], 2); ?></td>
            </tr>
            <tr class="total-row">
                <td>Gross Pay</td>
                <td class="amount">₱<?php echo number_format($payroll['gross_pay'], 2); ?></td>
            </tr>
            <tr class="deduction-row">
                <td>Late Deductions</td>
                <td class="amount">-₱<?php echo number_format($payroll['late_deductions'] ?? 0, 2); ?></td>
            </tr>
            <tr class="deduction-row">
                <td>Total Deductions</td>
                <td class="amount">-₱<?php echo number_format($payroll['deductions'], 2); ?></td>
            </tr>
            <tr class="final-row">
                <td><strong>Net Pay</strong></td>
                <td class="amount"><strong>₱<?php echo number_format($payroll['net_pay'], 2); ?></strong></td>
            </tr>
        </table>
    </div>
    
    <div class="payroll-footer">
        <div class="info-row">
            <label>Payment Method:</label>
            <span><?php echo ucfirst(str_replace('_', ' ', $payroll['payment_method'])); ?></span>
        </div>
        <div class="info-row">
            <label>Payment Date:</label>
            <span><?php echo $payroll['payment_date'] ? date('M d, Y', strtotime($payroll['payment_date'])) : 'Not paid'; ?></span>
        </div>
        <div class="info-row">
            <label>Status:</label>
            <span class="status-badge badge-<?php echo $payroll['status']; ?>"><?php echo ucfirst($payroll['status']); ?></span>
        </div>
        <?php if (!empty($payroll['payment_proof_path'])): ?>
        <div class="info-row">
            <label>Payment Proof:</label>
            <span><a href="../<?php echo htmlspecialchars($payroll['payment_proof_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">View Proof</a></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.payroll-details {
    padding: 10px 0;
}
.payroll-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #eee;
}
.payroll-header h5 {
    margin: 0;
    font-size: 16px;
}
.employee-id {
    font-size: 12px;
    color: #999;
}
.payroll-period {
    font-size: 13px;
    color: #666;
    margin-bottom: 15px;
}
.payroll-table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
    font-size: 13px;
}
.payroll-table tr {
    border-bottom: 1px solid #eee;
}
.payroll-table td {
    padding: 8px 0;
}
.payroll-table td:first-child {
    color: #666;
}
.payroll-table .amount {
    text-align: right;
    font-weight: 600;
}
.payroll-table .total-row {
    background: #f5f5f5;
    font-weight: 600;
}
.payroll-table .deduction-row td:first-child {
    color: #d32f2f;
}
.payroll-table .deduction-row .amount {
    color: #d32f2f;
}
.payroll-table .final-row {
    background: #e8f5e9;
    font-weight: 700;
}
.payroll-table .final-row .amount {
    color: #1b5e20;
}
.payroll-footer {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #eee;
}
.info-row {
    display: grid;
    grid-template-columns: 120px 1fr;
    padding: 8px 0;
}
.info-row label {
    font-weight: 600;
    font-size: 12px;
    color: #999;
}
.info-row span {
    font-size: 14px;
    color: #333;
}
.btn-sm {
    padding: .25rem .5rem;
    font-size: .875rem;
}
</style>
