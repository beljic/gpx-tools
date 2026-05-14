<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Parser\GpxParser;
use Beljic\GpxTools\Parser\GpxWriter;
use Beljic\GpxTools\Processor\GpxCleaner;
use PHPUnit\Framework\TestCase;

class GpxWriterTest extends TestCase
{
    private GpxParser $parser;
    private GpxWriter $writer;

    protected function setUp(): void
    {
        $this->parser = new GpxParser();
        $this->writer = new GpxWriter();
    }

    public function testRoundTripPreservesPointCount(): void
    {
        $gpx    = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml    = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        $this->assertCount(count($gpx->track), $reparsed->track);
    }

    public function testRoundTripPreservesCoordinates(): void
    {
        $gpx      = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml      = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        foreach ($gpx->track as $i => $orig) {
            $this->assertEqualsWithDelta($orig->lat, $reparsed->track[$i]->lat, 0.000001);
            $this->assertEqualsWithDelta($orig->lon, $reparsed->track[$i]->lon, 0.000001);
            $this->assertEqualsWithDelta((float) $orig->ele, (float) $reparsed->track[$i]->ele, 0.01);
        }
    }

    public function testRoundTripPreservesTimestamps(): void
    {
        $gpx      = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml      = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        $this->assertNotNull($reparsed->track[0]->time);
        $this->assertSame(
            $gpx->track[0]->time?->getTimestamp(),
            $reparsed->track[0]->time?->getTimestamp()
        );
    }

    public function testRoundTripPreservesExtensions(): void
    {
        $gpx      = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml      = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        $this->assertSame($gpx->track[1]->heartRate,   $reparsed->track[1]->heartRate);
        $this->assertSame($gpx->track[1]->cadence,     $reparsed->track[1]->cadence);
        $this->assertEqualsWithDelta(
            (float) $gpx->track[1]->temperature,
            (float) $reparsed->track[1]->temperature,
            0.1
        );
    }

    public function testRoundTripPreservesTrackMetadata(): void
    {
        $gpx      = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml      = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        $this->assertSame($gpx->name, $reparsed->name);
        $this->assertSame($gpx->type, $reparsed->type);
    }

    public function testRoundTripPreservesWaypoints(): void
    {
        $gpx      = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml      = $this->writer->toXml($gpx);
        $reparsed = $this->parser->parseString($xml);

        $this->assertCount(count($gpx->waypoints), $reparsed->waypoints);
        $this->assertSame($gpx->waypoints[0]->name, $reparsed->waypoints[0]->name);
    }

    public function testOutputWithoutExtensionsOmitsGarminNamespace(): void
    {
        $gpx   = $this->parser->parseFile($this->fixture('sample.gpx'));
        $clean = (new GpxCleaner())->stripExtensions($gpx);
        $xml   = $this->writer->toXml($clean);

        $this->assertStringNotContainsString('gpxtpx', $xml);
        $this->assertStringNotContainsString('TrackPointExtension', $xml);
    }

    public function testOutputIsValidXml(): void
    {
        $gpx = $this->parser->parseFile($this->fixture('sample.gpx'));
        $xml = $this->writer->toXml($gpx);

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $result = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertTrue($result, 'Writer produced invalid XML');
        $this->assertEmpty($errors);
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }
}
