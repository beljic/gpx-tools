<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Analyzer;

use Beljic\GpxTools\Data\EffortLevel;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Data\TrackStats;
use Beljic\GpxTools\Data\TrainingReport;

class TrainingAnalyzer
{
    /**
     * Equivalent flat distance: adds 1 km per 100 m of elevation gain.
     * Standard trail running heuristic for effort estimation.
     */
    private const ELEVATION_KM_PER_100M = 1.0;

    public function analyze(TrackStats $stats, Sport $sport = Sport::Unknown): TrainingReport
    {
        $effortLevel = $this->classifyEffort($stats, $sport);
        $summary     = $this->buildSummary($stats, $sport, $effortLevel);
        $suggestions = $this->buildSuggestions($stats, $sport, $effortLevel);
        $zones       = $this->buildZones($stats);

        return new TrainingReport(
            effortLevel: $effortLevel,
            sport:       $sport,
            summary:     $summary,
            suggestions: $suggestions,
            zones:       $zones,
        );
    }

    private function classifyEffort(TrackStats $stats, Sport $sport): EffortLevel
    {
        // Use HR-based classification if available
        if ($stats->avgHeartRate !== null && $stats->maxHeartRate !== null) {
            $hrRatio = $stats->avgHeartRate / $stats->maxHeartRate;
            return match (true) {
                $hrRatio < 0.60 => EffortLevel::Recovery,
                $hrRatio < 0.70 => EffortLevel::Easy,
                $hrRatio < 0.80 => EffortLevel::Moderate,
                $hrRatio < 0.87 => EffortLevel::Hard,
                $hrRatio < 0.93 => EffortLevel::VeryHard,
                default         => EffortLevel::Race,
            };
        }

        // Fallback: equivalent flat km
        $equivKm = $stats->distanceKm + ($stats->elevationGainM / 100.0) * self::ELEVATION_KM_PER_100M;

        // Duration bonus: long slow efforts are harder than their distance suggests
        $durationHours = ($stats->movingTimeSeconds ?? $stats->durationSeconds ?? 0) / 3600.0;

        $score = match (true) {
            $equivKm < 8  && $durationHours < 0.75 => 1,
            $equivKm < 15 && $durationHours < 1.5  => 2,
            $equivKm < 25 && $durationHours < 2.5  => 3,
            $equivKm < 40 && $durationHours < 4.0  => 4,
            $equivKm < 60 && $durationHours < 6.0  => 5,
            default                                 => 6,
        };

        return match ($score) {
            1       => EffortLevel::Recovery,
            2       => EffortLevel::Easy,
            3       => EffortLevel::Moderate,
            4       => EffortLevel::Hard,
            5       => EffortLevel::VeryHard,
            default => EffortLevel::Race,
        };
    }

    private function buildSummary(TrackStats $stats, Sport $sport, EffortLevel $effort): string
    {
        $parts = [];

        $parts[] = sprintf('%.1f km', $stats->distanceKm);

        if ($stats->elevationGainM > 0) {
            $parts[] = sprintf('+%d m elevation', (int) $stats->elevationGainM);
        }

        if ($stats->durationFormatted() !== null) {
            $parts[] = $stats->durationFormatted();
        }

        if ($sport->isPaceBased() && $stats->avgPaceFormatted() !== null) {
            $parts[] = sprintf('avg pace %s /km', $stats->avgPaceFormatted());
        } elseif ($sport->isSpeedBased() && $stats->avgSpeedKmh !== null) {
            $parts[] = sprintf('avg %.1f km/h', $stats->avgSpeedKmh);
        }

        if ($stats->avgHeartRate !== null) {
            $parts[] = sprintf('avg HR %d bpm', $stats->avgHeartRate);
        }

        return implode(' · ', $parts) . sprintf(' · %s effort', $effort->value);
    }

    /** @return string[] */
    private function buildSuggestions(TrackStats $stats, Sport $sport, EffortLevel $effort): array
    {
        $suggestions = [];

        // Recovery time
        $recoveryDays = match ($effort) {
            EffortLevel::Recovery, EffortLevel::Easy => 1,
            EffortLevel::Moderate                    => 1,
            EffortLevel::Hard                        => 2,
            EffortLevel::VeryHard                    => 3,
            EffortLevel::Race                        => 5,
        };

        if ($recoveryDays >= 2) {
            $suggestions[] = sprintf(
                'Plan at least %d days of easy training or rest before the next hard session.',
                $recoveryDays
            );
        }

        // Elevation density
        $elevPerKm = $stats->distanceKm > 0 ? $stats->elevationGainM / $stats->distanceKm : 0;
        if ($elevPerKm > 100) {
            $suggestions[] = sprintf(
                'High elevation density (%.0f m/km). Focus on uphill running form and downhill quad strength.',
                $elevPerKm
            );
        }

        // Pace spread (trail running specific)
        if ($sport === Sport::TrailRunning && $stats->avgPaceSecPerKm !== null) {
            if ($stats->avgPaceSecPerKm > 480) { // > 8 min/km
                $suggestions[] = 'Average pace suggests power hiking sections — normal for technical trails with significant elevation.';
            }
        }

        // HR data quality hints
        if ($stats->avgHeartRate === null && ($stats->distanceKm > 10 || $stats->elevationGainM > 500)) {
            $suggestions[] = 'No heart rate data available. Consider a chest strap or optical HR monitor for more accurate effort measurement on longer efforts.';
        }

        if ($stats->maxHeartRate !== null && $stats->maxHeartRate > 190) {
            $suggestions[] = sprintf(
                'Max HR %d bpm is unusually high — verify sensor data or check for GPS/HR spikes.',
                $stats->maxHeartRate
            );
        }

        // Temperature
        if ($stats->avgTemperature !== null) {
            if ($stats->avgTemperature > 30) {
                $suggestions[] = sprintf(
                    'Activity performed in heat (avg %.0f°C). Ensure adequate hydration and allow extra recovery.',
                    $stats->avgTemperature
                );
            } elseif ($stats->avgTemperature < 0) {
                $suggestions[] = 'Cold conditions — factor in extra warm-up time and layering when planning similar efforts.';
            }
        }

        return $suggestions;
    }

    /** @return array<string, mixed> */
    private function buildZones(TrackStats $stats): array
    {
        if ($stats->avgHeartRate === null) {
            return [];
        }

        return [
            'avg_hr'  => $stats->avgHeartRate,
            'max_hr'  => $stats->maxHeartRate,
        ];
    }
}
