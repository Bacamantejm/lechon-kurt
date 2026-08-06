<?php
// Optional local credentials file for XAMPP/local development.
// These should take precedence when running on localhost.
$local_credentials_path = __DIR__ . '/local_credentials.php';
if (file_exists($local_credentials_path)) {
    require_once $local_credentials_path;
}

// Optional deployment credentials file for production hosting.
// Only use it as a fallback when local values are not defined.
$deployment_credentials_path = __DIR__ . '/deployment_credentials.php';
if (file_exists($deployment_credentials_path)) {
    require_once $deployment_credentials_path;
}

if (!function_exists('appConfigValue')) {
    function appConfigValue($key, $default = '') {
        if (defined($key)) {
            $value = constant($key);
            if ($value !== null && trim((string)$value) !== '') {
                return (string)$value;
            }
        }

        $env_value = getenv($key);
        if ($env_value !== false && trim((string)$env_value) !== '') {
            return (string)$env_value;
        }

        return (string)$default;
    }
}

if (!function_exists('appConfigBool')) {
    function appConfigBool($key, $default = true) {
        $has_value = false;
        $raw = null;

        if (defined($key)) {
            $raw = constant($key);
            $has_value = true;
        } else {
            $env_value = getenv($key);
            if ($env_value !== false) {
                $raw = $env_value;
                $has_value = true;
            }
        }

        if (!$has_value) {
            return (bool)$default;
        }
        if ($raw === null) {
            return (bool)$default;
        }
        if (is_string($raw) && trim($raw) === '') {
            return (bool)$default;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $normalized = strtolower(trim((string)$raw));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return (bool)$default;
    }
}

// Database connection
$host = appConfigValue('APP_DB_HOST', 'localhost');
$user = appConfigValue('APP_DB_USER', 'root');
$password = appConfigValue('APP_DB_PASSWORD', '');
$database = appConfigValue('APP_DB_NAME', 'lechon_db');
$db_port = (int)appConfigValue('APP_DB_PORT', '3306');
if ($db_port <= 0) {
    $db_port = 3306;
}

if (!function_exists('appRequestWantsJson')) {
    function appRequestWantsJson() {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/api/') !== false) {
            return true;
        }

        $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')));
        if ($accept !== '' && strpos($accept, 'application/json') !== false) {
            return true;
        }

        $requested_with = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        return $requested_with === 'xmlhttprequest';
    }
}

if (!function_exists('appAbortDatabaseConnection')) {
    function appAbortDatabaseConnection($message) {
        $safe_message = trim((string)$message);
        if ($safe_message === '') {
            $safe_message = 'Database connection failed.';
        }

        http_response_code(500);
        if (appRequestWantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error' => $safe_message
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        exit($safe_message);
    }
}

if (!function_exists('appConnectDatabaseWithRetry')) {
    function appConnectDatabaseWithRetry($host, $user, $password, $database, $db_port, $max_attempts = 3) {
        mysqli_report(MYSQLI_REPORT_OFF);

        $max_attempts = max(1, (int)$max_attempts);
        $last_error = '';
        $retryable_substrings = [
            'Only one usage of each socket address',
            'No connection could be made',
            'Connection refused',
            'server has gone away',
            'Lost connection',
            'Resource temporarily unavailable'
        ];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $conn = @mysqli_connect($host, $user, $password, $database, (int)$db_port);
            if ($conn) {
                return [$conn, ''];
            }

            $last_error = trim((string)mysqli_connect_error());
            $error_no = (int)mysqli_connect_errno();
            $lower_error = strtolower($last_error);
            $is_retryable_error = in_array($error_no, [2002, 2003, 2006, 2013], true);

            if (!$is_retryable_error) {
                foreach ($retryable_substrings as $pattern) {
                    if (strpos($lower_error, strtolower($pattern)) !== false) {
                        $is_retryable_error = true;
                        break;
                    }
                }
            }

            if (!$is_retryable_error || $attempt === $max_attempts) {
                break;
            }

            usleep(150000 * $attempt);
        }

        return [false, $last_error];
    }
}

list($conn, $db_connect_error) = appConnectDatabaseWithRetry($host, $user, $password, $database, $db_port, 3);

if (!$conn) {
    appAbortDatabaseConnection("Database connection failed: " . $db_connect_error);
}
if (!@mysqli_set_charset($conn, 'utf8mb4')) {
    appAbortDatabaseConnection("Database charset setup failed: " . mysqli_error($conn));
}

// Set timezone
date_default_timezone_set(appConfigValue('APP_TIMEZONE', 'Asia/Manila'));

