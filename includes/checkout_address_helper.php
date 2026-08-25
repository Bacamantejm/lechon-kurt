<?php
/**
 * Checkout Saved Address Helper Functions
 * Manages user saved addresses, PSGC hierarchical normalization, and address deduplication.
 */

if (!function_exists('caNormalizeAddressValue')) {
    function caNormalizeAddressValue($value, $max_length = 255) {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        if ($max_length > 0 && function_exists('mb_substr')) {
            return mb_substr($text, 0, $max_length);
        }

        return substr($text, 0, max(0, (int)$max_length));
    }
}

if (!function_exists('caBuildAddressFromSegments')) {
    function caBuildAddressFromSegments($street, $barangay, $city, $province, $region) {
        $parts = [
            caNormalizeAddressValue($street, 190),
            caNormalizeAddressValue($barangay, 120),
            caNormalizeAddressValue($city, 120),
            caNormalizeAddressValue($province, 120),
            caNormalizeAddressValue($region, 120)
        ];
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
        return implode(', ', $parts);
    }
}

if (!function_exists('caAddressHash')) {
    function caAddressHash($address_text) {
        $normalized = strtolower(caNormalizeAddressValue($address_text, 350));
        return sha1($normalized);
    }
}

if (!function_exists('caEnsureUserSavedAddressSchema')) {
    function caEnsureUserSavedAddressSchema($conn) {
        static $ensured = false;
        if ($ensured || !($conn instanceof mysqli)) {
            return;
        }
        $ensured = true;

        $sql = "
            CREATE TABLE IF NOT EXISTS `user_saved_addresses` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `label` varchar(80) NOT NULL DEFAULT 'Saved Address',
                `contact_name` varchar(120) DEFAULT NULL,
                `contact_phone` varchar(30) DEFAULT NULL,
                `street_address` varchar(190) DEFAULT NULL,
                `region_name` varchar(120) DEFAULT NULL,
                `region_code` varchar(30) DEFAULT NULL,
                `province_name` varchar(120) DEFAULT NULL,
                `province_code` varchar(30) DEFAULT NULL,
                `city_name` varchar(120) DEFAULT NULL,
                `city_code` varchar(30) DEFAULT NULL,
                `barangay_name` varchar(120) DEFAULT NULL,
                `barangay_code` varchar(30) DEFAULT NULL,
                `full_address` varchar(350) NOT NULL,
                `address_hash` char(40) NOT NULL,
                `latitude` decimal(10,7) DEFAULT NULL,
                `longitude` decimal(10,7) DEFAULT NULL,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_hash` (`user_id`,`address_hash`),
                KEY `idx_user_default` (`user_id`,`is_default`),
                KEY `idx_user_updated` (`user_id`,`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        mysqli_query($conn, $sql);
    }
}

if (!function_exists('caFetchUserSavedAddresses')) {
    function caFetchUserSavedAddresses($conn, $user_id) {
        $user_id = (int)$user_id;
        if (!($conn instanceof mysqli) || $user_id <= 0) {
            return [];
        }

        $addresses = [];
        $query = "SELECT id, label, contact_name, contact_phone, street_address,
                         region_name, region_code, province_name, province_code,
                         city_name, city_code, barangay_name, barangay_code,
                         full_address, latitude, longitude, is_default, created_at, updated_at
                  FROM user_saved_addresses
                  WHERE user_id = ?
                  ORDER BY is_default DESC, updated_at DESC, id DESC";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $addresses[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $addresses;
    }
}

if (!function_exists('checkoutSavedAddressRowsForClient')) {
    function checkoutSavedAddressRowsForClient($saved_addresses) {
        if (!is_array($saved_addresses)) return [];
        $rows = [];
        foreach ($saved_addresses as $addr) {
            $rows[] = [
                'id' => (int)$addr['id'],
                'label' => (string)($addr['label'] ?? 'Saved Address'),
                'contact_name' => (string)($addr['contact_name'] ?? ''),
                'contact_phone' => (string)($addr['contact_phone'] ?? ''),
                'street_address' => (string)($addr['street_address'] ?? ''),
                'region_name' => (string)($addr['region_name'] ?? ''),
                'region_code' => (string)($addr['region_code'] ?? ''),
                'province_name' => (string)($addr['province_name'] ?? ''),
                'province_code' => (string)($addr['province_code'] ?? ''),
                'city_name' => (string)($addr['city_name'] ?? ''),
                'city_code' => (string)($addr['city_code'] ?? ''),
                'barangay_name' => (string)($addr['barangay_name'] ?? ''),
                'barangay_code' => (string)($addr['barangay_code'] ?? ''),
                'full_address' => (string)($addr['full_address'] ?? ''),
                'latitude' => (string)($addr['latitude'] ?? ''),
                'longitude' => (string)($addr['longitude'] ?? ''),
                'is_default' => (int)($addr['is_default'] ?? 0)
            ];
        }
        return $rows;
    }
}

