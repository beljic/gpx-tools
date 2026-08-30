<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\HighwaySegment;
use Beljic\GpxTools\Data\SurfaceAnalysis;
use Beljic\GpxTools\Data\SurfaceBreakdownEntry;
use Beljic\GpxTools\Data\SurfaceCategory;
use Beljic\GpxTools\Data\SurfaceTechnicality;
use Beljic\GpxTools\Data\TechnicalityLevel;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\External\Overpass\OverpassClient;
use Beljic\GpxTools\Support\Geo;

class SurfaceAnalyzer
{
    /**
     * How far a sampled point may sit from the nearest indexed way and still
     * count as matched to it (Approach 1: nearest vertex, not line projection).
     */
    public const MATCH_RADIUS_M = 30.0;

    /** The radius fetchHighwaySegments() searches around each sampled point. */
    public const SEARCH_RADIUS_M = 30.0;

    /** A category needs this share of the categorized points to be "dominant" rather than "mixed". */
    private const DOMINANT_SHARE = 0.6;

    public function __construct(
        private readonly OverpassClient $overpass,
    ) {}

    /** @param TrackPoint[] $sampledPoints */
    public function analyze(array $sampledPoints): SurfaceAnalysis
    {
        if ($sampledPoints === []) {
            return $this->unavailable();
        }

        $segments = $this->overpass->fetchHighwaySegments($sampledPoints, self::SEARCH_RADIUS_M);

        if ($segments === []) {
            return $this->unavailable();
        }

        $matchRadiusKm   = self::MATCH_RADIUS_M / 1000.0;
        $counts          = [];
        $matchedSegments = [];

        foreach ($sampledPoints as $point) {
            $nearest = $this->nearestSegment($point, $segments, $matchRadiusKm);
            if ($nearest === null) {
                continue;
            }

            $matchedSegments[] = $nearest;
            $category = $this->categoryOf($nearest);
            if ($category !== null) {
                $counts[$category->value] = ($counts[$category->value] ?? 0) + 1;
            }
        }

        $categorizedCount = array_sum($counts);

        if ($categorizedCount === 0) {
            return $this->unavailable();
        }

        $totalPoints     = count($sampledPoints);
        $coveragePercent = ($categorizedCount / $totalPoints) * 100.0;

        $breakdown = [];
        foreach ($counts as $value => $count) {
            $breakdown[] = new SurfaceBreakdownEntry(
                category: SurfaceCategory::from($value),
                percent: ($count / $categorizedCount) * 100.0,
                pointCount: $count,
            );
        }

        usort($breakdown, fn (SurfaceBreakdownEntry $a, SurfaceBreakdownEntry $b) => $b->pointCount <=> $a->pointCount);

        return new SurfaceAnalysis(
            status: $coveragePercent >= 100.0 ? 'ok' : 'partial',
            coveragePercent: $coveragePercent,
            confidence: $this->confidenceFor($coveragePercent),
            dominantCategory: $this->dominantCategory($breakdown, $categorizedCount),
            breakdown: $breakdown,
            technicality: $this->technicalityOf($matchedSegments),
        );
    }

    private function unavailable(): SurfaceAnalysis
    {
        return new SurfaceAnalysis(
            status: 'unavailable',
            coveragePercent: 0.0,
            confidence: 'low',
            dominantCategory: null,
            breakdown: [],
            technicality: new SurfaceTechnicality(),
        );
    }

    /** @param HighwaySegment[] $segments */
    private function nearestSegment(TrackPoint $point, array $segments, float $matchRadiusKm): ?HighwaySegment
    {
        $nearest     = null;
        $nearestDist = PHP_FLOAT_MAX;

        foreach ($segments as $segment) {
            foreach ($segment->points as $vertex) {
                $d = Geo::haversineKm($point->lat, $point->lon, $vertex->lat, $vertex->lon);
                if ($d < $nearestDist) {
                    $nearestDist = $d;
                    $nearest     = $segment;
                }
            }
        }

        return $nearestDist <= $matchRadiusKm ? $nearest : null;
    }

