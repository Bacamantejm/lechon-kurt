<?php
/**
 * Migration: Add mobile_splash_seen tracking column to users table
 * Date: 2026-10-19
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

$res = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'mobile_splash_seen'");
if ($res && mysqli_num_rows($res) === 0) {
    $alter = mysqli_query($conn, "ALTER TABLE users ADD COLUMN mobile_splash_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER tutorial_seen_customer");
    if ($alter) {
        echo "Added users.mobile_splash_seen column.\n";
        // Mark existing users who have already logged in before as seen
        mysqli_query($conn, "UPDATE users SET mobile_splash_seen = 1 WHERE last_login IS NOT NULL");
        echo "Marked existing active users as seen.\n";
    } else {
        echo "Error adding mobile_splash_seen: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Column users.mobile_splash_seen already exists.\n";
}

echo "Mobile splash schema migration complete!\n";
