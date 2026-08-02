<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sales_receipt_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    exit('Invalid receipt request.');
}

$stmt = mysqli_prepare($conn, 'SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare receipt download.');
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

$pdf = srBuildSimplePdfDocument(srBuildReceiptPdfLines($receipt));
$filenameBase = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($receipt['receipt_no'] ?? ('receipt_' . $orderId)));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
