<?php
// my_account.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php?mode=login#login&redirect=my_account.php');
    exit;
}

require_once 'includes/config.php';

function myAccountUserColumnExists(mysqli $conn, string $column_name): bool {
    static $cache = [];
    $column_name = trim($column_name);
    if ($column_name === '') {
        return false;
    }
    if (array_key_exists($column_name, $cache)) {
        return (bool)$cache[$column_name];
    }
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE '{$safe_column}'");
    $cache[$column_name] = $result && mysqli_num_rows($result) > 0;
    return (bool)$cache[$column_name];
}

function myAccountTableExists(mysqli $conn, string $table_name): bool {
    static $cache = [];
    $table_name = trim($table_name);
    if ($table_name === '') {
        return false;
    }
    if (array_key_exists($table_name, $cache)) {
        return (bool)$cache[$table_name];
    }
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    $cache[$table_name] = $result && mysqli_num_rows($result) > 0;
    return (bool)$cache[$table_name];
}

function myAccountEnsurePasswordCooldownTable(mysqli $conn): bool {
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    $sql = "CREATE TABLE IF NOT EXISTS user_password_change_locks (
                user_id INT NOT NULL PRIMARY KEY,
                last_changed_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_last_changed_at (last_changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $ensured = mysqli_query($conn, $sql) === true;
    if (!$ensured) {
        error_log('Unable to ensure password cooldown table: ' . mysqli_error($conn));
    }
    return $ensured;
}

function myAccountGetPasswordChangeWindow(mysqli $conn, int $user_id, int $cooldown_days = 7): array {
    $state = [
        'success' => false,
        'can_change' => true,
        'last_changed_at' => null,
        'next_allowed_at' => null,
        'remaining_seconds' => 0,
        'message' => 'Unable to validate password cooldown right now.'
    ];

    if ($user_id <= 0) {
        $state['message'] = 'Invalid account context for password update.';
        return $state;
    }

    if (!myAccountEnsurePasswordCooldownTable($conn)) {
        $state['message'] = 'Password security checks are temporarily unavailable. Please try again in a moment.';
        return $state;
    }

    $query = "SELECT last_changed_at FROM user_password_change_locks WHERE user_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        $state['message'] = 'Unable to prepare password cooldown check.';
        return $state;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $state['success'] = true;
    $state['message'] = '';
    if (!$row || empty($row['last_changed_at'])) {
        return $state;
    }

    $last_changed_at = (string)$row['last_changed_at'];
    $last_timestamp = strtotime($last_changed_at);
    if ($last_timestamp === false) {
        return $state;
    }

    $cooldown_days = max(1, $cooldown_days);
    $next_timestamp = strtotime('+' . $cooldown_days . ' days', $last_timestamp);
    if ($next_timestamp === false) {
        return $state;
    }

    $now_timestamp = time();
    $state['last_changed_at'] = date('Y-m-d H:i:s', $last_timestamp);
    $state['next_allowed_at'] = date('Y-m-d H:i:s', $next_timestamp);

    if ($now_timestamp < $next_timestamp) {
        $state['can_change'] = false;
        $state['remaining_seconds'] = $next_timestamp - $now_timestamp;
        $remaining_days = (int)ceil($state['remaining_seconds'] / 86400);
        $state['message'] = 'Password was recently changed on ' . date('F j, Y g:i A', $last_timestamp) . '. You can update it again after ' . $remaining_days . ' day' . ($remaining_days === 1 ? '' : 's') . ' (on ' . date('F j, Y g:i A', $next_timestamp) . ').';
    }

    return $state;
}

function myAccountRecordPasswordChange(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0 || !myAccountEnsurePasswordCooldownTable($conn)) {
        return false;
    }

    $sql = "INSERT INTO user_password_change_locks (user_id, last_changed_at)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_changed_at = NOW()";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    $executed = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $executed;
}

function myAccountNormalizeAvatarPath($path): string {
    $clean_path = str_replace('\\', '/', trim((string)$path));
    if ($clean_path === '') {
        return '';
    }

    if (preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $clean_path)) {
        return $clean_path;
    }

    if (preg_match('#^[A-Za-z0-9._-]+\\.(jpg|jpeg|png|webp)$#i', $clean_path)) {
        return 'uploads/profile_pictures/' . $clean_path;
    }

    return '';
}

function myAccountDeleteAvatarFile(string $relative_path): void {
    $normalized = myAccountNormalizeAvatarPath($relative_path);
    if ($normalized === '') {
        return;
    }
    $full_path = __DIR__ . '/' . $normalized;
    if (is_file($full_path)) {
        @unlink($full_path);
    }
}

function myAccountBindParams(mysqli_stmt $stmt, string $types, array &$values): bool {
    $params = [$stmt, $types];
    foreach ($values as $key => $value) {
        $params[] = &$values[$key];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $params);
}

function myAccountGetUserById(mysqli $conn, int $user_id): ?array {
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $user ?: null;
}

$user_id = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg = '';
$default_account_tab = 'profile';

