<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Cache\CacheInterface;
use Beljic\GpxTools\Cache\NullCache;
use Beljic\GpxTools\Data\ActivitySummary;
use Beljic\GpxTools\Data\Bounds;
use Beljic\GpxTools\Data\GpxMetadata;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Http\CurlHttpClient;
use Beljic\GpxTools\Http\HttpClientInterface;
use Beljic\GpxTools\Parser\GpxParser;

/**
 * One-call facade: parse → stats → training → route features → bounds + metadata.
 *
 * Pass a custom HttpClientInterface / CacheInterface to swap the HTTP stack or
 * enable disk caching for Nominatim/Overpass lookups.
 */
class FullActivityAnalyzer
{
    private GpxParser $parser;
    private TrackStatsCalculator $statsCalc;
    private TrainingAnalyzer $trainingAnalyzer;
    private RouteAnalyzer $routeAnalyzer;

    public function __construct(
        private readonly Sport $sport = Sport::Unknown,
        ?HttpClientInterface $http    = null,
        CacheInterface $cache         = new NullCache(),
        float $intervalKm             = 0.2,
        float $peakRadiusM            = 200.0,
    ) {
        $http                   = $http ?? new CurlHttpClient();
        $this->parser           = new GpxParser();
        $this->statsCalc        = new TrackStatsCalculator();
        $this->trainingAnalyzer = new TrainingAnalyzer();
        $this->routeAnalyzer    = new RouteAnalyzer(
            intervalKm:  $intervalKm,
            peakRadiusM: $peakRadiusM,
            http:        $http,
            cache:       $cache,
        );
    }

    public function analyzeFile(string $path, Sport $sport = Sport::Unknown): ActivitySummary
    {
        return $this->doAnalyze($this->parser->parseFile($path), $sport);
    }

    public function analyzeString(string $content, Sport $sport = Sport::Unknown): ActivitySummary
    {
        return $this->doAnalyze($this->parser->parseString($content), $sport);
    }

    private function doAnalyze(ParsedGpx $gpx, Sport $sport): ActivitySummary
    {
        $metadata = GpxMetadata::fromParsedGpx($gpx);

        $resolvedSport = $sport !== Sport::Unknown
            ? $sport
            : ($metadata->sport ?? Sport::Unknown);

        $stats    = $this->statsCalc->calculate($gpx);
        $training = $this->trainingAnalyzer->analyze($stats, $resolvedSport);
        $route    = $this->routeAnalyzer->analyze($gpx);
        $bounds   = Bounds::fromTrack($gpx->track);

        return new ActivitySummary(
            gpx:      $gpx,
            stats:    $stats,
            training: $training,
            route:    $route,
            bounds:   $bounds,
            metadata: $metadata,
        );
    }
}
