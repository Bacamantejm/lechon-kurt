<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner_dashboard_helper.php';

if (!pdhEnsureProductViewEventsSchema($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'View tracking service is not ready.'
    ]);
    exit;
}

$payload = [];
$rawInput = file_get_contents('php://input');
if (is_string($rawInput) && trim($rawInput) !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!is_array($payload) || empty($payload)) {
    $payload = $_POST;
}

$rawProductIds = $payload['product_ids'] ?? [];
if (!is_array($rawProductIds)) {
    $rawProductIds = [$rawProductIds];
}

$productIds = [];
foreach ($rawProductIds as $rawId) {
    $id = (int)$rawId;
    if ($id > 0) {
        $productIds[$id] = true;
    }
}
$productIds = array_slice(array_keys($productIds), 0, 120);

if (empty($productIds)) {
    echo json_encode([
        'success' => true,
        'tracked' => 0
    ]);
    exit;
}

if (empty($_SESSION['product_view_session_token'])) {
    try {
        $_SESSION['product_view_session_token'] = bin2hex(random_bytes(18));
    } catch (Throwable $e) {
        $_SESSION['product_view_session_token'] = session_id() ?: ('sid_' . mt_rand(100000, 999999));
    }
}
$sessionToken = substr(trim((string)$_SESSION['product_view_session_token']), 0, 120);
if ($sessionToken === '') {
    $sessionToken = session_id() ?: ('sid_' . mt_rand(100000, 999999));
}

$viewerUserId = (int)($_SESSION['user_id'] ?? 0);
if ($viewerUserId <= 0) {
    $viewerUserId = 0;
}

$idSql = implode(',', array_map('intval', $productIds));
$productsQuery = "SELECT id, seller_id FROM products WHERE is_archived = 0 AND id IN ({$idSql})";
$productsResult = mysqli_query($conn, $productsQuery);
if (!$productsResult) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load products for tracking.'
    ]);
    exit;
}

$productsToTrack = [];
while ($row = mysqli_fetch_assoc($productsResult)) {
    $productId = (int)($row['id'] ?? 0);
    if ($productId <= 0) {
        continue;
    }
    $productsToTrack[] = [
        'product_id' => $productId,
        'seller_id' => isset($row['seller_id']) ? (int)$row['seller_id'] : 0
    ];
}
mysqli_free_result($productsResult);

if (empty($productsToTrack)) {
    echo json_encode([
        'success' => true,
        'tracked' => 0
    ]);
    exit;
}

$insertSql = "
    INSERT INTO product_view_events (
        product_id, seller_id, viewer_user_id, session_token, view_date, first_viewed_at, last_viewed_at, view_count
    ) VALUES (?, ?, ?, ?, CURDATE(), NOW(), NOW(), 1)
    ON DUPLICATE KEY UPDATE
        seller_id = COALESCE(product_view_events.seller_id, VALUES(seller_id)),
        viewer_user_id = COALESCE(product_view_events.viewer_user_id, VALUES(viewer_user_id)),
        last_viewed_at = NOW()
";
$insertStmt = mysqli_prepare($conn, $insertSql);
if (!$insertStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'View tracking statement failed to initialize.'
    ]);
    exit;
}

$tracked = 0;
foreach ($productsToTrack as $productRow) {
    $productId = (int)$productRow['product_id'];
    $sellerId = (int)($productRow['seller_id'] ?? 0);
    $sellerIdParam = $sellerId > 0 ? $sellerId : 0;
    $viewerUserIdParam = $viewerUserId > 0 ? $viewerUserId : 0;
    mysqli_stmt_bind_param($insertStmt, "iiis", $productId, $sellerIdParam, $viewerUserIdParam, $sessionToken);
    if (mysqli_stmt_execute($insertStmt)) {
        $tracked++;
    }
}
mysqli_stmt_close($insertStmt);

echo json_encode([
    'success' => true,
    'tracked' => $tracked
]);
