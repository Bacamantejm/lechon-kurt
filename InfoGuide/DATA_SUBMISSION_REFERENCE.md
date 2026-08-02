# Step 2 Form - Data Submission Reference

## Form Submission Example

### HTML Form Structure
```html
<form id="preorderForm" method="POST" action="preorder_handler.php">
    <!-- Step 2 fields -->
    <input type="text" id="street_address" name="street_address" required>
    <select id="province" name="province" required></select>
    <select id="city" name="city" required></select>
    <select id="barangay" name="barangay"></select>
    
    <input type="text" id="recipient_name" name="recipient_name" required>
    <input type="tel" id="mobile_number" name="mobile_number" required>
    <input type="email" id="delivery_email" name="delivery_email" required>
    <input type="text" id="agent_code" name="agent_code">
    
    <input type="date" id="deliveryDate" name="delivery_date" required>
    <select id="deliveryTime" name="delivery_time" required></select>
    
    <button type="submit">Submit</button>
</form>
```

---

## POST Data Format

### Example 1: Complete Submission (All Fields)

```
Request Type: POST
Content-Type: application/x-www-form-urlencoded
Endpoint: preorder_handler.php

POST Data:
street_address=123+Main+Street,+Unit+4B
&province=Metro+Manila
&city=Makati
&barangay=Barangay+1
&recipient_name=Juan+Dela+Cruz
&mobile_number=09171234567
&delivery_email=juan@email.com
&agent_code=AGENT2024001
&delivery_date=2024-01-15
&delivery_time=2:00+PM
```

### Example 2: Minimal Submission (Only Required Fields)

```
POST Data:
street_address=456+Maharlika+Avenue
&province=Calabarzon
&city=Laguna
&barangay=
&recipient_name=Maria+Santos
&mobile_number=09281234567
&delivery_email=maria@email.com
&agent_code=
&delivery_date=2024-01-20
&delivery_time=10:00+AM
```

---

## JSON Representation

### Complete Data Object

```json
{
  "street_address": "123 Main Street, Unit 4B",
  "province": "Metro Manila",
  "city": "Makati",
  "barangay": "Barangay 1",
  "recipient_name": "Juan Dela Cruz",
  "mobile_number": "09171234567",
  "delivery_email": "juan@email.com",
  "agent_code": "AGENT2024001",
  "delivery_date": "2024-01-15",
  "delivery_time": "2:00 PM"
}
```

---

## PHP Array Format

```php
$_POST = array(
    'street_address' => '123 Main Street, Unit 4B',
    'province' => 'Metro Manila',
    'city' => 'Makati',
    'barangay' => 'Barangay 1',
    'recipient_name' => 'Juan Dela Cruz',
    'mobile_number' => '09171234567',
    'delivery_email' => 'juan@email.com',
    'agent_code' => 'AGENT2024001',
    'delivery_date' => '2024-01-15',
    'delivery_time' => '2:00 PM'
);
```

---

## SQL INSERT Statement

```sql
INSERT INTO pre_orders (
    user_id,
    product_id,
    quantity,
    total_amount,
    delivery_fee,
    street_address,
    province,
    city,
    barangay,
    recipient_name,
    mobile_number,
    delivery_email,
    agent_code,
    delivery_date,
    delivery_time,
    status,
    payment_type,
    payment_status,
    created_at
) VALUES (
    1,
    5,
    2,
    998.00,
    150.00,
    '123 Main Street, Unit 4B',
    'Metro Manila',
    'Makati',
    'Barangay 1',
    'Juan Dela Cruz',
    '09171234567',
    'juan@email.com',
    'AGENT2024001',
    '2024-01-15',
    '2:00 PM',
    'pending',
    'full',
    'pending',
    NOW()
);
```

---

## Database Record Example

```sql
mysql> SELECT * FROM pre_orders WHERE user_id = 1 LIMIT 1;

pre_order_id       | 42
user_id            | 1
product_id         | 5
quantity           | 2
total_amount       | 998.00
delivery_fee       | 150.00
street_address     | 123 Main Street, Unit 4B
province           | Metro Manila
city               | Makati
barangay           | Barangay 1
recipient_name     | Juan Dela Cruz
mobile_number      | 09171234567
delivery_email     | juan@email.com
agent_code         | AGENT2024001
delivery_date      | 2024-01-15
delivery_time      | 2:00 PM
status             | pending
payment_type       | full
payment_status     | pending
created_at         | 2024-01-10 14:30:45
updated_at         | 2024-01-10 14:30:45
```

---

## PHP Handler Template

