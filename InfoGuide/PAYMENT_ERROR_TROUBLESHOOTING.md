# Payment Processing Error - Troubleshooting Guide

## Quick Diagnosis

**Error:** "An error occurred during payment processing"

This error appears when the frontend receives a response indicating the payment processing failed. Here's how to diagnose and fix it:

## Step 1: Access the Debug Dashboard

Navigate to: `http://localhost/lechonsystem/debug_payment.php`

This will show you:
- ✓ Database connection status
- ✓ Preorders table existence
- ✓ Recent error logs
- ✓ PayMongo API key configuration
- ✓ User login status
- ✓ Product availability

## Step 2: Check the Error Log

The system now logs all payment processing errors to: `logs/payment_errors.log`

**To view errors:**
1. Open `logs/payment_errors.log` in a text editor
2. Look at the most recent entries (bottom of file)
3. Each entry has timestamp, error message, and relevant data

## Common Issues & Solutions

### Issue 1: "Table 'lechon_db.preorders' doesn't exist"

**Cause:** The preorders table hasn't been created yet.

**Solution:**
1. Open phpMyAdmin
2. Select `lechon_db` database
3. Click "SQL" tab
4. Copy and paste contents of `PREORDER_MIGRATION.sql`
5. Click "Go" to execute

**Verify:** Run debug_payment.php - should show green checkmark for "Preorders Table"

---

### Issue 2: "User not logged in"

**Cause:** The payment form was submitted without being logged in.

**Solution:**
1. Go to `http://localhost/lechonsystem/login.php`
2. Log in with valid credentials
3. Then navigate to `preorder.php`
4. Fill and submit the form

**Test Credentials:**
- Email: `admin@lechondelights.com`
- Password: `123`

Or register a new account at `register.php`

---

### Issue 3: "PayMongo API keys not configured"

**Cause:** PayMongo API keys not set as environment variables.

**Solution A: Using Environment Variables (Recommended)**

On Windows XAMPP:
1. Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
2. Add before `</VirtualHost>`:
   ```apache
   SetEnv PAYMONGO_SECRET_KEY pk_test_YOUR_SECRET_KEY_HERE
   SetEnv PAYMONGO_PUBLIC_KEY pk_test_YOUR_PUBLIC_KEY_HERE
   ```
3. Restart Apache

**Solution B: Direct Configuration**

Edit `process_preorder_payment.php` line 44-45:
```php
$paymongo_secret = 'pk_test_YOUR_SECRET_KEY_HERE';
$paymongo_public = 'pk_test_YOUR_PUBLIC_KEY_HERE';
```

**Get PayMongo Keys:**
1. Go to https://dashboard.paymongo.com
2. Create account or log in
3. Get test keys from Dashboard → Developers → API Keys
4. Keys look like: `pk_test_XXXXXXXXXXXXX`

---

### Issue 4: "Product not found"

**Cause:** The product_id sent doesn't exist in the database.

**Solution:**
1. Open debug_payment.php
2. Check "Products in Database" section
3. Use a valid product ID from the list
4. Verify preorder.php is fetching correct product IDs

---

### Issue 5: "Failed to create preorder record"

**Cause:** Database query error or missing columns in preorders table.

**Solution:**
1. Check the error log for specific database error
2. Verify preorders table has all required columns:
   - user_id, product_id, quantity
   - full_name, email, phone
   - street_address, province, city, barangay
   - payment_type, total_amount, status
   - paymongo_session_id, paymongo_payment_id
3. Run PREORDER_MIGRATION.sql to recreate table if needed

---

### Issue 6: "Failed to create payment session"

**Cause:** PayMongo API error or connection issue.

**Symptoms in Error Log:**
- cURL error connecting to PayMongo
- PayMongo API returns error response
- Network/SSL certificate error

**Solutions:**

**A. Check cURL:**
```php
// In your debug_payment.php or test script
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

echo "cURL Error: " . $error;
```

