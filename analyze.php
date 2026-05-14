<?php

declare(strict_types=1);

/**
 * GPX analyzer — extracts villages, settlements and mountains via OSM Nominatim.
 *
 * Usage: php analyze.php <file.gpx> [--interval-km=2] [--verbose]
 */

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * @return array{track: array, waypoints: array}
 * @throws RuntimeException
 */
function parseGpx(string $path): array
{
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new RuntimeException("Ne mogu da parsiran GPX fajl: $path");
    }

    $track     = [];
    $waypoints = [];

    foreach ($xml->wpt as $wpt) {
        $waypoints[] = [
            'lat'  => (float) $wpt['lat'],
            'lon'  => (float) $wpt['lon'],
            'ele'  => isset($wpt->ele) ? (float) $wpt->ele : null,
            'name' => isset($wpt->name) ? trim((string) $wpt->name) : null,
        ];
    }

    foreach ($xml->trk as $trk) {
        foreach ($trk->trkseg as $seg) {
            foreach ($seg->trkpt as $pt) {
                $track[] = [
                    'lat' => (float) $pt['lat'],
                    'lon' => (float) $pt['lon'],
                    'ele' => isset($pt->ele) ? (float) $pt->ele : null,
                ];
            }
        }
    }

    foreach ($xml->rte as $rte) {
        foreach ($rte->rtept as $pt) {
            $track[] = [
                'lat' => (float) $pt['lat'],
                'lon' => (float) $pt['lon'],
                'ele' => isset($pt->ele) ? (float) $pt->ele : null,
            ];
        }
    }

    return ['track' => $track, 'waypoints' => $waypoints];
}

function samplePoints(array $points, float $intervalKm): array
{
    if (empty($points)) {
        return [];
    }

    $sampled     = [$points[0]];
    $accumulated = 0.0;
    $prev        = $points[0];

    for ($i = 1, $n = count($points); $i < $n; $i++) {
        $d = haversineKm($prev['lat'], $prev['lon'], $points[$i]['lat'], $points[$i]['lon']);
        $accumulated += $d;

        if ($accumulated >= $intervalKm) {
            $sampled[]   = $points[$i];
            $accumulated -= $intervalKm; // preserve overshoot residual
        }

        $prev = $points[$i];
    }

    $last = end($points);
    if ($sampled[count($sampled) - 1] !== $last) {
        $sampled[] = $last;
    }

    return $sampled;
}

function reverseGeocode(float $lat, float $lon, int $retry = 1): ?array
{
    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?lat=%F&lon=%F&format=json&zoom=14&addressdetails=1',
        $lat,
        $lon
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'GPX-Analyzer/1.0 (beljic@gmail.com)',
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (in_array($code, [429, 503], true) && $retry > 0) {
        sleep(10);
        return reverseGeocode($lat, $lon, $retry - 1);
    }

    if ($code !== 200 || $raw === false || $raw === '') {
        return null;
    }

    return json_decode($raw, true) ?: null;
}

function makeGeocoder(string $cacheDir): Closure
{
    @mkdir($cacheDir, 0775, true);

    return function (float $lat, float $lon) use ($cacheDir): ?array {
        $key  = sprintf('%.4f_%.4f', $lat, $lon);
        $file = "$cacheDir/$key.json";

        if (is_file($file)) {
            return json_decode((string) file_get_contents($file), true) ?: null;
        }

        $geo = reverseGeocode($lat, $lon);
        usleep(1_100_000); // Nominatim ToS: max 1 req/s, only on real HTTP calls

        if ($geo !== null) {
            file_put_contents($file, json_encode($geo));
        }

        return $geo;
    };
}

function minDistToTrackKm(float $lat, float $lon, array $trackPoints): float
{
    $min = PHP_FLOAT_MAX;
    foreach ($trackPoints as $p) {
        $d = haversineKm($lat, $lon, $p['lat'], $p['lon']);
        if ($d < $min) {
            $min = $d;
        }
    }
    return $min;
}

/**
 * Queries Overpass API for peaks, rivers and lakes near the actual track.
 * Peaks are post-filtered by exact haversine distance to track points.
 * Rivers/lakes use Overpass `around` filter directly.
 *
 * @return array{peaks: array, rivers: array, lakes: array}
 */
