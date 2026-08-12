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
        'business_type' => 'partnership',
        'tin_number' => getFormInput('tin_number', 'N/A') ?: 'N/A',
        'dti_sec_number' => getFormInput('dti_sec_number', 'N/A') ?: 'N/A',
        'bir_registration_number' => getFormInput('bir_registration_number', 'N/A') ?: 'N/A',
        'mayors_permit' => getFormInput('mayors_permit', 'N/A') ?: 'N/A',
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

    $form_data['proposed_location'] = $form_data['business_address'] !== '' ? $form_data['business_address'] : 'To be confirmed during site validation';

    $required_fields = [
        'business_name' => 'Business name',
        'business_type' => 'Business type',
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
        $duplicate_checks = [];
        if ($form_data['tin_number'] !== 'N/A') {
            $duplicate_checks[] = [
                'query' => "SELECT id FROM users WHERE id <> ? AND account_type = 'organization' AND tax_id = ? AND tax_id <> 'N/A' LIMIT 1",
                'types' => 'is',
                'params' => [$user_id, $form_data['tin_number']],
                'message' => 'This business TIN is already registered in the platform.'
            ];
        }
        if ($form_data['dti_sec_number'] !== 'N/A') {
            $duplicate_checks[] = [
                'query' => "SELECT id FROM users WHERE id <> ? AND account_type = 'organization' AND business_registration = ? AND business_registration <> 'N/A' LIMIT 1",
                'types' => 'is',
                'params' => [$user_id, $form_data['dti_sec_number']],
                'message' => 'This DTI/SEC registration number is already registered in the platform.'
            ];
        }

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

$current_page = 'franchise_application';
$page_title = "Business Application | Lechon Delights";
include 'includes/header.php';
?>

<div class="franchise-application-page">
    <div class="container">

        <!-- Merchant Partner Onboarding Hero Section (Screenshot 2 & Login UI Style) -->
        <section class="foodpanda-hero-section" style="position: relative; background: linear-gradient(rgba(23, 25, 34, 0.75), rgba(23, 25, 34, 0.88)), url('assets/images/promo_lechon.jpg') center/cover no-repeat; border-radius: 28px; padding: 52px 40px; color: #fff; margin-bottom: 48px; box-shadow: 0 20px 48px rgba(0,0,0,0.22); min-height: 560px; display: flex; align-items: center;">
            <div style="display: grid; grid-template-columns: 1fr 460px; gap: 44px; width: 100%; align-items: start;">
                <!-- Left Title & Subtitle (Screenshot 2) -->
                <div style="padding-top: 32px;">
                    <span style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3); padding: 6px 16px; border-radius: 999px; font-size: 0.82rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; margin-bottom: 16px;">
                        <i class="fas fa-store" style="margin-right: 6px; color: #ef6b2e;"></i> Partner With Us
                    </span>
                    <h1 style="font-family: 'Outfit', sans-serif; font-size: 3.4rem; font-weight: 800; color: #ffffff; margin: 0 0 18px 0; line-height: 1.1; text-shadow: 0 3px 12px rgba(0,0,0,0.5);">
                        Register your restaurant with us!
                    </h1>
                    <p style="font-size: 1.35rem; color: rgba(255,255,255,0.92); line-height: 1.45; font-weight: 500; max-width: 520px; text-shadow: 0 2px 8px rgba(0,0,0,0.4);">
                        Sign up easily, showcase your menu, and you can start reaching new customers
                    </p>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 24px;">
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.92rem; font-weight: 700; background: rgba(255,255,255,0.12); padding: 10px 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2);">
                            <i class="fas fa-check-circle" style="color: #25d366;"></i> Instant Verification
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.92rem; font-weight: 700; background: rgba(255,255,255,0.12); padding: 10px 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2);">
                            <i class="fas fa-check-circle" style="color: #25d366;"></i> Zero Upfront Setup Fee
                        </div>
                    </div>
                </div>

                <!-- Right Floating Form Card (Screenshot 1 & 2) -->
                <div style="background: #ffffff; border-radius: 24px; padding: 34px 30px; color: #171922; box-shadow: 0 24px 56px rgba(0,0,0,0.32);">
                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; color: #171922; margin: 0 0 20px 0;">
                        Ready to boost your sales?
                    </h2>

                    <form method="POST" action="" id="heroFranchiseForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="submit_application" value="1">
                        <input type="hidden" name="contact_person" id="hidden_contact_person" value="">

                        <div style="display: flex; flex-direction: column; gap: 14px;">
                            <!-- Business Name -->
                            <div class="fp-form-field">
                                <input type="text" id="hero_business_name" name="business_name" class="fp-input" placeholder="Your Business Name *" required value="<?php echo oldFormValue('business_name', $franchise_prefill['business_name'] ?? ''); ?>">
                            </div>

                            <!-- Owner First Name -->
                            <div class="fp-form-field">
                                <input type="text" id="hero_owner_first_name" class="fp-input" placeholder="Business Owner First Name *" required>
                            </div>

                            <!-- Owner Last Name -->
                            <div class="fp-form-field">
                                <input type="text" id="hero_owner_last_name" class="fp-input" placeholder="Business Owner Last Name *" required>
                            </div>

                            <!-- Business Description Dropdown -->
                            <div class="fp-form-field">
                                <select id="hero_business_desc" name="business_type" class="fp-select" required>
                                    <option value="">What describes your business? *</option>
                                    <option value="Restaurant / Eatery">Restaurant / Eatery</option>
                                    <option value="Food Stall / Kiosk">Food Stall / Kiosk</option>
                                    <option value="Partnership / Sole Proprietorship">Partnership / Sole Proprietorship</option>
                                    <option value="Cloud Kitchen / Bakery">Cloud Kitchen / Bakery</option>
                                </select>
                            </div>

                            <!-- BIR 2303 Radio Group -->
                            <div class="fp-radio-group">
                                <label class="fp-radio-label">Do you have BIR 2303 form? *</label>
                                <div style="display: flex; gap: 24px; margin-top: 6px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                                        <input type="radio" name="hero_bir_form" value="Yes" checked style="accent-color: #b3261e;"> Yes
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                                        <input type="radio" name="hero_bir_form" value="No" style="accent-color: #b3261e;"> No
                                    </label>
                                </div>
                            </div>

                            <!-- Android Device Radio Group -->
                            <div class="fp-radio-group">
                                <label class="fp-radio-label">Do you have an android device to receive orders? *</label>
                                <div style="display: flex; gap: 24px; margin-top: 6px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                                        <input type="radio" name="hero_android_device" value="Yes" checked style="accent-color: #b3261e;"> Yes
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                                        <input type="radio" name="hero_android_device" value="No" style="accent-color: #b3261e;"> No
                                    </label>
                                </div>
                            </div>

                            <!-- Number of branches -->
                            <div class="fp-form-field" style="position: relative;">
                                <label style="font-size: 0.75rem; color: #667085; font-weight: 700; display: block; margin-bottom: 2px;">Number of branches to register *</label>
                                <input type="number" id="hero_branches" name="number_of_branches" class="fp-input" value="1" min="1" required style="padding-right: 36px;">
                                <i class="fas fa-info-circle" style="position: absolute; right: 14px; top: 28px; color: #b3261e; font-size: 16px;" title="Total physical store locations"></i>
                            </div>

                            <!-- Business Email -->
                            <div class="fp-form-field">
                                <input type="email" id="hero_business_email" name="contact_email" class="fp-input" placeholder="Enter your Business Email *" required value="<?php echo oldFormValue('contact_email', $franchise_prefill['contact_email'] ?? ($_SESSION['email'] ?? '')); ?>">
                            </div>

                            <!-- Phone Number -->
                            <div class="fp-form-field" style="display: flex; border: 1px solid #d0d5dd; border-radius: 12px; overflow: hidden; align-items: center;">
                                <span style="background: #f8f9fa; padding: 14px; font-weight: 700; color: #344054; border-right: 1px solid #d0d5dd; font-size: 0.95rem;">+63</span>
                                <input type="tel" id="hero_phone" name="contact_phone" class="fp-input" placeholder="Business Owner Phone Number *" style="border: none; border-radius: 0;" required value="<?php echo oldFormValue('contact_phone', $franchise_prefill['contact_phone'] ?? ''); ?>">
                            </div>

                            <!-- Info Alert Note (Screenshot 1) -->
                            <div style="background: #f0f8ff; border-radius: 12px; padding: 14px 16px; display: flex; gap: 12px; align-items: start;">
                                <i class="fas fa-info-circle" style="color: #0284c7; font-size: 18px; margin-top: 2px;"></i>
                                <p style="font-size: 0.82rem; color: #334155; line-height: 1.45; margin: 0; font-weight: 500;">
                                    We’ll send an OTP to this number for verification. This number will also be used for all important communication.
                                </p>
                            </div>

                            <!-- Checkboxes (Screenshot 1) -->
                            <label style="display: flex; align-items: start; gap: 10px; font-size: 0.85rem; color: #171922; font-weight: 700; cursor: pointer; margin-top: 4px;">
                                <input type="checkbox" checked style="accent-color: #b3261e; width: 18px; height: 18px; margin-top: 2px;">
                                My Business Phone is the same as my Mobile Number
                            </label>

                            <label style="display: flex; align-items: start; gap: 10px; font-size: 0.85rem; color: #171922; font-weight: 700; cursor: pointer;">
                                <input type="checkbox" checked style="accent-color: #b3261e; width: 18px; height: 18px; margin-top: 2px;">
                                <span>I'd like to get updates & promotions by <strong style="color: #25d366;"><i class="fab fa-whatsapp"></i> WhatsApp</strong> and I also agree to the <a href="#" style="color: #b3261e; text-decoration: underline;">AI Notice</a></span>
                            </label>

                            <!-- Submit Button -->
                            <button type="submit" id="btnHeroQuickApply" style="width: 100%; background: #b3261e; color: #fff; font-weight: 800; font-size: 1.05rem; border: none; border-radius: 12px; padding: 16px; cursor: pointer; margin-top: 8px; box-shadow: 0 6px 18px rgba(179,38,30,0.32); transition: all 0.2s;">
                                SUBMIT APPLICATION
                            </button>

                            <!-- Footer Links -->
                            <div style="text-align: center; margin-top: 8px; font-size: 0.82rem; color: #667085; line-height: 1.6;">
                                Already have an account? <a href="login.php" style="color: #b3261e; font-weight: 700; text-decoration: none;">Login</a><br>
                                Do you want to be a delivery rider? <a href="locations.php" style="color: #b3261e; font-weight: 700; text-decoration: none;">Click here</a>
                            </div>
                            <p style="font-size: 0.7rem; color: #98a2b3; text-align: center; margin: 4px 0 0 0; line-height: 1.3;">
                                This site is protected by reCAPTCHA and the Google <a href="privacy_policy.php" style="color: #667085;">Privacy Policy</a> and <a href="terms_of_service.php" style="color: #667085;">Terms of Service</a> apply.
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <?php if ($success_msg): ?>
        <div class="alert alert-success" style="background-color: #d4edda; border: 2px solid #28a745; border-radius: 12px; padding: 20px; color: #155724; font-size: 1.05rem; margin-bottom: 24px;">
            <i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i> <?php echo $success_msg; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="alert alert-error" style="background-color: #f8d7da; border: 2px solid #dc3545; border-radius: 12px; padding: 20px; color: #721c24; font-size: 1.05rem; margin-bottom: 24px;">
            <i class="fas fa-exclamation-circle" style="color: #dc3545; margin-right: 10px;"></i> <?php echo $error_msg; ?>
        </div>
                        <!-- Expandable Optional Compliance Files -->
                        <div class="form-section">
                            <div class="accordion-header" id="toggleOptionalDocs">
                                <div>
                                    <h4 style="margin:0;font-size:1.05rem;color:#2f1a12;"><i class="fas fa-folder-open" style="color:#ef6b2e;margin-right:8px;"></i> Additional Compliance Permits (Optional)</h4>
                                    <small style="color:#7b6d64;">Click to attach LGU permits, lease contract, or statutory records now to speed up review</small>
                                </div>
                                <i class="fas fa-chevron-down accordion-chevron"></i>
                            </div>

                            <div class="accordion-body" id="optionalDocsBody">
                                <div class="documents-grid" style="margin-top:16px;">
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Mayor's Permit</span>
                                            <input type="file" name="mayor_doc" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>LGU Business Permit</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Barangay Clearance</span>
                                            <input type="file" name="barangay_clearance" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Barangay Hall clearance</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Lease Contract / Title</span>
                                            <input type="file" name="lease_or_title" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Proof of store occupancy</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Certificate of Occupancy</span>
                                            <input type="file" name="occupancy_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Occupancy permit</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Fire Safety Certificate</span>
                                            <input type="file" name="fire_safety_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>BFP Fire Inspection</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Community Tax (Cedula)</span>
                                            <input type="file" name="community_tax_certificate" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Cedula file</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Sanitary Permit</span>
                                            <input type="file" name="sanitary_permit" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Food sanitation clearance</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Bank Account Proof</span>
                                            <input type="file" name="bank_proof" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Bank statement / cert</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>BIR Form 1901/1903</span>
                                            <input type="file" name="bir_form" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Application for registration</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>SSS Registration</span>
                                            <input type="file" name="sss_registration" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Employer SSS record</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>PhilHealth Registration</span>
                                            <input type="file" name="philhealth_registration" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Employer PhilHealth record</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Pag-IBIG Registration</span>
                                            <input type="file" name="pagibig_registration" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Employer Pag-IBIG record</small>
                                        </label>
                                    </div>
                                    <div class="document-item">
                                        <label class="document-label">
                                            <span>Industry License (FDA/BSP)</span>
                                            <input type="file" name="industry_permit" accept=".pdf,.jpg,.jpeg,.png">
                                            <small>Sector-specific licenses</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="wizard-nav-bar">
                            <button type="button" class="btn-outline" id="btnBackToStep1">
                                <i class="fas fa-arrow-left"></i> Previous: Business Info
                            </button>
                            <button type="button" class="btn-primary" id="btnGoToStep3">
                                Next: Review & Submit <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- WIZARD STEP 3: Review & Final Submit       -->
                    <!-- ========================================== -->
                    <div class="wizard-pane" id="wizardStep3">
                        <div class="form-section">
                            <h3><i class="fas fa-clipboard-check"></i> Application Summary Preview</h3>
                            <p class="section-description">Please double-check your application details before submitting.</p>

                            <div class="summary-card" id="summaryCard">
                                <div class="summary-grid">
                                    <div class="summary-item">
                                        <small>Business Name</small>
                                        <strong id="sumBusinessName">-</strong>
                                    </div>
                                    <div class="summary-item">
                                        <small>Business Type</small>
                                        <strong id="sumBusinessType">Partnership</strong>
                                    </div>
                                    <div class="summary-item">
                                        <small>Contact Person</small>
                                        <strong id="sumContactPerson">-</strong>
                                    </div>
                                    <div class="summary-item">
                                        <small>Contact Email & Phone</small>
                                        <strong id="sumContactDetails">-</strong>
                                    </div>
                                    <div class="summary-item full-width">
                                        <small>Business Location</small>
                                        <strong id="sumBusinessAddress">-</strong>
                                    </div>
                                    <div class="summary-item">
                                        <small>Capital Investment</small>
                                        <strong id="sumCapital">-</strong>
                                    </div>
                                    <div class="summary-item">
                                        <small>Attached Documents</small>
                                        <strong id="sumDocCount">0 files attached</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Confirmations -->
                        <div class="form-section">
                            <h3><i class="fas fa-check-double"></i> Confirmations & Agreements</h3>
                            
                            <div class="tutorial-agreement">
                                <input type="checkbox" id="acknowledge_tutorial" name="acknowledge_tutorial" required <?php echo isset($_POST['acknowledge_tutorial']) ? 'checked' : ''; ?>>
                                <label for="acknowledge_tutorial">
                                    I reviewed the partner registration guide and understand that complete permits accelerate approval.
                                </label>
                            </div>

                            <div class="terms-agreement">
                                <input type="checkbox" id="agree_terms" name="agree_terms" required <?php echo isset($_POST['agree_terms']) ? 'checked' : ''; ?>>
                                <label for="agree_terms">
                                    I hereby certify that all information provided is true and correct, and agree to the <a href="franchise_terms.php" target="_blank">Partner Terms & Conditions</a>.
                                </label>
                            </div>
                        </div>

                        <div class="wizard-nav-bar">
                            <button type="button" class="btn-outline" id="btnBackToStep2">
                                <i class="fas fa-arrow-left"></i> Previous: Documents
                            </button>
                            <button type="submit" name="submit_application" class="btn-primary btn-large" id="btnFinalSubmit">
                                <i class="fas fa-paper-plane"></i> Submit Application
                            </button>
                        </div>
                    </div>

                </form>
                <?php endif; ?>
            </div>

            <!-- Requirements Sidebar -->
            <div class="requirements-sidebar">
                <div class="requirements-card">
                    <h3><i class="fas fa-clipboard-check"></i> Compliance Checklist</h3>
                    <div class="requirements-list compact">
                        <div class="requirement-item">
                            <i class="fas fa-star" style="color:#b3261e;"></i>
                            <div>
                                <strong>5 Core Requirements</strong>
                                <small>Logo, DTI/SEC, BIR, Owner Valid ID, and Proof of Address are mandatory.</small>
                            </div>
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-city" style="color:#ef6b2e;"></i>
                            <div>
                                <strong>LGU Permits</strong>
                                <small>Add Mayor's permit, Barangay clearance, and Fire Safety for faster routing.</small>
                            </div>
                        </div>
                    </div>

                    <div class="support-info">
                        <h4>Partner Benefits</h4>
                        <ul>
                            <li>Application review and location validation</li>
                            <li>Direct brand marketing and assets</li>
                            <li>Integrated order fulfillment portal</li>
                        </ul>
                    </div>

                    <div class="contact-support">
                        <h4>Need Assistance?</h4>
                        <p><i class="fas fa-phone"></i> (02) 8123-4567</p>
                        <p><i class="fas fa-envelope"></i> partner@lechondelights.com</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- SECTION 2: Platform Opportunities ("Lechon Delights brings new opportunities") -->
        <!-- ============================================================== -->
        <section style="margin-top: 56px; margin-bottom: 56px;">
            <div style="text-align: center; max-width: 640px; margin: 0 auto 36px auto;">
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; color: #171922; margin-bottom: 8px;">
                    Lechon Delights brings new opportunities
                </h2>
                <p style="color: #667085; font-size: 1rem; margin: 0;">Grow your food business with our powerful marketplace and delivery network.</p>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
                <div style="background: #fff; border: 1px solid #efddcd; border-radius: 20px; padding: 32px 24px; text-align: center; box-shadow: 0 8px 24px rgba(42,33,29,0.04); transition: transform 0.2s;">
                    <div style="width: 64px; height: 64px; background: #fff8ef; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; color: #b3261e; font-size: 24px;">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #171922; margin-bottom: 12px;">Connect With New Customers</h3>
                    <p style="font-size: 0.95rem; color: #667085; line-height: 1.5; margin: 0;">
                        Adding your business to the platform means access to thousands of new customers in different neighbourhoods across Cavite.
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid #efddcd; border-radius: 20px; padding: 32px 24px; text-align: center; box-shadow: 0 8px 24px rgba(42,33,29,0.04); transition: transform 0.2s;">
                    <div style="width: 64px; height: 64px; background: #fff8ef; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; color: #ef6b2e; font-size: 24px;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #171922; margin-bottom: 12px;">Unlock Revenue</h3>
                    <p style="font-size: 0.95rem; color: #667085; line-height: 1.5; margin: 0;">
                        Let customers enjoy your business from anywhere, and capture the interest of new ones who haven't tried your food yet.
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid #efddcd; border-radius: 20px; padding: 32px 24px; text-align: center; box-shadow: 0 8px 24px rgba(42,33,29,0.04); transition: transform 0.2s;">
                    <div style="width: 64px; height: 64px; background: #fff8ef; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; color: #b3261e; font-size: 24px;">
                        <i class="fas fa-motorcycle"></i>
                    </div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #171922; margin-bottom: 12px;">Focus On Your Business</h3>
                    <p style="font-size: 0.95rem; color: #667085; line-height: 1.5; margin: 0;">
                        We take care of all the payments and customer support, whilst our riders take care of delivery. Leaving you to focus on what matters!
                    </p>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- SECTION 3: How It Works ("We make it simple and easy")         -->
        <!-- ============================================================== -->
        <section style="background: #fff9f2; border: 1px solid #efddcd; border-radius: 24px; padding: 48px 32px; margin-bottom: 56px;">
            <div style="text-align: center; max-width: 640px; margin: 0 auto 40px auto;">
                <span style="color: #b3261e; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">Simple Onboarding</span>
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 800; color: #171922; margin-top: 4px;">
                    Partner with Lechon Delights today
                </h2>
                <p style="color: #667085; font-size: 1rem; margin-top: 6px;">Take your business to the next level by reaching new customers and boosting your sales!</p>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px;">
                <div style="background: #fff; border-radius: 16px; padding: 24px; border: 1px solid #efddcd; position: relative;">
                    <span style="font-size: 2.5rem; font-weight: 800; color: #ef6b2e; opacity: 0.3; position: absolute; right: 16px; top: 12px;">01</span>
                    <i class="fas fa-mobile-screen-button" style="font-size: 28px; color: #b3261e; margin-bottom: 16px; display: block;"></i>
                    <h4 style="font-size: 1.1rem; font-weight: 800; color: #171922; margin-bottom: 8px;">The Customer Orders</h4>
                    <p style="font-size: 0.9rem; color: #667085; line-height: 1.4; margin: 0;">The customer places an order through the Lechon Delights web & mobile application.</p>
                </div>
                <div style="background: #fff; border-radius: 16px; padding: 24px; border: 1px solid #efddcd; position: relative;">
                    <span style="font-size: 2.5rem; font-weight: 800; color: #ef6b2e; opacity: 0.3; position: absolute; right: 16px; top: 12px;">02</span>
                    <i class="fas fa-utensils" style="font-size: 28px; color: #ef6b2e; margin-bottom: 16px; display: block;"></i>
                    <h4 style="font-size: 1.1rem; font-weight: 800; color: #171922; margin-bottom: 8px;">You Prepare</h4>
                    <p style="font-size: 0.9rem; color: #667085; line-height: 1.4; margin: 0;">You will receive a notification to start preparing the order fresh for dispatch.</p>
                </div>
                <div style="background: #fff; border-radius: 16px; padding: 24px; border: 1px solid #efddcd; position: relative;">
                    <span style="font-size: 2.5rem; font-weight: 800; color: #ef6b2e; opacity: 0.3; position: absolute; right: 16px; top: 12px;">03</span>
                    <i class="fas fa-truck-ramp-box" style="font-size: 28px; color: #b3261e; margin-bottom: 16px; display: block;"></i>
                    <h4 style="font-size: 1.1rem; font-weight: 800; color: #171922; margin-bottom: 8px;">We Deliver</h4>
                    <p style="font-size: 0.9rem; color: #667085; line-height: 1.4; margin: 0;">A rider will be along shortly to pick up the order and deliver it to the customer.</p>
                </div>
                <div style="background: #fff; border-radius: 16px; padding: 24px; border: 1px solid #efddcd; position: relative;">
                    <span style="font-size: 2.5rem; font-weight: 800; color: #ef6b2e; opacity: 0.3; position: absolute; right: 16px; top: 12px;">04</span>
                    <i class="fas fa-square-poll-vertical" style="font-size: 28px; color: #15803d; margin-bottom: 16px; display: block;"></i>
                    <h4 style="font-size: 1.1rem; font-weight: 800; color: #171922; margin-bottom: 8px;">Watch Your Business Grow</h4>
                    <p style="font-size: 0.9rem; color: #667085; line-height: 1.4; margin: 0;">We provide you with real-time insights so you can keep track of revenue and performance.</p>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- SECTION 4: Partner Testimonials & Quotes                      -->
        <!-- ============================================================== -->
        <section style="margin-bottom: 56px;">
            <div style="text-align: center; margin-bottom: 32px;">
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; color: #171922; margin: 0;">
                    What our merchant partners say
                </h2>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                <div style="background: #fff; border: 1px solid #efddcd; border-radius: 20px; padding: 32px; box-shadow: 0 8px 24px rgba(42,33,29,0.04);">
                    <p style="font-size: 1.05rem; color: #2a211d; line-height: 1.6; font-style: italic; margin-bottom: 20px;">
                        "The platform provided an opportunity for our brands to be readily accessible to customers whenever and wherever they are."
                    </p>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: #b3261e; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800;">LA</div>
                        <div>
                            <strong style="display: block; color: #171922; font-size: 1rem;">Lorent Adrias</strong>
                            <span style="font-size: 0.85rem; color: #667085;">Kenny Rogers Group</span>
                        </div>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #efddcd; border-radius: 20px; padding: 32px; box-shadow: 0 8px 24px rgba(42,33,29,0.04);">
                    <p style="font-size: 1.05rem; color: #2a211d; line-height: 1.6; font-style: italic; margin-bottom: 20px;">
                        "Apart from their strong consumer base, Lechon Delights always ensures that we grow our business together. Thank you!"
                    </p>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: #ef6b2e; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800;">ME</div>
                        <div>
                            <strong style="display: block; color: #171922; font-size: 1rem;">Mark Embino</strong>
                            <span style="font-size: 0.85rem; color: #667085;">Minute Burger</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- SECTION 5: Latest Trends & Articles Grid                      -->
        <!-- ============================================================== -->
        <section style="margin-bottom: 56px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 28px; flex-wrap: wrap; gap: 12px;">
                <div>
                    <span style="color: #b3261e; font-weight: 800; font-size: 0.85rem; text-transform: uppercase;">Resource Center</span>
                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; color: #171922; margin-top: 4px;">
                        Latest trends and tips for new partners
                    </h2>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
                <!-- Article 1 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #b3261e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">RESTAURANT TIPS</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">5 Types of Restaurant Logos to Elevate Your Brand</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            Your restaurant's logo is more than just a picture – it's the visual cornerstone of your brand identity. Here are five primary types to enhance your brand presence.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
                <!-- Article 2 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #b3261e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">RESTAURANT TIPS</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">How to Create a Restaurant Menu design that Sells</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            In today's competitive landscape, a well-designed menu is a strategic tool to attract customers, boost sales, and build a strong brand identity.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
                <!-- Article 3 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #b3261e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">RESTAURANT TIPS</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">6 key steps to opening a new restaurant</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            Opening a new restaurant can be an exciting experience. Learn key steps from choosing the right location to menu development and hiring staff.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
                <!-- Article 4 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #b3261e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">RESTAURANT TIPS</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">How to Create a Business Plan for Your Restaurant</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            Writing a business plan will help you clarify your concept, identify target markets, and develop a financial roadmap for your business.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
                <!-- Article 5 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #ef6b2e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">ONLINE FOOD DELIVERY</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">Benefits of Online Food Delivery for your Restaurant</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            Online food delivery has become an essential part of the restaurant industry, providing an easy way for customers to order favorite meals.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
                <!-- Article 6 -->
                <article style="background: #fff; border: 1px solid #efddcd; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(42,33,29,0.03);">
                    <div style="padding: 24px; display: flex; flex-direction: column; flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="background: #fff8ef; color: #b3261e; font-size: 0.75rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase;">RESTAURANT TIPS</span>
                            <span style="font-size: 0.8rem; color: #667085; font-weight: 600;"><i class="far fa-clock"></i> 5 minutes</span>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #171922; margin-bottom: 8px; line-height: 1.3;">Tips to Reduce Food Wastage in Your Restaurant</h3>
                        <p style="font-size: 0.88rem; color: #667085; line-height: 1.5; margin-bottom: 16px; flex: 1;">
                            Food waste management in restaurants helps cut operating costs, improve profits, and build goodwill among environmentally aware customers.
                        </p>
                        <a href="faq.php" style="color: #b3261e; font-weight: 700; font-size: 0.9rem; text-decoration: none;">Read more <i class="fas fa-arrow-right" style="font-size: 12px;"></i></a>
                    </div>
                </article>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- SECTION 6: FAQ & Footer Quick Links                            -->
        <!-- ============================================================== -->
        <footer style="border-top: 1px solid #efddcd; padding-top: 40px; margin-top: 32px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 32px; margin-bottom: 32px;">
                <div>
                    <h4 style="font-size: 1.1rem; font-weight: 800; color: #171922; margin-bottom: 14px;">Any questions?</h4>
                    <p style="font-size: 0.9rem; color: #667085; line-height: 1.5;">Our merchant support team is available 24/7 to assist with onboarding & setup.</p>
                </div>
                <div>
                    <h4 style="font-size: 1rem; font-weight: 800; color: #171922; margin-bottom: 14px;">Company</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; line-height: 2; font-size: 0.9rem;">
                        <li><a href="about.php" style="color: #667085; text-decoration: none;">About us</a></li>
                        <li><a href="faq.php" style="color: #667085; text-decoration: none;">Resources</a></li>
                        <li><a href="terms_of_service.php" style="color: #667085; text-decoration: none;">Terms & Conditions</a></li>
                        <li><a href="privacy_policy.php" style="color: #667085; text-decoration: none;">Privacy Policy</a></li>
                    </ul>
                </div>
                <div>
                    <h4 style="font-size: 1rem; font-weight: 800; color: #171922; margin-bottom: 14px;">Contact Us</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; line-height: 2; font-size: 0.9rem;">
                        <li><a href="help_center.php" style="color: #667085; text-decoration: none;">Help Center</a></li>
                        <li><a href="mailto:partner@lechondelights.com" style="color: #667085; text-decoration: none;">partner@lechondelights.com</a></li>
                    </ul>
                </div>
            </div>
            <div style="border-top: 1px solid #efddcd; padding-top: 20px; text-align: center; color: #7b6d64; font-size: 0.85rem;">
                © Lechon Delights Marketplace 2026. All rights reserved.
            </div>
        </footer>
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.market-address-wrap, #marketAddressWrap {
    display: none !important;
}

