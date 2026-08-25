<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['user_id'] ?? 0;
$is_partner_scoped_admin = false;
$partner_scope_owner_id = null;
$is_partner_owner_admin = false;
$partner_ops_flow = null;
$partner_account_control_state = ['status' => 'active', 'notes' => ''];
if ($user_id && isset($conn) && $conn && function_exists('isApprovedFranchiseSellerAccount')) {
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, (int)$user_id);
    if (function_exists('getUserAccountControlState')) {
        $partner_account_control_state = getUserAccountControlState($conn, (int)$user_id);
    }
    if ($is_partner_scoped_admin && function_exists('getFranchiseSellerScopeOwnerId')) {
        $partner_scope_owner_id = (int)getFranchiseSellerScopeOwnerId($conn, (int)$user_id);
        $is_partner_owner_admin = $partner_scope_owner_id > 0 && $partner_scope_owner_id === (int)$user_id;
        if ($partner_scope_owner_id > 0) {
            require_once __DIR__ . '/partner_operations_flow.php';
            if (function_exists('partnerOpsGetSummary')) {
                $partner_ops_flow = partnerOpsGetSummary($conn, $partner_scope_owner_id);
            }
        }
    }
}
$partner_account_control_status = strtolower(trim((string)($partner_account_control_state['status'] ?? 'active')));
$is_partner_restricted = $is_partner_scoped_admin && $partner_account_control_status === 'restricted';

$is_super_admin_user = false;
if ($user_id && isset($conn) && $conn && function_exists('isSuperAdmin')) {
    $is_super_admin_user = isSuperAdmin($conn, (int)$user_id);
} else {
    $is_super_admin_user = strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin';
}
$current_role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$is_business_owner_role = $current_role_name === 'business_owner';

$canPermission = function($permission) use ($user_id) {
    global $conn;
    static $permission_cache = [];

    if (!$user_id) {
        return false;
    }

    if (array_key_exists($permission, $permission_cache)) {
        return $permission_cache[$permission];
    }

    if (!isset($conn) || !$conn || !function_exists('hasPermission')) {
        return $permission_cache[$permission] = false;
    }

    return $permission_cache[$permission] = hasPermission($conn, $user_id, $permission);
};

$canModule = function($module) use ($user_id) {
    global $conn;
    static $module_cache = [];

    if (!$user_id) {
        return false;
    }

    if (array_key_exists($module, $module_cache)) {
        return $module_cache[$module];
    }

    if (!isset($conn) || !$conn || !function_exists('hasModuleAccess')) {
        return $module_cache[$module] = false;
    }

    return $module_cache[$module] = hasModuleAccess($conn, $user_id, $module);
};

$can_dashboard = $canPermission('dashboard.view') || $canModule('dashboard');

$can_orders = $canPermission('orders.view') || $canModule('orders');
$can_order_policy = $is_partner_scoped_admin && ($canPermission('orders.edit') || $is_partner_owner_admin);
$can_store_availability = $is_partner_scoped_admin && ($is_partner_owner_admin || $canPermission('orders.edit') || $canPermission('products.edit'));
$can_preorders = $canPermission('preorders.view') || $canModule('preorders');
$can_cancellations = $canPermission('cancellations.view') || $canModule('orders');
$can_reviews = $can_orders;
$can_logistics = $canPermission('logistics.view') || $canModule('logistics');
$can_chat = $can_orders || $can_logistics || $is_super_admin_user || $canModule('admin');
$show_sales_logistics = $can_orders || $can_order_policy || $can_store_availability || $can_preorders || $can_cancellations || $can_reviews || $can_logistics || $can_chat;

$can_products = $canPermission('products.view') || $canModule('products');
$can_inventory = $canPermission('inventory.view') || $canModule('inventory');
$can_mrp = $canPermission('mrp.view') || $canModule('mrp');
$show_inventory = $can_products || $can_inventory || $can_mrp;
$can_vouchers = false;

$can_finance = $canPermission('finance.view') || $canModule('finance');
$can_expenses = $canPermission('expenses.view') || $canModule('finance');
$can_partner_billing = $canPermission('billing.view') || $canPermission('billing.manage') || $canModule('billing') || ($is_partner_scoped_admin && $is_partner_owner_admin);
$can_receipt_settings = $canPermission('billing.manage') || ($is_partner_scoped_admin && $is_partner_owner_admin);
$can_partner_banking = $canPermission('billing.manage') || ($is_partner_scoped_admin && $is_partner_owner_admin);
$can_business_account = $is_partner_scoped_admin && $is_partner_owner_admin;
$show_finance = $can_finance || $can_expenses || $can_partner_billing || $can_receipt_settings || $can_partner_banking;

$hr_pages = ['hr.php', 'employees.php', 'departments.php', 'attendance.php', 'schedules.php', 'leave_requests.php', 'leave_balance.php', 'payroll.php', 'deductions.php', 'payslip_generation.php', 'performance.php', 'recruitment.php', 'candidates.php', 'turnover.php', 'hr_reports.php', 'hr_migration_checker.php'];
$is_hr_active = in_array($current_page, $hr_pages);

$can_hr_overview = $canPermission('hr.view') || $canModule('hr');
$can_employees = $canPermission('employees.view') || $canPermission('employees.create') || $canPermission('employees.edit');
$can_departments = $canPermission('departments.manage');
$can_attendance = $canPermission('attendance.view') || $canPermission('attendance.manage');
$can_schedules = $canPermission('attendance.manage') || $can_hr_overview;
$can_leave = $canPermission('leave.view') || $canPermission('leave.approve');
$can_payroll = $canPermission('payroll.view') || $canPermission('payroll.manage');
$can_payslips = $canPermission('payslip.view') || $canPermission('payslip.generate');
$can_performance = $canPermission('performance.view') || $canPermission('performance.manage');
$can_recruitment = $can_hr_overview;
$can_candidates = $can_hr_overview;
$can_turnover = $can_hr_overview;
$can_hr_reports = $canPermission('dashboard.analytics') || $can_hr_overview;
$can_hr_db_checker = $can_hr_overview;
$show_hr = $can_hr_overview || $can_employees || $can_departments || $can_attendance || $can_leave || $can_payroll || $can_payslips || $can_performance || $can_recruitment || $can_candidates || $can_turnover || $can_hr_reports || $can_hr_db_checker;

$can_users = $is_super_admin_user;
$can_franchise = $is_super_admin_user;
$can_statistics = $canPermission('dashboard.analytics') || $canPermission('audit.view');
$can_dss_reports = $is_business_owner_role || $canPermission('reports.view');
$can_forecasting = $is_business_owner_role || $canPermission('forecasting.view') || $canModule('forecasting');
$can_rbac = $canPermission('roles.manage');
$forecasting_pages = ['forecasting_dashboard.php', 'events.php', 'dss_reports.php', 'statistics.php'];
$is_forecasting_active = in_array($current_page, $forecasting_pages);
$super_admin_module_pages = [
    'user_business_management.php',
    'business_monitoring.php',
    'analytics_reports.php',
    'reports_complaints.php',
    'security_access_control.php',
    'activity_logs.php',
    'system_monitoring.php',
    'notification_center.php',
    'transactions_financial.php',
    'platform_monetization.php',
    'operations_dashboard.php',
    'operations_incidents.php',
    'operations_user_business_control.php',
    'operations_content_moderation.php',
    'operations_decision_support.php',
    'operations_notifications.php',
    'operations_automation.php',
    'operations_logs_backups.php',
    'operations_team.php'
];
$is_super_admin_modules_active = in_array($current_page, $super_admin_module_pages, true);
$can_super_admin_modules = $is_super_admin_user;
$can_operations_modules = $is_super_admin_user
    || in_array($current_role_name, ['operational_manager', 'operations_staff'], true)
    || $canPermission('operations.view')
    || $canModule('operations');

$show_admin = $can_users || $can_franchise || $can_statistics || $can_rbac || $can_forecasting || $can_dss_reports;
if ($can_operations_modules) {
    $show_admin = true;
}

if ($is_partner_scoped_admin) {
    // Only partner owners get full operational visibility by default.
    // Partner staff must follow explicit RBAC permissions.
    if ($is_partner_owner_admin) {
        $can_dashboard = true;
        $can_products = true;
        $can_vouchers = true;

        $can_orders = true;
        $can_preorders = true;
        $can_cancellations = true;
        $can_reviews = true;
        $can_logistics = true;
        $can_chat = true;
        $show_sales_logistics = true;

        $can_inventory = true;
        $can_mrp = true;
        $show_inventory = true;

        $can_hr_overview = true;
        $can_employees = true;
        $can_departments = true;
        $can_attendance = true;
        $can_schedules = true;
        $can_leave = true;
        $can_payroll = true;
        $can_payslips = true;
        $can_performance = true;
        $can_recruitment = true;
        $can_candidates = true;
        $can_turnover = true;
        $can_hr_reports = true;
        $can_hr_db_checker = false;
        $show_hr = true;
    }

    $can_finance = $is_partner_owner_admin || $canPermission('finance.view') || $canPermission('finance.manage') || $canModule('finance');
    $can_expenses = $is_partner_owner_admin || $canPermission('expenses.view') || $canPermission('expenses.manage') || $canPermission('finance.manage');
    $can_order_policy = $is_partner_scoped_admin && ($is_partner_owner_admin || $canPermission('orders.edit'));
    $can_store_availability = $is_partner_scoped_admin && ($is_partner_owner_admin || $canPermission('orders.edit') || $canPermission('products.edit'));
    $can_partner_billing = $is_partner_owner_admin || $canPermission('billing.view') || $canPermission('billing.manage') || $canModule('billing');
    $can_receipt_settings = $is_partner_owner_admin || $canPermission('billing.manage');
    $can_partner_banking = $is_partner_owner_admin || $canPermission('billing.manage');
    $can_business_account = $is_partner_owner_admin;
    $show_finance = $can_finance || $can_expenses || $can_partner_billing || $can_receipt_settings || $can_partner_banking;

    $can_users = false;
    $can_franchise = false;
    $can_statistics = $is_partner_owner_admin || $canPermission('dashboard.analytics') || $canPermission('audit.view');
    $can_dss_reports = $is_partner_owner_admin || $canPermission('reports.view');
    $can_forecasting = $is_partner_owner_admin || $canPermission('forecasting.view') || $canModule('forecasting');
    $can_rbac = $is_partner_owner_admin || $canPermission('roles.manage');
    $can_operations_modules = false;
    $show_admin = $can_statistics || $can_dss_reports || $can_forecasting || $can_rbac;
}

if ($is_partner_restricted) {
    $can_products = false;
    $can_vouchers = false;
    $can_preorders = false;
    $can_logistics = false;
    $can_chat = $can_orders;
    $show_sales_logistics = $can_orders || $can_cancellations || $can_reviews || $can_chat;

    $can_inventory = false;
    $can_mrp = false;
    $show_inventory = false;

    $can_expenses = false;
    $can_receipt_settings = false;
    $can_partner_banking = false;
    $can_business_account = $is_partner_owner_admin;
    $can_order_policy = false;
    $can_store_availability = false;
    $show_finance = $can_finance;

    $can_hr_overview = false;
    $can_employees = false;
    $can_departments = false;
    $can_attendance = false;
    $can_schedules = false;
    $can_leave = false;
    $can_payroll = false;
    $can_payslips = false;
    $can_performance = false;
    $can_recruitment = false;
    $can_candidates = false;
    $can_turnover = false;
    $can_hr_reports = false;
    $can_hr_db_checker = false;
    $show_hr = false;

    $can_dss_reports = false;
    $can_forecasting = false;
    $can_rbac = false;
    $can_operations_modules = false;
    $show_admin = $can_statistics;
}

// Super admin should focus on owner-level governance modules,
// not day-to-day partner operations modules.
if ($is_super_admin_user) {
    $can_orders = false;
    $can_preorders = false;
    $can_cancellations = false;
    $can_reviews = false;
    $can_logistics = false;
    $show_sales_logistics = false;

    $can_products = false;
    $can_vouchers = false;
    $can_inventory = false;
    $can_mrp = false;
    $show_inventory = false;

    $can_finance = false;
    $can_expenses = false;
    $can_receipt_settings = false;
    $can_partner_banking = false;
    $can_order_policy = false;
    $can_store_availability = false;
    $show_finance = false;

    $can_hr_overview = false;
    $can_employees = false;
    $can_departments = false;
    $can_attendance = false;
    $can_schedules = false;
    $can_leave = false;
    $can_payroll = false;
    $can_payslips = false;
    $can_performance = false;
    $can_recruitment = false;
    $can_candidates = false;
    $can_turnover = false;
    $can_hr_reports = false;
    $can_hr_db_checker = false;
    $show_hr = false;
}

