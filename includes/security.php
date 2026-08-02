<?php
/**
 * Security Helper Functions
 * Comprehensive security utilities for input validation, CSRF protection, and more
 */

if (!defined('SECURITY_CSRF_TTL')) {
    define('SECURITY_CSRF_TTL', 3600);
}

if (!defined('SECURITY_SESSION_ROTATION_TTL')) {
    define('SECURITY_SESSION_ROTATION_TTL', 1800);
}

/**
 * Ensure security helpers have access to an active PHP session.
 * @return bool
 */
function ensureSecuritySessionStarted() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    if (headers_sent()) {
        return false;
    }

    return session_start();
}

// =====================================================
// EMAIL VALIDATION
// =====================================================

/**
 * Advanced email validation with typo detection
 * Catches common mistakes like .colm, .ocm, .con, etc.
 */
function validateEmail($email, &$suggestion = null) {
    $email = trim(strtolower($email));
    
    // Basic format check
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Common domain typos
    $common_typos = [
        '.colm' => '.com',
        '.ocm' => '.com',
        '.cmo' => '.com',
        '.con' => '.com',
        '.vom' => '.com',
        '.xom' => '.com',
        '.coml' => '.com',
        '.comm' => '.com',
        '.coom' => '.com',
        '.cim' => '.com',
        '.cum' => '.com',
        'gmial.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gmaiL.com' => 'gmail.com',
        'yahooo.com' => 'yahoo.com',
        'yaho.com' => 'yahoo.com',
        'outloo.com' => 'outlook.com',
        'outlok.com' => 'outlook.com',
        'hotmial.com' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com'
    ];
    
    // Check for typos
    foreach ($common_typos as $typo => $correct) {
        if (str_ends_with($email, $typo)) {
            $suggestion = str_replace($typo, $correct, $email);
            return false; // Invalid due to typo
        }
    }
    
    // Check for common valid TLDs
    $valid_tlds = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'ph', 'co', 'io', 'ai'];
    $domain_parts = explode('@', $email);
    
    if (count($domain_parts) === 2) {
        $domain = $domain_parts[1];
        $tld_parts = explode('.', $domain);
        $tld = end($tld_parts);
        
        // If TLD is suspicious (not in common list and looks like a typo)
        if (strlen($tld) === 3 && !in_array($tld, $valid_tlds)) {
            // Suggest .com as most common
            if (levenshtein($tld, 'com') <= 1) {
                $suggestion = str_replace('.' . $tld, '.com', $email);
                return false;
            }
        }
    }
    
    // Check for disposable/temporary email domains
    $disposable_domains = [
        '10minutemail.com', 'tempmail.com', 'guerrillamail.com', 
        'mailinator.com', 'throwaway.email', 'temp-mail.org'
    ];
    
    foreach ($disposable_domains as $disposable) {
        if (str_ends_with($email, '@' . $disposable)) {
            $suggestion = "Please use a permanent email address";
            return false;
        }
    }
    
    return true;
}

/**
 * Validate and sanitize email
 */
function sanitizeEmail($email) {
    $email = trim($email);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return strtolower($email);
}

// =====================================================
// CSRF PROTECTION
// =====================================================

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!ensureSecuritySessionStarted()) {
        return '';
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) ||
        (time() - intval($_SESSION['csrf_token_time'])) > SECURITY_CSRF_TTL) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    if (!ensureSecuritySessionStarted()) {
        return false;
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }

    if (!is_string($token) || $token === '') {
        return false;
    }

    $stored_token = $_SESSION['csrf_token'];
    if (!is_string($stored_token) || $stored_token === '') {
        return false;
    }

    // Check if token is expired
    if ((time() - intval($_SESSION['csrf_token_time'])) > SECURITY_CSRF_TTL) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    // Use hash_equals to prevent timing attacks
    return hash_equals($stored_token, $token);
}

/**
 * Get CSRF token input field HTML
 */
function getCSRFTokenField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// =====================================================
// RATE LIMITING
// =====================================================

