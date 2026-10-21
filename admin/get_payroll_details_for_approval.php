<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once __DIR__ . '/hr_module_common.php';

checkAdminAccess();
requireAnyPermission(['finance.view', 'finance.manage']);
$csrf_token = generateCSRFToken();
$admin_user_id = intval($_SESSION['user_id'] ?? 0);
$can_manage_finance = true;
if (function_exists('hasPermission')) {
    $can_manage_finance = hasPermission($conn, $admin_user_id, 'finance.manage');
}
if ((string)($_SESSION['role_name'] ?? '') === 'super_admin') {
    $can_manage_finance = true;
}

$payroll_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($payroll_id <= 0) {
    http_response_code(400);
    die('Invalid request: no payroll ID provided.');
}

if (function_exists('hrPayrollIdInScope') && !hrPayrollIdInScope($conn, $payroll_id)) {
    http_response_code(403);
    die('Access denied: payroll record is outside your finance scope.');
}

function calculatePayrollBreakdown($attendance_result, $employee_details) {
    $payroll = [
        'attendance_records' => [],
        'total_hours_worked' => 0,
        'late_hours' => 0,
        'overtime_hours' => 0,
        'gross_pay' => 0,
        'late_deduction' => 0,
        'overtime_pay' => 0,
        'total_deductions' => 0,
        'net_pay' => 0,
        'regular_pay' => 0,
        'overtime_premium' => 0,
        'hourly_rate' => 0
    ];

    $total_hours = 0;
    $late_hours = 0;
    $overtime_hours = 0;
    $daily_hours = 8;
    $hourly_rate = 0;

    $employment_basis = strtolower(trim((string)($employee_details['employment_basis'] ?? '')));
    if ($employment_basis === 'monthly') {
        $monthly_salary = floatval($employee_details['salary'] ?? 0);
        $hourly_rate = $monthly_salary > 0 ? ($monthly_salary / 21.75) / $daily_hours : 0;
    } else {
        $daily_rate = floatval($employee_details['daily_rate'] ?? 0);
        $hourly_rate = $daily_rate > 0 ? $daily_rate / $daily_hours : 0;
    }

    $attendance_records = [];
    while ($attendance_result && ($row = mysqli_fetch_assoc($attendance_result))) {
        $record = [
            'date' => $row['attendance_date'],
            'time_in' => $row['check_in_time'],
            'time_out' => $row['check_out_time'],
            'status' => $row['status'],
            'hours_worked' => 0,
            'is_late' => false,
            'late_minutes' => 0,
            'is_overtime' => false,
            'overtime_hours' => 0
        ];

        if (!empty($row['check_in_time']) && !empty($row['check_out_time'])) {
            $check_in = new DateTime($row['attendance_date'] . ' ' . $row['check_in_time']);
            $check_out = new DateTime($row['attendance_date'] . ' ' . $row['check_out_time']);
            if ($check_out < $check_in) {
                $check_out->modify('+1 day');
            }

            $interval = $check_in->diff($check_out);
            $hours_worked = $interval->h + ($interval->i / 60);

            $expected_time = new DateTime($row['attendance_date'] . ' 10:00:00');
            if ($check_in > $expected_time) {
                $late_interval = $check_in->diff($expected_time);
                $record['is_late'] = true;
                $record['late_minutes'] = abs($late_interval->i + ($late_interval->h * 60));
                $late_hours += ($record['late_minutes'] / 60);
            }

            if ($hours_worked > $daily_hours) {
                $record['is_overtime'] = true;
                $record['overtime_hours'] = $hours_worked - $daily_hours;
                $overtime_hours += $record['overtime_hours'];
                $total_hours += $daily_hours;
            } else {
                $total_hours += $hours_worked;
            }
            $record['hours_worked'] = $hours_worked;
        }

        $attendance_records[] = $record;
    }

    $payroll['attendance_records'] = $attendance_records;
    $payroll['total_hours_worked'] = round($total_hours, 2);
    $payroll['late_hours'] = round($late_hours, 2);
    $payroll['overtime_hours'] = round($overtime_hours, 2);
    $payroll['hourly_rate'] = round($hourly_rate, 2);
    $payroll['regular_pay'] = round($total_hours * $hourly_rate, 2);
    $payroll['overtime_pay'] = round($overtime_hours * $hourly_rate * 1.25, 2);
    $payroll['late_deduction'] = round($late_hours * $hourly_rate, 2);

    return $payroll;
}

