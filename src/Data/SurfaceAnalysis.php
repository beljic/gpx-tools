<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * `status`/`confidence` are plain strings (`ok`/`partial`/`unavailable`,
 * `high`/`medium`/`low`) rather than enums, matching the convention already
 * used for the outer `route_analysis.status` in running-booker.
 */
readonly class SurfaceAnalysis
{
    /** @param SurfaceBreakdownEntry[] $breakdown */
    public function __construct(
        public string $status,
        public float $coveragePercent,
        public string $confidence,
        public ?SurfaceCategory $dominantCategory,
        public array $breakdown,
        public SurfaceTechnicality $technicality,
    ) {}

    public function toArray(): array
    {
        return [
            'status'            => $this->status,
            'coverage_percent'  => round($this->coveragePercent, 1),
            'confidence'        => $this->confidence,
            'dominant_category' => $this->dominantCategory?->value,
            'breakdown'         => array_map(fn (SurfaceBreakdownEntry $e) => $e->toArray(), $this->breakdown),
            'technicality'      => $this->technicality->toArray(),
            'source'            => 'openstreetmap',
        ];
    }
}