if (!function_exists('caSaveUserSavedAddress')) {
    function caSaveUserSavedAddress($conn, $user_id, array $payload, $set_default = false) {
        $user_id = (int)$user_id;
        if (!($conn instanceof mysqli) || $user_id <= 0) {
            return ['success' => false, 'message' => 'Invalid user context.'];
        }

        $label = caNormalizeAddressValue($payload['label'] ?? '', 80);
        if ($label === '') {
            $label = 'Saved Address';
        }

        $contact_name = caNormalizeAddressValue($payload['contact_name'] ?? '', 120);
        $contact_phone = caNormalizeAddressValue($payload['contact_phone'] ?? '', 30);
        $street_address = caNormalizeAddressValue($payload['street_address'] ?? '', 190);
        $region_name = caNormalizeAddressValue($payload['region_name'] ?? '', 120);
        $region_code = caNormalizeAddressValue($payload['region_code'] ?? '', 30);
        $province_name = caNormalizeAddressValue($payload['province_name'] ?? '', 120);
        $province_code = caNormalizeAddressValue($payload['province_code'] ?? '', 30);
        $city_name = caNormalizeAddressValue($payload['city_name'] ?? '', 120);
        $city_code = caNormalizeAddressValue($payload['city_code'] ?? '', 30);
        $barangay_name = caNormalizeAddressValue($payload['barangay_name'] ?? '', 120);
        $barangay_code = caNormalizeAddressValue($payload['barangay_code'] ?? '', 30);

        $full_address = caNormalizeAddressValue($payload['full_address'] ?? '', 350);
        if ($full_address === '') {
            $full_address = caBuildAddressFromSegments($street_address, $barangay_name, $city_name, $province_name, $region_name);
        }
        if ($full_address === '') {
            return ['success' => false, 'message' => 'Address is empty.'];
        }

        if ($street_address === '') {
            $street_address = caNormalizeAddressValue(strtok($full_address, ','), 190);
        }

        $latitude = '';
        if (isset($payload['latitude']) && is_numeric((string)$payload['latitude'])) {
            $latitude = number_format((float)$payload['latitude'], 7, '.', '');
        }

        $longitude = '';
        if (isset($payload['longitude']) && is_numeric((string)$payload['longitude'])) {
            $longitude = number_format((float)$payload['longitude'], 7, '.', '');
        }

        $address_hash = caAddressHash($full_address);
        $is_default = $set_default ? 1 : (!empty($payload['is_default']) ? 1 : 0);

        if ($is_default === 1) {
            $reset_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 0 WHERE user_id = ?");
            if ($reset_stmt) {
                mysqli_stmt_bind_param($reset_stmt, "i", $user_id);
                mysqli_stmt_execute($reset_stmt);
                mysqli_stmt_close($reset_stmt);
            }
        }

        $address_id = (int)($payload['address_id'] ?? 0);

        if ($address_id > 0) {
            $update_sql = "UPDATE user_saved_addresses SET
                label = ?,
                contact_name = NULLIF(?, ''),
                contact_phone = NULLIF(?, ''),
                street_address = NULLIF(?, ''),
                region_name = NULLIF(?, ''),
                region_code = NULLIF(?, ''),
                province_name = NULLIF(?, ''),
                province_code = NULLIF(?, ''),
                city_name = NULLIF(?, ''),
                city_code = NULLIF(?, ''),
                barangay_name = NULLIF(?, ''),
                barangay_code = NULLIF(?, ''),
                full_address = ?,
                address_hash = ?,
                latitude = NULLIF(?, ''),
                longitude = NULLIF(?, ''),
                is_default = IF(? = 1, 1, is_default),
                updated_at = NOW()
                WHERE id = ? AND user_id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            if (!$stmt) {
                return ['success' => false, 'message' => 'Failed to prepare address update query.'];
            }
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssssiii",
                $label,
                $contact_name,
                $contact_phone,
                $street_address,
                $region_name,
                $region_code,
                $province_name,
                $province_code,
                $city_name,
                $city_code,
                $barangay_name,
                $barangay_code,
                $full_address,
                $address_hash,
                $latitude,
                $longitude,
                $is_default,
                $address_id,
                $user_id
            );
            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                return ['success' => false, 'message' => 'Failed to update address: ' . $error];
            }
            mysqli_stmt_close($stmt);
            return ['success' => true, 'message' => 'Address updated.', 'address_id' => $address_id];
        }

        $insert_sql = "INSERT INTO user_saved_addresses
            (user_id, label, contact_name, contact_phone, street_address,
             region_name, region_code, province_name, province_code,
             city_name, city_code, barangay_name, barangay_code,
             full_address, address_hash, latitude, longitude, is_default, created_at, updated_at)
            VALUES
            (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
             NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
             NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
             ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                contact_name = VALUES(contact_name),
                contact_phone = VALUES(contact_phone),
                street_address = VALUES(street_address),
                region_name = VALUES(region_name),
                region_code = VALUES(region_code),
                province_name = VALUES(province_name),
                province_code = VALUES(province_code),
                city_name = VALUES(city_name),
                city_code = VALUES(city_code),
                barangay_name = VALUES(barangay_name),
                barangay_code = VALUES(barangay_code),
                full_address = VALUES(full_address),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                is_default = IF(VALUES(is_default) = 1, 1, is_default),
                updated_at = NOW()";
        $stmt = mysqli_prepare($conn, $insert_sql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare address save query.'];
        }

        mysqli_stmt_bind_param(
            $stmt,
            "isssssssssssssssii",
            $user_id,
            $label,
            $contact_name,
            $contact_phone,
            $street_address,
            $region_name,
            $region_code,
            $province_name,
            $province_code,
            $city_name,
            $city_code,
            $barangay_name,
            $barangay_code,
            $full_address,
            $address_hash,
            $latitude,
            $longitude,
            $is_default
        );

        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => 'Failed to save address: ' . $error];
        }
        mysqli_stmt_close($stmt);

        $id = 0;
        $lookup_stmt = mysqli_prepare($conn, "SELECT id FROM user_saved_addresses WHERE user_id = ? AND address_hash = ? LIMIT 1");
        if ($lookup_stmt) {
            mysqli_stmt_bind_param($lookup_stmt, "is", $user_id, $address_hash);
            mysqli_stmt_execute($lookup_stmt);
            $lookup_result = mysqli_stmt_get_result($lookup_stmt);
            $lookup_row = $lookup_result ? mysqli_fetch_assoc($lookup_result) : null;
            if ($lookup_result) {
                mysqli_free_result($lookup_result);
            }
            mysqli_stmt_close($lookup_stmt);
            $id = (int)($lookup_row['id'] ?? 0);
        }

        return ['success' => true, 'message' => 'Address saved.', 'address_id' => $id];
    }
}

