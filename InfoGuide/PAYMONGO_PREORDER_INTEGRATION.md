# PayMongo Integration for Pre-Order System

## Overview
The pre-order system now integrates with PayMongo for secure payment processing. When a customer completes the pre-order form and reaches the confirmation step, they can proceed to payment with PayMongo.

## Files Created/Modified

### New Files:
1. **process_preorder_payment.php** - Backend handler for PayMongo payment creation
2. **payment_success_preorder.php** - Payment success confirmation page
3. **payment_cancel_preorder.php** - Payment cancellation handling page
4. **PREORDER_MIGRATION.sql** - Database migration for preorders table

### Modified Files:
1. **preorder.php** - Updated form submission to use PayMongo

## Setup Instructions

### Step 1: Create Preorders Table
Run the SQL migration in phpMyAdmin:

```sql
CREATE TABLE IF NOT EXISTS `preorders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `street_address` varchar(255) NOT NULL,
  `province` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `payment_type` enum('full','downpayment') DEFAULT 'full',
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','payment_pending','paid','completed','cancelled') DEFAULT 'pending',
  `paymongo_session_id` varchar(255) DEFAULT NULL,
  `paymongo_payment_id` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  KEY `status` (`status`),
  CONSTRAINT `preorders_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `preorders_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Step 2: Configure PayMongo API Keys
Set your PayMongo API keys as environment variables in your server configuration or directly in the PHP files.

Option A: Using Environment Variables (Recommended)
```php
$paymongo_secret = getenv('PAYMONGO_SECRET_KEY');
$paymongo_public = getenv('PAYMONGO_PUBLIC_KEY');
```

Option B: Direct Configuration
Edit `process_preorder_payment.php` and add your keys:
```php
$paymongo_secret = 'your_secret_key_here';
$paymongo_public = 'your_public_key_here';
```

### Step 3: Verify Payment Processing
The payment flow works as follows:

1. **Customer Completes Form** - On Step 4 (Confirmation)
2. **Submit Order** - Clicks "Submit Order" button
3. **Backend Processing** - `process_preorder_payment.php`:
   - Creates preorder record in database
   - Calculates payment amount (full or 30% downpayment)
   - Creates PayMongo checkout session
   - Returns checkout URL
4. **Redirect to PayMongo** - Customer redirected to payment page
5. **Payment Success** - `payment_success_preorder.php`:
   - Updates preorder status to 'paid'
   - Shows order confirmation
6. **Payment Cancelled** - `payment_cancel_preorder.php`:
   - Updates preorder status to 'cancelled'
   - Shows cancellation message

## Database Schema

### Preorders Table Columns:
- `id` - Unique preorder ID
- `user_id` - Customer user ID
- `product_id` - Product being pre-ordered
- `quantity` - Quantity ordered
- `full_name` - Delivery recipient name
- `email` - Customer email
- `phone` - Customer phone number
- `street_address` - Delivery address (street)
- `province` - Delivery province
- `city` - Delivery city
- `barangay` - Delivery barangay
- `payment_type` - 'full' or 'downpayment' (30%)
- `total_amount` - Order total in PHP
- `status` - 'pending', 'payment_pending', 'paid', 'completed', 'cancelled'
- `paymongo_session_id` - PayMongo checkout session ID
- `paymongo_payment_id` - PayMongo payment ID (after successful payment)
- `notes` - Any additional notes
- `created_at` - Order creation timestamp
- `updated_at` - Last update timestamp

## Payment Type Logic

### Full Payment:
- Amount = Product Price × Quantity
- Customer pays full amount immediately

### 30% Downpayment:
- Amount = (Product Price × Quantity) × 0.30
- Customer pays 30% upfront
- Remaining 70% due on delivery

## Frontend Flow (preorder.php)

The form submission now:
1. Validates all required fields
2. Shows "Processing Payment..." loading state
3. Sends form data to `process_preorder_payment.php`
4. Receives PayMongo checkout URL
5. Redirects to PayMongo payment page

## Error Handling

Common errors and solutions:

### Error: "Database error: Table doesn't exist"
- Solution: Run the PREORDER_MIGRATION.sql in phpMyAdmin

### Error: "PayMongo API Error"
- Check API keys are correct
- Verify PayMongo API endpoint is accessible
- Check network/firewall settings

### Error: "Payment processing failed"
- Check browser console for detailed error
- Verify all form fields are filled correctly
- Ensure user is logged in

## Testing

### Test Full Payment:
1. Go to `/lechonsystem/preorder.php`
2. Select a product, quantity
3. Fill in all address information
4. On Payment step, select "Full Payment"
5. Review and submit
6. Use PayMongo sandbox payment credentials

### Test Downpayment:
1. Repeat steps 1-3 above
2. On Payment step, select "30% Downpayment"
3. Submit and verify calculated amount is 30% of total

## Next Steps

To complete the integration, consider:

1. **Email Notifications**
   - Send order confirmation email to customer
   - Notify admin of new pre-orders
   - Send payment receipts

2. **Admin Dashboard**
   - Create pre-order management page
   - View all pre-orders and their status
   - Update pre-order status as they're fulfilled

3. **Customer Dashboard**
   - Show pre-order history in my_orders.php
   - Allow customers to track pre-order status
   - Option to modify/cancel pre-orders

4. **Webhook Handling**
   - Set up PayMongo webhooks for real-time payment updates
   - Auto-update order status when payment confirmed

5. **Additional Validations**
   - Phone number format validation
   - Email verification
   - Address availability check
   - Delivery date restrictions

## Security Notes

- All API keys stored securely (environment variables)
- HTTPS required for production
- Input validation on both frontend and backend
- SQL prepared statements used for all queries
- CSRF protection recommended