// --- SMTP Configuration ---
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', appConfigValue('SMTP_HOST', 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', appConfigValue('SMTP_PORT', '587'));
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', appConfigValue('SMTP_USERNAME', ''));
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', appConfigValue('SMTP_PASSWORD', ''));
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', appConfigValue('SMTP_SECURE', 'tls'));
}
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', appConfigValue('MAIL_FROM_ADDRESS', 'orders@lechondelights.com'));
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', appConfigValue('MAIL_FROM_NAME', 'Lechon Delights'));
}

// --- Twilio SMS Configuration ---
if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', appConfigValue('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'));
}
if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', appConfigValue('TWILIO_AUTH_TOKEN', 'your_auth_token'));
}
if (!defined('TWILIO_PHONE_NUMBER')) {
    define('TWILIO_PHONE_NUMBER', appConfigValue('TWILIO_PHONE_NUMBER', '+15017122661'));
}


// Include RBAC functions
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/email_verification_helper.php';

if (!function_exists('getGoogleMapsApiKey')) {
    function getGoogleMapsApiKey() {
        return appConfigValue('GOOGLE_MAPS_API_KEY', '');
    }
}

if (!function_exists('shouldUseGoogleGeocoding')) {
    function shouldUseGoogleGeocoding() {
        return appConfigBool('GOOGLE_GEOCODING_ENABLED', true);
    }
}

function userAccountControlColumnExists($conn, $column_name) {
    static $column_cache = [];
    $column_name = trim((string)$column_name);
    if ($column_name === '') {
        return false;
    }
    if (array_key_exists($column_name, $column_cache)) {
        return $column_cache[$column_name];
    }
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE '{$safe_column}'");
    return $column_cache[$column_name] = ($result && mysqli_num_rows($result) > 0);
}

function normalizeUserAvatarPath($value) {
    $path = str_replace('\\', '/', trim((string)$value));
    if ($path === '') {
        return '';
    }
    if (!preg_match('#^uploads/(profile_pictures|business_logos)/[A-Za-z0-9._-]+$#', $path)) {
        return '';
    }
    return $path;
}

function ensureUserPersonalInfoSchema($conn) {
    $columns = [
        'middle_name' => "ALTER TABLE users ADD COLUMN middle_name VARCHAR(80) NULL DEFAULT NULL",
        'birth_date' => "ALTER TABLE users ADD COLUMN birth_date DATE NULL DEFAULT NULL",
        'gender' => "ALTER TABLE users ADD COLUMN gender VARCHAR(30) NULL DEFAULT NULL",
        'nickname' => "ALTER TABLE users ADD COLUMN nickname VARCHAR(80) NULL DEFAULT NULL",
    ];

    foreach ($columns as $column_name => $sql) {
        if (!userAccountControlColumnExists($conn, $column_name)) {
            @mysqli_query($conn, $sql);
        }
    }
}

if (!function_exists('ensureOrdersTableSchema')) {
    function ensureOrdersTableSchema($conn) {
        static $checked = false;
        if ($checked || !$conn) return;
        $checked = true;

        $res = @mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'order_number'");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            if (strpos(strtolower($row['Type'] ?? ''), 'varchar(64)') === false) {
                @mysqli_query($conn, "SET SESSION sql_mode = ''");
                @mysqli_query($conn, "UPDATE `orders` SET `delivery_date` = CURDATE() WHERE CAST(`delivery_date` AS CHAR) LIKE '0000%' OR `delivery_date` IS NULL");
                @mysqli_query($conn, "UPDATE `orders` SET `created_at` = NOW() WHERE CAST(`created_at` AS CHAR) LIKE '0000%'");
                @mysqli_query($conn, "ALTER TABLE `orders` MODIFY COLUMN `order_number` VARCHAR(64) NOT NULL");
            }
        }
    }
}

