# OAuth Integration Setup Guide

## Overview
This guide explains how to configure OAuth authentication for Google, Facebook, X (Twitter), and Instagram in your Lechon Delights system.

## Prerequisites
- All OAuth controllers are already integrated in the following files:
  - `controllers/google_auth.php`
  - `controllers/facebook_auth.php`
  - `controllers/twitter_auth.php`
  - `controllers/instagram_auth.php`
- Social login buttons are added to `login.php` and `register.php`

---

## 1. Google OAuth Setup

### Step 1: Create a Google Cloud Project
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project named "Lechon Delights"
3. Enable the Google+ API

### Step 2: Create OAuth 2.0 Credentials
1. Navigate to **APIs & Services** > **Credentials**
2. Click **Create Credentials** > **OAuth client ID**
3. Choose **Web application**
4. Add authorized redirect URIs:
   - `http://localhost/lechonsystem/controllers/google_auth.php`
   - `http://yourdomain.com/lechonsystem/controllers/google_auth.php` (for production)
5. Copy the **Client ID** and **Client Secret**

### Step 3: Configure in Your Application
Edit `controllers/google_auth.php` and add your credentials:

```php
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
```

---

## 2. Facebook OAuth Setup

### Step 1: Create a Facebook Application
1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Log in with your Facebook account
3. Click **My Apps** > **Create App**
4. Choose **Consumer** as app type
5. Fill in app details (App Name: "Lechon Delights", etc.)

### Step 2: Configure Facebook Login
1. Add **Facebook Login** product to your app
2. Go to **Settings** > **Basic**
3. Copy the **App ID** and **App Secret**
4. Add App Domains:
   - `localhost`
   - `yourdomain.com` (for production)
5. Go to **Facebook Login** > **Settings**
6. Add Valid OAuth Redirect URIs:
   - `http://localhost/lechonsystem/controllers/facebook_auth.php`
   - `http://yourdomain.com/lechonsystem/controllers/facebook_auth.php` (for production)

### Step 3: Configure in Your Application
Edit `controllers/facebook_auth.php` and add your credentials:

```php
define('FACEBOOK_APP_ID', 'YOUR_FACEBOOK_APP_ID');
define('FACEBOOK_APP_SECRET', 'YOUR_FACEBOOK_APP_SECRET');
```

---

## 3. X (Twitter) OAuth Setup

