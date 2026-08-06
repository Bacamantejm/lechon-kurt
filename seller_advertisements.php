<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partner_advertisement_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_stmt = mysqli_prepare($conn, "SELECT id, account_type, business_name FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($user_stmt, "i", $user_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);
$user_info = $user_result ? mysqli_fetch_assoc($user_result) : null;
mysqli_stmt_close($user_stmt);

$has_seller_access = $user_info && (($user_info['account_type'] ?? '') === 'organization' || ($user_info['account_type'] ?? '') === 'seller');

if (!$has_seller_access) {
    header('Location: my_account.php');
    exit;
}

paEnsureAdvertisementSchema($conn);

$success_msg = '';
$error_msg = '';

// Handle Ad Creation / Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ad') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $promo_code = strtoupper(trim($_POST['promo_code'] ?? ''));
        $discount_tag = trim($_POST['discount_tag'] ?? '');
        $bg_theme = trim($_POST['bg_theme'] ?? 'gradient-red');
        $target_url = trim($_POST['target_url'] ?? '');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $banner_image = '';

        if (empty($target_url)) {
            $target_url = 'menu.php?seller_id=' . $user_id;
        }

        // Handle Image Upload if provided
        if (isset($_FILES['banner_image_file']) && $_FILES['banner_image_file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['banner_image_file']['tmp_name'];
            $file_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['banner_image_file']['name']));
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $target_dir = __DIR__ . '/uploads/ads/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                $new_file_name = 'ad_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($tmp_name, $target_dir . $new_file_name)) {
                    $banner_image = 'uploads/ads/' . $new_file_name;
                }
            }
        }

        if ($title === '') {
            $error_msg = 'Advertisement title is required.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO partner_advertisements (seller_id, title, subtitle, promo_code, discount_tag, banner_image, target_url, bg_theme, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "isssssssss", $user_id, $title, $subtitle, $promo_code, $discount_tag, $banner_image, $target_url, $bg_theme, $start_date, $end_date);
                if (mysqli_stmt_execute($stmt)) {
                    $success_msg = 'Partner promotion posted successfully!';
                } else {
                    $error_msg = 'Failed to post promotion: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            }
        }
    } elseif ($action === 'toggle_status') {
        $ad_id = (int)($_POST['ad_id'] ?? 0);
        $new_status = ($_POST['current_status'] ?? '') === 'active' ? 'inactive' : 'active';
        
        $stmt = mysqli_prepare($conn, "UPDATE partner_advertisements SET status = ? WHERE id = ? AND seller_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sii", $new_status, $ad_id, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success_msg = 'Advertisement status updated.';
        }
    } elseif ($action === 'delete_ad') {
        $ad_id = (int)($_POST['ad_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM partner_advertisements WHERE id = ? AND seller_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $ad_id, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success_msg = 'Advertisement removed.';
        }
    }
}

$my_ads = paGetSellerAdvertisements($conn, $user_id);
$page_title = 'Store Advertisements & Promos';
include 'includes/header.php';
?>

