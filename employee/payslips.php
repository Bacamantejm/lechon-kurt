<?php
require_once 'session_check.php';
require_once 'header.php';

// Fetch employee name from users table for topbar
$stmt_user = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$employee_user = $result_user->fetch_assoc();
$stmt_user->close();
?>

<!-- Top Navigation Bar -->
<div class="admin-topbar">
    <div class="topbar-content">
        <div class="d-flex align-items-center">
            <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
            <div class="topbar-title">
                <h1>My Payslips</h1>
            </div>
        </div>
        <div class="topbar-right d-flex align-items-center">
            <div class="notification-wrapper">
                <button class="theme-toggler" id="notificationBell" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="badge bg-danger" id="notificationCount"></span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span>Notifications</span>
                        <a href="#" id="markAllRead">Mark all as read</a>
                    </div>
                    <div class="notification-list" id="notificationList"><div class="text-center p-3">Loading...</div></div>
                    <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
                </div>
            </div>
            <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>
            <div class="date-display" id="currentDate" style="color: #666; font-size: 0.9rem; margin-right: 15px;"></div>
            <div class="admin-profile">
                <span><?php echo htmlspecialchars($employee_user['full_name']); ?></span>
                <i class="fas fa-user-circle"></i>
            </div>
        </div>
    </div>
</div>

<div class="admin-main">

    <div class="recent-section">
        <h3>Payslip History</h3>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Payslip Number</th>
                        <th>Pay Period</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Fetches payslips for the logged-in employee where the corresponding payroll
                    // has been approved or paid by finance.
                    $payslip_stmt = $conn->prepare(
                        "SELECT p.id, p.payslip_number, p.gross_pay, p.total_deductions, p.net_pay, p.status, p.pay_period_start, p.pay_period_end, p.generated_at
                         FROM payslips p
                         JOIN payroll pr ON p.payroll_id = pr.id
                         WHERE p.employee_id = ? AND pr.status IN ('approved', 'paid')
                         ORDER BY p.pay_period_start DESC"
                    );
                    $payslip_stmt->bind_param("i", $employee_id);
                    $payslip_stmt->execute();
                    $payslip_result = $payslip_stmt->get_result();

                    if ($payslip_result->num_rows > 0) {
                        while ($row = $payslip_result->fetch_assoc()) {
                            $pay_period = date('M d, Y', strtotime($row['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($row['pay_period_end']));
                            echo "<tr>
                                <td>" . htmlspecialchars($row['payslip_number']) . "</td>
                                <td>" . htmlspecialchars($pay_period) . "</td>
                                <td>₱ " . number_format($row['gross_pay'], 2) . "</td>
                                <td>₱ " . number_format($row['total_deductions'], 2) . "</td>
                                <td>₱ " . number_format($row['net_pay'], 2) . "</td>
                                <td><span class='badge badge-" . strtolower($row['status']) . "'>" . htmlspecialchars(ucfirst($row['status'])) . "</span></td>
                                <td><a href='view_payslip.php?id=" . $row['id'] . "' class='btn btn-sm btn-info'><i class='fas fa-eye'></i> View</a></td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' class='text-center'>No payslips found.</td></tr>";
                    }
                    $payslip_stmt->close();
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .badge {
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-draft {
        background-color: #6c757d;
        color: white;
    }
    
    .badge-generated {
        background-color: #17a2b8;
        color: white;
    }
    
    .badge-sent {
        background-color: #28a745;
        color: white;
    }
    
    .badge-viewed {
        background-color: #007bff;
        color: white;
    }
</style>

<script>
    // Theme Toggler Logic
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
</script>

<?php
require_once 'footer.php';
?>