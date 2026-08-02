-- sample_platform_seed.sql
-- Purpose:
--   Generate realistic demo data per active Cavite store:
--   - Orders + Order Items
--   - Pre-orders
--   - Product reviews
--   - Delivery reviews
-- Notes:
--   1) Idempotent for demo rows: rerunning replaces only rows tagged with [DEMO-SEED].
--   2) Uses existing stores/products/customers; inserts fallback demo customers if needed.
--   3) Tenant-aware product selection:
--      - Prefer store owner products
--      - Fallback to seller_id = 1 products
--      - Fallback to seller_id IS NULL products
--      - Fallback to any active product

START TRANSACTION;

SET @seed_tag := '[DEMO-SEED]';

-- ---------------------------------------------------------------------------
-- Cleanup prior demo-seed rows
-- ---------------------------------------------------------------------------
DELETE FROM delivery_reviews
WHERE comment LIKE CONCAT(@seed_tag, '%');

DELETE FROM product_reviews
WHERE comment LIKE CONCAT(@seed_tag, '%');

DELETE FROM pre_orders
WHERE notes LIKE CONCAT(@seed_tag, '%');

DELETE oi
FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
WHERE o.special_instructions LIKE CONCAT(@seed_tag, '%');

DELETE FROM orders
WHERE special_instructions LIKE CONCAT(@seed_tag, '%');

-- ---------------------------------------------------------------------------
-- Ensure we have at least a few customer accounts for realistic order data
-- ---------------------------------------------------------------------------
INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'demo.customer1@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Demo Customer One',
    '09170001001',
    'Blk 14 Lot 3 Brunei St, Salawag, Dasmarinas City, Cavite',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'demo.customer1@seed.local'
);

INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'demo.customer2@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Demo Customer Two',
    '09170001002',
    '29-15 Gumamela St, Santa Rosa City, Laguna 4026',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'demo.customer2@seed.local'
);

INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'demo.customer3@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Demo Customer Three',
    '09170001003',
    'Poblacion 1A, Carmona City, Cavite 4116',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'demo.customer3@seed.local'
);

-- ---------------------------------------------------------------------------
-- Optional demo store rows (Cavite scope, added once, safe to rerun)
-- ---------------------------------------------------------------------------
-- Convert old non-Cavite demo rows (from previous seed versions) into Cavite rows.
UPDATE store_locations
SET
    store_name = 'Dasmarinas Demo Branch',
    address = '29-15 Gumamela Street, Salawag',
    city = 'City of Dasmariñas',
    province = 'Cavite',
    email = 'dasma.demo@lechondelights.com',
    opening_hours = 'Daily | 8:00 AM - 8:00 PM',
    opening_time = '08:00:00',
    closing_time = '20:00:00',
    latitude = 14.32096000,
    longitude = 121.11152000,
    is_active = 1
WHERE store_name = 'Laguna Demo Branch'
  AND email = 'laguna.demo@lechondelights.com';

UPDATE store_locations
SET
    store_name = 'Imus Demo Branch',
    address = 'Aguinaldo Highway, Anabu',
    city = 'City of Imus',
    province = 'Cavite',
    email = 'imus.demo@lechondelights.com',
    opening_hours = 'Daily | 9:00 AM - 9:00 PM',
    opening_time = '09:00:00',
    closing_time = '21:00:00',
    latitude = 14.42970000,
    longitude = 120.93670000,
    is_active = 1
WHERE store_name = 'Batangas Demo Branch'
  AND email = 'batangas.demo@lechondelights.com';

INSERT INTO store_locations (
    owner_user_id, store_name, address, city, province, phone, email,
    opening_hours, opening_time, closing_time, operating_days,
    availability_mode, manual_status, latitude, longitude, is_active, created_at, updated_at
)
SELECT
    NULL,
    'Dasmarinas Demo Branch',
    '29-15 Gumamela Street, Salawag',
    'City of Dasmariñas',
    'Cavite',
    '09175550001',
    'dasma.demo@lechondelights.com',
    'Daily | 8:00 AM - 8:00 PM',
    '08:00:00',
    '20:00:00',
    '1,2,3,4,5,6,7',
    'schedule',
    'closed',
    14.32096000,
    121.11152000,
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM store_locations WHERE store_name = 'Dasmarinas Demo Branch'
);

