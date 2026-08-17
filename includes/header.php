<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($current_page)) $current_page = basename($_SERVER['PHP_SELF'], '.php');

$page_title = $page_title ?? 'Lechon Delights';
$script_parent = basename(dirname($_SERVER['PHP_SELF']));
$path_prefix = ($script_parent === 'admin') ? '../' : '';
if (!isset($google_maps_api_key) || trim((string)$google_maps_api_key) === '') {
    $google_maps_api_key = function_exists('getGoogleMapsApiKey')
        ? getGoogleMapsApiKey()
        : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
}
$google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

$normalized_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$is_logged_in_user = !empty($_SESSION['user_id']);
$is_customer_user = $is_logged_in_user && ($normalized_user_type === '' || $normalized_user_type === 'customer' || $normalized_user_type === 'user');
$auth_pages = ['login', 'register', 'reset_password', 'reset_password_request'];
$is_auth_page = in_array($current_page, $auth_pages, true);
$market_header_excluded_pages = ['login', 'register', 'reset_password', 'reset_password_request'];
$is_market_home_header = ($script_parent !== 'admin') && !in_array($current_page, $market_header_excluded_pages, true);
$show_market_header_bottom = in_array($current_page, ['home', 'index', 'shops'], true);
$cart_count = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;

$current_user_address = trim((string)($current_user_address ?? ($_SESSION['address'] ?? '')));
$market_header_address_display = $current_user_address !== '' ? $current_user_address : 'Select your address';

$viewer_name = trim((string)($_SESSION['full_name'] ?? ''));
$viewer_email = trim((string)($_SESSION['email'] ?? ''));
$viewer_first_name = $viewer_name !== '' ? explode(' ', $viewer_name)[0] : 'Welcome';
$viewer_profile_image = trim((string)($_SESSION['profile_image'] ?? ''));
$viewer_profile_image = str_replace('\\', '/', $viewer_profile_image);
if (!preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $viewer_profile_image)) {
    $viewer_profile_image = '';
}
if ($viewer_profile_image !== '' && !is_file(__DIR__ . '/../' . $viewer_profile_image)) {
    $viewer_profile_image = '';
}
$viewer_profile_image_url = $viewer_profile_image !== '' ? $path_prefix . ltrim($viewer_profile_image, '/') : '';
$storefront_seller_id = isset($_SESSION['storefront_seller_id']) ? (int)$_SESSION['storefront_seller_id'] : 0;
$preorder_nav_href = $path_prefix . 'preorder.php' . ($storefront_seller_id > 0 ? '?seller_id=' . $storefront_seller_id : '');
$favorites_page_href = $path_prefix . 'favorites.php';
$favorites_api_href = $path_prefix . 'api/favorites.php';
$favorites_feature_enabled = $is_customer_user;

$ongoing_order_id = 0;
$ongoing_order_number = '';
$ongoing_order_status = '';

