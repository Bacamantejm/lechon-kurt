<?php
session_start();

// Check if order success data exists
if (!isset($_SESSION['order_success'])) {
    header('Location: menu.php');
    exit;
}

$page_title = "Order Confirmed | Lechon Delights";
include 'includes/header.php';

$order_number = $_SESSION['order_success']['order_number'] ?? '';
$amount_paid = $_SESSION['order_success']['amount_paid'] ?? 0;
$total_amount = $_SESSION['order_success']['total_amount'] ?? $amount_paid; // Fallback for old sessions
$payment_method = $_SESSION['order_success']['payment_method'] ?? 'paymongo';
$payment_type = $_SESSION['order_success']['payment_type'] ?? 'full';
$downpayment_amount = $_SESSION['order_success']['downpayment_amount'] ?? 0;
$remaining_balance = $_SESSION['order_success']['remaining_balance'] ?? 0;

// Don't clear order success data yet - keep it for display
?>

<section class="success-section">
    <div class="container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            
            <h2>Thank You for Your Order!</h2>
            <p class="order-number">Order Number: <strong><?php echo htmlspecialchars($order_number); ?></strong></p>
            
            <div class="order-details">
                <p>We have received your order and will begin processing it immediately.</p>
                <p>You will receive an email confirmation shortly with your order details.</p>
                
                <div class="amount-paid">
                    <h3>Amount Paid: PHP <?php echo number_format($amount_paid, 2); ?></h3>
                </div>
            </div>
            
            <div class="payment-details">
                <h3>Payment Information</h3>
                <p><strong>Payment Type:</strong> 
                    <?php echo $payment_type === 'downpayment' ? '30% Downpayment' : 'Full Payment'; ?>
                </p>
                <p><strong>Payment Method:</strong> <?php echo ucfirst($payment_method); ?></p>
                <p><strong>Payment Status:</strong> 
                    <?php echo $payment_type === 'downpayment' ? 'Partial (30% Paid)' : 'Fully Paid'; ?>
                </p>
                
                <?php if ($payment_type === 'downpayment'): ?>
                <div class="balance-info">
                    <p><strong>Downpayment Paid:</strong> PHP <?php echo number_format($downpayment_amount, 2); ?></p>
                    <p><strong>Remaining Balance:</strong> PHP <?php echo number_format($remaining_balance, 2); ?></p>
                    <p class="note"><em>Please settle the remaining balance upon pickup/delivery.</em></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="next-steps">
                <h3>What's Next?</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h4>Order Processing</h4>
                            <p>We'll prepare your order and confirm availability</p>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h4>Order Confirmation</h4>
                            <p>We'll contact you to confirm your order details</p>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h4>Delivery/Pickup</h4>
                            <p>Your order will be ready for pickup/delivery as scheduled</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="contact-info">
                <h3>Need Help?</h3>
                <p>If you have any questions about your order, please contact us:</p>
                <ul>
                    <li><i class="fas fa-phone"></i> (02) 1234-5678</li>
                    <li><i class="fas fa-envelope"></i> orders@lechondelights.com</li>
                    <li><i class="fas fa-clock"></i> Mon-Sun: 8:00 AM - 10:00 PM</li>
                </ul>
            </div>
            
            <div class="success-actions">
                <a href="my_orders.php" class="btn-primary">
                    <i class="fas fa-shopping-bag"></i> View My Orders
                </a>
                <a href="menu.php" class="btn-secondary">
                    <i class="fas fa-utensils"></i> Continue Shopping
                </a>
            </div>
        </div>
    </div>
</section>

<style>
.success-section {
    padding: 80px 0;
    background-color: #f9f9f9;
}

.success-card {
    max-width: 800px;
    margin: 0 auto;
    background-color: white;
    padding: 50px;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    text-align: center;
}

.success-icon {
    font-size: 5rem;
    color: #4CAF50;
    margin-bottom: 30px;
}

.success-card h2 {
    color: #333;
    font-size: 2.2rem;
    margin-bottom: 20px;
}

.order-number {
    font-size: 1.2rem;
    color: #666;
    margin-bottom: 40px;
    padding: 15px;
    background-color: #f0f7ff;
    border-radius: 8px;
}

.order-details {
    margin-bottom: 40px;
    padding: 30px;
    background-color: #f9f9f9;
    border-radius: 12px;
}

