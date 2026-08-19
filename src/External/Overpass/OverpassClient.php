<?php

declare(strict_types=1);

namespace Beljic\GpxTools\External\Overpass;

use Beljic\GpxTools\Data\Peak;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Http\HttpClientInterface;
use Beljic\GpxTools\Support\Geo;

class OverpassClient
{
    /**
     * The public instance, and the one to move off when a bulk run needs more
     * than its fair-use policy allows. It runs on donated capacity and refuses
     * connections outright from a client that has been queueing heavy queries,
     * so anything importing more than a handful of routes should point at a
     * mirror or an instance it runs itself.
     */
    public const DEFAULT_ENDPOINT = 'https://overpass-api.de/api/interpreter';

    /**
     * Ceiling on how many coordinates go into the query polyline.
     *
     * Coverage is bought with request size: every clause repeats the polyline,
     * so the body grows with the route. 500 points keeps a 250 km route at the
     * full search density and degrades gracefully beyond that, rather than
     * posting a megabyte for an ultra nobody has entered yet.
     */
    public const MAX_QUERY_POINTS = 500;

    /**
     * How long the server is allowed to spend on the query. The HTTP client
     * must be willing to wait longer than this, or it hangs up on work the
     * server is still permitted to finish - see CurlHttpClient::DEFAULT_TIMEOUT.
     */
    public const QUERY_TIMEOUT_SECONDS = 60;

    private readonly string $endpoint;

    public function __construct(
        private readonly HttpClientInterface $http,
        ?string $endpoint = null,
    ) {
        $this->endpoint = $endpoint !== null && $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    /**
     * @param TrackPoint[] $trackPoints
     * @return array{peaks: Peak[], rivers: string[], lakes: string[]}
     */
    public function fetchNaturalFeatures(array $trackPoints, float $peakRadiusM = 200.0): array
    {
        if (empty($trackPoints)) {
            return ['peaks' => [], 'rivers' => [], 'lakes' => []];
        }

        $overpassPeakM = max(500, (int) ($peakRadiusM * 2));
        $waterM        = 500;

        $queryPoints = $this->sampleForQuery($trackPoints, max($overpassPeakM, $waterM));
        $polyline    = implode(',', array_map(
            fn(TrackPoint $p) => sprintf('%F,%F', $p->lat, $p->lon),
            $queryPoints
        ));

        $query = '[out:json][timeout:' . self::QUERY_TIMEOUT_SECONDS . '];'
            . "(node[\"natural\"=\"peak\"](around:{$overpassPeakM},{$polyline});"
            . "node[\"natural\"=\"hill\"](around:{$overpassPeakM},{$polyline});"
            . "way[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
            . "relation[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
            . "way[\"waterway\"=\"stream\"](around:{$waterM},{$polyline});"
            . "way[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
            . "relation[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
            . ");out;";

        $raw = $this->http->post($this->endpoint, 'data=' . urlencode($query));

        if ($raw === null || !json_validate($raw)) {
            return ['peaks' => [], 'rivers' => [], 'lakes' => []];
        }

        return $this->parseResponse((array) json_decode($raw, true), $trackPoints, $peakRadiusM);
    }

    private function parseResponse(array $data, array $trackPoints, float $peakRadiusM): array
    {
        $peakRadiusKm = $peakRadiusM / 1000.0;
        $checkPoints  = $this->sampleForCheck($trackPoints);

        $peaks  = [];
        $rivers = [];
        $lakes  = [];
        $seen   = [];

        foreach ($data['elements'] ?? [] as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? $tags['name:sr'] ?? null;

            if (!$name || isset($seen[$name])) {
                continue;
            }

            $natural  = $tags['natural']  ?? null;
            $waterway = $tags['waterway'] ?? null;

            if (in_array($natural, ['peak', 'hill'], true)) {
                $lat = isset($el['lat']) ? (float) $el['lat'] : null;
                $lon = isset($el['lon']) ? (float) $el['lon'] : null;
                if ($lat === null || $lon === null) {
                    continue;
                }
                if ($this->minDistToTrack($lat, $lon, $checkPoints) > $peakRadiusKm) {
                    continue;
                }
                $seen[$name] = true;
                $peaks[] = new Peak(
                    name:      $name,
                    elevation: isset($tags['ele']) ? (float) $tags['ele'] : null,
                );
            } elseif (in_array($waterway, ['river', 'stream'], true)) {
                $seen[$name] = true;
                $rivers[] = $name;
            } elseif ($natural === 'water') {
                $seen[$name] = true;
                $lakes[] = $name;
            }
        }

        usort($peaks, fn(Peak $a, Peak $b) => ($b->elevation ?? 0) <=> ($a->elevation ?? 0));
        sort($rivers);
        sort($lakes);

        return ['peaks' => $peaks, 'rivers' => $rivers, 'lakes' => $lakes];
    }

    /** @param TrackPoint[] $trackPoints */
    private function minDistToTrack(float $lat, float $lon, array $trackPoints): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($trackPoints as $p) {
            $d = Geo::haversineKm($lat, $lon, $p->lat, $p->lon);
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    /**
     * Sample the polyline the query is built from.
     *
     * Overpass searches circles of $searchRadiusM around each coordinate given,
     * so the spacing decides what is searched at all. Spacing the centres one
     * radius apart makes consecutive circles overlap, which leaves no stretch
     * of the route unsearched: a feature at perpendicular distance d from the
     * line, worst case halfway between two centres, is sqrt((r/2)^2 + d^2) from
     * the nearer one, still inside r for every d up to 0.86 r - and the peak
     * filter downstream only keeps d <= r/2 anyway.
     *
     * A fixed spacing here was the bug this replaces: at 5 km it asked about
     * seven 400 m circles on a 30 km route and never saw the rest of it, so
     * summits directly on the line came back missing.
     *
     * @param  TrackPoint[] $points
     * @return TrackPoint[]
     */
    private function sampleForQuery(array $points, int $searchRadiusM): array
    {
        $intervalKm = max($searchRadiusM, 1) / 1000.0;

        $sampled = $this->sample($points, $intervalKm);
        if (count($sampled) <= self::MAX_QUERY_POINTS) {
            return $sampled;
        }

        // Too long to search at full density; widen just enough to fit.
        $lengthKm = $this->lengthKm($points);

        // Two points of headroom: sample() always keeps the first coordinate
        // and appends the last, on top of the intervals it walks.
        return $this->sample($points, max($intervalKm, $lengthKm / (self::MAX_QUERY_POINTS - 2)));
    }

    /** @param TrackPoint[] $points */
    private function lengthKm(array $points): float
    {
        $total = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += Geo::haversineKm($points[$i - 1]->lat, $points[$i - 1]->lon, $points[$i]->lat, $points[$i]->lon);
        }

        return $total;
    }

    /**
     * Sample to ~100 m spacing for precise peak distance checks.
     *
     * @param  TrackPoint[] $points
     * @return TrackPoint[]
     */
    private function sampleForCheck(array $points): array
    {
        return $this->sample($points, 0.1);
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
