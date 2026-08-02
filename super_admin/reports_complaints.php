<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'reports_complaints.php';
$has_conversations = saTableExists($conn, 'chat_conversations');
$has_messages = saTableExists($conn, 'chat_messages');
$has_users = saTableExists($conn, 'users');

if (!function_exists('saComplaintCategoryLabel')) {
    function saComplaintCategoryLabel($subject, $conversation_type = '', $entity_type = '') {
        $subject_text = strtolower(trim((string)$subject));
        $conversation_type = strtolower(trim((string)$conversation_type));
        $entity_type = strtolower(trim((string)$entity_type));

        if (strpos($subject_text, '[abuse]') === 0 || strpos($subject_text, 'abuse') !== false || strpos($subject_text, 'fraud') !== false || strpos($subject_text, 'scam') !== false) {
            return 'abuse';
        }
        if (strpos($subject_text, '[technical]') === 0 || strpos($subject_text, 'technical') !== false || strpos($subject_text, 'bug') !== false || strpos($subject_text, 'error') !== false || strpos($subject_text, 'system') !== false) {
            return 'technical';
        }
        if ($entity_type === 'order' || $conversation_type === 'refund_inquiry' || strpos($subject_text, '[business]') === 0 || strpos($subject_text, 'business') !== false || strpos($subject_text, 'order') !== false || strpos($subject_text, 'payment') !== false) {
            return 'business-related';
        }
        return 'general';
    }
}