/**
 * Check rate limit for an action
 * @param string $action - Action identifier (e.g., 'login', 'register')
 * @param int $max_attempts - Maximum attempts allowed
 * @param int $time_window - Time window in seconds
 */
function checkRateLimit($action, $max_attempts = 5, $time_window = 300) {
    if (!ensureSecuritySessionStarted()) {
        return ['allowed' => false, 'remaining_time' => $time_window];
    }

    $action = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$action);
    if ($action === '') {
        $action = 'default';
    }

    $max_attempts = max(1, intval($max_attempts));
    $time_window = max(1, intval($time_window));

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . $action . '_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time()
        ];
    }
    
    $rate_limit = &$_SESSION[$key];
    
    // Reset if time window has passed
    if ((time() - $rate_limit['first_attempt']) > $time_window) {
        $rate_limit = [
            'attempts' => 0,
            'first_attempt' => time()
        ];
    }
    
    // Check if limit exceeded
    if ($rate_limit['attempts'] >= $max_attempts) {
        $remaining_time = $time_window - (time() - $rate_limit['first_attempt']);
        return [
            'allowed' => false,
            'remaining_time' => $remaining_time
        ];
    }
    
    // Increment attempts
    $rate_limit['attempts']++;
    
    return [
        'allowed' => true,
        'attempts' => $rate_limit['attempts'],
        'remaining' => $max_attempts - $rate_limit['attempts']
    ];
}

/**
 * Clear rate limit for an action (useful for unlocking users)
 */
function clearRateLimit($action) {
    if (!ensureSecuritySessionStarted()) {
        return;
    }

    $action = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$action);
    if ($action === '') {
        $action = 'default';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . $action . '_' . md5($ip);
    unset($_SESSION[$key]);
}

// =====================================================
// INPUT SANITIZATION
// =====================================================

/**
 * Sanitize string input (remove HTML tags, trim)
 */
function sanitizeString($input) {
    if ($input === null) {
        return '';
    }

    $input = trim((string)$input);
    $input = strip_tags($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Sanitize integer input
 */
function sanitizeInt($input) {
    return intval($input);
}

/**
 * Sanitize float input
 */
function sanitizeFloat($input) {
    return floatval($input);
}

/**
 * Sanitize phone number (convert to standard 09XXXXXXXXX format)
 */
function sanitizePhone($phone) {
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Convert +639XXXXXXXXX to 09XXXXXXXXX
    if (preg_match('/^\+639(\d{9})$/', $phone, $matches)) {
        return '09' . $matches[1];
    }
    
    // Convert 639XXXXXXXXX to 09XXXXXXXXX
    if (preg_match('/^639(\d{9})$/', $phone, $matches)) {
        return '09' . $matches[1];
    }
    
    // Remove all non-numeric (to handle 09XXXXXXXXX)
    return preg_replace('/[^0-9]/', '', $phone);
}

/**
 * Validate phone number (Philippines - 11 digits starting with 09)
 */
function validatePhoneNumber($phone) {
    // Remove spaces, dashes, parentheses
    $cleaned = preg_replace('/[\s\-\(\)]/', '', $phone);
    
    // Valid Philippine mobile formats:
    // 09XXXXXXXXX (11 digits)
    // +639XXXXXXXXX (13 chars)
    // 639XXXXXXXXX (12 digits)
    $valid_formats = [
        '/^09\d{9}$/',           // 09171234567
        '/^\+639\d{9}$/',        // +639171234567
        '/^639\d{9}$/'           // 639171234567
    ];
    
    foreach ($valid_formats as $format) {
        if (preg_match($format, $cleaned)) {
            return true;
        }
    }
    
    return false;
}

// =====================================================
// PASSWORD SECURITY
// =====================================================

/**
 * Validate password strength
 */
function validatePasswordStrength($password, &$errors = []) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&*)";
    }
    
    // Check for common passwords
    $common_passwords = [
        'password', '12345678', 'qwerty123', 'password123', 
        'admin123', 'welcome123', 'letmein123'
    ];
    
    if (in_array(strtolower($password), $common_passwords, true)) {
        $errors[] = "Password is too common. Please choose a stronger password";
    }
    
    return empty($errors);
}

