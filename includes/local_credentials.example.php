<?php
/**
 * Local Credentials Configuration Template
 * Copy this file to includes/local_credentials.php to configure local secrets.
 * Do not commit actual passwords or API keys to version control.
 */

// Database credentials
if (!defined('DB_SERVER')) {
    define('DB_SERVER', 'localhost');
}

if (!defined('DB_USERNAME')) {
    define('DB_USERNAME', 'root');
}

if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', '');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'lechon');
}

// PayMongo keys
if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', '');
}

if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', '');
}

// PhilSys credentials
if (!defined('PHILSYS_API_URL')) {
    define('PHILSYS_API_URL', 'https://api.philsys.gov.ph/v1');
}

if (!defined('PHILSYS_CLIENT_ID')) {
    define('PHILSYS_CLIENT_ID', '');
}

if (!defined('PHILSYS_CLIENT_SECRET')) {
    define('PHILSYS_CLIENT_SECRET', '');
}

if (!defined('PHILSYS_BEARER_TOKEN')) {
    define('PHILSYS_BEARER_TOKEN', '');
}

// SMTP settings for local development.
// For localhost testing in Laragon, use Mailpit on port 1025.
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'localhost');
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', '1025');
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