INSERT INTO store_locations (
    owner_user_id, store_name, address, city, province, phone, email,
    opening_hours, opening_time, closing_time, operating_days,
    availability_mode, manual_status, latitude, longitude, is_active, created_at, updated_at
)
SELECT
    NULL,
    'Imus Demo Branch',
    'Aguinaldo Highway, Anabu',
    'City of Imus',
    'Cavite',
    '09175550002',
    'imus.demo@lechondelights.com',
    'Daily | 9:00 AM - 9:00 PM',
    '09:00:00',
    '21:00:00',
    '1,2,3,4,5,6,7',
    'schedule',
    'closed',
    14.42970000,
    120.93670000,
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM store_locations WHERE store_name = 'Imus Demo Branch'
);

-- ---------------------------------------------------------------------------
-- Build customer pool
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_customer_pool;
CREATE TEMPORARY TABLE tmp_demo_customer_pool AS
SELECT
    (@rn_customers := @rn_customers + 1) AS rn,
    u.id AS user_id,
    u.full_name,
    u.email,
    COALESCE(NULLIF(TRIM(u.phone), ''), CONCAT('0917', LPAD(u.id, 7, '0'))) AS phone,
    COALESCE(NULLIF(TRIM(u.address), ''), CONCAT('Sample Address for User #', u.id)) AS address
FROM (
    SELECT id, full_name, email, phone, address
    FROM users
    WHERE user_type = 'customer'
      AND is_active = 1
    ORDER BY id
    LIMIT 20
) u
CROSS JOIN (SELECT @rn_customers := 0) seed;

SELECT @customer_count := COUNT(*) FROM tmp_demo_customer_pool;

-- ---------------------------------------------------------------------------
-- Build per-store product map (tenant-aware with fallbacks)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_store_products;
CREATE TEMPORARY TABLE tmp_demo_store_products AS
SELECT
    sl.store_id,
    sl.store_name,
    sl.address AS store_address,
    sl.city,
    sl.province,
    sl.owner_user_id,
    sl.latitude,
    sl.longitude,
    COALESCE(
        (
            SELECT p1.id
            FROM products p1
            WHERE p1.is_active = 1
              AND p1.is_archived = 0
              AND p1.seller_id = sl.owner_user_id
              AND TRIM(COALESCE(p1.product_id, '')) <> ''
            ORDER BY p1.id
            LIMIT 1
        ),
        (
            SELECT p2.id
            FROM products p2
            WHERE p2.is_active = 1
              AND p2.is_archived = 0
              AND p2.seller_id = 1
              AND TRIM(COALESCE(p2.product_id, '')) <> ''
            ORDER BY p2.id
            LIMIT 1
        ),
        (
            SELECT p3.id
            FROM products p3
            WHERE p3.is_active = 1
              AND p3.is_archived = 0
              AND p3.seller_id IS NULL
              AND TRIM(COALESCE(p3.product_id, '')) <> ''
            ORDER BY p3.id
            LIMIT 1
        ),
        (
            SELECT p4.id
            FROM products p4
            WHERE p4.is_active = 1
              AND p4.is_archived = 0
            ORDER BY p4.id
            LIMIT 1
        )
    ) AS product_id
FROM store_locations sl
WHERE sl.is_active = 1
  AND (
      LOWER(TRIM(COALESCE(sl.province, ''))) = 'cavite'
      OR LOWER(CONCAT(COALESCE(sl.address, ''), ' ', COALESCE(sl.city, ''), ' ', COALESCE(sl.province, ''))) LIKE '%cavite%'
  );

DELETE FROM tmp_demo_store_products
WHERE product_id IS NULL;

-- ---------------------------------------------------------------------------
-- Order blueprint (3 realistic orders per store)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_order_blueprint;
CREATE TEMPORARY TABLE tmp_demo_order_blueprint AS
SELECT
    CONCAT('SD', DATE_FORMAT(CURDATE(), '%y%m%d'), LPAD(sp.store_id, 2, '0'), s.scenario, 'O') AS order_number,
    sp.store_id,
    sp.store_name,
    sp.store_address,
    sp.city,
    sp.province,
    sp.latitude,
    sp.longitude,
    s.scenario,
    s.qty,
    s.delivery_day_offset,
    s.delivery_time_slot,
    s.order_status,
    s.payment_status,
    s.delivery_option,
    s.payment_method,
    s.note_suffix,
    cp.user_id,
    cp.full_name,
    cp.email,
    cp.phone,
    cp.address AS customer_address,
    p.id AS product_db_id,
    CASE
        WHEN TRIM(COALESCE(p.product_id, '')) = '' THEN CONCAT('demo-p-', p.id)
        ELSE p.product_id
    END AS product_code,
    p.name AS product_name,
    p.price AS unit_price
