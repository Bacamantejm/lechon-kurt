<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
header('Content-Type: application/json');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);

$csrf_token = $_POST['csrf_token'] ?? '';
if (!hrIsValidCsrfToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

function calculatePayrollForEmployee($conn, $employee_id, $start_date, $end_date) {
    $has_attendance_table = hrTableExists($conn, 'attendance');
    $has_schedules_table = hrTableExists($conn, 'schedules');
    $has_deductions_table = hrTableExists($conn, 'employee_deductions');

    // 1. Fetch employee details
    $emp_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, salary, daily_rate, employment_basis FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($emp_stmt, "i", $employee_id);
    mysqli_stmt_execute($emp_stmt);
    $employee_details = mysqli_fetch_assoc(mysqli_stmt_get_result($emp_stmt));
    mysqli_stmt_close($emp_stmt);

    if (!$employee_details) return null;

    // 2. Fetch attendance records for the period
    $attendance_records = [];
    if ($has_attendance_table) {
        $att_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
        mysqli_stmt_bind_param($att_stmt, "iss", $employee_id, $start_date, $end_date);
        mysqli_stmt_execute($att_stmt);
        $att_result = mysqli_stmt_get_result($att_stmt);
        while ($row = mysqli_fetch_assoc($att_result)) {
            $attendance_records[$row['attendance_date']] = $row;
        }
        mysqli_stmt_close($att_stmt);
    }

    // 3. Fetch schedules for the period
    $schedules = [];
    if ($has_schedules_table) {
        $sched_stmt = mysqli_prepare($conn, "SELECT * FROM schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ?");
        mysqli_stmt_bind_param($sched_stmt, "iss", $employee_id, $start_date, $end_date);
        mysqli_stmt_execute($sched_stmt);
        $sched_result = mysqli_stmt_get_result($sched_stmt);
        while ($row = mysqli_fetch_assoc($sched_result)) {
            $schedules[$row['schedule_date']] = $row;
        }
        mysqli_stmt_close($sched_stmt);
    }

    // --- 4. Payroll Calculation Logic ---
    $total_regular_hours = 0;
    $total_late_minutes = 0;
    $total_overtime_hours = 0;
    $total_absent_days = 0;
    $daily_hours = 8; // Standard work hours
    $hourly_rate = 0;

    if ($employee_details['employment_basis'] === 'monthly') {
        $base_salary = floatval($employee_details['salary'] ?? 0);
        $hourly_rate = $base_salary > 0 ? ($base_salary / 21.75) / $daily_hours : 0;
    } else { // daily
        $daily_rate = floatval($employee_details['daily_rate'] ?? 0);
        $hourly_rate = $daily_rate > 0 ? $daily_rate / $daily_hours : 0;
    }

    $period = new DatePeriod(
        new DateTime($start_date),
        new DateInterval('P1D'),
        (new DateTime($end_date))->modify('+1 day')
    );

    foreach ($period as $date) {
        $current_date_str = $date->format('Y-m-d');
        $day_of_week = $date->format('N'); // 1 (for Monday) through 7 (for Sunday)

        // Skip weekends if no specific schedule
        if ($day_of_week >= 6 && !isset($schedules[$current_date_str])) {
            continue;
        }

        $schedule = $schedules[$current_date_str] ?? ['start_time' => '10:00:00', 'end_time' => '19:00:00'];
        $attendance = $attendance_records[$current_date_str] ?? null;

        if ($attendance && $attendance['status'] === 'present' && $attendance['check_in_time'] && $attendance['check_out_time']) {
            try {
                $check_in = new DateTime($current_date_str . ' ' . $attendance['check_in_time']);
                $check_out = new DateTime($current_date_str . ' ' . $attendance['check_out_time']);
                if ($check_out < $check_in) $check_out->modify('+1 day');

                $sched_start = new DateTime($current_date_str . ' ' . $schedule['start_time']);
                $sched_end = new DateTime($current_date_str . ' ' . $schedule['end_time']);

                // Late calculation
                if ($check_in > $sched_start) {
                    $late_interval = $check_in->diff($sched_start);
                    $total_late_minutes += $late_interval->h * 60 + $late_interval->i;
                }

                // Hours worked calculation
                $interval = $check_in->diff($check_out);
                $hours_worked = $interval->h + ($interval->i / 60);

                // Overtime calculation
                if ($check_out > $sched_end) {
                    $ot_interval = $check_out->diff($sched_end);
                    $overtime = $ot_interval->h + ($ot_interval->i / 60);
                    $total_overtime_hours += $overtime;
                    $total_regular_hours += ($hours_worked - $overtime);
                } else {
                    $total_regular_hours += $hours_worked;
                }
            } catch (Exception $e) {
                // Log error or skip record
                continue;
            }
        } else {
            // Not present, check if on leave
            $is_on_leave = $attendance && $attendance['status'] === 'on_leave';
            if (!$is_on_leave) {
                $total_absent_days++;
            }
        }
    }

    $total_late_hours = $total_late_minutes / 60;

    // --- 5. Pay Calculation ---
    $regular_pay = $total_regular_hours * $hourly_rate;
    $overtime_pay = $total_overtime_hours * $hourly_rate * 1.25; // OT premium
    $gross_pay = $regular_pay + $overtime_pay;

    // --- 6. Deductions ---
    $late_deduction = $total_late_hours * $hourly_rate;
    
    $other_deductions = 0;
    $deduction_breakdown = [];
    if ($has_deductions_table) {
        $deductions_stmt = mysqli_prepare($conn,
            "SELECT description, amount_per_payroll FROM employee_deductions 
             WHERE employee_id = ? AND status = 'active' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
        mysqli_stmt_bind_param($deductions_stmt, "iss", $employee_id, $end_date, $start_date);
        mysqli_stmt_execute($deductions_stmt);
        $deductions_result = mysqli_stmt_get_result($deductions_stmt);
        if ($deductions_result) {
            while ($deduction = mysqli_fetch_assoc($deductions_result)) {
                $amount = floatval($deduction['amount_per_payroll']);
                $other_deductions += $amount;
                $deduction_breakdown[] = ['description' => $deduction['description'], 'amount' => $amount];
            }
            mysqli_free_result($deductions_result);
        }
        mysqli_stmt_close($deductions_stmt);
    }

    $total_deductions = $late_deduction + $other_deductions;
    $net_pay = $gross_pay - $total_deductions;

    // --- 7. Return Data ---
    return [
        'employee_id' => $employee_details['id'],
        'full_name' => $employee_details['first_name'] . ' ' . $employee_details['last_name'],
        'base_salary' => round($regular_pay, 2),
        'gross_pay' => round($gross_pay, 2),
        'total_deductions' => round($total_deductions, 2),
        'net_pay' => round($net_pay, 2),
        'bonuses' => 0,
        'overtime_hours' => round($total_overtime_hours, 2),
        'deduction_breakdown' => $deduction_breakdown,
        'details' => [
            'regular_hours' => round($total_regular_hours, 2),
            'late_hours' => round($total_late_hours, 2),
            'regular_pay' => round($regular_pay, 2),
            'overtime_pay' => round($overtime_pay, 2),
            'late_deduction' => round($late_deduction, 2),
            'other_deductions' => round($other_deductions, 2),
            'absent_days' => $total_absent_days
        ]
    ];
}

$employee_ids = $_POST['employee_ids'] ?? [];
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';

if (empty($employee_ids) || empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}
if (!hrIsValidDate($start_date) || !hrIsValidDate($end_date) || $start_date > $end_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll date range.']);
    exit;
}

$results = [];
foreach ($employee_ids as $eid) {
    $employee_id = intval($eid);
    if ($employee_id <= 0) {
        continue;
    }
    if ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
        continue;
    }
    $payroll_result = calculatePayrollForEmployee($conn, $employee_id, $start_date, $end_date);
    if ($payroll_result) {
        $results[] = $payroll_result;
    }
}

if ($is_partner_scoped_hr && empty($results)) {
    echo json_encode(['success' => false, 'message' => 'No employees in your partner HR scope were selected.']);
    exit;
}

echo json_encode(['success' => true, 'data' => $results]);

$conn->close();
?>
