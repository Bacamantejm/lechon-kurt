<?php

class OperationalManagerService
{
    private $conn;
    private $owner_scope_user_id = 0;
    private $scoped_user_ids = null;

    public function __construct(mysqli $conn, int $owner_scope_user_id = 0)
    {
        $this->conn = $conn;
        $this->owner_scope_user_id = max(0, (int)$owner_scope_user_id);
    }

    public function ensureReady(int $actor_user_id = 0): void
    {
        $this->ensureSchema();
        $this->ensureRolesAndPermissions();
        $this->seedDefaultRules();
        $this->syncOperationalAlerts($actor_user_id);
    }

    public function ensureSchema(): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS operational_incidents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                incident_code VARCHAR(40) NOT NULL,
                category ENUM('system','security','business','user','content','data') NOT NULL DEFAULT 'system',
                severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                title VARCHAR(180) NOT NULL,
                description TEXT NULL,
                source_module VARCHAR(80) NULL,
                status ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
                assigned_to INT NULL,
                detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                created_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_operational_incidents_status (status, severity),
                KEY idx_operational_incidents_assigned (assigned_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_type VARCHAR(60) NOT NULL,
                alert_key VARCHAR(120) NOT NULL,
                severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                title VARCHAR(180) NOT NULL,
                message TEXT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id INT NULL,
                is_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
                acknowledged_by INT NULL,
                acknowledged_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_operational_alert_key (alert_key),
                KEY idx_operational_alerts_ack (is_acknowledged, severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_metric_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                snapshot_date DATE NOT NULL,
                snapshot_hour TINYINT NOT NULL DEFAULT 0,
                active_users INT NOT NULL DEFAULT 0,
                transactions_count INT NOT NULL DEFAULT 0,
                gross_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                open_complaints INT NOT NULL DEFAULT 0,
                pending_businesses INT NOT NULL DEFAULT 0,
                system_errors INT NOT NULL DEFAULT 0,
                failed_logins INT NOT NULL DEFAULT 0,
                api_latency_ms DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_operational_snapshot (snapshot_date, snapshot_hour)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_content_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content_type VARCHAR(50) NOT NULL,
                content_id INT NULL,
                submitted_by INT NULL,
                shop_id INT NULL,
                review_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                risk_score TINYINT NOT NULL DEFAULT 0,
                flag_reason VARCHAR(255) NULL,
                review_notes TEXT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_operational_content_queue_status (review_status, risk_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_watchlist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type ENUM('user','business','ip','device') NOT NULL DEFAULT 'user',
                entity_id INT NULL,
                reason VARCHAR(255) NOT NULL,
                risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                watch_status ENUM('active','cleared') NOT NULL DEFAULT 'active',
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_operational_watchlist_entity (entity_type, watch_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rule_name VARCHAR(120) NOT NULL,
                rule_type ENUM('alert','automation','moderation','security') NOT NULL DEFAULT 'alert',
                conditions_json LONGTEXT NULL,
                actions_json LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_run_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_operational_rule_name (rule_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(120) NOT NULL,
                job_type VARCHAR(60) NOT NULL,
                status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
                payload_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                error_message TEXT NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_operational_jobs_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_announcements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                audience_type ENUM('all','users','businesses','staff') NOT NULL DEFAULT 'all',
                title VARCHAR(180) NOT NULL,
                message TEXT NOT NULL,
                delivery_channel VARCHAR(30) NOT NULL DEFAULT 'in_app',
                status ENUM('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
                scheduled_at DATETIME NULL,
                sent_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS operational_backup_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                backup_type VARCHAR(40) NOT NULL,
                file_name VARCHAR(180) NULL,
                storage_path VARCHAR(255) NULL,
                backup_status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
                file_size BIGINT NOT NULL DEFAULT 0,
                checksum VARCHAR(120) NULL,
                notes TEXT NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_operational_backup_status (backup_status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($queries as $query) {
            $this->conn->query($query);
        }

        $this->ensureOwnerScopeColumns();
    }

    public function ensureRolesAndPermissions(): void
    {
        if (!$this->tableExists('roles') || !$this->tableExists('permissions') || !$this->tableExists('role_permissions')) {
            return;
        }

        $roles = [
            'operational_manager' => ['Operational Manager', 85],
            'operations_staff' => ['Operations Staff', 70]
        ];
        foreach ($roles as $role_slug => $role_meta) {
            $existing = $this->fetchOne("SELECT id FROM roles WHERE name = ? LIMIT 1", [$role_slug], 's');
            if ($existing) {
                continue;
            }
            $stmt = $this->conn->prepare(
                "INSERT INTO roles (name, description, is_active, level, department_id, owner_user_id, created_at, updated_at)
                 VALUES (?, ?, 1, ?, NULL, NULL, NOW(), NOW())"
            );
            if ($stmt) {
                $description = $role_meta[0];
                $level = (int)$role_meta[1];
                $stmt->bind_param('ssi', $role_slug, $description, $level);
                $stmt->execute();
                $stmt->close();
            }
        }

        $permissions = [
            ['operations.view', 'operations', 'view', 'View operations dashboard'],
            ['operations.incidents', 'operations', 'incidents', 'Manage incidents and alerts'],
            ['operations.monitoring', 'operations', 'monitoring', 'View monitoring signals'],
            ['operations.users_business', 'operations', 'users_business', 'Review users and businesses'],
            ['operations.content', 'operations', 'content', 'Moderate content queue'],
            ['operations.decision_support', 'operations', 'decision_support', 'View decision support insights'],
            ['operations.notifications', 'operations', 'notifications', 'Manage announcements and notices'],
            ['operations.automation', 'operations', 'automation', 'Manage automation rules and jobs'],
            ['operations.logs', 'operations', 'logs', 'Review audit, logs, and backups']
        ];

        $role_ids = [];
        foreach (['operational_manager', 'operations_staff'] as $role_name) {
            $role = $this->fetchOne("SELECT id FROM roles WHERE name = ? LIMIT 1", [$role_name], 's');
            if ($role) {
                $role_ids[$role_name] = (int)$role['id'];
            }
        }

        foreach ($permissions as $permission_meta) {
            $permission = $this->fetchOne("SELECT id FROM permissions WHERE name = ? LIMIT 1", [$permission_meta[0]], 's');
            $permission_id = 0;
            if ($permission) {
                $permission_id = (int)$permission['id'];
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO permissions (name, module, action, description, created_at)
                     VALUES (?, ?, ?, ?, NOW())"
                );
                if ($stmt) {
                    [$name, $module, $action, $description] = $permission_meta;
                    $stmt->bind_param('ssss', $name, $module, $action, $description);
                    $stmt->execute();
                    $permission_id = (int)$this->conn->insert_id;
                    $stmt->close();
                }
            }

            if ($permission_id <= 0) {
                continue;
            }

            foreach ($role_ids as $role_name => $role_id) {
                if ($role_name === 'operations_staff' && in_array($permission_meta[0], ['operations.automation', 'operations.logs'], true)) {
                    continue;
                }
                $existing_link = $this->fetchOne(
                    "SELECT role_id FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1",
                    [$role_id, $permission_id],
                    'ii'
                );
                if (!$existing_link) {
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
    }

    public function getOverviewMetrics(): array
    {
        $active_users_query = "SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        if ($this->isTenantScoped()) {
            $active_users_query .= " AND " . $this->scopedUserWhereSql('id');
        }

        $orders_scope_sql = $this->orderScopeCondition('orders');
        $chat_scope_sql = $this->chatConversationScopeCondition('chat_conversations');
        $franchise_scope_sql = $this->franchiseScopeCondition('franchise_applications');

        return [
            'active_users_24h' => $this->scalarIfTable("users", $active_users_query, 0),
            'transactions_today' => $this->scalarIfTable("orders", "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE(){$orders_scope_sql}", 0),
            'gross_revenue_today' => $this->scalarIfTable("orders", "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status NOT IN ('cancelled'){$orders_scope_sql}", 0),
            'open_complaints' => $this->scalarIfTable("chat_conversations", "SELECT COUNT(*) FROM chat_conversations WHERE (conversation_type = 'complaint' OR subject LIKE '%complaint%') AND status IN ('open','in_progress'){$chat_scope_sql}", 0),
            'pending_businesses' => $this->scalarIfTable("franchise_applications", "SELECT COUNT(*) FROM franchise_applications WHERE status = 'pending'{$franchise_scope_sql}", 0),
            'critical_alerts' => $this->scalarIfTable("operational_alerts", "SELECT COUNT(*) FROM operational_alerts WHERE is_acknowledged = 0 AND severity = 'critical'" . $this->ownerScopeCondition('operational_alerts'), 0),
            'open_incidents' => $this->scalarIfTable("operational_incidents", "SELECT COUNT(*) FROM operational_incidents WHERE status IN ('open','investigating')" . $this->ownerScopeCondition('operational_incidents'), 0),
            'pending_content' => $this->scalarIfTable("operational_content_queue", "SELECT COUNT(*) FROM operational_content_queue WHERE review_status = 'pending'" . $this->ownerScopeCondition('operational_content_queue'), 0),
            'running_jobs' => $this->scalarIfTable("operational_jobs", "SELECT COUNT(*) FROM operational_jobs WHERE status IN ('queued','running')" . $this->ownerScopeCondition('operational_jobs'), 0),
            'suspicious_events_24h' => $this->countSuspiciousEvents()
        ];
    }

    public function getAlerts(int $limit = 8): array
    {
        return $this->rowsIfTable(
            "operational_alerts",
            "SELECT a.*, u.full_name AS acknowledged_name
             FROM operational_alerts a
             LEFT JOIN users u ON u.id = a.acknowledged_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_alerts', 'a') . "
             ORDER BY a.is_acknowledged ASC, FIELD(a.severity, 'critical','high','medium','low'), a.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getIncidents(int $limit = 12): array
    {
        return $this->rowsIfTable(
            "operational_incidents",
            "SELECT i.*, u.full_name AS assigned_name, c.full_name AS created_name
             FROM operational_incidents i
             LEFT JOIN users u ON u.id = i.assigned_to
             LEFT JOIN users c ON c.id = i.created_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_incidents', 'i') . "
             ORDER BY FIELD(i.status, 'open','investigating','resolved','closed'),
                      FIELD(i.severity, 'critical','high','medium','low'),
                      i.detected_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getContentQueue(int $limit = 12): array
    {
        return $this->rowsIfTable(
            "operational_content_queue",
            "SELECT q.*, u.full_name AS submitted_name, r.full_name AS reviewed_name
             FROM operational_content_queue q
             LEFT JOIN users u ON u.id = q.submitted_by
             LEFT JOIN users r ON r.id = q.reviewed_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_content_queue', 'q') . "
             ORDER BY FIELD(q.review_status, 'pending','rejected','approved'), q.risk_score DESC, q.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getJobs(int $limit = 10): array
    {
        return $this->rowsIfTable(
            "operational_jobs",
            "SELECT j.*, u.full_name AS created_name
             FROM operational_jobs j
             LEFT JOIN users u ON u.id = j.created_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_jobs', 'j') . "
             ORDER BY FIELD(j.status, 'running','queued','failed','completed'), j.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getAnnouncements(int $limit = 10): array
    {
        return $this->rowsIfTable(
            "operational_announcements",
            "SELECT a.*, u.full_name AS created_name
             FROM operational_announcements a
             LEFT JOIN users u ON u.id = a.created_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_announcements', 'a') . "
             ORDER BY a.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getBackupLog(int $limit = 12): array
    {
        return $this->rowsIfTable(
            "operational_backup_log",
            "SELECT b.*, u.full_name AS created_name
             FROM operational_backup_log b
             LEFT JOIN users u ON u.id = b.created_by
             WHERE 1=1" . $this->ownerScopeCondition('operational_backup_log', 'b') . "
             ORDER BY b.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getMetricSnapshots(int $limit = 24): array
    {
        return $this->rowsIfTable(
            "operational_metric_snapshots",
            "SELECT *
             FROM operational_metric_snapshots
             WHERE 1=1" . $this->ownerScopeCondition('operational_metric_snapshots') . "
             ORDER BY snapshot_date DESC, snapshot_hour DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getTrendSeries(int $limit = 12): array
    {
        $snapshots = $this->rowsIfTable(
            "operational_metric_snapshots",
            "SELECT *
             FROM operational_metric_snapshots
             WHERE 1=1" . $this->ownerScopeCondition('operational_metric_snapshots') . "
             ORDER BY snapshot_date DESC, snapshot_hour DESC
             LIMIT " . max(1, (int)max(1, $limit - 1))
        );
        $snapshots = array_reverse($snapshots);
        $current = $this->getOverviewMetrics();
        $labels = [];
        $activity_users = [];
        $activity_transactions = [];
        $activity_complaints = [];
        $workload_pending = [];
        $workload_alerts = [];
        $workload_suspicious = [];

        foreach ($snapshots as $snapshot) {
            $labels[] = date('M d', strtotime((string)$snapshot['snapshot_date'])) . ' ' . str_pad((string)((int)$snapshot['snapshot_hour']), 2, '0', STR_PAD_LEFT) . ':00';
            $activity_users[] = (int)($snapshot['active_users'] ?? 0);
            $activity_transactions[] = (int)($snapshot['transactions_count'] ?? 0);
            $activity_complaints[] = (int)($snapshot['open_complaints'] ?? 0);
            $workload_pending[] = (int)($snapshot['pending_businesses'] ?? 0);
            $workload_alerts[] = (int)($snapshot['system_errors'] ?? 0);
            $workload_suspicious[] = (int)($snapshot['failed_logins'] ?? 0);
        }

        $labels[] = 'Now';
        $activity_users[] = (int)$current['active_users_24h'];
        $activity_transactions[] = (int)$current['transactions_today'];
        $activity_complaints[] = (int)$current['open_complaints'];
        $workload_pending[] = (int)$current['pending_businesses'];
        $workload_alerts[] = (int)$current['critical_alerts'];
        $workload_suspicious[] = (int)$current['suspicious_events_24h'];

        return [
            'labels' => $labels,
            'activity' => [
                'active_users' => $activity_users,
                'transactions' => $activity_transactions,
                'complaints' => $activity_complaints
            ],
            'workload' => [
                'pending_businesses' => $workload_pending,
                'critical_alerts' => $workload_alerts,
                'suspicious_events' => $workload_suspicious
            ]
        ];
    }

    public function getDashboardPayload(): array
    {
        $overview = $this->getOverviewMetrics();
        $decision = $this->getDecisionSupportSummary();
        $alerts = $this->getAlerts(6);
        $incidents = $this->getIncidents(6);
        $jobs = $this->getJobs(6);
        $announcements = $this->getAnnouncements(6);
        $team_summary = $this->getOperationalRoleSummary();

        return [
            'overview' => $overview,
            'alerts' => $alerts,
            'incidents' => $incidents,
            'decision' => $decision,
            'jobs' => $jobs,
            'announcements' => $announcements,
            'team_summary' => $team_summary,
            'charts' => $this->getTrendSeries(12),
            'generated_at' => date('c')
        ];
    }

    public function getOperationalRoles(): array
    {
        if (!$this->tableExists('roles')) {
            return [];
        }

        return $this->rows(
            "SELECT id, name, description, level
             FROM roles
             WHERE is_active = 1
               AND name IN ('operational_manager', 'operations_staff')
             ORDER BY FIELD(name, 'operational_manager', 'operations_staff')"
        );
    }

    public function getOperationalRoleSummary(): array
    {
        if (!$this->tableExists('users') || !$this->tableExists('roles')) {
            return [
                'operational_manager' => 0,
                'operations_staff' => 0,
                'total_assigned' => 0
            ];
        }

        return [
            'operational_manager' => (int)$this->scalar(
                "SELECT COUNT(*)
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.name = 'operational_manager' AND r.is_active = 1
                   AND " . $this->scopedUserWhereSql('u.id'),
                0
            ),
            'operations_staff' => (int)$this->scalar(
                "SELECT COUNT(*)
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.name = 'operations_staff' AND r.is_active = 1
                   AND " . $this->scopedUserWhereSql('u.id'),
                0
            ),
            'total_assigned' => (int)$this->scalar(
                "SELECT COUNT(*)
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.name IN ('operational_manager', 'operations_staff') AND r.is_active = 1
                   AND " . $this->scopedUserWhereSql('u.id'),
                0
            )
        ];
    }

    public function getOperationalTeam(int $limit = 100): array
    {
        if (!$this->tableExists('users') || !$this->tableExists('roles')) {
            return [];
        }

        return $this->rows(
            "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, u.last_login, u.created_at,
                    r.name AS role_name, r.description AS role_description
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.name IN ('operational_manager', 'operations_staff') AND r.is_active = 1
               AND " . $this->scopedUserWhereSql('u.id') . "
             ORDER BY FIELD(r.name, 'operational_manager', 'operations_staff'), u.full_name ASC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getOperationalRoleCandidates(int $limit = 250): array
    {
        if (!$this->tableExists('users') || !$this->tableExists('roles')) {
            return [];
        }

        return $this->rows(
            "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, u.last_login, u.created_at,
                    r.name AS role_name, r.description AS role_description
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE COALESCE(r.name, '') <> 'super_admin'
               AND " . $this->scopedUserWhereSql('u.id') . "
             ORDER BY
                 CASE
                     WHEN r.name IN ('operational_manager', 'operations_staff') THEN 0
                     WHEN u.is_active = 1 THEN 1
                     ELSE 2
                 END,
                 u.full_name ASC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function assignOperationalRoleToUser(int $target_user_id, string $role_name): bool
    {
        if ($target_user_id <= 0 || !$this->tableExists('users') || !$this->tableExists('roles')) {
            return false;
        }

        $role_name = $this->enumValue($role_name, ['operational_manager', 'operations_staff'], '');
        if ($role_name === '') {
            return false;
        }

        $target_user = $this->fetchOne(
            "SELECT u.id, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1",
            [$target_user_id],
            'i'
        );
        if (!$target_user || ($target_user['role_name'] ?? '') === 'super_admin') {
            return false;
        }
        if (!$this->isUserInScope($target_user_id)) {
            return false;
        }

        $target_role = $this->fetchOne(
            "SELECT id
             FROM roles
             WHERE name = ? AND is_active = 1
             LIMIT 1",
            [$role_name],
            's'
        );
        if (!$target_role) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE users SET role_id = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $role_id = (int)$target_role['id'];
        $stmt->bind_param('ii', $role_id, $target_user_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function clearOperationalRoleFromUser(int $target_user_id): bool
    {
        if ($target_user_id <= 0 || !$this->tableExists('users') || !$this->tableExists('roles')) {
            return false;
        }

        $target_user = $this->fetchOne(
            "SELECT u.id, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1",
            [$target_user_id],
            'i'
        );
        if (!$target_user || !in_array((string)($target_user['role_name'] ?? ''), ['operational_manager', 'operations_staff'], true)) {
            return false;
        }
        if (!$this->isUserInScope($target_user_id)) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE users SET role_id = NULL WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $target_user_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getUserBusinessInsights(): array
    {
        $total_users_query = "SELECT COUNT(*) FROM users";
        if ($this->isTenantScoped()) {
            $total_users_query .= " WHERE " . $this->scopedUserWhereSql('id');
        }

        $active_businesses_query = "SELECT COUNT(DISTINCT user_id) FROM franchise_applications WHERE status = 'approved'";
        $pending_businesses_query = "SELECT COUNT(*) FROM franchise_applications WHERE status = 'pending'";
        if ($this->isTenantScoped()) {
            $active_businesses_query .= " AND user_id = " . (int)$this->owner_scope_user_id;
            $pending_businesses_query .= " AND user_id = " . (int)$this->owner_scope_user_id;
        }

        $metrics = [
            'total_users' => $this->scalarIfTable("users", $total_users_query, 0),
            'active_businesses' => $this->scalarIfTable("franchise_applications", $active_businesses_query, 0),
            'pending_businesses' => $this->scalarIfTable("franchise_applications", $pending_businesses_query, 0),
            'watchlist_entries' => $this->scalarIfTable("operational_watchlist", "SELECT COUNT(*) FROM operational_watchlist WHERE watch_status = 'active'" . $this->ownerScopeCondition('operational_watchlist'), 0)
        ];

        $business_risk = [];
        if ($this->tableExists('franchise_applications') && $this->tableExists('users')) {
            $warning_join = $this->tableExists('partner_warnings')
                ? "(SELECT partner_user_id, COUNT(*) AS warning_count FROM partner_warnings WHERE warning_status = 'active' GROUP BY partner_user_id) pw"
                : "(SELECT 0 AS partner_user_id, 0 AS warning_count) pw";
            $business_risk = $this->rows(
                "SELECT fa.user_id,
                        COALESCE(fa.business_name, u.business_name, u.full_name) AS business_name,
                        u.email,
                        COALESCE(pw.warning_count, 0) AS active_warnings,
                        u.account_control_status
                 FROM franchise_applications fa
                 INNER JOIN users u ON u.id = fa.user_id
                 LEFT JOIN {$warning_join} ON pw.partner_user_id = fa.user_id
                 WHERE fa.status = 'approved'
                   " . ($this->isTenantScoped() ? "AND fa.user_id = " . (int)$this->owner_scope_user_id : "") . "
                 ORDER BY active_warnings DESC, fa.updated_at DESC
                 LIMIT 10"
            );
        }

        $watchlist = $this->rowsIfTable(
            "operational_watchlist",
            "SELECT w.*, u.full_name, u.email
             FROM operational_watchlist w
             LEFT JOIN users u ON u.id = w.entity_id AND w.entity_type = 'user'
             WHERE w.watch_status = 'active'
             " . $this->ownerScopeCondition('operational_watchlist', 'w') . "
             ORDER BY FIELD(w.risk_level, 'critical','high','medium','low'), w.created_at DESC
             LIMIT 10"
        );

        return [
            'metrics' => $metrics,
            'business_risk' => $business_risk,
            'watchlist' => $watchlist
        ];
    }

    public function getDecisionSupportSummary(): array
    {
        $metrics = $this->getOverviewMetrics();
        $recommendations = [];

        if ($metrics['pending_businesses'] >= 5) {
            $recommendations[] = 'Business approvals are building up. Review pending partner applications before they age past 24 hours.';
        }
        if ($metrics['open_complaints'] >= 10) {
            $recommendations[] = 'Complaint backlog is elevated. Consider assigning a dedicated resolver for high-priority cases.';
        }
        if ($metrics['suspicious_events_24h'] >= 8) {
            $recommendations[] = 'Suspicious access activity is above normal. Review recent authentication events and tighten watchlist entries.';
        }
        if ($metrics['pending_content'] >= 5) {
            $recommendations[] = 'Content moderation queue is growing. Review flagged listings before trust signals degrade.';
        }
        if (empty($recommendations)) {
            $recommendations[] = 'Operational signals are within a stable range. Capture a new snapshot and review trend movement for planning.';
        }

        return [
            'metrics' => $metrics,
            'snapshots' => $this->getMetricSnapshots(14),
            'recommendations' => $recommendations
        ];
    }

    public function createIncident(array $payload, int $user_id): bool
    {
        $title = substr(trim((string)($payload['title'] ?? '')), 0, 180);
        if ($title === '') {
            return false;
        }

        $category = $this->enumValue($payload['category'] ?? 'system', ['system','security','business','user','content','data'], 'system');
        $severity = $this->enumValue($payload['severity'] ?? 'medium', ['low','medium','high','critical'], 'medium');
        $description = trim((string)($payload['description'] ?? ''));
        $source_module = substr(trim((string)($payload['source_module'] ?? 'operations_dashboard')), 0, 80);
        $incident_code = 'OPS-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)$user_id, true)), 0, 6));

        $owner_scope_id = $this->resolveWriteOwnerScope($user_id);
        if ($this->hasOwnerScopeColumn('operational_incidents')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_incidents
                 (incident_code, category, severity, title, description, source_module, status, assigned_to, detected_at, created_by, owner_user_id, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'open', NULL, NOW(), ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssssii', $incident_code, $category, $severity, $title, $description, $source_module, $user_id, $owner_scope_id);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_incidents
                 (incident_code, category, severity, title, description, source_module, status, assigned_to, detected_at, created_by, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'open', NULL, NOW(), ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssssi', $incident_code, $category, $severity, $title, $description, $source_module, $user_id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function updateIncidentStatus(int $incident_id, string $status, int $user_id, string $note = ''): bool
    {
        $status = $this->enumValue($status, ['open','investigating','resolved','closed'], 'open');
        $note = trim($note);
        $resolved_at_sql = in_array($status, ['resolved', 'closed'], true) ? 'NOW()' : 'NULL';
        $scope_sql = $this->ownerScopeCondition('operational_incidents');
        $stmt = $this->conn->prepare(
            "UPDATE operational_incidents
             SET status = ?, resolved_at = {$resolved_at_sql}, updated_at = NOW(),
                 description = CASE WHEN ? <> '' THEN CONCAT(COALESCE(description, ''), '\n\nOps note: ', ?) ELSE description END,
                 assigned_to = COALESCE(assigned_to, ?)
             WHERE id = ?{$scope_sql}
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssii', $status, $note, $note, $user_id, $incident_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function acknowledgeAlert(int $alert_id, int $user_id): bool
    {
        $scope_sql = $this->ownerScopeCondition('operational_alerts');
        $stmt = $this->conn->prepare(
            "UPDATE operational_alerts
             SET is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW(), updated_at = NOW()
             WHERE id = ?{$scope_sql}
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $user_id, $alert_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function queueContentItem(array $payload, int $user_id): bool
    {
        $content_type = substr(trim((string)($payload['content_type'] ?? 'listing')), 0, 50);
        $flag_reason = substr(trim((string)($payload['flag_reason'] ?? 'Manual review requested')), 0, 255);
        $risk_score = max(0, min(100, (int)($payload['risk_score'] ?? 50)));
        $content_id = (int)($payload['content_id'] ?? 0);
        $shop_id = (int)($payload['shop_id'] ?? 0);
        $owner_scope_id = $this->resolveWriteOwnerScope($user_id);

        if ($this->hasOwnerScopeColumn('operational_content_queue')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_content_queue
                 (content_type, content_id, submitted_by, shop_id, review_status, risk_score, flag_reason, owner_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('siiiisi', $content_type, $content_id, $user_id, $shop_id, $risk_score, $flag_reason, $owner_scope_id);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_content_queue
                 (content_type, content_id, submitted_by, shop_id, review_status, risk_score, flag_reason, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('siiiis', $content_type, $content_id, $user_id, $shop_id, $risk_score, $flag_reason);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function reviewContentItem(int $queue_id, string $status, string $review_notes, int $user_id): bool
    {
        $status = $this->enumValue($status, ['approved','rejected'], 'approved');
        $scope_sql = $this->ownerScopeCondition('operational_content_queue');
        $stmt = $this->conn->prepare(
            "UPDATE operational_content_queue
             SET review_status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ?{$scope_sql}
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssii', $status, $review_notes, $user_id, $queue_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function createAnnouncement(array $payload, int $user_id): bool
    {
        $audience = $this->enumValue($payload['audience_type'] ?? 'all', ['all','users','businesses','staff'], 'all');
        $title = substr(trim((string)($payload['title'] ?? '')), 0, 180);
        $message = trim((string)($payload['message'] ?? ''));
        $channel = substr(trim((string)($payload['delivery_channel'] ?? 'in_app')), 0, 30);
        $status = $this->enumValue($payload['status'] ?? 'draft', ['draft','scheduled','sent'], 'draft');
        if ($title === '' || $message === '') {
            return false;
        }
        $owner_scope_id = $this->resolveWriteOwnerScope($user_id);

        if ($this->hasOwnerScopeColumn('operational_announcements')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_announcements
                 (audience_type, title, message, delivery_channel, status, scheduled_at, sent_at, created_by, owner_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", ?, ?, NOW(), NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssii', $audience, $title, $message, $channel, $status, $user_id, $owner_scope_id);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_announcements
                 (audience_type, title, message, delivery_channel, status, scheduled_at, sent_at, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", ?, NOW(), NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssi', $audience, $title, $message, $channel, $status, $user_id);
        }
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $status === 'sent' && function_exists('createNotification') && $this->tableExists('users')) {
            foreach ($this->getAudienceUserIds($audience) as $target_user_id) {
                createNotification($this->conn, $target_user_id, 'operations_announcement', $title, $message, null, 'operations');
            }
        }

        return $ok;
    }

    public function enqueueJob(string $job_name, string $job_type, array $payload, int $user_id): bool
    {
        $payload_json = json_encode($payload);
        $owner_scope_id = $this->resolveWriteOwnerScope($user_id);
        if ($this->hasOwnerScopeColumn('operational_jobs')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_jobs
                 (job_name, job_type, status, payload_json, created_by, owner_user_id, created_at)
                 VALUES (?, ?, 'queued', ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssii', $job_name, $job_type, $payload_json, $user_id, $owner_scope_id);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_jobs
                 (job_name, job_type, status, payload_json, created_by, created_at)
                 VALUES (?, ?, 'queued', ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssi', $job_name, $job_type, $payload_json, $user_id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function toggleRule(int $rule_id, bool $is_active): bool
    {
        $active = $is_active ? 1 : 0;
        $scope_sql = $this->ownerScopeCondition('operational_rules');
        $stmt = $this->conn->prepare("UPDATE operational_rules SET is_active = ?, updated_at = NOW() WHERE id = ?{$scope_sql} LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $active, $rule_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getRules(): array
    {
        return $this->rowsIfTable(
            "operational_rules",
            "SELECT * FROM operational_rules WHERE 1=1" . $this->ownerScopeCondition('operational_rules') . " ORDER BY rule_type ASC, rule_name ASC"
        );
    }

    public function captureMetricSnapshot(): bool
    {
        $metrics = $this->getOverviewMetrics();
        $hour = (int)date('G');
        $owner_scope_id = $this->resolveWriteOwnerScope();
        if ($this->hasOwnerScopeColumn('operational_metric_snapshots')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_metric_snapshots
                 (snapshot_date, snapshot_hour, owner_user_id, active_users, transactions_count, gross_revenue, open_complaints, pending_businesses, system_errors, failed_logins, api_latency_ms, created_at)
                 VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    active_users = VALUES(active_users),
                    transactions_count = VALUES(transactions_count),
                    gross_revenue = VALUES(gross_revenue),
                    open_complaints = VALUES(open_complaints),
                    pending_businesses = VALUES(pending_businesses),
                    system_errors = VALUES(system_errors),
                    failed_logins = VALUES(failed_logins),
                    api_latency_ms = VALUES(api_latency_ms),
                    created_at = NOW()"
            );
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_metric_snapshots
                 (snapshot_date, snapshot_hour, active_users, transactions_count, gross_revenue, open_complaints, pending_businesses, system_errors, failed_logins, api_latency_ms, created_at)
                 VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    active_users = VALUES(active_users),
                    transactions_count = VALUES(transactions_count),
                    gross_revenue = VALUES(gross_revenue),
                    open_complaints = VALUES(open_complaints),
                    pending_businesses = VALUES(pending_businesses),
                    system_errors = VALUES(system_errors),
                    failed_logins = VALUES(failed_logins),
                    api_latency_ms = VALUES(api_latency_ms),
                    created_at = NOW()"
            );
        }
        if (!$stmt) {
            return false;
        }
        $system_errors = $this->scalarIfTable("operational_alerts", "SELECT COUNT(*) FROM operational_alerts WHERE severity IN ('high','critical') AND is_acknowledged = 0" . $this->ownerScopeCondition('operational_alerts'), 0);
        $failed_logins = $this->countSuspiciousEvents();
        $latency = 0.0;
        if ($this->hasOwnerScopeColumn('operational_metric_snapshots')) {
            $stmt->bind_param(
                'iiiidiiiid',
                $hour,
                $owner_scope_id,
                $metrics['active_users_24h'],
                $metrics['transactions_today'],
                $metrics['gross_revenue_today'],
                $metrics['open_complaints'],
                $metrics['pending_businesses'],
                $system_errors,
                $failed_logins,
                $latency
            );
        } else {
            $stmt->bind_param(
                'iiidiiiid',
                $hour,
                $metrics['active_users_24h'],
                $metrics['transactions_today'],
                $metrics['gross_revenue_today'],
                $metrics['open_complaints'],
                $metrics['pending_businesses'],
                $system_errors,
                $failed_logins,
                $latency
            );
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function logBackupCheck(array $payload, int $user_id): bool
    {
        $backup_type = substr(trim((string)($payload['backup_type'] ?? 'database')), 0, 40);
        $file_name = substr(trim((string)($payload['file_name'] ?? 'manual-check')), 0, 180);
        $storage_path = substr(trim((string)($payload['storage_path'] ?? 'local')), 0, 255);
        $status = $this->enumValue($payload['backup_status'] ?? 'success', ['pending','success','failed'], 'success');
        $notes = trim((string)($payload['notes'] ?? 'Manual backup verification logged.'));
        $owner_scope_id = $this->resolveWriteOwnerScope($user_id);
        if ($this->hasOwnerScopeColumn('operational_backup_log')) {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_backup_log
                 (backup_type, file_name, storage_path, backup_status, file_size, checksum, notes, started_at, completed_at, created_by, owner_user_id, created_at)
                 VALUES (?, ?, ?, ?, 0, NULL, ?, NOW(), NOW(), ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssii', $backup_type, $file_name, $storage_path, $status, $notes, $user_id, $owner_scope_id);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO operational_backup_log
                 (backup_type, file_name, storage_path, backup_status, file_size, checksum, notes, started_at, completed_at, created_by, created_at)
                 VALUES (?, ?, ?, ?, 0, NULL, ?, NOW(), NOW(), ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssi', $backup_type, $file_name, $storage_path, $status, $notes, $user_id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getAuditEvents(int $limit = 20): array
    {
        if (!$this->tableExists('audit_logs')) {
            return [];
        }

        return $this->rows(
            "SELECT a.*, u.full_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE " . $this->scopedUserWhereSql('a.user_id') . "
             ORDER BY a.created_at DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    public function getLogFiles(int $limit = 12): array
    {
        if ($this->isTenantScoped()) {
            return [];
        }

        $log_dir = realpath(__DIR__ . '/../logs');
        if (!$log_dir || !is_dir($log_dir)) {
            return [];
        }

        $files = [];
        foreach ((array)scandir($log_dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $log_dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'name' => $entry,
                'size' => filesize($path) ?: 0,
                'modified_at' => filemtime($path) ?: 0
            ];
        }

        usort($files, static function ($a, $b) {
            return (int)$b['modified_at'] <=> (int)$a['modified_at'];
        });
        return array_slice($files, 0, max(1, (int)$limit));
    }

    public function syncOperationalAlerts(int $actor_user_id = 0): void
    {
        $metrics = $this->getOverviewMetrics();

        $this->syncThresholdAlert(
            'pending_business_backlog',
            $metrics['pending_businesses'] >= 5,
            'business',
            $metrics['pending_businesses'] >= 10 ? 'high' : 'medium',
            'Pending business approvals need attention',
            'Business applications are building up and should be reviewed by operations.'
        );

        $this->syncThresholdAlert(
            'complaint_backlog',
            $metrics['open_complaints'] >= 10,
            'complaint',
            $metrics['open_complaints'] >= 20 ? 'critical' : 'high',
            'Complaint backlog is elevated',
            'Open complaints are trending above the expected baseline.'
        );

        $this->syncThresholdAlert(
            'content_queue_backlog',
            $metrics['pending_content'] >= 5,
            'content',
            'medium',
            'Content moderation queue is growing',
            'Flagged or pending content should be reviewed before it affects trust and quality.'
        );

        $this->syncThresholdAlert(
            'suspicious_access',
            $metrics['suspicious_events_24h'] >= 8,
            'security',
            $metrics['suspicious_events_24h'] >= 15 ? 'critical' : 'high',
            'Suspicious access activity detected',
            'Authentication or access-related events crossed the monitoring threshold in the last 24 hours.'
        );
    }

    public function seedDefaultRules(): void
    {
        if (!$this->tableExists('operational_rules')) {
            return;
        }

        $rules = [
            ['Complaint backlog threshold', 'alert', ['metric' => 'open_complaints', 'operator' => '>=', 'value' => 10], ['create_alert' => 'complaint_backlog']],
            ['Pending business approval threshold', 'automation', ['metric' => 'pending_businesses', 'operator' => '>=', 'value' => 5], ['notify_ops' => true]],
            ['Suspicious access threshold', 'security', ['metric' => 'suspicious_events_24h', 'operator' => '>=', 'value' => 8], ['create_alert' => 'suspicious_access']],
            ['Content moderation threshold', 'moderation', ['metric' => 'pending_content', 'operator' => '>=', 'value' => 5], ['notify_moderator' => true]]
        ];
        $scope_sql = $this->ownerScopeCondition('operational_rules');
        $has_owner_scope_column = $this->hasOwnerScopeColumn('operational_rules');
        $owner_scope_id = $this->resolveWriteOwnerScope();

        foreach ($rules as $rule_meta) {
            $exists = $this->fetchOne("SELECT id FROM operational_rules WHERE rule_name = ?{$scope_sql} LIMIT 1", [$rule_meta[0]], 's');
            if ($exists) {
                continue;
            }
            if ($has_owner_scope_column) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO operational_rules
                     (rule_name, rule_type, conditions_json, actions_json, is_active, created_by, owner_user_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, NULL, ?, NOW(), NOW())"
                );
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO operational_rules
                     (rule_name, rule_type, conditions_json, actions_json, is_active, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, NULL, NOW(), NOW())"
                );
            }
            if ($stmt) {
                $conditions = json_encode($rule_meta[2]);
                $actions = json_encode($rule_meta[3]);
                if ($has_owner_scope_column) {
                    $stmt->bind_param('ssssi', $rule_meta[0], $rule_meta[1], $conditions, $actions, $owner_scope_id);
                } else {
                    $stmt->bind_param('ssss', $rule_meta[0], $rule_meta[1], $conditions, $actions);
                }
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function syncThresholdAlert(string $alert_key, bool $should_exist, string $alert_type, string $severity, string $title, string $message): void
    {
        if (!$this->tableExists('operational_alerts')) {
            return;
        }

        $scoped_alert_key = $this->scopedAlertKey($alert_key);
        $scope_sql = $this->ownerScopeCondition('operational_alerts');

        if ($should_exist) {
            $existing = $this->fetchOne("SELECT id FROM operational_alerts WHERE alert_key = ?{$scope_sql} LIMIT 1", [$scoped_alert_key], 's');
            if ($existing) {
                $stmt = $this->conn->prepare(
                    "UPDATE operational_alerts
                     SET severity = ?, title = ?, message = ?, is_acknowledged = 0, updated_at = NOW()
                     WHERE alert_key = ?{$scope_sql}
                     LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('ssss', $severity, $title, $message, $scoped_alert_key);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                if ($this->hasOwnerScopeColumn('operational_alerts')) {
                    $stmt = $this->conn->prepare(
                        "INSERT INTO operational_alerts
                         (alert_type, alert_key, severity, title, message, entity_type, entity_id, is_acknowledged, owner_user_id, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?, NOW(), NOW())"
                    );
                    if ($stmt) {
                        $owner_scope_id = $this->resolveWriteOwnerScope();
                        $stmt->bind_param('ssssssi', $alert_type, $scoped_alert_key, $severity, $title, $message, $alert_type, $owner_scope_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $this->conn->prepare(
                        "INSERT INTO operational_alerts
                         (alert_type, alert_key, severity, title, message, entity_type, entity_id, is_acknowledged, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, NULL, 0, NOW(), NOW())"
                    );
                    if ($stmt) {
                        $stmt->bind_param('ssssss', $alert_type, $scoped_alert_key, $severity, $title, $message, $alert_type);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        } else {
            $stmt = $this->conn->prepare("DELETE FROM operational_alerts WHERE alert_key = ?{$scope_sql} LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $scoped_alert_key);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function getAudienceUserIds(string $audience): array
    {
        if (!$this->tableExists('users')) {
            return [];
        }

        $where = "1=1";
        if ($audience === 'users') {
            $where = "LOWER(COALESCE(user_type, 'customer')) IN ('customer','user','')";
        } elseif ($audience === 'businesses') {
            $where = "LOWER(COALESCE(user_type, '')) = 'admin' AND LOWER(COALESCE(account_type, '')) = 'organization'";
        } elseif ($audience === 'staff') {
            $where = "LOWER(COALESCE(user_type, '')) IN ('admin','employee')";
        }

        if ($this->isTenantScoped()) {
            $where .= " AND " . $this->scopedUserWhereSql('id');
        }

        $rows = $this->rows("SELECT id FROM users WHERE {$where}");
        return array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $rows);
    }

    private function countSuspiciousEvents(): int
    {
        if (!$this->tableExists('audit_logs')) {
            return 0;
        }
        $scope_sql = '';
        if ($this->isTenantScoped()) {
            $scope_sql = " AND " . $this->scopedUserWhereSql('user_id');
        }
        return (int)$this->scalar(
            "SELECT COUNT(*)
             FROM audit_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               {$scope_sql}
               AND (
                   LOWER(action) LIKE '%fail%'
                   OR LOWER(action) LIKE '%denied%'
                   OR LOWER(description) LIKE '%invalid%'
                   OR LOWER(description) LIKE '%blocked%'
               )",
            0
        );
    }

    private function isTenantScoped(): bool
    {
        return $this->owner_scope_user_id > 0;
    }

    private function resolveWriteOwnerScope(int $fallback_user_id = 0): int
    {
        if ($this->owner_scope_user_id > 0) {
            return $this->owner_scope_user_id;
        }
        if ($fallback_user_id > 0 && function_exists('getFranchiseSellerScopeOwnerId')) {
            $resolved_owner = (int)(getFranchiseSellerScopeOwnerId($this->conn, $fallback_user_id) ?? 0);
            if ($resolved_owner > 0) {
                return $resolved_owner;
            }
        }
        return 0;
    }

    private function getScopedUserIds(): array
    {
        if (!$this->isTenantScoped()) {
            return [];
        }
        if (is_array($this->scoped_user_ids)) {
            return $this->scoped_user_ids;
        }

        $scope_ids = [(int)$this->owner_scope_user_id];
        if (function_exists('getFranchiseSellerScopeUserIds')) {
            $resolved_scope_ids = getFranchiseSellerScopeUserIds($this->conn, (int)$this->owner_scope_user_id);
            if (is_array($resolved_scope_ids)) {
                foreach ($resolved_scope_ids as $scope_user_id) {
                    $scope_user_id = (int)$scope_user_id;
                    if ($scope_user_id > 0) {
                        $scope_ids[] = $scope_user_id;
                    }
                }
            }
        }

        $scope_ids = array_values(array_unique(array_filter($scope_ids, static function ($value) {
            return (int)$value > 0;
        })));
        $this->scoped_user_ids = $scope_ids;
        return $this->scoped_user_ids;
    }

    private function scopedUserWhereSql(string $column = 'id'): string
    {
        if (!$this->isTenantScoped()) {
            return '1=1';
        }

        $scope_ids = $this->getScopedUserIds();
        if (empty($scope_ids)) {
            return '0=1';
        }

        return $column . ' IN (' . implode(',', array_map('intval', $scope_ids)) . ')';
    }

    private function isUserInScope(int $user_id): bool
    {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return false;
        }
        if (!$this->isTenantScoped()) {
            return true;
        }
        return in_array($user_id, $this->getScopedUserIds(), true);
    }

    private function hasOwnerScopeColumn(string $table_name): bool
    {
        return $this->tableExists($table_name) && $this->columnExists($table_name, 'owner_user_id');
    }

    private function ownerScopeCondition(string $table_name, string $alias = ''): string
    {
        if (!$this->isTenantScoped() || !$this->hasOwnerScopeColumn($table_name)) {
            return '';
        }

        $column = $alias !== '' ? "{$alias}.owner_user_id" : 'owner_user_id';
        return " AND {$column} = " . (int)$this->owner_scope_user_id;
    }

    private function orderScopeCondition(string $alias = 'orders'): string
    {
        if (
            !$this->isTenantScoped()
            || !$this->tableExists('orders')
            || !$this->columnExists('orders', 'seller_id')
        ) {
            return '';
        }

        return " AND {$alias}.seller_id = " . (int)$this->owner_scope_user_id;
    }

    private function franchiseScopeCondition(string $table_name = 'franchise_applications', string $alias = ''): string
    {
        if (
            !$this->isTenantScoped()
            || !$this->tableExists($table_name)
            || !$this->columnExists($table_name, 'user_id')
        ) {
            return '';
        }

        $column = $alias !== '' ? "{$alias}.user_id" : 'user_id';
        return " AND {$column} = " . (int)$this->owner_scope_user_id;
    }

    private function chatConversationScopeCondition(string $alias = 'chat_conversations'): string
    {
        if (!$this->isTenantScoped() || !$this->tableExists('chat_conversations')) {
            return '';
        }

        $scope_user_id = (int)$this->owner_scope_user_id;
        $conditions = [];
        if ($this->columnExists('chat_conversations', 'seller_id')) {
            $conditions[] = "{$alias}.seller_id = {$scope_user_id}";
        }
        if ($this->columnExists('chat_conversations', 'shop_user_id')) {
            $conditions[] = "{$alias}.shop_user_id = {$scope_user_id}";
        }
        if (
            $this->columnExists('chat_conversations', 'order_id')
            && $this->tableExists('orders')
            && $this->columnExists('orders', 'seller_id')
        ) {
            $conditions[] = "EXISTS (
                SELECT 1
                FROM orders scoped_orders
                WHERE scoped_orders.id = {$alias}.order_id
                  AND scoped_orders.seller_id = {$scope_user_id}
            )";
        }

        if (empty($conditions)) {
            return ' AND 0=1';
        }

        return ' AND (' . implode(' OR ', $conditions) . ')';
    }

    private function scopedAlertKey(string $alert_key): string
    {
        $alert_key = trim($alert_key);
        if ($alert_key === '') {
            return $alert_key;
        }
        if (!$this->isTenantScoped()) {
            return $alert_key;
        }

        return substr($alert_key . ':owner:' . (int)$this->owner_scope_user_id, 0, 120);
    }

    private function ensureOwnerScopeColumns(): void
    {
        $scope_tables = [
            'operational_incidents' => ['idx_ops_incidents_owner', 'created_by'],
            'operational_alerts' => ['idx_ops_alerts_owner', 'acknowledged_at'],
            'operational_metric_snapshots' => ['idx_ops_snapshots_owner', 'snapshot_hour'],
            'operational_content_queue' => ['idx_ops_content_owner', 'reviewed_by'],
            'operational_watchlist' => ['idx_ops_watchlist_owner', 'created_by'],
            'operational_rules' => ['idx_ops_rules_owner', 'created_by'],
            'operational_jobs' => ['idx_ops_jobs_owner', 'created_by'],
            'operational_announcements' => ['idx_ops_announcements_owner', 'created_by'],
            'operational_backup_log' => ['idx_ops_backup_owner', 'created_by']
        ];

        foreach ($scope_tables as $table_name => $scope_meta) {
            [$index_name, $after_column] = $scope_meta;
            if (!$this->tableExists($table_name)) {
                continue;
            }
            if (!$this->columnExists($table_name, 'owner_user_id')) {
                $after_sql = $after_column !== '' && $this->columnExists($table_name, $after_column)
                    ? " AFTER `{$after_column}`"
                    : '';
                $this->conn->query("ALTER TABLE `{$table_name}` ADD COLUMN `owner_user_id` INT NOT NULL DEFAULT 0{$after_sql}");
            }
            if (!$this->indexExists($table_name, $index_name)) {
                $this->conn->query("ALTER TABLE `{$table_name}` ADD INDEX `{$index_name}` (`owner_user_id`)");
            }
        }

        $this->ensureScopedUniqueIndex(
            'operational_alerts',
            'uniq_operational_alert_key',
            'uniq_operational_alert_scope',
            '(alert_key, owner_user_id)'
        );
        $this->ensureScopedUniqueIndex(
            'operational_rules',
            'uniq_operational_rule_name',
            'uniq_operational_rule_scope',
            '(rule_name, owner_user_id)'
        );
        $this->ensureScopedUniqueIndex(
            'operational_metric_snapshots',
            'uniq_operational_snapshot',
            'uniq_operational_snapshot_scope',
            '(snapshot_date, snapshot_hour, owner_user_id)'
        );
    }

    private function ensureScopedUniqueIndex(string $table_name, string $legacy_index, string $scoped_index, string $columns_sql): void
    {
        if (!$this->tableExists($table_name) || !$this->columnExists($table_name, 'owner_user_id')) {
            return;
        }
        if ($this->indexExists($table_name, $scoped_index)) {
            return;
        }
        if ($this->indexExists($table_name, $legacy_index)) {
            $this->conn->query("ALTER TABLE `{$table_name}` DROP INDEX `{$legacy_index}`");
        }
        $this->conn->query("ALTER TABLE `{$table_name}` ADD UNIQUE KEY `{$scoped_index}` {$columns_sql}");
    }

    private function indexExists(string $table_name, string $index_name): bool
    {
        $table_name = trim($table_name);
        $index_name = trim($index_name);
        if ($table_name === '' || $index_name === '') {
            return false;
        }
        $safe_table = $this->conn->real_escape_string($table_name);
        $safe_index = $this->conn->real_escape_string($index_name);
        $result = $this->conn->query("SHOW INDEX FROM `{$safe_table}` WHERE Key_name = '{$safe_index}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(string $table_name, string $column_name): bool
    {
        $table_name = trim($table_name);
        $column_name = trim($column_name);
        if ($table_name === '' || $column_name === '') {
            return false;
        }
        if (!$this->tableExists($table_name)) {
            return false;
        }
        $safe_table = $this->conn->real_escape_string($table_name);
        $safe_column = $this->conn->real_escape_string($column_name);
        $result = $this->conn->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function tableExists(string $table_name): bool
    {
        $table_name = trim($table_name);
        if ($table_name === '') {
            return false;
        }
        $safe_table = $this->conn->real_escape_string($table_name);
        $result = $this->conn->query("SHOW TABLES LIKE '{$safe_table}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function rowsIfTable(string $table_name, string $query): array
    {
        if (!$this->tableExists($table_name)) {
            return [];
        }
        return $this->rows($query);
    }

    private function scalarIfTable(string $table_name, string $query, $default)
    {
        if (!$this->tableExists($table_name)) {
            return $default;
        }
        return $this->scalar($query, $default);
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

    private function scalar(string $query, $default)
    {
        $result = $this->conn->query($query);
        if (!$result) {
            return $default;
        }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? $default;
    }

    private function fetchOne(string $query, array $params, string $types): ?array
    {
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }
        if ($params) {
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
}