if (!isset($_SESSION['my_account_csrf']) || !is_string($_SESSION['my_account_csrf']) || $_SESSION['my_account_csrf'] === '') {
    $_SESSION['my_account_csrf'] = bin2hex(random_bytes(16));
}
$my_account_csrf = $_SESSION['my_account_csrf'];

$user = myAccountGetUserById($conn, $user_id);
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$is_organization_account = strtolower((string)($user['account_type'] ?? '')) === 'organization';
$avatar_column_for_account = $is_organization_account ? 'business_logo' : 'profile_image';
$avatar_column_exists_for_account = myAccountUserColumnExists($conn, $avatar_column_for_account);
if (!$avatar_column_exists_for_account && myAccountUserColumnExists($conn, 'profile_image')) {
    $avatar_column_for_account = 'profile_image';
    $avatar_column_exists_for_account = true;
}

$business_name_column_exists = myAccountUserColumnExists($conn, 'business_name');
$business_type_column_exists = myAccountUserColumnExists($conn, 'business_type');

$password_cooldown_days = 7;
$password_change_window = myAccountGetPasswordChangeWindow($conn, $user_id, $password_cooldown_days);
$password_change_blocked = ($password_change_window['success'] ?? false) && !($password_change_window['can_change'] ?? true);
$password_change_hint = $password_change_blocked ? (string)($password_change_window['message'] ?? '') : '';

$name_change_window = function_exists('getUserNameChangeWindow') ? getUserNameChangeWindow($conn, $user_id, 7) : ['can_change' => true];
$name_change_hint = '';
if (($name_change_window['success'] ?? false) && !($name_change_window['can_change'] ?? true) && !empty($name_change_window['next_allowed_at'])) {
    $name_change_hint = 'Full name was recently updated. It can be changed again on ' . date('F j, Y g:i A', strtotime((string)$name_change_window['next_allowed_at'])) . '.';
}

