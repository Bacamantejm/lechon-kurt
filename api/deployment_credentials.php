<?php
/*
|--------------------------------------------------------------------------
| Deployment Credentials (Active)
|--------------------------------------------------------------------------
| This file is used by includes/config.php before local credentials.
| Keep this private and do not expose it publicly.
*/

if (!defined('APP_ENV')) define('APP_ENV', 'local');

// Keep Google Maps key pre-configured for deployment.
if (!defined('GOOGLE_MAPS_API_KEY')) define('GOOGLE_MAPS_API_KEY', 'AIzaSyBET55RIjwdCs6gpVpwoPhgrLMAlqu5UWU');

// Current key still needs Geocoding API enabled in Google Cloud.
// Keep fallback mode until Geocoding API is active on this key's project.
if (!defined('GOOGLE_GEOCODING_ENABLED')) define('GOOGLE_GEOCODING_ENABLED', false);

// App environment
if (!defined('APP_ENV')) define('APP_ENV', 'local');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Manila');

// Local fallback database settings for XAMPP.
if (!defined('APP_DB_HOST')) define('APP_DB_HOST', 'localhost');
if (!defined('APP_DB_PORT')) define('APP_DB_PORT', '3306');
if (!defined('APP_DB_USER')) define('APP_DB_USER', 'root');
if (!defined('APP_DB_PASSWORD')) define('APP_DB_PASSWORD', '');
if (!defined('APP_DB_NAME')) define('APP_DB_NAME', 'lechon_db');

// SMTP settings for outgoing mail.
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', '587');
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'justinehero03@gmail.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'fwpugavawtxmwsoj');
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', 'justinehero03@gmail.com');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'Lechon Delights');
