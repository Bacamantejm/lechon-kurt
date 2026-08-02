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

<div class="performance-details">
    <div class="review-header">
        <h5><?php echo htmlspecialchars($review['emp_first'] . ' ' . $review['emp_last']); ?></h5>
        <span class="status-badge badge-<?php echo $review['status']; ?>"><?php echo ucfirst($review['status']); ?></span>
    </div>
    
    <div class="review-period">
        <strong>Review Period:</strong> <?php echo date('M d, Y', strtotime($review['period_start'])); ?> - <?php echo date('M d, Y', strtotime($review['period_end'])); ?>
    </div>
    
    <div class="ratings-grid">
        <div class="rating-item">
            <label>Attendance</label>
            <div class="rating-stars"><?php echo str_repeat('★', $review['attendance_rating']); ?><?php echo str_repeat('☆', 5 - $review['attendance_rating']); ?></div>
            <span class="rating-score"><?php echo $review['attendance_rating']; ?>/5</span>
        </div>
        <div class="rating-item">
            <label>Performance</label>
            <div class="rating-stars"><?php echo str_repeat('★', $review['performance_rating']); ?><?php echo str_repeat('☆', 5 - $review['performance_rating']); ?></div>
            <span class="rating-score"><?php echo $review['performance_rating']; ?>/5</span>
        </div>
        <div class="rating-item">
            <label>Teamwork</label>
            <div class="rating-stars"><?php echo str_repeat('★', $review['teamwork_rating']); ?><?php echo str_repeat('☆', 5 - $review['teamwork_rating']); ?></div>
            <span class="rating-score"><?php echo $review['teamwork_rating']; ?>/5</span>
        </div>
        <div class="rating-item">
            <label>Communication</label>
            <div class="rating-stars"><?php echo str_repeat('★', $review['communication_rating']); ?><?php echo str_repeat('☆', 5 - $review['communication_rating']); ?></div>
            <span class="rating-score"><?php echo $review['communication_rating']; ?>/5</span>
        </div>
    </div>
    
    <div class="overall-rating">
        <strong>Overall Rating:</strong>
        <span class="badge bg-<?php echo $rating_color; ?>"><?php echo str_repeat('★', $review['overall_rating']); ?> <?php echo $review['overall_rating']; ?>/5</span>
    </div>
    
    <div class="review-sections">
        <div class="review-section">
            <h6>Strengths</h6>
            <p><?php echo nl2br(htmlspecialchars(isset($review['strengths']) ? $review['strengths'] : 'Not specified')); ?></p>
        </div>
        <div class="review-section">
            <h6>Areas for Improvement</h6>
            <p><?php echo nl2br(htmlspecialchars(isset($review['areas_for_improvement']) ? $review['areas_for_improvement'] : 'Not specified')); ?></p>
        </div>
        <div class="review-section">
            <h6>Goals for Next Period</h6>
            <p><?php echo nl2br(htmlspecialchars(isset($review['goals_for_next_period']) ? $review['goals_for_next_period'] : 'Not specified')); ?></p>
        </div>
        <div class="review-section">
            <h6>Comments</h6>
            <p><?php echo nl2br(htmlspecialchars(isset($review['comments']) ? $review['comments'] : 'No additional comments')); ?></p>
        </div>
    </div>
    
    <div class="review-footer">
        <strong>Reviewer:</strong> <?php echo htmlspecialchars($review['reviewer_name']); ?><br>
        <strong>Date:</strong> <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
    </div>
</div>

<style>
.performance-details {
    padding: 10px 0;
    font-size: 13px;
}
.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #eee;
}
.review-header h5 {
    margin: 0;
    font-size: 16px;
}
.review-period {
    font-size: 12px;
    color: #666;
    margin-bottom: 15px;
}
.ratings-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin: 15px 0;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}
.rating-item {
    text-align: center;
}
.rating-item label {
    display: block;
    font-weight: 600;
    font-size: 12px;
    margin-bottom: 5px;
    color: #333;
}
.rating-stars {
    display: block;
    font-size: 18px;
    color: #ffc107;
    margin-bottom: 3px;
}
.rating-score {
    display: block;
    font-size: 12px;
    color: #999;
}
.overall-rating {
    text-align: center;
    padding: 12px;
    background: #e3f2fd;
    border-radius: 4px;
    margin: 15px 0;
}
.overall-rating strong {
    display: block;
    margin-bottom: 5px;
}
.review-sections {
    margin: 15px 0;
}
.review-section {
    margin-bottom: 12px;
    padding: 10px;
    background: #f5f5f5;
    border-radius: 4px;
}
.review-section h6 {
    margin: 0 0 8px 0;
    font-size: 12px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
}
.review-section p {
    margin: 0;
    font-size: 13px;
    color: #333;
    line-height: 1.5;
}
.review-footer {
    padding-top: 10px;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #999;
}
</style>
