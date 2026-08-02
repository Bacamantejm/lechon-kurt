# Pre-Order PayMongo Payment Integration Guide

**Last Updated:** January 23, 2026  
**System:** Lechon Delights Pre-Order System  
**Version:** 1.0

---

## Overview

This guide documents the complete PayMongo payment integration for the pre-order feature. The system supports:
- **Full Payment:** Customer pays entire order amount upfront
- **Downpayment Option:** Customer pays 30% upfront, 70% on pickup/delivery
- **Multiple Payment Methods:** Credit/Debit Cards, GCash, Maya, Bank Transfer (via PayMongo)

---

## Architecture

### Payment Flow Diagram

```
Customer Creates Pre-Order
         ↓
   preorder.php (Form submitted)
         ↓
   preorder_payment.php (Payment method selection)
         ↓
   Create PayMongo Checkout Session
         ↓
   Customer redirected to PayMongo checkout page
         ↓
   Customer completes payment on PayMongo
         ↓
   PayMongo redirects to preorder_payment_success.php
         ↓
   Verify payment with PayMongo API
         ↓
   Update pre_order_payments table with status
         ↓
   Update pre_orders table with payment status
         ↓
   Send confirmation email
         ↓
   Display success page to customer
```

---

## Key Files

### 1. **paymongo_integration.php** (PayMongo API Client)
**Location:** Root directory  
**Purpose:** Encapsulates PayMongo API communication  
**API Keys:** 
- Secret Key: `sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE`
- Public Key: `pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE`

**Key Methods:**

#### `createCheckoutSession($orderData)`
Creates a new PayMongo checkout session for payment processing.

**Parameters:**
```php
$orderData = [
    'amount' => 5000,                    // Amount in PHP (not centavos)
    'currency' => 'PHP',
    'description' => 'Pre-Order Payment - Order #123',
    'order_id' => 123,                   // Pre-order ID
    'customer_name' => 'John Doe',
    'customer_email' => 'john@email.com',
    'customer_phone' => '09123456789',
    'payment_method' => 'all',           // 'all', 'gcash', 'paymaya', 'card'
    'success_url' => 'http://...',
    'cancel_url' => 'http://...'
];

$paymongo = new PayMongoIntegration($secretKey, $publicKey);
$result = $paymongo->createCheckoutSession($orderData);

// Returns:
// [
//     'success' => true,
//     'checkout_url' => 'https://paymongo.com/checkout/...',
//     'session_id' => 'cs_...'
// ]
```

**Internal Flow:**
1. Prepares payload with line items, customer info, and URLs
2. Makes POST request to `/checkout_sessions` endpoint
3. PayMongo returns checkout session with unique ID
4. Returns checkout URL for customer redirection

**Important Notes:**
- Amount conversion: Method expects PHP amount, NOT centavos
- Customer details are required for successful session creation
- Success and Cancel URLs must be absolute (include protocol and host)

#### `retrieveCheckoutSession($sessionId)`
Retrieves and verifies checkout session status from PayMongo.

**Parameters:**
```php
$sessionId = 'cs_...';  // Session ID returned from createCheckoutSession

$result = $paymongo->retrieveCheckoutSession($sessionId);

// Returns:
// [
//     'success' => true,
//     'status' => 'paid',  // or 'pending', 'expired', etc.
//     'session_data' => [...]  // Full session data from PayMongo
// ]
```

**Internal Flow:**
1. Makes GET request to `/checkout_sessions/{sessionId}`
2. Extracts payment status from response
3. Returns structured result with payment verification

**Status Values:**
- `paid` - Payment successful
- `pending` - Awaiting payment
- `expired` - Session expired
- `cancelled` - User cancelled payment

#### `verifyPayment($sessionId)`
Legacy method for payment verification (currently in demo mode).

#### `createPaymentLink($amount, $description, $reference)`
Creates a reusable payment link (useful for invoices, email reminders).

### 2. **preorder_payment.php** (Payment Processing Page)
**Location:** Root directory  
**Purpose:** Customer selects payment method and initiates PayMongo checkout

**Key Components:**

#### Payment Method Selection
```html
<!-- PayMongo Option (Card, GCash, Maya) -->
<label class="method-option">
    <input type="radio" name="payment_method" value="paymongo" checked>
    Card Payment (PayMongo)
</label>

<!-- Cash Option -->
<label class="method-option">
    <input type="radio" name="payment_method" value="cash">
    Cash Payment
</label>
```

