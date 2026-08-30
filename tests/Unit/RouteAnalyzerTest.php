<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\RouteAnalyzer;
use Beljic\GpxTools\Cache\CacheInterface;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Http\HttpClientInterface;
use PHPUnit\Framework\TestCase;

final class RouteAnalyzerTest extends TestCase
{
    /**
     * Which massif a route belongs to has to be asked from its summit, not
     * its trailhead. Mountain routes start in a valley and the range polygons
     * are tagged fuzzy, so a start point can sit outside the massif the whole
     * climb is inside.
     */
    public function testAsksForTheRangeFromTheHighestPointOfTheRoute(): void
    {
        $http = new class implements HttpClientInterface
        {
            /** @var string[] */
            public array $posted = [];

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->posted[] = urldecode($body);

                return '{"elements":[]}';
            }
        };

        $analyzer = new RouteAnalyzer(
            intervalKm: 100.0,
            http: $http,
            cache: $this->alwaysHitCache(),
        );

        $analyzer->analyze(new ParsedGpx(track: [
            new TrackPoint(lat: 43.20, lon: 22.70, ele: 780.0),
            new TrackPoint(lat: 43.28, lon: 22.85, ele: 1120.0),
            new TrackPoint(lat: 43.31, lon: 22.90, ele: 1963.0),
        ]));

        // Deliberately not the middle point, so a fallback to the midpoint
        // cannot pass this test by accident.
        $this->assertStringContainsString('is_in(43.310000,22.900000)', (string) end($http->posted));
    }

    /**
     * Without elevation there is no summit to ask from, and the start of a
     * route is its least representative point - the middle at least sits on
     * the route rather than at its edge.
     */
    public function testFallsBackToTheMiddleWhenTheRouteCarriesNoElevation(): void
    {
        $http = new class implements HttpClientInterface
        {
            /** @var string[] */
            public array $posted = [];

            #[\Override]
            public function get(string $url): ?string
            {
                return null;
            }

            #[\Override]
            public function post(string $url, string $body): ?string
            {
                $this->posted[] = urldecode($body);

                return '{"elements":[]}';
            }
        };

        $analyzer = new RouteAnalyzer(
            intervalKm: 100.0,
            http: $http,
            cache: $this->alwaysHitCache(),
        );

        $analyzer->analyze(new ParsedGpx(track: [
            new TrackPoint(lat: 43.20, lon: 22.70),
            new TrackPoint(lat: 43.28, lon: 22.85),
            new TrackPoint(lat: 43.31, lon: 22.90),
        ]));

        $this->assertStringContainsString('is_in(43.280000,22.850000)', (string) end($http->posted));
    }

    /**
     * The surface lookup rides along the same sampled points and Overpass
     * client the peak/river lookup already built — no second HTTP client.
     */
    public function testSurfaceAnalysisIsPresentOnTheResult(): void
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
                return '{"elements":[]}';
            }
        };

        $analyzer = new RouteAnalyzer(intervalKm: 100.0, http: $http, cache: $this->alwaysHitCache());

        $result = $analyzer->analyze(new ParsedGpx(track: [
            new TrackPoint(lat: 43.20, lon: 22.70),
            new TrackPoint(lat: 43.28, lon: 22.85),
        ]));

        $this->assertSame('unavailable', $result->surface->status);
    }

    /**
     * A cache that always reports a hit keeps the reverse-geocoding path from
     * sleeping out its 1.1 s rate limit per sampled point.
     */
    private function alwaysHitCache(): CacheInterface
    {
        return new class implements CacheInterface
        {
            #[\Override]
            public function get(string $key): ?string
            {
                return null;
            }

            #[\Override]
            public function set(string $key, string $value): void {}

            #[\Override]
            public function has(string $key): bool
            {
                return true;
            }
        };
    }
}
