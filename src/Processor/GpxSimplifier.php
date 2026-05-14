<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Processor;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Support\Geo;

class GpxSimplifier
{
    /**
     * Keep points that are at least $minDistanceM meters apart.
     * Lightweight, O(n). Good for reducing GPS over-sampling (e.g., 1 pt/s → 1 pt/10m).
     */
    public function byMinDistance(ParsedGpx $gpx, float $minDistanceM): ParsedGpx
    {
        $minKm  = $minDistanceM / 1000.0;
        $points = $gpx->track;

        if (count($points) < 2) {
            return $gpx;
        }

        $kept = [$points[0]];
        $prev = $points[0];

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $d = Geo::haversineKm($prev->lat, $prev->lon, $points[$i]->lat, $points[$i]->lon);
            if ($d >= $minKm) {
                $kept[] = $points[$i];
                $prev   = $points[$i];
            }
        }

        $last = end($points);
        if ($kept[count($kept) - 1] !== $last) {
            $kept[] = $last;
        }

        return new ParsedGpx(
            track:     $kept,
            waypoints: $gpx->waypoints,
            name:      $gpx->name,
            type:      $gpx->type,
        );
    }

    /**
     * Sample to at most $maxPoints points with even spacing.
     * Preserves start and end point. O(n).
     */
    public function byMaxPoints(ParsedGpx $gpx, int $maxPoints): ParsedGpx
    {
        $points = $gpx->track;
        $n      = count($points);

        if ($n <= $maxPoints) {
            return $gpx;
        }

        $kept        = [$points[0]];
        $accumulated = 0.0;
        $prev        = $points[0];

        $totalKm   = $this->totalDistance($points);
        $intervalKm = $totalKm / ($maxPoints - 1);

        for ($i = 1; $i < $n; $i++) {
            $d = Geo::haversineKm($prev->lat, $prev->lon, $points[$i]->lat, $points[$i]->lon);
            $accumulated += $d;

            if ($accumulated >= $intervalKm) {
                $kept[]      = $points[$i];
                $accumulated -= $intervalKm;
            }

            $prev = $points[$i];
        }

        $last = end($points);
        if ($kept[count($kept) - 1] !== $last) {
            $kept[] = $last;
        }

        return new ParsedGpx(
            track:     $kept,
            waypoints: $gpx->waypoints,
            name:      $gpx->name,
            type:      $gpx->type,
        );
    }

    /** @param TrackPoint[] $points */
    private function totalDistance(array $points): float
    {
        $total = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += Geo::haversineKm(
                $points[$i - 1]->lat, $points[$i - 1]->lon,
                $points[$i]->lat,     $points[$i]->lon,
            );
        }
        return $total;
    }
}
