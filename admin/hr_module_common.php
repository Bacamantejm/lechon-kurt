<?php
if (!function_exists('hrEnsureCsrfToken')) {
    function hrEnsureCsrfToken() {
        if (empty($_SESSION['hr_csrf_token']) || !is_string($_SESSION['hr_csrf_token'])) {
            try {
                $_SESSION['hr_csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['hr_csrf_token'] = sha1(uniqid((string)mt_rand(), true));
            }
        }

        return $_SESSION['hr_csrf_token'];
    }
}

if (!function_exists('hrIsValidCsrfToken')) {
    function hrIsValidCsrfToken($token) {
        return is_string($token)
            && isset($_SESSION['hr_csrf_token'])
            && is_string($_SESSION['hr_csrf_token'])
            && hash_equals($_SESSION['hr_csrf_token'], $token);
    }
}

if (!function_exists('hrEnforcePostCsrf')) {
    function hrEnforcePostCsrf($redirect_file) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!hrIsValidCsrfToken($token)) {
            $_SESSION['error'] = 'Security check failed. Please refresh the page and try again.';
            header('Location: ' . $redirect_file);
            exit();
        }
    }
}

if (!function_exists('hrIsValidDate')) {
    function hrIsValidDate($date, $format = 'Y-m-d') {
        if (!is_string($date) || trim($date) === '') {
            return false;
        }

        $date_obj = DateTime::createFromFormat($format, $date);
        return $date_obj && $date_obj->format($format) === $date;
    }
}

