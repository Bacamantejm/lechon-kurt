<?php
/**
 * Subscription & Access Control Service
 * Handles plan tier validation, feature permissions, and upgrade gating for Shop Owners.
 */

class SubscriptionAccessService
{
    public const TIER_NONE = 'none';
    public const TIER_STARTER = 'starter';
    public const TIER_GROWTH = 'growth';
    public const TIER_PRO = 'pro';

    private const TIER_HIERARCHY = [
        self::TIER_NONE => 0,
        self::TIER_STARTER => 1,
        self::TIER_GROWTH => 2,
        self::TIER_PRO => 3
    ];

    private const FEATURE_TIER_MAP = [
        'change_store_name' => self::TIER_STARTER,
        'change_store_logo' => self::TIER_STARTER,
        'upload_products' => self::TIER_STARTER,
        'basic_orders' => self::TIER_NONE,
        'customer_chat' => self::TIER_NONE,
        'hr_department' => self::TIER_GROWTH,
        'inventory_mrp' => self::TIER_GROWTH,
        'expenses' => self::TIER_GROWTH,
        'store_settings' => self::TIER_GROWTH,
        'forecasting' => self::TIER_PRO,
        'dss_reports' => self::TIER_PRO,
        'rbac_management' => self::TIER_PRO,
        'receipt_banking' => self::TIER_PRO
    ];

    private const PAGE_FEATURE_MAP = [
        // HR Department (Growth or Pro)
        'hr.php' => 'hr_department',
        'employees.php' => 'hr_department',
        'departments.php' => 'hr_department',
        'attendance.php' => 'hr_department',
        'schedules.php' => 'hr_department',
        'leave_requests.php' => 'hr_department',
        'leave_balance.php' => 'hr_department',
        'payroll.php' => 'hr_department',
        'deductions.php' => 'hr_department',
        'payslip_generation.php' => 'hr_department',
        'ajax_payroll.php' => 'hr_department',
        'get_payroll_details.php' => 'hr_department',
        'get_payroll_details_for_approval.php' => 'hr_department',
        'view_payslip.php' => 'hr_department',
        'send_payslip.php' => 'hr_department',
        'performance.php' => 'hr_department',
        'recruitment.php' => 'hr_department',
        'candidates.php' => 'hr_department',
        'turnover.php' => 'hr_department',
        'hr_reports.php' => 'hr_department',
        'get_employee_details.php' => 'hr_department',
        'get_leave_details.php' => 'hr_department',
        'get_performance_details.php' => 'hr_department',

        // Inventory & MRP (Growth or Pro)
        'inventory.php' => 'inventory_mrp',
        'get_inventory_details.php' => 'inventory_mrp',
        'get_inventory_history.php' => 'inventory_mrp',
        'mrp.php' => 'inventory_mrp',
        'purchase_order.php' => 'inventory_mrp',
        'get_po_details.php' => 'inventory_mrp',
        'materials.php' => 'inventory_mrp',
        'bom.php' => 'inventory_mrp',

        // Store Settings & Availability (Growth or Pro)
        'store_availability.php' => 'store_settings',
        'order_policy_settings.php' => 'store_settings',

        // Shop Expenses & Finance (Growth or Pro)
        'finance.php' => 'expenses',
        'expenses.php' => 'expenses',

        // AI Demand Forecasting (Pro Only)
        'forecasting_dashboard.php' => 'forecasting',
        'events.php' => 'forecasting',

        // DSS Decision Support & Deep Analytics (Pro Only)
        'dss_reports.php' => 'dss_reports',
        'statistics.php' => 'dss_reports',

        // Custom Staff RBAC Management (Pro Only)
        'rbac_management.php' => 'rbac_management',

        // Custom Receipt & Partner Banking (Pro Only)
        'receipt_settings.php' => 'receipt_banking',
        'partner_banking.php' => 'receipt_banking'
    ];

    private const FEATURE_LABELS = [
        'change_store_name' => 'Change Store Name',
        'change_store_logo' => 'Update Store Logo',
        'upload_products' => 'Upload & Add Products',
        'basic_orders' => 'Order Management',
        'customer_chat' => 'Live Customer Chat',
        'hr_department' => 'HR Department & Automated Payroll',
        'inventory_mrp' => 'Inventory & MRP Batch Roasting Calculator',
        'expenses' => 'Shop Expense Tracking & Finance',
        'store_settings' => 'Store Availability & Order Policy Settings',
        'forecasting' => 'AI Demand Forecasting & Predictive Analytics',
        'dss_reports' => 'DSS Decision Support Reports & Statistics',
        'rbac_management' => 'Custom Staff RBAC Role Management',
        'receipt_banking' => 'Custom Receipt Branding & Partner Banking'
    ];

