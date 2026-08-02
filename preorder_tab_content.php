<?php
// Pre-orders tab content for my_orders.php
// This file is included when current_tab === 'preorders'
?>

<div class="tab-content active">
    <h3><i class="fas fa-calendar-check"></i> My Pre-Orders</h3>
    
    <?php
    require_once 'preorder_service.php';
    
    $preorder_service = new PreOrderService($conn);
    $preorders = $preorder_service->getUserPreOrders($user_id);
    
    if ($preorders) {
        ?>
        <div class="preorders-grid">
                <?php foreach ($preorders as $preorder): ?>
                    <div class="preorder-item">
                        <div class="preorder-header">
                            <h5>Order #<?php echo $preorder['id']; ?></h5>
                            <span class="status-badge status-<?php echo $preorder['reservation_status']; ?>">
                                <?php echo ucwords(str_replace('_', ' ', $preorder['reservation_status'])); ?>
                            </span>
                        </div>
                        
                        <div class="preorder-body">
                            <div class="preorder-product">
                                <strong><?php echo htmlspecialchars($preorder['product_name']); ?></strong>
                            </div>
                            
                            <div class="preorder-details">
                                <p><i class="fas fa-cube"></i> Quantity: <strong><?php echo $preorder['quantity']; ?></strong></p>
                                <p><i class="fas fa-calendar"></i> Date: <strong><?php echo date('M d, Y', strtotime($preorder['preferred_pickup_date'])); ?></strong></p>
                                <p><i class="fas fa-clock"></i> Time: <strong><?php echo htmlspecialchars($preorder['preferred_pickup_time']); ?></strong></p>
                                
                                <?php if ($preorder['delivery_method'] === 'delivery'): ?>
                                    <p><i class="fas fa-map-marker-alt"></i> Address: <strong><?php echo htmlspecialchars($preorder['delivery_address']); ?></strong></p>
                                <?php else: ?>
                                    <p><i class="fas fa-store"></i> Location: <strong><?php echo htmlspecialchars($preorder['pickup_location']); ?></strong></p>
                                <?php endif; ?>
                                
                                <div class="price-info">
                                    <p class="total-price">₱<?php echo number_format($preorder['total_price'], 2); ?></p>
                                    
                                    <?php if ($preorder['payment_type'] === 'downpayment'): ?>
                                        <div style="font-size: 12px; color: #666;">
                                            Downpayment: 
                                            <span class="payment-label <?php echo $preorder['downpayment_status']; ?>">
                                                <?php echo ucfirst($preorder['downpayment_status']); ?>
                                            </span>
                                            (₱<?php echo number_format($preorder['downpayment_amount'], 2); ?>)
                                            <br>
                                            Remaining: 
                                            <span class="payment-label <?php echo $preorder['final_payment_status']; ?>">
                                                <?php echo ucfirst($preorder['final_payment_status']); ?>
                                            </span>
                                            (₱<?php echo number_format($preorder['remaining_amount'], 2); ?>)
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 12px; color: #666;">
                                            Full Payment: 
                                            <span class="payment-label <?php echo $preorder['final_payment_status']; ?>">
                                                <?php echo ucfirst($preorder['final_payment_status']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="preorder-actions">
                                <?php 
                                $can_pay = ($preorder['reservation_status'] !== 'cancelled' && $preorder['reservation_status'] !== 'completed');
                                $needs_downpayment = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] !== 'paid');
                                $needs_final = ($preorder['payment_type'] === 'downpayment' && $preorder['downpayment_status'] === 'paid' && $preorder['final_payment_status'] !== 'paid');
                                $needs_full = ($preorder['payment_type'] === 'full_payment' && $preorder['final_payment_status'] !== 'paid');
                                ?>
                                
                                <?php if ($needs_downpayment || $needs_final || $needs_full): ?>
                                    <a href="preorder_payment.php?id=<?php echo $preorder['id']; ?>&type=<?php echo $needs_downpayment ? 'downpayment' : 'full'; ?>" class="btn-action btn-pay">
                                        <i class="fas fa-credit-card"></i> Pay Now
                                    </a>
                                <?php endif; ?>
                                
                                <a href="preorder_details.php?id=<?php echo $preorder['id']; ?>" class="btn-action btn-view">
                                    <i class="fas fa-eye"></i> View Details
                                </a>

                                <a href="preorder_receipt.php?id=<?php echo $preorder['id']; ?>" class="btn-action btn-view" target="_blank" rel="noopener">
                                    <i class="fas fa-receipt"></i> Receipt
                                </a>
                                
                                <?php if ($can_pay && $preorder['reservation_status'] === 'pending'): ?>
                                    <a href="javascript:cancelPreOrder(<?php echo $preorder['id']; ?>)" class="btn-action btn-cancel">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <style>
                .preorders-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                    gap: 20px;
                    margin: 20px 0;
                }
                
                .preorder-item {
                    background: white;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    padding: 20px;
                    transition: all 0.3s ease;
                }
                
                .preorder-item:hover {
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                
                .preorder-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 15px;
                    padding-bottom: 15px;
                    border-bottom: 1px solid #eee;
                }
                
                .preorder-header h5 {
                    margin: 0;
                    color: #333;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 6px 12px;
                    border-radius: 20px;
                    font-size: 11px;
                    font-weight: bold;
                }
                
                .status-pending {
                    background: #fff3cd;
                    color: #856404;
                }
                
                .status-confirmed {
                    background: #cfe2ff;
                    color: #084298;
                }
                
                .status-in_preparation {
                    background: #e2e3e5;
                    color: #383d41;
                }
                
                .status-ready_for_pickup {
                    background: #d1e7dd;
                    color: #0f5132;
                }
                
                .status-completed {
                    background: #d1e7dd;
                    color: #0f5132;
                }
                
                .status-cancelled {
                    background: #f8d7da;
                    color: #842029;
                }
                
                .preorder-product {
                    font-size: 16px;
                    color: #2ecc71;
                    margin-bottom: 10px;
                }
                
                .preorder-details {
                    font-size: 13px;
                    color: #666;
                    margin: 15px 0;
                }
                
                .preorder-details p {
                    margin: 8px 0;
                }
                
                .preorder-details i {
                    width: 20px;
                    color: #2ecc71;
                }
                
                .price-info {
                    background: #f9f9f9;
                    padding: 10px;
                    border-radius: 4px;
                    margin: 15px 0;
                }
                
                .total-price {
                    font-size: 18px;
                    font-weight: bold;
                    color: #d32f2f;
                    margin: 0;
                }
                
                .payment-label {
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: bold;
                }
                
                .payment-label.paid {
                    background: #d1e7dd;
                    color: #0f5132;
                }
                
                .payment-label.pending {
                    background: #fff3cd;
                    color: #856404;
                }
                
                .preorder-actions {
                    display: flex;
                    gap: 10px;
                    margin-top: 15px;
                    flex-wrap: wrap;
                }
                
                .btn-action {
                    flex: 1;
                    min-width: 100px;
                    padding: 8px 12px;
                    border: none;
                    border-radius: 4px;
                    text-align: center;
                    text-decoration: none;
                    cursor: pointer;
                    font-size: 13px;
                    transition: all 0.3s ease;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 5px;
                }
                
                .btn-pay {
                    background: #2ecc71;
                    color: white;
                }
                
                .btn-pay:hover {
                    background: #27ae60;
                }
                
                .btn-view {
                    background: #3498db;
                    color: white;
                }
                
                .btn-view:hover {
                    background: #2980b9;
                }
                
                .btn-cancel {
                    background: #e74c3c;
                    color: white;
                }
                
                .btn-cancel:hover {
                    background: #c0392b;
                }
            </style>
            
            <script>
                // Cancelling pre-orders handled by global cancellation.js using data-cancel-type="reservation"
            </script>
            <?php
        } else {
            echo '<p style="text-align: center; padding: 40px; color: #666;">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i><br>
                    No pre-orders yet. <a href="preorder.php">Create one now!</a>
                  </p>';
        }
        ?>
</div>
