<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/partner_voucher_helper.php';
require_once 'includes/checkout_address_helper.php';
require_once 'includes/delivery_pricing_helper.php';

// Check if user is logged in BEFORE including header
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_to'] = 'checkout.php';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    header('Location: menu.php');
    exit;
}

// Get user information
$user_id = (int)($_SESSION['user_id'] ?? 0);
$storefront_seller_id = isset($_SESSION['storefront_seller_id']) ? (int)$_SESSION['storefront_seller_id'] : 0;
// Build compact cart prefill param: "id:qty,id:qty,..."
$_co_prefill_parts = [];
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $_co_item) {
        $pid = (int)($_co_item['id'] ?? 0);
        $qty = max(1, (int)($_co_item['quantity'] ?? 1));
        if ($pid > 0) {
            $_co_prefill_parts[] = $pid . ':' . $qty;
        }
    }
}
$_co_prefill_param = count($_co_prefill_parts) > 0 ? '&prefill=' . urlencode(implode(',', $_co_prefill_parts)) : '';
$preorder_switch_link = 'preorder.php' . ($storefront_seller_id > 0 ? '?seller_id=' . $storefront_seller_id . $_co_prefill_param : (count($_co_prefill_parts) > 0 ? '?prefill=' . urlencode(implode(',', $_co_prefill_parts)) : ''));
pvEnsureVoucherSchema($conn);
caEnsureUserSavedAddressSchema($conn);

$query = "SELECT full_name, email, phone, address FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? (mysqli_fetch_assoc($result) ?: []) : [];
mysqli_stmt_close($stmt);

if (empty($user['full_name']) && isset($_SESSION['full_name'])) {
    $user['full_name'] = trim((string)$_SESSION['full_name']);
}
if (empty($user['email']) && isset($_SESSION['email'])) {
    $user['email'] = trim((string)$_SESSION['email']);
}
if (empty($user['phone']) && isset($_SESSION['phone'])) {
    $user['phone'] = trim((string)$_SESSION['phone']);
}
if (empty($user['address']) && isset($_SESSION['address'])) {
    $user['address'] = trim((string)$_SESSION['address']);
}

