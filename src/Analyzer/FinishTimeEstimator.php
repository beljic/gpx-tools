<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\FinishTimeEstimate;
use Beljic\GpxTools\Data\RouteProfile;
use InvalidArgumentException;

/**
 * Predicts a finish time by applying Riegel's endurance formula to the route's
 * trail-equivalent distance rather than its map distance. Climbing is therefore
 * paid for as extra kilometres, which is what it feels like.
 *
 * The trail equivalent is used in preference to Minetti's flat equivalent
 * because Minetti measures energy, not time: it hands back most of the cost of
 * a climb on the way down, which on a loop course nearly cancels the climbing
 * out. Nobody runs a mountain descent fast enough to bank that saving, so the
 * Minetti figure produced finish times that were far too optimistic.
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

    /**
     * The trail rule of thumb: a kilometre of effort per 100 m climbed, and
     * nothing back for the descent. One place, so the profile's figure and an
     * estimate from a corrected climb can never use two different rules.
     */
    public static function trailEquivalentKm(float $distanceKm, float $ascentM): float
    {
        return $distanceKm + max(0.0, $ascentM) / 100.0;
    }

    public function estimate(
        RouteProfile $profile,
        int $referenceSeconds,
        float $referenceDistanceKm = self::HALF_MARATHON_KM,
    ): FinishTimeEstimate {
        return $this->estimateForEffort($profile->distanceKm, $profile->trailEquivalentKm, $referenceSeconds, $referenceDistanceKm);
    }

    /**
     * The same estimate over a climb the caller knows better than the profile
     * does. The profile smooths elevation over a few hundred metres, which
     * flattens short real climbs; a race's published climb, or a corrected
     * one, is usually the closer figure.
     */
    public function estimateForClimb(
        float $distanceKm,
        float $ascentM,
        int $referenceSeconds,
        float $referenceDistanceKm = self::HALF_MARATHON_KM,
    ): FinishTimeEstimate {
        return $this->estimateForEffort($distanceKm, self::trailEquivalentKm($distanceKm, $ascentM), $referenceSeconds, $referenceDistanceKm);
    }

    private function estimateForEffort(
        float $routeDistanceKm,
        float $effortKm,
        int $referenceSeconds,
        float $referenceDistanceKm,
    ): FinishTimeEstimate {
        if ($referenceSeconds <= 0) {
            throw new InvalidArgumentException('Reference time must be greater than zero.');
        }

        if ($referenceDistanceKm <= 0.0) {
            throw new InvalidArgumentException('Reference distance must be greater than zero.');
        }

        if ($effortKm <= 0.0) {
            throw new InvalidArgumentException('Route has no measurable distance to estimate over.');
        }

        $seconds = $referenceSeconds * ($effortKm / $referenceDistanceKm) ** $this->exponent;

        return new FinishTimeEstimate(
            referenceDistanceKm: $referenceDistanceKm,
            referenceSeconds:    $referenceSeconds,
            routeDistanceKm:     $routeDistanceKm,
            effortDistanceKm:    $effortKm,
            estimatedSeconds:    (int) round($seconds),
            riegelExponent:      $this->exponent,
        );
    }
}