<?php
$page_title = 'Rider Profile';
require_once __DIR__ . '/auth.php';

$rider = checkRiderAccess();
$rider_pk = (int)$rider['id'];
$user_pk = (int)$rider['user_id'];

$save_msg = '';
$save_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $vehicle_plate = trim($_POST['vehicle_plate'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? 'Motorcycle');

    if (empty($phone)) {
        $save_err = 'Phone number is required.';
    } else {
        // Update user phone
        mysqli_query($conn, "UPDATE users SET phone = '" . mysqli_real_escape_string($conn, $phone) . "' WHERE id = $user_pk");
        // Update rider vehicle plate and vehicle type
        mysqli_query($conn, "UPDATE riders SET vehicle_plate = '" . mysqli_real_escape_string($conn, $vehicle_plate) . "', vehicle_type = '" . mysqli_real_escape_string($conn, $vehicle_type) . "' WHERE id = $rider_pk");

        $save_msg = 'Profile updated successfully.';
        $rider = checkRiderAccess(); // Refresh
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="px-2 px-md-3 pt-2 pt-md-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--primary-ink);"><i class="fas fa-user-circle text-danger me-2"></i>Rider Profile</h5>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger" style="border-radius: 8px; font-size: 11.5px; font-weight: 600;">
            <i class="fas fa-sign-out-alt me-1"></i> Sign Out
        </a>
    </div>

    <?php if ($save_msg): ?>
        <div class="alert alert-success py-2 px-3 small mb-3"><?php echo htmlspecialchars($save_msg); ?></div>
    <?php endif; ?>
    <?php if ($save_err): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3"><?php echo htmlspecialchars($save_err); ?></div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <!-- Left Column: Profile Card & Duty Status -->
        <div class="col-12 col-lg-5">
            <!-- Profile Header Card (Section 17) -->
            <div class="rider-card text-center mb-3">
                <div style="position: relative; display: inline-block; margin-bottom: 12px;">
                    <?php if (!empty($rider['profile_image'])): ?>
                        <img src="../<?php echo htmlspecialchars($rider['profile_image']); ?>" alt="Profile" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--border-neutral);">
                    <?php else: ?>
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: #fee4e2; color: var(--primary-red); display:flex; align-items:center; justify-content:center; font-size: 32px; font-weight: 800; margin: 0 auto;">
                            <?php echo strtoupper(substr($rider['rider_name'] ?? 'R', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <span class="pulse-dot <?php echo htmlspecialchars($rider['duty_status']); ?>" style="position: absolute; bottom: 4px; right: 4px; border: 2px solid #ffffff;"></span>
                </div>

                <h5 class="fw-bold mb-1" style="font-size: 1.25rem;"><?php echo htmlspecialchars($rider['rider_name'] ?? 'Rider'); ?></h5>
                <div class="d-flex justify-content-center align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-weight:700;">
                        <?php echo htmlspecialchars($rider['rider_code']); ?>
                    </span>
                    <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-weight:700;">
                        <i class="fas fa-check-circle me-1"></i> Verified Rider
                    </span>
                    <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-weight:700;">
                        <i class="fas fa-star text-warning me-1"></i> <?php echo number_format((float)($rider['rating'] ?? 5.0), 1); ?>
                    </span>
                </div>

                <div class="text-muted small">
                    <?php echo ($rider['rider_type'] === 'shop_rider') ? 'Assigned to ' . htmlspecialchars($rider['store_name'] ?? 'In-house Branch') : 'Platform / Partner On-Demand Fleet'; ?>
                </div>
            </div>

            <!-- Support & Quick Help Shortcut -->
            <div class="rider-card p-3 mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="mb-0 fw-bold" style="font-size: 13px;"><i class="fas fa-headset text-danger me-1"></i> Need Road Support?</h6>
                        <div class="text-muted" style="font-size: 11.5px;">Emergencies, breakdowns, or delays.</div>
                    </div>
                    <a href="support.php" class="btn btn-sm btn-outline-danger p-2 px-3 fw-bold" style="border-radius: 8px; font-size: 12px;">
                        Support &rarr;
                    </a>
                </div>
            </div>
        </div>

        <!-- Right Column: Personal & Vehicle Details Form -->
        <div class="col-12 col-lg-7">
            <!-- Edit Profile Form Card -->
            <div class="rider-card mb-3">
                <h6 class="fw-bold mb-3 text-dark" style="font-size: 13.5px;"><i class="fas fa-id-card text-danger me-1"></i> Personal & Vehicle Details</h6>
                <form method="POST" action="profile.php">
                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-muted">Email Address (Read-only)</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($rider['email'] ?? ''); ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark">Mobile Contact Number</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?php echo htmlspecialchars($rider['user_phone'] ?? ($rider['phone'] ?? '')); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark">Vehicle Type</label>
                        <input type="text" name="vehicle_type" class="form-control form-control-sm" value="<?php echo htmlspecialchars($rider['vehicle_type'] ?? 'Motorcycle'); ?>" placeholder="e.g. Motorcycle, Scooter, E-Bike">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small mb-1 fw-semibold text-dark">Plate Number / Registration</label>
                        <input type="text" name="vehicle_plate" class="form-control form-control-sm" value="<?php echo htmlspecialchars($rider['vehicle_plate'] ?? ''); ?>" placeholder="e.g. ABC 1234">
                    </div>

                    <button type="submit" class="btn-rider-primary py-2" style="font-size: 13px;">
                        <i class="fas fa-save me-1"></i> Save Profile Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
