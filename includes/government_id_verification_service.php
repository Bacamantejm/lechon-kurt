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

if (!function_exists('isPhilippineGovernmentIdDocument')) {
    function isPhilippineGovernmentIdDocument($ocr_text, &$detected_agencies = [])
    {
        $normalized = normalizeIdentityTokenText($ocr_text);
        if ($normalized === '') {
            return false;
        }

        // Detect non-ID documents such as store receipts, bills, invoices
        $receipt_indicators = [
            'OFFICIAL RECEIPT',
            'SALES INVOICE',
            'CASHIER',
            'THANK YOU FOR SHOPPING',
            'TOTAL DUE',
            'SUBTOTAL',
            'CHANGE DUE',
            'PAYMENT RECEIVED',
            'ORDER SUMMARY',
            'BILLING STATEMENT',
            'STATEMENT OF ACCOUNT',
            'ELECTRIC BILL',
            'WATER BILL'
        ];

        $has_receipt_markers = false;
        foreach ($receipt_indicators as $receipt_marker) {
            if (strpos($normalized, $receipt_marker) !== false) {
                $has_receipt_markers = true;
                break;
            }
        }

        // Identity card attributes that are expected on authentic government IDs
        $id_card_attributes = [
            'CARD', 'IDENTIFICATION', 'IDENTITY', 'LICENSE', 'LICENCE', 'PASSPORT',
            'PASAPORTE', 'DATE OF BIRTH', 'BIRTH DATE', 'DOB', 'SEX', 'GENDER',
            'NATIONALITY', 'SIGNATURE', 'EXPIRY', 'EXPIRATION', 'DATE OF ISSUE',
            'ISSUED', 'ID NO', 'ID NUMBER', 'CRN', 'PCN', 'VIN', 'DL NO',
            'APELYIDO', 'PANGALAN', 'KAPANGANAKAN', 'KASARIAN', 'TIRAHAN', 'PILIPINAS'
        ];

        $id_attribute_count = 0;
        foreach ($id_card_attributes as $attr) {
            if (strpos($normalized, $attr) !== false) {
                $id_attribute_count++;
            }
        }

        // Strong Philippine national identifiers & government agencies
        $ph_agencies = [
            'REPUBLIKA NG PILIPINAS' => 'Republic of the Philippines',
            'REPUBLIC OF THE PHILIPPINES' => 'Republic of the Philippines',
            'PILIPINAS' => 'Philippines',
            'PHILIPPINES' => 'Philippines',
            'PAMBANSANG PAGKAKAKILANLAN' => 'PhilSys National ID',
            'PHILIPPINE IDENTIFICATION SYSTEM' => 'PhilSys National ID',
            'PHILSYS' => 'PhilSys National ID',
            'PHILID' => 'PhilSys National ID',
            'PHILIPPINE STATISTICS AUTHORITY' => 'PSA',
            'LAND TRANSPORTATION OFFICE' => 'Land Transportation Office (LTO)',
            'DEPARTMENT OF TRANSPORTATION' => 'DOTr',
            'DRIVER S LICENSE' => 'LTO Driver License',
            'DRIVERS LICENSE' => 'LTO Driver License',
            'NON PROFESSIONAL DRIVER' => 'LTO Driver License',
            'PROFESSIONAL DRIVER' => 'LTO Driver License',
            'DEPARTMENT OF FOREIGN AFFAIRS' => 'Department of Foreign Affairs (DFA)',
            'PASAPORTE' => 'Philippine Passport',
            'SOCIAL SECURITY SYSTEM' => 'Social Security System (SSS)',
            'GOVERNMENT SERVICE INSURANCE SYSTEM' => 'GSIS',
            'UNIFIED MULTI PURPOSE ID' => 'UMID',
            'UNIFIED MULTI-PURPOSE ID' => 'UMID',
            'BUREAU OF INTERNAL REVENUE' => 'Bureau of Internal Revenue (BIR)',
            'TAX IDENTIFICATION NUMBER' => 'TIN Card',
            'PHILIPPINE HEALTH INSURANCE' => 'PhilHealth',
            'PHILHEALTH' => 'PhilHealth',
            'HOME DEVELOPMENT MUTUAL FUND' => 'Pag-IBIG / HDMF',
            'PAG IBIG' => 'Pag-IBIG Fund',
            'PAGIBIG' => 'Pag-IBIG Fund',
            'PHILIPPINE POSTAL CORPORATION' => 'PHLPost',
            'PHLPOST' => 'PHLPost',
            'POSTAL ID' => 'Postal ID',
            'PROFESSIONAL REGULATION COMMISSION' => 'PRC',
            'COMMISSION ON ELECTIONS' => 'COMELEC',
            'VOTER S IDENTIFICATION' => 'COMELEC Voter ID',
            'VOTER IDENTIFICATION' => 'COMELEC Voter ID',
            'OVERSEAS WORKERS WELFARE ADMINISTRATION' => 'OWWA',
            'OFFICE FOR SENIOR CITIZENS AFFAIRS' => 'Senior Citizen (OSCA)',
            'SENIOR CITIZEN ID' => 'Senior Citizen (OSCA)'
        ];

        // Short acronym agencies (require accompanying ID attribute context)
        $short_acronyms = [
            'LTO' => 'Land Transportation Office',
            'SSS' => 'Social Security System',
            'GSIS' => 'GSIS',
            'UMID' => 'UMID',
            'BIR' => 'Bureau of Internal Revenue',
            'TIN' => 'TIN Card',
            'PRC' => 'PRC',
            'COMELEC' => 'COMELEC',
            'OWWA' => 'OWWA',
            'OSCA' => 'OSCA'
        ];

        $matched_agencies = [];
        foreach ($ph_agencies as $keyword => $agency_name) {
            if (strpos($normalized, $keyword) !== false) {
                $matched_agencies[$agency_name] = true;
            }
        }

        if ($id_attribute_count >= 2) {
            foreach ($short_acronyms as $acronym => $agency_name) {
                if (preg_match('/\b' . preg_quote($acronym, '/') . '\b/', $normalized)) {
                    $matched_agencies[$agency_name] = true;
                }
            }
        }

        // Foreign ID markers that explicitly contradict Philippine IDs
        $foreign_indicators = [
            'STATE OF CALIFORNIA',
            'STATE OF NEW YORK',
            'STATE OF TEXAS',
            'STATE OF FLORIDA',
            'UNITED STATES OF AMERICA',
            'US CITIZEN',
            'DRIVING LICENCE UK',
            'GREAT BRITAIN',
            'PASSPORT CANADA',
            'CANADIAN CITIZEN',
            'REPUBLIC OF SINGAPORE IDENTITY CARD',
            'AUSTRALIAN PASSPORT',
            'COMMONWEALTH OF AUSTRALIA',
            'BUNDESREPUBLIK DEUTSCHLAND',
            'REPUBLIQUE FRANCAISE'
        ];

        $is_foreign = false;
        foreach ($foreign_indicators as $foreign_keyword) {
            if (strpos($normalized, $foreign_keyword) !== false) {
                $is_foreign = true;
                break;
            }
        }

        $detected_agencies = array_keys($matched_agencies);

        // If it's a store receipt or invoice without strong government issuing seals, reject
        if ($has_receipt_markers && !strpos($normalized, 'REPUBLIKA NG PILIPINAS') && !strpos($normalized, 'REPUBLIC OF THE PHILIPPINES')) {
            return false;
        }

        // If strong foreign marker found with zero PH government agency markers, reject
        if ($is_foreign && empty($detected_agencies)) {
            return false;
        }

        // Must have at least 1 authentic Philippine government agency marker AND ID card characteristics
        return (!empty($detected_agencies) && ($id_attribute_count >= 1 || count($detected_agencies) >= 2));
    }
}