<style>
.ad-manager-page {
    padding: 48px 0 80px;
    background: linear-gradient(180deg, #fff9f2 0%, #fff4e8 100%);
    min-height: 85vh;
}
.ad-manager-card {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(23, 25, 34, 0.05);
    margin-bottom: 24px;
}
.ad-manager-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    border-bottom: 1px solid #f3e8de;
    padding-bottom: 16px;
}
.ad-manager-header h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: #171922;
    margin: 0;
}
.ad-manager-header p {
    color: #7b6d64;
    font-size: 0.9rem;
    margin: 4px 0 0 0;
}
.ad-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}
.ad-preview-card {
    border: 1px solid #efddcd;
    border-radius: 16px;
    padding: 20px;
    color: #ffffff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
}
.ad-preview-card.gradient-red { background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%); }
.ad-preview-card.gradient-orange { background: linear-gradient(135deg, #ef6b2e 0%, #ff9e43 100%); }
.ad-preview-card.gradient-dark { background: linear-gradient(135deg, #171922 0%, #343a40 100%); }

.ad-tag {
    display: inline-block;
    background: rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(4px);
    color: #ffffff;
    font-weight: 800;
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
}
.ad-title { font-size: 1.25rem; font-weight: 800; margin: 0 0 6px 0; color: #fff; }
.ad-desc { font-size: 0.88rem; opacity: 0.92; margin: 0 0 14px 0; line-height: 1.4; color: #fff; }
.ad-code-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    color: #171922;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 0.82rem;
    font-family: monospace;
}
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 700; font-size: 0.88rem; color: #171922; margin-bottom: 6px; }
.form-control {
    width: 100%;
    border: 1px solid #efddcd;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.92rem;
    background: #ffffff;
    color: #171922;
}
.form-control:focus { outline: none; border-color: #b3261e; box-shadow: 0 0 0 3px rgba(179,38,30,0.12); }
</style>

<section class="ad-manager-page">
    <div class="container" style="max-width: 1180px; margin: 0 auto; padding: 0 16px;">
        
        <?php if ($success_msg): ?>
            <div style="background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:700;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div style="background:#ffebee; color:#c62828; border:1px solid #ffcdd2; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:700;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="ad-manager-header">
            <div>
                <h1><i class="fas fa-bullhorn" style="color:#ef6b2e;"></i> Store Advertisements & Promos</h1>
                <p>Post special deals, discount vouchers, and featured banners to attract more customers to your store.</p>
            </div>
            <button class="market-btn" onclick="document.getElementById('createAdForm').scrollIntoView({behavior: 'smooth'})" style="background:linear-gradient(135deg, #b3261e, #ef6b2e); color:#fff; border:0; padding:10px 20px; border-radius:12px; font-weight:700; cursor:pointer;">
                <i class="fas fa-plus"></i> Post New Promo
            </button>
        </div>

        <!-- Current Promos Grid -->
        <h2 style="font-size: 1.25rem; font-weight: 800; color: #171922; margin-bottom: 16px;">Your Active Banners & Offers</h2>
        <?php if (empty($my_ads)): ?>
            <div class="ad-manager-card" style="text-align: center; padding: 40px 20px;">
                <div style="width:60px; height:60px; border-radius:50%; background:#fff4e8; color:#ef6b2e; display:inline-flex; align-items:center; justify-content:center; font-size:1.6rem; margin-bottom:12px;">
                    <i class="fas fa-tag"></i>
                </div>
                <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; color: #171922;">No Promotions Posted Yet</h3>
                <p style="color: #7b6d64; margin: 0 0 16px 0; font-size: 0.9rem;">Post your first promo banner below to feature your store deals on the marketplace homepage!</p>
            </div>
        <?php else: ?>
            <div class="ad-grid" style="margin-bottom: 36px;">
                <?php foreach ($my_ads as $ad): ?>
                    <div class="ad-preview-card <?php echo htmlspecialchars($ad['bg_theme']); ?>">
                        <?php if ($ad['discount_tag']): ?>
                            <span class="ad-tag"><?php echo htmlspecialchars($ad['discount_tag']); ?></span>
                        <?php endif; ?>
                        <h3 class="ad-title"><?php echo htmlspecialchars($ad['title']); ?></h3>
                        <p class="ad-desc"><?php echo htmlspecialchars($ad['subtitle'] ?: 'Exclusive promo deal for store customers.'); ?></p>
                        
                        <?php if ($ad['promo_code']): ?>
                            <div class="ad-code-badge">
                                <i class="fas fa-ticket-alt" style="color:#b3261e;"></i> CODE: <?php echo htmlspecialchars($ad['promo_code']); ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">
                                Status: <strong><?php echo strtoupper(htmlspecialchars($ad['status'])); ?></strong>
                            </span>
                            <div style="display: flex; gap: 8px;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="ad_id" value="<?php echo $ad['id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($ad['status']); ?>">
                                    <button type="submit" style="background:rgba(255,255,255,0.25); border:0; color:#fff; padding:6px 12px; border-radius:8px; font-weight:700; font-size:0.78rem; cursor:pointer;">
                                        <?php echo $ad['status'] === 'active' ? 'Pause' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this promotion?');">
                                    <input type="hidden" name="action" value="delete_ad">
                                    <input type="hidden" name="ad_id" value="<?php echo $ad['id']; ?>">
                                    <button type="submit" style="background:rgba(0,0,0,0.3); border:0; color:#ffcdd2; padding:6px 10px; border-radius:8px; font-weight:700; font-size:0.78rem; cursor:pointer;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Create Ad Form -->
        <div id="createAdForm" class="ad-manager-card">
            <h2 style="font-size: 1.3rem; font-weight: 800; color: #171922; margin: 0 0 16px 0;">Post a New Partner Promotion</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_ad">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label>Promo Headline / Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., 20% OFF Whole Lechon Belly" required>
                    </div>
                    <div class="form-group">
                        <label>Discount Tag (Badge)</label>
                        <input type="text" name="discount_tag" class="form-control" placeholder="e.g., 20% OFF or FREE DELIVERY">
                    </div>
                </div>

                <div class="form-group">
                    <label>Subtitle / Promo Details</label>
                    <textarea name="subtitle" class="form-control" rows="2" placeholder="e.g., Enjoy fresh crispy roasted lechon belly with free gravy for orders above ₱1,000."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label>Promo Voucher Code</label>
                        <input type="text" name="promo_code" class="form-control" placeholder="e.g., LECHON20">
                    </div>
                    <div class="form-group">
                        <label>Card Visual Theme</label>
                        <select name="bg_theme" class="form-control">
                            <option value="gradient-red">Primary Red & Orange Gradient</option>
                            <option value="gradient-orange">Warm Brand Orange</option>
                            <option value="gradient-dark">Premium Dark Ink</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Destination Link</label>
                        <input type="text" name="target_url" class="form-control" value="menu.php?seller_id=<?php echo $user_id; ?>" placeholder="menu.php?seller_id=...">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date (Optional)</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="market-btn" style="background:linear-gradient(135deg, #b3261e, #ef6b2e); color:#fff; border:0; padding:12px 28px; border-radius:12px; font-weight:800; font-size:0.95rem; cursor:pointer;">
                        <i class="fas fa-paper-plane"></i> Publish Promo Banner
                    </button>
                </div>
            </form>
        </div>

    </div>
</section>

<?php include 'includes/footer.php'; ?>