// Fetch active partner subscription if organization account
$partner_subscription = null;
if (myAccountTableExists($conn, 'partner_plan_subscriptions') && myAccountTableExists($conn, 'platform_subscription_plans')) {
    $sub_sql = "SELECT s.*, p.plan_name, p.plan_code, p.monthly_price, p.annual_price 
                FROM partner_plan_subscriptions s 
                JOIN platform_subscription_plans p ON p.id = s.plan_id 
                WHERE s.partner_user_id = ? AND s.subscription_status IN ('active', 'trial') 
                ORDER BY s.id DESC LIMIT 1";
    $sub_stmt = mysqli_prepare($conn, $sub_sql);
    if ($sub_stmt) {
        mysqli_stmt_bind_param($sub_stmt, "i", $user_id);
        mysqli_stmt_execute($sub_stmt);
        $sub_res = mysqli_stmt_get_result($sub_stmt);
        $partner_subscription = $sub_res ? mysqli_fetch_assoc($sub_res) : null;
        mysqli_stmt_close($sub_stmt);
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $default_account_tab = 'profile';
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($my_account_csrf, $posted_token)) {
        $error_msg = 'Invalid request token. Please refresh the page and try again.';
    } else {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ($user['address'] ?? '')));
        $latitude = trim((string)($_POST['latitude'] ?? ($user['latitude'] ?? '')));
        $longitude = trim((string)($_POST['longitude'] ?? ($user['longitude'] ?? '')));
        $business_name = trim((string)($_POST['business_name'] ?? ($user['business_name'] ?? '')));
        $business_type = trim((string)($_POST['business_type'] ?? ($user['business_type'] ?? '')));
        $remove_avatar = !empty($_POST['remove_account_avatar']);

        $name_changed = false;
        if (strcasecmp($full_name, (string)($user['full_name'] ?? '')) !== 0) {
            $name_changed = true;
            if (!($name_change_window['can_change'] ?? true)) {
                $error_msg = $name_change_hint !== '' ? $name_change_hint : 'Full name can only be changed once every 7 days.';
            }
        }

        if ($error_msg === '' && $full_name === '') {
            $error_msg = "Full Name is required.";
        }

        if ($error_msg === '') {
            $current_avatar_path = $avatar_column_exists_for_account ? myAccountNormalizeAvatarPath($user[$avatar_column_for_account] ?? '') : '';
            $next_avatar_path = $current_avatar_path;
            $uploaded_new_avatar = false;

            if ($remove_avatar) {
                $next_avatar_path = null;
            } elseif (isset($_FILES['account_avatar']) && $_FILES['account_avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $avatar_file = $_FILES['account_avatar'];
                if ($avatar_file['error'] === UPLOAD_ERR_OK) {
                    $max_size = 5 * 1024 * 1024;
                    if ($avatar_file['size'] > $max_size) {
                        $error_msg = "Avatar file size must be less than 5MB.";
                    } else {
                        $target_folder_rel = $avatar_column_for_account === 'business_logo' ? 'uploads/business_logos' : 'uploads/profile_pictures';
                        $target_dir = __DIR__ . '/' . $target_folder_rel;
                        if (!is_dir($target_dir)) {
                            @mkdir($target_dir, 0755, true);
                        }

                        $ext = strtolower(pathinfo($avatar_file['name'], PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
                        if (!in_array($ext, $allowed_extensions, true)) {
                            $error_msg = "Only JPG, PNG, and WEBP image formats are supported.";
                        } else {
                            $prefix = $avatar_column_for_account === 'business_logo' ? 'biz_' : 'user_';
                            $file_name = $prefix . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $target_file = $target_dir . '/' . $file_name;

                            if (move_uploaded_file($avatar_file['tmp_name'], $target_file)) {
                                $next_avatar_path = $target_folder_rel . '/' . $file_name;
                                $uploaded_new_avatar = true;
                            } else {
                                $error_msg = "Failed to upload image. Please try again.";
                            }
                        }
                    }
                } else {
                    $error_msg = "Avatar upload error. Code: " . (int)$avatar_file['error'];
                }
            }

            if ($error_msg === '') {
                $update_fields = ['full_name = ?', 'phone = ?', 'address = ?'];
                $bind_types = 'sss';
                $bind_values = [$full_name, $phone, $address];

                if (myAccountUserColumnExists($conn, 'latitude')) {
                    $update_fields[] = 'latitude = ?';
                    $bind_types .= 's';
                    $bind_values[] = $latitude;
                }
                if (myAccountUserColumnExists($conn, 'longitude')) {
                    $update_fields[] = 'longitude = ?';
                    $bind_types .= 's';
                    $bind_values[] = $longitude;
                }

                if ($is_organization_account && $business_name_column_exists) {
                    $update_fields[] = 'business_name = ?';
                    $bind_types .= 's';
                    $bind_values[] = $business_name;
                }
                if ($is_organization_account && $business_type_column_exists) {
                    $update_fields[] = 'business_type = ?';
                    $bind_types .= 's';
                    $bind_values[] = $business_type;
                }
                if ($avatar_column_exists_for_account) {
                    $update_fields[] = "`{$avatar_column_for_account}` = ?";
                    $bind_types .= 's';
                    $bind_values[] = $next_avatar_path;
                }

                $update_query = "UPDATE users SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
                $bind_types .= 'i';
                $bind_values[] = $user_id;
                $update_stmt = mysqli_prepare($conn, $update_query);
                $save_ok = false;

                if (!$update_stmt || !myAccountBindParams($update_stmt, $bind_types, $bind_values)) {
                    if ($uploaded_new_avatar && is_string($next_avatar_path)) {
                        myAccountDeleteAvatarFile($next_avatar_path);
                    }
                    $error_msg = "Failed to update profile. Please try again.";
                } else {
                    mysqli_begin_transaction($conn);
                    $save_ok = mysqli_stmt_execute($update_stmt);
                    if ($save_ok && $name_changed) {
                        $save_ok = recordUserNameChangeTimestamp($conn, $user_id);
                    }

                    if ($save_ok) {
                        mysqli_commit($conn);
                    } else {
                        mysqli_rollback($conn);
                    }
                }

                if (!empty($save_ok)) {
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['phone'] = $phone;
                    $_SESSION['address'] = $address;
                    if ($is_organization_account) {
                        $_SESSION['business_name'] = $business_name;
                        $_SESSION['business_type'] = $business_type;
                    }

                    if ($avatar_column_exists_for_account) {
                        $normalized_next_path = myAccountNormalizeAvatarPath($next_avatar_path);
                        if ($normalized_next_path !== '') {
                            $_SESSION['profile_image'] = $normalized_next_path;
                        } else {
                            unset($_SESSION['profile_image']);
                        }
                        if ($avatar_column_for_account === 'business_logo') {
                            $_SESSION['business_logo'] = $normalized_next_path !== '' ? $normalized_next_path : null;
                        }
                        if ($current_avatar_path !== '' && $current_avatar_path !== $normalized_next_path) {
                            myAccountDeleteAvatarFile($current_avatar_path);
                        }
                    }

                    syncUserProfileReferences($conn, $user_id, $full_name, $user['email'], $phone);
                    $success_msg = $name_changed ? "Profile updated successfully. Full name can be updated again after 7 days." : "Profile updated successfully!";
                    $user = myAccountGetUserById($conn, $user_id) ?? $user;
                } else {
                    if ($uploaded_new_avatar && is_string($next_avatar_path)) {
                        myAccountDeleteAvatarFile($next_avatar_path);
                    }
                    $error_msg = "Failed to update profile. Please try again.";
                }

                if ($update_stmt) {
                    mysqli_stmt_close($update_stmt);
                }
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $default_account_tab = 'password';
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($my_account_csrf, $posted_token)) {
        $error_msg = 'Invalid request token. Please refresh the page and try again.';
    } elseif (!($password_change_window['success'] ?? false)) {
        $error_msg = (string)($password_change_window['message'] ?? 'Password security checks are unavailable right now.');
    } elseif (!($password_change_window['can_change'] ?? true)) {
        $error_msg = (string)($password_change_window['message'] ?? 'Password cooldown is active.');
    } else {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        
        if (password_verify($current_password, (string)($user['password'] ?? ''))) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 8) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    if ($hashed_password === false) {
                        $error_msg = 'Unable to secure your new password. Please try again.';
                    } else {
                        $update_stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        if (!$update_stmt) {
                            $error_msg = "Failed to prepare password update request.";
                        } else {
                            mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user_id);
                            mysqli_begin_transaction($conn);
                            if (mysqli_stmt_execute($update_stmt) && myAccountRecordPasswordChange($conn, $user_id)) {
                                mysqli_commit($conn);
                                $success_msg = "Password changed successfully! Cooldown is active for 7 days.";
                                $user = myAccountGetUserById($conn, $user_id) ?? $user;
                            } else {
                                mysqli_rollback($conn);
                                $error_msg = "Failed to change password. Please try again.";
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    }
                } else {
                    $error_msg = "Password must be at least 8 characters long.";
                }
            } else {
                $error_msg = "New passwords do not match.";
            }
        } else {
            $error_msg = "Current password is incorrect.";
        }
    }
}

