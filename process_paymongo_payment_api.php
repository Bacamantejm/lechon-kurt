<?php
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';

// Set JSON response header
header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);

error_log("=== PAYMONGO PAYMENT API CALLED ===");
error_log("Session pending_order: " . json_encode($_SESSION['pending_order'] ?? 'NOT SET'));

try {
    // Check if pending order exists
    if (!isset($_SESSION['pending_order'])) {
        error_log("ERROR: No pending order in session");
        throw new Exception("No pending order found. Please start checkout again.");
    }
    
    $order = $_SESSION['pending_order'];
    error_log("Processing PayMongo payment for order: " . json_encode($order));
    
    // Validate order data
    if (empty($order['order_id']) || empty($order['amount'])) {
        error_log("ERROR: Invalid order data");
        throw new Exception("Invalid order data");
    }
    
    // Initialize PayMongo with your API keys
    $paymongo = new PayMongoIntegration(
        'sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE',  // Secret key
        'pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE'   // Public key
    );
    
    // Prepare checkout session data
    $checkoutData = [
        'amount' => floatval($order['amount']),
        'description' => $order['description'],
        'order_id' => intval($order['order_id']),
        'customer_name' => $order['customer_name'],
        'customer_email' => $order['customer_email'],
        'customer_phone' => $order['customer_phone'],
        'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_success.php?order_id=' . intval($order['order_id']),
        'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_cancel.php?order_id=' . intval($order['order_id']),
        'payment_method' => $order['payment_method']
    ];
    
    error_log("Checkout data prepared: " . json_encode($checkoutData));
    
    // Create checkout session
    $result = $paymongo->createCheckoutSession($checkoutData);
    
    error_log("PayMongo response: " . json_encode($result));
    
    if ($result['success']) {
        // Save checkout session ID to database
        $query = "UPDATE payments SET checkout_session_id = ? WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        
        if (!$stmt) {
            error_log("ERROR: Database prepare failed: " . mysqli_error($conn));
            throw new Exception("Database prepare failed: " . mysqli_error($conn));
        }
        
        $session_id = $result['session_id'];
        $order_id = intval($order['order_id']);
        
        mysqli_stmt_bind_param($stmt, "si", $session_id, $order_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            error_log("ERROR: Database update failed: " . mysqli_stmt_error($stmt));
            throw new Exception("Database update failed: " . mysqli_stmt_error($stmt));
        }
        
        mysqli_stmt_close($stmt);
        
        error_log("Checkout session saved. Checkout URL: " . $result['checkout_url']);
        
        // Return success response with checkout URL
        echo json_encode([
            'success' => true,
            'checkout_url' => $result['checkout_url'],
            'session_id' => $result['session_id'],
            'order_id' => $order_id
        ]);
        exit;
    } else {
        // Handle error
        $errorMessage = $result['error'] ?? 'Unknown error occurred';
        error_log("PayMongo checkout creation failed: " . $errorMessage);
        
        echo json_encode([
            'success' => false,
            'error' => $errorMessage
        ]);
        exit;
    }
    
} catch (Exception $e) {
    error_log("Exception in process_paymongo_payment_api.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
?>
