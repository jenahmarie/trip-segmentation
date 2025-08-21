<?php

// Turn on strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Constants
define('INPUT_FILE', 'input.csv');
define('REJECTS_LOG', 'rejects.log');
define('GEOJSON_FILE', 'trips.geojson');

// Variables
$valid_points = [];
$trips = [];
$current_trip = [];
$trip_index = 1;

// 1. --- Open files ---
$input = fopen(INPUT_FILE, 'r');
$rejects = fopen(REJECTS_LOG, 'w');

if (!$input) {
    die("Failed to open input file.");
}

// 2. --- Read and Clean ---
while (($row = fgetcsv($input)) !== false) {
    [$device_id, $lat, $lon, $timestamp] = $row;

    if (!isValidCoord($lat, $lon) || !isValidTimestamp($timestamp)) {
        fwrite($rejects, implode(',', $row) . PHP_EOL);
        continue;
    }

    $valid_points[] = [
        'device_id' => $device_id,
        'lat' => (float) $lat,
        'lon' => (float) $lon,
        'timestamp' => $timestamp,
        'time' => strtotime($timestamp)
    ];
}
fclose($input);
fclose($rejects);

// 3. --- Sort by timestamp ---
usort($valid_points, fn($a, $b) => $a['time'] <=> $b['time']);

// 4. --- Split into trips ---
$prev = null;
foreach ($valid_points as $point) {
    if ($prev) {
        $time_diff = ($point['time'] - $prev['time']) / 60; // in minutes
        $distance = haversine($prev['lat'], $prev['lon'], $point['lat'], $point['lon']);

        if ($time_diff > 25 || $distance > 2) {
            finalizeTrip($current_trip, $trip_index, $trips);
            $trip_index++;
            $current_trip = [];
        }
    }

    $current_trip[] = $point;
    $prev = $point;
}
if (!empty($current_trip)) {
    finalizeTrip($current_trip, $trip_index, $trips);
}

// 5. --- Output GeoJSON ---
generateGeoJSON($trips, GEOJSON_FILE);

echo "Processing complete. Trips saved to " . GEOJSON_FILE . PHP_EOL;

//
// ----- Helper Functions Below -----
//

function isValidCoord($lat, $lon): bool
{
    return is_numeric($lat) && is_numeric($lon) &&
        $lat >= -90 && $lat <= 90 &&
        $lon >= -180 && $lon <= 180;
}

function isValidTimestamp($ts): bool
{
    return strtotime($ts) !== false;
}

function haversine($lat1, $lon1, $lat2, $lon2): float
{
    $earth_radius = 6371; // in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);

    $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}

function finalizeTrip($points, $trip_id, &$trips)
{
    $total_distance = 0;
    $max_speed = 0;
    $start = $points[0];
    $end = end($points);
    $total_time = ($end['time'] - $start['time']) / 3600; // in hours

    for ($i = 1; $i < count($points); $i++) {
        $dist = haversine($points[$i - 1]['lat'], $points[$i - 1]['lon'], $points[$i]['lat'], $points[$i]['lon']);
        $time_diff = ($points[$i]['time'] - $points[$i - 1]['time']) / 3600;
        $total_distance += $dist;
        if ($time_diff > 0) {
            $speed = $dist / $time_diff;
            $max_speed = max($max_speed, $speed);
        }
    }

    $avg_speed = $total_time > 0 ? $total_distance / $total_time : 0;
    $trips[] = [
        'id' => "trip_$trip_id",
        'points' => $points,
        'distance' => round($total_distance, 2),
        'duration_min' => round($total_time * 60, 2),
        'avg_speed' => round($avg_speed, 2),
        'max_speed' => round($max_speed, 2),
    ];
}

function generateGeoJSON($trips, $filename)
{
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => []
    ];

    // Predefined colors for styling
    $colors = ['#e6194b', '#3cb44b', '#ffe119', '#4363d8', '#f58231', '#911eb4'];

    foreach ($trips as $index => $trip) {
        $coordinates = array_map(function ($p) {
            return [(float)$p['lon'], (float)$p['lat']];
        }, $trip['points']);

        $color = $colors[$index % count($colors)];

        $geojson['features'][] = [
            'type' => 'Feature',
            'properties' => [
                'trip_id' => $trip['id'],
                'stroke' => $color,
                'stroke-width' => 3,
                'stroke-opacity' => 1,
                'distance_km' => $trip['distance'],
                'duration_min' => $trip['duration_min'],
                'avg_speed_kph' => $trip['avg_speed'],
                'max_speed_kph' => $trip['max_speed'],
            ],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates
            ]
        ];
    }

    file_put_contents($filename, json_encode($geojson, JSON_PRETTY_PRINT));
}
