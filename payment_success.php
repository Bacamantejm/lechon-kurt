<?php
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';
require_once 'email_service.php';
require_once 'logistics_service.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Log all access to this file
error_log("=== PAYMENT SUCCESS PAGE ACCESSED ===");
error_log("URL: " . $_SERVER['REQUEST_URI']);
error_log("GET Parameters: " . json_encode($_GET));
error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: User not logged in, redirecting to login");
    header('Location: login.php');
    exit();
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// Log the payment success callback for debugging
error_log("Payment success callback received for order: " . $order_id);

if (empty($order_id) || $order_id <= 0) {
    error_log("ERROR: Invalid or missing order ID");
    echo '<!DOCTYPE html>
<html>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: "Error!",
            text: "Invalid order ID provided.",
            icon: "error"
        }).then(() => {
            window.location.href = "checkout.php";
        });
    </script>
</body>
</html>';
    exit();
}

try {
    // Verify order exists and belongs to current user
    $verify_query = "SELECT id FROM orders WHERE id = ? AND user_id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    if (!$verify_stmt) {
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }
    
    $user_id = $_SESSION['user_id'];
    mysqli_stmt_bind_param($verify_stmt, "ii", $order_id, $user_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        mysqli_stmt_close($verify_stmt);
        error_log("ERROR: Order $order_id not found or doesn't belong to user $user_id");
        throw new Exception("Order not found or unauthorized access");
    }
    mysqli_stmt_close($verify_stmt);
    
    // Get checkout session ID from database
    $query = "SELECT checkout_session_id FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $payment = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$payment || empty($payment['checkout_session_id'])) {
        error_log("No checkout session found for order: " . $order_id);
        throw new Exception("No payment session found for this order.");
    }
    
    // Verify payment with PayMongo
    $paymongo = new PayMongoIntegration(
        appConfigValue('PAYMONGO_SECRET_KEY'),
        appConfigValue('PAYMONGO_PUBLIC_KEY')
    );
    
    $verification = $paymongo->verifyPayment($payment['checkout_session_id']);
    error_log("PayMongo verification result: " . json_encode($verification));
    
    if ($verification['success']) {
        if ($verification['status'] === 'paid') {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Get order details
                $order_query = "SELECT * FROM orders WHERE id = ?";
                $order_stmt = mysqli_prepare($conn, $order_query);
                if (!$order_stmt) {
                    throw new Exception("Database prepare failed: " . mysqli_error($conn));
                }
                
                mysqli_stmt_bind_param($order_stmt, "i", $order_id);
                mysqli_stmt_execute($order_stmt);
                $order_result = mysqli_stmt_get_result($order_stmt);
                $order = mysqli_fetch_assoc($order_result);
                mysqli_stmt_close($order_stmt);
                
                if (!$order) {
                    throw new Exception("Order not found");
                }
                
                // Update order status
                $update_order = "UPDATE orders SET status = 'confirmed', payment_status = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_order);
                if (!$update_stmt) {
                    throw new Exception("Database prepare failed: " . mysqli_error($conn));
                }
                
                $payment_status = ($order['downpayment_amount'] > 0) ? 'partial' : 'paid';
                mysqli_stmt_bind_param($update_stmt, "si", $payment_status, $order_id);
                
                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception("Failed to update order: " . mysqli_stmt_error($update_stmt));
                }
                mysqli_stmt_close($update_stmt);
                
                // Update payment status
                $update_payment = "UPDATE payments SET status = 'paid', paid_at = NOW(), transaction_id = ? WHERE order_id = ?";
                $update_pmt_stmt = mysqli_prepare($conn, $update_payment);
                if (!$update_pmt_stmt) {
                    throw new Exception("Database prepare failed: " . mysqli_error($conn));
                }
                
                $transaction_id = $verification['session_data']['id'] ?? uniqid();
                mysqli_stmt_bind_param($update_pmt_stmt, "si", $transaction_id, $order_id);
                
                if (!mysqli_stmt_execute($update_pmt_stmt)) {
                    throw new Exception("Failed to update payment: " . mysqli_stmt_error($update_pmt_stmt));
                }
                mysqli_stmt_close($update_pmt_stmt);
                
                // Deduct inventory
                $items_query = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
                if ($items_stmt = mysqli_prepare($conn, $items_query)) {
                    mysqli_stmt_bind_param($items_stmt, "i", $order_id);
                    mysqli_stmt_execute($items_stmt);
                    $items_result = mysqli_stmt_get_result($items_stmt);
                    $inventory_date = date('Y-m-d');
                    
                    while ($item = mysqli_fetch_assoc($items_result)) {
                        // Get the integer product ID from the string product_id in order_items
                        $string_product_id = $item['product_id'];
                        $int_product_id = 0;

                        // Simplified and more robust product ID lookup.
                        $get_id_query = "SELECT id FROM products WHERE id = ? OR product_id = ? LIMIT 1";
                        $get_id_stmt = mysqli_prepare($conn, $get_id_query);
                        if ($get_id_stmt) {
                            mysqli_stmt_bind_param($get_id_stmt, "ss", $string_product_id, $string_product_id);
                            mysqli_stmt_execute($get_id_stmt);
                            $id_result = mysqli_stmt_get_result($get_id_stmt);
                            if ($id_row = mysqli_fetch_assoc($id_result)) {
                                $int_product_id = $id_row['id'];
                            }
                            mysqli_stmt_close($get_id_stmt);
                        }

                        if ($int_product_id > 0) {
                            // Check if inventory record exists for today
                            $check_inv_query = "SELECT id, current_stock FROM inventory WHERE product_id = ? AND inventory_date = ?";
                            $check_inv_stmt = mysqli_prepare($conn, $check_inv_query);
                            mysqli_stmt_bind_param($check_inv_stmt, "is", $int_product_id, $inventory_date);
                            mysqli_stmt_execute($check_inv_stmt);
                            $check_inv_res = mysqli_stmt_get_result($check_inv_stmt);
                            $inv_data = mysqli_fetch_assoc($check_inv_res);
                            mysqli_stmt_close($check_inv_stmt);

                            if (!$inv_data) {
                                // Initialize inventory for today from product master stock
                                $init_inv_sql = "INSERT INTO inventory (product_id, inventory_date, current_stock, min_stock_level, last_updated)
                                                 SELECT id, ?, stock, 5, NOW() FROM products WHERE id = ?";
                                $init_inv_stmt = mysqli_prepare($conn, $init_inv_sql);
                                mysqli_stmt_bind_param($init_inv_stmt, "si", $inventory_date, $int_product_id);
                                if (!mysqli_stmt_execute($init_inv_stmt)) {
                                    throw new Exception("Failed to initialize inventory: " . mysqli_error($conn));
                                }
                                mysqli_stmt_close($init_inv_stmt);
                                
                                // Fetch again to get the new ID and stock
                                $check_inv_stmt = mysqli_prepare($conn, $check_inv_query);
                                mysqli_stmt_bind_param($check_inv_stmt, "is", $int_product_id, $inventory_date);
                                mysqli_stmt_execute($check_inv_stmt);
                                $check_inv_res = mysqli_stmt_get_result($check_inv_stmt);
                                $inv_data = mysqli_fetch_assoc($check_inv_res);
                                mysqli_stmt_close($check_inv_stmt);
                            }

                            if ($inv_data) {
                                $current_stock = $inv_data['current_stock'];
                                $quantity = $item['quantity'];
                                $new_stock = $current_stock - $quantity;

                                // Update inventory
                                $update_inv_sql = "UPDATE inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                                $update_inv_stmt = mysqli_prepare($conn, $update_inv_sql);
                                mysqli_stmt_bind_param($update_inv_stmt, "ii", $new_stock, $inv_data['id']);
                                if (!mysqli_stmt_execute($update_inv_stmt)) {
                                    throw new Exception("Failed to update inventory: " . mysqli_error($conn));
                                }
                                mysqli_stmt_close($update_inv_stmt);

                                // Log history
                                $history_query = "INSERT INTO inventory_history (product_id, adjustment_type, quantity_changed, previous_stock, new_stock, notes, created_at) VALUES (?, 'reduce', ?, ?, ?, ?, NOW())";
                                $history_stmt = mysqli_prepare($conn, $history_query);
                                $notes = "Order #" . $order['order_number'];
                                mysqli_stmt_bind_param($history_stmt, "iiiis", $int_product_id, $quantity, $current_stock, $new_stock, $notes);
                                mysqli_stmt_execute($history_stmt);
                                mysqli_stmt_close($history_stmt);
                            }
                        } else {
                            error_log("Inventory deduction failed: Could not find product with product_id '{$string_product_id}' for order #{$order_id}");
                        }
                    }
                    mysqli_stmt_close($items_stmt);
                }
                
                // Commit transaction
                mysqli_commit($conn);

                // Notify admins that payment is verified and order is ready for fulfillment workflow.
                if (function_exists('getAdminUserIds') && function_exists('createNotification')) {
                    $admin_ids = getAdminUserIds($conn);
                    $paid_notif_title = 'Order Payment Verified';
                    $paid_notif_message = 'Order #' . $order['order_number'] . ' has a verified ' . strtoupper($payment_status) . ' payment and is now confirmed.';
                    foreach ($admin_ids as $admin_id) {
                        createNotification($conn, (int)$admin_id, 'order_paid', $paid_notif_title, $paid_notif_message, (int)$order_id, 'order');
                    }
                }
                
                // --- AUTO-ASSIGN DRIVER ---
                if (($order['delivery_option'] ?? 'pickup') === 'delivery') {
                    try {
                        $logisticsService = new LogisticsService($conn);
                        
                        // 1. Create tracking record for the order
                        $trackingResult = $logisticsService->createTrackingForOrder($order_id, 1, 1, $order['special_instructions'] ?? '', $order['latitude'] ?? null, $order['longitude'] ?? null); // Provider 1 = In-house, Method 1 = Standard
                        
                        if ($trackingResult['success']) {
                            $tracking_id = $trackingResult['tracking_id'];
                            error_log("Logistics tracking record #{$tracking_id} created for order #{$order_id}.");
                            
                            // 2. Find an available driver
                            $availableDriver = $logisticsService->findAvailableDriver(
                                (int)$order_id,
                                (string)($order['delivery_date'] ?? '')
                            );
                            
                            if ($availableDriver) {
                                // 3. Assign the driver to the newly created tracking record
                                $logisticsService->assignDriver(
                                    $tracking_id,
                                    $availableDriver['id'],
                                    $availableDriver['first_name'] . ' ' . $availableDriver['last_name'],
                                    $availableDriver['phone'],
                                    $availableDriver['vehicle_details'] ?? ''
                                );
                                error_log("Order #{$order_id} auto-assigned to driver ID {$availableDriver['id']}.");
                            } else {
                            error_log("Auto-assign failed for order #{$order_id}: No available drivers found. Order needs manual assignment.");
                            // --- NEW: Notify admins ---
                            $admin_ids = getAdminUserIds($conn);
                            $notif_title = "Driver Assignment Needed";
                            $notif_message = "Order #" . $order['order_number'] . " requires manual driver assignment. No drivers were available.";
                            foreach ($admin_ids as $admin_id) {
                                createNotification($conn, $admin_id, 'driver_assignment_needed', $notif_title, $notif_message, $order_id, 'order');
                            }
                            // --- END NOTIFY ---
                        }
                    }
                    } catch (Exception $e) {
                        error_log("Auto-assign driver exception for order #{$order_id}: " . $e->getMessage());
                    }
                }
                // --- END AUTO-ASSIGN ---
                
                // Send payment confirmation email
                try {
                    $emailService = new EmailService($conn);
                    $emailService->sendPaymentConfirmation($order_id, [
                        'amount' => $order['downpayment_amount'] > 0 ? $order['downpayment_amount'] : $order['total_amount'],
                        'type' => $order['downpayment_amount'] > 0 ? 'downpayment' : 'full',
                        'method' => $order['payment_method'],
                        'transaction_id' => $transaction_id
                    ]);
                } catch (Exception $e) {
                    error_log("Email sending error: " . $e->getMessage());
                }
                
                // Determine the amount that was actually paid in this transaction
                $amount_paid = ($payment_status === 'partial') ? $order['downpayment_amount'] : $order['total_amount'];

                // Store order success data in session
                $_SESSION['order_success'] = [
                    'order_number' => $order['order_number'],
                    'amount_paid' => $amount_paid, // The amount paid in this transaction
                    'total_amount' => $order['total_amount'], // The total value of the order
                    'payment_method' => $order['payment_method'],
                    'payment_type' => $order['downpayment_amount'] > 0 ? 'downpayment' : 'full',
                    'downpayment_amount' => $order['downpayment_amount'],
                    'remaining_balance' => $order['remaining_balance']
                ];
                
                // Clear checkout session data
                unset($_SESSION['cart']);
                unset($_SESSION['pending_order']);
                unset($_SESSION['delivery_option']);
                unset($_SESSION['pickup_location']);
                unset($_SESSION['delivery_location']);
                
                error_log("Payment successful for order: " . $order_id);
                error_log("Order success data stored: " . json_encode($_SESSION['order_success']));
                
                // Show success message and redirect
                echo '<!DOCTYPE html>
<html>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: "Payment Successful!",
            text: "Your payment has been confirmed. You will receive an email confirmation shortly.",
            icon: "success",
            confirmButtonText: "View Order",
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(() => {
            window.location.href = "order_success.php";
        });
    </script>
</body>
</html>';
                exit();
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                throw $e;
            }
        } else {
            // Payment not yet paid
            error_log("Payment not paid yet. Status: " . $verification['status']);
            echo '<!DOCTYPE html>
<html>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: "Payment Processing",
            text: "Your payment is still processing. We will notify you once it\'s confirmed.",
            icon: "info",
            confirmButtonText: "OK"
        }).then(() => {
            window.location.href = "my_orders.php";
        });
    </script>
</body>
</html>';
            exit();
        }
    } else {
        throw new Exception("Payment verification failed: " . ($verification['error'] ?? 'Unknown error'));
    }
    
} catch (Exception $e) {
    error_log("Payment verification error: " . $e->getMessage());
    
    echo '<!DOCTYPE html>
<html>
<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: "Payment Verification Failed",
            text: "' . addslashes($e->getMessage()) . '",
            icon: "error",
            confirmButtonText: "Try Again"
        }).then(() => {
            window.location.href = "checkout.php";
        });
    </script>
</body>
</html>';
    exit();
}
?>
