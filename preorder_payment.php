<?php
ob_start(); // Start output buffering
session_start();
$current_page = 'preorder_payment';
$page_title = "Pre-Order Payment | Lechon Delights";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=preorder_payment.php");
    exit();
}

require_once 'includes/config.php';
require_once 'preorder_service.php';
require_once 'paymongo_integration.php';

$user_id = $_SESSION['user_id'];
$preorder_service = new PreOrderService($conn);

// Initialize PayMongo with API keys
$paymongo = new PayMongoIntegration(
    appConfigValue('PAYMONGO_SECRET_KEY'),
    appConfigValue('PAYMONGO_PUBLIC_KEY')
);

// Get pre-order ID and payment type
$pre_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$payment_type = isset($_GET['type']) ? trim($_GET['type']) : 'full';

if (!$pre_order_id) {
    header("Location: my_orders.php?tab=preorders&error=invalid_order");
    exit();
}

// Get pre-order details
$preorder = $preorder_service->getPreOrder($pre_order_id);
if (!$preorder || $preorder['user_id'] != $user_id) {
    header("Location: my_orders.php?tab=preorders&error=not_found");
    exit();
}

$session_id = trim((string)($preorder['paymongo_session_id'] ?? ''));
$linked_preorders = [];
if ($session_id !== '') {
    $linked_query = "SELECT * FROM pre_orders WHERE user_id = ? AND paymongo_session_id = ? ORDER BY id ASC";
    $linked_stmt = mysqli_prepare($conn, $linked_query);
    if ($linked_stmt) {
        mysqli_stmt_bind_param($linked_stmt, "is", $user_id, $session_id);
        mysqli_stmt_execute($linked_stmt);
        $linked_result = mysqli_stmt_get_result($linked_stmt);
        while ($linked_result && ($linked_row = mysqli_fetch_assoc($linked_result))) {
            $linked_preorders[] = $linked_row;
        }
        mysqli_stmt_close($linked_stmt);
    }
}
if (empty($linked_preorders)) {
    $linked_preorders[] = $preorder;
}
$transaction_item_count = count($linked_preorders);
$transaction_subtotal = 0.0;
$transaction_total = 0.0;
$transaction_due_downpayment = 0.0;
$transaction_due_full = 0.0;
foreach ($linked_preorders as $linked_po) {
    $line_total = (float)($linked_po['total_price'] ?? 0);
    $line_subtotal = (float)($linked_po['unit_price'] ?? 0) * (int)($linked_po['quantity'] ?? 0);
    if ($line_total + 0.01 < $line_subtotal) {
        $line_subtotal = $line_total;
    }
    $transaction_subtotal += $line_subtotal;
    $transaction_total += $line_total;
    if (strtolower((string)($linked_po['downpayment_status'] ?? 'pending')) !== 'paid') {
        $transaction_due_downpayment += (float)($linked_po['downpayment_amount'] ?? 0);
    }
    if ((string)($linked_po['payment_type'] ?? '') === 'downpayment') {
        if (strtolower((string)($linked_po['final_payment_status'] ?? 'pending')) !== 'paid') {
            $transaction_due_full += (float)($linked_po['remaining_amount'] ?? 0);
        }
    } else {
        if (strtolower((string)($linked_po['final_payment_status'] ?? 'pending')) !== 'paid') {
            $transaction_due_full += $line_total;
        }
    }
}
$transaction_vat = round(max(0, $transaction_total - $transaction_subtotal), 2);

$error_message = '';
$success_message = '';

// Handle payment submission - Always use PayMongo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    // Get user details for PayMongo checkout
    $user_query = "SELECT email, full_name, phone FROM users WHERE id = ?";
    $user_stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($user_stmt, "i", $user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user_details = mysqli_fetch_assoc($user_result);
    mysqli_stmt_close($user_stmt);
    
    if (!$user_details) {
        $error_message = "Unable to retrieve user information. Please try again.";
    } else {
        // Create PayMongo checkout session
        $amount = ($payment_type === 'downpayment') ? $transaction_due_downpayment : $transaction_due_full;
        if ($amount <= 0) {
            $amount = ($payment_type === 'downpayment') ? (float)$preorder['downpayment_amount'] : (float)$preorder['total_price'];
        }
        
        $checkout_data = $paymongo->createCheckoutSession([
            'amount' => $amount,
            'currency' => 'PHP',
            'description' => "Pre-Order Payment - Transaction #$pre_order_id ({$transaction_item_count} item(s))",
            'order_id' => $pre_order_id,
            'customer_name' => $user_details['full_name'],
            'customer_email' => $user_details['email'],
            'customer_phone' => $user_details['phone'],
            'payment_method' => 'all',
            'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/preorder_payment_success.php?id=' . $pre_order_id . '&type=' . $payment_type,
            'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/preorder_payment.php?id=' . $pre_order_id . '&type=' . $payment_type . '&cancelled=1'
        ]);
        
        if ($checkout_data['success']) {
            // Save checkout session ID for verification later
            $insert_payment = "INSERT INTO pre_order_payments (pre_order_id, payment_type, amount, transaction_id, payment_status, payment_gateway, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $payment_stmt = mysqli_prepare($conn, $insert_payment);
            $payment_gateway = 'paymongo';
            $payment_status = 'pending';
            $session_id = $checkout_data['session_id'];
            $payment_type_value = ($payment_type === 'downpayment') ? 'downpayment' : 'final_payment';
            
            mysqli_stmt_bind_param($payment_stmt, "isdsss", $pre_order_id, $payment_type_value, $amount, $session_id, $payment_status, $payment_gateway);
            mysqli_stmt_execute($payment_stmt);
            mysqli_stmt_close($payment_stmt);
            
            header("Location: " . $checkout_data['checkout_url']);
            exit();
        } else {
            $error_message = "Failed to create payment session: " . $checkout_data['error'];
        }
    }}

