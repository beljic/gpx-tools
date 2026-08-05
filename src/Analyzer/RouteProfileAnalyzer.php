<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\GradientBand;
use Beljic\GpxTools\Data\GradientBucket;
use Beljic\GpxTools\Data\ParsedGpx;
use Beljic\GpxTools\Data\RouteExtremes;
use Beljic\GpxTools\Data\RouteProfile;
use Beljic\GpxTools\Data\RouteSegment;
use Beljic\GpxTools\Data\TrackPoint;
use Beljic\GpxTools\Support\Geo;

/**
 * Turns a route into gradient analytics: segments, distribution, extremes,
 * a gradient x length matrix, and the Minetti grade-adjusted distance.
 *
 * Two things have to happen before any gradient is believable. GPX points are
 * spaced by whatever the recording device felt like, so a dense cluster would
 * otherwise produce nonsense gradients over a few metres; the track is
 * therefore resampled onto fixed-distance bins. And consumer GPS elevation is
 * noisy by several metres, so the resampled elevations are smoothed before any
 * slope is computed. Skipping either step yields a profile full of imaginary
 * 40% walls.
 */
class RouteProfileAnalyzer
{
    /** Energy cost of level running, J/kg/m, from Minetti et al. (2002). */
    private const FLAT_COST = 3.6;

    /**
     * Minetti's polynomial is fitted over roughly +/-45%. Beyond that it turns
     * upward without physical meaning, so gradients are clamped before use.
     */
    private const MAX_MODELLED_GRADIENT = 0.45;

    public function __construct(
        /** Distance between resampled bins, in km. 50 m keeps short walls visible. */
        private readonly float $binKm = 0.05,
        /** Moving-average window in bins. 5 bins at 50 m smooths over 250 m. */
        private readonly int $smoothingWindow = 5,
        /** Segments shorter than this get merged into a neighbour. */
        private readonly float $minSegmentKm = 0.2,
        /** Upper bound on returned chart samples. */
        private readonly int $maxSamples = 400,
    ) {}

    public function analyze(ParsedGpx $gpx): RouteProfile
    {
        $bins = $this->resample($gpx->track);

        if (count($bins) < 2) {
            return $this->emptyProfile();
        }

        $bins = $this->smooth($bins);

        $totalKm = $bins[count($bins) - 1]['km'];

        if ($totalKm <= 0.0) {
            return $this->emptyProfile();
        }

        $steps = $this->buildSteps($bins);

        $segments     = $this->buildSegments($steps);
        $distribution = $this->buildDistribution($steps, $totalKm);
        $extremes     = $this->findExtremes($segments, $bins);
        $matrix       = $this->buildMatrix($segments);

        $flatKm = $this->flatEquivalentKm($steps);

        $climbKm   = 0.0;
        $descentKm = 0.0;
        $flatBandKm = 0.0;
        $ascentM   = 0.0;
        $descentM  = 0.0;

        foreach ($distribution as $bucket) {
            $ascentM  += $bucket->elevationGainM;
            $descentM += $bucket->elevationLossM;

            if ($bucket->band->isClimb()) {
                $climbKm += $bucket->distanceKm;
            } elseif ($bucket->band->isDescent()) {
                $descentKm += $bucket->distanceKm;
            } else {
                $flatBandKm += $bucket->distanceKm;
            }
        }

        $netChange = ($bins[count($bins) - 1]['ele'] ?? 0.0) - ($bins[0]['ele'] ?? 0.0);

        return new RouteProfile(
            segments:               $segments,
            distribution:           $distribution,
            extremes:               $extremes,
            matrix:                 $matrix,
            samples:                $this->buildSamples($steps),
            distanceKm:             $totalKm,
            flatEquivalentKm:       $flatKm,
            // The trail rule of thumb: a kilometre of effort per 100 m climbed,
            // and nothing back for the descent.
            trailEquivalentKm:      $totalKm + $ascentM / 100.0,
            totalAscentM:           $ascentM,
            totalDescentM:          $descentM,
            gradeAdjustedFactor:    $totalKm > 0 ? $flatKm / $totalKm : 1.0,
            averageGradientPercent: $totalKm > 0 ? $netChange / ($totalKm * 1000.0) * 100.0 : 0.0,
            climbPercentOfRoute:    $totalKm > 0 ? $climbKm / $totalKm * 100.0 : 0.0,
            descentPercentOfRoute:  $totalKm > 0 ? $descentKm / $totalKm * 100.0 : 0.0,
            flatPercentOfRoute:     $totalKm > 0 ? $flatBandKm / $totalKm * 100.0 : 0.0,
        );
    }