/* Foodpanda-style Floating Form Card Fields */
.fp-form-field {
    width: 100%;
}
.fp-input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 500;
    color: #171922;
    background: #ffffff;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.fp-input:focus {
    border-color: #d81b60;
    box-shadow: 0 0 0 4px rgba(216, 27, 96, 0.12);
}
.fp-select {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 500;
    color: #171922;
    background: #ffffff;
    outline: none;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.fp-select:focus {
    border-color: #d81b60;
    box-shadow: 0 0 0 4px rgba(216, 27, 96, 0.12);
}
.fp-radio-group {
    margin-top: 2px;
}
.fp-radio-label {
    font-size: 0.9rem;
    font-weight: 700;
    color: #171922;
    display: block;
}

.franchise-application-page {
    --food-red: #b3261e;
    --food-orange: #ef6b2e;
    --food-ink: #171922;
    --food-cream: #fff9f2;
    --food-border: #efddcd;
    --food-shadow: 0 16px 36px rgba(42, 33, 29, 0.08);
    font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
    padding: 32px 0 64px;
    background: var(--food-cream);
    min-height: 100vh;
}

/* Quick Capital Chips */
.chip-btn {
    padding: 4px 10px;
    background: #ffffff;
    border: 1px solid var(--food-border);
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--food-ink);
    cursor: pointer;
    transition: all 0.2s ease;
}

