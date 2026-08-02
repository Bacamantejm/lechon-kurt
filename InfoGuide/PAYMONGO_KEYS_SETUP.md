# PayMongo API Keys - Configuration Summary

**Error:** "PayMongo API keys not configured. Please set PAYMONGO_SECRET_KEY and PAYMONGO_PUBLIC_KEY environment variables."

**Solution:** ✅ Complete setup wizard created

---

## 🚀 Quickest Solution (Right Now)

### Open This Page:
```
http://localhost/lechonsystem/paymongo_setup_wizard.php
```

**This page will:**
- ✓ Check current configuration status
- ✓ Guide you step-by-step through setup
- ✓ Show 3 configuration options
- ✓ Verify your keys are correct
- ✓ Provide troubleshooting help
- ✓ Auto-detect when keys are configured

---

## 📋 What You Need to Do (5 Minutes)

### 1. Get API Keys
- Go to https://dashboard.paymongo.com
- Get your **test** API keys (pk_test_...)
- Both secret and public keys

### 2. Choose Configuration Method
**Option A (Recommended):**
- Edit: `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
- Add environment variables
- Restart Apache

**Option B (Quick Testing):**
- Edit: `process_preorder_payment.php`
- Add keys directly in code
- No restart needed

**Option C (This Wizard):**
- Fill form on setup wizard page
- Still need to do Option A or B after

### 3. Verify
- Open: `http://localhost/lechonsystem/debug_payment.php`
- Look for green checkmark on PayMongo Configuration

### 4. Test
- Go to: `http://localhost/lechonsystem/preorder.php`
- Try placing an order
- Should redirect to PayMongo payment

---

## 🎯 Files Created

| File | Purpose | Access |
|------|---------|--------|
| `paymongo_setup_wizard.php` | Interactive setup guide | http://localhost/lechonsystem/paymongo_setup_wizard.php |
| `PAYMONGO_SETUP_GUIDE.md` | Detailed setup documentation | Text file (reference) |

---

## ✅ Next Steps

1. **Open Setup Wizard:**
   ```
   http://localhost/lechonsystem/paymongo_setup_wizard.php
   ```

2. **Follow the Steps:**
   - Get keys from PayMongo
   - Choose configuration method
   - Apply configuration

3. **Verify Setup:**
   ```
   http://localhost/lechonsystem/debug_payment.php
   ```
   - Check PayMongo Configuration section
   - Should show green checkmark

4. **Test Payment:**
   ```
   http://localhost/lechonsystem/preorder.php
   ```
   - Fill pre-order form
   - Submit order
   - Should redirect to PayMongo

---

## 🔑 Test API Keys (for Sandbox)

Use these with pk_test_ keys:

```
Card Number:  4242 4242 4242 4242
Expiry:       12/25
CVV:          123
Name:         Test User
```

---

## 📞 Help Resources

- **Setup Wizard:** `paymongo_setup_wizard.php`
- **Setup Guide:** `PAYMONGO_SETUP_GUIDE.md`
- **Debug Dashboard:** `debug_payment.php`
- **Error Log:** `logs/payment_errors.log`
- **Troubleshooting:** `PAYMENT_ERROR_TROUBLESHOOTING.md`

---

**Status:** ✅ Ready to configure API keys  
**Time to complete:** 5-10 minutes  
**Next action:** Open paymongo_setup_wizard.php right now