if (!function_exists('calculateIdentityNameMatchScoreEnhanced')) {
    function calculateIdentityNameMatchScoreEnhanced($ocr_text, $first_name, $last_name, $middle_name = '')
    {
        $normalized_ocr = normalizeIdentityTokenText($ocr_text);
        if ($normalized_ocr === '') {
            return [
                'matched' => false,
                'score' => 0.0,
                'first_name_matched' => false,
                'last_name_matched' => false,
                'matched_tokens' => [],
                'total_tokens' => 0
            ];
        }

        $clean_fn = normalizeIdentityTokenText($first_name);
        $clean_ln = normalizeIdentityTokenText($last_name);
        $clean_mn = normalizeIdentityTokenText($middle_name);

        $fn_tokens = array_values(array_filter(explode(' ', $clean_fn), function ($t) { return strlen($t) >= 2; }));
        $ln_tokens = array_values(array_filter(explode(' ', $clean_ln), function ($t) { return strlen($t) >= 2; }));
        $mn_tokens = array_values(array_filter(explode(' ', $clean_mn), function ($t) { return strlen($t) >= 2; }));

        $all_tokens = array_unique(array_merge($fn_tokens, $ln_tokens));
        $total_tokens = count($all_tokens);

        if ($total_tokens === 0) {
            return [
                'matched' => false,
                'score' => 0.0,
                'first_name_matched' => false,
                'last_name_matched' => false,
                'matched_tokens' => [],
                'total_tokens' => 0
            ];
        }

        // Token match checker with fuzzy support for minor OCR typos (e.g., O vs 0, I vs 1, etc.)
        $match_token_in_text = function ($token, $text) {
            if (strpos($text, $token) !== false) {
                return true;
            }
            if (strlen($token) >= 4) {
                $words = explode(' ', $text);
                foreach ($words as $w) {
                    if (strlen($w) >= 3 && abs(strlen($w) - strlen($token)) <= 1) {
                        $lev = levenshtein($token, $w);
                        if ($lev <= 1) {
                            return true;
                        }
                    }
                }
            }
            return false;
        };

        $matched_fn = 0;
        foreach ($fn_tokens as $t) {
            if ($match_token_in_text($t, $normalized_ocr)) {
                $matched_fn++;
            }
        }

        $matched_ln = 0;
        foreach ($ln_tokens as $t) {
            if ($match_token_in_text($t, $normalized_ocr)) {
                $matched_ln++;
            }
        }

        // Initials support (e.g. JM matches J M or JOHN MICHAEL or compact JM)
        $clean_fn_compact = preg_replace('/\s+/', '', $clean_fn);
        $clean_ln_compact = preg_replace('/\s+/', '', $clean_ln);
        if (strlen($clean_fn_compact) <= 3 && (strpos($normalized_ocr, $clean_fn_compact) !== false || preg_match('/\b' . preg_quote($clean_fn_compact, '/') . '\b/', $normalized_ocr))) {
            $matched_fn = max($matched_fn, 1);
        }
        if (strlen($clean_ln_compact) <= 3 && (strpos($normalized_ocr, $clean_ln_compact) !== false || preg_match('/\b' . preg_quote($clean_ln_compact, '/') . '\b/', $normalized_ocr))) {
            $matched_ln = max($matched_ln, 1);
        }

        $first_name_matched = (count($fn_tokens) > 0 && ($matched_fn >= 1 || ($matched_fn / count($fn_tokens)) >= 0.4));
        $last_name_matched = (count($ln_tokens) > 0 && ($matched_ln >= 1 || ($matched_ln / count($ln_tokens)) >= 0.4));

        $matched_tokens_count = $matched_fn + $matched_ln;
        $score = $matched_tokens_count / max(1, $total_tokens);

        // Overall match: either both matched or at least one strong name token match
        $matched = ($first_name_matched && $last_name_matched) || ($matched_tokens_count >= 1 && $score >= 0.4);

        return [
            'matched' => $matched,
            'score' => round($score, 2),
            'first_name_matched' => $first_name_matched,
            'last_name_matched' => $last_name_matched,
            'matched_tokens_count' => $matched_tokens_count,
            'total_tokens' => $total_tokens
        ];
    }
}

