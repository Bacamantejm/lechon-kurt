<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
hrEnsureNormalizedPositionModel($conn);

if (!isset($_GET['id'])) {
    die("Invalid employee");
}

$employee_id = intval($_GET['id']);
if (hrIsPartnerScopeEnabled($conn) && !hrEmployeeIdInScope($conn, $employee_id)) {
    die("Employee not found");
}

$query = "SELECT e.*, d.department_name, u.user_type, u.email as user_email, u.profile_image as user_profile_image,
                 r.name as role_name,
                 COALESCE(jp.position_title, e.position) AS position_label 
          FROM employees e
          LEFT JOIN departments d ON e.department_id = d.id
          LEFT JOIN job_positions jp ON jp.id = e.position_id
          LEFT JOIN users u ON e.user_id = u.id
          LEFT JOIN roles r ON u.role_id = r.id
          WHERE e.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $employee_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$employee = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$employee) {
    die("Employee not found");
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($employee);
    exit;
}

$photo_src = !empty($employee['photo']) ? '../' . ltrim($employee['photo'], '/') : (!empty($employee['user_profile_image']) ? '../' . ltrim($employee['user_profile_image'], '/') : '');
$role_display = !empty($employee['role_name']) ? (function_exists('getRoleDisplayName') ? getRoleDisplayName($employee['role_name']) : ucwords(str_replace('_', ' ', $employee['role_name']))) : 'No Login Account';
$role_display = preg_replace('/\s*\(.*\)\s*/', '', (string)$role_display);
$initials = strtoupper(substr($employee['first_name'] ?? 'E', 0, 1) . substr($employee['last_name'] ?? '', 0, 1));
?>

<div class="employee-details-wrap">
    <!-- Header Avatar Profile Banner -->
    <div class="d-flex align-items-center gap-3 p-3 mb-3 rounded" style="background: #ffffff; border: 1px solid #eaecf0; box-shadow: 0 1px 3px rgba(16,24,40,0.04);">
        <?php if (!empty($photo_src) && file_exists(dirname(__DIR__) . '/' . ltrim($photo_src, '../'))): ?>
            <img src="<?php echo htmlspecialchars($photo_src); ?>" alt="Photo" style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #f2f4f7;">
        <?php else: ?>
            <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #b3261e, #d93829); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; border: 3px solid #f2f4f7;">
                <?php echo htmlspecialchars($initials); ?>
            </div>
        <?php endif; ?>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars(formatEmployeeFullName($employee['first_name'] ?? '', $employee['middle_initial'] ?? '', $employee['last_name'] ?? '', $employee['suffix'] ?? '')); ?></h5>
            <p class="text-muted small mb-1"><?php echo htmlspecialchars($employee['position_label']); ?> &bull; <?php echo htmlspecialchars($employee['department_name'] ?? 'Unassigned'); ?></p>
            <span class="badge" style="background: <?php echo !empty($employee['role_name']) ? '#eff8ff' : '#f8f9fa'; ?>; color: <?php echo !empty($employee['role_name']) ? '#175cd3' : '#667085'; ?>; border: 1px solid <?php echo !empty($employee['role_name']) ? '#b2ddff' : '#eaecf0'; ?>; font-size: 11px; padding: 2px 8px; border-radius: 10px;">
                <i class="fas fa-shield-alt me-1"></i> System Role: <?php echo htmlspecialchars($role_display); ?>
            </span>
        </div>
    </div>

    <!-- Section 1: Identity & Role -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-user-circle text-danger"></i>
                Identity & Department
            </div>
            <span class="badge" style="background: <?php echo $employee['status'] === 'active' ? '#ecfdf3' : '#fff1f0'; ?>; color: <?php echo $employee['status'] === 'active' ? '#027a48' : '#b3261e'; ?>; border: 1px solid <?php echo $employee['status'] === 'active' ? '#abefc6' : '#fee4e2'; ?>; font-weight: 600; font-size: 12px; padding: 4px 10px; border-radius: 20px;">
                <i class="fas fa-circle me-1" style="font-size: 7px;"></i> <?php echo ucfirst(str_replace('_', ' ', $employee['status'])); ?>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Full Name</small>
                    <span class="fw-bold" style="color: #101828; font-size: 14px;"><?php echo htmlspecialchars(formatEmployeeFullName($employee['first_name'] ?? '', $employee['middle_initial'] ?? '', $employee['last_name'] ?? '', $employee['suffix'] ?? '')); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Employee ID</small>
                    <span class="fw-bold" style="color: #101828; font-size: 14px;"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Position / Role</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars($employee['position_label']); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Department</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars(isset($employee['department_name']) ? $employee['department_name'] : 'Unassigned'); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Work Email</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars($employee['email']); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Contact Number</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars(isset($employee['phone']) ? $employee['phone'] : 'Not provided'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Employment & Compensation -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-file-contract text-danger"></i>
                Compensation & Terms
            </div>
            <span class="form-opt-pill"><?php echo ucfirst(str_replace('_', ' ', $employee['employment_type'])); ?></span>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Rate / Salary</small>
                    <span class="fw-bold" style="color: #b3261e; font-size: 14px;">
                        <?php if($employee['employment_basis'] == 'daily'): ?>
                            ₱<?php echo number_format($employee['daily_rate'], 2); ?> <span class="fw-normal text-muted" style="font-size: 12px;">/ day</span>
                        <?php else: ?>
                            ₱<?php echo number_format($employee['salary'], 2); ?> <span class="fw-normal text-muted" style="font-size: 12px;">/ month</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Hire Date</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo date('M d, Y', strtotime($employee['hire_date'])); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Emergency Contact</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars(isset($employee['emergency_contact']) ? $employee['emergency_contact'] : 'Not provided'); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold" style="font-size: 11px; text-transform: uppercase;">Emergency Phone</small>
                    <span class="fw-semibold" style="color: #344054; font-size: 13px;"><?php echo htmlspecialchars(isset($employee['emergency_phone']) ? $employee['emergency_phone'] : 'Not provided'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Statutory Compliance -->
    <div class="form-section-card">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-shield-alt text-danger"></i>
                Government Compliance Records
            </div>
            <span class="form-opt-pill">Statutory</span>
        </div>

        <div class="row g-2 text-center">
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">SSS NUMBER</small>
                    <span class="fw-semibold" style="color: #101828; font-size: 13px;"><?php echo htmlspecialchars($employee['sss_number'] ?? 'N/A'); ?></span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">PHILHEALTH</small>
                    <span class="fw-semibold" style="color: #101828; font-size: 13px;"><?php echo htmlspecialchars($employee['philhealth_number'] ?? 'N/A'); ?></span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">PAG-IBIG</small>
                    <span class="fw-semibold" style="color: #101828; font-size: 13px;"><?php echo htmlspecialchars($employee['pagibig_number'] ?? 'N/A'); ?></span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">TIN</small>
                    <span class="fw-semibold" style="color: #101828; font-size: 13px;"><?php echo htmlspecialchars($employee['tin_number'] ?? 'N/A'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