FROM tmp_demo_store_products sp
INNER JOIN products p ON p.id = sp.product_id
INNER JOIN (
    SELECT
        1 AS scenario,
        1 AS qty,
        1 AS delivery_day_offset,
        '10:00-12:00' AS delivery_time_slot,
        'delivered' AS order_status,
        'paid' AS payment_status,
        'pickup' AS delivery_option,
        'paymongo' AS payment_method,
        'Family lunch order. Product arrived crispy and on time.' AS note_suffix
    UNION ALL
    SELECT
        2,
        2,
        2,
        '14:00-16:00',
        'confirmed',
        'paid',
        'delivery',
        'gcash',
        'Office merienda order for 8 people.' 
    UNION ALL
    SELECT
        3,
        1,
        3,
        '17:00-19:00',
        'pending',
        'pending',
        'delivery',
        'cod',
        'Weekend dinner advance booking.'
) s ON 1 = 1
INNER JOIN tmp_demo_customer_pool cp
    ON cp.rn = ((sp.store_id + s.scenario - 1) MOD @customer_count) + 1;

-- ---------------------------------------------------------------------------
-- Insert orders
-- ---------------------------------------------------------------------------
INSERT INTO orders (
    order_number,
    user_id,
    customer_name,
    customer_email,
    customer_phone,
    delivery_address,
    delivery_date,
    delivery_time,
    estimated_delivery_time,
    payment_method,
    subtotal,
    delivery_fee,
    voucher_discount,
    total_amount,
    status,
    confirmed_at,
    special_instructions,
    created_at,
    updated_at,
    is_archived,
    delivery_option,
    pickup_location,
    delivery_location,
    latitude,
    longitude,
    delivery_instructions,
    payment_status,
    downpayment_amount,
    remaining_balance,
    payment_method_detail,
    receipt_sent,
    cancellation_reason,
    has_proof_of_delivery,
    actual_delivery_time
)
SELECT
    ob.order_number,
    ob.user_id,
    ob.full_name,
    ob.email,
    ob.phone,
    CASE
        WHEN ob.delivery_option = 'pickup'
            THEN CONCAT(ob.store_name, ', ', ob.store_address, ', ', ob.city)
        ELSE ob.customer_address
    END AS delivery_address,
    DATE_ADD(CURDATE(), INTERVAL ob.delivery_day_offset DAY) AS delivery_date,
    ob.delivery_time_slot,
    DATE_ADD(
        STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL ob.delivery_day_offset DAY), '%Y-%m-%d'), ' 18:00:00'), '%Y-%m-%d %H:%i:%s'),
        INTERVAL 0 MINUTE
    ) AS estimated_delivery_time,
    ob.payment_method,
    ROUND(ob.unit_price * ob.qty, 2) AS subtotal,
    CASE WHEN ob.delivery_option = 'delivery' THEN 120.00 ELSE 0.00 END AS delivery_fee,
    0.00 AS voucher_discount,
    ROUND((ob.unit_price * ob.qty) + CASE WHEN ob.delivery_option = 'delivery' THEN 120.00 ELSE 0.00 END, 2) AS total_amount,
    ob.order_status,
    CASE
        WHEN ob.order_status IN ('confirmed', 'preparing', 'delivered')
            THEN DATE_SUB(NOW(), INTERVAL 12 HOUR)
        ELSE NULL
    END AS confirmed_at,
    CONCAT(
        @seed_tag,
        ' [STORE=', ob.store_id, '][SCN=', ob.scenario, '] ',
        ob.note_suffix
    ) AS special_instructions,
    DATE_SUB(NOW(), INTERVAL (ob.scenario + ob.store_id) DAY) AS created_at,
    DATE_SUB(NOW(), INTERVAL (ob.scenario * 4) HOUR) AS updated_at,
    0 AS is_archived,
    ob.delivery_option,
    CASE WHEN ob.delivery_option = 'pickup' THEN ob.store_id ELSE NULL END AS pickup_location,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.city ELSE NULL END AS delivery_location,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.latitude ELSE NULL END AS latitude,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.longitude ELSE NULL END AS longitude,
    CASE
        WHEN ob.delivery_option = 'delivery'
            THEN 'Please call first before arrival.'
        ELSE 'Pickup at front counter.'
    END AS delivery_instructions,
    ob.payment_status,
    0.00 AS downpayment_amount,
    0.00 AS remaining_balance,
    CASE
        WHEN ob.payment_method = 'paymongo' THEN 'Card'
        WHEN ob.payment_method = 'gcash' THEN 'E-Wallet'
        ELSE 'Cash on Delivery'
    END AS payment_method_detail,
    CASE WHEN ob.payment_status = 'paid' THEN 1 ELSE 0 END AS receipt_sent,
    CASE WHEN ob.order_status = 'cancelled' THEN 'Customer changed schedule' ELSE NULL END AS cancellation_reason,
    CASE WHEN ob.order_status = 'delivered' THEN 1 ELSE 0 END AS has_proof_of_delivery,
    CASE
        WHEN ob.order_status = 'delivered'
            THEN DATE_SUB(NOW(), INTERVAL 1 DAY)
        ELSE NULL
    END AS actual_delivery_time
