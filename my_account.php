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
            $update_fields = ['full_name = ?', 'phone = ?', 'address = ?'];
            $bind_types = 'sss';
            $bind_values = [$full_name, $phone, $address];

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

<div class="account-page">
    <div class="container">
        <section class="account-hero">
            <div class="hero-main">
                <div class="avatar-pill<?php echo $has_profile_image ? ' has-image' : ''; ?>">
                    <?php if ($has_profile_image): ?>
                    <img src="<?php echo htmlspecialchars($profile_image_relative_path); ?>" alt="Profile picture" class="avatar-pill-image">
                    <?php else: ?>
                    <?php echo htmlspecialchars($avatar_initials); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="hero-kicker">Account Workspace</p>
                    <h1><?php echo htmlspecialchars($user['full_name'] ?? 'My Account'); ?></h1>
                    <p class="account-subtitle">Manage profile details, track orders, check franchise status, and secure your account from one clean dashboard.</p>
                </div>
            </div>
            <div class="hero-badges">
                <span class="hero-badge"><i class="fas fa-store"></i> Franchise: <?php echo htmlspecialchars($franchise_status_label); ?></span>
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <i class="fas fa-moon"></i>
                    <span>Dark Mode</span>
                </button>
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
                <small>Total Orders</small>
                <strong><?php echo (int)$order_stats['total_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small>Active Orders</small>
                <strong><?php echo (int)$order_stats['active_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small>Completed</small>
                <strong><?php echo (int)$order_stats['completed_orders']; ?></strong>
            </article>
            <article class="metric-card">
                <small>Total Spent</small>
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
                    <?php
                    // Get user orders
                    $orders_query = "SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC";
                    $stmt = mysqli_prepare($conn, $orders_query);
                    mysqli_stmt_bind_param($stmt, "i", $user_id);
                    mysqli_stmt_execute($stmt);
                    $orders_result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($orders_result) > 0): ?>
                    <div class="orders-list">
                        <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                        <div class="order-item">
                            <div class="order-header">
                                <div class="order-number">Order #<?php echo $order['order_number']; ?></div>
                                <div class="order-date"><?php echo date('F j, Y', strtotime($order['created_at'])); ?></div>
                            </div>
                            <div class="order-details">
                                <div class="order-status status-<?php echo $order['status']; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </div>
                                <div class="order-total">&#8369;<?php echo number_format($order['total_amount'], 2); ?></div>
                            </div>
                            <a href="track_order.php?order_id=<?php echo $order['id']; ?>" class="btn-outline">View Details</a>
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

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.account-page {
    --acc-red: #b3261e;
    --acc-orange: #ef6b2e;
    --acc-ink: #2f1f18;
    --acc-muted: #6b7280;
    --acc-border: #e8ddd2;
    --acc-shadow: 0 16px 36px rgba(37, 20, 12, 0.12);
    padding: 86px 0 56px;
    min-height: 100vh;
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
    background:
        radial-gradient(circle at 95% -5%, rgba(239, 107, 46, 0.2), transparent 38%),
        radial-gradient(circle at 0% 20%, rgba(179, 38, 30, 0.11), transparent 35%),
        linear-gradient(180deg, #fffaf4 0%, #fff5ea 44%, #fffdf8 100%);
}

.account-hero {
    background: linear-gradient(125deg, rgba(179, 38, 30, 0.96), rgba(239, 107, 46, 0.93));
    color: #fff;
    border-radius: 20px;
    padding: 26px;
    box-shadow: 0 20px 40px rgba(111, 33, 17, 0.28);
    margin-bottom: 20px;
}

.hero-main {
    display: flex;
    align-items: center;
    gap: 16px;
}

.hero-kicker {
    margin: 0 0 4px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    opacity: 0.86;
}

.account-page h1 {
    margin: 0 0 8px;
    color: #fff;
    letter-spacing: 0.2px;
    font-size: clamp(1.3rem, 2.3vw, 1.9rem);
}

.account-subtitle {
    margin: 0;
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.95rem;
    max-width: 800px;
}

.avatar-pill {
    width: 58px;
    height: 58px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.2rem;
    background: rgba(255, 255, 255, 0.17);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.avatar-pill.has-image {
    padding: 0;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.28);
}

.avatar-pill-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.hero-badges {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 999px;
    padding: 8px 11px;
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.27);
    font-size: 0.83rem;
    font-weight: 600;
}

.theme-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 999px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.35);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s ease;
}

