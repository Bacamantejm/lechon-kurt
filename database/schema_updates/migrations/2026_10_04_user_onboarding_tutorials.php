<?php
/**
 * Migration: Add onboarding tutorial tracking columns to users table
 * Date: 2026-10-04
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

// 1. Add tutorial_seen_customer column
$res = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'tutorial_seen_customer'");
if ($res && mysqli_num_rows($res) === 0) {
    $alter1 = mysqli_query($conn, "ALTER TABLE users ADD COLUMN tutorial_seen_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    if ($alter1) {
        echo "Added users.tutorial_seen_customer column.\n";
    } else {
        echo "Error adding tutorial_seen_customer: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Column users.tutorial_seen_customer already exists.\n";
}

// 2. Add tutorial_seen_shop column
$res = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'tutorial_seen_shop'");
if ($res && mysqli_num_rows($res) === 0) {
    $alter2 = mysqli_query($conn, "ALTER TABLE users ADD COLUMN tutorial_seen_shop TINYINT(1) NOT NULL DEFAULT 0 AFTER tutorial_seen_customer");
    if ($alter2) {
        echo "Added users.tutorial_seen_shop column.\n";
    } else {
        echo "Error adding tutorial_seen_shop: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Column users.tutorial_seen_shop already exists.\n";
}

echo "Onboarding tutorial migration complete!\n";