FROM tmp_demo_order_blueprint ob;

-- ---------------------------------------------------------------------------
-- Insert order items from blueprint
-- ---------------------------------------------------------------------------
INSERT INTO order_items (
    order_id,
    product_id,
    product_name,
    price,
    quantity,
    size,
    addons,
    total,
    is_reviewed
)
SELECT
    o.id AS order_id,
    ob.product_code,
    ob.product_name,
    ob.unit_price,
    ob.qty,
    'Regular' AS size,
    '[]' AS addons,
    ROUND(ob.unit_price * ob.qty, 2) AS total,
    CASE WHEN ob.order_status = 'delivered' THEN 1 ELSE 0 END AS is_reviewed
FROM tmp_demo_order_blueprint ob
INNER JOIN orders o ON o.order_number = ob.order_number;

-- ---------------------------------------------------------------------------
-- Pre-order blueprint (2 realistic pre-orders per store)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_preorder_blueprint;
CREATE TEMPORARY TABLE tmp_demo_preorder_blueprint AS
SELECT
    sp.store_id,
    sp.store_name,
    sp.store_address,
    sp.city,
    sp.province,
    p.id AS product_db_id,
    p.name AS product_name,
    p.price AS unit_price,
    s.scenario,
    s.qty,
    s.reservation_status,
    s.delivery_method,
    s.payment_type,
    s.downpayment_status,
    s.final_payment_status,
    s.day_offset,
    cp.user_id,
    cp.address AS customer_address
FROM tmp_demo_store_products sp
INNER JOIN products p ON p.id = sp.product_id
INNER JOIN (
    SELECT
        1 AS scenario,
        1 AS qty,
        'confirmed' AS reservation_status,
        'pickup' AS delivery_method,
        'full_payment' AS payment_type,
        'paid' AS downpayment_status,
        'paid' AS final_payment_status,
        4 AS day_offset
    UNION ALL
    SELECT
        2,
        2,
        'pending',
        'delivery',
        'downpayment',
        'paid',
        'pending',
        7
) s ON 1 = 1
INNER JOIN tmp_demo_customer_pool cp
    ON cp.rn = ((sp.store_id + s.scenario + 1) MOD @customer_count) + 1;

