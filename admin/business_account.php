<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;

$shop_sub_details = SubscriptionAccessService::getShopSubscriptionDetails($conn, $current_user_id);
$can_change_store_name = !empty($shop_sub_details['can_change_store_name']);
$can_change_store_logo = !empty($shop_sub_details['can_change_store_logo']);
$can_change_store_banner = !empty($shop_sub_details['can_change_store_banner']) || !empty($shop_sub_details['can_change_store_logo']);

if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: Business account settings are only available to approved business partner shops.');
}
if (!$is_partner_owner_admin) {
    denyAdminAccess('Access denied: Only the partner owner can edit business account details.');
}

function baColumnExists(mysqli $conn, string $column_name): bool {
    if (function_exists('userAccountControlColumnExists')) {
        return userAccountControlColumnExists($conn, $column_name);
    }
    return true;
}

function baNormalizeMediaPath(string $value): string {
    $path = trim(str_replace('\\', '/', $value));
    if ($path === '') {
        return '';
    }
    if (!preg_match('#^uploads/(profile_pictures|business_logos|business_banners)/[A-Za-z0-9._-]+$#', $path)) {
        return '';
    }
    return $path;
}

function baDeleteMediaIfExists(string $relative_path): void {
    $normalized = baNormalizeMediaPath($relative_path);
    if ($normalized === '') {
        return;
    }
    $absolute = __DIR__ . '/../' . $normalized;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function baUploadBusinessLogo(int $user_id, array $file): array {
    $error_code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error_code !== UPLOAD_ERR_OK) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'message' => 'Business logo is too large. Maximum file size is 5MB.'];
            case UPLOAD_ERR_PARTIAL:
                return ['success' => false, 'message' => 'Business logo upload was interrupted. Please try again.'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'message' => 'No logo file was selected.'];
            default:
                return ['success' => false, 'message' => 'Unable to upload business logo right now.'];
        }
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return ['success' => false, 'message' => 'Invalid business logo upload source.'];
    }

    $max_size = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max_size) {
        return ['success' => false, 'message' => 'Business logo is too large. Maximum file size is 5MB.'];
    }

    $allowed_mime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return ['success' => false, 'message' => 'Unable to validate uploaded file type.'];
    }
    $mime_type = (string)finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    if (!isset($allowed_mime[$mime_type])) {
        return ['success' => false, 'message' => 'Invalid logo format. Please use JPG, PNG, or WEBP.'];
    }
    if (!@getimagesize($tmp_name)) {
        return ['success' => false, 'message' => 'Uploaded logo is not a valid image file.'];
    }

    $upload_dir = __DIR__ . '/../uploads/business_logos';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'message' => 'Unable to prepare business logo upload directory.'];
    }

    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $token = str_replace('.', '', uniqid((string)$user_id, true));
    }
    $extension = $allowed_mime[$mime_type];
    $filename = 'business_logo_' . $user_id . '_' . date('YmdHis') . '_' . $token . '.' . $extension;
    $absolute_target = $upload_dir . '/' . $filename;
    $relative_target = 'uploads/business_logos/' . $filename;

    if (!move_uploaded_file($tmp_name, $absolute_target)) {
        return ['success' => false, 'message' => 'Failed to save the uploaded business logo. Please try again.'];
    }

    return ['success' => true, 'path' => $relative_target];
}