if (!function_exists('getPayMongoSecretKey')) {
    function getPayMongoSecretKey() {
        return trim((string)appConfigValue('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: ''));
    }
}

if (!function_exists('getPayMongoPublicKey')) {
    function getPayMongoPublicKey() {
        return trim((string)appConfigValue('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: ''));
    }
}

function resolveUserAvatarPathFromRow($row) {
    $account_type = strtolower(trim((string)($row['account_type'] ?? 'individual')));
    $profile_image = normalizeUserAvatarPath($row['profile_image'] ?? '');
    $business_logo = normalizeUserAvatarPath($row['business_logo'] ?? '');

    if ($account_type === 'organization' && $business_logo !== '') {
        return $business_logo;
    }
    if ($profile_image !== '') {
        return $profile_image;
    }
    return '';
}

function getUserAccountControlState($conn, $user_id) {
    $default_state = [
        'status' => 'active',
        'notes' => '',
        'restricted_at' => null,
        'restricted_by' => null
    ];

    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$conn || !userAccountControlColumnExists($conn, 'account_control_status')) {
        return $default_state;
    }

    $select_sql = "SELECT account_control_status";
    if (userAccountControlColumnExists($conn, 'access_restriction_notes')) {
        $select_sql .= ", access_restriction_notes";
    }
    if (userAccountControlColumnExists($conn, 'access_restricted_at')) {
        $select_sql .= ", access_restricted_at";
    }
    if (userAccountControlColumnExists($conn, 'access_restricted_by')) {
        $select_sql .= ", access_restricted_by";
    }
    $select_sql .= " FROM users WHERE id = ? LIMIT 1";

    $stmt = mysqli_prepare($conn, $select_sql);
    if (!$stmt) {
        return $default_state;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return $default_state;
    }

    $status = strtolower(trim((string)($row['account_control_status'] ?? 'active')));
    if (!in_array($status, ['active', 'restricted', 'suspended', 'banned'], true)) {
        $status = 'active';
    }

    return [
        'status' => $status,
        'notes' => (string)($row['access_restriction_notes'] ?? ''),
        'restricted_at' => $row['access_restricted_at'] ?? null,
        'restricted_by' => isset($row['access_restricted_by']) ? (int)$row['access_restricted_by'] : null
    ];
}

function isUserAccessBlockedByControlState($state) {
    $status = strtolower(trim((string)($state['status'] ?? 'active')));
    return in_array($status, ['suspended', 'banned'], true);
}

function getUserAccountControlMessage($state) {
    $status = strtolower(trim((string)($state['status'] ?? 'active')));
    if ($status === 'banned') {
        return 'Your partner account has been banned by the system owner. Please contact support.';
    }
    if ($status === 'suspended') {
        return 'Your partner account has been suspended by the system owner. Please contact support.';
    }
    if ($status === 'restricted') {
        return 'Your partner account is currently under restricted access by the system owner.';
    }
    return 'Your account is active.';
}

// Enhanced user authentication functions
function registerUser($conn, $email, $password, $full_name, $phone = '', $address = '', $account_type = 'individual', $business_name = null, $business_type = null, $business_registration = null, $website = null, $tax_id = null, $middle_name = null, $birth_date = null, $gender = null, $nickname = null) {
    ensureUserEmailVerificationSchema($conn);
    ensureUserPersonalInfoSchema($conn);

    $email = strtolower(trim((string)$email));
    $full_name = preg_replace('/\s+/', ' ', trim((string)$full_name));
    $phone = trim((string)$phone);
    $address = preg_replace('/\s+/', ' ', trim((string)$address));
    $account_type = strtolower(trim((string)$account_type));
    $business_name = $business_name !== null ? preg_replace('/\s+/', ' ', trim((string)$business_name)) : null;
    $business_type = $business_type !== null ? trim((string)$business_type) : null;
    $business_registration = $business_registration !== null ? trim((string)$business_registration) : null;
    $website = $website !== null ? trim((string)$website) : null;
    $tax_id = $tax_id !== null ? trim((string)$tax_id) : null;
    $middle_name = $middle_name !== null ? preg_replace('/\s+/', ' ', trim((string)$middle_name)) : '';
    $nickname = $nickname !== null ? preg_replace('/\s+/', ' ', trim((string)$nickname)) : '';
    $birth_date = $birth_date !== null ? trim((string)$birth_date) : '';
    $gender = $gender !== null ? strtolower(trim((string)$gender)) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please provide a valid email address.'];
    }

    if (!preg_match('/^[\p{L}\p{M}\'\-\s]{2,120}$/u', $full_name)) {
        return ['success' => false, 'message' => 'Please provide a valid full name.'];
    }

    if (!preg_match('/^[0-9]{11}$/', $phone)) {
        return ['success' => false, 'message' => 'Phone number must be exactly 11 digits.'];
    }

    if (!in_array($account_type, ['individual', 'organization'], true)) {
        return ['success' => false, 'message' => 'Invalid account type.'];
    }

    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,72}$/', (string)$password)) {
        return ['success' => false, 'message' => 'Password must be 8-72 chars with uppercase, lowercase, number, and symbol.'];
    }

    if (strlen($address) < 10) {
        return ['success' => false, 'message' => 'Please provide a complete delivery address.'];
    }

    if ($account_type === 'organization' && empty($business_name)) {
        return ['success' => false, 'message' => 'Business name is required for organization accounts.'];
    }

    $allowed_business_types = ['restaurant', 'catering', 'hotel', 'corporate', 'school', 'other'];
    if ($business_type !== null && $business_type !== '' && !in_array($business_type, $allowed_business_types, true)) {
        $business_type = 'other';
    }

    if ($website !== null && $website !== '') {
        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'Please provide a valid website URL.'];
        }
    } else {
        $website = null;
    }

    if ($business_registration !== null && $business_registration !== '') {
        $business_registration = preg_replace('/[^A-Za-z0-9\- ]/', '', $business_registration);
    } else {
        $business_registration = null;
    }

    if ($tax_id !== null && $tax_id !== '') {
        $tax_id = preg_replace('/[^A-Za-z0-9\- ]/', '', $tax_id);
    } else {
        $tax_id = null;
    }

    $check_query = "SELECT id FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    if (!$stmt) {
        error_log('Register email check prepare failed: ' . mysqli_error($conn));
        return ['success' => false, 'message' => 'Unable to process registration right now.'];
    }

    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Email already registered.'];
    }
    mysqli_stmt_close($stmt);

    $phone_check_query = "SELECT id FROM users WHERE phone = ?";
    $phone_stmt = mysqli_prepare($conn, $phone_check_query);
    if ($phone_stmt) {
        mysqli_stmt_bind_param($phone_stmt, "s", $phone);
        mysqli_stmt_execute($phone_stmt);
        mysqli_stmt_store_result($phone_stmt);
        if (mysqli_stmt_num_rows($phone_stmt) > 0) {
            mysqli_stmt_close($phone_stmt);
            return ['success' => false, 'message' => 'Mobile number already registered.'];
        }
        mysqli_stmt_close($phone_stmt);
    } else {
        error_log('Register phone check prepare failed: ' . mysqli_error($conn));
    }

    if ($account_type === 'organization') {
        if ($business_registration !== null && $business_registration !== '') {
            $registration_check = mysqli_prepare($conn, "SELECT id FROM users WHERE account_type = 'organization' AND business_registration = ? LIMIT 1");
            if ($registration_check) {
                mysqli_stmt_bind_param($registration_check, "s", $business_registration);
                mysqli_stmt_execute($registration_check);
                mysqli_stmt_store_result($registration_check);
                if (mysqli_stmt_num_rows($registration_check) > 0) {
                    mysqli_stmt_close($registration_check);
                    return ['success' => false, 'message' => 'This business registration number is already registered in the platform. Please use the existing partner account.'];
                }
                mysqli_stmt_close($registration_check);
            }
        }

        if ($tax_id !== null && $tax_id !== '') {
            $tax_check = mysqli_prepare($conn, "SELECT id FROM users WHERE account_type = 'organization' AND tax_id = ? LIMIT 1");
            if ($tax_check) {
                mysqli_stmt_bind_param($tax_check, "s", $tax_id);
                mysqli_stmt_execute($tax_check);
                mysqli_stmt_store_result($tax_check);
                if (mysqli_stmt_num_rows($tax_check) > 0) {
                    mysqli_stmt_close($tax_check);
                    return ['success' => false, 'message' => 'This business TIN is already registered in the platform. Please use the existing partner account.'];
                }
                mysqli_stmt_close($tax_check);
            }
        }
    }

    $hashed_password = password_hash((string)$password, PASSWORD_DEFAULT);
    if ($hashed_password === false) {
        error_log('Password hashing failed during registration for email: ' . $email);
        return ['success' => false, 'message' => 'Unable to process registration right now.'];
    }

    $query = "INSERT INTO users (email, password, full_name, phone, address, account_type, business_name, business_type, business_registration, website, tax_id, middle_name, birth_date, gender, nickname) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log('Register insert prepare failed: ' . mysqli_error($conn));
        return ['success' => false, 'message' => 'Unable to create your account right now.'];
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssssssssssssss",
        $email,
        $hashed_password,
        $full_name,
        $phone,
        $address,
        $account_type,
        $business_name,
        $business_type,
        $business_registration,
        $website,
        $tax_id,
        $middle_name,
        $birth_date,
        $gender,
        $nickname
    );

    if (mysqli_stmt_execute($stmt)) {
        $user_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return ['success' => true, 'user_id' => $user_id];
    }

    $sql_state = mysqli_stmt_sqlstate($stmt);
    $db_error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    error_log('Registration insert failed [' . $sql_state . ']: ' . $db_error);

    return ['success' => false, 'message' => 'Unable to create your account right now. Please try again later.'];
}

