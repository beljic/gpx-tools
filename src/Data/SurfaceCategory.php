<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

enum SurfaceCategory: string
{
    case AsphaltPaved = 'asphalt_paved';
    case Gravel       = 'gravel';
    case TrailPath    = 'trail_path';
    case Mixed        = 'mixed';
}
