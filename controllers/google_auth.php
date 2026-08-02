<?php
/**
 * Google OAuth Authentication Handler
 * Handles login and registration via Google OAuth 2.0
 */

session_start();
require_once '../includes/config.php';

// Google OAuth Configuration
// TODO: Add your Google API credentials
define('GOOGLE_CLIENT_ID', '514921515237-1q1lkgcvcd7q4e436sj1n1e9r8eegd9c.apps.googleusercontent.com'); // Get from Google Cloud Console
define('GOOGLE_CLIENT_SECRET', 'GOCSPX-1234567890abcdef'); // Get from Google Cloud Console
define('GOOGLE_REDIRECT_URI', 'http://localhost/lechonsystem/controllers/google_auth.php');

// Check if client ID and secret are configured
if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    die('Error: Google OAuth credentials not configured. Please add CLIENT_ID and CLIENT_SECRET in ' . __FILE__);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'login';
$code = isset($_GET['code']) ? $_GET['code'] : null;
$state = isset($_GET['state']) ? $_GET['state'] : null;

// Step 1: Redirect to Google login page
if (empty($code)) {
    // Generate state token for security (CSRF protection)
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_action'] = $action;
    
    // Build Google OAuth URL
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online'
    ]);
    
    header('Location: ' . $auth_url);
    exit;
}

// Step 2: Exchange code for access token
if (!empty($code) && !empty($state)) {
    // Verify state token
    if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $state) {
        die('Error: Invalid state parameter. Possible CSRF attack.');
    }
    
    // Exchange authorization code for access token
    $token_url = 'https://oauth2.googleapis.com/token';
    
    $post_data = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => GOOGLE_REDIRECT_URI
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        die('Error: Failed to obtain access token from Google. HTTP Code: ' . $http_code);
    }
    
    $token_data = json_decode($response, true);
    
    if (!isset($token_data['access_token'])) {
        die('Error: No access token received from Google.');
    }
    
    // Step 3: Fetch user info using access token
    $user_info_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_info_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token_data['access_token']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $user_response = curl_exec($ch);
    curl_close($ch);
    
    $user_data = json_decode($user_response, true);
    
    if (!isset($user_data['email'])) {
        die('Error: Could not retrieve email from Google account.');
    }
    
    // Step 4: Create or login user
    $email = $user_data['email'];
    $full_name = $user_data['name'] ?? 'Google User';
    $google_id = $user_data['id'];
    $picture = $user_data['picture'] ?? null;
    
    // Check if user exists
    $check_query = "SELECT id, user_type FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing_user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($existing_user) {
        // User exists - log them in
        $_SESSION['user_id'] = $existing_user['id'];
        $_SESSION['email'] = $email;
        $_SESSION['full_name'] = $full_name;
        $existing_type = strtolower(trim((string)($existing_user['user_type'] ?? '')));
        $_SESSION['user_type'] = in_array($existing_type, ['admin', 'employee'], true) ? $existing_type : 'customer';
        $_SESSION['account_type'] = 'individual'; // Default for OAuth users
        $_SESSION['oauth_provider'] = 'google';
        
        // Clear OAuth session variables
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_action']);
        
        // Redirect to dashboard
        header('Location: ../index.php');
        exit;
    } else {
        // New user - create account
        $action = isset($_SESSION['oauth_action']) ? $_SESSION['oauth_action'] : 'login';
        
        if ($action === 'register') {
            // Auto-register user
            $account_type = 'individual';
            $phone = ''; // Not provided by Google OAuth
            $address = '';
            
            // Hash a random password for OAuth users
            $oauth_password = bin2hex(random_bytes(32));
            $hashed_password = password_hash($oauth_password, PASSWORD_BCRYPT);
            
            $insert_query = "INSERT INTO users (email, password, full_name, phone, address, account_type, user_type, oauth_provider, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            if ($insert_stmt = mysqli_prepare($conn, $insert_query)) {
                $user_type = 'customer';
                $oauth_provider = 'google';
                
                mysqli_stmt_bind_param($insert_stmt, "ssssssss", 
                    $email, 
                    $hashed_password, 
                    $full_name, 
                    $phone, 
                    $address, 
                    $account_type, 
                    $user_type,
                    $oauth_provider
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $user_id = mysqli_insert_id($conn);
                    
                    // Set session
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['user_type'] = 'customer';
                    $_SESSION['account_type'] = 'individual';
                    $_SESSION['oauth_provider'] = 'google';
                    $_SESSION['register_success'] = true;
                    
                    // Clear OAuth session variables
                    unset($_SESSION['oauth_state']);
                    unset($_SESSION['oauth_action']);
                    
                    // Redirect to dashboard
                    header('Location: ../index.php');
                    exit;
                } else {
                    die('Error: Failed to create user account. ' . mysqli_error($conn));
                }
                mysqli_stmt_close($insert_stmt);
            }
        } else {
            // Login action but user doesn't exist - redirect to register with pre-filled data
            $_SESSION['oauth_email'] = $email;
            $_SESSION['oauth_name'] = $full_name;
            $_SESSION['oauth_provider'] = 'google';
            
            // Clear OAuth session variables
            unset($_SESSION['oauth_state']);
            unset($_SESSION['oauth_action']);
            
            header('Location: ../register.php?oauth=google&email=' . urlencode($email) . '&name=' . urlencode($full_name));
            exit;
        }
    }
    
    // Clean up session
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_action']);
}

// If we get here, something went wrong
die('Error: OAuth authentication failed. Please try again.');
?>
