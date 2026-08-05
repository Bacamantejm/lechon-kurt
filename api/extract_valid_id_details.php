<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/government_id_verification_service.php';

$csrf_token = trim((string)($_POST['csrf_token'] ?? ''));
$session_csrf = trim((string)($_SESSION['registration_csrf_token'] ?? ''));
if ($csrf_token === '' || $session_csrf === '' || !hash_equals($session_csrf, $csrf_token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token. Please refresh and try again.'
    ]);
    exit;
}

$file = $_FILES['valid_id_file'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please upload a valid ID image first.'
    ]);
    exit;
}

$validation = validateFileUpload($file, ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
if (empty($validation['valid'])) {
    http_response_code(422);
    $errors = $validation['errors'] ?? [];
    echo json_encode([
        'success' => false,
        'message' => !empty($errors) ? (string)$errors[0] : 'Please upload a clear JPG, PNG, or WEBP valid ID image up to 5MB.'
    ]);
    exit;
}

$details = extractGovernmentIdDetailsWithOcrApi($file);
if (empty($details['success'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => (string)($details['message'] ?? 'Unable to read your valid ID. Please enter the details manually.'),
        'details' => [
            'id_type' => '',
            'id_number' => ''
        ]
    ]);
    exit;
}

$extracted = $details['details'] ?? [];
$allowed_id_types = [
    'passport',
    'drivers_license',
    'prc',
    'tin',
    'sss',
    'gsis',
    'owwa',
    'postal',
    'ibp',
    'ofw',
    'senior_citizen',
    'umid',
    'company',
    'national_id',
    'pagibig',
    'philhealth'
];
$detected_type = strtolower(trim((string)($extracted['id_type'] ?? '')));
$detected_number = trim((string)($extracted['id_number'] ?? ''));

if ($detected_type === '' || $detected_number === '' || !in_array($detected_type, $allowed_id_types, true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'This image does not look like a supported Philippine ID. Please upload a clearer valid ID image.',
        'details' => [
            'id_type' => '',
            'id_number' => ''
        ]
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => !empty($extracted['success'])
        ? 'We detected your ID details. Please confirm them before continuing.'
        : 'We could not confidently detect your ID number. Please enter it manually.',
    'details' => [
        'id_type' => $detected_type,
        'id_number' => $detected_number,
        'confidence' => (float)($extracted['confidence'] ?? 0),
        'source' => (string)($extracted['source'] ?? 'none'),
        'ocr_text_excerpt' => (string)($extracted['ocr_text_excerpt'] ?? '')
    ]
]);