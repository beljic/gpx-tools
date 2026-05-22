<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\TrackStatsCalculator;
use Beljic\GpxTools\Analyzer\TrainingAnalyzer;
use Beljic\GpxTools\Data\Bounds;
use Beljic\GpxTools\Data\GpxMetadata;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Normalizer\GpxNormalizer;
use Beljic\GpxTools\Parser\GpxParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class GpxNormalizerTest extends TestCase
{
    private GpxNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GpxNormalizer();
    }

    public function testParsedGpxToArrayHasExpectedKeys(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $array = $this->normalizer->parsedGpxToArray($gpx);

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('track', $array);
        $this->assertArrayHasKey('waypoints', $array);
        $this->assertCount(3, $array['track']);
    }

    public function testTrackPointArrayHasExpectedKeys(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $array = $this->normalizer->parsedGpxToArray($gpx);
        $pt    = $array['track'][0];

        foreach (['lat', 'lon', 'ele', 'time', 'hr', 'cad', 'temp', 'pow'] as $key) {
            $this->assertArrayHasKey($key, $pt);
        }
    }

    public function testStatsToArrayHasFormattedHelpers(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats = (new TrackStatsCalculator())->calculate($gpx);
        $array = $this->normalizer->statsToArray($stats);

        $this->assertArrayHasKey('distance_km', $array);
        $this->assertArrayHasKey('elevation_gain_m', $array);
        $this->assertArrayHasKey('duration_formatted', $array);
        $this->assertArrayHasKey('avg_pace_formatted', $array);
        $this->assertIsFloat($array['distance_km']);
    }

    public function testTrainingReportToArrayHasEffortLevel(): void
    {
        $gpx    = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats  = (new TrackStatsCalculator())->calculate($gpx);
        $report = (new TrainingAnalyzer())->analyze($stats, Sport::TrailRunning);
        $array  = $this->normalizer->trainingReportToArray($report);

        $this->assertArrayHasKey('effort_level', $array);
        $this->assertArrayHasKey('sport', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('suggestions', $array);
        $this->assertIsString($array['effort_level']);
    }

    public function testBoundsToArrayIncludesCenter(): void
    {
        $bounds = new Bounds(minLat: 44.0, maxLat: 45.0, minLon: 19.0, maxLon: 21.0);
        $array  = $this->normalizer->boundsToArray($bounds);

        $this->assertArrayHasKey('min_lat', $array);
        $this->assertArrayHasKey('max_lat', $array);
        $this->assertArrayHasKey('center', $array);
        $this->assertEqualsWithDelta(44.5, $array['center']['lat'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $array['center']['lon'], 0.0001);
    }

    public function testMetadataToArrayHasSensorFlags(): void
    {
        $gpx   = new ParsedGpx(
            track: [new TrackPoint(lat: 44.0, lon: 19.0, heartRate: 140, time: new DateTimeImmutable())],
            type:  'running',
        );
        $meta  = GpxMetadata::fromParsedGpx($gpx);
        $array = $this->normalizer->metadataToArray($meta);

        $this->assertArrayHasKey('sport', $array);
        $this->assertArrayHasKey('is_activity', $array);
        $this->assertArrayHasKey('has_heart_rate', $array);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertTrue($array['has_heart_rate']);
        $this->assertTrue($array['is_activity']);
        $this->assertSame('running', $array['sport']);
    }

    public function testNullBoundsProducesNullInSummaryArray(): void
    {
        $gpx      = new ParsedGpx();
        $stats    = (new TrackStatsCalculator())->calculate($gpx);
        $training = (new TrainingAnalyzer())->analyze($stats);
        $meta     = GpxMetadata::fromParsedGpx($gpx);

        $summary = new \Beljic\GpxTools\Data\ActivitySummary(
            gpx:      $gpx,
            stats:    $stats,
            training: $training,
            route:    new \Beljic\GpxTools\Data\RouteAnalysis(),
            bounds:   null,
            metadata: $meta,
        );

        $array = $this->normalizer->activitySummaryToArray($summary);

        $this->assertNull($array['bounds']);
        $this->assertArrayHasKey('gpx', $array);
        $this->assertArrayHasKey('stats', $array);
        $this->assertArrayHasKey('training', $array);
        $this->assertArrayHasKey('route', $array);
        $this->assertArrayHasKey('metadata', $array);
    }
}