function baUploadBusinessBanner(int $user_id, array $file): array {
    global $conn;
    if (function_exists('baUploadBusinessBannerHelper')) {
        return baUploadBusinessBannerHelper($conn, $user_id, $file);
    }
    $error_code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error_code !== UPLOAD_ERR_OK) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'message' => 'Store background picture is too large. Maximum file size is 8MB.'];
            case UPLOAD_ERR_PARTIAL:
                return ['success' => false, 'message' => 'Background picture upload was interrupted. Please try again.'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'message' => 'No background picture file was selected.'];
            default:
                return ['success' => false, 'message' => 'Unable to upload store background picture right now.'];
        }
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return ['success' => false, 'message' => 'Invalid background picture upload source.'];
    }

    $max_size = 8 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max_size) {
        return ['success' => false, 'message' => 'Store background picture is too large. Maximum file size is 8MB.'];
    }

    $allowed_mime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return ['success' => false, 'message' => 'Unable to validate uploaded file type.'];
    }
    $mime_type = (string)finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    if (!isset($allowed_mime[$mime_type])) {
        return ['success' => false, 'message' => 'Invalid background format. Please use JPG, PNG, or WEBP.'];
    }
    if (!@getimagesize($tmp_name)) {
        return ['success' => false, 'message' => 'Uploaded background picture is not a valid image file.'];
    }

    $upload_dir = __DIR__ . '/../uploads/business_banners';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'message' => 'Unable to prepare store background upload directory.'];
    }

    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $token = str_replace('.', '', uniqid((string)$user_id, true));
    }
    $extension = $allowed_mime[$mime_type];
    $filename = 'business_banner_' . $user_id . '_' . date('YmdHis') . '_' . $token . '.' . $extension;
    $absolute_target = $upload_dir . '/' . $filename;
    $relative_target = 'uploads/business_banners/' . $filename;

    if (!move_uploaded_file($tmp_name, $absolute_target)) {
        return ['success' => false, 'message' => 'Failed to save the uploaded background picture. Please try again.'];
    }

    return ['success' => true, 'path' => $relative_target];
}

function baBindParams(mysqli_stmt $stmt, string $types, array $values): bool {
    if ($types === '') {
        return true;
    }
    $bind_args = [$stmt, $types];
    foreach ($values as $index => $value) {
        $bind_args[] = &$values[$index];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $bind_args);
}