// =====================================================
// SESSION SECURITY
// =====================================================

/**
 * Regenerate session ID to prevent session fixation
 */
function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Secure session start with additional security measures
 */
function secureSessionStart() {
    if (session_status() === PHP_SESSION_NONE) {
        $is_https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

        // Set secure cookie parameters before session start.
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $is_https,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        } else {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            if ($is_https) {
                ini_set('session.cookie_secure', '1');
            }
        }

        session_start();
    }

    // Validate session lifetime
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif ((time() - intval($_SESSION['created'])) > SECURITY_SESSION_ROTATION_TTL) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }

    // Check for session hijacking
    validateSessionFingerprint();
}

/**
 * Create and validate session fingerprint to prevent hijacking
 */
function validateSessionFingerprint() {
    if (!ensureSecuritySessionStarted()) {
        return false;
    }

    $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $accept_language = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $fingerprint = hash('sha256', $user_agent . '|' . $accept_language);
    
    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
        return true;
    } elseif ($_SESSION['fingerprint'] !== $fingerprint) {
        // Fingerprint changed - refresh it instead of destroying session
        // This can happen when browser updates or user changes settings
        $_SESSION['fingerprint'] = $fingerprint;
        logSecurityEvent('SESSION_FINGERPRINT_UPDATED', 'Session fingerprint updated', 'INFO');
    }

    return true;
}

// =====================================================
// XSS PROTECTION
// =====================================================

/**
 * Escape output for display (prevent XSS)
 */
function escapeHTML($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Escape JavaScript string
 */
function escapeJS($string) {
    return json_encode($string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

// =====================================================
// FILE UPLOAD SECURITY
// =====================================================

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowed_types = ['image/jpeg', 'image/png', 'image/gif'], $max_size = 5242880) {
    $errors = [];
    
    // Check if file was uploaded
    if (!isset($file) || !is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "No file uploaded";
        return ['valid' => false, 'errors' => $errors];
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error (Code: {$file['error']})";
        return ['valid' => false, 'errors' => $errors];
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = "Invalid upload source";
        return ['valid' => false, 'errors' => $errors];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $errors[] = "File size exceeds maximum allowed (" . ($max_size / 1024 / 1024) . "MB)";
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        $errors[] = "Unable to inspect uploaded file type";
        return ['valid' => false, 'errors' => $errors];
    }

    $mime_type = finfo_file($finfo, $file['tmp_name']) ?: '';
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types, true)) {
        $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
    }
    
    // Check for double extensions
    $filename = isset($file['name']) ? (string)$file['name'] : '';
    if (preg_match('/\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|htm|shtml|sh|cgi)/i', $filename)) {
        $errors[] = "Invalid file extension detected";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'mime_type' => $mime_type
    ];
}

// =====================================================
// SQL INJECTION PREVENTION HELPERS
// =====================================================

/**
 * Prepare statement helper with error handling
 */
function prepareStatement($conn, $query) {
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . mysqli_error($conn));
        error_log("Query: " . $query);
        return false;
    }
    return $stmt;
}

// =====================================================
// LOGGING & AUDIT
// =====================================================

/**
 * Log security events
 */
function logSecurityEvent($event_type, $details = '', $severity = 'INFO') {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => $event_type,
        'severity' => $severity,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'user_id' => $_SESSION['user_id'] ?? 'guest',
        'details' => $details
    ];
    
    $log_file = __DIR__ . '/../logs/security_' . date('Y-m-d') . '.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        if (!mkdir($log_dir, 0755, true) && !is_dir($log_dir)) {
            error_log('Security Log Error: Unable to create log directory ' . $log_dir);
            return;
        }
    }
    
    $encoded = json_encode($log_entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        $encoded = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'LOG_ENCODING_ERROR',
            'severity' => 'ERROR',
            'details' => 'Unable to encode security log event'
        ]);
    }

    error_log($encoded . PHP_EOL, 3, $log_file);
}

?>
