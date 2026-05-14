<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Parser\GpxParser;
use Beljic\GpxTools\Processor\GpxSimplifier;
use PHPUnit\Framework\TestCase;

class GpxSimplifierTest extends TestCase
{
    private GpxSimplifier $simplifier;

    protected function setUp(): void
    {
        $this->simplifier = new GpxSimplifier();
    }

    public function testByMinDistanceReducesPoints(): void
    {
        // 3 points ~111 m apart — filter at 200 m should keep only first and last
        $gpx     = (new GpxParser())->parseFile($this->fixture('sample.gpx'));
        $simple  = $this->simplifier->byMinDistance($gpx, minDistanceM: 200.0);

        $this->assertLessThan(count($gpx->track), count($simple->track));
    }

    public function testByMinDistanceAlwaysKeepsFirstAndLastPoint(): void
    {
        $gpx    = (new GpxParser())->parseFile($this->fixture('sample.gpx'));
        $simple = $this->simplifier->byMinDistance($gpx, minDistanceM: 999_999.0);

        $this->assertCount(2, $simple->track);
        $this->assertSame($gpx->track[0]->lat,                       $simple->track[0]->lat);
        $this->assertSame($gpx->track[count($gpx->track) - 1]->lat, $simple->track[1]->lat);
    }

    public function testByMinDistanceWithZeroThresholdKeepsAllPoints(): void
    {
        $gpx    = (new GpxParser())->parseFile($this->fixture('sample.gpx'));
        $simple = $this->simplifier->byMinDistance($gpx, minDistanceM: 0.0);

        $this->assertSame(count($gpx->track), count($simple->track));
    }

    public function testByMaxPointsReducesToTarget(): void
    {
        $points = $this->buildLinePoints(100);
        $gpx    = new ParsedGpx(track: $points);
        $simple = $this->simplifier->byMaxPoints($gpx, maxPoints: 10);

        $this->assertLessThanOrEqual(11, count($simple->track)); // ±1 for overshoot residual
    }

    public function testByMaxPointsAlwaysKeepsFirstAndLastPoint(): void
    {
        $points = $this->buildLinePoints(50);
        $gpx    = new ParsedGpx(track: $points);
        $simple = $this->simplifier->byMaxPoints($gpx, maxPoints: 5);

        $first = $points[0];
        $last  = $points[49];

        $this->assertSame($first->lat, $simple->track[0]->lat);
        $this->assertSame($last->lat,  $simple->track[count($simple->track) - 1]->lat);
    }

    public function testByMaxPointsReturnsUnchangedIfAlreadyUnderLimit(): void
    {
        $gpx    = (new GpxParser())->parseFile($this->fixture('sample.gpx'));
        $simple = $this->simplifier->byMaxPoints($gpx, maxPoints: 1000);

        $this->assertSame(count($gpx->track), count($simple->track));
    }

    public function testEmptyTrackReturnedUnchanged(): void
    {
        $gpx    = new ParsedGpx(track: []);
        $simple = $this->simplifier->byMinDistance($gpx, minDistanceM: 10.0);

        $this->assertEmpty($simple->track);
    }

    public function testSinglePointReturnedUnchanged(): void
    {
        $gpx    = new ParsedGpx(track: [new TrackPoint(lat: 44.0, lon: 19.0)]);
        $simple = $this->simplifier->byMinDistance($gpx, minDistanceM: 10.0);

        $this->assertCount(1, $simple->track);
    }

    public function testPreservesWaypointsAndMetadata(): void
    {
        $gpx    = (new GpxParser())->parseFile($this->fixture('sample.gpx'));
        $simple = $this->simplifier->byMaxPoints($gpx, maxPoints: 2);

        $this->assertSame($gpx->name,      $simple->name);
        $this->assertSame($gpx->type,      $simple->type);
        $this->assertCount(count($gpx->waypoints), $simple->waypoints);
    }

    /** @return TrackPoint[] */
    private function buildLinePoints(int $n): array
    {
        $points = [];
        for ($i = 0; $i < $n; $i++) {
            // Each step ~111 m north
            $points[] = new TrackPoint(lat: 44.0 + $i * 0.001, lon: 19.0);
        }
        return $points;
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
