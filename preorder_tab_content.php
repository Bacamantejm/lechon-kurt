<?php
// Pre-orders tab content for my_orders.php
// This file is included when current_tab === 'preorders'
?>

<div class="tab-content active">
    <?php
    require_once 'preorder_service.php';
    
    $preorder_service = new PreOrderService($conn);
    $preorders = $preorder_service->getUserPreOrders($user_id);
    
    if ($preorders) {
        ?>
        <div class="orders-card-list">
            <?php foreach ($preorders as $preorder): ?>
                <?php
                $res_status = strtolower(trim((string)($preorder['reservation_status'] ?? 'pending')));
                $delivery_method = strtolower(trim((string)($preorder['delivery_method'] ?? 'pickup')));
                
                $status_display_map = [
                    'pending' => 'Pending Confirmation',
                    'confirmed' => 'Pre-Order Confirmed',
                    'in_preparation' => 'In Preparation',
                    'ready_for_pickup' => 'Ready for Pickup',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled'
                ];
                $status_text = $status_display_map[$res_status] ?? ucwords(str_replace('_', ' ', $res_status));
                ?>
                <div class="ecom-order-card" data-status="<?php echo htmlspecialchars($res_status); ?>">
                    <!-- Card Top Bar -->
                    <div class="ecom-card-top">
                        <div class="ecom-shop-info">
                            <i class="fas fa-calendar-check shop-icon"></i>
                            <span class="shop-name">Pre-Order #<?php echo (int)$preorder['id']; ?></span>
                            <span class="divider">&bull;</span>
                            <span class="order-date">Schedule: <?php echo date('M d, Y', strtotime($preorder['preferred_pickup_date'])); ?> @ <?php echo htmlspecialchars($preorder['preferred_pickup_time']); ?></span>
                            <span class="type-badge type-<?php echo htmlspecialchars($delivery_method); ?>">
                                <?php echo ucfirst($delivery_method); ?>
                            </span>
                        </div>
                        <div class="ecom-status-label status-<?php echo htmlspecialchars($res_status); ?>">
                            <span class="status-dot"></span>
                            <?php echo htmlspecialchars($status_text); ?>
                        </div>
                    </div>

                    <!-- Card Middle Body -->
                    <div class="ecom-card-middle">
                        <div class="ecom-item-row">
                            <img src="assets/images/promo_lechon.jpg" alt="<?php echo htmlspecialchars($preorder['product_name']); ?>" class="item-img">
                            <div class="item-details">
                                <h4 class="item-name"><?php echo htmlspecialchars($preorder['product_name']); ?></h4>
                                <div class="item-meta">
                                    <span class="meta-tag">Pre-Order Reservation</span>
                                    <span class="meta-qty">Qty: <?php echo (int)$preorder['quantity']; ?></span>
                                </div>
                            </div>
                            <div class="item-price">
                                ₱<?php echo number_format($preorder['total_price'], 2); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Card Bottom Footer -->
                    <div class="ecom-card-bottom">
                        <div class="summary-line">
                            <div class="preorder-pay-status">
                                <?php if ($preorder['payment_type'] === 'downpayment'): ?>
                                    <span class="payment-method">
                                        Downpayment: <strong>₱<?php echo number_format($preorder['downpayment_amount'], 2); ?></strong> (<?php echo ucfirst($preorder['downpayment_status']); ?>)
                                        &bull; Balance: <strong>₱<?php echo number_format($preorder['remaining_amount'], 2); ?></strong> (<?php echo ucfirst($preorder['final_payment_status']); ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="payment-method">Full Payment Status: <strong><?php echo ucfirst($preorder['final_payment_status']); ?></strong></span>
                                <?php endif; ?>
                            </div>
                            <div class="total-wrap">
                                <span class="total-label">Total Amount:</span>
                                <span class="total-amount">₱<?php echo number_format($preorder['total_price'], 2); ?></span>
                            </div>
                        </div>

                        <div class="actions-line">
                            <div></div>
                            <div class="action-buttons-group">
                                <?php 
                                $can_pay = ($preorder['reservation_status'] !== 'cancelled' && $preorder['reservation_status'] !== 'completed');
                                $needs_downpayment = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] !== 'paid');
                                $needs_final = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] === 'paid' && $preorder['final_payment_status'] !== 'paid');
                                $needs_full = ($preorder['payment_type'] === 'full_payment' && $preorder['final_payment_status'] !== 'paid');
                                ?>
                                
                                <?php if ($can_pay && $preorder['reservation_status'] === 'pending'): ?>
                                    <button type="button" class="btn-action-outline btn-cancel" onclick="cancelPreOrder(<?php echo $preorder['id']; ?>)">
                                        Cancel Pre-Order
                                    </button>
                                <?php endif; ?>

                                <a href="preorder_receipt.php?id=<?php echo $preorder['id']; ?>" class="btn-action-outline" target="_blank" rel="noopener">
                                    Receipt
                                </a>

                                <a href="preorder_details.php?id=<?php echo $preorder['id']; ?>" class="btn-action-outline">
                                    View Details
                                </a>

                                <?php if ($needs_downpayment || $needs_final || $needs_full): ?>
                                    <a href="preorder_payment.php?id=<?php echo $preorder['id']; ?>&type=<?php echo $needs_downpayment ? 'downpayment' : 'full'; ?>" class="btn-action-primary">
                                        Pay Now
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    } else {
        ?>
        <div class="empty-orders-state">
            <div class="empty-icon-wrap">
                <i class="fas fa-calendar-times"></i>
            </div>
            <h3>No Pre-Orders Yet</h3>
            <p>Reserve custom lechon in advance for upcoming holidays and events.</p>
            <a href="preorder.php" class="btn-browse-menu">
                Create Pre-Order
            </a>
        </div>
        <?php
    }
    ?>
</div>

