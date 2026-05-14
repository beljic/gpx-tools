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
     */
    public function __construct(
        public array $peaks     = [],
        public array $places    = [],
        public array $rivers    = [],
        public array $lakes     = [],
        public array $waypoints = [],
    ) {}
}