$preorder_subtotal = $transaction_subtotal;
$preorder_total = $transaction_total;
$preorder_vat = $transaction_vat;
$payment_amount = ($payment_type === 'downpayment') ? $transaction_due_downpayment : $transaction_due_full;
if ($payment_amount <= 0) {
    $payment_amount = ($payment_type === 'downpayment') ? (float)$preorder['downpayment_amount'] : $preorder_total;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="font_awesome/css/all.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <style>
        .payment-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
        }
        .payment-card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .order-summary {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
        }
        .summary-total {
            font-size: 18px;
            font-weight: bold;
            color: #d32f2f;
            border-top: 2px solid #ddd;
            padding-top: 10px;
            margin-top: 10px;
        }
        .payment-methods {
            margin: 30px 0;
        }
        .method-option {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 2px solid #eee;
            border-radius: 8px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .method-option:hover {
            border-color: #2ecc71;
            background: #f0f8f5;
        }
        .method-option input {
            margin-right: 15px;
        }
        .method-icon {
            font-size: 24px;
            margin-right: 15px;
            color: #2ecc71;
            width: 40px;
            text-align: center;
        }
        .method-info {
            flex: 1;
        }
        .method-title {
            font-weight: bold;
            font-size: 16px;
        }
        .method-desc {
            font-size: 13px;
            color: #666;
        }
        .btn-pay {
            background: #2ecc71;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
        }
        .btn-pay:hover {
            background: #27ae60;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .alert {
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <h1><i class="fas fa-credit-card"></i> Complete Payment</h1>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['cancelled'])): ?>
            <div class="alert alert-warning">Payment was cancelled. Please try again.</div>
        <?php endif; ?>
        
        <div class="payment-card">
            <!-- Order Summary -->
            <div class="order-summary">
                <h5 style="margin-bottom: 15px;"><i class="fas fa-box"></i> Pre-Order Summary</h5>
                <div class="summary-row">
                    <span>Order ID:</span>
                    <strong>#<?php echo $pre_order_id; ?></strong>
                </div>
                <div class="summary-row">
                    <span>Product:</span>
                    <strong>
                        <?php
                        if ($transaction_item_count > 1) {
                            echo htmlspecialchars($preorder['product_name']) . ' +' . ($transaction_item_count - 1) . ' more';
                        } else {
                            echo htmlspecialchars($preorder['product_name']);
                        }
                        ?>
                    </strong>
                </div>
                <div class="summary-row">
                    <span>Line Items:</span>
                    <strong><?php echo (int)$transaction_item_count; ?></strong>
                </div>
                <div class="summary-row">
                    <span>Unit Price:</span>
                    <strong>₱<?php echo number_format($preorder['unit_price'], 2); ?></strong>
                </div>
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <strong>₱<?php echo number_format($preorder_subtotal, 2); ?></strong>
                </div>
                <div class="summary-row">
                    <span>VAT (12%):</span>
                    <strong>₱<?php echo number_format($preorder_vat, 2); ?></strong>
                </div>
                <div class="summary-row" style="font-weight: bold;">
                    <span>Total Amount:</span>
                    <strong style="color: #d32f2f;">₱<?php echo number_format($preorder_total, 2); ?></strong>
                </div>
                
                <?php if ($payment_type === 'downpayment'): ?>
                    <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 15px;">
                        <div class="summary-row">
                            <span>Downpayment (30%):</span>
                            <strong style="color: #d32f2f;">₱<?php echo number_format($preorder['downpayment_amount'], 2); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Remaining Balance (70%):</span>
                            <strong>₱<?php echo number_format($preorder['remaining_amount'], 2); ?></strong>
                        </div>
                        <p style="font-size: 12px; color: #666; margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Remaining balance will be due upon pickup/delivery.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <i class="fas fa-check-circle"></i> 
                <strong>You're paying: ₱<?php echo number_format($payment_amount, 2); ?></strong>
            </div>
            
            <!-- Payment with PayMongo -->
            <form method="POST" class="payment-methods">
                <p style="margin-bottom: 20px; text-align: center;">
                    <i class="fas fa-lock"></i> Secure payment via PayMongo
                </p>
                <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
                    We accept Credit/Debit Cards, GCash, Maya, and Bank Transfer on the next page.
                </p>
                
                <input type="hidden" name="process_payment" value="1">
                <button type="submit" class="btn-pay">
                    <i class="fas fa-lock"></i> Proceed to Secure Payment
                </button>
            </form>
            
            <p style="text-align: center; font-size: 12px; color: #666; margin-top: 20px;">
                <i class="fas fa-lock"></i> Your payment information is secure and encrypted.
            </p>
        </div>
    </div>
    
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php mysqli_close($conn); ?>