if (empty($user['full_name']) || empty($user['email']) || empty($user['phone']) || empty($user['address'])) {
    $order_profile_stmt = mysqli_prepare(
        $conn,
        "SELECT customer_name, customer_email, customer_phone, delivery_address
         FROM orders
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    if ($order_profile_stmt) {
        mysqli_stmt_bind_param($order_profile_stmt, "i", $user_id);
        mysqli_stmt_execute($order_profile_stmt);
        $order_profile_result = mysqli_stmt_get_result($order_profile_stmt);
        $recent_order_profile = $order_profile_result ? mysqli_fetch_assoc($order_profile_result) : null;
        mysqli_stmt_close($order_profile_stmt);

        if (is_array($recent_order_profile)) {
            if (empty($user['full_name']) && !empty($recent_order_profile['customer_name'])) {
                $user['full_name'] = $recent_order_profile['customer_name'];
            }
            if (empty($user['email']) && !empty($recent_order_profile['customer_email'])) {
                $user['email'] = $recent_order_profile['customer_email'];
            }
            if (empty($user['phone']) && !empty($recent_order_profile['customer_phone'])) {
                $user['phone'] = $recent_order_profile['customer_phone'];
            }
            if (empty($user['address']) && !empty($recent_order_profile['delivery_address'])) {
                $user['address'] = $recent_order_profile['delivery_address'];
            }
        }
    }
}

caEnsureDefaultUserProfileAddress(
    $conn,
    $user_id,
    (string)($user['address'] ?? ''),
    (string)($user['full_name'] ?? ''),
    (string)($user['phone'] ?? '')
);

if (!function_exists('checkoutSavedAddressRowsForClient')) {
    function checkoutSavedAddressRowsForClient(array $rows) {
        $output = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $output[] = [
                'id' => (int)$row['id'],
                'label' => (string)($row['label'] ?? ''),
                'contact_name' => (string)($row['contact_name'] ?? ''),
                'contact_phone' => (string)($row['contact_phone'] ?? ''),
                'street_address' => (string)($row['street_address'] ?? ''),
                'region_name' => (string)($row['region_name'] ?? ''),
                'region_code' => (string)($row['region_code'] ?? ''),
                'province_name' => (string)($row['province_name'] ?? ''),
                'province_code' => (string)($row['province_code'] ?? ''),
                'city_name' => (string)($row['city_name'] ?? ''),
                'city_code' => (string)($row['city_code'] ?? ''),
                'barangay_name' => (string)($row['barangay_name'] ?? ''),
                'barangay_code' => (string)($row['barangay_code'] ?? ''),
                'full_address' => (string)($row['full_address'] ?? ''),
                'latitude' => (string)($row['latitude'] ?? ''),
                'longitude' => (string)($row['longitude'] ?? ''),
                'is_default' => (int)($row['is_default'] ?? 0)
            ];
        }
        return $output;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_action'])) {
    header('Content-Type: application/json');

    $address_action = strtolower(trim((string)($_POST['address_action'] ?? '')));
    if ($address_action === 'save') {
        $address_payload = [
            'label' => $_POST['label'] ?? 'Saved Address',
            'contact_name' => $_POST['contact_name'] ?? ($user['full_name'] ?? ''),
            'contact_phone' => $_POST['contact_phone'] ?? ($user['phone'] ?? ''),
            'street_address' => $_POST['street_address'] ?? '',
            'region_name' => $_POST['region_name'] ?? '',
            'region_code' => $_POST['region_code'] ?? '',
            'province_name' => $_POST['province_name'] ?? '',
            'province_code' => $_POST['province_code'] ?? '',
            'city_name' => $_POST['city_name'] ?? '',
            'city_code' => $_POST['city_code'] ?? '',
            'barangay_name' => $_POST['barangay_name'] ?? '',
            'barangay_code' => $_POST['barangay_code'] ?? '',
            'full_address' => $_POST['full_address'] ?? '',
            'latitude' => $_POST['latitude'] ?? '',
            'longitude' => $_POST['longitude'] ?? '',
            'is_default' => !empty($_POST['is_default']) ? 1 : 0
        ];

        $save_result = caSaveUserSavedAddress($conn, $user_id, $address_payload, !empty($_POST['is_default']));
        if (!($save_result['success'] ?? false)) {
            echo json_encode([
                'success' => false,
                'message' => (string)($save_result['message'] ?? 'Unable to save address.')
            ]);
            exit;
        }

        $fresh_addresses = caFetchUserSavedAddresses($conn, $user_id);
        echo json_encode([
            'success' => true,
            'message' => (string)($save_result['message'] ?? 'Address saved.'),
            'saved_address_id' => (int)($save_result['address_id'] ?? 0),
            'addresses' => checkoutSavedAddressRowsForClient($fresh_addresses)
        ]);
        exit;
    }

    if ($address_action === 'delete') {
        $address_id = (int)($_POST['address_id'] ?? 0);
        $delete_result = caDeleteUserSavedAddress($conn, $user_id, $address_id);
        $fresh_addresses = caFetchUserSavedAddresses($conn, $user_id);
        echo json_encode([
            'success' => !empty($delete_result['success']),
            'message' => (string)($delete_result['message'] ?? 'Address removed.'),
            'addresses' => checkoutSavedAddressRowsForClient($fresh_addresses)
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Invalid address action.'
    ]);
    exit;
}

$saved_addresses = caFetchUserSavedAddresses($conn, $user_id);
$default_saved_address_id = 0;
foreach ($saved_addresses as $saved_address_row) {
    if ((int)($saved_address_row['is_default'] ?? 0) === 1) {
        $default_saved_address_id = (int)$saved_address_row['id'];
        break;
    }
}

// Include header after all redirects and JSON exits are done
$current_page = 'checkout';
$page_title = "Checkout | Lechon Delights";
include 'includes/header.php';
?>
<!-- Leaflet Map Assets -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
if (!window.L) {
    document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />');
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"><\/script>');
}
</script>
<?php

// Get store locations and delivery quote context from session
$stores = $_SESSION['store_locations'] ?? [];
if (empty($stores) && isset($conn) && $conn instanceof mysqli) {
    $store_query = "SELECT store_id AS id, store_id, owner_user_id, store_name AS name, store_name, address, city, province, phone, opening_hours AS hours, opening_hours, latitude, longitude FROM store_locations WHERE is_active = 1 ORDER BY store_name ASC";
    $store_res = mysqli_query($conn, $store_query);
    if ($store_res) {
        $stores = mysqli_fetch_all($store_res, MYSQLI_ASSOC);
        $_SESSION['store_locations'] = $stores;
    }
}

// Auto-align seller store ID for pickup pre-selection if storefront_seller_id is set
if (!empty($_SESSION['storefront_seller_id']) && !empty($stores)) {
    $preferred_seller_id = (int)$_SESSION['storefront_seller_id'];
    foreach ($stores as $s) {
        if ((int)($s['owner_user_id'] ?? 0) === $preferred_seller_id || (int)($s['id'] ?? 0) === $preferred_seller_id) {
            if (!isset($_SESSION['pickup_location']) || $_SESSION['pickup_location'] <= 0) {
                $_SESSION['pickup_location'] = (int)($s['id'] ?? $s['store_id'] ?? 1);
            }
            break;
        }
    }
}

$target_saved = null;
if (!empty($saved_addresses)) {
    foreach ($saved_addresses as $sa) {
        if ($default_saved_address_id > 0 && (int)($sa['id'] ?? 0) === $default_saved_address_id) {
            $target_saved = $sa;
            break;
        }
    }
    if (!$target_saved && !empty($saved_addresses[0])) {
        $target_saved = $saved_addresses[0];
    }
}

$current_delivery_quote = is_array($_SESSION['current_delivery_quote'] ?? null) ? $_SESSION['current_delivery_quote'] : [];
$deliveryPricingConfig = dpGetDeliveryPricingConfig();
$seller_owner_scope = (int)($_SESSION['storefront_seller_id'] ?? 0);

$target_saved_id = (int)($target_saved['id'] ?? $default_saved_address_id ?? 0);
$target_full_address = (string)($target_saved['full_address'] ?? ($user['address'] ?? ''));
$target_street_address = (string)($target_saved['street_address'] ?? '');
if ($target_street_address === '' && $target_full_address !== '') {
    $addr_parts = explode(',', $target_full_address);
    $target_street_address = trim($addr_parts[0]);
}
$target_region_name = (string)($target_saved['region_name'] ?? '');
$target_region_code = (string)($target_saved['region_code'] ?? '');
$target_province_name = (string)($target_saved['province_name'] ?? '');
$target_province_code = (string)($target_saved['province_code'] ?? '');
$target_city_name = (string)($target_saved['city_name'] ?? '');
$target_city_code = (string)($target_saved['city_code'] ?? '');
$target_barangay_name = (string)($target_saved['barangay_name'] ?? '');
$target_barangay_code = (string)($target_saved['barangay_code'] ?? '');
$target_postal_code = (string)($target_saved['postal_code'] ?? '');

$saCoords = dpSanitizeCoordinates($target_saved['latitude'] ?? null, $target_saved['longitude'] ?? null);
if ($saCoords === null && $target_full_address !== '') {
    $saCoords = dpResolveCoordinatesFromAddress($target_full_address);
}

$lat_val = $saCoords ? (float)($saCoords['lat'] ?? $saCoords['latitude'] ?? 0) : null;
$lng_val = $saCoords ? (float)($saCoords['lng'] ?? $saCoords['longitude'] ?? 0) : null;

// Calculate initial delivery quote if missing or not successful
if (empty($current_delivery_quote) || empty($current_delivery_quote['success'])) {
    if ($lat_val !== null || $target_full_address !== '') {
        $initial_q = dpBuildDeliveryQuote($stores, $lat_val, $lng_val, $seller_owner_scope, $deliveryPricingConfig, $target_full_address);
        if (!empty($initial_q['success'])) {
            $current_delivery_quote = $initial_q;
            $_SESSION['current_delivery_quote'] = $initial_q;
        }
    }
}

$target_lat_val = ($lat_val !== null) ? number_format($lat_val, 8, '.', '') : (string)($current_delivery_quote['customer_lat'] ?? '');
$target_lng_val = ($lng_val !== null) ? number_format($lng_val, 8, '.', '') : (string)($current_delivery_quote['customer_lng'] ?? '');
$target_distance_val = !empty($current_delivery_quote['distance_km']) ? (string)$current_delivery_quote['distance_km'] : '';
$target_fee_val = isset($current_delivery_quote['fee']) ? number_format((float)$current_delivery_quote['fee'], 2, '.', '') : '';

// Display labels for Step 2 address card
$target_street_display = $target_street_address ?: ($target_full_address ?: 'No address selected');
$city_display_parts = array_filter([$target_barangay_name, $target_city_name, $target_province_name]);
if (!empty($city_display_parts)) {
    $target_city_display = implode(', ', $city_display_parts);
} elseif ($target_full_address !== '') {
    $addr_parts = explode(',', $target_full_address);
    array_shift($addr_parts);
    $target_city_display = trim(implode(',', $addr_parts));
} else {
    $target_city_display = '';
}

// Fulfillment Mode: Default to 'delivery' so customer immediately sees the delivery fee and breakdown
$has_explicit_delivery_choice = !empty($_SESSION['delivery_option_explicit']);
if (!$has_explicit_delivery_choice || empty($_SESSION['delivery_option'])) {
    $_SESSION['delivery_option'] = 'delivery';
    if (!isset($_SESSION['pickup_location']) && !empty($stores)) {
        $_SESSION['pickup_location'] = (int)($stores[0]['id'] ?? 1);
    }
}
$current_checkout_delivery_option = in_array((string)($_SESSION['delivery_option'] ?? ''), ['pickup', 'delivery'], true)
    ? (string)$_SESSION['delivery_option']
    : 'delivery';
$_SESSION['delivery_option'] = $current_checkout_delivery_option;

// Calculate order totals
$subtotal = 0;
$checkout_item_count = 0;
$checkout_total_quantity = 0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $checkout_item_count++;
    $checkout_total_quantity += (int)($item['quantity'] ?? 0);
}

// Shared distance-based pricing settings
$base_delivery_fee = (float)($deliveryPricingConfig['base_fee'] ?? 50);
$per_km_rate = (float)($deliveryPricingConfig['per_km_rate'] ?? 15);
$preparation_time_minutes = (int)($deliveryPricingConfig['preparation_time_minutes'] ?? 20);
$average_speed_kmh = (float)($deliveryPricingConfig['average_speed_kmh'] ?? 25);

// Get delivery fee
$delivery_fee = 0;
$delivery_details = '';
$estimated_delivery_text = '';
$selected_store_address = '';
$selected_store = null;

if ($current_checkout_delivery_option === 'pickup') {
    $pickup_location = $_SESSION['pickup_location'] ?? 1;
    foreach ($stores as $store) {
        if ($store['id'] == $pickup_location) {
            $selected_store = $store;
            break;
        }
    }
    $delivery_details = "Pickup from: " . ($selected_store['name'] ?? ($selected_store['store_name'] ?? 'Main Store'));
    $selected_store_address = $selected_store['address'] ?? '';
} else {
    if (!empty($current_delivery_quote['success'])) {
        $delivery_fee = (float)($current_delivery_quote['fee'] ?? 0);
        $delivery_details = (string)($current_delivery_quote['delivery_details'] ?? 'Delivery fee calculated from the nearest store.');
        $estimated_delivery_text = (string)($current_delivery_quote['estimated_delivery_text'] ?? '');
        $selected_store_address = (string)($current_delivery_quote['nearest_store_address'] ?? '');
    } else {
        $delivery_details = 'Pin your exact location to calculate the delivery fee from the nearest store.';
    }
}

// Fulfilling store context for Delivery Route Map Overview
$overview_store = null;
$overview_store_id = (int)($current_delivery_quote['nearest_store_id'] ?? 0);
if ($overview_store_id > 0 && !empty($stores)) {
    foreach ($stores as $st) {
        if ((int)($st['id'] ?? $st['store_id'] ?? 0) === $overview_store_id) {
            $overview_store = $st;
            break;
        }
    }
}
if (!$overview_store && !empty($stores)) {
    $overview_store = $stores[0];
}

$overview_store_id = (int)($overview_store['id'] ?? $overview_store['store_id'] ?? 1);
$overview_store_name = (string)($current_delivery_quote['nearest_store_name'] ?? ($overview_store['name'] ?? ($overview_store['store_name'] ?? 'Nearest Store')));
$overview_store_address = (string)($current_delivery_quote['nearest_store_address'] ?? ($overview_store['address'] ?? ''));
$overview_store_lat = !empty($overview_store['latitude']) ? (float)$overview_store['latitude'] : 0.0;
$overview_store_lng = !empty($overview_store['longitude']) ? (float)$overview_store['longitude'] : 0.0;
$overview_distance_km = !empty($current_delivery_quote['distance_km']) ? (float)$current_delivery_quote['distance_km'] : 0.0;
$overview_fee = !empty($current_delivery_quote['fee']) ? (float)$current_delivery_quote['fee'] : (float)$delivery_fee;
$overview_eta = (string)($current_delivery_quote['estimated_delivery_text'] ?? $estimated_delivery_text);
if ($overview_eta === '' && $overview_distance_km > 0) {
    $overview_eta = '35 - 50 mins';
}

$vat_rate = 0.12;
$vat_amount = round($subtotal * $vat_rate, 2);

if (!empty($_GET['voucher']) && $user_id > 0 && !empty($_SESSION['cart'])) {
    pvApplyVoucherCodeForSession($conn, (int)$user_id, (string)$_GET['voucher'], $_SESSION['cart']);
}

if (empty($_SESSION['applied_voucher']) && !empty($_SESSION['pending_welcome_voucher']) && $user_id > 0 && !empty($_SESSION['cart'])) {
    $auto_pre = pvApplyVoucherCodeForSession($conn, (int)$user_id, (string)$_SESSION['pending_welcome_voucher'], $_SESSION['cart']);
    if (!empty($auto_pre['success'])) {
        unset($_SESSION['pending_welcome_voucher']);
    }
}

$applied_voucher_state = pvResolveAppliedVoucherState($conn, (int)$user_id, $_SESSION['cart']);
$voucher_discount = (float)($applied_voucher_state['discount_amount'] ?? 0);
$applied_voucher_code = (string)($applied_voucher_state['voucher_code'] ?? '');
$prefill_voucher_code = $applied_voucher_code !== '' ? $applied_voucher_code : (!empty($_SESSION['pending_welcome_voucher']) ? (string)$_SESSION['pending_welcome_voucher'] : '');
$applied_voucher_id = (int)($applied_voucher_state['voucher_id'] ?? 0);
$voucher_message = (string)($applied_voucher_state['message'] ?? '');
$voucher_scope_label = (string)($applied_voucher_state['scope_label'] ?? '');
$voucher_store_name = (string)($applied_voucher_state['store_name'] ?? '');
$voucher_is_exclusive = !empty($applied_voucher_state['is_store_exclusive']);
$available_vouchers = function_exists('pvFetchAvailableVouchersForCart') ? pvFetchAvailableVouchersForCart($conn, (int)$user_id, $_SESSION['cart']) : [];
$checkout_tenant_scope = function_exists('pvGetCheckoutTenantScope')
    ? pvGetCheckoutTenantScope($conn, $_SESSION['cart'])
    : ['is_valid' => true, 'seller_id' => 0, 'message' => ''];
$checkout_tenant_blocked = empty($checkout_tenant_scope['is_valid']);
$checkout_tenant_message = $checkout_tenant_blocked
    ? (string)($checkout_tenant_scope['message'] ?? 'Your cart has items from multiple stores. Please checkout one store at a time.')
    : '';
if (!$checkout_tenant_blocked && (int)($checkout_tenant_scope['seller_id'] ?? 0) > 0) {
    $storefront_seller_id = (int)$checkout_tenant_scope['seller_id'];
    $_SESSION['storefront_seller_id'] = $storefront_seller_id;
    $preorder_switch_link = 'preorder.php?seller_id=' . $storefront_seller_id . $_co_prefill_param;
}
$total = max(0, $subtotal + $vat_amount + $delivery_fee - $voucher_discount);

// Calculate downpayment (30%)
$downpayment = $total * 0.30;
$remaining = $total - $downpayment;
?>

<section class="checkout-section">
    <div class="container">
        <div class="checkout-grid">
            <!-- Order Summary -->
            <div class="checkout-summary">
                <h3>Order Summary</h3>
                <div class="checkout-summary-kpis">
                    <div class="checkout-kpi">
                        <span class="checkout-kpi-label">Items</span>
                        <strong><?php echo (int)$checkout_item_count; ?></strong>
                    </div>
                    <div class="checkout-kpi">
                        <span class="checkout-kpi-label">Quantity</span>
                        <strong><?php echo (int)$checkout_total_quantity; ?></strong>
                    </div>
                    <div class="checkout-kpi">
                        <span class="checkout-kpi-label">Mode</span>
                        <strong id="summaryModeValue"><?php echo $current_checkout_delivery_option === 'delivery' ? 'Delivery' : 'Pickup'; ?></strong>
                    </div>
                </div>
                <div class="summary-items">
                    <?php foreach ($_SESSION['cart'] as $item): ?>
                    <div class="summary-item">
                        <div class="item-info">
                            <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                            <?php if (isset($item['size']) && $item['size'] != 'Regular'): ?>
                            <p>Size: <?php echo htmlspecialchars($item['size']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['addons'])): ?>
                            <p>Add-ons: <?php echo htmlspecialchars(implode(', ', $item['addons'])); ?></p>
                            <?php endif; ?>
                            <p>Quantity: <?php echo $item['quantity']; ?></p>
                        </div>
                        <div class="item-price">
                            ₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="summary-totals">
                    <div class="total-row">
                        <span>Subtotal</span>
                        <span id="summarySubtotal">₱<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span>Delivery Fee</span>
                        <span id="summaryDeliveryFee">₱<?php echo number_format($delivery_fee, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span>VAT (12%)</span>
                        <span id="summaryVat">₱<?php echo number_format($vat_amount, 2); ?></span>
                    </div>
                    <div class="total-row voucher-row" id="voucherSummaryRow" style="<?php echo $voucher_discount > 0 ? '' : 'display:none;'; ?>">
                        <span>Voucher <small id="summaryVoucherCode"><?php echo $applied_voucher_code !== '' ? '(' . htmlspecialchars($applied_voucher_code) . ')' : ''; ?></small></span>
                        <span id="summaryVoucherDiscount">- ₱<?php echo number_format($voucher_discount, 2); ?></span>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total Amount</span>
                        <span id="summaryTotal">₱<?php echo number_format($total, 2); ?></span>
                    </div>
                    
                    <!-- Payment Breakdown -->
                    <div class="payment-breakdown" style="background: #fffdfb; border: 1px solid #efddcd; border-radius: 14px; padding: 16px; margin-top: 18px;">
                        <h4 style="font-family: 'Outfit', sans-serif; font-size: 0.95rem; font-weight: 800; color: #171922; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                            <span><i class="fas fa-wallet" style="color: #ef6b2e; margin-right: 6px;"></i> Payment Schedule</span>
                            <span style="font-size: 0.72rem; background: #fff8ef; color: #ef6b2e; border: 1px solid #efddcd; padding: 2px 8px; border-radius: 999px; font-weight: 700;">30% Deposit</span>
                        </h4>
                        <div class="breakdown-row d-flex justify-content-between align-items-center mb-2" style="font-size: 0.88rem;">
                            <span style="color: #667085; font-weight: 600;">Downpayment (30%):</span>
                            <strong class="downpayment-amount" style="color: #ef6b2e; font-size: 1rem; font-weight: 800;">₱<?php echo number_format($downpayment, 2); ?></strong>
                        </div>
                        <div class="breakdown-row d-flex justify-content-between align-items-center mb-2" style="font-size: 0.88rem;">
                            <span style="color: #667085; font-weight: 600;">Remaining Balance (70%):</span>
                            <span class="remaining-amount" style="color: #2a211d; font-weight: 700;">₱<?php echo number_format($remaining, 2); ?></span>
                        </div>
                        <div class="breakdown-row d-flex justify-content-between align-items-center pt-2" style="font-size: 0.88rem; border-top: 1px dashed #efddcd;">
                            <span style="color: #171922; font-weight: 700;">Full Payment (100%):</span>
                            <span class="full-amount" style="color: #b3261e; font-weight: 800;">₱<?php echo number_format($total, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="delivery-info" style="background: #ffffff; border: 1px solid #efddcd; border-radius: 14px; padding: 18px; margin-top: 18px; box-shadow: 0 4px 14px rgba(42, 33, 29, 0.04);">
                    <h4 style="font-family: 'Outfit', sans-serif; font-size: 0.98rem; font-weight: 800; color: #171922; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-truck-fast" style="color: #b3261e;"></i> Delivery Information
                    </h4>
                    <p id="summaryDeliveryDetails" style="margin-bottom: 6px; font-weight: 700; color: #171922; font-size: 0.9rem;">
                        <i class="fas fa-store" style="color: #ef6b2e; margin-right: 6px;"></i><?php echo htmlspecialchars($delivery_details); ?>
                    </p>
                    <p id="summaryDeliveryTime" style="font-weight: 700; color: #15803d; margin-bottom: 6px; font-size: 0.88rem; display: <?php echo !empty($estimated_delivery_text) ? 'inline-flex' : 'none'; ?>; align-items: center; gap: 6px; background: #f0fdf4; padding: 4px 10px; border-radius: 999px; border: 1px solid #bbf7d0;">
                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars($estimated_delivery_text); ?>
                    </p>
                    <p id="summaryStoreAddress" style="margin: 6px 0 0 0; font-size: 0.84rem; color: #667085; display: <?php echo !empty($selected_store_address) ? 'block' : 'none'; ?>;">
                        <i class="fas fa-location-dot" style="color: #b3261e; margin-right: 4px;"></i><?php echo htmlspecialchars($selected_store_address); ?>
                    </p>
                </div>
            </div>
            
            <!-- Checkout Form -->
            <div class="checkout-form">
                <h3>Complete Your Order</h3>
                <div class="checkout-flow">
                    <div class="checkout-flow-step is-active" data-step="1" style="cursor: pointer;"><span>1</span>Contact</div>
                    <div class="checkout-flow-step" data-step="2" style="cursor: pointer;"><span>2</span>Address</div>
                    <div class="checkout-flow-step" data-step="3" style="cursor: pointer;"><span>3</span>Payment</div>
                </div>
                <div class="progress-container" style="width: 100%; height: 6px; background: #efddcd; border-radius: 3px; margin-top: 15px; margin-bottom: 24px; overflow: visible; position: relative;">
                    <div class="progress-bar" id="checkoutProgressBar" style="position: absolute; left: 0; top: 0; height: 100%; width: 33.33%; background: #b3261e; transition: width 0.3s ease; display: block !important; overflow: visible;">
                        <div class="running-pig" style="position: absolute; right: -14px; top: -20px; font-size: 22px; user-select: none; line-height: 1; animation: pigRun 0.4s infinite alternate ease-in-out;">🐖</div>
                    </div>
                </div>

                <div class="checkout-type-switch" aria-label="Checkout Type">
                    <p class="checkout-type-title">Checkout Type</p>
                    <div class="checkout-type-actions">
                        <a href="checkout.php" class="checkout-type-chip is-active" aria-current="page">
                            <i class="fas fa-bolt"></i> Order Now
                        </a>
                        <a href="<?php echo htmlspecialchars($preorder_switch_link); ?>" class="checkout-type-chip">
                            <i class="fas fa-calendar-alt"></i> Pre-Order
                        </a>
                    </div>
                    <p class="checkout-type-note">Need a scheduled date and time instead? Switch to pre-order anytime.</p>
                </div>
                
                <!-- Delivery Option Form -->
                <div class="delivery-option-form" id="deliveryOptionForm">
                    <div class="checkout-mode-card <?php echo $current_checkout_delivery_option === 'delivery' ? 'mode-delivery' : 'mode-pickup'; ?>" id="checkoutModeCard">
                        <div class="checkout-mode-copy">
                            <h4>Fulfillment Mode</h4>
                            <p>
                                <strong id="activeModeLabel"><?php echo $current_checkout_delivery_option === 'delivery' ? 'Home Delivery' : 'Pickup from Store'; ?></strong>
                                is active. Tap a button to switch instantly.
                            </p>
                        </div>
                        <div class="checkout-mode-toggle" role="group" aria-label="Fulfillment mode">
                            <button type="button"
                                    id="modePickupBtn"
                                    class="checkout-mode-btn <?php echo $current_checkout_delivery_option === 'pickup' ? 'is-active' : ''; ?>"
                                    data-mode="pickup"
                                    onclick="switchCheckoutFulfillmentMode('pickup')">
                                <i class="fas fa-store"></i> Pickup
                            </button>
                            <button type="button"
                                    id="modeDeliveryBtn"
                                    class="checkout-mode-btn <?php echo $current_checkout_delivery_option === 'delivery' ? 'is-active' : ''; ?>"
                                    data-mode="delivery"
                                    onclick="switchCheckoutFulfillmentMode('delivery')">
                                <i class="fas fa-truck"></i> Delivery
                            </button>
                        </div>
                    </div>
                        <!-- Pickup Location -->
                        <div class="form-group pickup-location" id="pickupLocation" 
                             style="<?php echo ($current_checkout_delivery_option === 'pickup') ? '' : 'display: none;'; ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label for="pickup_location" style="margin-bottom:0;">Select Store for Pickup</label>
                                <button type="button" id="findNearestStoreBtn" class="btn-secondary" style="padding: 6px 12px; font-size: 0.85rem; background:#fff; color:#c62828; border:1px solid #c62828;">
                                    <i class="fas fa-location-arrow"></i> Find Nearest
                                </button>
                            </div>
                            <select id="pickup_location" name="pickup_location" class="store-select">
                                <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" 
                                        <?php echo ($store['id'] == ($_SESSION['pickup_location'] ?? 1)) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name'] ?? ($store['store_name'] ?? 'Store')); ?> - 
                                    <?php echo htmlspecialchars($store['city']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="store-info" id="storeInfo">
                                <?php if ($selected_store): ?>
                                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_store['address']); ?></p>
                                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($selected_store['phone']); ?></p>
                                <p><i class="fas fa-clock"></i> <?php echo htmlspecialchars($selected_store['hours'] ?? ($selected_store['opening_hours'] ?? '')); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Delivery Location -->
                        <div class="form-group delivery-location" id="deliveryLocation"
                             style="<?php echo ($current_checkout_delivery_option === 'delivery') ? '' : 'display: none;'; ?>">
                            <label>Delivery Fee Calculation</label>
                            <div class="delivery-fee-info-box">
                                <p><i class="fas fa-info-circle"></i> The delivery fee is calculated automatically based on the distance from the nearest store to your pinned location.</p>
                                <p><strong>Base Fee:</strong> PHP <?php echo number_format($base_delivery_fee, 2); ?> + 
                                   <strong>Rate:</strong> PHP <?php echo number_format($per_km_rate, 2); ?> / km
                                </p>
                            </div>
                        </div>
                </div>
                
                <!-- Main Checkout Form -->
                <?php if ($checkout_tenant_blocked): ?>
                    <div class="checkout-tenant-guard" role="alert">
                        <p><i class="fas fa-store-slash"></i> <?php echo htmlspecialchars($checkout_tenant_message); ?></p>
                        <a href="cart.php" class="btn-secondary">
                            <i class="fas fa-shopping-cart"></i> Review Cart
                        </a>
                    </div>
                <?php endif; ?>
                <form id="checkoutForm" action="process_order.php" method="POST" novalidate>
                    <!-- Step 1: Contact Details -->
                    <div class="step-content is-active" id="stepContent1">
                        <p class="checkout-section-label"><i class="fas fa-user"></i> Contact Details</p>
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                   required pattern="[0-9]{11}" 
                                   title="11-digit Philippine phone number">
                        </div>

                        <div class="account-autofill-note">
                            <i class="fas fa-user-check"></i>
                            Account details are auto-filled from your profile. You can still edit before placing the order.
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; margin-top: 24px;">
                            <button type="button" class="btn-primary" id="btnNextToAddress" style="background: #b3261e; border: none; padding: 12px 24px; font-weight: 700; border-radius: 8px; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                Continue to Delivery <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Delivery Details & Pickup Store Details -->
                    <div class="step-content" id="stepContent2">
                        <!-- Pickup Store Section (Form for Pickup Mode) -->
                        <div id="pickupStoreSection" style="<?php echo ($current_checkout_delivery_option === 'pickup') ? '' : 'display: none;'; ?>">
                            <div class="co-address-card" style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
                                <h3 style="font-size: 22px; font-weight: 800; color: #2a211d; margin: 0 0 16px 0; font-family: inherit;">Pickup store location</h3>
                                <label for="pickup_location_step" style="font-weight: 700; color: #2a211d; display: block; margin-bottom: 8px;">Select Store for Pickup *</label>
                                <select id="pickup_location_step" class="store-select" style="width: 100%; padding: 14px 16px; border: 1px solid #efddcd; border-radius: 12px; font-size: 15px; color: #2a211d; background: #fff9f2; outline: none; box-sizing: border-box; margin-bottom: 16px; font-weight: 600;">
                                    <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" <?php echo ($store['id'] == ($_SESSION['pickup_location'] ?? 1)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name'] ?? ($store['store_name'] ?? 'Store')); ?> - <?php echo htmlspecialchars($store['city']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="store-info" id="storeInfoStep">
                                    <?php if ($selected_store): ?>
                                    <p style="margin: 6px 0; color: #2a211d; font-size: 14px; font-weight: 600;"><i class="fas fa-map-marker-alt" style="color: #b3261e; margin-right: 8px;"></i> <?php echo htmlspecialchars($selected_store['address']); ?></p>
                                    <p style="margin: 6px 0; color: #667085; font-size: 14px;"><i class="fas fa-phone" style="color: #ef6b2e; margin-right: 8px;"></i> <?php echo htmlspecialchars($selected_store['phone']); ?></p>
                                    <p style="margin: 6px 0; color: #667085; font-size: 14px;"><i class="fas fa-clock" style="color: #667085; margin-right: 8px;"></i> <?php echo htmlspecialchars($selected_store['hours'] ?? ($selected_store['opening_hours'] ?? '')); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Delivery Address Section (Form for Delivery Mode) -->
                        <div id="deliveryAddressSection" style="<?php echo ($current_checkout_delivery_option === 'delivery') ? '' : 'display: none;'; ?>">
                            <div class="co-address-card" id="mainDeliveryAddressCard" style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                                    <h3 style="font-size: 22px; font-weight: 800; color: #2a211d; margin: 0; font-family: inherit;">Delivery address</h3>
                                    <button type="button" id="openChangeAddressModalBtn" style="background: none; border: none; color: #2a211d; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: underline; padding: 4px 8px;">
                                        Change
                                    </button>
                                </div>

                                <div style="display: flex; gap: 12px; align-items: flex-start; margin-bottom: 20px;">
                                    <i class="fas fa-location-dot" style="font-size: 22px; color: #2a211d; margin-top: 2px; flex-shrink: 0;"></i>
                                    <div>
                                        <div id="displayStreetAddress" style="font-size: 15px; font-weight: 700; color: #2a211d; line-height: 1.4;">
                                            <?php echo htmlspecialchars($target_street_display); ?>
                                        </div>
                                        <div id="displayCityAddress" style="font-size: 14px; color: #7b6d64; margin-top: 4px;">
                                            <?php echo htmlspecialchars($target_city_display); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-bottom: 0;">
                                    <input type="text" id="delivery_instructions" name="delivery_instructions" 
                                           placeholder="Note to rider - e.g. building, landmark" 
                                           style="width: 100%; border: 1px solid #efddcd; border-radius: 12px; padding: 14px 16px; font-size: 14px; color: #2a211d; background: #fff9f2; outline: none; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- Delivery Route & Store Overview Map Card -->
                            <div class="co-delivery-map-card" id="deliveryRouteMapCard" style="background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                                    <div>
                                        <h4 style="font-size: 16px; font-weight: 800; color: #101828; margin: 0; display: flex; align-items: center; gap: 8px;">
                                            <i class="fas fa-map-location-dot" style="color: #b3261e; font-size: 18px;"></i> Delivery Route & Store Overview
                                        </h4>
                                        <p style="font-size: 13px; color: #667085; margin: 4px 0 0 0;">
                                            Real-time distance, estimated delivery time, and route from our store branch to your pinned address.
                                        </p>
                                    </div>
                                    <button type="button" id="recenterRouteMapBtn" style="background: #ffffff; border: 1px solid #d0d5dd; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: #344054; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s;">
                                        <i class="fas fa-crosshairs" style="color: #b3261e;"></i> Recenter Map
                                    </button>
                                </div>

                                <!-- Overview Metrics Strip -->
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 14px;">
                                    <div style="background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; padding: 10px 12px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #667085; margin-bottom: 3px; display: flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-store" style="color: #b3261e;"></i> Fulfilling Store
                                        </div>
                                        <div id="overviewStoreName" style="font-size: 13px; font-weight: 800; color: #101828; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo htmlspecialchars($overview_store_name); ?>
                                        </div>
                                    </div>
                                    <div style="background: #eff8ff; border: 1px solid #b2ddff; border-radius: 10px; padding: 10px 12px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #175cd3; margin-bottom: 3px; display: flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-route"></i> Distance
                                        </div>
                                        <div id="overviewDistance" style="font-size: 13px; font-weight: 800; color: #175cd3;">
                                            <?php echo $overview_distance_km > 0 ? number_format($overview_distance_km, 1) . ' km' : '0.0 km'; ?>
                                        </div>
                                    </div>
                                    <div style="background: #ecfdf3; border: 1px solid #abefc6; border-radius: 10px; padding: 10px 12px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #027a48; margin-bottom: 3px; display: flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-clock"></i> Est. Delivery
                                        </div>
                                        <div id="overviewEta" style="font-size: 13px; font-weight: 800; color: #027a48;">
                                            <?php echo !empty($overview_eta) ? htmlspecialchars(str_replace('Estimated delivery: ', '', $overview_eta)) : '35 - 50 mins'; ?>
                                        </div>
                                    </div>
                                    <div style="background: #fff1f0; border: 1px solid #fee4e2; border-radius: 10px; padding: 10px 12px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #b3261e; margin-bottom: 3px; display: flex; align-items: center; gap: 5px;">
                                            <i class="fas fa-peso-sign"></i> Delivery Fee
                                        </div>
                                        <div id="overviewDeliveryFee" style="font-size: 13px; font-weight: 800; color: #b3261e;">
                                            ₱<?php echo number_format($overview_fee, 2); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Map Canvas Shell -->
                                <div id="deliveryOverviewMapShell" style="height: 280px; width: 100%; border-radius: 12px; overflow: hidden; position: relative; background: #f8f9fa; border: 1px solid #eaecf0;">
                                    <div id="checkoutDeliveryOverviewMap" style="width: 100%; height: 280px; min-height: 280px; z-index: 1;"></div>
                                </div>

                                <!-- Map Legend Footer -->
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 12px; color: #667085; flex-wrap: wrap; gap: 8px;">
                                    <div style="display: flex; align-items: center; gap: 14px;">
                                        <span style="display: inline-flex; align-items: center; gap: 5px;">
                                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #b3261e;"></span>
                                            <strong style="color: #344054;">Store:</strong> <span id="legendStoreText"><?php echo htmlspecialchars($overview_store_name); ?></span>
                                        </span>
                                        <span style="display: inline-flex; align-items: center; gap: 5px;">
                                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #101828;"></span>
                                            <strong style="color: #344054;">Delivery:</strong> <span id="legendCustomerText"><?php echo htmlspecialchars($target_street_display); ?></span>
                                        </span>
                                    </div>
                                    <div style="font-size: 11px; color: #98a2b3;">
                                        <i class="fas fa-shield-alt"></i> Automatic calculation from fulfilling store
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden Address & PSGC Form Fields -->
                        <input type="hidden" id="street_address" name="street_address" value="<?php echo htmlspecialchars($target_street_address); ?>">
                        <input type="hidden" id="postal_code" name="postal_code" value="<?php echo htmlspecialchars($target_postal_code); ?>">
                        <input type="hidden" id="delivery_address" name="delivery_address" value="<?php echo htmlspecialchars($target_full_address); ?>">
                        <input type="hidden" id="delivery_region_name" name="delivery_region_name" value="<?php echo htmlspecialchars($target_region_name); ?>">
                        <input type="hidden" id="delivery_region_code" name="delivery_region_code" value="<?php echo htmlspecialchars($target_region_code); ?>">
                        <input type="hidden" id="delivery_province_name" name="delivery_province_name" value="<?php echo htmlspecialchars($target_province_name); ?>">
                        <input type="hidden" id="delivery_province_code" name="delivery_province_code" value="<?php echo htmlspecialchars($target_province_code); ?>">
                        <input type="hidden" id="delivery_city_name" name="delivery_city_name" value="<?php echo htmlspecialchars($target_city_name); ?>">
                        <input type="hidden" id="delivery_city_code" name="delivery_city_code" value="<?php echo htmlspecialchars($target_city_code); ?>">
                        <input type="hidden" id="delivery_barangay_name" name="delivery_barangay_name" value="<?php echo htmlspecialchars($target_barangay_name); ?>">
                        <input type="hidden" id="delivery_barangay_code" name="delivery_barangay_code" value="<?php echo htmlspecialchars($target_barangay_code); ?>">
                        <input type="hidden" id="delivery_postal_code" name="delivery_postal_code" value="<?php echo htmlspecialchars($target_postal_code); ?>">
                        <input type="hidden" id="saved_address_id" name="saved_address_id" value="<?php echo (int)$target_saved_id; ?>">
                        <input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars($target_lat_val); ?>">
                        <input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars($target_lng_val); ?>">
                        <input type="hidden" id="distance_km" name="distance_km" value="<?php echo htmlspecialchars($target_distance_val); ?>">
                        <input type="hidden" id="calculated_delivery_fee" name="calculated_delivery_fee" value="<?php echo htmlspecialchars($target_fee_val); ?>">

                        <!-- Hidden select elements kept for PSGC JS compatibility -->
                        <select id="checkout_region" style="display:none;"><option value="">--</option></select>
                        <select id="checkout_province" style="display:none;"><option value="">--</option></select>
                        <select id="checkout_city" style="display:none;"><option value="">--</option></select>
                        <select id="checkout_barangay" style="display:none;"><option value="">--</option></select>
                        <select id="saved_address_select" style="display:none;">
                            <option value="">--</option>
                            <?php foreach ($saved_addresses as $saved_address): ?>
                            <option value="<?php echo (int)$saved_address['id']; ?>"><?php echo htmlspecialchars($saved_address['full_address'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                        <!-- Delivery Date & Time (Auto-set to Today/ASAP) -->
                        <input type="hidden" name="delivery_date" value="<?php echo date('Y-m-d'); ?>">
                        <input type="hidden" name="delivery_time" value="ASAP">
                        
                        <div class="form-group" style="margin-top: 16px;">
                            <label for="order_notes">Order Notes (Optional)</label>
                            <textarea id="order_notes" name="order_notes" rows="2" 
                                      placeholder="Special instructions for your order"></textarea>
                        </div>

                        <div style="display: flex; justify-content: space-between; margin-top: 24px; gap: 12px;">
                            <button type="button" class="btn-secondary btn-step-back" data-target="1" style="border: 1px solid #efddcd; padding: 12px 20px; font-weight: 700; border-radius: 8px; cursor: pointer; background: #fff; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn-primary" id="btnNextToPayment" style="background: #b3261e; border: none; padding: 12px 24px; font-weight: 700; border-radius: 8px; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                Continue to Payment <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Discounts and Payment -->
                    <div class="step-content" id="stepContent3">
                        <p class="checkout-section-label"><i class="fas fa-receipt"></i> Discounts and Payment</p>
                        <div class="voucher-section" style="background:#ffffff; border:1px solid #eaecf0; border-radius:16px; padding:20px; margin-bottom:24px; box-shadow:0 2px 8px rgba(16,24,40,0.04);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                <h4 style="margin:0; font-family:'Outfit',sans-serif; font-size:1.1rem; font-weight:800; color:#101828;">
                                    <i class="fas fa-ticket-alt" style="color:#b3261e; margin-right:6px;"></i> Discount Voucher
                                </h4>
                                <?php if (!empty($available_vouchers)): ?>
                                    <span style="font-size:0.78rem; font-weight:700; color:#027a48; background:#ecfdf3; border:1px solid #abefc6; padding:3px 10px; border-radius:999px;">
                                        <i class="fas fa-sparkles"></i> <?php echo count($available_vouchers); ?> Available Promotions
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="voucher-input-row">
                                <input type="text"
                                       id="voucherCodeInput"
                                       maxlength="60"
                                       placeholder="Enter promo code (e.g. WELCOME100, FREESHIP)"
                                       value="<?php echo htmlspecialchars($prefill_voucher_code); ?>">
                                <button type="button" class="btn-voucher-apply" id="applyVoucherBtn" onclick="applyVoucherCode()">
                                    <i class="fas fa-ticket-alt"></i> Apply
                                </button>
                                <button type="button" class="btn-voucher-remove" id="removeVoucherBtn" onclick="removeVoucherCode()" <?php echo $voucher_discount > 0 ? '' : 'style="display:none;"'; ?>>
                                    Remove
                                </button>
                            </div>

                            <p class="voucher-feedback <?php echo $voucher_discount > 0 ? 'success' : (!empty($voucher_message) ? 'warning' : (!empty($prefill_voucher_code) ? 'info' : '')); ?>" id="voucherFeedback" style="margin-top:10px;">
                                <?php
                                if ($voucher_discount > 0) {
                                    $scope_tag = $voucher_scope_label ? ' (' . htmlspecialchars($voucher_scope_label) . ')' : '';
                                    echo '<i class="fas fa-check-circle"></i> Applied ' . htmlspecialchars($applied_voucher_code) . ': -PHP ' . number_format($voucher_discount, 2) . $scope_tag;
                                } elseif (!empty($voucher_message)) {
                                    echo '<i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($voucher_message);
                                } elseif (!empty($prefill_voucher_code)) {
                                    echo '<i class="fas fa-ticket-alt" style="color:#b3261e;"></i> Claimed voucher <strong>' . htmlspecialchars($prefill_voucher_code) . '</strong> is ready. Add items to qualify or click Apply.';
                                } else {
                                    echo '<i class="fas fa-info-circle"></i> Select or claim a promotion below to apply instant discounts to your order.';
                                }
                                ?>
                            </p>

                            <!-- Available Customer Promotions & Store Vouchers Cards -->
                            <?php if (!empty($available_vouchers)): ?>
                                <div class="available-vouchers-container" style="margin-top:16px; padding-top:16px; border-top:1px dashed #eaecf0;">
                                    <div style="font-size:0.84rem; font-weight:800; color:#344054; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-tags" style="color:#b3261e;"></i> Claimed & Available Customer Vouchers:
                                    </div>
                                    <div class="available-vouchers-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:12px;">
                                        <?php foreach ($available_vouchers as $v): ?>
                                            <?php 
                                                $is_current = (strtoupper($applied_voucher_code) === strtoupper($v['code']));
                                                $disc_label = ($v['discount_type'] === 'percent') ? number_format($v['discount_value'], 0) . '% OFF' : 'PHP ' . number_format($v['discount_value'], 2) . ' OFF';
                                                $min_spend_label = $v['min_order_amount'] > 0 ? 'Min. spend PHP ' . number_format($v['min_order_amount'], 2) : 'No min. spend';
                                            ?>
                                            <div class="available-voucher-card<?php echo $is_current ? ' is-active' : ''; ?><?php echo !$v['is_eligible'] ? ' is-ineligible' : ''; ?>" 
                                                 style="background:<?php echo $is_current ? '#ecfdf3' : '#f8f9fa'; ?>; border:1px solid <?php echo $is_current ? '#abefc6' : '#eaecf0'; ?>; border-radius:12px; padding:12px 14px; position:relative; transition:all 0.2s; box-shadow: 0 1px 3px rgba(16,24,40,0.04);">
                                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                                    <span style="font-family:'Outfit',sans-serif; font-weight:800; font-size:0.95rem; color:#b3261e; letter-spacing:0.5px;">
                                                        <?php echo htmlspecialchars($v['code']); ?>
                                                    </span>
                                                    <span style="font-size:0.68rem; font-weight:800; padding:2px 8px; border-radius:999px; background:<?php echo $v['seller_id'] > 0 ? '#eff8ff' : '#fff1f0'; ?>; color:<?php echo $v['seller_id'] > 0 ? '#175cd3' : '#b3261e'; ?>; border:1px solid <?php echo $v['seller_id'] > 0 ? '#b2ddff' : '#fee4e2'; ?>;">
                                                        <?php echo htmlspecialchars($v['badge']); ?>
                                                    </span>
                                                </div>
                                                <div style="font-size:0.84rem; font-weight:700; color:#101828; margin-bottom:2px;">
                                                    <?php echo htmlspecialchars($v['name']); ?> (<?php echo $disc_label; ?>)
                                                </div>
                                                <div style="font-size:0.75rem; color:#667085; margin-bottom:10px;">
                                                    <?php echo htmlspecialchars($min_spend_label); ?>
                                                </div>
                                                <?php if ($is_current): ?>
                                                    <button type="button" class="btn btn-sm" style="width:100%; background:#027a48; color:#fff; font-weight:700; font-size:0.8rem; border-radius:8px; padding:7px; border:none;" disabled>
                                                        <i class="fas fa-check-circle"></i> Applied
                                                    </button>
                                                <?php elseif ($v['is_eligible']): ?>
                                                    <button type="button" onclick="applySelectedVoucher('<?php echo htmlspecialchars($v['code']); ?>')" class="btn btn-sm" style="width:100%; background:#b3261e; color:#fff; font-weight:700; font-size:0.8rem; border-radius:8px; padding:7px; border:none; cursor:pointer;" onmouseover="this.style.background='#981b15';" onmouseout="this.style.background='#b3261e';">
                                                        <i class="fas fa-ticket-alt"></i> Apply Voucher
                                                    </button>
                                                <?php else: ?>
                                                    <?php
                                                        $cart_subtotal_val = (float)($subtotal ?? 0);
                                                        $min_spend_val = (float)($v['min_order_amount'] ?? 0);
                                                        $shortfall = ($min_spend_val > $cart_subtotal_val) ? ($min_spend_val - $cart_subtotal_val) : 0;
                                                    ?>
                                                    <?php if ($shortfall > 0): ?>
                                                        <button type="button" onclick="showVoucherShortfall('<?php echo htmlspecialchars($v['code']); ?>', <?php echo $min_spend_val; ?>, <?php echo $shortfall; ?>)" class="btn btn-sm" style="width:100%; background:#fff1f0; color:#b3261e; font-weight:700; font-size:0.75rem; border-radius:8px; padding:7px; border:1px solid #fee4e2; cursor:pointer;" title="Click to view requirements">
                                                            <i class="fas fa-plus-circle"></i> Add PHP <?php echo number_format($shortfall, 2); ?> to unlock
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" onclick="showVoucherShortfall('<?php echo htmlspecialchars($v['code']); ?>', 0, 0, '<?php echo htmlspecialchars(addslashes($v['ineligible_reason'])); ?>')" class="btn btn-sm" style="width:100%; background:#f2f4f7; color:#667085; font-weight:700; font-size:0.75rem; border-radius:8px; padding:7px; border:1px solid #eaecf0; cursor:pointer;" title="Click for details">
                                                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($v['ineligible_reason']); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Payment Type Selection -->
                        <div class="payment-type-section">
                            <h4>Payment Option</h4>
                            <div class="payment-type-options">
                                <label class="payment-type-option">
                                    <input type="radio" name="payment_type" value="full" checked>
                                    <div class="option-content">
                                        <span class="option-title">Full Payment</span>
                                        <span class="option-amount">PHP <?php echo number_format($total, 2); ?></span>
                                        <small>Pay the complete amount now</small>
                                    </div>
                                </label>
                                <label class="payment-type-option">
                                    <input type="radio" name="payment_type" value="downpayment">
                                    <div class="option-content">
                                        <span class="option-title">30% Downpayment</span>
                                        <span class="option-amount">PHP <?php echo number_format($downpayment, 2); ?></span>
                                        <small>Pay 30% now, balance on delivery (PHP <?php echo number_format($remaining, 2); ?>)</small>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Payment Info Section -->
                        <div class="payment-info-section">
                            <h4>Payment Information</h4>
                            <div class="payment-info-box">
                                <p><strong>Amount to Pay:</strong></p>
                                <p class="payment-amount" id="paymentAmount">PHP <?php echo number_format($total, 2); ?></p>
                                <p class="payment-note"><i class="fas fa-info-circle"></i> You will be redirected to PayMongo to complete the payment securely.</p>
                            </div>
                        </div>
                        
                        <input type="hidden" name="payment_method" value="paymongo">
                        
                        <div class="terms-agreement">
                            <label class="checkbox-label">
                                <input type="checkbox" name="terms" required>
                                <span>
                                    I agree to the
                                    <a href="terms_of_service.php" data-policy-modal="terms">Terms and Conditions</a>
                                    and
                                    <a href="privacy_policy.php" data-policy-modal="privacy">Privacy Policy</a>
                                </span>
                            </label>
                        </div>
                        
                        <div class="checkout-actions">
                            <button type="button" class="btn-secondary btn-step-back" data-target="2" style="border: 1px solid #efddcd; padding: 12px 20px; font-weight: 700; border-radius: 8px; cursor: pointer; background: #fff; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="submit" class="btn-primary" id="submitOrder" <?php echo $checkout_tenant_blocked ? 'disabled aria-disabled="true"' : ''; ?> style="background: #b3261e; border: none; padding: 12px 24px; font-weight: 700; border-radius: 8px; color: #fff; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-lock"></i> Proceed to Payment
                            </button>
                        </div>
                        <p class="checkout-final-note">
                            <i class="fas fa-shield-alt"></i> Your payment details are securely handled via PayMongo.
                        </p>
                    </div>

                    <div class="checkout-mode-sticky" id="checkoutModeSticky" aria-label="Quick fulfillment switch">
                        <span class="checkout-mode-sticky-label">Quick mode</span>
                        <div class="checkout-mode-sticky-actions" role="group" aria-label="Mobile fulfillment mode">
                            <button type="button"
                                    id="stickyModePickupBtn"
                                    class="checkout-mode-btn <?php echo $current_checkout_delivery_option === 'pickup' ? 'is-active' : ''; ?>"
                                    data-mode="pickup"
                                    onclick="switchCheckoutFulfillmentMode('pickup')">
                                <i class="fas fa-store"></i> Pickup
                            </button>
                            <button type="button"
                                    id="stickyModeDeliveryBtn"
                                    class="checkout-mode-btn <?php echo $current_checkout_delivery_option === 'delivery' ? 'is-active' : ''; ?>"
                                    data-mode="delivery"
                                    onclick="switchCheckoutFulfillmentMode('delivery')">
                                <i class="fas fa-truck"></i> Delivery
                            </button>
                        </div>
                    </div>
                    
                    <!-- Hidden fields for delivery option -->
                    <input type="hidden" id="delivery_option_hidden" name="delivery_option" value="<?php echo htmlspecialchars($current_checkout_delivery_option); ?>">
                    <input type="hidden" id="pickup_location_hidden" name="pickup_location" value="<?php echo $_SESSION['pickup_location'] ?? 1; ?>">
                    <input type="hidden" id="delivery_location_hidden" name="delivery_location" value="<?php echo $_SESSION['delivery_location'] ?? 'metro_manila'; ?>">
                    <input type="hidden" id="total_amount" name="total_amount" value="<?php echo $total; ?>">
                    <input type="hidden" id="downpayment_amount" name="downpayment_amount" value="<?php echo $downpayment; ?>">
                    <input type="hidden" id="voucher_id_hidden" name="voucher_id" value="<?php echo (int)$applied_voucher_id; ?>">
                    <input type="hidden" id="voucher_code_hidden" name="voucher_code" value="<?php echo htmlspecialchars($applied_voucher_code); ?>">
                    <input type="hidden" id="voucher_discount_hidden" name="voucher_discount" value="<?php echo number_format($voucher_discount, 2, '.', ''); ?>">
                </form>
            </div>
        </div>
    </div>
</section>

<!-- Add SweetAlert2 CSS & JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Add new styles for payment breakdown */
.payment-breakdown {
    margin-top: 20px;
    padding: 15px;
    background-color: #f0f7ff;
    border-radius: 8px;
    border-left: 4px solid #1976d2;
}

.breakdown-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    color: #333;
    font-size: 1rem;
}

.breakdown-row .downpayment-amount {
    color: #ff9800;
    font-weight: 600;
}

.breakdown-row .remaining-amount {
    color: #666;
}

.breakdown-row .full-amount {
    color: #4caf50;
    font-weight: 600;
}

.payment-type-section {
    margin-bottom: 25px;
}

.voucher-row {
    color: #166534;
    font-weight: 600;
}

.voucher-section {
    margin-bottom: 24px;
    padding: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.voucher-section h4 {
    margin: 0 0 12px;
    color: #111827;
}

.voucher-input-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.voucher-input-row input {
    flex: 1;
}

.btn-voucher-apply,
.btn-voucher-remove {
    border: none;
    border-radius: 8px;
    padding: 11px 14px;
    font-weight: 700;
    cursor: pointer;
}

.btn-voucher-apply {
    background: #16a34a;
    color: #fff;
}

.btn-voucher-remove {
    background: #e5e7eb;
    color: #111827;
}

.voucher-feedback {
    margin: 10px 0 0;
    font-size: .9rem;
    color: #475569;
}

.voucher-feedback.success {
    color: #166534;
    font-weight: 600;
}

.voucher-feedback.warning {
    color: #b45309;
    font-weight: 600;
}

.payment-type-section h4 {
    color: #333;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.payment-type-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.payment-type-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background-color: #f9f9f9;
    border: 2px solid #ddd;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.payment-type-option:hover {
    border-color: #c62828;
    background-color: #fff;
}

.payment-type-option input[type="radio"]:checked + .option-content {
    color: #c62828;
}

.payment-type-option input[type="radio"]:checked ~ .option-content .option-title {
    font-weight: 700;
    color: #c62828;
}

.option-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.option-title {
    font-weight: 600;
    font-size: 1.1rem;
    color: #333;
}

.option-amount {
    font-weight: 700;
    font-size: 1.2rem;
}

.option-content small {
    color: #666;
    font-size: 0.85rem;
}

.payment-method-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #c62828, #e53935);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}

.payment-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background-color: #f9f9f9;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.payment-option:hover {
    background-color: #f0f0f0;
}

.payment-option input[type="radio"]:checked ~ span {
    font-weight: 600;
    color: #c62828;
}

.payment-option input[type="radio"]:checked {
    border-color: #c62828;
}

/* Add to existing checkout CSS */
.btn-primary {
    background: linear-gradient(135deg, #c62828, #e53935);
    color: white;
    border: none;
    padding: 16px 30px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(198, 40, 40, 0.4);
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
    border: none;
    padding: 16px 30px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    text-decoration: none;
}

.btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-2px);
}

.checkout-section {
    padding: 60px 0;
}

.checkout-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
}

.checkout-summary {
    background-color: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
}

.checkout-summary h3 {
    color: #333;
    margin-bottom: 25px;
    font-size: 1.5rem;
    border-bottom: 2px solid #c62828;
    padding-bottom: 10px;
}

.summary-items {
    margin-bottom: 30px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 15px 0;
    border-bottom: 1px solid #eee;
}

.summary-item:last-child {
    border-bottom: none;
}

.item-info h4 {
    color: #333;
    margin-bottom: 5px;
    font-size: 1.1rem;
}

.item-info p {
    color: #666;
    font-size: 0.9rem;
    margin: 3px 0;
}

.item-price {
    color: #c62828;
    font-weight: 600;
    font-size: 1.1rem;
}

.summary-totals {
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    color: #555;
}

.total-row.grand-total {
    font-weight: 700;
    color: #333;
    font-size: 1.2rem;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #ddd;
}

.delivery-info {
    padding: 20px;
    background-color: #f0f7ff;
    border-radius: 8px;
    color: #1976d2;
}

.delivery-info h4 {
    color: #1976d2;
    margin-bottom: 10px;
    font-size: 1.1rem;
}

.checkout-form {
    background-color: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
}

.checkout-form h3 {
    color: #333;
    margin-bottom: 25px;
    font-size: 1.5rem;
    border-bottom: 2px solid #c62828;
    padding-bottom: 10px;
}

.delivery-option-form {
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.checkout-type-switch {
    border: 1px solid #e7d6c8;
    border-radius: 12px;
    padding: 14px;
    background: #fffaf3;
    margin-bottom: 16px;
}

.checkout-type-title {
    margin: 0 0 8px;
    color: #3d2b22;
    font-weight: 700;
    font-size: 0.95rem;
}

.checkout-type-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.checkout-type-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    padding: 9px 12px;
    border-radius: 999px;
    border: 1px solid #d9b79c;
    background: #fff;
    color: #7a2e21;
    font-weight: 700;
    transition: all 0.2s ease;
}

.checkout-type-chip:hover {
    background: #fff2e6;
    border-color: #c98f65;
}

.checkout-type-chip.is-active {
    background: #c62828;
    border-color: #c62828;
    color: #fff;
    cursor: default;
    pointer-events: none;
}

.checkout-type-note {
    margin: 10px 0 0;
    color: #6f5f53;
    font-size: 0.85rem;
}

.checkout-mode-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 14px;
    border-radius: 12px;
    border: 1px solid #e5d3c1;
    background: #fff;
    margin-bottom: 16px;
}

