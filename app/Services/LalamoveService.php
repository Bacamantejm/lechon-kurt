<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LalamoveService
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $baseUrl;
    protected string $market;

    public function __construct()
    {
        $this->apiKey = config('services.lalamove.key', env('LALAMOVE_API_KEY', ''));
        $this->apiSecret = config('services.lalamove.secret', env('LALAMOVE_API_SECRET', ''));
        $this->market = config('services.lalamove.market', env('LALAMOVE_MARKET', 'PH'));
        $isSandbox = config('services.lalamove.sandbox', env('LALAMOVE_SANDBOX', true));
        $this->baseUrl = $isSandbox 
            ? 'https://rest.sandbox.lalamove.com' 
            : 'https://rest.lalamove.com';
    }

    /**
     * Generate HMAC-SHA256 signature for Lalamove REST API v3
     */
    protected function generateSignature(string $method, string $path, string $timestamp, string $body = ''): string
    {
        $rawSignature = "{$timestamp}\r\n{$method}\r\n{$path}\r\n\r\n{$body}";
        return hash_hmac('sha256', $rawSignature, $this->apiSecret);
    }

    /**
     * Get real-time delivery quotation between store branch and customer address
     */
    public function getQuotation(array $pickup, array $dropoff, string $serviceType = 'MOTORCYCLE'): array
    {
        $path = '/v3/quotations';
        $timestamp = (string)(round(microtime(true) * 1000));
        
        $payload = [
            'data' => [
                'serviceType' => $serviceType,
                'specialRequests' => [],
                'language' => 'en_PH',
                'stops' => [
                    [
                        'coordinates' => [
                            'lat' => (string)$pickup['lat'],
                            'lng' => (string)$pickup['lng']
                        ],
                        'address' => $pickup['address']
                    ],
                    [
                        'coordinates' => [
                            'lat' => (string)$dropoff['lat'],
                            'lng' => (string)$dropoff['lng']
                        ],
                        'address' => $dropoff['address']
                    ]
                ]
            ]
        ];

        $jsonBody = json_encode($payload);
        $signature = $this->generateSignature('POST', $path, $timestamp, $jsonBody);

        try {
            $response = Http::withHeaders([
                'Authorization' => "hmac {$this->apiKey}:{$timestamp}:{$signature}",
                'Market' => $this->market,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(15)->post("{$this->baseUrl}{$path}", $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data')
                ];
            }

            Log::warning('Lalamove Quotation Failed', ['status' => $response->status(), 'response' => $response->body()]);
            return [
                'success' => false,
                'error' => $response->json('message', 'Quotation service error')
            ];
        } catch (\Exception $e) {
            Log::error('Lalamove Quotation Exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
