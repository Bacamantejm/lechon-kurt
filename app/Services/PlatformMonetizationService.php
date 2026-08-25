<?php

namespace App\Services;

use App\Models\PartnerSubscription;
use Carbon\Carbon;

class PlatformMonetizationService
{
    public const TIERS = [
        'starter' => [
            'name' => 'Starter Partner',
            'price_monthly' => 999.00,
            'price_annual' => 9990.00,
            'max_products' => 15,
            'commission_rate' => 0.08,
            'badge' => 'Verified Starter',
        ],
        'growth' => [
            'name' => 'Growth Merchant',
            'price_monthly' => 2499.00,
            'price_annual' => 24990.00,
            'max_products' => 50,
            'commission_rate' => 0.05,
            'badge' => 'Top Seller',
        ],
        'pro' => [
            'name' => 'Enterprise Lechon Brand',
            'price_monthly' => 4999.00,
            'price_annual' => 49990.00,
            'max_products' => 200,
            'commission_rate' => 0.03,
            'badge' => 'Master Roaster',
        ],
    ];

    /**
     * Compute subscription expiration and renewal schedule
     */
    public function calculateBillingSchedule(string $cycle): array
    {
        $startDate = Carbon::today();
        $endDate = $cycle === 'annual' 
            ? $startDate->copy()->addYear() 
            : $startDate->copy()->addMonth();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_billing_date' => $endDate->copy()->subDays(3),
        ];
    }
}
