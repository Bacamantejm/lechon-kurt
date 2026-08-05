<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/config.php';

function loginSessionUserExists($conn, $user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return false;
    }

    $query = "SELECT id FROM users WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result ? mysqli_num_rows($result) > 0 : false;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $exists;
}

function sanitizeRelativePhpRedirect($target) {
    $target = trim((string)$target);
    if ($target === '') {
        return null;
    }

    $decoded = urldecode($target);
    if (strpos($decoded, '://') !== false || strpos($decoded, '//') === 0) {
        return null;
    }

    $path = parse_url($decoded, PHP_URL_PATH);
    if (!$path || !preg_match('/^[a-zA-Z0-9_\\-\\/]+\\.php$/', $path)) {
        return null;
    }

    $query = parse_url($decoded, PHP_URL_QUERY);
    if ($query !== null && $query !== '' && !preg_match('/^[a-zA-Z0-9_\\-=&%.]*$/', $query)) {
        return null;
    }

    return $query ? ($path . '?' . $query) : $path;
}

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    $session_user_id = (int)$_SESSION['user_id'];
    if (loginSessionUserExists($conn, $session_user_id)) {
        $redirect = getUserDashboardRoute($conn, $session_user_id, $_SESSION['user_type'] ?? '');
        $custom_redirect = sanitizeRelativePhpRedirect($_GET['redirect'] ?? '');
        if ($custom_redirect !== null) {
            $custom_redirect_path = parse_url($custom_redirect, PHP_URL_PATH) ?: $custom_redirect;
            if (file_exists(__DIR__ . '/' . ltrim($custom_redirect_path, '/'))) {
                $redirect = $custom_redirect;
            }
        }
        header("Location: " . $redirect);
        exit;
    }

    // Stale session points to non-existing user; clear it and allow login form.
    unset(
        $_SESSION['user_id'],
        $_SESSION['email'],
        $_SESSION['full_name'],
        $_SESSION['user_type'],
        $_SESSION['account_type'],
        $_SESSION['role_id'],
        $_SESSION['permissions'],
        $_SESSION['has_backoffice_access'],
        $_SESSION['profile_image'],
        $_SESSION['business_logo']
    );
}

$error = '';
$success = '';
$form_data = [];

// Handle registration success redirect
if (isset($_SESSION['register_success'])) {
    $success = (string)($_SESSION['registration_verification_notice'] ?? 'Registration successful! Please sign in with your new account.');
    if (!empty($_SESSION['registration_verification_link'])) {
        $success .= ' <a href="' . htmlspecialchars((string)$_SESSION['registration_verification_link'], ENT_QUOTES, 'UTF-8') . '" style="font-weight:600;color:#b91c1c;">Verify your email now</a>';
    }
    unset($_SESSION['register_success']);
    unset($_SESSION['registration_verification_notice']);
    unset($_SESSION['registration_verification_link']);
    unset($_SESSION['registration_verification_token']);
}

if (isset($_SESSION['email_verification_success'])) {
    $success = (string)$_SESSION['email_verification_success'];
    unset($_SESSION['email_verification_success']);
}

