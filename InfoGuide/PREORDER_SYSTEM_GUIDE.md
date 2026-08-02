# Pre-Order / Advance Reservation System Documentation

## Overview

The Pre-Order system allows customers to reserve products in advance (typically for special items like lechon) with flexible payment options - either full payment upfront or a 30% downpayment with the remaining balance due upon pickup/delivery.

## Features

✅ **Customer-Facing**
- Create pre-order reservations for any product
- Select quantity, preferred pickup date/time, and delivery method (pickup/delivery)
- Add special instructions (e.g., "no onions", "extra sauce")
- Choose payment type: Full Payment or Downpayment (30% now, 70% later)
- View pre-order history with status tracking
- Track payment status (downpayment pending/paid, final payment pending/paid)
- Cancel pre-orders with reason before confirmation
- Receive email notifications for important milestones

✅ **Admin-Facing**
- View all pre-orders with filtering and search capabilities
- Filter by status: Pending, Confirmed, In Preparation, Ready for Pickup, Completed, Cancelled
- Update pre-order status with admin notes
- Track payment status and amounts
- Send payment reminders
- Export pre-order data

✅ **Email Notifications**
- Pre-order confirmation with order summary
- Payment reminder for pending payments
- Ready for pickup/delivery notification
- Cancellation confirmation with refund information
- Order completion thank you email

## Database Schema

### Tables

**pre_orders** (Main reservation table)
```
- id: INT Primary Key
- user_id: INT (FK to users)
- product_id: INT (FK to products)
- product_name: VARCHAR (snapshot of product at time of order)
- quantity: INT
- unit_price: DECIMAL(10,2)
- total_price: DECIMAL(10,2) = unit_price * quantity
- reservation_date: TIMESTAMP
- preferred_pickup_date: DATE
- preferred_pickup_time: VARCHAR (e.g., "9:00 AM - 12:00 PM")
- pickup_location: VARCHAR (for pickup method)
- delivery_address: TEXT (for delivery method)
- delivery_method: ENUM('pickup', 'delivery')
- special_instructions: TEXT
- payment_type: ENUM('full_payment', 'downpayment')
- downpayment_amount: DECIMAL(10,2)
- remaining_amount: DECIMAL(10,2)
- downpayment_status: ENUM('pending', 'paid', 'failed')
- final_payment_status: ENUM('pending', 'paid', 'failed')
- downpayment_paid_at: TIMESTAMP NULL
- final_payment_paid_at: TIMESTAMP NULL
- reservation_status: ENUM('pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled')
- cancellation_reason: TEXT
- cancelled_at: TIMESTAMP NULL
- notes: TEXT (customer notes)
- admin_notes: TEXT (staff notes)
- created_at: TIMESTAMP
- updated_at: TIMESTAMP
- Indexes: idx_user_id, idx_status, idx_preferred_pickup_date
```

**pre_order_payments** (Payment transaction log)
```
- id: INT Primary Key
- pre_order_id: INT (FK to pre_orders)
- payment_type: ENUM('downpayment', 'final_payment')
- amount: DECIMAL(10,2)
- payment_method: VARCHAR (credit_card, gcash, maya, cash, bank_transfer)
- transaction_id: VARCHAR (unique transaction reference)
- payment_status: ENUM('pending', 'paid', 'failed', 'refunded')
- payment_gateway: ENUM('paymongo', 'bank_transfer', 'cash')
- paid_at: TIMESTAMP NULL
- created_at: TIMESTAMP
- updated_at: TIMESTAMP
- Index: idx_pre_order_id
```

**pre_order_notifications** (Email/SMS tracking)
```
- id: INT Primary Key
- pre_order_id: INT (FK to pre_orders)
- user_id: INT (FK to users)
- notification_type: ENUM('confirmation', 'payment_reminder', 'ready_for_pickup', 'cancellation', 'completion')
- title: VARCHAR
- message: TEXT
- email_sent: BOOLEAN
- sms_sent: BOOLEAN
- sent_at: TIMESTAMP NULL
- created_at: TIMESTAMP
- Index: idx_pre_order_id
```

## Installation