/**
 * Sync approved franchise partners into admin portal access while keeping them
 * as organization sellers (data access should be store-scoped in modules).
 */
function syncApprovedFranchisePartnerAccess($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return false;
    }

    $app_query = "SELECT business_name, business_type
                  FROM franchise_applications
                  WHERE user_id = ? AND status = 'approved'
                  ORDER BY reviewed_at DESC, id DESC
                  LIMIT 1";
    $app_stmt = mysqli_prepare($conn, $app_query);
    if (!$app_stmt) {
        error_log("Franchise sync query prepare failed: " . mysqli_error($conn));
        return false;
    }
    mysqli_stmt_bind_param($app_stmt, "i", $user_id);
    mysqli_stmt_execute($app_stmt);
    $app_result = mysqli_stmt_get_result($app_stmt);
    $approved_app = $app_result ? mysqli_fetch_assoc($app_result) : null;
    mysqli_stmt_close($app_stmt);

    if (!$approved_app) {
        return false;
    }

    $business_name = trim((string)($approved_app['business_name'] ?? ''));
    $business_type = trim((string)($approved_app['business_type'] ?? ''));
    if ($business_type === '') {
        $business_type = 'restaurant';
    }

    $role_id = 0;
    $role_query = "SELECT id FROM roles WHERE name = 'business_owner' AND is_active = 1 LIMIT 1";
    $role_result = mysqli_query($conn, $role_query);
    if ($role_result) {
        $role_row = mysqli_fetch_assoc($role_result);
        $role_id = (int)($role_row['id'] ?? 0);
        mysqli_free_result($role_result);
    }

    if ($role_id > 0) {
        $update_query = "UPDATE users
                         SET user_type = 'admin',
                             role_id = ?,
                             account_type = 'organization',
                             business_name = CASE WHEN ? <> '' THEN ? ELSE business_name END,
                             business_type = ?
                         WHERE id = ?";
    } else {
        $update_query = "UPDATE users
                         SET user_type = 'admin',
                             account_type = 'organization',
                             business_name = CASE WHEN ? <> '' THEN ? ELSE business_name END,
                             business_type = ?
                         WHERE id = ?";
    }

    $update_stmt = mysqli_prepare($conn, $update_query);
    if (!$update_stmt) {
        error_log("Franchise sync update prepare failed: " . mysqli_error($conn));
        return false;
    }
    if ($role_id > 0) {
        mysqli_stmt_bind_param($update_stmt, "isssi", $role_id, $business_name, $business_name, $business_type, $user_id);
    } else {
        mysqli_stmt_bind_param($update_stmt, "sssi", $business_name, $business_name, $business_type, $user_id);
    }
    $ok = mysqli_stmt_execute($update_stmt);
    if (!$ok) {
        error_log("Franchise sync update failed: " . mysqli_stmt_error($update_stmt));
    }
    mysqli_stmt_close($update_stmt);

    return $ok;
}