```php
<?php
session_start();
require_once 'includes/config.php';

// Get form data
$street_address = $_POST['street_address'] ?? '';
$province = $_POST['province'] ?? '';
$city = $_POST['city'] ?? '';
$barangay = $_POST['barangay'] ?? '';
$recipient_name = $_POST['recipient_name'] ?? '';
$mobile_number = $_POST['mobile_number'] ?? '';
$delivery_email = $_POST['delivery_email'] ?? '';
$agent_code = $_POST['agent_code'] ?? '';
$delivery_date = $_POST['delivery_date'] ?? '';
$delivery_time = $_POST['delivery_time'] ?? '';

// Sanitize
$street_address = mysqli_real_escape_string($conn, $street_address);
$province = mysqli_real_escape_string($conn, $province);
$city = mysqli_real_escape_string($conn, $city);
$barangay = mysqli_real_escape_string($conn, $barangay);
$recipient_name = mysqli_real_escape_string($conn, $recipient_name);
$mobile_number = mysqli_real_escape_string($conn, $mobile_number);
$delivery_email = mysqli_real_escape_string($conn, $delivery_email);
$agent_code = mysqli_real_escape_string($conn, $agent_code);

// Validate
$errors = [];
if (empty($street_address)) $errors[] = 'Street address required';
if (empty($province)) $errors[] = 'Province required';
if (empty($city)) $errors[] = 'City required';
if (empty($recipient_name)) $errors[] = 'Name required';
if (!preg_match('/^09\d{9}$/', $mobile_number)) $errors[] = 'Invalid mobile';
if (!filter_var($delivery_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
if (empty($delivery_date) || strtotime($delivery_date) < strtotime('today')) $errors[] = 'Invalid date';
if (empty($delivery_time)) $errors[] = 'Time required';

if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    header('Location: preorder.php?step=2');
    exit();
}

// Get pre-order details from session
$user_id = $_SESSION['user_id'] ?? null;
$product_id = $_SESSION['product_id'] ?? null;
$quantity = $_SESSION['quantity'] ?? 1;
$total_amount = $_SESSION['total_amount'] ?? 0;

// Insert into database
$query = "INSERT INTO pre_orders (
    user_id, product_id, quantity, total_amount, delivery_fee,
    street_address, province, city, barangay,
    recipient_name, mobile_number, delivery_email, agent_code,
    delivery_date, delivery_time, status, payment_type, payment_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'full', 'pending')";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param(
    $stmt,
    'iiiddsssssssss',
    $user_id, $product_id, $quantity, $total_amount, 150.00,
    $street_address, $province, $city, $barangay,
    $recipient_name, $mobile_number, $delivery_email, $agent_code,
    $delivery_date, $delivery_time
);

if (!mysqli_stmt_execute($stmt)) {
    $_SESSION['error'] = 'Database error: ' . mysqli_stmt_error($stmt);
    header('Location: preorder.php?step=2');
    exit();
}

// Store in session
$pre_order_id = mysqli_insert_id($conn);
$_SESSION['pre_order_id'] = $pre_order_id;
$_SESSION['delivery_details'] = [
    'recipient_name' => $recipient_name,
    'street_address' => $street_address,
    'city' => $city,
    'province' => $province,
    'barangay' => $barangay,
    'mobile_number' => $mobile_number,
    'delivery_date' => $delivery_date,
    'delivery_time' => $delivery_time
];

// Redirect to payment
header('Location: preorder.php?step=3');
exit();
?>
```

---

## AJAX Submission (Alternative)

If using AJAX instead of form submit:

```javascript
// Collect form data
const formData = new FormData();
formData.append('street_address', document.getElementById('street_address').value);
formData.append('province', document.getElementById('province').value);
formData.append('city', document.getElementById('city').value);
formData.append('barangay', document.getElementById('barangay').value);
formData.append('recipient_name', document.getElementById('recipient_name').value);
formData.append('mobile_number', document.getElementById('mobile_number').value);
formData.append('delivery_email', document.getElementById('delivery_email').value);
formData.append('agent_code', document.getElementById('agent_code').value);
formData.append('delivery_date', document.getElementById('deliveryDate').value);
formData.append('delivery_time', document.getElementById('deliveryTime').value);

// Send via AJAX
fetch('preorder_handler.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        // Go to step 3
        goToStep(3);
    } else {
        // Show errors
        showErrors(data.errors);
    }
})
.catch(error => console.error('Error:', error));
```

---

## Email Confirmation Template