#### PayMongo Checkout Session Creation (Lines 43-88)
```php
if ($payment_method === 'paymongo') {
    // 1. Get user details (name, email, phone)
    $user_query = "SELECT email, full_name, phone FROM users WHERE id = ?";
    // ... execute query
    
    // 2. Calculate payment amount (full or downpayment)
    $amount = ($payment_type === 'downpayment') 
        ? $preorder['downpayment_amount'] 
        : $preorder['total_price'];
    
    // 3. Create PayMongo checkout session
    $checkout_data = $paymongo->createCheckoutSession([
        'amount' => $amount,
        'currency' => 'PHP',
        'description' => "Pre-Order Payment - Order #$pre_order_id",
        'order_id' => $pre_order_id,
        'customer_name' => $user_details['full_name'],
        'customer_email' => $user_details['email'],
        'customer_phone' => $user_details['phone'],
        'payment_method' => 'all',
        'success_url' => '...',
        'cancel_url' => '...'
    ]);
    
    // 4. Save session ID in pre_order_payments table
    $insert_payment = "INSERT INTO pre_order_payments (...) VALUES (...)";
    // ... save session_id as transaction_id
    
    // 5. Redirect to PayMongo checkout
    header("Location: " . $checkout_data['checkout_url']);
}
```

### 3. **preorder_payment_success.php** (Payment Verification)
**Location:** Root directory  
**Purpose:** Verify payment with PayMongo and update database

**Key Components:**

#### Payment Verification (Lines 30-87)
```php
if ($payment_method === 'paymongo') {
    // 1. Retrieve session ID from pre_order_payments table
    $payment_query = "SELECT transaction_id FROM pre_order_payments 
                      WHERE pre_order_id = ? AND payment_gateway = 'paymongo'";
    
    // 2. Verify payment with PayMongo API
    $paymongo = new PayMongoIntegration($secretKey, $publicKey);
    $session = $paymongo->retrieveCheckoutSession($session_id);
    
    // 3. Check if payment was successful
    if ($session['success'] && $session['status'] === 'paid') {
        // 4. Update pre_order_payments table
        $update_payment = "UPDATE pre_order_payments 
                           SET payment_status = 'paid', paid_at = NOW()";
        
        // 5. Update pre_orders table
        $update_preorder = "UPDATE pre_orders 
                            SET downpayment_status = 'paid' OR final_payment_status = 'paid'";
        
        // 6. Send confirmation email
        $email_service->sendPreOrderConfirmation(...);
    }
}
```

### 4. **preorder_service.php** (Database Operations)
**Location:** Root directory  
**Purpose:** Pre-order business logic and database operations

**Key Methods:**

#### `recordCashPayment($pre_order_id, $payment_type, $amount, $gateway)`
Records payment in the database.

```php
$result = $preorder_service->recordCashPayment(
    $pre_order_id,           // Pre-order ID
    'downpayment',           // or 'final_payment'
    5000,                    // Amount
    'paymongo'               // or 'cash'
);

// Returns: ['success' => true, 'message' => '...']
```

**Database Updates:**
- Inserts record into `pre_order_payments` table
- Updates payment status in `pre_orders` table
- Triggers email notifications

---

## Database Schema

### `pre_orders` Table (Relevant Payment Fields)
```sql
CREATE TABLE `pre_orders` (
    -- ... other fields ...
    `downpayment_amount` DECIMAL(10,2),      -- 30% of total
    `remaining_amount` DECIMAL(10,2),        -- 70% of total
    `downpayment_status` ENUM('pending', 'paid', 'failed'),
    `final_payment_status` ENUM('pending', 'paid', 'failed'),
    `payment_type` ENUM('full_payment', 'downpayment'),
    -- ... timestamps ...
);
```

### `pre_order_payments` Table (Payment Tracking)
```sql
CREATE TABLE `pre_order_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pre_order_id` INT NOT NULL,
    `payment_type` ENUM('downpayment', 'final_payment'),
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_method` VARCHAR(50),
    `transaction_id` VARCHAR(255),           -- PayMongo session ID
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded'),
    `payment_gateway` ENUM('paymongo', 'bank_transfer', 'cash'),
    `paid_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`pre_order_id`) REFERENCES `pre_orders`(`id`)
);
```

---

## Payment Flow Scenarios

### Scenario 1: Full Payment with Card
```
1. Customer selects PayMongo → Credit Card
2. preorder_payment.php creates checkout session:
   - Amount: Total price (₱5,000)
   - Payment type: full
3. Customer redirected to PayMongo, completes payment
4. PayMongo redirects to preorder_payment_success.php
5. System verifies payment status = 'paid'
6. Updates pre_orders.final_payment_status = 'paid'
7. Updates pre_order_payments.payment_status = 'paid'
8. Sends confirmation email
```

