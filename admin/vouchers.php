<?php
session_start();
include 'auth.php';
include '../includes/config.php';
require_once '../includes/partner_voucher_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? (int)getFranchiseSellerScopeOwnerId($conn, $current_user_id) : 0;

if (!$is_partner_scoped_admin || $seller_scope_id <= 0) {
    $_SESSION['error'] = 'Voucher management is available only for approved business partner admins.';
    header('Location: index.php');
    exit();
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
                $seller_scope_id,
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
                $_SESSION['error'] = (mysqli_errno($conn) === 1062)
                    ? 'Voucher code already exists for your shop.'
                    : ('Failed to create voucher: ' . mysqli_error($conn));
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $_SESSION['error'] = 'Failed to prepare voucher insert query.';
        }
    } else {
        $_SESSION['error'] = implode("\n", $errors);
    }

    header('Location: vouchers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_voucher'])) {
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    if ($voucher_id > 0) {
        $toggle_sql = "UPDATE partner_vouchers
                       SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW()
                       WHERE id = ? AND seller_id = ?";
        $toggle_stmt = mysqli_prepare($conn, $toggle_sql);
        if ($toggle_stmt) {
            mysqli_stmt_bind_param($toggle_stmt, "ii", $voucher_id, $seller_scope_id);
            mysqli_stmt_execute($toggle_stmt);
            $affected_rows = mysqli_stmt_affected_rows($toggle_stmt);
            $_SESSION[($affected_rows > 0) ? 'success' : 'error'] =
                ($affected_rows > 0) ? 'Voucher status updated.' : 'Voucher not found or unauthorized.';
            mysqli_stmt_close($toggle_stmt);
        } else {
            $_SESSION['error'] = 'Unable to update voucher status right now.';
        }
    } else {
        $_SESSION['error'] = 'Invalid voucher selection.';
    }

    header('Location: vouchers.php');
    exit();
}

$vouchers = [];
$total_redemptions = 0;
$list_stmt = mysqli_prepare($conn, "SELECT id, code, name, discount_type, discount_value, min_order_amount, max_discount_amount, start_at, end_at, usage_limit, usage_count, is_active FROM partner_vouchers WHERE seller_id = ? ORDER BY created_at DESC");
if ($list_stmt) {
    mysqli_stmt_bind_param($list_stmt, "i", $seller_scope_id);
    mysqli_stmt_execute($list_stmt);
    $list_result = mysqli_stmt_get_result($list_stmt);
    if ($list_result) {
        while ($row = mysqli_fetch_assoc($list_result)) {
            $vouchers[] = $row;
            $total_redemptions += (int)($row['usage_count'] ?? 0);
        }
        mysqli_free_result($list_result);
    }
    mysqli_stmt_close($list_stmt);
}

