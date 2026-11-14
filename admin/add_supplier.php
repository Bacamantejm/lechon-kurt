<?php
/**
 * admin/add_supplier.php
 * Direct registration portal for wholesale suppliers.
 * Creates an authorized user account with supplier credentials to log into the Supplier Dashboard.
 */
session_start();
include 'auth.php';
include '../includes/config.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $admin_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $admin_id) : null;
$scope_owner_id = $seller_scope_id !== null ? (int)$seller_scope_id : $admin_id;

// Database schema safeguard for suppliers and users tables
function ensureSupplierSchema($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // 1. Ensure user_type enum on users table supports 'supplier'
    $col_res = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'user_type'");
    if ($col_res && ($col_row = mysqli_fetch_assoc($col_res))) {
        $type = (string)($col_row['Type'] ?? '');
        if (strpos($type, "'supplier'") === false) {
            @mysqli_query($conn, "ALTER TABLE `users` MODIFY COLUMN `user_type` ENUM('customer','admin','employee','rider','supplier') NOT NULL DEFAULT 'customer'");
        }
    }

    // 2. Ensure suppliers table has user_id, store_id, created_by, is_active
    $s_cols = mysqli_query($conn, "SHOW COLUMNS FROM `suppliers`");
    $existing = [];
    while ($s_cols && ($row = mysqli_fetch_assoc($s_cols))) {
        $existing[$row['Field']] = true;
    }
    if (!isset($existing['user_id'])) {
        @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `user_id` INT NULL AFTER `id`");
    }
    if (!isset($existing['store_id'])) {
        @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `store_id` INT NULL AFTER `user_id`");
    }
    if (!isset($existing['created_by'])) {
        @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `created_by` INT NULL AFTER `store_id`");
    }
    if (!isset($existing['is_active'])) {
        @mysqli_query($conn, "ALTER TABLE `suppliers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `address`");
    }
}
ensureSupplierSchema($conn);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create New Supplier Account
    if ($action === 'create_supplier') {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $staff_name = trim($_POST['staff_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($supplier_name === '' || $staff_name === '' || $phone === '' || $email === '' || $password === '') {
            $_SESSION['error'] = "All fields (Shop Supplier Name, Owner/Staff Name, Phone Number, Email, and Password) are required.";
            header("Location: add_supplier.php");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Please provide a valid email address.";
            header("Location: add_supplier.php");
            exit;
        }

        if (strlen($password) < 6) {
            $_SESSION['error'] = "Password must be at least 6 characters in length.";
            header("Location: add_supplier.php");
            exit;
        }

        // Check if email already exists in users table
        $chk_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($chk_stmt) {
            mysqli_stmt_bind_param($chk_stmt, "s", $email);
            mysqli_stmt_execute($chk_stmt);
            $chk_res = mysqli_stmt_get_result($chk_stmt);
            if ($chk_res && mysqli_num_rows($chk_res) > 0) {
                $_SESSION['error'] = "An account with the email '{$email}' already exists. Please use a unique email address.";
                mysqli_stmt_close($chk_stmt);
                header("Location: add_supplier.php");
                exit;
            }
            mysqli_stmt_close($chk_stmt);
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // 1. Create User Account in users table
        $u_ins = mysqli_prepare($conn, "
            INSERT INTO users (full_name, email, phone, password, user_type, business_name, is_active)
            VALUES (?, ?, ?, ?, 'supplier', ?, 1)
        ");

        if ($u_ins) {
            mysqli_stmt_bind_param($u_ins, "sssss", $staff_name, $email, $phone, $hashed_password, $supplier_name);
            if (mysqli_stmt_execute($u_ins)) {
                $new_user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($u_ins);

                // 2. Link & provision Supplier in suppliers table
                $s_ins = mysqli_prepare($conn, "
                    INSERT INTO suppliers (user_id, store_id, created_by, name, contact_person, email, phone, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                if ($s_ins) {
                    mysqli_stmt_bind_param($s_ins, "iiissss", $new_user_id, $scope_owner_id, $admin_id, $supplier_name, $staff_name, $email, $phone);
                    mysqli_stmt_execute($s_ins);
                    mysqli_stmt_close($s_ins);
                }

                $_SESSION['success'] = "Supplier account for '{$supplier_name}' created successfully! Credentials: {$email}. They can now log in directly to the Supplier Dashboard.";
                header("Location: add_supplier.php");
                exit;
            } else {
                $_SESSION['error'] = "Failed to create user account: " . mysqli_error($conn);
                mysqli_stmt_close($u_ins);
                header("Location: add_supplier.php");
                exit;
            }
        } else {
            $_SESSION['error'] = "Database error: " . mysqli_error($conn);
            header("Location: add_supplier.php");
            exit;
        }
    }

    // Toggle Supplier Status (Active / Inactive)
    if ($action === 'toggle_status') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $new_status = (int)($_POST['status'] ?? 1);

        $upd_stmt = mysqli_prepare($conn, "UPDATE suppliers SET is_active = ? WHERE id = ?");
        if ($upd_stmt) {
            mysqli_stmt_bind_param($upd_stmt, "ii", $new_status, $supplier_id);
            mysqli_stmt_execute($upd_stmt);
            mysqli_stmt_close($upd_stmt);
        }

        // Also update linked user status if exists
        $upd_u = mysqli_prepare($conn, "UPDATE users u INNER JOIN suppliers s ON s.user_id = u.id SET u.is_active = ? WHERE s.id = ?");
        if ($upd_u) {
            mysqli_stmt_bind_param($upd_u, "ii", $new_status, $supplier_id);
            mysqli_stmt_execute($upd_u);
            mysqli_stmt_close($upd_u);
        }

        $_SESSION['success'] = "Supplier status updated.";
        header("Location: add_supplier.php");
        exit;
    }
}

// Fetch registered suppliers
$suppliers_query = mysqli_query($conn, "
    SELECT s.*, u.last_login 
    FROM suppliers s 
    LEFT JOIN users u ON u.id = s.user_id 
    WHERE (s.store_id = {$scope_owner_id} OR s.created_by = {$admin_id} OR s.store_id IS NULL)
    ORDER BY s.id DESC
");
$suppliers_list = [];
$total_suppliers = 0;
$active_suppliers = 0;
while ($suppliers_query && ($s_row = mysqli_fetch_assoc($suppliers_query))) {
    $suppliers_list[] = $s_row;
    $total_suppliers++;
    if (!empty($s_row['is_active'])) {
        $active_suppliers++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Supplier - Store Operations & Procurement</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary-red: #b3261e;
            --brand-hover: #981b15;
            --page-bg: #f8f9fa;
            --card-container: #ffffff;
            --border-neutral: #eaecf0;
            --primary-ink: #101828;
            --muted-text: #475467;
        }

        body {
            background-color: var(--page-bg);
            color: var(--primary-ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .supplier-mgmt-wrapper {
            padding-bottom: 140px;
        }

        .metric-card-supplier {
            background: #ffffff;
            border: 1px solid var(--border-neutral);
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .metric-card-supplier .metric-label {
            font-size: 13px;
            color: var(--muted-text);
            font-weight: 500;
            margin-bottom: 4px;
        }

        .metric-card-supplier .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-ink);
            line-height: 1.2;
        }

        .metric-card-supplier .metric-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .supplier-form-card {
            background: #ffffff;
            border: 1px solid var(--border-neutral);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            overflow: hidden;
        }

        .supplier-form-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-neutral);
            background: #ffffff;
        }

        .supplier-form-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-ink);
            margin: 0 0 4px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .supplier-form-header p {
            font-size: 13px;
            color: var(--muted-text);
            margin: 0;
        }

        .supplier-form-body {
            padding: 24px;
        }

        .form-label-custom {
            font-size: 13px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 6px;
            display: block;
        }

        .form-label-custom .req-star {
            color: var(--primary-red);
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            color: #98a2b3;
            font-size: 14px;
            z-index: 3;
            pointer-events: none;
        }

        .input-group-custom .form-control {
            padding-left: 42px;
            height: 46px;
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            font-size: 14px;
            color: var(--primary-ink);
            transition: all 0.2s ease;
        }

        .input-group-custom .form-control:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
        }

        .pwd-toggle-btn {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #667085;
            cursor: pointer;
            padding: 4px;
            z-index: 4;
        }

        .btn-submit-supplier {
            background-color: var(--primary-red);
            border: 1px solid var(--primary-red);
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            padding: 12px 24px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            width: 100%;
        }

        .btn-submit-supplier:hover {
            background-color: var(--brand-hover);
            border-color: var(--brand-hover);
            color: #ffffff;
        }

        .supplier-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }

        .supplier-table th {
            background-color: #f9fafb;
            color: var(--muted-text);
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-neutral);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-size: 11px;
        }

        .supplier-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-neutral);
            vertical-align: middle;
            color: #344054;
        }

        .supplier-table tbody tr:hover {
            background-color: #fcfcfd;
        }

        .status-badge-supplier {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background-color: #ecfdf3;
            color: #027a48;
            border: 1px solid #abefc6;
        }

        .status-inactive {
            background-color: #fff1f0;
            color: var(--primary-red);
            border: 1px solid #fee4e2;
        }

        .badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-active .badge-dot { background-color: #12b76a; }
        .status-inactive .badge-dot { background-color: #f04438; }

        .helper-callout {
            background: #eff8ff;
            border: 1px solid #b2ddff;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 13px;
            color: #175cd3;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 24px;
        }

        .helper-callout i {
            font-size: 16px;
            margin-top: 2px;
        }
    </style>
</head>
<body class="admin-polish">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <div>
                        <h1 style="font-size: 20px; font-weight: 700; margin: 0; color: #101828;">Add Supplier</h1>
                        <span style="font-size: 12px; color: #667085;">Wholesale Partner Procurement &amp; Dashboard Account Provisioning</span>
                    </div>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="margin-left: auto;">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main supplier-mgmt-wrapper">
                <!-- Overview Metric Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="metric-card-supplier">
                            <div>
                                <div class="metric-label">Total Suppliers</div>
                                <div class="metric-value"><?php echo number_format($total_suppliers); ?></div>
                            </div>
                            <div class="metric-icon" style="background:#eff8ff; color:#175cd3;">
                                <i class="fas fa-truck-loading"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card-supplier">
                            <div>
                                <div class="metric-label">Active Dashboard Accounts</div>
                                <div class="metric-value"><?php echo number_format($active_suppliers); ?></div>
                            </div>
                            <div class="metric-icon" style="background:#ecfdf3; color:#027a48;">
                                <i class="fas fa-user-check"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card-supplier">
                            <div>
                                <div class="metric-label">Supplier Portal Access</div>
                                <a href="../supplier/dashboard.php" target="_blank" class="btn btn-sm btn-outline-danger mt-1" style="font-size: 12px; font-weight: 600; border-radius: 6px;">
                                    <i class="fas fa-external-link-alt"></i> Preview Portal
                                </a>
                            </div>
                            <div class="metric-icon" style="background:#fff8ef; color:#b54708;">
                                <i class="fas fa-store"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Guidance Callout -->
                <div class="helper-callout">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Direct Supplier Account Creation:</strong> Registering a supplier here instantly generates their login account. The supplier uses their registered email and password to log in at the Supplier Portal, where they view your refill requests, manage wholesale pricing, and fulfill ingredient restocks.
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Column: Add Supplier Account Form -->
                    <div class="col-lg-5">
                        <div class="supplier-form-card">
                            <div class="supplier-form-header">
                                <h3><i class="fas fa-user-plus" style="color:var(--primary-red);"></i> Register Supplier Account</h3>
                                <p>Provide supplier credentials to grant them dashboard access.</p>
                            </div>
                            <div class="supplier-form-body">
                                <form method="POST" action="add_supplier.php" id="addSupplierForm">
                                    <input type="hidden" name="action" value="create_supplier">

                                    <!-- 1. Shop Supplier Name -->
                                    <div class="mb-3">
                                        <label class="form-label-custom" for="supplier_name">
                                            Shop Supplier Name <span class="req-star">*</span>
                                        </label>
                                        <div class="input-group-custom">
                                            <i class="fas fa-store input-icon"></i>
                                            <input type="text" class="form-control" id="supplier_name" name="supplier_name" placeholder="e.g. San Miguel Meat & Poultry Supply" required>
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size: 11px;">The business or company name of the supplier.</div>
                                    </div>

                                    <!-- 2. Name of Owner / Staff -->
                                    <div class="mb-3">
                                        <label class="form-label-custom" for="staff_name">
                                            Name of Owner / Staff <span class="req-star">*</span>
                                        </label>
                                        <div class="input-group-custom">
                                            <i class="fas fa-user input-icon"></i>
                                            <input type="text" class="form-control" id="staff_name" name="staff_name" placeholder="e.g. Jonard Baltazar" required>
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size: 11px;">Primary contact person or authorized representative.</div>
                                    </div>

                                    <!-- 3. Phone Number -->
                                    <div class="mb-3">
                                        <label class="form-label-custom" for="phone">
                                            Phone Number <span class="req-star">*</span>
                                        </label>
                                        <div class="input-group-custom">
                                            <i class="fas fa-phone input-icon"></i>
                                            <input type="tel" class="form-control" id="phone" name="phone" placeholder="e.g. 09055657350" required>
                                        </div>
                                    </div>

                                    <!-- 4. Email (Login Username) -->
                                    <div class="mb-3">
                                        <label class="form-label-custom" for="email">
                                            Email Address (Login Username) <span class="req-star">*</span>
                                        </label>
                                        <div class="input-group-custom">
                                            <i class="fas fa-envelope input-icon"></i>
                                            <input type="email" class="form-control" id="email" name="email" placeholder="e.g. jonard@gmail.com" required autocomplete="off">
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size: 11px;">The username they will use when signing in.</div>
                                    </div>

                                    <!-- 5. Password -->
                                    <div class="mb-4">
                                        <label class="form-label-custom" for="password">
                                            Dashboard Password <span class="req-star">*</span>
                                        </label>
                                        <div class="input-group-custom">
                                            <i class="fas fa-lock input-icon"></i>
                                            <input type="password" class="form-control" id="password" name="password" placeholder="Min. 6 characters" required autocomplete="new-password">
                                            <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('password', this)" title="Toggle Visibility">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size: 11px;">Supplier will use this password to access their dashboard.</div>
                                    </div>

                                    <button type="submit" class="btn-submit-supplier">
                                        <i class="fas fa-user-plus"></i> Create Supplier Account
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Registered Suppliers Directory -->
                    <div class="col-lg-7">
                        <div class="supplier-form-card h-100">
                            <div class="supplier-form-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <h3><i class="fas fa-address-book" style="color:#175cd3;"></i> Registered Suppliers</h3>
                                    <p>Wholesale partners linked to your store operations.</p>
                                </div>
                                <span class="badge rounded-pill bg-light text-dark border px-3 py-2" style="font-size: 12px;">
                                    <?php echo count($suppliers_list); ?> Supplier(s)
                                </span>
                            </div>
                            <div class="table-responsive">
                                <table class="supplier-table">
                                    <thead>
                                        <tr>
                                            <th>Shop / Company</th>
                                            <th>Owner / Contact</th>
                                            <th>Login &amp; Phone</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($suppliers_list)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-5 text-muted">
                                                    <i class="fas fa-truck-loading mb-2" style="font-size: 32px; color: #d0d5dd; display:block;"></i>
                                                    No wholesale suppliers registered yet.<br>Fill out the form on the left to add your first supplier.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($suppliers_list as $s): 
                                                $sid = (int)$s['id'];
                                                $sname = htmlspecialchars($s['name']);
                                                $scontact = htmlspecialchars($s['contact_person'] ?? '—');
                                                $semail = htmlspecialchars($s['email'] ?? '—');
                                                $sphone = htmlspecialchars($s['phone'] ?? '—');
                                                $is_act = !empty($s['is_active']);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?php echo $sname; ?></div>
                                                        <span class="text-muted small" style="font-size: 11px;">ID: #SUP-<?php echo str_pad($sid, 4, '0', STR_PAD_LEFT); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?php echo $scontact; ?></div>
                                                        <span class="text-muted small" style="font-size: 11px;">Authorized Staff</span>
                                                    </td>
                                                    <td>
                                                        <div style="font-size: 12px; font-weight: 500; color: #101828;">
                                                            <i class="fas fa-envelope text-muted me-1"></i> <?php echo $semail; ?>
                                                        </div>
                                                        <div style="font-size: 11px; color: #667085;">
                                                            <i class="fas fa-phone text-muted me-1"></i> <?php echo $sphone; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($is_act): ?>
                                                            <span class="status-badge-supplier status-active">
                                                                <span class="badge-dot"></span> Active
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="status-badge-supplier status-inactive">
                                                                <span class="badge-dot"></span> Inactive
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <form method="POST" action="add_supplier.php" style="display:inline;">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="supplier_id" value="<?php echo $sid; ?>">
                                                            <input type="hidden" name="status" value="<?php echo $is_act ? 0 : 1; ?>">
                                                            <button type="submit" class="btn btn-sm <?php echo $is_act ? 'btn-outline-warning' : 'btn-outline-success'; ?>" style="font-size: 11px; padding: 4px 8px; border-radius: 6px;" title="<?php echo $is_act ? 'Deactivate Account' : 'Activate Account'; ?>">
                                                                <i class="fas fa-<?php echo $is_act ? 'ban' : 'check'; ?>"></i> <?php echo $is_act ? 'Deactivate' : 'Activate'; ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Password visibility toggle
        function togglePasswordVisibility(fieldId, btn) {
            const field = document.getElementById(fieldId);
            const icon = btn.querySelector('i');
            if (!field) return;
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        if (themeToggler) {
            const body = document.body;
            const icon = themeToggler.querySelector('i');
            if (localStorage.getItem('theme') === 'dark') {
                body.classList.add('dark-mode');
                if (icon) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                }
            }
            themeToggler.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                const isDark = body.classList.contains('dark-mode');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            });
        }

        // SweetAlert Notifications
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: <?php echo json_encode($_SESSION['success']); ?>,
                confirmButtonColor: '#b3261e'
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?php echo json_encode($_SESSION['error']); ?>,
                confirmButtonColor: '#b3261e'
            });
        <?php unset($_SESSION['error']); endif; ?>
    </script>
</body>
</html>
