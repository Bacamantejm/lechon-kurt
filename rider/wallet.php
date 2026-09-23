<?php
$page_title = 'COD Cash Management';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
$rider_pk = (int)$rider['id'];

// Handle remittance submission
$remit_msg = '';
$remit_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remit_cod') {
    $amount = (float)($_POST['remit_amount'] ?? 0.0);
    $ref = trim($_POST['remit_reference'] ?? '');

    if ($amount <= 0) {
        $remit_err = 'Please enter a valid remittance amount.';
    } elseif (empty($ref)) {
        $remit_err = 'Please provide a deposit reference number or shop cashier receipt code.';
    } else {
        // Mark pending COD collections as remitted up to the amount
        $remit_stmt = mysqli_prepare($conn, "
            UPDATE cod_collections
            SET remittance_status = 'remitted',
                remitted_amount = cash_received,
                remittance_reference = ?,
                remitted_at = NOW()
            WHERE rider_id = ?
              AND remittance_status = 'pending'
            LIMIT 50
        ");
        mysqli_stmt_bind_param($remit_stmt, "si", $ref, $rider_pk);
        $ok = mysqli_stmt_execute($remit_stmt);
        mysqli_stmt_close($remit_stmt);

        // Notify fleet
        mysqli_query($conn, "
            INSERT INTO rider_notifications (rider_id, title, message, type)
            VALUES ($rider_pk, 'COD Remittance Submitted', 'Remittance of ₱" . number_format($amount, 2) . " (Ref: $ref) submitted for admin verification.', 'cod_remittance')
        ");

        $remit_msg = 'COD remittance submitted successfully for verification.';
    }
}

// COD Aggregates
$cod_stats_q = mysqli_query($conn, "
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(collected_at) = CURDATE() THEN cash_received ELSE 0 END), 0) AS cod_today,
        COALESCE(SUM(CASE WHEN remittance_status IN ('remitted', 'verified') THEN remitted_amount ELSE 0 END), 0) AS cod_remitted_all,
        COALESCE(SUM(CASE WHEN remittance_status = 'pending' THEN cash_received ELSE 0 END), 0) AS remaining_cod
    FROM cod_collections
    WHERE rider_id = $rider_pk
");
$cod_stats = mysqli_fetch_assoc($cod_stats_q);

