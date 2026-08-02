-- bulk_seed_500_per_store.sql
-- Generates:
--   - 500 orders per active store
--   - 500 pre-orders per active store
--   - product reviews + delivery reviews for delivered orders
-- Safe to rerun: only rows tagged with [BULK-SEED-500] are replaced.

START TRANSACTION;

SET @seed_tag := '[BULK-SEED-500]';

-- ---------------------------------------------------------------------------
-- Cleanup previous bulk-seed rows
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
-- Ensure there are customer accounts to assign orders/pre-orders to
-- ---------------------------------------------------------------------------
INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'bulk.seed.customer1@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Bulk Seed Customer One',
    '09170002001',
    'Cavite Demo Address 1',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'bulk.seed.customer1@seed.local'
);

INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'bulk.seed.customer2@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Bulk Seed Customer Two',
    '09170002002',
    'Laguna Demo Address 2',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'bulk.seed.customer2@seed.local'
);

INSERT INTO users (
    email, password, full_name, phone, address, user_type, account_type, is_active,
    account_control_status, created_at, updated_at
)
SELECT
    'bulk.seed.customer3@seed.local',
    '$2y$10$zTHQvjea7LVWZmPhaQO9f.Vdx8gQ4P8jRB4qBYfLw7zDIDpQ9aQva',
    'Bulk Seed Customer Three',
    '09170002003',
    'Metro Manila Demo Address 3',
    'customer',
    'individual',
    1,
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'bulk.seed.customer3@seed.local'
);

-- ---------------------------------------------------------------------------
-- Build customer pool
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_bulk_customers;
CREATE TEMPORARY TABLE tmp_bulk_customers AS
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
    LIMIT 5000
) u
CROSS JOIN (SELECT @rn_customers := 0) init_customer_rn;

SELECT @customer_count := COUNT(*) FROM tmp_bulk_customers;

-- ---------------------------------------------------------------------------
-- Build active store map + multi-product tenant-aware product pool
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_bulk_stores;
CREATE TEMPORARY TABLE tmp_bulk_stores AS
SELECT
    sl.store_id,
    sl.store_name,
    sl.address AS store_address,
    sl.city,
    sl.province,
    sl.owner_user_id,
    sl.latitude,
    sl.longitude
FROM store_locations sl
WHERE sl.is_active = 1;

DROP TEMPORARY TABLE IF EXISTS tmp_bulk_store_product_candidates;
CREATE TEMPORARY TABLE tmp_bulk_store_product_candidates AS
SELECT
    bs.store_id,
    p.id AS product_db_id,
    1 AS priority
FROM tmp_bulk_stores bs
INNER JOIN products p
    ON p.is_active = 1
   AND p.is_archived = 0
   AND p.seller_id = bs.owner_user_id
   AND TRIM(COALESCE(p.product_id, '')) <> ''

UNION ALL

SELECT
    bs.store_id,
    p.id AS product_db_id,
    2 AS priority
FROM tmp_bulk_stores bs
INNER JOIN products p
    ON p.is_active = 1
   AND p.is_archived = 0
   AND p.seller_id = 1
   AND TRIM(COALESCE(p.product_id, '')) <> ''

UNION ALL

SELECT
    bs.store_id,
    p.id AS product_db_id,
    3 AS priority
FROM tmp_bulk_stores bs
INNER JOIN products p
    ON p.is_active = 1
   AND p.is_archived = 0
   AND p.seller_id IS NULL
   AND TRIM(COALESCE(p.product_id, '')) <> ''

UNION ALL

SELECT
    bs.store_id,
    p.id AS product_db_id,
    4 AS priority
FROM tmp_bulk_stores bs
INNER JOIN products p
    ON p.is_active = 1
   AND p.is_archived = 0;

DROP TEMPORARY TABLE IF EXISTS tmp_bulk_store_products;
CREATE TEMPORARY TABLE tmp_bulk_store_products AS
SELECT
    c.store_id,
    c.product_db_id,
    MIN(c.priority) AS priority
FROM tmp_bulk_store_product_candidates c
GROUP BY c.store_id, c.product_db_id;

DROP TEMPORARY TABLE IF EXISTS tmp_bulk_store_product_pool;
CREATE TEMPORARY TABLE tmp_bulk_store_product_pool AS
SELECT
    ranked.store_id,
    ranked.store_name,
    ranked.store_address,
    ranked.city,
    ranked.province,
    ranked.owner_user_id,
    ranked.latitude,
    ranked.longitude,
    ranked.product_db_id,
    ranked.product_code,
    ranked.product_name,
    ranked.unit_price,
    ranked.priority,
    (@product_rn := IF(@prev_store_id = ranked.store_id, @product_rn + 1, 1)) AS product_seq,
    (@prev_store_id := ranked.store_id) AS _prev_store_id