if (!function_exists('hrSafeEnum')) {
    function hrSafeEnum($value, array $allowed, $default) {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists('hrTableExists')) {
    function hrTableExists($conn, $table_name) {
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "s", $table_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $exists;
    }
}

if (!function_exists('hrColumnExists')) {
    function hrColumnExists($conn, $table_name, $column_name) {
        $sql = "SELECT 1
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ss", $table_name, $column_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $exists;
    }
}

if (!function_exists('hrIndexExists')) {
    function hrIndexExists($conn, $table_name, $index_name) {
        $sql = "SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND index_name = ?
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ss", $table_name, $index_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $exists;
    }
}

if (!function_exists('hrHasForeignKeyReference')) {
    function hrHasForeignKeyReference($conn, $table_name, $column_name, $referenced_table, $referenced_column = 'id') {
        $sql = "SELECT 1
                FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
                  AND referenced_table_name = ?
                  AND referenced_column_name = ?
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ssss", $table_name, $column_name, $referenced_table, $referenced_column);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);

        return $exists;
    }
}

if (!function_exists('hrTrySchemaQuery')) {
    function hrTrySchemaQuery($conn, $sql) {
        try {
            return (bool)mysqli_query($conn, $sql);
        } catch (Throwable $e) {
            error_log('HR schema migration warning: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hrEnsureEmployeesPositionIdColumn')) {
    function hrEnsureEmployeesPositionIdColumn($conn) {
        if (!hrTableExists($conn, 'employees')) {
            return false;
        }

        if (!hrColumnExists($conn, 'employees', 'position_id')) {
            hrTrySchemaQuery($conn, "ALTER TABLE employees ADD COLUMN position_id INT NULL AFTER department_id");
        }

        if (!hrColumnExists($conn, 'employees', 'position_id')) {
            return false;
        }

        if (!hrIndexExists($conn, 'employees', 'idx_employees_position_id')) {
            hrTrySchemaQuery($conn, "ALTER TABLE employees ADD INDEX idx_employees_position_id (position_id)");
        }
        if (!hrIndexExists($conn, 'employees', 'idx_employees_position_id')) {
            return false;
        }

        if (hrTableExists($conn, 'job_positions')
            && !hrHasForeignKeyReference($conn, 'employees', 'position_id', 'job_positions', 'id')) {
            hrTrySchemaQuery(
                $conn,
                "ALTER TABLE employees
                 ADD CONSTRAINT fk_employees_position_id
                 FOREIGN KEY (position_id) REFERENCES job_positions(id)
                 ON DELETE SET NULL"
            );
        }

        return true;
    }
}

if (!function_exists('hrEnsurePositionModuleAccessTable')) {
    function hrEnsurePositionModuleAccessTable($conn) {
        if (!hrTableExists($conn, 'job_positions')) {
            return false;
        }

        $create_sql = "CREATE TABLE IF NOT EXISTS hr_position_module_access (
            position_id INT NOT NULL,
            module_key VARCHAR(100) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (position_id, module_key),
            CONSTRAINT fk_hr_position_module_access_position
                FOREIGN KEY (position_id) REFERENCES job_positions(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        return (bool)@mysqli_query($conn, $create_sql);
    }
}

if (!function_exists('hrEnsureNormalizedPositionModel')) {
    function hrEnsureNormalizedPositionModel($conn) {
        static $done = false;
        if ($done) {
            return true;
        }
        $done = true;

        if (!hrTableExists($conn, 'employees') || !hrTableExists($conn, 'job_positions')) {
            return false;
        }

        if (!hrEnsureEmployeesPositionIdColumn($conn)) {
            return false;
        }

        // Ensure legacy employee positions are represented in normalized job_positions.
        @mysqli_query(
            $conn,
            "INSERT INTO job_positions (
                position_title, department_id, description, requirements,
                salary_range_min, salary_range_max, employment_type, status,
                posted_date, closing_date, created_by, created_at, updated_at
            )
            SELECT DISTINCT
                TRIM(e.position) AS position_title,
                e.department_id,
                NULL AS description,
                NULL AS requirements,
                NULL AS salary_range_min,
                NULL AS salary_range_max,
                e.employment_type,
                'open' AS status,
                CURDATE() AS posted_date,
                NULL AS closing_date,
                e.user_id AS created_by,
                NOW() AS created_at,
                NOW() AS updated_at
            FROM employees e
            LEFT JOIN job_positions jp
              ON LOWER(TRIM(jp.position_title)) = LOWER(TRIM(e.position))
             AND (
                (jp.department_id IS NULL AND e.department_id IS NULL)
                OR jp.department_id = e.department_id
             )
            WHERE e.position IS NOT NULL
              AND TRIM(e.position) <> ''
              AND jp.id IS NULL"
        );

        // 1) Backfill by exact title + same department.
        @mysqli_query(
            $conn,
            "UPDATE employees e
             INNER JOIN job_positions jp
                 ON LOWER(TRIM(jp.position_title)) = LOWER(TRIM(e.position))
                AND (
                    (jp.department_id IS NULL AND e.department_id IS NULL)
                    OR jp.department_id = e.department_id
                )
             SET e.position_id = jp.id
             WHERE e.position_id IS NULL
               AND e.position IS NOT NULL
               AND TRIM(e.position) <> ''"
        );

        // 2) Backfill by unique global title when department match was not possible.
        @mysqli_query(
            $conn,
            "UPDATE employees e
             INNER JOIN (
                SELECT LOWER(TRIM(position_title)) AS normalized_title, MIN(id) AS chosen_id
                FROM job_positions
                GROUP BY LOWER(TRIM(position_title))
                HAVING COUNT(*) = 1
             ) map ON map.normalized_title = LOWER(TRIM(e.position))
             SET e.position_id = map.chosen_id
             WHERE e.position_id IS NULL
               AND e.position IS NOT NULL
               AND TRIM(e.position) <> ''"
        );

        // Keep legacy text field canonical and align department to position ownership.
        @mysqli_query(
            $conn,
            "UPDATE employees e
             INNER JOIN job_positions jp ON jp.id = e.position_id
             SET e.position = jp.position_title,
                 e.department_id = COALESCE(jp.department_id, e.department_id)"
        );

        if (!hrEnsurePositionModuleAccessTable($conn)) {
            return true;
        }

        // Seed logistics module access from existing operational assignments.
        @mysqli_query(
            $conn,
            "INSERT IGNORE INTO hr_position_module_access (position_id, module_key, is_enabled, created_at, updated_at)
             SELECT DISTINCT e.position_id, 'employee.logistics', 1, NOW(), NOW()
             FROM employees e
             INNER JOIN logistics_tracking lt ON lt.driver_id = e.id
             WHERE e.position_id IS NOT NULL"
        );

        @mysqli_query(
            $conn,
            "INSERT IGNORE INTO hr_position_module_access (position_id, module_key, is_enabled, created_at, updated_at)
             SELECT DISTINCT e.position_id, 'employee.logistics', 1, NOW(), NOW()
             FROM employees e
             INNER JOIN driver_availability da ON da.driver_id = e.id
             WHERE e.position_id IS NOT NULL"
        );

        // Propagate logistics access to all positions within logistics-enabled departments.
        @mysqli_query(
            $conn,
            "INSERT IGNORE INTO hr_position_module_access (position_id, module_key, is_enabled, created_at, updated_at)
             SELECT jp.id, 'employee.logistics', 1, NOW(), NOW()
             FROM job_positions jp
             WHERE jp.department_id IN (
                 SELECT DISTINCT jp_seed.department_id
                 FROM job_positions jp_seed
                 INNER JOIN hr_position_module_access pma
                     ON pma.position_id = jp_seed.id
                    AND pma.module_key = 'employee.logistics'
                    AND pma.is_enabled = 1
                 WHERE jp_seed.department_id IS NOT NULL
             )"
        );

        return true;
    }
}

if (!function_exists('hrPartnerScopeContext')) {
    function hrPartnerScopeContext($conn) {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = [
            'enabled' => false,
            'owner_user_id' => 0,
            'scoped_user_ids' => []
        ];

        $current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        if ($current_user_id <= 0 || !$conn || !function_exists('isApprovedFranchiseSellerAccount') || !function_exists('getFranchiseSellerScopeOwnerId')) {
            return $cached;
        }

        if (!isApprovedFranchiseSellerAccount($conn, $current_user_id)) {
            return $cached;
        }

        $owner_user_id = intval(getFranchiseSellerScopeOwnerId($conn, $current_user_id) ?? 0);
        if ($owner_user_id <= 0) {
            return $cached;
        }

        $scoped_user_ids = [$owner_user_id => true];
        if (hrTableExists($conn, 'partner_user_links')) {
            $links_stmt = mysqli_prepare($conn, "SELECT managed_user_id FROM partner_user_links WHERE owner_user_id = ?");
            if ($links_stmt) {
                mysqli_stmt_bind_param($links_stmt, "i", $owner_user_id);
                mysqli_stmt_execute($links_stmt);
                $links_result = mysqli_stmt_get_result($links_stmt);
                while ($links_result && ($link_row = mysqli_fetch_assoc($links_result))) {
                    $managed_user_id = intval($link_row['managed_user_id'] ?? 0);
                    if ($managed_user_id > 0) {
                        $scoped_user_ids[$managed_user_id] = true;
                    }
                }
                mysqli_stmt_close($links_stmt);
            }
        }

        $cached = [
            'enabled' => true,
            'owner_user_id' => $owner_user_id,
            'scoped_user_ids' => array_keys($scoped_user_ids)
        ];

        return $cached;
    }
}

if (!function_exists('hrIsPartnerScopeEnabled')) {
    function hrIsPartnerScopeEnabled($conn) {
        $ctx = hrPartnerScopeContext($conn);
        return !empty($ctx['enabled']);
    }
}

if (!function_exists('hrScopedUserIds')) {
    function hrScopedUserIds($conn) {
        $ctx = hrPartnerScopeContext($conn);
        return isset($ctx['scoped_user_ids']) && is_array($ctx['scoped_user_ids']) ? $ctx['scoped_user_ids'] : [];
    }
}

if (!function_exists('hrPartnerScopeOwnerUserId')) {
    function hrPartnerScopeOwnerUserId($conn) {
        $ctx = hrPartnerScopeContext($conn);
        return intval($ctx['owner_user_id'] ?? 0);
    }
}

if (!function_exists('hrScopeUserIdCsv')) {
    function hrScopeUserIdCsv($conn) {
        $ids = [];
        foreach (hrScopedUserIds($conn) as $id) {
            $id = intval($id);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (empty($ids)) {
            return '';
        }
        return implode(',', array_keys($ids));
    }
}

if (!function_exists('hrEmployeeScopeSql')) {
    function hrEmployeeScopeSql($conn, $employee_alias = 'e', $user_column = 'user_id') {
        if (!hrIsPartnerScopeEnabled($conn)) {
            return '1=1';
        }
        $csv = hrScopeUserIdCsv($conn);
        if ($csv === '') {
            return '1=0';
        }
        return "{$employee_alias}.{$user_column} IS NOT NULL AND {$employee_alias}.{$user_column} IN ({$csv})";
    }
}

if (!function_exists('hrDepartmentScopeSql')) {
    function hrDepartmentScopeSql($conn, $department_alias = 'd', $employee_alias = 'e_scope') {
        if (!hrIsPartnerScopeEnabled($conn)) {
            return '1=1';
        }
        $csv = hrScopeUserIdCsv($conn);
        if ($csv === '') {
            return '1=0';
        }
        return "(
            {$department_alias}.manager_id IN ({$csv})
            OR EXISTS (
                SELECT 1
                FROM employees {$employee_alias}
                WHERE {$employee_alias}.department_id = {$department_alias}.id
                  AND {$employee_alias}.user_id IN ({$csv})
            )
        )";
    }
}

if (!function_exists('hrPositionScopeSql')) {
    function hrPositionScopeSql($conn, $position_alias = 'jp') {
        if (!hrIsPartnerScopeEnabled($conn)) {
            return '1=1';
        }
        $csv = hrScopeUserIdCsv($conn);
        if ($csv === '') {
            return '1=0';
        }
        return "{$position_alias}.created_by IN ({$csv})";
    }
}

if (!function_exists('hrEmployeeIdInScope')) {
    function hrEmployeeIdInScope($conn, $employee_id) {
        $employee_id = intval($employee_id);
        if ($employee_id <= 0) {
            return false;
        }
        if (!hrIsPartnerScopeEnabled($conn)) {
            return true;
        }

        $scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
        $query = "SELECT e.id FROM employees e WHERE e.id = ? AND {$scope_sql} LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $employee_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('hrDepartmentIdInScope')) {
    function hrDepartmentIdInScope($conn, $department_id) {
        $department_id = intval($department_id);
        if ($department_id <= 0) {
            return false;
        }
        if (!hrIsPartnerScopeEnabled($conn)) {
            return true;
        }

        $scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');
        $query = "SELECT d.id FROM departments d WHERE d.id = ? AND {$scope_sql} LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $department_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('hrPositionIdInScope')) {
    function hrPositionIdInScope($conn, $position_id) {
        $position_id = intval($position_id);
        if ($position_id <= 0) {
            return false;
        }
        if (!hrIsPartnerScopeEnabled($conn)) {
            return true;
        }
        $scope_sql = hrPositionScopeSql($conn, 'jp');
        $query = "SELECT jp.id FROM job_positions jp WHERE jp.id = ? AND {$scope_sql} LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $position_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('hrResolveFranchiseScopeOwnerUserId')) {
    function hrResolveFranchiseScopeOwnerUserId($conn, $user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || !$conn) {
            return 0;
        }

        static $cache = [];
        if (array_key_exists($user_id, $cache)) {
            return $cache[$user_id];
        }

        $owner_user_id = 0;
        if (function_exists('getFranchiseSellerScopeOwnerId')) {
            $resolved_owner = getFranchiseSellerScopeOwnerId($conn, $user_id);
            $owner_user_id = intval($resolved_owner ?? 0);
        }

        $cache[$user_id] = max(0, $owner_user_id);
        return $cache[$user_id];
    }
}

if (!function_exists('hrCanManageEmployeeUserIdInScope')) {
    function hrCanManageEmployeeUserIdInScope($conn, $employee_user_id) {
        $employee_user_id = intval($employee_user_id);
        $partner_scoped = hrIsPartnerScopeEnabled($conn);

        if ($employee_user_id <= 0) {
            // Partner queues are tenant-linked through user scope.
            return !$partner_scoped;
        }

        $employee_owner_user_id = hrResolveFranchiseScopeOwnerUserId($conn, $employee_user_id);
        if ($partner_scoped) {
            $current_owner_user_id = hrPartnerScopeOwnerUserId($conn);
            if ($current_owner_user_id <= 0) {
                return false;
            }

            if ($employee_owner_user_id > 0) {
                return $employee_owner_user_id === $current_owner_user_id;
            }

            $scope_ids = hrScopedUserIds($conn);
            return in_array($employee_user_id, array_map('intval', $scope_ids), true);
        }

        // Global finance/admin should not process partner-owned payroll queues.
        return $employee_owner_user_id <= 0;
    }
}

if (!function_exists('hrPayrollIdInScope')) {
    function hrPayrollIdInScope($conn, $payroll_id) {
        $payroll_id = intval($payroll_id);
        if ($payroll_id <= 0 || !$conn || !hrTableExists($conn, 'payroll') || !hrTableExists($conn, 'employees')) {
            return false;
        }

        $query = "SELECT e.user_id
                  FROM payroll p
                  INNER JOIN employees e ON e.id = p.employee_id
                  WHERE p.id = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $payroll_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            return false;
        }

        return hrCanManageEmployeeUserIdInScope($conn, intval($row['user_id'] ?? 0));
    }
}

if (!function_exists('hrLogisticsEmployeeSqlCondition')) {
    function hrLogisticsEmployeeSqlCondition($employee_alias = 'e', $department_alias = 'd', $conn = null) {
        if ($conn) {
            hrEnsureNormalizedPositionModel($conn);
            if (!hrColumnExists($conn, 'employees', 'position_id') || !hrTableExists($conn, 'hr_position_module_access')) {
                return '1=0';
            }
        }
        return "{$employee_alias}.position_id IN (
            SELECT pma.position_id
            FROM hr_position_module_access pma
            WHERE pma.module_key = 'employee.logistics'
              AND pma.is_enabled = 1
        )";
    }
}

if (!function_exists('hrIsLogisticsEmployeeByUserId')) {
    function hrIsLogisticsEmployeeByUserId($conn, $user_id) {
        hrEnsureNormalizedPositionModel($conn);
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return false;
        }

        $workforce_sql = hrLogisticsEmployeeSqlCondition('e', 'd', $conn);
        $query = "SELECT e.id
                  FROM employees e
                  LEFT JOIN departments d ON d.id = e.department_id
                  WHERE e.user_id = ?
                    AND {$workforce_sql}
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $is_member = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        return $is_member;
    }
}

if (!function_exists('hrFetchDepartmentCatalog')) {
    function hrFetchDepartmentCatalog($conn, $department_scope_sql = '1=1') {
        if (!hrTableExists($conn, 'departments')) {
            return [];
        }

        $query = "SELECT d.id, d.department_name FROM departments d";
        if (is_string($department_scope_sql) && trim($department_scope_sql) !== '' && trim($department_scope_sql) !== '1=1') {
            $query .= " WHERE {$department_scope_sql}";
        }
        $query .= " ORDER BY d.department_name";

        $result = mysqli_query($conn, $query);
        $departments = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $departments[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'department_name' => (string)($row['department_name'] ?? '')
                ];
            }
        }

        return $departments;
    }
}

