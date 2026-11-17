<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\External\Overpass\OverpassClient;
use Beljic\GpxTools\Http\CurlHttpClient;
use Beljic\GpxTools\Http\HttpClientInterface;
use Beljic\GpxTools\Support\Geo;
use PHPUnit\Framework\TestCase;

final class OverpassClientTest extends TestCase
{
    /**
     * A summit sitting right on the route must be inside one of the circles the
     * query asks Overpass about. Sampling the polyline more coarsely than the
     * search radius leaves stretches of the route nobody ever asks about, and a
     * peak that falls in one of those gaps is never returned - so the distance
     * filter downstream, however exact, has nothing to filter.
     */
    public function testQueryCirclesCoverEveryPointOnTheRoute(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $body = '';

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->body = $body;

                return '{"elements":[]}';
            }
        };

        // A straight 30 km line, recorded at roughly 100 m intervals.
        $points = [];
        for ($i = 0; $i <= 300; $i++) {
            $points[] = new TrackPoint(lat: 43.0 + $i * 0.0009, lon: 22.8);
        }

        (new OverpassClient($http))->fetchNaturalFeatures($points, peakRadiusM: 200.0);

        $query = urldecode($http->body);

        // The radius each circle in the query covers.
        preg_match('/around:(\d+)/', $query, $radiusMatch);
        $this->assertNotEmpty($radiusMatch, 'query carries no around: radius');
        $radiusKm = (int) $radiusMatch[1] / 1000.0;

        preg_match('/around:\d+,([0-9.,\-]+)\)/', $query, $polylineMatch);
        $coordinates = array_map('floatval', explode(',', $polylineMatch[1]));
        $sampled = [];
        for ($i = 0; $i < count($coordinates); $i += 2) {
            $sampled[] = [$coordinates[$i], $coordinates[$i + 1]];
        }

        $this->assertGreaterThan(1, count($sampled));

        // Every recorded point must sit inside some circle, or that stretch of
        // the route is simply not searched.
        foreach ($points as $point) {
            $nearest = PHP_FLOAT_MAX;
            foreach ($sampled as [$lat, $lon]) {
                $nearest = min($nearest, Geo::haversineKm($point->lat, $point->lon, $lat, $lon));
            }

            $this->assertLessThanOrEqual(
                $radiusKm,
                $nearest,
                sprintf('route point %F,%F is %.3f km from the nearest queried circle centre', $point->lat, $point->lon, $nearest)
            );
        }
    }

    /**
     * Coverage must not be bought with an unbounded request: a 200 km ultra
     * would otherwise put thousands of coordinates into every clause.
     */
    public function testQueryStaysBoundedOnVeryLongRoutes(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $body = '';

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->body = $body;

                return '{"elements":[]}';
            }
        };

        // ~330 km, far longer than any race this is likely to see.
        $points = [];
        for ($i = 0; $i <= 3000; $i++) {
            $points[] = new TrackPoint(lat: 43.0 + $i * 0.001, lon: 22.8);
        }

        (new OverpassClient($http))->fetchNaturalFeatures($points, peakRadiusM: 200.0);

        $query = urldecode($http->body);
        preg_match('/around:\d+,([0-9.,\-]+)\)/', $query, $polylineMatch);
        $sampledCount = (count(explode(',', $polylineMatch[1]))) / 2;

        $this->assertLessThanOrEqual(OverpassClient::MAX_QUERY_POINTS, $sampledCount);
    }

    /**
     * A bulk import has to be able to move off the public instance; leaving the
     * endpoint hard-coded is what turns one heavy run into a refused connection
     * for every run after it.
     */
    public function testQueriesTheEndpointItWasGiven(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $url = '';

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->url = $url;

                return '{"elements":[]}';
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.01, lon: 22.8)];

        (new OverpassClient($http, 'https://overpass.example.test/api/interpreter'))
            ->fetchNaturalFeatures($points);

        $this->assertSame('https://overpass.example.test/api/interpreter', $http->url);
    }

    public function testFallsBackToThePublicInstanceWhenNoEndpointIsGiven(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $url = '';

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->url = $url;

                return '{"elements":[]}';
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.01, lon: 22.8)];

        (new OverpassClient($http, ''))->fetchNaturalFeatures($points);

        $this->assertSame(OverpassClient::DEFAULT_ENDPOINT, $http->url);
    }

    /**
     * The client must outlast the query it sends. When it did not, Overpass was
     * still allowed 60 s while curl hung up at 30, and the route came back with
     * no features and nothing to say why.
     */
    public function testTheHttpClientWaitsLongerThanTheQueryIsAllowedToRun(): void
    {
        $this->assertGreaterThan(
            OverpassClient::QUERY_TIMEOUT_SECONDS,
            CurlHttpClient::DEFAULT_TIMEOUT,
            'the HTTP timeout must exceed the timeout the query declares to Overpass'
        );
    }

    /**
     * 504 is what an overloaded Overpass answers, and it is transient - the
     * same query succeeds on a quieter instance seconds later.
     */
    public function testAnOverloadedInstanceIsRetried(): void
    {
        $this->assertTrue(CurlHttpClient::isRetryable(504), '504 must be retried');
        $this->assertTrue(CurlHttpClient::isRetryable(429), '429 must be retried');
        $this->assertTrue(CurlHttpClient::isRetryable(503), '503 must be retried');
        $this->assertFalse(CurlHttpClient::isRetryable(400), 'a malformed query must not be retried');
        $this->assertFalse(CurlHttpClient::isRetryable(200), 'success must not be retried');
    }

    /**
     * A range covers hundreds of kilometres and its polygon is tagged fuzzy,
     * so the only question that answers "which massif is this route on" is
     * containment. An `around:` search measures distance to the outline and
     * comes back empty for a route in the middle of the massif.
     */
    public function testAsksWhichRangeContainsThePoint(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $body = '';

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->body = $body;

                return '{"elements":[]}';
            }
        };

        (new OverpassClient($http))->fetchMountainRanges(43.2319127, 22.7815272);

        $query = urldecode($http->body);

        $this->assertStringContainsString('is_in(43.231913,22.781527)', $query);
        $this->assertStringContainsString('"natural"="mountain_range"', $query);
        $this->assertStringNotContainsString('around:', $query);
    }

    public function testReadsRangeNamesFromTheResponse(): void
    {
        $http = new class implements HttpClientInterface
        {
            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                return json_encode(['elements' => [
                    ['type' => 'area', 'id' => 3618306789, 'tags' => [
                        'name'    => 'Стара планина',
                        'name:en' => 'Balkan Mountains',
                        'natural' => 'mountain_range',
                        'fuzzy'   => 'yes',
                    ]],
                    ['type' => 'area', 'id' => 3600000001, 'tags' => [
                        'name:sr' => 'Сува планина',
                        'natural' => 'mountain_range',
                    ]],
                    ['type' => 'area', 'id' => 3600000002, 'tags' => ['natural' => 'mountain_range']],
                ]]);
            }
        };

        $ranges = (new OverpassClient($http))->fetchMountainRanges(43.0, 22.8);

        $this->assertSame(['Стара планина', 'Сува планина'], $ranges);
    }

    public function testReturnsNoRangesWhenTheInstanceRefuses(): void
    {
        $http = new class implements HttpClientInterface
        {
            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                return null;
            }
        };

        $this->assertSame([], (new OverpassClient($http))->fetchMountainRanges(43.0, 22.8));
    }

    public function testFetchHighwaySegmentsParsesGeometryAndTags(): void
    {
        $http = new class implements HttpClientInterface
        {
            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return json_encode(['elements' => [[
                    'type' => 'way',
                    'id' => 1,
                    'tags' => ['highway' => 'path', 'surface' => 'dirt', 'sac_scale' => 'mountain_hiking'],
                    'geometry' => [
                        ['lat' => 43.000, 'lon' => 22.800],
                        ['lat' => 43.001, 'lon' => 22.801],
                    ],
                ]]]);
            }
        };

        $points = [new TrackPoint(lat: 43.000, lon: 22.800), new TrackPoint(lat: 43.001, lon: 22.801)];
        $segments = (new OverpassClient($http))->fetchHighwaySegments($points);

        $this->assertCount(1, $segments);
        $this->assertSame('path', $segments[0]->highway);
        $this->assertSame('dirt', $segments[0]->surface);
        $this->assertSame('mountain_hiking', $segments[0]->sacScale);
        $this->assertNull($segments[0]->trailVisibility);
        $this->assertCount(2, $segments[0]->points);
        $this->assertSame(43.000, $segments[0]->points[0]->lat);
    }

    public function testFetchHighwaySegmentsSkipsElementsWithoutGeometry(): void
    {
        $http = new class implements HttpClientInterface
        {
            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return json_encode(['elements' => [
                    ['type' => 'way', 'id' => 1, 'tags' => ['highway' => 'path']],
                    ['type' => 'node', 'id' => 2, 'tags' => ['highway' => 'bus_stop']],
                ]]);
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];
        $segments = (new OverpassClient($http))->fetchHighwaySegments($points);

        $this->assertSame([], $segments);
    }

    public function testFetchHighwaySegmentsReturnsEmptyOnMalformedResponse(): void
    {
        $http = new class implements HttpClientInterface
        {
            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return 'not json';
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];

        $this->assertSame([], (new OverpassClient($http))->fetchHighwaySegments($points));
    }

    public function testFetchHighwaySegmentsReturnsEmptyWhenTheInstanceRefuses(): void
    {
        $http = new class implements HttpClientInterface
        {
            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return null;
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];

        $this->assertSame([], (new OverpassClient($http))->fetchHighwaySegments($points));
    }

    public function testFetchHighwaySegmentsQueriesWayHighwayWithGeometry(): void
    {
        $http = new class implements HttpClientInterface
        {
            public string $body = '';

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->body = $body;

                return '{"elements":[]}';
            }
        };

        $points = [new TrackPoint(lat: 43.0, lon: 22.8), new TrackPoint(lat: 43.001, lon: 22.8)];
        (new OverpassClient($http))->fetchHighwaySegments($points, searchRadiusM: 30.0);

        $query = urldecode($http->body);
        $this->assertStringContainsString('way["highway"]', $query);
        $this->assertStringContainsString('around:30,', $query);
        $this->assertStringContainsString('out geom;', $query);
    }

    /**
     * A straight line north, ~100 m between points.
     *
     * @return TrackPoint[]
     */
    private function lineNorth(float $km): array
    {
        $points = [];
        for ($i = 0, $n = (int) round($km * 10); $i <= $n; $i++) {
            $points[] = new TrackPoint(lat: 45.0 + $i * 0.0009, lon: 13.8);
        }

        return $points;
    }

    /** @return list<array{0: float, 1: float}> lat/lon pairs of the query polyline */
    private static function polylineOf(string $body): array
    {
        preg_match('/around:\d+,([^)]*)\)/', urldecode($body), $m);
        $numbers = array_map('floatval', explode(',', $m[1]));

        return array_chunk($numbers, 2);
    }

    public function testHighwaysOfALongRouteAreAskedForStretchByStretch(): void
    {
        // One query for a whole ultra asks the server for every road in the
        // bounding box of the route, and on 69-168 km routes in Istria it ran
        // out of time every time, leaving the surface "unavailable".
        $http = new class implements HttpClientInterface
        {
            /** @var string[] */
            public array $bodies = [];

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->bodies[] = $body;

                return '{"elements":[]}';
            }
        };

        $points = $this->lineNorth(50.0);
        (new OverpassClient($http, rateLimitUs: 0))->fetchHighwaySegments($points);

        $this->assertCount(3, $http->bodies, '50 km in stretches of ' . OverpassClient::HIGHWAY_CHUNK_KM . ' km');

        $previousEnd = null;
        foreach ($http->bodies as $body) {
            $polyline = self::polylineOf($body);
            $first = $polyline[0];
            $last = $polyline[count($polyline) - 1];
            $spanKm = Geo::haversineKm($first[0], $first[1], $last[0], $last[1]);

            $this->assertLessThanOrEqual(OverpassClient::HIGHWAY_CHUNK_KM + 0.2, $spanKm);
            if ($previousEnd !== null) {
                // Stretches meet at a shared point, so no part of the route is skipped.
                $this->assertEqualsWithDelta($previousEnd[0], $first[0], 1e-6);
            }
            $previousEnd = $last;
        }

        $this->assertEqualsWithDelta($points[0]->lat, self::polylineOf($http->bodies[0])[0][0], 1e-6);
        $this->assertEqualsWithDelta(end($points)->lat, $previousEnd[0], 1e-6);
    }

    public function testAShortRouteIsStillOneHighwayQuery(): void
    {
        $http = new class implements HttpClientInterface
        {
            public int $calls = 0;

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->calls++;

                return '{"elements":[]}';
            }
        };

        (new OverpassClient($http, rateLimitUs: 0))->fetchHighwaySegments($this->lineNorth(15.0));

        $this->assertSame(1, $http->calls);
    }

    public function testAWayReturnedForTwoStretchesIsCountedOnce(): void
    {
        $http = new class implements HttpClientInterface
        {
            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                return json_encode(['elements' => [[
                    'type' => 'way',
                    'id' => 77,
                    'tags' => ['highway' => 'track', 'surface' => 'gravel'],
                    'geometry' => [['lat' => 45.0, 'lon' => 13.8], ['lat' => 45.4, 'lon' => 13.8]],
                ]]]);
            }
        };

        $segments = (new OverpassClient($http, rateLimitUs: 0))->fetchHighwaySegments($this->lineNorth(50.0));

        $this->assertCount(1, $segments);
    }

    public function testStretchesThatAnsweredAreKeptWhenOneFails(): void
    {
        $http = new class implements HttpClientInterface
        {
            public int $calls = 0;

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->calls++;
                if ($this->calls === 2) {
                    return null;
                }

                return json_encode(['elements' => [[
                    'type' => 'way',
                    'id' => $this->calls,
                    'tags' => ['highway' => 'path'],
                    'geometry' => [['lat' => 45.0, 'lon' => 13.8], ['lat' => 45.001, 'lon' => 13.8]],
                ]]]);
            }
        };

        $segments = (new OverpassClient($http, rateLimitUs: 0))->fetchHighwaySegments($this->lineNorth(50.0));

        $this->assertSame(3, $http->calls, 'a failed stretch does not stop the rest');
        $this->assertCount(2, $segments);
    }

    public function testDrinkingWaterNearTheRouteComesBackWithItsKilometre(): void
    {
        // A straight line north, ~100 m between points: about 3 km long.
        $points = [];
        for ($i = 0; $i <= 30; $i++) {
            $points[] = new TrackPoint(lat: 44.0 + $i * 0.0009, lon: 19.3);
        }
        $response = json_encode(['elements' => [
            // Unnamed fountain right on the line, ~2 km in.
            ['type' => 'node', 'lat' => 44.018, 'lon' => 19.30005, 'tags' => ['amenity' => 'drinking_water']],
            // Named spring 50 m off the line, ~1 km in.
            ['type' => 'node', 'lat' => 44.009, 'lon' => 19.3006, 'tags' => ['natural' => 'spring', 'name' => 'Izvor']],
            // A tap 400 m away: not on the route.
            ['type' => 'node', 'lat' => 44.018, 'lon' => 19.305, 'tags' => ['man_made' => 'water_tap']],
        ]]);
        $http = new class ($response) implements HttpClientInterface
        {
            public string $body = '';

            public function __construct(private readonly string $response) {}

            public function get(string $url): ?string
            {
                return null;
            }

            public function post(string $url, string $body): ?string
            {
                $this->body = $body;

                return $this->response;
            }
        };

        $water = (new OverpassClient($http, rateLimitUs: 0))->fetchNaturalFeatures($points)['water'];

        $this->assertStringContainsString('drinking_water', urldecode($http->body));
        $this->assertCount(2, $water);
        $this->assertSame('spring', $water[0]->kind);
        $this->assertSame('Izvor', $water[0]->name);
        $this->assertEqualsWithDelta(1.0, $water[0]->routeKm, 0.15);
        $this->assertSame('drinking_water', $water[1]->kind);
        $this->assertNull($water[1]->name);
        $this->assertEqualsWithDelta(2.0, $water[1]->routeKm, 0.15);
    }
}
