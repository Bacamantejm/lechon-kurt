<?php
/**
 * X (Twitter) OAuth Authentication Handler
 * Handles login and registration via X (Twitter) OAuth 2.0
 */

session_start();
require_once '../includes/config.php';

// X (Twitter) OAuth Configuration
// TODO: Add your X API credentials
define('TWITTER_API_KEY', ''); // Get from X Developer Portal
define('TWITTER_API_SECRET', ''); // Get from X Developer Portal
define('TWITTER_BEARER_TOKEN', ''); // Get from X Developer Portal
define('TWITTER_REDIRECT_URI', 'http://localhost/lechonsystem/controllers/twitter_auth.php');

// Check if credentials are configured
if (empty(TWITTER_API_KEY) || empty(TWITTER_API_SECRET)) {
    die('Error: X (Twitter) OAuth credentials not configured. Please add API_KEY and API_SECRET in ' . __FILE__);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'login';
$code = isset($_GET['code']) ? $_GET['code'] : null;
$state = isset($_GET['state']) ? $_GET['state'] : null;

// Step 1: Redirect to X (Twitter) login page
if (empty($code)) {
    // Generate state token for security (CSRF protection)
    $state = bin2hex(random_bytes(16));
    $code_challenge = base64_encode(random_bytes(32));
    
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_action'] = $action;
    $_SESSION['code_challenge'] = $code_challenge;
    
    // Build X (Twitter) OAuth URL
    $auth_url = 'https://twitter.com/i/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => TWITTER_API_KEY,
        'redirect_uri' => TWITTER_REDIRECT_URI,
        'scope' => 'tweet.read users.read follows.read follows.write tweet.write',
        'state' => $state,
        'code_challenge' => $code_challenge,
        'code_challenge_method' => 'plain'
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
    $token_url = 'https://twitter.com/2/oauth2/token';
    
    // Prepare Basic Auth header
    $auth = base64_encode(TWITTER_API_KEY . ':' . TWITTER_API_SECRET);
    
    $post_data = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => TWITTER_REDIRECT_URI,
        'code_verifier' => $_SESSION['code_challenge'] ?? ''
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        die('Error: Failed to obtain access token from X. HTTP Code: ' . $http_code . '. Response: ' . $response);
    }
    
    $token_data = json_decode($response, true);
    
    if (!isset($token_data['access_token'])) {
        die('Error: No access token received from X. Response: ' . $response);
    }
    
    // Step 3: Fetch user info using access token
    $user_info_url = 'https://api.twitter.com/2/users/me?user.fields=name,username,email,profile_image_url';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_info_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token_data['access_token']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $user_response = curl_exec($ch);
    curl_close($ch);
    
    $user_info = json_decode($user_response, true);
    
    if (!isset($user_info['data']) || !isset($user_info['data']['id'])) {
        die('Error: Could not retrieve user information from X.');
    }
    
    // Step 4: Create or login user
    $user_data = $user_info['data'];
    $twitter_id = $user_data['id'];
    $full_name = $user_data['name'] ?? $user_data['username'] ?? 'X User';
    
    // For X OAuth, email is often not public, so we generate one from username
    $username = $user_data['username'] ?? 'xuser';
    $email = $user_data['email'] ?? strtolower($username) . '@twitter.local';
    
    // Check if user exists
    $check_query = "SELECT id, user_type FROM users WHERE email = ? OR oauth_provider_id = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    $oauth_id = $twitter_id;
    mysqli_stmt_bind_param($stmt, "ss", $email, $oauth_id);
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
        $_SESSION['oauth_provider'] = 'twitter';
        
        // Clear OAuth session variables
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_action']);
        unset($_SESSION['code_challenge']);
        
        // Redirect to dashboard
        header('Location: ../index.php');
        exit;
    } else {
        // New user - create account
        $action = isset($_SESSION['oauth_action']) ? $_SESSION['oauth_action'] : 'login';
        
        if ($action === 'register') {
            // Auto-register user
            $account_type = 'individual';
            $phone = ''; // Not provided by X OAuth
            $address = '';
            
            // Hash a random password for OAuth users
            $oauth_password = bin2hex(random_bytes(32));
            $hashed_password = password_hash($oauth_password, PASSWORD_BCRYPT);
            
            $insert_query = "INSERT INTO users (email, password, full_name, phone, address, account_type, user_type, oauth_provider, oauth_provider_id, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            if ($insert_stmt = mysqli_prepare($conn, $insert_query)) {
                $user_type = 'customer';
                $oauth_provider = 'twitter';
                
                mysqli_stmt_bind_param($insert_stmt, "sssssssss", 
                    $email, 
                    $hashed_password, 
                    $full_name, 
                    $phone, 
                    $address, 
                    $account_type, 
                    $user_type,
                    $oauth_provider,
                    $oauth_id
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $user_id = mysqli_insert_id($conn);
                    
                    // Set session
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $email;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['user_type'] = 'customer';
                    $_SESSION['account_type'] = 'individual';
                    $_SESSION['oauth_provider'] = 'twitter';
                    $_SESSION['register_success'] = true;
                    
                    // Clear OAuth session variables
                    unset($_SESSION['oauth_state']);
                    unset($_SESSION['oauth_action']);
                    unset($_SESSION['code_challenge']);
                    
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
            $_SESSION['oauth_provider'] = 'twitter';
            
            // Clear OAuth session variables
            unset($_SESSION['oauth_state']);
            unset($_SESSION['oauth_action']);
            unset($_SESSION['code_challenge']);
            
            header('Location: ../register.php?oauth=twitter&email=' . urlencode($email) . '&name=' . urlencode($full_name));
            exit;
        }
    }
    
    // Clean up session
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_action']);
    unset($_SESSION['code_challenge']);
}

// If we get here, something went wrong
die('Error: OAuth authentication failed. Please try again.');
?>
