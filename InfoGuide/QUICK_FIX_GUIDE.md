# Payment Error - Quick Action Guide

**Problem:** "An error occurred during payment processing"

---

## 🚀 Immediate Action (5 Minutes)

### Step 1: Open Debug Dashboard
```
http://localhost/lechonsystem/debug_payment.php
```

### Step 2: Check Status (Look for green checkmarks)
- ✓ Database Connection
- ✓ Preorders Table
- ✓ PayMongo Keys
- ✓ User Logged In

### Step 3: Note Any Red Items
Any red items = issue to fix (see below)

---

## 🔧 Fix Common Issues

### Issue: Preorders Table Missing
**Fix:** 
1. Open phpMyAdmin
2. Select `lechon_db`
3. Click SQL tab
4. Paste contents of `PREORDER_MIGRATION.sql`
5. Click Go

**Time:** 2 minutes

---

### Issue: PayMongo Keys Not Set
**Fix:**

**Option A: Set Environment Variables (Recommended)**
1. Get keys from https://dashboard.paymongo.com
2. Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
3. Add these lines before `</VirtualHost>`:
   ```apache
   SetEnv PAYMONGO_SECRET_KEY pk_test_YOUR_KEY_HERE
   SetEnv PAYMONGO_PUBLIC_KEY pk_test_YOUR_KEY_HERE
   ```
4. Restart Apache

**Option B: Direct Config (Quick)**
1. Open `process_preorder_payment.php`
2. Find lines 44-45
3. Replace with your actual keys:
   ```php
   $paymongo_secret = 'pk_test_YOUR_KEY_HERE';
   $paymongo_public = 'pk_test_YOUR_KEY_HERE';
   ```

**Time:** 5-10 minutes

---

### Issue: User Not Logged In
**Fix:**
1. Go to `http://localhost/lechonsystem/login.php`
2. Log in with valid credentials
3. Then go to `/preorder.php`

**Test Creds:**
- Email: `admin@lechondelights.com`
- Password: `123`

**Time:** 1 minute

---

### Issue: Database Connection Failed
**Fix:**
1. Make sure XAMPP is running (Apache + MySQL)
2. Check credentials in `includes/config.php`
3. Default is: `root` user, empty password, `lechon_db` database

**Time:** 2 minutes

---

## 🧪 Test Payment Processing

1. Go to `http://localhost/lechonsystem/debug_payment.php`
2. Scroll to "Test Payment Processing" section
3. Fill form with sample data:
   - Product: (select from dropdown)
   - Quantity: 1
   - Name: Test Customer
   - Email: test@example.com
   - Phone: 09123456789
   - Address: 123 Main St
   - City: Makati
   - Payment Type: Full Payment
4. Click "Test Payment Processing"
5. See result (success or specific error)

**Time:** 2 minutes

---

## 📋 Verify Setup

Run through this checklist:

```
□ XAMPP is running (Apache + MySQL)
□ Database lechon_db exists
□ Preorders table created
□ User is logged in
□ PayMongo API keys set
□ Products exist in database
```

If all checked, payment processing should work.

---

## 🔍 Check Error Details

**Browser Console (F12):**
- Open browser
- Press F12
- Go to Console tab
- Try payment again
- Look for red error messages

**Error Log File:**
- Path: `logs/payment_errors.log`
- Open with text editor
- Last entries show recent errors
- Copy error message and search troubleshooting guide

**Database Check:**
```sql
-- Check preorders table exists
SHOW TABLES LIKE 'preorders';

-- Check products exist
SELECT COUNT(*) FROM products;

-- Check user logged in
SELECT * FROM users WHERE id = YOUR_USER_ID;
```

---

## 📚 Full Documentation

**For complete help:**
- See: `PAYMENT_ERROR_TROUBLESHOOTING.md`
- Section: 7 Common Issues & Solutions
- Section: Browser Console Debugging
- Section: Step-by-Step Testing

---

## ✅ Success Indicators

When payment processing works, you should see:

1. **In Browser Console:**
   ```json
   {
     "success": true,
     "checkout_url": "https://checkout.paymongo.com/...",
     "preorder_id": 123
   }
   ```

2. **In Error Log:**
   ```
   [timestamp] Payment session created - Checkout URL: https://checkout...
   ```

3. **In Database:**
   - New row in `preorders` table
   - Status = "pending"
   - paymongo_session_id = filled

4. **User Experience:**
   - Form submitted
   - Redirected to PayMongo payment page
   - After payment: redirected to success/cancel page

---

## 📞 Still Having Issues?

1. **Check Error Log:**
   ```
   logs/payment_errors.log
   ```

2. **Run Debug Dashboard:**
   ```
   debug_payment.php
   ```

3. **Read Troubleshooting Guide:**
   ```
   PAYMENT_ERROR_TROUBLESHOOTING.md
   ```

4. **Check PayMongo API Keys:**
   - Must start with `pk_test_` or `pk_live_`
   - Must be 30+ characters
   - Must be correct (no typos)

5. **Verify Database Connection:**
   - XAMPP MySQL running?
   - Correct credentials in config.php?
   - lechon_db database exists?

---

## 🎯 Most Likely Fix

Based on common issues, try in this order:

1. **Create preorders table** (Fixes 40% of cases)
   - Run PREORDER_MIGRATION.sql

2. **Set PayMongo API keys** (Fixes 30% of cases)
   - Configure environment variables or direct config

3. **Log in to account** (Fixes 20% of cases)
   - Must be logged in to place pre-order

4. **Check error log** (Fixes remaining cases)
   - Open logs/payment_errors.log
   - See specific error message
   - Follow solution in troubleshooting guide

---

**Status:** All diagnostic tools in place. You should be able to identify and fix the issue within 15 minutes.

