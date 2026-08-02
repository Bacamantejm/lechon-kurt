# Step 2 Form - Visual Reference & Structure Map

## Form Layout Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    PRE-ORDER WIZARD - STEP 2                    │
│                  Delivery Information                             │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  DELIVERY ADDRESS                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Label: Address Street Name, Building, House No.                │
│  Input: [________________________________________] (Text field) │
│  Help:  e.g., 123 Main Street, Unit 4B                          │
│                                                                   │
│  ┌─────────────────────────────────┬──────────────────────────┐ │
│  │ Province *                      │ City / Municipalities *  │ │
│  │ [▼ Choose a province    ]       │ [▼ Choose a City    ]    │ │
│  └─────────────────────────────────┴──────────────────────────┘ │
│                                                                   │
│  Barangay                                                         │
│  [▼ Select a Barangay    ]                                        │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  CONTACT INFORMATION                                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Name *                                                           │
│  [________________________________] (Text field)                │
│  Help: Full name of recipient                                    │
│                                                                   │
│  ┌─────────────────────────────────┬──────────────────────────┐ │
│  │ Mobile Number *                 │ Email *                  │ │
│  │ [09171234567________]           │ [you@example.com     ]   │ │
│  │ Format: 09xxxxxxxxx             │                          │ │
│  └─────────────────────────────────┴──────────────────────────┘ │
│                                                                   │
│  Agent Code (if applicable)                                       │
│  [________________________________] (Text field, optional)      │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  DELIVERY SCHEDULE                                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────────┬──────────────────────────┐ │
│  │ Select Date *                   │ Select Time *            │ │
│  │ [▼ YYYY-MM-DD      ]            │ [▼ 7:00 AM ▼ 9:00 PM]   │ │
│  │ (min: today)                    │ (15 time slots)          │ │
│  └─────────────────────────────────┴──────────────────────────┘ │
│                                                                   │
│  ℹ️  Note: To request earlier delivery times, please contact     │
│      our hotline: 1-800-LECHON-1                                 │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  [◀ BACK]                                 [NEXT ▶]               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Interactive Flow Diagram

```
START
  ↓
┌─────────────────────────┐
│ User Opens Pre-order    │
│ (Step 1 Product Select) │
└────────────┬────────────┘
             ↓
      ┌──────────────┐
      │ Select Product│
      └────────┬─────┘
               ↓
        ┌─────────────────────────────────────────────┐
        │ Step 2: Delivery Information Form           │
        │                                             │
        │ 1. Enter street address                      │
        │ 2. Select Province → City → Barangay        │
        │ 3. Enter recipient name                      │
        │ 4. Enter mobile (09XXXXXXXXX)               │
        │ 5. Enter email                              │
        │ 6. (Optional) Enter agent code              │
        │ 7. Select delivery date (today+)            │
        │ 8. Select delivery time (7am-9pm)           │
        └────────────┬────────────────────────────────┘
                     ↓
          ┌──────────────────────┐
          │ Client-Side Validation│
          │ (JavaScript)         │
          └────────┬─────────────┘
                   ↓
          ┌─ Is Valid? ─────┐
          │                 │
       NO│                 │YES
        ↓                  ↓
    ┌────────┐      ┌──────────────────┐
    │ Show   │      │ Submit Form to   │
    │ Error  │      │ Backend (PHP)    │
    └───┬────┘      └────────┬─────────┘
        │                    ↓
        │           ┌────────────────────────┐
        │           │ Server-Side Validation │
        │           │ (PHP)                  │
        │           └────────┬───────────────┘
        │                    ↓
        │           ┌─ Is Valid? ─────────┐
        │           │                     │
        │        NO│                     │YES
        │         ↓                      ↓
        │    ┌─────────┐        ┌──────────────────┐
        │    │ Return  │        │ Insert into      │
        │    │ Errors  │        │ pre_orders table │
        │    └────┬────┘        └────────┬─────────┘
        │         │                      ↓
        │         │             ┌──────────────────┐
        │         │             │ Store in Session │
        │         │             └────────┬─────────┘
        │         │                      ↓
        └─────────┼──────────────┬───────────────┐
                  │              ↓               │
                  │        ┌──────────────┐      │
                  │        │ Redirect to  │      │
                  │        │ Step 3       │      │
                  │        │ (Payment)    │      │
                  │        └──────┬───────┘      │
                  │               ↓              │
                  │        ┌──────────────┐      │
                  │        │ Continue     │      │
                  │        │ Pre-order    │      │
                  │        │ Process      │      │
                  │        └──────────────┘      │
                  │                             │
                  └─────────────────────────────┘
```

---

## Form Field Dependencies