.checkout-mode-card h4 {
    margin: 0 0 6px;
    color: #37251d;
}

.checkout-mode-card p {
    margin: 0;
    color: #5f5045;
    font-size: 0.92rem;
}

.checkout-mode-toggle {
    display: inline-flex;
    gap: 8px;
    flex-wrap: wrap;
}

.checkout-mode-btn {
    border: 1px solid #d9b79c;
    background: #fff;
    color: #7a2e21;
    border-radius: 10px;
    padding: 9px 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.checkout-mode-btn:hover {
    background: #fff2e6;
    border-color: #c98f65;
}

.checkout-mode-btn.is-active {
    background: #c62828;
    color: #fff;
    border-color: #c62828;
}

.checkout-mode-sticky {
    display: none;
}

.checkout-mode-sticky-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #5f5045;
    margin-bottom: 6px;
    display: inline-block;
}

.checkout-mode-sticky-actions {
    display: inline-flex;
    gap: 8px;
}

.checkout-mode-card.mode-delivery {
    border-left: 4px solid #c62828;
}

.checkout-mode-card.mode-pickup {
    border-left: 4px solid #2f6d44;
}

.checkout-mode-change-link {
    text-decoration: none;
    white-space: nowrap;
    border-radius: 10px;
    padding: 10px 12px;
}

.delivery-options {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.delivery-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background-color: white;
    border: 2px solid #ddd;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    flex: 1;
}

.delivery-option:hover {
    border-color: #c62828;
}

.delivery-option input[type="radio"]:checked + span {
    font-weight: 600;
    color: #c62828;
}

.store-select, .delivery-select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    margin-top: 10px;
}

.store-info {
    margin-top: 15px;
    padding: 15px;
    background-color: white;
    border-radius: 8px;
    border-left: 4px solid #28a745;
}

.store-info p {
    margin: 5px 0;
    color: #555;
    font-size: 0.9rem;
}

.store-info i {
    color: #c62828;
    width: 20px;
}

.btn-update {
    background-color: #28a745;
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: background-color 0.3s;
    margin-top: 15px;
}

.btn-update:hover {
    background-color: #218838;
}

.form-group {
    margin-bottom: 25px;
}

.account-autofill-note {
    margin: -8px 0 18px;
    padding: 10px 12px;
    background: #eef8f1;
    border: 1px solid #cdebd6;
    border-radius: 8px;
    color: #1f5f39;
    font-size: 0.9rem;
}

.account-autofill-note i {
    margin-right: 6px;
}

.saved-address-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px;
}

.delivery-address-shell {
    background: #fffaf4;
    border: 1px solid #efddcc;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 8px;
}

.delivery-address-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.delivery-address-head h4 {
    margin: 0 0 4px;
    color: #2a211d;
    font-size: 1rem;
}

.delivery-address-head p {
    margin: 0;
    color: #6f5f53;
    font-size: 0.88rem;
}

.address-manage-link {
    text-decoration: none;
    border-radius: 10px;
    padding: 9px 11px;
    white-space: nowrap;
}

.saved-address-select {
    min-height: 46px;
}