.order-details p {
    color: #555;
    margin-bottom: 15px;
    font-size: 1.1rem;
    line-height: 1.6;
}

.amount-paid {
    margin-top: 30px;
    padding: 20px;
    background: linear-gradient(135deg, #4CAF50, #45a049);
    border-radius: 12px;
    color: white;
}

.amount-paid h3 {
    margin: 0;
    font-size: 1.8rem;
}

.next-steps {
    margin-bottom: 40px;
    text-align: left;
}

.next-steps h3 {
    color: #333;
    margin-bottom: 25px;
    font-size: 1.5rem;
    text-align: center;
}

.steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
}

.step {
    display: flex;
    gap: 20px;
    padding: 25px;
    background-color: white;
    border-radius: 12px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    border: 2px solid #e0e0e0;
}

.step-number {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #c62828, #e53935);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    font-weight: 700;
    flex-shrink: 0;
}

.step-content h4 {
    color: #333;
    margin-bottom: 10px;
    font-size: 1.2rem;
}

.step-content p {
    color: #666;
    font-size: 0.95rem;
    line-height: 1.5;
}

.contact-info {
    margin-bottom: 40px;
    padding: 30px;
    background-color: #f0f7ff;
    border-radius: 12px;
    text-align: left;
}

.contact-info h3 {
    color: #1976d2;
    margin-bottom: 20px;
    font-size: 1.5rem;
}

.contact-info p {
    color: #555;
    margin-bottom: 20px;
    font-size: 1.1rem;
}

.contact-info ul {
    list-style: none;
    padding: 0;
}

.contact-info li {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 12px;
    color: #333;
    font-size: 1.1rem;
}

.contact-info i {
    color: #1976d2;
    width: 24px;
    font-size: 1.2rem;
}

.success-actions {
    display: flex;
    gap: 20px;
    justify-content: center;
}

.success-actions .btn-primary,
.success-actions .btn-secondary {
    padding: 18px 35px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 10px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
}

.success-actions .btn-primary {
    background: linear-gradient(135deg, #c62828, #e53935);
    color: white;
    border: none;
}

.success-actions .btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(198, 40, 40, 0.4);
}

.success-actions .btn-secondary {
    background-color: #6c757d;
    color: white;
    border: none;
}

.success-actions .btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-3px);
}

/* Modern Food Success Refresh */
:root {
    --ok-red: #b3261e;
    --ok-orange: #ef6b2e;
    --ok-cream: #fff8ef;
    --ok-ink: #2a211d;
    --ok-muted: #7c6e65;
    --ok-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--ok-cream);
}

.page-header {
    margin-bottom: 0;
    padding: 126px 20px 80px;
    background:
        linear-gradient(128deg, rgba(16, 10, 8, 0.86), rgba(43, 20, 13, 0.75)),
        url('images/about-us-bg.jpg') center/cover no-repeat;
    color: #fff;
    text-align: center;
}

.page-header p {
    color: #f8e7d8;
}

.success-section {
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
    padding-top: 44px;
}

.success-card {
    border: 1px solid var(--ok-border);
    border-radius: 22px;
    box-shadow: 0 18px 36px rgba(74, 32, 20, 0.12);
}

.success-card h2,
.next-steps h3,
.payment-details h3 {
    color: var(--ok-ink);
}

.order-number,
.order-details,
.contact-info,
.payment-details,
.step {
    border: 1px solid var(--ok-border);
    border-radius: 14px;
}

.order-number,
.order-details,
.contact-info,
.payment-details {
    background: #fff9f1;
}

.order-details p,
.step-content p,
.contact-info p,
.contact-info li {
    color: var(--ok-muted);
}

.step-number {
    background: linear-gradient(135deg, var(--ok-red), var(--ok-orange));
}

.amount-paid {
    background: linear-gradient(135deg, #1f7a4d, #2e9460);
}

.success-actions .btn-primary {
    background: linear-gradient(135deg, var(--ok-red), var(--ok-orange));
}

.success-actions .btn-secondary {
    background: #233f32;
}

@media (max-width: 768px) {
    .success-card {
        padding: 30px 20px;
    }
    
    .steps {
        grid-template-columns: 1fr;
    }
    
    .success-actions {
        flex-direction: column;
    }
    
    .success-actions .btn-primary,
    .success-actions .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<?php include 'includes/footer.php'; ?>






