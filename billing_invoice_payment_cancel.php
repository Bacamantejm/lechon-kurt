<?php
session_start();
$_SESSION['error'] = 'Invoice payment was cancelled before completion.';
header('Location: admin/partner_billing.php');
exit;