.save-address-btn {
    white-space: nowrap;
    border: 2px solid #233f32;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.address-fields-row {
    margin-bottom: 6px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-weight: 600;
    font-size: 0.95rem;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.3s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    border-color: #c62828;
    outline: none;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.address-search-container {
    display: flex;
    gap: 10px;
}

.address-search {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
}

.btn-search {
    background-color: #c62828;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-location {
    background-color: #fff;
    color: #c62828;
    border: 2px solid #c62828;
    padding: 12px 15px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1.1rem;
    transition: all 0.3s;
}

.btn-location:hover {
    background-color: #ffebee;
}

.btn-search:hover {
    background-color: #a71c1c;
}

.help-text {
    margin-top: 10px;
    color: #666;
    font-size: 0.85rem;
}

.help-text i {
    color: #c62828;
    margin-right: 5px;
}

.payment-info-section {
    margin-bottom: 25px;
}

.payment-info-section h4 {
    color: #333;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.payment-info-box {
    background: linear-gradient(135deg, #c62828, #e53935);
    color: white;
    padding: 25px;
    border-radius: 8px;
    text-align: center;
}

.payment-info-box p {
    margin: 10px 0;
}

.payment-info-box p:first-child {
    font-size: 0.95rem;
    opacity: 0.9;
}

.payment-amount {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 15px 0 !important;
}

.payment-note {
    font-size: 0.85rem;
    opacity: 0.95;
    margin-top: 15px !important;
}

.payment-note i {
    margin-right: 8px;
}

.payment-methods {
    margin-bottom: 25px;
}

.payment-methods h4 {
    color: #333;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.payment-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.payment-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 8px;
    cursor: pointer;
    transition: background-color 0.3s;
}

.payment-option:hover {
    background-color: #f0f0f0;
}

.payment-option input {
    width: 18px;
    height: 18px;
}

.terms-agreement {
    margin-bottom: 30px;
    padding: 20px;
    background-color: #f9f9f9;
    border-radius: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.checkbox-label input {
    width: 18px;
    height: 18px;
}

.checkbox-label a {
    color: #c62828;
    text-decoration: none;
}

.checkbox-label a:hover {
    text-decoration: underline;
}

.checkout-actions {
    display: flex;
    gap: 15px;
}

.checkout-actions .btn-secondary,
.checkout-actions .btn-primary {
    flex: 1;
    padding: 16px;
    text-align: center;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
    font-size: 1rem;
}

.checkout-actions .btn-secondary {
    background-color: #6c757d;
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.checkout-actions .btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-2px);
}

.checkout-actions .btn-primary {
    background: linear-gradient(135deg, #c62828, #e53935);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.checkout-actions .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(198, 40, 40, 0.4);
}

/* Modern Food Checkout Refresh */
:root {
    --check-red: #b3261e;
    --check-orange: #ef6b2e;
    --check-cream: #fff8ef;
    --check-ink: #2a211d;
    --check-muted: #7d6f65;
    --check-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--check-cream);
}

.checkout-section {
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
    padding-top: 42px;
}

.checkout-summary,
.checkout-form {
    border: 1px solid var(--check-border);
    border-radius: 18px;
    box-shadow: 0 16px 34px rgba(74, 32, 20, 0.1);
}

.checkout-summary h3,
.checkout-form h3 {
    color: var(--check-ink);
    border-bottom: 2px solid #e9cdb7;
}

.summary-item,
.delivery-option-form,
.checkout-type-switch,
.terms-agreement,
.payment-type-option {
    border: 1px solid var(--check-border);
    border-radius: 12px;
    background: #fff;
}

.summary-totals,
.delivery-info {
    border: 1px solid var(--check-border);
    background: #fff8ef;
}

.delivery-info h4 {
    color: #8f2f1f;
}

.item-price,
.total-row.grand-total,
.option-amount,
.payment-amount {
    color: #9e3322;
}

.payment-breakdown {
    border: 1px solid #e5c6ad;
    background: #fff6eb;
}

.payment-type-option:hover,
.delivery-option:hover {
    border-color: #d59d78;
    background: #fff7ee;
}

.form-group input,
.form-group textarea,
.form-group select,
.address-search {
    border: 1px solid #e8d4c4;
    background: #fffefc;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus,
.address-search:focus {
    border-color: #d1724a;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary,
.btn-search,
.payment-info-box {
    background: linear-gradient(135deg, var(--check-red), var(--check-orange));
}

.checkout-actions .btn-secondary {
    background: #233f32;
}

.btn-location {
    border: 1px solid #cd8f67;
    color: #8c2f1f;
    background: #fff4ea;
}

.help-text,
.item-info p,
.option-content small {
    color: var(--check-muted);
}

@media (max-width: 992px) {
    .checkout-grid {
        grid-template-columns: 1fr;
    }
    
    .delivery-options {
        flex-direction: column;
    }
    
    .payment-options {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 576px) {
    .checkout-actions {
        flex-direction: column;
    }
    
    .address-search-container {
        flex-direction: column;
    }

    .saved-address-row {
        grid-template-columns: 1fr;
    }

    .delivery-address-head,
    .checkout-mode-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .checkout-type-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .checkout-type-chip {
        justify-content: center;
    }

    .voucher-input-row {
        flex-direction: column;
        align-items: stretch;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}

/* Checkout UX refinements */
.checkout-grid {
    align-items: start;
    gap: 28px;
}

.checkout-summary {
    position: sticky;
    top: 92px;
    max-height: calc(100vh - 110px);
    overflow: auto;
}

.checkout-summary-kpis {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}

.checkout-kpi {
    border: 1px solid #ead6c4;
    background: #fff8ef;
    border-radius: 12px;
    padding: 10px 12px;
    display: grid;
    gap: 3px;
}

.checkout-kpi-label {
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #7e6e62;
    font-size: 0.72rem;
    font-weight: 700;
}

.checkout-kpi strong {
    color: #2d241f;
    font-size: 1rem;
}

.checkout-flow {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin: 0 0 16px;
}

.checkout-flow-step {
    border: 1px solid #ecd9c8;
    border-radius: 999px;
    min-height: 40px;
    background: #fff;
    color: #7a6759;
    font-size: 0.82rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.checkout-flow-step span {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 1px solid #d9baa3;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.74rem;
}

.checkout-flow-step.is-active {
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    color: #fff;
    border-color: transparent;
}

.checkout-flow-step.is-active span {
    border-color: rgba(255, 255, 255, 0.55);
}

.checkout-section-label {
    margin: 8px 0 12px;
    color: #3a2921;
    font-weight: 800;
    font-size: 0.9rem;
    letter-spacing: 0.02em;
}

.checkout-section-label i {
    margin-right: 6px;
    color: #a13a26;
}

.checkout-final-note {
    margin: 12px 0 0;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #cfe7d5;
    background: #f2fbf5;
    color: #24553a;
    font-size: 0.86rem;
}

.checkout-final-note i {
    margin-right: 6px;
}

.checkout-actions {
    margin-top: 6px;
}
.checkout-tenant-guard {
    margin: 0 0 14px;
    padding: 12px;
    border: 1px solid #f4b4b4;
    border-radius: 10px;
    background: #fff3f3;
    color: #7a1e1e;
}
.checkout-tenant-guard p {
    margin: 0 0 10px;
    font-weight: 600;
}
.checkout-tenant-guard i {
    margin-right: 6px;
}
.checkout-actions .btn-primary[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
}

@media (max-width: 992px) {
    .checkout-summary {
        position: static;
        max-height: none;
        overflow: visible;
    }
}

@media (max-width: 700px) {
    .checkout-summary-kpis,
    .checkout-flow {
        grid-template-columns: 1fr;
    }

    .checkout-flow-step {
        justify-content: flex-start;
        padding: 0 12px;
    }

    .checkout-mode-sticky {
        display: block;
        position: fixed;
        left: 12px;
        right: 12px;
        bottom: 78px;
        z-index: 998;
        background: rgba(255, 255, 255, 0.98);
        border: 1px solid #f0ddd0;
        border-radius: 12px;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.12);
        padding: 10px;
        backdrop-filter: blur(4px);
    }

    .checkout-mode-sticky-actions {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .checkout-mode-sticky .checkout-mode-btn {
        justify-content: center;
    }

    .checkout-form {
        padding-bottom: 140px;
    }
}

/* Minimalist & Clean Checkout Layout Overrides */
.checkout-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 32px;
}
.checkout-form {
    order: 1;
    background-color: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 12px;
    box-shadow: none !important;
    padding: 32px;
}
.checkout-summary {
    order: 2;
    background-color: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 12px;
    box-shadow: none !important;
    padding: 32px;
}
.checkout-summary h3, .checkout-form h3 {
    color: #171922;
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    font-size: 1.35rem;
    border-bottom: 1px solid #efddcd;
    padding-bottom: 12px;
    margin-bottom: 24px;
}
.checkout-section-label {
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    font-size: 1.02rem;
    color: #171922;
    margin: 8px 0 16px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.form-group label {
    font-weight: 700 !important;
    color: #667085 !important;
    font-size: 0.76rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    margin-bottom: 6px;
}
.form-control, select, textarea {
    border: 1px solid #efddcd !important;
    border-radius: 8px !important;
    padding: 11px 14px !important;
    background: #fcfbf9 !important;
    font-size: 0.95rem !important;
    transition: all 0.2s ease !important;
    color: #171922 !important;
    box-shadow: none !important;
}
.form-control:focus, select:focus, textarea:focus {
    border-color: #b3261e !important;
    background: #ffffff !important;
    box-shadow: 0 0 0 3px rgba(179,38,30,0.1) !important;
}
.payment-option {
    background-color: #fffdfb !important;
    border: 1px solid #efddcd !important;
    border-radius: 10px !important;
    padding: 14px 18px !important;
    transition: all 0.22s ease !important;
    box-shadow: none !important;
}
.payment-option:hover {
    background-color: #fff9f2 !important;
    border-color: #ef6b2e !important;
}
.payment-option input[type="radio"]:checked ~ span {
    color: #b3261e !important;
    font-weight: 700 !important;
}
.payment-breakdown {
    background-color: #fff9f2 !important;
    border-radius: 10px !important;
    border: 1px solid #efddcd !important;
    border-left: 4px solid #b3261e !important;
    padding: 14px !important;
}
.summary-totals {
    background-color: #fff9f2 !important;
    border: 1px solid #efddcd !important;
    border-radius: 10px !important;
    padding: 18px !important;
}
.total-row.grand-total {
    border-top: 1px solid #efddcd !important;
    color: #171922 !important;
    font-family: 'Outfit', sans-serif;
    font-weight: 800 !important;
    font-size: 1.2rem !important;
    padding-top: 14px !important;
}
.voucher-section {
    background: #fffdfb !important;
    border: 1px solid #efddcd !important;
    border-radius: 10px !important;
}
.btn-voucher-apply {
    background: #b3261e !important;
    box-shadow: none !important;
    border-radius: 8px !important;
    font-size: 0.85rem !important;
}
.btn-voucher-apply:hover {
    background: #8f261a !important;
}
.checkout-flow-step {
    border: 1px solid #efddcd !important;
    background: #ffffff !important;
    color: #667085 !important;
    border-radius: 8px !important;
    font-size: 0.8rem !important;
    min-height: 38px !important;
}
.checkout-flow-step.is-active {
    background: #b3261e !important;
    color: #ffffff !important;
    border-color: #b3261e !important;
}
.checkout-flow-step span {
    border-color: #efddcd !important;
    width: 20px !important;
    height: 20px !important;
    font-size: 0.7rem !important;
}
.checkout-flow-step.is-active span {
    background-color: transparent !important;
    border-color: #ffffff !important;
    color: #ffffff !important;
}
.checkout-mode-btn {
    border: 1px solid #efddcd;
    background: #ffffff;
    color: #667085;
    border-radius: 8px;
    font-weight: 700;
    transition: all 0.2s ease;
    padding: 8px 16px;
}
.checkout-mode-btn:hover {
    background-color: #fff8ef;
    border-color: #efddcd;
    color: #171922;
}
.checkout-mode-btn[data-mode="pickup"].is-active,
#modePickupBtn.is-active,
#stickyModePickupBtn.is-active {
    background-color: #ef6b2e !important;
    border-color: #ef6b2e !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(239, 107, 46, 0.25) !important;
}
.checkout-mode-btn[data-mode="delivery"].is-active,
#modeDeliveryBtn.is-active,
#stickyModeDeliveryBtn.is-active {
    background-color: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.25) !important;
}
.checkout-mode-card {
    border-color: #efddcd !important;
    border-radius: 10px !important;
}
.checkout-type-switch {
    background-color: #fffdfb !important;
    border-color: #efddcd !important;
    border-radius: 10px !important;
}
.checkout-type-chip {
    border-radius: 8px !important;
    border-color: #efddcd !important;
    font-size: 0.82rem !important;
}
.checkout-type-chip.is-active {
    background-color: #b3261e !important;
    border-color: #b3261e !important;
}
.checkout-kpi {
    border: 1px solid #efddcd !important;
    background: #fffdfb !important;
    border-radius: 8px !important;
    text-align: center;
}
.checkout-kpi-label {
    color: #667085 !important;
    font-size: 0.65rem !important;
}
.checkout-kpi strong {
    color: #171922 !important;
    font-size: 0.9rem !important;
    font-weight: 800 !important;
    font-family: 'Outfit', sans-serif !important;
}
.summary-item {
    border-bottom: 1px solid #efddcd !important;
    padding: 12px 0 !important;
}
.item-info h4 {
    font-weight: 700 !important;
    font-size: 0.95rem !important;
    color: #171922 !important;
}
.item-price {
    color: #b3261e !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
}
.delivery-info {
    background-color: #fffdfb !important;
    border: 1px solid #efddcd !important;
    border-radius: 10px !important;
    color: #667085 !important;
}
.delivery-info h4 {
    color: #171922 !important;
    font-family: 'Outfit', sans-serif !important;
    font-weight: 800 !important;
    font-size: 1.05rem !important;
    margin-bottom: 8px !important;
}
@media (max-width: 992px) {
    .checkout-grid {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .checkout-form {
        order: 2;
    }
    .checkout-summary {
        order: 1;
    }
}
/* ==========================================================================
   CHECKOUT PAGE DARK THEME ENGINE
   ========================================================================== */
body.dark-mode {
    background-color: #0f172a !important;
    color: #f8fafc !important;
}

body.dark-mode .checkout-section {
    background: #0f172a !important;
}

body.dark-mode .checkout-summary,
body.dark-mode .checkout-form {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35) !important;
}

body.dark-mode .checkout-summary h3,
body.dark-mode .checkout-form h3 {
    color: #f8fafc !important;
    border-bottom-color: #334155 !important;
}

body.dark-mode .checkout-summary-kpis {
    gap: 8px;
}

body.dark-mode .checkout-kpi {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .checkout-kpi-label {
    color: #94a3b8 !important;
}

body.dark-mode .checkout-kpi strong,
body.dark-mode #summaryModeValue {
    color: #f8fafc !important;
}

body.dark-mode .summary-item {
    border-bottom-color: #334155 !important;
    background: transparent !important;
}

body.dark-mode .item-info h4 {
    color: #f8fafc !important;
}

body.dark-mode .item-info p {
    color: #94a3b8 !important;
}

body.dark-mode .item-price {
    color: #ef4444 !important;
}

body.dark-mode .summary-totals {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .total-row {
    color: #cbd5e1 !important;
}

body.dark-mode .total-row span:first-child {
    color: #94a3b8 !important;
}

body.dark-mode .total-row span:last-child {
    color: #f8fafc !important;
}

body.dark-mode .total-row.grand-total {
    border-top-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .total-row.grand-total span:first-child {
    color: #f8fafc !important;
}

body.dark-mode .total-row.grand-total span:last-child,
body.dark-mode #summaryTotal {
    color: #ef4444 !important;
}

body.dark-mode .payment-breakdown {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .payment-breakdown h4 {
    color: #f8fafc !important;
}

body.dark-mode .payment-breakdown h4 span:last-child {
    background: rgba(239, 107, 46, 0.15) !important;
    border-color: rgba(239, 107, 46, 0.4) !important;
    color: #f97316 !important;
}

body.dark-mode .breakdown-row span {
    color: #94a3b8 !important;
}

body.dark-mode .breakdown-row .downpayment-amount {
    color: #fb923c !important;
}

body.dark-mode .breakdown-row .remaining-amount {
    color: #f8fafc !important;
}

body.dark-mode .breakdown-row .full-amount {
    color: #ef4444 !important;
}

body.dark-mode .breakdown-row[style*="border-top"] {
    border-top-color: #334155 !important;
}

body.dark-mode .delivery-info {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .delivery-info h4 {
    color: #f8fafc !important;
}

body.dark-mode #summaryDeliveryDetails {
    color: #f8fafc !important;
}

body.dark-mode #summaryDeliveryTime {
    background: rgba(2, 122, 72, 0.15) !important;
    border-color: rgba(74, 222, 128, 0.4) !important;
    color: #4ade80 !important;
}

body.dark-mode #summaryStoreAddress {
    color: #94a3b8 !important;
}

/* Step Flow */
body.dark-mode .checkout-flow-step {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}

body.dark-mode .checkout-flow-step span {
    border-color: #475569 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .checkout-flow-step.is-active {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-flow-step.is-active span {
    border-color: rgba(255, 255, 255, 0.4) !important;
    color: #ffffff !important;
}

/* Checkout Type Switch */
body.dark-mode .checkout-type-switch {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .checkout-type-title {
    color: #f8fafc !important;
}

body.dark-mode .checkout-type-chip {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .checkout-type-chip:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-type-chip.is-active {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-type-note {
    color: #94a3b8 !important;
}

/* Fulfillment Mode Card */
body.dark-mode .delivery-option-form {
    background: #111827 !important;
    border-color: #334155 !important;
}

body.dark-mode .checkout-mode-card {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .checkout-mode-card h4 {
    color: #f8fafc !important;
}

body.dark-mode .checkout-mode-card p,
body.dark-mode .checkout-mode-card p strong {
    color: #f8fafc !important;
}

body.dark-mode .checkout-mode-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .checkout-mode-btn:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-mode-btn.is-active {
    background: #b3261e !important;
    border-color: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .checkout-mode-btn[data-mode="pickup"].is-active,
body.dark-mode #modePickupBtn.is-active {
    background-color: #ef6b2e !important;
    border-color: #ef6b2e !important;
}

body.dark-mode #findNearestStoreBtn {
    background: #1e293b !important;
    border-color: #b3261e !important;
    color: #ef4444 !important;
}

/* Form Controls, Inputs & Labels */
body.dark-mode .checkout-section-label {
    color: #f8fafc !important;
}

body.dark-mode .form-group label {
    color: #f8fafc !important;
}

body.dark-mode .form-group input,
body.dark-mode .form-group select,
body.dark-mode .form-group textarea,
body.dark-mode .store-select,
body.dark-mode .delivery-select,
body.dark-mode .address-search {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .form-group input:focus,
body.dark-mode .form-group select:focus,
body.dark-mode .form-group textarea:focus,
body.dark-mode .store-select:focus,
body.dark-mode .delivery-select:focus,
body.dark-mode .address-search:focus {
    border-color: #b3261e !important;
    background: #0f172a !important;
    color: #f8fafc !important;
}

body.dark-mode .store-info {
    background: #111827 !important;
    border-color: #334155 !important;
    border-left: 4px solid #027a48 !important;
}

body.dark-mode .store-info p {
    color: #cbd5e1 !important;
}

body.dark-mode .account-autofill-note {
    background: rgba(2, 122, 72, 0.12) !important;
    border-color: rgba(74, 222, 128, 0.3) !important;
    color: #4ade80 !important;
}

body.dark-mode .delivery-fee-info-box {
    background: #111827 !important;
    border: 1px solid #334155 !important;
    color: #cbd5e1 !important;
    padding: 12px;
    border-radius: 8px;
}

body.dark-mode .delivery-fee-info-box p {
    color: #cbd5e1 !important;
}

/* Step 2 Address Cards */
body.dark-mode .co-address-card,
body.dark-mode #mainDeliveryAddressCard,
body.dark-mode .delivery-address-shell {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .co-address-card h3,
body.dark-mode #displayStreetAddress {
    color: #f8fafc !important;
}

body.dark-mode #displayCityAddress {
    color: #94a3b8 !important;
}

body.dark-mode #openChangeAddressModalBtn {
    color: #ef4444 !important;
}

body.dark-mode #delivery_instructions {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .btn-step-back {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .btn-step-back:hover {
    background: #334155 !important;
    color: #ffffff !important;
}

/* Step 3 Voucher and Payment Options */
body.dark-mode .voucher-section {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .voucher-section h4 {
    color: #f8fafc !important;
}

body.dark-mode #voucherCodeInput {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .available-voucher-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}

body.dark-mode .available-voucher-card.is-active {
    background: rgba(2, 122, 72, 0.15) !important;
    border-color: #22c55e !important;
}

body.dark-mode .available-voucher-card div[style*="font-weight:700"] {
    color: #f8fafc !important;
}

body.dark-mode .available-voucher-card div[style*="font-size:0.75rem"] {
    color: #94a3b8 !important;
}

body.dark-mode .available-vouchers-container {
    border-top-color: #334155 !important;
}

body.dark-mode .available-vouchers-container div {
    color: #f8fafc !important;
}

body.dark-mode .payment-type-section h4,
body.dark-mode .payment-info-section h4 {
    color: #f8fafc !important;
}

body.dark-mode .payment-type-option {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .payment-type-option:hover {
    border-color: #b3261e !important;
    background: #1e293b !important;
}

body.dark-mode .option-title {
    color: #f8fafc !important;
}

body.dark-mode .option-amount {
    color: #ef4444 !important;
}

body.dark-mode .option-content small {
    color: #94a3b8 !important;
}

body.dark-mode .payment-info-box {
    background: #111827 !important;
    border: 1px solid #334155 !important;
    border-radius: 12px;
    padding: 16px;
}

body.dark-mode .payment-info-box p {
    color: #cbd5e1 !important;
}

body.dark-mode .payment-amount {
    color: #ef4444 !important;
}

body.dark-mode .payment-note {
    color: #94a3b8 !important;
}

body.dark-mode .terms-agreement {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

body.dark-mode .terms-agreement span,
body.dark-mode .terms-agreement label {
    color: #cbd5e1 !important;
}

body.dark-mode .terms-agreement a {
    color: #ef4444 !important;
}

body.dark-mode .checkout-final-note {
    background: rgba(2, 122, 72, 0.12) !important;
    border-color: rgba(74, 222, 128, 0.3) !important;
    color: #4ade80 !important;
}

/* Modals Dark Mode */
body.dark-mode #changeAddressModal > div,
body.dark-mode #addNewAddressModal > div,
body.dark-mode #editAddressModal > div {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
}

body.dark-mode #changeAddressModal h3,
body.dark-mode #addNewAddressModal h3,
body.dark-mode #editAddressModal h3,
body.dark-mode #changeAddressModal label,
body.dark-mode #addNewAddressModal label,
body.dark-mode #editAddressModal label {
    color: #f8fafc !important;
}

body.dark-mode #changeAddressModal div[style*="border-bottom"],
body.dark-mode #changeAddressModal div[style*="border-top"],
body.dark-mode #addNewAddressModal div[style*="border-bottom"],
body.dark-mode #addNewAddressModal div[style*="border-top"],
body.dark-mode #editAddressModal div[style*="border-bottom"],
body.dark-mode #editAddressModal div[style*="border-top"] {
    border-color: #334155 !important;
}

body.dark-mode .modal-saved-addr-card {
    background: #111827 !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .modal-saved-addr-card.is-selected {
    border-color: #ef4444 !important;
    background: rgba(179, 38, 30, 0.15) !important;
}

body.dark-mode .addr-street-line {
    color: #f8fafc !important;
}

body.dark-mode .addr-city-line,
body.dark-mode .addr-rider-note {
    color: #94a3b8 !important;
}

body.dark-mode .addr-radio-btn {
    background: #111827 !important;
    border-color: #475569 !important;
}

body.dark-mode .modal-saved-addr-card.is-selected .addr-radio-btn {
    border-color: #ef4444 !important;
}

body.dark-mode .modal-saved-addr-card.is-selected .addr-radio-inner {
    background: #ef4444 !important;
}

body.dark-mode .btn-edit-saved-addr,
body.dark-mode .btn-delete-saved-addr,
body.dark-mode #closeChangeAddressModalBtn,
body.dark-mode #closeAddAddressModalBtn,
body.dark-mode #closeEditAddressModalBtn {
    color: #cbd5e1 !important;
}

body.dark-mode #openAddAddressModalBtn {
    color: #f8fafc !important;
}
</style>

<script>
let map;
let marker;
let isMapInitialized = false;

function calculateCoordinatesDistance(lat1, lon1, lat2, lon2) {
    const latA = parseFloat(lat1);
    const lonA = parseFloat(lon1);
    const latB = parseFloat(lat2);
    const lonB = parseFloat(lon2);
    if (!Number.isFinite(latA) || !Number.isFinite(lonA) || !Number.isFinite(latB) || !Number.isFinite(lonB)) {
        return Infinity;
    }
    if (window.L && typeof L.latLng === 'function') {
        return L.latLng(latA, lonA).distanceTo(L.latLng(latB, lonB));
    }
    const R = 6371000;
    const dLat = (latB - latA) * Math.PI / 180;
    const dLon = (lonB - lonA) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(latA * Math.PI / 180) * Math.cos(latB * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}
const storesData = <?php echo json_encode($stores); ?>;
const preferredStoreOwnerId = <?php echo (int)$storefront_seller_id; ?>;
const baseDeliveryFee = <?php echo $base_delivery_fee ?? 50; ?>;
const perKmRate = <?php echo $per_km_rate ?? 15; ?>;
const prepTime = <?php echo $preparation_time_minutes ?? 20; ?>;
const avgSpeed = <?php echo $average_speed_kmh ?? 25; ?>;
const currentSubtotal = <?php echo $subtotal; ?>;
const vatRate = <?php echo $vat_rate; ?>;
let currentDeliveryFee = <?php echo number_format($delivery_fee, 2, '.', ''); ?>;
let currentVoucherDiscount = <?php echo number_format($voucher_discount, 2, '.', ''); ?>;
let currentVoucherCode = <?php echo json_encode($applied_voucher_code); ?>;
const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
const userAddressSeed = <?php echo json_encode((string)($user['address'] ?? '')); ?>;
const accountAutoFill = <?php echo json_encode([
    'full_name' => (string)($user['full_name'] ?? ''),
    'email' => (string)($user['email'] ?? ''),
    'phone' => (string)($user['phone'] ?? '')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let savedAddressesData = <?php echo json_encode(
    checkoutSavedAddressRowsForClient($saved_addresses),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
); ?>;
const defaultSavedAddressId = <?php echo (int)$default_saved_address_id; ?>;
const initialCheckoutDeliveryOption = <?php echo json_encode($current_checkout_delivery_option); ?>;
let activeCheckoutDeliveryOption = (initialCheckoutDeliveryOption === 'delivery') ? 'delivery' : 'pickup';
const initialDeliveryQuote = <?php echo json_encode($current_delivery_quote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const initialStoreContext = <?php echo json_encode([
    'id' => $overview_store_id,
    'name' => $overview_store_name,
    'address' => $overview_store_address,
    'latitude' => $overview_store_lat,
    'longitude' => $overview_store_lng
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const checkoutTenantBlocked = <?php echo $checkout_tenant_blocked ? 'true' : 'false'; ?>;
const checkoutTenantMessage = <?php echo json_encode($checkout_tenant_message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let latestResolvedAddressText = userAddressSeed || '';
const psgcCache = new Map();
const marketAddressPayloadStorageKey = 'market_address_payload';

async function switchCheckoutFulfillmentMode(mode) {
    const normalizedMode = (String(mode || '').toLowerCase() === 'delivery') ? 'delivery' : 'pickup';
    const isDelivery = (normalizedMode === 'delivery');
    activeCheckoutDeliveryOption = normalizedMode;

    // 1. Toggle Button UI Classes
    ['modePickupBtn', 'stickyModePickupBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.classList.toggle('is-active', !isDelivery);
            btn.setAttribute('aria-pressed', (!isDelivery).toString());
        }
    });
    ['modeDeliveryBtn', 'stickyModeDeliveryBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.classList.toggle('is-active', isDelivery);
            btn.setAttribute('aria-pressed', isDelivery.toString());
        }
    });

    // 2. Toggle Mode Card Copy & Summary Labels
    const card = document.getElementById('checkoutModeCard');
    if (card) {
        card.classList.toggle('mode-delivery', isDelivery);
        card.classList.toggle('mode-pickup', !isDelivery);
    }
    const label = document.getElementById('activeModeLabel');
    if (label) label.textContent = isDelivery ? 'Home Delivery' : 'Pickup from Store';
    const summaryVal = document.getElementById('summaryModeValue');
    if (summaryVal) summaryVal.textContent = isDelivery ? 'Delivery' : 'Pickup';

    // 3. Toggle Form Visibility Directly
    const pickupBlock = document.getElementById('pickupLocation');
    const pickupStepSection = document.getElementById('pickupStoreSection');
    const deliveryBlock = document.getElementById('deliveryLocation');
    const deliverySection = document.getElementById('deliveryAddressSection');

    if (pickupBlock) pickupBlock.style.display = isDelivery ? 'none' : 'block';
    if (pickupStepSection) pickupStepSection.style.display = isDelivery ? 'none' : 'block';
    if (deliveryBlock) deliveryBlock.style.display = isDelivery ? 'block' : 'none';
    if (deliverySection) deliverySection.style.display = isDelivery ? 'block' : 'none';

    // 4. Update Hidden Inputs
    const hiddenDeliveryInput = document.getElementById('delivery_option_hidden');
    if (hiddenDeliveryInput) hiddenDeliveryInput.value = normalizedMode;
    const hiddenPickupInput = document.getElementById('pickup_location_hidden');
    if (hiddenPickupInput) hiddenPickupInput.value = document.getElementById('pickup_location')?.value || document.getElementById('pickup_location_step')?.value || '1';

    // 5. Update Delivery Field Requirements
    if (typeof setDeliveryAddressFieldRequirements === 'function') {
        setDeliveryAddressFieldRequirements(isDelivery);
    }

    // 6. Sync with Backend PHP Session & Update Pricing
    if (typeof window.updateDeliveryOption === 'function' || typeof updateDeliveryOption === 'function') {
        const updater = window.updateDeliveryOption || updateDeliveryOption;
        await updater();
    } else {
        const latVal = (document.getElementById('latitude')?.value || '').trim();
        const lngVal = (document.getElementById('longitude')?.value || '').trim();
        const addrVal = (document.getElementById('delivery_address')?.value || '').trim();
        try {
            const bodyParams = {
                delivery_option: normalizedMode,
                pickup_location: document.getElementById('pickup_location')?.value || '1'
            };
            if (isDelivery) {
                if (latVal && lngVal) {
                    bodyParams.latitude = latVal;
                    bodyParams.longitude = lngVal;
                }
                if (addrVal) {
                    bodyParams.delivery_address = addrVal;
                }
            }
            const res = await fetch('update_delivery_option.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(bodyParams)
            });
            const data = await res.json();
            if (data && data.success && typeof updateSummaryUI === 'function') {
                updateSummaryUI(data);
            }
        } catch (err) {
            console.error('Failed to update delivery option on server:', err);
        }

        if (isDelivery) {
            if (latVal && lngVal && typeof calculateDeliveryFee === 'function') {
                await calculateDeliveryFee(latVal, lngVal);
            }
        } else {
            currentDeliveryFee = 0;
            if (typeof recalculateOrderTotals === 'function') {
                recalculateOrderTotals();
            }
        }
    }
}

function normalizePostalCode(value) {
    return String(value || '').replace(/[^\dA-Za-z-]/g, '').trim();
}

async function forwardGeocodeFromNominatim(query) {
    const addressText = String(query || '').trim();
    if (!addressText) return null;
    try {
        const endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2'
            + '&addressdetails=1'
            + '&countrycodes=ph'
            + '&limit=1'
            + '&q=' + encodeURIComponent(addressText);
        const response = await fetch(endpoint, {
            method: 'GET',
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) return null;

        const payload = await response.json();
        if (!Array.isArray(payload) || !payload.length) return null;
        const first = payload[0] || {};
        const lat = Number(first?.lat);
        const lng = Number(first?.lon);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;

        return {
            lat,
            lng,
            formattedAddress: String(first?.display_name || '').trim()
        };
    } catch (error) {
        return null;
    }
}

function extractPostalCode(value) {
    const match = String(value || '').match(/\b\d{4,6}\b/);
    return match ? String(match[0]) : '';
}

function readMarketAddressPayloadFromStorage() {
    try {
        const raw = localStorage.getItem(marketAddressPayloadStorageKey);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                const streetAddress = String(parsed.street_address || '').trim();
                const city = String(parsed.city || '').trim();
                const postalCode = normalizePostalCode(parsed.postal_code || '');
                const fullAddress = String(parsed.full_address || [streetAddress, [city, postalCode].filter(Boolean).join(' ')].filter(Boolean).join(', ')).trim();
                if (streetAddress || city || fullAddress) {
                    return {
                        street_address: streetAddress,
                        city,
                        postal_code: postalCode,
                        full_address: fullAddress
                    };
                }
            }
        }
    } catch (error) {
        console.error('Unable to parse market address payload:', error);
    }

    try {
        const legacy = String(localStorage.getItem('market_address') || '').trim();
        if (!legacy) return null;
        const parts = legacy.split(',').map((part) => part.trim()).filter(Boolean);
        const streetAddress = parts[0] || legacy;
        const cityPart = parts.length > 1 ? parts[parts.length - 1] : '';
        return {
            street_address: streetAddress,
            city: cityPart.replace(/\b\d{4,6}\b/g, '').trim(),
            postal_code: extractPostalCode(cityPart),
            full_address: legacy
        };
    } catch (error) {
        return null;
    }
}

function initMap() {
    if (typeof window.initializeCheckoutMap === 'function') {
        window.initializeCheckoutMap();
    }
    if (typeof window.autoPinCheckoutMapFromHeader === 'function') {
        window.autoPinCheckoutMapFromHeader().catch((error) => {
            console.error('Header payload auto-pin failed:', error);
        });
    }
}

function normalizePlaceName(value) {
    let text = String(value || '').toLowerCase();
    try {
        text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    } catch (error) {
        // Keep original text when normalize is unavailable.
    }
    return text.replace(/[^a-z0-9]/g, '');
}

function toNameTokens(value) {
    let text = String(value || '').toLowerCase();
    try {
        text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    } catch (error) {
        // Keep original text when normalize is unavailable.
    }

    if (text.includes('metro manila')) {
        text += ' ncr national capital region';
    }
    if (text.includes('national capital region') || /\bncr\b/.test(text)) {
        text += ' metro manila ncr';
    }
    if (text.includes('calabarzon') || text.includes('region iv-a') || text.includes('region iva') || text.includes('region 4a')) {
        text += ' calabarzon region iva region 4a iv-a';
    }

    text = text
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    const stopWords = new Set([
        'city',
        'municipality',
        'municipal',
        'province',
        'region',
        'barangay',
        'brgy',
        'of',
        'the',
        'and'
    ]);

    return Array.from(new Set(text.split(/\s+/).filter((token) => token && !stopWords.has(token))));
}

function toCandidateNames(...groups) {
    const seen = new Set();
    const names = [];
    const pushName = (value) => {
        const text = String(value || '').trim();
        if (!text) return;
        const key = normalizePlaceName(text);
        if (!key || seen.has(key)) return;
        seen.add(key);
        names.push(text);
    };

    groups.forEach((group) => {
        if (Array.isArray(group)) {
            group.forEach(pushName);
            return;
        }
        pushName(group);
    });

    return names;
}

function sortByName(items) {
    return [...items].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
}

function getSelectedOptionText(selectElement) {
    if (!selectElement || selectElement.selectedIndex < 0) return '';
    const option = selectElement.options[selectElement.selectedIndex];
    if (!option || !option.value) return '';
    return option.textContent.trim();
}

function findOptionValueByName(selectElement, targetName) {
    if (!selectElement || !targetName) return '';
    const normalizedTarget = normalizePlaceName(targetName);
    const targetTokens = toNameTokens(targetName);
    if (!normalizedTarget || !targetTokens.length) return '';

    let bestValue = '';
    let bestScore = 0;
    let bestOverlap = 0;

    for (const option of Array.from(selectElement.options || [])) {
        if (!option.value) continue;
        const normalizedOption = normalizePlaceName(option.textContent || '');
        if (!normalizedOption) continue;
        if (normalizedOption === normalizedTarget || normalizedOption.includes(normalizedTarget) || normalizedTarget.includes(normalizedOption)) {
            return option.value;
        }

        const optionTokens = toNameTokens(option.textContent || '');
        if (!optionTokens.length) continue;
        const optionTokenSet = new Set(optionTokens);
        let overlap = 0;
        targetTokens.forEach((token) => {
            if (optionTokenSet.has(token)) overlap++;
        });
        if (overlap <= 0) continue;

        const score = overlap / Math.max(targetTokens.length, optionTokens.length);
        if (overlap > bestOverlap || (overlap === bestOverlap && score > bestScore)) {
            bestOverlap = overlap;
            bestScore = score;
            bestValue = option.value;
        }
    }

    if (bestValue && (bestOverlap >= Math.max(1, targetTokens.length - 1) || bestScore >= 0.45)) {
        return bestValue;
    }
    return '';
}

function findOptionValueFromCandidates(selectElement, candidates = []) {
    for (const name of toCandidateNames(candidates)) {
        const code = findOptionValueByName(selectElement, name);
        if (code) return code;
    }
    return '';
}

function setSelectOptions(selectElement, items, placeholder) {
    if (!selectElement) return;
    selectElement.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder;
    selectElement.appendChild(defaultOption);

    sortByName(items).forEach((item) => {
        const option = document.createElement('option');
        option.value = item.code || '';
        option.textContent = item.name || '';
        selectElement.appendChild(option);
    });
}

async function fetchPsgc(path) {
    const key = String(path || '');
    if (psgcCache.has(key)) {
        return psgcCache.get(key);
    }

    const response = await fetch(PSGC_API_BASE + key, {
        method: 'GET',
        headers: { Accept: 'application/json' }
    });
    if (!response.ok) {
        throw new Error('PSGC API request failed for ' + key);
    }
    const payload = await response.json();
    psgcCache.set(key, payload);
    return payload;
}

function syncPsgcHiddenFields() {
    const regionSelect = document.getElementById('checkout_region');
    const provinceSelect = document.getElementById('checkout_province');
    const citySelect = document.getElementById('checkout_city');
    const barangaySelect = document.getElementById('checkout_barangay');
    const postalCodeInput = document.getElementById('postal_code');

    const setValue = (id, value) => {
        const input = document.getElementById(id);
        if (input) input.value = value || '';
    };

    setValue('delivery_region_code', regionSelect?.value || '');
    setValue('delivery_region_name', getSelectedOptionText(regionSelect));
    setValue('delivery_province_code', provinceSelect?.value || '');
    setValue('delivery_province_name', getSelectedOptionText(provinceSelect));
    setValue('delivery_city_code', citySelect?.value || '');
    setValue('delivery_city_name', getSelectedOptionText(citySelect));
    setValue('delivery_barangay_code', barangaySelect?.value || '');
    setValue('delivery_barangay_name', getSelectedOptionText(barangaySelect));
    setValue('delivery_postal_code', normalizePostalCode(postalCodeInput?.value || ''));
}

function buildDeliveryAddressFromFields() {
    const street = (document.getElementById('street_address')?.value || '').trim();
    const postalCode = normalizePostalCode(document.getElementById('postal_code')?.value || '');
    const region = getSelectedOptionText(document.getElementById('checkout_region'));
    const province = getSelectedOptionText(document.getElementById('checkout_province'));
    const city = getSelectedOptionText(document.getElementById('checkout_city'));
    const barangay = getSelectedOptionText(document.getElementById('checkout_barangay'));
    const cityWithPostal = [city, postalCode].filter(Boolean).join(' ');

    if (street && region && city && barangay) {
        return [street, barangay, cityWithPostal, province, region].filter(Boolean).join(', ');
    }

    if (latestResolvedAddressText) {
        return latestResolvedAddressText;
    }

    return street || '';
}

function syncDeliveryAddressField() {
    syncPsgcHiddenFields();
    const hiddenAddress = document.getElementById('delivery_address');
    if (hiddenAddress) {
        hiddenAddress.value = buildDeliveryAddressFromFields();
    }
}

function roundToMoney(value) {
    return Math.round(Number(value || 0) * 100) / 100;
}

function getDeliveryCandidateStores() {
    const stores = Array.isArray(storesData) ? storesData : [];
    const storesWithCoords = stores.filter((store) => {
        const lat = Number(store.latitude);
        const lng = Number(store.longitude);
        return Number.isFinite(lat) && Number.isFinite(lng);
    });

    if (preferredStoreOwnerId > 0) {
        const preferred = storesWithCoords.filter((store) => Number(store.owner_user_id || 0) === preferredStoreOwnerId);
        if (preferred.length > 0) {
            return preferred;
        }
    }

    return storesWithCoords;
}

const moneyFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

function setVoucherFeedback(message, stateClass) {
    const feedback = document.getElementById('voucherFeedback');
    if (!feedback) return;

    let iconHtml = '';
    if (stateClass === 'success') {
        iconHtml = '<i class="fas fa-check-circle" style="margin-right:6px;"></i> ';
    } else if (stateClass === 'warning') {
        iconHtml = '<i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i> ';
    } else {
        iconHtml = '<i class="fas fa-info-circle" style="margin-right:6px;"></i> ';
    }

    feedback.innerHTML = iconHtml + String(message || '');
    feedback.classList.remove('success', 'warning');
    if (stateClass === 'success') {
        feedback.classList.add('success');
    } else if (stateClass === 'warning') {
        feedback.classList.add('warning');
    }
}

function recalculateOrderTotals() {
    const subtotal = roundToMoney(currentSubtotal);
    const vatAmount = roundToMoney(subtotal * vatRate);
    const deliveryFee = Math.max(0, roundToMoney(currentDeliveryFee));
    let voucherDiscount = Math.max(0, roundToMoney(currentVoucherDiscount));

    const maxPossibleDiscount = roundToMoney(subtotal + vatAmount + deliveryFee);
    if (voucherDiscount > maxPossibleDiscount) {
        voucherDiscount = maxPossibleDiscount;
    }
    currentVoucherDiscount = voucherDiscount;

    const total = Math.max(0, roundToMoney(subtotal + vatAmount + deliveryFee - voucherDiscount));
    const downpayment = roundToMoney(total * 0.30);
    const remaining = roundToMoney(total - downpayment);

    const subtotalEl = document.getElementById('summarySubtotal');
    const deliveryEl = document.getElementById('summaryDeliveryFee');
    const vatEl = document.getElementById('summaryVat');
    const totalEl = document.getElementById('summaryTotal');
    if (subtotalEl) subtotalEl.textContent = moneyFormatter.format(subtotal);
    if (deliveryEl) deliveryEl.textContent = moneyFormatter.format(deliveryFee);
    if (vatEl) vatEl.textContent = moneyFormatter.format(vatAmount);
    if (totalEl) totalEl.textContent = moneyFormatter.format(total);

    const voucherRow = document.getElementById('voucherSummaryRow');
    const voucherCodeEl = document.getElementById('summaryVoucherCode');
    const voucherDiscountEl = document.getElementById('summaryVoucherDiscount');
    if (voucherRow) {
        voucherRow.style.display = voucherDiscount > 0 ? '' : 'none';
    }
    if (voucherCodeEl) {
        voucherCodeEl.textContent = currentVoucherCode ? `(${currentVoucherCode})` : '';
    }
    if (voucherDiscountEl) {
        voucherDiscountEl.textContent = '- ' + moneyFormatter.format(voucherDiscount);
    }

    const downpaymentEl = document.querySelector('.downpayment-amount');
    const remainingEl = document.querySelector('.remaining-amount');
    const fullAmountEl = document.querySelector('.full-amount');
    if (downpaymentEl) downpaymentEl.textContent = moneyFormatter.format(downpayment);
    if (remainingEl) remainingEl.textContent = moneyFormatter.format(remaining);
    if (fullAmountEl) fullAmountEl.textContent = moneyFormatter.format(total);

    const totalInput = document.getElementById('total_amount');
    const downpaymentInput = document.getElementById('downpayment_amount');
    const voucherIdInput = document.getElementById('voucher_id_hidden');
    const voucherCodeInputHidden = document.getElementById('voucher_code_hidden');
    const voucherDiscountInput = document.getElementById('voucher_discount_hidden');
    if (totalInput) totalInput.value = total.toFixed(2);
    if (downpaymentInput) downpaymentInput.value = downpayment.toFixed(2);
    if (voucherCodeInputHidden) voucherCodeInputHidden.value = currentVoucherCode || '';
    if (voucherDiscountInput) voucherDiscountInput.value = voucherDiscount.toFixed(2);
    if (voucherIdInput && voucherDiscount <= 0) voucherIdInput.value = '';

    const removeVoucherBtn = document.getElementById('removeVoucherBtn');
    if (removeVoucherBtn) {
        removeVoucherBtn.style.display = voucherDiscount > 0 ? '' : 'none';
    }

    const checkedPayment = document.querySelector('input[name="payment_type"]:checked');
    if (checkedPayment) {
        checkedPayment.dispatchEvent(new Event('change'));
    }

    return {
        subtotal,
        vatAmount,
        deliveryFee,
        voucherDiscount,
        total,
        downpayment,
        remaining
    };
}

async function applyVoucherCode(codeOverride) {
    const input = document.getElementById('voucherCodeInput');
    const applyBtn = document.getElementById('applyVoucherBtn');
    const removeBtn = document.getElementById('removeVoucherBtn');
    const hiddenIdInput = document.getElementById('voucher_id_hidden');

    let voucherCode = (typeof codeOverride === 'string' && codeOverride.trim()) 
        ? codeOverride.trim() 
        : (input?.value || '').trim();

    if (!voucherCode) {
        setVoucherFeedback('Please enter a voucher code.', 'warning');
        return;
    }

    if (applyBtn) applyBtn.disabled = true;
    if (removeBtn) removeBtn.disabled = true;

    try {
        const response = await fetch('apply_voucher.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'apply',
                code: voucherCode
            })
        });

        const rawText = await response.text();
        let result = null;
        try {
            result = JSON.parse(rawText);
        } catch (e) {
            const match = rawText.match(/\{[\s\S]*\}/);
            if (match) {
                result = JSON.parse(match[0]);
            } else {
                throw new Error('Server returned invalid response');
            }
        }

        if (!result.success) {
            currentVoucherCode = '';
            currentVoucherDiscount = 0;
            if (hiddenIdInput) hiddenIdInput.value = '';
            recalculateOrderTotals();
            setVoucherFeedback(result.message || 'Voucher could not be applied.', 'warning');
            return;
        }

        currentVoucherCode = String(result.voucher_code || voucherCode).toUpperCase();
        currentVoucherDiscount = roundToMoney(result.discount_amount || 0);

        if (hiddenIdInput) hiddenIdInput.value = String(result.voucher_id || '');
        if (input) input.value = currentVoucherCode;

        try {
            sessionStorage.removeItem('pending_welcome_voucher');
        } catch (e) {}

        recalculateOrderTotals();
        const scopeBadge = result.scope_label ? ` (${result.scope_label})` : '';
        setVoucherFeedback(`Applied ${currentVoucherCode}: -${moneyFormatter.format(currentVoucherDiscount)}${scopeBadge}`, 'success');

        // Dynamically update available voucher cards UI
        document.querySelectorAll('.available-voucher-card').forEach(card => {
            const cardCodeEl = card.querySelector('span');
            const cardCode = cardCodeEl ? cardCodeEl.textContent.trim().toUpperCase() : '';
            if (cardCode === currentVoucherCode) {
                card.classList.add('is-active');
                card.style.background = '#ecfdf3';
                card.style.borderColor = '#abefc6';
            } else {
                card.classList.remove('is-active');
                if (!card.classList.contains('is-ineligible')) {
                    card.style.background = '#f8f9fa';
                    card.style.borderColor = '#eaecf0';
                }
            }
        });
    } catch (error) {
        console.error('Unable to apply voucher:', error);
        setVoucherFeedback('Voucher request failed. Please try again.', 'warning');
    } finally {
        if (applyBtn) applyBtn.disabled = false;
        if (removeBtn) removeBtn.disabled = false;
    }
}

async function removeVoucherCode() {
    const input = document.getElementById('voucherCodeInput');
    const applyBtn = document.getElementById('applyVoucherBtn');
    const removeBtn = document.getElementById('removeVoucherBtn');
    const hiddenIdInput = document.getElementById('voucher_id_hidden');

    if (applyBtn) applyBtn.disabled = true;
    if (removeBtn) removeBtn.disabled = true;

    try {
        const response = await fetch('apply_voucher.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'remove' })
        });
        const result = await response.json();

        currentVoucherCode = '';
        currentVoucherDiscount = 0;
        if (hiddenIdInput) hiddenIdInput.value = '';
        if (input) input.value = '';

        try {
            sessionStorage.removeItem('pending_welcome_voucher');
        } catch (e) {}

        recalculateOrderTotals();
        setVoucherFeedback(result.message || 'Voucher removed.', '');

        document.querySelectorAll('.available-voucher-card').forEach(card => {
            card.classList.remove('is-active');
            if (!card.classList.contains('is-ineligible')) {
                card.style.background = '#f8f9fa';
                card.style.borderColor = '#eaecf0';
            }
        });
    } catch (error) {
        console.error('Unable to remove voucher:', error);
        setVoucherFeedback('Could not remove voucher right now.', 'warning');
    } finally {
        if (applyBtn) applyBtn.disabled = false;
        if (removeBtn) removeBtn.disabled = false;
    }
}