FROM (
    SELECT
        bs.store_id,
        bs.store_name,
        bs.store_address,
        bs.city,
        bs.province,
        bs.owner_user_id,
        bs.latitude,
        bs.longitude,
        p.id AS product_db_id,
        CASE
            WHEN TRIM(COALESCE(p.product_id, '')) = '' THEN CONCAT('bulk-p-', p.id)
            ELSE p.product_id
        END AS product_code,
        p.name AS product_name,
        p.price AS unit_price,
        tsp.priority
    FROM tmp_bulk_store_products tsp
    INNER JOIN tmp_bulk_stores bs ON bs.store_id = tsp.store_id
    INNER JOIN products p ON p.id = tsp.product_db_id
    ORDER BY bs.store_id, tsp.priority, p.id
) ranked
CROSS JOIN (SELECT @product_rn := 0, @prev_store_id := 0) init_product_rn;

DROP TEMPORARY TABLE IF EXISTS tmp_bulk_store_product_count;
CREATE TEMPORARY TABLE tmp_bulk_store_product_count AS
SELECT
    spp.store_id,
    COUNT(*) AS product_count
FROM tmp_bulk_store_product_pool spp
GROUP BY spp.store_id;

-- ---------------------------------------------------------------------------
-- Generate sequence 1..500
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_bulk_seq_500;
CREATE TEMPORARY TABLE tmp_bulk_seq_500 (
    seq INT NOT NULL PRIMARY KEY
);

INSERT INTO tmp_bulk_seq_500 (seq)
SELECT n
FROM (
    SELECT (@n := @n + 1) AS n
    FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
    CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
    CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
    CROSS JOIN (SELECT @n := 0) init_n
) nums
WHERE n BETWEEN 1 AND 500;

-- ---------------------------------------------------------------------------
-- Orders blueprint (500 per store)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_bulk_orders_blueprint;
CREATE TEMPORARY TABLE tmp_bulk_orders_blueprint AS
SELECT
    CONCAT('B', DATE_FORMAT(CURDATE(), '%y%m%d'), LPAD(bs.store_id, 3, '0'), LPAD(seq.seq, 4, '0')) AS order_number,
    bs.store_id,
    bs.store_name,
    bs.store_address,
    bs.city,
    bs.province,
    bs.latitude,
    bs.longitude,
    seq.seq,
    cp.user_id,
    cp.full_name,
    cp.email,
    cp.phone,
    cp.address AS customer_address,
    spp.product_db_id,
    spp.product_code,
    spp.product_name,
    spp.unit_price,
    (1 + (seq.seq % 4)) AS qty,
    CASE
        WHEN seq.seq % 10 IN (1, 2, 3, 4) THEN 'delivered'
        WHEN seq.seq % 10 IN (5, 6, 7) THEN 'confirmed'
        WHEN seq.seq % 10 = 8 THEN 'preparing'
        WHEN seq.seq % 10 = 9 THEN 'pending'
        ELSE 'cancelled'
    END AS order_status,
    CASE
        WHEN seq.seq % 10 = 0 THEN 'cancelled'
        WHEN seq.seq % 6 = 0 THEN 'partial'
        WHEN seq.seq % 10 IN (8, 9) THEN 'pending'
        ELSE 'paid'
    END AS payment_status,
    CASE
        WHEN seq.seq % 3 = 0 THEN 'pickup'
        ELSE 'delivery'
    END AS delivery_option,
    CASE
        WHEN seq.seq % 4 = 0 THEN 'paymongo'
        WHEN seq.seq % 4 = 1 THEN 'gcash'
        ELSE 'cod'
    END AS payment_method
FROM tmp_bulk_stores bs
INNER JOIN tmp_bulk_seq_500 seq ON 1 = 1
INNER JOIN tmp_bulk_store_product_count spc ON spc.store_id = bs.store_id
INNER JOIN tmp_bulk_store_product_pool spp
    ON spp.store_id = bs.store_id
   AND spp.product_seq = ((seq.seq - 1) MOD spc.product_count) + 1
