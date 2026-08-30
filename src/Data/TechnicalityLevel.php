<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

enum TechnicalityLevel: string
{
    case Easy      = 'easy';
    case Moderate  = 'moderate';
    case Difficult = 'difficult';
    case Unknown   = 'unknown';
}
