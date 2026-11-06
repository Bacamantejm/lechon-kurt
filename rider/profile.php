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
        $photo_updated = false;

        // Handle profile photo upload
        if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
            $file_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_exts, true)) {
                $save_err = 'Invalid photo format. Please upload JPG, PNG, or WebP.';
            } elseif ($_FILES['profile_photo']['size'] > (5 * 1024 * 1024)) {
                $save_err = 'Photo size exceeds the 5MB limit.';
            } else {
                $target_dir = dirname(__DIR__) . '/uploads/profile_pictures';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0777, true);
                }
                $photo_filename = 'rider_' . $rider_pk . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                $target_dest = $target_dir . '/' . $photo_filename;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_dest)) {
                    $rel_path = 'uploads/profile_pictures/' . $photo_filename;

                    // 1. Update user profile image in users table
                    $stmt_u = mysqli_prepare($conn, "UPDATE users SET profile_image = ? WHERE id = ?");
                    if ($stmt_u) {
                        mysqli_stmt_bind_param($stmt_u, "si", $rel_path, $user_pk);
                        mysqli_stmt_execute($stmt_u);
                        mysqli_stmt_close($stmt_u);
                    }

                    // 2. Synchronize to linked employee cards in employees table
                    $emp_ids = [];
                    $q1 = mysqli_query($conn, "SELECT id FROM employees WHERE user_id = " . (int)$user_pk);
                    if ($q1) {
                        while ($r1 = mysqli_fetch_assoc($q1)) { $emp_ids[] = (int)$r1['id']; }
                    }
                    $q2 = mysqli_query($conn, "SELECT employee_id FROM riders WHERE user_id = " . (int)$user_pk . " AND employee_id IS NOT NULL AND employee_id > 0");
                    if ($q2) {
                        while ($r2 = mysqli_fetch_assoc($q2)) { $emp_ids[] = (int)$r2['employee_id']; }
                    }
                    $q3 = mysqli_query($conn, "SELECT id FROM employees WHERE email = (SELECT email FROM users WHERE id = " . (int)$user_pk . " LIMIT 1)");
                    if ($q3) {
                        while ($r3 = mysqli_fetch_assoc($q3)) { $emp_ids[] = (int)$r3['id']; }
                    }
                    $emp_ids = array_unique(array_filter($emp_ids));
                    if (!empty($emp_ids)) {
                        $id_list = implode(',', $emp_ids);
                        mysqli_query($conn, "UPDATE employees SET photo = '" . mysqli_real_escape_string($conn, $rel_path) . "' WHERE id IN ($id_list)");
                    }

                    // 3. Update session for instant header/navigation reflection
                    $_SESSION['profile_image'] = $rel_path;
                    $_SESSION['rider_profile_image'] = $rel_path;
                    $photo_updated = true;
                } else {
                    $save_err = 'Failed to upload photo. Please check folder permissions.';
                }
            }
        }

        if (empty($save_err)) {
            // Update user phone
            $stmt_ph = mysqli_prepare($conn, "UPDATE users SET phone = ? WHERE id = ?");
            if ($stmt_ph) {
                mysqli_stmt_bind_param($stmt_ph, "si", $phone, $user_pk);
                mysqli_stmt_execute($stmt_ph);
                mysqli_stmt_close($stmt_ph);
            }

            // Update rider vehicle plate and vehicle type
            $stmt_r = mysqli_prepare($conn, "UPDATE riders SET vehicle_plate = ?, vehicle_type = ? WHERE id = ?");
            if ($stmt_r) {
                mysqli_stmt_bind_param($stmt_r, "ssi", $vehicle_plate, $vehicle_type, $rider_pk);
                mysqli_stmt_execute($stmt_r);
                mysqli_stmt_close($stmt_r);
            }

            $save_msg = $photo_updated ? 'Profile and photo updated successfully! Synced with admin employee card.' : 'Profile updated successfully.';
            $rider = checkRiderAccess(); // Refresh data
        }
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
        <div class="alert alert-success py-2 px-3 small mb-3"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($save_msg); ?></div>
    <?php endif; ?>
    <?php if ($save_err): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3"><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($save_err); ?></div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <!-- Left Column: Profile Card & Duty Status -->
        <div class="col-12 col-lg-5">
            <!-- Profile Header Card (Section 17) -->
            <div class="rider-card text-center mb-3">
                <?php 
                    $profile_name = !empty($rider['rider_name']) ? $rider['rider_name'] : (!empty($_SESSION['rider_name']) ? $_SESSION['rider_name'] : 'Rider');
                ?>
                <div style="position: relative; display: inline-block; margin-bottom: 12px;">
                    <?php if (!empty($rider['profile_image'])): ?>
                        <img id="riderAvatarPreview" src="../<?php echo htmlspecialchars($rider['profile_image']); ?>" alt="Profile" style="width: 86px; height: 86px; border-radius: 50%; object-fit: cover; border: 3px solid #d0d5dd;">
                        <div id="riderAvatarInitial" style="display:none; width: 86px; height: 86px; border-radius: 50%; background: #fee4e2; color: var(--primary-red); align-items:center; justify-content:center; font-size: 32px; font-weight: 800; margin: 0 auto;">
                            <?php echo strtoupper(substr($profile_name, 0, 1)); ?>
                        </div>
                    <?php else: ?>
                        <div id="riderAvatarInitial" style="width: 86px; height: 86px; border-radius: 50%; background: #fee4e2; color: var(--primary-red); display:flex; align-items:center; justify-content:center; font-size: 32px; font-weight: 800; margin: 0 auto;">
                            <?php echo strtoupper(substr($profile_name, 0, 1)); ?>
                        </div>
                        <img id="riderAvatarPreview" src="" alt="Profile" style="display:none; width: 86px; height: 86px; border-radius: 50%; object-fit: cover; border: 3px solid #d0d5dd;">
                    <?php endif; ?>
                    <label for="rider_photo_input" title="Upload New Profile Photo" style="position: absolute; bottom: 0; right: 0; width: 28px; height: 28px; border-radius: 50%; background: #b3261e; color: #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid #ffffff; box-shadow: 0 2px 6px rgba(0,0,0,0.2); font-size: 12px; z-index: 10;">
                        <i class="fas fa-camera"></i>
                    </label>
                    <span class="pulse-dot <?php echo htmlspecialchars($rider['duty_status']); ?>" style="position: absolute; bottom: 4px; left: 4px; border: 2px solid #ffffff;"></span>
                </div>

                <h5 class="fw-bold mb-1" style="font-size: 1.25rem;"><?php echo htmlspecialchars($profile_name); ?></h5>
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
                    <a href="support.php" class="rider-glass-pill" style="color: var(--primary-red); font-size: 11.5px; padding: 6px 14px;">
                        <i class="fas fa-headset me-1"></i> Support
                    </a>
                </div>
            </div>
        </div>

        <!-- Right Column: Personal & Vehicle Details Form -->
        <div class="col-12 col-lg-7">
            <!-- Edit Profile Form Card -->
            <div class="rider-card mb-3">
                <h6 class="fw-bold mb-3 text-dark" style="font-size: 13.5px;"><i class="fas fa-id-card text-danger me-1"></i> Personal & Vehicle Details</h6>
                <form method="POST" action="profile.php" enctype="multipart/form-data">
                    <!-- Profile Photo File Upload -->
                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark"><i class="fas fa-camera text-danger me-1"></i> Profile Photo</label>
                        <input type="file" id="rider_photo_input" name="profile_photo" class="form-control form-control-sm rider-glass-input" accept="image/jpeg,image/png,image/webp" onchange="previewRiderPhoto(this)">
                        <div class="form-text" style="font-size: 11px; color: #667085;">
                            Upload a clear photo (JPG, PNG, or WebP, max 5MB). Photo will automatically reflect on your employee HR card in admin.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-muted">Email Address (Read-only)</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($rider['email'] ?? ''); ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark">Mobile Contact Number</label>
                        <input type="text" name="phone" class="form-control form-control-sm rider-glass-input" value="<?php echo htmlspecialchars($rider['user_phone'] ?? ($rider['phone'] ?? '')); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1 fw-semibold text-dark">Vehicle Type</label>
                        <input type="text" name="vehicle_type" class="form-control form-control-sm rider-glass-input" value="<?php echo htmlspecialchars($rider['vehicle_type'] ?? 'Motorcycle'); ?>" placeholder="e.g. Motorcycle, Scooter, E-Bike">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small mb-1 fw-semibold text-dark">Plate Number / Registration</label>
                        <input type="text" name="vehicle_plate" class="form-control form-control-sm rider-glass-input" value="<?php echo htmlspecialchars($rider['vehicle_plate'] ?? ''); ?>" placeholder="e.g. ABC 1234">
                    </div>

                    <button type="submit" class="btn-rider-primary py-2" style="font-size: 13px;">
                        <i class="fas fa-save me-1"></i> Save Profile Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewRiderPhoto(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('riderAvatarPreview');
            const initial = document.getElementById('riderAvatarInitial');
            if (preview) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            if (initial) {
                initial.style.display = 'none';
            }
        };
        reader.readAsDataURL(file);
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
