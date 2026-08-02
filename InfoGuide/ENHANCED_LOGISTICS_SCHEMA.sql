-- Enhanced Logistics Management System - Database Schema
-- Run this in phpMyAdmin to update logistics tables with advanced features

-- ============================================
-- ALTER logistics_tracking TABLE
-- ============================================
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS proof_of_delivery_path VARCHAR(255) AFTER notes;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS proof_of_delivery_timestamp DATETIME AFTER proof_of_delivery_path;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS customer_signature_path VARCHAR(255) AFTER proof_of_delivery_timestamp;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS customer_name_confirmed VARCHAR(100) AFTER customer_signature_path;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS delivery_notes TEXT AFTER customer_name_confirmed;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS failed_reason VARCHAR(255) AFTER delivery_notes;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS failed_timestamp DATETIME AFTER failed_reason;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS attempts INT DEFAULT 0 AFTER failed_timestamp;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS last_attempt_timestamp DATETIME AFTER attempts;
ALTER TABLE logistics_tracking ADD COLUMN IF NOT EXISTS automatic_assignment BOOLEAN DEFAULT FALSE AFTER last_attempt_timestamp;

-- ============================================
-- CREATE proof_of_delivery TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS proof_of_delivery (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT NOT NULL,
    order_id INT NOT NULL,
    driver_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    thumbnail_path VARCHAR(255),
    signature_path VARCHAR(255),
    location_latitude DECIMAL(10, 8),
    location_longitude DECIMAL(11, 8),
    delivery_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    customer_confirmed BOOLEAN DEFAULT FALSE,
    customer_feedback TEXT,
    delivery_condition VARCHAR(100) COMMENT 'good, damaged, incomplete, etc',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX (tracking_id),
    INDEX (order_id),
    INDEX (driver_id),
    INDEX (delivery_time)
);

-- ============================================
-- CREATE driver_assignment_history TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS driver_assignment_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT NOT NULL,
    order_id INT NOT NULL,
    driver_id INT,
    assignment_method ENUM('automatic', 'manual', 'system_reassign') DEFAULT 'manual',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assignment_score INT COMMENT 'Score determining suitability for assignment 0-100',
    assignment_criteria JSON COMMENT 'Details of criteria used for assignment',
    reason_if_unassigned TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX (tracking_id),
    INDEX (order_id),
    INDEX (driver_id),
    INDEX (assigned_at)
);

-- ============================================
-- CREATE driver_availability TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS driver_availability (
    id INT PRIMARY KEY AUTO_INCREMENT,
    driver_id INT NOT NULL,
    date DATE NOT NULL,
    available_from TIME,
    available_to TIME,
    max_deliveries_per_day INT DEFAULT 10,
    current_deliveries_count INT DEFAULT 0,
    is_available BOOLEAN DEFAULT TRUE,
    status ENUM('available', 'on_break', 'offline', 'unavailable') DEFAULT 'available',
    last_location_latitude DECIMAL(10, 8),
    last_location_longitude DECIMAL(11, 8),
    last_location_update TIMESTAMP NULL,
    current_order_count INT DEFAULT 0,
    estimated_completion_time DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_driver_date (driver_id, date),
    INDEX (date),
    INDEX (is_available),
    INDEX (status)
);

-- ============================================
-- CREATE driver_delivery_stats TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS driver_delivery_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    driver_id INT NOT NULL,
    total_deliveries INT DEFAULT 0,
    successful_deliveries INT DEFAULT 0,
    failed_deliveries INT DEFAULT 0,
    cancelled_deliveries INT DEFAULT 0,
    avg_rating DECIMAL(3, 2) DEFAULT 5.0,
    total_reviews INT DEFAULT 0,
    avg_delivery_time_minutes INT DEFAULT 0,
    total_distance_km DECIMAL(10, 2) DEFAULT 0,
    total_earnings DECIMAL(10, 2) DEFAULT 0,
    success_rate DECIMAL(5, 2) DEFAULT 100.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_driver (driver_id),
    INDEX (avg_rating),
    INDEX (success_rate)
);

-- ============================================
-- CREATE delivery_route_optimization TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS delivery_route_optimization (
    id INT PRIMARY KEY AUTO_INCREMENT,
    driver_id INT NOT NULL,
    date DATE NOT NULL,
    planned_route JSON COMMENT 'Array of order IDs in optimized sequence',
    estimated_total_distance_km DECIMAL(10, 2),
    estimated_total_time_minutes INT,
    actual_total_distance_km DECIMAL(10, 2),
    actual_total_time_minutes INT,
    efficiency_percentage DECIMAL(5, 2),
    optimization_algorithm VARCHAR(100),
    route_status ENUM('optimized', 'in_progress', 'completed', 'partial') DEFAULT 'optimized',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX (date),
    INDEX (route_status)
);

-- ============================================
-- CREATE delivery_ratings TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS delivery_ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT NOT NULL,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    driver_id INT NOT NULL,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    categories JSON COMMENT 'e.g., punctuality, professionalism, condition_of_food',
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review (tracking_id, user_id),
    INDEX (driver_id),
    INDEX (created_at)
);