function applySelectedVoucher(code) {
    if (!code) return;
    const input = document.getElementById('voucherCodeInput');
    if (input) {
        input.value = code;
    }
    applyVoucherCode(code);
}

function showVoucherShortfall(code, minSpend, shortfall, customReason) {
    const input = document.getElementById('voucherCodeInput');
    if (input && code) {
        input.value = code;
    }
    let msg = '';
    if (customReason) {
        msg = `Voucher ${code}: ${customReason}`;
    } else {
        msg = `Voucher ${code} requires a minimum order of PHP ${Number(minSpend).toFixed(2)}. Add PHP ${Number(shortfall).toFixed(2)} more to your cart to use this discount!`;
    }
    setVoucherFeedback(msg, 'warning');
    if (window.showPopupAlert) {
        window.showPopupAlert(msg, 'warning', 4000);
    }
}

// Explicit global exports
window.applyVoucherCode = applyVoucherCode;
window.removeVoucherCode = removeVoucherCode;
window.applySelectedVoucher = applySelectedVoucher;
window.showVoucherShortfall = showVoucherShortfall;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof initMap === 'function') {
        try {
            initMap();
        } catch (initErr) {
            console.warn('initMap startup caught:', initErr);
        }
    }

    // Multi-step form switching logic
    const steps = document.querySelectorAll('.step-content');
    const flowSteps = document.querySelectorAll('.checkout-flow-step');
    
    function showStep(stepNum) {
        steps.forEach((step, idx) => {
            if (idx + 1 === stepNum) {
                step.style.display = 'block';
                step.classList.add('is-active');
            } else {
                step.style.display = 'none';
                step.classList.remove('is-active');
            }
        });
        
        flowSteps.forEach((flowStep, idx) => {
            if (idx + 1 === stepNum) {
                flowStep.classList.add('is-active');
            } else {
                flowStep.classList.remove('is-active');
            }
        });

        const progressBar = document.getElementById('checkoutProgressBar');
        if (progressBar) {
            const widthPercent = stepNum === 1 ? '33.33%' : (stepNum === 2 ? '66.66%' : '100%');
            progressBar.style.width = widthPercent;
        }

        if (stepNum === 2) {
            setTimeout(() => {
                if (typeof window.refreshDeliveryOverviewMap === 'function') {
                    window.refreshDeliveryOverviewMap();
                }
            }, 80);
            setTimeout(() => {
                if (window.deliveryOverviewMap && typeof window.deliveryOverviewMap.invalidateSize === 'function') {
                    window.deliveryOverviewMap.invalidateSize();
                }
            }, 250);
        }
    }

    window.showStep = showStep;
    window.goToStep = showStep;

    function validateStep(stepNum) {
        if (stepNum === 1) {
            const fullName = document.getElementById('full_name');
            const email = document.getElementById('email');
            const phone = document.getElementById('phone');
            
            if (!fullName.value.trim()) {
                Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please enter your full name.', confirmButtonColor: '#b3261e' });
                fullName.focus();
                return false;
            }
            if (!email.value.trim() || !email.checkValidity()) {
                Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please enter a valid email address.', confirmButtonColor: '#b3261e' });
                email.focus();
                return false;
            }
            if (!phone.value.trim() || !phone.checkValidity()) {
                Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please enter a valid 11-digit mobile number.', confirmButtonColor: '#b3261e' });
                phone.focus();
                return false;
            }
        } else if (stepNum === 2) {
            const isDelivery = document.getElementById('delivery_option_hidden').value === 'delivery';
            if (isDelivery) {
                const street = document.getElementById('street_address');
                const deliveryAddress = document.getElementById('delivery_address');
                const displayStreet = document.getElementById('displayStreetAddress');
                const hasAddress = !!(
                    (street && street.value.trim()) ||
                    (deliveryAddress && deliveryAddress.value.trim()) ||
                    (displayStreet && displayStreet.textContent.trim() && displayStreet.textContent.trim() !== 'No address selected')
                );

                if (!hasAddress) {
                    Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please enter or select a delivery address.', confirmButtonColor: '#b3261e' });
                    if (street) street.focus();
                    return false;
                }
            }
        }
        return true;
    }
    
    const nextToAddress = document.getElementById('btnNextToAddress');
    if (nextToAddress) {
        nextToAddress.addEventListener('click', function() {
            if (validateStep(1)) {
                showStep(2);
                if (typeof map !== 'undefined' && map) {
                    setTimeout(() => { map.invalidateSize(); }, 200);
                }
            }
        });
    }
    
    const nextToPayment = document.getElementById('btnNextToPayment');
    if (nextToPayment) {
        nextToPayment.addEventListener('click', function() {
            if (validateStep(2)) {
                showStep(3);
            }
        });
    }
    
    document.querySelectorAll('.btn-step-back').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetStep = parseInt(this.getAttribute('data-target'));
            showStep(targetStep);
        });
    });
    
    flowSteps.forEach(flowStep => {
        flowStep.addEventListener('click', function() {
            const targetStep = parseInt(this.getAttribute('data-step'));
            if (targetStep === 2 && !validateStep(1)) return;
            if (targetStep === 3) {
                if (!validateStep(1)) return;
                if (!validateStep(2)) return;
            }
            showStep(targetStep);
            if (targetStep === 2 && typeof map !== 'undefined' && map) {
                setTimeout(() => { map.invalidateSize(); }, 200);
            }
        });
    });
    
    showStep(1);

    const regionSelect = document.getElementById('checkout_region');
const provinceSelect = document.getElementById('checkout_province');
const citySelect = document.getElementById('checkout_city');
const barangaySelect = document.getElementById('checkout_barangay');
const streetAddressInput = document.getElementById('street_address');
const postalCodeInput = document.getElementById('postal_code');
const fullNameInput = document.getElementById('full_name');
const emailInput = document.getElementById('email');
const phoneInput = document.getElementById('phone');
const savedAddressSelect = document.getElementById('saved_address_select');
const savedAddressIdInput = document.getElementById('saved_address_id');
const applySavedAddressBtn = document.getElementById('applySavedAddressBtn');
const saveCurrentAddressBtn = document.getElementById('saveCurrentAddressBtn');
const marketAddressPayload = readMarketAddressPayloadFromStorage();
const pickupLocationBlock = document.getElementById('pickupLocation');
const deliveryLocationBlock = document.getElementById('deliveryLocation');
const deliveryAddressSection = document.getElementById('deliveryAddressSection');
const checkoutModeCard = document.getElementById('checkoutModeCard');
const activeModeLabel = document.getElementById('activeModeLabel');
const summaryModeValue = document.getElementById('summaryModeValue');
const modePickupBtn = document.getElementById('modePickupBtn');
const modeDeliveryBtn = document.getElementById('modeDeliveryBtn');
const stickyModePickupBtn = document.getElementById('stickyModePickupBtn');
const stickyModeDeliveryBtn = document.getElementById('stickyModeDeliveryBtn');
const deliveryOptionHiddenInput = document.getElementById('delivery_option_hidden');
const pickupLocationHiddenInput = document.getElementById('pickup_location_hidden');
const deliveryLocationHiddenInput = document.getElementById('delivery_location_hidden');
let shouldAutoPinFromMarketPayload = false;
let latestDeliveryQuoteRequestToken = 0;

const normalizeDeliveryOption = (value) => (String(value || '').toLowerCase() === 'delivery' ? 'delivery' : 'pickup');

const applyCheckoutModeUI = (mode) => {
    const normalizedMode = normalizeDeliveryOption(mode);
    const isDelivery = normalizedMode === 'delivery';

    if (checkoutModeCard) {
        checkoutModeCard.classList.toggle('mode-delivery', isDelivery);
        checkoutModeCard.classList.toggle('mode-pickup', !isDelivery);
    }
    if (activeModeLabel) {
        activeModeLabel.textContent = isDelivery ? 'Home Delivery' : 'Pickup from Store';
    }
    if (summaryModeValue) {
        summaryModeValue.textContent = isDelivery ? 'Delivery' : 'Pickup';
    }
    if (modePickupBtn) {
        modePickupBtn.classList.toggle('is-active', !isDelivery);
        modePickupBtn.setAttribute('aria-pressed', (!isDelivery).toString());
    }
    if (modeDeliveryBtn) {
        modeDeliveryBtn.classList.toggle('is-active', isDelivery);
        modeDeliveryBtn.setAttribute('aria-pressed', isDelivery.toString());
    }
    if (stickyModePickupBtn) {
        stickyModePickupBtn.classList.toggle('is-active', !isDelivery);
        stickyModePickupBtn.setAttribute('aria-pressed', (!isDelivery).toString());
    }
    if (stickyModeDeliveryBtn) {
        stickyModeDeliveryBtn.classList.toggle('is-active', isDelivery);
        stickyModeDeliveryBtn.setAttribute('aria-pressed', isDelivery.toString());
    }

    const pickupBlock = document.getElementById('pickupLocation');
    const pickupStepSection = document.getElementById('pickupStoreSection');
    const deliveryBlock = document.getElementById('deliveryLocation');
    const deliverySection = document.getElementById('deliveryAddressSection');

    if (pickupBlock) pickupBlock.style.display = isDelivery ? 'none' : 'block';
    if (pickupStepSection) pickupStepSection.style.display = isDelivery ? 'none' : 'block';
    if (deliveryBlock) deliveryBlock.style.display = isDelivery ? 'block' : 'none';
    if (deliverySection) deliverySection.style.display = isDelivery ? 'block' : 'none';
};

const setCheckoutMode = async (mode, syncServer = true) => {
    activeCheckoutDeliveryOption = normalizeDeliveryOption(mode);
    const isDelivery = activeCheckoutDeliveryOption === 'delivery';
    let alreadySyncedViaQuote = false;

    applyCheckoutModeUI(activeCheckoutDeliveryOption);
    setDeliveryAddressFieldRequirements(isDelivery);

    if (!isDelivery) {
        currentDeliveryFee = 0;
        if (document.getElementById('summaryDeliveryTime')) {
            document.getElementById('summaryDeliveryTime').innerHTML = '';
        }
        recalculateOrderTotals();
    } else {
        const latitudeValue = (document.getElementById('latitude')?.value || '').trim();
        const longitudeValue = (document.getElementById('longitude')?.value || '').trim();
        if (latitudeValue && longitudeValue) {
            await calculateDeliveryFee(latitudeValue, longitudeValue);
            alreadySyncedViaQuote = true;
        } else {
            if (!(Number.isFinite(Number(currentDeliveryFee)) && Number(currentDeliveryFee) > 0)) {
                currentDeliveryFee = 0;
                const detailsEl = document.getElementById('summaryDeliveryDetails');
                if (detailsEl) {
                    detailsEl.textContent = 'Pin your exact location to calculate the delivery fee from the nearest store.';
                }
                if (document.getElementById('summaryDeliveryTime')) {
                    document.getElementById('summaryDeliveryTime').innerHTML = '';
                }
            }
            recalculateOrderTotals();
        }
    }

    if (deliveryOptionHiddenInput) deliveryOptionHiddenInput.value = activeCheckoutDeliveryOption;
    if (pickupLocationHiddenInput) pickupLocationHiddenInput.value = document.getElementById('pickup_location')?.value || '';
    if (deliveryLocationHiddenInput) deliveryLocationHiddenInput.value = '';

    if (syncServer && !alreadySyncedViaQuote) {
        await updateDeliveryOption();
    }
};

applyCheckoutModeUI(activeCheckoutDeliveryOption);

const resetSelect = (selectElement, placeholder, disabled = true) => {
    if (!selectElement) return;
    setSelectOptions(selectElement, [], placeholder);
    selectElement.value = '';
    selectElement.disabled = disabled;
};

const loadRegions = async () => {
    if (!regionSelect) return;
    const regions = await fetchPsgc('/regions');
    setSelectOptions(regionSelect, regions, '-- Select Region --');
    regionSelect.disabled = false;
};

const loadProvinces = async (regionCode) => {
    if (!provinceSelect) return;
    if (!regionCode) {
        resetSelect(provinceSelect, '-- Select Province --');
        return [];
    }
    const provinces = await fetchPsgc('/regions/' + encodeURIComponent(regionCode) + '/provinces');
    setSelectOptions(provinceSelect, provinces, provinces.length ? '-- Select Province --' : '-- No Province --');
    provinceSelect.disabled = provinces.length === 0;
    provinceSelect.required = false;
    return provinces;
};

const loadCities = async (regionCode, provinceCode) => {
    if (!citySelect) return [];
    if (!regionCode) {
        resetSelect(citySelect, '-- Select City/Municipality --');
        return [];
    }

    const path = provinceCode
        ? '/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities'
        : '/regions/' + encodeURIComponent(regionCode) + '/cities-municipalities';

    const cities = await fetchPsgc(path);
    setSelectOptions(citySelect, cities, '-- Select City/Municipality --');
    citySelect.disabled = cities.length === 0;
    return cities;
};

const loadBarangays = async (cityCode) => {
    if (!barangaySelect) return [];
    if (!cityCode) {
        resetSelect(barangaySelect, '-- Select Barangay --');
        return [];
    }
    const barangays = await fetchPsgc('/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays');
    setSelectOptions(barangaySelect, barangays, '-- Select Barangay --');
    barangaySelect.disabled = barangays.length === 0;
    return barangays;
};

const ensureAccountCredentials = () => {
    if (fullNameInput && !fullNameInput.value.trim()) {
        fullNameInput.value = accountAutoFill.full_name || '';
    }
    if (emailInput && !emailInput.value.trim()) {
        emailInput.value = accountAutoFill.email || '';
    }
    if (phoneInput && !phoneInput.value.trim()) {
        phoneInput.value = accountAutoFill.phone || '';
    }
};

const findSavedAddressById = (rawId) => {
    const numericId = Number(rawId || 0);
    if (!numericId) return null;
    return (savedAddressesData || []).find((row) => Number(row.id || 0) === numericId) || null;
};

const refreshSavedAddressOptions = (selectedId = 0) => {
    if (!savedAddressSelect) return;
    const normalizedSelectedId = Number(selectedId || 0);
    savedAddressSelect.innerHTML = '<option value="">-- Select saved address --</option>';

    (savedAddressesData || []).forEach((row) => {
        const option = document.createElement('option');
        const rowId = Number(row.id || 0);
        const label = (row.label || 'Saved Address').trim();
        const fullAddress = (row.full_address || '').trim();
        option.value = String(rowId);
        option.textContent = fullAddress ? `${label} - ${fullAddress}` : label;
        if (rowId === normalizedSelectedId) {
            option.selected = true;
        }
        savedAddressSelect.appendChild(option);
    });

    if (savedAddressIdInput) {
        savedAddressIdInput.value = normalizedSelectedId > 0 ? String(normalizedSelectedId) : '';
    }
};

const applyMarketAddressPayloadToForm = async (payload) => {
    if (!payload || typeof payload !== 'object') return false;

    const streetText = String(payload.street_address || '').trim();
    const cityText = String(payload.city || '').trim();
    const postalCodeText = normalizePostalCode(payload.postal_code || '');
    const fullAddressText = String(payload.full_address || '').trim();

    if (streetAddressInput && streetText && !streetAddressInput.value.trim()) {
        streetAddressInput.value = streetText;
    }
    if (postalCodeInput && postalCodeText && !postalCodeInput.value.trim()) {
        postalCodeInput.value = postalCodeText;
    }
    if (fullAddressText) {
        latestResolvedAddressText = fullAddressText;
    } else if (streetText || cityText) {
        latestResolvedAddressText = [streetText, [cityText, postalCodeText].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    }

    const addressSearchInput = document.getElementById('address_search');
    if (addressSearchInput && latestResolvedAddressText && !addressSearchInput.value.trim()) {
        addressSearchInput.value = latestResolvedAddressText;
    }

    if (cityText || streetText || postalCodeText) {
        await applyAddressComponentsToPsgc({
            city: cityText,
            cityCandidates: toCandidateNames(cityText)
        });
    }

    syncDeliveryAddressField();
    return !!(streetText || cityText || postalCodeText);
};

const autoPinCheckoutMapFromHeaderPayload = async () => {
    if (!shouldAutoPinFromMarketPayload) return false;
    if (activeCheckoutDeliveryOption !== 'delivery') return false;
    if (!marketAddressPayload) return false;

    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    if ((latitudeInput?.value || '').trim() && (longitudeInput?.value || '').trim()) {
        return false;
    }

    const applyPinFromCoords = async (lat, lng) => {
        const pointLat = Number(lat);
        const pointLng = Number(lng);
        if (!Number.isFinite(pointLat) || !Number.isFinite(pointLng)) return false;

        if (latitudeInput) latitudeInput.value = String(pointLat);
        if (longitudeInput) longitudeInput.value = String(pointLng);

        if (typeof map !== 'undefined' && map && typeof marker !== 'undefined' && marker) {
            map.setView([pointLat, pointLng], 17);
            marker.setLatLng([pointLat, pointLng]);
        }

        try {
            await updateAddressFromCoordinates(pointLat, pointLng);
            await calculateDeliveryFee(pointLat, pointLng);
            if (typeof refreshDeliveryOverviewMap === 'function') {
                refreshDeliveryOverviewMap({ customer_lat: pointLat, customer_lng: pointLng });
            }
            shouldAutoPinFromMarketPayload = false;
            return true;
        } catch (error) {
            console.error('Unable to auto-pin checkout map:', error);
            return false;
        }
    };

    const payloadLat = parseFloat(marketAddressPayload.latitude || '');
    const payloadLng = parseFloat(marketAddressPayload.longitude || '');
    if (!Number.isNaN(payloadLat) && !Number.isNaN(payloadLng) && payloadLat !== 0 && payloadLng !== 0) {
        return applyPinFromCoords(payloadLat, payloadLng);
    }

    const query = String(
        marketAddressPayload.full_address
        || [marketAddressPayload.street_address, [marketAddressPayload.city, marketAddressPayload.postal_code].filter(Boolean).join(' ')].filter(Boolean).join(', ')
    ).trim();
    if (!query) return false;

    const fallback = await forwardGeocodeFromNominatim(query);
    if (!fallback) return false;
    return applyPinFromCoords(fallback.lat, fallback.lng);
};

window.autoPinCheckoutMapFromHeader = autoPinCheckoutMapFromHeaderPayload;

const applySavedAddressToForm = async (savedAddress) => {
    if (!savedAddress) return false;

    const addressText = String(savedAddress.full_address || '').trim();
    const streetText = String(savedAddress.street_address || '').trim()
        || (addressText ? addressText.split(',')[0].trim() : '');
    const postalCodeText = extractPostalCode(addressText);

    if (streetAddressInput) {
        streetAddressInput.value = streetText;
    }
    if (postalCodeInput) {
        postalCodeInput.value = postalCodeText || postalCodeInput.value;
    }
    if (fullNameInput && savedAddress.contact_name) {
        fullNameInput.value = String(savedAddress.contact_name);
    }
    if (phoneInput && savedAddress.contact_phone) {
        phoneInput.value = String(savedAddress.contact_phone);
    }
    ensureAccountCredentials();

    const regionCode = String(savedAddress.region_code || '');
    const provinceCode = String(savedAddress.province_code || '');
    const cityCode = String(savedAddress.city_code || '');
    const barangayCode = String(savedAddress.barangay_code || '');

    if (regionCode && regionSelect) {
        regionSelect.value = regionCode;
        const provinces = await loadProvinces(regionCode);

        if (provinceCode && provinceSelect && provinces.length) {
            provinceSelect.value = provinceCode;
        } else if (provinceSelect) {
            provinceSelect.value = '';
        }

        await loadCities(regionCode, provinceSelect?.value || '');
        if (cityCode && citySelect) {
            citySelect.value = cityCode;
            await loadBarangays(cityCode);
        }

        if (barangayCode && barangaySelect) {
            barangaySelect.value = barangayCode;
        }
    } else if (savedAddress.region_name || savedAddress.city_name || savedAddress.barangay_name) {
        await applyAddressComponentsToPsgc({
            region: savedAddress.region_name || '',
            province: savedAddress.province_name || '',
            city: savedAddress.city_name || '',
            barangay: savedAddress.barangay_name || ''
        });
    }

    if (addressText) {
        latestResolvedAddressText = addressText;
    }

    const addressSearchInput = document.getElementById('address_search');
    if (addressSearchInput && addressText) {
        addressSearchInput.value = addressText;
    }

    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    let lat = parseFloat(savedAddress.latitude || '');
    let lng = parseFloat(savedAddress.longitude || '');

    if ((Number.isNaN(lat) || Number.isNaN(lng) || (!lat && !lng)) && addressText) {
        try {
            if (typeof forwardGeocodeFromNominatim === 'function') {
                const geocoded = await forwardGeocodeFromNominatim(addressText);
                if (geocoded) {
                    lat = geocoded.lat;
                    lng = geocoded.lng;
                }
            }
        } catch (e) {
            console.warn('Geocode fallback error:', e);
        }
    }

    if (!Number.isNaN(lat) && !Number.isNaN(lng) && lat !== 0 && lng !== 0) {
        if (latitudeInput) latitudeInput.value = String(lat);
        if (longitudeInput) longitudeInput.value = String(lng);

        if (map && marker) {
            map.setView([lat, lng], 17);
            marker.setLatLng([lat, lng]);
        }
        if (typeof calculateDeliveryFee === 'function') {
            calculateDeliveryFee(lat, lng);
        }
    }

    if (savedAddressIdInput) {
        savedAddressIdInput.value = String(savedAddress.id || '');
    }

    syncDeliveryAddressField();
    if (typeof window.refreshDeliveryOverviewMap === 'function') {
        window.refreshDeliveryOverviewMap();
    }
    return true;
};

const saveCurrentAddress = async () => {
    syncDeliveryAddressField();
    const fullAddressInput = document.getElementById('delivery_address');
    const fullAddress = (fullAddressInput?.value || '').trim();
    const deliveryMode = activeCheckoutDeliveryOption === 'delivery' ? 'delivery' : 'pickup';
    if (deliveryMode !== 'delivery') {
        Swal.fire({
            icon: 'warning',
            title: 'Delivery mode required',
            text: 'Switch to Home Delivery before saving a delivery address.',
            confirmButtonColor: '#c62828'
        });
        return;
    }

    if (!fullAddress) {
        Swal.fire({
            icon: 'warning',
            title: 'Address Required',
            text: 'Please complete your delivery address first.',
            confirmButtonColor: '#c62828'
        });
        return;
    }

    const labelPrompt = await Swal.fire({
        title: 'Save this address',
        text: 'Enter a label so you can find it quickly next time.',
        input: 'text',
        inputValue: 'My Address',
        inputPlaceholder: 'Example: Home or Office',
        showCancelButton: true,
        confirmButtonText: 'Save Address',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        inputValidator: (value) => {
            if (!String(value || '').trim()) {
                return 'Please enter an address label.';
            }
            return null;
        }
    });

    if (!labelPrompt.isConfirmed) {
        return;
    }

    const payload = new URLSearchParams({
        address_action: 'save',
        label: String(labelPrompt.value || 'Saved Address').trim(),
        contact_name: fullNameInput?.value || '',
        contact_phone: phoneInput?.value || '',
        street_address: streetAddressInput?.value || '',
        region_name: document.getElementById('delivery_region_name')?.value || '',
        region_code: document.getElementById('delivery_region_code')?.value || '',
        province_name: document.getElementById('delivery_province_name')?.value || '',
        province_code: document.getElementById('delivery_province_code')?.value || '',
        city_name: document.getElementById('delivery_city_name')?.value || '',
        city_code: document.getElementById('delivery_city_code')?.value || '',
        barangay_name: document.getElementById('delivery_barangay_name')?.value || '',
        barangay_code: document.getElementById('delivery_barangay_code')?.value || '',
        postal_code: postalCodeInput?.value || '',
        full_address: fullAddress,
        latitude: document.getElementById('latitude')?.value || '',
        longitude: document.getElementById('longitude')?.value || '',
        is_default: savedAddressesData.length === 0 ? '1' : '0'
    });

    try {
        if (saveCurrentAddressBtn) saveCurrentAddressBtn.disabled = true;
        const response = await fetch('checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Unable to save address.');
        }

        savedAddressesData = Array.isArray(result.addresses) ? result.addresses : savedAddressesData;
        const selectedAddressId = Number(result.saved_address_id || 0);
        refreshSavedAddressOptions(selectedAddressId);

        const selectedSavedAddress = findSavedAddressById(selectedAddressId);
        if (selectedSavedAddress) {
            await applySavedAddressToForm(selectedSavedAddress);
        }

        Swal.fire({
            icon: 'success',
            title: 'Address Saved',
            text: result.message || 'Your address is ready for future checkouts.',
            timer: 1600,
            showConfirmButton: false
        });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Save Failed',
            text: error.message || 'Could not save address right now.',
            confirmButtonColor: '#c62828'
        });
    } finally {
        if (saveCurrentAddressBtn) saveCurrentAddressBtn.disabled = false;
    }
};

function extractAddressParts(components = []) {
    const bucket = {
        region: [],
        province: [],
        city: [],
        barangay: [],
        postalCode: []
    };

    const pushCandidate = (key, value) => {
        bucket[key] = toCandidateNames(bucket[key], value);
    };

    (components || []).forEach((component) => {
        const types = Array.isArray(component?.types) ? component.types : [];
        if (!types.length) return;

        const longName = String(component?.long_name || '').trim();
        const shortName = String(component?.short_name || '').trim();
        const add = (key) => {
            pushCandidate(key, longName);
            pushCandidate(key, shortName);
        };

        if (types.includes('administrative_area_level_1') || types.includes('region')) {
            add('region');
        }
        if (types.includes('administrative_area_level_2')) {
            add('province');
            add('city');
        }
        if (types.includes('administrative_area_level_3') || types.includes('locality') || types.includes('postal_town')) {
            add('city');
        }
        if (types.some((type) => type === 'sublocality' || type === 'neighborhood' || type.startsWith('sublocality_level_'))) {
            add('barangay');
        }
        if (types.includes('postal_code')) {
            add('postalCode');
        }
    });

    return {
        region: bucket.region[0] || '',
        province: bucket.province[0] || '',
        city: bucket.city[0] || '',
        barangay: bucket.barangay[0] || '',
        postalCode: bucket.postalCode[0] || '',
        regionCandidates: bucket.region,
        provinceCandidates: bucket.province,
        cityCandidates: bucket.city,
        barangayCandidates: bucket.barangay,
        postalCodeCandidates: bucket.postalCode
    };
}

function mergeAddressParts(primary = {}, fallback = {}) {
    const regionCandidates = toCandidateNames(primary.regionCandidates, primary.region, fallback.regionCandidates, fallback.region);
    const provinceCandidates = toCandidateNames(primary.provinceCandidates, primary.province, fallback.provinceCandidates, fallback.province);
    const cityCandidates = toCandidateNames(primary.cityCandidates, primary.city, fallback.cityCandidates, fallback.city);
    const barangayCandidates = toCandidateNames(primary.barangayCandidates, primary.barangay, fallback.barangayCandidates, fallback.barangay);
    const postalCodeCandidates = toCandidateNames(primary.postalCodeCandidates, primary.postalCode, fallback.postalCodeCandidates, fallback.postalCode);

    return {
        region: regionCandidates[0] || '',
        province: provinceCandidates[0] || '',
        city: cityCandidates[0] || '',
        barangay: barangayCandidates[0] || '',
        postalCode: postalCodeCandidates[0] || '',
        regionCandidates,
        provinceCandidates,
        cityCandidates,
        barangayCandidates,
        postalCodeCandidates
    };
}

