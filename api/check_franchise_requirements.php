<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/security.php';

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

$raw_input = file_get_contents('php://input');
$payload = json_decode($raw_input, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload'
    ]);
    exit;
}

if (!validateCSRFToken($payload['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session token. Please refresh and try again.'
    ]);
    exit;
}

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
$default_required_docs = ['business_logo', 'dti_doc', 'bir_doc', 'valid_id', 'address_proof'];
$allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
$allowed_mime_types = ['application/pdf', 'image/jpeg', 'image/png'];
$max_size = getFranchiseMaxUploadBytes();
$max_size_label = formatFileSizeLabel($max_size);

$required_docs = [];
if (!empty($payload['required_documents']) && is_array($payload['required_documents'])) {
    foreach ($payload['required_documents'] as $doc_key) {
        $doc_key = strtolower(trim((string)$doc_key));
        if (isset($doc_labels[$doc_key])) {
            $required_docs[] = $doc_key;
        }
    }
}
if (empty($required_docs)) {
    $required_docs = $default_required_docs;
}

$documents = [];
if (!empty($payload['documents']) && is_array($payload['documents'])) {
    $documents = $payload['documents'];
}

$valid_docs_by_type = [];
$invalid_documents = [];

foreach ($documents as $item) {
    if (!is_array($item)) {
        continue;
    }

    $doc_type = strtolower(trim((string)($item['doc_type'] ?? '')));
    $doc_name = trim((string)($item['name'] ?? ''));
    $doc_size = intval($item['size'] ?? 0);
    $mime_type = strtolower(trim((string)($item['mime_type'] ?? '')));

    if (!isset($doc_labels[$doc_type])) {
        $invalid_documents[] = [
            'doc_type' => $doc_type,
            'message' => 'Unknown document type.'
        ];
        continue;
    }

    if ($doc_name === '') {
        $invalid_documents[] = [
            'doc_type' => $doc_type,
            'message' => 'Missing filename.'
        ];
        continue;
    }

    $extension = strtolower(pathinfo($doc_name, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions, true)) {
        $invalid_documents[] = [
            'doc_type' => $doc_type,
            'message' => $doc_labels[$doc_type] . ' must be PDF, JPG, or PNG.'
        ];
        continue;
    }

    if ($doc_size <= 0 || $doc_size > $max_size) {
        $invalid_documents[] = [
            'doc_type' => $doc_type,
            'message' => $doc_labels[$doc_type] . ' must be less than ' . $max_size_label . '.'
        ];
        continue;
    }

    if ($mime_type !== '' && !in_array($mime_type, $allowed_mime_types, true)) {
        $invalid_documents[] = [
            'doc_type' => $doc_type,
            'message' => $doc_labels[$doc_type] . ' has an invalid file type.'
        ];
        continue;
    }

    $valid_docs_by_type[$doc_type] = true;
}

$missing_documents = [];
foreach ($required_docs as $required_doc) {
    if (empty($valid_docs_by_type[$required_doc])) {
        $missing_documents[] = $required_doc;
    }
}

$ready = empty($missing_documents) && empty($invalid_documents);
$message = $ready
    ? 'All required documents are valid.'
    : 'Some required documents are missing or invalid.';

if (!$ready) {
    error_log('Franchise document check failed for user ' . intval($_SESSION['user_id']) . ': missing=' . implode(',', $missing_documents) . ' invalid=' . count($invalid_documents));
}

echo json_encode([
    'success' => true,
    'ready' => $ready,
    'message' => $message,
    'required_documents' => $required_docs,
    'required_document_labels' => $doc_labels,
    'max_size_bytes' => $max_size,
    'max_size_label' => $max_size_label,
    'missing_documents' => $missing_documents,
    'invalid_documents' => $invalid_documents,
    'debug' => [
        'received_documents' => count($documents),
        'valid_documents' => count($valid_docs_by_type)
    ]
]);
?>
