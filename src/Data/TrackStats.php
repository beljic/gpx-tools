<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class TrackStats
{
    public function __construct(
        public float $distanceKm,
        public float $elevationGainM,
        public float $elevationLossM,
        public float $maxElevationM,
        public float $minElevationM,
        public int $pointCount,
        public ?float $durationSeconds     = null,
        public ?float $movingTimeSeconds   = null,
        public ?float $avgPaceSecPerKm     = null,
        public ?float $avgSpeedKmh         = null,
        public ?int $avgHeartRate          = null,
        public ?int $maxHeartRate          = null,
        public ?float $avgPower            = null,
        public ?float $avgTemperature      = null,
    ) {}

    public function durationFormatted(): ?string
    {
        if ($this->durationSeconds === null) {
            return null;
        }

        $h = (int) ($this->durationSeconds / 3600);
        $m = (int) (($this->durationSeconds % 3600) / 60);
        $s = (int) ($this->durationSeconds % 60);

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    public function avgPaceFormatted(): ?string
    {
        if ($this->avgPaceSecPerKm === null) {
            return null;
        }

        $m = (int) ($this->avgPaceSecPerKm / 60);
        $s = (int) ($this->avgPaceSecPerKm % 60);

        return sprintf("%d'%02d\"", $m, $s);
    }
}
