# Pre-Order System - Quick Setup & Testing Guide

## Setup Steps (5 minutes)

### Step 1: Import Database Schema
1. Open phpMyAdmin
2. Select `lechon_db` database
3. Click "Import" tab
4. Upload `PREORDER_MIGRATION.sql`
5. Click "Import"
6. Verify 3 tables created: `pre_orders`, `pre_order_payments`, `pre_order_notifications`

### Step 2: Verify Files Exist
All files should be in place:
```
✓ Root Level:
  - preorder.php (customer form)
  - preorder_payment.php (payment selection)
  - preorder_payment_success.php (confirmation)
  - preorder_details.php (order details)
  - preorder_service.php (backend logic)
  - preorder_tab_content.php (for my_orders.php)
  - PREORDER_SYSTEM_GUIDE.md (documentation)
  - PREORDER_MIGRATION.sql (database)

✓ Admin Folder:
  - admin/preorders.php (admin dashboard)

✓ Updated:
  - email_service.php (5 new email methods)
  - admin/sidebar.php (Pre-Orders menu item)
```

### Step 3: Update Navigation
Edit your header/navigation menu to include:
```html
<li><a href="preorder.php"><i class="fas fa-calendar-check"></i> Pre-Order Now</a></li>
```

### Step 4: Verify Email Configuration
Check `email_service.php` has correct Gmail credentials:
```php
$this->mail->Username = 'your-email@gmail.com';
$this->mail->Password = 'your-app-password'; // NOT regular Gmail password
```

### Step 5: Test Payment Integration
Ensure `paymongo_integration.php` has valid API keys:
```php
const API_KEY = 'your-paymongo-key';
const API_URL = 'https://api.paymongo.com/v1/checkout_sessions';
```

## Quick Testing (15 minutes)

### Test 1: Create Pre-Order (Full Payment)
**Steps:**
1. Login as regular user (or register new account)
2. Go to `preorder.php`
3. Select any product, quantity 2
4. Choose "Pickup" method, select any location
5. Pick tomorrow's date, "9:00 AM - 12:00 PM" time
6. Select "Full Payment"
7. Click "Confirm Pre-Order"

**Expected Results:**
- ✓ Redirect to payment page
- ✓ See order summary
- ✓ PayMongo option available
- ✓ Cash option available
- ✓ Email sent to your email with confirmation
- ✓ Pre-order appears in admin dashboard

### Test 2: Create Pre-Order (Downpayment)
**Steps:**
1. Login (same or different user)
2. Go to `preorder.php`
3. Select product, quantity 1
4. Choose "Delivery" method, enter address
5. Pick next week's date, "6:00 PM - 9:00 PM" time
6. Select "Downpayment (30%)"
7. Click "Confirm Pre-Order"
8. Choose "Cash Payment" on payment page

**Expected Results:**
- ✓ Order created with status "pending"
- ✓ Downpayment amount = 30% of total
- ✓ Remaining amount = 70% of total
- ✓ Confirmation email shows both amounts
- ✓ Payment recorded as "paid" (cash)
- ✓ Downpayment status updates to "paid"
- ✓ Remaining payment still "pending"

### Test 3: View Pre-Order
**Steps:**
1. Go to "My Orders" menu
2. Click "Pre-Orders" tab
3. See list of your pre-orders
4. Click "View Details" on any pre-order

**Expected Results:**
- ✓ Shows all pre-order information
- ✓ Displays payment summary
- ✓ Shows status with color-coded badge
- ✓ "Complete Payment" button if payment pending
- ✓ "Cancel Pre-Order" button if not completed

### Test 4: Cancel Pre-Order
**Steps:**
1. From pre-order details page
2. Click "Cancel Pre-Order" button
3. Enter cancellation reason
4. Confirm

**Expected Results:**
- ✓ Status changes to "cancelled"
- ✓ Cancellation email sent
- ✓ Refund amount shown in email
- ✓ Pre-order marked as cancelled in list

### Test 5: Admin Dashboard
**Steps:**
1. Login as admin
2. Click "Admin Dashboard"
3. Click "Pre-Orders" in sidebar
4. View list of pre-orders

**Expected Results:**
- ✓ See all pre-orders from all users
- ✓ Filter by status (pending, confirmed, etc.)
- ✓ Search by order ID, product name, customer name
- ✓ See payment status (downpayment paid/pending, final paid/pending)
- ✓ "View Details" and "Update Status" buttons

### Test 6: Admin Update Status
**Steps:**
1. In admin pre-orders page
2. Click "Update Status" button on any pre-order
3. Select new status: "Confirmed"
4. Add admin note: "Approved, will prepare tomorrow"
5. Click "Update Status"

**Expected Results:**
- ✓ Status updates in database
- ✓ Admin notes saved
- ✓ Success message shown
- ✓ Pre-order card refreshes with new status

### Test 7: Email Notifications
**Steps:**
1. Create a pre-order
2. Check your email inbox (check spam folder)
3. Verify email contains:
   - Order ID and product name
   - Quantity and prices
   - Pickup/delivery details
   - Payment type and amounts
   - Link to view order

**Expected Results:**
- ✓ Professional email template
- ✓ All key information displayed
- ✓ Links functional
- ✓ Delivered within 30 seconds

