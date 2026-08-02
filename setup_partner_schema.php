<?php
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/admin/auth.php';

if (PHP_SAPI !== 'cli') {
    checkAdminAccess();
    if (($_SESSION['role_name'] ?? '') !== 'super_admin') {
        requirePermission('users.edit');
    }
}

function setupColumnExists(mysqli $conn, string $table, string $column): bool {
    $table_safe = mysqli_real_escape_string($conn, $table);
    $column_safe = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `{$table_safe}` LIKE '{$column_safe}'";
    $res = mysqli_query($conn, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

function setupIndexExists(mysqli $conn, string $table, string $index): bool {
    $table_safe = mysqli_real_escape_string($conn, $table);
    $index_safe = mysqli_real_escape_string($conn, $index);
    $sql = "SHOW INDEX FROM `{$table_safe}` WHERE Key_name = '{$index_safe}'";
    $res = mysqli_query($conn, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

function setupForeignKeyExists(mysqli $conn, string $table, string $constraint): bool {
    $table_safe = mysqli_real_escape_string($conn, $table);
    $constraint_safe = mysqli_real_escape_string($conn, $constraint);
    $sql = "SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table_safe}'
              AND CONSTRAINT_NAME = '{$constraint_safe}'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

$steps = [];

$logStep = function (string $name, bool $ok, string $message) use (&$steps): void {
    $steps[] = [
        'name' => $name,
        'ok' => $ok,
        'message' => $message
    ];
};

mysqli_begin_transaction($conn);

try {
    if (!setupColumnExists($conn, 'products', 'seller_id')) {
        $added = mysqli_query($conn, "ALTER TABLE products ADD COLUMN seller_id INT(11) DEFAULT NULL AFTER product_id");
        if (!$added) {
            throw new RuntimeException('Failed to add products.seller_id: ' . mysqli_error($conn));
        }
        $logStep('Schema', true, 'Added `products.seller_id` column.');
    } else {
        $logStep('Schema', true, '`products.seller_id` already exists.');
    }

    if (!setupIndexExists($conn, 'products', 'seller_id_idx')) {
        $indexed = mysqli_query($conn, "ALTER TABLE products ADD KEY seller_id_idx (seller_id)");
        if (!$indexed) {
            throw new RuntimeException('Failed to add seller_id index: ' . mysqli_error($conn));
        }
        $logStep('Schema', true, 'Added `seller_id_idx` index on products.');
    } else {
        $logStep('Schema', true, '`seller_id_idx` already exists.');
    }

    $cleanup_orphans = mysqli_query(
        $conn,
        "UPDATE products p
         LEFT JOIN users u ON u.id = p.seller_id
         SET p.seller_id = NULL
         WHERE p.seller_id IS NOT NULL
           AND u.id IS NULL"
    );
    if (!$cleanup_orphans) {
        throw new RuntimeException('Failed to normalize orphan seller IDs: ' . mysqli_error($conn));
    }
    $logStep('Data', true, 'Normalized orphan product seller references: ' . mysqli_affected_rows($conn) . ' row(s).');

    if (!setupForeignKeyExists($conn, 'products', 'fk_products_seller')) {
        $fk_added = mysqli_query(
            $conn,
            "ALTER TABLE products
             ADD CONSTRAINT fk_products_seller
             FOREIGN KEY (seller_id) REFERENCES users(id)
             ON DELETE SET NULL
             ON UPDATE CASCADE"
        );
        if (!$fk_added) {
            throw new RuntimeException('Failed to add FK `fk_products_seller`: ' . mysqli_error($conn));
        }
        $logStep('Schema', true, 'Added foreign key `fk_products_seller`.');
    } else {
        $logStep('Schema', true, 'Foreign key `fk_products_seller` already exists.');
    }

    $business_owner_role_id = 0;
    $role_res = mysqli_query($conn, "SELECT id FROM roles WHERE name = 'business_owner' AND is_active = 1 LIMIT 1");
    if ($role_res) {
        $role_row = mysqli_fetch_assoc($role_res);
        $business_owner_role_id = (int)($role_row['id'] ?? 0);
        mysqli_free_result($role_res);
    }

    $normalize_partner_accounts = mysqli_query(
        $conn,
        "UPDATE users u
         INNER JOIN franchise_applications fa
             ON fa.user_id = u.id
            AND fa.status = 'approved'
         LEFT JOIN roles r
             ON r.id = u.role_id
         SET u.account_type = 'organization',
             u.business_name = COALESCE(NULLIF(TRIM(u.business_name), ''), NULLIF(TRIM(fa.business_name), ''), u.business_name),
             u.business_type = COALESCE(NULLIF(TRIM(u.business_type), ''), NULLIF(TRIM(fa.business_type), ''), 'restaurant'),
             u.user_type = 'admin',
             u.role_id = CASE
                 WHEN " . (int)$business_owner_role_id . " > 0 THEN " . (int)$business_owner_role_id . "
                 ELSE u.role_id
             END,
             u.is_active = 1
         WHERE u.account_type = 'organization'
           AND (u.user_type <> 'admin' OR u.role_id IS NULL OR r.name = 'super_admin')"
    );
    if (!$normalize_partner_accounts) {
        throw new RuntimeException('Failed to normalize approved partner accounts: ' . mysqli_error($conn));
    }
    $logStep('Data', true, 'Normalized approved partner admin role assignment: ' . mysqli_affected_rows($conn) . ' user(s).');

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $logStep('Error', false, $e->getMessage());
}

$all_ok = true;
foreach ($steps as $step) {
    if (!$step['ok']) {
        $all_ok = false;
        break;
    }
}

if (PHP_SAPI === 'cli') {
    echo "Partner schema setup results:\n";
    foreach ($steps as $step) {
        echo ($step['ok'] ? '[OK] ' : '[FAIL] ') . $step['name'] . ' - ' . $step['message'] . "\n";
    }
    echo $all_ok ? "Completed.\n" : "Completed with errors.\n";
    exit($all_ok ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Schema Setup</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f6f8fb; margin: 0; padding: 24px; color: #1f2937; }
        .wrap { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        h1 { margin-top: 0; font-size: 24px; }
        .ok { color: #166534; }
        .fail { color: #991b1b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #e5e7eb; padding: 10px; text-align: left; }
        th { background: #f3f4f6; }
        .status { font-weight: bold; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Partner Schema Setup</h1>
        <p class="<?php echo $all_ok ? 'ok' : 'fail'; ?>">
            <?php echo $all_ok ? 'Setup completed successfully.' : 'Setup completed with errors.'; ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th>Step</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($steps as $step): ?>
                <tr>
                    <td><?php echo htmlspecialchars($step['name']); ?></td>
                    <td class="status <?php echo $step['ok'] ? 'ok' : 'fail'; ?>">
                        <?php echo $step['ok'] ? 'OK' : 'FAILED'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($step['message']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
