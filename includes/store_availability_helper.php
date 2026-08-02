<?php

function sahTableExists($conn, string $table): bool
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    $table = trim($table);
    if ($table === '') {
        return false;
    }

    $safe = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    if (!$result) {
        return false;
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function sahColumnExists($conn, string $table, string $column): bool
{
    if (!($conn instanceof mysqli) || !sahTableExists($conn, $table)) {
        return false;
    }

    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!$result) {
        return false;
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function sahIndexExists($conn, string $table, string $index): bool
{
    if (!($conn instanceof mysqli) || !sahTableExists($conn, $table)) {
        return false;
    }

    $table = mysqli_real_escape_string($conn, $table);
    $index = mysqli_real_escape_string($conn, $index);
    $result = mysqli_query($conn, "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    if (!$result) {
        return false;
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function sahEnsureStoreLocationAvailabilitySchema($conn): void
{
    if (!($conn instanceof mysqli)) {
        return;
    }

    mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS store_locations (
            store_id INT AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT NULL,
            store_name VARCHAR(180) NOT NULL,
            address VARCHAR(255) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            province VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(60) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            opening_hours VARCHAR(120) DEFAULT NULL,
            opening_time TIME DEFAULT NULL,
            closing_time TIME DEFAULT NULL,
            operating_days VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
            availability_mode ENUM('schedule','manual') NOT NULL DEFAULT 'schedule',
            manual_status ENUM('open','away','closed') NOT NULL DEFAULT 'closed',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_owner_user_id (owner_user_id),
            KEY idx_store_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $alter_map = [
        'owner_user_id' => "ALTER TABLE store_locations ADD COLUMN owner_user_id INT NULL AFTER store_id",
        'opening_time' => "ALTER TABLE store_locations ADD COLUMN opening_time TIME NULL AFTER opening_hours",
        'closing_time' => "ALTER TABLE store_locations ADD COLUMN closing_time TIME NULL AFTER opening_time",
        'operating_days' => "ALTER TABLE store_locations ADD COLUMN operating_days VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7' AFTER closing_time",
        'availability_mode' => "ALTER TABLE store_locations ADD COLUMN availability_mode ENUM('schedule','manual') NOT NULL DEFAULT 'schedule' AFTER operating_days",
        'manual_status' => "ALTER TABLE store_locations ADD COLUMN manual_status ENUM('open','away','closed') NOT NULL DEFAULT 'closed' AFTER availability_mode",
        'created_at' => "ALTER TABLE store_locations ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($alter_map as $column => $sql) {
        if (!sahColumnExists($conn, 'store_locations', $column)) {
            mysqli_query($conn, $sql);
        }
    }

    if (!sahColumnExists($conn, 'store_locations', 'updated_at')) {
        $updated_sql = sahColumnExists($conn, 'store_locations', 'created_at')
            ? "ALTER TABLE store_locations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
            : "ALTER TABLE store_locations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        mysqli_query($conn, $updated_sql);
    }

    if (!sahIndexExists($conn, 'store_locations', 'idx_owner_user_id')) {
        mysqli_query($conn, "ALTER TABLE store_locations ADD INDEX idx_owner_user_id (owner_user_id)");
    }

    if (!sahIndexExists($conn, 'store_locations', 'idx_store_email')) {
        mysqli_query($conn, "ALTER TABLE store_locations ADD INDEX idx_store_email (email)");
    }
}

function sahNormalizeOperatingDays($days): string
{
    if (!is_array($days)) {
        $days = array_filter(array_map('trim', explode(',', (string)$days)));
    }

    $normalized = [];
    foreach ($days as $day) {
        $day_number = (int)$day;
        if ($day_number >= 1 && $day_number <= 7) {
            $normalized[$day_number] = (string)$day_number;
        }
    }

    if (empty($normalized)) {
        return '1,2,3,4,5,6,7';
    }

    ksort($normalized);
    return implode(',', array_values($normalized));
}

function sahFormatOperatingDays(string $days_csv): string
{
    $map = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    $days = array_filter(array_map('intval', explode(',', $days_csv)));
    if ($days === [1, 2, 3, 4, 5, 6, 7]) {
        return 'Daily';
    }

    $labels = [];
    foreach ($days as $day) {
        if (isset($map[$day])) {
            $labels[] = $map[$day];
        }
    }

    return !empty($labels) ? implode(', ', $labels) : 'Daily';
}

function sahFormatTime(?string $time_value): string
{
    $time_value = trim((string)$time_value);
    if ($time_value === '') {
        return '';
    }

    $timestamp = strtotime($time_value);
    if ($timestamp === false) {
        return $time_value;
    }

    return date('g:i A', $timestamp);
}

function sahBuildOpeningHours(?string $opening_time, ?string $closing_time, string $operating_days = '1,2,3,4,5,6,7'): string
{
    $open_label = sahFormatTime($opening_time);
    $close_label = sahFormatTime($closing_time);
    $days_label = sahFormatOperatingDays($operating_days);

    if ($open_label !== '' && $close_label !== '') {
        return $days_label . ' | ' . $open_label . ' - ' . $close_label;
    }

    return $days_label;
}

function sahTimeToSeconds(?string $time_value): ?int
{
    $time_value = trim((string)$time_value);
    if ($time_value === '') {
        return null;
    }

    $timestamp = strtotime($time_value);
    if ($timestamp === false) {
        return null;
    }

    return ((int)date('H', $timestamp) * 3600) + ((int)date('i', $timestamp) * 60) + (int)date('s', $timestamp);
}

function sahIsScheduleDayAllowed(string $operating_days, ?string $opening_time, ?string $closing_time, ?DateTimeInterface $now = null): bool
{
    $now = $now ?: new DateTimeImmutable('now');
    $open_days = array_map('intval', array_filter(explode(',', $operating_days)));
    if (empty($open_days)) {
        return false;
    }

    $today_iso = (int)$now->format('N');
    if (in_array($today_iso, $open_days, true)) {
        return true;
    }

    $opening_seconds = sahTimeToSeconds($opening_time);
    $closing_seconds = sahTimeToSeconds($closing_time);
    if ($opening_seconds === null || $closing_seconds === null) {
        return false;
    }

    $is_overnight = $closing_seconds <= $opening_seconds;
    if (!$is_overnight) {
        return false;
    }

    $current_seconds = ((int)$now->format('H') * 3600) + ((int)$now->format('i') * 60) + (int)$now->format('s');
    $previous_day_iso = $today_iso === 1 ? 7 : ($today_iso - 1);

    return $current_seconds <= $closing_seconds && in_array($previous_day_iso, $open_days, true);
}

function sahIsTimeWithinSchedule(?string $opening_time, ?string $closing_time, ?DateTimeInterface $now = null): bool
{
    $opening_time = trim((string)$opening_time);
    $closing_time = trim((string)$closing_time);
    if ($opening_time === '' || $closing_time === '') {
        return false;
    }

    $now = $now ?: new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    $open_at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . date('H:i:s', strtotime($opening_time)));
    $close_at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . date('H:i:s', strtotime($closing_time)));

    if (!$open_at || !$close_at) {
        return false;
    }

    if ($close_at <= $open_at) {
        $close_at = $close_at->modify('+1 day');
        if ($now < $open_at) {
            $now = $now->modify('+1 day');
        }
    }

    return $now >= $open_at && $now <= $close_at;
}

function sahResolveStoreAvailability(array $store_row, ?DateTimeInterface $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now');
    $is_active = (int)($store_row['is_active'] ?? 1) === 1;
    $mode = strtolower(trim((string)($store_row['availability_mode'] ?? 'schedule')));
    $manual_status = strtolower(trim((string)($store_row['manual_status'] ?? 'closed')));
    $opening_time = trim((string)($store_row['opening_time'] ?? '08:00:00'));
    $closing_time = trim((string)($store_row['closing_time'] ?? '20:00:00'));
    $operating_days = sahNormalizeOperatingDays((string)($store_row['operating_days'] ?? '1,2,3,4,5,6,7'));
    $day_allowed = sahIsScheduleDayAllowed($operating_days, $opening_time, $closing_time, $now);
    $schedule_label = sahBuildOpeningHours($opening_time, $closing_time, $operating_days);

    if (!$is_active) {
        return [
            'code' => 'closed',
            'label' => 'Closed',
            'class' => 'closed',
            'note' => 'Store is currently unavailable.',
            'schedule' => $schedule_label,
            'is_open' => false,
        ];
    }

    if ($mode === 'manual') {
        $manual_label_map = [
            'open' => ['Open', 'open', 'Store manually marked as open.'],
            'away' => ['Away', 'away', 'Store is temporarily away from live operations.'],
            'closed' => ['Closed', 'closed', 'Store is manually marked as closed for now.'],
        ];
        $status = $manual_label_map[$manual_status] ?? $manual_label_map['closed'];

        return [
            'code' => strtolower($status[0]),
            'label' => $status[0],
            'class' => $status[1],
            'note' => $status[2],
            'schedule' => $schedule_label,
            'is_open' => $status[1] === 'open',
        ];
    }

    if (!$day_allowed) {
        return [
            'code' => 'closed',
            'label' => 'Closed',
            'class' => 'closed',
            'note' => 'Store opens on scheduled operating days only.',
            'schedule' => $schedule_label,
            'is_open' => false,
        ];
    }

    if (sahIsTimeWithinSchedule($opening_time, $closing_time, $now)) {
        return [
            'code' => 'open',
            'label' => 'Open',
            'class' => 'open',
            'note' => 'Open now based on the store schedule.',
            'schedule' => $schedule_label,
            'is_open' => true,
        ];
    }

    return [
        'code' => 'closed',
        'label' => 'Closed',
        'class' => 'closed',
        'note' => 'Closed right now based on the store schedule.',
        'schedule' => $schedule_label,
        'is_open' => false,
    ];
}

function sahBuildStoreAddressFromApplication(array $app_data): string
{
    $address = trim((string)($app_data['business_address'] ?? ''));
    if ($address !== '') {
        return $address;
    }

    $parts = array_filter([
        trim((string)($app_data['business_address_street'] ?? '')),
        trim((string)($app_data['barangay_name'] ?? '')),
        trim((string)($app_data['city_name'] ?? '')),
        trim((string)($app_data['province_name'] ?? '')),
        trim((string)($app_data['region_name'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    });

    return !empty($parts) ? implode(', ', $parts) : 'TBD - exact store address to be finalized';
}

function sahUpsertPartnerStoreLocation($conn, array $app_data): array
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    sahEnsureStoreLocationAvailabilitySchema($conn);

    $owner_user_id = (int)($app_data['user_id'] ?? 0);
    $store_name = trim((string)($app_data['business_name'] ?? 'Franchise Store'));
    $address = sahBuildStoreAddressFromApplication($app_data);
    $city = trim((string)($app_data['city_name'] ?? ''));
    $province = trim((string)($app_data['province_name'] ?? ''));
    $contact_phone = trim((string)($app_data['contact_phone'] ?? ''));
    $contact_email = trim((string)($app_data['contact_email'] ?? ''));
    if ($contact_email === '' || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $contact_email = 'franchise+' . max(1, $owner_user_id) . '@lechondelights.local';
    }

    $opening_time = '08:00:00';
    $closing_time = '20:00:00';
    $operating_days = '1,2,3,4,5,6,7';
    $opening_hours = sahBuildOpeningHours($opening_time, $closing_time, $operating_days);
    $availability_mode = 'schedule';
    $manual_status = 'closed';
    $existing_id = 0;

    $find_stmt = mysqli_prepare(
        $conn,
        "SELECT store_id
         FROM store_locations
         WHERE (owner_user_id IS NOT NULL AND owner_user_id = ?)
            OR email = ?
            OR (store_name = ? AND address = ?)
         ORDER BY store_id ASC
         LIMIT 1"
    );
    if (!$find_stmt) {
        throw new RuntimeException('Unable to prepare store lookup query.');
    }
    mysqli_stmt_bind_param($find_stmt, "isss", $owner_user_id, $contact_email, $store_name, $address);
    mysqli_stmt_execute($find_stmt);
    $find_result = mysqli_stmt_get_result($find_stmt);
    $existing = $find_result ? mysqli_fetch_assoc($find_result) : null;
    mysqli_stmt_close($find_stmt);
    $existing_id = (int)($existing['store_id'] ?? 0);

    if ($existing_id > 0) {
        $update_stmt = mysqli_prepare(
            $conn,
            "UPDATE store_locations
             SET owner_user_id = ?, store_name = ?, address = ?, city = ?, province = ?, phone = ?, email = ?,
                 opening_hours = ?, opening_time = ?, closing_time = ?, operating_days = ?, availability_mode = ?,
                 is_active = 1
             WHERE store_id = ?"
        );
        if (!$update_stmt) {
            throw new RuntimeException('Unable to prepare store update query.');
        }
        mysqli_stmt_bind_param(
            $update_stmt,
            "isssssssssssi",
            $owner_user_id,
            $store_name,
            $address,
            $city,
            $province,
            $contact_phone,
            $contact_email,
            $opening_hours,
            $opening_time,
            $closing_time,
            $operating_days,
            $availability_mode,
            $existing_id
        );
        if (!mysqli_stmt_execute($update_stmt)) {
            $message = trim((string)mysqli_stmt_error($update_stmt));
            mysqli_stmt_close($update_stmt);
            throw new RuntimeException($message !== '' ? $message : 'Failed to update store location.');
        }
        mysqli_stmt_close($update_stmt);
        return ['store_id' => $existing_id, 'created' => false];
    }

    $insert_stmt = mysqli_prepare(
        $conn,
        "INSERT INTO store_locations
            (owner_user_id, store_name, address, city, province, phone, email, opening_hours, opening_time, closing_time, operating_days, availability_mode, manual_status, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    if (!$insert_stmt) {
        throw new RuntimeException('Unable to prepare store insert query.');
    }
    mysqli_stmt_bind_param(
        $insert_stmt,
        "issssssssssss",
        $owner_user_id,
        $store_name,
        $address,
        $city,
        $province,
        $contact_phone,
        $contact_email,
        $opening_hours,
        $opening_time,
        $closing_time,
        $operating_days,
        $availability_mode,
        $manual_status
    );
    if (!mysqli_stmt_execute($insert_stmt)) {
        $message = trim((string)mysqli_stmt_error($insert_stmt));
        mysqli_stmt_close($insert_stmt);
        throw new RuntimeException($message !== '' ? $message : 'Failed to create store location.');
    }

    $new_store_id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($insert_stmt);
    return ['store_id' => $new_store_id, 'created' => true];
}

function sahFetchPartnerStoreLocations($conn, int $owner_user_id): array
{
    if (!($conn instanceof mysqli) || $owner_user_id <= 0) {
        return [];
    }

    sahEnsureStoreLocationAvailabilitySchema($conn);

    $email = '';
    $email_stmt = mysqli_prepare($conn, "SELECT email FROM users WHERE id = ? LIMIT 1");
    if ($email_stmt) {
        mysqli_stmt_bind_param($email_stmt, "i", $owner_user_id);
        mysqli_stmt_execute($email_stmt);
        $result = mysqli_stmt_get_result($email_stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($email_stmt);
        $email = strtolower(trim((string)($row['email'] ?? '')));
    }

    $rows = [];
    $sql = "SELECT store_id, owner_user_id, store_name, address, city, province, phone, email, opening_hours,
                   opening_time, closing_time, operating_days, availability_mode, manual_status, is_active,
                   latitude, longitude
            FROM store_locations
            WHERE owner_user_id = ?";
    $types = 'i';
    $params = [$owner_user_id];
    if ($email !== '') {
        $sql .= " OR email = ?";
        $types .= 's';
        $params[] = $email;
    }
    $sql .= " ORDER BY store_name ASC, store_id ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['availability'] = sahResolveStoreAvailability($row);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $rows;
}