-- ---------------------------------------------------------------------------
-- Insert pre-orders
-- ---------------------------------------------------------------------------
INSERT INTO pre_orders (
    user_id,
    product_id,
    product_name,
    quantity,
    unit_price,
    total_price,
    reservation_date,
    preferred_pickup_date,
    preferred_pickup_time,
    pickup_location,
    delivery_address,
    delivery_method,
    special_instructions,
    payment_type,
    downpayment_amount,
    remaining_amount,
    downpayment_status,
    final_payment_status,
    downpayment_paid_at,
    final_payment_paid_at,
    reservation_status,
    notes,
    admin_notes,
    created_at,
    updated_at
)
SELECT
    pb.user_id,
    pb.product_db_id,
    pb.product_name,
    pb.qty,
    pb.unit_price,
    ROUND(pb.unit_price * pb.qty, 2) AS total_price,
    CURDATE() AS reservation_date,
    DATE_ADD(CURDATE(), INTERVAL pb.day_offset DAY) AS preferred_pickup_date,
    CASE WHEN pb.scenario = 1 THEN '11:00 AM' ELSE '5:00 PM' END AS preferred_pickup_time,
    CASE
        WHEN pb.delivery_method = 'pickup'
            THEN CONCAT(pb.store_name, ' - ', pb.city)
        ELSE NULL
    END AS pickup_location,
    CASE
        WHEN pb.delivery_method = 'delivery'
            THEN pb.customer_address
        ELSE ''
    END AS delivery_address,
    pb.delivery_method,
    CONCAT('Pre-order generated for demo scenario ', pb.scenario) AS special_instructions,
    pb.payment_type,
    CASE
        WHEN pb.payment_type = 'downpayment'
            THEN ROUND((pb.unit_price * pb.qty) * 0.30, 2)
        ELSE ROUND(pb.unit_price * pb.qty, 2)
    END AS downpayment_amount,
    CASE
        WHEN pb.payment_type = 'downpayment'
            THEN ROUND((pb.unit_price * pb.qty) * 0.70, 2)
        ELSE 0.00
    END AS remaining_amount,
    pb.downpayment_status,
    pb.final_payment_status,
    CASE
        WHEN pb.downpayment_status = 'paid' THEN NOW()
        ELSE NULL
    END AS downpayment_paid_at,
    CASE
        WHEN pb.final_payment_status = 'paid' THEN NOW()
        ELSE NULL
    END AS final_payment_paid_at,
    pb.reservation_status,
    CONCAT(@seed_tag, ' [STORE=', pb.store_id, '][PRE-SCN=', pb.scenario, ']') AS notes,
    'System-generated realistic sample pre-order.' AS admin_notes,
    DATE_SUB(NOW(), INTERVAL pb.scenario DAY) AS created_at,
    NOW() AS updated_at
FROM tmp_demo_preorder_blueprint pb;

-- ---------------------------------------------------------------------------
-- Insert product reviews from delivered demo orders
-- ---------------------------------------------------------------------------
INSERT INTO product_reviews (
    order_id,
    product_id,
    user_id,
    rating,
    comment,
    is_approved,
    created_at
)
SELECT
    o.id AS order_id,
    ob.product_db_id AS product_id,
    ob.user_id,
    CASE
        WHEN (ob.store_id % 5) = 0 THEN 4
        ELSE 5
    END AS rating,
    CONCAT(
        @seed_tag,
        ' Great taste and serving size for ',
        ob.store_name,
        '.'
    ) AS comment,
    1 AS is_approved,
    DATE_SUB(NOW(), INTERVAL 1 DAY) AS created_at
FROM tmp_demo_order_blueprint ob
INNER JOIN orders o ON o.order_number = ob.order_number
WHERE ob.order_status = 'delivered'
  AND NOT EXISTS (
      SELECT 1
      FROM product_reviews pr
      WHERE pr.order_id = o.id
        AND pr.product_id = ob.product_db_id
        AND pr.user_id = ob.user_id
  );

-- ---------------------------------------------------------------------------
-- Optional delivery reviews for delivered + delivery orders (if any)
-- ---------------------------------------------------------------------------
INSERT INTO delivery_reviews (
    order_id,
    user_id,
    rating,
    comment,
    created_at
)
SELECT
    o.id AS order_id,
    o.user_id,
    5 AS rating,
    CONCAT(@seed_tag, ' Rider was polite and easy to contact.') AS comment,
    NOW() AS created_at
FROM orders o
WHERE o.special_instructions LIKE CONCAT(@seed_tag, '%')
  AND o.status = 'delivered'
  AND o.delivery_option = 'delivery';

-- ---------------------------------------------------------------------------
-- Recompute product rating aggregates
-- ---------------------------------------------------------------------------
UPDATE products p
LEFT JOIN (
    SELECT
        product_id,
        ROUND(AVG(rating), 2) AS avg_rating,
        COUNT(*) AS review_count
    FROM product_reviews
    WHERE is_approved = 1
    GROUP BY product_id
) pr ON pr.product_id = p.id
SET
    p.avg_rating = COALESCE(pr.avg_rating, 0.00),
    p.review_count = COALESCE(pr.review_count, 0);

COMMIT;

-- Quick verification queries (optional):
-- SELECT store_id, COUNT(*) AS demo_orders
-- FROM store_locations sl
-- LEFT JOIN orders o ON o.pickup_location = sl.store_id AND o.special_instructions LIKE '[DEMO-SEED]%'
-- GROUP BY store_id
-- ORDER BY store_id;
--
-- SELECT COUNT(*) AS demo_preorders
-- FROM pre_orders
-- WHERE notes LIKE '[DEMO-SEED]%';
