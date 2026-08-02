<?php
class PayMongoIntegration {
    private $secretKey;
    private $publicKey;
    private $baseUrl = 'https://api.paymongo.com/v1';
    
    public function __construct($secretKey, $publicKey) {
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
    }
    
    private function makeRequest($endpoint, $data = null, $method = 'POST') {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init();
        
        $headers = [
            'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Lechon Delights');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Add timeout to prevent hanging
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Handle SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log cURL errors for debugging
        if ($curlError) {
            error_log("cURL Error on $method $endpoint: " . $curlError);
        }
        
        return [
            'status' => $httpCode,
            'data' => json_decode($response, true),
            'error' => $curlError
        ];
    }
    
    public function createCheckoutSession($orderData) {
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => intval($orderData['amount'] * 100), // Convert to centavos
                        'name' => $orderData['description'],
                        'quantity' => 1
                    ]],
                    'payment_method_types' => $this->getPaymentMethods($orderData['payment_method']),
                    'description' => $orderData['description'],
                    'reference_number' => (string)$orderData['order_id'], // Convert to string
                    'customer' => [
                        'name' => $orderData['customer_name'],
                        'email' => $orderData['customer_email'],
                        'phone' => $orderData['customer_phone']
                    ],
                    'success_url' => $orderData['success_url'],
                    'cancel_url' => $orderData['cancel_url'],
                    'billing' => [
                        'name' => $orderData['customer_name'],
                        'email' => $orderData['customer_email'],
                        'phone' => $orderData['customer_phone']
                    ]
                ]
            ]
        ];
        
        error_log("PayMongo Request: " . json_encode($payload));
        
        $result = $this->makeRequest('/checkout_sessions', $payload);
        
        error_log("PayMongo Response Status: " . $result['status']);
        error_log("PayMongo Response Data: " . json_encode($result['data']));
        
        if ($result['status'] === 200 && isset($result['data']['data']['attributes']['checkout_url'])) {
            return [
                'success' => true,
                'checkout_url' => $result['data']['data']['attributes']['checkout_url'],
                'session_id' => $result['data']['data']['id']
            ];
        } else {
            $errorMsg = 'Unknown error';
            if (isset($result['data']['errors']) && is_array($result['data']['errors'])) {
                $errorMsg = $result['data']['errors'][0]['detail'] ?? 'API Error';
            } elseif ($result['error']) {
                $errorMsg = $result['error'];
            }
            error_log("PayMongo Error: " . $errorMsg);
            return [
                'success' => false,
                'error' => $errorMsg
            ];
        }
    }
    
    private function getPaymentMethods($method) {
        switch($method) {
            case 'gcash':
                return ['gcash'];
            case 'paymaya':
                return ['paymaya'];
            case 'card':
                return ['card'];
            default:
                return ['card', 'gcash', 'paymaya'];
        }
    }
    
    public function verifyPayment($sessionId) {
        // For demo/testing - return success
        // Remove this in production
        return [
            'success' => true,
            'status' => 'paid',
            'session_data' => [
                'id' => $sessionId,
                'attributes' => [
                    'payments' => [
                        [
                            'attributes' => [
                                'status' => 'paid',
                                'paid_at' => date('c')
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        /* Production code (uncomment when ready):
        $result = $this->makeRequest('/checkout_sessions/' . $sessionId, null, 'GET');
        
        if ($result['status'] === 200) {
            $session = $result['data']['data'];
            $paymentStatus = $session['attributes']['payments'][0]['attributes']['status'] ?? 'pending';
            
            return [
                'success' => true,
                'status' => $paymentStatus,
                'session_data' => $session
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to verify payment'];
        */
    }
    
    public function createPaymentLink($amount, $description, $reference) {
        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => intval($amount * 100),
                    'description' => $description,
                    'remarks' => $reference,
                    'currency' => 'PHP'
                ]
            ]
        ];
        
        $result = $this->makeRequest('/links', $payload);
        
        if ($result['status'] === 200 && isset($result['data']['data']['attributes']['checkout_url'])) {
            return [
                'success' => true,
                'checkout_url' => $result['data']['data']['attributes']['checkout_url'],
                'link_id' => $result['data']['data']['id']
            ];
        } else {
            error_log("PayMongo Link Error: " . json_encode($result));
            return [
                'success' => false,
                'error' => $result['data']['errors'][0]['detail'] ?? 'Unknown error'
            ];
        }
    }
    
    public function retrieveCheckoutSession($sessionId) {
        $result = $this->makeRequest('/checkout_sessions/' . $sessionId, null, 'GET');
        
        if ($result['status'] === 200) {
            $session = $result['data']['data'];
            $paymentStatus = 'pending';
            
            // Check if payment was successful
            if (isset($session['attributes']['payments']) && count($session['attributes']['payments']) > 0) {
                $paymentStatus = $session['attributes']['payments'][0]['attributes']['status'] ?? 'pending';
            }
            
            return [
                'success' => true,
                'status' => $paymentStatus,
                'session_data' => $session
            ];
        }
        
        error_log("Failed to retrieve checkout session: " . json_encode($result));
        return ['success' => false, 'error' => 'Failed to retrieve session'];
    }
}
?>