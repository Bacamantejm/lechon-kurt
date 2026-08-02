<?php
$target = '../super_admin/franchise_applications.php';
$query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $target .= '?' . $query;
}
header('Location: ' . $target, true, 307);
exit;

