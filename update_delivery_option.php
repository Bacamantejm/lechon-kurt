<?php
session_start();

header('Content-Type: application/json');

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
$stores = $_SESSION['store_locations'] ?? [];
$preferred_owner_user_id = (int)($_SESSION['storefront_seller_id'] ?? 0);

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
    'estimated_delivery_text' => '',
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

    echo json_encode($response);
    exit;
}

unset($_SESSION['pickup_location']);

$latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
$longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

if ($latitude !== null && $longitude !== null) {
    $quote = dpBuildDeliveryQuote($stores, $latitude, $longitude, $preferred_owner_user_id);
    if (!empty($quote['success'])) {
        $_SESSION['current_delivery_quote'] = $quote;
        $response['delivery_fee'] = (float)($quote['fee'] ?? 0);
        $response['delivery_details'] = (string)($quote['delivery_details'] ?? 'Delivery fee calculated from the nearest store.');
        $response['distance_km'] = (float)($quote['distance_km'] ?? 0);
        $response['nearest_store_id'] = (int)($quote['nearest_store_id'] ?? 0);
        $response['nearest_store_name'] = (string)($quote['nearest_store_name'] ?? '');
        $response['estimated_delivery_text'] = (string)($quote['estimated_delivery_text'] ?? '');
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

