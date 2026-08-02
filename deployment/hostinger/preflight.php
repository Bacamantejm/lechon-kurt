<?php
/**
 * Hostinger deployment preflight checker.
 * Run with: php deployment/hostinger/preflight.php
 */

declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/../../');
if ($baseDir === false) {
    fwrite(STDERR, "ERROR: Could not resolve project base directory.\n");
    exit(1);
}

$deploymentFile = $baseDir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'deployment_credentials.php';
$configFile = $baseDir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "ERROR: includes/config.php not found.\n");
    exit(1);
}

if (is_file($deploymentFile)) {
    require_once $deploymentFile;
}

function preflightValue(string $key, string $default = ''): string {
    if (defined($key)) {
        $value = constant($key);
        if ($value !== null && trim((string)$value) !== '') {
            return (string)$value;
        }
    }
    $env = getenv($key);
    if ($env !== false && trim((string)$env) !== '') {
        return (string)$env;
    }
    return $default;
}

function printCheck(string $label, bool $ok, string $detail = ''): void {
    $prefix = $ok ? '[OK]  ' : '[FAIL]';
    echo $prefix . ' ' . $label;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
}

echo "Hostinger Preflight Check\n";
echo "Project: {$baseDir}\n\n";

printCheck(
    'Deployment credentials file',
    is_file($deploymentFile),
    is_file($deploymentFile) ? basename($deploymentFile) . ' found' : 'includes/deployment_credentials.php is missing'
);

$dbHost = preflightValue('APP_DB_HOST', 'localhost');
$dbPort = (int)preflightValue('APP_DB_PORT', '3306');
if ($dbPort <= 0) $dbPort = 3306;
$dbUser = preflightValue('APP_DB_USER', '');
$dbPass = preflightValue('APP_DB_PASSWORD', '');
$dbName = preflightValue('APP_DB_NAME', '');

$dbConfigComplete = ($dbUser !== '' && $dbName !== '');
printCheck('DB config completeness', $dbConfigComplete, "host={$dbHost} port={$dbPort} user=" . ($dbUser !== '' ? 'set' : 'missing') . " db=" . ($dbName !== '' ? 'set' : 'missing'));

if (!$dbConfigComplete) {
    printCheck('DB connection test', false, 'skipped because DB config is incomplete');
} else {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    if ($mysqli) {
        @mysqli_set_charset($mysqli, 'utf8mb4');
        printCheck('DB connection test', true, 'connection successful');
        mysqli_close($mysqli);
    } else {
        printCheck('DB connection test', false, mysqli_connect_error());
    }
}

$requiredWritableDirs = [
    $baseDir . DIRECTORY_SEPARATOR . 'uploads',
    $baseDir . DIRECTORY_SEPARATOR . 'logs',
];

foreach ($requiredWritableDirs as $dir) {
    $exists = is_dir($dir);
    $writable = $exists && is_writable($dir);
    printCheck(
        'Writable directory',
        $writable,
        str_replace($baseDir . DIRECTORY_SEPARATOR, '', $dir) . ($exists ? '' : ' (missing)')
    );
}

$mapsKey = preflightValue('GOOGLE_MAPS_API_KEY', '');
printCheck('Google Maps key configured', $mapsKey !== '', $mapsKey !== '' ? 'set' : 'missing');

echo PHP_EOL . "Done.\n";
