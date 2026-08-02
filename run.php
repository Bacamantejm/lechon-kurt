<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

function runSqlFile(mysqli $conn, string $file_path): array
{
    if (!is_file($file_path)) {
        throw new RuntimeException('SQL file not found: ' . $file_path);
    }

    $sql = file_get_contents($file_path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('SQL file is empty or unreadable: ' . $file_path);
    }

    if (!mysqli_multi_query($conn, $sql)) {
        throw new RuntimeException('Failed to execute SQL batch: ' . mysqli_error($conn));
    }

    $result_sets = 0;
    do {
        $result = mysqli_store_result($conn);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
        $result_sets++;
    } while (mysqli_more_results($conn) && mysqli_next_result($conn));

    if (mysqli_errno($conn) !== 0) {
        throw new RuntimeException('SQL batch error: ' . mysqli_error($conn));
    }

    return ['result_sets' => $result_sets];
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return $rows;
    }
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function getTask(): string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        return isset($argv[1]) ? trim((string)$argv[1]) : 'bulk500';
    }
    return trim((string)($_GET['task'] ?? 'bulk500'));
}

function sendJson(array $payload, int $status_code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

$task = getTask();
$task_map = [
    'bulk500' => [
        'file' => __DIR__ . '/database/bulk_seed_500_per_store.sql',
        'tag' => '[BULK-SEED-500]'
    ],
    'sample' => [
        'file' => __DIR__ . '/database/sample_platform_seed.sql',
        'tag' => '[DEMO-SEED]'
    ]
];

if (!isset($task_map[$task])) {
    sendJson([
        'success' => false,
        'message' => 'Invalid task. Use "bulk500" or "sample".',
        'task' => $task
    ], 400);
}

try {
    $seed = $task_map[$task];
    $exec = runSqlFile($conn, $seed['file']);
    $tag = mysqli_real_escape_string($conn, $seed['tag']);

    $store_summary_sql = "
        SELECT
            sl.store_id,
            sl.store_name,
            (
                SELECT COUNT(*)
                FROM orders o
                WHERE o.special_instructions LIKE CONCAT('{$tag}', '%[STORE=', sl.store_id, ']%')
            ) AS seeded_orders,
            (
                SELECT COUNT(*)
                FROM pre_orders po
                WHERE po.notes LIKE CONCAT('{$tag}', '%[STORE=', sl.store_id, ']%')
            ) AS seeded_preorders,
            (
                SELECT COUNT(DISTINCT oi.product_id)
                FROM orders o
                INNER JOIN order_items oi ON oi.order_id = o.id
                WHERE o.special_instructions LIKE CONCAT('{$tag}', '%[STORE=', sl.store_id, ']%')
            ) AS distinct_order_products,
            (
                SELECT COUNT(DISTINCT pr.product_id)
                FROM product_reviews pr
                INNER JOIN orders o ON o.id = pr.order_id
                WHERE pr.comment LIKE CONCAT('{$tag}', '%')
                  AND o.special_instructions LIKE CONCAT('{$tag}', '%[STORE=', sl.store_id, ']%')
            ) AS distinct_reviewed_products
        FROM store_locations sl
        WHERE sl.is_active = 1
        ORDER BY sl.store_id
    ";

    $total_orders_sql = "SELECT COUNT(*) AS total FROM orders WHERE special_instructions LIKE CONCAT('{$tag}', '%')";
    $total_preorders_sql = "SELECT COUNT(*) AS total FROM pre_orders WHERE notes LIKE CONCAT('{$tag}', '%')";
    $total_product_reviews_sql = "SELECT COUNT(*) AS total FROM product_reviews WHERE comment LIKE CONCAT('{$tag}', '%')";
    $total_delivery_reviews_sql = "SELECT COUNT(*) AS total FROM delivery_reviews WHERE comment LIKE CONCAT('{$tag}', '%')";

    $store_summary = fetchAllAssoc($conn, $store_summary_sql);
    $total_orders = fetchAllAssoc($conn, $total_orders_sql);
    $total_preorders = fetchAllAssoc($conn, $total_preorders_sql);
    $total_product_reviews = fetchAllAssoc($conn, $total_product_reviews_sql);
    $total_delivery_reviews = fetchAllAssoc($conn, $total_delivery_reviews_sql);

    sendJson([
        'success' => true,
        'message' => 'Seed task completed.',
        'task' => $task,
        'sql_file' => $seed['file'],
        'result_sets' => $exec['result_sets'],
        'totals' => [
            'orders' => (int)($total_orders[0]['total'] ?? 0),
            'pre_orders' => (int)($total_preorders[0]['total'] ?? 0),
            'product_reviews' => (int)($total_product_reviews[0]['total'] ?? 0),
            'delivery_reviews' => (int)($total_delivery_reviews[0]['total'] ?? 0)
        ],
        'store_summary' => $store_summary
    ]);
} catch (Throwable $e) {
    sendJson([
        'success' => false,
        'message' => $e->getMessage(),
        'task' => $task
    ], 500);
}
