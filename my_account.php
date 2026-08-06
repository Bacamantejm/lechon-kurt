<?php
// my_account.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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
        $state['message'] = 'You can change your password again on ' . date('F j, Y g:i A', $next_timestamp) . '.';
    }

    return $state;
}

function myAccountRecordPasswordChange(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }
    if (!myAccountEnsurePasswordCooldownTable($conn)) {
        return false;
    }

    $query = "INSERT INTO user_password_change_locks (user_id, last_changed_at, created_at, updated_at)
              VALUES (?, NOW(), NOW(), NOW())
              ON DUPLICATE KEY UPDATE last_changed_at = NOW(), updated_at = NOW()";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function myAccountFetchSavedAddresses(mysqli $conn, int $user_id, int $limit = 20): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !myAccountTableExists($conn, 'user_saved_addresses')) {
        return [];
    }

    $limit = max(1, min(50, (int)$limit));
    $query = "SELECT id, label, full_address, is_default, updated_at
              FROM user_saved_addresses
              WHERE user_id = ? AND TRIM(COALESCE(full_address, '')) <> ''
              ORDER BY is_default DESC, updated_at DESC, id DESC
              LIMIT {$limit}";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $full_address = trim((string)($row['full_address'] ?? ''));
            if ($full_address === '') {
                continue;
            }
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'label' => trim((string)($row['label'] ?? 'Saved Address')),
                'full_address' => $full_address,
                'is_default' => (int)($row['is_default'] ?? 0) === 1
            ];
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function myAccountNormalizeAvatarPath($value): string {
    $path = str_replace('\\', '/', trim((string)$value));
    if ($path === '') {
        return '';
    }
    if (!preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $path)) {
        return '';
    }
    return $path;
}

function myAccountDeleteAvatarFile(string $relative_path): void {
    $normalized = myAccountNormalizeAvatarPath($relative_path);
    if ($normalized === '') {
        return;
    }
    $absolute_path = __DIR__ . '/' . $normalized;
    if (is_file($absolute_path)) {
        @unlink($absolute_path);
    }
}

function myAccountUploadAvatarImage(int $user_id, array $file, string $asset_type = 'profile'): array {
    $asset_type = strtolower(trim($asset_type)) === 'business' ? 'business' : 'profile';
    $asset_label = $asset_type === 'business' ? 'Business logo' : 'Profile image';
    $upload_folder = $asset_type === 'business' ? 'business_logos' : 'profile_pictures';
    $upload_prefix = $asset_type === 'business' ? 'business_logo' : 'profile';

    $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upload_error !== UPLOAD_ERR_OK) {
        $message = 'Unable to upload the selected image.';
        switch ($upload_error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $message = $asset_label . ' is too large. Maximum file size is 5MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = $asset_label . ' upload was interrupted. Please try again.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $message = 'No ' . strtolower($asset_label) . ' was selected.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                $message = 'Server cannot process uploads right now. Please try again later.';
                break;
        }
        return ['success' => false, 'message' => $message];
    }

    $max_size = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max_size) {
        return ['success' => false, 'message' => $asset_label . ' is too large. Maximum file size is 5MB.'];
    }

    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        return ['success' => false, 'message' => 'Invalid upload source for ' . strtolower($asset_label) . '.'];
    }

    $allowed_mime_types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return ['success' => false, 'message' => 'Unable to inspect uploaded file type.'];
    }
    $mime_type = (string)finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    if (!isset($allowed_mime_types[$mime_type])) {
        return ['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, or WEBP only.'];
    }
    if (!@getimagesize($tmp_name)) {
        return ['success' => false, 'message' => 'Uploaded file is not a valid image.'];
    }

    $upload_dir = __DIR__ . '/uploads/' . $upload_folder;
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'message' => 'Unable to prepare upload directory.'];
    }

    $extension = $allowed_mime_types[$mime_type];
    try {
        $token = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $token = str_replace('.', '', uniqid((string)$user_id, true));
    }
    $file_name = $upload_prefix . '_' . $user_id . '_' . date('YmdHis') . '_' . $token . '.' . $extension;
    $target_path = $upload_dir . '/' . $file_name;

    if (!move_uploaded_file($tmp_name, $target_path)) {
        return ['success' => false, 'message' => 'Failed to save ' . strtolower($asset_label) . '. Please try again.'];
    }

    return [
        'success' => true,
        'path' => 'uploads/' . $upload_folder . '/' . $file_name
    ];
}

