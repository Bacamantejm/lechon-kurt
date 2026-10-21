<?php
/**
 * admin/store_wallet.php
 * Automated Store Wallet Ledger & Remittance Tracking Portal
 * Enforces strict multi-tenant store isolation for shop owners, and aggregate overview for platform admin.
 */

session_start();
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once 'auth.php';
require_once dirname(__DIR__) . '/includes/CommissionAndWalletService.php';

if (function_exists('ensureOrdersTableSchema') && isset($conn) && $conn) {
    ensureOrdersTableSchema($conn);
}

checkAdminAccess();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_role = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$is_super_admin = ($current_role === 'super_admin');
$admin_info = getAdminInfo($conn) ?: [];

$walletService = CommissionAndWalletService::getInstance($conn);
$user_store_id = $walletService->getStoreIdForUser($current_user_id);

// If super admin, allow viewing any store or aggregate
$selected_store_id = $user_store_id;
if ($is_super_admin && isset($_GET['store_id'])) {
    $selected_store_id = (int)$_GET['store_id'];
}

// Retrieve flash message from session if available
$alert_msg = '';
$alert_type = 'info';
if (!empty($_SESSION['wallet_alert']) && is_array($_SESSION['wallet_alert'])) {
    $alert_msg = (string)($_SESSION['wallet_alert']['msg'] ?? '');
    $alert_type = (string)($_SESSION['wallet_alert']['type'] ?? 'info');
    unset($_SESSION['wallet_alert']);
}

// Action: Handle Store Cashout Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cashout'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['wallet_alert'] = [
            'msg' => 'Invalid security token. Please refresh and try again.',
            'type' => 'danger'
        ];
    } else {
        $target_store = $is_super_admin ? (int)($_POST['store_id'] ?? $selected_store_id) : $user_store_id;
        $amount = (float)($_POST['cashout_amount'] ?? 0);
        $channel = sanitizeString($_POST['payout_channel'] ?? 'gcash');
        $acc_no = sanitizeString($_POST['account_number'] ?? '');
        $acc_name = sanitizeString($_POST['account_name'] ?? '');

        $res = $walletService->requestStoreCashout($target_store, $amount, $channel, $acc_no, $acc_name);
        $_SESSION['wallet_alert'] = [
            'msg' => $res['message'],
            'type' => $res['success'] ? 'success' : 'danger'
        ];
    }

    $redir_url = 'store_wallet.php' . ($is_super_admin && $selected_store_id ? '?store_id=' . $selected_store_id : '');
    header('Location: ' . $redir_url);
    exit;
}

// Action: Handle Rider Remittance Recording (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_record_remittance']) && $is_super_admin) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['wallet_alert'] = [
            'msg' => 'Invalid security token.',
            'type' => 'danger'
        ];
    } else {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $remit_amount = (float)($_POST['remittance_amount'] ?? 0);
        $method = sanitizeString($_POST['remittance_channel'] ?? 'gcash');
        $ref_no = sanitizeString($_POST['remittance_ref'] ?? '');
        $notes = sanitizeString($_POST['remittance_notes'] ?? '');

        $res = $walletService->recordRiderRemittance($rider_id, $remit_amount, $method, $ref_no, $notes);
        $_SESSION['wallet_alert'] = [
            'msg' => $res['message'],
            'type' => $res['success'] ? 'success' : 'danger'
        ];
    }

    header('Location: store_wallet.php');
    exit;
}

