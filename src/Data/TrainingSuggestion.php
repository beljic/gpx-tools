<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * One training suggestion in machine-readable form, so a consumer can show
 * it in its own language: `code` names the rule that fired and `params`
 * holds the numbers the rule quoted. `message` is the English sentence also
 * listed in TrainingReport::$suggestions.
 */
readonly class TrainingSuggestion
{
    /**
     * @param array<string, int> $params
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $params = [],
    ) {}
}
