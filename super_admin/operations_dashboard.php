<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_dashboard.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'capture_snapshot') {
        if ($opsService->captureMetricSnapshot()) {
            saSetFlash('success', 'Operational metric snapshot captured.');
        } else {
            saSetFlash('danger', 'Unable to capture operational snapshot.');
        }
    }

    header('Location: operations_dashboard.php');
    exit;
}

$dashboard = $opsService->getDashboardPayload();
$overview = (array)($dashboard['overview'] ?? []);
$alerts = (array)($dashboard['alerts'] ?? []);
$incidents = (array)($dashboard['incidents'] ?? []);
$decision = (array)($dashboard['decision'] ?? []);
$jobs = (array)($dashboard['jobs'] ?? []);
$announcements = (array)($dashboard['announcements'] ?? []);
$teamSummary = (array)($dashboard['team_summary'] ?? []);
$initialPayloadJson = json_encode($dashboard, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($initialPayloadJson === false) {
    $initialPayloadJson = '{}';
}

$activityFeed = [];
foreach ($jobs as $job) {
    $activityFeed[] = [
        'type' => 'Job',
        'title' => (string)($job['job_name'] ?? 'Operational Job'),
        'status' => ucfirst((string)($job['status'] ?? 'queued')),
        'owner' => (string)($job['created_name'] ?? 'System'),
        'detail' => ucfirst((string)($job['job_type'] ?? 'task')),
        'created_at' => (string)($job['created_at'] ?? '')
    ];
}
foreach ($announcements as $announcement) {
    $activityFeed[] = [
        'type' => 'Announcement',
        'title' => (string)($announcement['title'] ?? 'Announcement'),
        'status' => ucfirst((string)($announcement['status'] ?? 'draft')),
        'owner' => (string)($announcement['created_name'] ?? 'System'),
        'detail' => ucfirst(str_replace('_', ' ', (string)($announcement['delivery_channel'] ?? 'in_app'))),
        'created_at' => (string)($announcement['created_at'] ?? '')
    ];
}
usort($activityFeed, static function ($left, $right) {
    return strtotime((string)($right['created_at'] ?? '')) <=> strtotime((string)($left['created_at'] ?? ''));
});
$activityFeed = array_slice($activityFeed, 0, 8);

saRenderModuleHeader('Operations Dashboard', 'Operations Dashboard', $admin_info);
?>
<div class="module-section ops-highlight-card">
    <div class="module-section-header">
        <div>
            <h2>Operational Command Center</h2>
            <p class="module-subtext">Live monitoring for alerts, incidents, traffic pressure, and team workload across the platform.</p>
        </div>
        <div class="ops-toolbar">
            <div class="live-indicator">
                <span class="live-indicator-dot"></span>
                <span id="opsLiveStatus">Live auto-refresh every 30 seconds</span>
            </div>
            <div class="module-inline-actions">
                <button type="button" class="btn btn-sm btn-outline-primary" id="opsManualRefresh"><i class="fas fa-rotate-right"></i> Refresh Now</button>
                <form method="post" class="module-inline-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="capture_snapshot">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-camera"></i> Capture Snapshot</button>
                </form>
            </div>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card" data-metric="active_users_24h"><span class="metric-label">Active Users (24h)</span><div class="metric-value"><?php echo number_format((int)($overview['active_users_24h'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="transactions_today"><span class="metric-label">Transactions Today</span><div class="metric-value"><?php echo number_format((int)($overview['transactions_today'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="gross_revenue_today"><span class="metric-label">Revenue Today</span><div class="metric-value"><?php echo saFormatCurrency($overview['gross_revenue_today'] ?? 0); ?></div></div>
        <div class="metric-card" data-metric="open_complaints"><span class="metric-label">Open Complaints</span><div class="metric-value"><?php echo number_format((int)($overview['open_complaints'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="pending_businesses"><span class="metric-label">Pending Businesses</span><div class="metric-value"><?php echo number_format((int)($overview['pending_businesses'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="critical_alerts"><span class="metric-label">Critical Alerts</span><div class="metric-value"><?php echo number_format((int)($overview['critical_alerts'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="open_incidents"><span class="metric-label">Open Incidents</span><div class="metric-value"><?php echo number_format((int)($overview['open_incidents'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="pending_content"><span class="metric-label">Pending Content</span><div class="metric-value"><?php echo number_format((int)($overview['pending_content'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="running_jobs"><span class="metric-label">Automation Jobs</span><div class="metric-value"><?php echo number_format((int)($overview['running_jobs'] ?? 0)); ?></div></div>
        <div class="metric-card" data-metric="suspicious_events_24h"><span class="metric-label">Suspicious Events</span><div class="metric-value"><?php echo number_format((int)($overview['suspicious_events_24h'] ?? 0)); ?></div></div>
    </div>

    <div class="module-inline-actions">
        <a href="../super_admin/operations_incidents.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-triangle-exclamation"></i> Incident Desk</a>
        <a href="../super_admin/operations_user_business_control.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-users-gear"></i> Users & Businesses</a>
        <a href="../super_admin/operations_content_moderation.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-shield-check"></i> Moderation</a>
        <a href="../super_admin/operations_decision_support.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-pie"></i> Insights</a>
        <a href="../super_admin/operations_notifications.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-bullhorn"></i> Announcements</a>
        <a href="../super_admin/operations_automation.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-robot"></i> Automation</a>
        <?php if ($is_super_admin_user): ?>
            <a href="../super_admin/operations_team.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-user-shield"></i> Team & Roles</a>
        <?php endif; ?>
    </div>
</div>

<div class="ops-grid-2">
    <div class="module-section chart-panel">
        <div class="module-section-header">
            <div>
                <h3>Activity Trend</h3>
                <p class="module-subtext">Traffic, transaction flow, and complaint volume compared across recent snapshots and the live moment.</p>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="opsActivityChart"></canvas>
        </div>
    </div>

    <div class="module-section chart-panel">
        <div class="module-section-header">
            <div>
                <h3>Operational Pressure</h3>
                <p class="module-subtext">Backlog and security pressure indicators that need active follow-up from the operations team.</p>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="opsPressureChart"></canvas>
        </div>
    </div>
</div>

<div class="ops-grid-2">
    <div class="module-section">
        <div class="module-section-header">
            <div>
                <h3>Active Alerts</h3>
                <p class="module-subtext">Threshold-based warnings that refresh automatically as conditions change.</p>
            </div>
            <a href="../super_admin/operations_incidents.php" class="btn btn-sm btn-outline-secondary">Open Alert Desk</a>
        </div>
        <div class="table-wrap">
            <table class="module-table">
                <thead><tr><th>Severity</th><th>Title</th><th>Message</th><th>Status</th><th>Created</th></tr></thead>
                <tbody id="opsAlertsBody">
                <?php if (empty($alerts)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No active operational alerts.</td></tr>
                <?php else: foreach ($alerts as $alert): ?>
                    <?php $chip = in_array((string)$alert['severity'], ['critical', 'high'], true) ? 'chip-danger' : 'chip-warning'; ?>
                    <tr>
                        <td><span class="status-chip <?php echo $chip; ?>"><?php echo htmlspecialchars(ucfirst((string)$alert['severity'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars((string)$alert['title']); ?></strong></td>
                        <td><?php echo htmlspecialchars((string)$alert['message']); ?></td>
                        <td><?php echo (int)($alert['is_acknowledged'] ?? 0) === 1 ? 'Acknowledged' : 'Open'; ?></td>
                        <td><?php echo htmlspecialchars(saFormatDateTime($alert['created_at'] ?? null)); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="module-section">
        <div class="module-section-header">
            <div>
                <h3>Incident Queue</h3>
                <p class="module-subtext">Investigation cases refreshed live so the owner can watch severity and assignee movement in real time.</p>
            </div>
            <a href="../super_admin/operations_incidents.php" class="btn btn-sm btn-outline-secondary">Manage Incidents</a>
        </div>
        <div class="table-wrap">
            <table class="module-table">
                <thead><tr><th>Code</th><th>Category</th><th>Severity</th><th>Title</th><th>Status</th><th>Assigned</th></tr></thead>
                <tbody id="opsIncidentsBody">
                <?php if (empty($incidents)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No incidents logged yet.</td></tr>
                <?php else: foreach ($incidents as $incident): ?>
                    <?php $chip = in_array((string)$incident['severity'], ['critical', 'high'], true) ? 'chip-danger' : 'chip-info'; ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$incident['incident_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars(ucfirst((string)$incident['category'])); ?></td>
                        <td><span class="status-chip <?php echo $chip; ?>"><?php echo htmlspecialchars(ucfirst((string)$incident['severity'])); ?></span></td>
                        <td><?php echo htmlspecialchars((string)$incident['title']); ?></td>
                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$incident['status']))); ?></td>
                        <td><?php echo htmlspecialchars((string)($incident['assigned_name'] ?? 'Unassigned')); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="ops-grid-2">
    <div class="module-section">
        <div class="module-section-header">
            <div>
                <h3>Decision Support</h3>
                <p class="module-subtext">Recommendations and staffing visibility based on live operations data.</p>
            </div>
            <a href="../super_admin/operations_decision_support.php" class="btn btn-sm btn-outline-secondary">Open Insights</a>
        </div>
        <div class="ops-stat-stack" id="opsTeamSummary">
            <div class="ops-stat-line"><span>Operational managers</span><strong><?php echo number_format((int)($teamSummary['operational_manager'] ?? 0)); ?></strong></div>
            <div class="ops-stat-line"><span>Operations staff</span><strong><?php echo number_format((int)($teamSummary['operations_staff'] ?? 0)); ?></strong></div>
            <div class="ops-stat-line"><span>Total assigned</span><strong><?php echo number_format((int)($teamSummary['total_assigned'] ?? 0)); ?></strong></div>
        </div>
        <div id="opsRecommendations" class="panel-list" style="margin-top: 14px;">
            <?php foreach ((array)($decision['recommendations'] ?? []) as $recommendation): ?>
                <div class="panel-list-item">
                    <div class="panel-list-title">Recommended next step</div>
                    <div class="panel-list-meta"><?php echo htmlspecialchars((string)$recommendation); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="module-section">
        <div class="module-section-header">
            <div>
                <h3>Recent Operational Activity</h3>
                <p class="module-subtext">Latest queued automation work and outbound notices from the operations desk.</p>
            </div>
        </div>
        <div id="opsActivityFeed" class="panel-list">
            <?php if (empty($activityFeed)): ?>
                <div class="ops-empty">No automation jobs or announcements recorded yet.</div>
            <?php else: foreach ($activityFeed as $item): ?>
                <div class="panel-list-item">
                    <div class="panel-list-title"><?php echo htmlspecialchars((string)$item['type'] . ': ' . (string)$item['title']); ?></div>
                    <div class="panel-list-meta"><?php echo htmlspecialchars((string)$item['detail'] . ' | ' . (string)$item['status'] . ' | ' . (string)$item['owner'] . ' | ' . saFormatDateTime($item['created_at'] ?? null)); ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php
$extra_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    const initialPayload = {$initialPayloadJson};
    const endpoint = new URL('operations_dashboard_feed.php', window.location.href).toString();
    const refreshButton = document.getElementById('opsManualRefresh');
    const liveStatus = document.getElementById('opsLiveStatus');
    const metricCards = document.querySelectorAll('[data-metric]');
    const alertsBody = document.getElementById('opsAlertsBody');
    const incidentsBody = document.getElementById('opsIncidentsBody');
    const recommendationsWrap = document.getElementById('opsRecommendations');
    const activityFeed = document.getElementById('opsActivityFeed');
    const teamSummary = document.getElementById('opsTeamSummary');
    const numberFormat = new Intl.NumberFormat();
    const currencyFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    let activityChart = null;
    let pressureChart = null;
    let isRefreshing = false;

    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const chipClass = (severity) => {
        const normalized = String(severity || '').toLowerCase();
        if (normalized === 'critical' || normalized === 'high') return 'chip-danger';
        if (normalized === 'medium') return 'chip-warning';
        return 'chip-info';
    };

    const renderMetric = (key, value) => {
        metricCards.forEach((card) => {
            if (card.getAttribute('data-metric') !== key) {
                return;
            }
            const valueNode = card.querySelector('.metric-value');
            if (!valueNode) {
                return;
            }
            if (key === 'gross_revenue_today') {
                valueNode.textContent = currencyFormat.format(Number(value || 0));
            } else {
                valueNode.textContent = numberFormat.format(Number(value || 0));
            }
        });
    };

    const renderAlerts = (items) => {
        if (!alertsBody) return;
        if (!Array.isArray(items) || items.length === 0) {
            alertsBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No active operational alerts.</td></tr>';
            return;
        }
        alertsBody.innerHTML = items.map((item) => {
            return '<tr>' +
                '<td><span class="status-chip ' + chipClass(item.severity) + '">' + escapeHtml(String(item.severity || '').replace(/^./, (m) => m.toUpperCase())) + '</span></td>' +
                '<td><strong>' + escapeHtml(item.title || '') + '</strong></td>' +
                '<td>' + escapeHtml(item.message || '') + '</td>' +
                '<td>' + escapeHtml(item.status || 'Open') + '</td>' +
                '<td>' + escapeHtml(item.created_label || '-') + '</td>' +
            '</tr>';
        }).join('');
    };

    const renderIncidents = (items) => {
        if (!incidentsBody) return;
        if (!Array.isArray(items) || items.length === 0) {
            incidentsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No incidents logged yet.</td></tr>';
            return;
        }
        incidentsBody.innerHTML = items.map((item) => {
            return '<tr>' +
                '<td><strong>' + escapeHtml(item.code || '') + '</strong></td>' +
                '<td>' + escapeHtml(item.category || '') + '</td>' +
                '<td><span class="status-chip ' + chipClass(item.severity) + '">' + escapeHtml(String(item.severity || '').replace(/^./, (m) => m.toUpperCase())) + '</span></td>' +
                '<td>' + escapeHtml(item.title || '') + '</td>' +
                '<td>' + escapeHtml(item.status || '') + '</td>' +
                '<td>' + escapeHtml(item.assigned || 'Unassigned') + '</td>' +
            '</tr>';
        }).join('');
    };

    const renderRecommendations = (items) => {
        if (!recommendationsWrap) return;
        if (!Array.isArray(items) || items.length === 0) {
            recommendationsWrap.innerHTML = '<div class="ops-empty">No recommendations available right now.</div>';
            return;
        }
        recommendationsWrap.innerHTML = items.map((item) => {
            return '<div class="panel-list-item">' +
                '<div class="panel-list-title">Recommended next step</div>' +
                '<div class="panel-list-meta">' + escapeHtml(item || '') + '</div>' +
            '</div>';
        }).join('');
    };

    const renderActivity = (items) => {
        if (!activityFeed) return;
        if (!Array.isArray(items) || items.length === 0) {
            activityFeed.innerHTML = '<div class="ops-empty">No automation jobs or announcements recorded yet.</div>';
            return;
        }
        activityFeed.innerHTML = items.map((item) => {
            return '<div class="panel-list-item">' +
                '<div class="panel-list-title">' + escapeHtml(String(item.type || 'Activity') + ': ' + String(item.title || '')) + '</div>' +
                '<div class="panel-list-meta">' + escapeHtml(String(item.detail || '') + ' | ' + String(item.status || '') + ' | ' + String(item.owner || '') + ' | ' + String(item.created_label || '-')) + '</div>' +
            '</div>';
        }).join('');
    };

    const renderTeamSummary = (summary) => {
        if (!teamSummary) return;
        teamSummary.innerHTML = [
            ['Operational managers', summary && summary.operational_manager],
            ['Operations staff', summary && summary.operations_staff],
            ['Total assigned', summary && summary.total_assigned]
        ].map((row) => {
            return '<div class="ops-stat-line"><span>' + escapeHtml(row[0]) + '</span><strong>' + numberFormat.format(Number(row[1] || 0)) + '</strong></div>';
        }).join('');
    };

    const renderCharts = (charts) => {
        if (!window.Chart || !charts || !Array.isArray(charts.labels)) {
            return;
        }
        const activityCanvas = document.getElementById('opsActivityChart');
        const pressureCanvas = document.getElementById('opsPressureChart');
        if (!activityCanvas || !pressureCanvas) {
            return;
        }

        const activityData = charts.activity || {};
        const workloadData = charts.workload || {};

        if (activityChart) {
            activityChart.destroy();
        }
        activityChart = new Chart(activityCanvas, {
            type: 'line',
            data: {
                labels: charts.labels,
                datasets: [
                    {
                        label: 'Active Users',
                        data: activityData.active_users || [],
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15, 118, 110, 0.12)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2.5
                    },
                    {
                        label: 'Transactions',
                        data: activityData.transactions || [],
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 2.2
                    },
                    {
                        label: 'Open Complaints',
                        data: activityData.complaints || [],
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.08)',
                        fill: false,
                        tension: 0.28,
                        borderWidth: 2,
                        borderDash: [6, 4]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true },
                    x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }
                }
            }
        });

        if (pressureChart) {
            pressureChart.destroy();
        }
        pressureChart = new Chart(pressureCanvas, {
            type: 'bar',
            data: {
                labels: charts.labels,
                datasets: [
                    {
                        label: 'Pending Businesses',
                        data: workloadData.pending_businesses || [],
                        backgroundColor: 'rgba(245, 158, 11, 0.75)',
                        borderRadius: 8
                    },
                    {
                        label: 'Critical Alerts',
                        data: workloadData.critical_alerts || [],
                        backgroundColor: 'rgba(220, 38, 38, 0.75)',
                        borderRadius: 8
                    },
                    {
                        label: 'Suspicious Events',
                        data: workloadData.suspicious_events || [],
                        backgroundColor: 'rgba(99, 102, 241, 0.75)',
                        borderRadius: 8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true },
                    x: { stacked: false, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }
                }
            }
        });
    };

    const applyPayload = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }
        const overview = payload.overview || {};
        Object.keys(overview).forEach((key) => renderMetric(key, overview[key]));
        renderAlerts(payload.alerts || []);
        renderIncidents(payload.incidents || []);
        renderRecommendations(payload.recommendations || []);
        renderActivity(payload.activity || []);
        renderTeamSummary(payload.team_summary || {});
        renderCharts(payload.charts || {});

        const timestamp = payload.generated_at ? new Date(payload.generated_at) : null;
        liveStatus.textContent = timestamp && !Number.isNaN(timestamp.getTime())
            ? 'Live data refreshed ' + timestamp.toLocaleTimeString()
            : 'Live auto-refresh every 30 seconds';
    };

    const refreshDashboard = async () => {
        if (isRefreshing) {
            return;
        }
        isRefreshing = true;
        if (liveStatus) {
            liveStatus.textContent = 'Refreshing live operations data...';
        }
        try {
            const response = await fetch(endpoint, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if (!response.ok) {
                throw new Error('Failed to refresh dashboard');
            }
            const payload = await response.json();
            if (payload && payload.success) {
                applyPayload(payload);
            } else {
                throw new Error('Invalid payload');
            }
        } catch (error) {
            if (liveStatus) {
                liveStatus.textContent = 'Live refresh paused. Retrying automatically.';
            }
        } finally {
            isRefreshing = false;
        }
    };

    if (refreshButton) {
        refreshButton.addEventListener('click', refreshDashboard);
    }

    applyPayload(initialPayload);
    window.setInterval(refreshDashboard, 30000);
})();
</script>
HTML;

saRenderModuleFooter($extra_scripts);
?>