```html
<html>
<head>
    <title>Order Confirmation - Lechon Delights</title>
</head>
<body>
    <h2>Your Order Confirmation</h2>
    
    <h3>Delivery Information</h3>
    <p>
        <strong>Recipient:</strong> Juan Dela Cruz<br>
        <strong>Address:</strong> 123 Main Street, Unit 4B<br>
        <strong>City:</strong> Makati<br>
        <strong>Province:</strong> Metro Manila<br>
        <strong>Barangay:</strong> Barangay 1<br>
        <strong>Contact Number:</strong> 09171234567<br>
        <strong>Email:</strong> juan@email.com<br>
        <strong>Scheduled Delivery:</strong> January 15, 2024 at 2:00 PM
    </p>
    
    <h3>Order Summary</h3>
    <p>
        <strong>Product:</strong> Lechon Platter<br>
        <strong>Quantity:</strong> 2<br>
        <strong>Subtotal:</strong> ₱998.00<br>
        <strong>Delivery Fee:</strong> ₱150.00<br>
        <strong>Total:</strong> ₱1,148.00
    </p>
    
    <p>We will contact you at <strong>09171234567</strong> on delivery day to confirm the exact time.</p>
    
    <p>Thank you for ordering from Lechon Delights!</p>
</body>
</html>
```

---

## Admin Display Example

### Order Details Page

```html
<div class="order-details">
    <h3>Order #42</h3>
    
    <div class="delivery-section">
        <h4>Delivery Information</h4>
        <p>
            <strong>Recipient:</strong> Juan Dela Cruz<br>
            <strong>Address:</strong> 123 Main Street, Unit 4B<br>
            <strong>Location:</strong> Barangay 1, Makati, Metro Manila<br>
            <strong>Contact:</strong> 09171234567<br>
            <strong>Email:</strong> juan@email.com<br>
            <strong>Scheduled:</strong> 2024-01-15 at 2:00 PM
        </p>
    </div>
    
    <div class="order-section">
        <h4>Order Items</h4>
        <p>Lechon Platter x2 - ₱998.00</p>
        <p>Delivery Fee: ₱150.00</p>
        <p><strong>Total: ₱1,148.00</strong></p>
    </div>
</div>
```

---

## Data Validation Checklist

Before submitting to database, verify:

```
☐ street_address: non-empty, ≤255 chars
☐ province: selected, non-empty
☐ city: selected, non-empty
☐ barangay: optional, ≤50 chars
☐ recipient_name: non-empty, ≤100 chars
☐ mobile_number: matches /^09\d{9}$/, ≤11 chars
☐ delivery_email: valid email, ≤100 chars
☐ agent_code: optional, ≤50 chars
☐ delivery_date: valid DATE, today or future
☐ delivery_time: selected, in list of 15 times
```

---

## Database Constraints

```sql
-- Recommended constraints
ALTER TABLE pre_orders MODIFY COLUMN street_address VARCHAR(255) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN province VARCHAR(50) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN city VARCHAR(50) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN recipient_name VARCHAR(100) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN mobile_number VARCHAR(11) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN delivery_email VARCHAR(100) NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN delivery_date DATE NOT NULL;
ALTER TABLE pre_orders MODIFY COLUMN delivery_time VARCHAR(10) NOT NULL;

-- Indexes for performance
CREATE INDEX idx_delivery_date ON pre_orders(delivery_date);
CREATE INDEX idx_province_city ON pre_orders(province, city);
CREATE INDEX idx_mobile ON pre_orders(mobile_number);
```

---

## Testing Data

### Test Case 1: Valid Complete Data
```
street_address: 123 Main Street, Unit 4B
province: Metro Manila
city: Makati
barangay: Barangay 1
recipient_name: Juan Dela Cruz
mobile_number: 09171234567
delivery_email: juan@email.com
agent_code: AGENT2024001
delivery_date: 2024-01-15
delivery_time: 2:00 PM
```

### Test Case 2: Minimal Valid Data
```
street_address: 456 Park Avenue
province: Calabarzon
city: Laguna
barangay: (empty)
recipient_name: Maria Santos
mobile_number: 09281234567
delivery_email: maria@example.com
agent_code: (empty)
delivery_date: 2024-01-20
delivery_time: 10:00 AM
```

### Test Case 3: Invalid Mobile (Should Fail)
```
mobile_number: 0917123456 (only 10 digits - INVALID)
```

### Test Case 4: Invalid Email (Should Fail)
```
delivery_email: notanemail (missing @ and domain - INVALID)
```

### Test Case 5: Past Date (Should Fail)
```
delivery_date: 2024-01-01 (past date - INVALID)
```

---

## Response Handling

### Success Response
```php
header('Location: preorder.php?step=3');
// Stores pre_order_id in session
// User proceeds to payment
```

### Error Response
```php
header('Location: preorder.php?step=2&error=validation');
// Displays error messages
// User corrects and resubmits
```

---

## Summary

**Form Fields**: 10 (8 required, 2 optional)
**Validation Rules**: 8 checks
**Database Columns**: 10 new columns
**Security**: Prepared statements + sanitization
**Testing**: 5+ test cases
**Documentation**: Complete with examples

**Status**: ✅ Ready for backend implementation

