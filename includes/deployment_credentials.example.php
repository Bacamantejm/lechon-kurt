<?php
/*
|--------------------------------------------------------------------------
| Deployment Credentials (Template)
|--------------------------------------------------------------------------
| Copy this file to: includes/deployment_credentials.php
| Then replace all placeholder values with your production credentials.
|
| IMPORTANT:
| - Do not commit includes/deployment_credentials.php to version control.
| - Keep this file outside public access whenever possible.
*/

// App environment
if (!defined('APP_ENV')) define('APP_ENV', 'local');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Manila');

// Database (XAMPP local MySQL)
if (!defined('APP_DB_HOST')) define('APP_DB_HOST', 'localhost');
if (!defined('APP_DB_PORT')) define('APP_DB_PORT', '3306');
if (!defined('APP_DB_USER')) define('APP_DB_USER', 'root');
if (!defined('APP_DB_PASSWORD')) define('APP_DB_PASSWORD', '');
if (!defined('APP_DB_NAME')) define('APP_DB_NAME', 'lechon_db');

// Google Maps
if (!defined('GOOGLE_MAPS_API_KEY')) define('GOOGLE_MAPS_API_KEY', 'CHANGE_ME');
if (!defined('GOOGLE_GEOCODING_ENABLED')) define('GOOGLE_GEOCODING_ENABLED', true);

// Twilio (optional)
if (!defined('TWILIO_ACCOUNT_SID')) define('TWILIO_ACCOUNT_SID', '');
if (!defined('TWILIO_AUTH_TOKEN')) define('TWILIO_AUTH_TOKEN', '');
if (!defined('TWILIO_PHONE_NUMBER')) define('TWILIO_PHONE_NUMBER', '');

// OCR / government verification (optional)
if (!defined('OCR_SPACE_API_KEY')) define('OCR_SPACE_API_KEY', '');
if (!defined('GOV_ID_OCR_API_KEY')) define('GOV_ID_OCR_API_KEY', OCR_SPACE_API_KEY);
if (!defined('ID_VERIFICATION_MATCH_MODE')) define('ID_VERIFICATION_MATCH_MODE', 'balanced');
if (!defined('PHILSYS_VERIFY_ENDPOINT')) define('PHILSYS_VERIFY_ENDPOINT', '');
if (!defined('PHILSYS_API_KEY')) define('PHILSYS_API_KEY', '');
if (!defined('PHILSYS_BEARER_TOKEN')) define('PHILSYS_BEARER_TOKEN', '');
