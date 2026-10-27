<?php

function dpGetDeliveryPricingConfig(): array
{
    return [
        'base_fee' => 50.0,
        'per_km_rate' => 15.0,
        'preparation_time_minutes' => 20,
        'average_speed_kmh' => 25.0,
    ];
}

/**
 * Fetch all active stores from the database.
 */
function dpFetchActiveStoresFromDb($conn = null): array
{
    if (!$conn) {
        global $conn;
    }
    if ($conn instanceof mysqli) {
        $query = "SELECT store_id AS id, store_id, owner_user_id, store_name AS name, store_name, 
                         address, city, province, phone, opening_hours AS hours, opening_hours, 
                         latitude, longitude 
                  FROM store_locations 
                  WHERE is_active = 1 
                  ORDER BY store_id ASC";
        $res = @mysqli_query($conn, $query);
        if ($res) {
            $stores = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $stores[] = $row;
            }
            return $stores;
        }
    }
    return [];
}

/**
 * Validates geographic coordinates to ensure they fall within the operational area
 * (Cavite, CALABARZON, and Metro Manila) and are not corrupted (such as 120.0000000).
 */
function dpSanitizeCoordinates($lat, $lng): ?array
{
    if ($lat === null || $lng === null) {
        return null;
    }
    if (!is_numeric((string)$lat) || !is_numeric((string)$lng)) {
        return null;
    }
    $lat = (float)$lat;
    $lng = (float)$lng;

    // Reject Null Island or uninitialized (0, 0)
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
        return null;
    }

    // Reject corrupted integer-truncated longitude 120.0000000
    if ($lng >= 119.999 && $lng <= 120.001) {
        return null;
    }

    // Cavite / CALABARZON / Metro Manila bounds check:
    // Latitude: ~14.0 to ~14.8
    // Longitude: ~120.55 to ~121.25
    if ($lat < 13.5 || $lat > 15.5 || $lng < 120.50 || $lng > 121.50) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
        'latitude' => $lat,
        'longitude' => $lng,
    ];
}


/**
 * Resolve realistic coordinates based on address text / city keywords.
 */
function dpResolveCoordinatesFromAddress(string $address, string $cityName = '', string $provinceName = ''): ?array
{
    $res = dpResolveCoordinatesRaw($address, $cityName, $provinceName);
    if ($res !== null) {
        $res['latitude'] = (float)$res['lat'];
        $res['longitude'] = (float)$res['lng'];
    }
    return $res;
}

