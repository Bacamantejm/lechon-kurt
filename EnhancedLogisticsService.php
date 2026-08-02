<?php
/**
 * Enhanced LogisticsService Class
 * Handles all logistics operations including automatic driver assignment,
 * proof of delivery, route optimization, and real-time tracking
 */
if (file_exists(__DIR__ . '/admin/hr_module_common.php')) {
    require_once __DIR__ . '/admin/hr_module_common.php';
}

class EnhancedLogisticsService {
    private $conn;
    private $error_log = [];
    private $order_scope_cache = [];
    
    const ASSIGNMENT_ALGORITHM_NEAREST = 'nearest_driver';
    const ASSIGNMENT_ALGORITHM_LOAD_BALANCED = 'load_balanced';
    const ASSIGNMENT_ALGORITHM_RATING_BASED = 'rating_based';
    const ASSIGNMENT_ALGORITHM_HYBRID = 'hybrid';
    
    const MAX_ASSIGNMENT_ATTEMPTS = 3;
    const DEFAULT_ASSIGNMENT_ALGORITHM = self::ASSIGNMENT_ALGORITHM_HYBRID;
    const EARTH_RADIUS_KM = 6371;
    
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

        $scoped_user_ids = $this->getScopedUserIdsForOwnerIds($owner_ids);
        $context = [
            'mode' => 'scoped',
            'scoped_user_ids' => $this->normalizePositiveIds($scoped_user_ids)
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

    private function isDriverInOrderTenantScope($order_id, $driver_id) {
        $order_id = (int)$order_id;
        $driver_id = (int)$driver_id;
        if ($order_id <= 0 || $driver_id <= 0) {
            return false;
        }

        $scope_sql = $this->getDriverScopeSqlForOrder($order_id, 'e', 'user_id');
        if ($scope_sql === '1=1') {
            return true;
        }
        if ($scope_sql === '1=0') {
            return false;
        }

        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');
        $query = "SELECT e.id
                  FROM employees e
                  LEFT JOIN departments d ON d.id = e.department_id
                  WHERE e.id = ?
                    AND e.status = 'active'
                    AND {$driver_role_sql}
                    AND {$scope_sql}
                  LIMIT 1";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $driver_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $ok = (bool)($result && mysqli_fetch_assoc($result));
        mysqli_stmt_close($stmt);
        return $ok;
    }
    
    /**
     * Get all logs from current execution
     */
    public function getErrorLogs() {
        return $this->error_log;
    }

    /**
     * Resolve assignment date from explicit value or order delivery date.
     */
    private function resolveAssignmentDate($order_id, $delivery_date = null) {
        if (is_string($delivery_date)) {
            $normalized = substr($delivery_date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
                return $normalized;
            }
        }

        $order_date_query = "SELECT delivery_date FROM orders WHERE id = ? LIMIT 1";
        $order_date_stmt = mysqli_prepare($this->conn, $order_date_query);
        if ($order_date_stmt) {
            mysqli_stmt_bind_param($order_date_stmt, "i", $order_id);
            mysqli_stmt_execute($order_date_stmt);
            $order_date_result = mysqli_stmt_get_result($order_date_stmt);
            $order_row = $order_date_result ? mysqli_fetch_assoc($order_date_result) : null;
            mysqli_stmt_close($order_date_stmt);

            $candidate_date = isset($order_row['delivery_date']) ? substr((string)$order_row['delivery_date'], 0, 10) : null;
            if ($candidate_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate_date)) {
                return $candidate_date;
            }
        }

        return date('Y-m-d');
    }
    
    /**
     * Log error or information
     */
    private function log($message, $level = 'INFO', $data = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'data' => $data
        ];
        $this->error_log[] = $log_entry;
        
