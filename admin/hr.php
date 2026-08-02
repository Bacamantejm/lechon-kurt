<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$hr_employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$hr_scope_user_ids_csv = hrScopeUserIdCsv($conn);
$hr_scope_employee_subquery = "SELECT e.id FROM employees e WHERE {$hr_employee_scope_sql}";

function fetchCount($conn, $sql) {
    try {
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return 0;
        }
        $row = mysqli_fetch_assoc($result);
        return (int)($row['count'] ?? 0);
    } catch (mysqli_sql_exception $e) {
        // Optional dashboard widgets may reference tables not yet created.
        // Return 0 so the HR dashboard still loads.
        return 0;
    }
}

function tableExists($conn, $table_name) {
    try {
        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
        return $result && mysqli_num_rows($result) > 0;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function safeQuery($conn, $sql) {
    try {
        return mysqli_query($conn, $sql);
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function safePercent($numerator, $denominator) {
    if ($denominator <= 0) {
        return 0;
    }
    return round(($numerator / $denominator) * 100, 1);
}

function decisionPriorityWeight($priority) {
    return match ($priority) {
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        default => 0
    };
}

$emp_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM employees e WHERE status = 'active'" . ($is_partner_scoped_hr ? " AND {$hr_employee_scope_sql}" : ""));
$leave_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM leave_requests WHERE status = 'pending'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$payroll_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM payroll WHERE status = 'pending'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$attendance_review_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM attendance WHERE attendance_date = CURDATE() AND hr_status = 'pending'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$upcoming_leave_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM leave_requests WHERE status = 'approved' AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));

$today_snapshot = [
    'present_on_time' => 0,
    'late' => 0,
    'absent' => 0,
    'on_leave' => 0,
    'logged_total' => 0
];

$today_snapshot_result = safeQuery($conn, "
    SELECT
        SUM(CASE WHEN status = 'present' AND IFNULL(late_minutes, 0) = 0 THEN 1 ELSE 0 END) AS present_on_time,
        SUM(CASE WHEN status = 'late' OR IFNULL(late_minutes, 0) > 0 THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) AS on_leave,
        COUNT(*) AS logged_total
    FROM attendance
    WHERE attendance_date = CURDATE()" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : "") . "
");

if ($today_snapshot_result && mysqli_num_rows($today_snapshot_result) > 0) {
    $row = mysqli_fetch_assoc($today_snapshot_result);
    $today_snapshot = [
        'present_on_time' => (int)($row['present_on_time'] ?? 0),
        'late' => (int)($row['late'] ?? 0),
        'absent' => (int)($row['absent'] ?? 0),
        'on_leave' => (int)($row['on_leave'] ?? 0),
        'logged_total' => (int)($row['logged_total'] ?? 0)
    ];
}

$present_total = $today_snapshot['present_on_time'] + $today_snapshot['late'];
$presence_rate = safePercent($present_total, $emp_count);
$coverage_rate = safePercent($today_snapshot['logged_total'], $emp_count);
$late_rate = safePercent((int)$today_snapshot['late'], $emp_count);
$absent_rate = safePercent((int)$today_snapshot['absent'], $emp_count);
$leave_pressure_rate = safePercent($upcoming_leave_count, $emp_count);

$has_departments = tableExists($conn, 'departments');
$has_schedules = tableExists($conn, 'schedules');
$has_leave_balance = tableExists($conn, 'leave_balance');
$has_employee_deductions = tableExists($conn, 'employee_deductions');
$has_payslips = tableExists($conn, 'payslips');
$has_performance_reviews = tableExists($conn, 'performance_reviews');
$has_job_positions = tableExists($conn, 'job_positions');
$has_job_openings = tableExists($conn, 'job_openings'); // legacy table name fallback
$has_recruitment_positions = $has_job_positions || $has_job_openings;
$has_candidates = tableExists($conn, 'candidates');
$has_employee_turnover = tableExists($conn, 'employee_turnover');

$department_count = fetchCount($conn, $is_partner_scoped_hr
    ? "SELECT COUNT(DISTINCT e.department_id) AS count FROM employees e WHERE e.department_id IS NOT NULL AND {$hr_employee_scope_sql}"
    : "SELECT COUNT(*) AS count FROM departments"
);
$schedule_today_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM schedules WHERE schedule_date = CURDATE()" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$leave_balance_profiles_count = fetchCount($conn, "SELECT COUNT(DISTINCT employee_id) AS count FROM leave_balance WHERE year = YEAR(CURDATE())" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$active_deductions_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM employee_deductions WHERE status = 'active'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$payslip_draft_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM payslips WHERE status IN ('draft', 'generated')" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$performance_submitted_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM performance_reviews WHERE status = 'submitted'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$open_positions_count = 0;
if ($has_job_positions) {
    $open_positions_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM job_positions WHERE status = 'open'" . ($is_partner_scoped_hr && $hr_scope_user_ids_csv !== '' ? " AND created_by IN ({$hr_scope_user_ids_csv})" : ($is_partner_scoped_hr ? " AND 1=0" : "")));
} elseif ($has_job_openings) {
    $open_positions_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM job_openings WHERE status = 'open'");
}
$new_candidates_count = fetchCount($conn, $is_partner_scoped_hr
    ? "SELECT COUNT(*) AS count
       FROM candidates c
       INNER JOIN job_positions jp ON jp.id = c.position_id
       WHERE c.status = 'new'" . ($hr_scope_user_ids_csv !== '' ? " AND jp.created_by IN ({$hr_scope_user_ids_csv})" : " AND 1=0")
    : "SELECT COUNT(*) AS count FROM candidates WHERE status = 'new'"
);
$turnover_pending_count = fetchCount($conn, "SELECT COUNT(*) AS count FROM employee_turnover WHERE exit_clearance_status = 'pending'" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));
$attendance_month_records = fetchCount($conn, "SELECT COUNT(*) AS count FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" . ($is_partner_scoped_hr ? " AND employee_id IN ({$hr_scope_employee_subquery})" : ""));

$dashboard_kpis = [
    [
        'title' => 'Active Employees',
        'value' => $emp_count,
        'subtitle' => 'Current active workforce',
        'url' => 'employees.php?status=active',
        'icon' => 'fas fa-users',
        'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    ],
    [
        'title' => 'Present Today',
        'value' => $present_total,
        'subtitle' => 'On-time + late attendance',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-check',
        'gradient' => 'linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%)'
    ],
    [
        'title' => 'Late Today',
        'value' => (int)$today_snapshot['late'],
        'subtitle' => $late_rate . '% of active employees',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-clock',
        'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #f97316 100%)'
    ],
    [
        'title' => 'Absent Today',
        'value' => (int)$today_snapshot['absent'],
        'subtitle' => $absent_rate . '% absentee rate',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-times',
        'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'
    ],
    [
        'title' => 'Pending Leave Requests',
        'value' => $leave_count,
        'subtitle' => 'Awaiting HR action',
        'url' => 'leave_requests.php',
        'icon' => 'fas fa-calendar-times',
        'gradient' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)'
    ],
    [
        'title' => 'Pending Payroll',
        'value' => $payroll_count,
        'subtitle' => 'Pending finance handoff',
        'url' => 'payroll.php',
        'icon' => 'fas fa-money-bill-wave',
        'gradient' => 'linear-gradient(135deg, #43e97b 0%, #10b981 100%)'
    ]
];

$decision_actions = [];

if ($coverage_rate < 90) {
    $decision_actions[] = [
        'priority' => ($coverage_rate < 75 ? 'high' : 'medium'),
        'title' => 'Improve Attendance Capture Coverage',
        'insight' => 'Only ' . $coverage_rate . '% of active employees have attendance records today.',
        'action' => 'Run attendance reconciliation and remind supervisors to validate missing logs.',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'url_label' => 'Open Attendance Desk'
    ];
}

if ($attendance_review_count > 0) {
    $decision_actions[] = [
        'priority' => ($attendance_review_count >= 8 ? 'high' : 'medium'),
        'title' => 'Resolve Pending Attendance Reviews',
        'insight' => $attendance_review_count . ' attendance records are waiting for HR decision.',
        'action' => 'Clear pending reviews before end of day to protect payroll accuracy.',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'url_label' => 'Review Pending Items'
    ];
}

if ($leave_count > 0 || $leave_pressure_rate >= 12) {
    $decision_actions[] = [
        'priority' => ($leave_pressure_rate >= 18 ? 'high' : 'medium'),
        'title' => 'Prepare Coverage for Upcoming Leaves',
        'insight' => $upcoming_leave_count . ' approved leaves are scheduled in the next 7 days.',
        'action' => 'Confirm replacements and adjust schedule allocations early.',
        'url' => 'schedules.php',
        'url_label' => 'Plan Schedules'
    ];
}

if ($absent_rate >= 8 || $late_rate >= 20) {
    $decision_actions[] = [
        'priority' => 'high',
        'title' => 'Address Attendance Reliability Risk',
        'insight' => 'Absentee rate is ' . $absent_rate . '% and late rate is ' . $late_rate . '%.',
        'action' => 'Review department trend, escalate repeat offenders, and tune shift assignment.',
        'url' => 'hr_reports.php?type=attendance',
        'url_label' => 'View Attendance Report'
    ];
}

if ($payroll_count > 0) {
    $decision_actions[] = [
        'priority' => ($payroll_count >= 10 ? 'high' : 'medium'),
        'title' => 'Close Payroll Queue',
        'insight' => $payroll_count . ' payroll records are pending.',
        'action' => 'Finalize payroll review cut-off to avoid pay-delay risk.',
        'url' => 'payroll.php',
        'url_label' => 'Process Payroll'
    ];
}

if ($has_recruitment_positions && $has_candidates && $open_positions_count > 0 && $new_candidates_count < $open_positions_count) {
    $decision_actions[] = [
        'priority' => 'medium',
        'title' => 'Candidate Pipeline is Thinner Than Open Roles',
        'insight' => $open_positions_count . ' open positions vs ' . $new_candidates_count . ' new candidates.',
        'action' => 'Boost sourcing and align interviews to high-impact roles.',
        'url' => 'recruitment.php',
        'url_label' => 'Open Recruitment'
    ];
}

if (empty($decision_actions)) {
    $decision_actions[] = [
        'priority' => 'low',
        'title' => 'HR Operations Look Stable Today',
        'insight' => 'No urgent blockers were detected from current HR signals.',
        'action' => 'Use this window to review process quality and update monthly targets.',
        'url' => 'hr_reports.php',
        'url_label' => 'Review HR Reports'
    ];
}

usort($decision_actions, function ($a, $b) {
    return decisionPriorityWeight($b['priority']) <=> decisionPriorityWeight($a['priority']);
});

$decision_score = min(100, (int)round(
    (($attendance_review_count > 0 ? min(25, $attendance_review_count * 2) : 0)) +
    (($leave_count > 0 ? min(20, $leave_count * 2) : 0)) +
    (($payroll_count > 0 ? min(20, $payroll_count * 2) : 0)) +
    (($absent_rate > 0 ? min(20, $absent_rate * 1.2) : 0)) +
    (($late_rate > 0 ? min(10, $late_rate * 0.5) : 0)) +
    (($coverage_rate < 100 ? min(15, (100 - $coverage_rate) * 0.4) : 0))
));

$decision_health_class = 'low-risk';
$decision_health_label = 'Stable';
$decision_health_text = 'No immediate HR risk spike. Keep current cadence and maintain approval SLAs.';

if ($decision_score >= 70) {
    $decision_health_class = 'high-risk';
    $decision_health_label = 'Critical Attention';
    $decision_health_text = 'Multiple HR risk signals detected. Prioritize high-severity actions today.';
} elseif ($decision_score >= 40) {
    $decision_health_class = 'medium-risk';
    $decision_health_label = 'Watch Closely';
    $decision_health_text = 'Moderate operational risk. Resolve medium/high items to avoid escalation.';
}

$decision_progress_class = $decision_health_class === 'high-risk'
    ? 'bg-danger'
    : ($decision_health_class === 'medium-risk' ? 'bg-warning' : 'bg-success');

$hr_modules = [
    [
        'title' => 'Employees',
        'description' => 'Manage employee records, profile updates, and status.',
        'url' => 'employees.php',
        'icon' => 'fas fa-id-badge',
        'metric' => $emp_count,
        'metric_label' => 'Active Employees',
        'chip' => 'Core',
        'gradient' => 'linear-gradient(135deg, #2563eb, #1d4ed8)'
    ],
    [
        'title' => 'Departments',
        'description' => 'Create and organize departments for clearer ownership.',
        'url' => 'departments.php',
        'icon' => 'fas fa-sitemap',
        'metric' => $department_count,
        'metric_label' => 'Departments',
        'chip' => 'Structure',
        'gradient' => 'linear-gradient(135deg, #0f766e, #0d9488)'
    ],
    [
        'title' => 'Attendance',
        'description' => 'Review daily logs, approvals, and attendance exceptions.',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-calendar-check',
        'metric' => $attendance_review_count,
        'metric_label' => 'Pending HR Review',
        'chip' => 'Daily Ops',
        'gradient' => 'linear-gradient(135deg, #ea580c, #f97316)'
    ],
    [
        'title' => 'Schedules',
        'description' => 'Manage shift schedules and date-specific assignments.',
        'url' => 'schedules.php',
        'icon' => 'fas fa-clock',
        'metric' => $schedule_today_count,
        'metric_label' => "Today's Schedules",
        'chip' => 'Planning',
        'gradient' => 'linear-gradient(135deg, #9333ea, #a855f7)'
    ],
    [
        'title' => 'Leave Requests',
        'description' => 'Approve, reject, and track employee leave requests.',
        'url' => 'leave_requests.php',
        'icon' => 'fas fa-calendar-minus',
        'metric' => $leave_count,
        'metric_label' => 'Pending Requests',
        'chip' => 'Approvals',
        'gradient' => 'linear-gradient(135deg, #0891b2, #06b6d4)'
    ],
    [
        'title' => 'Leave Balance',
        'description' => 'Maintain yearly leave allocations and balances.',
        'url' => 'leave_balance.php',
        'icon' => 'fas fa-balance-scale',
        'metric' => $leave_balance_profiles_count,
        'metric_label' => 'Profiles Updated',
        'chip' => 'Compliance',
        'gradient' => 'linear-gradient(135deg, #6366f1, #818cf8)'
    ],
    [
        'title' => 'Payroll',
        'description' => 'Generate payroll drafts and submit for finance approval.',
        'url' => 'payroll.php',
        'icon' => 'fas fa-wallet',
        'metric' => $payroll_count,
        'metric_label' => 'Pending Payroll',
        'chip' => 'Compensation',
        'gradient' => 'linear-gradient(135deg, #16a34a, #22c55e)'
    ],
    [
        'title' => 'Deductions',
        'description' => 'Track recurring and employee-specific deduction rules.',
        'url' => 'deductions.php',
        'icon' => 'fas fa-file-invoice-dollar',
        'metric' => $active_deductions_count,
        'metric_label' => 'Active Deductions',
        'chip' => 'Compensation',
        'gradient' => 'linear-gradient(135deg, #be123c, #e11d48)'
    ],
    [
        'title' => 'Payslip Generation',
        'description' => 'Generate, review, and release payslips to employees.',
        'url' => 'payslip_generation.php',
        'icon' => 'fas fa-file-invoice',
        'metric' => $payslip_draft_count,
        'metric_label' => 'Draft/Generated',
        'chip' => 'Payroll',
        'gradient' => 'linear-gradient(135deg, #7c3aed, #8b5cf6)'
    ],
    [
        'title' => 'Performance',
        'description' => 'Monitor performance cycles and submitted evaluations.',
        'url' => 'performance.php',
        'icon' => 'fas fa-chart-line',
        'metric' => $performance_submitted_count,
        'metric_label' => 'Submitted Reviews',
        'chip' => 'People Dev',
        'gradient' => 'linear-gradient(135deg, #0d9488, #14b8a6)'
    ],
    [
        'title' => 'Recruitment',
        'description' => 'Track open positions and hiring progress.',
        'url' => 'recruitment.php',
        'icon' => 'fas fa-user-plus',
        'metric' => $open_positions_count,
        'metric_label' => 'Open Positions',
        'chip' => 'Hiring',
        'gradient' => 'linear-gradient(135deg, #1d4ed8, #3b82f6)'
    ],
    [
        'title' => 'Candidates',
        'description' => 'Review candidate pipeline and interview readiness.',
        'url' => 'candidates.php',
        'icon' => 'fas fa-user-friends',
        'metric' => $new_candidates_count,
        'metric_label' => 'New Candidates',
        'chip' => 'Hiring',
        'gradient' => 'linear-gradient(135deg, #4f46e5, #6366f1)'
    ],
    [
        'title' => 'Turnover',
        'description' => 'Handle resignation, clearance, and transition records.',
        'url' => 'turnover.php',
        'icon' => 'fas fa-people-arrows',
        'metric' => $turnover_pending_count,
        'metric_label' => 'Pending Clearance',
        'chip' => 'Lifecycle',
        'gradient' => 'linear-gradient(135deg, #b45309, #d97706)'
    ],
    [
        'title' => 'HR Reports',
        'description' => 'Generate attendance, payroll, leave, and trend reports.',
        'url' => 'hr_reports.php',
        'icon' => 'fas fa-chart-bar',
        'metric' => $attendance_month_records,
        'metric_label' => 'Attendance Rows This Month',
        'chip' => 'Analytics',
        'gradient' => 'linear-gradient(135deg, #334155, #475569)'
    ],
    [
        'title' => 'HR DB Checker',
        'description' => 'Validate required HR tables and columns before opening modules.',
        'url' => 'hr_migration_checker.php',
        'icon' => 'fas fa-database',
        'metric' => 1,
        'metric_label' => 'Readiness Tool',
        'chip' => 'Setup',
        'gradient' => 'linear-gradient(135deg, #1f2937, #374151)'
    ]
];

if ($is_partner_scoped_hr) {
    $partner_hidden_modules = ['hr_migration_checker.php'];
    $hr_modules = array_values(array_filter($hr_modules, function ($module) use ($partner_hidden_modules) {
        return !in_array((string)($module['url'] ?? ''), $partner_hidden_modules, true);
    }));
}

$module_availability_map = [
    'Departments' => $has_departments,
    'Schedules' => $has_schedules,
    'Leave Balance' => $has_leave_balance,
    'Deductions' => $has_employee_deductions,
    'Payslip Generation' => $has_payslips,
    'Performance' => $has_performance_reviews,
    'Recruitment' => $has_recruitment_positions,
    'Candidates' => $has_candidates,
    'Turnover' => $has_employee_turnover
];

foreach ($hr_modules as &$module) {
    $module['available'] = $module_availability_map[$module['title']] ?? true;
    if (!$module['available']) {
        $module['chip'] = 'Setup';
        $module['metric'] = 0;
        $module['metric_label'] = 'Table Missing';
        $module['description'] = 'This module is available after its database table is created.';
    }
}
unset($module);

$recent_reviews = safeQuery($conn, "
    SELECT pr.*, e.first_name, e.last_name
    FROM performance_reviews pr
    JOIN employees e ON pr.employee_id = e.id
    WHERE " . ($is_partner_scoped_hr ? $hr_employee_scope_sql : "1=1") . "
    ORDER BY pr.created_at DESC
    LIMIT 5
");

$upcoming_leaves = safeQuery($conn, "
    SELECT lr.leave_type, lr.start_date, lr.end_date, e.first_name, e.last_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE lr.status = 'approved'
      AND lr.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
      AND " . ($is_partner_scoped_hr ? $hr_employee_scope_sql : "1=1") . "
    ORDER BY lr.start_date ASC
    LIMIT 5
");

$department_snapshot = safeQuery($conn, "
    SELECT COALESCE(d.department_name, 'Unassigned') AS department_name, COUNT(*) AS total
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.status = 'active'
      AND " . ($is_partner_scoped_hr ? $hr_employee_scope_sql : "1=1") . "
    GROUP BY e.department_id, d.department_name
    ORDER BY total DESC
    LIMIT 6
");

$attendance_trend_result = safeQuery($conn, "
    SELECT
        attendance_date,
        SUM(CASE WHEN status = 'present' AND IFNULL(late_minutes, 0) = 0 THEN 1 ELSE 0 END) AS present_on_time,
        SUM(CASE WHEN status = 'late' OR IFNULL(late_minutes, 0) > 0 THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) AS on_leave
    FROM attendance
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      " . ($is_partner_scoped_hr ? "AND employee_id IN ({$hr_scope_employee_subquery})" : "") . "
    GROUP BY attendance_date
");

$chart_dates = [];
$chart_present = [];
$chart_late = [];
$chart_absent = [];
$chart_on_leave = [];

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_dates[$d] = date('M d', strtotime($d));
    $chart_present[$d] = 0;
    $chart_late[$d] = 0;
    $chart_absent[$d] = 0;
    $chart_on_leave[$d] = 0;
}

if ($attendance_trend_result) {
    while ($row = mysqli_fetch_assoc($attendance_trend_result)) {
        $d = $row['attendance_date'];
        if (isset($chart_dates[$d])) {
            $chart_present[$d] = (int)$row['present_on_time'];
            $chart_late[$d] = (int)$row['late'];
            $chart_absent[$d] = (int)$row['absent'];
            $chart_on_leave[$d] = (int)$row['on_leave'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --bg-color-dark: #1a1a1a; --text-color-dark: #e0e0e0; --card-bg-dark: #2d2d2d; --border-color-dark: #404040; }
        body.dark-mode { background-color: var(--bg-color-dark) !important; color: var(--text-color-dark) !important; }
        body.dark-mode .admin-content, body.dark-mode .admin-container { background-color: var(--bg-color-dark) !important; }
        body.dark-mode .admin-topbar, body.dark-mode .stat-card, body.dark-mode .card, body.dark-mode .card-header, body.dark-mode .card-body, body.dark-mode .module-card, body.dark-mode .recent-item, body.dark-mode .focus-item, body.dark-mode .snapshot-banner, body.dark-mode .module-hub-card, body.dark-mode .module-chip { background-color: var(--card-bg-dark) !important; color: var(--text-color-dark) !important; border-color: var(--border-color-dark) !important; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode h4, body.dark-mode h5, body.dark-mode h6, body.dark-mode strong { color: var(--text-color-dark) !important; }
        body.dark-mode .text-muted, body.dark-mode .small, body.dark-mode p, body.dark-mode small { color: #b0b0b0 !important; }
        .theme-toggler { background: none; border: none; color: #666; font-size: 1.2rem; cursor: pointer; margin: 0; padding: 5px; transition: color 0.3s; }
        body.dark-mode .theme-toggler { color: #ffc107; }
        .stat-card-link { text-decoration: none; color: inherit; }
        .snapshot-banner { background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); border: 1px solid #fed7aa; border-radius: 12px; padding: 16px 18px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .snapshot-banner h2 { font-size: 1.1rem; margin: 0; color: #9a3412; }
        .snapshot-banner p { margin: 0; font-size: 0.9rem; color: #9a3412; }
        .stats-dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stats-dashboard-grid .stat-card { min-height: 128px; }
        .stats-dashboard-grid .stat-content p { font-size: 0.82rem; line-height: 1.3; }
        .section-header.compact { margin-bottom: 14px; align-items: flex-end; }
        .module-header-note { color: #64748b; font-size: 0.87rem; font-weight: 500; }
        .module-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .module-search-input { width: 250px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 12px; background: #fff; color: #0f172a; font-size: 0.88rem; }
        .module-search-input:focus { outline: none; border-color: #94a3b8; box-shadow: 0 0 0 4px rgba(148, 163, 184, 0.15); }
        .module-hub-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .module-hub-card { position: relative; display: flex; flex-direction: column; gap: 10px; border: 1px solid #e2e8f0; border-radius: 14px; background: #ffffff; padding: 16px; text-decoration: none; color: inherit; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06); transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease; min-height: 208px; overflow: hidden; }
        .module-hub-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px; background: linear-gradient(90deg, #e2e8f0, #cbd5e1); transition: opacity 0.2s ease; opacity: 0.8; }
        .module-hub-card:hover { transform: translateY(-5px); box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12); border-color: #cbd5e1; }
        .module-hub-card:hover::before { opacity: 1; }
        .module-hub-card.unavailable { border-style: dashed; opacity: 0.75; }
        .module-hub-card.unavailable .module-link-text { color: #b45309; }
        .module-hub-card.unavailable:hover { transform: translateY(-2px); }
        .module-hub-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .module-icon-wrap { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; color: #ffffff; font-size: 18px; flex-shrink: 0; box-shadow: 0 8px 14px rgba(0, 0, 0, 0.16); }
        .module-chip { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .module-hub-card h4 { margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; }
        .module-hub-card p { margin: 0; color: #64748b; font-size: 0.84rem; line-height: 1.45; }
        .module-meta { margin-top: auto; display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
        .module-meta strong { font-size: 1.2rem; font-weight: 800; color: #0f172a; }
        .module-meta span { font-size: 0.72rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; text-align: right; }
        .module-link-text { font-size: 0.8rem; color: #c62828; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        body.dark-mode .module-hub-card h4, body.dark-mode .module-meta strong, body.dark-mode .module-link-text { color: var(--text-color-dark) !important; }
        body.dark-mode .module-hub-card p, body.dark-mode .module-meta span, body.dark-mode .module-header-note, body.dark-mode .module-chip { color: #b0b0b0 !important; }
        body.dark-mode .module-search-input { background: #1f2937; color: #e2e8f0; border-color: #475569; }
        .decision-board {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: linear-gradient(140deg, #fff7ed 0%, #ffffff 40%, #f8fafc 100%);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .decision-board .card-header {
            border-bottom: 1px solid #e2e8f0;
            background: transparent !important;
            padding: 16px 18px;
        }
        .decision-health-chip {
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            border: 1px solid transparent;
        }
        .decision-health-chip.low-risk { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .decision-health-chip.medium-risk { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .decision-health-chip.high-risk { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .decision-summary {
            display: grid;
            grid-template-columns: minmax(120px, 140px) 1fr;
            gap: 14px;
            align-items: center;
            padding: 4px 0 2px;
        }
        .decision-score {
            width: 116px;
            height: 116px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 8px solid #e2e8f0;
            background: #fff;
            box-shadow: inset 0 0 0 1px #f1f5f9;
            margin: 0 auto;
        }
        .decision-score span { font-size: 1.9rem; line-height: 1; font-weight: 800; color: #0f172a; }
        .decision-score small { font-size: 0.72rem; color: #64748b; font-weight: 700; }
        .decision-score.low-risk { border-color: #86efac; }
        .decision-score.medium-risk { border-color: #fcd34d; }
        .decision-score.high-risk { border-color: #fca5a5; }
        .decision-progress { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
        .decision-progress .progress-bar { border-radius: 999px; }
        .decision-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 320px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .decision-item {
            border: 1px solid #e2e8f0;
            border-left: 4px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            padding: 11px 12px;
        }
        .decision-item.high { border-left-color: #dc2626; }
        .decision-item.medium { border-left-color: #d97706; }
        .decision-item.low { border-left-color: #0d9488; }
        .decision-item .top-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        .priority-badge {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 4px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
        }
        .priority-badge.high { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .priority-badge.medium { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .priority-badge.low { background: #ecfeff; color: #0f766e; border-color: #99f6e4; }
        .decision-item h6 { margin: 0 0 5px 0; font-size: 0.92rem; font-weight: 700; color: #0f172a; }
        .decision-item p { margin: 0 0 4px 0; font-size: 0.82rem; color: #475569; line-height: 1.35; }
        .decision-item small { color: #64748b; font-size: 0.76rem; }
        .decision-item a { color: #c62828; font-size: 0.76rem; font-weight: 700; text-decoration: none; }
        .decision-item a:hover { text-decoration: underline; }
        body.dark-mode .decision-board {
            background: linear-gradient(140deg, #2d2d2d 0%, #2a2f39 100%);
            border-color: var(--border-color-dark);
        }
        body.dark-mode .decision-item,
        body.dark-mode .decision-score {
            background: #2f3642;
            border-color: var(--border-color-dark);
            box-shadow: none;
        }
        body.dark-mode .decision-item h6,
        body.dark-mode .decision-score span { color: #e2e8f0; }
        body.dark-mode .decision-item p,
        body.dark-mode .decision-item small { color: #b0b0b0; }
        @media (max-width: 768px) {
            .decision-summary { grid-template-columns: 1fr; text-align: center; }
            .module-search-input { width: 100%; }
            .module-tools { width: 100%; }
            .module-header-note { width: 100%; }
        }
        .focus-list { display: flex; flex-direction: column; gap: 10px; }
        .focus-item { border: 1px solid #e5e7eb; border-radius: 10px; padding: 11px 12px; background: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .focus-item h6 { margin: 0; font-size: 0.92rem; }
        .focus-item p { margin: 0; font-size: 0.8rem; color: #6b7280; }
        .focus-value { font-size: 1.15rem; font-weight: 700; white-space: nowrap; }
        .rating-stars { color: #f59e0b; display: inline-flex; gap: 2px; }
    </style>
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>HR Management</h1>
                    <div class="topbar-right" style="margin-left: auto; gap: 10px;">
                        <div class="date-display" id="currentDate"></div>
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <div class="snapshot-banner">
                    <div>
                        <h2>Daily Workforce Snapshot</h2>
                        <p>Presence: <?php echo $presence_rate; ?>% | Coverage: <?php echo $coverage_rate; ?>%</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark border">Pending Attendance Review: <?php echo $attendance_review_count; ?></span>
                        <span class="badge bg-light text-dark border">Upcoming Leaves (7d): <?php echo $upcoming_leave_count; ?></span>
                    </div>
                </div>

                <div class="stats-dashboard-grid">
                    <?php foreach ($dashboard_kpis as $kpi): ?>
                        <a href="<?php echo htmlspecialchars($kpi['url']); ?>" class="stat-card-link">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: <?php echo htmlspecialchars($kpi['gradient']); ?>;">
                                    <i class="<?php echo htmlspecialchars($kpi['icon']); ?>"></i>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo number_format((int)$kpi['value']); ?></h3>
                                    <p><?php echo htmlspecialchars($kpi['title']); ?></p>
                                    <small class="text-muted"><?php echo htmlspecialchars($kpi['subtitle']); ?></small>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="section-header compact">
                    <h2>HR Module Center</h2>
                    <div class="module-tools">
                        <span class="module-header-note">All HR modules are accessible here for faster navigation.</span>
                        <input type="text" id="moduleSearchInput" class="module-search-input" placeholder="Search module...">
                    </div>
                </div>
                <div class="module-hub-grid">
                    <?php foreach ($hr_modules as $module): ?>
                        <a href="<?php echo htmlspecialchars($module['url']); ?>" class="module-hub-card <?php echo !$module['available'] ? 'unavailable' : ''; ?>">
                            <div class="module-hub-top">
                                <span class="module-icon-wrap" style="background: <?php echo htmlspecialchars($module['gradient']); ?>;">
                                    <i class="<?php echo htmlspecialchars($module['icon']); ?>"></i>
                                </span>
                                <span class="module-chip"><?php echo htmlspecialchars($module['chip']); ?></span>
                            </div>
                            <h4><?php echo htmlspecialchars($module['title']); ?></h4>
                            <p><?php echo htmlspecialchars($module['description']); ?></p>
                            <div class="module-meta">
                                <strong><?php echo number_format((int)$module['metric']); ?></strong>
                                <span><?php echo htmlspecialchars($module['metric_label']); ?></span>
                            </div>
                            <span class="module-link-text">
                                <?php echo $module['available'] ? 'Open Module' : 'Setup Needed'; ?>
                                <i class="fas fa-arrow-right"></i>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-5">
                        <div class="card h-100 decision-board">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">HR Decision Support</h5>
                                <span class="decision-health-chip <?php echo htmlspecialchars($decision_health_class); ?>">
                                    <?php echo htmlspecialchars($decision_health_label); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="decision-summary">
                                    <div class="decision-score <?php echo htmlspecialchars($decision_health_class); ?>">
                                        <span><?php echo (int)$decision_score; ?></span>
                                        <small>Risk Score</small>
                                    </div>
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($decision_health_label); ?></h6>
                                        <p class="text-muted mb-2"><?php echo htmlspecialchars($decision_health_text); ?></p>
                                        <div class="decision-progress">
                                            <div class="progress-bar <?php echo htmlspecialchars($decision_progress_class); ?>" role="progressbar" style="width: <?php echo (int)$decision_score; ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2">Generated from live attendance, leave, payroll, and staffing signals.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card h-100 decision-board">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Recommended Actions</h5>
                                <small class="text-muted">Prioritized by urgency</small>
                            </div>
                            <div class="card-body">
                                <div class="decision-list">
                                    <?php foreach ($decision_actions as $item): ?>
                                        <div class="decision-item <?php echo htmlspecialchars($item['priority']); ?>">
                                            <div class="top-line">
                                                <span class="priority-badge <?php echo htmlspecialchars($item['priority']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($item['priority'])); ?>
                                                </span>
                                                <a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['url_label']); ?></a>
                                            </div>
                                            <h6><?php echo htmlspecialchars($item['title']); ?></h6>
                                            <p><?php echo htmlspecialchars($item['insight']); ?></p>
                                            <small>Action: <?php echo htmlspecialchars($item['action']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-8">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Attendance Trends (Last 7 Days)</h5>
                                <small class="text-muted">On-time, late, on leave, absent</small>
                            </div>
                            <div class="card-body"><canvas id="attendanceChart" style="max-height: 320px;"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card h-100">
                            <div class="card-header bg-white"><h5 class="mb-0">Focus Queue</h5></div>
                            <div class="card-body">
                                <div class="focus-list">
                                    <div class="focus-item"><div><h6>Attendance Review</h6><p>Pending HR approval</p></div><div class="focus-value"><?php echo $attendance_review_count; ?></div></div>
                                    <div class="focus-item"><div><h6>Leave Requests</h6><p>Pending manager action</p></div><div class="focus-value"><?php echo $leave_count; ?></div></div>
                                    <div class="focus-item"><div><h6>Upcoming Leaves</h6><p>Starts within 7 days</p></div><div class="focus-value"><?php echo $upcoming_leave_count; ?></div></div>
                                    <div class="focus-item"><div><h6>Payroll Queue</h6><p>Pending finance handoff</p></div><div class="focus-value"><?php echo $payroll_count; ?></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hr-recent">
                    <div class="recent-section">
                        <h3>Department Headcount</h3>
                        <div class="recent-list">
                            <?php if ($department_snapshot && mysqli_num_rows($department_snapshot) > 0): ?>
                                <?php while ($dept = mysqli_fetch_assoc($department_snapshot)): ?>
                                    <div class="recent-item"><div class="recent-info"><strong><?php echo htmlspecialchars($dept['department_name']); ?></strong><span class="status-badge badge-active"><?php echo (int)$dept['total']; ?></span></div><small>Active employees</small></div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="text-muted">No department data found.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="recent-section">
                        <h3>Upcoming Approved Leaves</h3>
                        <div class="recent-list">
                            <?php if ($upcoming_leaves && mysqli_num_rows($upcoming_leaves) > 0): ?>
                                <?php while ($leave = mysqli_fetch_assoc($upcoming_leaves)): ?>
                                    <div class="recent-item">
                                        <div class="recent-info"><strong><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></strong><span class="status-badge badge-approved">Approved</span></div>
                                        <small><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($leave['leave_type']))); ?> | <?php echo date('M d', strtotime($leave['start_date'])); ?> - <?php echo date('M d, Y', strtotime($leave['end_date'])); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="text-muted">No upcoming approved leaves.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="recent-section">
                        <h3>Recent Performance Reviews</h3>
                        <div class="recent-list">
                            <?php if ($recent_reviews && mysqli_num_rows($recent_reviews) > 0): ?>
                                <?php while ($review = mysqli_fetch_assoc($recent_reviews)): ?>
                                    <?php $rating = max(0, min(5, (int)($review['overall_rating'] ?? 0))); $stars_html = str_repeat('<i class="fas fa-star"></i>', $rating); ?>
                                    <div class="recent-item"><div class="recent-info"><strong><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></strong><span class="rating-stars"><?php echo $stars_html; ?></span></div><small><?php echo date('M d, Y', strtotime($review['period_start'])); ?> - <?php echo date('M d, Y', strtotime($review['period_end'])); ?></small></div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="text-muted">No recent performance reviews.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebarToggler = document.getElementById('sidebarToggler');
        const adminSidebar = document.getElementById('adminSidebar');
        if (sidebarToggler && adminSidebar) {
            sidebarToggler.addEventListener('click', () => adminSidebar.classList.toggle('active'));
        }

        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        function chartTextColor() { return body.classList.contains('dark-mode') ? '#cbd5e1' : '#475569'; }
        function chartGridColor() { return body.classList.contains('dark-mode') ? 'rgba(148,163,184,0.18)' : 'rgba(148,163,184,0.25)'; }

        let attendanceChart;
        function renderAttendanceChart() {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            if (attendanceChart) attendanceChart.destroy();
            attendanceChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_values($chart_dates)); ?>,
                    datasets: [
                        { label: 'Present (On-time)', data: <?php echo json_encode(array_values($chart_present)); ?>, backgroundColor: '#16a34a' },
                        { label: 'Late', data: <?php echo json_encode(array_values($chart_late)); ?>, backgroundColor: '#f59e0b' },
                        { label: 'On Leave', data: <?php echo json_encode(array_values($chart_on_leave)); ?>, backgroundColor: '#3b82f6' },
                        { label: 'Absent', data: <?php echo json_encode(array_values($chart_absent)); ?>, backgroundColor: '#ef4444' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, ticks: { color: chartTextColor() }, grid: { color: chartGridColor() } },
                        y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, color: chartTextColor() }, grid: { color: chartGridColor() } }
                    },
                    plugins: { legend: { labels: { color: chartTextColor() } } }
                }
            });
        }

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
            renderAttendanceChart();
        });

        const dateDisplay = document.getElementById('currentDate');
        if (dateDisplay) {
            dateDisplay.textContent = new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }

        const moduleSearchInput = document.getElementById('moduleSearchInput');
        if (moduleSearchInput) {
            moduleSearchInput.addEventListener('input', () => {
                const query = moduleSearchInput.value.trim().toLowerCase();
                document.querySelectorAll('.module-hub-card').forEach((card) => {
                    const title = card.querySelector('h4')?.textContent?.toLowerCase() || '';
                    const description = card.querySelector('p')?.textContent?.toLowerCase() || '';
                    const show = !query || title.includes(query) || description.includes(query);
                    card.style.display = show ? '' : 'none';
                });
            });
        }

        renderAttendanceChart();
    </script>
</body>
</html>


