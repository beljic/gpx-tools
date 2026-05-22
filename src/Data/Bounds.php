<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class Bounds
{
    public function __construct(
        public float $minLat,
        public float $maxLat,
        public float $minLon,
        public float $maxLon,
    ) {}

    /**
     * @param TrackPoint[] $points
     */
    public static function fromTrack(array $points): ?self
    {
        if ($points === []) {
            return null;
        }

        $minLat = $maxLat = $points[0]->lat;
        $minLon = $maxLon = $points[0]->lon;

        foreach ($points as $point) {
            if ($point->lat < $minLat) { $minLat = $point->lat; }
            if ($point->lat > $maxLat) { $maxLat = $point->lat; }
            if ($point->lon < $minLon) { $minLon = $point->lon; }
            if ($point->lon > $maxLon) { $maxLon = $point->lon; }
        }

        return new self(
            minLat: $minLat,
            maxLat: $maxLat,
            minLon: $minLon,
            maxLon: $maxLon,
        );
    }

    /** @return array{lat: float, lon: float} */
    public function center(): array
    {
        return [
            'lat' => ($this->minLat + $this->maxLat) / 2.0,
            'lon' => ($this->minLon + $this->maxLon) / 2.0,
        ];
    }
}
