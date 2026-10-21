<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

@error_reporting(0);
@ini_set('display_errors', '0');

if (ob_get_length()) {
    @ob_clean();
}
@ob_start();

function sendVoucherJsonResponse($data) {
    if (ob_get_length()) {
        @ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendVoucherJsonResponse(['success' => false, 'message' => 'Invalid request method.']);
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner_voucher_helper.php';

pvEnsureVoucherSchema($conn);

$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['customer_id'] ?? 0));
$action = strtolower(trim((string)($_POST['action'] ?? 'apply')));

$subtotal = 0.0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
}
$subtotal = round($subtotal, 2);
$vat_amount = round($subtotal * 0.12, 2);

if ($action === 'remove') {
    pvClearAppliedVoucherSession();
    unset($_SESSION['pending_welcome_voucher']);
    sendVoucherJsonResponse([
        'success' => true,
        'message' => 'Voucher removed.',
        'voucher_applied' => false,
        'voucher_id' => 0,
        'voucher_code' => '',
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
}

if ($action === 'claim') {
    $raw_code = pvNormalizeVoucherCode($_POST['code'] ?? '');
    if ($raw_code === '') {
        sendVoucherJsonResponse(['success' => false, 'message' => 'Please provide a valid voucher code.']);
    }

    $v_stmt = mysqli_prepare($conn, "SELECT pv.*, COALESCE(NULLIF(TRIM(u.business_name), ''), u.full_name, 'Platform') AS store_name FROM partner_vouchers pv LEFT JOIN users u ON pv.seller_id = u.id WHERE pv.code = ? AND pv.is_active = 1 LIMIT 1");
    if ($v_stmt) {
        mysqli_stmt_bind_param($v_stmt, "s", $raw_code);
        mysqli_stmt_execute($v_stmt);
        $v_res = mysqli_stmt_get_result($v_stmt);
        $voucher_data = $v_res ? mysqli_fetch_assoc($v_res) : null;
        mysqli_stmt_close($v_stmt);

        if (!$voucher_data) {
            sendVoucherJsonResponse(['success' => false, 'message' => "Promo code {$raw_code} is not active or could not be found."]);
        }

        $_SESSION['pending_welcome_voucher'] = $raw_code;

        if (!empty($_SESSION['cart'])) {
            $apply_result = pvApplyVoucherCodeForSession($conn, $user_id, $raw_code, $_SESSION['cart']);
            if (!empty($apply_result['success'])) {
                unset($_SESSION['pending_welcome_voucher']);
                sendVoucherJsonResponse(array_merge([
                    'success' => true,
                    'message' => "Voucher {$raw_code} claimed and applied to your current order!"
                ], $apply_result));
            }
        }

        $min_spend = (float)($voucher_data['min_order_amount'] ?? 0);
        $spend_note = $min_spend > 0 ? (' (Min. spend PHP ' . number_format($min_spend, 2) . ')') : '';
        sendVoucherJsonResponse([
            'success' => true,
            'message' => "Voucher {$raw_code} claimed!{$spend_note} It will automatically apply at checkout.",
            'voucher_code' => $raw_code
        ]);
    }

    sendVoucherJsonResponse(['success' => false, 'message' => 'Unable to claim voucher right now.']);
}

$raw_code = (string)($_POST['code'] ?? '');
$apply_result = pvApplyVoucherCodeForSession($conn, $user_id, $raw_code, $_SESSION['cart']);
if (empty($apply_result['success'])) {
    sendVoucherJsonResponse([
        'success' => false,
        'message' => $apply_result['message'] ?? 'Voucher could not be applied.',
        'voucher_applied' => false,
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
}

$state = pvResolveAppliedVoucherState($conn, $user_id, $_SESSION['cart']);
if (empty($state['applied'])) {
    sendVoucherJsonResponse([
        'success' => false,
        'message' => $state['message'] ?: 'Voucher could not be applied.',
        'voucher_applied' => false,
        'discount_amount' => 0,
        'subtotal' => $subtotal,
        'vat_amount' => $vat_amount
    ]);
}

sendVoucherJsonResponse([
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

