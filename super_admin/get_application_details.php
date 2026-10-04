<?php
require_once __DIR__ . '/module_common.php';

if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid application reference.</div>';
    exit;
}

if (!saTableExists($conn, 'franchise_applications')) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Franchise applications table is unavailable.</div>';
    exit;
}

$app_id = (int)$_GET['id'];

// Get application details
$app_query = "SELECT * FROM franchise_applications WHERE id = ?";
$stmt = mysqli_prepare($conn, $app_query);
if (!$stmt) {
    http_response_code(500);
    echo '<div class="alert alert-danger">Unable to fetch application details right now.</div>';
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $app_id);
mysqli_stmt_execute($stmt);
$app_result = mysqli_stmt_get_result($stmt);
$app = mysqli_fetch_assoc($app_result);
mysqli_stmt_close($stmt);

if (!$app) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Application not found.</div>';
    exit;
}

// Get documents
$docs_result = false;
if (saTableExists($conn, 'franchise_documents')) {
    $docs_query = "SELECT * FROM franchise_documents WHERE application_id = ?";
    $stmt = mysqli_prepare($conn, $docs_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $app_id);
        mysqli_stmt_execute($stmt);
        $docs_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    }
}

// Get applicant info
$user = null;
if (saTableExists($conn, 'users')) {
    $user_query = "SELECT * FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $app['user_id']);
        mysqli_stmt_execute($stmt);
        $user_result = mysqli_stmt_get_result($stmt);
        $user = $user_result ? mysqli_fetch_assoc($user_result) : null;
        mysqli_stmt_close($stmt);
    }
}

$applicant_attempts = 0;
$approved_attempts = 0;
$rejected_attempts = 0;
$next_reapply_at = null;
if (saTableExists($conn, 'franchise_applications')) {
    $attempt_stmt = mysqli_prepare(
        $conn,
        "SELECT status, created_at, reviewed_at
         FROM franchise_applications
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC"
    );
    if ($attempt_stmt) {
        mysqli_stmt_bind_param($attempt_stmt, "i", $app['user_id']);
        mysqli_stmt_execute($attempt_stmt);
        $attempt_result = mysqli_stmt_get_result($attempt_stmt);
        $attempt_rows = [];
        while ($attempt_result && ($attempt_row = mysqli_fetch_assoc($attempt_result))) {
            $attempt_rows[] = $attempt_row;
        }
        mysqli_stmt_close($attempt_stmt);

        $applicant_attempts = count($attempt_rows);
        foreach ($attempt_rows as $attempt_row) {
            $attempt_status = strtolower(trim((string)($attempt_row['status'] ?? '')));
            if ($attempt_status === 'approved') {
                $approved_attempts++;
            } elseif ($attempt_status === 'rejected') {
                $rejected_attempts++;
            }
        }

        $latest_attempt = $attempt_rows[0] ?? null;
        if ($latest_attempt && strtolower(trim((string)($latest_attempt['status'] ?? ''))) === 'rejected') {
            $cooldown_anchor = strtotime((string)($latest_attempt['reviewed_at'] ?? $latest_attempt['created_at'] ?? 'now'));
            $next_reapply_at = date('M d, Y h:i A', strtotime('+3 days', $cooldown_anchor));
        }
    }
}
?>

