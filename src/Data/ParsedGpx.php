<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class ParsedGpx
{
    /**
     * @param TrackPoint[] $track
     * @param Waypoint[]   $waypoints
     */
    public function __construct(
        public array $track     = [],
        public array $waypoints = [],
        public ?string $name    = null,
        public ?string $type    = null,
    ) {}
}
