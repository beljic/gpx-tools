<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

enum PlaceCategory: string
{
    case City          = 'city';
    case Town          = 'town';
    case Village       = 'village';
    case Hamlet        = 'hamlet';
    case Suburb        = 'suburb';
    case Neighbourhood = 'neighbourhood';
    case Quarter       = 'quarter';
    case Locality      = 'locality';
    case Municipality  = 'municipality';
}
