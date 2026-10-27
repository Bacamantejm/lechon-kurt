<?php
session_start();
require_once 'includes/config.php';

$order_id = (int)($_SESSION['order_success']['order_id'] ?? ($_GET['order_id'] ?? 0));
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Check if order success data or valid order id exists
if (!isset($_SESSION['order_success']) && $order_id <= 0) {
    header('Location: menu.php');
    exit;
}

$db_order = null;
if ($order_id > 0 && isset($conn) && $conn instanceof mysqli) {
    if ($user_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $db_order = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $db_order = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }
    }
}

if (!$db_order && !isset($_SESSION['order_success'])) {
    header('Location: menu.php');
    exit;
}

$order_number = $db_order['order_number'] ?? ($_SESSION['order_success']['order_number'] ?? '');
$total_amount = (float)($db_order['total_amount'] ?? ($_SESSION['order_success']['total_amount'] ?? 0));
$amount_paid = (float)($_SESSION['order_success']['amount_paid'] ?? 0);
$payment_method = strtolower((string)($db_order['payment_method'] ?? ($_SESSION['order_success']['payment_method'] ?? 'paymongo')));
$payment_type = $_SESSION['order_success']['payment_type'] ?? 'full';
$payment_status = strtolower((string)($db_order['payment_status'] ?? 'pending'));
$delivery_option = strtolower((string)($db_order['delivery_option'] ?? 'delivery'));
$delivery_address = $db_order['delivery_address'] ?? '';
$customer_name = $db_order['customer_name'] ?? '';
$customer_phone = $db_order['customer_phone'] ?? '';
$downpayment_amount = (float)($db_order['downpayment_amount'] ?? ($_SESSION['order_success']['downpayment_amount'] ?? 0));
$remaining_balance = (float)($db_order['remaining_balance'] ?? ($_SESSION['order_success']['remaining_balance'] ?? 0));

$is_cod = (stripos($payment_method, 'cod') !== false);
$is_pickup = ($delivery_option === 'pickup');

$page_title = "Order Confirmed | Lechon Delights";
include 'includes/header.php';
?>