$is_employee_user = isset($_SESSION['user_type']) && strtolower(trim($_SESSION['user_type'])) === 'employee';
$current_script_path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
$is_super_admin_context = strpos($current_script_path, '/super_admin/') !== false;

if ($is_super_admin_user) {
    $dashboard_href = $is_super_admin_context ? 'super_admin_dashboard.php' : '../super_admin/super_admin_dashboard.php';
    $dashboard_active = in_array($current_page, ['super_admin_dashboard.php', 'index.php'], true);
} elseif ($can_operations_modules && $is_super_admin_context) {
    $dashboard_href = 'operations_dashboard.php';
    $dashboard_active = in_array($current_page, ['operations_dashboard.php', 'index.php'], true);
} else {
    // In admin pages, keep Dashboard inside admin/index.php.
    $dashboard_href = 'index.php';
    $dashboard_active = ($current_page === 'index.php');
}
if ($can_operations_modules) {
    $can_dashboard = true;
    if (!$is_partner_restricted) {
        $show_admin = true;
    }
}

$sidebar_brand_user_id = $partner_scope_owner_id > 0 ? (int)$partner_scope_owner_id : (int)$user_id;
$sidebar_brand_user = null;
if ($sidebar_brand_user_id > 0 && isset($conn) && $conn && function_exists('getUserInfo')) {
    $sidebar_brand_user = getUserInfo($conn, $sidebar_brand_user_id);
}

$sidebar_brand_name = trim((string)($sidebar_brand_user['business_name'] ?? ''));
if ($sidebar_brand_name === '') {
    $sidebar_brand_name = trim((string)($sidebar_brand_user['full_name'] ?? ''));
}
if ($sidebar_brand_name === '') {
    $sidebar_brand_name = 'Lechon Delights';
}

$sidebar_brand_logo = trim((string)($sidebar_brand_user['profile_image'] ?? ''));
if ($sidebar_brand_logo !== '' && stripos($sidebar_brand_logo, 'http://') !== 0 && stripos($sidebar_brand_logo, 'https://') !== 0) {
    $sidebar_brand_logo = '../' . ltrim($sidebar_brand_logo, '/');
}
$sidebar_current_user = null;
if ($user_id > 0 && isset($conn) && $conn && function_exists('getUserInfo')) {
    $sidebar_current_user = getUserInfo($conn, (int)$user_id);
}
$topbar_profile_avatar = trim((string)($sidebar_current_user['profile_image'] ?? ''));
if ($topbar_profile_avatar !== '' && stripos($topbar_profile_avatar, 'http://') !== 0 && stripos($topbar_profile_avatar, 'https://') !== 0) {
    $topbar_profile_avatar = '../' . ltrim($topbar_profile_avatar, '/');
}
$sidebar_logged_in_name = trim((string)($_SESSION['full_name'] ?? ($sidebar_brand_user['full_name'] ?? '')));
$sidebar_role_key = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
if ($sidebar_role_key === '' && $is_partner_scoped_admin) {
    $sidebar_role_key = $is_partner_owner_admin ? 'business_owner' : 'partner_staff';
}
$sidebar_role_label = $sidebar_role_key !== ''
    ? (function_exists('getRoleDisplayName') ? getRoleDisplayName($sidebar_role_key) : ucwords(str_replace('_', ' ', $sidebar_role_key)))
    : ($is_super_admin_user ? 'Super Administrator (System Owner)' : 'Admin Dashboard');
$sidebar_role_label = preg_replace('/\s*\(.*\)\s*/', '', (string)$sidebar_role_label);
$topbar_account_type_label = strtolower(trim((string)($_SESSION['account_type'] ?? ($sidebar_current_user['account_type'] ?? 'individual')))) === 'organization'
    ? 'Business Partner'
    : 'Customer Account';
$topbar_current_shop_label = $sidebar_brand_name;
$topbar_my_account_link = '../my_account.php';
$topbar_billing_link = '';
if ($can_partner_billing) {
    $topbar_billing_link = 'partner_billing.php';
} elseif ($is_super_admin_user) {
    $topbar_billing_link = '../super_admin/platform_monetization.php';
}
?>

