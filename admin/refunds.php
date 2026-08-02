<?php
session_start();
require_once 'auth.php';
require_once '../includes/config.php';
require_once '../includes/security.php';

checkAdminAccess();
if (!empty($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
    requireAnyPermission(['finance.view', 'finance.manage']);
}

$admin_info = getAdminInfo($conn);
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Management | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-row .form-label { font-size: 0.85rem; margin-bottom: 0.25rem; }
        .refund-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 999px; }
        .refund-pending { background: #fff3cd; color: #856404; }
        .refund-approved { background: #d1e7dd; color: #0f5132; }
        .refund-rejected { background: #f8d7da; color: #842029; }
        .refund-completed { background: #cff4fc; color: #055160; }
        .table td, .table th { vertical-align: middle; }
    </style>
</head>
<body>
    <div class="page-loader"><div class="spinner"></div></div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Refund Management</h1>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name'] ?? 'Admin'); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-main">
                <div class="card mb-3">
                    <div class="card-body">
                        <form id="filterForm" class="row g-2 filter-row">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All</option>
                                    <option value="Refund Pending">Refund Pending</option>
                                    <option value="Refund Approved">Refund Approved</option>
                                    <option value="Refund Rejected">Refund Rejected</option>
                                    <option value="Refund Completed">Refund Completed</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">User ID</label>
                                <input type="number" min="1" name="user_id" class="form-control form-control-sm" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control form-control-sm" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control form-control-sm" />
                            </div>
                            <div class="col-md-3 d-flex align-items-end gap-2">
                                <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" id="resetFilters"><i class="fas fa-rotate-left"></i> Reset</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm" id="refundTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Customer</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Rule</th>
                                        <th>Requested</th>
                                        <th>Remarks</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
    <script>
    const csrfToken = <?php echo json_encode($csrf); ?>;
    const apiUrl = '../api/refund_admin.php';
    const tbody = document.querySelector('#refundTable tbody');
    const filterForm = document.getElementById('filterForm');

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getReference(row) {
        if (row.order_number) return `Order #${escapeHtml(row.order_number)}`;
        if (row.order_id) return `Order #${escapeHtml(row.order_id)}`;
        if (row.reservation_id) return `Pre-Order #${escapeHtml(row.reservation_id)}`;
        if (row.service_request_id) return `Service #${escapeHtml(row.service_request_id)}`;
        return '-';
    }

    function badgeClass(status) {
        if (status === 'Refund Approved') return 'refund-approved';
        if (status === 'Refund Rejected') return 'refund-rejected';
        if (status === 'Refund Completed') return 'refund-completed';
        return 'refund-pending';
    }

    function actionsForStatus(status, refundId) {
        if (status === 'Refund Pending') {
            return `
                <button class="btn btn-sm btn-success me-1" data-act="approve" data-id="${refundId}">Approve</button>
                <button class="btn btn-sm btn-danger" data-act="reject" data-id="${refundId}">Reject</button>
            `;
        }
        if (status === 'Refund Approved') {
            return `
                <button class="btn btn-sm btn-info me-1" data-act="complete" data-id="${refundId}">Complete</button>
                <button class="btn btn-sm btn-danger" data-act="reject" data-id="${refundId}">Reject</button>
            `;
        }
        return '<span class="text-muted">No actions</span>';
    }

    function showRefundSwal(message, type = 'info') {
        if (typeof Swal !== 'undefined') {
            const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
            Swal.fire({
                icon: iconMap[type] || 'info',
                title: type === 'success' ? 'Success' : (type === 'error' ? 'Error' : 'Notice'),
                text: message,
                confirmButtonColor: '#3085d6'
            });
            return;
        }
        console.log('[Refunds][' + type + ']', message);
    }

    async function requestRemarks(action) {
        if (typeof Swal === 'undefined') {
            return { confirmed: false, remarks: '' };
        }

        const isReject = action === 'reject';
        const result = await Swal.fire({
            title: isReject ? 'Reject refund request' : 'Add remarks',
            text: isReject ? 'Please provide a rejection reason.' : 'Remarks are optional for this action.',
            input: 'textarea',
            inputPlaceholder: isReject ? 'Type rejection reason...' : 'Type optional remarks...',
            showCancelButton: true,
            confirmButtonText: isReject ? 'Reject refund' : 'Continue',
            cancelButtonText: 'Cancel',
            confirmButtonColor: isReject ? '#d33' : '#3085d6',
            cancelButtonColor: '#6c757d',
            inputValidator: (value) => {
                if (isReject && !String(value || '').trim()) {
                    return 'Rejection reason is required.';
                }
                return null;
            }
        });

        return {
            confirmed: !!(result && result.isConfirmed),
            remarks: result && typeof result.value !== 'undefined' && result.value !== null
                ? String(result.value).trim()
                : ''
        };
    }

    async function loadRefunds() {
        const params = new URLSearchParams(new FormData(filterForm));
        const res = await fetch(`${apiUrl}?${params.toString()}`, { credentials: 'same-origin' });
        const j = await res.json();

        tbody.innerHTML = '';
        if (!j.success) {
            showRefundSwal(j.error || 'Failed to load refunds.', 'error');
            return;
        }

        if (!j.data || j.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No refund records found.</td></tr>';
            return;
        }

        for (const r of j.data) {
            const amount = Number.parseFloat(r.refund_amount || 0);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(r.refund_id)}</td>
                <td>${escapeHtml(r.customer_name || ('User #' + r.user_id))}</td>
                <td>${getReference(r)}</td>
                <td>${escapeHtml(r.currency || 'PHP')} ${amount.toFixed(2)}</td>
                <td><span class="refund-badge ${badgeClass(r.refund_status)}">${escapeHtml(r.refund_status)}</span></td>
                <td>${escapeHtml(r.computed_rule || '')} ${r.percentage ? '(' + escapeHtml(r.percentage) + '%)' : ''}</td>
                <td>${escapeHtml(r.cancellation_date || '')}</td>
                <td>${escapeHtml(r.remarks || '-')}</td>
                <td>${actionsForStatus(r.refund_status, r.refund_id)}</td>
            `;
            tbody.appendChild(tr);
        }
    }

    async function submitRefundAction(action, refundId) {
        const remarkResult = await requestRemarks(action);
        if (!remarkResult.confirmed) {
            return;
        }
        const remarks = remarkResult.remarks;

        const fd = new FormData();
        fd.append('action', action);
        fd.append('refund_id', refundId);
        fd.append('remarks', remarks);
        fd.append('csrf_token', csrfToken);

        const res = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();

        if (!j.success) {
            showRefundSwal(j.error || 'Unable to process refund action.', 'error');
            return;
        }

        showRefundSwal('Refund action processed successfully.', 'success');
        loadRefunds();
    }

    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        loadRefunds();
    });

    document.getElementById('resetFilters').addEventListener('click', function() {
        filterForm.reset();
        loadRefunds();
    });

    document.addEventListener('click', function(e) {
        const button = e.target.closest('button[data-act]');
        if (!button) return;
        const action = button.getAttribute('data-act');
        const refundId = button.getAttribute('data-id');
        submitRefundAction(action, refundId);
    });

    loadRefunds();
    </script>
</body>
</html>