if (isset($_SESSION['email_verification_error'])) {
    $error = (string)$_SESSION['email_verification_error'];
    unset($_SESSION['email_verification_error']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
        $form_data['email'] = $email;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
        $form_data['email'] = $email;
    } else {
        // Use loginUser function from config.php
        $result = loginUser($conn, $email, $password);
        
        if ($result['success']) {
            // Login successful
            $_SESSION['user_id'] = $result['user_id'];
            $_SESSION['email'] = $result['email'];
            $_SESSION['full_name'] = $result['full_name'];
            $_SESSION['user_type'] = $result['user_type'];
            $_SESSION['account_type'] = $result['account_type'];
            $_SESSION['profile_image'] = $result['profile_image'] ?? null;
            $_SESSION['business_logo'] = $result['business_logo'] ?? null;
            $_SESSION['role_id'] = $result['role_id'] ?? null;  // Store role_id for RBAC
            $_SESSION['has_backoffice_access'] = hasBackofficeAccess($conn, $result['user_id']);
            $_SESSION['permissions'] = getUserPermissions($conn, $result['user_id']);
            
            $user_type = strtolower(trim($result['user_type']));

            // Check if user is an employee (exists in employees table)
            $check_emp = $conn->prepare("SELECT id FROM employees WHERE user_id = ?");
            $check_emp->bind_param("i", $result['user_id']);
            $check_emp->execute();
            $emp_result = $check_emp->get_result();
            $is_employee_record = false;
            
            if ($emp_result->num_rows > 0) {
                $emp_row = $emp_result->fetch_assoc();
                $_SESSION['employee_id'] = $emp_row['id']; // Correct ID from employees table
                $is_employee_record = true;
            }
            $check_emp->close();
            
            // Determine default redirect from RBAC role permissions first, then legacy fallback
            $redirect = getUserDashboardRoute($conn, $result['user_id'], $result['user_type']);
            if ($redirect === 'index.php' && ($user_type === 'employee' || $is_employee_record)) {
                $redirect = 'employee/dashboard.php';
            }
            
            
            // Handle remember me
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $expires = time() + (30 * 24 * 60 * 60);
                
                setcookie('remember_token', $token, [
                    'expires' => $expires,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
                
                // Store in database
                $token_query = "UPDATE users 
                            SET remember_token = ?, 
                                remember_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) 
                            WHERE id = ?";
                if ($token_stmt = mysqli_prepare($conn, $token_query)) {
                    mysqli_stmt_bind_param($token_stmt, "si", $token, $result['user_id']);
                    mysqli_stmt_execute($token_stmt);
                    mysqli_stmt_close($token_stmt);
                }
            }
            
            // Check for redirect URL (override role-based default if specific page requested)
            if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
                $custom_redirect = sanitizeRelativePhpRedirect($_GET['redirect']);
                if ($custom_redirect !== null) {
                    $custom_redirect_path = parse_url($custom_redirect, PHP_URL_PATH) ?: $custom_redirect;
                    $is_admin_redirect = strpos($custom_redirect_path, 'admin/') === 0;
                    $is_employee_redirect = strpos($custom_redirect_path, 'employee/') === 0;
                    $can_access_admin = hasBackofficeAccess($conn, $result['user_id']);
                    $can_access_employee = ($user_type === 'employee' || $is_employee_record);
                    $target_exists = file_exists(__DIR__ . '/' . ltrim($custom_redirect_path, '/'));

                    if (($is_admin_redirect && $can_access_admin) ||
                        ($is_employee_redirect && $can_access_employee) ||
                        (!$is_admin_redirect && !$is_employee_redirect)) {
                        if ($target_exists) {
                            $redirect = $custom_redirect;
                        }
                    }
                }
            }
            
            // Check if this is an AJAX request
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            $is_ajax = $is_ajax || (isset($_POST['ajax']) && $_POST['ajax'] == 'true');
            
            if ($is_ajax) {
                // Return JSON for AJAX requests
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => $redirect,
                    'user_type' => $user_type
                ]);
                exit();
            } else {
                // Regular redirect for non-AJAX requests
                header("Location: " . $redirect);
                exit();
            }
        } else {
            $error = $result['message'];
            $form_data['email'] = $email;
        }
    }
}

$page_title = "Login to Your Account | Lechon Delights";
include 'includes/header.php';
?>

<!-- CSS Styles -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
/* Main Layout Styles */
.login-page-container {
    background: #ffffff !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    min-height: calc(100vh - 64px) !important;
    padding: 0 !important;
}

