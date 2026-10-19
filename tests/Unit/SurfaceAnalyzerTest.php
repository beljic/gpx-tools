<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\SurfaceAnalyzer;
use Beljic\GpxTools\Data\SurfaceCategory;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\External\Overpass\OverpassClient;
use Beljic\GpxTools\Http\HttpClientInterface;
use PHPUnit\Framework\TestCase;

final class SurfaceAnalyzerTest extends TestCase
{
    private function overpass(?string $response): OverpassClient
    {
        $http = new class($response) implements HttpClientInterface
        {
            public function __construct(private readonly ?string $response) {}

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return $this->response;
            }
        };

        return new OverpassClient($http);
    }

    public function testFullCoverageOfOneCategoryYieldsOkAndHighConfidence(): void
    {
        $points = [
            new TrackPoint(lat: 43.0000, lon: 22.8000),
            new TrackPoint(lat: 43.0005, lon: 22.8000),
            new TrackPoint(lat: 43.0010, lon: 22.8000),
        ];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'residential', 'surface' => 'asphalt'],
            'geometry' => [
                ['lat' => 43.0000, 'lon' => 22.8000],
                ['lat' => 43.0005, 'lon' => 22.8000],
                ['lat' => 43.0010, 'lon' => 22.8000],
            ],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('ok', $analysis->status);
        $this->assertSame('high', $analysis->confidence);
        $this->assertSame(100.0, $analysis->coveragePercent);
        $this->assertSame(SurfaceCategory::AsphaltPaved, $analysis->dominantCategory);
        $this->assertCount(1, $analysis->breakdown);
    }

    public function testPointsFarFromAnyWayLowerCoverageToPartial(): void
    {
        $points = [
            new TrackPoint(lat: 43.0000, lon: 22.8000),
            new TrackPoint(lat: 43.0005, lon: 22.8000),
            // 1 km away from the only indexed way — outside the match radius.
            new TrackPoint(lat: 43.0100, lon: 22.8000),
            new TrackPoint(lat: 43.0110, lon: 22.8000),
        ];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'track', 'surface' => 'compacted'],
            'geometry' => [
                ['lat' => 43.0000, 'lon' => 22.8000],
                ['lat' => 43.0005, 'lon' => 22.8000],
            ],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('partial', $analysis->status);
        $this->assertSame(50.0, $analysis->coveragePercent);
        $this->assertSame('medium', $analysis->confidence);
        $this->assertSame(SurfaceCategory::Gravel, $analysis->dominantCategory);
    }

    public function testNoSegmentsReturnedYieldsUnavailable(): void
    {
        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];

        $analysis = (new SurfaceAnalyzer($this->overpass('{"elements":[]}')))->analyze($points);

        $this->assertSame('unavailable', $analysis->status);
        $this->assertSame(0.0, $analysis->coveragePercent);
        $this->assertSame('low', $analysis->confidence);
        $this->assertNull($analysis->dominantCategory);
        $this->assertSame([], $analysis->breakdown);
    }

    public function testOverpassRefusingTheRequestYieldsUnavailable(): void
    {
        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];

        $analysis = (new SurfaceAnalyzer($this->overpass(null)))->analyze($points);

        $this->assertSame('unavailable', $analysis->status);
    }

    public function testNoDominantCategoryWhenSplitEvenlyIsReportedAsMixed(): void
    {
        $points = [
            new TrackPoint(lat: 43.0000, lon: 22.8000),
            new TrackPoint(lat: 43.0010, lon: 22.8100),
        ];

        $response = json_encode(['elements' => [
            [
                'type' => 'way',
                'tags' => ['highway' => 'residential', 'surface' => 'asphalt'],
                'geometry' => [['lat' => 43.0000, 'lon' => 22.8000]],
            ],
            [
                'type' => 'way',
                'tags' => ['highway' => 'track', 'surface' => 'gravel'],
                'geometry' => [['lat' => 43.0010, 'lon' => 22.8100]],
            ],
        ]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame(SurfaceCategory::Mixed, $analysis->dominantCategory);
        $this->assertCount(2, $analysis->breakdown);
    }

    public function testTechnicalityIsUnknownWithoutSacScaleOrSteps(): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'residential', 'surface' => 'asphalt'],
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('unknown', $analysis->technicality->level->value);
        $this->assertSame([], $analysis->technicality->evidence);
    }

    public function testTechnicalityCombinesStepsAndSacScaleTakingTheHigherLevel(): void
    {
        $points = [
            new TrackPoint(lat: 43.0000, lon: 22.8000),
            new TrackPoint(lat: 43.0010, lon: 22.8100),
        ];

        $response = json_encode(['elements' => [
            [
                'type' => 'way',
                'tags' => ['highway' => 'steps'],
                'geometry' => [['lat' => 43.0000, 'lon' => 22.8000]],
            ],
            [
                'type' => 'way',
                'tags' => ['highway' => 'path', 'sac_scale' => 'demanding_mountain_hiking'],
                'geometry' => [['lat' => 43.0010, 'lon' => 22.8100]],
            ],
        ]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('moderate', $analysis->technicality->level->value);
        $this->assertContains('highway=steps', $analysis->technicality->evidence);
        $this->assertContains('sac_scale=demanding_mountain_hiking', $analysis->technicality->evidence);
    }

    public function testPoorTrailVisibilityBumpsAnExistingLevelUpOneStep(): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'path', 'sac_scale' => 'hiking', 'trail_visibility' => 'bad'],
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('moderate', $analysis->technicality->level->value);
        $this->assertContains('trail_visibility=bad', $analysis->technicality->evidence);
    }

    public function testPoorTrailVisibilityAloneDoesNotEstablishTechnicality(): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'path', 'trail_visibility' => 'bad'],
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('unknown', $analysis->technicality->level->value);
    }

    /**
     * In OSM a paved road usually carries surface=*, a forest track or a
     * footpath usually does not. Reading only surface=* made every mountain
     * route look paved: on a 34 km PSS trekking route the only classified
     * points were the four on asphalt, reported as "100 % asphalt".
     *
     * @return array<string, array{array<string, string>, SurfaceCategory}>
     */
    public static function untaggedWays(): array
    {
        return [
            'track without tracktype' => [['highway' => 'track'], SurfaceCategory::Gravel],
            'track grade1' => [['highway' => 'track', 'tracktype' => 'grade1'], SurfaceCategory::AsphaltPaved],
            'track grade2' => [['highway' => 'track', 'tracktype' => 'grade2'], SurfaceCategory::Gravel],
            'track grade3' => [['highway' => 'track', 'tracktype' => 'grade3'], SurfaceCategory::Gravel],
            'track grade4' => [['highway' => 'track', 'tracktype' => 'grade4'], SurfaceCategory::TrailPath],
            'track grade5' => [['highway' => 'track', 'tracktype' => 'grade5'], SurfaceCategory::TrailPath],
            'path' => [['highway' => 'path'], SurfaceCategory::TrailPath],
            'bridleway' => [['highway' => 'bridleway'], SurfaceCategory::TrailPath],
        ];
    }

    /** @param array<string, string> $tags */
    #[\PHPUnit\Framework\Attributes\DataProvider('untaggedWays')]
    public function testAWayWithoutASurfaceTagIsClassifiedFromItsKind(array $tags, SurfaceCategory $expected): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => $tags,
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000], ['lat' => 43.0010, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame($expected, $analysis->dominantCategory);
    }

    public function testAnExplicitSurfaceTagStillWinsOverTheKindOfWay(): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'track', 'tracktype' => 'grade5', 'surface' => 'asphalt'],
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000], ['lat' => 43.0010, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame(SurfaceCategory::AsphaltPaved, $analysis->dominantCategory);
    }

    public function testAnUntaggedResidentialStreetStaysUnclassified(): void
    {
        $points = [new TrackPoint(lat: 43.0000, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'residential'],
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000], ['lat' => 43.0010, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame('unavailable', $analysis->status);
    }

    /**
     * A straight forest road is often mapped with vertices hundreds of metres
     * apart. A point on the road between two vertices is on the way, even
     * though it is far from both vertices.
     */
    public function testAPointBetweenTwoDistantVerticesMatchesTheWay(): void
    {
        $points = [new TrackPoint(lat: 43.0045, lon: 22.8000)];

        $response = json_encode(['elements' => [[
            'type' => 'way',
            'tags' => ['highway' => 'track', 'surface' => 'gravel'],
            // Vertices 1 km apart; the point sits halfway, on the line.
            'geometry' => [['lat' => 43.0000, 'lon' => 22.8000], ['lat' => 43.0090, 'lon' => 22.8000]],
        ]]]);

        $analysis = (new SurfaceAnalyzer($this->overpass($response)))->analyze($points);

        $this->assertSame(100.0, $analysis->coveragePercent);
        $this->assertSame(SurfaceCategory::Gravel, $analysis->dominantCategory);
    }
}
