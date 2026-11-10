<?php
$query_string = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
$target = '../super_admin/security_access_control.php';
if ($query_string !== '') {
    $target .= '?' . $query_string;
}
header('Location: ' . $target, true, 302);
exit;
