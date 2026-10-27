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
    if (function_exists('fixMojibakeUtf8')) {
        $notes = fixMojibakeUtf8($notes);
    }

    $status_map = ['approve' => 'approved', 'reject' => 'rejected', 'incomplete' => 'incomplete'];
    $new_status = $status_map[$action] ?? null;

    if ($app_id <= 0 || !$new_status) {
        saSetFlash('warning', 'Invalid franchise application action request.');
        header('Location: ' . $page_file);
        exit;
    }

    if (in_array($new_status, ['rejected', 'incomplete'], true) && $notes === '') {
        saSetFlash('warning', 'A reason is required when rejecting or marking an application incomplete.');
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

        if (function_exists('fixMojibakeUtf8')) {
            foreach ($app_data as $adk => $adv) {
                if (is_string($adv)) {
                    $app_data[$adk] = fixMojibakeUtf8($adv);
                }
            }
        }

        $flagged_docs = isset($_POST['flagged_docs']) && is_array($_POST['flagged_docs']) ? $_POST['flagged_docs'] : [];
        $doc_remarks = isset($_POST['doc_remarks']) && is_array($_POST['doc_remarks']) ? $_POST['doc_remarks'] : [];
        if (function_exists('fixMojibakeUtf8')) {
            foreach ($doc_remarks as &$rmk) {
                $rmk = fixMojibakeUtf8($rmk);
            }
            unset($rmk);
        }
        $deadline_raw = trim((string)($_POST['resubmission_deadline'] ?? ''));
        $deadline_date = null;
        if ($new_status === 'incomplete') {
            if ($deadline_raw !== '') {
                $deadline_ts = strtotime($deadline_raw);
                $deadline_date = $deadline_ts ? date('Y-m-d 23:59:59', $deadline_ts) : date('Y-m-d 23:59:59', strtotime('+7 days'));
            } else {
                $deadline_date = date('Y-m-d 23:59:59', strtotime('+7 days'));
            }
        }

        $doc_labels = [
            'business_logo' => 'Store Business Logo',
            'dti_doc' => 'DTI / SEC Certificate',
            'bir_doc' => 'BIR Registration (Form 2303)',
            'mayor_doc' => "Mayor's Permit",
            'barangay_clearance' => 'Barangay Clearance',
            'lease_or_title' => 'Lease Contract / Title',
            'occupancy_certificate' => 'Certificate of Occupancy',
            'fire_safety_certificate' => 'Fire Safety Certificate',
            'community_tax_certificate' => 'Community Tax Certificate (Cedula)',
            'sanitary_permit' => 'Sanitary Permit',
            'valid_id' => 'Valid ID of Owner',
            'address_proof' => 'Proof of Address',
            'bank_proof' => 'Bank Account Proof',
            'bir_form' => 'BIR Form 1901/1903',
            'sss_registration' => 'SSS Registration',
            'philhealth_registration' => 'PhilHealth Registration',
            'pagibig_registration' => 'Pag-IBIG Registration',
            'industry_permit' => 'Industry-Specific Permit'
        ];

        $incomplete_map = [];
        if ($new_status === 'incomplete') {
            foreach ($flagged_docs as $f_doc) {
                $f_doc = trim((string)$f_doc);
                if ($f_doc !== '') {
                    $remark = trim((string)($doc_remarks[$f_doc] ?? ''));
                    if ($remark === '') {
                        $remark = 'Document requires revision or clearer copy.';
                    }
                    $incomplete_map[$f_doc] = [
                        'label' => $doc_labels[$f_doc] ?? ucwords(str_replace('_', ' ', $f_doc)),
                        'reason' => $remark
                    ];
                }
            }

            if (empty($incomplete_map)) {
                throw new RuntimeException('Select at least one specific document to mark for re-upload before marking the application incomplete.');
            }
        }
        $incomplete_json = !empty($incomplete_map) ? json_encode($incomplete_map, JSON_UNESCAPED_UNICODE) : null;

        $has_inc_col = saColumnExists($conn, 'franchise_applications', 'incomplete_documents');
        $has_deadline_col = saColumnExists($conn, 'franchise_applications', 'resubmission_deadline');

        if ($has_inc_col && $has_deadline_col) {
            $update_query = "UPDATE franchise_applications
                             SET status = ?, admin_notes = ?, incomplete_documents = ?, resubmission_deadline = ?, admin_id = ?, reviewed_at = NOW()
                             WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare application status update.');
            }
            mysqli_stmt_bind_param($stmt, "ssssii", $new_status, $notes, $incomplete_json, $deadline_date, $current_admin_id, $app_id);
        } elseif ($has_inc_col) {
            $update_query = "UPDATE franchise_applications
                             SET status = ?, admin_notes = ?, incomplete_documents = ?, admin_id = ?, reviewed_at = NOW()
                             WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare application status update.');
            }
            mysqli_stmt_bind_param($stmt, "sssii", $new_status, $notes, $incomplete_json, $current_admin_id, $app_id);
        } else {
            $update_query = "UPDATE franchise_applications
                             SET status = ?, admin_notes = ?, admin_id = ?, reviewed_at = NOW()
                             WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare application status update.');
            }
            mysqli_stmt_bind_param($stmt, "ssii", $new_status, $notes, $current_admin_id, $app_id);
        }

        if (!mysqli_stmt_execute($stmt)) {
            $msg = trim((string)mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            throw new RuntimeException($msg !== '' ? $msg : 'Failed to update application status.');
        }
        mysqli_stmt_close($stmt);

        // Update document statuses in franchise_documents
        if (saTableExists($conn, 'franchise_documents')) {
            if ($new_status === 'incomplete') {
                $reset_stmt = mysqli_prepare($conn, "UPDATE franchise_documents SET status = 'approved', admin_remarks = NULL WHERE application_id = ?");
                if ($reset_stmt) {
                    mysqli_stmt_bind_param($reset_stmt, "i", $app_id);
                    mysqli_stmt_execute($reset_stmt);
                    mysqli_stmt_close($reset_stmt);
                }
                foreach ($incomplete_map as $f_type => $f_info) {
                    $f_reason = is_array($f_info) ? ($f_info['reason'] ?? '') : (string)$f_info;
                    $doc_stmt = mysqli_prepare($conn, "UPDATE franchise_documents SET status = 'incomplete', admin_remarks = ? WHERE application_id = ? AND document_type = ?");
                    if ($doc_stmt) {
                        mysqli_stmt_bind_param($doc_stmt, "sis", $f_reason, $app_id, $f_type);
                        mysqli_stmt_execute($doc_stmt);
                        mysqli_stmt_close($doc_stmt);
                    }
                }
            } elseif ($new_status === 'approved') {
                $all_ok_stmt = mysqli_prepare($conn, "UPDATE franchise_documents SET status = 'approved', admin_remarks = NULL WHERE application_id = ?");
                if ($all_ok_stmt) {
                    mysqli_stmt_bind_param($all_ok_stmt, "i", $app_id);
                    mysqli_stmt_execute($all_ok_stmt);
                    mysqli_stmt_close($all_ok_stmt);
                }
            }
        }

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

        $status_label = strtoupper($new_status === 'incomplete' ? 'INCOMPLETE' : $new_status);
        $reason = $notes !== '' ? $notes : ($new_status === 'approved'
            ? 'Your application passed the current review requirements. Your one-month platform trial starts today.'
            : ($new_status === 'incomplete'
                ? 'Please provide the missing requirements or corrected documents.'
                : 'The application did not meet the current review requirements.'));
        $business_name = trim((string)($app_data['business_name'] ?? ''));
        $title = 'Franchise Application: ' . $status_label;
        $deadline_line = ($new_status === 'incomplete' && !empty($deadline_date))
            ? "\nDeadline: " . date('M d, Y', strtotime($deadline_date))
            : '';
        $message = "Status: {$status_label}\nReason: {$reason}{$deadline_line}\nBusiness: {$business_name}";

        createNotification(
            $conn,
            $app_data['user_id'],
            'franchise_' . $new_status,
            $title,
            $message,
            $app_data['id'],
            'franchise_application'
        );

        sendFranchiseNotification($conn, $app_data, $new_status, $notes, $incomplete_map, $deadline_date);

        if ($new_status === 'approved' && is_array($store_result)) {
            $store_note = !empty($store_result['created']) ? 'A new store location was created.' : 'Existing store location was updated.';
            $trial_note = $trial_started ? ' A 1-month trial subscription was also activated.' : ' Trial subscription setup was skipped because an active billing profile already exists.';
            saSetFlash('success', "Application approved successfully. {$store_note} Business partner now has admin access with store-scoped product management.{$trial_note}");
        } elseif ($new_status === 'incomplete') {
            $doc_count = count($incomplete_map);
            $count_msg = $doc_count > 0 ? " ({$doc_count} document" . ($doc_count > 1 ? 's' : '') . " flagged for re-upload)" : "";
            $deadline_flash = !empty($deadline_date) ? " Deadline set to " . date('M d, Y', strtotime($deadline_date)) . "." : "";
            saSetFlash('warning', "Application marked as Incomplete Requirements{$count_msg}.{$deadline_flash} Notification sent to applicant.");
        } else {
            saSetFlash('success', 'Application status updated successfully. Notification sent to applicant.');
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log("Franchise approval flow failed for application {$app_id}: " . $e->getMessage());
        saSetFlash('danger', 'Failed to update franchise application: ' . $e->getMessage());
    }

    header('Location: ' . $page_file);
    exit;
}

