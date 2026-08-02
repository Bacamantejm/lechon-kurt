<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);

date_default_timezone_set(date_default_timezone_get());

function fetchTableColumns($conn, $table_name) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        return [];
    }

    $columns = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table_name}`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

$module_checks = [
    [
        'name' => 'Employees',
        'url' => 'employees.php',
        'requirements' => [
            ['table' => 'employees', 'columns' => ['id', 'employee_id', 'first_name', 'last_name', 'email', 'position', 'status', 'hire_date', 'salary', 'daily_rate']]
        ]
    ],
    [
        'name' => 'Departments',
        'url' => 'departments.php',
        'requirements' => [
            ['table' => 'departments', 'columns' => ['id', 'department_name']]
        ]
    ],
    [
        'name' => 'Attendance',
        'url' => 'attendance.php',
        'requirements' => [
            ['table' => 'attendance', 'columns' => ['id', 'employee_id', 'attendance_date', 'status', 'check_in_time', 'check_out_time']]
        ]
    ],
    [
        'name' => 'Schedules',
        'url' => 'schedules.php',
        'requirements' => [
            ['table' => 'schedules', 'columns' => ['id', 'employee_id', 'schedule_date', 'start_time', 'end_time']]
        ]
    ],
    [
        'name' => 'Leave Requests',
        'url' => 'leave_requests.php',
        'requirements' => [
            ['table' => 'leave_requests', 'columns' => ['id', 'employee_id', 'leave_type', 'start_date', 'end_date', 'status']]
        ]
    ],
    [
        'name' => 'Leave Balance',
        'url' => 'leave_balance.php',
        'requirements' => [
            ['table' => 'leave_balance', 'columns' => ['id', 'employee_id', 'leave_type', 'year', 'initial_balance', 'balance_remaining']]
        ]
    ],
    [
        'name' => 'Payroll',
        'url' => 'payroll.php',
        'requirements' => [
            ['table' => 'payroll', 'columns' => ['id', 'employee_id', 'base_salary', 'overtime_hours', 'overtime_pay', 'bonuses', 'gross_pay', 'deductions', 'net_pay', 'pay_period_start', 'pay_period_end', 'status']]
        ]
    ],
    [
        'name' => 'Deductions',
        'url' => 'deductions.php',
        'requirements' => [
            ['table' => 'employee_deductions', 'columns' => ['id', 'employee_id', 'deduction_type', 'description', 'amount_per_payroll', 'start_date', 'end_date', 'status']]
        ]
    ],
    [
        'name' => 'Payslip Generation',
        'url' => 'payslip_generation.php',
        'requirements' => [
            ['table' => 'payroll', 'columns' => ['id', 'employee_id', 'base_salary', 'overtime_hours', 'overtime_pay', 'bonuses', 'deductions', 'pay_period_start', 'pay_period_end']],
            ['table' => 'payslips', 'columns' => ['id', 'employee_id', 'payroll_id', 'payslip_number', 'gross_pay', 'total_deductions', 'net_pay', 'status', 'generated_at']],
            ['table' => 'deduction_rates', 'columns' => ['id', 'rate_type', 'employee_rate', 'salary_ceiling', 'year', 'active'], 'optional' => true, 'note' => 'Optional: used for statutory auto-computation']
        ]
    ],
    [
        'name' => 'Performance',
        'url' => 'performance.php',
        'requirements' => [
            ['table' => 'performance_reviews', 'columns' => ['id', 'employee_id', 'reviewer_id', 'period_start', 'period_end', 'overall_rating', 'status', 'created_at']]
        ]
    ],
    [
        'name' => 'Recruitment',
        'url' => 'recruitment.php',
        'requirements' => [
            ['table' => 'job_positions', 'columns' => ['id', 'position_title', 'department_id', 'employment_type', 'status', 'posted_date', 'created_by']],
            ['table' => 'candidates', 'columns' => ['id', 'position_id', 'status'], 'optional' => true, 'note' => 'Optional for candidate counts']
        ]
    ],
    [
        'name' => 'Candidates',
        'url' => 'candidates.php',
        'requirements' => [
            ['table' => 'candidates', 'columns' => ['id', 'position_id', 'first_name', 'last_name', 'email', 'phone', 'years_experience', 'status', 'created_at']],
            ['table' => 'job_positions', 'columns' => ['id', 'position_title']]
        ]
    ],
    [
        'name' => 'Turnover',
        'url' => 'turnover.php',
        'requirements' => [
            ['table' => 'employee_turnover', 'columns' => ['id', 'employee_id', 'separation_type', 'resignation_date', 'last_working_day', 'notice_period_days', 'exit_clearance_status', 'processed_by', 'created_at']]
        ]
    ],
    [
        'name' => 'HR Reports',
        'url' => 'hr_reports.php',
        'requirements' => [
            ['table' => 'attendance', 'columns' => ['id', 'employee_id', 'attendance_date', 'status']],
            ['table' => 'employees', 'columns' => ['id', 'employee_id', 'first_name', 'last_name', 'status']]
        ]
    ]
];

$all_tables = [];
foreach ($module_checks as $module) {
    foreach ($module['requirements'] as $req) {
        $all_tables[$req['table']] = true;
    }
}
$all_tables = array_keys($all_tables);
sort($all_tables);

$table_state = [];
foreach ($all_tables as $table_name) {
    $exists = hrTableExists($conn, $table_name);
    $table_state[$table_name] = [
        'exists' => $exists,
        'columns' => $exists ? fetchTableColumns($conn, $table_name) : []
    ];
}

$summary = [
    'total_modules' => count($module_checks),
    'ready' => 0,
    'warnings' => 0,
    'critical' => 0
];

foreach ($module_checks as &$module) {
    $critical_issues = [];
    $warning_issues = [];

    foreach ($module['requirements'] as $req) {
        $table_name = $req['table'];
        $is_optional = (bool)($req['optional'] ?? false);
        $note = $req['note'] ?? '';

        $state = $table_state[$table_name] ?? ['exists' => false, 'columns' => []];
        if (!$state['exists']) {
            $message = "Missing table: {$table_name}";
            if ($note !== '') {
                $message .= " ({$note})";
            }

            if ($is_optional) {
                $warning_issues[] = $message;
            } else {
                $critical_issues[] = $message;
            }
            continue;
        }

        $missing_columns = array_values(array_diff($req['columns'], $state['columns']));
        if (!empty($missing_columns)) {
            $message = "Table {$table_name} missing columns: " . implode(', ', $missing_columns);
            if ($note !== '') {
                $message .= " ({$note})";
            }

            if ($is_optional) {
                $warning_issues[] = $message;
            } else {
                $critical_issues[] = $message;
            }
        }
    }

    if (!empty($critical_issues)) {
        $module['status'] = 'critical';
        $summary['critical']++;
    } elseif (!empty($warning_issues)) {
        $module['status'] = 'warning';
        $summary['warnings']++;
    } else {
        $module['status'] = 'ready';
        $summary['ready']++;
    }

    $module['critical_issues'] = $critical_issues;
    $module['warning_issues'] = $warning_issues;
}
unset($module);

$last_checked = date('M d, Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR DB Migration Checker</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <style>
        .checker-summary-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: #fff;
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }
        .checker-value {
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1;
        }
        .checker-label {
            font-size: 0.86rem;
            color: var(--text-light);
        }
        .schema-badge {
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .schema-badge.ready {
            background: #ecfdf3;
            color: #15803d;
            border-color: #86efac;
        }
        .schema-badge.warning {
            background: #fffbeb;
            color: #b45309;
            border-color: #fcd34d;
        }
        .schema-badge.critical {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fca5a5;
        }
        .issue-line {
            font-size: 0.82rem;
            margin-bottom: 4px;
        }
        .issue-line.critical { color: #b91c1c; }
        .issue-line.warning { color: #b45309; }
        .table-check-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
        }
        .table-check-item {
            border: 1px solid var(--border);
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .table-check-item .name { font-weight: 700; }
        .table-check-item .state { font-size: 0.8rem; color: var(--text-light); }
        body.dark-mode .checker-summary-card,
        body.dark-mode .table-check-item {
            background: #2f3543 !important;
            border-color: #404040 !important;
        }
        body.dark-mode .issue-line.warning { color: #facc15; }
        body.dark-mode .issue-line.critical { color: #f87171; }
    </style>
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>HR DB Migration Checker</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name'] ?? 'Admin'); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <div class="section-header">
                    <h2>Schema Health Overview</h2>
                    <a href="hr.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> HR Dashboard</a>
                </div>

                <div class="alert alert-info">
                    <strong>Last Checked:</strong> <?php echo htmlspecialchars($last_checked); ?> |
                    This page verifies required HR tables/columns so you can fix migrations before opening each module.
                </div>

                <div class="dashboard-grid mb-4">
                    <div class="checker-summary-card">
                        <div class="checker-value"><?php echo (int)$summary['total_modules']; ?></div>
                        <div class="checker-label">Modules Checked</div>
                    </div>
                    <div class="checker-summary-card">
                        <div class="checker-value text-success"><?php echo (int)$summary['ready']; ?></div>
                        <div class="checker-label">Ready</div>
                    </div>
                    <div class="checker-summary-card">
                        <div class="checker-value text-warning"><?php echo (int)$summary['warnings']; ?></div>
                        <div class="checker-label">Warnings</div>
                    </div>
                    <div class="checker-summary-card">
                        <div class="checker-value text-danger"><?php echo (int)$summary['critical']; ?></div>
                        <div class="checker-label">Critical Issues</div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Module-by-Module Check</h5>
                        <button class="btn btn-sm btn-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Recheck
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Module</th>
                                        <th>Status</th>
                                        <th>Findings</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($module_checks as $module): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($module['name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="schema-badge <?php echo htmlspecialchars($module['status']); ?>">
                                                    <i class="fas <?php echo $module['status'] === 'ready' ? 'fa-check-circle' : ($module['status'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'); ?>"></i>
                                                    <?php echo strtoupper(htmlspecialchars($module['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($module['status'] === 'ready'): ?>
                                                    <span class="text-success small">No schema issues detected.</span>
                                                <?php endif; ?>
                                                <?php foreach ($module['critical_issues'] as $issue): ?>
                                                    <div class="issue-line critical"><?php echo htmlspecialchars($issue); ?></div>
                                                <?php endforeach; ?>
                                                <?php foreach ($module['warning_issues'] as $issue): ?>
                                                    <div class="issue-line warning"><?php echo htmlspecialchars($issue); ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($module['url']); ?>" class="btn btn-sm btn-secondary">
                                                    Open <?php echo htmlspecialchars($module['name']); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Detected HR Tables</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-check-grid">
                            <?php foreach ($table_state as $table_name => $state): ?>
                                <div class="table-check-item">
                                    <div class="name"><?php echo htmlspecialchars($table_name); ?></div>
                                    <div class="state">
                                        <?php if ($state['exists']): ?>
                                            <span class="text-success">Exists</span> | <?php echo count($state['columns']); ?> columns
                                        <?php else: ?>
                                            <span class="text-danger">Missing</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>

