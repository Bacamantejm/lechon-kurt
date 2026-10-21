<?php
session_start();
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['user_type'] ?? '') === 'admin') {
        header('Location: admin/products.php');
        exit;
    }
    header('Location: seller_products.php');
    exit;
}
header('Location: login.php');
exit;
