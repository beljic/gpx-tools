<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\RouteProfileAnalyzer;
use Beljic\GpxTools\Data\GradientBand;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use PHPUnit\Framework\TestCase;

class RouteProfileAnalyzerTest extends TestCase
{
    /** One degree of latitude is close enough to 111.19 km for a synthetic route. */
    private const KM_PER_DEGREE_LAT = 111.19492664455873;

    /**
     * Builds a route running due north from the equator so that distance is a
     * simple function of latitude and the expected gradients can be worked out
     * by hand.
     *
     * @param  list<array{0: float, 1: float}>  $legs  [length in km, gradient in percent]
     */
    private function route(array $legs, float $startEle = 100.0): ParsedGpx
    {
        $points = [new TrackPoint(lat: 0.0, lon: 0.0, ele: $startEle)];

        $lat = 0.0;
        $ele = $startEle;

        foreach ($legs as [$lengthKm, $gradientPercent]) {
            // Sample every 25 m so resampling has real data to interpolate from.
            $stepKm = 0.025;
            $steps  = (int) round($lengthKm / $stepKm);

            for ($i = 0; $i < $steps; $i++) {
                $lat += $stepKm / self::KM_PER_DEGREE_LAT;
                $ele += $stepKm * 1000.0 * ($gradientPercent / 100.0);
                $points[] = new TrackPoint(lat: $lat, lon: 0.0, ele: $ele);
            }
        }

        return new ParsedGpx(track: $points);
    }

    public function testFlatRouteIsAllFlatAndCostsItsOwnDistance(): void
    {
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([[4.0, 0.0]]));

        $this->assertEqualsWithDelta(4.0, $profile->distanceKm, 0.05);
        $this->assertEqualsWithDelta(100.0, $profile->flatPercentOfRoute, 0.5);
        $this->assertEqualsWithDelta(0.0, $profile->climbPercentOfRoute, 0.5);
        $this->assertEqualsWithDelta(1.0, $profile->gradeAdjustedFactor, 0.01);
    }

    public function testSteadyClimbLandsInTheExpectedBand(): void
    {
        // 2 km at a steady 10% is a "climb", between 8 and 15 percent.
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([[2.0, 10.0]]));

        $this->assertEqualsWithDelta(100.0, $profile->climbPercentOfRoute, 1.0);

        $climbing = array_values(array_filter(
            $profile->distribution,
            fn ($bucket) => $bucket->band === GradientBand::Climb
        ));

        $this->assertCount(1, $climbing);
        $this->assertEqualsWithDelta(2.0, $climbing[0]->distanceKm, 0.05);
        $this->assertEqualsWithDelta(200.0, $climbing[0]->elevationGainM, 5.0);
    }

    public function testClimbingCostsMoreThanItsMapDistance(): void
    {
        $flat  = (new RouteProfileAnalyzer())->analyze($this->route([[2.0, 0.0]]));
        $climb = (new RouteProfileAnalyzer())->analyze($this->route([[2.0, 10.0]]));

        $this->assertGreaterThan($flat->flatEquivalentKm, $climb->flatEquivalentKm);
        // Minetti's polynomial at i = 0.10 gives 5.968 J/kg/m against 3.6 on
        // the level, so a steady 10% climb costs about 1.66x its map distance.
        $this->assertEqualsWithDelta(1.66, $climb->gradeAdjustedFactor, 0.05);
    }

    public function testGentleDescentIsCheaperThanTheFlat(): void
    {
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([[3.0, -10.0]], 500.0));

        $this->assertLessThan(1.0, $profile->gradeAdjustedFactor);
        $this->assertEqualsWithDelta(100.0, $profile->descentPercentOfRoute, 1.0);
    }

    public function testExtremesFindTheLongestClimbAndTheHighPoint(): void
    {
        // up 1 km at 8%, down 0.5 km, then up 2 km at 6%: the second climb wins.
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([
            [1.0, 8.0],
            [0.5, -6.0],
            [2.0, 6.0],
        ]));

        $this->assertNotNull($profile->extremes->longestClimb);
        $this->assertEqualsWithDelta(2.0, $profile->extremes->longestClimb->lengthKm, 0.15);

        $this->assertNotNull($profile->extremes->longestDescent);
        $this->assertEqualsWithDelta(0.5, $profile->extremes->longestDescent->lengthKm, 0.15);

        // Starts at 100, +80, -30, +120 => 270 at the finish, which is the peak.
        $this->assertEqualsWithDelta(270.0, $profile->extremes->highestPointM, 5.0);
        $this->assertEqualsWithDelta(3.5, $profile->extremes->highestPointAtKm, 0.1);
        $this->assertEqualsWithDelta(100.0, $profile->extremes->lowestPointM, 5.0);
    }

    public function testShortBlipsDoNotBecomeTheirOwnSegments(): void
    {
        // A 50 m dip in the middle of a long climb must not split it into three.
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([
            [1.5, 9.0],
            [0.05, -4.0],
            [1.5, 9.0],
        ]));

        $this->assertCount(1, $profile->segments);
        $this->assertSame(GradientBand::Climb, $profile->segments[0]->band);
    }

    public function testMatrixSeparatesOneLongWallFromManyShortRamps(): void
    {
        $wall = (new RouteProfileAnalyzer())->analyze($this->route([[3.0, 10.0]]));

        $this->assertEqualsWithDelta(3.0, $wall->matrix[GradientBand::Climb->value]['2-5'], 0.1);
        $this->assertSame(0.0, $wall->matrix[GradientBand::Climb->value]['<0.5']);
    }

    public function testSamplesAreCappedForTheBrowser(): void
    {
        $profile = (new RouteProfileAnalyzer(maxSamples: 50))->analyze($this->route([[20.0, 2.0]]));

        $this->assertLessThanOrEqual(51, count($profile->samples));
        $this->assertGreaterThan(10, count($profile->samples));
    }

    public function testRouteWithoutElevationYieldsAnEmptyProfile(): void
    {
        $points = [
            new TrackPoint(lat: 0.0, lon: 0.0),
            new TrackPoint(lat: 0.01, lon: 0.0),
        ];

        $profile = (new RouteProfileAnalyzer())->analyze(new ParsedGpx(track: $points));

        $this->assertSame(0.0, $profile->distanceKm);
        $this->assertSame([], $profile->segments);
        $this->assertSame(1.0, $profile->gradeAdjustedFactor);
    }

    public function testEmptyTrackDoesNotBlowUp(): void
    {
        $profile = (new RouteProfileAnalyzer())->analyze(new ParsedGpx());

        $this->assertSame(0.0, $profile->distanceKm);
        $this->assertSame([], $profile->samples);
        $this->assertCount(7, $profile->distribution);
    }

    public function testDistributionCoversTheWholeRoute(): void
    {
        $profile = (new RouteProfileAnalyzer())->analyze($this->route([
            [1.0, 12.0],
            [1.0, 0.0],
            [1.0, -12.0],
        ]));

        $covered = array_sum(array_map(fn ($bucket) => $bucket->distanceKm, $profile->distribution));

        $this->assertEqualsWithDelta($profile->distanceKm, $covered, 0.01);
        $this->assertEqualsWithDelta(100.0, array_sum(array_map(
            fn ($bucket) => $bucket->percentOfRoute,
            $profile->distribution
        )), 0.5);
    }
}