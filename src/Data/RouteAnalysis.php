<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class RouteAnalysis
{
    /**
     * @param Peak[]     $peaks
     * @param Place[]    $places
     * @param string[]   $rivers
     * @param string[]   $lakes
     * @param Waypoint[] $waypoints
     * @param string[]   $mountainRanges
     */
    public function __construct(
        public array $peaks          = [],
        public array $places         = [],
        public array $rivers         = [],
        public array $lakes          = [],
        public array $waypoints      = [],
        public array $mountainRanges = [],
        public ?SurfaceAnalysis $surface = null,
    ) {}
}
