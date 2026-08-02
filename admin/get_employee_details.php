<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
hrEnsureNormalizedPositionModel($conn);

if (!isset($_GET['id'])) {
    die("Invalid employee");
}

$employee_id = intval($_GET['id']);
if (hrIsPartnerScopeEnabled($conn) && !hrEmployeeIdInScope($conn, $employee_id)) {
    die("Employee not found");
}

$query = "SELECT e.*, d.department_name, COALESCE(jp.position_title, e.position) AS position_label FROM employees e
          LEFT JOIN departments d ON e.department_id = d.id
          LEFT JOIN job_positions jp ON jp.id = e.position_id
          WHERE e.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $employee_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$employee = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$employee) {
    die("Employee not found");
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($employee);
    exit;
}
?>

<div class="employee-details">
    <div class="employee-header">
        <h5><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h5>
        <p><?php echo htmlspecialchars($employee['position_label']); ?></p>
    </div>
    
    <div class="details-grid">
        <div class="detail-item">
            <label>Employee ID</label>
            <p><?php echo htmlspecialchars($employee['employee_id']); ?></p>
        </div>
        <div class="detail-item">
            <label>Email</label>
            <p><?php echo htmlspecialchars($employee['email']); ?></p>
        </div>
        <div class="detail-item">
            <label>Phone</label>
            <p><?php echo htmlspecialchars(isset($employee['phone']) ? $employee['phone'] : 'Not provided'); ?></p>
        </div>
        <div class="detail-item">
            <label>Department</label>
            <p><?php echo htmlspecialchars(isset($employee['department_name']) ? $employee['department_name'] : 'Unassigned'); ?></p>
        </div>
        <div class="detail-item">
            <label>Employment Type</label>
            <p><?php echo ucfirst(str_replace('_', ' ', $employee['employment_type'])); ?></p>
        </div>
        <div class="detail-item">
            <label>Compensation</label>
            <p>
                <?php if($employee['employment_basis'] == 'daily'): ?>
                    ₱<?php echo number_format($employee['daily_rate'], 2); ?> / day
                <?php else: ?>
                    ₱<?php echo number_format($employee['salary'], 2); ?> / month
                <?php endif; ?>
            </p>
        </div>
        <div class="detail-item">
            <label>Hire Date</label>
            <p><?php echo date('M d, Y', strtotime($employee['hire_date'])); ?></p>
        </div>
        <div class="detail-item">
            <label>Status</label>
            <p><span class="status-badge badge-<?php echo str_replace('_', '-', $employee['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $employee['status'])); ?></span></p>
        </div>
        <div class="detail-item">
            <label>Emergency Contact</label>
            <p><?php echo htmlspecialchars(isset($employee['emergency_contact']) ? $employee['emergency_contact'] : 'Not provided'); ?></p>
        </div>
        <div class="detail-item">
            <label>Emergency Phone</label>
            <p><?php echo htmlspecialchars(isset($employee['emergency_phone']) ? $employee['emergency_phone'] : 'Not provided'); ?></p>
        </div>
        <div class="detail-item" style="grid-column: span 2;">
            <label>Government Compliance</label>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 13px;">
                <div><strong>SSS:</strong><br><?php echo htmlspecialchars($employee['sss_number'] ?? 'N/A'); ?></div>
                <div><strong>PhilHealth:</strong><br><?php echo htmlspecialchars($employee['philhealth_number'] ?? 'N/A'); ?></div>
                <div><strong>Pag-IBIG:</strong><br><?php echo htmlspecialchars($employee['pagibig_number'] ?? 'N/A'); ?></div>
                <div><strong>TIN:</strong><br><?php echo htmlspecialchars($employee['tin_number'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </div>
</div>

<style>
.employee-details {
    padding: 10px 0;
}
.employee-header {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #eee;
}
.employee-header h5 {
    margin: 0 0 5px 0;
    font-size: 18px;
}
.employee-header p {
    margin: 0;
    color: #666;
    font-size: 14px;
}
.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}
.detail-item {
    padding: 10px;
    background: #f5f5f5;
    border-radius: 4px;
}
.detail-item label {
    display: block;
    font-weight: 600;
    font-size: 12px;
    color: #999;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.detail-item p {
    margin: 0;
    font-size: 14px;
    color: #333;
}
</style>