<!-- Admin Sidebar Navigation -->
<nav class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <a href="../menu.php?seller_id=<?php echo (int)($partner_scope_owner_id ?: $user_id); ?>" class="sidebar-brand-link" title="View Your Storefront" style="text-decoration: none; color: inherit; display: block;">
            <div class="sidebar-brand-mark">
                <?php if ($sidebar_brand_logo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_brand_logo); ?>" alt="<?php echo htmlspecialchars($sidebar_brand_name); ?> logo" class="sidebar-brand-logo">
                <?php else: ?>
                    <div class="sidebar-brand-fallback">
                        <?php echo htmlspecialchars(strtoupper(substr($sidebar_brand_name, 0, 1))); ?>
                    </div>
                <?php endif; ?>
            </div>
            <h3><?php echo htmlspecialchars($sidebar_brand_name); ?></h3>
            <p><?php echo htmlspecialchars($sidebar_role_label); ?></p>
        </a>
    </div>
    
    <div class="sidebar-search-container">
        <div class="sidebar-search-wrap">
            <i class="fas fa-search sidebar-search-icon"></i>
            <input type="text" id="sidebarSearchInput" class="sidebar-search-input" placeholder="Search menu..." autocomplete="off">
            <button type="button" id="sidebarSearchClear" class="sidebar-search-clear" title="Clear search" style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    
    <ul class="sidebar-menu" id="sidebarMenu">
        <li class="sidebar-search-empty" id="sidebarSearchEmpty" style="display: none; padding: 22px 14px; text-align: center; color: #94a3b8; font-size: 13px;">
            <i class="fas fa-search" style="font-size: 20px; display: block; margin: 0 auto 8px; opacity: 0.5;"></i>
            No matching menu items
        </li>
        <?php if ($can_dashboard): ?>
            <li class="menu-header">Main</li>
            <li>
                <a href="<?php echo htmlspecialchars($dashboard_href); ?>" class="menu-item <?php echo $dashboard_active ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span><?php echo $is_super_admin_user ? 'Owner Dashboard' : 'Dashboard'; ?></span>
                </a>
            </li>
            <?php if ($can_business_account): ?>
            <li>
                <a href="business_account.php" class="menu-item <?php echo ($current_page === 'business_account.php') ? 'active' : ''; ?>">
                    <i class="fas fa-id-card"></i>
                    <span>Business Account</span>
                </a>
            </li>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$is_super_admin_user && $is_partner_scoped_admin && is_array($partner_ops_flow)): ?>
            <li class="menu-header">Operations Flow</li>
            <li>
                <div class="menu-item <?php echo (in_array($current_page, ['orders.php', 'preorders.php', 'logistics.php', 'order_policy_settings.php', 'store_availability.php', 'finance.php', 'inventory.php', 'hr.php', 'payroll.php', 'partner_billing.php', 'subscription_plans.php', 'receipt_settings.php', 'partner_banking.php'], true)) ? 'active' : ''; ?>">
                    <i class="fas fa-project-diagram"></i>
                    <span>Partner Pipeline</span>
                </div>
                <div class="sidebar-submenu" style="display:block;">
                    <?php foreach (($partner_ops_flow['modules'] ?? []) as $flow_module): ?>
                        <a href="<?php echo htmlspecialchars((string)$flow_module['url']); ?>" class="submenu-item">
                            <i class="fas fa-arrow-right"></i>
                            <?php echo htmlspecialchars((string)$flow_module['label']); ?>
                            <span style="margin-left:auto;background:#c62828;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;">
                                <?php echo (int)($flow_module['count'] ?? 0); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <div style="padding:8px 16px 4px 36px;font-size:11px;color:#9aa0a6;text-transform:uppercase;letter-spacing:.4px;">Next Actions</div>
                    <?php foreach (($partner_ops_flow['steps'] ?? []) as $flow_step): ?>
                        <a href="<?php echo htmlspecialchars((string)$flow_step['url']); ?>" class="submenu-item">
                            <i class="fas fa-check-circle"></i>
                            <?php echo htmlspecialchars((string)$flow_step['label']); ?>
                        </a>
                    <?php endforeach; ?>
                    <div style="padding:6px 16px 8px 36px;font-size:10px;color:#9aa0a6;">Updated <?php echo htmlspecialchars((string)($partner_ops_flow['updated_at'] ?? date('H:i'))); ?></div>
                </div>
            </li>
        <?php endif; ?>

        <?php if ($is_partner_restricted): ?>
            <li class="menu-header">Partner Status</li>
            <li>
                <div class="menu-item active" style="cursor:default;background:#b45309;">
                    <i class="fas fa-user-lock"></i>
                    <span>Restricted Access</span>
                </div>
            </li>
        <?php endif; ?>
        
        <?php if ($show_sales_logistics): ?>
            <li class="menu-header">Sales & Logistics</li>
            <?php if ($can_orders): ?>
                <li>
                    <a href="orders.php" class="menu-item <?php echo ($current_page === 'orders.php') ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Orders</span>
                    </a>
                </li>
                <li>
                    <a href="kiosk.php" class="menu-item <?php echo ($current_page === 'kiosk.php') ? 'active' : ''; ?>">
                        <i class="fas fa-cash-register"></i>
                        <span>Walk-in Orders</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_preorders): ?>
                <li>
                    <a href="preorders.php" class="menu-item <?php echo ($current_page === 'preorders.php') ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check"></i>
                        <span>Pre-Orders</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_cancellations): ?>
                <li>
                    <a href="cancellations.php" class="menu-item <?php echo ($current_page === 'cancellations.php') ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i>
                        <span>Cancellations & Refunds</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_reviews): ?>
                <li>
                    <a href="reviews.php" class="menu-item <?php echo ($current_page === 'reviews.php') ? 'active' : ''; ?>">
                        <i class="fas fa-star"></i>
                        <span>Reviews</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_logistics): ?>
                <li>
                    <a href="logistics.php" class="menu-item <?php echo (in_array($current_page, ['logistics.php', 'logistics_settings.php'])) ? 'active' : ''; ?>">
                        <i class="fas fa-truck"></i>
                        <span>Logistics</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_chat): ?>
                <li>
                    <a href="chat.php" class="menu-item <?php echo ($current_page === 'chat.php') ? 'active' : ''; ?>">
                        <i class="fas fa-comments"></i>
                        <span>Messages</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_order_policy): ?>
                <li>
                    <a href="order_policy_settings.php" class="menu-item <?php echo ($current_page === 'order_policy_settings.php') ? 'active' : ''; ?>">
                        <i class="fas fa-file-signature"></i>
                        <span>Order Policy</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($can_store_availability): ?>
                <li>
                    <a href="store_availability.php" class="menu-item <?php echo ($current_page === 'store_availability.php') ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i>
                        <span>Store Availability</span>
                    </a>
                </li>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($show_inventory): ?>
            <li class="menu-header">Inventory & Production</li>
            <?php if ($can_products): ?>
                <li>
                    <a href="products.php" class="menu-item <?php echo ($current_page === 'products.php') ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i>
                        <span>Products</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_inventory): ?>
                <li>
                    <a href="inventory.php" class="menu-item <?php echo ($current_page === 'inventory.php') ? 'active' : ''; ?>">
                        <i class="fas fa-warehouse"></i>
                        <span>Inventory</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_mrp): ?>
                <li>
                    <a href="mrp.php" class="menu-item <?php echo (in_array($current_page, ['mrp.php', 'materials.php', 'bom.php', 'purchase_order.php'])) ? 'active' : ''; ?>">
                        <i class="fas fa-dolly"></i>
                        <span>Procurement</span>
                    </a>
                </li>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($can_vouchers): ?>
            <li class="menu-header">Promotions</li>
            <li>
                <a href="vouchers.php" class="menu-item <?php echo ($current_page === 'seller_vouchers.php' || $current_page === 'vouchers.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tags"></i>
                    <span>Vouchers &amp; Discounts</span>
                </a>
            </li>
        <?php endif; ?>
        
        <?php if ($show_finance): ?>
            <li class="menu-header">Finance</li>
            <?php if ($can_finance): ?>
                <li>
                    <a href="finance.php" class="menu-item <?php echo ($current_page === 'finance.php') ? 'active' : ''; ?>">
                        <i class="fas fa-coins"></i>
                        <span>Finance</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_expenses): ?>
                <li>
                    <a href="expenses.php" class="menu-item <?php echo ($current_page === 'expenses.php') ? 'active' : ''; ?>">
                        <i class="fas fa-receipt"></i>
                        <span>Expenses</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_partner_billing): ?>
                <li>
                    <a href="subscription_plans.php" class="menu-item <?php echo ($current_page === 'subscription_plans.php') ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i>
                        <span>Subscription Plans</span>
                    </a>
                </li>
                <li>
                    <a href="partner_billing.php" class="menu-item <?php echo ($current_page === 'partner_billing.php') ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Billing</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($can_receipt_settings): ?>
                <li>
                    <a href="receipt_settings.php" class="menu-item <?php echo ($current_page === 'receipt_settings.php') ? 'active' : ''; ?>">
                        <i class="fas fa-receipt"></i>
                        <span>Receipt Settings</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($can_partner_banking): ?>
                <li>
                    <a href="partner_banking.php" class="menu-item <?php echo ($current_page === 'partner_banking.php') ? 'active' : ''; ?>">
                        <i class="fas fa-university"></i>
                        <span>Banking Setup</span>
                    </a>
                </li>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($show_hr): ?>
            <li class="menu-header">Human Resources</li>
            <li>
                <a href="hr.php" class="menu-item <?php echo (in_array($current_page, ['hr.php', 'employees.php', 'departments.php', 'attendance.php', 'schedules.php', 'leave_requests.php', 'leave_balance.php', 'payroll.php', 'deductions.php', 'payslip_generation.php', 'performance.php', 'recruitment.php', 'candidates.php', 'turnover.php', 'hr_reports.php', 'hr_migration_checker.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-people-arrows"></i>
                    <span>HR Management</span>
                </a>
                <div class="sidebar-submenu" id="hrSubmenu" style="<?php echo $is_hr_active ? 'display: block;' : 'display: none;'; ?>">
                    <?php if ($can_employees): ?>
                        <a href="employees.php" class="submenu-item <?php echo ($current_page === 'employees.php') ? 'active' : ''; ?>">
                            <i class="fas fa-id-badge"></i> Employees
                        </a>
                    <?php endif; ?>
                    <?php if ($can_departments): ?>
                        <a href="departments.php" class="submenu-item <?php echo ($current_page === 'departments.php') ? 'active' : ''; ?>">
                            <i class="fas fa-sitemap"></i> Departments
                        </a>
                    <?php endif; ?>
                    <?php if ($can_attendance): ?>
                        <a href="attendance.php" class="submenu-item <?php echo ($current_page === 'attendance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i> Attendance
                        </a>
                    <?php endif; ?>
                    <?php if ($can_schedules): ?>
                        <a href="schedules.php" class="submenu-item <?php echo ($current_page === 'schedules.php') ? 'active' : ''; ?>">
                            <i class="fas fa-clock"></i> Schedules
                        </a>
                    <?php endif; ?>
                    <?php if ($can_leave): ?>
                        <a href="leave_requests.php" class="submenu-item <?php echo ($current_page === 'leave_requests.php') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-minus"></i> Leave Requests
                        </a>
                        <a href="leave_balance.php" class="submenu-item <?php echo ($current_page === 'leave_balance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-balance-scale"></i> Leave Balance
                        </a>
                    <?php endif; ?>
                    <?php if ($can_payroll): ?>
                        <a href="payroll.php" class="submenu-item <?php echo ($current_page === 'payroll.php') ? 'active' : ''; ?>">
                            <i class="fas fa-wallet"></i> Payroll
                        </a>
                        <a href="deductions.php" class="submenu-item <?php echo ($current_page === 'deductions.php') ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar"></i> Deductions
                        </a>
                    <?php endif; ?>
                    <?php if ($can_payslips): ?>
                        <a href="payslip_generation.php" class="submenu-item <?php echo ($current_page === 'payslip_generation.php') ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar"></i> Payslips
                        </a>
                    <?php endif; ?>
                    <?php if ($can_performance): ?>
                        <a href="performance.php" class="submenu-item <?php echo ($current_page === 'performance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-star"></i> Performance
                        </a>
                    <?php endif; ?>
                    <?php if ($can_recruitment): ?>
                        <a href="recruitment.php" class="submenu-item <?php echo ($current_page === 'recruitment.php') ? 'active' : ''; ?>">
                            <i class="fas fa-briefcase"></i> Recruitment
                        </a>
                    <?php endif; ?>
                    <?php if ($can_candidates): ?>
                        <a href="candidates.php" class="submenu-item <?php echo ($current_page === 'candidates.php') ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i> Candidates
                        </a>
                    <?php endif; ?>
                    <?php if ($can_turnover): ?>
                        <a href="turnover.php" class="submenu-item <?php echo ($current_page === 'turnover.php') ? 'active' : ''; ?>">
                            <i class="fas fa-door-open"></i> Turnover
                        </a>
                    <?php endif; ?>
                    <?php if ($can_hr_reports): ?>
                        <a href="hr_reports.php" class="submenu-item <?php echo ($current_page === 'hr_reports.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    <?php endif; ?>
                    <?php if ($can_hr_db_checker): ?>
                        <a href="hr_migration_checker.php" class="submenu-item <?php echo ($current_page === 'hr_migration_checker.php') ? 'active' : ''; ?>">
                            <i class="fas fa-database"></i> DB Checker
                        </a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endif; ?>

        <?php if ($show_admin): ?>
            <li class="menu-header">Administration</li>
            <?php if ($can_users): ?>
                <li>
                    <a href="../super_admin/users.php" class="menu-item <?php echo ($current_page === 'users.php') ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($can_franchise): ?>
                <li>
                    <a href="../super_admin/franchise_applications.php" class="menu-item <?php echo ($current_page === 'franchise_applications.php') ? 'active' : ''; ?>">
                        <i class="fas fa-file-contract"></i>
                        <span>Business Apps</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($can_super_admin_modules): ?>
                <?php if ($is_super_admin_context): ?>
                    <li class="menu-header">Owner Modules</li>
                    <li>
                        <a href="../super_admin/user_business_management.php" class="menu-item <?php echo ($current_page === 'user_business_management.php') ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>User & Business</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/business_monitoring.php" class="menu-item <?php echo ($current_page === 'business_monitoring.php') ? 'active' : ''; ?>">
                            <i class="fas fa-store"></i>
                            <span>Business Monitoring</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/analytics_reports.php" class="menu-item <?php echo ($current_page === 'analytics_reports.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i>
                            <span>Analytics & Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/reports_complaints.php" class="menu-item <?php echo ($current_page === 'reports_complaints.php') ? 'active' : ''; ?>">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span>Complaints</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/security_access_control.php" class="menu-item <?php echo ($current_page === 'security_access_control.php') ? 'active' : ''; ?>">
                            <i class="fas fa-user-shield"></i>
                            <span>Security Access</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/activity_logs.php" class="menu-item <?php echo ($current_page === 'activity_logs.php') ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Activity Logs</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/system_monitoring.php" class="menu-item <?php echo ($current_page === 'system_monitoring.php') ? 'active' : ''; ?>">
                            <i class="fas fa-server"></i>
                            <span>System Monitoring</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/notification_center.php" class="menu-item <?php echo ($current_page === 'notification_center.php') ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notification Center</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/transactions_financial.php" class="menu-item <?php echo ($current_page === 'transactions_financial.php') ? 'active' : ''; ?>">
                            <i class="fas fa-coins"></i>
                            <span>Transactions</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/platform_monetization.php" class="menu-item <?php echo ($current_page === 'platform_monetization.php') ? 'active' : ''; ?>">
                            <i class="fas fa-sack-dollar"></i>
                            <span>Monetization</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="../super_admin/user_business_management.php" class="menu-item menu-item-with-submenu <?php echo $is_super_admin_modules_active ? 'active' : ''; ?>">
                            <i class="fas fa-crown"></i>
                            <span>Super Admin Modules</span>
                            <i class="fas fa-chevron-down submenu-arrow"></i>
                        </a>
                        <div class="sidebar-submenu" style="<?php echo $is_super_admin_modules_active ? 'display: block;' : 'display: none;'; ?>">
                            <a href="../super_admin/user_business_management.php" class="submenu-item <?php echo ($current_page === 'user_business_management.php') ? 'active' : ''; ?>">
                                <i class="fas fa-users-cog"></i> User & Business
                            </a>
                            <a href="../super_admin/business_monitoring.php" class="submenu-item <?php echo ($current_page === 'business_monitoring.php') ? 'active' : ''; ?>">
                                <i class="fas fa-store"></i> Business Monitoring
                            </a>
                            <a href="../super_admin/analytics_reports.php" class="submenu-item <?php echo ($current_page === 'analytics_reports.php') ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line"></i> Analytics & Reports
                            </a>
                            <a href="../super_admin/reports_complaints.php" class="submenu-item <?php echo ($current_page === 'reports_complaints.php') ? 'active' : ''; ?>">
                                <i class="fas fa-triangle-exclamation"></i> Complaints
                            </a>
                            <a href="../super_admin/security_access_control.php" class="submenu-item <?php echo ($current_page === 'security_access_control.php') ? 'active' : ''; ?>">
                                <i class="fas fa-user-shield"></i> Security Access
                            </a>
                            <a href="../super_admin/activity_logs.php" class="submenu-item <?php echo ($current_page === 'activity_logs.php') ? 'active' : ''; ?>">
                                <i class="fas fa-clipboard-list"></i> Activity Logs
                            </a>
                            <a href="../super_admin/system_monitoring.php" class="submenu-item <?php echo ($current_page === 'system_monitoring.php') ? 'active' : ''; ?>">
                                <i class="fas fa-server"></i> System Monitoring
                            </a>
                            <a href="../super_admin/notification_center.php" class="submenu-item <?php echo ($current_page === 'notification_center.php') ? 'active' : ''; ?>">
                                <i class="fas fa-bell"></i> Notification Center
                            </a>
                            <a href="../super_admin/transactions_financial.php" class="submenu-item <?php echo ($current_page === 'transactions_financial.php') ? 'active' : ''; ?>">
                                <i class="fas fa-coins"></i> Transactions
                            </a>
                            <a href="../super_admin/platform_monetization.php" class="submenu-item <?php echo ($current_page === 'platform_monetization.php') ? 'active' : ''; ?>">
                                <i class="fas fa-sack-dollar"></i> Monetization
                            </a>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($can_operations_modules): ?>
                <?php
                $show_ops_logs_backups = !$is_partner_scoped_admin;
                $show_ops_team_roles = $is_super_admin_user;
                $operations_pages = [
                    'operations_dashboard.php',
                    'operations_incidents.php',
                    'operations_content_moderation.php',
                    'operations_decision_support.php',
                    'operations_user_business_control.php',
                    'operations_notifications.php',
                    'operations_automation.php'
                ];
                if ($show_ops_logs_backups) {
                    $operations_pages[] = 'operations_logs_backups.php';
                }
                if ($show_ops_team_roles) {
                    $operations_pages[] = 'operations_team.php';
                }
                ?>
                <?php if ($is_super_admin_context): ?>
                    <li class="menu-header">Operations Manager</li>
                    <li>
                        <a href="../super_admin/operations_dashboard.php" class="menu-item <?php echo ($current_page === 'operations_dashboard.php') ? 'active' : ''; ?>">
                            <i class="fas fa-gauge-high"></i>
                            <span>Operations Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_incidents.php" class="menu-item <?php echo ($current_page === 'operations_incidents.php') ? 'active' : ''; ?>">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span>Incidents & Alerts</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_content_moderation.php" class="menu-item <?php echo ($current_page === 'operations_content_moderation.php') ? 'active' : ''; ?>">
                            <i class="fas fa-shield-check"></i>
                            <span>Content Moderation</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_decision_support.php" class="menu-item <?php echo ($current_page === 'operations_decision_support.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-pie"></i>
                            <span>Decision Support</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_user_business_control.php" class="menu-item <?php echo ($current_page === 'operations_user_business_control.php') ? 'active' : ''; ?>">
                            <i class="fas fa-users-gear"></i>
                            <span>Users & Businesses</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_notifications.php" class="menu-item <?php echo ($current_page === 'operations_notifications.php') ? 'active' : ''; ?>">
                            <i class="fas fa-bullhorn"></i>
                            <span>Notifications</span>
                        </a>
                    </li>
                    <li>
                        <a href="../super_admin/operations_automation.php" class="menu-item <?php echo ($current_page === 'operations_automation.php') ? 'active' : ''; ?>">
                            <i class="fas fa-robot"></i>
                            <span>Automation</span>
                        </a>
                    </li>
                    <?php if ($show_ops_logs_backups): ?>
                        <li>
                            <a href="../super_admin/operations_logs_backups.php" class="menu-item <?php echo ($current_page === 'operations_logs_backups.php') ? 'active' : ''; ?>">
                                <i class="fas fa-database"></i>
                                <span>Logs & Backups</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($show_ops_team_roles): ?>
                        <li>
                            <a href="../super_admin/operations_team.php" class="menu-item <?php echo ($current_page === 'operations_team.php') ? 'active' : ''; ?>">
                                <i class="fas fa-user-shield"></i>
                                <span>Team & Roles</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php else: ?>
                    <li>
                        <a href="../super_admin/operations_dashboard.php" class="menu-item menu-item-with-submenu <?php echo in_array($current_page, $operations_pages, true) ? 'active' : ''; ?>">
                            <i class="fas fa-sitemap"></i>
                            <span>Operations Manager</span>
                            <i class="fas fa-chevron-down submenu-arrow"></i>
                        </a>
                        <div class="sidebar-submenu" style="<?php echo in_array($current_page, $operations_pages, true) ? 'display: block;' : 'display: none;'; ?>">
                            <a href="../super_admin/operations_dashboard.php" class="submenu-item <?php echo ($current_page === 'operations_dashboard.php') ? 'active' : ''; ?>">
                                <i class="fas fa-gauge-high"></i> Operations Dashboard
                            </a>
                            <a href="../super_admin/operations_incidents.php" class="submenu-item <?php echo ($current_page === 'operations_incidents.php') ? 'active' : ''; ?>">
                                <i class="fas fa-triangle-exclamation"></i> Incidents & Alerts
                            </a>
                            <a href="../super_admin/operations_content_moderation.php" class="submenu-item <?php echo ($current_page === 'operations_content_moderation.php') ? 'active' : ''; ?>">
                                <i class="fas fa-shield-check"></i> Content Moderation
                            </a>
                            <a href="../super_admin/operations_decision_support.php" class="submenu-item <?php echo ($current_page === 'operations_decision_support.php') ? 'active' : ''; ?>">
                                <i class="fas fa-chart-pie"></i> Decision Support
                            </a>
                            <a href="../super_admin/operations_user_business_control.php" class="submenu-item <?php echo ($current_page === 'operations_user_business_control.php') ? 'active' : ''; ?>">
                                <i class="fas fa-users-gear"></i> Users & Businesses
                            </a>
                            <a href="../super_admin/operations_notifications.php" class="submenu-item <?php echo ($current_page === 'operations_notifications.php') ? 'active' : ''; ?>">
                                <i class="fas fa-bullhorn"></i> Notifications
                            </a>
                            <a href="../super_admin/operations_automation.php" class="submenu-item <?php echo ($current_page === 'operations_automation.php') ? 'active' : ''; ?>">
                                <i class="fas fa-robot"></i> Automation
                            </a>
                            <?php if ($show_ops_logs_backups): ?>
                                <a href="../super_admin/operations_logs_backups.php" class="submenu-item <?php echo ($current_page === 'operations_logs_backups.php') ? 'active' : ''; ?>">
                                    <i class="fas fa-database"></i> Logs & Backups
                                </a>
                            <?php endif; ?>
                            <?php if ($show_ops_team_roles): ?>
                                <a href="../super_admin/operations_team.php" class="submenu-item <?php echo ($current_page === 'operations_team.php') ? 'active' : ''; ?>">
                                    <i class="fas fa-user-shield"></i> Team & Roles
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($can_forecasting): ?>
                <?php if ($is_super_admin_context): ?>
                    <li class="menu-header">Forecasting & DSS</li>
                    <li>
                        <a href="../admin/forecasting_dashboard.php" class="menu-item <?php echo ($current_page === 'forecasting_dashboard.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i>
                            <span>Forecasting Dashboard</span>
                        </a>
                    </li>
                    <?php if ($can_dss_reports): ?>
                        <li>
                            <a href="../admin/dss_reports.php" class="menu-item <?php echo ($current_page === 'dss_reports.php') ? 'active' : ''; ?>">
                                <i class="fas fa-file-invoice"></i>
                                <span>DSS Reports</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($can_statistics): ?>
                        <li>
                            <a href="../admin/statistics.php" class="menu-item <?php echo ($current_page === 'statistics.php') ? 'active' : ''; ?>">
                                <i class="fas fa-chart-bar"></i>
                                <span>Statistics</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li>
                        <a href="../admin/events.php" class="menu-item <?php echo ($current_page === 'events.php') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Business Events</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="forecasting_dashboard.php" class="menu-item menu-item-with-submenu <?php echo $is_forecasting_active ? 'active' : ''; ?>">
                            <i class="fas fa-brain"></i>
                            <span>Forecasting & DSS</span>
                            <i class="fas fa-chevron-down submenu-arrow"></i>
                        </a>
                        <div class="sidebar-submenu" style="<?php echo $is_forecasting_active ? 'display: block;' : 'display: none;'; ?>">
                            <a href="forecasting_dashboard.php" class="submenu-item <?php echo ($current_page === 'forecasting_dashboard.php') ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line"></i> Dashboard
                            </a>
                            <?php if ($can_dss_reports): ?>
                                <a href="dss_reports.php" class="submenu-item <?php echo ($current_page === 'dss_reports.php') ? 'active' : ''; ?>">
                                    <i class="fas fa-file-invoice"></i> DSS Reports
                                </a>
                            <?php endif; ?>
                            <?php if ($can_statistics): ?>
                                <a href="statistics.php" class="submenu-item <?php echo ($current_page === 'statistics.php') ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-bar"></i> Statistics
                                </a>
                            <?php endif; ?>
                            <a href="events.php" class="submenu-item <?php echo ($current_page === 'events.php') ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-alt"></i> Business Events
                            </a>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($can_rbac): ?>
                <?php $rbac_href = $is_super_admin_context ? '../admin/rbac_management.php' : 'rbac_management.php'; ?>
                <li>
                    <a href="<?php echo htmlspecialchars($rbac_href); ?>" class="menu-item <?php echo ($current_page === 'rbac_management.php') ? 'active' : ''; ?>">
                        <i class="fas fa-lock"></i>
                        <span>RBAC Management</span>
                    </a>
                </li>
            <?php endif; ?>
        <?php endif; ?>
    </ul>
    
    <div class="sidebar-footer">
        <?php if ($is_employee_user): ?>
            <a href="../employee/dashboard.php" class="logout-btn" style="margin-bottom: 8px; background-color: #1e88e5;">
                <i class="fas fa-user-clock"></i>
                <span>Employee Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="logout.php" class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</nav>

<style>
    /* Submenu arrow for sidebar */
    .menu-item-with-submenu {
        position: relative;
    }
    .submenu-arrow {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.8rem;
        transition: transform 0.3s;
    }
    .menu-item-with-submenu.active .submenu-arrow {
        transform: translateY(-50%) rotate(180deg);
    }

    /* Force topbar right controls in one horizontal line */
    .admin-topbar .topbar-content {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
    }

    .admin-topbar .topbar-right {
        margin-left: auto;
        display: flex !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        gap: 10px;
        white-space: nowrap;
    }

    .admin-topbar .topbar-right .date-display {
        display: none;
    }

    .admin-topbar .topbar-right .admin-profile {
        display: flex !important;
        align-items: center;
        flex: 0 0 auto;
    }

    .admin-topbar .topbar-right .theme-toggler {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 1px solid #dbe2ea;
        background: #f1f5f9;
        color: #475569;
        margin: 0 !important;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        transition: all 0.2s ease;
    }

    .admin-topbar .topbar-right .theme-toggler:hover {
        color: #c62828;
        background: #fff1f2;
        border-color: #fecaca;
        transform: translateY(-1px);
    }

    @media (max-width: 576px) {
        .admin-topbar .topbar-right .theme-toggler,
        .admin-topbar .topbar-right .notification-btn {
            width: 36px;
            height: 36px;
        }

        .admin-topbar .topbar-right .admin-profile span {
            display: none;
        }
    }

    /* Admin Notification Styles */
    .admin-header-actions {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 8px;
        margin-right: 0;
        flex-shrink: 0;
    }

    .admin-notification-wrapper, .admin-chat-wrapper {
        position: relative;
    }
    
    .notification-btn {
        width: 40px;
        min-width: 40px;
        height: 40px;
        border-radius: 999px;
        background: #f1f5f9;
        border: 1px solid #dbe2ea;
        font-size: 1rem;
        color: #475569;
        cursor: pointer;
        position: relative;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .topbar-action-btn {
        width: auto;
        padding: 0 10px;
        gap: 8px;
        font-size: 0.84rem;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .topbar-action-btn i {
        font-size: 0.95rem;
    }

    .admin-action-label {
        line-height: 1;
        white-space: nowrap;
    }
    
    .notification-btn:hover {
        color: #c62828;
        background: #fff1f2;
        border-color: #fecaca;
        transform: translateY(-1px);
    }
    
    .notification-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        background-color: #c62828;
        color: white;
        font-size: 0.7rem;
        font-weight: bold;
        padding: 2px 5px;
        border-radius: 10px;
        min-width: 18px;
        text-align: center;
        display: none;
    }
    
    .notification-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        width: 320px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        z-index: 1000;
        display: none;
        overflow: hidden;
        border: 1px solid #eee;
        margin-top: 10px;
    }
    
    .notification-dropdown.show {
        display: block;
        animation: slideDown 0.2s ease;
    }
    
    .notification-header {
        padding: 12px 15px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 600;
        background: #f8f9fa;
    }
    
    .mark-read-btn {
        font-size: 0.8rem;
        color: #c62828;
        text-decoration: none;
        cursor: pointer;
    }
    
    .notification-list {
        max-height: 350px;
        overflow-y: auto;
    }
    
    .notification-item {
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        gap: 12px;
        transition: background 0.2s;
        cursor: pointer;
    }
    
    .notification-item:hover {
        background-color: #f9f9f9;
    }
    
    .notification-item.unread {
        background-color: #fff5f5;
    }
    
    .notif-icon {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background: #e3f2fd;
        color: #1976d2;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .notif-content {
        flex: 1;
    }
    
    .notif-title {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 2px;
        color: #333;
    }
    
    .notif-message {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 4px;
        line-height: 1.3;
    }
    
    .notif-time {
        font-size: 0.75rem;
        color: #999;
    }
    
    .notification-empty {
        padding: 20px;
        text-align: center;
        color: #999;
        font-size: 0.9rem;
    }

    /* Dark Mode Support */
    body.dark-mode .notification-btn {
        color: #e2e8f0;
        background: #2f3543;
        border-color: var(--border-color-dark);
    }
    body.dark-mode .notification-dropdown { background: var(--card-bg-dark); border-color: var(--border-color-dark); }
    body.dark-mode .notification-header { background: #333; border-color: var(--border-color-dark); color: #e0e0e0; }
    body.dark-mode .notification-item { border-color: var(--border-color-dark); }
    body.dark-mode .notification-item:hover { background-color: #3d3d3d; }
    body.dark-mode .notification-item.unread { background-color: #3a2a2a; }
    body.dark-mode .notif-title { color: #e0e0e0; }
    body.dark-mode .notif-message { color: #b0b0b0; }
    body.dark-mode .notification-empty { color: #b0b0b0; }

    @keyframes bell-shake {
        0% { transform: rotate(0); }
        15% { transform: rotate(5deg); }
        30% { transform: rotate(-5deg); }
        45% { transform: rotate(4deg); }
        60% { transform: rotate(-4deg); }
        75% { transform: rotate(2deg); }
        85% { transform: rotate(-2deg); }
        100% { transform: rotate(0); }
    }
    
    .notification-btn.shaking i {
        animation: bell-shake 2s infinite;
        color: #c62828;
    }

    .notification-btn.is-active {
        color: #c62828;
        background: #fff1f2;
        border-color: #fecaca;
    }

    @media (max-width: 1200px) {
        .topbar-action-btn {
            padding: 0;
            width: 40px;
        }

        .admin-action-label {
            display: none;
        }
    }

    .admin-profile {
        gap: 8px;
        max-width: 220px;
        padding: 6px 10px 6px 8px;
    }

    .admin-profile > span,
    .admin-profile-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .admin-profile-name {
        flex: 1 1 auto;
        min-width: 0;
        font-size: 13px;
        font-weight: 700;
    }

    .admin-profile > i,
    .admin-profile-caret {
        color: var(--primary);
        font-size: 13px;
        flex: 0 0 auto;
    }

    .admin-profile-caret {
        transition: transform 0.18s ease;
    }

    .admin-profile.open .admin-profile-caret {
        transform: rotate(180deg);
    }

    .admin-profile-dropdown-head {
        gap: 4px;
    }

    .admin-profile-dropdown-head small {
        font-size: 12px;
        color: var(--text-light);
        line-height: 1.4;
    }

    .admin-profile-meta-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
    }

    .admin-profile-meta-item b {
        text-align: right;
    }

    .admin-profile-links {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .admin-profile-link {
        justify-content: center;
        min-height: 42px;
    }

    .admin-profile-link i {
        color: currentColor;
        font-size: 14px;
    }

    body.dark-mode .admin-profile-dropdown-head small {
        color: #cbd5e1;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const topbarProfile = {
            name: <?php echo json_encode($sidebar_logged_in_name); ?>,
            avatar: <?php echo json_encode($topbar_profile_avatar); ?>,
            initials: <?php echo json_encode(strtoupper(substr($sidebar_logged_in_name !== '' ? $sidebar_logged_in_name : 'Admin', 0, 1))); ?>
        };
        const topbarProfileMenu = {
            accountType: <?php echo json_encode($topbar_account_type_label); ?>,
            role: <?php echo json_encode($sidebar_role_label); ?>,
            currentShop: <?php echo json_encode($topbar_current_shop_label); ?>,
            myAccountLink: <?php echo json_encode($topbar_my_account_link); ?>,
            billingLink: <?php echo json_encode($topbar_billing_link); ?>
        };

        function escapeInlineHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Sidebar submenu behavior:
        // - Single click on row toggles submenu
        // - Double click on row navigates to its default module page
        const submenuParents = document.querySelectorAll('a.menu-item-with-submenu');
        const submenuToggleDelayMs = 220;
        const closeAllSubmenus = () => {
            submenuParents.forEach((menuLink) => {
                const linkedSubmenu = menuLink.nextElementSibling;
                if (linkedSubmenu && linkedSubmenu.classList.contains('sidebar-submenu')) {
                    linkedSubmenu.style.display = 'none';
                }
                menuLink.classList.remove('active');
            });
        };

        submenuParents.forEach((item) => {
            const submenu = item.nextElementSibling;
            if (!submenu || !submenu.classList.contains('sidebar-submenu')) {
                return;
            }
            let clickTimer = null;

            item.addEventListener('click', function(e) {
                e.preventDefault();
                if (clickTimer) {
                    clearTimeout(clickTimer);
                    clickTimer = null;
                }

                clickTimer = setTimeout(() => {
                    const isVisible = submenu.style.display === 'block' || window.getComputedStyle(submenu).display === 'block';
                    if (isVisible) {
                        submenu.style.display = 'none';
                        item.classList.remove('active');
                    } else {
                        closeAllSubmenus();
                        submenu.style.display = 'block';
                        item.classList.add('active');
                    }
                    clickTimer = null;
                }, submenuToggleDelayMs);
            });

            item.addEventListener('dblclick', function(e) {
                e.preventDefault();
                if (clickTimer) {
                    clearTimeout(clickTimer);
                    clickTimer = null;
                }

                const href = item.getAttribute('href') || '';
                if (href && href !== '#') {
                    window.location.href = href;
                }
            });
        });


        // Inject Notification Bell
        const topbarContent = document.querySelector('.topbar-content');
        let topbarRight = topbarContent ? topbarContent.querySelector('.topbar-right') : null;
        const themeToggler = document.getElementById('themeToggler');

        // Ensure there is a right-side container so actions stay grouped beside the profile.
        if (!topbarRight && topbarContent) {
            const existingProfile = topbarContent.querySelector('.admin-profile');
            const existingDate = topbarContent.querySelector('.date-display');
            if (existingProfile || existingDate || themeToggler) {
                topbarRight = document.createElement('div');
                topbarRight.className = 'topbar-right';

                if (existingDate && existingDate.parentNode === topbarContent) {
                    topbarRight.appendChild(existingDate);
                }
                if (existingProfile && existingProfile.parentNode === topbarContent) {
                    topbarRight.appendChild(existingProfile);
                }

                topbarContent.appendChild(topbarRight);
            }
        }
        
        if (topbarRight || themeToggler) {
            // Normalize topbar children: move any direct profile/date into the right-side group.
            if (topbarContent && topbarRight) {
                Array.from(topbarContent.children).forEach(child => {
                    if (child.classList && (child.classList.contains('admin-profile') || child.classList.contains('date-display'))) {
                        topbarRight.appendChild(child);
                    }
                });
            }

            let notifWrapper = topbarRight ? topbarRight.querySelector('.admin-header-actions') : null;
            if (!notifWrapper) {
                notifWrapper = document.createElement('div');
                notifWrapper.className = 'admin-header-actions';
            }
            notifWrapper.innerHTML = `
                <!-- Chat Button -->
                <div class="admin-chat-wrapper">
                    <button class="notification-btn topbar-action-btn" id="adminChatBtn" type="button" title="Chat Support" aria-label="Open chat support conversations">
                        <i class="fas fa-comment-dots"></i>
                        <span class="admin-action-label">Messages</span>
                        <span class="notification-badge" id="adminChatBadge" style="display: none;">0</span>
                    </button>
                    <div class="notification-dropdown" id="adminChatDropdown">
                        <div class="notification-header">
                            <span>Messages</span>
                            <a href="chat.php" class="mark-read-btn">View All</a>
                        </div>
                        <div class="notification-list" id="adminChatList"></div>
                    </div>
                </div>
                
                <!-- Notification Button -->
                <div class="admin-notification-wrapper">
                    <button class="notification-btn topbar-action-btn" id="adminNotifBtn" type="button" title="Notifications" aria-label="Open notifications">
                        <i class="fas fa-bell"></i>
                        <span class="admin-action-label">Alerts</span>
                        <span class="notification-badge" id="adminNotifBadge" style="display: none;">0</span>
                    </button>
                    <div class="notification-dropdown" id="adminNotifDropdown">
                        <div class="notification-header">
                            <span>Notifications</span>
                            <span class="mark-read-btn" id="markAllRead">Mark all read</span>
                        </div>
                        <div class="notification-list" id="adminNotifList">
                            <div class="notification-empty">Loading...</div>
                        </div>
                    </div>
                </div>
            `;

            const adminProfile = topbarRight ? topbarRight.querySelector('.admin-profile') : null;

            if (adminProfile) {
                const safeProfileName = String(topbarProfile.name || 'Admin');
                const safeProfileInitials = escapeInlineHtml(topbarProfile.initials || 'A');
                const safeAccountType = escapeInlineHtml(topbarProfileMenu.accountType || 'Account');
                const safeRoleInfo = escapeInlineHtml(topbarProfileMenu.role || 'Admin');
                const safeShop = escapeInlineHtml(topbarProfileMenu.currentShop || 'Lechon Delights');
                const safeMyAccountLink = escapeInlineHtml(topbarProfileMenu.myAccountLink || '../my_account.php');
                const safeBillingLink = escapeInlineHtml(topbarProfileMenu.billingLink || '');
                let profileLabel = adminProfile.querySelector('.admin-profile-name');
                if (!profileLabel) {
                    profileLabel = document.createElement('span');
                    profileLabel.className = 'admin-profile-name';
                    adminProfile.appendChild(profileLabel);
                }
                profileLabel.textContent = safeProfileName;
                adminProfile.setAttribute('role', 'button');
                adminProfile.setAttribute('tabindex', '0');
                adminProfile.setAttribute('aria-haspopup', 'true');
                adminProfile.setAttribute('aria-expanded', 'false');

                adminProfile.querySelectorAll('i').forEach(icon => icon.remove());

                if (!adminProfile.querySelector('.admin-profile-avatar')) {
                    const avatarMarkup = topbarProfile.avatar
                        ? `<img src="${escapeInlineHtml(topbarProfile.avatar)}" alt="${escapeInlineHtml(safeProfileName)} avatar" class="admin-profile-avatar">`
                        : `<span class="admin-profile-avatar admin-profile-fallback">${safeProfileInitials}</span>`;
                    adminProfile.insertAdjacentHTML('afterbegin', avatarMarkup);
                }

                if (!adminProfile.querySelector('.admin-profile-caret')) {
                    adminProfile.insertAdjacentHTML('beforeend', '<i class="fas fa-chevron-down admin-profile-caret" aria-hidden="true"></i>');
                }

                if (!adminProfile.querySelector('.admin-profile-dropdown')) {
                    const billingMarkup = safeBillingLink !== ''
                        ? `<a href="${safeBillingLink}" class="admin-profile-link"><i class="fas fa-wallet"></i><span>Billing</span></a>`
                        : '';
                    adminProfile.insertAdjacentHTML('beforeend', `
                        <div class="admin-profile-dropdown" aria-hidden="true">
                            <div class="admin-profile-dropdown-head">
                                <strong>${escapeInlineHtml(safeProfileName)}</strong>
                                <span>${safeRoleInfo}</span>
                                <small>Managing ${safeShop}</small>
                            </div>
                            <div class="admin-profile-meta">
                                <div class="admin-profile-meta-item"><label>Account Type</label><b>${safeAccountType}</b></div>
                                <div class="admin-profile-meta-item"><label>Role</label><b>${safeRoleInfo}</b></div>
                                <div class="admin-profile-meta-item"><label>Current Shop</label><b>${safeShop}</b></div>
                            </div>
                            <div class="admin-profile-links">
                                <a href="${safeMyAccountLink}" class="admin-profile-link"><i class="fas fa-user-circle"></i><span>My Account</span></a>
                                ${billingMarkup}
                            </div>
                        </div>
                    `);
                }
            }

            const profileDropdown = adminProfile ? adminProfile.querySelector('.admin-profile-dropdown') : null;
            const closeProfileMenu = () => {
                if (adminProfile) {
                    adminProfile.classList.remove('open');
                    adminProfile.setAttribute('aria-expanded', 'false');
                }
                if (profileDropdown) {
                    profileDropdown.setAttribute('aria-hidden', 'true');
                }
            };
            const toggleProfileMenu = () => {
                if (!adminProfile || !profileDropdown) return;
                const isOpen = adminProfile.classList.contains('open');
                if (isOpen) {
                    closeProfileMenu();
                } else {
                    adminProfile.classList.add('open');
                    adminProfile.setAttribute('aria-expanded', 'true');
                    profileDropdown.setAttribute('aria-hidden', 'false');
                }
            };

            if (adminProfile) {
                adminProfile.addEventListener('click', function (event) {
                    event.stopPropagation();
                    toggleProfileMenu();
                });
                adminProfile.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        toggleProfileMenu();
                    } else if (event.key === 'Escape') {
                        closeProfileMenu();
                    }
                });
            }

            // Keep theme button inside the right group and remove spacing overrides from per-page styles.
            if (themeToggler && topbarRight) {
                if (themeToggler.parentNode !== topbarRight) {
                    if (adminProfile) {
                        topbarRight.insertBefore(themeToggler, adminProfile);
                    } else {
                        topbarRight.appendChild(themeToggler);
                    }
                }
                themeToggler.style.margin = '0';
                themeToggler.style.marginLeft = '0';
                themeToggler.style.marginRight = '0';
            }

            // Place message + notification beside profile, then dark mode button.
            if (topbarRight) {
                if (themeToggler && themeToggler.parentNode === topbarRight) {
                    topbarRight.insertBefore(notifWrapper, themeToggler);
                } else if (adminProfile) {
                    topbarRight.insertBefore(notifWrapper, adminProfile);
                } else {
                    topbarRight.appendChild(notifWrapper);
                }
            } else if (themeToggler && themeToggler.parentNode) {
                themeToggler.parentNode.insertBefore(notifWrapper, themeToggler);
            }

            // Notification Logic
            const btn = notifWrapper.querySelector('#adminNotifBtn');
            const dropdown = notifWrapper.querySelector('#adminNotifDropdown');
            const badge = notifWrapper.querySelector('#adminNotifBadge');
            const list = notifWrapper.querySelector('#adminNotifList');
            const markAllBtn = notifWrapper.querySelector('#markAllRead');
            
            // Chat Variables
            const chatBtn = notifWrapper.querySelector('#adminChatBtn');
            const chatDropdown = notifWrapper.querySelector('#adminChatDropdown');
            const chatBadge = notifWrapper.querySelector('#adminChatBadge');
            const chatList = notifWrapper.querySelector('#adminChatList');
            
            // Toggle Dropdown
            if (btn && dropdown) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (chatDropdown) {
                        chatDropdown.classList.remove('show');
                    }
                    if (chatBtn) {
                        chatBtn.classList.remove('is-active');
                    }
                    dropdown.classList.toggle('show');
                    btn.classList.toggle('is-active', dropdown.classList.contains('show'));
                    if (dropdown.classList.contains('show')) {
                        loadNotifications();
                        btn.classList.remove('shaking');
                    }
                });
            }
            
            // Toggle Chat Dropdown
            if (chatBtn && chatDropdown) {
                chatBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    if (btn) {
                        btn.classList.remove('is-active');
                    }
                    chatDropdown.classList.toggle('show');
                    chatBtn.classList.toggle('is-active', chatDropdown.classList.contains('show'));
                    if (chatDropdown.classList.contains('show')) {
                        loadChatConversations();
                    }
                });
            }
            
            // Close on outside click
            document.addEventListener('click', (e) => {
                if (!notifWrapper.contains(e.target)) {
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    if (btn) {
                        btn.classList.remove('is-active');
                    }
                    if (chatDropdown) {
                        chatDropdown.classList.remove('show');
                    }
                    if (chatBtn) {
                        chatBtn.classList.remove('is-active');
                    }
                }
                if (!adminProfile || !adminProfile.contains(e.target)) {
                    closeProfileMenu();
                }
            });
            
            // Mark all read
            if (markAllBtn) {
                markAllBtn.addEventListener('click', () => {
                    fetch('get_notifications.php?action=mark_read', { method: 'POST' })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                loadNotificationCount();
                                loadNotifications();
                            }
                        });
                });
            }
            
            function loadNotificationCount() {
                if (!btn || !badge) return;
                fetch('get_notifications.php?action=count')
                    .then(res => res.json())
                    .then(data => {
                        if (data.count > 0) {
                            badge.style.display = 'block';
                            badge.textContent = data.count > 99 ? '99+' : data.count;
                            // Add shaking animation if not already open
                            if (!dropdown || !dropdown.classList.contains('show')) {
                                btn.classList.add('shaking');
                            }
                        } else {
                            badge.style.display = 'none';
                            btn.classList.remove('shaking');
                        }
                    });
            }
            
            function loadChatConversations() {
                if (!chatList) return;
                
                fetch('../api/get_conversations.php?limit=5')
                    .then(res => res.json())
                    .then(data => {
                        chatList.innerHTML = '';
                        if (!data.success || !data.conversations || data.conversations.length === 0) {
                            chatList.innerHTML = '<div class="notification-empty">No messages</div>';
                            return;
                        }

                        let totalUnread = 0;
                        data.conversations.forEach(conv => {
                            totalUnread += parseInt(conv.unread_count || 0);
                            
                            const item = document.createElement('div');
                            item.className = `notification-item ${parseInt(conv.unread_count) > 0 ? 'unread' : ''}`;
                            
                            // Calculate time ago
                            const date = new Date(conv.last_message_time);
                            const seconds = Math.floor((new Date() - date) / 1000);
                            let timeString = "just now";
                            if (seconds > 86400) timeString = Math.floor(seconds/86400) + "d ago";
                            else if (seconds > 3600) timeString = Math.floor(seconds/3600) + "h ago";
                            else if (seconds > 60) timeString = Math.floor(seconds/60) + "m ago";

                            item.innerHTML = `
                                <div class="notif-icon" style="background: #e3f2fd; color: #1976d2;">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="notif-content">
                                    <div class="notif-title">${escapeInlineHtml(conv.counterpart_name || conv.customer_name || 'Customer')}</div>
                                    <div class="notif-message">${escapeInlineHtml((conv.channel_label ? conv.channel_label + ' · ' : '') + (conv.last_message_preview || 'No messages'))}</div>
                                    <div class="notif-time">${timeString}</div>
                                </div>
                            `;
                            item.onclick = () => window.location.href = `chat.php?conversation_id=${conv.id}`;
                            chatList.appendChild(item);
                        });

                        if (chatBadge) {
                            chatBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                            chatBadge.style.display = totalUnread > 0 ? 'block' : 'none';
                        }
                    }).catch(e => console.error(e));
            }
            
            function loadNotifications() {
                if (!list) return;
                fetch('get_notifications.php?action=get')
                    .then(res => res.json())
                    .then(data => {
                        const notifications = Array.isArray(data) ? data : [];
                        list.innerHTML = '';
                        if (notifications.length === 0) {
                            list.innerHTML = '<div class="notification-empty">No notifications</div>';
                            return;
                        }
                        
                        notifications.forEach(notif => {
                            const item = document.createElement('div');
                            item.className = `notification-item ${notif.is_read == 0 ? 'unread' : ''}`;
                            
                            // Determine icon based on type
                            let icon = 'fa-bell';
                            let color = '#1976d2';
                            let bg = '#e3f2fd';
                            const notifType = String(notif.type || '').toLowerCase();
                            
                            if (notifType.includes('order')) { icon = 'fa-shopping-cart'; color = '#2e7d32'; bg = '#e8f5e9'; }
                            else if (notifType.includes('alert')) { icon = 'fa-exclamation-triangle'; color = '#c62828'; bg = '#ffebee'; }
                            else if (notifType.includes('user')) { icon = 'fa-user'; color = '#ef6c00'; bg = '#fff3e0'; }
                            else if (notifType.includes('franchise')) { icon = 'fa-store'; color = '#7c3aed'; bg = '#f3e8ff'; }
                            
                            item.innerHTML = `
                                <div class="notif-icon" style="color: ${color}; background: ${bg}">
                                    <i class="fas ${icon}"></i>
                                </div>
                                <div class="notif-content">
                                    <div class="notif-title">${escapeInlineHtml(notif.title)}</div>
                                    <div class="notif-message">${escapeInlineHtml(notif.message)}</div>
                                    <div class="notif-time">${escapeInlineHtml(notif.time_ago)}</div>
                                </div>
                            `;
                            
                            item.addEventListener('click', () => {
                                // Mark as read on click
                                if (notif.is_read == 0) {
                                    const formData = new FormData();
                                    formData.append('id', notif.id);
                                    fetch('get_notifications.php?action=mark_read', {
                                        method: 'POST',
                                        body: formData
                                    }).then(() => loadNotificationCount());
                                    item.classList.remove('unread');
                                }
                                
                                // Redirect if related_id exists (basic mapping)
                                if (notif.related_type === 'order') window.location.href = `orders.php?search=${notif.related_id}`;
                                else if (notif.related_type === 'pre_order') window.location.href = `preorders.php?search=${notif.related_id}`;
                                else if (notif.related_type === 'franchise_application') window.location.href = `../super_admin/franchise_applications.php?search=${notif.related_id}`;
                            });
                            
                            list.appendChild(item);
                        });
                    });
            }
            
            // Initial load and polling
            if (btn && badge) {
                loadNotificationCount();
                setInterval(loadNotificationCount, 30000);
            }
            if (chatBtn && chatList) {
                loadChatConversations();
                setInterval(loadChatConversations, 10000);
            }
        }

        window.swalConfirmAction = window.swalConfirmAction || function(options) {
            const config = Object.assign({
                title: 'Are you sure?',
                text: 'Please confirm this action.',
                icon: 'warning',
                confirmButtonText: 'Confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33'
            }, options || {});

            if (typeof Swal !== 'undefined') {
                return Swal.fire({
                    title: config.title,
                    text: config.text,
                    icon: config.icon,
                    showCancelButton: true,
                    confirmButtonColor: config.confirmButtonColor,
                    cancelButtonColor: config.cancelButtonColor,
                    confirmButtonText: config.confirmButtonText,
                    cancelButtonText: config.cancelButtonText
                }).then((result) => !!(result && result.isConfirmed));
            }

            // Fallback: proceed when SweetAlert is unavailable to avoid blocked actions.
            return Promise.resolve(true);
        };

        window.bindSwalConfirmForms = window.bindSwalConfirmForms || function(root) {
            const scope = root || document;
            scope.querySelectorAll('form[data-sw-confirm]').forEach((form) => {
                if (form.dataset.swConfirmBound === '1') return;
                form.dataset.swConfirmBound = '1';
                form.addEventListener('submit', function(event) {
                    if (form.dataset.swConfirmed === '1') {
                        form.dataset.swConfirmed = '0';
                        return;
                    }
                    event.preventDefault();
                    window.swalConfirmAction({
                        title: form.dataset.swConfirmTitle || 'Confirm action?',
                        text: form.dataset.swConfirmText || 'Please confirm this action.',
                        icon: form.dataset.swConfirmIcon || 'warning',
                        confirmButtonText: form.dataset.swConfirmConfirmText || 'Confirm',
                        cancelButtonText: form.dataset.swConfirmCancelText || 'Cancel',
                        confirmButtonColor: form.dataset.swConfirmConfirmColor || '#3085d6',
                        cancelButtonColor: form.dataset.swConfirmCancelColor || '#d33'
                    }).then((confirmed) => {
                        if (confirmed) {
                            form.dataset.swConfirmed = '1';
                            form.submit();
                        }
                    });
                });
            });
        };

        window.bindSwalConfirmForms(document);

        <?php if (!empty($_SESSION['error'])): ?>
            const sessionErrorMessage = <?php echo json_encode($_SESSION['error']); ?>;
            <?php unset($_SESSION['error']); ?>
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Access Denied',
                    text: sessionErrorMessage,
                    confirmButtonText: 'OK'
                });
            } else {
                const fallbackErrorBanner = document.createElement('div');
                fallbackErrorBanner.className = 'alert alert-danger';
                fallbackErrorBanner.style.margin = '16px';
                fallbackErrorBanner.textContent = sessionErrorMessage;
                document.body.prepend(fallbackErrorBanner);
            }
        <?php endif; ?>

        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn && logoutBtn.dataset.swLogoutBound !== '1') {
            logoutBtn.dataset.swLogoutBound = '1';
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.swalConfirmAction({
                    title: 'Log out of the admin panel?',
                    text: 'You will be logged out of the system.',
                    icon: 'warning',
                    confirmButtonText: 'Yes, log out'
                }).then((confirmed) => {
                    if (confirmed) {
                        window.location.href = this.getAttribute('href') || 'logout.php';
                    }
                });
            });
        }
    });
