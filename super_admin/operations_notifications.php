<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_notifications.php');
    if (($action = strtolower(trim((string)($_POST['action'] ?? '')))) === 'create_announcement') {
        if ($opsService->createAnnouncement($_POST, $current_admin_id)) {
            saSetFlash('success', 'Operational announcement saved.');
        } else {
            saSetFlash('danger', 'Unable to save announcement.');
        }
    }
    header('Location: operations_notifications.php');
    exit;
}

$announcements = $opsService->getAnnouncements(25);

saRenderModuleHeader('Operations Notifications', 'Operations Notifications', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Create Announcement</h2>
            <p class="module-subtext">Broadcast updates, approvals, advisories, or issue notices to the right audience.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="create_announcement">
        <select name="audience_type" class="form-select">
            <option value="all">All</option><option value="users">Users</option><option value="businesses">Businesses</option><option value="staff">Staff</option>
        </select>
        <select name="delivery_channel" class="form-select">
            <option value="in_app">In-App</option><option value="email">Email</option>
        </select>
        <select name="status" class="form-select">
            <option value="draft">Save as Draft</option><option value="sent">Send Now</option>
        </select>
        <input type="text" name="title" class="form-control" style="grid-column:1/-1;" placeholder="Announcement title" required>
        <textarea name="message" class="form-control" style="grid-column:1/-1;" rows="4" placeholder="What should users or businesses know?" required></textarea>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-bullhorn"></i> Save Announcement</button>
            <a href="../super_admin/notification_center.php" class="btn btn-outline-primary">Open Existing Notification Center</a>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Announcement History</h3>
            <p class="module-subtext">Track drafts, sent notices, and delivery targeting choices.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Audience</th><th>Title</th><th>Status</th><th>Channel</th><th>Owner</th><th>Created</th></tr></thead>
            <tbody>
            <?php if (empty($announcements)): ?>
                <tr><td colspan="6" class="text-center text-muted">No announcements yet.</td></tr>
            <?php else: foreach ($announcements as $announcement): ?>
                <tr>
                    <td><?php echo htmlspecialchars(ucfirst((string)$announcement['audience_type'])); ?></td>
                    <td><strong><?php echo htmlspecialchars((string)$announcement['title']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)$announcement['message']); ?></span></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$announcement['status'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$announcement['delivery_channel']))); ?></td>
                    <td><?php echo htmlspecialchars((string)($announcement['created_name'] ?? 'System')); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($announcement['created_at'] ?? null)); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

