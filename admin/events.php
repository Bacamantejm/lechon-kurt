<?php
/**
 * ============================================================================
 * BUSINESS EVENTS MANAGEMENT
 * ============================================================================
 * Manage holidays, promotions, and special events that impact demand
 * These are used by forecasting system for seasonal adjustments
 * 
 * Path: admin/events.php
 */

session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/DSSInsightsService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$insights_service = new DSSInsightsService($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);

// Handle add/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_partner_scoped_admin) {
        $_SESSION['error'] = 'Event management is read-only for partner accounts.';
        header("Location: events.php");
        exit;
    }
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            $stmt = $conn->prepare("INSERT INTO business_events (event_name, event_date, event_type, impact_multiplier, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssds", $_POST['event_name'], $_POST['event_date'], $_POST['event_type'], $_POST['impact_multiplier'], $_POST['description']);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event added successfully!";
            } else {
                $_SESSION['error'] = "Error: " . $stmt->error;
            }
            $stmt->close();
        } elseif ($_POST['action'] == 'update') {
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $stmt = $conn->prepare("UPDATE business_events SET impact_multiplier = ?, is_active = ? WHERE event_id = ?");
            $stmt->bind_param("dii", $_POST['impact_multiplier'], $is_active, $_POST['event_id']);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event updated successfully!";
            } else {
                $_SESSION['error'] = "Error: " . $stmt->error;
            }
            $stmt->close();
        } elseif ($_POST['action'] == 'delete') {
            $stmt = $conn->prepare("DELETE FROM business_events WHERE event_id = ?");
            $stmt->bind_param("i", $_POST['event_id']);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Event deleted successfully!";
            } else {
                $_SESSION['error'] = "Error: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    header("Location: events.php");
    exit;
}

// Get events
$query = "SELECT * FROM business_events ORDER BY event_date ASC";
$result = $conn->query($query);
$events = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
}

$event_insights = $insights_service->getEventImpactInsights(90);
$insight_map = [];
foreach (($event_insights['upcoming_events'] ?? []) as $insight_item) {
    $insight_map[(int)$insight_item['event_id']] = $insight_item;
}