function hasStructuredPsgcParts(parts = {}) {
    const regions = toCandidateNames(parts.regionCandidates, parts.region);
    const cities = toCandidateNames(parts.cityCandidates, parts.city);
    const barangays = toCandidateNames(parts.barangayCandidates, parts.barangay);
    return regions.length > 0 && cities.length > 0 && barangays.length > 0;
}

async function reverseGeocodeFromNominatim(lat, lng) {
    try {
        const endpoint = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
            + '&addressdetails=1'
            + '&countrycodes=ph'
            + '&lat=' + encodeURIComponent(String(lat))
            + '&lon=' + encodeURIComponent(String(lng));
        const response = await fetch(endpoint, {
            method: 'GET',
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) {
            return { formatted: '', parts: {} };
        }

        const data = await response.json();
        const address = data?.address || {};
        const regionCandidates = toCandidateNames(address.region, address.state);
        const provinceCandidates = toCandidateNames(address.state_district, address.province, address.county);
        const cityCandidates = toCandidateNames(address.city, address.town, address.municipality, address.county);
        const barangayCandidates = toCandidateNames(address.suburb, address.neighbourhood, address.village, address.hamlet, address.quarter);
        const postalCodeCandidates = toCandidateNames(address.postcode);

        return {
            formatted: String(data?.display_name || '').trim(),
            parts: {
                region: regionCandidates[0] || '',
                province: provinceCandidates[0] || '',
                city: cityCandidates[0] || '',
                barangay: barangayCandidates[0] || '',
                postalCode: postalCodeCandidates[0] || '',
                regionCandidates,
                provinceCandidates,
                cityCandidates,
                barangayCandidates,
                postalCodeCandidates
            }
        };
    } catch (error) {
        console.error('Nominatim reverse geocode failed:', error);
        return { formatted: '', parts: {} };
    }
}

async function resolveAddressContextFromCoordinates(lat, lng) {
    let formattedAddress = '';
    let parts = {};

    const fallback = await reverseGeocodeFromNominatim(lat, lng);
    if (fallback.formatted) {
        formattedAddress = fallback.formatted;
    }
    parts = mergeAddressParts(parts, fallback.parts || {});

    return {
        formatted: formattedAddress,
        parts
    };
}

async function applyAddressComponentsToPsgc(parts = {}) {
    if (!regionSelect || !citySelect || !barangaySelect) return;

    const normalizedParts = mergeAddressParts(parts, {});
    const regionCandidates = toCandidateNames(normalizedParts.regionCandidates, normalizedParts.region);
    const provinceCandidates = toCandidateNames(normalizedParts.provinceCandidates, normalizedParts.province);
    const cityCandidates = toCandidateNames(normalizedParts.cityCandidates, normalizedParts.city);
    const barangayCandidates = toCandidateNames(normalizedParts.barangayCandidates, normalizedParts.barangay);

    const regionCode = findOptionValueFromCandidates(regionSelect, regionCandidates);
    if (regionCode) {
        regionSelect.value = regionCode;
    }

    const activeRegionCode = regionCode || regionSelect?.value || '';
    if (!activeRegionCode) {
        syncDeliveryAddressField();
        return;
    }

    const provinces = await loadProvinces(activeRegionCode);
    let provinceCode = '';
    if (provinces.length) {
        provinceCode = findOptionValueFromCandidates(provinceSelect, provinceCandidates);
        if (provinceCode && provinceSelect) {
            provinceSelect.value = provinceCode;
        }
    } else if (provinceSelect) {
        provinceSelect.value = '';
    }

    let cityCode = '';
    if (provinces.length && !provinceCode && provinceSelect) {
        const provinceOptions = Array.from(provinceSelect.options || []).filter((option) => option && option.value);
        for (const option of provinceOptions) {
            await loadCities(activeRegionCode, option.value);
            const candidateCityCode = findOptionValueFromCandidates(citySelect, cityCandidates);
            if (candidateCityCode) {
                provinceCode = option.value;
                provinceSelect.value = option.value;
                cityCode = candidateCityCode;
                break;
            }
        }
        if (!cityCode) {
            await loadCities(activeRegionCode, '');
            cityCode = findOptionValueFromCandidates(citySelect, cityCandidates);
        }
    } else {
        await loadCities(activeRegionCode, provinceCode);
        cityCode = findOptionValueFromCandidates(citySelect, cityCandidates);

        if (!cityCode && provinces.length && provinceSelect) {
            const provinceOptions = Array.from(provinceSelect.options || []).filter((option) => option && option.value);
            for (const option of provinceOptions) {
                if (!option.value || option.value === provinceCode) continue;
                await loadCities(activeRegionCode, option.value);
                const candidateCityCode = findOptionValueFromCandidates(citySelect, cityCandidates);
                if (candidateCityCode) {
                    provinceCode = option.value;
                    provinceSelect.value = option.value;
                    cityCode = candidateCityCode;
                    break;
                }
            }
            if (!cityCode) {
                await loadCities(activeRegionCode, provinceCode || '');
            }
        }
    }

    if (cityCode) {
        citySelect.value = cityCode;
        await loadBarangays(cityCode);
        const barangayCode = findOptionValueFromCandidates(barangaySelect, barangayCandidates);
        if (barangayCode) {
            barangaySelect.value = barangayCode;
        }
    }

    syncDeliveryAddressField();
}

async function applyResolvedMapAddress({ lat, lng, formattedAddress = '', parts = {} } = {}) {
    const latValue = Number(lat);
    const lngValue = Number(lng);
    if (!Number.isFinite(latValue) || !Number.isFinite(lngValue)) return;

    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    if (latitudeInput) latitudeInput.value = String(latValue);
    if (longitudeInput) longitudeInput.value = String(lngValue);

    const addressText = String(formattedAddress || '').trim();
    const streetCandidate = addressText ? (addressText.split(',')[0] || '').trim() : '';
    const postalCandidate = normalizePostalCode(parts?.postalCode || extractPostalCode(addressText));
    if (streetAddressInput) {
        streetAddressInput.value = streetCandidate || streetAddressInput.value;
    }
    if (postalCodeInput && postalCandidate) {
        postalCodeInput.value = postalCandidate;
    }
    if (addressText) {
        latestResolvedAddressText = addressText;
    }

    const addressSearchInput = document.getElementById('address_search');
    if (addressSearchInput && addressText) {
        addressSearchInput.value = addressText;
    }

    if (savedAddressSelect) savedAddressSelect.value = '';
    if (savedAddressIdInput) savedAddressIdInput.value = '';

    await applyAddressComponentsToPsgc(parts);
    syncDeliveryAddressField();
}

const initializeCheckoutPsgcAddress = async () => {
    if (!regionSelect || !provinceSelect || !citySelect || !barangaySelect) return;
    try {
        resetSelect(provinceSelect, '-- Select Province --');
        resetSelect(citySelect, '-- Select City/Municipality --');
        resetSelect(barangaySelect, '-- Select Barangay --');
        regionSelect.disabled = true;
        await loadRegions();

        const lowerAddress = userAddressSeed.toLowerCase();
        if (lowerAddress.includes('cavite')) {
            const caviteRegionCode = findOptionValueByName(regionSelect, 'CALABARZON');
            if (caviteRegionCode) {
                regionSelect.value = caviteRegionCode;
                await loadProvinces(caviteRegionCode);
                const caviteProvinceCode = findOptionValueByName(provinceSelect, 'Cavite');
                if (caviteProvinceCode) {
                    provinceSelect.value = caviteProvinceCode;
                    await loadCities(caviteRegionCode, caviteProvinceCode);
                } else {
                    await loadCities(caviteRegionCode, '');
                }
            }
        }

        if (userAddressSeed) {
            latestResolvedAddressText = userAddressSeed;
        }

        syncDeliveryAddressField();
    } catch (error) {
        console.error('Failed to load PSGC data:', error);
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Address Data Unavailable',
                text: 'PSGC address data could not be loaded. Please refresh and try again.',
                confirmButtonColor: '#c62828'
            });
        }
    }
};

window.showMapContainer = function() {
    const mapDiv = document.getElementById('map');
    if (mapDiv && mapDiv.style.display === 'none') {
        mapDiv.style.display = 'block';
        if (map && typeof map.invalidateSize === 'function') {
            map.invalidateSize();
        }
    }
};

window.initializeCheckoutMap = function() {
    if (isMapInitialized) {
        return;
    }
    const mapEl = document.getElementById('map');
    if (!mapEl || !window.L) {
        return;
    }
    isMapInitialized = true;

    console.log("Initializing Leaflet Map...");
    
    map = L.map('map').setView([14.5995, 120.9842], 11);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    
    marker = L.marker([14.5995, 120.9842], {
        draggable: true
    }).addTo(map);
    
    const input = document.getElementById("address_search");
    if (input) {
        let debounceTimer;
        input.addEventListener('focus', function() {
            window.showMapContainer();
        });
        input.addEventListener('input', function() {
            window.showMapContainer();
            clearTimeout(debounceTimer);
            const query = this.value.trim();
            if (query.length < 4) return;
            
            debounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=ph&limit=1`);
                    const data = await res.json();
                    if (data && data.length > 0) {
                        const first = data[0];
                        const lat = parseFloat(first.lat);
                        const lng = parseFloat(first.lon);
                        
                        map.setView([lat, lng], 17);
                        marker.setLatLng([lat, lng]);
                        
                        updateAddressFromCoordinates(lat, lng);
                        calculateDeliveryFee(lat, lng);
                    }
                } catch (err) {
                    console.error('Nominatim search error:', err);
                }
            }, 800);
        });
    }
    
    marker.on("dragend", () => {
        const position = marker.getLatLng();
        updateAddressFromCoordinates(position.lat, position.lng).catch((error) => {
            console.error('Unable to sync PSGC fields from marker drag:', error);
        });
        calculateDeliveryFee(position.lat, position.lng);
    });
    
    map.on("click", (event) => {
        const coords = event.latlng;
        marker.setLatLng(coords);
        updateAddressFromCoordinates(coords.lat, coords.lng).catch((error) => {
            console.error('Unable to sync PSGC fields from map click:', error);
        });
        calculateDeliveryFee(coords.lat, coords.lng);
    });
    
    console.log("Leaflet Map initialized successfully");
};

// Use My Location Button Logic
const useMyLocationBtn = document.getElementById('useMyLocation');
if (useMyLocationBtn) {
    useMyLocationBtn.addEventListener('click', function() {
        window.showMapContainer();
        if (!map || !marker) {
            if (typeof window.initializeCheckoutMap === 'function') {
                window.initializeCheckoutMap();
            }
        }

    if (navigator.geolocation) {
        Swal.fire({
            title: 'Locating...',
            text: 'Please wait while we find your location',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        
        navigator.geolocation.getCurrentPosition(async (position) => {
            const pos = {
                lat: position.coords.latitude,
                lng: position.coords.longitude,
            };
            
            if (map && marker) {
                map.setView([pos.lat, pos.lng], 17);
                marker.setLatLng([pos.lat, pos.lng]);
            }
            try {
                const synced = await updateAddressFromCoordinates(pos.lat, pos.lng);
                if (!synced) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Address partially resolved',
                        text: 'We found your pin but could not fully match all PSGC fields. Please confirm City and Barangay.',
                        confirmButtonColor: '#c62828'
                    });
                }
            } catch (error) {
                console.error('Unable to sync PSGC fields from current location:', error);
            }
            calculateDeliveryFee(pos.lat, pos.lng);
            Swal.close();
        }, (error) => {
            let message = 'Error: The Geolocation service failed.';
            if (error && error.code === 1) {
                message = 'Location permission was denied. Please allow location access.';
            } else if (error && error.code === 2) {
                message = 'Location could not be determined. Please check your signal and try again.';
            } else if (error && error.code === 3) {
                message = 'Location request timed out. Please try again.';
            }
            Swal.fire('Error', message, 'error');
        }, {
            enableHighAccuracy: true,
            timeout: 12000,
            maximumAge: 300000
        });
    } else {
        Swal.fire('Error', 'Error: Your browser doesn\'t support geolocation.', 'error');
    }
    });
}

// Find Nearest Store Logic
const findNearestStoreBtn = document.getElementById('findNearestStoreBtn');
if (findNearestStoreBtn) {
    findNearestStoreBtn.addEventListener('click', function() {
        if (navigator.geolocation) {
            Swal.fire({ title: 'Finding nearest store...', didOpen: () => { Swal.showLoading() } });
            
            navigator.geolocation.getCurrentPosition((position) => {
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;
                let nearest = null;
                let minDist = Infinity;
                
                getDeliveryCandidateStores().forEach(store => {
                    if (store.latitude && store.longitude) {
                        const storeLat = parseFloat(store.latitude);
                        const storeLng = parseFloat(store.longitude);
                        const dist = calculateCoordinatesDistance(userLat, userLng, storeLat, storeLng);
                        if (dist < minDist) {
                            minDist = dist;
                            nearest = store;
                        }
                    }
                });
                
                if (nearest) {
                    const select = document.getElementById('pickup_location');
                    if (select) {
                        select.value = nearest.id;
                        select.dispatchEvent(new Event('change'));
                    }
                    Swal.fire('Found!', `Nearest store is ${nearest.name || nearest.store_name} (${(minDist/1000).toFixed(1)}km away)`, 'success');
                } else {
                    Swal.fire('Error', 'Could not determine nearest store location.', 'error');
                }
            }, () => {
                Swal.fire('Error', 'Geolocation permission denied.', 'error');
            });
        } else {
            Swal.fire('Error', 'Geolocation not supported.', 'error');
        }
    });
}

function calculateDeliveryFeeFallbackLocally(lat, lng) {
    const userLat = parseFloat(lat);
    const userLng = parseFloat(lng);
    if (Number.isNaN(userLat) || Number.isNaN(userLng)) return;

    let minDistance = Infinity;
    let nearestStoreName = 'Nearest Store';

    getDeliveryCandidateStores().forEach((store) => {
        if (store.latitude && store.longitude) {
            const storeLat = parseFloat(store.latitude);
            const storeLng = parseFloat(store.longitude);
            const distance = calculateCoordinatesDistance(userLat, userLng, storeLat, storeLng);
            if (distance < minDistance) {
                minDistance = distance;
                nearestStoreName = store.name || store.store_name;
            }
        }
    });

    if (minDistance === Infinity) {
        document.getElementById('summaryDeliveryTime').innerHTML = '';
        document.getElementById('summaryDeliveryDetails').textContent = 'No mapped store coordinates are available for delivery pricing yet.';
        return;
    }

    const distanceKm = minDistance / 1000;
    const fee = Math.ceil(baseDeliveryFee + (distanceKm * perKmRate));
    const travelTimeMinutes = (distanceKm / avgSpeed) * 60;
    const totalTimeMinutes = prepTime + travelTimeMinutes;
    const minEta = Math.ceil(totalTimeMinutes / 5) * 5;
    const maxEta = minEta + 15;
    const etaText = `Estimated Delivery: ${minEta} - ${maxEta} minutes`;

    currentDeliveryFee = fee;
    document.getElementById('summaryDeliveryTime').innerHTML = `<i class="fas fa-clock"></i> ${etaText}`;
    document.getElementById('summaryDeliveryFee').textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(fee);
    document.getElementById('summaryDeliveryDetails').innerHTML = `Delivery via ${nearestStoreName} (${distanceKm.toFixed(1)} km)`;
    document.getElementById('calculated_delivery_fee').value = fee;
    document.getElementById('distance_km').value = distanceKm.toFixed(2);
    recalculateOrderTotals();
}

async function calculateDeliveryFee(lat, lng) {
    const latValue = Number(lat);
    const lngValue = Number(lng);
    if (!Number.isFinite(latValue) || !Number.isFinite(lngValue)) return;

    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    if (latitudeInput) latitudeInput.value = String(latValue);
    if (longitudeInput) longitudeInput.value = String(lngValue);

    const requestToken = ++latestDeliveryQuoteRequestToken;
    const summaryDeliveryDetails = document.getElementById('summaryDeliveryDetails');
    const summaryDeliveryTime = document.getElementById('summaryDeliveryTime');
    if (summaryDeliveryDetails) summaryDeliveryDetails.textContent = 'Calculating delivery fee from nearest store...';
    if (summaryDeliveryTime) summaryDeliveryTime.innerHTML = '';

    try {
        const quote = await persistDeliveryQuote(latValue, lngValue);
        if (requestToken !== latestDeliveryQuoteRequestToken) return;
        updateSummaryUI(quote);
    } catch (error) {
        if (requestToken !== latestDeliveryQuoteRequestToken) return;
        console.error('Unable to fetch delivery quote from server:', error);
        calculateDeliveryFeeFallbackLocally(latValue, lngValue);
    }
}

async function persistDeliveryQuote(lat, lng) {
    const response = await fetch('update_delivery_option.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            delivery_option: 'delivery',
            latitude: String(lat),
            longitude: String(lng)
        })
    });

    const result = await response.json();
    if (!result.success) {
        throw new Error(result.message || 'Unable to refresh delivery pricing.');
    }
    return result;
}

async function updateAddressFromCoordinates(lat, lng) {
    const latVal = parseFloat(lat);
    const lngVal = parseFloat(lng);
    if (Number.isNaN(latVal) || Number.isNaN(lngVal)) {
        return false;
    }

    try {
        const resolved = await resolveAddressContextFromCoordinates(latVal, lngVal);
        const mergedParts = mergeAddressParts({}, resolved.parts || {});
        await applyResolvedMapAddress({
            lat: latVal,
            lng: lngVal,
            formattedAddress: resolved.formatted || '',
            parts: mergedParts
        });

        const hasAnyResolvedPart = !!(
            String(mergedParts.region || '').trim()
            || String(mergedParts.province || '').trim()
            || String(mergedParts.city || '').trim()
            || String(mergedParts.barangay || '').trim()
            || String(mergedParts.postalCode || '').trim()
            || String(resolved.formatted || '').trim()
        );
        return hasAnyResolvedPart;
    } catch (error) {
        console.error('Unable to sync PSGC fields from coordinates:', error);
        return false;
    }
}

// Search button handler
const searchAddressBtn = document.getElementById("searchAddress");
if (searchAddressBtn) {
    searchAddressBtn.addEventListener("click", async () => {
        window.showMapContainer();
        if (!map || !marker) {
            if (typeof window.initializeCheckoutMap === 'function') {
                window.initializeCheckoutMap();
            }
        }
        if (!map || !marker) {
            Swal.fire('Map not ready', 'Please wait for the map to finish loading.', 'warning');
            return;
        }

        const address = String(document.getElementById("address_search")?.value || '').trim();
        if (address) {
            Swal.fire({
                title: 'Searching address...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&countrycodes=ph&limit=1`);
                const data = await res.json();
                if (data && data.length > 0) {
                    const first = data[0];
                    const lat = parseFloat(first.lat);
                    const lng = parseFloat(first.lon);
                    
                    map.setView([lat, lng], 17);
                    marker.setLatLng([lat, lng]);
                    
                    await updateAddressFromCoordinates(lat, lng);
                    calculateDeliveryFee(lat, lng);
                    Swal.close();
                } else {
                    Swal.fire('Address not found', 'Please try a more specific address.', 'warning');
                }
            } catch (err) {
                console.error('Nominatim search button error:', err);
                Swal.fire('Search Error', 'Failed to retrieve address details. Please try again.', 'error');
            }
        } else {
            Swal.fire('Missing address', 'Please enter an address to search.', 'warning');
        }
    });
}

function setDeliveryAddressFieldRequirements(isDelivery) {
    if (streetAddressInput) streetAddressInput.required = false;
    if (postalCodeInput) postalCodeInput.required = false;
    if (regionSelect) regionSelect.required = false;
    if (provinceSelect) provinceSelect.required = false;
    if (citySelect) citySelect.required = false;
    if (barangaySelect) barangaySelect.required = false;

    if (!isDelivery) {
        const hiddenAddress = document.getElementById('delivery_address');
        if (hiddenAddress) hiddenAddress.value = '';
        ['delivery_region_name', 'delivery_region_code', 'delivery_province_name', 'delivery_province_code', 'delivery_city_name', 'delivery_city_code', 'delivery_barangay_name', 'delivery_barangay_code', 'delivery_postal_code'].forEach((id) => {
            const input = document.getElementById(id);
            if (input) input.value = '';
        });
    } else {
        syncDeliveryAddressField();
    }
}

if (regionSelect) {
    regionSelect.addEventListener('change', async () => {
        try {
            resetSelect(citySelect, '-- Select City/Municipality --');
            resetSelect(barangaySelect, '-- Select Barangay --');
            const provinces = await loadProvinces(regionSelect.value);
            if (!provinces.length) {
                await loadCities(regionSelect.value, '');
            }
            syncDeliveryAddressField();
            setDeliveryAddressFieldRequirements(activeCheckoutDeliveryOption === 'delivery');
        } catch (error) {
            console.error('Unable to load provinces/cities:', error);
        }
    });
}

if (provinceSelect) {
    provinceSelect.addEventListener('change', async () => {
        try {
            resetSelect(barangaySelect, '-- Select Barangay --');
            await loadCities(regionSelect?.value || '', provinceSelect.value);
            syncDeliveryAddressField();
        } catch (error) {
            console.error('Unable to load cities:', error);
        }
    });
}

if (citySelect) {
    citySelect.addEventListener('change', async () => {
        try {
            await loadBarangays(citySelect.value);
            syncDeliveryAddressField();
            if (activeCheckoutDeliveryOption === 'delivery' && typeof updateDeliveryOption === 'function') {
                updateDeliveryOption();
            }
        } catch (error) {
            console.error('Unable to load barangays:', error);
        }
    });
}

if (barangaySelect) {
    barangaySelect.addEventListener('change', () => {
        syncDeliveryAddressField();
        if (activeCheckoutDeliveryOption === 'delivery' && typeof updateDeliveryOption === 'function') {
            updateDeliveryOption();
        }
    });
}


if (streetAddressInput) {
    streetAddressInput.addEventListener('input', syncDeliveryAddressField);
}
if (postalCodeInput) {
    postalCodeInput.addEventListener('input', syncDeliveryAddressField);
}

if (savedAddressSelect) {
    savedAddressSelect.addEventListener('change', function() {
        if (savedAddressIdInput) {
            savedAddressIdInput.value = this.value || '';
        }
    });
}

if (applySavedAddressBtn) {
    applySavedAddressBtn.addEventListener('click', async () => {
        const selectedId = Number(savedAddressSelect?.value || 0);
        if (!selectedId) {
            Swal.fire({
                icon: 'warning',
                title: 'Select an address',
                text: 'Please choose one saved address first.',
                confirmButtonColor: '#c62828'
            });
            return;
        }

        const selectedAddress = findSavedAddressById(selectedId);
        if (!selectedAddress) {
            Swal.fire({
                icon: 'warning',
                title: 'Address not found',
                text: 'The selected saved address is no longer available.',
                confirmButtonColor: '#c62828'
            });
            return;
        }

        await applySavedAddressToForm(selectedAddress);
    });
}

if (saveCurrentAddressBtn) {
    saveCurrentAddressBtn.addEventListener('click', saveCurrentAddress);
}

// Fulfillment Mode Button Click Listeners (Delivery & Pickup)
document.querySelectorAll('.checkout-mode-btn').forEach(btn => {
    btn.addEventListener('click', async function(e) {
        e.preventDefault();
        e.stopPropagation();
        const mode = this.getAttribute('data-mode') || (this.id && this.id.toLowerCase().includes('delivery') ? 'delivery' : 'pickup');
        try {
            await setCheckoutMode(mode, true);

            // Automatically advance to Step 2 (Address / Store Selection) if currently on Step 1
            if (typeof currentStep !== 'undefined' && currentStep === 1) {
                if (typeof showStep === 'function') {
                    showStep(2);
                } else if (typeof goToStep === 'function') {
                    goToStep(2);
                }
            }
        } catch (error) {
            console.error('Unable to switch fulfillment mode:', error);
        }
    });
});

const pickupSelectMain = document.getElementById('pickup_location');
const pickupSelectStep = document.getElementById('pickup_location_step');

if (pickupSelectMain && pickupSelectStep) {
    pickupSelectStep.addEventListener('change', function() {
        pickupSelectMain.value = this.value;
        pickupSelectMain.dispatchEvent(new Event('change'));
    });
    pickupSelectMain.addEventListener('change', function() {
        pickupSelectStep.value = this.value;
    });
}

if (pickupSelectMain) {
    pickupSelectMain.addEventListener('change', function() {
        if (activeCheckoutDeliveryOption !== 'pickup') return;
        updateDeliveryOption();
    });

    // Update store info when pickup location changes
    pickupSelectMain.addEventListener('change', function() {
        const storeId = this.value;
        const storeInfo = document.getElementById('storeInfo');
        if (!storeInfo) return;
        
        // Show loading
        storeInfo.innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
        setTimeout(() => {
            const stores = <?php echo json_encode($stores); ?>;
            const selectedStore = stores.find(store => store.id == storeId);
            
            if (selectedStore) {
                storeInfo.innerHTML = `
                    <p><i class="fas fa-map-marker-alt"></i> ${selectedStore.address}</p>
                    <p><i class="fas fa-phone"></i> ${selectedStore.phone}</p>
                    <p><i class="fas fa-clock"></i> ${selectedStore.hours || selectedStore.opening_hours || ''}</p>
                `;
            }
        }, 500);
    });
}

function initializePage() {
    // Initialize store info
    const storeId = document.getElementById('pickup_location')?.value || '1';
    const storeInfo = document.getElementById('storeInfo');
    if (storeInfo) {
        const stores = <?php echo json_encode($stores); ?>;
        const selectedStore = stores.find(store => store.id == storeId);
        
        if (selectedStore) {
            storeInfo.innerHTML = `
                <p><i class="fas fa-map-marker-alt"></i> ${selectedStore.address}</p>
                <p><i class="fas fa-phone"></i> ${selectedStore.phone}</p>
                <p><i class="fas fa-clock"></i> ${selectedStore.hours || selectedStore.opening_hours || ''}</p>
            `;
        }
    }
}

// Handle payment type selection
document.querySelectorAll('input[name="payment_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const totalAmount = parseFloat(document.getElementById('total_amount').value);
        const downpaymentAmount = parseFloat(document.getElementById('downpayment_amount').value);
        
        let selectedAmount;
        if (this.value === 'downpayment') {
            selectedAmount = downpaymentAmount;
        } else {
            selectedAmount = totalAmount;
        }
        
        // Update payment amount display
        const paymentAmountDisplay = document.getElementById('paymentAmount');
        const formattedAmount = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(selectedAmount);
        paymentAmountDisplay.textContent = formattedAmount;
        
        // Update submit button text
        const submitBtn = document.getElementById('submitOrder');
        submitBtn.innerHTML = `<i class="fas fa-lock"></i> Pay ${formattedAmount}`;
    });
});

// Bind event listeners for voucher controls
const voucherInputEl = document.getElementById('voucherCodeInput');
const voucherApplyBtnEl = document.getElementById('applyVoucherBtn');
const voucherRemoveBtnEl = document.getElementById('removeVoucherBtn');

if (voucherApplyBtnEl) {
    voucherApplyBtnEl.addEventListener('click', function(e) {
        e.preventDefault();
        applyVoucherCode();
    });
}

if (voucherRemoveBtnEl) {
    voucherRemoveBtnEl.addEventListener('click', function(e) {
        e.preventDefault();
        removeVoucherCode();
    });
}

if (voucherInputEl) {
    voucherInputEl.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyVoucherCode();
        }
    });

    try {
        const pendingVoucher = sessionStorage.getItem('pending_welcome_voucher');
        if (pendingVoucher && !voucherInputEl.value.trim()) {
            voucherInputEl.value = pendingVoucher;
            setTimeout(() => {
                applyVoucherCode(pendingVoucher);
            }, 350);
        }
    } catch(e) {}
}

