<?php

require_once __DIR__ . '/partner_receipt_settings_helper.php';

if (!function_exists('srVatRate')) {
    function srVatRate(): float
    {
        return 0.12;
    }
}

if (!function_exists('srMoney')) {
    function srMoney(float $amount): string
    {
        return 'PHP ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('srClaimCode')) {
    function srClaimCode(string $value): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $value));
        if ($clean === '') {
            return 'N/A';
        }
        return substr($clean, -8);
    }
}

if (!function_exists('srResolveVatBreakdown')) {
    function srResolveVatBreakdown(float $subtotal, float $deliveryFee, float $storedTotal, float $voucherDiscount = 0.00): array
    {
        $subtotal = round(max(0, $subtotal), 2);
        $deliveryFee = round(max(0, $deliveryFee), 2);
        $storedTotal = round(max(0, $storedTotal), 2);
        $voucherDiscount = round(max(0, $voucherDiscount), 2);

        $defaultVat = round($subtotal * srVatRate(), 2);
        $expectedTotal = round(max(0, $subtotal + $deliveryFee + $defaultVat - $voucherDiscount), 2);
        $vatAmount = abs($storedTotal - $expectedTotal) < 0.03
            ? $defaultVat
            : round(max(0, $storedTotal + $voucherDiscount - $subtotal - $deliveryFee), 2);

        if ($vatAmount <= 0 && $subtotal > 0) {
            $vatAmount = $defaultVat;
        }

        $totalAmount = $storedTotal > 0 ? $storedTotal : round(max(0, $subtotal + $deliveryFee + $vatAmount - $voucherDiscount), 2);

        return [
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'voucher_discount' => $voucherDiscount,
            'vatable_sales' => $subtotal,
            'vat_exempt_sales' => 0.00,
            'zero_rated_sales' => 0.00,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ];
    }
}

if (!function_exists('srFetchBusinessProfile')) {
    function srFetchBusinessProfile(mysqli $conn, int $userId = 0): array
    {
        $profile = [
            'business_name' => 'Lechon Delights',
            'branch_name' => '',
            'address' => 'Business address not set',
            'phone' => '',
            'tax_id' => '',
            'business_style' => '',
            'permit_no' => '',
            'ptu_no' => '',
            'accreditation_no' => '',
            'serial_no' => '',
            'footer_text' => '',
            'business_registration' => '',
            'email' => '',
        ];

        if ($userId <= 0) {
            return $profile;
        }

        $query = "SELECT
                    COALESCE(NULLIF(TRIM(business_name), ''), NULLIF(TRIM(full_name), ''), 'Lechon Delights') AS business_name,
                    COALESCE(NULLIF(TRIM(address), ''), 'Business address not set') AS address,
                    COALESCE(NULLIF(TRIM(phone), ''), '') AS phone,
                    COALESCE(NULLIF(TRIM(tax_id), ''), '') AS tax_id,
                    COALESCE(NULLIF(TRIM(business_registration), ''), '') AS business_registration,
                    COALESCE(NULLIF(TRIM(email), ''), '') AS email
                  FROM users
                  WHERE id = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return $profile;
        }

        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($row) {
            $profile = array_merge($profile, $row);
        }

        $receiptSettings = prsFetchReceiptSettings($conn, $userId);
        if (!empty($receiptSettings['store_display_name'])) {
            $profile['business_name'] = $receiptSettings['store_display_name'];
        }
        if (!empty($receiptSettings['vat_tin'])) {
            $profile['tax_id'] = $receiptSettings['vat_tin'];
        }
        foreach (['branch_name', 'business_style', 'permit_no', 'ptu_no', 'accreditation_no', 'serial_no', 'footer_text'] as $key) {
            if (!empty($receiptSettings[$key])) {
                $profile[$key] = $receiptSettings[$key];
            }
        }

        return $profile;
    }
}

if (!function_exists('srPdfEscapeText')) {
    function srPdfEscapeText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n", "\t"],
            ['\\\\', '\\(', '\\)', '', ' ', ' '],
            $text
        );
    }
}

