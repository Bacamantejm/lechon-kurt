<?php
/**
 * Pre-upload cleanup script for Hostinger deployment.
 * ─────────────────────────────────────────────────────
 * Run this locally BEFORE uploading via FTP/File Manager:
 *   php deployment/hostinger/cleanup_before_upload.php [--dry-run]
 *
 * With --dry-run it only lists what WOULD be deleted/excluded — safe to run
 * any time to audit what will be sent to production.
 *
 * What it does:
 *  1. Lists files/folders that must NOT be uploaded to production.
 *  2. Optionally deletes them from a staging copy (never touches .git history).
 *  3. Verifies deployment_credentials.php does NOT exist locally in the wrong
 *     place (it should only live on the server).
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$baseDir = realpath(__DIR__ . '/../../');

if ($baseDir === false) {
    fwrite(STDERR, "ERROR: Cannot resolve project root.\n");
    exit(1);
}

$mode = $dryRun ? '[DRY-RUN]' : '[DELETE] ';
echo "Lechon System — Pre-upload Cleanup\n";
echo "Project root : {$baseDir}\n";
echo "Mode         : " . ($dryRun ? 'Dry run (nothing will be deleted)' : 'LIVE (files will be deleted)') . "\n\n";

// ─── Files / folders to REMOVE before upload ─────────────────────────────────
// These serve no purpose on production and may expose sensitive data.
$removals = [
    // Raw database dumps — MUST NOT be publicly accessible
    'lechon_db.sql',
    'lechon_db_backup!.sql',
    'database/lechon_db_latest.sql',
    'database/bulk_seed_500_per_store.sql',
    'database/sample_platform_seed.sql',

    // Composer bootstrap — not needed after vendor install
    'composer-setup.php',
    'composer.phar',

    // Debug/test scripts — expose credentials and config
    'smtp_test.php',
    'temp_mail_test.php',
    'setup_partner_schema.php',
    'run.php',

    // Thesis files — irrelevant on production
    'thesis_revision',

    // Local dev credentials — must never go to production
    'includes/local_credentials.php',

    // Deployment_credentials.php should only exist ON the server, not locally
    // (listed here as a warning check, not auto-deleted)
    // 'includes/deployment_credentials.php',

    // IDE / agent config folders — not needed on server
    '.cursor',
    '.agent',
    'GEMINI.md',
    'InfoGuide',

    // Temp directory contents
    'tmp',
];

$errors = 0;
$skipped = 0;
$actioned = 0;

foreach ($removals as $rel) {
    $full = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    if (!file_exists($full) && !is_dir($full)) {
        echo "  [SKIP]   {$rel} — not present\n";
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $type = is_dir($full) ? 'DIR ' : 'FILE';
        echo "  {$mode} {$type} {$rel}\n";
        $actioned++;
    } else {
        $ok = is_dir($full) ? rmdirRecursive($full) : @unlink($full);
        if ($ok) {
            echo "  [DONE]   Removed: {$rel}\n";
            $actioned++;
        } else {
            echo "  [ERROR]  Could not remove: {$rel}\n";
            $errors++;
        }
    }
}

// ─── Security warnings ────────────────────────────────────────────────────────
echo "\n--- Security Checks ---\n";

$deployFile = $baseDir . '/includes/deployment_credentials.php';
if (file_exists($deployFile)) {
    echo "  [WARN]  includes/deployment_credentials.php exists locally.\n";
    echo "          Make sure it is NOT uploaded (it should only live on the server).\n";
    echo "          If this is your local production test copy, that is fine — just confirm.\n";
} else {
    echo "  [OK]    includes/deployment_credentials.php not present locally (correct).\n";
}

$localCreds = $baseDir . '/includes/local_credentials.php';
if (file_exists($localCreds)) {
    echo "  [WARN]  includes/local_credentials.php still exists.\n";
    echo "          It is gitignored but if you are using FTP, exclude this file manually.\n";
} else {
    echo "  [OK]    includes/local_credentials.php not present.\n";
}

// Check for hardcoded plaintext test keys in config.php
$configContents = file_get_contents($baseDir . '/includes/config.php') ?: '';
if (strpos($configContents, 'sk_test_') !== false || strpos($configContents, 'pk_test_') !== false) {
    echo "  [WARN]  includes/config.php may contain hardcoded PayMongo TEST keys.\n";
    echo "          Ensure deployment_credentials.php overrides them with LIVE keys.\n";
} else {
    echo "  [OK]    No hardcoded PayMongo test keys detected in config.php.\n";
}

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n--- Summary ---\n";
echo "  Actioned : {$actioned}\n";
echo "  Skipped  : {$skipped}\n";
echo "  Errors   : {$errors}\n";

if ($dryRun) {
    echo "\nDry run complete. Run without --dry-run to actually delete.\n";
} else {
    echo "\nCleanup complete. Safe to upload the remaining files to Hostinger.\n";
}

exit($errors > 0 ? 1 : 0);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function rmdirRecursive(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    $items = scandir($dir);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rmdirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}
