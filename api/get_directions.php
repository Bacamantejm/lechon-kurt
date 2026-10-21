<?php
/**
 * Street & Highway Directions API Proxy
 * Resolves precise turn-by-turn road geometry for rider navigation and customer live tracking.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$origin_lat = isset($_GET['origin_lat']) ? (float)$_GET['origin_lat'] : null;
$origin_lng = isset($_GET['origin_lng']) ? (float)$_GET['origin_lng'] : null;
$dest_lat = isset($_GET['dest_lat']) ? (float)$_GET['dest_lat'] : null;
$dest_lng = isset($_GET['dest_lng']) ? (float)$_GET['dest_lng'] : null;

if (($origin_lat === null || $origin_lng === null) && !empty($_GET['origin'])) {
    $parts = explode(',', $_GET['origin']);
    if (count($parts) >= 2) {
        $origin_lat = (float)trim($parts[0]);
        $origin_lng = (float)trim($parts[1]);
    }
}
if (($dest_lat === null || $dest_lng === null) && !empty($_GET['destination'])) {
    $parts = explode(',', $_GET['destination']);
    if (count($parts) >= 2) {
        $dest_lat = (float)trim($parts[0]);
        $dest_lng = (float)trim($parts[1]);
    }
}

if ($origin_lat === null || $origin_lng === null || $dest_lat === null || $dest_lng === null ||
    $origin_lat == 0 || $origin_lng == 0 || $dest_lat == 0 || $dest_lng == 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing origin/destination coordinates.'
    ]);
    exit;
}

function haversineDist($lat1, $lon1, $lat2, $lon2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
}

$mirrors = [
    'https://router.project-osrm.org/route/v1/driving/' . $origin_lng . ',' . $origin_lat . ';' . $dest_lng . ',' . $dest_lat . '?overview=full&geometries=geojson',
    'https://routing.openstreetmap.de/routed-car/route/v1/driving/' . $origin_lng . ',' . $origin_lat . ';' . $dest_lng . ',' . $dest_lat . '?overview=full&geometries=geojson'
];

$routeData = null;
$sourceUsed = 'none';

foreach ($mirrors as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) LechonSystem-Navigator/2.0'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        $json = json_decode($response, true);
        if ($json && isset($json['routes'][0]['geometry']['coordinates']) && !empty($json['routes'][0]['geometry']['coordinates'])) {
            $routeData = $json['routes'][0];
            $sourceUsed = (strpos($url, 'openstreetmap.de') !== false) ? 'osm_de' : 'osrm_org';
            break;
        }
    }
}

if ($routeData) {
    $rawCoords = $routeData['geometry']['coordinates'];
    $latLngs = array_map(function($pt) {
        return [(float)$pt[1], (float)$pt[0]];
    }, $rawCoords);

    $distanceMeters = (float)($routeData['distance'] ?? 0);
    $durationSeconds = (float)($routeData['duration'] ?? 0);
    $distKm = round($distanceMeters / 1000, 1);
    $durMins = max(1, round($durationSeconds / 60));

    echo json_encode([
        'success' => true,
        'coordinates' => $latLngs,
        'distance_km' => $distKm,
        'duration_mins' => $durMins,
        'distance_meters' => $distanceMeters,
        'duration_seconds' => $durationSeconds,
        'source' => $sourceUsed
    ]);
    exit;
}

$directDist = haversineDist($origin_lat, $origin_lng, $dest_lat, $dest_lng);
$estimatedRoadDist = round($directDist * 1.25, 1);
$estimatedMins = max(1, round(($estimatedRoadDist / 25) * 60));

echo json_encode([
    'success' => true,
    'coordinates' => [
        [$origin_lat, $origin_lng],
        [$dest_lat, $dest_lng]
    ],
    'distance_km' => $estimatedRoadDist,
    'duration_mins' => $estimatedMins,
    'distance_meters' => $estimatedRoadDist * 1000,
    'duration_seconds' => $estimatedMins * 60,
    'source' => 'direct_fallback'
]);
exit;