-- ============================================
-- CREATE order_status_log TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS order_status_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT COMMENT 'user_id of who made the change',
    reason TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX (order_id),
    INDEX (created_at)
);

-- ============================================
-- ALTER orders TABLE
-- ============================================
ALTER TABLE orders ADD COLUMN IF NOT EXISTS estimated_delivery_time DATETIME AFTER delivery_time;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS actual_delivery_time DATETIME AFTER estimated_delivery_time;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_instructions TEXT AFTER actual_delivery_time;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_coordinates JSON AFTER delivery_instructions;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS is_high_priority BOOLEAN DEFAULT FALSE AFTER customer_coordinates;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS has_proof_of_delivery BOOLEAN DEFAULT FALSE AFTER is_high_priority;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_rating INT AFTER has_proof_of_delivery;

-- ============================================
-- CREATE employees_geo_tracking TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employees_geo_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    current_latitude DECIMAL(10, 8),
    current_longitude DECIMAL(11, 8),
    current_location_address VARCHAR(255),
    accuracy_meters FLOAT,
    battery_percentage INT,
    tracking_status ENUM('active', 'inactive', 'low_battery') DEFAULT 'active',
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_employee_tracking (employee_id),
    INDEX (last_update),
    INDEX (tracking_status)
);

-- ============================================
-- UPDATE indexes for better performance
-- ============================================
CREATE INDEX IF NOT EXISTS idx_logistics_order_status ON logistics_tracking(order_id, current_status);
CREATE INDEX IF NOT EXISTS idx_logistics_driver_status ON logistics_tracking(driver_id, current_status);
CREATE INDEX IF NOT EXISTS idx_logistics_timestamp ON logistics_tracking(created_at, updated_at);
CREATE INDEX IF NOT EXISTS idx_orders_delivery_option ON orders(delivery_option, status);
CREATE INDEX IF NOT EXISTS idx_orders_delivery_date ON orders(delivery_date);
CREATE INDEX IF NOT EXISTS idx_orders_user_status ON orders(user_id, status);

-- ============================================
-- CREATE logistics_audit_log TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS logistics_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT,
    order_id INT,
    action VARCHAR(100),
    actor_type ENUM('system', 'admin', 'driver', 'customer') DEFAULT 'system',
    actor_id INT,
    old_value TEXT,
    new_value TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX (action),
    INDEX (actor_type),
    INDEX (created_at)
);

-- ============================================
-- UPDATE customer_notifications TABLE
-- ============================================
ALTER TABLE customer_notifications ADD COLUMN IF NOT EXISTS priority ENUM('low', 'normal', 'high') DEFAULT 'normal' AFTER sent_at;
ALTER TABLE customer_notifications ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0 AFTER priority;
ALTER TABLE customer_notifications ADD COLUMN IF NOT EXISTS last_retry_at DATETIME AFTER retry_count;

-- ============================================
-- Create automation_rules TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS automation_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rule_name VARCHAR(100) NOT NULL,
    rule_type ENUM('driver_assignment', 'status_update', 'notification', 'payment') DEFAULT 'driver_assignment',
    trigger_condition JSON,
    action_sequence JSON,
    is_active BOOLEAN DEFAULT TRUE,
    priority INT DEFAULT 0,
    execution_count INT DEFAULT 0,
    last_execution DATETIME,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (rule_type),
    INDEX (is_active),
    INDEX (priority)
);

-- ============================================
-- Insert sample automation rules
-- ============================================
INSERT INTO automation_rules (rule_name, rule_type, trigger_condition, action_sequence, is_active, priority) VALUES
(
    'Auto-assign delivery orders',
    'driver_assignment',
    JSON_OBJECT('event', 'order_confirmed', 'delivery_option', 'delivery'),
    JSON_ARRAY(
        JSON_OBJECT('action', 'find_nearest_driver', 'params', JSON_OBJECT('max_distance_km', 10)),
        JSON_OBJECT('action', 'assign_driver', 'params', JSON_OBJECT('notify', true)),
        JSON_OBJECT('action', 'send_notification', 'params', JSON_OBJECT('type', 'driver_assigned'))
    ),
    TRUE,
    10
),
(
    'Auto-update status to in_progress',
    'status_update',
    JSON_OBJECT('event', 'order_confirmed', 'delivery_option', 'delivery'),
    JSON_ARRAY(
        JSON_OBJECT('action', 'update_order_status', 'params', JSON_OBJECT('status', 'confirmed')),
        JSON_OBJECT('action', 'send_notification', 'params', JSON_OBJECT('type', 'order_confirmed'))
    ),
    TRUE,
    5
),
(
    'Notify customer on driver assignment',
    'notification',
    JSON_OBJECT('event', 'driver_assigned'),
    JSON_ARRAY(
        JSON_OBJECT('action', 'send_sms', 'params', JSON_OBJECT('template', 'driver_assigned_sms')),
        JSON_OBJECT('action', 'send_email', 'params', JSON_OBJECT('template', 'driver_assigned_email'))
    ),
    TRUE,
    8
);
