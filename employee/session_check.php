<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login page if the user is not logged in.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

require_once __DIR__ . '/../includes/config.php'; // Assumes a central config for DB connection
$user_id = $_SESSION['user_id']; // This is the ID from the `users` table.

// Check if user_type is 'employee' or 'admin' in session
$user_type = isset($_SESSION['user_type']) ? strtolower(trim($_SESSION['user_type'])) : '';
if ($user_type !== 'employee' && $user_type !== 'admin') {
    // If not employee or admin, deny access.
    header("Location: ../index.php?error=unauthorized");
    exit();
}

// Find the corresponding employee ID from the `employees` table
$stmt = $conn->prepare("SELECT id FROM employees WHERE user_id = ?");
if (!$stmt) {
    // Handle database preparation error
    die("Error preparing statement to find employee record: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$employee_id = null; // Default to null

if ($result->num_rows > 0) {
    $employee_record = $result->fetch_assoc();
    $employee_id = $employee_record['id']; // This is the correct ID for the `attendance` and other employee-related tables.
} else {
    // Auto-linking logic: If no employee record is linked by user_id, try to link by email.
    // This is useful if an employee was created in HR but the user account was created separately.
    $user_email_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $user_email_stmt->bind_param("i", $user_id);
    $user_email_stmt->execute();
    $user_email_result = $user_email_stmt->get_result();
    if ($user_email_row = $user_email_result->fetch_assoc()) {
        $user_email = $user_email_row['email'];

        // Find an unlinked employee record with the same email
        $find_emp_stmt = $conn->prepare("SELECT id FROM employees WHERE email = ? AND (user_id IS NULL OR user_id = 0)");
        $find_emp_stmt->bind_param("s", $user_email);
        $find_emp_stmt->execute();
        $emp_result = $find_emp_stmt->get_result();
        if ($unlinked_employee = $emp_result->fetch_assoc()) {
            $employee_id_to_link = $unlinked_employee['id'];
            // Link them!
            $link_stmt = $conn->prepare("UPDATE employees SET user_id = ? WHERE id = ?");
            $link_stmt->bind_param("ii", $user_id, $employee_id_to_link);
            if ($link_stmt->execute()) {
                $employee_id = $employee_id_to_link; // Successfully linked, set the employee_id for the session
            }
            $link_stmt->close();
        }
        $find_emp_stmt->close();
    }
    $user_email_stmt->close();
}
$stmt->close();

// For admins without an employee record, they can still view some pages, but actions requiring an employee_id will fail gracefully.
// For users with 'employee' type, not having a record is a configuration error.
// We allow the script to continue so the page can handle the display of this error gracefully.