<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class Peak
{
    public function __construct(
        public string $name,
        public ?float $elevation = null,
    ) {}
}