</script>

<!-- Admin Floating Chat Button -->
<?php if (basename($_SERVER['PHP_SELF']) !== 'chat.php'): ?>
<a href="javascript:void(0);" onclick="toggleAdminChatWidget()" class="admin-floating-chat-btn with-timestamp" title="Support Chat">
    <i class="fas fa-comments"></i>
    <span class="admin-floating-chat-timestamp" id="adminFloatingTimestamp"></span>
    <span class="badge" id="adminFloatingBadge" style="display: none;">0</span>
</a>

<style>
.admin-floating-chat-btn {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    background: var(--primary, #c62828);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    box-shadow: 0 4px 15px rgba(198, 40, 40, 0.3);
    z-index: 9999;
    transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.3s;
    text-decoration: none;
}
.admin-floating-chat-btn:hover {
    transform: scale(1.1);
    background: #b71c1c;
    box-shadow: 0 8px 25px rgba(198, 40, 40, 0.5);
    color: white;
}
.admin-floating-chat-btn .badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #ff4757;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    border: 2px solid white;
}

.admin-floating-chat-btn.with-timestamp {
    flex-direction: column;
    height: 70px;
    width: 70px;
    padding: 8px;
}

.admin-floating-chat-timestamp {
    font-size: 10px;
    margin-top: 2px;
    font-weight: 600;
    display: block;
}

