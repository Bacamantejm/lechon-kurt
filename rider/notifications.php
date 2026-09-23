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
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-bell text-danger me-2"></i>Notifications</h5>
        <a href="notifications.php?action=mark_all_read" class="small text-decoration-none fw-semibold" style="color: var(--primary-red); font-size: 12px;">
            Mark all as read
        </a>
    </div>

    <!-- Notification Cards Feed (Section 16) -->
    <?php if ($notifs_q && mysqli_num_rows($notifs_q) > 0): ?>
        <?php while ($n = mysqli_fetch_assoc($notifs_q)): 
            $icon = 'fa-info-circle';
            $bg_color = '#f8f9fa';
            if ($n['type'] === 'delivery_request') { $icon = 'fa-motorcycle'; $bg_color = '#fff1f0'; }
            elseif ($n['type'] === 'earnings') { $icon = 'fa-coins'; $bg_color = '#ecfdf3'; }
            elseif ($n['type'] === 'cod_remittance') { $icon = 'fa-money-bill-wave'; $bg_color = '#fffaeb'; }
        ?>
            <div class="rider-card p-3 mb-2 <?php echo empty($n['is_read']) ? 'border-danger-subtle' : ''; ?>" style="background: <?php echo empty($n['is_read']) ? '#ffffff' : '#fafafa'; ?>;">
                <div class="d-flex align-items-start gap-3">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: <?php echo $bg_color; ?>; display:flex; align-items:center; justify-content:center; font-size: 15px;">
                        <i class="fas <?php echo $icon; ?> text-danger"></i>
                    </div>
                    <div style="flex: 1;">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="mb-1 fw-bold text-dark" style="font-size: 13px;">
                                <?php echo htmlspecialchars($n['title']); ?>
                            </h6>
                            <?php if (empty($n['is_read'])): ?>
                                <span class="badge rounded-pill bg-danger" style="font-size: 8px;">NEW</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted mb-1" style="font-size: 12px; line-height: 1.4;">
                            <?php echo htmlspecialchars($n['message']); ?>
                        </p>
                        <div class="text-muted" style="font-size: 10px;">
                            <?php echo date('M d, h:i A', strtotime($n['created_at'])); ?>
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