$flash_success = $_SESSION['success'] ?? null;
$flash_error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers & Discounts - Admin Dashboard</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="admin-polish">
<div class="admin-container">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div class="topbar-content">
                <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                <h1>Vouchers &amp; Discounts</h1>
                <div class="admin-profile">
                    <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner Admin')); ?></span>
                    <i class="fas fa-user-circle"></i>
                </div>
            </div>
        </div>
        <div class="admin-main">
            <div class="card mb-3">
                <div class="card-body d-flex flex-wrap gap-4">
                    <div><strong><?php echo number_format(count($vouchers)); ?></strong><br><small>Total Vouchers</small></div>
                    <div><strong><?php echo number_format($total_redemptions); ?></strong><br><small>Total Redemptions</small></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Create Voucher</strong></div>
                <div class="card-body">
                    <form method="POST" id="createVoucherForm">
                        <input type="hidden" name="create_voucher" value="1">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">Code</label><input class="form-control" name="code" maxlength="60" required></div>
                            <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="120" required></div>
                            <div class="col-md-4"><label class="form-label">Discount Type</label><select class="form-select" name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed</option></select></div>
                            <div class="col-md-3"><label class="form-label">Discount Value</label><input class="form-control" type="number" name="discount_value" min="0.01" step="0.01" required></div>
                            <div class="col-md-3"><label class="form-label">Minimum Order</label><input class="form-control" type="number" name="min_order_amount" min="0" step="0.01" value="0"></div>
                            <div class="col-md-3"><label class="form-label">Max Discount</label><input class="form-control" type="number" name="max_discount_amount" min="0" step="0.01" value="0"></div>
                            <div class="col-md-3"><label class="form-label">Usage Limit</label><input class="form-control" type="number" name="usage_limit" min="0" step="1" value="0"></div>
                            <div class="col-md-4"><label class="form-label">Start</label><input class="form-control" type="datetime-local" name="start_at"></div>
                            <div class="col-md-4"><label class="form-label">End</label><input class="form-control" type="datetime-local" name="end_at"></div>
                            <div class="col-md-4"><label class="form-label">Per User Limit</label><input class="form-control" type="number" name="per_user_limit" min="1" step="1" value="1"></div>
                            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                            <div class="col-12 d-flex align-items-center gap-2"><input type="checkbox" id="is_active" name="is_active" checked><label class="mb-0" for="is_active">Set active immediately</label></div>
                        </div>
                        <div class="mt-3 text-end"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Voucher</button></div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Manage Vouchers</strong></div>
                <div class="card-body table-responsive">
                    <table class="table table-striped align-middle">
                        <thead><tr><th>Code</th><th>Discount</th><th>Min</th><th>Usage</th><th>Validity</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php if ($vouchers): foreach ($vouchers as $v): ?>
                            <?php
                                $discount = strtolower((string)$v['discount_type']) === 'percent'
                                    ? rtrim(rtrim(number_format((float)$v['discount_value'], 2), '0'), '.') . '%'
                                    : 'PHP ' . number_format((float)$v['discount_value'], 2);
                                if ((float)$v['max_discount_amount'] > 0) { $discount .= ' (max PHP ' . number_format((float)$v['max_discount_amount'], 2) . ')'; }
                                $usage_limit = (int)$v['usage_limit'];
                                $usage = $usage_limit > 0 ? ((int)$v['usage_count'] . ' / ' . $usage_limit) : ((int)$v['usage_count'] . ' used');
                                $validity = 'No date window';
                                if (!empty($v['start_at']) || !empty($v['end_at'])) {
                                    $start_txt = !empty($v['start_at']) ? date('M j, Y g:i A', strtotime((string)$v['start_at'])) : 'Anytime';
                                    $end_txt = !empty($v['end_at']) ? date('M j, Y g:i A', strtotime((string)$v['end_at'])) : 'No expiry';
                                    $validity = $start_txt . ' to ' . $end_txt;
                                }
                                $active = (int)$v['is_active'] === 1;
                                $form_id = 'toggleVoucherForm' . (int)$v['id'];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string)$v['code']); ?></strong><br><small><?php echo htmlspecialchars((string)$v['name']); ?></small></td>
                                <td><?php echo htmlspecialchars($discount); ?></td>
                                <td>PHP <?php echo number_format((float)$v['min_order_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($usage); ?></td>
                                <td><?php echo htmlspecialchars($validity); ?></td>
                                <td><span class="badge <?php echo $active ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
                                <td>
                                    <form method="POST" id="<?php echo htmlspecialchars($form_id); ?>">
                                        <input type="hidden" name="toggle_voucher" value="1">
                                        <input type="hidden" name="voucher_id" value="<?php echo (int)$v['id']; ?>">
                                        <button type="button" class="btn btn-sm <?php echo $active ? 'btn-outline-danger' : 'btn-outline-success'; ?>" data-form-id="<?php echo htmlspecialchars($form_id); ?>" data-code="<?php echo htmlspecialchars((string)$v['code']); ?>" data-is-active="<?php echo $active ? '1' : '0'; ?>" onclick="confirmVoucherToggle(this)"><?php echo $active ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted">No vouchers yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const flashSuccess = <?php echo json_encode($flash_success, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const flashError = <?php echo json_encode($flash_error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
if (flashSuccess) {
    Swal.fire({ icon: 'success', title: 'Success', text: flashSuccess, timer: 2200, showConfirmButton: false });
} else if (flashError) {
    Swal.fire({ icon: 'error', title: 'Action failed', text: flashError });
}

const createVoucherForm = document.getElementById('createVoucherForm');
if (createVoucherForm) {
    createVoucherForm.addEventListener('submit', function (event) {
        if (this.dataset.confirmed === '1') return;
        event.preventDefault();
        Swal.fire({
            title: 'Create voucher?',
            text: 'Save this voucher now?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, save it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) {
                this.dataset.confirmed = '1';
                this.submit();
            }
        });
    });
}

function confirmVoucherToggle(button) {
    const formId = button.getAttribute('data-form-id');
    const isActive = button.getAttribute('data-is-active') === '1';
    const code = button.getAttribute('data-code') || 'voucher';
    const action = isActive ? 'deactivate' : 'activate';
    Swal.fire({
        title: (isActive ? 'Deactivate' : 'Activate') + ' voucher?',
        text: 'Do you want to ' + action + ' ' + code + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, ' + action + ' it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: isActive ? '#dc2626' : '#16a34a',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.getElementById(formId);
            if (form) form.submit();
        }
    });
}
</script>
</body>
</html>
