<?php
/**
 * Facebook OAuth Authentication Handler
 * Handles login and registration via Facebook OAuth 2.0
 */

session_start();
require_once '../includes/config.php';

// Facebook OAuth Configuration
// TODO: Add your Facebook API credentials
define('FACEBOOK_APP_ID', '1577609610247558'); // Get from Facebook Developers
define('FACEBOOK_APP_SECRET', 'fca863a02ee88ea0398e781ed8422df2'); // Get from Facebook Developers
define('FACEBOOK_REDIRECT_URI', 'http://localhost/lechonsystem/controllers/facebook_auth.php');

// Check if app ID and secret are configured
if (empty(FACEBOOK_APP_ID) || empty(FACEBOOK_APP_SECRET)) {
    die('Error: Facebook OAuth credentials not configured. Please add APP_ID and APP_SECRET in ' . __FILE__);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'login';
$code = isset($_GET['code']) ? $_GET['code'] : null;
$state = isset($_GET['state']) ? $_GET['state'] : null;

// Step 1: Redirect to Facebook login page
if (empty($code)) {
    // Generate state token for security (CSRF protection)
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_action'] = $action;
    
    // Build Facebook OAuth URL
    $auth_url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
        'client_id' => FACEBOOK_APP_ID,
        'redirect_uri' => FACEBOOK_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'email,public_profile',
        'state' => $state
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
    $token_url = 'https://graph.facebook.com/v18.0/oauth/access_token';
    
    $token_params = [
        'client_id' => FACEBOOK_APP_ID,
        'client_secret' => FACEBOOK_APP_SECRET,
        'code' => $code,
        'redirect_uri' => FACEBOOK_REDIRECT_URI
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url . '?' . http_build_query($token_params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        die('Error: Failed to obtain access token from Facebook. HTTP Code: ' . $http_code);
    }
    
    $token_data = json_decode($response, true);
    
    if (!isset($token_data['access_token'])) {
        die('Error: No access token received from Facebook.');
    }
    
    // Step 3: Fetch user info using access token
    $user_info_url = 'https://graph.facebook.com/v18.0/me';
    
    $user_params = [
        'fields' => 'id,name,email,picture.width(200).height(200)',
        'access_token' => $token_data['access_token']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_info_url . '?' . http_build_query($user_params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $user_response = curl_exec($ch);
    curl_close($ch);
    
    $user_data = json_decode($user_response, true);
    
    if (!isset($user_data['email'])) {
        die('Error: Could not retrieve email from Facebook account. Make sure email is public.');
    }
    
    // Step 4: Create or login user
    $email = $user_data['email'];
    $full_name = $user_data['name'] ?? 'Facebook User';
    $facebook_id = $user_data['id'];
    $picture = isset($user_data['picture']['data']['url']) ? $user_data['picture']['data']['url'] : null;
    
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
        $_SESSION['oauth_provider'] = 'facebook';
        
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
            $phone = ''; // Not provided by Facebook OAuth
            $address = '';
            
            // Hash a random password for OAuth users
            $oauth_password = bin2hex(random_bytes(32));
            $hashed_password = password_hash($oauth_password, PASSWORD_BCRYPT);
            
            $insert_query = "INSERT INTO users (email, password, full_name, phone, address, account_type, user_type, oauth_provider, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            if ($insert_stmt = mysqli_prepare($conn, $insert_query)) {
                $user_type = 'customer';
                $oauth_provider = 'facebook';
                
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
                    $_SESSION['oauth_provider'] = 'facebook';
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
            $_SESSION['oauth_provider'] = 'facebook';
            
            // Clear OAuth session variables
            unset($_SESSION['oauth_state']);
            unset($_SESSION['oauth_action']);
            
            header('Location: ../register.php?oauth=facebook&email=' . urlencode($email) . '&name=' . urlencode($full_name));
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
