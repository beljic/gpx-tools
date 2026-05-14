<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Parser\GpxParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GpxParserTest extends TestCase
{
    private GpxParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GpxParser();
    }

    public function testParsesTrackPointCount(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $this->assertCount(3, $gpx->track);
    }

    public function testParsesTrackPointCoordinates(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $pt  = $gpx->track[0];

        $this->assertEqualsWithDelta(44.0606380, $pt->lat, 0.000001);
        $this->assertEqualsWithDelta(19.9084890, $pt->lon, 0.000001);
        $this->assertEqualsWithDelta(487.2, $pt->ele, 0.01);
    }

    public function testParsesTimestamps(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $this->assertNotNull($gpx->track[0]->time);
        $this->assertSame('2026-01-01T08:00:00+00:00', $gpx->track[0]->time->format('c'));
    }

    public function testParsesGarminExtensions(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $pt  = $gpx->track[1];

        $this->assertSame(145, $pt->heartRate);
        $this->assertSame(82, $pt->cadence);
        $this->assertEqualsWithDelta(15.0, $pt->temperature, 0.1);
        $this->assertEqualsWithDelta(150.0, $pt->power, 0.1);
    }

    public function testParsesTrackMetadata(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $this->assertSame('Test Track', $gpx->name);
        $this->assertSame('trail_running', $gpx->type);
    }

    public function testParsesWaypoints(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $this->assertCount(1, $gpx->waypoints);
        $this->assertSame('Start', $gpx->waypoints[0]->name);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parser->parseFile('/nonexistent/file.gpx');
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parser->parseString('not xml at all');
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