.chip-btn:hover {
    background: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
}

/* Hero Section */
.application-hero {
    display: grid;
    grid-template-columns: 1.8fr 1fr;
    gap: 24px;
    padding: 32px;
    border-radius: 20px;
    margin-bottom: 24px;
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    box-shadow: 0 16px 36px rgba(179, 38, 30, 0.22);
    color: #ffffff;
}

.hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 999px;
    padding: 6px 14px;
    margin-bottom: 14px;
}

.application-hero h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 2.2rem;
    font-weight: 800;
    margin: 0 0 12px;
    color: #ffffff;
}

.application-hero .application-subtitle {
    margin: 0;
    color: rgba(255, 255, 255, 0.92);
    font-size: 1rem;
    line-height: 1.6;
}

.hero-chip-row {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hero-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.15);
}

.application-hero-metrics {
    display: grid;
    gap: 12px;
}

.metric-card {
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.28);
    border-radius: 14px;
    padding: 14px 18px;
    backdrop-filter: blur(4px);
}

.metric-card small {
    display: block;
    opacity: 0.88;
    font-size: 0.78rem;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 600;
}

.metric-card strong {
    font-size: 1.05rem;
    font-weight: 800;
}

/* Playbook section */
.application-playbook {
    margin-bottom: 24px;
    background: #ffffff;
    border: 1px solid var(--food-border);
    border-radius: 20px;
    padding: 24px;
    box-shadow: var(--food-shadow);
}

