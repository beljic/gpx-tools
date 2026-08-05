<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * Everything derived from the shape of a route: where it climbs, how steeply,
 * and what that costs a runner compared with the same distance on the flat.
 *
 * Two different equivalent distances are offered, because they answer two
 * different questions and disagree by a lot on a mountain course.
 *
 * `flatEquivalentKm` is the Minetti grade-adjusted distance — the distance on
 * level ground that would cost the same *energy*. It is physiologically honest
 * and it credits descents heavily: a kilometre at -10% costs 0.6 of a flat one.
 *
 * `trailEquivalentKm` is the rule of thumb trail runners actually use: the map
 * distance plus one kilometre for every 100 m climbed, with no credit at all
 * for the way down. It is cruder, but it is closer to how long a course takes,
 * because on rough ground nobody banks the energy Minetti says they save.
 */
readonly class RouteProfile
{
    /**
     * @param  list<RouteSegment>  $segments
     * @param  list<GradientBucket>  $distribution
     * @param  list<array{distance_km: float, elevation_m: float, gradient_percent: float}>  $samples
     * @param  array<string, array<string, float>>  $matrix  band value => length bucket => km
     */
    public function __construct(
        public array $segments,
        public array $distribution,
        public RouteExtremes $extremes,
        public array $matrix,
        public array $samples,
        public float $distanceKm,
        public float $flatEquivalentKm,
        public float $trailEquivalentKm,
        public float $totalAscentM,
        public float $totalDescentM,
        public float $gradeAdjustedFactor,
        public float $averageGradientPercent,
        public float $climbPercentOfRoute,
        public float $descentPercentOfRoute,
        public float $flatPercentOfRoute,
    ) {}

    public function toArray(): array
    {
        return [
            'distance_km'               => round($this->distanceKm, 3),
            'flat_equivalent_km'        => round($this->flatEquivalentKm, 2),
            'trail_equivalent_km'       => round($this->trailEquivalentKm, 2),
            'total_ascent_m'            => round($this->totalAscentM),
            'total_descent_m'           => round($this->totalDescentM),
            'grade_adjusted_factor'     => round($this->gradeAdjustedFactor, 3),
            'average_gradient_percent'  => round($this->averageGradientPercent, 2),
            'climb_percent_of_route'    => round($this->climbPercentOfRoute, 1),
            'descent_percent_of_route'  => round($this->descentPercentOfRoute, 1),
            'flat_percent_of_route'     => round($this->flatPercentOfRoute, 1),
            'segments'                  => array_map(fn (RouteSegment $s) => $s->toArray(), $this->segments),
            'distribution'              => array_map(fn (GradientBucket $b) => $b->toArray(), $this->distribution),
            'extremes'                  => $this->extremes->toArray(),
            'matrix'                    => $this->matrix,
            'samples'                   => $this->samples,
        ];
    }
}