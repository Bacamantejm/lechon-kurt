<?php
session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';
require_once 'email_service.php';
require_once 'includes/partner_voucher_helper.php';
require_once 'includes/checkout_address_helper.php';
require_once 'includes/delivery_pricing_helper.php';

$is_ajax_request =
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

function checkoutJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function checkoutRenderErrorScript(string $title, string $message): void
{
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>
        Swal.fire({
            title: "' . addslashes($title) . '",
            text: "' . addslashes($message) . '",
            icon: "error",
            confirmButtonText: "Go Back"
        }).then(() => {
            window.location.href = "checkout.php";
        });
    </script>';
    exit;
}

function checkoutFail(string $title, string $message, int $statusCode = 400, array $extra = []): void
{
    global $is_ajax_request;

    if ($is_ajax_request) {
        checkoutJsonResponse(array_merge([
            'success' => false,
            'message' => $message
        ], $extra), $statusCode);
    }

    checkoutRenderErrorScript($title, $message);
}

function checkoutRedirectTo(string $url, array $extra = []): void
{
    global $is_ajax_request;

    if ($is_ajax_request) {
        checkoutJsonResponse(array_merge([
            'success' => true,
            'redirect_url' => $url
        ], $extra));
    }

    header('Location: ' . $url);
    exit;
}