**B. Check SSL Certificate:**
If error mentions SSL, add to paymongo_integration.php makeRequest():
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
```

**C. Verify PayMongo API Keys:**
- Make sure keys start with `pk_test_` (test mode)
- Keys should be 30+ characters long
- Check for typos

---

### Issue 7: "Missing required field: [field_name]"

**Cause:** One of the form fields was empty when submitted.

**Solution:**
1. Check preorder.php form validation
2. Ensure all required fields have values before submitting
3. Look at error log to see which field is missing
4. Run debug_payment.php test form to verify

---

## Browser Console Debugging

**To see network errors:**

1. Open browser DevTools: Press `F12`
2. Go to "Console" tab
3. Try payment again
4. Look for:
   - Red errors in console
   - Network tab → See "process_preorder_payment.php" request
   - Response tab → See JSON error response

**Example Good Response:**
```json
{
  "success": true,
  "checkout_url": "https://checkout.paymongo.com/...",
  "preorder_id": 123
}
```

**Example Error Response:**
```json
{
  "success": false,
  "error": "Product not found"
}
```

---

## Step-by-Step Testing

### Test 1: Database Setup
```sql
-- Check if preorders table exists
SHOW TABLES LIKE 'preorders';

-- Check table structure
DESCRIBE preorders;

-- Check if products exist
SELECT COUNT(*) FROM products;

-- Check if you're logged in user exists
SELECT * FROM users WHERE id = YOUR_USER_ID;
```

### Test 2: PayMongo Connection
Use the test form in `debug_payment.php`:
1. Select a product from dropdown
2. Fill all fields with valid data
3. Click "Test Payment Processing"
4. Check the result

### Test 3: Check Error Log
```bash
# On Windows, open logs/payment_errors.log
# Last entries will show what went wrong
```

### Test 4: Manual API Test
```php
<?php
require_once 'paymongo_integration.php';

$paymongo = new PayMongoIntegration('pk_test_YOUR_KEY', 'pk_test_YOUR_KEY');

$result = $paymongo->createCheckoutSession([
    'amount' => 10000, // ₱100.00
    'description' => 'Test Order',
    'order_id' => 999,
    'customer_name' => 'Test User',
    'customer_email' => 'test@example.com',
    'customer_phone' => '09123456789',
    'success_url' => 'http://localhost/lechonsystem/payment_success.php',
    'cancel_url' => 'http://localhost/lechonsystem/payment_cancel.php'
]);

echo '<pre>' . print_r($result, true) . '</pre>';
?>
```

---

## System Requirements Check

Verify your system is ready:

```
✓ PHP version 7.4+ (check phpinfo.php)
✓ MySQL/MariaDB running
✓ cURL enabled in PHP
✓ OpenSSL support
✓ File write permissions in /logs directory
✓ Session support enabled
```

To check:
1. Create `phpinfo.php` with: `<?php phpinfo(); ?>`
2. Open in browser
3. Search for: cURL, OpenSSL, session

---

## Detailed Error Log Example

```
[2025-01-23 14:30:45] Processing order - Product ID: 5, User ID: 1
[2025-01-23 14:30:46] Amount calculated - Type: full, Amount: ₱3500
[2025-01-23 14:30:46] Preorder created - ID: 42
[2025-01-23 14:30:46] Checkout data prepared: {"amount":3500000,...}
[2025-01-23 14:30:47] cURL Error on POST /checkout_sessions: Connection refused
```

This log shows the issue: cURL cannot connect to PayMongo API
- Check internet connection
- Check if https://api.paymongo.com is accessible
- Check firewall settings

---

## Quick Fix Checklist

- [ ] Run PREORDER_MIGRATION.sql to create table
- [ ] Log in to your account
- [ ] Configure PayMongo API keys
- [ ] Check error log in logs/payment_errors.log
- [ ] Run debug_payment.php to verify setup
- [ ] Test with sample data in debug dashboard
- [ ] Check browser console for network errors
- [ ] Verify internet connection to PayMongo

---

## Getting Help

If issues persist:

1. **Check Error Log:** Most detailed info
   ```
   c:\xampp\htdocs\lechonsystem\logs\payment_errors.log
   ```

2. **Test Payment Page:** Debug dashboard
   ```
   http://localhost/lechonsystem/debug_payment.php
   ```

3. **PHP Error Log:** XAMPP errors
   ```
   C:\xampp\apache\logs\error.log
   ```

4. **PayMongo Status:** Is their API up?
   ```
   https://status.paymongo.com
   ```

---

## Files Modified

- `process_preorder_payment.php` - Enhanced with detailed logging
- `debug_payment.php` - New diagnostic dashboard
- `logs/payment_errors.log` - Error log file (created automatically)

