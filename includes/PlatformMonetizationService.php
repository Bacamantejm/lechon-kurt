<?php

class PlatformMonetizationService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function ensureReady(int $actor_user_id = 0): void
    {
        $this->ensureSchema();
        $this->ensureBillingPermissions();
        $this->seedDefaultPlans($actor_user_id);
        $this->seedDefaultFeeRule($actor_user_id);
        $this->syncInvoiceAging();
    }

    public function ensureSchema(): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS platform_subscription_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plan_code VARCHAR(80) NOT NULL,
                plan_name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                annual_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                included_order_fee_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                included_order_fee_flat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                max_staff_accounts INT NOT NULL DEFAULT 1,
                includes_ai_automation TINYINT(1) NOT NULL DEFAULT 0,
                includes_priority_support TINYINT(1) NOT NULL DEFAULT 0,
                includes_featured_placement TINYINT(1) NOT NULL DEFAULT 0,
                includes_custom_branding TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_platform_plan_code (plan_code),
                KEY idx_platform_plans_active (is_active, plan_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS partner_plan_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                partner_user_id INT NOT NULL,
                plan_id INT NOT NULL,
                billing_cycle ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
                subscription_status ENUM('trial','active','past_due','paused','cancelled') NOT NULL DEFAULT 'active',
                price_override DECIMAL(12,2) NULL,
                started_at DATE NULL,
                renews_at DATE NULL,
                ended_at DATE NULL,
                notes TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_partner_plan_subscription (partner_user_id),
                KEY idx_partner_plan_status (subscription_status, renews_at),
                KEY idx_partner_plan_plan (plan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS platform_fee_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                partner_user_id INT NULL,
                rule_scope ENUM('global','partner') NOT NULL DEFAULT 'global',
                rule_name VARCHAR(150) NOT NULL,
                fee_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                fee_flat_per_order DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                effective_from DATE NOT NULL,
                effective_to DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_platform_fee_rules_scope (rule_scope, is_active, effective_from),
                KEY idx_platform_fee_rules_partner (partner_user_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS partner_billing_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(60) NOT NULL,
                partner_user_id INT NOT NULL,
                subscription_id INT NULL,
                invoice_type ENUM('subscription','platform_fee','combined','manual') NOT NULL DEFAULT 'combined',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                subscription_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                order_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'PHP',
                invoice_status ENUM('draft','issued','paid','overdue','void') NOT NULL DEFAULT 'issued',
                issued_at DATETIME NULL,
                due_at DATETIME NULL,
                paid_at DATETIME NULL,
                payment_reference VARCHAR(120) NULL,
                payment_channel VARCHAR(60) NULL,
                line_items_json LONGTEXT NULL,
                notes TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_partner_billing_invoice_number (invoice_number),
                KEY idx_partner_billing_partner (partner_user_id, invoice_status),
                KEY idx_partner_billing_due (invoice_status, due_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS partner_invoice_payment_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                partner_user_id INT NOT NULL,
                provider VARCHAR(40) NOT NULL DEFAULT 'paymongo',
                session_id VARCHAR(120) NULL,
                checkout_url TEXT NULL,
                payment_status ENUM('pending','paid','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'PHP',
                payment_method VARCHAR(40) NULL,
                transaction_reference VARCHAR(120) NULL,
                provider_payload LONGTEXT NULL,
                paid_at DATETIME NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_partner_invoice_session (session_id),
                KEY idx_partner_invoice_payment_invoice (invoice_id, payment_status),
                KEY idx_partner_invoice_payment_partner (partner_user_id, payment_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS partner_billing_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                partner_user_id INT NOT NULL,
                reminder_type ENUM('invoice_issued','due_soon','overdue','manual') NOT NULL DEFAULT 'manual',
                delivery_channel ENUM('in_app','email','both') NOT NULL DEFAULT 'both',
                delivery_status ENUM('sent','partial','failed') NOT NULL DEFAULT 'sent',
                sent_to_email VARCHAR(190) NULL,
                subject VARCHAR(255) NULL,
                message TEXT NULL,
                sent_by INT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_partner_billing_notification_invoice (invoice_id, reminder_type, sent_at),
                KEY idx_partner_billing_notification_partner (partner_user_id, reminder_type, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS partner_subscription_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                partner_user_id INT NOT NULL,
                current_subscription_id INT NULL,
                requested_plan_id INT NOT NULL,
                requested_billing_cycle ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
                request_type ENUM('new','renew','upgrade','downgrade','change_plan') NOT NULL DEFAULT 'new',
                request_status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                partner_notes TEXT NULL,
                review_notes TEXT NULL,
                requested_by INT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_partner_subscription_requests_partner (partner_user_id, request_status, created_at),
                KEY idx_partner_subscription_requests_plan (requested_plan_id, request_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($queries as $query) {
            $this->conn->query($query);
        }
    }

    private function ensureBillingPermissions(): void
    {
        if (!$this->tableExists('roles') || !$this->tableExists('permissions') || !$this->tableExists('role_permissions')) {
            return;
        }

        $permissions = [
            ['billing.view', 'billing', 'view', 'View partner billing pages and invoices'],
            ['billing.manage', 'billing', 'manage', 'Manage partner billing workflows and invoice actions']
        ];

        $permission_ids = [];
        foreach ($permissions as $permission_meta) {
            $existing = $this->fetchOne("SELECT id FROM permissions WHERE name = ? LIMIT 1", [$permission_meta[0]], 's');
            if ($existing) {
                $permission_ids[$permission_meta[0]] = (int)$existing['id'];
                continue;
            }

            $stmt = $this->conn->prepare(
                "INSERT INTO permissions (name, module, action, description, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                [$name, $module, $action, $description] = $permission_meta;
                $stmt->bind_param('ssss', $name, $module, $action, $description);
                $stmt->execute();
                $permission_ids[$name] = (int)$this->conn->insert_id;
                $stmt->close();
            }
        }

        $role_permission_map = [
            'business_owner' => ['billing.view', 'billing.manage'],
            'finance_manager' => ['billing.view', 'billing.manage']
        ];

        foreach ($role_permission_map as $role_name => $permission_names) {
            $role = $this->fetchOne("SELECT id FROM roles WHERE name = ? LIMIT 1", [$role_name], 's');
            $role_id = (int)($role['id'] ?? 0);
            if ($role_id <= 0) {
                continue;
            }

            foreach ($permission_names as $permission_name) {
                $permission_id = (int)($permission_ids[$permission_name] ?? 0);
                if ($permission_id <= 0) {
                    continue;
                }

                $existing_link = $this->fetchOne(
                    "SELECT role_id FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1",
                    [$role_id, $permission_id],
                    'ii'
                );
                if ($existing_link) {
                    continue;
                }

                $stmt = $this->conn->prepare(
                    "INSERT INTO role_permissions (role_id, permission_id, assigned_at) VALUES (?, ?, NOW())"
                );
                if ($stmt) {
                    $stmt->bind_param('ii', $role_id, $permission_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    public function getPlans(): array
    {
        return $this->rowsIfTable(
            'platform_subscription_plans',
            "SELECT p.*,
                    (
                        SELECT COUNT(*)
                        FROM partner_plan_subscriptions s
                        WHERE s.plan_id = p.id
                          AND s.subscription_status IN ('trial','active','past_due','paused')
                    ) AS active_subscribers
             FROM platform_subscription_plans p
             ORDER BY p.is_active DESC, p.monthly_price ASC, p.plan_name ASC"
        );
    }

    public function savePlan(array $payload, int $actor_user_id): bool
    {
        $plan_id = (int)($payload['plan_id'] ?? 0);
        $plan_name = substr(trim((string)($payload['plan_name'] ?? '')), 0, 120);
        $plan_code = strtolower(trim((string)($payload['plan_code'] ?? '')));
        $description = trim((string)($payload['description'] ?? ''));
        $monthly_price = round((float)($payload['monthly_price'] ?? 0), 2);
        $annual_price = round((float)($payload['annual_price'] ?? 0), 2);
        $order_fee_percent = round((float)($payload['included_order_fee_percent'] ?? 0), 2);
        $order_fee_flat = round((float)($payload['included_order_fee_flat'] ?? 0), 2);
        $max_staff_accounts = max(1, (int)($payload['max_staff_accounts'] ?? 1));
        $is_active = (int)($payload['is_active'] ?? 0) === 1 ? 1 : 0;
        $includes_ai = (int)($payload['includes_ai_automation'] ?? 0) === 1 ? 1 : 0;
        $includes_priority = (int)($payload['includes_priority_support'] ?? 0) === 1 ? 1 : 0;
        $includes_featured = (int)($payload['includes_featured_placement'] ?? 0) === 1 ? 1 : 0;
        $includes_branding = (int)($payload['includes_custom_branding'] ?? 0) === 1 ? 1 : 0;

        if ($plan_name === '') {
            return false;
        }
        if ($plan_code === '') {
            $plan_code = strtolower((string)(preg_replace('/[^a-zA-Z0-9]+/', '_', $plan_name) ?? ''));
        }
        $plan_code = substr(trim($plan_code, '_'), 0, 80);
        if ($plan_code === '') {
            return false;
        }

        if ($plan_id > 0) {
            $stmt = $this->conn->prepare(
                "UPDATE platform_subscription_plans
                 SET plan_code = ?, plan_name = ?, description = ?, monthly_price = ?, annual_price = ?,
                     included_order_fee_percent = ?, included_order_fee_flat = ?, max_staff_accounts = ?,
                     includes_ai_automation = ?, includes_priority_support = ?, includes_featured_placement = ?,
                     includes_custom_branding = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param(
                'sssddddiiiiiiii',
                $plan_code,
                $plan_name,
                $description,
                $monthly_price,
                $annual_price,
                $order_fee_percent,
                $order_fee_flat,
                $max_staff_accounts,
                $includes_ai,
                $includes_priority,
                $includes_featured,
                $includes_branding,
                $is_active,
                $actor_user_id,
                $plan_id
            );
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO platform_subscription_plans
             (plan_code, plan_name, description, monthly_price, annual_price, included_order_fee_percent, included_order_fee_flat,
              max_staff_accounts, includes_ai_automation, includes_priority_support, includes_featured_placement, includes_custom_branding,
              is_active, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'sssddddiiiiiiii',
            $plan_code,
            $plan_name,
            $description,
            $monthly_price,
            $annual_price,
            $order_fee_percent,
            $order_fee_flat,
            $max_staff_accounts,
            $includes_ai,
            $includes_priority,
            $includes_featured,
            $includes_branding,
            $is_active,
            $actor_user_id,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $invoiceIdRow = $this->fetchOne(
                "SELECT id FROM partner_billing_invoices WHERE invoice_number = ? LIMIT 1",
                [$invoice_number],
                's'
            );
            if ($invoiceIdRow) {
                $this->sendInvoiceReminder((int)$invoiceIdRow['id'], 'invoice_issued', $actor_user_id, true);
            }
        }
        return $ok;
    }

    public function assignPartnerSubscription(array $payload, int $actor_user_id): bool
    {
        $partner_user_id = (int)($payload['partner_user_id'] ?? 0);
        $plan_id = (int)($payload['plan_id'] ?? 0);
        $billing_cycle = $this->enumValue($payload['billing_cycle'] ?? 'monthly', ['monthly', 'annual'], 'monthly');
        $subscription_status = $this->enumValue($payload['subscription_status'] ?? 'active', ['trial', 'active', 'past_due', 'paused', 'cancelled'], 'active');
        $price_override = trim((string)($payload['price_override'] ?? ''));
        $price_override_value = $price_override !== '' ? round((float)$price_override, 2) : null;
        $started_at = trim((string)($payload['started_at'] ?? ''));
        $renews_at = trim((string)($payload['renews_at'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));

        if ($partner_user_id <= 0 || $plan_id <= 0) {
            return false;
        }
        if (!$this->isApprovedPartner($partner_user_id)) {
            return false;
        }

        $started_at = $this->normalizeDate($started_at) ?: date('Y-m-d');
        $renews_at = $this->normalizeDate($renews_at) ?: date('Y-m-d', strtotime($billing_cycle === 'annual' ? '+1 year' : '+1 month'));

        $existing = $this->fetchOne(
            "SELECT id FROM partner_plan_subscriptions WHERE partner_user_id = ? LIMIT 1",
            [$partner_user_id],
            'i'
        );

        if ($existing) {
            $subscription_id = (int)$existing['id'];
            $stmt = $this->conn->prepare(
                "UPDATE partner_plan_subscriptions
                 SET plan_id = ?, billing_cycle = ?, subscription_status = ?, price_override = ?,
                     started_at = ?, renews_at = ?, notes = ?, ended_at = CASE WHEN ? = 'cancelled' THEN CURDATE() ELSE NULL END,
                     updated_by = ?, updated_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param(
                'issdsssisi',
                $plan_id,
                $billing_cycle,
                $subscription_status,
                $price_override_value,
                $started_at,
                $renews_at,
                $notes,
                $subscription_status,
                $actor_user_id,
                $subscription_id
            );
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO partner_plan_subscriptions
             (partner_user_id, plan_id, billing_cycle, subscription_status, price_override, started_at, renews_at, notes, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'iissdsssii',
            $partner_user_id,
            $plan_id,
            $billing_cycle,
            $subscription_status,
            $price_override_value,
            $started_at,
            $renews_at,
            $notes,
            $actor_user_id,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function activateApprovedPartnerTrial(int $partner_user_id, int $actor_user_id, string $preferred_plan_code = 'starter'): bool
    {
        $partner_user_id = (int)$partner_user_id;
        if ($partner_user_id <= 0 || !$this->isApprovedPartner($partner_user_id)) {
            return false;
        }

        $plan_id = $this->getPreferredTrialPlanId($preferred_plan_code);
        if ($plan_id <= 0) {
            return false;
        }

        $current = $this->fetchOne(
            "SELECT id, subscription_status FROM partner_plan_subscriptions WHERE partner_user_id = ? LIMIT 1",
            [$partner_user_id],
            'i'
        );
        $current_status = strtolower(trim((string)($current['subscription_status'] ?? '')));
        if (in_array($current_status, ['trial', 'active', 'past_due', 'paused'], true)) {
            return true;
        }

        return $this->assignPartnerSubscription([
            'partner_user_id' => $partner_user_id,
            'plan_id' => $plan_id,
            'billing_cycle' => 'monthly',
            'subscription_status' => 'trial',
            'started_at' => date('Y-m-d'),
            'renews_at' => date('Y-m-d', strtotime('+1 month')),
            'notes' => 'Automatic one-month platform trial after franchise approval.'
        ], $actor_user_id);
    }

    public function saveFeeRule(array $payload, int $actor_user_id): bool
    {
        $scope = $this->enumValue($payload['rule_scope'] ?? 'global', ['global', 'partner'], 'global');
        $partner_user_id = $scope === 'partner' ? (int)($payload['partner_user_id'] ?? 0) : null;
        $fee_percent = round((float)($payload['fee_percent'] ?? 0), 2);
        $fee_flat = round((float)($payload['fee_flat_per_order'] ?? 0), 2);
        $effective_from = $this->normalizeDate((string)($payload['effective_from'] ?? '')) ?: date('Y-m-d');
        $effective_to = $this->normalizeDate((string)($payload['effective_to'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $rule_name = substr(trim((string)($payload['rule_name'] ?? '')), 0, 150);
        if ($rule_name === '') {
            $rule_name = $scope === 'partner' ? 'Partner custom fee' : 'Default platform fee';
        }

        if ($scope === 'partner') {
            if (($partner_user_id ?? 0) <= 0 || !$this->isApprovedPartner((int)$partner_user_id)) {
                return false;
            }
        }

        $existing = null;
        if ($scope === 'global') {
            $existing = $this->fetchOne(
                "SELECT id FROM platform_fee_rules
                 WHERE rule_scope = 'global'
                 ORDER BY is_active DESC, effective_from DESC, id DESC
                 LIMIT 1",
                [],
                ''
            );
        } else {
            $existing = $this->fetchOne(
                "SELECT id FROM platform_fee_rules
                 WHERE rule_scope = 'partner' AND partner_user_id = ?
                 ORDER BY is_active DESC, effective_from DESC, id DESC
                 LIMIT 1",
                [$partner_user_id],
                'i'
            );
        }

        if ($existing) {
            $rule_id = (int)$existing['id'];
            $stmt = $this->conn->prepare(
                "UPDATE platform_fee_rules
                 SET partner_user_id = ?, rule_scope = ?, rule_name = ?, fee_percent = ?, fee_flat_per_order = ?,
                     effective_from = ?, effective_to = ?, is_active = 1, notes = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param(
                'issddsssii',
                $partner_user_id,
                $scope,
                $rule_name,
                $fee_percent,
                $fee_flat,
                $effective_from,
                $effective_to,
                $notes,
                $actor_user_id,
                $rule_id
            );
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO platform_fee_rules
             (partner_user_id, rule_scope, rule_name, fee_percent, fee_flat_per_order, effective_from, effective_to, is_active, notes, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'issddsssii',
            $partner_user_id,
            $scope,
            $rule_name,
            $fee_percent,
            $fee_flat,
            $effective_from,
            $effective_to,
            $notes,
            $actor_user_id,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getApprovedPartners(): array
    {
        if (!$this->tableExists('franchise_applications') || !$this->tableExists('users')) {
            return [];
        }

        return $this->rows(
            "SELECT fa.user_id AS partner_user_id,
                    COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                    u.full_name,
                    u.email,
                    u.is_active,
                    u.account_control_status
             FROM franchise_applications fa
             INNER JOIN users u ON u.id = fa.user_id
             INNER JOIN (
                 SELECT user_id, MAX(id) AS latest_id
                 FROM franchise_applications
                 GROUP BY user_id
             ) latest_fa ON latest_fa.latest_id = fa.id
             WHERE fa.status = 'approved'
             ORDER BY business_name ASC"
        );
    }

    public function getSubscriptions(): array
    {
        return $this->rowsIfTable(
            'partner_plan_subscriptions',
            "SELECT s.*, p.plan_name, p.plan_code, p.monthly_price, p.annual_price,
                    COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                    u.email
             FROM partner_plan_subscriptions s
             INNER JOIN platform_subscription_plans p ON p.id = s.plan_id
             INNER JOIN users u ON u.id = s.partner_user_id
             LEFT JOIN franchise_applications fa ON fa.user_id = s.partner_user_id AND fa.status = 'approved'
             ORDER BY FIELD(s.subscription_status, 'active','trial','past_due','paused','cancelled'), business_name ASC"
        );
    }

    public function getFeeRules(): array
    {
        return $this->rowsIfTable(
            'platform_fee_rules',
            "SELECT r.*,
                    COALESCE(fa.business_name, u.business_name, u.full_name, 'All Partners') AS business_name
             FROM platform_fee_rules r
             LEFT JOIN users u ON u.id = r.partner_user_id
             LEFT JOIN franchise_applications fa ON fa.user_id = r.partner_user_id AND fa.status = 'approved'
             ORDER BY FIELD(r.rule_scope, 'partner','global'), r.is_active DESC, r.updated_at DESC"
        );
    }

    public function getBillingInvoices(?int $partner_user_id = null, int $limit = 120): array
    {
        if (!$this->tableExists('partner_billing_invoices')) {
            return [];
        }

        $where = '';
        if ($partner_user_id !== null && $partner_user_id > 0) {
            $where = "WHERE i.partner_user_id = " . (int)$partner_user_id;
        }

        return $this->rows(
            "SELECT i.*,
                    COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                    u.email,
                    p.plan_name
             FROM partner_billing_invoices i
             INNER JOIN users u ON u.id = i.partner_user_id
             LEFT JOIN franchise_applications fa ON fa.user_id = i.partner_user_id AND fa.status = 'approved'
             LEFT JOIN partner_plan_subscriptions s ON s.id = i.subscription_id
             LEFT JOIN platform_subscription_plans p ON p.id = s.plan_id
             {$where}
             ORDER BY i.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getSubscriptionRequests(?int $partner_user_id = null, int $limit = 80): array
    {
        if (!$this->tableExists('partner_subscription_requests')) {
            return [];
        }

        $where = '';
        if ($partner_user_id !== null && $partner_user_id > 0) {
            $where = "WHERE r.partner_user_id = " . (int)$partner_user_id;
        }

        return $this->rows(
            "SELECT r.*,
                    COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                    u.email,
                    rp.plan_name AS requested_plan_name,
                    cp.plan_name AS current_plan_name,
                    reviewer.full_name AS reviewed_by_name
             FROM partner_subscription_requests r
             INNER JOIN users u ON u.id = r.partner_user_id
             LEFT JOIN franchise_applications fa ON fa.user_id = r.partner_user_id AND fa.status = 'approved'
             LEFT JOIN platform_subscription_plans rp ON rp.id = r.requested_plan_id
             LEFT JOIN partner_plan_subscriptions ps ON ps.id = r.current_subscription_id
             LEFT JOIN platform_subscription_plans cp ON cp.id = ps.plan_id
             LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
             {$where}
             ORDER BY FIELD(r.request_status, 'pending','approved','rejected','cancelled'), r.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function submitSubscriptionRequest(
        int $partner_user_id,
        int $requested_plan_id,
        string $billing_cycle,
        string $request_type,
        string $partner_notes,
        int $actor_user_id
    ): bool {
        if ($partner_user_id <= 0 || $requested_plan_id <= 0 || !$this->isApprovedPartner($partner_user_id)) {
            return false;
        }

        $plan = $this->fetchOne(
            "SELECT id, plan_name FROM platform_subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1",
            [$requested_plan_id],
            'i'
        );
        if (!$plan) {
            return false;
        }

        $billing_cycle = $this->enumValue($billing_cycle, ['monthly', 'annual'], 'monthly');
        $request_type = $this->enumValue($request_type, ['new', 'renew', 'upgrade', 'downgrade', 'change_plan'], 'new');
        $partner_notes = trim($partner_notes);

        $existingPending = $this->fetchOne(
            "SELECT id FROM partner_subscription_requests
             WHERE partner_user_id = ? AND request_status = 'pending'
             LIMIT 1",
            [$partner_user_id],
            'i'
        );
        if ($existingPending) {
            return false;
        }

        $currentSubscription = $this->fetchOne(
            "SELECT id FROM partner_plan_subscriptions WHERE partner_user_id = ? LIMIT 1",
            [$partner_user_id],
            'i'
        );
        $currentSubscriptionId = $currentSubscription ? (int)$currentSubscription['id'] : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO partner_subscription_requests
             (partner_user_id, current_subscription_id, requested_plan_id, requested_billing_cycle, request_type, request_status, partner_notes, review_notes,
              requested_by, reviewed_by, reviewed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, NULL, ?, NULL, NULL, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'iiisssi',
            $partner_user_id,
            $currentSubscriptionId,
            $requested_plan_id,
            $billing_cycle,
            $request_type,
            $partner_notes,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $requestId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();

        if ($ok) {
            $businessName = $this->getPartnerDisplayName($partner_user_id);
            $title = 'Subscription request submitted';
            $message = $businessName . ' requested the ' . (string)($plan['plan_name'] ?? 'selected') . ' plan (' . ucfirst($billing_cycle) . ').';
            foreach ($this->getSuperAdminUserIds() as $adminId) {
                $this->createInAppNotification($adminId, 'subscription_request', $title, $message, $requestId, 'partner_subscription_request');
            }
        }

        return $ok;
    }

    public function reviewSubscriptionRequest(int $request_id, string $decision, string $review_notes, int $actor_user_id): bool
    {
        if ($request_id <= 0 || !$this->tableExists('partner_subscription_requests')) {
            return false;
        }

        $decision = $this->enumValue($decision, ['approved', 'rejected', 'cancelled'], 'rejected');
        $review_notes = trim($review_notes);

        $request = $this->fetchOne(
            "SELECT * FROM partner_subscription_requests WHERE id = ? AND request_status = 'pending' LIMIT 1",
            [$request_id],
            'i'
        );
        if (!$request) {
            return false;
        }

        $partner_user_id = (int)($request['partner_user_id'] ?? 0);
        if ($decision === 'approved') {
            $applied = $this->assignPartnerSubscription([
                'partner_user_id' => $partner_user_id,
                'plan_id' => (int)($request['requested_plan_id'] ?? 0),
                'billing_cycle' => (string)($request['requested_billing_cycle'] ?? 'monthly'),
                'subscription_status' => 'active',
                'started_at' => date('Y-m-d'),
                'renews_at' => date('Y-m-d', strtotime(((string)($request['requested_billing_cycle'] ?? 'monthly') === 'annual') ? '+1 year' : '+1 month')),
                'notes' => $review_notes !== '' ? $review_notes : 'Subscription request approved by system owner.'
            ], $actor_user_id);
            if (!$applied) {
                return false;
            }
        }

        $stmt = $this->conn->prepare(
            "UPDATE partner_subscription_requests
             SET request_status = ?,
                 review_notes = ?,
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssii', $decision, $review_notes, $actor_user_id, $request_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $requestedPlanName = $this->getPlanNameById((int)($request['requested_plan_id'] ?? 0));
            $title = 'Subscription request ' . $decision;
            $message = 'Your request for the ' . $requestedPlanName . ' plan has been ' . $decision . '.';
            if ($review_notes !== '') {
                $message .= ' Notes: ' . $review_notes;
            }
            $this->createInAppNotification($partner_user_id, 'subscription_request_' . $decision, $title, $message, $request_id, 'partner_subscription_request');
            $this->sendSubscriptionRequestEmail($partner_user_id, $title, $message);
        }

        return $ok;
    }

    public function getInvoiceById(int $invoice_id, ?int $partner_user_id = null): ?array
    {
        if ($invoice_id <= 0 || !$this->tableExists('partner_billing_invoices')) {
            return null;
        }

        $where = "WHERE i.id = ?";
        $params = [$invoice_id];
        $types = 'i';
        if ($partner_user_id !== null && $partner_user_id > 0) {
            $where .= " AND i.partner_user_id = ?";
            $params[] = $partner_user_id;
            $types .= 'i';
        }

        return $this->fetchOne(
            "SELECT i.*,
                    COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                    u.full_name,
                    u.email,
                    u.phone,
                    p.plan_name,
                    s.billing_cycle,
                    s.subscription_status,
                    s.started_at AS subscription_started_at,
                    s.renews_at AS subscription_renews_at
             FROM partner_billing_invoices i
             INNER JOIN users u ON u.id = i.partner_user_id
             LEFT JOIN franchise_applications fa ON fa.user_id = i.partner_user_id AND fa.status = 'approved'
             LEFT JOIN partner_plan_subscriptions s ON s.id = i.subscription_id
             LEFT JOIN platform_subscription_plans p ON p.id = s.plan_id
             {$where}
             LIMIT 1",
            $params,
            $types
        );
    }

    public function getInvoicePaymentSessions(int $invoice_id, ?int $partner_user_id = null, int $limit = 12): array
    {
        if ($invoice_id <= 0 || !$this->tableExists('partner_invoice_payment_sessions')) {
            return [];
        }

        $where = "WHERE s.invoice_id = " . (int)$invoice_id;
        if ($partner_user_id !== null && $partner_user_id > 0) {
            $where .= " AND s.partner_user_id = " . (int)$partner_user_id;
        }

        return $this->rows(
            "SELECT s.*
             FROM partner_invoice_payment_sessions s
             {$where}
             ORDER BY s.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getInvoiceDocumentData(int $invoice_id, ?int $partner_user_id = null): array
    {
        $invoice = $this->getInvoiceById($invoice_id, $partner_user_id);
        if (!$invoice) {
            return [];
        }

        $lineItems = [];
        $json = trim((string)($invoice['line_items_json'] ?? ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lineItems[] = [
                        'label' => trim((string)($row['label'] ?? 'Line item')) ?: 'Line item',
                        'amount' => round((float)($row['amount'] ?? 0), 2)
                    ];
                }
            }
        }

        if ($lineItems === []) {
            $lineItems[] = ['label' => 'Subscription Fee', 'amount' => round((float)($invoice['subscription_amount'] ?? 0), 2)];
            $lineItems[] = ['label' => 'Platform Order Fees', 'amount' => round((float)($invoice['order_fee_amount'] ?? 0), 2)];
        }

        return [
            'invoice' => $invoice,
            'line_items' => $lineItems,
            'payment_sessions' => $this->getInvoicePaymentSessions($invoice_id, $partner_user_id, 8)
        ];
    }

    public function generateInvoiceForPartner(int $partner_user_id, int $actor_user_id, ?string $for_date = null): bool
    {
        if ($partner_user_id <= 0 || !$this->isApprovedPartner($partner_user_id)) {
            return false;
        }

        $billingDate = $this->normalizeDate((string)$for_date) ?: date('Y-m-d');
        $periodStart = date('Y-m-01', strtotime($billingDate));
        $periodEnd = date('Y-m-t', strtotime($billingDate));
        $existing = $this->fetchOne(
            "SELECT id FROM partner_billing_invoices
             WHERE partner_user_id = ? AND period_start = ? AND period_end = ? AND invoice_status <> 'void'
             LIMIT 1",
            [$partner_user_id, $periodStart, $periodEnd],
            'iss'
        );
        if ($existing) {
            return false;
        }

        $summary = $this->buildPartnerPeriodSummary($partner_user_id, $periodStart, $periodEnd);
        if ((float)($summary['total_amount'] ?? 0) <= 0) {
            return false;
        }
        $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(substr(md5((string)$partner_user_id . '|' . $periodStart . '|' . microtime(true)), 0, 6));
        $dueAt = date('Y-m-d 23:59:59', strtotime($billingDate . ' +7 days'));
        $lineItems = [
            [
                'label' => 'Subscription Fee',
                'amount' => round((float)$summary['subscription_amount'], 2)
            ],
            [
                'label' => 'Platform Order Fees',
                'amount' => round((float)$summary['order_fee_amount'], 2)
            ]
        ];

        $stmt = $this->conn->prepare(
            "INSERT INTO partner_billing_invoices
             (invoice_number, partner_user_id, subscription_id, invoice_type, period_start, period_end, subscription_amount, order_fee_amount,
              subtotal_amount, tax_amount, total_amount, currency_code, invoice_status, issued_at, due_at, paid_at, payment_reference,
              payment_channel, line_items_json, notes, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, 'combined', ?, ?, ?, ?, ?, 0.00, ?, 'PHP', 'issued', NOW(), ?, NULL, NULL, NULL, ?, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return false;
        }

        $line_items_json = json_encode($lineItems);
        $notes = 'Generated from subscription and current platform fee rules.';
        $stmt->bind_param(
            'siissddddsssii',
            $invoice_number,
            $partner_user_id,
            $summary['subscription_id'],
            $periodStart,
            $periodEnd,
            $summary['subscription_amount'],
            $summary['order_fee_amount'],
            $summary['subtotal_amount'],
            $summary['total_amount'],
            $dueAt,
            $line_items_json,
            $notes,
            $actor_user_id,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function autoGenerateMonthlyInvoices(int $actor_user_id = 0, ?string $run_date = null): array
    {
        $runDate = $this->normalizeDate((string)$run_date) ?: date('Y-m-d');
        $targetMonthStart = date('Y-m-01', strtotime($runDate . ' -1 month'));
        $partners = $this->getApprovedPartners();
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($partners as $partner) {
            $partnerId = (int)($partner['partner_user_id'] ?? 0);
            if ($partnerId <= 0) {
                continue;
            }

            $summary = $this->buildPartnerPeriodSummary($partnerId, $targetMonthStart, date('Y-m-t', strtotime($targetMonthStart)));
            if ((float)($summary['total_amount'] ?? 0) <= 0) {
                $skipped++;
                continue;
            }

            $ok = $this->generateInvoiceForPartner($partnerId, $actor_user_id, $targetMonthStart);
            if ($ok) {
                $generated++;
                $details[] = [
                    'partner_user_id' => $partnerId,
                    'business_name' => $partner['business_name'] ?? '',
                    'status' => 'generated'
                ];
                continue;
            }

            $existing = $this->fetchOne(
                "SELECT id, invoice_number
                 FROM partner_billing_invoices
                 WHERE partner_user_id = ? AND period_start = ? AND period_end = ?
                 LIMIT 1",
                [$partnerId, $targetMonthStart, date('Y-m-t', strtotime($targetMonthStart))],
                'iss'
            );
            if ($existing) {
                $skipped++;
                $details[] = [
                    'partner_user_id' => $partnerId,
                    'business_name' => $partner['business_name'] ?? '',
                    'status' => 'already_exists',
                    'invoice_number' => $existing['invoice_number'] ?? ''
                ];
            } else {
                $failed++;
                $details[] = [
                    'partner_user_id' => $partnerId,
                    'business_name' => $partner['business_name'] ?? '',
                    'status' => 'failed'
                ];
            }
        }

        return [
            'run_date' => $runDate,
            'target_month' => $targetMonthStart,
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'details' => $details
        ];
    }

    public function sendInvoiceReminder(int $invoice_id, string $reminder_type, int $actor_user_id = 0, bool $force = false): bool
    {
        $invoice = $this->getInvoiceById($invoice_id);
        if (!$invoice) {
            return false;
        }

        $reminder_type = $this->enumValue($reminder_type, ['invoice_issued', 'due_soon', 'overdue', 'manual'], 'manual');
        $partner_user_id = (int)($invoice['partner_user_id'] ?? 0);
        if ($partner_user_id <= 0) {
            return false;
        }

        if (!$force && $this->tableExists('partner_billing_notifications')) {
            $sentToday = $this->fetchOne(
                "SELECT id
                 FROM partner_billing_notifications
                 WHERE invoice_id = ? AND reminder_type = ? AND DATE(sent_at) = CURDATE()
                 LIMIT 1",
                [$invoice_id, $reminder_type],
                'is'
            );
            if ($sentToday) {
                return false;
            }
        }

        [$subject, $message] = $this->buildInvoiceReminderMessage($invoice, $reminder_type);
        $inAppOk = $this->createInAppNotification($partner_user_id, 'billing_' . $reminder_type, $subject, $message, $invoice_id, 'partner_invoice');
        $emailOk = $this->sendInvoiceReminderEmail($invoice, $subject, $message);

        if ($this->tableExists('partner_billing_notifications')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO partner_billing_notifications
                 (invoice_id, partner_user_id, reminder_type, delivery_channel, delivery_status, sent_to_email, subject, message, sent_by, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $channel = ($inAppOk && $emailOk) ? 'both' : ($emailOk ? 'email' : 'in_app');
                $status = ($inAppOk || $emailOk) ? (($inAppOk && $emailOk) ? 'sent' : 'partial') : 'failed';
                $sentTo = (string)($invoice['email'] ?? '');
                $stmt->bind_param(
                    'iissssssi',
                    $invoice_id,
                    $partner_user_id,
                    $reminder_type,
                    $channel,
                    $status,
                    $sentTo,
                    $subject,
                    $message,
                    $actor_user_id
                );
                $stmt->execute();
                $stmt->close();
            }
        }

        return $inAppOk || $emailOk;
    }

    public function sendAutomaticInvoiceReminders(int $actor_user_id = 0, ?string $run_date = null): array
    {
        $runDate = $this->normalizeDate((string)$run_date) ?: date('Y-m-d');
        $this->syncInvoiceAging();
        $reminded = 0;
        $skipped = 0;
        $targets = [];

        foreach ($this->getBillingInvoices(null, 300) as $invoice) {
            $invoiceId = (int)($invoice['id'] ?? 0);
            $status = strtolower(trim((string)($invoice['invoice_status'] ?? 'issued')));
            $dueAt = $this->normalizeDate((string)($invoice['due_at'] ?? ''));
            $reminderType = null;

            if ($status === 'overdue') {
                $reminderType = 'overdue';
            } elseif (in_array($status, ['issued', 'draft'], true) && $dueAt !== null) {
                $daysUntilDue = (int)floor((strtotime($dueAt) - strtotime($runDate)) / 86400);
                if ($daysUntilDue <= 3) {
                    $reminderType = 'due_soon';
                }
            }

            if ($reminderType === null) {
                $skipped++;
                continue;
            }

            if ($this->sendInvoiceReminder($invoiceId, $reminderType, $actor_user_id, false)) {
                $reminded++;
                $targets[] = [
                    'invoice_number' => $invoice['invoice_number'] ?? '',
                    'business_name' => $invoice['business_name'] ?? '',
                    'reminder_type' => $reminderType
                ];
            } else {
                $skipped++;
            }
        }

        return [
            'run_date' => $runDate,
            'reminded' => $reminded,
            'skipped' => $skipped,
            'targets' => $targets
        ];
    }

    public function updateInvoiceStatus(int $invoice_id, string $invoice_status, string $payment_reference, string $payment_channel, string $notes, int $actor_user_id): bool
    {
        if ($invoice_id <= 0 || !$this->tableExists('partner_billing_invoices')) {
            return false;
        }

        $invoice_status = $this->enumValue($invoice_status, ['draft', 'issued', 'paid', 'overdue', 'void'], 'issued');
        $payment_reference = substr(trim($payment_reference), 0, 120);
        $payment_channel = substr(trim($payment_channel), 0, 60);
        $notes = trim($notes);

        $stmt = $this->conn->prepare(
            "UPDATE partner_billing_invoices
             SET invoice_status = ?,
                 payment_reference = CASE WHEN ? <> '' THEN ? ELSE payment_reference END,
                 payment_channel = CASE WHEN ? <> '' THEN ? ELSE payment_channel END,
                 notes = CASE WHEN ? <> '' THEN ? ELSE notes END,
                 paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END,
                 issued_at = CASE WHEN ? = 'issued' AND issued_at IS NULL THEN NOW() ELSE issued_at END,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'sssssssssii',
            $invoice_status,
            $payment_reference,
            $payment_reference,
            $payment_channel,
            $payment_channel,
            $notes,
            $notes,
            $invoice_status,
            $invoice_status,
            $actor_user_id,
            $invoice_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function createInvoicePaymentSession(int $invoice_id, int $partner_user_id, string $base_url, string $payment_method = 'gcash', int $actor_user_id = 0): array
    {
        $invoice = $this->getInvoiceById($invoice_id, $partner_user_id);
        if (!$invoice) {
            return ['success' => false, 'message' => 'Invoice not found for this partner account.'];
        }

        $invoiceStatus = strtolower(trim((string)($invoice['invoice_status'] ?? 'issued')));
        if ($invoiceStatus === 'paid') {
            return ['success' => false, 'message' => 'This invoice is already paid.'];
        }
        if (!in_array($invoiceStatus, ['issued', 'overdue', 'draft'], true)) {
            return ['success' => false, 'message' => 'This invoice is not available for online payment.'];
        }

        $credentials = $this->getPayMongoCredentials();
        if ($credentials['secret'] === '' || $credentials['public'] === '') {
            return ['success' => false, 'message' => 'PayMongo credentials are not configured yet.'];
        }

        require_once dirname(__DIR__) . '/paymongo_integration.php';

        $checkout = new PayMongoIntegration($credentials['secret'], $credentials['public']);
        $paymentMethod = $this->normalizePaymentMethod($payment_method);
        $description = 'Platform Invoice ' . (string)($invoice['invoice_number'] ?? ('#' . $invoice_id));
        $successUrl = $this->buildAbsoluteUrl($base_url, 'billing_invoice_payment_success.php', ['invoice_id' => $invoice_id]);
        $cancelUrl = $this->buildAbsoluteUrl($base_url, 'billing_invoice_payment_cancel.php', ['invoice_id' => $invoice_id]);
        $result = $checkout->createCheckoutSession([
            'amount' => round((float)($invoice['total_amount'] ?? 0), 2),
            'description' => $description,
            'order_id' => 'invoice-' . $invoice_id,
            'customer_name' => (string)($invoice['business_name'] ?? $invoice['full_name'] ?? 'Partner Shop'),
            'customer_email' => (string)($invoice['email'] ?? ''),
            'customer_phone' => (string)($invoice['phone'] ?? ''),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_method' => $paymentMethod
        ]);

        if (empty($result['success']) || empty($result['session_id']) || empty($result['checkout_url'])) {
            return ['success' => false, 'message' => 'Unable to create the online payment session.', 'provider_error' => $result['error'] ?? null];
        }

        $payload = json_encode($result);
        $stmt = $this->conn->prepare(
            "INSERT INTO partner_invoice_payment_sessions
             (invoice_id, partner_user_id, provider, session_id, checkout_url, payment_status, amount, currency_code, payment_method, transaction_reference,
              provider_payload, paid_at, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, 'paymongo', ?, ?, 'pending', ?, 'PHP', ?, NULL, ?, NULL, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return ['success' => false, 'message' => 'Payment session was created but could not be saved locally.'];
        }

        $amount = round((float)($invoice['total_amount'] ?? 0), 2);
        $stmt->bind_param(
            'iissdssii',
            $invoice_id,
            $partner_user_id,
            $result['session_id'],
            $result['checkout_url'],
            $amount,
            $paymentMethod,
            $payload,
            $actor_user_id,
            $actor_user_id
        );
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return ['success' => false, 'message' => 'Unable to save the payment session locally.'];
        }

        return [
            'success' => true,
            'checkout_url' => $result['checkout_url'],
            'session_id' => $result['session_id']
        ];
    }

    public function completeInvoicePayment(int $invoice_id, int $partner_user_id, ?string $session_id = null, int $actor_user_id = 0): array
    {
        $invoice = $this->getInvoiceById($invoice_id, $partner_user_id);
        if (!$invoice) {
            return ['success' => false, 'message' => 'Invoice not found.'];
        }

        if ((string)($invoice['invoice_status'] ?? '') === 'paid') {
            return ['success' => true, 'message' => 'Invoice is already marked paid.', 'invoice_status' => 'paid'];
        }

        if (!$this->tableExists('partner_invoice_payment_sessions')) {
            return ['success' => false, 'message' => 'No payment session was found for this invoice yet.'];
        }

        $paymentSession = null;
        if ($session_id !== null && trim($session_id) !== '') {
            $paymentSession = $this->fetchOne(
                "SELECT *
                 FROM partner_invoice_payment_sessions
                 WHERE invoice_id = ? AND partner_user_id = ? AND session_id = ?
                 LIMIT 1",
                [$invoice_id, $partner_user_id, trim($session_id)],
                'iis'
            );
        }
        if (!$paymentSession) {
            $paymentSession = $this->fetchOne(
                "SELECT *
                 FROM partner_invoice_payment_sessions
                 WHERE invoice_id = ? AND partner_user_id = ?
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$invoice_id, $partner_user_id],
                'ii'
            );
        }
        if (!$paymentSession || empty($paymentSession['session_id'])) {
            return ['success' => false, 'message' => 'No payment session was found for this invoice yet.'];
        }

        $credentials = $this->getPayMongoCredentials();
        if ($credentials['secret'] === '' || $credentials['public'] === '') {
            return ['success' => false, 'message' => 'PayMongo credentials are not configured yet.'];
        }

        require_once dirname(__DIR__) . '/paymongo_integration.php';

        $checkout = new PayMongoIntegration($credentials['secret'], $credentials['public']);
        $verification = $checkout->retrieveCheckoutSession((string)$paymentSession['session_id']);
        if (empty($verification['success'])) {
            return ['success' => false, 'message' => 'Unable to verify the payment session yet.'];
        }

        $providerStatus = $this->normalizeRemotePaymentStatus((string)($verification['status'] ?? 'pending'));
        $sessionAttributes = $this->extractSessionPaymentAttributes($verification['session_data'] ?? []);
        $providerPayload = json_encode($verification);
        $paymentSessionId = (int)($paymentSession['id'] ?? 0);

        $stmt = $this->conn->prepare(
            "UPDATE partner_invoice_payment_sessions
             SET payment_status = ?,
                 transaction_reference = CASE WHEN ? <> '' THEN ? ELSE transaction_reference END,
                 provider_payload = ?,
                 paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $reference = $sessionAttributes['reference'];
            $stmt->bind_param(
                'sssssii',
                $providerStatus,
                $reference,
                $reference,
                $providerPayload,
                $providerStatus,
                $actor_user_id,
                $paymentSessionId
            );
            $stmt->execute();
            $stmt->close();
        }

        if ($providerStatus === 'paid') {
            $note = $this->appendInvoiceNote(
                (string)($invoice['notes'] ?? ''),
                'Paid online via PayMongo on ' . date('M d, Y h:i A') . '.'
            );
            $invoiceStmt = $this->conn->prepare(
                "UPDATE partner_billing_invoices
                 SET invoice_status = 'paid',
                     paid_at = NOW(),
                     payment_reference = CASE WHEN ? <> '' THEN ? ELSE payment_reference END,
                     payment_channel = CASE WHEN ? <> '' THEN ? ELSE 'paymongo' END,
                     notes = ?,
                     updated_by = ?,
                     updated_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            if ($invoiceStmt) {
                $reference = $sessionAttributes['reference'] !== '' ? $sessionAttributes['reference'] : (string)$paymentSession['session_id'];
                $channel = $sessionAttributes['channel'] !== '' ? $sessionAttributes['channel'] : 'paymongo';
                $invoiceStmt->bind_param(
                    'sssssii',
                    $reference,
                    $reference,
                    $channel,
                    $channel,
                    $note,
                    $actor_user_id,
                    $invoice_id
                );
                $invoiceStmt->execute();
                $invoiceStmt->close();
            }

            return ['success' => true, 'message' => 'Invoice payment confirmed successfully.', 'invoice_status' => 'paid'];
        }

        return [
            'success' => false,
            'message' => 'Payment is still ' . $providerStatus . '. Please try again once the payment is completed.',
            'invoice_status' => (string)($invoice['invoice_status'] ?? 'issued'),
            'payment_status' => $providerStatus
        ];
    }

    public function getDashboardData(): array
    {
        $plans = $this->getPlans();
        $subscriptions = $this->getSubscriptions();
        $invoices = $this->getBillingInvoices(null, 160);
        $partners = $this->getApprovedPartners();
        $monthlyPerformance = $this->getPartnerSalesForMonth(date('Y-m-01'), date('Y-m-t'));
        $feeMap = $this->getEffectiveFeeRuleMap();
        $subscriptionMap = [];
        $mrr = 0.0;
        $arr = 0.0;
        $activeSubscriberCount = 0;
        $actualCollected = 0.0;
        $outstandingInvoices = 0.0;
        $overdueInvoices = 0.0;

        foreach ($subscriptions as $subscription) {
            $partnerId = (int)($subscription['partner_user_id'] ?? 0);
            $subscriptionMap[$partnerId] = $subscription;
            if (!in_array((string)($subscription['subscription_status'] ?? ''), ['trial', 'active', 'past_due', 'paused'], true)) {
                continue;
            }
            $activeSubscriberCount++;
            $effectiveMonthly = $this->getSubscriptionMonthlyValue($subscription);
            $mrr += $effectiveMonthly;
            $arr += $effectiveMonthly * 12;
        }

        foreach ($invoices as $invoice) {
            $status = (string)($invoice['invoice_status'] ?? 'issued');
            $amount = (float)($invoice['total_amount'] ?? 0);
            if ($status === 'paid') {
                $actualCollected += $amount;
            } elseif (in_array($status, ['issued', 'draft'], true)) {
                $outstandingInvoices += $amount;
            } elseif ($status === 'overdue') {
                $overdueInvoices += $amount;
            }
        }

        $partnerRows = [];
        $estimatedOrderFeeTotal = 0.0;
        foreach ($partners as $partner) {
            $partnerId = (int)($partner['partner_user_id'] ?? 0);
            $sales = $monthlyPerformance[$partnerId] ?? ['order_count' => 0, 'gross_sales' => 0.0, 'last_order_at' => null];
            $subscription = $subscriptionMap[$partnerId] ?? null;
            $feeRule = $feeMap[$partnerId] ?? $feeMap[0] ?? ['fee_percent' => 0.0, 'fee_flat_per_order' => 0.0];
            $orderFeeRevenue = round(
                ((float)$sales['gross_sales'] * ((float)$feeRule['fee_percent'] / 100)) +
                ((int)$sales['order_count'] * (float)$feeRule['fee_flat_per_order']),
                2
            );
            $estimatedOrderFeeTotal += $orderFeeRevenue;
            $subscriptionMonthly = $subscription ? $this->getSubscriptionMonthlyValue($subscription) : 0.0;

            $partnerRows[] = [
                'partner_user_id' => $partnerId,
                'business_name' => $partner['business_name'] ?? '',
                'email' => $partner['email'] ?? '',
                'subscription_plan' => $subscription['plan_name'] ?? 'Unassigned',
                'subscription_status' => $subscription['subscription_status'] ?? 'unassigned',
                'billing_cycle' => $subscription['billing_cycle'] ?? 'monthly',
                'monthly_subscription_revenue' => $subscriptionMonthly,
                'monthly_order_count' => (int)$sales['order_count'],
                'monthly_gross_sales' => (float)$sales['gross_sales'],
                'estimated_order_fee_revenue' => $orderFeeRevenue,
                'projected_platform_revenue' => $subscriptionMonthly + $orderFeeRevenue,
                'fee_percent' => (float)($feeRule['fee_percent'] ?? 0),
                'fee_flat_per_order' => (float)($feeRule['fee_flat_per_order'] ?? 0),
                'last_order_at' => $sales['last_order_at'] ?? null
            ];
        }

        usort($partnerRows, static function ($left, $right) {
            return ((float)$right['projected_platform_revenue']) <=> ((float)$left['projected_platform_revenue']);
        });

        $unassignedPartners = 0;
        foreach ($partnerRows as $row) {
            if (($row['subscription_plan'] ?? 'Unassigned') === 'Unassigned') {
                $unassignedPartners++;
            }
        }

        return [
            'metrics' => [
                'approved_partners' => count($partners),
                'active_subscribers' => $activeSubscriberCount,
                'unassigned_partners' => $unassignedPartners,
                'mrr' => round($mrr, 2),
                'arr' => round($arr, 2),
                'estimated_order_fee_month' => round($estimatedOrderFeeTotal, 2),
                'projected_platform_revenue_month' => round($mrr + $estimatedOrderFeeTotal, 2),
                'average_platform_revenue_per_partner' => count($partners) > 0 ? round(($mrr + $estimatedOrderFeeTotal) / count($partners), 2) : 0.0,
                'actual_collected_revenue' => round($actualCollected, 2),
                'outstanding_invoice_total' => round($outstandingInvoices, 2),
                'overdue_invoice_total' => round($overdueInvoices, 2)
            ],
            'plans' => $plans,
            'subscriptions' => $subscriptions,
            'invoices' => $invoices,
            'fee_rules' => $this->getFeeRules(),
            'partner_rows' => $partnerRows,
            'monthly_series' => $this->getMonthlyRevenueSeries()
        ];
    }

    public function getPartnerBillingPortalData(int $partner_user_id): array
    {
        $partners = $this->getApprovedPartners();
        $partnerProfile = null;
        foreach ($partners as $partner) {
            if ((int)($partner['partner_user_id'] ?? 0) === $partner_user_id) {
                $partnerProfile = $partner;
                break;
            }
        }
        if (!$partnerProfile) {
            return [];
        }

        $subscription = null;
        foreach ($this->getSubscriptions() as $row) {
            if ((int)($row['partner_user_id'] ?? 0) === $partner_user_id) {
                $subscription = $row;
                break;
            }
        }

        $feeMap = $this->getEffectiveFeeRuleMap();
        $feeRule = $feeMap[$partner_user_id] ?? $feeMap[0] ?? ['fee_percent' => 0.0, 'fee_flat_per_order' => 0.0];
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $monthSummary = $this->buildPartnerPeriodSummary($partner_user_id, $monthStart, $monthEnd);
        $invoices = $this->getBillingInvoices($partner_user_id, 60);
        $paidTotal = 0.0;
        $dueTotal = 0.0;
        $overdueTotal = 0.0;

        foreach ($invoices as $invoice) {
            $status = (string)($invoice['invoice_status'] ?? 'issued');
            $amount = (float)($invoice['total_amount'] ?? 0);
            if ($status === 'paid') {
                $paidTotal += $amount;
            } elseif (in_array($status, ['issued', 'draft'], true)) {
                $dueTotal += $amount;
            } elseif ($status === 'overdue') {
                $overdueTotal += $amount;
            }
        }

        return [
            'partner' => $partnerProfile,
            'subscription' => $subscription,
            'fee_rule' => $feeRule,
            'month_summary' => $monthSummary,
            'invoices' => $invoices,
            'reminders' => $this->getPartnerBillingNotifications($partner_user_id, 12),
            'timeline' => $this->getPartnerBillingTimeline($partner_user_id, 30),
            'available_plans' => array_values(array_filter($this->getPlans(), static function ($plan) {
                return (int)($plan['is_active'] ?? 0) === 1;
            })),
            'subscription_requests' => $this->getSubscriptionRequests($partner_user_id, 20),
            'metrics' => [
                'paid_total' => round($paidTotal, 2),
                'due_total' => round($dueTotal, 2),
                'overdue_total' => round($overdueTotal, 2)
            ]
        ];
    }

    public function getPartnerBillingNotifications(int $partner_user_id, int $limit = 20): array
    {
        if ($partner_user_id <= 0 || !$this->tableExists('partner_billing_notifications')) {
            return [];
        }

        return $this->rows(
            "SELECT n.*,
                    i.invoice_number,
                    i.invoice_status,
                    i.due_at,
                    i.total_amount
             FROM partner_billing_notifications n
             LEFT JOIN partner_billing_invoices i ON i.id = n.invoice_id
             WHERE n.partner_user_id = " . (int)$partner_user_id . "
             ORDER BY n.sent_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getPartnerBillingTimeline(int $partner_user_id, int $limit = 30): array
    {
        if ($partner_user_id <= 0) {
            return [];
        }

        $timeline = [];
        $subscription = $this->fetchOne(
            "SELECT s.*, p.plan_name
             FROM partner_plan_subscriptions s
             LEFT JOIN platform_subscription_plans p ON p.id = s.plan_id
             WHERE s.partner_user_id = ?
             LIMIT 1",
            [$partner_user_id],
            'i'
        );

        if ($subscription) {
            if (!empty($subscription['started_at'])) {
                $timeline[] = [
                    'event_at' => (string)$subscription['started_at'],
                    'event_type' => 'subscription_started',
                    'title' => 'Subscription started',
                    'detail' => 'Your shop started the ' . ((string)($subscription['plan_name'] ?? 'platform')) . ' plan (' . ucfirst((string)($subscription['billing_cycle'] ?? 'monthly')) . ').',
                    'status' => (string)($subscription['subscription_status'] ?? 'active'),
                    'amount' => null,
                    'invoice_number' => null
                ];
            }
            if (!empty($subscription['renews_at']) && in_array((string)($subscription['subscription_status'] ?? ''), ['trial', 'active', 'past_due'], true)) {
                $timeline[] = [
                    'event_at' => (string)$subscription['renews_at'],
                    'event_type' => 'subscription_renewal',
                    'title' => 'Next renewal date',
                    'detail' => 'The current ' . ucfirst((string)($subscription['billing_cycle'] ?? 'monthly')) . ' subscription is scheduled to renew on this date.',
                    'status' => (string)($subscription['subscription_status'] ?? 'active'),
                    'amount' => null,
                    'invoice_number' => null
                ];
            }
        }

        foreach ($this->getBillingInvoices($partner_user_id, max($limit, 20)) as $invoice) {
            $invoiceNumber = (string)($invoice['invoice_number'] ?? 'Invoice');
            $invoiceStatus = (string)($invoice['invoice_status'] ?? 'issued');
            $invoiceTotal = round((float)($invoice['total_amount'] ?? 0), 2);

            $timeline[] = [
                'event_at' => (string)($invoice['issued_at'] ?: $invoice['created_at'] ?? ''),
                'event_type' => 'invoice_issued',
                'title' => $invoiceNumber . ' issued',
                'detail' => 'A billing invoice was issued for your subscription and platform charges.',
                'status' => $invoiceStatus,
                'amount' => $invoiceTotal,
                'invoice_number' => $invoiceNumber
            ];

            if (!empty($invoice['paid_at'])) {
                $timeline[] = [
                    'event_at' => (string)$invoice['paid_at'],
                    'event_type' => 'invoice_paid',
                    'title' => $invoiceNumber . ' paid',
                    'detail' => 'Payment was confirmed through ' . ((string)($invoice['payment_channel'] ?? '') !== '' ? (string)$invoice['payment_channel'] : 'your selected payment channel') . '.',
                    'status' => 'paid',
                    'amount' => $invoiceTotal,
                    'invoice_number' => $invoiceNumber
                ];
            } elseif ($invoiceStatus === 'overdue') {
                $timeline[] = [
                    'event_at' => (string)($invoice['due_at'] ?: $invoice['updated_at'] ?? ''),
                    'event_type' => 'invoice_overdue',
                    'title' => $invoiceNumber . ' became overdue',
                    'detail' => 'The invoice due date passed without a completed payment.',
                    'status' => 'overdue',
                    'amount' => $invoiceTotal,
                    'invoice_number' => $invoiceNumber
                ];
            }
        }

        if ($this->tableExists('partner_billing_notifications')) {
            foreach ($this->getPartnerBillingNotifications($partner_user_id, max($limit, 15)) as $notification) {
                $reminderType = (string)($notification['reminder_type'] ?? 'manual');
                $invoiceNumber = trim((string)($notification['invoice_number'] ?? ''));
                $reminderLabel = match ($reminderType) {
                    'invoice_issued' => 'Invoice issued reminder',
                    'due_soon' => 'Due soon reminder',
                    'overdue' => 'Overdue reminder',
                    default => 'Billing reminder',
                };
                $timeline[] = [
                    'event_at' => (string)($notification['sent_at'] ?? ''),
                    'event_type' => 'reminder_' . $reminderType,
                    'title' => $reminderLabel,
                    'detail' => trim($invoiceNumber !== '' ? ($invoiceNumber . ' was included in this reminder.') : 'A billing reminder was sent to your shop.'),
                    'status' => (string)($notification['delivery_status'] ?? 'sent'),
                    'amount' => isset($notification['total_amount']) ? round((float)$notification['total_amount'], 2) : null,
                    'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null
                ];
            }
        }

        if ($this->tableExists('partner_invoice_payment_sessions')) {
            foreach ($this->rows(
                "SELECT s.*, i.invoice_number
                 FROM partner_invoice_payment_sessions s
                 LEFT JOIN partner_billing_invoices i ON i.id = s.invoice_id
                 WHERE s.partner_user_id = " . (int)$partner_user_id . "
                 ORDER BY s.created_at DESC
                 LIMIT " . max(1, (int)$limit)
            ) as $session) {
                $paymentStatus = (string)($session['payment_status'] ?? 'pending');
                $title = match ($paymentStatus) {
                    'paid' => 'PayMongo payment completed',
                    'failed' => 'PayMongo payment failed',
                    'cancelled' => 'PayMongo payment cancelled',
                    'expired' => 'PayMongo checkout expired',
                    default => 'PayMongo checkout started',
                };
                $timeline[] = [
                    'event_at' => (string)(($paymentStatus === 'paid' && !empty($session['paid_at'])) ? $session['paid_at'] : ($session['updated_at'] ?? $session['created_at'] ?? '')),
                    'event_type' => 'payment_' . $paymentStatus,
                    'title' => $title,
                    'detail' => 'Invoice ' . ((string)($session['invoice_number'] ?? '')) . ' used ' . strtoupper((string)($session['payment_method'] ?? 'paymongo')) . '.',
                    'status' => $paymentStatus,
                    'amount' => round((float)($session['amount'] ?? 0), 2),
                    'invoice_number' => !empty($session['invoice_number']) ? (string)$session['invoice_number'] : null
                ];
            }
        }

        $timeline = array_values(array_filter($timeline, static function (array $row): bool {
            return trim((string)($row['event_at'] ?? '')) !== '';
        }));

        usort($timeline, static function (array $left, array $right): int {
            $leftTime = strtotime((string)($left['event_at'] ?? ''));
            $rightTime = strtotime((string)($right['event_at'] ?? ''));
            return $rightTime <=> $leftTime;
        });

        return array_slice($timeline, 0, max(1, (int)$limit));
    }

    private function seedDefaultPlans(int $actor_user_id): void
    {
        if (!$this->tableExists('platform_subscription_plans')) {
            return;
        }

        $defaults = [
            ['starter', 'Starter', 'Basic storefront, chat support, and order presence for small shops.', 1499.00, 14990.00, 7.50, 5.00, 2, 0, 0, 0, 0],
            ['growth', 'Growth', 'Adds AI support automation, more staff access, and better visibility tools.', 3499.00, 34990.00, 6.00, 3.00, 6, 1, 1, 0, 0],
            ['pro', 'Pro', 'Best for high-volume stores with priority handling and stronger branding.', 6999.00, 69990.00, 4.50, 2.00, 15, 1, 1, 1, 1]
        ];

        foreach ($defaults as $plan) {
            $exists = $this->fetchOne(
                "SELECT id FROM platform_subscription_plans WHERE plan_code = ? LIMIT 1",
                [$plan[0]],
                's'
            );
            if ($exists) {
                continue;
            }

            $stmt = $this->conn->prepare(
                "INSERT INTO platform_subscription_plans
                 (plan_code, plan_name, description, monthly_price, annual_price, included_order_fee_percent, included_order_fee_flat,
                  max_staff_accounts, includes_ai_automation, includes_priority_support, includes_featured_placement, includes_custom_branding,
                  is_active, created_by, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())"
            );
            if ($stmt) {
                $stmt->bind_param(
                    'sssddddiiiiiii',
                    $plan[0],
                    $plan[1],
                    $plan[2],
                    $plan[3],
                    $plan[4],
                    $plan[5],
                    $plan[6],
                    $plan[7],
                    $plan[8],
                    $plan[9],
                    $plan[10],
                    $plan[11],
                    $actor_user_id,
                    $actor_user_id
                );
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function getPreferredTrialPlanId(string $preferred_plan_code = 'starter'): int
    {
        $preferred_plan_code = strtolower(trim($preferred_plan_code));
        if ($preferred_plan_code !== '') {
            $preferred = $this->fetchOne(
                "SELECT id
                 FROM platform_subscription_plans
                 WHERE plan_code = ? AND is_active = 1
                 LIMIT 1",
                [$preferred_plan_code],
                's'
            );
            if ($preferred) {
                return (int)($preferred['id'] ?? 0);
            }
        }

        $fallback = $this->fetchOne(
            "SELECT id
             FROM platform_subscription_plans
             WHERE is_active = 1
             ORDER BY monthly_price ASC, id ASC
             LIMIT 1",
            [],
            ''
        );

        return (int)($fallback['id'] ?? 0);
    }

    private function seedDefaultFeeRule(int $actor_user_id): void
    {
        if (!$this->tableExists('platform_fee_rules')) {
            return;
        }

        $exists = $this->fetchOne(
            "SELECT id FROM platform_fee_rules WHERE rule_scope = 'global' LIMIT 1",
            [],
            ''
        );
        if ($exists) {
            return;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO platform_fee_rules
             (partner_user_id, rule_scope, rule_name, fee_percent, fee_flat_per_order, effective_from, effective_to, is_active, notes, created_by, updated_by, created_at, updated_at)
             VALUES (NULL, 'global', 'Default platform fee', 6.00, 2.00, CURDATE(), NULL, 1, 'Default marketplace fee for all approved partners.', ?, ?, NOW(), NOW())"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $actor_user_id, $actor_user_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function getPartnerSalesForMonth(string $startDate, string $endDate): array
    {
        $hasOrderItems = $this->tableExists('order_items');
        $hasOrders = $this->tableExists('orders');
        $hasProducts = $this->tableExists('products');
        $hasProductsSellerId = $hasProducts && $this->columnExists('products', 'seller_id');
        $hasProductsId = $hasProducts && $this->columnExists('products', 'id');
        $hasProductsProductId = $hasProducts && $this->columnExists('products', 'product_id');
        $hasOrdersArchived = $hasOrders && $this->columnExists('orders', 'is_archived');
        $map = [];

        if ($hasOrderItems && $hasOrders && $hasProductsSellerId && $hasProductsId) {
            $joinOn = "oi.product_id = CAST(p.id AS CHAR)";
            if ($hasProductsProductId) {
                $joinOn .= " OR (p.product_id IS NOT NULL AND p.product_id <> '' AND oi.product_id = p.product_id)";
            }
            $archivedClause = $hasOrdersArchived ? "AND o.is_archived = 0" : "";
            $rows = $this->rows(
                "SELECT p.seller_id AS partner_user_id,
                        COUNT(DISTINCT oi.order_id) AS order_count,
                        COALESCE(SUM(oi.total), 0) AS gross_sales,
                        MAX(o.created_at) AS last_order_at
                 FROM order_items oi
                 INNER JOIN products p ON ({$joinOn})
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE p.seller_id IS NOT NULL
                   AND DATE(o.created_at) BETWEEN '" . $this->esc($startDate) . "' AND '" . $this->esc($endDate) . "'
                   AND LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'failed')
                   {$archivedClause}
                 GROUP BY p.seller_id"
            );
            foreach ($rows as $row) {
                $map[(int)$row['partner_user_id']] = [
                    'order_count' => (int)($row['order_count'] ?? 0),
                    'gross_sales' => (float)($row['gross_sales'] ?? 0),
                    'last_order_at' => $row['last_order_at'] ?? null
                ];
            }
            return $map;
        }

        if ($hasOrders) {
            $archivedClause = $hasOrdersArchived ? "AND o.is_archived = 0" : "";
            $rows = $this->rows(
                "SELECT o.user_id AS partner_user_id,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(o.total_amount), 0) AS gross_sales,
                        MAX(o.created_at) AS last_order_at
                 FROM orders o
                 WHERE DATE(o.created_at) BETWEEN '" . $this->esc($startDate) . "' AND '" . $this->esc($endDate) . "'
                   AND LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'failed')
                   {$archivedClause}
                 GROUP BY o.user_id"
            );
            foreach ($rows as $row) {
                $map[(int)$row['partner_user_id']] = [
                    'order_count' => (int)($row['order_count'] ?? 0),
                    'gross_sales' => (float)($row['gross_sales'] ?? 0),
                    'last_order_at' => $row['last_order_at'] ?? null
                ];
            }
        }

        return $map;
    }

    private function getMonthlyRevenueSeries(): array
    {
        $labels = [];
        $orderFees = [];
        $subscriptionBase = [];
        $currentMrr = 0.0;
        foreach ($this->getSubscriptions() as $subscription) {
            if (in_array((string)($subscription['subscription_status'] ?? ''), ['trial', 'active', 'past_due', 'paused'], true)) {
                $currentMrr += $this->getSubscriptionMonthlyValue($subscription);
            }
        }

        for ($i = 5; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-{$i} month"));
            $end = date('Y-m-t', strtotime($start));
            $labels[] = date('M Y', strtotime($start));
            $performance = $this->getPartnerSalesForMonth($start, $end);
            $feeMap = $this->getEffectiveFeeRuleMap($end);
            $monthFeeTotal = 0.0;
            foreach ($performance as $partnerId => $row) {
                $feeRule = $feeMap[$partnerId] ?? $feeMap[0] ?? ['fee_percent' => 0.0, 'fee_flat_per_order' => 0.0];
                $monthFeeTotal += ((float)$row['gross_sales'] * ((float)$feeRule['fee_percent'] / 100)) + ((int)$row['order_count'] * (float)$feeRule['fee_flat_per_order']);
            }
            $orderFees[] = round($monthFeeTotal, 2);
            $subscriptionBase[] = round($currentMrr, 2);
        }

        return [
            'labels' => $labels,
            'order_fee_revenue' => $orderFees,
            'subscription_revenue' => $subscriptionBase
        ];
    }

    private function buildPartnerPeriodSummary(int $partner_user_id, string $period_start, string $period_end): array
    {
        $salesMap = $this->getPartnerSalesForMonth($period_start, $period_end);
        $sales = $salesMap[$partner_user_id] ?? ['order_count' => 0, 'gross_sales' => 0.0, 'last_order_at' => null];
        $subscription = null;
        foreach ($this->getSubscriptions() as $row) {
            if ((int)($row['partner_user_id'] ?? 0) === $partner_user_id) {
                $subscription = $row;
                break;
            }
        }

        $feeMap = $this->getEffectiveFeeRuleMap($period_end);
        $feeRule = $feeMap[$partner_user_id] ?? $feeMap[0] ?? ['fee_percent' => 0.0, 'fee_flat_per_order' => 0.0];
        $subscriptionAmount = 0.0;
        $subscriptionId = null;
        $startedAt = $subscription ? $this->normalizeDate((string)($subscription['started_at'] ?? '')) : null;
        $endedAt = $subscription ? $this->normalizeDate((string)($subscription['ended_at'] ?? '')) : null;
        $isActiveInPeriod = (!$startedAt || $startedAt <= $period_end) && (!$endedAt || $endedAt >= $period_start);
        if ($subscription && $isActiveInPeriod && in_array((string)($subscription['subscription_status'] ?? ''), ['trial', 'active', 'past_due', 'paused'], true)) {
            $subscriptionId = (int)($subscription['id'] ?? 0);
            $billingCycle = (string)($subscription['billing_cycle'] ?? 'monthly');
            $billingAmount = ($subscription['price_override'] ?? null) !== null && $subscription['price_override'] !== ''
                ? (float)$subscription['price_override']
                : ($billingCycle === 'annual' ? (float)($subscription['annual_price'] ?? 0) : (float)($subscription['monthly_price'] ?? 0));
            if ($billingCycle === 'annual') {
                $renewsAt = $this->normalizeDate((string)($subscription['renews_at'] ?? ''));
                $chargeDate = $renewsAt ?: $startedAt;
                if ($chargeDate !== null && $chargeDate >= $period_start && $chargeDate <= $period_end) {
                    $subscriptionAmount = $billingAmount;
                }
            } else {
                $subscriptionAmount = $billingAmount;
            }
        }

        $orderFeeAmount = round(
            ((float)$sales['gross_sales'] * ((float)$feeRule['fee_percent'] / 100)) +
            ((int)$sales['order_count'] * (float)$feeRule['fee_flat_per_order']),
            2
        );
        $subtotal = round($subscriptionAmount + $orderFeeAmount, 2);

        return [
            'subscription_id' => $subscriptionId,
            'subscription_amount' => round($subscriptionAmount, 2),
            'order_fee_amount' => $orderFeeAmount,
            'subtotal_amount' => $subtotal,
            'total_amount' => $subtotal,
            'order_count' => (int)($sales['order_count'] ?? 0),
            'gross_sales' => (float)($sales['gross_sales'] ?? 0),
            'last_order_at' => $sales['last_order_at'] ?? null
        ];
    }

    private function syncInvoiceAging(): void
    {
        if (!$this->tableExists('partner_billing_invoices')) {
            return;
        }
        $this->conn->query(
            "UPDATE partner_billing_invoices
             SET invoice_status = 'overdue', updated_at = NOW()
             WHERE invoice_status IN ('draft','issued')
               AND due_at IS NOT NULL
               AND due_at < NOW()"
        );
    }

    private function getEffectiveFeeRuleMap(?string $forDate = null): array
    {
        $date = $this->normalizeDate((string)$forDate) ?: date('Y-m-d');
        $rows = $this->rowsIfTable(
            'platform_fee_rules',
            "SELECT *
             FROM platform_fee_rules
             WHERE is_active = 1
               AND effective_from <= '" . $this->esc($date) . "'
               AND (effective_to IS NULL OR effective_to = '0000-00-00' OR effective_to >= '" . $this->esc($date) . "')
             ORDER BY FIELD(rule_scope, 'partner','global'), updated_at DESC, id DESC"
        );

        $map = [];
        foreach ($rows as $row) {
            $key = (string)($row['rule_scope'] ?? '') === 'partner'
                ? (int)($row['partner_user_id'] ?? 0)
                : 0;
            if (!array_key_exists($key, $map)) {
                $map[$key] = [
                    'fee_percent' => (float)($row['fee_percent'] ?? 0),
                    'fee_flat_per_order' => (float)($row['fee_flat_per_order'] ?? 0)
                ];
            }
        }
        return $map;
    }

    private function getSubscriptionMonthlyValue(array $subscription): float
    {
        $override = $subscription['price_override'] ?? null;
        if ($override !== null && $override !== '') {
            return (float)$override;
        }

        $cycle = (string)($subscription['billing_cycle'] ?? 'monthly');
        if ($cycle === 'annual') {
            return round(((float)($subscription['annual_price'] ?? 0)) / 12, 2);
        }

        return (float)($subscription['monthly_price'] ?? 0);
    }

    private function buildInvoiceReminderMessage(array $invoice, string $reminder_type): array
    {
        $invoiceNumber = (string)($invoice['invoice_number'] ?? ('#' . (int)($invoice['id'] ?? 0)));
        $businessName = (string)($invoice['business_name'] ?? 'Partner Shop');
        $amount = 'PHP ' . number_format((float)($invoice['total_amount'] ?? 0), 2);
        $dueText = !empty($invoice['due_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['due_at'])) : 'the current billing deadline';

        if ($reminder_type === 'overdue') {
            return [
                'Overdue invoice reminder',
                "Your billing invoice {$invoiceNumber} for {$businessName} is overdue. Outstanding amount: {$amount}. Please settle it as soon as possible."
            ];
        }
        if ($reminder_type === 'due_soon') {
            return [
                'Invoice due soon',
                "Your billing invoice {$invoiceNumber} is due on {$dueText}. Outstanding amount: {$amount}. Please complete payment before the deadline to keep your subscription in good standing."
            ];
        }
        if ($reminder_type === 'invoice_issued') {
            return [
                'New billing invoice issued',
                "A new billing invoice {$invoiceNumber} has been issued for {$businessName}. Total due: {$amount}. Please review it in your partner billing page."
            ];
        }

        return [
            'Billing reminder',
            "This is a billing reminder for invoice {$invoiceNumber}. Outstanding amount: {$amount}. Please review the invoice in your partner billing page."
        ];
    }

    private function sendInvoiceReminderEmail(array $invoice, string $subject, string $message): bool
    {
        $email = trim((string)($invoice['email'] ?? ''));
        if ($email === '') {
            return false;
        }
        if (!class_exists('EmailService')) {
            $emailPath = dirname(__DIR__) . '/email_service.php';
            if (is_file($emailPath)) {
                require_once $emailPath;
            }
        }
        if (!class_exists('EmailService')) {
            return false;
        }

        try {
            $service = new EmailService($this->conn);
            return $service->sendPartnerBillingReminderEmail($email, [
                'business_name' => (string)($invoice['business_name'] ?? 'Partner Shop'),
                'invoice_number' => (string)($invoice['invoice_number'] ?? ''),
                'total_amount' => (float)($invoice['total_amount'] ?? 0),
                'due_at' => (string)($invoice['due_at'] ?? ''),
                'invoice_status' => (string)($invoice['invoice_status'] ?? 'issued'),
                'subject' => $subject,
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            error_log('Partner billing reminder email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function sendSubscriptionRequestEmail(int $partner_user_id, string $subject, string $message): bool
    {
        $partner = $this->fetchOne("SELECT email FROM users WHERE id = ? LIMIT 1", [$partner_user_id], 'i');
        if (!$partner || empty($partner['email'])) {
            return false;
        }
        if (!class_exists('EmailService')) {
            $emailPath = dirname(__DIR__) . '/email_service.php';
            if (is_file($emailPath)) {
                require_once $emailPath;
            }
        }
        if (!class_exists('EmailService')) {
            return false;
        }
        try {
            $service = new EmailService($this->conn);
            return $service->sendPartnerSubscriptionUpdateEmail((string)$partner['email'], $subject, $message);
        } catch (\Throwable $e) {
            error_log('Partner subscription email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function createInAppNotification(int $user_id, string $type, string $title, string $message, ?int $related_id = null, ?string $related_type = null): bool
    {
        if ($user_id <= 0 || !function_exists('createNotification')) {
            return false;
        }
        return createNotification($this->conn, $user_id, $type, $title, $message, $related_id, $related_type);
    }

    private function getSuperAdminUserIds(): array
    {
        if (!$this->tableExists('users')) {
            return [];
        }
        $conditions = [];
        if ($this->columnExists('users', 'role_name')) {
            $conditions[] = "LOWER(COALESCE(role_name, '')) = 'super_admin'";
        }
        if ($this->columnExists('users', 'user_type')) {
            $conditions[] = "LOWER(COALESCE(user_type, '')) = 'super_admin'";
        }
        if ($this->tableExists('user_roles') && $this->tableExists('roles')) {
            $conditions[] = "id IN (
                SELECT ur.user_id
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE LOWER(r.role_name) = 'super_admin'
            )";
        }
        if ($conditions === []) {
            return [];
        }
        $rows = $this->rows("SELECT id FROM users WHERE " . implode(' OR ', $conditions));
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)($row['id'] ?? 0);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function getPartnerDisplayName(int $partner_user_id): string
    {
        $row = $this->fetchOne(
            "SELECT COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name
             FROM users u
             LEFT JOIN franchise_applications fa ON fa.user_id = u.id AND fa.status = 'approved'
             WHERE u.id = ?
             LIMIT 1",
            [$partner_user_id],
            'i'
        );
        return trim((string)($row['business_name'] ?? 'Partner Shop')) ?: 'Partner Shop';
    }

    private function getPlanNameById(int $plan_id): string
    {
        if ($plan_id <= 0) {
            return 'selected';
        }
        $row = $this->fetchOne("SELECT plan_name FROM platform_subscription_plans WHERE id = ? LIMIT 1", [$plan_id], 'i');
        return trim((string)($row['plan_name'] ?? 'selected')) ?: 'selected';
    }

    private function getPayMongoCredentials(): array
    {
        $secret = trim((string)(getenv('PAYMONGO_SECRET_KEY') ?: ''));
        $public = trim((string)(getenv('PAYMONGO_PUBLIC_KEY') ?: ''));

        if ($secret === '') {
            $secret = 'sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE';
        }
        if ($public === '') {
            $public = 'pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE';
        }

        return ['secret' => $secret, 'public' => $public];
    }

    public function getPayMongoStatusSummary(): array
    {
        $envSecret = trim((string)(getenv('PAYMONGO_SECRET_KEY') ?: ''));
        $envPublic = trim((string)(getenv('PAYMONGO_PUBLIC_KEY') ?: ''));
        $credentials = $this->getPayMongoCredentials();
        $secret = trim((string)($credentials['secret'] ?? ''));
        $public = trim((string)($credentials['public'] ?? ''));

        $hasCredentials = $secret !== '' && $public !== '';
        $usingFallbackTestKeys = $envSecret === '' || $envPublic === '';
        $mode = 'unavailable';
        if ($hasCredentials) {
            $mode = (strpos($secret, 'sk_live_') === 0 || strpos($public, 'pk_live_') === 0) ? 'live' : 'sandbox';
        }

        return [
            'available' => $hasCredentials,
            'mode' => $mode,
            'using_fallback_test_keys' => $usingFallbackTestKeys,
            'channels' => ['gcash', 'paymaya', 'card']
        ];
    }

    private function buildAbsoluteUrl(string $base_url, string $path, array $query = []): string
    {
        $base_url = rtrim(trim($base_url), '/');
        $url = $base_url . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function normalizePaymentMethod(string $payment_method): string
    {
        $payment_method = strtolower(trim($payment_method));
        if (!in_array($payment_method, ['gcash', 'paymaya', 'card'], true)) {
            return 'gcash';
        }
        return $payment_method;
    }

    private function normalizeRemotePaymentStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['paid', 'failed', 'cancelled', 'expired'], true)) {
            return $status;
        }
        return 'pending';
    }

    private function extractSessionPaymentAttributes(array $sessionData): array
    {
        $payments = $sessionData['attributes']['payments'] ?? [];
        $firstPayment = is_array($payments) && isset($payments[0]['attributes']) && is_array($payments[0]['attributes'])
            ? $payments[0]['attributes']
            : [];

        $reference = trim((string)($firstPayment['id'] ?? $sessionData['id'] ?? ''));
        $channel = trim((string)($firstPayment['source']['type'] ?? $firstPayment['payment_method_used'] ?? 'paymongo'));
        return [
            'reference' => $reference,
            'channel' => $channel
        ];
    }

    private function appendInvoiceNote(string $existing, string $note): string
    {
        $existing = trim($existing);
        $note = trim($note);
        if ($note === '') {
            return $existing;
        }
        if ($existing === '') {
            return $note;
        }
        return $existing . "\n" . $note;
    }

    private function isApprovedPartner(int $userId): bool
    {
        if ($userId <= 0 || !$this->tableExists('franchise_applications')) {
            return false;
        }

        $row = $this->fetchOne(
            "SELECT id FROM franchise_applications WHERE user_id = ? AND status = 'approved' LIMIT 1",
            [$userId],
            'i'
        );
        return $row !== null;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $safeTable = $this->esc($tableName);
        $safeColumn = $this->esc($columnName);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function tableExists(string $tableName): bool
    {
        $safeTable = $this->esc($tableName);
        $result = $this->conn->query("SHOW TABLES LIKE '{$safeTable}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function rowsIfTable(string $tableName, string $query): array
    {
        if (!$this->tableExists($tableName)) {
            return [];
        }
        return $this->rows($query);
    }

    private function rows(string $query): array
    {
        $result = $this->conn->query($query);
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    private function fetchOne(string $query, array $params, string $types): ?array
    {
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    private function enumValue($value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d', $timestamp);
    }

    private function esc(string $value): string
    {
        return $this->conn->real_escape_string($value);
    }
}