$payroll_stmt = mysqli_prepare(
    $conn,
    "SELECT p.*, e.user_id, e.first_name, e.last_name, e.employment_basis, e.salary, e.daily_rate
     FROM payroll p
     INNER JOIN employees e ON e.id = p.employee_id
     WHERE p.id = ?
     LIMIT 1"
);
if (!$payroll_stmt) {
    http_response_code(500);
    die('Unable to load payroll details.');
}
mysqli_stmt_bind_param($payroll_stmt, 'i', $payroll_id);
mysqli_stmt_execute($payroll_stmt);
$payroll_result = mysqli_stmt_get_result($payroll_stmt);
$payroll_record = $payroll_result ? mysqli_fetch_assoc($payroll_result) : null;
mysqli_stmt_close($payroll_stmt);

if (!$payroll_record) {
    http_response_code(404);
    die('Payroll record not found.');
}

if (function_exists('hrCanManageEmployeeUserIdInScope')
    && !hrCanManageEmployeeUserIdInScope($conn, (int)($payroll_record['user_id'] ?? 0))) {
    http_response_code(403);
    die('Access denied: payroll record is outside your finance scope.');
}

$attendance_stmt = mysqli_prepare(
    $conn,
    'SELECT attendance_date, check_in_time, check_out_time, status
     FROM attendance
     WHERE employee_id = ?
       AND attendance_date BETWEEN ? AND ?
     ORDER BY attendance_date ASC'
);
if (!$attendance_stmt) {
    http_response_code(500);
    die('Unable to load attendance records.');
}
mysqli_stmt_bind_param(
    $attendance_stmt,
    'iss',
    $payroll_record['employee_id'],
    $payroll_record['pay_period_start'],
    $payroll_record['pay_period_end']
);
mysqli_stmt_execute($attendance_stmt);
$attendance_result = mysqli_stmt_get_result($attendance_stmt);

$payroll_breakdown = calculatePayrollBreakdown($attendance_result, $payroll_record);
mysqli_stmt_close($attendance_stmt);
?>

