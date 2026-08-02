<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sales_receipt_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$preOrderId = (int)($_GET['id'] ?? $_GET['pre_order_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($preOrderId <= 0) {
    http_response_code(400);
    exit('Invalid receipt request.');
}

$stmt = mysqli_prepare($conn, 'SELECT id FROM pre_orders WHERE id = ? AND user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare receipt download.');
}
mysqli_stmt_bind_param($stmt, 'ii', $preOrderId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$preOrderRow = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$preOrderRow) {
    http_response_code(404);
    exit('Receipt not found or inaccessible.');
}

$receipt = srFetchPreOrderReceiptData($conn, $preOrderId);
if ($receipt === []) {
    http_response_code(404);
    exit('Receipt details are not available for this pre-order.');
}

$pdf = srBuildSimplePdfDocument(srBuildReceiptPdfLines($receipt));
$filenameBase = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($receipt['receipt_no'] ?? ('preorder_receipt_' . $preOrderId)));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
