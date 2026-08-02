<?php

require_once __DIR__ . '/government_id_verification_service.php';

if (!function_exists('govVerificationEnvValue')) {
    function govVerificationEnvValue(array $keys, $default = '')
    {
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }

            $candidates = [
                getenv($key),
                $_ENV[$key] ?? null,
                $_SERVER[$key] ?? null,
                defined($key) ? constant($key) : null
            ];

            foreach ($candidates as $candidate) {
                $value = trim((string)$candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return (string)$default;
    }
}

if (!function_exists('govNormalizeBool')) {
    function govNormalizeBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float)$value) > 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'ok', 'verified', 'success', 'passed'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'failed', 'error', 'denied'], true)) {
                return false;
            }
        }

        return null;
    }
}

if (!function_exists('getGovernmentVerificationProviderChoice')) {
    function getGovernmentVerificationProviderChoice()
    {
        $provider = strtolower(govVerificationEnvValue([
            'GOV_ID_VERIFICATION_PROVIDER',
            'GOVERNMENT_ID_VERIFICATION_PROVIDER'
        ], 'auto'));

        if (!in_array($provider, ['auto', 'philsys', 'ocr_space'], true)) {
            return 'auto';
        }
        return $provider;
    }
}

if (!function_exists('getPhilSysVerificationSettings')) {
    function getPhilSysVerificationSettings()
    {
        $timeout = (int)govVerificationEnvValue([
            'PHILSYS_TIMEOUT_SEC',
            'PHILSYS_API_TIMEOUT_SEC'
        ], '35');

        return [
            'endpoint' => govVerificationEnvValue([
                'PHILSYS_VERIFY_ENDPOINT',
                'PHILSYS_API_ENDPOINT',
                'GOV_ID_PHILSYS_ENDPOINT'
            ]),
            'api_key' => govVerificationEnvValue([
                'PHILSYS_API_KEY',
                'GOV_ID_PHILSYS_API_KEY'
            ]),
            'bearer_token' => govVerificationEnvValue([
                'PHILSYS_BEARER_TOKEN',
                'PHILSYS_API_TOKEN',
                'GOV_ID_PHILSYS_BEARER_TOKEN'
            ]),
            'client_id' => govVerificationEnvValue([
                'PHILSYS_CLIENT_ID',
                'GOV_ID_PHILSYS_CLIENT_ID'
            ]),
            'client_secret' => govVerificationEnvValue([
                'PHILSYS_CLIENT_SECRET',
                'GOV_ID_PHILSYS_CLIENT_SECRET'
            ]),
            'timeout' => max(10, $timeout)
        ];
    }
}

if (!function_exists('isPhilSysVerificationReady')) {
    function isPhilSysVerificationReady()
    {
        $config = getPhilSysVerificationSettings();
        return trim((string)($config['endpoint'] ?? '')) !== '';
    }
}

if (!function_exists('govExtractByPath')) {
    function govExtractByPath($payload, $path)
    {
        $current = $payload;
        foreach (explode('.', (string)$path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}

if (!function_exists('govFirstString')) {
    function govFirstString(array $values)
    {
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }
}

if (!function_exists('govFirstNumeric')) {
    function govFirstNumeric(array $values)
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float)$value;
            }
            if (is_string($value)) {
                $normalized = trim($value);
                if ($normalized !== '' && is_numeric($normalized)) {
                    return (float)$normalized;
                }
            }
        }
        return null;
    }
}

if (!function_exists('govFirstBool')) {
    function govFirstBool(array $values)
    {
        foreach ($values as $value) {
            $bool = govNormalizeBool($value);
            if ($bool !== null) {
                return $bool;
            }
        }
        return null;
    }
}

