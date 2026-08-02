<?php
session_start();
require_once '../includes/config.php';
require_once 'auth.php';
checkAdminAccess();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'get';

header('Content-Type: application/json');

function notifTableExists($conn, string $table_name): bool {
    static $cache = [];
    $table_name = trim($table_name);
    if ($table_name === '') {
        return false;
    }
    if (array_key_exists($table_name, $cache)) {
        return (bool)$cache[$table_name];
    }
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    $exists = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    if ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
    $cache[$table_name] = $exists;
    return $exists;
}

function notifColumnExists($conn, string $table_name, string $column_name): bool {
    static $cache = [];
    $key = $table_name . '.' . $column_name;
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }
    if (!notifTableExists($conn, $table_name)) {
        $cache[$key] = false;
        return false;
    }
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_column = mysqli_real_escape_string($conn, $column_name);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    $exists = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    if ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
    $cache[$key] = $exists;
    return $exists;
}

function buildTenantNotificationScopeSql($conn, int $partner_scope_owner_id): string {
    if ($partner_scope_owner_id <= 0) {
        return "(n.related_type IS NULL OR n.related_type = '' OR n.related_type NOT IN ('order','pre_order'))";
    }

    $scope_ids = [];
    if (function_exists('getFranchiseSellerScopeUserIds')) {
        $scope_ids = getFranchiseSellerScopeUserIds($conn, $partner_scope_owner_id);
    }
    $scope_ids = array_values(array_unique(array_filter(array_map('intval', $scope_ids), static function($id) {
        return $id > 0;
    })));
    if (empty($scope_ids)) {
        $scope_ids = [$partner_scope_owner_id];
    }
    $scope_list = implode(',', $scope_ids);

    $scope_conditions = [
        "n.related_type IS NULL",
        "n.related_type = ''",
        "n.related_type NOT IN ('order','pre_order')"
    ];

    if (notifTableExists($conn, 'orders')) {
        if (notifColumnExists($conn, 'orders', 'seller_id')) {
            $scope_conditions[] = "(n.related_type = 'order' AND EXISTS (
                SELECT 1 FROM orders o
                WHERE o.id = n.related_id
                  AND o.seller_id IN ({$scope_list})
            ))";
        } elseif (
            notifColumnExists($conn, 'orders', 'pickup_location')
            && notifTableExists($conn, 'store_locations')
            && notifColumnExists($conn, 'store_locations', 'store_id')
            && notifColumnExists($conn, 'store_locations', 'owner_user_id')
        ) {
            $scope_conditions[] = "(n.related_type = 'order' AND EXISTS (
                SELECT 1
                FROM orders o
                INNER JOIN store_locations sl ON sl.store_id = o.pickup_location
                WHERE o.id = n.related_id
                  AND sl.owner_user_id IN ({$scope_list})
            ))";
        }
    }

    if (notifTableExists($conn, 'pre_orders')) {
        if (notifColumnExists($conn, 'pre_orders', 'seller_id')) {
            $scope_conditions[] = "(n.related_type = 'pre_order' AND EXISTS (
                SELECT 1 FROM pre_orders po
                WHERE po.id = n.related_id
                  AND po.seller_id IN ({$scope_list})
            ))";
        } elseif (
            notifColumnExists($conn, 'pre_orders', 'product_id')
            && notifTableExists($conn, 'products')
            && notifColumnExists($conn, 'products', 'id')
            && notifColumnExists($conn, 'products', 'seller_id')
        ) {
            $scope_conditions[] = "(n.related_type = 'pre_order' AND EXISTS (
                SELECT 1
                FROM pre_orders po
                INNER JOIN products p ON p.id = po.product_id
                WHERE po.id = n.related_id
                  AND p.seller_id IN ({$scope_list})
            ))";
        }
    }

    return '(' . implode(' OR ', $scope_conditions) . ')';
}

function getTenantNotificationWhereSql($conn, int $user_id): string {
    if (
        function_exists('isApprovedFranchiseSellerAccount')
        && function_exists('getFranchiseSellerScopeOwnerId')
        && isApprovedFranchiseSellerAccount($conn, $user_id)
    ) {
        $partner_scope_owner_id = (int)(getFranchiseSellerScopeOwnerId($conn, $user_id) ?? 0);
        return ' AND ' . buildTenantNotificationScopeSql($conn, $partner_scope_owner_id);
    }
    return '';
}

$tenant_scope_sql = getTenantNotificationWhereSql($conn, (int)$user_id);

if ($action === 'count') {
    $query = "SELECT COUNT(*) as count FROM notifications n WHERE n.user_id = ? AND n.is_read = 0{$tenant_scope_sql}";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    echo json_encode(['count' => $row['count']]);
} elseif ($action === 'get') {
    $query = "SELECT n.* FROM notifications n WHERE n.user_id = ?{$tenant_scope_sql} ORDER BY n.created_at DESC LIMIT 10";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $notifications = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Format date for display
        $row['time_ago'] = time_elapsed_string($row['created_at']);
        $notifications[] = $row;
    }
    echo json_encode($notifications);
} elseif ($action === 'mark_read') {
    $notif_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if ($notif_id > 0) {
        $query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $notif_id, $user_id);
        mysqli_stmt_execute($stmt);
    } else {
        // Mark all as read
        $query = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
    }
    echo json_encode(['success' => true]);
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($k === 'w') {
            $val = $weeks;
        } elseif ($k === 'd') {
            $val = $days;
        } else {
            $val = $diff->$k;
        }

        if ($val) {
            $v = $val . ' ' . $v . ($val > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
