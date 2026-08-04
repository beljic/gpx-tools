<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * A predicted finish time, together with everything it was derived from, so a
 * caller can show the working rather than an unexplained number.
 */
readonly class FinishTimeEstimate
{
    public function __construct(
        public float $referenceDistanceKm,
        public int $referenceSeconds,
        public float $routeDistanceKm,
        public float $flatEquivalentKm,
        public int $estimatedSeconds,
        public float $riegelExponent,
    ) {}

    public function formatted(): string
    {
        $h = intdiv($this->estimatedSeconds, 3600);
        $m = intdiv($this->estimatedSeconds % 3600, 60);
        $s = $this->estimatedSeconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    /** Average pace over the real distance, not the flat-equivalent one. */
    public function paceSecPerKm(): ?float
    {
        if ($this->routeDistanceKm <= 0.0) {
            return null;
        }

        return $this->estimatedSeconds / $this->routeDistanceKm;
    }

    public function toArray(): array
    {
        return [
            'reference_distance_km' => round($this->referenceDistanceKm, 3),
            'reference_seconds'     => $this->referenceSeconds,
            'route_distance_km'     => round($this->routeDistanceKm, 3),
            'flat_equivalent_km'    => round($this->flatEquivalentKm, 2),
            'estimated_seconds'     => $this->estimatedSeconds,
            'estimated_formatted'   => $this->formatted(),
            'pace_sec_per_km'       => $this->paceSecPerKm() !== null ? round($this->paceSecPerKm()) : null,
            'riegel_exponent'       => $this->riegelExponent,
        ];
    }
}