<?php
/**
 * ============================================================================
 * FORECASTING ENGINE - Decision Support System
 * ============================================================================
 * Provides sales forecasting, demand prediction, and decision recommendations
 * 
 * Features:
 * - Time-series forecasting (moving average, exponential smoothing)
 * - Product-level demand forecasting
 * - Revenue projections
 * - Seasonal/event adjustments
 * - Decision recommendations based on forecasts
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class ForecastingEngine {
    private $conn;
    private $config = [];
    private $MIN_DAYS_OF_DATA = 7;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->loadConfig();
    }
    
    /**
     * Load forecasting configuration from database
     */
    private function loadConfig() {
        $query = "SELECT config_key, config_value FROM forecasting_config";
        $result = $this->conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $this->config[$row['config_key']] = $row['config_value'];
        }
    }
    
    /**
     * Generate comprehensive forecast (7-day and 30-day)
     */
    public function generateForecast($days = 7, $include_product_level = true) {
        try {
            $results = [
                'daily_orders' => $this->forecastDailyOrders($days),
                'revenue' => $this->forecastRevenue($days),
                'product_demands' => $include_product_level ? $this->forecastProductDemands($days) : [],
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            return $results;
        } catch (Exception $e) {
            error_log("Forecasting Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * CORE: Forecast daily order volume using exponential smoothing
     * 
     * @param int $days - Number of days to forecast
     * @return array - Forecast data with confidence levels
     */
    private function forecastDailyOrders($days) {
        // 1. Get historical daily order data (last 60 days)
        $historical_data = $this->getHistoricalDailyOrders(60);
        
        if (count($historical_data) < $this->MIN_DAYS_OF_DATA) {
            return [
                'status' => 'insufficient_data',
                'message' => 'Need at least ' . $this->MIN_DAYS_OF_DATA . ' days of history',
                'available_days' => count($historical_data)
            ];
        }
        
        // 2. Calculate trend and level using exponential smoothing
        $smoothing_params = $this->exponentialSmoothing($historical_data);
        
        // 3. Apply seasonal adjustments and events
        $event_adjustments = $this->getEventAdjustments($days);
        
        // 4. Generate forecast
        $forecast = [];
        $current_level = $smoothing_params['level'];
        $current_trend = $smoothing_params['trend'];
        
        for ($i = 1; $i <= $days; $i++) {
            $forecast_date = date('Y-m-d', strtotime("+$i days"));
            
            // Base forecast
            $predicted_value = $current_level + ($current_trend * $i);
            
            // Apply event multiplier if applicable
            $event_multiplier = $event_adjustments[$forecast_date] ?? 1.0;
            $adjusted_prediction = $predicted_value * $event_multiplier;
            
            // Confidence decreases with forecast distance
            $confidence = max(0.60, 0.90 - (($i / $days) * 0.30));
            
            // Store forecast
            $forecast[] = [
                'date' => $forecast_date,
                'predicted_orders' => round($adjusted_prediction, 2),
                'base_prediction' => round($predicted_value, 2),
                'event_impact' => $event_multiplier,
                'confidence_level' => round($confidence, 2),
                'trend' => $current_trend > 0 ? 'increasing' : ($current_trend < 0 ? 'decreasing' : 'stable')
            ];
            
            // Store in database
            $this->saveForecast('daily_orders', $forecast_date, round($adjusted_prediction, 2), $confidence, 'exponential_smoothing');
        }
        
        return [
            'status' => 'success',
            'forecast_period' => $days,
            'forecasts' => $forecast,
            'historical_avg' => round(array_sum($historical_data) / count($historical_data), 2),
            'smoothing_params' => $smoothing_params
        ];
    }
    
    /**
     * Exponential Smoothing Algorithm
     * Holt's linear trend method
     */
    private function exponentialSmoothing($data) {
        $alpha = 0.3; // Level smoothing parameter
        $beta = 0.1;  // Trend smoothing parameter
        
        $level = $data[0];
        $trend = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            $prev_level = $level;
            $level = ($alpha * $data[$i]) + ((1 - $alpha) * ($prev_level + $trend));
            $trend = ($beta * ($level - $prev_level)) + ((1 - $beta) * $trend);
        }
        
        return [
            'level' => round($level, 2),
            'trend' => round($trend, 4),
            'alpha' => $alpha,
            'beta' => $beta
        ];
    }
    
    /**
     * Get historical daily order count (aggregated by date)
     */
    private function getHistoricalDailyOrders($days_back = 60) {
        $start_date = date('Y-m-d', strtotime("-$days_back days"));
        
        $query = "
            SELECT DATE(created_at) as order_date, COUNT(*) as order_count
            FROM orders
            WHERE created_at >= '$start_date'
            AND is_archived = 0
            AND status != 'cancelled'
            GROUP BY DATE(created_at)
            ORDER BY order_date ASC
        ";
        
        $result = $this->conn->query($query);
        $data = [];
        
        while ($row = $result->fetch_assoc()) {
            $data[(string)$row['order_date']] = (float)$row['order_count'];
        }
        
        // Fill gaps with 0 orders
        $current = strtotime($start_date);
        $end = strtotime('today');
        $filled_data = [];
        
        while ($current <= $end) {
            $date_str = date('Y-m-d', $current);
            $filled_data[] = $data[$date_str] ?? 0;
            $current = strtotime('+1 day', $current);
        }
        
        return $filled_data;
    }
    
    /**
     * Forecast revenue based on order volume and average order value
     */
    private function forecastRevenue($days) {
        // Get daily order forecast
        $order_forecast = $this->forecastDailyOrders($days);
        
        if ($order_forecast['status'] !== 'success') {
            return $order_forecast;
        }
        
        // Get average order value from last 30 days
        $aov = $this->getAverageOrderValue(30);
        
        $revenue_forecast = [];
        
        foreach ($order_forecast['forecasts'] as $forecast) {
            $predicted_revenue = $forecast['predicted_orders'] * $aov;
            
            $revenue_forecast[] = [
                'date' => $forecast['date'],
                'predicted_revenue' => round($predicted_revenue, 2),
                'predicted_orders' => $forecast['predicted_orders'],
                'avg_order_value' => round($aov, 2),
                'confidence_level' => $forecast['confidence_level']
            ];
            
            // Store in database
            $this->saveForecast('revenue', $forecast['date'], $predicted_revenue, $forecast['confidence_level'], 'aov_multiplier');
        }
        
        $total_forecasted = array_sum(array_column($revenue_forecast, 'predicted_revenue'));
        
        return [
            'status' => 'success',
            'forecast_period' => $days,
            'forecasts' => $revenue_forecast,
            'total_forecasted_revenue' => round($total_forecasted, 2),
            'daily_average' => round($total_forecasted / $days, 2)
        ];
    }
    
    /**
     * Forecast product-level demand
     */
    private function forecastProductDemands($days) {
        $product_history = $this->getProductHistoricalDemand(60);
        
        $product_forecasts = [];
        
        foreach ($product_history as $product_id => $history) {
            if (count($history['quantities']) < 3) continue;
            
            // Simple trend analysis
            $trend = $this->calculateTrend($history['quantities']);
            $avg_qty = array_sum($history['quantities']) / count($history['quantities']);
            $price_avg = $history['avg_price'];
            
            for ($i = 1; $i <= $days; $i++) {
                $forecast_date = date('Y-m-d', strtotime("+$i days"));
                
                // Predicted quantity with trend adjustment
                $predicted_qty = max(0, $avg_qty + ($trend * $i / 10));
                $predicted_revenue = $predicted_qty * $price_avg;
                $confidence = max(0.60, 0.85 - (($i / $days) * 0.25));
                
                $product_forecasts[] = [
                    'product_id' => $product_id,
                    'product_name' => $history['name'],
                    'date' => $forecast_date,
                    'predicted_quantity' => round($predicted_qty, 2),
                    'predicted_revenue' => round($predicted_revenue, 2),
                    'trend' => $trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'stable'),
                    'trend_strength' => round(abs($trend), 2),
                    'confidence_level' => round($confidence, 2)
                ];
                
                // Store in database
                $this->saveProductForecast($product_id, $forecast_date, $predicted_qty, $predicted_revenue, $confidence, $trend);
            }
        }
        
        return [
            'status' => 'success',
            'forecast_period' => $days,
            'total_products_forecasted' => count(array_unique(array_column($product_forecasts, 'product_id'))),
            'forecasts' => $product_forecasts
        ];
    }
    
    /**
     * Get product historical demand data
     */
    private function getProductHistoricalDemand($days_back = 60) {
        $start_date = date('Y-m-d', strtotime("-$days_back days"));
        
        $query = "
            SELECT 
                p.product_id,
                p.product_name,
                p.price,
                oi.quantity,
                DATE(o.created_at) as order_date
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.order_id
            JOIN products p ON oi.product_id = p.product_id
            WHERE o.created_at >= '$start_date'
            AND o.status != 'cancelled'
            ORDER BY o.created_at ASC
        ";
        
        $result = $this->conn->query($query);
        $product_data = [];
        
        while ($row = $result->fetch_assoc()) {
            $pid = $row['product_id'];
            if (!isset($product_data[$pid])) {
                $product_data[$pid] = [
                    'name' => $row['product_name'],
                    'quantities' => [],
                    'prices' => [],
                    'dates' => []
                ];
            }
            $product_data[$pid]['quantities'][] = (float)$row['quantity'];
            $product_data[$pid]['prices'][] = (float)$row['price'];
            $product_data[$pid]['dates'][] = $row['order_date'];
        }
        
        // Calculate averages
        foreach ($product_data as $pid => &$data) {
            $data['avg_quantity'] = array_sum($data['quantities']) / count($data['quantities']);
            $data['avg_price'] = array_sum($data['prices']) / count($data['prices']);
        }
        
        return $product_data;
    }
    
    /**
     * Calculate trend from historical data
     * Returns trend value (positive = increasing, negative = decreasing)
     */
    private function calculateTrend($data) {
        if (count($data) < 2) return 0;
        
        $n = count($data);
        $sum_x = $n * ($n + 1) / 2;
        $sum_y = array_sum($data);
        $sum_xy = 0;
        $sum_x2 = $n * ($n + 1) * (2 * $n + 1) / 6;
        
        for ($i = 0; $i < $n; $i++) {
            $sum_xy += ($i + 1) * $data[$i];
        }
        
        $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_x2 - $sum_x * $sum_x);
        
        return $slope;
    }
    
    /**
     * Get average order value
     */
    private function getAverageOrderValue($days = 30) {
        $start_date = date('Y-m-d', strtotime("-$days days"));
        
        $query = "
            SELECT AVG(total_amount) as avg_value
            FROM orders
            WHERE created_at >= '$start_date'
            AND status != 'cancelled'
        ";
        
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['avg_value'] ?? 0;
    }
    
    /**
     * Get event adjustments (holidays, promotions, etc)
     */
    private function getEventAdjustments($days) {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+$days days"));
        
        $query = "
            SELECT event_date, impact_multiplier
            FROM business_events
            WHERE event_date BETWEEN '$start_date' AND '$end_date'
            AND is_active = 1
        ";
        
        $result = $this->conn->query($query);
        $adjustments = [];
        
        while ($row = $result->fetch_assoc()) {
            $adjustments[$row['event_date']] = (float)$row['impact_multiplier'];
        }
        
        return $adjustments;
    }
    
    /**
     * Save forecast to database
     */
    private function saveForecast($type, $date, $value, $confidence, $model) {
        $query = "
            INSERT INTO forecasts 
            (forecast_type, metric_name, predicted_value, confidence_level, model_used, forecast_start_date, forecast_end_date)
            VALUES ('$type', '$type', $value, $confidence, '$model', '$date', '$date')
            ON DUPLICATE KEY UPDATE 
            predicted_value = $value, 
            confidence_level = $confidence, 
            updated_at = NOW()
        ";
        
        return $this->conn->query($query);
    }
    
    /**
     * Save product forecast to database
     */
    private function saveProductForecast($product_id, $forecast_date, $qty, $revenue, $confidence, $trend) {
        $trend_str = $trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'stable');
        $trend_strength = abs($trend);
        
        $query = "
            INSERT INTO product_demand_forecasts 
            (product_id, forecast_date, predicted_quantity, predicted_revenue, confidence_level, trend, trend_strength)
            VALUES ($product_id, '$forecast_date', $qty, $revenue, $confidence, '$trend_str', $trend_strength)
            ON DUPLICATE KEY UPDATE 
            predicted_quantity = $qty, 
            predicted_revenue = $revenue, 
            confidence_level = $confidence
        ";
        
        return $this->conn->query($query);
    }
    
    /**
     * Get latest forecasts from database
     */
    public function getLatestForecasts($forecast_type = null, $days_ahead = 7) {
        $where = "forecast_end_date >= CURDATE()";
        
        if ($forecast_type) {
            $where .= " AND forecast_type = '$forecast_type'";
        }
        
        $query = "
            SELECT * FROM forecasts
            WHERE $where
            ORDER BY forecast_start_date DESC
            LIMIT 100
        ";
        
        $result = $this->conn->query($query);
        $forecasts = [];
        
        while ($row = $result->fetch_assoc()) {
            $forecasts[] = $row;
        }
        
        return $forecasts;
    }
    
    /**
     * Manual system health check
     */
    public function getSystemHealth() {
        $metrics = [];
        
        // Check data freshness
        $query = "SELECT MAX(created_at) as last_order FROM orders";
        $result = $this->conn->query($query);
        $last_order = $result->fetch_assoc()['last_order'];
        $hours_ago = (strtotime('now') - strtotime($last_order)) / 3600;
        $metrics['data_freshness_hours'] = round($hours_ago, 1);
        $metrics['data_fresh'] = $hours_ago < 24;
        
        // Check forecasts staleness
        $query = "SELECT MAX(created_at) as last_forecast FROM forecasts";
        $result = $this->conn->query($query);
        $last_forecast = $result->fetch_assoc()['last_forecast'];
        $forecast_hours_old = (strtotime('now') - strtotime($last_forecast)) / 3600;
        $metrics['forecast_freshness_hours'] = round($forecast_hours_old, 1);
        $metrics['forecasts_fresh'] = $forecast_hours_old < 24;
        
        // Order count
        $query = "SELECT COUNT(*) as total_orders FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)";
        $result = $this->conn->query($query);
        $metrics['orders_last_60_days'] = $result->fetch_assoc()['total_orders'];
        
        return $metrics;
    }
}

?>
