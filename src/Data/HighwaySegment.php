<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class HighwaySegment
{
    /** @param TrackPoint[] $points Geometry vertices, lat/lon only. */
    public function __construct(
        public array $points,
        public ?string $surface = null,
        public ?string $highway = null,
        public ?string $sacScale = null,
        public ?string $trailVisibility = null,
    ) {}
}
