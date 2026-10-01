<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Tests\Unit;

use Beljic\GpxTools\Analyzer\TrainingAnalyzer;
use Beljic\GpxTools\Data\EffortLevel;
use Beljic\GpxTools\Data\Sport;
use Beljic\GpxTools\Data\TrackStats;
use Beljic\GpxTools\Data\TrainingSuggestion;
use PHPUnit\Framework\TestCase;

class TrainingAnalyzerTest extends TestCase
{
    private TrainingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new TrainingAnalyzer();
    }

    public function testShortFlatRunIsRecoveryOrEasy(): void
    {
        $stats  = $this->makeStats(distanceKm: 5.0, elevationGainM: 20.0, durationSeconds: 1800.0);
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $this->assertContains($report->effortLevel, [EffortLevel::Recovery, EffortLevel::Easy]);
    }

    public function testLongTrailRunWithBigElevationIsHardOrMore(): void
    {
        $stats  = $this->makeStats(distanceKm: 40.0, elevationGainM: 2000.0, durationSeconds: 18000.0);
        $report = $this->analyzer->analyze($stats, Sport::TrailRunning);

        $this->assertContains($report->effortLevel, [
            EffortLevel::Hard,
            EffortLevel::VeryHard,
            EffortLevel::Race,
        ]);
    }

    public function testVeryShortRunIsRecovery(): void
    {
        $stats  = $this->makeStats(distanceKm: 3.0, elevationGainM: 0.0, durationSeconds: 900.0);
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $this->assertSame(EffortLevel::Recovery, $report->effortLevel);
    }

    public function testHrBasedClassificationOverridesDistanceBased(): void
    {
        // Short distance but very high HR → hard/race effort
        $stats = $this->makeStats(
            distanceKm:    5.0,
            elevationGainM: 0.0,
            durationSeconds: 1200.0,
            avgHeartRate:   175,
            maxHeartRate:   185,
        );
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $this->assertContains($report->effortLevel, [EffortLevel::VeryHard, EffortLevel::Race]);
    }

    public function testLowHrIsEasyOrRecovery(): void
    {
        $stats = $this->makeStats(
            distanceKm:    10.0,
            elevationGainM: 100.0,
            durationSeconds: 3600.0,
            avgHeartRate:   110,
            maxHeartRate:   185,
        );
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $this->assertContains($report->effortLevel, [EffortLevel::Recovery, EffortLevel::Easy]);
    }

    public function testSummaryContainsSportAndDistance(): void
    {
        $stats  = $this->makeStats(distanceKm: 12.5, elevationGainM: 300.0, durationSeconds: 3600.0);
        $report = $this->analyzer->analyze($stats, Sport::TrailRunning);

        $this->assertStringContainsString('12.5 km', $report->summary);
    }

    public function testSportIsStoredInReport(): void
    {
        $stats  = $this->makeStats(distanceKm: 10.0, elevationGainM: 0.0);
        $report = $this->analyzer->analyze($stats, Sport::Cycling);

        $this->assertSame(Sport::Cycling, $report->sport);
    }

    public function testHardEffortProducesRecoverySuggestion(): void
    {
        $stats  = $this->makeStats(distanceKm: 30.0, elevationGainM: 1500.0, durationSeconds: 10800.0);
        $report = $this->analyzer->analyze($stats, Sport::TrailRunning);

        $this->assertNotEmpty($report->suggestions);
        $hasSuggestion = false;
        foreach ($report->suggestions as $s) {
            if (str_contains(strtolower($s), 'recover') || str_contains(strtolower($s), 'days') || str_contains(strtolower($s), 'rest')) {
                $hasSuggestion = true;
                break;
            }
        }
        $this->assertTrue($hasSuggestion, 'Expected recovery suggestion for hard effort');
    }

    public function testHighElevationDensityProducesSuggestion(): void
    {
        // >100 m/km elevation density
        $stats  = $this->makeStats(distanceKm: 10.0, elevationGainM: 1500.0, durationSeconds: 7200.0);
        $report = $this->analyzer->analyze($stats, Sport::TrailRunning);

        $hasElevSuggestion = false;
        foreach ($report->suggestions as $s) {
            if (str_contains(strtolower($s), 'elevation') || str_contains(strtolower($s), 'uphill')) {
                $hasElevSuggestion = true;
                break;
            }
        }
        $this->assertTrue($hasElevSuggestion, 'Expected elevation-related suggestion');
    }

    public function testHighTemperatureProducesSuggestion(): void
    {
        $stats  = $this->makeStats(distanceKm: 10.0, elevationGainM: 0.0, avgTemperature: 35.0);
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $hasHeatSuggestion = false;
        foreach ($report->suggestions as $s) {
            if (str_contains(strtolower($s), 'heat') || str_contains(strtolower($s), 'hydrat')) {
                $hasHeatSuggestion = true;
                break;
            }
        }
        $this->assertTrue($hasHeatSuggestion, 'Expected heat/hydration suggestion');
    }

    public function testEverySuggestionHasAMatchingCodeWithItsNumbers(): void
    {
        $stats  = $this->makeStats(distanceKm: 10.0, elevationGainM: 1500.0, durationSeconds: 25000.0, avgTemperature: 35.0, avgPaceSecPerKm: 600.0);
        $report = $this->analyzer->analyze($stats, Sport::TrailRunning);

        $this->assertCount(count($report->suggestions), $report->suggestionDetails);
        foreach ($report->suggestionDetails as $i => $detail) {
            $this->assertInstanceOf(TrainingSuggestion::class, $detail);
            $this->assertSame($report->suggestions[$i], $detail->message);
        }

        $byCode = [];
        foreach ($report->suggestionDetails as $detail) {
            $byCode[$detail->code] = $detail->params;
        }
        $this->assertSame(['days' => 5], $byCode['recovery_days']);
        $this->assertSame(['m_per_km' => 150], $byCode['high_elevation_density']);
        $this->assertSame(['celsius' => 35], $byCode['heat']);
        $this->assertSame([], $byCode['power_hiking']);
        $this->assertSame([], $byCode['missing_heart_rate']);
    }

    public function testColdAndSuspiciousMaxHeartRateHaveCodes(): void
    {
        $stats  = $this->makeStats(distanceKm: 5.0, elevationGainM: 0.0, avgHeartRate: 150, maxHeartRate: 205, avgTemperature: -3.0);
        $report = $this->analyzer->analyze($stats, Sport::Running);

        $codes = array_map(static fn (TrainingSuggestion $s): string => $s->code, $report->suggestionDetails);
        $this->assertContains('cold', $codes);
        $this->assertContains('max_heart_rate_suspicious', $codes);
    }

    private function makeStats(
        float $distanceKm,
        float $elevationGainM,
        ?float $durationSeconds = null,
        ?int $avgHeartRate      = null,
        ?int $maxHeartRate      = null,
        ?float $avgTemperature  = null,
        ?float $avgPaceSecPerKm = null,
    ): TrackStats {
        return new TrackStats(
            distanceKm:      $distanceKm,
            elevationGainM:  $elevationGainM,
            elevationLossM:  0.0,
            maxElevationM:   1000.0,
            minElevationM:   500.0,
            pointCount:      100,
            durationSeconds: $durationSeconds,
            avgHeartRate:    $avgHeartRate,
            maxHeartRate:    $maxHeartRate,
            avgTemperature:  $avgTemperature,
            avgPaceSecPerKm: $avgPaceSecPerKm,
        );
    }
}
