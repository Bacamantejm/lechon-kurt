<?php
$target = '../super_admin/get_application_details.php';
$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $target .= '?' . $query;
}
header('Location: ' . $target, true, 307);
exit;