.playbook-head h2 {
    font-family: 'Outfit', sans-serif;
    margin: 0 0 6px;
    color: var(--food-ink);
    font-size: 1.35rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}

.playbook-head p {
    margin: 0 0 16px;
    color: #7b6d64;
    font-size: 0.94rem;
}

.playbook-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.playbook-step {
    padding: 18px;
    background: #fffdfb;
    border: 1px solid var(--food-border);
    border-radius: 16px;
}

.playbook-step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 800;
    font-size: 0.85rem;
    color: #ffffff;
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    margin-bottom: 10px;
}

.playbook-step h3 {
    font-family: 'Outfit', sans-serif;
    margin: 0 0 6px;
    color: var(--food-ink);
    font-size: 1rem;
    font-weight: 700;
}

.playbook-step p {
    margin: 0;
    color: #7b6d64;
    font-size: 0.88rem;
    line-height: 1.5;
}

/* WIZARD STEPPER HEADER */
.wizard-stepper {
    position: relative;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 32px;
    padding: 8px 0;
}

.stepper-track {
    position: absolute;
    top: 26px;
    left: 15%;
    right: 15%;
    height: 3px;
    background: var(--food-border);
    z-index: 1;
}

.stepper-progress {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #b3261e, #ef6b2e);
    transition: width 0.35s ease;
}

