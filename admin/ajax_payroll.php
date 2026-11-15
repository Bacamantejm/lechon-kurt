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

if (!function_exists('hrFormatWorkDuration')) {
    function hrFormatWorkDuration($total_minutes) {
        $total_minutes = round((float)$total_minutes, 1);
        $whole_mins = (int)round($total_minutes);
        $hours = floor($whole_mins / 60);
        $mins = $whole_mins % 60;
        $dec_hours = number_format($total_minutes / 60.0, 2);

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m ({$dec_hours} hrs)";
        } elseif ($hours > 0) {
            return "{$hours}h ({$dec_hours} hrs)";
        } else {
            return "{$mins} min" . ($mins === 1 ? '' : 's') . " ({$dec_hours} hrs)";
        }
    }
}

function calculatePayrollForEmployee($conn, $employee_id, $start_date, $end_date) {
    $has_attendance_table = hrTableExists($conn, 'attendance');
    $has_schedules_table = hrTableExists($conn, 'schedules');
    $has_deductions_table = hrTableExists($conn, 'employee_deductions');

    // 1. Fetch employee details
    $emp_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, position, salary, daily_rate, employment_basis FROM employees WHERE id = ?");
    if (!$emp_stmt) {
        $emp_stmt = mysqli_prepare($conn, "SELECT id, first_name, last_name, salary, daily_rate FROM employees WHERE id = ?");
    }
    if (!$emp_stmt) {
        return null;
    }
    mysqli_stmt_bind_param($emp_stmt, "i", $employee_id);
    mysqli_stmt_execute($emp_stmt);
    $employee_details = mysqli_fetch_assoc(mysqli_stmt_get_result($emp_stmt));
    mysqli_stmt_close($emp_stmt);

    if (!$employee_details) {
        return null;
    }

    // 2. Resolve Rate Basis (Daily vs. Monthly)
    $salary = floatval($employee_details['salary'] ?? 0);
    $daily_rate_raw = floatval($employee_details['daily_rate'] ?? 0);
    $basis = strtolower(trim((string)($employee_details['employment_basis'] ?? '')));

    $daily_hours = 8.0; // Standard DOLE work hours per day
    $daily_minutes = 480.0; // 8 hours * 60 minutes
    $monthly_work_days = 21.75; // Standard DOLE factor (261 annual working days / 12 months)

    $rate_basis_key = 'daily';
    $eff_daily_rate = 0.0;
    $eff_monthly_salary = 0.0;

    if ($basis === 'monthly') {
        $rate_basis_key = 'monthly';
        $eff_monthly_salary = ($salary > 0) ? $salary : $daily_rate_raw;
        $eff_daily_rate = ($eff_monthly_salary > 0) ? ($eff_monthly_salary / $monthly_work_days) : 0.0;
    } elseif ($basis === 'daily') {
        $rate_basis_key = 'daily';
        $eff_daily_rate = ($daily_rate_raw > 0) ? $daily_rate_raw : $salary;
        $eff_monthly_salary = $eff_daily_rate * $monthly_work_days;
    } else {
        // If employment_basis is not set, intelligently infer:
        if ($daily_rate_raw > 0 && $salary <= 0) {
            $rate_basis_key = 'daily';
            $eff_daily_rate = $daily_rate_raw;
            $eff_monthly_salary = $eff_daily_rate * $monthly_work_days;
        } elseif ($salary > 0 && $daily_rate_raw <= 0) {
            if ($salary <= 2500.0) { // e.g. 750/day standard Philippine daily wage
                $rate_basis_key = 'daily';
                $eff_daily_rate = $salary;
                $eff_monthly_salary = $eff_daily_rate * $monthly_work_days;
            } else {
                $rate_basis_key = 'monthly';
                $eff_monthly_salary = $salary;
                $eff_daily_rate = $eff_monthly_salary / $monthly_work_days;
            }
        } else {
            $eff_daily_rate = max($daily_rate_raw, $salary);
            $rate_basis_key = 'daily';
            $eff_monthly_salary = $eff_daily_rate * $monthly_work_days;
        }
    }

    $hourly_rate = ($eff_daily_rate > 0) ? ($eff_daily_rate / $daily_hours) : 0.0;
    $minute_rate = ($eff_daily_rate > 0) ? ($eff_daily_rate / $daily_minutes) : 0.0;

    // 3. Fetch attendance records for the period
    $attendance_records = [];
    if ($has_attendance_table) {
        $att_stmt = mysqli_prepare($conn, "
            SELECT id, attendance_date, check_in_time, check_out_time, break_start, break_end, status, hr_status, late_minutes, overtime_hours, notes 
            FROM attendance 
            WHERE employee_id = ? 
              AND attendance_date BETWEEN ? AND ?
            ORDER BY attendance_date ASC, check_in_time ASC, id ASC
        ");
        if ($att_stmt) {
            mysqli_stmt_bind_param($att_stmt, "iss", $employee_id, $start_date, $end_date);
            mysqli_stmt_execute($att_stmt);
            $att_result = mysqli_stmt_get_result($att_stmt);
            while ($row = mysqli_fetch_assoc($att_result)) {
                $attendance_records[] = $row;
            }
            mysqli_stmt_close($att_stmt);
        }
    }

    // 4. Fetch schedules for the period
    $schedules = [];
    if ($has_schedules_table) {
        $sched_stmt = mysqli_prepare($conn, "SELECT schedule_date, start_time, end_time FROM schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ?");
        if ($sched_stmt) {
            mysqli_stmt_bind_param($sched_stmt, "iss", $employee_id, $start_date, $end_date);
            mysqli_stmt_execute($sched_stmt);
            $sched_result = mysqli_stmt_get_result($sched_stmt);
            while ($row = mysqli_fetch_assoc($sched_result)) {
                $schedules[$row['schedule_date']] = $row;
            }
            mysqli_stmt_close($sched_stmt);
        }
    }

    // 5. Compute Worked Time from Attendance Timeline (Minutes & Hours)
    $total_worked_seconds = 0;
    $total_regular_seconds = 0;
    $total_overtime_seconds = 0;
    $days_worked = [];
    $today_str = date('Y-m-d');
    $now_time_str = date('H:i:s');

    foreach ($attendance_records as $att) {
        $st = strtolower(trim((string)($att['status'] ?? '')));
        $hr_st = strtolower(trim((string)($att['hr_status'] ?? '')));

        // Skip records explicitly marked absent, rejected, or on leave
        if ($st === 'absent' || $hr_st === 'rejected' || $st === 'on_leave') {
            continue;
        }

        $check_in_str = trim((string)($att['check_in_time'] ?? ''));
        if (empty($check_in_str)) {
            continue;
        }

        $att_date = $att['attendance_date'];
        $check_out_str = trim((string)($att['check_out_time'] ?? ''));

        // If employee has not clocked out yet:
        if (empty($check_out_str)) {
            if ($att_date === $today_str) {
                // Live timeline of today's attendance!
                $check_out_str = $now_time_str;
            } else {
                // Past date with missing checkout: use schedule end or default 8 hours
                $sched_end = $schedules[$att_date]['end_time'] ?? null;
                if (!empty($sched_end)) {
                    $check_out_str = $sched_end;
                } else {
                    $check_out_str = date('H:i:s', strtotime($check_in_str . ' +8 hours'));
                }
            }
        }

        try {
            $in_dt = new DateTime($att_date . ' ' . $check_in_str);
            $out_dt = new DateTime($att_date . ' ' . $check_out_str);
            if ($out_dt < $in_dt) {
                // Overnight shift crossing midnight
                $out_dt->modify('+1 day');
            }

            $elapsed_secs = max(0, $out_dt->getTimestamp() - $in_dt->getTimestamp());

            // Deduct break time if recorded
            $break_secs = 0;
            if (!empty($att['break_start'])) {
                $b_start = new DateTime($att_date . ' ' . $att['break_start']);
                if (!empty($att['break_end'])) {
                    $b_end = new DateTime($att_date . ' ' . $att['break_end']);
                    if ($b_end < $b_start) $b_end->modify('+1 day');
                    $break_secs = max(0, $b_end->getTimestamp() - $b_start->getTimestamp());
                } elseif ($att_date === $today_str) {
                    $now_dt = new DateTime();
                    if ($now_dt > $b_start) {
                        $break_secs = max(0, $now_dt->getTimestamp() - $b_start->getTimestamp());
                    }
                }
            }

            $net_secs = max(0, $elapsed_secs - $break_secs);

            // Cap regular shift to 8 hours (28,800 seconds) per calendar day
            $prev_day_secs = $days_worked[$att_date] ?? 0;
            $new_day_secs = $prev_day_secs + $net_secs;
            $days_worked[$att_date] = $new_day_secs;

            $max_reg_day_secs = 8 * 3600; // 28,800 seconds
            $reg_secs_entry = max(0, min($net_secs, $max_reg_day_secs - $prev_day_secs));
            $ot_secs_entry = max(0, $net_secs - $reg_secs_entry);

            $total_worked_seconds += $net_secs;
            $total_regular_seconds += $reg_secs_entry;
            $total_overtime_seconds += $ot_secs_entry;
        } catch (Exception $e) {
            continue;
        }
    }

    $total_worked_minutes = $total_worked_seconds / 60.0;
    $total_regular_minutes = $total_regular_seconds / 60.0;
    $total_overtime_minutes = $total_overtime_seconds / 60.0;

    $total_worked_hours = $total_worked_seconds / 3600.0;
    $total_regular_hours = $total_regular_seconds / 3600.0;
    $total_overtime_hours = $total_overtime_seconds / 3600.0;

    // Minute-level calculation for exact precision
    $regular_pay = $total_regular_minutes * $minute_rate;
    $overtime_pay = $total_overtime_minutes * ($minute_rate * 1.25);
    $gross_pay = $regular_pay + $overtime_pay;

    // 6. Deductions (Paying by actual worked time avoids double-docking late unworked minutes)
    $late_deduction = 0.0;
    $other_deductions = 0.0;
    $deduction_breakdown = [];
    if ($has_deductions_table) {
        $deductions_stmt = mysqli_prepare($conn,
            "SELECT description, amount_per_payroll FROM employee_deductions 
             WHERE employee_id = ? AND status = 'active' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
        if ($deductions_stmt) {
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
    }

    $total_deductions = $late_deduction + $other_deductions;
    $net_pay = max(0.00, $gross_pay - $total_deductions);

    // 7. Return Full Breakdown Payload
    return [
        'employee_id' => (int)$employee_details['id'],
        'full_name' => trim(($employee_details['first_name'] ?? '') . ' ' . ($employee_details['last_name'] ?? '')),
        'position' => $employee_details['position'] ?? '',
        'rate_basis' => $rate_basis_key,
        'rate_basis_label' => ($rate_basis_key === 'monthly') ? 'Monthly' : 'Daily',
        'rate_basis_display' => ($rate_basis_key === 'monthly') ? ('₱' . number_format($eff_monthly_salary, 2) . '/mo') : ('₱' . number_format($eff_daily_rate, 2) . '/day'),
        'base_rate' => ($rate_basis_key === 'monthly') ? round($eff_monthly_salary, 2) : round($eff_daily_rate, 2),
        'daily_rate' => round($eff_daily_rate, 2),
        'hourly_rate' => round($hourly_rate, 2),
        'minute_rate' => round($minute_rate, 4),
        'base_salary' => round($regular_pay, 2),
        'gross_pay' => round($gross_pay, 2),
        'total_deductions' => round($total_deductions, 2),
        'net_pay' => round($net_pay, 2),
        'bonuses' => 0,
        'overtime_hours' => round($total_overtime_hours, 2),
        'deduction_breakdown' => $deduction_breakdown,
        'details' => [
            'rate_basis' => $rate_basis_key,
            'rate_basis_text' => ($rate_basis_key === 'monthly') ? ('₱' . number_format($eff_monthly_salary, 2) . '/month') : ('₱' . number_format($eff_daily_rate, 2) . '/day'),
            'daily_rate' => round($eff_daily_rate, 2),
            'hourly_rate' => round($hourly_rate, 2),
            'minute_rate' => round($minute_rate, 4),
            'hourly_rate_text' => '₱' . number_format($hourly_rate, 2) . '/hr',
            'minute_rate_text' => '₱' . number_format($minute_rate, 2) . '/min',
            'rate_breakdown_label' => '₱' . number_format($hourly_rate, 2) . '/hr • ₱' . number_format($minute_rate, 2) . '/min',
            'time_worked_label' => hrFormatWorkDuration($total_worked_minutes),
            'total_worked_minutes' => round($total_worked_minutes, 1),
            'total_worked_hours' => round($total_worked_hours, 2),
            'regular_minutes' => round($total_regular_minutes, 1),
            'regular_hours' => round($total_regular_hours, 2),
            'overtime_minutes' => round($total_overtime_minutes, 1),
            'overtime_hours' => round($total_overtime_hours, 2),
            'regular_pay' => round($regular_pay, 2),
            'overtime_pay' => round($overtime_pay, 2),
            'late_deduction' => round($late_deduction, 2),
            'other_deductions' => round($other_deductions, 2),
            'days_present' => count($days_worked),
            'total_attendance_logs' => count($attendance_records)
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