### 1. Import Database Migration
```bash
# Run the PREORDER_MIGRATION.sql file in phpMyAdmin
# This creates all 3 tables with proper relationships and indexes
```

### 2. Required Files

All files are pre-created:

**Customer-Facing Files:**
- `preorder.php` - Pre-order creation form
- `preorder_payment.php` - Payment method selection
- `preorder_payment_success.php` - Payment confirmation
- `preorder_details.php` - View single pre-order details
- `preorder_tab_content.php` - Tab for my_orders.php page

**Admin Files:**
- `admin/preorders.php` - Pre-order management dashboard

**Backend Services:**
- `preorder_service.php` - Core business logic
- `email_service.php` - Email notifications (updated with pre-order methods)

### 3. Add Navigation Links

Add to your navigation menu:
```html
<a href="preorder.php" class="nav-item">
    <i class="fas fa-calendar-check"></i> Pre-Order Now
</a>
```

## Usage Guide

### For Customers

#### Creating a Pre-Order
1. Navigate to `preorder.php`
2. Select a product from dropdown
3. Enter quantity
4. Choose delivery method (Pickup/Delivery)
5. Select preferred pickup date and time
6. Add special instructions (optional)
7. Choose payment type:
   - **Full Payment**: Pay everything now
   - **Downpayment**: Pay 30% now, 70% on pickup/delivery
8. Review total amount
9. Click "Confirm Pre-Order"
10. Proceed to payment

#### Payment Methods
- **PayMongo**: Credit Card, GCash, Maya, Online Banking
- **Cash**: Pay in full or downpayment at store, remaining upon pickup
- **Bank Transfer**: Coming soon

#### Viewing Pre-Orders
1. Go to "My Orders" → "Pre-Orders" tab
2. See all pre-orders with status
3. Click "View Details" for full information
4. Click "Pay Now" to complete pending payments
5. Click "Cancel" to cancel pending pre-orders

### For Admins

#### Managing Pre-Orders
1. Navigate to Admin → Pre-Orders
2. View all pre-orders with filtering options:
   - Search by Order ID, Product Name, or Customer Name
   - Filter by Status (All, Pending, Confirmed, In Preparation, Ready, Completed, Cancelled)
3. Click "View Details" to see full order
4. Click "Update Status" to change pre-order status and add admin notes

#### Status Workflow
```
pending → confirmed → in_preparation → ready_for_pickup → completed
                ↘ cancelled (can happen at any stage)
```

## API Reference

### PreOrderService Class

#### `createPreOrder()`
```php
$result = $preorder_service->createPreOrder(
    $user_id,           // INT
    $product_id,        // INT
    $product_name,      // STRING
    $quantity,          // INT
    $unit_price,        // FLOAT
    $pickup_date,       // DATE (YYYY-MM-DD)
    $pickup_time,       // STRING
    $pickup_location,   // STRING (for pickup method)
    $delivery_method,   // 'pickup' or 'delivery'
    $payment_type,      // 'full_payment' or 'downpayment'
    $downpayment_percentage,  // INT (default 30)
    $special_instructions,    // STRING
    $delivery_address   // STRING (for delivery method)
);

// Returns:
[
    'success' => true/false,
    'pre_order_id' => INT,
    'total_price' => FLOAT,
    'downpayment_amount' => FLOAT,
    'remaining_amount' => FLOAT,
    'message' => STRING
]
```

#### `getPreOrder()`
```php
$preorder = $preorder_service->getPreOrder($pre_order_id);

// Returns: Full pre-order record as associative array
```

#### `getUserPreOrders()`
```php
$preorders = $preorder_service->getUserPreOrders($user_id);

// Returns: Array of pre-orders for user ordered by date DESC
```

#### `updatePreOrderStatus()`
```php
$result = $preorder_service->updatePreOrderStatus(
    $pre_order_id,      // INT
    $new_status,        // String: pending, confirmed, in_preparation, ready_for_pickup, completed, cancelled
    $admin_notes        // STRING (optional)
);

// Returns: ['success' => true/false, 'message' => STRING]
```

