<?php
use Twilio\Rest\Client;

class SmsService {
    private $client;
    private $twilio_phone_number;
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;

        // Check if Twilio SDK is loaded. If not, SMS sending will be disabled.
        if (!class_exists('Twilio\Rest\Client')) {
            error_log("Twilio SDK not found. SMS sending is disabled. Please run 'composer install' in your project root.");
            $this->client = null;
            return;
        }

        // Check if constants are defined and not placeholders
        if (!defined('TWILIO_ACCOUNT_SID') || strpos(TWILIO_ACCOUNT_SID, 'ACxxx') === 0 ||
            !defined('TWILIO_AUTH_TOKEN') || TWILIO_AUTH_TOKEN === 'your_auth_token' ||
            !defined('TWILIO_PHONE_NUMBER')) {
            
            error_log("Twilio credentials are not configured in includes/config.php. SMS not sent.");
            $this->client = null;
            return;
        }
        
        $sid = TWILIO_ACCOUNT_SID;
        $token = TWILIO_AUTH_TOKEN;
        $this->twilio_phone_number = TWILIO_PHONE_NUMBER;
        
        try {
            $this->client = new Client($sid, $token);
        } catch (\Twilio\Exceptions\ConfigurationException $e) {
            error_log("Twilio Configuration Error: " . $e->getMessage());
            $this->client = null;
        }
    }

    public function send($to, $message) {
        if (!$this->client) {
            $this->logSms('SYSTEM', 'send_sms_failed', ['phone' => $to, 'message' => $message], false, 'Twilio client not initialized or configured.');
            return false;
        }

        try {
            // Ensure phone number is in E.164 format
            $formatted_to = $this->formatPhoneNumber($to);

            $this->client->messages->create(
                $formatted_to,
                [
                    'from' => $this->twilio_phone_number,
                    'body' => $message
                ]
            );
            
            $this->logSms('Twilio', 'send_sms', ['phone' => $formatted_to, 'message' => $message], true, '');
            return true;
        } catch (Exception $e) {
            error_log("SMS sending failed to $to: " . $e->getMessage());
            $this->logSms('Twilio', 'send_sms_failed', ['phone' => $to, 'message' => $message], false, $e->getMessage());
            return false;
        }
    }

    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '09') {
            return '+63' . substr($phone, 1);
        }
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '9') {
            return '+63' . $phone;
        }
        return $phone; // Assume it's already formatted if it doesn't match
    }

    private function logSms($provider, $type, $data, $success, $error = '') {
        if (!$this->conn) return;
        $log_query = "INSERT INTO logistics_api_logs (provider_name, request_type, request_data, success, error_message) VALUES (?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($this->conn, $log_query);
        $request_data = json_encode($data);
        mysqli_stmt_bind_param($stmt, "sssis", $provider, $type, $request_data, $success, $error);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}