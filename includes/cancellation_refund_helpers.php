<?php
// Cancellation & Refund shared helpers
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/rbac.php';

function crh_ensure_session_started() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function crh_bind_params($stmt, array $params) {
    if (empty($params)) {
        return;
    }

    $types = '';
    $values = [];
    foreach ($params as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
            if ($value === null) {
                $value = null;
            }
        }
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
}

// Bridge CSRF helper names to project conventions
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        crh_ensure_session_started();
        if (function_exists('generateCSRFToken')) {
            return generateCSRFToken();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token) {
        crh_ensure_session_started();
        if (function_exists('validateCSRFToken')) {
            return validateCSRFToken($token);
        }
        if (empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Utility: fetch associative row
function db_fetch_one($sql, $params = []) {
    global $conn; // assumes includes/config.php sets $conn (mysqli)
    if (!$conn) {
        throw new Exception('Database connection is not available.');
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('DB Prepare Error: ' . $conn->error);
    }

    crh_bind_params($stmt, array_values($params));
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function db_execute($sql, $params = []) {
    global $conn;
    if (!$conn) {
        throw new Exception('Database connection is not available.');
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('DB Prepare Error: ' . $conn->error);
    }

    crh_bind_params($stmt, array_values($params));
    $ok = $stmt->execute();
    $insert_id = $stmt->insert_id;
    $affected = $stmt->affected_rows;
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) { throw new Exception('DB Error: ' . $err); }
    return [$insert_id, $affected];
}

function log_activity($user_id, $action, $entity_type, $entity_id, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;
    $sql = "INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)";
    db_execute($sql, [$user_id, $action, $entity_type, $entity_id, $json, $ip, $ua]);
}

function ensure_csrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

function require_login_json() {
    crh_ensure_session_started();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function current_user_is_legacy_admin() {
    crh_ensure_session_started();
    $user_type = strtolower((string)($_SESSION['user_type'] ?? ''));
    $role_id = intval($_SESSION['role_id'] ?? 0);
    return $user_type === 'admin' && $role_id <= 0;
}

function current_user_is_super_admin() {
    crh_ensure_session_started();
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_super_role = strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin';
    if (!$is_super_role || $user_id <= 0) {
        return false;
    }

    global $conn;
    if ($conn && function_exists('isApprovedFranchiseSellerAccount') && isApprovedFranchiseSellerAccount($conn, $user_id)) {
        return false;
    }

    if ($conn && function_exists('isSuperAdmin')) {
        return isSuperAdmin($conn, $user_id);
    }

    return true;
}

function require_admin_json() {
    $user_id = require_login_json();

    if (current_user_is_super_admin() || current_user_is_legacy_admin()) {
        return $user_id;
    }

    global $conn;
    if ($conn && function_exists('hasBackofficeAccess') && hasBackofficeAccess($conn, $user_id)) {
        return $user_id;
    }

    if (isset($_SESSION['has_backoffice_access']) && $_SESSION['has_backoffice_access']) {
        return $user_id;
    }

    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

function require_refund_permission_json($admin_id, $is_write = false) {
    if (current_user_is_super_admin() || current_user_is_legacy_admin()) {
        return;
    }

    global $conn;
    if (!$conn || !function_exists('hasPermission')) {
        if ($is_write) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission check unavailable']);
            exit;
        }
        return;
    }

    $allowed = $is_write
        ? hasPermission($conn, $admin_id, 'finance.manage')
        : (hasPermission($conn, $admin_id, 'finance.view') || hasPermission($conn, $admin_id, 'finance.manage'));

    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
}

// Refund computation based on created_at and cancellation time
function compute_refund_rule($created_at, $total_amount, $now = null) {
    $now = $now ?: new DateTime('now');
    $created = new DateTime($created_at);
    $diff = $now->getTimestamp() - $created->getTimestamp();
    // Full within 24h (86400 s), 80% after 24h but before processing (caller ensures status not completed/used)
    if ($diff <= 86400) {
        return ['rule' => 'FULL', 'percentage' => 100.0, 'amount' => round($total_amount, 2)];
    }
    return ['rule' => 'PARTIAL', 'percentage' => 80.0, 'amount' => round($total_amount * 0.8, 2)];
}

function json_out($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

?>