/* Admin Chat Widget Window */
.admin-chat-window {
    position: fixed;
    bottom: 100px;
    right: 30px;
    width: 350px;
    height: 500px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 5px 25px rgba(0,0,0,0.2);
    display: none;
    flex-direction: column;
    z-index: 10000;
    overflow: hidden;
    border: 1px solid #e0e0e0;
    animation: slideUp 0.3s ease;
}

.admin-chat-header {
    background: var(--primary, #c62828);
    color: white;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}

.admin-chat-back {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    margin-right: 10px;
    font-size: 1rem;
}

.admin-chat-body {
    flex: 1;
    overflow-y: auto;
    background: #f0f2f5;
    display: flex;
    flex-direction: column;
}

.admin-chat-footer {
    padding: 10px;
    background: white;
    border-top: 1px solid #eee;
}

.widget-conv-item {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
}
.widget-conv-item:hover { background: #f0f0f0; }
.widget-conv-name { font-weight: 600; font-size: 0.9rem; color: #333; }
.widget-conv-preview { font-size: 0.8rem; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.widget-conv-time { font-size: 0.7rem; color: #999; text-align: right; }

.widget-message {
    max-width: 80%;
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 0.9rem;
    word-wrap: break-word;
    margin-bottom: 8px;
}
.widget-message.agent {
    background: var(--primary, #c62828);
    color: white;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
    margin-right: 10px;
}
.widget-message.customer {
    background: #ffffff;
    color: #333;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    margin-left: 10px;
}

@keyframes pulse-red {
    0% {
        box-shadow: 0 0 0 0 rgba(198, 40, 40, 0.7);
    }
    70% {
        box-shadow: 0 0 0 15px rgba(198, 40, 40, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(198, 40, 40, 0);
    }
}
.admin-floating-chat-btn.pulse {
    animation: pulse-red 2s infinite;
}
.admin-floating-chat-btn.pulse:hover {
    animation: none;
}

/* Sidebar Search Styling */
.sidebar-search-container {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border, #eaecf0);
    background: var(--white, #ffffff);
    flex-shrink: 0;
}
.sidebar-search-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.sidebar-search-icon {
    position: absolute;
    left: 11px;
    color: #94a3b8;
    font-size: 12px;
    pointer-events: none;
    transition: color 0.2s ease;
}
.sidebar-search-input {
    width: 100%;
    height: 36px;
    padding: 6px 28px 6px 32px;
    font-size: 13px;
    font-weight: 500;
    color: #1e293b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    outline: none;
    transition: all 0.2s ease;
    font-family: inherit;
}
.sidebar-search-input:focus {
    background: #ffffff;
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}
.sidebar-search-wrap:focus-within .sidebar-search-icon {
    color: #b3261e;
}
.sidebar-search-input::placeholder {
    color: #94a3b8;
    font-weight: 400;
}
.sidebar-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    border: none;
    background: #e2e8f0;
    color: #64748b;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    cursor: pointer;
    padding: 0;
    transition: all 0.15s ease;
}
.sidebar-search-clear:hover {
    background: #cbd5e1;
    color: #1e293b;
}

/* Sidebar Collapsible & Mobile Transitions */
.admin-sidebar {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
}
.admin-content {
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
}
.sidebar-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 1040;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s ease;
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}

/* Desktop Collapsed */
@media (min-width: 769px) {
    .admin-container.sidebar-collapsed .admin-sidebar {
        transform: translateX(-100%) !important;
    }
    .admin-container.sidebar-collapsed .admin-content {
        margin-left: 0 !important;
    }
}

/* Mobile View Active */
@media (max-width: 768px) {
    .admin-sidebar {
        transform: translateX(-100%) !important;
        z-index: 1050 !important;
        box-shadow: 0 0 25px rgba(0, 0, 0, 0.25) !important;
    }
    .admin-sidebar.active,
    .admin-container.sidebar-mobile-active .admin-sidebar {
        transform: translateX(0) !important;
    }
    .admin-content {
        margin-left: 0 !important;
    }
    .admin-container.sidebar-mobile-active .sidebar-backdrop {
        opacity: 1 !important;
        visibility: visible !important;
    }
}

/* Sidebar Dark Mode Theme */
body.dark-mode .admin-sidebar {
    background: #181d26 !important;
    border-right: 1px solid #27303f !important;
    color: #e2e8f0 !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .sidebar-header {
    background: #181d26 !important;
    border-bottom: 1px solid #27303f !important;
}

body.dark-mode .sidebar-header h3 {
    color: #ffffff !important;
    background: linear-gradient(45deg, #ff6b6b, #ffa07a) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
}

body.dark-mode .sidebar-header p {
    color: #94a3b8 !important;
}

body.dark-mode .sidebar-account-badge {
    background: #2a1818 !important;
    color: #fca5a5 !important;
    border: 1px solid #4c1d1d !important;
}

body.dark-mode .sidebar-search-container {
    background: #181d26 !important;
    border-bottom: 1px solid #27303f !important;
}

body.dark-mode .sidebar-search-input {
    background: #222936 !important;
    border: 1px solid #2e3848 !important;
    color: #f1f5f9 !important;
}

body.dark-mode .sidebar-search-input::placeholder {
    color: #64748b !important;
}

body.dark-mode .sidebar-search-input:focus {
    background: #1e2430 !important;
    border-color: #b3261e !important;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.25) !important;
}

body.dark-mode .sidebar-search-icon {
    color: #64748b !important;
}

body.dark-mode .sidebar-search-wrap:focus-within .sidebar-search-icon {
    color: #ef4444 !important;
}

body.dark-mode .sidebar-search-clear {
    background: #2e3848 !important;
    color: #94a3b8 !important;
}

body.dark-mode .sidebar-search-clear:hover {
    background: #3e4c60 !important;
    color: #ffffff !important;
}

body.dark-mode .sidebar-search-empty {
    color: #64748b !important;
}

body.dark-mode .sidebar-menu {
    background: #181d26 !important;
}

body.dark-mode .sidebar-menu::-webkit-scrollbar-track {
    background: #181d26 !important;
}

body.dark-mode .sidebar-menu::-webkit-scrollbar-thumb {
    background: #2e3848 !important;
}

body.dark-mode .sidebar-menu .menu-header {
    color: #64748b !important;
}

body.dark-mode .sidebar-menu .menu-item {
    color: #94a3b8 !important;
}

body.dark-mode .sidebar-menu .menu-item:hover {
    background: #222936 !important;
    color: #ffffff !important;
}

body.dark-mode .sidebar-menu .menu-item.active {
    background: #b3261e !important;
    color: #ffffff !important;
}

body.dark-mode .sidebar-submenu {
    background: #13171f !important;
}

body.dark-mode .sidebar-submenu .submenu-item {
    color: #94a3b8 !important;
}

body.dark-mode .sidebar-submenu .submenu-item:hover {
    background: #222936 !important;
    color: #ffffff !important;
}

body.dark-mode .sidebar-submenu .submenu-item.active {
    color: #fca5a5 !important;
    font-weight: 700 !important;
}

body.dark-mode .sidebar-footer {
    background: #181d26 !important;
    border-top: 1px solid #27303f !important;
}

body.dark-mode .sidebar-footer .admin-user {
    background: #222936 !important;
    color: #e2e8f0 !important;
    border: 1px solid #2e3848 !important;
}

body.dark-mode .sidebar-footer .logout-btn {
    background: #222936 !important;
    border: 1px solid #2e3848 !important;
    color: #e2e8f0 !important;
}

body.dark-mode .sidebar-footer .logout-btn:hover {
    background: #2f1717 !important;
    border-color: #ef4444 !important;
    color: #fca5a5 !important;
}
</style>

<div id="adminChatWidget" class="admin-chat-window">
    <div class="admin-chat-header">
        <div style="display: flex; align-items: center;">
            <button id="adminChatBackBtn" class="admin-chat-back" style="display: none;" onclick="showAdminConversationList()"><i class="fas fa-arrow-left"></i></button>
            <span id="adminChatTitle">Conversations</span>
        </div>
        <button onclick="toggleAdminChatWidget()" style="background:none;border:none;color:white;"><i class="fas fa-times"></i></button>
    </div>
    <div class="admin-chat-body" id="adminChatBody">
        <!-- Content injected via JS -->
    </div>
    <div class="admin-chat-footer" id="adminChatFooter" style="display: none;">
        <form onsubmit="event.preventDefault(); sendWidgetMessage();" style="display: flex; gap: 5px;">
            <input type="text" id="adminWidgetInput" class="form-control form-control-sm" placeholder="Type..." autocomplete="off">
            <button type="submit" class="btn btn-primary btn-sm" style="background: var(--primary, #c62828); border-color: var(--primary, #c62828);"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<script>
(function() {
    // Immediate check to restore theme and sidebar collapsed state without visual flash
    try {
        const savedTheme = localStorage.getItem('theme') || (document.cookie.match(/(?:^|;\s*)theme=([^;]*)/) || [])[1];
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('dark-mode');
            if (document.body) document.body.classList.add('dark-mode');
        } else if (savedTheme === 'light') {
            document.documentElement.classList.remove('dark-mode');
            if (document.body) document.body.classList.remove('dark-mode');
        }
        if (window.innerWidth > 768 && localStorage.getItem('admin_sidebar_collapsed') === '1') {
            const container = document.querySelector('.admin-container');
            if (container) container.classList.add('sidebar-collapsed');
        }
    } catch(e) {}
})();

document.addEventListener('DOMContentLoaded', function() {
    // 0. Universal Sticky Theme Synchronization
    function applyAdminTheme(isDark) {
        if (isDark) {
            document.documentElement.classList.add('dark-mode');
            document.body.classList.add('dark-mode');
        } else {
            document.documentElement.classList.remove('dark-mode');
            document.body.classList.remove('dark-mode');
        }
        try {
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.cookie = "theme=" + (isDark ? "dark" : "light") + "; path=/; max-age=31536000; SameSite=Lax";
        } catch(e) {}

        document.querySelectorAll('#themeToggler, .theme-toggler').forEach(function(btn) {
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
        });
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { isDark: isDark } }));
    }

    const currentSavedTheme = localStorage.getItem('theme') || (document.cookie.match(/(?:^|;\s*)theme=([^;]*)/) || [])[1];
    applyAdminTheme(currentSavedTheme === 'dark');

    // 1. Unified Sidebar Collapse & Mobile Drawer Toggle
    const adminContainer = document.querySelector('.admin-container') || document.body;
    const sidebar = document.getElementById('adminSidebar');
    
    // Ensure Burger Button & Theme Toggler are always present on ANY admin/seller/partner topbar
    const topbars = document.querySelectorAll('.topbar-content, .admin-topbar, header.admin-topbar');
    topbars.forEach(function(topbar) {
        if (!topbar.querySelector('#sidebarToggler, .sidebar-toggler')) {
            const btn = document.createElement('button');
            btn.className = 'sidebar-toggler';
            btn.id = 'sidebarToggler';
            btn.type = 'button';
            btn.title = 'Toggle Navigation';
            btn.setAttribute('aria-label', 'Toggle Navigation');
            btn.innerHTML = '<i class="fas fa-bars"></i>';
            topbar.prepend(btn);
        }

        if (!topbar.querySelector('#themeToggler, .theme-toggler')) {
            const rightWrap = topbar.querySelector('.topbar-right') || topbar.querySelector('.topbar-content') || topbar;
            const tBtn = document.createElement('button');
            tBtn.className = 'theme-toggler';
            tBtn.id = 'themeToggler';
            tBtn.type = 'button';
            tBtn.title = 'Toggle Theme';
            tBtn.innerHTML = (currentSavedTheme === 'dark') ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
            const profile = topbar.querySelector('.admin-profile');
            if (profile && profile.parentNode) {
                profile.parentNode.insertBefore(tBtn, profile);
            } else {
                rightWrap.appendChild(tBtn);
            }
        }
    });

    // Global Event Listener for Theme Toggling
    document.addEventListener('click', function(e) {
        const toggler = e.target.closest('#themeToggler, .theme-toggler');
        if (!toggler) return;
        e.preventDefault();
        e.stopPropagation();
        const isCurrentlyDark = document.body.classList.contains('dark-mode') || document.documentElement.classList.contains('dark-mode');
        applyAdminTheme(!isCurrentlyDark);
    });

    // If no topbar exists on a custom page, create a standalone floating burger button
    if (!document.querySelector('#sidebarToggler, .sidebar-toggler') && sidebar) {
        const floatingBtn = document.createElement('button');
        floatingBtn.className = 'sidebar-toggler floating-sidebar-toggler';
        floatingBtn.id = 'sidebarToggler';
        floatingBtn.type = 'button';
        floatingBtn.title = 'Toggle Navigation';
        floatingBtn.setAttribute('aria-label', 'Toggle Navigation');
        floatingBtn.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(floatingBtn);
    }

    const togglers = document.querySelectorAll('#sidebarToggler, .sidebar-toggler');
    
    // Ensure state matches localStorage on load
    try {
        if (window.innerWidth > 768 && localStorage.getItem('admin_sidebar_collapsed') === '1') {
            adminContainer.classList.add('sidebar-collapsed');
        }
    } catch(e) {}

    // Ensure backdrop element exists
    let backdrop = document.querySelector('.sidebar-backdrop');
    if (!backdrop && adminContainer) {
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        adminContainer.appendChild(backdrop);
    }
    if (backdrop) {
        backdrop.addEventListener('click', function() {
            adminContainer.classList.remove('sidebar-mobile-active');
            if (sidebar) sidebar.classList.remove('active');
        });
    }

    // Attach click listeners to all sidebar togglers (burger buttons)
    function handleToggle(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        if (window.innerWidth > 768) {
            // Desktop: Toggle collapsed state
            const isCollapsed = adminContainer.classList.toggle('sidebar-collapsed');
            try {
                localStorage.setItem('admin_sidebar_collapsed', isCollapsed ? '1' : '0');
            } catch(err) {}
        } else {
            // Mobile: Toggle slide-in drawer
            const isActive = adminContainer.classList.toggle('sidebar-mobile-active');
            if (sidebar) {
                sidebar.classList.toggle('active', isActive);
            }
        }
    }

    togglers.forEach(function(btn) {
        if (btn.dataset.sidebarBound === '1') return;
        btn.dataset.sidebarBound = '1';
        btn.addEventListener('click', handleToggle);
    });

    // On mobile, clicking a navigation link inside the sidebar closes the drawer
    if (sidebar) {
        sidebar.querySelectorAll('a.menu-item, a.submenu-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    adminContainer.classList.remove('sidebar-mobile-active');
                    sidebar.classList.remove('active');
                }
            });
        });
    }

    // 2. Sidebar Navigation Live Search Filter
    const searchInput = document.getElementById('sidebarSearchInput');
    const clearBtn = document.getElementById('sidebarSearchClear');
    const emptyMsg = document.getElementById('sidebarSearchEmpty');
    const menu = document.getElementById('sidebarMenu') || document.querySelector('.sidebar-menu');

    if (searchInput && menu) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            if (clearBtn) {
                clearBtn.style.display = query ? 'flex' : 'none';
            }

            const items = menu.querySelectorAll('li:not(.sidebar-search-empty)');
            let totalMatches = 0;

            if (!query) {
                // Reset all items to default visible state
                items.forEach(function(li) {
                    li.style.display = '';
                    const subLinks = li.querySelectorAll('.submenu-item');
                    subLinks.forEach(function(a) { a.style.display = ''; });
                });
                if (emptyMsg) emptyMsg.style.display = 'none';
                return;
            }

            // Iterate section by section
            let currentHeader = null;
            let currentHeaderHasMatches = false;

            items.forEach(function(li) {
                if (li.classList.contains('menu-header')) {
                    if (currentHeader) {
                        currentHeader.style.display = currentHeaderHasMatches ? '' : 'none';
                    }
                    currentHeader = li;
                    currentHeaderHasMatches = false;
                    return;
                }

                const menuItem = li.querySelector('.menu-item');
                const submenuItems = li.querySelectorAll('.submenu-item');
                let matches = false;

                // Match against main menu item label
                if (menuItem) {
                    const text = menuItem.textContent.toLowerCase();
                    if (text.includes(query)) {
                        matches = true;
                    }
                }

                // Match against submenu items
                if (submenuItems.length > 0) {
                    let subCount = 0;
                    submenuItems.forEach(function(sub) {
                        const subText = sub.textContent.toLowerCase();
                        if (subText.includes(query)) {
                            sub.style.display = '';
                            subCount++;
                            matches = true;
                        } else {
                            sub.style.display = 'none';
                        }
                    });
                    const submenu = li.querySelector('.sidebar-submenu');
                    if (submenu && subCount > 0) {
                        submenu.style.display = 'block';
                    }
                }

                if (matches) {
                    li.style.display = '';
                    currentHeaderHasMatches = true;
                    totalMatches++;
                } else {
                    li.style.display = 'none';
                }
            });

            if (currentHeader) {
                currentHeader.style.display = currentHeaderHasMatches ? '' : 'none';
            }

            if (emptyMsg) {
                emptyMsg.style.display = (totalMatches === 0) ? 'block' : 'none';
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
        }
    }

    let lastAdminUnreadCount = -1;
    
    // Start polling for badge updates
    setInterval(updateAdminFloatingBadge, 10000);
    updateAdminFloatingBadge();

    function playAdminChatSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            
            const ctx = new AudioContext();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.1);
            
            gain.gain.setValueAtTime(0.05, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        } catch (e) {
            // Ignore audio errors
        }
    }

    function formatTimeForButton(dateString) {
        if (!dateString) return '';
        const lastDate = new Date(dateString);
        if (Number.isNaN(lastDate.getTime())) return '';

        const diffMs = Date.now() - lastDate.getTime();
        const diffMinutes = Math.floor(diffMs / 60000);

        if (diffMinutes < 1) return 'now';
        if (diffMinutes < 60) return `${diffMinutes}m`;
        if (diffMinutes < 1440) return `${Math.floor(diffMinutes / 60)}h`;
        return `${Math.floor(diffMinutes / 1440)}d`;
    }

    function updateAdminFloatingBadge() {
        const badge = document.getElementById('adminFloatingBadge');
        const timestampEl = document.getElementById('adminFloatingTimestamp');
        if (!badge) return;
        
        fetch('../api/get_conversations.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.conversations) {
                    let totalUnread = 0;
                    data.conversations.forEach(conv => totalUnread += parseInt(conv.unread_count || 0));
                    
                    if (lastAdminUnreadCount !== -1 && totalUnread > lastAdminUnreadCount) {
                        playAdminChatSound();
                    }
                    lastAdminUnreadCount = totalUnread;
                    
                    badge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                    const hasUnread = totalUnread > 0;
                    badge.style.display = hasUnread ? 'flex' : 'none';
                    
                    const btn = document.querySelector('.admin-floating-chat-btn');
                    if (btn) {
                        if (hasUnread) btn.classList.add('pulse');
                        else btn.classList.remove('pulse');
                    }

                    if (timestampEl && data.conversations.length > 0) {
                        const lastConv = data.conversations[0];
                        if (lastConv.last_message_time) {
                            timestampEl.textContent = formatTimeForButton(lastConv.last_message_time);
                        } else {
                            timestampEl.textContent = '';
                        }
                    } else if (timestampEl) {
                        timestampEl.textContent = '';
                    }
                }
            })
            .catch(err => console.error('Chat badge error:', err));
    }
});

