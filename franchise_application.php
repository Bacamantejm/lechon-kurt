<?php
// franchise_application.php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/government_verification_provider.php';

$is_ajax_request = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
);

$success_msg = '';
$error_msg = '';
$swal_alert = null;
$allowed_business_types = ['sole_proprietorship', 'partnership', 'corporation', 'llc'];
$csrf_token = generateCSRFToken();
$has_pending_application = false;
$latest_application = null;
$application_number = '';
$franchise_workflow = [
    'stage' => 'initial_application',
    'can_submit' => true,
    'message' => '',
    'remaining_attempts' => 2,
    'total_attempts' => 0,
    'next_eligible_at' => null,
    'latest_application' => null,
    'approved_trial_ends_at' => null
];

function getUserByIdForSession($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return null;
    }

    $query = "SELECT id, email, full_name, user_type, account_type FROM users WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $user ?: null;
}

function getUserByEmailForSession($conn, $email) {
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $query = "SELECT id, email, full_name, user_type, account_type FROM users WHERE email = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $user ?: null;
}

function resolveFranchiseSessionUserId($conn) {
    $session_user_id = (int)($_SESSION['user_id'] ?? 0);
    $session_email = trim((string)($_SESSION['email'] ?? ''));

    if ($session_user_id > 0) {
        $user = getUserByIdForSession($conn, $session_user_id);
        if ($user) {
            return ['ok' => true, 'user_id' => (int)$user['id'], 'recovered' => false];
        }
    }

    if ($session_email !== '') {
        $user = getUserByEmailForSession($conn, $session_email);
        if ($user) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_type'] = $user['user_type'] ?: ($_SESSION['user_type'] ?? 'customer');
            $_SESSION['account_type'] = $user['account_type'] ?: ($_SESSION['account_type'] ?? 'individual');
            return ['ok' => true, 'user_id' => (int)$user['id'], 'recovered' => true];
        }
    }

    return [
        'ok' => false,
        'message' => 'Your account session is out of sync with the database. Please sign in again and retry your submission.'
    ];
}

$session_resolution = resolveFranchiseSessionUserId($conn);
if (!$session_resolution['ok']) {
    $session_error = $session_resolution['message'];
    unset($_SESSION['user_id'], $_SESSION['email'], $_SESSION['full_name'], $_SESSION['user_type'], $_SESSION['account_type'], $_SESSION['role_id'], $_SESSION['permissions'], $_SESSION['has_backoffice_access']);

    if ($is_ajax_request || $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $session_error
        ]);
        mysqli_close($conn);
        exit;
    }

    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user_id = (int)$session_resolution['user_id'];
if (!empty($session_resolution['recovered'])) {
    error_log("Franchise submission session recovered by email for user {$user_id}.");
}

function getFranchiseWorkflowState($conn, $user_id) {
    $user_id = (int)$user_id;
    $state = [
        'stage' => 'initial_application',
        'can_submit' => true,
        'message' => '',
        'remaining_attempts' => 2,
        'total_attempts' => 0,
        'next_eligible_at' => null,
        'latest_application' => null,
        'approved_trial_ends_at' => null
    ];

    if ($user_id <= 0) {
        $state['can_submit'] = false;
        $state['stage'] = 'invalid_user';
        $state['message'] = 'We could not verify your account session. Please sign in again.';
        return $state;
    }

    $user = getUserByIdForSession($conn, $user_id);
    if ($user) {
        $user_type = strtolower(trim((string)($user['user_type'] ?? 'customer')));
        $account_type = strtolower(trim((string)($user['account_type'] ?? 'individual')));
        if ($account_type === 'organization' || $user_type === 'admin') {
            $state['stage'] = 'already_registered_partner';
            $state['can_submit'] = false;
            $state['message'] = 'Your business partner account is already active in the system. You do not need to register for franchise access again.';
        }
    }

    $apps = [];
    $query = "SELECT id, application_number, status, created_at, reviewed_at, admin_notes
              FROM franchise_applications
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $apps[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    $state['total_attempts'] = count($apps);
    $state['remaining_attempts'] = max(0, 2 - $state['total_attempts']);
    $state['latest_application'] = $apps[0] ?? null;
    $latest_status = strtolower(trim((string)($state['latest_application']['status'] ?? '')));

    if ($latest_status === 'approved') {
        $state['stage'] = 'approved_partner';
        $state['can_submit'] = false;
        $state['approved_trial_ends_at'] = date('Y-m-d', strtotime('+1 month', strtotime((string)($state['latest_application']['reviewed_at'] ?? $state['latest_application']['created_at'] ?? 'now'))));
        $state['message'] = 'Your franchise application has already been approved. Your one-month platform trial is now active, so you cannot apply again.';
        return $state;
    }

    if ($latest_status === 'pending') {
        $state['stage'] = 'pending_review';
        $state['can_submit'] = false;
        $state['message'] = 'You already have a pending franchise application under review.';
        return $state;
    }

    if ($state['total_attempts'] >= 2) {
        $state['stage'] = 'max_attempts_reached';
        $state['can_submit'] = false;
        $state['message'] = 'You have already used your two franchise application attempts. No additional reapplication is allowed.';
        return $state;
    }

    if ($latest_status === 'rejected') {
        $reference_time = (string)($state['latest_application']['reviewed_at'] ?? $state['latest_application']['created_at'] ?? '');
        $cooldown_anchor = $reference_time !== '' ? strtotime($reference_time) : time();
        $next_eligible_timestamp = strtotime('+3 days', $cooldown_anchor);
        $state['next_eligible_at'] = date('Y-m-d H:i:s', $next_eligible_timestamp);

        if (time() < $next_eligible_timestamp) {
            $state['stage'] = 'reapply_cooldown';
            $state['can_submit'] = false;
            $state['message'] = 'Your last franchise application was rejected. You may submit one final application after the 3-day cooldown period.';
            return $state;
        }

        $state['stage'] = 'final_retry_available';
        $state['can_submit'] = true;
        $state['remaining_attempts'] = 1;
        $state['message'] = 'Your cooldown is complete. You may now submit one final franchise application.';
        return $state;
    }

    if ($state['stage'] === 'already_registered_partner') {
        return $state;
    }

    $state['message'] = 'You may submit your franchise application now. You have up to two total attempts, and rejected applications require a 3-day wait before the final retry.';
    return $state;
}

$franchise_workflow = getFranchiseWorkflowState($conn, $user_id);
$latest_application = $franchise_workflow['latest_application'];
$has_pending_application = $franchise_workflow['stage'] === 'pending_review';

function getFormInput($key, $default = '') {
    return trim((string)($_POST[$key] ?? $default));
}

function oldFormValue($key, $fallback = '') {
    return htmlspecialchars((string)($_POST[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
}

function isOldSelected($key, $value, $fallback = '') {
    $current = (string)($_POST[$key] ?? $fallback);
    return $current === $value ? 'selected' : '';
}

function buildFranchiseBusinessAddress($street_address, $barangay_name, $city_name, $province_name, $region_name) {
    $segments = [
        trim((string)$street_address),
        trim((string)$barangay_name),
        trim((string)$city_name),
        trim((string)$province_name),
        trim((string)$region_name)
    ];

    $filtered = [];
    $seen = [];
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $normalized = strtolower(preg_replace('/\s+/', ' ', $segment));
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $filtered[] = $segment;
    }

    return implode(', ', $filtered);
}

function normalizeFranchiseBusinessTypeValue($value, array $allowed_business_types) {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return '';
    }

    $alias_map = [
        'sole proprietorship' => 'sole_proprietorship',
        'sole_proprietorship' => 'sole_proprietorship',
        'soleproprietorship' => 'sole_proprietorship',
        'partnership' => 'partnership',
        'corporation' => 'corporation',
        'llc' => 'llc',
        'limited liability company' => 'llc',
        'limitedliabilitycompany' => 'llc'
    ];

    $collapsed = preg_replace('/\s+/', ' ', $normalized);
    $candidate = $alias_map[$collapsed] ?? '';
    if ($candidate === '') {
        $compact = preg_replace('/[^a-z]/', '', $normalized);
        $candidate = $alias_map[$compact] ?? $normalized;
    }

    return in_array($candidate, $allowed_business_types, true) ? $candidate : '';
}

function franchiseIsCoordinateOnlyAddress($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return false;
    }

    if (preg_match('/\b(lat|latitude|lng|long|longitude)\b/i', $text)) {
        return true;
    }

    return (bool)preg_match('/^\s*-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+\s*$/', $text);
}

function franchiseExtractStreetAddress($full_address) {
    $full_address = trim((string)$full_address);
    if ($full_address === '' || franchiseIsCoordinateOnlyAddress($full_address)) {
        return '';
    }

    $parts = array_values(array_filter(array_map('trim', explode(',', $full_address)), static function ($part) {
        return $part !== '';
    }));
    return $parts[0] ?? $full_address;
}

function fetchFranchiseApplicantAccountProfile($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return [];
    }

    $query = "SELECT full_name, email, phone, address, business_name, business_type, business_registration, tax_id
              FROM users
              WHERE id = ?
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $profile = $result ? mysqli_fetch_assoc($result) : [];
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return is_array($profile) ? $profile : [];
}

function fetchFranchiseApplicantDefaultAddress($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !tableExists($conn, 'user_saved_addresses')) {
        return [];
    }

    $query = "SELECT contact_name, contact_phone, street_address, full_address,
                     region_name, region_code, province_name, province_code,
                     city_name, city_code, barangay_name, barangay_code
              FROM user_saved_addresses
              WHERE user_id = ?
              ORDER BY is_default DESC, updated_at DESC, id DESC
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $address = $result ? mysqli_fetch_assoc($result) : [];
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return is_array($address) ? $address : [];
}

function fetchLatestFranchiseApplicationForPrefill($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !tableExists($conn, 'franchise_applications')) {
        return [];
    }

    $query = "SELECT business_name, business_type, tin_number, dti_sec_number, bir_registration_number, mayors_permit,
                     business_address, region_name, region_code, province_name, province_code, city_name, city_code,
                     barangay_name, barangay_code, contact_person, contact_phone, contact_email, capital_investment,
                     business_experience, marketing_plan
              FROM franchise_applications
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $application = $result ? mysqli_fetch_assoc($result) : [];
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return is_array($application) ? $application : [];
}

function buildFranchiseApplicantPrefill($conn, $user_id, array $allowed_business_types) {
    $prefill = [
        'business_name' => '',
        'business_type' => '',
        'tin_number' => '',
        'dti_sec_number' => '',
        'bir_registration_number' => '',
        'mayors_permit' => '',
        'business_address_street' => '',
        'business_address' => '',
        'psgc_region_code' => '',
        'psgc_region_name' => '',
        'psgc_province_code' => '',
        'psgc_province_name' => '',
        'psgc_city_code' => '',
        'psgc_city_name' => '',
        'psgc_barangay_code' => '',
        'psgc_barangay_name' => '',
        'contact_person' => '',
        'contact_phone' => '',
        'contact_email' => '',
        'capital_investment' => '',
        'business_experience' => '',
        'marketing_plan' => '',
        'psgc_manual_mode' => '0'
    ];

    $fill_if_empty = static function ($key, $value) use (&$prefill) {
        $value = trim((string)$value);
        if ($value !== '' && $prefill[$key] === '') {
            $prefill[$key] = $value;
        }
    };

    $profile = fetchFranchiseApplicantAccountProfile($conn, $user_id);
    if (!empty($profile)) {
        $prefill['business_name'] = trim((string)($profile['business_name'] ?? ''));
        $prefill['business_type'] = normalizeFranchiseBusinessTypeValue($profile['business_type'] ?? '', $allowed_business_types);
        $prefill['tin_number'] = trim((string)($profile['tax_id'] ?? ''));
        $prefill['dti_sec_number'] = trim((string)($profile['business_registration'] ?? ''));
        $prefill['contact_person'] = trim((string)($profile['full_name'] ?? ''));
        $prefill['contact_phone'] = trim((string)($profile['phone'] ?? ''));
        $prefill['contact_email'] = trim((string)($profile['email'] ?? ''));

        $profile_address = trim((string)($profile['address'] ?? ''));
        if ($profile_address !== '' && !franchiseIsCoordinateOnlyAddress($profile_address)) {
            $prefill['business_address'] = $profile_address;
            $prefill['business_address_street'] = franchiseExtractStreetAddress($profile_address);
        }
    }

    $saved_address = fetchFranchiseApplicantDefaultAddress($conn, $user_id);
    if (!empty($saved_address)) {
        $fill_if_empty('contact_person', $saved_address['contact_name'] ?? '');
        $fill_if_empty('contact_phone', $saved_address['contact_phone'] ?? '');

        if ($prefill['business_address_street'] === '') {
            $fill_if_empty('business_address_street', $saved_address['street_address'] ?? '');
        }

        $saved_full_address = trim((string)($saved_address['full_address'] ?? ''));
        if ($saved_full_address !== '' && !franchiseIsCoordinateOnlyAddress($saved_full_address) && $prefill['business_address'] === '') {
            $prefill['business_address'] = $saved_full_address;
        }

        foreach (['region', 'province', 'city', 'barangay'] as $part) {
            $fill_if_empty('psgc_' . $part . '_code', $saved_address[$part . '_code'] ?? '');
            $fill_if_empty('psgc_' . $part . '_name', $saved_address[$part . '_name'] ?? '');
        }
    }

    $latest_application = fetchLatestFranchiseApplicationForPrefill($conn, $user_id);
    if (!empty($latest_application)) {
        $fill_if_empty('business_name', $latest_application['business_name'] ?? '');
        $app_business_type = normalizeFranchiseBusinessTypeValue($latest_application['business_type'] ?? '', $allowed_business_types);
        $fill_if_empty('business_type', $app_business_type);
        $fill_if_empty('tin_number', $latest_application['tin_number'] ?? '');
        $fill_if_empty('dti_sec_number', $latest_application['dti_sec_number'] ?? '');
        $fill_if_empty('bir_registration_number', $latest_application['bir_registration_number'] ?? '');
        $fill_if_empty('mayors_permit', $latest_application['mayors_permit'] ?? '');
        $fill_if_empty('business_address', $latest_application['business_address'] ?? '');
        $fill_if_empty('contact_person', $latest_application['contact_person'] ?? '');
        $fill_if_empty('contact_phone', $latest_application['contact_phone'] ?? '');
        $fill_if_empty('contact_email', $latest_application['contact_email'] ?? '');
        $fill_if_empty('business_experience', $latest_application['business_experience'] ?? '');
        $fill_if_empty('marketing_plan', $latest_application['marketing_plan'] ?? '');

        foreach (['region', 'province', 'city', 'barangay'] as $part) {
            $fill_if_empty('psgc_' . $part . '_code', $latest_application[$part . '_code'] ?? '');
            $fill_if_empty('psgc_' . $part . '_name', $latest_application[$part . '_name'] ?? '');
        }

        $capital = trim((string)($latest_application['capital_investment'] ?? ''));
        if ($capital !== '' && is_numeric($capital)) {
            $capital = rtrim(rtrim(number_format((float)$capital, 2, '.', ''), '0'), '.');
        }
        $fill_if_empty('capital_investment', $capital);
    }

    if ($prefill['business_address'] !== '' && franchiseIsCoordinateOnlyAddress($prefill['business_address'])) {
        $prefill['business_address'] = '';
    }

    if ($prefill['business_address_street'] === '' && $prefill['business_address'] !== '') {
        $prefill['business_address_street'] = franchiseExtractStreetAddress($prefill['business_address']);
    }

    if ($prefill['business_address'] === '' && (
        $prefill['business_address_street'] !== '' ||
        $prefill['psgc_barangay_name'] !== '' ||
        $prefill['psgc_city_name'] !== '' ||
        $prefill['psgc_province_name'] !== '' ||
        $prefill['psgc_region_name'] !== ''
    )) {
        $prefill['business_address'] = buildFranchiseBusinessAddress(
            $prefill['business_address_street'],
            $prefill['psgc_barangay_name'],
            $prefill['psgc_city_name'],
            $prefill['psgc_province_name'],
            $prefill['psgc_region_name']
        );
    }

    if ($prefill['business_type'] === '') {
        $prefill['business_type'] = normalizeFranchiseBusinessTypeValue($_SESSION['business_type'] ?? '', $allowed_business_types);
    }
    if ($prefill['contact_person'] === '') {
        $prefill['contact_person'] = trim((string)($_SESSION['full_name'] ?? ''));
    }
    if ($prefill['contact_email'] === '') {
        $prefill['contact_email'] = trim((string)($_SESSION['email'] ?? ''));
    }

    return $prefill;
}

