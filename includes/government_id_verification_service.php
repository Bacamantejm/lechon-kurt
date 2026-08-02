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
