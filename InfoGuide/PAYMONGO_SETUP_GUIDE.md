# PayMongo API Keys - Setup Guide

## 🔑 Getting Your API Keys

### Step 1: Create PayMongo Account
1. Go to https://dashboard.paymongo.com
2. Click "Sign Up"
3. Create account with email/password
4. Verify email

### Step 2: Get Test API Keys
1. Log in to PayMongo Dashboard
2. Go to **Developers** → **API Keys**
3. You'll see:
   - **Secret Key** (pk_test_XXXXXXXXXXXXX)
   - **Public Key** (pk_test_XXXXXXXXXXXXX)
4. Copy both keys (you'll need them)

### Step 3: Get Live API Keys (Later, for Production)
- Same location in dashboard
- These start with `pk_live_` instead of `pk_test_`
- Use ONLY after testing is complete

---

## ⚙️ Option A: Environment Variables (Recommended)

### On Windows XAMPP:

1. **Open Apache Configuration File:**
   ```
   C:\xampp\apache\conf\extra\httpd-vhosts.conf
   ```

2. **Find Your Virtual Host:**
   Look for a section like:
   ```apache
   <VirtualHost *:80>
       ServerName localhost
       DocumentRoot "C:\xampp\htdocs"
       ...
   </VirtualHost>
   ```

3. **Add Before `</VirtualHost>`:**
   ```apache
   SetEnv PAYMONGO_SECRET_KEY pk_test_YOUR_SECRET_KEY_HERE
   SetEnv PAYMONGO_PUBLIC_KEY pk_test_YOUR_PUBLIC_KEY_HERE
   ```

4. **Save File**

5. **Restart Apache:**
   - XAMPP Control Panel
   - Click "Stop" Apache
   - Click "Start" Apache

6. **Verify:**
   - Open `http://localhost/lechonsystem/debug_payment.php`
   - Check "PayMongo Configuration" section
   - Should show green checkmark

---

## 🚀 Option B: Direct Configuration (Quick Testing)

### If you need to test quickly:

1. **Edit `process_preorder_payment.php`**
2. **Find lines 44-45:**
   ```php
   $paymongo_secret = getenv('PAYMONGO_SECRET_KEY') ?: 'pk_test_xyz';
   $paymongo_public = getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_xyz';
   ```

3. **Replace with your actual keys:**
   ```php
   $paymongo_secret = 'pk_test_YOUR_SECRET_KEY_HERE';
   $paymongo_public = 'pk_test_YOUR_PUBLIC_KEY_HERE';
   ```

4. **Save File**

5. **Test Payment:**
   - Go to `http://localhost/lechonsystem/preorder.php`
   - Try placing an order

### ⚠️ Security Note:
- Never commit API keys to version control
- Don't share this code with keys in it
- Remove keys before pushing to production
- Use environment variables in production

---

## 🧪 Test API Keys (Sandbox)

PayMongo provides test credit cards to use with sandbox API keys:

```
Card Number:  4242 4242 4242 4242
Expiry:       12/25 (any future date)
CVV:          123 (any 3 digits)
Name:         Test User (anything)
```

Use these to test payment flow WITHOUT real money.

---

## 🔍 Verify Configuration

### Check in Browser:
1. Open: `http://localhost/lechonsystem/debug_payment.php`
2. Scroll to "PayMongo Configuration" section
3. Look for green checkmark OR error message

### Expected Green Checkmark:
```
✓ API keys configured via environment variables
Secret Key: pk_test_XXXXX...XXXXX
```

### If Still Red:
- Check you used correct key format (pk_test_...)
- Verify Apache was restarted (Option A)
- Verify no typos in keys
- Check `process_preorder_payment.php` if using Option B

---

## 🛠️ Troubleshooting

### Problem: "PayMongo API keys not configured"
**Solution:**
1. You haven't set the keys yet
2. Follow Option A (environment) or Option B (direct config)
3. Restart Apache if using Option A
4. Refresh `debug_payment.php`

### Problem: "Invalid API key"
**Solution:**
1. Key format is wrong (should start with `pk_test_` or `pk_live_`)
2. Key is incomplete (missing characters)
3. Key has extra spaces (check for copy-paste issues)
4. Using live key without switching to production

### Problem: "Connection refused"
**Solution:**
1. Internet connection issue
2. Firewall blocking PayMongo API
3. XAMPP/PHP cURL not working
4. PayMongo API down (check https://status.paymongo.com)

### Problem: "Checkout session creation failed"
**Solution:**
1. PayMongo API key is invalid
2. PayMongo rate limit exceeded
3. Network error (try again)
4. Invalid checkout data (check error log)

---

## 📝 Checklist

- [ ] Created PayMongo account
- [ ] Got test API keys (pk_test_...)
- [ ] Configured keys (Option A or B)
- [ ] Restarted Apache (if Option A)
- [ ] Opened debug_payment.php
- [ ] PayMongo Configuration shows green
- [ ] Tested with sandbox card
- [ ] Order placed successfully
- [ ] Redirected to PayMongo payment page

---

## 📚 Related Files

- `process_preorder_payment.php` - Uses API keys here
- `paymongo_integration.php` - PayMongo class
- `debug_payment.php` - Check configuration status
- `logs/payment_errors.log` - Error messages
- `QUICK_FIX_GUIDE.md` - Quick troubleshooting

---

## 🎯 Next Steps

### After Configuration Works:
1. Test payment with sandbox card
2. Verify order in database
3. Check success/cancel pages
4. Monitor `logs/payment_errors.log`
5. Set up live keys for production

### Before Production:
1. Get LIVE API keys (pk_live_...)
2. Change configuration to use live keys
3. Update webhook URL in PayMongo dashboard
4. Test with small real payment
5. Monitor payment success rate

---

## 💡 Tips

- **Keep keys secure** - Never commit to Git
- **Use environment variables** - Better than hardcoding
- **Test thoroughly** - Use sandbox before going live
- **Monitor logs** - Check `logs/payment_errors.log` regularly
- **Document keys** - Note where you stored them

---

**Status:** Ready to configure PayMongo keys  
**Time to complete:** 10-15 minutes (Option A) or 5 minutes (Option B)  
**Next action:** Get keys from PayMongo dashboard, then follow above steps