function loginUser($conn, $email, $password) {
    ensureUserEmailVerificationSchema($conn);

    // Updated to support RBAC - includes role_id
    $select_fields = "id, email, password, full_name, user_type, is_active, account_type, role_id";
    $has_profile_image_column = userAccountControlColumnExists($conn, 'profile_image');
    $has_business_logo_column = userAccountControlColumnExists($conn, 'business_logo');

    if ($has_profile_image_column) {
        $select_fields .= ", profile_image";
    }
    if ($has_business_logo_column) {
        $select_fields .= ", business_logo";
    }
    $query = "SELECT {$select_fields} FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if (!$stmt) {
        error_log("Login Query Error: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'Database error. Please try again.'];
    }
    
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $account_control_state = getUserAccountControlState($conn, (int)$row['id']);
        if (isUserAccessBlockedByControlState($account_control_state)) {
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => getUserAccountControlMessage($account_control_state)];
        }
        if (!$row['is_active']) {
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => 'Account is deactivated. Please contact support.'];
        }
        
        // Verify password - handle both plaintext (for migration) and hashed
        $password_valid = false;
        if (password_verify($password, $row['password'])) {
            $password_valid = true;
        } elseif ($row['password'] === $password) {
            // Temporary: allow plaintext for migration purposes
            // This will be removed after data migration
            $password_valid = true;
            // Hash the password for next login
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $hash_query = "UPDATE users SET password = ? WHERE id = ?";
            $hash_stmt = mysqli_prepare($conn, $hash_query);
            if ($hash_stmt) {
                mysqli_stmt_bind_param($hash_stmt, "si", $hashed, $row['id']);
                mysqli_stmt_execute($hash_stmt);
                mysqli_stmt_close($hash_stmt);
            }
        }
        
        if ($password_valid) {
            // Ensure approved franchise partners are synced as organization sellers.
            syncApprovedFranchisePartnerAccess($conn, (int)$row['id']);

            // Re-fetch current auth columns after potential sync.
            $refresh_fields = "user_type, account_type, role_id";
            if ($has_profile_image_column) {
                $refresh_fields .= ", profile_image";
            }
            if ($has_business_logo_column) {
                $refresh_fields .= ", business_logo";
            }
            $refresh_query = "SELECT {$refresh_fields} FROM users WHERE id = ? LIMIT 1";
            $refresh_stmt = mysqli_prepare($conn, $refresh_query);
            if ($refresh_stmt) {
                mysqli_stmt_bind_param($refresh_stmt, "i", $row['id']);
                mysqli_stmt_execute($refresh_stmt);
                $refresh_result = mysqli_stmt_get_result($refresh_stmt);
                if ($refresh_result && ($fresh = mysqli_fetch_assoc($refresh_result))) {
                    $row['user_type'] = $fresh['user_type'] ?? $row['user_type'];
                    $row['account_type'] = $fresh['account_type'] ?? $row['account_type'];
                    $row['role_id'] = $fresh['role_id'] ?? $row['role_id'];
                    if ($has_profile_image_column) {
                        $row['profile_image'] = $fresh['profile_image'] ?? ($row['profile_image'] ?? null);
                    }
                    if ($has_business_logo_column) {
                        $row['business_logo'] = $fresh['business_logo'] ?? ($row['business_logo'] ?? null);
                    }
                }
                mysqli_stmt_close($refresh_stmt);
            }

            $normalized_user_type = strtolower(trim((string)($row['user_type'] ?? '')));
            if (!in_array($normalized_user_type, ['admin', 'employee', 'customer'], true)) {
                $normalized_user_type = 'customer';
            }

            // Update last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, "i", $row['id']);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
            }

            if ($normalized_user_type !== strtolower(trim((string)($row['user_type'] ?? '')))) {
                $fix_type_query = "UPDATE users SET user_type = ? WHERE id = ?";
                $fix_type_stmt = mysqli_prepare($conn, $fix_type_query);
                if ($fix_type_stmt) {
                    mysqli_stmt_bind_param($fix_type_stmt, "si", $normalized_user_type, $row['id']);
                    mysqli_stmt_execute($fix_type_stmt);
                    mysqli_stmt_close($fix_type_stmt);
                }
            }
            
            mysqli_stmt_close($stmt);
            return [
                'success' => true,
                'user_id' => $row['id'],
                'email' => $row['email'],
                'full_name' => $row['full_name'],
                'user_type' => $normalized_user_type,
                'account_type' => $row['account_type'],
                'role_id' => $row['role_id'],
                'profile_image' => resolveUserAvatarPathFromRow($row),
                'business_logo' => $row['business_logo'] ?? null
            ];
        }
    }
    
    mysqli_stmt_close($stmt);
    return ['success' => false, 'message' => 'Invalid email or password'];
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header("Location: login.php?redirect=$redirect");
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_type'] !== 'admin') {
        header("Location: index.php");
        exit;
    }
}

