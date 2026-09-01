<?php

class PartnerExpenseSyncService
{
    private mysqli $conn;

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

    private function monthBounds(string $month): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $start = $month . '-01';
        $next = date('Y-m-d', strtotime($start . ' +1 month'));
        return [$month, $start, $next];
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

    public function ensureSchema(): bool
    {
        $createExpenses = "
            CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(120) NOT NULL,
                description TEXT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                expense_date DATE NOT NULL,
                vendor VARCHAR(120) NULL,
                payment_method VARCHAR(60) NULL,
                receipt_image VARCHAR(255) NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                recorded_by INT(11) NULL,
                source_type VARCHAR(50) NULL,
                source_id INT(11) NULL,
                owner_user_id INT(11) NULL,
                is_system_generated TINYINT(1) NOT NULL DEFAULT 0,
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_expense_owner_date (recorded_by, expense_date, status),
                KEY idx_expense_source (source_type, source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        if (!mysqli_query($this->conn, $createExpenses)) {
            return false;
        }

        $alterMap = [
            'status' => "ALTER TABLE expenses ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER receipt_image",
            'recorded_by' => "ALTER TABLE expenses ADD COLUMN recorded_by INT(11) NULL AFTER expense_date",
            'vendor' => "ALTER TABLE expenses ADD COLUMN vendor VARCHAR(120) NULL AFTER expense_date",
            'payment_method' => "ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(60) NULL AFTER vendor",
            'receipt_image' => "ALTER TABLE expenses ADD COLUMN receipt_image VARCHAR(255) NULL AFTER payment_method",
            'source_type' => "ALTER TABLE expenses ADD COLUMN source_type VARCHAR(50) NULL AFTER recorded_by",
            'source_id' => "ALTER TABLE expenses ADD COLUMN source_id INT(11) NULL AFTER source_type",
            'owner_user_id' => "ALTER TABLE expenses ADD COLUMN owner_user_id INT(11) NULL AFTER source_id",
            'is_system_generated' => "ALTER TABLE expenses ADD COLUMN is_system_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_user_id",
            'metadata_json' => "ALTER TABLE expenses ADD COLUMN metadata_json LONGTEXT NULL AFTER is_system_generated",
            'created_at' => "ALTER TABLE expenses ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER metadata_json",
            'updated_at' => "ALTER TABLE expenses ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ];

        foreach ($alterMap as $column => $sql) {
            if ($this->columnExists('expenses', $column)) {
                continue;
            }
            if (!mysqli_query($this->conn, $sql)) {
                return false;
            }
        }

        return true;
    }

    private function upsertExpense(array $expense): bool
    {
        $sourceType = trim((string)($expense['source_type'] ?? ''));
        $sourceId = (int)($expense['source_id'] ?? 0);
        $recordedBy = (int)($expense['recorded_by'] ?? 0);
        if ($sourceType === '' || $sourceId <= 0 || $recordedBy <= 0) {
            return false;
        }

        $existingId = 0;
        $checkStmt = mysqli_prepare($this->conn, "SELECT id FROM expenses WHERE source_type = ? AND source_id = ? AND recorded_by = ? LIMIT 1");
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, 'sii', $sourceType, $sourceId, $recordedBy);
            mysqli_stmt_execute($checkStmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt)) ?: null;
            mysqli_stmt_close($checkStmt);
            $existingId = (int)($existing['id'] ?? 0);
        }

