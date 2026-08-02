<?php
/**
 * Enhanced Order Processing with Automatic Driver Assignment
 * This file processes orders and creates logistics tracking records
 */
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';
require_once 'email_service.php';
require_once 'EnhancedLogisticsService.php';
require_once 'includes/partner_voucher_helper.php';

// Data validation and error handling class
class OrderValidator {
    private $errors = [];
    
    public function validate($data) {
        $this->errors = [];
        
        // Required fields validation
        $required_fields = ['full_name', 'email', 'phone', 'payment_method', 'delivery_date', 'delivery_time', 'delivery_option', 'payment_type'];
        foreach ($required_fields as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                $this->errors[] = "Missing required field: $field";
            }
        }
        
        // Email validation
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Invalid email address";
        }
        
        // Phone validation (Philippine format)
        if (isset($data['phone']) && !preg_match('/^[0-9]{11}$/', $data['phone'])) {
            $this->errors[] = "Phone number must be 11 digits";
        }
        
        // Delivery option validation
        if (!in_array($data['delivery_option'] ?? '', ['delivery', 'pickup'])) {
            $this->errors[] = "Invalid delivery option";
        }
        
        // Delivery address required for delivery option
        if ($data['delivery_option'] === 'delivery' && empty(trim($data['delivery_address'] ?? ''))) {
            $this->errors[] = "Delivery address is required for delivery option";
        }
        
        // Amount validation
        $total_amount = floatval($data['total_amount'] ?? 0);
        if ($total_amount <= 0) {
            $this->errors[] = "Invalid order amount";
        }
        
        // Payment type validation
        if (!in_array($data['payment_type'] ?? '', ['full', 'downpayment'])) {
            $this->errors[] = "Invalid payment type";
        }
        
        return empty($this->errors);
    }
    
    public function getErrors() {
        return $this->errors;
    }
}