// Transaction History
$cod_trans_q = mysqli_query($conn, "
    SELECT cc.*, o.order_number, o.customer_name
    FROM cod_collections cc
    JOIN orders o ON cc.order_id = o.id
    WHERE cc.rider_id = $rider_pk
    ORDER BY cc.collected_at DESC
    LIMIT 50
");

require_once __DIR__ . '/header.php';
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-money-bill-wave text-success me-2"></i>COD Cash Wallet</h5>
        <span class="badge" style="background:#fffaeb; color:#b54708; font-weight:700;">Remittance Portal</span>
    </div>

    <!-- Separation Disclaimer Banner (Section 14) -->
    <div class="p-3 rounded mb-3" style="background:#eff8ff; border:1px solid #b2ddff; font-size:12px; color:#175cd3;">
        <i class="fas fa-shield-alt me-1"></i> <strong>Strict Distinction:</strong> Customer cash collected from COD orders belongs to the restaurant/platform and is tracked separately from your personal delivery earnings.
    </div>

    <?php if ($remit_msg): ?>
        <div class="alert alert-success py-2 px-3 small mb-3"><?php echo htmlspecialchars($remit_msg); ?></div>
    <?php endif; ?>
    <?php if ($remit_err): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3"><?php echo htmlspecialchars($remit_err); ?></div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <!-- Left Column: Financial Metrics & Remittance Form -->
        <div class="col-12 col-lg-5">
            <!-- COD Financial Metrics (Section 14) -->
            <div class="row g-2 mb-3">
                <div class="col-4 col-sm-4 col-lg-12">
                    <div class="rider-card p-3 text-center h-100 mb-lg-2">
                        <span class="text-muted d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Collected Today</span>
                        <strong style="color: #101828; font-size: 1.25rem;">₱<?php echo number_format($cod_stats['cod_today'], 2); ?></strong>
                    </div>
                </div>
                <div class="col-4 col-sm-4 col-lg-12">
                    <div class="rider-card p-3 text-center h-100 mb-lg-2">
                        <span class="text-muted d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Total Remitted</span>
                        <strong style="color: #027a48; font-size: 1.25rem;">₱<?php echo number_format($cod_stats['cod_remitted_all'], 2); ?></strong>
                    </div>
                </div>
                <div class="col-4 col-sm-4 col-lg-12">
                    <div class="rider-card p-3 text-center h-100" style="background:#fff8f8; border-color:#fee4e2;">
                        <span class="text-danger d-block" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">Remaining Due</span>
                        <strong style="color: #b3261e; font-size: 1.35rem;">₱<?php echo number_format($cod_stats['remaining_cod'], 2); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Remit Cash Form Card -->
            <?php if ($cod_stats['remaining_cod'] > 0): ?>
                <div class="rider-card mb-3 p-3">
                    <h6 class="fw-bold mb-2 text-dark" style="font-size: 13.5px;"><i class="fas fa-hand-holding-usd text-danger me-1"></i> Remit Pending COD Cash</h6>
                    <form method="POST" action="wallet.php">
                        <input type="hidden" name="action" value="remit_cod">
                        <div class="mb-2">
                            <label class="form-label small mb-1 fw-semibold">Remittance Amount (₱)</label>
                            <input type="number" step="0.01" name="remit_amount" class="form-control form-control-sm fw-bold" value="<?php echo $cod_stats['remaining_cod']; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1 fw-semibold">Deposit Reference / Cashier Name</label>
                            <input type="text" name="remit_reference" class="form-control form-control-sm" placeholder="e.g. GCash Ref #12345 or Cashier Justine" required>
                        </div>
                        <button type="submit" class="btn-rider-primary py-2" style="font-size: 13px;">
                            <i class="fas fa-check-circle me-1"></i> Submit Remittance for Verification
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: COD Collections Log -->
        <div class="col-12 col-lg-7">
            <!-- COD Collections History (Section 14) -->
            <div class="rider-card p-0 mb-3" style="overflow: hidden;">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold" style="font-size: 13px;">COD Collections Log</h6>
                    <span class="text-muted" style="font-size: 11px;"><?php echo mysqli_num_rows($cod_trans_q); ?> records</span>
                </div>

                <?php if ($cod_trans_q && mysqli_num_rows($cod_trans_q) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 12px;">
                            <thead style="background:#f8f9fa;">
                                <tr>
                                    <th class="ps-3">Order / Customer</th>
                                    <th class="text-end">Collected</th>
                                    <th class="text-end">Change</th>
                                    <th class="text-center pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($cod_trans_q)): 
                                    $status_badge = 'bg-warning text-dark';
                                    if ($row['remittance_status'] === 'remitted') $status_badge = 'bg-info text-white';
                                    if ($row['remittance_status'] === 'verified') $status_badge = 'bg-success text-white';
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <strong class="text-dark">#<?php echo htmlspecialchars($row['order_number']); ?></strong>
                                            <div class="text-muted" style="font-size: 10.5px;"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                            <div class="text-muted" style="font-size: 9.5px;"><?php echo date('M d, h:i A', strtotime($row['collected_at'])); ?></div>
                                        </td>
                                        <td class="text-end fw-bold text-dark">₱<?php echo number_format($row['cash_received'], 2); ?></td>
                                        <td class="text-end text-muted">₱<?php echo number_format($row['change_given'], 2); ?></td>
                                        <td class="text-center pe-3">
                                            <span class="badge <?php echo $status_badge; ?>" style="font-size: 10px; text-transform: uppercase;">
                                                <?php echo htmlspecialchars($row['remittance_status']); ?>
                                            </span>
                                            <?php if (!empty($row['remittance_reference'])): ?>
                                                <div class="text-muted" style="font-size: 9px;">Ref: <?php echo htmlspecialchars($row['remittance_reference']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-center text-muted small">
                        <i class="fas fa-coins fa-2x mb-2 text-muted"></i>
                        <div>No COD orders collected yet.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