function myAccountGetUserById(mysqli $conn, int $user_id): ?array {
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function myAccountBindParams(mysqli_stmt $stmt, string $types, array $values): bool {
    if ($types === '') {
        return true;
    }
    $params = [$stmt, $types];
    foreach ($values as $index => $value) {
        $params[] = &$values[$index];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $params);
}

$user_id = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg = '';
$default_account_tab = 'profile';
$profile_image_column_exists = myAccountUserColumnExists($conn, 'profile_image');
$business_logo_column_exists = myAccountUserColumnExists($conn, 'business_logo');
$business_name_column_exists = myAccountUserColumnExists($conn, 'business_name');
$business_type_column_exists = myAccountUserColumnExists($conn, 'business_type');

$user = myAccountGetUserById($conn, $user_id);
if (empty($user)) {
    header('Location: logout.php');
    exit;
}

$session_account_type = strtolower(trim((string)($_SESSION['account_type'] ?? '')));
$user_account_type = strtolower(trim((string)($user['account_type'] ?? 'individual')));
$effective_account_type = $session_account_type !== '' ? $session_account_type : $user_account_type;
$is_organization_account = ($effective_account_type === 'organization');
$avatar_column_for_account = ($is_organization_account && $business_logo_column_exists) ? 'business_logo' : 'profile_image';
$avatar_column_exists_for_account = ($avatar_column_for_account === 'business_logo') ? $business_logo_column_exists : $profile_image_column_exists;
$avatar_asset_type = $avatar_column_for_account === 'business_logo' ? 'business' : 'profile';
$avatar_field_label = $avatar_column_for_account === 'business_logo' ? 'Business Logo' : 'Profile Picture';
if (!isset($_SESSION['my_account_csrf']) || !is_string($_SESSION['my_account_csrf']) || $_SESSION['my_account_csrf'] === '') {
    try {
        $_SESSION['my_account_csrf'] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $_SESSION['my_account_csrf'] = sha1(uniqid((string)$user_id, true));
    }
}
$my_account_csrf = (string)$_SESSION['my_account_csrf'];
$name_change_window = getUserNameChangeWindow($conn, $user_id, 7);
$name_change_hint = '';
if (($name_change_window['success'] ?? false) && !($name_change_window['can_change'] ?? true) && !empty($name_change_window['next_allowed_at'])) {
    $name_change_hint = 'You can change your full name again on ' . date('F j, Y g:i A', strtotime((string)$name_change_window['next_allowed_at'])) . '.';
}
$password_cooldown_days = 7;
$password_change_window = myAccountGetPasswordChangeWindow($conn, $user_id, $password_cooldown_days);
$password_change_hint = '';
if (($password_change_window['success'] ?? false) && !($password_change_window['can_change'] ?? true) && !empty($password_change_window['next_allowed_at'])) {
    $password_change_hint = 'Password updates are limited to once every ' . $password_cooldown_days . ' days. You can change it again on ' . date('F j, Y g:i A', strtotime((string)$password_change_window['next_allowed_at'])) . '.';
}
$password_change_blocked = (($password_change_window['success'] ?? false) && !($password_change_window['can_change'] ?? true));
$quick_saved_addresses = myAccountFetchSavedAddresses($conn, $user_id);
$current_profile_address = trim((string)($user['address'] ?? ''));

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $default_account_tab = 'profile';
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($my_account_csrf, $posted_token)) {
        $error_msg = 'Invalid request token. Please refresh the page and try again.';
    } else {
        $restricted_fields = [
            'email',
            'user_type',
            'role_id',
            'account_type',
            'is_active',
            'account_control_status',
            'access_restriction_notes',
            'access_restricted_at',
            'access_restricted_by',
            'business_registration',
            'website',
            'tax_id',
            'email_verified_at'
        ];
        foreach ($restricted_fields as $field_name) {
            if (array_key_exists($field_name, $_POST)) {
                $error_msg = 'For account security, protected account fields cannot be changed from My Account.';
                break;
            }
        }
    }

    if ($error_msg === '') {
        $full_name = normalizeUserProfileName($_POST['full_name'] ?? '');
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $phone_raw = trim((string)($_POST['phone'] ?? ''));
        $phone = preg_replace('/\D+/', '', $phone_raw);
        $address = trim((string)($_POST['address'] ?? ''));
        $business_name = trim((string)($_POST['business_name'] ?? ($user['business_name'] ?? '')));
        $business_type = trim((string)($_POST['business_type'] ?? ($user['business_type'] ?? '')));

        if (!isValidUserProfileName($full_name)) {
            $error_msg = 'Please enter a valid full name (2-120 letters).';
        } elseif ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 15)) {
            $error_msg = 'Phone number must contain 10 to 15 digits.';
        } elseif (strlen($address) > 350) {
            $error_msg = 'Address is too long.';
        } elseif ($is_organization_account && $business_name === '') {
            $error_msg = 'Business name is required for partner accounts.';
        } elseif (strlen($business_name) > 200 || strlen($business_type) > 100) {
            $error_msg = 'Business profile details are too long.';
        }
    }

    $name_policy = null;
    if ($error_msg === '') {
        $name_policy = evaluateUserNameChangePolicy($conn, $user_id, (string)($user['full_name'] ?? ''), $full_name, 7);
        if (!($name_policy['success'] ?? false) || !($name_policy['can_apply'] ?? false)) {
            $error_msg = (string)($name_policy['message'] ?? 'Name change policy validation failed.');
        }
    }

    if ($error_msg === '') {
        $name_changed = !userProfileNamesMatch((string)($user['full_name'] ?? ''), $full_name);
        $current_avatar_path = myAccountNormalizeAvatarPath($user[$avatar_column_for_account] ?? '');
        $next_avatar_path = $current_avatar_path !== '' ? $current_avatar_path : null;
        $remove_avatar = isset($_POST['remove_account_avatar']) && (string)$_POST['remove_account_avatar'] === '1';
        $uploaded_file = $_FILES['account_avatar'] ?? null;
        $has_uploaded_file = is_array($uploaded_file) && (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $uploaded_new_avatar = false;

        if ($avatar_column_exists_for_account) {
            if ($remove_avatar) {
                $next_avatar_path = null;
            }
            if ($has_uploaded_file && is_array($uploaded_file)) {
                $upload_result = myAccountUploadAvatarImage($user_id, $uploaded_file, $avatar_asset_type);
                if (!empty($upload_result['success'])) {
                    $next_avatar_path = (string)($upload_result['path'] ?? '');
                    $uploaded_new_avatar = true;
                } else {
                    $error_msg = (string)($upload_result['message'] ?? ($avatar_field_label . ' upload failed.'));
                }
            }
        } elseif ($remove_avatar || $has_uploaded_file) {
            $error_msg = $avatar_field_label . " upload requires the latest schema. Please run database/schema_updates/run.php first.";
        }

        if ($error_msg === '') {
            $latitude = isset($_POST['latitude']) ? trim((string)$_POST['latitude']) : '';
            $longitude = isset($_POST['longitude']) ? trim((string)$_POST['longitude']) : '';

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
                $_SESSION['email'] = $email;
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

                syncUserProfileReferences($conn, $user_id, $full_name, $email, $phone);
                if ($name_changed) {
                    $success_msg = "Profile updated successfully. Your full name can be changed again after 7 days.";
                } else {
                    $success_msg = "Profile updated successfully!";
                }
                $user = myAccountGetUserById($conn, $user_id) ?? $user;
                $name_change_window = getUserNameChangeWindow($conn, $user_id, 7);
                if (($name_change_window['success'] ?? false) && !($name_change_window['can_change'] ?? true) && !empty($name_change_window['next_allowed_at'])) {
                    $name_change_hint = 'You can change your full name again on ' . date('F j, Y g:i A', strtotime((string)$name_change_window['next_allowed_at'])) . '.';
                }
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

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $default_account_tab = 'password';
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($my_account_csrf, $posted_token)) {
        $error_msg = 'Invalid request token. Please refresh the page and try again.';
    } elseif (!($password_change_window['success'] ?? false)) {
        $error_msg = (string)($password_change_window['message'] ?? 'Password security checks are unavailable right now. Please try again later.');
    } elseif (!($password_change_window['can_change'] ?? true)) {
        $error_msg = (string)($password_change_window['message'] ?? 'Password cooldown is active. Please try again later.');
    } else {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        
        // Verify current password
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
                            $transaction_started = false;
                            if (mysqli_begin_transaction($conn)) {
                                $transaction_started = true;
                            }

                            if (!$transaction_started) {
                                $error_msg = "Unable to start a secure password update transaction.";
                            } elseif (!mysqli_stmt_execute($update_stmt)) {
                                $error_msg = "Failed to change password. Please try again.";
                            } elseif (!myAccountRecordPasswordChange($conn, $user_id)) {
                                $error_msg = "Unable to apply password security cooldown. Please try again.";
                            } elseif (!mysqli_commit($conn)) {
                                $error_msg = "Failed to finalize password update. Please try again.";
                            } else {
                                $success_msg = "Password changed successfully! You can update it again after {$password_cooldown_days} days.";
                                $user = myAccountGetUserById($conn, $user_id) ?? $user;
                                $password_change_window = myAccountGetPasswordChangeWindow($conn, $user_id, $password_cooldown_days);
                            }

                            if ($transaction_started && $error_msg !== '') {
                                mysqli_rollback($conn);
                            }

                            mysqli_stmt_close($update_stmt);
                        }
                    }
                } else {
                    $error_msg = "New password must be at least 8 characters long.";
                }
            } else {
                $error_msg = "New passwords do not match.";
            }
        } else {
            $error_msg = "Current password is incorrect.";
        }
    }
    if (($password_change_window['success'] ?? false) && !($password_change_window['can_change'] ?? true) && !empty($password_change_window['next_allowed_at'])) {
        $password_change_hint = 'Password updates are limited to once every ' . $password_cooldown_days . ' days. You can change it again on ' . date('F j, Y g:i A', strtotime((string)$password_change_window['next_allowed_at'])) . '.';
    } else {
        $password_change_hint = '';
    }
    $password_change_blocked = (($password_change_window['success'] ?? false) && !($password_change_window['can_change'] ?? true));
}

// Account summary metrics
$order_stats = [
    'total_orders' => 0,
    'active_orders' => 0,
    'completed_orders' => 0,
    'total_spent' => 0.00
];