// Check if cart is empty
if (empty($_SESSION['cart'])) {
    if ($is_ajax_request) {
        checkoutJsonResponse([
            'success' => false,
            'message' => 'Your cart is empty.',
            'redirect_url' => 'menu.php'
        ], 400);
    }
    header('Location: menu.php');
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if ($is_ajax_request) {
        checkoutJsonResponse([
            'success' => false,
            'message' => 'Please log in to continue checkout.',
            'redirect_url' => 'login.php'
        ], 401);
    }
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pvEnsureVoucherSchema($conn);
    caEnsureUserSavedAddressSchema($conn);

    $cart_tenant_scope = function_exists('pvGetCheckoutTenantScope')
        ? pvGetCheckoutTenantScope($conn, $_SESSION['cart'])
        : ['is_valid' => true, 'seller_id' => 0, 'message' => ''];
    if (empty($cart_tenant_scope['is_valid'])) {
        if (function_exists('pvClearAppliedVoucherSession')) {
            pvClearAppliedVoucherSession();
        }
        checkoutFail(
            'Checkout Error',
            (string)($cart_tenant_scope['message'] ?? 'Your cart contains items from multiple stores. Please checkout one store at a time.'),
            409,
            ['code' => 'MIXED_TENANT_CART']
        );
    }
    $checkout_seller_owner_id = (int)($cart_tenant_scope['seller_id'] ?? 0);
    if ($checkout_seller_owner_id > 0) {
        $_SESSION['storefront_seller_id'] = $checkout_seller_owner_id;
    }

    // Validate required fields
    $required_fields = ['full_name', 'email', 'phone', 'payment_method', 'delivery_date', 'delivery_time', 'delivery_option', 'payment_type'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            checkoutFail(
                'Checkout Error',
                'Missing required field: ' . $field,
                422,
                ['field' => $field]
            );
        }
    }
    
    // Get form data
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $full_name = normalizeUserProfileName($_POST['full_name'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    if (!isValidUserProfileName($full_name)) {
        checkoutFail('Checkout Error', 'Please enter a valid full name.', 422, ['field' => 'full_name']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        checkoutFail('Checkout Error', 'Please enter a valid email address.', 422, ['field' => 'email']);
    }
    if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) {
        checkoutFail('Checkout Error', 'Please enter a valid mobile number.', 422, ['field' => 'phone']);
    }
    $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date']);
    $delivery_time = mysqli_real_escape_string($conn, $_POST['delivery_time']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $payment_type = mysqli_real_escape_string($conn, $_POST['payment_type']);
    $delivery_option = mysqli_real_escape_string($conn, $_POST['delivery_option']);
    $order_notes = isset($_POST['order_notes']) ? mysqli_real_escape_string($conn, $_POST['order_notes']) : '';
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $downpayment_amount = floatval($_POST['downpayment_amount'] ?? 0);
    $calculated_delivery_fee = isset($_POST['calculated_delivery_fee']) ? floatval($_POST['calculated_delivery_fee']) : 0;
    $distance_km = isset($_POST['distance_km']) ? floatval($_POST['distance_km']) : 0;
    $street_address = trim((string)($_POST['street_address'] ?? ''));
    $delivery_region_name = trim((string)($_POST['delivery_region_name'] ?? ''));
    $delivery_region_code = trim((string)($_POST['delivery_region_code'] ?? ''));
    $delivery_province_name = trim((string)($_POST['delivery_province_name'] ?? ''));
    $delivery_province_code = trim((string)($_POST['delivery_province_code'] ?? ''));
    $delivery_city_name = trim((string)($_POST['delivery_city_name'] ?? ''));
    $delivery_city_code = trim((string)($_POST['delivery_city_code'] ?? ''));
    $delivery_barangay_name = trim((string)($_POST['delivery_barangay_name'] ?? ''));
    $delivery_barangay_code = trim((string)($_POST['delivery_barangay_code'] ?? ''));
    
    // Handle delivery/pickup specific data
    if ($delivery_option === 'delivery') {
        $delivery_address = isset($_POST['delivery_address']) ? mysqli_real_escape_string($conn, $_POST['delivery_address']) : '';
        $delivery_instructions = isset($_POST['delivery_instructions']) ? mysqli_real_escape_string($conn, $_POST['delivery_instructions']) : '';
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : NULL;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : NULL;
        $delivery_location = isset($_POST['delivery_location']) ? mysqli_real_escape_string($conn, $_POST['delivery_location']) : 'metro_manila';
        $pickup_location = NULL;
        
        // Validate delivery address
        if (empty($delivery_address)) {
            checkoutFail(
                'Checkout Error',
                'Delivery address is required for delivery option.',
                422,
                ['field' => 'delivery_address']
            );
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
            checkoutFail('Checkout Error', 'Unable to load pickup location details right now.', 500);
        }
        mysqli_stmt_bind_param($store_stmt, "i", $pickup_location);
        mysqli_stmt_execute($store_stmt);
        $store_result = mysqli_stmt_get_result($store_stmt);
        $store = mysqli_fetch_assoc($store_result);
        mysqli_stmt_close($store_stmt);

        if (!$store) {
            checkoutFail(
                'Checkout Error',
                'Selected pickup location was not found. Please choose another branch.',
                422,
                ['field' => 'pickup_location']
            );
        }
        
        $delivery_address = $store['store_name'] . ', ' . $store['address'];
    }
    
    // Calculate order totals
    $subtotal = 0;
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    $delivery_pricing_note = '';
    $delivery_quote = null;
    $deliveryPricingConfig = dpGetDeliveryPricingConfig();
    // Get delivery fee
    $delivery_fee = 0;
    if ($delivery_option === 'delivery') {
        if ($latitude !== null && $longitude !== null) {
            $delivery_quote = dpBuildDeliveryQuote(
                $_SESSION['store_locations'] ?? [],
                (float)$latitude,
                (float)$longitude,
                $checkout_seller_owner_id > 0
                    ? $checkout_seller_owner_id
                    : (int)($_SESSION['storefront_seller_id'] ?? 0),
                $deliveryPricingConfig
            );
        }

        if (!empty($delivery_quote['success'])) {
            $delivery_fee = (float)($delivery_quote['fee'] ?? 0);
            $distance_km = (float)($delivery_quote['distance_km'] ?? $distance_km);
            $delivery_pricing_note = 'Nearest fulfillment store: ' . (string)($delivery_quote['nearest_store_name'] ?? 'Nearest Store');
            if ($distance_km > 0) {
                $delivery_pricing_note .= ' | Delivery Distance: ' . number_format($distance_km, 2) . ' km';
            }
            if (!empty($delivery_quote['estimated_delivery_text'])) {
                $delivery_pricing_note .= ' | ' . (string)$delivery_quote['estimated_delivery_text'];
            }
            $_SESSION['current_delivery_quote'] = $delivery_quote;
        } elseif ($calculated_delivery_fee > 0) {
            $delivery_fee = $calculated_delivery_fee;
            if ($distance_km > 0) {
                $delivery_pricing_note = 'Delivery Distance: ' . number_format($distance_km, 2) . ' km';
            }
        } else {
            checkoutFail(
                'Checkout Error',
                'Please pin your exact delivery location so the system can calculate the correct per-kilometer delivery fee.',
                422,
                ['field' => 'delivery_address']
            );
        }
    }

    if ($delivery_pricing_note !== '') {
        $order_notes .= ($order_notes ? "\n" : '') . $delivery_pricing_note;
    }
    
    $vat_rate = 0.12;
    $vat_amount = round($subtotal * $vat_rate, 2);

    $voucher_state = pvResolveAppliedVoucherState($conn, (int)$user_id, $_SESSION['cart']);
    $voucher_discount = 0.0;
    $voucher_id = 0;
    $voucher_code = '';
    if (!empty($voucher_state['applied'])) {
        $voucher_discount = max(0, round((float)($voucher_state['discount_amount'] ?? 0), 2));
        $voucher_id = (int)($voucher_state['voucher_id'] ?? 0);
        $voucher_code = (string)($voucher_state['voucher_code'] ?? '');
    }

    $total_amount = max(0, $subtotal + $vat_amount + $delivery_fee - $voucher_discount);
    
    // Calculate payment amounts
    if ($payment_type === 'downpayment') {
        $downpayment_amount = $total_amount * 0.30;
        $remaining_balance = $total_amount - $downpayment_amount;
        $payment_status = 'partial';
    } else {
        $downpayment_amount = 0;
        $remaining_balance = 0;
        $payment_status = 'pending'; // Will be updated after payment
    }
    
    ensureOrdersTableSchema($conn);

    // Generate order number (19 chars: ORD-YYYYMMDD-XXXXXX)
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    // Start transaction

    mysqli_begin_transaction($conn);
    
    try {
        // Set status variable
        $status = 'pending';
        
        // Debug: Check what data we have
        error_log("Creating order with data:");
        error_log("User ID: " . $user_id);
        error_log("Order Number: " . $order_number);
        error_log("Full Name: " . $full_name);
        error_log("Email: " . $email);
        error_log("Phone: " . $phone);
        error_log("Delivery Address: " . $delivery_address);
        error_log("Delivery Date: " . $delivery_date);
        error_log("Delivery Time: " . $delivery_time);
        error_log("Payment Method: " . $payment_method);
        error_log("Delivery Option: " . $delivery_option);
        error_log("Pickup Location: " . ($pickup_location ?? 'NULL'));
        error_log("Delivery Location: " . ($delivery_location ?? 'NULL'));
        error_log("Subtotal: " . $subtotal);
        error_log("VAT Amount: " . $vat_amount);
        error_log("Delivery Fee: " . $delivery_fee);
        error_log("Voucher ID: " . $voucher_id);
        error_log("Voucher Code: " . $voucher_code);
        error_log("Voucher Discount: " . $voucher_discount);
        error_log("Total Amount: " . $total_amount);
        error_log("Order Notes: " . $order_notes);
        error_log("Latitude: " . ($latitude ?? 'NULL'));
        error_log("Longitude: " . ($longitude ?? 'NULL'));
        error_log("Delivery Instructions: " . $delivery_instructions);
        error_log("Payment Status: " . $payment_status);
        error_log("Downpayment Amount: " . $downpayment_amount);
        error_log("Remaining Balance: " . $remaining_balance);
        error_log("Status: " . $status);
        
        // Check if columns exist in database
        $check_columns = "SHOW COLUMNS FROM orders";
        $columns_result = mysqli_query($conn, $check_columns);
        $columns = [];
        while ($row = mysqli_fetch_assoc($columns_result)) {
            $columns[] = $row['Field'];
        }
        $has_voucher_columns = in_array('voucher_id', $columns, true)
            && in_array('voucher_code', $columns, true)
            && in_array('voucher_discount', $columns, true);
        
        // Insert order based on actual database structure
        if (in_array('delivery_option', $columns) && 
            in_array('pickup_location', $columns) && 
            in_array('delivery_location', $columns)) {
            // Database has new columns
            $query = "INSERT INTO orders (
                order_number, user_id, customer_name, customer_email, customer_phone, 
                delivery_address, delivery_date, delivery_time, payment_method,
                delivery_option, pickup_location, delivery_location,
                subtotal, delivery_fee, total_amount, special_instructions,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($conn));
            }
            
            // Handle NULL values properly
            $pickup_location_param = $pickup_location ?? NULL;
            $delivery_location_param = $delivery_location ?? NULL;
            
            mysqli_stmt_bind_param($stmt, "sissssssssiisddss", 
                $order_number,          // s
                $user_id,               // i
                $full_name,             // s
                $email,                 // s
                $phone,                 // s
                $delivery_address,      // s
                $delivery_date,         // s
                $delivery_time,         // s
                $payment_method,        // s
                $delivery_option,       // s
                $pickup_location_param, // i (could be NULL)
                $delivery_location_param, // s (could be NULL)
                $subtotal,              // d
                $delivery_fee,          // d
                $total_amount,          // d
                $order_notes,           // s
                $status                 // s - must be a variable, not literal
            );
        } else {
            // Database has old structure
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
                $order_number,          // s
                $user_id,               // i
                $full_name,             // s
                $email,                 // s
                $phone,                 // s
                $delivery_address,      // s
                $delivery_date,         // s
                $delivery_time,         // s
                $payment_method,        // s
                $subtotal,              // d
                $delivery_fee,          // d
                $total_amount,          // d
                $order_notes,           // s
                $status                 // s - must be a variable, not literal
            );
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to create order: " . mysqli_stmt_error($stmt));
        }
        
        $order_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($has_voucher_columns) {
            $voucher_update_sql = "UPDATE orders
                                   SET voucher_id = NULLIF(?, 0),
                                       voucher_code = NULLIF(?, ''),
                                       voucher_discount = ?
                                   WHERE id = ?";
            $voucher_update_stmt = mysqli_prepare($conn, $voucher_update_sql);
            if (!$voucher_update_stmt) {
                throw new Exception("Failed to prepare voucher update: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($voucher_update_stmt, "isdi", $voucher_id, $voucher_code, $voucher_discount, $order_id);
            if (!mysqli_stmt_execute($voucher_update_stmt)) {
                throw new Exception("Failed to update order voucher fields: " . mysqli_stmt_error($voucher_update_stmt));
            }
            mysqli_stmt_close($voucher_update_stmt);
        }

        if ($delivery_option === 'delivery' && in_array('customer_coordinates', $columns, true) && $latitude !== null && $longitude !== null) {
            $customer_coordinates = json_encode([
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
            ]);
            $coords_stmt = mysqli_prepare($conn, "UPDATE orders SET customer_coordinates = ? WHERE id = ?");
            if ($coords_stmt) {
                mysqli_stmt_bind_param($coords_stmt, "si", $customer_coordinates, $order_id);
                mysqli_stmt_execute($coords_stmt);
                mysqli_stmt_close($coords_stmt);
            }
        }
        
        // Insert order items
        foreach ($_SESSION['cart'] as $item) {
            $product_id = $item['product_id'] ?? '';
            $product_name = $item['name'] ?? '';
            $price = floatval($item['price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            $size = isset($item['size']) ? $item['size'] : 'Regular';
            
            if (empty($product_id)) {
                $product_id = 'unknown-' . uniqid();
            }
            
            $query = "INSERT INTO order_items (order_id, product_id, product_name, price, quantity, size, addons, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed for order items: " . mysqli_error($conn));
            }
            
            $addons_json = !empty($item['addons']) ? json_encode($item['addons']) : '[]';
            $item_total = $price * $quantity;
            
            mysqli_stmt_bind_param($stmt, "issdissd", 
                $order_id, $product_id, $product_name, $price, $quantity, $size, $addons_json, $item_total
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to add order item: " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }

        // Bridge customer->admin workflow by creating order intake notifications
        if (function_exists('createNotification')) {
            $customer_notif_title = 'Order Submitted';
            $customer_notif_message = 'Your order #' . $order_number . ' was submitted. We will confirm it after payment verification.';
            createNotification($conn, (int)$user_id, 'order_submitted', $customer_notif_title, $customer_notif_message, (int)$order_id, 'order');

            if (function_exists('getAdminUserIds')) {
                $admin_ids = getAdminUserIds($conn);
                $admin_notif_title = 'New Customer Order';
                $admin_notif_message = 'Order #' . $order_number . ' has been submitted by ' . $full_name . '.';
                foreach ($admin_ids as $admin_id) {
                    createNotification($conn, (int)$admin_id, 'order_submitted', $admin_notif_title, $admin_notif_message, (int)$order_id, 'order');
                }
            }
        }

        $voucher_redeem_result = pvRedeemVoucherForOrder(
            $conn,
            $voucher_state,
            (int)$order_id,
            (int)$user_id,
            (float)$subtotal
        );
        if (!($voucher_redeem_result['success'] ?? false)) {
            throw new Exception($voucher_redeem_result['message'] ?? 'Failed to redeem voucher.');
        }

        if ($delivery_option === 'delivery' && trim((string)$delivery_address) !== '') {
            $auto_save_address_payload = [
                'label' => 'Checkout Address',
                'contact_name' => $full_name,
                'contact_phone' => $phone,
                'street_address' => $street_address,
                'region_name' => $delivery_region_name,
                'region_code' => $delivery_region_code,
                'province_name' => $delivery_province_name,
                'province_code' => $delivery_province_code,
                'city_name' => $delivery_city_name,
                'city_code' => $delivery_city_code,
                'barangay_name' => $delivery_barangay_name,
                'barangay_code' => $delivery_barangay_code,
                'full_address' => $delivery_address,
                'latitude' => $latitude,
                'longitude' => $longitude
            ];
            $address_save_result = caSaveUserSavedAddress($conn, (int)$user_id, $auto_save_address_payload, false);
            if (!($address_save_result['success'] ?? false)) {
                error_log('Checkout address save warning: ' . (string)($address_save_result['message'] ?? 'Unknown address save error.'));
            }

            $profile_name_to_store = $full_name;
            $profile_name_changed = false;
            $profile_email_for_sync = $email;
            $profile_lookup_stmt = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            if ($profile_lookup_stmt) {
                mysqli_stmt_bind_param($profile_lookup_stmt, "i", $user_id);
                mysqli_stmt_execute($profile_lookup_stmt);
                $profile_lookup_result = mysqli_stmt_get_result($profile_lookup_stmt);
                $profile_lookup_row = $profile_lookup_result ? mysqli_fetch_assoc($profile_lookup_result) : null;
                mysqli_stmt_close($profile_lookup_stmt);

                if (!empty($profile_lookup_row['email'])) {
                    $profile_email_for_sync = strtolower(trim((string)$profile_lookup_row['email']));
                }

                $current_profile_name = normalizeUserProfileName((string)($profile_lookup_row['full_name'] ?? ''));
                $policy = evaluateUserNameChangePolicy($conn, $user_id, $current_profile_name, $full_name, 7);
                if (($policy['success'] ?? false) && ($policy['can_apply'] ?? false)) {
                    $profile_name_to_store = $full_name;
                    $profile_name_changed = (bool)($policy['name_changed'] ?? false);
                } else {
                    $profile_name_to_store = $current_profile_name !== '' ? $current_profile_name : $full_name;
                    if (!userProfileNamesMatch($profile_name_to_store, $full_name)) {
                        error_log('Checkout profile update skipped full name change due policy for user #' . $user_id);
                    }
                }
            }

            $profile_update_stmt = mysqli_prepare($conn, "UPDATE users SET full_name = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?");
            if ($profile_update_stmt) {
                mysqli_stmt_bind_param($profile_update_stmt, "sssi", $profile_name_to_store, $phone, $delivery_address, $user_id);
                if (!mysqli_stmt_execute($profile_update_stmt)) {
                    error_log('Checkout profile update warning: ' . mysqli_stmt_error($profile_update_stmt));
                } else {
                    if ($profile_name_changed) {
                        recordUserNameChangeTimestamp($conn, $user_id);
                    }
                    syncUserProfileReferences($conn, $user_id, $profile_name_to_store, $profile_email_for_sync, $phone);
                }
                mysqli_stmt_close($profile_update_stmt);
            }
        }
        
        // Check if payments table exists and insert payment record
        $check_payments = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
        $payments_table_exists = $check_payments && mysqli_num_rows($check_payments) > 0;
        if ($payments_table_exists) {
            // Insert initial payment record
            $payment_amount = ($payment_type === 'downpayment') ? $downpayment_amount : $total_amount;
            $payment_record = "INSERT INTO payments (order_id, payment_type, amount, payment_method, status) VALUES (?, ?, ?, ?, 'pending')";
            $payment_stmt = mysqli_prepare($conn, $payment_record);
            if (!$payment_stmt) {
                throw new Exception("Failed to prepare payment insert: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($payment_stmt, "isds", $order_id, $payment_type, $payment_amount, $payment_method);
            if (!mysqli_stmt_execute($payment_stmt)) {
                throw new Exception("Failed to create payment record: " . mysqli_stmt_error($payment_stmt));
            }
            mysqli_stmt_close($payment_stmt);
            
            // Update order with payment information if columns exist
            if (in_array('payment_status', $columns) && 
                in_array('downpayment_amount', $columns) && 
                in_array('remaining_balance', $columns)) {
                $update_payment_query = "UPDATE orders SET 
                    payment_status = ?,
                    downpayment_amount = ?,
                    remaining_balance = ?
                    WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_payment_query);
                if (!$update_stmt) {
                    throw new Exception("Failed to prepare order payment update: " . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($update_stmt, "sddi", 
                    $payment_status, 
                    $downpayment_amount, 
                    $remaining_balance,
                    $order_id
                );
                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception("Failed to update order payment fields: " . mysqli_stmt_error($update_stmt));
                }
                mysqli_stmt_close($update_stmt);
            }
        }
        
        // Commit transaction
        mysqli_commit($conn);
        pvClearAppliedVoucherSession();
        
        // All payments go through PayMongo
        $payment_amount = ($payment_type === 'downpayment') ? $downpayment_amount : $total_amount;

        // Initialize PayMongo
        $paymongo_secret = appConfigValue('PAYMONGO_SECRET_KEY');
        $paymongo_public = appConfigValue('PAYMONGO_PUBLIC_KEY');
        $paymongo = new PayMongoIntegration($paymongo_secret, $paymongo_public);

        // Prepare checkout session data
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
            // Save checkout session ID to database
            if (!empty($payments_table_exists)) {
                $query = "UPDATE payments SET checkout_session_id = ? WHERE order_id = ? ORDER BY id DESC LIMIT 1";
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "si", $result['session_id'], $order_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                } else {
                    error_log("Unable to update checkout session ID: " . mysqli_error($conn));
                }
            }

            // Send order confirmation email
            try {
                $emailService = new EmailService($conn);
                $emailService->sendOrderConfirmation($order_id);
            } catch (Exception $e) {
                error_log("Email sending error: " . $e->getMessage());
            }

            // Redirect to PayMongo checkout
            checkoutRedirectTo($result['checkout_url'], [
                'order_id' => (int)$order_id
            ]);
        } else {
            // Handle error
            $errorMessage = $result['error'] ?? 'Unknown error occurred';
            error_log("PayMongo checkout creation failed: " . $errorMessage);

            checkoutFail(
                'Payment Error',
                'Could not create payment session: ' . $errorMessage,
                502,
                ['order_id' => (int)$order_id]
            );
        }
        
    } catch (Throwable $e) {
        // Rollback transaction
        if (isset($conn) && mysqli_ping($conn)) {
            mysqli_rollback($conn);
        }
        
        error_log("Order processing error: " . $e->getMessage());

        checkoutFail(
            'Order Error',
            'There was an error processing your order: ' . $e->getMessage(),
            500
        );
    }
} else {
    if ($is_ajax_request) {
        checkoutJsonResponse([
            'success' => false,
            'message' => 'Invalid request method.'
        ], 405);
    }
    header('Location: checkout.php');
    exit;
}
?>
