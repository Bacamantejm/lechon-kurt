<?php
$target = '../super_admin/users.php';
$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $target .= '?' . $query;
}
header('Location: ' . $target, true, 307);
exit;