$stats_query = "SELECT 
    COUNT(*) AS total_orders,
    SUM(CASE WHEN status IN ('pending', 'confirmed', 'processing', 'shipped') THEN 1 ELSE 0 END) AS active_orders,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS completed_orders,
    SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END) AS total_spent
    FROM orders WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $stats_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $stats_result = mysqli_stmt_get_result($stmt);
    $stats_row = $stats_result ? mysqli_fetch_assoc($stats_result) : null;
    if ($stats_row) {
        $order_stats['total_orders'] = (int)($stats_row['total_orders'] ?? 0);
        $order_stats['active_orders'] = (int)($stats_row['active_orders'] ?? 0);
        $order_stats['completed_orders'] = (int)($stats_row['completed_orders'] ?? 0);
        $order_stats['total_spent'] = (float)($stats_row['total_spent'] ?? 0);
    }
    mysqli_stmt_close($stmt);
}

// Fetch latest franchise application once and reuse in UI
$franchise = null;
$franchise_query = "SELECT * FROM franchise_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $franchise_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $franchise_result = mysqli_stmt_get_result($stmt);
    $franchise = $franchise_result ? mysqli_fetch_assoc($franchise_result) : null;
    mysqli_stmt_close($stmt);
}

if ($franchise && ($franchise['status'] ?? '') === 'approved') {
    $_SESSION['account_type'] = 'organization';
}

$session_account_type = strtolower(trim((string)($_SESSION['account_type'] ?? '')));
$user_account_type = strtolower(trim((string)($user['account_type'] ?? 'individual')));
$effective_account_type = $session_account_type !== '' ? $session_account_type : $user_account_type;
$is_organization_account = ($effective_account_type === 'organization');
$avatar_column_for_account = ($is_organization_account && $business_logo_column_exists) ? 'business_logo' : 'profile_image';
$avatar_column_exists_for_account = ($avatar_column_for_account === 'business_logo') ? $business_logo_column_exists : $profile_image_column_exists;
$avatar_asset_type = $avatar_column_for_account === 'business_logo' ? 'business' : 'profile';
$avatar_field_label = $avatar_column_for_account === 'business_logo' ? 'Business Logo' : 'Profile Picture';

$profile_image_relative_path = '';
if ($avatar_column_exists_for_account) {
    $profile_image_relative_path = myAccountNormalizeAvatarPath($user[$avatar_column_for_account] ?? '');
    if ($profile_image_relative_path !== '' && !is_file(__DIR__ . '/' . $profile_image_relative_path)) {
        $profile_image_relative_path = '';
    }
}
if ($profile_image_relative_path !== '') {
    $_SESSION['profile_image'] = $profile_image_relative_path;
} else {
    unset($_SESSION['profile_image']);
}
if ($avatar_column_for_account === 'business_logo') {
    $_SESSION['business_logo'] = $profile_image_relative_path !== '' ? $profile_image_relative_path : null;
}
$has_profile_image = $profile_image_relative_path !== '';

$avatar_name_source = $is_organization_account ? trim((string)($user['business_name'] ?? '')) : '';
if ($avatar_name_source === '') {
    $avatar_name_source = trim((string)($user['full_name'] ?? 'User'));
}
$name_parts = preg_split('/\s+/', $avatar_name_source);
$avatar_initials = '';
if (!empty($name_parts[0])) {
    $avatar_initials .= strtoupper(substr($name_parts[0], 0, 1));
}
if (!empty($name_parts[1])) {
    $avatar_initials .= strtoupper(substr($name_parts[1], 0, 1));
}
if ($avatar_initials === '') {
    $avatar_initials = 'U';
}

$franchise_status_label = $franchise ? ucfirst((string)($franchise['status'] ?? 'pending')) : 'No Application';

$page_title = "My Account | Lechon Delights";
include 'includes/header.php';
?>