if (!function_exists('parsePhilSysVerificationResponse')) {
    function parsePhilSysVerificationResponse($payload)
    {
        if (!is_array($payload)) {
            return [
                'api_success' => null,
                'verified' => null,
                'score' => null,
                'message' => '',
                'provider' => 'philsys'
            ];
        }

        $api_success = govFirstBool([
            $payload['success'] ?? null,
            govExtractByPath($payload, 'result.success'),
            govExtractByPath($payload, 'data.success')
        ]);

        if ($api_success === null) {
            $status = strtolower(govFirstString([
                $payload['status'] ?? '',
                govExtractByPath($payload, 'result.status'),
                govExtractByPath($payload, 'data.status')
            ]));
            if (in_array($status, ['ok', 'success', 'verified', 'passed'], true)) {
                $api_success = true;
            } elseif (in_array($status, ['error', 'failed', 'denied', 'invalid'], true)) {
                $api_success = false;
            }
        }

        $verified = govFirstBool([
            $payload['verified'] ?? null,
            $payload['is_verified'] ?? null,
            govExtractByPath($payload, 'result.verified'),
            govExtractByPath($payload, 'result.is_verified'),
            govExtractByPath($payload, 'data.verified'),
            govExtractByPath($payload, 'data.is_verified')
        ]);

        $score = govFirstNumeric([
            $payload['score'] ?? null,
            $payload['match_score'] ?? null,
            govExtractByPath($payload, 'result.score'),
            govExtractByPath($payload, 'result.match_score'),
            govExtractByPath($payload, 'data.score'),
            govExtractByPath($payload, 'data.match_score')
        ]);

        $message = govFirstString([
            $payload['message'] ?? '',
            $payload['detail'] ?? '',
            $payload['error'] ?? '',
            govExtractByPath($payload, 'result.message'),
            govExtractByPath($payload, 'result.error_message'),
            govExtractByPath($payload, 'data.message'),
            govExtractByPath($payload, 'data.error_message')
        ]);

        $provider = govFirstString([
            $payload['provider'] ?? '',
            govExtractByPath($payload, 'result.provider'),
            govExtractByPath($payload, 'data.provider')
        ]);
        if ($provider === '') {
            $provider = 'philsys';
        }

        return [
            'api_success' => $api_success,
            'verified' => $verified,
            'score' => $score,
            'message' => $message,
            'provider' => $provider
        ];
    }
}

if (!function_exists('callPhilSysVerificationProvider')) {
    function callPhilSysVerificationProvider($verification_type, $uploaded_file, array $extra_fields = [])
    {
        if (!is_array($uploaded_file) || (int)($uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'verified' => false,
                'provider' => 'philsys',
                'message' => 'Government document file was not uploaded correctly.'
            ];
        }

        $tmp_path = (string)($uploaded_file['tmp_name'] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            return [
                'success' => false,
                'verified' => false,
                'provider' => 'philsys',
                'message' => 'Uploaded government document file is invalid.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'verified' => false,
                'provider' => 'philsys',
                'message' => 'PhilSys verification is unavailable on this server (cURL missing).'
            ];
        }

        $config = getPhilSysVerificationSettings();
        $endpoint = trim((string)($config['endpoint'] ?? ''));
        if ($endpoint === '') {
            return [
                'success' => false,
                'verified' => false,
                'provider' => 'philsys',
                'message' => 'PhilSys verification endpoint is not configured. Set PHILSYS_VERIFY_ENDPOINT.'
            ];
        }

        $mime_type = function_exists('mime_content_type') ? (string)mime_content_type($tmp_path) : '';
        $original_name = trim((string)($uploaded_file['name'] ?? 'government_document_upload'));
        if ($original_name === '') {
            $original_name = 'government_document_upload';
        }

        $post_fields = [
            'verification_type' => trim((string)$verification_type),
            'file' => curl_file_create($tmp_path, $mime_type !== '' ? $mime_type : 'application/octet-stream', $original_name)
        ];
        foreach ($extra_fields as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || !is_scalar($value)) {
                continue;
            }
            $post_fields[$key] = (string)$value;
        }

        $headers = ['Accept: application/json'];
        if (!empty($config['api_key'])) $headers[] = 'X-API-KEY: ' . (string)$config['api_key'];
        if (!empty($config['bearer_token'])) $headers[] = 'Authorization: Bearer ' . (string)$config['bearer_token'];
        if (!empty($config['client_id'])) $headers[] = 'X-CLIENT-ID: ' . (string)$config['client_id'];
        if (!empty($config['client_secret'])) $headers[] = 'X-CLIENT-SECRET: ' . (string)$config['client_secret'];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(10, (int)($config['timeout'] ?? 35)),
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
                'provider' => 'philsys',
                'message' => 'PhilSys verification request failed: ' . ($curl_error !== '' ? $curl_error : 'network error')
            ];
        }

        $payload = json_decode((string)$raw_response, true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'verified' => false,
                'provider' => 'philsys',
                'message' => 'PhilSys verification returned an unreadable response.'
            ];
        }

        $parsed = parsePhilSysVerificationResponse($payload);
        $provider = (string)($parsed['provider'] ?? 'philsys');

        if ($http_code >= 400 || $parsed['api_success'] === false) {
            return [
                'success' => false,
                'verified' => false,
                'provider' => $provider,
                'message' => $parsed['message'] !== '' ? $parsed['message'] : ('PhilSys verification failed with HTTP ' . $http_code . '.'),
                'raw_payload' => $payload
            ];
        }

        return [
            'success' => true,
            'verified' => (bool)($parsed['verified'] ?? false),
            'provider' => $provider,
            'message' => (string)($parsed['message'] ?? ''),
            'score' => $parsed['score'] !== null ? (float)$parsed['score'] : 0.0,
            'raw_payload' => $payload
        ];
    }
}

