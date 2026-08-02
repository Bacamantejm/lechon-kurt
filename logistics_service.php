<?php
/**
 * This needs composer's autoloader. Make sure to run `composer install`.
 */
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}
require_once __DIR__ . '/sms_service.php';
if (file_exists(__DIR__ . '/admin/hr_module_common.php')) {
    require_once __DIR__ . '/admin/hr_module_common.php';
}
/**
 * LogisticsService Class
 * Handles all logistics operations including tracking, notifications, and third-party API integration
 */

class LogisticsService {
    private $conn;
    private $order_scope_cache = [];
    
    public function __construct($connection) {
        $this->conn = $connection;
    }

    private function getDriverRoleCondition($employee_alias = 'e', $department_alias = 'd') {
        if (function_exists('hrLogisticsEmployeeSqlCondition')) {
            return hrLogisticsEmployeeSqlCondition($employee_alias, $department_alias, $this->conn);
        }
        return "1=0";
    }

    private function tableExists($table_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        if ($table_name === '') {
            return false;
        }
        if (array_key_exists($table_name, $cache)) {
            return $cache[$table_name];
        }
        $safe_table = mysqli_real_escape_string($this->conn, $table_name);
        $result = mysqli_query($this->conn, "SHOW TABLES LIKE '{$safe_table}'");
        return $cache[$table_name] = (bool)($result && mysqli_num_rows($result) > 0);
    }

    private function columnExists($table_name, $column_name) {
        static $cache = [];
        $cache_key = trim((string)$table_name) . '.' . trim((string)$column_name);
        if ($cache_key === '.' || $cache_key === '') {
            return false;
        }
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }
        if (!$this->tableExists($table_name)) {
            return $cache[$cache_key] = false;
        }
        $safe_table = mysqli_real_escape_string($this->conn, (string)$table_name);
        $safe_column = mysqli_real_escape_string($this->conn, (string)$column_name);
        $result = mysqli_query($this->conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        return $cache[$cache_key] = (bool)($result && mysqli_num_rows($result) > 0);
    }

    private function normalizePositiveIds(array $ids) {
        $normalized = [];
        foreach ($ids as $id) {
            $int_id = (int)$id;
            if ($int_id > 0) {
                $normalized[$int_id] = true;
            }
        }
        return array_keys($normalized);
    }

