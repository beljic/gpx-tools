<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Normalizer;

use Beljic\GpxTools\Data\ActivitySummary;
use Beljic\GpxTools\Data\Bounds;
use Beljic\GpxTools\Data\GpxMetadata;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\Peak;
use Beljic\GpxTools\Data\Place;
use Beljic\GpxTools\Data\RouteAnalysis;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Data\TrackStats;
use Beljic\GpxTools\Data\TrainingReport;
use Beljic\GpxTools\Data\Waypoint;

/**
 * Converts library value objects to plain PHP arrays, suitable for JSON serialization
 * or passing to an API response layer.
 */
class GpxNormalizer
{
    /** @return array<string, mixed> */
    public function parsedGpxToArray(ParsedGpx $gpx): array
    {
        return [
            'name'      => $gpx->name,
            'type'      => $gpx->type,
            'track'     => array_map($this->trackPointToArray(...), $gpx->track),
            'waypoints' => array_map($this->waypointToArray(...), $gpx->waypoints),
        ];
    }

    /** @return array<string, mixed> */
    public function statsToArray(TrackStats $stats): array
    {
        return [
            'distance_km'         => $stats->distanceKm,
            'elevation_gain_m'    => $stats->elevationGainM,
            'elevation_loss_m'    => $stats->elevationLossM,
            'max_elevation_m'     => $stats->maxElevationM,
            'min_elevation_m'     => $stats->minElevationM,
            'point_count'         => $stats->pointCount,
            'duration_seconds'    => $stats->durationSeconds,
            'moving_time_seconds' => $stats->movingTimeSeconds,
            'avg_pace_sec_per_km' => $stats->avgPaceSecPerKm,
            'avg_speed_kmh'       => $stats->avgSpeedKmh,
            'avg_heart_rate'      => $stats->avgHeartRate,
            'max_heart_rate'      => $stats->maxHeartRate,
            'avg_power'           => $stats->avgPower,
            'avg_temperature'     => $stats->avgTemperature,
            'duration_formatted'  => $stats->durationFormatted(),
            'avg_pace_formatted'  => $stats->avgPaceFormatted(),
        ];
    }

    /** @return array<string, mixed> */
    public function trainingReportToArray(TrainingReport $report): array
    {
        return [
            'effort_level' => $report->effortLevel->value,
            'sport'        => $report->sport->value,
            'summary'      => $report->summary,
            'suggestions'  => $report->suggestions,
            'zones'        => $report->zones,
        ];
    }

    /** @return array<string, mixed> */
    public function routeAnalysisToArray(RouteAnalysis $route): array
    {
        return [
            'peaks'     => array_map($this->peakToArray(...), $route->peaks),
            'places'    => array_map($this->placeToArray(...), $route->places),
            'rivers'    => $route->rivers,
            'lakes'     => $route->lakes,
            'waypoints' => array_map($this->waypointToArray(...), $route->waypoints),
        ];
    }

    /** @return array<string, mixed> */
    public function metadataToArray(GpxMetadata $metadata): array
    {
        return [
            'sport'           => $metadata->sport?->value,
            'start_time'      => $metadata->startTime?->format('c'),
            'end_time'        => $metadata->endTime?->format('c'),
            'waypoint_count'  => $metadata->waypointCount,
            'has_heart_rate'  => $metadata->hasHeartRate,
            'has_cadence'     => $metadata->hasCadence,
            'has_power'       => $metadata->hasPower,
            'has_temperature' => $metadata->hasTemperature,
            'is_activity'     => $metadata->isActivity,
        ];
    }

    /** @return array<string, mixed> */
    public function boundsToArray(Bounds $bounds): array
    {
        return [
            'min_lat' => $bounds->minLat,
            'max_lat' => $bounds->maxLat,
            'min_lon' => $bounds->minLon,
            'max_lon' => $bounds->maxLon,
            'center'  => $bounds->center(),
        ];
    }

    /** @return array<string, mixed> */
    public function activitySummaryToArray(ActivitySummary $summary): array
    {
        return [
            'gpx'      => $this->parsedGpxToArray($summary->gpx),
            'stats'    => $this->statsToArray($summary->stats),
            'training' => $this->trainingReportToArray($summary->training),
            'route'    => $this->routeAnalysisToArray($summary->route),
            'bounds'   => $summary->bounds !== null ? $this->boundsToArray($summary->bounds) : null,
            'metadata' => $this->metadataToArray($summary->metadata),
        ];
    }

    /** @return array<string, mixed> */
    private function trackPointToArray(TrackPoint $point): array
    {
        return [
            'lat'  => $point->lat,
            'lon'  => $point->lon,
            'ele'  => $point->ele,
            'time' => $point->time?->format('c'),
            'hr'   => $point->heartRate,
            'cad'  => $point->cadence,
            'temp' => $point->temperature,
            'pow'  => $point->power,
        ];
    }

    /** @return array<string, mixed> */
    private function waypointToArray(Waypoint $waypoint): array
    {
        return [
            'lat'         => $waypoint->lat,
            'lon'         => $waypoint->lon,
            'ele'         => $waypoint->ele,
            'name'        => $waypoint->name,
            'description' => $waypoint->description,
            'time'        => $waypoint->time?->format('c'),
        ];
    }

    /** @return array<string, mixed> */
    private function peakToArray(Peak $peak): array
    {
        return [
            'name'      => $peak->name,
            'elevation' => $peak->elevation,
        ];
    }

    /** @return array<string, mixed> */
    private function placeToArray(Place $place): array
    {
        return [
            'name'     => $place->name,
            'category' => $place->category->value,
        ];
    }
}
