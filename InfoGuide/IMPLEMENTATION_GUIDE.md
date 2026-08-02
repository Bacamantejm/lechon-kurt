# Enhanced Lechon System - Integrated Logistics & Ordering System
## Complete Implementation Guide

---

## 📋 Overview

This documentation covers the complete enhanced logistics and ordering system that interconnects:
- **menu.php** - Customer ordering interface
- **checkout.php** - Payment & delivery selection
- **process_order_enhanced.php** - Order creation & logistics initialization
- **payment_success_enhanced.php** - Automatic driver assignment
- **employee/logistics.php** - Driver delivery management with POD
- **admin/logistics.php** - Advanced logistics dashboard

---

## 🏗️ System Architecture

### Flow Diagram
```
Customer Order Flow:
Menu (Select items)
    ↓
Checkout (Select delivery & pay)
    ↓
Process Order (Create order & tracking)
    ↓
PayMongo Payment
    ↓
Payment Success (Auto-assign driver)
    ↓
Driver Notification
    ↓
Driver App (Accept & deliver)
    ↓
Proof of Delivery (Photo + Signature)
    ↓
Order Completion & Rating
```

---

## 🗄️ Database Schema Enhancements

### New Tables Created:
1. **logistics_tracking** - Enhanced with:
   - `proof_of_delivery_path`, `proof_of_delivery_timestamp`
   - `customer_signature_path`, `customer_name_confirmed`
   - `automatic_assignment`, `failed_reason`, `attempts`

2. **proof_of_delivery** - Photo & signature storage
   - Tracks photos, signatures, location, condition
   - Customer feedback & verification

3. **driver_assignment_history** - Tracks all assignments
   - Assignment method (automatic/manual/reassign)
   - Assignment score and criteria used

4. **driver_availability** - Driver capacity management
   - Date-based availability tracking
   - Current delivery count vs max capacity
   - Driver status (available/on_break/offline)

5. **driver_delivery_stats** - Performance metrics
   - Total/successful/failed deliveries
   - Average rating, success rate, earnings
   - Average delivery time, total distance

6. **delivery_route_optimization** - Route planning
   - Planned vs actual routes & performance
   - Efficiency metrics

7. **delivery_ratings** - Customer feedback
   - Star ratings and reviews
   - Category-based ratings (punctuality, etc.)

8. **order_status_log** - Audit trail
   - Tracks all status changes
   - Who changed it and when

### Execute Schema Updates:
```bash
# Run this SQL file to update your database:
InfoGuide/ENHANCED_LOGISTICS_SCHEMA.sql
```

---

## 🔧 Core Components

### 1. EnhancedLogisticsService.php

**Main Methods:**

```php
// Create tracking for new delivery order
autoAssignDriver($order_id, $address, $latitude, $longitude, $algorithm)
// Returns: ['success' => bool, 'driver_id' => int, 'driver_name' => string]

// Update delivery status in real-time
updateTrackingStatus($tracking_id, $new_status, $lat, $lon, $proof_path)
// Status: pending → assigned → picked_up → on_the_way → arriving → delivered

// Upload proof of delivery
uploadProofOfDelivery($tracking_id, $order_id, $driver_id, $photo_path, ...)
// Stores photo, signature, location, condition

// Get driver's active deliveries
getDeliveriesForDriver($driver_id, $status)
// Returns array of assigned deliveries

// Update driver performance stats
updateDriverStats($driver_id, $delivery_successful, $time_minutes, $distance_km)
```

**Assignment Algorithms:**
- `NEAREST_DRIVER` - Proximity-based (nearest store)
- `LOAD_BALANCED` - Lowest current delivery count
- `RATING_BASED` - Highest average rating
- `HYBRID` - Combined scoring (default)

---

## 📝 Implementation Steps

### Step 1: Database Setup
```php
// Run in phpMyAdmin or terminal:
mysql -u root -p lechon_db < InfoGuide/ENHANCED_LOGISTICS_SCHEMA.sql
```

### Step 2: Update Checkout Flow
```php
// In checkout.php - Form submission:
<form action="process_order_enhanced.php" method="POST">
    <!-- existing fields -->
    <input type="hidden" name="latitude" id="latitude">
    <input type="hidden" name="longitude" id="longitude">
</form>
```

### Step 3: Integrate Enhanced Order Processing
```php
// Replace process_order.php calls with:
require_once 'process_order_enhanced.php';
// This automatically creates tracking and assigns drivers
```

### Step 4: Handle Payment Success
```php
// After PayMongo success:
// Redirect to: payment_success_enhanced.php?order_id=123
// This triggers automatic driver assignment
```

### Step 5: Employee App Integration
```php
// In employee/logistics.php:
require_once 'proof_of_delivery_modal.php';
// Driver marks as delivered with photo + signature
```

### Step 6: Admin Dashboard
```php
// Include in admin/logistics.php:
<script src="logistics_dashboard_enhanced.js"></script>
// Provides real-time tracking and management
```

---

## 🤖 Automatic Driver Assignment Logic

### Hybrid Algorithm (Default)
```
Score each available driver:
- Proximity: 40% weight
  Nearest drivers to delivery location
  
- Load Balance: 30% weight
  Drivers with fewest current deliveries
  
- Rating: 30% weight
  Drivers with highest customer ratings

Select top-scored driver
Assign order automatically
Send notifications to customer & driver
```

### Selection Criteria
- Active status in Delivery department
- Not at max capacity for the day
- No conflicting delivery assignments
- Minimum 4.0 rating (configurable)
- 85%+ success rate (configurable)

---

## 📱 Proof of Delivery Features

### Photo Capture
- Real-time camera access
- Auto-upload to server
- Thumbnail generation for preview
- Fallback file upload option

