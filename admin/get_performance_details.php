<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();

if (!isset($_GET['id'])) {
    die("Invalid performance review");
}

$review_id = intval($_GET['id']);
if (hrIsPartnerScopeEnabled($conn) && !hrRecordIdInEmployeeScope($conn, 'performance_reviews', $review_id, 'id', 'employee_id')) {
    die("Performance review not found");
}

$query = "SELECT pr.*, e.first_name as emp_first, e.last_name as emp_last, u.full_name as reviewer_name
          FROM performance_reviews pr
          JOIN employees e ON pr.employee_id = e.id
          JOIN users u ON pr.reviewer_id = u.id
          WHERE pr.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $review_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$review = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$review) {
    die("Performance review not found");
}

$rating_color = $review['overall_rating'] >= 4 ? 'success' : ($review['overall_rating'] >= 3 ? 'warning' : 'danger');
?>

<div class="performance-details-wrap">
    <!-- Section 1: Overview & Rating -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-user-check text-danger"></i>
                Review Overview
            </div>
            <span class="badge" style="background: <?php echo $review['status'] === 'completed' ? '#ecfdf3' : '#eff8ff'; ?>; color: <?php echo $review['status'] === 'completed' ? '#027a48' : '#175cd3'; ?>; border: 1px solid <?php echo $review['status'] === 'completed' ? '#abefc6' : '#b2ddff'; ?>; font-weight: 600; font-size: 12px; padding: 4px 10px; border-radius: 20px;">
                <i class="fas fa-circle me-1" style="font-size: 7px;"></i> <?php echo ucfirst(str_replace('_', ' ', $review['status'])); ?>
            </span>
        </div>

        <div class="row g-3 align-items-center">
            <div class="col-md-7">
                <div class="p-3 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <div class="fw-bold mb-1" style="color: #101828; font-size: 15px;">
                        <?php echo htmlspecialchars($review['emp_first'] . ' ' . $review['emp_last']); ?>
                    </div>
                    <div class="text-muted" style="font-size: 12px;">
                        <i class="fas fa-calendar-alt text-danger me-1"></i>
                        Period: <?php echo date('M d, Y', strtotime($review['period_start'])); ?> &ndash; <?php echo date('M d, Y', strtotime($review['period_end'])); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="p-3 rounded text-center" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-semibold mb-1" style="font-size: 11px; text-transform: uppercase;">Overall Rating</small>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill" style="background: <?php echo $review['overall_rating'] >= 4 ? '#ecfdf3' : ($review['overall_rating'] >= 3 ? '#fffaeb' : '#fff1f0'); ?>; color: <?php echo $review['overall_rating'] >= 4 ? '#027a48' : ($review['overall_rating'] >= 3 ? '#b54708' : '#b3261e'); ?>; border: 1px solid <?php echo $review['overall_rating'] >= 4 ? '#abefc6' : ($review['overall_rating'] >= 3 ? '#fedf89' : '#fee4e2'); ?>; font-weight: 700; font-size: 15px;">
                        <span>★ <?php echo number_format($review['overall_rating'], 1); ?> / 5.0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Competency Breakdown -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-award text-danger"></i>
                Competency Scores
            </div>
            <span class="form-opt-pill">1 to 5 Stars</span>
        </div>

        <div class="row g-2 text-center">
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">ATTENDANCE</small>
                    <div class="text-warning my-1" style="font-size: 14px;"><?php echo str_repeat('★', $review['attendance_rating']); ?><?php echo str_repeat('☆', 5 - $review['attendance_rating']); ?></div>
                    <span class="fw-bold" style="color: #101828; font-size: 13px;"><?php echo $review['attendance_rating']; ?>/5</span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">PERFORMANCE</small>
                    <div class="text-warning my-1" style="font-size: 14px;"><?php echo str_repeat('★', $review['performance_rating']); ?><?php echo str_repeat('☆', 5 - $review['performance_rating']); ?></div>
                    <span class="fw-bold" style="color: #101828; font-size: 13px;"><?php echo $review['performance_rating']; ?>/5</span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">TEAMWORK</small>
                    <div class="text-warning my-1" style="font-size: 14px;"><?php echo str_repeat('★', $review['teamwork_rating']); ?><?php echo str_repeat('☆', 5 - $review['teamwork_rating']); ?></div>
                    <span class="fw-bold" style="color: #101828; font-size: 13px;"><?php echo $review['teamwork_rating']; ?>/5</span>
                </div>
            </div>
            <div class="col-sm-3 col-6">
                <div class="p-2 rounded" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold" style="font-size: 11px;">COMMUNICATION</small>
                    <div class="text-warning my-1" style="font-size: 14px;"><?php echo str_repeat('★', $review['communication_rating']); ?><?php echo str_repeat('☆', 5 - $review['communication_rating']); ?></div>
                    <span class="fw-bold" style="color: #101828; font-size: 13px;"><?php echo $review['communication_rating']; ?>/5</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Qualitative Feedback -->
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <div class="form-section-title">
                <i class="fas fa-comment-alt text-danger"></i>
                Qualitative Feedback & Goals
            </div>
            <span class="form-opt-pill">Evaluations</span>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 rounded h-100" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold mb-1" style="font-size: 11px; text-transform: uppercase;">Key Strengths</small>
                    <p class="mb-0" style="color: #344054; font-size: 13px;"><?php echo nl2br(htmlspecialchars(isset($review['strengths']) ? $review['strengths'] : 'Not specified')); ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 rounded h-100" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold mb-1" style="font-size: 11px; text-transform: uppercase;">Areas for Improvement</small>
                    <p class="mb-0" style="color: #344054; font-size: 13px;"><?php echo nl2br(htmlspecialchars(isset($review['areas_for_improvement']) ? $review['areas_for_improvement'] : 'Not specified')); ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 rounded h-100" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold mb-1" style="font-size: 11px; text-transform: uppercase;">Goals for Next Period</small>
                    <p class="mb-0" style="color: #344054; font-size: 13px;"><?php echo nl2br(htmlspecialchars(isset($review['goals_for_next_period']) ? $review['goals_for_next_period'] : 'Not specified')); ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 rounded h-100" style="background: #f8f9fa; border: 1px solid #eaecf0;">
                    <small class="text-muted d-block fw-bold mb-1" style="font-size: 11px; text-transform: uppercase;">Comments</small>
                    <p class="mb-0" style="color: #344054; font-size: 13px;"><?php echo nl2br(htmlspecialchars(isset($review['comments']) ? $review['comments'] : 'No additional comments')); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Sign-off & Audit -->
    <div class="form-section-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 text-muted" style="font-size: 12px;">
            <div>
                <i class="fas fa-user-edit text-danger me-1"></i>
                Evaluated by: <strong class="text-dark"><?php echo htmlspecialchars($review['reviewer_name']); ?></strong>
            </div>
            <div>
                <i class="fas fa-clock text-danger me-1"></i>
                Recorded: <strong class="text-dark"><?php echo date('M d, Y h:i A', strtotime($review['created_at'])); ?></strong>
            </div>
        </div>
    </div>
</div>
