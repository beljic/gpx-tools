<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function testZeroDistanceForSamePoint(): void
    {
        $this->assertEqualsWithDelta(0.0, Geo::haversineKm(44.0, 19.0, 44.0, 19.0), 0.0001);
    }

    public function testKnownDistanceBelgradeParis(): void
    {
        // Belgrade (44.8167, 20.4667) → Paris (48.8566, 2.3522) ≈ 1737 km
        $km = Geo::haversineKm(44.8167, 20.4667, 48.8566, 2.3522);
        $this->assertEqualsWithDelta(1737, $km, 5);
    }

    public function testApproximately111KmPerDegreeLatitude(): void
    {
        // 1 degree of latitude ≈ 111.195 km
        $km = Geo::haversineKm(0.0, 0.0, 1.0, 0.0);
        $this->assertEqualsWithDelta(111.195, $km, 0.01);
    }

    public function testSymmetric(): void
    {
        $a = Geo::haversineKm(44.0, 19.0, 45.0, 20.0);
        $b = Geo::haversineKm(45.0, 20.0, 44.0, 19.0);
        $this->assertEqualsWithDelta($a, $b, 0.0001);
    }
}