// Fetch context data based on role
if ($is_super_admin && empty($selected_store_id)) {
    // Platform Admin Aggregate View
    $aggregate = $walletService->getPlatformAggregateFinancials();
    
    // All store wallets list
    $all_stores = [];
    if (function_exists('appTableExists') && appTableExists($conn, 'store_locations')) {
        $has_sw = appTableExists($conn, 'store_wallets');
        $store_sql = $has_sw ? "
            SELECT sl.store_id, sl.store_name, sl.city, sl.owner_user_id,
                   COALESCE(sw.available_balance, 0.00) AS available_balance,
                   COALESCE(sw.total_lifetime_earnings, 0.00) AS total_lifetime_earnings,
                   COALESCE(sw.total_withdrawn, 0.00) AS total_withdrawn,
                   sw.last_payout_at
            FROM store_locations sl
            LEFT JOIN store_wallets sw ON sw.store_id = sl.store_id
            ORDER BY sl.store_name ASC" : "
            SELECT sl.store_id, sl.store_name, sl.city, sl.owner_user_id,
                   0.00 AS available_balance, 0.00 AS total_lifetime_earnings, 0.00 AS total_withdrawn, NULL AS last_payout_at
            FROM store_locations sl
            ORDER BY sl.store_name ASC";
        $all_stores_res = @mysqli_query($conn, $store_sql);
        if ($all_stores_res instanceof mysqli_result) {
            while ($s = mysqli_fetch_assoc($all_stores_res)) {
                $all_stores[] = $s;
            }
        }
    }

    // All rider wallets list
    $all_riders = [];
    if (function_exists('appTableExists') && appTableExists($conn, 'riders')) {
        $has_rw = appTableExists($conn, 'rider_wallets');
        $has_emp = appTableExists($conn, 'employees');
        $has_u = appTableExists($conn, 'users');
        $has_plate = function_exists('appColumnExists') && appColumnExists($conn, 'riders', 'vehicle_plate');

        $name_expr = "COALESCE(";
        if ($has_u) $name_expr .= "u.full_name, ";
        if ($has_emp) $name_expr .= "CONCAT(e.first_name, ' ', e.last_name), ";
        $name_expr .= "'Rider')";

        $phone_expr = "COALESCE(";
        if ($has_u) $phone_expr .= "u.phone, ";
        if ($has_emp) $phone_expr .= "e.phone, ";
        $phone_expr .= "'' )";

        $join_u = $has_u ? "LEFT JOIN users u ON u.id = r.user_id" : "";
        $join_e = $has_emp ? "LEFT JOIN employees e ON e.id = r.employee_id" : "";
        $join_rw = $has_rw ? "LEFT JOIN rider_wallets rw ON rw.rider_id = r.id" : "";

        $plate_col = $has_plate ? "r.vehicle_plate" : "NULL AS vehicle_plate";
        $bal_col = $has_rw ? "COALESCE(rw.available_balance, 0.00) AS available_balance" : "0.00 AS available_balance";
        $debt_col = $has_rw ? "COALESCE(rw.remittance_debt, 0.00) AS remittance_debt" : "0.00 AS remittance_debt";
        $ceiling_col = $has_rw ? "COALESCE(rw.safety_ceiling, 3000.00) AS safety_ceiling" : "3000.00 AS safety_ceiling";
        $status_col = $has_rw ? "COALESCE(rw.status, 'active') AS wallet_status" : "'active' AS wallet_status";
        $remitted_col = $has_rw ? "rw.last_remitted_at" : "NULL AS last_remitted_at";

        $rider_sql = "
            SELECT r.id AS rider_id, r.rider_code, 
                   COALESCE(r.vehicle_type, 'Motorcycle') AS vehicle_type,
                   {$plate_col},
                   {$name_expr} AS rider_name,
                   {$phone_expr} AS rider_phone,
                   {$bal_col},
                   {$debt_col},
                   {$ceiling_col},
                   {$status_col},
                   {$remitted_col}
            FROM riders r
            {$join_rw}
            {$join_u}
            {$join_e}
            ORDER BY " . ($has_rw ? "rw.remittance_debt DESC, " : "") . "r.id ASC";
        $all_riders_res = @mysqli_query($conn, $rider_sql);
        if ($all_riders_res instanceof mysqli_result) {
            while ($r = mysqli_fetch_assoc($all_riders_res)) {
                $all_riders[] = $r;
            }
        }
    }
} else {
    // Store-Scoped View
    $active_store_id = $selected_store_id ?: 1;
    $store_info = $walletService->getStoreDetails($active_store_id) ?: ['store_name' => 'Store #' . $active_store_id, 'city' => 'Active'];
    $wallet = $walletService->getStoreWallet($active_store_id) ?: [
        'available_balance' => 0.00,
        'pending_balance' => 0.00,
        'total_lifetime_earnings' => 0.00,
        'total_withdrawn' => 0.00,
        'payout_method' => 'gcash',
        'payout_account_number' => '',
        'payout_account_name' => ''
    ];
    $splits = $walletService->getStoreOrderSplits($active_store_id, 30) ?: [];
    $transactions = $walletService->getStoreTransactions($active_store_id, 30) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Wallet &amp; Remittance Ledger — Lechon System</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #b3261e;
            --primary-hover: #981b15;
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --ink: #101828;
            --muted: #475467;
            --border: #eaecf0;
            --border-strong: #d0d5dd;
        }
        body { background: var(--bg); color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .admin-main { padding-bottom: 140px !important; }

        /* KPI Cards */
        .wallet-stat-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .wallet-stat-content h3 { font-size: 1.55rem; font-weight: 800; color: var(--ink); margin: 0; letter-spacing: -0.01em; }
        .wallet-stat-content p { font-size: 13px; font-weight: 600; color: var(--muted); margin: 2px 0 0 0; }
        .wallet-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .icon-green { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
        .icon-amber { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
        .icon-red { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
        .icon-blue { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; }

        /* Ledger Tables */
        .ledger-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .ledger-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ledger-header h4 { font-size: 1.1rem; font-weight: 700; color: var(--ink); margin: 0; }
        .table th { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); padding: 12px; }
        .table td { font-size: 13px; padding: 13px 12px; vertical-align: middle; border-bottom: 1px solid var(--border); }

        /* Badge tokens */
        .badge-success-token { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; font-size: 11.5px; font-weight: 700; padding: 4px 8px; border-radius: 6px; }
        .badge-danger-token { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; font-size: 11.5px; font-weight: 700; padding: 4px 8px; border-radius: 6px; }
        .badge-warning-token { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; font-size: 11.5px; font-weight: 700; padding: 4px 8px; border-radius: 6px; }
        .badge-info-token { background: #eff8ff; color: #175cd3; border: 1px solid #b2ddff; font-size: 11.5px; font-weight: 700; padding: 4px 8px; border-radius: 6px; }

        .btn-cta-red { background: var(--primary); color: #fff; font-weight: 600; border-radius: 8px; border: 1px solid var(--primary); padding: 8px 16px; font-size: 13px; }
        .btn-cta-red:hover { background: var(--primary-hover); color: #fff; }
        .btn-outline-neutral { background: #fff; border: 1px solid var(--border-strong); color: #344054; font-weight: 600; border-radius: 8px; padding: 8px 14px; font-size: 13px; text-decoration: none; }
        .btn-outline-neutral:hover { background: #f8fafc; color: var(--ink); }
    </style>
</head>
<body class="admin-page">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>

        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Store Wallet &amp; Ledger</h1>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"><?php echo date('M d, Y'); ?></div>
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <?php if ($alert_msg): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?> alert-dismissible fade show" role="alert">
                        <i class="fas <?php echo $alert_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                        <?php echo htmlspecialchars($alert_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($is_super_admin && empty($selected_store_id)): ?>
                    <!-- ======================================================= -->
                    <!-- PLATFORM ADMIN AGGREGATE VIEW (Super Admin Only) -->
                    <!-- ======================================================= -->
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <div>
                            <h2 class="h4 fw-bold mb-1" style="color: var(--ink);"><i class="fas fa-chart-line text-danger me-2"></i> Platform Revenue &amp; Multi-Store Ledger</h2>
                            <p class="text-muted small mb-0">System-wide automated revenue split monitoring, merchant balances, and rider remittance tracking.</p>
                        </div>
                        <div class="badge-info-token">
                            <i class="fas fa-shield-alt me-1"></i> Aggregate Platform Admin Scope
                        </div>
                    </div>

                    <!-- Platform Financial Stats -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Total Food Gross</p>
                                    <h3>&#8369;<?php echo number_format((float)($aggregate['total_gross_food'] ?? 0), 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-blue"><i class="fas fa-utensils"></i></div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Admin Profit (Platform Cut)</p>
                                    <h3 class="text-danger">&#8369;<?php echo number_format((float)($aggregate['total_platform_profit'] ?? 0), 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-red"><i class="fas fa-percentage"></i></div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Merchant Net Payouts</p>
                                    <h3 class="text-success">&#8369;<?php echo number_format((float)($aggregate['total_merchant_payouts'] ?? 0), 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-green"><i class="fas fa-store"></i></div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Rider Remittance Debt</p>
                                    <h3 class="text-warning">&#8369;<?php echo number_format((float)($aggregate['total_rider_remittance_debt'] ?? 0), 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-amber"><i class="fas fa-hand-holding-usd"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- All Stores Wallet Directory -->
                    <div class="ledger-card mb-4">
                        <div class="ledger-header">
                            <div>
                                <h4><i class="fas fa-store-alt text-danger me-2"></i> Registered Store Wallets Directory</h4>
                                <span class="text-muted small">Multi-tenant merchant balances and withdrawable funds.</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Store Name</th>
                                        <th>Branch Location</th>
                                        <th>Available Balance</th>
                                        <th>Lifetime Net Earnings</th>
                                        <th>Total Withdrawn</th>
                                        <th>Last Payout</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_stores as $st): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($st['store_name']); ?></strong>
                                                <div class="text-muted small">Store ID: #<?php echo $st['store_id']; ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($st['city']); ?></td>
                                            <td>
                                                <strong class="text-success">&#8369;<?php echo number_format((float)$st['available_balance'], 2); ?></strong>
                                            </td>
                                            <td>&#8369;<?php echo number_format((float)$st['total_lifetime_earnings'], 2); ?></td>
                                            <td>&#8369;<?php echo number_format((float)$st['total_withdrawn'], 2); ?></td>
                                            <td>
                                                <?php echo !empty($st['last_payout_at']) ? date('M d, Y', strtotime($st['last_payout_at'])) : '<span class="text-muted">&mdash;</span>'; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="store_wallet.php?store_id=<?php echo (int)$st['store_id']; ?>" class="btn-outline-neutral py-1 px-2" style="font-size: 12px;">
                                                    <i class="fas fa-folder-open me-1"></i> View Store Ledger
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Rider COD Remittance Debt Monitor -->
                    <div class="ledger-card">
                        <div class="ledger-header">
                            <div>
                                <h4><i class="fas fa-motorcycle text-warning me-2"></i> Rider COD Remittance Debt Monitor</h4>
                                <span class="text-muted small">Tracks cash collected in hand. Riders exceeding the ₱3,000 ceiling are blocked from taking orders until remitted.</span>
                            </div>
                            <?php if (!empty($aggregate['blocked_riders_count'])): ?>
                                <span class="badge-danger-token">
                                    <i class="fas fa-exclamation-triangle me-1"></i> <?php echo (int)$aggregate['blocked_riders_count']; ?> Riders Exceeded Safety Ceiling
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Rider</th>
                                        <th>Vehicle &amp; Plate</th>
                                        <th>Remittance Debt</th>
                                        <th>Safety Ceiling</th>
                                        <th>Status</th>
                                        <th>Last Remitted</th>
                                        <th class="text-end">Remittance Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_riders as $rd): ?>
                                        <?php
                                        $debt = (float)$rd['remittance_debt'];
                                        $ceiling = (float)$rd['safety_ceiling'];
                                        $is_exceeded = ($debt >= $ceiling && $debt > 0);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($rd['rider_name']); ?></strong>
                                                <div class="text-muted small"><?php echo htmlspecialchars($rd['rider_code']); ?> &bull; <?php echo htmlspecialchars($rd['rider_phone']); ?></div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($rd['vehicle_type']); ?>
                                                <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars($rd['vehicle_plate'] ?: 'No Plate'); ?></span>
                                            </td>
                                            <td>
                                                <strong class="<?php echo $debt > 0 ? 'text-danger' : 'text-success'; ?>" style="font-size: 14px;">
                                                    &#8369;<?php echo number_format($debt, 2); ?>
                                                </strong>
                                            </td>
                                            <td>&#8369;<?php echo number_format($ceiling, 2); ?></td>
                                            <td>
                                                <?php if ($is_exceeded): ?>
                                                    <span class="badge-danger-token"><i class="fas fa-ban me-1"></i> Blocked (Over Limit)</span>
                                                <?php else: ?>
                                                    <span class="badge-success-token"><i class="fas fa-check-circle me-1"></i> Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($rd['last_remitted_at']) ? date('M d, Y h:i A', strtotime($rd['last_remitted_at'])) : '<span class="text-muted">None</span>'; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($debt > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success fw-bold py-1 px-2" style="font-size: 12px;" onclick="openRemitModal(<?php echo (int)$rd['rider_id']; ?>, '<?php echo addslashes($rd['rider_name']); ?>', <?php echo $debt; ?>)">
                                                        <i class="fas fa-receipt me-1"></i> Record Remit
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small"><i class="fas fa-check"></i> Cleared</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ======================================================= -->
                    <!-- STORE OWNER (MERCHANT) ISOLATED VIEW -->
                    <!-- ======================================================= -->
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h2 class="h4 fw-bold mb-0" style="color: var(--ink);">
                                    <i class="fas fa-wallet text-danger me-2"></i> <?php echo htmlspecialchars($store_info['store_name'] ?? 'My Store'); ?> Wallet
                                </h2>
                                <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:11px;">
                                    Store ID: #<?php echo $active_store_id; ?> &bull; <?php echo htmlspecialchars($store_info['city'] ?? 'Active'); ?>
                                </span>
                            </div>
                            <p class="text-muted small mb-0 mt-1">Multi-Tenant Store Ledger: View withdrawable earnings, per-order commission cuts, and cashout history.</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($is_super_admin): ?>
                                <a href="store_wallet.php" class="btn-outline-neutral"><i class="fas fa-arrow-left me-1"></i> Platform Overview</a>
                            <?php endif; ?>
                            <button type="button" class="btn-cta-red" data-bs-toggle="modal" data-bs-target="#cashoutModal">
                                <i class="fas fa-money-bill-wave me-1"></i> Request Cashout
                            </button>
                        </div>
                    </div>

                    <!-- Store Balance Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-4">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Available Withdrawable Balance</p>
                                    <h3 class="text-success">&#8369;<?php echo number_format((float)$wallet['available_balance'], 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-green"><i class="fas fa-coins"></i></div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Total Lifetime Net Take-Home</p>
                                    <h3>&#8369;<?php echo number_format((float)$wallet['total_lifetime_earnings'], 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-blue"><i class="fas fa-trophy"></i></div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="wallet-stat-card">
                                <div class="wallet-stat-content">
                                    <p>Total Cashouts Withdrawn</p>
                                    <h3 class="text-muted">&#8369;<?php echo number_format((float)$wallet['total_withdrawn'], 2); ?></h3>
                                </div>
                                <div class="wallet-stat-icon icon-amber"><i class="fas fa-receipt"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabbed Sections: Order Payout Splits vs Wallet Transactions -->
                    <div class="ledger-card">
                        <div class="ledger-header">
                            <div>
                                <h4><i class="fas fa-file-invoice-dollar text-danger me-2"></i> Per-Order Revenue Splits (Kinita ng Tindahan)</h4>
                                <span class="text-muted small">Breakdown of food subtotal, platform commission deducted, and net take-home per order.</span>
                            </div>
                            <a href="../store/orders.php" class="btn-outline-neutral py-1 px-3" style="font-size: 12px;">
                                <i class="fas fa-external-link-alt me-1"></i> Store Orders Portal
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Food Subtotal</th>
                                        <th>Platform Fee (15%)</th>
                                        <th>Kinita ng Tindahan</th>
                                        <th>Delivery Snapshot</th>
                                        <th>Payment Mode</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($splits)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">No completed order splits recorded yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($splits as $sp): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($sp['order_number']); ?></strong>
                                                    <div class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($sp['created_at'])); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($sp['customer_name']); ?></td>
                                                <td>&#8369;<?php echo number_format((float)$sp['food_subtotal'], 2); ?></td>
                                                <td class="text-danger">-&#8369;<?php echo number_format((float)$sp['platform_commission_amount'], 2); ?></td>
                                                <td>
                                                    <strong class="text-success" style="font-size: 14px;">
                                                        &#8369;<?php echo number_format((float)$sp['store_net_cut'], 2); ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <i class="fas fa-motorcycle text-muted me-1"></i> <?php echo htmlspecialchars($sp['rider_name'] ?: 'Unassigned'); ?>
                                                    </div>
                                                    <div class="text-muted" style="font-size: 11px;">Delivery Fee: &#8369;<?php echo number_format((float)$sp['delivery_fee'], 2); ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($sp['payment_method'] === 'cod'): ?>
                                                        <span class="badge-warning-token"><i class="fas fa-hand-holding-usd me-1"></i> COD</span>
                                                    <?php else: ?>
                                                        <span class="badge-info-token"><i class="fas fa-credit-card me-1"></i> Online</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="../store/orders.php?id=<?php echo (int)$sp['order_id']; ?>" class="btn-outline-neutral py-1 px-2" style="font-size: 11.5px;">
                                                        <i class="fas fa-eye"></i> Breakdown
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Store Transactions Audit Ledger -->
                    <div class="ledger-card">
                        <div class="ledger-header">
                            <div>
                                <h4><i class="fas fa-history text-secondary me-2"></i> Store Wallet Transaction Ledger</h4>
                                <span class="text-muted small">Immutable credit/debit records and running wallet balance.</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference No.</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th class="text-end">Balance After</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No wallet transactions logged yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $tx): ?>
                                            <?php $is_credit = ((float)$tx['amount'] >= 0); ?>
                                            <tr>
                                                <td class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($tx['created_at'])); ?></td>
                                                <td><code><?php echo htmlspecialchars($tx['reference_no']); ?></code></td>
                                                <td>
                                                    <?php if ($tx['type'] === 'order_credit'): ?>
                                                        <span class="badge-success-token"><i class="fas fa-plus-circle me-1"></i> Order Credit</span>
                                                    <?php elseif ($tx['type'] === 'cashout_debit'): ?>
                                                        <span class="badge-danger-token"><i class="fas fa-arrow-down me-1"></i> Cashout</span>
                                                    <?php else: ?>
                                                        <span class="badge-info-token"><?php echo htmlspecialchars($tx['type']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                                <td>
                                                    <strong class="<?php echo $is_credit ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $is_credit ? '+' : ''; ?>&#8369;<?php echo number_format((float)$tx['amount'], 2); ?>
                                                    </strong>
                                                </td>
                                                <td class="text-end fw-bold text-dark">&#8369;<?php echo number_format((float)$tx['balance_after'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal: Request Cashout -->
    <div class="modal fade" id="cashoutModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: 1px solid var(--border); overflow: hidden;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                    <input type="hidden" name="action_cashout" value="1">
                    <input type="hidden" name="store_id" value="<?php echo (int)($active_store_id ?? $user_store_id); ?>">

                    <div class="modal-header" style="border-bottom: 1px solid var(--border); background: #ffffff;">
                        <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: var(--ink);">
                            <i class="fas fa-money-bill-wave text-success me-2"></i> Request Store Cashout
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="p-3 rounded mb-3" style="background: #ecfdf3; border: 1px solid #abefc6;">
                            <span class="small text-muted d-block">Available Withdrawable Balance</span>
                            <strong style="font-size: 1.45rem; color: #027a48;">
                                &#8369;<?php echo number_format((float)($wallet['available_balance'] ?? 0), 2); ?>
                            </strong>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Withdrawal Amount (&#8369;)</label>
                            <input type="number" step="0.01" min="1" max="<?php echo max(1, (float)($wallet['available_balance'] ?? 0)); ?>" name="cashout_amount" class="form-control" placeholder="e.g. 500.00" required <?php echo ((float)($wallet['available_balance'] ?? 0) < 1 ? 'disabled' : ''); ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Payout Channel</label>
                            <select name="payout_channel" class="form-select" required <?php echo ((float)($wallet['available_balance'] ?? 0) < 1 ? 'disabled' : ''); ?>>
                                <option value="gcash">GCash (Instant E-Wallet)</option>
                                <option value="maya">Maya</option>
                                <option value="bank_transfer">Direct Bank Transfer (BDO / BPI / UnionBank)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Account Number / Mobile Number</label>
                            <input type="text" name="account_number" class="form-control" placeholder="e.g. 09171234567" required <?php echo ((float)($wallet['available_balance'] ?? 0) < 1 ? 'disabled' : ''); ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Account Registered Name</label>
                            <input type="text" name="account_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required <?php echo ((float)($wallet['available_balance'] ?? 0) < 1 ? 'disabled' : ''); ?>>
                        </div>
                    </div>

                    <div class="modal-footer" style="border-top: 1px solid var(--border); background: #f8fafc;">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm text-white fw-bold px-3" style="background: var(--primary);" <?php echo ((float)($wallet['available_balance'] ?? 0) < 1 ? 'disabled' : ''); ?>>
                            <i class="fas fa-check-circle me-1"></i> Submit Cashout
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Record Rider Remittance (Admin Only) -->
    <?php if ($is_super_admin): ?>
        <div class="modal fade" id="remitModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 16px; border: 1px solid var(--border); overflow: hidden;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <input type="hidden" name="action_record_remittance" value="1">
                        <input type="hidden" name="rider_id" id="modalRiderId" value="0">

                        <div class="modal-header" style="border-bottom: 1px solid var(--border); background: #ffffff;">
                            <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: var(--ink);">
                                <i class="fas fa-receipt text-success me-2"></i> Record Rider Remittance
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body p-4">
                            <div class="p-3 rounded mb-3" style="background: #fffbeb; border: 1px solid #fedf89;">
                                <div class="small text-muted">Rider: <strong id="modalRiderName">Rider</strong></div>
                                <div class="small text-muted mt-1">Outstanding COD Debt: <strong class="text-danger" id="modalRiderDebt">&#8369;0.00</strong></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Remittance Amount (&#8369;)</label>
                                <input type="number" step="0.01" min="1" name="remittance_amount" id="modalRemitAmount" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Payment Channel</label>
                                <select name="remittance_channel" class="form-select" required>
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cash_in_person">Cash in Person (Office Counter)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Reference Number / Proof ID</label>
                                <input type="text" name="remittance_ref" class="form-control" placeholder="e.g. GCash Ref No. 123456789">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Internal Notes</label>
                                <textarea name="remittance_notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                            </div>
                        </div>

                        <div class="modal-footer" style="border-top: 1px solid var(--border); background: #f8fafc;">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-success fw-bold px-3">
                                <i class="fas fa-check-circle me-1"></i> Verify &amp; Deduct Debt
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <script>
            function openRemitModal(riderId, riderName, debt) {
                document.getElementById('modalRiderId').value = riderId;
                document.getElementById('modalRiderName').textContent = riderName;
                document.getElementById('modalRiderDebt').textContent = '₱' + Number(debt).toLocaleString('en-US', { minimumFractionDigits: 2 });
                document.getElementById('modalRemitAmount').value = Number(debt).toFixed(2);
                new bootstrap.Modal(document.getElementById('remitModal')).show();
            }
        </script>
    <?php endif; ?>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebarToggler = document.getElementById('sidebarToggler');
        if (sidebarToggler) {
            sidebarToggler.addEventListener('click', () => {
                document.querySelector('.admin-container').classList.toggle('sidebar-collapsed');
            });
        }
    </script>
</body>
</html>
