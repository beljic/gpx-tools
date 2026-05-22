<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\GpxMetadata;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Data\Waypoint;
use Beljic\GpxTools\Parser\GpxParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class GpxMetadataTest extends TestCase
{
    public function testEmptyGpxHasNoFlags(): void
    {
        $meta = GpxMetadata::fromParsedGpx(new ParsedGpx());

        $this->assertNull($meta->sport);
        $this->assertNull($meta->startTime);
        $this->assertNull($meta->endTime);
        $this->assertSame(0, $meta->waypointCount);
        $this->assertFalse($meta->hasHeartRate);
        $this->assertFalse($meta->hasCadence);
        $this->assertFalse($meta->hasPower);
        $this->assertFalse($meta->hasTemperature);
        $this->assertFalse($meta->isActivity);
    }

    public function testDetectsSportFromType(): void
    {
        $gpx  = new ParsedGpx(type: 'trail_running');
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertSame(Sport::TrailRunning, $meta->sport);
    }

    public function testIsActivityWhenTimestampsPresent(): void
    {
        $t1 = new DateTimeImmutable('2026-01-01T08:00:00Z');
        $t2 = new DateTimeImmutable('2026-01-01T08:10:00Z');
        $gpx = new ParsedGpx(track: [
            new TrackPoint(lat: 44.0, lon: 19.0, time: $t1),
            new TrackPoint(lat: 44.1, lon: 19.1, time: $t2),
        ]);
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertTrue($meta->isActivity);
        $this->assertSame($t1, $meta->startTime);
        $this->assertSame($t2, $meta->endTime);
    }

    public function testIsNotActivityWithoutTimestamps(): void
    {
        $gpx = new ParsedGpx(track: [
            new TrackPoint(lat: 44.0, lon: 19.0),
            new TrackPoint(lat: 44.1, lon: 19.1),
        ]);
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertFalse($meta->isActivity);
        $this->assertNull($meta->startTime);
        $this->assertNull($meta->endTime);
    }

    public function testDetectsSensorFlags(): void
    {
        $gpx = new ParsedGpx(track: [
            new TrackPoint(lat: 44.0, lon: 19.0, heartRate: 140, cadence: 80, power: 200.0, temperature: 15.0),
        ]);
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertTrue($meta->hasHeartRate);
        $this->assertTrue($meta->hasCadence);
        $this->assertTrue($meta->hasPower);
        $this->assertTrue($meta->hasTemperature);
    }

    public function testCountsWaypoints(): void
    {
        $gpx = new ParsedGpx(waypoints: [
            new Waypoint(lat: 44.0, lon: 19.0, name: 'A'),
            new Waypoint(lat: 44.1, lon: 19.1, name: 'B'),
        ]);
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertSame(2, $meta->waypointCount);
    }

    public function testFromFixtureHasAllSensors(): void
    {
        $gpx  = (new GpxParser())->parseFile(dirname(__DIR__) . '/fixtures/sample.gpx');
        $meta = GpxMetadata::fromParsedGpx($gpx);

        $this->assertSame(Sport::TrailRunning, $meta->sport);
        $this->assertTrue($meta->isActivity);
        $this->assertTrue($meta->hasHeartRate);
        $this->assertTrue($meta->hasCadence);
        $this->assertTrue($meta->hasTemperature);
        $this->assertSame(1, $meta->waypointCount);
    }
}
