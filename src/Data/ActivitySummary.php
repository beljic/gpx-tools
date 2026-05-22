<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class ActivitySummary
{
    public function __construct(
        public ParsedGpx $gpx,
        public TrackStats $stats,
        public TrainingReport $training,
        public RouteAnalysis $route,
        public ?Bounds $bounds,
        public GpxMetadata $metadata,
    ) {}
}