    private function categoryOf(HighwaySegment $segment): ?SurfaceCategory
    {
        $surface = $segment->surface !== null ? strtolower($segment->surface) : null;

        if ($surface !== null && (
            str_starts_with($surface, 'asphalt')
            || str_starts_with($surface, 'paved')
            || str_starts_with($surface, 'concrete')
        )) {
            return SurfaceCategory::AsphaltPaved;
        }

        if ($surface !== null && in_array($surface, ['gravel', 'fine_gravel', 'compacted', 'unpaved'], true)) {
            return SurfaceCategory::Gravel;
        }

        if ($surface !== null && in_array($surface, ['dirt', 'ground', 'grass', 'mud'], true)) {
            return SurfaceCategory::TrailPath;
        }

        $trailHighways = ['path', 'footway', 'track', 'bridleway', 'steps'];
        if ($surface === null && $segment->sacScale !== null && in_array($segment->highway, $trailHighways, true)) {
            return SurfaceCategory::TrailPath;
        }

        return null;
    }

    /** @param SurfaceBreakdownEntry[] $breakdown */
    private function dominantCategory(array $breakdown, int $categorizedCount): ?SurfaceCategory
    {
        if ($breakdown === []) {
            return null;
        }

        $top = $breakdown[0];

        return ($top->pointCount / $categorizedCount) >= self::DOMINANT_SHARE
            ? $top->category
            : SurfaceCategory::Mixed;
    }

    private function confidenceFor(float $coveragePercent): string
    {
        return match (true) {
            $coveragePercent >= 80.0 => 'high',
            $coveragePercent >= 40.0 => 'medium',
            default => 'low',
        };
    }

    /** @param HighwaySegment[] $matchedSegments */
    private function technicalityOf(array $matchedSegments): SurfaceTechnicality
    {
        $level    = null;
        $evidence = [];

        foreach ($matchedSegments as $segment) {
            if ($segment->highway === 'steps') {
                $level      = $this->maxLevel($level, TechnicalityLevel::Moderate);
                $evidence[] = 'highway=steps';
            }

            if ($segment->sacScale !== null) {
                $fromScale = $this->levelFromSacScale($segment->sacScale);
                if ($fromScale !== null) {
                    $level      = $this->maxLevel($level, $fromScale);
                    $evidence[] = 'sac_scale=' . $segment->sacScale;
                }
            }
        }

        // trail_visibility only ever modifies a level already established
        // above — poor visibility alone is not technicality evidence.
        if ($level !== null) {
            foreach ($matchedSegments as $segment) {
                if ($segment->trailVisibility !== null
                    && in_array(strtolower($segment->trailVisibility), ['bad', 'horrible', 'no'], true)
                ) {
                    $level      = $this->bump($level);
                    $evidence[] = 'trail_visibility=' . $segment->trailVisibility;
                    break;
                }
            }
        }

        return new SurfaceTechnicality(
            level: $level ?? TechnicalityLevel::Unknown,
            evidence: array_values(array_unique($evidence)),
        );
    }

    /**
     * `sac_scale`'s actual OSM tag values, not the informal T1-T6 shorthand:
     * https://wiki.openstreetmap.org/wiki/Key:sac_scale
     */
    private function levelFromSacScale(string $sacScale): ?TechnicalityLevel
    {
        return match ($sacScale) {
            'hiking', 'mountain_hiking' => TechnicalityLevel::Easy,
            'demanding_mountain_hiking', 'alpine_hiking' => TechnicalityLevel::Moderate,
            'demanding_alpine_hiking', 'difficult_alpine_hiking' => TechnicalityLevel::Difficult,
            default => null,
        };
    }

    private function maxLevel(?TechnicalityLevel $current, TechnicalityLevel $candidate): TechnicalityLevel
    {
        if ($current === null) {
            return $candidate;
        }

        $rank = ['easy' => 0, 'moderate' => 1, 'difficult' => 2];

        return $rank[$candidate->value] > $rank[$current->value] ? $candidate : $current;
    }

    private function bump(TechnicalityLevel $level): TechnicalityLevel
    {
        return match ($level) {
            TechnicalityLevel::Easy => TechnicalityLevel::Moderate,
            TechnicalityLevel::Moderate, TechnicalityLevel::Difficult => TechnicalityLevel::Difficult,
            TechnicalityLevel::Unknown => TechnicalityLevel::Unknown,
        };
    }
}
