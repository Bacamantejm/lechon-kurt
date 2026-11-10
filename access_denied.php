<?php
/**
 * 403 Forbidden / Access Restricted Error Page
 * Displays a clean, modern error screen when users attempt to access
 * administrative or restricted portal resources without authorization.
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (!headers_sent()) {
    http_response_code(403);
}

// Compute relative root path based on whether file was included from a subfolder
$script_path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
$is_nested = (
    strpos($script_path, '/admin/') !== false ||
    strpos($script_path, '/super_admin/') !== false ||
    strpos($script_path, '/employee/') !== false ||
    strpos($script_path, '/rider/') !== false
);
$path_prefix = $is_nested ? '../' : './';

// Defensive DB connection lookup if already active in environment
$conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;

// Session user details
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_name = trim((string)($_SESSION['full_name'] ?? ''));
$user_email = trim((string)($_SESSION['email'] ?? ''));
$user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));

// Populate missing details from database if logged in
if ($user_id > 0 && $conn && ($user_name === '' || $user_email === '')) {
    $stmt = mysqli_prepare($conn, "SELECT full_name, email, user_type FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            if ($user_name === '') $user_name = (string)($row['full_name'] ?? '');
            if ($user_email === '') $user_email = (string)($row['email'] ?? '');
            if ($user_type === '') $user_type = strtolower((string)($row['user_type'] ?? ''));
        }
        mysqli_stmt_close($stmt);
    }
}

// Format friendly role badge title
if ($user_id <= 0) {
    $role_title = 'Guest / Not Logged In';
    $role_badge_class = 'badge-neutral';
} elseif ($user_type === 'customer' || $role_name === 'customer' || (empty($role_name) && empty($user_type))) {
    $role_title = 'Customer Account';
    $role_badge_class = 'badge-customer';
} elseif ($user_type === 'employee' || in_array($role_name, ['staff', 'cashier', 'kitchen_staff', 'inventory_staff', 'logistics_staff'], true)) {
    $role_title = 'Staff / Employee';
    $role_badge_class = 'badge-warning';
} elseif ($user_type === 'rider' || $role_name === 'rider') {
    $role_title = 'Delivery Rider';
    $role_badge_class = 'badge-info';
} else {
    $role_title = ucwords(str_replace('_', ' ', $role_name ?: $user_type));
    $role_badge_class = 'badge-neutral';
}

// Resolve error reason message
$denied_reason = $_SESSION['access_denied_message'] ?? ($_GET['msg'] ?? ($_GET['error'] ?? ''));
if ($denied_reason === 'unauthorized' || empty($denied_reason)) {
    $denied_reason = 'Your current account does not have authorization to access the administrative backoffice portal.';
}
// Clear flash session error
if (isset($_SESSION['access_denied_message'])) {
    unset($_SESSION['access_denied_message']);
}

// Attempted URI
$requested_url = $_SESSION['access_denied_requested_url'] ?? ($_SERVER['REQUEST_URI'] ?? '');
$requested_path = parse_url($requested_url, PHP_URL_PATH) ?: $requested_url;
if (isset($_SESSION['access_denied_requested_url'])) {
    unset($_SESSION['access_denied_requested_url']);
}

// Fallback home URL for Back button
$fallback_home_url = $path_prefix . 'index.php';
if ($user_type === 'employee') {
    $fallback_home_url = $path_prefix . 'employee/dashboard.php';
} elseif ($user_type === 'rider') {
    $fallback_home_url = $path_prefix . 'rider/index.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Access Denied &bull; Lechon Delights</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $path_prefix; ?>favicon-32x32.png?v=lechon2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $path_prefix; ?>favicon-16x16.png?v=lechon2">
    <link rel="icon" type="image/x-icon" href="<?php echo $path_prefix; ?>favicon.ico?v=lechon2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-red: #b3261e;
            --brand-hover: #981b15;
            --page-bg: #f8f9fa;
            --card-bg: #ffffff;
            --border-neutral: #eaecf0;
            --border-hover: #d0d5dd;
            --primary-ink: #101828;
            --text-secondary: #344054;
            --text-muted: #667085;
            --danger-bg: #fff1f0;
            --danger-text: #b3261e;
            --danger-border: #fee4e2;
            --info-bg: #eff8ff;
            --info-text: #175cd3;
            --info-border: #b2ddff;
            --warning-bg: #fffaeb;
            --warning-text: #b54708;
            --warning-border: #fedf89;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--primary-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            -webkit-font-smoothing: antialiased;
        }

        .error-card {
            background: var(--card-bg);
            border: 1px solid var(--border-neutral);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(16, 24, 40, 0.05);
            max-width: 580px;
            width: 100%;
            padding: 40px 36px;
            text-align: center;
        }

        .brand-header {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--primary-ink);
            margin-bottom: 28px;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            display: block;
        }

        .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--primary-ink);
            letter-spacing: -0.3px;
        }

        .brand-name span {
            color: var(--primary-red);
        }

        .icon-shield-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 20px auto;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid var(--danger-border);
            margin-bottom: 16px;
            letter-spacing: 0.2px;
        }

        .status-badge i {
            font-size: 11px;
        }

        .error-title {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--primary-ink);
            margin-bottom: 10px;
            letter-spacing: -0.4px;
        }

        .error-desc {
            font-size: 14px;
            line-height: 1.55;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .user-context-box {
            background: var(--page-bg);
            border: 1px solid var(--border-neutral);
            border-radius: 12px;
            padding: 16px 18px;
            text-align: left;
            margin-bottom: 24px;
        }

        .user-context-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            padding: 4px 0;
        }

        .user-context-row:not(:last-child) {
            border-bottom: 1px solid var(--border-neutral);
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .context-label {
            color: var(--text-muted);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .context-value {
            color: var(--primary-ink);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-customer {
            background: var(--info-bg);
            color: var(--info-text);
            border: 1px solid var(--info-border);
        }

        .badge-warning {
            background: var(--warning-bg);
            color: var(--warning-text);
            border: 1px solid var(--warning-border);
        }

        .badge-info {
            background: var(--info-bg);
            color: var(--info-text);
            border: 1px solid var(--info-border);
        }

        .badge-neutral {
            background: #f2f4f7;
            color: #344054;
            border: 1px solid #d0d5dd;
        }

        .requested-path-pill {
            display: block;
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-muted);
            text-align: left;
        }

        .requested-path-pill code {
            background: #ffffff;
            border: 1px solid var(--border-neutral);
            padding: 2px 6px;
            border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11px;
            color: var(--text-secondary);
        }

        .actions-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 24px;
        }

        .btn-row {
            display: flex;
            gap: 12px;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            border: 1px solid transparent;
            flex: 1;
        }

        .btn-secondary {
            background: #ffffff;
            color: var(--text-secondary);
            border-color: #d0d5dd;
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #98a2b3;
            color: var(--primary-ink);
        }

        .btn-primary {
            background: var(--primary-red);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--brand-hover);
            color: #ffffff;
        }

        .btn-outline {
            background: #ffffff;
            color: var(--text-secondary);
            border-color: var(--border-neutral);
        }

        .btn-outline:hover {
            background: #f9fafb;
            border-color: var(--border-hover);
        }

        .switch-note {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
            padding-top: 16px;
            border-top: 1px solid var(--border-neutral);
        }

        .switch-note a {
            color: var(--primary-red);
            text-decoration: none;
            font-weight: 600;
        }

        .switch-note a:hover {
            text-decoration: underline;
        }

        .page-footer {
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
        }

        @media (max-width: 540px) {
            .error-card {
                padding: 28px 20px;
            }

            .error-title {
                font-size: 22px;
            }

            .btn-row {
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <a href="<?php echo htmlspecialchars($path_prefix . 'index.php'); ?>" class="brand-header">
            <img src="<?php echo htmlspecialchars($path_prefix . 'assets/images/logo.png?v=lechon2'); ?>" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
            <span class="brand-name">Lechon <span>Delights</span></span>
        </a>

        <div class="icon-shield-wrap">
            <i class="fas fa-shield-halved"></i>
        </div>

        <div class="status-badge">
            <i class="fas fa-lock"></i>
            <span>403 Forbidden &bull; Restricted Area</span>
        </div>

        <h1 class="error-title">Access Restricted</h1>

        <p class="error-desc"><?php echo htmlspecialchars($denied_reason); ?></p>

        <div class="user-context-box">
            <div class="user-context-row">
                <span class="context-label"><i class="fas fa-user-circle"></i> Signed-in Account</span>
                <span class="context-value">
                    <?php if ($user_id > 0): ?>
                        <?php echo htmlspecialchars($user_name ?: 'Logged-in User'); ?>
                    <?php else: ?>
                        Not logged in
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($user_id > 0 && !empty($user_email)): ?>
            <div class="user-context-row">
                <span class="context-label"><i class="fas fa-envelope"></i> Email</span>
                <span class="context-value"><?php echo htmlspecialchars($user_email); ?></span>
            </div>
            <?php endif; ?>

            <div class="user-context-row">
                <span class="context-label"><i class="fas fa-id-badge"></i> Account Type</span>
                <span class="role-pill <?php echo htmlspecialchars($role_badge_class); ?>">
                    <i class="fas fa-shield-check"></i>
                    <?php echo htmlspecialchars($role_title); ?>
                </span>
            </div>

            <?php if (!empty($requested_path) && $requested_path !== '/'): ?>
            <div class="requested-path-pill">
                Attempted URL: <code><?php echo htmlspecialchars($requested_path); ?></code>
            </div>
            <?php endif; ?>
        </div>

        <div class="actions-group">
            <div class="btn-row">
                <button type="button" class="btn-action btn-secondary" onclick="handleGoBack()" id="btnGoBack">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Previous Page</span>
                </button>

                <a href="<?php echo htmlspecialchars($path_prefix . 'index.php'); ?>" class="btn-action btn-primary">
                    <i class="fas fa-store"></i>
                    <span>Return to Store</span>
                </a>
            </div>

            <?php if ($user_type === 'customer' || $user_id > 0): ?>
            <a href="<?php echo htmlspecialchars($path_prefix . 'my_orders.php'); ?>" class="btn-action btn-outline">
                <i class="fas fa-receipt"></i>
                <span>View My Orders</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="switch-note">
            Need administrative backoffice access?
            <br>
            <a href="<?php echo htmlspecialchars($path_prefix . 'logout.php?redirect=admin/'); ?>">
                <i class="fas fa-right-to-bracket"></i> Sign out and log in with an Admin Account
            </a>
        </div>
    </div>

    <div class="page-footer">
        &copy; <?php echo date('Y'); ?> Lechon Delights Marketplace &bull; Security & Access Control
    </div>

    <script>
        function handleGoBack() {
            const referrer = document.referrer;
            const currentHost = window.location.host;

            // If user navigated from within our site and referrer is not an admin/forbidden URL
            if (referrer && referrer.indexOf(currentHost) !== -1) {
                try {
                    const parsedUrl = new URL(referrer);
                    const path = parsedUrl.pathname.toLowerCase();
                    const isRestrictedTarget = path.indexOf('/admin/') !== -1 ||
                                              path.indexOf('/super_admin/') !== -1 ||
                                              path.indexOf('access_denied.php') !== -1;
                    if (!isRestrictedTarget) {
                        window.location.href = referrer;
                        return;
                    }
                } catch (e) {
                    // Fall through to history back
                }
            }

            // If history exists, attempt back
            if (window.history.length > 1) {
                window.history.back();
                // Safety timeout fallback if history.back() fails (e.g. pasted into fresh tab)
                setTimeout(function () {
                    window.location.href = '<?php echo addslashes($fallback_home_url); ?>';
                }, 400);
            } else {
                window.location.href = '<?php echo addslashes($fallback_home_url); ?>';
            }
        }
    </script>
</body>
</html>