.theme-toggle-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.hero-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hero-action-btn {
    border: 1px solid rgba(255, 255, 255, 0.35);
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.hero-action-btn:hover {
    background: rgba(255, 255, 255, 0.24);
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.metric-card {
    background: #fff;
    border: 1px solid var(--acc-border);
    border-radius: 14px;
    padding: 14px;
    box-shadow: var(--acc-shadow);
}

.metric-card small {
    display: block;
    color: var(--acc-muted);
    font-size: 0.82rem;
    margin-bottom: 6px;
}

.metric-card strong {
    color: var(--acc-ink);
    font-size: 1.1rem;
}

.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.account-tabs {
    display: flex;
    gap: 8px;
    background-color: #fff7f0;
    border-radius: 12px;
    padding: 7px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    border: 1px solid #f1dece;
    box-shadow: 0 12px 24px rgba(69, 37, 22, 0.08);
}

.tab-btn {
    flex: 1;
    min-width: 140px;
    padding: 13px 14px;
    background: none;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    color: #6b5a50;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.tab-link {
    text-decoration: none;
}

.tab-btn.active {
    background-color: #fff;
    color: var(--acc-red);
    box-shadow: 0 8px 14px rgba(15, 23, 42, 0.08);
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

.address-book-pane {
    background: #fff;
    border: 1px solid var(--acc-border);
    border-radius: 16px;
    box-shadow: var(--acc-shadow);
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
    from { opacity: 0; }
    to { opacity: 1; }
}

.profile-card,
.orders-card,
.franchise-card,
.password-card {
    background-color: #fff;
    padding: 28px;
    border-radius: 16px;
    border: 1px solid var(--acc-border);
    box-shadow: var(--acc-shadow);
    margin-bottom: 30px;
}

.card-head {
    margin-bottom: 18px;
}

.profile-card h2,
.orders-card h2,
.franchise-card h2,
.password-card h2 {
    color: var(--acc-ink);
    margin: 0 0 6px;
    font-size: 1.35rem;
}

.card-head p {
    margin: 0;
    color: var(--acc-muted);
    font-size: 0.9rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #3c2d25;
    font-weight: 600;
    font-size: 0.92rem;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #e1d5c8;
    border-radius: 10px;
    font-size: 0.96rem;
    transition: border-color 0.3s;
    background: #fffefb;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #d56f36;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
    background: #fff;
}

.form-group input:disabled {
    background-color: #f8f4ef;
    cursor: not-allowed;
    color: #7e6b5f;
}

.profile-upload-row {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.profile-upload-preview {
    width: 84px;
    height: 84px;
    border-radius: 14px;
    border: 1px solid #e2d2c1;
    background: #fff7f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.45rem;
    font-weight: 800;
    color: #915c3f;
    overflow: hidden;
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
    margin: 7px 0 0;
    color: #786052;
    font-size: 0.82rem;
}

.upload-note.schema-note {
    margin-top: 6px;
}

.checkbox-label {
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #4f382c;
    font-size: 0.86rem;
    font-weight: 600;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}

.orders-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.order-item {
    background-color: #fffaf3;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #eadbcf;
    border-left: 4px solid #d55f29;
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.order-number {
    font-weight: 700;
    color: #3b2a22;
}

.order-date {
    color: #6b7280;
    font-size: 0.88rem;
}

.order-details {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.order-status {
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
}

.status-pending { background-color: #fff3cd; color: #856404; }
.status-confirmed { background-color: #d1ecf1; color: #0c5460; }
.status-processing { background-color: #cce5ff; color: #004085; }
.status-shipped { background-color: #d4edda; color: #155724; }
.status-delivered { background-color: #28a745; color: #fff; }
.status-cancelled { background-color: #f8d7da; color: #721c24; }

.order-total {
    font-weight: 700;
    color: var(--acc-red);
    font-size: 1.1rem;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.empty-state i {
    font-size: 3rem;
    color: #ddd;
    margin-bottom: 15px;
}

.empty-state p {
    margin-bottom: 20px;
}

.application-status {
    background-color: #fff8ef;
    padding: 30px;
    border-radius: 12px;
    border: 1px solid #efdcc9;
    margin-bottom: 20px;
}

.status-card {
    background-color: white;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e6d8cb;
    margin-bottom: 20px;
}

.status-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.status-badge {
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
}

.status-approved { background-color: #d4edda; color: #155724; }
.status-rejected { background-color: #f8d7da; color: #721c24; }
.status-pending { background-color: #fff3cd; color: #856404; }

.status-message {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    border-radius: 6px;
    margin-top: 15px;
}

.status-message.success {
    background-color: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.status-message.error {
    background-color: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.status-message.info {
    background-color: #d1ecf1;
    color: #0c5460;
    border-left: 4px solid #17a2b8;
}

.status-actions {
    margin-top: 14px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.requirements-list {
    background-color: #fff9f3;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #eadcca;
    margin: 30px 0;
}

.requirements-list h4 {
    color: #333;
    margin-bottom: 15px;
}

.requirements-list ol {
    padding-left: 20px;
    color: #666;
    line-height: 1.6;
}

.requirements-list li {
    margin-bottom: 8px;
}

.password-strength {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background-color: #fff9f3;
    border-radius: 10px;
    border: 1px solid #eadcca;
}

.strength-bars {
    display: flex;
    gap: 4px;
    flex: 1;
}

.strength-bar {
    flex: 1;
    height: 6px;
    background-color: #e0e0e0;
    border-radius: 3px;
}

.btn-large {
    padding: 15px 30px;
    font-size: 1.05rem;
}

.btn-primary,
.btn-outline {
    border-radius: 10px;
    font-weight: 700;
}

.btn-outline {
    border: 1px solid #d9c7b5;
    color: #5c463a;
    background: #fff8f2;
}

.btn-outline:hover {
    background: #fff0e3;
}

.inline-action {
    margin-top: 10px;
}

.btn-inline {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
}

.mobile-tab-bar {
    display: none;
}

.mobile-tab-btn {
    border: none;
    background: transparent;
    color: #735b4c;
    text-decoration: none;
}

.account-page.theme-dark {
    background:
        radial-gradient(circle at 95% -5%, rgba(239, 107, 46, 0.2), transparent 38%),
        radial-gradient(circle at 0% 20%, rgba(179, 38, 30, 0.18), transparent 35%),
        linear-gradient(180deg, #1a1512 0%, #1f1915 44%, #15110f 100%);
}

.account-page.theme-dark .metric-card,
.account-page.theme-dark .profile-card,
.account-page.theme-dark .orders-card,
.account-page.theme-dark .address-book-pane,
.account-page.theme-dark .franchise-card,
.account-page.theme-dark .password-card,
.account-page.theme-dark .status-card,
.account-page.theme-dark .requirements-list,
.account-page.theme-dark .password-strength,
.account-page.theme-dark .order-item,
.account-page.theme-dark .application-status {
    background: #201a16;
    border-color: #3a2f27;
    color: #f3ece6;
}

.account-page.theme-dark .account-tabs {
    background: #2b231e;
    border-color: #3d3129;
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.35);
}

.account-page.theme-dark .tab-btn {
    color: #d2b7a6;
}

.account-page.theme-dark .tab-btn.active {
    background: #1b1512;
    color: #ffb083;
}

.account-page.theme-dark .form-group label,
.account-page.theme-dark .card-head p,
.account-page.theme-dark .order-date,
.account-page.theme-dark .empty-state,
.account-page.theme-dark .requirements-list ol {
    color: #ccb7aa;
}

.account-page.theme-dark .profile-card h2,
.account-page.theme-dark .orders-card h2,
.account-page.theme-dark .franchise-card h2,
.account-page.theme-dark .password-card h2,
.account-page.theme-dark .order-number,
.account-page.theme-dark .metric-card strong {
    color: #fff6ef;
}

.account-page.theme-dark .form-group input,
.account-page.theme-dark .form-group textarea,
.account-page.theme-dark .form-group select {
    background: #2b241f;
    border-color: #46372c;
    color: #fff6ef;
}

.account-page.theme-dark .form-group input:disabled {
    background: #2c241e;
    color: #ccb7aa;
}

.account-page.theme-dark .profile-upload-preview {
    background: #2b241f;
    border-color: #4a3a2f;
    color: #f5d3bc;
}

.account-page.theme-dark .upload-note,
.account-page.theme-dark .checkbox-label {
    color: #ccb7aa;
}

.account-page.theme-dark .btn-outline {
    background: #2e251f;
    border-color: #4b3b2f;
    color: #ecd7c9;
}

.account-page.theme-dark .btn-outline:hover {
    background: #3a2e26;
}

.account-page.theme-dark .status-message.success {
    background: rgba(45, 125, 78, 0.2);
    color: #9ad7b0;
}

.account-page.theme-dark .status-message.error {
    background: rgba(154, 55, 63, 0.24);
    color: #f1b9bf;
}

.account-page.theme-dark .status-message.info {
    background: rgba(42, 99, 128, 0.22);
    color: #a8d6eb;
}

@media (max-width: 900px) {
    .metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .account-page {
        padding-bottom: 110px;
    }

    .account-hero {
        padding: 20px;
    }

    .hero-main {
        align-items: flex-start;
    }

    .account-tabs {
        flex-direction: column;
    }
    
    .tab-btn {
        min-width: 100%;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }

    .profile-upload-row {
        align-items: flex-start;
    }
    
    .order-header,
    .order-details {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .address-book-frame {
        min-height: 1180px;
    }

    .mobile-tab-bar {
        position: fixed;
        left: 12px;
        right: 12px;
        bottom: 10px;
        z-index: 999;
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 4px;
        border-radius: 16px;
        padding: 8px 6px calc(8px + env(safe-area-inset-bottom));
        background: rgba(255, 250, 245, 0.95);
        border: 1px solid #ead5c3;
        box-shadow: 0 12px 26px rgba(28, 18, 11, 0.24);
        backdrop-filter: blur(8px);
    }

    .mobile-tab-btn {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        min-height: 52px;
        border-radius: 10px;
        font-size: 0.69rem;
        font-weight: 700;
        color: #735b4c;
        text-decoration: none;
    }

    .mobile-tab-btn i {
        font-size: 0.95rem;
    }

    .mobile-tab-btn.active {
        background: #fff;
        color: var(--acc-red);
        box-shadow: 0 8px 16px rgba(19, 14, 9, 0.12);
    }

    .account-page.theme-dark .mobile-tab-bar {
        background: rgba(36, 28, 23, 0.96);
        border-color: #4c3d32;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.45);
    }

    .account-page.theme-dark .mobile-tab-btn {
        color: #c6ab9c;
    }

    .account-page.theme-dark .mobile-tab-btn.active {
        background: #1b1512;
        color: #ffb083;
    }
}

@media (max-width: 480px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
}
</style>

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

    setTheme(getInitialTheme());
    showToast('success', serverSuccessMsg);
    showToast('error', serverErrorMsg);
    if (typeof Swal !== 'undefined' && (serverSuccessMsg || serverErrorMsg)) {
        document.querySelectorAll('.alert').forEach((alertEl) => {
            alertEl.style.display = 'none';
        });
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            const isDark = accountPage && accountPage.classList.contains('theme-dark');
            const nextTheme = isDark ? 'light' : 'dark';
            setTheme(nextTheme);
            localStorage.setItem(THEME_KEY, nextTheme);
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
});
</script>

<?php
mysqli_close($conn);
include 'includes/footer.php';
?>