### Step 1: Create a Twitter Developer Account
1. Go to [X Developer Portal](https://developer.twitter.com/)
2. Apply for a developer account
3. Once approved, go to **Projects & Apps**

### Step 2: Create an App
1. Click **Create an App**
2. Name it "Lechon Delights"
3. Choose your use case (e.g., "Building a consumer-facing application")

### Step 3: Configure OAuth 2.0
1. Go to **App Settings** > **Authentication settings**
2. Enable **OAuth 2.0**
3. Set Callback URL / Redirect URL:
   - `http://localhost/lechonsystem/controllers/twitter_auth.php`
   - `http://yourdomain.com/lechonsystem/controllers/twitter_auth.php` (for production)
4. Set Website URL: `http://yourdomain.com`
5. Enable **Request email address from users** (optional but recommended)

### Step 4: Get Your Credentials
1. Go to **Keys and Tokens**
2. Copy **API Key** (Client ID)
3. Copy **API Key Secret** (Client Secret)
4. Generate and copy **Bearer Token**

### Step 5: Configure in Your Application
Edit `controllers/twitter_auth.php` and add your credentials:

```php
define('TWITTER_API_KEY', 'YOUR_TWITTER_API_KEY');
define('TWITTER_API_SECRET', 'YOUR_TWITTER_API_SECRET');
define('TWITTER_BEARER_TOKEN', 'YOUR_TWITTER_BEARER_TOKEN');
```

---

## 4. Instagram OAuth Setup

### Step 1: Use Facebook Developers
Instagram OAuth is managed through Facebook's Graph API. Follow the Facebook OAuth setup above using the same App ID and App Secret.

### Step 2: Configure Instagram Product
1. In your Facebook App, add **Instagram** product
2. Go to **Instagram** > **Settings**
3. Add the same redirect URIs:
   - `http://localhost/lechonsystem/controllers/instagram_auth.php`
   - `http://yourdomain.com/lechonsystem/controllers/instagram_auth.php` (for production)

### Step 3: Configure in Your Application
Edit `controllers/instagram_auth.php` and add your credentials:

```php
define('INSTAGRAM_APP_ID', 'YOUR_FACEBOOK_APP_ID');
define('INSTAGRAM_APP_SECRET', 'YOUR_FACEBOOK_APP_SECRET');
```

---

## 5. Database Schema Updates

The OAuth implementation uses the following columns in the `users` table:
- `oauth_provider` (VARCHAR 50) - Provider name (google, facebook, twitter, instagram)
- `oauth_provider_id` (VARCHAR 255) - User ID from OAuth provider

If these columns don't exist, add them:

```sql
ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN oauth_provider_id VARCHAR(255) NULL;
```

---

## 6. How OAuth Works

### Login Flow
1. User clicks social provider button (Google, Facebook, X, Instagram)
2. Redirected to provider's login page
3. User grants permission
4. Redirected back with authorization code
5. System exchanges code for access token
6. System fetches user data
7. If user exists → Login to account
8. If user doesn't exist → Redirect to register with pre-filled email/name

### Registration Flow
1. User clicks social provider button on register page
2. Same as login flow above, but redirects to finish registration
3. Account is created automatically with provided name and email
4. User is logged in immediately

---

## 7. Security Considerations

✅ **Already Implemented:**
- CSRF protection using state tokens
- Secure random state generation
- HTTPS verification enabled for API calls
- Passwords hashed with bcrypt for OAuth users
- User data validated before database insertion
- Prepared statements to prevent SQL injection

⚠️ **Before Production:**
- Update `GOOGLE_REDIRECT_URI`, `FACEBOOK_REDIRECT_URI`, `TWITTER_REDIRECT_URI`, `INSTAGRAM_REDIRECT_URI` with your actual domain
- Use HTTPS in production (force `secure` flag in cookies)
- Keep API credentials in environment variables (not hardcoded)
- Enable email verification before allowing access
- Implement rate limiting on OAuth endpoints
- Monitor for suspicious OAuth activity

---

## 8. Testing

### Local Testing
1. All providers are configured for `http://localhost` redirects
2. Fill in your test credentials in the controller files
3. Click the social buttons in login.php or register.php
4. Should redirect to provider and back

### Production Testing
1. Update all redirect URIs to your production domain
2. Use HTTPS in all redirect URLs
3. Update the `REDIRECT_URI` constants to production URLs
4. Test with real user accounts

---

## 9. Troubleshooting

### "Credentials not configured" Error
- Add your Client ID and Secret to the respective controller file

### Redirect URI Mismatch Error
- Ensure the redirect URI in your OAuth provider matches exactly
- Check for trailing slashes, http vs https, and port numbers

### "No access token received"
- Verify your credentials are correct
- Check that the OAuth provider's API is accessible
- Check your network/firewall settings

### User not logging in
- Check database columns `oauth_provider` and `oauth_provider_id` exist
- Verify email is being retrieved from OAuth provider
- Check browser console for JavaScript errors

### Email not available from provider
- X (Twitter) doesn't always provide email
- Instagram doesn't provide email via Basic API
- System handles this by generating placeholder emails

---

## 10. References

- [Google OAuth Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Facebook Login Documentation](https://developers.facebook.com/docs/facebook-login)
- [X OAuth 2.0 Documentation](https://developer.twitter.com/en/docs/authentication/oauth-2-0)
- [Instagram Graph API Documentation](https://developers.facebook.com/docs/instagram-api)

---

**Need help?** Check the error messages in your browser console and PHP error logs for detailed debugging information.
