<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../admin/hr_module_common.php';

$can_back_to_admin = false;
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    $can_back_to_admin = hasBackofficeAccess($conn, $_SESSION['user_id']);
}

// Check if the current user is part of logistics workforce to show logistics link.
$is_driver = false;
if (isset($_SESSION['user_id']) && function_exists('hrIsLogisticsEmployeeByUserId')) {
    $is_driver = hrIsLogisticsEmployeeByUserId($conn, (int)$_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: #c62828;
            --primary-dark: #b71c1c;
            --secondary-color: #ff9800;
            --dark-color: #333333;
            --light-color: #f8f9fa;
            --text-color: #555555;
            --border-color: #e0e0e0;
            --shadow: 0 5px 15px rgba(0,0,0,0.08);
            --shadow-hover: 0 10px 30px rgba(0,0,0,0.12);
            --transition: all 0.3s ease;
            --sidebar-width: 260px;
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body { 
            font-family: 'Poppins', sans-serif;
            background-color: #f3f4f6; 
            color: var(--text-color);
            overflow-x: hidden;
        }

        .admin-container {
            display: flex;
        }

        .admin-sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #333 0%, #1a1a1a 100%);
            color: #fff;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }

        .admin-sidebar h3 {
            padding: 20px;
            text-align: center;
            font-weight: 700;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
            color: #fff;
            font-size: 1.4rem;
        }

        .admin-sidebar a {
            padding: 15px 25px;
            text-decoration: none;
            font-size: 1rem;
            color: rgba(255,255,255,0.8);
            display: block;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .admin-sidebar a:hover, .admin-sidebar a.active {
            color: #fff;
            background-color: rgba(255,255,255,0.1);
            border-left-color: var(--primary-color);
            padding-left: 30px;
        }

        .admin-sidebar i {
            width: 30px;
            text-align: center;
            margin-right: 10px;
        }

        .admin-content {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: calc(100% - var(--sidebar-width));
        }

        .admin-topbar {
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 15px 30px;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .topbar-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }
        
        .topbar-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin: 0;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .admin-profile i {
            font-size: 1.8rem;
            color: var(--dark-color);
        }

        .admin-main {
            padding: 30px;
            flex-grow: 1;
            animation: fadeIn 0.6s ease-in-out;
        }

        .dashboard-header {
            margin-bottom: 30px;
        }

        .dashboard-header h2 {
            color: var(--dark-color);
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .dashboard-header p {
            color: #666;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative; /* For pseudo-elements if needed */
            overflow: hidden;
            text-decoration: none !important;
            color: inherit;
            border: none;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 20px;
        }

        .stat-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark-color);
        }

        .stat-content p {
            color: #888;
            margin: 0;
            font-size: 1rem;
            font-weight: 500;
        }

        .stat-value {
            margin-top: 15px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            color: var(--primary-color);
        }
        
        .stat-value i {
            margin-left: 5px;
            transition: var(--transition);
        }
        .stat-card:hover .stat-value i {
            margin-left: 10px;
        }

        /* Recent Section / Tables */
        .recent-section {
            background-color: #fff;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .recent-section h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 25px;
            color: var(--primary-color);
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .admin-table th {
            background-color: #f8f9fc;
            color: var(--dark-color);
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e3e6f0;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        .admin-table td {
            padding: 15px;
            border-bottom: 1px solid #e3e6f0;
            vertical-align: middle;
        }

        .admin-table tr:last-child td {
            border-bottom: none;
        }
        
        .admin-table tr:hover td {
            background-color: #f8f9fc;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-pending { background-color: #fff3cd; color: #856404; }
        .badge-approved { background-color: #d4edda; color: #155724; }
        .badge-rejected { background-color: #f8d7da; color: #721c24; }
        .badge-info { background-color: #d1ecf1; color: #0c5460; }
        
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

        /* Notification styles */
        .notification-wrapper {
            position: relative;
            margin-right: 10px;
        }
        #notificationBell {
            position: relative;
        }
        #notificationBell .badge {
            position: absolute;
            top: 0px;
            right: 0px;
            padding: 2px 5px;
            border-radius: 50%;
            font-size: 10px;
            display: none; /* Hidden by default */
            border: 1px solid white;
        }
        .notification-dropdown {
            position: absolute;
            top: 120%;
            right: 0;
            width: 350px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            display: none;
            z-index: 1001;
            overflow: hidden;
        }
        body.dark-mode .notification-dropdown { background-color: #2d2d2d; }
        .notification-header, .notification-footer {
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f8f9fa;
        }
        body.dark-mode .notification-header, body.dark-mode .notification-footer { background-color: #333; }
        .notification-header span { font-weight: 600; }
        .notification-header a, .notification-footer a { font-size: 12px; text-decoration: none; }
        .notification-list { max-height: 300px; overflow-y: auto; }
        .notification-item { display: flex; padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background-color 0.2s; }
        body.dark-mode .notification-item { border-bottom-color: #444; }
        .notification-item:hover { background-color: #f8f9fa; }
        body.dark-mode .notification-item:hover { background-color: #333; }
        .notification-item.unread { background-color: #e3f2fd; }
        body.dark-mode .notification-item.unread { background-color: #3a4a5a; }
        .notification-item .icon { font-size: 1.2rem; width: 40px; text-align: center; margin-right: 15px; }
        .notification-item .content .title { font-weight: 600; font-size: 14px; margin-bottom: 3px; }
        .notification-item .content .message {
            font-size: 13px;
            color: #666;
            line-height: 1.4;
            white-space: normal;
            word-wrap: break-word;
        }
        body.dark-mode .notification-item .content .message { color: #ccc; }
        .notification-item .content .time { font-size: 11px; color: #999; margin-top: 5px; }

    </style>
    <style>
        /* Dark Mode Styles */
        body.dark-mode { background-color: var(--bg-color-dark) !important; color: var(--text-color-dark) !important; }
        body.dark-mode .admin-content { background-color: var(--bg-color-dark) !important; }
        body.dark-mode .admin-sidebar { background: linear-gradient(180deg, #2d2d2d 0%, #1a1a1a 100%); }
        body.dark-mode .admin-topbar, body.dark-mode .stat-card, body.dark-mode .recent-section, body.dark-mode .card, body.dark-mode .card-body, body.dark-mode .card-header { background-color: var(--card-bg-dark) !important; color: var(--text-color-dark) !important; border-color: var(--border-color-dark) !important; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode h4, body.dark-mode .topbar-title h1, body.dark-mode .stat-content h3, body.dark-mode .admin-profile span { color: var(--text-color-dark) !important; }
        body.dark-mode .admin-table th { background-color: #333; color: #fff; border-color: var(--border-color-dark); }
        body.dark-mode .admin-table td { border-color: var(--border-color-dark); }
        body.dark-mode .admin-table tr:hover td { background-color: #3d3d3d; }
        body.dark-mode .theme-toggler { color: #ffc107; }
        body.dark-mode .admin-profile i { color: #ffc107; }
        body.dark-mode .text-muted { color: #b0b0b0 !important; }
        body.dark-mode .form-control, body.dark-mode .form-select { background-color: #333; color: #fff; border-color: #555; }
        body.dark-mode .form-control:focus, body.dark-mode .form-select:focus { background-color: #444; }
        body.dark-mode .modal-content { background-color: var(--card-bg-dark); }
        body.dark-mode .modal-header, body.dark-mode .modal-footer { border-color: var(--border-color-dark); }
        body.dark-mode .btn-close { filter: invert(1); }

        /* Page Loader */
        .page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #fff; z-index: 9999; display: flex; align-items: center; justify-content: center; transition: opacity 0.5s ease; }
        body.dark-mode .page-loader { background: var(--bg-color-dark); }
        .spinner { width: 50px; height: 50px; border: 5px solid #f3f3f3; border-top: 5px solid var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Sidebar Toggler */
        .sidebar-toggler { background: none; border: none; font-size: 1.2rem; color: #666; cursor: pointer; display: none; }
        body.dark-mode .sidebar-toggler { color: #e0e0e0; }
        @media (max-width: 992px) {
            .sidebar-toggler { display: block; }
            .admin-sidebar { left: -260px; }
            .admin-content { margin-left: 0; width: 100%; }
            .admin-container.sidebar-collapsed .admin-sidebar { left: 0; }
        }
    </style>
</head>
<body>
<div class="page-loader"><div class="spinner"></div></div>
<div class="admin-container">
    <nav class="admin-sidebar" id="adminSidebar">
        <h3>Lechon Delights</h3>
        <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> My Attendance</a>
        <a href="payslips.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'payslips.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i> My Payslips</a>
        <a href="leave_request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'leave_request.php' ? 'active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Request Leave</a>
        <?php if ($is_driver): ?>
            <a href="logistics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'logistics.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> My Deliveries</a>
        <?php endif; ?>
        <?php if ($can_back_to_admin): ?>
            <a href="../employee/dashboard.php"><i class="fas fa-user-shield"></i> Back to Dashboard</a>
        <?php endif; ?>
        <a href="../logout.php" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <div class="admin-content">