$active_events_count = count(array_filter($events, function ($event) {
    return (int)$event['is_active'] === 1;
}));
$upcoming_30_count = count(array_filter($events, function ($event) {
    return (int)$event['is_active'] === 1 && strtotime($event['event_date']) >= strtotime(date('Y-m-d')) && strtotime($event['event_date']) <= strtotime('+30 days');
}));
$high_impact_count = count(array_filter($events, function ($event) {
    return (int)$event['is_active'] === 1 && (float)$event['impact_multiplier'] >= 1.4;
}));
$total_expected_event_revenue = 0.0;
$total_expected_event_orders = 0.0;
$event_prep_alerts = 0;
foreach (($event_insights['upcoming_events'] ?? []) as $item) {
    $total_expected_event_revenue += (float)$item['expected_revenue'];
    $total_expected_event_orders += (float)$item['expected_orders'];
    if ((int)$item['days_until'] <= 14 && ((float)$item['impact_multiplier'] >= 1.2 || (float)$item['impact_multiplier'] <= 0.8)) {
        $event_prep_alerts++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Events Management</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .event-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .event-badge.holiday {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .event-badge.promotion {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .event-badge.special_event {
            background-color: #d4edda;
            color: #155724;
        }
        
        .event-badge.seasonal {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .event-badge.maintenance {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .impact-meter {
            display: inline-block;
            width: 150px;
            height: 24px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        
        .impact-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .card-event {
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        
        .card-event:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .modal-content {
            border-radius: 8px;
        }
        
        .btn-primary-custom {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .btn-primary-custom:hover {
            background-color: #764ba2;
            border-color: #764ba2;
        }

        .summary-card {
            border: 1px solid #edf0f7;
            border-radius: 12px;
            padding: 14px 16px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
            height: 100%;
        }

        .summary-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #667085;
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #101828;
            line-height: 1.2;
        }

        .summary-note {
            color: #667085;
            font-size: 0.8rem;
            margin-top: 4px;
            margin-bottom: 0;
        }

        .event-health-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .event-health-chip.positive {
            background: #ecfdf3;
            color: #027a48;
        }

        .event-health-chip.risk {
            background: #fff1f3;
            color: #c01048;
        }

        .event-health-chip.neutral {
            background: #f2f4f7;
            color: #344054;
        }

        .insight-box {
            border: 1px solid #e4e7ec;
            border-radius: 10px;
            background: #fcfcfd;
            padding: 10px 12px;
            margin-top: 10px;
        }

        .insight-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 6px;
        }

        .insight-metric {
            background: #fff;
            border: 1px solid #eef2f6;
            border-radius: 8px;
            padding: 8px;
        }

        .insight-metric .label {
            font-size: 0.72rem;
            color: #667085;
            display: block;
            margin-bottom: 2px;
        }

        .insight-metric .value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #101828;
        }

        .insight-action {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #344054;
            margin-bottom: 0;
        }
    </style>
</head>
<body>

<div class="admin-container">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div class="topbar-content">
                <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                <h1>Business Events</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-main">
            <div class="section-header">
                <h2>Manage Events for Forecasting</h2>
                <?php if (!$is_partner_scoped_admin): ?>
                    <button class="btn btn-primary btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addEventModal">
                        <i class="fas fa-plus"></i> Add Event
                    </button>
                <?php else: ?>
                    <span class="text-muted small"><i class="fas fa-lock"></i> Read-only access for partner accounts</span>
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="summary-label">Active Events</div>
                        <div class="summary-value"><?= (int)$active_events_count ?></div>
                        <p class="summary-note">Currently affecting forecast adjustments</p>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="summary-label">Upcoming (30 Days)</div>
                        <div class="summary-value"><?= (int)$upcoming_30_count ?></div>
                        <p class="summary-note">Events requiring short-term planning</p>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="summary-label">High Impact Events</div>
                        <div class="summary-value"><?= (int)$high_impact_count ?></div>
                        <p class="summary-note">Impact multiplier 1.4x and above</p>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="summary-label">Projected Event Revenue</div>
                        <div class="summary-value">PHP <?= number_format($total_expected_event_revenue, 0) ?></div>
                        <p class="summary-note">Estimated orders: <?= number_format($total_expected_event_orders, 1) ?> in 90 days</p>
                    </div>
                </div>
            </div>

            <div class="alert alert-info mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong>Decision Support Planning:</strong>
                        Baseline Daily Orders <strong><?= number_format($event_insights['baseline_daily_orders'] ?? 0, 1) ?></strong> |
                        Baseline Daily Revenue <strong>PHP <?= number_format($event_insights['baseline_daily_revenue'] ?? 0, 2) ?></strong>
                    </div>
                    <span class="event-health-chip <?= $event_prep_alerts > 0 ? 'risk' : 'positive' ?>">
                        <?= $event_prep_alerts > 0 ? $event_prep_alerts . ' prep alert(s) in next 14 days' : 'No urgent prep alerts' ?>
                    </span>
                </div>
            </div>

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

            <!-- Events List -->
            <div class="row">
                <?php foreach ($events as $event): ?>
                    <?php
                    $event_id = (int)$event['event_id'];
                    $insight = $insight_map[$event_id] ?? null;
                    $event_date = strtotime($event['event_date']);
                    $days_until = (int)floor(($event_date - strtotime(date('Y-m-d'))) / 86400);
                    $timing_label = $days_until >= 0 ? $days_until . ' day(s) to go' : abs($days_until) . ' day(s) ago';
                    $timing_class = $days_until < 0 ? 'neutral' : (($days_until <= 14 && (float)$event['impact_multiplier'] >= 1.2) ? 'risk' : 'positive');
                    ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card card-event h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($event['event_name']); ?></h6>
                                        <small class="text-muted"><?php echo date('F j, Y', strtotime($event['event_date'])); ?></small>
                                    </div>
                                    <span class="event-badge <?php echo htmlspecialchars($event['event_type']); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $event['event_type'])); ?>
                                    </span>
                                </div>
                                
                                <?php if ($event['description']): ?>
                                    <p class="card-text small text-muted mb-3">
                                        <?php echo htmlspecialchars($event['description']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="event-health-chip <?= $timing_class ?>">
                                    <i class="fas fa-clock"></i>
                                    <?= htmlspecialchars($timing_label) ?>
                                </div>

                                <div class="mt-auto">
                                    <div class="mb-2">
                                        <small class="text-muted">Demand Impact:</small>
                                        <div class="impact-meter">
                                            <div class="impact-fill" style="width: <?php echo min(100, $event['impact_multiplier'] * 50); ?>%;">
                                                <?php echo round($event['impact_multiplier'] * 100, 0); ?>%
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($insight): ?>
                                        <div class="insight-box">
                                            <div class="small fw-semibold text-muted">DSS Projection</div>
                                            <div class="insight-grid">
                                                <div class="insight-metric">
                                                    <span class="label">Expected Orders</span>
                                                    <span class="value"><?= number_format($insight['expected_orders'], 1) ?></span>
                                                </div>
                                                <div class="insight-metric">
                                                    <span class="label">Expected Revenue</span>
                                                    <span class="value">PHP <?= number_format($insight['expected_revenue'], 0) ?></span>
                                                </div>
                                            </div>
                                            <p class="insight-action"><strong>Preparation:</strong> <?= htmlspecialchars($insight['recommended_preparation']) ?></p>
                                        </div>
                                    <?php elseif ((int)$event['is_active'] === 1): ?>
                                        <div class="insight-box">
                                            <div class="small text-muted mb-0">No forward projection yet. Event may be outside planning horizon.</div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$is_partner_scoped_admin): ?>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editEventModal"
                                                    onclick="editEvent(<?php echo htmlspecialchars(json_encode($event), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="post" class="d-inline" data-sw-confirm="1" data-sw-confirm-title="Delete event?" data-sw-confirm-text="This event will be removed from the calendar." data-sw-confirm-confirm-text="Yes, delete">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$event['is_active']): ?>
                                        <div class="alert alert-warning mt-2 mb-0 py-1 px-2">
                                            <small><i class="fas fa-exclamation-triangle"></i> Inactive</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($events) === 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No events defined yet. Add your first event to help the forecasting system understand demand patterns.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$is_partner_scoped_admin): ?>
<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #667eea; color: white;">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add Business Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Event Name *</label>
                        <input type="text" name="event_name" class="form-control" required 
                               placeholder="e.g., New Year Holiday, Valentine's Day">
                    </div>
                    
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="event_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="event_type" class="form-control" required>
                            <option value="">-- Select Type --</option>
                            <option value="holiday">Holiday (Reduced demand)</option>
                            <option value="promotion">Promotion (Sales boost)</option>
                            <option value="special_event">Special Event (High demand)</option>
                            <option value="seasonal">Seasonal (Pattern change)</option>
                            <option value="maintenance">Maintenance (Limited service)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Demand Impact Multiplier *</label>
                        <div class="input-group">
                            <input type="number" name="impact_multiplier" class="form-control" required 
                                   min="0.1" max="3" step="0.1" value="1.0"
                                   onchange="updatePreview(this.value)">
                            <div class="input-group-append">
                                <span class="input-group-text">x normal demand</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            1.0 = normal, <0.5 = reduced, >1.5 = high demand
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Optional notes about this event"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-primary-custom">Add Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #667eea; color: white;">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="event_id" id="edit_event_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Event Name</label>
                        <input type="text" id="edit_event_name" class="form-control" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="edit_event_date" class="form-control" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>Type</label>
                        <input type="text" id="edit_event_type" class="form-control" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>Demand Impact Multiplier</label>
                        <div class="input-group">
                            <input type="number" name="impact_multiplier" id="edit_impact_multiplier" 
                                   class="form-control" min="0.1" max="3" step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">x normal demand</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input">
                        <label class="form-check-label" for="edit_is_active">
                            Active (affects forecasts)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-primary-custom">Update Event</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function editEvent(event) {
    document.getElementById('edit_event_id').value = event.event_id; // Make sure your event object has event_id
    document.getElementById('edit_event_name').value = event.event_name;
    document.getElementById('edit_event_date').value = event.event_date;
    document.getElementById('edit_event_type').value = event.event_type;
    document.getElementById('edit_impact_multiplier').value = event.impact_multiplier;
    document.getElementById('edit_is_active').checked = event.is_active == 1;
}

function updatePreview(value) {
    // Optional: Add preview of impact
}
</script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="admin.js"></script>

</body>
</html>