if (!function_exists('srBuildSimplePdfDocument')) {
    function srBuildSimplePdfDocument(array $lines): string
    {
        $content = "BT\n/F1 11 Tf\n";
        $y = 790;
        foreach ($lines as $line) {
            $safe = srPdfEscapeText((string)$line);
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
}

if (!function_exists('srBuildReceiptPdfLines')) {
    function srBuildReceiptPdfLines(array $receipt): array
    {
        $business = $receipt['business'] ?? [];
        $totals = $receipt['totals'] ?? [];
        $items = $receipt['items'] ?? [];

        $lines = [
            (string)($business['business_name'] ?? 'Lechon Delights'),
        ];
        if (!empty($business['branch_name'])) {
            $lines[] = 'Branch: ' . (string)$business['branch_name'];
        }
        $lines[] = (string)($business['address'] ?? 'Business address not set');
        if (!empty($business['phone'])) {
            $lines[] = 'Tel: ' . (string)$business['phone'];
        }
        if (!empty($business['tax_id'])) {
            $lines[] = 'VAT Reg TIN: ' . (string)$business['tax_id'];
        }
        if (!empty($business['business_style'])) {
            $lines[] = 'Business Style: ' . (string)$business['business_style'];
        }
        if (!empty($business['permit_no'])) {
            $lines[] = 'Permit No.: ' . (string)$business['permit_no'];
        }
        if (!empty($business['ptu_no'])) {
            $lines[] = 'PTU No.: ' . (string)$business['ptu_no'];
        }
        if (!empty($business['accreditation_no'])) {
            $lines[] = 'Accreditation No.: ' . (string)$business['accreditation_no'];
        }
        if (!empty($business['serial_no'])) {
            $lines[] = 'Serial No.: ' . (string)$business['serial_no'];
        }
        $lines[] = (string)($receipt['invoice_heading'] ?? 'Sales Invoice');
        $lines[] = ' ';
        $lines[] = 'Invoice No.: ' . (string)($receipt['receipt_no'] ?? '');
        $lines[] = 'Trans. No.: ' . (string)($receipt['transaction_no'] ?? '');
        $lines[] = 'Date: ' . date('Y-m-d H:i:s', strtotime((string)($receipt['timestamp'] ?? 'now')));
        $lines[] = (string)($receipt['operator_label'] ?? 'Payment') . ': ' . (string)($receipt['operator_name'] ?? '-');
        $lines[] = (string)($receipt['secondary_label'] ?? 'Status') . ': ' . (string)($receipt['secondary_value'] ?? '-');
        $lines[] = 'Service: ' . (string)($receipt['service_label'] ?? 'ORDER');
        $lines[] = ' ';
        $lines[] = 'Items';
        foreach ($items as $item) {
            $lines[] = sprintf(
                '%s x%d @ %s = %s',
                (string)($item['name'] ?? 'Item'),
                (int)($item['quantity'] ?? 0),
                srMoney((float)($item['price'] ?? 0)),
                srMoney((float)($item['total'] ?? 0))
            );
        }
        $lines[] = ' ';
        $lines[] = 'Subtotal: ' . srMoney((float)($totals['subtotal'] ?? 0));
        if ((float)($totals['delivery_fee'] ?? 0) > 0) {
            $lines[] = 'Delivery Fee: ' . srMoney((float)($totals['delivery_fee'] ?? 0));
        }
        if ((float)($totals['voucher_discount'] ?? 0) > 0) {
            $lines[] = 'Voucher Discount: -' . srMoney((float)($totals['voucher_discount'] ?? 0));
        }
        $lines[] = 'Total Amount Due: ' . srMoney((float)($totals['total_amount'] ?? 0));
        if (isset($receipt['cash_received']) && $receipt['cash_received'] !== null) {
            $lines[] = 'Cash: ' . srMoney((float)$receipt['cash_received']);
        }
        if (isset($receipt['change_amount']) && $receipt['change_amount'] !== null) {
            $lines[] = 'Change: ' . srMoney((float)$receipt['change_amount']);
        }
        $lines[] = 'VATable Sales: ' . srMoney((float)($totals['vatable_sales'] ?? 0));
        $lines[] = 'VAT-Exempt Sales: ' . srMoney((float)($totals['vat_exempt_sales'] ?? 0));
        $lines[] = 'Zero Rated Sales: ' . srMoney((float)($totals['zero_rated_sales'] ?? 0));
        $lines[] = 'VAT Amount: ' . srMoney((float)($totals['vat_amount'] ?? 0));
        $lines[] = ' ';
        $lines[] = 'Customer: ' . (string)($receipt['customer_name'] ?? '');
        if (!empty($receipt['customer_address'])) {
            $lines[] = 'Address: ' . (string)$receipt['customer_address'];
        }
        if (!empty($receipt['customer_tin'])) {
            $lines[] = 'TIN: ' . (string)$receipt['customer_tin'];
        }
        if (!empty($receipt['customer_business_style'])) {
            $lines[] = 'Business Style: ' . (string)$receipt['customer_business_style'];
        }
        if (!empty($receipt['notes'])) {
            $lines[] = 'Remarks: ' . preg_replace('/\s+/', ' ', (string)$receipt['notes']);
        }
        if (!empty($business['footer_text'])) {
            $lines[] = ' ';
            foreach (preg_split('/\r\n|\r|\n/', (string)$business['footer_text']) as $footerLine) {
                $footerLine = trim((string)$footerLine);
                if ($footerLine !== '') {
                    $lines[] = $footerLine;
                }
            }
        }
        $lines[] = ' ';
        $lines[] = 'CLAIM# ' . (string)($receipt['claim_code'] ?? 'N/A');

        return $lines;
    }
}

if (!function_exists('srResolveOrderSellerId')) {
    function srResolveOrderSellerId(mysqli $conn, int $orderId): int
    {
        $query = "SELECT p.seller_id
                  FROM order_items oi
                  INNER JOIN products p
                    ON oi.product_id = p.product_id
                    OR oi.product_id = CAST(p.id AS CHAR)
                    OR CAST(oi.product_id AS UNSIGNED) = p.id
                  WHERE oi.order_id = ?
                    AND p.seller_id IS NOT NULL
                  GROUP BY p.seller_id
                  ORDER BY COUNT(*) DESC, p.seller_id ASC
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        return (int)($row['seller_id'] ?? 0);
    }
}

if (!function_exists('srResolvePreOrderSellerId')) {
    function srResolvePreOrderSellerId(mysqli $conn, int $preOrderId): int
    {
        $query = "SELECT p.seller_id
                  FROM pre_orders po
                  INNER JOIN products p ON p.id = po.product_id
                  WHERE po.id = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 'i', $preOrderId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        return (int)($row['seller_id'] ?? 0);
    }
}

if (!function_exists('srFetchOrderReceiptData')) {
    function srFetchOrderReceiptData(mysqli $conn, int $orderId, ?int $sellerScopeId = null): array
    {
        $orderQuery = "SELECT * FROM orders WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $orderQuery);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $orderResult = mysqli_stmt_get_result($stmt);
        $order = $orderResult ? mysqli_fetch_assoc($orderResult) : null;
        mysqli_stmt_close($stmt);

        if (!$order) {
            return [];
        }

        $itemsSql = "SELECT oi.product_name, oi.quantity, oi.price, oi.total
                     FROM order_items oi";
        if ($sellerScopeId !== null) {
            $itemsSql .= " INNER JOIN products p
                            ON oi.product_id = p.product_id
                            OR oi.product_id = CAST(p.id AS CHAR)
                            OR CAST(oi.product_id AS UNSIGNED) = p.id";
        }
        $itemsSql .= " WHERE oi.order_id = ?";
        if ($sellerScopeId !== null) {
            $itemsSql .= " AND p.seller_id = ?";
        }
        $itemsSql .= " ORDER BY oi.id ASC";

        $stmt = mysqli_prepare($conn, $itemsSql);
        if (!$stmt) {
            return [];
        }
        if ($sellerScopeId !== null) {
            mysqli_stmt_bind_param($stmt, 'ii', $orderId, $sellerScopeId);
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $orderId);
        }
        mysqli_stmt_execute($stmt);
        $itemsResult = mysqli_stmt_get_result($stmt);
        $items = [];
        while ($itemsResult && ($row = mysqli_fetch_assoc($itemsResult))) {
            $items[] = [
                'name' => (string)($row['product_name'] ?? 'Item'),
                'quantity' => (int)($row['quantity'] ?? 0),
                'price' => (float)($row['price'] ?? 0),
                'total' => (float)($row['total'] ?? 0),
            ];
        }
        mysqli_stmt_close($stmt);

        if (empty($items)) {
            return [];
        }

        $sellerUserId = $sellerScopeId !== null ? (int)$sellerScopeId : srResolveOrderSellerId($conn, $orderId);
        $business = srFetchBusinessProfile($conn, $sellerUserId);
        $totals = srResolveVatBreakdown(
            (float)($order['subtotal'] ?? 0),
            (float)($order['delivery_fee'] ?? 0),
            (float)($order['total_amount'] ?? 0),
            (float)($order['voucher_discount'] ?? 0)
        );

        $deliveryOption = strtolower(trim((string)($order['delivery_option'] ?? '')));
        $serviceLabel = 'ORDER';
        if ($deliveryOption === 'delivery') {
            $serviceLabel = 'DELIVERY';
        } elseif ($deliveryOption === 'pickup') {
            $serviceLabel = stripos((string)($order['special_instructions'] ?? ''), 'walk-in') !== false ? 'WALK-IN' : 'PICKUP';
        }

        return [
            'invoice_heading' => 'Sales Invoice',
            'service_label' => $serviceLabel,
            'receipt_no' => (string)($order['order_number'] ?? ('ORD-' . $orderId)),
            'transaction_no' => (string)$orderId,
            'timestamp' => (string)($order['created_at'] ?? date('Y-m-d H:i:s')),
            'business' => $business,
            'items' => $items,
            'totals' => $totals,
            'customer_name' => (string)($order['customer_name'] ?? 'Customer'),
            'customer_address' => (string)($order['delivery_address'] ?? ''),
            'customer_tin' => '',
            'customer_business_style' => '',
            'operator_label' => 'Payment',
            'operator_name' => (string)($order['payment_method'] ?? 'Unspecified'),
            'secondary_label' => 'Status',
            'secondary_value' => strtoupper((string)($order['payment_status'] ?? $order['status'] ?? 'PENDING')),
            'cash_received' => null,
            'change_amount' => null,
            'notes' => trim((string)($order['special_instructions'] ?? '')),
            'claim_code' => srClaimCode((string)($order['order_number'] ?? (string)$orderId)),
        ];
    }
}

if (!function_exists('srFetchPreOrderReceiptData')) {
    function srFetchPreOrderReceiptData(mysqli $conn, int $preOrderId, ?int $sellerScopeId = null): array
    {
        $query = "SELECT po.*, u.full_name, u.email, u.phone, u.address
                  FROM pre_orders po
                  INNER JOIN users u ON po.user_id = u.id";
        if ($sellerScopeId !== null) {
            $query .= " INNER JOIN products p ON p.id = po.product_id AND p.seller_id = ?";
        }
        $query .= " WHERE po.id = ? LIMIT 1";

        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return [];
        }
        if ($sellerScopeId !== null) {
            mysqli_stmt_bind_param($stmt, 'ii', $sellerScopeId, $preOrderId);
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $preOrderId);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $preOrder = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$preOrder) {
            return [];
        }

        $subtotal = (float)($preOrder['unit_price'] ?? 0) * (int)($preOrder['quantity'] ?? 0);
        $totalPrice = (float)($preOrder['total_price'] ?? 0);
        if ($totalPrice > 0 && $totalPrice + 0.01 < $subtotal) {
            $subtotal = $totalPrice;
        }

        $sellerUserId = $sellerScopeId !== null ? (int)$sellerScopeId : srResolvePreOrderSellerId($conn, $preOrderId);
        $business = srFetchBusinessProfile($conn, $sellerUserId);
        $totals = srResolveVatBreakdown($subtotal, 0.00, $totalPrice);

        $notes = trim((string)($preOrder['special_instructions'] ?? ''));
        $adminNotes = trim((string)($preOrder['admin_notes'] ?? ''));
        if ($adminNotes !== '') {
            $notes = $notes !== '' ? $notes . "\nAdmin: " . $adminNotes : 'Admin: ' . $adminNotes;
        }

        return [
            'invoice_heading' => 'Sales Invoice',
            'service_label' => 'PRE-ORDER',
            'receipt_no' => 'PRE-' . str_pad((string)$preOrderId, 6, '0', STR_PAD_LEFT),
            'transaction_no' => (string)$preOrderId,
            'timestamp' => (string)($preOrder['created_at'] ?? date('Y-m-d H:i:s')),
            'business' => $business,
            'items' => [[
                'name' => (string)($preOrder['product_name'] ?? 'Reserved Item'),
                'quantity' => (int)($preOrder['quantity'] ?? 0),
                'price' => (float)($preOrder['unit_price'] ?? 0),
                'total' => $subtotal,
            ]],
            'totals' => $totals,
            'customer_name' => (string)($preOrder['full_name'] ?? 'Customer'),
            'customer_address' => (string)($preOrder['address'] ?? ''),
            'customer_tin' => '',
            'customer_business_style' => '',
            'operator_label' => 'Payment',
            'operator_name' => ucwords(str_replace('_', ' ', (string)($preOrder['payment_type'] ?? 'Unspecified'))),
            'secondary_label' => 'Status',
            'secondary_value' => strtoupper(str_replace('_', ' ', (string)($preOrder['reservation_status'] ?? 'pending'))),
            'cash_received' => null,
            'change_amount' => null,
            'notes' => $notes,
            'claim_code' => srClaimCode((string)$preOrderId),
        ];
    }
}

