# Password Reset Testing Guide

## Pre-Testing Checklist

- [ ] Database has `reset_token` and `reset_expires` columns in `users` table
- [ ] Email credentials configured in `reset_password_request.php` (line ~130)
- [ ] Test user account exists in database with valid email
- [ ] XAMPP MySQL running
- [ ] XAMPP Apache running
- [ ] Browser with JavaScript enabled

---

## Test Scenario 1: Happy Path (Successful Reset)

### Objective
User successfully resets password within 10-minute window

### Steps
1. Go to login page → Click "Forgot Password" link
2. Enter registered email address (e.g., user@example.com)
3. Click "Send Reset Link" button
4. **Expected:** 
   - Button shows "Sending Reset Link..." (loading state)
   - Success SweetAlert2 shows: "Success! Check your inbox and spam folder..."
   - Email received in inbox (or spam folder)
5. Click link in email (within 10 minutes)
6. **Expected:**
   - Page displays password reset form
   - "Create New Password" header shows
   - Password requirements box displays with checkmarks
7. Enter new password meeting all requirements:
   - [ ] At least 8 characters
   - [ ] Uppercase letter (A-Z)
   - [ ] Number (0-9)
   - [ ] Special character (!@#$%^&*)
8. **Expected:**
   - Real-time validation shows green checkmarks
   - Requirements update as you type
9. Confirm password matches
10. Click "Reset Password" button
11. **Expected:**
    - Button shows "Resetting Password..." (loading state)
    - Success SweetAlert2 shows
    - Auto-redirects to login page after 2 seconds
12. Try logging in with old password
13. **Expected:** Login fails with "Invalid credentials"
14. Try logging in with new password
15. **Expected:** Login succeeds, user dashboard loads

---

## Test Scenario 2: Expired Token

### Objective
User tries to use reset link after 10-minute expiry

### Steps
1. Request password reset (as in Test 1, steps 1-4)
2. Wait 11 minutes (or modify database `reset_expires` to past time)
3. Click reset link from email
4. **Expected:**
   - Error page displays with clock icon
   - Message: "Reset Link Expired"
   - "For security reasons, reset links are only valid for 10 minutes"
   - SweetAlert2 shows: "Your reset link has expired..."
   - Two button options: "Request New Link" or "Back to Login"
5. Click "Request New Link"
6. **Expected:** Redirects to reset_password_request.php
7. Follow Test Scenario 1 again with new link

---

## Test Scenario 3: Invalid Email

### Objective
System rejects non-existent or invalid emails

### Steps
1. Go to password reset page
2. Enter invalid email (not in database)
3. Click "Send Reset Link"
4. **Expected:**
   - SweetAlert2 shows info message
   - Message: "If this email is registered, you will receive..."
   - No email actually sent (security feature)
   - Success message shown regardless (prevents user enumeration)

---

## Test Scenario 4: Invalid Email Format

### Objective
Client-side validation catches email format errors

### Steps
1. Go to password reset page
2. Enter invalid format: "notanemail"
3. Click "Send Reset Link"
4. **Expected:**
   - SweetAlert2 shows warning
   - Message: "Please enter a valid email address"
   - Format hint: "e.g., user@example.com"

---

## Test Scenario 5: Weak Password Validation

### Objective
System rejects passwords not meeting requirements

### Steps
**Test 5a: Too Short**
1. Go to reset link (valid token)
2. Enter password: "Pass1!"
3. Click reset
4. **Expected:** SweetAlert2 error: "Password must be at least 8 characters"

**Test 5b: No Uppercase**
1. Enter password: "password123!"
2. Click reset
3. **Expected:** SweetAlert2 with requirements list

**Test 5c: No Number**
1. Enter password: "Password!"
2. Click reset
3. **Expected:** SweetAlert2 with requirements list

**Test 5d: No Special Character**
1. Enter password: "Password123"
2. Click reset
3. **Expected:** SweetAlert2 with requirements list

**Test 5e: Mismatched Passwords**
1. Enter password 1: "Password123!"
2. Enter password 2: "Password456!"
3. Click reset
4. **Expected:** SweetAlert2 error: "Passwords don't match"

---

## Test Scenario 6: Real-Time Validation Indicator

### Objective
Password requirements update in real-time as user types

### Steps
1. Go to valid reset link
2. Click on password field
3. Type: "p" 
4. **Expected:** All requirements show unchecked (red)
5. Add more: "Password"
6. **Expected:** "At least 8 characters" still unchecked (only 8 chars, need uppercase too)
7. Make uppercase: "Password"
8. **Expected:** Uppercase requirement checked (green)
9. Add number: "Password1"
10. **Expected:** Number requirement checked
11. Add special: "Password1!"
12. **Expected:** All requirements except "match" checked
13. Enter same in confirm field
14. **Expected:** "Passwords match" also checked

---

## Test Scenario 7: Empty Form Fields

### Objective
System requires both password fields

### Steps
1. Go to valid reset link
2. Leave both password fields empty
3. Click "Reset Password"
4. **Expected:** SweetAlert2 info alert: "Missing Fields - Please enter both password fields"

---

## Test Scenario 8: Mobile Responsiveness

### Objective
Forms work well on mobile devices

### Steps
1. Use Chrome DevTools → Toggle Device Toolbar (mobile view)
2. Test all scenarios from landscape and portrait
3. **Expected:**
   - Forms remain centered
   - Text readable (no horizontal scroll)
   - Buttons touch-friendly (min 44px height)
   - SweetAlert2 displays properly
   - Email readable on mobile

---

## Test Scenario 9: Email Delivery

### Objective
Reset email arrives and contains correct information

### Steps
1. Request password reset with test email
2. Check inbox for email
3. **Expected:**
   - From: "Lechon Delights" <noreply@lechondelights.com>
   - Subject: "Password Reset Request - Lechon Delights"
   - Contains user's name: "Hello [Full Name]"
   - Contains reset button with link
   - Alternative link shown
   - States: "This link will expire in 10 minutes"
   - Security note: "Do not share this link"
   - Can see both HTML view and plain text

---

## Test Scenario 10: Database Verification

### Objective
Token correctly stored and expires in database

### Steps
1. Request password reset
2. Open phpMyAdmin
3. Go to `lechon_db.users` table
4. Find the user's record
5. **Expected:**
   - `reset_token` column contains 64-character hex string
   - `reset_expires` column contains datetime 10 minutes from now
6. Wait 11 minutes
7. Refresh database view
8. Click reset link
9. **Expected:** Validation fails (token expired)

---

## Test Scenario 11: Multiple Reset Requests

### Objective
User can request multiple reset links

### Steps
1. Request password reset (link 1)
2. Request password reset again (link 2) immediately
3. **Expected:** Second request overwrites first token in database
4. Click link 1 (old token)
5. **Expected:** "Link Expired" error
6. Click link 2 (new token)
7. **Expected:** Form displays (token valid)

---

## Test Scenario 12: Different Browsers

### Test On
- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

### Expected Results
- All functionality works identically
- SweetAlert2 displays correctly
- Forms responsive on mobile
- Email links clickable on all browsers

---

## Test Scenario 13: Error Handling

### Objective
Application handles edge cases gracefully

### Steps
**Test 13a: No Token in URL**
1. Go to: `reset_password.php` (no ?token=xxx)
2. **Expected:** Error page with "No reset token" message

**Test 13b: Invalid Token Format**
1. Go to: `reset_password.php?token=invalid123`
2. **Expected:** "Link Expired or Invalid" error

**Test 13c: Database Connection Failure**
1. Stop MySQL (will simulate error)
2. Try password reset
3. **Expected:** Error message (graceful failure)
4. Restart MySQL

---

## Test Scenario 14: Form Interaction

### Objective
Form buttons respond correctly to user actions

### Steps
1. Go to valid reset link
2. Click password field → Type slowly
3. **Expected:** Requirements update in real-time (no lag)
4. Click show/hide password button
5. **Expected:** Password visibility toggles
6. Try to submit incomplete form
7. **Expected:** Alert shows before submission
8. Complete form correctly
9. Click reset button
10. **Expected:** Button disables, shows loading spinner

---

## Test Scenario 15: Success Message Flow

### Objective
After successful reset, user properly redirected

### Steps
1. Complete successful password reset (Test 1)
2. See success SweetAlert2
3. Message should say: "Password reset successfully"
4. Auto-redirect count: 2 seconds
5. **Expected:** Automatic redirect to login.php
6. Can click "Go to Login" button manually
7. **Expected:** Also redirects to login.php
8. Page should not close/dismiss before redirect completes

---

## Troubleshooting During Tests

### No Email Received
```
1. Check Gmail credentials in reset_password_request.php
2. Check server error logs for SMTP errors
3. Look in spam/junk folder
4. Verify Gmail account allows app access
```

### SweetAlert Not Showing
```
1. Check browser console (F12 → Console)
2. Look for JavaScript errors
3. Verify CDN link is correct
4. Check internet connection
```

### Token Not Expiring After 10 Minutes
```
1. Check server system time (correct?)
2. Check PHP timezone: date_default_timezone_set('Asia/Manila')
3. Check database for correct timestamp format
4. Manually set reset_expires to past time for testing
```

### Form Not Submitting
```
1. Check browser console for errors
2. Verify form has correct id="resetPasswordForm"
3. Check button has type="submit"
4. Ensure JavaScript enabled
```

### Email Link Doesn't Work
```
1. Check if ampersands properly encoded (?token=xxx)
2. Try copy/pasting link instead of clicking
3. Check if email client is modifying link
4. Try on different device
```

---

## Performance Metrics

### Expected Times
- Page load: < 1 second
- Form validation: Instant (real-time)
- Email send: 1-3 seconds (button loading state)
- Database query: < 100ms
- Page redirect: Instant

### Load Testing
- Test with 10+ concurrent password reset requests
- Database should handle without issues
- Email queue should not backup

---

## Security Validation

- [ ] Token is random 32-byte hex (check in DB)
- [ ] Token expires exactly 10 minutes from request
- [ ] Old token becomes invalid after new request
- [ ] Old token cannot be reused
- [ ] Password is hashed before storage (PASSWORD_DEFAULT)
- [ ] Email address not enumerated (same message for all emails)
- [ ] No sensitive data in URL parameters (only token)
- [ ] SQL injection attempts blocked (prepared statements)
- [ ] XSS attempts blocked (htmlspecialchars)

---

## Sign-Off Checklist

All tests completed and passed:
- [ ] Scenario 1: Happy path ✓
- [ ] Scenario 2: Expired token ✓
- [ ] Scenario 3: Invalid email ✓
- [ ] Scenario 4: Bad email format ✓
- [ ] Scenario 5: Weak passwords ✓
- [ ] Scenario 6: Real-time validation ✓
- [ ] Scenario 7: Empty fields ✓
- [ ] Scenario 8: Mobile responsive ✓
- [ ] Scenario 9: Email delivery ✓
- [ ] Scenario 10: Database ✓
- [ ] Scenario 11: Multiple requests ✓
- [ ] Scenario 12: Browsers ✓
- [ ] Scenario 13: Error handling ✓
- [ ] Scenario 14: Form interaction ✓
- [ ] Scenario 15: Success flow ✓

**Tester Name:** ___________
**Date:** ___________
**Notes:** ___________

---

**Status:** ✅ Ready for Production Testing
**Last Updated:** January 22, 2026
