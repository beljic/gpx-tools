<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\TrackStatsCalculator;
use Beljic\GpxTools\Parser\GpxParser;
use PHPUnit\Framework\TestCase;

class TrackStatsCalculatorTest extends TestCase
{
    public function testCalculatesDistanceFromFixture(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats = (new TrackStatsCalculator())->calculate($gpx);

        // 3 points ~111 m apart each = ~0.222 km total
        $this->assertEqualsWithDelta(0.222, $stats->distanceKm, 0.01);
    }

    public function testCalculatesElevationGain(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats = (new TrackStatsCalculator())->calculate($gpx);

        // 487.2 → 510 → 530 = ~42.8 m gain
        $this->assertGreaterThan(40.0, $stats->elevationGainM);
        $this->assertEqualsWithDelta(0.0, $stats->elevationLossM, 0.1);
    }

    public function testCalculatesDuration(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats = (new TrackStatsCalculator())->calculate($gpx);

        // 3 points, 1 min apart = 120 seconds total
        $this->assertEqualsWithDelta(120.0, $stats->durationSeconds, 1.0);
    }

    public function testCalculatesHeartRate(): void
    {
        $gpx   = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $stats = (new TrackStatsCalculator())->calculate($gpx);

        $this->assertNotNull($stats->avgHeartRate);
        $this->assertSame(155, $stats->maxHeartRate);
    }

    public function testEmptyTrackReturnsZeroStats(): void
    {
        $gpx   = new \Beljic\GpxTools\Data\ParsedGpx(track: []);
        $stats = (new TrackStatsCalculator())->calculate($gpx);

        $this->assertSame(0.0, $stats->distanceKm);
        $this->assertSame(0, $stats->pointCount);
    }
}
