<?php
$page_title = 'Rider Notifications';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
$rider_pk = (int)$rider['id'];

// Handle mark all read
if (isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
    mysqli_query($conn, "UPDATE rider_notifications SET is_read = 1 WHERE rider_id = $rider_pk");
    header("Location: notifications.php");
    exit;
}

$notifs_q = mysqli_query($conn, "
    SELECT * FROM rider_notifications
    WHERE rider_id = $rider_pk
    ORDER BY created_at DESC
    LIMIT 40
");

require_once __DIR__ . '/header.php';
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <!-- Header with Glass Pill Action -->
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <div class="d-flex align-items-center gap-2">
            <div class="rider-glass-icon-btn text-danger" style="width: 36px; height: 36px; pointer-events: none;">
                <i class="fas fa-bell"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-0" style="color: var(--primary-ink); font-size: 1.15rem; line-height: 1.2;">Notifications</h5>
                <span class="text-muted" style="font-size: 11px;">Dispatches, wallet credits & shift alerts</span>
            </div>
        </div>
        <a href="notifications.php?action=mark_all_read" class="rider-glass-pill" style="font-size: 11.5px; padding: 6px 12px; color: var(--primary-red);">
            <i class="fas fa-check-double me-1"></i> Mark all read
        </a>
    </div>

    <!-- Notification Cards Feed (Section 16) -->
    <?php if ($notifs_q && mysqli_num_rows($notifs_q) > 0): ?>
        <?php while ($n = mysqli_fetch_assoc($notifs_q)): 
            $icon = 'fa-info-circle';
            $icon_color = '#175cd3';
            $icon_bg = 'rgba(239, 248, 255, 0.9)';
            $icon_border = 'rgba(178, 221, 255, 0.8)';
            if ($n['type'] === 'delivery_request') {
                $icon = 'fa-motorcycle';
                $icon_color = '#b3261e';
                $icon_bg = 'rgba(255, 241, 240, 0.9)';
                $icon_border = 'rgba(254, 228, 226, 0.8)';
            } elseif ($n['type'] === 'earnings') {
                $icon = 'fa-coins';
                $icon_color = '#027a48';
                $icon_bg = 'rgba(236, 253, 243, 0.9)';
                $icon_border = 'rgba(171, 239, 198, 0.8)';
            } elseif ($n['type'] === 'cod_remittance') {
                $icon = 'fa-money-bill-wave';
                $icon_color = '#b54708';
                $icon_bg = 'rgba(255, 250, 235, 0.9)';
                $icon_border = 'rgba(254, 223, 137, 0.8)';
            }
            $is_unread = empty($n['is_read']);
        ?>
            <div class="rider-card rider-notif-card p-3 mb-2 <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>">
                <div class="d-flex align-items-start gap-3">
                    <div class="notif-icon-box" style="background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>; border-color: <?php echo $icon_border; ?>;">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="d-flex justify-content-between align-items-center mb-1 gap-2">
                            <h6 class="mb-0 fw-bold text-dark text-truncate" style="font-size: 13.5px;">
                                <?php echo htmlspecialchars($n['title']); ?>
                            </h6>
                            <?php if ($is_unread): ?>
                                <span class="notif-badge-new">NEW</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mb-1.5" style="font-size: 12px; line-height: 1.45; word-wrap: break-word;">
                            <?php echo htmlspecialchars($n['message']); ?>
                        </p>
                        <div class="d-flex align-items-center gap-1 text-muted" style="font-size: 10.5px;">
                            <i class="far fa-clock"></i>
                            <span><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="rider-card p-4 text-center text-muted small">
            <i class="fas fa-bell-slash fa-2x mb-2 text-muted"></i>
            <div>No notifications yet. New dispatches and payment alerts will arrive here.</div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>