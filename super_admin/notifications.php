<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'notifications.php';
$has_notifications = saTableExists($conn, 'notifications');
$user_id = (int)$current_admin_id;
$selected_id = (int)($_GET['id'] ?? 0);

function saNotificationDestination(array $notification): string
{
    $related_type = strtolower(trim((string)($notification['related_type'] ?? '')));
    $related_id = (int)($notification['related_id'] ?? 0);
    $type = strtolower(trim((string)($notification['type'] ?? '')));

    if (
        in_array($related_type, ['franchise_application', 'business_application', 'application'], true)
        || strpos($type, 'franchise') !== false
        || strpos($type, 'business_application') !== false
    ) {
        return '../super_admin/franchise_applications.php' . ($related_id > 0 ? '?search=' . $related_id : '');
    }
    if ($related_type === 'order' || strpos($type, 'order') !== false) {
        return '../super_admin/transactions_financial.php';
    }
    if ($related_type === 'user') {
        return '../super_admin/user_business_management.php';
    }
    return '../super_admin/notifications.php?id=' . (int)($notification['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($has_notifications && $action === 'mark_read') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ii", $notification_id, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($has_notifications && $action === 'mark_all_read') {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: ' . $page_file . ($selected_id > 0 ? '?id=' . $selected_id : ''));
    exit;
}

$notifications = [];
if ($has_notifications) {
    $stmt = mysqli_prepare($conn, "SELECT id, type, title, message, related_id, related_type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
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
    if ((int)$notification['id'] === $selected_id) {
        $selected_notification = $notification;
        break;
    }
}

saRenderModuleHeader('Notifications', 'Platform Notifications', $admin_info);
?>
<style>
    .sa-message-page { max-width:1080px; }
    .sa-message-toolbar { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
    .sa-message-toolbar h2 { margin:0; color:#101828; font-size:1.35rem; }
    .sa-message-card, .sa-message-detail, .sa-message-empty { background:#fff; border:1px solid #eaecf0; border-radius:12px; box-shadow:0 1px 3px rgba(16,24,40,.04); }
    .sa-message-card { display:flex; gap:14px; padding:16px; margin-bottom:10px; color:#101828; text-decoration:none; }
    .sa-message-card:hover, .sa-message-card.selected { border-color:#b3261e; }
    .sa-message-card.unread { border-left:3px solid #b3261e; }
    .sa-message-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:#fff1f0; color:#b3261e; flex:0 0 auto; }
    .sa-message-body { min-width:0; flex:1; }
    .sa-message-title { font-weight:700; margin-bottom:4px; }
    .sa-message-copy { color:#475467; white-space:pre-line; line-height:1.5; }
    .sa-message-time { color:#667085; font-size:.78rem; margin-top:7px; }
    .sa-message-detail, .sa-message-empty { padding:20px; margin-bottom:18px; }
    .sa-message-empty { padding:42px 20px; text-align:center; color:#667085; }
    @media (max-width:576px) { .sa-message-toolbar { align-items:flex-start; flex-direction:column; } }
</style>
<main class="sa-message-page">
    <div class="sa-message-toolbar">
        <div>
            <h2>Platform Notifications</h2>
            <p class="module-subtext mb-0">Updates and messages addressed to the system owner.</p>
        </div>
        <?php if (!empty($notifications)): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-outline-primary btn-sm">Mark all as read</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($selected_notification): ?>
    <section class="sa-message-detail">
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

    <?php if (!$has_notifications || empty($notifications)): ?>
        <div class="sa-message-empty"><i class="fas fa-bell-slash fa-2x mb-3"></i><div>No platform notifications yet.</div></div>
    <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
        <a class="sa-message-card <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?> <?php echo (int)$notification['id'] === $selected_id ? 'selected' : ''; ?>" href="<?php echo htmlspecialchars(saNotificationDestination($notification)); ?>">
            <span class="sa-message-icon"><i class="fas fa-bell"></i></span>
            <span class="sa-message-body">
                <span class="sa-message-title d-block"><?php echo htmlspecialchars((string)$notification['title']); ?></span>
                <span class="sa-message-copy d-block"><?php echo htmlspecialchars((string)$notification['message']); ?></span>
                <span class="sa-message-time d-block"><?php echo htmlspecialchars((string)$notification['created_at']); ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php saRenderModuleFooter(); ?>