function dpResolveCoordinatesRaw(string $address, string $cityName = '', string $provinceName = ''): ?array
{
    $combined = strtolower(trim($address . ' ' . $cityName . ' ' . $provinceName));
    if ($combined === '') {
        return null;
    }

    // Salawag / San Marino / El Salvador / Japan St / Dasmariñas East
    if (strpos($combined, 'salawag') !== false || strpos($combined, 'el salvador') !== false || strpos($combined, 'san marino') !== false || strpos($combined, 'japan st') !== false) {
        return ['lat' => 14.3248, 'lng' => 120.9806, 'city' => 'Dasmariñas (Salawag)'];
    }

    // Dasmariñas Central / Governor's Drive / Sampaloc
    if (strpos($combined, 'dasmar') !== false) {
        return ['lat' => 14.3294, 'lng' => 120.9367, 'city' => 'Dasmariñas'];
    }
    // Bacoor / Talaba / Tirona / Habay / Molino
    if (strpos($combined, 'molino') !== false) {
        return ['lat' => 14.3853, 'lng' => 120.9822, 'city' => 'Bacoor (Molino)'];
    }
    if (strpos($combined, 'bacoor') !== false) {
        return ['lat' => 14.4445, 'lng' => 120.9439, 'city' => 'Bacoor'];
    }
    // Imus / Nueno / Poblacion
    if (strpos($combined, 'imus') !== false) {
        return ['lat' => 14.4297, 'lng' => 120.9367, 'city' => 'Imus'];
    }
    // General Trias / Manggahan / Arnaldo / San Francisco
    if (strpos($combined, 'trias') !== false) {
        return ['lat' => 14.3869, 'lng' => 120.8809, 'city' => 'General Trias'];
    }
    // Silang / Biga / J.P. Rizal
    if (strpos($combined, 'silang') !== false) {
        return ['lat' => 14.2307, 'lng' => 120.9749, 'city' => 'Silang'];
    }
    // Tagaytay / Maharlika / Silang Junction
    if (strpos($combined, 'tagaytay') !== false) {
        return ['lat' => 14.1153, 'lng' => 120.9621, 'city' => 'Tagaytay'];
    }
    // Kawit
    if (strpos($combined, 'kawit') !== false) {
        return ['lat' => 14.4445, 'lng' => 120.9039, 'city' => 'Kawit'];
    }
    // Trece Martires
    if (strpos($combined, 'trece') !== false) {
        return ['lat' => 14.2831, 'lng' => 120.8672, 'city' => 'Trece Martires'];
    }
    // Tanza
    if (strpos($combined, 'tanza') !== false) {
        return ['lat' => 14.3944, 'lng' => 120.8544, 'city' => 'Tanza'];
    }
    // Naic
    if (strpos($combined, 'naic') !== false) {
        return ['lat' => 14.3167, 'lng' => 120.7667, 'city' => 'Naic'];
    }
    // Carmona
    if (strpos($combined, 'carmona') !== false) {
        return ['lat' => 14.3142, 'lng' => 121.0583, 'city' => 'Carmona'];
    }
    // GMA / General Mariano Alvarez
    if (strpos($combined, 'gma') !== false || strpos($combined, 'mariano alvarez') !== false) {
        return ['lat' => 14.3000, 'lng' => 121.0000, 'city' => 'General Mariano Alvarez'];
    }
    // Rosario
    if (strpos($combined, 'rosario') !== false) {
        return ['lat' => 14.4167, 'lng' => 120.8500, 'city' => 'Rosario'];
    }
    // Noveleta
    if (strpos($combined, 'noveleta') !== false) {
        return ['lat' => 14.4278, 'lng' => 120.8797, 'city' => 'Noveleta'];
    }
    // Cavite City
    if (strpos($combined, 'cavite city') !== false) {
        return ['lat' => 14.4833, 'lng' => 120.9000, 'city' => 'Cavite City'];
    }
    // Alfonso
    if (strpos($combined, 'alfonso') !== false) {
        return ['lat' => 14.1333, 'lng' => 120.8500, 'city' => 'Alfonso'];
    }
    // Amadeo
    if (strpos($combined, 'amadeo') !== false) {
        return ['lat' => 14.1700, 'lng' => 120.9200, 'city' => 'Amadeo'];
    }
    // Indang
    if (strpos($combined, 'indang') !== false) {
        return ['lat' => 14.1950, 'lng' => 120.8750, 'city' => 'Indang'];
    }
    // Mendez
    if (strpos($combined, 'mendez') !== false) {
        return ['lat' => 14.1289, 'lng' => 120.9033, 'city' => 'Mendez'];
    }
    // Maragondon
    if (strpos($combined, 'maragondon') !== false) {
        return ['lat' => 14.2750, 'lng' => 120.7389, 'city' => 'Maragondon'];
    }
    // Ternate
    if (strpos($combined, 'ternate') !== false) {
        return ['lat' => 14.2889, 'lng' => 120.7167, 'city' => 'Ternate'];
    }
    // Magallanes
    if (strpos($combined, 'magallanes') !== false) {
        return ['lat' => 14.1878, 'lng' => 120.7583, 'city' => 'Magallanes'];
    }
    // Bailen
    if (strpos($combined, 'bailen') !== false || strpos($combined, 'aguinaldo') !== false) {
        return ['lat' => 14.1833, 'lng' => 120.7958, 'city' => 'Gen. Emilio Aguinaldo'];
    }

    // Default Cavite center
    if (strpos($combined, 'cavite') !== false) {
        return ['lat' => 14.3294, 'lng' => 120.9367, 'city' => 'Cavite'];
    }

    return null;
}

function dpNormalizeStoreRows(array $stores): array
{
    $normalized = [];
    foreach ($stores as $store) {
        if (!is_array($store)) {
            continue;
        }

        $normalized[] = [
            'id' => (int)($store['id'] ?? $store['store_id'] ?? 0),
            'owner_user_id' => (int)($store['owner_user_id'] ?? 0),
            'name' => trim((string)($store['name'] ?? $store['store_name'] ?? 'Store')),
            'address' => trim((string)($store['address'] ?? '')),
            'city' => trim((string)($store['city'] ?? '')),
            'province' => trim((string)($store['province'] ?? '')),
            'phone' => trim((string)($store['phone'] ?? '')),
            'hours' => trim((string)($store['hours'] ?? $store['opening_hours'] ?? '')),
            'latitude' => isset($store['latitude']) && is_numeric($store['latitude']) ? (float)$store['latitude'] : null,
            'longitude' => isset($store['longitude']) && is_numeric($store['longitude']) ? (float)$store['longitude'] : null,
        ];
    }

    return $normalized;
}

function dpHaversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

    return max(0.0, $earthRadiusKm * $c);
}

function dpGetCandidateStores(array $stores, int $preferredOwnerUserId = 0): array
{
    $normalized = dpNormalizeStoreRows($stores);
    $withCoords = array_values(array_filter($normalized, static function ($store) {
        return isset($store['latitude'], $store['longitude'])
            && $store['latitude'] !== null
            && $store['longitude'] !== null
            && abs((float)$store['latitude']) > 0.0001
            && abs((float)$store['longitude']) > 0.0001;
    }));

    if ($preferredOwnerUserId > 0) {
        $preferred = array_values(array_filter($withCoords, static function ($store) use ($preferredOwnerUserId) {
            return (int)($store['owner_user_id'] ?? 0) === $preferredOwnerUserId;
        }));
        if (!empty($preferred)) {
            return $preferred;
        }
    }

    return $withCoords;
}

function dpFindNearestStore(array $stores, float $customerLat, float $customerLng, int $preferredOwnerUserId = 0): ?array
{
    $candidates = dpGetCandidateStores($stores, $preferredOwnerUserId);
    if (empty($candidates)) {
        return null;
    }

    $nearest = null;
    $minDistance = INF;
    foreach ($candidates as $store) {
        $distanceKm = dpHaversineDistanceKm(
            $customerLat,
            $customerLng,
            (float)$store['latitude'],
            (float)$store['longitude']
        );
        if ($distanceKm < $minDistance) {
            $minDistance = $distanceKm;
            $nearest = $store;
            $nearest['distance_km'] = $distanceKm;
        }
    }

    return $nearest;
}

function dpCalculateEtaWindow(float $distanceKm, array $config = []): array
{
    $config = array_merge(dpGetDeliveryPricingConfig(), $config);
    $prepMinutes = (int)($config['preparation_time_minutes'] ?? 20);
    $averageSpeed = max(1.0, (float)($config['average_speed_kmh'] ?? 25.0));

    $travelMinutes = ($distanceKm / $averageSpeed) * 60.0;
    $totalMinutes = $prepMinutes + $travelMinutes;
    $minEta = (int)(ceil($totalMinutes / 5) * 5);
    $maxEta = $minEta + 15;

    return [
        'min_minutes' => max($prepMinutes, $minEta),
        'max_minutes' => max($prepMinutes + 15, $maxEta),
        'label' => 'Estimated delivery: ' . max($prepMinutes, $minEta) . ' - ' . max($prepMinutes + 15, $maxEta) . ' minutes',
    ];
}

function dpCalculateDeliveryFeeFromDistance(float $distanceKm, array $config = []): float
{
    $config = array_merge(dpGetDeliveryPricingConfig(), $config);
    $baseFee = (float)($config['base_fee'] ?? 50.0);
    $perKmRate = (float)($config['per_km_rate'] ?? 15.0);

    return max(0.0, ceil($baseFee + ($distanceKm * $perKmRate)));
}

if (file_exists(__DIR__ . '/LalamoveService.php')) {
    require_once __DIR__ . '/LalamoveService.php';
}

