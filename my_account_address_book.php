<?php
$is_embedded = (defined('MY_ACCOUNT_ADDRESS_BOOK_EMBED') && MY_ACCOUNT_ADDRESS_BOOK_EMBED === true)
    || (isset($_GET['embedded']) && $_GET['embedded'] === '1');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!$is_embedded) {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_to'] = 'my_account.php#addresses';
        header('Location: login.php');
        exit;
    }
    header('Location: my_account.php#addresses');
    exit;
}

$address_book_redirect_url = 'my_account_address_book.php?embedded=1';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_to'] = 'my_account.php#addresses';
    header('Location: login.php');
    exit;
}

require_once 'includes/config.php';
require_once 'includes/checkout_address_helper.php';
$google_maps_api_key = function_exists('getGoogleMapsApiKey')
    ? getGoogleMapsApiKey()
    : trim((string)(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : (getenv('GOOGLE_MAPS_API_KEY') ?: '')));
$google_geocoding_enabled = function_exists('shouldUseGoogleGeocoding') ? shouldUseGoogleGeocoding() : true;

$user_id = (int)($_SESSION['user_id'] ?? 0);
$flash_success = $_SESSION['address_book_success'] ?? '';
$flash_error = $_SESSION['address_book_error'] ?? '';
unset($_SESSION['address_book_success'], $_SESSION['address_book_error']);

caEnsureUserSavedAddressSchema($conn);

