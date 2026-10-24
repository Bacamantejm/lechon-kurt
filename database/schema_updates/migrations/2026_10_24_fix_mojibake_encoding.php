<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/security.php';

echo "Running migration: fix_mojibake_encoding...\n";

if (!function_exists('fixMojibakeUtf8')) {
    function fixMojibakeUtf8($text) {
        if ($text === null || $text === '') return '';
        $text = (string)$text;
        static $replacements = [
            'Dasmari├▒as' => 'Dasmariñas',
            'Dasmari├æas' => 'Dasmariñas',
            'DasmariÃ±as' => 'Dasmariñas',
            'DasmariÃ‘as' => 'Dasmariñas',
            'DasmariÃƒÂ±as' => 'Dasmariñas',
            'DasmariΓö£ΓûÆas' => 'Dasmariñas',
            'Dasmari├â╞Æ├é┬▒as' => 'Dasmariñas',
            'Dasmari├â┬▒as' => 'Dasmariñas',
            'City of Dasmarinas' => 'City of Dasmariñas',
            'City of Dasmari├▒as' => 'City of Dasmariñas',
            '├▒' => 'ñ',
            '├æ' => 'Ñ',
            'Ã±' => 'ñ',
            'Ã‘' => 'Ñ',
            'ÃƒÂ±' => 'ñ',
            'Γö£ΓûÆ' => 'ñ',
            '├â╞Æ├é┬▒' => 'ñ',
            '├â┬▒' => 'ñ',
            'ΓÇô' => '–',
            'ΓÇö' => '—',
            'ΓÇÿ' => '‘',
            'ΓÇÖ' => '’',
            'â€™' => '’',
            'â€œ' => '“',
            'â€' => '”',
            'â€“' => '–',
            'â€”' => '—',
            'Cavite╬ô├ç├┤Laguna' => 'Cavite–Laguna',
            'CaviteΓÇôLaguna' => 'Cavite–Laguna'
        ];
        $cleaned = str_replace(array_keys($replacements), array_values($replacements), $text);
        return preg_replace('/Dasmari(?:[^a-zA-Z0-9\s]{1,6}|n)as/ui', 'Dasmariñas', $cleaned);
    }
}

$tables_cols = [
    'franchise_applications' => ['business_name', 'business_address', 'proposed_location', 'city_name', 'province_name', 'barangay_name', 'admin_notes'],
    'store_locations' => ['store_name', 'address', 'city', 'province'],
    'users' => ['full_name', 'address', 'business_name'],
    'user_saved_addresses' => ['address_label', 'street_address', 'city', 'province', 'barangay', 'full_address'],
    'orders' => ['shipping_address', 'customer_name', 'delivery_notes'],
    'logistics_tracking' => ['delivery_notes', 'driver_name', 'condition_notes'],
    'branches' => ['name', 'address'],
    'system_settings' => ['setting_value']
];

$total_repaired = 0;

foreach ($tables_cols as $table => $cols) {
    $chk_t = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (!$chk_t || mysqli_num_rows($chk_t) === 0) continue;

    $valid_cols = [];
    foreach ($cols as $col) {
        $chk_c = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        if ($chk_c && mysqli_num_rows($chk_c) > 0) {
            $valid_cols[] = $col;
        }
    }
    if (empty($valid_cols)) continue;

    $chk_id = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE 'id'");
    $id_col = ($chk_id && mysqli_num_rows($chk_id) > 0) ? 'id' : $valid_cols[0];

    $cols_sql = implode(', ', array_map(fn($c) => "`$c`", array_merge([$id_col], $valid_cols)));
    $res = mysqli_query($conn, "SELECT $cols_sql FROM `$table`");
    if (!$res) continue;

    while ($row = mysqli_fetch_assoc($res)) {
        $updates = [];
        $params = [];
        $types = '';

        foreach ($valid_cols as $c) {
            $orig = $row[$c];
            if ($orig !== null) {
                $fixed = fixMojibakeUtf8($orig);
                if ($fixed !== $orig) {
                    $updates[] = "`$c` = ?";
                    $params[] = $fixed;
                    $types .= 's';
                }
            }
        }

        if (!empty($updates)) {
            $u_sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE `$id_col` = ?";
            $params[] = $row[$id_col];
            $types .= is_int($row[$id_col]) ? 'i' : 's';

            $u_stmt = mysqli_prepare($conn, $u_sql);
            if ($u_stmt) {
                mysqli_stmt_bind_param($u_stmt, $types, ...$params);
                mysqli_stmt_execute($u_stmt);
                mysqli_stmt_close($u_stmt);
                $total_repaired++;
                echo "Repaired $table ID {$row[$id_col]}: " . implode(', ', $updates) . "\n";
            }
        }
    }
}

echo "Migration complete. Total repaired records: $total_repaired.\n";