// Count user saved addresses
$saved_addresses_count = 0;
if (function_exists('caFetchUserSavedAddresses')) {
    $all_saved = caFetchUserSavedAddresses($conn, $user_id);
    $saved_addresses_count = count($all_saved);
} else {
    $cnt_res = $conn->query("SELECT COUNT(*) as c FROM user_saved_addresses WHERE user_id = $user_id AND is_deleted = 0");
    if ($cnt_res && $crow = $cnt_res->fetch_assoc()) {
        $saved_addresses_count = (int)$crow['c'];
    }
}

$profile_image_relative_path = '';
if ($avatar_column_exists_for_account) {
    $profile_image_relative_path = myAccountNormalizeAvatarPath($user[$avatar_column_for_account] ?? '');
    if ($profile_image_relative_path !== '' && !is_file(__DIR__ . '/' . $profile_image_relative_path)) {
        $profile_image_relative_path = '';
    }
}
$has_profile_image = $profile_image_relative_path !== '';

$avatar_name_source = $is_organization_account ? trim((string)($user['business_name'] ?? '')) : '';
if ($avatar_name_source === '') {
    $avatar_name_source = trim((string)($user['full_name'] ?? 'User'));
}
$name_parts = preg_split('/\s+/', $avatar_name_source);
$avatar_initials = '';
if (!empty($name_parts[0])) $avatar_initials .= strtoupper(substr($name_parts[0], 0, 1));
if (!empty($name_parts[1])) $avatar_initials .= strtoupper(substr($name_parts[1], 0, 1));
if ($avatar_initials === '') $avatar_initials = 'U';

$page_title = "My Account | Lechon Delights";
include 'includes/header.php';
?>

<!-- Leaflet Map CSS and JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --acc-primary: #b3261e;
    --acc-primary-hover: #981b15;
    --acc-bg: #f8f9fa;
    --acc-card: #ffffff;
    --acc-ink: #101828;
    --acc-muted: #475467;
    --acc-border: #eaecf0;
    --acc-border-light: #f2f4f7;
    --acc-success: #027a48;
    --acc-success-bg: #ecfdf3;
    --acc-success-border: #abefc6;
}