if (!function_exists('caEnsureDefaultUserProfileAddress')) {
    function caEnsureDefaultUserProfileAddress($conn, $user_id, $profile_address, $contact_name = '', $contact_phone = '') {
        $profile_address = caNormalizeAddressValue($profile_address, 350);
        if ($profile_address === '' || !($conn instanceof mysqli) || (int)$user_id <= 0) {
            return;
        }

        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total_count FROM user_saved_addresses WHERE user_id = ?");
        if (!$count_stmt) {
            return;
        }

        mysqli_stmt_bind_param($count_stmt, "i", $user_id);
        mysqli_stmt_execute($count_stmt);
        $result = mysqli_stmt_get_result($count_stmt);
        $row = $result ? mysqli_fetch_assoc($result) : ['total_count' => 0];
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($count_stmt);

        if ((int)($row['total_count'] ?? 0) > 0) {
            return;
        }

        caSaveUserSavedAddress(
            $conn,
            $user_id,
            [
                'label' => 'Account Address',
                'contact_name' => $contact_name,
                'contact_phone' => $contact_phone,
                'full_address' => $profile_address
            ],
            true
        );
    }
}

if (!function_exists('caDeleteUserSavedAddress')) {
    function caDeleteUserSavedAddress($conn, $user_id, $address_id) {
        $user_id = (int)$user_id;
        $address_id = (int)$address_id;
        if (!($conn instanceof mysqli) || $user_id <= 0 || $address_id <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters.'];
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM user_saved_addresses WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare delete query.'];
        }
        mysqli_stmt_bind_param($stmt, "ii", $address_id, $user_id);
        $executed = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($executed) {
            return ['success' => true, 'message' => 'Address removed successfully.'];
        }
        return ['success' => false, 'message' => 'Failed to remove address.'];
    }
}
