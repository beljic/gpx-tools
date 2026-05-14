<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Parser;

use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Data\Waypoint;
use DOMDocument;
use DOMElement;

class GpxWriter
{
    private const GPX_NS    = 'http://www.topografix.com/GPX/1/1';
    private const GARMIN_NS = 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1';

    public function toFile(ParsedGpx $gpx, string $path): void
    {
        file_put_contents($path, $this->toXml($gpx));
    }

    public function toXml(ParsedGpx $gpx): string
    {
        $hasExtensions = $this->hasExtensionData($gpx->track);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::GPX_NS, 'gpx');
        $root->setAttribute('version', '1.1');
        $root->setAttribute('creator', 'beljic/gpx-tools');

        if ($hasExtensions) {
            $root->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns:gpxtpx',
                self::GARMIN_NS
            );
        }

        $doc->appendChild($root);

        foreach ($gpx->waypoints as $wpt) {
            $root->appendChild($this->buildWaypoint($doc, $wpt));
        }

        if (!empty($gpx->track)) {
            $trk = $doc->createElement('trk');

            if ($gpx->name !== null) {
                $trk->appendChild($doc->createElement('name', htmlspecialchars($gpx->name)));
            }
            if ($gpx->type !== null) {
                $trk->appendChild($doc->createElement('type', htmlspecialchars($gpx->type)));
            }

            $seg = $doc->createElement('trkseg');
            foreach ($gpx->track as $pt) {
                $seg->appendChild($this->buildTrackPoint($doc, $pt, $hasExtensions));
            }
            $trk->appendChild($seg);
            $root->appendChild($trk);
        }

        return (string) $doc->saveXML();
    }

    private function buildWaypoint(DOMDocument $doc, Waypoint $wpt): DOMElement
    {
        $el = $doc->createElement('wpt');
        $el->setAttribute('lat', (string) $wpt->lat);
        $el->setAttribute('lon', (string) $wpt->lon);

        if ($wpt->ele !== null) {
            $el->appendChild($doc->createElement('ele', (string) $wpt->ele));
        }
        if ($wpt->time !== null) {
            $el->appendChild($doc->createElement('time', $wpt->time->format('Y-m-d\TH:i:s\Z')));
        }
        if ($wpt->name !== null) {
            $el->appendChild($doc->createElement('name', htmlspecialchars($wpt->name)));
        }
        if ($wpt->description !== null) {
            $el->appendChild($doc->createElement('desc', htmlspecialchars($wpt->description)));
        }

        return $el;
    }

    private function buildTrackPoint(DOMDocument $doc, TrackPoint $pt, bool $withExtensions): DOMElement
    {
        $el = $doc->createElement('trkpt');
        $el->setAttribute('lat', (string) $pt->lat);
        $el->setAttribute('lon', (string) $pt->lon);

        if ($pt->ele !== null) {
            $el->appendChild($doc->createElement('ele', (string) $pt->ele));
        }
        if ($pt->time !== null) {
            $el->appendChild($doc->createElement('time', $pt->time->format('Y-m-d\TH:i:s\Z')));
        }

        if ($withExtensions && ($pt->heartRate !== null || $pt->cadence !== null || $pt->temperature !== null || $pt->power !== null)) {
            $ext = $doc->createElement('extensions');

            if ($pt->power !== null) {
                $ext->appendChild($doc->createElement('power', (string) $pt->power));
            }

            $hasGarmin = $pt->heartRate !== null || $pt->cadence !== null || $pt->temperature !== null;
            if ($hasGarmin) {
                $tpx = $doc->createElementNS(self::GARMIN_NS, 'gpxtpx:TrackPointExtension');
                if ($pt->temperature !== null) {
                    $tpx->appendChild($doc->createElementNS(self::GARMIN_NS, 'gpxtpx:atemp', (string) $pt->temperature));
                }
                if ($pt->heartRate !== null) {
                    $tpx->appendChild($doc->createElementNS(self::GARMIN_NS, 'gpxtpx:hr', (string) $pt->heartRate));
                }
                if ($pt->cadence !== null) {
                    $tpx->appendChild($doc->createElementNS(self::GARMIN_NS, 'gpxtpx:cad', (string) $pt->cadence));
                }
                $ext->appendChild($tpx);
            }

            $el->appendChild($ext);
        }

        return $el;
    }

    /** @param TrackPoint[] $track */
    private function hasExtensionData(array $track): bool
    {
        foreach ($track as $pt) {
            if ($pt->heartRate !== null || $pt->cadence !== null || $pt->temperature !== null || $pt->power !== null) {
                return true;
            }
        }
        return false;
    }
}
