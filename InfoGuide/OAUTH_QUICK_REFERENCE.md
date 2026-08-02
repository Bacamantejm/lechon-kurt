# OAuth Integration - Quick Reference

## Files Modified

### Frontend (UI Components Added)
- **login.php** - Added social login buttons with CSS styling and JavaScript handlers
- **register.php** - Added social register buttons with CSS styling and JavaScript handlers

### Backend (OAuth Controllers)
- **controllers/google_auth.php** - Google OAuth 2.0 implementation
- **controllers/facebook_auth.php** - Facebook OAuth 2.0 implementation  
- **controllers/twitter_auth.php** - X (Twitter) OAuth 2.0 implementation
- **controllers/instagram_auth.php** - Instagram OAuth implementation (via Facebook Graph API)

## Configuration Checklist

### ☐ Google
- [ ] Get Client ID from [Google Cloud Console](https://console.cloud.google.com/)
- [ ] Get Client Secret from Google Cloud Console
- [ ] Add to `controllers/google_auth.php` (lines 9-10)

### ☐ Facebook
- [ ] Get App ID from [Facebook Developers](https://developers.facebook.com/)
- [ ] Get App Secret from Facebook Developers
- [ ] Add to `controllers/facebook_auth.php` (lines 9-10)

### ☐ X (Twitter)
- [ ] Get API Key from [X Developer Portal](https://developer.twitter.com/)
- [ ] Get API Secret from X Developer Portal
- [ ] Get Bearer Token from X Developer Portal
- [ ] Add to `controllers/twitter_auth.php` (lines 9-11)

### ☐ Instagram
- [ ] Use same App ID as Facebook
- [ ] Use same App Secret as Facebook
- [ ] Add to `controllers/instagram_auth.php` (lines 9-10)

### ☐ Database
- [ ] Run these SQL commands to ensure columns exist:
```sql
ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN oauth_provider_id VARCHAR(255) NULL;
```

## Credential Locations

**Google OAuth**
- File: `controllers/google_auth.php`
- Lines: 9-10
```php
define('GOOGLE_CLIENT_ID', ''); 
define('GOOGLE_CLIENT_SECRET', '');
```

**Facebook OAuth**
- File: `controllers/facebook_auth.php`
- Lines: 9-10
```php
define('FACEBOOK_APP_ID', '');
define('FACEBOOK_APP_SECRET', '');
```

**X (Twitter) OAuth**
- File: `controllers/twitter_auth.php`
- Lines: 9-11
```php
define('TWITTER_API_KEY', '');
define('TWITTER_API_SECRET', '');
define('TWITTER_BEARER_TOKEN', '');
```

**Instagram OAuth**
- File: `controllers/instagram_auth.php`
- Lines: 9-10
```php
define('INSTAGRAM_APP_ID', '');
define('INSTAGRAM_APP_SECRET', '');
```

## Redirect URIs to Register

### For Google
- `http://localhost/lechonsystem/controllers/google_auth.php`
- `https://yourdomain.com/lechonsystem/controllers/google_auth.php`

### For Facebook
- `http://localhost/lechonsystem/controllers/facebook_auth.php`
- `https://yourdomain.com/lechonsystem/controllers/facebook_auth.php`

### For X (Twitter)
- `http://localhost/lechonsystem/controllers/twitter_auth.php`
- `https://yourdomain.com/lechonsystem/controllers/twitter_auth.php`

### For Instagram
- `http://localhost/lechonsystem/controllers/instagram_auth.php`
- `https://yourdomain.com/lechonsystem/controllers/instagram_auth.php`

## How Users See It

### Login Page
Users see 4 social buttons below the email/password fields:
- Google
- Facebook
- X (Twitter)
- Instagram

### Register Page
Users see the same 4 social buttons after filling the form:
- Google
- Facebook
- X (Twitter)
- Instagram

## Flow Diagram

```
User clicks social button
        ↓
Redirects to provider login page
        ↓
User grants permission
        ↓
Redirects back with auth code
        ↓
System exchanges code for token
        ↓
System fetches user data
        ↓
Check if user exists in database
        ↓
User Exists? → Login
        ↓
User Doesn't Exist? → Auto-create account → Login
```

## Features

✅ CSRF Protection (state tokens)
✅ Secure OAuth flow (PKCE for Twitter)
✅ Auto account creation for new users
✅ Email pre-filling on redirect
✅ Password hashing for OAuth users
✅ Session management
✅ Error handling with user feedback
✅ Mobile responsive buttons
✅ Font Awesome icons

## Testing Steps

1. Navigate to `login.php`
2. Click one of the social provider buttons
3. You'll be redirected to that provider's login
4. After authorization, you'll be logged in or prompted to register
5. Verify in browser console if any errors occur

## Common Issues & Solutions

| Issue | Solution |
|-------|----------|
| "Credentials not configured" | Add API keys to controller files |
| Redirect URI mismatch | Check exact URL match in provider settings |
| User not created | Verify `oauth_provider` column exists in DB |
| CSRF error | State token mismatch - try again |
| No email from provider | Some providers don't provide email - system generates one |

## Security Notes

- All credentials should be moved to environment variables in production
- Always use HTTPS in production
- Implement rate limiting for OAuth endpoints
- Monitor for suspicious authentication attempts
- Enable email verification for OAuth accounts
- Keep tokens secure and never expose them in frontend

## Next Steps

1. Get OAuth credentials from each provider (see Configuration Checklist)
2. Add credentials to respective controller files
3. Add redirect URIs to each OAuth provider's settings
4. Run database migration for `oauth_provider` columns
5. Test with each provider
6. Move credentials to environment variables for production
7. Update redirect URIs to production domain before launch

---

See `OAUTH_SETUP_GUIDE.md` for detailed setup instructions.
