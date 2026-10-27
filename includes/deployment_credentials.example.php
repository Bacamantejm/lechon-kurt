<?php
/**
 * Hostinger Production Credentials Template
 * ─────────────────────────────────────────
 * 1. Copy this file to includes/deployment_credentials.php on the server.
 * 2. Fill in every value below.
 * 3. NEVER commit deployment_credentials.php — it is listed in .gitignore.
 *
 * config.php loads this file first (before local_credentials.php), so any
 * constant defined here takes precedence over the local fallback.
 */

// ─── Environment ─────────────────────────────────────────────────────────────

if (!defined('APP_ENV')) {
    define('APP_ENV', 'production');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

// Suppress errors on production — log them instead.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
// Optional: set a writable log path on Hostinger
// ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

// Harden session on production (shared hosting has shared /tmp).
// Point sessions to a private folder inside your project.
$_session_path = realpath(__DIR__ . '/../tmp') ?: sys_get_temp_dir();
if (!is_dir($_session_path)) {
    @mkdir($_session_path, 0750, true);
}
ini_set('session.save_path', $_session_path);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');   // requires HTTPS — Hostinger provides free SSL
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
unset($_session_path);

// ─── Database ─────────────────────────────────────────────────────────────────
// Get these values from Hostinger hPanel → Databases → MySQL Databases.
// Host is almost always 'localhost' on Hostinger shared hosting.

if (!defined('APP_DB_HOST')) {
    define('APP_DB_HOST', 'localhost');
}

if (!defined('APP_DB_PORT')) {
    define('APP_DB_PORT', '3306');
}

if (!defined('APP_DB_USER')) {
    // Format: u<account_id>_<suffix>  e.g. u123456789_lechon
    define('APP_DB_USER', 'u000000000_lechon');
}

if (!defined('APP_DB_PASSWORD')) {
    define('APP_DB_PASSWORD', 'CHANGE_ME_strong_password');
}

if (!defined('APP_DB_NAME')) {
    // Format: u<account_id>_<suffix>  e.g. u123456789_lechon_db
    define('APP_DB_NAME', 'u000000000_lechon_db');
}

// ─── PayMongo ─────────────────────────────────────────────────────────────────
// Switch from test keys (sk_test_...) to LIVE keys when ready to accept payments.
// Dashboard: https://dashboard.paymongo.com → Developers → API Keys

if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', 'sk_live_CHANGE_ME');
}

if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', 'pk_live_CHANGE_ME');
}

// ─── Google Maps ─────────────────────────────────────────────────────────────
// Restrict this key in Google Cloud Console to your Hostinger domain only
// to prevent quota theft.
// APIs needed: Maps JavaScript API, Geocoding API, Directions API

if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', 'CHANGE_ME_google_maps_key');
}

if (!defined('GOOGLE_GEOCODING_ENABLED')) {
    define('GOOGLE_GEOCODING_ENABLED', true);
}

// ─── SMTP / Email ─────────────────────────────────────────────────────────────
// Option A: Keep Gmail (recommended — already tested locally)
//   - Use an App Password, not your real Gmail password.
//   - Generate at: https://myaccount.google.com/apppasswords
//
// Option B: Use Hostinger's own email server (hPanel → Email Accounts)
//   SMTP_HOST = mail.yourdomain.com
//   SMTP_PORT = 587
//   SMTP_USERNAME = yourname@yourdomain.com
//   SMTP_SECURE  = tls

if (!defined('SMTP_DEBUG')) {
    define('SMTP_DEBUG', false);
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', '587');
}

if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', 'youremail@gmail.com');
}

if (!defined('SMTP_PASSWORD')) {
    // Gmail App Password (16 chars, no spaces), NOT your login password.
    define('SMTP_PASSWORD', 'CHANGE_ME_app_password');
}

if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'tls');
}

if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'youremail@gmail.com');
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Lechon Delights');
}

if (!defined('FORCE_LOCAL_MAILER')) {
    define('FORCE_LOCAL_MAILER', false);
}

// ─── OCR Space API ────────────────────────────────────────────────────────────
// Used for ID document scanning during registration.
// Get a free/paid key at: https://ocr.space/ocrapi

if (!defined('OCR_SPACE_API_KEY')) {
    define('OCR_SPACE_API_KEY', 'CHANGE_ME_ocr_key');
}

if (!defined('GOV_ID_OCR_API_KEY')) {
    define('GOV_ID_OCR_API_KEY', OCR_SPACE_API_KEY);
}

// ─── ID Verification ─────────────────────────────────────────────────────────

if (!defined('ID_VERIFICATION_MATCH_MODE')) {
    // Options: 'loose' | 'balanced' | 'strict'
    define('ID_VERIFICATION_MATCH_MODE', 'balanced');
}

// ─── PhilSys (optional) ───────────────────────────────────────────────────────
// Leave blank if you are not using a real PhilSys integration endpoint.

if (!defined('PHILSYS_VERIFY_ENDPOINT')) {
    define('PHILSYS_VERIFY_ENDPOINT', '');
}

if (!defined('PHILSYS_API_KEY')) {
    define('PHILSYS_API_KEY', '');
}

if (!defined('PHILSYS_BEARER_TOKEN')) {
    define('PHILSYS_BEARER_TOKEN', '');
}

// ─── Twilio SMS (optional) ────────────────────────────────────────────────────
// Leave placeholder values if SMS is not active on production yet.

if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', '');
}

if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', '');
}

if (!defined('TWILIO_PHONE_NUMBER')) {
    define('TWILIO_PHONE_NUMBER', '');
}