#### `processDownPayment()`
```php
$result = $preorder_service->processDownPayment(
    $pre_order_id,      // INT
    $transaction_id,    // STRING (from PayMongo or payment gateway)
    $payment_gateway    // STRING: paymongo, cash, bank_transfer
);

// Records downpayment and updates status
// Returns: ['success' => true/false, 'remaining_amount' => FLOAT]
```

#### `processFinalPayment()`
```php
$result = $preorder_service->processFinalPayment(
    $pre_order_id,      // INT
    $transaction_id,    // STRING
    $payment_gateway    // STRING
);

// Records final payment and completes order
// Returns: ['success' => true/false]
```

#### `cancelPreOrder()`
```php
$result = $preorder_service->cancelPreOrder(
    $pre_order_id,      // INT
    $cancellation_reason // STRING
);

// Marks as cancelled with reason and timestamp
// Returns: ['success' => true/false]
```

#### `recordCashPayment()`
```php
$result = $preorder_service->recordCashPayment(
    $pre_order_id,      // INT
    $payment_type,      // STRING: downpayment or final_payment
    $amount,            // FLOAT
    $payment_method     // STRING: cash
);

// Records cash payment without requiring payment gateway
// Returns: ['success' => true/false, 'transaction_id' => STRING]
```

#### `createNotification()`
```php
$preorder_service->createNotification(
    $pre_order_id,      // INT
    $user_id,           // INT
    $notification_type, // String: confirmation, payment_reminder, ready_for_pickup, cancellation, completion
    $title,             // STRING
    $message            // STRING
);

// Creates notification record for tracking
```

### EmailService Class - Pre-Order Methods

#### `sendPreOrderConfirmation()`
```php
$email_service->sendPreOrderConfirmation($email, [
    'pre_order_id' => INT,
    'product_name' => STRING,
    'quantity' => INT,
    'total_price' => FLOAT,
    'downpayment_amount' => FLOAT,
    'remaining_amount' => FLOAT,
    'pickup_date' => DATE,
    'payment_type' => STRING
]);
```

#### `sendPreOrderPaymentReminder()`
```php
$email_service->sendPreOrderPaymentReminder($email, [
    'pre_order_id' => INT,
    'product_name' => STRING,
    'amount_due' => FLOAT
]);
```

#### `sendPreOrderReadyNotification()`
```php
$email_service->sendPreOrderReadyNotification($email, [
    'pre_order_id' => INT,
    'product_name' => STRING,
    'delivery_method' => STRING,
    'delivery_address' => STRING,
    'pickup_location' => STRING,
    'preferred_pickup_time' => STRING
]);
```

#### `sendPreOrderCancellationConfirmation()`
```php
$email_service->sendPreOrderCancellationConfirmation($email, [
    'pre_order_id' => INT,
    'product_name' => STRING,
    'cancellation_reason' => STRING,
    'refund_amount' => FLOAT
]);
```

#### `sendPreOrderCompletionConfirmation()`
```php
$email_service->sendPreOrderCompletionConfirmation($email, [
    'pre_order_id' => INT,
    'product_name' => STRING,
    'quantity' => INT,
    'total_price' => FLOAT
]);
```

## Configuration

### Payment Settings

**In `preorder_payment.php`:**
- Downpayment percentage: Default 30% (adjust in form or in `preorder_service.php::createPreOrder()`)
- Payment methods available: PayMongo, Cash
- Coming soon: Bank Transfer

**In `paymongo_integration.php`:**
- Ensure PayMongo API keys are configured
- Test mode vs Live mode settings

### Email Configuration

**In `email_service.php`:**
```php
$this->mail->Username = 'your-email@gmail.com';
$this->mail->Password = 'your-app-password'; // Use app-specific password, not regular password
```

### Allowed Products

Currently, all active products (where `is_active = 1`) are available for pre-order. To restrict certain products:

```php
// In preorder.php, modify the product query:
$products_query = "SELECT * FROM products 
                   WHERE is_active = 1 
                   AND category IN ('lechon', 'roasted_meats')  // Add restriction
                   ORDER BY category, name";
```

## Security Considerations