function baFetchOwnerBusinessAccount(mysqli $conn, int $owner_user_id, array $columns): ?array {
    if ($owner_user_id <= 0) {
        return null;
    }

    $select_columns = ['id', 'email', 'full_name', 'account_type', 'created_at'];
    foreach ($columns as $col) {
        if (!in_array($col, $select_columns, true)) {
            $select_columns[] = $col;
        }
    }

    $query = "SELECT " . implode(', ', $select_columns) . " FROM users WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $owner_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

if (function_exists('ensureUserStoreBannerSchema')) {
    ensureUserStoreBannerSchema($conn);
}
$has_profile_image_col = baColumnExists($conn, 'profile_image');
$has_business_logo_col = baColumnExists($conn, 'business_logo');
$has_business_banner_col = baColumnExists($conn, 'business_banner');
if (!$has_business_banner_col) {
    @mysqli_query($conn, "ALTER TABLE users ADD COLUMN business_banner VARCHAR(255) NULL DEFAULT NULL AFTER business_logo");
    $has_business_banner_col = baColumnExists($conn, 'business_banner');
}
$has_business_name_col = baColumnExists($conn, 'business_name');
$has_business_type_col = baColumnExists($conn, 'business_type');
$has_business_registration_col = baColumnExists($conn, 'business_registration');
$has_tax_id_col = baColumnExists($conn, 'tax_id');
$has_website_col = baColumnExists($conn, 'website');
$has_phone_col = baColumnExists($conn, 'phone');
$has_address_col = baColumnExists($conn, 'address');
$has_updated_at_col = baColumnExists($conn, 'updated_at');

$logo_column = $has_business_logo_col ? 'business_logo' : ($has_profile_image_col ? 'profile_image' : '');
$editable_columns = array_values(array_filter([
    $has_business_name_col ? 'business_name' : null,
    $has_business_type_col ? 'business_type' : null,
    $has_business_registration_col ? 'business_registration' : null,
    $has_tax_id_col ? 'tax_id' : null,
    $has_website_col ? 'website' : null,
    $has_phone_col ? 'phone' : null,
    $has_address_col ? 'address' : null,
    $logo_column !== '' ? $logo_column : null,
    $has_business_banner_col ? 'business_banner' : null
]));

$business_user = baFetchOwnerBusinessAccount($conn, (int)$seller_scope_id, $editable_columns);
if (!$business_user) {
    $_SESSION['error'] = 'Unable to load business account details.';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_business_account'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: business_account.php');
        exit;
    }

    $business_name = trim((string)($_POST['business_name'] ?? ''));
    $business_type = trim((string)($_POST['business_type'] ?? ''));
    $business_registration = trim((string)($_POST['business_registration'] ?? ''));
    $tax_id = trim((string)($_POST['tax_id'] ?? ''));
    $website_input = trim((string)($_POST['website'] ?? ''));
    $phone_input = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    $errors = [];
    if ($has_business_name_col && $business_name === '') {
        $errors[] = 'Business name is required.';
    }
    if ($has_business_name_col && !$can_change_store_name) {
        $orig_name = trim((string)($business_user['business_name'] ?? ''));
        if ($business_name !== $orig_name) {
            $errors[] = 'Changing Store Name is restricted. Please upgrade to a Starter, Growth, or Pro Subscription Plan.';
            $business_name = $orig_name;
        }
    }
    if (strlen($business_name) > 200) {
        $errors[] = 'Business name must be 200 characters or less.';
    }
    if (strlen($business_type) > 100) {
        $errors[] = 'Business type must be 100 characters or less.';
    }
    if (strlen($business_registration) > 100) {
        $errors[] = 'Business registration must be 100 characters or less.';
    }
    if (strlen($tax_id) > 50) {
        $errors[] = 'Tax ID must be 50 characters or less.';
    }
    if (strlen($address) > 350) {
        $errors[] = 'Business address must be 350 characters or less.';
    }

    $phone = preg_replace('/\D+/', '', $phone_input);
    if ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 15)) {
        $errors[] = 'Phone number must contain 10 to 15 digits.';
    }

    $website = '';
    if ($website_input !== '') {
        $candidate = $website_input;
        if (!preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }
        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please provide a valid website URL.';
        } elseif (strlen($candidate) > 200) {
            $errors[] = 'Website URL must be 200 characters or less.';
        } else {
            $website = $candidate;
        }
    }

    $current_logo_path = $logo_column !== '' ? baNormalizeMediaPath((string)($business_user[$logo_column] ?? '')) : '';
    $next_logo_path = $current_logo_path;
    $uploaded_new_logo = false;
    $remove_logo = isset($_POST['remove_business_logo']) && (string)$_POST['remove_business_logo'] === '1';

    if ($logo_column !== '') {
        $logo_file = $_FILES['business_logo'] ?? null;
        $has_logo_upload = is_array($logo_file) && (int)($logo_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$can_change_store_logo && ($remove_logo || $has_logo_upload)) {
            $errors[] = 'Changing or updating Store Logo is restricted. Please upgrade to a Starter, Growth, or Pro Subscription Plan.';
        } else {
            if ($remove_logo) {
                $next_logo_path = '';
            }

            if ($has_logo_upload) {
                $upload_result = baUploadBusinessLogo((int)$seller_scope_id, $logo_file);
                if (!($upload_result['success'] ?? false)) {
                    $errors[] = (string)($upload_result['message'] ?? 'Unable to upload business logo.');
                } else {
                    $next_logo_path = (string)$upload_result['path'];
                    $uploaded_new_logo = true;
                }
            }
        }
    }

    $current_banner_path = $has_business_banner_col ? baNormalizeMediaPath((string)($business_user['business_banner'] ?? '')) : '';
    $next_banner_path = $current_banner_path;
    $uploaded_new_banner = false;
    $remove_banner = isset($_POST['remove_business_banner']) && (string)$_POST['remove_business_banner'] === '1';

    if ($has_business_banner_col) {
        $banner_file = $_FILES['business_banner'] ?? null;
        $has_banner_upload = is_array($banner_file) && (int)($banner_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$can_change_store_banner && ($remove_banner || $has_banner_upload)) {
            $errors[] = 'Changing or updating Store Background Picture is restricted. Please upgrade to a Starter, Growth, or Pro Subscription Plan.';
        } else {
            if ($remove_banner) {
                $next_banner_path = '';
            }

            if ($has_banner_upload) {
                $upload_banner_result = baUploadBusinessBanner((int)$seller_scope_id, $banner_file);
                if (!($upload_banner_result['success'] ?? false)) {
                    $errors[] = (string)($upload_banner_result['message'] ?? 'Unable to upload store background picture.');
                } else {
                    $next_banner_path = (string)$upload_banner_result['path'];
                    $uploaded_new_banner = true;
                }
            }
        }
    }

    if (empty($errors)) {
        $update_fields = [];
        $bind_values = [];
        $bind_types = '';

        if ($has_business_name_col) {
            $update_fields[] = "business_name = ?";
            $bind_values[] = $business_name;
            $bind_types .= 's';
        }
        if ($has_business_type_col) {
            $update_fields[] = "business_type = ?";
            $bind_values[] = $business_type;
            $bind_types .= 's';
        }
        if ($has_business_registration_col) {
            $update_fields[] = "business_registration = ?";
            $bind_values[] = $business_registration;
            $bind_types .= 's';
        }
        if ($has_tax_id_col) {
            $update_fields[] = "tax_id = ?";
            $bind_values[] = $tax_id;
            $bind_types .= 's';
        }
        if ($has_website_col) {
            $update_fields[] = "website = ?";
            $bind_values[] = $website;
            $bind_types .= 's';
        }
        if ($has_phone_col) {
            $update_fields[] = "phone = ?";
            $bind_values[] = $phone;
            $bind_types .= 's';
        }
        if ($has_address_col) {
            $update_fields[] = "address = ?";
            $bind_values[] = $address;
            $bind_types .= 's';
        }
        if ($logo_column !== '') {
            $update_fields[] = "{$logo_column} = ?";
            $bind_values[] = $next_logo_path;
            $bind_types .= 's';
        }
        if ($has_business_banner_col) {
            $update_fields[] = "business_banner = ?";
            $bind_values[] = $next_banner_path;
            $bind_types .= 's';
        }
        if ($has_updated_at_col) {
            $update_fields[] = "updated_at = NOW()";
        }

        if (empty($update_fields)) {
            if ($uploaded_new_logo && $next_logo_path !== '') {
                baDeleteMediaIfExists($next_logo_path);
            }
            if ($uploaded_new_banner && $next_banner_path !== '') {
                baDeleteMediaIfExists($next_banner_path);
            }
            $_SESSION['error'] = 'No editable business account fields are available in this database schema.';
            header('Location: business_account.php');
            exit;
        }

        $update_query = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $update_query);
        if (!$stmt) {
            if ($uploaded_new_logo && $next_logo_path !== '') {
                baDeleteMediaIfExists($next_logo_path);
            }
            if ($uploaded_new_banner && $next_banner_path !== '') {
                baDeleteMediaIfExists($next_banner_path);
            }
            $_SESSION['error'] = 'Unable to prepare business account update request.';
            header('Location: business_account.php');
            exit;
        }

        $bind_values[] = (int)$seller_scope_id;
        $bind_types .= 'i';
        if (!baBindParams($stmt, $bind_types, $bind_values)) {
            mysqli_stmt_close($stmt);
            if ($uploaded_new_logo && $next_logo_path !== '') {
                baDeleteMediaIfExists($next_logo_path);
            }
            if ($uploaded_new_banner && $next_banner_path !== '') {
                baDeleteMediaIfExists($next_banner_path);
            }
            $_SESSION['error'] = 'Unable to bind business account update values.';
            header('Location: business_account.php');
            exit;
        }

        $execute_ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$execute_ok) {
            if ($uploaded_new_logo && $next_logo_path !== '') {
                baDeleteMediaIfExists($next_logo_path);
            }
            if ($uploaded_new_banner && $next_banner_path !== '') {
                baDeleteMediaIfExists($next_banner_path);
            }
            $_SESSION['error'] = 'Failed to save business account details. Please try again.';
            header('Location: business_account.php');
            exit;
        }

        if ($current_logo_path !== '' && $current_logo_path !== $next_logo_path) {
            baDeleteMediaIfExists($current_logo_path);
        }
        if ($current_banner_path !== '' && $current_banner_path !== $next_banner_path) {
            baDeleteMediaIfExists($current_banner_path);
        }

        if (function_exists('syncUserProfileReferences')) {
            syncUserProfileReferences(
                $conn,
                (int)$seller_scope_id,
                (string)($business_user['full_name'] ?? ''),
                (string)($business_user['email'] ?? ''),
                $phone
            );
        }

        $fresh_owner = getUserInfo($conn, (int)$seller_scope_id);
        if ($fresh_owner && (int)$seller_scope_id === $current_user_id) {
            $_SESSION['profile_image'] = (string)($fresh_owner['profile_image'] ?? '');
            if (!empty($fresh_owner['business_name'])) {
                $_SESSION['business_name'] = (string)$fresh_owner['business_name'];
            }
            if (isset($fresh_owner['business_banner'])) {
                $_SESSION['business_banner'] = (string)$fresh_owner['business_banner'];
            }
        }

        $_SESSION['success'] = 'Business account details updated successfully.';
        header('Location: business_account.php');
        exit;
    } else {
        if ($uploaded_new_logo && $next_logo_path !== '') {
            baDeleteMediaIfExists($next_logo_path);
        }
        if ($uploaded_new_banner && $next_banner_path !== '') {
            baDeleteMediaIfExists($next_banner_path);
        }
        $_SESSION['error'] = implode("\n", $errors);
        header('Location: business_account.php');
        exit;
    }
}

