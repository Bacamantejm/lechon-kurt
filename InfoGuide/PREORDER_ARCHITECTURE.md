# Pre-Order System - Architecture & Data Flow Diagram

## 📊 System Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         LECHON DELIGHTS                             │
│                      PRE-ORDER SYSTEM v1.0                          │
└─────────────────────────────────────────────────────────────────────┘

┌──────────────────┐              ┌──────────────────┐
│   CUSTOMERS      │              │    ADMINS        │
│   (Web Browser)  │              │  (Admin Panel)   │
└────────┬─────────┘              └────────┬─────────┘
         │                                  │
         │                                  │
         ├─→ preorder.php                   │
         │   (Create Order)                 │
         │                                  │
         ├─→ preorder_payment.php           ├─→ admin/preorders.php
         │   (Choose Payment Method)        │   (View All Pre-Orders)
         │                                  │
         ├─→ preorder_payment_success.php   ├─→ Status Updates
         │   (Confirm Payment)              │   (pending → confirmed)
         │                                  │
         └─→ preorder_details.php           └─→ Admin Notes
             (View/Cancel Order)


                 ┌──────────────────────────────────────┐
                 │      BACKEND SERVICES LAYER          │
                 ├──────────────────────────────────────┤
                 │  PreOrderService                     │
                 │  - createPreOrder()                  │
                 │  - getUserPreOrders()                │
                 │  - updatePreOrderStatus()            │
                 │  - processDownPayment()              │
                 │  - processFinalPayment()             │
                 │  - cancelPreOrder()                  │
                 │  - recordCashPayment()               │
                 │  - createNotification()              │
                 └──────────────────────────────────────┘
                                 │
                 ┌───────────────┴───────────────┐
                 │                               │
        ┌────────▼─────────┐          ┌────────▼─────────┐
        │  EmailService    │          │  PayMongoAPI     │
        ├──────────────────┤          ├──────────────────┤
        │ 5 Email Methods: │          │ Integration      │
        │ • Confirmation   │          │ - Checkout       │
        │ • Reminder       │          │ - Payment Verify │
        │ • Ready Notice   │          │ - Webhooks       │
        │ • Cancellation   │          │                  │
        │ • Completion     │          │                  │
        └──────────────────┘          └──────────────────┘
                 │                              │
                 └───────────────┬──────────────┘
                                 │
                 ┌───────────────▼──────────────┐
                 │     DATABASE LAYER (MySQL)   │
                 ├──────────────────────────────┤
                 │ pre_orders (28 cols)         │
                 │ pre_order_payments (8 cols)  │
                 │ pre_order_notifications      │
                 │                              │
                 │ FK: users                    │
                 │ FK: products                 │
                 └──────────────────────────────┘
```

## 🔄 Customer Pre-Order Workflow

```
START
  │
  ├─→ Customer logs in
  │
  ├─→ Clicks "Pre-Order Now" button
  │
  ├─→ preorder.php loads
  │   │
  │   ├─ Select Product (dropdown from products table)
  │   ├─ Enter Quantity
  │   ├─ Choose Delivery Method (Pickup/Delivery)
  │   ├─ Select Preferred Date & Time
  │   ├─ Choose Payment Type (Full/Downpayment)
  │   └─ Optional: Add Special Instructions
  │
  ├─→ Form Validation ✓
  │
  ├─→ PreOrderService::createPreOrder()
  │   │
  │   ├─ Calculate total_price = unit_price × quantity
  │   ├─ Calculate downpayment_amount = total × 30%
  │   ├─ Calculate remaining_amount = total × 70%
  │   ├─ Insert into pre_orders table
  │   ├─ Return: pre_order_id, total_price, amounts
  │   └─ Status: "pending"
  │
  ├─→ Redirect to preorder_payment.php
  │
  ├─→ Customer selects payment method:
  │   │
  │   ├─ Option A: PayMongo
  │   │   │
  │   │   ├─ PayMongoIntegration::createCheckoutSession()
  │   │   ├─ Redirect to PayMongo checkout page
  │   │   ├─ Customer pays online
  │   │   ├─ PayMongo webhook confirms payment
  │   │   ├─ Redirect to payment_success.php
  │   │   └─ Record payment in pre_order_payments table
  │   │
  │   └─ Option B: Cash
  │       │
  │       ├─ PreOrderService::recordCashPayment()
  │       ├─ Set payment_status = "paid"
  │       ├─ Redirect to payment_success.php
  │       └─ Mark as "paid" in system
  │
  ├─→ Payment Successful (or marked as cash)
  │
  ├─→ PreOrderService::createNotification()
  │   └─ Create notification record
  │
  ├─→ EmailService::sendPreOrderConfirmation()
  │   └─ Send confirmation email to customer
  │
  ├─→ Display preorder_payment_success.php
  │
  └─→ END (Pre-order created and confirmed)


