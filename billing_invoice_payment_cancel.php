<?php
session_start();
$_SESSION['error'] = 'Subscription checkout was cancelled before completion.';
header('Location: admin/subscription_plans.php');
exit;