### Scenario 2: Downpayment with GCash
```
1. Customer selects PayMongo → GCash
2. preorder_payment.php creates checkout session:
   - Amount: 30% of total (₱1,500)
   - Payment type: downpayment
3. Customer completes payment on PayMongo
4. System verifies payment status = 'paid'
5. Updates pre_orders.downpayment_status = 'paid'
6. Updates pre_order_payments.payment_status = 'paid'
7. Remaining 70% (₱3,500) marked as pending
8. Sends downpayment confirmation email
```

### Scenario 3: Payment Cancelled
```
1. Customer initiates PayMongo checkout
2. Customer closes/cancels payment before completion
3. PayMongo redirects to cancel_url
4. preorder_payment.php shows cancellation message
5. Pre-order remains in 'pending' status
6. Customer can retry payment from My Orders
```

### Scenario 4: Cash Payment (Store/Delivery)
```
1. Customer selects "Cash Payment"
2. preorder_payment.php records payment directly:
   - payment_method = 'cash'
   - payment_status = 'pending' (COD)
3. No PayMongo checkout session created
4. Redirects to success page with cash details
5. Admin receives notification for cash collection
```

---

## API Key Management

### Current Environment
- **Environment:** Development/Testing
- **Secret Key:** `sk_test_YOUR_PAYMONGO_SECRET_KEY_HERE`
- **Public Key:** `pk_test_YOUR_PAYMONGO_PUBLIC_KEY_HERE`

### Production Migration
```php
// When moving to production:
// 1. Generate new keys from PayMongo dashboard
// 2. Update in paymongo_integration.php:
$paymongo = new PayMongoIntegration(
    'sk_live_YOUR_LIVE_SECRET_KEY',
    'pk_live_YOUR_LIVE_PUBLIC_KEY'
);
// 3. Test with real payment (1-10 PHP)
// 4. Deploy to production
```

---

## Webhook Integration (Optional)

For automatic payment confirmation without page redirects:

```php
// File: preorder_payment_webhook.php
// PayMongo sends POST request when payment status changes

$payload = json_decode(file_get_contents('php://input'), true);

if ($payload['type'] === 'payment.paid') {
    $session_id = $payload['data']['attributes']['checkout_session_id'];
    $amount = $payload['data']['attributes']['amount'];
    
    // Verify signature
    // Update pre_order_payments
    // Update pre_orders
    // Send email
}
```

---

## Error Handling

### Common Issues

#### 1. "Failed to create payment session"
**Cause:** Invalid customer data or PayMongo API error  
**Solution:** 
- Verify user exists in database
- Check API keys are correct
- Review PayMongo API response in error logs

#### 2. "Payment verification failed"
**Cause:** Session ID not found or payment not completed  
**Solution:**
- Verify transaction_id saved in pre_order_payments
- Check PayMongo API response
- Allow user to retry payment

#### 3. "Database error: Unknown column"
**Cause:** pre_order_payments table not created  
**Solution:**
- Run database migration: `lechon_db.sql`
- Verify pre_order_payments table exists
- Check column names match queries

### Debugging

Enable error logging:
```php
// In paymongo_integration.php
error_log("PayMongo Response: " . json_encode($result));
```

Check logs:
```bash
# XAMPP error log location:
# Windows: C:\xampp\apache\logs\error.log
```

---

## Testing Checklist

### Test Cases

#### Test 1: Full Payment - Card
```
✓ Create pre-order with full payment option
✓ Select PayMongo payment method
✓ Verify checkout session created
✓ Complete payment in PayMongo sandbox
✓ Verify payment status updated to 'paid'
✓ Verify confirmation email sent
✓ Check My Orders shows paid status
```

#### Test 2: Downpayment - GCash
```
✓ Create pre-order with downpayment option
✓ Verify downpayment amount = 30% of total
✓ Select PayMongo → GCash
✓ Complete downpayment
✓ Verify downpayment_status = 'paid'
✓ Verify remaining_amount still pending
✓ Can record final payment later
```

#### Test 3: Payment Cancellation
```
✓ Create checkout session
✓ Cancel payment on PayMongo
✓ Verify redirect to cancel URL
✓ Pre-order still exists in pending
✓ Can retry payment from My Orders
```