if (!function_exists('hrFetchPositionCatalog')) {
    function hrFetchPositionCatalog($conn, $department_scope_sql = '1=1', $employee_scope_sql = '1=1') {
        hrEnsureNormalizedPositionModel($conn);
        if (!hrTableExists($conn, 'job_positions')) {
            return ['all' => [], 'by_department' => [], 'by_id' => []];
        }

        $query = "SELECT jp.id, jp.position_title, jp.department_id
                  FROM job_positions jp
                  LEFT JOIN departments d ON d.id = jp.department_id
                  WHERE 1=1";
        if (is_string($department_scope_sql) && trim($department_scope_sql) !== '' && trim($department_scope_sql) !== '1=1') {
            $query .= " AND ({$department_scope_sql} OR jp.department_id IS NULL)";
        }
        $query .= " ORDER BY jp.position_title";

        $result = mysqli_query($conn, $query);
        $all = [];
        $by_department = [];
        $by_id = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $position = [
                    'id' => (int)($row['id'] ?? 0),
                    'title' => (string)($row['position_title'] ?? ''),
                    'department_id' => isset($row['department_id']) ? (int)$row['department_id'] : 0
                ];
                if ($position['id'] <= 0 || $position['title'] === '') {
                    continue;
                }
                $all[] = $position;
                $by_id[(string)$position['id']] = $position;
                $dept_key = (string)$position['department_id'];
                if (!isset($by_department[$dept_key])) {
                    $by_department[$dept_key] = [];
                }
                $by_department[$dept_key][] = $position;
            }
        }

        return ['all' => $all, 'by_department' => $by_department, 'by_id' => $by_id];
    }
}

