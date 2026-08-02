# PayMongo Pre-Order Integration - Flow Diagram

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     CUSTOMER BROWSER                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              preorder.php (4-Step Form)                  │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ Step 1: Product Selection                                │   │
│  │ Step 2: Delivery Address                                 │   │
│  │ Step 3: Payment Type (Full/Downpayment)                  │   │
│  │ Step 4: Confirmation → Submit Order                      │   │
│  └──────────────────────────────────────────────────────────┘   │
│                         │                                        │
│                         │ Form Submitted                         │
│                         ▼                                        │
│                   JavaScript Fetch                              │
│                   (JSON Data)                                   │
│                         │                                        │
└─────────────────────────┼────────────────────────────────────────┘
                          │
                          │ POST: process_preorder_payment.php
                          │
┌─────────────────────────▼────────────────────────────────────────┐
│                   BACKEND SERVER                                 │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │         process_preorder_payment.php                        │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 1. Validate form data                                      │ │
│  │ 2. Get product from database                               │ │
│  │ 3. Calculate amount (Full or 30% Down)                     │ │
│  │ 4. Create preorder in database                             │ │
│  │ 5. Initialize PayMongo class                               │ │
│  │ 6. Create checkout session                                 │ │
│  │ 7. Store session ID in database                            │ │
│  │ 8. Return checkout URL to frontend                         │ │
│  └────────────────────────────────────────────────────────────┘ │
│                         │                                        │
│                         │ Returns JSON:                          │
│                         │ {checkout_url, preorder_id}            │
│                         │                                        │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │         Database (lechon_db)                               │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │                                                            │ │
│  │  preorders table:                                          │ │
│  │  ├─ id, user_id, product_id                               │ │
│  │  ├─ full_name, email, phone                               │ │
│  │  ├─ street_address, province, city, barangay              │ │
│  │  ├─ payment_type, total_amount                            │ │
│  │  ├─ status (pending → paid/cancelled)                     │ │
│  │  └─ paymongo_session_id, paymongo_payment_id              │ │
│  │                                                            │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
                          │
                          │ JSON Response
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                     CUSTOMER BROWSER                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  JavaScript receives checkout_url                               │
│  Redirects: window.location.href = checkout_url                 │
│                         │                                        │
│                         ▼                                        │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │           PayMongo Payment Page                           │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ - Shows order amount (₱XXX or ₱XXX for 30% down)        │   │
│  │ - Payment methods (Card, GCash, PayMaya)                 │   │
│  │ - Customer enters payment details                        │   │
│  │ - Processes payment                                      │   │
│  └──────────────────────────────────────────────────────────┘   │
│                         │                                        │
│            ┌────────────┴────────────┐                           │
│            ▼                         ▼                           │
│       PAYMENT SUCCESS           PAYMENT FAILED                  │
│            │                         │                          │
│            │                         │                          │
└────────────┼─────────────────────────┼──────────────────────────┘
             │                         │
             │ Redirect to:            │ Redirect to:
             │ success_url             │ cancel_url
             │                         │
             ▼                         ▼
    payment_success_          payment_cancel_
    preorder.php              preorder.php
        │                         │
        │ Updates DB:             │ Updates DB:
        │ status = 'paid'         │ status = 'cancelled'
        │                         │
        │ Shows:                  │ Shows:
        │ ✓ Order Confirmed       │ ✗ Payment Cancelled
        │ ✓ Order Details         │ ✓ Try Again Option
        │ ✓ Confirmation #        │
        │                         │
        └─────────────┬───────────┘
                      │
                      ▼
             Customer sees result
```

## Payment Type Logic

```
FULL PAYMENT
─────────────────────
Product Price: ₱3500
Quantity: 2
═════════════════════
Total: ₱7000
Amount to Pay: ₱7000
Status: Full Payment


30% DOWNPAYMENT
─────────────────────
Product Price: ₱3500
Quantity: 2
═════════════════════
Subtotal: ₱7000
Down (30%): ₱2100
Remaining: ₱4900
Amount to Pay Now: ₱2100
Status: Downpayment Pending
```

## Database State Changes

```
STEP 1: Create Preorder
┌─────────────────────────────────────────┐
│ INSERT INTO preorders                   │
│ user_id: 1                              │
│ product_id: 5                           │
│ quantity: 2                             │
│ full_name: "John Doe"                   │
│ email: "john@example.com"               │
│ phone: "09123456789"                    │
│ street_address: "123 Main St"           │
│ province: "Metro Manila"                │
│ city: "Makati"                          │
│ barangay: "Bangkal"                     │
│ payment_type: "full"                    │
│ total_amount: 7000.00                   │
│ status: "pending"                       │
│ paymongo_session_id: NULL               │
│ created_at: NOW()                       │
└─────────────────────────────────────────┘
         ↓ Returns preorder_id = 1


