<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\Bounds;
use Beljic\GpxTools\Data\TrackPoint;
use PHPUnit\Framework\TestCase;

class BoundsTest extends TestCase
{
    public function testReturnsNullForEmptyTrack(): void
    {
        $this->assertNull(Bounds::fromTrack([]));
    }

    public function testSinglePointBoundsAreEqual(): void
    {
        $pt     = new TrackPoint(lat: 44.0, lon: 19.0);
        $bounds = Bounds::fromTrack([$pt]);

        $this->assertNotNull($bounds);
        $this->assertSame(44.0, $bounds->minLat);
        $this->assertSame(44.0, $bounds->maxLat);
        $this->assertSame(19.0, $bounds->minLon);
        $this->assertSame(19.0, $bounds->maxLon);
    }

    public function testCalculatesMinMaxFromTrack(): void
    {
        $points = [
            new TrackPoint(lat: 44.0, lon: 19.0),
            new TrackPoint(lat: 44.5, lon: 20.5),
            new TrackPoint(lat: 43.8, lon: 18.7),
        ];
        $bounds = Bounds::fromTrack($points);

        $this->assertNotNull($bounds);
        $this->assertEqualsWithDelta(43.8, $bounds->minLat, 0.0001);
        $this->assertEqualsWithDelta(44.5, $bounds->maxLat, 0.0001);
        $this->assertEqualsWithDelta(18.7, $bounds->minLon, 0.0001);
        $this->assertEqualsWithDelta(20.5, $bounds->maxLon, 0.0001);
    }

    public function testCenterIsAverageOfMinMax(): void
    {
        $bounds = new Bounds(minLat: 44.0, maxLat: 45.0, minLon: 19.0, maxLon: 21.0);
        $center = $bounds->center();

        $this->assertEqualsWithDelta(44.5, $center['lat'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $center['lon'], 0.0001);
    }
}