    /**
     * Walks the track accumulating distance and emits one entry every $binKm,
     * interpolating elevation between the two points that bracket each bin.
     *
     * @param  list<TrackPoint>  $points
     * @return list<array{km: float, ele: float}>
     */
    private function resample(array $points): array
    {
        $points = array_values(array_filter($points, fn (TrackPoint $p) => $p->ele !== null));
        $count  = count($points);

        if ($count < 2) {
            return [];
        }

        $bins       = [['km' => 0.0, 'ele' => (float) $points[0]->ele]];
        $nextTarget = $this->binKm;
        $travelled  = 0.0;

        for ($i = 1; $i < $count; $i++) {
            $prev = $points[$i - 1];
            $curr = $points[$i];

            $legKm = Geo::haversineKm($prev->lat, $prev->lon, $curr->lat, $curr->lon);

            if ($legKm <= 0.0) {
                continue;
            }

            $legStart = $travelled;
            $legEnd   = $travelled + $legKm;

            while ($nextTarget <= $legEnd) {
                $ratio = ($nextTarget - $legStart) / $legKm;
                $bins[] = [
                    'km'  => $nextTarget,
                    'ele' => (float) $prev->ele + ((float) $curr->ele - (float) $prev->ele) * $ratio,
                ];
                $nextTarget += $this->binKm;
            }

            $travelled = $legEnd;
        }

        // Keep the true finish so the reported distance is not truncated to the
        // last whole bin.
        if ($travelled > ($bins[count($bins) - 1]['km'] ?? 0.0)) {
            $bins[] = ['km' => $travelled, 'ele' => (float) $points[$count - 1]->ele];
        }

        return $bins;
    }

    /**
     * @param  list<array{km: float, ele: float}>  $bins
     * @return list<array{km: float, ele: float}>
     */
    private function smooth(array $bins): array
    {
        $count = count($bins);

        if ($this->smoothingWindow < 2 || $count < $this->smoothingWindow) {
            return $bins;
        }

        $half     = intdiv($this->smoothingWindow, 2);
        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            // The window shrinks symmetrically at the ends. A one-sided window
            // would drag the first and last bins toward the interior and invent
            // a gentler gradient there, which showed up as 150 m going missing
            // from the band totals of a constant climb.
            $reach = min($half, $i, $count - 1 - $i);

            $sum = 0.0;
            for ($j = $i - $reach; $j <= $i + $reach; $j++) {
                $sum += $bins[$j]['ele'];
            }

            $smoothed[] = ['km' => $bins[$i]['km'], 'ele' => $sum / ($reach * 2 + 1)];
        }

