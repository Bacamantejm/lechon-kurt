<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$hr_report_employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

$report_map = [
    'attendance' => ['label' => 'Attendance Report', 'include' => 'reports/attendance_report.php'],
    'payroll' => ['label' => 'Payroll Summary', 'include' => null],
    'leave' => ['label' => 'Leave Utilization Report', 'include' => null],
    'performance' => ['label' => 'Performance Review Report', 'include' => null],
    'turnover' => ['label' => 'Turnover Analysis', 'include' => null]
];

$report_type = $_GET['type'] ?? 'attendance';
if (!array_key_exists($report_type, $report_map)) {
    $report_type = 'attendance';
}

$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$month = ($month >= 1 && $month <= 12) ? $month : intval(date('n'));
$year = ($year >= intval(date('Y')) - 5 && $year <= intval(date('Y')) + 1) ? $year : intval(date('Y'));

$selected_report = $report_map[$report_type];
$report_include = $selected_report['include'];
$report_label = $selected_report['label'];
$has_attendance_table = hrTableExists($conn, 'attendance');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Reports - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title {
            color: var(--text-color-dark) !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }
    </style>
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>HR Reports</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <div class="section-header">
                    <h2>Generate Reports</h2>
                    <a href="hr.php" class="btn btn-secondary"><i class="fas fa-chart-line"></i> HR Dashboard</a>
                </div>
                
                <!-- Report Filter -->
                <div class="card hr-filter-panel mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label>Report Type</label>
                                <select id="reportType" class="form-control">
                                    <?php foreach ($report_map as $report_key => $meta): ?>
                                        <option value="<?php echo htmlspecialchars($report_key); ?>" <?php echo $report_type === $report_key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($meta['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label>Month</label>
                                <select id="monthFilter" class="form-control">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($m == $month) ? 'selected' : ''; ?>>
                                            <?php echo date('M', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label>Year</label>
                                <select id="yearFilter" class="form-control">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y == $year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label>&nbsp;</label>
                                <button class="btn btn-primary w-100" onclick="generateReport()">
                                    <i class="fas fa-chart-bar"></i> Generate Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Report Content -->
                <div class="card">
                    <div class="card-header">
                        <h5 id="reportTitle"><?php echo htmlspecialchars($report_label); ?> - <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h5>
                        <div class="float-end">
                            <button class="btn btn-sm btn-secondary" onclick="exportReport('pdf')">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="exportReport('excel')">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="reportContent">
                        <?php if ($report_type === 'attendance' && !$has_attendance_table): ?>
                            <div class="alert alert-warning mb-0">
                                `attendance` table is missing. Please configure attendance data tables first.
                            </div>
                        <?php elseif ($report_include !== null && file_exists(__DIR__ . '/' . $report_include)): ?>
                            <?php include $report_include; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <?php echo htmlspecialchars($report_label); ?> is not yet generated in-page. Use the linked operational module below to review live data.
                            </div>
                            <div class="dashboard-grid mb-0">
                                <?php if ($report_type === 'payroll'): ?>
                                    <a class="module-card" href="payroll.php">
                                        <i class="fas fa-money-check-alt"></i>
                                        <h3>Open Payroll</h3>
                                        <p>Review payroll drafts, statuses, and historical runs.</p>
                                    </a>
                                    <a class="module-card" href="payslip_generation.php">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <h3>Open Payslips</h3>
                                        <p>Generate and verify payroll-to-payslip output.</p>
                                    </a>
                                <?php elseif ($report_type === 'leave'): ?>
                                    <a class="module-card" href="leave_requests.php">
                                        <i class="fas fa-calendar-minus"></i>
                                        <h3>Leave Requests</h3>
                                        <p>Track leave approvals and status changes.</p>
                                    </a>
                                    <a class="module-card" href="leave_balance.php">
                                        <i class="fas fa-balance-scale"></i>
                                        <h3>Leave Balance</h3>
                                        <p>Review and update annual leave balances.</p>
                                    </a>
                                <?php elseif ($report_type === 'performance'): ?>
                                    <a class="module-card" href="performance.php">
                                        <i class="fas fa-star"></i>
                                        <h3>Performance</h3>
                                        <p>View detailed review records and ratings.</p>
                                    </a>
                                    <a class="module-card" href="employees.php">
                                        <i class="fas fa-id-badge"></i>
                                        <h3>Employees</h3>
                                        <p>Inspect employee profiles for review context.</p>
                                    </a>
                                <?php elseif ($report_type === 'turnover'): ?>
                                    <a class="module-card" href="turnover.php">
                                        <i class="fas fa-user-slash"></i>
                                        <h3>Turnover</h3>
                                        <p>Analyze separations and clearance progress.</p>
                                    </a>
                                    <a class="module-card" href="recruitment.php">
                                        <i class="fas fa-briefcase"></i>
                                        <h3>Recruitment</h3>
                                        <p>Coordinate replacement hiring after exits.</p>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Theme Toggler
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

        document.getElementById('reportType').addEventListener('change', updateReportTitle);
        document.getElementById('monthFilter').addEventListener('change', updateReportTitle);
        document.getElementById('yearFilter').addEventListener('change', updateReportTitle);
        
        function updateReportTitle() {
            const type = document.getElementById('reportType').value;
            const month = document.getElementById('monthFilter').value;
            const year = document.getElementById('yearFilter').value;
            
            const monthName = new Date(year, month - 1).toLocaleString('en-US', { month: 'long' });
            const titles = <?php echo json_encode(array_map(function ($meta) { return $meta['label']; }, $report_map)); ?>;
            
            document.getElementById('reportTitle').textContent = titles[type] + ' - ' + monthName + ' ' + year;
        }
        
        function generateReport() {
            const type = document.getElementById('reportType').value;
            const month = document.getElementById('monthFilter').value;
            const year = document.getElementById('yearFilter').value;
            
            window.location.href = 'hr_reports.php?type=' + type + '&month=' + month + '&year=' + year;
        }
        
        function exportReport(format) {
            const type = document.getElementById('reportType').value;
            const month = document.getElementById('monthFilter').value;
            const year = document.getElementById('yearFilter').value;
            
            window.open('export_report.php?type=' + type + '&month=' + month + '&year=' + year + '&format=' + format, '_blank');
        }
    </script>
</body>
</html>


