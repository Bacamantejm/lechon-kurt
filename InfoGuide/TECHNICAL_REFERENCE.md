# Technical Reference - Multi-Step Pre-Order System

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      User Interface Layer                    │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ preorder.php - Multi-step Wizard (5 Steps)           │   │
│  │ - Step 1: Product Selection                          │   │
│  │ - Step 2: Delivery Method (Pickup/Delivery)          │   │
│  │ - Step 3: Date & Time Selection                      │   │
│  │ - Step 4: Payment Option Selection                   │   │
│  │ - Step 5: Order Confirmation & Review                │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    Service Layer                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ preorder_service.php - PreOrderService Class          │   │
│  │ - createPreOrder() - Insert new pre-order with coords │   │
│  │ - getPreOrder() - Retrieve pre-order details          │   │
│  │ - updatePreOrderStatus() - Status updates + notify    │   │
│  │ - createNotification() - Send customer notifications  │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    Data Layer                                │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ MySQL Database - pre_orders Table                     │   │
│  │ - id, user_id, product_id, product_name              │   │
│  │ - quantity, unit_price, total_price                  │   │
│  │ - preferred_pickup_date, preferred_pickup_time       │   │
│  │ - pickup_location, delivery_address                  │   │
│  │ - latitude, longitude ← NEW FIELDS                   │   │
│  │ - delivery_method, payment_type                      │   │
│  │ - downpayment_amount, remaining_amount               │   │
│  │ - special_instructions, created_at, updated_at       │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                 External APIs                                │
│  ┌──────────────────┐  ┌──────────────────┐                 │
│  │   Google Maps    │  │  PayMongo Payment │                │
│  │ - Autocomplete   │  │ - Process Payment │                │
│  │ - Coordinates    │  │ - Verify Payment  │                │
│  └──────────────────┘  └──────────────────┘                 │
└─────────────────────────────────────────────────────────────┘
```

---

## File Structure

### Frontend Files
```
preorder.php
├── HTML Structure (5 step divs)
├── CSS Styling (Step-by-step wizard UI)
├── JavaScript Logic
│   ├── Step Navigation (goToStep, nextStep, prevStep)
│   ├── Form Validation (validateStep)
│   ├── Product Selection (selectProduct, renderProducts)
│   ├── Delivery Method (setDeliveryMethod, selectStore)
│   ├── Time Slots (selectTimeSlot, setupTimeSlots)
│   ├── Google Maps (initGoogleMaps, setupGoogleMaps)
│   └── Payment (setupPaymentTypeToggle)
└── SweetAlert2 Confirmations
```

### Backend Files
```
preorder_service.php
├── class PreOrderService
│   ├── __construct($db_connection)
│   ├── createPreOrder($user_id, $product_id, ..., $latitude, $longitude)
│   ├── getPreOrder($pre_order_id)
│   ├── getUserPreOrders($user_id)
│   ├── updatePreOrderStatus($pre_order_id, $new_status, $admin_notes)
│   └── createPreOrderNotification($user_id, ...)
└── (Also in includes/config.php)
    └── createNotification($conn, $user_id, $type, $title, ...)
```

---

## Database Schema Updates

### New Fields in pre_orders Table

```sql
ALTER TABLE pre_orders 
ADD COLUMN latitude DECIMAL(10,8) AFTER delivery_address,
ADD COLUMN longitude DECIMAL(11,8) AFTER latitude;
```

### Field Details

| Field | Type | Purpose | Example |
|-------|------|---------|---------|
| latitude | DECIMAL(10,8) | Geographic latitude | 14.55960000 |
| longitude | DECIMAL(11,8) | Geographic longitude | 120.98420000 |

### Data Range
- **Latitude:** -90.00000000 to 90.00000000
- **Longitude:** -180.00000000 to 180.00000000
- **Precision:** 8 decimal places = ~1.1mm accuracy

---

## JavaScript Modules & Functions

### Step Navigation

```javascript
goToStep(step)
// - Hides all steps
// - Shows selected step
// - Updates progress bar
// - Scrolls to top

nextStep(e)
// - Validates current step
// - Calls goToStep(currentStep + 1)

prevStep(e)
// - Calls goToStep(currentStep - 1)
```

### Form Validation

```javascript
validateStep(step)
// Step 1: Check product selected
// Step 2: Check delivery method + location
// Step 3: Check date and time slot
// Returns: true/false with error alerts
```

### Product Management

```javascript
selectProduct(element, id, name, price)
// - Updates selectedProduct object
// - Marks element as selected
// - Updates summary totals