```
Province Selection
  ├─ Changes: City dropdown (populates cities)
  │            Barangay dropdown (clears)
  └─ Updates: validateStep() availability

City Selection
  ├─ Changes: Barangay dropdown (populates barangays)
  ├─ Requires: Province to be selected
  └─ Updates: validateStep() availability

Barangay Selection (Optional)
  ├─ No dependencies
  └─ Informational only

Delivery Date Selection
  ├─ Constraint: Minimum = Today
  ├─ Constraint: Maximum = Any future date
  └─ Affects: Time slot availability (future enhancement)

Delivery Time Selection
  ├─ Requires: Delivery date to be selected
  ├─ Options: 15 hourly slots (7 AM - 9 PM)
  └─ Affects: Availability (future enhancement)

Validation Dependencies
  ├─ Step 2 validation requires ALL required fields filled
  ├─ Can only proceed to Step 3 if validateStep(2) returns true
  └─ Form submission blocked if validation fails
```

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                       USER INPUT LAYER                           │
│                                                                   │
│  Street Address ──┐                                              │
│  Province ────────┼─→ Form Validation ─→ Error Handling         │
│  City ────────────┼─→ (JavaScript)     ─→ SweetAlert Modal      │
│  Barangay ────────┼─→ validateStep(2)  ─→ User Corrects         │
│  Name ────────────┤                                              │
│  Mobile ──────────┤                                              │
│  Email ───────────┤                                              │
│  Agent Code ──────┤                                              │
│  Date ────────────┤                                              │
│  Time ────────────┘                                              │
└────────────────────┬──────────────────────────────────────────────┘
                     │
                     │ IF VALID
                     ↓
┌─────────────────────────────────────────────────────────────────┐
│                    FORM SUBMISSION LAYER                         │
│                                                                   │
│  POST Data sent to:                                              │
│  preorder_handler.php                                            │
│  (or preorder.php with process_form handler)                     │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────────┐
│                 SERVER VALIDATION LAYER (PHP)                    │
│                                                                   │
│  ├─ Sanitize all inputs (mysqli_real_escape_string)             │
│  ├─ Validate required fields                                     │
│  ├─ Validate data formats                                        │
│  ├─ Check against database (if needed)                           │
│  └─ Return errors or proceed                                     │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────────┐
│                   DATABASE LAYER (MySQL)                         │
│                                                                   │
│  INSERT INTO pre_orders (                                        │
│    product_id, quantity, total_amount,                           │
│    street_address, province, city, barangay,                     │
│    recipient_name, mobile_number, delivery_email,               │
│    agent_code, delivery_date, delivery_time,                     │
│    status, created_at                                            │
│  ) VALUES (...)                                                  │
│                                                                   │
│  Returns: pre_order_id (new record)                              │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────────┐
│                   SESSION STORAGE LAYER                          │
│                                                                   │
│  $_SESSION['pre_order_id'] = new_id                              │
│  $_SESSION['delivery_details'] = [                               │
│    'recipient_name',                                             │
│    'street_address',                                             │
│    'city', 'province', 'barangay',                               │
│    'mobile_number', 'delivery_date', 'delivery_time'            │
│  ]                                                               │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────────┐
│                    NEXT STEP (Step 3: Payment)                   │
│                                                                   │
│  Redirect to: preorder.php?step=3                                │
│  Available: All delivery info from session                       │
│  Display: Order summary with delivery details                    │
│  Next: Payment type selection                                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Validation Rules Matrix

```
┌─────────────────────┬────────────┬──────────────────┬──────────────┐
│ Field               │ Required   │ Validation Rule  │ Error Message│
├─────────────────────┼────────────┼──────────────────┼──────────────┤
│ Street Address      │ YES        │ Length > 0       │ Required     │
│ Province            │ YES        │ Must select      │ Required     │
│ City                │ YES        │ Must select      │ Required     │
│ Barangay            │ NO         │ Optional         │ N/A          │
│ Recipient Name      │ YES        │ Length > 0       │ Required     │
│ Mobile Number       │ YES        │ /^09\d{9}$/      │ Format: 09x..│
│ Email               │ YES        │ Valid email      │ Invalid      │
│ Agent Code          │ NO         │ Optional         │ N/A          │
│ Delivery Date       │ YES        │ Today or later   │ Required     │
│ Delivery Time       │ YES        │ In list          │ Required     │
└─────────────────────┴────────────┴──────────────────┴──────────────┘
```

---

## Dropdown Cascade Flow