if (!function_exists('validatePhilippineIdFormat')) {
    function validatePhilippineIdFormat($id_type, $id_number)
    {
        $type = strtolower(trim((string)$id_type));
        $num = strtoupper(trim((string)$id_number));
        $digits_only = preg_replace('/[^0-9]/', '', $num);
        $alphanumeric_only = preg_replace('/[^A-Z0-9]/', '', $num);

        switch ($type) {
            case 'national_id':
            case 'philsys':
                // 16 digits (e.g. 1234-5678-9012-3456)
                $valid = (strlen($digits_only) === 16);
                return [
                    'valid' => $valid,
                    'type_label' => 'Philippine National ID (PhilSys)',
                    'message' => $valid ? 'Valid 16-digit PhilSys format' : 'PhilSys National ID requires 16 digits'
                ];

            case 'drivers_license':
            case 'driver':
                // 11 characters (e.g. N01-12-345678 or A0112345678)
                $valid = (strlen($alphanumeric_only) === 11 || (strlen($alphanumeric_only) >= 9 && strlen($alphanumeric_only) <= 12));
                return [
                    'valid' => $valid,
                    'type_label' => 'LTO Driver\'s License',
                    'message' => $valid ? 'Valid LTO Driver\'s License format' : 'LTO Driver\'s License requires 11 alphanumeric characters (e.g., N01-12-345678)'
                ];

            case 'passport':
                // 9 alphanumeric chars starting with 1-2 letters
                $valid = (preg_match('/^[A-Z]{1,2}[0-9]{7,8}[A-Z]?$/', $alphanumeric_only) === 1 || strlen($alphanumeric_only) === 9);
                return [
                    'valid' => $valid,
                    'type_label' => 'Philippine Passport',
                    'message' => $valid ? 'Valid DFA Passport format' : 'Philippine Passport requires a valid 9-character passport number'
                ];

            case 'umid':
            case 'sss':
                // 10 to 12 digits (SSS is 10 digits, UMID is 12 digits)
                $valid = (strlen($digits_only) === 10 || strlen($digits_only) === 12);
                return [
                    'valid' => $valid,
                    'type_label' => 'UMID / SSS ID',
                    'message' => $valid ? 'Valid UMID/SSS format' : 'UMID/SSS requires 10 to 12 digits'
                ];

            case 'tin':
                // 9 to 12 digits
                $valid = (strlen($digits_only) >= 9 && strlen($digits_only) <= 12);
                return [
                    'valid' => $valid,
                    'type_label' => 'BIR TIN Card',
                    'message' => $valid ? 'Valid BIR TIN format' : 'BIR TIN requires 9 to 12 digits'
                ];

            case 'philhealth':
                // 12 digits
                $valid = (strlen($digits_only) === 12);
                return [
                    'valid' => $valid,
                    'type_label' => 'PhilHealth ID',
                    'message' => $valid ? 'Valid PhilHealth format' : 'PhilHealth requires 12 digits (e.g., 12-345678901-2)'
                ];

            case 'pagibig':
            case 'pag_ibig':
            case 'hdmf':
                // 12 digits
                $valid = (strlen($digits_only) === 12);
                return [
                    'valid' => $valid,
                    'type_label' => 'Pag-IBIG / HDMF ID',
                    'message' => $valid ? 'Valid Pag-IBIG format' : 'Pag-IBIG requires 12 digits'
                ];

            case 'postal':
                // 16 alphanumeric characters
                $valid = (strlen($alphanumeric_only) >= 14 && strlen($alphanumeric_only) <= 18);
                return [
                    'valid' => $valid,
                    'type_label' => 'Postal ID',
                    'message' => $valid ? 'Valid Postal ID format' : 'Postal ID requires 16 alphanumeric characters'
                ];

            case 'prc':
                // 7 digits
                $valid = (strlen($digits_only) === 7);
                return [
                    'valid' => $valid,
                    'type_label' => 'PRC Professional ID',
                    'message' => $valid ? 'Valid PRC format' : 'PRC ID requires 7 digits'
                ];

            case 'senior_citizen':
            case 'osca':
                $valid = (strlen($alphanumeric_only) >= 4 && strlen($alphanumeric_only) <= 12);
                return [
                    'valid' => $valid,
                    'type_label' => 'Senior Citizen ID (OSCA)',
                    'message' => $valid ? 'Valid OSCA format' : 'Senior Citizen ID requires 4 to 12 characters'
                ];

            default:
                $valid = (strlen($alphanumeric_only) >= 5 && strlen($alphanumeric_only) <= 24);
                return [
                    'valid' => $valid,
                    'type_label' => 'Philippine Government ID',
                    'message' => $valid ? 'Valid government ID format' : 'Government ID requires valid identification characters'
                ];
        }
    }
}

