<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/PlatformMonetizationService.php';
require_once __DIR__ . '/../includes/store_availability_helper.php';

$page_file = 'franchise_applications.php';
$has_apps = saTableExists($conn, 'franchise_applications');

function parseCityProvinceFromLocation($proposed_location, $business_address, $city_name = '', $province_name = '', $region_name = '') {
    $city_name = trim((string)$city_name);
    $province_name = trim((string)$province_name);
    $region_name = trim((string)$region_name);

    if ($city_name !== '' && $province_name !== '') {
        return [
            'city' => substr($city_name, 0, 100),
            'province' => substr($province_name, 0, 100)
        ];
    }

    $source = trim((string)$proposed_location);
    if ($source === '') {
        $source = trim((string)$business_address);
    }

    $segments = array_values(array_filter(array_map('trim', explode(',', $source)), function ($value) {
        return $value !== '';
    }));

    $city = 'Metro Manila';
    $province = 'Metro Manila';

    if (count($segments) >= 2) {
        $city = $segments[count($segments) - 2];
        $province = $segments[count($segments) - 1];
    } elseif (count($segments) === 1) {
        $city = $segments[0];
        if ($province_name !== '') {
            $province = $province_name;
        } elseif ($region_name !== '') {
            $province = $region_name;
        }
    }

    return [
        'city' => substr($city, 0, 100),
        'province' => substr($province, 0, 100)
    ];
}

function upsertFranchiseStoreLocation($conn, $app_data) {
    return sahUpsertPartnerStoreLocation($conn, (array)$app_data);
}

