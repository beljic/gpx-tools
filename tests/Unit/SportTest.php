<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\Sport;
use PHPUnit\Framework\TestCase;

class SportTest extends TestCase
{
    /** @dataProvider gpxTypeProvider */
    public function testFromGpxType(string $type, Sport $expected): void
    {
        $this->assertSame($expected, Sport::fromGpxType($type));
    }

    public static function gpxTypeProvider(): array
    {
        return [
            ['trail_running',    Sport::TrailRunning],
            ['Trail Run',        Sport::TrailRunning],
            ['running',          Sport::Running],
            ['run',              Sport::Running],
            ['cycling',          Sport::Cycling],
            ['ride',             Sport::Cycling],
            ['mountain_biking',  Sport::MountainBiking],
            ['mtb',              Sport::MountainBiking],
            ['hiking',           Sport::Hiking],
            ['walk',             Sport::Hiking],
            ['skiing',           Sport::Skiing],
            ['unknown_sport',    Sport::Unknown],
            ['',                 Sport::Unknown],
        ];
    }

    public function testFromGpxTypeNullReturnsUnknown(): void
    {
        $this->assertSame(Sport::Unknown, Sport::fromGpxType(null));
    }

    public function testIsPaceBasedForRunningSports(): void
    {
        $this->assertTrue(Sport::Running->isPaceBased());
        $this->assertTrue(Sport::TrailRunning->isPaceBased());
        $this->assertTrue(Sport::Hiking->isPaceBased());
    }

    public function testIsPaceBasedFalseForCycling(): void
    {
        $this->assertFalse(Sport::Cycling->isPaceBased());
        $this->assertFalse(Sport::MountainBiking->isPaceBased());
    }

    public function testIsSpeedBasedForCyclingSports(): void
    {
        $this->assertTrue(Sport::Cycling->isSpeedBased());
        $this->assertTrue(Sport::MountainBiking->isSpeedBased());
        $this->assertTrue(Sport::Skiing->isSpeedBased());
    }

    public function testIsSpeedBasedFalseForRunning(): void
    {
        $this->assertFalse(Sport::Running->isSpeedBased());
        $this->assertFalse(Sport::TrailRunning->isSpeedBased());
    }
}