<section class="success-section">
    <div class="container">
        <div class="success-card">
            <div class="success-icon-badge">
                <i class="fas fa-check"></i>
            </div>
            
            <h1 class="success-title">
                <?php 
                if ($is_cod) {
                    echo $is_pickup ? 'Pickup Order Confirmed!' : 'Cash on Delivery Order Confirmed!';
                } else {
                    echo 'Thank You for Your Order!';
                }
                ?>
            </h1>

            <div class="order-number-pill">
                <span>Order Reference:</span>
                <strong>#<?php echo htmlspecialchars($order_number); ?></strong>
            </div>

            <!-- Main Payment Summary Banner -->
            <?php if ($is_cod): ?>
                <?php if ($is_pickup): ?>
                    <div class="payment-highlight-box highlight-pickup">
                        <span class="highlight-label">Total Cash Due at Pickup Counter</span>
                        <div class="highlight-amount">PHP <?php echo number_format($total_amount, 2); ?></div>
                        <p class="highlight-desc">
                            <i class="fas fa-store"></i> No online payment required. Please prepare exact cash when claiming your fresh lechon at the store counter.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="payment-highlight-box highlight-cod">
                        <span class="highlight-label">Total Cash Due upon Doorstep Delivery</span>
                        <div class="highlight-amount">PHP <?php echo number_format($total_amount, 2); ?></div>
                        <p class="highlight-desc">
                            <i class="fas fa-motorcycle"></i> No advance online payment required. Please prepare exact cash to hand directly to your delivery rider upon arrival.
                        </p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($payment_type === 'downpayment' || $payment_status === 'partial'): ?>
                    <div class="payment-highlight-box highlight-online-downpayment">
                        <span class="highlight-label">30% Downpayment Paid Online</span>
                        <div class="highlight-amount">PHP <?php echo number_format($amount_paid ?: $downpayment_amount, 2); ?></div>
                        <p class="highlight-desc">
                            <i class="fas fa-check-circle"></i> Downpayment confirmed via PayMongo. Remaining balance of <strong>PHP <?php echo number_format($remaining_balance, 2); ?></strong> is due upon <?php echo $is_pickup ? 'pickup' : 'delivery'; ?>.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="payment-highlight-box highlight-online-full">
                        <span class="highlight-label">Amount Paid Online (PayMongo)</span>
                        <div class="highlight-amount">PHP <?php echo number_format($amount_paid ?: $total_amount, 2); ?></div>
                        <p class="highlight-desc">
                            <i class="fas fa-check-circle"></i> Payment completed securely. No additional cash needed upon <?php echo $is_pickup ? 'pickup' : 'delivery'; ?>.
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Order Details Breakdown Grid -->
            <div class="order-details-grid">
                <div class="details-item">
                    <span class="details-label">Fulfillment Mode</span>
                    <strong class="details-value">
                        <i class="<?php echo $is_pickup ? 'fas fa-store' : 'fas fa-truck-fast'; ?>" style="color: #b3261e; margin-right: 4px;"></i>
                        <?php echo $is_pickup ? 'Store Pickup' : 'Home Delivery'; ?>
                    </strong>
                </div>

                <div class="details-item">
                    <span class="details-label">Payment Mode</span>
                    <strong class="details-value">
                        <?php if ($is_cod): ?>
                            <i class="<?php echo $is_pickup ? 'fas fa-hand-holding-dollar' : 'fas fa-money-bill-wave'; ?>" style="color: #027a48; margin-right: 4px;"></i>
                            <?php echo $is_pickup ? 'Cash on Pickup' : 'Cash on Delivery (COD)'; ?>
                        <?php else: ?>
                            <i class="fas fa-credit-card" style="color: #175cd3; margin-right: 4px;"></i>
                            Online Payment (PayMongo)
                        <?php endif; ?>
                    </strong>
                </div>

                <div class="details-item">
                    <span class="details-label">Payment Status</span>
                    <div class="details-value">
                        <?php if ($is_cod): ?>
                            <span class="status-pill status-warning">
                                <i class="fas fa-clock"></i> Cash Due upon Arrival
                            </span>
                        <?php elseif ($payment_type === 'downpayment' || $payment_status === 'partial'): ?>
                            <span class="status-pill status-info">
                                <i class="fas fa-check"></i> Partial (30% Paid)
                            </span>
                        <?php else: ?>
                            <span class="status-pill status-success">
                                <i class="fas fa-check-circle"></i> Fully Paid
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="details-item">
                    <span class="details-label">Total Order Value</span>
                    <strong class="details-value" style="color: #b3261e; font-size: 1.15rem;">
                        PHP <?php echo number_format($total_amount, 2); ?>
                    </strong>
                </div>

                <?php if (!$is_pickup && !empty($delivery_address)): ?>
                <div class="details-item full-width">
                    <span class="details-label">Delivery Address</span>
                    <div class="details-value" style="font-weight: 500; color: #344054;">
                        <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 6px;"></i>
                        <?php echo htmlspecialchars($delivery_address); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Step by Step What Happens Next -->
            <div class="next-steps-section">
                <h3 class="section-heading">What Happens Next?</h3>
                <div class="steps-flow-grid">
                    <?php if ($is_cod && !$is_pickup): ?>
                        <div class="flow-card">
                            <div class="flow-step-num">1</div>
                            <div class="flow-step-body">
                                <h4>Order Confirmed</h4>
                                <p>Our kitchen immediately prepares and roasts your fresh lechon order.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">2</div>
                            <div class="flow-step-body">
                                <h4>Rider Dispatched</h4>
                                <p>A nearby delivery rider is assigned with turn-by-turn road navigation.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">3</div>
                            <div class="flow-step-body">
                                <h4>Doorstep Cash Payment</h4>
                                <p>Receive your fresh lechon and hand <strong>PHP <?php echo number_format($total_amount, 2); ?></strong> cash to the rider.</p>
                            </div>
                        </div>
                    <?php elseif ($is_cod && $is_pickup): ?>
                        <div class="flow-card">
                            <div class="flow-step-num">1</div>
                            <div class="flow-step-body">
                                <h4>Order Confirmed</h4>
                                <p>Our store kitchen confirms and prepares your order fresh for pickup.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">2</div>
                            <div class="flow-step-body">
                                <h4>Freshly Packed</h4>
                                <p>Your order is kept hot and sealed ready at the counter.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">3</div>
                            <div class="flow-step-body">
                                <h4>Counter Cash Payment</h4>
                                <p>Present order #<strong><?php echo htmlspecialchars($order_number); ?></strong> and pay <strong>PHP <?php echo number_format($total_amount, 2); ?></strong> cash at the cashier.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="flow-card">
                            <div class="flow-step-num">1</div>
                            <div class="flow-step-body">
                                <h4>Payment Cleared</h4>
                                <p>Your online payment is securely processed and confirmed.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">2</div>
                            <div class="flow-step-body">
                                <h4>Food Preparation</h4>
                                <p>Our kitchen cooks and packs your authentic lechon fresh.</p>
                            </div>
                        </div>
                        <div class="flow-card">
                            <div class="flow-step-num">3</div>
                            <div class="flow-step-body">
                                <h4><?php echo $is_pickup ? 'Ready for Pickup' : 'Prompt Delivery'; ?></h4>
                                <p><?php echo $is_pickup ? 'Head to the branch counter to claim your food.' : 'Enjoy your hot lechon delivered straight to your door.'; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Primary CTAs -->
            <div class="success-actions-wrap">
                <?php if (!$is_pickup && $order_id > 0): ?>
                    <a href="track_order.php?order_id=<?php echo $order_id; ?>" class="btn-success-primary">
                        <i class="fas fa-location-dot"></i> Track Live Delivery
                    </a>
                <?php endif; ?>
                <a href="my_orders.php" class="<?php echo ($is_pickup || $order_id <= 0) ? 'btn-success-primary' : 'btn-success-secondary'; ?>">
                    <i class="fas fa-receipt"></i> View My Orders
                </a>
                <a href="menu.php" class="btn-success-secondary">
                    <i class="fas fa-utensils"></i> Order More
                </a>
            </div>
        </div>
    </div>
