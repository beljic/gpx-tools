<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

use DateTimeImmutable;

readonly class TrackPoint
{
    public function __construct(
        public float $lat,
        public float $lon,
        public ?float $ele         = null,
        public ?DateTimeImmutable $time = null,
        public ?int $heartRate     = null,
        public ?int $cadence       = null,
        public ?float $temperature = null,
        public ?float $power       = null,
    ) {}
}
