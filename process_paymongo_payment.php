<?php
// process_paymongo_payment.php
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['pending_order'])) {
    header('Location: checkout.php');
    exit;
}

$order = $_SESSION['pending_order'];

// Log the order data for debugging
error_log("Processing PayMongo payment for order: " . json_encode($order));

// Initialize PayMongo with your API keys
$paymongo = new PayMongoIntegration(
    appConfigValue('PAYMONGO_SECRET_KEY'),
    appConfigValue('PAYMONGO_PUBLIC_KEY')
);

// Prepare checkout session data
$checkoutData = [
    'amount' => $order['amount'],
    'description' => $order['description'],
    'order_id' => $order['order_id'],
    'customer_name' => $order['customer_name'],
    'customer_email' => $order['customer_email'],
    'customer_phone' => $order['customer_phone'],
    'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_success.php?order_id=' . $order['order_id'],
    'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_cancel.php?order_id=' . $order['order_id'],
    'payment_method' => $order['payment_method']
];

error_log("Checkout data prepared: " . json_encode($checkoutData));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Payment - Lechon Delights</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #c62828, #e53935);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .payment-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #c62828;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.8rem;
        }
        
        p {
            color: #666;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .order-info {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: left;
        }
        
        .order-info p {
            margin: 8px 0;
            font-size: 0.95rem;
        }
        
        .order-info strong {
            color: #333;
        }
        
        .amount {
            font-size: 1.5rem;
            color: #c62828;
            font-weight: 700;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="spinner"></div>
        <h1>Processing Payment</h1>
        <p>Please wait while we redirect you to PayMongo...</p>
        
        <div class="order-info">
            <p><strong>Order Number:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
            <p><strong>Amount to Pay:</strong></p>
            <p class="amount">₱<?php echo number_format($order['amount'], 2); ?></p>
            <p><strong>Customer:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Process payment after page loads
        document.addEventListener('DOMContentLoaded', function() {
            processPayment();
        });
        
        function processPayment() {
            // Make AJAX call to process payment
            fetch('process_paymongo_payment_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'create_checkout'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Redirect to PayMongo checkout
                    window.location.href = data.checkout_url;
                } else {
                    // Show error
                    Swal.fire({
                        title: 'Payment Error',
                        text: data.error || 'Failed to create payment session',
                        icon: 'error',
                        confirmButtonText: 'Try Again'
                    }).then(() => {
                        window.location.href = 'checkout.php';
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    title: 'System Error',
                    text: 'An error occurred while processing your payment',
                    icon: 'error',
                    confirmButtonText: 'Go Back'
                }).then(() => {
                    window.location.href = 'checkout.php';
                });
            });
        }
    </script>
</body>
</html>
<?php
// This section won't execute because we output HTML above
// But keeping it for reference
?>