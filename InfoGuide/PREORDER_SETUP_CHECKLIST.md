# PayMongo Pre-Order Integration - Setup Checklist

## Files Created
✅ process_preorder_payment.php - Backend payment handler
✅ payment_success_preorder.php - Success page after payment
✅ payment_cancel_preorder.php - Cancellation page if payment failed
✅ PREORDER_MIGRATION.sql - Database table creation script
✅ PAYMONGO_PREORDER_INTEGRATION.md - Detailed documentation

## Files Modified
✅ preorder.php - Updated form submission to integrate PayMongo

## Setup Steps

### 1. Create Database Table
```
Copy and paste the SQL from PREORDER_MIGRATION.sql into phpMyAdmin SQL tab
OR run: php -r "require 'includes/config.php'; exec(file_get_contents('PREORDER_MIGRATION.sql'));"
```

### 2. Configure PayMongo API Keys
Choose one method:

**Option A: Environment Variables (Recommended)**
```
Set in your web server:
- PAYMONGO_SECRET_KEY = your_secret_key
- PAYMONGO_PUBLIC_KEY = your_public_key
```

**Option B: Direct Configuration**
Edit process_preorder_payment.php line ~29:
```php
$paymongo_secret = 'pk_test_XXXXX';
$paymongo_public = 'pk_test_XXXXX';
```

### 3. Test the Integration

#### Test Full Payment:
1. Navigate to: http://localhost/lechonsystem/preorder.php
2. Login with valid credentials
3. Select a product
4. Choose quantity
5. Fill in delivery address (all fields required)
6. On Payment step: Select "Full Payment"
7. Click "Confirm Your Order" → "Submit Order"
8. Should redirect to PayMongo payment page

#### Test 30% Downpayment:
1. Repeat steps 1-5 above
2. On Payment step: Select "30% Downpayment"
3. Verify amount shown is 30% of total
4. Submit and verify PayMongo amount is correct

### 4. Verify Payment Success
After successful payment:
- Page redirects to payment_success_preorder.php
- Preorder status in database changes to 'paid'
- Shows order confirmation with details
- Shows "View My Orders" button

### 5. Verify Payment Cancellation
If payment is cancelled:
- Page redirects to payment_cancel_preorder.php
- Preorder status in database changes to 'cancelled'
- Shows cancellation message
- Option to try again

## Testing with PayMongo Sandbox

PayMongo Sandbox Test Cards:
- Visa: 4343434343434343 (Any expiry, Any CVV)
- Mastercard: 5555555555554444 (Any expiry, Any CVV)
- GCash (OTP): 09XXXXXXXXX (Any phone, Test OTP from email)

## Database Fields Stored

When a pre-order is created, the following is stored:
- User ID
- Product ID
- Quantity
- Full Name
- Email
- Phone
- Street Address
- Province
- City
- Barangay
- Payment Type (full or downpayment)
- Total Amount
- PayMongo Session ID
- Order Status

## Payment Flow

```
Customer Fills Form
    ↓
Step 1: Select Product & Quantity
    ↓
Step 2: Enter Delivery Address
    ↓
Step 3: Choose Payment Type
    ↓
Step 4: Confirm Order & Submit
    ↓
Frontend sends to process_preorder_payment.php
    ↓
Backend creates preorder record
    ↓
Backend creates PayMongo checkout session
    ↓
Redirect to PayMongo payment page
    ↓
Customer makes payment
    ↓
PayMongo redirects to success/cancel page
    ↓
Page updates preorder status
    ↓
Success: Status = 'paid'
Cancel: Status = 'cancelled'
```

## Troubleshooting

### Issue: "Database error: Table doesn't exist"
**Solution:** Run PREORDER_MIGRATION.sql in phpMyAdmin

### Issue: PayMongo returns "Unauthorized"
**Solution:** Check API keys are correct and properly set

### Issue: Payment page not loading
**Solution:** 
- Check browser console for errors (F12)
- Verify internet connection
- Check PayMongo API status

### Issue: After payment, redirects to wrong page
**Solution:** Check success_url and cancel_url in process_preorder_payment.php

## Next Steps for Production

1. ✅ Get live PayMongo API keys
2. ✅ Update API keys in process_preorder_payment.php
3. ✅ Test with live PayMongo environment
4. ✅ Set up payment success email notifications
5. ✅ Create admin dashboard for pre-order management
6. ✅ Set up PayMongo webhooks for real-time updates
7. ✅ Add order tracking for customers
8. ✅ Implement pre-order fulfillment workflow

## Support

For PayMongo API documentation: https://developers.paymongo.com
For issues with this integration: Check PAYMONGO_PREORDER_INTEGRATION.md
