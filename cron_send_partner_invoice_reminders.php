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
$result = $service->sendAutomaticInvoiceReminders(0, $runDate);

echo "Automatic invoice reminders complete.\n";
echo 'Run date: ' . ($result['run_date'] ?? date('Y-m-d')) . "\n";
echo 'Reminded: ' . (int)($result['reminded'] ?? 0) . "\n";
echo 'Skipped: ' . (int)($result['skipped'] ?? 0) . "\n";

foreach (($result['targets'] ?? []) as $target) {
    echo '- ' . ($target['business_name'] ?? 'Partner') . ': ' . ($target['invoice_number'] ?? '') . ' [' . ($target['reminder_type'] ?? 'manual') . ']' . "\n";
}
