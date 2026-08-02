# Password Reset Feature - Quick Reference

## What's New

✅ **10-Minute Token Expiry** - Reset links now expire after just 10 minutes (was 1 hour)
✅ **Enhanced SweetAlert2 UI** - Beautiful, user-friendly alert dialogs
✅ **Better Error Messages** - Clear, helpful feedback for all scenarios
✅ **Real-Time Validation** - Live password requirement indicator
✅ **Mobile Optimized** - Fully responsive design

---

## User Flow

### 1. Forgot Password
```
Login Page → "Forgot Password" → reset_password_request.php
User enters email → System sends reset link → Success message
```

### 2. Click Email Link
```
Email link → reset_password.php?token=xxx
Token validated → If expired: Show error
If valid: Display password form
```

### 3. Reset Password
```
Enter new password (8+ chars, uppercase, number, special char)
Confirm password matches → Submit
Success → Auto-redirect to login
```

---

## Key Changes

### File: `/reset_password_request.php`
- **Line 55:** Token expiry changed to `+10 minutes`
- **Line 180:** Email template updated with 10-minute message
- **Line 198:** Plain text email updated
- **Lines 593-620:** Enhanced SweetAlert2 alerts

### File: `/reset_password.php`
- **Line 21:** Error message updated for 10-minute window
- **Lines 467-477:** Improved error page display
- **Lines 670-712:** Better password validation with helpful messages
- **Lines 723-757:** Enhanced SweetAlert2 for all scenarios

---

## Password Requirements

Users must create a password with:
```
✓ Minimum 8 characters
✓ At least one UPPERCASE letter (A-Z)
✓ At least one number (0-9)
✓ At least one special character (!@#$%^&*)
```

**Real-time checker shows each requirement as it's met**

---

## Database Setup

**Required columns (should already exist):**
```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME;
```

---

## Email Configuration

**Location:** `/reset_password_request.php` lines 125-135

```php
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-app-password'; // Use App Password, not Gmail password
```

---

## Testing Checklist

- [ ] Request reset with valid email → Receives email
- [ ] Click link within 10 minutes → Form displays
- [ ] Click link after 10 minutes → Shows "Link Expired" alert
- [ ] Enter weak password → Shows requirement list
- [ ] Mismatched passwords → Shows error alert
- [ ] Valid password → Shows success alert + redirects
- [ ] Test on mobile → Responsive and works well

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Email not arriving | Check Gmail credentials, use App Password |
| Token expires too fast | Check server timezone in config.php |
| SweetAlert not showing | Check browser console for errors |
| Password reqs not updating | Clear cache, verify jQuery loaded |

---

## SweetAlert2 Alerts Used

### Alert Types
1. **Success** (✓) - Green, for successful actions
2. **Error** (✗) - Red, for critical issues
3. **Warning** (!) - Orange, for caution items
4. **Info** (i) - Blue, for informational messages

### Special Features
- Backdrop dimming for focus
- Auto-dismiss or manual confirmation
- Custom button colors (#c62828 red)
- HTML support for formatted messages
- Auto-redirect capability

---

## Email Template Preview

```
TO: customer@example.com
SUBJECT: Password Reset Request - Lechon Delights

Dear John,

We received a request to reset your password for your Lechon Delights account.

[Reset Password Button]

Or copy this link: https://lechondelights.com/reset_password.php?token=abc123...

⚠️ IMPORTANT: This link will expire in 10 minutes for security reasons.

If you did not request this, please ignore this email.
```

---

## Security Highlights

✅ 32-byte random token generation
✅ 10-minute expiry window (short duration)
✅ Single-use tokens (cleared after use)
✅ Password hashing with PASSWORD_DEFAULT
✅ Server-side validation
✅ SMTP over TLS encryption
✅ Prepared statements (SQL injection safe)

---

## Browser Compatibility

✅ Chrome/Edge (latest)
✅ Firefox (latest)
✅ Safari (latest)
✅ Mobile browsers (iOS Safari, Chrome Mobile)
✅ All with JavaScript enabled

---

## Key Files

| File | Purpose |
|------|---------|
| `/reset_password_request.php` | Request reset link |
| `/reset_password.php` | Reset password form |
| `/includes/config.php` | Database & email functions |
| `/includes/header.php` | HTML header template |

---

## Front-End Integration

### Login Page Button
Add this button/link to your login page:
```html
<a href="reset_password_request.php" class="forgot-password-link">
    Forgot Password?
</a>
```

### Styling
Uses Bootstrap 5 + Font Awesome icons
Color scheme: Lechon Red (#c62828)
Responsive: Mobile-first design

---

## API Functions

### From config.php

**`validateResetToken($conn, $token)`**
- Returns: User ID if valid, FALSE if expired
- Checks token against database and current time

**`resetPassword($conn, $token, $new_password)`**
- Returns: TRUE if successful, FALSE if failed
- Hashes password, updates database, clears token

---

## Common Questions

**Q: How long is the reset link valid?**
A: 10 minutes from when requested

**Q: Can I change the expiry time?**
A: Yes, modify `strtotime('+10 minutes')` in reset_password_request.php line 55

**Q: Where is the email template?**
A: In reset_password_request.php, function `sendPasswordResetEmail()`, line ~140

**Q: What happens after successful reset?**
A: User redirected to login page (after 2-second success message)

**Q: Is the system secure?**
A: Yes! Implements industry best practices for password reset flows

---

## Need Help?

See detailed guide: `PASSWORD_RESET_IMPLEMENTATION.md`

---

**Status:** ✅ Ready for Production
**Last Updated:** January 22, 2026
