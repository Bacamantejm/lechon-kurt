<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();

if (!isset($_GET['id'])) {
    die("Invalid leave request");
}

$leave_id = intval($_GET['id']);
if (hrIsPartnerScopeEnabled($conn) && !hrRecordIdInEmployeeScope($conn, 'leave_requests', $leave_id, 'id', 'employee_id')) {
    die("Leave request not found");
}

$query = "SELECT lr.*, e.first_name, e.last_name, u.full_name as reviewer_name
          FROM leave_requests lr
          JOIN employees e ON lr.employee_id = e.id
          LEFT JOIN users u ON lr.reviewed_by = u.id
          WHERE lr.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $leave_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$leave = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$leave) {
    die("Leave request not found");
}
?>

<div class="leave-details">
    <div class="leave-header">
        <h5><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></h5>
        <span class="status-badge badge-<?php echo $leave['status']; ?>"><?php echo ucfirst($leave['status']); ?></span>
    </div>
    
    <div class="leave-info">
        <div class="info-row">
            <label>Leave Type:</label>
            <span><?php echo ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?></span>
        </div>
        <div class="info-row">
            <label>Start Date:</label>
            <span><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></span>
        </div>
        <div class="info-row">
            <label>End Date:</label>
            <span><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></span>
        </div>
        <div class="info-row">
            <label>Duration:</label>
            <span><?php echo (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / (24*60*60) + 1; ?> days</span>
        </div>
        <div class="info-row">
            <label>Reason:</label>
            <span><?php echo htmlspecialchars($leave['reason']); ?></span>
        </div>
        <?php if ($leave['status'] !== 'pending'): ?>
        <div class="info-row">
            <label>Reviewed By:</label>
            <span><?php echo htmlspecialchars(isset($leave['reviewer_name']) ? $leave['reviewer_name'] : 'N/A'); ?></span>
        </div>
        <div class="info-row">
            <label>Review Date:</label>
            <span><?php echo $leave['reviewed_at'] ? date('M d, Y', strtotime($leave['reviewed_at'])) : 'N/A'; ?></span>
        </div>
        <?php if ($leave['review_notes']): ?>
        <div class="info-row">
            <label>Review Notes:</label>
            <span><?php echo htmlspecialchars($leave['review_notes']); ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.leave-details {
    padding: 10px 0;
}
.leave-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #eee;
}
.leave-header h5 {
    margin: 0;
    font-size: 16px;
}
.leave-info {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.info-row {
    display: grid;
    grid-template-columns: 100px 1fr;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}
.info-row label {
    font-weight: 600;
    font-size: 12px;
    color: #999;
}
.info-row span {
    font-size: 14px;
    color: #333;
}
</style>
