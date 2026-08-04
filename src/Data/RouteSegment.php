<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/** A contiguous stretch of route whose gradient stays inside one band. */
readonly class RouteSegment
{
    public function __construct(
        public float $startKm,
        public float $endKm,
        public float $lengthKm,
        public float $gradientPercent,
        public float $elevationChangeM,
        public float $startElevationM,
        public float $endElevationM,
        public GradientBand $band,
    ) {}

    public function toArray(): array
    {
        return [
            'start_km'           => round($this->startKm, 3),
            'end_km'             => round($this->endKm, 3),
            'length_km'          => round($this->lengthKm, 3),
            'gradient_percent'   => round($this->gradientPercent, 1),
            'elevation_change_m' => round($this->elevationChangeM, 1),
            'start_elevation_m'  => round($this->startElevationM, 1),
            'end_elevation_m'    => round($this->endElevationM, 1),
            'band'               => $this->band->value,
        ];
    }
}