<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class TrainingReport
{
    /**
     * @param string[] $suggestions
     * @param array<string, mixed> $zones
     */
    public function __construct(
        public EffortLevel $effortLevel,
        public Sport $sport,
        public string $summary,
        public array $suggestions = [],
        public array $zones       = [],
    ) {}
}
