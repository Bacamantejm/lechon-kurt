<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sales_receipt_helper.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['login_redirect'] = 'my_orders.php';
    header('Location: login.php');
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: my_orders.php');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load receipt.');
}
mysqli_stmt_bind_param($stmt, 'ii', $orderId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$orderRow = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$orderRow) {
    http_response_code(404);
    exit('Receipt not found or inaccessible.');
}

$receipt = srFetchOrderReceiptData($conn, $orderId);
if ($receipt === []) {
    http_response_code(404);
    exit('Receipt details are not available for this order.');
}
$receipt['download_pdf_url'] = 'receipt_pdf.php?order_id=' . $orderId;
$receipt['back_url'] = 'my_orders.php';

echo srRenderReceiptPage($receipt, isset($_GET['print']));
exit;
