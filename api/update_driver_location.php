<?php
/**
 * API Endpoint: Update Driver Location
 * Receives GPS location updates from driver app
 */
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

/**
 * Safely checks if a table column exists.
 */
function hasColumn($conn, $table, $column) {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $query = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    $result = mysqli_query($conn, $query);
    return $result && mysqli_num_rows($result) > 0;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid request method']));
}

try {
    // Get user's employee ID
    $user_id = $_SESSION['user_id'];
    $employee_query = "SELECT id FROM employees WHERE user_id = ?";
    $emp_stmt = mysqli_prepare($conn, $employee_query);
    
    if (!$emp_stmt) {
        throw new Exception("Database error");
    }
    
    mysqli_stmt_bind_param($emp_stmt, "i", $user_id);
    mysqli_stmt_execute($emp_stmt);
    $emp_result = mysqli_stmt_get_result($emp_stmt);
    $employee = mysqli_fetch_assoc($emp_result);
    mysqli_stmt_close($emp_stmt);
    
    if (!$employee) {
        throw new Exception("Employee record not found");
    }
    
    $driver_id = $employee['id'];
    
    // Get location data
    if (!isset($_POST['latitude'], $_POST['longitude'])) {
        throw new Exception("Missing coordinates");
    }

    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $accuracy = isset($_POST['accuracy']) ? floatval($_POST['accuracy']) : null;

    // Validate coordinates
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        throw new Exception("Coordinates out of range");
    }

    // employees_geo_tracking has schema variants:
    // some DBs use accuracy_meters while older ones may use accuracy.
    $accuracy_column = null;
    if (hasColumn($conn, 'employees_geo_tracking', 'accuracy_meters')) {
        $accuracy_column = 'accuracy_meters';
    } elseif (hasColumn($conn, 'employees_geo_tracking', 'accuracy')) {
        $accuracy_column = 'accuracy';
    }

    $existing_geo_query = "SELECT id FROM employees_geo_tracking WHERE employee_id = ? LIMIT 1";
    $existing_geo_stmt = mysqli_prepare($conn, $existing_geo_query);
    if (!$existing_geo_stmt) {
        throw new Exception("Failed to prepare employee geotracking query");
    }

    mysqli_stmt_bind_param($existing_geo_stmt, "i", $driver_id);
    mysqli_stmt_execute($existing_geo_stmt);
    $existing_geo_result = mysqli_stmt_get_result($existing_geo_stmt);
    $existing_geo = mysqli_fetch_assoc($existing_geo_result);
    mysqli_stmt_close($existing_geo_stmt);

    if ($existing_geo) {
        if ($accuracy_column !== null) {
            $update_geo_query = "UPDATE employees_geo_tracking
                                 SET current_latitude = ?, current_longitude = ?, `{$accuracy_column}` = ?, last_update = NOW()
                                 WHERE id = ?";
            $update_geo_stmt = mysqli_prepare($conn, $update_geo_query);
            if (!$update_geo_stmt) {
                throw new Exception("Failed to prepare geotracking update");
            }
            mysqli_stmt_bind_param($update_geo_stmt, "dddi", $latitude, $longitude, $accuracy, $existing_geo['id']);
        } else {
            $update_geo_query = "UPDATE employees_geo_tracking
                                 SET current_latitude = ?, current_longitude = ?, last_update = NOW()
                                 WHERE id = ?";
            $update_geo_stmt = mysqli_prepare($conn, $update_geo_query);
            if (!$update_geo_stmt) {
                throw new Exception("Failed to prepare geotracking update");
            }
            mysqli_stmt_bind_param($update_geo_stmt, "ddi", $latitude, $longitude, $existing_geo['id']);
        }

        if (!mysqli_stmt_execute($update_geo_stmt)) {
            $update_error = mysqli_stmt_error($update_geo_stmt);
            mysqli_stmt_close($update_geo_stmt);
            throw new Exception("Failed to update driver location: " . $update_error);
        }
        mysqli_stmt_close($update_geo_stmt);
    } else {
        if ($accuracy_column !== null) {
            $insert_geo_query = "INSERT INTO employees_geo_tracking
                                 (employee_id, current_latitude, current_longitude, `{$accuracy_column}`, last_update)
                                 VALUES (?, ?, ?, ?, NOW())";
            $insert_geo_stmt = mysqli_prepare($conn, $insert_geo_query);
            if (!$insert_geo_stmt) {
                throw new Exception("Failed to prepare geotracking insert");
            }
            mysqli_stmt_bind_param($insert_geo_stmt, "iddd", $driver_id, $latitude, $longitude, $accuracy);
        } else {
            $insert_geo_query = "INSERT INTO employees_geo_tracking
                                 (employee_id, current_latitude, current_longitude, last_update)
                                 VALUES (?, ?, ?, NOW())";
            $insert_geo_stmt = mysqli_prepare($conn, $insert_geo_query);
            if (!$insert_geo_stmt) {
                throw new Exception("Failed to prepare geotracking insert");
            }
            mysqli_stmt_bind_param($insert_geo_stmt, "idd", $driver_id, $latitude, $longitude);
        }

        if (!mysqli_stmt_execute($insert_geo_stmt)) {
            $insert_error = mysqli_stmt_error($insert_geo_stmt);
            mysqli_stmt_close($insert_geo_stmt);
            throw new Exception("Failed to store driver location: " . $insert_error);
        }
        mysqli_stmt_close($insert_geo_stmt);
    }

    // Push fresh coordinates to all active deliveries for this driver.
    $update_tracking = "UPDATE logistics_tracking
                        SET current_latitude = ?,
                            current_longitude = ?,
                            last_location_update = NOW(),
                            updated_at = NOW()
                        WHERE driver_id = ?
                          AND current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')";
    $tracking_stmt = mysqli_prepare($conn, $update_tracking);
    $updated_deliveries = 0;
    if ($tracking_stmt) {
        mysqli_stmt_bind_param($tracking_stmt, "ddi", $latitude, $longitude, $driver_id);
        mysqli_stmt_execute($tracking_stmt);
        $updated_deliveries = mysqli_stmt_affected_rows($tracking_stmt);
        mysqli_stmt_close($tracking_stmt);
    }

    die(json_encode([
        'success' => true,
        'message' => 'Location updated successfully',
        'updated_deliveries' => max(0, intval($updated_deliveries)),
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => $e->getMessage()]));
}
?>
