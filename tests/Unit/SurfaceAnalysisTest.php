<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\SurfaceAnalysis;
use Beljic\GpxTools\Data\SurfaceBreakdownEntry;
use Beljic\GpxTools\Data\SurfaceCategory;
use Beljic\GpxTools\Data\SurfaceTechnicality;
use Beljic\GpxTools\Data\TechnicalityLevel;
use PHPUnit\Framework\TestCase;

final class SurfaceAnalysisTest extends TestCase
{
    public function testToArrayShapeMatchesThePayloadSchema(): void
    {
        $analysis = new SurfaceAnalysis(
            status: 'ok',
            coveragePercent: 87.456,
            confidence: 'high',
            dominantCategory: SurfaceCategory::Gravel,
            breakdown: [
                new SurfaceBreakdownEntry(SurfaceCategory::Gravel, 62.04, 31),
                new SurfaceBreakdownEntry(SurfaceCategory::AsphaltPaved, 37.96, 19),
            ],
            technicality: new SurfaceTechnicality(TechnicalityLevel::Moderate, ['sac_scale=mountain_hiking']),
        );

        $this->assertSame([
            'status' => 'ok',
            'coverage_percent' => 87.5,
            'confidence' => 'high',
            'dominant_category' => 'gravel',
            'breakdown' => [
                ['category' => 'gravel', 'percent' => 62.0, 'point_count' => 31],
                ['category' => 'asphalt_paved', 'percent' => 38.0, 'point_count' => 19],
            ],
            'technicality' => [
                'level' => 'moderate',
                'evidence' => ['sac_scale=mountain_hiking'],
            ],
            'source' => 'openstreetmap',
        ], $analysis->toArray());
    }

    public function testDominantCategoryIsNullWhenThereIsNoBreakdown(): void
    {
        $analysis = new SurfaceAnalysis(
            status: 'unavailable',
            coveragePercent: 0.0,
            confidence: 'low',
            dominantCategory: null,
            breakdown: [],
            technicality: new SurfaceTechnicality(),
        );

        $this->assertNull($analysis->toArray()['dominant_category']);
        $this->assertSame('unknown', $analysis->toArray()['technicality']['level']);
        $this->assertSame([], $analysis->toArray()['technicality']['evidence']);
    }
}