async function submitCheckoutAjax(formElement) {
    const submitBtn = document.getElementById('submitOrder');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(formElement);
        formData.set('ajax', '1');

        const response = await fetch(formElement.action || 'process_order.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });

        if (response.redirected && response.url) {
            window.location.href = response.url;
            return;
        }

        const rawText = await response.text();
        let result = null;
        try {
            result = JSON.parse(rawText);
        } catch (parseError) {
            const match = rawText.match(/\{[\s\S]*\}/);
            if (match) {
                try {
                    result = JSON.parse(match[0]);
                } catch (e2) {
                    console.error('Failed to parse checkout JSON response:', rawText);
                    throw new Error('Unexpected server response format.');
                }
            } else {
                console.error('Failed to parse checkout response as JSON:', rawText);
                throw new Error('Unexpected server response. Please try again.');
            }
        }

        if (!response.ok || !result.success) {
            if (result?.redirect_url) {
                window.location.href = result.redirect_url;
                return;
            }
            throw new Error(result?.message || 'Unable to process checkout right now.');
        }

        if (!result.redirect_url) {
            throw new Error('Payment redirect URL was not received. Please try again.');
        }

        window.location.href = result.redirect_url;
    } catch (error) {
        console.error('Checkout processing error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Checkout Failed',
            text: error.message || 'There was an issue processing your checkout.',
            confirmButtonColor: '#c62828'
        });
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

// Handle form submission with SweetAlert2 + AJAX
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (typeof checkoutTenantBlocked !== 'undefined' && checkoutTenantBlocked) {
        Swal.fire({
            icon: 'warning',
            title: 'Checkout Blocked',
            text: (typeof checkoutTenantMessage !== 'undefined' && checkoutTenantMessage) ? checkoutTenantMessage : 'Your cart has items from multiple stores. Please checkout one store at a time.',
            confirmButtonColor: '#c62828'
        });
        return;
    }
    const scrollToTarget = (element) => {
        if (!element || typeof element.scrollIntoView !== 'function') {
            return;
        }
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const selectedDeliveryOption = (typeof activeCheckoutDeliveryOption !== 'undefined' && activeCheckoutDeliveryOption === 'delivery') ? 'delivery' : 'pickup';
    const missingFields = [];
    let firstMissingTarget = null;
    const addMissingField = (label, target) => {
        missingFields.push(label);
        if (!firstMissingTarget && target) {
            firstMissingTarget = target;
        }
    };

    const fullNameInput = document.getElementById('full_name');
    const emailInput = document.getElementById('email');
    const phoneInput = document.getElementById('phone');
    const streetAddressInput = document.getElementById('street_address');
    const postalCodeInput = document.getElementById('postal_code');
    const regionSelect = document.getElementById('checkout_region');
    const provinceSelect = document.getElementById('checkout_province');
    const citySelect = document.getElementById('checkout_city');
    const barangaySelect = document.getElementById('checkout_barangay');

    const fullName = (fullNameInput?.value || '').trim();
    const email = (emailInput?.value || '').trim();
    const phone = (phoneInput?.value || '').trim();
    const phoneDigits = phone.replace(/[^\d]/g, '');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const termsInput = document.querySelector('input[name="terms"]');

    if (!fullName) {
        addMissingField('Full name', fullNameInput);
    }
    if (!email || !emailRegex.test(email)) {
        addMissingField('Valid email address', emailInput);
    }
    if (!/^09\d{9}$/.test(phoneDigits)) {
        addMissingField('Valid 11-digit phone number (e.g., 09171234567)', phoneInput);
    }
    if (!termsInput || !termsInput.checked) {
        addMissingField('Accept Terms and Conditions and Privacy Policy', termsInput);
    }

    if (selectedDeliveryOption === 'delivery') {
        if (typeof syncDeliveryAddressField === 'function') {
            syncDeliveryAddressField();
        }
        const street = (streetAddressInput?.value || '').trim();
        const postalCode = (postalCodeInput?.value || '').trim();
        const region = (regionSelect?.value || '').trim();
        const province = (provinceSelect?.value || '').trim();
        const city = (citySelect?.value || '').trim();
        const barangay = (barangaySelect?.value || '').trim();
        const latitude = (document.getElementById('latitude')?.value || '').trim();
        const longitude = (document.getElementById('longitude')?.value || '').trim();
        const provinceNeeded = !!(provinceSelect && !provinceSelect.disabled && provinceSelect.options && provinceSelect.options.length > 1);
        const currentDeliveryAddress = (document.getElementById('delivery_address')?.value || '').trim();
        const hasStructuredAddress = !!(street && postalCode && region && (!provinceNeeded || province) && city && barangay);

        if (!hasStructuredAddress && !currentDeliveryAddress) {
            addMissingField('Complete delivery address details', document.getElementById('deliveryAddressSection'));
        }

        if (!latitude || !longitude) {
            addMissingField('Pin delivery location on the map', document.getElementById('mainDeliveryAddressCard') || document.getElementById('deliveryRouteMapCard'));
        }

        if (typeof syncDeliveryAddressField === 'function') {
            syncDeliveryAddressField();
        }
    }

    if (missingFields.length > 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing information',
            html: `<div style="text-align:left;"><p>Please complete the following before checkout:</p><ul style="margin:8px 0 0 18px;">${missingFields.map((field) => `<li>${field}</li>`).join('')}</ul></div>`,
            confirmButtonColor: '#c62828'
        });
        if (firstMissingTarget) {
            const stepContent = firstMissingTarget.closest('.step-content');
            if (stepContent && stepContent.id) {
                const stepNum = parseInt(stepContent.id.replace('stepContent', ''));
                if (stepNum && typeof showStep === 'function') {
                    showStep(stepNum);
                }
            }
        }
        scrollToTarget(firstMissingTarget || document.getElementById('checkoutForm'));
        return;
    }
    
    // Get payment type and amounts safely
    const paymentTypeRadio = document.querySelector('input[name="payment_type"]:checked');
    const paymentType = paymentTypeRadio ? paymentTypeRadio.value : 'full';
    const totalAmount = parseFloat(document.getElementById('total_amount')?.value || '0') || 0;
    const downpaymentAmount = parseFloat(document.getElementById('downpayment_amount')?.value || '0') || 0;
    
    // Determine amount to pay based on payment type
    let amountToPay = paymentType === 'downpayment' ? downpaymentAmount : totalAmount;
    
    // Format currency
    const formattedAmount = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amountToPay);
    
    // Build confirmation message
    let confirmationHtml = `
        <div style="text-align: left;">
            <p><strong>Payment Type:</strong> ${paymentType === 'downpayment' ? '30% Downpayment' : 'Full Payment'}</p>
            <p><strong>Amount to Pay:</strong></p>
            <p style="font-size: 1.5rem; color: #c62828; font-weight: bold; margin: 15px 0;">${formattedAmount}</p>
    `;
    
    if (paymentType === 'downpayment') {
        const remainingAmount = totalAmount - downpaymentAmount;
        const formattedRemaining = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(remainingAmount);
        confirmationHtml += `<p><strong>Remaining Balance:</strong> ${formattedRemaining} (due on delivery)</p>`;
    }
    
    confirmationHtml += `
            <hr>
            <p><small><i class="fas fa-info-circle"></i> You will be redirected to PayMongo to complete the payment securely.</small></p>
        </div>
    `;
    
    Swal.fire({
        title: 'Confirm Order',
        html: confirmationHtml,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Proceed to Payment',
        cancelButtonText: 'Review Order'
    }).then(async (result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait while we prepare your order',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit checkout without refreshing the page
            const formElement = document.getElementById('checkoutForm');
            await submitCheckoutAjax(formElement);
        }
    });
});

async function updateDeliveryOption() {
    const deliveryOption = activeCheckoutDeliveryOption === 'delivery' ? 'delivery' : 'pickup';
    // Clear ETA when switching to pickup
    if (deliveryOption === 'pickup') {
        const timeEl = document.getElementById('summaryDeliveryTime');
        if (timeEl) timeEl.innerHTML = '';
    }

    let data = { delivery_option: deliveryOption };

    if (deliveryOption === 'pickup') {
        data.pickup_location = document.getElementById('pickup_location')?.value || '1';
    } else {
        const latitudeValue = (document.getElementById('latitude')?.value || '').trim();
        const longitudeValue = (document.getElementById('longitude')?.value || '').trim();
        if (latitudeValue && longitudeValue) {
            data.latitude = latitudeValue;
            data.longitude = longitudeValue;
        }
        const deliveryAddressVal = (document.getElementById('delivery_address')?.value || '').trim();
        if (deliveryAddressVal) {
            data.delivery_address = deliveryAddressVal;
        }
    }

    try {
        const response = await fetch('update_delivery_option.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        });
        const result = await response.json();
        if (result.success) {
            activeCheckoutDeliveryOption = normalizeDeliveryOption(result.delivery_option || deliveryOption);
            applyCheckoutModeUI(activeCheckoutDeliveryOption);
            if (result.customer_lat && result.customer_lng) {
                const latInput = document.getElementById('latitude');
                const lngInput = document.getElementById('longitude');
                if (latInput && !latInput.value) latInput.value = String(result.customer_lat);
                if (lngInput && !lngInput.value) lngInput.value = String(result.customer_lng);
            }
            updateSummaryUI(result);
            if (deliveryOptionHiddenInput) deliveryOptionHiddenInput.value = activeCheckoutDeliveryOption;
            if (pickupLocationHiddenInput) pickupLocationHiddenInput.value = result.pickup_location || '';
            if (deliveryLocationHiddenInput) deliveryLocationHiddenInput.value = result.delivery_location || '';
        }
    } catch (error) {
        console.error('Error updating delivery option:', error);
    }
}


function updateSummaryUI(data) {
    const deliveryFee = parseFloat(data.delivery_fee || 0);
    currentDeliveryFee = deliveryFee;
    
    const detailsEl = document.getElementById('summaryDeliveryDetails');
    if (detailsEl) {
        detailsEl.innerHTML = `<i class="fas fa-store" style="color: #ef6b2e; margin-right: 6px;"></i>${data.delivery_details || '...'}`;
    }
    
    const summaryDeliveryTime = document.getElementById('summaryDeliveryTime');
    if (summaryDeliveryTime) {
        if (data.estimated_delivery_text) {
            summaryDeliveryTime.style.display = 'inline-flex';
            summaryDeliveryTime.innerHTML = `<i class="fas fa-clock"></i> ${data.estimated_delivery_text}`;
        } else {
            summaryDeliveryTime.style.display = 'none';
            summaryDeliveryTime.innerHTML = '';
        }
    }

    const summaryStoreAddress = document.getElementById('summaryStoreAddress');
    if (summaryStoreAddress) {
        if (data.nearest_store_address) {
            summaryStoreAddress.style.display = 'block';
            summaryStoreAddress.innerHTML = `<i class="fas fa-location-dot" style="color: #b3261e; margin-right: 4px;"></i>${data.nearest_store_address}`;
        } else {
            summaryStoreAddress.style.display = 'none';
            summaryStoreAddress.innerHTML = '';
        }
    }

    if ((data.delivery_option || '').toLowerCase() === 'pickup') {
        const calculatedFeeInput = document.getElementById('calculated_delivery_fee');
        const distanceInput = document.getElementById('distance_km');
        if (calculatedFeeInput) calculatedFeeInput.value = '0';
        if (distanceInput) distanceInput.value = '';
    } else {
        const calculatedFeeInput = document.getElementById('calculated_delivery_fee');
        const distanceInput = document.getElementById('distance_km');
        if (calculatedFeeInput) calculatedFeeInput.value = String(deliveryFee.toFixed(2));
        if (distanceInput && data.distance_km) distanceInput.value = String(data.distance_km);
    }

    recalculateOrderTotals();
    if (typeof refreshDeliveryOverviewMap === 'function') {
        refreshDeliveryOverviewMap(data);
    }
}

// ==========================================
// Delivery Route & Store Overview Map (Leaflet)
// ==========================================
let deliveryOverviewMap = null;
let deliveryOverviewStoreMarker = null;
let deliveryOverviewCustomerMarker = null;
let deliveryOverviewRouteLine = null;

function createOverviewPinIcon(type, iconClass) {
    const isStore = type === 'store';
    const bg = isStore ? '#b3261e' : '#101828';
    const shadow = isStore ? 'rgba(179,38,30,0.45)' : 'rgba(16,24,40,0.45)';
    const label = isStore ? 'Store Branch' : 'Your Address';
    return L.divIcon({
        className: 'custom-overview-pin',
        html: `<div style="display:flex; flex-direction:column; align-items:center; transform: translate(-50%, -100%); pointer-events: auto;">
            <div style="background:${bg}; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 8px ${shadow}; border:2.5px solid #ffffff; font-size:15px;">
                <i class="${iconClass}"></i>
            </div>
            <div style="background:#ffffff; color:#101828; font-size:11px; font-weight:800; padding:2px 6px; border-radius:4px; margin-top:2px; box-shadow:0 1px 4px rgba(0,0,0,0.2); border:1px solid #eaecf0; white-space:nowrap;">
                ${label}
            </div>
        </div>`,
        iconSize: [34, 48],
        iconAnchor: [17, 48],
        popupAnchor: [0, -42]
    });
}

function refreshDeliveryOverviewMap(quoteData = null) {
    const mapCanvas = document.getElementById('checkoutDeliveryOverviewMap');
    if (!mapCanvas) return;

    if (!window.L) {
        console.warn('Leaflet library is still loading, retrying in 300ms...');
        setTimeout(() => refreshDeliveryOverviewMap(quoteData), 300);
        return;
    }

    // 1. Resolve Customer Coordinates
    let custLat = quoteData && Number.isFinite(Number(quoteData.customer_lat)) ? Number(quoteData.customer_lat) : null;
    let custLng = quoteData && Number.isFinite(Number(quoteData.customer_lng)) ? Number(quoteData.customer_lng) : null;
    if (custLat === null || custLng === null) {
        const latInput = (document.getElementById('latitude')?.value || '').trim();
        const lngInput = (document.getElementById('longitude')?.value || '').trim();
        if (latInput && lngInput) {
            custLat = parseFloat(latInput);
            custLng = parseFloat(lngInput);
        }
    }
    if ((!Number.isFinite(custLat) || !Number.isFinite(custLng) || (custLat === 0 && custLng === 0)) && typeof initialDeliveryQuote === 'object') {
        if (initialDeliveryQuote && initialDeliveryQuote.customer_lat && initialDeliveryQuote.customer_lng) {
            custLat = parseFloat(initialDeliveryQuote.customer_lat);
            custLng = parseFloat(initialDeliveryQuote.customer_lng);
        }
    }

    // 2. Resolve Store Coordinates & Context
    let storeId = quoteData?.nearest_store_id || initialDeliveryQuote?.nearest_store_id || (typeof initialStoreContext === 'object' ? initialStoreContext?.id : 0) || 0;
    let storeObj = null;
    if (Array.isArray(storesData)) {
        storeObj = storesData.find(s => Number(s.id || s.store_id) === Number(storeId)) || storesData[0] || null;
    }
    let storeLat = storeObj && storeObj.latitude ? parseFloat(storeObj.latitude) : (typeof initialStoreContext === 'object' ? initialStoreContext?.latitude || 14.3294 : 14.3294);
    let storeLng = storeObj && storeObj.longitude ? parseFloat(storeObj.longitude) : (typeof initialStoreContext === 'object' ? initialStoreContext?.longitude || 120.9367 : 120.9367);
    let storeName = quoteData?.nearest_store_name || storeObj?.name || storeObj?.store_name || (typeof initialStoreContext === 'object' ? initialStoreContext?.name : 'Nearest Store') || 'Nearest Store';
    let storeAddress = quoteData?.nearest_store_address || storeObj?.address || (typeof initialStoreContext === 'object' ? initialStoreContext?.address : '') || '';

    // 3. Resolve Metrics (Distance, Time, Fee)
    let distanceKm = quoteData?.distance_km !== undefined ? parseFloat(quoteData.distance_km) : (initialDeliveryQuote?.distance_km ? parseFloat(initialDeliveryQuote.distance_km) : 0);
    let etaText = quoteData?.estimated_delivery_text || initialDeliveryQuote?.estimated_delivery_text || '';
    let deliveryFee = quoteData?.delivery_fee !== undefined ? parseFloat(quoteData.delivery_fee) : (typeof currentDeliveryFee !== 'undefined' ? currentDeliveryFee : 0);
    let customerStreet = (document.getElementById('displayStreetAddress')?.textContent || '').trim();
    if (!customerStreet || customerStreet === 'Loading address...') {
        customerStreet = document.getElementById('street_address')?.value || 'Your Delivery Location';
    }

    // 4. Update Header Metrics
    const storeNameEl = document.getElementById('overviewStoreName');
    const distanceEl = document.getElementById('overviewDistance');
    const etaEl = document.getElementById('overviewEta');
    const feeEl = document.getElementById('overviewDeliveryFee');
    const legendStoreEl = document.getElementById('legendStoreText');
    const legendCustomerEl = document.getElementById('legendCustomerText');

    if (storeNameEl) storeNameEl.textContent = storeName;
    if (distanceEl) distanceEl.textContent = distanceKm > 0 ? `${distanceKm.toFixed(1)} km` : '0.0 km';
    if (etaEl) etaEl.textContent = etaText ? etaText.replace(/^Estimated delivery:\s*/i, '') : (distanceKm > 0 ? '35 - 50 mins' : 'ASAP');
    if (feeEl) feeEl.textContent = typeof moneyFormatter !== 'undefined' ? moneyFormatter.format(deliveryFee) : `₱${deliveryFee.toFixed(2)}`;
    if (legendStoreEl) legendStoreEl.textContent = storeName;
    if (legendCustomerEl) legendCustomerEl.textContent = customerStreet;

    // 5. Initialize Leaflet Map Instance if not yet initialized
    if (!deliveryOverviewMap) {
        if (mapCanvas._leaflet_id) {
            mapCanvas._leaflet_id = null;
        }
        try {
            deliveryOverviewMap = L.map('checkoutDeliveryOverviewMap', {
                zoomControl: true,
                attributionControl: false
            }).setView([storeLat || 14.3294, storeLng || 120.9367], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19
            }).addTo(deliveryOverviewMap);
            window.deliveryOverviewMap = deliveryOverviewMap;
        } catch (mapErr) {
            console.warn('Error initializing checkoutDeliveryOverviewMap:', mapErr);
            return;
        }
    }

    // Force map to adapt to container layout
    try {
        deliveryOverviewMap.invalidateSize();
        setTimeout(() => {
            if (deliveryOverviewMap) deliveryOverviewMap.invalidateSize();
        }, 120);
    } catch (e) {}

    // 6. Update Map Markers & Route Polyline
    const validStore = Number.isFinite(storeLat) && Number.isFinite(storeLng) && storeLat !== 0 && storeLng !== 0;
    const validCustomer = Number.isFinite(custLat) && Number.isFinite(custLng) && custLat !== 0 && custLng !== 0;

    if (validStore) {
        if (!deliveryOverviewStoreMarker) {
            deliveryOverviewStoreMarker = L.marker([storeLat, storeLng], {
                icon: createOverviewPinIcon('store', 'fas fa-store')
            }).addTo(deliveryOverviewMap);
        } else {
            deliveryOverviewStoreMarker.setLatLng([storeLat, storeLng]);
        }
        deliveryOverviewStoreMarker.bindPopup(`<strong>${storeName}</strong><br><span style="font-size:12px; color:#667085;">${storeAddress || 'Origin Branch Store'}</span>`);
    }

    if (validCustomer) {
        if (!deliveryOverviewCustomerMarker) {
            deliveryOverviewCustomerMarker = L.marker([custLat, custLng], {
                icon: createOverviewPinIcon('customer', 'fas fa-house-user')
            }).addTo(deliveryOverviewMap);
        } else {
            deliveryOverviewCustomerMarker.setLatLng([custLat, custLng]);
        }
        deliveryOverviewCustomerMarker.bindPopup(`<strong>Your Delivery Address</strong><br><span style="font-size:12px; color:#667085;">${customerStreet}</span>`);
    }

    if (validStore && validCustomer) {
        const routeCoords = [[storeLat, storeLng], [custLat, custLng]];
        if (!deliveryOverviewRouteLine) {
            deliveryOverviewRouteLine = L.polyline(routeCoords, {
                color: '#b3261e',
                weight: 4,
                opacity: 0.85,
                dashArray: '8, 8',
                lineJoin: 'round'
            }).addTo(deliveryOverviewMap);
        } else {
            deliveryOverviewRouteLine.setLatLngs(routeCoords);
        }

        try {
            const bounds = L.latLngBounds(routeCoords);
            if (bounds.isValid()) {
                deliveryOverviewMap.fitBounds(bounds, {
                    padding: [45, 45],
                    maxZoom: 16
                });
            }
        } catch (e) {
            console.warn('fitBounds error:', e);
        }
    } else if (validStore) {
        deliveryOverviewMap.setView([storeLat, storeLng], 14);
    } else if (validCustomer) {
        deliveryOverviewMap.setView([custLat, custLng], 14);
    }
}

window.refreshDeliveryOverviewMap = refreshDeliveryOverviewMap;
window.updateDeliveryOption = updateDeliveryOption;
window.updateSummaryUI = updateSummaryUI;

// Recenter Button Handler
document.addEventListener('click', function(e) {
    const btn = e.target && (e.target.id === 'recenterRouteMapBtn' || e.target.closest('#recenterRouteMapBtn'));
    if (btn) {
        e.preventDefault();
        if (deliveryOverviewMap) {
            deliveryOverviewMap.invalidateSize();
            if (deliveryOverviewStoreMarker && deliveryOverviewCustomerMarker) {
                const bounds = L.latLngBounds([
                    deliveryOverviewStoreMarker.getLatLng(),
                    deliveryOverviewCustomerMarker.getLatLng()
                ]);
                if (bounds.isValid()) {
                    deliveryOverviewMap.fitBounds(bounds, { padding: [45, 45], maxZoom: 16 });
                }
            } else if (deliveryOverviewStoreMarker) {
                deliveryOverviewMap.setView(deliveryOverviewStoreMarker.getLatLng(), 14);
            }
        }
    }
});

initializePage();
ensureAccountCredentials();
refreshSavedAddressOptions(defaultSavedAddressId);
if (activeCheckoutDeliveryOption === 'delivery' && initialDeliveryQuote && initialDeliveryQuote.success) {
    updateSummaryUI({
        delivery_option: 'delivery',
        delivery_fee: initialDeliveryQuote.fee || 0,
        delivery_details: initialDeliveryQuote.delivery_details || '',
        nearest_store_address: initialDeliveryQuote.nearest_store_address || '',
        distance_km: initialDeliveryQuote.distance_km || '',
        estimated_delivery_text: initialDeliveryQuote.estimated_delivery_text || ''
    });
}

(async () => {
    await initializeCheckoutPsgcAddress();
    let appliedSavedAddress = false;

    if (savedAddressesData.length > 0) {
        const selectedAddressId = Number(savedAddressSelect?.value || defaultSavedAddressId || 0);
        const selectedAddress = findSavedAddressById(selectedAddressId)
            || savedAddressesData.find((row) => Number(row.is_default || 0) === 1)
            || savedAddressesData[0];

        if (selectedAddress) {
            await applySavedAddressToForm(selectedAddress);
            refreshSavedAddressOptions(Number(selectedAddress.id || 0));
            appliedSavedAddress = true;
        }
    }

    if (!appliedSavedAddress && marketAddressPayload) {
        shouldAutoPinFromMarketPayload = await applyMarketAddressPayloadToForm(marketAddressPayload);
    }

    await setCheckoutMode(activeCheckoutDeliveryOption, true).catch((error) => {
        console.error('Unable to sync checkout delivery option:', error);
    });
    syncDeliveryAddressField();

    if (typeof refreshDeliveryOverviewMap === 'function') {
        setTimeout(() => refreshDeliveryOverviewMap(), 250);
    }

    if (typeof window.initializeCheckoutMap === 'function') {
        window.initializeCheckoutMap();
    }
    if (typeof autoPinCheckoutMapFromHeaderPayload === 'function') {
        autoPinCheckoutMapFromHeaderPayload().catch((error) => {
            console.error('Unable to auto-pin from market payload:', error);
        });
    }

    // Auto-resolve coordinates if initial delivery address is present but coordinates are missing
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    if ((!latInput?.value || !lngInput?.value) && activeCheckoutDeliveryOption === 'delivery') {
        const addressText = (document.getElementById('delivery_address')?.value || document.getElementById('street_address')?.value || '').trim();
        if (addressText && typeof forwardGeocodeFromNominatim === 'function') {
            try {
                const geocoded = await forwardGeocodeFromNominatim(addressText);
                if (geocoded && geocoded.lat && geocoded.lng) {
                    if (latInput) latInput.value = String(geocoded.lat);
                    if (lngInput) lngInput.value = String(geocoded.lng);
                    if (typeof calculateDeliveryFee === 'function') {
                        calculateDeliveryFee(geocoded.lat, geocoded.lng);
                    }
                    if (typeof refreshDeliveryOverviewMap === 'function') {
                        refreshDeliveryOverviewMap({ customer_lat: geocoded.lat, customer_lng: geocoded.lng });
                    }
                }
            } catch (err) {
                console.warn('Initial address geocoding fallback error:', err);
            }
        }
    }
})();
});
</script>

<style>
.swal2-container {
    z-index: 99999999 !important;
}
.market-address-wrap, #marketAddressWrap {
    display: none !important;
}
</style>

<!-- Select Delivery Address Modal (Screenshot 1) -->
<div id="changeAddressModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10050; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #fff; border-radius: 20px; width: 100%; max-width: 540px; padding: 28px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #efddcd; padding-bottom: 14px;">
            <h3 style="font-size: 20px; font-weight: 800; color: #2a211d; margin: 0;"><i class="fas fa-location-dot" style="color: #b3261e;"></i> Select Delivery Address</h3>
            <button type="button" id="closeChangeAddressModalBtn" style="background: none; border: none; font-size: 20px; color: #7b6d64; cursor: pointer;"><i class="fas fa-times"></i></button>
        </div>

        <!-- Saved Addresses Section -->
        <div style="margin-bottom: 20px;">
            <label style="font-weight: 700; color: #2a211d; margin-bottom: 12px; display: block; font-size: 15px;">Saved Addresses</label>
            <div id="modalSavedAddressesList" style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($saved_addresses as $index => $saved_addr): ?>
                <?php
                $is_addr_default = ($default_saved_address_id > 0) ? ((int)$saved_addr['id'] === $default_saved_address_id) : ($index === 0);
                $addr_notes = !empty($saved_addr['notes']) ? htmlspecialchars($saved_addr['notes']) : 'none';
                ?>
                <div class="modal-saved-addr-card <?php echo $is_addr_default ? 'is-selected' : ''; ?>" 
                     data-address-id="<?php echo (int)$saved_addr['id']; ?>" 
                     data-street="<?php echo htmlspecialchars($saved_addr['street_address'] ?? ($saved_addr['full_address'] ?? '')); ?>"
                     data-city="<?php echo htmlspecialchars($saved_addr['city_name'] ?? ($saved_addr['full_address'] ?? '')); ?>"
                     data-full-address="<?php echo htmlspecialchars($saved_addr['full_address'] ?? ''); ?>"
                     data-latitude="<?php echo htmlspecialchars($saved_addr['latitude'] ?? ''); ?>"
                     data-longitude="<?php echo htmlspecialchars($saved_addr['longitude'] ?? ''); ?>"
                     data-notes="<?php echo htmlspecialchars($saved_addr['notes'] ?? ''); ?>"
                     style="border: 1px solid <?php echo $is_addr_default ? '#2a211d' : '#e8d4c3'; ?>; border-radius: 14px; padding: 16px 18px; cursor: pointer; transition: all 0.2s; background: #fff; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;">
                    <div style="display: flex; gap: 12px; align-items: flex-start; flex: 1;">
                        <!-- Radio Icon -->
                        <div class="addr-radio-btn" style="width: 20px; height: 20px; border-radius: 50%; border: 2px solid <?php echo $is_addr_default ? '#2a211d' : '#7b6d64'; ?>; display: flex; align-items: center; justify-content: center; margin-top: 2px; flex-shrink: 0; background: #fff;">
                            <div class="addr-radio-inner" style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $is_addr_default ? '#2a211d' : 'transparent'; ?>;"></div>
                        </div>
                        <!-- Details -->
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                <i class="fas fa-location-dot" style="color: #2a211d; font-size: 14px;"></i>
                                <span class="addr-street-line" style="font-size: 14px; font-weight: 700; color: #2a211d; line-height: 1.4;">
                                    <?php echo htmlspecialchars($saved_addr['street_address'] ?? ($saved_addr['full_address'] ?? '')); ?>
                                </span>
                            </div>
                            <div class="addr-city-line" style="font-size: 13px; color: #667085; padding-left: 20px;">
                                <?php echo htmlspecialchars($saved_addr['city_name'] ?? ($saved_addr['full_address'] ?? '')); ?>
                            </div>
                            <div class="addr-rider-note" style="font-size: 12px; color: #7b6d64; padding-left: 20px; margin-top: 4px;">
                                Note to rider: <?php echo $addr_notes; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Actions (Edit & Delete) -->
                    <div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;" onclick="event.stopPropagation();">
                        <button type="button" class="btn-edit-saved-addr" data-address-id="<?php echo (int)$saved_addr['id']; ?>" style="background: none; border: none; color: #2a211d; font-size: 16px; cursor: pointer; padding: 4px;" title="Edit address">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button type="button" class="btn-delete-saved-addr" data-address-id="<?php echo (int)$saved_addr['id']; ?>" style="background: none; border: none; color: #2a211d; font-size: 16px; cursor: pointer; padding: 4px;" title="Delete address">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($saved_addresses)): ?>
                <p style="font-size: 13px; color: #7b6d64; font-style: italic;">No saved addresses found in your account.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer Actions: + Add Address Button & Confirm Address Button -->
        <div style="border-top: 1px solid #efddcd; padding-top: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
            <button type="button" id="openAddAddressModalBtn" style="background: none; border: none; color: #2a211d; font-size: 15px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; padding: 4px 0;">
                <i class="fas fa-plus" style="font-size: 14px;"></i> Add address
            </button>
            <button type="button" id="confirmSelectedAddressBtn" style="background: #b3261e; color: #ffffff; font-weight: 800; font-size: 14px; letter-spacing: 0.5px; border: none; border-radius: 10px; padding: 12px 28px; cursor: pointer; transition: background 0.2s, transform 0.1s; box-shadow: 0 4px 12px rgba(179,38,30,0.25);">
                Confirm Address
            </button>
        </div>
    </div>