function normalizeUserProfileName($value) {
    return preg_replace('/\s+/', ' ', trim((string)$value));
}

function userProfileNamesMatch($a, $b) {
    $left = normalizeUserProfileName($a);
    $right = normalizeUserProfileName($b);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
    }
    return strtolower($left) === strtolower($right);
}

function isValidUserProfileName($full_name) {
    $full_name = normalizeUserProfileName($full_name);
    if ($full_name === '') {
        return false;
    }
    return (bool)preg_match('/^[\p{L}\p{M}\'\-\.\s]{2,120}$/u', $full_name);
}

function ensureUserNameChangeLockTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!($conn instanceof mysqli)) {
        $ready = false;
        return false;
    }

    $create_sql = "CREATE TABLE IF NOT EXISTS user_name_change_locks (
        user_id INT(11) NOT NULL,
        last_changed_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!mysqli_query($conn, $create_sql)) {
        error_log('Unable to ensure name change lock table: ' . mysqli_error($conn));
        $ready = false;
        return false;
    }

    $ready = true;
    return true;
}

function getUserNameChangeWindow($conn, $user_id, $cooldown_days = 7) {
    $state = [
        'success' => false,
        'can_change' => false,
        'last_changed_at' => null,
        'next_allowed_at' => null,
        'remaining_seconds' => 0,
        'message' => 'Unable to validate name change policy right now.'
    ];

    $user_id = (int)$user_id;
    $cooldown_days = max(1, (int)$cooldown_days);
    if ($user_id <= 0) {
        $state['message'] = 'Invalid user account.';
        return $state;
    }

    if (!ensureUserNameChangeLockTable($conn)) {
        return $state;
    }

    $stmt = mysqli_prepare($conn, "SELECT last_changed_at FROM user_name_change_locks WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        $state['message'] = 'Unable to read name change policy.';
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
        $state['can_change'] = true;
        return $state;
    }

    $last_changed_at = (string)$row['last_changed_at'];
    $last_ts = strtotime($last_changed_at);
    if (!$last_ts) {
        $state['can_change'] = true;
        return $state;
    }

    $next_ts = strtotime('+' . $cooldown_days . ' days', $last_ts);
    $now_ts = time();
    $state['last_changed_at'] = date('Y-m-d H:i:s', $last_ts);
    $state['next_allowed_at'] = date('Y-m-d H:i:s', $next_ts);

    if ($next_ts <= $now_ts) {
        $state['can_change'] = true;
        return $state;
    }

    $state['can_change'] = false;
    $state['remaining_seconds'] = max(0, $next_ts - $now_ts);
    $state['message'] = 'You can update your full name again on ' . date('F j, Y g:i A', $next_ts) . '.';
    return $state;
}

