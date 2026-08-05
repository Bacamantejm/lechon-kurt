<?php

if (!function_exists('normalizeIdentityTokenText')) {
    function normalizeIdentityTokenText($value)
    {
        $value = strtoupper((string)$value);
        $value = preg_replace('/[^A-Z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim((string)$value));
        return $value;
    }
}

if (!function_exists('buildIdentityVerificationNameHash')) {
    function buildIdentityVerificationNameHash($first_name, $last_name)
    {
        $normalized = normalizeIdentityTokenText(trim((string)$first_name) . ' ' . trim((string)$last_name));
        return hash('sha256', $normalized);
    }
}

if (!function_exists('buildIdentityVerificationProfileHash')) {
    function buildIdentityVerificationProfileHash($first_name, $last_name, $address = '')
    {
        $normalized = normalizeIdentityTokenText(
            trim((string)$first_name) . ' ' . trim((string)$last_name) . ' ' . trim((string)$address)
        );
        return hash('sha256', $normalized);
    }
}

if (!function_exists('extractOcrParsedText')) {
    function extractOcrParsedText($payload)
    {
        if (!is_array($payload)) {
            return '';
        }

        $chunks = [];
        if (!empty($payload['ParsedResults']) && is_array($payload['ParsedResults'])) {
            foreach ($payload['ParsedResults'] as $parsed) {
                if (!is_array($parsed)) {
                    continue;
                }
                $text = trim((string)($parsed['ParsedText'] ?? ''));
                if ($text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        if (empty($chunks)) {
            return trim((string)($payload['ParsedText'] ?? ''));
        }

        return trim(implode("\n", $chunks));
    }
}

if (!function_exists('normalizeGovernmentIdExtractionText')) {
    function normalizeGovernmentIdExtractionText($value)
    {
        $value = strtoupper((string)$value);
        $value = preg_replace('/[^A-Z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim((string)$value));
        return $value;
    }
}

if (!function_exists('cleanGovernmentIdNumberValue')) {
    function cleanGovernmentIdNumberValue($value, $id_type)
    {
        $value = strtoupper(trim((string)$value));
        $id_type = strtolower(trim((string)$id_type));

        if ($id_type === 'drivers_license' || $id_type === 'tin' || $id_type === 'sss' || $id_type === 'national_id' || $id_type === 'pagibig' || $id_type === 'philhealth') {
            return preg_replace('/[^A-Z0-9]/', '', $value);
        }

        if ($id_type === 'postal' || $id_type === 'company' || $id_type === 'government') {
            return preg_replace('/[^A-Z0-9]/', '', $value);
        }

        return preg_replace('/\s+/', '', $value);
    }
}

if (!function_exists('detectGovernmentIdTypeFromText')) {
    function detectGovernmentIdTypeFromText($ocr_text)
    {
        $normalized = normalizeGovernmentIdExtractionText($ocr_text);
        if ($normalized === '') {
            return '';
        }

        $has_drivers_license_indicators = function ($text) {
            return (strpos($text, 'DRIVER') !== false && strpos($text, 'LICENSE') !== false) ||
                strpos($text, 'LTO') !== false ||
                strpos($text, 'LAND TRANSPORTATION') !== false;
        };

        $checks = [
            'passport' => ['PASSPORT'],
            'prc' => ['PRC', 'PROFESSIONAL REGULATION COMMISSION'],
            'tin' => ['TIN', 'TAX IDENTIFICATION NUMBER'],
            'sss' => ['SSS'],
            'gsis' => ['GSIS'],
            'owwa' => ['OWWA'],
            'postal' => ['POSTAL'],
            'ibp' => ['IBP'],
            'ofw' => ['OFW'],
            'senior_citizen' => ['SENIOR CITIZEN', 'SENIOR'],
            'umid' => ['UMID', 'UNIFIED MULTI PURPOSE ID', 'UNIFIED MULTI-PURPOSE ID'],
            'company' => ['COMPANY'],
            'national_id' => ['NATIONAL ID', 'PHILSYS', 'PHILID'],
            'pagibig' => ['PAG IBIG', 'PAGIBIG', 'HDMF'],
            'philhealth' => ['PHILHEALTH'],
        ];

        if ($has_drivers_license_indicators($normalized)) {
            foreach (['DRIVER S LICENSE', 'DRIVERS LICENSE', 'LICENSE NO', 'LICENSE NUMBER'] as $label) {
                if (strpos($normalized, $label) !== false) {
                    return 'drivers_license';
                }
            }
        }

        foreach ($checks as $id_type => $labels) {
            foreach ($labels as $label) {
                if (strpos($normalized, $label) !== false) {
                    return $id_type;
                }
            }
        }

        return '';
    }
}

if (!function_exists('extractGovernmentIdNumberFromText')) {
    function extractGovernmentIdNumberFromText($ocr_text, $id_type)
    {
        $normalized = normalizeGovernmentIdExtractionText($ocr_text);
        if ($normalized === '' || trim((string)$id_type) === '') {
            return '';
        }

        $patterns = [];
        switch (strtolower(trim((string)$id_type))) {
            case 'passport':
                $patterns = [
                    '/PASSPORT(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9]{9})\b/',
                    '/\b([A-Z0-9]{9})\b/'
                ];
                break;
            case 'drivers_license':
                $patterns = [
                    '/(?:DRIVER S LICENSE|DRIVERS LICENSE|LAND TRANSPORTATION OFFICE|LTO)(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-]{8,20})\b/',
                    '/(?:DRIVER S LICENSE|DRIVERS LICENSE)(?:\s*[:\-])?\s*([A-Z0-9\-]{8,20})\b/'
                ];
                break;
            case 'prc':
                $patterns = [
                    '/PRC(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9]{7})\b/',
                    '/\b([0-9]{7})\b/'
                ];
                break;
            case 'tin':
                $patterns = [
                    '/TIN(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{12,20})\b/',
                    '/\b([0-9]{12})\b/'
                ];
                break;
            case 'sss':
                $patterns = [
                    '/SSS(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{10,16})\b/',
                    '/\b([0-9]{10})\b/'
                ];
                break;
            case 'gsis':
                $patterns = [
                    '/GSIS(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{11,16})\b/',
                    '/\b([0-9]{11})\b/'
                ];
                break;
            case 'owwa':
                $patterns = [
                    '/OWWA(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{11,16})\b/',
                    '/\b([0-9]{11})\b/'
                ];
                break;
            case 'postal':
                $patterns = [
                    '/POSTAL(?:\s+ID|\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-\s]{16,24})\b/',
                    '/\b([A-Z0-9]{16})\b/'
                ];
                break;
            case 'ibp':
                $patterns = [
                    '/IBP(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{5,12})\b/',
                    '/\b([0-9]{5})\b/'
                ];
                break;
            case 'ofw':
                $patterns = [
                    '/OFW(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{11,16})\b/',
                    '/\b([0-9]{11})\b/'
                ];
                break;
            case 'senior_citizen':
                $patterns = [
                    '/SENIOR(?:\s+CITIZEN)?(?:\s+ID)?(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{4,8})\b/',
                    '/\b([0-9]{4})\b/'
                ];
                break;
            case 'umid':
                $patterns = [
                    '/UMID(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-]{8,20})\b/',
                    '/UNIFIED\s+MULTI(?:\-|\s)?PURPOSE(?:D)?\s+ID(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-]{8,20})\b/',
                    '/UNIFIED\s+MULTI(?:\-|\s)?PURPOSE(?:D)?\s+IDENTIFICATION(?:\s+CARD)?(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-]{8,20})\b/'
                ];
                break;
            case 'company':
                $patterns = [
                    '/COMPANY(?:\s+ID|\s+NO(?:\.?)?|\s+NUMBER)?\s*([A-Z0-9\-\s]{5,20})\b/',
                    '/\b([A-Z0-9]{5,20})\b/'
                ];
                break;
            case 'national_id':
                $patterns = [
                    '/(?:NATIONAL ID|PHILSYS|PHILID)(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{16,24})\b/',
                    '/\b([0-9]{16})\b/'
                ];
                break;
            case 'pagibig':
                $patterns = [
                    '/(?:PAG IBIG|PAGIBIG|HDMF)(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{12,20})\b/',
                    '/\b([0-9]{12})\b/'
                ];
                break;
            case 'philhealth':
                $patterns = [
                    '/PHILHEALTH(?:\s+NO(?:\.?)?|\s+NUMBER)?\s*([0-9\-\s]{12,20})\b/',
                    '/\b([0-9]{12})\b/'
                ];
                break;
            case 'government':
                $patterns = [
                    '/\b([A-Z0-9]{6,20})\b/'
                ];
                break;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) && !empty($matches[1])) {
                return cleanGovernmentIdNumberValue($matches[1], $id_type);
            }
        }

        if ($id_type === 'drivers_license' || $id_type === 'umid') {
            return '';
        }

        if ($id_type === 'national_id' && strpos($normalized, 'UMID') !== false) {
            return '';
        }

        return '';
    }
}

if (!function_exists('extractGovernmentIdDetailsFromOcrText')) {
    function extractGovernmentIdDetailsFromOcrText($ocr_text)
    {
        $detected_type = detectGovernmentIdTypeFromText($ocr_text);
        $ordered_types = [
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

        if ($detected_type !== '') {
            array_unshift($ordered_types, $detected_type);
            $ordered_types = array_values(array_unique($ordered_types));
        }

        foreach ($ordered_types as $id_type) {
            $id_number = extractGovernmentIdNumberFromText($ocr_text, $id_type);
            if ($id_number !== '') {
                return [
                    'success' => true,
                    'id_type' => $id_type,
                    'id_number' => $id_number,
                    'confidence' => $id_type === $detected_type ? 0.9 : 0.65,
                    'source' => $id_type === $detected_type ? 'label_and_pattern' : 'pattern_only',
                    'ocr_text_excerpt' => substr(trim((string)$ocr_text), 0, 800)
                ];
            }
        }

        if (strpos($normalized_ocr = normalizeGovernmentIdExtractionText($ocr_text), 'UMID') !== false) {
            return [
                'success' => false,
                'id_type' => '',
                'id_number' => '',
                'confidence' => 0,
                'source' => 'umid_without_number',
                'ocr_text_excerpt' => substr(trim((string)$ocr_text), 0, 800)
            ];
        }

        return [
            'success' => false,
            'id_type' => '',
            'id_number' => '',
            'confidence' => 0,
            'source' => 'none',
            'ocr_text_excerpt' => substr(trim((string)$ocr_text), 0, 800)
        ];
    }
}

if (!function_exists('extractGovernmentIdDetailsWithOcrApi')) {
    function extractGovernmentIdDetailsWithOcrApi($uploaded_file)
    {
        if (!is_array($uploaded_file) || (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Valid ID file was not uploaded correctly.'
            ];
        }

        $tmp_path = (string)($uploaded_file['tmp_name'] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            return [
                'success' => false,
                'message' => 'Uploaded valid ID file is invalid.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'Valid ID OCR is unavailable on this server (cURL missing).'
            ];
        }

        $api_key = getGovernmentIdVerificationApiKey();
        if ($api_key === '') {
            return [
                'success' => false,
                'message' => 'Valid ID OCR API key is not configured.'
            ];
        }

        $mime_type = function_exists('mime_content_type') ? mime_content_type($tmp_path) : '';
        $original_name = trim((string)($uploaded_file['name'] ?? 'valid_id_upload'));
        if ($original_name === '') {
            $original_name = 'valid_id_upload';
        }

        $cfile = curl_file_create($tmp_path, $mime_type !== '' ? $mime_type : 'application/octet-stream', $original_name);
        $endpoint = 'https://api.ocr.space/parse/image';
        $post_fields = [
            'apikey' => $api_key,
            'language' => 'eng',
            'isOverlayRequired' => 'false',
            'OCREngine' => '2',
            'detectOrientation' => 'true',
            'scale' => 'true',
            'file' => $cfile
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $raw_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw_response === false) {
            return [
                'success' => false,
                'message' => 'Valid ID OCR request failed: ' . ($curl_error !== '' ? $curl_error : 'network error')
            ];
        }

        $response_payload = json_decode((string)$raw_response, true);
        if (!is_array($response_payload)) {
            return [
                'success' => false,
                'message' => 'Valid ID OCR returned an unreadable response.'
            ];
        }

        if ($http_code >= 400 || !empty($response_payload['IsErroredOnProcessing'])) {
            $api_error = '';
            if (!empty($response_payload['ErrorMessage'])) {
                $api_error = is_array($response_payload['ErrorMessage'])
                    ? implode(' ', array_map('strval', $response_payload['ErrorMessage']))
                    : (string)$response_payload['ErrorMessage'];
            }
            return [
                'success' => false,
                'message' => 'Valid ID OCR could not process the uploaded image: ' . ($api_error !== '' ? $api_error : 'OCR processing failed')
            ];
        }

        $parsed_text = extractOcrParsedText($response_payload);
        $details = extractGovernmentIdDetailsFromOcrText($parsed_text);
        $ocr_excerpt = substr(trim((string)$parsed_text), 0, 800);
        if (empty($details['success']) || empty($details['id_type']) || empty($details['id_number'])) {
            return [
                'success' => false,
                'message' => 'No valid Philippine ID type or ID number was detected in the uploaded image.',
                'ocr_text_excerpt' => $ocr_excerpt
            ];
        }

        $confidence = (float)($details['confidence'] ?? 0);
        if ($confidence < 0.65) {
            return [
                'success' => false,
                'message' => 'The uploaded image did not look like a valid Philippine ID. Please upload a clear photo of a valid ID.',
                'ocr_text_excerpt' => $ocr_excerpt
            ];
        }

        return [
            'success' => true,
            'details' => $details,
            'ocr_text_excerpt' => $ocr_excerpt
        ];
    }
}

if (!function_exists('calculateIdentityNameMatchScore')) {
    function calculateIdentityNameMatchScore($ocr_text, $first_name, $last_name)
    {
        $normalized_ocr = normalizeIdentityTokenText($ocr_text);
        $full_name = trim((string)$first_name) . ' ' . trim((string)$last_name);
        $normalized_name = normalizeIdentityTokenText($full_name);

        $name_tokens = array_values(array_filter(explode(' ', $normalized_name), function ($token) {
            return strlen($token) >= 2;
        }));

        if (empty($name_tokens) || $normalized_ocr === '') {
            return [
                'score' => 0.0,
                'matched_tokens' => 0,
                'total_tokens' => count($name_tokens),
                'normalized_name' => $normalized_name
            ];
        }

        $matched = 0;
        foreach ($name_tokens as $token) {
            if (strpos($normalized_ocr, $token) !== false) {
                $matched++;
            }
        }

        $score = $matched / max(1, count($name_tokens));
        return [
            'score' => $score,
            'matched_tokens' => $matched,
            'total_tokens' => count($name_tokens),
            'normalized_name' => $normalized_name
        ];
    }
}

if (!function_exists('extractIdentityAddressTokens')) {
    function extractIdentityAddressTokens($address)
    {
        $normalized = normalizeIdentityTokenText($address);
        if ($normalized === '') {
            return [];
        }

        $ignore_tokens = [
            'THE', 'AND', 'HOUSE', 'HOME', 'NO', 'NUMBER', 'STREET', 'ST', 'ROAD', 'RD', 'AVENUE', 'AVE',
            'BLOCK', 'BLK', 'LOT', 'PHASE', 'UNIT', 'FLOOR', 'ROOM', 'RM', 'BARANGAY', 'BRGY', 'BRGY.',
            'PUROK', 'ZONE', 'SUBDIVISION', 'SUBD', 'VILLAGE', 'VLG', 'CITY', 'MUNICIPALITY', 'MUNICIPAL',
            'PROVINCE', 'REGION', 'PHILIPPINES'
        ];

        $tokens = array_values(array_filter(explode(' ', $normalized), static function ($token) use ($ignore_tokens) {
            return strlen($token) >= 3 && !in_array($token, $ignore_tokens, true);
        }));

        $tokens = array_values(array_unique($tokens));
        if (count($tokens) > 8) {
            $tokens = array_slice($tokens, -8);
        }

        return $tokens;
    }
}

if (!function_exists('calculateIdentityProfileMatchScore')) {
    function calculateIdentityProfileMatchScore($ocr_text, $first_name, $last_name, $address = '')
    {
        $match_mode = 'balanced';
        $raw_mode_candidates = [
            getenv('ID_VERIFICATION_MATCH_MODE'),
            $_ENV['ID_VERIFICATION_MATCH_MODE'] ?? null,
            $_SERVER['ID_VERIFICATION_MATCH_MODE'] ?? null,
            defined('ID_VERIFICATION_MATCH_MODE') ? constant('ID_VERIFICATION_MATCH_MODE') : null,
        ];
        foreach ($raw_mode_candidates as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if (in_array($candidate, ['loose', 'balanced', 'strict'], true)) {
                $match_mode = $candidate;
                break;
            }
        }

        $name_match = calculateIdentityNameMatchScore($ocr_text, $first_name, $last_name);
        $normalized_ocr = normalizeIdentityTokenText($ocr_text);
        $address_tokens = extractIdentityAddressTokens($address);
        $matched_address_tokens = [];

        foreach ($address_tokens as $token) {
            if ($normalized_ocr !== '' && strpos($normalized_ocr, $token) !== false) {
                $matched_address_tokens[] = $token;
            }
        }

        $address_total = count($address_tokens);
        $address_matched = count($matched_address_tokens);
        $address_score = $address_total > 0 ? ($address_matched / $address_total) : 1.0;
        $name_score = (float)($name_match['score'] ?? 0);
        $name_matched_tokens = (int)($name_match['matched_tokens'] ?? 0);
        $name_total_tokens = (int)($name_match['total_tokens'] ?? 0);

        $name_verified = ($name_score >= 0.75 && $name_matched_tokens >= 2);
        $overall_score = ($address_total > 0)
            ? (($name_score * 0.7) + ($address_score * 0.3))
            : $name_score;

        $overall_threshold = 0.75;
        $minimum_address_matches = min(2, $address_total);
        $minimum_address_score = 0.5;

        if ($match_mode === 'loose') {
            $overall_threshold = 0.65;
            $minimum_address_matches = min(1, $address_total);
            $minimum_address_score = 0.25;
        } elseif ($match_mode === 'strict') {
            $overall_threshold = 0.82;
            $minimum_address_matches = min(3, $address_total);
            $minimum_address_score = 0.6;
            $name_verified = ($name_score >= 0.85 && $name_matched_tokens >= max(2, min(3, $name_total_tokens)));
        }

        $address_verified = $address_total === 0 || $address_matched >= $minimum_address_matches || $address_score >= $minimum_address_score;

        return [
            'match_mode' => $match_mode,
            'name_score' => $name_score,
            'name_matched_tokens' => $name_matched_tokens,
            'name_total_tokens' => $name_total_tokens,
            'name_verified' => $name_verified,
            'address_score' => (float)$address_score,
            'address_matched_tokens' => $address_matched,
            'address_total_tokens' => $address_total,
            'address_verified' => $address_verified,
            'matched_address_tokens' => $matched_address_tokens,
            'overall_score' => $overall_score,
            'overall_threshold' => $overall_threshold,
        ];
    }
}

if (!function_exists('getGovernmentIdVerificationApiKey')) {
    function getGovernmentIdVerificationApiKey()
    {
        $candidates = [
            getenv('GOV_ID_OCR_API_KEY'),
            $_ENV['GOV_ID_OCR_API_KEY'] ?? null,
            $_SERVER['GOV_ID_OCR_API_KEY'] ?? null,
            defined('GOV_ID_OCR_API_KEY') ? constant('GOV_ID_OCR_API_KEY') : null,
            getenv('OCR_SPACE_API_KEY'),
            $_ENV['OCR_SPACE_API_KEY'] ?? null,
            $_SERVER['OCR_SPACE_API_KEY'] ?? null,
            defined('OCR_SPACE_API_KEY') ? constant('OCR_SPACE_API_KEY') : null
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if (
                $candidate !== '' &&
                stripos($candidate, 'PASTE_YOUR_') !== 0 &&
                stripos($candidate, 'your_') !== 0
            ) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('verifyGovernmentIdWithOcrApi')) {
    function verifyGovernmentIdWithOcrApi($first_name, $last_name, $uploaded_file, $address = '')
    {
        if (!is_array($uploaded_file) || (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID file was not uploaded correctly.'
            ];
        }

        $tmp_path = (string)($uploaded_file['tmp_name'] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Uploaded government ID file is invalid.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'ID verification service is unavailable on this server (cURL missing).'
            ];
        }

        $api_key = getGovernmentIdVerificationApiKey();
        if ($api_key === '') {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'ID verification API key is not configured. Add your OCR key in includes/local_credentials.php or set GOV_ID_OCR_API_KEY / OCR_SPACE_API_KEY.'
            ];
        }

        $mime_type = function_exists('mime_content_type') ? mime_content_type($tmp_path) : '';
        $original_name = trim((string)($uploaded_file['name'] ?? 'government_id_upload'));
        if ($original_name === '') {
            $original_name = 'government_id_upload';
        }

        $cfile = curl_file_create($tmp_path, $mime_type !== '' ? $mime_type : 'application/octet-stream', $original_name);
        $endpoint = 'https://api.ocr.space/parse/image';
        $post_fields = [
            'apikey' => $api_key,
            'language' => 'eng',
            'isOverlayRequired' => 'false',
            'OCREngine' => '2',
            'detectOrientation' => 'true',
            'scale' => 'true',
            'file' => $cfile
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $raw_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw_response === false) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID verification request failed: ' . ($curl_error !== '' ? $curl_error : 'network error')
            ];
        }

        $response_payload = json_decode((string)$raw_response, true);
        if (!is_array($response_payload)) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID verification returned an unreadable response.'
            ];
        }

        if ($http_code >= 400) {
            $api_error = '';
            if (!empty($response_payload['ErrorMessage'])) {
                $api_error = is_array($response_payload['ErrorMessage'])
                    ? implode(' ', array_map('strval', $response_payload['ErrorMessage']))
                    : (string)$response_payload['ErrorMessage'];
            }
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID verification failed (' . $http_code . '): ' . ($api_error !== '' ? $api_error : 'API error')
            ];
        }

        $is_errored = !empty($response_payload['IsErroredOnProcessing']);
        if ($is_errored) {
            $api_error = '';
            if (!empty($response_payload['ErrorMessage'])) {
                $api_error = is_array($response_payload['ErrorMessage'])
                    ? implode(' ', array_map('strval', $response_payload['ErrorMessage']))
                    : (string)$response_payload['ErrorMessage'];
            }
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID verification could not parse the uploaded image: ' . ($api_error !== '' ? $api_error : 'OCR processing failed')
            ];
        }

        $parsed_text = extractOcrParsedText($response_payload);
        $match = calculateIdentityProfileMatchScore($parsed_text, $first_name, $last_name, $address);
        $threshold = (float)($match['overall_threshold'] ?? 0.75);
        $verified = !empty($match['name_verified']) && !empty($match['address_verified']) && (float)$match['overall_score'] >= $threshold;

        $message = 'Unable to verify identity: registration details do not match the uploaded ID.';
        if ($verified) {
            $message = 'Identity verified: personal information on the uploaded government ID matches the registration details.';
        } elseif (empty($match['name_verified'])) {
            $message = 'Unable to verify identity: the name on the uploaded ID does not match the registration details.';
        } elseif (empty($match['address_verified']) && trim((string)$address) !== '') {
            $message = 'Unable to verify identity: the address on the uploaded ID does not sufficiently match the registration details.';
        }

        return [
            'success' => true,
            'verified' => $verified,
            'message' => $message,
            'provider' => 'ocr_space',
            'score' => (float)$match['overall_score'],
            'threshold' => $threshold,
            'match_mode' => (string)($match['match_mode'] ?? 'balanced'),
            'matched_tokens' => (int)$match['name_matched_tokens'],
            'total_tokens' => (int)$match['name_total_tokens'],
            'address_score' => (float)$match['address_score'],
            'address_matched_tokens' => (int)$match['address_matched_tokens'],
            'address_total_tokens' => (int)$match['address_total_tokens'],
            'matched_address_tokens' => $match['matched_address_tokens'],
            'ocr_text_excerpt' => substr($parsed_text, 0, 500)
        ];
    }
}