$user_stmt = mysqli_prepare($conn, "SELECT full_name, email, phone, address FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($user_stmt, "i", $user_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);
$user = $user_result ? (mysqli_fetch_assoc($user_result) ?: []) : [];
mysqli_stmt_close($user_stmt);

caEnsureDefaultUserProfileAddress(
    $conn,
    $user_id,
    (string)($user['address'] ?? ''),
    (string)($user['full_name'] ?? ''),
    (string)($user['phone'] ?? '')
);

if (!isset($_SESSION['address_book_csrf']) || !is_string($_SESSION['address_book_csrf']) || $_SESSION['address_book_csrf'] === '') {
    $_SESSION['address_book_csrf'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['address_book_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requested_action = strtolower(trim((string)($_POST['action'] ?? '')));
    $allowed_actions = ['add_address', 'rename_address', 'update_address_details', 'set_default', 'delete_address'];
    if ($requested_action === '' || !in_array($requested_action, $allowed_actions, true)) {
        // Ignore non-address-book forms when embedded in my_account.php.
    } else {
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals((string)$_SESSION['address_book_csrf'], $posted_token)) {
        $_SESSION['address_book_error'] = 'Invalid form token. Please refresh and try again.';
        header('Location: ' . $address_book_redirect_url);
        exit;
    }

    $action = $requested_action;

    if ($action === 'add_address') {
        $existing = caFetchUserSavedAddresses($conn, $user_id);
        $set_default = !empty($_POST['is_default']) || empty($existing);
        $payload = [
            'label' => $_POST['label'] ?? 'Saved Address',
            'contact_name' => $_POST['contact_name'] ?? ($user['full_name'] ?? ''),
            'contact_phone' => $_POST['contact_phone'] ?? ($user['phone'] ?? ''),
            'street_address' => $_POST['street_address'] ?? '',
            'region_name' => $_POST['region_name'] ?? '',
            'region_code' => $_POST['region_code'] ?? '',
            'province_name' => $_POST['province_name'] ?? '',
            'province_code' => $_POST['province_code'] ?? '',
            'city_name' => $_POST['city_name'] ?? '',
            'city_code' => $_POST['city_code'] ?? '',
            'barangay_name' => $_POST['barangay_name'] ?? '',
            'barangay_code' => $_POST['barangay_code'] ?? '',
            'full_address' => $_POST['full_address'] ?? '',
            'latitude' => $_POST['latitude'] ?? '',
            'longitude' => $_POST['longitude'] ?? '',
            'is_default' => $set_default ? 1 : 0
        ];

        $save_result = caSaveUserSavedAddress($conn, $user_id, $payload, $set_default);
        if (!($save_result['success'] ?? false)) {
            $_SESSION['address_book_error'] = (string)($save_result['message'] ?? 'Unable to save address.');
        } else {
            if ($set_default) {
                $default_address_text = trim((string)($payload['full_address'] ?? ''));
                if ($default_address_text !== '') {
                    $profile_stmt = mysqli_prepare($conn, "UPDATE users SET address = ?, updated_at = NOW() WHERE id = ?");
                    if ($profile_stmt) {
                        mysqli_stmt_bind_param($profile_stmt, "si", $default_address_text, $user_id);
                        mysqli_stmt_execute($profile_stmt);
                        mysqli_stmt_close($profile_stmt);
                        $_SESSION['address'] = $default_address_text;
                    }
                }
            }
            $_SESSION['address_book_success'] = 'Address saved to your Address Book.';
        }
    } elseif ($action === 'rename_address') {
        $address_id = (int)($_POST['address_id'] ?? 0);
        $new_label = caNormalizeAddressValue($_POST['new_label'] ?? '', 80);
        if ($address_id <= 0 || $new_label === '') {
            $_SESSION['address_book_error'] = 'Please provide a valid address label.';
        } else {
            $rename_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET label = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            if (!$rename_stmt) {
                $_SESSION['address_book_error'] = 'Could not update address label.';
            } else {
                mysqli_stmt_bind_param($rename_stmt, "sii", $new_label, $address_id, $user_id);
                mysqli_stmt_execute($rename_stmt);
                $affected = mysqli_stmt_affected_rows($rename_stmt);
                mysqli_stmt_close($rename_stmt);
                $_SESSION[$affected > 0 ? 'address_book_success' : 'address_book_error'] = $affected > 0
                    ? 'Address label updated.'
                    : 'Address not found or no changes detected.';
            }
        }
    } elseif ($action === 'update_address_details') {
        $address_id = (int)($_POST['address_id'] ?? 0);
        $label = caNormalizeAddressValue($_POST['label'] ?? '', 80);
        $contact_name = caNormalizeAddressValue($_POST['contact_name'] ?? '', 120);
        $contact_phone = caNormalizeAddressValue($_POST['contact_phone'] ?? '', 30);
        $street_address = caNormalizeAddressValue($_POST['street_address'] ?? '', 190);
        $region_name = caNormalizeAddressValue($_POST['region_name'] ?? '', 120);
        $region_code = caNormalizeAddressValue($_POST['region_code'] ?? '', 30);
        $province_name = caNormalizeAddressValue($_POST['province_name'] ?? '', 120);
        $province_code = caNormalizeAddressValue($_POST['province_code'] ?? '', 30);
        $city_name = caNormalizeAddressValue($_POST['city_name'] ?? '', 120);
        $city_code = caNormalizeAddressValue($_POST['city_code'] ?? '', 30);
        $barangay_name = caNormalizeAddressValue($_POST['barangay_name'] ?? '', 120);
        $barangay_code = caNormalizeAddressValue($_POST['barangay_code'] ?? '', 30);
        $full_address = caNormalizeAddressValue($_POST['full_address'] ?? '', 350);
        $set_default = !empty($_POST['is_default']);

        if ($address_id <= 0) {
            $_SESSION['address_book_error'] = 'Invalid address selection.';
        } elseif ($label === '') {
            $_SESSION['address_book_error'] = 'Address label is required.';
        } else {
            if ($full_address === '') {
                $full_address = caBuildAddressFromSegments($street_address, $barangay_name, $city_name, $province_name, $region_name);
            }
            if ($full_address === '') {
                $_SESSION['address_book_error'] = 'Full address is required.';
                header('Location: ' . $address_book_redirect_url);
                exit;
            }

            $latitude = '';
            if (isset($_POST['latitude']) && is_numeric((string)$_POST['latitude'])) {
                $latitude = number_format((float)$_POST['latitude'], 7, '.', '');
            }

            $longitude = '';
            if (isset($_POST['longitude']) && is_numeric((string)$_POST['longitude'])) {
                $longitude = number_format((float)$_POST['longitude'], 7, '.', '');
            }

            $address_hash = caAddressHash($full_address);

            mysqli_begin_transaction($conn);
            try {
                $existing_stmt = mysqli_prepare($conn, "SELECT is_default FROM user_saved_addresses WHERE id = ? AND user_id = ? LIMIT 1");
                if (!$existing_stmt) {
                    throw new RuntimeException('Unable to read address state.');
                }
                mysqli_stmt_bind_param($existing_stmt, "ii", $address_id, $user_id);
                mysqli_stmt_execute($existing_stmt);
                $existing_result = mysqli_stmt_get_result($existing_stmt);
                $existing_row = $existing_result ? mysqli_fetch_assoc($existing_result) : null;
                mysqli_stmt_close($existing_stmt);

                if (!$existing_row) {
                    throw new RuntimeException('Address not found.');
                }
                $was_default = (int)($existing_row['is_default'] ?? 0) === 1;

                if ($set_default) {
                    $reset_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 0 WHERE user_id = ?");
                    if ($reset_stmt) {
                        mysqli_stmt_bind_param($reset_stmt, "i", $user_id);
                        mysqli_stmt_execute($reset_stmt);
                        mysqli_stmt_close($reset_stmt);
                    }
                }

                $update_sql = "UPDATE user_saved_addresses
                               SET label = ?,
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
                                   is_default = IF(?, 1, is_default),
                                   updated_at = NOW()
                               WHERE id = ? AND user_id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);
                if (!$update_stmt) {
                    throw new RuntimeException('Could not prepare address update query.');
                }
                $set_default_int = $set_default ? 1 : 0;
                mysqli_stmt_bind_param(
                    $update_stmt,
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
                    $set_default_int,
                    $address_id,
                    $user_id
                );
                if (!mysqli_stmt_execute($update_stmt)) {
                    if (mysqli_errno($conn) === 1062) {
                        mysqli_stmt_close($update_stmt);
                        throw new RuntimeException('This address is already saved in your Address Book.');
                    }
                    $stmt_error = mysqli_stmt_error($update_stmt);
                    mysqli_stmt_close($update_stmt);
                    throw new RuntimeException('Unable to update address: ' . $stmt_error);
                }
                mysqli_stmt_close($update_stmt);

                if ($set_default || $was_default) {
                    $profile_stmt = mysqli_prepare($conn, "UPDATE users SET address = ?, updated_at = NOW() WHERE id = ?");
                    if ($profile_stmt) {
                        mysqli_stmt_bind_param($profile_stmt, "si", $full_address, $user_id);
                        mysqli_stmt_execute($profile_stmt);
                        mysqli_stmt_close($profile_stmt);
                        $_SESSION['address'] = $full_address;
                    }
                }

                mysqli_commit($conn);
                $_SESSION['address_book_success'] = 'Address details updated.';
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['address_book_error'] = $e->getMessage() ?: 'Unable to update address details.';
            }
        }
    } elseif ($action === 'set_default') {
        $address_id = (int)($_POST['address_id'] ?? 0);
        if ($address_id <= 0) {
            $_SESSION['address_book_error'] = 'Invalid address selection.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $reset_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 0 WHERE user_id = ?");
                mysqli_stmt_bind_param($reset_stmt, "i", $user_id);
                mysqli_stmt_execute($reset_stmt);
                mysqli_stmt_close($reset_stmt);

                $set_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
                mysqli_stmt_bind_param($set_stmt, "ii", $address_id, $user_id);
                mysqli_stmt_execute($set_stmt);
                $affected = mysqli_stmt_affected_rows($set_stmt);
                mysqli_stmt_close($set_stmt);

                if ($affected <= 0) {
                    throw new RuntimeException('Address not found.');
                }

                $lookup_stmt = mysqli_prepare($conn, "SELECT full_address FROM user_saved_addresses WHERE id = ? AND user_id = ? LIMIT 1");
                mysqli_stmt_bind_param($lookup_stmt, "ii", $address_id, $user_id);
                mysqli_stmt_execute($lookup_stmt);
                $lookup_result = mysqli_stmt_get_result($lookup_stmt);
                $lookup_row = $lookup_result ? mysqli_fetch_assoc($lookup_result) : null;
                mysqli_stmt_close($lookup_stmt);
                $default_full_address = trim((string)($lookup_row['full_address'] ?? ''));

                if ($default_full_address !== '') {
                    $profile_stmt = mysqli_prepare($conn, "UPDATE users SET address = ?, updated_at = NOW() WHERE id = ?");
                    if ($profile_stmt) {
                        mysqli_stmt_bind_param($profile_stmt, "si", $default_full_address, $user_id);
                        mysqli_stmt_execute($profile_stmt);
                        mysqli_stmt_close($profile_stmt);
                        $_SESSION['address'] = $default_full_address;
                    }
                }

                mysqli_commit($conn);
                $_SESSION['address_book_success'] = 'Default address updated.';
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['address_book_error'] = 'Failed to set default address.';
            }
        }
    } elseif ($action === 'delete_address') {
        $address_id = (int)($_POST['address_id'] ?? 0);
        if ($address_id <= 0) {
            $_SESSION['address_book_error'] = 'Invalid address selection.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $check_stmt = mysqli_prepare($conn, "SELECT is_default FROM user_saved_addresses WHERE id = ? AND user_id = ? LIMIT 1");
                mysqli_stmt_bind_param($check_stmt, "ii", $address_id, $user_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $check_row = $check_result ? mysqli_fetch_assoc($check_result) : null;
                mysqli_stmt_close($check_stmt);

                if (!$check_row) {
                    throw new RuntimeException('Address not found.');
                }
                $was_default = (int)($check_row['is_default'] ?? 0) === 1;

                $delete_stmt = mysqli_prepare($conn, "DELETE FROM user_saved_addresses WHERE id = ? AND user_id = ?");
                mysqli_stmt_bind_param($delete_stmt, "ii", $address_id, $user_id);
                mysqli_stmt_execute($delete_stmt);
                $deleted_rows = mysqli_stmt_affected_rows($delete_stmt);
                mysqli_stmt_close($delete_stmt);

                if ($deleted_rows <= 0) {
                    throw new RuntimeException('Address not deleted.');
                }

                $remaining_stmt = mysqli_prepare($conn, "SELECT id, full_address, is_default FROM user_saved_addresses WHERE user_id = ? ORDER BY updated_at DESC, id DESC");
                mysqli_stmt_bind_param($remaining_stmt, "i", $user_id);
                mysqli_stmt_execute($remaining_stmt);
                $remaining_result = mysqli_stmt_get_result($remaining_stmt);
                $remaining_rows = [];
                if ($remaining_result) {
                    while ($remaining = mysqli_fetch_assoc($remaining_result)) {
                        $remaining_rows[] = $remaining;
                    }
                }
                mysqli_stmt_close($remaining_stmt);

                if (!empty($remaining_rows)) {
                    $has_default = false;
                    foreach ($remaining_rows as $remaining_row) {
                        if ((int)($remaining_row['is_default'] ?? 0) === 1) {
                            $has_default = true;
                            break;
                        }
                    }

                    if ($was_default || !$has_default) {
                        $new_default_id = (int)($remaining_rows[0]['id'] ?? 0);
                        if ($new_default_id > 0) {
                            $reset_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 0 WHERE user_id = ?");
                            mysqli_stmt_bind_param($reset_stmt, "i", $user_id);
                            mysqli_stmt_execute($reset_stmt);
                            mysqli_stmt_close($reset_stmt);

                            $set_stmt = mysqli_prepare($conn, "UPDATE user_saved_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
                            mysqli_stmt_bind_param($set_stmt, "ii", $new_default_id, $user_id);
                            mysqli_stmt_execute($set_stmt);
                            mysqli_stmt_close($set_stmt);

                            $default_full_address = trim((string)($remaining_rows[0]['full_address'] ?? ''));
                            if ($default_full_address !== '') {
                                $profile_stmt = mysqli_prepare($conn, "UPDATE users SET address = ?, updated_at = NOW() WHERE id = ?");
                                if ($profile_stmt) {
                                    mysqli_stmt_bind_param($profile_stmt, "si", $default_full_address, $user_id);
                                    mysqli_stmt_execute($profile_stmt);
                                    mysqli_stmt_close($profile_stmt);
                                    $_SESSION['address'] = $default_full_address;
                                }
                            }
                        }
                    }
                } else {
                    $profile_stmt = mysqli_prepare($conn, "UPDATE users SET address = '', updated_at = NOW() WHERE id = ?");
                    if ($profile_stmt) {
                        mysqli_stmt_bind_param($profile_stmt, "i", $user_id);
                        mysqli_stmt_execute($profile_stmt);
                        mysqli_stmt_close($profile_stmt);
                        $_SESSION['address'] = '';
                    }
                }

                mysqli_commit($conn);
                $_SESSION['address_book_success'] = 'Address removed.';
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['address_book_error'] = 'Unable to delete address.';
            }
        }
    } else {
        $_SESSION['address_book_error'] = 'Unknown action requested.';
    }

    header('Location: ' . $address_book_redirect_url);
    exit;
    }
}

$addresses = caFetchUserSavedAddresses($conn, $user_id);
$address_count = count($addresses);
$default_address_id = 0;
foreach ($addresses as $address_row) {
    if ((int)($address_row['is_default'] ?? 0) === 1) {
        $default_address_id = (int)$address_row['id'];
        break;
    }
}
$address_book_sync_payload = [];
foreach ($addresses as $address_row) {
    $sync_full_address = trim((string)($address_row['full_address'] ?? ''));
    if ($sync_full_address === '') {
        continue;
    }
    $sync_label = trim((string)($address_row['label'] ?? 'Saved Address'));
    if ($sync_label === '') {
        $sync_label = 'Saved Address';
    }
    $address_book_sync_payload[] = [
        'id' => (int)($address_row['id'] ?? 0),
        'label' => $sync_label,
        'full_address' => $sync_full_address,
        'is_default' => (int)($address_row['is_default'] ?? 0) === 1
    ];
}

$page_title = 'My Address Book | Lechon Delights';
if (!$is_embedded) {
    include 'includes/header.php';
}
?>

<!-- Leaflet Map API -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="<?php echo $is_embedded ? 'address-embed-wrap' : 'account-page'; ?>">
    <div class="<?php echo $is_embedded ? 'address-embed-container' : 'container'; ?>">
        <div class="address-book-head">
            <div>
                <?php if ($is_embedded): ?>
                    <h2>Address Book</h2>
                <?php else: ?>
                    <h1>Address Book</h1>
                <?php endif; ?>
                <p class="account-subtitle">Manage saved delivery addresses for faster checkout.</p>
            </div>
            <?php if (!$is_embedded): ?>
                <div class="address-book-actions">
                    <a href="my_account.php#addresses" class="btn-outline"><i class="fas fa-arrow-left"></i> Back to My Account</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($flash_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars((string)$flash_success); ?>
            </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars((string)$flash_error); ?>
            </div>
        <?php endif; ?>

        <div class="address-grid">
            <section class="address-card">
                <h2><i class="fas fa-plus-circle"></i> Add New Address</h2>
                <form method="POST" action="<?php echo htmlspecialchars($address_book_redirect_url); ?>" class="address-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="add_address">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_label">Label</label>
                            <input type="text" id="add_label" name="label" maxlength="80" placeholder="Home, Office, Condo" required>
                        </div>
                        <div class="form-group">
                            <label for="add_contact_name">Contact Name</label>
                            <input type="text" id="add_contact_name" name="contact_name" maxlength="120" value="<?php echo htmlspecialchars((string)($user['full_name'] ?? '')); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_contact_phone">Contact Phone</label>
                            <input type="text" id="add_contact_phone" name="contact_phone" maxlength="30" value="<?php echo htmlspecialchars((string)($user['phone'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="add_street_address">Street Address</label>
                            <input type="text" id="add_street_address" name="street_address" maxlength="190" placeholder="House no., building, street">
                        </div>
                    </div>

                    <div class="form-row psgc-row">
                        <div class="form-group">
                            <label for="add_region_code">Region (PSGC)</label>
                            <select id="add_region_code" name="region_code" class="psgc-region" data-prefix="add" data-selected="">
                                <option value="">Select region</option>
                            </select>
                            <input type="hidden" id="add_region_name" name="region_name" value="">
                        </div>
                        <div class="form-group">
                            <label for="add_province_code">Province</label>
                            <select id="add_province_code" name="province_code" class="psgc-province" data-prefix="add" data-selected="" disabled>
                                <option value="">Select province</option>
                            </select>
                            <input type="hidden" id="add_province_name" name="province_name" value="">
                        </div>
                    </div>

                    <div class="form-row psgc-row">
                        <div class="form-group">
                            <label for="add_city_code">City / Municipality</label>
                            <select id="add_city_code" name="city_code" class="psgc-city" data-prefix="add" data-selected="" disabled>
                                <option value="">Select city or municipality</option>
                            </select>
                            <input type="hidden" id="add_city_name" name="city_name" value="">
                        </div>
                        <div class="form-group">
                            <label for="add_barangay_code">Barangay</label>
                            <select id="add_barangay_code" name="barangay_code" class="psgc-barangay" data-prefix="add" data-selected="" disabled>
                                <option value="">Select barangay</option>
                            </select>
                            <input type="hidden" id="add_barangay_name" name="barangay_name" value="">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add_full_address">Full Address</label>
                        <textarea id="add_full_address" name="full_address" rows="3" maxlength="350" placeholder="Complete address including barangay, city, province, and region" required></textarea>
                        <small class="psgc-help">Select PSGC fields for a structured address. You can still refine with the map picker.</small>
                        <div class="map-inline-actions">
                            <button type="button" class="btn-outline btn-small open-map-picker" data-target-prefix="add">
                                <i class="fas fa-map-marker-alt"></i> Pick from Map
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="add_latitude" name="latitude" value="">
                    <input type="hidden" id="add_longitude" name="longitude" value="">

                    <label class="checkbox-row">
                        <input type="checkbox" name="is_default" value="1">
                        <span>Set as default address</span>
                    </label>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Save Address
                    </button>
                </form>
            </section>

            <section class="address-card">
                <h2><i class="fas fa-address-book"></i> Saved Addresses (<?php echo (int)$address_count; ?>)</h2>

                <?php if ($address_count === 0): ?>
                    <div class="empty-addresses">
                        <i class="fas fa-map-marker-alt"></i>
                        <p>No saved addresses yet. Add your first address on the left.</p>
                    </div>
                <?php else: ?>
                    <div class="address-list">
                        <?php foreach ($addresses as $address): ?>
                            <?php
                            $is_default = (int)($address['is_default'] ?? 0) === 1;
                            $address_id = (int)($address['id'] ?? 0);
                            ?>
                            <article class="address-item <?php echo $is_default ? 'is-default' : ''; ?>">
                                <div class="address-item-head">
                                    <h3><?php echo htmlspecialchars((string)($address['label'] ?? 'Saved Address')); ?></h3>
                                    <?php if ($is_default): ?>
                                        <span class="default-badge"><i class="fas fa-star"></i> Default</span>
                                    <?php endif; ?>
                                </div>
                                <p class="address-line"><?php echo htmlspecialchars((string)($address['full_address'] ?? '')); ?></p>
                                <?php if (!empty($address['contact_name']) || !empty($address['contact_phone'])): ?>
                                    <p class="address-contact">
                                        <?php if (!empty($address['contact_name'])): ?>
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars((string)$address['contact_name']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($address['contact_phone'])): ?>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars((string)$address['contact_phone']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <div class="address-actions">
                                    <?php $edit_prefix = 'edit_' . $address_id; ?>
                                    <form method="POST" action="<?php echo htmlspecialchars($address_book_redirect_url); ?>" class="inline-form edit-details-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="update_address_details">
                                        <input type="hidden" name="address_id" value="<?php echo $address_id; ?>">
                                        <div class="form-row compact-row">
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_label">Label</label>
                                                <input type="text" id="<?php echo $edit_prefix; ?>_label" name="label" maxlength="80" value="<?php echo htmlspecialchars((string)($address['label'] ?? '')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_contact_name">Contact Name</label>
                                                <input type="text" id="<?php echo $edit_prefix; ?>_contact_name" name="contact_name" maxlength="120" value="<?php echo htmlspecialchars((string)($address['contact_name'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="form-row compact-row">
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_contact_phone">Contact Phone</label>
                                                <input type="text" id="<?php echo $edit_prefix; ?>_contact_phone" name="contact_phone" maxlength="30" value="<?php echo htmlspecialchars((string)($address['contact_phone'] ?? '')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_street_address">Street Address</label>
                                                <input type="text" id="<?php echo $edit_prefix; ?>_street_address" name="street_address" maxlength="190" value="<?php echo htmlspecialchars((string)($address['street_address'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="form-row compact-row psgc-row">
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_region_code">Region (PSGC)</label>
                                                <select id="<?php echo $edit_prefix; ?>_region_code" name="region_code" class="psgc-region" data-prefix="<?php echo $edit_prefix; ?>" data-selected="<?php echo htmlspecialchars((string)($address['region_code'] ?? '')); ?>">
                                                    <option value="">Select region</option>
                                                </select>
                                                <input type="hidden" id="<?php echo $edit_prefix; ?>_region_name" name="region_name" value="<?php echo htmlspecialchars((string)($address['region_name'] ?? '')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_province_code">Province</label>
                                                <select id="<?php echo $edit_prefix; ?>_province_code" name="province_code" class="psgc-province" data-prefix="<?php echo $edit_prefix; ?>" data-selected="<?php echo htmlspecialchars((string)($address['province_code'] ?? '')); ?>" disabled>
                                                    <option value="">Select province</option>
                                                </select>
                                                <input type="hidden" id="<?php echo $edit_prefix; ?>_province_name" name="province_name" value="<?php echo htmlspecialchars((string)($address['province_name'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="form-row compact-row psgc-row">
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_city_code">City / Municipality</label>
                                                <select id="<?php echo $edit_prefix; ?>_city_code" name="city_code" class="psgc-city" data-prefix="<?php echo $edit_prefix; ?>" data-selected="<?php echo htmlspecialchars((string)($address['city_code'] ?? '')); ?>" disabled>
                                                    <option value="">Select city or municipality</option>
                                                </select>
                                                <input type="hidden" id="<?php echo $edit_prefix; ?>_city_name" name="city_name" value="<?php echo htmlspecialchars((string)($address['city_name'] ?? '')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="<?php echo $edit_prefix; ?>_barangay_code">Barangay</label>
                                                <select id="<?php echo $edit_prefix; ?>_barangay_code" name="barangay_code" class="psgc-barangay" data-prefix="<?php echo $edit_prefix; ?>" data-selected="<?php echo htmlspecialchars((string)($address['barangay_code'] ?? '')); ?>" disabled>
                                                    <option value="">Select barangay</option>
                                                </select>
                                                <input type="hidden" id="<?php echo $edit_prefix; ?>_barangay_name" name="barangay_name" value="<?php echo htmlspecialchars((string)($address['barangay_name'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="<?php echo $edit_prefix; ?>_full_address">Full Address</label>
                                            <textarea id="<?php echo $edit_prefix; ?>_full_address" name="full_address" rows="3" maxlength="350" required><?php echo htmlspecialchars((string)($address['full_address'] ?? '')); ?></textarea>
                                            <small class="psgc-help">Keep PSGC details updated so checkout can detect your exact location.</small>
                                            <div class="map-inline-actions">
                                                <button type="button" class="btn-outline btn-small open-map-picker" data-target-prefix="<?php echo $edit_prefix; ?>">
                                                    <i class="fas fa-map-marker-alt"></i> Pick from Map
                                                </button>
                                            </div>
                                        </div>

                                        <input type="hidden" id="<?php echo $edit_prefix; ?>_latitude" name="latitude" value="<?php echo htmlspecialchars((string)($address['latitude'] ?? '')); ?>">
                                        <input type="hidden" id="<?php echo $edit_prefix; ?>_longitude" name="longitude" value="<?php echo htmlspecialchars((string)($address['longitude'] ?? '')); ?>">

                                        <label class="checkbox-row compact-check">
                                            <input type="checkbox" name="is_default" value="1" <?php echo $is_default ? 'checked' : ''; ?>>
                                            <span>Default address</span>
                                        </label>

                                        <button type="submit" class="btn-outline btn-small">Save Changes</button>
                                    </form>

                                    <div class="action-row">
                                        <?php if (!$is_default): ?>
                                            <form method="POST" action="<?php echo htmlspecialchars($address_book_redirect_url); ?>" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="set_default">
                                                <input type="hidden" name="address_id" value="<?php echo $address_id; ?>">
                                                <button type="submit" class="btn-outline btn-small">Set Default</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" action="<?php echo htmlspecialchars($address_book_redirect_url); ?>" class="inline-form delete-address-form" data-label="<?php echo htmlspecialchars((string)($address['label'] ?? 'Saved Address')); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="delete_address">
                                            <input type="hidden" name="address_id" value="<?php echo $address_id; ?>">
                                            <button type="submit" class="btn-danger btn-small">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<div class="address-map-overlay" id="addressMapOverlay" aria-hidden="true">
    <div class="address-map-modal" role="dialog" aria-modal="true" aria-labelledby="addressMapTitle">
        <div class="address-map-head">
            <div>
                <h3 id="addressMapTitle"><i class="fas fa-map-marked-alt"></i> Pick Address on Map</h3>
                <p>Search, drag marker, or use your current location.</p>
            </div>
            <button type="button" class="address-map-close" id="addressMapClose" aria-label="Close map picker">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="address-map-body">
            <div class="address-map-search-row">
                <input type="text" id="addressMapSearch" placeholder="Search address or place">
                <button type="button" id="addressMapSearchBtn" class="btn-outline btn-small">
                    <i class="fas fa-search"></i> Search
                </button>
                <button type="button" id="addressMapCurrentBtn" class="btn-outline btn-small">
                    <i class="fas fa-crosshairs"></i> Use Current
                </button>
            </div>
            <div id="addressMapCanvas"></div>
        </div>
        <div class="address-map-foot">
            <button type="button" class="btn-outline" id="addressMapCancelBtn">Cancel</button>
            <button type="button" class="btn-primary" id="addressMapApplyBtn">
                <i class="fas fa-check"></i> Use This Address
            </button>
        </div>
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --acc-red: #b3261e;
    --acc-orange: #ef6b2e;
    --acc-ink: #2f1f18;
    --acc-muted: #6b7280;
    --acc-border: #e8ddd2;
    --acc-shadow: 0 16px 36px rgba(37, 20, 12, 0.12);
}

.address-embed-wrap {
    padding: 4px;
    background: transparent;
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
}

.address-embed-container {
    width: 100%;
}

.account-page {
    padding: 86px 0 56px;
    min-height: 100vh;
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
    background:
        radial-gradient(circle at 95% -5%, rgba(239, 107, 46, 0.2), transparent 38%),
        radial-gradient(circle at 0% 20%, rgba(179, 38, 30, 0.11), transparent 35%),
        linear-gradient(180deg, #fffaf4 0%, #fff5ea 44%, #fffdf8 100%);
}

.address-book-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 20px;
}

.address-book-head h1,
.address-book-head h2 {
    margin: 0;
    color: var(--acc-ink);
}

.account-subtitle {
    margin: 6px 0 0;
    color: var(--acc-muted);
}

.address-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 18px;
}

.address-card {
    background: #fff;
    border: 1px solid var(--acc-border);
    border-radius: 16px;
    box-shadow: var(--acc-shadow);
    padding: 24px;
}

.address-card h2 {
    margin: 0 0 16px;
    color: var(--acc-ink);
    font-size: 1.25rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.form-group {
    margin-bottom: 14px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    color: #3c2d25;
    font-weight: 600;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    border: 1px solid #e1d5c8;
    border-radius: 10px;
    background: #fffefb;
    padding: 11px 12px;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #d56f36;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
    background: #fff;
}

.psgc-help {
    display: block;
    margin-top: 6px;
    color: #7c6658;
    font-size: 0.82rem;
}

.checkbox-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 2px 0 14px;
    color: #3c2d25;
    font-weight: 500;
}

.address-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.address-item {
    border: 1px solid #eadbcf;
    border-left: 4px solid #d55f29;
    border-radius: 12px;
    background: #fffaf3;
    padding: 14px;
}

.address-item.is-default {
    border-left-color: var(--acc-red);
    background: #fff5ec;
}

.address-item-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
}