### Signature Capture
- Canvas-based signature pad
- High-resolution signature image
- Optional (customer can decline)

### Location Tracking
- GPS coordinates captured at delivery
- Accuracy measurement
- Can use for dispute resolution

### Condition Assessment
- Good (no damage)
- Minor damage
- Major damage  
- Incomplete items
- Other

### Delivery Notes
- Free-text field for special notes
- Customer name confirmation
- Timestamp & location recorded

---

## 📊 Admin Dashboard Features

### Real-Time Monitoring
```
Dashboard displays:
- Total deliveries (today)
- Active deliveries in progress
- Completed deliveries
- Failed deliveries
- Average customer rating
- Success rate percentage
```

### Delivery Management
- View active delivery list
- Click "Track" to see live map
- "Auto Assign" - let system assign best driver
- "Manual Assign" - choose specific driver
- "Reassign" - change driver for order

### Driver Management
- List all available drivers
- See current workload (X/10 deliveries)
- View average rating and success rate
- Click on driver for detailed performance

### Live Map View
- Real-time driver locations
- Color-coded status
  - Green: Available
  - Yellow: On Delivery
  - Red: Busy/Offline
- Click markers for driver info

---

## 🔐 Error Handling & Validation

### Order Validation
```php
use OrderValidator;

OrderValidator::validateDeliveryAddress($address);
OrderValidator::validatePhoneNumber($phone);
OrderValidator::validateCoordinates($lat, $lon);
OrderValidator::validateDeliveryOption($option);
OrderValidator::validatePaymentType($type);
```

### Error Recovery
- Transaction rollbacks on failure
- Graceful degradation (manual assignment if auto fails)
- Retry logic for failed assignments
- Detailed error logging
- User-friendly error messages

---

## 📧 Notification System

### Events Triggering Notifications
1. Order Confirmed
2. Driver Assigned
3. Driver Picked Up Items
4. Driver On The Way
5. Driver Arriving
6. Order Delivered
7. Delivery Failed

### Channels
- **In-App**: Notification bell in portal
- **SMS**: Text message to customer
- **Email**: Order status updates

### Preferences
Users can customize notifications in account settings:
- Enable/disable by channel
- Choose which events to be notified about

---

## 🚀 Performance Optimization

### Database Indexes
```sql
CREATE INDEX idx_logistics_order_status ON logistics_tracking(order_id, current_status);
CREATE INDEX idx_driver_availability ON driver_availability(driver_id, date);
CREATE INDEX idx_pod_tracking ON proof_of_delivery(tracking_id, delivery_time);
```

### Caching
- Driver availability cached (30-second refresh)
- Order statistics cached (1-minute refresh)
- Route optimization results cached (5-minute refresh)

### Batch Operations
- Assignment runs as scheduled job (every 30 seconds)
- Stats update batched hourly
- Notifications batched every 5 minutes

---

## 🐛 Troubleshooting

### Issue: Drivers not auto-assigning
**Solution:**
1. Check if drivers exist in employees table
2. Verify driver department is "Delivery"
3. Check driver_availability table for capacity
4. Check logistics_assignment_history for errors
5. Review PHP error logs

### Issue: POD photos not uploading
**Solution:**
1. Ensure `uploads/proof_of_delivery/` directory exists
2. Check directory permissions (755)
3. Verify max file size in php.ini
4. Check browser console for CORS errors

### Issue: Notifications not sending
**Solution:**
1. Verify SMS gateway credentials
2. Check email service configuration
3. Verify customer notification preferences
4. Check customer_notifications table for records

---

## 📈 Reporting & Analytics

### Driver Performance Report
```php
$driver_id = 5;
$stats = $logistics_service->getDriverStats($driver_id);
// Returns: total_deliveries, success_rate, avg_rating, earnings, etc.
```

### Daily Delivery Report
```sql
SELECT 
    DATE(created_at) as delivery_date,
    COUNT(*) as total_deliveries,
    SUM(CASE WHEN current_status='delivered' THEN 1 ELSE 0 END) as completed,
    AVG(TIMEDIFF(delivery_time, created_at)) as avg_time
FROM logistics_tracking
GROUP BY DATE(created_at)
```

### Revenue Analysis
```sql
SELECT 
    driver_id,
    SUM(o.total_amount) as revenue,
    COUNT(*) as delivery_count,
    AVG(o.total_amount) as avg_order_value
FROM logistics_tracking lt
JOIN orders o ON lt.order_id = o.id
WHERE DATE(lt.created_at) = CURDATE() AND lt.current_status='delivered'
GROUP BY driver_id
ORDER BY revenue DESC
```

---

## 🔄 Integration Checklist

- [ ] Run database migration script (ENHANCED_LOGISTICS_SCHEMA.sql)
- [ ] Update checkout.php to include coordinates
- [ ] Replace process_order.php with process_order_enhanced.php
- [ ] Update payment success handler to payment_success_enhanced.php
- [ ] Include POD modal in employee/logistics.php
- [ ] Set up proof_of_delivery API endpoint
- [ ] Include admin dashboard JavaScript
- [ ] Test auto-assignment with sample orders
- [ ] Verify SMS/Email notifications
- [ ] Test POD photo upload functionality
- [ ] Set up geolocation tracking for drivers
- [ ] Configure admin access controls
- [ ] Set up scheduled jobs for assignment
- [ ] Implement usage monitoring/logging
- [ ] Train staff on new dashboard

---

## 📞 Support & Contact

For issues or questions:
1. Check error logs in `logs/` directory
2. Review database audit logs
3. Check logistics_audit_log table
4. Contact development team

---

## Version Information
- **Version**: 2.0 (Enhanced)
- **Release Date**: 2026-03-17
- **Compatibility**: PHP 7.4+, MySQL 5.7+
- **Status**: Production Ready