        // Also log to PHP error log
        error_log("[Logistics] [$level] $message " . json_encode($data));
    }
    
    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function haversineDistance($lat1, $lon1, $lat2, $lon2) {
        $lat1_rad = deg2rad($lat1);
        $lon1_rad = deg2rad($lon1);
        $lat2_rad = deg2rad($lat2);
        $lon2_rad = deg2rad($lon2);
        
        $dlat = $lat2_rad - $lat1_rad;
        $dlon = $lon2_rad - $lon1_rad;
        
        $a = sin($dlat / 2) ** 2 + cos($lat1_rad) * cos($lat2_rad) * sin($dlon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return self::EARTH_RADIUS_KM * $c;
    }
    
    /**
     * Get tracking record by order ID
     */
    public function getTrackingByOrderId($order_id) {
        $query = "SELECT * FROM logistics_tracking WHERE order_id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare tracking query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return null;
        }
        
        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $tracking = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        return $tracking;
    }
    
    /**
     * Create tracking record for delivery order
     */
    public function createTrackingForOrder($order_id, $delivery_address, $latitude = null, $longitude = null, $special_instructions = '') {
        $this->log("Creating tracking record", "INFO", ['order_id' => $order_id]);
        
        $query = "INSERT INTO logistics_tracking (
            order_id, current_status, special_instructions, current_latitude, 
            current_longitude, created_at, updated_at
        ) VALUES (?, 'pending', ?, ?, ?, NOW(), NOW())";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare insert tracking query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return ['success' => false, 'message' => 'Database error'];
        }
        
        mysqli_stmt_bind_param($stmt, "isdd", $order_id, $special_instructions, $latitude, $longitude);
        
        if (mysqli_stmt_execute($stmt)) {
            $tracking_id = mysqli_insert_id($this->conn);
            mysqli_stmt_close($stmt);
            
            // Update order to mark it has tracking
            $this->updateOrderTrackingStatus($order_id, 'pending');
            
            $this->log("Tracking record created", "INFO", ['tracking_id' => $tracking_id, 'order_id' => $order_id]);
            return ['success' => true, 'tracking_id' => $tracking_id];
        }
        
        $error = mysqli_error($this->conn);
        mysqli_stmt_close($stmt);
        $this->log("Failed to insert tracking record", "ERROR", ['error' => $error, 'order_id' => $order_id]);
        return ['success' => false, 'message' => 'Failed to create tracking record'];
    }
    
    /**
     * Automatically assign driver to delivery order
     */
    public function autoAssignDriver($order_id, $delivery_address, $latitude = null, $longitude = null, $algorithm = self::DEFAULT_ASSIGNMENT_ALGORITHM, $delivery_date = null) {
        $this->log("Starting automatic driver assignment", "INFO", ['order_id' => $order_id, 'algorithm' => $algorithm]);
        
        try {
            $assignment_date = $this->resolveAssignmentDate($order_id, $delivery_date);
            $this->log("Resolved assignment date for auto-assignment", "INFO", ['order_id' => $order_id, 'assignment_date' => $assignment_date]);
            $driver_scope_sql = $this->getDriverScopeSqlForOrder($order_id, 'e', 'user_id');
            if ($driver_scope_sql === '1=0') {
                $this->log("No tenant-scoped drivers available for order", "WARNING", ['order_id' => $order_id]);
                return ['success' => false, 'message' => 'No scoped drivers are available for this store.'];
            }

            // Get tracking record
            $tracking = $this->getTrackingByOrderId($order_id);
            if (!$tracking) {
                throw new Exception("Tracking record not found for order ID: $order_id");
            }
            
            // Validate coordinates for algorithms that need them
            if (($algorithm === self::ASSIGNMENT_ALGORITHM_NEAREST || $algorithm === self::ASSIGNMENT_ALGORITHM_HYBRID) &&
                (!is_numeric($latitude) || !is_numeric($longitude))) {
                $this->log("Invalid coordinates for algorithm: $algorithm", "WARNING", ['lat' => $latitude, 'lon' => $longitude]);
                $algorithm = self::ASSIGNMENT_ALGORITHM_LOAD_BALANCED;
            }
            
            // Get available drivers based on algorithm
            $available_drivers = [];
            if ($algorithm === self::ASSIGNMENT_ALGORITHM_NEAREST) {
                $available_drivers = $this->getAvailableDriversNearest($latitude, $longitude, $assignment_date, $driver_scope_sql);
            } elseif ($algorithm === self::ASSIGNMENT_ALGORITHM_LOAD_BALANCED) {
                $available_drivers = $this->getAvailableDriversLoadBalanced($assignment_date, $driver_scope_sql);
            } elseif ($algorithm === self::ASSIGNMENT_ALGORITHM_RATING_BASED) {
                $available_drivers = $this->getAvailableDriversRatingBased($assignment_date, $driver_scope_sql);
            } elseif ($algorithm === self::ASSIGNMENT_ALGORITHM_HYBRID) {
                $available_drivers = $this->getAvailableDriversHybrid($latitude, $longitude, $assignment_date, $driver_scope_sql);
            } else {
                $available_drivers = $this->getAvailableDriversLoadBalanced($assignment_date, $driver_scope_sql);
            }
            
            $this->log("Found available drivers", "INFO", ['count' => count($available_drivers)]);
            
            if (empty($available_drivers)) {
                $this->log("No available drivers found (attendance-aware filter applied)", "WARNING", ['order_id' => $order_id, 'assignment_date' => $assignment_date]);
                return ['success' => false, 'message' => "No available drivers with attendance on {$assignment_date}."];
            }
            
            // Try to assign best driver
            foreach ($available_drivers as $driver) {
                $assignment_result = $this->assignDriverToOrder($order_id, $driver['id'], $algorithm);
                if ($assignment_result['success']) {
                    $this->log("Driver assigned successfully", "INFO", ['driver_id' => $driver['id'], 'order_id' => $order_id]);
                    return $assignment_result;
                }
            }
            
            $this->log("Failed to assign any driver", "ERROR", ['order_id' => $order_id]);
            return ['success' => false, 'message' => 'Unable to assign driver at this time'];
        } catch (Exception $e) {
            $this->log("Error in autoAssignDriver", "ERROR", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get available drivers sorted by proximity
     */
    private function getAvailableDriversNearest($latitude, $longitude, $assignment_date = null, $driver_scope_sql = '1=1') {
        // Validate coordinates
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            $this->log("Invalid coordinates for nearest search", "WARNING", ['lat' => $latitude, 'lon' => $longitude]);
            return [];
        }

        if (!$assignment_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignment_date)) {
            $assignment_date = date('Y-m-d');
        }
        
        $this->log("Finding nearest available drivers", "INFO", ['lat' => $latitude, 'lon' => $longitude, 'assignment_date' => $assignment_date]);
        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');
        
        $query = "
            SELECT 
                e.id, e.first_name, e.last_name, e.phone, e.vehicle_details,
                gt.current_latitude, gt.current_longitude,
                dav.current_deliveries_count, dav.max_deliveries_per_day,
                dds.avg_rating, dds.success_rate,
                (6371 * acos(cos(radians(?)) * cos(radians(gt.current_latitude)) * 
                 cos(radians(gt.current_longitude) - radians(?)) + 
                 sin(radians(?)) * sin(radians(gt.current_latitude)))) AS distance_km
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN driver_availability dav ON e.id = dav.driver_id AND dav.date = ?
            LEFT JOIN driver_delivery_stats dds ON e.id = dds.driver_id
            LEFT JOIN employees_geo_tracking gt ON e.id = gt.employee_id
            LEFT JOIN attendance att ON e.id = att.employee_id
                AND att.attendance_date = ?
                AND att.status IN ('present', 'late', 'half_day')
                AND (att.hr_status IS NULL OR att.hr_status <> 'rejected')
            WHERE e.status = 'active'
            AND {$driver_role_sql}
            AND {$driver_scope_sql}
            AND att.id IS NOT NULL
            AND (dav.current_deliveries_count < dav.max_deliveries_per_day OR dav.id IS NULL)
            AND NOT EXISTS (
                SELECT 1
                FROM logistics_tracking busy_lt
                INNER JOIN orders busy_o ON busy_o.id = busy_lt.order_id
                WHERE busy_lt.driver_id = e.id
                  AND busy_lt.driver_id IS NOT NULL
                  AND busy_lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                  AND DATE(busy_o.delivery_date) = ?
            )
            ORDER BY distance_km ASC, dds.avg_rating DESC
            LIMIT 10
        ";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare nearest drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return [];
        }
        
        mysqli_stmt_bind_param($stmt, "dddsss", $latitude, $longitude, $latitude, $assignment_date, $assignment_date, $assignment_date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $drivers = [];
        
        while ($driver = mysqli_fetch_assoc($result)) {
            $drivers[] = $driver;
        }
        mysqli_stmt_close($stmt);
        
        return $drivers;
    }
    
    /**
     * Get available drivers with load balancing
     */
    private function getAvailableDriversLoadBalanced($assignment_date = null, $driver_scope_sql = '1=1') {
        if (!$assignment_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignment_date)) {
            $assignment_date = date('Y-m-d');
        }
        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');

        $query = "
            SELECT 
                e.id, e.first_name, e.last_name, e.phone, e.vehicle_details,
                dav.current_deliveries_count, dav.max_deliveries_per_day,
                dds.avg_rating, dds.success_rate
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN driver_availability dav ON e.id = dav.driver_id AND dav.date = ?
            LEFT JOIN driver_delivery_stats dds ON e.id = dds.driver_id
            LEFT JOIN attendance att ON e.id = att.employee_id
                AND att.attendance_date = ?
                AND att.status IN ('present', 'late', 'half_day')
                AND (att.hr_status IS NULL OR att.hr_status <> 'rejected')
            WHERE e.status = 'active'
            AND {$driver_role_sql}
            AND {$driver_scope_sql}
            AND att.id IS NOT NULL
            AND (dav.current_deliveries_count < dav.max_deliveries_per_day OR dav.id IS NULL)
            AND e.id NOT IN (
                SELECT lt.driver_id
                FROM logistics_tracking lt
                INNER JOIN orders o ON o.id = lt.order_id
                WHERE lt.driver_id IS NOT NULL
                AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                AND DATE(o.delivery_date) = ?
            )
            ORDER BY 
                (COALESCE(dav.current_deliveries_count, 0) / COALESCE(dav.max_deliveries_per_day, 10)) ASC,
                dds.avg_rating DESC
            LIMIT 10
        ";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare load-balanced drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return [];
        }
        mysqli_stmt_bind_param($stmt, "sss", $assignment_date, $assignment_date, $assignment_date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $drivers = [];
        
        if (!$result) {
            $this->log("Failed to execute load-balanced drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            mysqli_stmt_close($stmt);
            return [];
        }

        while ($driver = mysqli_fetch_assoc($result)) {
                $drivers[] = $driver;
            }
        mysqli_stmt_close($stmt);
        
        return $drivers;
    }
    
    /**
     * Get available drivers sorted by rating
     */
    private function getAvailableDriversRatingBased($assignment_date = null, $driver_scope_sql = '1=1') {
        if (!$assignment_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignment_date)) {
            $assignment_date = date('Y-m-d');
        }
        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');

        $query = "
            SELECT 
                e.id, e.first_name, e.last_name, e.phone, e.vehicle_details,
                dav.current_deliveries_count, dav.max_deliveries_per_day,
                dds.avg_rating, dds.success_rate
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN driver_availability dav ON e.id = dav.driver_id AND dav.date = ?
            LEFT JOIN driver_delivery_stats dds ON e.id = dds.driver_id
            LEFT JOIN attendance att ON e.id = att.employee_id
                AND att.attendance_date = ?
                AND att.status IN ('present', 'late', 'half_day')
                AND (att.hr_status IS NULL OR att.hr_status <> 'rejected')
            WHERE e.status = 'active'
            AND {$driver_role_sql}
            AND {$driver_scope_sql}
            AND att.id IS NOT NULL
            AND (dav.current_deliveries_count < dav.max_deliveries_per_day OR dav.id IS NULL)
            AND e.id NOT IN (
                SELECT lt.driver_id
                FROM logistics_tracking lt
                INNER JOIN orders o ON o.id = lt.order_id
                WHERE lt.driver_id IS NOT NULL
                AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                AND DATE(o.delivery_date) = ?
            )
            ORDER BY COALESCE(dds.avg_rating, 0) DESC, dds.success_rate DESC
            LIMIT 10
        ";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare rating-based drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return [];
        }
        mysqli_stmt_bind_param($stmt, "sss", $assignment_date, $assignment_date, $assignment_date);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $drivers = [];
        
        if (!$result) {
            $this->log("Failed to execute rating-based drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            mysqli_stmt_close($stmt);
            return [];
        }

        while ($driver = mysqli_fetch_assoc($result)) {
                $drivers[] = $driver;
            }
        mysqli_stmt_close($stmt);
        
        return $drivers;
    }
    
    /**
     * Get available drivers using hybrid algorithm (combination of proximity, load, and rating)
     */
    private function getAvailableDriversHybrid($latitude, $longitude, $assignment_date = null, $driver_scope_sql = '1=1') {
        $this->log("Using hybrid driver selection algorithm", "INFO");
        
        // Validate coordinates
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            $this->log("Invalid coordinates for hybrid algorithm, using load-balanced fallback", "WARNING");
            return $this->getAvailableDriversLoadBalanced($assignment_date, $driver_scope_sql);
        }
        
        $drivers_nearest = $this->getAvailableDriversNearest($latitude, $longitude, $assignment_date, $driver_scope_sql);
        $drivers_load = $this->getAvailableDriversLoadBalanced($assignment_date, $driver_scope_sql);
        $drivers_rating = $this->getAvailableDriversRatingBased($assignment_date, $driver_scope_sql);
        
        // Merge and score drivers
        $scored_drivers = [];
        $all_drivers_map = [];
        
        // Score nearest drivers (40% weight)
        foreach ($drivers_nearest as $index => $driver) {
            $score = (10 - $index) * 4; // 40 points max
            $all_drivers_map[$driver['id']]['score'] = ($all_drivers_map[$driver['id']]['score'] ?? 0) + $score;
            $all_drivers_map[$driver['id']]['data'] = $driver;
        }
        
        // Score load-balanced drivers (30% weight)
        foreach ($drivers_load as $index => $driver) {
            $score = (10 - $index) * 3; // 30 points max
            $all_drivers_map[$driver['id']]['score'] = ($all_drivers_map[$driver['id']]['score'] ?? 0) + $score;
            $all_drivers_map[$driver['id']]['data'] = $driver;
        }
        
        // Score rating-based drivers (30% weight)
        foreach ($drivers_rating as $index => $driver) {
            $score = (10 - $index) * 3; // 30 points max
            $all_drivers_map[$driver['id']]['score'] = ($all_drivers_map[$driver['id']]['score'] ?? 0) + $score;
            $all_drivers_map[$driver['id']]['data'] = $driver;
        }
        
        // Sort by score
        uasort($all_drivers_map, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Extract top drivers
        foreach ($all_drivers_map as $driver_info) {
            $scored_drivers[] = array_merge($driver_info['data'], ['assignment_score' => $driver_info['score']]);
        }
        
        return array_slice($scored_drivers, 0, 10);
    }
    
    /**
     * Assign driver to order
     */
    public function assignDriverToOrder($order_id, $driver_id, $assignment_method = 'automatic') {
        $this->log("Assigning driver to order", "INFO", ['order_id' => $order_id, 'driver_id' => $driver_id]);
        
        try {
            mysqli_begin_transaction($this->conn);
            
            // Get tracking record
            $tracking = $this->getTrackingByOrderId($order_id);
            if (!$tracking) {
                throw new Exception("Tracking record not found");
            }
            
            // Get driver information
            $driver = $this->getDriverDetails($driver_id);
            if (!$driver) {
                throw new Exception("Driver not found");
            }
            if (!$this->isDriverInOrderTenantScope($order_id, $driver_id)) {
                throw new Exception("Driver is outside the allowed tenant scope for this order");
            }
            
            // Update tracking record
            $update_query = "UPDATE logistics_tracking SET 
                driver_id = ?, 
                driver_name = ?, 
                driver_phone = ?,
                driver_vehicle = ?,
                current_status = 'assigned',
                automatic_assignment = 1,
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = mysqli_prepare($this->conn, $update_query);
            if (!$stmt) {
                throw new Exception("Failed to prepare update query");
            }
            
            $driver_name = $driver['first_name'] . ' ' . $driver['last_name'];
            $tracking_id = $tracking['id'];
            
            mysqli_stmt_bind_param($stmt, "isssi", $driver_id, $driver_name, $driver['phone'], $driver['vehicle_details'], $tracking_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to update tracking record");
            }
            mysqli_stmt_close($stmt);
            
            // Record assignment in history
            $assignment_criteria = [
                'algorithm' => 'hybrid',
                'criteria_used' => ['proximity', 'load_balance', 'rating'],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $history_query = "INSERT INTO driver_assignment_history (
                tracking_id, order_id, driver_id, assignment_method, assignment_score, assignment_criteria
            ) VALUES (?, ?, ?, ?, 85, ?)";
            
            $history_stmt = mysqli_prepare($this->conn, $history_query);
            if ($history_stmt) {
                $assignment_criteria_json = json_encode($assignment_criteria);
                mysqli_stmt_bind_param($history_stmt, "iiiis", $tracking_id, $order_id, $driver_id, $assignment_method, $assignment_criteria_json);
                mysqli_stmt_execute($history_stmt);
                mysqli_stmt_close($history_stmt);
            }
            
            // Update driver availability
            $this->updateDriverCurrentDeliveries($driver_id, 1);
            
            // Record action in audit log
            $this->logAuditAction($tracking_id, $order_id, 'driver_assigned', 'system', null, $driver_id, "Driver automatically assigned: $driver_name");
            
            // Create history log
            $this->createTrackingHistory($tracking_id, 'assigned', "Driver assigned: $driver_name (" . $driver['phone'] . ")");
            
            // Commit transaction
            mysqli_commit($this->conn);
            
            $this->log("Driver assigned successfully", "INFO", ['driver_id' => $driver_id, 'tracking_id' => $tracking_id]);
            
            return [
                'success' => true, 
                'message' => 'Driver assigned successfully',
                'driver_id' => $driver_id,
                'driver_name' => $driver_name,
                'tracking_id' => $tracking_id
            ];
        } catch (Exception $e) {
            mysqli_rollback($this->conn);
            $this->log("Error assigning driver", "ERROR", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get driver details
     */
    private function getDriverDetails($driver_id) {
        $query = "SELECT id, first_name, last_name, phone, vehicle_details FROM employees WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare driver details query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return null;
        }
        
        mysqli_stmt_bind_param($stmt, "i", $driver_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $driver = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        return $driver;
    }
    
    /**
     * Update driver current deliveries count
     */
    private function updateDriverCurrentDeliveries($driver_id, $increment = 1) {
        $query = "INSERT INTO driver_availability (driver_id, date, current_deliveries_count, is_available)
                 VALUES (?, CURDATE(), ?, TRUE)
                 ON DUPLICATE KEY UPDATE 
                 current_deliveries_count = current_deliveries_count + ?";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare update driver deliveries query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return;
        }

        mysqli_stmt_bind_param($stmt, "iii", $driver_id, $increment, $increment);
        if (!mysqli_stmt_execute($stmt)) {
            $this->log("Failed to execute update driver deliveries", "ERROR", ['error' => mysqli_stmt_error($stmt)]);
        }
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Update tracking status with location and proof
     */
    public function updateTrackingStatus($tracking_id, $new_status, $latitude = null, $longitude = null, $proof_path = null, $delivery_notes = '') {
        $this->log("Updating tracking status", "INFO", ['tracking_id' => $tracking_id, 'new_status' => $new_status]);
        
        $valid_statuses = ['pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled'];
        
        if (!in_array($new_status, $valid_statuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }
        
        try {
            mysqli_begin_transaction($this->conn);
            
            // Get current tracking
            $current_tracking = $this->getTrackingById($tracking_id);
            if (!$current_tracking) {
                throw new Exception("Tracking record not found");
            }
            
            // Build update query
            $query = "UPDATE logistics_tracking SET current_status = ?, updated_at = NOW()";
            $types = "s";
            $params = [$new_status];
            
            if ($latitude !== null) {
                $query .= ", current_latitude = ?";
                $types .= "d";
                $params[] = $latitude;
            }
            
            if ($longitude !== null) {
                $query .= ", current_longitude = ?";
                $types .= "d";
                $params[] = $longitude;
            }
            
            // The proof_path and delivery_time are now handled by uploadProofOfDelivery
            if ($new_status === 'failed') {
                $query .= ", failed_timestamp = NOW(), attempts = attempts + 1";
            }
            
            if ($delivery_notes) {
                $query .= ", notes = ?";
                $types .= "s";
                $params[] = $delivery_notes;
            }
            
            $query .= " WHERE id = ?";
            $types .= "i";
            $params[] = $tracking_id;
            
            $stmt = mysqli_prepare($this->conn, $query);
            if (!$stmt) {
                throw new Exception("Failed to prepare update query");
            }
            
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to execute update");
            }
            mysqli_stmt_close($stmt);
            
            // Create tracking history
            $this->createTrackingHistory($tracking_id, $new_status, $delivery_notes);
            
            // Log audit
            $this->logAuditAction($tracking_id, $current_tracking['order_id'], 'status_updated', 'system', null, $current_tracking['driver_id'], "Status updated to: $new_status");
            
            // Update order status if delivered
            if ($new_status === 'delivered') {
                $order_update = "UPDATE orders SET status = 'delivered', has_proof_of_delivery = 1, actual_delivery_time = NOW() WHERE id = ?";
                $order_stmt = mysqli_prepare($this->conn, $order_update);
                if ($order_stmt) {
                    mysqli_stmt_bind_param($order_stmt, "i", $current_tracking['order_id']);
                    mysqli_stmt_execute($order_stmt);
                    mysqli_stmt_close($order_stmt);
                }
            }
            
            mysqli_commit($this->conn);
            
            $this->log("Tracking status updated successfully", "INFO");
            
            return ['success' => true, 'message' => 'Status updated successfully'];
        } catch (Exception $e) {
            mysqli_rollback($this->conn);
            $this->log("Error updating tracking status", "ERROR", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get tracking by ID
     */
    private function getTrackingById($tracking_id) {
        $query = "SELECT * FROM logistics_tracking WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare tracking query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return null;
        }
        
        mysqli_stmt_bind_param($stmt, "i", $tracking_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $tracking = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        return $tracking;
    }
    
    /**
     * Create tracking history record
     */
    private function createTrackingHistory($tracking_id, $status, $status_description = '') {
        $query = "INSERT INTO logistics_tracking_history (tracking_id, status, status_description, timestamp)
                 VALUES (?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare tracking history query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return;
        }

        mysqli_stmt_bind_param($stmt, "iss", $tracking_id, $status, $status_description);
        if (!mysqli_stmt_execute($stmt)) {
            $this->log("Failed to execute tracking history insert", "ERROR", ['error' => mysqli_stmt_error($stmt)]);
        }
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Log audit action
     */
    private function logAuditAction($tracking_id, $order_id, $action, $actor_type = 'system', $old_value = null, $actor_id = null, $description = '') {
        $query = "INSERT INTO logistics_audit_log (tracking_id, order_id, action, actor_type, actor_id, old_value, new_value, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare audit log statement", "ERROR", ['error' => mysqli_error($this->conn)]);
            return;
        }
        
        mysqli_stmt_bind_param($stmt, "iississ", $tracking_id, $order_id, $action, $actor_type, $actor_id, $old_value, $description);
        
        if (!mysqli_stmt_execute($stmt)) {
            $this->log("Failed to execute audit log statement", "ERROR", ['error' => mysqli_stmt_error($stmt)]);
        }
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Upload proof of delivery
     */
    public function uploadProofOfDelivery($tracking_id, $order_id, $driver_id, $photo_path, $signature_path = null, $latitude = null, $longitude = null, $condition = 'good', $delivery_notes = '') {
        $this->log("Uploading proof of delivery", "INFO", ['tracking_id' => $tracking_id, 'photo' => $photo_path]);
        
        try {
            // Validate required fields
            if (!$photo_path) {
                throw new Exception("Photo path is required");
            }
            
            // Create proof of delivery record with proper handling of nullable fields
            $query = "INSERT INTO proof_of_delivery (
                tracking_id, order_id, driver_id, photo_path, signature_path,
                location_latitude, location_longitude, delivery_condition, delivery_time
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = mysqli_prepare($this->conn, $query);
            if (!$stmt) {
                throw new Exception("Failed to prepare POD query: " . mysqli_error($this->conn));
            }
            
            // Bind parameters with proper types (i, i, i, s, s, d, d, s)
            // Note: mysqli will handle NULL values properly - use 's' not 'S'
            mysqli_stmt_bind_param($stmt, "iiissdds", 
                $tracking_id, $order_id, $driver_id, $photo_path, $signature_path, 
                $latitude, $longitude, $condition);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to insert POD record: " . mysqli_stmt_error($stmt));
            }
            
            $pod_id = mysqli_insert_id($this->conn);
            mysqli_stmt_close($stmt);
            
            // Update tracking - separate operation for better error handling
            $tracking_update = "UPDATE logistics_tracking SET 
                proof_of_delivery_path = ?,
                proof_of_delivery_timestamp = NOW(),
                delivery_time = NOW(),
                customer_signature_path = ?,
                notes = ?
                WHERE id = ?";
            
            $tracking_stmt = mysqli_prepare($this->conn, $tracking_update);
            if ($tracking_stmt) {
                $notes = "Condition: " . ucfirst($condition);
                if (!empty($delivery_notes)) {
                    $notes .= "\nDriver Notes: " . $delivery_notes;
                }
                mysqli_stmt_bind_param($tracking_stmt, "sssi", $photo_path, $signature_path, $notes, $tracking_id);
                if (!mysqli_stmt_execute($tracking_stmt)) {
                    $this->log("Failed to update tracking record", "ERROR", ['error' => mysqli_stmt_error($tracking_stmt)]);
                }
                mysqli_stmt_close($tracking_stmt);
            }
            
            $this->log("Proof of delivery uploaded successfully", "INFO", ['pod_id' => $pod_id]);
            
            return [
                'success' => true,
                'message' => 'Proof of delivery uploaded successfully',
                'pod_id' => $pod_id
            ];
        } catch (Exception $e) {
            $this->log("Error uploading POD", "ERROR", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get deliveries for driver
     */
    public function getDeliveriesForDriver($driver_id, $status = null) {
        $query = "SELECT 
            lt.id,
            lt.order_id,
            lt.driver_id,
            lt.current_status,
            lt.driver_name,
            lt.driver_phone,
            lt.current_latitude,
            lt.current_longitude,
            o.order_number,
            o.customer_name,
            o.customer_phone,
            o.delivery_address,
            o.customer_email,
            o.total_amount,
            o.special_instructions,
            o.delivery_date,
            o.delivery_time,
            lt.created_at,
            lt.notes as delivery_notes
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id
        WHERE lt.driver_id = ?";
        
        $params = [$driver_id];
        $types = "i";
        
        if ($status !== null) {
            $query .= " AND lt.current_status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $query .= " ORDER BY lt.created_at DESC";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return [];
        }
        
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $deliveries = [];
        
        while ($delivery = mysqli_fetch_assoc($result)) {
            $deliveries[] = $delivery;
        }
        mysqli_stmt_close($stmt);
        
        return $deliveries;
    }
    
    /**
     * Get driver delivery history (completed, failed, cancelled)
     */
    public function getDriverHistory($driver_id) {
        $query = "SELECT 
            lt.id,
            lt.order_id,
            lt.driver_id,
            lt.current_status,
            lt.driver_name,
            lt.driver_phone,
            lt.current_latitude,
            lt.current_longitude,
            o.order_number,
            o.customer_name,
            o.customer_phone,
            o.delivery_address,
            o.customer_email,
            o.total_amount,
            o.special_instructions,
            o.delivery_date,
            o.delivery_time,
            lt.created_at,
            lt.updated_at,
            lt.proof_of_delivery_path,
            lt.notes as delivery_notes
        FROM logistics_tracking lt
        JOIN orders o ON lt.order_id = o.id
        WHERE lt.driver_id = ? 
        AND lt.current_status IN ('delivered', 'cancelled', 'failed')
        ORDER BY lt.created_at DESC";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare driver history query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return [];
        }
        
        mysqli_stmt_bind_param($stmt, "i", $driver_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $history = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $history[] = $row;
        }
        mysqli_stmt_close($stmt);
        
        return $history;
    }
    
    /**
     * Get driver statistics
     */
    public function getDriverStats($driver_id) {
        $query = "SELECT * FROM driver_delivery_stats WHERE driver_id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare driver stats query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return null;
        }
        
        mysqli_stmt_bind_param($stmt, "i", $driver_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $stats = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        return $stats;
    }
    
    /**
     * Update driver statistics after delivery
     */
    public function updateDriverStats($driver_id, $delivery_successful = true, $delivery_time_minutes = 0, $distance_km = 0) {
        try {
            $successful = $delivery_successful ? 1 : 0;
            
            // First try INSERT with ON DUPLICATE KEY UPDATE
            $query = "INSERT INTO driver_delivery_stats 
                     (driver_id, total_deliveries, successful_deliveries, failed_deliveries, avg_delivery_time_minutes, total_distance_km)
                     VALUES (?, 1, ?, 0, ?, ?)
                     ON DUPLICATE KEY UPDATE
                     total_deliveries = total_deliveries + 1,
                     successful_deliveries = successful_deliveries + ?,
                     failed_deliveries = failed_deliveries + ?,
                     total_distance_km = total_distance_km + ?,
                     avg_delivery_time_minutes = (avg_delivery_time_minutes * (total_deliveries - 1) + ?) / total_deliveries,
                     last_updated = NOW()";
            
            $stmt = mysqli_prepare($this->conn, $query);
            if (!$stmt) {
                $this->log("Failed to prepare driver stats query", "ERROR", ['error' => mysqli_error($this->conn)]);
                return false;
            }
            
            $failed = $delivery_successful ? 0 : 1;
            $delivery_time_minutes = floatval($delivery_time_minutes);
            $distance_km = floatval($distance_km);
            
            // Bind parameters for INSERT + ON DUPLICATE UPDATE
            // Values: driver_id, successful_on_insert, delivery_time_on_insert, distance_on_insert, 
            //         successful_on_update, failed_on_update, distance_on_update, delivery_time_on_update
            mysqli_stmt_bind_param($stmt, "iddiidid", 
                $driver_id, $successful, $delivery_time_minutes, $distance_km,
                $successful, $failed, $distance_km, $delivery_time_minutes
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                $this->log("Failed to execute driver stats update", "ERROR", ['error' => mysqli_stmt_error($stmt)]);
                mysqli_stmt_close($stmt);
                return false;
            }
            
            mysqli_stmt_close($stmt);
            $this->log("Driver stats updated successfully", "INFO", ['driver_id' => $driver_id, 'successful' => $delivery_successful]);
            return true;
        } catch (Exception $e) {
            $this->log("Error updating driver stats", "ERROR", ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Update order tracking status
     */
    private function updateOrderTrackingStatus($order_id, $status) {
        $query = "UPDATE orders SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare order status update query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return;
        }

        mysqli_stmt_bind_param($stmt, "si", $status, $order_id);
        if (!mysqli_stmt_execute($stmt)) {
            $this->log("Failed to execute order status update", "ERROR", ['error' => mysqli_stmt_error($stmt)]);
        }
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Get nearby available drivers for map
     */
    public function getNearbyDrivers($latitude, $longitude, $radius_km = 10) {
        $driver_role_sql = $this->getDriverRoleCondition('e', 'd');
        $query = "
            SELECT 
                e.id, e.first_name, e.last_name,
                gt.current_latitude, gt.current_longitude,
                (6371 * acos(cos(radians(?)) * cos(radians(gt.current_latitude)) * 
                 cos(radians(gt.current_longitude) - radians(?)) + 
                 sin(radians(?)) * sin(radians(gt.current_latitude)))) AS distance_km,
                lt.order_id, lt.current_status
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN employees_geo_tracking gt ON e.id = gt.employee_id
            LEFT JOIN logistics_tracking lt ON e.id = lt.driver_id AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
            WHERE e.status = 'active'
            AND {$driver_role_sql}
            AND gt.current_latitude IS NOT NULL
            HAVING distance_km <= ?
            ORDER BY distance_km ASC
        ";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            $this->log("Failed to prepare nearby drivers query", "ERROR", ['error' => mysqli_error($this->conn)]);
            return [];
        }
        
        mysqli_stmt_bind_param($stmt, "dddd", $latitude, $longitude, $latitude, $radius_km);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $drivers = [];
        
        while ($driver = mysqli_fetch_assoc($result)) {
            $drivers[] = $driver;
        }
        mysqli_stmt_close($stmt);
        
        return $drivers;
    }

    /**
     * Get driver statistics for the current week
     */
    public function getDriverWeeklyStats($driver_id) {
        // Calculate start and end of current week (Monday to Sunday)
        $start_of_week = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end_of_week = date('Y-m-d 23:59:59', strtotime('sunday this week'));

        // Calculate total completed and average time (in minutes) from creation to delivery
        // Fallback to updated_at if delivery_time is not set
        $query = "SELECT 
                    COUNT(*) as total_completed_weekly,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(delivery_time, updated_at))) as avg_time_weekly
                  FROM logistics_tracking 
                  WHERE driver_id = ? 
                  AND current_status = 'delivered'
                  AND COALESCE(delivery_time, updated_at) BETWEEN ? AND ?";
        
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
             $this->log("Failed to prepare weekly stats query", "ERROR", ['error' => mysqli_error($this->conn)]);
             return ['total_weekly' => 0, 'avg_time_weekly' => 0];
        }
        
        mysqli_stmt_bind_param($stmt, "iss", $driver_id, $start_of_week, $end_of_week);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $stats = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        return [
            'total_weekly' => (int)($stats['total_completed_weekly'] ?? 0),
            'avg_time_weekly' => round((float)($stats['avg_time_weekly'] ?? 0))
        ];
    }
}

?>