LATER - Customer Views Pre-Order:
  │
  ├─→ Go to "My Orders" → "Pre-Orders" tab
  │
  ├─→ PreOrderService::getUserPreOrders()
  │   └─ Get all pre-orders for logged-in user
  │
  ├─→ Display preorder_tab_content.php
  │   │
  │   ├─ Show status badge (pending/confirmed/etc)
  │   ├─ Show payment status (downpayment pending/paid, final pending/paid)
  │   ├─ Show action buttons (Pay/View/Cancel)
  │   └─ Color-coded cards for visual status
  │
  ├─→ Click "View Details" → preorder_details.php
  │   │
  │   ├─ PreOrderService::getPreOrder()
  │   ├─ Display all order information
  │   ├─ Show payment breakdown
  │   ├─ Show admin notes (if any)
  │   └─ Display action buttons
  │
  └─→ END


PAYMENT SCENARIO - Downpayment:
  │
  ├─→ First payment: Downpayment (30%)
  │   │
  │   ├─ PreOrderService::processDownPayment()
  │   ├─ Set downpayment_status = "paid"
  │   ├─ Set downpayment_paid_at = NOW()
  │   ├─ Update pre_order_payments table
  │   ├─ Notify admin (order ready for preparation)
  │   └─ Status still "pending" until confirmed
  │
  ├─ Later - Admin confirms order
  │   └─ Update status to "confirmed"
  │
  ├─ Kitchen prepares order
  │   ├─ Admin updates status to "in_preparation"
  │   ├─ Admin updates status to "ready_for_pickup"
  │   └─ EmailService::sendPreOrderReadyNotification()
  │
  ├─→ Second payment: Final Amount (70%)
  │   │
  │   ├─ Customer pays remaining balance
  │   ├─ PreOrderService::processFinalPayment()
  │   ├─ Set final_payment_status = "paid"
  │   ├─ Set final_payment_paid_at = NOW()
  │   ├─ Update status to "completed"
  │   └─ EmailService::sendPreOrderCompletionConfirmation()
  │
  └─→ END


CANCELLATION FLOW:
  │
  ├─→ Customer clicks "Cancel Pre-Order"
  │
  ├─→ Prompt for cancellation reason
  │
  ├─→ PreOrderService::cancelPreOrder()
  │   │
  │   ├─ Set status = "cancelled"
  │   ├─ Set cancelled_at = NOW()
  │   ├─ Store cancellation_reason
  │   └─ Create notification record
  │
  ├─→ EmailService::sendPreOrderCancellationConfirmation()
  │   │
  │   └─ Email shows:
  │       ├─ Refund amount (downpayment if paid)
  │       ├─ Expected refund timeline (3-5 business days)
  │       └─ Contact info if issues
  │
  └─→ END (Order cancelled, refund processed)
```

## 🛡️ Admin Status Update Workflow

```
ADMIN DASHBOARD - admin/preorders.php
  │
  ├─→ View all pre-orders
  │   │
  │   └─ Filtered by status (default: "pending")
  │       or search by order ID / customer / product
  │
  ├─→ Click "Update Status" button
  │
  ├─→ Modal dialog opens:
  │   ├─ Current status shown (readonly)
  │   ├─ Dropdown with allowed next statuses:
  │   │  ├─ pending → confirmed
  │   │  ├─ confirmed → in_preparation
  │   │  ├─ in_preparation → ready_for_pickup
  │   │  ├─ ready_for_pickup → completed
  │   │  └─ ANY → cancelled
  │   ├─ Text area for admin notes
  │   └─ "Update Status" button
  │
  ├─→ Admin selects new status and adds note
  │   └─ Example: "Approved! Will prepare tomorrow morning"
  │
  ├─→ PreOrderService::updatePreOrderStatus()
  │   │
  │   ├─ Validate status transition
  │   ├─ Update reservation_status
  │   ├─ Update admin_notes
  │   ├─ Update updated_at timestamp
  │   └─ Return success message
  │
  ├─→ Email notifications sent (based on new status):
  │   │
  │   ├─ IF status = "ready_for_pickup"
  │   │   └─ EmailService::sendPreOrderReadyNotification()
  │   │
  │   ├─ IF status = "completed"
  │   │   └─ EmailService::sendPreOrderCompletionConfirmation()
  │   │
  │   └─ IF status = "cancelled"
  │       └─ EmailService::sendPreOrderCancellationConfirmation()
  │
  ├─→ Display success message
  │
  └─→ Dashboard refreshes with updated status