renderProducts()
// - Fetches products from JavaScript array
// - Creates product cards
// - Adds click handlers
```

### Delivery Method

```javascript
setDeliveryMethod(method)
// - Sets selectedDeliveryMethod variable
// - Shows/hides relevant sections
// - Updates pricing
// - For delivery: initializes Google Maps
// - For pickup: renders store list

selectStore(storeId, storeName)
// - Updates selected store
// - Shows store details in confirmation
```

### Time Slots

```javascript
setupTimeSlots()
// - Creates 14 hourly slots (8 AM - 9 PM)
// - Renders time slot buttons
// - Adds click handlers

selectTimeSlot(time, label)
// - Marks slot as selected (red background)
// - Updates pickup time hidden field
// - Updates confirmation text
```

### Google Maps Integration

```javascript
setupGoogleMaps()
// - Gets user's current geolocation (if permitted)
// - Auto-fills latitude/longitude

initGoogleMaps()
// - Creates map instance
// - Centers on Manila by default
// - Initializes Places Autocomplete

autocomplete.addListener('place_changed', function() {
// - Gets selected place from Google
// - Extracts coordinates
// - Updates map view
// - Fills latitude/longitude inputs
```

### Payment Type Toggle

```javascript
setupPaymentTypeToggle()
// - Listens to payment type radio buttons
// - Shows/hides downpayment row in confirmation
// - Updates amounts based on selection
```

---

## API Integration

### Google Maps API

**Endpoint:** `https://maps.googleapis.com/maps/api/js`

**Parameters:**
```
key=AIzaSyD1xFgC7ck0sKVSKkPrOeqmAn2GgxBLxCk
libraries=places
```

**Features Used:**
- `google.maps.Map` - Interactive map display
- `google.maps.places.Autocomplete` - Address autocomplete
- `Geocoding` - Coordinate extraction from addresses

### Form Submission

**Method:** POST to same page
**Data Submitted:**
```
- product_id
- product_name
- quantity
- unit_price
- pickup_date
- pickup_time
- pickup_location (for pickup) OR delivery_address (for delivery)
- latitude (for delivery)
- longitude (for delivery)
- delivery_method
- payment_type
- special_instructions
- submit_preorder = 1
```

---

## Client-Side Validation

### Step 1 Validation
```javascript
if (!selectedProduct) {
    Alert: "Please select a product to continue"
    return false
}
```

### Step 2 Validation
```javascript
// If Pickup:
if (!document.getElementById('pickupLocation').value) {
    Alert: "Please select a pickup location"
    return false
}

// If Delivery:
if (!document.getElementById('deliveryAddress').value) {
    Alert: "Please enter your delivery address"
    return false
}
```

### Step 3 Validation
```javascript
if (!pickupDate) {
    Alert: "Please select a pickup date"
    return false
}

if (!pickupTime) {
    Alert: "Please select a time slot"
    return false
}
```

---

## CSS Classes & Styling

### Progress Bar
```css
.progress-steps          /* Container for progress indicators */
.progress-step           /* Individual step indicator */
.progress-step.active    /* Currently active step (red) */
.progress-step.completed /* Completed step (green) */
.progress-step-circle    /* Circle around step number */
.progress-step-label     /* Text under circle */
```

### Form Elements
```css
.step-content            /* Container for each step */
.step-content.active     /* Currently visible step */
.step-title              /* Title of current step */
.form-group              /* Wrapper for form inputs */
.quantity-selector       /* Quantity +/- buttons */
```

### Product & Store Cards
```css
.product-card            /* Product selection card */
.product-card.selected   /* Selected product (red border) */
.store-card              /* Store location card */
.store-card.selected     /* Selected store (red border) */
```

### Time Slots
```css
.time-slots-grid         /* Grid container for time slots */
.time-slot               /* Individual time slot button */
.time-slot.selected      /* Selected time slot (red) */
```

### Delivery Options
```css
.delivery-method-group   /* Container for delivery options */
.delivery-option         /* Individual delivery option */
.delivery-option-icon    /* Icon inside option */
.delivery-option-label   /* Label text */
```

### Summary
```css
.order-summary           /* Gray box with order details */
.summary-row             /* Single line in summary */
.summary-row.total       /* Total row (bold, red) */
```

---

## Time Zone & Date Handling

### Current Implementation
- Uses browser's local timezone
- Date input type handles locale automatically
- Time stored as HH:MM format (24-hour)
- Validation ensures future dates only

### Timezone Considerations
- **Server:** Asia/Manila (UTC+8)
- **Client:** Browser local time
- **Database:** Stored as TEXT "HH:MM"
- **No conversion:** Direct client → DB

---

## Performance Optimizations

### JavaScript
- Event listeners added once on DOMContentLoaded
- No unnecessary re-renders
- Efficient DOM updates
- Minimal API calls

### Google Maps
- Map initialized only when delivery option selected
- Autocomplete instance created once
- No map re-initialization on step changes

### Database
- Prepared statements (secure + fast)
- Single INSERT for pre-order + coordinates
- Indexed queries on user_id

---

## Security Considerations

### Input Validation
- Client-side: SweetAlert2 validation
- Server-side: mysqli prepared statements
- HTML escaping on display

### Data Protection
- Coordinates stored securely in DB
- User authentication required
- Session-based access control

### API Keys
- Google Maps API key in HTML (public key needed)
- PayMongo keys in separate config (not exposed)

---

## Error Handling

### JavaScript Errors
```javascript
try {
    // Google Maps initialization
} catch(e) {
    console.error('Error:', e);
}

// Network errors
.catch(error => {
    Swal.fire('Error', 'Failed to load data', 'error');
})
```

### Database Errors
```php
if (!$stmt) {
    return ['success' => false, 'message' => 'Database error'];
}
```

---

## Testing Checklist

### Functional Testing
- [ ] Product selection works
- [ ] Quantity adjustment updates totals
- [ ] Delivery method toggle shows/hides sections
- [ ] Pickup location selection saves value
- [ ] Google Maps loads and searches work
- [ ] Coordinates auto-fill on address selection
- [ ] Date picker allows only future dates
- [ ] Time slot selection stores value
- [ ] Payment type toggle updates summary
- [ ] Form submission creates pre-order
- [ ] Confirmation email sends

### UI/UX Testing
- [ ] Progress bar updates correctly
- [ ] Step navigation works (forward/back)
- [ ] Error alerts display properly
- [ ] Responsive on mobile (< 768px)
- [ ] Responsive on tablet (768-1024px)
- [ ] Responsive on desktop (> 1024px)
- [ ] All colors display correctly
- [ ] Icons load properly
- [ ] Animations smooth

### Data Testing
- [ ] User ID stored correctly
- [ ] Product info persists
- [ ] Delivery coordinates save
- [ ] Pickup location ID saves
- [ ] Payment type recorded
- [ ] Special instructions captured

---

## Deployment Checklist

- [ ] preorder_old.php backed up
- [ ] New preorder.php in place
- [ ] preorder_service.php updated
- [ ] Database ALTER TABLE executed
- [ ] Google Maps API key configured
- [ ] Cache cleared
- [ ] Tested in all browsers
- [ ] Mobile testing completed
- [ ] Email notifications verify
- [ ] PayMongo redirect URLs updated

---

## Troubleshooting Guide

### Google Maps Not Loading
**Cause:** API key invalid/expired
**Fix:** Check API key in preorder.php line with googleapis.com

### Coordinates Not Capturing
**Cause:** Geolocation denied or unavailable
**Fix:** Manual input or grant location permission

### Time Slots Not Showing
**Cause:** Delivery method not selected
**Fix:** Select delivery method first to show time slots

### Form Not Submitting
**Cause:** Validation failed
**Fix:** Check SweetAlert error message and fill required fields

### Map Not Centering
**Cause:** Browser blocking geolocation
**Fix:** Check browser permissions, defaults to Manila (14.5995, 120.9842)

---

## Future Enhancement Ideas

1. **Save Addresses** - Store previous delivery addresses
2. **Repeat Orders** - One-click reorder from history
3. **Scheduled Delivery** - Date/time ranges instead of exact times
4. **Admin Map View** - Visualize all orders on map
5. **Route Optimization** - Calculate delivery routes
6. **SMS Notifications** - Text alerts at each step
7. **Promo Codes** - Discount validation in step 4
8. **Wishlist** - Save products for later

---

## Support & Documentation

- **User Guide:** PREORDER_USER_GUIDE.md
- **Implementation Details:** MULTISTEP_ORDER_IMPLEMENTATION.md
- **Database:** lechon_db.sql (initial schema)
- **Email:** EmailService class in email_service.php
- **Notifications:** createNotification() in includes/config.php

