# Step 2 Form - Field Reference & Backend Integration

## HTML Field IDs and Names

| HTML ID | HTML Name | Input Type | Database Column | Data Type | Validation |
|---------|-----------|-----------|-----------------|-----------|-----------|
| street_address | street_address | text | street_address | VARCHAR(255) | Required, 1-255 chars |
| province | province | select | province | VARCHAR(50) | Required, must be selected |
| city | city | select | city | VARCHAR(50) | Required, must be selected |
| barangay | barangay | select | barangay | VARCHAR(50) | Optional |
| recipient_name | recipient_name | text | recipient_name | VARCHAR(100) | Required, 1-100 chars |
| mobile_number | mobile_number | tel | mobile_number | VARCHAR(11) | Required, 09XXXXXXXXX |
| delivery_email | delivery_email | email | delivery_email | VARCHAR(100) | Required, valid email |
| agent_code | agent_code | text | agent_code | VARCHAR(50) | Optional, alphanumeric |
| deliveryDate | delivery_date | date | delivery_date | DATE | Required, today+ |
| deliveryTime | delivery_time | select | delivery_time | VARCHAR(10) | Required, selected |

---

## Form Submission Handler Template

### PHP Backend Code (preorder_handler.php or equivalent)

```php
<?php
session_start();
require_once 'includes/config.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Sanitize and validate input
    $street_address = mysqli_real_escape_string($conn, $_POST['street_address'] ?? '');
    $province = mysqli_real_escape_string($conn, $_POST['province'] ?? '');
    $city = mysqli_real_escape_string($conn, $_POST['city'] ?? '');
    $barangay = mysqli_real_escape_string($conn, $_POST['barangay'] ?? '');
    $recipient_name = mysqli_real_escape_string($conn, $_POST['recipient_name'] ?? '');
    $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number'] ?? '');
    $delivery_email = mysqli_real_escape_string($conn, $_POST['delivery_email'] ?? '');
    $agent_code = mysqli_real_escape_string($conn, $_POST['agent_code'] ?? '');
    $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date'] ?? '');
    $delivery_time = mysqli_real_escape_string($conn, $_POST['delivery_time'] ?? '');
    
    // Validate required fields
    $errors = [];
    
    if (empty($street_address)) {
        $errors[] = 'Street address is required';
    }
    
    if (empty($province)) {
        $errors[] = 'Province is required';
    }
    
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    
    if (empty($recipient_name)) {
        $errors[] = 'Recipient name is required';
    }
    
    if (empty($mobile_number) || !preg_match('/^09\d{9}$/', $mobile_number)) {
        $errors[] = 'Valid mobile number (09xxxxxxxxx) is required';
    }
    
    if (empty($delivery_email) || !filter_var($delivery_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required';
    }
    
    if (empty($delivery_date) || strtotime($delivery_date) < strtotime('today')) {
        $errors[] = 'Valid delivery date is required';
    }
    
    if (empty($delivery_time)) {
        $errors[] = 'Delivery time is required';
    }
    
    // If there are errors, return them
    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
        header('Location: preorder.php?step=2');
        exit();
    }
    
    // Get pre-order details from session or POST
    $product_id = $_SESSION['selected_product_id'] ?? $_POST['product_id'] ?? null;
    $quantity = $_SESSION['product_quantity'] ?? $_POST['quantity'] ?? 1;
    $user_id = $_SESSION['user_id'] ?? null;
    
    // Calculate total (product price * quantity)
    $product_query = "SELECT price FROM products WHERE product_id = ?";
    $stmt = mysqli_prepare($conn, $product_query);
    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($result);
    
    if (!$product) {
        $_SESSION['form_errors'] = ['Product not found'];
        header('Location: preorder.php?step=1');
        exit();
    }
    
    $price_per_unit = $product['price'];
    $total_amount = $price_per_unit * $quantity;
    $delivery_fee = 150.00; // Or calculate based on city
    
    // Insert pre-order into database
    $insert_query = "INSERT INTO pre_orders (
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
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
    
    $stmt = mysqli_prepare($conn, $insert_query);
    
    if (!$stmt) {
        $_SESSION['form_errors'] = ['Database error: ' . mysqli_error($conn)];
        header('Location: preorder.php?step=2');
        exit();
    }
    
    mysqli_stmt_bind_param(
        $stmt,
        'iiiddsssssssss',
        $user_id,
        $product_id,
        $quantity,
        $total_amount,
        $delivery_fee,
        $street_address,
        $province,
        $city,
        $barangay,
        $recipient_name,
        $mobile_number,
        $delivery_email,
        $agent_code,
        $delivery_date,
        $delivery_time
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        $_SESSION['form_errors'] = ['Failed to create pre-order: ' . mysqli_stmt_error($stmt)];
        header('Location: preorder.php?step=2');
        exit();
    }
    
    $pre_order_id = mysqli_insert_id($conn);
    
    // Store pre-order ID in session for next steps (payment, etc.)
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
    
    // Redirect to payment step (Step 3)
    header('Location: preorder.php?step=3');
    exit();
}

// If GET request, show form
header('Location: preorder.php?step=2');
exit();
```