$business_user = baFetchOwnerBusinessAccount($conn, (int)$seller_scope_id, $editable_columns);
$display_logo = '';
if ($business_user && $logo_column !== '') {
    $display_logo = baNormalizeMediaPath((string)($business_user[$logo_column] ?? ''));
}
$display_logo_url = $display_logo !== '' ? '../' . ltrim($display_logo, '/') : '';

$display_banner = '';
if ($business_user && $has_business_banner_col) {
    $display_banner = baNormalizeMediaPath((string)($business_user['business_banner'] ?? ''));
}
$display_banner_url = $display_banner !== '' ? '../' . ltrim($display_banner, '/') : '';
$flash_success = $_SESSION['success'] ?? '';
$flash_error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Account - Lechon Delights Admin</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-refresh.css">
    <style>
        .biz-shell { padding: 24px; }
        .biz-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(300px, 0.9fr);
            gap: 18px;
            align-items: start;
        }
        .biz-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
        }
        .biz-title {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .biz-subtitle {
            margin: 0 0 16px;
            color: #64748b;
            font-size: .92rem;
        }
        .biz-status {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: .9rem;
            white-space: pre-line;
        }
        .biz-status.success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        .biz-status.error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .biz-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .biz-form-grid .full { grid-column: 1 / -1; }
        .biz-logo-row {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .biz-logo-preview {
            width: 88px;
            height: 88px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
            font-weight: 700;
            overflow: hidden;
            flex: 0 0 88px;
        }
        .biz-logo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .biz-banner-preview-wrap {
            width: 100%;
            height: 180px;
            border-radius: 14px;
            border: 1.5px dashed #cbd5e1;
            background: #f8fafc;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .biz-banner-preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .biz-banner-preview-overlay {
            position: absolute;
            bottom: 12px;
            right: 12px;
            z-index: 2;
        }
        .biz-banner-preview-tag {
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(4px);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .biz-banner-placeholder {
            text-align: center;
            padding: 24px 16px;
            color: #64748b;
        }
        .biz-banner-placeholder i {
            font-size: 2.2rem;
            color: #94a3b8;
            margin-bottom: 8px;
            display: block;
        }
        .biz-banner-placeholder-title {
            font-weight: 700;
            color: #334155;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        .biz-banner-placeholder-sub {
            font-size: 0.82rem;
            color: #64748b;
            max-width: 440px;
            margin: 0 auto;
            line-height: 1.4;
        }
        .biz-summary-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 12px;
        }
        .biz-summary-list li {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 12px;
            background: #f8fafc;
        }
        .biz-summary-list span {
            display: block;
            font-size: .78rem;
            color: #64748b;
            margin-bottom: 3px;
        }
        .biz-summary-list strong {
            color: #0f172a;
            font-size: .95rem;
            word-break: break-word;
        }
        @media (max-width: 980px) {
            .biz-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .biz-shell { padding: 16px 12px 90px 12px; }
            .biz-form-grid { grid-template-columns: 1fr; }
            .biz-logo-row { flex-direction: column; }
        }

        /* Business Account Dark Mode */
        body.dark-mode .biz-card {
            background: #1e2430 !important;
            border-color: #2d3748 !important;
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3) !important;
            color: #e2e8f0 !important;
        }
        body.dark-mode .biz-title {
            color: #ffffff !important;
        }
        body.dark-mode .biz-subtitle {
            color: #94a3b8 !important;
        }
        body.dark-mode .biz-logo-preview {
            background: #171c26 !important;
            border-color: #2d3748 !important;
            color: #ffffff !important;
        }
        body.dark-mode .biz-banner-preview-wrap {
            background: #171c26 !important;
            border-color: #2d3748 !important;
        }
        body.dark-mode .biz-banner-placeholder-title {
            color: #f1f5f9 !important;
        }
        body.dark-mode .biz-banner-placeholder-sub {
            color: #94a3b8 !important;
        }
        body.dark-mode .biz-summary-list li {
            background: #171c26 !important;
            border-color: #2d3748 !important;
        }
        body.dark-mode .biz-summary-list span {
            color: #94a3b8 !important;
        }
        body.dark-mode .biz-summary-list strong {
            color: #f1f5f9 !important;
        }
        body.dark-mode .form-label {
            color: #e2e8f0 !important;
        }
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #171c26 !important;
            border-color: #2d3748 !important;
            color: #f1f5f9 !important;
        }
        body.dark-mode .form-control:focus,
        body.dark-mode .form-select:focus {
            background: #141821 !important;
            border-color: #b3261e !important;
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.25) !important;
            color: #ffffff !important;
        }
        body.dark-mode .text-muted {
            color: #94a3b8 !important;
        }
        body.dark-mode .form-check-label {
            color: #cbd5e1 !important;
        }
        body.dark-mode .biz-status.success {
            background: #064e3b !important;
            border-color: #059669 !important;
            color: #a7f3d0 !important;
        }
        body.dark-mode .biz-status.error {
            background: #450a0a !important;
            border-color: #dc2626 !important;
            color: #fecaca !important;
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
                <h1>Business Account</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner Admin')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="biz-shell">
            <?php if ($flash_success !== ''): ?>
                <div class="biz-status success"><?php echo htmlspecialchars($flash_success); ?></div>
            <?php endif; ?>
            <?php if ($flash_error !== ''): ?>
                <div class="biz-status error"><?php echo htmlspecialchars($flash_error); ?></div>
            <?php endif; ?>

            <div class="biz-grid">
                <section class="biz-card">
                    <h2 class="biz-title">Edit Business Profile</h2>
                    <p class="biz-subtitle">Update your brand identity and business credentials here without leaving the admin module.</p>

                    <?php if (!$can_change_store_name || !$can_change_store_logo): ?>
                    <div style="background:#fff8ef; border:1px solid #fedf89; border-radius:12px; padding:14px 16px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                        <div style="color:#b54708; font-size:0.88rem; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-lock" style="font-size:1.1rem; color:#b54708;"></i>
                            <span><strong>Limited Access (Non-Subscribed):</strong> Store Name and Logo editing are locked. Subscribe to a plan to customize your store identity.</span>
                        </div>
                        <a href="subscription_plans.php?locked_feature=change_store_name&required_tier=starter" class="btn btn-sm" style="background:#b3261e; color:#ffffff; border-radius:999px; font-weight:700; padding:6px 16px; text-decoration:none;">
                            <i class="fas fa-arrow-up"></i> Upgrade to Starter
                        </a>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="save_business_account" value="1">

                        <div class="biz-form-grid">
                            <div class="full">
                                <label class="form-label fw-semibold">
                                    Business Logo
                                    <?php if (!$can_change_store_logo): ?>
                                        <span class="badge ms-2" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:0.75rem;"><i class="fas fa-lock"></i> Locked</span>
                                    <?php endif; ?>
                                </label>
                                <div class="biz-logo-row">
                                    <div class="biz-logo-preview">
                                        <?php if ($display_logo_url !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($display_logo_url); ?>" alt="Business logo preview">
                                        <?php else: ?>
                                            <?php
                                                $fallback_name = trim((string)($business_user['business_name'] ?? ''));
                                                $fallback_initial = strtoupper(substr($fallback_name !== '' ? $fallback_name : 'B', 0, 1));
                                                echo htmlspecialchars($fallback_initial);
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="w-100">
                                        <?php if ($can_change_store_logo): ?>
                                            <input type="file" class="form-control" name="business_logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                            <small class="text-muted d-block mt-1">Accepted formats: JPG, PNG, WEBP. Maximum size: 5MB.</small>
                                            <?php if ($display_logo_url !== ''): ?>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" name="remove_business_logo" id="remove_business_logo" value="1">
                                                    <label class="form-check-label" for="remove_business_logo">Remove current logo</label>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <input type="file" class="form-control" disabled style="background:#f8f9fa; cursor:not-allowed;">
                                            <div class="form-text text-danger mt-1">
                                                <i class="fas fa-lock"></i> Store Logo upload is locked. <a href="subscription_plans.php?locked_feature=change_store_logo&required_tier=starter" class="text-danger fw-bold text-decoration-underline">Upgrade to Starter Plan</a> to customize your store logo.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($has_business_banner_col): ?>
                            <div class="full">
                                <label class="form-label fw-semibold d-flex align-items-center justify-content-between">
                                    <span>
                                        <i class="fas fa-panorama me-1 text-danger"></i> Store Background Picture (Cover Banner)
                                        <?php if (!$can_change_store_banner): ?>
                                            <span class="badge ms-2" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:0.75rem;"><i class="fas fa-lock"></i> Locked</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($display_banner_url !== ''): ?>
                                        <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-size:0.75rem;"><i class="fas fa-check-circle"></i> Custom Cover Active</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:0.75rem;">Default Platform Cover</span>
                                    <?php endif; ?>
                                </label>
                                
                                <div class="biz-banner-preview-wrap mb-2">
                                    <?php if ($display_banner_url !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($display_banner_url); ?>" alt="Store background picture preview" class="biz-banner-preview-img">
                                        <div class="biz-banner-preview-overlay">
                                            <span class="biz-banner-preview-tag"><i class="fas fa-store me-1"></i> Current Store Cover</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="biz-banner-placeholder">
                                            <i class="fas fa-image"></i>
                                            <div class="biz-banner-placeholder-title">No Custom Store Background Uploaded</div>
                                            <div class="biz-banner-placeholder-sub">Upload an appetizing cover photo (roasted lechon, storefront, dining ambience) to personalize your store page and marketplace cards.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="biz-banner-controls">
                                    <?php if ($can_change_store_banner): ?>
                                        <input type="file" class="form-control" name="business_banner" id="business_banner" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-1">
                                            <small class="text-muted">Recommended: 1200 &times; 400px (16:9 or 3:1 ratio). JPG, PNG, WEBP up to 8MB. Appears as your storefront hero background.</small>
                                            <?php if ($display_banner_url !== ''): ?>
                                                <div class="form-check m-0">
                                                    <input class="form-check-input" type="checkbox" name="remove_business_banner" id="remove_business_banner" value="1">
                                                    <label class="form-check-label text-danger fw-semibold" for="remove_business_banner" style="font-size:0.85rem;">
                                                        <i class="fas fa-trash-can me-1"></i> Remove background picture
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <input type="file" class="form-control" disabled style="background:#f8f9fa; cursor:not-allowed;">
                                        <div class="form-text text-danger mt-1">
                                            <i class="fas fa-lock"></i> Store Background Picture upload is locked. <a href="subscription_plans.php?locked_feature=change_store_banner&required_tier=starter" class="text-danger fw-bold text-decoration-underline">Upgrade to Starter Plan</a> to customize your store background.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($has_business_name_col): ?>
                            <div>
                                <label class="form-label fw-semibold">
                                    Business Name
                                    <?php if (!$can_change_store_name): ?>
                                        <span class="badge ms-2" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-size:0.75rem;"><i class="fas fa-lock"></i> Locked</span>
                                    <?php endif; ?>
                                </label>
                                <?php if ($can_change_store_name): ?>
                                    <input type="text" class="form-control" name="business_name" maxlength="200" required value="<?php echo htmlspecialchars((string)($business_user['business_name'] ?? '')); ?>">
                                <?php else: ?>
                                    <input type="text" class="form-control" readonly style="background:#f8f9fa; cursor:not-allowed;" value="<?php echo htmlspecialchars((string)($business_user['business_name'] ?? '')); ?>">
                                    <input type="hidden" name="business_name" value="<?php echo htmlspecialchars((string)($business_user['business_name'] ?? '')); ?>">
                                    <div class="form-text text-danger mt-1">
                                        <i class="fas fa-lock"></i> Store Name customization is locked. <a href="subscription_plans.php?locked_feature=change_store_name&required_tier=starter" class="text-danger fw-bold text-decoration-underline">Upgrade to Starter Plan</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($has_business_type_col): ?>
                            <div>
                                <label class="form-label fw-semibold">Business Type</label>
                                <input type="text" class="form-control" name="business_type" maxlength="100" placeholder="e.g. Restaurant" value="<?php echo htmlspecialchars((string)($business_user['business_type'] ?? '')); ?>">
                            </div>
                            <?php endif; ?>

                            <?php if ($has_business_registration_col): ?>
                            <div>
                                <label class="form-label fw-semibold">Business Registration</label>
                                <input type="text" class="form-control" name="business_registration" maxlength="100" placeholder="DTI/SEC Registration Number" value="<?php echo htmlspecialchars((string)($business_user['business_registration'] ?? '')); ?>">
                            </div>
                            <?php endif; ?>

                            <?php if ($has_tax_id_col): ?>
                            <div>
                                <label class="form-label fw-semibold">Tax ID</label>
                                <input type="text" class="form-control" name="tax_id" maxlength="50" placeholder="BIR TIN" value="<?php echo htmlspecialchars((string)($business_user['tax_id'] ?? '')); ?>">
                            </div>
                            <?php endif; ?>

                            <?php if ($has_website_col): ?>
                            <div>
                                <label class="form-label fw-semibold">Website</label>
                                <input type="text" class="form-control" name="website" maxlength="200" placeholder="https://yourbusiness.com" value="<?php echo htmlspecialchars((string)($business_user['website'] ?? '')); ?>">
                            </div>
                            <?php endif; ?>

                            <?php if ($has_phone_col): ?>
                            <div>
                                <label class="form-label fw-semibold">Business Phone</label>
                                <input type="text" class="form-control" name="phone" maxlength="20" placeholder="09171234567" value="<?php echo htmlspecialchars((string)($business_user['phone'] ?? '')); ?>">
                            </div>
                            <?php endif; ?>

                            <?php if ($has_address_col): ?>
                            <div class="full">
                                <label class="form-label fw-semibold">Business Address</label>
                                <textarea class="form-control" name="address" rows="3" maxlength="350" placeholder="Street, barangay, city, province"><?php echo htmlspecialchars((string)($business_user['address'] ?? '')); ?></textarea>
                            </div>
                            <?php endif; ?>

                            <div class="full mt-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save Business Account
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <aside class="biz-card">
                    <h3 class="biz-title">Account Snapshot</h3>
                    <p class="biz-subtitle">Quick view of your current partner owner account information.</p>
                    <ul class="biz-summary-list">
                        <li>
                            <span>Owner Account</span>
                            <strong><?php echo htmlspecialchars((string)($business_user['full_name'] ?? '')); ?></strong>
                        </li>
                        <li>
                            <span>Login Email</span>
                            <strong><?php echo htmlspecialchars((string)($business_user['email'] ?? '')); ?></strong>
                        </li>
                        <li>
                            <span>Business Name</span>
                            <strong><?php echo htmlspecialchars((string)($business_user['business_name'] ?? 'Not set')); ?></strong>
                        </li>
                        <li>
                            <span>Business Type</span>
                            <strong><?php echo htmlspecialchars((string)($business_user['business_type'] ?? 'Not set')); ?></strong>
                        </li>
                        <li>
                            <span>Store Background Picture</span>
                            <?php if ($display_banner_url !== ''): ?>
                                <div style="width:100%; height:80px; border-radius:10px; overflow:hidden; margin-top:6px; border:1px solid #e2e8f0;">
                                    <img src="<?php echo htmlspecialchars($display_banner_url); ?>" alt="Store Background" style="width:100%; height:100%; object-fit:cover; display:block;">
                                </div>
                            <?php else: ?>
                                <strong style="font-size:0.85rem; color:#64748b;"><i class="fas fa-image text-muted me-1"></i> Default Platform Cover</strong>
                            <?php endif; ?>
                        </li>
                        <li>
                            <span>Member Since</span>
                            <strong>
                                <?php
                                    $created_at = (string)($business_user['created_at'] ?? '');
                                    echo htmlspecialchars($created_at !== '' ? date('F j, Y', strtotime($created_at)) : 'N/A');
                                ?>
                            </strong>
                        </li>
                    </ul>
                </aside>
            </div>
        </div>
    </div>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
<script src="admin.js"></script>
</body>
</html>