if (!function_exists('saComplaintStatusChip')) {
    function saComplaintStatusChip($status) {
        $status = strtolower(trim((string)$status));
        if ($status === 'resolved' || $status === 'closed') {
            return 'chip-success';
        }
        if ($status === 'in_progress') {
            return 'chip-warning';
        }
        if ($status === 'open') {
            return 'chip-danger';
        }
        return 'chip-muted';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'create_complaint') {
        if (!$has_conversations || !$has_users) {
            saSetFlash('danger', 'Complaint creation is unavailable because required chat tables are missing.');
        } else {
            $target_user_id = (int)($_POST['target_user_id'] ?? 0);
            $category = strtolower(trim((string)($_POST['category'] ?? 'general')));
            $priority = strtolower(trim((string)($_POST['priority'] ?? 'medium')));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $initial_message = trim((string)($_POST['initial_message'] ?? ''));

            $allowed_categories = ['technical', 'business-related', 'abuse', 'general'];
            $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($category, $allowed_categories, true)) {
                $category = 'general';
            }
            if (!in_array($priority, $allowed_priorities, true)) {
                $priority = 'medium';
            }

            if ($target_user_id <= 0 || $subject === '') {
                saSetFlash('danger', 'Please select a target user and provide a complaint subject.');
            } else {
                $user_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? LIMIT 1");
                if (!$user_stmt) {
                    saSetFlash('danger', 'Unable to validate selected user.');
                } else {
                    mysqli_stmt_bind_param($user_stmt, "i", $target_user_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    $user_exists = $user_result && mysqli_fetch_assoc($user_result);
                    mysqli_stmt_close($user_stmt);

                    if (!$user_exists) {
                        saSetFlash('danger', 'Selected user does not exist.');
                    } else {
                        $subject_prefix = '[' . strtoupper(str_replace('-', ' ', $category)) . '] ';
                        $full_subject = substr($subject_prefix . $subject, 0, 255);
                        $entity_type = $category === 'business-related' ? 'order' : 'general';

                        $insert_sql = "INSERT INTO chat_conversations
                                       (customer_id, assigned_agent_id, entity_type, conversation_type, subject, status, priority, first_message_time, last_message_time, created_at, updated_at)
                                       VALUES (?, ?, ?, 'complaint', ?, 'open', ?, NOW(), NOW(), NOW(), NOW())";
                        $insert_stmt = mysqli_prepare($conn, $insert_sql);
                        if (!$insert_stmt) {
                            saSetFlash('danger', 'Unable to create complaint record.');
                        } else {
                            mysqli_stmt_bind_param($insert_stmt, "iisss", $target_user_id, $current_admin_id, $entity_type, $full_subject, $priority);
                            $ok = mysqli_stmt_execute($insert_stmt);
                            $conversation_id = $ok ? (int)mysqli_insert_id($conn) : 0;
                            mysqli_stmt_close($insert_stmt);

                            if ($ok && $conversation_id > 0) {
                                if ($has_messages && $initial_message !== '') {
                                    $message_stmt = mysqli_prepare(
                                        $conn,
                                        "INSERT INTO chat_messages
                                         (conversation_id, sender_id, sender_type, message_text, message_type, created_at, updated_at)
                                         VALUES (?, ?, 'agent', ?, 'text', NOW(), NOW())"
                                    );
                                    if ($message_stmt) {
                                        mysqli_stmt_bind_param($message_stmt, "iis", $conversation_id, $current_admin_id, $initial_message);
                                        mysqli_stmt_execute($message_stmt);
                                        mysqli_stmt_close($message_stmt);
                                    }
                                }

                                saSetFlash('success', 'Complaint has been created successfully.');
                                saLogAudit(
                                    $conn,
                                    $current_admin_id,
                                    'COMPLAINT_CREATED',
                                    'super_admin_complaints',
                                    "Created complaint conversation #{$conversation_id} for user #{$target_user_id}."
                                );
                            } else {
                                saSetFlash('danger', 'Failed to create complaint conversation.');
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'update_complaint') {
        if (!$has_conversations) {
            saSetFlash('danger', 'Complaint status updates are unavailable.');
        } else {
            $conversation_id = (int)($_POST['conversation_id'] ?? 0);
            $status = strtolower(trim((string)($_POST['status'] ?? 'open')));
            $priority = strtolower(trim((string)($_POST['priority'] ?? 'medium')));
            $allowed_statuses = ['open', 'in_progress', 'resolved', 'closed'];
            $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($status, $allowed_statuses, true) || !in_array($priority, $allowed_priorities, true) || $conversation_id <= 0) {
                saSetFlash('danger', 'Invalid complaint update request.');
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE chat_conversations
                     SET status = ?, priority = ?, updated_at = NOW()
                     WHERE id = ? LIMIT 1"
                );
                if (!$stmt) {
                    saSetFlash('danger', 'Unable to prepare complaint update.');
                } else {
                    mysqli_stmt_bind_param($stmt, "ssi", $status, $priority, $conversation_id);
                    $ok = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    if ($ok) {
                        saSetFlash('success', 'Complaint status updated.');
                        saLogAudit(
                            $conn,
                            $current_admin_id,
                            'COMPLAINT_UPDATED',
                            'super_admin_complaints',
                            "Updated complaint #{$conversation_id} to status {$status} / priority {$priority}."
                        );
                    } else {
                        saSetFlash('danger', 'Unable to update complaint.');
                    }
                }
            }
        }
    } elseif ($action === 'respond_complaint') {
        if (!$has_messages || !$has_conversations) {
            saSetFlash('danger', 'Complaint response is unavailable because message tables are missing.');
        } else {
            $conversation_id = (int)($_POST['conversation_id'] ?? 0);
            $response_message = trim((string)($_POST['response_message'] ?? ''));
            if ($conversation_id <= 0 || $response_message === '') {
                saSetFlash('danger', 'Please provide a valid complaint response message.');
            } else {
                $insert_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO chat_messages
                     (conversation_id, sender_id, sender_type, message_text, message_type, created_at, updated_at)
                     VALUES (?, ?, 'agent', ?, 'text', NOW(), NOW())"
                );
                if (!$insert_stmt) {
                    saSetFlash('danger', 'Unable to prepare complaint response.');
                } else {
                    mysqli_stmt_bind_param($insert_stmt, "iis", $conversation_id, $current_admin_id, $response_message);
                    $ok = mysqli_stmt_execute($insert_stmt);
                    mysqli_stmt_close($insert_stmt);

                    if ($ok) {
                        $touch_stmt = mysqli_prepare($conn, "UPDATE chat_conversations SET last_message_time = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1");
                        if ($touch_stmt) {
                            mysqli_stmt_bind_param($touch_stmt, "i", $conversation_id);
                            mysqli_stmt_execute($touch_stmt);
                            mysqli_stmt_close($touch_stmt);
                        }

                        saSetFlash('success', 'Complaint response sent successfully.');
                        saLogAudit(
                            $conn,
                            $current_admin_id,
                            'COMPLAINT_RESPONDED',
                            'super_admin_complaints',
                            "Sent complaint response to conversation #{$conversation_id}."
                        );
                    } else {
                        saSetFlash('danger', 'Unable to send complaint response.');
                    }
                }
            }
        }
    } else {
        saSetFlash('warning', 'Unsupported complaint action.');
    }

    header('Location: ' . $page_file);
    exit;
}

$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$category_filter = strtolower(trim((string)($_GET['category'] ?? 'all')));

$complaints = [];
if ($has_conversations) {
    $where = ["(c.conversation_type = 'complaint' OR c.subject LIKE '%complaint%')"];
    if (in_array($status_filter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        $safe_status = mysqli_real_escape_string($conn, $status_filter);
        $where[] = "c.status = '{$safe_status}'";
    }

    $complaints = saQueryRows(
        $conn,
        "SELECT c.id, c.customer_id, c.assigned_agent_id, c.entity_type, c.conversation_type, c.subject, c.status, c.priority,
                c.created_at, c.updated_at, c.last_message_time,
                u.full_name AS customer_name, u.email AS customer_email,
                (SELECT m.message_text FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS latest_message
         FROM chat_conversations c
         LEFT JOIN users u ON u.id = c.customer_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY FIELD(c.status, 'open', 'in_progress', 'resolved', 'closed'), c.updated_at DESC
         LIMIT 200"
    );
}

$category_counts = [
    'technical' => 0,
    'business-related' => 0,
    'abuse' => 0,
    'general' => 0
];
$status_counts = [
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'closed' => 0
];

foreach ($complaints as &$complaint) {
    $category = saComplaintCategoryLabel($complaint['subject'] ?? '', $complaint['conversation_type'] ?? '', $complaint['entity_type'] ?? '');
    $complaint['detected_category'] = $category;
    if (isset($category_counts[$category])) {
        $category_counts[$category]++;
    }
    $status = strtolower(trim((string)($complaint['status'] ?? 'open')));
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}
unset($complaint);

if ($category_filter !== 'all') {
    $complaints = array_values(array_filter($complaints, function ($item) use ($category_filter) {
        return ($item['detected_category'] ?? 'general') === $category_filter;
    }));
}

$recipient_options = [];
if ($has_users) {
    $recipient_options = saQueryRows(
        $conn,
        "SELECT id, full_name, email, account_type
         FROM users
         ORDER BY created_at DESC
         LIMIT 120"
    );
}

saRenderModuleHeader('Reports & Complaints', 'Reports & Complaints', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Complaint Monitoring Overview</h2>
            <p class="module-subtext">Track issues, categorize concerns, monitor status, and respond directly from super admin.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Open Complaints</span>
            <div class="metric-value"><?php echo number_format($status_counts['open'] + $status_counts['in_progress']); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Resolved / Closed</span>
            <div class="metric-value"><?php echo number_format($status_counts['resolved'] + $status_counts['closed']); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Technical Issues</span>
            <div class="metric-value"><?php echo number_format($category_counts['technical']); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Abuse Reports</span>
            <div class="metric-value"><?php echo number_format($category_counts['abuse']); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Submit Complaint Record</h3>
            <p class="module-subtext">Create and register complaint entries for monitoring and follow-up.</p>
        </div>
    </div>
    <?php if (!$has_conversations || !$has_users): ?>
        <div class="note-box">Complaint submission requires `chat_conversations` and `users` tables.</div>
    <?php else: ?>
        <form method="POST" class="module-form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="create_complaint">
            <select name="target_user_id" class="form-select" required>
                <option value="">Select User / Business</option>
                <?php foreach ($recipient_options as $recipient): ?>
                    <option value="<?php echo (int)$recipient['id']; ?>">
                        <?php echo htmlspecialchars((string)$recipient['full_name']); ?> (<?php echo htmlspecialchars((string)$recipient['email']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="category" class="form-select">
                <option value="technical">Technical</option>
                <option value="business-related">Business-Related</option>
                <option value="abuse">Abuse</option>
                <option value="general">General</option>
            </select>
            <select name="priority" class="form-select">
                <option value="medium">Medium</option>
                <option value="low">Low</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </select>
            <input type="text" name="subject" class="form-control" placeholder="Complaint subject" required>
            <input type="text" name="initial_message" class="form-control" placeholder="Initial response (optional)">
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Complaint</button>
        </form>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Complaint Queue</h3>
            <p class="module-subtext">Categorize, update status, and respond to user/business complaints.</p>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <select name="status" class="form-select">
            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
        </select>
        <select name="category" class="form-select">
            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
            <option value="technical" <?php echo $category_filter === 'technical' ? 'selected' : ''; ?>>Technical</option>
            <option value="business-related" <?php echo $category_filter === 'business-related' ? 'selected' : ''; ?>>Business-Related</option>
            <option value="abuse" <?php echo $category_filter === 'abuse' ? 'selected' : ''; ?>>Abuse</option>
            <option value="general" <?php echo $category_filter === 'general' ? 'selected' : ''; ?>>General</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
    </form>

    <?php if (!$has_conversations): ?>
        <div class="note-box">`chat_conversations` table is unavailable.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Complaint</th>
                        <th>Category</th>
                        <th>Status Tracking</th>
                        <th>Latest Message</th>
                        <th>Admin Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($complaints)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No complaint records match your filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo (int)$complaint['id']; ?> · <?php echo htmlspecialchars((string)$complaint['subject']); ?></strong><br>
                                    <span class="compact-text">
                                        <?php echo htmlspecialchars((string)($complaint['customer_name'] ?? 'Unknown User')); ?>
                                        (<?php echo htmlspecialchars((string)($complaint['customer_email'] ?? '')); ?>)
                                    </span><br>
                                    <span class="compact-text">Created: <?php echo htmlspecialchars(saFormatDateTime($complaint['created_at'] ?? null)); ?></span>
                                </td>
                                <td>
                                    <span class="status-chip chip-info"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string)($complaint['detected_category'] ?? 'general')))); ?></span><br>
                                    <span class="compact-text">Priority: <?php echo htmlspecialchars(ucfirst((string)($complaint['priority'] ?? 'medium'))); ?></span>
                                </td>
                                <td>
                                    <form method="POST" class="module-inline-actions">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="update_complaint">
                                        <input type="hidden" name="conversation_id" value="<?php echo (int)$complaint['id']; ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $status_key => $status_label): ?>
                                                <option value="<?php echo $status_key; ?>" <?php echo ($status_key === (string)$complaint['status']) ? 'selected' : ''; ?>>
                                                    <?php echo $status_label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="priority" class="form-select form-select-sm">
                                            <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                                                <option value="<?php echo $priority; ?>" <?php echo ($priority === (string)$complaint['priority']) ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst($priority); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-primary"
                                                data-sa-confirm="1"
                                                data-sa-confirm-title-template="Update Complaint to {field_label:status}?"
                                                data-sa-confirm-text-template="This will set the complaint to {field_label:status} with {field_label:priority} priority."
                                                data-sa-confirm-confirm-text-template="Yes, Set to {field_label:status}"
                                                data-sa-confirm-confirm-color="#9f1239">
                                            Save
                                        </button>
                                    </form>
                                    <div style="margin-top: 6px;">
                                        <span class="status-chip <?php echo saComplaintStatusChip($complaint['status'] ?? 'open'); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$complaint['status']))); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-truncate-2"><?php echo htmlspecialchars((string)($complaint['latest_message'] ?? 'No message yet.')); ?></div>
                                    <span class="compact-text">Updated: <?php echo htmlspecialchars(saFormatDateTime($complaint['updated_at'] ?? null)); ?></span>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="respond_complaint">
                                        <input type="hidden" name="conversation_id" value="<?php echo (int)$complaint['id']; ?>">
                                        <textarea name="response_message" class="form-control form-control-sm" rows="2" placeholder="Type admin response..." required></textarea>
                                        <button type="submit" class="btn btn-sm btn-success" style="margin-top: 6px;">
                                            <i class="fas fa-paper-plane"></i> Send
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
saRenderModuleFooter();
