<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';

function billingPdfEscapeText(string $text): string
{
    return str_replace(
        ['\\', '(', ')', "\r", "\n", "\t"],
        ['\\\\', '\\(', '\\)', '', ' ', ' '],
        $text
    );
}

function buildSimplePdfDocument(array $lines): string
{
    $content = "BT\n/F1 11 Tf\n";
    $y = 790;
    foreach ($lines as $line) {
        $safe = billingPdfEscapeText((string)$line);
        $content .= "1 0 0 1 48 {$y} Tm ({$safe}) Tj\n";
        $y -= 16;
        if ($y < 48) {
            break;
        }
    }
    $content .= "ET";

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $objects[] = "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

checkAdminAccess();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_user_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

if (!$is_super_admin_user && $seller_scope_id === null) {
    denyAdminAccess('Access denied: You do not have permission to download billing invoices.');
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$service = new PlatformMonetizationService($conn);
$service->ensureReady($current_user_id);
$document = $service->getInvoiceDocumentData($invoice_id, $is_super_admin_user ? null : (int)$seller_scope_id);
if ($invoice_id <= 0 || $document === []) {
    denyAdminAccess('Billing invoice not found or inaccessible.');
}

$invoice = $document['invoice'] ?? [];
$line_items = $document['line_items'] ?? [];
$lines = [
    'Lechon Delights Platform Billing Invoice',
    'Invoice Number: ' . (string)($invoice['invoice_number'] ?? ('#' . $invoice_id)),
    'Business: ' . (string)($invoice['business_name'] ?? 'Partner Shop'),
    'Email: ' . (string)($invoice['email'] ?? '-'),
    'Status: ' . ucfirst(str_replace('_', ' ', (string)($invoice['invoice_status'] ?? 'issued'))),
    'Billing Period: ' . date('M d, Y', strtotime((string)($invoice['period_start'] ?? 'now'))) . ' to ' . date('M d, Y', strtotime((string)($invoice['period_end'] ?? 'now'))),
    'Issued At: ' . (!empty($invoice['issued_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['issued_at'])) : '-'),
    'Due At: ' . (!empty($invoice['due_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['due_at'])) : '-'),
    ' '
];

foreach ($line_items as $item) {
    $lines[] = '- ' . (string)($item['label'] ?? 'Line item') . ': PHP ' . number_format((float)($item['amount'] ?? 0), 2);
}

$lines[] = ' ';
$lines[] = 'Subtotal: PHP ' . number_format((float)($invoice['subtotal_amount'] ?? 0), 2);
$lines[] = 'Tax: PHP ' . number_format((float)($invoice['tax_amount'] ?? 0), 2);
$lines[] = 'Total Amount: PHP ' . number_format((float)($invoice['total_amount'] ?? 0), 2);
$lines[] = 'Payment Reference: ' . ((string)($invoice['payment_reference'] ?? '') !== '' ? (string)$invoice['payment_reference'] : '-');
$lines[] = 'Payment Channel: ' . ((string)($invoice['payment_channel'] ?? '') !== '' ? (string)$invoice['payment_channel'] : '-');
$lines[] = 'Paid At: ' . (!empty($invoice['paid_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['paid_at'])) : '-');
$lines[] = ' ';
$lines[] = 'Notes: ' . trim((string)($invoice['notes'] ?? ''));

$pdf = buildSimplePdfDocument($lines);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($invoice['invoice_number'] ?? ('invoice_' . $invoice_id))) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