.stepper-item {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    text-align: center;
}

.stepper-icon {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #ffffff;
    border: 2px solid var(--food-border);
    color: #7b6d64;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    font-weight: 700;
    transition: all 0.3s ease;
    box-shadow: 0 4px 10px rgba(0,0,0,0.04);
}

.stepper-item.active .stepper-icon {
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    border-color: #b3261e;
    color: #ffffff;
    box-shadow: 0 6px 16px rgba(179, 38, 30, 0.3);
    transform: scale(1.1);
}

.stepper-item.completed .stepper-icon {
    background: #15803d;
    border-color: #15803d;
    color: #ffffff;
}

.stepper-label {
    margin-top: 8px;
    font-size: 0.88rem;
    font-weight: 700;
    color: #7b6d64;
    transition: color 0.3s ease;
}

.stepper-item.active .stepper-label {
    color: var(--food-ink);
}

/* WIZARD PANES */
.wizard-pane {
    display: none;
    animation: fadeIn 0.4s ease-out;
}

.wizard-pane.active {
    display: block;
}

.wizard-nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--food-border);
}

/* Form Container */
.application-container {
    display: grid;
    grid-template-columns: 2.2fr 1fr;
    gap: 24px;
    max-width: 1320px;
    margin: 0 auto;
}

.application-form-container {
    background: #ffffff;
    border-radius: 20px;
    padding: 32px;
    border: 1px solid var(--food-border);
    box-shadow: var(--food-shadow);
}

.application-workflow-card {
    margin-bottom: 24px;
    padding: 16px 20px;
    border-radius: 14px;
    border: 1px solid var(--food-border);
    background: #fffdfb;
}

.workflow-pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.workflow-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid var(--food-border);
    color: var(--food-ink);
    font-size: 0.82rem;
    font-weight: 700;
}

.workflow-summary {
    margin: 10px 0 0;
    color: #7b6d64;
    font-size: 0.88rem;
}

.form-section {
    margin-bottom: 28px;
}

.form-section h3 {
    font-family: 'Outfit', sans-serif;
    color: var(--food-ink);
    margin-bottom: 6px;
    font-size: 1.2rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-description {
    color: #7b6d64;
    margin-bottom: 18px;
    font-size: 0.9rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    color: var(--food-ink);
    font-weight: 700;
    font-size: 0.92rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--food-border);
    border-radius: 12px;
    font-size: 0.95rem;
    font-family: inherit;
    transition: all 0.25s ease;
    background-color: #fffdfb;
    color: var(--food-ink);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #ef6b2e;
    background-color: #ffffff;
    box-shadow: 0 0 0 4px rgba(239, 107, 46, 0.15);
}

.form-text {
    display: block;
    margin-top: 4px;
    color: #7b6d64;
    font-size: 0.82rem;
}

.psgc-help {
    margin: 4px 0 14px;
    color: #7b6d64;
    font-size: 0.84rem;
}

/* Documents Grid & Drag-Drop */
.documents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.document-item {
    background: #fffdfb;
    padding: 16px;
    border-radius: 14px;
    border: 2px dashed var(--food-border);
    transition: all 0.25s ease;
    position: relative;
}

.document-item.dragover {
    border-color: #b3261e;
    background: #fff5ea;
    transform: scale(1.02);
}

.document-item.required-highlight {
    border-style: solid;
    border-color: #ef6b2e;
    background: #fffdfa;
}

.document-item:hover {
    border-color: #b3261e;
    background-color: #ffffff;
    box-shadow: 0 6px 16px rgba(0,0,0,0.04);
}

.document-label span {
    display: block;
    font-weight: 700;
    color: var(--food-ink);
    font-size: 0.9rem;
    margin-bottom: 6px;
}

.document-label input[type="file"] {
    width: 100%;
    padding: 6px 0;
    font-size: 0.84rem;
}

.document-label small {
    color: #7b6d64;
    font-size: 0.78rem;
}

