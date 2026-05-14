<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\PlaceCategory;
use Beljic\GpxTools\External\Nominatim\PlaceClassifier;
use PHPUnit\Framework\TestCase;

class PlaceClassifierTest extends TestCase
{
    private PlaceClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PlaceClassifier();
    }

    public function testClassifiesVillage(): void
    {
        $result = ['address' => ['village' => 'Kremna']];
        $places = $this->classifier->classify($result);

        $this->assertCount(1, $places);
        $this->assertSame(PlaceCategory::Village, $places[0]->category);
        $this->assertSame('Kremna', $places[0]->name);
    }

    public function testClassifiesCity(): void
    {
        $result = ['address' => ['city' => 'Užice']];
        $places = $this->classifier->classify($result);

        $this->assertSame(PlaceCategory::City, $places[0]->category);
    }

    public function testMostSpecificSettlementWins(): void
    {
        // Both village and city present — village wins (listed first in map)
        $result = ['address' => ['village' => 'Kremna', 'city' => 'Užice']];
        $places = $this->classifier->classify($result);

        $this->assertSame(PlaceCategory::Village, $places[0]->category);
    }

    public function testFallsBackToLocality(): void
    {
        $result = ['address' => ['locality' => 'Forest area']];
        $places = $this->classifier->classify($result);

        $this->assertSame(PlaceCategory::Locality, $places[0]->category);
    }

    public function testReturnsEmptyForUnknownResult(): void
    {
        $result = ['address' => ['country' => 'Serbia']];
        $places = $this->classifier->classify($result);

        $this->assertEmpty($places);
    }
}
