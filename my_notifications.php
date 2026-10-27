<?php
session_start();

if (empty($_SESSION['user_id'])) {
    $redirect = 'my_notifications.php';
    if (!empty($_GET['id'])) {
        $redirect .= '?id=' . (int)$_GET['id'];
    }
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';

$user_id = (int)$_SESSION['user_id'];
$notification_id = (int)($_GET['id'] ?? 0);
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $redirect = 'my_notifications.php';
    if ($notification_id > 0) {
        $redirect .= '?id=' . $notification_id;
    }
    header('Location: ' . $redirect);
    exit;
}

$notifications = [];
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

$selected_notification = null;
foreach ($notifications as $notification) {
    if ((int)$notification['id'] === $notification_id) {
        $selected_notification = $notification;
        break;
    }
}

$current_page = 'my_notifications';
$page_title = 'Notifications | Lechon Delights';
include __DIR__ . '/includes/header.php';
?>
<style>
    .customer-notifications-page { max-width: 980px; margin: 0 auto; padding: 28px 18px 140px; }
    .customer-notifications-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .customer-notifications-toolbar h1 { margin:0; color:#101828; font-size:1.55rem; }
    .customer-notifications-card { display:flex; gap:14px; padding:16px; margin-bottom:10px; background:#fff; border:1px solid #eaecf0; border-radius:12px; color:#101828; text-decoration:none; box-shadow:0 1px 3px rgba(16,24,40,.04); }
    .customer-notifications-card:hover, .customer-notifications-card.selected { border-color:#b3261e; }
    .customer-notifications-card.unread { border-left:3px solid #b3261e; }
    .customer-notifications-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:#fff1f0; color:#b3261e; flex:0 0 auto; }
    .customer-notifications-body { min-width:0; flex:1; }
    .customer-notifications-title { font-weight:700; margin-bottom:4px; }
    .customer-notifications-message { color:#475467; white-space:pre-line; line-height:1.5; }
    .customer-notifications-time { color:#667085; font-size:.78rem; margin-top:7px; }
    .customer-notifications-detail, .customer-notifications-empty { padding:22px; margin-bottom:18px; background:#fff; border:1px solid #eaecf0; border-radius:12px; }
    .customer-notifications-empty { padding:48px 20px; text-align:center; color:#667085; }
    @media (max-width: 576px) { .customer-notifications-page { padding-left:14px; padding-right:14px; } .customer-notifications-toolbar { align-items:flex-start; flex-direction:column; } }
</style>

<main class="customer-notifications-page">
    <div class="customer-notifications-toolbar">
        <h1>Notifications</h1>
        <?php if (!empty($notifications)): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="market-btn market-btn-ghost">Mark all as read</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($selected_notification): ?>
    <section class="customer-notifications-detail">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;">
            <div>
                <h2 style="margin:0 0 6px;font-size:1.2rem;color:#101828;"><?php echo htmlspecialchars((string)$selected_notification['title']); ?></h2>
                <div class="customer-notifications-time"><?php echo htmlspecialchars((string)$selected_notification['created_at']); ?></div>
            </div>
            <?php if ((int)$selected_notification['is_read'] === 0): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="mark_read">
                <input type="hidden" name="notification_id" value="<?php echo (int)$selected_notification['id']; ?>">
                <button type="submit" class="market-btn market-btn-soft">Mark as read</button>
            </form>
            <?php endif; ?>
        </div>
        <p style="margin:16px 0 0;color:#475467;white-space:pre-line;line-height:1.6;"><?php echo htmlspecialchars((string)$selected_notification['message']); ?></p>
        <?php if ($selected_notification['related_type'] === 'order' && (int)$selected_notification['related_id'] > 0): ?>
            <a class="market-btn" href="my_orders.php?highlight_order=<?php echo (int)$selected_notification['related_id']; ?>">View order</a>
        <?php elseif ($selected_notification['related_type'] === 'pre_order' && (int)$selected_notification['related_id'] > 0): ?>
            <a class="market-btn" href="my_orders.php?tab=preorders">View pre-order</a>
        <?php elseif ($selected_notification['related_type'] === 'franchise_application'): ?>
            <a class="market-btn" href="franchise_application.php">View application</a>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
        <div class="customer-notifications-empty"><i class="fas fa-bell-slash fa-2x" style="margin-bottom:12px;"></i><div>No notifications yet.</div></div>
    <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
        <a class="customer-notifications-card <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?> <?php echo (int)$notification['id'] === $notification_id ? 'selected' : ''; ?>" href="my_notifications.php?id=<?php echo (int)$notification['id']; ?>">
            <span class="customer-notifications-icon"><i class="fas fa-bell"></i></span>
            <span class="customer-notifications-body">
                <span class="customer-notifications-title d-block"><?php echo htmlspecialchars((string)$notification['title']); ?></span>
                <span class="customer-notifications-message d-block"><?php echo htmlspecialchars((string)$notification['message']); ?></span>
                <span class="customer-notifications-time d-block"><?php echo htmlspecialchars((string)$notification['created_at']); ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