#### Test 4: Cash Payment
```
✓ Create pre-order
✓ Select cash payment method
✓ Verify no PayMongo checkout
✓ Direct redirect to success page
✓ Payment status shows pending (COD)
✓ Admin notified for cash collection
```

---

## Security Considerations

### 1. API Key Protection
- Store API keys in environment variables (future enhancement)
- Never commit keys to version control
- Rotate keys regularly

### 2. Payment Verification
- Always verify payment with PayMongo API
- Never trust frontend payment confirmation
- Check transaction_id matches pre_order_payments

### 3. User Authorization
- Verify user owns the pre-order before processing payment
- Check user_id in pre_orders table matches session user
- Prevent unauthorized access to payment endpoints

### 4. Amount Verification
- Verify amount matches pre-order total
- Check downpayment percentage is correct
- Prevent amount tampering

```php
// Example verification
if ($preorder['user_id'] != $_SESSION['user_id']) {
    die('Unauthorized access');
}

$expected_amount = ($payment_type === 'downpayment') 
    ? $preorder['downpayment_amount']
    : $preorder['total_price'];

if ($checkout_data['amount'] != $expected_amount) {
    die('Amount mismatch');
}
```

---

## Performance Optimization

### 1. Payment Session Caching
```php
// Cache session ID to avoid multiple API calls
$_SESSION['preorder_checkout_session'] = [
    'pre_order_id' => $pre_order_id,
    'session_id' => $session_id,
    'created_at' => time(),
    'expires_at' => time() + 3600  // 1 hour
];
```

### 2. Database Indexing
```sql
-- Already in schema
ALTER TABLE `pre_order_payments` ADD INDEX `idx_pre_order_id` (`pre_order_id`);
ALTER TABLE `pre_order_payments` ADD INDEX `idx_payment_gateway` (`payment_gateway`);
```

### 3. API Rate Limiting
```php
// Implement rate limiting for checkout creation
$cache_key = "paymongo_session_{$pre_order_id}";
if ($cached = apcu_fetch($cache_key)) {
    return $cached;  // Return cached session
}
```

---

## Admin Dashboard Integration

### View Payment Status
**File:** `admin/preorders.php`  
**Query:**
```sql
SELECT 
    po.id,
    po.product_name,
    po.total_price,
    pop.payment_status,
    pop.payment_gateway,
    pop.paid_at
FROM pre_orders po
LEFT JOIN pre_order_payments pop ON po.id = pop.pre_order_id
ORDER BY po.created_at DESC
```

### Update Payment Status
**Endpoint:** `admin/preorders.php` (POST)  
**Action:** Mark payment as paid or failed manually

```php
$update = "UPDATE pre_order_payments 
           SET payment_status = ?, paid_at = NOW()
           WHERE id = ?";
```

---

## Future Enhancements

### 1. Payment Link via Email
```php
$paymongo->createPaymentLink(
    $preorder['downpayment_amount'],
    "Pre-Order Downpayment - Order #{$pre_order_id}",
    $pre_order_id
);
// Send payment link via email
```

### 2. Automated Reminders
Send email reminder for pending final payment:
```php
// Schedule daily task to send reminders
// for pre-orders with downpayment_status = 'paid'
// and final_payment_status = 'pending'
```

### 3. Multiple Currency Support
```php
// PayMongo supports: PHP, USD, JPY, SGD
$paymongo->createCheckoutSession([
    'currency' => 'USD',
    'amount' => 100  // USD
]);
```

### 4. Subscription Payments
For recurring pre-orders (weekly/monthly)

### 5. Refund Handling
```php
public function refundPayment($transaction_id, $amount) {
    // Implement refund logic
    // Update payment_status = 'refunded'
}
```

---

## Support & Documentation

### PayMongo Official Docs
- API Reference: https://developers.paymongo.com/docs/
- Sandbox Testing: https://developers.paymongo.com/docs/testing/
- Payment Methods: https://developers.paymongo.com/docs/payment-methods/

### Related Files
- [PREORDER_SYSTEM_GUIDE.md](PREORDER_SYSTEM_GUIDE.md) - Complete system overview
- [PREORDER_QUICK_START.md](PREORDER_QUICK_START.md) - Setup instructions
- [email_service.php](../email_service.php) - Email notifications

---

## Revision History

| Date | Version | Changes |
|------|---------|---------|
| 2026-01-23 | 1.0 | Initial PayMongo integration guide |

---

**Document Status:** Complete ✓  
**Last Verified:** January 23, 2026  
**Tested By:** System Integration Team
