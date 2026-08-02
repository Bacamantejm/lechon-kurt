<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);
$payload = $opsService->getDashboardPayload();

$activity = [];
foreach ((array)($payload['jobs'] ?? []) as $job) {
    $activity[] = [
        'type' => 'job',
        'title' => (string)($job['job_name'] ?? 'Operational Job'),
        'status' => ucfirst((string)($job['status'] ?? 'queued')),
        'owner' => (string)($job['created_name'] ?? 'System'),
        'created_at' => (string)($job['created_at'] ?? ''),
        'created_label' => saFormatDateTime($job['created_at'] ?? null),
        'detail' => ucfirst((string)($job['job_type'] ?? 'task'))
    ];
}
foreach ((array)($payload['announcements'] ?? []) as $announcement) {
    $activity[] = [
        'type' => 'announcement',
        'title' => (string)($announcement['title'] ?? 'Announcement'),
        'status' => ucfirst((string)($announcement['status'] ?? 'draft')),
        'owner' => (string)($announcement['created_name'] ?? 'System'),
        'created_at' => (string)($announcement['created_at'] ?? ''),
        'created_label' => saFormatDateTime($announcement['created_at'] ?? null),
        'detail' => ucfirst(str_replace('_', ' ', (string)($announcement['delivery_channel'] ?? 'in_app')))
    ];
}

usort($activity, static function ($left, $right) {
    return strtotime((string)($right['created_at'] ?? '')) <=> strtotime((string)($left['created_at'] ?? ''));
});
$activity = array_slice($activity, 0, 8);

$alerts = array_map(static function (array $alert): array {
    return [
        'severity' => (string)($alert['severity'] ?? 'medium'),
        'title' => (string)($alert['title'] ?? ''),
        'message' => (string)($alert['message'] ?? ''),
        'status' => (int)($alert['is_acknowledged'] ?? 0) === 1 ? 'Acknowledged' : 'Open',
        'created_label' => saFormatDateTime($alert['created_at'] ?? null)
    ];
}, (array)($payload['alerts'] ?? []));

$incidents = array_map(static function (array $incident): array {
    return [
        'code' => (string)($incident['incident_code'] ?? ''),
        'category' => ucfirst((string)($incident['category'] ?? 'system')),
        'severity' => (string)($incident['severity'] ?? 'medium'),
        'title' => (string)($incident['title'] ?? ''),
        'status' => ucwords(str_replace('_', ' ', (string)($incident['status'] ?? 'open'))),
        'assigned' => (string)($incident['assigned_name'] ?? 'Unassigned'),
        'detected_label' => saFormatDateTime($incident['detected_at'] ?? null)
    ];
}, (array)($payload['incidents'] ?? []));

$response = [
    'success' => true,
    'generated_at' => (string)($payload['generated_at'] ?? date('c')),
    'overview' => (array)($payload['overview'] ?? []),
    'charts' => (array)($payload['charts'] ?? []),
    'team_summary' => (array)($payload['team_summary'] ?? []),
    'alerts' => $alerts,
    'incidents' => $incidents,
    'recommendations' => array_values((array)($payload['decision']['recommendations'] ?? [])),
    'activity' => $activity
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);

