<?php

class LalamoveService
{
    private string $apiKey;
    private string $apiSecret;
    private string $market;
    private bool $sandboxMode;
    private string $baseUrl;

    public function __construct(string $apiKey = '', string $apiSecret = '', bool $sandboxMode = true, string $market = 'PH')
    {
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->sandboxMode = $sandboxMode;
        $this->market = strtoupper(trim($market ?: 'PH'));
        $this->baseUrl = $sandboxMode ? 'https://rest.sandbox.lalamove.com' : 'https://rest.lalamove.com';
    }

    /**
     * Get active configuration from database or fallback to environment/defaults
     */
    public static function createFromDatabase(?mysqli $conn = null): self
    {
        $apiKey = '';
        $apiSecret = '';
        $sandboxMode = true;

        if ($conn instanceof mysqli) {
            $query = "SELECT api_key, api_secret, is_active, sandbox_mode FROM food_delivery_integrations WHERE platform_name = 'Lalamove' LIMIT 1";
            $res = @mysqli_query($conn, $query);
            if ($res && $row = mysqli_fetch_assoc($res)) {
                $apiKey = (string)($row['api_key'] ?? '');
                $apiSecret = (string)($row['api_secret'] ?? '');
                $sandboxMode = !empty($row['sandbox_mode']);
            }
        }

        return new self($apiKey, $apiSecret, $sandboxMode, 'PH');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * Generate HMAC-SHA256 signature for Lalamove API v3
     */
    private function generateSignature(int $timestamp, string $method, string $path, string $body = ''): string
    {
        $rawSignature = "{$timestamp}\r\n{$method}\r\n{$path}\r\n\r\n{$body}";
        return hash_hmac('sha256', $rawSignature, $this->apiSecret);
    }

    /**
     * Fetch real-time Lalamove quotation for delivery fee calculation
     */
    public function getQuotation(
        float $pickupLat,
        float $pickupLng,
        float $deliveryLat,
        float $deliveryLng,
        string $pickupAddress = 'Store Location',
        string $deliveryAddress = 'Customer Address',
        string $serviceType = 'MOTORCYCLE'
    ): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Lalamove API credentials are not configured.',
                'fee' => null,
            ];
        }

        $path = '/v3/quotations';
        $url = $this->baseUrl . $path;
        $timestamp = (int)round(microtime(true) * 1000);

        $payloadData = [
            'data' => [
                'serviceType' => strtoupper(trim($serviceType ?: 'MOTORCYCLE')),
                'stops' => [
                    [
                        'coordinates' => [
                            'lat' => sprintf('%.6f', $pickupLat),
                            'lng' => sprintf('%.6f', $pickupLng),
                        ],
                        'address' => trim($pickupAddress ?: 'Store Location'),
                    ],
                    [
                        'coordinates' => [
                            'lat' => sprintf('%.6f', $deliveryLat),
                            'lng' => sprintf('%.6f', $deliveryLng),
                        ],
                        'address' => trim($deliveryAddress ?: 'Customer Address'),
                    ],
                ],
            ],
        ];

        $body = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = $this->generateSignature($timestamp, 'POST', $path, $body);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: hmac ' . $this->apiKey . ':' . $timestamp . ':' . $signature,
            'Market: ' . $this->market,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'success' => false,
                'message' => 'Lalamove API connection error: ' . $curlErr,
                'fee' => null,
            ];
        }

        $json = json_decode($response, true);

        if (($httpCode === 200 || $httpCode === 201) && isset($json['data'])) {
            $data = $json['data'];
            $totalFee = (float)($data['priceBreakdown']['total'] ?? 0.0);
            $currency = (string)($data['priceBreakdown']['currency'] ?? 'PHP');

            // Distance in meters converted to kilometers
            $distanceMeters = (float)($data['distance']['value'] ?? 0);
            $distanceKm = $distanceMeters > 0 ? round($distanceMeters / 1000.0, 2) : null;

            return [
                'success' => true,
                'fee' => $totalFee,
                'currency' => $currency,
                'distance_km' => $distanceKm,
                'quotation_id' => (string)($data['quotationId'] ?? ''),
                'stops' => $data['stops'] ?? [],
                'breakdown' => $data['priceBreakdown'] ?? [],
                'raw' => $data,
            ];
        }

        $errorMsg = $json['message'] ?? $json['errors'][0]['message'] ?? 'Failed to retrieve Lalamove quote (HTTP ' . $httpCode . ')';
        return [
            'success' => false,
            'message' => $errorMsg,
            'fee' => null,
            'http_code' => $httpCode,
            'raw' => $json,
        ];
    }
}