</section>

<style>
/* Clean E-Commerce Design System Tokens */
:root {
    --brand-primary: #b3261e;
    --brand-hover: #981b15;
    --ink-primary: #101828;
    --ink-secondary: #344054;
    --ink-muted: #475467;
    --border-neutral: #eaecf0;
    --bg-page: #f8f9fa;
    --card-bg: #ffffff;
}

body {
    background-color: var(--bg-page) !important;
    color: var(--ink-primary);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.success-section {
    padding: 48px 16px 140px; /* Safe bottom padding for floating navigation/widgets */
}

.success-card {
    max-width: 760px;
    margin: 0 auto;
    background: var(--card-bg);
    border: 1px solid var(--border-neutral);
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    padding: 44px 36px;
    text-align: center;
}

.success-icon-badge {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #ecfdf3;
    color: #027a48;
    border: 2px solid #abefc6;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 20px;
}

.success-title {
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--ink-primary);
    margin: 0 0 12px;
    letter-spacing: -0.02em;
}

.order-number-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f2f4f7;
    border: 1px solid var(--border-neutral);
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 13.5px;
    color: var(--ink-muted);
    margin-bottom: 28px;
}

/* Payment Highlight Banner */
.payment-highlight-box {
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 28px;
    text-align: center;
}

.highlight-cod {
    background: #fffbfa;
    border: 1.5px solid #fee4e2;
}

.highlight-cod .highlight-label {
    color: #7a2e0e;
}

.highlight-cod .highlight-amount {
    color: #b3261e;
}

.highlight-pickup {
    background: #eff8ff;
    border: 1.5px solid #b2ddff;
}

.highlight-pickup .highlight-label {
    color: #175cd3;
}

.highlight-pickup .highlight-amount {
    color: #175cd3;
}

.highlight-online-full {
    background: #ecfdf3;
    border: 1.5px solid #abefc6;
}

.highlight-online-full .highlight-label {
    color: #027a48;
}