</div>

<!-- What's Your Exact Location? Map Modal (Screenshot 2) -->
<div id="exactLocationModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10060; align-items: center; justify-content: center; padding: 16px;">
    <div style="background: #fff; border-radius: 20px; width: 100%; max-width: 580px; padding: 26px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); max-height: 92vh; display: flex; flex-direction: column; position: relative;">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
            <div>
                <h3 style="font-size: 20px; font-weight: 800; color: #2a211d; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-location-dot" style="color: #b3261e;"></i> What’s your exact location?
                </h3>
                <p style="font-size: 13px; color: #667085; margin: 4px 0 0 0; line-height: 1.4;">
                    Providing your location enables more accurate search and delivery ETA, seamless order tracking and personalised recommendations.
                </p>
            </div>
            <button type="button" id="closeExactLocationModalBtn" style="background: none; border: none; font-size: 20px; color: #667085; cursor: pointer; padding: 4px;"><i class="fas fa-times"></i></button>
        </div>

        <!-- Address Search Input Box -->
        <div style="position: relative; margin-bottom: 16px;">
            <label style="font-size: 11px; font-weight: 700; color: #667085; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: block;">Enter your address</label>
            <div style="display: flex; align-items: center; border: 1px solid #efddcd; border-radius: 12px; padding: 4px 12px; background: #fff;">
                <input type="text" id="exactLocationInput" placeholder="Street, House No., Building, City" style="width: 100%; border: none; outline: none; padding: 10px 4px; font-size: 14px; color: #2a211d; background: transparent;">
                <button type="button" id="clearExactLocationInputBtn" style="background: none; border: none; color: #667085; cursor: pointer; font-size: 16px; padding: 4px;"><i class="fas fa-times-circle"></i></button>
            </div>
        </div>

        <!-- Interactive Map Canvas Container -->
        <div id="exactLocationMapShell" style="height: 280px; width: 100%; border-radius: 14px; overflow: hidden; position: relative; margin-bottom: 16px; background: #f3f4f6; border: 1px solid #efddcd;">
            <div id="exactLocationMapCanvas" style="width: 100%; height: 100%;"></div>
        </div>

        <!-- Footer SUBMIT Button -->
        <div style="display: flex; justify-content: flex-end; margin-top: auto; padding-top: 8px;">
            <button type="button" id="submitExactLocationBtn" style="background: #d92632; color: #fff; font-weight: 800; font-size: 14px; letter-spacing: 0.8px; text-transform: uppercase; border: none; border-radius: 10px; padding: 14px 38px; cursor: pointer; transition: background 0.2s, transform 0.1s; box-shadow: 0 4px 12px rgba(217,38,50,0.25);">
                SUBMIT
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function updateDisplayAddressText(streetText, cityText) {
        const streetEl = document.getElementById('displayStreetAddress');
        const cityEl = document.getElementById('displayCityAddress');
        if (streetEl) {
            streetEl.textContent = streetText || 'No address selected';
        }
        if (cityEl) {
            cityEl.textContent = cityText || '';
        }
    }

    const mainCard = document.getElementById('mainDeliveryAddressCard');
    const openModalBtn = document.getElementById('openChangeAddressModalBtn');
    const closeModalBtn = document.getElementById('closeChangeAddressModalBtn');
    const changeModal = document.getElementById('changeAddressModal');
    const openAddAddrBtn = document.getElementById('openAddAddressModalBtn');

    const exactModal = document.getElementById('exactLocationModal');
    const closeExactModalBtn = document.getElementById('closeExactLocationModalBtn');
    const exactInput = document.getElementById('exactLocationInput');
    const clearExactInputBtn = document.getElementById('clearExactLocationInputBtn');
    const submitExactBtn = document.getElementById('submitExactLocationBtn');

    let exactMap = null;
    let exactMarker = null;

    if (mainCard && changeModal) {
        mainCard.addEventListener('click', function(e) {
            if (e.target.id !== 'delivery_instructions') {
                changeModal.style.display = 'flex';
            }
        });
    }
    if (openModalBtn && changeModal) {
        openModalBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            changeModal.style.display = 'flex';
        });
    }
    if (closeModalBtn && changeModal) {
        closeModalBtn.addEventListener('click', function() {
            changeModal.style.display = 'none';
        });
    }

    let currentEditingAddressId = null;
    let exactSearchDebounceTimer = null;

    // Open Exact Location Map Modal (Screenshot 2)
    async function openExactMapModal(initialText = '', latVal = null, lngVal = null, editingId = null) {
        currentEditingAddressId = editingId ? String(editingId) : null;

        if (changeModal) changeModal.style.display = 'none';
        if (!exactModal) return;

        exactModal.style.display = 'flex';
        const navPayload = readMarketAddressPayloadFromStorage();
        const startText = initialText || (navPayload ? (navPayload.full_address || navPayload.street_address) : '');
        if (exactInput) exactInput.value = startText;

        let initLat = Number(latVal) || (navPayload ? Number(navPayload.latitude) : 0) || 14.3294;
        let initLng = Number(lngVal) || (navPayload ? Number(navPayload.longitude) : 0) || 120.9367;

        if (startText && (!latVal || !lngVal)) {
            const geocoded = await forwardGeocodeFromNominatim(startText);
            if (geocoded) {
                initLat = geocoded.lat;
                initLng = geocoded.lng;
            }
        }

        setTimeout(function() {
            if (!exactMap && window.L && document.getElementById('exactLocationMapCanvas')) {
                exactMap = L.map('exactLocationMapCanvas').setView([initLat, initLng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap contributors'
                }).addTo(exactMap);

                exactMarker = L.marker([initLat, initLng], { draggable: true }).addTo(exactMap);

                function reverseGeocodeExactPin(lat, lng) {
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data && data.display_name && exactInput) {
                                exactInput.value = data.display_name;
                            }
                        })
                        .catch(err => console.error('Nominatim reverse error:', err));
                }

                exactMarker.on('dragend', function() {
                    const pos = exactMarker.getLatLng();
                    if (exactMap) exactMap.panTo(pos);
                    reverseGeocodeExactPin(pos.lat, pos.lng);
                });

                exactMap.on('click', function(e) {
                    const pos = e.latlng;
                    if (exactMarker) exactMarker.setLatLng(pos);
                    if (exactMap) exactMap.panTo(pos);
                    reverseGeocodeExactPin(pos.lat, pos.lng);
                });

                exactMap.on('moveend', function() {
                    if (exactMarker && exactMap) {
                        const center = exactMap.getCenter();
                        exactMarker.setLatLng(center);
                        reverseGeocodeExactPin(center.lat, center.lng);
                    }
                });
            } else if (exactMap) {
                exactMap.invalidateSize();
                exactMap.setView([initLat, initLng], 16);
                if (exactMarker) exactMarker.setLatLng([initLat, initLng]);
            }
        }, 120);
    }

    // Live Geocode when user enters/edits address in input box
    if (exactInput) {
        exactInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (!query || query.length < 4) return;

            if (exactSearchDebounceTimer) clearTimeout(exactSearchDebounceTimer);
            exactSearchDebounceTimer = setTimeout(async function() {
                const result = await forwardGeocodeFromNominatim(query);
                if (result && Number.isFinite(result.lat) && Number.isFinite(result.lng)) {
                    if (exactMap && exactMarker) {
                        exactMap.setView([result.lat, result.lng], 16);
                        exactMarker.setLatLng([result.lat, result.lng]);
                    }
                }
            }, 600);
        });

        exactInput.addEventListener('keydown', async function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const query = this.value.trim();
                if (!query) return;
                const result = await forwardGeocodeFromNominatim(query);
                if (result && Number.isFinite(result.lat) && Number.isFinite(result.lng)) {
                    if (exactMap && exactMarker) {
                        exactMap.setView([result.lat, result.lng], 16);
                        exactMarker.setLatLng([result.lat, result.lng]);
                    }
                }
            }
        });
    }

    if (openAddAddrBtn) {
        openAddAddrBtn.addEventListener('click', function() {
            openExactMapModal('', null, null, null);
        });
    }
    if (closeExactModalBtn && exactModal) {
        closeExactModalBtn.addEventListener('click', function() {
            exactModal.style.display = 'none';
        });
    }
    if (clearExactInputBtn && exactInput) {
        clearExactInputBtn.addEventListener('click', function() {
            exactInput.value = '';
            exactInput.focus();
        });
    }

    function parseAddressCardDisplayTexts(selectedCard, savedRow) {
        const streetLineEl = selectedCard ? selectedCard.querySelector('.addr-street-line') : null;
        const cityLineEl = selectedCard ? selectedCard.querySelector('.addr-city-line') : null;

        let fullText = (
            savedRow?.full_address || 
            selectedCard?.getAttribute('data-full-address') || 
            (streetLineEl ? streetLineEl.textContent : '') || 
            ''
        ).trim();

        let streetText = '';
        let cityText = '';

        if (fullText) {
            const parts = fullText.split(',').map(p => p.trim()).filter(Boolean);
            if (parts.length >= 2) {
                const firstPartIsNumberOnly = parts[0].length <= 10 && (
                    /^\d/.test(parts[0]) || 
                    /^blk\s/i.test(parts[0]) || 
                    /^unit\s/i.test(parts[0]) || 
                    /^apt\s/i.test(parts[0])
                );
                
                if (firstPartIsNumberOnly && parts.length >= 3) {
                    streetText = parts.slice(0, 2).join(', ');
                    cityText = parts.slice(2).join(', ');
                } else {
                    streetText = parts[0];
                    cityText = parts.slice(1).join(', ');
                }
            } else {
                streetText = fullText;
                cityText = '';
            }
        } else {
            streetText = (savedRow?.street_address || selectedCard?.getAttribute('data-street') || (streetLineEl ? streetLineEl.textContent : 'Selected Address')).trim();
            cityText = (savedRow?.city_name || selectedCard?.getAttribute('data-city') || (cityLineEl ? cityLineEl.textContent : '')).trim();
            if (cityText === streetText) cityText = '';
        }

        return { streetText, cityText, fullText };
    }

    function selectAndApplyAddressCard(selectedCard) {
        if (!selectedCard) return;

        // 1. Highlight card visually
        document.querySelectorAll('.modal-saved-addr-card').forEach(c => {
            c.style.borderColor = '#e8d4c3';
            c.classList.remove('is-selected');
            const radioInner = c.querySelector('.addr-radio-inner');
            if (radioInner) radioInner.style.background = 'transparent';
            const radioBtn = c.querySelector('.addr-radio-btn');
            if (radioBtn) radioBtn.style.borderColor = '#7b6d64';
        });

        selectedCard.style.borderColor = '#2a211d';
        selectedCard.classList.add('is-selected');
        const selInner = selectedCard.querySelector('.addr-radio-inner');
        if (selInner) selInner.style.background = '#2a211d';
        const selRadio = selectedCard.querySelector('.addr-radio-btn');
        if (selRadio) selRadio.style.borderColor = '#2a211d';

        // 2. Extract address data
        const addrId = selectedCard.getAttribute('data-address-id');
        let savedRow = typeof findSavedAddressById === 'function' ? findSavedAddressById(addrId) : null;
        if (!savedRow) {
            savedRow = {
                id: addrId,
                street_address: selectedCard.getAttribute('data-street') || '',
                city_name: selectedCard.getAttribute('data-city') || '',
                full_address: selectedCard.getAttribute('data-full-address') || selectedCard.getAttribute('data-street') || '',
                latitude: selectedCard.getAttribute('data-latitude') || '',
                longitude: selectedCard.getAttribute('data-longitude') || '',
                notes: selectedCard.getAttribute('data-notes') || ''
            };
        }

        const { streetText, cityText, fullText } = parseAddressCardDisplayTexts(selectedCard, savedRow);

        // 3. Update Checkout UI Card text INSTANTLY in real-time
        updateDisplayAddressText(streetText, cityText);

        // 4. Update hidden inputs & Note to rider INSTANTLY
        const streetInput = document.getElementById('street_address');
        const deliveryAddressInput = document.getElementById('delivery_address');
        const instructionsInput = document.getElementById('delivery_instructions');
        const latitudeInput = document.getElementById('latitude');
        const longitudeInput = document.getElementById('longitude');
        const savedAddressIdInput = document.getElementById('saved_address_id');
        const riderNoteEl = selectedCard.querySelector('.addr-rider-note');

        const fullAddrVal = fullText || `${streetText}, ${cityText}`;
        if (streetInput) streetInput.value = streetText;
        if (deliveryAddressInput) deliveryAddressInput.value = fullAddrVal;
        if (savedAddressIdInput) savedAddressIdInput.value = String(savedRow.id || '');

        if (instructionsInput) {
            let noteValue = savedRow.notes || '';
            if (!noteValue && riderNoteEl) {
                const rawNoteText = riderNoteEl.textContent.replace(/^Note to rider:\s*/i, '').trim();
                if (rawNoteText && rawNoteText.toLowerCase() !== 'none') {
                    noteValue = rawNoteText;
                }
            }
            instructionsInput.value = (noteValue && noteValue.toLowerCase() !== 'none') ? noteValue : '';
        }

        // 5. Sync storage INSTANTLY
        let lat = parseFloat(savedRow.latitude || '');
        let lng = parseFloat(savedRow.longitude || '');
        const payload = {
            full_address: fullAddrVal,
            street_address: streetText,
            city_name: cityText,
            latitude: String(lat || ''),
            longitude: String(lng || '')
        };
        try {
            localStorage.setItem('market_address', JSON.stringify(payload));
            sessionStorage.setItem('market_address', JSON.stringify(payload));
        } catch(e) {}

        // 6. Coordinates & Delivery Fee Recalculation (async background helper)
        (async function processCoordinatesAndFee() {
            if ((Number.isNaN(lat) || Number.isNaN(lng) || (!lat && !lng)) && fullAddrVal) {
                if (typeof forwardGeocodeFromNominatim === 'function') {
                    const geocoded = await forwardGeocodeFromNominatim(fullAddrVal);
                    if (geocoded) {
                        lat = geocoded.lat;
                        lng = geocoded.lng;
                    }
                }
            }

            if (!Number.isNaN(lat) && !Number.isNaN(lng) && lat !== 0 && lng !== 0) {
                if (latitudeInput) latitudeInput.value = String(lat);
                if (longitudeInput) longitudeInput.value = String(lng);

                if (typeof map !== 'undefined' && map && typeof marker !== 'undefined' && marker) {
                    map.setView([lat, lng], 17);
                    marker.setLatLng([lat, lng]);
                }
                if (typeof calculateDeliveryFee === 'function') {
                    calculateDeliveryFee(lat, lng);
                }
            }
        })().catch(err => console.warn('Coord fee error:', err));

        // 7. PSGC background sync
        if (typeof applySavedAddressToForm === 'function') {
            applySavedAddressToForm(savedRow).catch(err => console.warn('Background PSGC sync:', err));
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bindSavedAddressCardEvents() {
        // Modal Saved Address Card Selection (Single Click & Double Click)
        document.querySelectorAll('.modal-saved-addr-card').forEach(card => {
            card.addEventListener('click', function() {
                selectAndApplyAddressCard(this);
            });

            card.addEventListener('dblclick', function() {
                selectAndApplyAddressCard(this);
                if (changeModal) changeModal.style.display = 'none';
            });
        });

        // Edit Saved Address (Pencil Icon)
        document.querySelectorAll('.btn-edit-saved-addr').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const addrId = this.getAttribute('data-address-id');
                const savedRow = findSavedAddressById(addrId);
                const streetText = savedRow ? (savedRow.full_address || savedRow.street_address) : '';
                const latVal = savedRow ? savedRow.latitude : null;
                const lngVal = savedRow ? savedRow.longitude : null;
                openExactMapModal(streetText, latVal, lngVal, addrId);
            });
        });

        // Delete Saved Address (Trash Icon)
        document.querySelectorAll('.btn-delete-saved-addr').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const card = this.closest('.modal-saved-addr-card');
                const addrId = this.getAttribute('data-address-id');

                Swal.fire({
                    title: 'Delete Address?',
                    text: 'Are you sure you want to remove this saved address?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#b3261e',
                    cancelButtonColor: '#667085',
                    confirmButtonText: 'Yes, delete'
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (card) card.remove();

                        // Send AJAX delete request to database
                        const delBody = new URLSearchParams();
                        delBody.append('address_action', 'delete');
                        delBody.append('address_id', String(addrId));
                        fetch('checkout.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: delBody })
                            .catch(err => console.error('Delete address AJAX error:', err));

                        Swal.fire({ icon: 'success', title: 'Address Removed', timer: 1200, showConfirmButton: false });
                    }
                });
            });
        });
    }

    function renderModalSavedAddressesFromData(addresses, selectedId) {
        const container = document.getElementById('modalSavedAddressesList');
        if (!container || !Array.isArray(addresses)) return;

        let html = '';
        addresses.forEach((saved_addr, index) => {
            const isSelected = selectedId ? (Number(saved_addr.id) === Number(selectedId)) : (index === 0);
            const addrId = saved_addr.id;
            const street = saved_addr.street_address || saved_addr.full_address || '';
            const city = saved_addr.city_name || saved_addr.full_address || '';
            const full = saved_addr.full_address || '';
            const lat = saved_addr.latitude || '';
            const lng = saved_addr.longitude || '';
            const notes = saved_addr.notes || 'none';

            html += `
                <div class="modal-saved-addr-card ${isSelected ? 'is-selected' : ''}" 
                     data-address-id="${addrId}" 
                     data-street="${escapeHtml(street)}"
                     data-city="${escapeHtml(city)}"
                     data-full-address="${escapeHtml(full)}"
                     data-latitude="${escapeHtml(lat)}"
                     data-longitude="${escapeHtml(lng)}"
                     data-notes="${escapeHtml(notes)}"
                     style="border: 1px solid ${isSelected ? '#2a211d' : '#e8d4c3'}; border-radius: 14px; padding: 16px 18px; cursor: pointer; transition: all 0.2s; background: #fff; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;">
                    <div style="display: flex; gap: 12px; align-items: flex-start; flex: 1;">
                        <div class="addr-radio-btn" style="width: 20px; height: 20px; border-radius: 50%; border: 2px solid ${isSelected ? '#2a211d' : '#7b6d64'}; display: flex; align-items: center; justify-content: center; margin-top: 2px; flex-shrink: 0; background: #fff;">
                            <div class="addr-radio-inner" style="width: 10px; height: 10px; border-radius: 50%; background: ${isSelected ? '#2a211d' : 'transparent'};"></div>
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                <i class="fas fa-location-dot" style="color: #2a211d; font-size: 14px;"></i>
                                <span class="addr-street-line" style="font-size: 14px; font-weight: 700; color: #2a211d; line-height: 1.4;">
                                    ${escapeHtml(street)}
                                </span>
                            </div>
                            <div class="addr-city-line" style="font-size: 13px; color: #667085; padding-left: 20px;">
                                ${escapeHtml(city)}
                            </div>
                            <div class="addr-rider-note" style="font-size: 12px; color: #7b6d64; padding-left: 20px; margin-top: 4px;">
                                Note to rider: ${escapeHtml(notes)}
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;" onclick="event.stopPropagation();">
                        <button type="button" class="btn-edit-saved-addr" data-address-id="${addrId}" style="background: none; border: none; color: #2a211d; font-size: 16px; cursor: pointer; padding: 4px;" title="Edit address">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button type="button" class="btn-delete-saved-addr" data-address-id="${addrId}" style="background: none; border: none; color: #2a211d; font-size: 16px; cursor: pointer; padding: 4px;" title="Delete address">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        bindSavedAddressCardEvents();
    }

    // Initial binding of modal address cards
    bindSavedAddressCardEvents();

    // Confirm Selected Address Button Click
    const confirmAddrBtn = document.getElementById('confirmSelectedAddressBtn');
    if (confirmAddrBtn) {
        confirmAddrBtn.addEventListener('click', function() {
            const selectedCard = document.querySelector('.modal-saved-addr-card.is-selected') 
                              || document.querySelector('.modal-saved-addr-card');
            if (selectedCard) {
                selectAndApplyAddressCard(selectedCard);
            }
            if (changeModal) changeModal.style.display = 'none';
        });
    }

    // SUBMIT Exact Location Map Modal (Screenshot 2)
    if (submitExactBtn) {
        submitExactBtn.addEventListener('click', async function() {
            const queryText = (exactInput?.value || '').trim();
            let lat = 14.3294, lng = 120.9367;
            if (exactMap) {
                const center = exactMap.getCenter();
                lat = center.lat;
                lng = center.lng;
            }

            if (queryText) {
                const streetInput = document.getElementById('street_address');
                if (streetInput) streetInput.value = queryText;

                const parts = queryText.split(',');
                const streetPart = parts[0].trim();
                const cityPart = parts.slice(1).join(',').trim() || 'Dasmariñas';
                updateDisplayAddressText(streetPart, cityPart);
                syncDeliveryAddressField();

                if (!exactMap && queryText) {
                    const fallback = await forwardGeocodeFromNominatim(queryText);
                    if (fallback) {
                        lat = fallback.lat;
                        lng = fallback.lng;
                    }
                }

                const latInput = document.getElementById('latitude');
                const lngInput = document.getElementById('longitude');
                if (latInput) latInput.value = String(lat);
                if (lngInput) lngInput.value = String(lng);

                // Automatically save or update address in database
                const saveBody = new URLSearchParams();
                if (currentEditingAddressId) {
                    saveBody.append('address_id', String(currentEditingAddressId));
                }
                saveBody.append('label', 'Saved Location');
                saveBody.append('street_address', streetPart);
                saveBody.append('city_name', cityPart);
                saveBody.append('full_address', queryText);
                saveBody.append('latitude', String(lat));
                saveBody.append('longitude', String(lng));
                saveBody.append('is_default', '1');

                fetch('api/save_user_address.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: saveBody
                }).then(r => r.json()).then(data => {
                    if (data.success && Array.isArray(data.addresses)) {
                        savedAddressesData = data.addresses;
                        renderModalSavedAddressesFromData(data.addresses, data.saved_address_id || currentEditingAddressId);

                        const targetCard = document.querySelector(`.modal-saved-addr-card[data-address-id="${data.saved_address_id || currentEditingAddressId}"]`);
                        if (targetCard) {
                            selectAndApplyAddressCard(targetCard);
                        }
                    }
                }).catch(err => console.error('Auto-save address error:', err));

                await calculateDeliveryFee(lat, lng);
                if (typeof refreshDeliveryOverviewMap === 'function') {
                    refreshDeliveryOverviewMap({ customer_lat: lat, customer_lng: lng });
                }
            }

            currentEditingAddressId = null;
            if (exactModal) exactModal.style.display = 'none';
            if (changeModal) changeModal.style.display = 'none';
        });
    }

    // Real-time synchronization when user saves a location from the Header Navbar popover
    window.addEventListener('marketAddressUpdated', function (e) {
        const payload = e.detail;
        if (!payload) return;

        const street = (payload.street_address || payload.street || payload.full_address || '').trim();
        const city = (payload.city_name || payload.city || 'Cavite').trim();
        const full = (payload.full_address || `${street}, ${city}`).trim();
        const lat = parseFloat(payload.latitude || '');
        const lng = parseFloat(payload.longitude || '');
        const addrId = payload.address_id || '';

        // 1. Update Checkout UI Address Card Text
        const parts = full.split(',').map(p => p.trim()).filter(Boolean);
        let displayStreet = street;
        let displayCity = city;
        if (parts.length >= 2) {
            if (parts.length >= 3 && (parts[0].length <= 10 || /^\d/.test(parts[0]) || /^blk\s/i.test(parts[0]))) {
                displayStreet = parts.slice(0, 2).join(', ');
                displayCity = parts.slice(2).join(', ');
            } else {
                displayStreet = parts[0];
                displayCity = parts.slice(1).join(', ');
            }
        }
        updateDisplayAddressText(displayStreet, displayCity);

        // 2. Update hidden form fields
        const streetInput = document.getElementById('street_address');
        const deliveryAddressInput = document.getElementById('delivery_address');
        const latitudeInput = document.getElementById('latitude');
        const longitudeInput = document.getElementById('longitude');
        const savedAddressIdInput = document.getElementById('saved_address_id');

        if (streetInput) streetInput.value = displayStreet;
        if (deliveryAddressInput) deliveryAddressInput.value = full;
        if (savedAddressIdInput && addrId) savedAddressIdInput.value = String(addrId);

        if (!Number.isNaN(lat) && !Number.isNaN(lng) && lat !== 0 && lng !== 0) {
            if (latitudeInput) latitudeInput.value = String(lat);
            if (longitudeInput) longitudeInput.value = String(lng);

            if (typeof map !== 'undefined' && map && typeof marker !== 'undefined' && marker) {
                map.setView([lat, lng], 17);
                marker.setLatLng([lat, lng]);
            }
            if (typeof calculateDeliveryFee === 'function') {
                calculateDeliveryFee(lat, lng);
            }
        }

        // 3. If fresh address list was returned, update modalSavedAddressesList and savedAddressesData
        if (Array.isArray(payload.addresses) && payload.addresses.length > 0) {
            savedAddressesData = payload.addresses;
            renderModalSavedAddressesFromData(payload.addresses, addrId);
        }
    });

    // Update display address on initial load
    setTimeout(function() {
        const selectedSavedCard = document.querySelector('.modal-saved-addr-card.is-selected') || document.querySelector('.modal-saved-addr-card');
        const streetInput = document.getElementById('street_address');
        const hiddenAddr = document.getElementById('delivery_address');
        const navPayload = readMarketAddressPayloadFromStorage();

        if (selectedSavedCard) {
            const addrId = selectedSavedCard.getAttribute('data-address-id');
            const savedRow = typeof findSavedAddressById === 'function' ? findSavedAddressById(addrId) : null;
            const { streetText, cityText } = parseAddressCardDisplayTexts(selectedSavedCard, savedRow);
            if (streetText) {
                updateDisplayAddressText(streetText, cityText);
                return;
            }
        }

        if (streetInput && streetInput.value.trim()) {
            const parts = streetInput.value.split(',');
            updateDisplayAddressText(parts[0].trim(), parts.slice(1).join(',').trim());
        } else if (navPayload) {
            updateDisplayAddressText(navPayload.street_address || navPayload.full_address, navPayload.city || '');
        } else if (hiddenAddr && hiddenAddr.value.trim()) {
            const parts = hiddenAddr.value.split(',');
            updateDisplayAddressText(parts[0].trim(), parts.slice(1).join(',').trim());
        }
    }, 400);
});
</script>

<?php include 'includes/footer.php'; ?>