.login-wrapper {
    max-width: 100% !important;
    width: 100%;
    min-height: calc(100vh - 64px) !important;
    background-color: white;
    border-radius: 0 !important;
    border: none;
    box-shadow: none !important;
    display: flex;
    flex-direction: row; /* Image left, form right */
    margin: 0 !important;
    animation: fadeIn 0.6s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Left Side - Brand/Info */
.login-left {
    width: 50%;
    background: linear-gradient(135deg, #fff2eb 0%, #ffd9ce 100%) !important;
    display: flex !important;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px !important;
    text-align: center;
    position: relative;
    overflow: hidden;
    min-height: calc(100vh - 64px) !important;
}

.login-left::before {
    display: none !important;
}

.brand-title {
    font-family: 'Outfit', sans-serif;
    font-size: 3.8rem;
    font-weight: 900;
    letter-spacing: -1.5px;
    margin: 0;
    color: #b3261e !important;
    text-shadow: 0 4px 12px rgba(179,38,30,0.15);
}

.brand-subtitle {
    font-size: 1.25rem;
    color: #7b6d64 !important;
    margin-top: 15px;
    max-width: 340px;
    font-weight: 600;
    line-height: 1.6;
}

.floating-pigs-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}

.floating-pig {
    position: absolute;
    font-size: 3.5rem;
    opacity: 0.16;
    animation: floatPig 8s ease-in-out infinite alternate;
}

.pig-1 { top: 10%; left: 15%; animation-duration: 9s; font-size: 4rem; }
.pig-2 { top: 25%; right: 15%; animation-duration: 11s; animation-delay: 1s; font-size: 3.5rem; }
.pig-3 { bottom: 20%; left: 20%; animation-duration: 10s; animation-delay: 2s; font-size: 4.5rem; }
.pig-4 { bottom: 15%; right: 25%; animation-duration: 8s; animation-delay: 0.5s; font-size: 3rem; }
.pig-5 { top: 50%; left: 40%; animation-duration: 12s; animation-delay: 1.5s; font-size: 3.8rem; }

@keyframes floatPig {
    0% {
        transform: translateY(0) rotate(0deg) scale(1);
    }
    50% {
        transform: translateY(-20px) rotate(8deg) scale(1.05);
    }
    100% {
        transform: translateY(10px) rotate(-8deg) scale(0.95);
    }
}

.dummy-placeholder {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="white" opacity="0.05"/></svg>');
    background-size: cover;
}

.brand-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 15px;
    margin-bottom: 30px;
}

.brand-logo i {
    font-size: 2.5rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 15px;
    border-radius: 12px;
}

