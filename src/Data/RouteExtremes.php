<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * The handful of numbers a runner actually quotes about a course: the longest
 * climb, the nastiest gradient, the high point. Any of these may be null on a
 * route with no elevation data or no climbing at all.
 */
readonly class RouteExtremes
{
    public function __construct(
        public ?RouteSegment $longestClimb = null,
        public ?RouteSegment $longestDescent = null,
        public ?RouteSegment $steepestClimb = null,
        public ?RouteSegment $steepestDescent = null,
        public ?float $highestPointM = null,
        public ?float $highestPointAtKm = null,
        public ?float $lowestPointM = null,
        public ?float $lowestPointAtKm = null,
        public ?float $biggestSingleClimbM = null,
    ) {}

    public function toArray(): array
    {
        return [
            'longest_climb'          => $this->longestClimb?->toArray(),
            'longest_descent'        => $this->longestDescent?->toArray(),
            'steepest_climb'         => $this->steepestClimb?->toArray(),
            'steepest_descent'       => $this->steepestDescent?->toArray(),
            'highest_point_m'        => $this->highestPointM !== null ? round($this->highestPointM, 1) : null,
            'highest_point_at_km'    => $this->highestPointAtKm !== null ? round($this->highestPointAtKm, 3) : null,
            'lowest_point_m'         => $this->lowestPointM !== null ? round($this->lowestPointM, 1) : null,
            'lowest_point_at_km'     => $this->lowestPointAtKm !== null ? round($this->lowestPointAtKm, 3) : null,
            'biggest_single_climb_m' => $this->biggestSingleClimbM !== null ? round($this->biggestSingleClimbM, 1) : null,
        ];
    }
}