## Database Verification

### Check Tables Exist
```sql
-- In phpMyAdmin, run these queries:

-- Check pre_orders table
SELECT * FROM pre_orders LIMIT 1;

-- Check pre_order_payments table
SELECT * FROM pre_order_payments LIMIT 1;

-- Check pre_order_notifications table
SELECT * FROM pre_order_notifications LIMIT 1;

-- Count records
SELECT 
  'pre_orders' as table_name, COUNT(*) as record_count FROM pre_orders
UNION
SELECT 'pre_order_payments', COUNT(*) FROM pre_order_payments
UNION
SELECT 'pre_order_notifications', COUNT(*) FROM pre_order_notifications;
```

### Query Sample Data
```sql
-- View a pre-order with all details
SELECT 
  po.id,
  po.product_name,
  po.quantity,
  po.total_price,
  po.downpayment_amount,
  po.remaining_amount,
  po.reservation_status,
  po.downpayment_status,
  po.final_payment_status,
  u.full_name,
  u.email
FROM pre_orders po
JOIN users u ON po.user_id = u.id
ORDER BY po.created_at DESC
LIMIT 5;

-- View payments for a pre-order
SELECT * FROM pre_order_payments 
WHERE pre_order_id = 1 
ORDER BY created_at DESC;

-- View notifications for a pre-order
SELECT * FROM pre_order_notifications 
WHERE pre_order_id = 1 
ORDER BY created_at DESC;
```

## Troubleshooting Quick Fixes

### "Database error" on preorder.php
**Solution:**
- Check PREORDER_MIGRATION.sql was imported
- Verify database connection in `includes/config.php`
- Check error logs in browser console (F12)

### "Payment page not loading"
**Solution:**
- Verify `preorder_service.php` file exists in root
- Check `preorder_service.php` has no syntax errors
- Verify `paymongo_integration.php` exists

### "Email not received"
**Solution:**
- Check spam/promotions folder
- Verify email configuration in `email_service.php`
- Use Gmail app-specific password, not regular password
- Enable "Less secure apps" in Gmail settings

### "Pre-order not showing in admin"
**Solution:**
- Verify admin user has `user_type = 'admin'` in database
- Check `admin/auth.php` `checkAdminAccess()` function
- Ensure `admin/sidebar.php` was updated with Pre-Orders link

### "Can't cancel pre-order"
**Solution:**
- Check pre-order status is not already "completed" or "cancelled"
- Verify user_id matches session
- Check `preorder_details.php` button conditions

## Performance Tips

### For High Volume Pre-Orders
1. Add database indexes (already in migration):
   ```sql
   CREATE INDEX idx_pre_order_user_id ON pre_orders(user_id);
   CREATE INDEX idx_pre_order_status ON pre_orders(reservation_status);
   CREATE INDEX idx_pre_order_date ON pre_orders(preferred_pickup_date);
   ```

2. Archive old completed pre-orders:
   ```sql
   -- Archive completed pre-orders older than 6 months
   INSERT INTO pre_orders_archive 
   SELECT * FROM pre_orders 
   WHERE reservation_status = 'completed' 
   AND updated_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
   
   DELETE FROM pre_orders 
   WHERE id IN (SELECT id FROM pre_orders_archive);
   ```

### Optimize Email Sending
- Consider queuing emails to send asynchronously
- Batch send payment reminders

## Deployment Checklist

Before going live:

- [ ] PREORDER_MIGRATION.sql imported into live database
- [ ] Email credentials updated for production Gmail
- [ ] PayMongo API keys set to LIVE (not sandbox)
- [ ] All files uploaded to production server
- [ ] Admin sidebar updated with Pre-Orders link
- [ ] Navigation menu includes "Pre-Order Now" link
- [ ] HTTPS enabled (required for PayMongo)
- [ ] Database backups configured
- [ ] Email forwarding set up for order notifications
- [ ] Terms & conditions added to preorder.php
- [ ] Test transaction completed
- [ ] Admin trained on pre-order management
- [ ] Customers notified about pre-order feature

## Success Indicators

✅ System is working correctly if:
1. Can create pre-orders on preorder.php
2. Payment page displays correctly
3. Can select payment method
4. Confirmation emails sent and received
5. Pre-orders appear in customer "My Orders" → "Pre-Orders"
6. Pre-orders appear in admin dashboard
7. Admin can update pre-order status
8. Cancellation works with email notification
9. Payment tracking accurate in database
10. No console errors (F12 → Console tab)

## Support

If you encounter any issues:
1. Check browser console (F12) for JavaScript errors
2. Check server error logs for PHP errors
3. Verify database tables exist with correct schema
4. Test with Firefox/Chrome in incognito mode
5. Check file permissions (755 for PHP files)
6. Verify all required files exist in correct locations

## Next Steps

After successful testing:
1. Train staff on admin pre-order management
2. Create FAQ for customers about pre-orders
3. Set up email reminders for ready-for-pickup orders
4. Monitor pre-order patterns for inventory planning
5. Consider offering pre-order discounts

---

**Happy Pre-Ordering! 🎉**

For detailed technical documentation, see: PREORDER_SYSTEM_GUIDE.md
