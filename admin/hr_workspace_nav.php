<?php
$current_hr_page = basename($_SERVER['PHP_SELF']);
$today_hr = date('Y-m-d');
$hr_workspace_is_partner = false;
if (isset($_SESSION['user_id']) && isset($conn) && $conn && function_exists('isApprovedFranchiseSellerAccount')) {
    $hr_workspace_is_partner = isApprovedFranchiseSellerAccount($conn, (int)$_SESSION['user_id']);
}

$hr_workspace_links = [
    ['file' => 'hr.php', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'href' => 'hr.php'],
    ['file' => 'employees.php', 'label' => 'Employees', 'icon' => 'fas fa-id-badge', 'href' => 'employees.php'],
    ['file' => 'departments.php', 'label' => 'Departments', 'icon' => 'fas fa-sitemap', 'href' => 'departments.php'],
    ['file' => 'attendance.php', 'label' => 'Attendance', 'icon' => 'fas fa-calendar-check', 'href' => "attendance.php?date_from={$today_hr}&date_to={$today_hr}"],
    ['file' => 'schedules.php', 'label' => 'Schedules', 'icon' => 'fas fa-clock', 'href' => 'schedules.php'],
    ['file' => 'leave_requests.php', 'label' => 'Leave Requests', 'icon' => 'fas fa-calendar-minus', 'href' => 'leave_requests.php'],
    ['file' => 'leave_balance.php', 'label' => 'Leave Balance', 'icon' => 'fas fa-balance-scale', 'href' => 'leave_balance.php'],
    ['file' => 'payroll.php', 'label' => 'Payroll', 'icon' => 'fas fa-money-check-alt', 'href' => 'payroll.php'],
    ['file' => 'deductions.php', 'label' => 'Deductions', 'icon' => 'fas fa-hand-holding-usd', 'href' => 'deductions.php'],
    ['file' => 'payslip_generation.php', 'label' => 'Payslips', 'icon' => 'fas fa-file-invoice-dollar', 'href' => 'payslip_generation.php'],
    ['file' => 'performance.php', 'label' => 'Performance', 'icon' => 'fas fa-star', 'href' => 'performance.php'],
    ['file' => 'recruitment.php', 'label' => 'Recruitment', 'icon' => 'fas fa-briefcase', 'href' => 'recruitment.php'],
    ['file' => 'candidates.php', 'label' => 'Candidates', 'icon' => 'fas fa-user-tie', 'href' => 'candidates.php'],
    ['file' => 'turnover.php', 'label' => 'Turnover', 'icon' => 'fas fa-user-slash', 'href' => 'turnover.php'],
    ['file' => 'hr_reports.php', 'label' => 'HR Reports', 'icon' => 'fas fa-chart-bar', 'href' => 'hr_reports.php'],
    ['file' => 'hr_migration_checker.php', 'label' => 'DB Checker', 'icon' => 'fas fa-database', 'href' => 'hr_migration_checker.php']
];

if ($hr_workspace_is_partner) {
    $hr_workspace_links = array_values(array_filter($hr_workspace_links, function ($link) {
        $blocked = ['hr_migration_checker.php'];
        return !in_array($link['file'], $blocked, true);
    }));
}
?>
<div class="hr-workspace-bar">
    <div class="hr-workspace-head">
        <i class="fas fa-link"></i>
        <span>HR Workspace</span>
    </div>
    <div class="hr-workspace-links">
        <?php foreach ($hr_workspace_links as $link): ?>
            <?php $active = ($current_hr_page === $link['file']) ? 'active' : ''; ?>
            <a href="<?php echo htmlspecialchars($link['href']); ?>"
               class="hr-workspace-link <?php echo $active; ?>"
               title="<?php echo htmlspecialchars($link['label']); ?>"
               aria-label="<?php echo htmlspecialchars($link['label']); ?>"
               data-label="<?php echo htmlspecialchars($link['label']); ?>">
                <i class="<?php echo htmlspecialchars($link['icon']); ?>"></i>
                <span class="hr-workspace-text"><?php echo htmlspecialchars($link['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
