<?php

declare(strict_types=1);

namespace Beljic\GpxTools\External\Overpass;

use Beljic\GpxTools\Data\Peak;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Http\HttpClientInterface;
use Beljic\GpxTools\Support\Geo;

class OverpassClient
{
    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    public function __construct(private readonly HttpClientInterface $http) {}

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

        $queryPoints = $this->sampleForQuery($trackPoints);
        $polyline    = implode(',', array_map(
            fn(TrackPoint $p) => sprintf('%F,%F', $p->lat, $p->lon),
            $queryPoints
        ));

        $query = "[out:json][timeout:60];"
            . "(node[\"natural\"=\"peak\"](around:{$overpassPeakM},{$polyline});"
            . "node[\"natural\"=\"hill\"](around:{$overpassPeakM},{$polyline});"
            . "way[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
            . "relation[\"waterway\"=\"river\"](around:{$waterM},{$polyline});"
            . "way[\"waterway\"=\"stream\"](around:{$waterM},{$polyline});"
            . "way[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
            . "relation[\"natural\"=\"water\"](around:{$waterM},{$polyline});"
            . ");out;";

        $raw = $this->http->post(self::ENDPOINT, 'data=' . urlencode($query));

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
     * Sample to ~5 km spacing to keep the Overpass polyline short.
     *
     * @param  TrackPoint[] $points
     * @return TrackPoint[]
     */
    private function sampleForQuery(array $points): array
    {
        return $this->sample($points, 5.0);
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
