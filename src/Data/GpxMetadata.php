<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

use DateTimeImmutable;

readonly class GpxMetadata
{
    public function __construct(
        public ?Sport $sport                  = null,
        public ?DateTimeImmutable $startTime  = null,
        public ?DateTimeImmutable $endTime    = null,
        public int $waypointCount             = 0,
        public bool $hasHeartRate             = false,
        public bool $hasCadence               = false,
        public bool $hasPower                 = false,
        public bool $hasTemperature           = false,
        /** True when track points carry timestamps — distinguishes a recorded activity from a route template. */
        public bool $isActivity               = false,
    ) {}

    public static function fromParsedGpx(ParsedGpx $gpx): self
    {
        $sport = $gpx->type !== null ? Sport::fromGpxType($gpx->type) : null;

        $hasHr   = false;
        $hasCad  = false;
        $hasPow  = false;
        $hasTemp = false;
        $firstTime = null;
        $lastTime  = null;

        foreach ($gpx->track as $point) {
            if ($point->heartRate !== null)   { $hasHr   = true; }
            if ($point->cadence !== null)      { $hasCad  = true; }
            if ($point->power !== null)        { $hasPow  = true; }
            if ($point->temperature !== null)  { $hasTemp = true; }

            if ($point->time !== null) {
                if ($firstTime === null) {
                    $firstTime = $point->time;
                }
                $lastTime = $point->time;
            }
        }

        return new self(
            sport:          $sport,
            startTime:      $firstTime,
            endTime:        $lastTime,
            waypointCount:  count($gpx->waypoints),
            hasHeartRate:   $hasHr,
            hasCadence:     $hasCad,
            hasPower:       $hasPow,
            hasTemperature: $hasTemp,
            isActivity:     $firstTime !== null,
        );
    }
}
