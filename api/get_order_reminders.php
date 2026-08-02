<?php
/**
 * Customer Active Order Reminder API
 * Returns orders currently in processing/preparing, on_the_way, or arriving state.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$user_id = intval($_SESSION['user_id']);
$session_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$is_customer_context = !in_array($session_user_type, ['admin', 'employee'], true);

if (!$is_customer_context) {
    echo json_encode([
        'success' => true,
        'has_active' => false,
        'count' => 0,
        'reminders' => []
    ]);
    exit;
}

function normalize_phone_digits($value) {
    return preg_replace('/\D+/', '', (string)$value);
}

$fallback_email = strtolower(trim((string)($_SESSION['email'] ?? '')));
$fallback_phone = normalize_phone_digits($_SESSION['phone'] ?? '');
$resolved_user_type = $session_user_type !== '' ? $session_user_type : 'customer';

$profile_stmt = $conn->prepare("SELECT email, phone, user_type FROM users WHERE id = ? LIMIT 1");
if ($profile_stmt) {
    $profile_stmt->bind_param("i", $user_id);
    $profile_stmt->execute();
    $profile_result = $profile_stmt->get_result();
    if ($profile_result && $profile_result->num_rows > 0) {
        $profile = $profile_result->fetch_assoc();
        if (!empty($profile['email'])) {
            $fallback_email = strtolower(trim((string)$profile['email']));
        }
        if (!empty($profile['phone'])) {
            $fallback_phone = normalize_phone_digits($profile['phone']);
        }
        $resolved_user_type = strtolower(trim((string)($profile['user_type'] ?? $resolved_user_type)));
    }
    $profile_stmt->close();
}

if (in_array($resolved_user_type, ['admin', 'employee'], true)) {
    echo json_encode([
        'success' => true,
        'has_active' => false,
        'count' => 0,
        'reminders' => []
    ]);
    exit;
}

$query = "
    SELECT 
        o.id AS order_id,
        o.order_number,
        o.delivery_option,
        o.user_id,
        o.customer_email,
        o.customer_phone,
        o.status AS order_status,
        o.updated_at AS order_updated_at,
        lt.current_status AS tracking_status,
        lt.updated_at AS tracking_updated_at
    FROM orders o
    LEFT JOIN (
        SELECT lt1.*
        FROM logistics_tracking lt1
        INNER JOIN (
            SELECT order_id, MAX(id) AS latest_id
            FROM logistics_tracking
            GROUP BY order_id
        ) latest ON latest.latest_id = lt1.id
    ) lt ON lt.order_id = o.id
    WHERE (
            o.user_id = ?
            OR (o.user_id IS NULL AND ? <> '' AND LOWER(TRIM(o.customer_email)) = ?)
            OR (
                o.user_id IS NULL
                AND ? <> ''
                AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(o.customer_phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?
            )
          )
      AND COALESCE(o.is_archived, 0) = 0
      AND o.status NOT IN ('delivered', 'cancelled')
      AND (
            o.status IN ('pending', 'confirmed', 'preparing')
            OR lt.current_status IN ('on_the_way', 'arriving')
          )
    ORDER BY COALESCE(lt.updated_at, o.updated_at) DESC
    LIMIT 5
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare reminder query.']);
    exit;
}

$stmt->bind_param("issss", $user_id, $fallback_email, $fallback_email, $fallback_phone, $fallback_phone);
$stmt->execute();
$result = $stmt->get_result();

$status_meta = [
    'pending' => [
        'label' => 'Processing',
        'message_template' => 'Order #%s is being processed.',
        'icon' => 'fa-hourglass-half'
    ],
    'confirmed' => [
        'label' => 'Processing',
        'message_template' => 'Order #%s is being processed.',
        'icon' => 'fa-utensils'
    ],
    'preparing' => [
        'label' => 'Preparing',
        'message_template' => 'Order #%s is now being prepared.',
        'icon' => 'fa-fire'
    ],
    'on_the_way' => [
        'label' => 'On the Way',
        'message_template' => 'Order #%s is on the way to your location.',
        'icon' => 'fa-motorcycle'
    ],
    'arriving' => [
        'label' => 'Arriving',
        'message_template' => 'Order #%s is arriving soon. Please be ready.',
        'icon' => 'fa-map-marker-alt'
    ]
];

$reminders = [];
$match_sources = [
    'user_id' => 0,
    'customer_email' => 0,
    'customer_phone' => 0
];
while ($row = $result->fetch_assoc()) {
    $resolved_status = '';

    if (!empty($row['tracking_status']) && in_array($row['tracking_status'], ['arriving', 'on_the_way'], true)) {
        $resolved_status = $row['tracking_status'];
    } elseif (in_array(($row['order_status'] ?? ''), ['pending', 'confirmed', 'preparing'], true)) {
        $resolved_status = $row['order_status'];
    }

    if ($resolved_status === '' || !isset($status_meta[$resolved_status])) {
        continue;
    }

    $meta = $status_meta[$resolved_status];
    $order_number = $row['order_number'] ?? ('#' . intval($row['order_id']));
    $row_phone = normalize_phone_digits($row['customer_phone'] ?? '');
    $row_email = strtolower(trim((string)($row['customer_email'] ?? '')));
    $match_source = 'user_id';
    if (intval($row['user_id']) !== $user_id) {
        if ($fallback_email !== '' && $row_email === $fallback_email) {
            $match_source = 'customer_email';
        } elseif ($fallback_phone !== '' && $row_phone === $fallback_phone) {
            $match_source = 'customer_phone';
        }
    }
    $match_sources[$match_source] = ($match_sources[$match_source] ?? 0) + 1;

    $reminders[] = [
        'order_id' => intval($row['order_id']),
        'order_number' => $order_number,
        'status' => $resolved_status,
        'status_label' => $meta['label'],
        'message' => sprintf($meta['message_template'], $order_number),
        'icon' => $meta['icon'],
        'updated_at' => $row['tracking_updated_at'] ?? $row['order_updated_at'],
        'track_url' => ($row['delivery_option'] === 'delivery')
            ? ('track_order.php?order_id=' . intval($row['order_id']))
            : ('my_orders.php?highlight_order=' . intval($row['order_id'])),
        'match_source' => $match_source
    ];
}

$stmt->close();
$response = [
    'success' => true,
    'has_active' => count($reminders) > 0,
    'count' => count($reminders),
    'reminders' => $reminders
];

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $response['debug'] = [
        'session_user_id' => $user_id,
        'session_user_type' => $session_user_type !== '' ? $session_user_type : '(missing)',
        'resolved_user_type' => $resolved_user_type,
        'fallback_email' => $fallback_email !== '' ? $fallback_email : '(missing)',
        'fallback_phone' => $fallback_phone !== '' ? $fallback_phone : '(missing)',
        'match_sources' => $match_sources
    ];
}

$conn->close();
echo json_encode($response);
