<?php
/**
 * Data Validation Utility Class
 * Provides centralized validation for order and logistics data
 */

class OrderValidator {
    public static function validateDeliveryAddress($address) {
        return !empty(trim($address)) && strlen($address) >= 10 && strlen($address) <= 500;
    }
    
    public static function validatePhoneNumber($phone) {
        // Philippine format: 0xxxxxxxxxx or +63xxxxxxxxx or xxxxxxxxxx
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return preg_match('/^(\+63|63|0)?9\d{9}$/', $phone) === 1;
    }
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function validateCoordinates($latitude, $longitude) {
        return is_numeric($latitude) && is_numeric($longitude) &&
               $latitude >= -90 && $latitude <= 90 &&
               $longitude >= -180 && $longitude <= 180;
    }
    
    public static function validateDeliveryOption($option) {
        return in_array($option, ['delivery', 'pickup']);
    }
    
    public static function validatePaymentType($type) {
        return in_array($type, ['full', 'downpayment']);
    }
    
    public static function validateDeliveryCondition($condition) {
        return in_array($condition, ['good', 'minor_damage', 'major_damage', 'incomplete', 'other']);
    }
    
    public static function sanitizeOrderNotes($notes) {
        return trim(htmlspecialchars(substr($notes, 0, 500)));
    }
    
    public static function validateOrderAmount($amount) {
        return is_numeric($amount) && floatval($amount) > 0 && floatval($amount) <= 999999.99;
    }
}

/**
 * Logistics Service Helper Class
 * Additional utility methods for logistics operations
 */

class LogisticsHelper {
    const AVG_DELIVERY_SPEED_KMH = 25;

    /**
     * Calculate estimated delivery time based on distance and current conditions
     */
    public static function calculateEstimatedDeliveryTime($distance_km, $preparation_minutes = 20) {
        // Average delivery speed: 25 km/h in traffic
        $avg_speed = self::AVG_DELIVERY_SPEED_KMH;
        $delivery_minutes = ceil(($distance_km / $avg_speed) * 60);
        $total_minutes = $preparation_minutes + $delivery_minutes;
        
        return date('Y-m-d H:i:s', strtotime("+$total_minutes minutes"));
    }
    
    /**
     * Format tracking status for display
     */
    public static function formatStatus($status) {
        $status_map = [
            'pending' => 'Pending Assignment',
            'assigned' => 'Driver Assigned',
            'picked_up' => 'Picked Up',
            'on_the_way' => 'On The Way',
            'arriving' => 'Arriving Soon',
            'delivered' => 'Delivered',
            'failed' => 'Delivery Failed',
            'cancelled' => 'Cancelled'
        ];
        
        return $status_map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
    
    /**
     * Get status color for UI
     */
    public static function getStatusColor($status) {
        $colors = [
            'pending' => '#6c757d',
            'assigned' => '#0d6efd',
            'picked_up' => '#0dcaf0',
            'on_the_way' => '#ffc107',
            'arriving' => '#198754',
            'delivered' => '#198754',
            'failed' => '#dc3545',
            'cancelled' => '#6c757d'
        ];
        
        return $colors[$status] ?? '#999';
    }
    
    /**
     * Generate tracking URL for customer
     */
    public static function generateTrackingUrl($order_id, $tracking_token = null) {
        $base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/track_order.php?order_id=' . $order_id;
        if ($tracking_token) {
            $base_url .= '&token=' . urlencode($tracking_token);
        }
        return $base_url;
    }
    
    /**
     * Calculate delivery fee based on distance
     */
    public static function calculateDeliveryFee($distance_km, $base_fee = 50, $per_km_rate = 15) {
        if ($distance_km <= 0) {
            return $base_fee;
        }
        return $base_fee + ($distance_km * $per_km_rate);
    }
    
    /**
     * Check if driver is capable of delivery (based on rating and track record)
     */
    public static function isDriverCapable($average_rating, $success_rate, $min_rating = 4.0, $min_success_rate = 85.0) {
        return floatval($average_rating) >= $min_rating && floatval($success_rate) >= $min_success_rate;
    }
}

/**
 * Notification Management Class
 * Handles SMS, Email, and in-app notifications
 */

class NotificationManager {
    private $conn;
    
