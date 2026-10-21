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
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-user"></i> Employee & Status</span>
            <?php 
                $st = strtolower($leave['status'] ?? 'pending');
                $pill_class = $st === 'approved' ? 'form-req-pill' : ($st === 'rejected' ? 'form-req-pill' : 'form-opt-pill');
            ?>
            <span class="<?php echo $pill_class; ?>"><?php echo ucfirst($st); ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 mb-2">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #fff1f0; color: #b3261e; display: flex; align-items: center; justify-content: center; font-size: 20px; border: 1px solid #fee4e2;">
                <i class="fas fa-user-clock"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0" style="color: #101828; font-size: 15px;"><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></h6>
                <span class="text-muted small">Type: <strong><?php echo ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?></strong></span>
            </div>
        </div>
    </div>
    
    <div class="form-section-card mb-3">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-calendar-alt"></i> Leave Schedule</span>
            <span class="form-req-pill">Duration</span>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-4">
                <div class="p-2 rounded bg-light border text-center">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">START DATE</span>
                    <strong><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></strong>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded bg-light border text-center">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">END DATE</span>
                    <strong><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></strong>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded bg-light border text-center">
                    <span class="d-block text-muted" style="font-size: 11px; font-weight: 600;">TOTAL DAYS</span>
                    <strong style="color: #b3261e;"><?php echo (strtotime($leave['end_date']) - strtotime($leave['start_date'])) / (24*60*60) + 1; ?> days</strong>
                </div>
            </div>
        </div>
        <div class="mt-2">
            <span class="d-block text-muted small fw-bold">REQUEST REASON</span>
            <p class="mb-0 text-dark" style="font-size: 13.5px;"><?php echo htmlspecialchars($leave['reason']); ?></p>
        </div>
    </div>
    
    <?php if ($leave['status'] !== 'pending'): ?>
    <div class="form-section-card">
        <div class="form-section-head">
            <span class="form-section-title"><i class="fas fa-clipboard-check"></i> Review Decision</span>
            <span class="form-opt-pill">Recorded</span>
        </div>
        <div class="mb-1"><span class="text-muted small">REVIEWED BY:</span> <strong><?php echo htmlspecialchars(isset($leave['reviewer_name']) ? $leave['reviewer_name'] : 'N/A'); ?></strong></div>
        <div class="mb-2"><span class="text-muted small">DATE:</span> <strong><?php echo $leave['reviewed_at'] ? date('M d, Y', strtotime($leave['reviewed_at'])) : 'N/A'; ?></strong></div>
        <?php if (!empty($leave['review_notes'])): ?>
            <div class="p-2 rounded bg-light border small text-dark"><i class="fas fa-comment-dots text-muted me-1"></i> <?php echo htmlspecialchars($leave['review_notes']); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
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