---

## Database Schema Update

### SQL to add columns to pre_orders table:

```sql
-- Add delivery information columns to pre_orders table
ALTER TABLE pre_orders ADD COLUMN street_address VARCHAR(255) AFTER delivery_fee;
ALTER TABLE pre_orders ADD COLUMN province VARCHAR(50) AFTER street_address;
ALTER TABLE pre_orders ADD COLUMN city VARCHAR(50) AFTER province;
ALTER TABLE pre_orders ADD COLUMN barangay VARCHAR(50) AFTER city;
ALTER TABLE pre_orders ADD COLUMN recipient_name VARCHAR(100) AFTER barangay;
ALTER TABLE pre_orders ADD COLUMN mobile_number VARCHAR(11) AFTER recipient_name;
ALTER TABLE pre_orders ADD COLUMN delivery_email VARCHAR(100) AFTER mobile_number;
ALTER TABLE pre_orders ADD COLUMN agent_code VARCHAR(50) AFTER delivery_email;
ALTER TABLE pre_orders ADD COLUMN delivery_date DATE AFTER agent_code;
ALTER TABLE pre_orders ADD COLUMN delivery_time VARCHAR(10) AFTER delivery_date;

-- Create indexes for faster queries
CREATE INDEX idx_delivery_date ON pre_orders(delivery_date);
CREATE INDEX idx_province_city ON pre_orders(province, city);
CREATE INDEX idx_mobile ON pre_orders(mobile_number);
```

### Complete pre_orders table structure (after update):

```sql
CREATE TABLE pre_orders (
    pre_order_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    total_amount DECIMAL(10, 2),
    delivery_fee DECIMAL(10, 2) DEFAULT 150.00,
    
    -- Delivery Information (NEW)
    street_address VARCHAR(255),
    province VARCHAR(50),
    city VARCHAR(50),
    barangay VARCHAR(50),
    recipient_name VARCHAR(100),
    mobile_number VARCHAR(11),
    delivery_email VARCHAR(100),
    agent_code VARCHAR(50),
    delivery_date DATE,
    delivery_time VARCHAR(10),
    
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    payment_type ENUM('full', 'downpayment') DEFAULT 'full',
    payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);
```

---

## Form Data Example

### Sample Data Being Submitted:

```json
{
  "street_address": "123 Main Street, Unit 4B, Building A",
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

### Database INSERT query:

```sql
INSERT INTO pre_orders (
    user_id, product_id, quantity, total_amount, delivery_fee,
    street_address, province, city, barangay,
    recipient_name, mobile_number, delivery_email, agent_code,
    delivery_date, delivery_time, status, payment_type, payment_status
) VALUES (
    1, 5, 2, 998.00, 150.00,
    '123 Main Street, Unit 4B, Building A', 'Metro Manila', 'Makati', 'Barangay 1',
    'Juan Dela Cruz', '09171234567', 'juan@email.com', 'AGENT2024001',
    '2024-01-15', '2:00 PM', 'pending', 'full', 'pending'
);
```

---

## Form Rendering in HTML

The form is rendered by JavaScript in preorder.php at lines 798-893. The form structure:

```html
<form id="preorderForm" method="POST" action="preorder_handler.php">
    
    <!-- Product info (from Step 1) -->
    <input type="hidden" name="product_id" value="[product_id]">
    <input type="hidden" name="quantity" value="[quantity]">
    
    <!-- Step 2: Delivery Information -->
    <h4>Step 2: Delivery Information</h4>
    
    <!-- Address Section -->
    <h5>Delivery Address</h5>
    <input type="text" id="street_address" name="street_address" required>
    <select id="province" name="province" required></select>
    <select id="city" name="city" required></select>
    <select id="barangay" name="barangay"></select>
    
    <!-- Contact Section -->
    <h5>Contact Information</h5>
    <input type="text" id="recipient_name" name="recipient_name" required>
    <input type="tel" id="mobile_number" name="mobile_number" required>
    <input type="email" id="delivery_email" name="delivery_email" required>
    <input type="text" id="agent_code" name="agent_code">
    
    <!-- Schedule Section -->
    <h5>Delivery Schedule</h5>
    <input type="date" id="deliveryDate" name="delivery_date" required>
    <select id="deliveryTime" name="delivery_time" required></select>
    
    <!-- Navigation -->
    <button type="button" onclick="previousStep()">Back</button>
    <button type="button" onclick="nextStep()">Next</button>
</form>
```

---

## Form Submission Flow

```
User Fills Form (Step 2)
        ↓
Client-side Validation (JavaScript - validateStep(2))
        ↓
If Valid → Form Submitted to preorder_handler.php
        ↓
Server-side Validation (PHP)
        ↓
If Valid → Insert into pre_orders table
        ↓
Store data in $_SESSION
        ↓
Redirect to Step 3 (Payment)
        ↓
User Selects Payment Type
        ↓