.highlight-online-full .highlight-amount {
    color: #027a48;
}

.highlight-online-downpayment {
    background: #eff8ff;
    border: 1.5px solid #b2ddff;
}

.highlight-online-downpayment .highlight-label {
    color: #175cd3;
}

.highlight-online-downpayment .highlight-amount {
    color: #175cd3;
}

.highlight-label {
    font-size: 12.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 6px;
}

.highlight-amount {
    font-size: 2.25rem;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 8px;
}

.highlight-desc {
    margin: 0;
    font-size: 13.5px;
    color: var(--ink-muted);
    line-height: 1.45;
}

/* Order Details Breakdown Grid */
.order-details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    background: #fafafa;
    border: 1px solid var(--border-neutral);
    border-radius: 12px;
    padding: 20px;
    text-align: left;
    margin-bottom: 32px;
}

.details-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.details-item.full-width {
    grid-column: 1 / -1;
    padding-top: 12px;
    border-top: 1px solid var(--border-neutral);
}

.details-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.details-value {
    font-size: 14.5px;
    color: var(--ink-primary);
    font-weight: 700;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    width: fit-content;
}

.status-warning {
    background: #fffaeb;
    color: #b54708;
    border: 1px solid #fedf89;
}

.status-success {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
}

.status-info {
    background: #eff8ff;
    color: #175cd3;
    border: 1px solid #b2ddff;
}

/* Next Steps Flow */
.next-steps-section {
    margin-bottom: 36px;
    text-align: left;
}

.section-heading {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--ink-primary);
    margin: 0 0 16px;
    text-align: center;
}

.steps-flow-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.flow-card {
    background: #ffffff;
    border: 1px solid var(--border-neutral);
    border-radius: 10px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.flow-step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #b3261e;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    flex-shrink: 0;
}

.flow-step-body h4 {
    margin: 0 0 4px;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink-primary);
}

.flow-step-body p {
    margin: 0;
    font-size: 12px;
    color: var(--ink-muted);
    line-height: 1.4;
}

/* Action Buttons */
.success-actions-wrap {
    display: flex;
    justify-content: center;
    gap: 14px;
    flex-wrap: wrap;
}

.btn-success-primary,
.btn-success-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.18s ease;
    cursor: pointer;
}

.btn-success-primary {
    background: var(--brand-primary);
    color: #ffffff;
    border: none;
}

.btn-success-primary:hover {
    background: var(--brand-hover);
    color: #ffffff;
}

.btn-success-secondary {
    background: #ffffff;
    color: var(--ink-secondary);
    border: 1px solid #d0d5dd;
}

.btn-success-secondary:hover {
    background: #f8f9fa;
    color: var(--ink-primary);
    border-color: #98a2b3;
}

@media (max-width: 680px) {
    .success-card {
        padding: 30px 20px;
    }

    .order-details-grid {
        grid-template-columns: 1fr;
    }

    .steps-flow-grid {
        grid-template-columns: 1fr;
    }

    .success-actions-wrap {
        flex-direction: column;
    }

    .btn-success-primary,
    .btn-success-secondary {
        width: 100%;
    }
}

/* Dark Theme Support */
body.dark-mode {
    background-color: #0f172a !important;
}

body.dark-mode .success-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .success-title,
body.dark-mode .section-heading,
body.dark-mode .flow-step-body h4 {
    color: #f8fafc !important;
}

body.dark-mode .order-number-pill {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .order-number-pill strong {
    color: #f8fafc !important;
}

body.dark-mode .order-details-grid {
    background: #0f172a !important;
    border-color: #334155 !important;
}

body.dark-mode .flow-card {
    background: #0f172a !important;
    border-color: #334155 !important;
}

body.dark-mode .flow-step-body p,
body.dark-mode .details-label {
    color: #94a3b8 !important;
}

body.dark-mode .details-value {
    color: #f8fafc !important;
}

body.dark-mode .btn-success-secondary {
    background: #334155 !important;
    border-color: #475467 !important;
    color: #f8fafc !important;
}
</style>

<?php include 'includes/footer.php'; ?>






