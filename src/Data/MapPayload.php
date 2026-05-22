<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class MapPayload
{
    /**
     * @param TrackPoint[] $points Downsampled track points suitable for map rendering
     */
    public function __construct(
        public Bounds $bounds,
        public array $points,
    ) {}

    /**
     * Returns a map-ready payload from a parsed GPX, downsampled to at most $maxPoints.
     * Returns null if the track is empty.
     */
    public static function fromParsedGpx(ParsedGpx $gpx, int $maxPoints = 500): ?self
    {
        $track = $gpx->track;

        if ($track === []) {
            return null;
        }

        $bounds = Bounds::fromTrack($track);
        if ($bounds === null) {
            return null;
        }

        return new self(
            bounds: $bounds,
            points: self::downsample($track, $maxPoints),
        );
    }

    /**
     * @param  TrackPoint[] $points
     * @return TrackPoint[]
     */
    private static function downsample(array $points, int $maxPoints): array
    {
        $n = count($points);

        if ($n <= $maxPoints) {
            return $points;
        }

        $step   = ($n - 1) / ($maxPoints - 1);
        $result = [];

        for ($i = 0; $i < $maxPoints; $i++) {
            $result[] = $points[(int) round($i * $step)];
        }

        return $result;
    }
}
