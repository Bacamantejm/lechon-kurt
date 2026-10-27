<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$reviewer_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$csrf_token = hrEnsureCsrfToken();
$has_performance_table = hrTableExists($conn, 'performance_reviews');
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

if (!$is_partner_scoped_hr) {
    requireAnyPermission(['performance.view', 'performance.manage']);
}

$employee_filter = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$status_filter = hrSafeEnum($_GET['status'] ?? '', ['', 'completed', 'in_progress', 'draft'], '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hrEnforcePostCsrf('performance.php');
    if (!$is_partner_scoped_hr) {
        requirePermission('performance.manage');
    }

    if (!$has_performance_table) {
        $_SESSION['error'] = "Performance reviews table is not configured yet.";
        header("Location: performance.php");
        exit();
    }

    $employee_id = intval($_POST['employee_id'] ?? 0);
    $period_start = trim($_POST['period_start'] ?? '');
    $period_end = trim($_POST['period_end'] ?? '');
    $attendance_rating = max(1, min(5, intval($_POST['attendance_rating'] ?? 0)));
    $performance_rating = max(1, min(5, intval($_POST['performance_rating'] ?? 0)));
    $teamwork_rating = max(1, min(5, intval($_POST['teamwork_rating'] ?? 0)));
    $communication_rating = max(1, min(5, intval($_POST['communication_rating'] ?? 0)));
    $strengths = trim($_POST['strengths'] ?? '');
    $areas_for_improvement = trim($_POST['areas_for_improvement'] ?? '');
    $goals_for_next_period = trim($_POST['goals_for_next_period'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    $status = hrSafeEnum(trim($_POST['status'] ?? 'completed'), ['completed', 'in_progress', 'draft'], 'completed');

    if ($employee_id === 0 || !hrIsValidDate($period_start) || !hrIsValidDate($period_end) || $period_start > $period_end) {
        $_SESSION['error'] = "Please provide employee and review period.";
    } elseif ($is_partner_scoped_hr && !hrEmployeeIdInScope($conn, $employee_id)) {
        $_SESSION['error'] = "Selected employee is outside your partner HR scope.";
    } else {
        $overall_rating = (int) round(($attendance_rating + $performance_rating + $teamwork_rating + $communication_rating) / 4);

        $insert_sql = "INSERT INTO performance_reviews (employee_id, reviewer_id, period_start, period_end, attendance_rating, performance_rating, teamwork_rating, communication_rating, overall_rating, strengths, areas_for_improvement, goals_for_next_period, comments, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($insert_stmt, "iissiiiiisssss", $employee_id, $reviewer_id, $period_start, $period_end, $attendance_rating, $performance_rating, $teamwork_rating, $communication_rating, $overall_rating, $strengths, $areas_for_improvement, $goals_for_next_period, $comments, $status);

        if ($insert_stmt && mysqli_stmt_execute($insert_stmt)) {
            $_SESSION['success'] = "Performance review saved.";
        } else {
            $_SESSION['error'] = "Unable to save performance review.";
        }
        if ($insert_stmt) {
            mysqli_stmt_close($insert_stmt);
        }
    }

    header("Location: performance.php");
    exit();
}

// Employees list for dropdown
$employees = [];
$employees_query_sql = "SELECT e.id, e.first_name, e.last_name FROM employees e WHERE e.status = 'active'";
if ($is_partner_scoped_hr) {
    $employees_query_sql .= " AND {$employee_scope_sql}";
}
$employees_query_sql .= " ORDER BY e.first_name, e.last_name";
$emp_query = mysqli_query($conn, $employees_query_sql);
if ($emp_query) {
    while ($emp = mysqli_fetch_assoc($emp_query)) {
        $employees[] = $emp;
    }
}

$reviews = false;
if ($has_performance_table) {
    $reviews_sql = "
        SELECT pr.*, e.first_name AS emp_first, e.last_name AS emp_last, e.id AS emp_id, u.full_name AS reviewer_name
        FROM performance_reviews pr
        JOIN employees e ON pr.employee_id = e.id
        JOIN users u ON pr.reviewer_id = u.id
        WHERE (? = 0 OR pr.employee_id = ?) AND (? = '' OR pr.status = ?)" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "") . "
        ORDER BY pr.created_at DESC
        LIMIT 100
    ";
    $reviews_stmt = mysqli_prepare($conn, $reviews_sql);
    mysqli_stmt_bind_param($reviews_stmt, "iiss", $employee_filter, $employee_filter, $status_filter, $status_filter);
    mysqli_stmt_execute($reviews_stmt);
    $reviews = mysqli_stmt_get_result($reviews_stmt);
    mysqli_stmt_close($reviews_stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Reviews - HR Management</title>
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
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Performance Reviews</h1>
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
                <?php if (!$has_performance_table): ?>
                    <div class="alert alert-warning">
                        <strong>Notice:</strong> `performance_reviews` table is missing. Review records cannot be saved until this table exists.
                    </div>
                <?php endif; ?>

                <div class="section-header">
                    <h2>Employee Performance Reviews</h2>
                    <div class="d-flex gap-2">
                        <a href="employees.php" class="btn btn-secondary"><i class="fas fa-id-badge"></i> Employees</a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReviewModal" <?php echo !$has_performance_table ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus"></i> Create Review
                        </button>
                    </div>
                </div>

                <div class="card hr-filter-panel mb-3">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label mb-1">Employee</label>
                                <select name="employee_id" class="form-select">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($employee_filter === (int)$emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1">Status</label>
                                <select name="status" class="form-select">
                                    <option value="" <?php echo ($status_filter === '') ? 'selected' : ''; ?>>All</option>
                                    <option value="completed" <?php echo ($status_filter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    <option value="in_progress" <?php echo ($status_filter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="draft" <?php echo ($status_filter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                                <a href="performance.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Review Period</th>
                                <th>Overall Rating</th>
                                <th>Reviewer</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$has_performance_table): ?>
                                <tr><td colspan="7" class="text-center text-muted">`performance_reviews` table is missing.</td></tr>
                            <?php elseif ($reviews && mysqli_num_rows($reviews) > 0): ?>
                                <?php while ($review = mysqli_fetch_assoc($reviews)): ?>
                                    <?php
                                    $review_status = hrSafeEnum($review['status'], ['completed', 'in_progress', 'draft'], 'draft');
                                    $status_class = 'badge-' . str_replace('_', '-', $review_status);
                                    $rating_color = $review['overall_rating'] >= 4 ? 'success' : ($review['overall_rating'] >= 3 ? 'warning' : 'danger');
                                    $stars = str_repeat('&#9733;', max(1, min(5, (int)$review['overall_rating'])));
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($review['emp_first'] . ' ' . $review['emp_last']); ?></strong></td>
                                        <td><?php echo date('M d, Y', strtotime($review['period_start'])) . " - " . date('M d, Y', strtotime($review['period_end'])); ?></td>
                                        <td><span class="badge bg-<?php echo $rating_color; ?>"><?php echo $stars; ?></span></td>
                                        <td><?php echo htmlspecialchars($review['reviewer_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $review_status)); ?></span></td>
                                        <td>
                                            <button class="btn-icon" data-bs-toggle="modal" data-bs-target="#reviewModal" onclick="loadReviewDetails(<?php echo (int)$review['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a class="btn-icon" href="attendance.php?employee_id=<?php echo (int)$review['emp_id']; ?>" title="Attendance">
                                                <i class="fas fa-calendar-check"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted">No performance reviews found for the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Review Modal -->
    <div class="modal fade modern-form-modal" id="addReviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <div class="modal-header-icon">
                            <i class="fas fa-star-half-alt"></i>
                        </div>
                        <div>
                            <h5 class="modal-title">Create Performance Review</h5>
                            <p class="modal-subtitle">Formal periodic evaluation and goal setting for staff members.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <!-- Section 1: Employee & Period -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <div class="form-section-title">
                                    <i class="fas fa-user-check text-danger"></i>
                                    Employee & Review Period
                                </div>
                                <span class="form-req-pill">Required</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="reviewEmployeeSelect">Employee</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-user form-input-icon"></i>
                                            <select name="employee_id" id="reviewEmployeeSelect" class="form-select" required>
                                                <option value="">Select employee</option>
                                                <?php foreach ($employees as $emp): ?>
                                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="periodStart">Period Start</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-calendar-alt form-input-icon"></i>
                                            <input type="date" name="period_start" id="periodStart" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="periodEnd">Period End</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-calendar-check form-input-icon"></i>
                                            <input type="date" name="period_end" id="periodEnd" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Core Competencies -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <div class="form-section-title">
                                    <i class="fas fa-award text-danger"></i>
                                    Core Competency Ratings (1 to 5)
                                </div>
                                <span class="form-req-pill">Required</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="attRating">Attendance</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-clock form-input-icon"></i>
                                            <input type="number" name="attendance_rating" id="attRating" class="form-control" min="1" max="5" value="4" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="perfRating">Performance</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-tasks form-input-icon"></i>
                                            <input type="number" name="performance_rating" id="perfRating" class="form-control" min="1" max="5" value="4" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="teamRating">Teamwork</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-users form-input-icon"></i>
                                            <input type="number" name="teamwork_rating" id="teamRating" class="form-control" min="1" max="5" value="4" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="commRating">Communication</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-comments form-input-icon"></i>
                                            <input type="number" name="communication_rating" id="commRating" class="form-control" min="1" max="5" value="4" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3: Feedback & Goals -->
                        <div class="form-section-card">
                            <div class="form-section-head">
                                <div class="form-section-title">
                                    <i class="fas fa-comment-dots text-danger"></i>
                                    Feedback & Development
                                </div>
                                <span class="form-opt-pill">Evaluation Details</span>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="strengthsInput">Strengths</label>
                                        <textarea name="strengths" id="strengthsInput" class="form-control" rows="2" placeholder="Key strengths displayed during the period..."></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="improveInput">Areas for Improvement</label>
                                        <textarea name="areas_for_improvement" id="improveInput" class="form-control" rows="2" placeholder="Specific areas needing refinement or training..."></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="goalsInput">Goals for Next Period</label>
                                        <textarea name="goals_for_next_period" id="goalsInput" class="form-control" rows="2" placeholder="Key milestones and targets to achieve..."></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="commentsInput">General Comments</label>
                                        <textarea name="comments" id="commentsInput" class="form-control" rows="2" placeholder="Additional reviewer observations..."></textarea>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern" for="reviewStatusSelect">Review Status</label>
                                        <div class="form-input-wrap">
                                            <i class="fas fa-check-double form-input-icon"></i>
                                            <select name="status" id="reviewStatusSelect" class="form-select">
                                                <option value="completed">Completed</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="draft">Draft</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-modal-primary">Save Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Review Details Modal -->
    <div class="modal fade modern-form-modal" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-header-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h5 class="modal-title">Performance Review Report</h5>
                        <p class="modal-subtitle">Detailed evaluation metrics, competencies, and development goals.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="reviewDetails">
                    <!-- Loaded via JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        function loadReviewDetails(reviewId) {
            $.ajax({
                url: 'get_performance_details.php',
                type: 'GET',
                data: { id: reviewId },
                success: function(response) {
                    $('#reviewDetails').html(response);
                }
            });
        }
    </script>
</body>
</html>



