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
    <title>Employee Portal | Lechon Delights</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --food-red: #b3261e;
            --food-red-dark: #8f1d17;
            --food-orange: #ef6b2e;
            --food-ink: #171922;
            --food-cream: #fff9f2;
            --food-border: #efddcd;
            --food-muted: #7b6d64;
            --food-shadow: 0 10px 28px rgba(42, 33, 29, 0.06);

            --primary-color: #b3261e;
            --primary-dark: #8f1d17;
            --secondary-color: #ef6b2e;
            --dark-color: #171922;
            --light-color: #fff9f2;
            --text-color: #2a211d;
            --border-color: #efddcd;
            --shadow: 0 8px 24px rgba(42, 33, 29, 0.06);
            --shadow-hover: 0 14px 32px rgba(42, 33, 29, 0.12);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 260px;
            --bg-color-dark: #121319;
            --text-color-dark: #e2e8f0;
            --card-bg-dark: #1e2029;
            --border-color-dark: #2d303e;
        }

        body { 
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background-color: #fcf8f4; 
            color: var(--text-color);
            overflow-x: hidden;
            line-height: 1.5;
        }

        h1, h2, h3, h4, h5, h6, .font-heading {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
        }

        .admin-container {
            display: flex;
        }

        /* Sidebar Styling */
        .admin-sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            background: #171922;
            color: #ffffff;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0,0,0,0.12);
            display: flex;
            flex-direction: column;
            border-right: 1px solid #2a2d3a;
        }

        .admin-sidebar h3 {
            padding: 24px 20px;
            text-align: left;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            margin-bottom: 10px;
            color: #ffffff;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #2a2d3a;
        }

        .admin-sidebar h3 i {
            color: var(--food-orange);
            font-size: 1.25rem;
        }

        .sidebar-nav-list {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .admin-sidebar a {
            padding: 12px 18px;
            text-decoration: none;
            font-size: 0.92rem;
            color: rgba(255,255,255,0.75);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
            border-radius: 12px;
            font-weight: 600;
        }

        .admin-sidebar a:hover {
            color: #ffffff;
            background-color: rgba(255,255,255,0.08);
            transform: translateX(3px);
        }

        .admin-sidebar a.active {
            color: #ffffff;
            background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
            box-shadow: 0 6px 16px rgba(179, 38, 30, 0.35);
        }

        .admin-sidebar i {
            width: 22px;
            text-align: center;
            font-size: 1rem;
        }

        /* Content & Topbar */
        .admin-content {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: calc(100% - var(--sidebar-width));
            background-color: #fcf8f4;
        }

        .admin-topbar {
            background-color: #ffffff;
            border-bottom: 1px solid var(--food-border);
            box-shadow: 0 4px 16px rgba(42, 33, 29, 0.03);
            padding: 14px 32px;
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
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--food-ink);
            margin: 0;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: var(--food-ink);
            font-size: 0.92rem;
            background: #fff9f2;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid var(--food-border);
        }

        .admin-profile i {
            font-size: 1.25rem;
            color: var(--food-red);
        }

        .admin-main {
            padding: 32px;
            flex-grow: 1;
            animation: fadeIn 0.4s ease-out;
        }

        .dashboard-header {
            margin-bottom: 28px;
        }

        .dashboard-header h2 {
            font-family: 'Outfit', sans-serif;
            color: var(--food-ink);
            font-weight: 800;
            margin-bottom: 6px;
            font-size: 1.75rem;
        }
        
        .dashboard-header p {
            color: var(--food-muted);
            margin: 0;
            font-size: 0.95rem;
        }

        /* Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 22px;
            border: 1px solid var(--food-border);
            box-shadow: var(--food-shadow);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            text-decoration: none !important;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: var(--food-red);
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 16px;
        }

        .stat-content h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 4px;
            color: var(--food-ink);
        }

        .stat-content p {
            color: var(--food-muted);
            margin: 0;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .stat-value {
            margin-top: 16px;
            font-weight: 700;
            font-size: 0.86rem;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--food-red);
        }
        
        .stat-value i {
            transition: var(--transition);
            font-size: 0.8rem;
        }
        .stat-card:hover .stat-value i {
            transform: translateX(4px);
        }

        /* Recent Section / Tables */
        .recent-section {
            background-color: #ffffff;
            border-radius: 20px;
            padding: 28px;
            border: 1px solid var(--food-border);
            box-shadow: var(--food-shadow);
            margin-bottom: 32px;
        }

        .recent-section h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--food-ink);
            border-bottom: 1px solid var(--food-border);
            padding-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .admin-table th {
            background-color: #fffdfb;
            color: var(--food-ink);
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--food-border);
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: 0.05em;
        }

        .admin-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3e8de;
            vertical-align: middle;
            font-size: 0.92rem;
            color: #2a211d;
        }

        .admin-table tr:last-child td {
            border-bottom: none;
        }
        
        .admin-table tr:hover td {
            background-color: #fff9f2;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-pending { background-color: #fff8ef; color: #b45309; border: 1px solid #fef3c7; }
        .badge-approved { background-color: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-rejected { background-color: #fff1f2; color: #b3261e; border: 1px solid #fecdd3; }
        .badge-info { background-color: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }
        
        .theme-toggler {
            background: #fff9f2;
            border: 1px solid var(--food-border);
            color: var(--food-ink);
            font-size: 1rem;
            cursor: pointer;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .theme-toggler:hover {
            background: var(--food-cream);
            color: var(--food-red);
        }

        /* Notification styles */
        .notification-wrapper {
            position: relative;
        }
        #notificationBell {
            position: relative;
        }
        #notificationBell .badge {
            position: absolute;
            top: -3px;
            right: -3px;
            padding: 3px 6px;
            border-radius: 50%;
            font-size: 10px;
            display: none;
            border: 2px solid white;
            background-color: var(--food-red) !important;
        }
        .notification-dropdown {
            position: absolute;
            top: 120%;
            right: 0;
            width: 350px;
            background-color: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--food-border);
            box-shadow: var(--shadow-hover);
            display: none;
            z-index: 1001;
            overflow: hidden;
        }
        body.dark-mode .notification-dropdown { background-color: #1e2029; border-color: var(--border-color-dark); }
        .notification-header, .notification-footer {
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #fff9f2;
            border-bottom: 1px solid var(--food-border);
        }
        body.dark-mode .notification-header, body.dark-mode .notification-footer { background-color: #272a37; border-color: var(--border-color-dark); }
        .notification-header span { font-weight: 700; font-size: 0.92rem; color: var(--food-ink); }
        .notification-header a, .notification-footer a { font-size: 12px; text-decoration: none; color: var(--food-red); font-weight: 700; }
        .notification-list { max-height: 300px; overflow-y: auto; }
        .notification-item { display: flex; padding: 15px; border-bottom: 1px solid #f3e8de; cursor: pointer; transition: background-color 0.2s; }
        body.dark-mode .notification-item { border-bottom-color: #2d303e; }
        .notification-item:hover { background-color: #fff9f2; }
        body.dark-mode .notification-item:hover { background-color: #272a37; }
        .notification-item.unread { background-color: #fff5ea; }
        body.dark-mode .notification-item.unread { background-color: #2d303e; }
        .notification-item .icon { font-size: 1.2rem; width: 40px; text-align: center; margin-right: 15px; color: var(--food-red); }
        .notification-item .content .title { font-weight: 700; font-size: 14px; margin-bottom: 3px; color: var(--food-ink); }
        .notification-item .content .message {
            font-size: 13px;
            color: var(--food-muted);
            line-height: 1.4;
            white-space: normal;
            word-wrap: break-word;
        }
        body.dark-mode .notification-item .content .message { color: #cbd5e1; }
        .notification-item .content .time { font-size: 11px; color: #999; margin-top: 5px; }

        /* Dark Mode Styles */
        body.dark-mode { background-color: var(--bg-color-dark) !important; color: var(--text-color-dark) !important; }
        body.dark-mode .admin-content { background-color: var(--bg-color-dark) !important; }
        body.dark-mode .admin-sidebar { background: #121319; border-color: #272a37; }
        body.dark-mode .admin-topbar, body.dark-mode .stat-card, body.dark-mode .recent-section, body.dark-mode .card, body.dark-mode .card-body, body.dark-mode .card-header { background-color: var(--card-bg-dark) !important; color: var(--text-color-dark) !important; border-color: var(--border-color-dark) !important; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode h4, body.dark-mode .topbar-title h1, body.dark-mode .stat-content h3, body.dark-mode .admin-profile span { color: var(--text-color-dark) !important; }
        body.dark-mode .admin-profile { background: #272a37; border-color: #373a4b; color: #fff; }
        body.dark-mode .admin-table th { background-color: #272a37; color: #fff; border-color: var(--border-color-dark); }
        body.dark-mode .admin-table td { border-color: var(--border-color-dark); color: #e2e8f0; }
        body.dark-mode .admin-table tr:hover td { background-color: #272a37; }
        body.dark-mode .theme-toggler { background: #272a37; color: #ffc107; border-color: #373a4b; }

        /* Page Loader */
        .page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #ffffff; z-index: 9999; display: flex; align-items: center; justify-content: center; transition: opacity 0.5s ease; }
        body.dark-mode .page-loader { background: var(--bg-color-dark); }
        .spinner { width: 44px; height: 44px; border: 4px solid #f3e8de; border-top: 4px solid var(--food-red); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Sidebar Toggler */
        .sidebar-toggler { background: #fff9f2; border: 1px solid var(--food-border); font-size: 1.1rem; color: var(--food-ink); cursor: pointer; display: none; width: 38px; height: 38px; border-radius: 12px; align-items: center; justify-content: center; margin-right: 12px; }
        body.dark-mode .sidebar-toggler { background: #272a37; border-color: #373a4b; color: #e2e8f0; }
        @media (max-width: 992px) {
            .sidebar-toggler { display: flex; }
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
        <h3><i class="fas fa-drumstick-bite"></i> Lechon Delights</h3>
        <div class="sidebar-nav-list">
            <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-gauge-high"></i> Dashboard</a>
            <a href="attendance.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> My Attendance</a>
            <a href="payslips.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'payslips.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i> My Payslips</a>
            <a href="leave_request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'leave_request.php' ? 'active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Request Leave</a>
            <?php if ($is_driver): ?>
                <a href="logistics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'logistics.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> My Deliveries</a>
            <?php endif; ?>
            <?php if ($can_back_to_admin): ?>
                <a href="../admin/index.php"><i class="fas fa-user-shield"></i> Back to Admin</a>
            <?php endif; ?>
            <a href="../logout.php" id="logoutBtn" style="margin-top:auto; color:#ef4444;"><i class="fas fa-right-from-bracket"></i> Logout</a>
        </div>
    </nav>

    <div class="admin-content">