```

## 💳 Payment Flow Diagram

```
                    START: Customer at preorder_payment.php
                              │
                              ├─────────────────────────────────┐
                              │                                 │
                    ┌─────────▼──────────┐          ┌──────────▼────────┐
                    │  PAYMENT METHOD    │          │   PAYMENT METHOD   │
                    │   PAYPAL/CREDIT    │          │      CASH          │
                    │      CARD          │          │                    │
                    └────────┬───────────┘          └────────┬───────────┘
                             │                               │
        ┌────────────────────┴────────────────────┬──────────┴────────────┐
        │                                         │                       │
        ▼                                         ▼                       ▼
   PayMongo         PreOrderService::            Form
   Checkout         recordCashPayment()       Submission
    Page                 │
        │                │
   User Pays        ├─ Insert payment record
        │           │   into pre_order_payments
        │           │
        ▼           ├─ payment_type: downpayment/final_payment
   Webhook          │   amount: calculated amount
   Confirmation     │   payment_method: "cash"
        │           │   payment_status: "paid"
        │           │   payment_gateway: "cash"
        │           │
        │           ├─ Update pre_order table:
        │           │   - Set status field (downpayment_status/final_payment_status)
        │           │   - Set paid_at timestamp
        │           │
        ▼           └─ Return success
   Redirect to         │
   payment_success     │
   .php                │
        │              │
        └──────────┬───┘
                   │
                   ▼
    Create Notification Record
                   │
                   ▼
    Send Email (Type depends on payment):
    ├─ Confirmation (if first payment)
    ├─ Ready Notice (if final payment)
    └─ Thank You (if full payment)
                   │
                   ▼
    Display preorder_payment_success.php
    with order summary and next steps
                   │
                   ▼
                 END
```

## 🗄️ Database Relationship Diagram

```
┌──────────────────────────┐
│        users             │
├──────────────────────────┤
│ id (PK)                  │
│ email                    │
│ full_name                │
│ phone                    │
│ address                  │
└───┬──────────────────────┘
    │ (1:N)
    │ user_id
    │
    ├─────────────────────────┬─────────────────────────────┐
    │                         │                             │
    ▼                         ▼                             ▼
┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐
│   pre_orders (PK)    │ │ pre_order_payments   │ │ pre_order_notif...   │
├──────────────────────┤ ├──────────────────────┤ ├──────────────────────┤
│ id                   │ │ id                   │ │ id                   │
│ user_id (FK)         │ │ pre_order_id (FK)    │ │ pre_order_id (FK)    │
│ product_id (FK)      │ │ payment_type         │ │ user_id (FK)         │
│ product_name         │ │ amount               │ │ notification_type    │
│ quantity             │ │ payment_method       │ │ title                │
│ unit_price           │ │ transaction_id       │ │ message              │
│ total_price          │ │ payment_status       │ │ email_sent           │
│ preferred_pickup_date│ │ payment_gateway      │ │ sms_sent             │
│ preferred_pickup_time│ │ paid_at              │ │ sent_at              │
│ pickup_location      │ │ created_at           │ │ created_at           │
│ delivery_address     │ │ updated_at           │ │                      │
│ delivery_method      │ └──────────────────────┘ └──────────────────────┘
│ special_instructions │         ▲
│ payment_type         │         │ (1:N)
│ downpayment_amount   │         │ pre_order_id
│ remaining_amount     │         │
│ downpayment_status   │────────┘
│ final_payment_status │
│ downpayment_paid_at  │
│ final_payment_paid_at│
│ reservation_status   │
│ cancellation_reason  │
│ cancelled_at         │
│ notes                │
│ admin_notes          │
│ created_at           │
│ updated_at           │
└──────┬───────────────┘
       │ (N:1)
       │ product_id
       │
       ▼
