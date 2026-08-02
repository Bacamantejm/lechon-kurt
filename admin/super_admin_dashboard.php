<?php
$target = '../super_admin/super_admin_dashboard.php';
$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $target .= '?' . $query;
}
header('Location: ' . $target, true, 307);
exit;