INNER JOIN tmp_bulk_customers cp
    ON cp.rn = ((bs.store_id * 503 + seq.seq) MOD @customer_count) + 1;

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
    DATE_ADD(CURDATE(), INTERVAL ((ob.seq % 30) - 20) DAY) AS delivery_date,
    CASE
        WHEN ob.seq % 4 = 0 THEN '09:00-11:00'
        WHEN ob.seq % 4 = 1 THEN '11:00-13:00'
        WHEN ob.seq % 4 = 2 THEN '14:00-16:00'
        ELSE '17:00-19:00'
    END AS delivery_time,
    DATE_ADD(
        DATE_SUB(NOW(), INTERVAL (ob.seq % 20) DAY),
        INTERVAL (ob.seq % 8) HOUR
    ) AS estimated_delivery_time,
    ob.payment_method,
    ROUND(ob.unit_price * ob.qty, 2) AS subtotal,
    CASE
        WHEN ob.delivery_option = 'delivery' THEN (120.00 + ((ob.seq % 5) * 10.00))
        ELSE 0.00
    END AS delivery_fee,
    0.00 AS voucher_discount,
    ROUND(
        (ob.unit_price * ob.qty) +
        CASE WHEN ob.delivery_option = 'delivery' THEN (120.00 + ((ob.seq % 5) * 10.00)) ELSE 0.00 END,
        2
    ) AS total_amount,
    ob.order_status,
    CASE
        WHEN ob.order_status IN ('confirmed', 'preparing', 'delivered')
            THEN DATE_SUB(NOW(), INTERVAL (ob.seq % 72) HOUR)
        ELSE NULL
    END AS confirmed_at,
    CONCAT(
        @seed_tag,
        ' [STORE=', ob.store_id, '][SEQ=', ob.seq, '] ',
        'Bulk generated realistic traffic order.'
    ) AS special_instructions,
    DATE_SUB(NOW(), INTERVAL (ob.seq % 90) DAY) AS created_at,
    DATE_SUB(NOW(), INTERVAL (ob.seq % 24) HOUR) AS updated_at,
    0 AS is_archived,
    ob.delivery_option,
    CASE WHEN ob.delivery_option = 'pickup' THEN ob.store_id ELSE NULL END AS pickup_location,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.city ELSE NULL END AS delivery_location,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.latitude ELSE NULL END AS latitude,
    CASE WHEN ob.delivery_option = 'delivery' THEN ob.longitude ELSE NULL END AS longitude,
    CASE
        WHEN ob.delivery_option = 'delivery' THEN 'Please contact customer before arrival.'
        ELSE 'Pickup at the main counter.'
    END AS delivery_instructions,
    ob.payment_status,
    CASE WHEN ob.payment_status = 'partial' THEN ROUND((ob.unit_price * ob.qty) * 0.30, 2) ELSE 0.00 END AS downpayment_amount,
    CASE WHEN ob.payment_status = 'partial' THEN ROUND((ob.unit_price * ob.qty) * 0.70, 2) ELSE 0.00 END AS remaining_balance,
    CASE
        WHEN ob.payment_method = 'paymongo' THEN 'Card'
        WHEN ob.payment_method = 'gcash' THEN 'E-Wallet'
        ELSE 'Cash'
    END AS payment_method_detail,
    CASE WHEN ob.payment_status = 'paid' THEN 1 ELSE 0 END AS receipt_sent,
    CASE WHEN ob.order_status = 'cancelled' THEN 'Auto-generated cancellation scenario' ELSE NULL END AS cancellation_reason,
    CASE WHEN ob.order_status = 'delivered' THEN 1 ELSE 0 END AS has_proof_of_delivery,
    CASE
        WHEN ob.order_status = 'delivered'
            THEN DATE_SUB(NOW(), INTERVAL (ob.seq % 10) HOUR)
        ELSE NULL
    END AS actual_delivery_time
FROM tmp_bulk_orders_blueprint ob;

-- ---------------------------------------------------------------------------
-- Insert order items (1 line item per seeded order)
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
FROM tmp_bulk_orders_blueprint ob
INNER JOIN orders o ON o.order_number = ob.order_number;

-- ---------------------------------------------------------------------------
-- Pre-orders blueprint (500 per store)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_bulk_preorders_blueprint;
CREATE TEMPORARY TABLE tmp_bulk_preorders_blueprint AS
SELECT
    bs.store_id,
    bs.store_name,
    bs.city,
    spp.product_db_id,
    spp.product_name,
    spp.unit_price,
    seq.seq,
    cp.user_id,
    cp.address AS customer_address,
    (1 + (seq.seq % 3)) AS qty,
    CASE
        WHEN seq.seq % 20 IN (1, 2, 3, 4, 5) THEN 'completed'
        WHEN seq.seq % 20 IN (6, 7, 8, 9, 10, 11) THEN 'confirmed'
        WHEN seq.seq % 20 IN (12, 13, 14) THEN 'in_preparation'
        WHEN seq.seq % 20 IN (15, 16) THEN 'ready_for_pickup'
        WHEN seq.seq % 20 = 17 THEN 'cancelled'
        ELSE 'pending'
    END AS reservation_status,
    CASE
        WHEN seq.seq % 2 = 0 THEN 'pickup'
        ELSE 'delivery'
    END AS delivery_method,
    CASE
        WHEN seq.seq % 5 IN (0, 1) THEN 'downpayment'
        ELSE 'full_payment'
    END AS payment_type
