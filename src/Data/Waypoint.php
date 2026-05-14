<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

use DateTimeImmutable;

readonly class Waypoint
{
    public function __construct(
        public float $lat,
        public float $lon,
        public ?float $ele         = null,
        public ?string $name       = null,
        public ?string $description = null,
        public ?DateTimeImmutable $time = null,
    ) {}
}