    private const TIER_LABELS = [
        self::TIER_NONE => 'Non-Subscribed (Limited Access)',
        self::TIER_STARTER => 'Starter / Basic Plan',
        self::TIER_GROWTH => 'Growth Plan',
        self::TIER_PRO => 'Pro Plan (Full Access)'
    ];

    /**
     * Resolve the active subscription details and tier of a shop owner or staff.
     *
     * @param mysqli $conn
     * @param int $user_id
     * @return array
     */
    public static function getShopSubscriptionDetails(mysqli $conn, int $user_id): array
    {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return self::buildDetails(self::TIER_NONE, null);
        }

        // Super Admin bypass: always granted full Pro access
        if (function_exists('isSuperAdmin') && isSuperAdmin($conn, $user_id)) {
            return self::buildDetails(self::TIER_PRO, [
                'plan_name' => 'Pro Plan (Super Admin)',
                'subscription_status' => 'active',
                'is_super_admin' => true
            ]);
        }

        // Resolve shop owner scope ID
        $seller_owner_id = $user_id;
        if (function_exists('getFranchiseSellerScopeOwnerId')) {
            $scoped_owner = getFranchiseSellerScopeOwnerId($conn, $user_id);
            if ($scoped_owner !== null && (int)$scoped_owner > 0) {
                $seller_owner_id = (int)$scoped_owner;
            }
        }

        // Check active subscription from database
        $subscription = self::fetchActiveSubscription($conn, $seller_owner_id);
        if (!$subscription) {
            return self::buildDetails(self::TIER_NONE, null);
        }

        $plan_code = strtolower(trim((string)($subscription['plan_code'] ?? '')));
        $tier = self::normalizePlanCode($plan_code);