function getBusinessOwnerRoleId($conn) {
    $role_query = "SELECT id FROM roles WHERE name = 'business_owner' AND is_active = 1 LIMIT 1";
    $role_result = mysqli_query($conn, $role_query);
    if (!$role_result) {
        throw new RuntimeException('Unable to read RBAC roles.');
    }

    $role_row = mysqli_fetch_assoc($role_result);
    mysqli_free_result($role_result);
    $role_id = (int)($role_row['id'] ?? 0);

    if ($role_id <= 0) {
        throw new RuntimeException('Business owner role is missing. Please configure RBAC roles first.');
    }

    return $role_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['app_action'])) {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);

    if (!$has_apps) {
        saSetFlash('danger', 'Franchise applications table is unavailable in this environment.');
        header('Location: ' . $page_file);
        exit;
    }

    $app_id = (int)($_POST['app_id'] ?? 0);
    $action = strtolower(trim((string)($_POST['app_action'] ?? '')));
    $notes = trim((string)($_POST['admin_notes'] ?? ''));

    $status_map = ['approve' => 'approved', 'reject' => 'rejected', 'incomplete' => 'incomplete'];
    $new_status = $status_map[$action] ?? null;

    if ($app_id <= 0 || !$new_status) {
        saSetFlash('warning', 'Invalid franchise application action request.');
        header('Location: ' . $page_file);
        exit;
    }

    $store_result = null;
    $trial_started = false;
    try {
        $monetizationService = null;
        if ($new_status === 'approved') {
            $monetizationService = new PlatformMonetizationService($conn);
            $monetizationService->ensureReady($current_admin_id);
        }

        mysqli_begin_transaction($conn);

        $app_query = "SELECT fa.*, u.email, u.full_name
                      FROM franchise_applications fa
                      JOIN users u ON fa.user_id = u.id
                      WHERE fa.id = ?
                      FOR UPDATE";
        $stmt = mysqli_prepare($conn, $app_query);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare application lookup query.');
        }
        mysqli_stmt_bind_param($stmt, "i", $app_id);
        mysqli_stmt_execute($stmt);
        $app_result = mysqli_stmt_get_result($stmt);
        $app_data = $app_result ? mysqli_fetch_assoc($app_result) : null;
        mysqli_stmt_close($stmt);

        if (!$app_data) {
            throw new RuntimeException('Application not found.');
        }

        $update_query = "UPDATE franchise_applications
                         SET status = ?, admin_notes = ?, admin_id = ?, reviewed_at = NOW()
                         WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare application status update.');
        }
        mysqli_stmt_bind_param($stmt, "ssii", $new_status, $notes, $current_admin_id, $app_id);
        if (!mysqli_stmt_execute($stmt)) {
            $msg = trim((string)mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            throw new RuntimeException($msg !== '' ? $msg : 'Failed to update application status.');
        }
        mysqli_stmt_close($stmt);

        if ($new_status === 'approved') {
            $approved_business_type = trim((string)($app_data['business_type'] ?? ''));
            if ($approved_business_type === '') {
                $approved_business_type = 'restaurant';
            }
            $business_owner_role_id = getBusinessOwnerRoleId($conn);

            $promote_query = "UPDATE users
                              SET user_type = 'admin',
                                  role_id = ?,
                                  account_type = 'organization',
                                  business_name = ?,
                                  business_type = ?,
                                  address = ?,
                                  phone = ?,
                                  is_active = 1
                              WHERE id = ?";
            $promote_stmt = mysqli_prepare($conn, $promote_query);
            if (!$promote_stmt) {
                throw new RuntimeException('Unable to prepare account promotion query.');
            }
            mysqli_stmt_bind_param(
                $promote_stmt,
                "issssi",
                $business_owner_role_id,
                $app_data['business_name'],
                $approved_business_type,
                $app_data['business_address'],
                $app_data['contact_phone'],
                $app_data['user_id']
            );
            if (!mysqli_stmt_execute($promote_stmt)) {
                $msg = trim((string)mysqli_stmt_error($promote_stmt));
                mysqli_stmt_close($promote_stmt);
                throw new RuntimeException($msg !== '' ? $msg : 'Failed to enable partner business-owner account.');
            }
            mysqli_stmt_close($promote_stmt);

            $store_result = upsertFranchiseStoreLocation($conn, $app_data);
            if ($monetizationService instanceof PlatformMonetizationService) {
                $trial_started = $monetizationService->activateApprovedPartnerTrial((int)$app_data['user_id'], $current_admin_id);
            }
        }

        mysqli_commit($conn);

        if ($new_status === 'approved') {
            $title = "Franchise Application Approved!";
            $message = "Congratulations! Your franchise application for " . htmlspecialchars($app_data['business_name']) . " has been approved. You can now access the admin portal and manage your own store products. Your 1-month platform trial starts today.";
        } elseif ($new_status === 'incomplete') {
            $title = "Action Required: Franchise Application Incomplete";
            $message = "Your franchise application for " . htmlspecialchars($app_data['business_name']) . " has missing requirements. " . (!empty($notes) ? "Details: " . substr($notes, 0, 100) . "..." : "Please upload your complete compliance documents.");
        } else {
            $title = "Franchise Application Update";
            $message = "Your franchise application has been reviewed. " . (!empty($notes) ? "Feedback: " . substr($notes, 0, 100) . "..." : "Please check your email for more details.");
        }

        createNotification(
            $conn,
            $app_data['user_id'],
            'franchise_' . $new_status,
            $title,
            $message,
            $app_data['id'],
            'franchise_application'
        );

        sendFranchiseNotification($conn, $app_data, $new_status, $notes);

        if ($new_status === 'approved' && is_array($store_result)) {
            $store_note = !empty($store_result['created']) ? 'A new store location was created.' : 'Existing store location was updated.';
            $trial_note = $trial_started ? ' A 1-month trial subscription was also activated.' : ' Trial subscription setup was skipped because an active billing profile already exists.';
            saSetFlash('success', "Application approved successfully. {$store_note} Business partner now has admin access with store-scoped product management.{$trial_note}");
        } elseif ($new_status === 'incomplete') {
            saSetFlash('warning', 'Application marked as Incomplete Requirements. Notification email sent to applicant.');
        } else {
            saSetFlash('success', 'Application status updated successfully. Notification email sent to applicant.');
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log("Franchise approval flow failed for application {$app_id}: " . $e->getMessage());
        saSetFlash('danger', 'Failed to update franchise application: ' . $e->getMessage());
    }

    header('Location: ' . $page_file);
    exit;
}

function sendFranchiseNotification($conn, $app_data, $status, $admin_notes) {
    try {
        require_once dirname(__DIR__) . '/email_service.php';
        $recipient_email = trim((string)($app_data['email'] ?? $app_data['contact_email'] ?? ''));
        if ($recipient_email !== '') {
            $mailer = new EmailService($conn);
            $mailer->sendFranchiseStatusEmail($recipient_email, $status, $app_data, $admin_notes);
        }
    } catch (Throwable $e) {
        error_log("Failed to send franchise status email notification: " . $e->getMessage());
    }
}

$app_stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
if ($has_apps) {
    $stats_rows = saQueryRows(
        $conn,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
         FROM franchise_applications"
    );
    if (!empty($stats_rows)) {
        $app_stats = array_merge($app_stats, $stats_rows[0]);
    }
}

$status_filter = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($status_filter, ['', 'pending', 'approved', 'rejected'], true)) {
    $status_filter = '';
}
$search = trim((string)($_GET['search'] ?? ''));

$apps = [];
if ($has_apps) {
    $where_clauses = [];
    if ($status_filter !== '') {
        $where_clauses[] = "status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
    }
    if ($search !== '') {
        $search_safe = mysqli_real_escape_string($conn, $search);
        $search_id = (int)$search;
        $where_clauses[] = "(application_number LIKE '%{$search_safe}%'
                         OR business_name LIKE '%{$search_safe}%'
                         OR contact_email LIKE '%{$search_safe}%'
                         OR id = {$search_id})";
    }

    $where_clause = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    $apps = saQueryRows($conn, "SELECT * FROM franchise_applications {$where_clause} ORDER BY created_at DESC");
}

