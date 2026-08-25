<?php

namespace App\Services;

class DeliveryPricingService
{
    /**
     * Cavite Bounding Box Boundaries
     */
    protected const CAVITE_BOUNDS = [
        'min_lat' => 14.0500,
        'max_lat' => 14.5000,
        'min_lng' => 120.5500,
        'max_lng' => 121.0500,
    ];

    /**
     * Validate if coordinates fall strictly inside Cavite Province
     */
    public function isWithinCavite(float $lat, float $lng): bool
    {
        return ($lat >= self::CAVITE_BOUNDS['min_lat'] && $lat <= self::CAVITE_BOUNDS['max_lat']) &&
               ($lng >= self::CAVITE_BOUNDS['min_lng'] && $lng <= self::CAVITE_BOUNDS['max_lng']);
    }

    /**
     * Calculate Great-Circle distance in kilometers between two geo-coordinates
     */
    public function calculateDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadiusKm * $c, 2);
    }

    /**
     * Calculate delivery fee based on distance and order characteristics
     */
    public function calculateDeliveryFee(float $distanceKm, float $orderSubtotal = 0.0): float
    {
        $baseFee = 59.00; // First 3 km
        $ratePerKm = 12.00; // Per km after 3 km

        if ($distanceKm <= 3.0) {
            $fee = $baseFee;
        } else {
            $fee = $baseFee + (($distanceKm - 3.0) * $ratePerKm);
        }

        // Free delivery on orders over ₱3,500
        if ($orderSubtotal >= 3500.00) {
            return 0.00;
        }

        return round($fee, 2);
    }
}