.address-item-head h3 {
    margin: 0;
    color: var(--acc-ink);
    font-size: 1rem;
}

.default-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.76rem;
    padding: 4px 9px;
    border-radius: 999px;
    background: var(--acc-red);
    color: #fff;
    font-weight: 700;
}

.address-line {
    margin: 0 0 8px;
    color: #5f4a3f;
    line-height: 1.45;
}

.address-contact {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 16px;
    margin: 0 0 10px;
    color: #7c6658;
    font-size: 0.9rem;
}

.address-contact i {
    margin-right: 6px;
}

.address-actions {
    display: grid;
    gap: 8px;
}

.inline-form {
    margin: 0;
}

.edit-details-form {
    border: 1px solid #e6d8cb;
    border-radius: 10px;
    background: #ffffff;
    padding: 12px;
}

.compact-row {
    grid-template-columns: 1fr 1fr;
}

.compact-check {
    margin: 0 0 10px;
    font-size: 0.9rem;
}

.map-inline-actions {
    margin-top: 8px;
}

.action-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn-small {
    padding: 8px 10px;
    font-size: 0.86rem;
}

.btn-danger {
    border: 1px solid #dd4e3a;
    background: #ffe6e1;
    color: #9f2517;
    border-radius: 10px;
    font-weight: 700;
}

