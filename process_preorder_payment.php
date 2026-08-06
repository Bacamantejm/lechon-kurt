<?php
// Start output buffering to catch any unexpected output
ob_start();

// #region agent log
/**
 * Debug logging for Cursor debug mode (NDJSON to .cursor/debug.log)
 */
function agent_debug_log($message, $data = [], $hypothesisId = '')
{
    $logPath = 'c:\\xampp\\htdocs\\lechonsystem\\.cursor\\debug.log';
    $entry = [
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix-1',
        'hypothesisId' => $hypothesisId,
        'location' => 'process_preorder_payment.php',
        'message' => $message,
        'data' => $data,
        'timestamp' => round(microtime(true) * 1000),
    ];

    // Suppress any errors from logging to avoid impacting main flow
    try {
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($logPath, json_encode($entry) . PHP_EOL, FILE_APPEND);
    } catch (Throwable $e) {
        // Ignore logging failures
    }
}
// #endregion

session_start();
require_once 'includes/config.php';
require_once 'paymongo_integration.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON header immediately
header('Content-Type: application/json', true);

// Set custom error handler to prevent output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    // #region agent log
    if (function_exists('agent_debug_log')) {
        agent_debug_log(
            'PHP error handler captured error',
            [
                'errno' => $errno,
                'errstr' => $errstr,
                'file' => $errfile,
                'line' => $errline,
            ],
            'HX'
        );
    }
    // #endregion
    return true; // Don't execute PHP's default error handler
});

// Capture fatal errors on shutdown (e.g., E_ERROR) for debugging
register_shutdown_function(function() {
    $lastError = error_get_last();
    if ($lastError && function_exists('agent_debug_log')) {
        agent_debug_log(
            'Shutdown handler last error',
            $lastError,
            'HZ'
        );
    }
});

// Log errors to file
$log_file = 'logs/payment_errors.log';
if (!is_dir('logs')) {
    mkdir('logs', 0755, true);
}

function log_error($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Only accept POST requests
agent_debug_log(
    'Checking request method',
    ['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'] ?? ''],
    'H1'
);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Invalid request method: ' . $_SERVER['REQUEST_METHOD'];
    log_error($error);
    agent_debug_log(
        'Invalid request method encountered',
        ['method' => $_SERVER['REQUEST_METHOD']],
        'H1'
    );
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

// Get JSON data from the request
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

agent_debug_log(
    'Decoded JSON payload',
    [
        'raw_length' => strlen((string) $raw_input),
        'json_error' => json_last_error_msg(),
    ],
    'H2'
);