```
STARTING STATE:
┌────────────┐     ┌────────┐     ┌──────────┐
│ Province   │     │ City   │     │ Barangay │
│ (empty)    │     │(empty) │     │ (empty)  │
└────────────┘     └────────┘     └──────────┘

USER SELECTS PROVINCE:
┌─────────────────────────────────────────┐
│ Province dropdown change event triggered │
└──────────────┬──────────────────────────┘
               ↓
        ┌─────────────────────┐
        │ updateCities()      │
        │ called              │
        └────────┬────────────┘
                 ↓
┌────────────┐     ┌─────────────────┐     ┌──────────┐
│ Province   │     │ City            │     │ Barangay │
│ (selected) │     │ (populated)     │     │ (empty)  │
└────────────┘     └─────────────────┘     └──────────┘

USER SELECTS CITY:
┌─────────────────────────────────────────┐
│ City dropdown change event triggered    │
└──────────────┬──────────────────────────┘
               ↓
        ┌──────────────────────┐
        │ updateBarangays()    │
        │ called               │
        └────────┬─────────────┘
                 ↓
┌────────────┐     ┌─────────────────┐     ┌──────────────┐
│ Province   │     │ City            │     │ Barangay     │
│ (selected) │     │ (selected)      │     │ (populated)  │
└────────────┘     └─────────────────┘     └──────────────┘

USER CHANGES PROVINCE:
┌────────────────────────────────────────────┐
│ Province dropdown change event triggered   │
└──────────────┬─────────────────────────────┘
               ↓
        ┌─────────────────────┐
        │ updateCities()      │
        │ called              │
        │ + Reset Barangay    │
        └────────┬────────────┘
                 ↓
┌────────────┐     ┌─────────────────┐     ┌──────────┐
│ Province   │     │ City            │     │ Barangay │
│ (new)      │     │ (new options)   │     │ (empty)  │
└────────────┘     └─────────────────┘     └──────────┘
```

---

## JavaScript Function Call Sequence

```
Page Load (onDOMContentLoaded)
  │
  ├─→ setupDeliveryForm()
  │     │
  │     ├─→ Get province select element
  │     │
  │     ├─→ Populate from philippineLocations
  │     │     for (const province in philippineLocations)
  │     │
  │     ├─→ Add event listeners
  │     │     provinceSelect.addEventListener('change', updateCities)
  │     │     citySelect.addEventListener('change', updateBarangays)
  │     │
  │     ├─→ Set date minimum
  │     │     const today = new Date().toISOString().split('T')[0]
  │     │     deliveryDate.setAttribute('min', today)
  │     │
  │     └─→ Populate time slots
  │           for (const time in deliveryTimes)
  │
  ├─→ Form Ready for User Input
  │
  └─→ [User interaction begins]


User Selects Province
  │
  ├─→ 'change' event fired on province select
  │
  ├─→ updateCities() executed
  │     │
  │     ├─→ Get selected province value
  │     │
  │     ├─→ Clear city dropdown
  │     │
  │     ├─→ Get cities for that province
  │     │     const cities = Object.keys(philippineLocations[province])
  │     │
  │     ├─→ Populate city dropdown
  │     │     for (const city in cities)
  │     │
  │     └─→ Clear barangay dropdown
  │
  └─→ Wait for next user action


User Selects City
  │
  ├─→ 'change' event fired on city select
  │
  ├─→ updateBarangays() executed
  │     │
  │     ├─→ Get selected city value
  │     │
  │     ├─→ Get barangays for that city
  │     │     const barangays = philippineLocations[province][city]
  │     │
  │     └─→ Populate barangay dropdown
  │           for (const barangay in barangays)
  │
  └─→ Wait for form submission


User Clicks "Next" Button
  │
  ├─→ nextStep() executed
  │     │
  │     ├─→ validateStep(2) called
  │     │     │
  │     │     ├─→ Get all form values
  │     │     │
  │     │     ├─→ Check each required field
  │     │     │     if (!street) → show error, return false
  │     │     │     if (!province) → show error, return false
  │     │     │     if (!city) → show error, return false
  │     │     │     [... etc for all 10 fields ...]
  │     │     │
  │     │     └─→ Return true if all valid
  │     │
  │     ├─→ If validation returns false
  │     │     └─→ Stop, user stays on Step 2
  │     │
  │     └─→ If validation returns true
  │           ├─→ Disable form inputs
  │           ├─→ Show form.submit() or AJAX call
  │           └─→ Send to backend
  │
  └─→ Backend processes...


Backend Response
  │
  ├─→ If successful
  │     └─→ Redirect to Step 3 (Payment)
  │
  └─→ If error
        └─→ Redirect back to Step 2 with error message
```

---

## Browser Console Debug Info

When troubleshooting, check console for:

