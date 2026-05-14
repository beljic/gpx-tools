<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Parser;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Data\Waypoint;
use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;

class GpxParser
{
    private const NS_GARMIN_TPX = 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1';

    public function parseFile(string $path): ParsedGpx
    {
        if (!is_file($path)) {
            throw new RuntimeException("GPX file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read GPX file: $path");
        }

        return $this->parseString($content);
    }

    public function parseString(string $content): ParsedGpx
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            $errors = array_map(fn($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException('Invalid GPX XML: ' . implode('; ', $errors));
        }

        return new ParsedGpx(
            track:     $this->parseTrack($xml),
            waypoints: $this->parseWaypoints($xml),
            name:      $this->parseTrackName($xml),
            type:      $this->parseTrackType($xml),
        );
    }

    /** @return TrackPoint[] */
    private function parseTrack(SimpleXMLElement $xml): array
    {
        $points = [];

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                foreach ($seg->trkpt as $pt) {
                    $points[] = $this->parseTrackPoint($pt);
                }
            }
        }

        foreach ($xml->rte as $rte) {
            foreach ($rte->rtept as $pt) {
                $points[] = $this->parseTrackPoint($pt);
            }
        }

        return $points;
    }

    private function parseTrackPoint(SimpleXMLElement $pt): TrackPoint
    {
        $time = null;
        if (isset($pt->time) && (string) $pt->time !== '') {
            try {
                $time = new DateTimeImmutable((string) $pt->time);
            } catch (\Exception) {
                // malformed timestamp — skip
            }
        }

        $hr    = null;
        $cad   = null;
        $temp  = null;
        $power = null;

        if (isset($pt->extensions)) {
            if (isset($pt->extensions->power)) {
                $p = (float) $pt->extensions->power;
                $power = $p > 0 ? $p : null;
            }

            $tpx = $pt->extensions->children(self::NS_GARMIN_TPX);
            if (isset($tpx->TrackPointExtension)) {
                $ext  = $tpx->TrackPointExtension;
                $hr   = isset($ext->hr)    ? (int) $ext->hr    : null;
                $cad  = isset($ext->cad)   ? (int) $ext->cad   : null;
                $temp = isset($ext->atemp) ? (float) $ext->atemp : null;
            }
        }

        return new TrackPoint(
            lat:         (float) $pt['lat'],
            lon:         (float) $pt['lon'],
            ele:         isset($pt->ele) ? (float) $pt->ele : null,
            time:        $time,
            heartRate:   $hr,
            cadence:     $cad,
            temperature: $temp,
            power:       $power,
        );
    }

    /** @return Waypoint[] */
    private function parseWaypoints(SimpleXMLElement $xml): array
    {
        $waypoints = [];

        foreach ($xml->wpt as $wpt) {
            $time = null;
            if (isset($wpt->time) && (string) $wpt->time !== '') {
                try {
                    $time = new DateTimeImmutable((string) $wpt->time);
                } catch (\Exception) {}
            }

            $waypoints[] = new Waypoint(
                lat:         (float) $wpt['lat'],
                lon:         (float) $wpt['lon'],
                ele:         isset($wpt->ele)  ? (float) $wpt->ele  : null,
                name:        isset($wpt->name) ? trim((string) $wpt->name) : null,
                description: isset($wpt->desc) ? trim((string) $wpt->desc) : null,
                time:        $time,
            );
        }

        return $waypoints;
    }

    private function parseTrackName(SimpleXMLElement $xml): ?string
    {
        foreach ($xml->trk as $trk) {
            if (isset($trk->name) && (string) $trk->name !== '') {
                return trim((string) $trk->name);
            }
        }
        return null;
    }

    private function parseTrackType(SimpleXMLElement $xml): ?string
    {
        foreach ($xml->trk as $trk) {
            if (isset($trk->type) && (string) $trk->type !== '') {
                return trim((string) $trk->type);
            }
        }
        return null;
    }
}