FROM tmp_bulk_stores bs
INNER JOIN tmp_bulk_seq_500 seq ON 1 = 1
INNER JOIN tmp_bulk_store_product_count spc ON spc.store_id = bs.store_id
INNER JOIN tmp_bulk_store_product_pool spp
    ON spp.store_id = bs.store_id
   AND spp.product_seq = ((seq.seq + 6 - 1) MOD spc.product_count) + 1
INNER JOIN tmp_bulk_customers cp
    ON cp.rn = ((bs.store_id * 997 + seq.seq) MOD @customer_count) + 1;

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
    cancellation_reason,
    cancelled_at,
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
    DATE_SUB(CURDATE(), INTERVAL (pb.seq % 30) DAY) AS reservation_date,
    DATE_ADD(CURDATE(), INTERVAL ((pb.seq % 60) + 1) DAY) AS preferred_pickup_date,
    CASE
        WHEN pb.seq % 4 = 0 THEN '10:00 AM'
        WHEN pb.seq % 4 = 1 THEN '12:00 PM'
        WHEN pb.seq % 4 = 2 THEN '3:00 PM'
        ELSE '6:00 PM'
    END AS preferred_pickup_time,
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
    'Bulk generated realistic pre-order flow.' AS special_instructions,
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
    CASE
        WHEN pb.payment_type = 'downpayment' THEN 'paid'
        WHEN pb.seq % 7 = 0 THEN 'pending'
        ELSE 'paid'
    END AS downpayment_status,
    CASE
        WHEN pb.reservation_status IN ('completed', 'ready_for_pickup') THEN 'paid'
        WHEN pb.payment_type = 'downpayment' THEN 'pending'
        ELSE 'pending'
    END AS final_payment_status,
    CASE
        WHEN pb.payment_type = 'downpayment' OR pb.seq % 3 = 0 THEN DATE_SUB(NOW(), INTERVAL (pb.seq % 20) DAY)
        ELSE NULL
    END AS downpayment_paid_at,
    CASE
        WHEN pb.reservation_status = 'completed' THEN DATE_SUB(NOW(), INTERVAL (pb.seq % 8) DAY)
        ELSE NULL
    END AS final_payment_paid_at,
    pb.reservation_status,
    CASE WHEN pb.reservation_status = 'cancelled' THEN 'Auto-generated cancellation scenario' ELSE NULL END AS cancellation_reason,
    CASE WHEN pb.reservation_status = 'cancelled' THEN DATE_SUB(NOW(), INTERVAL (pb.seq % 5) DAY) ELSE NULL END AS cancelled_at,
    CONCAT(@seed_tag, ' [STORE=', pb.store_id, '][SEQ=', pb.seq, ']') AS notes,
    'System-generated bulk sample pre-order.' AS admin_notes,
    DATE_SUB(NOW(), INTERVAL (pb.seq % 75) DAY) AS created_at,
    NOW() AS updated_at
FROM tmp_bulk_preorders_blueprint pb;

-- ---------------------------------------------------------------------------
-- Product reviews from delivered bulk orders
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
        WHEN ob.seq % 10 IN (0, 1, 2, 3, 4, 5) THEN 5
        WHEN ob.seq % 10 IN (6, 7, 8) THEN 4
        ELSE 3
    END AS rating,
    CONCAT(
        @seed_tag,
        ' Good quality and flavor from ',
        ob.store_name,
        '.'
    ) AS comment,
    1 AS is_approved,
    DATE_SUB(NOW(), INTERVAL (ob.seq % 30) DAY) AS created_at
FROM tmp_bulk_orders_blueprint ob
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
-- Delivery reviews from delivered + delivery bulk orders
-- ---------------------------------------------------------------------------
INSERT INTO delivery_reviews (
    order_id,
    user_id,
    rating,
    comment,
    created_at
)
SELECT
    o.id,
    o.user_id,
    CASE
        WHEN ob.seq % 6 IN (0, 1, 2, 3) THEN 5
        ELSE 4
    END AS rating,
    CONCAT(@seed_tag, ' Rider was professional and on time.') AS comment,
    DATE_SUB(NOW(), INTERVAL (ob.seq % 20) DAY) AS created_at
FROM tmp_bulk_orders_blueprint ob
INNER JOIN orders o ON o.order_number = ob.order_number
WHERE ob.order_status = 'delivered'
  AND ob.delivery_option = 'delivery';

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

-- Optional checks:
-- SELECT COUNT(*) FROM orders WHERE special_instructions LIKE '[BULK-SEED-500]%';
-- SELECT COUNT(*) FROM pre_orders WHERE notes LIKE '[BULK-SEED-500]%';