let adminWidgetActiveId = null;
let adminWidgetPollInterval;

function toggleAdminChatWidget() {
    const widget = document.getElementById('adminChatWidget');
    if (!widget) return;

    const isHidden = widget.style.display === 'none' || window.getComputedStyle(widget).display === 'none';
    if (isHidden) {
        widget.style.display = 'flex';
        showAdminConversationList();
    } else {
        widget.style.display = 'none';
        if (adminWidgetPollInterval) clearInterval(adminWidgetPollInterval);
    }
}

function showAdminConversationList() {
    adminWidgetActiveId = null;
    document.getElementById('adminChatBackBtn').style.display = 'none';
    document.getElementById('adminChatTitle').textContent = 'Conversations';
    document.getElementById('adminChatFooter').style.display = 'none';
    
    if (adminWidgetPollInterval) clearInterval(adminWidgetPollInterval);
    
    const body = document.getElementById('adminChatBody');
    body.innerHTML = '<div class="text-center p-3 text-muted">Loading...</div>';
    
    fetch('../api/get_conversations.php')
        .then(r => r.json())
        .then(data => {
            body.innerHTML = '';
            const conversations = (data && data.success && Array.isArray(data.conversations)) ? data.conversations : [];
            if (conversations.length > 0) {
                conversations.forEach(conv => {
                    const div = document.createElement('div');
                    div.className = 'widget-conv-item';
                    // Bold name if unread
                    const nameStyle = conv.unread_count > 0 ? 'font-weight: 800;' : '';
                    div.innerHTML = `
                        <div class="d-flex justify-content-between">
                            <div class="widget-conv-name" style="${nameStyle}">${escapeHtml(conv.counterpart_name || conv.customer_name || 'Guest')}</div>
                            <div class="widget-conv-time">${formatTime(conv.last_message_time)}</div>
                        </div>
                        <div class="widget-conv-preview" style="${nameStyle}">${escapeHtml((conv.channel_label ? conv.channel_label + ' · ' : '') + (conv.last_message_preview || 'No messages'))}</div>
                    `;
                    div.onclick = () => openAdminWidgetChat(conv.id, conv.counterpart_name || conv.customer_name);
                    body.appendChild(div);
                });
            } else {
                body.innerHTML = '<div class="text-center p-3 text-muted">No active conversations</div>';
            }
        })
        .catch(() => {
            body.innerHTML = '<div class="text-center p-3 text-muted">Unable to load conversations</div>';
        });
}

