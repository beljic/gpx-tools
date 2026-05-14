<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class Place
{
    public function __construct(
        public PlaceCategory $category,
        public string $name,
    ) {}
}
