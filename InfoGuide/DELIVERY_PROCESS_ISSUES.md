# Delivery Process Issues - Diagnosis & Solutions

## 📋 Available Products

The system currently has **5 products** hardcoded in `preorder.php` (lines 920-925):

### Product Listing
| ID | Product Name | Price | Category |
|---|---|---|---|
| 1 | Lechon (Whole) | ₱2,500.00 | lechon |
| 2 | Lechon (Half) | ₱1,400.00 | lechon |
| 3 | Lechon (Quarter) | ₱800.00 | lechon |
| 4 | Lechon Paksiw | ₱1,200.00 | main |
| 5 | Lechon Roll | ₱600.00 | appetizers |

### Issue 1: Products Are Hardcoded
**Problem:** Products are stored in JavaScript array, not fetched from database
```javascript
const products = [
    { id: 1, name: 'Lechon (Whole)', price: 2500, category: 'lechon' },
    // ... etc
];
```

**Impact:** 
- New products can't be added without editing PHP file
- Admin product management not integrated
- No product inventory tracking
- Changes require redeployment

**Solution:** Fetch products from `products` table instead

---

## 🚚 Delivery Process Issues

### Issue 2: Google Maps API Not Fully Functional
**Location:** Lines 1244-1290 in `preorder.php`

**Problems Identified:**

#### A. Geolocation Error Handling Missing
```javascript
function setupGoogleMaps() {
    const addressInput = document.getElementById('deliveryAddress');
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            // Success callback exists
        });
        // ❌ NO ERROR CALLBACK!
    }
}
```

**Impact:** If user denies location permission, coordinates are never filled in

#### B. Map Initialization Error
```javascript
function initGoogleMaps() {
    if (mapInstance) return;
    
    const defaultLocation = { lat: 14.5995, lng: 120.9842 };
    mapInstance = new google.maps.Map(document.getElementById('map'), {
        zoom: 13,
        center: defaultLocation
    });
    // ❌ PROBLEM: Map tries to initialize but 'map' div may not be visible yet
}
```

**Impact:** Map doesn't appear or throws errors when delivery method selected

#### C. Autocomplete Listener Not Handling Missing Place
```javascript
autocomplete.addListener('place_changed', function() {
    const place = autocomplete.getPlace();
    if (place.geometry) {
        // ... code works
    }
    // ❌ NO ELSE - silent failure if user doesn't select from suggestions
});
```

**Impact:** User can type address but not search results properly

---

### Issue 3: Missing Map Styling
**Location:** No CSS for `#map` element

**Problem:** Map div has no size defined
```html
<div id="map"></div>
```

**Missing CSS:**
```css
#map {
    width: 100%;
    height: 400px;
    border-radius: 8px;
    margin: 15px 0;
    border: 1px solid #ddd;
}
```

**Impact:** Map doesn't display even if Google Maps API loads correctly

---

### Issue 4: Latitude/Longitude Input Validation
**Location:** Line 1256-1258

**Problems:**
- No validation that coordinates are within Philippines bounds
- No validation that delivery address is actually in service area
- `readonly` attributes prevent manual input correction

**Impact:** Invalid coordinates could be submitted

---

### Issue 5: Delivery Address Not Validated Server-Side
**Location:** Lines 52-54 (validation section)

```php
if (empty($delivery_address) && $delivery_method === 'delivery') 
    $errors[] = "Please enter delivery address";
```

**Problems:**
- Only checks if empty, doesn't validate address format
- Doesn't verify coordinates were captured
- No distance calculation to check if within delivery zone

**Impact:** Orders can be created with incomplete delivery info

---

### Issue 6: Store Locations Query May Fail Silently
**Location:** Lines 133-137

```php
$store_query = "SELECT * FROM store_locations WHERE is_active = 1 ORDER BY store_name";
$store_result = mysqli_query($conn, $store_query);
$stores = mysqli_fetch_all($store_result, MYSQLI_ASSOC);
```

**Problems:**
- No error checking
- If table doesn't exist, `$stores` becomes NULL
- JavaScript tries to JSON encode NULL

**Impact:** Pickup option may fail to load, stuck on step 2

---

## 🔴 Critical Issues Blocking Delivery Selection

### Issue 7: Payment Page Not Configured
**Location:** Line 100 in `preorder.php`

```php
header("Location: preorder_payment.php?id=$pre_order_id&type=...");
```

**Problem:** `preorder_payment.php` file doesn't exist in workspace

**Impact:** Form submission redirects to 404 page after creating pre-order

---

### Issue 8: Email Service Not Fully Configured
**Location:** Lines 85-97 in `preorder.php`

```php
$email_service->sendPreOrderConfirmation($_SESSION['email'], [...]);
```

**Problems:**
- Uses `$_SESSION['email']` which may not be set
- Should use `$user['email']` from database query
- No error handling if email fails

**Impact:** Users don't receive confirmation emails

---

## 📊 Summary of Issues

### Severity: CRITICAL 🔴
1. **preorder_payment.php missing** - Form can't submit
2. **Store locations query fails silently** - Pickup stuck

### Severity: HIGH 🟠
3. **Google Maps styling missing** - Map doesn't appear
4. **Geolocation error handling missing** - Coordinates not auto-filled
5. **Coordinates validation missing** - Invalid data accepted

### Severity: MEDIUM 🟡
6. **Products hardcoded** - Can't add new products without code change
7. **Email uses wrong session var** - Confirmation emails fail
8. **No server-side address validation** - Bad data can be saved

### Severity: LOW 🟢
9. **Autocomplete error handling missing** - Silent failures on manual input
10. **Readonly coordinates** - Users can't fix bad geolocation

---

## 🔧 Step-by-Step Fix Guide

### Step 1: Create Missing Payment Page
Create `preorder_payment.php` to handle payment processing

### Step 2: Add Map Styling
Add CSS for `#map` element

### Step 3: Fix Google Maps Integration
- Add error callbacks for geolocation
- Add error handling for autocomplete
- Add validation alerts

### Step 4: Fix Email Service
- Use `$user['email']` instead of `$_SESSION['email']`
- Add error handling

### Step 5: Add Server-Side Validation
- Validate coordinates are in Philippines
- Calculate distance for delivery zone
- Prevent out-of-area deliveries

### Step 6: Integrate Products from Database
- Fetch products from `products` table
- Cache in session or AJAX endpoint

### Step 7: Add Error Checking
- Check store locations query result
- Add try-catch for Google Maps API
- Add logging for debugging

---

## 🧪 Testing Checklist

- [ ] Products load in Step 1
- [ ] Pickup option shows store list in Step 2
- [ ] Delivery option shows map in Step 2
- [ ] Map displays Manila on load
- [ ] Geolocation fills latitude/longitude
- [ ] Address search works on map
- [ ] Coordinates update when address selected
- [ ] Date picker allows future dates only
- [ ] Time slots appear for selected date
- [ ] Payment options show in Step 4
- [ ] Order summary calculates correctly
- [ ] Form submits to payment page
- [ ] Pre-order created in database
- [ ] User receives confirmation email

---

## 📝 Code Locations for Fixes

| Issue | File | Lines | Fix Type |
|-------|------|-------|----------|
| Missing payment page | preorder_payment.php | NEW | Create file |
| Map styling | preorder.php | 140-800 | Add CSS |
| Geolocation error | preorder.php | 1244-1250 | Add callback |
| Store query error | preorder.php | 133-137 | Add validation |
| Email session var | preorder.php | 85-97 | Change variable |
| Address validation | preorder.php | 52-54 | Add validation |
| Autocomplete errors | preorder.php | 1268-1280 | Add error handling |