/* Accordion for optional docs */
.accordion-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: #fffdfb;
    border: 1px solid var(--food-border);
    border-radius: 14px;
    cursor: pointer;
    transition: background 0.2s ease;
}

.accordion-header:hover {
    background: #ffffff;
}

.accordion-chevron {
    transition: transform 0.3s ease;
    color: #7b6d64;
}

.accordion-header.active .accordion-chevron {
    transform: rotate(180deg);
}

.accordion-body {
    display: none;
}

.accordion-body.open {
    display: block;
}

/* Summary Card for Step 3 */
.summary-card {
    background: #fffdfb;
    border: 1px solid var(--food-border);
    border-radius: 16px;
    padding: 24px;
}

.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.summary-item.full-width {
    grid-column: 1 / -1;
}

.summary-item small {
    display: block;
    color: #7b6d64;
    font-size: 0.8rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.03em;
    margin-bottom: 4px;
}

.summary-item strong {
    font-size: 1rem;
    color: var(--food-ink);
    word-break: break-word;
}

/* Confirmations */
.tutorial-agreement,
.terms-agreement {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 18px;
    background: #fffdfb;
    border-radius: 12px;
    border: 1px solid var(--food-border);
    margin-bottom: 12px;
}

.tutorial-agreement input,
.terms-agreement input {
    margin-top: 4px;
    accent-color: #b3261e;
    width: 18px;
    height: 18px;
}

.tutorial-agreement label,
.terms-agreement label {
    color: #7b6d64;
    font-size: 0.92rem;
    line-height: 1.5;
}

/* Buttons */
.btn-primary {
    padding: 14px 28px;
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 10px 24px rgba(179, 38, 30, 0.25);
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 14px 30px rgba(179, 38, 30, 0.35);
}

.btn-primary:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.btn-outline {
    padding: 13px 24px;
    background: #ffffff;
    color: #b3261e;
    border: 1px solid #b3261e;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-outline:hover {
    background: #fff5ea;
}

/* Sidebar */
.requirements-sidebar {
    position: sticky;
    top: 84px;
    height: fit-content;
}

.requirements-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 24px;
    border: 1px solid var(--food-border);
    box-shadow: var(--food-shadow);
}

.requirements-card h3 {
    font-family: 'Outfit', sans-serif;
    color: var(--food-ink);
    margin-bottom: 20px;
    font-size: 1.15rem;
    font-weight: 700;
}

.requirement-item {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--food-border);
}

.requirement-item:last-child {
    border-bottom: none;
}

.requirement-item strong {
    display: block;
    color: var(--food-ink);
    font-size: 0.92rem;
    margin-bottom: 2px;
}

.requirement-item small {
    color: #7b6d64;
    font-size: 0.84rem;
}

.support-info, .contact-support {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--food-border);
}

.support-info h4, .contact-support h4 {
    font-family: 'Outfit', sans-serif;
    color: var(--food-ink);
    margin-bottom: 10px;
    font-size: 0.95rem;
    font-weight: 700;
}

.support-info ul {
    list-style: none;
    padding-left: 0;
    margin: 0;
    color: #7b6d64;
    font-size: 0.86rem;
}

.support-info li {
    padding: 4px 0;
}

