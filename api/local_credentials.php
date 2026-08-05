<?php

/*
|--------------------------------------------------------------------------
| Local Credentials
|--------------------------------------------------------------------------
| Use this file for local XAMPP development when environment variables are
| inconvenient to manage. Do not commit real keys to public repositories.
|
| To enable OCR government ID verification, paste your OCR.Space API key below.
| You can get one from: https://ocr.space/ocrapi
*/

if (!defined('APP_ENV')) {
    define('APP_ENV', 'local');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}

if (!defined('APP_DB_HOST')) {
    define('APP_DB_HOST', 'localhost');
}

if (!defined('APP_DB_PORT')) {
    define('APP_DB_PORT', '3306');
}

if (!defined('APP_DB_USER')) {
    define('APP_DB_USER', 'root');
}

if (!defined('APP_DB_PASSWORD')) {
    define('APP_DB_PASSWORD', '');
}

if (!defined('APP_DB_NAME')) {
    define('APP_DB_NAME', 'lechon_db');
}

if (!defined('OCR_SPACE_API_KEY')) {
    define('OCR_SPACE_API_KEY', 'K85790143788957');
}

if (!defined('GOV_ID_OCR_API_KEY')) {
    define('GOV_ID_OCR_API_KEY', OCR_SPACE_API_KEY);
}

/*
| Optional Google Maps JavaScript/Geocoding API key for local development.
| Prefer environment variable GOOGLE_MAPS_API_KEY in production.
*/
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', 'AIzaSyBET55RIjwdCs6gpVpwoPhgrLMAlqu5UWU');
}

/*
| Temporary switch:
| Set to true after your Google key is confirmed valid for Geocoding API.
*/
if (!defined('GOOGLE_GEOCODING_ENABLED')) {
    define('GOOGLE_GEOCODING_ENABLED', false);
}

/*
| Optional registration ID match strictness.
| Available values: 'loose', 'balanced', 'strict'
| - loose: easier address token matching
| - balanced: recommended default
| - strict: stronger name/address match requirement
*/
if (!defined('ID_VERIFICATION_MATCH_MODE')) {
    define('ID_VERIFICATION_MATCH_MODE', 'balanced');
}

/*
| Optional PhilSys / custom verification provider settings.
| Leave blank unless you are integrating a real provider endpoint.
*/
if (!defined('PHILSYS_VERIFY_ENDPOINT')) {
    define('PHILSYS_VERIFY_ENDPOINT', '');
}

if (!defined('PHILSYS_API_KEY')) {
    define('PHILSYS_API_KEY', '');
}

if (!defined('PHILSYS_BEARER_TOKEN')) {
    define('PHILSYS_BEARER_TOKEN', '');
}

// SMTP settings for local development.
// For localhost testing, use a local SMTP relay or mail catcher rather than
// external Gmail credentials.
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'localhost');
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', '25');
}

if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', '');
}

if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', '');
}

if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', '');
}

if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'no-reply@localhost.localdomain');
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Lechon Delights');
}

if (!defined('FORCE_LOCAL_MAILER')) {
    define('FORCE_LOCAL_MAILER', true);
}
