<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\External\Overpass\OverpassClient;
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
}