function openAdminWidgetChat(id, name) {
    adminWidgetActiveId = id;
    document.getElementById('adminChatBackBtn').style.display = 'inline-block';
    document.getElementById('adminChatTitle').textContent = name || 'Chat';
    document.getElementById('adminChatFooter').style.display = 'block';
    document.getElementById('adminChatBody').innerHTML = '<div class="text-center p-3 text-muted">Loading messages...</div>';
    
    loadAdminWidgetMessages();
    adminWidgetPollInterval = setInterval(loadAdminWidgetMessages, 3000);
}

function loadAdminWidgetMessages() {
    if (!adminWidgetActiveId) return;
    
    fetch(`../api/get_messages.php?conversation_id=${adminWidgetActiveId}&limit=20`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const body = document.getElementById('adminChatBody');
                const messages = Array.isArray(data.messages) ? data.messages : [];
                // Only clear if loading for the first time or completely replacing
                // Simple approach: replace all content
                body.innerHTML = '<div style="padding: 10px;"></div>'; // spacer
                messages.forEach(msg => {
                    const div = document.createElement('div');
                    div.className = `widget-message ${msg.sender_type === 'customer' ? 'customer' : 'agent'}`;
                    div.innerHTML = `
                        <div>${escapeHtml(msg.message_text)}</div>
                        <div style="font-size: 0.65rem; opacity: 0.7; text-align: right; margin-top: 2px;">
                            ${formatTime(msg.created_at)}
                        </div>
                    `;
                    body.appendChild(div);
                });
                body.scrollTop = body.scrollHeight;
            }
        });
}

