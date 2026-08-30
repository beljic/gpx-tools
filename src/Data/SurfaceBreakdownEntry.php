<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class SurfaceBreakdownEntry
{
    public function __construct(
        public SurfaceCategory $category,
        public float $percent,
        public int $pointCount,
    ) {}

    public function toArray(): array
    {
        return [
            'category'    => $this->category->value,
            'percent'     => round($this->percent, 1),
            'point_count' => $this->pointCount,
        ];
    }
}