if (!function_exists('saApplicationStatusChipClass')) {
    function saApplicationStatusChipClass($status) {
        $status = strtolower(trim((string)$status));
        if ($status === 'approved') {
            return 'chip-success';
        }
        if ($status === 'pending' || $status === 'incomplete') {
            return 'chip-warning';
        }
        if ($status === 'rejected') {
            return 'chip-danger';
        }
        return 'chip-muted';
    }
}

saRenderModuleHeader('Business Applications', 'Business Applications', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Application Review Overview</h2>
            <p class="module-subtext">Review, approve, and track all incoming franchise partner applications.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Applications</span>
            <div class="metric-value"><?php echo number_format((int)($app_stats['total'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pending Review</span>
            <div class="metric-value"><?php echo number_format((int)($app_stats['pending'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Approved</span>
            <div class="metric-value"><?php echo number_format((int)($app_stats['approved'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Rejected</span>
            <div class="metric-value"><?php echo number_format((int)($app_stats['rejected'] ?? 0)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Franchise Applications</h3>
            <p class="module-subtext">Open each application to inspect documents and approve or reject requests.</p>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <input type="text" name="search" class="form-control" placeholder="Search applications..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="status" class="form-select">
            <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>All Status</option>
            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
            <option value="incomplete" <?php echo $status_filter === 'incomplete' ? 'selected' : ''; ?>>Incomplete Requirements</option>
            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    </form>

    <?php if (!$has_apps): ?>
        <div class="note-box">`franchise_applications` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Application #</th>
                        <th>Business Name</th>
                        <th>Contact</th>
                        <th>Investment</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($apps)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No applications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($apps as $app): ?>
                            <?php $status = (string)($app['status'] ?? ''); ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string)($app['application_number'] ?? '-')); ?></strong></td>
                                <td><?php echo htmlspecialchars((string)($app['business_name'] ?? '-')); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars((string)($app['contact_person'] ?? '-')); ?></div>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($app['contact_email'] ?? '-')); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(saFormatCurrency((float)($app['capital_investment'] ?? 0))); ?></td>
                                <td>
                                    <span class="status-chip <?php echo saApplicationStatusChipClass($status); ?>">
                                        <?php echo htmlspecialchars(ucfirst($status !== '' ? $status : 'unknown')); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($app['created_at'] ?? null, 'M d, Y')); ?></td>
                                <td>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#appModal"
                                            onclick="loadApplicationDetails(<?php echo (int)($app['id'] ?? 0); ?>)"
                                            title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="appModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Application Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="appDetails">
                <div class="text-muted">Loading application details...</div>
            </div>
        </div>
    </div>
</div>
<?php
$extra_scripts = <<<'HTML'
<script>
    function loadApplicationDetails(appId) {
        $.ajax({
            url: '../super_admin/get_application_details.php',
            type: 'GET',
            data: { id: appId },
            success: function(response) {
                $('#appDetails').html(response);
            },
            error: function() {
                $('#appDetails').html('<div class="alert alert-danger">Unable to load application details.</div>');
            }
        });
    }

    function handleIncomplete(applicationNumber) {
        Swal.fire({
            title: 'Mark Incomplete Requirements',
            html: 'Are you sure you want to mark application <strong>' + applicationNumber + '</strong> as <strong>INCOMPLETE REQUIREMENTS</strong>?<br><br>An email notification with admin notes will be sent to the applicant asking for completed documents.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Mark Incomplete & Notify Client',
            cancelButtonText: 'Cancel',
            backdrop: 'rgba(0,0,0,0.5)'
        }).then((result) => {
            if (result.isConfirmed) {
                const appActionInput = document.getElementById('app_action');
                const appForm = document.getElementById('appForm');
                if (appActionInput && appForm) {
                    appActionInput.value = 'incomplete';
                    appForm.submit();
                }
            }
        });
    }

    function handleReject(applicationNumber) {
        Swal.fire({
            title: 'Confirm Rejection',
            html: 'Are you sure you want to <strong>REJECT</strong> this application?<br><br><strong>Application #:</strong> ' + applicationNumber + '<br><br>This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Reject Application',
            cancelButtonText: 'Cancel',
            backdrop: 'rgba(0,0,0,0.5)'
        }).then((result) => {
            if (result.isConfirmed) {
                const appActionInput = document.getElementById('app_action');
                const appForm = document.getElementById('appForm');
                if (appActionInput && appForm) {
                    appActionInput.value = 'reject';
                    appForm.submit();
                }
            }
        });
    }
</script>
HTML;

saRenderModuleFooter($extra_scripts);
