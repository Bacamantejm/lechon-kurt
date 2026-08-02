<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();

$position_id = isset($_GET['position_id']) ? intval($_GET['position_id']) : 0;
$status_filter = hrSafeEnum($_GET['status'] ?? '', ['', 'new', 'reviewed', 'interviewed', 'offered', 'hired', 'rejected', 'withdrawn'], '');
$has_candidates = hrTableExists($conn, 'candidates');
$has_job_positions = hrTableExists($conn, 'job_positions');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$position_scope_sql = hrPositionScopeSql($conn, 'jp');

if (!$is_partner_scoped_hr) {
    requirePermission('hr.view');
}

// Handle candidate actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('candidates.php');
    if (!$is_partner_scoped_hr) {
        requirePermission('hr.view');
    }

    if (!$has_candidates) {
        $_SESSION['error'] = "Candidates table is not configured yet.";
        header("Location: candidates.php");
        exit();
    }

    if (isset($_POST['update_candidate_status'])) {
        $candidate_id = intval($_POST['candidate_id']);
        $new_status = hrSafeEnum($_POST['new_status'] ?? '', ['new', 'reviewed', 'interviewed', 'offered', 'hired', 'rejected', 'withdrawn'], 'new');
        $interview_date = trim($_POST['interview_date'] ?? '');
        $interview_date = ($interview_date !== '' && hrIsValidDate($interview_date, 'Y-m-d\TH:i')) ? str_replace('T', ' ', $interview_date) . ':00' : null;
        $interview_notes = trim($_POST['interview_notes'] ?? '');
        $interview_notes = $interview_notes !== '' ? $interview_notes : null;
        
        if ($is_partner_scoped_hr && !hrCandidateIdInScope($conn, $candidate_id)) {
            $_SESSION['error'] = "Selected candidate is outside your partner HR scope.";
            header("Location: candidates.php");
            exit();
        }
        
        $update = "UPDATE candidates SET status = ?, interview_date = ?, interview_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update);
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        mysqli_stmt_bind_param($stmt, "sssii", $new_status, $interview_date, $interview_notes, $user_id, $candidate_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Candidate status updated.";
        } else {
            $_SESSION['error'] = "Unable to update candidate status.";
        }
        mysqli_stmt_close($stmt);
    }

    $redirect_params = [];
    if ($position_id > 0) {
        $redirect_params[] = 'position_id=' . $position_id;
    }
    if ($status_filter !== '') {
        $redirect_params[] = 'status=' . urlencode($status_filter);
    }
    $redirect_url = 'candidates.php' . (!empty($redirect_params) ? ('?' . implode('&', $redirect_params)) : '');
    header("Location: " . $redirect_url);
    exit();
}

$candidates_result = false;
if ($has_candidates && $has_job_positions) {
    $candidates_query = "SELECT c.*, jp.position_title
                        FROM candidates c
                        JOIN job_positions jp ON c.position_id = jp.id
                        WHERE (? = 0 OR c.position_id = ?)
                          AND (? = '' OR c.status = ?)" . ($is_partner_scoped_hr ? " AND {$position_scope_sql}" : "") . "
                        ORDER BY c.created_at DESC";
    $candidates_stmt = mysqli_prepare($conn, $candidates_query);
    mysqli_stmt_bind_param($candidates_stmt, "iiss", $position_id, $position_id, $status_filter, $status_filter);
    mysqli_stmt_execute($candidates_stmt);
    $candidates_result = mysqli_stmt_get_result($candidates_stmt);
    mysqli_stmt_close($candidates_stmt);
}

