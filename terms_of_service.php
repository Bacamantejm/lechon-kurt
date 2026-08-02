<?php
$current_page = 'terms_of_service';
$page_title = "Terms of Service | Lechon Delights";
include 'includes/header.php';
require_once __DIR__ . '/includes/legal_policy_content.php';
?>

<section class="policy-page">
    <div class="container">
        <h1>Terms of Service</h1>
        <p>Last updated: <?php echo htmlspecialchars(legalPoliciesLastUpdatedLabel()); ?></p>
        <?php renderTermsOfServiceSections(); ?>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
