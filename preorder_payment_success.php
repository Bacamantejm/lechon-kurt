<?php
session_start();
$current_page = 'preorder_payment_success';
$page_title = "Payment Successful | Lechon Delights";
include 'includes/header.php';
require_once 'includes/config.php';
require_once 'preorder_service.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$pre_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$payment_type = isset($_GET['type']) ? trim($_GET['type']) : 'full';
$payment_method = isset($_GET['method']) ? trim($_GET['method']) : 'paymongo';

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
$linked_ids = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $linked_preorders);
$linked_ids = array_values(array_filter($linked_ids));
$linked_item_count = count($linked_preorders);
$linked_subtotal = 0.0;
$linked_total = 0.0;
$linked_downpayment_total = 0.0;
$linked_remaining_total = 0.0;
$linked_product_names = [];
foreach ($linked_preorders as $linked_po) {
    $line_total = (float)($linked_po['total_price'] ?? 0);
    $line_subtotal = (float)($linked_po['unit_price'] ?? 0) * (int)($linked_po['quantity'] ?? 0);
    if ($line_total + 0.01 < $line_subtotal) {
        $line_subtotal = $line_total;
    }
    $linked_subtotal += $line_subtotal;
    $linked_total += $line_total;
    $linked_downpayment_total += (float)($linked_po['downpayment_amount'] ?? 0);
    $linked_remaining_total += (float)($linked_po['remaining_amount'] ?? 0);
    $name = trim((string)($linked_po['product_name'] ?? ''));
    if ($name !== '') {
        $linked_product_names[] = $name;
    }
}
$linked_vat = round(max(0, $linked_total - $linked_subtotal), 2);
$linked_product_summary = !empty($linked_product_names)
    ? implode(', ', array_slice($linked_product_names, 0, 3)) . (count($linked_product_names) > 3 ? ' +' . (count($linked_product_names) - 3) . ' more' : '')
    : (string)$preorder['product_name'];

// If this is a PayMongo payment, verify and process it
if ($payment_method === 'paymongo') {
    require_once 'paymongo_integration.php';

    // Get checkout session ID from pre_order_payments table
    $payment_query = "SELECT transaction_id FROM pre_order_payments WHERE pre_order_id = ? AND payment_gateway = 'paymongo' ORDER BY created_at DESC LIMIT 1";
    $payment_stmt = mysqli_prepare($conn, $payment_query);
    mysqli_stmt_bind_param($payment_stmt, "i", $pre_order_id);
    mysqli_stmt_execute($payment_stmt);
    $payment_result = mysqli_stmt_get_result($payment_stmt);
    $payment_record = mysqli_fetch_assoc($payment_result);
    mysqli_stmt_close($payment_stmt);

    if ($payment_record) {
        $session_id = $payment_record['transaction_id'];

        // Initialize PayMongo with API keys
        $paymongo = new PayMongoIntegration(
            appConfigValue('PAYMONGO_SECRET_KEY'),
            appConfigValue('PAYMONGO_PUBLIC_KEY')
        );

        // Retrieve and verify checkout session
        $session = $paymongo->retrieveCheckoutSession($session_id);

        if ($session['success'] && $session['status'] === 'paid') {
            // Record payment with transaction ID
            $payment_amount = ($payment_type === 'downpayment') ? $preorder['downpayment_amount'] : $preorder['total_price'];
            $transaction_id = $session['session_data']['id'];

            // Update payment record with verification status
            $update_payment = "UPDATE pre_order_payments SET payment_status = 'paid', paid_at = NOW() WHERE pre_order_id = ? AND payment_gateway = 'paymongo' ORDER BY created_at DESC LIMIT 1";
            $update_stmt = mysqli_prepare($conn, $update_payment);
            mysqli_stmt_bind_param($update_stmt, "i", $pre_order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);

            // Update pre-order status
            $payment_type_col = ($payment_type === 'downpayment') ? 'downpayment_status' : 'final_payment_status';
            if (!empty($linked_ids)) {
                $placeholders = implode(',', array_fill(0, count($linked_ids), '?'));
                $update_preorder = "UPDATE pre_orders SET $payment_type_col = 'paid', updated_at = NOW() WHERE id IN ({$placeholders})";
                $preorder_stmt = mysqli_prepare($conn, $update_preorder);
                if ($preorder_stmt) {
                    $bind_values = $linked_ids;
                    $bind_refs = [];
                    foreach ($bind_values as $k => $v) {
                        $bind_refs[$k] = &$bind_values[$k];
                    }
                    array_unshift($bind_refs, str_repeat('i', count($linked_ids)));
                    call_user_func_array([$preorder_stmt, 'bind_param'], $bind_refs);
                    mysqli_stmt_execute($preorder_stmt);
                    mysqli_stmt_close($preorder_stmt);
                }
            }

            // Deduct inventory if applicable
            mysqli_begin_transaction($conn);
            try {
                $should_deduct = false;
                if ($payment_type === 'downpayment') {
                    $should_deduct = true;
                } elseif ($payment_type === 'full' && $preorder['downpayment_status'] !== 'paid') {
                    $should_deduct = true;
                }

                if ($should_deduct) {
                    foreach ($linked_preorders as $linked_po) {
                        $int_product_id = (int)($linked_po['product_id'] ?? 0);
                        $quantity = (int)($linked_po['quantity'] ?? 0);
                        $inventory_date = (string)($linked_po['preferred_pickup_date'] ?? '');
                        if ($int_product_id <= 0 || $quantity <= 0 || $inventory_date === '') {
                            continue;
                        }

                        // Atomically create a daily inventory record if it doesn't exist for the preferred date
                        $init_daily_inv_sql = "
                            INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
                            SELECT ?, ?, p.stock, 5, NOW()
                            FROM products p
                            WHERE p.id = ?
                            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";
                        $init_stmt = mysqli_prepare($conn, $init_daily_inv_sql);
                        mysqli_stmt_bind_param($init_stmt, "isi", $int_product_id, $inventory_date, $int_product_id);
                        mysqli_stmt_execute($init_stmt);
                        mysqli_stmt_close($init_stmt);

                        // Decrement daily inventory
                        $update_daily_sql = "UPDATE inventory SET current_stock = current_stock - ?, last_updated = NOW() WHERE product_id = ? AND inventory_date = ?";
                        $daily_stmt = mysqli_prepare($conn, $update_daily_sql);
                        mysqli_stmt_bind_param($daily_stmt, "iis", $quantity, $int_product_id, $inventory_date);
                        mysqli_stmt_execute($daily_stmt);
                        mysqli_stmt_close($daily_stmt);
                    }
                }
                mysqli_commit($conn);
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log("Inventory deduction failed for preorder #{$pre_order_id}: " . $e->getMessage());
            }

            // Send confirmation email
            require_once 'email_service.php';
            $email_service = new EmailService($conn);

            $email_service->sendPreOrderConfirmation($_SESSION['email'], [
                'pre_order_id' => $pre_order_id,
                'product_name' => $preorder['product_name'],
                'quantity' => $preorder['quantity'],
                'total_price' => $preorder['total_price'],
                'downpayment_amount' => $preorder['downpayment_amount'],
                'remaining_amount' => $preorder['remaining_amount'],
                'pickup_date' => $preorder['preferred_pickup_date'],
                'payment_type' => $payment_type
            ]);
        }
    }
}

