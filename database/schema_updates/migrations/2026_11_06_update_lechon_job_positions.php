<?php
/**
 * Migration: Modernize job positions into authentic lechon shop roles
 * (Lechon Roaster / Litsonero, Service Crew, Kitchen Helper, Delivery Rider)
 * and purge obsolete generic test roles (Software Developer, Project Manager, Hourly Staff, Staff).
 * Date: 2026-11-06
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting migration: update job positions to authentic lechon roles...\n";

if (function_exists('ensureLechonShopJobPositions')) {
    ensureLechonShopJobPositions($conn);
    echo "Executed ensureLechonShopJobPositions successfully.\n";
}

echo "Migration completed successfully!\n";
