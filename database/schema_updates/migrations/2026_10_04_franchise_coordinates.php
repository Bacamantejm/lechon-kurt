<?php
/**
 * Migration: Add latitude and longitude to franchise_applications table
 * and backfill coordinates for store_locations.
 * Date: 2026-10-04
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

function getCaviteCityDefaultCoordinates(string $text): array {
    $haystack = strtolower(trim($text));
    if (strpos($haystack, 'dasma') !== false || strpos($haystack, 'salawag') !== false || strpos($haystack, 'paliparan') !== false || strpos($haystack, 'san agustin') !== false) {
        return ['lat' => 14.3294, 'lng' => 120.9367];
    }
    if (strpos($haystack, 'bacoor') !== false || strpos($haystack, 'molino') !== false || strpos($haystack, 'habay') !== false) {
        return ['lat' => 14.4445, 'lng' => 120.9439];
    }
    if (strpos($haystack, 'imus') !== false || strpos($haystack, 'anabu') !== false || strpos($haystack, 'bayan luma') !== false) {
        return ['lat' => 14.4296, 'lng' => 120.9367];
    }
    if (strpos($haystack, 'tagaytay') !== false) {
        return ['lat' => 14.1153, 'lng' => 120.9621];
    }
    if (strpos($haystack, 'trias') !== false || strpos($haystack, 'gentri') !== false || strpos($haystack, 'manggahan') !== false) {
        return ['lat' => 14.2818, 'lng' => 120.8800];
    }
    if (strpos($haystack, 'silang') !== false) {
        return ['lat' => 14.2307, 'lng' => 120.9749];
    }
    if (strpos($haystack, 'trece') !== false) {
        return ['lat' => 14.2820, 'lng' => 120.8670];
    }
    if (strpos($haystack, 'kawit') !== false) {
        return ['lat' => 14.4450, 'lng' => 120.9020];
    }
    if (strpos($haystack, 'rosario') !== false) {
        return ['lat' => 14.4167, 'lng' => 120.8500];
    }
    if (strpos($haystack, 'tanza') !== false) {
        return ['lat' => 14.3940, 'lng' => 120.8540];
    }
    if (strpos($haystack, 'naic') !== false) {
        return ['lat' => 14.3167, 'lng' => 120.7667];
    }
    if (strpos($haystack, 'carmona') !== false) {
        return ['lat' => 14.3167, 'lng' => 121.0500];
    }
    return ['lat' => 14.3294, 'lng' => 120.9367];
}

// 1. Add latitude and longitude to franchise_applications table
$col_lat = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'latitude'");
if ($col_lat && mysqli_num_rows($col_lat) === 0) {
    $alter = mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER barangay_code, ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
    if ($alter) {
        echo "Added latitude and longitude columns to franchise_applications table.\n";
    } else {
        echo "Error adding columns: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Columns latitude and longitude already exist on franchise_applications.\n";
}

// 2. Ensure store_locations has latitude and longitude
$col_store_lat = mysqli_query($conn, "SHOW COLUMNS FROM store_locations LIKE 'latitude'");
if ($col_store_lat && mysqli_num_rows($col_store_lat) === 0) {
    mysqli_query($conn, "ALTER TABLE store_locations ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER is_active, ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
    echo "Added latitude and longitude to store_locations.\n";
}

// 3. Backfill any stores in store_locations with NULL or 0 coordinates
$empty_stores = mysqli_query($conn, "SELECT store_id, store_name, address, city, province FROM store_locations WHERE latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0");
if ($empty_stores) {
    while ($store = mysqli_fetch_assoc($empty_stores)) {
        $coords = getCaviteCityDefaultCoordinates(($store['city'] ?? '') . ' ' . ($store['address'] ?? ''));
        $sid = (int)$store['store_id'];
        $up = mysqli_query($conn, "UPDATE store_locations SET latitude = {$coords['lat']}, longitude = {$coords['lng']} WHERE store_id = {$sid}");
        if ($up) {
            echo "Backfilled store #{$sid} ({$store['store_name']}) with coordinates [{$coords['lat']}, {$coords['lng']}].\n";
        }
    }
    mysqli_free_result($empty_stores);
}

echo "Franchise coordinates migration complete!\n";