$preorder_subtotal = $linked_subtotal;
$preorder_total = $linked_total;
$preorder_vat = $linked_vat;
$paid_amount_display = ($payment_type === 'downpayment') ? $linked_downpayment_total : $preorder_total;
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
        .success-container {
            max-width: 600px;
            margin: 60px auto;
            padding: 20px;
            text-align: center;
        }
        .success-card {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success-icon {
            font-size: 60px;
            color: #2ecc71;
            margin-bottom: 20px;
            animation: bounceIn 0.6s ease-in-out;
        }
        .success-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .success-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
        }
        .order-details {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
            text-align: left;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #333;
        }
        .detail-value {
            color: #666;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #2ecc71;
            color: white;
        }
        .btn-primary:hover {
            background: #27ae60;
        }
        .btn-secondary {
            background: #3498db;
            color: white;
        }
        .btn-secondary:hover {
            background: #2980b9;
        }
        @keyframes bounceIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        .next-steps {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        .next-steps h5 {
            color: #2ecc71;
            margin-bottom: 15px;
        }
        .next-steps ol {
            margin: 0;
            padding-left: 20px;
        }
        .next-steps li {
            margin: 8px 0;
            color: #333;
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
            <p class="success-message">
                Your pre-order payment has been processed successfully.
                <?php if ($payment_type === 'downpayment'): ?>
                    Your reservation is confirmed. Please settle the remaining balance upon pickup/delivery.
                <?php else: ?>
                    Your full payment is complete. We'll prepare your order now!
                <?php endif; ?>
            </p>

            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value">#<?php echo $pre_order_id; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Product:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($linked_product_summary); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Line Items:</span>
                    <span class="detail-value"><?php echo (int)$linked_item_count; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Preferred Date:</span>
                    <span class="detail-value"><?php echo date('F j, Y', strtotime($preorder['preferred_pickup_date'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Preferred Time:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($preorder['preferred_pickup_time']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Delivery Method:</span>
                    <span class="detail-value"><?php echo ucfirst($preorder['delivery_method']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Subtotal:</span>
                    <span class="detail-value">PHP <?php echo number_format($preorder_subtotal, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">VAT (12%):</span>
                    <span class="detail-value">PHP <?php echo number_format($preorder_vat, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value">PHP <?php echo number_format($preorder_total, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span class="detail-value" style="color: #2ecc71; font-weight: bold;">
                        PHP <?php echo number_format($paid_amount_display, 2); ?>
                    </span>
                </div>
                    <?php if ($payment_type === 'downpayment'): ?>
                    <div class="detail-row">
                        <span class="detail-label">Remaining Balance:</span>
                        <span class="detail-value">PHP <?php echo number_format($linked_remaining_total, 2); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="next-steps">
                <h5><i class="fas fa-list-check"></i> What's Next?</h5>
                <ol>
                    <li>A confirmation email has been sent to your email address</li>
                    <li>We will prepare your order on your preferred date</li>
                    <li>You'll receive a notification when it's ready</li>
                    <?php if ($preorder['delivery_method'] === 'pickup'): ?>
                        <li>Come to <strong><?php echo htmlspecialchars($preorder['pickup_location']); ?></strong> to pick up your order</li>
                    <?php else: ?>
                        <li>We'll deliver your order to <strong><?php echo htmlspecialchars($preorder['delivery_address']); ?></strong></li>
                    <?php endif; ?>
                    <?php if ($payment_type === 'downpayment'): ?>
                        <li>Pay the remaining balance (PHP <?php echo number_format($linked_remaining_total, 2); ?>) upon pickup/delivery</li>
                    <?php endif; ?>
                </ol>
            </div>

            <div class="action-buttons">
                <a href="preorder_details.php?id=<?php echo $pre_order_id; ?>" class="btn btn-primary">
                    <i class="fas fa-eye"></i> View Pre-Order Details
                </a>
                <a href="my_orders.php?tab=preorders" class="btn btn-info">
                    <i class="fas fa-list"></i> View All Pre-Orders
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php mysqli_close($conn); ?>
