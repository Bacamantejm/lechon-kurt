<?php

if (!function_exists('emailVerificationUserColumnExists')) {
    function emailVerificationUserColumnExists($conn, $column_name)
    {
        static $column_cache = [];
        $column_name = trim((string)$column_name);
        if ($column_name === '') {
            return false;
        }

        if (array_key_exists($column_name, $column_cache)) {
            return $column_cache[$column_name];
        }

        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE '{$safe_column}'");
        $exists = $result && mysqli_num_rows($result) > 0;
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }

        $column_cache[$column_name] = $exists;
        return $exists;
    }
}

if (!function_exists('emailVerificationUserIndexExists')) {
    function emailVerificationUserIndexExists($conn, $index_name)
    {
        static $index_cache = [];
        $index_name = trim((string)$index_name);
        if ($index_name === '') {
            return false;
        }

        if (array_key_exists($index_name, $index_cache)) {
            return $index_cache[$index_name];
        }

        $safe_index = mysqli_real_escape_string($conn, $index_name);
        $result = mysqli_query($conn, "SHOW INDEX FROM `users` WHERE Key_name = '{$safe_index}'");
        $exists = $result && mysqli_num_rows($result) > 0;
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }

        $index_cache[$index_name] = $exists;
        return $exists;
    }
}

if (!function_exists('ensureUserEmailVerificationSchema')) {
    function ensureUserEmailVerificationSchema($conn)
    {
        $columns = [
            'email_verified_at' => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL",
            'email_verification_token' => "ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(64) NULL DEFAULT NULL",
            'email_otp_code' => "ALTER TABLE users ADD COLUMN email_otp_code VARCHAR(10) NULL DEFAULT NULL",
            'email_verification_expires' => "ALTER TABLE users ADD COLUMN email_verification_expires DATETIME NULL DEFAULT NULL",
            'email_verification_sent_at' => "ALTER TABLE users ADD COLUMN email_verification_sent_at DATETIME NULL DEFAULT NULL",
        ];

        foreach ($columns as $column_name => $sql) {
            if (!emailVerificationUserColumnExists($conn, $column_name)) {
                @mysqli_query($conn, $sql);
            }
        }

        if (!emailVerificationUserIndexExists($conn, 'idx_users_email_verification_token')) {
            @mysqli_query($conn, "ALTER TABLE users ADD INDEX idx_users_email_verification_token (email_verification_token)");
        }
    }
}

if (!function_exists('buildPlatformAbsoluteUrl')) {
    function buildPlatformAbsoluteUrl($path = '')
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $path = ltrim((string)$path, '/');

        $request_path = trim((string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
        $base_path = '';
        if ($request_path !== '') {
            $parsed_path = parse_url($request_path, PHP_URL_PATH) ?: $request_path;
            $parsed_path = trim((string)$parsed_path);
            if ($parsed_path !== '' && $parsed_path !== '/') {
                $base_path = rtrim((string)dirname($parsed_path), '/');
                if ($base_path === '.' || $base_path === '\\') {
                    $base_path = '';
                }
            }
        }

        if ($base_path !== '' && $path !== '') {
            return $scheme . '://' . $host . $base_path . '/' . $path;
        }

        return $scheme . '://' . $host . '/' . $path;
    }
}

if (!function_exists('issueUserEmailVerification')) {
    function issueUserEmailVerification($conn, $user_id, $email, $full_name)
    {
        ensureUserEmailVerificationSchema($conn);

        $user_id = (int)$user_id;
        $email = strtolower(trim((string)$email));
        $full_name = trim((string)$full_name);
        if ($user_id <= 0 || $email === '') {
            return ['success' => false, 'message' => 'Unable to prepare email verification.'];
        }

        $token = bin2hex(random_bytes(32));
        $otp_code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET email_verification_token = ?,
                 email_otp_code = ?,
                 email_verification_expires = ?,
                 email_verification_sent_at = NOW(),
                 email_verified_at = NULL
             WHERE id = ?
             LIMIT 1"
        );

        if (!$update_stmt) {
            return ['success' => false, 'message' => 'Unable to prepare verification OTP token.'];
        }

        mysqli_stmt_bind_param($update_stmt, "sssi", $token, $otp_code, $expires_at, $user_id);
        $saved = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);

        if (!$saved) {
            return ['success' => false, 'message' => 'Unable to save email verification code.'];
        }

        require_once dirname(__DIR__) . '/email_service.php';
        $verification_url = buildPlatformAbsoluteUrl('verify_email.php?email=' . urlencode($email) . '&token=' . urlencode($token));
        $mailer = new EmailService($conn);
        $sent = $mailer->sendRegistrationOtpEmail($email, $full_name, $otp_code);

        return [
            'success' => $sent,
            'message' => $sent ? 'Verification OTP code sent to your email.' : 'Verification email could not be sent.',
            'verification_url' => $verification_url,
            'token' => $token,
            'otp_code' => $otp_code,
            'expires_at' => $expires_at
        ];
    }
}

