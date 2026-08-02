<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function widgetTableExists($conn, $tableName)
{
    $safe = mysqli_real_escape_string($conn, $tableName);
    $sql = "SHOW TABLES LIKE '{$safe}'";
    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

function widgetParseTimestamp($value)
{
    if (!is_string($value) || trim($value) === '') {
        return 0;
    }
    $ts = strtotime($value);
    return ($ts === false) ? 0 : $ts;
}

function widgetReadableSchedule($dateValue, $timeValue)
{
    $dateValue = trim((string)$dateValue);
    $timeValue = trim((string)$timeValue);

    if ($dateValue === '' || $dateValue === '0000-00-00') {
        return '';
    }

    $dateTs = strtotime($dateValue);
    if ($dateTs === false) {
        return '';
    }

    if ($timeValue !== '') {
        return 'Scheduled ' . date('M j', $dateTs) . ', ' . $timeValue;
    }

    return 'Scheduled ' . date('M j', $dateTs);
}

$user_id = (int)$_SESSION['user_id'];
$user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
if (!in_array($user_type, ['', 'customer', 'user'], true)) {
    echo json_encode(['success' => true, 'orders' => [], 'count' => 0]);
    exit;
}

$items = [];
$now_ts = time();

$orderSql = "
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.delivery_address,
        o.status AS order_status,
        o.estimated_delivery_time,
        o.created_at,
        o.updated_at,
        lt.current_status AS tracking_status,
        lt.driver_name,
        lt.estimated_delivery,
        lt.last_location_update,
        lt.updated_at AS tracking_updated_at
    FROM orders o
    LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
    WHERE o.user_id = ?
      AND (
        (lt.id IS NOT NULL AND lt.current_status IN ('pending','assigned','picked_up','on_the_way','arriving'))
        OR
        (lt.id IS NULL AND o.status IN ('pending','confirmed','preparing'))
      )
    ORDER BY COALESCE(lt.updated_at, o.updated_at, o.created_at) DESC
    LIMIT 5
";

$orderStmt = $conn->prepare($orderSql);
if (!$orderStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$orderStmt->bind_param('i', $user_id);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();

while ($row = $orderResult->fetch_assoc()) {
    $tracking_status = strtolower(trim((string)($row['tracking_status'] ?? '')));
    $order_status = strtolower(trim((string)($row['order_status'] ?? 'pending')));
    $status_for_label = ($tracking_status !== '') ? $tracking_status : $order_status;

    $status_labels = [
        'pending' => 'Pending',
        'assigned' => 'Driver Assigned',
        'picked_up' => 'Picked Up',
        'on_the_way' => 'On The Way',
        'arriving' => 'Arriving Soon',
        'processing' => 'Processing',
        'preparing' => 'Preparing',
        'confirmed' => 'Confirmed',
        'in_progress' => 'In Progress',
    ];
    $status_label = $status_labels[$status_for_label] ?? ucfirst(str_replace('_', ' ', $status_for_label));

    $eta_text = 'ETA updating';
    $estimated_delivery = trim((string)($row['estimated_delivery'] ?? ''));
    if ($estimated_delivery === '') {
        $estimated_delivery = trim((string)($row['estimated_delivery_time'] ?? ''));
    }

    if ($estimated_delivery !== '') {
        $eta_ts = strtotime($estimated_delivery);
        if ($eta_ts !== false) {
            $diff_seconds = $eta_ts - $now_ts;
            if ($diff_seconds > 0) {
                $minutes = (int)ceil($diff_seconds / 60);
                if ($minutes <= 1) {
                    $eta_text = 'Arriving in 1 min';
                } elseif ($minutes < 60) {
                    $eta_text = 'Arriving in ' . $minutes . ' mins';
                } else {
                    $eta_text = 'Arriving by ' . date('h:i A', $eta_ts);
                }
            } else {
                $eta_text = 'Arriving soon';
            }
        }
    } elseif ($tracking_status === 'arriving') {
        $eta_text = 'Arriving soon';
    } elseif ($tracking_status === 'on_the_way' || $tracking_status === 'picked_up') {
        $eta_text = 'Driver en route';
    } elseif ($tracking_status === 'assigned') {
        $eta_text = 'Driver assigned';
    } elseif ($tracking_status === 'pending') {
        $eta_text = 'Finding a driver';
    }

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'order_type' => 'order',
        'order_number' => (string)($row['order_number'] ?? ''),
        'display_number' => (string)($row['order_number'] ?? ('#' . (int)($row['id'] ?? 0))),
        'total_amount' => isset($row['total_amount']) ? (float)$row['total_amount'] : 0.0,
        'delivery_address' => trim((string)($row['delivery_address'] ?? '')),
        'status' => $status_for_label,
        'status_label' => $status_label,
        'driver_name' => trim((string)($row['driver_name'] ?? '')),
        'eta_text' => $eta_text,
        'estimated_delivery' => ($estimated_delivery !== '') ? $estimated_delivery : null,
        'last_location_update' => !empty($row['last_location_update']) ? $row['last_location_update'] : null,
        'item_summary' => '',
        'details_url' => 'track_order.php?order_id=' . (int)($row['id'] ?? 0),
        'details_label' => 'View Order Tracking',
        'sort_timestamp' => (string)($row['tracking_updated_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? ''),
    ];
}

$orderStmt->close();

if (widgetTableExists($conn, 'pre_orders')) {
    $preSql = "
        SELECT
            po.id,
            po.product_name,
            po.quantity,
            po.total_price,
            po.delivery_address,
            po.delivery_method,
            po.pickup_location,
            po.preferred_pickup_date,
            po.preferred_pickup_time,
            po.reservation_status,
            po.created_at,
            po.updated_at
        FROM pre_orders po
        WHERE po.user_id = ?
          AND po.reservation_status IN ('pending', 'confirmed', 'in_preparation', 'ready_for_pickup')
        ORDER BY COALESCE(po.updated_at, po.created_at) DESC
        LIMIT 5
    ";

    $preStmt = $conn->prepare($preSql);
    if ($preStmt) {
        $preStmt->bind_param('i', $user_id);
        $preStmt->execute();
        $preResult = $preStmt->get_result();

        while ($row = $preResult->fetch_assoc()) {
            $status = strtolower(trim((string)($row['reservation_status'] ?? 'pending')));
            $statusLabelMap = [
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'in_preparation' => 'In Preparation',
                'ready_for_pickup' => 'Ready',
            ];
            $statusLabel = $statusLabelMap[$status] ?? ucfirst(str_replace('_', ' ', $status));

            $etaText = widgetReadableSchedule($row['preferred_pickup_date'] ?? '', $row['preferred_pickup_time'] ?? '');
            if ($etaText === '') {
                if ($status === 'ready_for_pickup') {
                    $etaText = 'Ready for pickup';
                } elseif ($status === 'in_preparation') {
                    $etaText = 'Being prepared';
                } elseif ($status === 'confirmed') {
                    $etaText = 'Confirmed and queued';
                } else {
                    $etaText = 'Schedule pending';
                }
            }

            $deliveryMethod = strtolower(trim((string)($row['delivery_method'] ?? 'pickup')));
            $address = '';
            if ($deliveryMethod === 'delivery') {
                $address = trim((string)($row['delivery_address'] ?? ''));
                if ($address === '') {
                    $address = 'Delivery address to be confirmed';
                }
            } else {
                $address = trim((string)($row['pickup_location'] ?? 'Pickup location to be confirmed'));
            }

            $productName = trim((string)($row['product_name'] ?? 'Pre-order item'));
            $quantity = (int)($row['quantity'] ?? 1);
            $itemSummary = $productName . ' x' . max(1, $quantity);

            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'order_type' => 'preorder',
                'order_number' => 'PO-' . (int)($row['id'] ?? 0),
                'display_number' => '#' . (int)($row['id'] ?? 0),
                'total_amount' => isset($row['total_price']) ? (float)$row['total_price'] : 0.0,
                'delivery_address' => $address,
                'status' => $status,
                'status_label' => $statusLabel,
                'driver_name' => '',
                'eta_text' => $etaText,
                'estimated_delivery' => null,
                'last_location_update' => null,
                'item_summary' => $itemSummary,
                'details_url' => 'preorder_details.php?id=' . (int)($row['id'] ?? 0),
                'details_label' => 'View Pre-Order Details',
                'sort_timestamp' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            ];
        }

        $preStmt->close();
    }
}

usort($items, function ($a, $b) {
    $aTs = widgetParseTimestamp($a['sort_timestamp'] ?? '');
    $bTs = widgetParseTimestamp($b['sort_timestamp'] ?? '');
    return $bTs <=> $aTs;
});

$items = array_slice($items, 0, 8);

foreach ($items as &$item) {
    unset($item['sort_timestamp']);
}
unset($item);

echo json_encode([
    'success' => true,
    'orders' => $items,
    'count' => count($items),
]);
