<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';

// Invalidate token in database
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $clear_stmt = mysqli_prepare($conn, "UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?");
    if ($clear_stmt) {
        mysqli_stmt_bind_param($clear_stmt, "i", $uid);
        mysqli_stmt_execute($clear_stmt);
        mysqli_stmt_close($clear_stmt);
    }
} elseif (!empty($_COOKIE['remember_token'])) {
    $token = trim((string)$_COOKIE['remember_token']);
    $clear_stmt = mysqli_prepare($conn, "UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE remember_token = ?");
    if ($clear_stmt) {
        mysqli_stmt_bind_param($clear_stmt, "s", $token);
        mysqli_stmt_execute($clear_stmt);
        mysqli_stmt_close($clear_stmt);
    }
}

// Unset all of the session variables.
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear the "remember me" cookie if it exists
setcookie('remember_token', '', time() - 3600, '/');

// Finally, destroy the session.
session_destroy();
header("Location: ../index.php");
exit;
?>
