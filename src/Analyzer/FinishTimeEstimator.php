<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\FinishTimeEstimate;
use Beljic\GpxTools\Data\RouteProfile;
use InvalidArgumentException;

/**
 * Predicts a finish time by applying Riegel's endurance formula to the route's
 * Minetti flat-equivalent distance rather than its map distance. Climbing is
 * therefore paid for as extra kilometres, which is what it feels like.
 *
 * What this deliberately does NOT model: technical ground, altitude, heat,
 * night sections, aid-station stops, or how well the runner descends. On a
 * rocky mountain course the honest reading of the output is a floor, not a
 * prediction.
 */
class FinishTimeEstimator
{
    /**
     * Riegel's fatigue exponent. 1.06 is his original fit across road race
     * distances; higher values punish long efforts harder.
     */
    public const DEFAULT_EXPONENT = 1.06;

    public const HALF_MARATHON_KM = 21.0975;

    public function __construct(private readonly float $exponent = self::DEFAULT_EXPONENT) {}

    public function estimate(
        RouteProfile $profile,
        int $referenceSeconds,
        float $referenceDistanceKm = self::HALF_MARATHON_KM,
    ): FinishTimeEstimate {
        if ($referenceSeconds <= 0) {
            throw new InvalidArgumentException('Reference time must be greater than zero.');
        }

        if ($referenceDistanceKm <= 0.0) {
            throw new InvalidArgumentException('Reference distance must be greater than zero.');
        }

        $flatKm = $profile->flatEquivalentKm;

        if ($flatKm <= 0.0) {
            throw new InvalidArgumentException('Route has no measurable distance to estimate over.');
        }

        $seconds = $referenceSeconds * ($flatKm / $referenceDistanceKm) ** $this->exponent;

        return new FinishTimeEstimate(
            referenceDistanceKm: $referenceDistanceKm,
            referenceSeconds:    $referenceSeconds,
            routeDistanceKm:     $profile->distanceKm,
            flatEquivalentKm:    $flatKm,
            estimatedSeconds:    (int) round($seconds),
            riegelExponent:      $this->exponent,
        );
    }
}