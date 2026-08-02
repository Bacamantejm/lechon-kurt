<?php
require_once __DIR__ . '/module_common.php';

$has_api_tokens = saTableExists($conn, 'api_tokens');
$has_anomaly_alerts = saTableExists($conn, 'anomaly_alerts');

if (!function_exists('saReadableBytes')) {
    function saReadableBytes($bytes) {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $idx = 0;
        while ($bytes >= 1024 && $idx < count($units) - 1) {
            $bytes /= 1024;
            $idx++;
        }
        return number_format($bytes, 2) . ' ' . $units[$idx];
    }
}

$db_online = false;
if (isset($conn) && $conn) {
    $db_online = @mysqli_ping($conn);
}

$query_benchmark_ms = null;
if ($db_online) {
    $start = microtime(true);
    @mysqli_query($conn, "SELECT 1");
    $query_benchmark_ms = round((microtime(true) - $start) * 1000, 2);
}

$table_status = [];
$database_total_size = 0;
if ($db_online) {
    $table_status = saQueryRows($conn, "SHOW TABLE STATUS");
    foreach ($table_status as $table_info) {
        $database_total_size += (float)($table_info['Data_length'] ?? 0) + (float)($table_info['Index_length'] ?? 0);
    }
    usort($table_status, function ($a, $b) {
        $size_a = ((float)($a['Data_length'] ?? 0) + (float)($a['Index_length'] ?? 0));
        $size_b = ((float)($b['Data_length'] ?? 0) + (float)($b['Index_length'] ?? 0));
        if ($size_a === $size_b) {
            return strcmp((string)($a['Name'] ?? ''), (string)($b['Name'] ?? ''));
        }
        return $size_a < $size_b ? 1 : -1;
    });
}

$php_version = phpversion();
$php_sapi = php_sapi_name();
$memory_limit = ini_get('memory_limit');
$upload_max_filesize = ini_get('upload_max_filesize');
$timezone = date_default_timezone_get();

$api_metrics = [
    'total_tokens' => 0,
    'active_tokens' => 0,
    'expired_tokens' => 0,
    'last_used_at' => null
];
if ($has_api_tokens) {
    $api_metrics['total_tokens'] = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM api_tokens", 0);
    $api_metrics['active_tokens'] = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM api_tokens WHERE is_active = 1", 0);
    $api_metrics['expired_tokens'] = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM api_tokens WHERE expires_at < NOW()", 0);
    $api_metrics['last_used_at'] = saQueryScalar($conn, "SELECT MAX(last_used_at) FROM api_tokens", null);
}

$anomaly_alert_count = 0;
$open_anomaly_alert_count = 0;
if ($has_anomaly_alerts) {
    $anomaly_alert_count = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM anomaly_alerts", 0);
    $open_anomaly_alert_count = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM anomaly_alerts WHERE resolved_at IS NULL", 0);
}

$log_dir = realpath(__DIR__ . '/../logs');
$log_files = [];
if ($log_dir && is_dir($log_dir)) {
    $entries = scandir($log_dir);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full_path = $log_dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($full_path)) {
                continue;
            }

            $log_files[] = [
                'name' => $entry,
                'size' => filesize($full_path) ?: 0,
                'modified_at' => filemtime($full_path) ?: null
            ];
        }
    }
    usort($log_files, function ($a, $b) {
        $a_time = (int)($a['modified_at'] ?? 0);
        $b_time = (int)($b['modified_at'] ?? 0);
        return $b_time <=> $a_time;
    });
}
$log_files = array_slice($log_files, 0, 30);

$table_count = count($table_status);
$largest_tables = array_slice($table_status, 0, 10);