STEP 2: Create PayMongo Session
┌─────────────────────────────────────────┐
│ UPDATE preorders SET                    │
│ paymongo_session_id = "sess_abc123"     │
│ WHERE id = 1                            │
│                                         │
│ PayMongo Response:                      │
│ {                                       │
│   "checkout_url": "https://checkout...  │
│   "session_id": "sess_abc123"           │
│ }                                       │
└─────────────────────────────────────────┘
         ↓ Redirect customer to checkout_url


STEP 3A: Payment Successful
┌─────────────────────────────────────────┐
│ UPDATE preorders SET                    │
│ status = "paid"                         │
│ paymongo_payment_id = "pay_xyz789"      │
│ updated_at = NOW()                      │
│ WHERE id = 1                            │
└─────────────────────────────────────────┘
         ↓ Show success page


STEP 3B: Payment Cancelled
┌─────────────────────────────────────────┐
│ UPDATE preorders SET                    │
│ status = "cancelled"                    │
│ updated_at = NOW()                      │
│ WHERE id = 1                            │
└─────────────────────────────────────────┘
         ↓ Show cancellation page
```

## File Dependencies

```
preorder.php
    │
    ├─ includes/config.php ────┐
    │                           ├─ MySQLi Connection
    │                           │
    ├─ includes/header.php      ├─ HTML Header/Navigation
    │                           │
    ├─ includes/footer.php      └─ HTML Footer
    │
    └─ (JavaScript Fetch)
            │
            ▼
    process_preorder_payment.php
            │
            ├─ includes/config.php ────┬─ Database Connection
            │                           │
            ├─ paymongo_integration.php ├─ PayMongo Class
            │                           │
            └─ Database Query           └─ Products Table Lookup
                    │
                    ├─ Creates: preorders record
                    │
                    └─ Calls: PayMongo API


payment_success_preorder.php
    │
    ├─ includes/config.php ────┬─ Database Connection
    │                           │
    ├─ includes/header.php      ├─ HTML Styling
    │                           │
    └─ includes/footer.php      └─ HTML Footer
            │
            └─ Updates: preorders status = 'paid'


payment_cancel_preorder.php
    │
    ├─ includes/config.php ────┬─ Database Connection
    │                           │
    ├─ includes/header.php      ├─ HTML Styling
    │                           │
    └─ includes/footer.php      └─ HTML Footer
            │
            └─ Updates: preorders status = 'cancelled'
```

## Data Flow Summary

```
INPUT:
  Customer Form Data
  ├─ Product ID
  ├─ Quantity
  ├─ Full Name
  ├─ Email
  ├─ Phone
  ├─ Street Address
  ├─ Province
  ├─ City
  ├─ Barangay
  └─ Payment Type

    ↓

PROCESSING:
  1. Validate all fields
  2. Fetch product from DB
  3. Calculate payment amount
  4. Create preorder record
  5. Initialize PayMongo
  6. Create checkout session
  7. Store session ID

    ↓

OUTPUT:
  Success:
    ├─ preorder_id
    ├─ checkout_url
    └─ Redirect customer to PayMongo

  Failure:
    └─ Error message returned to frontend

    ↓

PAYMENT PROCESSING:
  PayMongo handles all payment logic
  Customer completes payment on PayMongo page

    ↓

COMPLETION:
  Success:
    └─ Redirect to payment_success_preorder.php
       └─ Update status to 'paid'
       └─ Show confirmation

  Failure:
    └─ Redirect to payment_cancel_preorder.php
       └─ Update status to 'cancelled'
       └─ Show cancellation message
```

## Error Handling Flow

```
Error Occurs
    │
    ├─ Missing form field?
    │  └─ Return: "Missing required field: [field name]"
    │
    ├─ Product not found?
    │  └─ Return: "Product not found"
    │
    ├─ Database error?
    │  └─ Return: "Database error: [error message]"
    │
    ├─ PayMongo API error?
    │  │  └─ Delete preorder record
    │  └─ Return: PayMongo error message
    │
    └─ Frontend displays SweetAlert2 error
       └─ User can fix and retry
```