.contact-support p {
    color: #7b6d64;
    font-size: 0.88rem;
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

@media (max-width: 992px) {
    .application-hero { grid-template-columns: 1fr; }
    .playbook-grid { grid-template-columns: 1fr; }
    .application-container { grid-template-columns: 1fr; }
    .requirements-sidebar { position: static; }
    .form-row { grid-template-columns: 1fr; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('franchiseForm');
    const serverAlert = <?php echo $swal_alert ? json_encode($swal_alert) : 'null'; ?>;
    const prefillData = <?php echo json_encode($franchise_prefill); ?>;
    
    if (serverAlert && serverAlert.text) {
        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: serverAlert.icon || 'info',
                title: serverAlert.title || 'Notice',
                text: serverAlert.text,
                confirmButtonColor: '#b3261e',
                confirmButtonText: 'OK'
            });
        }
    }

    if (!form) return;

    // Quick Capital Chip Selection Automation
    document.querySelectorAll('.chip-btn').forEach(chip => {
        chip.addEventListener('click', function() {
            const capVal = this.dataset.capital;
            const capInput = document.getElementById('capital_investment');
            if (capInput && capVal) {
                capInput.value = capVal;
                capInput.style.borderColor = '#15803d';
                saveDraft();
            }
        });
    });

    // Auto TIN Masking
    const tinInput = document.getElementById('tin_number');
    if (tinInput) {
        tinInput.addEventListener('input', function() {
            let digits = this.value.replace(/\D/g, '').slice(0, 12);
            let formatted = '';
            for (let i = 0; i < digits.length; i++) {
                if (i > 0 && i % 3 === 0) formatted += '-';
                formatted += digits[i];
            }
            this.value = formatted;
        });
    }

    // Auto Phone Masking
    const phoneInput = document.getElementById('contact_phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            let val = this.value.replace(/[^\d+]/g, '');
            if (val.startsWith('09') && val.length > 4 && !val.includes('-')) {
                val = val.slice(0, 4) + '-' + val.slice(4, 7) + (val.length > 7 ? '-' + val.slice(7, 11) : '');
            }
            this.value = val;
        });
    }

    // Auto-Fill Profile Button Handler
    // Hero Quick Application Handler (Screenshot 1 & 2)
    const btnHeroQuickApply = document.getElementById('btnHeroQuickApply');
    if (btnHeroQuickApply) {
        btnHeroQuickApply.addEventListener('click', function() {
            const bizName = document.getElementById('hero_business_name')?.value.trim() || '';
            const firstName = document.getElementById('hero_owner_first_name')?.value.trim() || '';
            const lastName = document.getElementById('hero_owner_last_name')?.value.trim() || '';
            const bizEmail = document.getElementById('hero_business_email')?.value.trim() || '';
            const bizPhone = document.getElementById('hero_phone')?.value.trim() || '';

            if (!bizName) {
                Swal.fire('Business Name Required', 'Please enter your business name.', 'warning');
                return;
            }

            const fullName = (firstName + ' ' + lastName).trim() || bizName;

            const nameInput = document.getElementById('business_name');
            const contactPersonInput = document.getElementById('contact_person');
            const emailInput = document.getElementById('contact_email');
            const phoneInput = document.getElementById('contact_phone');

            if (nameInput) nameInput.value = bizName;
            if (contactPersonInput && fullName) contactPersonInput.value = fullName;
            if (emailInput && bizEmail) emailInput.value = bizEmail;
            if (phoneInput && bizPhone) phoneInput.value = bizPhone;

            const formElem = document.getElementById('franchiseForm') || document.getElementById('wizardStepper');
            if (formElem) {
                formElem.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Business info saved! Complete your location & document verification below.',
                showConfirmButton: false,
                timer: 3000
            });
        });
    }

    const btnAutoFill = document.getElementById('btnAutoFillProfile');
    if (btnAutoFill) {
        btnAutoFill.addEventListener('click', function() {
            if (!prefillData) return;
            const setIfPresent = (id, key) => {
                const el = document.getElementById(id);
                if (el && prefillData[key]) el.value = prefillData[key];
            };

            setIfPresent('business_name', 'business_name');
            setIfPresent('business_type', 'business_type');
            setIfPresent('dti_sec_number', 'dti_sec_number');
            setIfPresent('tin_number', 'tin_number');
            setIfPresent('contact_person', 'contact_person');
            setIfPresent('contact_phone', 'contact_phone');
            setIfPresent('contact_email', 'contact_email');
            setIfPresent('business_address_street', 'business_address_street');
            setIfPresent('capital_investment', 'capital_investment');

            composeBusinessAddress();
            saveDraft();

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Profile data auto-filled!',
                showConfirmButton: false,
                timer: 2000
            });
        });
    }

    // Drag and Drop Upload Automation
    document.querySelectorAll('.document-item').forEach(item => {
        const fileInput = item.querySelector('input[type="file"]');
        if (!fileInput) return;

        ['dragenter', 'dragover'].forEach(eventName => {
            item.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
                item.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            item.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
                item.classList.remove('dragover');
            }, false);
        });

        item.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                fileInput.files = files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
    });

    // LocalStorage Draft Auto-Save Automation
    const DRAFT_KEY = 'franchise_app_draft_' + (<?php echo (int)$user_id; ?>);

    function saveDraft() {
        const data = {};
        form.querySelectorAll('input:not([type="file"]):not([type="hidden"]), select, textarea').forEach(field => {
            if (field.name) data[field.name] = field.value;
        });
        localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
    }

    function restoreDraft() {
        const saved = localStorage.getItem(DRAFT_KEY);
        if (!saved) return;
        try {
            const data = JSON.parse(saved);
            Object.keys(data).forEach(key => {
                const field = form.querySelector(`[name="${key}"]`);
                if (field && !field.value) {
                    field.value = data[key];
                }
            });
        } catch (e) {}
    }

    form.querySelectorAll('input, select, textarea').forEach(field => {
        field.addEventListener('input', saveDraft);
        field.addEventListener('change', saveDraft);
    });

    restoreDraft();

    // Wizard Step Navigation Logic
    let currentStep = 1;
    const stepperItems = document.querySelectorAll('.stepper-item');
    const stepperProgress = document.getElementById('stepperProgress');
    const wizardPanes = {
        1: document.getElementById('wizardStep1'),
        2: document.getElementById('wizardStep2'),
        3: document.getElementById('wizardStep3')
    };

    const btnGoToStep2 = document.getElementById('btnGoToStep2');
    const btnBackToStep1 = document.getElementById('btnBackToStep1');
    const btnGoToStep3 = document.getElementById('btnGoToStep3');
    const btnBackToStep2 = document.getElementById('btnBackToStep2');

    function updateStepperUI(step) {
        currentStep = step;
        stepperItems.forEach(item => {
            const itemStep = parseInt(item.dataset.step);
            item.classList.remove('active', 'completed');
            if (itemStep === step) {
                item.classList.add('active');
            } else if (itemStep < step) {
                item.classList.add('completed');
            }
        });

        if (stepperProgress) {
            const pct = ((step - 1) / 2) * 100;
            stepperProgress.style.width = pct + '%';
        }

        Object.keys(wizardPanes).forEach(s => {
            if (wizardPanes[s]) {
                wizardPanes[s].classList.toggle('active', parseInt(s) === step);
            }
        });

        window.scrollTo({ top: document.getElementById('wizardStepper').offsetTop - 90, behavior: 'smooth' });

        if (step === 3) {
            buildSummaryPreview();
        }
    }

    function validateStep1() {
        const pane1 = wizardPanes[1];
        if (!pane1) return true;

        const inputs = pane1.querySelectorAll('input[required], select[required], textarea[required]');
        let valid = true;

        inputs.forEach(field => {
            if (!field.value.trim()) {
                field.style.borderColor = '#dc3545';
                valid = false;
            } else {
                field.style.borderColor = '';
            }
        });

        const capInput = document.getElementById('capital_investment');
        if (capInput && parseFloat(capInput.value) < 100000) {
            capInput.style.borderColor = '#dc3545';
            valid = false;
        }

        return valid;
    }

    function validateStep2() {
        const pane2 = wizardPanes[2];
        if (!pane2) return true;

        const requiredFiles = pane2.querySelectorAll('input[type="file"][required]');
        let valid = true;

        requiredFiles.forEach(fileInput => {
            if (!fileInput.files || fileInput.files.length === 0) {
                const docItem = fileInput.closest('.document-item');
                if (docItem) docItem.style.borderColor = '#dc3545';
                valid = false;
            } else {
                const docItem = fileInput.closest('.document-item');
                if (docItem) docItem.style.borderColor = '';
            }
        });

        return valid;
    }

    if (btnGoToStep2) {
        btnGoToStep2.addEventListener('click', function() {
            if (!validateStep1()) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Step 1',
                    text: 'Please complete all required business and location fields (and minimum investment of ₱100,000) before proceeding.',
                    confirmButtonColor: '#b3261e'
                });
                return;
            }
            updateStepperUI(2);
        });
    }

    if (btnBackToStep1) {
        btnBackToStep1.addEventListener('click', function() {
            updateStepperUI(1);
        });
    }

    if (btnGoToStep3) {
        btnGoToStep3.addEventListener('click', function() {
            if (!validateStep2()) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Documents',
                    text: 'Please upload all 5 essential required documents before reviewing.',
                    confirmButtonColor: '#b3261e'
                });
                return;
            }
            updateStepperUI(3);
        });
    }

    if (btnBackToStep2) {
        btnBackToStep2.addEventListener('click', function() {
            updateStepperUI(2);
        });
    }

    stepperItems.forEach(item => {
        item.addEventListener('click', function() {
            const targetStep = parseInt(this.dataset.step);
            if (targetStep === 2 && !validateStep1()) return;
            if (targetStep === 3 && (!validateStep1() || !validateStep2())) return;
            updateStepperUI(targetStep);
        });
    });

    // Accordion Toggle for Optional Documents
    const toggleOptionalDocs = document.getElementById('toggleOptionalDocs');
    const optionalDocsBody = document.getElementById('optionalDocsBody');

    if (toggleOptionalDocs && optionalDocsBody) {
        toggleOptionalDocs.addEventListener('click', function() {
            this.classList.toggle('active');
            optionalDocsBody.classList.toggle('open');
        });
    }

    // Build Summary Preview in Step 3
    function buildSummaryPreview() {
        const getVal = id => {
            const el = document.getElementById(id);
            if (!el) return '-';
            if (el.tagName === 'SELECT') {
                return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : '-';
            }
            return el.value.trim() || '-';
        };

        const bName = getVal('business_name');
        const person = getVal('contact_person');
        const email = getVal('contact_email');
        const phone = getVal('contact_phone');
        const address = getVal('business_address');
        const capital = getVal('capital_investment');

        let fileCount = 0;
        form.querySelectorAll('input[type="file"]').forEach(f => {
            if (f.files && f.files.length > 0) fileCount++;
        });

        document.getElementById('sumBusinessName').textContent = bName;
        document.getElementById('sumBusinessType').textContent = 'Partnership';
        document.getElementById('sumContactPerson').textContent = person;
        document.getElementById('sumContactDetails').textContent = email + ' | ' + phone;
        document.getElementById('sumBusinessAddress').textContent = address;
        document.getElementById('sumCapital').textContent = capital !== '-' ? 'PHP ' + Number(capital).toLocaleString('en-US') : '-';
        document.getElementById('sumDocCount').textContent = fileCount + ' file(s) attached';
    }

    // File Selection Feedback & Logo Preview
    form.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', function() {
            const file = this.files[0];
            const item = this.closest('.document-item');
            const span = item ? item.querySelector('.document-label span') : null;

            if (this.id === 'business_logo_input' && file) {
                const previewContainer = document.getElementById('logoPreviewContainer');
                const previewImg = document.getElementById('logoPreviewImg');
                if (file.type.startsWith('image/') && previewContainer && previewImg) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImg.src = e.target.result;
                        previewContainer.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else if (previewContainer) {
                    previewContainer.style.display = 'none';
                }
            }

            if (file && item) {
                item.style.borderColor = '#15803d';
                if (span) {
                    if (!span.dataset.orig) span.dataset.orig = span.textContent;
                    span.innerHTML = span.dataset.orig + ' <i class="fas fa-check-circle" style="color:#15803d;margin-left:4px;"></i>';
                }
            } else if (item) {
                item.style.borderColor = '';
                if (span && span.dataset.orig) span.textContent = span.dataset.orig;
            }
        });
    });

    // On submit success clear draft
    form.addEventListener('submit', function() {
        localStorage.removeItem(DRAFT_KEY);
    });

    // PSGC cascading dropdowns initialization
    const psgcRegion = document.getElementById('psgcRegion');
    const psgcProvince = document.getElementById('psgcProvince');
    const psgcCity = document.getElementById('psgcCity');
    const psgcBarangay = document.getElementById('psgcBarangay');
    const psgcRegionName = document.getElementById('psgcRegionName');
    const psgcProvinceName = document.getElementById('psgcProvinceName');
    const psgcCityName = document.getElementById('psgcCityName');
    const psgcBarangayName = document.getElementById('psgcBarangayName');
    const businessStreetInput = document.getElementById('business_address_street');
    const businessAddressInput = document.getElementById('business_address');
    const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
    const psgcCache = new Map();

    function normalizePsgcText(v) { return String(v || '').replace(/\s+/g, ' ').trim(); }

    function syncPsgcHiddenNames() {
        if (psgcRegionName && psgcRegion) psgcRegionName.value = psgcRegion.options[psgcRegion.selectedIndex]?.text || '';
        if (psgcProvinceName && psgcProvince) psgcProvinceName.value = psgcProvince.options[psgcProvince.selectedIndex]?.text || '';
        if (psgcCityName && psgcCity) psgcCityName.value = psgcCity.options[psgcCity.selectedIndex]?.text || '';
        if (psgcBarangayName && psgcBarangay) psgcBarangayName.value = psgcBarangay.options[psgcBarangay.selectedIndex]?.text || '';
    }

    function composeBusinessAddress() {
        if (!businessAddressInput) return;
        const street = normalizePsgcText(businessStreetInput?.value);
        const barangay = normalizePsgcText(psgcBarangayName?.value);
        const city = normalizePsgcText(psgcCityName?.value);
        const province = normalizePsgcText(psgcProvinceName?.value);
        const region = normalizePsgcText(psgcRegionName?.value);

        const parts = [street, barangay, city, province, region].filter(p => p !== '');
        businessAddressInput.value = parts.join(', ');
    }

    async function fetchPsgc(path) {
        if (psgcCache.has(path)) return psgcCache.get(path);
        const res = await fetch(PSGC_API_BASE + path);
        const data = await res.json();
        psgcCache.set(path, data);
        return data;
    }

    async function initPsgc() {
        if (!psgcRegion) return;
        try {
            const regions = await fetchPsgc('/regions');
            psgcRegion.innerHTML = '<option value="">Select region</option>';
            regions.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.code;
                opt.textContent = r.name;
                if (r.code === psgcRegion.dataset.selected) opt.selected = true;
                psgcRegion.appendChild(opt);
            });

            if (psgcRegion.value) {
                await loadProvinces(psgcRegion.value);
            }
        } catch (err) {
            console.error('PSGC init error:', err);
        }
    }

    async function loadProvinces(rCode) {
        if (!psgcProvince) return;
        psgcProvince.innerHTML = '<option value="">Select province</option>';
        psgcProvince.disabled = true;
        if (!rCode) return;

        const provinces = await fetchPsgc('/regions/' + rCode + '/provinces');
        provinces.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.code;
            opt.textContent = p.name;
            if (p.code === psgcProvince.dataset.selected) opt.selected = true;
            psgcProvince.appendChild(opt);
        });
        psgcProvince.disabled = false;

        if (psgcProvince.value) {
            await loadCities(psgcProvince.value);
        }
    }

    async function loadCities(pCode) {
        if (!psgcCity) return;
        psgcCity.innerHTML = '<option value="">Select city / municipality</option>';
        psgcCity.disabled = true;
        if (!pCode) return;

        const cities = await fetchPsgc('/provinces/' + pCode + '/cities-municipalities');
        cities.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            if (c.code === psgcCity.dataset.selected) opt.selected = true;
            psgcCity.appendChild(opt);
        });
        psgcCity.disabled = false;

        if (psgcCity.value) {
            await loadBarangays(psgcCity.value);
        }
    }

    async function loadBarangays(cCode) {
        if (!psgcBarangay) return;
        psgcBarangay.innerHTML = '<option value="">Select barangay</option>';
        psgcBarangay.disabled = true;
        if (!cCode) return;

        const barangays = await fetchPsgc('/cities-municipalities/' + cCode + '/barangays');
        barangays.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.code;
            opt.textContent = b.name;
            if (b.code === psgcBarangay.dataset.selected) opt.selected = true;
            psgcBarangay.appendChild(opt);
        });
        psgcBarangay.disabled = false;
    }

    if (psgcRegion) psgcRegion.addEventListener('change', function() { loadProvinces(this.value); syncPsgcHiddenNames(); composeBusinessAddress(); });
    if (psgcProvince) psgcProvince.addEventListener('change', function() { loadCities(this.value); syncPsgcHiddenNames(); composeBusinessAddress(); });
    if (psgcCity) psgcCity.addEventListener('change', function() { loadBarangays(this.value); syncPsgcHiddenNames(); composeBusinessAddress(); });
    if (psgcBarangay) psgcBarangay.addEventListener('change', function() { syncPsgcHiddenNames(); composeBusinessAddress(); });
    if (businessStreetInput) businessStreetInput.addEventListener('input', composeBusinessAddress);

    initPsgc();
});
</script>