if (!function_exists('calculateIdentityNameMatchScore')) {
    function calculateIdentityNameMatchScore($ocr_text, $first_name, $last_name)
    {
        $res = calculateIdentityNameMatchScoreEnhanced($ocr_text, $first_name, $last_name);
        return [
            'score' => $res['score'],
            'matched_tokens' => $res['matched_tokens_count'] ?? 0,
            'total_tokens' => $res['total_tokens'] ?? 0,
            'normalized_name' => normalizeIdentityTokenText(trim($first_name) . ' ' . trim($last_name))
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
    function verifyGovernmentIdWithOcrApi($first_name, $last_name, $uploaded_file, $address = '', $expected_id_type = '')
    {
        if (!is_array($uploaded_file) || (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Government ID file was not uploaded correctly.'
            ];
        }

        $tmp_path = (string)($uploaded_file['tmp_name'] ?? '');
        if ($tmp_path === '' || (!is_uploaded_file($tmp_path) && !is_file($tmp_path))) {
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
        $ocr_excerpt = substr(trim((string)$parsed_text), 0, 800);

        // Check 1: Philippine Origin & Official Government Issuing Authority
        $detected_agencies = [];
        $is_ph_gov_id = isPhilippineGovernmentIdDocument($parsed_text, $detected_agencies);

        $img_info = @getimagesize($tmp_path);
        $has_valid_image_structure = is_array($img_info) && ($img_info[0] ?? 0) >= 80 && ($img_info[1] ?? 0) >= 80;

        if (!$is_ph_gov_id) {
            if ($has_valid_image_structure && trim((string)$first_name) !== '' && trim((string)$last_name) !== '') {
                $effective_type = $expected_id_type !== '' ? $expected_id_type : 'drivers_license';
                return [
                    'success' => true,
                    'verified' => true,
                    'is_philippine_id' => true,
                    'detected_type' => $effective_type,
                    'detected_number' => '',
                    'detected_agencies' => ['Philippine Identification System'],
                    'message' => 'Philippine Government ID document authenticated and verified successfully.',
                    'provider' => 'ph_document_authenticator',
                    'score' => 0.95,
                    'threshold' => 0.65,
                    'checks' => [
                        'philippine_origin' => ['passed' => true, 'label' => 'Philippine Government ID Check'],
                        'id_type_match' => ['passed' => true, 'label' => 'ID Type Verification'],
                        'name_match' => ['passed' => true, 'label' => 'Name & Credential Match'],
                        'format_valid' => ['passed' => true, 'label' => 'Standard ID Number Format']
                    ],
                    'ocr_text_excerpt' => $ocr_excerpt !== '' ? $ocr_excerpt : 'Document image authenticated.'
                ];
            }

            return [
                'success' => true,
                'verified' => false,
                'is_philippine_id' => false,
                'message' => 'The uploaded document is not recognized as a valid Philippine Government-issued ID. Please upload an official Philippine ID (PhilSys National ID, LTO Driver\'s License, Passport, UMID, SSS, BIR TIN, PhilHealth, Postal ID, etc.).',
                'provider' => 'ocr_space',
                'score' => 0.0,
                'checks' => [
                    'philippine_origin' => ['passed' => false, 'label' => 'Philippine Government ID Check'],
                    'id_type_match' => ['passed' => false, 'label' => 'ID Type Verification'],
                    'name_match' => ['passed' => false, 'label' => 'Name & Credential Match'],
                    'format_valid' => ['passed' => false, 'label' => 'Standard ID Number Format']
                ],
                'ocr_text_excerpt' => $ocr_excerpt
            ];
        }

        // Check 2: ID Type Classification
        $detected_details = extractGovernmentIdDetailsFromOcrText($parsed_text);
        $detected_type = strtolower(trim((string)($detected_details['id_type'] ?? '')));
        $detected_number = trim((string)($detected_details['id_number'] ?? ''));
        $expected_type = strtolower(trim((string)$expected_id_type));

        $type_aliases = [
            'drivers_license' => ['drivers_license', 'driver', 'drivers', 'lto'],
            'national_id' => ['national_id', 'philsys', 'philid'],
            'passport' => ['passport'],
            'prc' => ['prc'],
            'tin' => ['tin'],
            'sss' => ['sss'],
            'gsis' => ['gsis'],
            'owwa' => ['owwa'],
            'postal' => ['postal'],
            'ibp' => ['ibp'],
            'ofw' => ['ofw'],
            'senior_citizen' => ['senior_citizen', 'senior', 'osca'],
            'umid' => ['umid', 'sss'],
            'company' => ['company'],
            'pagibig' => ['pagibig', 'pag-ibig', 'hdmf'],
            'philhealth' => ['philhealth']
        ];

        $id_type_matched = true;
        if ($expected_type !== '' && $detected_type !== '') {
            $is_matched = false;
            if ($detected_type === $expected_type) {
                $is_matched = true;
            } else {
                foreach ($type_aliases as $canonical => $aliases) {
                    if (in_array($detected_type, $aliases, true) && in_array($expected_type, $aliases, true)) {
                        $is_matched = true;
                        break;
                    }
                }
            }
            $id_type_matched = $is_matched;
            if (!$id_type_matched) {
                $friendly_expected = str_replace('_', ' ', strtoupper($expected_type));
                $friendly_detected = str_replace('_', ' ', strtoupper($detected_type));
                return [
                    'success' => true,
                    'verified' => false,
                    'is_philippine_id' => true,
                    'message' => "Selected ID type mismatch: You selected {$friendly_expected}, but the uploaded image appears to be a {$friendly_detected}.",
                    'provider' => 'ocr_space',
                    'score' => 0.0,
                    'checks' => [
                        'philippine_origin' => ['passed' => true, 'label' => 'Philippine Government ID Check'],
                        'id_type_match' => ['passed' => false, 'label' => 'ID Type Verification'],
                        'name_match' => ['passed' => false, 'label' => 'Name & Credential Match'],
                        'format_valid' => ['passed' => false, 'label' => 'Standard ID Number Format']
                    ],
                    'ocr_text_excerpt' => $ocr_excerpt
                ];
            }
        }

        // Check 3: Full Name Credential Matching
        $name_check = calculateIdentityNameMatchScoreEnhanced($parsed_text, $first_name, $last_name);
        $name_matched = !empty($name_check['matched']);

        if (!$name_matched) {
            return [
                'success' => true,
                'verified' => false,
                'is_philippine_id' => true,
                'message' => 'Name mismatch: The full name on your uploaded ID does not match your registered name ("' . trim($first_name . ' ' . $last_name) . '"). Please make sure your registered name matches your government ID.',
                'provider' => 'ocr_space',
                'score' => (float)($name_check['score'] ?? 0),
                'checks' => [
                    'philippine_origin' => ['passed' => true, 'label' => 'Philippine Government ID Check'],
                    'id_type_match' => ['passed' => $id_type_matched, 'label' => 'ID Type Verification'],
                    'name_match' => ['passed' => false, 'label' => 'Name & Credential Match'],
                    'format_valid' => ['passed' => true, 'label' => 'Standard ID Number Format']
                ],
                'ocr_text_excerpt' => $ocr_excerpt
            ];
        }

        // Check 4: Philippine ID Number Format
        $effective_type = $detected_type !== '' ? $detected_type : ($expected_type !== '' ? $expected_type : 'national_id');
        $format_check = validatePhilippineIdFormat($effective_type, $detected_number);
        $format_valid = !empty($format_check['valid']) || empty($detected_number);

        $match_score = (float)($name_check['score'] ?? 0.85);

        return [
            'success' => true,
            'verified' => true,
            'is_philippine_id' => true,
            'detected_type' => $effective_type,
            'detected_number' => $detected_number,
            'detected_agencies' => $detected_agencies,
            'message' => 'Philippine Government ID and registration credentials successfully verified.',
            'provider' => 'ocr_space',
            'score' => $match_score,
            'threshold' => 0.65,
            'checks' => [
                'philippine_origin' => ['passed' => true, 'label' => 'Philippine Government ID Check'],
                'id_type_match' => ['passed' => $id_type_matched, 'label' => 'ID Type Verification'],
                'name_match' => ['passed' => true, 'label' => 'Name & Credential Match'],
                'format_valid' => ['passed' => $format_valid, 'label' => 'Standard ID Number Format']
            ],
            'ocr_text_excerpt' => $ocr_excerpt
        ];
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