┌──────────────────────┐
│    products          │
├──────────────────────┤
│ id (PK)              │
│ name                 │
│ category             │
│ price                │
│ image_path           │
│ is_active            │
└──────────────────────┘

Legend:
(PK) = Primary Key
(FK) = Foreign Key
(1:N) = One-to-Many relationship
(N:1) = Many-to-One relationship
```

## 📧 Email Notification Types

```
Pre-Order System Emails (5 Types)

1. CONFIRMATION EMAIL
   └─ Sent: Immediately after pre-order creation
   └─ Recipient: Customer email
   └─ Contains:
      ├─ Order ID and product name
      ├─ Quantity and total price
      ├─ Preferred pickup/delivery date & time
      ├─ Delivery method and address
      ├─ Payment type (full or downpayment)
      ├─ Amount breakdown
      └─ Link to view order

2. PAYMENT REMINDER EMAIL
   └─ Sent: When status = "pending" and payment not received (manual trigger)
   └─ Recipient: Customer email
   └─ Contains:
      ├─ Order ID
      ├─ Product name
      ├─ Amount due
      ├─ Payment link
      └─ Deadline to complete payment

3. READY FOR PICKUP/DELIVERY EMAIL
   └─ Sent: When status updated to "ready_for_pickup"
   └─ Recipient: Customer email
   └─ Contains:
      ├─ Order is ready notification
      ├─ Product name and quantity
      ├─ Pickup location or delivery address
      ├─ Time window for pickup/delivery
      └─ Link to view order

4. CANCELLATION CONFIRMATION EMAIL
   └─ Sent: When order cancelled (by customer or admin)
   └─ Recipient: Customer email
   └─ Contains:
      ├─ Cancellation confirmation
      ├─ Order ID and product name
      ├─ Cancellation reason (if provided)
      ├─ Refund amount and timeline
      └─ Contact info for questions

5. COMPLETION THANK YOU EMAIL
   └─ Sent: When status updated to "completed"
   └─ Recipient: Customer email
   └─ Contains:
      ├─ Thank you message
      ├─ Order summary (product, quantity, amount paid)
      ├─ Appreciation for business
      └─ Encouragement to order again
```

## 🔑 Key System Constants

```
Payment Types:
├─ full_payment: Pay 100% upfront
└─ downpayment: Pay 30% upfront, 70% later

Downpayment Percentage: 30% (configurable in createPreOrder)

Reservation Statuses:
├─ pending: Awaiting admin confirmation
├─ confirmed: Admin confirmed, ready for kitchen
├─ in_preparation: Kitchen is preparing
├─ ready_for_pickup: Ready for customer to collect
├─ completed: Order delivered/picked up
└─ cancelled: Order cancelled

Payment Status Values:
├─ pending: Awaiting payment
├─ paid: Payment received
├─ failed: Payment failed
└─ refunded: Refund processed

Payment Methods:
├─ credit_card: PayMongo credit card
├─ gcash: PayMongo GCash
├─ maya: PayMongo Maya
├─ online_banking: PayMongo online banking
├─ bank_transfer: Direct bank deposit (coming soon)
└─ cash: In-person cash payment

Payment Gateways:
├─ paymongo: Online payment via PayMongo API
├─ bank_transfer: Direct bank deposit
└─ cash: In-person cash

Notification Types:
├─ confirmation: Pre-order confirmation
├─ payment_reminder: Payment reminder
├─ ready_for_pickup: Ready for collection
├─ cancellation: Order cancelled
└─ completion: Order completed
```

---

## 📞 Architecture Notes

1. **Stateless Design**: Each page can be accessed directly if authenticated
2. **Service Layer**: Business logic separated in PreOrderService class
3. **Email Queue**: Emails sent synchronously (can be made async in future)
4. **Database Normalization**: 3NF design with proper relationships
5. **Security**: Prepared statements, user verification, input validation
6. **Scalability**: Indexes on frequently queried columns
7. **Error Handling**: Try-catch blocks with meaningful error messages
8. **Audit Trail**: All payment transactions logged with timestamps

---

*Last Updated: 2024*
*System Version: 1.0.0*
*Database Schema Version: 1.0*
