<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

checkAdminAccess();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$notification_id = (int)($_GET['id'] ?? 0);
$has_notifications = adminAuthTableExists($conn, 'notifications');
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $has_notifications) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid request token.');
    }
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'mark_read') {
        $id = (int)($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($action === 'mark_all_read') {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    $redirect = 'notifications.php';
    if ($notification_id > 0) {
        $redirect .= '?id=' . $notification_id;
    }
    header('Location: ' . $redirect);
    exit;
}

$notifications = [];
if ($has_notifications) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, type, title, message, related_id, related_type, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 100"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $notifications[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

$selected_notification = null;
foreach ($notifications as $notification) {
    if ((int)$notification['id'] === $notification_id) {
        $selected_notification = $notification;
        break;
    }
}

$admin_info = getAdminInfo($conn);
$page_title = 'Notifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .notifications-page { padding: 24px; }
        .notifications-toolbar { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
        .notifications-toolbar h2 { margin:0; color:#101828; font-size:1.35rem; }
        .notification-card { display:flex; gap:14px; padding:16px; margin-bottom:10px; background:#fff; border:1px solid #eaecf0; border-radius:12px; color:#101828; text-decoration:none; box-shadow:0 1px 3px rgba(16,24,40,.04); }
        .notification-card:hover, .notification-card.selected { border-color:#b3261e; }
        .notification-card.unread { border-left:3px solid #b3261e; }
        .notification-card-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:#fff1f0; color:#b3261e; flex:0 0 auto; }
        .notification-card-body { min-width:0; flex:1; }
        .notification-card-title { font-weight:700; margin-bottom:4px; }
        .notification-card-message { color:#475467; white-space:pre-line; line-height:1.5; }
        .notification-card-time { color:#667085; font-size:.78rem; margin-top:7px; }
        .notification-detail { padding:18px; margin-bottom:18px; background:#fff; border:1px solid #eaecf0; border-radius:12px; }
        .notification-empty-state { padding:42px 20px; text-align:center; color:#667085; background:#fff; border:1px solid #eaecf0; border-radius:12px; }
        @media (max-width: 576px) { .notifications-page { padding:16px; } .notifications-toolbar { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body class="admin-polish">
<div class="admin-container">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div class="topbar-content">
                <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                <h1>Notifications</h1>
                <div class="topbar-right">
                    <div class="date-display" id="currentDate"></div>
                    <div class="admin-profile"><span><?php echo htmlspecialchars($admin_info['full_name'] ?? 'Admin'); ?></span><i class="fas fa-user-circle"></i></div>
                </div>
            </div>
        </div>
        <main class="notifications-page">
            <div class="notifications-toolbar">
                <h2>Alerts and notifications</h2>
                <?php if (!empty($notifications)): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Mark all as read</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($selected_notification): ?>
            <section class="notification-detail">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h3 class="h5 mb-2"><?php echo htmlspecialchars((string)$selected_notification['title']); ?></h3>
                        <div class="text-muted small"><?php echo htmlspecialchars((string)$selected_notification['created_at']); ?></div>
                    </div>
                    <?php if ((int)$selected_notification['is_read'] === 0): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?php echo (int)$selected_notification['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Mark as read</button>
                    </form>
                    <?php endif; ?>
                </div>
                <p class="mb-0 mt-3" style="white-space:pre-line;"><?php echo htmlspecialchars((string)$selected_notification['message']); ?></p>
            </section>
            <?php endif; ?>

            <?php if (empty($notifications)): ?>
                <div class="notification-empty-state"><i class="fas fa-bell-slash fa-2x mb-3"></i><div>No notifications yet.</div></div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                <a class="notification-card <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?> <?php echo (int)$notification['id'] === $notification_id ? 'selected' : ''; ?>" href="notifications.php?id=<?php echo (int)$notification['id']; ?>">
                    <span class="notification-card-icon"><i class="fas fa-bell"></i></span>
                    <span class="notification-card-body">
                        <span class="notification-card-title d-block"><?php echo htmlspecialchars((string)$notification['title']); ?></span>
                        <span class="notification-card-message d-block"><?php echo htmlspecialchars((string)$notification['message']); ?></span>
                        <span class="notification-card-time d-block"><?php echo htmlspecialchars((string)$notification['created_at']); ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="admin.js"></script>
</body>
</html>
