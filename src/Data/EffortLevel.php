<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

enum EffortLevel: string
{
    case Recovery = 'recovery';
    case Easy     = 'easy';
    case Moderate = 'moderate';
    case Hard     = 'hard';
    case VeryHard = 'very_hard';
    case Race     = 'race';
}