        return $smoothed;
    }

    /**
     * One step per gap between bins, carrying its own gradient and band.
     *
     * @param  list<array{km: float, ele: float}>  $bins
     * @return list<array{start_km: float, end_km: float, length_km: float, rise_m: float, gradient: float, band: GradientBand}>
     */
    private function buildSteps(array $bins): array
    {
        $steps = [];
        $count = count($bins);

        for ($i = 1; $i < $count; $i++) {
            $lengthKm = $bins[$i]['km'] - $bins[$i - 1]['km'];

            if ($lengthKm <= 0.0) {
                continue;
            }

            $rise     = $bins[$i]['ele'] - $bins[$i - 1]['ele'];
            $gradient = $rise / ($lengthKm * 1000.0) * 100.0;

            $steps[] = [
                'start_km'  => $bins[$i - 1]['km'],
                'end_km'    => $bins[$i]['km'],
                'length_km' => $lengthKm,
                'rise_m'    => $rise,
                'gradient'  => $gradient,
                'band'      => GradientBand::fromGradient($gradient),
                'start_ele' => $bins[$i - 1]['ele'],
                'end_ele'   => $bins[$i]['ele'],
            ];
        }

        return $steps;
    }

    /**
     * Merges consecutive steps sharing a band, then folds away segments too
     * short to mean anything so the list stays readable rather than listing
     * every 50 m bin as its own "segment".
     *
     * @return list<RouteSegment>
     */
    private function buildSegments(array $steps): array
    {
        if ($steps === []) {
            return [];
        }

        $runs    = [];
        $current = $this->newRun($steps[0]);

        for ($i = 1, $n = count($steps); $i < $n; $i++) {
            if ($steps[$i]['band'] === $current['band']) {
                $current['end_km']  = $steps[$i]['end_km'];
                $current['end_ele'] = $steps[$i]['end_ele'];
                continue;
            }

            $runs[]  = $current;
            $current = $this->newRun($steps[$i]);
        }

        $runs[] = $current;

        // Three passes, in this order: drop runs too short to be features,
        // absorb brief interruptions between two stretches of the same band,
        // then join whatever ended up adjacent and alike.
        $runs = $this->coalesce($this->absorbInterruptions($this->mergeShortRuns($runs)));

        return array_map(fn (array $run) => $this->toSegment($run), $runs);
    }

    private function newRun(array $step): array
    {
        return [
            'start_km'  => $step['start_km'],
            'end_km'    => $step['end_km'],
            'start_ele' => $step['start_ele'],
            'end_ele'   => $step['end_ele'],
            'band'      => $step['band'],
        ];
    }

    /**
     * A short run is absorbed by the neighbour it resembles more, measured on
     * elevation change rather than band index so a 30 m blip between two climbs
     * rejoins the climb instead of splitting it in three.
     */
    private function mergeShortRuns(array $runs): array
    {
        if (count($runs) < 2) {
            return $runs;
        }

        $changed = true;

        while ($changed && count($runs) > 1) {
            $changed = false;

            foreach ($runs as $index => $run) {
                if (($run['end_km'] - $run['start_km']) >= $this->minSegmentKm) {
                    continue;
                }

                $prev = $runs[$index - 1] ?? null;
                $next = $runs[$index + 1] ?? null;

                if ($prev === null && $next === null) {
                    break;
                }

                $target = $index - 1;

                if ($prev === null) {
                    $target = $index + 1;
                } elseif ($next !== null) {
                    $prevLength = $prev['end_km'] - $prev['start_km'];
                    $nextLength = $next['end_km'] - $next['start_km'];
                    $target     = $prevLength >= $nextLength ? $index - 1 : $index + 1;
                }

                if ($target < $index) {
                    $runs[$target]['end_km']  = $run['end_km'];
                    $runs[$target]['end_ele'] = $run['end_ele'];
                } else {
                    $runs[$target]['start_km']  = $run['start_km'];
                    $runs[$target]['start_ele'] = $run['start_ele'];
                }

                unset($runs[$index]);
                $runs    = array_values($runs);
                $changed = true;
                break;
            }
        }

        return $runs;
    }

    /**
     * A brief change of band flanked on both sides by the same band is part of
     * that stretch, not a feature of its own: nobody describes a 3 km climb as
     * "climb, breather, climb".
     *
     * The width tested against is the smoothing span itself, because that is
     * exactly how far the filter can smear a single dip. A 50 m drop inside a
     * 9% climb reappeared as 250 m of 6.4% purely as a filtering artifact, and
     * it survived the short-run pass by being longer than the minimum.
     */
    private function absorbInterruptions(array $runs): array
    {
        $span    = $this->smoothingWindow * $this->binKm;
        $changed = true;

        while ($changed) {
            $changed = false;

            for ($i = 1, $n = count($runs) - 1; $i < $n; $i++) {
                if ($runs[$i - 1]['band'] !== $runs[$i + 1]['band']) {
                    continue;
                }

                // Epsilon because an interruption exactly one smoothing span
                // wide is the common case, and it arrives as 0.2500000000004.
                if (($runs[$i]['end_km'] - $runs[$i]['start_km']) > $span + 1e-9) {
                    continue;
                }

                $runs[$i - 1]['end_km']  = $runs[$i + 1]['end_km'];
                $runs[$i - 1]['end_ele'] = $runs[$i + 1]['end_ele'];

                unset($runs[$i], $runs[$i + 1]);
                $runs    = array_values($runs);
                $changed = true;
                break;
            }
        }

        return $runs;
    }

    /** Joins neighbouring runs that ended up in the same band. */
    private function coalesce(array $runs): array
    {
        if (count($runs) < 2) {
            return $runs;
        }

        $merged  = [array_shift($runs)];

        foreach ($runs as $run) {
            $last = count($merged) - 1;

            if ($merged[$last]['band'] === $run['band']) {
                $merged[$last]['end_km']  = $run['end_km'];
                $merged[$last]['end_ele'] = $run['end_ele'];
                continue;
            }

            $merged[] = $run;
        }

        return $merged;
    }

    private function toSegment(array $run): RouteSegment
    {
        $lengthKm = $run['end_km'] - $run['start_km'];
        $rise     = $run['end_ele'] - $run['start_ele'];
        $gradient = $lengthKm > 0 ? $rise / ($lengthKm * 1000.0) * 100.0 : 0.0;

        return new RouteSegment(
            startKm:          $run['start_km'],
            endKm:            $run['end_km'],
            lengthKm:         $lengthKm,
            gradientPercent:  $gradient,
            elevationChangeM: $rise,
            startElevationM:  $run['start_ele'],
            endElevationM:    $run['end_ele'],
            // Recomputed: after merging, the run's overall gradient may sit in a
            // different band than the steps it started from.
            band:             GradientBand::fromGradient($gradient),
        );
    }

    /** @return list<GradientBucket> */
    private function buildDistribution(array $steps, float $totalKm): array
    {
        $totals = [];

        foreach (GradientBand::ordered() as $band) {
            $totals[$band->value] = ['km' => 0.0, 'gain' => 0.0, 'loss' => 0.0];
        }

        foreach ($steps as $step) {
            $key = $step['band']->value;
            $totals[$key]['km'] += $step['length_km'];

            if ($step['rise_m'] > 0) {
                $totals[$key]['gain'] += $step['rise_m'];
            } else {
                $totals[$key]['loss'] += abs($step['rise_m']);
            }
        }

        $buckets = [];

        foreach (GradientBand::ordered() as $band) {
            $entry = $totals[$band->value];

            $buckets[] = new GradientBucket(
                band:           $band,
                distanceKm:     $entry['km'],
                percentOfRoute: $totalKm > 0 ? $entry['km'] / $totalKm * 100.0 : 0.0,
                elevationGainM: $entry['gain'],
                elevationLossM: $entry['loss'],
            );
        }

        return $buckets;
    }

    /** @param  list<RouteSegment>  $segments */
    private function findExtremes(array $segments, array $bins): RouteExtremes
    {
        $longestClimb = $longestDescent = $steepestClimb = $steepestDescent = null;
        $biggestClimb = null;

        foreach ($segments as $segment) {
            if ($segment->band->isClimb()) {
                if ($longestClimb === null || $segment->lengthKm > $longestClimb->lengthKm) {
                    $longestClimb = $segment;
                }
                if ($steepestClimb === null || $segment->gradientPercent > $steepestClimb->gradientPercent) {
                    $steepestClimb = $segment;
                }
                if ($biggestClimb === null || $segment->elevationChangeM > $biggestClimb) {
                    $biggestClimb = $segment->elevationChangeM;
                }
            }

            if ($segment->band->isDescent()) {
                if ($longestDescent === null || $segment->lengthKm > $longestDescent->lengthKm) {
                    $longestDescent = $segment;
                }
                if ($steepestDescent === null || $segment->gradientPercent < $steepestDescent->gradientPercent) {
                    $steepestDescent = $segment;
                }
            }
        }

        $highest = $lowest = null;
        $highestAt = $lowestAt = null;

        foreach ($bins as $bin) {
            if ($highest === null || $bin['ele'] > $highest) {
                $highest   = $bin['ele'];
                $highestAt = $bin['km'];
            }
            if ($lowest === null || $bin['ele'] < $lowest) {
                $lowest   = $bin['ele'];
                $lowestAt = $bin['km'];
            }
        }

        return new RouteExtremes(
            longestClimb:        $longestClimb,
            longestDescent:      $longestDescent,
            steepestClimb:       $steepestClimb,
            steepestDescent:     $steepestDescent,
            highestPointM:       $highest,
            highestPointAtKm:    $highestAt,
            lowestPointM:        $lowest,
            lowestPointAtKm:     $lowestAt,
            biggestSingleClimbM: $biggestClimb,
        );
    }

    /**
     * Kilometres per gradient band, split by how long the segment ran. A course
     * with 5 km of 10% spread over twenty short ramps is a different race from
     * one with a single 5 km wall, and this is what separates them.
     *
     * @param  list<RouteSegment>  $segments
     * @return array<string, array<string, float>>
     */
    private function buildMatrix(array $segments): array
    {
        $lengthBuckets = ['<0.5', '0.5-1', '1-2', '2-5', '5+'];
        $matrix        = [];

        foreach (GradientBand::ordered() as $band) {
            $matrix[$band->value] = array_fill_keys($lengthBuckets, 0.0);
        }

        foreach ($segments as $segment) {
            $key = match (true) {
                $segment->lengthKm < 0.5 => '<0.5',
                $segment->lengthKm < 1.0 => '0.5-1',
                $segment->lengthKm < 2.0 => '1-2',
                $segment->lengthKm < 5.0 => '2-5',
                default                  => '5+',
            };

            $matrix[$segment->band->value][$key] += round($segment->lengthKm, 3);
        }

        foreach ($matrix as $band => $buckets) {
            foreach ($buckets as $bucket => $km) {
                $matrix[$band][$bucket] = round($km, 3);
            }
        }

        return $matrix;
    }

    /**
     * Minetti's energy cost of running as a function of gradient, expressed as
     * an equivalent distance on the flat. The curve dips below 1 on gentle
     * descents — running downhill really is cheaper — and rises steeply on
     * both sides beyond that.
     */
    private function flatEquivalentKm(array $steps): float
    {
        $flatKm = 0.0;

        foreach ($steps as $step) {
            $flatKm += $step['length_km'] * $this->costRatio($step['gradient'] / 100.0);
        }

        return $flatKm;
    }

    private function costRatio(float $gradient): float
    {
        $i = max(-self::MAX_MODELLED_GRADIENT, min(self::MAX_MODELLED_GRADIENT, $gradient));

        $cost = 155.4 * $i ** 5
            - 30.4 * $i ** 4
            - 43.3 * $i ** 3
            + 46.3 * $i ** 2
            + 19.5 * $i
            + self::FLAT_COST;

        // The polynomial can dip fractionally negative around -20% on some
        // fits; a non-positive cost would be meaningless here.
        return max(0.1, $cost) / self::FLAT_COST;
    }

    /**
     * Evenly spaced points for drawing the profile, capped so a 60 km route
     * does not ship 1200 samples to a browser.
     *
     * @return list<array{distance_km: float, elevation_m: float, gradient_percent: float}>
     */
    private function buildSamples(array $steps): array
    {
        $count = count($steps);

        if ($count === 0) {
            return [];
        }

        $stride  = max(1, (int) ceil($count / $this->maxSamples));
        $samples = [];

        for ($i = 0; $i < $count; $i += $stride) {
            $samples[] = [
                'distance_km'      => round($steps[$i]['start_km'], 3),
                'elevation_m'      => round($steps[$i]['start_ele'], 1),
                'gradient_percent' => round($steps[$i]['gradient'], 1),
            ];
        }

        $last = $steps[$count - 1];

        $samples[] = [
            'distance_km'      => round($last['end_km'], 3),
            'elevation_m'      => round($last['end_ele'], 1),
            'gradient_percent' => round($last['gradient'], 1),
        ];

        return $samples;
    }

    private function emptyProfile(): RouteProfile
    {
        $distribution = array_map(
            fn (GradientBand $band) => new GradientBucket($band, 0.0, 0.0, 0.0, 0.0),
            GradientBand::ordered()
        );

        $matrix = [];
        foreach (GradientBand::ordered() as $band) {
            $matrix[$band->value] = array_fill_keys(['<0.5', '0.5-1', '1-2', '2-5', '5+'], 0.0);
        }

        return new RouteProfile(
            segments:               [],
            distribution:           $distribution,
            extremes:               new RouteExtremes,
            matrix:                 $matrix,
            samples:                [],
            distanceKm:             0.0,
            flatEquivalentKm:       0.0,
            trailEquivalentKm:      0.0,
            totalAscentM:           0.0,
            totalDescentM:          0.0,
            gradeAdjustedFactor:    1.0,
            averageGradientPercent: 0.0,
            climbPercentOfRoute:    0.0,
            descentPercentOfRoute:  0.0,
            flatPercentOfRoute:     0.0,
        );
    }
}