.btn-danger:hover {
    background: #ffd1c8;
}

.btn-primary,
.btn-outline {
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
}

.btn-primary {
    border: 1px solid #b3261e;
    background: linear-gradient(125deg, #b3261e, #ef6b2e);
    color: #fff;
    padding: 11px 14px;
}

.btn-primary:hover {
    filter: brightness(1.03);
}

.btn-outline {
    border: 1px solid #d9c7b5;
    color: #5c463a;
    background: #fff8f2;
    padding: 9px 12px;
}

.btn-outline:hover {
    background: #fff0e3;
}

.alert {
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 9px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border-left: 4px solid #22c55e;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.empty-addresses {
    border: 1px dashed #d9c6b4;
    border-radius: 12px;
    padding: 22px;
    text-align: center;
    color: #7c6658;
}

.empty-addresses i {
    font-size: 2rem;
    margin-bottom: 8px;
    color: #bd9f89;
}

.address-map-overlay {
    position: fixed;
    inset: 0;
    background: rgba(22, 16, 12, 0.58);
    z-index: 1300;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}

.address-map-overlay.active {
    display: flex;
}

.address-map-modal {
    width: min(900px, 100%);
    background: #fff;
    border: 1px solid #e5d3c1;
    border-radius: 14px;
    box-shadow: 0 24px 50px rgba(40, 21, 9, 0.3);
    overflow: hidden;
}

.address-map-head,
.address-map-foot {
    padding: 12px 14px;
    border-bottom: 1px solid #ecdccb;
}

.address-map-foot {
    border-bottom: 0;
    border-top: 1px solid #ecdccb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.address-map-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.address-map-head h3 {
    margin: 0 0 4px;
    color: var(--acc-ink);
}

.address-map-head p {
    margin: 0;
    color: #7c6658;
    font-size: 0.9rem;
}

.address-map-close {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border: 1px solid #e6d8cb;
    background: #fff;
    cursor: pointer;
}

.address-map-body {
    padding: 14px;
    display: grid;
    gap: 10px;
}

.address-map-search-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 8px;
}

