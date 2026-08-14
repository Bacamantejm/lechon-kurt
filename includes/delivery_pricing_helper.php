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
            && $store['longitude'] !== null;
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

require_once __DIR__ . '/LalamoveService.php';

function dpBuildDeliveryQuote(array $stores, float $customerLat, float $customerLng, int $preferredOwnerUserId = 0, array $config = []): array
{
    global $conn;
    $config = array_merge(dpGetDeliveryPricingConfig(), $config);
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
    if ($conn instanceof mysqli) {
        $lalamoveQuery = "SELECT api_key, api_secret, partner_id, is_active, sandbox_mode FROM food_delivery_integrations WHERE platform_name = 'Lalamove' AND is_active = 1 LIMIT 1";
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

    // 2. Fallback to distance-based calculation if Lalamove API is inactive or unavailable
    if ($fee === null) {
        $fee = dpCalculateDeliveryFeeFromDistance($distanceKm, $config);
    }

    $eta = dpCalculateEtaWindow($distanceKm, $config);
    $providerLabel = $lalamoveUsed ? 'Lalamove Express (' . htmlspecialchars($serviceType) . ')' : (string)($nearestStore['name'] ?? 'Nearest Store');

    return [
        'success' => true,
        'fee' => $fee,
        'distance_km' => $distanceKm,
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

