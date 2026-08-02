<?php
require_once 'session_check.php';

// Check if employee_id is null, which means the user is logged in but not linked to an employee record
if ($employee_id === null) {
    $_SESSION['message'] = "Error: Your user account is not linked to an employee record. Please contact HR to file a leave.";
    $_SESSION['msg_type'] = "danger";
    header("Location: leave_request.php");
    exit();
}

if (isset($_POST['submit_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];
    $status = 'pending'; // Default status
    $proof_path = NULL;

    // Basic validation
    if (empty($leave_type) || empty($start_date) || empty($end_date) || empty($reason)) {
        $_SESSION['message'] = "All fields except proof are required.";
        $_SESSION['msg_type'] = "danger";
        header("Location: leave_request.php");
        exit();
    }

    if (strtotime($start_date) > strtotime($end_date)) {
        $_SESSION['message'] = "End date cannot be before the start date.";
        $_SESSION['msg_type'] = "danger";
        header("Location: leave_request.php");
        exit();
    }

    // Handle file upload
    if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
        $target_dir = "../uploads/leave_proofs/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file = $_FILES['proof'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_ext, $allowed_ext)) {
            if ($file['size'] <= 5 * 1024 * 1024) { // 5MB limit
                $new_file_name = "proof_" . $employee_id . "_" . time() . "." . $file_ext;
                $target_file = $target_dir . $new_file_name;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $proof_path = $target_file;
                } else {
                    $_SESSION['message'] = "Failed to upload proof file.";
                    $_SESSION['msg_type'] = "danger";
                    header("Location: leave_request.php");
                    exit();
                }
            } else {
                $_SESSION['message'] = "File is too large. Maximum size is 5MB.";
                $_SESSION['msg_type'] = "danger";
                header("Location: leave_request.php");
                exit();
            }
        } else {
            $_SESSION['message'] = "Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.";
            $_SESSION['msg_type'] = "danger";
            header("Location: leave_request.php");
            exit();
        }
    }

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, proof_path, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issssss", $employee_id, $leave_type, $start_date, $end_date, $reason, $proof_path, $status);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Leave request submitted successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error submitting request: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();

} else {
    $_SESSION['message'] = "Invalid request.";
    $_SESSION['msg_type'] = "danger";
}

header("Location: leave_request.php");
exit();
?>