.brand-logo h1 {
    font-size: 1.8rem;
    margin: 0;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.brand-logo span {
    font-weight: 300;
    opacity: 0.9;
}

.login-left-content {
    position: relative;
    z-index: 1;
}

.login-left h2 {
    font-size: 2rem;
    margin-bottom: 20px;
    font-weight: 700;
    line-height: 1.3;
}

.login-left p {
    font-size: 1.05rem;
    line-height: 1.6;
    opacity: 0.9;
    margin-bottom: 30px;
}

.features-list {
    list-style: none;
    padding: 0;
    margin: 30px 0;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.features-list li {
    display: flex;
    align-items: center;
    gap: 15px;
    font-size: 0.95rem;
    justify-content: center;
}

.features-list i {
    width: 25px;
    height: 25px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
}

.security-notice {
    margin-top: 30px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    backdrop-filter: blur(10px);
}

.security-notice p {
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-size: 0.9rem;
}

/* Right Side - Forms */
.login-right {
    width: 50%;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    min-height: calc(100vh - 64px) !important;
}

.login-header {
    margin-bottom: 30px;
    text-align: center;
}

.login-header h2 {
    color: #333;
    font-size: 1.8rem;
    margin-bottom: 10px;
    font-weight: 700;
}

.login-header p {
    color: #666;
    font-size: 1rem;
}

/* Alert Messages */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    animation: slideDown 0.3s ease;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

@keyframes slideDown {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.alert-error {
    background-color: #FFEBEE;
    border-left: 4px solid #F44336;
    color: #b3261e;
}

.alert-success {
    background-color: #E8F5E9;
    border-left: 4px solid #4CAF50;
    color: #2E7D32;
}

.alert i {
    font-size: 1.2rem;
    margin-top: 2px;
}

/* Form Styles */
.login-form {
    animation: slideUp 0.5s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-weight: 600;
    font-size: 0.95rem;
}

.form-control {
    width: 100%;
    padding: 15px 18px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
    background-color: #fafafa;
}

.form-control:focus {
    outline: none;
    border-color: #b3261e;
    background-color: white;
    box-shadow: 0 0 0 4px rgba(198, 40, 40, 0.1);
}

.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 1.1rem;
}

.input-with-icon .form-control {
    padding-left: 50px;
}

.password-wrapper {
    position: relative;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    padding: 8px;
    font-size: 1.1rem;
    transition: color 0.3s;
}

.toggle-password:hover {
    color: #b3261e;
}

.remember-forgot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.remember-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.remember-checkbox input {
    width: 16px;
    height: 16px;
    accent-color: #b3261e;
    cursor: pointer;
}

.remember-checkbox span {
    color: #666;
    font-size: 0.95rem;
    font-weight: 500;
}

.forgot-link {
    color: #b3261e;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    transition: all 0.3s;
    padding: 6px 10px;
    border-radius: 6px;
}

.forgot-link:hover {
    color: #8f261a;
    background-color: rgba(198, 40, 40, 0.05);
    text-decoration: none;
}

/* Button Styles */
.btn-primary {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.5px;
    margin-top: 10px;
}

.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(198, 40, 40, 0.3);
}

.btn-primary:active {
    transform: translateY(-1px);
}

.btn-primary:disabled {
    background: #cccccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-primary.loading {
    color: transparent;
}

.btn-primary.loading::after {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.btn-secondary {
    width: 100%;
    padding: 16px;
    background: white;
    color: #b3261e;
    border: 2px solid #b3261e;
    border-radius: 10px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    letter-spacing: 0.5px;
    margin-top: 10px;
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(198, 40, 40, 0.3);
}

/* Auth Link */
.auth-link {
    text-align: center;
    margin-top: 25px;
    color: #666;
    font-size: 0.95rem;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.auth-link a {
    color: #b3261e;
    text-decoration: none;
    font-weight: 600;
    margin-left: 5px;
    transition: all 0.3s;
}

.auth-link a:hover {
    color: #8f261a;
    text-decoration: underline;
}

/* Social Login Divider */
.social-divider {
    display: flex;
    align-items: center;
    margin: 25px 0 20px;
    gap: 15px;
    color: #999;
    font-size: 0.9rem;
}

.social-divider::before,
.social-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: #e0e0e0;
}

/* Social Login Buttons */
.social-login-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.social-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    background-color: #fafafa;
    color: #333;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
    min-height: 50px;
}

.social-btn:hover {
    border-color: #333;
    background-color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.social-btn:active {
    transform: translateY(0);
}

.social-btn i {
    font-size: 1.2rem;
}

.social-btn span {
    display: none;
}

/* Social button specific colors */
.google-btn {
    border-color: #EA4335;
    color: #EA4335;
}

.google-btn:hover {
    background-color: #EA4335;
    color: white;
}

.facebook-btn {
    border-color: #1877F2;
    color: #1877F2;
}

.facebook-btn:hover {
    background-color: #1877F2;
    color: white;
}

.twitter-btn {
    border-color: #000;
    color: #000;
}

.twitter-btn:hover {
    background-color: #000;
    color: white;
}

.instagram-btn {
    border-color: #E4405F;
    color: #E4405F;
}

.instagram-btn:hover {
    background-color: #E4405F;
    color: white;
}

@media (min-width: 576px) {
    .social-btn span {
        display: inline;
    }
    
    .social-login-buttons {
        grid-template-columns: 1fr 1fr;
    }
}

/* Responsive Design */
@media (max-width: 850px) {
    .login-wrapper {
        flex-direction: column;
    }
    .login-right {
        width: 100%;
        height: auto;
        min-height: calc(100vh - 64px);
        padding: 40px 20px;
    }
    .login-left {
        display: none !important;
    }
    /* Prevent automatic zoom-in on focus on mobile devices */
    input, select, textarea, .form-control {
        font-size: 16px !important;
    }
}

/* Modern Food Auth Refresh */
:root {
    --auth-red: #b3261e;
    --auth-orange: #ef6b2e;
    --auth-cream: #fff8ef;
    --auth-ink: #2a211d;
    --auth-muted: #7b6d64;
    --auth-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--auth-cream);
}

.login-page-container {
    background: transparent;
}

.login-wrapper {
    border: 1px solid var(--auth-border);
    border-radius: 22px;
    box-shadow: 0 20px 40px rgba(74, 32, 20, 0.14);
}

.login-left {
    background:
        linear-gradient(130deg, rgba(22, 14, 10, 0.9), rgba(65, 30, 20, 0.78)),
        url('images/about-us-bg.jpg') center/cover no-repeat;
}

.login-header h2,
.login-header p,
.form-group label {
    color: var(--auth-ink);
}

.form-group label {
    font-weight: 700;
}

.form-control {
    border: 1px solid #e8d4c3;
    background: #fffdfb;
}

.form-control:focus {
    border-color: #d06d44;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, var(--auth-red), var(--auth-orange));
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover:not(:disabled) {
    box-shadow: 0 15px 34px rgba(179, 38, 30, 0.34);
}

.remember-checkbox span,
.forgot-link,
.auth-link,
.auth-link a {
    color: var(--auth-muted);
}

.forgot-link:hover,
.auth-link a:hover {
    color: #8f2f1f;
}

.social-divider span {
    color: #8a7a70;
}

.social-btn {
    border: 1px solid #ecd8c7;
    background: #fffaf5;
}

.social-btn:hover {
    border-color: #d7a37f;
    background: #fff2e6;
}
.footer {
    margin-top: 0 !important;
}
.auth-link a {
    color: #b3261e !important;
    font-weight: 700 !important;
    text-decoration: none !important;
}
.auth-link a:hover {
    text-decoration: underline !important;
}
</style>

<div class="login-page-container">
    <div class="login-wrapper">
        <!-- Left Side: Branding Panel with Floating Mascot Pigs -->
        <div class="login-left">
            <div class="floating-pigs-container">
                <div class="floating-pig pig-1">🐷</div>
                <div class="floating-pig pig-2">🐷</div>
                <div class="floating-pig pig-3">🐷</div>
                <div class="floating-pig pig-4">🐷</div>
                <div class="floating-pig pig-5">🐷</div>
            </div>
            <div class="brand-content" style="position: relative; z-index: 10;">
                <h1 class="brand-title">Lechon Delights</h1>
                <p class="brand-subtitle">Cavite's Finest Lechon at Your Doorsteps</p>
            </div>
        </div>

        <!-- Login Form Section -->
        <div class="login-right">
            <div style="max-width: 460px; width: 100%; margin: 0 auto; display: flex; flex-direction: column;">
                <!-- Login View -->
                <div id="loginViewContainer" class="auth-panel-view">
                    <div class="login-header" style="text-align: center; margin-bottom: 24px;">
                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 12px;">
                            <img src="assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 48px; height: 48px; object-fit: cover; border-radius: 12px; display: block; border: 1px solid #efddcd; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                            <span style="font-size: 1.6rem; font-weight: 800; color: #171922; font-family: 'Outfit', sans-serif;">Lechon Delights</span>
                        </div>
                        <h2>Welcome Back!</h2>
                        <p>Sign in to continue your delicious journey with Lechon Delights</p>
                    </div>
                
                <?php if ($error): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success; ?></div>
                </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="" class="login-form" id="loginForm">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" class="form-control" required 
                                placeholder="Enter your email address" 
                                value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                                autocomplete="email">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" class="form-control" required 
                                placeholder="Enter your password"
                                autocomplete="current-password">
                            <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="remember-forgot">
                        <label class="remember-checkbox">
                            <input type="checkbox" name="remember" id="rememberMe" value="1">
                            <span>Remember me</span>
                        </label>
                        <a href="reset_password_request.php" class="forgot-link" id="switchToForgotView">Forgot password?</a>
                    </div>
                    
                    <button type="submit" name="login" class="btn-primary" id="loginBtn">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Sign In</span>
                    </button>
                    

                    
                    <div class="auth-link">
                        Don't have an account? 
                        <a href="register.php" id="switchToRegister">Create an account</a>
                    </div>
                </form>
            </div>

            <!-- In-Place Forgot Password Panel View -->
            <div id="forgotPasswordViewContainer" class="auth-panel-view" style="display: none;">
                <div class="login-header">
                    <h2>Reset Password</h2>
                    <p>Enter your registered account email and we'll send you instructions to reset your password.</p>
                </div>

                <div id="forgotViewAlert" class="alert" style="display: none;"></div>

                <form id="forgotViewForm" method="POST" action="reset_password_request.php" class="login-form">
                    <input type="hidden" name="ajax" value="true">
                    <div class="form-group">
                        <label for="forgotViewEmail">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="forgotViewEmail" name="email" class="form-control" required placeholder="Enter your email address" autocomplete="email">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" id="forgotViewSubmitBtn" style="margin-bottom: 25px;">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send Reset Link</span>
                    </button>

                    <div class="auth-link" style="text-align: center;">
                        <button type="button" id="switchToLoginView" style="background: none; border: none; color: #b3261e; font-weight: 700; cursor: pointer; font-size: 0.95rem; font-family: inherit; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fas fa-arrow-left"></i> Back to Sign In
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize SweetAlert2
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    // Toggle password visibility
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.closest('.password-wrapper').querySelector('input');
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
                this.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
                this.setAttribute('aria-label', 'Show password');
            }
        });
    });

    // Login form validation and AJAX submission
    const loginForm = document.getElementById('loginForm');

    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const remember = document.getElementById('rememberMe').checked;
            const btn = document.getElementById('loginBtn');
            
            if (!email || !password) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter both email and password.',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
            
            if (!isValidEmail(email)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address.',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
            
            // Show loading state
            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
                const span = btn.querySelector('span');
                if (span) {
                    span.textContent = 'Signing in...';
                }
            }
            
            // Create form data
            const formData = new FormData();
            formData.append('login', 'true');
            formData.append('ajax', 'true');  // Indicate this is an AJAX request
            formData.append('email', email);
            formData.append('password', password);
            if (remember) {
                formData.append('remember', '1');
            }
            
            // Get redirect parameter if exists
            const urlParams = new URLSearchParams(window.location.search);
            const redirect = urlParams.get('redirect');
            if (redirect) {
                formData.append('redirect', redirect);
            }
            
            // Submit via AJAX
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Try to parse as JSON first
                try {
                    const jsonData = JSON.parse(data);
                    if (jsonData.success) {
                        // Login successful, use server-provided redirect URL
                        window.location.href = jsonData.redirect;
                        return;
                    }
                } catch (e) {
                    // Not JSON, check for HTML error messages
                }
                
                // Check if response contains error
                if (data.includes('alert-error') || data.includes('Invalid email or password')) {
                    // Extract error message
                    const errorMatch = data.match(/alert-error.*?<div>(.*?)<\/div>/s);
                    const errorMessage = errorMatch ? errorMatch[1].trim() : 'Invalid email or password';
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Login Failed',
                        text: errorMessage,
                        confirmButtonColor: '#b3261e'
                    });
                    
                    // Reset button
                    if (btn) {
                        btn.classList.remove('loading');
                        btn.disabled = false;
                        const span = btn.querySelector('span');
                        if (span) {
                            span.textContent = 'Sign In';
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Login Error',
                    text: 'An error occurred while trying to log in. Please try again.',
                    confirmButtonColor: '#b3261e'
                });
                
                // Reset button
                if (btn) {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                    const span = btn.querySelector('span');
                    if (span) {
                        span.textContent = 'Sign In';
                    }
                }
            });
        });
    }

    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // Social Login Handlers
    document.getElementById('googleLoginBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/google_auth.php';
    });

    document.getElementById('facebookLoginBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/facebook_auth.php';
    });

    document.getElementById('twitterLoginBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/twitter_auth.php';
    });

    document.getElementById('instagramLoginBtn').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'controllers/instagram_auth.php';
    });

    // Auto focus on first input
    setTimeout(() => {
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.focus();
        }
    }, 300);
    
    // In-Place View Switcher between Login and Forgot Password
    const loginView = document.getElementById('loginViewContainer');
    const forgotView = document.getElementById('forgotPasswordViewContainer');
    const switchToForgotBtn = document.getElementById('switchToForgotView');
    const switchToLoginBtn = document.getElementById('switchToLoginView');
    const forgotViewForm = document.getElementById('forgotViewForm');
    const forgotViewEmail = document.getElementById('forgotViewEmail');
    const forgotViewAlert = document.getElementById('forgotViewAlert');
    const forgotViewSubmitBtn = document.getElementById('forgotViewSubmitBtn');

    if (switchToForgotBtn) {
        switchToForgotBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const mainEmail = document.getElementById('email');
            if (mainEmail && mainEmail.value) {
                forgotViewEmail.value = mainEmail.value.trim();
            }
            if (loginView && forgotView) {
                loginView.style.display = 'none';
                forgotView.style.display = 'block';
                setTimeout(() => { if (forgotViewEmail) forgotViewEmail.focus(); }, 100);
            }
        });
    }

    if (switchToLoginBtn) {
        switchToLoginBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (loginView && forgotView) {
                forgotView.style.display = 'none';
                loginView.style.display = 'block';
            }
        });
    }

    if (forgotViewForm) {
        forgotViewForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const email = forgotViewEmail ? forgotViewEmail.value.trim() : '';
            if (!email) return;

            if (forgotViewSubmitBtn) {
                forgotViewSubmitBtn.disabled = true;
                forgotViewSubmitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Sending Link...</span>';
            }
            if (forgotViewAlert) forgotViewAlert.style.display = 'none';

            try {
                const formData = new FormData(forgotViewForm);
                const res = await fetch('reset_password_request.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (forgotViewAlert) {
                        forgotViewAlert.className = 'alert alert-success';
                        forgotViewAlert.innerHTML = '<i class="fas fa-check-circle"></i> <div>' + (data.message || 'Password reset link sent! Check your inbox.') + '</div>';
                        forgotViewAlert.style.display = 'flex';
                    }
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Reset Link Sent',
                            text: data.message || 'Check your email inbox for password reset instructions.',
                            confirmButtonColor: '#b3261e'
                        });
                    }
                } else {
                    if (forgotViewAlert) {
                        forgotViewAlert.className = 'alert alert-error';
                        forgotViewAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> <div>' + (data.message || 'Error processing request.') + '</div>';
                        forgotViewAlert.style.display = 'flex';
                    }
                }
            } catch (err) {
                if (forgotViewAlert) {
                    forgotViewAlert.className = 'alert alert-error';
                    forgotViewAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> <div>An unexpected error occurred. Please try again.</div>';
                    forgotViewAlert.style.display = 'flex';
                }
            } finally {
                if (forgotViewSubmitBtn) {
                    forgotViewSubmitBtn.disabled = false;
                    forgotViewSubmitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>Send Reset Link</span>';
                }
            }
        });
    }

    <?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Login Error',
        text: '<?php echo addslashes($error); ?>',
        confirmButtonColor: '#b3261e'
    });
    <?php endif; ?>
    
    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        html: '<?php echo addslashes($success); ?>',
        confirmButtonColor: '#b3261e'
    });
    <?php endif; ?>
});
</script>

<?php 
// Close database connection
if (isset($conn)) {
    mysqli_close($conn);
}

include 'includes/footer.php'; 
?>