✅ **Implemented:**
- Prepared statements for all database queries
- User ownership verification (user_id check)
- Session-based authentication
- Input validation and sanitization
- Email verification through session
- Transaction ID generation for payment tracking

### Best Practices

1. **NEVER** display sensitive payment information in URLs
2. **ALWAYS** verify user ownership before showing pre-order details
3. **ALWAYS** use prepared statements for database queries
4. **ALWAYS** validate and sanitize user input
5. **ALWAYS** log payment transactions in `pre_order_payments` table
6. **ALWAYS** send confirmation emails to verify customer contact
7. **NEVER** store full payment information (use payment gateway)

## Error Handling

### Common Errors and Solutions

**"Pre-order not found"**
- User is trying to view someone else's pre-order
- Check user_id matches session user_id

**"Payment session creation failed"**
- PayMongo API keys not configured
- Check `paymongo_integration.php` configuration
- Verify API endpoint accessibility

**"Email not sending"**
- Gmail app-specific password incorrect
- Check `email_service.php` configuration
- Enable "Less secure apps" or use app password

**"Invalid pickup date"**
- Pickup date is in the past
- System requires minimum 1 day advance notice
- Check date validation in `preorder.php`

## Testing

### Test Cases

1. **Create Pre-Order - Full Payment**
   - Select product, enter quantity
   - Choose pickup method, date, time
   - Select "Full Payment"
   - Submit and verify notification email

2. **Create Pre-Order - Downpayment**
   - Select product, enter quantity
   - Choose delivery method, enter address
   - Select "Downpayment"
   - Submit, verify email, pay downpayment
   - Verify remaining amount due

3. **Cancel Pre-Order**
   - Create pre-order
   - Cancel before confirmation
   - Verify cancellation email

4. **Admin Status Update**
   - View pre-order in admin panel
   - Update status to "In Preparation"
   - Add admin notes
   - Verify status change in database

5. **Payment Processing**
   - Test with PayMongo sandbox credentials
   - Test cash payment recording
   - Verify payment_status updates

### Test Credentials

**Admin Account:**
- Email: admin@lechondelights.com
- Password: 123

**Test Payment (PayMongo):**
- Use PayMongo sandbox environment
- Card: 4343 4343 4343 4343
- Any future date, any CVV

## Troubleshooting

### Database Issues
```bash
# Check if tables exist:
SELECT COUNT(*) FROM pre_orders;
SELECT COUNT(*) FROM pre_order_payments;
SELECT COUNT(*) FROM pre_order_notifications;

# Reset database (WARNING: Deletes all pre-orders):
DROP TABLE pre_order_notifications;
DROP TABLE pre_order_payments;
DROP TABLE pre_orders;
# Then re-run PREORDER_MIGRATION.sql
```

### Payment Issues
```php
// Debug PayMongo integration:
// Add to paymongo_integration.php:
error_log('PayMongo Request: ' . json_encode($data));
error_log('PayMongo Response: ' . $response);
```

### Email Issues
```php
// Test email configuration:
$email_service = new EmailService($conn);
$result = $email_service->sendPreOrderConfirmation('test@example.com', [...]);
echo $result ? 'Email sent' : 'Email failed';
```

## Future Enhancements

- [ ] SMS notifications for pre-order updates
- [ ] Recurring pre-orders (weekly/monthly)
- [ ] Inventory reservation system
- [ ] Pre-order calendar showing available dates
- [ ] Batch pre-order management for corporate/catering
- [ ] Pre-order analytics and forecasting
- [ ] Integration with kitchen display system (KDS)
- [ ] Walkin customers can place pre-orders on tablet kiosk
- [ ] Bank transfer payment option
- [ ] WhatsApp notifications

## Support & Maintenance

For issues or questions:
1. Check this documentation
2. Review database schema in PREORDER_MIGRATION.sql
3. Check application logs in `error_log`
4. Contact development team

## Changelog

### Version 1.0.0 (Initial Release)
- Full pre-order system with customer-facing pages
- Admin pre-order management dashboard
- Email notification system (5 email types)
- Payment integration with PayMongo
- Cash payment option
- Downpayment/Full payment support
- Pre-order cancellation with refunds
- Status tracking and admin notes