if (!$data) {
    $error = 'Invalid JSON data: ' . json_last_error_msg();
    log_error($error . ' Raw input: ' . $raw_input);
    agent_debug_log(
        'Invalid JSON data received',
        ['raw_sample' => substr((string) $raw_input, 0, 200)],
        'H2'
    );
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $error = 'User not logged in';
    log_error($error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$session_scope_seller_id = isset($_SESSION['storefront_seller_id']) ? (int)$_SESSION['storefront_seller_id'] : 0;
$request_scope_seller_id = isset($data['seller_id']) ? (int)$data['seller_id'] : 0;
if ($request_scope_seller_id > 0 && $session_scope_seller_id > 0 && $request_scope_seller_id !== $session_scope_seller_id) {
    $error = 'Seller scope mismatch detected. Please refresh and try again.';
    log_error($error . ' session=' . $session_scope_seller_id . ' request=' . $request_scope_seller_id);
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}
$expected_scope_seller_id = $request_scope_seller_id > 0 ? $request_scope_seller_id : $session_scope_seller_id;


// Validate required fields
$required = ['full_name', 'email', 'phone', 'street_address', 'province', 'city', 'barangay', 'payment_type', 'pickup_date', 'pickup_time'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        $error = "Missing required field: $field";
        log_error($error . ' Data: ' . json_encode($data));
        ob_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}

if (empty($data['items']) && empty($data['product_id'])) {
    $error = "No items selected";
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

log_error('Processing preorder for User ID: ' . $_SESSION['user_id']);

// Check database connection
if (!$conn) {
    $error = 'Database connection failed: ' . mysqli_connect_error();
    log_error($error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database connection error']);
    exit;
}

// Get PayMongo API keys from config or environment
$paymongo_secret = appConfigValue('PAYMONGO_SECRET_KEY');
$paymongo_public = appConfigValue('PAYMONGO_PUBLIC_KEY');

// Initialize PayMongo
try {
    $paymongo = new PayMongoIntegration($paymongo_secret, $paymongo_public);
} catch (Exception $e) {
    $error = 'PayMongo initialization error: ' . $e->getMessage();
    log_error($error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to initialize payment processor']);
    exit;
}

// Normalize items into an array
$items = [];
if (!empty($data['items']) && is_array($data['items'])) {
    $items = $data['items'];
} else {
    // Legacy fallback for single item
    $items[] = [
        'id' => $data['product_id'],
        'quantity' => $data['quantity']
    ];
}

$processed_items = [];
$subtotal = 0;
$vat_rate = 0.12;
$detected_seller_ids = [];

// Process items
foreach ($items as $item) {
    $product_id = intval($item['id']);
    $qty = intval($item['quantity']);
    
    if ($qty <= 0) continue;

    // Get product details
    if ($expected_scope_seller_id > 0) {
        $product_query = "SELECT id, name, price, seller_id
                          FROM products
                          WHERE id = ?
                            AND is_active = 1
                            AND (is_archived = 0 OR is_archived IS NULL)
                            AND seller_id = ?";
        $stmt = mysqli_prepare($conn, $product_query);
        if (!$stmt) {
            log_error('Failed to prepare scoped product query for preorder item.');
            continue;
        }
        mysqli_stmt_bind_param($stmt, "ii", $product_id, $expected_scope_seller_id);
    } else {
        $product_query = "SELECT id, name, price, seller_id
                          FROM products
                          WHERE id = ?
                            AND is_active = 1
                            AND (is_archived = 0 OR is_archived IS NULL)";
        $stmt = mysqli_prepare($conn, $product_query);
        if (!$stmt) {
            log_error('Failed to prepare product query for preorder item.');
            continue;
        }
        mysqli_stmt_bind_param($stmt, "i", $product_id);
    }
    mysqli_stmt_execute($stmt);
    $product_result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($product_result);
    mysqli_stmt_close($stmt);

    if (!$product) {
        log_error("Product ID $product_id not available for preorder scope, skipping.");
        continue;
    }
    $resolved_seller_id = (int)($product['seller_id'] ?? 0);
    if ($resolved_seller_id > 0) {
        $detected_seller_ids[$resolved_seller_id] = true;
    }

    $item_total = $product['price'] * $qty;
    $subtotal += $item_total;
    
    $processed_items[] = [
        'id' => $product['id'],
        'name' => $product['name'],
        'price' => $product['price'],
        'quantity' => $qty,
        'total' => $item_total
    ];
}

if ($expected_scope_seller_id <= 0 && count($detected_seller_ids) > 1) {
    $error = 'Please pre-order items from one business partner at a time.';
    log_error($error . ' sellers=' . json_encode(array_keys($detected_seller_ids)));
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

if (empty($processed_items)) {
    $error = 'No valid items to process.';
    log_error($error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

// Calculate VAT and amount based on payment type
$vat_amount = round($subtotal * $vat_rate, 2);
$grand_total = $subtotal + $vat_amount;
if ($data['payment_type'] === 'downpayment') {
    $amount = $grand_total * 0.30; // 30% downpayment
    $payment_type = 'downpayment';
} else {
    $amount = $grand_total; // Full payment
    $payment_type = 'full_payment';
}

// Validate amount
if ($amount <= 0) {
    $error = 'Invalid amount calculated: ' . $amount;
    log_error($error);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid payment amount']);
    exit;
}

log_error('Amount calculated - Type: ' . $payment_type . ', Amount: ₱' . $amount);
log_error('VAT breakdown - Subtotal: ' . $subtotal . ', VAT: ' . $vat_amount . ', Gross Total: ' . $grand_total);
agent_debug_log(
    'Amount calculated',
    ['payment_type' => $payment_type, 'subtotal' => $subtotal, 'vat_amount' => $vat_amount, 'gross_total' => $grand_total, 'amount' => $amount],
    'H3'
);

// Create preorder records
$user_id = $_SESSION['user_id'];
$region_name = trim((string)($data['region_name'] ?? ''));
$address_parts = [
    trim((string)($data['street_address'] ?? '')),
    trim((string)($data['barangay'] ?? '')),
    trim((string)($data['city'] ?? '')),
    trim((string)($data['province'] ?? '')),
    $region_name
];
$address = implode(', ', array_values(array_filter($address_parts, static function ($part) {
    return $part !== '';
})));
$pickup_date = $data['pickup_date'];
$pickup_time = $data['pickup_time'];
$latitude_value = '';
$longitude_value = '';
if (isset($data['latitude']) && is_numeric((string)$data['latitude'])) {
    $latitude_value = number_format((float)$data['latitude'], 8, '.', '');
}
if (isset($data['longitude']) && is_numeric((string)$data['longitude'])) {
    $longitude_value = number_format((float)$data['longitude'], 8, '.', '');
}

$first_preorder_id = 0;
$created_preorder_ids = [];
$transaction_ref = 'TXN-' . time() . '-' . rand(1000, 9999);

// Start transaction for DB integrity
mysqli_begin_transaction($conn);

try {
    foreach ($processed_items as $item) {
        $item_subtotal = $item['total'];
        $item_vat = round($item_subtotal * $vat_rate, 2);
        $item_total_with_vat = $item_subtotal + $item_vat;
        $item_downpayment = ($payment_type === 'downpayment') ? $item_total_with_vat * 0.30 : $item_total_with_vat;
        $item_remaining = $item_total_with_vat - $item_downpayment;
        
        // Note: Using special_instructions to store transaction ref if needed, or just rely on paymongo_session_id later
        $preorder_query = "INSERT INTO pre_orders (user_id, product_id, product_name, quantity, unit_price, total_price, delivery_address, delivery_method, payment_type, downpayment_amount, remaining_amount, preferred_pickup_date, preferred_pickup_time, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'delivery', ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NOW())";
        
        $preorder_stmt = mysqli_prepare($conn, $preorder_query);
        if (!$preorder_stmt) throw new Exception('DB Prepare Error');
        
        mysqli_stmt_bind_param($preorder_stmt, "iisiddssddssss", 
            $user_id, 
            $item['id'], 
            $item['name'],
            $item['quantity'], 
            $item['price'], 
            $item_total_with_vat, 
            $address,
            $payment_type, 
            $item_downpayment,
            $item_remaining,
            $pickup_date,
            $pickup_time,
            $latitude_value,
            $longitude_value
        );
        
        if (!mysqli_stmt_execute($preorder_stmt)) throw new Exception('DB Execute Error: ' . mysqli_stmt_error($preorder_stmt));
        
        $pid = mysqli_insert_id($conn);
        $created_preorder_ids[] = (int)$pid;
        if ($first_preorder_id === 0) $first_preorder_id = $pid;
        
        mysqli_stmt_close($preorder_stmt);
    }
    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

log_error('Preorder(s) created - First ID: ' . $first_preorder_id);

$item_count = count($processed_items);
if (function_exists('createNotification')) {
    $customer_msg = "Your pre-order transaction was submitted with {$item_count} item(s). Complete payment to finalize.";
    createNotification($conn, (int)$user_id, 'preorder_submitted', 'Pre-Order Submitted', $customer_msg, (int)$first_preorder_id, 'pre_order');

    if (function_exists('getAdminUserIds')) {
        $admin_ids = getAdminUserIds($conn);
        $admin_msg = "New pre-order transaction submitted by customer #{$user_id} ({$item_count} item(s)) awaiting payment verification.";
        foreach ($admin_ids as $admin_id) {
            createNotification($conn, (int)$admin_id, 'preorder_submitted', 'New Pre-Order Submitted', $admin_msg, (int)$first_preorder_id, 'pre_order');
        }
    }
}

// Use the first preorder ID as the reference for PayMongo, but the amount covers all items
$preorder_id = $first_preorder_id;
$desc_text = $item_count > 1 ? "Pre-Order: $item_count items" : "Pre-Order: " . $processed_items[0]['name'];

// Prepare PayMongo checkout data
$checkout_data = [
    'amount' => $amount,
    'description' => $desc_text,
    'order_id' => $preorder_id,
    'customer_name' => $data['full_name'],
    'customer_email' => $data['email'],
    'customer_phone' => $data['phone'],
    'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_success_preorder.php?preorder_id=' . $preorder_id,
    'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/payment_cancel_preorder.php?preorder_id=' . $preorder_id,
    'payment_method' => 'all' // Allow all payment methods
];

log_error('Checkout data prepared: ' . json_encode($checkout_data));

// Create PayMongo checkout session
agent_debug_log(
    'Creating PayMongo checkout session',
    ['preorder_id' => $preorder_id, 'amount' => $amount],
    'H4'
);
try {
    $checkout_result = $paymongo->createCheckoutSession($checkout_data);
    log_error('createCheckoutSession returned: ' . json_encode($checkout_result));
    agent_debug_log(
        'PayMongo checkout session created',
        ['result' => $checkout_result],
        'H4'
    );
} catch (Exception $e) {
    $error = 'PayMongo checkout creation error: ' . $e->getMessage();
    log_error($error);
    agent_debug_log(
        'Exception during PayMongo checkout creation',
        ['error' => $e->getMessage()],
        'H4'
    );
    
    // Delete all created preorders if payment session creation failed
    if (!empty($created_preorder_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($created_preorder_ids), '?'));
        $id_types = str_repeat('i', count($created_preorder_ids));
        $delete_query = "DELETE FROM pre_orders WHERE id IN ({$id_placeholders})";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        if ($delete_stmt) {
            $bind_params = $created_preorder_ids;
            $bind_refs = [];
            foreach ($bind_params as $k => $v) {
                $bind_refs[$k] = &$bind_params[$k];
            }
            array_unshift($bind_refs, $id_types);
            call_user_func_array([$delete_stmt, 'bind_param'], $bind_refs);
            mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);
        }
    }
    
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to create payment session']);
    exit;
}

if ($checkout_result['success']) {
    agent_debug_log(
        'Entered success block after PayMongo session creation',
        ['preorder_id' => $preorder_id],
        'H4'
    );

    // Update all created pre-orders with the same session ID (group link)
    if (!empty($created_preorder_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($created_preorder_ids), '?'));
        $id_types = str_repeat('i', count($created_preorder_ids));
        $update_query = "UPDATE pre_orders SET paymongo_session_id = ? WHERE id IN ({$id_placeholders})";
        $update_stmt = mysqli_prepare($conn, $update_query);

        if (!$update_stmt) {
            $error = 'Database prepare error on session update: ' . mysqli_error($conn);
            log_error($error);
        } else {
            $bind_types = 's' . $id_types;
            $bind_params = array_merge([$checkout_result['session_id']], $created_preorder_ids);
            $bind_refs = [];
            foreach ($bind_params as $k => $v) {
                $bind_refs[$k] = &$bind_params[$k];
            }
            array_unshift($bind_refs, $bind_types);
            call_user_func_array([$update_stmt, 'bind_param'], $bind_refs);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
    }

    agent_debug_log(
        'After preorder update attempt',
        ['preorder_id' => $preorder_id],
        'H4'
    );

    log_error('Payment session created - Checkout URL: ' . $checkout_result['checkout_url']);
    agent_debug_log(
        'Preorder updated with PayMongo session',
        ['preorder_id' => $preorder_id, 'session_id' => $checkout_result['session_id']],
        'H4'
    );

    // Ensure successful HTTP status code is returned with JSON
    http_response_code(200);
    agent_debug_log(
        'Sending success JSON response',
        ['status_code' => http_response_code(), 'preorder_id' => $preorder_id],
        'H4'
    );

    ob_clean();
    echo json_encode([
        'success' => true,
        'checkout_url' => $checkout_result['checkout_url'],
        'preorder_id' => $preorder_id,
        'preorder_ids' => $created_preorder_ids
    ]);
} else {
    // Delete all created preorders if payment session creation failed
    if (!empty($created_preorder_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($created_preorder_ids), '?'));
        $id_types = str_repeat('i', count($created_preorder_ids));
        $delete_query = "DELETE FROM pre_orders WHERE id IN ({$id_placeholders})";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        if ($delete_stmt) {
            $bind_params = $created_preorder_ids;
            $bind_refs = [];
            foreach ($bind_params as $k => $v) {
                $bind_refs[$k] = &$bind_params[$k];
            }
            array_unshift($bind_refs, $id_types);
            call_user_func_array([$delete_stmt, 'bind_param'], $bind_refs);
            mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);
        }
    }
    
    $error = 'PayMongo checkout creation failed: ' . ($checkout_result['error'] ?? 'Unknown error');
    log_error($error);
    agent_debug_log(
        'PayMongo checkout creation failed',
        ['preorder_id' => $preorder_id, 'error' => $checkout_result['error'] ?? 'Unknown error'],
        'H4'
    );
    
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => $checkout_result['error'] ?? 'Failed to create payment session'
    ]);
}
?>

