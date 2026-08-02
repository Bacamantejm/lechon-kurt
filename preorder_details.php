<?php
session_start();
$current_page = 'preorder_details';
$page_title = "Pre-Order Details | Lechon Delights";
include 'includes/header.php';
require_once 'includes/config.php';
require_once 'preorder_service.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=preorder_details.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$pre_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$pre_order_id) {
    header("Location: my_orders.php?tab=preorders&error=invalid");
    exit();
}

$preorder_service = new PreOrderService($conn);
$preorder = $preorder_service->getPreOrder($pre_order_id);

if (!$preorder || $preorder['user_id'] != $user_id) {
    header("Location: my_orders.php?tab=preorders&error=not_found");
    exit();
}

// Handle cancellation
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_preorder'])) {
    $reason = trim($_POST['cancellation_reason'] ?? '');
    
    $result = $preorder_service->cancelPreOrder($pre_order_id, $reason);
    
    if ($result['success']) {
        // Send cancellation email
        require_once 'email_service.php';
        $email_service = new EmailService($conn);
        
        $email_service->sendPreOrderCancellationConfirmation($_SESSION['email'], [
            'pre_order_id' => $pre_order_id,
            'product_name' => $preorder['product_name'],
            'cancellation_reason' => $reason,
            'refund_amount' => (float)($result['refund_amount'] ?? 0)
        ]);
        
        header("Location: my_orders.php?tab=preorders&success=cancelled");
        exit();
    } else {
        $message = "<div class='alert alert-danger'>" . $result['message'] . "</div>";
    }
}
?>

<section class="page-header">
    <div class="container">
        <h1>Pre-Order Details</h1>
        <p>View details for Pre-Order #<?php echo $pre_order_id; ?></p>
    </div>
</section>

