<?php
/**
 * ============================================================================
 * DECISION RECOMMENDATION SYSTEM
 * ============================================================================
 * Generates actionable recommendations based on forecasts
 * 
 * Categories:
 * - Inventory: What and how much to stock
 * - Staffing: How many employees to schedule
 * - Production: How many lechons to prepare
 * - Pricing: Dynamic pricing suggestions
 * - Marketing: Promotional recommendations
 * - Logistics: Delivery planning
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class DecisionRecommendationSystem {
    private $conn;
    private $forecasting_engine;
    
    public function __construct($database_connection, $forecasting_engine = null) {
        $this->conn = $database_connection;
        $this->forecasting_engine = $forecasting_engine;
    }
    
    /**
     * Generate all recommendations for the period
     */
    public function generateAllRecommendations($forecast_period_days = 7) {
        $recommendations = [];
        
        // Get forecasts
        $daily_forecast = $this->forecasting_engine->generateForecast($forecast_period_days, true);
        
        if ($daily_forecast['status'] !== 'success') {
            return ['error' => 'Unable to generate forecasts'];
        }
        
        // Generate recommendations in each category
        $recommendations['inventory'] = $this->generateInventoryRecommendations($daily_forecast);
        $recommendations['staffing'] = $this->generateStaffingRecommendations($daily_forecast);
        $recommendations['production'] = $this->generateProductionRecommendations($daily_forecast);
        $recommendations['pricing'] = $this->generatePricingRecommendations($daily_forecast);
        $recommendations['marketing'] = $this->generateMarketingRecommendations($daily_forecast);
        $recommendations['logistics'] = $this->generateLogisticsRecommendations($daily_forecast);
        $recommendations['quality_control'] = $this->generateQualityControlRecommendations($daily_forecast);
        $recommendations['quality_control'] = $this->generateQualityControlRecommendations($daily_forecast);
        
        return $recommendations;
    }

    /**
     * NEW DECISION TYPE: QUALITY CONTROL RECOMMENDATIONS
     * Recommends quality checks based on production spikes.
     */
    private function generateQualityControlRecommendations($daily_forecast) {
        $recommendations = [];
        
        // Example Logic: If a product has a very high demand spike, recommend a quality check.
        $product_forecasts = $daily_forecast['product_demands']['forecasts'] ?? [];
        
        foreach ($product_forecasts as $pf) {
            // This is example logic. You would replace this with your own business rule.
            // e.g., check if predicted quantity is > 200% of historical average.
            $is_spike = $pf['predicted_quantity'] > 10; // Simplified for example
            
            if ($is_spike && $pf['confidence_level'] > 0.8) {
                $recommendation = [
                    'product_id' => $pf['product_id'],
                    'product_name' => $pf['product_name'],
                    'forecast_date' => $pf['date'],
                    'predicted_demand' => round($pf['predicted_quantity'], 2),
                    'priority' => 'high',
                    'reason' => "Unusual demand spike predicted for " . $pf['product_name'] . ". Recommend quality spot-check on production batch for " . $pf['date'] . ".",
                    'expected_impact' => 'Ensures product quality during high-volume production.'
                ];
                
                $recommendations[] = $recommendation;
                
                // Save to database with the new category 'quality_control'
                $this->saveRecommendation(
                    'quality_control',
                    $recommendation['reason'],
                    $recommendation['priority'],
                    json_encode($recommendation)
                );
            }
        }
        
        return ['status' => 'success', 'recommendations' => $recommendations];
    }
    
    /**
     * DECISION 1: INVENTORY RECOMMENDATIONS
     * What products to stock and in what quantities
     */
    private function generateInventoryRecommendations($daily_forecast) {
        $recommendations = [];
        
        if (!isset($daily_forecast['product_demands']) || $daily_forecast['status'] !== 'success') {
            return $recommendations;
        }
        
        $product_forecasts = $daily_forecast['product_demands']['forecasts'] ?? [];
        $grouped_by_product = [];
        
        // Group and aggregate by product
        foreach ($product_forecasts as $pf) {
            $pid = $pf['product_id'];
            if (!isset($grouped_by_product[$pid])) {
                $grouped_by_product[$pid] = [
                    'name' => $pf['product_name'],
                    'total_qty' => 0,
                    'trend' => $pf['trend'],
                    'confidence' => $pf['confidence_level']
                ];
            }
            $grouped_by_product[$pid]['total_qty'] += $pf['predicted_quantity'];
        }
        
        // Get current inventory levels
        $current_inventory = $this->getCurrentInventoryLevels();
        
        foreach ($grouped_by_product as $product_id => $data) {
            $current = $current_inventory[$product_id] ?? 0;
            $needed = $data['total_qty'];
            $restock_amount = max(0, $needed - $current);
            
            // Calculate lead time from products table
            $lead_time = $this->getProductLeadTime($product_id);
            
            // Determine priority
            $priority = 'medium';
            if ($restock_amount > 0 && $data['confidence'] > 0.85) {
                $priority = $restock_amount > $current ? 'high' : 'medium';
            }
            
            if ($data['trend'] === 'up' && $restock_amount > 0) {
                $priority = 'critical';
            }
            
            $recommendation = [
                'product_id' => $product_id,
                'product_name' => $data['name'],
                'current_stock' => $current,
                'predicted_demand' => round($needed, 2),
                'recommended_stock' => round($needed + ($needed * 0.2), 2), // 20% buffer
                'restock_needed' => round($restock_amount, 2),
                'priority' => $priority,
                'reason' => "Predicted demand is " . round($needed, 2) . " units with " . 
                            ($data['trend'] === 'up' ? 'increasing' : 'stable') . " trend",
                'lead_time_days' => $lead_time,
                'expected_impact' => 'Prevents stockouts and lost sales'
            ];
            
            $recommendations[] = $recommendation;
            
            // Save to database
            $this->saveRecommendation(
                'inventory',
                $recommendation['reason'],
                $priority,
                json_encode($recommendation)
            );
        }
        
        return [
            'status' => 'success',
            'total_recommendations' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * DECISION 2: STAFFING RECOMMENDATIONS
     * How many staff to schedule based on predicted order volume
     */
    private function generateStaffingRecommendations($daily_forecast) {
        $recommendations = [];
        
        if (!isset($daily_forecast['daily_orders'])) {
            return $recommendations;
        }
        
        $order_forecasts = $daily_forecast['daily_orders']['forecasts'] ?? [];
        $historical_avg = $daily_forecast['daily_orders']['historical_avg'] ?? 5;
        
        // Get current staffing
        $query = "SELECT COUNT(*) as staff_count FROM employees WHERE employment_status = 'active'";
        $result = $this->conn->query($query);
        $current_staff = $result->fetch_assoc()['staff_count'];
        
        // Orders per staff ratio (assume 8-10 orders per staff per day)
        $orders_per_staff = 8;
        
        foreach ($order_forecasts as $forecast) {
            $predicted_orders = $forecast['predicted_orders'];
            $recommended_staff = ceil($predicted_orders / $orders_per_staff);
            
            // Determine priority
            $priority = 'medium';
            if ($recommended_staff > $current_staff) {
                $priority = $forecast['confidence_level'] > 0.85 ? 'high' : 'medium';
            }
            
            $variance = $recommended_staff - $current_staff;
            $variance_str = $variance > 0 ? "+$variance" : $variance;
            
            $recommendation = [
                'forecast_date' => $forecast['date'],
                'predicted_orders' => round($predicted_orders, 2),
                'current_staff_assigned' => $current_staff,
                'recommended_staff' => $recommended_staff,
                'staff_variance' => $variance_str,
                'priority' => $priority,
                'reason' => "Expecting " . round($predicted_orders, 2) . " orders on " . 
                           $forecast['date'] . " - recommend $recommended_staff staff",
                'expected_impact' => 'Ensures smooth operations and customer satisfaction'
            ];
            
            $recommendations[] = $recommendation;
            
            $this->saveRecommendation(
                'staffing',
                $recommendation['reason'],
                $priority,
                json_encode($recommendation)
            );
        }
        
        return [
            'status' => 'success',
            'current_staff' => $current_staff,
            'total_days_planned' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * DECISION 3: PRODUCTION RECOMMENDATIONS
     * How many lechons and dishes to prepare
     */
    private function generateProductionRecommendations($daily_forecast) {
        $recommendations = [];
        
        // Get product forecasts
        if (!isset($daily_forecast['product_demands'])) {
            return $recommendations;
        }
        
        $product_forecasts = $daily_forecast['product_demands']['forecasts'] ?? [];
        
        // Identify "whole lechon" type products (typically highest value items)
        $lechon_products = $this->getProductsByCategory('whole');
        
        foreach ($lechon_products as $product_id => $product_info) {
            $total_demand = 0;
            $avg_confidence = 0;
            $count = 0;
            
            // Sum predicted demand for this product across forecast period
            foreach ($product_forecasts as $pf) {
                if ($pf['product_id'] == $product_id) {
                    $total_demand += $pf['predicted_quantity'];
                    $avg_confidence += $pf['confidence_level'];
                    $count++;
                }
            }
            
            if ($count === 0) continue;
            
            $avg_confidence = $avg_confidence / $count;
            $prep_time = 24; // Standard 24-hour prep time for whole lechon
            
            // Lead time consideration: start prep now if needed
            $urgent = $total_demand > 0 && $avg_confidence > 0.80;
            
            $recommendation = [
                'product_id' => $product_id,
                'product_name' => $product_info,
                'predicted_total_demand' => round($total_demand, 2),
                'recommended_to_prepare' => ceil($total_demand * 1.1), // 10% buffer
                'prep_time_hours' => $prep_time,
                'priority' => $urgent ? 'critical' : 'high',
                'reason' => "Predicted demand: " . round($total_demand, 2) . " units. " .
                           "Recommend starting prep immediately due to $prep_time hour lead time",
                'expected_impact' => 'Prevents delays and ensures product availability'
            ];
            
            $recommendations[] = $recommendation;
            
            $this->saveRecommendation(
                'production',
                $recommendation['reason'],
                $recommendation['priority'],
                json_encode($recommendation)
            );
        }
        
        return [
            'status' => 'success',
            'total_products_to_produce' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * DECISION 4: PRICING RECOMMENDATIONS
     * Dynamic pricing based on demand predictions
     */
    private function generatePricingRecommendations($daily_forecast) {
        $recommendations = [];
        
        if (!isset($daily_forecast['product_demands'])) {
            return $recommendations;
        }
        
        $product_forecasts = $daily_forecast['product_demands']['forecasts'] ?? [];
        $grouped_by_product = [];
        
        foreach ($product_forecasts as $pf) {
            $pid = $pf['product_id'];
            if (!isset($grouped_by_product[$pid])) {
                $grouped_by_product[$pid] = [
                    'name' => $pf['product_name'],
                    'forecasts' => []
                ];
            }
            $grouped_by_product[$pid]['forecasts'][] = $pf;
        }
        
        foreach ($grouped_by_product as $product_id => $data) {
            // Calculate average predicted demand
            $total_qty = array_sum(array_column($data['forecasts'], 'predicted_quantity'));
            $avg_qty = $total_qty / count($data['forecasts']);
            
            // Get current price and sales velocity
            $query = "SELECT price FROM products WHERE product_id = $product_id";
            $result = $this->conn->query($query);
            $current_price = $result->fetch_assoc()['price'] ?? 0;
            
            // Simple pricing logic
            // If demand is high (above median), consider increasing price by 5-10%
            // If demand is low, consider promotion/discount
            $median_demand = 2; // Business baseline
            $demand_intensity = $avg_qty / $median_demand;
            
            $recommendation = null;
            
            if ($demand_intensity > 1.5) {
                $suggested_price = $current_price * 1.08;
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'current_price' => round($current_price, 2),
                    'suggested_price' => round($suggested_price, 2),
                    'price_adjustment_percent' => 8,
                    'action' => 'INCREASE',
                    'priority' => 'high',
                    'reason' => "High demand predicted ($avg_qty units avg). " .
                               "Increasing price by 8% can improve margins while maintaining sales",
                    'estimated_impact' => round(($suggested_price - $current_price) * $total_qty, 2) . " PHP additional revenue"
                ];
            } elseif ($demand_intensity < 0.7) {
                $suggested_price = $current_price * 0.92;
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'current_price' => round($current_price, 2),
                    'suggested_price' => round($suggested_price, 2),
                    'price_adjustment_percent' => -8,
                    'action' => 'DISCOUNT',
                    'priority' => 'medium',
                    'reason' => "Lower predicted demand ($avg_qty units avg). " .
                               "Consider 8% discount to stimulate demand",
                    'estimated_impact' => "Expected to increase volume by 15-25%"
                ];
            } else {
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'current_price' => round($current_price, 2),
                    'suggested_price' => round($current_price, 2),
                    'price_adjustment_percent' => 0,
                    'action' => 'MAINTAIN',
                    'priority' => 'low',
                    'reason' => "Stable demand pattern ($avg_qty units avg). Current pricing appropriate",
                    'estimated_impact' => 'No change expected'
                ];
            }
            
            if ($recommendation) {
                $recommendations[] = $recommendation;
                
                $this->saveRecommendation(
                    'pricing',
                    $recommendation['reason'],
                    $recommendation['priority'],
                    json_encode($recommendation)
                );
            }
        }
        
        return [
            'status' => 'success',
            'total_recommendations' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * DECISION 5: MARKETING RECOMMENDATIONS
     * Which products to promote based on forecast insights
     */
    private function generateMarketingRecommendations($daily_forecast) {
        $recommendations = [];
        
        if (!isset($daily_forecast['product_demands'])) {
            return $recommendations;
        }
        
        $product_forecasts = $daily_forecast['product_demands']['forecasts'] ?? [];
        $grouped_by_product = [];
        
        foreach ($product_forecasts as $pf) {
            $pid = $pf['product_id'];
            if (!isset($grouped_by_product[$pid])) {
                $grouped_by_product[$pid] = [
                    'name' => $pf['product_name'],
                    'total_demand' => 0,
                    'trend' => $pf['trend'],
                    'avg_confidence' => 0,
                    'count' => 0
                ];
            }
            $grouped_by_product[$pid]['total_demand'] += $pf['predicted_quantity'];
            $grouped_by_product[$pid]['avg_confidence'] += $pf['confidence_level'];
            $grouped_by_product[$pid]['count']++;
        }
        
        // Calculate averages and identify opportunities
        foreach ($grouped_by_product as $product_id => $data) {
            $data['avg_confidence'] = $data['avg_confidence'] / $data['count'];
            
            // High demand with downward trend = PROMOTE to sustain demand
            if ($data['total_demand'] > 5 && $data['trend'] === 'down') {
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'total_predicted_demand' => round($data['total_demand'], 2),
                    'trend' => 'declining',
                    'campaign_type' => 'SUSTAIN_DEMAND',
                    'suggested_promotion' => 'Bundle with side dishes or loyalty discount',
                    'priority' => 'high',
                    'reason' => "Strong product with declining trend. Promote to reverse momentum",
                    'expected_impact' => 'Prevent 15-25% sales drop'
                ];
                
                $recommendations[] = $recommendation;
                
                $this->saveRecommendation(
                    'marketing',
                    $recommendation['reason'],
                    $recommendation['priority'],
                    json_encode($recommendation)
                );
            }
            
            // Low demand with upward trend = PROMOTE to accelerate growth
            if ($data['total_demand'] < 3 && $data['trend'] === 'up') {
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'total_predicted_demand' => round($data['total_demand'], 2),
                    'trend' => 'growing',
                    'campaign_type' => 'ACCELERATE_GROWTH',
                    'suggested_promotion' => 'Feature in social media, email campaign',
                    'priority' => 'medium',
                    'reason' => "Emerging product with growth potential. Invest in promotion",
                    'expected_impact' => 'Could 2-3x sales with targeted marketing'
                ];
                
                $recommendations[] = $recommendation;
                
                $this->saveRecommendation(
                    'marketing',
                    $recommendation['reason'],
                    $recommendation['priority'],
                    json_encode($recommendation)
                );
            }
            
            // Seasonal opportunity
            if ($data['avg_confidence'] > 0.88) {
                $recommendation = [
                    'product_id' => $product_id,
                    'product_name' => $data['name'],
                    'total_predicted_demand' => round($data['total_demand'], 2),
                    'confidence' => round($data['avg_confidence'], 2),
                    'campaign_type' => 'CAPITALIZE',
                    'suggested_promotion' => 'High-confidence forecast. Stock up and promote heavily',
                    'priority' => 'high',
                    'reason' => "High confidence forecast ($data[count] days). Strong opportunity",
                    'expected_impact' => 'Maximize revenue during peak demand period'
                ];
                
                $recommendations[] = $recommendation;
                
                $this->saveRecommendation(
                    'marketing',
                    $recommendation['reason'],
                    $recommendation['priority'],
                    json_encode($recommendation)
                );
            }
        }
        
        return [
            'status' => 'success',
            'total_recommendations' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * DECISION 6: LOGISTICS RECOMMENDATIONS
     * Delivery planning and route optimization
     */
    private function generateLogisticsRecommendations($daily_forecast) {
        $recommendations = [];
        
        if (!isset($daily_forecast['daily_orders'])) {
            return $recommendations;
        }
        
        $order_forecasts = $daily_forecast['daily_orders']['forecasts'] ?? [];
        
        // Get current delivery capacity (drivers)
        $query = "SELECT COUNT(DISTINCT driver_id) as total_drivers FROM logistics_tracking WHERE status = 'active'";
        $result = $this->conn->query($query);
        $current_drivers = $result->fetch_assoc()['total_drivers'] ?? 1;
        
        $avg_orders_per_driver = 5; // Orders per driver per day
        
        foreach ($order_forecasts as $forecast) {
            $predicted_orders = $forecast['predicted_orders'];
            $needed_drivers = ceil($predicted_orders / $avg_orders_per_driver);
            
            $recommendation = [
                'forecast_date' => $forecast['date'],
                'predicted_orders' => round($predicted_orders, 2),
                'predicted_deliveries' => round($predicted_orders * 0.8, 0), // 80% delivery
                'current_drivers' => $current_drivers,
                'drivers_needed' => $needed_drivers,
                'driver_shortage' => max(0, $needed_drivers - $current_drivers),
                'priority' => $needed_drivers > $current_drivers ? 'high' : 'medium',
                'reason' => "Expecting " . round($predicted_orders, 2) . " orders on " .
                           $forecast['date'] . ". Recommend " . $needed_drivers . 
                           " drivers for timely delivery",
                'action_recommendations' => [
                    'Assign ' . $needed_drivers . ' drivers for the day',
                    'Pre-plan delivery routes',
                    'Notify customers of estimated delivery times'
                ],
                'expected_impact' => 'On-time delivery rate 95%+'
            ];
            
            $recommendations[] = $recommendation;
            
            $this->saveRecommendation(
                'logistics',
                $recommendation['reason'],
                $recommendation['priority'],
                json_encode($recommendation)
            );
        }
        
        return [
            'status' => 'success',
            'current_drivers' => $current_drivers,
            'total_days_planned' => count($recommendations),
            'recommendations' => $recommendations
        ];
    }
    
    // ========== HELPER METHODS ==========
    
    /**
     * Get current inventory levels by product
     */
    private function getCurrentInventoryLevels() {
        $query = "
            SELECT product_id, quantity_on_hand
            FROM inventory
            WHERE updated_at = (SELECT MAX(updated_at) FROM inventory)
        ";
        
        $result = $this->conn->query($query);
        $inventory = [];
        
        while ($row = $result->fetch_assoc()) {
            $inventory[$row['product_id']] = $row['quantity_on_hand'];
        }
        
        return $inventory;
    }
    
    /**
     * Get product lead time
     */
    private function getProductLeadTime($product_id) {
        $query = "SELECT lead_time_hours FROM products WHERE product_id = $product_id";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return ($row['lead_time_hours'] ?? 24) / 24; // Convert hours to days
    }
    
    /**
     * Get products by category
     */
    private function getProductsByCategory($category = 'whole') {
        $query = "
            SELECT product_id, product_name
            FROM products
            WHERE product_name LIKE '%$category%'
            OR category = '$category'
        ";
        
        $result = $this->conn->query($query);
        $products = [];
        
        while ($row = $result->fetch_assoc()) {
            $products[$row['product_id']] = $row['product_name'];
        }
        
        return $products;
    }
    
    /**
     * Save recommendation to database
     */
    private function saveRecommendation($category, $recommendation_text, $priority, $details_json = null) {
        $recommendation_text = $this->conn->real_escape_string($recommendation_text);
        $details = $details_json ? $this->conn->real_escape_string($details_json) : 'NULL';
        
        $query = "
            INSERT INTO decisions_recommendations 
            (decision_category, recommendation_text, priority, recommendation_date, status, expected_impact)
            VALUES ('$category', '$recommendation_text', '$priority', CURDATE(), 'pending', '$details')
        ";
        
        return $this->conn->query($query);
    }
    
    /**
     * Get active recommendations
     */
    public function getActiveRecommendations($status = 'pending', $days = 7) {
        $where = "status = '$status'";
        if ($days > 0) {
            $where .= " AND action_start_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
        }
        
        $query = "
            SELECT * FROM decisions_recommendations
            WHERE $where
            ORDER BY priority DESC, recommendation_date DESC
        ";
        
        $result = $this->conn->query($query);
        $recommendations = [];
        
        while ($row = $result->fetch_assoc()) {
            $recommendations[] = $row;
        }
        
        return $recommendations;
    }
}

?>
