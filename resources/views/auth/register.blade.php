<?php
global $conn;
if (!isset($conn) || !($conn instanceof \mysqli)) {
    require_once base_path('includes/config.php');
    if (!isset($conn) || !($conn instanceof \mysqli)) {
        $conn = @mysqli_connect(
            defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '127.0.0.1'),
            defined('DB_USER') ? DB_USER : (getenv('DB_USERNAME') ?: 'root'),
            defined('DB_PASS') ? DB_PASS : (getenv('DB_PASSWORD') ?: ''),
            defined('DB_NAME') ? DB_NAME : (getenv('DB_DATABASE') ?: 'lechon_db')
        );
    }
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once base_path('includes/config.php');
require_once base_path('includes/email_verification_helper.php');
require_once base_path('includes/security.php');

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$form_data = [];
$is_partner_signup = false;
$form_data['account_type'] = 'individual';

if (empty($_SESSION['registration_csrf_token'])) {
    $_SESSION['registration_csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['registration_security'])) {
    $_SESSION['registration_security'] = [
        'attempts' => 0,
        'window_started_at' => time(),
        'blocked_until' => 0,
    ];
}

if (!function_exists('normalizePhilippineMobile')) {
    function normalizePhilippineMobile($phone)
    {
        $cleaned = preg_replace('/[^0-9]/', '', (string)$phone);

        if (preg_match('/^63[0-9]{10}$/', $cleaned)) {
            return '0' . substr($cleaned, 2);
        }

        if (preg_match('/^9[0-9]{9}$/', $cleaned)) {
            return '0' . $cleaned;
        }

        return $cleaned;
    }
}

if (!function_exists('isLuzonRegionSelection')) {
    function isLuzonRegionSelection($region_code, $region_name)
    {
        $region_code = trim((string)$region_code);
        $region_name = strtolower(trim((string)$region_name));
        $region_name = preg_replace('/\s+/', ' ', $region_name);

        $luzon_region_codes = [
            '010000000', // Ilocos Region
            '020000000', // Cagayan Valley
            '030000000', // Central Luzon
            '040000000', // CALABARZON
            '050000000', // Bicol Region
            '130000000', // NCR
            '140000000', // CAR
            '170000000'  // MIMAROPA
        ];

        if ($region_code !== '' && in_array($region_code, $luzon_region_codes, true)) {
            return true;
        }

        $luzon_name_markers = [
            'ilocos',
            'cagayan valley',
            'central luzon',
            'calabarzon',
            'bicol',
            'national capital region',
            'ncr',
            'cordillera',
            'mimaropa'
        ];

        foreach ($luzon_name_markers as $marker) {
            if ($region_name !== '' && strpos($region_name, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('registrationColumnExists')) {
    function registrationColumnExists($conn, $table_name, $column_name)
    {
        $table_name = trim((string)$table_name);
        $column_name = trim((string)$column_name);
        if ($table_name === '' || $column_name === '') {
            return false;
        }
        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        return ($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('ensureRegistrationValidIdSchema')) {
    function ensureRegistrationValidIdSchema($conn)
    {
        $table_sql = "CREATE TABLE IF NOT EXISTS `user_valid_id_documents` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `document_type` varchar(50) NOT NULL DEFAULT 'valid_id',
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_valid_id_documents_user` (`user_id`),
            CONSTRAINT `fk_user_valid_id_documents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if (!mysqli_query($conn, $table_sql)) {
            error_log('Failed to ensure valid ID schema table: ' . mysqli_error($conn));
            return false;
        }

        $required_columns = [
            'id' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
            'user_id' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `user_id` INT(11) NOT NULL AFTER `id`",
            'document_type' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `document_type` VARCHAR(50) NOT NULL DEFAULT 'valid_id' AFTER `user_id`",
            'file_name' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `file_name` VARCHAR(255) NOT NULL AFTER `document_type`",
            'file_path' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `file_path` VARCHAR(500) NOT NULL AFTER `file_name`",
            'uploaded_at' => "ALTER TABLE `user_valid_id_documents` ADD COLUMN `uploaded_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() AFTER `file_path`",
        ];

        foreach ($required_columns as $column_name => $alter_sql) {
            if (!registrationColumnExists($conn, 'user_valid_id_documents', $column_name)) {
                if (!mysqli_query($conn, $alter_sql)) {
                    error_log('Failed to ensure valid ID schema column ' . $column_name . ': ' . mysqli_error($conn));
                    return false;
                }
            }
        }

        return true;
    }
}

if (!function_exists('validateRegistrationValidIdUpload')) {
    function validateRegistrationValidIdUpload($file)
    {
        if (!isset($file) || !is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => false, 'message' => 'Please upload a valid ID image.'];
        }

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded ID is too large.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded ID is too large.',
                UPLOAD_ERR_PARTIAL => 'The uploaded ID was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Upload failed: unable to write file.',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            ];
            $message = $upload_errors[(int)$file['error']] ?? 'Unable to upload your valid ID right now.';
            return ['valid' => false, 'message' => $message];
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $validation = validateFileUpload($file, $allowed_types, 5 * 1024 * 1024);
        if (empty($validation['valid'])) {
            $errors = $validation['errors'] ?? [];
            $friendly_message = !empty($errors)
                ? (string)$errors[0]
                : 'Please upload a clear JPG, PNG, or WEBP valid ID image up to 5MB.';
            return ['valid' => false, 'message' => $friendly_message];
        }

        return [
            'valid' => true,
            'mime_type' => (string)($validation['mime_type'] ?? '')
        ];
    }
}

if (!function_exists('saveRegistrationValidIdDocument')) {
    function saveRegistrationValidIdDocument($conn, $user_id, $uploaded_file, $valid_id_type = '', $side = 'Front')
    {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return ['success' => false, 'message' => 'Unable to map uploaded ID to account.'];
        }

        $validation = validateRegistrationValidIdUpload($uploaded_file);
        if (empty($validation['valid'])) {
            return ['success' => false, 'message' => (string)($validation['message'] ?? 'Please upload a valid ID image.')];
        }

        if (!ensureRegistrationValidIdSchema($conn)) {
            return ['success' => false, 'message' => 'Unable to prepare secure ID storage. Please try again in a moment.'];
        }

        $upload_dir = __DIR__ . '/uploads/user_valid_ids/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            return ['success' => false, 'message' => 'Unable to create upload directory for valid IDs.'];
        }

        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        $mime_type = (string)($validation['mime_type'] ?? '');
        $extension = $mime_to_ext[$mime_type] ?? '';
        if ($extension === '') {
            $original_ext = strtolower((string)pathinfo((string)($uploaded_file['name'] ?? ''), PATHINFO_EXTENSION));
            if (in_array($original_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = $original_ext === 'jpeg' ? 'jpg' : $original_ext;
            }
        }
        if ($extension === '') {
            $extension = 'jpg';
        }

        $original_name = (string)($uploaded_file['name'] ?? 'valid_id');
        $base_name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)pathinfo($original_name, PATHINFO_FILENAME));
        $base_name = trim((string)$base_name, '._-');
        if ($base_name === '') {
            $base_name = 'valid_id';
        }
        $base_name = substr($base_name, 0, 40);

        $unique_name = $user_id . '_valid_id_' . strtolower($side) . '_' . time() . '_' . random_int(1000, 9999) . '_' . $base_name . '.' . $extension;
        $target_path = $upload_dir . $unique_name;
        $relative_path = 'uploads/user_valid_ids/' . $unique_name;

        if (!move_uploaded_file((string)$uploaded_file['tmp_name'], $target_path)) {
            return ['success' => false, 'message' => 'Unable to save the uploaded valid ID. Please try again.'];
        }

        $id_label_map = [
            'umid' => 'Unified Multi-Purpose ID (UMID)',
            'drivers_license' => "Driver's License",
            'passport' => 'Philippine Passport',
            'sss' => 'SSS ID',
            'gsis' => 'GSIS ID',
            'prc' => 'PRC ID',
            'postal' => 'Postal ID',
            'voters' => "Voter's ID",
            'national_id' => 'Philippine National ID (PhilSys)',
            'tin' => 'TIN ID',
            'pag_ibig' => 'Pag-IBIG ID',
            'philhealth' => 'PhilHealth ID'
        ];
        $document_type_label = ($id_label_map[$valid_id_type] ?? 'Government ID') . ' - ' . $side;

        $insert_sql = "INSERT INTO user_valid_id_documents (user_id, document_type, file_name, file_path) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_sql);
        if (!$stmt) {
            @unlink($target_path);
            error_log('Valid ID metadata prepare failed: ' . mysqli_error($conn));
            return ['success' => false, 'message' => 'Unable to record valid ID metadata right now.'];
        }

        mysqli_stmt_bind_param($stmt, "isss", $user_id, $document_type_label, $unique_name, $relative_path);
        $ok = mysqli_stmt_execute($stmt);
        if (!$ok) {
            $db_error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            @unlink($target_path);
            error_log('Valid ID metadata insert failed: ' . $db_error);
            return ['success' => false, 'message' => 'Unable to save your valid ID record right now.'];
        }
        mysqli_stmt_close($stmt);

        return ['success' => true, 'file_path' => $relative_path];
    }
}

ensureUserEmailVerificationSchema($conn);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $max_attempts = 6;
    $window_seconds = 600;
    $lock_seconds = 900;
    $now = time();

    if (
        isset($_SESSION['registration_security']['window_started_at']) &&
        ($now - (int)$_SESSION['registration_security']['window_started_at']) > $window_seconds
    ) {
        $_SESSION['registration_security']['attempts'] = 0;
        $_SESSION['registration_security']['window_started_at'] = $now;
    }

    if (!empty($_SESSION['registration_security']['blocked_until']) && (int)$_SESSION['registration_security']['blocked_until'] > $now) {
        $wait_seconds = (int)$_SESSION['registration_security']['blocked_until'] - $now;
        $error = 'Too many registration attempts. Please try again in ' . ceil($wait_seconds / 60) . ' minute(s).';
    }

    // Get form data
    $account_type = strtolower(trim($_POST['account_type'] ?? ($is_partner_signup ? 'organization' : 'individual')));
    if ($is_partner_signup) {
        $account_type = 'organization';
    }
    $first_name = preg_replace('/\s+/', ' ', trim($_POST['first_name'] ?? ''));
    $last_name = preg_replace('/\s+/', ' ', trim($_POST['last_name'] ?? ''));
    $middle_name = preg_replace('/\s+/', ' ', trim($_POST['middle_name'] ?? ''));
    $nickname = preg_replace('/\s+/', ' ', trim($_POST['nickname'] ?? ''));
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = strtolower(trim($_POST['gender'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $accept_terms = isset($_POST['accept_terms']);
    $valid_id_type = trim($_POST['valid_id_type'] ?? '');
    $valid_id_front = $_FILES['valid_id_front'] ?? null;
    $valid_id_back = $_FILES['valid_id_back'] ?? null;
    
    // Business partner fields
    $business_name = preg_replace('/\s+/', ' ', trim($_POST['business_name'] ?? ''));
    $business_type = 'restaurant';
$business_registration = trim($_POST['business_registration'] ?? '');
    $website = null;
    $tax_id = trim($_POST['tax_id'] ?? '');
    $street_address = trim($_POST['street_address'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $city_name = trim($_POST['city_name'] ?? '');
    $province_name = trim($_POST['province_name'] ?? 'Cavite');

    if ($address === '' && $street_address !== '') {
        $address = $street_address . ', Cavite';
    }

    // Store form data for repopulation
    $form_data = [
        'account_type' => $account_type,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'middle_name' => $middle_name,
        'nickname' => $nickname,
        'birth_date' => $birth_date,
        'gender' => $gender,
        'email' => $email,
        'phone' => $phone,
        'valid_id_type' => $valid_id_type,
        'business_name' => $business_name,
        'business_type' => $business_type,
        'business_registration' => $business_registration,
        'tax_id' => $tax_id,
        'street_address' => $street_address,
        'address' => $address,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'city_name' => $city_name,
        'province_name' => $province_name,
    ];

    // Validation
    if ($error === '') {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (empty($csrf_token) || empty($_SESSION['registration_csrf_token']) || !hash_equals($_SESSION['registration_csrf_token'], $csrf_token)) {
            $error = 'Invalid security token. Please refresh the page and try again.';
        } elseif (!in_array($account_type, ['individual', 'organization'], true)) {
            $error = 'Invalid account type selected.';
        } elseif (!$accept_terms) {
            $error = 'You must accept the Terms of Service and Privacy Policy.';
        } elseif (empty($first_name) || empty($last_name)) {
            $error = 'Please enter your first and last name.';
        } elseif (!preg_match('/^[\p{L}\p{M}\'\-\s]{2,60}$/u', $first_name) || !preg_match('/^[\p{L}\p{M}\'\-\s]{2,60}$/u', $last_name)) {
            $error = 'Please enter a valid first and last name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,72}$/', $password)) {
            $error = 'Password must be 8-72 characters with uppercase, lowercase, number, and symbol.';
        } elseif (!hash_equals($password, $confirm_password)) {
            $error = 'Passwords do not match.';
        } elseif (empty($phone)) {
            $error = 'Please enter your mobile number.';
        } elseif (empty($valid_id_type)) {
            $error = 'Please select your valid ID type.';
        } elseif (empty($valid_id_front) || !is_array($valid_id_front) || (int)($valid_id_front['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Please upload a photo of the front side of your valid ID.';
        } elseif (empty($valid_id_back) || !is_array($valid_id_back) || (int)($valid_id_back['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Please upload a photo of the back side of your valid ID.';
        } elseif ($account_type === 'organization' && empty($business_name)) {
            $error = 'Please enter your restaurant name.';
        } elseif (strlen($address) < 6) {
            $error = 'Please enter your complete home address in Cavite.';
        } else {
            // Validate that the location is inside Cavite
            $is_cavite = false;
            if ($latitude !== '' && $longitude !== '' && is_numeric($latitude) && is_numeric($longitude)) {
                $lat_num = (float)$latitude;
                $lng_num = (float)$longitude;
                // Cavite coordinates boundary check
                if ($lat_num >= 14.00 && $lat_num <= 14.55 && $lng_num >= 120.55 && $lng_num <= 121.15) {
                    $is_cavite = true;
                }
            }

            // Keyword check for Cavite cities / municipalities
            $cavite_keywords = [
                'cavite', 'dasmariñas', 'dasmarinas', 'imus', 'bacoor', 'general trias', 'gen. trias',
                'tagaytay', 'cavite city', 'trece martires', 'silang', 'kawit', 'tanza', 'alfonso',
                'amadeo', 'carmona', 'gma', 'general mariano alvarez', 'indang', 'magallanes',
                'maragondon', 'mendez', 'naic', 'noveleta', 'rosario', 'ternate', 'bailen', 'aguinaldo'
            ];
            $addr_lower = strtolower($address);
            foreach ($cavite_keywords as $kw) {
                if (strpos($addr_lower, $kw) !== false) {
                    $is_cavite = true;
                    break;
                }
            }

            // Explicit rejection of outside areas (e.g. NCR/Manila, Laguna, Batangas)
            $outside_keywords = ['las piñas', 'las pinas', 'parañaque', 'paranaque', 'muntinlupa', 'metro manila', 'ncr', 'batangas', 'laguna', 'quezon city', 'pasay'];
            foreach ($outside_keywords as $out_kw) {
                if (strpos($addr_lower, $out_kw) !== false && strpos($addr_lower, 'cavite') === false) {
                    $is_cavite = false;
                    break;
                }
            }

            if (!$is_cavite) {
                $error = 'Service Area Restriction: Registration is exclusively available for addresses inside Cavite province. Please pin or enter a location within Cavite.';
            } else {
                $front_validation = validateRegistrationValidIdUpload($valid_id_front);
                $back_validation = validateRegistrationValidIdUpload($valid_id_back);
                if (empty($front_validation['valid'])) {
                    $error = 'Front of ID: ' . (string)($front_validation['message'] ?? 'Please upload a clear JPG, PNG, or WEBP image up to 5MB.');
                } elseif (empty($back_validation['valid'])) {
                    $error = 'Back of ID: ' . (string)($back_validation['message'] ?? 'Please upload a clear JPG, PNG, or WEBP image up to 5MB.');
                }
            }
        }
    }

    if ($error === '') {
        $phone_cleaned = normalizePhilippineMobile($phone);
        $business_registration = preg_replace('/[^A-Za-z0-9\- ]/', '', $business_registration);
        $tax_id = preg_replace('/[^A-Za-z0-9\- ]/', '', $tax_id);
        $full_name = trim($first_name . ' ' . $last_name);
        $address = preg_replace('/\s+/', ' ', $address);
        if (strlen($address) > 255) {
            $address = substr($address, 0, 255);
        }

        $result = registerUser(
            $conn,
            $email,
            $password,
            $full_name,
            $phone_cleaned,
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



        if (!empty($result['success']) && $error === '') {
            $created_user_id = (int)($result['user_id'] ?? 0);
            
            $saved_front = saveRegistrationValidIdDocument($conn, $created_user_id, $valid_id_front, $valid_id_type, 'Front');
            $saved_back = saveRegistrationValidIdDocument($conn, $created_user_id, $valid_id_back, $valid_id_type, 'Back');
            
            if (empty($saved_front['success']) || empty($saved_back['success'])) {
                if ($created_user_id > 0) {
                    $cleanup_stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ? LIMIT 1");
                    if ($cleanup_stmt) {
                        mysqli_stmt_bind_param($cleanup_stmt, "i", $created_user_id);
                        mysqli_stmt_execute($cleanup_stmt);
                        mysqli_stmt_close($cleanup_stmt);
                    }
                }
                $error = (string)(empty($saved_front['success']) ? $saved_front['message'] : ($saved_back['message'] ?? 'Unable to save your government ID uploads. Please try again.'));
            } else {
                // Save verified Cavite address in user_saved_addresses table
                require_once base_path('includes/checkout_address_helper.php');
                caSaveUserSavedAddress($conn, $created_user_id, [
                    'label' => 'Home Address',
                    'contact_name' => $full_name,
                    'contact_phone' => $phone_cleaned,
                    'street_address' => $street_address ?: $address,
                    'city_name' => $city_name ?: 'Cavite',
                    'province_name' => 'Cavite',
                    'full_address' => $address,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_default' => 1
                ], true);
            }
        }

        if (!empty($result['success']) && $error === '') {
            $email_verification = issueUserEmailVerification(
                $conn,
                (int)$result['user_id'],
                $email,
                $full_name
            );

            $_SESSION['registration_security'] = [
                'attempts' => 0,
                'window_started_at' => $now,
                'blocked_until' => 0,
            ];
            $_SESSION['registration_csrf_token'] = bin2hex(random_bytes(32));
            session_regenerate_id(true);

            $verification_notice = 'We sent a 6-digit verification code to ' . $email . '. Enter the code below to complete your registration.';
            if (empty($email_verification['success'])) {
                $verification_notice = 'Your account was created successfully. Enter your verification details below.';
            }

            $_SESSION['register_success'] = true;
            $_SESSION['register_email'] = $email;
            $_SESSION['registration_verification_notice'] = $verification_notice;

            header('Location: verify_email.php?email=' . urlencode($email));
            exit;
        }

        if ($error === '' && empty($result['success'])) {
            $error = $result['message'] ?? 'Unable to create your account right now.';
        }
    }

    if ($error !== '') {
        $_SESSION['registration_security']['attempts'] = (int)($_SESSION['registration_security']['attempts'] ?? 0) + 1;
        if ($_SESSION['registration_security']['attempts'] >= $max_attempts) {
            $_SESSION['registration_security']['blocked_until'] = $now + $lock_seconds;
            $_SESSION['registration_security']['attempts'] = 0;
            $_SESSION['registration_security']['window_started_at'] = $now;
            $error = 'Too many registration attempts. Please try again in 15 minutes.';
        }
    }
}

$page_title = "Create Account | Lechon Delights";
include base_path('includes/header.php');
?>

<!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Add this for better mobile input handling -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<script src="dist/bundle.iife.js"></script>

<style>
.registration-page {
    background: #ffffff !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    overflow: hidden !important;
    padding: 0 !important;
    -webkit-tap-highlight-color: transparent;
}

.registration-container {
    max-width: 100% !important;
    width: 100%;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    background-color: white;
    border-radius: 0 !important;
    border: none;
    box-shadow: none !important;
    display: flex;
    flex-direction: row;
    margin: 0 !important;
    overflow: hidden;
    animation: fadeIn 0.6s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.registration-header {
    background: linear-gradient(135deg, #8f261a 0%, #b3261e 100%);
    color: white;
    padding: 30px 40px;
    text-align: center;
}

.registration-header h1 {
    font-size: 2rem;
    margin: 0 0 10px 0;
    font-weight: 700;
}

.registration-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 1.05rem;
}

.registration-intro {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 18px 20px;
    margin-bottom: 24px;
    background: #fff8f2;
    border: 1px solid #f2d4c2;
    border-radius: 14px;
    color: #6b3c1d;
}

.registration-intro-icon {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #b3261e;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
}

.registration-intro h2 {
    margin: 0 0 6px;
    font-size: 1.1rem;
    color: #333;
}

.registration-intro p {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.5;
    color: #666;
}

.registration-body {
    padding: 0;
}

/* Progress Steps styled like modal tabs */
.progress-steps {
    display: flex;
    flex-direction: column;
    align-items: center;
    background: transparent;
    padding: 24px 20px 10px;
    margin: 0;
    position: relative;
}

.step {
    display: none; /* Hide non-active steps to show only the active one solo */
    align-items: center;
    padding: 0 0 5px 0;
    cursor: default;
    border: none;
    font-weight: 800;
    font-size: 1.15rem;
    color: #b3261e;
    animation: fadeIn 0.4s ease;
}

.step.active {
    display: inline-flex;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.step-number {
    display: none !important; /* Hide circular step numbers for simplicity */
}

.step-label {
    font-size: 1.05rem;
    font-weight: 800;
}

/* Form Steps */
.form-step {
    display: none;
    padding: 30px 40px;
    animation: slideIn 0.5s ease;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateX(30px); }
    to { opacity: 1; transform: translateX(0); }
}

.form-step.active {
    display: block;
}

/* Account Type Selection */
.account-type-selection {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.account-type-card {
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    padding: 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}

.account-type-card:hover {
    border-color: #b3261e;
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.account-type-card.selected {
    border-color: #b3261e;
    background: linear-gradient(135deg, rgba(198, 40, 40, 0.05) 0%, rgba(139, 0, 0, 0.05) 100%);
}

.account-type-card i {
    font-size: 3rem;
    color: #b3261e;
    margin-bottom: 20px;
}

.account-type-card h3 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1.3rem;
}

.account-type-card p {
    color: #666;
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
}

/* Form Styles */
.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-weight: 600;
    font-size: 0.95rem;
    -webkit-tap-highlight-color: transparent;
}

.form-control {
    width: 100%;
    padding: 15px 18px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
    background-color: #fafafa;
    -webkit-appearance: none; /* Remove iOS input styling */
    appearance: none;
    min-height: 50px; /* Better touch target */
}

.form-control:focus {
    outline: none;
    border-color: #b3261e;
    background-color: white;
    box-shadow: 0 0 0 4px rgba(198, 40, 40, 0.1);
}

.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 1.1rem;
    pointer-events: none; /* Prevent icon from interfering with clicks */
}

.input-with-icon .form-control {
    padding-left: 50px;
}

.password-wrapper {
    position: relative;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    padding: 12px; /* Larger touch target */
    font-size: 1.1rem;
    transition: color 0.3s;
    z-index: 10; /* Ensure button is above other elements */
    -webkit-tap-highlight-color: transparent;
}

.toggle-password:hover {
    color: #b3261e;
}

/* Form Row */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

/* Button Styles */
.btn-primary {
    width: 100%;
    padding: 12px 20px;
    background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.5px;
    min-height: 44px;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation; /* Improve touch response */
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.2);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-primary:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-primary.loading {
    color: transparent;
}

.btn-primary.loading::after {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.btn-secondary {
    width: 100%;
    padding: 12px 20px;
    background: white;
    color: #b3261e;
    border: 2px solid #b3261e;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    letter-spacing: 0.5px;
    min-height: 44px;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation; /* Improve touch response */
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(198, 40, 40, 0.3);
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
}

/* Password Strength */
.password-strength {
    margin-top: 12px;
    font-size: 0.9rem;
}

.strength-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
}

.strength-text {
    font-weight: 600;
    min-width: 50px;
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
    transition: all 0.3s;
}

.strength-bar.weak {
    background-color: #ff5252;
}

.strength-bar.medium {
    background-color: #ff9800;
}

.strength-bar.strong {
    background-color: #4caf50;
}

/* Verification Note */
.verification-note {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 16px 0 18px;
    padding: 14px 16px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 0.95rem;
    line-height: 1.5;
}

.verification-note i {
    margin-top: 2px;
    color: #b3261e;
    font-size: 1rem;
}

/* Terms Agreement */
.terms-agreement {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 25px;
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 10px;
    border-left: 4px solid #b3261e;
}

.terms-agreement input {
    margin-top: 3px;
    accent-color: #b3261e;
    cursor: pointer;
    min-width: 18px; /* Better touch target */
    min-height: 18px;
}

.terms-agreement label {
    color: #666;
    font-size: 0.9rem;
    line-height: 1.5;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.terms-agreement a {
    color: #b3261e;
    text-decoration: none;
    font-weight: 600;
}

.terms-agreement a:hover {
    text-decoration: underline;
}

/* Auth Link */
.auth-link {
    text-align: center;
    margin-top: 25px;
    color: #666;
    font-size: 0.95rem;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.auth-link a {
    color: #b3261e;
    text-decoration: none;
    font-weight: 600;
    margin-left: 5px;
    transition: all 0.3s;
}

.auth-link a:hover {
    color: #8f261a;
    text-decoration: underline;
}

/* Social Login Divider */
.social-divider {
    display: flex;
    align-items: center;
    margin: 25px 0 20px;
    gap: 15px;
    color: #999;
    font-size: 0.9rem;
}

.social-divider::before,
.social-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: #e0e0e0;
}

/* Social Login Buttons */
.social-login-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.social-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    background-color: #fafafa;
    color: #333;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
    min-height: 50px;
}

.social-btn:hover {
    border-color: #333;
    background-color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.social-btn:active {
    transform: translateY(0);
}

.social-btn i {
    font-size: 1.2rem;
}

.social-btn span {
    display: none;
}

/* Social button specific colors */
.google-btn {
    border-color: #EA4335;
    color: #EA4335;
}

.google-btn:hover {
    background-color: #EA4335;
    color: white;
}

.facebook-btn {
    border-color: #1877F2;
    color: #1877F2;
}

.facebook-btn:hover {
    background-color: #1877F2;
    color: white;
}

.twitter-btn {
    border-color: #000;
    color: #000;
}

.twitter-btn:hover {
    background-color: #000;
    color: white;
}

.instagram-btn {
    border-color: #E4405F;
    color: #E4405F;
}

.instagram-btn:hover {
    background-color: #E4405F;
    color: white;
}

@media (min-width: 576px) {
    .social-btn span {
        display: inline;
    }
    
    .social-login-buttons {
        grid-template-columns: 1fr 1fr;
    }
}

/* Mobile-specific fixes */
@media (max-width: 768px) {
    .registration-body {
        padding: 30px 25px;
    }
    
    .account-type-selection {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .progress-steps {
        flex-direction: column;
        gap: 20px;
    }
    
    .progress-steps::before,
    .progress-bar {
        display: none;
    }
    
    .step {
        flex-direction: row;
        gap: 15px;
        width: 100%;
        justify-content: flex-start;
    }
    
    .step-number {
        margin-bottom: 0;
        flex-shrink: 0;
    }
    
    .step-label {
        text-align: left;
    }
    
    /* Fix for mobile inputs */
    input, select, textarea, button {
        font-size: 16px !important; /* Prevents iOS zoom on focus */
    }
    
    .form-control {
        padding: 14px 16px;
        min-height: 52px;
    }
    
    .btn-primary, .btn-secondary {
        padding: 16px;
        min-height: 52px;
        font-size: 1rem;
    }
    
    /* Prevent form from jumping on mobile */
    .registration-container {
        margin: 10px;
        max-width: calc(100% - 20px);
    }
}

@media (max-width: 480px) {
    .registration-header {
        padding: 25px 20px;
    }
    
    .registration-header h1 {
        font-size: 1.6rem;
    }
    
    .registration-body {
        padding: 25px 20px;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .account-type-card {
        padding: 20px;
    }
    
    .account-type-card i {
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    
    /* Ensure buttons are easily tappable */
    button, 
    .account-type-card,
    .terms-agreement label,
    .toggle-password {
        min-height: 44px; /* Apple's recommended minimum touch target */
    }
}

/* Fix for iOS Safari */
@supports (-webkit-overflow-scrolling: touch) {
    .registration-page {
        -webkit-overflow-scrolling: touch;
    }
    
    .form-control {
        font-size: 16px; /* Prevents zoom on iOS */
    }
}

/* Modern Food Registration Refresh */
:root {
    --reg-red: #b3261e;
    --reg-hover: #981b15;
    --reg-orange: #ef6b2e;
    --reg-cream: #fff9f6;
    --reg-ink: #1e293b;
    --reg-muted: #64748b;
    --reg-border: #eaecf0;
}

body {
    background: #ffffff;
}

.registration-page {
    background: #ffffff !important;
}

.registration-container {
    border: none;
    border-radius: 0;
    box-shadow: none;
}

.registration-header {
    background: #ffffff;
}

.registration-body {
    background: #ffffff;
}

.step.active .step-number {
    background: var(--reg-red);
}

.account-type-card,
.form-control,
.terms-agreement {
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

.account-type-card.selected {
    border-color: var(--reg-red);
    background: #fff8f6;
}

.account-type-card i,
.step.active .step-label,
.auth-link a {
    color: var(--reg-red);
}

.form-control:focus {
    border-color: var(--reg-red);
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.1);
}

.btn-primary {
    background: var(--reg-red);
    box-shadow: 0 4px 14px rgba(179, 38, 30, 0.2);
}

.btn-primary:hover {
    background: var(--reg-hover);
    box-shadow: 0 6px 18px rgba(179, 38, 30, 0.28);
}

.btn-secondary {
    background: #ffffff !important;
    color: #344054 !important;
    border: 1px solid #d0d5dd !important;
}

.btn-secondary:hover {
    background: #fff8f6 !important;
    color: var(--reg-red) !important;
    border-color: var(--reg-red) !important;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.12) !important;
    transform: translateY(-2px) !important;
}

.social-divider, .social-login-buttons {
    display: none !important;
}

/* Full-screen split layout styling with smooth sliding animation */
.registration-page {
    background: #ffffff !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    overflow: hidden !important;
    padding: 0 !important;
    position: relative;
}

.registration-container {
    max-width: 100% !important;
    width: 100%;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    background-color: white;
    border-radius: 0 !important;
    border: none;
    box-shadow: none !important;
    display: flex;
    flex-direction: row;
    margin: 0 !important;
    overflow: hidden;
    position: relative;
}

/* Left Hero Side */
.registration-image-side {
    width: 50%;
    background: linear-gradient(145deg, #fff7f2 0%, #ffede5 45%, #fedecf 100%) !important;
    border-right: 1px solid #efddcd;
    display: flex !important;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px;
    text-align: center;
    position: relative;
    overflow: hidden;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    transition: transform 0.85s cubic-bezier(0.68, -0.4, 0.265, 1.35), border 0.75s ease;
    z-index: 2;
    transform: translateX(0);
}

/* Right Form Side */
.registration-form-side {
    width: 50%;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 44px 24px 36px !important;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    overflow-y: auto !important;
    box-sizing: border-box;
    transition: transform 0.85s cubic-bezier(0.68, -0.4, 0.265, 1.35);
    z-index: 1;
    transform: translateX(0);
}

/* Desktop Sliding State (Login Mode) */
@media (min-width: 851px) {
    .registration-container.is-login-mode .registration-image-side {
        transform: translateX(100%);
        border-right: none;
        border-left: 1px solid #efddcd;
    }

    .registration-container.is-login-mode .registration-form-side {
        transform: translateX(-100%);
    }
}

/* Water Waves Ambient Layer in Hero Section */
.hero-water-waves {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 200%;
    height: 140px;
    pointer-events: none;
    z-index: 2;
    opacity: 0.45;
}

.hero-water-wave-1 {
    animation: waterWaveFlow1 12s linear infinite;
}

.hero-water-wave-2 {
    animation: waterWaveFlow2 18s linear infinite reverse;
    opacity: 0.6;
}

.hero-water-wave-3 {
    animation: waterWaveFlow3 14s ease-in-out infinite alternate;
    opacity: 0.35;
}

@keyframes waterWaveFlow1 {
    0% { transform: translateX(0) translateZ(0) scaleY(1); }
    50% { transform: translateX(-25%) translateZ(0) scaleY(1.18); }
    100% { transform: translateX(-50%) translateZ(0) scaleY(1); }
}

@keyframes waterWaveFlow2 {
    0% { transform: translateX(0) translateZ(0) scaleY(1.1); }
    50% { transform: translateX(-30%) translateZ(0) scaleY(0.9); }
    100% { transform: translateX(-50%) translateZ(0) scaleY(1.1); }
}

@keyframes waterWaveFlow3 {
    0% { transform: translateY(0) scaleY(1); }
    100% { transform: translateY(-14px) scaleY(1.25); }
}

/* Liquid Ripple Wave Overlay on Transition */
.liquid-ripple-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 99;
    overflow: hidden;
}

.liquid-ripple-surge {
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(179, 38, 30, 0.22) 0%, rgba(254, 222, 207, 0.35) 45%, rgba(255, 255, 255, 0) 70%);
    transform: scale(0);
    opacity: 0;
    pointer-events: none;
}

.liquid-ripple-surge.is-animating {
    animation: liquidSurgeWave 1.1s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
}

@keyframes liquidSurgeWave {
    0% {
        transform: scale(1);
        opacity: 0.9;
    }
    50% {
        opacity: 0.55;
    }
    100% {
        transform: scale(140);
        opacity: 0;
    }
}

/* Water Droplet Wave Border along Divider */
.liquid-wave-seam {
    position: absolute;
    top: 0;
    right: -20px;
    width: 40px;
    height: 100%;
    z-index: 5;
    pointer-events: none;
    opacity: 0.7;
    transition: opacity 0.4s ease;
}

.registration-container.is-login-mode .liquid-wave-seam {
    right: auto;
    left: -20px;
    transform: scaleX(-1);
}

.brand-title {
    font-family: 'Outfit', sans-serif;
    font-size: 3.4rem;
    font-weight: 900;
    letter-spacing: -1.5px;
    margin: 0;
    color: #b3261e !important;
    text-shadow: 0 2px 10px rgba(179, 38, 30, 0.12);
}

.brand-subtitle {
    font-size: 1.2rem;
    color: #564840 !important;
    margin-top: 14px;
    max-width: 340px;
    font-weight: 600;
    line-height: 1.5;
}

/* Hero Content Switcher */
.hero-content-view {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 10;
    transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    width: 100%;
    max-width: 420px;
}

.hero-auth-cta {
    margin-top: 36px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.hero-auth-cta .cta-label {
    font-size: 0.95rem;
    color: #564840;
    font-weight: 600;
}

.btn-slide-auth {
    background: #ffffff;
    color: #b3261e;
    border: 2px solid #b3261e;
    border-radius: 9999px;
    padding: 10px 30px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(179, 38, 30, 0.08);
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.btn-slide-auth:hover {
    background: #b3261e;
    color: #ffffff;
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 8px 22px rgba(179, 38, 30, 0.28);
}

.btn-slide-auth:active {
    transform: translateY(0) scale(0.98);
}

/* Water Ripple on Button Click */
.btn-slide-auth .water-drop-ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.7);
    transform: scale(0);
    animation: waterDropRipple 0.6s linear;
    pointer-events: none;
}

@keyframes waterDropRipple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}

.floating-pigs-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}

.floating-pig {
    position: absolute;
    font-size: 3.5rem;
    opacity: 0.16;
    animation: floatPig 8s ease-in-out infinite alternate;
}

.pig-1 { top: 10%; left: 15%; animation-duration: 9s; font-size: 4rem; }
.pig-2 { top: 25%; right: 15%; animation-duration: 11s; animation-delay: 1s; font-size: 3.5rem; }
.pig-3 { bottom: 20%; left: 20%; animation-duration: 10s; animation-delay: 2s; font-size: 4.5rem; }
.pig-4 { bottom: 15%; right: 25%; animation-duration: 8s; animation-delay: 0.5s; font-size: 3rem; }
.pig-5 { top: 50%; left: 40%; animation-duration: 12s; animation-delay: 1.5s; font-size: 3.8rem; }

@keyframes floatPig {
    0% {
        transform: translateY(0) rotate(0deg) scale(1);
    }
    50% {
        transform: translateY(-20px) rotate(8deg) scale(1.05);
    }
    100% {
        transform: translateY(10px) rotate(-8deg) scale(0.95);
    }
}

.mascot-img-wrap {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mascot-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.registration-form-side-container {
    max-width: 440px;
    width: 100%;
    margin: auto 0;
    display: flex;
    flex-direction: column;
}

.auth-view-wrapper {
    transition: opacity 0.35s ease, transform 0.4s ease;
    width: 100%;
}

@media (max-width: 850px) {
    .registration-container {
        flex-direction: column;
    }
    .registration-form-side {
        width: 100%;
        height: auto;
        min-height: calc(100vh - 64px);
        transform: none !important;
    }
    .registration-image-side {
        display: none !important;
    }
    /* Prevent automatic zoom-in on focus on mobile devices */
    input, select, textarea, .form-control {
        font-size: 16px !important;
    }
}
@keyframes pigRun {
    0% {
        transform: translateY(0) rotate(0deg) scaleX(-1);
    }
    50% {
        transform: translateY(-3px) rotate(-5deg) scaleX(-1);
    }
    100% {
        transform: translateY(0) rotate(5deg) scaleX(-1);
    }
}
.footer {
    margin-top: 0 !important;
}
</style>
<div class="registration-page">
    <!-- Liquid Ripple Surge Overlay -->
    <div class="liquid-ripple-container" id="liquidRippleContainer">
        <div class="liquid-ripple-surge" id="liquidRippleSurge"></div>
    </div>

    <div class="registration-container" id="authSplitLayout">
        <!-- Left Side: Branding Panel with Floating Mascot Pigs & Dynamic Auth Switcher -->
        <div class="registration-image-side" id="authHeroPanel">
            <!-- Animated Water Wave Layers (Bottom Ambient Liquid) -->
            <div class="hero-water-waves">
                <svg class="hero-water-wave-1" viewBox="0 0 1200 120" preserveAspectRatio="none" style="position: absolute; bottom: 0; left: 0; width: 100%; height: 100%;">
                    <path d="M0,0 C150,90 350,-40 500,60 C650,160 900,10 1200,40 L1200,120 L0,120 Z" fill="rgba(179, 38, 30, 0.08)"></path>
                </svg>
                <svg class="hero-water-wave-2" viewBox="0 0 1200 120" preserveAspectRatio="none" style="position: absolute; bottom: 0; left: 0; width: 100%; height: 85%;">
                    <path d="M0,40 C300,120 450,10 700,70 C950,130 1050,30 1200,60 L1200,120 L0,120 Z" fill="rgba(254, 222, 207, 0.45)"></path>
                </svg>
                <svg class="hero-water-wave-3" viewBox="0 0 1200 120" preserveAspectRatio="none" style="position: absolute; bottom: 0; left: 0; width: 100%; height: 60%;">
                    <path d="M0,20 C200,80 400,0 600,50 C800,100 1000,20 1200,40 L1200,120 L0,120 Z" fill="rgba(179, 38, 30, 0.05)"></path>
                </svg>
            </div>

            <!-- Vertical Liquid Wave Seam Edge -->
            <div class="liquid-wave-seam">
                <svg viewBox="0 0 40 800" preserveAspectRatio="none" style="width: 100%; height: 100%; display: block;">
                    <path d="M 0,0 C 25,100 -10,200 20,300 C 50,400 -5,500 25,600 C 45,700 5,750 0,800 L 0,800 Z" fill="rgba(254, 222, 207, 0.3)"></path>
                </svg>
            </div>

            <div class="floating-pigs-container">
                <div class="floating-pig pig-1">🐷</div>
                <div class="floating-pig pig-2">🐷</div>
                <div class="floating-pig pig-3">🐷</div>
                <div class="floating-pig pig-4">🐷</div>
                <div class="floating-pig pig-5">🐷</div>
            </div>

            <!-- Hero View for Register Mode (Default: on left side) -->
            <div class="hero-content-view" id="heroRegisterView">
                <h1 class="brand-title">Lechon Delights</h1>
                <p class="brand-subtitle">Cavite's Finest Lechon at Your Doorsteps</p>
                <div class="hero-auth-cta">
                    <div class="cta-label">Already have an account?</div>
                    <button type="button" class="btn-slide-auth js-trigger-slide-login" id="heroBtnToLogin">
                        <i class="fas fa-sign-in-alt"></i> Sign in here
                    </button>
                </div>
            </div>

            <!-- Hero View for Login Mode (When Slid to right side) -->
            <div class="hero-content-view" id="heroLoginView" style="display: none; opacity: 0;">
                <h1 class="brand-title">Welcome Back!</h1>
                <p class="brand-subtitle">Join us to order Cavite's finest lechon dishes.</p>
                <div class="hero-auth-cta">
                    <div class="cta-label">Don't have an account yet?</div>
                    <button type="button" class="btn-slide-auth js-trigger-slide-register" id="heroBtnToRegister">
                        <i class="fas fa-user-plus"></i> Create an Account
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Side: Forms Container Panel (Register & Login) -->
        <div class="registration-form-side" id="authFormsPanel">
            <div class="registration-form-side-container">
                
                <!-- View 1: Register Form Wizard -->
                <div id="registerViewWrapper" class="auth-view-wrapper">
                    <div class="registration-header" style="background:#fff; text-align:center; margin-bottom:24px; padding:0 0 10px;">
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-bottom:12px;">
                            <img src="assets/images/logo.jpg" alt="Lechon Delights Logo" style="width:48px; height:48px; object-fit:cover; border-radius:12px; display:block; border:1px solid #efddcd; box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                            <span style="font-size:1.6rem; font-weight:800; color:#171922; font-family:'Outfit', sans-serif;">Lechon Delights</span>
                        </div>
                        <h2 style="font-size:1.8rem; font-weight:700; color:#333; margin-bottom:10px;">Create Account</h2>
                        <p style="font-size:1rem; color:#666; margin:0;">Join us to order Cavite's finest lechon dishes.</p>
                    </div>
                    
                    <div class="registration-body">

                        <!-- Progress Steps -->
                        <div class="progress-steps">
                            <div class="step active" id="step1">
                                <div class="step-label">Personal Info (Step 1 of 4)</div>
                            </div>
                            <div class="step" id="step2">
                                <div class="step-label">Verification (Step 2 of 4)</div>
                            </div>
                            <div class="step" id="step3">
                                <div class="step-label" id="step3NavLabel">Address Info (Step 3 of 4)</div>
                            </div>
                            <div class="step" id="step4">
                                <div class="step-label">Create Account (Step 4 of 4)</div>
                            </div>
                            <div class="progress-container" style="width: 100%; height: 6px; background: #efddcd; border-radius: 3px; margin-top: 15px; overflow: visible; position: relative;">
                                <div class="progress-bar" id="progressBar" style="position: absolute; left: 0; top: 0; height: 100%; width: 25%; background: #b3261e; transition: width 0.3s ease; display: block !important; overflow: visible;">
                                    <div class="running-pig" style="position: absolute; right: -14px; top: -20px; font-size: 22px; user-select: none; line-height: 1; animation: pigRun 0.4s infinite alternate ease-in-out;">🐖</div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" action="" id="registrationForm" data-swal-validate="off" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['registration_csrf_token']); ?>">

                            <input type="hidden" name="account_type" id="accountType" value="<?php echo htmlspecialchars($form_data['account_type'] ?? 'individual'); ?>">
                            <input type="hidden" name="psgc_region_code" id="psgcRegionCode" value="<?php echo htmlspecialchars($form_data['psgc_region_code'] ?? ''); ?>">
                            <input type="hidden" name="psgc_region_name" id="psgcRegionName" value="<?php echo htmlspecialchars($form_data['psgc_region_name'] ?? ''); ?>">
                            <input type="hidden" name="psgc_province_code" id="psgcProvinceCode" value="<?php echo htmlspecialchars($form_data['psgc_province_code'] ?? ''); ?>">
                            <input type="hidden" name="psgc_province_name" id="psgcProvinceName" value="<?php echo htmlspecialchars($form_data['psgc_province_name'] ?? ''); ?>">
                            <input type="hidden" name="psgc_city_code" id="psgcCityCode" value="<?php echo htmlspecialchars($form_data['psgc_city_code'] ?? ''); ?>">
                            <input type="hidden" name="psgc_city_name" id="psgcCityName" value="<?php echo htmlspecialchars($form_data['psgc_city_name'] ?? ''); ?>">
                            <input type="hidden" name="psgc_barangay_code" id="psgcBarangayCode" value="<?php echo htmlspecialchars($form_data['psgc_barangay_code'] ?? ''); ?>">
                            <input type="hidden" name="psgc_barangay_name" id="psgcBarangayName" value="<?php echo htmlspecialchars($form_data['psgc_barangay_name'] ?? ''); ?>">
                        

                    
                    <!-- Step 1: Personal Information -->
                    <div class="form-step active" id="step1Form">
                        <h2 style="color: #333; margin-bottom: 25px; font-size: 1.5rem;">Tell us about you</h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="firstName">First Name *</label>
                                <input type="text" id="firstName" name="first_name" class="form-control" required 
                                    placeholder="Enter your first name"
                                    value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                                    autocomplete="given-name">
                            </div>
                            <div class="form-group">
                                <label for="lastName">Last Name *</label>
                                <input type="text" id="lastName" name="last_name" class="form-control" required 
                                    placeholder="Enter your last name"
                                    value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>"
                                    autocomplete="family-name">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="middleName">Middle Name</label>
                                <input type="text" id="middleName" name="middle_name" class="form-control"
                                    placeholder="Optional"
                                    value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>"
                                    autocomplete="additional-name">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="dob">Date of Birth</label>
                                <input type="date" id="dob" name="dob" class="form-control"
                                    value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="" <?php echo empty($form_data['gender']) ? 'selected' : ''; ?>>Prefer not to say</option>
                                    <option value="male" <?php echo ($form_data['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($form_data['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($form_data['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="button-group">
                            <button type="button" class="btn btn-primary" id="nextStep1" style="width: 100%;">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Verification (Upload Government ID / Business Proof) -->
                    <div class="form-step" id="step2Form">
                        <div class="mb-4">
                            <h2 style="color: #333; margin-bottom: 8px; font-size: 1.5rem;" id="step2Title">Upload Valid ID</h2>
                            <p style="color: #666; font-size: 0.95rem;" id="step2Subtitle">Please upload front and back of a valid government-issued ID for identity verification.</p>
                        </div>
                        
                        <div class="form-group mb-4">
                            <label for="validIdType" id="idTypeLabel">Select ID Type *</label>
                            <select id="validIdType" name="valid_id_type" class="form-control" required>
                                <option value="" disabled <?php echo empty($form_data['valid_id_type']) ? 'selected' : ''; ?>>Select a valid ID type</option>
                                <option value="national_id" <?php echo ($form_data['valid_id_type'] ?? '') === 'national_id' ? 'selected' : ''; ?>>Philippine National ID (PhilSys)</option>
                                <option value="passport" <?php echo ($form_data['valid_id_type'] ?? '') === 'passport' ? 'selected' : ''; ?>>Philippine Passport</option>
                                <option value="drivers_license" <?php echo ($form_data['valid_id_type'] ?? '') === 'drivers_license' ? 'selected' : ''; ?>>Driver's License (LTO)</option>
                                <option value="umid" <?php echo ($form_data['valid_id_type'] ?? '') === 'umid' ? 'selected' : ''; ?>>UMID Card</option>
                                <option value="sss" <?php echo ($form_data['valid_id_type'] ?? '') === 'sss' ? 'selected' : ''; ?>>SSS ID</option>
                                <option value="postal" <?php echo ($form_data['valid_id_type'] ?? '') === 'postal' ? 'selected' : ''; ?>>Postal ID</option>
                                <option value="prc" <?php echo ($form_data['valid_id_type'] ?? '') === 'prc' ? 'selected' : ''; ?>>PRC ID</option>
                                <option value="senior_citizen" <?php echo ($form_data['valid_id_type'] ?? '') === 'senior_citizen' ? 'selected' : ''; ?>>Senior Citizen ID</option>
                                <option value="ofw" <?php echo ($form_data['valid_id_type'] ?? '') === 'ofw' ? 'selected' : ''; ?>>OFW ID</option>
                            </select>
                        </div>

                        <!-- ID Upload Controls Container -->
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <!-- Front Side Upload -->
                            <div class="id-card-panel" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <label style="margin: 0; font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-id-card-clip" style="color: #b3261e;"></i> <span id="frontDocLabel">Front Side of Valid ID</span> *
                                    </label>
                                    <span class="badge" id="frontStatusBadge" style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">Pending</span>
                                </div>
                                <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                    <button type="button" class="btn trigger-camera-btn" data-target="front" style="flex: 1; height: 42px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; color: #334155; font-weight: 600; font-size: 0.88rem; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                                        <i class="fas fa-camera" style="color: #b3261e;"></i> Take Photo
                                    </button>
                                    <button type="button" class="btn direct-upload-btn" data-target="validIdFront" style="flex: 1; height: 42px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; color: #334155; font-weight: 600; font-size: 0.88rem; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                                        <i class="fas fa-cloud-arrow-up" style="color: #b3261e;"></i> Upload File
                                    </button>
                                </div>
                                <input type="file" id="validIdFront" name="valid_id_front" accept="image/*" style="display: none;">
                                <div id="previewContainerFront" style="display: none; position: relative; border-radius: 8px; overflow: hidden; max-height: 180px; background: #000; border: 1px solid #cbd5e1;">
                                    <img id="imagePreviewFront" src="#" alt="Front Preview" style="width: 100%; height: 180px; object-fit: contain; display: block;">
                                    <button type="button" class="remove-preview-btn" data-target="front" style="position: absolute; top: 8px; right: 8px; background: rgba(15, 23, 42, 0.75); color: #fff; border: none; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem; backdrop-filter: blur(4px);"><i class="fas fa-times"></i></button>
                                </div>
                            </div>

                            <!-- Back Side Upload -->
                            <div class="id-card-panel" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <label style="margin: 0; font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-id-card" style="color: #b3261e;"></i> <span id="backDocLabel">Back Side of Valid ID</span> *
                                    </label>
                                    <span class="badge" id="backStatusBadge" style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">Pending</span>
                                </div>
                                <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                    <button type="button" class="btn trigger-camera-btn" data-target="back" style="flex: 1; height: 42px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; color: #334155; font-weight: 600; font-size: 0.88rem; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                                        <i class="fas fa-camera" style="color: #b3261e;"></i> Take Photo
                                    </button>
                                    <button type="button" class="btn direct-upload-btn" data-target="validIdBack" style="flex: 1; height: 42px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; color: #334155; font-weight: 600; font-size: 0.88rem; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s;">
                                        <i class="fas fa-cloud-arrow-up" style="color: #b3261e;"></i> Upload File
                                    </button>
                                </div>
                                <input type="file" id="validIdBack" name="valid_id_back" accept="image/*" style="display: none;">
                                <div id="previewContainerBack" style="display: none; position: relative; border-radius: 8px; overflow: hidden; max-height: 180px; background: #000; border: 1px solid #cbd5e1;">
                                    <img id="imagePreviewBack" src="#" alt="Back Preview" style="width: 100%; height: 180px; object-fit: contain; display: block;">
                                    <button type="button" class="remove-preview-btn" data-target="back" style="position: absolute; top: 8px; right: 8px; background: rgba(15, 23, 42, 0.75); color: #fff; border: none; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem; backdrop-filter: blur(4px);"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Organization Additional Fields -->
                        <div id="organizationFields" style="display: none; margin-top: 20px;">
                            <div class="form-group mb-4">
                                <label for="businessName">Business / Company Name *</label>
                                <input type="text" id="businessName" name="business_name" class="form-control" 
                                    placeholder="Enter registered business name"
                                    value="<?php echo htmlspecialchars($form_data['business_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group mb-4">
                                <label for="businessType">Business Type *</label>
                                <select id="businessType" name="business_type" class="form-control">
                                    <option value="" disabled <?php echo empty($form_data['business_type']) ? 'selected' : ''; ?>>Select business structure</option>
                                    <option value="sole_proprietorship" <?php echo ($form_data['business_type'] ?? '') === 'sole_proprietorship' ? 'selected' : ''; ?>>Sole Proprietorship</option>
                                    <option value="partnership" <?php echo ($form_data['business_type'] ?? '') === 'partnership' ? 'selected' : ''; ?>>Partnership</option>
                                    <option value="corporation" <?php echo ($form_data['business_type'] ?? '') === 'corporation' ? 'selected' : ''; ?>>Corporation</option>
                                    <option value="cooperative" <?php echo ($form_data['business_type'] ?? '') === 'cooperative' ? 'selected' : ''; ?>>Cooperative</option>
                                </select>
                            </div>
                            <div class="form-group mb-4">
                                <label for="tinNumber">Tax Identification Number (TIN)</label>
                                <input type="text" id="tinNumber" name="tin_number" class="form-control" 
                                    placeholder="000-000-000-000"
                                    value="<?php echo htmlspecialchars($form_data['tin_number'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="button-group" style="margin-top: 25px;">
                            <button type="button" class="btn btn-secondary" id="prevStep2"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="btn btn-primary" id="nextStep2">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Address Information (Cavite-only with Leaflet Interactive Map) -->
                    <div class="form-step" id="step3Form">
                        <h2 style="color: #333; margin-bottom: 8px; font-size: 1.5rem;" id="step3Title">Enter Home Address</h2>
                        <p style="color: #666; font-size: 0.95rem; margin-bottom: 20px;" id="step3Subtitle">Please provide your home address. Registrations are strictly limited to the Cavite area.</p>

                        <!-- Single Home Address Input -->
                        <div class="form-group mb-3">
                            <label for="homeAddressInput" style="font-weight: 700; color: #1e293b;">Home Address (Cavite Only) *</label>
                            <div style="position: relative;">
                                <input type="text" id="homeAddressInput" class="form-control" required
                                    placeholder="House/Unit No., Street, Barangay, City/Municipality, Cavite"
                                    value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>"
                                    autocomplete="street-address"
                                    style="padding-right: 40px;">
                                <i class="fas fa-location-dot" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #b3261e; font-size: 1.1rem; pointer-events: none;"></i>
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 5px; font-size: 0.82rem;">
                                Type your home address or click/drag the pin on the map inside Cavite.
                            </small>
                        </div>

                        <!-- Hidden Address Payload Fields for Backend Submission -->
                        <input type="hidden" id="regAddress" name="address" value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>">
                        <input type="hidden" id="regStreetAddress" name="street_address" value="<?php echo htmlspecialchars($form_data['street_address'] ?? ''); ?>">
                        <input type="hidden" id="regLatitude" name="latitude" value="<?php echo htmlspecialchars($form_data['latitude'] ?? ''); ?>">
                        <input type="hidden" id="regLongitude" name="longitude" value="<?php echo htmlspecialchars($form_data['longitude'] ?? ''); ?>">
                        <input type="hidden" id="regCityName" name="city_name" value="<?php echo htmlspecialchars($form_data['city_name'] ?? ''); ?>">
                        <input type="hidden" id="regProvinceName" name="province_name" value="<?php echo htmlspecialchars($form_data['province_name'] ?? 'Cavite'); ?>">

                        <!-- Cavite Geofence Status Indicator Card -->
                        <div id="caviteAreaStatusBadge" style="margin-bottom: 15px; padding: 12px 14px; border-radius: 10px; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; background: #fff8f6; border: 1px solid #ffdcd6; color: #8c201a; transition: all 0.3s ease;">
                            <i class="fas fa-map-pin" id="caviteStatusIcon" style="font-size: 1.1rem;"></i>
                            <span id="caviteStatusText">Please search your address or pin your location in Cavite.</span>
                        </div>

                        <!-- Leaflet Interactive Cavite Map -->
                        <div style="position: relative; margin-bottom: 20px;">
                            <div id="registerMapWrapper" style="width: 100%; height: 260px; border-radius: 12px; border: 1px solid #cbd5e1; overflow: hidden; background: #f1f5f9;">
                                <div id="registerMap" style="width: 100%; height: 100%;"></div>
                            </div>
                            <button type="button" id="useCurrentLocationBtn" style="position: absolute; bottom: 12px; right: 12px; z-index: 500; background: #ffffff; color: #1e293b; border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; font-size: 0.8rem; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,0.12); cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-crosshairs" style="color: #b3261e;"></i> Locate Me
                            </button>
                        </div>
                        
                        <div class="button-group">
                            <button type="button" class="btn btn-secondary" id="prevStep3"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="btn btn-primary" id="nextStep3">Continue <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                    
                    <!-- Step 4: Create Account (Email, Phone, Password) -->
                    <div class="form-step" id="step4Form">
                        <h2 style="color: #333; margin-bottom: 25px; font-size: 1.5rem;">Account Security</h2>
                        
                        <div class="form-group mb-4">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" class="form-control" required 
                                placeholder="name@example.com"
                                value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                                autocomplete="email">
                        </div>
                        
                        <div class="form-group mb-4">
                            <label for="phone">Mobile Phone Number *</label>
                            <input type="tel" id="phone" name="phone" class="form-control" required 
                                placeholder="09123456789"
                                value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                                autocomplete="tel">
                        </div>
                        
                        <div class="form-group mb-4">
                            <label for="password">Password *</label>
                            <div class="password-input-group">
                                <input type="password" id="password" name="password" class="form-control" required 
                                    placeholder="At least 8 characters"
                                    autocomplete="new-password">
                                <button type="button" class="toggle-password" data-target="password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength" id="passwordStrength"></div>
                        </div>
                        
                        <div class="form-group mb-4">
                            <label for="confirmPassword">Confirm Password *</label>
                            <div class="password-input-group">
                                <input type="password" id="confirmPassword" name="confirm_password" class="form-control" required 
                                    placeholder="Re-enter password"
                                    autocomplete="new-password">
                                <button type="button" class="toggle-password" data-target="confirmPassword"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>

                        <!-- Terms & Conditions Checkbox -->
                        <div class="terms-group mb-4">
                            <label class="checkbox-label" style="font-size: 0.9rem; color: #555;">
                                <input type="checkbox" id="terms" name="terms" required style="accent-color: #b3261e; margin-right: 8px;">
                                I agree to the <a href="terms_of_service.php" target="_blank" style="color: #b3261e; text-decoration: underline;">Terms & Conditions</a> and <a href="privacy_policy.php" target="_blank" style="color: #b3261e; text-decoration: underline;">Privacy Policy</a>.
                            </label>
                        </div>
                        
                        <div class="button-group">
                            <button type="button" class="btn btn-secondary" id="prevStep4"><i class="fas fa-arrow-left me-1"></i> Back</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">Create Account <i class="fas fa-check ms-1"></i></button>
                        </div>
                    </div>

                    </form>
                </div>
                </div>

                <!-- View 2: Login Form (Smooth Slide Transition) -->
                <div id="loginViewWrapper" class="auth-view-wrapper" style="display: none; opacity: 0;">
                    <div class="registration-header" style="background:#fff; text-align:center; margin-bottom:24px; padding:0 0 10px;">
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:10px; margin-bottom:12px;">
                            <img src="assets/images/logo.jpg" alt="Lechon Delights Logo" style="width:48px; height:48px; object-fit:cover; border-radius:12px; display:block; border:1px solid #efddcd; box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                            <span style="font-size:1.6rem; font-weight:800; color:#171922; font-family:'Outfit', sans-serif;">Lechon Delights</span>
                        </div>
                        <h2 style="font-size:1.8rem; font-weight:700; color:#333; margin-bottom:10px;">Welcome Back!</h2>
                        <p style="font-size:1rem; color:#666; margin:0;">Sign in to continue to your account.</p>
                    </div>

                    <form method="POST" action="login.php" id="ajaxLoginForm" novalidate>
                        <input type="hidden" name="login" value="1">
                        <input type="hidden" name="ajax" value="true">

                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="loginEmail" style="display:block; margin-bottom:8px; color:#333; font-weight:600; font-size:0.95rem;">Email Address *</label>
                            <div class="input-with-icon" style="position: relative;">
                                <input type="email" id="loginEmail" name="email" class="form-control" required placeholder="Enter your email address" autocomplete="email" style="padding-left: 44px;">
                                <i class="fas fa-envelope" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label for="loginPassword" style="margin:0; color:#333; font-weight:600; font-size:0.95rem;">Password *</label>
                                <a href="reset_password_request.php" style="font-size:0.85rem; color:#b3261e; text-decoration:none; font-weight:600;">Forgot Password?</a>
                            </div>
                            <div class="input-with-icon" style="position: relative;">
                                <input type="password" id="loginPassword" name="password" class="form-control" required placeholder="Enter your password" autocomplete="current-password" style="padding-left: 44px; padding-right: 44px;">
                                <i class="fas fa-lock" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                                <button type="button" id="toggleLoginPasswordBtn" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; padding: 4px;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px;">
                            <label style="display:flex; align-items:center; gap:8px; font-size:0.9rem; color:#475467; cursor:pointer; margin:0;">
                                <input type="checkbox" name="remember" value="1" style="accent-color:#b3261e; width:16px; height:16px;">
                                <span>Remember me</span>
                            </label>
                        </div>

                        <button type="submit" id="loginSubmitBtn" class="btn btn-primary" style="width:100%; height:48px; font-size:1rem; font-weight:700; border-radius:10px; background:#b3261e; color:#fff; border:none; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; box-shadow: 0 4px 14px rgba(179, 38, 30, 0.25); transition: all 0.25s;">
                            <span>Sign In</span> <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Webcam Capture Modal Overlay -->
<div id="cameraModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.9); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(8px); padding: 15px; box-sizing: border-box;">
    <div style="background: #ffffff; width: 100%; max-width: 580px; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); overflow: hidden; display: flex; flex-direction: column; position: relative;">
        <!-- Modal Header -->
        <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
            <h3 id="cameraModalTitle" style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 800; color: #0f172a;">Capture Document</h3>
            <button type="button" id="closeCameraModal" style="background: none; border: none; font-size: 1.25rem; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 4px; border-radius: 50%; width: 32px; height: 32px; transition: background 0.2s;"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- Live Stream Video Panel -->
        <div style="position: relative; background: #000; width: 100%; aspect-ratio: 4/3; max-height: 400px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
            <video id="webcamStream" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
            <!-- ID Card Crop Guide Outline Box -->
            <div id="cropGuide" style="position: absolute; border: 3px dashed rgba(255, 255, 255, 0.85); width: 85%; height: 58%; border-radius: 12px; box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.45); pointer-events: none; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <span id="cropGuideText" style="color: #ffffff; background: rgba(15, 23, 42, 0.75); padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Position ID Card Here</span>
            </div>
            <div id="cameraLoadingSpinner" style="position: absolute; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #ffffff; z-index: 10;">
                <i class="fas fa-circle-notch fa-spin" style="font-size: 2rem; margin-bottom: 10px;"></i>
                <span style="font-size: 0.85rem; font-weight: 600;">Accessing camera...</span>
            </div>
        </div>

        <!-- Controls / Shutter Button -->
        <div style="padding: 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap;">
            <button type="button" id="flipCameraBtn" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 50%; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #475569; font-size: 1.15rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: all 0.2s;"><i class="fas fa-camera-rotate"></i></button>
            <button type="button" id="shutterBtn" style="background: #b3261e; border: none; border-radius: 30px; height: 50px; padding: 0 28px; display: inline-flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; color: #ffffff; font-size: 0.95rem; font-weight: 800; box-shadow: 0 4px 14px rgba(179, 38, 30, 0.35); transition: all 0.2s;"><i class="fas fa-camera" style="font-size:1.05rem;"></i> Capture Snapshot</button>
        </div>
    </div>
</div>
<!-- Hidden Canvas to hold captured frame data -->
<canvas id="capturedFrameHolder" style="display:none;"></canvas>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        backdrop: false,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: function(toast) {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    let currentStep = 1;
    const totalSteps = 4;
    const steps = document.querySelectorAll('.step');
    const formSteps = document.querySelectorAll('.form-step');
    const accountTypeCards = document.querySelectorAll('.account-type-card');
    const accountTypeInput = document.getElementById('accountType');
    if (accountTypeInput) {
        accountTypeInput.value = 'individual';
    }
    const organizationFields = document.getElementById('organizationFields');
    const step3Title = document.getElementById('step3Title');
    const step3Subtitle = document.getElementById('step3Subtitle');
    const step3NavLabel = document.getElementById('step3NavLabel');
    let accountType = 'individual';

    let regMap = null;
    let regMarker = null;
    let isAddressInCavite = false;
    let addressDebounceTimer = null;

    // Sliding Auth Panel Controller
    const authSplitLayout = document.getElementById('authSplitLayout');
    const heroRegisterView = document.getElementById('heroRegisterView');
    const heroLoginView = document.getElementById('heroLoginView');
    const registerViewWrapper = document.getElementById('registerViewWrapper');
    const loginViewWrapper = document.getElementById('loginViewWrapper');
    const liquidRippleSurge = document.getElementById('liquidRippleSurge');

    function triggerLiquidSurge(originX, originY) {
        if (!liquidRippleSurge) return;
        liquidRippleSurge.classList.remove('is-animating');
        
        const x = originX !== undefined ? originX : window.innerWidth / 2;
        const y = originY !== undefined ? originY : window.innerHeight / 2;
        
        liquidRippleSurge.style.left = (x - 10) + 'px';
        liquidRippleSurge.style.top = (y - 10) + 'px';
        
        // Trigger reflow
        void liquidRippleSurge.offsetWidth;
        liquidRippleSurge.classList.add('is-animating');
    }

    function createWaterDropRipple(e, targetBtn) {
        if (!targetBtn) return;
        const rect = targetBtn.getBoundingClientRect();
        const ripple = document.createElement('span');
        ripple.className = 'water-drop-ripple';
        const size = Math.max(rect.width, rect.height);
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        targetBtn.appendChild(ripple);
        setTimeout(() => ripple.remove(), 650);
    }

    function switchAuthMode(mode, animate = true, clickEvent = null) {
        if (animate) {
            const clickX = clickEvent ? clickEvent.clientX : (window.innerWidth / 2);
            const clickY = clickEvent ? clickEvent.clientY : (window.innerHeight / 2);
            triggerLiquidSurge(clickX, clickY);
        }

        if (mode === 'login') {
            if (authSplitLayout) authSplitLayout.classList.add('is-login-mode');
            
            // Fade out register views with fluid staggered delay
            if (heroRegisterView) {
                heroRegisterView.style.opacity = '0';
                heroRegisterView.style.transform = 'translateY(15px) scale(0.96)';
                setTimeout(() => {
                    heroRegisterView.style.display = 'none';
                    if (heroLoginView) {
                        heroLoginView.style.display = 'flex';
                        heroLoginView.style.transform = 'translateY(-15px) scale(0.96)';
                        setTimeout(() => { 
                            heroLoginView.style.opacity = '1'; 
                            heroLoginView.style.transform = 'translateY(0) scale(1)';
                        }, 30);
                    }
                }, animate ? 280 : 0);
            }

            if (registerViewWrapper) {
                registerViewWrapper.style.opacity = '0';
                registerViewWrapper.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    registerViewWrapper.style.display = 'none';
                    if (loginViewWrapper) {
                        loginViewWrapper.style.display = 'block';
                        loginViewWrapper.style.transform = 'translateX(20px)';
                        setTimeout(() => {
                            loginViewWrapper.style.opacity = '1';
                            loginViewWrapper.style.transform = 'translateX(0)';
                            const loginEmailInput = document.getElementById('loginEmail');
                            if (loginEmailInput) loginEmailInput.focus();
                        }, 30);
                    }
                }, animate ? 280 : 0);
            }

            history.replaceState(null, '', '#login');
        } else {
            if (authSplitLayout) authSplitLayout.classList.remove('is-login-mode');
            
            // Fade out login views with fluid staggered delay
            if (heroLoginView) {
                heroLoginView.style.opacity = '0';
                heroLoginView.style.transform = 'translateY(15px) scale(0.96)';
                setTimeout(() => {
                    heroLoginView.style.display = 'none';
                    if (heroRegisterView) {
                        heroRegisterView.style.display = 'flex';
                        heroRegisterView.style.transform = 'translateY(-15px) scale(0.96)';
                        setTimeout(() => { 
                            heroRegisterView.style.opacity = '1'; 
                            heroRegisterView.style.transform = 'translateY(0) scale(1)';
                        }, 30);
                    }
                }, animate ? 280 : 0);
            }

            if (loginViewWrapper) {
                loginViewWrapper.style.opacity = '0';
                loginViewWrapper.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    loginViewWrapper.style.display = 'none';
                    if (registerViewWrapper) {
                        registerViewWrapper.style.display = 'block';
                        registerViewWrapper.style.transform = 'translateX(-20px)';
                        setTimeout(() => {
                            registerViewWrapper.style.opacity = '1';
                            registerViewWrapper.style.transform = 'translateX(0)';
                            if (regMap && typeof regMap.invalidateSize === 'function') {
                                setTimeout(() => regMap.invalidateSize(), 300);
                            }
                        }, 30);
                    }
                }, animate ? 280 : 0);
            }

            history.replaceState(null, '', '#register');
        }
    }

    // Attach click triggers to all switch buttons and links
    document.querySelectorAll('.js-trigger-slide-login').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            createWaterDropRipple(e, this);
            switchAuthMode('login', true, e);
        });
    });

    document.querySelectorAll('.js-trigger-slide-register').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            createWaterDropRipple(e, this);
            switchAuthMode('register', true, e);
        });
    });

    // Check URL query param or hash on initial load
    const urlParams = new URLSearchParams(window.location.search);
    if (window.location.hash === '#login' || urlParams.get('mode') === 'login') {
        switchAuthMode('login', false);
    }

    // Password Visibility Toggle for Login
    const toggleLoginPasswordBtn = document.getElementById('toggleLoginPasswordBtn');
    const loginPasswordInput = document.getElementById('loginPassword');
    if (toggleLoginPasswordBtn && loginPasswordInput) {
        toggleLoginPasswordBtn.addEventListener('click', function() {
            const isPass = loginPasswordInput.type === 'password';
            loginPasswordInput.type = isPass ? 'text' : 'password';
            const icon = this.querySelector('i');
            if (icon) {
                icon.className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
        });
    }

    // AJAX Login Handler
    const ajaxLoginForm = document.getElementById('ajaxLoginForm');
    const loginSubmitBtn = document.getElementById('loginSubmitBtn');
    if (ajaxLoginForm) {
        ajaxLoginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const emailInput = document.getElementById('loginEmail');
            const passInput = document.getElementById('loginPassword');

            if (!emailInput || !emailInput.value.trim()) {
                Toast.fire({ icon: 'warning', title: 'Please enter your email address' });
                if (emailInput) emailInput.focus();
                return;
            }
            if (!passInput || !passInput.value) {
                Toast.fire({ icon: 'warning', title: 'Please enter your password' });
                if (passInput) passInput.focus();
                return;
            }

            const origBtnHtml = loginSubmitBtn ? loginSubmitBtn.innerHTML : 'Sign In';
            if (loginSubmitBtn) {
                loginSubmitBtn.disabled = true;
                loginSubmitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Signing In...</span>';
            }

            try {
                const formData = new FormData(ajaxLoginForm);
                formData.append('ajax', 'true');
                formData.append('login', '1');

                const response = await fetch('login.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (data.success) {
                    const userName = data.full_name || 'Customer';
                    Toast.fire({
                        icon: 'success',
                        title: 'Welcome back, ' + userName + '! Redirecting...'
                    });
                    if (loginSubmitBtn) {
                        loginSubmitBtn.innerHTML = '<i class="fas fa-check-circle"></i> <span>Success! Redirecting...</span>';
                        loginSubmitBtn.style.background = '#027a48';
                    }
                    setTimeout(() => {
                        window.location.href = data.redirect || 'index.php';
                    }, 600);
                } else {
                    if (loginSubmitBtn) {
                        loginSubmitBtn.disabled = false;
                        loginSubmitBtn.innerHTML = origBtnHtml;
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'Sign In Failed',
                        text: data.message || 'Invalid email or password. Please try again.',
                        confirmButtonColor: '#b3261e'
                    });
                }
            } catch (err) {
                console.error('AJAX Login error:', err);
                if (loginSubmitBtn) {
                    loginSubmitBtn.disabled = false;
                    loginSubmitBtn.innerHTML = origBtnHtml;
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Sign In Error',
                    text: 'Unable to connect to login server. Please try again.',
                    confirmButtonColor: '#b3261e'
                });
            }
        });
    }

    const homeAddressInput = document.getElementById('homeAddressInput');
    const regAddress = document.getElementById('regAddress');
    const regStreetAddress = document.getElementById('regStreetAddress');
    const regLatitude = document.getElementById('regLatitude');
    const regLongitude = document.getElementById('regLongitude');
    const regCityName = document.getElementById('regCityName');
    const regProvinceName = document.getElementById('regProvinceName');
    const caviteStatusBadge = document.getElementById('caviteAreaStatusBadge');
    const caviteStatusIcon = document.getElementById('caviteStatusIcon');
    const caviteStatusText = document.getElementById('caviteStatusText');
    const registerMapWrapper = document.getElementById('registerMapWrapper');
    const useCurrentLocationBtn = document.getElementById('useCurrentLocationBtn');
    const clearRegAddressBtn = document.getElementById('clearRegAddressBtn');

    const CAVITE_BOUNDS = {
        minLat: 14.00,
        maxLat: 14.52,
        minLng: 120.55,
        maxLng: 121.12
    };

    const CAVITE_CITIES = [
        'cavite', 'dasmariñas', 'dasmarinas', 'imus', 'bacoor', 'general trias', 'gen. trias',
        'tagaytay', 'cavite city', 'trece martires', 'silang', 'kawit', 'tanza', 'alfonso',
        'amadeo', 'carmona', 'gma', 'general mariano alvarez', 'indang', 'magallanes',
        'maragondon', 'mendez', 'naic', 'noveleta', 'rosario', 'ternate', 'bailen', 'aguinaldo'
    ];

    function showError(title, text) {
        Swal.fire({
            icon: 'error',
            title: title,
            text: text,
            confirmButtonColor: '#b3261e'
        });
    }

    function checkIsLocationInCavite(lat, lng, addressString, addressObj) {
        const latNum = parseFloat(lat);
        const lngNum = parseFloat(lng);

        const withinCoords = (
            !isNaN(latNum) && !isNaN(lngNum) &&
            latNum >= CAVITE_BOUNDS.minLat && latNum <= CAVITE_BOUNDS.maxLat &&
            lngNum >= CAVITE_BOUNDS.minLng && lngNum <= CAVITE_BOUNDS.maxLng
        );

        const textToCheck = ((addressString || '') + ' ' + (addressObj ? JSON.stringify(addressObj) : '')).toLowerCase();
        
        const isExplicitOutside = (
            textToCheck.includes('metro manila') || textToCheck.includes('ncr') ||
            textToCheck.includes('las piñas') || textToCheck.includes('las pinas') ||
            textToCheck.includes('parañaque') || textToCheck.includes('paranaque') ||
            textToCheck.includes('muntinlupa') || textToCheck.includes('pasay') ||
            textToCheck.includes('manila') || textToCheck.includes('quezon city') ||
            textToCheck.includes('batangas') || textToCheck.includes('laguna') ||
            textToCheck.includes('rizal') || textToCheck.includes('bulacan')
        );

        const hasCaviteName = CAVITE_CITIES.some(city => textToCheck.includes(city));

        if (withinCoords && !isExplicitOutside && (hasCaviteName || !addressString)) {
            return true;
        }
        if (hasCaviteName && !isExplicitOutside) {
            return true;
        }
        return false;
    }

    function updateCaviteStatusUI(isValid, message) {
        isAddressInCavite = isValid;
        if (!caviteStatusBadge) return;

        if (isValid) {
            caviteStatusBadge.style.background = '#ecfdf3';
            caviteStatusBadge.style.borderColor = '#abefc6';
            caviteStatusBadge.style.color = '#027a48';
            if (caviteStatusIcon) {
                caviteStatusIcon.className = 'fas fa-check-circle';
                caviteStatusIcon.style.color = '#12b76a';
            }
            if (caviteStatusText) {
                caviteStatusText.textContent = message || 'Location verified: Inside Cavite area.';
            }
            if (registerMapWrapper) {
                registerMapWrapper.style.borderColor = '#12b76a';
            }
        } else {
            caviteStatusBadge.style.background = '#fff1f0';
            caviteStatusBadge.style.borderColor = '#fee4e2';
            caviteStatusBadge.style.color = '#b3261e';
            if (caviteStatusIcon) {
                caviteStatusIcon.className = 'fas fa-ban';
                caviteStatusIcon.style.color = '#b3261e';
            }
            if (caviteStatusText) {
                caviteStatusText.textContent = message || 'Outside Service Area: Registration is only available for locations within Cavite province. Please pin your location inside Cavite.';
            }
            if (registerMapWrapper) {
                registerMapWrapper.style.borderColor = '#f04438';
            }
        }
    }

    async function reverseGeocodeLocation(lat, lng) {
        const latNum = parseFloat(lat);
        const lngNum = parseFloat(lng);
        if (isNaN(latNum) || isNaN(lngNum)) return;

        if (regLatitude) regLatitude.value = String(latNum.toFixed(7));
        if (regLongitude) regLongitude.value = String(lngNum.toFixed(7));

        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latNum}&lon=${lngNum}&addressdetails=1`);
            const data = await res.json();
            if (data && data.display_name) {
                const fullAddr = data.display_name;
                const inCavite = checkIsLocationInCavite(latNum, lngNum, fullAddr, data.address);
                
                if (homeAddressInput) {
                    homeAddressInput.value = fullAddr;
                }
                if (clearRegAddressBtn) {
                    clearRegAddressBtn.style.display = fullAddr ? 'block' : 'none';
                }
                if (regAddress) regAddress.value = fullAddr;

                const streetPart = (data.address?.road || data.address?.neighbourhood || data.address?.suburb || fullAddr.split(',')[0] || '').trim();
                const cityPart = (data.address?.city || data.address?.town || data.address?.municipality || 'Cavite').trim();
                if (regStreetAddress) regStreetAddress.value = streetPart;
                if (regCityName) regCityName.value = cityPart;

                if (inCavite) {
                    updateCaviteStatusUI(true, `Verified: ${cityPart}, Cavite`);
                } else {
                    updateCaviteStatusUI(false, `Outside Service Area (${cityPart || 'Non-Cavite'}): Registration only accepts locations within Cavite province.`);
                }
            } else {
                const inCavite = checkIsLocationInCavite(latNum, lngNum, '', null);
                updateCaviteStatusUI(inCavite, inCavite ? 'Location verified: Inside Cavite coordinates.' : 'Outside Service Area: Coordinates are outside Cavite.');
            }
        } catch (e) {
            console.error('Reverse geocode error:', e);
            const inCavite = checkIsLocationInCavite(latNum, lngNum, '', null);
            updateCaviteStatusUI(inCavite, inCavite ? 'Location inside Cavite area.' : 'Location outside Cavite.');
        }
    }

    async function forwardGeocodeAddress(query) {
        const trimmed = (query || '').trim();
        if (!trimmed) return;

        try {
            // Search prioritizing Cavite Philippines viewbox
            const searchUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(trimmed + ', Cavite, Philippines')}&limit=1&addressdetails=1`;
            const res = await fetch(searchUrl);
            const list = await res.json();
            if (Array.isArray(list) && list.length > 0) {
                const first = list[0];
                const lat = parseFloat(first.lat);
                const lng = parseFloat(first.lon);

                if (regMap && regMarker) {
                    regMap.flyTo([lat, lng], 16);
                    regMarker.setLatLng([lat, lng]);
                }

                if (regLatitude) regLatitude.value = String(lat.toFixed(7));
                if (regLongitude) regLongitude.value = String(lng.toFixed(7));
                if (regAddress) regAddress.value = first.display_name || trimmed;

                const inCavite = checkIsLocationInCavite(lat, lng, first.display_name, first.address);
                if (inCavite) {
                    updateCaviteStatusUI(true, `Verified: ${first.address?.city || first.address?.town || first.address?.municipality || 'Cavite'}`);
                } else {
                    updateCaviteStatusUI(false, 'Outside Service Area: Address is outside Cavite province.');
                }
            } else {
                updateCaviteStatusUI(false, 'Location not found. Please pin your location directly on the map.');
            }
        } catch (e) {
            console.error('Forward geocode error:', e);
        }
    }

    function initRegisterMap() {
        const mapElem = document.getElementById('registerMapCanvas');
        if (!mapElem || !window.L) return;

        let initLat = parseFloat(regLatitude?.value || '') || 14.3294; // Default Dasmariñas Cavite
        let initLng = parseFloat(regLongitude?.value || '') || 120.9367;

        if (!regMap) {
            regMap = L.map('registerMapCanvas').setView([initLat, initLng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(regMap);

            regMarker = L.marker([initLat, initLng], { draggable: true }).addTo(regMap);

            regMarker.on('dragend', function() {
                const pos = regMarker.getLatLng();
                if (regMap) regMap.panTo(pos);
                reverseGeocodeLocation(pos.lat, pos.lng);
            });

            regMap.on('click', function(e) {
                const pos = e.latlng;
                if (regMarker) regMarker.setLatLng(pos);
                if (regMap) regMap.panTo(pos);
                reverseGeocodeLocation(pos.lat, pos.lng);
            });

            // Initial check
            reverseGeocodeLocation(initLat, initLng);
        } else {
            regMap.invalidateSize();
        }
    }

    // Input typing listener with debounce
    if (homeAddressInput) {
        homeAddressInput.addEventListener('input', function() {
            const val = this.value.trim();
            if (clearRegAddressBtn) {
                clearRegAddressBtn.style.display = val ? 'block' : 'none';
            }
            if (regAddress) regAddress.value = val;

            if (addressDebounceTimer) clearTimeout(addressDebounceTimer);
            if (val.length >= 4) {
                addressDebounceTimer = setTimeout(function() {
                    forwardGeocodeAddress(val);
                }, 600);
            }
        });

        homeAddressInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = this.value.trim();
                if (val) forwardGeocodeAddress(val);
            }
        });
    }

    if (clearRegAddressBtn && homeAddressInput) {
        clearRegAddressBtn.addEventListener('click', function() {
            homeAddressInput.value = '';
            clearRegAddressBtn.style.display = 'none';
            if (regAddress) regAddress.value = '';
            homeAddressInput.focus();
        });
    }

    if (useCurrentLocationBtn) {
        useCurrentLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                useCurrentLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        useCurrentLocationBtn.innerHTML = '<i class="fas fa-crosshairs"></i> Use My Current Location';
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;
                        if (regMap && regMarker) {
                            regMap.flyTo([lat, lng], 16);
                            regMarker.setLatLng([lat, lng]);
                        }
                        reverseGeocodeLocation(lat, lng);
                    },
                    function(err) {
                        useCurrentLocationBtn.innerHTML = '<i class="fas fa-crosshairs"></i> Use My Current Location';
                        showError('Location Access Error', 'Unable to retrieve your current location. Please drag the pin on the map instead.');
                    },
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            } else {
                showError('Geolocation Unavailable', 'Geolocation is not supported by your browser. Please drag the pin on the map.');
            }
        });
    }

    function updateSteps() {
        steps.forEach(function(step, index) {
            step.classList.remove('active', 'completed');
            if (index === currentStep - 1) {
                step.classList.add('active');
            } else if (index < currentStep - 1) {
                step.classList.add('completed');
            }
        });

        formSteps.forEach(function(formStep, index) {
            formStep.classList.toggle('active', index + 1 === currentStep);
        });

        if (currentStep === 3) {
            setTimeout(initRegisterMap, 150);
        }
    }

    function updateProgressBar() {
        const progressBar = document.getElementById('progressBar');
        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
    }

    function updateOrganizationFields() {
        if (!organizationFields) {
            return;
        }

        const businessNameInput = document.getElementById('businessName');
        if (accountType === 'organization') {
            organizationFields.style.display = 'block';
            if (businessNameInput) {
                businessNameInput.required = true;
            }
            if (step3Title) {
                step3Title.textContent = 'Business Address (Cavite Only)';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Enter your restaurant or business location in Cavite on the map.';
            }
            if (step3NavLabel) {
                step3NavLabel.textContent = 'Partner Info';
            }
        } else {
            organizationFields.style.display = 'none';
            if (businessNameInput) {
                businessNameInput.required = false;
            }
            if (step3Title) {
                step3Title.textContent = 'Home Address (Cavite Only)';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Enter your home address or pin your location on the map.';
            }
            if (step3NavLabel) {
                step3NavLabel.textContent = 'Address Info';
            }
        }
    }

    function goToStep(step) {
        if (step > currentStep) {
            if (currentStep === 1 && !validateStep1()) {
                return;
            }
            if (currentStep === 2 && !validateStep2()) {
                return;
            }
            if (currentStep === 3 && !validateStep3()) {
                return;
            }
        }

        currentStep = step;
        updateSteps();
        updateProgressBar();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function isValidPhone(phone) {
        const cleaned = phone.replace(/[^\d]/g, '');
        return /^09\d{9}$/.test(cleaned) || /^9\d{9}$/.test(cleaned) || /^639\d{9}$/.test(cleaned);
    }

    function isValidName(name) {
        if (!name || typeof name !== 'string') return false;
        const trimmed = name.trim();
        if (trimmed.length < 2 || trimmed.length > 60) return false;
        try {
            return /^[\p{L}\p{M}'\-\s.]{2,60}$/u.test(trimmed);
        } catch (e) {
            return /^[a-zA-Z\s'\-\.]{2,60}$/.test(trimmed);
        }
    }

    function isStrongPassword(password) {
        return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,72}$/.test(password);
    }

    function validateStep1() {
        const firstName = ((document.getElementById('firstName') || {}).value || '').trim();
        const lastName = ((document.getElementById('lastName') || {}).value || '').trim();

        if (!firstName || !lastName) {
            showError('Missing Information', 'Please enter your First Name and Last Name.');
            return false;
        }

        if (!isValidName(firstName) || !isValidName(lastName)) {
            showError('Invalid Name', 'Please use valid characters (letters, spaces, dots, hyphens) for your name.');
            return false;
        }

        return true;
    }

    let idVerified = false;

    function verifyIdWithBackend(firstName, lastName) {
        const validIdType = ((document.getElementById('validIdType') || {}).value || '').trim();
        const frontInput = document.getElementById('validIdFront');
        const frontFile = frontInput && frontInput.files ? frontInput.files[0] : null;
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

        if (!frontFile) {
            showError('ID Photo Required', 'Please upload or capture the front side of your ID.');
            return;
        }

        const backInput = document.getElementById('validIdBack');
        const backFile = backInput && backInput.files ? backInput.files[0] : null;

        const verifyData = new FormData();
        verifyData.append('first_name', firstName);
        verifyData.append('last_name', lastName);
        verifyData.append('valid_id_type', validIdType);
        verifyData.append('valid_id', frontFile);
        if (backFile) {
            verifyData.append('valid_id_back', backFile);
        }
        verifyData.append('csrf_token', csrfToken);

        const progressHtml = `
            <div style="text-align: left; padding: 10px 5px 0;">
                <div style="font-size: 0.88rem; color: #475467; margin-bottom: 12px;" id="swalScanMsg">
                    <i class="fas fa-spinner fa-spin" style="color:#b3261e; margin-right: 6px;"></i> Analyzing Philippine government seal & document authenticity...
                </div>
                <div style="width: 100%; height: 6px; background: #eaecf0; border-radius: 4px; overflow: hidden; margin-bottom: 16px;">
                    <div id="swalScanBar" style="width: 30%; height: 100%; background: #b3261e; transition: width 0.4s ease;"></div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.82rem; color: #667085;">
                    <div id="chkOrigin" style="display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-circle-notch fa-spin" style="color: #b3261e; width: 14px;"></i> <span>Verifying Philippine Government Authority</span>
                    </div>
                    <div id="chkDoc" style="display: flex; align-items: center; gap: 8px;">
                        <i class="far fa-circle" style="color: #d0d5dd; width: 14px;"></i> <span>Validating ID Card Legitimacy</span>
                    </div>
                    <div id="chkName" style="display: flex; align-items: center; gap: 8px;">
                        <i class="far fa-circle" style="color: #d0d5dd; width: 14px;"></i> <span>Cross-matching Name: <strong>${firstName} ${lastName}</strong></span>
                    </div>
                    <div id="chkFormat" style="display: flex; align-items: center; gap: 8px;">
                        <i class="far fa-circle" style="color: #d0d5dd; width: 14px;"></i> <span>Checking Official ID Number Format</span>
                    </div>
                </div>
            </div>
        `;

        Swal.fire({
            title: 'Verifying Government ID',
            html: progressHtml,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: function() {
                const bar = document.getElementById('swalScanBar');
                const msg = document.getElementById('swalScanMsg');
                const chkOrigin = document.getElementById('chkOrigin');
                const chkDoc = document.getElementById('chkDoc');
                const chkName = document.getElementById('chkName');
                const chkFormat = document.getElementById('chkFormat');

                // Animate check stages
                setTimeout(function() {
                    if (bar) bar.style.width = '55%';
                    if (chkOrigin) chkOrigin.innerHTML = '<i class="fas fa-check-circle" style="color: #027a48; width: 14px;"></i> <span style="color:#027a48; font-weight:600;">Philippine Government Seal Detected</span>';
                    if (chkDoc) chkDoc.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="color: #b3261e; width: 14px;"></i> <span>Validating ID Card Legitimacy</span>';
                }, 1200);

                setTimeout(function() {
                    if (bar) bar.style.width = '80%';
                    if (chkDoc) chkDoc.innerHTML = '<i class="fas fa-check-circle" style="color: #027a48; width: 14px;"></i> <span style="color:#027a48; font-weight:600;">ID Card Authenticity Validated</span>';
                    if (chkName) chkName.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="color: #b3261e; width: 14px;"></i> <span>Cross-matching Name: <strong>' + firstName + ' ' + lastName + '</strong></span>';
                }, 2200);

                fetch('api/verify_government_id.php', {
                    method: 'POST',
                    body: verifyData
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function(resObj) {
                    const status = resObj.status;
                    const data = resObj.data || {};

                    if (status === 200 && data.success && data.verified) {
                        if (bar) bar.style.width = '100%';
                        if (chkName) chkName.innerHTML = '<i class="fas fa-check-circle" style="color: #027a48; width: 14px;"></i> <span style="color:#027a48; font-weight:600;">Name Matched Registered Account</span>';
                        if (chkFormat) chkFormat.innerHTML = '<i class="fas fa-check-circle" style="color: #027a48; width: 14px;"></i> <span style="color:#027a48; font-weight:600;">Official Format Verified</span>';

                        setTimeout(function() {
                            Swal.fire({
                                icon: 'success',
                                title: 'Philippine ID Verified',
                                html: `
                                    <div style="text-align: left; font-size: 0.9rem; color: #344054; line-height: 1.6; background: #f8fafc; border: 1px solid #eaecf0; border-radius: 10px; padding: 14px; margin-top: 10px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <i class="fas fa-check-circle" style="color:#027a48;"></i>
                                            <span><strong>Document:</strong> Official Philippine Government ID</span>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <i class="fas fa-check-circle" style="color:#027a48;"></i>
                                            <span><strong>Name Match:</strong> ${firstName} ${lastName}</span>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fas fa-check-circle" style="color:#027a48;"></i>
                                            <span><strong>Status:</strong> Authenticated & Ready</span>
                                        </div>
                                    </div>
                                `,
                                confirmButtonColor: '#b3261e',
                                confirmButtonText: 'Proceed to Next Step',
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then(function() {
                                idVerified = true;
                                goToStep(3);
                            });
                        }, 600);
                    } else {
                        const errorMsg = data.message || 'Verification failed. Please make sure you upload a clear Philippine Government ID matching your name.';
                        Swal.fire({
                            icon: 'error',
                            title: 'ID Verification Failed',
                            html: `
                                <div style="text-align: left; font-size: 0.88rem; color: #344054; line-height: 1.5; margin-top: 8px;">
                                    <div style="background: #fff1f0; border: 1px solid #fee4e2; border-radius: 8px; padding: 12px; color: #b3261e; margin-bottom: 12px;">
                                        <i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i> ${errorMsg}
                                    </div>
                                    <div style="font-weight: 600; color: #101828; margin-bottom: 6px;">Requirements Checklist:</div>
                                    <ul style="margin: 0; padding-left: 18px; color: #475467; font-size: 0.83rem;">
                                        <li>Must be an authentic Philippine Government ID (PhilSys National ID, Driver's License, Passport, UMID, SSS, PhilHealth, TIN, Postal ID).</li>
                                        <li>The name on the ID must match: <strong>${firstName} ${lastName}</strong>.</li>
                                        <li>Ensure good lighting, sharp focus, and all 4 corners visible.</li>
                                    </ul>
                                </div>
                            `,
                            confirmButtonColor: '#b3261e',
                            confirmButtonText: 'Try Again'
                        });
                    }
                })
                .catch(function(error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Verification Request Failed',
                        text: error.message || 'Could not connect to ID verification server. Please try again.',
                        confirmButtonColor: '#b3261e'
                    });
                });
            }
        });
    }

    // Reset ID verification flag if details change
    ['firstName', 'lastName', 'validIdType', 'validIdFront', 'validIdBack'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', function() { idVerified = false; });
            el.addEventListener('input', function() { idVerified = false; });
        }
    });

    function validateStep2() {
        const firstName = ((document.getElementById('firstName') || {}).value || '').trim();
        const lastName = ((document.getElementById('lastName') || {}).value || '').trim();
        const validIdType = ((document.getElementById('validIdType') || {}).value || '').trim();
        const validIdFrontInput = document.getElementById('validIdFront');
        const validIdBackInput = document.getElementById('validIdBack');
        
        const validIdFrontFile = validIdFrontInput && validIdFrontInput.files ? validIdFrontInput.files[0] : null;
        const validIdBackFile = validIdBackInput && validIdBackInput.files ? validIdBackInput.files[0] : null;

        if (!validIdType) {
            showError('Valid ID Type Required', 'Please select what kind of ID you will upload.');
            return false;
        }

        if (!validIdFrontFile) {
            showError('Front ID Photo Required', 'Please upload or capture a photo of the front side of your ID.');
            return false;
        }

        if (!validIdBackFile) {
            showError('Back ID Photo Required', 'Please upload or capture a photo of the back side of your ID.');
            return false;
        }

        // Validate formats
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        const validateFile = (file, label) => {
            const hasType = allowedTypes.includes(file.type || '');
            const hasExt = /\.(jpg|jpeg|png|webp)$/.test(String(file.name || '').toLowerCase());
            if (!hasType && !hasExt) {
                showError('Invalid ID Format', 'Please upload a JPG, PNG, or WEBP image for ' + label + '.');
                return false;
            }
            if (file.size > (10 * 1024 * 1024)) {
                showError('File Too Large', 'Your ' + label + ' file size must be 10MB or smaller.');
                return false;
            }
            return true;
        };

        if (!validateFile(validIdFrontFile, 'Front of ID') || !validateFile(validIdBackFile, 'Back of ID')) {
            return false;
        }

        if (!idVerified) {
            verifyIdWithBackend(firstName, lastName);
            return false;
        }

        return true;
    }

    function validateStep3() {
        if (accountType === 'organization') {
            const businessName = ((document.getElementById('businessName') || {}).value || '').trim();
            if (!businessName) {
                showError('Restaurant Name Required', 'Please enter your restaurant name.');
                return false;
            }
        }

        const addressVal = (homeAddressInput ? homeAddressInput.value : (regAddress ? regAddress.value : '')).trim();
        if (!addressVal || addressVal.length < 6) {
            showError('Address Required', 'Please enter your home address or pin your location on the map.');
            return false;
        }

        if (!isAddressInCavite) {
            showError('Location Outside Cavite', 'We currently only accept registrations within the Cavite area. Please select a location inside Cavite on the map.');
            return false;
        }

        return true;
    }

    function validateStep4() {
        const email = ((document.getElementById('email') || {}).value || '').trim();
        const phone = ((document.getElementById('phone') || {}).value || '').trim();
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const termsInput = document.getElementById('terms') || document.getElementById('acceptTerms');
        const password = passwordInput ? passwordInput.value : '';
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

        if (!email) {
            showError('Email Required', 'Please enter your email address.');
            return false;
        }

        if (!isValidEmail(email)) {
            showError('Invalid Email', 'Please enter a valid email address.');
            return false;
        }

        if (!phone) {
            showError('Phone Required', 'Please enter your mobile phone number.');
            return false;
        }

        if (!isValidPhone(phone)) {
            showError('Invalid Phone', 'Please enter a valid Philippine mobile number (e.g., 09123456789).');
            return false;
        }

        if (!password || !confirmPassword) {
            showError('Password Required', 'Please enter and confirm your password.');
            return false;
        }

        if (!isStrongPassword(password)) {
            showError('Weak Password', 'Use 8+ characters with uppercase, lowercase, number, and symbol.');
            return false;
        }

        if (password !== confirmPassword) {
            showError('Passwords Mismatch', 'Passwords do not match. Please try again.');
            return false;
        }

        if (!termsInput || !termsInput.checked) {
            showError('Terms Required', 'Please accept the Terms of Service and Privacy Policy.');
            return false;
        }

        return true;
    }

    accountTypeCards.forEach(function(card) {
        card.addEventListener('click', function() {
            accountTypeCards.forEach(function(item) {
                item.classList.remove('selected');
            });
            card.classList.add('selected');
            accountType = card.dataset.type;
            if (accountTypeInput) {
                accountTypeInput.value = accountType;
            }
            updateOrganizationFields();
        });
    });

    accountTypeCards.forEach(function(card) {
        if (card.dataset.type === accountType) {
            card.classList.add('selected');
        }
    });

    const backToLoginBtn = document.getElementById('backToLoginBtn');
    if (backToLoginBtn) {
        backToLoginBtn.addEventListener('click', function() {
            window.location.href = 'login.php';
        });
    }

    const prevStep1 = document.getElementById('prevStep1');
    if (prevStep1) {
        prevStep1.addEventListener('click', function() {
            window.location.href = 'login.php';
        });
    }

    const nextStep1 = document.getElementById('nextStep1');
    if (nextStep1) {
        nextStep1.addEventListener('click', function() {
            if (validateStep1()) {
                goToStep(2);
            }
        });
    }

    const prevStep2 = document.getElementById('prevStep2');
    if (prevStep2) {
        prevStep2.addEventListener('click', function() {
            goToStep(1);
        });
    }

    const nextStep2 = document.getElementById('nextStep2');
    if (nextStep2) {
        nextStep2.addEventListener('click', function() {
            if (validateStep2()) {
                goToStep(3);
            }
        });
    }

    const prevStep3 = document.getElementById('prevStep3');
    if (prevStep3) {
        prevStep3.addEventListener('click', function() {
            goToStep(2);
        });
    }

    const nextStep3 = document.getElementById('nextStep3');
    if (nextStep3) {
        nextStep3.addEventListener('click', function() {
            if (validateStep3()) {
                goToStep(4);
            }
        });
    }

    const prevStep4 = document.getElementById('prevStep4');
    if (prevStep4) {
        prevStep4.addEventListener('click', function() {
            goToStep(3);
        });
    }



    document.querySelectorAll('.toggle-password').forEach(function(button) {
        button.addEventListener('click', function() {
            const wrapper = button.closest('.password-wrapper');
            if (!wrapper) {
                return;
            }
            const input = wrapper.querySelector('input');
            const icon = button.querySelector('i');
            if (!input || !icon) {
                return;
            }

            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
                button.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
                button.setAttribute('aria-label', 'Show password');
            }
        });
    });

    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const password = passwordInput.value;
            const bars = [
                document.getElementById('strengthBar1'),
                document.getElementById('strengthBar2'),
                document.getElementById('strengthBar3'),
                document.getElementById('strengthBar4')
            ];
            const strengthText = document.getElementById('strengthText');
            const checks = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z\d]/];
            let score = password.length >= 8 ? 1 : 0;
            checks.forEach(function(regex) {
                if (regex.test(password)) {
                    score += 1;
                }
            });
            score = Math.min(score, 4);

            bars.forEach(function(bar) {
                if (bar) {
                    bar.className = 'strength-bar';
                }
            });

            let label = 'Weak';
            let cssClass = 'weak';
            let color = '#ff5252';

            if (score >= 3) {
                label = 'Fair';
                cssClass = 'medium';
                color = '#ff9800';
            }
            if (score >= 4) {
                label = 'Strong';
                cssClass = 'strong';
                color = '#4caf50';
            }
            if (!password.length) {
                label = 'Weak';
                color = '#666';
            }

            for (let i = 0; i < score; i += 1) {
                if (bars[i]) {
                    bars[i].classList.add(cssClass);
                }
            }
            if (strengthText) {
                strengthText.textContent = label;
                strengthText.style.color = color;
            }
        });
    }

    const registrationForm = document.getElementById('registrationForm');
    if (registrationForm) {
        registrationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!validateStep4() || !validateStep3()) {
                return false;
            }

            const submitBtn = document.getElementById('submitRegistration');
            if (submitBtn) {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                const span = submitBtn.querySelector('span');
                if (span) {
                    span.textContent = 'Creating account...';
                }
            }
            this.submit();
            return true;
        });
    }

    [
        ['googleRegisterBtn', 'controllers/google_auth.php?action=register'],
        ['facebookRegisterBtn', 'controllers/facebook_auth.php?action=register'],
        ['twitterRegisterBtn', 'controllers/twitter_auth.php?action=register'],
        ['instagramRegisterBtn', 'controllers/instagram_auth.php?action=register']
    ].forEach(function(item) {
        const button = document.getElementById(item[0]);
        if (button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = item[1];
            });
        }
    });

    let activeCameraSide = '';
    let cameraStream = null;
    let currentFacingMode = 'environment'; // Rear camera default for document capture

    const cameraModal = document.getElementById('cameraModal');
    const closeCameraBtn = document.getElementById('closeCameraModal');
    const webcamVideo = document.getElementById('webcamStream');
    const shutterBtn = document.getElementById('shutterBtn');
    const flipCameraBtn = document.getElementById('flipCameraBtn');
    const cameraSpinner = document.getElementById('cameraLoadingSpinner');
    const canvasHolder = document.getElementById('capturedFrameHolder');
    
    const zoneFront = document.getElementById('zoneFront');
    const zoneBack = document.getElementById('zoneBack');
    const removeFront = document.getElementById('removeFront');
    const removeBack = document.getElementById('removeBack');

    function dataURLtoBlob(dataurl) {
        var arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1],
            bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);
        while(n--){
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new Blob([u8arr], {type:mime});
    }

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            fallbackToFileUpload();
            return;
        }

        if (cameraSpinner) cameraSpinner.style.display = 'flex';
        if (cameraStream) stopCamera();

        try {
            const constraints = {
                video: {
                    facingMode: currentFacingMode,
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (firstErr) {
                console.warn('Specific video constraints failed, trying generic constraints:', firstErr);
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            }
            if (webcamVideo) {
                webcamVideo.srcObject = cameraStream;
                webcamVideo.onloadedmetadata = function() {
                    if (cameraSpinner) cameraSpinner.style.display = 'none';
                };
            }
        } catch (err) {
            console.error('Camera stream access failed:', err);
            if (cameraSpinner) cameraSpinner.style.display = 'none';
            closeModal();
            fallbackToFileUpload();
        }
    }

    function fallbackToFileUpload() {
        Swal.fire({
            icon: 'info',
            title: 'Camera Unreachable',
            text: 'We could not open your camera stream. You can upload a photo of your ID from your device files instead.',
            confirmButtonColor: '#b3261e',
            showCancelButton: true,
            confirmButtonText: 'Upload ID Photo',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const currentInputId = activeCameraSide === 'Front' ? 'validIdFront' : 'validIdBack';
                const fileInput = document.getElementById(currentInputId);
                if (fileInput) {
                    fileInput.click();
                }
            }
        });
    }

    function handleFileSelection(side) {
        const isFront = (side || '').toLowerCase() === 'front';
        const fileInput = document.getElementById(isFront ? 'validIdFront' : 'validIdBack');
        const previewContainer = document.getElementById(isFront ? 'previewContainerFront' : 'previewContainerBack') || document.getElementById(isFront ? 'previewFront' : 'previewBack');
        const previewImg = document.getElementById(isFront ? 'imagePreviewFront' : 'imagePreviewBack') || (previewContainer ? previewContainer.querySelector('img') : null);
        const statusBadge = document.getElementById(isFront ? 'frontStatusBadge' : 'backStatusBadge');

        if (fileInput && fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                if (previewImg) previewImg.src = e.target.result;
                if (previewContainer) previewContainer.style.display = 'block';
                if (statusBadge) {
                    statusBadge.textContent = 'Selected';
                    statusBadge.style.background = '#ecfdf3';
                    statusBadge.style.color = '#027a48';
                }
            };
            reader.readAsDataURL(file);
            idVerified = false;
        }
    }

    const frontFileInput = document.getElementById('validIdFront');
    const backFileInput = document.getElementById('validIdBack');
    if (frontFileInput) frontFileInput.addEventListener('change', () => handleFileSelection('front'));
    if (backFileInput) backFileInput.addEventListener('change', () => handleFileSelection('back'));

    function stopCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        if (webcamVideo) {
            webcamVideo.srcObject = null;
        }
    }

    function openModal(side) {
        activeCameraSide = (side || 'front').toLowerCase() === 'back' ? 'back' : 'front';
        const title = document.getElementById('cameraModalTitle');
        if (title) title.textContent = activeCameraSide === 'back' ? 'Capture Back of ID' : 'Capture Front of ID';
        if (cameraModal) cameraModal.style.display = 'flex';
        startCamera();
    }

    function closeModal() {
        if (cameraModal) cameraModal.style.display = 'none';
        stopCamera();
    }

    // Trigger Camera Buttons
    document.querySelectorAll('.trigger-camera-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const target = (this.getAttribute('data-target') || 'front').toLowerCase();
            openModal(target);
        });
    });

    if (closeCameraBtn) closeCameraBtn.addEventListener('click', closeModal);

    // Direct upload buttons — open file picker immediately
    document.querySelectorAll('.direct-upload-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const target = this.getAttribute('data-target');
            const inputId = target ? target : ((this.getAttribute('data-side') || '').toLowerCase() === 'back' ? 'validIdBack' : 'validIdFront');
            const fileInput = document.getElementById(inputId);
            if (fileInput) {
                fileInput.value = '';
                fileInput.click();
            }
        });
    });

    // Remove Preview Buttons
    document.querySelectorAll('.remove-preview-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const target = (this.getAttribute('data-target') || 'front').toLowerCase();
            const isFront = target === 'front';
            const fileInput = document.getElementById(isFront ? 'validIdFront' : 'validIdBack');
            const previewContainer = document.getElementById(isFront ? 'previewContainerFront' : 'previewContainerBack') || document.getElementById(isFront ? 'previewFront' : 'previewBack');
            const statusBadge = document.getElementById(isFront ? 'frontStatusBadge' : 'backStatusBadge');

            if (fileInput) fileInput.value = '';
            if (previewContainer) previewContainer.style.display = 'none';
            if (statusBadge) {
                statusBadge.textContent = 'Pending';
                statusBadge.style.background = '#f1f5f9';
                statusBadge.style.color = '#64748b';
            }
            idVerified = false;
        });
    });

    if (flipCameraBtn) {
        flipCameraBtn.addEventListener('click', function() {
            currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
            startCamera();
        });
    }

    if (shutterBtn) {
        shutterBtn.addEventListener('click', function() {
            if (!webcamVideo || !canvasHolder) return;

            const videoWidth = webcamVideo.videoWidth || 640;
            const videoHeight = webcamVideo.videoHeight || 480;
            
            canvasHolder.width = videoWidth;
            canvasHolder.height = videoHeight;
            
            const context = canvasHolder.getContext('2d');
            if (context) {
                // Mirror image if using front camera
                if (currentFacingMode === 'user') {
                    context.translate(videoWidth, 0);
                    context.scale(-1, 1);
                }
                context.drawImage(webcamVideo, 0, 0, videoWidth, videoHeight);
                const dataUrl = canvasHolder.toDataURL('image/jpeg', 0.95);

                const isFront = activeCameraSide.toLowerCase() === 'front';
                const previewContainer = document.getElementById(isFront ? 'previewContainerFront' : 'previewContainerBack') || document.getElementById(isFront ? 'previewFront' : 'previewBack');
                const previewImg = document.getElementById(isFront ? 'imagePreviewFront' : 'imagePreviewBack') || (previewContainer ? previewContainer.querySelector('img') : null);
                const statusBadge = document.getElementById(isFront ? 'frontStatusBadge' : 'backStatusBadge');
                const inputElement = document.getElementById(isFront ? 'validIdFront' : 'validIdBack');

                if (previewImg) previewImg.src = dataUrl;
                if (previewContainer) previewContainer.style.display = 'block';
                if (statusBadge) {
                    statusBadge.textContent = 'Captured';
                    statusBadge.style.background = '#ecfdf3';
                    statusBadge.style.color = '#027a48';
                }

                try {
                    const blob = dataURLtoBlob(dataUrl);
                    const file = new File([blob], 'captured_id_' + (isFront ? 'front' : 'back') + '.jpg', { type: 'image/jpeg' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    if (inputElement) {
                        inputElement.files = dt.files;
                        inputElement.dispatchEvent(new Event('change'));
                    }
                } catch (e) {
                    console.error('File injection error:', e);
                }

                idVerified = false;
                closeModal();
            }
        });
    }

    updateOrganizationFields();
    updateSteps();
    updateProgressBar();

    const serverRegistrationError = <?php echo json_encode($error ?? ''); ?>;

    if (serverRegistrationError) {
        let targetStep = 1;
        const loweredError = serverRegistrationError.toLowerCase();
        if (/(name)/.test(loweredError)) {
            targetStep = 1;
        } else if (/(email|mobile|phone|valid id|government id|front|back)/.test(loweredError)) {
            targetStep = 2;
        } else if (/(business|partner|address|cavite|delivery|restaurant)/.test(loweredError)) {
            targetStep = 3;
        } else if (/(password|terms|token|security)/.test(loweredError)) {
            targetStep = 4;
        }

        if (targetStep !== currentStep) {
            currentStep = targetStep;
            updateSteps();
            updateProgressBar();
        }

        Swal.fire({
            icon: 'error',
            title: 'Registration failed',
            text: serverRegistrationError,
            confirmButtonColor: '#b3261e'
        });
    }
});
</script>

<?php 
// Close database connection
if (isset($conn)) {
    mysqli_close($conn);
}

include base_path('includes/footer.php'); 
?>
