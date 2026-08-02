<?php
/**
 * ============================================================================
 * DECISION SCORING SERVICE
 * ============================================================================
 * Scores and ranks decisions based on multiple weighted criteria
 * 
 * Features:
 * - Multi-criteria scoring system
 * - Weighted decisioning
 * - Comparative analysis
 * - Historical tracking
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class DecisionScoringService {
    private $conn;
    
    // Weights for each criterion (must sum to 1.0)
    private $weights = [
        'demand_certainty' => 0.25,          // How confident we are in the forecast
        'cost_efficiency' => 0.20,            // Cost vs. benefit
        'implementation_speed' => 0.15,       // Time to implement
        'risk_level' => 0.20,                 // Risk exposure (negative weighting)
        'strategic_fit' => 0.20               // Alignment with business goals
    ];
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Score a single decision recommendation
     * 
     * @param array $decision_data Decision details
     * @return array Scoring breakdown with total score
     */
    public function scoreDecision($decision_data) {
        $scores = [];
        
        // Calculate individual criterion scores (0-100)
        $scores['demand_certainty'] = $this->calculateDemandCertainty(
            $decision_data['confidence_level'] ?? 0,
            $decision_data['forecast_variance'] ?? 0
        );
        
        $scores['cost_efficiency'] = $this->calculateCostEfficiency(
            $decision_data['expected_cost'] ?? 0,
            $decision_data['expected_benefit'] ?? 0,
            $decision_data['roi_estimate'] ?? 0
        );
        
        $scores['implementation_speed'] = $this->calculateImplementationSpeed(
            $decision_data['implementation_timeline'] ?? 7
        );
        
        $scores['risk_level'] = $this->calculateRiskLevel(
            $decision_data['risk_exposure'] ?? 50,
            $decision_data['market_volatility'] ?? 50,
            $decision_data['dependency_risk'] ?? 30
        );
        
        $scores['strategic_fit'] = $this->calculateStrategicFit(
            $decision_data['category'] ?? 'inventory',
            $decision_data['business_priorities'] ?? []
        );
        
        // Calculate weighted total (0-100)
        $total_score = $this->calculateWeightedTotal($scores);
        
        // Determine ranking percentile
        $ranking = $this->getDecisionRanking($total_score);
        
        return [
            'scores' => $scores,
            'total_score' => round($total_score, 2),
            'ranking' => $ranking,
            'rating' => $this->getRatingLabel($total_score),
            'recommendation' => $this->generateScoreRecommendation($total_score, $scores),
            'calculated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Calculate demand certainty score
     * Based on forecast confidence and variance
     */
    private function calculateDemandCertainty($confidence_level, $variance) {
        // Normalize confidence (0-1) to 0-100
        $confidence_score = ($confidence_level * 100);
        
        // Penalize high variance (variance increases uncertainty)
        $variance_penalty = (($variance / 100) * 20); // Up to 20 point penalty
        
        $score = $confidence_score - $variance_penalty;
        return max(0, min(100, $score));
    }
    
    /**
     * Calculate cost efficiency score
     * ROI and cost-benefit analysis
     */
    private function calculateCostEfficiency($cost, $benefit, $roi) {
        if ($cost <= 0) {
            return 50; // Neutral if no cost data
        }
        
        // ROI-based scoring (higher ROI = higher score)
        $roi_score = min(100, ($roi / 2)); // 200% ROI = 100 score
        
        // Cost-benefit ratio
        $if_benefit_exceeds_cost = ($benefit > $cost) ? 100 : (($benefit / $cost) * 100);
        
        // Average the two metrics
        return ($roi_score + $if_benefit_exceeds_cost) / 2;
    }
    
    /**
     * Calculate implementation speed score
     * Faster implementation = higher score
     */
    private function calculateImplementationSpeed($timeline_days) {
        if ($timeline_days <= 1) return 100;      // Immediate
        if ($timeline_days <= 3) return 90;       // Very Fast
        if ($timeline_days <= 7) return 80;       // Fast
        if ($timeline_days <= 14) return 70;      // Moderate
        if ($timeline_days <= 30) return 50;      // Slow
        return 30;                                 // Very Slow
    }
    
    /**
     * Calculate risk level score
     * Lower risk = higher score (note: this criterion is inverted)
     */
    private function calculateRiskLevel($risk_exposure, $volatility, $dependency) {
        // Average of risk factors (0-100, where 100 = highest risk)
        $average_risk = ($risk_exposure + $volatility + $dependency) / 3;
        
        // Invert: lower risk becomes higher score
        return 100 - $average_risk;
    }
    
    /**
     * Calculate strategic fit score
     * How well does this decision align with business goals
     */
    private function calculateStrategicFit($category, $business_priorities = []) {
        $category_priority_map = [
            'inventory' => 0.25,
            'staffing' => 0.25,
            'production' => 0.20,
            'pricing' => 0.15,
            'marketing' => 0.10,
            'logistics' => 0.05
        ];
        
        $base_score = ($category_priority_map[$category] ?? 0.10) * 100;
        
        // Boost if matches business priorities
        $priority_boost = 0;
        if (!empty($business_priorities) && in_array($category, $business_priorities)) {
            $priority_boost = 15;
        }
        
        return min(100, $base_score + $priority_boost);
    }
    
    /**
     * Calculate weighted total score
     */
    private function calculateWeightedTotal($scores) {
        $total = 0;
        foreach ($scores as $criterion => $score) {
            $weight = $this->weights[$criterion] ?? 0;
            $total += ($score * $weight);
        }
        return $total;
    }
    
    /**
     * Get ranking percentile based on total score
     */
    private function getDecisionRanking($total_score) {
        // Get all recent decision scores for comparison
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total_decisions, 
                   SUM(CASE WHEN total_score > ? THEN 1 ELSE 0 END) as better_decisions
            FROM decision_scores 
            WHERE DATEDIFF(NOW(), created_at) <= 30
        ");
        $stmt->bind_param("d", $total_score);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result['total_decisions'] == 0) {
            return ['percentile' => 50, 'out_of' => 1];
        }
        
        $percentile = round((($result['total_decisions'] - $result['better_decisions']) / 
                           $result['total_decisions']) * 100);
        
        return [
            'percentile' => $percentile,
            'out_of' => $result['total_decisions'],
            'better_than_count' => $result['better_decisions']
        ];
    }
    
    /**
     * Get human-readable rating for score
     */
    private function getRatingLabel($score) {
        if ($score >= 90) return ['label' => 'Excellent', 'stars' => 5, 'color' => 'success'];
        if ($score >= 75) return ['label' => 'Very Good', 'stars' => 4, 'color' => 'success'];
        if ($score >= 60) return ['label' => 'Good', 'stars' => 3, 'color' => 'info'];
        if ($score >= 45) return ['label' => 'Fair', 'stars' => 2, 'color' => 'warning'];
        return ['label' => 'Poor', 'stars' => 1, 'color' => 'danger'];
    }
    
    /**
     * Generate recommendation text based on score
     */
    private function generateScoreRecommendation($total_score, $scores) {
        $recommendation = [];
        
        if ($total_score >= 85) {
            $recommendation[] = "✓ Highly recommended decision";
            $recommendation[] = "✓ Strong business case supports this option";
            $recommendation[] = "→ Action: Implement immediately";
        } elseif ($total_score >= 70) {
            $recommendation[] = "✓ Recommended decision";
            $recommendation[] = "• Monitor key risk factors";
            $recommendation[] = "→ Action: Schedule for implementation";
        } elseif ($total_score >= 55) {
            $recommendation[] = "⚠ Consider this decision carefully";
            $recommendation[] = "• Address identified risks before proceeding";
            $recommendation[] = "→ Action: Develop mitigation strategies";
        } else {
            $recommendation[] = "✗ Not recommended at this time";
            $recommendation[] = "• Significant risks or concerns identified";
            $recommendation[] = "→ Action: Review alternatives or defer decision";
        }
        
        // Identify weakest criterion
        $weakest = array_keys($scores, min($scores))[0];
        $recommendation[] = "• Weakest area: " . ucfirst(str_replace('_', ' ', $weakest));
        
        return $recommendation;
    }
    
    /**
     * Compare multiple decision options
     * Returns them ranked by score
     */
    public function compareOptions($options) {
        $scored_options = [];
        
        foreach ($options as $option) {
            $score_result = $this->scoreDecision($option);
            $option['scoring'] = $score_result;
            $scored_options[] = $option;
        }
        
        // Sort by total score (highest first)
        usort($scored_options, function($a, $b) {
            return $b['scoring']['total_score'] <=> $a['scoring']['total_score'];
        });
        
        // Assign ranking
        foreach ($scored_options as $key => $option) {
            $scored_options[$key]['rank'] = $key + 1;
            $scored_options[$key]['is_best'] = ($key === 0);
        }
        
        return [
            'options' => $scored_options,
            'best_option' => $scored_options[0] ?? null,
            'alternatives' => array_slice($scored_options, 1),
            'comparison_date' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Save score to database for historical tracking
     */
    public function saveScore($recommendation_id, $scoring_result) {
        $stmt = $this->conn->prepare("
            INSERT INTO decision_scores 
            (recommendation_id, demand_certainty, cost_efficiency, implementation_speed, 
             risk_level, strategic_fit, total_score, ranking)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $scores = $scoring_result['scores'];
        $ranking = $scoring_result['ranking']['percentile'] ?? 50;
        
        $stmt->bind_param("iddddddi",
            $recommendation_id,
            $scores['demand_certainty'],
            $scores['cost_efficiency'],
            $scores['implementation_speed'],
            $scores['risk_level'],
            $scores['strategic_fit'],
            $scoring_result['total_score'],
            $ranking
        );
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    /**
     * Get historical scoring data for a decision
     */
    public function getScoreHistory($recommendation_id, $limit = 10) {
        $stmt = $this->conn->prepare("
            SELECT * FROM decision_scores 
            WHERE recommendation_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("ii", $recommendation_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[] = $row;
        }
        $stmt->close();
        
        return $scores;
    }
}

/**
 * USAGE EXAMPLE:
 * 
 * include '../includes/config.php';
 * include 'includes/DecisionScoringService.php';
 * 
 * $scoring_service = new DecisionScoringService($conn);
 * 
 * // Score a single decision
 * $decision_data = [
 *     'confidence_level' => 0.85,
 *     'forecast_variance' => 15,
 *     'expected_cost' => 5000,
 *     'expected_benefit' => 15000,
 *     'roi_estimate' => 2.0,
 *     'implementation_timeline' => 3,
 *     'risk_exposure' => 40,
 *     'market_volatility' => 30,
 *     'dependency_risk' => 20,
 *     'category' => 'inventory',
 *     'business_priorities' => ['inventory', 'production']
 * ];
 * 
 * $score_result = $scoring_service->scoreDecision($decision_data);
 * print_r($score_result);
 * 
 * // Compare multiple options
 * $options = [$option1, $option2, $option3];
 * $comparison = $scoring_service->compareOptions($options);
 * print_r($comparison);
 */
?>