function evaluateUserNameChangePolicy($conn, $user_id, $current_name, $requested_name, $cooldown_days = 7) {
    $current_name = normalizeUserProfileName($current_name);
    $requested_name = normalizeUserProfileName($requested_name);

    $result = [
        'success' => false,
        'name_changed' => false,
        'can_apply' => false,
        'message' => 'Unable to process name change right now.',
        'next_allowed_at' => null
    ];

    if (!isValidUserProfileName($requested_name)) {
        $result['message'] = 'Please enter a valid full name (2-120 letters).';
        return $result;
    }

    if (userProfileNamesMatch($current_name, $requested_name)) {
        $result['success'] = true;
        $result['can_apply'] = true;
        $result['name_changed'] = false;
        $result['message'] = '';
        return $result;
    }

    $window = getUserNameChangeWindow($conn, (int)$user_id, (int)$cooldown_days);
    if (!($window['success'] ?? false)) {
        $result['message'] = (string)($window['message'] ?? $result['message']);
        return $result;
    }

    if (!($window['can_change'] ?? false)) {
        $result['message'] = (string)($window['message'] ?? 'Name change cooldown is still active.');
        $result['next_allowed_at'] = $window['next_allowed_at'] ?? null;
        return $result;
    }

    $result['success'] = true;
    $result['can_apply'] = true;
    $result['name_changed'] = true;
    $result['message'] = '';
    $result['next_allowed_at'] = date('Y-m-d H:i:s', strtotime('+' . max(1, (int)$cooldown_days) . ' days'));
    return $result;
}

function recordUserNameChangeTimestamp($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !ensureUserNameChangeLockTable($conn)) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO user_name_change_locks (user_id, last_changed_at) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE last_changed_at = VALUES(last_changed_at), updated_at = CURRENT_TIMESTAMP"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool)$ok;
}

function syncUserProfileReferences($conn, $user_id, $full_name, $email, $phone) {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !($conn instanceof mysqli)) {
        return;
    }

    $full_name = normalizeUserProfileName($full_name);
    $email = strtolower(trim((string)$email));
    $phone = trim((string)$phone);

    $orders_stmt = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET customer_name = ?, customer_email = ?, customer_phone = ?, updated_at = NOW()
         WHERE user_id = ? AND status IN ('pending', 'confirmed', 'preparing', 'processing', 'shipped')"
    );
    if ($orders_stmt) {
        mysqli_stmt_bind_param($orders_stmt, "sssi", $full_name, $email, $phone, $user_id);
        mysqli_stmt_execute($orders_stmt);
        mysqli_stmt_close($orders_stmt);
    }

    $franchise_stmt = mysqli_prepare(
        $conn,
        "UPDATE franchise_applications
         SET contact_person = ?, contact_phone = ?, contact_email = ?, updated_at = NOW()
         WHERE user_id = ? AND status = 'pending'"
    );
    if ($franchise_stmt) {
        mysqli_stmt_bind_param($franchise_stmt, "sssi", $full_name, $phone, $email, $user_id);
        mysqli_stmt_execute($franchise_stmt);
        mysqli_stmt_close($franchise_stmt);
    }
}

function getUserInfo($conn, $user_id) {
    $select_fields = "id, email, full_name, phone, address, user_type, account_type, business_name, business_type, created_at, last_login";
    if (userAccountControlColumnExists($conn, 'profile_image')) {
        $select_fields .= ", profile_image";
    }
    if (userAccountControlColumnExists($conn, 'business_logo')) {
        $select_fields .= ", business_logo";
    }
    $query = "SELECT {$select_fields} FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!empty($data)) {
        $data['profile_image'] = resolveUserAvatarPathFromRow($data);
    }
    return $data;
}