if (!function_exists('hrGetPositionById')) {
    function hrGetPositionById($conn, $position_id) {
        hrEnsureNormalizedPositionModel($conn);
        $position_id = intval($position_id);
        if ($position_id <= 0 || !hrTableExists($conn, 'job_positions')) {
            return null;
        }

        $query = "SELECT jp.id, jp.position_title, jp.department_id
                  FROM job_positions jp
                  WHERE jp.id = ?
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, "i", $position_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['position_title'] ?? ''),
            'department_id' => isset($row['department_id']) ? (int)$row['department_id'] : 0
        ];
    }
}

if (!function_exists('hrRecordIdInEmployeeScope')) {
    function hrRecordIdInEmployeeScope($conn, $table_name, $record_id, $record_id_col = 'id', $employee_fk_col = 'employee_id') {
        $record_id = intval($record_id);
        if ($record_id <= 0) {
            return false;
        }
        if (!hrIsPartnerScopeEnabled($conn)) {
            return true;
        }
        $allowed_tables = [
            'attendance' => true,
            'schedules' => true,
            'leave_requests' => true,
            'leave_balance' => true,
            'performance_reviews' => true,
            'employee_turnover' => true,
            'payroll' => true,
            'employee_deductions' => true,
            'payslips' => true
        ];
        if (!isset($allowed_tables[$table_name])) {
            return false;
        }

        $scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
        $query = "SELECT t.`{$record_id_col}`
                  FROM `{$table_name}` t
                  INNER JOIN employees e ON e.id = t.`{$employee_fk_col}`
                  WHERE t.`{$record_id_col}` = ?
                    AND {$scope_sql}
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $record_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('hrCandidateIdInScope')) {
    function hrCandidateIdInScope($conn, $candidate_id) {
        $candidate_id = intval($candidate_id);
        if ($candidate_id <= 0) {
            return false;
        }
        if (!hrIsPartnerScopeEnabled($conn)) {
            return true;
        }
        $scope_sql = hrPositionScopeSql($conn, 'jp');
        $query = "SELECT c.id
                  FROM candidates c
                  INNER JOIN job_positions jp ON jp.id = c.position_id
                  WHERE c.id = ?
                    AND {$scope_sql}
                  LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $candidate_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $ok = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        return $ok;
    }
}