function iniSizeToBytes($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;

    switch ($unit) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int)$number;
}

$franchise_prefill = buildFranchiseApplicantPrefill($conn, $user_id, $allowed_business_types);

function getFranchiseMaxUploadBytes() {
    $five_mb = 5 * 1024 * 1024;
    $upload_limit = iniSizeToBytes(ini_get('upload_max_filesize'));
    $post_limit = iniSizeToBytes(ini_get('post_max_size'));

    $limits = [];
    if ($upload_limit > 0) {
        $limits[] = $upload_limit;
    }
    if ($post_limit > 0) {
        $limits[] = $post_limit;
    }

    if (empty($limits)) {
        return $five_mb;
    }

    return max(1, min($five_mb, min($limits)));
}

function formatFileSizeLabel($bytes) {
    $bytes = max(0, (int)$bytes);
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . 'MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . 'KB';
    }
    return $bytes . 'B';
}

function tableExists($conn, $table_name) {
    $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $table_name);
    $ok = mysqli_stmt_execute($stmt);
    $exists = false;
    if ($ok) {
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            $exists = mysqli_num_rows($result) > 0;
            mysqli_free_result($result);
        }
    }
    mysqli_stmt_close($stmt);
    return $exists;
}

function franchiseColumnExists($conn, $table_name, $column_name) {
    $query = "SELECT 1
              FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $table_name, $column_name);
    $ok = mysqli_stmt_execute($stmt);
    $exists = false;
    if ($ok) {
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            $exists = mysqli_num_rows($result) > 0;
            mysqli_free_result($result);
        }
    }

    mysqli_stmt_close($stmt);
    return $exists;
}

function franchiseBindParams($stmt, $types, array &$params) {
    $references = [];
    $references[] = &$types;

    foreach ($params as $index => &$value) {
        $references[] = &$value;
    }
    unset($value);

    return call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $references));
}

function resolveSubmissionFailureMessage($conn, $error_msg, $exception, $submission_stage) {
    $error_msg = trim((string)$error_msg);
    if ($error_msg !== '') {
        return $error_msg;
    }

    $runtime_message = trim((string)($exception instanceof Throwable ? $exception->getMessage() : ''));
    if ($runtime_message !== '') {
        if (stripos($runtime_message, 'duplicate') !== false && stripos($runtime_message, 'application_number') !== false) {
            return "A duplicate application number was generated. Please submit again.";
        }
        return $runtime_message;
    }

    $db_error = trim((string)mysqli_error($conn));
    if ($db_error !== '') {
        return "Submission failed during {$submission_stage}: {$db_error}";
    }

    return "Submission failed during {$submission_stage}. Please check server error logs.";
}

function generateUniqueFranchiseApplicationNumber($conn, $user_id) {
    $prefix = 'FR-' . date('Ymd') . '-' . str_pad((string)$user_id, 6, '0', STR_PAD_LEFT) . '-';

    for ($attempt = 0; $attempt < 6; $attempt++) {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(2)));
        } catch (Throwable $e) {
            $suffix = strtoupper(dechex(mt_rand(0, 65535)));
        }
        $candidate = $prefix . str_pad($suffix, 4, '0', STR_PAD_LEFT);

        $check_query = "SELECT id FROM franchise_applications WHERE application_number = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_query);
        if (!$check_stmt) {
            return $candidate;
        }

        mysqli_stmt_bind_param($check_stmt, "s", $candidate);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        $exists = mysqli_stmt_num_rows($check_stmt) > 0;
        mysqli_stmt_close($check_stmt);

        if (!$exists) {
            return $candidate;
        }
    }

    return $prefix . strtoupper(substr(md5(uniqid((string)$user_id, true)), 0, 6));
}

$max_document_size_bytes = getFranchiseMaxUploadBytes();
$max_document_size_label = formatFileSizeLabel($max_document_size_bytes);
$franchise_psgc_columns = [
    'region_name',
    'region_code',
    'province_name',
    'province_code',
    'city_name',
    'city_code',
    'barangay_name',
    'barangay_code'
];
$franchise_psgc_columns_ready = tableExists($conn, 'franchise_applications');
if ($franchise_psgc_columns_ready) {
    foreach ($franchise_psgc_columns as $psgc_column) {
        if (!franchiseColumnExists($conn, 'franchise_applications', $psgc_column)) {
            $franchise_psgc_columns_ready = false;
            break;
        }
    }
}

function getFranchiseReviewerIds($conn) {
    // Strict privacy rule:
    // Franchise submission notifications must be delivered only to super admin
    // (system owner) accounts, never to partner/admin reviewers.
    if (
        !tableExists($conn, 'users')
        || !tableExists($conn, 'roles')
        || !franchiseColumnExists($conn, 'users', 'role_id')
        || !franchiseColumnExists($conn, 'roles', 'name')
    ) {
        error_log('Franchise notification recipient lookup skipped: super admin role schema unavailable.');
        return [];
    }

    $reviewer_ids = [];
    $query = "SELECT DISTINCT u.id
              FROM users u
              INNER JOIN roles r ON u.role_id = r.id
              WHERE u.is_active = 1
                AND r.is_active = 1
                AND r.name = 'super_admin'";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $resolved_id = (int)($row['id'] ?? 0);
            if ($resolved_id > 0) {
                $reviewer_ids[$resolved_id] = true;
            }
        }
        mysqli_free_result($result);
    } else {
        error_log("Franchise super admin lookup failed: " . mysqli_error($conn));
    }

    return array_keys($reviewer_ids);
}

