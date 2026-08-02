<?php
/**
 * Enhanced Payment Success Handler with Automatic Driver Assignment
 * This page is called after successful PayMongo payment
 * It automatically assigns drivers to delivery orders
 */
session_start();
require_once 'includes/config.php';
require_once 'EnhancedLogisticsService.php';
require_once 'email_service.php';
require_once 'sms_service.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$user_id = $_SESSION['user_id'] ?? 0; // Get logged-in user ID

if (!$order_id || !$user_id) { // Also check for user_id
    die("Invalid order ID or not logged in.");
}

// Initialize variables for the view
$order = null;
$assignment_result = ['success' => false];

try {
    // Get order details and verify ownership
    $order_query = "SELECT * FROM orders WHERE id = ? AND user_id = ?"; // Added user_id check
    $order_stmt = mysqli_prepare($conn, $order_query);
    if (!$order_stmt) {
        throw new Exception("Database error preparing order query.");
    }
    
    mysqli_stmt_bind_param($order_stmt, "ii", $order_id, $user_id); // Bind user_id
    mysqli_stmt_execute($order_stmt);
    $order_result = mysqli_stmt_get_result($order_stmt);
    $order = mysqli_fetch_assoc($order_result);
    mysqli_stmt_close($order_stmt);
    
    if (!$order) {
        throw new Exception("Order not found or you do not have permission to view it.");
    }
    
    // Validate required order fields
    if (empty($order['customer_name']) || empty($order['delivery_address']) || empty($order['total_amount'])) {
        throw new Exception("Incomplete order data");
    }
    
    // Set default email if missing
    if (empty($order['customer_email'])) {
        $order['customer_email'] = 'noreply@lechondelights.com';
    }
    
    // Only process if the order is still pending confirmation
    if ($order['status'] !== 'confirmed') {
        // Update order status to confirmed
        $update_order = "UPDATE orders SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_order);
        if ($update_stmt) {
            mysqli_stmt_bind_param($update_stmt, "i", $order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
        
        // Update payment status
        $payment_update = "UPDATE payments SET status = 'completed' WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
        $pay_stmt = mysqli_prepare($conn, $payment_update);
        if ($pay_stmt) {
            mysqli_stmt_bind_param($pay_stmt, "i", $order_id);
            mysqli_stmt_execute($pay_stmt);
            mysqli_stmt_close($pay_stmt);
        }
    }
    
    // Automatic driver assignment for delivery orders
    if ($order['delivery_option'] === 'delivery') {
        $logistics_service = new EnhancedLogisticsService($conn);
        
        // Get user's latitude/longitude or calculate from address
        $address = $order['delivery_address'];
        $latitude = null;
        $longitude = null;
        
        // Check if coordinates are stored in order
        if (isset($order['customer_coordinates'])) {
            $coords = json_decode($order['customer_coordinates'], true);
            if ($coords && isset($coords['latitude'], $coords['longitude'])) {
                $latitude = floatval($coords['latitude']);
                $longitude = floatval($coords['longitude']);
            }
        }
        
        // Auto-assign driver
        $assignment_result = $logistics_service->autoAssignDriver(
            $order_id,
            $address,
            $latitude,
            $longitude,
            EnhancedLogisticsService::ASSIGNMENT_ALGORITHM_HYBRID
        );
        
        if ($assignment_result['success']) {
            // Send SMS to customer about driver assignment
            try {
                $sms_service = new SmsService($conn); // Corrected instantiation
                $driver_name = $assignment_result['driver_name'] ?? 'Your driver';
                $driver_phone = $assignment_result['driver_phone'] ?? '';
                
                $message = "Hi {$order['customer_name']}, your order #{$order['order_number']} has been assigned to driver $driver_name. They will call you soon at $driver_phone. Track your order: " . $_SERVER['HTTP_HOST'];
                
                if (!empty($order['customer_phone'])) {
                    $sms_service->send($order['customer_phone'], $message); // Corrected method call
                }
            } catch (Exception $e) {
                error_log("SMS sending error: " . $e->getMessage());
            }
            
            // Send email notification
            try {
                if (class_exists('EmailService')) {
                    $email_service = new EmailService($conn);
                    $email_subject = "Driver Assigned - Order #" . $order['order_number'];
                    $email_body = "Hello {$order['customer_name']},\n\nYour order has been assigned to driver {$assignment_result['driver_name']}. They will contact you shortly.\n\nOrder #: {$order['order_number']}\nDelivery Address: {$order['delivery_address']}";
                    $email_service->sendNotificationEmail($order['customer_email'], $email_subject, $email_body);
                }
            } catch (Exception $e) {
                error_log("Email notification error: " . $e->getMessage());
            }
        } else {
            // Log failed assignment but don't fail the payment flow
            error_log("Failed to assign driver for order $order_id: " . $assignment_result['message']);
            
            // Mark for manual assignment - create tracking if missing
            $check_tracking = "SELECT id FROM logistics_tracking WHERE order_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_tracking);
            if ($check_stmt) {
                mysqli_stmt_bind_param($check_stmt, "i", $order_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                mysqli_stmt_close($check_stmt);
                
                if ($check_result->num_rows === 0) {
                    $mark_manual = "INSERT INTO logistics_tracking (order_id, current_status, created_at, updated_at) 
                                   VALUES (?, 'pending', NOW(), NOW())";
                    $manual_stmt = mysqli_prepare($conn, $mark_manual);
                    if ($manual_stmt) {
                        mysqli_stmt_bind_param($manual_stmt, "i", $order_id);
                        mysqli_stmt_execute($manual_stmt);
                        mysqli_stmt_close($manual_stmt);
                    }
                }
            }
        }
    }
    
    // Clear cart session
    if (isset($_SESSION['cart'])) {
        unset($_SESSION['cart']);
    }
    
} catch (Exception $e) {
    error_log("Payment success handler error: " . $e->getMessage());
    // If order is not found, we should not show the success page.
    if (!$order) {
        die("An error occurred: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful | Lechon Delights</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="font_awesome/css/all.css">
    <style>
        .success-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .success-card {
            background: white;
            border-radius: 15px;
            padding: 50px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
        }
        
        .success-icon {
            font-size: 80px;
            color: #28a745;
            margin-bottom: 20px;
            animation: bounce 0.6s;
        }
        
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        .success-title {
            font-size: 32px;
            color: #333;
            margin-bottom: 15px;
            font-weight: bold;
        }
        
        .success-message {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .order-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            text-align: left;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .detail-label {
            color: #666;
            font-weight: 500;
        }
        
        .detail-value {
            color: #333;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s;
        }
        
        .btn-primary-custom:hover {
            transform: scale(1.05);
            color: white;
            text-decoration: none;
        }
        
        .btn-secondary-custom {
            background: white;
            color: #667eea;
            padding: 12px 30px;
            border: 2px solid #667eea;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-secondary-custom:hover {
            background: #667eea;
            color: white;
            text-decoration: none;
        }
        
        .status-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .driver-info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #2196F3;
            display: none;
        }
        
        .driver-info.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            
            <h1 class="success-title">Payment Successful!</h1>
            
            <div class="success-message">
                <p>Thank you for your order! Your payment has been processed successfully.</p>
                <p>We're preparing your delicious Lechon meal!</p>
            </div>
            
            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Order Number:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span class="detail-value">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Delivery Scheduled:</span>
                    <span class="detail-value"><?php echo date('M d, Y - g:i A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="status-badge">Confirmed</span>
                </div>
                <?php if ($order['delivery_option'] === 'delivery'): ?>
                <div class="detail-row">
                    <span class="detail-label">Delivery To:</span>
                    <span class="detail-value" style="word-break: break-word;">
                        <?php echo htmlspecialchars(substr($order['delivery_address'], 0, 40)); ?>...
                    </span>
                </div>
                <?php else: ?>
                <div class="detail-row">
                    <span class="detail-label">Pickup Location:</span>
                    <span class="detail-value">Store Branch</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="driver-info <?php if ($assignment_result['success']) echo 'show'; ?>" id="driverInfo">
                <strong><i class="fas fa-truck"></i> Driver Assigned!</strong>
                <p id="driverDetails" style="margin-top: 10px; margin-bottom: 0;">
                    <?php if ($assignment_result['success']): ?>
                        Your driver <strong><?php echo htmlspecialchars($assignment_result['driver_name']); ?></strong> will be in touch.
                        <br>Phone: <strong><?php echo htmlspecialchars($assignment_result['driver_phone']); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="action-buttons">
                <a href="my_orders.php" class="btn-primary-custom">
                    <i class="fas fa-list"></i> View Order
                </a>
                <a href="menu.php" class="btn-secondary-custom">
                    <i class="fas fa-shopping-bag"></i> Order More
                </a>
            </div>
            
            <p style="margin-top: 20px; color: #999; font-size: 12px;">
                A confirmation email has been sent to <?php echo htmlspecialchars($order['customer_email']); ?>
            </p>
        </div>
    </div>
    
    <script>
        // No longer needed as data is embedded from PHP
    </script>
</body>
</html>
