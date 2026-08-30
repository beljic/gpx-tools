<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

readonly class SurfaceTechnicality
{
    /** @param string[] $evidence */
    public function __construct(
        public TechnicalityLevel $level = TechnicalityLevel::Unknown,
        public array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'level'    => $this->level->value,
            'evidence' => $this->evidence,
        ];
    }
}
