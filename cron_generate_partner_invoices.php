<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is intended for CLI or scheduled task use only.\n";
    exit(1);
}

$runDate = $argv[1] ?? null;
$service = new PlatformMonetizationService($conn);
$service->ensureReady(0);
$result = $service->autoGenerateMonthlyInvoices(0, $runDate);

echo "Automatic invoice generation complete.\n";
echo 'Run date: ' . ($result['run_date'] ?? date('Y-m-d')) . "\n";
echo 'Target month: ' . ($result['target_month'] ?? '-') . "\n";
echo 'Generated: ' . (int)($result['generated'] ?? 0) . "\n";
echo 'Skipped: ' . (int)($result['skipped'] ?? 0) . "\n";
echo 'Failed: ' . (int)($result['failed'] ?? 0) . "\n";

foreach (($result['details'] ?? []) as $detail) {
    echo '- ' . ($detail['business_name'] ?? 'Partner') . ': ' . ($detail['status'] ?? 'unknown');
    if (!empty($detail['invoice_number'])) {
        echo ' (' . $detail['invoice_number'] . ')';
    }
    echo "\n";
}