async function sendWidgetMessage() {
    const input = document.getElementById('adminWidgetInput');
    if (!input) return;

    const message = input.value.trim();
    if (!message || !adminWidgetActiveId) return;
    
    input.value = '';
    await fetch('../api/send_message.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ conversation_id: adminWidgetActiveId, message: message })
    });
    loadAdminWidgetMessages();
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function formatTime(str) {
    if (!str) return '';
    const d = new Date(str);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}
</script>
<?php endif; ?>

<?php if (!empty($_SESSION['login_success_flash'])): ?>
<style>
.swal2-container {
    background: transparent !important;
    background-color: transparent !important;
}
.swal2-container.swal2-backdrop-show,
.swal2-container.swal2-no-backdrop {
    background: transparent !important;
    background-color: transparent !important;
}
.swal2-container.swal2-top-end,
.swal2-container.swal2-top-right {
    top: 30px !important;
    right: 24px !important;
    left: auto !important;
    bottom: auto !important;
    padding: 0 !important;
    z-index: 99999999 !important;
    overflow: visible !important;
    pointer-events: none !important;
    background: transparent !important;
}
.swal2-popup.swal2-toast {
    pointer-events: auto !important;
    background: #ffffff !important;
    color: #101828 !important;
    border: 1px solid #eaecf0 !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 25px -3px rgba(16, 24, 40, 0.1), 0 4px 6px -2px rgba(16, 24, 40, 0.05) !important;
    padding: 12px 18px !important;
    min-width: 280px !important;
    max-width: 420px !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}
.swal2-popup.swal2-toast .swal2-title {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Outfit', sans-serif !important;
    font-size: 0.92rem !important;
    font-weight: 600 !important;
    color: #101828 !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1.4 !important;
}
.swal2-popup.swal2-toast .swal2-icon {
    margin: 0 !important;
    width: 24px !important;
    height: 24px !important;
    min-width: 24px !important;
    border-color: #027a48 !important;
    color: #027a48 !important;
}
.swal2-popup.swal2-toast .swal2-timer-progress-bar {
    background: #b3261e !important;
    height: 3px !important;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            backdrop: false,
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
        Toast.fire({
            icon: 'success',
            title: <?php echo json_encode($_SESSION['login_success_flash']); ?>
        });
    }
});
</script>
<?php unset($_SESSION['login_success_flash']); ?>
<?php endif; ?>
