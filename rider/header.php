<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = $page_title ?? 'Rider Portal';
$unread_notifs = 0;
if (isset($rider['id'])) {
    global $conn;
    $rn_chk = mysqli_query($conn, "SELECT COUNT(*) FROM rider_notifications WHERE rider_id = " . (int)$rider['id'] . " AND is_read = 0");
    if ($rn_chk) {
        $unread_notifs = (int)mysqli_fetch_row($rn_chk)[0];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title); ?> — Rider Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-red: #b3261e;
            --primary-hover: #981b15;
            --page-bg: #f8f9fa;
            --card-bg: #ffffff;
            --border-neutral: #eaecf0;
            --border-dark: #d0d5dd;
            --primary-ink: #101828;
            --muted-ink: #475467;
            --secondary-ink: #667085;
            --status-success-bg: #ecfdf3;
            --status-success-text: #027a48;
            --status-success-border: #abefc6;
            --status-warning-bg: #fffaeb;
            --status-warning-text: #b54708;
            --status-warning-border: #fedf89;
            --status-danger-bg: #fff1f0;
            --status-danger-text: #b3261e;
            --status-danger-border: #fee4e2;
            --status-info-bg: #eff8ff;
            --status-info-text: #175cd3;
            --status-info-border: #b2ddff;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--primary-ink);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        .rider-mobile-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            min-height: 100vh;
            background-color: var(--page-bg);
            position: relative;
            padding-bottom: 110px;
        }

        @media (min-width: 768px) {
            .rider-bottom-nav {
                display: none !important;
            }
            .rider-mobile-container {
                padding-bottom: 40px !important;
            }
        }

        @media (min-width: 992px) {
            .rider-mobile-container {
                max-width: 1200px;
                padding-left: 20px;
                padding-right: 20px;
            }
        }

        @media (min-width: 1400px) {
            .rider-mobile-container {
                max-width: 1320px;
            }
        }

        /* Top Bar */
        .rider-topbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: #ffffff;
            border-bottom: 1px solid var(--border-neutral);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            border-radius: 0;
        }

        @media (min-width: 992px) {
            .rider-topbar {
                padding: 14px 24px;
                border-radius: 0 0 16px 16px;
                margin-bottom: 24px;
                border-left: 1px solid var(--border-neutral);
                border-right: 1px solid var(--border-neutral);
            }
        }

        .rider-toplink {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted-ink);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
        }

        .rider-toplink:hover {
            color: var(--primary-ink);
            background: #f2f4f7;
        }

        .rider-toplink.active {
            color: var(--primary-red);
            background: #fff1f0;
            font-weight: 700;
        }

        .rider-topbar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 16px;
            color: var(--primary-ink);
            text-decoration: none;
        }

        .rider-topbar .brand-icon {
            width: 34px;
            height: 34px;
            background: #fff1f0;
            color: var(--primary-red);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            border: 1px solid #fee4e2;
        }

        /* Cards */
        .rider-card {
            background: #ffffff;
            border: 1px solid var(--border-neutral);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }

        /* Duty Status Switch */
        .duty-switch-container {
            display: flex;
            background: #f2f4f7;
            padding: 4px;
            border-radius: 30px;
            border: 1px solid var(--border-neutral);
            margin: 12px 0 16px;
            position: relative;
        }

        .duty-switch-btn {
            flex: 1;
            padding: 9px 8px;
            border: none;
            background: transparent;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            border-radius: 24px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--muted-ink);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .duty-switch-btn.active.offline {
            background: #ffffff;
            color: #475467;
            box-shadow: 0 2px 4px rgba(16, 24, 40, 0.08);
        }

        .duty-switch-btn.active.online {
            background: #027a48;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(2, 122, 72, 0.3);
        }

        .duty-switch-btn.active.busy {
            background: #b3261e;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(179, 38, 30, 0.3);
        }

        /* Primary Action Buttons */
        .btn-rider-primary {
            background-color: var(--primary-red);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 13px 20px;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
            text-decoration: none;
            cursor: pointer;
            width: 100%;
        }

        .btn-rider-primary:hover, .btn-rider-primary:focus {
            background-color: var(--primary-hover);
            color: #ffffff;
        }

        .btn-rider-secondary {
            background-color: #ffffff;
            color: var(--primary-ink);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
            width: 100%;
        }

        .btn-rider-success {
            background-color: #027a48;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 13px 20px;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
            width: 100%;
        }

        /* Bottom Navigation Bar */
        .rider-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid var(--border-neutral);
            display: flex;
            justify-content: space-around;
            padding: 8px 6px (max(8px, env(safe-area-inset-bottom)));
            z-index: 1050;
            box-shadow: 0 -2px 10px rgba(16, 24, 40, 0.05);
            max-width: 540px;
            margin: 0 auto;
        }

        .rider-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--muted-ink);
            text-decoration: none;
            font-size: 10.5px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 8px;
            transition: color 0.15s ease;
            position: relative;
            flex: 1;
            text-align: center;
        }

        .rider-nav-item i {
            font-size: 18px;
            margin-bottom: 3px;
        }

        .rider-nav-item.active {
            color: var(--primary-red);
            font-weight: 700;
        }

        .rider-nav-badge {
            position: absolute;
            top: 2px;
            right: 18px;
            background: var(--primary-red);
            color: #ffffff;
            font-size: 9px;
            font-weight: 800;
            padding: 2px 5px;
            border-radius: 10px;
            line-height: 1;
        }

        /* Pulse Dot */
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .pulse-dot.online {
            background-color: #12b76a;
            box-shadow: 0 0 0 3px rgba(18, 183, 106, 0.25);
            animation: pulse-green 2s infinite;
        }
        .pulse-dot.busy {
            background-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.25);
            animation: pulse-red 2s infinite;
        }
        .pulse-dot.offline {
            background-color: #98a2b3;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(18, 183, 106, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(18, 183, 106, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(18, 183, 106, 0); }
        }

        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(179, 38, 30, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(179, 38, 30, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(179, 38, 30, 0); }
        }

        /* Leaflet custom map markers */
        .rider-custom-pin {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            color: #ffffff;
            font-size: 15px;
        }
        .pin-rider { background: #b3261e; }
        .pin-store { background: #175cd3; }
        .pin-customer { background: #101828; }
    </style>
</head>
<body>
<div class="rider-mobile-container">
    <!-- Sticky Topbar with Mobile + Desktop Navigation -->
    <header class="rider-topbar">
        <div class="d-flex align-items-center gap-2 gap-md-3">
            <a href="index.php" class="brand">
                <div class="brand-icon"><i class="fas fa-motorcycle"></i></div>
                <span>RiderPortal</span>
            </a>
            <!-- Desktop Navigation Menu (hidden on mobile, visible on >= 768px) -->
            <nav class="rider-desktop-nav d-none d-md-flex align-items-center gap-1 ms-2 ms-lg-3">
                <a href="index.php" class="rider-toplink <?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home me-1"></i> Home
                </a>
                <a href="active_delivery.php" class="rider-toplink <?php echo in_array($current_page, ['active_delivery.php', 'history.php'], true) ? 'active' : ''; ?>">
                    <i class="fas fa-route me-1"></i> Deliveries
                </a>
                <a href="earnings.php" class="rider-toplink <?php echo $current_page === 'earnings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-wallet me-1"></i> Earnings
                </a>
                <a href="wallet.php" class="rider-toplink <?php echo $current_page === 'wallet.php' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave me-1"></i> COD Cash
                </a>
                <a href="profile.php" class="rider-toplink <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle me-1"></i> Profile
                </a>
                <a href="support.php" class="rider-toplink <?php echo $current_page === 'support.php' ? 'active' : ''; ?>">
                    <i class="fas fa-headset me-1"></i> Support
                </a>
            </nav>
        </div>

        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($rider)): ?>
                <!-- Duty status badge on desktop -->
                <span class="d-none d-sm-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill" style="font-size:11px; font-weight:700; background: <?php echo $rider['duty_status'] === 'online' ? '#ecfdf3' : ($rider['duty_status'] === 'busy' ? '#fff1f0' : '#f2f4f7'); ?>; color: <?php echo $rider['duty_status'] === 'online' ? '#027a48' : ($rider['duty_status'] === 'busy' ? '#b3261e' : '#475467'); ?>; border: 1px solid <?php echo $rider['duty_status'] === 'online' ? '#abefc6' : ($rider['duty_status'] === 'busy' ? '#fee4e2' : '#d0d5dd'); ?>;">
                    <span class="pulse-dot <?php echo $rider['duty_status']; ?>" style="width:6px; height:6px;"></span>
                    <span class="text-uppercase"><?php echo htmlspecialchars($rider['duty_status']); ?></span>
                </span>
                <span class="badge" style="background:#f2f4f7; color:#344054; border:1px solid #d0d5dd; font-size:11px; font-weight:700;">
                    <?php echo htmlspecialchars($rider['rider_code']); ?>
                </span>
                <a href="notifications.php" class="btn btn-sm btn-light position-relative p-2" title="Notifications" style="border-radius: 10px; border:1px solid var(--border-neutral);">
                    <i class="fas fa-bell text-muted"></i>
                    <?php if ($unread_notifs > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 8px;">
                            <?php echo $unread_notifs; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="../logout.php" class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center gap-1" title="Sign Out" style="border-radius: 10px; font-size: 12px; padding: 5px 10px;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            <?php endif; ?>
        </div>
    </header>
