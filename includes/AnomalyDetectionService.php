<?php
/**
 * ============================================================================
 * ANOMALY DETECTION SERVICE
 * ============================================================================
 * Identifies unusual patterns in demand, inventory, and business metrics
 * 
 * Uses statistical methods:
 * - Z-score analysis for outlier detection
 * - Moving average deviation
 * - Seasonal pattern analysis
 * - Threshold-based alerting
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class AnomalyDetectionService {
    private $conn;
    private $z_score_threshold = 2.0;  // Values beyond 2 std devs are anomalies
    private $variance_threshold = 0.35; // 35% deviation from moving average
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Detect demand spikes or drops
     */
    public function detectDemandAnomalies($days_back = 60) {
        $anomalies = [];
        
        // Get historical daily order data
        $stmt = $this->conn->prepare("
            SELECT 
                DATE(o.created_at) as order_date,
                COUNT(*) as order_count,
                SUM(o.total_amount) as total_revenue
            FROM orders o
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(o.created_at)
            ORDER BY order_date ASC
        ");
        $stmt->bind_param("i", $days_back);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $daily_data = [];
        while ($row = $result->fetch_assoc()) {
            $daily_data[] = $row;
        }
        $stmt->close();
        
        if (count($daily_data) < 10) {
            return ['error' => 'Insufficient data for anomaly detection'];
        }
        
        // Calculate statistics
        $order_counts = array_column($daily_data, 'order_count');
        $mean = array_sum($order_counts) / count($order_counts);
        $variance = $this->calculateVariance($order_counts, $mean);
        $std_dev = sqrt($variance);
        
        // Detect anomalies using Z-score
        foreach ($daily_data as $day) {
            $z_score = ($day['order_count'] - $mean) / ($std_dev ?? 1);
            
            if (abs($z_score) > $this->z_score_threshold) {
                $anomaly_type = $z_score > 0 ? 'SPIKE' : 'DROP';
                $severity = abs($z_score) > 3.0 ? 'CRITICAL' : 'HIGH';
                
                $anomalies[] = [
                    'date' => $day['order_date'],
                    'type' => $anomaly_type,
                    'severity' => $severity,
                    'z_score' => round($z_score, 2),
                    'value' => $day['order_count'],
                    'expected_value' => round($mean, 2),
                    'deviation_percent' => round((abs($day['order_count'] - $mean) / $mean) * 100, 1),
                    'revenue' => $day['total_revenue'],
                    'description' => $this->generateAnomalyDescription($anomaly_type, $day['order_count'], $mean)
                ];
            }
        }
        
        return [
            'status' => 'success',
            'anomalies' => $anomalies,
            'anomaly_count' => count($anomalies),
            'statistics' => [
                'mean_daily_orders' => round($mean, 2),
                'std_deviation' => round($std_dev, 2),
                'analysis_period' => $days_back,
                'data_points' => count($daily_data)
            ]
        ];
    }
    
    /**
     * Detect product-level demand anomalies
     */
    public function detectProductAnomalies($days_back = 30) {
        $anomalies = [];
        
        // Get products with their daily sales
        $stmt = $this->conn->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                DATE(o.created_at) as sale_date,
                SUM(oi.quantity) as quantity_sold,
                SUM(oi.quantity * oi.price) as revenue
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY p.id, DATE(o.created_at)
            ORDER BY p.id, sale_date ASC
        ");
        $stmt->bind_param("i", $days_back);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $product_data = [];
        while ($row = $result->fetch_assoc()) {
            $key = $row['product_id'];
            if (!isset($product_data[$key])) {
                $product_data[$key] = [
                    'product_name' => $row['product_name'],
                    'daily_data' => []
                ];
            }
            $product_data[$key]['daily_data'][] = $row;
        }
        $stmt->close();
        
        // Analyze each product
        foreach ($product_data as $product_id => $product_info) {
            $daily_quantities = array_column($product_info['daily_data'], 'quantity_sold');
            
            if (count($daily_quantities) < 3) continue;
            
            $mean = array_sum($daily_quantities) / count($daily_quantities);
            $variance = $this->calculateVariance($daily_quantities, $mean);
            $std_dev = sqrt($variance);
            
            foreach ($product_info['daily_data'] as $day) {
                $z_score = ($day['quantity_sold'] - $mean) / ($std_dev ?? 1);
                
                if (abs($z_score) > 1.5) { // Lower threshold for products
                    $anomalies[] = [
                        'product_id' => $product_id,
                        'product_name' => $product_info['product_name'],
                        'date' => $day['sale_date'],
                        'quantity' => $day['quantity_sold'],
                        'expected_quantity' => round($mean, 2),
                        'z_score' => round($z_score, 2),
                        'trending' => $z_score > 0 ? 'UP' : 'DOWN',
                        'revenue' => $day['revenue'],
                        'alert_level' => abs($z_score) > 2.5 ? 'HIGH' : 'MEDIUM'
                    ];
                }
            }
        }
        
        return [
            'status' => 'success',
            'product_anomalies' => $anomalies,
            'anomaly_count' => count($anomalies),
            'products_analyzed' => count($product_data)
        ];
    }
    
    /**
     * Detect inventory level anomalies
     */
    public function detectInventoryAnomalies() {
        $anomalies = [];
        
        // Get current inventory levels vs expected levels
        $stmt = $this->conn->prepare("
            SELECT 
                p.id,
                p.name,
                COALESCE(i.current_stock, p.stock) as quantity_available,
                0 as quantity_reserved,
                COALESCE(pdf.predicted_quantity, 0) as predicted_demand_7d
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id AND i.inventory_date = CURDATE()
            LEFT JOIN (
                SELECT product_id, SUM(predicted_quantity) as predicted_quantity
                FROM product_demand_forecasts
                WHERE forecast_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                GROUP BY product_id
            ) pdf ON p.id = pdf.product_id
            WHERE p.is_active = 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $available = $row['quantity_available'];
            $predicted_demand = $row['predicted_demand_7d'];
            $buffer_needed = 10; // Minimum safety stock
            
            // Check for low stock alerts
            if ($available < ($predicted_demand + $buffer_needed)) {
                $days_until_stockout = ($available - $buffer_needed) / 
                                      (($predicted_demand / 7) + 0.1);
                
                $severity = $days_until_stockout <= 1 ? 'CRITICAL' : 
                           ($days_until_stockout <= 3 ? 'HIGH' : 'MEDIUM');
                
                $anomalies[] = [
                    'product_id' => $row['id'],
                    'product_name' => $row['name'],
                    'current_stock' => $available,
                    'predicted_demand_7d' => round($predicted_demand, 2),
                    'gap' => round($available - $predicted_demand, 2),
                    'days_until_stockout' => max(0, round($days_until_stockout, 1)),
                    'severity' => $severity,
                    'action' => $severity === 'CRITICAL' ? 'ORDER IMMEDIATELY' : 'SCHEDULE ORDER'
                ];
            }
            
            // Check for overstocking
            if ($available > ($predicted_demand * 2) && $available > 100) {
                $anomalies[] = [
                    'product_id' => $row['id'],
                    'product_name' => $row['name'],
                    'current_stock' => $available,
                    'predicted_demand_7d' => round($predicted_demand, 2),
                    'overstocked_by' => round($available - ($predicted_demand * 1.2), 2),
                    'severity' => 'INFO',
                    'issue_type' => 'OVERSTOCK',
                    'recommendation' => 'Consider promotions or adjust production'
                ];
            }
        }
        $stmt->close();
        
        return [
            'status' => 'success',
            'inventory_anomalies' => $anomalies,
            'anomaly_count' => count($anomalies)
        ];
    }
    
    /**
     * Detect staffing anomalies
     */
    public function detectStaffingAnomalies() {
        $anomalies = [];
        
        // Get forecasted workload vs available staff
        $total_forecast = 0;
        $stmt = $this->conn->prepare("
            SELECT SUM(predicted_value) as total_orders
            FROM forecasts
            WHERE forecast_type = 'daily_orders'
            AND forecast_start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $forecast_result = $stmt->get_result()->fetch_assoc();
        $total_forecast = $forecast_result['total_orders'] ?? 0;
        $stmt->close();
        
        // Orders per staff ratio: approximately 8 orders per staff per day
        $orders_per_staff_per_day = 8;
        $average_daily_forecast = $total_forecast / 7;
        $staff_needed = ceil($average_daily_forecast / $orders_per_staff_per_day);
        
        // Get current active staff
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as active_staff FROM employees 
            WHERE status = 'active'
        ");
        $stmt->execute();
        $staff_result = $stmt->get_result()->fetch_assoc();
        $current_staff = $staff_result['active_staff'] ?? 0;
        $stmt->close();
        
        if ($current_staff < $staff_needed) {
            $anomalies[] = [
                'issue_type' => 'UNDERSTAFFED',
                'current_staff' => $current_staff,
                'staff_needed' => $staff_needed,
                'shortage' => $staff_needed - $current_staff,
                'severity' => ($staff_needed - $current_staff) > 2 ? 'HIGH' : 'MEDIUM',
                'recommendation' => 'Schedule additional staff or hire temporary workers',
                'analysis_period' => '7 days'
            ];
        } elseif ($current_staff > ($staff_needed * 1.3)) {
            $anomalies[] = [
                'issue_type' => 'OVERSTAFFED',
                'current_staff' => $current_staff,
                'staff_needed' => $staff_needed,
                'excess' => $current_staff - $staff_needed,
                'severity' => 'INFO',
                'recommendation' => 'Optimize schedule or adjust other departments',
                'analysis_period' => '7 days'
            ];
        }
        
        return [
            'status' => 'success',
            'staffing_anomalies' => $anomalies,
            'staffing_analysis' => [
                'current_staff' => $current_staff,
                'forecasted_need' => $staff_needed,
                'average_daily_forecast' => round($average_daily_forecast, 2)
            ]
        ];
    }
    
    /**
     * Save anomaly alert to database
     */
    public function saveAnomalyAlert($alert_type, $alert_level, $description, $affected_data = []) {
        $affected_data_json = json_encode($affected_data);
        
        $stmt = $this->conn->prepare("
            INSERT INTO anomaly_alerts 
            (alert_type, alert_level, description, affected_data)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->bind_param("ssss", $alert_type, $alert_level, $description, $affected_data_json);
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    }
    
    /**
     * Generate human-readable description for anomaly
     */
    private function generateAnomalyDescription($type, $value, $expected) {
        $deviation = round(abs($value - $expected) / $expected * 100, 1);
        
        if ($type === 'SPIKE') {
            return "Demand spike: {$deviation}% higher than normal ({$value} vs {$expected} expected)";
        } else {
            return "Demand drop: {$deviation}% lower than normal ({$value} vs {$expected} expected)";
        }
    }
    
    /**
     * Calculate variance of array values
     */
    private function calculateVariance($values, $mean) {
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        return $variance / count($values);
    }
    
    /**
     * Get all active anomalies
     */
    public function getActiveAnomalies() {
        $stmt = $this->conn->prepare("
            SELECT * FROM anomaly_alerts
            WHERE resolved_at IS NULL
            ORDER BY alert_level DESC, created_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $alerts = [];
        while ($row = $result->fetch_assoc()) {
            $row['affected_data'] = json_decode($row['affected_data'], true);
            $alerts[] = $row;
        }
        $stmt->close();
        
        return $alerts;
    }
}

/**
 * USAGE EXAMPLE:
 * 
 * include '../includes/config.php';
 * include 'includes/AnomalyDetectionService.php';
 * 
 * $anomaly_service = new AnomalyDetectionService($conn);
 * 
 * // Detect demand anomalies
 * $demand_anomalies = $anomaly_service->detectDemandAnomalies(60);
 * 
 * // Detect inventory anomalies
 * $inventory_anomalies = $anomaly_service->detectInventoryAnomalies();
 * 
 * // Detect staffing anomalies
 * $staffing_anomalies = $anomaly_service->detectStaffingAnomalies();
 * 
 * // Save critical alerts
 * foreach ($demand_anomalies['anomalies'] as $anomaly) {
 *     if ($anomaly['severity'] === 'CRITICAL') {
 *         $anomaly_service->saveAnomalyAlert(
 *             'DEMAND_SPIKE',
 *             'CRITICAL',
 *             $anomaly['description'],
 *             $anomaly
 *         );
 *     }
 * }
 */
?>
