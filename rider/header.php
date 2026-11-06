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
    <title>Lechon Delight | <?php echo htmlspecialchars($page_title); ?> — Rider Portal</title>
    <!-- Favicon & Brand Logo Icons -->
    <link rel="icon" type="image/png" sizes="32x32" href="../favicon-32x32.png?v=lechon2">
    <link rel="icon" type="image/png" sizes="16x16" href="../favicon-16x16.png?v=lechon2">
    <link rel="icon" type="image/x-icon" href="../favicon.ico?v=lechon2">
    <link rel="shortcut icon" type="image/x-icon" href="../favicon.ico?v=lechon2">
    <link rel="apple-touch-icon" sizes="180x180" href="../apple-touch-icon.png?v=lechon2">
    <!-- Bootstrap 5 CSS -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        // CDN Fallback for Leaflet if unpkg is blocked or slow
        if (typeof L === 'undefined') {
            document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"><\/script>');
        }
    </script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-red: #b3261e;
            --primary-hover: #981b15;
            --page-bg: #f4f6fa;
            --card-bg: rgba(255, 255, 255, 0.82);
            --border-neutral: rgba(234, 236, 240, 0.8);
            --border-dark: #d0d5dd;
            --primary-ink: #101828;
            --muted-ink: #475467;
            --secondary-ink: #667085;
            --status-success-bg: rgba(236, 253, 243, 0.85);
            --status-success-text: #027a48;
            --status-success-border: rgba(171, 239, 198, 0.8);
            --status-warning-bg: rgba(255, 250, 235, 0.85);
            --status-warning-text: #b54708;
            --status-warning-border: rgba(254, 223, 137, 0.8);
            --status-danger-bg: rgba(255, 241, 240, 0.85);
            --status-danger-text: #b3261e;
            --status-danger-border: rgba(254, 228, 226, 0.8);
            --status-info-bg: rgba(239, 248, 255, 0.85);
            --status-info-text: #175cd3;
            --status-info-border: rgba(178, 221, 255, 0.8);

            /* Glassmorphism Design Tokens */
            --glass-bg: rgba(255, 255, 255, 0.82);
            --glass-bg-elevated: rgba(255, 255, 255, 0.90);
            --glass-bg-subtle: rgba(255, 255, 255, 0.68);
            --glass-border: rgba(255, 255, 255, 0.85);
            --glass-border-subtle: rgba(234, 236, 240, 0.7);
            --glass-shadow: 0 6px 22px -3px rgba(16, 24, 40, 0.06), 0 2px 6px -1px rgba(16, 24, 40, 0.03);
            --glass-shadow-hover: 0 12px 28px -4px rgba(16, 24, 40, 0.10), 0 4px 10px rgba(16, 24, 40, 0.04);
            --glass-dock-bg: rgba(255, 255, 255, 0.85);
            --glass-dock-border: rgba(255, 255, 255, 0.90);
            --glass-dock-shadow: 0 14px 38px -4px rgba(16, 24, 40, 0.14), 0 4px 12px -2px rgba(16, 24, 40, 0.05);
            --glass-blur-sm: 10px;
            --glass-blur-md: 18px;
            --glass-blur-lg: 24px;
        }

        /* Dark Mode Glassmorphism Support */
        body.dark-mode {
            --page-bg: #0b0f19;
            --card-bg: rgba(15, 23, 42, 0.82);
            --border-neutral: rgba(51, 65, 85, 0.7);
            --border-dark: #475569;
            --primary-ink: #f8fafc;
            --muted-ink: #94a3b8;
            --secondary-ink: #64748b;
            --glass-bg: rgba(15, 23, 42, 0.82);
            --glass-bg-elevated: rgba(30, 41, 59, 0.90);
            --glass-bg-subtle: rgba(15, 23, 42, 0.65);
            --glass-border: rgba(255, 255, 255, 0.12);
            --glass-border-subtle: rgba(255, 255, 255, 0.08);
            --glass-shadow: 0 8px 30px rgba(0, 0, 0, 0.45);
            --glass-dock-bg: rgba(15, 23, 42, 0.85);
            --glass-dock-border: rgba(255, 255, 255, 0.12);
            --glass-dock-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        /* 0ms Mobile Tap Delay Elimination */
        html, body, a, button, input, select, textarea, label, [role="button"],
        .rider-nav-item, .rider-card, .duty-switch-btn, .btn, .btn-sm {
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--page-bg);
            background-image: 
                radial-gradient(at 15% 15%, rgba(179, 38, 30, 0.04) 0px, transparent 45%),
                radial-gradient(at 85% 10%, rgba(23, 92, 211, 0.04) 0px, transparent 40%),
                radial-gradient(at 50% 50%, rgba(2, 122, 72, 0.03) 0px, transparent 50%),
                radial-gradient(at 80% 85%, rgba(179, 38, 30, 0.04) 0px, transparent 45%);
            background-attachment: fixed;
            background-size: cover;
            color: var(--primary-ink);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        body.dark-mode {
            background-image: 
                radial-gradient(at 15% 15%, rgba(179, 38, 30, 0.12) 0px, transparent 45%),
                radial-gradient(at 85% 10%, rgba(23, 92, 211, 0.10) 0px, transparent 40%),
                radial-gradient(at 80% 85%, rgba(179, 38, 30, 0.10) 0px, transparent 45%);
        }

        .rider-mobile-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            min-height: 100vh;
            background: transparent;
            position: relative;
            padding-bottom: 125px;
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

        /* Sticky Glassmorphism Top Bar */
        .rider-topbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            border-bottom: 1px solid var(--glass-border);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px -2px rgba(16, 24, 40, 0.04);
            border-radius: 0;
        }

        @media (min-width: 992px) {
            .rider-topbar {
                padding: 14px 24px;
                border-radius: 0 0 20px 20px;
                margin-bottom: 24px;
                border-left: 1px solid var(--glass-border);
                border-right: 1px solid var(--glass-border);
                border-bottom: 1px solid var(--glass-border);
                box-shadow: 0 10px 30px -4px rgba(16, 24, 40, 0.06);
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
            border-radius: 10px;
            transition: all 0.15s ease;
        }

        .rider-toplink:hover {
            color: var(--primary-ink);
            background: rgba(242, 244, 247, 0.8);
        }

        .rider-toplink.active {
            color: var(--primary-red);
            background: rgba(255, 241, 240, 0.9);
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
            width: 36px;
            height: 36px;
            background: rgba(255, 241, 240, 0.9);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--primary-red);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            border: 1px solid rgba(254, 228, 226, 0.9);
            box-shadow: 0 2px 6px rgba(179, 38, 30, 0.1);
        }

        /* Glassmorphism Cards */
        .rider-card {
            background: var(--glass-bg);
            backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 14px;
            box-shadow: var(--glass-shadow);
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease, background 0.2s ease;
        }

        .rider-card:hover {
            transform: translateY(-2px);
            background: var(--glass-bg-elevated);
            box-shadow: var(--glass-shadow-hover);
            border-color: rgba(255, 255, 255, 0.95);
        }

        /* Glassmorphic Inner Sub-Containers */
        .rider-glass-inner {
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(var(--glass-blur-sm));
            -webkit-backdrop-filter: blur(var(--glass-blur-sm));
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(16, 24, 40, 0.03);
        }

        /* Glassmorphic Badge & Icon Buttons */
        .rider-glass-badge {
            background: rgba(242, 244, 247, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #344054;
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            font-size: 11px;
            font-weight: 700;
            border-radius: 10px;
            padding: 5px 8px;
        }

        .rider-glass-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted-ink);
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            flex-shrink: 0;
        }

        .rider-glass-icon-btn:hover,
        .rider-glass-icon-btn:active {
            background: #ffffff;
            color: var(--primary-red);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
        }

        /* Glassmorphic Filter & Action Pills */
        .rider-glass-pill {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 99px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted-ink);
            box-shadow: 0 2px 6px rgba(16, 24, 40, 0.04);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            cursor: pointer;
        }

        .rider-glass-pill:hover,
        .rider-glass-pill.active {
            background: var(--primary-red);
            color: #ffffff !important;
            border-color: var(--primary-red);
            box-shadow: 0 4px 14px rgba(179, 38, 30, 0.28);
            transform: translateY(-1px);
        }

        /* Notification Cards Glassmorphism */
        .rider-notif-card {
            position: relative;
            overflow: hidden;
            margin-bottom: 11px;
            border-radius: 16px;
        }

        .rider-notif-card.is-unread {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--glass-blur-md)) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.95);
            box-shadow: 0 6px 20px -3px rgba(16, 24, 40, 0.07), 0 2px 6px -1px rgba(16, 24, 40, 0.03);
        }

        .rider-notif-card.is-unread::before {
            content: '';
            position: absolute;
            top: 12px;
            bottom: 12px;
            left: 0;
            width: 3.5px;
            border-radius: 0 4px 4px 0;
            background: var(--primary-red);
        }

        .rider-notif-card.is-read {
            background: rgba(255, 255, 255, 0.68);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.75);
            opacity: 0.92;
            box-shadow: 0 2px 8px rgba(16, 24, 40, 0.03);
        }

        .rider-notif-card.is-read:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.88);
        }

        .notif-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 2px 6px rgba(16, 24, 40, 0.04);
        }

        .notif-badge-new {
            font-size: 8.5px;
            font-weight: 800;
            letter-spacing: 0.04em;
            background: rgba(254, 228, 226, 0.9);
            color: var(--primary-red);
            border: 1px solid rgba(254, 205, 202, 0.9);
            border-radius: 12px;
            padding: 2.5px 7px;
            line-height: 1.2;
        }

        /* Frosted Glass Inputs */
        .rider-glass-input,
        .form-control.rider-glass-input {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 12px;
            padding: 10px 14px;
            box-shadow: inset 0 1px 2px rgba(16, 24, 40, 0.03);
            transition: all 0.2s ease;
        }

        .rider-glass-input:focus,
        .form-control.rider-glass-input:focus {
            background: #ffffff;
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.15);
            outline: none;
        }

        /* Glassmorphism Duty Status Switch */
        .duty-switch-container {
            display: flex;
            background: rgba(242, 244, 247, 0.72);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            padding: 5px;
            border-radius: 30px;
            border: 1px solid var(--glass-border);
            box-shadow: inset 0 1px 3px rgba(16, 24, 40, 0.04);
            margin: 12px 0 16px;
            position: relative;
        }

        .duty-switch-btn {
            flex: 1;
            padding: 9px 8px;
            border: none;
            background: transparent;
            font-weight: 700;
            font-size: 11.5px;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            border-radius: 24px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            color: var(--muted-ink);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .duty-switch-btn:hover {
            transform: translateY(-1px);
        }

        .duty-switch-btn:active {
            transform: scale(0.95);
        }

        .duty-switch-btn.active.offline {
            background: rgba(255, 255, 255, 0.95);
            color: #344054;
            box-shadow: 0 4px 12px rgba(16, 24, 40, 0.08);
        }

        .duty-switch-btn.active.online {
            background: #027a48;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(2, 122, 72, 0.35);
        }

        .duty-switch-btn.active.busy {
            background: #b3261e;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(179, 38, 30, 0.35);
        }

        /* Primary Action Buttons */
        .btn-rider-primary {
            background-color: var(--primary-red);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 14px;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 6px 18px -2px rgba(179, 38, 30, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            cursor: pointer;
            width: 100%;
            user-select: none;
        }

        .btn-rider-primary:hover, .btn-rider-primary:focus {
            background-color: var(--primary-hover);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -2px rgba(179, 38, 30, 0.45);
        }

        .btn-rider-primary:active {
            transform: translateY(1.5px) scale(0.975);
            box-shadow: 0 2px 6px rgba(179, 38, 30, 0.25);
            transition-duration: 0.08s;
        }

        .btn-rider-secondary {
            background-color: rgba(255, 255, 255, 0.85);
            color: var(--primary-ink);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 600;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
            width: 100%;
            box-shadow: 0 2px 8px rgba(16, 24, 40, 0.04);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
        }

        .btn-rider-secondary:hover {
            transform: translateY(-1.5px);
            background-color: rgba(255, 255, 255, 0.96);
            box-shadow: 0 6px 16px rgba(16, 24, 40, 0.08);
            border-color: #ffffff;
        }

        .btn-rider-secondary:active {
            transform: translateY(1px) scale(0.98);
            transition-duration: 0.08s;
        }

        .btn-rider-success {
            background-color: #027a48;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 14px;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 13.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 6px 18px -2px rgba(2, 122, 72, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            cursor: pointer;
            width: 100%;
            user-select: none;
        }

        .btn-rider-success:hover {
            background-color: #02663c;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -2px rgba(2, 122, 72, 0.45);
        }

        .btn-rider-success:active {
            transform: translateY(1.5px) scale(0.975);
            box-shadow: 0 2px 6px rgba(2, 122, 72, 0.25);
            transition-duration: 0.08s;
        }

        /* Floating Frosted Glass Bottom Navigation Dock */
        .rider-bottom-nav {
            position: fixed;
            bottom: calc(10px + env(safe-area-inset-bottom, 0px));
            left: 12px;
            right: 12px;
            max-width: 500px;
            margin: 0 auto;
            background: var(--glass-dock-bg);
            backdrop-filter: blur(var(--glass-blur-lg)) saturate(190%);
            -webkit-backdrop-filter: blur(var(--glass-blur-lg)) saturate(190%);
            border: 1px solid var(--glass-dock-border);
            border-radius: 26px;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 7px 5px calc(7px + env(safe-area-inset-bottom, 0px));
            z-index: 1050;
            box-shadow: var(--glass-dock-shadow);
            touch-action: manipulation;
        }

        @media (max-width: 360px) {
            .rider-bottom-nav {
                left: 6px;
                right: 6px;
                padding: 6px 2px calc(6px + env(safe-area-inset-bottom, 0px));
                border-radius: 20px;
            }
            .rider-nav-item {
                font-size: 9px !important;
                padding: 4px 1px !important;
            }
            .rider-nav-item i {
                font-size: 15px !important;
                margin-bottom: 1px !important;
            }
            .rider-nav-badge {
                right: calc(50% - 14px) !important;
                font-size: 7.5px !important;
                padding: 1px 3.5px !important;
            }
        }

        .rider-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #667085;
            text-decoration: none;
            font-size: 10px;
            font-weight: 600;
            padding: 6px 3px;
            border-radius: 16px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            flex: 1;
            text-align: center;
            touch-action: manipulation;
        }

        .rider-nav-item:active,
        .rider-nav-item.is-touch-active {
            transform: scale(0.92);
            background: rgba(179, 38, 30, 0.14);
            color: var(--primary-red);
        }

        .rider-nav-item i {
            font-size: 17px;
            margin-bottom: 2px;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), color 0.15s ease;
        }

        .rider-nav-item.active {
            color: var(--primary-red);
            font-weight: 700;
            background: rgba(179, 38, 30, 0.09);
        }

        .rider-nav-item.active i {
            transform: translateY(-1px) scale(1.12);
        }

        .rider-nav-badge {
            position: absolute;
            top: 1px;
            right: calc(50% - 18px);
            background: var(--primary-red);
            color: #ffffff;
            font-size: 8.5px;
            font-weight: 800;
            padding: 1.5px 5px;
            border-radius: 99px;
            border: 1.5px solid #ffffff;
            box-shadow: 0 2px 4px rgba(179, 38, 30, 0.3);
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
                <?php 
                    $header_name = !empty($rider['rider_name']) ? $rider['rider_name'] : (!empty($_SESSION['rider_name']) ? $_SESSION['rider_name'] : '');
                    if ($header_name !== ''):
                ?>
                    <span class="d-none d-lg-inline-flex align-items-center gap-1 fw-bold text-dark me-1" style="font-size: 13px;">
                        <i class="fas fa-user-circle text-muted"></i>
                        <span><?php echo htmlspecialchars($header_name); ?></span>
                    </span>
                <?php endif; ?>
                <?php if (!empty($rider['store_name'])): ?>
                    <span class="d-none d-md-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill" style="font-size:11px; font-weight:700; background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff;" title="Hiring Store Branch">
                        <i class="fas fa-store"></i>
                        <span><?php echo htmlspecialchars($rider['store_name']); ?></span>
                    </span>
                <?php endif; ?>
                <!-- Duty status badge on desktop -->
                <span class="d-none d-sm-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill" style="font-size:11px; font-weight:700; background: <?php echo $rider['duty_status'] === 'online' ? '#ecfdf3' : ($rider['duty_status'] === 'busy' ? '#fff1f0' : '#f2f4f7'); ?>; color: <?php echo $rider['duty_status'] === 'online' ? '#027a48' : ($rider['duty_status'] === 'busy' ? '#b3261e' : '#475467'); ?>; border: 1px solid <?php echo $rider['duty_status'] === 'online' ? '#abefc6' : ($rider['duty_status'] === 'busy' ? '#fee4e2' : '#d0d5dd'); ?>;">
                    <span class="pulse-dot <?php echo $rider['duty_status']; ?>" style="width:6px; height:6px;"></span>
                    <span class="text-uppercase"><?php echo htmlspecialchars($rider['duty_status']); ?></span>
                </span>
                <span class="badge rider-glass-badge">
                    <?php echo htmlspecialchars($rider['rider_code']); ?>
                </span>
                <a href="notifications.php" class="rider-glass-icon-btn position-relative" title="Notifications">
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
