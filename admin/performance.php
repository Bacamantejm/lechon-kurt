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
    <div class="modal fade" id="addReviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Performance Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Employee *</label>
                                <select name="employee_id" class="form-select" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Period Start *</label>
                                <input type="date" name="period_start" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label>Period End *</label>
                                <input type="date" name="period_end" class="form-control" required>
                            </div>
                        </div>
                        <div class="row g-3 mt-3">
                            <div class="col-md-3">
                                <label>Attendance</label>
                                <input type="number" name="attendance_rating" class="form-control" min="1" max="5" value="4" required>
                            </div>
                            <div class="col-md-3">
                                <label>Performance</label>
                                <input type="number" name="performance_rating" class="form-control" min="1" max="5" value="4" required>
                            </div>
                            <div class="col-md-3">
                                <label>Teamwork</label>
                                <input type="number" name="teamwork_rating" class="form-control" min="1" max="5" value="4" required>
                            </div>
                            <div class="col-md-3">
                                <label>Communication</label>
                                <input type="number" name="communication_rating" class="form-control" min="1" max="5" value="4" required>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label>Strengths</label>
                            <textarea name="strengths" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mt-3">
                            <label>Areas for Improvement</label>
                            <textarea name="areas_for_improvement" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mt-3">
                            <label>Goals for Next Period</label>
                            <textarea name="goals_for_next_period" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mt-3">
                            <label>Comments</label>
                            <textarea name="comments" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mt-3">
                            <label>Status</label>
                            <select name="status" class="form-select">
                                <option value="completed">Completed</option>
                                <option value="in_progress">In Progress</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Review Details Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Performance Review Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="reviewDetails">
                    <!-- Loaded via JS -->
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



