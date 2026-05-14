<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Data\TrackStats;
use Beljic\GpxTools\Support\Geo;

class TrackStatsCalculator
{
    /**
     * Elevation changes smaller than this threshold are ignored to reduce GPS noise.
     */
    public function __construct(private readonly float $elevationNoiseM = 5.0) {}

    public function calculate(ParsedGpx $gpx): TrackStats
    {
        $points = $gpx->track;
        $n      = count($points);

        if ($n === 0) {
            return new TrackStats(
                distanceKm:    0.0,
                elevationGainM: 0.0,
                elevationLossM: 0.0,
                maxElevationM:  0.0,
                minElevationM:  0.0,
                pointCount:     0,
            );
        }

        $distanceKm    = 0.0;
        $elevGain      = 0.0;
        $elevLoss      = 0.0;
        $elevations    = array_filter(array_map(fn(TrackPoint $p) => $p->ele, $points));
        $maxEle        = $elevations ? max($elevations) : 0.0;
        $minEle        = $elevations ? min($elevations) : 0.0;

        $hrValues    = [];
        $powerValues = [];
        $tempValues  = [];

        $movingTimeSeconds = 0.0;
        $prevEle           = $points[0]->ele;

        for ($i = 1; $i < $n; $i++) {
            $prev    = $points[$i - 1];
            $current = $points[$i];

            $distanceKm += Geo::haversineKm($prev->lat, $prev->lon, $current->lat, $current->lon);

            // Elevation gain/loss with noise filter
            if ($current->ele !== null && $prevEle !== null) {
                $diff = $current->ele - $prevEle;
                if (abs($diff) >= $this->elevationNoiseM) {
                    if ($diff > 0) {
                        $elevGain += $diff;
                    } else {
                        $elevLoss += abs($diff);
                    }
                    $prevEle = $current->ele;
                }
            } elseif ($current->ele !== null) {
                $prevEle = $current->ele;
            }

            // Moving time: segment between two timestamped points
            if ($prev->time !== null && $current->time !== null) {
                $segmentSeconds = $current->time->getTimestamp() - $prev->time->getTimestamp();
                $segDistKm      = Geo::haversineKm($prev->lat, $prev->lon, $current->lat, $current->lon);
                // Exclude stopped segments: < 0.5 km/h or < 0.5 m in > 30 s
                $isMoving = $segDistKm > 0 && ($segmentSeconds <= 0 || ($segDistKm / $segmentSeconds * 3600) >= 0.5);
                if ($isMoving) {
                    $movingTimeSeconds += $segmentSeconds;
                }
            }

            if ($current->heartRate !== null) {
                $hrValues[] = $current->heartRate;
            }
            if ($current->power !== null) {
                $powerValues[] = $current->power;
            }
            if ($current->temperature !== null) {
                $tempValues[] = $current->temperature;
            }
        }

        $firstTime    = $points[0]->time;
        $lastTime     = $points[$n - 1]->time;
        $durationSecs = ($firstTime !== null && $lastTime !== null)
            ? (float) ($lastTime->getTimestamp() - $firstTime->getTimestamp())
            : null;

        $avgPace = null;
        $avgSpeed = null;

        if ($movingTimeSeconds > 0 && $distanceKm > 0) {
            $avgPace  = $movingTimeSeconds / $distanceKm;
            $avgSpeed = $distanceKm / ($movingTimeSeconds / 3600.0);
        } elseif ($durationSecs !== null && $durationSecs > 0 && $distanceKm > 0) {
            $avgPace  = $durationSecs / $distanceKm;
            $avgSpeed = $distanceKm / ($durationSecs / 3600.0);
        }

        return new TrackStats(
            distanceKm:        round($distanceKm, 3),
            elevationGainM:    round($elevGain, 1),
            elevationLossM:    round($elevLoss, 1),
            maxElevationM:     (float) $maxEle,
            minElevationM:     (float) $minEle,
            pointCount:        $n,
            durationSeconds:   $durationSecs,
            movingTimeSeconds: $movingTimeSeconds > 0 ? $movingTimeSeconds : null,
            avgPaceSecPerKm:   $avgPace !== null ? round($avgPace) : null,
            avgSpeedKmh:       $avgSpeed !== null ? round($avgSpeed, 2) : null,
            avgHeartRate:      $hrValues      ? (int) round(array_sum($hrValues)    / count($hrValues))      : null,
            maxHeartRate:      $hrValues      ? max($hrValues) : null,
            avgPower:          $powerValues   ? round(array_sum($powerValues) / count($powerValues), 1)       : null,
            avgTemperature:    $tempValues    ? round(array_sum($tempValues)  / count($tempValues),  1)       : null,
        );
    }
}