        $metadataJson = !empty($expense['metadata_json']) ? (string)$expense['metadata_json'] : null;
        if ($existingId > 0) {
            $stmt = mysqli_prepare(
                $this->conn,
                "UPDATE expenses
                 SET category = ?, description = ?, amount = ?, expense_date = ?, vendor = ?, payment_method = ?, status = ?, owner_user_id = ?, is_system_generated = 1, metadata_json = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            if (!$stmt) {
                return false;
            }
            mysqli_stmt_bind_param(
                $stmt,
                'ssdssssissi',
                $expense['category'],
                $expense['description'],
                $expense['amount'],
                $expense['expense_date'],
                $expense['vendor'],
                $expense['payment_method'],
                $expense['status'],
                $expense['owner_user_id'],
                $metadataJson,
                $existingId
            );
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return $ok;
        }

        $stmt = mysqli_prepare(
            $this->conn,
            "INSERT INTO expenses
             (category, description, amount, expense_date, vendor, payment_method, status, recorded_by, source_type, source_id, owner_user_id, is_system_generated, metadata_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'ssdssssiisss',
            $expense['category'],
            $expense['description'],
            $expense['amount'],
            $expense['expense_date'],
            $expense['vendor'],
            $expense['payment_method'],
            $expense['status'],
            $expense['recorded_by'],
            $sourceType,
            $sourceId,
            $expense['owner_user_id'],
            $metadataJson
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }

    private function deleteExpenseBySource(string $sourceType, int $sourceId, int $recordedBy): bool
    {
        if ($sourceType === '' || $sourceId <= 0 || $recordedBy <= 0 || !$this->tableExists('expenses')) {
            return false;
        }
        $stmt = mysqli_prepare($this->conn, "DELETE FROM expenses WHERE source_type = ? AND source_id = ? AND recorded_by = ?");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sii', $sourceType, $sourceId, $recordedBy);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }

    private function syncBillingInvoices(int $partnerUserId, string $monthStart, string $monthNext): int
    {
        if (!$this->tableExists('partner_billing_invoices')) {
            return 0;
        }

        $stmt = mysqli_prepare(
            $this->conn,
            "SELECT id, invoice_number, invoice_status, period_start, due_at, paid_at, total_amount, notes
             FROM partner_billing_invoices
             WHERE partner_user_id = ?
               AND period_start >= ?
               AND period_start < ?
               AND invoice_status <> 'void'"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'iss', $partnerUserId, $monthStart, $monthNext);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $count = 0;
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $status = strtolower(trim((string)($row['invoice_status'] ?? 'issued')));
            $expenseStatus = $status === 'paid' ? 'approved' : 'pending';
            $expenseDate = !empty($row['paid_at']) && $status === 'paid'
                ? date('Y-m-d', strtotime((string)$row['paid_at']))
                : (!empty($row['due_at']) ? date('Y-m-d', strtotime((string)$row['due_at'])) : (string)$row['period_start']);
            $expense = [
                'category' => 'Platform Billing',
                'description' => 'Platform billing invoice ' . (string)($row['invoice_number'] ?? ('#' . (int)$row['id'])),
                'amount' => (float)($row['total_amount'] ?? 0),
                'expense_date' => $expenseDate,
                'vendor' => 'Lechon Delights Platform',
                'payment_method' => 'Platform Billing',
                'status' => $expenseStatus,
                'recorded_by' => $partnerUserId,
                'source_type' => 'partner_billing_invoice',
                'source_id' => (int)($row['id'] ?? 0),
                'owner_user_id' => $partnerUserId,
                'metadata_json' => json_encode([
                    'invoice_number' => $row['invoice_number'] ?? '',
                    'invoice_status' => $row['invoice_status'] ?? '',
                    'notes' => $row['notes'] ?? ''
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ];
            if ($expense['amount'] > 0 && $this->upsertExpense($expense)) {
                $count++;
            }
        }
        mysqli_stmt_close($stmt);
        return $count;
    }

    private function syncRefundExpenses(int $partnerUserId, string $monthStart, string $monthNext): int
    {
        if (!$this->tableExists('refunds') || !$this->tableExists('cancellations') || !$this->tableExists('orders')) {
            return 0;
        }

        $scopeSql = "EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (
                    oi_scope.product_id COLLATE utf8mb4_general_ci = p_scope.product_id COLLATE utf8mb4_general_ci
                    OR oi_scope.product_id COLLATE utf8mb4_general_ci = CAST(p_scope.id AS CHAR) COLLATE utf8mb4_general_ci
                    OR CAST(oi_scope.product_id AS UNSIGNED) = p_scope.id
                )
            WHERE oi_scope.order_id = o.id
              AND p_scope.seller_id = {$partnerUserId}
        )";

        $query = "
            SELECT r.id, r.refund_amount, r.refund_status, r.processed_date, r.refund_reason,
                   c.cancellation_date, o.order_number
            FROM refunds r
            INNER JOIN cancellations c ON c.id = r.cancellation_id
            INNER JOIN orders o ON o.id = c.order_id
            WHERE {$scopeSql}
              AND COALESCE(DATE(r.processed_date), DATE(c.cancellation_date)) >= ?
              AND COALESCE(DATE(r.processed_date), DATE(c.cancellation_date)) < ?
              AND r.refund_status IN ('Refund Pending', 'Refund Approved', 'Refund Completed')
        ";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $count = 0;
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $refundStatus = (string)($row['refund_status'] ?? 'Refund Pending');
            $expense = [
                'category' => 'Refunds',
                'description' => 'Customer refund for order ' . (string)($row['order_number'] ?? ('#' . (int)$row['id'])),
                'amount' => (float)($row['refund_amount'] ?? 0),
                'expense_date' => !empty($row['processed_date']) ? date('Y-m-d', strtotime((string)$row['processed_date'])) : date('Y-m-d', strtotime((string)$row['cancellation_date'])),
                'vendor' => 'Customer Refund',
                'payment_method' => 'Refund Payout',
                'status' => $refundStatus === 'Refund Completed' ? 'approved' : 'pending',
                'recorded_by' => $partnerUserId,
                'source_type' => 'refund',
                'source_id' => (int)($row['id'] ?? 0),
                'owner_user_id' => $partnerUserId,
                'metadata_json' => json_encode([
                    'refund_status' => $refundStatus,
                    'refund_reason' => $row['refund_reason'] ?? ''
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ];
            if ($expense['amount'] > 0 && $this->upsertExpense($expense)) {
                $count++;
            }
        }
        mysqli_stmt_close($stmt);
        return $count;
    }

    private function syncProcurementExpenses(int $partnerUserId, string $monthStart, string $monthNext): array
    {
        if (!$this->tableExists('purchase_orders') || !$this->tableExists('purchase_order_items')) {
            return ['commitments_synced' => 0, 'supplier_invoices_synced' => 0];
        }

        $hasReceivedColumn = $this->columnExists('purchase_order_items', 'quantity_received');
        $outstandingExpr = $hasReceivedColumn
            ? "SUM(GREATEST(COALESCE(poi.quantity_ordered, 0) - COALESCE(poi.quantity_received, 0), 0) * COALESCE(poi.unit_cost, 0))"
            : "SUM(COALESCE(poi.quantity_ordered, 0) * COALESCE(poi.unit_cost, 0))";
        $receivedExpr = $hasReceivedColumn
            ? "SUM(COALESCE(poi.quantity_received, 0) * COALESCE(poi.unit_cost, 0))"
            : "SUM(CASE WHEN po.status = 'completed' THEN COALESCE(poi.quantity_ordered, 0) * COALESCE(poi.unit_cost, 0) ELSE 0 END)";

        $query = "
            SELECT po.id,
                   po.po_number,
                   po.status,
                   po.order_date,
                   COALESCE(s.name, 'Supplier') AS supplier_name,
                   COALESCE({$outstandingExpr}, 0) AS outstanding_amount,
                   COALESCE({$receivedExpr}, 0) AS received_amount
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
            WHERE po.order_date >= ?
              AND po.order_date < ?
              AND " . $this->purchaseOrderScopeSql($partnerUserId, 'po') . "
            GROUP BY po.id, po.po_number, po.status, po.order_date, s.name
        ";
        $stmt = mysqli_prepare($this->conn, $query);
        if (!$stmt) {
            return ['commitments_synced' => 0, 'supplier_invoices_synced' => 0];
        }
        mysqli_stmt_bind_param($stmt, 'ss', $monthStart, $monthNext);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $counts = ['commitments_synced' => 0, 'supplier_invoices_synced' => 0];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $status = strtolower(trim((string)($row['status'] ?? 'draft')));
            if ($status === 'cancelled') {
                $this->deleteExpenseBySource('purchase_order_commitment', (int)($row['id'] ?? 0), $partnerUserId);
                $this->deleteExpenseBySource('supplier_invoice', (int)($row['id'] ?? 0), $partnerUserId);
                continue;
            }

            $outstandingAmount = (float)($row['outstanding_amount'] ?? 0);
            if ($outstandingAmount > 0) {
                $commitmentExpense = [
                    'category' => 'Inventory Procurement',
                    'description' => 'Outstanding purchase commitment for PO ' . (string)($row['po_number'] ?? ('#' . (int)$row['id'])),
                    'amount' => $outstandingAmount,
                    'expense_date' => (string)($row['order_date'] ?? date('Y-m-d')),
                    'vendor' => (string)($row['supplier_name'] ?? 'Supplier'),
                    'payment_method' => 'Purchase Order',
                    'status' => 'pending',
                    'recorded_by' => $partnerUserId,
                    'source_type' => 'purchase_order_commitment',
                    'source_id' => (int)($row['id'] ?? 0),
                    'owner_user_id' => $partnerUserId,
                    'metadata_json' => json_encode([
                        'po_number' => $row['po_number'] ?? '',
                        'po_status' => $row['status'] ?? '',
                        'expense_kind' => 'outstanding_commitment'
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
                if ($this->upsertExpense($commitmentExpense)) {
                    $counts['commitments_synced']++;
                }
            } else {
                $this->deleteExpenseBySource('purchase_order_commitment', (int)($row['id'] ?? 0), $partnerUserId);
            }

            $receivedAmount = (float)($row['received_amount'] ?? 0);
            if ($receivedAmount > 0) {
                $invoiceExpense = [
                    'category' => 'Supplier Invoice',
                    'description' => 'Received stock / supplier invoice for PO ' . (string)($row['po_number'] ?? ('#' . (int)$row['id'])),
                    'amount' => $receivedAmount,
                    'expense_date' => (string)($row['order_date'] ?? date('Y-m-d')),
                    'vendor' => (string)($row['supplier_name'] ?? 'Supplier'),
                    'payment_method' => 'Supplier Invoice',
                    'status' => 'approved',
                    'recorded_by' => $partnerUserId,
                    'source_type' => 'supplier_invoice',
                    'source_id' => (int)($row['id'] ?? 0),
                    'owner_user_id' => $partnerUserId,
                    'metadata_json' => json_encode([
                        'po_number' => $row['po_number'] ?? '',
                        'po_status' => $row['status'] ?? '',
                        'expense_kind' => 'received_stock'
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];
                if ($this->upsertExpense($invoiceExpense)) {
                    $counts['supplier_invoices_synced']++;
                }
            } else {
                $this->deleteExpenseBySource('supplier_invoice', (int)($row['id'] ?? 0), $partnerUserId);
            }
        }
        mysqli_stmt_close($stmt);
        return $counts;
    }

    public function syncPartnerMonth(int $partnerUserId, string $month = ''): array
    {
        $partnerUserId = (int)$partnerUserId;
        [$month, $monthStart, $monthNext] = $this->monthBounds($month !== '' ? $month : date('Y-m'));
        $summary = [
            'month' => $month,
            'billing_synced' => 0,
            'refunds_synced' => 0,
            'procurement_synced' => 0,
            'supplier_invoices_synced' => 0,
            'schema_ready' => false
        ];
        if ($partnerUserId <= 0) {
            return $summary;
        }

        $summary['schema_ready'] = $this->ensureSchema();
        if (!$summary['schema_ready']) {
            return $summary;
        }

        $summary['billing_synced'] = $this->syncBillingInvoices($partnerUserId, $monthStart, $monthNext);
        $summary['refunds_synced'] = $this->syncRefundExpenses($partnerUserId, $monthStart, $monthNext);
        $procurementSummary = $this->syncProcurementExpenses($partnerUserId, $monthStart, $monthNext);
        $summary['procurement_synced'] = (int)($procurementSummary['commitments_synced'] ?? 0);
        $summary['supplier_invoices_synced'] = (int)($procurementSummary['supplier_invoices_synced'] ?? 0);

        return $summary;
    }
}
