<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/config.php';
require_once 'includes/email_verification_helper.php';
require_once 'includes/security.php';

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
    $psgc_region_code = trim($_POST['psgc_region_code'] ?? '');
    $psgc_region_name = trim($_POST['psgc_region_name'] ?? '');
    $psgc_province_code = trim($_POST['psgc_province_code'] ?? '');
    $psgc_province_name = trim($_POST['psgc_province_name'] ?? '');
    $psgc_city_code = trim($_POST['psgc_city_code'] ?? '');
    $psgc_city_name = trim($_POST['psgc_city_name'] ?? '');
    $psgc_barangay_code = trim($_POST['psgc_barangay_code'] ?? '');
    $psgc_barangay_name = trim($_POST['psgc_barangay_name'] ?? '');

    $address_parts = array_filter([
        $street_address,
        $psgc_barangay_name,
        $psgc_city_name,
        $psgc_province_name,
        $psgc_region_name
    ], static function ($part) {
        return $part !== '';
    });

    if (!empty($address_parts)) {
        $address = implode(', ', array_unique($address_parts));
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
        'psgc_region_code' => $psgc_region_code,
        'psgc_region_name' => $psgc_region_name,
        'psgc_province_code' => $psgc_province_code,
        'psgc_province_name' => $psgc_province_name,
        'psgc_city_code' => $psgc_city_code,
        'psgc_city_name' => $psgc_city_name,
        'psgc_barangay_code' => $psgc_barangay_code,
        'psgc_barangay_name' => $psgc_barangay_name
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
        } else {
            $front_validation = validateRegistrationValidIdUpload($valid_id_front);
            $back_validation = validateRegistrationValidIdUpload($valid_id_back);
            if (empty($front_validation['valid'])) {
                $error = 'Front of ID: ' . (string)($front_validation['message'] ?? 'Please upload a clear JPG, PNG, or WEBP image up to 5MB.');
            } elseif (empty($back_validation['valid'])) {
                $error = 'Back of ID: ' . (string)($back_validation['message'] ?? 'Please upload a clear JPG, PNG, or WEBP image up to 5MB.');
            }
        }

        if ($error === '' && $account_type === 'organization' && empty($business_name)) {
            $error = 'Please enter your restaurant name.';
        } elseif ($error === '' && strlen($address) < 10) {
            $error = 'Please provide a complete delivery address.';
        } elseif (
            $error === '' &&
            ($psgc_region_code !== '' || $psgc_region_name !== '' || $psgc_city_code !== '' || $psgc_city_name !== '' || $psgc_barangay_code !== '' || $psgc_barangay_name !== '') &&
            ($psgc_region_name === '' || $psgc_city_name === '' || $psgc_barangay_name === '')
        ) {
            $error = 'Please complete the PSGC address fields (region, city/municipality, and barangay).';
        } elseif ($error === '' && $account_type === 'organization') {
            $is_complete_org_psgc = (
                $psgc_region_code !== '' && $psgc_region_name !== '' &&
                $psgc_province_code !== '' && $psgc_province_name !== '' &&
                $psgc_city_code !== '' && $psgc_city_name !== '' &&
                $psgc_barangay_code !== '' && $psgc_barangay_name !== ''
            );

            if (!$is_complete_org_psgc) {
                $error = 'Please complete your PSGC business address details for Cavite (Region IV-A CALABARZON).';
            } elseif (
                ($psgc_region_code !== '040000000') ||
                (stripos($psgc_region_name, 'calabarzon') === false)
            ) {
                $error = 'Business partner registration is limited to CALABARZON (Region IV-A).';
            } elseif (
                ($psgc_province_code !== '042100000') ||
                (stripos($psgc_province_name, 'cavite') === false)
            ) {
                $error = 'Business partner registration is limited to Cavite province.';
            }
        } elseif ($error === '') {
            $is_ncr_region = (
                $psgc_region_code === '130000000' ||
                stripos($psgc_region_name, 'national capital region') !== false ||
                preg_match('/\bncr\b/i', $psgc_region_name)
            );

            $is_complete_individual_psgc = (
                $psgc_region_code !== '' && $psgc_region_name !== '' &&
                $psgc_city_code !== '' && $psgc_city_name !== '' &&
                $psgc_barangay_code !== '' && $psgc_barangay_name !== '' &&
                ($is_ncr_region || ($psgc_province_code !== '' && $psgc_province_name !== ''))
            );

            if (!$is_complete_individual_psgc) {
                $error = 'Please complete your PSGC home address details for Luzon.';
            } elseif (!isLuzonRegionSelection($psgc_region_code, $psgc_region_name)) {
                $error = 'Individual registration is limited to Luzon regions only.';
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

            $verification_notice = 'Your account was created successfully. We sent a confirmation email to ' . $email . '. Please open it and click the verification button to activate your account.';
            $verification_link = '';
            if (empty($email_verification['success'])) {
                $verification_notice = 'Your account was created successfully. We could not send the confirmation email automatically, but you can verify your address from the next screen.';
                $verification_link = (string)($email_verification['verification_url'] ?? '');
            }

            $_SESSION['register_success'] = true;
            $_SESSION['register_email'] = $email;
            $_SESSION['registration_verification_notice'] = $verification_notice;
            $_SESSION['registration_verification_link'] = $verification_link;

            header('Location: login.php');
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
include 'includes/header.php';
?>

<!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Add this for better mobile input handling -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">

<style>
.registration-page {
    min-height: calc(100vh - 200px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, #fff9f2 0%, #fff 100%);
    -webkit-tap-highlight-color: transparent; /* Remove tap highlight on mobile */
}

.registration-container {
    background-color: white;
    border-radius: 24px;
    border: 1px solid #efddcd;
    box-shadow: 0 20px 40px rgba(15,23,42,.06);
    max-width: 600px;
    width: 100%;
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
    --reg-orange: #ef6b2e;
    --reg-cream: #fff8ef;
    --reg-ink: #2a211d;
    --reg-muted: #7c6e65;
    --reg-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--reg-cream);
}

.registration-page {
    background: transparent;
}

.registration-container {
    border: 1px solid var(--reg-border);
    border-radius: 22px;
    box-shadow: 0 22px 44px rgba(74, 32, 20, 0.14);
}

.registration-header {
    background:
        linear-gradient(130deg, rgba(22, 14, 10, 0.9), rgba(65, 30, 20, 0.78)),
        url('images/about-us-bg.jpg') center/cover no-repeat;
}

.registration-body {
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.step.active .step-number {
    background: linear-gradient(135deg, var(--reg-red), var(--reg-orange));
}

.account-type-card,
.form-control,
.terms-agreement {
    border: 1px solid #ead4c1;
    background: #fffdfb;
}

.account-type-card.selected {
    border-color: #d79972;
    background: #fff5e9;
}

.account-type-card i,
.step.active .step-label,
.auth-link a {
    color: #9a3322;
}

.form-control:focus {
    border-color: #d17148;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, var(--reg-red), var(--reg-orange));
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover {
    box-shadow: 0 16px 34px rgba(179, 38, 30, 0.34);
}

.btn-secondary {
    background: white !important;
    color: var(--reg-red) !important;
    border: 2px solid var(--reg-red) !important;
}

.btn-secondary:hover {
    background: var(--reg-red) !important;
    color: white !important;
    border-color: var(--reg-red) !important;
    box-shadow: 0 8px 20px rgba(179, 38, 30, 0.2) !important;
    transform: translateY(-2px) !important;
}

.social-divider, .social-login-buttons {
    display: none !important;
}

/* Full-screen split layout styling */
.registration-page {
    background: #ffffff !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    min-height: calc(100vh - 64px) !important;
    padding: 0 !important;
}

.registration-container {
    max-width: 100% !important;
    width: 100vw;
    min-height: calc(100vh - 64px) !important;
    background-color: white;
    border-radius: 0 !important;
    border: none;
    box-shadow: none !important;
    display: flex;
    flex-direction: row; /* Image left, form right */
    margin: 0 !important;
}

.registration-image-side {
    width: 50%;
    background: #ff541c; /* Match solid orange background of the mascot */
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    min-height: calc(100vh - 64px) !important;
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

.registration-form-side {
    width: 50%;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    min-height: calc(100vh - 64px) !important;
}

.registration-form-side-container {
    max-width: 460px;
    width: 100%;
    display: flex;
    flex-direction: column;
}

@media (max-width: 850px) {
    .registration-container {
        flex-direction: column;
    }
    .registration-form-side {
        width: 100%;
        height: 100vh;
    }
    .registration-image-side {
        display: none !important;
    }
}
</style>

<div class="registration-page">
    <div class="registration-container">
        <!-- Left Side: Mascot Image Panel -->
        <!-- Left Side: Branding Panel -->
        <div class="registration-image-side" style="background: var(--reg-red, #b3261e); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px; text-align: center; color: #ffffff;">
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 3.5rem; font-weight: 900; letter-spacing: -1.5px; margin: 0; color: #ffffff; text-shadow: 0 4px 12px rgba(0,0,0,0.1);">Lechon Delights</h1>
            <p style="font-size: 1.1rem; opacity: 0.9; margin-top: 15px; max-width: 320px; font-weight: 500; line-height: 1.5;">Cavite's Finest Lechon at Your Doorsteps</p>
        </div>

        <!-- Right Side: Registration Form Panel -->
        <div class="registration-form-side">
            <div class="registration-form-side-container">
                <div class="registration-header" style="background:#fff; border-bottom:1px solid #efddcd; padding:30px 24px 20px; text-align:center;">
                    <div style="display:inline-flex; align-items:center; gap:10px; margin-bottom:12px;">
                        <span style="font-size:1.45rem; font-weight:800; color:#0f172a;">Lechon Delights</span>
                    </div>
                    <h2 style="font-size:1.35rem; font-weight:800; color:#0f172a; margin:0 0 4px 0;">Create Account</h2>
                    <p style="font-size:0.9rem; color:#64748b; margin:0;">Join us to order Cavite's finest lechon dishes.</p>
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
                        <div class="progress-container" style="width: 100%; height: 4px; background: #efddcd; border-radius: 2px; margin-top: 10px; overflow: hidden; position: relative;">
                            <div class="progress-bar" id="progressBar" style="position: absolute; left: 0; top: 0; height: 100%; width: 25%; background: #b3261e; transition: width 0.3s ease; display: block !important;"></div>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="registrationForm" data-swal-validate="off" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['registration_csrf_token']); ?>">

                        <input type="hidden" name="account_type" id="accountType" value="individual">
                        <input type="hidden" name="psgc_region_name" id="psgcRegionName" value="<?php echo htmlspecialchars($form_data['psgc_region_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_province_name" id="psgcProvinceName" value="<?php echo htmlspecialchars($form_data['psgc_province_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_city_name" id="psgcCityName" value="<?php echo htmlspecialchars($form_data['psgc_city_name'] ?? ''); ?>">
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
                            <label for="birthDate">Date of Birth</label>
                            <input type="date" id="birthDate" name="birth_date" class="form-control"
                                value="<?php echo htmlspecialchars($form_data['birth_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="" <?php echo (($form_data['gender'] ?? '') === '') ? 'selected' : ''; ?>>Prefer not to say</option>
                                <option value="male" <?php echo (($form_data['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo (($form_data['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo (($form_data['gender'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" id="prevStep1">
                            <i class="fas fa-arrow-left"></i>
                            Back to Login
                        </button>
                        <button type="button" class="btn-primary" id="nextStep1">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Contact & ID Verification -->
                <div class="form-step" id="step2Form">
                    <h2 style="color: #333; margin-bottom: 25px; font-size: 1.5rem;">Contact & Verification</h2>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" class="form-control" required 
                                placeholder="Enter your email address"
                                value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                                autocomplete="email"
                                inputmode="email">
                        </div>
                        <small style="display:block; margin-top:5px; color:#666; font-size:0.85rem;">
                            We will use this email for account notifications and sign-in.
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Mobile Number *</label>
                        <div class="input-with-icon">
                            <i class="fas fa-phone"></i>
                            <input type="tel" id="phone" name="phone" class="form-control" required 
                                placeholder="e.g., 09171234567 or +639171234567"
                                value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                                autocomplete="tel"
                                inputmode="tel">
                        </div>
                        <small style="display: block; margin-top: 5px; color: #666; font-size: 0.85rem;">
                            Enter your 11-digit mobile number (starting with 09) or with country code (+63)
                        </small>
                    </div>

                    <div class="form-group" style="margin-top:20px;">
                        <label for="validIdType">Type of Valid ID *</label>
                        <select id="validIdType" name="valid_id_type" class="form-control" required style="margin-bottom:15px;">
                            <option value="">Select ID Type</option>
                            <option value="umid">Unified Multi-Purpose ID (UMID)</option>
                            <option value="drivers_license">Driver's License</option>
                            <option value="passport">Philippine Passport</option>
                            <option value="sss">SSS ID</option>
                            <option value="gsis">GSIS ID</option>
                            <option value="prc">PRC ID</option>
                            <option value="postal">Postal ID</option>
                            <option value="voters">Voter's ID</option>
                            <option value="national_id">Philippine National ID (PhilSys)</option>
                            <option value="tin">TIN ID</option>
                            <option value="pag_ibig">Pag-IBIG ID</option>
                            <option value="philhealth">PhilHealth ID</option>
                        </select>
                    </div>

                    <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:0.9rem;">Front of ID Card *</label>
                            <div class="id-upload-zone" id="zoneFront" style="border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px 15px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #f8fafc; position: relative;">
                                <input type="file" id="validIdFront" name="valid_id_front" accept="image/*" style="display:none;" required>
                                <div class="upload-zone-content" id="zoneContentFront">
                                    <i class="fas fa-id-card" style="font-size: 2.2rem; color: #b3261e; margin-bottom: 10px; display: block;"></i>
                                    <span style="font-size: 0.85rem; font-weight:700; color: #475569; display: block; margin-bottom: 4px;">Capture Front Side</span>
                                    <span style="font-size: 0.72rem; color: #94a3b8; display: block;">Tap to open camera</span>
                                </div>
                                <div class="upload-preview" id="previewFront" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 10px; background: #fff; z-index: 2; overflow: hidden; padding: 4px;">
                                    <img src="" style="width: 100%; height: 100%; object-fit: contain;">
                                    <button type="button" class="remove-preview" id="removeFront" style="position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.6); border: none; color: #fff; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 3;"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:0.9rem;">Back of ID Card *</label>
                            <div class="id-upload-zone" id="zoneBack" style="border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px 15px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #f8fafc; position: relative;">
                                <input type="file" id="validIdBack" name="valid_id_back" accept="image/*" style="display:none;" required>
                                <div class="upload-zone-content" id="zoneContentBack">
                                    <i class="fas fa-id-card-clip" style="font-size: 2.2rem; color: #b3261e; margin-bottom: 10px; display: block;"></i>
                                    <span style="font-size: 0.85rem; font-weight:700; color: #475569; display: block; margin-bottom: 4px;">Capture Back Side</span>
                                    <span style="font-size: 0.72rem; color: #94a3b8; display: block;">Tap to open camera</span>
                                </div>
                                <div class="upload-preview" id="previewBack" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 10px; background: #fff; z-index: 2; overflow: hidden; padding: 4px;">
                                    <img src="" style="width: 100%; height: 100%; object-fit: contain;">
                                    <button type="button" class="remove-preview" id="removeBack" style="position: absolute; top: 8px; right: 8px; width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.6); border: none; color: #fff; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 3;"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" id="prevStep2">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="button" class="btn-primary" id="nextStep2">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Address + Business Partner Information -->
                <div class="form-step" id="step3Form">
                    <h2 id="step3Title" style="color: #333; margin-bottom: 10px; font-size: 1.5rem;">Add your delivery details</h2>
                    <p id="step3Subtitle" style="margin: 0 0 18px; color: #666; font-size: 0.92rem;">
                        Provide your address so we can help you get your orders delivered smoothly.
                    </p>
                    
                        <div id="organizationFields">
                            <div class="form-group">
                            <label for="businessName">Restaurant Name *</label>
                            <input type="text" id="businessName" name="business_name" class="form-control" 
                                placeholder="Enter your restaurant name"
                                value="<?php echo htmlspecialchars($form_data['business_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="businessType">Business Type</label>
                                <select id="businessType" name="business_type" class="form-control">
                                    <option value="restaurant" selected>Restaurant</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="businessRegistration">Business Registration Number</label>
                                <input type="text" id="businessRegistration" name="business_registration" class="form-control" 
                                    placeholder="Enter registration number"
                                    value="<?php echo htmlspecialchars($form_data['business_registration'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="taxId">Tax ID Number</label>
                            <input type="text" id="taxId" name="tax_id" class="form-control" 
                                placeholder="Enter TIN"
                                value="<?php echo htmlspecialchars($form_data['tax_id'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label for="psgcRegion" id="addressSectionLabel" style="margin: 0; font-weight: 700; color: #333;">Home Address (PSGC) *</label>
                            <button type="button" id="btnLocateMe" class="btn-secondary" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.8rem; min-height: 32px; padding: 0 12px; width: auto; font-weight: 700; border-radius: 6px; border: 1px solid #b3261e; background: #fff; color: #b3261e;">
                                <i class="fas fa-location-crosshairs"></i> Locate Me
                            </button>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="psgcRegion" id="regionLabel">Region (Luzon only) *</label>
                                <select id="psgcRegion" name="psgc_region_code" class="form-control" required data-selected="<?php echo htmlspecialchars($form_data['psgc_region_code'] ?? ''); ?>">
                                    <option value="">Select region</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="psgcProvince" id="provinceLabel">Province (Required except NCR) *</label>
                                <select id="psgcProvince" name="psgc_province_code" class="form-control" required data-selected="<?php echo htmlspecialchars($form_data['psgc_province_code'] ?? ''); ?>">
                                    <option value="">Select province</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="psgcCity">City / Municipality *</label>
                                <select id="psgcCity" name="psgc_city_code" class="form-control" required data-selected="<?php echo htmlspecialchars($form_data['psgc_city_code'] ?? ''); ?>">
                                    <option value="">Select city or municipality</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="psgcBarangay">Barangay *</label>
                                <select id="psgcBarangay" name="psgc_barangay_code" class="form-control" required data-selected="<?php echo htmlspecialchars($form_data['psgc_barangay_code'] ?? ''); ?>">
                                    <option value="">Select barangay</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="streetAddress" id="streetAddressLabel">House No. / Street / Landmark *</label>
                            <input type="text" id="streetAddress" name="street_address" class="form-control"
                                placeholder="e.g., Blk 5 Lot 2, Brgy. San Agustin"
                                value="<?php echo htmlspecialchars($form_data['street_address'] ?? ''); ?>"
                                maxlength="120"
                                required>
                        </div>
                        <small id="psgcAddressHelp" style="display:block; margin-top:4px; color:#666; font-size:0.84rem;">
                            Individual registration is limited to Luzon regions.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="address" id="completeAddressLabel">Complete Home Address *</label>
                        <textarea id="address" name="address" class="form-control" rows="3"
                                placeholder="Your complete home address will appear here after selecting PSGC fields"
                                readonly required><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" id="prevStep3">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="button" class="btn-primary" id="nextStep3">
                            Continue
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 4: Create Account -->
                <div class="form-step" id="step4Form">
                    <h2 style="color: #333; margin-bottom: 25px; font-size: 1.5rem;">Secure your account</h2>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <div class="password-wrapper input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" class="form-control" required 
                                placeholder="Create a strong password"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="72"
                                pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,72}"
                                title="Use at least 8 characters with uppercase, lowercase, number, and symbol.">
                            <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-indicator">
                                <span class="strength-text" id="strengthText">Weak</span>
                                <div class="strength-bars">
                                    <div class="strength-bar" id="strengthBar1"></div>
                                    <div class="strength-bar" id="strengthBar2"></div>
                                    <div class="strength-bar" id="strengthBar3"></div>
                                    <div class="strength-bar" id="strengthBar4"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password *</label>
                        <div class="password-wrapper input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirmPassword" name="confirm_password" class="form-control" required 
                                placeholder="Confirm your password"
                                autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="verification-note">
                        <i class="fas fa-envelope-open-text"></i>
                        <div>
                            <strong>What happens next?</strong><br>
                            After you create your account, we’ll send a confirmation email so you can verify your address and start using your account.
                        </div>
                    </div>

                    <div class="terms-agreement">
                        <input type="checkbox" id="acceptTerms" name="accept_terms" required>
                        <label for="acceptTerms">
                            I agree to the <a href="terms_of_service.php" data-policy-modal="terms">Terms of Service</a> and 
                            <a href="privacy_policy.php" data-policy-modal="privacy">Privacy Policy</a>
                        </label>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" id="prevStep4">
                            <i class="fas fa-arrow-left"></i>
                            Back
                        </button>
                        <button type="submit" class="btn-primary" id="submitRegistration">
                            <i class="fas fa-user-plus"></i>
                            <span>Create Account</span>
                        </button>
                    </div>
                </div>
            </form>
            
            <!-- Social Registration Divider -->
            <div class="social-divider">
                <span>Or register with</span>
            </div>
            
            <!-- Social Login Buttons -->
            <div class="social-login-buttons">
                <button type="button" class="social-btn google-btn" id="googleRegisterBtn" title="Register with Google">
                    <i class="fab fa-google"></i>
                    <span>Google</span>
                </button>
                <button type="button" class="social-btn facebook-btn" id="facebookRegisterBtn" title="Register with Facebook">
                    <i class="fab fa-facebook-f"></i>
                    <span>Facebook</span>
                </button>
                <button type="button" class="social-btn twitter-btn" id="twitterRegisterBtn" title="Register with X">
                    <i class="fab fa-x-twitter"></i>
                    <span>X</span>
                </button>
                <button type="button" class="social-btn instagram-btn" id="instagramRegisterBtn" title="Register with Instagram">
                    <i class="fab fa-instagram"></i>
                    <span>Instagram</span>
                </button>
            </div>
            
            <div class="auth-link">
                Already have an account? 
                <a href="login.php">Sign in here</a>
            </div>
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

<script type="text/plain" data-legacy-registration-script>
document.addEventListener('DOMContentLoaded', function() {
    if (!window.__useLegacyRegistrationScript) {
        return;
    }

    console.log('DOM loaded - registration form initialized');
    
    // Initialize SweetAlert2
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });
    
    // Current step tracking
    let currentStep = 1;
    const totalSteps = 4;
    let accountType = (document.getElementById('accountType')?.value || '').trim() || 'individual';
    
    // DOM Elements
    const steps = document.querySelectorAll('.step');
    const formSteps = document.querySelectorAll('.form-step');
    const accountTypeCards = document.querySelectorAll('.account-type-card');
    const accountTypeInput = document.getElementById('accountType');
    const organizationFields = document.getElementById('organizationFields');
    
    // Initialize
    updateProgressBar();
    updateOrganizationFields();
    
    // Debug logging
    console.log('Form steps found:', formSteps.length);
    console.log('Account type cards found:', accountTypeCards.length);
    
    // Back to login button
    document.getElementById('backToLoginBtn').addEventListener('click', function() {
        window.location.href = 'login.php';
    });
    
    // Account Type Selection
    accountTypeCards.forEach(card => {
        card.addEventListener('click', function() {
            console.log('Account type card clicked:', this.dataset.type);
            // Remove selected class from all cards
            accountTypeCards.forEach(c => c.classList.remove('selected'));
            
            // Add selected class to clicked card
            this.classList.add('selected');
            
            // Update account type
            accountType = this.dataset.type;
            accountTypeInput.value = accountType;
            console.log('Account type set to:', accountType);
            
            // Update organization fields visibility
            updateOrganizationFields();
        });
    });
    
    // Step Navigation - SIMPLIFIED EVENT LISTENERS
    document.getElementById('nextStep1').addEventListener('click', function() {
        console.log('Next Step 1 clicked');
        goToStep(2);
    });
    
    document.getElementById('nextStep2').addEventListener('click', function() {
        console.log('Next Step 2 clicked');
        validateStep2();
    });
    
    document.getElementById('nextStep3').addEventListener('click', function() {
        console.log('Next Step 3 clicked');
        validateStep3();
    });
    
    document.getElementById('prevStep2').addEventListener('click', function() {
        console.log('Prev Step 2 clicked');
        goToStep(1);
    });
    
    document.getElementById('prevStep3').addEventListener('click', function() {
        console.log('Prev Step 3 clicked');
        goToStep(2);
    });
    
    document.getElementById('prevStep4').addEventListener('click', function() {
        console.log('Prev Step 4 clicked');
        goToStep(3);
    });
    
    // Toggle password visibility
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.closest('.password-wrapper').querySelector('input');
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
                this.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
                this.setAttribute('aria-label', 'Show password');
            }
        });
    });
    
    // Password strength indicator
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strengthBars = [
                document.getElementById('strengthBar1'),
                document.getElementById('strengthBar2'),
                document.getElementById('strengthBar3'),
                document.getElementById('strengthBar4')
            ];
            const strengthText = document.getElementById('strengthText');
            
            let score = 0;
            if (password.length >= 8) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            
            // Reset bars
            strengthBars.forEach(bar => {
                bar.className = 'strength-bar';
            });
            
            // Update bars
            for (let i = 0; i < score; i++) {
                if (score <= 1) {
                    strengthBars[i].classList.add('weak');
                    strengthText.textContent = 'Weak';
                    strengthText.style.color = '#ff5252';
                } else if (score <= 2) {
                    strengthBars[i].classList.add('medium');
                    strengthText.textContent = 'Fair';
                    strengthText.style.color = '#ff9800';
                } else {
                    strengthBars[i].classList.add('strong');
                    strengthText.textContent = 'Strong';
                    strengthText.style.color = '#4caf50';
                }
            }
            
            if (password.length === 0) {
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#666';
            }
        });
    }
    
    // Form submission
    const registrationForm = document.getElementById('registrationForm');
    if (registrationForm) {
        registrationForm.addEventListener('submit', function(e) {
            console.log('Form submission attempted');
            e.preventDefault();
            
            // Validate step 4
            if (!validateStep4()) {
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
            
            // Submit form
            console.log('Submitting form...');
            this.submit();
        });
    }
    
    // Functions
    function goToStep(step) {
        console.log('Going to step:', step, 'from current step:', currentStep);
        
        // Validate current step before proceeding
        if (currentStep === 1 && !validateStep1()) {
            console.log('Step 1 validation failed');
            return;
        }
        
        // Update current step
        currentStep = step;
        console.log('Current step updated to:', currentStep);
        
        // Update UI
        updateSteps();
        updateProgressBar();
        
        // Scroll to top of form
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Focus on first input
        setTimeout(() => {
            const formStep = document.getElementById(`step${step}Form`);
            if (formStep) {
                const firstInput = formStep.querySelector('input:not([type="hidden"]), select, textarea');
                if (firstInput) {
                    firstInput.focus();
                    console.log('Focused on:', firstInput.id);
                }
            }
        }, 300);
    }
    
    function updateSteps() {
        console.log('Updating steps UI');
        // Update step indicators
        steps.forEach((step, index) => {
            step.classList.remove('active', 'completed');
            if (index + 1 === currentStep) {
                step.classList.add('active');
            } else if (index + 1 < currentStep) {
                step.classList.add('completed');
            }
        });
        
        // Update form steps
        formSteps.forEach((formStep, index) => {
            formStep.classList.remove('active');
            if (index + 1 === currentStep) {
                formStep.classList.add('active');
                console.log('Activated form step:', index + 1);
            }
        });
    }
    
    function updateProgressBar() {
        const progressBar = document.getElementById('progressBar');
        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
        if (progressBar) {
            progressBar.style.width = `${progress}%`;
        }
    }
    
    function updateOrganizationFields() {
        console.log('Updating organization fields for account type:', accountType);
        if (accountType === 'organization') {
            organizationFields.style.display = 'block';
            // Make business name required
            const businessNameInput = document.getElementById('businessName');
            if (businessNameInput) {
                businessNameInput.required = true;
            }
        } else {
            organizationFields.style.display = 'none';
            // Make business name optional
            const businessNameInput = document.getElementById('businessName');
            if (businessNameInput) {
                businessNameInput.required = false;
            }
        }
    }
    
    // Validation functions
    function validateStep1() {
        console.log('Validating step 1, account type:', accountType);
        if (!accountType) {
            Swal.fire({
                icon: 'error',
                title: 'Account Type Required',
                text: 'Please select an account type to continue.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        return true;
    }
    
    function validateStep2() {
        console.log('Validating step 2');
        
        const firstName = document.getElementById('firstName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        
        console.log('First name:', firstName);
        console.log('Last name:', lastName);
        console.log('Email:', email);
        console.log('Phone:', phone);
        
        // Check for empty fields
        if (!firstName || !lastName || !email || !phone) {
            console.log('Validation failed: Empty fields');
            Swal.fire({
                icon: 'error',
                title: 'Missing Information',
                text: 'Please fill in all required fields.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        // Validate email
        if (!isValidEmail(email)) {
            console.log('Validation failed: Invalid email');
            Swal.fire({
                icon: 'error',
                title: 'Invalid Email',
                text: 'Please enter a valid email address.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        // Validate phone - MUCH MORE FLEXIBLE
        if (!isValidPhone(phone)) {
            console.log('Validation failed: Invalid phone');
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Phone Number',
                    text: 'Please enter a valid Philippine mobile number (e.g., 09XXXXXXXXX).',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
        
        console.log('Step 2 validation passed');
        goToStep(3);
        return true;
    }
    
    function validateStep3() {
        console.log('Validating step 3');
        
        if (accountType === 'organization') {
            const businessName = document.getElementById('businessName').value.trim();
            console.log('Business name:', businessName);
            
            if (!businessName) {
                console.log('Validation failed: Business name required');
                Swal.fire({
                    icon: 'error',
                    title: 'Business Name Required',
                    text: 'Please enter your business name.',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
        }
        
        console.log('Step 3 validation passed');
        goToStep(4);
        return true;
    }
    
    function validateStep4() {
        console.log('Validating step 4');
        
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const terms = document.getElementById('acceptTerms');
        
        console.log('Password length:', password.length);
        console.log('Terms checked:', terms.checked);
        
        if (!password || !confirmPassword) {
            console.log('Validation failed: Password required');
            Swal.fire({
                icon: 'error',
                title: 'Password Required',
                text: 'Please enter and confirm your password.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        if (password.length < 8) {
            console.log('Validation failed: Password too short');
            Swal.fire({
                icon: 'error',
                title: 'Weak Password',
                text: 'Password must be at least 8 characters long.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        if (password !== confirmPassword) {
            console.log('Validation failed: Passwords dont match');
            Swal.fire({
                icon: 'error',
                title: 'Passwords Mismatch',
                text: 'Passwords do not match. Please try again.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        if (!terms.checked) {
            console.log('Validation failed: Terms not accepted');
            Swal.fire({
                icon: 'error',
                title: 'Terms Required',
                text: 'Please accept the Terms of Service and Privacy Policy.',
                confirmButtonColor: '#b3261e'
            });
            return false;
        }
        
        console.log('Step 4 validation passed');
        return true;
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    function isValidPhone(phone) {
        // Accept PH mobile formats:
        // 09XXXXXXXXX, 9XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX
        console.log('Validating phone:', phone);
        
        const cleaned = phone.replace(/[^\d]/g, '');
        console.log('Cleaned phone:', cleaned);
        
        const valid =
            /^09\d{9}$/.test(cleaned) ||
            /^9\d{9}$/.test(cleaned) ||
            /^639\d{9}$/.test(cleaned);

        if (valid) {
            console.log('Phone validation passed');
            return true;
        }
        
        console.log('Phone validation failed');
        return false;
    }
    
    // Auto-select account type card based on previous selection
    if (accountType === 'organization') {
        const orgCard = document.querySelector('.account-type-card[data-type="organization"]');
        if (orgCard) {
            orgCard.classList.add('selected');
        }
    }
    
    // Show error message if exists
    <?php if ($error): ?>
    Toast.fire({
        icon: 'error',
        title: '<?php echo addslashes($error); ?>'
    });
    <?php endif; ?>
    
    // Add Enter key support for mobile keyboards
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (currentStep === 1) {
                document.getElementById('nextStep1').click();
            } else if (currentStep === 2) {
                document.getElementById('nextStep2').click();
            } else if (currentStep === 3) {
                document.getElementById('nextStep3').click();
            } else if (currentStep === 4) {
                document.getElementById('submitRegistration').click();
            }
        }
    });
    
    // Test button functionality
    console.log('All event listeners attached');
    console.log('nextStep2 button exists:', !!document.getElementById('nextStep2'));
    console.log('nextStep3 button exists:', !!document.getElementById('nextStep3'));

    // Social Registration Handlers
    document.getElementById('googleRegisterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/google_auth.php?action=register';
    });

    document.getElementById('facebookRegisterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/facebook_auth.php?action=register';
    });

    document.getElementById('twitterRegisterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/twitter_auth.php?action=register';
    });

    document.getElementById('instagramRegisterBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/instagram_auth.php?action=register';
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
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
    const addressSectionLabel = document.getElementById('addressSectionLabel');
    const regionLabel = document.getElementById('regionLabel');
    const provinceLabel = document.getElementById('provinceLabel');
    const streetAddressLabel = document.getElementById('streetAddressLabel');
    const completeAddressLabel = document.getElementById('completeAddressLabel');
    let accountType = 'individual';

    const regionSelect = document.getElementById('psgcRegion');
    const provinceSelect = document.getElementById('psgcProvince');
    const citySelect = document.getElementById('psgcCity');
    const barangaySelect = document.getElementById('psgcBarangay');
    const streetAddressInput = document.getElementById('streetAddress');
    const addressPreviewInput = document.getElementById('address');
    const psgcAddressHelp = document.getElementById('psgcAddressHelp');
    const psgcRegionNameInput = document.getElementById('psgcRegionName');
    const psgcProvinceNameInput = document.getElementById('psgcProvinceName');
    const psgcCityNameInput = document.getElementById('psgcCityName');
    const psgcBarangayNameInput = document.getElementById('psgcBarangayName');

    const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
    const CALABARZON_REGION_CODE = '040000000';
    const CAVITE_PROVINCE_CODE = '042100000';
    const NCR_REGION_CODE = '130000000';
    const LUZON_REGION_CODES = [
        '010000000', // Ilocos Region
        '020000000', // Cagayan Valley
        '030000000', // Central Luzon
        '040000000', // CALABARZON
        '050000000', // Bicol Region
        '130000000', // NCR
        '140000000', // CAR
        '170000000'  // MIMAROPA
    ];
    let psgcEnabled = true;
    const psgcCache = new Map();

    function showError(title, text) {
        Swal.fire({
            icon: 'error',
            title: title,
            text: text,
            confirmButtonColor: '#b3261e'
        });
    }

    function isNcrSelection(regionCode, regionName) {
        const code = String(regionCode || '').trim();
        const name = String(regionName || '').toLowerCase();
        return code === NCR_REGION_CODE || name.includes('national capital region') || /\bncr\b/i.test(name);
    }

    function isLuzonSelection(regionCode, regionName) {
        const code = String(regionCode || '').trim();
        if (LUZON_REGION_CODES.includes(code)) {
            return true;
        }

        const name = String(regionName || '').toLowerCase();
        return (
            name.includes('ilocos') ||
            name.includes('cagayan valley') ||
            name.includes('central luzon') ||
            name.includes('calabarzon') ||
            name.includes('bicol') ||
            name.includes('national capital region') ||
            /\bncr\b/i.test(name) ||
            name.includes('cordillera') ||
            name.includes('mimaropa')
        );
    }

    function normalizePlaceName(value) {
        let text = String(value || '').toLowerCase();
        try {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {
            // Keep original text when normalize is unavailable.
        }
        return text.replace(/[^a-z0-9]/g, '');
    }

    function toNameTokens(value) {
        let text = String(value || '').toLowerCase();
        try {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {
            // Keep original text when normalize is unavailable.
        }

        if (text.includes('metro manila')) {
            text += ' ncr national capital region';
        }
        if (text.includes('national capital region') || /\bncr\b/.test(text)) {
            text += ' metro manila ncr';
        }
        if (text.includes('calabarzon') || text.includes('region iv-a') || text.includes('region iva') || text.includes('region 4a')) {
            text += ' calabarzon region iva region 4a iv-a';
        }

        text = text
            .replace(/&/g, ' and ')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();

        const stopWords = new Set([
            'city',
            'municipality',
            'municipal',
            'province',
            'region',
            'barangay',
            'brgy',
            'of',
            'the',
            'and'
        ]);

        return Array.from(new Set(text.split(/\s+/).filter(function(token) {
            return token && !stopWords.has(token);
        })));
    }

    function toCandidateNames() {
        const seen = new Set();
        const names = [];
        const addName = function(value) {
            const text = String(value || '').trim();
            if (!text) return;
            const key = normalizePlaceName(text);
            if (!key || seen.has(key)) return;
            seen.add(key);
            names.push(text);
        };

        Array.prototype.slice.call(arguments).forEach(function(group) {
            if (Array.isArray(group)) {
                group.forEach(addName);
            } else {
                addName(group);
            }
        });
        return names;
    }

    function findOptionValueByName(selectElement, targetName) {
        if (!selectElement || !targetName) return '';
        const normalizedTarget = normalizePlaceName(targetName);
        const targetTokens = toNameTokens(targetName);
        if (!normalizedTarget || targetTokens.length === 0) return '';

        let bestValue = '';
        let bestScore = 0;
        let bestOverlap = 0;

        Array.from(selectElement.options || []).forEach(function(option) {
            if (!option || !option.value) return;
            const normalizedOption = normalizePlaceName(option.textContent || option.label || '');
            if (!normalizedOption) return;

            if (normalizedOption === normalizedTarget ||
                normalizedOption.includes(normalizedTarget) ||
                normalizedTarget.includes(normalizedOption)) {
                bestValue = bestValue || option.value;
                bestScore = 1;
                bestOverlap = targetTokens.length;
                return;
            }

            const optionTokens = toNameTokens(option.textContent || option.label || '');
            if (!optionTokens.length) return;
            const optionTokenSet = new Set(optionTokens);
            let overlap = 0;
            targetTokens.forEach(function(token) {
                if (optionTokenSet.has(token)) overlap++;
            });
            if (overlap <= 0) return;

            const score = overlap / Math.max(targetTokens.length, optionTokens.length);
            if (overlap > bestOverlap || (overlap === bestOverlap && score > bestScore)) {
                bestOverlap = overlap;
                bestScore = score;
                bestValue = option.value;
            }
        });

        if (bestValue && (bestOverlap >= Math.max(1, targetTokens.length - 1) || bestScore >= 0.45)) {
            return bestValue;
        }
        return '';
    }

    function findOptionValueFromCandidates(selectElement, candidates) {
        const names = toCandidateNames(candidates);
        for (let i = 0; i < names.length; i += 1) {
            const code = findOptionValueByName(selectElement, names[i]);
            if (code) return code;
        }
        return '';
    }

    function hasOptionValue(selectElement, value) {
        const target = String(value || '').trim();
        if (!selectElement || !target) return false;
        return Array.from(selectElement.options || []).some(function(option) {
            return String(option.value || '').trim() === target;
        });
    }

    function applySelectCodeOrName(selectElement, code, candidateNames) {
        const normalizedCode = String(code || '').trim();
        if (normalizedCode && hasOptionValue(selectElement, normalizedCode)) {
            selectElement.value = normalizedCode;
            return normalizedCode;
        }
        const fallbackCode = findOptionValueFromCandidates(selectElement, candidateNames);
        if (fallbackCode) {
            selectElement.value = fallbackCode;
            return fallbackCode;
        }
        return '';
    }

    function sortByName(items) {
        return [].slice.call(items).sort(function(a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''));
        });
    }

    function setSelectOptions(selectElement, items, placeholder) {
        if (!selectElement) {
            return;
        }
        selectElement.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = placeholder;
        selectElement.appendChild(defaultOption);

        sortByName(items).forEach(function(item) {
            const option = document.createElement('option');
            option.value = item.code || '';
            option.textContent = item.name || '';
            selectElement.appendChild(option);
        });
    }

    function getSelectedLabel(selectElement) {
        if (!selectElement || selectElement.selectedIndex < 0) {
            return '';
        }
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            return '';
        }
        return selectedOption.textContent.trim();
    }

    function syncAddressPreview() {
        if (!addressPreviewInput) {
            return;
        }

        if (psgcRegionNameInput) {
            psgcRegionNameInput.value = getSelectedLabel(regionSelect);
        }
        if (psgcProvinceNameInput) {
            psgcProvinceNameInput.value = getSelectedLabel(provinceSelect);
        }
        if (psgcCityNameInput) {
            psgcCityNameInput.value = getSelectedLabel(citySelect);
        }
        if (psgcBarangayNameInput) {
            psgcBarangayNameInput.value = getSelectedLabel(barangaySelect);
        }

        if (!psgcEnabled) {
            return;
        }

        const composedAddress = [
            streetAddressInput ? streetAddressInput.value.trim() : '',
            psgcBarangayNameInput ? psgcBarangayNameInput.value.trim() : '',
            psgcCityNameInput ? psgcCityNameInput.value.trim() : '',
            psgcProvinceNameInput ? psgcProvinceNameInput.value.trim() : '',
            psgcRegionNameInput ? psgcRegionNameInput.value.trim() : ''
        ].filter(Boolean).join(', ');

        addressPreviewInput.value = composedAddress;
    }

    function setManualAddressFallback(message) {
        psgcEnabled = false;
        if (psgcAddressHelp) {
            psgcAddressHelp.textContent = message;
            psgcAddressHelp.style.color = '#b71c1c';
        }

        [regionSelect, provinceSelect, citySelect, barangaySelect].forEach(function(selectElement) {
            if (selectElement) {
                selectElement.required = false;
                selectElement.disabled = true;
            }
        });

        if (streetAddressInput) {
            streetAddressInput.required = true;
            streetAddressInput.disabled = true;
            streetAddressInput.value = '';
        }

        if (addressPreviewInput) {
            addressPreviewInput.readOnly = true;
            addressPreviewInput.value = '';
            addressPreviewInput.placeholder = 'PSGC location service is required. Please try again shortly.';
        }
    }

    function resetSelect(selectElement, placeholder) {
        if (!selectElement) {
            return;
        }
        setSelectOptions(selectElement, [], placeholder);
        selectElement.value = '';
        selectElement.disabled = true;
    }

    async function fetchPSGC(path) {
        const key = String(path || '');
        if (psgcCache.has(key)) {
            return psgcCache.get(key);
        }
        const response = await fetch(PSGC_API_BASE + key, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) {
            throw new Error('PSGC API request failed');
        }
        const payload = await response.json();
        psgcCache.set(key, payload);
        return payload;
    }

    async function loadRegions() {
        const regions = await fetchPSGC('/regions');
        const previousValue = regionSelect ? String(regionSelect.value || '').trim() : '';
        const previousRegionName = psgcRegionNameInput ? String(psgcRegionNameInput.value || '').trim() : '';

        const allowedRegions = regions.filter(function(region) {
            return String(region.code || '') === CALABARZON_REGION_CODE ||
                String(region.name || '').toLowerCase().includes('calabarzon');
        });

        setSelectOptions(regionSelect, allowedRegions, allowedRegions.length ? 'Select region' : 'No allowed region');
        regionSelect.disabled = allowedRegions.length === 0;

        if (allowedRegions.some(function(region) { return String(region.code || '') === previousValue; })) {
            regionSelect.value = previousValue;
        } else {
            const fallbackRegionCode = findOptionValueFromCandidates(regionSelect, previousRegionName);
            if (fallbackRegionCode) {
                regionSelect.value = fallbackRegionCode;
            } else if (allowedRegions.length === 1) {
                regionSelect.value = allowedRegions[0].code || CALABARZON_REGION_CODE;
            }
        }
    }

    async function loadProvinces(regionCode) {
        if (!regionCode) {
            resetSelect(provinceSelect, 'Select province');
            return [];
        }

        const provinces = await fetchPSGC('/regions/' + encodeURIComponent(regionCode) + '/provinces');
        const previousValue = provinceSelect ? String(provinceSelect.value || '').trim() : '';
        const previousProvinceName = psgcProvinceNameInput ? String(psgcProvinceNameInput.value || '').trim() : '';

        let allowedProvinces = provinces;
        if (accountType === 'organization') {
            allowedProvinces = provinces.filter(function(province) {
                return String(province.code || '') === CAVITE_PROVINCE_CODE ||
                    String(province.name || '').toLowerCase().includes('cavite');
            });
        }

        setSelectOptions(provinceSelect, allowedProvinces, allowedProvinces.length ? 'Select province' : 'No allowed province');
        provinceSelect.disabled = allowedProvinces.length === 0;

        if (allowedProvinces.some(function(province) { return String(province.code || '') === previousValue; })) {
            provinceSelect.value = previousValue;
        } else {
            const fallbackProvinceCode = findOptionValueFromCandidates(provinceSelect, previousProvinceName);
            if (fallbackProvinceCode) {
                provinceSelect.value = fallbackProvinceCode;
            } else if (accountType === 'organization' && allowedProvinces.length === 1) {
                provinceSelect.value = allowedProvinces[0].code || CAVITE_PROVINCE_CODE;
            }
        }
        return allowedProvinces;
    }

    async function loadCities(regionCode, provinceCode) {
        if (!regionCode) {
            resetSelect(citySelect, 'Select city or municipality');
            return [];
        }

        let cities = [];
        if (provinceCode) {
            cities = await fetchPSGC('/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities');
        } else {
            cities = await fetchPSGC('/regions/' + encodeURIComponent(regionCode) + '/cities-municipalities');
        }

        setSelectOptions(citySelect, cities, 'Select city or municipality');
        citySelect.disabled = cities.length === 0;
        return cities;
    }

    async function loadBarangays(cityCode) {
        if (!cityCode) {
            resetSelect(barangaySelect, 'Select barangay');
            return [];
        }

        const barangays = await fetchPSGC('/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays');
        setSelectOptions(barangaySelect, barangays, 'Select barangay');
        barangaySelect.disabled = barangays.length === 0;
        return barangays;
    }

    async function handleRegionChange(isRestore) {
        const regionCode = regionSelect ? regionSelect.value : '';
        if (!isRestore) {
            resetSelect(provinceSelect, 'Select province');
            resetSelect(citySelect, 'Select city or municipality');
            resetSelect(barangaySelect, 'Select barangay');
            if (psgcProvinceNameInput) {
                psgcProvinceNameInput.value = '';
            }
            if (psgcCityNameInput) {
                psgcCityNameInput.value = '';
            }
            if (psgcBarangayNameInput) {
                psgcBarangayNameInput.value = '';
            }
            syncAddressPreview();
        }

        if (!regionCode) {
            syncAddressPreview();
            return;
        }

        const provinces = await loadProvinces(regionCode);
        provinceSelect.required = provinces.length > 0;
        if (provinces.length === 0) {
            await loadCities(regionCode, '');
        } else if (provinces.length === 1 && provinceSelect.value) {
            await handleProvinceChange(true);
        }
        syncAddressPreview();
    }

    async function handleProvinceChange(isRestore) {
        const regionCode = regionSelect ? regionSelect.value : '';
        const provinceCode = provinceSelect ? provinceSelect.value : '';

        if (!isRestore) {
            resetSelect(citySelect, 'Select city or municipality');
            resetSelect(barangaySelect, 'Select barangay');
            if (psgcCityNameInput) {
                psgcCityNameInput.value = '';
            }
            if (psgcBarangayNameInput) {
                psgcBarangayNameInput.value = '';
            }
            syncAddressPreview();
        }

        await loadCities(regionCode, provinceCode);
        syncAddressPreview();
    }

    async function handleCityChange(isRestore) {
        const cityCode = citySelect ? citySelect.value : '';
        if (!isRestore) {
            resetSelect(barangaySelect, 'Select barangay');
            if (psgcBarangayNameInput) {
                psgcBarangayNameInput.value = '';
            }
            syncAddressPreview();
        }

        await loadBarangays(cityCode);
        syncAddressPreview();
    }

    async function restoreAddressSelections() {
        if (!psgcEnabled) {
            return;
        }

        const presetRegionCode = regionSelect ? (regionSelect.getAttribute('data-selected') || '') : '';
        const presetProvinceCode = provinceSelect ? (provinceSelect.getAttribute('data-selected') || '') : '';
        const presetCityCode = citySelect ? (citySelect.getAttribute('data-selected') || '') : '';
        const presetBarangayCode = barangaySelect ? (barangaySelect.getAttribute('data-selected') || '') : '';

        const regionCandidates = toCandidateNames(psgcRegionNameInput ? psgcRegionNameInput.value : '');
        const provinceCandidates = toCandidateNames(psgcProvinceNameInput ? psgcProvinceNameInput.value : '');
        const cityCandidates = toCandidateNames(psgcCityNameInput ? psgcCityNameInput.value : '');
        const barangayCandidates = toCandidateNames(psgcBarangayNameInput ? psgcBarangayNameInput.value : '');

        const regionAppliedCode = applySelectCodeOrName(regionSelect, presetRegionCode, regionCandidates);
        if (!regionAppliedCode) {
            if (regionSelect && regionSelect.value) {
                await handleRegionChange(true);
            }
            syncAddressPreview();
            return;
        }

        await handleRegionChange(true);

        if (provinceSelect && !provinceSelect.disabled) {
            applySelectCodeOrName(provinceSelect, presetProvinceCode, provinceCandidates);
            await handleProvinceChange(true);
        } else if (provinceSelect && !provinceSelect.required) {
            await handleProvinceChange(true);
        }

        let cityAppliedCode = '';
        if (citySelect && !citySelect.disabled) {
            cityAppliedCode = applySelectCodeOrName(citySelect, presetCityCode, cityCandidates);

            if (!cityAppliedCode && provinceSelect && !provinceSelect.disabled) {
                const currentProvinceCode = String(provinceSelect.value || '').trim();
                const provinceOptions = Array.from(provinceSelect.options || []).filter(function(option) {
                    return option && option.value;
                });

                for (let i = 0; i < provinceOptions.length; i += 1) {
                    const option = provinceOptions[i];
                    if (!option || !option.value || String(option.value) === currentProvinceCode) {
                        continue;
                    }
                    provinceSelect.value = String(option.value);
                    await handleProvinceChange(true);
                    cityAppliedCode = applySelectCodeOrName(citySelect, presetCityCode, cityCandidates);
                    if (cityAppliedCode) {
                        break;
                    }
                }

                if (!cityAppliedCode && currentProvinceCode && String(provinceSelect.value || '') !== currentProvinceCode) {
                    provinceSelect.value = currentProvinceCode;
                    await handleProvinceChange(true);
                }
            }

            if (cityAppliedCode) {
                await handleCityChange(true);
            }
        }

        if (barangaySelect && !barangaySelect.disabled) {
            applySelectCodeOrName(barangaySelect, presetBarangayCode, barangayCandidates);
        }

        syncAddressPreview();
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
                step3Title.textContent = 'Business Partner Information';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Provide your business details and business address.';
            }
            if (step3NavLabel) {
                step3NavLabel.textContent = 'Partner Info';
            }
            if (addressSectionLabel) {
                addressSectionLabel.textContent = 'Business Address (PSGC) *';
            }
            if (regionLabel) {
                regionLabel.textContent = 'Region (CALABARZON only) *';
            }
            if (provinceLabel) {
                provinceLabel.textContent = 'Province (Cavite only) *';
            }
            if (streetAddressLabel) {
                streetAddressLabel.textContent = 'Business Street / Landmark *';
            }
            if (completeAddressLabel) {
                completeAddressLabel.textContent = 'Complete Business Address *';
            }
            if (psgcAddressHelp) {
                psgcAddressHelp.textContent = 'Business partner registration is limited to Cavite, Region IV-A (CALABARZON).';
                psgcAddressHelp.style.color = '#666';
            }
            if (addressPreviewInput) {
                addressPreviewInput.placeholder = 'Your complete business address will appear here after selecting PSGC fields';
            }
        } else {
            organizationFields.style.display = 'none';
            if (businessNameInput) {
                businessNameInput.required = false;
            }
            if (step3Title) {
                step3Title.textContent = 'Individual Address Information';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Provide your complete home address for deliveries.';
            }
            if (step3NavLabel) {
                step3NavLabel.textContent = 'Address Info';
            }
            if (addressSectionLabel) {
                addressSectionLabel.textContent = 'Home Address (PSGC) *';
            }
            if (regionLabel) {
                regionLabel.textContent = 'Region (Luzon only) *';
            }
            if (provinceLabel) {
                provinceLabel.textContent = 'Province (Required except NCR) *';
            }
            if (streetAddressLabel) {
                streetAddressLabel.textContent = 'House No. / Street / Landmark *';
            }
            if (completeAddressLabel) {
                completeAddressLabel.textContent = 'Complete Home Address *';
            }
            if (psgcAddressHelp) {
                psgcAddressHelp.textContent = 'Individual registration is limited to Luzon regions.';
                psgcAddressHelp.style.color = '#666';
            }
            if (addressPreviewInput) {
                addressPreviewInput.placeholder = 'Your complete home address will appear here after selecting PSGC fields';
            }
        }
    }

    async function refreshAddressScopeByAccountType() {
        if (!psgcEnabled || !regionSelect || !provinceSelect || !citySelect || !barangaySelect) {
            return;
        }

        const previousRegion = String(regionSelect.value || '').trim();
        await loadRegions();
        const currentRegion = String(regionSelect.value || '').trim();

        if (currentRegion === '') {
            resetSelect(provinceSelect, 'Select province');
            resetSelect(citySelect, 'Select city or municipality');
            resetSelect(barangaySelect, 'Select barangay');
            syncAddressPreview();
            return;
        }

        if (currentRegion !== previousRegion) {
            resetSelect(provinceSelect, 'Select province');
            resetSelect(citySelect, 'Select city or municipality');
            resetSelect(barangaySelect, 'Select barangay');
        }

        await handleRegionChange(true);
        syncAddressPreview();
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
        return /^[\p{L}\p{M}'\-\s]{2,60}$/u.test(name);
    }

    function isStrongPassword(password) {
        return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,72}$/.test(password);
    }

    function validateStep1() {
        const firstName = ((document.getElementById('firstName') || {}).value || '').trim();
        const lastName = ((document.getElementById('lastName') || {}).value || '').trim();

        if (!firstName || !lastName) {
            showError('Missing Information', 'Please fill in all required personal details.');
            return false;
        }

        if (!isValidName(firstName) || !isValidName(lastName)) {
            showError('Invalid Name', 'Please use letters, spaces, apostrophes, or hyphens only.');
            return false;
        }

        return true;
    }

    let idVerified = false;

    function simulateIdVerification(firstName, lastName) {
        Swal.fire({
            title: 'Verifying Identity',
            html: '<div style="margin-bottom:15px; font-size:0.95rem; color:#475569;" id="swalScanMsg">Initializing verification scanner...</div>' +
                  '<div class="ocr-scan-progress" style="width:100%; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; position:relative; margin-bottom:10px;">' +
                  '  <div id="swalScanBar" style="position:absolute; top:0; left:0; height:100%; width:0%; background:#b3261e; transition: width 0.3s ease;"></div>' +
                  '</div>' +
                  '<div style="font-size:0.8rem; color:#94a3b8;"><i class="fas fa-shield-halved fa-spin"></i> Secure biometric credentials match</div>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
                const bar = document.getElementById('swalScanBar');
                const msg = document.getElementById('swalScanMsg');
                
                setTimeout(() => {
                    if (bar) bar.style.width = '35%';
                    if (msg) msg.textContent = 'Scanning Front & Back ID documents...';
                }, 800);

                setTimeout(() => {
                    if (bar) bar.style.width = '65%';
                    if (msg) msg.textContent = 'Extracting document credentials (OCR)...';
                }, 1800);

                setTimeout(() => {
                    if (bar) bar.style.width = '88%';
                    if (msg) msg.textContent = 'Cross-matching name: "' + firstName + ' ' + lastName + '"...';
                }, 2800);

                setTimeout(() => {
                    if (bar) bar.style.width = '100%';
                    if (msg) msg.textContent = 'Verification successful! Credentials match.';
                }, 3800);

                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Identity Verified',
                        text: 'OCR details successfully match your registration credentials.',
                        confirmButtonColor: '#b3261e',
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then(() => {
                        idVerified = true;
                        goToStep(3);
                    });
                }, 4300);
            }
        });
    }

    // Reset ID verification flag if details change
    document.addEventListener('DOMContentLoaded', function() {
        ['firstName', 'lastName', 'validIdType', 'validIdFront', 'validIdBack'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => { idVerified = false; });
                el.addEventListener('input', () => { idVerified = false; });
            }
        });
    });

    function validateStep2() {
        const firstName = ((document.getElementById('firstName') || {}).value || '').trim();
        const lastName = ((document.getElementById('lastName') || {}).value || '').trim();
        const email = ((document.getElementById('email') || {}).value || '').trim();
        const phone = ((document.getElementById('phone') || {}).value || '').trim();
        const validIdType = ((document.getElementById('validIdType') || {}).value || '').trim();
        const validIdFrontInput = document.getElementById('validIdFront');
        const validIdBackInput = document.getElementById('validIdBack');
        
        const validIdFrontFile = validIdFrontInput && validIdFrontInput.files ? validIdFrontInput.files[0] : null;
        const validIdBackFile = validIdBackInput && validIdBackInput.files ? validIdBackInput.files[0] : null;

        if (!email || !phone) {
            showError('Missing Information', 'Please fill in all required personal details.');
            return false;
        }

        if (!isValidName(firstName) || !isValidName(lastName)) {
            showError('Invalid Name', 'Please use letters, spaces, apostrophes, or hyphens only.');
            return false;
        }

        if (!isValidEmail(email)) {
            showError('Invalid Email', 'Please enter a valid email address.');
            return false;
        }

        if (!isValidPhone(phone)) {
            showError('Invalid Phone', 'Please enter a valid Philippine mobile number.');
            return false;
        }

        if (!validIdType) {
            showError('Valid ID Type Required', 'Please select what kind of ID you will upload.');
            return false;
        }

        if (!validIdFrontFile) {
            showError('Front ID Photo Required', 'Please upload a photo of the front side of your ID.');
            return false;
        }

        if (!validIdBackFile) {
            showError('Back ID Photo Required', 'Please upload a photo of the back side of your ID.');
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
            if (file.size > (5 * 1024 * 1024)) {
                showError('File Too Large', 'Your ' + label + ' file size must be 5MB or smaller.');
                return false;
            }
            return true;
        };

        if (!validateFile(validIdFrontFile, 'Front of ID') || !validateFile(validIdBackFile, 'Back of ID')) {
            return false;
        }

        if (!idVerified) {
            simulateIdVerification(firstName, lastName);
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

        if (!addressPreviewInput || addressPreviewInput.value.trim().length < 10) {
            showError('Address Required', accountType === 'organization'
                ? 'Please provide a complete business address.'
                : 'Please provide a complete home address.');
            return false;
        }

        if (!psgcEnabled) {
            showError('Address Service Unavailable', 'PSGC address lookup is currently unavailable. Please try again in a few minutes.');
            return false;
        }

        if (psgcEnabled) {
            const selectedRegionLabel = getSelectedLabel(regionSelect);
            const isNcrRegion = isNcrSelection(regionSelect.value, selectedRegionLabel);
            const hasRequiredLocationPieces =
                !!regionSelect.value &&
                !!citySelect.value &&
                !!barangaySelect.value &&
                !!streetAddressInput.value.trim() &&
                (isNcrRegion || !!provinceSelect.value);

            if (!hasRequiredLocationPieces) {
                showError('Incomplete Address', accountType === 'organization'
                    ? 'Please complete your business address details (region, province, city/municipality, barangay, and street).'
                    : 'Please complete your home address details in Luzon (province may be skipped for NCR).');
                return false;
            }

            if (accountType === 'organization') {
                if (regionSelect.value !== CALABARZON_REGION_CODE || !String(selectedRegionLabel).toLowerCase().includes('calabarzon')) {
                    showError('Region Restricted', 'Business partner registration is available only in Region IV-A (CALABARZON).');
                    return false;
                }
                const selectedProvinceLabel = getSelectedLabel(provinceSelect);
                if (provinceSelect.value !== CAVITE_PROVINCE_CODE || !String(selectedProvinceLabel).toLowerCase().includes('cavite')) {
                    showError('Province Restricted', 'Business partner registration is available only in Cavite.');
                    return false;
                }
            } else if (!isLuzonSelection(regionSelect.value, selectedRegionLabel)) {
                showError('Region Restricted', 'Individual registration is available only for Luzon regions.');
                return false;
            }
        }

        return true;
    }

    function validateStep4() {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const termsInput = document.getElementById('acceptTerms');
        const password = passwordInput ? passwordInput.value : '';
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';

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
            refreshAddressScopeByAccountType().catch(function() {
                setManualAddressFallback('PSGC address lookup is unavailable while updating account type scope.');
            });
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

    const btnLocateMe = document.getElementById('btnLocateMe');
    if (btnLocateMe) {
        btnLocateMe.addEventListener('click', function() {
            if (!navigator.geolocation) {
                showError('Geolocation Unavailable', 'Your browser does not support location services.');
                return;
            }

            Swal.fire({
                title: 'Locating...',
                text: 'Please wait while we determine your location.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            navigator.geolocation.getCurrentPosition(async (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                try {
                    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                    const data = await response.json();
                    
                    if (data && data.address) {
                        const addr = data.address;
                        
                        const regionName = addr.region || addr.state || '';
                        const provinceName = addr.province || addr.county || '';
                        const cityName = addr.city || addr.municipality || addr.town || addr.village || '';
                        const barangayName = addr.neighbourhood || addr.suburb || addr.quarter || addr.village || '';
                        const road = addr.road || addr.suburb || '';
                        const houseNumber = addr.house_number || '';
                        const streetAddressVal = (houseNumber ? houseNumber + ' ' : '') + road;

                        if (regionSelect) {
                            const regionVal = findOptionValueFromCandidates(regionSelect, [regionName, 'calabarzon', 'iv-a']);
                            if (regionVal) {
                                regionSelect.value = regionVal;
                                if (psgcRegionNameInput) psgcRegionNameInput.value = regionSelect.options[regionSelect.selectedIndex].text;
                                await handleRegionChange(true);
                            }
                        }

                        if (provinceSelect && provinceName) {
                            const provinceVal = findOptionValueFromCandidates(provinceSelect, [provinceName]);
                            if (provinceVal) {
                                provinceSelect.value = provinceVal;
                                if (psgcProvinceNameInput) psgcProvinceNameInput.value = provinceSelect.options[provinceSelect.selectedIndex].text;
                                await handleProvinceChange(true);
                            }
                        }

                        if (citySelect && cityName) {
                            const cityVal = findOptionValueFromCandidates(citySelect, [cityName]);
                            if (cityVal) {
                                citySelect.value = cityVal;
                                if (psgcCityNameInput) psgcCityNameInput.value = citySelect.options[citySelect.selectedIndex].text;
                                await handleCityChange(true);
                            }
                        }

                        if (barangaySelect && barangayName) {
                            const barangayVal = findOptionValueFromCandidates(barangaySelect, [barangayName]);
                            if (barangayVal) {
                                barangaySelect.value = barangayVal;
                                if (psgcBarangayNameInput) psgcBarangayNameInput.value = barangaySelect.options[barangaySelect.selectedIndex].text;
                            }
                        }

                        if (streetAddressVal) {
                            const streetInput = document.getElementById('streetAddress');
                            if (streetInput) {
                                streetInput.value = streetAddressVal;
                            }
                        }

                        syncAddressPreview();
                        Swal.fire({
                            icon: 'success',
                            title: 'Location found!',
                            text: 'Your address details have been auto-filled. Please check them for accuracy.',
                            confirmButtonColor: '#b3261e',
                            timer: 3000
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Location resolution failed',
                            text: 'We could not determine your address name from the coordinates.',
                            confirmButtonColor: '#b3261e'
                        });
                    }
                } catch (err) {
                    console.error(err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Search failed',
                        text: 'Unable to connect to the reverse-geocoding service.',
                        confirmButtonColor: '#b3261e'
                    });
                }
            }, (error) => {
                let message = 'Error: The Geolocation service failed.';
                if (error && error.code === 1) {
                    message = 'Location permission was denied. Please allow location access.';
                } else if (error && error.code === 2) {
                    message = 'Location could not be determined. Please check your signal and try again.';
                } else if (error && error.code === 3) {
                    message = 'Location request timed out. Please try again.';
                }
                Swal.fire({
                    icon: 'error',
                    title: 'Geolocation Error',
                    text: message,
                    confirmButtonColor: '#b3261e'
                });
            }, {
                enableHighAccuracy: true,
                timeout: 8000,
                maximumAge: 0
            });
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

    if (regionSelect && provinceSelect && citySelect && barangaySelect && streetAddressInput && addressPreviewInput) {
        Promise.resolve()
            .then(loadRegions)
            .then(restoreAddressSelections)
            .catch(function() {
                setManualAddressFallback('PSGC address lookup is unavailable right now. You can enter your complete address manually.');
            });

        regionSelect.addEventListener('change', function() {
            handleRegionChange(false).catch(function() {
                setManualAddressFallback('PSGC address lookup failed while loading provinces/cities. Use manual address entry for now.');
            });
        });

        provinceSelect.addEventListener('change', function() {
            handleProvinceChange(false).catch(function() {
                setManualAddressFallback('PSGC address lookup failed while loading cities/municipalities.');
            });
        });

        citySelect.addEventListener('change', function() {
            handleCityChange(false).catch(function() {
                setManualAddressFallback('PSGC address lookup failed while loading barangays.');
            });
        });

        barangaySelect.addEventListener('change', syncAddressPreview);
        streetAddressInput.addEventListener('input', syncAddressPreview);
    }

    const registrationForm = document.getElementById('registrationForm');
    if (registrationForm) {
        registrationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!validateStep4() || !validateStep3()) {
                return false;
            }

            if (psgcEnabled) {
                syncAddressPreview();
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
            cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
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
        const fileInput = document.getElementById(side === 'Front' ? 'validIdFront' : 'validIdBack');
        const previewContainer = document.getElementById(side === 'Front' ? 'previewFront' : 'previewBack');
        const zoneContent = document.getElementById(side === 'Front' ? 'zoneContentFront' : 'zoneContentBack');

        if (fileInput && fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                if (previewContainer) {
                    const img = previewContainer.querySelector('img');
                    if (img) img.src = e.target.result;
                    previewContainer.style.display = 'block';
                }
                if (zoneContent) zoneContent.style.visibility = 'hidden';
            };
            reader.readAsDataURL(file);
        }
    }

    document.getElementById('validIdFront').addEventListener('change', () => handleFileSelection('Front'));
    document.getElementById('validIdBack').addEventListener('change', () => handleFileSelection('Back'));

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
        activeCameraSide = side;
        const title = document.getElementById('cameraModalTitle');
        if (title) title.textContent = 'Capture Front of ID';
        if (side === 'Back' && title) title.textContent = 'Capture Back of ID';
        if (cameraModal) cameraModal.style.display = 'flex';
        startCamera();
    }

    function closeModal() {
        if (cameraModal) cameraModal.style.display = 'none';
        stopCamera();
    }

    if (zoneFront) zoneFront.addEventListener('click', () => openModal('Front'));
    if (zoneBack) zoneBack.addEventListener('click', () => openModal('Back'));
    if (closeCameraBtn) closeCameraBtn.addEventListener('click', closeModal);

    if (removeFront) {
        removeFront.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('validIdFront').value = '';
            document.getElementById('previewFront').style.display = 'none';
            document.getElementById('zoneContentFront').style.visibility = 'visible';
            idVerified = false;
        });
    }

    if (removeBack) {
        removeBack.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('validIdBack').value = '';
            document.getElementById('previewBack').style.display = 'none';
            document.getElementById('zoneContentBack').style.visibility = 'visible';
            idVerified = false;
        });
    }

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

                const previewContainer = document.getElementById(activeCameraSide === 'Front' ? 'previewFront' : 'previewBack');
                const zoneContent = document.getElementById(activeCameraSide === 'Front' ? 'zoneContentFront' : 'zoneContentBack');
                const inputElement = document.getElementById(activeCameraSide === 'Front' ? 'validIdFront' : 'validIdBack');

                if (previewContainer) {
                    const img = previewContainer.querySelector('img');
                    if (img) img.src = dataUrl;
                    previewContainer.style.display = 'block';
                }
                if (zoneContent) zoneContent.style.visibility = 'hidden';

                try {
                    const blob = dataURLtoBlob(dataUrl);
                    const file = new File([blob], 'captured_id_' + activeCameraSide.toLowerCase() + '.jpg', { type: 'image/jpeg' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    if (inputElement) {
                        inputElement.files = dt.files;
                        inputElement.dispatchEvent(new Event('change'));
                    }
                } catch (e) {
                    console.error('File injection error:', e);
                }

                closeModal();
            }
        });
    }

    updateOrganizationFields();
    updateSteps();
    updateProgressBar();
    syncAddressPreview();

    const serverRegistrationError = <?php echo json_encode($error ?? ''); ?>;

    if (serverRegistrationError) {
        const loweredError = serverRegistrationError.toLowerCase();
        if (/(name)/.test(loweredError)) {
            targetStep = 1;
        } else if (/(email|mobile|phone|valid id|government id)/.test(loweredError)) {
            targetStep = 2;
        } else if (/(business|partner|address|psgc|delivery|restaurant)/.test(loweredError)) {
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

include 'includes/footer.php'; 
?>