// Check if cart is empty
if (empty($_SESSION['cart'])) {
    die(json_encode(['success' => false, 'message' => 'Cart is empty']));
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validator = new OrderValidator();
    
    // Validate all inputs
    if (!$validator->validate($_POST)) {
        $errors = $validator->getErrors();
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        echo '<script>
            Swal.fire({
                title: "Validation Error",
                text: "' . addslashes(implode("\n", $errors)) . '",
                icon: "error",
                confirmButtonText: "Go Back"
            }).then(() => {
                window.location.href = "checkout.php";
            });
        </script>';
        exit;
    }

    $cart_tenant_scope = function_exists('pvGetCheckoutTenantScope')
        ? pvGetCheckoutTenantScope($conn, $_SESSION['cart'])
        : ['is_valid' => true, 'seller_id' => 0, 'message' => ''];
    if (empty($cart_tenant_scope['is_valid'])) {
        if (function_exists('pvClearAppliedVoucherSession')) {
            pvClearAppliedVoucherSession();
        }
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        echo '<script>
            Swal.fire({
                title: "Checkout Error",
                text: "' . addslashes((string)($cart_tenant_scope['message'] ?? 'Your cart contains items from multiple stores. Please checkout one store at a time.')) . '",
                icon: "error",
                confirmButtonText: "Go Back"
            }).then(() => {
                window.location.href = "checkout.php";
            });
        </script>';
        exit;
    }
    $checkout_seller_owner_id = (int)($cart_tenant_scope['seller_id'] ?? 0);
    if ($checkout_seller_owner_id > 0) {
        $_SESSION['storefront_seller_id'] = $checkout_seller_owner_id;
    }
    
    // Sanitize all inputs
    $user_id = $_SESSION['user_id'];
    $full_name = trim(mysqli_real_escape_string($conn, $_POST['full_name']));
    $email = trim(mysqli_real_escape_string($conn, $_POST['email']));
    $phone = trim(mysqli_real_escape_string($conn, $_POST['phone']));
    $delivery_date = trim(mysqli_real_escape_string($conn, $_POST['delivery_date']));
    $delivery_time = trim(mysqli_real_escape_string($conn, $_POST['delivery_time']));
    $payment_method = trim(mysqli_real_escape_string($conn, $_POST['payment_method']));
    $payment_type = trim(mysqli_real_escape_string($conn, $_POST['payment_type']));
    $delivery_option = trim(mysqli_real_escape_string($conn, $_POST['delivery_option']));
    $order_notes = isset($_POST['order_notes']) ? trim(mysqli_real_escape_string($conn, $_POST['order_notes'])) : '';
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $downpayment_amount = floatval($_POST['downpayment_amount'] ?? 0);
    $calculated_delivery_fee = isset($_POST['calculated_delivery_fee']) ? floatval($_POST['calculated_delivery_fee']) : 0;
    $distance_km = isset($_POST['distance_km']) ? floatval($_POST['distance_km']) : 0;
    
    // Handle delivery/pickup specific data
    if ($delivery_option === 'delivery') {
        $delivery_address = trim(mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? ''));
        $delivery_instructions = isset($_POST['delivery_instructions']) ? trim(mysqli_real_escape_string($conn, $_POST['delivery_instructions'])) : '';
        $latitude = isset($_POST['latitude']) && !empty($_POST['latitude']) ? floatval($_POST['latitude']) : NULL;
        $longitude = isset($_POST['longitude']) && !empty($_POST['longitude']) ? floatval($_POST['longitude']) : NULL;
        $delivery_location = isset($_POST['delivery_location']) ? trim(mysqli_real_escape_string($conn, $_POST['delivery_location'])) : 'metro_manila';
        $pickup_location = NULL;
        
        if (empty($delivery_address)) {
            throw new Exception("Delivery address is required for delivery option");
        }
    } else {
        // Pickup option
        $pickup_location = isset($_POST['pickup_location']) ? intval($_POST['pickup_location']) : 1;
        $delivery_location = NULL;
        $latitude = NULL;
        $longitude = NULL;
        $delivery_instructions = '';
        
        // Get store address for pickup
        $store_query = "SELECT store_name, address FROM store_locations WHERE store_id = ?";
        $store_stmt = mysqli_prepare($conn, $store_query);
        if (!$store_stmt) {
            throw new Exception("Database error retrieving store");
        }
        mysqli_stmt_bind_param($store_stmt, "i", $pickup_location);
        mysqli_stmt_execute($store_stmt);
        $store_result = mysqli_stmt_get_result($store_stmt);
        $store = mysqli_fetch_assoc($store_result);
        mysqli_stmt_close($store_stmt);
        
        if (!$store) {
            throw new Exception("Invalid pickup location selected");
        }
        
        $delivery_address = $store['store_name'] . ', ' . $store['address'];
    }
    
    // Calculate order totals
    $subtotal = 0;
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += floatval($item['price']) * intval($item['quantity']);
    }
    
    // Get delivery fee
    $delivery_fee = 0;
    if ($delivery_option === 'delivery') {
        if ($calculated_delivery_fee > 0) {
            $delivery_fee = $calculated_delivery_fee;
            if ($distance_km > 0) {
                $order_notes .= ($order_notes ? "\n" : "") . "Distance: " . number_format($distance_km, 2) . "km";
            }
        } else {
            $delivery_location = $_POST['delivery_location'] ?? 'metro_manila';
            if (isset($_SESSION['delivery_fees'][$delivery_location])) {
                $delivery_fee = $_SESSION['delivery_fees'][$delivery_location]['fee'];
            }
        }
    }
    
    $vat_rate = 0.12;
    $vat_amount = round($subtotal * $vat_rate, 2);
    $total_amount = $subtotal + $vat_amount + $delivery_fee;
    
    // Calculate payment amounts
    if ($payment_type === 'downpayment') {
        $downpayment_amount = $total_amount * 0.30;
        $remaining_balance = $total_amount - $downpayment_amount;
        $payment_status = 'partial';
    } else {
        $downpayment_amount = 0;
        $remaining_balance = 0;
        $payment_status = 'pending';
    }
    
    // Generate order number
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(uniqid());
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        $status = 'pending';
        
        // Get actual columns in orders table
        $check_columns = "SHOW COLUMNS FROM orders";
        $columns_result = mysqli_query($conn, $check_columns);
        if (!$columns_result) {
            throw new Exception("Database schema error");
        }
        
        $columns = [];
        while ($row = mysqli_fetch_assoc($columns_result)) {
            $columns[] = $row['Field'];
        }
        
        // Insert order with appropriate columns
        if (in_array('delivery_option', $columns) && 
            in_array('pickup_location', $columns) && 
            in_array('delivery_location', $columns)) {
            
            $query = "INSERT INTO orders (
                order_number, user_id, customer_name, customer_email, customer_phone, 
                delivery_address, delivery_date, delivery_time, payment_method,
                delivery_option, pickup_location, delivery_location,
                subtotal, delivery_fee, total_amount, special_instructions,
                status, delivery_instructions, estimated_delivery_time
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 45 MINUTE))";
            
            $stmt = mysqli_prepare($conn, $query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($conn));
            }
            
            $pickup_location_param = $pickup_location ?? NULL;
            $delivery_location_param = $delivery_location ?? NULL;
            
            mysqli_stmt_bind_param($stmt, "sisssssssiisddssss", 
                $order_number,
                $user_id,
                $full_name,
                $email,
                $phone,
                $delivery_address,
                $delivery_date,
                $delivery_time,
                $payment_method,
                $delivery_option,
                $pickup_location_param,
                $delivery_location_param,
                $subtotal,
                $delivery_fee,
                $total_amount,
                $order_notes,
                $status,
                $delivery_instructions
            );
        } else {
            // Legacy schema
            $query = "INSERT INTO orders (
                order_number, user_id, customer_name, customer_email, customer_phone, 
                delivery_address, delivery_date, delivery_time, payment_method,
                subtotal, delivery_fee, total_amount, special_instructions,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($stmt, "sisssssssdddss", 
                $order_number,
                $user_id,
                $full_name,
                $email,
                $phone,
                $delivery_address,
                $delivery_date,
                $delivery_time,
                $payment_method,
                $subtotal,
                $delivery_fee,
                $total_amount,
                $order_notes,
                $status
            );
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to create order: " . mysqli_stmt_error($stmt));
        }
        
        $order_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        error_log("Order created successfully - ID: $order_id");
        
        // Insert order items
        foreach ($_SESSION['cart'] as $item) {
            $product_id = $item['product_id'] ?? 'unknown-' . uniqid();
            $product_name = trim(mysqli_real_escape_string($conn, $item['name'] ?? ''));
            $price = floatval($item['price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            $size = isset($item['size']) ? trim(mysqli_real_escape_string($conn, $item['size'])) : 'Regular';
            $addons_json = !empty($item['addons']) ? json_encode($item['addons']) : '[]';
            $item_total = $price * $quantity;
            
            $item_query = "INSERT INTO order_items (order_id, product_id, product_name, price, quantity, size, addons, total) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $item_stmt = mysqli_prepare($conn, $item_query);
            
            if (!$item_stmt) {
                throw new Exception("Prepare failed for order items: " . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($item_stmt, "issdissd", 
                $order_id, $product_id, $product_name, $price, $quantity, $size, $addons_json, $item_total
            );
            
            if (!mysqli_stmt_execute($item_stmt)) {
                throw new Exception("Failed to add order item: " . mysqli_stmt_error($item_stmt));
            }
            mysqli_stmt_close($item_stmt);
        }
        
        // Insert payment record
        $check_payments = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
        if ($check_payments && mysqli_num_rows($check_payments) > 0) {
            $payment_amount = ($payment_type === 'downpayment') ? $downpayment_amount : $total_amount;
            $payment_record = "INSERT INTO payments (order_id, payment_type, amount, payment_method, status) 
                             VALUES (?, ?, ?, ?, 'pending')";
            $payment_stmt = mysqli_prepare($conn, $payment_record);
            if ($payment_stmt) {
                mysqli_stmt_bind_param($payment_stmt, "isds", $order_id, $payment_type, $payment_amount, $payment_method);
                mysqli_stmt_execute($payment_stmt);
                mysqli_stmt_close($payment_stmt);
            }
        }
        
        // Create logistics tracking for delivery orders
        if ($delivery_option === 'delivery') {
            $logistics_service = new EnhancedLogisticsService($conn);
            $tracking_result = $logistics_service->createTrackingForOrder($order_id, $delivery_address, $latitude, $longitude, $delivery_instructions);
            
            if ($tracking_result['success']) {
                error_log("Tracking created - Order: $order_id, Tracking: " . $tracking_result['tracking_id']);
            } else {
                error_log("Failed to create tracking: " . $tracking_result['message']);
            }
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        error_log("Order processing completed - Order Number: $order_number");
        
        // Initialize PayMongo
        $paymongo = new PayMongoIntegration(
            'sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE',
            'pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE'
        );
        
        // Prepare checkout session data
        $payment_amount = ($payment_type === 'downpayment') ? $downpayment_amount : $total_amount;
        $checkoutData = [
            'amount' => $payment_amount,
            'description' => 'Order #' . $order_number . ' - Lechon Delights',
            'order_id' => $order_id,
            'customer_name' => $full_name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_success.php?order_id=' . $order_id,
            'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_cancel.php?order_id=' . $order_id,
            'payment_method' => $payment_method
        ];
        
        $result = $paymongo->createCheckoutSession($checkoutData);
        
        if ($result['success']) {
            // Save checkout session ID
            $query = "UPDATE payments SET checkout_session_id = ? WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $result['session_id'], $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            // Send order confirmation email
            try {
                $emailService = new EmailService($conn);
                $emailService->sendOrderConfirmation($order_id);
            } catch (Exception $e) {
                error_log("Email sending error: " . $e->getMessage());
            }
            
            // Clear cart
            unset($_SESSION['cart']);
            
            // Redirect to payment
            header('Location: ' . $result['checkout_url']);
            exit;
        } else {
            throw new Exception($result['error'] ?? 'Unknown payment error');
        }
        
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && mysqli_ping($conn)) {
            mysqli_rollback($conn);
        }
        
        error_log("Order processing error: " . $e->getMessage());
        
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        echo '<script>
            Swal.fire({
                title: "Order Error",
                text: "' . addslashes($e->getMessage()) . '",
                icon: "error",
                confirmButtonText: "Go Back"
            }).then(() => {
                window.location.href = "checkout.php";
            });
        </script>';
        exit;
    }
} else {
    header('Location: checkout.php');
    exit;
}
?>
