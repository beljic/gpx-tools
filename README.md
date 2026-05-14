# beljic/gpx-tools

![PHP](https://img.shields.io/badge/PHP-8.3%2B-8892bf)
![License](https://img.shields.io/badge/license-MIT-green)

A PHP toolkit for working with GPX tracks. Parse, clean, simplify, and analyze — in plain PHP, Laravel, Symfony, or Magento 2.

## What it does

| Feature | Description |
|---|---|
| **Parse** | Read GPX 1.1 files from Garmin, Strava, Komoot, or any compliant source. Extracts track points, waypoints, HR, cadence, temperature, and power data. |
| **Clean** | Strip sensor data from recorded activities. Remove timestamps to turn activities into route templates. Keep only geometry for lightweight map files. |
| **Simplify** | Reduce point density. A typical Strava activity has 50 000+ points — reduce to 500 for web maps or storage without losing the route shape. |
| **Statistics** | Calculate distance, elevation gain/loss, duration, pace, speed, and heart rate statistics. Moving time excludes stopped segments automatically. |
| **Training analysis** | Classify effort level, estimate recovery time, and generate training suggestions. Rule-based, no external services needed. Works with or without HR data. |
| **Route features** | Identify mountain peaks, rivers, lakes, and settlements along a route using OpenStreetMap's Nominatim and Overpass APIs. Disk-cached to respect rate limits. |

## Requirements

- PHP 8.3+
- Extensions: `curl`, `simplexml`, `dom`, `json`

## Installation

```bash
composer require beljic/gpx-tools
```

## Quick start

```php
use Beljic\GpxTools\Parser\GpxParser;
use Beljic\GpxTools\Analyzer\TrackStatsCalculator;
use Beljic\GpxTools\Analyzer\TrainingAnalyzer;
use Beljic\GpxTools\Data\Sport;

$gpx   = (new GpxParser())->parseFile('track.gpx');
$stats = (new TrackStatsCalculator())->calculate($gpx);
$report = (new TrainingAnalyzer())->analyze($stats, Sport::TrailRunning);

echo $stats->distanceKm;          // 42.3
echo $stats->elevationGainM;      // 2150
echo $stats->durationFormatted(); // "5:42:18"
echo $stats->avgPaceFormatted();  // "8'06\"/km"
echo $report->effortLevel->value; // "very_hard"
echo $report->summary;
// 42.3 km · +2150 m elevation · 5:42:18 · avg pace 8'06"/km · avg HR 148 bpm · very_hard effort
```

## Usage

### Parse

```php
use Beljic\GpxTools\Parser\GpxParser;

$gpx = (new GpxParser())->parseFile('track.gpx');

$gpx->name;  // "Iron Ultra Trail Etapa 1"
$gpx->type;  // "trail_running"

$pt = $gpx->track[0]; // TrackPoint
$pt->lat;         // 44.0606380
$pt->lon;         // 19.9084890
$pt->ele;         // 487.2
$pt->time;        // DateTimeImmutable
$pt->heartRate;   // 69
$pt->cadence;     // 80
$pt->temperature; // 28.0
$pt->power;       // 0.0
```

Supports: track segments, routes, waypoints, Garmin `TrackPointExtension` namespace.

### Statistics

```php
use Beljic\GpxTools\Analyzer\TrackStatsCalculator;

$stats = (new TrackStatsCalculator())->calculate($gpx);

$stats->distanceKm;         // 42.3
$stats->elevationGainM;     // 2150
$stats->elevationLossM;     // 2140
$stats->maxElevationM;      // 1591
$stats->minElevationM;      // 330
$stats->durationSeconds;    // 20538
$stats->movingTimeSeconds;  // 19880  (excludes stopped segments)
$stats->avgPaceSecPerKm;    // 486    (8 min 6 sec per km)
$stats->avgSpeedKmh;        // 7.4
$stats->avgHeartRate;       // 148
$stats->maxHeartRate;       // 178
$stats->avgTemperature;     // 28.0

// Formatted helpers
$stats->durationFormatted(); // "5:42:18"
$stats->avgPaceFormatted();  // "8'06\"/km"
```

Elevation noise is filtered by a configurable threshold (default: 5 m) to reduce GPS inaccuracies from inflating gain numbers.

```php
// Stricter noise filter — only count elevation changes > 10 m
$calc  = new TrackStatsCalculator(elevationNoiseM: 10.0);
$stats = $calc->calculate($gpx);
```

### Training analysis

```php
use Beljic\GpxTools\Analyzer\TrainingAnalyzer;
use Beljic\GpxTools\Data\Sport;

$report = (new TrainingAnalyzer())->analyze($stats, Sport::TrailRunning);

$report->effortLevel; // EffortLevel::VeryHard
$report->sport;       // Sport::TrailRunning
$report->summary;     // one-line human-readable summary
$report->suggestions; // string[] — actionable recommendations

foreach ($report->suggestions as $s) {
    echo $s . "\n";
}
// "Plan at least 3 days of easy training or rest before the next hard session."
// "High elevation density (51 m/km). Focus on uphill running form and downhill quad strength."
```

**Effort classification** uses HR-based zones when heart rate data is available, or falls back to equivalent flat distance (distance + elevation gain / 100 m ≈ 1 km).

**Available sports:**

```php
Sport::Running
Sport::TrailRunning
Sport::Cycling
Sport::MountainBiking
Sport::Hiking
Sport::Skiing

// Auto-detect from GPX type field
$sport = Sport::fromGpxType($gpx->type); // "trail_running" → Sport::TrailRunning
```

### Clean

```php
use Beljic\GpxTools\Processor\GpxCleaner;
use Beljic\GpxTools\Parser\GpxWriter;

$cleaner = new GpxCleaner();

// Remove HR, cadence, temperature, power — keep coordinates, elevation, timestamps
$clean = $cleaner->stripExtensions($gpx);

// Remove timestamps — turns an activity into a plain route
$clean = $cleaner->stripTimestamps($gpx);

// Remove waypoints — keep only the track
$clean = $cleaner->trackOnly($gpx);

// Keep only lat/lon/ele — smallest possible GPX file
$clean = $cleaner->geometryOnly($gpx);

// Operations return new instances — original is unchanged
(new GpxWriter())->toFile($clean, 'output.gpx');
```

### Simplify

```php
use Beljic\GpxTools\Processor\GpxSimplifier;

$simplifier = new GpxSimplifier();

// Keep points at least 10 m apart — removes GPS over-sampling
$simple = $simplifier->byMinDistance($gpx, minDistanceM: 10.0);

// Target a specific point count using even spacing
$simple = $simplifier->byMaxPoints($gpx, maxPoints: 500);

echo count($gpx->track);    // 54286
echo count($simple->track); // 498
```

Start and end points are always preserved. Waypoints and track metadata carry over unchanged.

### Route features via OpenStreetMap

Queries [Nominatim](https://nominatim.org) for settlements and [Overpass API](https://overpass-api.de) for natural features (peaks, rivers, lakes) along the route.

```php
use Beljic\GpxTools\Analyzer\RouteAnalyzer;
use Beljic\GpxTools\Cache\FileCache;

$analyzer = new RouteAnalyzer(
    intervalKm:  2.0,   // Nominatim sample interval along track
    peakRadiusM: 200.0, // max distance from track to count a peak
    cache:       new FileCache('/tmp/gpx-cache/nominatim'),
);

$route = $analyzer->analyze($gpx);

// Peaks (sorted by elevation, descending)
foreach ($route->peaks as $peak) {
    echo "{$peak->name} ({$peak->elevation} m)\n";
}

// Places (villages, towns, cities along route)
foreach ($route->places as $place) {
    echo "{$place->category->value}: {$place->name}\n";
}

// Rivers, lakes
$route->rivers; // string[]
$route->lakes;  // string[]
```

**Place categories** (`PlaceCategory` enum): `city`, `town`, `village`, `hamlet`, `suburb`, `neighbourhood`, `quarter`, `locality`, `municipality`.

**Important — API usage:**
- Nominatim requires a [valid User-Agent](https://operations.osmfoundation.org/policies/nominatim/) and a maximum of 1 request/second. This library enforces the rate limit automatically. Always use a disk cache in production — `FileCache` writes responses as JSON files and skips the network on repeated runs.
- Overpass API is used for a single bulk query per analysis. No rate limit enforcement needed, but avoid querying very long tracks at high frequency.

## CLI

After `composer install`, the `gpx` binary is available at `vendor/bin/gpx`:

```
vendor/bin/gpx <command> <file.gpx> [options]
```

### Commands

**`parse`** — show file summary

```bash
vendor/bin/gpx parse track.gpx
# File:      track.gpx
# Name:      Iron Ultra Trail Etapa 1
# Type:      trail_running
# Points:    54286
# Waypoints: 0
# Has timestamps: yes
# Has elevation:  yes
# Has heart rate: yes
```

**`stats`** — track statistics and training report

```bash
vendor/bin/gpx stats track.gpx --sport=trail_running
```

**`clean`** — filter GPX data, write to file or stdout

```bash
# Remove sensor data, keep route shape
vendor/bin/gpx clean track.gpx --strip-ext --out=route.gpx

# Keep only lat/lon/ele
vendor/bin/gpx clean track.gpx --geometry-only --out=minimal.gpx

# Available flags: --strip-ext  --strip-ts  --track-only  --geometry-only
```

**`simplify`** — reduce point count

```bash
# Target 500 points
vendor/bin/gpx simplify track.gpx --max-points=500 --out=simple.gpx

# Minimum 10 m between points
vendor/bin/gpx simplify track.gpx --min-dist-m=10 --out=simple.gpx
# Reduced: 54286 → 498 points (1%)
```

**`analyze`** — full route + training analysis using OSM APIs

```bash
vendor/bin/gpx analyze track.gpx \
  --interval-km=2 \
  --peak-radius-m=200 \
  --cache-dir=./cache/nominatim \
  --sport=trail_running
```

## Framework integration

### Laravel

```php
// AppServiceProvider::register()
use Beljic\GpxTools\Analyzer\RouteAnalyzer;
use Beljic\GpxTools\Cache\FileCache;

$this->app->singleton(RouteAnalyzer::class, fn() => new RouteAnalyzer(
    cache: new FileCache(storage_path('gpx/nominatim')),
));
```

### Symfony

```yaml
# config/services.yaml
Beljic\GpxTools\Cache\FileCache:
    arguments:
        $directory: '%kernel.project_dir%/var/cache/gpx/nominatim'

Beljic\GpxTools\Analyzer\RouteAnalyzer:
    arguments:
        $cache: '@Beljic\GpxTools\Cache\FileCache'
```

### Magento 2

```xml
<!-- etc/di.xml -->
<type name="Beljic\GpxTools\Analyzer\RouteAnalyzer">
    <arguments>
        <argument name="cache" xsi:type="object">Beljic\GpxTools\Cache\FileCache</argument>
    </arguments>
</type>
```

### Custom HTTP client

Implement `HttpClientInterface` to use your framework's HTTP stack (Guzzle, Symfony HttpClient, Laravel HTTP, etc.):

```php
use Beljic\GpxTools\Http\HttpClientInterface;

class GuzzleAdapter implements HttpClientInterface
{
    public function __construct(private ClientInterface $client) {}

    public function get(string $url): ?string { /* ... */ }
    public function post(string $url, string $body): ?string { /* ... */ }
}

$analyzer = new RouteAnalyzer(http: new GuzzleAdapter($guzzle));
```

## Architecture

```
src/
  Parser/      GpxParser         Parse GPX files and strings into value objects
               GpxWriter         Serialize ParsedGpx back to GPX 1.1 XML

  Processor/   GpxCleaner        Strip or filter data from a parsed GPX
               GpxSimplifier     Reduce track point density

  Analyzer/    TrackStatsCalculator   Calculate statistics from track points
               TrainingAnalyzer       Classify effort, generate suggestions
               RouteAnalyzer          Identify terrain features via OSM APIs

  External/    Nominatim/        Reverse geocoding client + place classifier
               Overpass/         Bulk natural features query client

  Data/        TrackPoint        lat, lon, ele, time, hr, cadence, temp, power
               Waypoint          lat, lon, ele, name, description, time
               ParsedGpx         track[], waypoints[], name, type
               TrackStats        all calculated statistics
               TrainingReport    effortLevel, summary, suggestions, zones
               Sport             enum — running, trail_running, cycling, ...
               EffortLevel       enum — recovery, easy, moderate, hard, very_hard, race
               RouteAnalysis     peaks[], places[], rivers[], lakes[], waypoints[]
               Peak              name, elevation
               Place             category (PlaceCategory), name
               PlaceCategory     enum — city, town, village, hamlet, ...

  Support/     Geo               Haversine distance calculation

  Cache/       CacheInterface    get / set / has
               FileCache         JSON files on disk — suitable for all frameworks
               NullCache         No-op — useful for testing

  Http/        HttpClientInterface   get / post
               CurlHttpClient        Default implementation with retry on 429/503
```

All dependencies (HTTP client, cache) are constructor-injectable. The core parsing, processing, and statistics classes have no external dependencies beyond PHP extensions.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

| Test class | What it covers |
|---|---|
| `GeoTest` | Haversine math — known distances, symmetry, zero |
| `GpxParserTest` | Track points, Garmin extensions, timestamps, waypoints, error handling |
| `GpxWriterTest` | Round-trip (parse → write → parse) — coordinates, extensions, metadata, valid XML |
| `GpxCleanerTest` | Each clean operation removes/preserves the correct fields; immutability |
| `GpxSimplifierTest` | Point reduction; start/end preservation; edge cases (empty, single point) |
| `TrackStatsCalculatorTest` | Distance, elevation, duration, HR, empty track |
| `TrainingAnalyzerTest` | Effort classification (distance-based and HR-based); suggestions |
| `SportTest` | GPX type detection; pace/speed classification |
| `PlaceClassifierTest` | Settlement mapping; priority order; locality fallback |

No unit test makes external API calls. All tests run against a synthetic GPX fixture.

To test `RouteAnalyzer` manually with a real file:

```bash
vendor/bin/gpx analyze track.gpx --cache-dir=./cache/nominatim
```

Nominatim responses are cached after the first run — subsequent runs are instant.

## Contributing

Bug reports and pull requests are welcome. For significant changes, open an issue first to discuss the approach.

- Keep the framework-agnostic core: no Laravel/Symfony imports in `src/`
- New analyzers belong in `Analyzer/`, new API clients in `External/`
- Tests required for all non-trivial behavior
- Follow existing naming conventions — `readonly` classes for value objects, enums for fixed sets

## License

MIT — see [LICENSE](LICENSE)