<div class="application-details">
    <div class="app-header">
        <h4><?php echo htmlspecialchars($app['application_number']); ?></h4>
        <?php
            $app_status = strtolower(trim((string)($app['status'] ?? '')));
            $app_status_chip = 'chip-muted';
            if ($app_status === 'approved') {
                $app_status_chip = 'chip-success';
            } elseif ($app_status === 'pending') {
                $app_status_chip = 'chip-warning';
            } elseif ($app_status === 'rejected') {
                $app_status_chip = 'chip-danger';
            }
        ?>
        <span class="status-chip <?php echo $app_status_chip; ?>"><?php echo htmlspecialchars(ucfirst($app_status !== '' ? $app_status : 'unknown')); ?></span>
    </div>
    
    <div class="app-info-grid">
        <div class="info-section">
            <h6>Applicant Information</h6>
            <p><strong>Name:</strong> <?php echo htmlspecialchars((string)($user['full_name'] ?? 'Unknown')); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars((string)($user['email'] ?? 'N/A')); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars((string)($user['phone'] ?? 'N/A')); ?></p>
        </div>
        
        <div class="info-section">
            <h6>Business Information</h6>
            <p><strong>Business Name:</strong> <?php echo htmlspecialchars($app['business_name']); ?></p>
            <p><strong>Business Type:</strong> <?php echo ucfirst($app['business_type']); ?></p>
            <p><strong>TIN:</strong> <?php echo htmlspecialchars($app['tin_number']); ?></p>
        </div>
    </div>

    <div class="app-section">
        <h6>Application Workflow Status</h6>
        <div class="detail-row">
            <span>Total Attempts:</span>
            <p><?php echo (int)$applicant_attempts; ?> / 2</p>
        </div>
        <div class="detail-row">
            <span>Approved Attempts:</span>
            <p><?php echo (int)$approved_attempts; ?></p>
        </div>
        <div class="detail-row">
            <span>Rejected Attempts:</span>
            <p><?php echo (int)$rejected_attempts; ?></p>
        </div>
        <?php if ($next_reapply_at !== null): ?>
        <div class="detail-row">
            <span>Cooldown Ends:</span>
            <p><?php echo htmlspecialchars($next_reapply_at); ?></p>
        </div>
        <?php endif; ?>
        <div class="detail-row">
            <span>Operational Rule:</span>
            <p>Applicants get up to 2 total submissions. Rejected applicants wait 3 days before the final retry. Approved partners enter a 1-month trial and should not re-register.</p>
        </div>
    </div>
    
    <div class="app-section">
        <h6>Business Details</h6>
        <div class="detail-row">
            <span>Business Address:</span>
            <p><?php echo nl2br(htmlspecialchars($app['business_address'])); ?></p>
        </div>
        <?php
            $psgc_parts = array_filter([
                trim((string)($app['barangay_name'] ?? '')),
                trim((string)($app['city_name'] ?? '')),
                trim((string)($app['province_name'] ?? '')),
                trim((string)($app['region_name'] ?? ''))
            ], function ($value) {
                return $value !== '';
            });
            $psgc_location_line = implode(', ', $psgc_parts);
        ?>
        <?php if ($psgc_location_line !== ''): ?>
        <div class="detail-row">
            <span>PSGC Location:</span>
            <p><?php echo htmlspecialchars($psgc_location_line); ?></p>
        </div>
        <?php endif; ?>
        <div class="detail-row">
            <span>Capital Investment:</span>
            <p>PHP <?php echo number_format((float)($app['capital_investment'] ?? 0), 2); ?></p>
        </div>
        <div class="detail-row">
            <span>Business Experience:</span>
            <p><?php echo nl2br(htmlspecialchars($app['business_experience'])); ?></p>
        </div>
        <div class="detail-row">
            <span>Marketing Plan:</span>
            <p><?php echo nl2br(htmlspecialchars($app['marketing_plan'])); ?></p>
        </div>
    </div>
    
    <div class="app-documents">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h6 style="margin:0;">Submitted Documents (<?php echo $docs_result ? mysqli_num_rows($docs_result) : 0; ?>)</h6>
            <?php if ($app['status'] === 'pending' || $app['status'] === 'incomplete'): ?>
                <small class="text-muted"><i class="fas fa-info-circle"></i> Check individual documents that need re-upload (e.g. blurry image)</small>
            <?php endif; ?>
        </div>
        <div class="documents-list">
            <?php
            $doc_types = [
                'business_logo' => 'Business Logo',
                'dti_doc' => 'DTI Document',
                'bir_doc' => 'BIR Document',
                'mayor_doc' => "Mayor's Permit",
                'barangay_clearance' => 'Barangay Clearance',
                'lease_or_title' => 'Lease Contract/Title',
                'occupancy_certificate' => 'Certificate of Occupancy',
                'fire_safety_certificate' => 'Fire Safety Certificate',
                'community_tax_certificate' => 'Community Tax Certificate',
                'sanitary_permit' => 'Sanitary Permit',
                'valid_id' => 'Valid ID',
                'address_proof' => 'Address Proof',
                'bank_proof' => 'Bank Proof',
                'bir_form' => 'BIR Form 1901/1903',
                'sss_registration' => 'SSS Registration',
                'philhealth_registration' => 'PhilHealth Registration',
                'pagibig_registration' => 'Pag-IBIG Registration',
                'industry_permit' => 'Industry-Specific Permit'
            ];

            $incomplete_map = [];
            if (!empty($app['incomplete_documents'])) {
                $decoded_inc = json_decode((string)$app['incomplete_documents'], true);
                if (is_array($decoded_inc)) {
                    $incomplete_map = $decoded_inc;
                }
            }
            
            if ($docs_result && mysqli_num_rows($docs_result) > 0) {
                mysqli_data_seek($docs_result, 0);
                while ($doc = mysqli_fetch_assoc($docs_result)) {
                    $doc_type_key = (string)($doc['document_type'] ?? '');
                    $doc_type_name = $doc_types[$doc_type_key] ?? $doc_type_key;
                    $document_url = '../' . ltrim((string)($doc['file_path'] ?? ''), '/');
                    $document_name = (string)($doc['file_name'] ?? $doc_type_name);
                    $document_extension = strtolower(pathinfo($document_name, PATHINFO_EXTENSION));
                    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                    $document_icon = in_array($document_extension, $image_extensions, true) ? 'fa-file-image' : 'fa-file-pdf';
                    $doc_status = strtolower(trim((string)($doc['status'] ?? 'pending')));
                    $is_flagged = ($doc_status === 'incomplete' || isset($incomplete_map[$doc_type_key]));
                    $doc_remark = (string)($doc['admin_remarks'] ?? $incomplete_map[$doc_type_key] ?? '');
                    ?>
                    <div class="doc-card-item <?php echo $is_flagged ? 'is-flagged' : ''; ?>" id="doc_card_<?php echo htmlspecialchars($doc_type_key); ?>">
                        <div class="doc-card-header">
                            <div class="doc-title-wrap">
                                <i class="fas <?php echo $document_icon; ?> doc-type-icon"></i>
                                <div>
                                    <span class="doc-type-title"><?php echo htmlspecialchars($doc_type_name); ?></span>
                                    <small class="doc-filename text-muted d-block"><?php echo htmlspecialchars(basename($document_name)); ?></small>
                                </div>
                            </div>
                            <div class="doc-actions-wrap">
                                <?php if ($is_flagged): ?>
                                    <span class="status-chip chip-danger" style="font-size:11px;padding:3px 8px;"><i class="fas fa-exclamation-circle"></i> Incomplete</span>
                                <?php elseif ($doc_status === 'approved'): ?>
                                    <span class="status-chip chip-success" style="font-size:11px;padding:3px 8px;"><i class="fas fa-check"></i> Verified</span>
                                <?php else: ?>
                                    <span class="status-chip chip-muted" style="font-size:11px;padding:3px 8px;"><i class="fas fa-file"></i> Submitted</span>
                                <?php endif; ?>

                                <button type="button"
                                        class="btn btn-sm btn-outline-primary doc-preview-button"
                                        data-preview-url="<?php echo htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-preview-title="<?php echo htmlspecialchars($doc_type_name, ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openDocumentPreview(this)"
                                        title="Preview Document">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                            </div>
                        </div>

                        <?php if ($app['status'] === 'pending' || $app['status'] === 'incomplete'): ?>
                        <div class="doc-flag-section">
                            <label class="doc-flag-label">
                                <input type="checkbox"
                                       name="flagged_docs[]"
                                       value="<?php echo htmlspecialchars($doc_type_key); ?>"
                                       form="appForm"
                                       class="doc-flag-checkbox"
                                       data-doc-name="<?php echo htmlspecialchars($doc_type_name); ?>"
                                       onchange="toggleDocFlagRemark(this, '<?php echo htmlspecialchars($doc_type_key); ?>')"
                                       <?php echo ($is_flagged ? 'checked' : ''); ?>>
                                <span class="doc-flag-text"><i class="fas fa-flag"></i> Mark for Re-upload (e.g. Blurry, Cut-off, Expired)</span>
                            </label>
                            <div class="doc-flag-remark-box" id="flag_remark_wrap_<?php echo htmlspecialchars($doc_type_key); ?>" style="<?php echo ($is_flagged ? '' : 'display:none;'); ?>">
                                <input type="text"
                                       name="doc_remarks[<?php echo htmlspecialchars($doc_type_key); ?>]"
                                       form="appForm"
                                       id="doc_remark_input_<?php echo htmlspecialchars($doc_type_key); ?>"
                                       class="form-control form-control-sm doc-remark-input"
                                       placeholder="Specific issue (e.g. Image is blurry, please upload clear copy)"
                                       value="<?php echo htmlspecialchars($doc_remark); ?>">
                                <div class="quick-reasons-row">
                                    <button type="button" class="btn btn-xs btn-outline-secondary reason-btn" onclick="setDocRemark('<?php echo htmlspecialchars($doc_type_key); ?>', 'Image is blurry / unreadable')">Blurry Image</button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary reason-btn" onclick="setDocRemark('<?php echo htmlspecialchars($doc_type_key); ?>', 'Document is expired')">Expired</button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary reason-btn" onclick="setDocRemark('<?php echo htmlspecialchars($doc_type_key); ?>', 'Incomplete pages / cut off')">Cut Off</button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary reason-btn" onclick="setDocRemark('<?php echo htmlspecialchars($doc_type_key); ?>', 'Name / details do not match')">Mismatched</button>
                                </div>
                            </div>
                        </div>
                        <?php elseif (!empty($doc_remark)): ?>
                        <div class="doc-past-remark">
                            <i class="fas fa-info-circle text-warning"></i> <strong>Admin Note:</strong> <?php echo htmlspecialchars($doc_remark); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
            } else {
                echo "<div class='text-muted' style='padding:12px;background:#f8f9fa;border-radius:8px;'>No documents attached to this application.</div>";
            }
            ?>
        </div>
    </div>
    
    <?php if ($app['status'] === 'pending' || $app['status'] === 'incomplete'): ?>
        <div class="app-actions">
            <form method="POST" action="../super_admin/franchise_applications.php" id="appForm">
                <input type="hidden" name="app_id" value="<?php echo $app_id; ?>">
                <input type="hidden" name="app_action" id="app_action" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <label>Overall Admin Feedback / Instructions to Applicant</label>
                    <textarea name="admin_notes" id="admin_notes" class="form-control" rows="3" placeholder="Add overall notes or instructions for the applicant..."><?php echo htmlspecialchars($app['admin_notes'] ?? ''); ?></textarea>
                    <small class="text-muted d-block mt-1">If you flag specific documents above and click <strong>Mark Incomplete</strong>, the applicant will only be prompted to re-upload the flagged files without losing their other data.</small>
                </div>
                
                <div class="action-buttons" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit"
                            name="app_action"
                            value="approve"
                            class="btn btn-outline-success"
                            data-sa-confirm="1"
                            data-sa-confirm-title="Approve Application?"
                            data-sa-confirm-text="This will approve this business application, notify the client via email, and grant partner access controls."
                            data-sa-confirm-confirm-text="Yes, Approve"
                            data-sa-confirm-confirm-color="#166534">
                        <i class="fas fa-check"></i> Approve Application
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="handleIncomplete('<?php echo htmlspecialchars($app['application_number']); ?>')">
                        <i class="fas fa-exclamation-triangle"></i> Mark Incomplete
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="handleReject('<?php echo htmlspecialchars($app['application_number']); ?>')">
                        <i class="fas fa-times"></i> Reject Application
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="app-notes">
            <h6>Admin Notes</h6>
            <p><?php echo nl2br(htmlspecialchars($app['admin_notes'] ?? 'No notes')); ?></p>
            <small>
                Reviewed by: <?php echo htmlspecialchars((string)($app['admin_id'] ?? 'N/A')); ?>
                on <?php echo htmlspecialchars(saFormatDateTime($app['reviewed_at'] ?? null, 'M d, Y H:i', 'N/A')); ?>
            </small>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleDocFlagRemark(checkbox, docType) {
    const wrap = document.getElementById('flag_remark_wrap_' + docType);
    const card = document.getElementById('doc_card_' + docType);
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
        if (checkbox.checked) {
            const input = document.getElementById('doc_remark_input_' + docType);
            if (input && input.value.trim() === '') {
                input.value = 'Image is blurry / please re-upload clear copy';
            }
        }
    }
    if (card) {
        if (checkbox.checked) {
            card.classList.add('is-flagged');
        } else {
            card.classList.remove('is-flagged');
        }
    }
}

function setDocRemark(docType, text) {
    const input = document.getElementById('doc_remark_input_' + docType);
    if (input) {
        input.value = text;
        input.focus();
    }
}
</script>

<style>
.application-details {
    padding: 20px 0;
}
.app-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #eee;
}
.app-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.info-section h6 {
    font-weight: 600;
    margin-bottom: 12px;
    color: #333;
}
.info-section p {
    margin: 6px 0;
    font-size: 14px;
}
.app-section {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}
.app-section h6 {
    font-weight: 600;
    margin-bottom: 12px;
    color: #333;
}
.detail-row {
    margin-bottom: 12px;
}
.detail-row span {
    font-weight: 600;
    color: #666;
}
.detail-row p {
    margin: 4px 0 0 0;
    color: #333;
    font-size: 14px;
}
.documents-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.doc-card-item {
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
}
.doc-card-item.is-flagged {
    border-color: #f59e0b;
    background: #fffdf5;
}
.doc-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.doc-title-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
.doc-type-icon {
    color: #b3261e;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.doc-type-title {
    font-weight: 600;
    color: #101828;
}
.doc-filename {
    font-size: 12px;
}
.doc-actions-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.doc-flag-section {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #eaecf0;
}
.doc-flag-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    color: #b3261e;
    margin-bottom: 4px;
    user-select: none;
}
.doc-flag-checkbox {
    width: 16px;
    height: 16px;
    accent-color: #b3261e;
    cursor: pointer;
}
.doc-flag-text {
    display: flex;
    align-items: center;
    gap: 5px;
}
.doc-flag-remark-box {
    margin-top: 6px;
    padding-left: 24px;
}
.quick-reasons-row {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 5px;
}
.reason-btn {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    background: #ffffff;
    border: 1px solid #d0d5dd;
    color: #344054;
    cursor: pointer;
}
.reason-btn:hover {
    background: #f2f4f7;
    border-color: #98a2b3;
    color: #101828;
}
.doc-past-remark {
    margin-top: 8px;
    padding: 6px 10px;
    background: #fffaeb;
    border-radius: 6px;
    font-size: 12px;
    color: #b54708;
    border: 1px solid #fedf89;
}
.doc-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: #f5f5f5;
    border-radius: 4px;
    font-size: 14px;
}
.doc-item i {
    color: #dc3545;
}
.doc-item .doc-preview-button {
    margin-left: auto;
}
.document-preview-controls {
    display: flex;
    gap: 4px;
    margin-left: auto;
    margin-right: 12px;
}
.document-preview-controls[hidden] {
    display: none;
}
.document-preview-body {
    min-height: 70vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    overflow: auto;
}
.document-preview-image {
    display: block;
    max-width: 100%;
    max-height: 68vh;
    height: auto;
    object-fit: contain;
    transform-origin: center center;
    transition: transform 150ms ease;
}
.document-preview-frame {
    width: 100%;
    height: 68vh;
    border: 0;
    background: #fff;
}
.app-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}
.form-group {
    margin-bottom: 15px;
}
.action-buttons {
    display: flex;
    gap: 10px;
}
.action-buttons .btn {
    flex: 1;
}
</style>
