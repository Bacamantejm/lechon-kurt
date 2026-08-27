<?php
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner_voucher_helper.php';

pvEnsureVoucherSchema($conn);

$user_id = (int)$_SESSION['user_id'];
$action = strtolower(trim((string)($_POST['action'] ?? 'apply')));

$subtotal = 0.0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
}
$subtotal = round($subtotal, 2);
$vat_amount = round($subtotal * 0.12, 2);

if ($action === 'remove') {
    pvClearAppliedVoucherSession();
    echo json_encode([
        'success' => true,
        'message' => 'Voucher removed.',
        'voucher_applied' => false,
        'voucher_id' => 0,
        'voucher_code' => '',
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
    exit;
}

$raw_code = (string)($_POST['code'] ?? '');
$apply_result = pvApplyVoucherCodeForSession($conn, $user_id, $raw_code, $_SESSION['cart']);
if (empty($apply_result['success'])) {
    echo json_encode([
        'success' => false,
        'message' => $apply_result['message'] ?? 'Voucher could not be applied.',
        'voucher_applied' => false,
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
    exit;
}

$state = pvResolveAppliedVoucherState($conn, $user_id, $_SESSION['cart']);
if (empty($state['applied'])) {
    echo json_encode([
        'success' => false,
        'message' => $state['message'] ?: 'Voucher could not be applied.',
        'voucher_applied' => false,
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Voucher applied successfully.',
    'voucher_applied' => true,
    'voucher_id' => (int)$state['voucher_id'],
    'voucher_code' => (string)$state['voucher_code'],
    'voucher_name' => (string)($state['voucher_name'] ?? ''),
    'seller_id' => (int)($state['seller_id'] ?? 0),
    'store_name' => (string)($state['store_name'] ?? ''),
    'is_store_exclusive' => !empty($state['is_store_exclusive']),
    'scope_label' => (string)($state['scope_label'] ?? ''),
    'discount_amount' => (float)$state['discount_amount'],
    'subtotal' => $subtotal,
    'vat_amount' => $vat_amount
]);

