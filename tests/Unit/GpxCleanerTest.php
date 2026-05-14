<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Parser\GpxParser;
use Beljic\GpxTools\Processor\GpxCleaner;
use PHPUnit\Framework\TestCase;

class GpxCleanerTest extends TestCase
{
    private GpxParser $parser;
    private GpxCleaner $cleaner;

    protected function setUp(): void
    {
        $this->parser  = new GpxParser();
        $this->cleaner = new GpxCleaner();
    }

    public function testStripExtensionsRemovesHrCadenceTempPower(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->stripExtensions($gpx);

        foreach ($clean->track as $pt) {
            $this->assertNull($pt->heartRate);
            $this->assertNull($pt->cadence);
            $this->assertNull($pt->temperature);
            $this->assertNull($pt->power);
        }
    }

    public function testStripExtensionsPreservesCoordinatesElevationTime(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $orig  = $gpx->track[1];
        $clean = $this->cleaner->stripExtensions($gpx);
        $pt    = $clean->track[1];

        $this->assertSame($orig->lat, $pt->lat);
        $this->assertSame($orig->lon, $pt->lon);
        $this->assertSame($orig->ele, $pt->ele);
        $this->assertSame($orig->time, $pt->time);
    }

    public function testStripExtensionsPreservesTrackMetadata(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->stripExtensions($gpx);

        $this->assertSame($gpx->name, $clean->name);
        $this->assertSame($gpx->type, $clean->type);
        $this->assertCount(count($gpx->track), $clean->track);
    }

    public function testStripTimestampsRemovesTimeFromPoints(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->stripTimestamps($gpx);

        foreach ($clean->track as $pt) {
            $this->assertNull($pt->time);
        }
        foreach ($clean->waypoints as $wpt) {
            $this->assertNull($wpt->time);
        }
    }

    public function testStripTimestampsPreservesExtensions(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->stripTimestamps($gpx);

        $this->assertNotNull($clean->track[1]->heartRate);
        $this->assertNotNull($clean->track[1]->cadence);
    }

    public function testTrackOnlyRemovesWaypoints(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $this->assertNotEmpty($gpx->waypoints);

        $clean = $this->cleaner->trackOnly($gpx);

        $this->assertEmpty($clean->waypoints);
        $this->assertCount(count($gpx->track), $clean->track);
    }

    public function testGeometryOnlyRemovesAllMetadata(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->geometryOnly($gpx);

        foreach ($clean->track as $pt) {
            $this->assertNull($pt->heartRate);
            $this->assertNull($pt->cadence);
            $this->assertNull($pt->temperature);
            $this->assertNull($pt->power);
            $this->assertNull($pt->time);
        }
    }

    public function testGeometryOnlyPreservesCoordinatesAndElevation(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $orig  = $gpx->track[0];
        $clean = $this->cleaner->geometryOnly($gpx);
        $pt    = $clean->track[0];

        $this->assertSame($orig->lat, $pt->lat);
        $this->assertSame($orig->lon, $pt->lon);
        $this->assertSame($orig->ele, $pt->ele);
    }

    public function testOperationsReturnNewInstance(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = $this->cleaner->stripExtensions($gpx);

        $this->assertNotSame($gpx, $clean);
        // Original unchanged
        $this->assertNotNull($gpx->track[1]->heartRate);
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
