<?php
/**
 * ============================================================================
 * DECISION COMPARISON SERVICE
 * ============================================================================
 * Compares different decision options side-by-side
 * Creates comparison matrices and helps users select best option
 * 
 * Features:
 * - Multi-option comparison
 * - Visual comparison matrix
 * - Weighted scoring across options
 * - Pros/cons analysis
 * - What-if scenario analysis
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class DecisionComparisonService {
    private $conn;
    private $scoring_service;
    
    public function __construct($database_connection, $scoring_service = null) {
        $this->conn = $database_connection;
        $this->scoring_service = $scoring_service;
    }
    
    /**
     * Compare multiple decision options
     * Returns scored and ranked options
     */
    public function compareOptions($options, $comparison_name = 'Decision Comparison') {
        $comparison_id = $this->generateComparisonId();
        
        $scored_options = [];
        foreach ($options as $idx => $option) {
            $option['index'] = $idx + 1;
            
            // Score if scoring service available
            if ($this->scoring_service) {
                $scoring = $this->scoring_service->scoreDecision($option);
                $option['score'] = $scoring['total_score'];
                $option['rating'] = $scoring['rating'];
                $option['detailed_scores'] = $scoring['scores'];
            }
            
            $scored_options[] = $option;
        }
        
        // Sort by score descending
        usort($scored_options, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        
        // Create comparison matrix
        $comparison_matrix = $this->buildComparisonMatrix($scored_options);
        
        // Save comparison
        $comparison_record = [
            'comparison_id' => $comparison_id,
            'name' => $comparison_name,
            'option_count' => count($scored_options),
            'best_option' => $scored_options[0],
            'alternatives' => array_slice($scored_options, 1),
            'matrix' => $comparison_matrix,
            'analysis' => $this->generateComparativeAnalysis($scored_options),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $comparison_record;
    }
    
    /**
     * Build detailed comparison matrix for UI display
     */
    private function buildComparisonMatrix($options) {
        $matrix = [];
        
        // Define comparison criteria
        $criteria = [
            'Cost' => 'expected_cost',
            'Timeline' => 'implementation_timeline',
            'ROI' => 'roi_estimate',
            'Risk' => 'risk_exposure',
            'Confidence' => 'confidence_level'
        ];
        
        $matrix['headers'] = array_merge(['Criteria'], 
                                        array_map(function($opt) { 
                                            return "Option " . $opt['index']; 
                                        }, $options));
        
        $matrix['rows'] = [];
        
        foreach ($criteria as $criteria_name => $criteria_key) {
            $row = ['criterion' => $criteria_name];
            
            foreach ($options as $option) {
                $value = $option[$criteria_key] ?? 'N/A';
                
                // Format value based on criteria
                if ($criteria_name === 'Cost') {
                    $value = '$' . number_format($value, 2);
                } elseif ($criteria_name === 'Timeline') {
                    $value = $value . ' days';
                } elseif ($criteria_name === 'ROI') {
                    $value = round($value * 100, 1) . '%';
                } elseif ($criteria_name === 'Risk') {
                    $value = $value . ' (0-100)';
                } elseif ($criteria_name === 'Confidence') {
                    $value = round($value * 100, 1) . '%';
                }
                
                $row['values'][] = $value;
            }
            
            $matrix['rows'][] = $row;
        }
        
        // Add score row
        $score_row = ['criterion' => 'Overall Score'];
        foreach ($options as $option) {
            $score = $option['score'] ?? 0;
            $score_row['values'][] = round($score, 1) . '/100';
        }
        $matrix['rows'][] = $score_row;
        
        return $matrix;
    }
    
    /**
     * Generate comparative analysis text
     */
    private function generateComparativeAnalysis($options) {
        $analysis = [];
        
        if (empty($options)) {
            return $analysis;
        }
        
        $best = $options[0];
        
        // Best overall
        $analysis[] = [
            'type' => 'BEST_OVERALL',
            'title' => 'Best Overall Choice',
            'description' => "Option " . $best['index'] . " has the highest score (" . 
                            round($best['score'], 1) . "/100) and is " . 
                            strtolower($best['rating']['label']) . ".",
            'recommendation' => 'Recommend implementing this option'
        ];
        
        // Cost analysis
        $min_cost = min(array_column($options, 'expected_cost'));
        $most_cost_effective = array_filter($options, 
            function($o) use ($min_cost) { return $o['expected_cost'] == $min_cost; })[0] ?? null;
        
        if ($most_cost_effective) {
            $analysis[] = [
                'type' => 'COST_EFFICIENCY',
                'title' => 'Most Cost-Effective',
                'description' => "Option " . $most_cost_effective['index'] . 
                                " has the lowest cost ($" . number_format($most_cost_effective['expected_cost'], 2) . ").",
                'recommendation' => 'Consider if other factors are acceptable'
            ];
        }
        
        // Speed analysis
        $fastest = array_reduce($options, function($carry, $item) {
            return ($carry === null || $item['implementation_timeline'] < $carry['implementation_timeline']) 
                   ? $item : $carry;
        });
        
        if ($fastest) {
            $analysis[] = [
                'type' => 'SPEED',
                'title' => 'Fastest Implementation',
                'description' => "Option " . $fastest['index'] . " can be implemented in " . 
                                $fastest['implementation_timeline'] . " days.",
                'recommendation' => 'Consider if quick results needed'
            ];
        }
        
        // Risk analysis
        $lowest_risk = array_reduce($options, function($carry, $item) {
            return ($carry === null || $item['risk_exposure'] < $carry['risk_exposure']) 
                   ? $item : $carry;
        });
        
        if ($lowest_risk) {
            $analysis[] = [
                'type' => 'RISK',
                'title' => 'Lowest Risk',
                'description' => "Option " . $lowest_risk['index'] . " has risk exposure of " . 
                                $lowest_risk['risk_exposure'] . " (0-100 scale).",
                'recommendation' => 'Consider if risk minimization is priority'
            ];
        }
        
        // Highest ROI
        $highest_roi = array_reduce($options, function($carry, $item) {
            return ($carry === null || $item['roi_estimate'] > $carry['roi_estimate']) 
                   ? $item : $carry;
        });
        
        if ($highest_roi && $highest_roi['roi_estimate'] > 1) {
            $analysis[] = [
                'type' => 'ROI',
                'title' => 'Best ROI',
                'description' => "Option " . $highest_roi['index'] . " has estimated ROI of " . 
                                round($highest_roi['roi_estimate'] * 100, 0) . "%.",
                'recommendation' => 'Strong business case for financial benefit'
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Generate pros and cons for each option
     */
    public function generateProsAndCons($options) {
        $pros_cons = [];
        
        foreach ($options as $option) {
            $option_pros_cons = [
                'option_index' => $option['index'],
                'pros' => [],
                'cons' => [],
                'neutral' => []
            ];
            
            // Analyze cost
            if ($option['expected_cost'] < 5000) {
                $option_pros_cons['pros'][] = 'Low implementation cost';
            } else {
                $option_pros_cons['cons'][] = 'High implementation cost ($' . 
                                             number_format($option['expected_cost'], 0) . ')';
            }
            
            // Analyze timeline
            if ($option['implementation_timeline'] <= 3) {
                $option_pros_cons['pros'][] = 'Fast implementation (' . 
                                             $option['implementation_timeline'] . ' days)';
            } elseif ($option['implementation_timeline'] > 14) {
                $option_pros_cons['cons'][] = 'Long implementation timeline (' . 
                                             $option['implementation_timeline'] . ' days)';
            } else {
                $option_pros_cons['neutral'][] = 'Moderate timeline (' . 
                                                $option['implementation_timeline'] . ' days)';
            }
            
            // Analyze ROI
            if ($option['roi_estimate'] > 2.0) {
                $option_pros_cons['pros'][] = 'Excellent ROI (' . 
                                             round($option['roi_estimate'] * 100, 0) . '%)';
            } elseif ($option['roi_estimate'] > 1.0) {
                $option_pros_cons['pros'][] = 'Good ROI (' . 
                                             round($option['roi_estimate'] * 100, 0) . '%)';
            } else {
                $option_pros_cons['cons'][] = 'Low ROI - may not be financially viable';
            }
            
            // Analyze risk
            if ($option['risk_exposure'] < 30) {
                $option_pros_cons['pros'][] = 'Low risk exposure';
            } elseif ($option['risk_exposure'] > 60) {
                $option_pros_cons['cons'][] = 'High risk exposure (' . 
                                             $option['risk_exposure'] . ')';
            } else {
                $option_pros_cons['neutral'][] = 'Moderate risk level';
            }
            
            // Analyze confidence
            if ($option['confidence_level'] >= 0.85) {
                $option_pros_cons['pros'][] = 'High confidence in forecast';
            } elseif ($option['confidence_level'] < 0.65) {
                $option_pros_cons['cons'][] = 'Low forecast confidence';
            }
            
            $pros_cons[] = $option_pros_cons;
        }
        
        return $pros_cons;
    }
    
    /**
     * Perform what-if analysis
     * Show impact of changing one parameter
     */
    public function whatIfAnalysis($base_option, $parameter_name, $new_value) {
        $modified_option = $base_option;
        $modified_option[$parameter_name] = $new_value;
        
        $analysis = [
            'base_option' => $base_option,
            'modified_option' => $modified_option,
            'parameter_changed' => $parameter_name,
            'original_value' => $base_option[$parameter_name],
            'new_value' => $new_value,
            'impact' => []
        ];
        
        // Score both options
        if ($this->scoring_service) {
            $base_score = $this->scoring_service->scoreDecision($base_option);
            $modified_score = $this->scoring_service->scoreDecision($modified_option);
            
            $analysis['base_score'] = $base_score['total_score'];
            $analysis['modified_score'] = $modified_score['total_score'];
            $analysis['score_change'] = round($modified_score['total_score'] - $base_score['total_score'], 2);
            $analysis['score_change_direction'] = $analysis['score_change'] > 0 ? 'improved' : 'declined';
            
            // Detailed impact
            $analysis['detailed_impact'] = [];
            foreach ($base_score['scores'] as $criterion => $base_value) {
                $modified_value = $modified_score['scores'][$criterion] ?? $base_value;
                $analysis['detailed_impact'][$criterion] = [
                    'base' => round($base_value, 2),
                    'modified' => round($modified_value, 2),
                    'change' => round($modified_value - $base_value, 2)
                ];
            }
        }
        
        $analysis['recommendation'] = $this->generateWhatIfRecommendation($analysis);
        
        return $analysis;
    }
    
    /**
     * Generate recommendation for what-if scenario
     */
    private function generateWhatIfRecommendation($what_if) {
        if ($what_if['score_change'] > 5) {
            return "This change would significantly improve the decision (+" . 
                   $what_if['score_change'] . " points). Strong recommendation to adjust.";
        } elseif ($what_if['score_change'] > 0) {
            return "This change would slightly improve the decision (+" . 
                   $what_if['score_change'] . " points). Consider if feasible.";
        } elseif ($what_if['score_change'] > -5) {
            return "This change would slightly reduce the decision quality (" . 
                   $what_if['score_change'] . " points). Minor impact.";
        } else {
            return "This change would significantly reduce the decision quality (" . 
                   $what_if['score_change'] . " points). Avoid if possible.";
        }
    }
    
    /**
     * Save comparison to database
     */
    public function saveComparison($comparison_data, $user_id = null) {
        $comparison_json = json_encode($comparison_data);
        
        $stmt = $this->conn->prepare("
            INSERT INTO decision_comparisons 
            (comparison_name, options, comparison_matrix, best_option, user_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $best_option_index = 1;
        $matrix_json = json_encode($comparison_data['matrix']);
        
        $stmt->bind_param("sssii",
            $comparison_data['name'],
            $comparison_json,
            $matrix_json,
            $best_option_index,
            $user_id
        );
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    }
    
    /**
     * Get saved comparisons
     */
    public function getSavedComparisons($limit = 10) {
        $stmt = $this->conn->prepare("
            SELECT * FROM decision_comparisons
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $comparisons = [];
        while ($row = $result->fetch_assoc()) {
            $row['options'] = json_decode($row['options'], true);
            $row['comparison_matrix'] = json_decode($row['comparison_matrix'], true);
            $comparisons[] = $row;
        }
        $stmt->close();
        
        return $comparisons;
    }
    
    /**
     * Generate unique comparison ID
     */
    private function generateComparisonId() {
        return 'COMP-' . date('Ymd') . '-' . strtoupper(substr(md5(microtime()), 0, 6));
    }
}

/**
 * USAGE EXAMPLE:
 * 
 * include '../includes/config.php';
 * include 'includes/DecisionScoringService.php';
 * include 'includes/DecisionComparisonService.php';
 * 
 * $scoring_service = new DecisionScoringService($conn);
 * $comparison_service = new DecisionComparisonService($conn, $scoring_service);
 * 
 * // Define options to compare
 * $options = [
 *     [
 *         'category' => 'inventory',
 *         'expected_cost' => 5000,
 *         'expected_benefit' => 15000,
 *         'implementation_timeline' => 3,
 *         'risk_exposure' => 35,
 *         'confidence_level' => 0.85,
 *         'roi_estimate' => 2.0
 *     ],
 *     [
 *         'category' => 'inventory',
 *         'expected_cost' => 8000,
 *         'expected_benefit' => 20000,
 *         'implementation_timeline' => 5,
 *         'risk_exposure' => 45,
 *         'confidence_level' => 0.80,
 *         'roi_estimate' => 1.5
 *     ],
 *     [
 *         'category' => 'inventory',
 *         'expected_cost' => 3000,
 *         'expected_benefit' => 9000,
 *         'implementation_timeline' => 1,
 *         'risk_exposure' => 25,
 *         'confidence_level' => 0.90,
 *         'roi_estimate' => 2.0
 *     ]
 * ];
 * 
 * // Compare options
 * $comparison = $comparison_service->compareOptions($options, 'Inventory Decision');
 * 
 * // Generate pros and cons
 * $pros_cons = $comparison_service->generateProsAndCons($comparison['alternatives']);
 * 
 * // What-if analysis
 * $what_if = $comparison_service->whatIfAnalysis($options[0], 'expected_cost', 4000);
 */
?>
