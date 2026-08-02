<?php

class PartnerBusinessEconomicsService
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $safe = mysqli_real_escape_string($this->conn, $table);
        $result = mysqli_query($this->conn, "SHOW TABLES LIKE '{$safe}'");
        return $cache[$table] = (bool)($result && mysqli_num_rows($result) > 0);
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!$this->tableExists($table)) {
            return $cache[$key] = false;
        }
        $safeTable = mysqli_real_escape_string($this->conn, $table);
        $safeColumn = mysqli_real_escape_string($this->conn, $column);
        $result = mysqli_query($this->conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $cache[$key] = (bool)($result && mysqli_num_rows($result) > 0);
    }

    private function number($value): float
    {
        return ($value === null || $value === '') ? 0.0 : (float)$value;
    }

    private function labelize(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function monthBounds(string $month): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $start = $month . '-01';
        $next = date('Y-m-d', strtotime($start . ' +1 month'));
        $end = date('Y-m-t', strtotime($start));
        return [$month, $start, $end, $next];
    }

    private function orderScopeExistsSql(int $partnerUserId, string $orderAlias = 'o'): string
    {
        return "EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (
                    oi_scope.product_id = p_scope.product_id
                    OR oi_scope.product_id = CAST(p_scope.id AS CHAR)
                    OR CAST(oi_scope.product_id AS UNSIGNED) = p_scope.id
                )
            WHERE oi_scope.order_id = {$orderAlias}.id
              AND p_scope.seller_id = {$partnerUserId}
        )";
    }

    private function preOrderScopeSql(int $partnerUserId, string $preAlias = 'po'): string
    {
        return "EXISTS (
            SELECT 1
            FROM products p_scope
            WHERE p_scope.id = {$preAlias}.product_id
              AND p_scope.seller_id = {$partnerUserId}
        )";
    }

    private function purchaseOrderScopeSql(int $partnerUserId, string $poAlias = 'po'): string
    {
        return "({$poAlias}.created_by = {$partnerUserId} OR EXISTS (
            SELECT 1
            FROM purchase_order_items poi_scope
            INNER JOIN bill_of_materials bom_scope ON bom_scope.material_id = poi_scope.material_id
            INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
            WHERE poi_scope.purchase_order_id = {$poAlias}.id
              AND p_scope.seller_id = {$partnerUserId}
        ))";
    }

    private function materialScopeSql(int $partnerUserId, string $materialAlias = 'm'): string
    {
        return "EXISTS (
            SELECT 1
            FROM bill_of_materials bom_scope
            INNER JOIN products p_scope ON p_scope.id = bom_scope.product_id
            WHERE bom_scope.material_id = {$materialAlias}.id
              AND p_scope.seller_id = {$partnerUserId}
        )";
    }

    private function employeeUserIdsInScope(int $partnerUserId): array
    {
        if (!$this->tableExists('employees') || !$this->columnExists('employees', 'user_id')) {
            return [];
        }

        $query = "SELECT DISTINCT e.user_id
                  FROM employees e
                  WHERE e.user_id IS NOT NULL
                    AND e.user_id > 0";

        if ($this->columnExists('employees', 'seller_scope_owner_id')) {
            $query .= " AND e.seller_scope_owner_id = ?";
            $stmt = mysqli_prepare($this->conn, $query);
            if (!$stmt) {
                return [];
            }
            mysqli_stmt_bind_param($stmt, 'i', $partnerUserId);
        } else {
            $query .= " AND EXISTS (
                SELECT 1
                FROM users u
                WHERE u.id = e.user_id
                  AND u.email IS NOT NULL
            )";
            $stmt = mysqli_prepare($this->conn, $query);
            if (!$stmt) {
                return [];
            }
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $ids = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $ids[] = (int)($row['user_id'] ?? 0);
        }
        mysqli_stmt_close($stmt);
        return array_values(array_filter(array_unique($ids)));
    }

    public function getSnapshot(int $partnerUserId, string $month = ''): array
    {
        $partnerUserId = (int)$partnerUserId;
        [$month, $monthStart, $monthEnd, $monthNext] = $this->monthBounds($month !== '' ? $month : date('Y-m'));

        $snapshot = [
            'month' => $month,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'business' => [
                'orders_revenue' => 0.0,
                'preorders_revenue' => 0.0,
                'total_revenue' => 0.0,
                'completed_orders' => 0,
                'active_preorders' => 0
            ],
            'booked' => [
                'approved_expenses' => 0.0,
                'manual_expenses' => 0.0,
                'payroll_expenses' => 0.0
            ],
            'procurement' => [
                'open_commitments' => 0.0,
                'received_total' => 0.0,
                'supplier_invoice_total' => 0.0,
                'paid_to_suppliers_total' => 0.0,
                'outstanding_supplier_payables' => 0.0,
                'draft_total' => 0.0,
                'open_count' => 0
            ],
            'platform' => [
                'paid_total' => 0.0,
                'due_total' => 0.0,
                'overdue_total' => 0.0,
                'invoice_count' => 0
            ],
            'refunds' => [
                'pending_total' => 0.0,
                'approved_total' => 0.0,
                'completed_total' => 0.0,
                'open_cases' => 0
            ],
            'pipeline' => [
                'pending_payroll' => 0.0,
                'approved_payroll' => 0.0
            ],
            'forecast' => [
                'days_elapsed' => 0,
                'days_in_month' => 0,
                'projected_month_end_revenue' => 0.0,
                'projected_month_end_spend' => 0.0,
                'projected_month_end_profit' => 0.0,
                'profit_at_risk' => 0.0,
                'reorder_risk_count' => 0,
                'reorder_risk_units' => 0.0
            ],
            'positions' => [
                'booked_net_income' => 0.0,
                'net_after_platform_paid' => 0.0,
                'fully_loaded_position' => 0.0,
                'expense_ratio' => 0.0,
                'payroll_ratio' => 0.0
            ],
            'cost_sources' => [],
            'recommendations' => [],
            'forecast_recommendations' => []
        ];

        if ($partnerUserId <= 0) {
            return $snapshot;
        }

        if ($this->tableExists('orders') && $this->columnExists('orders', 'total_amount') && $this->columnExists('orders', 'created_at')) {
            $sql = "SELECT
                        COUNT(*) AS completed_orders,
                        COALESCE(SUM(o.total_amount), 0) AS revenue
                    FROM orders o
                    WHERE o.created_at >= ?
                      AND o.created_at < ?
                      AND o.status IN ('delivered', 'completed')
                      AND " . ($this->columnExists('orders', 'is_archived') ? "COALESCE(o.is_archived, 0) = 0 AND " : "") . $this->orderScopeExistsSql($partnerUserId, 'o');
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['business']['orders_revenue'] = $this->number($row['revenue'] ?? 0);
                $snapshot['business']['completed_orders'] = (int)($row['completed_orders'] ?? 0);
            }
        }

        if ($this->tableExists('pre_orders') && $this->columnExists('pre_orders', 'created_at') && ($this->columnExists('pre_orders', 'total_price') || $this->columnExists('pre_orders', 'total_amount'))) {
            $sumCol = $this->columnExists('pre_orders', 'total_price') ? 'total_price' : 'total_amount';
            $sql = "SELECT
                        COUNT(*) AS active_preorders,
                        COALESCE(SUM(po.{$sumCol}), 0) AS revenue
                    FROM pre_orders po
                    WHERE po.created_at >= ?
                      AND po.created_at < ?
                      AND po.reservation_status <> 'cancelled'
                      AND " . $this->preOrderScopeSql($partnerUserId, 'po');
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['business']['preorders_revenue'] = $this->number($row['revenue'] ?? 0);
                $snapshot['business']['active_preorders'] = (int)($row['active_preorders'] ?? 0);
            }
        }

        if ($this->tableExists('expenses') && $this->columnExists('expenses', 'amount') && $this->columnExists('expenses', 'expense_date') && $this->columnExists('expenses', 'recorded_by')) {
            $statusFilter = $this->columnExists('expenses', 'status') ? "AND e.status = 'approved'" : '';
            $expenseOwnerExpr = $this->columnExists('expenses', 'owner_user_id')
                ? 'COALESCE(e.owner_user_id, e.recorded_by)'
                : 'e.recorded_by';
            $sql = "SELECT
                        COALESCE(SUM(e.amount), 0) AS approved_expenses,
                        COALESCE(SUM(CASE WHEN e.category = 'Payroll' THEN e.amount ELSE 0 END), 0) AS payroll_expenses
                    FROM expenses e
                    WHERE e.expense_date >= ?
                      AND e.expense_date < ?
                      AND {$expenseOwnerExpr} = ?
                      {$statusFilter}";
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssi', $monthStart, $monthNext, $partnerUserId);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['booked']['approved_expenses'] = $this->number($row['approved_expenses'] ?? 0);
                $snapshot['booked']['payroll_expenses'] = $this->number($row['payroll_expenses'] ?? 0);
                $snapshot['booked']['manual_expenses'] = max(0, $snapshot['booked']['approved_expenses'] - $snapshot['booked']['payroll_expenses']);
            }
        }

        if ($this->tableExists('payroll') && $this->columnExists('payroll', 'gross_pay') && $this->columnExists('payroll', 'pay_period_end') && $this->tableExists('employees') && $this->columnExists('employees', 'id')) {
            $employeeScope = $this->employeeUserIdsInScope($partnerUserId);
            if (!empty($employeeScope)) {
                $idsSql = implode(',', array_fill(0, count($employeeScope), '?'));
                $types = 'ss' . str_repeat('i', count($employeeScope));
                $sql = "SELECT
                            COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.gross_pay ELSE 0 END), 0) AS pending_payroll,
                            COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.gross_pay ELSE 0 END), 0) AS approved_payroll
                        FROM payroll p
                        INNER JOIN employees e ON e.id = p.employee_id
                        WHERE p.pay_period_end >= ?
                          AND p.pay_period_end <= ?
                          AND e.user_id IN ({$idsSql})";
                $stmt = mysqli_prepare($this->conn, $sql);
                if ($stmt) {
                    $params = array_merge([$monthStart, $monthEnd], $employeeScope);
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                    mysqli_stmt_execute($stmt);
                    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                    mysqli_stmt_close($stmt);
                    $snapshot['pipeline']['pending_payroll'] = $this->number($row['pending_payroll'] ?? 0);
                    $snapshot['pipeline']['approved_payroll'] = $this->number($row['approved_payroll'] ?? 0);
                }
            }
        }

        if ($this->tableExists('purchase_orders') && $this->columnExists('purchase_orders', 'order_date') && $this->tableExists('purchase_order_items') && $this->columnExists('purchase_order_items', 'quantity_ordered') && $this->columnExists('purchase_order_items', 'unit_cost')) {
            $statusCol = $this->columnExists('purchase_orders', 'status');
            $hasReceivedQty = $this->columnExists('purchase_order_items', 'quantity_received');
            $sql = "SELECT
                        po.status,
                        COALESCE(SUM(GREATEST(COALESCE(poi.quantity_ordered, 0) - " . ($hasReceivedQty ? "COALESCE(poi.quantity_received, 0)" : "0") . ", 0) * COALESCE(poi.unit_cost, 0)), 0) AS open_commitments,
                        COALESCE(SUM(" . ($hasReceivedQty ? "COALESCE(poi.quantity_received, 0)" : "0") . " * COALESCE(poi.unit_cost, 0)), 0) AS received_total,
                        COALESCE(SUM(COALESCE(poi.quantity_ordered, 0) * COALESCE(poi.unit_cost, 0)), 0) AS draft_total
                    FROM purchase_orders po
                    LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                    WHERE po.order_date >= ?
                      AND po.order_date < ?
                      AND " . $this->purchaseOrderScopeSql($partnerUserId, 'po') . "
                    GROUP BY po.id, po.status";
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                while ($result && ($row = mysqli_fetch_assoc($result))) {
                    $status = strtolower(trim((string)($row['status'] ?? 'draft')));
                    if ($status === 'cancelled') {
                        continue;
                    }
                    if ($status === 'draft') {
                        $snapshot['procurement']['draft_total'] += $this->number($row['draft_total'] ?? 0);
                        continue;
                    }
                    $snapshot['procurement']['open_commitments'] += $this->number($row['open_commitments'] ?? 0);
                    $snapshot['procurement']['received_total'] += $this->number($row['received_total'] ?? 0);
                    $snapshot['procurement']['supplier_invoice_total'] += $this->number($row['received_total'] ?? 0);
                    if (in_array($status, ['pending', 'ordered', 'partially_received'], true)) {
                        $snapshot['procurement']['open_count']++;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        if ($this->tableExists('materials') && $this->columnExists('materials', 'current_stock') && $this->columnExists('materials', 'min_level')) {
            $sql = "SELECT
                        COUNT(*) AS reorder_risk_count,
                        COALESCE(SUM(GREATEST(m.min_level - m.current_stock, 0)), 0) AS reorder_risk_units
                    FROM materials m
                    WHERE m.current_stock <= m.min_level
                      AND " . $this->materialScopeSql($partnerUserId, 'm');
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['forecast']['reorder_risk_count'] = (int)($row['reorder_risk_count'] ?? 0);
                $snapshot['forecast']['reorder_risk_units'] = $this->number($row['reorder_risk_units'] ?? 0);
            }
        }

        if ($this->tableExists('supplier_payment_records') && $this->columnExists('supplier_payment_records', 'amount_paid') && $this->columnExists('supplier_payment_records', 'owner_user_id') && $this->columnExists('supplier_payment_records', 'payment_date')) {
            $sql = "SELECT COALESCE(SUM(amount_paid), 0) AS paid_total
                    FROM supplier_payment_records
                    WHERE owner_user_id = ?
                      AND payment_date >= ?
                      AND payment_date < ?";
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'iss', $partnerUserId, $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['procurement']['paid_to_suppliers_total'] = $this->number($row['paid_total'] ?? 0);
            }
        }

        if ($this->tableExists('partner_billing_invoices')) {
            $sql = "SELECT
                        COUNT(*) AS invoice_count,
                        COALESCE(SUM(CASE WHEN invoice_status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
                        COALESCE(SUM(CASE WHEN invoice_status = 'issued' THEN total_amount ELSE 0 END), 0) AS due_total,
                        COALESCE(SUM(CASE WHEN invoice_status = 'overdue' THEN total_amount ELSE 0 END), 0) AS overdue_total
                    FROM partner_billing_invoices
                    WHERE partner_user_id = ?
                      AND period_start >= ?
                      AND period_start < ?";
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'iss', $partnerUserId, $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['platform']['invoice_count'] = (int)($row['invoice_count'] ?? 0);
                $snapshot['platform']['paid_total'] = $this->number($row['paid_total'] ?? 0);
                $snapshot['platform']['due_total'] = $this->number($row['due_total'] ?? 0);
                $snapshot['platform']['overdue_total'] = $this->number($row['overdue_total'] ?? 0);
            }
        }

        if ($this->tableExists('refunds') && $this->tableExists('cancellations') && $this->tableExists('orders')) {
            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN r.refund_status = 'Refund Pending' THEN r.refund_amount ELSE 0 END), 0) AS pending_total,
                        COALESCE(SUM(CASE WHEN r.refund_status = 'Refund Approved' THEN r.refund_amount ELSE 0 END), 0) AS approved_total,
                        COALESCE(SUM(CASE WHEN r.refund_status = 'Refund Completed' THEN r.refund_amount ELSE 0 END), 0) AS completed_total,
                        COALESCE(SUM(CASE WHEN r.refund_status IN ('Refund Pending', 'Refund Approved') THEN 1 ELSE 0 END), 0) AS open_cases
                    FROM refunds r
                    INNER JOIN cancellations c ON c.id = r.cancellation_id
                    INNER JOIN orders o ON o.id = c.order_id
                    WHERE c.cancellation_date >= ?
                      AND c.cancellation_date < ?
                      AND " . $this->orderScopeExistsSql($partnerUserId, 'o');
            $stmt = mysqli_prepare($this->conn, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
                mysqli_stmt_close($stmt);
                $snapshot['refunds']['pending_total'] = $this->number($row['pending_total'] ?? 0);
                $snapshot['refunds']['approved_total'] = $this->number($row['approved_total'] ?? 0);
                $snapshot['refunds']['completed_total'] = $this->number($row['completed_total'] ?? 0);
                $snapshot['refunds']['open_cases'] = (int)($row['open_cases'] ?? 0);
            }
        }

        $snapshot['business']['total_revenue'] = round($snapshot['business']['orders_revenue'] + $snapshot['business']['preorders_revenue'], 2);
        $snapshot['procurement']['outstanding_supplier_payables'] = round(max(0, $snapshot['procurement']['supplier_invoice_total'] - $snapshot['procurement']['paid_to_suppliers_total']), 2);

        $bookedExpenses = $snapshot['booked']['approved_expenses'];
        $paidPlatform = $snapshot['platform']['paid_total'];
        $openObligations = $snapshot['procurement']['open_commitments']
            + $snapshot['platform']['due_total']
            + $snapshot['platform']['overdue_total']
            + $snapshot['refunds']['pending_total']
            + $snapshot['refunds']['approved_total']
            + $snapshot['pipeline']['pending_payroll'];

        $snapshot['positions']['booked_net_income'] = round($snapshot['business']['total_revenue'] - $bookedExpenses, 2);
        $snapshot['positions']['net_after_platform_paid'] = round($snapshot['business']['total_revenue'] - $bookedExpenses - $paidPlatform, 2);
        $snapshot['positions']['fully_loaded_position'] = round($snapshot['business']['total_revenue'] - $bookedExpenses - $openObligations - $paidPlatform, 2);
        $snapshot['positions']['expense_ratio'] = $snapshot['business']['total_revenue'] > 0
            ? round(($bookedExpenses / $snapshot['business']['total_revenue']) * 100, 2)
            : 0.0;
        $snapshot['positions']['payroll_ratio'] = $snapshot['business']['total_revenue'] > 0
            ? round((($snapshot['booked']['payroll_expenses'] + $snapshot['pipeline']['pending_payroll']) / $snapshot['business']['total_revenue']) * 100, 2)
            : 0.0;

        $daysInMonth = (int)date('t', strtotime($monthStart));
        $daysElapsed = $month === date('Y-m') ? max(1, (int)date('j')) : $daysInMonth;
        $projectedRevenue = $daysElapsed > 0 ? round(($snapshot['business']['total_revenue'] / $daysElapsed) * $daysInMonth, 2) : $snapshot['business']['total_revenue'];
        $projectedBookedSpend = $daysElapsed > 0 ? round(($snapshot['booked']['approved_expenses'] / $daysElapsed) * $daysInMonth, 2) : $snapshot['booked']['approved_expenses'];
        $projectedMonthEndSpend = round(
            $projectedBookedSpend
            + $snapshot['pipeline']['pending_payroll']
            + $snapshot['platform']['due_total']
            + $snapshot['platform']['overdue_total']
            + $snapshot['refunds']['pending_total']
            + $snapshot['refunds']['approved_total']
            + $snapshot['procurement']['open_commitments'],
            2
        );
        $snapshot['forecast']['days_elapsed'] = $daysElapsed;
        $snapshot['forecast']['days_in_month'] = $daysInMonth;
        $snapshot['forecast']['projected_month_end_revenue'] = $projectedRevenue;
        $snapshot['forecast']['projected_month_end_spend'] = $projectedMonthEndSpend;
        $snapshot['forecast']['projected_month_end_profit'] = round($projectedRevenue - $projectedMonthEndSpend, 2);
        $snapshot['forecast']['profit_at_risk'] = round(max(0, $snapshot['positions']['booked_net_income'] - $snapshot['positions']['fully_loaded_position']), 2);

        $snapshot['cost_sources'] = [
            [
                'label' => 'Booked Operating Expenses',
                'amount' => round($snapshot['booked']['manual_expenses'], 2),
                'type' => 'booked',
                'note' => 'Approved manual and operating costs already recorded in the ledger.'
            ],
            [
                'label' => 'Booked Payroll',
                'amount' => round($snapshot['booked']['payroll_expenses'], 2),
                'type' => 'booked',
                'note' => 'Payroll already approved and posted into expenses.'
            ],
            [
                'label' => 'Pending Payroll Pipeline',
                'amount' => round($snapshot['pipeline']['pending_payroll'], 2),
                'type' => 'pipeline',
                'note' => 'Payroll drafted or pending approval that may hit your books next.'
            ],
            [
                'label' => 'Open Procurement Commitments',
                'amount' => round($snapshot['procurement']['open_commitments'], 2),
                'type' => 'commitment',
                'note' => 'Purchase orders not yet fully completed.'
            ],
            [
                'label' => 'Received Supplier Invoices',
                'amount' => round($snapshot['procurement']['supplier_invoice_total'], 2),
                'type' => 'booked',
                'note' => 'Supplier-side inventory cost already received into stock.'
            ],
            [
                'label' => 'Outstanding Supplier Payables',
                'amount' => round($snapshot['procurement']['outstanding_supplier_payables'], 2),
                'type' => 'liability',
                'note' => 'Received supplier invoices that are not yet fully paid.'
            ],
            [
                'label' => 'Platform Billing Due',
                'amount' => round($snapshot['platform']['due_total'] + $snapshot['platform']['overdue_total'], 2),
                'type' => 'liability',
                'note' => 'Current partner invoices that still need settlement.'
            ],
            [
                'label' => 'Refund Exposure',
                'amount' => round($snapshot['refunds']['pending_total'] + $snapshot['refunds']['approved_total'], 2),
                'type' => 'liability',
                'note' => 'Customer refunds still waiting for payout completion.'
            ]
        ];

        $revenue = $snapshot['business']['total_revenue'];
        if ($snapshot['positions']['expense_ratio'] >= 70) {
            $snapshot['recommendations'][] = [
                'priority' => 'critical',
                'headline' => 'Booked cost ratio is too high',
                'action' => 'Freeze non-essential spend and review pricing or product mix this week.',
                'detail' => 'Booked expenses are ' . number_format($snapshot['positions']['expense_ratio'], 1) . '% of revenue.'
            ];
        }
        if (($snapshot['procurement']['open_commitments'] > ($revenue * 0.35)) && $snapshot['procurement']['open_commitments'] > 0) {
            $snapshot['recommendations'][] = [
                'priority' => 'high',
                'headline' => 'Procurement commitments are building up',
                'action' => 'Review open POs against real demand before placing more orders.',
                'detail' => 'Open procurement commitments reached ' . number_format(($snapshot['procurement']['open_commitments'] / max(1, $revenue)) * 100, 1) . '% of monthly revenue.'
            ];
        }
        if (($snapshot['platform']['due_total'] + $snapshot['platform']['overdue_total']) > 0) {
            $snapshot['recommendations'][] = [
                'priority' => 'high',
                'headline' => 'Platform billing needs attention',
                'action' => 'Settle due invoices early to avoid service friction and overdue accumulation.',
                'detail' => 'Outstanding platform billing is PHP ' . number_format($snapshot['platform']['due_total'] + $snapshot['platform']['overdue_total'], 2) . '.'
            ];
        }
        if (($snapshot['refunds']['pending_total'] + $snapshot['refunds']['approved_total']) > 0) {
            $snapshot['recommendations'][] = [
                'priority' => 'medium',
                'headline' => 'Refund pressure is active',
                'action' => 'Audit quality, prep, and fulfillment issues behind open refund cases.',
                'detail' => 'Open refund exposure totals PHP ' . number_format($snapshot['refunds']['pending_total'] + $snapshot['refunds']['approved_total'], 2) . '.'
            ];
        }
        if ($snapshot['positions']['payroll_ratio'] >= 35) {
            $snapshot['recommendations'][] = [
                'priority' => 'medium',
                'headline' => 'Payroll is taking a large share of revenue',
                'action' => 'Review staffing schedules, peak-hour coverage, and overtime usage.',
                'detail' => 'Payroll load is ' . number_format($snapshot['positions']['payroll_ratio'], 1) . '% of revenue.'
            ];
        }
        if (($snapshot['forecast']['reorder_risk_count'] ?? 0) > 0) {
            $snapshot['recommendations'][] = [
                'priority' => 'high',
                'headline' => 'Reorder risk is building in raw materials',
                'action' => 'Inspect low-stock materials and rebalance replenishment before production is affected.',
                'detail' => number_format((int)$snapshot['forecast']['reorder_risk_count']) . ' material(s) are already at or below minimum stock.'
            ];
        }
        if (($snapshot['procurement']['outstanding_supplier_payables'] ?? 0) > 0) {
            $snapshot['recommendations'][] = [
                'priority' => 'medium',
                'headline' => 'Supplier balances are still open',
                'action' => 'Record or settle supplier payments so supplier relationships and delivery reliability stay healthy.',
                'detail' => 'Outstanding supplier payables total PHP ' . number_format($snapshot['procurement']['outstanding_supplier_payables'], 2) . '.'
            ];
        }
        if (empty($snapshot['recommendations'])) {
            $snapshot['recommendations'][] = [
                'priority' => 'low',
                'headline' => 'Business cost posture is stable',
                'action' => 'Keep weekly reviews on spend, procurement timing, and billing due dates.',
                'detail' => 'No major cost pressure was detected for this month.'
            ];
        }

        if (($snapshot['procurement']['open_commitments'] > ($snapshot['business']['total_revenue'] * 0.30)) && $snapshot['procurement']['open_commitments'] > 0) {
            $snapshot['forecast_recommendations'][] = [
                'priority' => 'high',
                'headline' => 'Slow reordering',
                'action' => 'Pause low-priority restocks and focus spend on critical low-stock materials first.',
                'detail' => 'Open procurement commitments are already high relative to revenue.'
            ];
        }
        if (($snapshot['platform']['due_total'] + $snapshot['platform']['overdue_total']) > 0) {
            $snapshot['forecast_recommendations'][] = [
                'priority' => 'high',
                'headline' => 'Settle billing now',
                'action' => 'Clear due platform invoices before they compound with payroll and procurement pressure.',
                'detail' => 'Platform billing has active due or overdue amounts.'
            ];
        }
        if (($snapshot['refunds']['pending_total'] + $snapshot['refunds']['approved_total']) > 0) {
            $snapshot['forecast_recommendations'][] = [
                'priority' => 'medium',
                'headline' => 'Reduce refund leakage',
                'action' => 'Audit damaged orders, prep quality, packaging, and delivery problems driving refund cases.',
                'detail' => 'Refund exposure is still active in the current month.'
            ];
        }
        if ($snapshot['positions']['payroll_ratio'] >= 35) {
            $snapshot['forecast_recommendations'][] = [
                'priority' => 'medium',
                'headline' => 'Optimize staffing',
                'action' => 'Match staffing and overtime decisions to forecasted demand and actual peak hours.',
                'detail' => 'Payroll is consuming a large share of revenue.'
            ];
        }
        if (empty($snapshot['forecast_recommendations'])) {
            $snapshot['forecast_recommendations'][] = [
                'priority' => 'low',
                'headline' => 'Forecast pressure is stable',
                'action' => 'Keep weekly reviews on replenishment pace, billing due dates, refunds, and staffing efficiency.',
                'detail' => 'No urgent forecast-driven action is required right now.'
            ];
        }

        return $snapshot;
    }

    public function getTrend(int $partnerUserId, int $months = 6): array
    {
        $partnerUserId = (int)$partnerUserId;
        $months = max(3, min(12, $months));
        $trend = [
            'labels' => [],
            'revenue' => [],
            'booked_expenses' => [],
            'open_commitments' => [],
            'platform_costs' => []
        ];

        if ($partnerUserId <= 0) {
            return $trend;
        }

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime(date('Y-m-01') . " -{$i} month"));
            $snapshot = $this->getSnapshot($partnerUserId, $month);
            $trend['labels'][] = date('M Y', strtotime($month . '-01'));
            $trend['revenue'][] = round((float)$snapshot['business']['total_revenue'], 2);
            $trend['booked_expenses'][] = round((float)$snapshot['booked']['approved_expenses'], 2);
            $trend['open_commitments'][] = round((float)$snapshot['procurement']['open_commitments'], 2);
            $trend['platform_costs'][] = round((float)($snapshot['platform']['paid_total'] + $snapshot['platform']['due_total'] + $snapshot['platform']['overdue_total']), 2);
        }

        return $trend;
    }
}
