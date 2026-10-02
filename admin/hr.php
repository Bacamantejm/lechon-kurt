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
        'theme' => 'kpi-blue'
    ],
    [
        'title' => 'Present Today',
        'value' => $present_total,
        'subtitle' => 'On-time + late attendance',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-check',
        'theme' => 'kpi-green'
    ],
    [
        'title' => 'Late Today',
        'value' => (int)$today_snapshot['late'],
        'subtitle' => $late_rate . '% of active employees',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-clock',
        'theme' => 'kpi-amber'
    ],
    [
        'title' => 'Absent Today',
        'value' => (int)$today_snapshot['absent'],
        'subtitle' => $absent_rate . '% absentee rate',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-user-times',
        'theme' => 'kpi-red'
    ],
    [
        'title' => 'Pending Leave Requests',
        'value' => $leave_count,
        'subtitle' => 'Awaiting HR action',
        'url' => 'leave_requests.php',
        'icon' => 'fas fa-calendar-times',
        'theme' => 'kpi-purple'
    ],
    [
        'title' => 'Pending Payroll',
        'value' => $payroll_count,
        'subtitle' => 'Pending finance handoff',
        'url' => 'payroll.php',
        'icon' => 'fas fa-money-bill-wave',
        'theme' => 'kpi-rose'
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
        'chip' => 'Core Team',
        'category' => 'staff',
        'theme' => 'blue'
    ],
    [
        'title' => 'Departments',
        'description' => 'Create and organize departments for clearer ownership.',
        'url' => 'departments.php',
        'icon' => 'fas fa-sitemap',
        'metric' => $department_count,
        'metric_label' => 'Departments',
        'chip' => 'Structure',
        'category' => 'staff',
        'theme' => 'blue'
    ],
    [
        'title' => 'Attendance',
        'description' => 'Review daily logs, approvals, and attendance exceptions.',
        'url' => 'attendance.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'),
        'icon' => 'fas fa-calendar-check',
        'metric' => $attendance_review_count,
        'metric_label' => 'Pending HR Review',
        'chip' => 'Daily Ops',
        'category' => 'daily',
        'theme' => 'green'
    ],
    [
        'title' => 'Schedules',
        'description' => 'Manage shift schedules and date-specific assignments.',
        'url' => 'schedules.php',
        'icon' => 'fas fa-clock',
        'metric' => $schedule_today_count,
        'metric_label' => "Today's Schedules",
        'chip' => 'Planning',
        'category' => 'daily',
        'theme' => 'amber'
    ],
    [
        'title' => 'Leave Requests',
        'description' => 'Approve, reject, and track employee leave requests.',
        'url' => 'leave_requests.php',
        'icon' => 'fas fa-calendar-minus',
        'metric' => $leave_count,
        'metric_label' => 'Pending Requests',
        'chip' => 'Approvals',
        'category' => 'daily',
        'theme' => 'amber'
    ],
    [
        'title' => 'Leave Balance',
        'description' => 'Maintain yearly leave allocations and balances.',
        'url' => 'leave_balance.php',
        'icon' => 'fas fa-balance-scale',
        'metric' => $leave_balance_profiles_count,
        'metric_label' => 'Profiles Updated',
        'chip' => 'Compliance',
        'category' => 'daily',
        'theme' => 'amber'
    ],
    [
        'title' => 'Payroll',
        'description' => 'Generate payroll drafts and submit for finance approval.',
        'url' => 'payroll.php',
        'icon' => 'fas fa-wallet',
        'metric' => $payroll_count,
        'metric_label' => 'Pending Payroll',
        'chip' => 'Compensation',
        'category' => 'payroll',
        'theme' => 'red'
    ],
    [
        'title' => 'Deductions',
        'description' => 'Track recurring and employee-specific deduction rules.',
        'url' => 'deductions.php',
        'icon' => 'fas fa-file-invoice-dollar',
        'metric' => $active_deductions_count,
        'metric_label' => 'Active Deductions',
        'chip' => 'Compensation',
        'category' => 'payroll',
        'theme' => 'red'
    ],
    [
        'title' => 'Payslip Generation',
        'description' => 'Generate, review, and release payslips to employees.',
        'url' => 'payslip_generation.php',
        'icon' => 'fas fa-file-invoice',
        'metric' => $payslip_draft_count,
        'metric_label' => 'Draft/Generated',
        'chip' => 'Payroll',
        'category' => 'payroll',
        'theme' => 'red'
    ],
    [
        'title' => 'Performance',
        'description' => 'Monitor performance cycles and submitted evaluations.',
        'url' => 'performance.php',
        'icon' => 'fas fa-chart-line',
        'metric' => $performance_submitted_count,
        'metric_label' => 'Submitted Reviews',
        'chip' => 'People Dev',
        'category' => 'talent',
        'theme' => 'purple'
    ],
    [
        'title' => 'Recruitment',
        'description' => 'Track open positions and hiring progress.',
        'url' => 'recruitment.php',
        'icon' => 'fas fa-user-plus',
        'metric' => $open_positions_count,
        'metric_label' => 'Open Positions',
        'chip' => 'Hiring',
        'category' => 'talent',
        'theme' => 'purple'
    ],
    [
        'title' => 'Candidates',
        'description' => 'Review candidate pipeline and interview readiness.',
        'url' => 'candidates.php',
        'icon' => 'fas fa-user-friends',
        'metric' => $new_candidates_count,
        'metric_label' => 'New Candidates',
        'chip' => 'Hiring',
        'category' => 'talent',
        'theme' => 'purple'
    ],
    [
        'title' => 'Turnover',
        'description' => 'Handle resignation, clearance, and transition records.',
        'url' => 'turnover.php',
        'icon' => 'fas fa-people-arrows',
        'metric' => $turnover_pending_count,
        'metric_label' => 'Pending Clearance',
        'chip' => 'Lifecycle',
        'category' => 'staff',
        'theme' => 'blue'
    ],
    [
        'title' => 'HR Reports',
        'description' => 'Generate attendance, payroll, leave, and trend reports.',
        'url' => 'hr_reports.php',
        'icon' => 'fas fa-chart-bar',
        'metric' => $attendance_month_records,
        'metric_label' => 'Attendance Rows This Month',
        'chip' => 'Analytics',
        'category' => 'talent',
        'theme' => 'indigo'
    ],
    [
        'title' => 'HR DB Checker',
        'description' => 'Validate required HR tables and columns before opening modules.',
        'url' => 'hr_migration_checker.php',
        'icon' => 'fas fa-database',
        'metric' => 1,
        'metric_label' => 'Readiness Tool',
        'chip' => 'Setup',
        'category' => 'talent',
        'theme' => 'slate'
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
        
        /* Welcome & Orientation Guide */
        .welcome-guide-banner {
            background: #ffffff;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }
        .welcome-guide-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #b3261e;
            background: #fff1f0;
            padding: 4px 10px;
            border-radius: 999px;
            margin-bottom: 8px;
            border: 1px solid #fee4e2;
        }
        .welcome-guide-banner h2 { font-size: 1.25rem; font-weight: 700; margin: 0 0 4px 0; color: #101828; }
        .welcome-guide-banner p { margin: 0 0 12px 0; font-size: 0.88rem; color: #475467; max-width: 650px; line-height: 1.45; }
        .welcome-status-pill {
            background: #f8fafc;
            border: 1px solid #eaecf0;
            color: #344054;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* KPI Cards with Friendly Accents */
        .stats-dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stats-dashboard-grid .stat-card {
            min-height: 110px;
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid #eaecf0;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.2s ease;
        }
        .stats-dashboard-grid .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 24, 40, 0.06);
            border-color: #d0d5dd;
        }
        .stats-dashboard-grid .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: none !important;
            transition: all 0.2s ease;
        }
        .kpi-blue .stat-icon { background: #eff8ff !important; color: #175cd3 !important; border: 1px solid #b2ddff !important; }
        .kpi-green .stat-icon { background: #ecfdf3 !important; color: #027a48 !important; border: 1px solid #abefc6 !important; }
        .kpi-amber .stat-icon { background: #fffaeb !important; color: #b54708 !important; border: 1px solid #fedf89 !important; }
        .kpi-red .stat-icon { background: #fff1f0 !important; color: #b3261e !important; border: 1px solid #fee4e2 !important; }
        .kpi-purple .stat-icon { background: #f9f5ff !important; color: #6941c6 !important; border: 1px solid #e9d7fe !important; }
        .kpi-rose .stat-icon { background: #fff1f2 !important; color: #e11d48 !important; border: 1px solid #fecdd3 !important; }

        .stats-dashboard-grid .stat-content { flex: 1; min-width: 0; }
        .stats-dashboard-grid .stat-content h3 { font-size: 22px; font-weight: 700; color: #101828; margin: 0; line-height: 1.25; }
        .stats-dashboard-grid .stat-content p { font-size: 13px; font-weight: 600; color: #344054; margin: 2px 0 0 0; }
        .stats-dashboard-grid .stat-content small { font-size: 11.5px; color: #667085; }

        /* Filter Pills Bar */
        .section-header.compact { margin-bottom: 12px; }
        .module-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .module-search-input { width: 240px; border: 1px solid #d0d5dd; border-radius: 8px; padding: 8px 12px; background: #fff; color: #101828; font-size: 0.88rem; }
        .module-search-input:focus { outline: none; border-color: #b3261e; box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1); }
        
        .module-filter-bar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .btn-filter-pill {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
            border: 1px solid #eaecf0;
            background: #ffffff;
            color: #475467;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-filter-pill:hover {
            background: #f8fafc;
            border-color: #d0d5dd;
            color: #101828;
        }
        .btn-filter-pill.active {
            background: #b3261e;
            border-color: #b3261e;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(179, 38, 30, 0.2);
        }

        /* Friendly & Elegant Module Cards */
        .module-hub-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .module-hub-card {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            background: #ffffff;
            padding: 18px 20px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            transition: all 0.2s ease;
            min-height: 205px;
            overflow: hidden;
        }
        .module-hub-card::before { display: none !important; }
        .module-hub-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px -3px rgba(16, 24, 40, 0.08);
            border-color: #d0d5dd;
        }
        .module-hub-card.unavailable { border-style: dashed; opacity: 0.75; }
        .module-hub-card.unavailable .module-link-text { color: #b54708; }
        .module-hub-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        
        .module-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: none !important;
            transition: all 0.2s ease;
        }

        /* Module Theme Accents */
        .module-theme-blue .module-icon-wrap { background: #eff8ff !important; color: #175cd3 !important; border: 1px solid #b2ddff !important; }
        .module-theme-blue .module-chip { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }
        .module-theme-blue:hover { border-color: #b2ddff; }

        .module-theme-green .module-icon-wrap { background: #ecfdf3 !important; color: #027a48 !important; border: 1px solid #abefc6 !important; }
        .module-theme-green .module-chip { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .module-theme-green:hover { border-color: #abefc6; }

        .module-theme-amber .module-icon-wrap { background: #fffaeb !important; color: #b54708 !important; border: 1px solid #fedf89 !important; }
        .module-theme-amber .module-chip { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
        .module-theme-amber:hover { border-color: #fedf89; }

        .module-theme-red .module-icon-wrap { background: #fff1f0 !important; color: #b3261e !important; border: 1px solid #fee4e2 !important; }
        .module-theme-red .module-chip { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
        .module-theme-red:hover { border-color: #fee4e2; }

        .module-theme-purple .module-icon-wrap { background: #f9f5ff !important; color: #6941c6 !important; border: 1px solid #e9d7fe !important; }
        .module-theme-purple .module-chip { background: #f9f5ff; color: #6941c6; border: 1px solid #e9d7fe; }
        .module-theme-purple:hover { border-color: #e9d7fe; }

        .module-theme-indigo .module-icon-wrap { background: #eef4ff !important; color: #3538cd !important; border: 1px solid #c7d7fe !important; }
        .module-theme-indigo .module-chip { background: #eef4ff; color: #3538cd; border: 1px solid #c7d7fe; }
        .module-theme-indigo:hover { border-color: #c7d7fe; }

        .module-theme-slate .module-icon-wrap { background: #f8fafc !important; color: #475467 !important; border: 1px solid #eaecf0 !important; }
        .module-theme-slate .module-chip { background: #f8fafc; color: #475467; border: 1px solid #eaecf0; }
        .module-theme-slate:hover { border-color: #d0d5dd; }

        .module-chip {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .module-hub-card h4 { margin: 2px 0 0 0; font-size: 1.02rem; font-weight: 700; color: #101828; }
        .module-hub-card p { margin: 0; color: #475467; font-size: 0.83rem; line-height: 1.45; }
        
        .module-meta {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
            padding-top: 10px;
            border-top: 1px solid #f2f4f7;
        }
        .module-meta strong { font-size: 1.25rem; font-weight: 800; color: #101828; font-variant-numeric: tabular-nums; }
        .module-meta span { font-size: 0.73rem; color: #667085; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; text-align: right; }
        .module-link-text { font-size: 0.82rem; color: #b3261e; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: transform 0.15s ease; }
        .module-hub-card:hover .module-link-text { transform: translateX(3px); }
        body.dark-mode .module-hub-card h4, body.dark-mode .module-meta strong, body.dark-mode .module-link-text { color: var(--text-color-dark) !important; }
        body.dark-mode .module-hub-card p, body.dark-mode .module-meta span, body.dark-mode .module-header-note, body.dark-mode .module-chip { color: #b0b0b0 !important; }
        body.dark-mode .module-search-input { background: #1f2937; color: #e2e8f0; border-color: #475569; }
        .decision-board {
            border: 1px solid #eaecf0;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }
        .decision-board .card-header {
            border-bottom: 1px solid #eaecf0;
            background: #ffffff !important;
            padding: 16px 18px;
        }
        .decision-board .card-header h5 {
            font-size: 1rem;
            font-weight: 700;
            color: #101828;
        }
        .decision-health-chip {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            border: 1px solid transparent;
        }
        .decision-health-chip.low-risk { background: #ecfdf3; color: #027a48; border-color: #abefc6; }
        .decision-health-chip.medium-risk { background: #fffaeb; color: #b54708; border-color: #fedf89; }
        .decision-health-chip.high-risk { background: #fff1f0; color: #b3261e; border-color: #fee4e2; }
        .decision-summary {
            display: grid;
            grid-template-columns: minmax(120px, 140px) 1fr;
            gap: 14px;
            align-items: center;
            padding: 4px 0 2px;
        }
        .decision-score {
            width: 108px;
            height: 108px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 6px solid #eaecf0;
            background: #fff;
            box-shadow: none;
            margin: 0 auto;
        }
        .decision-score span { font-size: 1.8rem; line-height: 1; font-weight: 700; color: #101828; }
        .decision-score small { font-size: 0.72rem; color: #667085; font-weight: 600; }
        .decision-score.low-risk { border-color: #abefc6; }
        .decision-score.medium-risk { border-color: #fedf89; }
        .decision-score.high-risk { border-color: #fee4e2; }
        .decision-progress { height: 6px; border-radius: 999px; background: #eaecf0; overflow: hidden; }
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
            border: 1px solid #eaecf0;
            border-radius: 8px;
            background: #ffffff;
            padding: 12px 14px;
        }
        .decision-item.high { border-color: #fee4e2; background: #fffcfb; }
        .decision-item.medium { border-color: #fedf89; background: #fffefa; }
        .decision-item.low { border-color: #eaecf0; background: #ffffff; }
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
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
        }
        .priority-badge.high { background: #fff1f0; color: #b3261e; border-color: #fee4e2; }
        .priority-badge.medium { background: #fffaeb; color: #b54708; border-color: #fedf89; }
        .priority-badge.low { background: #eff8ff; color: #175cd3; border-color: #b2ddff; }
        .decision-item h6 { margin: 0 0 4px 0; font-size: 0.9rem; font-weight: 700; color: #101828; }
        .decision-item p { margin: 0 0 4px 0; font-size: 0.82rem; color: #475467; line-height: 1.35; }
        .decision-item small { color: #667085; font-size: 0.76rem; }
        .decision-item a { color: #b3261e; font-size: 0.76rem; font-weight: 600; text-decoration: none; }
        .decision-item a:hover { text-decoration: underline; }
        body.dark-mode .decision-board {
            background: #1e2430;
            border-color: var(--border-color-dark);
        }
        body.dark-mode .decision-item,
        body.dark-mode .decision-score {
            background: #252d3a;
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
                <div class="welcome-guide-banner">
                    <div>
                        <div class="welcome-guide-badge"><i class="fas fa-compass"></i> HR Operations Hub</div>
                        <h2>Good day, <?php echo htmlspecialchars($admin_info['full_name']); ?>!</h2>
                        <p>Welcome to your Human Resources workspace. Monitor workforce attendance, approve leave requests, and manage payroll with ease. Choose a category below to quickly find what you need.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="welcome-status-pill"><i class="fas fa-user-check text-success"></i> Presence: <strong><?php echo $presence_rate; ?>%</strong></span>
                            <span class="welcome-status-pill"><i class="fas fa-shield-alt text-primary"></i> Coverage: <strong><?php echo $coverage_rate; ?>%</strong></span>
                            <span class="welcome-status-pill"><i class="fas fa-clipboard-check text-warning"></i> Pending Reviews: <strong><?php echo $attendance_review_count; ?></strong></span>
                            <span class="welcome-status-pill"><i class="fas fa-plane-departure text-info"></i> Upcoming Leaves: <strong><?php echo $upcoming_leave_count; ?></strong></span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a href="employees.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; background: #fff;"><i class="fas fa-users me-1"></i> Staff Directory</a>
                        <a href="attendance.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; background: #fff;"><i class="fas fa-calendar-check me-1"></i> Today's Logs</a>
                        <a href="payroll.php" class="btn btn-sm text-white" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; background: #b3261e;"><i class="fas fa-file-invoice-dollar me-1"></i> Run Payroll</a>
                    </div>
                </div>

                <div class="stats-dashboard-grid">
                    <?php foreach ($dashboard_kpis as $kpi): ?>
                        <a href="<?php echo htmlspecialchars($kpi['url']); ?>" class="stat-card-link <?php echo htmlspecialchars($kpi['theme'] ?? 'kpi-blue'); ?>">
                            <div class="stat-card">
                                <div class="stat-icon">
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
                    <div>
                        <h2>HR Module Hub</h2>
                        <span class="module-header-note">Browse HR workflows by category or search by module name.</span>
                    </div>
                    <div class="module-tools">
                        <input type="text" id="moduleSearchInput" class="module-search-input" placeholder="Search modules...">
                    </div>
                </div>

                <div class="module-filter-bar" id="moduleFilterBar">
                    <button type="button" class="btn-filter-pill active" data-filter="all">
                        <i class="fas fa-th-large"></i> All Modules (<?php echo count($hr_modules); ?>)
                    </button>
                    <button type="button" class="btn-filter-pill" data-filter="staff">
                        <i class="fas fa-user-friends"></i> Staff & Teams
                    </button>
                    <button type="button" class="btn-filter-pill" data-filter="daily">
                        <i class="fas fa-calendar-day"></i> Attendance & Leaves
                    </button>
                    <button type="button" class="btn-filter-pill" data-filter="payroll">
                        <i class="fas fa-receipt"></i> Payroll & Pay
                    </button>
                    <button type="button" class="btn-filter-pill" data-filter="talent">
                        <i class="fas fa-user-tie"></i> Hiring & Growth
                    </button>
                </div>

                <div class="module-hub-grid">
                    <?php foreach ($hr_modules as $module): ?>
                        <a href="<?php echo htmlspecialchars($module['url']); ?>" 
                           class="module-hub-card module-theme-<?php echo htmlspecialchars($module['theme'] ?? 'slate'); ?> <?php echo !$module['available'] ? 'unavailable' : ''; ?>"
                           data-category="<?php echo htmlspecialchars($module['category'] ?? 'all'); ?>">
                            <div class="module-hub-top">
                                <span class="module-icon-wrap">
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
        const filterPills = document.querySelectorAll('.btn-filter-pill');
        let currentModuleFilter = 'all';

        function filterHrModules() {
            const query = (moduleSearchInput ? moduleSearchInput.value : '').trim().toLowerCase();
            document.querySelectorAll('.module-hub-card').forEach((card) => {
                const category = card.getAttribute('data-category') || 'all';
                const matchesCategory = (currentModuleFilter === 'all' || category === currentModuleFilter);

                const title = card.querySelector('h4')?.textContent?.toLowerCase() || '';
                const description = card.querySelector('p')?.textContent?.toLowerCase() || '';
                const matchesSearch = !query || title.includes(query) || description.includes(query);

                card.style.display = (matchesCategory && matchesSearch) ? '' : 'none';
            });
        }

        if (moduleSearchInput) {
            moduleSearchInput.addEventListener('input', filterHrModules);
        }

        filterPills.forEach((pill) => {
            pill.addEventListener('click', () => {
                filterPills.forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                currentModuleFilter = pill.getAttribute('data-filter') || 'all';
                filterHrModules();
            });
        });

        renderAttendanceChart();
    </script>
</body>
</html>