Proceed to Step 4 (Confirmation)
        ↓
Process Payment (PayMongo)
        ↓
Order Confirmation & Email
```

---

## Error Handling

### Client-Side Errors (SweetAlert2)
Shown immediately when user clicks "Next" without valid data:
- Displayed as modal dialog
- Clear error message
- User stays on current step
- Can correct and retry

### Server-Side Errors (PHP)
Shown if JavaScript validation is bypassed or if database error occurs:
- Errors stored in `$_SESSION['form_errors']`
- User redirected back to form
- Errors displayed to user
- Can correct and resubmit

### Database Errors
If INSERT fails:
- MySQL error logged
- User shown generic error message
- Admin notified via logs
- Pre-order transaction rolled back

---

## Email Template Update

### Update Email Service for Delivery Info

In `email_service.php`, update `sendOrderConfirmation()`:

```php
// Add delivery information to email body
$deliverySection = "
    <h3>Delivery Information</h3>
    <p>
        <strong>Recipient:</strong> {$delivery_details['recipient_name']}<br>
        <strong>Address:</strong> {$delivery_details['street_address']}<br>
        {$delivery_details['city']}, {$delivery_details['province']}<br>
        <strong>Barangay:</strong> {$delivery_details['barangay']}<br>
        <strong>Contact:</strong> {$delivery_details['mobile_number']}<br>
        <strong>Email:</strong> {$delivery_details['delivery_email']}<br>
        <strong>Scheduled Delivery:</strong> {$delivery_details['delivery_date']} at {$delivery_details['delivery_time']}
    </p>
";

// Include in email body
$emailBody = "
    <h2>Order Confirmation</h2>
    ...existing order info...
    $deliverySection
    ...payment info...
";
```

---

## Admin Panel Updates

### Update Order Details Modal (get_order_details.php)

```php
// Show delivery information
$query = "SELECT * FROM pre_orders WHERE pre_order_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $pre_order_id);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Display in admin panel
echo "<div class='delivery-info'>";
echo "<h5>Delivery Information</h5>";
echo "<p><strong>Recipient:</strong> " . htmlspecialchars($order['recipient_name']) . "</p>";
echo "<p><strong>Address:</strong> " . htmlspecialchars($order['street_address']) . "</p>";
echo "<p><strong>Location:</strong> " . htmlspecialchars($order['barangay']) . ", " . 
     htmlspecialchars($order['city']) . ", " . htmlspecialchars($order['province']) . "</p>";
echo "<p><strong>Contact:</strong> " . htmlspecialchars($order['mobile_number']) . "</p>";
echo "<p><strong>Delivery Date/Time:</strong> " . $order['delivery_date'] . " at " . $order['delivery_time'] . "</p>";
echo "</div>";
```

---

## Testing Queries

### Verify data was inserted:
```sql
SELECT * FROM pre_orders WHERE user_id = 1 ORDER BY created_at DESC LIMIT 1;
```

### Check all deliveries for a specific date:
```sql
SELECT recipient_name, city, mobile_number, delivery_time 
FROM pre_orders 
WHERE delivery_date = '2024-01-15'
ORDER BY delivery_time;
```

### Find deliveries by city:
```sql
SELECT COUNT(*) as total_deliveries, city, delivery_date
FROM pre_orders 
WHERE status IN ('confirmed', 'pending')
GROUP BY city, delivery_date;
```

### Find deliveries by agent code:
```sql
SELECT * FROM pre_orders 
WHERE agent_code = 'AGENT2024001' 
AND delivery_date >= CURDATE();
```

---

## Performance Optimization

### Indexes to add:
```sql
CREATE INDEX idx_delivery_date ON pre_orders(delivery_date);
CREATE INDEX idx_city ON pre_orders(city);
CREATE INDEX idx_province ON pre_orders(province);
CREATE INDEX idx_agent_code ON pre_orders(agent_code);
CREATE INDEX idx_mobile ON pre_orders(mobile_number);
CREATE INDEX idx_user_delivery ON pre_orders(user_id, delivery_date);
```

---

## Security Notes

1. **SQL Injection Prevention**: Use prepared statements with mysqli_stmt_bind_param()
2. **XSS Prevention**: Always use htmlspecialchars() when displaying user input
3. **Input Validation**: Validate on both client (JS) and server (PHP)
4. **Phone Number**: Regex pattern enforces Philippine format
5. **Email**: Use filter_var() with FILTER_VALIDATE_EMAIL
6. **Agent Code**: Whitelist if integrating with agent system
7. **Barangay**: Even optional fields should be sanitized

---

## Summary

The Step 2 form collects complete delivery information through:
✅ 10 form fields (8 required, 2 optional)
✅ 3-section organization (Address, Contact, Schedule)
✅ Cascading dropdowns (Province → City → Barangay)
✅ Client-side validation (JavaScript)
✅ Server-side validation (PHP)
✅ Database storage (pre_orders table)
✅ Email confirmation (updated template)
✅ Admin visibility (order details modal)

**Ready for backend integration!**
