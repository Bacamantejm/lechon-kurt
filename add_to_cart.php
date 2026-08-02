<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users

session_start();

// Set content type to JSON
header('Content-Type: application/json');

function sendJsonResponse($success, $message = '', $data = []) {
    $response = ['success' => $success, 'message' => $message];
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Return cart count for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    sendJsonResponse(true, '', [
        'cart_count' => count($_SESSION['cart'])
    ]);
}

// Check if required data is provided for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['product_id']) || !isset($_POST['quantity'])) {
        sendJsonResponse(false, 'Missing required data');
    }
    
    $product_id = intval($_POST['product_id']); // Use numeric ID
    $quantity = intval($_POST['quantity']);
    $size = isset($_POST['size']) ? trim($_POST['size']) : 'Regular';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    
    // Handle addons
    $addons = [];
    if (isset($_POST['addons'])) {
        $addons_data = $_POST['addons'];
        if (is_string($addons_data)) {
            $addons = json_decode($addons_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $addons = [];
            }
        }
    }
    
    // Validate quantity request (final quantity still constrained by live stock below)
    if ($quantity < 1 || $quantity > 20) {
        sendJsonResponse(false, 'Invalid quantity (1-20 only)');
    }
    
    // Get product details from database
    try {
        require_once 'includes/config.php';
        require_once 'includes/partner_voucher_helper.php';
        
        if (!$conn) {
            throw new Exception('Database connection failed');
        }
        
        // Fetch product with live available stock from inventory (today) fallback to products.stock
        $query = "SELECT
                    p.id,
                    p.product_id,
                    p.name,
                    p.image,
                    p.price,
                    p.seller_id,
                    COALESCE(i.current_stock, p.stock, 0) AS available_stock
                  FROM products p
                  LEFT JOIN inventory i
                    ON p.id = i.product_id
                    AND i.inventory_date = CURDATE()
                    AND i.is_archived = 0
                  WHERE p.id = ?
                    AND p.is_active = 1
                    AND p.is_archived = 0
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Database execute failed: ' . mysqli_stmt_error($stmt));
        }
        
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result) {
            throw new Exception('Database result failed: ' . mysqli_error($conn));
        }
        
        if ($product = mysqli_fetch_assoc($result)) {
            $product_name = $product['name'];
            $product_image = $product['image'];
            $string_product_id = $product['product_id'];
            $product_seller_id = (int)($product['seller_id'] ?? 0);
            $available_stock = max(0, (int)$product['available_stock']);
            
            // If price is 0, get from database
            if ($price == 0) {
                $price = floatval($product['price']);
            }
            
            mysqli_stmt_close($stmt);
        } else {
            mysqli_stmt_close($stmt);
            mysqli_close($conn);
            sendJsonResponse(false, 'Product not found or inactive');
        }
    } catch (Exception $e) {
        // Clean up any open connections
        if (isset($stmt)) mysqli_stmt_close($stmt);
        if (isset($conn)) mysqli_close($conn);
        
        // Log error but don't show details to user
        error_log('Add to cart error: ' . $e->getMessage());
            sendJsonResponse(false, 'Unable to process request. Please try again.');
    }

    // Enforce single-tenant cart before modifying cart contents
    if (($product_seller_id ?? 0) <= 0) {
        if (isset($conn) && $conn instanceof mysqli) {
            mysqli_close($conn);
        }
        sendJsonResponse(false, 'This item is not linked to a valid store and cannot be added right now.');
    }

    $current_cart_seller_id = 0;
    if (!empty($_SESSION['cart']) && function_exists('pvGetCheckoutTenantScope')) {
        $cart_scope = pvGetCheckoutTenantScope($conn, $_SESSION['cart']);
        if (empty($cart_scope['is_valid'])) {
            if (isset($conn) && $conn instanceof mysqli) {
                mysqli_close($conn);
            }
            sendJsonResponse(
                false,
                (string)($cart_scope['message'] ?? 'Your cart has mixed store items. Please review your cart before adding new items.'),
                [
                    'code' => 'MIXED_TENANT_CART_EXISTING',
                    'redirect_url' => 'cart.php'
                ]
            );
        }
        $current_cart_seller_id = (int)($cart_scope['seller_id'] ?? 0);
    }

    if ($current_cart_seller_id > 0 && $product_seller_id !== $current_cart_seller_id) {
        if (isset($conn) && $conn instanceof mysqli) {
            mysqli_close($conn);
        }
        sendJsonResponse(
            false,
            'You can only add items from one store per checkout. Please finish or clear your current cart first.',
            [
                'code' => 'MIXED_TENANT_ADD_BLOCKED',
                'current_seller_id' => $current_cart_seller_id,
                'attempted_seller_id' => $product_seller_id,
                'redirect_url' => 'cart.php'
            ]
        );
    }

    $_SESSION['storefront_seller_id'] = $current_cart_seller_id > 0
        ? $current_cart_seller_id
        : $product_seller_id;

    if (isset($conn) && $conn instanceof mysqli) {
        mysqli_close($conn);
    }

    // Enforce stock-based maximum (not just hardcoded 20)
    $requested_quantity = $quantity;
    $max_allowed_by_stock = min(20, $available_stock);
    if ($max_allowed_by_stock <= 0) {
        sendJsonResponse(false, 'This item is currently out of stock.');
    }

    $response_message = 'Item added to cart';
    
    // Check if product already exists in cart with same size and addons
    $found_index = -1;
    foreach ($_SESSION['cart'] as $index => $item) {
        if ($item['id'] == $product_id && 
            $item['size'] == $size && 
            json_encode($item['addons']) == json_encode($addons)) {
            $found_index = $index;
            break;
        }
    }
    
    if ($found_index >= 0) {
        // Update existing item quantity
        $current_quantity = (int)$_SESSION['cart'][$found_index]['quantity'];
        $new_quantity = $current_quantity + $requested_quantity;

        if ($new_quantity > $max_allowed_by_stock) {
            $new_quantity = $max_allowed_by_stock;
        }

        if ($new_quantity <= $current_quantity) {
            sendJsonResponse(false, 'Only ' . $max_allowed_by_stock . ' unit(s) available for this item.');
        }

        $_SESSION['cart'][$found_index]['quantity'] = $new_quantity;

        if ($new_quantity < ($current_quantity + $requested_quantity)) {
            $response_message = 'Quantity adjusted to available stock (' . $max_allowed_by_stock . ').';
        } else {
            $response_message = 'Item quantity updated in cart';
        }
    } else {
        $final_quantity = min($requested_quantity, $max_allowed_by_stock);
        if ($final_quantity <= 0) {
            sendJsonResponse(false, 'This item is currently out of stock.');
        }

        // Add new item to cart
        $cart_item = [
            'id' => $product_id,
            'product_id' => $string_product_id,
            'name' => $product_name,
            'image' => $product_image,
            'price' => $price,
            'quantity' => $final_quantity,
            'size' => $size,
            'addons' => $addons,
            'added_at' => time()
        ];
        $_SESSION['cart'][] = $cart_item;

        if ($final_quantity < $requested_quantity) {
            $response_message = 'Only ' . $max_allowed_by_stock . ' unit(s) available. Quantity adjusted.';
        }
    }
    
    // Debug: Log cart contents
    error_log('Cart after addition: ' . json_encode($_SESSION['cart']));
    
    // Return success response with cart items
    $cart_items = [];
    $subtotal = 0;
    foreach ($_SESSION['cart'] as $index => $item) {
        $item_total = $item['price'] * $item['quantity'];
        $subtotal += $item_total;
        
        $cart_items[] = [
            'index' => $index,
            'product_id' => $item['product_id'],
            'name' => $item['name'],
            'image' => $item['image'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'size' => $item['size'],
            'addons' => $item['addons'],
            'item_total' => $item_total
        ];
    }
    
    // Return success response
    sendJsonResponse(true, $response_message, [
        'cart_count' => count($_SESSION['cart']),
        'subtotal' => $subtotal,
        'items' => $cart_items
    ]);
}
?>