.address-map-search-row input {
    width: 100%;
    border: 1px solid #e1d5c8;
    border-radius: 10px;
    padding: 10px 12px;
}

#addressMapCanvas {
    width: 100%;
    height: 380px;
    border-radius: 12px;
    border: 1px solid #e6d8cb;
    background: #fff6ed;
}

body.theme-dark .address-book-head h1,
body.theme-dark .address-book-head h2,
body.theme-dark .address-card h2,
body.theme-dark .address-item-head h3,
body.theme-dark .address-map-head h3 {
    color: #fff6ef;
}

body.theme-dark .account-subtitle,
body.theme-dark .address-line,
body.theme-dark .address-contact,
body.theme-dark .address-map-head p,
body.theme-dark .empty-addresses {
    color: #ccb7aa;
}

body.theme-dark .address-card,
body.theme-dark .address-item,
body.theme-dark .edit-details-form,
body.theme-dark .address-map-modal {
    background: #201a16;
    border-color: #3a2f27;
    color: #f3ece6;
}

body.theme-dark .address-item.is-default {
    background: #2b211b;
}

body.theme-dark .form-group label,
body.theme-dark .checkbox-row {
    color: #d9c3b5;
}

body.theme-dark .form-group input,
body.theme-dark .form-group textarea,
body.theme-dark .form-group select,
body.theme-dark .address-map-search-row input {
    background: #2b241f;
    border-color: #46372c;
    color: #fff6ef;
}

body.theme-dark .psgc-help {
    color: #c4ac9d;
}

body.theme-dark .btn-outline {
    background: #2e251f;
    border-color: #4b3b2f;
    color: #ecd7c9;
}

body.theme-dark .btn-outline:hover {
    background: #3a2e26;
}

body.theme-dark .btn-danger {
    border-color: #a94433;
    background: #4a221c;
    color: #f2bcb3;
}

body.theme-dark .address-map-overlay {
    background: rgba(0, 0, 0, 0.65);
}

body.theme-dark #addressMapCanvas {
    background: #2b241f;
    border-color: #46372c;
}

