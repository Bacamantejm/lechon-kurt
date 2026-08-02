<?php
$current_page = 'privacy_policy';
$page_title = "Privacy Policy | Lechon Delights";
include 'includes/header.php';
require_once __DIR__ . '/includes/legal_policy_content.php';
?>

<section class="policy-page">
    <div class="container">
        <h1>Privacy Policy</h1>
        <p>Last updated: <?php echo htmlspecialchars(legalPoliciesLastUpdatedLabel()); ?></p>
        <?php renderPrivacyPolicySections(); ?>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