```javascript
// Check if data structures are loaded:
console.log(window.philippineLocations);  // Should show province object
console.log(window.deliveryTimes);        // Should show 15 time slots

// Check if elements exist:
console.log(document.getElementById('province'));      // Should not be null
console.log(document.getElementById('city'));          // Should not be null
console.log(document.getElementById('barangay'));      // Should not be null
console.log(document.getElementById('deliveryDate'));  // Should not be null
console.log(document.getElementById('deliveryTime'));  // Should not be null

// Check event listeners:
document.getElementById('province').addEventListener('change', () => {
  console.log('Province changed to:', this.value);
});

// Check validation:
console.log(validateStep(2));  // Should return true/false

// Check form values:
console.log({
  street: document.getElementById('street_address').value,
  province: document.getElementById('province').value,
  city: document.getElementById('city').value,
  recipient: document.getElementById('recipient_name').value,
  mobile: document.getElementById('mobile_number').value,
  email: document.getElementById('delivery_email').value,
  date: document.getElementById('deliveryDate').value,
  time: document.getElementById('deliveryTime').value
});
```

---

## Form State Diagram

```
┌──────────────────────────────────────────────┐
│ FORM STATE: EMPTY                            │
│ ✗ Invalid (all required fields empty)        │
│ [NEXT] Button: Disabled or Shows Errors      │
└──────────────┬───────────────────────────────┘
               │ User enters data
               ↓
┌──────────────────────────────────────────────┐
│ FORM STATE: PARTIALLY FILLED                 │
│ ✗ Invalid (some required fields empty)       │
│ [NEXT] Button: Shows Errors When Clicked     │
└──────────────┬───────────────────────────────┘
               │ User continues filling
               ↓
┌──────────────────────────────────────────────┐
│ FORM STATE: FULLY FILLED (VALID DATA)        │
│ ✓ Valid (all validations pass)               │
│ [NEXT] Button: Can Be Clicked to Proceed     │
└──────────────┬───────────────────────────────┘
               │ User clicks [NEXT]
               ↓
┌──────────────────────────────────────────────┐
│ FORM STATE: SUBMITTED                        │
│ 🔄 Processing (sending to backend)           │
│ [NEXT] Button: Disabled (prevents double)    │
└──────────────┬───────────────────────────────┘
               │ Backend validates & inserts
               ↓
           ┌───┴───┐
          ✓│       │✗
           ↓       ↓
    ┌─────────────┐    ┌──────────────────┐
    │ SUCCESS     │    │ VALIDATION ERROR │
    │ Redirect to │    │ Show Error Msg   │
    │ Step 3      │    │ Return to Step 2 │
    │ (Payment)   │    │ User Can Retry   │
    └─────────────┘    └──────────────────┘
```

---

## Mobile Responsiveness

```
DESKTOP (≥992px)
┌─────────────────────────────────────────┐
│ Street Address                          │
│ [____________________________] (full)    │
│                                         │
│ [Province________] [City_________]      │
│ [Barangay____________]                  │
│                                         │
│ [Name________] [Mobile_] [Email____]    │
│ [Agent Code__________]                  │
│                                         │
│ [Date____] [Time___________]            │
│                                         │
│ [◀ BACK]              [NEXT ▶]          │
└─────────────────────────────────────────┘

TABLET (576px - 991px)
┌──────────────────────┐
│ Street Address       │
│ [________________]   │
│                      │
│ [Province______]     │
│ [City_________]      │
│ [Barangay_____]      │
│                      │
│ [Name_______] [Phone]│
│ [Email_____]         │
│ [Agent Code__]       │
│                      │
│ [Date__] [Time____]  │
│                      │
│ [BACK] [NEXT]        │
└──────────────────────┘

MOBILE (<576px)
┌──────────────────┐
│ Street Address   │
│ [______________] │
│                  │
│ [Province_____]  │
│ [City________]   │
│ [Barangay____]   │
│                  │
│ [Name______]     │
│ [Mobile____]     │
│ [Email_____]     │
│ [Agent Code]     │
│                  │
│ [Date__]         │
│ [Time_______]    │
│                  │
│ [BACK] [NEXT]    │
└──────────────────┘
```

---

## Summary

This Step 2 form:
✅ Displays 10 form fields organized in 3 logical sections
✅ Validates all inputs client-side and server-side
✅ Uses cascading dropdowns for location selection
✅ Restricts dates to today or future
✅ Validates mobile number format (09XXXXXXXXX)
✅ Validates email format
✅ Is fully responsive on all devices
✅ Provides clear error messages via SweetAlert2
✅ Submits to backend for database storage
✅ Proceeds to Step 3 (Payment) on successful validation

**Visual form is complete and ready for use!**
