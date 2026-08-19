<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Cache\CacheInterface;
use Beljic\GpxTools\Cache\NullCache;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\Place;
use Beljic\GpxTools\Data\RouteAnalysis;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\External\Nominatim\NominatimClient;
use Beljic\GpxTools\External\Nominatim\PlaceClassifier;
use Beljic\GpxTools\External\Overpass\OverpassClient;
use Beljic\GpxTools\Http\CurlHttpClient;
use Beljic\GpxTools\Http\HttpClientInterface;
use Beljic\GpxTools\Support\Geo;

class RouteAnalyzer
{
    private NominatimClient $nominatim;
    private PlaceClassifier $classifier;
    private OverpassClient $overpass;

    public function __construct(
        private readonly float $intervalKm  = 0.2,
        private readonly float $peakRadiusM = 200.0,
        ?HttpClientInterface $http          = null,
        CacheInterface $cache               = new NullCache(),
        ?string $overpassEndpoint           = null,
    ) {
        $http             = $http ?? new CurlHttpClient();
        $this->nominatim  = new NominatimClient($http, $cache);
        $this->classifier = new PlaceClassifier();
        $this->overpass   = new OverpassClient($http, $overpassEndpoint);
    }

    public function analyze(ParsedGpx $gpx): RouteAnalysis
    {
        $trackPoints = $gpx->track;
        $sampled     = $this->sample($trackPoints, $this->intervalKm);

        $natural = $this->overpass->fetchNaturalFeatures($trackPoints, $this->peakRadiusM);

        $places = [];
        $seen   = [];

        foreach ($sampled as $point) {
            $geo = $this->nominatim->reverse($point->lat, $point->lon);
            if ($geo === null) {
                continue;
            }

            foreach ($this->classifier->classify($geo) as $place) {
                $key = $place->category->value . '::' . $place->name;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $places[]   = $place;
                }
            }
        }

        $summit = $this->highestPoint($trackPoints);

        return new RouteAnalysis(
            peaks:          $natural['peaks'],
            places:         $places,
            rivers:         $natural['rivers'],
            lakes:          $natural['lakes'],
            waypoints:      $gpx->waypoints,
            mountainRanges: $summit === null
                ? []
                : $this->overpass->fetchMountainRanges($summit->lat, $summit->lon),
        );
    }

    /**
     * The point that best says which massif a route belongs to.
     *
     * The start is the wrong one to ask with: a mountain route usually starts
     * in a valley, and the range polygons are tagged fuzzy, so a trailhead
     * can fall just outside the massif its whole climb sits in. The highest
     * recorded point is inside it by definition. Routes without elevation
     * fall back to the middle of the track rather than its edge.
     *
     * @param TrackPoint[] $points
     */
    private function highestPoint(array $points): ?TrackPoint
    {
        if ($points === []) {
            return null;
        }

        $highest = null;

        foreach ($points as $point) {
            if ($point->ele !== null && ($highest === null || $point->ele > $highest->ele)) {
                $highest = $point;
            }
        }

        if ($highest !== null) {
            return $highest;
        }

        $ordered = array_values($points);

        return $ordered[intdiv(count($ordered), 2)];
    }

    /** @param TrackPoint[] $points @return TrackPoint[] */
    private function sample(array $points, float $intervalKm): array
    {
        if (empty($points)) {
            return [];
        }

        $sampled     = [$points[0]];
        $accumulated = 0.0;
        $prev        = $points[0];

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $d = Geo::haversineKm($prev->lat, $prev->lon, $points[$i]->lat, $points[$i]->lon);
            $accumulated += $d;

            if ($accumulated >= $intervalKm) {
                $sampled[]   = $points[$i];
                $accumulated -= $intervalKm;
            }

            $prev = $points[$i];
        }

        $last = end($points);
        if ($sampled[count($sampled) - 1] !== $last) {
            $sampled[] = $last;
        }

        return $sampled;
    }
}
