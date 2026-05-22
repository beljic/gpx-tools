<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\MapPayload;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use PHPUnit\Framework\TestCase;

class MapPayloadTest extends TestCase
{
    public function testReturnsNullForEmptyTrack(): void
    {
        $this->assertNull(MapPayload::fromParsedGpx(new ParsedGpx()));
    }

    public function testReturnsAllPointsWhenBelowMax(): void
    {
        $points = [
            new TrackPoint(lat: 44.0, lon: 19.0),
            new TrackPoint(lat: 44.1, lon: 19.1),
            new TrackPoint(lat: 44.2, lon: 19.2),
        ];
        $payload = MapPayload::fromParsedGpx(new ParsedGpx(track: $points), maxPoints: 10);

        $this->assertNotNull($payload);
        $this->assertCount(3, $payload->points);
    }

    public function testDownsamplesToMaxPoints(): void
    {
        $points = [];
        for ($i = 0; $i < 1000; $i++) {
            $points[] = new TrackPoint(lat: 44.0 + $i * 0.001, lon: 19.0);
        }

        $payload = MapPayload::fromParsedGpx(new ParsedGpx(track: $points), maxPoints: 100);

        $this->assertNotNull($payload);
        $this->assertCount(100, $payload->points);
    }

    public function testPreservesFirstAndLastPoint(): void
    {
        $points = [];
        for ($i = 0; $i < 1000; $i++) {
            $points[] = new TrackPoint(lat: 44.0 + $i * 0.001, lon: 19.0);
        }

        $payload = MapPayload::fromParsedGpx(new ParsedGpx(track: $points), maxPoints: 50);

        $this->assertNotNull($payload);
        $this->assertSame($points[0], $payload->points[0]);
        $this->assertSame($points[999], $payload->points[count($payload->points) - 1]);
    }

    public function testBoundsAreSetCorrectly(): void
    {
        $points = [
            new TrackPoint(lat: 44.0, lon: 19.0),
            new TrackPoint(lat: 45.0, lon: 20.0),
        ];
        $payload = MapPayload::fromParsedGpx(new ParsedGpx(track: $points));

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(44.0, $payload->bounds->minLat, 0.0001);
        $this->assertEqualsWithDelta(45.0, $payload->bounds->maxLat, 0.0001);
    }
}
