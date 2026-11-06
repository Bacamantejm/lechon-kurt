<?php
/**
 * Migration: Auto-repair corrupted letter encoding (Dasmari├▒as, Dasmari├▒a, ñ, etc.)
 * Date: 2026-11-06
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

if (!isset($conn) || !$conn) {
    die("Database connection failed.\n");
}

mysqli_set_charset($conn, 'utf8mb4');

echo "Starting comprehensive mojibake repair migration...\n";

// 1. Orders
if (function_exists('appTableExists') && appTableExists($conn, 'orders')) {
    @mysqli_query($conn, "UPDATE orders SET delivery_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(delivery_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), 'DasmariÃ±as', 'Dasmariñas'), 'DasmariÃ±a', 'Dasmariñas'), 'Dasmari├æas', 'Dasmariñas'), 'DasmariÃ‘as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ'), 'Ã©', 'é') WHERE delivery_address LIKE '%├%' OR delivery_address LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE orders SET customer_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ') WHERE customer_name LIKE '%├%' OR customer_name LIKE '%Ã%'");
    if (appColumnExists($conn, 'orders', 'shipping_address')) {
        @mysqli_query($conn, "UPDATE orders SET shipping_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(shipping_address, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ') WHERE shipping_address LIKE '%├%' OR shipping_address LIKE '%Ã%'");
    }
    if (appColumnExists($conn, 'orders', 'billing_address')) {
        @mysqli_query($conn, "UPDATE orders SET billing_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(billing_address, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ') WHERE billing_address LIKE '%├%' OR billing_address LIKE '%Ã%'");
    }
    echo "Repaired orders.\n";
}

// 2. Logistics Tracking
if (function_exists('appTableExists') && appTableExists($conn, 'logistics_tracking')) {
    @mysqli_query($conn, "UPDATE logistics_tracking SET delivery_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(delivery_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), 'DasmariÃ±as', 'Dasmariñas'), 'DasmariÃ±a', 'Dasmariñas'), 'Dasmari├æas', 'Dasmariñas'), 'DasmariÃ‘as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ'), 'Ã©', 'é') WHERE delivery_address LIKE '%├%' OR delivery_address LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE logistics_tracking SET customer_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ') WHERE customer_name LIKE '%├%' OR customer_name LIKE '%Ã%'");
    if (appColumnExists($conn, 'logistics_tracking', 'driver_name')) {
        @mysqli_query($conn, "UPDATE logistics_tracking SET driver_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(driver_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), '├æ', 'Ñ'), 'Ã±', 'ñ'), 'Ã‘', 'Ñ') WHERE driver_name LIKE '%├%' OR driver_name LIKE '%Ã%'");
    }
    echo "Repaired logistics_tracking.\n";
}

// 3. Store Locations
if (function_exists('appTableExists') && appTableExists($conn, 'store_locations')) {
    @mysqli_query($conn, "UPDATE store_locations SET store_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(store_name, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE store_name LIKE '%├%' OR store_name LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE store_locations SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE store_locations SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas'");
    echo "Repaired store_locations.\n";
}

// 4. User Saved Addresses
if (function_exists('appTableExists') && appTableExists($conn, 'user_saved_addresses')) {
    @mysqli_query($conn, "UPDATE user_saved_addresses SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
    if (appColumnExists($conn, 'user_saved_addresses', 'street_address')) {
        @mysqli_query($conn, "UPDATE user_saved_addresses SET street_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(street_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE street_address LIKE '%├%' OR street_address LIKE '%Ã%'");
    }
    @mysqli_query($conn, "UPDATE user_saved_addresses SET city = 'Dasmariñas' WHERE (city LIKE 'Dasmari%as%' OR city LIKE 'Dasmari%a%' OR city LIKE '%Dasmari%') AND city <> 'Dasmariñas'");
    if (appColumnExists($conn, 'user_saved_addresses', 'city_name')) {
        @mysqli_query($conn, "UPDATE user_saved_addresses SET city_name = 'Dasmariñas' WHERE (city_name LIKE 'Dasmari%as%' OR city_name LIKE 'Dasmari%a%' OR city_name LIKE '%Dasmari%') AND city_name <> 'Dasmariñas'");
    }
    echo "Repaired user_saved_addresses.\n";
}

// 5. Users
if (function_exists('appTableExists') && appTableExists($conn, 'users')) {
    @mysqli_query($conn, "UPDATE users SET full_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(full_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ'), 'Ã‘', 'Ñ') WHERE full_name LIKE '%├%' OR full_name LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE users SET business_name = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(business_name, 'Dasmari├▒as', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ'), 'Ã‘', 'Ñ') WHERE business_name LIKE '%├%' OR business_name LIKE '%Ã%'");
    @mysqli_query($conn, "UPDATE users SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
    echo "Repaired users.\n";
}

// 6. Franchise Applications
if (function_exists('appTableExists') && appTableExists($conn, 'franchise_applications')) {
    @mysqli_query($conn, "UPDATE franchise_applications SET address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE address LIKE '%├%' OR address LIKE '%Ã%'");
    if (appColumnExists($conn, 'franchise_applications', 'business_address')) {
        @mysqli_query($conn, "UPDATE franchise_applications SET business_address = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(business_address, 'Dasmari├▒as', 'Dasmariñas'), 'Dasmari├▒a', 'Dasmariñas'), '├▒', 'ñ'), '├░', 'ñ'), 'Ã±', 'ñ'), '├æ', 'Ñ') WHERE business_address LIKE '%├%' OR business_address LIKE '%Ã%'");
    }
    @mysqli_query($conn, "UPDATE franchise_applications SET city_name = 'City of Dasmariñas' WHERE (city_name LIKE 'City of Dasmari%' OR city_name LIKE '%Dasmari%') AND city_name <> 'City of Dasmariñas'");
    echo "Repaired franchise_applications.\n";
}

echo "Comprehensive mojibake character repair migration complete!\n";
