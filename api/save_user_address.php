<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/checkout_address_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Please log in to save addresses to your account.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);
$data = is_array($json_data) ? $json_data : $_POST;

$full_address = trim((string)($data['full_address'] ?? $data['street_address'] ?? ''));
$street_address = trim((string)($data['street_address'] ?? ''));
$city_name = trim((string)($data['city_name'] ?? $data['city'] ?? ''));
$latitude = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : '';
$longitude = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : '';

if ($full_address === '' && $street_address === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Address is required.'
    ]);
    exit;
}

// Fetch user profile info if not provided
$contact_name = trim((string)($data['contact_name'] ?? ($_SESSION['full_name'] ?? '')));
$contact_phone = trim((string)($data['contact_phone'] ?? ''));

if ($contact_phone === '' && $user_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT phone FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && $u = mysqli_fetch_assoc($res)) {
            $contact_phone = (string)($u['phone'] ?? '');
        }
        mysqli_stmt_close($stmt);
    }
}

$address_id = (int)($data['address_id'] ?? 0);

$address_payload = [
    'address_id' => $address_id,
    'label' => trim((string)($data['label'] ?? 'Saved Location')),
    'contact_name' => $contact_name,
    'contact_phone' => $contact_phone,
    'street_address' => $street_address ?: strtok($full_address, ','),
    'city_name' => $city_name ?: 'Cavite',
    'full_address' => $full_address ?: $street_address,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'is_default' => 1
];

$save_result = caSaveUserSavedAddress($conn, $user_id, $address_payload, true);
if (!($save_result['success'] ?? false)) {
    echo json_encode([
        'success' => false,
        'message' => (string)($save_result['message'] ?? 'Unable to save address.')
    ]);
    exit;
}

// Update session context
$_SESSION['delivery_location'] = $address_payload['full_address'];
$_SESSION['delivery_address'] = $address_payload['full_address'];
if ($latitude !== '' && $longitude !== '') {
    $_SESSION['latitude'] = $latitude;
    $_SESSION['longitude'] = $longitude;
}

$fresh_addresses = caFetchUserSavedAddresses($conn, $user_id);

echo json_encode([
    'success' => true,
    'message' => 'Address successfully saved to your delivery address book.',
    'saved_address_id' => (int)($save_result['address_id'] ?? 0),
    'address' => $address_payload,
    'addresses' => $fresh_addresses
]);
