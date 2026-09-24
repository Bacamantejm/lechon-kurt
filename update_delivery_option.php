<?php
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner_voucher_helper.php';
require_once __DIR__ . '/includes/delivery_pricing_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$delivery_option = strtolower(trim((string)($_POST['delivery_option'] ?? 'pickup')));
if (!in_array($delivery_option, ['pickup', 'delivery'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid delivery option.']);
    exit;
}

$_SESSION['delivery_option'] = $delivery_option;
$_SESSION['delivery_option_explicit'] = true;
$stores = !empty($_SESSION['store_locations'])
    ? $_SESSION['store_locations']
    : dpFetchActiveStoresFromDb($conn);

$preferred_owner_user_id = (int)($_SESSION['storefront_seller_id'] ?? 0);
if ($preferred_owner_user_id <= 0 && !empty($_SESSION['cart']) && function_exists('pvGetCheckoutTenantScope')) {
    $cart_scope = pvGetCheckoutTenantScope($conn, $_SESSION['cart']);
    if (!empty($cart_scope['is_valid']) && !empty($cart_scope['seller_id'])) {
        $preferred_owner_user_id = (int)$cart_scope['seller_id'];
    }
}

$response = [
    'success' => true,
    'delivery_option' => $delivery_option,
    'delivery_fee' => 0,
    'delivery_details' => '',
    'pickup_location' => null,
    'delivery_location' => null,
    'distance_km' => null,
    'nearest_store_id' => null,
    'nearest_store_name' => null,
    'nearest_store_address' => '',
    'estimated_delivery_text' => '',
    'customer_lat' => null,
    'customer_lng' => null,
];

if ($delivery_option === 'pickup') {
    $pickup_location_id = (int)($_POST['pickup_location'] ?? ($_SESSION['pickup_location'] ?? 1));
    $_SESSION['pickup_location'] = $pickup_location_id;
    unset($_SESSION['delivery_location'], $_SESSION['current_delivery_quote']);

    $selected_store = null;
    foreach (dpNormalizeStoreRows($stores) as $store) {
        if ((int)($store['id'] ?? 0) === $pickup_location_id) {
            $selected_store = $store;
            break;
        }
    }

    $response['pickup_location'] = $pickup_location_id;
    $response['delivery_details'] = 'Pickup from: ' . ($selected_store['name'] ?? 'Main Store');
    $response['nearest_store_address'] = (string)($selected_store['address'] ?? '');

    echo json_encode($response);
    exit;
}

unset($_SESSION['pickup_location']);

$rawLat = $_POST['latitude'] ?? null;
$rawLng = $_POST['longitude'] ?? null;
$coords = dpSanitizeCoordinates($rawLat, $rawLng);

$delivery_address = trim((string)($_POST['delivery_address'] ?? $_POST['address'] ?? ''));
if ($coords === null && $delivery_address !== '') {
    $coords = dpResolveCoordinatesFromAddress($delivery_address);
}

if ($coords !== null) {
    $latitude = (float)($coords['lat'] ?? $coords['latitude'] ?? 0);
    $longitude = (float)($coords['lng'] ?? $coords['longitude'] ?? 0);
    $quote = dpBuildDeliveryQuote($stores, $latitude, $longitude, $preferred_owner_user_id, [], $delivery_address);

    if (!empty($quote['success'])) {
        $_SESSION['current_delivery_quote'] = $quote;
        $resolved_store_id = (int)($quote['nearest_store_id'] ?? 1);
        $_SESSION['pickup_location'] = $resolved_store_id;
        $response['pickup_location'] = $resolved_store_id;
        $response['delivery_fee'] = (float)($quote['fee'] ?? 0);
        $response['delivery_details'] = (string)($quote['delivery_details'] ?? 'Delivery fee calculated from the nearest store.');
        $response['distance_km'] = (float)($quote['distance_km'] ?? 0);
        $response['nearest_store_id'] = $resolved_store_id;
        $response['nearest_store_name'] = (string)($quote['nearest_store_name'] ?? '');
        $response['nearest_store_address'] = (string)($quote['nearest_store_address'] ?? '');
        $response['estimated_delivery_text'] = (string)($quote['estimated_delivery_text'] ?? '');
        $response['customer_lat'] = $latitude;
        $response['customer_lng'] = $longitude;
    } else {
        unset($_SESSION['current_delivery_quote']);
        $response['success'] = false;
        $response['message'] = (string)($quote['message'] ?? 'Unable to calculate delivery fee.');
    }
} else {
    unset($_SESSION['current_delivery_quote']);
    $response['delivery_details'] = 'Pin your exact location to calculate the fee from the nearest store.';
}

echo json_encode($response);


