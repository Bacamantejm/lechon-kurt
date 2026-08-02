<?php
$current_page = 'sitemap';
$page_title = "Sitemap | Lechon Delights";
include 'includes/header.php';
?>

<section class="sitemap-page">
    <div class="container">
        <h1>Sitemap</h1>
        <p>Navigate through our website using the links below.</p>

        <div class="sitemap-grid">
            <div class="sitemap-col">
                <h3>Main Pages</h3>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="menu.php">Menu</a></li>
                    <li><a href="preorder.php">Pre-Order</a></li>
                    <li><a href="about.php">Our Story</a></li>
                    <li><a href="locations.php">Store Locations</a></li>
                    <li><a href="faq.php">FAQ</a></li>
                </ul>
            </div>
            <div class="sitemap-col">
                <h3>User Account</h3>
                <ul>
                    <li><a href="login.php">Sign In / Register</a></li>
                    <li><a href="my_account.php">My Profile</a></li>
                    <li><a href="my_orders.php">My Orders</a></li>
                    <li><a href="franchise_application.php">Business Application</a></li>
                    <li><a href="cart.php">View Cart</a></li>
                    <li><a href="checkout.php">Checkout</a></li>
                </ul>
            </div>
            <div class="sitemap-col">
                <h3>Legal & Information</h3>
                <ul>
                    <li><a href="privacy_policy.php" data-policy-modal="privacy">Privacy Policy</a></li>
                    <li><a href="terms_of_service.php" data-policy-modal="terms">Terms of Service</a></li>
                    <li><a href="sitemap.php">Sitemap</a></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
