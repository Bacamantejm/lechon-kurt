-- Logistics Management System - Database Migration
-- Run this in phpMyAdmin to set up logistics tables

-- Create logistics_providers table
CREATE TABLE IF NOT EXISTS logistics_providers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_name VARCHAR(100) NOT NULL UNIQUE,
    api_key VARCHAR(255),
    api_secret VARCHAR(255),
    sandbox_mode BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    base_url VARCHAR(255),
    webhook_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create delivery_methods table
CREATE TABLE IF NOT EXISTS delivery_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    method_name VARCHAR(100) NOT NULL,
    provider_id INT,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provider_id) REFERENCES logistics_providers(id)
);

-- Create logistics_tracking table
CREATE TABLE IF NOT EXISTS logistics_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL UNIQUE,
    tracking_number VARCHAR(100),
    logistics_provider_id INT,
    delivery_method_id INT,
    driver_id VARCHAR(100),
    driver_name VARCHAR(100),
    driver_phone VARCHAR(20),
    driver_vehicle VARCHAR(100),
    current_status ENUM('pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled') DEFAULT 'pending',
    status_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pickup_time DATETIME,
    delivery_time DATETIME,
    estimated_delivery DATETIME,
    current_latitude DECIMAL(10, 8),
    current_longitude DECIMAL(11, 8),
    last_location_update TIMESTAMP NULL,
    special_instructions TEXT,
    external_tracking_id VARCHAR(100),
    external_tracking_url VARCHAR(255),
    total_distance_km DECIMAL(8, 2),
    cost DECIMAL(10, 2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (logistics_provider_id) REFERENCES logistics_providers(id)
);

-- Create logistics_tracking_history table
CREATE TABLE IF NOT EXISTS logistics_tracking_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT NOT NULL,
    status ENUM('pending', 'assigned', 'picked_up', 'on_the_way', 'arriving', 'delivered', 'failed', 'cancelled') NOT NULL,
    status_description TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    external_event_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id)
);

-- Create delivery_driver_assignments table
CREATE TABLE IF NOT EXISTS delivery_driver_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT NOT NULL,
    driver_id VARCHAR(100) NOT NULL,
    driver_name VARCHAR(100),
    driver_phone VARCHAR(20),
    driver_email VARCHAR(100),
    driver_rating DECIMAL(3, 2),
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME,
    started_at DATETIME,
    completed_at DATETIME,
    is_current BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES logistics_tracking(id)
);

-- Create customer_notifications table
CREATE TABLE IF NOT EXISTS customer_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    notification_type ENUM('order_confirmed', 'order_processing', 'driver_assigned', 'driver_on_the_way', 'driver_arriving', 'order_delivered', 'order_failed', 'order_cancelled') NOT NULL,
    title VARCHAR(200),
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    notification_channel ENUM('sms', 'email', 'push', 'in_app') DEFAULT 'in_app',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX (user_id),
    INDEX (order_id),
    INDEX (is_read)
);

-- Create customer_notification_preferences table
CREATE TABLE IF NOT EXISTS customer_notification_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    sms_notifications BOOLEAN DEFAULT TRUE,
    email_notifications BOOLEAN DEFAULT TRUE,
    push_notifications BOOLEAN DEFAULT FALSE,
    in_app_notifications BOOLEAN DEFAULT TRUE,
    notify_on_order_confirmed BOOLEAN DEFAULT TRUE,
    notify_on_processing BOOLEAN DEFAULT TRUE,
    notify_on_driver_assigned BOOLEAN DEFAULT TRUE,
    notify_on_pickup BOOLEAN DEFAULT TRUE,
    notify_on_on_the_way BOOLEAN DEFAULT TRUE,
    notify_on_arriving BOOLEAN DEFAULT TRUE,
    notify_on_delivered BOOLEAN DEFAULT TRUE,
    notify_on_failed BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create logistics_api_logs table
CREATE TABLE IF NOT EXISTS logistics_api_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_name VARCHAR(100),
    request_type VARCHAR(50),
    request_data LONGTEXT,
    response_data LONGTEXT,
    http_status_code INT,
    success BOOLEAN,
    error_message TEXT,
    execution_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (provider_name),
    INDEX (created_at)
);

-- Create food_delivery_integrations table
CREATE TABLE IF NOT EXISTS food_delivery_integrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    platform_name VARCHAR(100) NOT NULL UNIQUE,
    api_key VARCHAR(255),
    api_secret VARCHAR(255),
    restaurant_id VARCHAR(100),
    sandbox_mode BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT FALSE,
    base_url VARCHAR(255),
    webhook_url VARCHAR(255),
    commission_percentage DECIMAL(5, 2),
    min_delivery_fee DECIMAL(8, 2),
    max_delivery_fee DECIMAL(8, 2),
    available_cities LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create food_delivery_orders table
CREATE TABLE IF NOT EXISTS food_delivery_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lechon_order_id INT NOT NULL,
    platform_name VARCHAR(100) NOT NULL,
    external_order_id VARCHAR(100) UNIQUE,
    order_status VARCHAR(50),
    delivery_fee DECIMAL(8, 2),
    platform_commission DECIMAL(8, 2),
    total_amount DECIMAL(10, 2),
    driver_info JSON,
    tracking_url VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lechon_order_id) REFERENCES orders(id)
);

-- Insert default logistics providers
INSERT INTO logistics_providers (provider_name, is_active) VALUES
('In-House Delivery', TRUE),
('FoodPanda', FALSE),
('GrabFood', FALSE);

-- Insert delivery methods for each provider
INSERT INTO delivery_methods (method_name, provider_id, is_active) VALUES
('Standard Delivery', 1, TRUE),
('Express Delivery', 1, TRUE),
('Pickup', 1, TRUE),
('FoodPanda Delivery', 2, FALSE),
('GrabFood Delivery', 3, FALSE);

-- Create index for faster queries
CREATE INDEX idx_tracking_status ON logistics_tracking(current_status);
CREATE INDEX idx_tracking_provider ON logistics_tracking(logistics_provider_id);
CREATE INDEX idx_history_tracking ON logistics_tracking_history(tracking_id);
CREATE INDEX idx_notifications_user ON customer_notifications(user_id);
CREATE INDEX idx_food_delivery_orders ON food_delivery_orders(lechon_order_id);