if (!function_exists('srRenderReceiptPage')) {
    function srRenderReceiptPage(array $receipt, bool $autoPrint = false): string
    {
        $business = $receipt['business'] ?? [];
        $totals = $receipt['totals'] ?? [];
        $items = $receipt['items'] ?? [];
        $downloadPdfUrl = trim((string)($receipt['download_pdf_url'] ?? ''));
        $backUrl = trim((string)($receipt['back_url'] ?? ''));

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string)($receipt['receipt_no'] ?? 'Sales Receipt')); ?></title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            margin: 0;
            background: #eef2f7;
            color: #111827;
            font-family: Arial, sans-serif;
        }
        .screen-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 16px 12px 0;
        }
        .screen-actions button,
        .screen-actions a {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .receipt-shell {
            width: 100%;
            display: flex;
            justify-content: center;
            padding: 16px 12px 32px;
            box-sizing: border-box;
        }
        .receipt-paper {
            width: 360px;
            background: #fff;
            color: #111;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
            padding: 18px 18px 22px;
            font-family: "Courier New", Courier, monospace;
            font-size: 12px;
            line-height: 1.32;
        }
        .receipt-center {
            text-align: center;
        }
        .receipt-business {
            font-size: 24px;
            line-height: 1.1;
            margin: 0 0 4px;
            font-weight: 700;
        }
        .receipt-muted {
            color: #4b5563;
        }
        .receipt-rule {
            border-top: 1px dashed #6b7280;
            margin: 10px 0;
        }
        .receipt-heading {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 13px;
            margin: 6px 0 0;
            font-weight: 700;
        }
        .receipt-service {
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            margin: 10px 0 6px;
            letter-spacing: 0.12em;
        }
        .receipt-meta-row,
        .receipt-total-row,
        .receipt-customer-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }
        .receipt-meta-row span:first-child,
        .receipt-total-row span:first-child,
        .receipt-customer-row span:first-child {
            min-width: 112px;
        }
        .receipt-items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .receipt-items th,
        .receipt-items td {
            padding: 3px 0;
            vertical-align: top;
        }
        .receipt-items th {
            border-bottom: 1px solid #111;
            font-weight: 700;
        }
        .receipt-items th:nth-child(2),
        .receipt-items td:nth-child(2) {
            text-align: center;
            width: 46px;
        }
        .receipt-items th:nth-child(3),
        .receipt-items th:nth-child(4),
        .receipt-items td:nth-child(3),
        .receipt-items td:nth-child(4) {
            text-align: right;
            width: 74px;
        }
        .receipt-items td:first-child {
            padding-right: 8px;
        }
        .receipt-total-row {
            margin: 3px 0;
        }
        .receipt-total-row strong {
            font-size: 13px;
        }
        .receipt-bottom-note {
            text-align: center;
            margin-top: 12px;
        }
        .receipt-claim {
            text-align: center;
            margin-top: 16px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        @media print {
            body {
                background: #fff;
            }
            .screen-actions {
                display: none !important;
            }
            .receipt-shell {
                padding: 0;
            }
            .receipt-paper {
                box-shadow: none;
                width: auto;
                padding: 0;
            }
            @page {
                size: auto;
                margin: 6mm;
            }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <?php if ($backUrl !== ''): ?>
            <a href="<?php echo htmlspecialchars($backUrl); ?>">Back</a>
        <?php endif; ?>
        <button type="button" onclick="window.print()">Print Receipt</button>
        <?php if ($downloadPdfUrl !== ''): ?>
            <a href="<?php echo htmlspecialchars($downloadPdfUrl); ?>">Download PDF</a>
        <?php endif; ?>
        <button type="button" onclick="window.close()">Close</button>
    </div>
    <div class="receipt-shell">
        <div class="receipt-paper">
            <div class="receipt-center">
                <div class="receipt-business"><?php echo htmlspecialchars((string)($business['business_name'] ?? 'Lechon Delights')); ?></div>
                <?php if (!empty($business['branch_name'])): ?>
                    <div class="receipt-muted"><?php echo htmlspecialchars((string)$business['branch_name']); ?></div>
                <?php endif; ?>
                <div class="receipt-muted"><?php echo htmlspecialchars((string)($business['address'] ?? 'Business address not set')); ?></div>
                <?php if (!empty($business['phone'])): ?>
                    <div class="receipt-muted">Tel: <?php echo htmlspecialchars((string)$business['phone']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['tax_id'])): ?>
                    <div class="receipt-muted">VAT Reg TIN: <?php echo htmlspecialchars((string)$business['tax_id']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['business_style'])): ?>
                    <div class="receipt-muted">Business Style: <?php echo htmlspecialchars((string)$business['business_style']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['permit_no'])): ?>
                    <div class="receipt-muted">Permit No.: <?php echo htmlspecialchars((string)$business['permit_no']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['ptu_no'])): ?>
                    <div class="receipt-muted">PTU No.: <?php echo htmlspecialchars((string)$business['ptu_no']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['accreditation_no'])): ?>
                    <div class="receipt-muted">Accreditation No.: <?php echo htmlspecialchars((string)$business['accreditation_no']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['serial_no'])): ?>
                    <div class="receipt-muted">Serial No.: <?php echo htmlspecialchars((string)$business['serial_no']); ?></div>
                <?php endif; ?>
                <?php if (!empty($business['business_registration'])): ?>
                    <div class="receipt-muted">Registration No.: <?php echo htmlspecialchars((string)$business['business_registration']); ?></div>
                <?php endif; ?>
                <div class="receipt-heading"><?php echo htmlspecialchars((string)($receipt['invoice_heading'] ?? 'Sales Invoice')); ?></div>
            </div>

            <div class="receipt-rule"></div>

            <div class="receipt-meta-row"><span>Invoice No.</span><span><?php echo htmlspecialchars((string)($receipt['receipt_no'] ?? '')); ?></span></div>
            <div class="receipt-meta-row"><span>Trans. No.</span><span><?php echo htmlspecialchars((string)($receipt['transaction_no'] ?? '')); ?></span></div>
            <div class="receipt-meta-row"><span>Date</span><span><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)($receipt['timestamp'] ?? 'now')))); ?></span></div>
            <div class="receipt-meta-row"><span><?php echo htmlspecialchars((string)($receipt['operator_label'] ?? 'Payment')); ?></span><span><?php echo htmlspecialchars((string)($receipt['operator_name'] ?? '-')); ?></span></div>
            <div class="receipt-meta-row"><span><?php echo htmlspecialchars((string)($receipt['secondary_label'] ?? 'Status')); ?></span><span><?php echo htmlspecialchars((string)($receipt['secondary_value'] ?? '-')); ?></span></div>

            <div class="receipt-rule"></div>

            <div class="receipt-service"><?php echo htmlspecialchars((string)($receipt['service_label'] ?? 'ORDER')); ?></div>

            <table class="receipt-items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($item['name'] ?? 'Item')); ?></td>
                            <td><?php echo (int)($item['quantity'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars(srMoney((float)($item['price'] ?? 0))); ?></td>
                            <td><?php echo htmlspecialchars(srMoney((float)($item['total'] ?? 0))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="receipt-rule"></div>

            <div class="receipt-total-row"><span>Subtotal</span><span><?php echo htmlspecialchars(srMoney((float)($totals['subtotal'] ?? 0))); ?></span></div>
            <?php if ((float)($totals['delivery_fee'] ?? 0) > 0): ?>
                <div class="receipt-total-row"><span>Delivery Fee</span><span><?php echo htmlspecialchars(srMoney((float)($totals['delivery_fee'] ?? 0))); ?></span></div>
            <?php endif; ?>
            <?php if ((float)($totals['voucher_discount'] ?? 0) > 0): ?>
                <div class="receipt-total-row"><span>Voucher Discount</span><span>-<?php echo htmlspecialchars(srMoney((float)($totals['voucher_discount'] ?? 0))); ?></span></div>
            <?php endif; ?>
            <div class="receipt-total-row"><span>Total Amount Due</span><strong><?php echo htmlspecialchars(srMoney((float)($totals['total_amount'] ?? 0))); ?></strong></div>
            <?php if (isset($receipt['cash_received']) && $receipt['cash_received'] !== null): ?>
                <div class="receipt-total-row"><span>Cash</span><span><?php echo htmlspecialchars(srMoney((float)$receipt['cash_received'])); ?></span></div>
            <?php endif; ?>
            <?php if (isset($receipt['change_amount']) && $receipt['change_amount'] !== null): ?>
                <div class="receipt-total-row"><span>Change</span><span><?php echo htmlspecialchars(srMoney((float)$receipt['change_amount'])); ?></span></div>
            <?php endif; ?>
            <div class="receipt-total-row"><span>VATable Sales</span><span><?php echo htmlspecialchars(srMoney((float)($totals['vatable_sales'] ?? 0))); ?></span></div>
            <div class="receipt-total-row"><span>VAT-Exempt Sales</span><span><?php echo htmlspecialchars(srMoney((float)($totals['vat_exempt_sales'] ?? 0))); ?></span></div>
            <div class="receipt-total-row"><span>Zero Rated Sales</span><span><?php echo htmlspecialchars(srMoney((float)($totals['zero_rated_sales'] ?? 0))); ?></span></div>
            <div class="receipt-total-row"><span>VAT Amount</span><span><?php echo htmlspecialchars(srMoney((float)($totals['vat_amount'] ?? 0))); ?></span></div>

            <div class="receipt-rule"></div>

            <div class="receipt-service">CUSTOMER</div>
            <div class="receipt-customer-row"><span>Name</span><span><?php echo htmlspecialchars((string)($receipt['customer_name'] ?? '')); ?></span></div>
            <div class="receipt-customer-row"><span>Address</span><span><?php echo htmlspecialchars((string)($receipt['customer_address'] ?? '')); ?></span></div>
            <div class="receipt-customer-row"><span>TIN</span><span><?php echo htmlspecialchars((string)($receipt['customer_tin'] ?? '')); ?></span></div>
            <div class="receipt-customer-row"><span>Business Style</span><span><?php echo htmlspecialchars((string)($receipt['customer_business_style'] ?? '')); ?></span></div>

            <?php if (!empty($receipt['notes'])): ?>
                <div class="receipt-rule"></div>
                <div><strong>Remarks:</strong></div>
                <div><?php echo nl2br(htmlspecialchars((string)$receipt['notes'])); ?></div>
            <?php endif; ?>

            <div class="receipt-bottom-note">
                <?php if (!empty($business['footer_text'])): ?>
                    <?php foreach (preg_split('/\r\n|\r|\n/', (string)$business['footer_text']) as $footerLine): ?>
                        <?php $footerLine = trim((string)$footerLine); ?>
                        <?php if ($footerLine !== ''): ?>
                            <div><?php echo htmlspecialchars($footerLine); ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div>Thank you for your order.</div>
                    <div class="receipt-muted">Please keep this receipt for your records.</div>
                <?php endif; ?>
            </div>

            <div class="receipt-claim">
                CLAIM#<br>
                <?php echo htmlspecialchars((string)($receipt['claim_code'] ?? 'N/A')); ?>
            </div>
        </div>
    </div>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load', function(){ window.print(); });</script>
    <?php endif; ?>
</body>
</html>
        <?php
        return (string)ob_get_clean();
    }
}
