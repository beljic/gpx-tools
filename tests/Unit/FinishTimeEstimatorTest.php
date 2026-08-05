<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\FinishTimeEstimator;
use Beljic\GpxTools\Data\RouteExtremes;
use Beljic\GpxTools\Data\RouteProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FinishTimeEstimatorTest extends TestCase
{
    /**
     * A profile with only the numbers the estimator reads, so a test can state
     * the two equivalent distances independently and see which one is used.
     */
    private function profile(
        float $distanceKm,
        float $flatEquivalentKm,
        float $trailEquivalentKm,
    ): RouteProfile {
        return new RouteProfile(
            segments:               [],
            distribution:           [],
            extremes:               new RouteExtremes,
            matrix:                 [],
            samples:                [],
            distanceKm:             $distanceKm,
            flatEquivalentKm:       $flatEquivalentKm,
            trailEquivalentKm:      $trailEquivalentKm,
            totalAscentM:           0.0,
            totalDescentM:          0.0,
            gradeAdjustedFactor:    $distanceKm > 0.0 ? $flatEquivalentKm / $distanceKm : 1.0,
            averageGradientPercent: 0.0,
            climbPercentOfRoute:    0.0,
            descentPercentOfRoute:  0.0,
            flatPercentOfRoute:     100.0,
        );
    }

    public function testAFlatRouteOfTheReferenceDistanceReturnsTheReferenceTime(): void
    {
        $profile = $this->profile(
            distanceKm:        FinishTimeEstimator::HALF_MARATHON_KM,
            flatEquivalentKm:  FinishTimeEstimator::HALF_MARATHON_KM,
            trailEquivalentKm: FinishTimeEstimator::HALF_MARATHON_KM,
        );

        $estimate = (new FinishTimeEstimator)->estimate($profile, 7200);

        $this->assertSame(7200, $estimate->estimatedSeconds);
        $this->assertSame('2:00:00', $estimate->formatted());
    }

    public function testTheEstimateRunsOverTheTrailEquivalentNotTheMinettiOne(): void
    {
        // Same route measured two ways: Minetti says 60 km of effort, the trail
        // rule of thumb says 90. Riegel over 90 km must be the slower answer.
        $profile = $this->profile(distanceKm: 57.0, flatEquivalentKm: 60.0, trailEquivalentKm: 90.0);

        $estimate = (new FinishTimeEstimator)->estimate($profile, 7200);

        $overMinetti = 7200 * (60.0 / FinishTimeEstimator::HALF_MARATHON_KM) ** FinishTimeEstimator::DEFAULT_EXPONENT;
        $overTrail   = 7200 * (90.0 / FinishTimeEstimator::HALF_MARATHON_KM) ** FinishTimeEstimator::DEFAULT_EXPONENT;

        $this->assertEqualsWithDelta($overTrail, $estimate->estimatedSeconds, 1.0);
        $this->assertGreaterThan($overMinetti, $estimate->estimatedSeconds);
        $this->assertSame(90.0, $estimate->effortDistanceKm);
    }

    public function testPaceIsReportedOverTheGroundActuallyCovered(): void
    {
        $profile = $this->profile(distanceKm: 40.0, flatEquivalentKm: 44.0, trailEquivalentKm: 60.0);

        $estimate = (new FinishTimeEstimator)->estimate($profile, 7200);

        $this->assertSame(40.0, $estimate->routeDistanceKm);
        $this->assertEqualsWithDelta($estimate->estimatedSeconds / 40.0, $estimate->paceSecPerKm(), 0.001);
    }

    public function testArrayFormNamesTheDistanceItActuallyRanOver(): void
    {
        $profile = $this->profile(distanceKm: 10.0, flatEquivalentKm: 11.0, trailEquivalentKm: 13.0);

        $array = (new FinishTimeEstimator)->estimate($profile, 3600)->toArray();

        $this->assertSame(13.0, $array['effort_distance_km']);
        $this->assertArrayNotHasKey('flat_equivalent_km', $array);
    }

    public function testARouteWithNoClimbAndNoDistanceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FinishTimeEstimator)->estimate($this->profile(0.0, 0.0, 0.0), 7200);
    }

    public function testANonPositiveReferenceTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FinishTimeEstimator)->estimate($this->profile(10.0, 11.0, 13.0), 0);
    }
}