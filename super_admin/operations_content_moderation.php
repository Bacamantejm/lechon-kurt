<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_content_moderation.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'queue_content') {
        if ($opsService->queueContentItem($_POST, $current_admin_id)) {
            saSetFlash('success', 'Content item queued for moderation.');
        } else {
            saSetFlash('danger', 'Unable to queue content item.');
        }
    } elseif ($action === 'review_content') {
        if ($opsService->reviewContentItem((int)($_POST['queue_id'] ?? 0), (string)($_POST['review_status'] ?? 'approved'), (string)($_POST['review_notes'] ?? ''), $current_admin_id)) {
            saSetFlash('success', 'Content moderation decision saved.');
        } else {
            saSetFlash('danger', 'Unable to save moderation decision.');
        }
    }

    header('Location: operations_content_moderation.php');
    exit;
}

$queue = $opsService->getContentQueue(25);

saRenderModuleHeader('Operations Content Moderation', 'Operations Content Moderation', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Queue Content for Review</h2>
            <p class="module-subtext">Log listings, media, or posts that require manual moderation.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="queue_content">
        <select name="content_type" class="form-select">
            <option value="listing">Listing</option><option value="product">Product</option><option value="media">Media</option><option value="announcement">Announcement</option>
        </select>
        <input type="number" name="content_id" class="form-control" placeholder="Content ID">
        <input type="number" name="shop_id" class="form-control" placeholder="Shop/User ID">
        <input type="number" name="risk_score" class="form-control" min="0" max="100" placeholder="Risk score">
        <input type="text" name="flag_reason" class="form-control" style="grid-column:1/-1;" placeholder="Why does this content need review?" required>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-flag"></i> Queue Review</button>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Moderation Queue</h3>
            <p class="module-subtext">Approve or reject content while preserving a clear review trail.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Content</th><th>Reason</th><th>Risk</th><th>Status</th><th>Decision</th></tr></thead>
            <tbody>
            <?php if (empty($queue)): ?>
                <tr><td colspan="5" class="text-center text-muted">No content queued for moderation.</td></tr>
            <?php else: foreach ($queue as $item): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars(ucfirst((string)$item['content_type'])); ?></strong> #<?php echo number_format((int)($item['content_id'] ?? 0)); ?><br><span class="compact-text">Submitted by <?php echo htmlspecialchars((string)($item['submitted_name'] ?? 'System')); ?></span></td>
                    <td><?php echo htmlspecialchars((string)$item['flag_reason']); ?></td>
                    <td><?php echo number_format((int)$item['risk_score']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$item['review_status'])); ?></td>
                    <td>
                        <form method="post" class="module-inline-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="review_content">
                            <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                            <select name="review_status" class="form-select form-select-sm">
                                <option value="approved">Approve</option>
                                <option value="rejected">Reject</option>
                            </select>
                            <input type="text" name="review_notes" class="form-control form-control-sm" placeholder="Notes">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