function notifyAdminsOfFranchiseSubmission($conn, $application_id, $application_number, $business_name, $contact_person) {
    try {
        $reviewer_ids = getFranchiseReviewerIds($conn);
    } catch (Throwable $e) {
        error_log("Franchise notification reviewer lookup failed: " . $e->getMessage());
        return;
    }

    if (empty($reviewer_ids)) {
        error_log("Franchise notification skipped: no reviewer recipients found.");
        return;
    }

    $title = "New Franchise Application";
    $message = $business_name . " submitted application " . $application_number . " (" . $contact_person . ").";

    foreach ($reviewer_ids as $reviewer_id) {
        $ok = createNotification(
            $conn,
            intval($reviewer_id),
            'franchise_submitted',
            $title,
            $message,
            intval($application_id),
            'franchise_application'
        );
        if (!$ok) {
            error_log("Franchise notification insert failed for reviewer {$reviewer_id} and application {$application_id}.");
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['submit_application']) || $is_ajax_request)) {
    $transaction_started = false;
    $submission_stage = 'validation';

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_msg = "Your session has expired. Please refresh the page and try again.";
    }

    $form_data = [
        'business_name' => getFormInput('business_name'),
        'business_type' => getFormInput('business_type'),
        'tin_number' => getFormInput('tin_number'),
        'dti_sec_number' => getFormInput('dti_sec_number'),
        'bir_registration_number' => getFormInput('bir_registration_number'),
        'mayors_permit' => getFormInput('mayors_permit'),
        'business_address_street' => getFormInput('business_address_street'),
        'business_address' => getFormInput('business_address'),
        'psgc_region_code' => getFormInput('psgc_region_code'),
        'psgc_region_name' => getFormInput('psgc_region_name'),
        'psgc_province_code' => getFormInput('psgc_province_code'),
        'psgc_province_name' => getFormInput('psgc_province_name'),
        'psgc_city_code' => getFormInput('psgc_city_code'),
        'psgc_city_name' => getFormInput('psgc_city_name'),
        'psgc_barangay_code' => getFormInput('psgc_barangay_code'),
        'psgc_barangay_name' => getFormInput('psgc_barangay_name'),
        'psgc_manual_mode' => getFormInput('psgc_manual_mode') === '1' ? '1' : '0',
        'contact_person' => getFormInput('contact_person'),
        'contact_phone' => getFormInput('contact_phone'),
        'contact_email' => getFormInput('contact_email'),
        'capital_investment' => str_replace([',', ' '], '', getFormInput('capital_investment')),
        'business_experience' => getFormInput('business_experience'),
        'marketing_plan' => getFormInput('marketing_plan')
    ];

    $structured_business_address = buildFranchiseBusinessAddress(
        $form_data['business_address_street'],
        $form_data['psgc_barangay_name'],
        $form_data['psgc_city_name'],
        $form_data['psgc_province_name'],
        $form_data['psgc_region_name']
    );

    if ($form_data['psgc_manual_mode'] !== '1' && $structured_business_address !== '') {
        $form_data['business_address'] = $structured_business_address;
    } elseif ($form_data['business_address'] === '' && $structured_business_address !== '') {
        $form_data['business_address'] = $structured_business_address;
    }

    // Keep DB compatibility while removing the separate proposed location field from the UI.
    $form_data['proposed_location'] = $form_data['business_address'] !== '' ? $form_data['business_address'] : 'To be confirmed during site validation';

    $required_fields = [
        'business_name' => 'Business name',
        'business_type' => 'Business type',
        'tin_number' => 'TIN number',
        'dti_sec_number' => 'DTI/SEC registration number',
        'bir_registration_number' => 'BIR registration number',
        'business_address' => 'Business address',
        'contact_person' => 'Contact person',
        'contact_phone' => 'Contact phone',
        'contact_email' => 'Contact email',
        'capital_investment' => 'Capital investment',
        'business_experience' => 'Business experience',
        'marketing_plan' => 'Marketing plan'
    ];

    if (!$error_msg) {
        foreach ($required_fields as $field_key => $field_label) {
            if ($form_data[$field_key] === '') {
                $error_msg = $field_label . " is required.";
                break;
            }
        }
    }

    $is_ncr_region = (
        $form_data['psgc_region_code'] === '130000000' ||
        stripos($form_data['psgc_region_name'], 'national capital region') !== false ||
        preg_match('/\bncr\b/i', $form_data['psgc_region_name'])
    );

    if (!$error_msg && $form_data['business_address_street'] === '') {
        $error_msg = "Street address / landmark is required.";
    }

    if (!$error_msg && $form_data['psgc_manual_mode'] !== '1') {
        $has_complete_psgc = (
            $form_data['psgc_region_code'] !== '' && $form_data['psgc_region_name'] !== '' &&
            $form_data['psgc_city_code'] !== '' && $form_data['psgc_city_name'] !== '' &&
            $form_data['psgc_barangay_code'] !== '' && $form_data['psgc_barangay_name'] !== '' &&
            ($is_ncr_region || ($form_data['psgc_province_code'] !== '' && $form_data['psgc_province_name'] !== ''))
        );

        if (!$has_complete_psgc) {
            $error_msg = "Please complete the PSGC business location fields (region, city/municipality, and barangay).";
        }
    }

    if (!$error_msg) {
        $is_cavite_scope = false;
        if ($form_data['psgc_manual_mode'] !== '1') {
            $province_name = strtolower(trim((string)$form_data['psgc_province_name']));
            $province_code = trim((string)$form_data['psgc_province_code']);
            $is_cavite_scope = $province_name === 'cavite' || $province_code === '042100000';
        } else {
            $manual_location_blob = strtolower(trim((string)($form_data['business_address'] . ' ' . $form_data['business_address_street'])));
            $is_cavite_scope = strpos($manual_location_blob, 'cavite') !== false;
        }

        if (!$is_cavite_scope) {
            $error_msg = "Business partner applications are currently limited to Cavite locations only.";
        }
    }

    if (!$error_msg && !in_array($form_data['business_type'], $allowed_business_types, true)) {
        $error_msg = "Please select a valid business type.";
    }

    if (!$error_msg && !filter_var($form_data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Please enter a valid contact email address.";
    }

    if (!$error_msg && !preg_match('/^[0-9+\-\s()]{7,20}$/', $form_data['contact_phone'])) {
        $error_msg = "Please enter a valid contact phone number.";
    }

    if (!$error_msg && (!is_numeric($form_data['capital_investment']) || (float)$form_data['capital_investment'] < 100000)) {
        $error_msg = "Capital investment must be at least PHP 100,000.";
    }

    if (!$error_msg) {
        $duplicate_checks = [
            [
                'query' => "SELECT id FROM users WHERE id <> ? AND account_type = 'organization' AND tax_id = ? LIMIT 1",
                'types' => 'is',
                'params' => [$user_id, $form_data['tin_number']],
                'message' => 'This business TIN is already registered in the platform.'
            ],
            [
                'query' => "SELECT id FROM users WHERE id <> ? AND account_type = 'organization' AND business_registration = ? LIMIT 1",
                'types' => 'is',
                'params' => [$user_id, $form_data['dti_sec_number']],
                'message' => 'This DTI/SEC registration number is already registered in the platform.'
            ],
            [
                'query' => "SELECT id FROM franchise_applications WHERE user_id <> ? AND status = 'approved' AND (tin_number = ? OR dti_sec_number = ? OR bir_registration_number = ?) LIMIT 1",
                'types' => 'isss',
                'params' => [$user_id, $form_data['tin_number'], $form_data['dti_sec_number'], $form_data['bir_registration_number']],
                'message' => 'A business with the same tax or registration details is already approved in the platform.'
            ]
        ];

        foreach ($duplicate_checks as $duplicate_check) {
            $stmt = mysqli_prepare($conn, $duplicate_check['query']);
            if (!$stmt) {
                continue;
            }
            $params = $duplicate_check['params'];
            franchiseBindParams($stmt, $duplicate_check['types'], $params);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $exists = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            if ($exists) {
                $error_msg = $duplicate_check['message'];
                break;
            }
        }
    }

    if (!$error_msg && !isset($_POST['acknowledge_tutorial'])) {
        $error_msg = "Please review and confirm the step-by-step registration tutorial.";
    }

    if (!$error_msg && !isset($_POST['agree_terms'])) {
        $error_msg = "You must agree to the terms and conditions before submitting.";
    }

    try {
        if (!$error_msg) {
            $submission_stage = 'session account validation';
            $existing_user = getUserByIdForSession($conn, $user_id);
            if (!$existing_user) {
                throw new RuntimeException('Your account could not be verified. Please sign in again and resubmit your application.');
            }

            $submission_stage = 'application workflow validation';
            $franchise_workflow = getFranchiseWorkflowState($conn, $user_id);
            $latest_application = $franchise_workflow['latest_application'];
            $has_pending_application = $franchise_workflow['stage'] === 'pending_review';
            if (!$franchise_workflow['can_submit']) {
                if ($franchise_workflow['stage'] === 'reapply_cooldown' && !empty($franchise_workflow['next_eligible_at'])) {
                    $error_msg = $franchise_workflow['message'] . ' You can apply again on ' . date('F j, Y g:i A', strtotime((string)$franchise_workflow['next_eligible_at'])) . '.';
                } elseif ($franchise_workflow['stage'] === 'approved_partner' && !empty($franchise_workflow['approved_trial_ends_at'])) {
                    $error_msg = $franchise_workflow['message'] . ' Trial access ends on ' . date('F j, Y', strtotime((string)$franchise_workflow['approved_trial_ends_at'])) . '.';
                } else {
                    $error_msg = $franchise_workflow['message'];
                }
            }

            if (!$error_msg) {
                $submission_stage = 'duplicate application check';
                // Check if user already has a pending application at write time.
                $check_query = "SELECT id FROM franchise_applications WHERE user_id = ? AND status = 'pending'";
                $stmt = mysqli_prepare($conn, $check_query);
                if (!$stmt) {
                    throw new RuntimeException("Unable to prepare duplicate application check.");
                }

                mysqli_stmt_bind_param($stmt, "i", $user_id);
                if (!mysqli_stmt_execute($stmt)) {
                    $dup_error = trim((string)mysqli_stmt_error($stmt));
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($dup_error !== '' ? $dup_error : 'Unable to verify existing pending applications.');
                }
                if (!mysqli_stmt_store_result($stmt)) {
                    $dup_error = trim((string)mysqli_stmt_error($stmt));
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($dup_error !== '' ? $dup_error : 'Unable to read pending application status.');
                }
                $has_pending_application = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);

                if ($has_pending_application) {
                    $error_msg = "You already have a pending business application.";
                }
            }

            if (!$error_msg) {
                $submission_stage = 'application number generation';
                // Generate collision-safe application number
                $application_number = generateUniqueFranchiseApplicationNumber($conn, $user_id);

                $submission_stage = 'transaction start';
                if (!mysqli_begin_transaction($conn)) {
                    throw new RuntimeException('Unable to start application transaction.');
                }
                $transaction_started = true;

                $submission_stage = 'application insert';
                if ($franchise_psgc_columns_ready) {
                    $insert_query = "INSERT INTO franchise_applications (
                        application_number, user_id, business_name, business_type,
                        tin_number, dti_sec_number, bir_registration_number, mayors_permit,
                        business_address, proposed_location,
                        region_name, region_code, province_name, province_code,
                        city_name, city_code, barangay_name, barangay_code,
                        contact_person, contact_phone, contact_email, capital_investment,
                        business_experience, marketing_plan, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                } else {
                    // Backward compatible insert for databases that have not yet applied PSGC schema updates.
                    $insert_query = "INSERT INTO franchise_applications (
                        application_number, user_id, business_name, business_type,
                        tin_number, dti_sec_number, bir_registration_number, mayors_permit,
                        business_address, proposed_location, contact_person,
                        contact_phone, contact_email, capital_investment,
                        business_experience, marketing_plan, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                }

                $stmt = mysqli_prepare($conn, $insert_query);
                if (!$stmt) {
                    throw new RuntimeException("Unable to prepare franchise application insert query.");
                }

                $capital_investment = (float)$form_data['capital_investment'];
                if ($franchise_psgc_columns_ready) {
                    $insert_params = [
                        $application_number,
                        $user_id,
                        $form_data['business_name'],
                        $form_data['business_type'],
                        $form_data['tin_number'],
                        $form_data['dti_sec_number'],
                        $form_data['bir_registration_number'],
                        $form_data['mayors_permit'],
                        $form_data['business_address'],
                        $form_data['proposed_location'],
                        $form_data['psgc_region_name'],
                        $form_data['psgc_region_code'],
                        $form_data['psgc_province_name'],
                        $form_data['psgc_province_code'],
                        $form_data['psgc_city_name'],
                        $form_data['psgc_city_code'],
                        $form_data['psgc_barangay_name'],
                        $form_data['psgc_barangay_code'],
                        $form_data['contact_person'],
                        $form_data['contact_phone'],
                        $form_data['contact_email'],
                        $capital_investment,
                        $form_data['business_experience'],
                        $form_data['marketing_plan']
                    ];
                    $insert_types = 'si' . str_repeat('s', 19) . 'dss';
                } else {
                    $insert_params = [
                        $application_number,
                        $user_id,
                        $form_data['business_name'],
                        $form_data['business_type'],
                        $form_data['tin_number'],
                        $form_data['dti_sec_number'],
                        $form_data['bir_registration_number'],
                        $form_data['mayors_permit'],
                        $form_data['business_address'],
                        $form_data['proposed_location'],
                        $form_data['contact_person'],
                        $form_data['contact_phone'],
                        $form_data['contact_email'],
                        $capital_investment,
                        $form_data['business_experience'],
                        $form_data['marketing_plan']
                    ];
                    $insert_types = 'si' . str_repeat('s', 11) . 'dss';
                }

                if (!franchiseBindParams($stmt, $insert_types, $insert_params)) {
                    $bind_error = trim((string)mysqli_stmt_error($stmt));
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($bind_error !== '' ? $bind_error : 'Unable to bind franchise application insert parameters.');
                }

                if (!mysqli_stmt_execute($stmt)) {
                    $insert_error = trim((string)mysqli_stmt_error($stmt));
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($insert_error !== '' ? $insert_error : 'Unable to save franchise application.');
                }
                $application_id = mysqli_insert_id($conn);
                if ($application_id <= 0) {
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException('Application was not saved correctly. Please try again.');
                }
                mysqli_stmt_close($stmt);

                $submission_stage = 'document upload';
                // Handle file uploads and fail fast on upload/document issues
                $upload_result = handleFileUploads(
                    $conn,
                    $application_id,
                    $max_document_size_bytes,
                    [
                        'contact_person' => (string)$form_data['contact_person'],
                        'contact_email' => (string)$form_data['contact_email'],
                        'business_name' => (string)$form_data['business_name']
                    ]
                );
                if (!$upload_result['success']) {
                    $upload_error = trim((string)($upload_result['message'] ?? ''));
                    throw new RuntimeException($upload_error !== '' ? $upload_error : 'Document upload validation failed.');
                }

                $submission_stage = 'transaction commit';
                if (!mysqli_commit($conn)) {
                    throw new RuntimeException('Application saved but commit failed. Please retry.');
                }
                $transaction_started = false;
                $submission_stage = 'admin notification';
                try {
                    notifyAdminsOfFranchiseSubmission(
                        $conn,
                        $application_id,
                        $application_number,
                        $form_data['business_name'],
                        $form_data['contact_person']
                    );
                } catch (Throwable $notification_error) {
                    error_log("Franchise notification failed after submit {$application_id}: " . $notification_error->getMessage());
                }
                $has_pending_application = true;
                $latest_application = [
                    'id' => $application_id,
                    'application_number' => $application_number,
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $new_total_attempts = max(1, (int)($franchise_workflow['total_attempts'] ?? 0) + 1);
                $franchise_workflow = [
                    'stage' => 'pending_review',
                    'can_submit' => false,
                    'message' => 'You already have a pending franchise application under review.',
                    'remaining_attempts' => max(0, 2 - $new_total_attempts),
                    'total_attempts' => $new_total_attempts,
                    'next_eligible_at' => null,
                    'latest_application' => $latest_application,
                    'approved_trial_ends_at' => null
                ];

                $success_msg = "Business application submitted successfully!<br>Your application number is: <strong>" . $application_number . "</strong><br><a href='my_account.php' style='color:#155724;text-decoration:underline;font-weight:600;'>View application status in My Account</a>";
                $swal_alert = [
                    'icon' => 'success',
                    'title' => 'Application Submitted',
                    'text' => 'Your application was sent successfully. The system owner has been notified.'
                ];
                $_POST = [];
            }
        }
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($conn);
        }

        error_log("Franchise application submission failed for user {$user_id} at stage {$submission_stage}: " . $e->getMessage());
        $error_msg = resolveSubmissionFailureMessage($conn, $error_msg, $e, $submission_stage);
        $swal_alert = [
            'icon' => 'error',
            'title' => 'Submission Failed',
            'text' => $error_msg
        ];
    }

    if ($error_msg && !$swal_alert) {
        $swal_alert = [
            'icon' => 'error',
            'title' => 'Validation Error',
            'text' => $error_msg
        ];
    }

    if ($is_ajax_request) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $error_msg === '',
            'message' => $error_msg === ''
                ? ('Business application submitted successfully. Application Number: ' . $application_number)
                : $error_msg,
            'application_number' => $application_number,
            'has_pending_application' => $has_pending_application,
            'latest_application' => $latest_application,
            'workflow' => $franchise_workflow
        ]);
        mysqli_close($conn);
        exit;
    }
}

$page_title = "Business Application | Lechon Delights";
include 'includes/header.php';
?>