        return self::buildDetails($tier, $subscription);
    }

    /**
     * Query database for current active or valid trial/cancelled-within-cycle subscription.
     */
    private static function fetchActiveSubscription(mysqli $conn, int $partner_user_id): ?array
    {
        $query = "SELECT s.*, p.plan_code, p.plan_name, p.monthly_price, p.annual_price
                  FROM partner_plan_subscriptions s
                  INNER JOIN platform_subscription_plans p ON p.id = s.plan_id
                  WHERE s.partner_user_id = ?
                    AND s.subscription_status IN ('active', 'trial')
                  ORDER BY s.id DESC
                  LIMIT 1";

        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, "i", $partner_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * Normalize plan_code string into defined tier constant.
     */
    public static function normalizePlanCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (strpos($code, 'pro') !== false) {
            return self::TIER_PRO;
        }
        if (strpos($code, 'growth') !== false) {
            return self::TIER_GROWTH;
        }
        if (strpos($code, 'starter') !== false || strpos($code, 'basic') !== false) {
            return self::TIER_STARTER;
        }
        return self::TIER_NONE;
    }

    /**
     * Build the structured subscription response array.
     */
    private static function buildDetails(string $tier, ?array $subscription): array
    {
        $tier_level = self::TIER_HIERARCHY[$tier] ?? 0;
        $is_active = $tier_level > 0;

        return [
            'tier' => $tier,
            'tier_level' => $tier_level,
            'tier_name' => self::TIER_LABELS[$tier] ?? self::TIER_LABELS[self::TIER_NONE],
            'plan_name' => (string)($subscription['plan_name'] ?? (self::TIER_LABELS[$tier] ?? 'No Active Plan')),
            'plan_code' => (string)($subscription['plan_code'] ?? $tier),
            'subscription_status' => (string)($subscription['subscription_status'] ?? 'none'),
            'is_active' => $is_active,
            'is_subscribed' => $is_active,
            'renews_at' => $subscription['renews_at'] ?? null,
            'started_at' => $subscription['started_at'] ?? null,
            'billing_cycle' => (string)($subscription['billing_cycle'] ?? 'monthly'),
            'can_change_store_name' => $tier_level >= self::TIER_HIERARCHY[self::TIER_STARTER],
            'can_change_store_logo' => $tier_level >= self::TIER_HIERARCHY[self::TIER_STARTER],
            'can_upload_products' => $tier_level >= self::TIER_HIERARCHY[self::TIER_STARTER],
            'can_access_hr' => $tier_level >= self::TIER_HIERARCHY[self::TIER_GROWTH],
            'can_access_inventory_mrp' => $tier_level >= self::TIER_HIERARCHY[self::TIER_GROWTH],
            'can_access_expenses' => $tier_level >= self::TIER_HIERARCHY[self::TIER_GROWTH],
            'can_access_store_settings' => $tier_level >= self::TIER_HIERARCHY[self::TIER_GROWTH],
            'can_access_forecasting' => $tier_level >= self::TIER_HIERARCHY[self::TIER_PRO],
            'can_access_dss_reports' => $tier_level >= self::TIER_HIERARCHY[self::TIER_PRO],
            'can_access_custom_rbac' => $tier_level >= self::TIER_HIERARCHY[self::TIER_PRO],
            'can_access_receipt_banking' => $tier_level >= self::TIER_HIERARCHY[self::TIER_PRO]
        ];
    }

    /**
     * Check if a user has access to a specific feature key.
     */
    public static function hasFeatureAccess(mysqli $conn, int $user_id, string $feature): bool
    {
        $details = self::getShopSubscriptionDetails($conn, $user_id);
        $user_tier_level = (int)$details['tier_level'];

        $required_tier = self::FEATURE_TIER_MAP[$feature] ?? self::TIER_PRO;
        $required_level = self::TIER_HIERARCHY[$required_tier] ?? 3;

        return $user_tier_level >= $required_level;
    }

    /**
     * Check if a given tier satisfies the required tier.
     */
    public static function tierSatisfies(string $current_tier, string $required_tier): bool
    {
        $current_level = self::TIER_HIERARCHY[self::normalizePlanCode($current_tier)] ?? 0;
        $required_level = self::TIER_HIERARCHY[self::normalizePlanCode($required_tier)] ?? 3;
        return $current_level >= $required_level;
    }

    /**
     * Resolve required tier code for a feature.
     */
    public static function getRequiredTierForFeature(string $feature): string
    {
        return self::FEATURE_TIER_MAP[$feature] ?? self::TIER_PRO;
    }

    /**
     * Resolve human-readable label for a feature.
     */
    public static function getFeatureLabel(string $feature): string
    {
        return self::FEATURE_LABELS[$feature] ?? ucwords(str_replace('_', ' ', $feature));
    }

    /**
     * Resolve human-readable label for a tier.
     */
    public static function getTierLabel(string $tier): string
    {
        return self::TIER_LABELS[$tier] ?? ucfirst($tier);
    }

    /**
     * Enforce feature access. If unauthorized, halts AJAX or redirects with an upgrade notice.
     */
    public static function enforceFeatureAccess(mysqli $conn, int $user_id, string $feature, string $fallback_url = 'subscription_plans.php'): void
    {
        if (self::hasFeatureAccess($conn, $user_id, $feature)) {
            return;
        }

        $details = self::getShopSubscriptionDetails($conn, $user_id);
        $required_tier = self::getRequiredTierForFeature($feature);
        $required_tier_name = self::getTierLabel($required_tier);
        $feature_label = self::getFeatureLabel($feature);

        $message = "Access Restricted: The feature \"{$feature_label}\" requires an active {$required_tier_name}. Please upgrade your subscription plan to unlock this feature.";

        if (function_exists('isAjaxRequest') && isAjaxRequest()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'code' => 'SUBSCRIPTION_UPGRADE_REQUIRED',
                'feature' => $feature,
                'feature_label' => $feature_label,
                'required_tier' => $required_tier,
                'required_tier_name' => $required_tier_name,
                'current_tier' => $details['tier'],
                'current_tier_name' => $details['tier_name'],
                'message' => $message,
                'upgrade_url' => 'subscription_plans.php?locked_feature=' . urlencode($feature) . '&required_tier=' . urlencode($required_tier)
            ]);
            exit;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $_SESSION['subscription_upgrade_notice'] = [
            'feature' => $feature,
            'feature_label' => $feature_label,
            'required_tier' => $required_tier,
            'required_tier_name' => $required_tier_name,
            'current_tier' => $details['tier'],
            'current_tier_name' => $details['tier_name'],
            'message' => $message
        ];

        $target = $fallback_url . (strpos($fallback_url, '?') !== false ? '&' : '?') . 'locked_feature=' . urlencode($feature) . '&required_tier=' . urlencode($required_tier);
        header('Location: ' . $target);
        exit;
    }

    /**
     * Resolve feature key associated with a specific PHP page script.
     */
    public static function getFeatureForPage(string $page): ?string
    {
        $page = basename($page);
        return self::PAGE_FEATURE_MAP[$page] ?? null;
    }

    /**
     * Enforce page-level access for admin module requests.
     */
    public static function enforcePageAccess(mysqli $conn, int $user_id, string $current_page, string $fallback_url = 'subscription_plans.php'): void
    {
        $current_page = basename($current_page);
        if (!isset(self::PAGE_FEATURE_MAP[$current_page])) {
            return;
        }

        $feature = self::PAGE_FEATURE_MAP[$current_page];
        self::enforceFeatureAccess($conn, $user_id, $feature, $fallback_url);
    }
}