function sendFranchiseNotification($conn, $app_data, $status, $admin_notes, $incomplete_documents = [], $deadline = null) {
    try {
        require_once dirname(__DIR__) . '/email_service.php';
        $recipient_email = trim((string)($app_data['email'] ?? $app_data['contact_email'] ?? ''));
        if ($recipient_email !== '') {
            $mailer = new EmailService($conn);
            $mailer->sendFranchiseStatusEmail($recipient_email, $status, $app_data, $admin_notes, $incomplete_documents, $deadline);
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

    $apps = saQueryRows($conn, "SELECT * FROM franchise_applications {$where_clause} ORDER BY created_at DESC");
    if (!empty($apps) && function_exists('fixMojibakeUtf8')) {
        foreach ($apps as &$appRow) {
            foreach ($appRow as $rk => $rv) {
                if (is_string($rv)) {
                    $appRow[$rk] = fixMojibakeUtf8($rv);
                }
            }
        }
        unset($appRow);
    }
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

<style>
.franchise-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
@media (min-width: 1400px) {
    .franchise-cards-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (min-width: 1750px) {
    .franchise-cards-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
.franchise-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 14px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.15s ease;
    cursor: pointer;
    position: relative;
}
.franchise-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px -4px rgba(16, 24, 40, 0.08);
    border-color: #d0d5dd;
}
body.dark-mode .franchise-card {
    background: #181d26 !important;
    border-color: #27303f !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
body.dark-mode .franchise-card:hover {
    border-color: #3b4758 !important;
    box-shadow: 0 12px 24px -4px rgba(0, 0, 0, 0.4);
}
.franchise-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 12px;
}
.franchise-card-app-no {
    font-size: 11.5px;
    font-weight: 700;
    font-family: monospace, -apple-system, BlinkMacSystemFont, sans-serif;
    color: #475467;
    background: #f8f9fa;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid #eaecf0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
body.dark-mode .franchise-card-app-no {
    background: #1f2937;
    color: #94a3b8;
    border-color: #374151;
}
.franchise-card-body {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}
.franchise-card-avatar {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #fff1f0;
    border: 1px solid #fee4e2;
    color: #b3261e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
body.dark-mode .franchise-card-avatar {
    background: #2a1515;
    border-color: #4c1d1d;
    color: #f87171;
}
.franchise-card-details {
    min-width: 0;
    flex: 1 1 auto;
}
.franchise-card-title {
    font-size: 15px;
    font-weight: 700;
    color: #101828;
    margin: 0 0 4px 0;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
body.dark-mode .franchise-card-title {
    color: #f8fafc;
}
.franchise-card-contact {
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: 12px;
    color: #475467;
}
body.dark-mode .franchise-card-contact {
    color: #94a3b8;
}
.franchise-card-contact-row {
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.franchise-card-contact-row i {
    font-size: 11px;
    color: #667085;
    width: 13px;
    text-align: center;
    flex-shrink: 0;
}
.franchise-card-meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    border-radius: 8px;
    padding: 8px 12px;
    margin-bottom: 12px;
}
body.dark-mode .franchise-card-meta-grid {
    background: #1f2937;
    border-color: #374151;
}
.franchise-meta-item {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.franchise-meta-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #667085;
    margin-bottom: 2px;
}
body.dark-mode .franchise-meta-label {
    color: #94a3b8;
}
.franchise-meta-value {
    font-size: 13px;
    font-weight: 700;
    color: #101828;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
body.dark-mode .franchise-meta-value {
    color: #f8fafc;
}
.franchise-card-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 10px;
    border-top: 1px solid #eaecf0;
    gap: 8px;
}
body.dark-mode .franchise-card-foot {
    border-top-color: #27303f;
}
.franchise-card-hint {
    font-size: 11.5px;
    color: #667085;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
body.dark-mode .franchise-card-hint {
    color: #94a3b8;
}
.franchise-card-cta {
    font-size: 12px;
    font-weight: 600;
    color: #b3261e;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: gap 0.15s ease;
}
.franchise-card:hover .franchise-card-cta {
    gap: 9px;
}
.franchise-cards-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 48px 20px;
    background: #ffffff;
    border: 1px dashed #d0d5dd;
    border-radius: 12px;
    color: #667085;
}
body.dark-mode .franchise-cards-empty {
    background: #181d26;
    border-color: #374151;
    color: #94a3b8;
}
</style>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Franchise Applications</h3>
            <p class="module-subtext">Open each application to inspect documents and approve or reject requests.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="view-mode-toggle" role="group" aria-label="View toggle">
                <button type="button" class="view-mode-btn active" id="saAppsViewCards" onclick="switchAppsView('cards')" title="Card Grid View">
                    <i class="fas fa-th-large"></i>
                </button>
                <button type="button" class="view-mode-btn" id="saAppsViewTable" onclick="switchAppsView('table')" title="Table Spreadsheet View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 16px;">
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
        <!-- Card Grid View (Compact & Clean) -->
        <div class="franchise-cards-grid" id="saAppsCardsGrid">
            <?php if (empty($apps)): ?>
                <div class="franchise-cards-empty">
                    <i class="fas fa-store-slash fa-2x mb-2 text-muted"></i>
                    <p class="mb-0">No franchise applications found matching your criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($apps as $app): ?>
                    <?php
                        $app_id = (int)($app['id'] ?? 0);
                        $status = strtolower(trim((string)($app['status'] ?? 'pending')));
                        $app_num = (string)($app['application_number'] ?? 'FR-' . $app_id);
                        $biz_name = (string)($app['business_name'] ?? 'Untitled Store');
                        $contact_person = (string)($app['contact_person'] ?? 'Unknown Applicant');
                        $contact_email = (string)($app['contact_email'] ?? '');
                        $investment = (float)($app['capital_investment'] ?? 0);
                        $created_date = saFormatDateTime($app['created_at'] ?? null, 'M d, Y');
                        $status_class = saApplicationStatusChipClass($status);
                        $status_label = ucfirst($status);
                        if ($status === 'incomplete') {
                            $status_label = 'Incomplete';
                        }
                    ?>
                    <div class="franchise-card" onclick="loadApplicationDetails(<?php echo $app_id; ?>)" data-bs-toggle="modal" data-bs-target="#appModal" title="Click to inspect application #<?php echo htmlspecialchars($app_num); ?>">
                        <div class="franchise-card-head">
                            <span class="franchise-card-app-no"><?php echo htmlspecialchars($app_num); ?></span>
                            <span class="status-chip <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($status_label); ?>
                            </span>
                        </div>

                        <div class="franchise-card-body">
                            <div class="franchise-card-avatar">
                                <i class="fas fa-store"></i>
                            </div>
                            <div class="franchise-card-details">
                                <h4 class="franchise-card-title" title="<?php echo htmlspecialchars($biz_name); ?>">
                                    <?php echo htmlspecialchars($biz_name); ?>
                                </h4>
                                <div class="franchise-card-contact">
                                    <span class="franchise-card-contact-row" title="<?php echo htmlspecialchars($contact_person); ?>">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($contact_person); ?>
                                    </span>
                                    <?php if ($contact_email !== ''): ?>
                                        <span class="franchise-card-contact-row" title="<?php echo htmlspecialchars($contact_email); ?>">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($contact_email); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="franchise-card-meta-grid">
                            <div class="franchise-meta-item">
                                <span class="franchise-meta-label">Capital</span>
                                <span class="franchise-meta-value"><?php echo htmlspecialchars(saFormatCurrency($investment)); ?></span>
                            </div>
                            <div class="franchise-meta-item">
                                <span class="franchise-meta-label">Applied</span>
                                <span class="franchise-meta-value"><?php echo htmlspecialchars($created_date); ?></span>
                            </div>
                        </div>

                        <div class="franchise-card-foot">
                            <span class="franchise-card-hint"><i class="fas fa-file-invoice"></i> Legal Docs</span>
                            <span class="franchise-card-cta">Review Application <i class="fas fa-arrow-right"></i></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Table Spreadsheet View (Alternate) -->
        <div class="table-wrap d-none" id="saAppsTableView">
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

<div class="modal fade modern-form-modal" id="appModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div>
                    <h5 class="modal-title">Franchise Application Details</h5>
                    <p class="modal-subtitle">Verify business credentials, submitted legal requirements, and capital capacity.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="appDetails">
                <div class="text-muted">Loading application details...</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade modern-form-modal" id="documentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div>
                    <h5 class="modal-title" id="documentPreviewTitle">Document Preview</h5>
                    <p class="modal-subtitle">High-resolution preview of submitted government permits and credentials.</p>
                </div>
                <div class="document-preview-controls ms-auto me-2" id="documentPreviewControls" aria-label="Image zoom controls">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeDocumentZoom(-0.25)" title="Zoom out" aria-label="Zoom out">
                        <i class="fas fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetDocumentZoom()" title="Reset zoom" aria-label="Reset zoom">100%</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeDocumentZoom(0.25)" title="Zoom in" aria-label="Zoom in">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body document-preview-body" id="documentPreviewBody">
                <div class="text-muted text-center">Loading document preview...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php
$extra_scripts = <<<'HTML'
<script>
    function cleanEnyeText(html) {
        if (!html || typeof html !== 'string') return '';
        return html
            .replace(/\u00c3\u0192\u00c2\u00b1/g, 'ñ')
            .replace(/\u00c3\u0192\u00c2\u2018/g, 'Ñ')
            .replace(/\u00c3\u00b1/g, 'ñ')
            .replace(/\u00c3\u0091/g, 'Ñ')
            .replace(/\u251c\u2592/g, 'ñ')
            .replace(/\u251c\u00e6/g, 'Ñ')
            .replace(/\u0393\u00f6\u00a3\u0393\u00fb\u00c6/g, 'ñ')
            .replace(/Dasmari(?:[^a-zA-Z0-9\s<>&]{1,6}|n)as/gi, 'Dasmariñas')
            .replace(/Para(?:[^a-zA-Z0-9\s<>&]{1,6}|n)aque/gi, 'Parañaque')
            .replace(/Las Pi(?:[^a-zA-Z0-9\s<>&]{1,6}|n)as/gi, 'Las Piñas');
    }

    function loadApplicationDetails(appId) {
        $.ajax({
            url: '../super_admin/get_application_details.php',
            type: 'GET',
            data: { id: appId },
            success: function(response) {
                $('#appDetails').html(cleanEnyeText(response));
            },
            error: function() {
                $('#appDetails').html('<div class="alert alert-danger">Unable to load application details.</div>');
            }
        });
    }

    function openDocumentPreview(button) {
        const previewUrl = button.getAttribute('data-preview-url');
        const previewTitle = button.getAttribute('data-preview-title') || 'Document Preview';
        const previewTitleElement = document.getElementById('documentPreviewTitle');
        const previewBody = document.getElementById('documentPreviewBody');
        const previewModalElement = document.getElementById('documentPreviewModal');
        const previewControls = document.getElementById('documentPreviewControls');

        if (!previewUrl || !previewTitleElement || !previewBody || !previewModalElement) {
            return;
        }

        const extension = previewUrl.split('?')[0].split('.').pop().toLowerCase();
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        previewTitleElement.textContent = previewTitle;
        previewBody.innerHTML = '';
        resetDocumentZoom();

        if (imageExtensions.includes(extension)) {
            previewControls.hidden = false;
            const image = document.createElement('img');
            image.src = previewUrl;
            image.alt = previewTitle;
            image.className = 'document-preview-image';
            image.addEventListener('error', function() {
                previewBody.innerHTML = '<div class="alert alert-danger">Unable to load this document preview.</div>';
            });
            previewBody.appendChild(image);
        } else {
            previewControls.hidden = true;
            const frame = document.createElement('iframe');
            frame.src = previewUrl;
            frame.title = previewTitle;
            frame.className = 'document-preview-frame';
            previewBody.appendChild(frame);
        }

        bootstrap.Modal.getOrCreateInstance(previewModalElement).show();
    }

    let documentZoom = 1;

    function applyDocumentZoom() {
        const image = document.querySelector('#documentPreviewBody .document-preview-image');
        const zoomLabel = document.querySelector('#documentPreviewControls button:nth-child(2)');
        if (image) {
            image.style.transform = 'scale(' + documentZoom + ')';
        }
        if (zoomLabel) {
            zoomLabel.textContent = Math.round(documentZoom * 100) + '%';
        }
    }

    function changeDocumentZoom(amount) {
        documentZoom = Math.min(3, Math.max(0.5, documentZoom + amount));
        applyDocumentZoom();
    }

    function resetDocumentZoom() {
        documentZoom = 1;
        applyDocumentZoom();
    }

    function getActiveApplicationForm() {
        const forms = Array.from(document.querySelectorAll('form[id="appForm"]'));
        return forms.find(form => form.getClientRects().length > 0) || forms[forms.length - 1] || null;
    }

    function handleIncomplete(applicationNumber) {
        const appForm = getActiveApplicationForm();
        const checkedBoxes = appForm
            ? Array.from(document.querySelectorAll('input[name="flagged_docs[]"]')).filter(checkbox => checkbox.checked && checkbox.form === appForm)
            : [];
        const flaggedNames = checkedBoxes.map(cb => cb.getAttribute('data-doc-name') || cb.value);
        const notesField = appForm ? appForm.querySelector('#admin_notes') : null;
        const notes = notesField ? notesField.value.trim() : '';

        if (flaggedNames.length === 0) {
            Swal.fire({
                title: 'Select a document first',
                text: 'Mark at least one specific document for re-upload before continuing.',
                icon: 'warning',
                confirmButtonColor: '#b3261e'
            });
            return;
        }
        
        const deadlineField = appForm ? appForm.querySelector('#resubmission_deadline') : null;
        const deadlineVal = deadlineField ? deadlineField.value : '';
        const deadlineDisplay = deadlineVal ? new Date(deadlineVal + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '7 days from today';

        let docListHtml = '';
        docListHtml = '<div style="background:#fff8ef;border:1px solid #fedf89;border-radius:8px;padding:10px 14px;margin:12px 0;text-align:left;color:#b54708;font-size:13px;">' +
                      '<strong>Flagged for Re-upload (' + flaggedNames.length + ' item' + (flaggedNames.length > 1 ? 's' : '') + '):</strong><ul style="margin:4px 0 0;padding-left:18px;">' +
                      flaggedNames.map(name => '<li>' + name + '</li>').join('') +
                      '</ul></div>' +
                      '<div style="background:#fef3f2;border:1px solid #fee4e2;border-radius:8px;padding:8px 12px;margin-bottom:12px;text-align:left;color:#b3261e;font-size:12.5px;">' +
                      '<i class="fas fa-calendar-day" style="margin-right:6px;"></i><strong>Compliance Deadline:</strong> ' + deadlineDisplay +
                      '</div>' +
                      '<p style="font-size:12px;color:#667085;margin-bottom:0;">The applicant will only need to re-upload the flagged document(s). All approved files and application information will be preserved.</p>';

        Swal.fire({
            title: 'Mark Incomplete Requirements',
            html: 'Are you sure you want to mark application <strong>' + applicationNumber + '</strong> as <strong>INCOMPLETE REQUIREMENTS</strong>?' + docListHtml,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            cancelButtonColor: '#6c757d',
            input: 'textarea',
            inputValue: notes,
            inputPlaceholder: 'Explain why this application is incomplete...',
            inputAttributes: {
                'aria-label': 'Reason for marking the application incomplete'
            },
            inputValidator: (value) => {
                return value.trim() === '' ? 'A reason is required.' : undefined;
            },
            confirmButtonText: 'Yes, Mark Incomplete & Notify Client',
            cancelButtonText: 'Cancel',
            backdrop: 'rgba(0,0,0,0.5)'
        }).then((result) => {
            if (result.isConfirmed) {
                const appActionInput = appForm ? appForm.querySelector('#app_action') : null;
                if (appActionInput && appForm) {
                    if (notesField) {
                        notesField.value = result.value.trim();
                    }
                    appActionInput.value = 'incomplete';
                    appForm.submit();
                }
            }
        });
    }

    function handleReject(applicationNumber) {
        const appForm = getActiveApplicationForm();
        const notesField = appForm ? appForm.querySelector('#admin_notes') : null;
        const notes = notesField ? notesField.value.trim() : '';

        Swal.fire({
            title: 'Confirm Rejection',
            html: 'Are you sure you want to <strong>REJECT</strong> this application?<br><br><strong>Application #:</strong> ' + applicationNumber + '<br><br>This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            input: 'textarea',
            inputValue: notes,
            inputPlaceholder: 'Explain why this application is rejected...',
            inputAttributes: {
                'aria-label': 'Reason for rejecting the application'
            },
            inputValidator: (value) => {
                return value.trim() === '' ? 'A reason is required.' : undefined;
            },
            confirmButtonText: 'Yes, Reject Application',
            cancelButtonText: 'Cancel',
            backdrop: 'rgba(0,0,0,0.5)'
        }).then((result) => {
            if (result.isConfirmed) {
                const appActionInput = appForm ? appForm.querySelector('#app_action') : null;
                if (appActionInput && appForm) {
                    if (notesField) {
                        notesField.value = result.value.trim();
                    }
                    appActionInput.value = 'reject';
                    appForm.submit();
                }
            }
        });
    }

    function switchAppsView(mode) {
        if (mode === 'table') {
            $('#saAppsCardsGrid').addClass('d-none');
            $('#saAppsTableView').removeClass('d-none');
            $('#saAppsViewCards').removeClass('active');
            $('#saAppsViewTable').addClass('active');
            try { localStorage.setItem('sa_apps_view_mode', 'table'); } catch(e){}
        } else {
            $('#saAppsCardsGrid').removeClass('d-none');
            $('#saAppsTableView').addClass('d-none');
            $('#saAppsViewCards').addClass('active');
            $('#saAppsViewTable').removeClass('active');
            try { localStorage.setItem('sa_apps_view_mode', 'cards'); } catch(e){}
        }
    }

    $(function() {
        var saved = 'cards';
        try { saved = localStorage.getItem('sa_apps_view_mode') || 'cards'; } catch(e){}
        switchAppsView(saved);
    });
</script>
HTML;

saRenderModuleFooter($extra_scripts);
