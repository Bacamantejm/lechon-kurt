<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner_voucher_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

function getLatestApprovedFranchiseForVoucherPage($conn, $user_id) {
    $query = "SELECT business_name, business_type
              FROM franchise_applications
              WHERE user_id = ? AND status = 'approved'
              ORDER BY reviewed_at DESC, created_at DESC
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $data ?: null;
}

$user_id = (int)$_SESSION['user_id'];
$user_stmt = mysqli_prepare($conn, "SELECT id, account_type, business_name FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($user_stmt, "i", $user_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);
$user_info = $user_result ? mysqli_fetch_assoc($user_result) : null;
mysqli_stmt_close($user_stmt);

$has_seller_access = $user_info && (($user_info['account_type'] ?? '') === 'organization');
if (!$has_seller_access) {
    $approved = getLatestApprovedFranchiseForVoucherPage($conn, $user_id);
    if ($approved) {
        $business_name = trim((string)($approved['business_name'] ?? ''));
        $business_type = trim((string)($approved['business_type'] ?? ''));

        $promote_query = "UPDATE users
                          SET account_type = 'organization',
                              business_name = COALESCE(NULLIF(business_name, ''), ?),
                              business_type = COALESCE(NULLIF(business_type, ''), ?)
                          WHERE id = ?";
        $promote_stmt = mysqli_prepare($conn, $promote_query);
        if ($promote_stmt) {
            mysqli_stmt_bind_param($promote_stmt, "ssi", $business_name, $business_type, $user_id);
            mysqli_stmt_execute($promote_stmt);
            mysqli_stmt_close($promote_stmt);
        }
        $_SESSION['account_type'] = 'organization';
        $has_seller_access = true;
    }
}

if (!$has_seller_access) {
    header('Location: index.php?error=not_approved');
    exit;
}

pvEnsureVoucherSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voucher'])) {
    $code = pvNormalizeVoucherCode($_POST['code'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $discount_type = strtolower(trim((string)($_POST['discount_type'] ?? 'fixed')));
    $discount_value = round((float)($_POST['discount_value'] ?? 0), 2);
    $min_order_amount = max(0, round((float)($_POST['min_order_amount'] ?? 0), 2));
    $max_discount_amount = round((float)($_POST['max_discount_amount'] ?? 0), 2);
    $start_at = trim((string)($_POST['start_at'] ?? ''));
    $end_at = trim((string)($_POST['end_at'] ?? ''));
    $usage_limit = max(0, (int)($_POST['usage_limit'] ?? 0));
    $per_user_limit = max(1, (int)($_POST['per_user_limit'] ?? 1));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (strlen($code) < 3) {
        $errors[] = 'Voucher code must be at least 3 characters.';
    }
    if ($name === '') {
        $errors[] = 'Voucher name is required.';
    }
    if (!in_array($discount_type, ['percent', 'fixed'], true)) {
        $errors[] = 'Invalid discount type selected.';
    }
    if ($discount_value <= 0) {
        $errors[] = 'Discount value must be greater than zero.';
    }
    if ($discount_type === 'percent' && $discount_value > 100) {
        $errors[] = 'Percentage discount cannot exceed 100%.';
    }
    if ($max_discount_amount < 0) {
        $errors[] = 'Maximum discount amount cannot be negative.';
    }

    $start_db = '';
    $end_db = '';
    if ($start_at !== '') {
        $start_ts = strtotime($start_at);
        if ($start_ts === false) {
            $errors[] = 'Invalid voucher start date.';
        } else {
            $start_db = date('Y-m-d H:i:s', $start_ts);
        }
    }
    if ($end_at !== '') {
        $end_ts = strtotime($end_at);
        if ($end_ts === false) {
            $errors[] = 'Invalid voucher end date.';
        } else {
            $end_db = date('Y-m-d H:i:s', $end_ts);
        }
    }
    if ($start_db !== '' && $end_db !== '' && strtotime($end_db) < strtotime($start_db)) {
        $errors[] = 'Voucher end date must be after start date.';
    }

    if (empty($errors)) {
        $insert_sql = "INSERT INTO partner_vouchers
            (seller_id, code, name, description, discount_type, discount_value, min_order_amount, max_discount_amount, start_at, end_at, usage_limit, per_user_limit, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, NOW(), NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        if ($insert_stmt) {
            $max_discount_for_sql = $max_discount_amount > 0 ? $max_discount_amount : 0;
            mysqli_stmt_bind_param(
                $insert_stmt,
                "issssdddssiii",
                $user_id,
                $code,
                $name,
                $description,
                $discount_type,
                $discount_value,
                $min_order_amount,
                $max_discount_for_sql,
                $start_db,
                $end_db,
                $usage_limit,
                $per_user_limit,
                $is_active
            );

            if (mysqli_stmt_execute($insert_stmt)) {
                $_SESSION['success'] = 'Voucher created successfully.';
            } else {
                if (mysqli_errno($conn) === 1062) {
                    $_SESSION['error'] = 'Voucher code already exists for your shop.';
                } else {
                    $_SESSION['error'] = 'Failed to create voucher: ' . mysqli_error($conn);
                }
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $_SESSION['error'] = 'Failed to prepare voucher insert query.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }

    header('Location: seller_vouchers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_voucher'])) {
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    if ($voucher_id > 0) {
        $toggle_sql = "UPDATE partner_vouchers
                       SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW()
                       WHERE id = ? AND seller_id = ?";
        $toggle_stmt = mysqli_prepare($conn, $toggle_sql);
        if ($toggle_stmt) {
            mysqli_stmt_bind_param($toggle_stmt, "ii", $voucher_id, $user_id);
            mysqli_stmt_execute($toggle_stmt);
            if (mysqli_stmt_affected_rows($toggle_stmt) > 0) {
                $_SESSION['success'] = 'Voucher status updated.';
            } else {
                $_SESSION['error'] = 'Voucher not found or unauthorized.';
            }
            mysqli_stmt_close($toggle_stmt);
        } else {
            $_SESSION['error'] = 'Unable to update voucher status right now.';
        }
    }

    header('Location: seller_vouchers.php');
    exit;
}

$vouchers = [];
$list_stmt = mysqli_prepare($conn, "
    SELECT
        id,
        code,
        name,
        description,
        discount_type,
        discount_value,
        min_order_amount,
        max_discount_amount,
        start_at,
        end_at,
        usage_limit,
        usage_count,
        per_user_limit,
        is_active,
        created_at,
        updated_at
    FROM partner_vouchers
    WHERE seller_id = ?
    ORDER BY created_at DESC
");
if ($list_stmt) {
    mysqli_stmt_bind_param($list_stmt, "i", $user_id);
    mysqli_stmt_execute($list_stmt);
    $list_result = mysqli_stmt_get_result($list_stmt);
    if ($list_result) {
        while ($row = mysqli_fetch_assoc($list_result)) {
            $vouchers[] = $row;
        }
        mysqli_free_result($list_result);
    }
    mysqli_stmt_close($list_stmt);
}

$page_title = "My Vouchers | Lechon Delights";
include __DIR__ . '/includes/header.php';
?>

<style>
.voucher-page { min-height: calc(100vh - 180px); padding: 42px 0; background: linear-gradient(135deg, #f6f7fb 0%, #fbfbfd 100%); }
.voucher-shell { background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08); }
.voucher-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; }
.voucher-head h1 { margin: 0; font-size: 1.8rem; color: #1f2937; }
.voucher-head p { margin: 6px 0 0; color: #6b7280; }
.voucher-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-pill { border: none; border-radius: 999px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
.btn-primary-lite { background: #b3261e; color: #fff; }
.btn-secondary-lite { background: #111827; color: #fff; }
.alert { padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; font-weight: 600; }
.alert-success { background: #e6f6ec; color: #14532d; border: 1px solid #a7e3bb; }
.alert-danger { background: #fdeaea; color: #7f1d1d; border: 1px solid #f7b4b4; }
.voucher-create-card { border: 1px solid #eceff3; border-radius: 12px; padding: 16px; margin-bottom: 18px; background: #fafcff; }
.voucher-create-card h2 { margin: 0 0 14px; font-size: 1.1rem; color: #111827; }
.voucher-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.voucher-form-grid .full { grid-column: 1 / -1; }
.voucher-form-grid label { display: block; margin-bottom: 6px; font-size: .86rem; color: #374151; font-weight: 700; }
.voucher-form-grid input, .voucher-form-grid select, .voucher-form-grid textarea { width: 100%; border: 1px solid #d8dee8; border-radius: 9px; padding: 10px 11px; font-size: .92rem; }
.voucher-form-grid textarea { min-height: 72px; resize: vertical; }
.voucher-table-wrap { overflow-x: auto; }
.voucher-table { width: 100%; border-collapse: collapse; min-width: 850px; }
.voucher-table th, .voucher-table td { padding: 12px 10px; border-bottom: 1px solid #edf2f7; text-align: left; vertical-align: top; }
.voucher-table th { font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; }
.status-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 10px; font-size: .76rem; font-weight: 800; }
.status-active { background: #ddf7e7; color: #166534; }
.status-inactive { background: #f3f4f6; color: #4b5563; }
.code-pill { font-weight: 800; letter-spacing: .04em; color: #111827; }
.hint { font-size: .82rem; color: #6b7280; margin-top: 8px; }
@media (max-width: 980px) {
    .voucher-form-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 680px) {
    .voucher-shell { padding: 20px 16px; }
    .voucher-head { flex-direction: column; align-items: flex-start; }
    .voucher-form-grid { grid-template-columns: 1fr; }
}
</style>

<section class="voucher-page">
    <div class="container">
        <div class="voucher-shell">
            <div class="voucher-head">
                <div>
                    <h1><i class="fas fa-tags"></i> My Vouchers</h1>
                    <p>Create partner-owned discount vouchers and apply them at checkout for your customers.</p>
                </div>
                <div class="voucher-actions">
                    <a href="seller_products.php" class="btn-pill btn-secondary-lite"><i class="fas fa-box"></i> Back to Products</a>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="voucher-create-card">
                <h2>Create Voucher</h2>
                <form method="POST">
                    <div class="voucher-form-grid">
                        <div>
                            <label for="code">Code</label>
                            <input type="text" id="code" name="code" maxlength="60" placeholder="WELCOME10" required>
                        </div>
                        <div>
                            <label for="name">Voucher Name</label>
                            <input type="text" id="name" name="name" maxlength="120" placeholder="Welcome Discount" required>
                        </div>
                        <div>
                            <label for="discount_type">Discount Type</label>
                            <select id="discount_type" name="discount_type" required>
                                <option value="percent">Percent (%)</option>
                                <option value="fixed">Fixed Amount (PHP)</option>
                            </select>
                        </div>
                        <div>
                            <label for="discount_value">Discount Value</label>
                            <input type="number" id="discount_value" name="discount_value" min="0.01" step="0.01" required>
                        </div>
                        <div>
                            <label for="min_order_amount">Minimum Order (PHP)</label>
                            <input type="number" id="min_order_amount" name="min_order_amount" min="0" step="0.01" value="0">
                        </div>
                        <div>
                            <label for="max_discount_amount">Max Discount (PHP, optional)</label>
                            <input type="number" id="max_discount_amount" name="max_discount_amount" min="0" step="0.01" value="0">
                        </div>
                        <div>
                            <label for="start_at">Start Date/Time</label>
                            <input type="datetime-local" id="start_at" name="start_at">
                        </div>
                        <div>
                            <label for="end_at">End Date/Time</label>
                            <input type="datetime-local" id="end_at" name="end_at">
                        </div>
                        <div>
                            <label for="usage_limit">Total Usage Limit (0 = unlimited)</label>
                            <input type="number" id="usage_limit" name="usage_limit" min="0" step="1" value="0">
                        </div>
                        <div>
                            <label for="per_user_limit">Per Customer Limit</label>
                            <input type="number" id="per_user_limit" name="per_user_limit" min="1" step="1" value="1">
                        </div>
                        <div class="full">
                            <label for="description">Description (optional)</label>
                            <textarea id="description" name="description" placeholder="Short voucher details shown to your team."></textarea>
                        </div>
                        <div class="full">
                            <label>
                                <input type="checkbox" name="is_active" checked>
                                Set voucher active immediately
                            </label>
                        </div>
                    </div>
                    <div class="hint">Tip: For percentage vouchers, set max discount to prevent too-large discounts on big orders.</div>
                    <div style="margin-top: 12px;">
                        <button type="submit" name="create_voucher" class="btn-pill btn-primary-lite">
                            <i class="fas fa-plus-circle"></i> Create Voucher
                        </button>
                    </div>
                </form>
            </div>

            <div class="voucher-table-wrap">
                <table class="voucher-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Discount</th>
                            <th>Minimum</th>
                            <th>Usage</th>
                            <th>Validity</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($vouchers)): ?>
                        <?php foreach ($vouchers as $voucher): ?>
                            <?php
                            $is_active = (int)$voucher['is_active'] === 1;
                            $discount_type = strtolower((string)$voucher['discount_type']);
                            $discount_label = $discount_type === 'percent'
                                ? rtrim(rtrim(number_format((float)$voucher['discount_value'], 2), '0'), '.') . '%'
                                : 'PHP ' . number_format((float)$voucher['discount_value'], 2);
                            if ((float)$voucher['max_discount_amount'] > 0) {
                                $discount_label .= ' (max PHP ' . number_format((float)$voucher['max_discount_amount'], 2) . ')';
                            }
                            $validity = 'Always active window';
                            if (!empty($voucher['start_at']) || !empty($voucher['end_at'])) {
                                $start_text = !empty($voucher['start_at']) ? date('M j, Y g:i A', strtotime((string)$voucher['start_at'])) : 'Anytime';
                                $end_text = !empty($voucher['end_at']) ? date('M j, Y g:i A', strtotime((string)$voucher['end_at'])) : 'No expiry';
                                $validity = $start_text . ' to ' . $end_text;
                            }
                            $usage_limit = (int)$voucher['usage_limit'];
                            $usage_count = (int)$voucher['usage_count'];
                            $usage_label = $usage_limit > 0 ? $usage_count . ' / ' . $usage_limit : $usage_count . ' used';
                            ?>
                            <tr>
                                <td>
                                    <div class="code-pill"><?php echo htmlspecialchars((string)$voucher['code']); ?></div>
                                    <div><?php echo htmlspecialchars((string)$voucher['name']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($discount_label); ?></td>
                                <td>PHP <?php echo number_format((float)$voucher['min_order_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($usage_label); ?></td>
                                <td><?php echo htmlspecialchars($validity); ?></td>
                                <td>
                                    <span class="status-pill <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="voucher_id" value="<?php echo (int)$voucher['id']; ?>">
                                        <button type="submit" name="toggle_voucher" class="btn-pill <?php echo $is_active ? 'btn-secondary-lite' : 'btn-primary-lite'; ?>">
                                            <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #6b7280;">No vouchers yet. Create your first voucher above.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