if (!function_exists('verifyUserEmailOtp')) {
    function verifyUserEmailOtp($conn, $email, $otp_code)
    {
        ensureUserEmailVerificationSchema($conn);

        $email = strtolower(trim((string)$email));
        $otp_code = preg_replace('/[^0-9]/', '', (string)$otp_code);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please provide a valid email address.'];
        }

        if (strlen($otp_code) !== 6) {
            return ['success' => false, 'message' => 'Please enter a valid 6-digit OTP verification code.'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, email, full_name, email_verified_at, email_otp_code, email_verification_expires
             FROM users
             WHERE email = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to check verification details right now.'];
        }

        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$user) {
            return ['success' => false, 'message' => 'No account found matching that email address.'];
        }

        if (!empty($user['email_verified_at'])) {
            return [
                'success' => true,
                'already_verified' => true,
                'email' => (string)$user['email'],
                'message' => 'Your email address is already verified. You can sign in now.'
            ];
        }

        $db_otp = trim((string)($user['email_otp_code'] ?? ''));
        if ($db_otp === '' || $db_otp !== $otp_code) {
            return ['success' => false, 'message' => 'Invalid verification code. Please check your email and try again.'];
        }

        $expires_at = trim((string)($user['email_verification_expires'] ?? ''));
        if ($expires_at !== '' && strtotime($expires_at) !== false && strtotime($expires_at) < time()) {
            return [
                'success' => false,
                'expired' => true,
                'email' => (string)$user['email'],
                'message' => 'This verification code has expired. Please request a new code.'
            ];
        }

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET email_verified_at = NOW(),
                 email_otp_code = NULL,
                 email_verification_token = NULL,
                 email_verification_expires = NULL
             WHERE id = ?
             LIMIT 1"
        );
        if (!$update_stmt) {
            return ['success' => false, 'message' => 'Unable to activate your account right now.'];
        }

        $user_id = (int)$user['id'];
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        $updated = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);

        if (!$updated) {
            return ['success' => false, 'message' => 'Unable to activate your account right now.'];
        }

        return [
            'success' => true,
            'email' => (string)$user['email'],
            'message' => 'Your account has been verified successfully! You can now sign in.'
        ];
    }
}

if (!function_exists('resendUserEmailVerificationForUser')) {
    function resendUserEmailVerificationForUser($conn, $user_id)
    {
        ensureUserEmailVerificationSchema($conn);

        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return ['success' => false, 'message' => 'Invalid user for verification resend.'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, email, full_name, email_verified_at
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to load account verification details.'];
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$user) {
            return ['success' => false, 'message' => 'Account not found for email verification resend.'];
        }

        if (!empty($user['email_verified_at'])) {
            return ['success' => true, 'already_verified' => true, 'message' => 'Email address is already verified.'];
        }

        return issueUserEmailVerification($conn, (int)$user['id'], (string)$user['email'], (string)$user['full_name']);
    }
}

if (!function_exists('resendUserEmailVerificationByEmail')) {
    function resendUserEmailVerificationByEmail($conn, $email)
    {
        ensureUserEmailVerificationSchema($conn);

        $email = strtolower(trim((string)$email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address first.'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, email_verified_at
             FROM users
             WHERE email = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to check that email address right now.'];
        }

        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$user) {
            return ['success' => true, 'message' => 'If an account exists for that email, a verification link has been prepared.'];
        }

        if (!empty($user['email_verified_at'])) {
            return ['success' => true, 'already_verified' => true, 'message' => 'That email address is already verified. You can sign in normally.'];
        }

        return resendUserEmailVerificationForUser($conn, (int)$user['id']);
    }
}

if (!function_exists('verifyUserEmailToken')) {
    function verifyUserEmailToken($conn, $token)
    {
        ensureUserEmailVerificationSchema($conn);

        $token = trim((string)$token);
        if ($token === '') {
            return ['success' => false, 'message' => 'Verification token is missing.'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, email, full_name, email_verified_at, email_verification_expires
             FROM users
             WHERE email_verification_token = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to validate email verification token.'];
        }

        mysqli_stmt_bind_param($stmt, "s", $token);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if (!$user) {
            return ['success' => false, 'message' => 'Verification link is invalid or has already been used.'];
        }

        if (!empty($user['email_verified_at'])) {
            return [
                'success' => true,
                'already_verified' => true,
                'email' => (string)($user['email'] ?? ''),
                'message' => 'Your email address is already verified.'
            ];
        }

        $expires_at = trim((string)($user['email_verification_expires'] ?? ''));
        if ($expires_at !== '' && strtotime($expires_at) !== false && strtotime($expires_at) < time()) {
            return [
                'success' => false,
                'expired' => true,
                'email' => (string)($user['email'] ?? ''),
                'message' => 'This verification link has expired. Please sign in to request a new verification email.'
            ];
        }

        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET email_verified_at = NOW(),
                 email_verification_token = NULL,
                 email_verification_expires = NULL
             WHERE id = ?
             LIMIT 1"
        );
        if (!$update_stmt) {
            return ['success' => false, 'message' => 'Unable to activate your verified email right now.'];
        }

        $user_id = (int)$user['id'];
        mysqli_stmt_bind_param($update_stmt, "i", $user_id);
        $updated = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);

        if (!$updated) {
            return ['success' => false, 'message' => 'Unable to activate your verified email right now.'];
        }

        return [
            'success' => true,
            'email' => (string)($user['email'] ?? ''),
            'message' => 'Your email address has been verified. You can now sign in.'
        ];
    }
}