<div class="franchise-application-page">
    <div class="container">
        <section class="application-hero">
            <div class="application-hero-content">
                <span class="hero-kicker"><i class="fas fa-store"></i> Lechon Delights Partner Program</span>
                <h1>Launch Your Business With A Trusted Lechon Brand</h1>
                <p class="application-subtitle">Submit your franchise application, upload requirements, and track every review step from your account dashboard.</p>
                <div class="hero-chip-row">
                    <span class="hero-chip"><i class="fas fa-shield-alt"></i> Secure document screening</span>
                    <span class="hero-chip"><i class="fas fa-bolt"></i> Faster admin routing</span>
                    <span class="hero-chip"><i class="fas fa-chart-line"></i> Built for scale operations</span>
                </div>
            </div>
            <div class="application-hero-metrics">
                <div class="metric-card">
                    <small>Minimum Capital</small>
                    <strong>PHP 100K</strong>
                </div>
                <div class="metric-card">
                    <small>Validation Stage</small>
                    <strong>Live API Check</strong>
                </div>
                <div class="metric-card">
                    <small>Tracking</small>
                    <strong>My Account Status</strong>
                </div>
            </div>
        </section>

        <section class="application-playbook">
            <div class="playbook-head">
                <h2><i class="fas fa-map-signs"></i> Step-by-Step Registration Tutorial</h2>
                <p>Follow these compliance steps in order. Upload what you already have now, then complete missing permits during review.</p>
            </div>
            <div class="playbook-grid">
                <article class="playbook-step">
                    <span class="playbook-step-number">1</span>
                    <h3>Business Entity Registration</h3>
                    <p>Register your business with the correct national agency based on your business type.</p>
                    <ul>
                        <li><strong>DTI:</strong> Sole proprietorship business name registration.</li>
                        <li><strong>SEC:</strong> Partnership or corporation registration.</li>
                        <li><strong>CDA:</strong> Cooperative registration.</li>
                    </ul>
                </article>
                <article class="playbook-step">
                    <span class="playbook-step-number">2</span>
                    <h3>LGU Permits And Clearance</h3>
                    <p>After national registration, secure local permits from your city or municipality.</p>
                    <ul>
                        <li>Barangay Clearance</li>
                        <li>Mayor's Permit (Business Permit)</li>
                        <li>Contract of Lease or Tax Declaration/Title</li>
                        <li>Certificate of Occupancy and Fire Safety Inspection Certificate</li>
                        <li>Cedula and Sanitary Permit</li>
                    </ul>
                </article>
                <article class="playbook-step">
                    <span class="playbook-step-number">3</span>
                    <h3>Tax And Statutory Registration</h3>
                    <p>Complete your tax setup and mandatory government registrations.</p>
                    <ul>
                        <li><strong>BIR:</strong> TIN and Certificate of Registration (Form 1901 / 1903).</li>
                        <li><strong>SSS, PhilHealth, Pag-IBIG:</strong> Required if hiring employees.</li>
                        <li><strong>Industry Licenses:</strong> FDA, BSP, and others where applicable.</li>
                    </ul>
                </article>
            </div>
            <div class="playbook-alert">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Penalty Reminder:</strong> Non-compliance can trigger fines such as PHP 20,000 for non-registration, PHP 10,000 to PHP 50,000 for invalid receipts, and PHP 1,000 to PHP 50,000 for record/tax filing violations.
                </div>
            </div>
        </section>
        
        <?php if ($success_msg): ?>
        <div class="alert alert-success" style="background-color: #d4edda; border: 2px solid #28a745; border-radius: 8px; padding: 20px; color: #155724; font-size: 1.05rem;">
            <i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i> <?php echo $success_msg; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_msg): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
        </div>
        <?php endif; ?>
        
        <div class="application-container">
            <div class="application-form-container">
                <div class="form-header">
                    <h2>Apply for a Lechon Delights Business Partnership</h2>
                    <p>Complete each step below. Every section includes guidance so your application is clean, complete, and review-ready.</p>
                </div>

                <div class="application-workflow-card">
                    <div class="workflow-pill-row">
                        <span class="workflow-pill"><i class="fas fa-layer-group"></i> Total Attempts: <?php echo (int)$franchise_workflow['total_attempts']; ?>/2</span>
                        <span class="workflow-pill"><i class="fas fa-hourglass-half"></i> Remaining Attempts: <?php echo (int)$franchise_workflow['remaining_attempts']; ?></span>
                        <?php if (!empty($franchise_workflow['approved_trial_ends_at'])): ?>
                            <span class="workflow-pill"><i class="fas fa-calendar-check"></i> Trial Ends: <?php echo date('F j, Y', strtotime((string)$franchise_workflow['approved_trial_ends_at'])); ?></span>
                        <?php elseif (!empty($franchise_workflow['next_eligible_at'])): ?>
                            <span class="workflow-pill"><i class="fas fa-clock"></i> Reapply On: <?php echo date('F j, Y g:i A', strtotime((string)$franchise_workflow['next_eligible_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="workflow-steps-grid">
                        <div class="workflow-step-card <?php echo $franchise_workflow['total_attempts'] === 0 ? 'current' : 'done'; ?>">
                            <strong>Attempt 1</strong>
                            <span>Submit your first franchise application with complete documents.</span>
                        </div>
                        <div class="workflow-step-card <?php echo in_array($franchise_workflow['stage'], ['reapply_cooldown', 'final_retry_available', 'max_attempts_reached'], true) ? 'current' : ''; ?>">
                            <strong>3-Day Cooldown</strong>
                            <span>If rejected, wait 3 days before the final reapplication.</span>
                        </div>
                        <div class="workflow-step-card <?php echo $franchise_workflow['remaining_attempts'] === 1 ? 'current' : ($franchise_workflow['total_attempts'] >= 2 ? 'done' : ''); ?>">
                            <strong>Final Attempt</strong>
                            <span>You may only submit one more application after a rejection.</span>
                        </div>
                        <div class="workflow-step-card <?php echo in_array($franchise_workflow['stage'], ['approved_partner', 'already_registered_partner'], true) ? 'current' : ''; ?>">
                            <strong>Approval Trial</strong>
                            <span>Approved partners receive a 1-month trial and cannot register again.</span>
                        </div>
                    </div>
                    <p class="workflow-summary"><?php echo htmlspecialchars((string)($franchise_workflow['message'] ?? '')); ?></p>
                </div>
                
                <?php if (!$franchise_workflow['can_submit'] && !$success_msg): ?>
                <div class="alert" style="background:#fff8e1;border:1px solid #ffd54f;color:#8a6d3b;border-radius:10px;padding:16px;margin-bottom:22px;">
                    <i class="fas fa-route" style="margin-right:8px;color:#f57f17;"></i>
                    <?php echo htmlspecialchars((string)($franchise_workflow['message'] ?? 'Application workflow is currently restricted.')); ?>
                    <?php if (!empty($latest_application['application_number'])): ?>
                        <br>
                        Latest application:
                        <strong><?php echo htmlspecialchars((string)$latest_application['application_number']); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($latest_application['created_at'])): ?>
                        <br>
                        Submitted on <?php echo date('F j, Y g:i a', strtotime((string)$latest_application['created_at'])); ?>.
                    <?php endif; ?>
                    <?php if (!empty($franchise_workflow['next_eligible_at'])): ?>
                        <br>
                        You may apply again on <?php echo date('F j, Y g:i a', strtotime((string)$franchise_workflow['next_eligible_at'])); ?>.
                    <?php endif; ?>
                    <?php if (!empty($franchise_workflow['approved_trial_ends_at'])): ?>
                        <br>
                        Your partner trial ends on <?php echo date('F j, Y', strtotime((string)$franchise_workflow['approved_trial_ends_at'])); ?>.
                    <?php endif; ?>
                </div>
                <div class="form-actions">
                    <a href="my_account.php" class="btn-primary btn-large" style="text-decoration:none;">
                        <i class="fas fa-user-circle"></i> Go to My Account
                    </a>
                    <?php if (in_array($franchise_workflow['stage'], ['approved_partner', 'already_registered_partner'], true)): ?>
                        <a href="admin/index.php" class="btn-secondary btn-large" style="text-decoration:none;">
                            <i class="fas fa-store"></i> Open Partner Dashboard
                        </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <form method="POST" action="" enctype="multipart/form-data" class="application-form" id="franchiseForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <!-- Step 1: Business Information -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 1</span> <i class="fas fa-building"></i> Business Information</h3>
                        <p class="section-description">Use your registered business name and official business address.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="business_name">Business Name *</label>
                                <input type="text" id="business_name" name="business_name" required
                                    value="<?php echo oldFormValue('business_name', $franchise_prefill['business_name'] ?? ''); ?>"
                                    placeholder="Enter your business name">
                            </div>
                            <div class="form-group">
                                <label for="business_type">Business Type *</label>
                                <select id="business_type" name="business_type" required>
                                    <option value="">Select business type</option>
                                    <option value="sole_proprietorship" <?php echo isOldSelected('business_type', 'sole_proprietorship', $franchise_prefill['business_type'] ?? ''); ?>>Sole Proprietorship</option>
                                    <option value="partnership" <?php echo isOldSelected('business_type', 'partnership', $franchise_prefill['business_type'] ?? ''); ?>>Partnership</option>
                                    <option value="corporation" <?php echo isOldSelected('business_type', 'corporation', $franchise_prefill['business_type'] ?? ''); ?>>Corporation</option>
                                    <option value="llc" <?php echo isOldSelected('business_type', 'llc', $franchise_prefill['business_type'] ?? ''); ?>>LLC</option>
                                </select>
                            </div>
                        </div>
                        
                        <input type="hidden" name="psgc_region_name" id="psgcRegionName" value="<?php echo oldFormValue('psgc_region_name', $franchise_prefill['psgc_region_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_province_name" id="psgcProvinceName" value="<?php echo oldFormValue('psgc_province_name', $franchise_prefill['psgc_province_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_city_name" id="psgcCityName" value="<?php echo oldFormValue('psgc_city_name', $franchise_prefill['psgc_city_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_barangay_name" id="psgcBarangayName" value="<?php echo oldFormValue('psgc_barangay_name', $franchise_prefill['psgc_barangay_name'] ?? ''); ?>">
                        <input type="hidden" name="psgc_manual_mode" id="psgcManualMode" value="<?php echo oldFormValue('psgc_manual_mode', $franchise_prefill['psgc_manual_mode'] ?? '0'); ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="business_address_street">Street Address / Landmark *</label>
                                <textarea id="business_address_street" name="business_address_street" rows="2" required
                                        placeholder="House no., street, subdivision, landmark"><?php echo oldFormValue('business_address_street', $franchise_prefill['business_address_street'] ?? ''); ?></textarea>
                                <small class="form-text">Add your exact street/landmark, then select PSGC location fields below. Business partner applications are currently accepted for Cavite locations only.</small>
                            </div>
                        </div>

                        <div class="form-row psgc-row">
                            <div class="form-group">
                                <label for="psgcRegion">Region (PSGC) *</label>
                                <select id="psgcRegion" name="psgc_region_code" required data-selected="<?php echo oldFormValue('psgc_region_code', $franchise_prefill['psgc_region_code'] ?? ''); ?>">
                                    <option value="">Select region</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="psgcProvince">Province *</label>
                                <select id="psgcProvince" name="psgc_province_code" required data-selected="<?php echo oldFormValue('psgc_province_code', $franchise_prefill['psgc_province_code'] ?? ''); ?>" disabled>
                                    <option value="">Select province</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row psgc-row">
                            <div class="form-group">
                                <label for="psgcCity">City / Municipality *</label>
                                <select id="psgcCity" name="psgc_city_code" required data-selected="<?php echo oldFormValue('psgc_city_code', $franchise_prefill['psgc_city_code'] ?? ''); ?>" disabled>
                                    <option value="">Select city / municipality</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="psgcBarangay">Barangay *</label>
                                <select id="psgcBarangay" name="psgc_barangay_code" required data-selected="<?php echo oldFormValue('psgc_barangay_code', $franchise_prefill['psgc_barangay_code'] ?? ''); ?>" disabled>
                                    <option value="">Select barangay</option>
                                </select>
                            </div>
                        </div>

                        <p class="psgc-help" id="psgcAddressHelp">Select PSGC fields so admin can validate your exact Cavite business location faster.</p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="business_address">Business Address (Auto-composed) *</label>
                                <textarea id="business_address" name="business_address" rows="3" required readonly
                                        placeholder="Your complete business address will be generated from the PSGC location and street"><?php echo oldFormValue('business_address', $franchise_prefill['business_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Registration Documents -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 2</span> <i class="fas fa-file-alt"></i> Registration Details</h3>
                        <p class="section-description">This aligns with Step 1 and Step 3 of the tutorial: entity registration + tax registration.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="dti_sec_number">DTI/SEC Registration Number *</label>
                                <input type="text" id="dti_sec_number" name="dti_sec_number" required
                                    value="<?php echo oldFormValue('dti_sec_number', $franchise_prefill['dti_sec_number'] ?? ''); ?>"
                                    placeholder="DTI or SEC registration number">
                                <small class="form-text">For sole proprietorship: DTI Certificate | For partnership/corporation: SEC Registration</small>
                            </div>
                            <div class="form-group">
                                <label for="tin_number">Tax Identification Number (TIN) *</label>
                                <input type="text" id="tin_number" name="tin_number" required
                                    value="<?php echo oldFormValue('tin_number', $franchise_prefill['tin_number'] ?? ''); ?>"
                                    placeholder="Business TIN">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="bir_registration_number">BIR Registration Number *</label>
                                <input type="text" id="bir_registration_number" name="bir_registration_number" required
                                    value="<?php echo oldFormValue('bir_registration_number', $franchise_prefill['bir_registration_number'] ?? ''); ?>"
                                    placeholder="BIR registration number">
                            </div>
                            <div class="form-group">
                                <label for="mayors_permit">Mayor's Permit/Business Permit</label>
                                <input type="text" id="mayors_permit" name="mayors_permit"
                                    value="<?php echo oldFormValue('mayors_permit', $franchise_prefill['mayors_permit'] ?? ''); ?>"
                                    placeholder="LGU Business Permit Number">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Contact Information -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 3</span> <i class="fas fa-address-book"></i> Contact Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="contact_person">Contact Person *</label>
                                <input type="text" id="contact_person" name="contact_person" required
                                    value="<?php echo oldFormValue('contact_person', $franchise_prefill['contact_person'] ?? ($_SESSION['full_name'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="contact_phone">Contact Phone *</label>
                                <input type="tel" id="contact_phone" name="contact_phone" required
                                    value="<?php echo oldFormValue('contact_phone', $franchise_prefill['contact_phone'] ?? ''); ?>"
                                    placeholder="0912-345-6789">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="contact_email">Contact Email *</label>
                                <input type="email" id="contact_email" name="contact_email" required
                                    value="<?php echo oldFormValue('contact_email', $franchise_prefill['contact_email'] ?? ($_SESSION['email'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="capital_investment">Capital Investment (PHP) *</label>
                                <input type="number" id="capital_investment" name="capital_investment" required
                                    value="<?php echo oldFormValue('capital_investment', $franchise_prefill['capital_investment'] ?? ''); ?>"
                                    min="100000" step="10000" placeholder="500000">
                                <small class="form-text">Estimated capital you plan to invest</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 4: Business Background -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 4</span> <i class="fas fa-chart-line"></i> Business Background</h3>
                        
                        <div class="form-group">
                            <label for="business_experience">Business Experience *</label>
                            <textarea id="business_experience" name="business_experience" rows="4" required
                                    placeholder="Describe your previous business experience, if any"><?php echo oldFormValue('business_experience', $franchise_prefill['business_experience'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="marketing_plan">Marketing Plan *</label>
                            <textarea id="marketing_plan" name="marketing_plan" rows="4" required
                                    placeholder="How do you plan to market your business?"><?php echo oldFormValue('marketing_plan', $franchise_prefill['marketing_plan'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Step 5: Documents Upload -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 5</span> <i class="fas fa-paperclip"></i> Documents Upload</h3>
                        <p class="section-description">Upload required files first, then optional compliance documents to speed up review. PhilSys-compatible verification is applied to government papers when configured.</p>
                        <div id="docRequirementStatus" class="doc-requirement-status info">Waiting for required documents upload...</div>
                        
                        <div class="documents-grid">
                            <div class="document-item required-highlight">
                                <label class="document-label">
                                    <span>Business Logo *</span>
                                    <input type="file" name="business_logo" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small>Official business logo (PNG/JPG/PDF)</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>DTI/SEC Certificate *</span>
                                    <input type="file" name="dti_doc" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small>PDF, JPG, or PNG format</small>
                                </label>
                            </div>
                            
                            <div class="document-item">
                                <label class="document-label">
                                    <span>BIR Registration *</span>
                                    <input type="file" name="bir_doc" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small>PDF, JPG, or PNG format</small>
                                </label>
                            </div>
                            
                            <div class="document-item">
                                <label class="document-label">
                                    <span>Mayor's Permit</span>
                                    <input type="file" name="mayor_doc" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>PDF, JPG, or PNG format</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Barangay Clearance</span>
                                    <input type="file" name="barangay_clearance" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Issued by Barangay Hall</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Lease Contract / Title</span>
                                    <input type="file" name="lease_or_title" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Proof of occupancy or ownership</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Certificate of Occupancy</span>
                                    <input type="file" name="occupancy_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Include map/photo support if available</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Fire Safety Certificate</span>
                                    <input type="file" name="fire_safety_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Fire Permit or inspection certificate</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Community Tax Certificate (Cedula)</span>
                                    <input type="file" name="community_tax_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Cedula document</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Sanitary Permit</span>
                                    <input type="file" name="sanitary_permit" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Required for food and beverage operations</small>
                                </label>
                            </div>
                            
                            <div class="document-item">
                                <label class="document-label">
                                    <span>Valid ID of Owner *</span>
                                    <input type="file" name="valid_id" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small>Driver's License, Passport, Postal ID, or PhilID</small>
                                </label>
                            </div>
                            
                            <div class="document-item">
                                <label class="document-label">
                                    <span>Proof of Address *</span>
                                    <input type="file" name="address_proof" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small>Utility bill or barangay clearance</small>
                                </label>
                            </div>
                            
                            <div class="document-item">
                                <label class="document-label">
                                    <span>Business Bank Account Proof</span>
                                    <input type="file" name="bank_proof" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Bank certificate or statement</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>BIR Form 1901/1903</span>
                                    <input type="file" name="bir_form" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Use 1901 for sole prop, 1903 for corp/partnership</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>SSS Registration</span>
                                    <input type="file" name="sss_registration" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Mandatory when hiring employees</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>PhilHealth Registration</span>
                                    <input type="file" name="philhealth_registration" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Mandatory when hiring employees</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Pag-IBIG Registration</span>
                                    <input type="file" name="pagibig_registration" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>Mandatory when hiring employees</small>
                                </label>
                            </div>

                            <div class="document-item">
                                <label class="document-label">
                                    <span>Industry-Specific Permit</span>
                                    <input type="file" name="industry_permit" accept=".pdf,.jpg,.jpeg,.png">
                                    <small>For FDA, BSP, or other sector-specific licenses</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 6: Confirmations -->
                    <div class="form-section">
                        <h3><span class="step-tag">Step 6</span> <i class="fas fa-check-double"></i> Confirmations</h3>
                        <div class="tutorial-agreement">
                            <input type="checkbox" id="acknowledge_tutorial" name="acknowledge_tutorial" required <?php echo isset($_POST['acknowledge_tutorial']) ? 'checked' : ''; ?>>
                            <label for="acknowledge_tutorial">
                                I reviewed the 3-step registration tutorial (Entity Registration, LGU Permits, and Tax/Statutory Registration) and understand that incomplete permits can delay or reject approval.
                            </label>
                        </div>

                        <div class="terms-agreement">
                            <input type="checkbox" id="agree_terms" name="agree_terms" required <?php echo isset($_POST['agree_terms']) ? 'checked' : ''; ?>>
                            <label for="agree_terms">
                                I hereby certify that all information provided is true and correct. 
                                I understand that submitting false information may result in rejection of my application. 
                                I agree to the <a href="franchise_terms.php" target="_blank">Franchise Terms and Conditions</a>.
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-outline" onclick="window.history.back()">Cancel</button>
                        <button type="submit" name="submit_application" class="btn-primary btn-large">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            
            <!-- Requirements Sidebar -->
            <div class="requirements-sidebar">
                <div class="requirements-card">
                    <h3><i class="fas fa-clipboard-check"></i> Compliance Guide</h3>
                    <div class="requirements-list compact">
                        <div class="requirement-item">
                            <i class="fas fa-flag-checkered"></i>
                            <div>
                                <strong>Submit Mandatory Files</strong>
                                <small>Business Logo, DTI/SEC, BIR, Valid ID, and Proof of Address are required.</small>
                            </div>
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-city"></i>
                            <div>
                                <strong>LGU Documents</strong>
                                <small>Add Barangay, Fire Safety, Sanitary, and Cedula files to reduce follow-up requests.</small>
                            </div>
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-users"></i>
                            <div>
                                <strong>Employee Statutory Setup</strong>
                                <small>If hiring, prepare SSS, PhilHealth, and Pag-IBIG registrations.</small>
                            </div>
                        </div>
                    </div>

                    <div class="penalty-card">
                        <h4><i class="fas fa-gavel"></i> Penalties For Non-Compliance</h4>
                        <ul>
                            <li>PHP 20,000 for failure to register the business</li>
                            <li>PHP 10,000 to PHP 50,000 for invalid receipts/invoices</li>
                            <li>PHP 1,000 to PHP 50,000 for records and late tax filing violations</li>
                        </ul>
                    </div>
                    
                    <div class="support-info">
                        <h4>What We Provide</h4>
                        <ul>
                            <li>Application review and compliance guidance</li>
                            <li>Operations onboarding and staff training</li>
                            <li>Marketing support and brand assets</li>
                            <li>Supply chain and business continuity support</li>
                        </ul>
                    </div>
                    
                    <div class="contact-support">
                        <h4>Need Help?</h4>
                        <p>Contact our Business Partnership Team:</p>
                        <p><i class="fas fa-phone"></i> (02) 8123-4567</p>
                        <p><i class="fas fa-envelope"></i> franchise@lechondelights.com</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.franchise-application-page {
    --food-red: #b3261e;
    --food-orange: #ef6b2e;
    --food-ink: #2f1a12;
    --food-cream: #fff4e5;
    --food-gold: #f9a93f;
    --food-shadow: 0 20px 44px rgba(60, 34, 21, 0.12);
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
    padding: 88px 0 64px;
    background:
        radial-gradient(circle at 90% -10%, rgba(239, 107, 46, 0.22), transparent 42%),
        radial-gradient(circle at -10% 10%, rgba(179, 38, 30, 0.12), transparent 34%),
        linear-gradient(180deg, #fffaf2 0%, #fff4e8 46%, #fffdf9 100%);
    min-height: 100vh;
}

.application-hero {
    display: grid;
    grid-template-columns: 1.75fr 1fr;
    gap: 24px;
    padding: 28px;
    border-radius: 20px;
    margin-bottom: 24px;
    background: linear-gradient(135deg, rgba(179, 38, 30, 0.94), rgba(239, 107, 46, 0.92));
    box-shadow: 0 22px 40px rgba(101, 34, 16, 0.28);
    color: #fff;
}

.hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 999px;
    padding: 7px 13px;
    margin-bottom: 14px;
}

.application-hero h1 {
    margin: 0 0 10px;
    line-height: 1.2;
    color: #fff;
    font-size: clamp(1.35rem, 2.3vw, 2rem);
}

.application-hero .application-subtitle {
    margin: 0;
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.97rem;
    line-height: 1.55;
}

.hero-chip-row {
    margin-top: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hero-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.14);
}

.application-hero-metrics {
    display: grid;
    gap: 12px;
}

.application-playbook {
    margin-bottom: 24px;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(255, 249, 241, 0.95));
    border: 1px solid #f0dbc7;
    border-radius: 20px;
    padding: 24px;
    box-shadow: var(--food-shadow);
}

.playbook-head h2 {
    margin: 0 0 8px;
    color: #4a2618;
    font-size: clamp(1.2rem, 2vw, 1.45rem);
    display: flex;
    align-items: center;
    gap: 10px;
}

.playbook-head p {
    margin: 0 0 18px;
    color: #6f5244;
    font-size: 0.94rem;
}

.playbook-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.playbook-step {
    position: relative;
    padding: 18px 16px 16px;
    background: #fff;
    border: 1px solid #f2e1d1;
    border-radius: 16px;
    animation: riseIn 0.5s ease both;
}

.playbook-step:nth-child(2) {
    animation-delay: 0.06s;
}

.playbook-step:nth-child(3) {
    animation-delay: 0.12s;
}

.playbook-step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 999px;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, #ca3b22, #f0802a);
    margin-bottom: 8px;
}

.playbook-step h3 {
    margin: 0 0 8px;
    color: #4a2618;
    font-size: 1rem;
}

.playbook-step p {
    margin: 0 0 10px;
    color: #7a5a4a;
    font-size: 0.9rem;
}

.playbook-step ul {
    margin: 0;
    padding-left: 18px;
    color: #6c4b3b;
    font-size: 0.86rem;
    line-height: 1.45;
}

.playbook-step li + li {
    margin-top: 4px;
}

.playbook-alert {
    margin-top: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border: 1px solid #ffd9a6;
    background: linear-gradient(180deg, #fff8ef 0%, #fff4e5 100%);
    border-radius: 14px;
    padding: 14px 16px;
    color: #7a4b20;
    font-size: 0.9rem;
}

.playbook-alert i {
    color: #de7a1d;
    margin-top: 2px;
}

.metric-card {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.28);
    border-radius: 14px;
    padding: 14px;
    backdrop-filter: blur(3px);
}

.metric-card small {
    display: block;
    opacity: 0.84;
    font-size: 0.78rem;
    margin-bottom: 6px;
    letter-spacing: 0.02em;
}

.metric-card strong {
    font-size: 1rem;
    letter-spacing: 0.02em;
}

.application-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    max-width: 1400px;
    margin: 0 auto;
}

.application-form-container {
    background: linear-gradient(180deg, #fffefa 0%, #ffffff 100%);
    border-radius: 20px;
    padding: 32px;
    border: 1px solid #f3e4d7;
    box-shadow: var(--food-shadow);
}

.form-header {
    margin-bottom: 34px;
    text-align: left;
    border-bottom: 1px dashed #f0ddd0;
    padding-bottom: 16px;
}

.form-header h2 {
    color: var(--food-ink);
    margin-bottom: 8px;
    font-size: 1.45rem;
}

.form-header p {
    color: #7a5c4b;
    font-size: 0.95rem;
    margin: 0;
}

.application-workflow-card {
    margin-bottom: 26px;
    padding: 20px;
    border-radius: 18px;
    border: 1px solid #f3d6c4;
    background: linear-gradient(135deg, #fff7f1 0%, #ffffff 100%);
}

.workflow-pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
}

.workflow-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #ecd4c4;
    color: #7a4c33;
    font-size: 0.82rem;
    font-weight: 700;
}

.workflow-steps-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.workflow-step-card {
    border-radius: 16px;
    border: 1px solid #f1dfd2;
    background: rgba(255, 255, 255, 0.9);
    padding: 14px;
    min-height: 112px;
}

.workflow-step-card strong {
    display: block;
    color: #6d2b18;
    margin-bottom: 8px;
    font-size: 0.93rem;
}

.workflow-step-card span {
    display: block;
    color: #7b675a;
    font-size: 0.84rem;
    line-height: 1.45;
}

.workflow-step-card.current {
    border-color: #ef7d2a;
    box-shadow: 0 10px 24px rgba(239, 125, 42, 0.12);
}

.workflow-step-card.done {
    border-color: #cce7d0;
    background: linear-gradient(135deg, #f4fff6 0%, #ffffff 100%);
}

.workflow-summary {
    margin: 16px 0 0;
    color: #6d4b3a;
    font-size: 0.92rem;
    line-height: 1.6;
}

.form-section {
    margin-bottom: 34px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f3e8df;
}

.form-section:last-child {
    border-bottom: none;
}

.form-section h3 {
    color: var(--food-red);
    margin-bottom: 18px;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.step-tag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 4px 11px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: #fff;
    background: linear-gradient(135deg, #cf3b22, #ef7d2a);
}

.section-description {
    color: #876552;
    margin-bottom: 20px;
    font-size: 0.9rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-row .form-group:only-child {
    grid-column: 1 / -1;
}

@media (max-width: 992px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #4b3428;
    font-weight: 600;
    font-size: 0.95rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #e8dacc;
    border-radius: 12px;
    font-size: 0.95rem;
    font-family: inherit;
    transition: all 0.25s ease;
    background-color: #fffdf9;
    color: #2f1d14;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #e66a2f;
    background-color: white;
    box-shadow: 0 0 0 4px rgba(239, 107, 46, 0.14);
}

.form-text {
    display: block;
    margin-top: 5px;
    color: #8d6f5f;
    font-size: 0.85rem;
}

.psgc-row {
    margin-bottom: 8px;
}

.psgc-help {
    margin: 4px 0 18px;
    color: #7a5e4f;
    font-size: 0.84rem;
}

#business_address[readonly] {
    background: #f8f3ed;
    color: #4b3428;
}

.doc-requirement-status {
    margin: 10px 0 18px;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 0.9rem;
    border: 1px solid #e4e9f1;
    background: #f8fafc;
    color: #334155;
}

.doc-requirement-status.success {
    border-color: #86efac;
    background: #f0fdf4;
    color: #166534;
}

.doc-requirement-status.warning {
    border-color: #fcd34d;
    background: #fffbeb;
    color: #92400e;
}

.doc-requirement-status.error {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
}

.application-form.submitted {
    opacity: 0.7;
    pointer-events: none;
}

.post-submit-state {
    margin-top: 18px;
    padding: 18px;
    border-radius: 14px;
    border: 1px solid #bde5c8;
    background: linear-gradient(180deg, #f3fff6 0%, #ffffff 100%);
    color: #1f5130;
}

.post-submit-state h4 {
    margin: 0 0 10px;
    color: #1a6f3c;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.post-submit-state p {
    margin: 0 0 8px;
    color: #2e5c3c;
}

.documents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.document-item {
    background: linear-gradient(180deg, #fff9f4 0%, #fff 100%);
    padding: 18px;
    border-radius: 12px;
    border: 1px dashed #edcdb7;
    transition: all 0.3s;
}

.document-item.required-highlight {
    border-style: solid;
    border-color: #e99756;
    background: linear-gradient(180deg, #fff4e7 0%, #fffefc 100%);
}

.document-item:hover {
    border-color: #df6b36;
    background-color: #fff7ef;
    transform: translateY(-1px);
}

.document-label {
    display: block;
    cursor: pointer;
}

.document-label span {
    display: block;
    font-weight: 600;
    color: #4f3428;
    margin-bottom: 5px;
}

.document-label input[type="file"] {
    width: 100%;
    padding: 10px 0;
    font-size: 0.9rem;
}

.document-label small {
    color: #8d6f5f;
    font-size: 0.8rem;
}

.tutorial-agreement,
.terms-agreement {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 20px;
    background: #fffaf2;
    border-radius: 12px;
    border: 1px solid #f1dbc7;
    border-left: 4px solid #df652d;
}

.tutorial-agreement {
    margin-bottom: 14px;
}

.tutorial-agreement input,
.terms-agreement input {
    margin-top: 5px;
    accent-color: #c62828;
}

.tutorial-agreement label,
.terms-agreement label {
    color: #725241;
    font-size: 0.95rem;
    line-height: 1.5;
}

.terms-agreement a {
    color: #c2421f;
    text-decoration: none;
    font-weight: 500;
}

.terms-agreement a:hover {
    text-decoration: underline;
}

.form-actions {
    display: flex;
    gap: 20px;
    margin-top: 40px;
    padding-top: 30px;
    border-top: 1px solid #f2e2d4;
}

.btn-outline {
    padding: 13px 24px;
    background: #fff8f0;
    color: #b63d1c;
    border: 1px solid #efc5a6;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-outline:hover {
    background: #b63d1c;
    color: #fff;
}

.application-form-container .btn-primary {
    border-radius: 12px;
    font-weight: 700;
    box-shadow: 0 14px 24px rgba(179, 38, 30, 0.26);
}

.requirements-sidebar {
    position: sticky;
    top: 100px;
    height: fit-content;
}

.requirements-card {
    background: linear-gradient(180deg, #fffefb 0%, #fff 100%);
    border-radius: 20px;
    padding: 24px;
    border: 1px solid #f1dfcf;
    box-shadow: var(--food-shadow);
}

.requirements-card h3 {
    color: #4a2618;
    margin-bottom: 25px;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.requirements-list {
    margin-bottom: 30px;
}

.requirements-list.compact {
    margin-bottom: 0;
}

.requirement-item {
    display: flex;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid #f2e5da;
}

.requirement-item:last-child {
    border-bottom: none;
}

.requirement-item i {
    color: #db6632;
    font-size: 1.2rem;
    margin-top: 2px;
}

.requirement-item strong {
    display: block;
    color: #4a2618;
    font-size: 0.95rem;
    margin-bottom: 3px;
}

.requirement-item small {
    color: #876c5d;
    font-size: 0.85rem;
}

.penalty-card,
.support-info,
.contact-support {
    margin-top: 30px;
    padding-top: 24px;
    border-top: 1px solid #edf1f5;
}

.penalty-card h4,
.support-info h4,
.contact-support h4 {
    color: #4a2618;
    margin-bottom: 15px;
    font-size: 1rem;
}

.penalty-card ul,
.support-info ul {
    list-style: none;
    padding-left: 0;
    color: #876c5d;
}

.penalty-card li,
.support-info li {
    padding: 5px 0;
    border-bottom: 1px solid #f2e5da;
}

.penalty-card li:last-child,
.support-info li:last-child {
    border-bottom: none;
}

.contact-support p {
    color: #876c5d;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.contact-support i {
    color: #df652d;
    width: 20px;
}

@media (max-width: 1200px) {
    .application-hero {
        grid-template-columns: 1fr;
    }

    .playbook-grid {
        grid-template-columns: 1fr;
    }

    .application-container {
        grid-template-columns: 1fr;
    }

    .workflow-steps-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    
    .requirements-sidebar {
        position: static;
    }
}

@media (max-width: 768px) {
    .franchise-application-page {
        padding: 80px 0 30px;
    }

    .application-hero {
        padding: 20px;
        border-radius: 16px;
    }
    
    .application-form-container {
        padding: 25px;
    }

    .application-playbook {
        padding: 18px;
        border-radius: 16px;
    }

    .workflow-steps-grid {
        grid-template-columns: 1fr;
    }
    
    .documents-grid {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-outline,
    .btn-primary {
        width: 100%;
    }
}

@keyframes riseIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('franchiseForm');
    const serverAlert = <?php echo $swal_alert ? json_encode($swal_alert) : 'null'; ?>;
    if (serverAlert && serverAlert.text) {
        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: serverAlert.icon || 'info',
                title: serverAlert.title || 'Notice',
                text: serverAlert.text,
                confirmButtonText: 'OK'
            });
        } else {
            alert(serverAlert.text);
        }
    }

    if (!form) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    const csrfInput = form.querySelector('input[name="csrf_token"]');
    const docStatusEl = document.getElementById('docRequirementStatus');
    const requiredDocumentKeys = ['business_logo', 'dti_doc', 'bir_doc', 'valid_id', 'address_proof'];
    const MAX_FILE_SIZE = <?php echo (int)$max_document_size_bytes; ?>;
    const MAX_FILE_SIZE_LABEL = <?php echo json_encode($max_document_size_label); ?>;
    const psgcRegion = document.getElementById('psgcRegion');
    const psgcProvince = document.getElementById('psgcProvince');
    const psgcCity = document.getElementById('psgcCity');
    const psgcBarangay = document.getElementById('psgcBarangay');
    const psgcRegionName = document.getElementById('psgcRegionName');
    const psgcProvinceName = document.getElementById('psgcProvinceName');
    const psgcCityName = document.getElementById('psgcCityName');
    const psgcBarangayName = document.getElementById('psgcBarangayName');
    const psgcManualMode = document.getElementById('psgcManualMode');
    const psgcHelp = document.getElementById('psgcAddressHelp');
    const businessStreetInput = document.getElementById('business_address_street');
    const businessAddressInput = document.getElementById('business_address');
    const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
    const psgcCache = new Map();
    const psgcInitialSelection = {
        region: psgcRegion ? String(psgcRegion.dataset.selected || '') : '',
        province: psgcProvince ? String(psgcProvince.dataset.selected || '') : '',
        city: psgcCity ? String(psgcCity.dataset.selected || '') : '',
        barangay: psgcBarangay ? String(psgcBarangay.dataset.selected || '') : ''
    };

    function normalizePsgcText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
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

        return Array.from(new Set(text.split(/\s+/).filter((token) => token && !stopWords.has(token))));
    }

    function toCandidateNames(...groups) {
        const seen = new Set();
        const names = [];
        const addName = (value) => {
            const text = String(value || '').trim();
            if (!text) return;
            const key = normalizePlaceName(text);
            if (!key || seen.has(key)) return;
            seen.add(key);
            names.push(text);
        };
        groups.forEach((group) => {
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
        if (!normalizedTarget || !targetTokens.length) return '';

        let bestValue = '';
        let bestScore = 0;
        let bestOverlap = 0;

        Array.from(selectElement.options || []).forEach((option) => {
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
            targetTokens.forEach((token) => {
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

    function findOptionValueFromCandidates(selectElement, candidates = []) {
        const names = toCandidateNames(candidates);
        for (let i = 0; i < names.length; i += 1) {
            const code = findOptionValueByName(selectElement, names[i]);
            if (code) return code;
        }
        return '';
    }

    function getSelectedText(selectElement) {
        if (!selectElement || selectElement.selectedIndex < 0) return '';
        const option = selectElement.options[selectElement.selectedIndex];
        if (!option || !option.value) return '';
        return normalizePsgcText(option.textContent || option.label || '');
    }

    function isNcrRegionSelection(code, name) {
        const normalizedCode = String(code || '').trim();
        const normalizedName = normalizePsgcText(name).toLowerCase();
        return normalizedCode === '130000000' ||
            normalizedName.includes('national capital region') ||
            /\bncr\b/.test(normalizedName);
    }

    function syncPsgcHiddenNames() {
        if (psgcRegionName) psgcRegionName.value = getSelectedText(psgcRegion);
        if (psgcProvinceName) psgcProvinceName.value = getSelectedText(psgcProvince);
        if (psgcCityName) psgcCityName.value = getSelectedText(psgcCity);
        if (psgcBarangayName) psgcBarangayName.value = getSelectedText(psgcBarangay);
    }

    function composeBusinessAddress() {
        if (!businessAddressInput) return;
        if (psgcManualMode && psgcManualMode.value === '1') {
            return;
        }
        const street = normalizePsgcText(businessStreetInput ? businessStreetInput.value : '');
        const barangay = normalizePsgcText(psgcBarangayName ? psgcBarangayName.value : '');
        const city = normalizePsgcText(psgcCityName ? psgcCityName.value : '');
        const province = normalizePsgcText(psgcProvinceName ? psgcProvinceName.value : '');
        const region = normalizePsgcText(psgcRegionName ? psgcRegionName.value : '');

        const parts = [street, barangay, city, province, region];
        const seen = new Set();
        const merged = [];
        parts.forEach((part) => {
            const key = part.toLowerCase();
            if (!part || seen.has(key)) return;
            seen.add(key);
            merged.push(part);
        });

        if (merged.length > 0) {
            businessAddressInput.value = merged.join(', ');
        }
    }

    function resetSelect(selectElement, placeholderText, shouldDisable) {
        if (!selectElement) return;
        selectElement.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholderText;
        selectElement.appendChild(option);
        selectElement.value = '';
        selectElement.disabled = !!shouldDisable;
    }

    function populateSelect(selectElement, items, placeholderText, selectedCode, selectedNames) {
        if (!selectElement) return;
        const currentSelected = String(selectedCode || '');
        resetSelect(selectElement, placeholderText, false);
        let appliedCode = '';
        items.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.code || '');
            option.textContent = normalizePsgcText(item.name || '');
            if (option.value === currentSelected) {
                option.selected = true;
                appliedCode = option.value;
            }
            selectElement.appendChild(option);
        });

        if (!appliedCode) {
            const fallbackCode = findOptionValueFromCandidates(selectElement, selectedNames);
            if (fallbackCode) {
                selectElement.value = fallbackCode;
                appliedCode = fallbackCode;
            }
        }

        return appliedCode;
    }

    function setPsgcHelp(message, isError) {
        if (!psgcHelp) return;
        psgcHelp.textContent = message;
        psgcHelp.style.color = isError ? '#b71c1c' : '#7a5e4f';
    }

    function enablePsgcManualFallback(message) {
        if (psgcManualMode) {
            psgcManualMode.value = '1';
        }
        if (businessAddressInput) {
            businessAddressInput.readOnly = false;
            businessAddressInput.placeholder = 'Enter complete business address manually (street, barangay, city, province, region)';
        }
        if (psgcRegion) psgcRegion.disabled = true;
        if (psgcProvince) psgcProvince.disabled = true;
        if (psgcCity) psgcCity.disabled = true;
        if (psgcBarangay) psgcBarangay.disabled = true;
        setPsgcHelp(message || 'PSGC lookup is temporarily unavailable. You can enter your complete address manually.', true);
    }

    function usePsgcMode() {
        if (psgcManualMode) {
            psgcManualMode.value = '0';
        }
        if (businessAddressInput) {
            businessAddressInput.readOnly = true;
        }
    }

    async function fetchPsgc(path) {
        const key = String(path || '');
        if (psgcCache.has(key)) {
            return psgcCache.get(key);
        }
        const response = await fetch(PSGC_API_BASE + key, {
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) {
            throw new Error('PSGC request failed for ' + key);
        }
        const payload = await response.json();
        psgcCache.set(key, payload);
        return payload;
    }

    async function loadPsgcRegions(selectedCode, selectedNames) {
        const regions = await fetchPsgc('/regions');
        return populateSelect(psgcRegion, Array.isArray(regions) ? regions : [], 'Select region', selectedCode, selectedNames);
    }

    async function loadPsgcProvinces(regionCode, selectedCode, selectedNames) {
        if (!psgcProvince) return;
        if (!regionCode) {
            resetSelect(psgcProvince, 'Select province', true);
            return '';
        }

        const provinces = await fetchPsgc('/regions/' + encodeURIComponent(regionCode) + '/provinces');
        const provinceItems = Array.isArray(provinces) ? provinces : [];

        if (provinceItems.length === 0) {
            resetSelect(psgcProvince, 'No province selection needed', true);
            psgcProvince.required = false;
            if (psgcProvinceName) psgcProvinceName.value = '';
            return '';
        }

        const appliedCode = populateSelect(psgcProvince, provinceItems, 'Select province', selectedCode, selectedNames);
        psgcProvince.disabled = false;
        const regionName = getSelectedText(psgcRegion);
        psgcProvince.required = !isNcrRegionSelection(regionCode, regionName);
        return appliedCode;
    }

    async function loadPsgcCities(regionCode, provinceCode, selectedCode, selectedNames) {
        if (!psgcCity) return;
        if (!regionCode) {
            resetSelect(psgcCity, 'Select city / municipality', true);
            return '';
        }

        let cities = [];
        if (provinceCode) {
            cities = await fetchPsgc('/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities');
        } else {
            cities = await fetchPsgc('/regions/' + encodeURIComponent(regionCode) + '/cities-municipalities');
        }

        const appliedCode = populateSelect(psgcCity, Array.isArray(cities) ? cities : [], 'Select city / municipality', selectedCode, selectedNames);
        psgcCity.disabled = false;
        return appliedCode;
    }

    async function loadPsgcBarangays(cityCode, selectedCode, selectedNames) {
        if (!psgcBarangay) return;
        if (!cityCode) {
            resetSelect(psgcBarangay, 'Select barangay', true);
            return '';
        }
        const barangays = await fetchPsgc('/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays');
        const appliedCode = populateSelect(psgcBarangay, Array.isArray(barangays) ? barangays : [], 'Select barangay', selectedCode, selectedNames);
        psgcBarangay.disabled = false;
        return appliedCode;
    }

    async function initPsgcSelectors() {
        if (!psgcRegion || !psgcProvince || !psgcCity || !psgcBarangay) {
            return;
        }

        usePsgcMode();
        setPsgcHelp('Select PSGC fields so admin can validate your exact Cavite business location faster.', false);

        const regionCandidates = toCandidateNames(psgcRegionName ? psgcRegionName.value : '');
        const provinceCandidates = toCandidateNames(psgcProvinceName ? psgcProvinceName.value : '');
        const cityCandidates = toCandidateNames(psgcCityName ? psgcCityName.value : '');
        const barangayCandidates = toCandidateNames(psgcBarangayName ? psgcBarangayName.value : '');

        await loadPsgcRegions(psgcInitialSelection.region, regionCandidates);
        let provinceCode = await loadPsgcProvinces(psgcRegion.value, psgcInitialSelection.province, provinceCandidates);
        provinceCode = provinceCode || (psgcProvince && !psgcProvince.disabled ? psgcProvince.value : '');
        if (psgcProvince && !psgcProvince.disabled && provinceCode === '') {
            resetSelect(psgcCity, 'Select city / municipality', true);
            resetSelect(psgcBarangay, 'Select barangay', true);
        } else {
            let cityCode = await loadPsgcCities(psgcRegion.value, provinceCode, psgcInitialSelection.city, cityCandidates);
            if (!cityCode && psgcProvince && !psgcProvince.disabled) {
                const currentProvinceCode = String(psgcProvince.value || '').trim();
                const provinceOptions = Array.from(psgcProvince.options || []).filter((option) => option && option.value);
                for (let i = 0; i < provinceOptions.length; i += 1) {
                    const option = provinceOptions[i];
                    if (!option || !option.value || String(option.value) === currentProvinceCode) {
                        continue;
                    }
                    psgcProvince.value = String(option.value);
                    cityCode = await loadPsgcCities(psgcRegion.value, psgcProvince.value, psgcInitialSelection.city, cityCandidates);
                    if (cityCode) {
                        break;
                    }
                }

                if (!cityCode && currentProvinceCode && String(psgcProvince.value || '') !== currentProvinceCode) {
                    psgcProvince.value = currentProvinceCode;
                    cityCode = await loadPsgcCities(psgcRegion.value, psgcProvince.value, psgcInitialSelection.city, cityCandidates);
                }
            }
            await loadPsgcBarangays(psgcCity.value, psgcInitialSelection.barangay, barangayCandidates);
        }

        syncPsgcHiddenNames();
        composeBusinessAddress();
    }

    function showSwal(icon, title, text) {
        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: icon || 'info',
                title: title || 'Notice',
                text: text || '',
                confirmButtonText: 'OK'
            });
        } else if (text) {
            alert(text);
        }
    }

    async function callDocumentCheckApi() {
        const makePhaseError = (message) => {
            const err = new Error(message);
            err.phase = 'document_check';
            return err;
        };

        const selectedDocs = [];
        form.querySelectorAll('input[type="file"]').forEach(input => {
            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                return;
            }

            selectedDocs.push({
                doc_type: input.name,
                name: file.name,
                size: file.size,
                mime_type: file.type || ''
            });
        });

        const response = await fetch('api/check_franchise_requirements.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                csrf_token: csrfInput ? csrfInput.value : '',
                required_documents: requiredDocumentKeys,
                documents: selectedDocs
            })
        });

        const rawText = await response.text();
        let data = null;
        try {
            data = JSON.parse(rawText);
        } catch (parseError) {
            throw makePhaseError('Document check returned an invalid response. Please contact admin.');
        }

        if (!response.ok) {
            throw makePhaseError(data && data.message ? data.message : 'Document check request failed');
        }

        if (!data || data.success !== true) {
            throw makePhaseError(data && data.message ? data.message : 'Document validation failed');
        }

        return data;
    }

    async function submitApplicationAjax() {
        const makePhaseError = (message) => {
            const err = new Error(message);
            err.phase = 'submission';
            return err;
        };

        const formData = new FormData(form);
        if (!formData.has('submit_application')) {
            formData.append('submit_application', '1');
        }
        const response = await fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const rawText = await response.text();
        let payload = null;

        try {
            payload = JSON.parse(rawText);
        } catch (parseError) {
            const textPreview = (rawText || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            const shortPreview = textPreview.length > 180 ? textPreview.slice(0, 180) + '...' : textPreview;
            throw makePhaseError(shortPreview ? ('Server returned non-JSON response: ' + shortPreview) : 'Submission returned an invalid response. Please check PHP errors/logs.');
        }

        if (!response.ok || !payload || payload.success !== true) {
            throw makePhaseError(payload && payload.message ? payload.message : 'Application submission failed.');
        }

        return payload;
    }

    function setDocStatus(message, state = 'info') {
        if (!docStatusEl) return;

        docStatusEl.classList.remove('info', 'success', 'warning', 'error');
        docStatusEl.classList.add(state);
        docStatusEl.textContent = message;
    }

    function getSelectedDocumentCount() {
        let count = 0;
        form.querySelectorAll('input[type="file"]').forEach(input => {
            if (input.files && input.files.length > 0) {
                count++;
            }
        });
        return count;
    }

    async function refreshDocumentCheckStatus() {
        if (getSelectedDocumentCount() === 0) {
            setDocStatus('Waiting for required documents upload...', 'info');
            return;
        }

        try {
            const check = await callDocumentCheckApi();
            const labelMap = check.required_document_labels || {};
            const toLabel = key => labelMap[key] || key;

            if (check.ready) {
                setDocStatus('All required documents are complete and valid.', 'success');
            } else if (Array.isArray(check.missing_documents) && check.missing_documents.length > 0) {
                setDocStatus('Missing required documents: ' + check.missing_documents.map(toLabel).join(', '), 'warning');
            } else if (Array.isArray(check.invalid_documents) && check.invalid_documents.length > 0) {
                setDocStatus('Some selected documents need correction.', 'warning');
            } else {
                setDocStatus(check.message || 'Waiting for required documents upload...', 'info');
            }
        } catch (error) {
            setDocStatus('Document requirement checker is temporarily unavailable.', 'error');
        }
    }

    if (psgcRegion && psgcProvince && psgcCity && psgcBarangay) {
        psgcRegion.addEventListener('change', async function() {
            try {
                usePsgcMode();
                resetSelect(psgcProvince, 'Select province', true);
                resetSelect(psgcCity, 'Select city / municipality', true);
                resetSelect(psgcBarangay, 'Select barangay', true);
                if (psgcProvinceName) psgcProvinceName.value = '';
                if (psgcCityName) psgcCityName.value = '';
                if (psgcBarangayName) psgcBarangayName.value = '';

                await loadPsgcProvinces(this.value, '');
                const provinceCode = !psgcProvince.disabled ? psgcProvince.value : '';
                if (psgcProvince.disabled || provinceCode !== '') {
                    await loadPsgcCities(this.value, provinceCode, '');
                } else {
                    resetSelect(psgcCity, 'Select city / municipality', true);
                    resetSelect(psgcBarangay, 'Select barangay', true);
                }
                syncPsgcHiddenNames();
                composeBusinessAddress();
            } catch (error) {
                console.error('PSGC region load failed:', error);
                enablePsgcManualFallback('PSGC address lookup failed while loading provinces and cities. Enter your complete address manually for now.');
            }
        });

        psgcProvince.addEventListener('change', async function() {
            try {
                usePsgcMode();
                resetSelect(psgcCity, 'Select city / municipality', true);
                resetSelect(psgcBarangay, 'Select barangay', true);
                if (psgcCityName) psgcCityName.value = '';
                if (psgcBarangayName) psgcBarangayName.value = '';

                await loadPsgcCities(psgcRegion ? psgcRegion.value : '', this.value, '');
                syncPsgcHiddenNames();
                composeBusinessAddress();
            } catch (error) {
                console.error('PSGC province load failed:', error);
                enablePsgcManualFallback('PSGC address lookup failed while loading cities/municipalities. Enter your complete address manually for now.');
            }
        });

        psgcCity.addEventListener('change', async function() {
            try {
                usePsgcMode();
                resetSelect(psgcBarangay, 'Select barangay', true);
                if (psgcBarangayName) psgcBarangayName.value = '';
                await loadPsgcBarangays(this.value, '');
                syncPsgcHiddenNames();
                composeBusinessAddress();
            } catch (error) {
                console.error('PSGC city load failed:', error);
                enablePsgcManualFallback('PSGC address lookup failed while loading barangays. Enter your complete address manually for now.');
            }
        });

        psgcBarangay.addEventListener('change', function() {
            syncPsgcHiddenNames();
            composeBusinessAddress();
        });

        if (businessStreetInput) {
            businessStreetInput.addEventListener('input', function() {
                composeBusinessAddress();
            });
        }

        initPsgcSelectors().catch((error) => {
            console.error('PSGC initialization failed:', error);
            enablePsgcManualFallback('PSGC address lookup is currently unavailable. Enter your complete business address manually and submit again.');
        });
    } else if (businessAddressInput) {
        businessAddressInput.readOnly = false;
        if (psgcManualMode) {
            psgcManualMode.value = '1';
        }
    }
    
    // Form validation
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        let validationErrorMessage = '';
        
        // Clear previous errors
        clearErrors();
        syncPsgcHiddenNames();
        composeBusinessAddress();
        
        // Validate required fields
        requiredFields.forEach(field => {
            if (field.type === 'file') {
                return;
            }
            if (field.disabled) {
                return;
            }

            if (field.type === 'checkbox' && !field.checked) {
                showError(field, 'You must agree before submitting');
                isValid = false;
                if (!validationErrorMessage) {
                    validationErrorMessage = 'You must agree to the terms before submitting.';
                }
                return;
            }

            if (!field.value.trim()) {
                showError(field, 'This field is required');
                isValid = false;
                if (!validationErrorMessage) {
                    const label = field.closest('.form-group') ? field.closest('.form-group').querySelector('label') : null;
                    validationErrorMessage = (label ? label.textContent.replace('*', '').trim() : 'A required field') + ' is required.';
                }
            }
        });
        
        // Validate file uploads
        const fileInputs = form.querySelectorAll('input[type="file"][required]');
        fileInputs.forEach(input => {
            if (!input.files || input.files.length === 0) {
                showError(input, 'Please upload the required document');
                isValid = false;
                if (!validationErrorMessage) {
                    validationErrorMessage = 'Please upload all required documents.';
                }
            } else {
                const file = input.files[0];
                const validTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'application/octet-stream'];
                const validExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                
                const hasValidType = !file.type || validTypes.includes(file.type);
                const hasValidExtension = validExtensions.includes(extension);
                if (!hasValidType && !hasValidExtension) {
                    showError(input, 'File must be PDF, JPG, or PNG');
                    isValid = false;
                    if (!validationErrorMessage) {
                        validationErrorMessage = 'One or more document files have invalid format.';
                    }
                }
                
                if (file.size > MAX_FILE_SIZE) {
                    showError(input, 'File size must be less than ' + MAX_FILE_SIZE_LABEL);
                    isValid = false;
                    if (!validationErrorMessage) {
                        validationErrorMessage = 'One or more documents exceed the ' + MAX_FILE_SIZE_LABEL + ' limit.';
                    }
                }
            }
        });
        
        // Validate capital investment
        const capitalInput = document.getElementById('capital_investment');
        if (capitalInput.value < 100000) {
            showError(capitalInput, 'Minimum capital investment is PHP 100,000');
            isValid = false;
            if (!validationErrorMessage) {
                validationErrorMessage = 'Minimum capital investment is PHP 100,000.';
            }
        }
        
        if (!isValid) {
            // Scroll to first error
            const firstError = form.querySelector('.error-message');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            showSwal('warning', 'Please Fix The Form', validationErrorMessage || 'Please complete all required fields.');
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking documents...';
        }

        try {
            const documentCheck = await callDocumentCheckApi();

            if (!documentCheck.ready) {
                const labelMap = documentCheck.required_document_labels || {};
                const toLabel = key => labelMap[key] || key;
                const missingLabels = Array.isArray(documentCheck.missing_documents) ? documentCheck.missing_documents.map(toLabel) : [];
                const invalidMessages = Array.isArray(documentCheck.invalid_documents)
                    ? documentCheck.invalid_documents.map(item => item.message || ((item.doc_type || 'Document') + ' is invalid.'))
                    : [];

                if (Array.isArray(documentCheck.missing_documents)) {
                    documentCheck.missing_documents.forEach(docKey => {
                        const input = form.querySelector(`input[type="file"][name="${docKey}"]`);
                        if (input) {
                            showError(input, 'This required document is missing');
                        }
                    });
                }

                if (Array.isArray(documentCheck.invalid_documents)) {
                    documentCheck.invalid_documents.forEach(item => {
                        const input = form.querySelector(`input[type="file"][name="${item.doc_type}"]`);
                        if (input) {
                            showError(input, item.message || 'Invalid document');
                        }
                    });
                }

                setDocStatus(documentCheck.message || 'Please complete all required documents.', 'warning');
                const firstError = form.querySelector('.error-message');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
                }

                const detailParts = [];
                if (missingLabels.length > 0) {
                    detailParts.push('Missing: ' + missingLabels.join(', '));
                }
                if (invalidMessages.length > 0) {
                    detailParts.push('Invalid: ' + invalidMessages.join(' | '));
                }
                const swalMessage = detailParts.length > 0 ? detailParts.join('\n') : (documentCheck.message || 'Please complete required documents before submitting.');
                showSwal('warning', 'Document Check Failed', swalMessage);
                return;
            }

            setDocStatus('All required documents are complete and valid.', 'success');

            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            const submitResponse = await submitApplicationAjax();
            const successText = submitResponse && submitResponse.message
                ? submitResponse.message
                : 'Your business application has been submitted.';
            setDocStatus('Submission complete. The system owner has been notified.', 'success');
            showSwal('success', 'Application Submitted', successText);

            form.classList.add('submitted');
            form.querySelectorAll('input, select, textarea, button').forEach(el => {
                el.disabled = true;
            });

            const postSubmitState = document.createElement('div');
            postSubmitState.className = 'post-submit-state';
            postSubmitState.innerHTML = `
                <h4><i class="fas fa-check-circle"></i> Submission Completed</h4>
                <p>Your application number is <strong>${submitResponse.application_number || '-'}</strong>.</p>
                <p>You can track review updates inside your account dashboard.</p>
                <div class="form-actions" style="margin-top:18px;padding-top:0;border-top:none;">
                    <a href="my_account.php" class="btn-primary btn-large" style="text-decoration:none;">
                        <i class="fas fa-user-circle"></i> Track In My Account
                    </a>
                </div>
            `;
            form.parentNode.insertBefore(postSubmitState, form.nextSibling);
        } catch (error) {
            const msg = error && error.message ? error.message : 'Unable to verify documents right now. Please try again.';
            const swalTitle = (error && error.phase === 'submission') ? 'Submission Error' : 'Document Check Error';
            setDocStatus(msg, 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
            }
            showSwal('error', swalTitle, msg);
        }
    });
    
    function showError(field, message) {
        const formGroup = field.closest('.form-group, .document-item, .terms-agreement');
        if (!formGroup) return;
        
        // Remove existing error
        const existingError = formGroup.querySelector('.error-message');
        if (existingError) existingError.remove();
        
        // Add error style
        if (field.type === 'checkbox') {
            formGroup.style.borderColor = '#dc3545';
        } else {
            field.style.borderColor = '#dc3545';
        }
        
        // Create error message
        const errorMsg = document.createElement('div');
        errorMsg.className = 'error-message';
        errorMsg.style.color = '#dc3545';
        errorMsg.style.fontSize = '0.85rem';
        errorMsg.style.marginTop = '5px';
        errorMsg.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        
        formGroup.appendChild(errorMsg);
    }
    
    function clearErrors() {
        // Remove error messages
        document.querySelectorAll('.error-message').forEach(el => el.remove());
        
        // Reset border colors
        document.querySelectorAll('.form-group input, .form-group select, .form-group textarea, .document-item input').forEach(el => {
            el.style.borderColor = '';
        });

        document.querySelectorAll('.terms-agreement').forEach(el => {
            el.style.borderColor = '';
        });
    }
    
    // Real-time validation for inputs
    form.querySelectorAll('input, select, textarea').forEach(field => {
        field.addEventListener('blur', function() {
            if (this.hasAttribute('required') && !this.value.trim()) {
                showError(this, 'This field is required');
            } else {
                clearFieldError(this);
            }
        });
        
        field.addEventListener('input', function() {
            clearFieldError(this);
        });
    });
    
    function clearFieldError(field) {
        const formGroup = field.closest('.form-group, .document-item, .terms-agreement');
        if (!formGroup) return;
        
        const errorMsg = formGroup.querySelector('.error-message');
        if (errorMsg) errorMsg.remove();
        
        if (field.type === 'checkbox') {
            formGroup.style.borderColor = '';
        } else {
            field.style.borderColor = '';
        }
    }
    
    // File preview functionality
    form.querySelectorAll('input[type="file"]').forEach(input => {
        const label = input.closest('.document-label');
        if (label) {
            const span = label.querySelector('span');
            if (span && !span.dataset.originalLabel) {
                span.dataset.originalLabel = span.textContent.trim();
            }
        }

        input.addEventListener('change', function() {
            const file = this.files[0];
            const currentLabel = this.closest('.document-label');
            const span = currentLabel ? currentLabel.querySelector('span') : null;

            if (span) {
                const originalText = span.dataset.originalLabel || span.textContent.trim();
                if (file) {
                    // Update label with file name
                    span.innerHTML = `${originalText} <br><small style="color: #4caf50;">[OK] ${file.name}</small>`;
                } else {
                    span.textContent = originalText;
                }
            }

            if (file) {
                // Clear any errors
                clearFieldError(this);
            }

            refreshDocumentCheckStatus();
        });
    });
    
    // TIN format validation
    const tinInput = document.getElementById('tin_number');
    if (tinInput) {
        tinInput.addEventListener('input', function() {
            // Remove non-numeric characters
            this.value = this.value.replace(/\D/g, '');
            
            // Format as XXX-XXX-XXX-XXX
            if (this.value.length > 3 && this.value.length <= 6) {
                this.value = this.value.slice(0,3) + '-' + this.value.slice(3);
            } else if (this.value.length > 6 && this.value.length <= 9) {
                this.value = this.value.slice(0,3) + '-' + this.value.slice(3,6) + '-' + this.value.slice(6);
            } else if (this.value.length > 9) {
                this.value = this.value.slice(0,3) + '-' + this.value.slice(3,6) + '-' + this.value.slice(6,9) + '-' + this.value.slice(9,12);
            }
        });
    }

    refreshDocumentCheckStatus();
});
</script>

<?php
// Function to handle file uploads
function getUploadErrorText($error_code, $max_size_label) {
    switch ((int)$error_code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "File exceeds maximum allowed size of {$max_size_label}.";
        case UPLOAD_ERR_PARTIAL:
            return "File upload was interrupted. Please try again.";
        case UPLOAD_ERR_NO_FILE:
            return "No file uploaded.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing temporary upload directory on server.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Server failed to write uploaded file.";
        case UPLOAD_ERR_EXTENSION:
            return "A server extension blocked the file upload.";
        default:
            return "Unknown file upload error.";
    }
}

function handleFileUploads($conn, $application_id, $max_file_size, array $verification_context = []) {
    $upload_dir = 'uploads/franchise_documents/';
    $doc_types = [
        'business_logo',
        'dti_doc',
        'bir_doc',
        'mayor_doc',
        'barangay_clearance',
        'lease_or_title',
        'occupancy_certificate',
        'fire_safety_certificate',
        'community_tax_certificate',
        'sanitary_permit',
        'valid_id',
        'address_proof',
        'bank_proof',
        'bir_form',
        'sss_registration',
        'philhealth_registration',
        'pagibig_registration',
        'industry_permit'
    ];
    $required_docs = ['business_logo', 'dti_doc', 'bir_doc', 'valid_id', 'address_proof'];
    $doc_labels = [
        'business_logo' => 'Business Logo',
        'dti_doc' => 'DTI/SEC Certificate',
        'bir_doc' => 'BIR Registration',
        'mayor_doc' => "Mayor's Permit",
        'barangay_clearance' => 'Barangay Clearance',
        'lease_or_title' => 'Lease Contract/Title',
        'occupancy_certificate' => 'Certificate of Occupancy',
        'fire_safety_certificate' => 'Fire Safety Certificate',
        'community_tax_certificate' => 'Community Tax Certificate',
        'sanitary_permit' => 'Sanitary Permit',
        'valid_id' => 'Valid ID',
        'address_proof' => 'Proof of Address',
        'bank_proof' => 'Bank Proof',
        'bir_form' => 'BIR Form 1901/1903',
        'sss_registration' => 'SSS Registration',
        'philhealth_registration' => 'PhilHealth Registration',
        'pagibig_registration' => 'Pag-IBIG Registration',
        'industry_permit' => 'Industry-Specific Permit'
    ];
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mime_types = ['application/pdf', 'image/jpeg', 'image/png'];
    $government_documents = [
        'dti_doc',
        'bir_doc',
        'mayor_doc',
        'barangay_clearance',
        'lease_or_title',
        'occupancy_certificate',
        'fire_safety_certificate',
        'community_tax_certificate',
        'sanitary_permit',
        'valid_id',
        'address_proof',
        'bank_proof',
        'bir_form',
        'sss_registration',
        'philhealth_registration',
        'pagibig_registration',
        'industry_permit'
    ];
    $saved_files = [];
    $max_size_label = formatFileSizeLabel($max_file_size);
    $contact_person = trim((string)($verification_context['contact_person'] ?? ''));
    $contact_name_parts = preg_split('/\s+/', $contact_person);
    $contact_first_name = trim((string)($contact_name_parts[0] ?? ''));
    $contact_last_name = trim((string)($contact_name_parts[count($contact_name_parts) - 1] ?? ''));
    $gov_doc_context = [
        'first_name' => $contact_first_name,
        'last_name' => $contact_last_name,
        'full_name' => $contact_person,
        'contact_email' => trim((string)($verification_context['contact_email'] ?? '')),
        'business_name' => trim((string)($verification_context['business_name'] ?? ''))
    ];

    // Validate required documents
    foreach ($required_docs as $required_doc) {
        if (!isset($_FILES[$required_doc])) {
            return ['success' => false, 'message' => ($doc_labels[$required_doc] ?? $required_doc) . " is required."];
        }

        if ((int)$_FILES[$required_doc]['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => ($doc_labels[$required_doc] ?? $required_doc) . ': ' . getUploadErrorText($_FILES[$required_doc]['error'], $max_size_label)
            ];
        }
    }

    // Create directory if it doesn't exist
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'message' => "Unable to prepare document upload directory."];
    }

    try {
        foreach ($doc_types as $doc_type) {
            if (!isset($_FILES[$doc_type]) || $_FILES[$doc_type]['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = $_FILES[$doc_type];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                cleanupUploadedFiles($saved_files);
                return [
                    'success' => false,
                    'message' => ($doc_labels[$doc_type] ?? $doc_type) . ': ' . getUploadErrorText($file['error'], $max_size_label)
                ];
            }

            if ($file['size'] > $max_file_size) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => ($doc_labels[$doc_type] ?? $doc_type) . " must be less than {$max_size_label}."];
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions, true)) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Documents must be uploaded as PDF, JPG, or PNG files."];
            }

            $mime_type = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : false;
            if ($mime_type !== false && !in_array($mime_type, $allowed_mime_types, true)) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "One of the uploaded files has an invalid file format."];
            }

            if (in_array($doc_type, $government_documents, true)) {
                $verify_context = $gov_doc_context;
                $verify_context['strict'] = ($doc_type === 'valid_id');
                $verification = verifyGovernmentDocumentWithConfiguredProvider($doc_type, $file, $verify_context);

                if (empty($verification['success']) || empty($verification['verified'])) {
                    cleanupUploadedFiles($saved_files);
                    $verification_message = trim((string)($verification['message'] ?? 'Document verification failed.'));
                    return [
                        'success' => false,
                        'message' => ($doc_labels[$doc_type] ?? $doc_type) . ': ' . ($verification_message !== '' ? $verification_message : 'Verification failed.')
                    ];
                }
            }

            $safe_original_name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
            $file_name = $application_id . '_' . $doc_type . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safe_original_name;
            $file_path = $upload_dir . $file_name;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Failed to upload one of the documents. Please try again."];
            }

            $saved_files[] = $file_path;

            // Insert into database
            $query = "INSERT INTO franchise_documents (application_id, document_type, file_name, file_path, uploaded_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            if (!$stmt) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Failed to save uploaded document details."];
            }

            mysqli_stmt_bind_param($stmt, "isss", $application_id, $doc_type, $file_name, $file_path);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Failed to save uploaded document details."];
            }

            mysqli_stmt_close($stmt);
        }
    } catch (Throwable $e) {
        cleanupUploadedFiles($saved_files);
        error_log("Franchise document upload failed for application {$application_id}: " . $e->getMessage());
        return ['success' => false, 'message' => "Failed to upload documents. Please try again."];
    }

    return ['success' => true, 'message' => 'Documents uploaded successfully'];
}

function cleanupUploadedFiles($file_paths) {
    foreach ($file_paths as $file_path) {
        if (is_string($file_path) && $file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
    }
}

mysqli_close($conn);
include 'includes/footer.php';
?>