saRenderModuleHeader('System Monitoring', 'System Monitoring', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>System Health Overview</h2>
            <p class="module-subtext">Track server health, database condition, errors, and API activity.</p>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Server Status</span>
            <div class="metric-value">
                <?php if ($db_online): ?>
                    <span class="status-chip chip-success">Online</span>
                <?php else: ?>
                    <span class="status-chip chip-danger">Offline</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="metric-card">
            <span class="metric-label">DB Query Benchmark</span>
            <div class="metric-value"><?php echo $query_benchmark_ms !== null ? htmlspecialchars(number_format($query_benchmark_ms, 2) . ' ms') : 'N/A'; ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Database Size</span>
            <div class="metric-value"><?php echo htmlspecialchars(saReadableBytes($database_total_size)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Error Log Files</span>
            <div class="metric-value"><?php echo number_format(count($log_files)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Server Runtime</h3>
            <p class="module-subtext">Environment and runtime settings relevant to system reliability.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table" style="min-width: 620px;">
            <thead>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>PHP Version</td><td><?php echo htmlspecialchars((string)$php_version); ?></td></tr>
                <tr><td>PHP SAPI</td><td><?php echo htmlspecialchars((string)$php_sapi); ?></td></tr>
                <tr><td>Memory Limit</td><td><?php echo htmlspecialchars((string)$memory_limit); ?></td></tr>
                <tr><td>Upload Max Filesize</td><td><?php echo htmlspecialchars((string)$upload_max_filesize); ?></td></tr>
                <tr><td>Timezone</td><td><?php echo htmlspecialchars((string)$timezone); ?></td></tr>
                <tr><td>Logs Directory Writable</td><td><?php echo ($log_dir && is_writable($log_dir)) ? 'Yes' : 'No'; ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Database Performance Snapshot</h3>
            <p class="module-subtext">Largest tables and storage footprint to monitor growth and optimization needs.</p>
        </div>
    </div>
    <?php if (!$db_online): ?>
        <div class="note-box">Database connection is offline. Table-level monitoring is currently unavailable.</div>
    <?php else: ?>
        <div class="note-box" style="margin-bottom: 12px;">
            Total tables: <strong><?php echo number_format($table_count); ?></strong> ·
            Estimated DB size: <strong><?php echo htmlspecialchars(saReadableBytes($database_total_size)); ?></strong>
        </div>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Rows (est.)</th>
                        <th>Data Size</th>
                        <th>Index Size</th>
                        <th>Total Size</th>
                        <th>Engine</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($largest_tables)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No table status information available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($largest_tables as $table_info): ?>
                            <?php
                                $data_size = (float)($table_info['Data_length'] ?? 0);
                                $index_size = (float)($table_info['Index_length'] ?? 0);
                                $total_size = $data_size + $index_size;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string)($table_info['Name'] ?? '')); ?></strong></td>
                                <td><?php echo number_format((int)($table_info['Rows'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars(saReadableBytes($data_size)); ?></td>
                                <td><?php echo htmlspecialchars(saReadableBytes($index_size)); ?></td>
                                <td><?php echo htmlspecialchars(saReadableBytes($total_size)); ?></td>
                                <td><?php echo htmlspecialchars((string)($table_info['Engine'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($table_info['Update_time'] ?? null, 'M d, Y h:i A', '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Error Logs and Alerts</h3>
            <p class="module-subtext">Recently modified log files and anomaly alert counters.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Anomaly Alerts</span>
            <div class="metric-value"><?php echo number_format($anomaly_alert_count); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Open Anomaly Alerts</span>
            <div class="metric-value"><?php echo number_format($open_anomaly_alert_count); ?></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table" style="min-width: 560px;">
            <thead>
                <tr>
                    <th>Log File</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log_files)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No files found in the logs directory.</td></tr>
                <?php else: ?>
                    <?php foreach ($log_files as $log_file): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$log_file['name']); ?></td>
                            <td><?php echo htmlspecialchars(saReadableBytes($log_file['size'] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars(saFormatDateTime(isset($log_file['modified_at']) ? date('Y-m-d H:i:s', (int)$log_file['modified_at']) : null)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>API Monitoring</h3>
            <p class="module-subtext">Track API token lifecycle and usage visibility.</p>
        </div>
    </div>
    <?php if (!$has_api_tokens): ?>
        <div class="note-box">`api_tokens` table is unavailable. API monitoring is currently limited.</div>
    <?php else: ?>
        <div class="metrics-grid">
            <div class="metric-card">
                <span class="metric-label">Total Tokens</span>
                <div class="metric-value"><?php echo number_format($api_metrics['total_tokens']); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Active Tokens</span>
                <div class="metric-value"><?php echo number_format($api_metrics['active_tokens']); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Expired Tokens</span>
                <div class="metric-value"><?php echo number_format($api_metrics['expired_tokens']); ?></div>
            </div>
            <div class="metric-card">
                <span class="metric-label">Last Token Activity</span>
                <div class="metric-value" style="font-size: 1rem;"><?php echo htmlspecialchars(saFormatDateTime($api_metrics['last_used_at'] ?? null, 'M d, Y h:i A', 'No usage')); ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
saRenderModuleFooter();