<div class="payroll-inspection-wrapper">
    <!-- 1. Employee & Period Summary Card -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-user-tie"></i>
                Employee &amp; Period Overview
            </div>
            <span class="form-req-pill">Payroll Record #<?php echo (int)$payroll_id; ?></span>
        </div>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-muted small">Employee Name</div>
                    <div class="fw-bold fs-6 text-dark mt-1">
                        <i class="fas fa-user me-1 text-danger"></i>
                        <?php echo htmlspecialchars((string)($payroll_record['first_name'] ?? '') . ' ' . (string)($payroll_record['last_name'] ?? '')); ?>
                    </div>
                    <div class="text-muted small mt-1">
                        Employee ID: #<?php echo htmlspecialchars((string)($payroll_record['employee_id'] ?? 'N/A')); ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Pay Period Window</div>
                    <div class="fw-bold fs-6 text-dark mt-1">
                        <i class="fas fa-calendar-alt me-1 text-primary"></i>
                        <?php echo date('M d, Y', strtotime($payroll_record['pay_period_start'])); ?> &ndash; <?php echo date('M d, Y', strtotime($payroll_record['pay_period_end'])); ?>
                    </div>
                    <div class="text-muted small mt-1">
                        Basis: <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucfirst((string)($payroll_record['employment_basis'] ?? 'Daily'))); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Compensation & Deductions Cards -->
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="form-section-card h-100">
                <div class="form-section-head">
                    <div class="form-section-title">
                        <i class="fas fa-coins"></i>
                        Gross Compensation
                    </div>
                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6;">Earnings</span>
                </div>
                <div class="p-3">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted">Regular Pay (<?php echo $payroll_breakdown['total_hours_worked']; ?> hrs x &#8369;<?php echo number_format((float)$payroll_breakdown['hourly_rate'], 2); ?>)</td>
                            <td class="text-end fw-semibold text-dark">&#8369;<?php echo number_format((float)$payroll_breakdown['regular_pay'], 2); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Overtime Pay (<?php echo $payroll_breakdown['overtime_hours']; ?> hrs x &#8369;<?php echo number_format((float)$payroll_breakdown['hourly_rate'] * 1.25, 2); ?>)</td>
                            <td class="text-end fw-semibold text-dark">&#8369;<?php echo number_format((float)$payroll_breakdown['overtime_pay'], 2); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #eaecf0;">
                            <td class="fw-bold text-dark pt-2">GROSS PAY</td>
                            <td class="text-end fw-bold text-dark pt-2">&#8369;<?php echo number_format((float)$payroll_record['gross_pay'], 2); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-section-card h-100">
                <div class="form-section-head">
                    <div class="form-section-title">
                        <i class="fas fa-file-invoice"></i>
                        Deductions &amp; Net Take-Home
                    </div>
                    <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2;">Deductions</span>
                </div>
                <div class="p-3">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted">Late Deductions (<?php echo $payroll_breakdown['late_hours']; ?> hrs)</td>
                            <td class="text-end fw-semibold text-danger">-&#8369;<?php echo number_format((float)$payroll_breakdown['late_deduction'], 2); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #eaecf0;">
                            <td class="fw-bold text-dark pt-2">TOTAL DEDUCTIONS</td>
                            <td class="text-end fw-bold text-danger pt-2">-&#8369;<?php echo number_format((float)$payroll_record['deductions'], 2); ?></td>
                        </tr>
                        <tr style="border-top: 2px solid #abefc6; background: #ecfdf3;">
                            <td class="fw-bold text-dark p-2">NET PAY</td>
                            <td class="text-end fw-bold p-2" style="font-size: 1.2rem; color: #027a48;">&#8369;<?php echo number_format((float)$payroll_record['net_pay'], 2); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Attendance Records Card -->
    <div class="form-section-card mb-4">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-calendar-check"></i>
                Attendance Records for Period
            </div>
            <span class="form-opt-pill"><?php echo count($payroll_breakdown['attendance_records']); ?> Logs</span>
        </div>
        <div class="p-3">
            <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours Worked</th>
                            <th>Late</th>
                            <th>Overtime</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payroll_breakdown['attendance_records'])): ?>
                            <?php foreach ($payroll_breakdown['attendance_records'] as $record): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo !empty($record['time_in']) ? date('h:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                    <td><?php echo !empty($record['time_out']) ? date('h:i A', strtotime($record['time_out'])) : '-'; ?></td>
                                    <td><?php echo number_format((float)$record['hours_worked'], 2); ?> hrs</td>
                                    <td>
                                        <?php if (!empty($record['is_late'])): ?>
                                            <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2;"><?php echo (int)$record['late_minutes']; ?> min</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['is_overtime'])): ?>
                                            <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6;"><?php echo number_format((float)$record['overtime_hours'], 2); ?> hrs</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">No attendance records found for this period</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 4. Decision Actions -->
    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
        <?php if ($can_manage_finance): ?>
            <form id="payrollActionForm" method="POST" action="finance.php" class="d-flex gap-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="payroll_id" value="<?php echo (int)$payroll_id; ?>">
                <input type="hidden" name="payroll_action" id="payrollActionInput" value="">
                <input type="hidden" name="rejection_reason" id="rejectionReasonInput" value="">
                <input type="hidden" name="payroll_signature" id="payrollSignatureInput" value="">
                
                <button type="button" class="btn btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-danger px-3 py-2 fw-semibold" style="border-radius:10px;" onclick="handlePayrollAction('reject', <?php echo (int)$payroll_id; ?>)">
                    <i class="fas fa-times-circle me-1"></i> Reject Payroll
                </button>
                <button type="button" class="btn btn-success px-4 py-2 fw-semibold" style="background:#027a48; border-color:#027a48; border-radius:10px;" onclick="handlePayrollAction('approve', <?php echo (int)$payroll_id; ?>)">
                    <i class="fas fa-check-circle me-1"></i> Approve Payroll
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning mb-0 me-auto">
                <i class="fas fa-lock me-1"></i> View-only mode. <code>finance.manage</code> permission required for approval.
            </div>
            <button type="button" class="btn btn-modal-cancel" data-bs-dismiss="modal">Close</button>
        <?php endif; ?>
    </div>
</div>