function updateUserProfile($conn, $user_id, $full_name, $phone, $address, $business_name = null, $business_type = null) {
    $user_id = (int)$user_id;
    $full_name = normalizeUserProfileName($full_name);
    $phone = preg_replace('/\D+/', '', (string)$phone);
    $address = trim((string)$address);
    $business_name = $business_name !== null ? trim((string)$business_name) : null;
    $business_type = $business_type !== null ? trim((string)$business_type) : null;

    if ($user_id <= 0 || !isValidUserProfileName($full_name)) {
        return false;
    }

    if ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 15)) {
        return false;
    }

    $current_stmt = mysqli_prepare($conn, "SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
    if (!$current_stmt) {
        return false;
    }
    mysqli_stmt_bind_param($current_stmt, "i", $user_id);
    mysqli_stmt_execute($current_stmt);
    $current_result = mysqli_stmt_get_result($current_stmt);
    $current_row = $current_result ? mysqli_fetch_assoc($current_result) : null;
    mysqli_stmt_close($current_stmt);
    if (!$current_row) {
        return false;
    }

    $name_policy = evaluateUserNameChangePolicy($conn, $user_id, (string)$current_row['full_name'], $full_name, 7);
    if (!($name_policy['success'] ?? false) || !($name_policy['can_apply'] ?? false)) {
        return false;
    }
    $name_changed = (bool)($name_policy['name_changed'] ?? false);

    $query = "UPDATE users SET full_name = ?, phone = ?, address = ?, business_name = ?, business_type = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sssssi", $full_name, $phone, $address, $business_name, $business_type, $user_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($result && $name_changed) {
        recordUserNameChangeTimestamp($conn, $user_id);
    }
    if ($result) {
        syncUserProfileReferences($conn, $user_id, $full_name, (string)$current_row['email'], $phone);
    }

    return $result;
}

function changePassword($conn, $user_id, $current_password, $new_password) {
    // Verify current password
    $query = "SELECT password FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (password_verify($current_password, $row['password'])) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_query = "UPDATE users SET password = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user_id);
        $result = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        return $result;
    }
    
    return false;
}

// In includes/config.php, add these functions if they don't exist:

/**
 * Generate password reset token for a user
 */
function generateResetToken($conn, $email) {
    // Check if user exists and is active
    $query = "SELECT id FROM users WHERE email = ? AND is_active = 1";
    if ($stmt = mysqli_prepare($conn, $query)) {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        
        if (mysqli_stmt_num_rows($stmt) > 0) {
            mysqli_stmt_bind_result($stmt, $user_id);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            
            // Generate unique token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database
            $updateQuery = "UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?";
            if ($updateStmt = mysqli_prepare($conn, $updateQuery)) {
                mysqli_stmt_bind_param($updateStmt, "ssi", $token, $expires, $user_id);
                if (mysqli_stmt_execute($updateStmt)) {
                    mysqli_stmt_close($updateStmt);
                    return $token;
                }
                mysqli_stmt_close($updateStmt);
            }
        } else {
            mysqli_stmt_close($stmt);
        }
    }
    return false;
}

/**
 * Validate reset token
 */
function validateResetToken($conn, $token) {
    $query = "SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()";
    if ($stmt = mysqli_prepare($conn, $query)) {
        mysqli_stmt_bind_param($stmt, "s", $token);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        
        if (mysqli_stmt_num_rows($stmt) > 0) {
            mysqli_stmt_bind_result($stmt, $user_id);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            return $user_id;
        }
        mysqli_stmt_close($stmt);
    }
    return false;
}

/**
 * Reset password using token
 */
function resetPassword($conn, $token, $new_password) {
    $user_id = validateResetToken($conn, $token);
    
    if ($user_id) {
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and clear reset token
        $query = "UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?";
        if ($stmt = mysqli_prepare($conn, $query)) {
            mysqli_stmt_bind_param($stmt, "si", $hashed_password, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                return true;
            }
            mysqli_stmt_close($stmt);
        }
    }
    return false;
}

/**
 * Get all active admin user IDs
 */
function getAdminUserIds($conn) {
    $admin_ids = [];
    $query = "SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $admin_ids[] = $row['id'];
        }
        mysqli_free_result($result);
    }
    return $admin_ids;
}

/**
 * Create a notification for users
 * @param $conn - Database connection
 * @param $user_id - User ID
 * @param $type - Notification type (order_confirmed, order_preparing, order_delivered, preorder_confirmed, preorder_ready, etc.)
 * @param $title - Notification title
 * @param $message - Notification message
 * @param $related_id - Related order/preorder ID
 * @param $related_type - Type of related item (order, pre_order)
 */
function createNotification($conn, $user_id, $type, $title, $message, $related_id = null, $related_type = null) {
    $notif_query = "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, is_read, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    $stmt = mysqli_prepare($conn, $notif_query);
    
    if (!$stmt) {
        error_log("Notification Error: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "isssss", $user_id, $type, $title, $message, $related_id, $related_type);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return true;
    } else {
        error_log("Notification Insert Error: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }
}
?>
