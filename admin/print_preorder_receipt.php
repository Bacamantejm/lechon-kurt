<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';
require_once '../includes/sales_receipt_helper.php';

checkAdminAccess();
requirePermission('preorders.view');

$preOrderId = (int)($_GET['id'] ?? 0);
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$sellerScopeId = null;
if (function_exists('isApprovedFranchiseSellerAccount') && isApprovedFranchiseSellerAccount($conn, $currentUserId)) {
    $sellerScopeId = (int)getFranchiseSellerScopeOwnerId($conn, $currentUserId);
}

$receipt = srFetchPreOrderReceiptData($conn, $preOrderId, $sellerScopeId);
if ($receipt === []) {
    http_response_code(404);
    exit('Pre-order receipt not found.');
}

echo srRenderReceiptPage($receipt, $autoPrint);