    public function __construct($connection) {
        if (!$connection) {
            throw new Exception("Database connection required for NotificationManager");
        }
        $this->conn = $connection;
    }
    
    /**
     * Create and send notification
     */
    public function sendOrderNotification($user_id, $order_id, $type, $title, $message, $channels = ['in_app', 'email', 'sms']) {
        try {
            // Check user notification preferences
            $prefs_query = "SELECT * FROM customer_notification_preferences WHERE user_id = ?";
            $prefs_stmt = mysqli_prepare($this->conn, $prefs_query);
            mysqli_stmt_bind_param($prefs_stmt, "i", $user_id);
            mysqli_stmt_execute($prefs_stmt);
            $prefs_result = mysqli_stmt_get_result($prefs_stmt);
            $prefs = mysqli_fetch_assoc($prefs_result);
            mysqli_stmt_close($prefs_stmt);
            
            $notifications = [];
            
            // In-app notification (always if enabled in prefs)
            if (in_array('in_app', $channels) && ($prefs['in_app_notifications'] ?? true)) {
                $this->createInAppNotification($user_id, $order_id, $type, $title, $message);
                $notifications[] = 'in_app';
            }
            
            // SMS notification
            if (in_array('sms', $channels) && ($prefs['sms_notifications'] ?? true)) {
                $this->sendSMSNotification($user_id, $order_id, $message);
                $notifications[] = 'sms';
            }
            
            // Email notification
            if (in_array('email', $channels) && ($prefs['email_notifications'] ?? true)) {
                $this->sendEmailNotification($user_id, $order_id, $title, $message);
                $notifications[] = 'email';
            }
            
            return ['success' => true, 'notifications_sent' => $notifications];
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function createInAppNotification($user_id, $order_id, $type, $title, $message) {
        $query = "INSERT INTO notifications (user_id, related_id, related_type, type, title, message, created_at)
                 VALUES (?, ?, 'order', ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($this->conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iisss", $user_id, $order_id, $type, $title, $message);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    private function sendSMSNotification($user_id, $order_id, $message) {
        // Validate connection
        if (!$this->conn) {
            error_log("Notification Manager: Database connection not available");
            return false;
        }
        
        // Get user phone
        $user_query = "SELECT phone FROM users WHERE id = ?";
        $user_stmt = mysqli_prepare($this->conn, $user_query);
        if (!$user_stmt) return false;
        
        mysqli_stmt_bind_param($user_stmt, "i", $user_id);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        $user = mysqli_fetch_assoc($user_result);
        mysqli_stmt_close($user_stmt);
        
        if (!$user || empty($user['phone'])) return false;
        
        // Send SMS via SMS service if available
        try {
            if (class_exists('SMSService')) {
                $sms_service = new SMSService($this->conn);
                return $sms_service->send($user['phone'], $message);
            }
            error_log("SMSService class not available for sending SMS");
            return false;
        } catch (Exception $e) {
            error_log("SMS sending error: " . $e->getMessage());
            return false;
        }
    }
    
    private function sendEmailNotification($user_id, $order_id, $title, $message) {
        // Validate connection
        if (!$this->conn) {
            error_log("Notification Manager: Database connection not available");
            return false;
        }
        
        // Get user email
        $user_query = "SELECT email FROM users WHERE id = ?";
        $user_stmt = mysqli_prepare($this->conn, $user_query);
        if (!$user_stmt) return false;
        
        mysqli_stmt_bind_param($user_stmt, "i", $user_id);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        $user = mysqli_fetch_assoc($user_result);
        mysqli_stmt_close($user_stmt);
        
        if (!$user || empty($user['email'])) return false;
        
        // Send email via email service if available
        try {
            if (class_exists('EmailService')) {
                $email_service = new EmailService($this->conn);
                return $email_service->sendNotificationEmail($user['email'], $title, $message);
            }
            error_log("EmailService class not available for sending email");
            return false;
        } catch (Exception $e) {
            error_log("Email sending error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id) {
        $query = "UPDATE notifications SET is_read = 1 WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $notification_id);
            $result = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return $result;
        }
        return false;
    }
}

?>
