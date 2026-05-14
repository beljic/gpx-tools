<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Processor;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;

class GpxCleaner
{
    /**
     * Remove all extension data (HR, cadence, temperature, power) from track points.
     * Keeps coordinates, elevation, and timestamps.
     */
    public function stripExtensions(ParsedGpx $gpx): ParsedGpx
    {
        $track = array_map(
            fn(TrackPoint $pt) => new TrackPoint(
                lat:  $pt->lat,
                lon:  $pt->lon,
                ele:  $pt->ele,
                time: $pt->time,
            ),
            $gpx->track
        );

        return new ParsedGpx(
            track:     $track,
            waypoints: $gpx->waypoints,
            name:      $gpx->name,
            type:      $gpx->type,
        );
    }

    /**
     * Remove all timestamps from track points and waypoints.
     * Useful for creating route templates from recorded activities.
     */
    public function stripTimestamps(ParsedGpx $gpx): ParsedGpx
    {
        $track = array_map(
            fn(TrackPoint $pt) => new TrackPoint(
                lat:         $pt->lat,
                lon:         $pt->lon,
                ele:         $pt->ele,
                heartRate:   $pt->heartRate,
                cadence:     $pt->cadence,
                temperature: $pt->temperature,
                power:       $pt->power,
            ),
            $gpx->track
        );

        $waypoints = array_map(
            fn($wpt) => new \Beljic\GpxTools\Data\Waypoint(
                lat:         $wpt->lat,
                lon:         $wpt->lon,
                ele:         $wpt->ele,
                name:        $wpt->name,
                description: $wpt->description,
            ),
            $gpx->waypoints
        );

        return new ParsedGpx(
            track:     $track,
            waypoints: $waypoints,
            name:      $gpx->name,
            type:      $gpx->type,
        );
    }

    /**
     * Keep only the track geometry — remove waypoints.
     */
    public function trackOnly(ParsedGpx $gpx): ParsedGpx
    {
        return new ParsedGpx(
            track: $gpx->track,
            name:  $gpx->name,
            type:  $gpx->type,
        );
    }

    /**
     * Keep only lat/lon/ele — strip extensions and timestamps.
     * Produces a clean route file suitable for navigation.
     */
    public function geometryOnly(ParsedGpx $gpx): ParsedGpx
    {
        $track = array_map(
            fn(TrackPoint $pt) => new TrackPoint(lat: $pt->lat, lon: $pt->lon, ele: $pt->ele),
            $gpx->track
        );

        return new ParsedGpx(
            track: $track,
            name:  $gpx->name,
            type:  $gpx->type,
        );
    }
}