if ($is_logged_in_user && isset($conn) && $conn instanceof mysqli) {
    $current_user_id = (int)$_SESSION['user_id'];
    $ongoing_query = "SELECT o.id, o.order_number, o.status, lt.current_status as tracking_status 
                      FROM orders o 
                      LEFT JOIN logistics_tracking lt ON o.id = lt.order_id 
                      WHERE o.user_id = ? 
                        AND o.status NOT IN ('delivered', 'cancelled', 'completed', 'rejected') 
                        AND (o.is_archived IS NULL OR o.is_archived = 0)
                      ORDER BY o.id DESC LIMIT 1";
    $ongoing_stmt = $conn->prepare($ongoing_query);
    if ($ongoing_stmt) {
        $ongoing_stmt->bind_param("i", $current_user_id);
        $ongoing_stmt->execute();
        $ongoing_res = $ongoing_stmt->get_result();
        if ($ongoing_row = $ongoing_res->fetch_assoc()) {
            $ongoing_order_id = (int)$ongoing_row['id'];
            $ongoing_order_number = (string)$ongoing_row['order_number'];
            $ongoing_order_status = (string)($ongoing_row['tracking_status'] ?: $ongoing_row['status']);
        }
        $ongoing_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . ' | Lechon Delights'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --ink:#171922; --muted:#667085; --line:#efddcd; --rose:#b3261e; --bg:#f8f9fa; --card:#fff; --shadow:0 12px 30px rgba(15,23,42,.1); --primary-color:#b3261e; --primary-dark:#8f261a; --motion-ease:cubic-bezier(.22,1,.36,1); --motion-fast:.22s; --motion-base:.28s; --transition-fast:all var(--motion-fast) var(--motion-ease); --transition-fade:opacity var(--motion-fast) var(--motion-ease), visibility var(--motion-fast) var(--motion-ease), transform var(--motion-fast) var(--motion-ease); --transition-lift:transform var(--motion-fast) var(--motion-ease), box-shadow var(--motion-fast) var(--motion-ease), border-color var(--motion-fast) var(--motion-ease), background-color var(--motion-fast) var(--motion-ease), color var(--motion-fast) var(--motion-ease); }
        * { box-sizing:border-box; }
        html, body { margin:0; padding:0; overflow-x:clip !important; max-width:100vw; width:100%; font-family:"Plus Jakarta Sans","Segoe UI",sans-serif; background:var(--bg); color:var(--ink); }
        h1,h2,h3,h4,h5,h6 { font-family:"Outfit","Plus Jakarta Sans",sans-serif; }
        .site-main { min-height:calc(100vh - 260px); }
        .site-header { position:sticky; top:0; z-index:1200; background:#ffffff; border-bottom:1px solid var(--line); box-shadow:0 6px 20px rgba(15,23,42,.04); width:100%; }
        .header-shell { max-width:1320px; margin:0 auto; padding:0 22px; width:100%; }
        .logo-link { display:inline-flex; gap:10px; align-items:center; text-decoration:none; flex-shrink:0; }
        .logo-icon { width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; color:#fff; background:linear-gradient(135deg,#b3261e,#ef6b2e); box-shadow:0 6px 16px rgba(179,38,30,.2); font-size:.95rem; }
        .logo-copy { display:grid; gap:1px; }
        .logo-title { font-size:1.15rem; font-weight:800; color:var(--ink); line-height:1; }
        .logo-sub { font-size:.65rem; text-transform:uppercase; letter-spacing:.12em; color:#7f879a; font-weight:700; }
        .header-actions,.auth-buttons,.main-nav,.market-home-nav { display:flex; align-items:center; gap:9px; flex-shrink:0; }
        .auth-buttons { gap:10px; display:flex !important; }
        .btn-signin,.btn-register { min-height:38px; padding:0 16px; border-radius:999px; border:1px solid; text-decoration:none; font-weight:700; font-size:.84rem; display:inline-flex; align-items:center; justify-content:center; transition:var(--transition-fast); white-space:nowrap; }
        .btn-signin { border-color:#d0d5dd; color:#344054; background:#ffffff; }
        .btn-signin:hover { background:#f8fafc; border-color:#98a2b3; color:#101828; }
        .btn-register { border-color:#b3261e; background:#b3261e; color:#ffffff; }
        .btn-register:hover { background:#981b15; border-color:#981b15; color:#ffffff; }
        .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--line); background:#fff; color:#222a3d; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; transition:var(--transition-fast); position:relative; font-size:.9rem; }
        .icon-btn:hover { background:var(--ink); color:#fff; border-color:var(--ink); }
        .user-avatar-btn { padding:0; overflow:hidden; }
        .user-avatar-thumb { width:100%; height:100%; object-fit:cover; border-radius:10px; display:block; }
        .badge { position:absolute; top:-5px; right:-5px; min-width:19px; height:19px; border-radius:999px; background:var(--rose); color:#fff; border:2px solid #fff; font-size:.68rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; padding:0 5px; }
        .standard-top { min-height:64px; display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .nav-link,.market-home-link { text-decoration:none; color:#56617a; font-size:.88rem; font-weight:700; min-height:38px; padding:0 12px; border-radius:999px; border:1px solid transparent; display:inline-flex; gap:6px; align-items:center; transition:var(--transition-fast); }
        .nav-link:hover,.nav-link.active,.market-home-link:hover,.market-home-link.active { background:#fff4e8; border-color:var(--line); color:var(--ink); }
        .user-menu-wrapper,.notification-wrapper { position:relative; }
        .user-dropdown,.notification-dropdown { position:absolute; top:calc(100% + 10px); right:0; min-width:260px; background:#fff; border:1px solid var(--line); border-radius:16px; box-shadow:var(--shadow); padding:8px 0; opacity:0; visibility:hidden; transform:translateY(8px); transition:var(--transition-fade); z-index:1500 !important; }
        .notification-dropdown { min-width:310px; }
        .user-menu-wrapper:hover .user-dropdown,.notification-wrapper:hover .notification-dropdown { opacity:1; visibility:visible; transform:translateY(0); }
        .user-dropdown-header { padding:4px 15px 11px; border-bottom:1px solid var(--line); margin-bottom:8px; }
        .user-name { font-size:1rem; font-weight:800; }
        .user-email { font-size:.84rem; color:var(--muted); }
        .user-dropdown-item { padding:10px 15px; display:flex; align-items:center; gap:10px; color:#2b3144; text-decoration:none; font-size:.9rem; font-weight:600; }
        .user-dropdown-item:hover { background:#f7f8fb; }
        .user-dropdown-item.logout-btn { border-top:1px solid var(--line); color:#b4233c; margin-top:6px; }
        .notification-header { padding:10px 13px; border-bottom:1px solid var(--line); font-weight:800; font-size:.9rem; }
        .notification-empty { padding:18px 13px; font-size:.9rem; color:var(--muted); }
        .mobile-toggle { display:none !important; }

        /* Header Active Order Pill */
        .header-ongoing-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #b3261e, #ef6b2e);
            color: #ffffff !important;
            padding: 0 14px;
            min-height: 38px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.84rem;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(179, 38, 30, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            white-space: nowrap;
            position: relative;
        }
        .header-ongoing-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(179, 38, 30, 0.35);
        }
        .ongoing-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7);
            animation: ongoingPulseDot 1.4s infinite;
        }
        @keyframes ongoingPulseDot {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(255, 255, 255, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
        }

        /* Floating Sticky Bottom Right Ongoing Order Banner */
        .sticky-ongoing-widget {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1100;
            max-width: 420px;
            width: calc(100vw - 32px);
            animation: stickySlideUp 0.4s cubic-bezier(0.22, 1, 0.36, 1);
        }
        @keyframes stickySlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sticky-ongoing-link {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #ffffff;
            border: 1px solid #efddcd;
            border-radius: 18px;
            padding: 14px 18px;
            box-shadow: 0 14px 34px rgba(23, 25, 34, 0.12);
            text-decoration: none;
            color: #171922;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }
        .sticky-ongoing-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 40px rgba(179, 38, 30, 0.15);
            border-color: #b3261e;
        }
        .sticky-ongoing-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(135deg, #b3261e, #ef6b2e);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(179, 38, 30, 0.25);
        }
        .sticky-pulse-ring {
            position: absolute;
            inset: -3px;
            border-radius: 16px;
            border: 2px solid #ef6b2e;
            animation: stickyPulse 1.8s infinite;
            pointer-events: none;
        }
        @keyframes stickyPulse {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(1.18); opacity: 0; }
        }
        .sticky-ongoing-info { flex: 1; min-width: 0; }
        .sticky-ongoing-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
        .sticky-live-tag {
            font-size: 0.68rem;
            font-weight: 800;
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
            padding: 2px 7px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }
        .sticky-ongoing-status {
            font-size: 0.86rem;
            color: #667085;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sticky-ongoing-btn {
            background: #fff4e8;
            color: #b3261e;
            border: 1px solid #efddcd;
            padding: 8px 14px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.84rem;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .sticky-ongoing-link:hover .sticky-ongoing-btn {
            background: #b3261e;
            color: #ffffff;
            border-color: #b3261e;
        }
        .mobile-menu-overlay { position:fixed; inset:0; background:rgba(14,20,31,.48); z-index:1290; opacity:0; visibility:hidden; transition:opacity var(--motion-fast) var(--motion-ease), visibility var(--motion-fast) var(--motion-ease); }
        .mobile-menu-overlay.active { opacity:1; visibility:visible; }
        .mobile-menu { position:fixed; top:0; right:-360px; width:min(340px,92vw); height:100vh; background:#fff; border-left:1px solid var(--line); box-shadow:-14px 0 34px rgba(15,23,42,.14); z-index:1300; transition:right var(--motion-base) var(--motion-ease); display:flex; flex-direction:column; }
        .mobile-menu.active { right:0; }
        .mobile-menu-header { padding:16px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; }
        .mobile-menu-close { width:38px; height:38px; border-radius:11px; border:1px solid var(--line); background:#fff; cursor:pointer; transition:var(--transition-fast); }
        .mobile-menu-close:hover { background:#fff4e8; border-color:#e3c8ad; }
        .mobile-nav { margin:0; padding:12px; list-style:none; display:grid; gap:4px; }
        .mobile-nav a { text-decoration:none; color:#2b3144; font-weight:700; display:flex; gap:10px; align-items:center; padding:10px 12px; border-radius:12px; transition:var(--transition-fast); }
        .mobile-nav a:hover,.mobile-nav a.active { background:#fff4e8; color:var(--ink); }
        /* Custom Lightweight Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f8fafc;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
            border: 2px solid #f8fafc;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        /* Smooth Foodpanda Dark Backdrop Overlay */
        .market-search-backdrop {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            top: 112px;
            background: rgba(30, 35, 45, 0.55);
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s cubic-bezier(.22, 1, .36, 1), visibility 0.3s;
            pointer-events: none;
        }
        .market-search-backdrop.is-active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .site-header {
            position: sticky;
            top: 0;
            z-index: 1200;
            background: #ffffff !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
            width: 100%;
        }

        .market-header-top, .market-header-bottom { max-width:1280px; margin:0 auto; padding:0 24px; width:100%; box-sizing:border-box; }
        .market-header-top { min-height:64px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:nowrap; padding-top:10px; padding-bottom:10px; }
        .market-header-bottom { min-height:48px; border-top:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:nowrap; padding-top:8px; padding-bottom:8px; position:relative; }
        
        .market-home-nav {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-shrink: 0;
            opacity: 1;
            visibility: visible;
            max-width: 360px;
            overflow: hidden;
            transition: opacity 0.3s cubic-bezier(.22, 1, .36, 1), max-width 0.35s cubic-bezier(.22, 1, .36, 1), visibility 0.3s, margin 0.3s;
        }

        .search-active .market-home-nav,
        .market-header-bottom.search-active .market-home-nav,
        .market-home-search-wrap.is-open ~ .market-home-nav {
            opacity: 0 !important;
            visibility: hidden !important;
            max-width: 0px !important;
            margin-right: 0 !important;
            padding-right: 0 !important;
            pointer-events: none !important;
        }

        .market-home-search-wrap {
            flex: 0 1 380px;
            max-width: 400px;
            min-width: 180px;
            position: relative;
            z-index: 1200;
            transition: flex 0.35s cubic-bezier(.22, 1, .36, 1), max-width 0.35s cubic-bezier(.22, 1, .36, 1), width 0.35s cubic-bezier(.22, 1, .36, 1);
        }

        .search-active .market-home-search-wrap,
        .market-home-search-wrap.is-open {
            flex: 1 1 100% !important;
            max-width: 100% !important;
            width: 100% !important;
        }

        .market-home-search {
            width: 100%;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            background: #f4f5f8;
            border: 1px solid #dcdfe6;
            border-radius: 999px;
            padding: 0 16px;
            box-shadow: 0 2px 6px rgba(15,23,42,.03);
            transition: border-color 0.3s ease, background-color 0.3s ease, box-shadow 0.3s ease;
        }
        .market-home-search i {
            color: #6b7280;
            font-size: .95rem;
            transition: transform 0.3s ease, color 0.3s ease;
        }
        .market-home-search-wrap.is-open .market-home-search i {
            color: var(--rose);
            transform: scale(1.1);
        }
        .market-home-search-wrap.is-open .market-home-search {
            border-color: var(--rose);
            box-shadow: 0 8px 28px rgba(179,38,30,.18);
            background: #fff;
        }
        .market-home-search input { width:100%; border:none; background:transparent; outline:none; font-size:.92rem; color:#1f2333; }
        .market-address-wrap {
            position: relative;
            display: inline-block;
        }

        .market-address-popover {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            width: min(480px, 92vw);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18);
            padding: 20px 22px;
            z-index: 1250;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px) scale(0.98);
            transition: opacity 0.28s cubic-bezier(.22, 1, .36, 1), transform 0.28s cubic-bezier(.22, 1, .36, 1), visibility 0.28s;
        }

        .market-address-wrap.is-open .market-address-popover {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .market-address-input-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .market-address-field-wrap {
            flex: 1;
            position: relative;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 8px 110px 8px 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .market-address-field-wrap:focus-within {
            border-color: #b3261e;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            background: #ffffff;
        }

        .market-address-field-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 2px;
        }

        .market-address-field-input {
            width: 100%;
            border: none;
            background: transparent;
            outline: none;
            font-size: 0.92rem;
            font-weight: 600;
            color: #1e293b;
        }

        .market-locate-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #b3261e;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .market-locate-btn:hover {
            background: rgba(179, 38, 30, 0.08);
        }

        .market-address-submit-btn {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, #b3261e, #ef6b2e);
            color: #ffffff;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 14px rgba(179, 38, 30, 0.25);
            flex-shrink: 0;
        }

        .market-address-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(179, 38, 30, 0.35);
        }

        .market-address-suggestions-section {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .market-address-suggestions-title {
            margin: 0 0 10px;
            font-weight: 800;
            font-size: 0.95rem;
            color: #0f172a;
        }

        .market-address-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .market-address-pill {
            appearance: none;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .market-address-pill:hover {
            border-color: #b3261e;
            color: #b3261e;
            background: #fff5f5;
            transform: translateY(-1px);
        }

        .market-address-trigger { border:1px solid var(--line); background:#fff7ef; border-radius:999px; min-height:38px; padding:0 12px; color:#2a3042; display:inline-flex; align-items:center; gap:7px; cursor:pointer; font-size:.84rem; font-weight:600; max-width:260px; transition:var(--transition-lift); flex-shrink:0; }
        .market-partner-cta { min-height:38px; padding:0 14px; border-radius:999px; border:1px solid #f0c4a8; background:linear-gradient(135deg,#171922,#2b3144); color:#fff; text-decoration:none; font-size:.82rem; font-weight:800; display:inline-flex; align-items:center; gap:8px; transition:var(--transition-lift); white-space:nowrap; }
        .market-search-popover {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            right: 0;
            width: 100%;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.16);
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px) scale(0.98);
            transition: opacity 0.28s cubic-bezier(.22,1,.36,1), transform 0.28s cubic-bezier(.22,1,.36,1), visibility 0.28s;
            z-index: 1250;
        }
        .market-home-search-wrap.is-open .market-search-popover { opacity:1; visibility:visible; transform:translateY(0) scale(1); }
        .market-search-tabs { display:flex; gap:4px; padding:12px 14px 0; border-bottom:1px solid #e5e7eb; }
        .market-search-tab { appearance:none; border:none; background:transparent; color:#4b5563; font-weight:700; font-size:.94rem; padding:10px 8px 12px; border-bottom:3px solid transparent; cursor:pointer; transition:var(--transition-fast); }
        .market-search-tab:hover { color:#111827; }
        .market-search-tab.active { color:#111827; border-bottom-color:#111827; }
        .market-search-panel { display:none; padding:14px; }
        .market-search-panel.active { display:block; }
        .market-search-title { margin:0 0 10px; font-size:1.05rem; color:#111827; font-weight:800; }
        .market-search-suggestions { display:flex; gap:8px; flex-wrap:wrap; }
        .market-search-suggestion { appearance:none; border:1px solid #e5e7eb; background:#f9fafb; color:#374151; border-radius:999px; padding:7px 12px; font-size:.86rem; font-weight:700; cursor:pointer; transition:var(--transition-fast); }
        .market-search-suggestion:hover { border-color:#cbd5e1; background:#fff; color:#111827; }
        .map-modal-overlay { position:fixed; inset:0; background:rgba(8,11,18,.5); z-index:1600; display:none; align-items:center; justify-content:center; padding:16px; overflow-y:auto; }
        .map-modal-overlay.active { display:flex; }
        .map-modal { width:min(720px, 96vw); max-height:min(92vh, 92dvh); background:#fff; border-radius:18px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 30px 80px rgba(16,24,40,.35); border:1px solid #e7d9cc; margin:auto; }
        .map-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:18px 18px 10px; }
        .map-modal-title { margin:0; font-size:1.6rem; font-weight:800; color:#1f2937; }
        .map-modal-sub { margin:4px 0 0; color:#6b7280; font-size:.95rem; }
        .map-modal-close { border:none; background:transparent; width:36px; height:36px; border-radius:10px; color:#4b5563; cursor:pointer; font-size:1.2rem; }
        .map-modal-close:hover { background:#f3f4f6; color:#111827; }
        .map-modal-body { padding:0 18px 16px; overflow:auto; }
        .map-modal-input-wrap { position:relative; margin-bottom:12px; }
        .map-modal-input { width:100%; min-height:48px; border-radius:12px; border:1px solid #d8dee8; padding:0 44px 0 14px; font-size:.95rem; background:#fff; }
        .map-modal-input:focus { outline:none; border-color:#0284c7; box-shadow:0 0 0 2px rgba(2,132,199,.15); }
        .map-modal-search-btn { position:absolute; right:7px; top:50%; transform:translateY(-50%); width:34px; height:34px; border:none; background:#fff; border-radius:99px; color:#6b7280; cursor:pointer; }
        .map-modal-search-btn:hover { background:#f3f4f6; color:#111827; }
        .map-modal-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(160px,.5fr); gap:10px; margin-bottom:12px; }
        .map-modal-map { height:min(420px, 52vh); border-radius:14px; border:1px solid #d8dee8; overflow:hidden; background:#f8fafc; }
        .map-modal-foot { border-top:1px solid #eef2f6; padding:14px 18px 18px; display:flex; justify-content:flex-end; gap:10px; }
        .map-modal-btn { min-height:48px; border:none; border-radius:12px; font-weight:800; padding:0 22px; cursor:pointer; transition:var(--transition-fast); }
        .map-modal-btn.secondary { background:#f3f4f6; color:#111827; }
        .map-modal-btn.primary { background:linear-gradient(135deg,#e11d8f,#db2777); color:#fff; }
        .map-modal-btn.secondary:hover { background:#e5e7eb; }
        .map-modal-btn.primary:hover { filter:brightness(.96); transform:translateY(-1px); }
        @media (max-width:768px){ .mobile-toggle{display:inline-flex !important;} .main-nav{display:none;} .header-shell,.market-header-top,.market-header-bottom{padding-left:14px;padding-right:14px;} .market-header-top{flex-wrap:wrap;} .market-home-search-wrap{order:3;flex-basis:100%;min-width:100%;margin-top:4px;} .btn-signin,.btn-register{padding:0 10px;font-size:.78rem;min-height:34px;} }
        @media (max-width:480px){ .logo-title{font-size:1.02rem;} .logo-sub{font-size:.6rem;} }
    </style>
    <!-- Leaflet Map CSS and JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body class="<?php echo $is_market_home_header ? 'market-body' : 'site-body'; ?>">
<?php if ($is_market_home_header): ?>
<header class="site-header market-main-header">
    <div class="market-header-top">
        <a href="<?php echo $path_prefix; ?>index.php" class="logo-link">
            <span class="logo-icon"><img src="<?php echo $path_prefix; ?>assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block;"></span>
            <span class="logo-copy"><span class="logo-title">Lechon Delights</span><span class="logo-sub">Marketplace</span></span>
        </a>

        <?php if ($current_page !== 'register' && $current_page !== 'login' && $current_page !== 'checkout' && $current_page !== 'franchise_application'): ?>
        <div class="market-address-wrap" id="marketAddressWrap">
            <button type="button" class="market-address-trigger" id="marketAddressToggle">
                <i class="fas fa-location-dot"></i><span class="address-text" id="marketAddressDisplay"><?php echo htmlspecialchars($market_header_address_display); ?></span><i class="fas fa-chevron-down"></i>
            </button>
            <div class="market-address-popover" id="marketAddressPopover">
                <div class="market-address-input-row">
                    <div class="market-address-field-wrap">
                        <label class="market-address-field-label" for="marketQuickStreetInput">Enter your address</label>
                        <input type="text" id="marketQuickStreetInput" class="market-address-field-input" placeholder="Street, Postal Code" value="<?php echo htmlspecialchars($market_saved_address['street_address'] ?? ''); ?>" autocomplete="off">
                        <button type="button" class="market-locate-btn" id="marketLocateMeBtn" title="Locate me">
                            <i class="fas fa-crosshairs"></i> Locate me
                        </button>
                    </div>
                    <button type="button" class="market-address-submit-btn" id="marketQuickAddressSubmitBtn" aria-label="Apply address">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <div class="market-address-suggestions-section">
                    <p class="market-address-suggestions-title">Popular locations</p>
                    <div class="market-address-pills">
                        <button type="button" class="market-address-pill" data-city="General Trias" data-street="General Trias, Cavite">General Trias</button>
                        <button type="button" class="market-address-pill" data-city="Dasmarinas" data-street="Dasmariñas, Cavite">Dasmariñas</button>
                        <button type="button" class="market-address-pill" data-city="Imus" data-street="Imus, Cavite">Imus</button>
                        <button type="button" class="market-address-pill" data-city="Bacoor" data-street="Bacoor, Cavite">Bacoor</button>
                        <button type="button" class="market-address-pill" data-city="Tagaytay" data-street="Tagaytay, Cavite">Tagaytay</button>
                    </div>
                </div>
                <!-- Header Location Pinning Map -->
                <div style="padding: 0 16px 14px 16px;">
                    <div id="headerMap" style="height: 180px; width: 100%; border-radius: 8px; border: 1px solid #efddcd; z-index: 1;"></div>
                    <p style="font-size: 0.72rem; color: #7b6d64; margin: 4px 0 10px 0; text-align: left; font-weight: 500;">Drag the pin or click on the map to set your location.</p>
                    <button type="button" id="headerSaveLocationBtn" style="width: 100%; background: #b3261e; color: #fff; font-weight: 800; font-size: 14px; border: none; border-radius: 10px; padding: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(179,38,30,0.25);">
                        <i class="fas fa-check-circle"></i> Save Location
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="header-actions">
            <?php if ($is_logged_in_user): ?>
            <div class="user-menu-wrapper">
                <button class="icon-btn user-avatar-btn" aria-label="Open user menu">
                    <?php if ($viewer_profile_image_url !== ''): ?>
                    <img src="<?php echo htmlspecialchars($viewer_profile_image_url); ?>" alt="Profile picture" class="user-avatar-thumb">
                    <?php else: ?>
                    <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </button>
                <div class="user-dropdown">
                    <div class="user-dropdown-header"><div class="user-name"><?php echo htmlspecialchars($viewer_first_name); ?></div><?php if ($viewer_email !== ''): ?><div class="user-email"><?php echo htmlspecialchars($viewer_email); ?></div><?php endif; ?></div>
                    <a href="<?php echo $path_prefix; ?>my_account.php" class="user-dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                    <a href="<?php echo $path_prefix; ?>my_account.php#addresses" class="user-dropdown-item"><i class="fas fa-address-book"></i> Address Book</a>
                    <a href="<?php echo $path_prefix; ?>my_orders.php" class="user-dropdown-item"><i class="fas fa-shopping-bag"></i> My Orders</a>
                    <a href="<?php echo $path_prefix; ?>help_center.php" class="user-dropdown-item"><i class="fas fa-life-ring"></i> Help Center</a>
                    <?php if ($favorites_feature_enabled): ?>
                    <a href="<?php echo $favorites_page_href; ?>" class="user-dropdown-item"><i class="fas fa-heart"></i> My Favorites</a>
                    <?php endif; ?>
                    <a href="<?php echo $path_prefix; ?>franchise_application.php" class="user-dropdown-item"><i class="fas fa-store"></i> Apply for Business</a>
                    <?php if (isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'organization'): ?>
                    <a href="<?php echo $path_prefix; ?>subscription_plans.php" class="user-dropdown-item"><i class="fas fa-layer-group"></i> Subscription Plans</a>
                    <a href="<?php echo $path_prefix; ?>seller_products.php" class="user-dropdown-item"><i class="fas fa-box"></i> My Products</a>
                    <a href="<?php echo $path_prefix; ?>seller_vouchers.php" class="user-dropdown-item"><i class="fas fa-tags"></i> My Vouchers</a>
                    <a href="<?php echo $path_prefix; ?>seller_advertisements.php" class="user-dropdown-item"><i class="fas fa-bullhorn"></i> My Advertisements</a>
                    <?php endif; ?>
                    <?php if ($normalized_user_type === 'admin'): ?>
                    <a href="<?php echo $path_prefix; ?>admin/index.php" class="user-dropdown-item"><i class="fas fa-gauge-high"></i> Admin Panel</a>
                    <?php endif; ?>
                    <a href="javascript:void(0);" onclick="confirmLogout()" class="user-dropdown-item logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
            <?php if ($favorites_feature_enabled): ?>
            <a class="icon-btn" id="favoritesToggle" href="<?php echo $favorites_page_href; ?>" title="Favorites"><i class="far fa-heart"></i><span class="badge" id="favoritesBadge" style="display:none;">0</span></a>
            <?php endif; ?>
            <?php if ($is_customer_user): ?>
            <button class="icon-btn" id="chatBtn" onclick="location.href='<?php echo $path_prefix; ?>help_center.php'" title="Help Center"><i class="fas fa-comment-dots"></i><span class="badge" id="chatBadge" style="display:none;">0</span></button>
            <?php endif; ?>
            <div class="notification-wrapper">
                <button class="icon-btn"><i class="fas fa-bell"></i><span class="badge" id="notificationBadge" style="display:none;">0</span></button>
                <div class="notification-dropdown" id="notificationDropdown"><div class="notification-header">Notifications</div><div class="notification-empty">No new notifications yet.</div></div>
            </div>
            <button class="icon-btn cart-btn" id="cartToggle" onclick="location.href='<?php echo $path_prefix; ?>cart.php'"><i class="fas fa-shopping-cart"></i><span class="badge" id="cartBadge"><?php echo (int)$cart_count; ?></span></button>
            <?php else: ?>
                <?php if ($current_page !== 'register' && $current_page !== 'login'): ?>
                <div class="auth-buttons">
                    <a href="<?php echo $path_prefix; ?>register.php?mode=login#login" class="btn-signin">Log in</a>
                    <a href="<?php echo $path_prefix; ?>register.php?mode=register#register" class="btn-register">Create account</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <button class="icon-btn mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
        </div>
    </div>
    
    <?php if ($show_market_header_bottom): ?>
    <div class="market-header-bottom">
        <nav class="market-home-nav">
            <a href="<?php echo $path_prefix; ?>index.php#marketplaceStores" class="market-home-link<?php echo ($current_page === 'home' || $current_page === 'index') ? ' active' : ''; ?>"><i class="fas fa-motorcycle"></i> Delivery</a>
            <a href="<?php echo $path_prefix; ?>index.php?type=pickup#marketplaceStores" class="market-home-link<?php echo ($current_page === 'pickup') ? ' active' : ''; ?>"><i class="fas fa-person-walking"></i> Pick-up</a>
            <a href="<?php echo $path_prefix; ?>shops.php" class="market-home-link<?php echo ($current_page === 'shops') ? ' active' : ''; ?>"><i class="fas fa-shop"></i> Shops</a>
        </nav>
        <div class="market-home-search-wrap" id="marketHeaderSearchWrap">
            <label class="market-home-search" for="marketHeaderSearch"><i class="fas fa-magnifying-glass"></i><input type="search" id="marketHeaderSearch" placeholder="Search for restaurants, cuisines, and dishes" autocomplete="off"></label>
            <div class="market-search-popover" id="marketSearchPopover">
                <div class="market-search-tabs">
                    <button type="button" class="market-search-tab active" data-tab="all">All</button>
                    <button type="button" class="market-search-tab" data-tab="delivery">Delivery</button>
                    <button type="button" class="market-search-tab" data-tab="pickup">Pick-up</button>
                    <button type="button" class="market-search-tab" data-tab="shops">Shops</button>
                </div>
                <div class="market-search-panel active" data-panel="all">
                    <p class="market-search-title">Popular searches</p>
                    <div class="market-search-suggestions">
                        <button type="button" class="market-search-suggestion" data-term="Lechon belly">Lechon belly</button>
                        <button type="button" class="market-search-suggestion" data-term="Whole lechon">Whole lechon</button>
                        <button type="button" class="market-search-suggestion" data-term="Cavite">Cavite</button>
                        <button type="button" class="market-search-suggestion" data-term="Top rated">Top rated</button>
                    </div>
                </div>
                <div class="market-search-panel" data-panel="delivery">
                    <p class="market-search-title">Delivery picks</p>
                    <div class="market-search-suggestions">
                        <button type="button" class="market-search-suggestion" data-term="Fast delivery">Fast delivery</button>
                        <button type="button" class="market-search-suggestion" data-term="Live menu">Live menu</button>
                    </div>
                </div>
                <div class="market-search-panel" data-panel="pickup">
                    <p class="market-search-title">Pick-up searches</p>
                    <div class="market-search-suggestions">
                        <button type="button" class="market-search-suggestion" data-term="Pickup branch">Pickup branch</button>
                        <button type="button" class="market-search-suggestion" data-term="Dasmarinas">Dasmarinas</button>
                    </div>
                </div>
                <div class="market-search-panel" data-panel="shops">
                    <p class="market-search-title">Shops</p>
                    <div class="market-search-suggestions">
                        <button type="button" class="market-search-suggestion" data-term="Partner store">Partner store</button>
                        <button type="button" class="market-search-suggestion" data-term="Marketplace seller">Marketplace seller</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($ongoing_order_id > 0 && $current_page !== 'track_order'): ?>
    <div id="stickyOngoingOrderWidget" class="sticky-ongoing-widget">
        <a href="<?php echo $path_prefix; ?>track_order.php?order_id=<?php echo $ongoing_order_id; ?>" class="sticky-ongoing-link">
            <div class="sticky-ongoing-icon">
                <i class="fas fa-motorcycle"></i>
                <span class="sticky-pulse-ring"></span>
            </div>
            <div class="sticky-ongoing-info">
                <div class="sticky-ongoing-header">
                    <strong style="font-weight:800; font-size:0.92rem; color:#171922;">Active Delivery Order</strong>
                    <span class="sticky-live-tag">LIVE</span>
                </div>
                <div class="sticky-ongoing-status">
                    #<?php echo htmlspecialchars($ongoing_order_number); ?> &bull; <span style="color:#b3261e; font-weight:700;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ongoing_order_status))); ?></span>
                </div>
            </div>
            <div class="sticky-ongoing-btn">
                Track <i class="fas fa-arrow-right"></i>
            </div>
        </a>
    </div>
    <?php endif; ?>
    
    <div class="map-modal-overlay" id="marketMapModal" aria-hidden="true">
        <div class="map-modal" role="dialog" aria-modal="true" aria-labelledby="mapModalTitle">
            <div class="map-modal-head">
                <div>
                    <h3 class="map-modal-title" id="mapModalTitle"><i class="fas fa-location-dot"></i> Your delivery address</h3>
                    <p class="map-modal-sub">Add address for search accuracy</p>
                </div>
                <button type="button" class="map-modal-close" id="marketMapModalClose" aria-label="Close map modal"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="map-modal-body">
                <div class="map-modal-input-wrap">
                    <input type="text" class="map-modal-input" id="mapAddressInput" placeholder="Street address">
                    <button type="button" class="map-modal-search-btn" id="mapAddressSearchBtn" aria-label="Search address"><i class="fas fa-magnifying-glass"></i></button>
                </div>
                <div class="map-modal-grid">
                    <input type="text" class="map-modal-input" id="mapCityInput" placeholder="City / Municipality">
                    <input type="text" class="map-modal-input" id="mapPostalCodeInput" placeholder="Postal code" inputmode="numeric" maxlength="10">
                </div>
                <div class="map-modal-map" id="marketMapCanvas"></div>
            </div>
            <div class="map-modal-foot">
                <button type="button" class="map-modal-btn secondary" id="marketMapUseCurrent"><i class="fas fa-crosshairs"></i> Use current</button>
                <button type="button" class="map-modal-btn primary" id="marketMapApplyBtn"><i class="fas fa-check"></i> Save Location</button>
            </div>
        </div>
    </div>
</header>
<div class="market-search-backdrop" id="marketSearchBackdrop"></div>
<?php else: ?>
<header class="site-header standard-header">
    <div class="header-shell standard-top">
        <a href="<?php echo $path_prefix; ?>index.php" class="logo-link">
            <span class="logo-icon"><img src="<?php echo $path_prefix; ?>assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block;"></span>
            <span class="logo-copy"><span class="logo-title">Lechon Delights</span><span class="logo-sub">Marketplace</span></span>
        </a>
        <?php if (!$is_auth_page): ?>
        <nav class="main-nav">
            <a href="<?php echo $path_prefix; ?>index.php" class="nav-link <?php echo $current_page === 'home' ? 'active' : ''; ?>"><i class="fas fa-house"></i> Home</a>
            <a href="<?php echo $path_prefix; ?>menu.php" class="nav-link <?php echo $current_page === 'menu' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu</a>
            <a href="<?php echo htmlspecialchars($preorder_nav_href); ?>" class="nav-link <?php echo $current_page === 'preorder' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Pre-order</a>
            <a href="<?php echo $path_prefix; ?>locations.php" class="nav-link <?php echo $current_page === 'locations' ? 'active' : ''; ?>"><i class="fas fa-location-dot"></i> Stores</a>
            <a href="<?php echo $path_prefix; ?>about.php" class="nav-link <?php echo $current_page === 'about' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> Our Story</a>
            <a href="<?php echo $path_prefix; ?>faq.php" class="nav-link <?php echo $current_page === 'faq' ? 'active' : ''; ?>"><i class="fas fa-circle-question"></i> FAQ</a>
            <?php if (isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'organization'): ?>
            <a href="<?php echo $path_prefix; ?>subscription_plans.php" class="nav-link <?php echo $current_page === 'subscription_plans' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Plans</a>
            <?php endif; ?>
        </nav>
        <div class="header-actions">
            <?php if ($is_logged_in_user): ?>
            <div class="user-menu-wrapper">
                <button class="icon-btn user-avatar-btn" aria-label="Open user menu">
                    <?php if ($viewer_profile_image_url !== ''): ?>
                    <img src="<?php echo htmlspecialchars($viewer_profile_image_url); ?>" alt="Profile picture" class="user-avatar-thumb">
                    <?php else: ?>
                    <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </button>
                <div class="user-dropdown">
                    <div class="user-dropdown-header"><div class="user-name"><?php echo htmlspecialchars($viewer_first_name); ?></div><?php if ($viewer_email !== ''): ?><div class="user-email"><?php echo htmlspecialchars($viewer_email); ?></div><?php endif; ?></div>
                    <a href="<?php echo $path_prefix; ?>my_account.php" class="user-dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                    <a href="<?php echo $path_prefix; ?>my_account.php#addresses" class="user-dropdown-item"><i class="fas fa-address-book"></i> Address Book</a>
                    <a href="<?php echo $path_prefix; ?>my_orders.php" class="user-dropdown-item"><i class="fas fa-shopping-bag"></i> My Orders</a>
                    <a href="<?php echo $path_prefix; ?>help_center.php" class="user-dropdown-item"><i class="fas fa-life-ring"></i> Help Center</a>
                    <?php if ($favorites_feature_enabled): ?>
                    <a href="<?php echo $favorites_page_href; ?>" class="user-dropdown-item"><i class="fas fa-heart"></i> My Favorites</a>
                    <?php endif; ?>
                    <a href="<?php echo $path_prefix; ?>franchise_application.php" class="user-dropdown-item"><i class="fas fa-store"></i> Apply for Business</a>
                    <?php if (isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'organization'): ?>
                    <a href="<?php echo $path_prefix; ?>subscription_plans.php" class="user-dropdown-item"><i class="fas fa-layer-group"></i> Subscription Plans</a>
                    <a href="<?php echo $path_prefix; ?>seller_products.php" class="user-dropdown-item"><i class="fas fa-box"></i> My Products</a>
                    <a href="<?php echo $path_prefix; ?>seller_vouchers.php" class="user-dropdown-item"><i class="fas fa-tags"></i> My Vouchers</a>
                    <?php endif; ?>
                    <?php if ($normalized_user_type === 'admin'): ?>
                    <a href="<?php echo $path_prefix; ?>admin/index.php" class="user-dropdown-item"><i class="fas fa-gauge-high"></i> Admin Panel</a>
                    <?php endif; ?>
                    <a href="javascript:void(0);" onclick="confirmLogout()" class="user-dropdown-item logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
            <?php else: ?>
                <?php if ($current_page !== 'register' && $current_page !== 'login'): ?>
                <div class="auth-buttons">
                    <a href="<?php echo $path_prefix; ?>register.php?mode=login#login" class="btn-signin">Log in</a>
                    <a href="<?php echo $path_prefix; ?>register.php?mode=register#register" class="btn-register">Create account</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($favorites_feature_enabled): ?>
            <a class="icon-btn" id="favoritesToggle" href="<?php echo $favorites_page_href; ?>" title="Favorites"><i class="far fa-heart"></i><span class="badge" id="favoritesBadge" style="display:none;">0</span></a>
            <?php endif; ?>
            <?php if ($is_customer_user): ?>
            <button class="icon-btn" id="chatBtn" onclick="location.href='<?php echo $path_prefix; ?>help_center.php'" title="Help Center"><i class="fas fa-comment-dots"></i><span class="badge" id="chatBadge" style="display:none;">0</span></button>
            <?php endif; ?>
            <button class="icon-btn cart-btn" id="cartToggle" onclick="location.href='<?php echo $path_prefix; ?>cart.php'"><i class="fas fa-shopping-cart"></i><span class="badge" id="cartBadge"><?php echo (int)$cart_count; ?></span></button>
            <button class="icon-btn mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
        </div>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>

<div class="mobile-menu-overlay" id="mobileOverlay"></div>
<aside class="mobile-menu" id="mobileMenu">
    <div class="mobile-menu-header">
        <a href="<?php echo $path_prefix; ?>index.php" class="logo-link">
            <span class="logo-icon" style="width:38px;height:38px;font-size:.88rem;"><img src="<?php echo $path_prefix; ?>assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block;"></span>
            <span class="logo-copy"><span class="logo-title" style="font-size:1.04rem;">Lechon Delights</span><span class="logo-sub">Marketplace</span></span>
        </a>
        <button class="mobile-menu-close" id="mobileMenuClose"><i class="fas fa-times"></i></button>
    </div>
    <ul class="mobile-nav">
        <li><a href="<?php echo $path_prefix; ?>index.php" class="<?php echo $current_page === 'home' ? 'active' : ''; ?>"><i class="fas fa-house"></i> Home</a></li>
        <li><a href="<?php echo $path_prefix; ?>menu.php" class="<?php echo $current_page === 'menu' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu</a></li>
        <li><a href="<?php echo htmlspecialchars($preorder_nav_href); ?>" class="<?php echo $current_page === 'preorder' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Pre-order</a></li>
        <li><a href="<?php echo $path_prefix; ?>locations.php" class="<?php echo $current_page === 'locations' ? 'active' : ''; ?>"><i class="fas fa-location-dot"></i> Stores</a></li>
        <li><a href="<?php echo $path_prefix; ?>about.php" class="<?php echo $current_page === 'about' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> Our Story</a></li>
        <li><a href="<?php echo $path_prefix; ?>faq.php" class="<?php echo $current_page === 'faq' ? 'active' : ''; ?>"><i class="fas fa-circle-question"></i> FAQ</a></li>
        <?php if ($is_logged_in_user): ?>
        <li><a href="<?php echo $path_prefix; ?>my_account.php"><i class="fas fa-user"></i> My Profile</a></li>
        <li><a href="<?php echo $path_prefix; ?>my_account.php#addresses"><i class="fas fa-address-book"></i> Address Book</a></li>
        <li><a href="<?php echo $path_prefix; ?>my_orders.php"><i class="fas fa-bag-shopping"></i> My Orders</a></li>
        <?php if ($favorites_feature_enabled): ?>
        <li><a href="<?php echo $favorites_page_href; ?>"><i class="fas fa-heart"></i> My Favorites</a></li>
        <?php endif; ?>
        <li><a href="<?php echo $path_prefix; ?>franchise_application.php"><i class="fas fa-briefcase"></i> Business</a></li>
        <?php if (isset($_SESSION['account_type']) && $_SESSION['account_type'] === 'organization'): ?>
        <li><a href="<?php echo $path_prefix; ?>subscription_plans.php" class="<?php echo $current_page === 'subscription_plans' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Subscription Plans</a></li>
        <li><a href="<?php echo $path_prefix; ?>seller_products.php"><i class="fas fa-box"></i> My Products</a></li>
        <li><a href="<?php echo $path_prefix; ?>seller_vouchers.php"><i class="fas fa-tags"></i> My Vouchers</a></li>
        <?php endif; ?>
        <li><a href="javascript:void(0);" onclick="confirmLogout()"><i class="fas fa-right-from-bracket"></i> Logout</a></li>
        <?php endif; ?>
    </ul>
    <?php if (!$is_logged_in_user && $current_page !== 'register' && $current_page !== 'login'): ?>
    <div class="mobile-auth">
        <a href="<?php echo $path_prefix; ?>register.php?mode=login#login" class="btn-signin" style="text-align:center;">Log in</a>
        <a href="<?php echo $path_prefix; ?>register.php?mode=register#register" class="btn-register" style="text-align:center;">Create account</a>
    </div>
    <?php endif; ?>
</aside>

<main class="site-main">
<script>
window.swalConfirmAction = window.swalConfirmAction || function(options) {
    const config = Object.assign({
        title: 'Are you sure?',
        text: 'Please confirm this action.',
        icon: 'warning',
        confirmButtonText: 'Confirm',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#171922',
        cancelButtonColor: '#98a2b3'
    }, options || {});

    if (typeof Swal !== 'undefined') {
        return Swal.fire({
            title: config.title,
            text: config.text,
            icon: config.icon,
            showCancelButton: true,
            confirmButtonText: config.confirmButtonText,
            cancelButtonText: config.cancelButtonText,
            confirmButtonColor: config.confirmButtonColor,
            cancelButtonColor: config.cancelButtonColor
        }).then(function(result) {
            return !!(result && result.isConfirmed);
        });
    }

    return Promise.resolve(window.confirm(config.text || config.title || 'Are you sure?'));
};

window.bindSwalConfirmForms = window.bindSwalConfirmForms || function(root) {
    const scope = root || document;
    scope.querySelectorAll('form[data-sw-confirm]').forEach(function(form) {
        if (form.dataset.swConfirmBound === '1') return;
        form.dataset.swConfirmBound = '1';
        form.addEventListener('submit', function(event) {
            if (form.dataset.swConfirmed === '1') {
                form.dataset.swConfirmed = '0';
                return;
            }

            event.preventDefault();
            window.swalConfirmAction({
                title: form.dataset.swConfirmTitle || 'Confirm action?',
                text: form.dataset.swConfirmText || 'Please confirm this action.',
                icon: form.dataset.swConfirmIcon || 'warning',
                confirmButtonText: form.dataset.swConfirmConfirmText || 'Confirm',
                cancelButtonText: form.dataset.swConfirmCancelText || 'Cancel',
                confirmButtonColor: form.dataset.swConfirmConfirmColor || '#171922',
                cancelButtonColor: form.dataset.swConfirmCancelColor || '#98a2b3'
            }).then(function(confirmed) {
                if (confirmed) {
                    form.dataset.swConfirmed = '1';
                    form.submit();
                }
            });
        });
    });
};

function confirmLogout() {
    const logoutUrl = '<?php echo $path_prefix; ?>logout.php';
    window.swalConfirmAction({
        title: 'Log out now?',
        text: 'You can sign in again anytime.',
        icon: 'question',
        confirmButtonText: 'Yes, log out'
    }).then(function(confirmed) {
        if (confirmed) window.location.href = logoutUrl;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    window.bindSwalConfirmForms(document);
    const siteHeader = document.querySelector('.site-header');
    const mobileToggle = document.getElementById('mobileToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const mobileMenuClose = document.getElementById('mobileMenuClose');

    const showSwalError = function (message, title) {
        const alertTitle = title || 'Error';
        const alertMessage = message || 'Please check your input and try again.';
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: alertTitle,
                text: alertMessage,
                confirmButtonColor: '#171922'
            });
            return;
        }
        alert(alertTitle + ': ' + alertMessage);
    };

    const getFieldLabel = function (field) {
        if (!field) return 'This field';
        if (field.dataset && field.dataset.label) return field.dataset.label.trim();
        if (field.getAttribute('aria-label')) return field.getAttribute('aria-label').trim();
        if (field.id) {
            const linkedLabel = document.querySelector('label[for="' + field.id.replace(/"/g, '\\"') + '"]');
            if (linkedLabel) return linkedLabel.textContent.replace('*', '').trim();
        }
        if (field.name) {
            return field.name.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        }
        return 'This field';
    };

    const getValidationMessage = function (field) {
        if (!field || !field.validity) return 'Please complete the form.';
        if (field.validity.valueMissing) return getFieldLabel(field) + ' is required.';
        if (field.validity.typeMismatch) return 'Please enter a valid ' + getFieldLabel(field).toLowerCase() + '.';
        if (field.validity.tooShort) return getFieldLabel(field) + ' is too short.';
        if (field.validity.tooLong) return getFieldLabel(field) + ' is too long.';
        if (field.validity.patternMismatch) return field.title ? field.title : 'Please follow the required format for ' + getFieldLabel(field).toLowerCase() + '.';
        if (field.validity.rangeUnderflow || field.validity.rangeOverflow) return 'Please enter a valid value for ' + getFieldLabel(field).toLowerCase() + '.';
        if (field.validity.stepMismatch) return 'Please enter a valid increment for ' + getFieldLabel(field).toLowerCase() + '.';
        return field.validationMessage || ('Please check ' + getFieldLabel(field).toLowerCase() + '.');
    };

    const showFieldValidationError = function (field) {
        const message = getValidationMessage(field);
        showSwalError(message, 'Missing or Invalid Field');
        if (field && typeof field.focus === 'function') {
            setTimeout(function () { field.focus(); }, 60);
        }
    };

    window.showSwalError = showSwalError;

    const forms = Array.from(document.querySelectorAll('form'));
    forms.forEach(function (form) {
        if (!form || form.dataset.swalValidate === 'off') return;

        form.addEventListener('invalid', function (event) {
            event.preventDefault();
        }, true);

        form.addEventListener('submit', function (event) {
            if (form.dataset.swalValidate === 'off') return;
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                const invalidField = form.querySelector(':invalid');
                showFieldValidationError(invalidField);
            }
        });
    });

    const syncHeaderOffset = () => {
        if (!siteHeader) return;
        const height = Math.max(72, Math.ceil(siteHeader.getBoundingClientRect().height || 0));
        document.documentElement.style.setProperty('--site-header-offset', height + 'px');
    };
    const scheduleHeaderOffsetSync = () => requestAnimationFrame(syncHeaderOffset);

    syncHeaderOffset();
    window.addEventListener('resize', scheduleHeaderOffsetSync, { passive: true });
    if (siteHeader && 'ResizeObserver' in window) {
        const headerResizeObserver = new ResizeObserver(scheduleHeaderOffsetSync);
        headerResizeObserver.observe(siteHeader);
    }

    const openMenu = () => { if (!mobileMenu || !mobileOverlay) return; mobileMenu.classList.add('active'); mobileOverlay.classList.add('active'); document.body.style.overflow = 'hidden'; };
    const closeMenu = () => {
        if (!mobileMenu || !mobileOverlay) return;
        mobileMenu.classList.remove('active');
        mobileOverlay.classList.remove('active');
        if (marketMapModal && marketMapModal.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    };
    if (mobileToggle) mobileToggle.addEventListener('click', openMenu);
    if (mobileMenuClose) mobileMenuClose.addEventListener('click', closeMenu);
    if (mobileOverlay) mobileOverlay.addEventListener('click', closeMenu);

    const marketAddressToggle = document.getElementById('marketAddressToggle');
    const marketAddressPanel = document.getElementById('marketAddressPanel');
    const marketStreetAddressInput = document.getElementById('marketStreetAddressInput');
    const marketCityInput = document.getElementById('marketCityInput');
    const marketPostalCodeInput = document.getElementById('marketPostalCodeInput');
    const marketAddressDisplay = document.getElementById('marketAddressDisplay');
    const marketAddressApply = document.getElementById('marketAddressApply');
    const marketLocateButton = document.getElementById('marketLocateButton');
    const marketMapModal = document.getElementById('marketMapModal');
    const marketMapModalClose = document.getElementById('marketMapModalClose');
    const mapAddressInput = document.getElementById('mapAddressInput');
    const mapCityInput = document.getElementById('mapCityInput');
    const mapPostalCodeInput = document.getElementById('mapPostalCodeInput');
    const mapAddressSearchBtn = document.getElementById('mapAddressSearchBtn');
    const marketMapUseCurrent = document.getElementById('marketMapUseCurrent');
    const marketMapApplyBtn = document.getElementById('marketMapApplyBtn');
    const marketHeaderSearchWrap = document.getElementById('marketHeaderSearchWrap');
    const marketHeaderSearch = document.getElementById('marketHeaderSearch');
    const marketSearchPopover = document.getElementById('marketSearchPopover');
    const marketSearchTabs = Array.from(document.querySelectorAll('.market-search-tab'));
    const marketSearchPanels = Array.from(document.querySelectorAll('.market-search-panel'));
    const marketSearchSuggestions = Array.from(document.querySelectorAll('.market-search-suggestion'));
    const loginUrl = <?php echo json_encode($path_prefix . 'login.php'); ?>;
    const isLoggedInUser = <?php echo $is_logged_in_user ? 'true' : 'false'; ?>;
    const marketSearchBaseUrl = <?php echo json_encode($path_prefix . 'index.php'); ?>;
    const favoritesApiUrl = <?php echo json_encode($favorites_api_href); ?>;
    const favoritesFeatureEnabled = <?php echo $favorites_feature_enabled ? 'true' : 'false'; ?>;
    const favoritesBadge = document.getElementById('favoritesBadge');
    const googleMapsApiKey = <?php echo json_encode((string)$google_maps_api_key); ?>;
    const defaultMapCoords = { lat: 14.3294, lng: 120.9367 };
    let marketMap = null;
    let marketMarker = null;
    let marketGeocoder = null;
    let marketGoogleGeocodingAvailable = <?php echo $google_geocoding_enabled ? 'true' : 'false'; ?>;
    let googleMapsLoaderPromise = null;
    let googleMapsModules = null;
    let pendingMapAddress = '';
    const marketAddressStorageKey = 'market_address_payload';

    // Keep the fixed modal at document level so sticky/header effects do not clip it.
    if (marketMapModal && marketMapModal.parentElement !== document.body) {
        document.body.appendChild(marketMapModal);
    }

    const normalizePostalCode = function (value) {
        return String(value || '').replace(/[^\dA-Za-z-]/g, '').trim();
    };

    const parseLatLngFromAddressText = function (value) {
        const text = String(value || '').trim();
        if (!text) return null;
        const match = text.match(/lat(?:itude)?\s*[:=]?\s*(-?\d+(?:\.\d+)?)\s*[, ]+\s*lng(?:itude)?\s*[:=]?\s*(-?\d+(?:\.\d+)?)/i);
        if (!match) return null;
        const lat = Number(match[1]);
        const lng = Number(match[2]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
        return { lat: lat, lng: lng };
    };

    const buildMarketAddressPayload = function (streetAddress, city, postalCode) {
        const street = String(streetAddress || '').trim();
        const cityName = String(city || '').trim();
        const zip = normalizePostalCode(postalCode);
        const cityWithZip = [cityName, zip].filter(Boolean).join(' ');
        const fullAddress = [street, cityWithZip].filter(Boolean).join(', ');
        return {
            street_address: street,
            city: cityName,
            postal_code: zip,
            full_address: fullAddress
        };
    };

    const parseAddressComponents = function (result) {
        const components = Array.isArray(result?.address_components) ? result.address_components : [];
        let streetNumber = '';
        let route = '';
        let premise = '';
        let city = '';
        let fallbackCity = '';
        let postalCode = '';

        components.forEach(function (component) {
            const types = Array.isArray(component?.types) ? component.types : [];
            const longName = String(component?.long_name || '').trim();
            if (!longName) return;
            if (types.includes('street_number')) streetNumber = longName;
            if (types.includes('route')) route = longName;
            if (types.includes('premise') || types.includes('subpremise') || types.includes('establishment')) {
                if (!premise) premise = longName;
            }
            if (types.includes('locality') || types.includes('postal_town') || types.includes('administrative_area_level_3')) {
                if (!city) city = longName;
            }
            if (types.includes('sublocality') || types.includes('neighborhood') || types.some(function (t) { return String(t).indexOf('sublocality_level_') === 0; })) {
                if (!fallbackCity) fallbackCity = longName;
            }
            if (types.includes('postal_code')) postalCode = longName;
        });

        const formattedParts = String(result?.formatted_address || '')
            .split(',')
            .map(function (part) { return String(part || '').trim(); })
            .filter(Boolean);

        const fallbackStreet = formattedParts[0] || '';
        const streetAddress = [streetNumber, route].filter(Boolean).join(' ').trim() || premise || fallbackStreet;
        const cityCandidate = city || fallbackCity || (formattedParts.length > 1 ? formattedParts[1].replace(/\b\d{4,6}\b/g, '').trim() : '');

        return buildMarketAddressPayload(streetAddress, cityCandidate, postalCode);
    };

    const getStoredMarketAddressPayload = function () {
        try {
            const rawPayload = localStorage.getItem(marketAddressStorageKey);
            if (rawPayload) {
                const parsed = JSON.parse(rawPayload);
                if (parsed && typeof parsed === 'object') {
                    const latLngFromFullAddress = parseLatLngFromAddressText(parsed.full_address || '');
                    if (latLngFromFullAddress) {
                        return {
                            street_address: '',
                            city: '',
                            postal_code: '',
                            full_address: '',
                            legacy_lat: latLngFromFullAddress.lat,
                            legacy_lng: latLngFromFullAddress.lng
                        };
                    }
                    return buildMarketAddressPayload(parsed.street_address, parsed.city, parsed.postal_code);
                }
            }
        } catch (error) {
            // Ignore invalid payloads and fallback to legacy key.
        }

        try {
            const legacy = String(localStorage.getItem('market_address') || '').trim();
            if (legacy) {
                const legacyLatLng = parseLatLngFromAddressText(legacy);
                if (legacyLatLng) {
                    return {
                        street_address: '',
                        city: '',
                        postal_code: '',
                        full_address: '',
                        legacy_lat: legacyLatLng.lat,
                        legacy_lng: legacyLatLng.lng
                    };
                }
                const legacyParts = legacy.split(',').map(function (part) { return String(part || '').trim(); }).filter(Boolean);
                const legacyStreet = legacyParts[0] || legacy;
                const legacyCityPart = legacyParts.length > 1 ? legacyParts[legacyParts.length - 1] : '';
                const zipMatch = legacyCityPart.match(/\b\d{4,6}\b/);
                const legacyPostal = zipMatch ? zipMatch[0] : '';
                const legacyCity = legacyCityPart ? legacyCityPart.replace(/\b\d{4,6}\b/g, '').trim() : '';
                return buildMarketAddressPayload(legacyStreet, legacyCity, legacyPostal);
            }
        } catch (error) {
            // Local storage can be unavailable.
        }

        return null;
    };

    const resolveLatLngToAddressPayload = async function (lat, lng) {
        if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return null;
        try {
            const endpoint = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                + '&addressdetails=1'
                + '&countrycodes=ph'
                + '&lat=' + encodeURIComponent(String(lat))
                + '&lon=' + encodeURIComponent(String(lng));
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) return null;
            const payload = await response.json();
            const address = payload?.address || {};
            const street = [
                String(address?.house_number || '').trim(),
                String(address?.road || address?.residential || address?.neighbourhood || '').trim()
            ].filter(Boolean).join(' ').trim()
                || String(payload?.display_name || '').split(',')[0].trim();
            const city = String(address?.city || address?.town || address?.municipality || address?.county || address?.state_district || address?.province || '').trim();
            const postalCode = normalizePostalCode(address?.postcode || '');
            const normalized = buildMarketAddressPayload(street, city, postalCode);
            return (normalized.street_address && normalized.city) ? normalized : null;
        } catch (error) {
            return null;
        }
    };

    const isGoogleGeocodingUnavailableStatus = function (status) {
        const normalized = String(status || '').trim().toUpperCase();
        return normalized === 'REQUEST_DENIED'
            || normalized === 'OVER_QUERY_LIMIT'
            || normalized === 'OVER_DAILY_LIMIT'
            || normalized === 'INVALID_REQUEST';
    };

    const forwardGeocodeFromNominatim = async function (query) {
        const addressText = String(query || '').trim();
        if (!addressText) return null;
        try {
            const endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2'
                + '&addressdetails=1'
                + '&countrycodes=ph'
                + '&limit=1'
                + '&q=' + encodeURIComponent(addressText);
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) return null;
            const payload = await response.json();
            if (!Array.isArray(payload) || !payload.length) return null;

            const first = payload[0] || {};
            const lat = Number(first?.lat);
            const lng = Number(first?.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;

            const address = first?.address || {};
            const street = [
                String(address?.house_number || '').trim(),
                String(address?.road || address?.residential || address?.neighbourhood || '').trim()
            ].filter(Boolean).join(' ').trim()
                || String(first?.display_name || '').split(',')[0].trim();
            const city = String(address?.city || address?.town || address?.municipality || address?.county || address?.state_district || address?.province || '').trim();
            const postalCode = normalizePostalCode(address?.postcode || '');

            return {
                lat,
                lng,
                payload: buildMarketAddressPayload(street, city, postalCode)
            };
        } catch (error) {
            return null;
        }
    };

    const persistMarketAddressPayload = function (payload) {
        const normalized = buildMarketAddressPayload(payload?.street_address, payload?.city, payload?.postal_code);
        try {
            localStorage.setItem(marketAddressStorageKey, JSON.stringify(normalized));
            localStorage.setItem('market_address', normalized.full_address);
        } catch (error) {
            // Best-effort persistence only.
        }
        return normalized;
    };

    const fillMarketAddressInputs = function (payload, options) {
        const normalized = buildMarketAddressPayload(payload?.street_address, payload?.city, payload?.postal_code);
        const config = Object.assign({ includeMapFields: true }, options || {});

        if (marketStreetAddressInput) marketStreetAddressInput.value = normalized.street_address;
        if (marketCityInput) marketCityInput.value = normalized.city;
        if (marketPostalCodeInput) marketPostalCodeInput.value = normalized.postal_code;

        if (config.includeMapFields) {
            if (mapAddressInput) mapAddressInput.value = normalized.street_address;
            if (mapCityInput) mapCityInput.value = normalized.city;
            if (mapPostalCodeInput) mapPostalCodeInput.value = normalized.postal_code;
        }

        return normalized;
    };

    const renderMarketAddressDisplay = function (payload) {
        if (!marketAddressDisplay) return;
        const normalized = buildMarketAddressPayload(payload?.street_address, payload?.city, payload?.postal_code);
        marketAddressDisplay.textContent = normalized.full_address || 'Select your address';
    };

    const applyAddress = function () {
        if (!marketStreetAddressInput || !marketCityInput) return;
        const payload = buildMarketAddressPayload(
            marketStreetAddressInput.value,
            marketCityInput.value,
            marketPostalCodeInput ? marketPostalCodeInput.value : ''
        );

        if (!payload.street_address) {
            showSwalError('Please enter your street address.', 'Address Required');
            marketStreetAddressInput.focus();
            return;
        }
        if (!payload.city) {
            showSwalError('Please enter your city or municipality.', 'Address Required');
            marketCityInput.focus();
            return;
        }

        const stored = persistMarketAddressPayload(payload);
        renderMarketAddressDisplay(stored);
        fillMarketAddressInputs(stored, { includeMapFields: false });
        if (marketAddressPanel) marketAddressPanel.classList.remove('active');
        scheduleHeaderOffsetSync();
    };

    const ensureGoogleMapsLoaded = function () {
        if (window.google && window.google.maps) return Promise.resolve(true);
        if (googleMapsLoaderPromise) return googleMapsLoaderPromise;
        if (!googleMapsApiKey) {
            showSwalError('Google Maps API key is missing. Please configure GOOGLE_MAPS_API_KEY.', 'Map Unavailable');
            return Promise.resolve(false);
        }
        googleMapsLoaderPromise = new Promise(function (resolve) {
            const existingScript = document.querySelector('script[data-google-maps-modal="1"]');
            if (existingScript) {
                existingScript.addEventListener('load', function () { resolve(!!(window.google && window.google.maps)); }, { once: true });
                existingScript.addEventListener('error', function () { resolve(false); }, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(googleMapsApiKey) + '&v=weekly&loading=async';
            script.async = true;
            script.defer = true;
            script.dataset.googleMapsModal = '1';
            script.onload = function () { resolve(!!(window.google && window.google.maps)); };
            script.onerror = function () { resolve(false); };
            document.head.appendChild(script);
        });

        return googleMapsLoaderPromise;
    };

    const ensureGoogleMapsModules = function () {
        return ensureGoogleMapsLoaded().then(function (isLoaded) {
            if (!isLoaded || !window.google || !google.maps || typeof google.maps.importLibrary !== 'function') return null;
            if (googleMapsModules) return googleMapsModules;
            return Promise.all([
                google.maps.importLibrary('maps'),
                google.maps.importLibrary('marker')
            ]).then(function (libraries) {
                googleMapsModules = {
                    maps: libraries[0] || {},
                    marker: libraries[1] || {}
                };
                return googleMapsModules;
            }).catch(function () {
                return null;
            });
        });
    };

    const toLatLngLiteral = function (position) {
        if (!position) return null;
        if (typeof position.lat === 'function' && typeof position.lng === 'function') {
            return { lat: position.lat(), lng: position.lng() };
        }
        if (typeof position.lat === 'number' && typeof position.lng === 'number') {
            return { lat: position.lat, lng: position.lng };
        }
        return null;
    };

    const getMarkerPosition = function () {
        if (!marketMarker) return null;
        if (typeof marketMarker.getPosition === 'function') {
            return toLatLngLiteral(marketMarker.getPosition());
        }
        if (Object.prototype.hasOwnProperty.call(marketMarker, 'position') || marketMarker.position) {
            return toLatLngLiteral(marketMarker.position);
        }
        return null;
    };

    const setMarkerPosition = function (point) {
        if (!marketMarker || !point) return;
        if (typeof marketMarker.setPosition === 'function') {
            marketMarker.setPosition(point);
            return;
        }
        marketMarker.position = point;
    };

    const reverseGeocode = function (lat, lng) {
        if (!marketGoogleGeocodingAvailable || !marketGeocoder || !window.google || !google.maps) return resolveLatLngToAddressPayload(lat, lng);
        return new Promise(function (resolve) {
            marketGeocoder.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
                if (status === 'OK' && Array.isArray(results) && results.length) {
                    for (let idx = 0; idx < results.length; idx++) {
                        const parsed = parseAddressComponents(results[idx]);
                        if (parsed && parsed.street_address && parsed.city) {
                            resolve(parsed);
                            return;
                        }
                    }
                    const parsedPrimary = parseAddressComponents(results[0]);
                    if (parsedPrimary && parsedPrimary.street_address) {
                        resolveLatLngToAddressPayload(lat, lng).then(function (fallbackPayload) {
                            resolve(fallbackPayload || parsedPrimary || null);
                        });
                        return;
                    }
                    resolveLatLngToAddressPayload(lat, lng).then(function (fallbackPayload) {
                        resolve(fallbackPayload || null);
                    });
                    return;
                }
                if (isGoogleGeocodingUnavailableStatus(status)) {
                    marketGoogleGeocodingAvailable = false;
                }
                resolveLatLngToAddressPayload(lat, lng).then(function (fallbackPayload) {
                    resolve(fallbackPayload || null);
                });
            });
        });
    };

    const requestFreshCurrentPosition = function () {
        if (!navigator.geolocation) {
            return Promise.reject(new Error('GEO_UNAVAILABLE'));
        }

        return new Promise(function (resolve, reject) {
            navigator.geolocation.getCurrentPosition(function (position) {
                resolve(position);
            }, function (error) {
                // Fallback once with relaxed cache settings if strict fresh lookup fails.
                navigator.geolocation.getCurrentPosition(function (position) {
                    resolve(position);
                }, function (fallbackError) {
                    reject(fallbackError || error || new Error('GEO_FAILED'));
                }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 180000 });
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        });
    };

    const centerMapAt = function (lat, lng, zoomLevel, shouldReverseLookup) {
        if (!marketMap || !marketMarker) return;
        const nextZoom = zoomLevel || 16;
        const point = { lat: lat, lng: lng };
        marketMap.setCenter(point);
        marketMap.setZoom(nextZoom);
        setMarkerPosition(point);
        if (shouldReverseLookup) {
            reverseGeocode(lat, lng).then(function (payload) {
                if (payload) {
                    fillMarketAddressInputs(payload, { includeMapFields: true });
                }
            });
        }
    };

    const ensureMarketMap = function () {
        if (!marketMapModal || !document.getElementById('marketMapCanvas')) return Promise.resolve(false);
        if (marketMap) return Promise.resolve(true);

        return ensureGoogleMapsModules().then(function (modules) {
            if (!modules || !window.google || !google.maps) {
                showSwalError('Google Maps is unavailable right now. Please refresh and try again.', 'Map Unavailable');
                return false;
            }

            const MapConstructor = modules.maps && modules.maps.Map ? modules.maps.Map : google.maps.Map;
            const AdvancedMarkerConstructor = modules.marker && modules.marker.AdvancedMarkerElement ? modules.marker.AdvancedMarkerElement : null;
            if (typeof MapConstructor !== 'function' || typeof AdvancedMarkerConstructor !== 'function') {
                showSwalError('Google Maps marker library could not be initialized.', 'Map Unavailable');
                return false;
            }

            marketGeocoder = new google.maps.Geocoder();
            marketMap = new MapConstructor(document.getElementById('marketMapCanvas'), {
                center: defaultMapCoords,
                zoom: 15,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                mapId: 'DEMO_MAP_ID'
            });
            marketMarker = new AdvancedMarkerConstructor({
                position: defaultMapCoords,
                map: marketMap,
                title: 'Delivery location',
                gmpDraggable: true
            });

            marketMap.addListener('click', function (event) {
                if (!event || !event.latLng) return;
                centerMapAt(event.latLng.lat(), event.latLng.lng(), marketMap.getZoom(), true);
            });

            marketMarker.addListener('dragend', function () {
                const markerPos = getMarkerPosition();
                if (!markerPos) return;
                centerMapAt(markerPos.lat, markerPos.lng, marketMap.getZoom(), true);
            });

            return true;
        });
    };

    const openMapModal = function () {
        if (!marketMapModal) return;
        pendingMapAddress = marketStreetAddressInput ? marketStreetAddressInput.value.trim() : '';
        fillMarketAddressInputs({
            street_address: marketStreetAddressInput ? marketStreetAddressInput.value : '',
            city: marketCityInput ? marketCityInput.value : '',
            postal_code: marketPostalCodeInput ? marketPostalCodeInput.value : ''
        }, { includeMapFields: true });
        marketMapModal.classList.add('active');
        marketMapModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        ensureMarketMap().then(function (isReady) {
            if (!isReady) {
                closeMapModal();
                return;
            }
            setTimeout(function () {
                if (marketMap && window.google && google.maps && google.maps.event) {
                    google.maps.event.trigger(marketMap, 'resize');
                    const markerPos = getMarkerPosition();
                    if (markerPos) marketMap.setCenter(markerPos);
                }
                if (mapAddressInput) mapAddressInput.focus();
            }, 70);
        });
    };
    window.openHeaderMapModal = openMapModal;

    const closeMapModal = function () {
        if (!marketMapModal) return;
        marketMapModal.classList.remove('active');
        marketMapModal.setAttribute('aria-hidden', 'true');
        if (mobileMenu && mobileMenu.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    };

    const searchAddressOnMap = function () {
        if (!mapAddressInput) return;
        const q = [
            mapAddressInput.value,
            mapCityInput ? mapCityInput.value : '',
            mapPostalCodeInput ? mapPostalCodeInput.value : ''
        ].map(function (part) { return String(part || '').trim(); }).filter(Boolean).join(', ');
        if (!q) {
            showSwalError('Please type an address to search.', 'Address Required');
            return;
        }
        ensureMarketMap().then(async function (isReady) {
            if (!isReady) return;
            const applyNominatimFallback = async function (status) {
                const fallback = await forwardGeocodeFromNominatim(q);
                if (fallback && Number.isFinite(fallback.lat) && Number.isFinite(fallback.lng)) {
                    centerMapAt(fallback.lat, fallback.lng, 16, false);
                    fillMarketAddressInputs(fallback.payload, { includeMapFields: true });
                    if (isGoogleGeocodingUnavailableStatus(status)) {
                        showSwalError('Google Geocoding is currently unavailable for this key. We used a fallback search provider.', 'Limited Geocoding Mode');
                    }
                    return true;
                }
                return false;
            };

            if (!marketGeocoder || !marketGoogleGeocodingAvailable) {
                const applied = await applyNominatimFallback('REQUEST_DENIED');
                if (!applied) {
                    showSwalError('No matching address found. Try a more specific location.', 'Address Not Found');
                }
                return;
            }

            marketGeocoder.geocode({ address: q }, async function (results, status) {
                if (status === 'REQUEST_DENIED') {
                    marketGoogleGeocodingAvailable = false;
                }

                if (status === 'OK' && Array.isArray(results) && results.length && results[0].geometry && results[0].geometry.location) {
                    const location = results[0].geometry.location;
                    centerMapAt(location.lat(), location.lng(), 16, false);
                    fillMarketAddressInputs(parseAddressComponents(results[0]), { includeMapFields: true });
                    return;
                }

                if (isGoogleGeocodingUnavailableStatus(status)) {
                    marketGoogleGeocodingAvailable = false;
                }

                const applied = await applyNominatimFallback(status);
                if (applied) {
                    return;
                }

                if (isGoogleGeocodingUnavailableStatus(status)) {
                    showSwalError('Google Geocoding is unavailable and fallback search found no match. Please enable Geocoding API or try another address.', 'Address Search Limited');
                    return;
                }

                if (status !== 'OK' || !Array.isArray(results) || !results.length || !results[0].geometry || !results[0].geometry.location) {
                    showSwalError('No matching address found. Try a more specific location.', 'Address Not Found');
                    return;
                }
            });
        });
    };

    if (marketAddressDisplay) {
        const savedPayload = getStoredMarketAddressPayload();
        if (savedPayload) {
            if (Number.isFinite(Number(savedPayload.legacy_lat)) && Number.isFinite(Number(savedPayload.legacy_lng))) {
                renderMarketAddressDisplay({ street_address: '', city: '', postal_code: '' });
                resolveLatLngToAddressPayload(Number(savedPayload.legacy_lat), Number(savedPayload.legacy_lng)).then(function (resolvedPayload) {
                    if (!resolvedPayload) return;
                    const storedPayload = persistMarketAddressPayload(resolvedPayload);
                    const normalized = fillMarketAddressInputs(storedPayload, { includeMapFields: true });
                    renderMarketAddressDisplay(normalized);
                });
            } else {
                const normalized = fillMarketAddressInputs(savedPayload, { includeMapFields: true });
                renderMarketAddressDisplay(normalized);
            }
        } else {
            renderMarketAddressDisplay({ street_address: '', city: '', postal_code: '' });
        }
    }

    const marketAddressWrap = document.getElementById('marketAddressWrap');
    const marketQuickStreetInput = document.getElementById('marketQuickStreetInput');
    const marketLocateMeBtn = document.getElementById('marketLocateMeBtn');
    const marketQuickAddressSubmitBtn = document.getElementById('marketQuickAddressSubmitBtn');
    const marketAddressPills = document.querySelectorAll('#marketAddressPopover .market-address-pill');

    // Header location Leaflet map
    let headerMap = null;
    let headerMarker = null;

    const initHeaderMap = function() {
        const headerMapEl = document.getElementById('headerMap');
        if (!headerMapEl || typeof L === 'undefined' || headerMap) return;

        let startLat = 14.3294;
        let startLng = 120.9367;

        headerMap = L.map('headerMap').setView([startLat, startLng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(headerMap);

        headerMarker = L.marker([startLat, startLng], {
            draggable: true
        }).addTo(headerMap);

        const geocodeHeaderCoords = async (lat, lng) => {
            try {
                if (marketQuickStreetInput) marketQuickStreetInput.value = "Locating...";
                const endpoint = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&countrycodes=ph&lat=${lat}&lon=${lng}`;
                const response = await fetch(endpoint, {
                    headers: { Accept: 'application/json' }
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data && data.display_name) {
                        const addr = data.display_name;
                        if (marketQuickStreetInput) marketQuickStreetInput.value = addr;
                        const city = data.address?.city || data.address?.town || data.address?.municipality || 'Cavite';
                        const payload = { street_address: addr, city: city, postal_code: data.address?.postcode || '', latitude: String(lat), longitude: String(lng) };
                        fillMarketAddressInputs(payload, { includeMapFields: true });
                        const stored = persistMarketAddressPayload(payload);
                        renderMarketAddressDisplay(stored);
                    }
                }
            } catch (e) {
                console.error("Header map geocoding error:", e);
            }
        };

        headerMarker.on('dragend', function() {
            const pos = headerMarker.getLatLng();
            geocodeHeaderCoords(pos.lat, pos.lng);
        });

        headerMap.on('click', function(e) {
            const pos = e.latlng;
            headerMarker.setLatLng(pos);
            geocodeHeaderCoords(pos.lat, pos.lng);
        });
    };

    const openAddressPopover = function () {
        if (!marketAddressWrap) return;
        marketAddressWrap.classList.add('is-open');
        if (marketSearchBackdrop) marketSearchBackdrop.classList.add('is-active');
        if (marketQuickStreetInput) marketQuickStreetInput.focus();
        
        setTimeout(function() {
            initHeaderMap();
            if (headerMap) {
                headerMap.invalidateSize();
            }
        }, 120);
    };

    const closeAddressPopover = function () {
        if (!marketAddressWrap) return;
        marketAddressWrap.classList.remove('is-open');
        if (marketSearchBackdrop) marketSearchBackdrop.classList.remove('is-active');
    };

    if (marketAddressToggle && marketAddressWrap) {
        marketAddressToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (marketAddressWrap.classList.contains('is-open')) {
                closeAddressPopover();
            } else {
                openAddressPopover();
            }
        });
    }

    const saveMarketLocationToUserDatabase = async function(payload) {
        if (!payload || (!payload.street_address && !payload.full_address)) return;
        const bodyData = new URLSearchParams();
        bodyData.append('label', 'Saved Location');
        bodyData.append('street_address', payload.street_address || payload.full_address);
        bodyData.append('city_name', payload.city || 'Cavite');
        bodyData.append('full_address', payload.full_address || payload.street_address);
        bodyData.append('latitude', String(payload.latitude || ''));
        bodyData.append('longitude', String(payload.longitude || ''));
        bodyData.append('is_default', '1');

        try {
            const apiEndpoint = '<?php echo $path_prefix; ?>api/save_user_address.php';
            const response = await fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: bodyData
            });
            const data = await response.json();
            if (data && data.success) {
                if (typeof window.dispatchEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('marketAddressUpdated', { 
                        detail: {
                            ...payload,
                            address_id: data.saved_address_id || 0,
                            addresses: data.addresses || []
                        }
                    }));
                }
            }
        } catch (err) {
            console.error('Header save location error:', err);
        }
    };

    const applyQuickAddressSelection = function (street, city) {
        const payload = buildMarketAddressPayload(street || '', city || 'Cavite', '');
        fillMarketAddressInputs(payload, { includeMapFields: true });
        const stored = persistMarketAddressPayload(payload);
        renderMarketAddressDisplay(stored);
        saveMarketLocationToUserDatabase(stored);
        closeAddressPopover();
    };

    marketAddressPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            const city = pill.dataset.city || 'Cavite';
            const street = pill.dataset.street || (city + ', Cavite');
            if (marketQuickStreetInput) marketQuickStreetInput.value = street;
            applyQuickAddressSelection(street, city);
        });
    });

    if (marketQuickAddressSubmitBtn) {
        marketQuickAddressSubmitBtn.addEventListener('click', function () {
            const rawVal = marketQuickStreetInput ? marketQuickStreetInput.value.trim() : '';
            if (!rawVal) {
                showSwalError('Please enter a valid street address.', 'Address Required');
                return;
            }
            applyQuickAddressSelection(rawVal, 'Cavite');
        });
    }

    const headerSaveBtn = document.getElementById('headerSaveLocationBtn');
    if (headerSaveBtn) {
        headerSaveBtn.addEventListener('click', function () {
            const rawVal = marketQuickStreetInput ? marketQuickStreetInput.value.trim() : '';
            let lat = '', lng = '';
            if (headerMarker) {
                const pos = headerMarker.getLatLng();
                lat = pos.lat;
                lng = pos.lng;
            }
            const streetText = rawVal || (lat ? 'Pinned Location' : '');
            if (!streetText) {
                showSwalError('Please select or pin a location first.', 'Location Required');
                return;
            }
            const payload = buildMarketAddressPayload(streetText, 'Cavite', '', lat, lng);
            fillMarketAddressInputs(payload, { includeMapFields: true });
            const stored = persistMarketAddressPayload(payload);
            renderMarketAddressDisplay(stored);
            saveMarketLocationToUserDatabase(stored);
            closeAddressPopover();
        });
    }

    if (marketQuickStreetInput) {
        marketQuickStreetInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const rawVal = marketQuickStreetInput.value.trim();
                if (rawVal) applyQuickAddressSelection(rawVal, 'Cavite');
            }
        });
    }

    if (marketLocateMeBtn) {
        marketLocateMeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();

            const originalBtnHtml = marketLocateMeBtn.innerHTML;
            marketLocateMeBtn.disabled = true;
            marketLocateMeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';

            requestFreshCurrentPosition().then(function (position) {
                if (!position || !position.coords) throw new Error('GEO_INVALID');
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                return reverseGeocode(lat, lng).then(function(payload) {
                    if (payload && payload.street_address) return payload;
                    const CAVITE_CITIES = [
                        { city: 'General Trias', lat: 14.3167, lng: 120.8833, street: 'General Trias, Cavite' },
                        { city: 'Dasmarinas', lat: 14.3294, lng: 120.9367, street: 'Dasmariñas, Cavite' },
                        { city: 'Imus', lat: 14.4297, lng: 120.9367, street: 'Imus, Cavite' },
                        { city: 'Bacoor', lat: 14.4608, lng: 120.9631, street: 'Bacoor, Cavite' },
                        { city: 'Tagaytay', lat: 14.1153, lng: 120.9621, street: 'Tagaytay, Cavite' }
                    ];
                    let minDistance = Infinity;
                    let nearest = CAVITE_CITIES[0];
                    CAVITE_CITIES.forEach(function(item) {
                        const dist = haversineKm(lat, lng, item.lat, item.lng);
                        if (dist < minDistance) { minDistance = dist; nearest = item; }
                    });
                    return { street_address: nearest.street, city: nearest.city, postal_code: '' };
                });
            }).then(function (resolvedPayload) {
                marketLocateMeBtn.disabled = false;
                marketLocateMeBtn.innerHTML = originalBtnHtml;

                const finalPayload = resolvedPayload || { street_address: 'General Trias, Cavite', city: 'General Trias', postal_code: '' };
                if (marketQuickStreetInput) {
                    marketQuickStreetInput.value = finalPayload.street_address;
                }
                fillMarketAddressInputs(finalPayload, { includeMapFields: true });
                const storedPayload = persistMarketAddressPayload(finalPayload);
                renderMarketAddressDisplay(storedPayload);
                closeAddressPopover();
            }).catch(function (error) {
                marketLocateMeBtn.disabled = false;
                marketLocateMeBtn.innerHTML = originalBtnHtml;
                const defaultPayload = { street_address: 'General Trias, Cavite', city: 'General Trias', postal_code: '' };
                if (marketQuickStreetInput) marketQuickStreetInput.value = defaultPayload.street_address;
                fillMarketAddressInputs(defaultPayload, { includeMapFields: true });
                const storedPayload = persistMarketAddressPayload(defaultPayload);
                renderMarketAddressDisplay(storedPayload);
                closeAddressPopover();
            });
        });
    }

    if (marketAddressToggle && marketAddressPanel) {
        marketAddressToggle.addEventListener('click', function () {
            if (marketAddressPanel) marketAddressPanel.classList.toggle('active');
        });
    }
    if (marketAddressApply) marketAddressApply.addEventListener('click', applyAddress);
    if (marketStreetAddressInput) marketStreetAddressInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyAddress(); } });
    if (marketCityInput) marketCityInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyAddress(); } });
    if (marketPostalCodeInput) marketPostalCodeInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyAddress(); } });

    if (marketLocateButton) {
        marketLocateButton.addEventListener('click', function () {
            openMapModal();
        });
    }

    if (marketMapModalClose) marketMapModalClose.addEventListener('click', closeMapModal);
    if (marketMapModal) {
        marketMapModal.addEventListener('click', function (event) {
            if (event.target === marketMapModal) closeMapModal();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && marketMapModal && marketMapModal.classList.contains('active')) {
            closeMapModal();
        }
    });

    if (mapAddressSearchBtn) mapAddressSearchBtn.addEventListener('click', searchAddressOnMap);
    if (mapAddressInput) {
        mapAddressInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchAddressOnMap();
            }
        });
    }

    if (marketMapUseCurrent) {
        marketMapUseCurrent.addEventListener('click', function () {
            if (!navigator.geolocation) {
                showSwalError('Browser geolocation is not supported on this device.', 'Geolocation Unavailable');
                return;
            }
            ensureMarketMap().then(function (isReady) {
                if (!isReady) return;
                marketMapUseCurrent.disabled = true;
                const originalLabel = marketMapUseCurrent.innerHTML;
                marketMapUseCurrent.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';

                requestFreshCurrentPosition().then(function (position) {
                    const lat = Number(position.coords.latitude);
                    const lng = Number(position.coords.longitude);
                    centerMapAt(lat, lng, 16, false);

                    reverseGeocode(lat, lng).then(function (payload) {
                        if (payload) {
                            fillMarketAddressInputs(payload, { includeMapFields: true });
                            applyAddress();
                            closeMapModal();
                        } else {
                            showSwalError('Could not resolve your current address. Please drag the pin and tap Find food.', 'Address Not Found');
                        }
                    }).finally(function () {
                        marketMapUseCurrent.disabled = false;
                        marketMapUseCurrent.innerHTML = originalLabel;
                    });
                }).catch(function () {
                    marketMapUseCurrent.disabled = false;
                    marketMapUseCurrent.innerHTML = originalLabel;
                    showSwalError('Location access was denied. Please allow location permission.', 'Geolocation Blocked');
                });
            });
        });
    }

    if (marketMapApplyBtn) {
        marketMapApplyBtn.addEventListener('click', function () {
            if (!marketStreetAddressInput || !marketCityInput) return;
            const payload = buildMarketAddressPayload(
                mapAddressInput ? mapAddressInput.value : '',
                mapCityInput ? mapCityInput.value : '',
                mapPostalCodeInput ? mapPostalCodeInput.value : ''
            );
            if (!payload.street_address || !payload.city) {
                showSwalError('Please complete street address and city first.', 'Address Required');
                return;
            }

            fillMarketAddressInputs(payload, { includeMapFields: true });
            applyAddress();
            closeMapModal();
        });
    }

    if (marketHeaderSearchWrap && marketHeaderSearch && marketSearchPopover) {
        let activeSearchTab = 'all';
        const setSearchTab = function (tab) {
            activeSearchTab = String(tab || 'all');
            marketSearchTabs.forEach(function (button) {
                button.classList.toggle('active', button.dataset.tab === tab);
            });
            marketSearchPanels.forEach(function (panel) {
                panel.classList.toggle('active', panel.dataset.panel === tab);
            });
        };

        const submitMarketHeaderSearch = function (rawTerm) {
            const term = String(rawTerm || '').trim();
            if (!term) return;

            const params = new URLSearchParams();
            params.set('search', term);

            const lowered = term.toLowerCase();
            if (activeSearchTab === 'delivery') params.set('live', '1');
            if (activeSearchTab === 'pickup') params.set('branch', '1');
            if (activeSearchTab === 'shops') params.set('partner', '1');

            if (lowered.includes('top rated') || lowered.includes('rating')) params.set('sort', 'top_rated');
            if (lowered.includes('fast') || lowered.includes('nearest') || lowered.includes('distance')) params.set('sort', 'distance');
            if (lowered.includes('live')) params.set('live', '1');
            if (lowered.includes('partner')) params.set('partner', '1');
            if (lowered.includes('pickup') || lowered.includes('branch')) params.set('branch', '1');

            window.location.href = marketSearchBaseUrl + '?' + params.toString() + '#marketplaceStores';
        };

        const marketMainHeader = document.querySelector('.market-main-header');
        const marketSearchBackdrop = document.getElementById('marketSearchBackdrop');
        const marketHeaderBottom = document.querySelector('.market-header-bottom');

        const openSearchPopover = function () {
            marketHeaderSearchWrap.classList.add('is-open');
            if (marketMainHeader) marketMainHeader.classList.add('search-active');
            if (marketHeaderBottom) marketHeaderBottom.classList.add('search-active');
            if (marketSearchBackdrop) marketSearchBackdrop.classList.add('is-active');
        };

        const closeSearchPopover = function () {
            marketHeaderSearchWrap.classList.remove('is-open');
            if (marketMainHeader) marketMainHeader.classList.remove('search-active');
            if (marketHeaderBottom) marketHeaderBottom.classList.remove('search-active');
            if (marketSearchBackdrop) marketSearchBackdrop.classList.remove('is-active');
        };

        if (marketSearchBackdrop) {
            marketSearchBackdrop.addEventListener('click', function () {
                closeSearchPopover();
                if (typeof closeAddressPopover === 'function') closeAddressPopover();
            });
        }

        marketHeaderSearch.addEventListener('focus', openSearchPopover);
        marketHeaderSearch.addEventListener('input', openSearchPopover);
        marketHeaderSearch.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeSearchPopover();
            if (event.key === 'Enter') {
                event.preventDefault();
                submitMarketHeaderSearch(marketHeaderSearch.value);
                closeSearchPopover();
            }
        });

        marketSearchTabs.forEach(function (button) {
            button.addEventListener('click', function () {
                setSearchTab(button.dataset.tab || 'all');
            });
        });

        marketSearchSuggestions.forEach(function (button) {
            button.addEventListener('click', function () {
                const term = (button.dataset.term || '').trim();
                if (!term) return;
                marketHeaderSearch.value = term;
                submitMarketHeaderSearch(term);
            });
        });

        document.addEventListener('click', function (event) {
            if (marketHeaderSearchWrap && !marketHeaderSearchWrap.contains(event.target)) {
                closeSearchPopover();
            }
        });
    }

    const updateFavoritesBadge = function (count) {
        if (!favoritesBadge) return;
        const normalizedCount = Math.max(0, parseInt(count, 10) || 0);
        if (normalizedCount <= 0) {
            favoritesBadge.style.display = 'none';
            favoritesBadge.textContent = '0';
            return;
        }
        favoritesBadge.style.display = 'inline-flex';
        favoritesBadge.textContent = normalizedCount > 99 ? '99+' : String(normalizedCount);
    };

    const setFavoriteButtonState = function (button, isFavorite) {
        if (!button) return;
        const active = !!isFavorite;
        button.dataset.favoriteActive = active ? '1' : '0';
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.setAttribute('title', active ? 'Remove from favorites' : 'Save to favorites');

        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.remove('far', 'fas');
            icon.classList.add(active ? 'fas' : 'far', 'fa-heart');
        }
    };

    const getFavoriteKeyInfo = function (button) {
        if (!button) return null;
        const favoriteType = String(button.dataset.favoriteType || '').trim().toLowerCase();
        if (favoriteType === 'store') {
            const storeKey = String(button.dataset.favoriteStoreKey || '').trim().toLowerCase();
            if (!storeKey) return null;
            return { favoriteType: 'store', key: storeKey };
        }
        if (favoriteType === 'product') {
            const productId = parseInt(button.dataset.favoriteProductId || '0', 10);
            if (!Number.isFinite(productId) || productId <= 0) return null;
            return { favoriteType: 'product', key: String(productId) };
        }
        return null;
    };

    const updateMatchingFavoriteButtons = function (favoriteType, key, isFavorite) {
        if (!favoriteType || !key) return;
        document.querySelectorAll('[data-favorite-toggle]').forEach(function (button) {
            const currentType = String(button.dataset.favoriteType || '').trim().toLowerCase();
            if (currentType !== favoriteType) return;
            const currentKey = favoriteType === 'store'
                ? String(button.dataset.favoriteStoreKey || '').trim().toLowerCase()
                : String(parseInt(button.dataset.favoriteProductId || '0', 10));
            if (currentKey !== key) return;
            setFavoriteButtonState(button, isFavorite);
        });
    };

    const requestFavoritesCount = async function () {
        if (!favoritesFeatureEnabled || !favoritesApiUrl) return;
        try {
            const response = await fetch(favoritesApiUrl + '?action=count', {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload || !payload.success) return;
            updateFavoritesBadge(payload.count || 0);
        } catch (error) {
            // Ignore transient count fetch failures.
        }
    };

    const navigateToLogin = function () {
        const redirectTarget = window.location.pathname + window.location.search + window.location.hash;
        window.location.href = loginUrl + '?redirect=' + encodeURIComponent(redirectTarget);
    };

    const toggleFavorite = async function (button) {
        const keyInfo = getFavoriteKeyInfo(button);
        if (!keyInfo) return;

        if (!favoritesFeatureEnabled) {
            if (isLoggedInUser) {
                showSwalError('Favorites are available for customer accounts only.', 'Favorites');
            } else {
                navigateToLogin();
            }
            return;
        }

        if (button.dataset.loading === '1') {
            return;
        }

        button.dataset.loading = '1';
        button.disabled = true;

        const body = new URLSearchParams();
        body.set('action', 'toggle');
        body.set('favorite_type', keyInfo.favoriteType);
        if (keyInfo.favoriteType === 'store') {
            body.set('store_key', keyInfo.key);
        } else {
            body.set('product_id', keyInfo.key);
        }

        try {
            const response = await fetch(favoritesApiUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            });

            const payload = await response.json();
            if (!response.ok || !payload || !payload.success) {
                throw new Error((payload && payload.message) || 'Failed to update favorites.');
            }

            const isFavorite = !!payload.is_favorite;
            const totalCount = parseInt(payload.total_count || '0', 10) || 0;
            updateMatchingFavoriteButtons(keyInfo.favoriteType, keyInfo.key, isFavorite);
            updateFavoritesBadge(totalCount);
            document.dispatchEvent(new CustomEvent('favorites:changed', {
                detail: {
                    favoriteType: keyInfo.favoriteType,
                    key: keyInfo.key,
                    isFavorite: isFavorite,
                    totalCount: totalCount
                }
            }));
        } catch (error) {
            showSwalError(error.message || 'Could not update favorite right now.', 'Favorites');
        } finally {
            button.dataset.loading = '0';
            button.disabled = false;
        }
    };

    document.querySelectorAll('[data-favorite-toggle]').forEach(function (button) {
        setFavoriteButtonState(button, button.dataset.favoriteActive === '1');
    });

    document.addEventListener('click', function (event) {
        const favoriteButton = event.target.closest('[data-favorite-toggle]');
        if (!favoriteButton) return;
        event.preventDefault();
        toggleFavorite(favoriteButton);
    });

    requestFavoritesCount();

    document.addEventListener('click', function (event) {
        if (marketAddressWrap && marketAddressWrap.classList.contains('is-open')) {
            if (!marketAddressWrap.contains(event.target)) {
                closeAddressPopover();
            }
        }
        if (marketAddressPanel && marketAddressPanel.classList.contains('active')) {
            const inPanel = marketAddressPanel.contains(event.target);
            const inToggle = marketAddressToggle && marketAddressToggle.contains(event.target);
            if (!inPanel && !inToggle) {
                marketAddressPanel.classList.remove('active');
                scheduleHeaderOffsetSync();
            }
        }
    });
});
</script>