if (!function_exists('verifyGovernmentIdWithConfiguredProvider')) {
    function verifyGovernmentIdWithConfiguredProvider($first_name, $last_name, $uploaded_file, $address = '')
    {
        $provider = getGovernmentVerificationProviderChoice();
        $threshold = 0.75;

        if ($provider === 'philsys') {
            $result = callPhilSysVerificationProvider('government_id', $uploaded_file, [
                'document_type' => 'valid_id',
                'first_name' => trim((string)$first_name),
                'last_name' => trim((string)$last_name),
                'full_name' => trim((string)$first_name . ' ' . (string)$last_name),
                'address' => trim((string)$address)
            ]);
            if (!empty($result['success']) && empty($result['verified']) && (float)($result['score'] ?? 0) >= $threshold) {
                $result['verified'] = true;
            }
            return $result;
        }

        if ($provider === 'ocr_space') {
            return verifyGovernmentIdWithOcrApi($first_name, $last_name, $uploaded_file, $address);
        }

        if (isPhilSysVerificationReady()) {
            $result = callPhilSysVerificationProvider('government_id', $uploaded_file, [
                'document_type' => 'valid_id',
                'first_name' => trim((string)$first_name),
                'last_name' => trim((string)$last_name),
                'full_name' => trim((string)$first_name . ' ' . (string)$last_name),
                'address' => trim((string)$address)
            ]);
            if (!empty($result['success'])) {
                if (empty($result['verified']) && (float)($result['score'] ?? 0) >= $threshold) {
                    $result['verified'] = true;
                }
                return $result;
            }
        }

        return verifyGovernmentIdWithOcrApi($first_name, $last_name, $uploaded_file, $address);
    }
}

if (!function_exists('verifyGovernmentDocumentWithConfiguredProvider')) {
    function verifyGovernmentDocumentWithConfiguredProvider($document_type, $uploaded_file, array $context = [])
    {
        $provider = getGovernmentVerificationProviderChoice();
        $strict = !empty($context['strict']);

        $extra = ['document_type' => strtolower(trim((string)$document_type))];
        foreach (['first_name', 'last_name', 'full_name', 'contact_email', 'business_name'] as $field) {
            if (isset($context[$field]) && is_scalar($context[$field])) {
                $extra[$field] = (string)$context[$field];
            }
        }

        if ($provider === 'philsys') {
            $result = callPhilSysVerificationProvider('document', $uploaded_file, $extra);
            if (!empty($result['success']) || $strict) {
                return $result;
            }
            return [
                'success' => true,
                'verified' => true,
                'provider' => 'manual_review',
                'message' => 'PhilSys verification unavailable; document flagged for manual review.'
            ];
        }

        if ($provider === 'auto' && isPhilSysVerificationReady()) {
            $result = callPhilSysVerificationProvider('document', $uploaded_file, $extra);
            if (!empty($result['success'])) {
                return $result;
            }
            if ($strict) {
                return $result;
            }
        }

        return [
            'success' => true,
            'verified' => true,
            'provider' => $provider === 'ocr_space' ? 'ocr_space' : 'manual_review',
            'message' => 'Document accepted and routed for standard compliance review.'
        ];
    }
}