function fetchNaturalFeatures(array $trackPoints, float $peakRadiusM = 200.0): array
{
    if (empty($trackPoints)) {
        return ['peaks' => [], 'rivers' => [], 'lakes' => []];
    }

    // Wider Overpass net for peaks (catches candidates), then post-filter precisely.
    // For rivers/lakes 500 m is the actual cutoff.
    $overpassPeakM = max(500, (int) ($peakRadiusM * 2));
    $waterM        = 500;

    // Sample to ~5 km spacing to keep the query polyline short
    $queryPoints = samplePoints($trackPoints, 5.0);
    $polyline    = implode(',', array_map(
        fn($p) => sprintf('%F,%F', $p['lat'], $p['lon']),
        $queryPoints
    ));

    // Use `out;` so nodes (peaks) include lat/lon for post-filtering
    $query = "[out:json][timeout:60];"
        . "(node[\"natural\"=\"peak\"](around:{$overpassPeakM},{$polyline});"
        . "node[\"natural\"=\"hill\"](around:{$overpassPeakM},{$polyline});"
        . "way[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
        . "relation[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
        . "way[\"waterway\"=\"stream\"](around:{$waterM},{$polyline});"
        . "way[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
        . "relation[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
        . ");out;";

    $ch = curl_init('https://overpass-api.de/api/interpreter');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
        CURLOPT_USERAGENT      => 'GPX-Analyzer/1.0 (beljic@gmail.com)',
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $raw === false || $raw === '') {
        fwrite(STDERR, "Overpass greška (HTTP $code)\n");
        return ['peaks' => [], 'rivers' => [], 'lakes' => []];
    }

    $data   = json_decode($raw, true);
    $peaks  = [];
    $rivers = [];
    $lakes  = [];
    $seen   = [];

    $peakRadiusKm = $peakRadiusM / 1000.0;

    // For distance checks sample track to every ~100 m to keep it fast
    $checkPoints = samplePoints($trackPoints, 0.1);

    foreach ($data['elements'] ?? [] as $el) {
        $tags = $el['tags'] ?? [];
        $name = $tags['name'] ?? $tags['name:sr'] ?? null;
        if (!$name || isset($seen[$name])) {
            continue;
        }

        $natural  = $tags['natural']  ?? null;
        $waterway = $tags['waterway'] ?? null;

        if (in_array($natural, ['peak', 'hill'], true)) {
            // Nodes have lat/lon; post-filter by exact distance
            $lat = $el['lat'] ?? null;
            $lon = $el['lon'] ?? null;
            if ($lat === null || $lon === null) {
                continue;
            }
            if (minDistToTrackKm((float) $lat, (float) $lon, $checkPoints) > $peakRadiusKm) {
                continue;
            }
            $seen[$name] = true;
            $ele = isset($tags['ele']) ? (float) $tags['ele'] : null;
            $peaks[] = ['name' => $name, 'ele' => $ele];
        } elseif (in_array($waterway, ['river', 'stream'], true)) {
            $seen[$name] = true;
            $rivers[] = $name;
        } elseif ($natural === 'water') {
            $seen[$name] = true;
            $lakes[] = $name;
        }
    }

    usort($peaks, fn($a, $b) => ($b['ele'] ?? 0) <=> ($a['ele'] ?? 0));
    sort($rivers);
    sort($lakes);

    return ['peaks' => $peaks, 'rivers' => $rivers, 'lakes' => $lakes];
}

function classifyResult(array $result): array
{
    $addr  = $result['address'] ?? [];
    $class = $result['class']   ?? '';
    $type  = $result['type']    ?? '';

    $found = [];

    // Peaks: Nominatim sets class=natural, type=peak|hill|ridge|saddle|pass
    $peakTypes = ['peak', 'mountain', 'hill', 'ridge', 'saddle', 'pass'];
    if ($class === 'natural' && in_array($type, $peakTypes, true)) {
        $name = $result['name'] ?? null;
        if ($name) {
            $found[] = ['category' => 'planina', 'name' => $name];
        }
    }

    // Settlements — ordered from most to least specific
    $settlementMap = [
        'village'            => 'selo',
        'hamlet'             => 'zaselak',
        'isolated_dwelling'  => 'zaselak',
        'suburb'             => 'naselje',
        'neighbourhood'      => 'naselje',
        'quarter'            => 'naselje',
        'town'               => 'naselje',
        'city'               => 'grad',
        'municipality'       => 'opština',
    ];

    foreach ($settlementMap as $key => $label) {
        if (!empty($addr[$key])) {
            $found[] = ['category' => $label, 'name' => $addr[$key]];
            break;
        }
    }

    if (empty($found) && !empty($addr['locality'])) {
        $found[] = ['category' => 'lokalitet', 'name' => $addr['locality']];
    }

    return $found;
}

// ─── CLI args ────────────────────────────────────────────────────────────────

$opts = getopt('', ['interval-km:', 'peak-radius-m:', 'verbose'], $restIdx);