function dpBuildDeliveryQuote(array $stores, ?float $customerLat = null, ?float $customerLng = null, int $preferredOwnerUserId = 0, array $config = [], string $addressFallback = ''): array
{
    global $conn;
    $config = array_merge(dpGetDeliveryPricingConfig(), $config);

    // Fallback to DB query if store list is empty
    if (empty($stores)) {
        $stores = dpFetchActiveStoresFromDb($conn);
    }

    // Sanitize customer coordinates
    $sanitized = ($customerLat !== null && $customerLng !== null)
        ? dpSanitizeCoordinates($customerLat, $customerLng)
        : null;

    if ($sanitized === null) {
        if ($addressFallback !== '') {
            $resolved = dpResolveCoordinatesFromAddress($addressFallback);
            if ($resolved) {
                $customerLat = (float)($resolved['lat'] ?? $resolved['latitude']);
                $customerLng = (float)($resolved['lng'] ?? $resolved['longitude']);
            } else {
                // Fallback to Dasmariñas center
                $customerLat = 14.3294;
                $customerLng = 120.9367;
            }
        } else {
            // Default to Dasmariñas center
            $customerLat = 14.3294;
            $customerLng = 120.9367;
        }
    } else {
        $customerLat = (float)($sanitized['lat'] ?? $sanitized['latitude']);
        $customerLng = (float)($sanitized['lng'] ?? $sanitized['longitude']);
    }

    $nearestStore = dpFindNearestStore($stores, $customerLat, $customerLng, $preferredOwnerUserId);


    if (!$nearestStore) {
        return [
            'success' => false,
            'message' => 'No active store with coordinates is available for delivery pricing.',
        ];
    }

    $distanceKm = round((float)($nearestStore['distance_km'] ?? 0), 2);
    $fee = null;
    $lalamoveUsed = false;
    $serviceType = 'MOTORCYCLE';

    // 1. Try Lalamove Real-Time API Quote if enabled in DB
    if ($conn instanceof mysqli && class_exists('LalamoveService')) {
        $lalamoveQuery = "SELECT api_key, api_secret, partner_id, is_active, sandbox_mode 
                          FROM food_delivery_integrations 
                          WHERE platform_name = 'Lalamove' AND is_active = 1 LIMIT 1";
        $lalamoveRes = @mysqli_query($conn, $lalamoveQuery);
        if ($lalamoveRes && $lalamoveRow = mysqli_fetch_assoc($lalamoveRes)) {
            $apiKey = trim((string)($lalamoveRow['api_key'] ?? ''));
            $apiSecret = trim((string)($lalamoveRow['api_secret'] ?? ''));
            $sandboxMode = !empty($lalamoveRow['sandbox_mode']);
            $serviceType = trim((string)($lalamoveRow['partner_id'] ?? 'MOTORCYCLE')) ?: 'MOTORCYCLE';

            if (!empty($apiKey) && !empty($apiSecret)) {
                $lalamove = new LalamoveService($apiKey, $apiSecret, $sandboxMode, 'PH');
                $storeAddress = trim(implode(', ', array_filter([
                    (string)($nearestStore['name'] ?? ''),
                    (string)($nearestStore['address'] ?? ''),
                    (string)($nearestStore['city'] ?? ''),
                ]))) ?: 'Store Location';

                $quoteResult = $lalamove->getQuotation(
                    (float)$nearestStore['latitude'],
                    (float)$nearestStore['longitude'],
                    $customerLat,
                    $customerLng,
                    $storeAddress,
                    'Customer Delivery Location',
                    $serviceType
                );

                if (!empty($quoteResult['success']) && is_numeric($quoteResult['fee'])) {
                    $fee = (float)$quoteResult['fee'];
                    $lalamoveUsed = true;
                    if (!empty($quoteResult['distance_km'])) {
                        $distanceKm = (float)$quoteResult['distance_km'];
                    }
                }
            }
        }
    }

    // 2. Fallback to distance-based calculation
    if ($fee === null) {
        $fee = dpCalculateDeliveryFeeFromDistance($distanceKm, $config);
    }

    $eta = dpCalculateEtaWindow($distanceKm, $config);
    $providerLabel = $lalamoveUsed ? 'Lalamove Express (' . htmlspecialchars($serviceType) . ')' : (string)($nearestStore['name'] ?? 'Nearest Store');

    return [
        'success' => true,
        'fee' => $fee,
        'distance_km' => $distanceKm,
        'customer_lat' => $customerLat,
        'customer_lng' => $customerLng,
        'lalamove_used' => $lalamoveUsed,
        'nearest_store_id' => (int)($nearestStore['id'] ?? 0),
        'nearest_store_name' => (string)($nearestStore['name'] ?? 'Nearest Store'),
        'nearest_store_address' => trim(implode(', ', array_filter([
            (string)($nearestStore['address'] ?? ''),
            (string)($nearestStore['city'] ?? ''),
            (string)($nearestStore['province'] ?? ''),
        ]))),
        'delivery_details' => 'Delivery via ' . $providerLabel . ' (' . number_format($distanceKm, 1) . ' km)',
        'eta_min_minutes' => (int)$eta['min_minutes'],
        'eta_max_minutes' => (int)$eta['max_minutes'],
        'estimated_delivery_text' => (string)$eta['label'],
        'config' => $config,
    ];
}
