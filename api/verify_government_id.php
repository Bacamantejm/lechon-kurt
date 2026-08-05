<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/government_id_verification_service.php';
require_once __DIR__ . '/../includes/government_verification_provider.php';

$csrf_token = trim((string)($_POST['csrf_token'] ?? ''));
$session_csrf = trim((string)($_SESSION['registration_csrf_token'] ?? ''));
if ($csrf_token === '' || $session_csrf === '' || !hash_equals($session_csrf, $csrf_token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'Invalid security token. Please refresh and try again.'
    ]);
    exit;
}

$first_name = trim((string)($_POST['first_name'] ?? ''));
$last_name = trim((string)($_POST['last_name'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$valid_id_type = trim((string)($_POST['valid_id_type'] ?? ''));
if ($first_name === '' || $last_name === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => 'First name and last name are required for ID verification.'
    ]);
    exit;
}

$verification = verifyGovernmentIdWithConfiguredProvider(
    $first_name,
    $last_name,
    $_FILES['valid_id'] ?? null,
    $address,
    $valid_id_type
);
$valid_id_file_hash = '';
if (isset($_FILES['valid_id']) && is_array($_FILES['valid_id']) && (int)($_FILES['valid_id']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp_path = (string)($_FILES['valid_id']['tmp_name'] ?? '');
    if ($tmp_path !== '' && is_uploaded_file($tmp_path)) {
        $hash = @hash_file('sha256', $tmp_path);
        $valid_id_file_hash = is_string($hash) ? $hash : '';
    }
}

if (empty($verification['success']) || empty($verification['verified'])) {
    $_SESSION['registration_id_verification'] = [
        'verified' => false,
        'name_hash' => '',
        'file_hash' => '',
        'provider' => (string)($verification['provider'] ?? getGovernmentVerificationProviderChoice()),
        'score' => (float)($verification['score'] ?? 0),
        'verified_at' => time(),
        'expires_at' => time() + 120
    ];

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'verified' => false,
        'message' => (string)($verification['message'] ?? 'Unable to verify government ID.')
    ]);
    exit;
}

$name_hash = buildIdentityVerificationNameHash($first_name, $last_name);
$profile_hash = buildIdentityVerificationProfileHash($first_name, $last_name, $address);
$_SESSION['registration_id_verification'] = [
    'verified' => true,
    'name_hash' => $name_hash,
    'profile_hash' => $profile_hash,
    'file_hash' => $valid_id_file_hash,
    'provider' => (string)($verification['provider'] ?? getGovernmentVerificationProviderChoice()),
    'score' => (float)($verification['score'] ?? 0),
    'verified_at' => time(),
    'expires_at' => time() + (15 * 60)
];

echo json_encode([
    'success' => true,
    'verified' => true,
    'message' => (string)($verification['message'] ?? 'Government ID verified successfully.'),
    'provider' => (string)($verification['provider'] ?? getGovernmentVerificationProviderChoice()),
    'score' => (float)($verification['score'] ?? 0),
    'threshold' => (float)($verification['threshold'] ?? 0.75),
    'address_score' => (float)($verification['address_score'] ?? 0)
]);
