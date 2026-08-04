<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'email_service.php';

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    header('Location: menu.php');
    exit;
}

// Fetch order details
$stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$order) {
    header('Location: menu.php');
    exit;
}

// Handle simulated payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_paymongo_payment') {
    $txn_id = 'pay_sim_' . strtoupper(bin2hex(random_bytes(6)));
    $is_downpayment = ($order['downpayment_amount'] > 0 && strtolower((string)$order['payment_status']) !== 'partial');
    $amount_to_pay = $is_downpayment ? (float)$order['downpayment_amount'] : (float)$order['total_amount'];
    $new_payment_status = $is_downpayment ? 'partial' : 'paid';

    // Update order status in database
    $update_stmt = mysqli_prepare($conn, "UPDATE orders SET payment_status = ?, status = 'confirmed', updated_at = NOW() WHERE id = ? LIMIT 1");
    if ($update_stmt) {
        mysqli_stmt_bind_param($update_stmt, "si", $new_payment_status, $order_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }

    // Insert payment log into database if payments table exists
    $pmt_check = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
    if ($pmt_check && mysqli_num_rows($pmt_check) > 0) {
        $insert_pmt = mysqli_prepare($conn, "INSERT INTO payments (order_id, amount, payment_method, payment_type, transaction_id, status, created_at) VALUES (?, ?, ?, ?, ?, 'paid', NOW())");
        if ($insert_pmt) {
            $payment_method_str = (string)($order['payment_method'] ?? 'paymongo');
            $payment_type_str = $is_downpayment ? 'downpayment' : 'full';
            mysqli_stmt_bind_param($insert_pmt, "idsss", $order_id, $amount_to_pay, $payment_method_str, $payment_type_str, $txn_id);
            mysqli_stmt_execute($insert_pmt);
            mysqli_stmt_close($insert_pmt);
        }
    }

    // Send payment receipt email
    try {
        $mailer = new EmailService($conn);
        $mailer->sendPaymentConfirmation($order_id, [
            'amount' => $amount_to_pay,
            'type' => $is_downpayment ? 'downpayment' : 'full',
            'method' => $order['payment_method'],
            'transaction_id' => $txn_id
        ]);
    } catch (Exception $e) {
        error_log("PayMongo simulator email error: " . $e->getMessage());
    }

    // Set order success session
    $_SESSION['order_success'] = [
        'order_id' => $order_id,
        'order_number' => $order['order_number'],
        'amount_paid' => $amount_to_pay,
        'total_amount' => $order['total_amount'],
        'payment_method' => $order['payment_method'],
        'payment_type' => $is_downpayment ? 'downpayment' : 'full',
        'downpayment_amount' => $order['downpayment_amount'],
        'remaining_balance' => $order['remaining_balance']
    ];

    header('Location: payment_success.php?order_id=' . $order_id);
    exit;
}

$page_title = 'PayMongo Payment Gateway | Lechon Delights';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayMongo Checkout Gateway</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .pm-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .pm-header {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            padding: 24px 30px;
            text-align: center;
        }
        .pm-logo {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .pm-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .pm-body {
            padding: 30px;
        }
        .amount-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 20px;
            text-align: center;
            margin-bottom: 24px;
        }
        .amount-box .amount {
            font-size: 2.2rem;
            font-weight: 800;
            color: #38bdf8;
        }
        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #334155;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            text-transform: uppercase;
            font-weight: 700;
        }
        .btn-paymongo {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            border: none;
            color: white;
            padding: 14px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            width: 100%;
            transition: all 0.2s;
        }
        .btn-paymongo:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(37,99,235,0.3);
        }
    </style>
</head>
<body>

<div class="pm-card">
    <div class="pm-header">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="pm-logo"><i class="fas fa-bolt text-warning me-2"></i>paymongo</span>
            <span class="pm-badge">Sandbox Gateway</span>
        </div>
        <p class="mb-0 text-white-50 small">Secure Merchant Payment System</p>
    </div>

    <div class="pm-body">
        <div class="text-center mb-4">
            <div class="text-secondary small mb-1">Merchant Account</div>
            <h5 class="fw-bold text-white mb-0">Lechon Delights</h5>
            <div class="small text-muted mt-1">Order #<?php echo htmlspecialchars($order['order_number']); ?></div>
        </div>

        <div class="amount-box">
            <div class="text-secondary small mb-1">Total Amount Due</div>
            <div class="amount">₱<?php 
                $pay_amt = ($order['downpayment_amount'] > 0 && strtolower((string)$order['payment_status']) !== 'partial')
                    ? $order['downpayment_amount'] 
                    : $order['total_amount'];
                echo number_format($pay_amt, 2); 
            ?></div>
            <?php if ($order['downpayment_amount'] > 0): ?>
                <div class="badge bg-warning text-dark mt-2">30% Downpayment Option</div>
            <?php endif; ?>
        </div>

        <div class="mb-4 text-center">
            <div class="text-secondary small mb-2">Payment Method</div>
            <div class="method-badge">
                <i class="fas fa-wallet text-info"></i>
                <?php echo htmlspecialchars(strtoupper((string)($order['payment_method'] ?? 'ONLINE PAYMENT'))); ?>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="confirm_paymongo_payment">
            <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
            
            <button type="submit" class="btn-paymongo mb-3">
                <i class="fas fa-lock me-2"></i> Pay ₱<?php echo number_format($pay_amt, 2); ?> Now
            </button>
        </form>

        <div class="text-center">
            <a href="checkout.php" class="text-secondary small text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i> Cancel & Return to Store
            </a>
        </div>
    </div>
</div>

</body>
</html>
