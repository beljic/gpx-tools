<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\FullActivityAnalyzer;
use Beljic\GpxTools\Cache\NullCache;
use Beljic\GpxTools\Data\ActivitySummary;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Http\HttpClientInterface;
use PHPUnit\Framework\TestCase;

class FullActivityAnalyzerTest extends TestCase
{
    private HttpClientInterface $http;

    protected function setUp(): void
    {
        // Stub returns empty-but-valid JSON for both Nominatim (GET) and Overpass (POST)
        $this->http = new class implements HttpClientInterface {
            #[\Override]
            public function get(string $url): ?string
            {
                return '{}';
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return '{"elements":[]}';
            }
        };
    }

    public function testAnalyzeFileReturnsActivitySummary(): void
    {
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeFile(dirname(__DIR__) . '/fixtures/sample.gpx');

        $this->assertInstanceOf(ActivitySummary::class, $summary);
        $this->assertCount(3, $summary->gpx->track);
        $this->assertGreaterThan(0.0, $summary->stats->distanceKm);
        $this->assertNotNull($summary->bounds);
        $this->assertTrue($summary->metadata->isActivity);
    }

    public function testAnalyzeStringReturnsActivitySummary(): void
    {
        $content  = file_get_contents(dirname(__DIR__) . '/fixtures/sample.gpx');
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeString($content);

        $this->assertInstanceOf(ActivitySummary::class, $summary);
        $this->assertCount(3, $summary->gpx->track);
    }

    public function testAutoDetectsSportFromGpxType(): void
    {
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeFile(dirname(__DIR__) . '/fixtures/sample.gpx');

        // sample.gpx has type="trail_running"
        $this->assertSame(Sport::TrailRunning, $summary->training->sport);
    }

    public function testExplicitSportOverridesAutoDetect(): void
    {
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeFile(
            path:  dirname(__DIR__) . '/fixtures/sample.gpx',
            sport: Sport::Cycling,
        );

        $this->assertSame(Sport::Cycling, $summary->training->sport);
    }

    public function testBoundsAreWithinTrackCoordinates(): void
    {
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeFile(dirname(__DIR__) . '/fixtures/sample.gpx');

        $this->assertNotNull($summary->bounds);
        // sample.gpx spans 44.0606–44.0626 lat
        $this->assertEqualsWithDelta(44.0606, $summary->bounds->minLat, 0.0001);
        $this->assertEqualsWithDelta(44.0626, $summary->bounds->maxLat, 0.0001);
    }

    public function testMetadataHasSensorFlags(): void
    {
        $analyzer = new FullActivityAnalyzer(http: $this->http, cache: new NullCache());
        $summary  = $analyzer->analyzeFile(dirname(__DIR__) . '/fixtures/sample.gpx');

        $this->assertTrue($summary->metadata->hasHeartRate);
        $this->assertTrue($summary->metadata->hasCadence);
        $this->assertTrue($summary->metadata->hasTemperature);
    }
}
