-- Pre-Order/Advance Reservation System Database Migration

-- Create pre_orders table
CREATE TABLE IF NOT EXISTS pre_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    reservation_date DATE NOT NULL,
    preferred_pickup_date DATE NOT NULL,
    preferred_pickup_time VARCHAR(50),
    pickup_location VARCHAR(255),
    delivery_address TEXT,
    delivery_method ENUM('pickup', 'delivery') DEFAULT 'pickup',
    special_instructions TEXT,
    payment_type ENUM('full_payment', 'downpayment') DEFAULT 'full_payment',
    downpayment_amount DECIMAL(10, 2),
    remaining_amount DECIMAL(10, 2),
    downpayment_status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    final_payment_status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    downpayment_paid_at DATETIME,
    final_payment_paid_at DATETIME,
    reservation_status ENUM('pending', 'confirmed', 'in_preparation', 'ready_for_pickup', 'completed', 'cancelled') DEFAULT 'pending',
    cancellation_reason TEXT,
    cancelled_at DATETIME,
    notes TEXT,
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX (user_id),
    INDEX (product_id),
    INDEX (reservation_status),
    INDEX (preferred_pickup_date)
);

-- Create pre_order_payments table to track all payments
CREATE TABLE IF NOT EXISTS pre_order_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pre_order_id INT NOT NULL,
    payment_type ENUM('downpayment', 'final_payment') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255),
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    payment_gateway ENUM('paymongo', 'bank_transfer', 'cash') DEFAULT 'paymongo',
    paid_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_order_id) REFERENCES pre_orders(id),
    INDEX (pre_order_id),
    INDEX (payment_status)
);

-- Create pre_order_notifications table
CREATE TABLE IF NOT EXISTS pre_order_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    pre_order_id INT NOT NULL,
    user_id INT NOT NULL,
    notification_type ENUM('confirmation', 'payment_reminder', 'ready_for_pickup', 'cancellation', 'completion') NOT NULL,
    title VARCHAR(255),
    message TEXT,
    email_sent BOOLEAN DEFAULT FALSE,
    sms_sent BOOLEAN DEFAULT FALSE,
    sent_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_order_id) REFERENCES pre_orders(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX (pre_order_id),
    INDEX (user_id)
);

-- Add indexes for better query performance
CREATE INDEX idx_preorder_status ON pre_orders(reservation_status);
CREATE INDEX idx_preorder_date ON pre_orders(preferred_pickup_date);
CREATE INDEX idx_preorder_user ON pre_orders(user_id);
