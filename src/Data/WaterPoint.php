<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * Drinking water near a route, as mapped in OpenStreetMap: a fountain or tap
 * (amenity=drinking_water, man_made=water_tap) or a spring (natural=spring,
 * whose water may or may not be drinkable). Most have no name.
 */
readonly class WaterPoint
{
    public function __construct(
        public string $kind,
        public float $lat,
        public float $lon,
        /** Distance along the route to the nearest point, in km. */
        public float $routeKm,
        public ?string $name = null,
    ) {}
}
