<?php
/**
 * Unified analytics helper for DSS pages.
 * Aggregates orders, pre-orders, forecasts, inventory, expenses, and events
 * into decision-ready insights.
 */
class DSSInsightsService {
    private $conn;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }

    private function safeDate($date) {
        return $this->conn->real_escape_string($date);
    }

    private function number($value, $default = 0.0) {
        if ($value === null || $value === '') {
            return (float)$default;
        }
        return (float)$value;
    }

    public function getOverviewMetrics($days = 30) {
        $days = max(1, min(365, (int)$days));
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $endDate = date('Y-m-d');
        $startEsc = $this->safeDate($startDate);
        $endEsc = $this->safeDate($endDate);

        $orders = [
            'total_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
            'order_revenue' => 0.0,
            'avg_order_value' => 0.0
        ];
        $orderQuery = "
            SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END), 0) AS order_revenue,
                COALESCE(AVG(CASE WHEN status != 'cancelled' THEN total_amount END), 0) AS avg_order_value
            FROM orders
            WHERE DATE(created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND is_archived = 0
        ";
        if ($result = $this->conn->query($orderQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $orders['total_orders'] = (int)$row['total_orders'];
                $orders['completed_orders'] = (int)$row['completed_orders'];
                $orders['cancelled_orders'] = (int)$row['cancelled_orders'];
                $orders['order_revenue'] = $this->number($row['order_revenue']);
                $orders['avg_order_value'] = $this->number($row['avg_order_value']);
            }
            $result->free();
        }

        $preOrders = [
            'total_preorders' => 0,
            'active_preorders' => 0,
            'preorder_revenue' => 0.0
        ];
        $preOrderQuery = "
            SELECT
                COUNT(*) AS total_preorders,
                SUM(CASE WHEN reservation_status != 'cancelled' THEN 1 ELSE 0 END) AS active_preorders,
                COALESCE(SUM(CASE WHEN reservation_status != 'cancelled' THEN total_price ELSE 0 END), 0) AS preorder_revenue
            FROM pre_orders
            WHERE DATE(created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
        ";
        if ($result = $this->conn->query($preOrderQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $preOrders['total_preorders'] = (int)$row['total_preorders'];
                $preOrders['active_preorders'] = (int)$row['active_preorders'];
                $preOrders['preorder_revenue'] = $this->number($row['preorder_revenue']);
            }
            $result->free();
        }

        $expenses = 0.0;
        $expenseQuery = "
            SELECT COALESCE(SUM(amount), 0) AS total_expenses
            FROM expenses
            WHERE DATE(expense_date) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND status = 'approved'
        ";
        if ($result = $this->conn->query($expenseQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $expenses = $this->number($row['total_expenses']);
            }
            $result->free();
        }

        $forecastConfidence = 0.0;
        $forecastFreshnessHours = null;
        $forecastQuery = "
            SELECT
                COALESCE(AVG(confidence_level), 0) AS avg_confidence,
                MAX(updated_at) AS last_forecast_update
            FROM forecasts
            WHERE forecast_start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ";
        if ($result = $this->conn->query($forecastQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $forecastConfidence = $this->number($row['avg_confidence']) * 100;
                if (!empty($row['last_forecast_update'])) {
                    $hours = (time() - strtotime($row['last_forecast_update'])) / 3600;
                    $forecastFreshnessHours = round($hours, 1);
                }
            }
            $result->free();
        }

        $orderItemCount = 0;
        $itemQuery = "
            SELECT COUNT(*) AS total_items
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND o.is_archived = 0
        ";
        if ($result = $this->conn->query($itemQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $orderItemCount = (int)$row['total_items'];
            }
            $result->free();
        }

        $forecastRows = 0;
        $forecastRowsQuery = "
            SELECT COUNT(*) AS total_rows
            FROM forecasts
            WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ";
        if ($result = $this->conn->query($forecastRowsQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $forecastRows = (int)$row['total_rows'];
            }
            $result->free();
        }

        $operationalOrders = max(1, ($orders['total_orders'] - $orders['cancelled_orders']));
        $completionRate = ($orders['completed_orders'] / $operationalOrders) * 100;

        $totalRevenue = $orders['order_revenue'] + $preOrders['preorder_revenue'];
        $netIncome = $totalRevenue - $expenses;
        $netMargin = $totalRevenue > 0 ? ($netIncome / $totalRevenue) * 100 : 0;

        return [
            'days' => $days,
            'date_from' => $startDate,
            'date_to' => $endDate,
            'total_orders' => $orders['total_orders'],
            'completed_orders' => $orders['completed_orders'],
            'cancelled_orders' => $orders['cancelled_orders'],
            'total_preorders' => $preOrders['total_preorders'],
            'active_preorders' => $preOrders['active_preorders'],
            'order_revenue' => round($orders['order_revenue'], 2),
            'preorder_revenue' => round($preOrders['preorder_revenue'], 2),
            'total_revenue' => round($totalRevenue, 2),
            'approved_expenses' => round($expenses, 2),
            'net_income' => round($netIncome, 2),
            'net_margin' => round($netMargin, 2),
            'completion_rate' => round($completionRate, 2),
            'avg_order_value' => round($orders['avg_order_value'], 2),
            'forecast_confidence_avg' => round($forecastConfidence, 2),
            'forecast_freshness_hours' => $forecastFreshnessHours,
            'records_analyzed' => $orders['total_orders'] + $preOrders['total_preorders'] + $orderItemCount + $forecastRows
        ];
    }

    public function getRevenueTrend($days = 14) {
        $days = max(3, min(120, (int)$days));
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $endDate = date('Y-m-d');
        $startEsc = $this->safeDate($startDate);
        $endEsc = $this->safeDate($endDate);

        $orderDaily = [];
        $orderDailyQuery = "
            SELECT
                DATE(created_at) AS metric_day,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_amount), 0) AS revenue
            FROM orders
            WHERE DATE(created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND is_archived = 0
              AND status != 'cancelled'
            GROUP BY DATE(created_at)
        ";
        if ($result = $this->conn->query($orderDailyQuery)) {
            while ($row = $result->fetch_assoc()) {
                $orderDaily[$row['metric_day']] = [
                    'order_count' => (int)$row['order_count'],
                    'revenue' => $this->number($row['revenue'])
                ];
            }
            $result->free();
        }

        $preOrderDaily = [];
        $preOrderQuery = "
            SELECT
                DATE(created_at) AS metric_day,
                COUNT(*) AS preorder_count,
                COALESCE(SUM(total_price), 0) AS revenue
            FROM pre_orders
            WHERE DATE(created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND reservation_status != 'cancelled'
            GROUP BY DATE(created_at)
        ";
        if ($result = $this->conn->query($preOrderQuery)) {
            while ($row = $result->fetch_assoc()) {
                $preOrderDaily[$row['metric_day']] = [
                    'preorder_count' => (int)$row['preorder_count'],
                    'revenue' => $this->number($row['revenue'])
                ];
            }
            $result->free();
        }

        $forecastDaily = [];
        $forecastQuery = "
            SELECT
                forecast_start_date AS metric_day,
                COALESCE(SUM(predicted_value), 0) AS predicted_revenue
            FROM forecasts
            WHERE forecast_type = 'revenue'
              AND forecast_start_date BETWEEN '{$startEsc}' AND '{$endEsc}'
            GROUP BY forecast_start_date
        ";
        if ($result = $this->conn->query($forecastQuery)) {
            while ($row = $result->fetch_assoc()) {
                $forecastDaily[$row['metric_day']] = $this->number($row['predicted_revenue']);
            }
            $result->free();
        }

        $labels = [];
        $dates = [];
        $actualRevenue = [];
        $forecastRevenue = [];
        $actualOrders = [];
        $preorderCounts = [];

        $mapeAccumulator = 0.0;
        $mapeCount = 0;

        $cursor = strtotime($startDate);
        $end = strtotime($endDate);
        while ($cursor <= $end) {
            $date = date('Y-m-d', $cursor);
            $dates[] = $date;
            $labels[] = date('M d', $cursor);

            $orderRevenue = isset($orderDaily[$date]) ? $orderDaily[$date]['revenue'] : 0.0;
            $orderCount = isset($orderDaily[$date]) ? $orderDaily[$date]['order_count'] : 0;
            $preRevenue = isset($preOrderDaily[$date]) ? $preOrderDaily[$date]['revenue'] : 0.0;
            $preCount = isset($preOrderDaily[$date]) ? $preOrderDaily[$date]['preorder_count'] : 0;
            $forecast = isset($forecastDaily[$date]) ? $forecastDaily[$date] : 0.0;

            $actual = $orderRevenue + $preRevenue;
            $actualRevenue[] = round($actual, 2);
            $forecastRevenue[] = round($forecast, 2);
            $actualOrders[] = $orderCount + $preCount;
            $preorderCounts[] = $preCount;

            if ($forecast > 0) {
                $mapeAccumulator += abs(($actual - $forecast) / $forecast);
                $mapeCount++;
            }

            $cursor = strtotime('+1 day', $cursor);
        }

        $mape = $mapeCount > 0 ? ($mapeAccumulator / $mapeCount) * 100 : 0;
        $accuracy = max(0, 100 - $mape);

        return [
            'days' => $days,
            'date_from' => $startDate,
            'date_to' => $endDate,
            'dates' => $dates,
            'labels' => $labels,
            'actual_revenue' => $actualRevenue,
            'forecast_revenue' => $forecastRevenue,
            'actual_orders' => $actualOrders,
            'preorder_counts' => $preorderCounts,
            'total_actual_revenue' => round(array_sum($actualRevenue), 2),
            'total_forecast_revenue' => round(array_sum($forecastRevenue), 2),
            'mape' => round($mape, 2),
            'forecast_accuracy' => round($accuracy, 2)
        ];
    }

    public function getTopProducts($days = 30, $limit = 10) {
        $days = max(1, min(365, (int)$days));
        $limit = max(3, min(30, (int)$limit));
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $endDate = date('Y-m-d');
        $startEsc = $this->safeDate($startDate);
        $endEsc = $this->safeDate($endDate);

        $combined = [];

        $orderProductQuery = "
            SELECT
                COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Unknown Product') AS product_name,
                COALESCE(SUM(oi.quantity), 0) AS total_quantity,
                COALESCE(SUM(oi.total), 0) AS total_revenue
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND o.is_archived = 0
              AND o.status != 'cancelled'
            GROUP BY oi.product_name
        ";
        if ($result = $this->conn->query($orderProductQuery)) {
            while ($row = $result->fetch_assoc()) {
                $name = $row['product_name'];
                $key = strtolower($name);
                if (!isset($combined[$key])) {
                    $combined[$key] = [
                        'product_name' => $name,
                        'quantity' => 0,
                        'revenue' => 0.0
                    ];
                }
                $combined[$key]['quantity'] += (int)$row['total_quantity'];
                $combined[$key]['revenue'] += $this->number($row['total_revenue']);
            }
            $result->free();
        }

        $preOrderProductQuery = "
            SELECT
                COALESCE(NULLIF(TRIM(product_name), ''), 'Unknown Product') AS product_name,
                COALESCE(SUM(quantity), 0) AS total_quantity,
                COALESCE(SUM(total_price), 0) AS total_revenue
            FROM pre_orders
            WHERE DATE(created_at) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND reservation_status != 'cancelled'
            GROUP BY product_name
        ";
        if ($result = $this->conn->query($preOrderProductQuery)) {
            while ($row = $result->fetch_assoc()) {
                $name = $row['product_name'];
                $key = strtolower($name);
                if (!isset($combined[$key])) {
                    $combined[$key] = [
                        'product_name' => $name,
                        'quantity' => 0,
                        'revenue' => 0.0
                    ];
                }
                $combined[$key]['quantity'] += (int)$row['total_quantity'];
                $combined[$key]['revenue'] += $this->number($row['total_revenue']);
            }
            $result->free();
        }

        $products = array_values($combined);
        usort($products, function ($a, $b) {
            if ($a['quantity'] === $b['quantity']) {
                return $b['revenue'] <=> $a['revenue'];
            }
            return $b['quantity'] <=> $a['quantity'];
        });

        $products = array_slice($products, 0, $limit);
        foreach ($products as &$row) {
            $row['revenue'] = round($row['revenue'], 2);
            $row['avg_unit_revenue'] = $row['quantity'] > 0 ? round($row['revenue'] / $row['quantity'], 2) : 0.0;
        }
        unset($row);

        return $products;
    }

    public function getInventoryPressure($daysAhead = 7, $limit = 8) {
        $daysAhead = max(1, min(60, (int)$daysAhead));
        $limit = max(3, min(20, (int)$limit));
        $futureStart = date('Y-m-d');
        $futureEnd = date('Y-m-d', strtotime('+' . ($daysAhead - 1) . ' days'));
        $futureStartEsc = $this->safeDate($futureStart);
        $futureEndEsc = $this->safeDate($futureEnd);

        $items = [];
        $query = "
            SELECT
                p.id,
                p.name,
                p.stock,
                COALESCE(SUM(pdf.predicted_quantity), 0) AS forecast_demand
            FROM products p
            LEFT JOIN product_demand_forecasts pdf
                ON pdf.product_id = p.id
               AND pdf.forecast_date BETWEEN '{$futureStartEsc}' AND '{$futureEndEsc}'
            WHERE p.is_active = 1
              AND p.is_archived = 0
            GROUP BY p.id, p.name, p.stock
        ";
        if ($result = $this->conn->query($query)) {
            while ($row = $result->fetch_assoc()) {
                $stock = (int)$row['stock'];
                $demand = $this->number($row['forecast_demand']);
                $dailyDemand = $demand > 0 ? ($demand / $daysAhead) : 0.0;
                $coverageDays = $dailyDemand > 0 ? ($stock / $dailyDemand) : null;

                $severity = 'low';
                if ($stock <= 0 && $demand > 0) {
                    $severity = 'critical';
                } elseif ($coverageDays !== null && $coverageDays < 2) {
                    $severity = 'critical';
                } elseif ($coverageDays !== null && $coverageDays < 4) {
                    $severity = 'high';
                } elseif ($coverageDays !== null && $coverageDays < 7) {
                    $severity = 'medium';
                }

                $items[] = [
                    'product_id' => (int)$row['id'],
                    'product_name' => $row['name'],
                    'stock' => $stock,
                    'forecast_demand' => round($demand, 2),
                    'daily_demand' => round($dailyDemand, 2),
                    'coverage_days' => $coverageDays !== null ? round($coverageDays, 1) : null,
                    'gap' => round($stock - $demand, 2),
                    'severity' => $severity
                ];
            }
            $result->free();
        }

        $severityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($items, function ($a, $b) use ($severityRank) {
            $rankA = $severityRank[$a['severity']] ?? 0;
            $rankB = $severityRank[$b['severity']] ?? 0;
            if ($rankA === $rankB) {
                return $a['gap'] <=> $b['gap'];
            }
            return $rankB <=> $rankA;
        });

        return array_slice($items, 0, $limit);
    }

    public function getEventImpactInsights($daysAhead = 90) {
        $daysAhead = max(1, min(365, (int)$daysAhead));
        $futureStart = date('Y-m-d');
        $futureEnd = date('Y-m-d', strtotime('+' . $daysAhead . ' days'));
        $futureStartEsc = $this->safeDate($futureStart);
        $futureEndEsc = $this->safeDate($futureEnd);

        $baselineOrders = 0.0;
        $baselineRevenue = 0.0;

        $baselineOrderQuery = "
            SELECT COALESCE(AVG(daily_orders), 0) AS baseline_orders
            FROM (
                SELECT DATE(created_at) AS order_day, COUNT(*) AS daily_orders
                FROM orders
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND is_archived = 0
                  AND status != 'cancelled'
                GROUP BY DATE(created_at)
            ) daily
        ";
        if ($result = $this->conn->query($baselineOrderQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $baselineOrders = $this->number($row['baseline_orders']);
            }
            $result->free();
        }

        $baselineRevenueQuery = "
            SELECT COALESCE(AVG(daily_revenue), 0) AS baseline_revenue
            FROM (
                SELECT DATE(created_at) AS order_day, SUM(total_amount) AS daily_revenue
                FROM orders
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND is_archived = 0
                  AND status != 'cancelled'
                GROUP BY DATE(created_at)
            ) daily
        ";
        if ($result = $this->conn->query($baselineRevenueQuery)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $baselineRevenue = $this->number($row['baseline_revenue']);
            }
            $result->free();
        }

        $events = [];
        $eventQuery = "
            SELECT event_id, event_name, event_date, event_type, impact_multiplier, is_active
            FROM business_events
            WHERE event_date BETWEEN '{$futureStartEsc}' AND '{$futureEndEsc}'
              AND is_active = 1
            ORDER BY event_date ASC
        ";
        if ($result = $this->conn->query($eventQuery)) {
            while ($row = $result->fetch_assoc()) {
                $impact = $this->number($row['impact_multiplier'], 1.0);
                $daysUntil = (int)floor((strtotime($row['event_date']) - strtotime(date('Y-m-d'))) / 86400);
                $expectedOrders = $baselineOrders * $impact;
                $expectedRevenue = $baselineRevenue * $impact;
                $liftPercent = ($impact - 1.0) * 100;

                if ($impact >= 1.5) {
                    $prep = 'Increase raw material procurement and staffing 2 days before this event.';
                } elseif ($impact >= 1.2) {
                    $prep = 'Prepare moderate stock buffers and schedule additional prep labor.';
                } elseif ($impact <= 0.7) {
                    $prep = 'Reduce batch sizes and prioritize cost-control operations.';
                } else {
                    $prep = 'Maintain normal operations and monitor same-day demand closely.';
                }

                $events[] = [
                    'event_id' => (int)$row['event_id'],
                    'event_name' => $row['event_name'],
                    'event_date' => $row['event_date'],
                    'event_type' => $row['event_type'],
                    'impact_multiplier' => round($impact, 2),
                    'days_until' => $daysUntil,
                    'lift_percent' => round($liftPercent, 1),
                    'expected_orders' => round($expectedOrders, 1),
                    'expected_revenue' => round($expectedRevenue, 2),
                    'recommended_preparation' => $prep
                ];
            }
            $result->free();
        }

        $highImpact = count(array_filter($events, function ($item) {
            return $item['impact_multiplier'] >= 1.4;
        }));

        return [
            'baseline_daily_orders' => round($baselineOrders, 2),
            'baseline_daily_revenue' => round($baselineRevenue, 2),
            'upcoming_events' => $events,
            'high_impact_events' => $highImpact
        ];
    }

    public function getForecastingSummary($daysAhead = 7) {
        $daysAhead = max(1, min(60, (int)$daysAhead));
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+' . ($daysAhead - 1) . ' days'));
        $startEsc = $this->safeDate($startDate);
        $endEsc = $this->safeDate($endDate);

        $summary = [
            'days_ahead' => $daysAhead,
            'predicted_revenue' => 0.0,
            'predicted_orders' => 0.0,
            'avg_confidence' => 0.0
        ];

        $query = "
            SELECT
                COALESCE(SUM(CASE WHEN forecast_type = 'revenue' THEN predicted_value ELSE 0 END), 0) AS predicted_revenue,
                COALESCE(SUM(CASE WHEN forecast_type = 'daily_orders' THEN predicted_value ELSE 0 END), 0) AS predicted_orders,
                COALESCE(AVG(confidence_level), 0) AS avg_confidence
            FROM forecasts
            WHERE forecast_start_date BETWEEN '{$startEsc}' AND '{$endEsc}'
        ";
        if ($result = $this->conn->query($query)) {
            $row = $result->fetch_assoc();
            if ($row) {
                $summary['predicted_revenue'] = round($this->number($row['predicted_revenue']), 2);
                $summary['predicted_orders'] = round($this->number($row['predicted_orders']), 2);
                $summary['avg_confidence'] = round($this->number($row['avg_confidence']) * 100, 2);
            }
            $result->free();
        }

        return $summary;
    }

    public function generateDecisionBrief($days = 30) {
        $overview = $this->getOverviewMetrics($days);
        $trend = $this->getRevenueTrend(min(14, max(7, (int)($days / 2))));
        $inventory = $this->getInventoryPressure(7, 5);
        $events = $this->getEventImpactInsights(45);
        $forecast = $this->getForecastingSummary(7);

        $brief = [];

        if ($overview['net_margin'] < 15) {
            $brief[] = [
                'category' => 'pricing',
                'priority' => 'high',
                'headline' => 'Protect margin with targeted price and cost actions',
                'rationale' => 'Net margin is below 15% in the selected period.',
                'action' => 'Review low-margin SKUs and adjust pricing or bundle strategy this week.',
                'expected_outcome' => 'Improve margin and stabilize cash flow.'
            ];
        }

        if ($overview['completion_rate'] < 85) {
            $brief[] = [
                'category' => 'logistics',
                'priority' => 'high',
                'headline' => 'Improve order completion reliability',
                'rationale' => 'Completion rate is below operational target (85%).',
                'action' => 'Audit cancelled/delayed orders and tighten dispatch + prep handoff SLAs.',
                'expected_outcome' => 'Higher fulfillment rate and stronger customer retention.'
            ];
        }

        if ($trend['forecast_accuracy'] > 0 && $trend['forecast_accuracy'] < 80) {
            $brief[] = [
                'category' => 'production',
                'priority' => 'medium',
                'headline' => 'Recalibrate forecast model with latest demand shifts',
                'rationale' => 'Forecast accuracy is below 80% over the measured trend window.',
                'action' => 'Regenerate forecasts and validate event multipliers before next planning cycle.',
                'expected_outcome' => 'Better planning accuracy and reduced over/under-production.'
            ];
        }

        $criticalInventory = array_filter($inventory, function ($item) {
            return in_array($item['severity'], ['critical', 'high'], true);
        });
        if (count($criticalInventory) > 0) {
            $brief[] = [
                'category' => 'inventory',
                'priority' => 'critical',
                'headline' => 'Prioritize procurement for high-risk items',
                'rationale' => count($criticalInventory) . ' item(s) are projected to run short within 7 days.',
                'action' => 'Create immediate purchase orders for critical SKUs and adjust production mix.',
                'expected_outcome' => 'Reduced stockout risk and sustained sales continuity.'
            ];
        }

        if ($events['high_impact_events'] > 0) {
            $brief[] = [
                'category' => 'marketing',
                'priority' => 'medium',
                'headline' => 'Capitalize on upcoming high-impact events',
                'rationale' => $events['high_impact_events'] . ' high-impact event(s) are scheduled soon.',
                'action' => 'Launch event-based campaigns and pre-build inventory buffers for peak dates.',
                'expected_outcome' => 'Stronger event-driven revenue and better demand capture.'
            ];
        }

        if ($forecast['avg_confidence'] < 70) {
            $brief[] = [
                'category' => 'staffing',
                'priority' => 'medium',
                'headline' => 'Use flexible staffing due to low forecast confidence',
                'rationale' => 'Short-term forecast confidence is below 70%.',
                'action' => 'Use staggered shifts and monitor intraday demand for staffing adjustments.',
                'expected_outcome' => 'Higher productivity with lower overstaffing risk.'
            ];
        }

        if (empty($brief)) {
            $brief[] = [
                'category' => 'operations',
                'priority' => 'low',
                'headline' => 'Maintain current strategy and monitor variance',
                'rationale' => 'Core metrics are within acceptable decision thresholds.',
                'action' => 'Continue weekly DSS review cadence and keep anomaly monitoring active.',
                'expected_outcome' => 'Sustained performance with minimal operational risk.'
            ];
        }

        return array_slice($brief, 0, 6);
    }
}
