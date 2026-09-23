<?php
$page_title = 'Help & Support';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
$rider_pk = (int)$rider['id'];

$sub_msg = '';
$sub_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $category = trim($_POST['issue_category'] ?? 'other');
    $description = trim($_POST['description'] ?? '');

    if (empty($description)) {
        $sub_err = 'Please provide details about the problem.';
    } else {
        $ins = mysqli_prepare($conn, "
            INSERT INTO rider_support_tickets (rider_id, order_id, issue_category, description, status, created_at)
            VALUES (?, ?, ?, ?, 'open', NOW())
        ");
        mysqli_stmt_bind_param($ins, "iiss", $rider_pk, $order_id, $category, $description);
        $ok = mysqli_stmt_execute($ins);
        $t_id = mysqli_stmt_insert_id($ins);
        mysqli_stmt_close($ins);

        if ($ok) {
            $sub_msg = "Support Ticket #$t_id opened successfully. Dispatch will review immediately.";
        } else {
            $sub_err = 'Failed to submit ticket: ' . mysqli_error($conn);
        }
    }
}

// Fetch active orders for dropdown
$my_orders_q = mysqli_query($conn, "
    SELECT lt.order_id, o.order_number, o.customer_name
    FROM logistics_tracking lt
    JOIN orders o ON lt.order_id = o.id
    WHERE lt.driver_id = $rider_pk
    ORDER BY lt.id DESC
    LIMIT 10
");

// Fetch tickets
$tickets_q = mysqli_query($conn, "
    SELECT t.*, o.order_number
    FROM rider_support_tickets t
    LEFT JOIN orders o ON t.order_id = o.id
    WHERE t.rider_id = $rider_pk
    ORDER BY t.created_at DESC
    LIMIT 30
");

require_once __DIR__ . '/header.php';
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-headset text-danger me-2"></i>Rider Help & Support</h5>
        <span class="badge" style="background:#eff8ff; color:#175cd3; font-weight:700;">24/7 Dispatch Desk</span>
    </div>

    <!-- Emergency Hotline Banner -->
    <div class="rider-card p-3 mb-3" style="background:#fff1f0; border-color:#fee4e2;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background:#b3261e; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size: 18px; flex-shrink: 0;">
                    <i class="fas fa-ambulance"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold text-danger" style="font-size: 13.5px;">Accident / Road Emergency?</h6>
                    <div class="text-muted" style="font-size: 11px;">Tap to call central emergency operations hotline immediately.</div>
                </div>
            </div>
            <a href="tel:09171234567" class="btn btn-sm btn-danger p-2 px-3 fw-bold ms-auto ms-sm-0" style="background:#b3261e; border-color:#b3261e; border-radius: 8px;">
                <i class="fas fa-phone-alt me-1"></i> Call Emergency Hotline
            </a>
        </div>
    </div>

    <?php if ($sub_msg): ?>
        <div class="alert alert-success py-2 px-3 small mb-3"><?php echo htmlspecialchars($sub_msg); ?></div>
    <?php endif; ?>
    <?php if ($sub_err): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3"><?php echo htmlspecialchars($sub_err); ?></div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <!-- Left Column: Submit Ticket Form -->
        <div class="col-12 col-lg-5">
            <!-- Create Ticket Form (Section 18) -->
            <div class="rider-card mb-3 p-3">
                <h6 class="fw-bold mb-3 text-dark" style="font-size: 13.5px;"><i class="fas fa-ticket-alt text-danger me-1"></i> Submit a Support Ticket</h6>
                <form method="POST" action="support.php">
                    <div class="mb-2">
                        <label class="form-label small mb-1 fw-semibold text-dark">Related Order (Optional)</label>
                        <select name="order_id" class="form-select form-select-sm">
                            <option value="0">-- General Issue (Not Order Specific) --</option>
                            <?php if ($my_orders_q): while ($ord = mysqli_fetch_assoc($my_orders_q)): ?>
                                <option value="<?php echo (int)$ord['order_id']; ?>">Order #<?php echo htmlspecialchars($ord['order_number']); ?> (<?php echo htmlspecialchars($ord['customer_name']); ?>)</option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small mb-1 fw-semibold text-dark">Problem Category</label>
                        <select name="issue_category" class="form-select form-select-sm" required>
                            <option value="customer_unavailable">Customer Unavailable / Phone Unreachable</option>
                            <option value="restaurant_problem">Restaurant Delay / Store Problem</option>
                            <option value="wrong_address">Wrong Address / Inaccessible Area</option>
                            <option value="damaged_food">Food Spilled or Damaged</option>
                            <option value="vehicle_problem">Motorcycle Breakdown / Flat Tire</option>
                            <option value="payment_problem">Payment Dispute / Short Cash</option>
                            <option value="cod_problem">COD Remittance Issue</option>
                            <option value="emergency">Personal Emergency</option>
                            <option value="other">Other Inquiry</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark">Describe the Situation</label>
                        <textarea name="description" class="form-control form-control-sm" rows="4" placeholder="Provide full details so dispatch can resolve quickly..." required></textarea>
                    </div>

                    <button type="submit" class="btn-rider-primary py-2" style="font-size: 13px;">
                        <i class="fas fa-paper-plane me-1"></i> Submit Ticket to Dispatch
                    </button>
                </form>
            </div>
        </div>

        <!-- Right Column: Ticket History -->
        <div class="col-12 col-lg-7">
            <!-- Ticket History (Section 18) -->
            <div class="rider-card p-0 mb-3" style="overflow: hidden;">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold" style="font-size: 13px;">My Support Tickets</h6>
                    <span class="text-muted" style="font-size: 11px;"><?php echo mysqli_num_rows($tickets_q); ?> tickets</span>
                </div>

                <?php if ($tickets_q && mysqli_num_rows($tickets_q) > 0): ?>
                    <?php while ($t = mysqli_fetch_assoc($tickets_q)): 
                        $badge_class = 'bg-warning text-dark';
                        if ($t['status'] === 'resolved' || $t['status'] === 'closed') $badge_class = 'bg-success text-white';
                        elseif ($t['status'] === 'in_progress') $badge_class = 'bg-info text-white';
                    ?>
                        <div class="p-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div>
                                    <strong class="text-dark" style="font-size: 13px;">Ticket #<?php echo $t['id']; ?>: <?php echo ucwords(str_replace('_', ' ', $t['issue_category'])); ?></strong>
                                    <?php if (!empty($t['order_number'])): ?>
                                        <div class="text-muted" style="font-size: 11px;">Order #<?php echo htmlspecialchars($t['order_number']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge rounded-pill <?php echo $badge_class; ?>" style="font-size: 10px; text-transform: uppercase;">
                                    <?php echo htmlspecialchars($t['status']); ?>
                                </span>
                            </div>

                            <p class="text-muted mb-2 small" style="font-size: 12px; line-height: 1.4;">
                                <?php echo htmlspecialchars($t['description']); ?>
                            </p>

                            <?php if (!empty($t['admin_response'])): ?>
                                <div class="p-2 rounded mb-1" style="background:#f2f4f7; border-left: 3px solid #b3261e; font-size: 11.5px;">
                                    <strong>Dispatch Response:</strong> <?php echo htmlspecialchars($t['admin_response']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="text-muted" style="font-size: 10px;">
                                Logged on <?php echo date('M d, Y h:i A', strtotime($t['created_at'])); ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="p-4 text-center text-muted small">
                        <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                        <div>No open support issues. Drive safely!</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