    private function getOrderSellerOwnerIds($order_id) {
        $order_id = (int)$order_id;
        if ($order_id <= 0) {
            return [];
        }

        $query = "SELECT DISTINCT p.seller_id
                  FROM order_items oi
                  INNER JOIN products p
                    ON (oi.product_id = p.product_id OR oi.product_id = CAST(p.id AS CHAR))
                  WHERE oi.order_id = ?
                    AND p.seller_id IS NOT NULL
                    AND p.seller_id > 0";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $seller_owner_ids = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $seller_owner_ids[] = (int)($row['seller_id'] ?? 0);
        }
        mysqli_stmt_close($stmt);
        return $this->normalizePositiveIds($seller_owner_ids);
    }

    private function getScopedUserIdsForOwnerIds(array $owner_ids) {
        $owner_ids = $this->normalizePositiveIds($owner_ids);
        if (empty($owner_ids)) {
            return [];
        }

        $scoped = [];
        foreach ($owner_ids as $owner_id) {
            $scoped[$owner_id] = true;
        }

        if ($this->tableExists('partner_user_links')
            && $this->columnExists('partner_user_links', 'owner_user_id')
            && $this->columnExists('partner_user_links', 'managed_user_id')) {
            $owner_csv = implode(',', array_map('intval', $owner_ids));
            $links_query = "SELECT managed_user_id
                            FROM partner_user_links
                            WHERE owner_user_id IN ({$owner_csv})";
            $links_result = mysqli_query($this->conn, $links_query);
            while ($links_result && ($link_row = mysqli_fetch_assoc($links_result))) {
                $managed_user_id = (int)($link_row['managed_user_id'] ?? 0);
                if ($managed_user_id > 0) {
                    $scoped[$managed_user_id] = true;
                }
            }
        }

        if ($this->tableExists('roles')
            && $this->columnExists('roles', 'owner_user_id')
            && $this->tableExists('users')
            && $this->columnExists('users', 'role_id')) {
            $owner_csv = implode(',', array_map('intval', $owner_ids));
            $roles_query = "SELECT u.id AS user_id
                            FROM users u
                            INNER JOIN roles r ON r.id = u.role_id
                            WHERE r.owner_user_id IN ({$owner_csv})";
            $roles_result = mysqli_query($this->conn, $roles_query);
            while ($roles_result && ($role_row = mysqli_fetch_assoc($roles_result))) {
                $linked_user_id = (int)($role_row['user_id'] ?? 0);
                if ($linked_user_id > 0) {
                    $scoped[$linked_user_id] = true;
                }
            }
        }

        return array_keys($scoped);
    }

    private function getOrderScopeContext($order_id) {
        $order_id = (int)$order_id;
        if ($order_id <= 0) {
            return ['mode' => 'invalid', 'scoped_user_ids' => []];
        }
        if (isset($this->order_scope_cache[$order_id])) {
            return $this->order_scope_cache[$order_id];
        }

        $owner_ids = $this->getOrderSellerOwnerIds($order_id);
        if (empty($owner_ids)) {
            $context = ['mode' => 'global', 'scoped_user_ids' => []];
            $this->order_scope_cache[$order_id] = $context;
            return $context;
        }

        $context = [
            'mode' => 'scoped',
            'scoped_user_ids' => $this->normalizePositiveIds($this->getScopedUserIdsForOwnerIds($owner_ids))
        ];
        $this->order_scope_cache[$order_id] = $context;
        return $context;
    }

    private function getDriverScopeSqlForOrder($order_id, $employee_alias = 'e', $user_column = 'user_id') {
        $context = $this->getOrderScopeContext($order_id);
        if (($context['mode'] ?? '') === 'global') {
            return '1=1';
        }
        $scoped_user_ids = is_array($context['scoped_user_ids'] ?? null) ? $context['scoped_user_ids'] : [];
        if (empty($scoped_user_ids)) {
            return '1=0';
        }
        $scoped_csv = implode(',', array_map('intval', $scoped_user_ids));
        return "{$employee_alias}.{$user_column} IS NOT NULL AND {$employee_alias}.{$user_column} IN ({$scoped_csv})";
    }
    
    /**
     * Create a new logistics tracking record for an order.
     * This should be called automatically after an order with 'delivery' option is confirmed.
     */
    public function createTrackingForOrder($order_id, $logistics_provider_id, $delivery_method_id, $special_instructions = '', $latitude = null, $longitude = null) {
        $query = "INSERT INTO logistics_tracking 
                    (order_id, logistics_provider_id, delivery_method_id, special_instructions, current_status, current_latitude, current_longitude, created_at, updated_at) 
                  VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "iiisdd", $order_id, $logistics_provider_id, $delivery_method_id, $special_instructions, $latitude, $longitude);
            
            if (mysqli_stmt_execute($stmt)) {
                $tracking_id = mysqli_insert_id($this->conn);
                mysqli_stmt_close($stmt);
                
                // Log the action
                $this->logAction('create_tracking', ['order_id' => $order_id, 'tracking_id' => $tracking_id], true);
                
                return ['success' => true, 'tracking_id' => $tracking_id];
            }
            mysqli_stmt_close($stmt);
        }
        
        return ['success' => false, 'message' => 'Failed to create tracking record'];
    }
    
    /**
     * Update tracking status
     */
    public function updateTrackingStatus($tracking_id, $new_status, $status_description = '', $latitude = null, $longitude = null, $proof_path = null) {
        $valid_statuses = ['pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled'];
        
        if (!in_array($new_status, $valid_statuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }
        
        $tracking_query = "SELECT order_id, current_status FROM logistics_tracking WHERE id = ?";
        if ($tracking_stmt = mysqli_prepare($this->conn, $tracking_query)) {
            mysqli_stmt_bind_param($tracking_stmt, "i", $tracking_id);
            mysqli_stmt_execute($tracking_stmt);
            $tracking_result = mysqli_stmt_get_result($tracking_stmt);
            $tracking = mysqli_fetch_assoc($tracking_result);
            mysqli_stmt_close($tracking_stmt);
            
            if (!$tracking) {
                return ['success' => false, 'message' => 'Tracking record not found'];
            }
        }
        
        $update_query = "UPDATE logistics_tracking SET current_status = ?, last_location_update = NOW()";
        $params = [$new_status];
        $types = "s";
        
        if ($latitude !== null) {
            $update_query .= ", current_latitude = ?";
            $params[] = $latitude;
            $types .= "d";
        }
        
        if ($longitude !== null) {
            $update_query .= ", current_longitude = ?";
            $params[] = $longitude;
            $types .= "d";
        }
        
        if ($proof_path !== null && $new_status === 'delivered') {
            $update_query .= ", proof_of_delivery_path = ?";
            $params[] = $proof_path;
            $types .= "s";
        }
        
        $update_query .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $tracking_id;
        $types .= "i";
        
        if ($update_stmt = mysqli_prepare($this->conn, $update_query)) {
            mysqli_stmt_bind_param($update_stmt, $types, ...$params);
            
            if (mysqli_stmt_execute($update_stmt)) {
                mysqli_stmt_close($update_stmt);
                
                // Add to history
                $this->addTrackingHistory($tracking_id, $new_status, $status_description, $latitude, $longitude, $proof_path);
                
                // Send notification to customer
                $this->notifyCustomerStatusChange($tracking['order_id'], $new_status, $proof_path);
                
                // Update order status based on logistics status
                $this->updateOrderStatus($tracking['order_id'], $new_status);
                
                return ['success' => true, 'message' => 'Status updated successfully'];
            }
            mysqli_stmt_close($update_stmt);
        }
        
        return ['success' => false, 'message' => 'Failed to update status'];
    }
    
    /**
     * Add entry to tracking history
     */
    private function addTrackingHistory($tracking_id, $status, $description = '', $latitude = null, $longitude = null, $proof_path = null) {
        $query = "INSERT INTO logistics_tracking_history (tracking_id, status, status_description, latitude, longitude, proof_path) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "issdds", $tracking_id, $status, $description, $latitude, $longitude, $proof_path);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    /**
     * Assign driver to delivery
     */
    public function assignDriver($tracking_id, $driver_id, $driver_name, $driver_phone, $driver_vehicle = '') {
        $query = "UPDATE logistics_tracking SET driver_id = ?, driver_name = ?, driver_phone = ?, driver_vehicle = ?, updated_at = NOW()
                  WHERE id = ?";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "isssi", $driver_id, $driver_name, $driver_phone, $driver_vehicle, $tracking_id);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                
                // Update status to 'assigned', which also creates history and sends notifications
                $this->updateTrackingStatus($tracking_id, 'assigned', 'Driver ' . $driver_name . ' assigned');
                
                return ['success' => true, 'message' => 'Driver assigned successfully'];
            }
            mysqli_stmt_close($stmt);
        }
        
        return ['success' => false, 'message' => 'Failed to assign driver'];
    }
    
    /**
     * Update delivery cost
     */
    public function updateDeliveryCost($tracking_id, $cost) {
        $query = "UPDATE logistics_tracking SET cost = ? WHERE id = ?";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "di", $cost, $tracking_id);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                return ['success' => true, 'message' => 'Cost updated successfully'];
            }
            mysqli_stmt_close($stmt);
        }
        
        return ['success' => false, 'message' => 'Failed to update cost'];
    }
    
    /**
     * Get tracking information
     */
    public function getTracking($tracking_id) {
        $query = "SELECT lt.*, 
                         lp.provider_name, 
                         dm.method_name,
                         o.order_number,
                         o.delivery_address,
                         u.email as customer_email, 
                         u.full_name as customer_name,
                         u.phone as customer_phone
                  FROM logistics_tracking lt
                  LEFT JOIN logistics_providers lp ON lt.logistics_provider_id = lp.id
                  LEFT JOIN delivery_methods dm ON lt.delivery_method_id = dm.id
                  LEFT JOIN orders o ON lt.order_id = o.id
                  LEFT JOIN users u ON o.user_id = u.id
                  WHERE lt.id = ?";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "i", $tracking_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $tracking = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            return $tracking ?: null;
        }
        
        return null;
    }
    
    /**
     * Get all deliveries assigned to a specific driver
     */
    public function getDeliveriesForDriver($driver_id, $include_history = false) {
        $query = "SELECT lt.*, o.order_number, o.customer_name, o.delivery_address, o.customer_phone, o.total_amount
                  FROM logistics_tracking lt
                  JOIN orders o ON lt.order_id = o.id
                  WHERE lt.driver_id = ?";
        
        if (!$include_history) {
            $query .= " AND lt.current_status NOT IN ('delivered', 'cancelled', 'failed')";
        }
        
        $query .= " ORDER BY lt.created_at " . ($include_history ? "DESC" : "ASC");
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "i", $driver_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $deliveries = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $deliveries[] = $row;
            }
            mysqli_stmt_close($stmt);
            return $deliveries;
        }
        return [];
    }
    /**
     * Finds a random available driver from the delivery department.
     * An available driver is active and not currently on an active delivery.
     * @return array|null An associative array of the driver's details or null if none found.
     */
    public function findAvailableDriver($order_id = 0, $delivery_date = null) {
        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');
        $driver_scope_sql = $this->getDriverScopeSqlForOrder((int)$order_id, 'e', 'user_id');
        if ($driver_scope_sql === '1=0') {
            return null;
        }
        $assignment_date = null;
        if (is_string($delivery_date)) {
            $candidate_date = substr($delivery_date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate_date)) {
                $assignment_date = $candidate_date;
            }
        }
        if ($assignment_date === null) {
            $assignment_date = date('Y-m-d');
        }

        // This query is adapted from admin/logistics.php and randomized for simple load balancing.
        $query = "
            SELECT e.id, e.first_name, e.last_name, e.phone, e.vehicle_details
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE e.status = 'active' 
            AND {$driver_role_sql}
            AND {$driver_scope_sql}
            AND e.id NOT IN (
                SELECT lt.driver_id 
                FROM logistics_tracking lt
                INNER JOIN orders o ON o.id = lt.order_id
                WHERE lt.driver_id IS NOT NULL 
                AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                AND DATE(o.delivery_date) = ?
            )
            ORDER BY RAND() -- Select a random available driver
            LIMIT 1
        ";

        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, "s", $assignment_date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $driver = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($driver) {
            return $driver;
        }
        
        return null;
    }
    /**
     * Get tracking by order ID
     */
    public function getTrackingByOrderId($order_id) {
        $query = "SELECT lt.*, 
                         lp.provider_name, 
                         dm.method_name
                  FROM logistics_tracking lt
                  LEFT JOIN logistics_providers lp ON lt.logistics_provider_id = lp.id
                  LEFT JOIN delivery_methods dm ON lt.delivery_method_id = dm.id
                  WHERE lt.order_id = ?";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $tracking = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            return $tracking ?: null;
        }
        
        return null;
    }
    
    /**
     * Get tracking history
     */
    public function getTrackingHistory($tracking_id) {
        $query = "SELECT * FROM logistics_tracking_history WHERE tracking_id = ? ORDER BY timestamp DESC";
        
        if ($stmt = mysqli_prepare($this->conn, $query)) {
            mysqli_stmt_bind_param($stmt, "i", $tracking_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $history = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $history[] = $row;
            }
            mysqli_stmt_close($stmt);
            
            return $history;
        }
        
        return [];
    }
    
    /**
     * Notify customer of status change
     */
    private function notifyCustomerStatusChange($order_id, $status, $proof_path = null) {
        // Get order and customer info
        $order_query = "SELECT o.id, o.user_id, u.email, u.phone, u.full_name 
                       FROM orders o
                       LEFT JOIN users u ON o.user_id = u.id
                       WHERE o.id = ?";
        
        if ($order_stmt = mysqli_prepare($this->conn, $order_query)) {
            mysqli_stmt_bind_param($order_stmt, "i", $order_id);
            mysqli_stmt_execute($order_stmt);
            $order_result = mysqli_stmt_get_result($order_stmt);
            $order = mysqli_fetch_assoc($order_result);
            mysqli_stmt_close($order_stmt);
            
            if (!$order) return;
            
            // Map status to notification
            $notification_map = [
                'pending' => 'Order Pending',
                'assigned' => 'Driver Assigned',
                'picked_up' => 'Order Picked Up',
                'on_the_way' => 'Driver on the Way',
                'arriving' => 'Driver Arriving Soon',
                'delivered' => 'Order Delivered',
                'failed' => 'Delivery Failed',
                'cancelled' => 'Order Cancelled'
            ];
            
            $title = $notification_map[$status] ?? 'Order Update';
            
            $messages = [
                'pending' => 'Your order is being processed and will be picked up soon.',
                'assigned' => 'A driver has been assigned to your order.',
                'picked_up' => 'Your order has been picked up and is on its way to you.',
                'on_the_way' => 'Your driver is on the way to your location.',
                'arriving' => 'Your driver is arriving soon. Please be ready.',
                'delivered' => 'Your order has been delivered. Thank you for ordering!',
                'failed' => 'Sorry, the delivery could not be completed. Our team will contact you soon.',
                'cancelled' => 'Your order delivery has been cancelled.'
            ];
            
            $message = $messages[$status] ?? 'Your order has been updated.';
            
            if ($status === 'delivered' && $proof_path) {
                $message .= " You can view the proof of delivery."; // Link can be added here
            }
            
            // Create notification
            $notification_query = "INSERT INTO notifications (user_id, related_id, related_type, type, title, message, created_at) 
                                  VALUES (?, ?, 'order', ?, ?, ?, NOW())";
            
            $notification_type = 'order_' . $status;
            if ($notif_stmt = mysqli_prepare($this->conn, $notification_query)) {
                mysqli_stmt_bind_param($notif_stmt, "iisss", $order['user_id'], $order_id, $notification_type, $title, $message);
                mysqli_stmt_execute($notif_stmt);
                mysqli_stmt_close($notif_stmt);
            }
            
            // Send notifications (assuming preferences are handled by a separate service or are always on)
            if ($order['phone']) {
                $this->sendSMS($order['phone'], $message);
            }
            if ($order['email']) {
                $this->sendEmailNotification($order['email'], $title, $message, $order['full_name']);
            }
        }
    }
    
    /**
     * Send SMS notification
     */
    private function sendSMS($phone, $message) {
        $smsService = new SmsService($this->conn);
        $smsService->send($phone, $message);
    }
    
    /**
     * Send email notification
     */
    private function sendEmailNotification($email, $title, $message, $customer_name) {
        // TODO: Send email using EmailService
        // This should be called from the main email service
    }
    
    /**
     * Update order status based on logistics status
     */
    private function updateOrderStatus($order_id, $logistics_status) {
        $status_map = [
            'pending' => 'confirmed', // Logistics pending means order is confirmed
            'assigned' => 'preparing',
            'picked_up' => 'preparing',
            'on_the_way' => 'delivered', // Or a new 'shipping' status
            'arriving' => 'delivered',
            'delivered' => 'delivered',
            'failed' => 'failed',
            'cancelled' => 'cancelled'
        ];
        
        $order_status = $status_map[$logistics_status] ?? null;
        if (!$order_status) return;
        
        $update_query = "UPDATE orders SET status = ? WHERE id = ?";
        
        if ($stmt = mysqli_prepare($this->conn, $update_query)) {
            mysqli_stmt_bind_param($stmt, "si", $order_status, $order_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    /**
     * Log API actions for debugging
     */
    private function logAction($action, $data, $success = true, $error = '') {
        $log_query = "INSERT INTO logistics_api_logs (provider_name, request_type, request_data, success, error_message) 
                     VALUES ('SYSTEM', ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($this->conn, $log_query)) {
            $request_data = json_encode($data);
            mysqli_stmt_bind_param($stmt, "ssbs", $action, $request_data, $success, $error);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}
?>
