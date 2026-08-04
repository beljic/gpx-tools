<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/** How much of the route falls in one gradient band. */
readonly class GradientBucket
{
    public function __construct(
        public GradientBand $band,
        public float $distanceKm,
        public float $percentOfRoute,
        public float $elevationGainM,
        public float $elevationLossM,
    ) {}

    public function toArray(): array
    {
        return [
            'band'             => $this->band->value,
            'distance_km'      => round($this->distanceKm, 3),
            'percent_of_route' => round($this->percentOfRoute, 1),
            'elevation_gain_m' => round($this->elevationGainM, 1),
            'elevation_loss_m' => round($this->elevationLossM, 1),
        ];
    }
}