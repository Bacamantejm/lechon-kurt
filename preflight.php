<?php
/**
 * Hostinger Deployment Preflight Check
 * ────────────────────────────────────
 * Runs automated checks across PHP configuration, required extensions,
 * database connectivity, table existence, and folder write permissions.
 */
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

require_once __DIR__ . '/includes/config.php';

$checks = [];

function addCheckResult(&$checks, $category, $name, $status, $message) {
    $checks[] = [
        'category' => $category,
        'name' => $name,
        'status' => $status, // 'OK', 'WARN', 'FAIL'
        'message' => $message
    ];
}

// 1. PHP Version & Engine
$min_php = '8.0.0';
if (version_compare(PHP_VERSION, $min_php, '>=')) {
    addCheckResult($checks, 'PHP Environment', 'PHP Version', 'OK', 'Running PHP ' . PHP_VERSION);
} else {
    addCheckResult($checks, 'PHP Environment', 'PHP Version', 'FAIL', 'PHP version must be >= ' . $min_php . ', currently running ' . PHP_VERSION);
}

// 2. Required PHP Extensions
$extensions = ['mysqli', 'curl', 'json', 'mbstring', 'openssl', 'fileinfo', 'gd'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        addCheckResult($checks, 'PHP Extensions', "ext-{$ext}", 'OK', 'Extension loaded');
    } else {
        addCheckResult($checks, 'PHP Extensions', "ext-{$ext}", 'FAIL', "Extension {$ext} is missing or disabled");
    }
}

// 3. Database Connection
if (isset($conn) && $conn instanceof mysqli && @mysqli_ping($conn)) {
    addCheckResult($checks, 'Database', 'Connection', 'OK', 'Successfully connected to MySQL database: ' . appConfigValue('APP_DB_NAME'));
    
    // Check Charset
    $charset = mysqli_character_set_name($conn);
    if (strpos(strtolower($charset), 'utf8') !== false) {
        addCheckResult($checks, 'Database', 'Charset', 'OK', "Charset is {$charset}");
    } else {
        addCheckResult($checks, 'Database', 'Charset', 'WARN', "Charset is {$charset}, recommend utf8mb4");
    }

    // Check Core Application Tables
    $core_tables = [
        'users', 'orders', 'order_items', 'products', 'store_locations',
        'riders', 'logistics_tracking', 'employees_geo_tracking',
        'order_payout_splits', 'store_wallets', 'store_transactions',
        'rider_wallets', 'rider_transactions', 'delivery_reviews'
    ];
    $missing_tables = [];
    foreach ($core_tables as $tbl) {
        $check = mysqli_query($conn, "SHOW TABLES LIKE '{$tbl}'");
        if (!$check || mysqli_num_rows($check) === 0) {
            $missing_tables[] = $tbl;
        }
    }
    if (empty($missing_tables)) {
        addCheckResult($checks, 'Database', 'Core Tables', 'OK', 'All ' . count($core_tables) . ' core tables verified.');
    } else {
        addCheckResult($checks, 'Database', 'Core Tables', 'FAIL', 'Missing tables: ' . implode(', ', $missing_tables) . '. Run database/schema_updates/run.php to create them.');
    }
} else {
    addCheckResult($checks, 'Database', 'Connection', 'FAIL', 'Could not establish MySQL connection. Check includes/deployment_credentials.php');
}

// 4. File Upload & Write Permissions
$writable_dirs = [
    'uploads',
    'uploads/proof_of_delivery',
    'uploads/profile_pictures',
    'uploads/business_logos',
    'uploads/products',
    'logs',
    'tmp'
];
foreach ($writable_dirs as $rel_dir) {
    $full_path = __DIR__ . '/' . $rel_dir;
    if (!is_dir($full_path)) {
        @mkdir($full_path, 0755, true);
    }
    if (is_dir($full_path) && is_writable($full_path)) {
        addCheckResult($checks, 'Storage & Permissions', $rel_dir, 'OK', 'Directory exists and is writable.');
    } else {
        addCheckResult($checks, 'Storage & Permissions', $rel_dir, 'WARN', 'Directory is not writable or could not be created. Ensure 0755 or 0775 permissions.');
    }
}

// 5. Session Save Path
$sess_path = session_save_path();
if ($sess_path === '' || is_writable($sess_path)) {
    addCheckResult($checks, 'Session Security', 'Save Path', 'OK', 'Session save path is writable: ' . ($sess_path ?: 'Default'));
} else {
    addCheckResult($checks, 'Session Security', 'Save Path', 'WARN', 'Session save path may not be writable: ' . $sess_path);
}

// 6. Base URL & HTTPS
$base_url = function_exists('appBaseUrl') ? appBaseUrl() : 'N/A';
addCheckResult($checks, 'Network & Routing', 'Application Base URL', 'OK', $base_url);

if ($is_cli) {
    echo "=========================================================\n";
    echo "       LECHON SYSTEM — HOSTINGER PREFLIGHT AUDIT        \n";
    echo "=========================================================\n\n";
    $has_fail = false;
    foreach ($checks as $c) {
        $badge = "[OK]  ";
        if ($c['status'] === 'WARN') $badge = "[WARN]";
        if ($c['status'] === 'FAIL') {
            $badge = "[FAIL]";
            $has_fail = true;
        }
        printf("%-7s %-22s %-25s %s\n", $badge, $c['category'], $c['name'], $c['message']);
    }
    echo "\n";
    if ($has_fail) {
        echo "RESULT: FAIL — Resolve items marked [FAIL] before uploading.\n";
        exit(1);
    } else {
        echo "RESULT: PASS — All preflight checks passed successfully!\n";
        exit(0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostinger Deployment Preflight Check</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; color: #101828; margin: 0; padding: 32px 16px; }
        .card { max-width: 900px; margin: 0 auto; background: #ffffff; border-radius: 14px; border: 1px solid #eaecf0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden; }
        .card-header { padding: 24px; background: #ffffff; border-bottom: 1px solid #eaecf0; }
        h1 { margin: 0 0 6px 0; font-size: 1.35rem; color: #b3261e; }
        p.sub { margin: 0; color: #667085; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f9fafb; padding: 12px 20px; font-size: 0.8rem; text-transform: uppercase; color: #475467; border-bottom: 1px solid #eaecf0; }
        td { padding: 12px 20px; font-size: 0.88rem; border-bottom: 1px solid #f2f4f7; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-ok { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .badge-warn { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
        .badge-fail { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
        .card-footer { padding: 18px 24px; background: #f9fafb; font-size: 0.85rem; color: #475467; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h1>Hostinger Deployment Preflight Check</h1>
            <p class="sub">Automated audit for PHP configuration, required extensions, database tables, and permissions.</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Category</th>
                    <th>Component</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $c): ?>
                <tr>
                    <td>
                        <?php if ($c['status'] === 'OK'): ?>
                            <span class="badge badge-ok">PASS</span>
                        <?php elseif ($c['status'] === 'WARN'): ?>
                            <span class="badge badge-warn">WARN</span>
                        <?php else: ?>
                            <span class="badge badge-fail">FAIL</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($c['category']); ?></strong></td>
                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                    <td><?php echo htmlspecialchars($c['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card-footer">
            Tip: Run <code>database/schema_updates/run.php</code> on Hostinger if any database tables or columns report missing.
        </div>
    </div>
</body>
</html>