.account-page-v2 {
    background: var(--acc-bg);
    min-height: 85vh;
    padding: 24px 0 140px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

.account-container-v2 {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 16px;
}

/* ==========================================================================
   Top Profile Summary Banner (High-Level Hierarchy)
   ========================================================================== */
.account-hero-card {
    background: var(--acc-card);
    border: 1px solid var(--acc-border);
    border-radius: 18px;
    padding: 20px 24px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.account-hero-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.hero-avatar-wrap {
    position: relative;
    width: 68px;
    height: 68px;
    flex-shrink: 0;
}

.hero-avatar-badge {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: #fff1f0;
    color: var(--acc-primary);
    border: 2px solid #fee4e2;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Outfit', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    cursor: pointer;
    overflow: hidden;
    position: relative;
    transition: transform 0.2s ease;
}

.hero-avatar-badge:hover {
    transform: scale(1.04);
}

.hero-avatar-badge img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.hero-avatar-overlay {
    position: absolute;
    inset: 0;
    background: rgba(16, 24, 40, 0.55);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.hero-avatar-badge:hover .hero-avatar-overlay {
    opacity: 1;
}

.hero-user-details h2 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--acc-ink);
    margin: 0 0 4px;
    line-height: 1.2;
}

.hero-user-tags {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.hero-tag {
    font-size: 0.76rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.hero-tag.type-tag {
    background: #f8f9fa;
    color: #344054;
    border: 1px solid var(--acc-border);
}

.hero-tag.verified-tag {
    background: var(--acc-success-bg);
    color: var(--acc-success);
    border: 1px solid var(--acc-success-border);
}

.hero-tag.member-tag {
    background: #eff8ff;
    color: #175cd3;
    border: 1px solid #b2ddff;
}

.account-hero-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.hero-action-btn {
    background: #ffffff;
    border: 1px solid var(--acc-border);
    color: #344054;
    border-radius: 10px;
    padding: 8px 16px;
    font-size: 0.84rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.hero-action-btn:hover {
    background: #f8f9fa;
    color: var(--acc-primary);
    border-color: #d0d5dd;
}

/* ==========================================================================
   Two-Column Grid Layout (Sidebar Navigation + Workspace Panels)
   ========================================================================== */
.account-grid-v2 {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 20px;
    align-items: start;
}

/* Sidebar Nav */
.account-sidebar-v2 {
    background: var(--acc-card);
    border: 1px solid var(--acc-border);
    border-radius: 16px;
    padding: 10px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: sticky;
    top: 90px;
}

.acc-tab-nav-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border: none;
    background: transparent;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 700;
    color: #475467;
    cursor: pointer;
    text-align: left;
    width: 100%;
    transition: all 0.15s ease;
}

.acc-tab-nav-btn i {
    font-size: 1rem;
    width: 20px;
    text-align: center;
    color: #667085;
}

.acc-tab-nav-btn:hover {
    background: #f8f9fa;
    color: var(--acc-ink);
}

.acc-tab-nav-btn.active {
    background: var(--acc-primary);
    color: #ffffff;
}

.acc-tab-nav-btn.active i {
    color: #ffffff;
}

.acc-tab-nav-btn .nav-badge {
    margin-left: auto;
    font-size: 0.72rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 999px;
    background: #f2f4f7;
    color: #344054;
}

.acc-tab-nav-btn.active .nav-badge {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

/* Workspace Panels */
.account-workspace-v2 {
    min-width: 0;
}

.acc-tab-pane {
    display: none;
}

.acc-tab-pane.active {
    display: block;
    animation: fadeInTab 0.2s ease-out;
}

@keyframes fadeInTab {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Common Workspace Card */
.acc-panel-card {
    background: var(--acc-card);
    border: 1px solid var(--acc-border);
    border-radius: 18px;
    padding: 24px 28px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    margin-bottom: 20px;
}

.acc-panel-head {
    margin-bottom: 20px;
    border-bottom: 1px solid var(--acc-border-light);
    padding-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.acc-panel-head h3 {
    margin: 0 0 4px;
    font-family: 'Outfit', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--acc-ink);
}

.acc-panel-head p {
    margin: 0;
    font-size: 0.86rem;
    color: var(--acc-muted);
}

/* Compact Forms Grid */
.acc-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.acc-form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.acc-form-group.full-width {
    grid-column: span 2;
}

.acc-form-group label {
    font-size: 0.86rem;
    font-weight: 700;
    color: #344054;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.acc-form-group label span.label-sub {
    font-size: 0.74rem;
    font-weight: 500;
    color: #667085;
}

.acc-form-input, .acc-form-select, .acc-form-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 0.9rem;
    color: var(--acc-ink);
    background: #ffffff;
    transition: all 0.15s ease;
    box-sizing: border-box;
}

.acc-form-input:focus, .acc-form-select:focus, .acc-form-textarea:focus {
    outline: none;
    border-color: var(--acc-primary);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

.acc-form-input:disabled {
    background-color: #f8f9fa;
    color: #667085;
    cursor: not-allowed;
}

.acc-form-hint {
    font-size: 0.76rem;
    color: #667085;
    margin: 0;
}

.acc-btn-primary {
    background: var(--acc-primary);
    color: #ffffff;
    border: 0;
    border-radius: 10px;
    padding: 10px 22px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.15s ease;
}

.acc-btn-primary:hover:not(:disabled) {
    background: var(--acc-primary-hover);
}

.acc-btn-primary:disabled {
    background: #98a2b3;
    cursor: not-allowed;
}

/* Primary Address Preview Snippet */
.primary-address-snippet {
    background: #f8f9fa;
    border: 1px solid var(--acc-border);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-top: 14px;
}

.address-snippet-left {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.address-snippet-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #fff1f0;
    color: var(--acc-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.address-snippet-left strong {
    display: block;
    font-size: 0.92rem;
    color: var(--acc-ink);
    margin-bottom: 2px;
}

.address-snippet-left p {
    margin: 0;
    font-size: 0.84rem;
    color: var(--acc-muted);
    line-height: 1.4;
}

.btn-switch-tab {
    background: #ffffff;
    border: 1px solid var(--acc-border);
    color: var(--acc-primary);
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.btn-switch-tab:hover {
    background: #fff1f0;
    border-color: #fee4e2;
}

/* Address Book Frame Container (clean and responsive) */
.acc-address-frame-wrap {
    width: 100%;
    min-height: 650px;
    border: none;
    background: transparent;
    border-radius: 14px;
    overflow: hidden;
}

/* Mobile Segmented Nav Bar */
.mobile-segmented-nav {
    display: none;
    position: sticky;
    top: 70px;
    z-index: 100;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(8px);
    border: 1px solid var(--acc-border);
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 16px;
    gap: 4px;
}

.mobile-segment-btn {
    flex: 1;
    border: none;
    background: transparent;
    padding: 8px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #475467;
    border-radius: 8px;
    cursor: pointer;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.mobile-segment-btn.active {
    background: var(--acc-primary);
    color: #ffffff;
}

@media (max-width: 992px) {
    .account-grid-v2 {
        grid-template-columns: 1fr;
    }
    .account-sidebar-v2 {
        display: none;
    }
    .mobile-segmented-nav {
        display: flex;
    }
    .acc-form-grid {
        grid-template-columns: 1fr;
    }
    .acc-form-group.full-width {
        grid-column: span 1;
    }
    .account-hero-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .account-hero-right {
        width: 100%;
    }
    .hero-action-btn {
        flex: 1;
        justify-content: center;
    }
}
</style>

<div class="account-page-v2">
    <div class="account-container-v2">
        
        <!-- Top Profile Summary Banner (Hierarchy Level 1) -->
        <div class="account-hero-card">
            <div class="account-hero-left">
                <div class="hero-avatar-wrap">
                    <div class="hero-avatar-badge" onclick="triggerAvatarUpload();" title="Click to change profile picture">
                        <?php if ($has_profile_image): ?>
                        <img src="<?php echo htmlspecialchars($profile_image_relative_path); ?>" alt="Profile picture" id="heroAvatarImg">
                        <?php else: ?>
                        <span><?php echo htmlspecialchars($avatar_initials); ?></span>
                        <?php endif; ?>
                        <div class="hero-avatar-overlay">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                </div>

                <div class="hero-user-details">
                    <h2><?php echo htmlspecialchars($user['full_name'] ?? 'My Account'); ?></h2>
                    <div class="hero-user-tags">
                        <span class="hero-tag type-tag">
                            <i class="fas fa-user-tag"></i> <?php echo $is_organization_account ? 'Store Partner' : 'Customer Account'; ?>
                        </span>
                        <?php if ($partner_subscription): ?>
                        <a href="subscription_plans.php" class="hero-tag" style="background:#b3261e;color:#fff;border:1px solid #b3261e;text-decoration:none;font-weight:800;" title="Active Partner Plan: <?php echo htmlspecialchars($partner_subscription['plan_name']); ?>">
                            <i class="fas fa-crown"></i> <?php echo htmlspecialchars(strtoupper($partner_subscription['plan_name'])); ?> PLAN
                        </a>
                        <?php endif; ?>
                        <span class="hero-tag verified-tag">
                            <i class="fas fa-shield-check"></i> <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                        </span>
                        <span class="hero-tag member-tag">
                            <i class="fas fa-calendar"></i> Since <?php echo !empty($user['created_at']) ? date('M Y', strtotime((string)$user['created_at'])) : '2024'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="account-hero-right">
                <a href="my_orders.php" class="hero-action-btn">
                    <i class="fas fa-bag-shopping"></i> My Orders
                </a>
                <a href="favorites.php" class="hero-action-btn">
                    <i class="fas fa-heart"></i> Favorites
                </a>
                <a href="help_center.php" class="hero-action-btn">
                    <i class="fas fa-headset"></i> Help Center
                </a>
            </div>
        </div>

        <!-- Mobile Segmented Controller -->
        <nav class="mobile-segmented-nav" aria-label="Mobile Navigation">
            <button type="button" class="mobile-segment-btn active" data-tab="profile">
                <i class="fas fa-user"></i> Profile
            </button>
            <button type="button" class="mobile-segment-btn" data-tab="addresses">
                <i class="fas fa-location-dot"></i> Addresses
            </button>
            <button type="button" class="mobile-segment-btn" data-tab="password">
                <i class="fas fa-lock"></i> Security
            </button>
        </nav>

        <!-- Main Account 2-Column Grid -->
        <div class="account-grid-v2">
            
            <!-- Left Sidebar Navigation Menu -->
            <aside class="account-sidebar-v2">
                <button type="button" class="acc-tab-nav-btn active" data-tab="profile">
                    <i class="fas fa-id-card"></i>
                    <span>Profile Details</span>
                </button>
                <button type="button" class="acc-tab-nav-btn" data-tab="addresses">
                    <i class="fas fa-map-location-dot"></i>
                    <span>Addresses & Pin</span>
                    <span class="nav-badge"><?php echo $saved_addresses_count; ?></span>
                </button>
                <button type="button" class="acc-tab-nav-btn" data-tab="password">
                    <i class="fas fa-shield-halved"></i>
                    <span>Password & Security</span>
                </button>
                <?php if ($is_organization_account || $partner_subscription): ?>
                <a href="subscription_plans.php" class="acc-tab-nav-btn" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-crown" style="color:#b3261e;"></i>
                    <span>Subscription Plan</span>
                    <?php if ($partner_subscription): ?>
                    <span class="nav-badge" style="background:#ecfdf3;color:#027a48;font-weight:800;border:1px solid #abefc6;margin-left:auto;"><?php echo htmlspecialchars($partner_subscription['plan_name']); ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </aside>

            <!-- Workspace Panels -->
            <main class="account-workspace-v2">

                <!-- 1. Profile Information Tab (Compact, Zero Unnecessary Scrolling) -->
                <div class="acc-tab-pane active" id="profile">

                    <?php if ($partner_subscription): ?>
                    <?php
                        $subStarted = !empty($partner_subscription['started_at']) ? date('M d, Y', strtotime((string)$partner_subscription['started_at'])) : 'Active';
                        $subRenews = !empty($partner_subscription['renews_at']) ? date('M d, Y', strtotime((string)$partner_subscription['renews_at'])) : 'N/A';
                        $subCycle = ucfirst((string)($partner_subscription['billing_cycle'] ?? 'monthly'));
                    ?>
                    <div style="background:#ffffff;border:1.5px solid #abefc6;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(135deg,#ffffff 0%,#f6fef9 100%);box-shadow:0 2px 8px rgba(2,122,72,0.06);">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:44px;height:44px;border-radius:12px;background:#ecfdf3;color:#027a48;display:flex;align-items:center;justify-content:center;font-size:1.3rem;border:1px solid #abefc6;flex-shrink:0;">
                                <i class="fas fa-crown"></i>
                            </div>
                            <div>
                                <div style="font-weight:800;font-size:1rem;color:#101828;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span><?php echo htmlspecialchars($partner_subscription['plan_name']); ?> Plan (<?php echo htmlspecialchars($subCycle); ?>)</span>
                                    <span style="background:#ecfdf3;color:#027a48;font-size:0.72rem;padding:2px 8px;border-radius:999px;border:1px solid #abefc6;font-weight:800;"><i class="fas fa-check-circle"></i> ACTIVE</span>
                                </div>
                                <div style="font-size:0.82rem;color:#475467;margin-top:2px;">
                                    Subscribed: <strong><?php echo htmlspecialchars($subStarted); ?></strong> &bull; Next Renewal / Due Date: <strong style="color:#b3261e;"><?php echo htmlspecialchars($subRenews); ?></strong>
                                </div>
                            </div>
                        </div>
                        <div>
                            <a href="subscription_plans.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#ffffff;border:1.5px solid #d0d5dd;border-radius:8px;font-size:0.84rem;font-weight:700;color:#344054;text-decoration:none;transition:all 0.2s ease;">
                                <i class="fas fa-layer-group"></i> Change Plan
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="acc-panel-card">
                        <div class="acc-panel-head">
                            <div>
                                <h3>Personal Information</h3>
                                <p>Manage your account contact details for smooth delivery coordination.</p>
                            </div>
                        </div>

                        <form method="POST" action="" id="profileForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($my_account_csrf); ?>">
                            <input type="hidden" name="update_profile" value="1">
                            <input type="file" id="account_avatar" name="account_avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="display:none;" onchange="handleAvatarSubmit();">
                            <input type="hidden" name="remove_account_avatar" id="remove_account_avatar" value="0">

                            <div class="acc-form-grid">
                                <div class="acc-form-group">
                                    <label for="full_name">
                                        <span>Full Name *</span>
                                    </label>
                                    <input type="text" id="full_name" name="full_name" class="acc-form-input" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                    <?php if ($name_change_hint !== ''): ?>
                                    <p class="acc-form-hint" style="color:#b54708;"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($name_change_hint); ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="acc-form-group">
                                    <label for="phone">
                                        <span>Mobile Contact Number *</span>
                                    </label>
                                    <input type="tel" id="phone" name="phone" class="acc-form-input" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                                </div>

                                <div class="acc-form-group">
                                    <label for="email">
                                        <span>Email Address</span>
                                        <span class="label-sub"><i class="fas fa-lock"></i> Protected</span>
                                    </label>
                                    <input type="email" id="email" class="acc-form-input" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                                </div>

                                <div class="acc-form-group">
                                    <label for="account_type_display">
                                        <span>Account Role</span>
                                    </label>
                                    <input type="text" id="account_type_display" class="acc-form-input" value="<?php echo ucfirst(htmlspecialchars((string)$user['account_type'])); ?> Account" disabled>
                                </div>

                                <?php if ($is_organization_account): ?>
                                <div class="acc-form-group">
                                    <label for="business_name"><span>Business / Brand Name</span></label>
                                    <input type="text" id="business_name" name="business_name" class="acc-form-input" value="<?php echo htmlspecialchars((string)($user['business_name'] ?? '')); ?>">
                                </div>

                                <div class="acc-form-group">
                                    <label for="business_type"><span>Business Type</span></label>
                                    <input type="text" id="business_type" name="business_type" class="acc-form-input" value="<?php echo htmlspecialchars((string)($user['business_type'] ?? '')); ?>" placeholder="e.g. Restaurant, Caterer">
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Primary Delivery Address Snippet -->
                            <div class="primary-address-snippet">
                                <div class="address-snippet-left">
                                    <div class="address-snippet-icon"><i class="fas fa-house"></i></div>
                                    <div>
                                        <strong>Default Delivery Address</strong>
                                        <p><?php echo !empty($user['address']) ? htmlspecialchars($user['address']) : 'No address set yet.'; ?></p>
                                    </div>
                                </div>
                                <button type="button" class="btn-switch-tab js-nav-to-addresses">
                                    <i class="fas fa-location-dot"></i> Manage / Pin Address
                                </button>
                            </div>

                            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                                <button type="submit" class="acc-btn-primary">
                                    <i class="fas fa-check"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 2. Address Book & Map Pin Tab -->
                <div class="acc-tab-pane" id="addresses">
                    <div class="acc-panel-card">
                        <div class="acc-panel-head">
                            <div>
                                <h3>Delivery Addresses & Location Pin</h3>
                                <p>Manage your delivery drop-offs and pin your exact coordinates for riders.</p>
                            </div>
                        </div>

                        <!-- Embedded Address Book Management -->
                        <iframe
                            id="addressBookFrame"
                            src="my_account_address_book.php?embedded=1"
                            class="acc-address-frame-wrap"
                            title="Address Book"
                            loading="lazy"></iframe>
                    </div>
                </div>

                <!-- 3. Password & Security Tab -->
                <div class="acc-tab-pane" id="password">
                    <div class="acc-panel-card">
                        <div class="acc-panel-head">
                            <div>
                                <h3>Account Security & Password</h3>
                                <p>Ensure your account remains safe with an up-to-date strong password.</p>
                            </div>
                        </div>

                        <?php if ($password_change_blocked): ?>
                        <div style="background:#fffaeb; border:1px solid #fedf89; color:#b54708; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-size:0.88rem; display:flex; align-items:center; gap:10px;">
                            <i class="fas fa-shield-halved" style="font-size:1.1rem;"></i>
                            <span><?php echo htmlspecialchars($password_change_hint); ?></span>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="passwordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($my_account_csrf); ?>">
                            <input type="hidden" name="change_password" value="1">

                            <div class="acc-form-grid">
                                <div class="acc-form-group full-width">
                                    <label for="current_password"><span>Current Password</span></label>
                                    <input type="password" id="current_password" name="current_password" class="acc-form-input" required <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                                </div>

                                <div class="acc-form-group">
                                    <label for="new_password"><span>New Password</span></label>
                                    <input type="password" id="new_password" name="new_password" class="acc-form-input" required minlength="8" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                                    <p class="acc-form-hint">At least 8 characters with letters & numbers.</p>
                                </div>

                                <div class="acc-form-group">
                                    <label for="confirm_password"><span>Confirm New Password</span></label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="acc-form-input" required minlength="8" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                                </div>
                            </div>

                            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                                <button type="submit" class="acc-btn-primary" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                                    <i class="fas fa-lock"></i> <?php echo $password_change_blocked ? 'Cooldown Active' : 'Update Password'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </main>
        </div>

    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabNavBtns = document.querySelectorAll('.acc-tab-nav-btn[data-tab]');
    const mobileSegmentBtns = document.querySelectorAll('.mobile-segment-btn[data-tab]');
    const tabPanes = document.querySelectorAll('.acc-tab-pane');
    const defaultTab = <?php echo json_encode((string)$default_account_tab); ?> || 'profile';

    const serverSuccessMsg = <?php echo json_encode((string)$success_msg); ?>;
    const serverErrorMsg = <?php echo json_encode((string)$error_msg); ?>;

    if (serverSuccessMsg) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: serverSuccessMsg,
            showConfirmButton: false,
            timer: 3000
        });
    }

    if (serverErrorMsg) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: serverErrorMsg,
            showConfirmButton: false,
            timer: 3500
        });
    }

    function activateAccountTab(tabId, updateHash = true) {
        if (!tabId) return;

        tabNavBtns.forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
        });

        mobileSegmentBtns.forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
        });

        tabPanes.forEach(pane => {
            pane.classList.toggle('active', pane.id === tabId);
        });

        if (updateHash) {
            window.history.replaceState(null, '', '#' + tabId);
        }
    }

    tabNavBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            activateAccountTab(this.getAttribute('data-tab'), true);
        });
    });

    mobileSegmentBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            activateAccountTab(this.getAttribute('data-tab'), true);
        });
    });

    document.querySelectorAll('.js-nav-to-addresses').forEach(btn => {
        btn.addEventListener('click', function() {
            activateAccountTab('addresses', true);
        });
    });

    // Check initial hash
    const initialHash = window.location.hash.substring(1);
    const validInitial = initialHash && document.getElementById(initialHash) ? initialHash : defaultTab;
    activateAccountTab(validInitial, !!initialHash);

    window.addEventListener('hashchange', () => {
        const h = window.location.hash.substring(1);
        if (h && document.getElementById(h)) {
            activateAccountTab(h, false);
        }
    });

    window.triggerAvatarUpload = function() {
        const fileInput = document.getElementById('account_avatar');
        if (fileInput) fileInput.click();
    };

    window.handleAvatarSubmit = function() {
        const fileInput = document.getElementById('account_avatar');
        if (fileInput && fileInput.files && fileInput.files[0]) {
            const form = document.getElementById('profileForm');
            if (form) form.submit();
        }
    };
});
</script>

<?php
mysqli_close($conn);
include 'includes/footer.php';
?>