// Collect non-option arguments manually to avoid getopt positional quirks
$positional = [];
for ($i = 1; $i < $argc; $i++) {
    if (!str_starts_with($argv[$i], '--')) {
        $positional[] = $argv[$i];
    } elseif (str_contains($argv[$i], '=')) {
        // --flag=value — skip
    } else {
        $i++; // skip value of --flag value form
    }
}

if (empty($positional)) {
    echo "Upotreba: php analyze.php <file.gpx> [--interval-km=2] [--verbose]\n";
    exit(1);
}

$gpxFile      = $positional[0];
$intervalKm   = (float) ($opts['interval-km']    ?? 2.0);
$peakRadiusM  = (float) ($opts['peak-radius-m']  ?? 200.0);
$verbose      = isset($opts['verbose']);

if (!file_exists($gpxFile)) {
    fwrite(STDERR, "Fajl ne postoji: $gpxFile\n");
    exit(1);
}

// ─── Parse & sample ──────────────────────────────────────────────────────────

echo "Parsiranje: $gpxFile\n";

try {
    $gpx = parseGpx($gpxFile);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$trackPoints = $gpx['track'];
$waypoints   = $gpx['waypoints'];

echo "Ukupno track tačaka: " . count($trackPoints) . "\n";

$sampled = samplePoints($trackPoints, $intervalKm);
echo "Uzorkovano (interval {$intervalKm} km): " . count($sampled) . "\n\n";

// ─── Geocode ─────────────────────────────────────────────────────────────────

$geocode = makeGeocoder(__DIR__ . '/cache/nominatim');

$results = [
    'grad'      => [],
    'naselje'   => [],
    'selo'      => [],
    'zaselak'   => [],
    'lokalitet' => [],
    'opština'   => [],
];

// ─── Peaks via Overpass (single query, much more reliable) ───────────────────

echo "Pretraga prirodnih objekata (Overpass API, vrh radius: {$peakRadiusM} m)...\n";
$natural = fetchNaturalFeatures($trackPoints, $peakRadiusM);
$peaks   = $natural['peaks'];
$rivers  = $natural['rivers'];
$lakes   = $natural['lakes'];
echo sprintf("Pronađeno: %d vrhova, %d reka/potoka, %d jezera\n\n", count($peaks), count($rivers), count($lakes));

// ─── Geocode settlements ──────────────────────────────────────────────────────

$seen  = [];
$total = count($sampled);

foreach ($sampled as $i => $pt) {
    if ($verbose) {
        echo sprintf("[%d/%d] Geokodiranje (%.5f, %.5f)...\n", $i + 1, $total, $pt['lat'], $pt['lon']);
    } else {
        echo "\rGeokodiranje: " . ($i + 1) . "/$total";
    }

    $geo = $geocode($pt['lat'], $pt['lon']);
    if ($geo === null) {
        continue;
    }

    foreach (classifyResult($geo) as $item) {
        $key = $item['category'] . '::' . $item['name'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $results[$item['category']][] = $item['name'];
        }
    }
}

echo "\n\n";

// ─── Output ──────────────────────────────────────────────────────────────────

if (!empty($peaks)) {
    echo "── Planine / vrhovi ──\n";
    foreach ($peaks as $p) {
        $ele = $p['ele'] !== null ? sprintf(' (%d m)', (int) $p['ele']) : '';
        echo "   • {$p['name']}$ele\n";
    }
    echo "\n";
}

if (!empty($rivers)) {
    echo "── Reke i potoci ──\n";
    foreach ($rivers as $r) {
        echo "   • $r\n";
    }
    echo "\n";
}

if (!empty($lakes)) {
    echo "── Jezera / akumulacije ──\n";
    foreach ($lakes as $l) {
        echo "   • $l\n";
    }
    echo "\n";
}

// Settlements from Nominatim
$labels = [
    'grad'      => 'Gradovi',
    'naselje'   => 'Naselja',
    'selo'      => 'Sela',
    'zaselak'   => 'Zaseoci',
    'lokalitet' => 'Lokaliteti',
    'opština'   => 'Opštine',
];

foreach ($labels as $cat => $label) {
    if (empty($results[$cat])) {
        continue;
    }
    echo "── $label ──\n";
    foreach ($results[$cat] as $name) {
        echo "   • $name\n";
    }
    echo "\n";
}

$namedWpts = array_filter($waypoints, fn($w) => !empty($w['name']));

if (!empty($namedWpts)) {
    echo "── Waypointi iz GPX fajla ──\n";
    foreach ($namedWpts as $w) {
        $ele = $w['ele'] !== null ? sprintf(' (%.0f m)', $w['ele']) : '';
        echo "   • {$w['name']}$ele\n";
    }
    echo "\n";
}