<!-- Leaflet Map CSS and JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.account-page {
    --acc-red: #b3261e;
    --acc-orange: #ef6b2e;
    --acc-ink: #171922;
    --acc-muted: #667085;
    --acc-border: #efddcd;
    --acc-shadow: 0 14px 34px rgba(23, 25, 34, 0.06);
    padding: 40px 0 80px;
    min-height: 85vh;
    font-family: 'Plus Jakarta Sans', sans-serif;
    background:
        radial-gradient(circle at 95% -5%, rgba(239, 107, 46, 0.15), transparent 40%),
        radial-gradient(circle at 0% 20%, rgba(179, 38, 30, 0.08), transparent 38%),
        linear-gradient(180deg, #fff9f2 0%, #fff4e8 100%);
}

.account-page .container {
    max-width: 1240px;
    margin: 0 auto;
    padding: 0 16px;
}

.account-hero {
    background: linear-gradient(135deg, #fff0f3 0%, #ffe5ea 40%, #fff8f0 100%) !important;
    color: #171922;
    border-radius: 24px;
    padding: 32px 36px;
    box-shadow: 0 10px 28px rgba(74, 32, 20, 0.06);
    border: 1px solid #ffd0d8;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}

.account-hero-arch {
    position: absolute;
    right: -20px;
    top: -30px;
    bottom: -30px;
    width: 280px;
    background: rgba(255, 255, 255, 0.65);
    border-radius: 50% 0 0 50%;
    pointer-events: none;
    z-index: 1;
    transition: transform 0.3s ease;
}

.account-hero:hover .account-hero-arch {
    transform: scale(1.05);
}

.hero-main {
    display: flex;
    align-items: center;
    gap: 22px;
    position: relative;
    z-index: 2;
}

.avatar-pill {
    width: 76px;
    height: 76px;
    border-radius: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.6rem;
    font-family: 'Outfit', sans-serif;
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    color: #ffffff;
    border: 3px solid #ffffff;
    box-shadow: 0 8px 20px rgba(179, 38, 30, 0.22);
    flex-shrink: 0;
}

.avatar-pill.has-image {
    padding: 0;
    overflow: hidden;
    background: #ffffff;
}

.avatar-pill-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.market-kicker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    background: #ffe8d2;
    color: #b3261e;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.account-page h1 {
    margin: 0 0 6px;
    color: #171922;
    letter-spacing: -0.03em;
    font-family: 'Outfit', sans-serif;
    font-size: clamp(1.6rem, 2.6vw, 2.2rem);
    font-weight: 800;
    line-height: 1.15;
}

.account-subtitle {
    margin: 0;
    color: #667085;
    font-size: 0.94rem;
    line-height: 1.5;
    max-width: 780px;
}

.hero-badges {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    position: relative;
    z-index: 2;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 999px;
    padding: 6px 14px;
    background: #ffffff;
    border: 1px solid #efddcd;
    font-size: 0.82rem;
    font-weight: 700;
    color: #171922;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
}

.hero-actions {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    position: relative;
    z-index: 2;
}

.hero-action-btn {
    border: 1px solid #efddcd;
    background: #ffffff;
    color: #171922;
    border-radius: 14px;
    padding: 9px 18px;
    font-size: 0.86rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(42, 33, 29, 0.04);
}

.hero-action-btn:hover {
    background: #fff8ef;
    border-color: #b3261e;
    color: #b3261e;
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(179, 38, 30, 0.12);
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.metric-card {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 20px;
    padding: 18px 20px;
    box-shadow: 0 8px 24px rgba(42, 33, 29, 0.04);
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
    position: relative;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-3px);
    border-color: #e8d4c3;
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.1);
}

.metric-card small {
    display: block;
    color: #7b6d64;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 800;
    margin-bottom: 6px;
}

.metric-card strong {
    color: #171922;
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.alert {
    padding: 16px 20px;
    border-radius: 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    font-size: 0.92rem;
}

.alert-success {
    background-color: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
    border-left: 5px solid #2e7d32;
}

.alert-error {
    background-color: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
    border-left: 5px solid #b3261e;
}

.account-tabs {
    display: flex;
    gap: 8px;
    background-color: #ffffff;
    border-radius: 20px;
    padding: 8px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    border: 1px solid #efddcd;
    box-shadow: 0 8px 24px rgba(42, 33, 29, 0.04);
}

.tab-btn {
    flex: 1;
    min-width: 140px;
    padding: 12px 18px;
    background: none;
    border: none;
    border-radius: 14px;
    font-size: 0.92rem;
    font-weight: 700;
    color: #7b6d64;
    cursor: pointer;
    transition: all 0.22s ease;
    text-align: center;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.tab-btn:hover {
    color: #b3261e;
    background-color: #fff8ef;
}

.tab-btn.active {
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(179, 38, 30, 0.25);
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

.address-book-pane {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 24px;
    box-shadow: 0 10px 28px rgba(74, 32, 20, 0.06);
    overflow: hidden;
}

.address-book-frame {
    width: 100%;
    min-height: 860px;
    border: none;
    display: block;
    background: transparent;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.profile-card,
.orders-card,
.franchise-card,
.password-card {
    background-color: #ffffff;
    padding: 32px;
    border-radius: 24px;
    border: 1px solid #efddcd;
    box-shadow: 0 10px 28px rgba(74, 32, 20, 0.06);
    margin-bottom: 30px;
}

.card-head {
    margin-bottom: 22px;
    border-bottom: 1px solid #f3e8de;
    padding-bottom: 18px;
}

.profile-card h2,
.orders-card h2,
.franchise-card h2,
.password-card h2 {
    color: #171922;
    margin: 0 0 4px;
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.card-head p {
    margin: 0;
    color: #667085;
    font-size: 0.92rem;
}

.info-callout {
    background: #fff6ed;
    border: 1px solid #efddcd;
    border-left: 4px solid #ef6b2e;
    padding: 14px 18px;
    border-radius: 16px;
    margin-bottom: 24px;
    font-size: 0.9rem;
    color: #171922;
    display: flex;
    align-items: center;
    gap: 12px;
}

.info-callout i {
    font-size: 1.15rem;
    color: #ef6b2e;
    flex-shrink: 0;
}

.btn-primary {
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    color: #ffffff;
    border: 0;
    padding: 12px 24px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.92rem;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(179, 38, 30, 0.2);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(179, 38, 30, 0.3);
    color: #ffffff;
}

.btn-outline {
    background: #ffffff;
    color: #171922;
    border: 1px solid #efddcd;
    padding: 10px 18px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-outline:hover {
    background: #fff8ef;
    border-color: #b3261e;
    color: #b3261e;
    transform: translateY(-1px);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 22px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #171922;
    font-weight: 700;
    font-size: 0.92rem;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 13px 16px;
    border: 1px solid #efddcd;
    border-radius: 14px;
    font-size: 0.95rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    background: #fff8f0;
    color: #171922;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #b3261e;
    box-shadow: 0 0 0 4px rgba(239, 107, 46, 0.12);
    background: #ffffff;
}

.form-group input:disabled {
    background-color: #f8fafc;
    cursor: not-allowed;
    color: #7b6d64;
}

.profile-upload-row {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
    background: #fff8f0;
    border: 1px solid #efddcd;
    border-radius: 18px;
    padding: 18px;
}

.profile-upload-preview {
    width: 84px;
    height: 84px;
    border-radius: 20px;
    border: 2px solid #ffffff;
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    font-family: 'Outfit', sans-serif;
    font-weight: 800;
    color: #ffffff;
    overflow: hidden;
    box-shadow: 0 6px 16px rgba(179, 38, 30, 0.18);
}

.profile-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.profile-upload-fields {
    flex: 1;
    min-width: min(100%, 260px);
}

.upload-note {
    margin: 6px 0 0;
    color: #7b6d64;
    font-size: 0.83rem;
    line-height: 1.4;
}

.checkbox-label {
    margin-top: 10px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #171922;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}

.orders-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-item {
    background-color: #ffffff;
    padding: 22px;
    border-radius: 20px;
    border: 1px solid #efddcd;
    border-left: 5px solid #b3261e;
    box-shadow: 0 6px 18px rgba(42, 33, 29, 0.04);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.order-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 26px rgba(179, 38, 30, 0.08);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px dashed #f3e8de;
    padding-bottom: 10px;
}

.order-number {
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: #171922;
    font-size: 1.1rem;
}

.order-date {
    color: #667085;
    font-size: 0.85rem;
    font-weight: 600;
}

.order-details {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.order-status {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-transform: capitalize;
}

.status-pending { background-color: #fff3e0; color: #f57c00; border: 1px solid #ffe0b2; }
.status-assigned, .status-picked_up, .status-on_the_way, .status-arriving { background-color: #fff0eb; color: #b3261e; border: 1px solid #efddcd; }
.status-delivered, .status-completed { background-color: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
.status-cancelled, .status-rejected { background-color: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

.order-total {
    font-weight: 800;
    font-family: 'Outfit', sans-serif;
    color: #b3261e;
    font-size: 1.2rem;
}

.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: #7b6d64;
}

.empty-state i {
    font-size: 3.2rem;
    color: #ef6b2e;
    margin-bottom: 12px;
}
</style>


<div class="account-page">
    <div class="container">
        <section class="account-hero">
            <div class="account-hero-arch"></div>
            <div class="hero-main">
                <div class="avatar-pill<?php echo $has_profile_image ? ' has-image' : ''; ?>">
                    <?php if ($has_profile_image): ?>
                    <img src="<?php echo htmlspecialchars($profile_image_relative_path); ?>" alt="Profile picture" class="avatar-pill-image">
                    <?php else: ?>
                    <?php echo htmlspecialchars($avatar_initials); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="market-kicker"><i class="fas fa-crown"></i> Customer Workspace</span>
                    <h1><?php echo htmlspecialchars($user['full_name'] ?? 'My Account'); ?></h1>
                    <p class="account-subtitle">Manage profile details, track orders, check franchise status, and secure your account from one clean dashboard.</p>
                </div>
            </div>
            <div class="hero-badges">
                <span class="hero-badge"><i class="fas fa-store"></i> Franchise: <?php echo htmlspecialchars($franchise_status_label); ?></span>
                <?php if (!empty($user['email'])): ?>
                <span class="hero-badge"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                <?php endif; ?>
            </div>
            <div class="hero-actions">
                <button type="button" class="hero-action-btn open-tab-trigger" data-target-tab="profile">
                    <i class="fas fa-user-pen"></i> Edit Profile
                </button>
                <button type="button" class="hero-action-btn open-tab-trigger" data-target-tab="password">
                    <i class="fas fa-key"></i> Security
                </button>
            </div>
        </section>

        <section class="metrics-grid">
            <article class="metric-card">
                <small><i class="fas fa-box" style="color: #ef6b2e; margin-right: 4px;"></i> Total Orders</small>
                <strong><?php echo (int)$order_stats['total_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small><i class="fas fa-motorcycle" style="color: #b3261e; margin-right: 4px;"></i> Active Orders</small>
                <strong><?php echo (int)$order_stats['active_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small><i class="fas fa-check-circle" style="color: #2e7d32; margin-right: 4px;"></i> Completed</small>
                <strong><?php echo (int)$order_stats['completed_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small><i class="fas fa-wallet" style="color: #d97706; margin-right: 4px;"></i> Total Spent</small>
                <strong>&#8369;<?php echo number_format((float)$order_stats['total_spent'], 2); ?></strong>
            </article>
        </section>
        
        <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_msg): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
        </div>
        <?php endif; ?>
        
        <div class="account-tabs">
            <button class="tab-btn active" data-tab="profile"><i class="fas fa-id-badge"></i> Profile</button>
            <button class="tab-btn" data-tab="addresses"><i class="fas fa-address-book"></i> Address Book</button>
            <button class="tab-btn" data-tab="orders"><i class="fas fa-receipt"></i> Orders</button>
            <button class="tab-btn" data-tab="franchise"><i class="fas fa-store"></i> Franchise</button>
            <button class="tab-btn" data-tab="password"><i class="fas fa-lock"></i> Password</button>
        </div>
        
        <div class="tab-content">
            <!-- Profile Tab -->
            <div class="tab-pane active" id="profile">
                <div class="profile-card">
                    <div class="card-head">
                        <h2>Profile Information</h2>
                        <p>Keep your contact details updated for smoother order coordination.</p>
                    </div>
                    <div class="info-callout">
                        <i class="fas fa-circle-info"></i>
                        <span>Your contact name and phone number are automatically used for delivery receipts and SMS order notifications.</span>
                    </div>
                    <form method="POST" action="" id="profileForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($my_account_csrf); ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" 
                                    value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                <p class="upload-note">For security, full name updates are limited to once every 7 days.</p>
                                <?php if ($name_change_hint !== ''): ?>
                                <p class="upload-note"><?php echo htmlspecialchars($name_change_hint); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                                <p class="upload-note">Email is protected. Contact support or an administrator for email changes.</p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" 
                                    value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="created_at">Member Since</label>
                                <input type="text" id="created_at" value="<?php echo !empty($user['created_at']) ? htmlspecialchars(date('F j, Y', strtotime($user['created_at']))) : '-'; ?>" disabled>
                            </div>
                        </div>

                        <?php if ($is_organization_account): ?>
                        <div class="form-row">
                            <?php if ($business_name_column_exists): ?>
                            <div class="form-group">
                                <label for="business_name">Business Name</label>
                                <input type="text" id="business_name" name="business_name" value="<?php echo htmlspecialchars((string)($user['business_name'] ?? '')); ?>" required>
                            </div>
                            <?php endif; ?>
                            <?php if ($business_type_column_exists): ?>
                            <div class="form-group">
                                <label for="business_type">Business Type</label>
                                <input type="text" id="business_type" name="business_type" value="<?php echo htmlspecialchars((string)($user['business_type'] ?? '')); ?>" placeholder="e.g. Restaurant">
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="account_avatar"><?php echo htmlspecialchars($avatar_field_label); ?></label>
                            <?php if ($avatar_column_exists_for_account): ?>
                            <div class="profile-upload-row">
                                <div class="profile-upload-preview" id="profileImagePreview">
                                    <?php if ($has_profile_image): ?>
                                    <img src="<?php echo htmlspecialchars($profile_image_relative_path); ?>" alt="Current avatar">
                                    <?php else: ?>
                                    <span><?php echo htmlspecialchars($avatar_initials); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="profile-upload-fields">
                                    <input type="file" id="account_avatar" name="account_avatar" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <p class="upload-note">Accepted formats: JPG, PNG, WEBP. Maximum file size: 5MB.</p>
                                    <?php if ($has_profile_image): ?>
                                    <label class="checkbox-label" for="remove_account_avatar">
                                        <input type="checkbox" name="remove_account_avatar" id="remove_account_avatar" value="1">
                                        Remove current <?php echo htmlspecialchars(strtolower($avatar_field_label)); ?>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <p class="upload-note schema-note"><?php echo htmlspecialchars($avatar_field_label); ?> upload needs the latest schema. Run <code>database/schema_updates/run.php</code> once to enable this field.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="quick_address_select">Saved Addresses</label>
                            <select id="quick_address_select">
                                <option value="">Select a saved address...</option>
                                <?php if ($current_profile_address !== ''): ?>
                                <option value="profile_current" data-address="<?php echo htmlspecialchars($current_profile_address); ?>">
                                    Current profile address
                                </option>
                                <?php endif; ?>
                                <?php foreach ($quick_saved_addresses as $saved_address): ?>
                                <?php
                                    $saved_label = trim((string)($saved_address['label'] ?? 'Saved Address'));
                                    if ($saved_label === '') {
                                        $saved_label = 'Saved Address';
                                    }
                                    $saved_full_address = trim((string)($saved_address['full_address'] ?? ''));
                                    $saved_option_value = 'saved_' . (int)($saved_address['id'] ?? 0);
                                    $saved_default_tag = !empty($saved_address['is_default']) ? ' (Default)' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($saved_option_value); ?>" data-address="<?php echo htmlspecialchars($saved_full_address); ?>">
                                    <?php echo htmlspecialchars($saved_label . $saved_default_tag . ': ' . $saved_full_address); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="upload-note">Pick a saved address to auto-fill the field below.</p>
                            <?php if (empty($quick_saved_addresses)): ?>
                            <p class="upload-note">No saved addresses yet. Add one in Address Book.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            <p class="inline-action">
                                <button type="button" class="btn-outline btn-inline open-tab-trigger" data-target-tab="addresses">
                                    <i class="fas fa-address-book"></i> Manage Address Book
                                </button>
                            </p>
                        </div>
                        
                        <!-- Leaflet Pin Location Map -->
                        <div class="form-group">
                            <label>Pin Your Location</label>
                            <div id="profileMap" style="height: 300px; width: 100%; border-radius: 8px; border: 1px solid #efddcd; margin-bottom: 8px; z-index: 1;"></div>
                            <p class="upload-note">Click or drag on the map to pin your exact location. This helps delivery drivers find you easily.</p>
                            <input type="hidden" name="latitude" id="profile_latitude" value="<?php echo htmlspecialchars($user['latitude'] ?? ''); ?>">
                            <input type="hidden" name="longitude" id="profile_longitude" value="<?php echo htmlspecialchars($user['longitude'] ?? ''); ?>">
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>

            <!-- Address Book Tab -->
            <div class="tab-pane" id="addresses">
                <div class="address-book-pane">
                    <iframe
                        id="addressBookFrame"
                        src="my_account_address_book.php?embedded=1"
                        class="address-book-frame"
                        title="My Address Book"
                        loading="lazy"></iframe>
                </div>
            </div>
            
            <!-- Orders Tab -->
            <div class="tab-pane" id="orders">
                <div class="orders-card">
                    <div class="card-head">
                        <h2>My Orders</h2>
                        <p>Review status, amount, and order history in one timeline.</p>
                    </div>
                    <div class="info-callout">
                        <i class="fas fa-truck-fast"></i>
                        <span>Active orders feature live rider tracking. Click <strong>Track Delivery</strong> to view your rider moving in real-time on the map.</span>
                    </div>
                    <?php
                    // Get user orders
                    $orders_query = "SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC";
                    $stmt = mysqli_prepare($conn, $orders_query);
                    mysqli_stmt_bind_param($stmt, "i", $user_id);
                    mysqli_stmt_execute($stmt);
                    $orders_result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($orders_result) > 0): ?>
                    <div class="orders-list">
                        <?php while ($order = mysqli_fetch_assoc($orders_result)): 
                            $status_clean = strtolower((string)$order['status']);
                            $is_active_order = in_array($status_clean, ['assigned', 'picked_up', 'on_the_way', 'arriving', 'pending', 'confirmed', 'preparing'], true);
                        ?>
                        <div class="order-item">
                            <div class="order-header">
                                <div class="order-number"><i class="fas fa-receipt" style="color:#ef6b2e; margin-right:6px;"></i> Order #<?php echo htmlspecialchars($order['order_number']); ?></div>
                                <div class="order-date"><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y • g:i A', strtotime($order['created_at'])); ?></div>
                            </div>
                            <div class="order-details">
                                <div class="order-status status-<?php echo $status_clean; ?>">
                                    <?php if ($is_active_order): ?>
                                    <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#b3261e; animation:blink 1.5s infinite; margin-right:6px;"></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['status']))); ?>
                                </div>
                                <div class="order-total">&#8369;<?php echo number_format((float)$order['total_amount'], 2); ?></div>
                            </div>
                            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:6px;">
                                <?php if ($is_active_order): ?>
                                <a href="track_order.php?order_id=<?php echo $order['id']; ?>" class="btn-primary" style="padding: 8px 16px; font-size:0.85rem;"><i class="fas fa-motorcycle"></i> Track Delivery</a>
                                <?php else: ?>
                                <a href="track_order.php?order_id=<?php echo $order['id']; ?>" class="btn-outline" style="padding: 8px 16px; font-size:0.85rem;"><i class="fas fa-eye"></i> View Details</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-bag"></i>
                        <p>You haven't placed any orders yet.</p>
                        <a href="menu.php" class="btn-primary">Order Now</a>
                    </div>
                    <?php endif; ?>
                    <?php mysqli_stmt_close($stmt); ?>
                </div>
            </div>
            
            <!-- Franchise Tab -->
            <div class="tab-pane" id="franchise">
                <div class="franchise-card">
                    <div class="card-head">
                        <h2>Franchise Application</h2>
                        <p>Track your business application status and next available actions.</p>
                    </div>
                    
                    <?php if ($franchise): ?>
                    <div class="application-status">
                        <h3>Application Status</h3>
                        <div class="status-card status-<?php echo $franchise['status']; ?>">
                            <div class="status-header">
                                <h4>Application #<?php echo $franchise['application_number']; ?></h4>
                                <span class="status-badge"><?php echo ucfirst($franchise['status']); ?></span>
                            </div>
                            <p>Submitted on: <?php echo date('F j, Y, g:i a', strtotime($franchise['created_at'])); ?></p>
                            
                            <?php if ($franchise['status'] == 'approved'): ?>
                            <div class="status-message success">
                                <i class="fas fa-check-circle"></i>
                                <p>Congratulations! Your franchise application has been approved.</p>
                            </div>
                            <div class="status-actions">
                                <a href="seller_products.php" class="btn-primary">
                                    <i class="fas fa-box"></i> Manage My Products
                                </a>
                                <a href="locations.php" class="btn-outline">
                                    <i class="fas fa-map-marker-alt"></i> View Store In Locations
                                </a>
                            </div>
                            <?php elseif ($franchise['status'] == 'rejected'): ?>
                            <div class="status-message error">
                                <i class="fas fa-times-circle"></i>
                                <p>Your application has been rejected. Reason: <?php echo htmlspecialchars($franchise['admin_notes']); ?></p>
                            </div>
                            <?php elseif ($franchise['status'] == 'pending'): ?>
                            <div class="status-message info">
                                <i class="fas fa-clock"></i>
                                <p>Your application is under review. We'll notify you once it's processed.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($franchise['status'] != 'pending'): ?>
                        <a href="franchise_application.php" class="btn-primary">Apply Again</a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="application-guide">
                        <h3>Apply for a Lechon Delights Franchise</h3>
                        <p>Want to own and operate your own Lechon Delights store? Fill out our franchise application form.</p>
                        
                        <div class="requirements-list">
                            <h4>Requirements:</h4>
                            <ol>
                                <li>Business Registration (DTI/SEC)</li>
                                <li>Local Permits (Barangay Clearance & Mayor's Permit)</li>
                                <li>BIR Registration</li>
                                <li>Sanitary Permit</li>
                                <li>Business Bank Account</li>
                            </ol>
                        </div>
                        
                        <a href="franchise_application.php" class="btn-primary btn-large">
                            <i class="fas fa-store"></i> Start Franchise Application
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Password Tab -->
            <div class="tab-pane" id="password">
                <div class="password-card">
                    <div class="card-head">
                        <h2>Change Password</h2>
                        <p>Use a strong password with uppercase, lowercase, number, and symbol.</p>
                        <?php if ($password_change_hint !== ''): ?>
                        <p class="upload-note"><?php echo htmlspecialchars($password_change_hint); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="info-callout">
                        <i class="fas fa-shield-halved"></i>
                        <span>For account security, password updates require your current password and trigger a 7-day cooldown lock.</span>
                    </div>
                    <form method="POST" action="" id="passwordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($my_account_csrf); ?>">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="password-strength">
                            <span>Password strength:</span>
                            <div class="strength-bars">
                                <div class="strength-bar" id="strengthBar1"></div>
                                <div class="strength-bar" id="strengthBar2"></div>
                                <div class="strength-bar" id="strengthBar3"></div>
                                <div class="strength-bar" id="strengthBar4"></div>
                            </div>
                        </div>
                        
                        <button type="submit" name="change_password" class="btn-primary" <?php echo $password_change_blocked ? 'disabled' : ''; ?>>
                            <?php echo $password_change_blocked ? 'Password Cooldown Active' : 'Change Password'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <nav class="mobile-tab-bar" aria-label="Account sections">
            <button type="button" class="mobile-tab-btn active" data-tab="profile">
                <i class="fas fa-id-badge"></i>
                <span>Profile</span>
            </button>
            <button type="button" class="mobile-tab-btn" data-tab="orders">
                <i class="fas fa-receipt"></i>
                <span>Orders</span>
            </button>
            <button type="button" class="mobile-tab-btn" data-tab="addresses">
                <i class="fas fa-address-book"></i>
                <span>Address</span>
            </button>
            <button type="button" class="mobile-tab-btn" data-tab="franchise">
                <i class="fas fa-store"></i>
                <span>Franchise</span>
            </button>
            <button type="button" class="mobile-tab-btn" data-tab="password">
                <i class="fas fa-lock"></i>
                <span>Password</span>
            </button>
        </nav>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountPage = document.querySelector('.account-page');
    const topTabBtns = document.querySelectorAll('button.tab-btn[data-tab]');
    const mobileTabBtns = document.querySelectorAll('button.mobile-tab-btn[data-tab]');
    const openTabTriggers = document.querySelectorAll('.open-tab-trigger[data-target-tab]');
    const tabPanes = document.querySelectorAll('.tab-pane');
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const profileForm = document.getElementById('profileForm');
    const quickAddressSelect = document.getElementById('quick_address_select');
    const profileAddressInput = document.getElementById('address');
    const profileImageInput = document.getElementById('account_avatar');
    const profileImagePreview = document.getElementById('profileImagePreview');
    const removeProfileImageCheckbox = document.getElementById('remove_account_avatar');
    const passwordForm = document.getElementById('passwordForm');
    const addressBookFrame = document.getElementById('addressBookFrame');
    const serverSuccessMsg = <?php echo json_encode((string)$success_msg); ?>;
    const serverErrorMsg = <?php echo json_encode((string)$error_msg); ?>;
    const defaultTab = <?php echo json_encode((string)$default_account_tab); ?> || 'profile';
    const THEME_KEY = 'myAccountTheme';
    const profileImageFallbackInitials = <?php echo json_encode((string)$avatar_initials); ?> || 'U';
    const ADDRESS_BOOK_MESSAGE_TYPE = 'lechon_address_book_updated';
    const pageOrigin = (window.location.origin && window.location.origin !== 'null') ? window.location.origin : '';

    function fireSwal(options) {
        if (typeof Swal === 'undefined') return Promise.resolve(null);
        return Swal.fire(options);
    }

    function ensureHiddenActionField(form, fieldName, fieldValue) {
        if (!form || !fieldName) return;
        let hiddenField = form.querySelector('input[type="hidden"][name="' + fieldName + '"]');
        if (!hiddenField) {
            hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = fieldName;
            form.appendChild(hiddenField);
        }
        hiddenField.value = String(fieldValue ?? '1');
    }

    function showToast(icon, title) {
        if (typeof Swal === 'undefined' || !title) return;
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            showConfirmButton: false,
            timer: 2600,
            timerProgressBar: true
        });
    }

    function normalizeAddressValue(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function rebuildQuickAddressSelect(nextAddresses, options = {}) {
        if (!quickAddressSelect) return;

        const preserveSelection = options.preserveSelection !== false;
        const includeCurrentAddress = options.includeCurrentAddress !== false;
        const previousValue = preserveSelection ? String(quickAddressSelect.value || '') : '';
        const currentInputAddress = normalizeAddressValue(profileAddressInput ? profileAddressInput.value : '');
        const rows = Array.isArray(nextAddresses) ? nextAddresses : [];
        const seenAddresses = new Set();

        const fragment = document.createDocumentFragment();
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = 'Select a saved address...';
        fragment.appendChild(placeholderOption);

        if (includeCurrentAddress && currentInputAddress !== '') {
            const currentOpt = document.createElement('option');
            currentOpt.value = 'profile_current';
            currentOpt.textContent = 'Current profile address';
            currentOpt.setAttribute('data-address', currentInputAddress);
            fragment.appendChild(currentOpt);
            seenAddresses.add(currentInputAddress.toLowerCase());
        }

        rows.forEach((row, index) => {
            const fullAddress = normalizeAddressValue(row && row.full_address ? row.full_address : '');
            if (fullAddress === '') return;
            const normalizedKey = fullAddress.toLowerCase();
            if (seenAddresses.has(normalizedKey)) return;
            seenAddresses.add(normalizedKey);

            const idValue = row && row.id ? String(row.id) : String(index + 1);
            const optionValue = 'saved_' + idValue;
            const label = normalizeAddressValue(row && row.label ? row.label : 'Saved Address') || 'Saved Address';
            const isDefault = !!(row && row.is_default);

            const option = document.createElement('option');
            option.value = optionValue;
            option.setAttribute('data-address', fullAddress);
            option.textContent = label + (isDefault ? ' (Default)' : '') + ': ' + fullAddress;
            fragment.appendChild(option);
        });

        quickAddressSelect.innerHTML = '';
        quickAddressSelect.appendChild(fragment);

        const hasPreviousOption = previousValue !== '' && Array.from(quickAddressSelect.options).some((option) => option.value === previousValue);
        if (preserveSelection && hasPreviousOption) {
            quickAddressSelect.value = previousValue;
        } else {
            syncQuickAddressSelectionWithInput();
        }
    }

    function extractAddressesFromAddressBookFrame() {
        if (!addressBookFrame || !addressBookFrame.contentDocument) return [];
        const frameDocument = addressBookFrame.contentDocument;
        const items = frameDocument.querySelectorAll('.address-item');
        const addresses = [];
        items.forEach((item, index) => {
            const fullAddress = normalizeAddressValue(item.querySelector('.address-line') ? item.querySelector('.address-line').textContent : '');
            if (fullAddress === '') return;
            const label = normalizeAddressValue(item.querySelector('.address-item-head h3') ? item.querySelector('.address-item-head h3').textContent : 'Saved Address') || 'Saved Address';
            const idInput = item.querySelector('input[name="address_id"]');
            const parsedId = idInput ? parseInt(String(idInput.value || ''), 10) : NaN;
            const isDefault = item.classList.contains('is-default') || !!item.querySelector('.default-badge');
            addresses.push({
                id: Number.isFinite(parsedId) ? parsedId : (index + 1),
                label: label,
                full_address: fullAddress,
                is_default: isDefault
            });
        });
        return addresses;
    }

    function refreshQuickAddressFromAddressBookFrame() {
        const frameAddresses = extractAddressesFromAddressBookFrame();
        rebuildQuickAddressSelect(frameAddresses, { preserveSelection: true, includeCurrentAddress: true });
    }

    function renderProfilePreviewImage(src) {
        if (!profileImagePreview) return;
        profileImagePreview.innerHTML = '';
        const img = document.createElement('img');
        img.src = src;
        img.alt = 'Profile picture preview';
        profileImagePreview.appendChild(img);
    }

    function renderProfilePreviewInitials() {
        if (!profileImagePreview) return;
        profileImagePreview.innerHTML = '';
        const initials = document.createElement('span');
        initials.textContent = profileImageFallbackInitials;
        profileImagePreview.appendChild(initials);
    }

    function setTheme(mode) {
        if (!accountPage) return;
        const isDark = mode === 'dark';
        accountPage.classList.toggle('theme-dark', isDark);
        if (addressBookFrame && addressBookFrame.contentDocument && addressBookFrame.contentDocument.body) {
            addressBookFrame.contentDocument.body.classList.toggle('theme-dark', isDark);
        }
        if (themeToggleBtn) {
            themeToggleBtn.innerHTML = isDark
                ? '<i class="fas fa-sun"></i><span>Light Mode</span>'
                : '<i class="fas fa-moon"></i><span>Dark Mode</span>';
            themeToggleBtn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            themeToggleBtn.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        }
    }

    function getInitialTheme() {
        const stored = localStorage.getItem(THEME_KEY);
        if (stored === 'dark' || stored === 'light') {
            return stored;
        }
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        return prefersDark ? 'dark' : 'light';
    }

    if (addressBookFrame) {
        addressBookFrame.addEventListener('load', function() {
            const isDark = accountPage && accountPage.classList.contains('theme-dark');
            if (addressBookFrame.contentDocument && addressBookFrame.contentDocument.body) {
                addressBookFrame.contentDocument.body.classList.toggle('theme-dark', !!isDark);
            }
            window.setTimeout(function() {
                refreshQuickAddressFromAddressBookFrame();
            }, 120);
        });
    }

    window.addEventListener('message', function(event) {
        const payload = event && typeof event.data === 'object' ? event.data : null;
        if (!payload || payload.type !== ADDRESS_BOOK_MESSAGE_TYPE) {
            return;
        }
        if (pageOrigin && event.origin && event.origin !== pageOrigin) {
            return;
        }

        const incomingAddresses = Array.isArray(payload.addresses) ? payload.addresses : [];
        rebuildQuickAddressSelect(incomingAddresses, { preserveSelection: true, includeCurrentAddress: true });
        syncQuickAddressSelectionWithInput();

        const successText = normalizeAddressValue(payload.success_message || '');
        const errorText = normalizeAddressValue(payload.error_message || '');
        if (successText !== '') {
            showToast('success', successText);
        } else if (errorText !== '') {
            showToast('error', errorText);
        }
    });

    function activateTab(tabId, updateHash) {
        if (!tabId) return;

        topTabBtns.forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
        });
        mobileTabBtns.forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
        });
        tabPanes.forEach(pane => {
            pane.classList.toggle('active', pane.id === tabId);
        });

        if (updateHash) {
            window.history.replaceState(null, '', '#' + tabId);
        }
    }

    topTabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            activateTab(this.getAttribute('data-tab'), true);
        });
    });

    mobileTabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            activateTab(this.getAttribute('data-tab'), true);
        });
    });

    openTabTriggers.forEach(btn => {
        btn.addEventListener('click', function() {
            activateTab(this.getAttribute('data-target-tab'), true);
        });
    });

    const hash = window.location.hash.substring(1);
    const validTabFromHash = hash && document.getElementById(hash) ? hash : defaultTab;
    activateTab(validTabFromHash, !!hash);
    window.addEventListener('hashchange', function() {
        const changedHash = window.location.hash.substring(1);
        if (changedHash && document.getElementById(changedHash)) {
            activateTab(changedHash, false);
        }
    });

    setTheme('light');
    showToast('success', serverSuccessMsg);
    showToast('error', serverErrorMsg);
    if (typeof Swal !== 'undefined' && (serverSuccessMsg || serverErrorMsg)) {
        document.querySelectorAll('.alert').forEach((alertEl) => {
            alertEl.style.display = 'none';
        });
    }

    if (profileImageInput) {
        profileImageInput.addEventListener('change', function() {
            const nextFile = this.files && this.files[0] ? this.files[0] : null;
            if (!nextFile) {
                return;
            }

            if (removeProfileImageCheckbox) {
                removeProfileImageCheckbox.checked = false;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                if (event && event.target && typeof event.target.result === 'string') {
                    renderProfilePreviewImage(event.target.result);
                }
            };
            reader.readAsDataURL(nextFile);
        });
    }

    if (removeProfileImageCheckbox) {
        removeProfileImageCheckbox.addEventListener('change', function() {
            if (!this.checked) {
                return;
            }
            if (profileImageInput) {
                profileImageInput.value = '';
            }
            renderProfilePreviewInitials();
        });
    }

    if (profileForm) {
        profileForm.addEventListener('submit', function(event) {
            if (this.dataset.confirmed === '1' || typeof Swal === 'undefined') return;
            event.preventDefault();
            fireSwal({
                title: 'Save profile changes?',
                text: 'Your account details will be updated immediately.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, save',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#b3261e',
                cancelButtonColor: '#7a6a5e'
            }).then((result) => {
                if (result && result.isConfirmed) {
                    ensureHiddenActionField(this, 'update_profile', '1');
                    this.dataset.confirmed = '1';
                    if (typeof this.requestSubmit === 'function') {
                        this.requestSubmit();
                    } else {
                        this.submit();
                    }
                }
            });
        });
    }

    function syncQuickAddressSelectionWithInput() {
        if (!quickAddressSelect || !profileAddressInput) return;
        const typedAddress = String(profileAddressInput.value || '').trim().toLowerCase();
        if (typedAddress === '') {
            quickAddressSelect.value = '';
            return;
        }
        let matchedValue = '';
        Array.from(quickAddressSelect.options).forEach((option) => {
            if (matchedValue) return;
            const optionAddress = String(option.getAttribute('data-address') || '').trim().toLowerCase();
            if (optionAddress !== '' && optionAddress === typedAddress) {
                matchedValue = option.value;
            }
        });
        quickAddressSelect.value = matchedValue;
    }

    if (quickAddressSelect && profileAddressInput) {
        quickAddressSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex] || null;
            if (!selectedOption) return;
            const selectedAddress = String(selectedOption.getAttribute('data-address') || '').trim();
            if (selectedAddress === '') return;
            profileAddressInput.value = selectedAddress;
            profileAddressInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        profileAddressInput.addEventListener('input', function() {
            syncQuickAddressSelectionWithInput();
        });

        syncQuickAddressSelectionWithInput();
    }

    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    if (newPassword) {
        newPassword.addEventListener('input', updatePasswordStrength);
    }
    
    function updatePasswordStrength() {
        const password = this.value;
        const bars = document.querySelectorAll('.strength-bar');
        
        let strength = 0;
        
        // Check password criteria
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        // Reset all bars
        bars.forEach(bar => {
            bar.style.backgroundColor = '#e0e0e0';
        });
        
        // Color the bars based on strength
        for (let i = 0; i < strength; i++) {
            if (strength <= 1) {
                bars[i].style.backgroundColor = '#dc3545';
            } else if (strength <= 2) {
                bars[i].style.backgroundColor = '#ff9800';
            } else {
                bars[i].style.backgroundColor = '#28a745';
            }
        }
    }
    
    // Confirm password validation
    if (confirmPassword && newPassword) {
        confirmPassword.addEventListener('input', function() {
            if (this.value !== newPassword.value && this.value !== '') {
                this.style.borderColor = '#dc3545';
            } else {
                this.style.borderColor = '';
            }
        });
    }

    if (passwordForm && confirmPassword && newPassword) {
        passwordForm.addEventListener('submit', function(event) {
            const nextPassword = String(newPassword.value || '');
            const confirmValue = String(confirmPassword.value || '');

            if (nextPassword.length < 8) {
                event.preventDefault();
                fireSwal({
                    icon: 'warning',
                    title: 'Password too short',
                    text: 'Your new password must be at least 8 characters.'
                });
                return;
            }

            if (nextPassword !== confirmValue) {
                event.preventDefault();
                fireSwal({
                    icon: 'error',
                    title: 'Passwords do not match',
                    text: 'Please make sure both new password fields are identical.'
                });
                return;
            }

            if (this.dataset.confirmed === '1' || typeof Swal === 'undefined') return;
            event.preventDefault();
            fireSwal({
                title: 'Change password now?',
                text: 'You will use this new password on your next login.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, change it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#b3261e',
                cancelButtonColor: '#7a6a5e'
            }).then((result) => {
                if (result && result.isConfirmed) {
                    ensureHiddenActionField(this, 'change_password', '1');
                    this.dataset.confirmed = '1';
                    if (typeof this.requestSubmit === 'function') {
                        this.requestSubmit();
                    } else {
                        this.submit();
                    }
                }
            });
        });
    }

    // Leaflet profile map initialization
    const profileMapEl = document.getElementById('profileMap');
    if (profileMapEl && typeof L !== 'undefined') {
        const latInput = document.getElementById('profile_latitude');
        const lngInput = document.getElementById('profile_longitude');
        
        let initialLat = parseFloat(latInput.value) || 14.3294; // Default to Cavite
        let initialLng = parseFloat(lngInput.value) || 120.9367;
        
        const profileMap = L.map('profileMap').setView([initialLat, initialLng], 14);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap contributors'
        }).addTo(profileMap);
        
        let marker = L.marker([initialLat, initialLng], {
            draggable: true
        }).addTo(profileMap);
        
        const addressTextarea = document.getElementById('address');
        const reverseGeocode = async (lat, lng) => {
            try {
                if (addressTextarea) {
                    addressTextarea.value = "Fetching address for pinned location...";
                }
                const endpoint = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&countrycodes=ph&lat=${lat}&lon=${lng}`;
                const response = await fetch(endpoint, {
                    method: 'GET',
                    headers: { Accept: 'application/json' }
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data && data.display_name && addressTextarea) {
                        addressTextarea.value = data.display_name;
                    }
                }
            } catch (error) {
                console.error("Reverse geocoding failed:", error);
            }
        };

        const updateCoords = (lat, lng) => {
            latInput.value = Number(lat).toFixed(6);
            lngInput.value = Number(lng).toFixed(6);
            reverseGeocode(lat, lng);
        };
        
        // Update on marker drag end
        marker.on('dragend', function(e) {
            const position = marker.getLatLng();
            updateCoords(position.lat, position.lng);
        });
        
        // Update on map click
        profileMap.on('click', function(e) {
            const position = e.latlng;
            marker.setLatLng(position);
            updateCoords(position.lat, position.lng);
        });

        // Trigger invalidateSize on tab switch to Profile
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.dataset.tab === 'profile') {
                    setTimeout(() => {
                        profileMap.invalidateSize();
                    }, 100);
                }
            });
        });
    }
});
</script>

<?php
mysqli_close($conn);
include 'includes/footer.php';
?>