@media (max-width: 900px) {
    .address-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .address-book-head {
        flex-direction: column;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .compact-row {
        grid-template-columns: 1fr;
    }

    .address-map-search-row {
        grid-template-columns: 1fr;
    }

    #addressMapCanvas {
        height: 320px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isEmbedded = <?php echo $is_embedded ? 'true' : 'false'; ?>;
    const addressBookGoogleMapsApiKey = <?php echo json_encode((string)$google_maps_api_key); ?>;
    const addressBookSyncPayload = <?php echo json_encode($address_book_sync_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || [];
    const addressBookSuccessMessage = <?php echo json_encode((string)$flash_success); ?>;
    const addressBookErrorMessage = <?php echo json_encode((string)$flash_error); ?>;
    const mapOverlay = document.getElementById('addressMapOverlay');
    const mapModalClose = document.getElementById('addressMapClose');
    const mapCancelBtn = document.getElementById('addressMapCancelBtn');
    const mapApplyBtn = document.getElementById('addressMapApplyBtn');
    const mapSearchInput = document.getElementById('addressMapSearch');
    const mapSearchBtn = document.getElementById('addressMapSearchBtn');
    const mapCurrentBtn = document.getElementById('addressMapCurrentBtn');
    const mapCanvas = document.getElementById('addressMapCanvas');
    const openMapButtons = document.querySelectorAll('.open-map-picker');
    const swalApi = typeof window.Swal !== 'undefined'
        ? window.Swal
        : ((window.parent && typeof window.parent.Swal !== 'undefined') ? window.parent.Swal : null);

    const fireSwal = (title, text, icon) => {
        if (!swalApi) return;
        swalApi.fire(title, text, icon);
    };

    const notifyParentAddressBookUpdated = () => {
        if (!isEmbedded || !window.parent || window.parent === window) return;
        const targetOrigin = (window.location.origin && window.location.origin !== 'null') ? window.location.origin : '*';
        try {
            window.parent.postMessage({
                type: 'lechon_address_book_updated',
                addresses: Array.isArray(addressBookSyncPayload) ? addressBookSyncPayload : [],
                success_message: String(addressBookSuccessMessage || ''),
                error_message: String(addressBookErrorMessage || '')
            }, targetOrigin);
        } catch (error) {
            // No-op: parent messaging should not break address book interactions.
        }
    };

    const applyEmbeddedTheme = () => {
        if (!isEmbedded || !window.parent || !window.parent.document) return;
        const parentAccountPage = window.parent.document.querySelector('.account-page');
        if (!parentAccountPage) return;
        document.body.classList.toggle('theme-dark', parentAccountPage.classList.contains('theme-dark'));
    };

    const syncFrameHeight = () => {
        if (!isEmbedded || !window.frameElement) return;
        const nextHeight = Math.max(
            document.body ? document.body.scrollHeight : 0,
            document.documentElement ? document.documentElement.scrollHeight : 0
        );
        if (nextHeight > 0) {
            window.frameElement.style.height = nextHeight + 'px';
        }
    };

    let mapsLoadPromise = null;
    let mapInstance = null;
    let mapMarker = null;
    let mapGeocoder = null;
    let mapGoogleGeocodingAvailable = <?php echo $google_geocoding_enabled ? 'true' : 'false'; ?>;
    let mapAutocomplete = null;
    let targetPrefix = '';
    let selectedAddressText = '';
    let selectedCoordinates = null;
    let selectedAddressComponents = null;
    const defaultCoordinates = { lat: 14.3294, lng: 120.9367 };
    const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
    const psgcCache = new Map();
    const psgcControllers = new Map();
    const psgcInitPromises = new Map();

    const normalizeName = (value) => {
        let text = String(value || '').toLowerCase();
        try {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {
            // Keep original text if normalize is not supported.
        }
        return text.replace(/[^a-z0-9]/g, '');
    };
    const toNameTokens = (value) => {
        let text = String(value || '').toLowerCase();
        try {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {
            // Keep original text if normalize is not supported.
        }

        if (text.includes('metro manila')) {
            text += ' ncr national capital region';
        }
        if (text.includes('national capital region') || /\bncr\b/.test(text)) {
            text += ' metro manila ncr';
        }
        if (text.includes('calabarzon') || text.includes('region iv-a') || text.includes('region iva') || text.includes('region 4a')) {
            text += ' calabarzon region iva region 4a iv-a';
        }

        text = text
            .replace(/&/g, ' and ')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();

        const stopWords = new Set([
            'city',
            'municipality',
            'municipal',
            'province',
            'region',
            'barangay',
            'brgy',
            'of',
            'the',
            'and'
        ]);

        return Array.from(new Set(text.split(/\s+/).filter((token) => token && !stopWords.has(token))));
    };
    const sortByName = (items) => [].slice.call(items || []).sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    const getSelectedLabel = (selectElement) => {
        if (!selectElement || selectElement.selectedIndex < 0) return '';
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        if (!selectedOption || !selectedOption.value) return '';
        return selectedOption.textContent.trim();
    };
    const setSelectOptions = (selectElement, items, placeholder) => {
        if (!selectElement) return;
        selectElement.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = placeholder;
        selectElement.appendChild(defaultOption);
        sortByName(items).forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.code || '');
            option.textContent = String(item.name || '');
            selectElement.appendChild(option);
        });
    };
    const resetSelect = (selectElement, placeholder) => {
        if (!selectElement) return;
        setSelectOptions(selectElement, [], placeholder);
        selectElement.value = '';
        selectElement.disabled = true;
    };
    const findOptionValueByName = (selectElement, targetName) => {
        if (!selectElement || !targetName) return '';
        const normalizedTarget = normalizeName(targetName);
        const targetTokens = toNameTokens(targetName);
        if (!normalizedTarget) return '';
        if (!targetTokens.length) return '';

        let bestValue = '';
        let bestScore = 0;
        let bestOverlap = 0;

        for (const option of Array.from(selectElement.options || [])) {
            if (!option.value) continue;
            const normalizedOption = normalizeName(option.textContent || '');
            if (!normalizedOption) continue;
            if (normalizedOption === normalizedTarget || normalizedOption.includes(normalizedTarget) || normalizedTarget.includes(normalizedOption)) {
                return option.value;
            }

            const optionTokens = toNameTokens(option.textContent || '');
            if (!optionTokens.length) continue;

            const optionTokenSet = new Set(optionTokens);
            let overlap = 0;
            targetTokens.forEach((token) => {
                if (optionTokenSet.has(token)) overlap++;
            });
            if (overlap <= 0) continue;

            const score = overlap / Math.max(targetTokens.length, optionTokens.length);
            if (overlap > bestOverlap || (overlap === bestOverlap && score > bestScore)) {
                bestOverlap = overlap;
                bestScore = score;
                bestValue = option.value;
            }
        }

        if (bestValue && (bestOverlap >= Math.max(1, targetTokens.length - 1) || bestScore >= 0.45)) {
            return bestValue;
        }
        return '';
    };
    const extractAddressParts = (components = []) => {
        const getLongName = (typeMatcher) => {
            const match = (components || []).find((component) => (component.types || []).some(typeMatcher));
            return match?.long_name || '';
        };
        return {
            region: getLongName((type) => type === 'administrative_area_level_1'),
            province: getLongName((type) => type === 'administrative_area_level_2'),
            city: getLongName((type) => type === 'locality' || type === 'administrative_area_level_3'),
            barangay: getLongName((type) => type === 'sublocality_level_1' || type === 'neighborhood' || type === 'sublocality')
        };
    };

    const composeAddressFromParts = (parts = {}, street = '') => {
        const values = [
            String(street || '').trim(),
            String(parts?.barangay || '').trim(),
            String(parts?.city || '').trim(),
            String(parts?.province || '').trim(),
            String(parts?.region || '').trim()
        ].filter(Boolean);
        return values.join(', ');
    };

    const reverseGeocodeFromGoogle = (lat, lng) => {
        if (!mapGeocoder || !mapGoogleGeocodingAvailable) return Promise.resolve({ formatted: '', parts: null });
        return new Promise((resolve) => {
            mapGeocoder.geocode({ location: { lat, lng } }, (results, status) => {
                if (status === 'OK' && Array.isArray(results) && results.length) {
                    const prioritized = results.find((entry) => {
                        const types = entry?.types || [];
                        return types.includes('street_address')
                            || types.includes('premise')
                            || types.includes('subpremise')
                            || types.includes('route')
                            || types.includes('neighborhood')
                            || types.includes('sublocality')
                            || types.includes('locality');
                    }) || results[0];
                    const parts = extractAddressParts(prioritized?.address_components || []);
                    const formatted = String(prioritized?.formatted_address || results[0]?.formatted_address || '').trim()
                        || composeAddressFromParts(parts, '');
                    resolve({ formatted, parts });
                    return;
                }
                if (isGoogleGeocodingUnavailableStatus(status)) {
                    mapGoogleGeocodingAvailable = false;
                }
                resolve({ formatted: '', parts: null });
            });
        });
    };

    const reverseGeocodeFromNominatim = async (lat, lng) => {
        try {
            const endpoint = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                + '&lat=' + encodeURIComponent(String(lat))
                + '&lon=' + encodeURIComponent(String(lng))
                + '&addressdetails=1';
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            if (!response.ok) {
                return { formatted: '', parts: null };
            }
            const data = await response.json();
            const address = data?.address || {};
            const parts = {
                barangay: String(address.suburb || address.neighbourhood || address.village || address.hamlet || '').trim(),
                city: String(address.city || address.town || address.municipality || address.county || '').trim(),
                province: String(address.state_district || address.province || address.county || '').trim(),
                region: String(address.state || address.region || '').trim()
            };
            const street = String(address.road || address.residential || address.pedestrian || '').trim();
            const formatted = String(data?.display_name || '').trim() || composeAddressFromParts(parts, street);
            return { formatted, parts };
        } catch (error) {
            console.error('Nominatim reverse geocode failed:', error);
            return { formatted: '', parts: null };
        }
    };

    const isGoogleGeocodingUnavailableStatus = (status) => {
        const normalized = String(status || '').trim().toUpperCase();
        return normalized === 'REQUEST_DENIED'
            || normalized === 'OVER_QUERY_LIMIT'
            || normalized === 'OVER_DAILY_LIMIT'
            || normalized === 'INVALID_REQUEST';
    };

    const forwardGeocodeFromNominatim = async (query) => {
        const addressText = String(query || '').trim();
        if (!addressText) return null;
        try {
            const endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2'
                + '&addressdetails=1'
                + '&countrycodes=ph'
                + '&limit=1'
                + '&q=' + encodeURIComponent(addressText);
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            if (!response.ok) {
                return null;
            }

            const data = await response.json();
            if (!Array.isArray(data) || !data.length) {
                return null;
            }

            const first = data[0] || {};
            const lat = Number(first?.lat);
            const lng = Number(first?.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return null;
            }

            const address = first?.address || {};
            const parts = {
                barangay: String(address.suburb || address.neighbourhood || address.village || address.hamlet || '').trim(),
                city: String(address.city || address.town || address.municipality || address.county || '').trim(),
                province: String(address.state_district || address.province || address.county || '').trim(),
                region: String(address.state || address.region || '').trim()
            };
            const street = String(address.road || address.residential || address.pedestrian || '').trim();
            const formatted = String(first?.display_name || '').trim() || composeAddressFromParts(parts, street);

            return {
                point: { lat, lng },
                formatted,
                parts
            };
        } catch (error) {
            return null;
        }
    };

    const fetchPsgc = async (path) => {
        const cacheKey = String(path || '');
        if (psgcCache.has(cacheKey)) {
            return psgcCache.get(cacheKey);
        }
        const response = await fetch(PSGC_API_BASE + cacheKey, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) {
            throw new Error('PSGC request failed: ' + cacheKey);
        }
        const payload = await response.json();
        psgcCache.set(cacheKey, payload);
        return payload;
    };

    const createPsgcController = (prefix) => {
        const refs = {
            region: getTargetField(prefix, 'region_code'),
            province: getTargetField(prefix, 'province_code'),
            city: getTargetField(prefix, 'city_code'),
            barangay: getTargetField(prefix, 'barangay_code'),
            regionName: getTargetField(prefix, 'region_name'),
            provinceName: getTargetField(prefix, 'province_name'),
            cityName: getTargetField(prefix, 'city_name'),
            barangayName: getTargetField(prefix, 'barangay_name'),
            street: getTargetField(prefix, 'street_address'),
            full: getTargetField(prefix, 'full_address')
        };

        if (!refs.region || !refs.city || !refs.barangay) {
            return null;
        }

        refs.region.required = true;
        refs.city.required = true;
        refs.barangay.required = true;
        if (refs.province) refs.province.required = false;

        const syncNamesAndAddress = () => {
            if (refs.regionName) refs.regionName.value = getSelectedLabel(refs.region);
            if (refs.provinceName) refs.provinceName.value = getSelectedLabel(refs.province);
            if (refs.cityName) refs.cityName.value = getSelectedLabel(refs.city);
            if (refs.barangayName) refs.barangayName.value = getSelectedLabel(refs.barangay);

            const locationParts = [
                refs.barangayName ? refs.barangayName.value.trim() : '',
                refs.cityName ? refs.cityName.value.trim() : '',
                refs.provinceName ? refs.provinceName.value.trim() : '',
                refs.regionName ? refs.regionName.value.trim() : ''
            ].filter(Boolean);
            const composedAddress = [
                refs.street ? refs.street.value.trim() : '',
                ...locationParts
            ].filter(Boolean).join(', ');

            if (refs.full && locationParts.length > 0 && composedAddress) {
                refs.full.value = composedAddress;
            }
        };

        const loadRegions = async () => {
            const regions = await fetchPsgc('/regions');
            setSelectOptions(refs.region, regions, 'Select region');
            refs.region.disabled = regions.length === 0;
        };

        const loadProvinces = async (regionCode) => {
            if (!refs.province) return [];
            if (!regionCode) {
                resetSelect(refs.province, 'Select province');
                return [];
            }
            const provinces = await fetchPsgc('/regions/' + encodeURIComponent(regionCode) + '/provinces');
            setSelectOptions(refs.province, provinces, provinces.length ? 'Select province' : 'No province');
            refs.province.disabled = provinces.length === 0;
            refs.province.required = provinces.length > 0;
            return provinces;
        };

        const loadCities = async (regionCode, provinceCode) => {
            if (!regionCode) {
                resetSelect(refs.city, 'Select city or municipality');
                return [];
            }
            const path = provinceCode
                ? '/provinces/' + encodeURIComponent(provinceCode) + '/cities-municipalities'
                : '/regions/' + encodeURIComponent(regionCode) + '/cities-municipalities';
            const cities = await fetchPsgc(path);
            setSelectOptions(refs.city, cities, 'Select city or municipality');
            refs.city.disabled = cities.length === 0;
            return cities;
        };

        const loadBarangays = async (cityCode) => {
            if (!cityCode) {
                resetSelect(refs.barangay, 'Select barangay');
                return [];
            }
            const barangays = await fetchPsgc('/cities-municipalities/' + encodeURIComponent(cityCode) + '/barangays');
            setSelectOptions(refs.barangay, barangays, 'Select barangay');
            refs.barangay.disabled = barangays.length === 0;
            return barangays;
        };

        const handleRegionChange = async (isRestore) => {
            const regionCode = refs.region.value || '';
            if (!isRestore) {
                resetSelect(refs.province, 'Select province');
                resetSelect(refs.city, 'Select city or municipality');
                resetSelect(refs.barangay, 'Select barangay');
                syncNamesAndAddress();
            }
            if (!regionCode) {
                syncNamesAndAddress();
                return;
            }
            const provinces = await loadProvinces(regionCode);
            if (!provinces.length) {
                await loadCities(regionCode, '');
            } else if (provinces.length === 1 && refs.province.value) {
                await handleProvinceChange(true);
            }
            syncNamesAndAddress();
        };

        const handleProvinceChange = async (isRestore) => {
            const regionCode = refs.region.value || '';
            const provinceCode = refs.province ? refs.province.value : '';
            if (!isRestore) {
                resetSelect(refs.city, 'Select city or municipality');
                resetSelect(refs.barangay, 'Select barangay');
                syncNamesAndAddress();
            }
            await loadCities(regionCode, provinceCode);
            syncNamesAndAddress();
        };

        const handleCityChange = async (isRestore) => {
            const cityCode = refs.city.value || '';
            if (!isRestore) {
                resetSelect(refs.barangay, 'Select barangay');
                syncNamesAndAddress();
            }
            await loadBarangays(cityCode);
            syncNamesAndAddress();
        };

        const restoreSelections = async () => {
            const presetRegion = refs.region.getAttribute('data-selected') || refs.region.value || '';
            const presetProvince = refs.province ? (refs.province.getAttribute('data-selected') || refs.province.value || '') : '';
            const presetCity = refs.city.getAttribute('data-selected') || refs.city.value || '';
            const presetBarangay = refs.barangay.getAttribute('data-selected') || refs.barangay.value || '';

            if (!presetRegion) {
                syncNamesAndAddress();
                return;
            }

            refs.region.value = presetRegion;
            await handleRegionChange(true);

            if (refs.province && presetProvince && !refs.province.disabled) {
                refs.province.value = presetProvince;
                await handleProvinceChange(true);
            } else if (refs.province && !refs.province.required) {
                await handleProvinceChange(true);
            }

            if (presetCity && !refs.city.disabled) {
                refs.city.value = presetCity;
                await handleCityChange(true);
            }

            if (presetBarangay && !refs.barangay.disabled) {
                refs.barangay.value = presetBarangay;
            }

            syncNamesAndAddress();
        };

        const applyAddressParts = async (parts = {}) => {
            if (!parts) return;
            const regionCode = findOptionValueByName(refs.region, parts.region);
            if (regionCode) {
                refs.region.value = regionCode;
                await handleRegionChange(true);
            }

            const provinceCode = findOptionValueByName(refs.province, parts.province);
            if (provinceCode && refs.province && !refs.province.disabled) {
                refs.province.value = provinceCode;
                await handleProvinceChange(true);
            }

            const cityCode = findOptionValueByName(refs.city, parts.city);
            if (cityCode && !refs.city.disabled) {
                refs.city.value = cityCode;
                await handleCityChange(true);
            }

            const barangayCode = findOptionValueByName(refs.barangay, parts.barangay);
            if (barangayCode && !refs.barangay.disabled) {
                refs.barangay.value = barangayCode;
            }

            syncNamesAndAddress();
        };

        refs.region.addEventListener('change', () => {
            handleRegionChange(false).catch((error) => {
                console.error('PSGC region loading failed:', error);
            });
        });
        if (refs.province) {
            refs.province.addEventListener('change', () => {
                handleProvinceChange(false).catch((error) => {
                    console.error('PSGC province loading failed:', error);
                });
            });
        }
        refs.city.addEventListener('change', () => {
            handleCityChange(false).catch((error) => {
                console.error('PSGC city loading failed:', error);
            });
        });
        refs.barangay.addEventListener('change', syncNamesAndAddress);
        if (refs.street) refs.street.addEventListener('input', syncNamesAndAddress);

        return {
            initialize: async () => {
                try {
                    resetSelect(refs.province, 'Select province');
                    resetSelect(refs.city, 'Select city or municipality');
                    resetSelect(refs.barangay, 'Select barangay');
                    await loadRegions();
                    await restoreSelections();
                } catch (error) {
                    console.error('PSGC initialization failed for', prefix, error);
                    syncNamesAndAddress();
                }
            },
            applyAddressParts,
            syncNamesAndAddress
        };
    };

    applyEmbeddedTheme();
    syncFrameHeight();
    notifyParentAddressBookUpdated();
    if (isEmbedded && window.parent && window.parent.document && typeof MutationObserver !== 'undefined') {
        const parentAccountPage = window.parent.document.querySelector('.account-page');
        if (parentAccountPage) {
            const parentThemeObserver = new MutationObserver(() => {
                applyEmbeddedTheme();
            });
            parentThemeObserver.observe(parentAccountPage, { attributes: true, attributeFilter: ['class'] });
        }

        if (typeof ResizeObserver !== 'undefined') {
            const frameResizeObserver = new ResizeObserver(() => {
                syncFrameHeight();
            });
            frameResizeObserver.observe(document.body);
        } else {
            setInterval(syncFrameHeight, 600);
        }

        window.addEventListener('load', function () {
            syncFrameHeight();
            notifyParentAddressBookUpdated();
        });
        window.setTimeout(syncFrameHeight, 180);
    }

    const getTargetField = (prefix, fieldName) => document.getElementById(prefix + '_' + fieldName);
    const psgcPrefixes = Array.from(new Set(
        Array.from(document.querySelectorAll('.psgc-region[data-prefix]'))
            .map((element) => String(element.getAttribute('data-prefix') || '').trim())
            .filter(Boolean)
    ));

    psgcPrefixes.forEach((prefix) => {
        const controller = createPsgcController(prefix);
        if (!controller) return;
        psgcControllers.set(prefix, controller);
        const initPromise = controller.initialize().then(() => {
            syncFrameHeight();
        });
        psgcInitPromises.set(prefix, initPromise);
    });

    const closeMapPicker = () => {
        if (!mapOverlay) return;
        mapOverlay.classList.remove('active');
        mapOverlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        targetPrefix = '';
    };

    const openMapPicker = async (prefix) => {
        if (!prefix || !mapOverlay) return;
        targetPrefix = prefix;
        mapOverlay.classList.add('active');
        mapOverlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        const mapReady = await ensureMapReady();
        if (!mapReady) {
            closeMapPicker();
            return;
        }

        const latField = getTargetField(prefix, 'latitude');
        const lngField = getTargetField(prefix, 'longitude');
        const fullAddressField = getTargetField(prefix, 'full_address');
        const lat = parseFloat(latField?.value || '');
        const lng = parseFloat(lngField?.value || '');
        const fullAddress = (fullAddressField?.value || '').trim();

        if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
            setMapPoint({ lat, lng }, true);
        } else if (fullAddress !== '') {
            if (mapSearchInput) mapSearchInput.value = fullAddress;
            const found = await geocodeAddress(fullAddress);
            if (!found) {
                setMapPoint(defaultCoordinates, false);
                selectedAddressText = fullAddress;
                selectedCoordinates = null;
                selectedAddressComponents = null;
            }
        } else {
            setMapPoint(defaultCoordinates, false);
            if (mapSearchInput) mapSearchInput.value = '';
            selectedAddressText = '';
            selectedCoordinates = null;
            selectedAddressComponents = null;
        }

        setTimeout(() => {
            if (mapInstance) {
                mapInstance.invalidateSize();
                const markerLatLng = mapMarker?.getLatLng?.();
                if (markerLatLng) {
                    mapInstance.setView(markerLatLng, mapInstance.getZoom());
                }
            }
            if (mapSearchInput) mapSearchInput.focus();
        }, 80);
    };

    const ensureMapReady = async () => {
        if (!window.L || !mapCanvas) {
            fireSwal('Map Unavailable', 'Leaflet Map failed to load. Please refresh and try again.', 'error');
            return false;
        }

        if (!mapInstance) {
            mapInstance = L.map(mapCanvas).setView([defaultCoordinates.lat, defaultCoordinates.lng], 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(mapInstance);

            mapMarker = L.marker([defaultCoordinates.lat, defaultCoordinates.lng], {
                draggable: true
            }).addTo(mapInstance);

            mapInstance.on('click', (event) => {
                if (!event || !event.latlng) return;
                const point = { lat: event.latlng.lat, lng: event.latlng.lng };
                setMapPoint(point, true);
            });

            mapMarker.on('dragend', () => {
                const latlng = mapMarker.getLatLng();
                if (!latlng) return;
                const point = { lat: latlng.lat, lng: latlng.lng };
                setMapPoint(point, true);
            });
        }
        return true;
    };

    const reverseGeocode = async (lat, lng) => {
        const fallbackResult = await reverseGeocodeFromNominatim(lat, lng);
        if ((fallbackResult?.formatted || '') !== '' || composeAddressFromParts(fallbackResult?.parts || {}, '') !== '') {
            return fallbackResult;
        }
        return { formatted: '', parts: null };
    };

    const geocodeAddress = async (addressText) => {
        if (!addressText) return false;
        const fallback = await forwardGeocodeFromNominatim(addressText);
        if (!fallback || !fallback.point) {
            return false;
        }
        await setMapPoint(fallback.point, false);
        selectedAddressText = String(fallback.formatted || addressText).trim();
        selectedAddressComponents = fallback.parts || null;
        if (mapSearchInput) mapSearchInput.value = selectedAddressText;
        return true;
    };

    const setMapPoint = async (point, lookupAddress) => {
        if (!mapInstance || !mapMarker || !point) return;
        mapInstance.setView([point.lat, point.lng], 16);
        mapMarker.setLatLng([point.lat, point.lng]);
        selectedCoordinates = point;

        if (lookupAddress) {
            const resolved = await reverseGeocode(point.lat, point.lng);
            selectedAddressText = (resolved && resolved.formatted) || composeAddressFromParts(resolved?.parts || {}, '');
            selectedAddressComponents = resolved ? (resolved.parts || null) : null;
            if (mapSearchInput) {
                mapSearchInput.value = selectedAddressText;
            }
        }
    };

    const applyMapSelection = async () => {
        if (!targetPrefix) {
            closeMapPicker();
            return;
        }
        const fullAddressField = getTargetField(targetPrefix, 'full_address');
        const streetField = getTargetField(targetPrefix, 'street_address');
        const latField = getTargetField(targetPrefix, 'latitude');
        const lngField = getTargetField(targetPrefix, 'longitude');
        const currentInputAddress = (mapSearchInput?.value || '').trim();
        let finalAddress = selectedAddressText || currentInputAddress;

        if (!finalAddress && selectedCoordinates) {
            const resolved = await reverseGeocode(selectedCoordinates.lat, selectedCoordinates.lng);
            if (resolved && resolved.formatted) {
                finalAddress = resolved.formatted;
                selectedAddressComponents = resolved.parts || selectedAddressComponents;
                selectedAddressText = finalAddress;
            }
        }

        if (!finalAddress && selectedAddressComponents) {
            finalAddress = composeAddressFromParts(selectedAddressComponents, streetField ? String(streetField.value || '').trim() : '');
        }

        if (!finalAddress && fullAddressField) {
            finalAddress = String(fullAddressField.value || '').trim();
        }

        if (!finalAddress) {
            fireSwal(
                'Address Not Resolved',
                'We could not get a readable address from this pin. Move the marker to a nearby road/building or search the address, then apply again.',
                'warning'
            );
            return;
        }

        if (fullAddressField) {
            fullAddressField.value = finalAddress;
        }
        if (streetField) {
            const streetText = (finalAddress.split(',')[0] || '').trim();
            if (streetText) streetField.value = streetText;
        }
        if (latField && selectedCoordinates) {
            latField.value = String(selectedCoordinates.lat);
        }
        if (lngField && selectedCoordinates) {
            lngField.value = String(selectedCoordinates.lng);
        }

        const controller = psgcControllers.get(targetPrefix);
        if (controller) {
            const initPromise = psgcInitPromises.get(targetPrefix);
            if (initPromise) {
                await initPromise;
            }
            let parts = selectedAddressComponents;
            if (!parts && finalAddress) {
                const mapReady = await ensureMapReady();
                if (mapReady && mapGeocoder) {
                    await geocodeAddress(finalAddress);
                    parts = selectedAddressComponents;
                }
            }
            if (parts) {
                await controller.applyAddressParts(parts);
            }
            controller.syncNamesAndAddress();
        }

        closeMapPicker();
        syncFrameHeight();
    };

    const syncPsgcFromMapSelection = async () => {
        if (!targetPrefix) return;
        const controller = psgcControllers.get(targetPrefix);
        if (!controller) return;

        const initPromise = psgcInitPromises.get(targetPrefix);
        if (initPromise) {
            await initPromise;
        }

        let parts = selectedAddressComponents;
        if ((!parts || !composeAddressFromParts(parts, '')) && selectedCoordinates) {
            const resolved = await reverseGeocode(selectedCoordinates.lat, selectedCoordinates.lng);
            if (resolved) {
                if (!selectedAddressText && resolved.formatted) {
                    selectedAddressText = resolved.formatted;
                    if (mapSearchInput) mapSearchInput.value = selectedAddressText;
                }
                if (resolved.parts) {
                    selectedAddressComponents = resolved.parts;
                    parts = resolved.parts;
                }
            }
        }

        if (parts) {
            await controller.applyAddressParts(parts);
        }
        controller.syncNamesAndAddress();
        syncFrameHeight();
    };

    const deleteForms = document.querySelectorAll('.delete-address-form');
    deleteForms.forEach((form) => {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const addressLabel = this.getAttribute('data-label') || 'this address';

            const proceed = () => this.submit();
            if (!swalApi) {
                if (confirm('Delete ' + addressLabel + '?')) proceed();
                return;
            }

            swalApi.fire({
                icon: 'warning',
                title: 'Delete Address?',
                text: 'Remove "' + addressLabel + '" from your Address Book?',
                showCancelButton: true,
                confirmButtonColor: '#c62828',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    proceed();
                }
            });
        });
    });

    openMapButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const prefix = String(button.getAttribute('data-target-prefix') || '').trim();
            openMapPicker(prefix);
        });
    });

    if (mapModalClose) mapModalClose.addEventListener('click', closeMapPicker);
    if (mapCancelBtn) mapCancelBtn.addEventListener('click', closeMapPicker);
    if (mapOverlay) {
        mapOverlay.addEventListener('click', (event) => {
            if (event.target === mapOverlay) {
                closeMapPicker();
            }
        });
    }

    if (mapSearchBtn) {
        mapSearchBtn.addEventListener('click', async () => {
            const query = (mapSearchInput?.value || '').trim();
            if (!query) {
                fireSwal('Address Required', 'Please type an address to search.', 'warning');
                return;
            }
            await ensureMapReady();
            const found = await geocodeAddress(query);
            if (!found) {
                fireSwal('Address Not Found', 'No matching result for that address.', 'warning');
            }
        });
    }

    if (mapSearchInput) {
        mapSearchInput.addEventListener('keydown', async (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const query = mapSearchInput.value.trim();
            if (!query) return;
            await ensureMapReady();
            await geocodeAddress(query);
        });
    }

    if (mapCurrentBtn) {
        mapCurrentBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                fireSwal('Geolocation Unavailable', 'This browser does not support location services.', 'error');
                return;
            }
            navigator.geolocation.getCurrentPosition(async (position) => {
                await ensureMapReady();
                const point = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                await setMapPoint(point, true);
                await syncPsgcFromMapSelection();
            }, () => {
                fireSwal('Location Error', 'Unable to retrieve current location.', 'error');
            }, {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 300000
            });
        });
    }

    if (mapApplyBtn) {
        mapApplyBtn.addEventListener('click', () => {
            applyMapSelection().catch((error) => {
                console.error('Unable to apply map selection:', error);
                fireSwal('Map Error', 'Unable to apply the selected map address. Please try again.', 'error');
            });
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mapOverlay && mapOverlay.classList.contains('active')) {
            closeMapPicker();
        }
    });
});
</script>

<?php
if (!$is_embedded) {
    mysqli_close($conn);
    include 'includes/footer.php';
}
?>