$positions = false;
if ($has_job_positions) {
    $positions_sql = "SELECT id, position_title FROM job_positions jp";
    if ($is_partner_scoped_hr) {
        $positions_sql .= " WHERE {$position_scope_sql}";
    }
    $positions_sql .= " ORDER BY position_title";
    $positions = mysqli_query($conn, $positions_sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidates - HR Management</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <h1>Candidate Management</h1>
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <?php include 'hr_workspace_nav.php'; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="section-header">
                    <h2>Candidates</h2>
                    <a href="recruitment.php" class="btn btn-secondary"><i class="fas fa-briefcase"></i> Recruitment</a>
                </div>

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label mb-1">Position</label>
                                <select name="position_id" id="positionFilter" class="form-select">
                                    <option value="0">All Positions</option>
                                    <?php if ($positions): ?>
                                        <?php while ($pos = mysqli_fetch_assoc($positions)): ?>
                                            <option value="<?php echo (int)$pos['id']; ?>" <?php echo ((int)$pos['id'] === $position_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pos['position_title']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Status</label>
                                <select name="status" class="form-select">
                                    <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All</option>
                                    <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>New</option>
                                    <option value="reviewed" <?php echo $status_filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                    <option value="interviewed" <?php echo $status_filter === 'interviewed' ? 'selected' : ''; ?>>Interviewed</option>
                                    <option value="offered" <?php echo $status_filter === 'offered' ? 'selected' : ''; ?>>Offered</option>
                                    <option value="hired" <?php echo $status_filter === 'hired' ? 'selected' : ''; ?>>Hired</option>
                                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="withdrawn" <?php echo $status_filter === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                                <a href="candidates.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Position Applied</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Experience</th>
                                <th>Rating</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$has_candidates || !$has_job_positions): ?>
                                <tr><td colspan="8" class="text-center text-muted">Recruitment tables are incomplete. Please create `job_positions` and `candidates` tables.</td></tr>
                            <?php elseif ($candidates_result && mysqli_num_rows($candidates_result) > 0): ?>
                                <?php while ($cand = mysqli_fetch_assoc($candidates_result)): ?>
                                    <?php
                                    $candidate_status = hrSafeEnum($cand['status'], ['new', 'reviewed', 'interviewed', 'offered', 'hired', 'rejected', 'withdrawn'], 'new');
                                    $status_class = 'badge-' . str_replace('_', '-', $candidate_status);
                                    $rating = $cand['rating'] ? str_repeat('&#9733;', intval($cand['rating'])) : 'N/A';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($cand['first_name'] . ' ' . $cand['last_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($cand['position_title']); ?></td>
                                        <td><?php echo htmlspecialchars($cand['email']); ?></td>
                                        <td><?php echo htmlspecialchars($cand['phone']); ?></td>
                                        <td><?php echo (int)$cand['years_experience']; ?> years</td>
                                        <td><span class="badge bg-warning"><?php echo $rating; ?></span></td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $candidate_status)); ?></span></td>
                                        <td>
                                            <button class="btn-icon" data-bs-toggle="modal" data-bs-target="#updateCandidateModal" onclick="editCandidate(<?php echo (int)$cand['id']; ?>, '<?php echo htmlspecialchars($candidate_status, ENT_QUOTES); ?>')" title="Update">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted">No candidates found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Candidate Modal -->
    <div class="modal fade" id="updateCandidateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Candidate Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" id="candidateId" name="candidate_id">
                        
                        <div class="mb-3">
                            <label>Status</label>
                            <select name="new_status" class="form-control" required>
                                <option value="new">New</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="interviewed">Interviewed</option>
                                <option value="offered">Offered</option>
                                <option value="hired">Hired</option>
                                <option value="rejected">Rejected</option>
                                <option value="withdrawn">Withdrawn</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label>Interview Date</label>
                            <input type="datetime-local" name="interview_date" class="form-control" step="60">
                        </div>
                        
                        <div class="mb-3">
                            <label>Interview Notes</label>
                            <textarea name="interview_notes" class="form-control" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_candidate_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        function editCandidate(candidateId, status) {
            document.getElementById('candidateId').value = candidateId;
            document.querySelector('select[name="new_status"]').value = status;
        }
    </script>
</body>
</html>



