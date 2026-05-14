<?php

declare(strict_types=1);

namespace Beljic\GpxTools\External\Nominatim;

use Beljic\GpxTools\Data\Place;
use Beljic\GpxTools\Data\PlaceCategory;

class PlaceClassifier
{
    private const SETTLEMENT_MAP = [
        'village'           => PlaceCategory::Village,
        'hamlet'            => PlaceCategory::Hamlet,
        'isolated_dwelling' => PlaceCategory::Hamlet,
        'suburb'            => PlaceCategory::Suburb,
        'neighbourhood'     => PlaceCategory::Neighbourhood,
        'quarter'           => PlaceCategory::Quarter,
        'town'              => PlaceCategory::Town,
        'city'              => PlaceCategory::City,
        'municipality'      => PlaceCategory::Municipality,
    ];

    /** @return Place[] */
    public function classify(array $result): array
    {
        $addr = $result['address'] ?? [];

        foreach (self::SETTLEMENT_MAP as $key => $category) {
            if (!empty($addr[$key])) {
                return [new Place(category: $category, name: $addr[$key])];
            }
        }

        if (!empty($addr['locality'])) {
            return [new Place(category: PlaceCategory::Locality, name: $addr['locality'])];
        }

        return [];
    }
}