<?php
function handleFileUploads($conn, $application_id, $max_file_size, array $verification_context = []) {
    $upload_dir = 'uploads/franchise_documents/';
    $doc_types = [
        'business_logo', 'dti_doc', 'bir_doc', 'mayor_doc', 'barangay_clearance',
        'lease_or_title', 'occupancy_certificate', 'fire_safety_certificate',
        'community_tax_certificate', 'sanitary_permit', 'valid_id', 'address_proof',
        'bank_proof', 'bir_form', 'sss_registration', 'philhealth_registration',
        'pagibig_registration', 'industry_permit'
    ];
    $required_docs = ['business_logo', 'dti_doc', 'bir_doc', 'valid_id', 'address_proof'];
    $doc_labels = [
        'business_logo' => 'Business Logo',
        'dti_doc' => 'DTI/SEC Certificate',
        'bir_doc' => 'BIR Registration',
        'valid_id' => 'Valid ID',
        'address_proof' => 'Proof of Address'
    ];
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
    $saved_files = [];

    foreach ($required_docs as $required_doc) {
        if (!isset($_FILES[$required_doc]) || $_FILES[$required_doc]['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'message' => ($doc_labels[$required_doc] ?? $required_doc) . " is required."];
        }
    }

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
                return ['success' => false, 'message' => "Error uploading " . ($doc_labels[$doc_type] ?? $doc_type)];
            }

            if ($file['size'] > $max_file_size) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => ($doc_labels[$doc_type] ?? $doc_type) . " exceeds size limit."];
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions, true)) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Documents must be PDF, JPG, or PNG files."];
            }

            $safe_original_name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
            $file_name = $application_id . '_' . $doc_type . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safe_original_name;
            $file_path = $upload_dir . $file_name;

            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                cleanupUploadedFiles($saved_files);
                return ['success' => false, 'message' => "Failed to upload document files."];
            }

            $saved_files[] = $file_path;

            $query = "INSERT INTO franchise_documents (application_id, document_type, file_name, file_path, uploaded_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "isss", $application_id, $doc_type, $file_name, $file_path);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    } catch (Throwable $e) {
        cleanupUploadedFiles($saved_files);
        return ['success' => false, 'message' => "Failed to save document records."];
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
