<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$csrf_token = hrEnsureCsrfToken();
$has_job_positions = hrTableExists($conn, 'job_positions');
$has_candidates_table = hrTableExists($conn, 'candidates');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$position_scope_sql = hrPositionScopeSql($conn, 'jp');
$department_scope_sql = hrDepartmentScopeSql($conn, 'd', 'e_scope');

if (!$is_partner_scoped_hr) {
    requirePermission('hr.view');
}

// Handle job position actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('recruitment.php');
    if (!$is_partner_scoped_hr) {
        requirePermission('hr.view');
    }

    if (!$has_job_positions) {
        $_SESSION['error'] = "Recruitment tables are not configured yet.";
        header("Location: recruitment.php");
        exit();
    }

    if (isset($_POST['add_position'])) {
        $position_title = trim($_POST['position_title'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $department_id = $department_id > 0 ? $department_id : null;
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $salary_min = $_POST['salary_min'] ? floatval($_POST['salary_min']) : null;
        $salary_max = $_POST['salary_max'] ? floatval($_POST['salary_max']) : null;
        $employment_type = hrSafeEnum($_POST['employment_type'] ?? '', ['full_time', 'part_time', 'contract', 'temporary'], '');
        $closing_date = trim($_POST['closing_date'] ?? '');
        $closing_date = ($closing_date !== '' && hrIsValidDate($closing_date)) ? $closing_date : null;

        if ($position_title === '' || $employment_type === '' || ($salary_min !== null && $salary_max !== null && $salary_min > $salary_max)) {
            $_SESSION['error'] = "Please provide valid job position details.";
            header("Location: recruitment.php");
            exit();
        }
        if ($is_partner_scoped_hr && $department_id !== null && !hrDepartmentIdInScope($conn, (int)$department_id)) {
            $_SESSION['error'] = "Selected department is outside your partner HR scope.";
            header("Location: recruitment.php");
            exit();
        }
        
        $insert = "INSERT INTO job_positions (position_title, department_id, description, requirements, salary_range_min, salary_range_max, employment_type, status, posted_date, closing_date, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'open', CURDATE(), ?, ?)";
        $stmt = mysqli_prepare($conn, $insert);
        $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        mysqli_stmt_bind_param($stmt, "sissddssi", $position_title, $department_id, $description, $requirements, $salary_min, $salary_max, $employment_type, $closing_date, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Job position posted successfully.";
        } else {
            $_SESSION['error'] = "Unable to post job position.";
        }
        mysqli_stmt_close($stmt);
    }
    
    if (isset($_POST['update_position_status'])) {
        $position_id = intval($_POST['position_id']);
        $new_status = hrSafeEnum($_POST['new_status'] ?? '', ['open', 'closed', 'on_hold'], 'open');
        if ($is_partner_scoped_hr && !hrPositionIdInScope($conn, $position_id)) {
            $_SESSION['error'] = "Selected position is outside your partner HR scope.";
            header("Location: recruitment.php");
            exit();
        }
        
        $update = "UPDATE job_positions SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $position_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['success'] = "Position status updated.";
    }

    header("Location: recruitment.php");
    exit();
}

$status_filter = hrSafeEnum($_GET['status'] ?? '', ['', 'open', 'closed', 'on_hold'], '');
$positions_result = false;
if ($has_job_positions) {
    $positions_query = $has_candidates_table
        ? "SELECT jp.*, d.department_name, COUNT(c.id) AS candidate_count
           FROM job_positions jp
           LEFT JOIN departments d ON jp.department_id = d.id
           LEFT JOIN candidates c ON jp.id = c.position_id
           WHERE (? = '' OR jp.status = ?)" . ($is_partner_scoped_hr ? " AND {$position_scope_sql}" : "") . "
           GROUP BY jp.id
           ORDER BY jp.posted_date DESC"
        : "SELECT jp.*, d.department_name, 0 AS candidate_count
           FROM job_positions jp
           LEFT JOIN departments d ON jp.department_id = d.id
           WHERE (? = '' OR jp.status = ?)" . ($is_partner_scoped_hr ? " AND {$position_scope_sql}" : "") . "
           ORDER BY jp.posted_date DESC";
    $positions_stmt = mysqli_prepare($conn, $positions_query);
    mysqli_stmt_bind_param($positions_stmt, "ss", $status_filter, $status_filter);
    mysqli_stmt_execute($positions_stmt);
    $positions_result = mysqli_stmt_get_result($positions_stmt);
    mysqli_stmt_close($positions_stmt);
}

$departments_sql = "SELECT id, department_name FROM departments d";
if ($is_partner_scoped_hr) {
    $departments_sql .= " WHERE {$department_scope_sql}";
}
$departments_sql .= " ORDER BY department_name";
$departments = mysqli_query($conn, $departments_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recruitment - HR Management</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="hr_theme.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="hr-theme">
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Recruitment Management</h1>
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
                <?php if (!$has_candidates_table): ?>
                    <div class="alert alert-warning">
                        <strong>Notice:</strong> `candidates` table is missing. Candidate counts are shown as 0 until the table is created.
                    </div>
                <?php endif; ?>

                <div class="section-header">
                    <h2>Job Positions</h2>
                    <div class="d-flex gap-2">
                        <a href="candidates.php" class="btn btn-secondary"><i class="fas fa-user-tie"></i> Candidates</a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal" <?php echo !$has_job_positions ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus"></i> Post New Position
                        </button>
                    </div>
                </div>

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label mb-1">Position Status</label>
                                <select name="status" class="form-select">
                                    <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="on_hold" <?php echo $status_filter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                    <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex gap-2 justify-content-md-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                                <a href="recruitment.php" class="btn btn-secondary">Reset</a>
                                <a href="hr_reports.php?type=turnover" class="btn btn-secondary"><i class="fas fa-chart-bar"></i> HR Reports</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Position Title</th>
                                <th>Department</th>
                                <th>Employment Type</th>
                                <th>Salary Range</th>
                                <th>Posted Date</th>
                                <th>Candidates</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$has_job_positions): ?>
                                <tr><td colspan="8" class="text-center text-muted">`job_positions` table is missing. Create the recruitment tables first.</td></tr>
                            <?php elseif ($positions_result && mysqli_num_rows($positions_result) > 0): ?>
                                <?php while ($pos = mysqli_fetch_assoc($positions_result)): ?>
                                    <?php
                                    $dept = $pos['department_name'] ?: 'Unassigned';
                                    $salary_range = ($pos['salary_range_min'] && $pos['salary_range_max'])
                                        ? '&#8369;' . number_format((float)$pos['salary_range_min']) . ' - &#8369;' . number_format((float)$pos['salary_range_max'])
                                        : 'Not specified';
                                    $position_status = hrSafeEnum($pos['status'], ['open', 'closed', 'on_hold'], 'open');
                                    $status_class = 'badge-' . str_replace('_', '-', $position_status);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($pos['position_title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($dept); ?></td>
                                        <td><?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $pos['employment_type']))); ?></td>
                                        <td><?php echo $salary_range; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($pos['posted_date'])); ?></td>
                                        <td><span class="badge bg-info"><?php echo (int)$pos['candidate_count']; ?></span></td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $position_status)); ?></span></td>
                                        <td>
                                            <button class="btn-icon" onclick="viewCandidates(<?php echo (int)$pos['id']; ?>)" title="View Candidates">
                                                <i class="fas fa-users"></i>
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="update_position_status" value="1">
                                                <input type="hidden" name="position_id" value="<?php echo (int)$pos['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $position_status === 'open' ? 'closed' : 'open'; ?>">
                                                <button class="btn-icon" type="submit" title="<?php echo $position_status === 'open' ? 'Close Position' : 'Reopen Position'; ?>">
                                                    <i class="fas <?php echo $position_status === 'open' ? 'fa-lock' : 'fa-unlock'; ?>"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted">No job positions posted</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Position Modal -->
    <div class="modal fade" id="addPositionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Post New Job Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="mb-3">
                            <label>Position Title</label>
                            <input type="text" name="position_title" class="form-control" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Department</label>
                                <select name="department_id" class="form-control">
                                    <option value="">Unassigned</option>
                                    <?php
                                    if ($departments) {
                                        mysqli_data_seek($departments, 0);
                                        while ($dept = mysqli_fetch_assoc($departments)) {
                                            echo "<option value='{$dept['id']}'>" . htmlspecialchars($dept['department_name']) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Employment Type</label>
                                <select name="employment_type" class="form-control" required>
                                    <option value="">Select Type</option>
                                    <option value="full_time">Full Time</option>
                                    <option value="part_time">Part Time</option>
                                    <option value="contract">Contract</option>
                                    <option value="temporary">Temporary</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Salary Range (Min)</label>
                                <input type="number" name="salary_min" class="form-control" step="0.01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Salary Range (Max)</label>
                                <input type="number" name="salary_max" class="form-control" step="0.01">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Position Description</label>
                            <textarea name="description" class="form-control" rows="4"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label>Requirements</label>
                            <textarea name="requirements" class="form-control" rows="4"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label>Closing Date</label>
                            <input type="date" name="closing_date" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_position" class="btn btn-primary">Post Position</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // SweetAlert2 for Session Messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['error']; ?>'
            });
        <?php unset($_SESSION['error']); endif; ?>

        function viewCandidates(positionId) {
            window.location.href = 'candidates.php?position_id=' + positionId;
        }
        
    </script>
</body>
</html>