<section class="orders-section">
    <div class="container">
        <!-- Back Button -->
        <div style="margin-bottom: 20px;">
             <a href="my_orders.php?tab=preorders" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Pre-Orders
            </a>
        </div>

        <?php if (!empty($message)): echo $message; endif; ?>
        
        <div class="order-card">
            <div class="order-header">
                <div class="order-info">
                    <h3>Pre-Order #<?php echo $pre_order_id; ?></h3>
                    <p class="order-date">
                        <i class="far fa-calendar"></i> 
                        Created on <?php echo date('F j, Y, g:i A', strtotime($preorder['created_at'])); ?>
                    </p>
                </div>
                <div class="order-status">
                    <span class="status-badge status-<?php echo $preorder['reservation_status']; ?>">
                        <?php echo ucwords(str_replace('_', ' ', $preorder['reservation_status'])); ?>
                    </span>
                    <p class="order-total">₱<?php echo number_format($preorder['total_price'], 2); ?></p>
                </div>
            </div>
            
            <div class="order-details">
                <!-- Product Info -->
                <h4 class="section-title"><i class="fas fa-box"></i> Product Information</h4>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Product Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($preorder['product_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Quantity:</span>
                        <span class="detail-value"><?php echo $preorder['quantity']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unit Price:</span>
                        <span class="detail-value">₱<?php echo number_format($preorder['unit_price'], 2); ?></span>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

                <!-- Delivery Info -->
                <h4 class="section-title"><i class="fas fa-truck"></i> Delivery Information</h4>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Preferred Date:</span>
                        <span class="detail-value">
                            <?php 
                                $date_str = $preorder['preferred_pickup_date'];
                                echo ($date_str && $date_str != '0000-00-00') ? date('F j, Y', strtotime($date_str)) : 'N/A'; 
                            ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Preferred Time:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($preorder['preferred_pickup_time']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Method:</span>
                        <span class="detail-value"><?php echo ucfirst($preorder['delivery_method']); ?></span>
                    </div>
                </div>

                <div class="detail-row">
                    <?php if ($preorder['delivery_method'] === 'delivery'): ?>
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <span class="detail-label">Delivery Address:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($preorder['delivery_address']); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <span class="detail-label">Pickup Location:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($preorder['pickup_location']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($preorder['admin_notes'])): ?>
                    <div class="special-instructions">
                        <span class="detail-label">Special Instructions:</span>
                        <p><?php echo htmlspecialchars($preorder['special_instructions']); ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Payment Summary -->
                 <h4 class="section-title" style="margin-top: 30px;"><i class="fas fa-credit-card"></i> Payment Summary</h4>
                 <div class="payment-summary-box">
                    <div class="summary-row">
                        <span>Unit Price × Quantity:</span>
                        <span>₱<?php echo number_format($preorder['unit_price'], 2); ?> × <?php echo $preorder['quantity']; ?></span>
                    </div>
                    
                    <?php if ($preorder['payment_type'] === 'downpayment'): ?>
                        <div class="summary-row">
                            <span>Downpayment (30%):</span>
                            <span>₱<?php echo number_format($preorder['downpayment_amount'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Remaining Amount (70%):</span>
                            <span>₱<?php echo number_format($preorder['remaining_amount'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Downpayment Status:</span>
                            <span class="payment-status-badge <?php echo $preorder['downpayment_status']; ?>">
                                <?php echo ucfirst($preorder['downpayment_status']); ?>
                            </span>
                        </div>
                        <div class="summary-row">
                            <span>Final Payment Status:</span>
                            <span class="payment-status-badge <?php echo $preorder['final_payment_status']; ?>">
                                <?php echo ucfirst($preorder['final_payment_status']); ?>
                            </span>
                        </div>
                        <div class="summary-row">
                            <span>Refund Rule:</span>
                            <span class="text-danger">Downpayment is non-refundable.</span>
                        </div>
                    <?php else: ?>
                        <div class="summary-row">
                            <span>Full Payment Status:</span>
                            <span class="payment-status-badge <?php echo $preorder['final_payment_status']; ?>">
                                <?php echo ucfirst($preorder['final_payment_status']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="summary-row total">
                        <span>Total Amount:</span>
                        <span>₱<?php echo number_format($preorder['total_price'], 2); ?></span>
                    </div>
                 </div>

                 <?php if (!empty($preorder['admin_notes'])): ?>
                    <div class="admin-notes">
                        <span class="detail-label"><i class="fas fa-sticky-note"></i> Store Notes:</span>
                        <p><?php echo nl2br(htmlspecialchars($preorder['admin_notes'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="order-actions">
                <?php 
                $needs_downpayment = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] !== 'paid');
                $needs_final = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] === 'paid' && $preorder['final_payment_status'] !== 'paid');
                $needs_full = ($preorder['payment_type'] === 'full_payment' && $preorder['final_payment_status'] !== 'paid');
                ?>
                
                <?php if ($needs_downpayment || $needs_final || $needs_full): ?>
                    <a href="preorder_payment.php?id=<?php echo $pre_order_id; ?>&type=<?php echo $needs_downpayment ? 'downpayment' : 'full'; ?>" class="btn-pay">
                        <i class="fas fa-credit-card"></i> Complete Payment
                    </a>
                <?php endif; ?>

                <a href="preorder_receipt.php?id=<?php echo $pre_order_id; ?>" class="btn-pay" target="_blank" rel="noopener" style="background:#fff3f3;color:#b91c1c;border:1px solid #fecaca;">
                    <i class="fas fa-receipt"></i> View Receipt
                </a>
                
                <?php if ($preorder['reservation_status'] !== 'cancelled' && $preorder['reservation_status'] !== 'completed'): ?>
                    <button type="button" class="btn-cancel" onclick="confirmCancel()">
                        <i class="fas fa-times"></i> Cancel Pre-Order
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Hidden form for cancellation -->
<form id="cancelForm" method="POST" style="display: none;">
    <input type="hidden" name="cancel_preorder" value="1">
    <input type="hidden" id="cancellationReason" name="cancellation_reason">
</form>

<!-- Add SweetAlert2 CSS & JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Modern Orders Page Styles */
:root {
    --primary-color: #c62828;
    --primary-dark: #b71c1c;
    --text-dark: #2c3e50;
    --text-light: #6c757d;
    --bg-light: #f8f9fa;
    --card-shadow: 0 2px 12px rgba(0,0,0,0.04);
    --hover-shadow: 0 12px 24px rgba(0,0,0,0.1);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.orders-section {
    padding: 40px 0 80px;
    background-color: var(--bg-light);
    min-height: 80vh;
}

.order-card {
    background-color: white;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    overflow: hidden;
    transition: var(--transition);
    border: 1px solid rgba(0,0,0,0.03);
    animation: slideUp 0.5s ease forwards;
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 30px;
    background-color: white;
    border-bottom: 1px solid #f1f1f1;
}

.order-info h3 {
    color: var(--text-dark);
    margin-bottom: 5px;
    font-size: 1.25rem;
    font-weight: 700;
}

.order-date {
    color: var(--text-light);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 3px 0;
}

.order-status {
    text-align: right;
}

.status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Status Colors */
.status-pending { background-color: #fff8e1; color: #f57c00; }
.status-confirmed { background-color: #e3f2fd; color: #1976d2; }
.status-in_preparation { background-color: #e0f2f1; color: #00796b; }
.status-ready_for_pickup { background-color: #e8f5e9; color: #2e7d32; }
.status-completed { background-color: #e8f5e9; color: #2e7d32; }
.status-cancelled { background-color: #ffebee; color: #c62828; }

.order-total {
    color: var(--primary-color);
    font-size: 1.4rem;
    font-weight: 800;
    margin-top: 5px;
}

.order-details {
    padding: 30px;
}

.section-title {
    font-size: 1.1rem;
    color: var(--text-dark);
    margin-bottom: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: var(--primary-color);
}

.detail-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
    margin-bottom: 25px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.detail-label {
    color: #999;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 600;
}

.special-instructions {
    margin: 20px 0 30px;
    padding: 20px;
    background-color: #fff8e1;
    border-radius: 8px;
    border-left: 4px solid #ffc107;
}

.special-instructions .detail-label {
    color: #f57c00;
}

.special-instructions p {
    color: #5d4037;
    margin: 0;
}

.admin-notes {
    margin: 20px 0;
    padding: 20px;
    background-color: #e3f2fd;
    border-radius: 8px;
    border-left: 4px solid #2196F3;
}

.admin-notes .detail-label {
    color: #1976d2;
}

.admin-notes p {
    color: #0d47a1;
    margin: 5px 0 0;
}

.payment-summary-box {
    background: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #eee;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    color: var(--text-dark);
}

.summary-row.total {
    border-top: 1px solid #ddd;
    padding-top: 15px;
    margin-top: 15px;
    font-weight: 800;
    font-size: 1.2rem;
    color: var(--primary-color);
}

.payment-status-badge {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.payment-status-badge.paid { background-color: #d1e7dd; color: #0f5132; }
.payment-status-badge.pending { background-color: #fff3cd; color: #856404; }

.order-actions {
    padding: 20px 30px 25px;
    background-color: white;
    border-top: 1px solid #f1f1f1;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.btn-cancel, .btn-pay, .btn-back {
    padding: 10px 24px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: var(--transition);
    border: none;
}

.btn-cancel {
    background-color: white;
    border: 1px solid #ffcdd2;
    color: #c62828;
}

.btn-cancel:hover {
    background-color: #ffebee;
    transform: translateY(-2px);
}

.btn-pay {
    background-color: #2ecc71;
    color: white;
}

.btn-pay:hover {
    background-color: #27ae60;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(46, 204, 113, 0.3);
}

.btn-back {
    background-color: transparent;
    color: var(--text-light);
    padding-left: 0;
}

.btn-back:hover {
    color: var(--primary-color);
    transform: translateX(-5px);
}

/* Animations */
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 768px) {
    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .order-status {
        text-align: left;
        width: 100%;
    }
    
    .detail-row {
        grid-template-columns: 1fr;
    }
    
    .order-actions {
        flex-direction: column;
    }
    
    .btn-cancel, .btn-pay {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function confirmCancel() {
    Swal.fire({
        title: 'Cancel Pre-Order?',
        text: "Are you sure you want to cancel this pre-order? Please provide a reason.",
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'Reason for cancellation',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, cancel it!',
        preConfirm: (reason) => {
            if (!reason) {
                Swal.showValidationMessage('Please enter a reason')
            }
            return reason;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('cancellationReason').value = result.value;
            document.getElementById('cancelForm').submit();
        }
    });
}
</script>

<?php mysqli_close($conn); ?>
<?php include 'includes/footer.php'; ?>
