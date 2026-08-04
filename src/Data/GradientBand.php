<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

/**
 * Gradient bands used to classify a stretch of route.
 *
 * The boundaries are the ones trail runners already think in: 3% is where a
 * road runner first feels a hill, 8% is where most people stop running the
 * climb, and 15% is where hands-on-knees hiking starts. They are deliberately
 * symmetric so a climb and its matching descent land in mirrored bands.
 */
enum GradientBand: string
{
    case SteepDescent = 'steep_descent';
    case Descent      = 'descent';
    case EasyDescent  = 'easy_descent';
    case Flat         = 'flat';
    case EasyClimb    = 'easy_climb';
    case Climb        = 'climb';
    case SteepClimb   = 'steep_climb';

    public static function fromGradient(float $gradientPercent): self
    {
        return match (true) {
            $gradientPercent < -15.0 => self::SteepDescent,
            $gradientPercent < -8.0  => self::Descent,
            $gradientPercent < -3.0  => self::EasyDescent,
            $gradientPercent <= 3.0  => self::Flat,
            $gradientPercent <= 8.0  => self::EasyClimb,
            $gradientPercent <= 15.0 => self::Climb,
            default                  => self::SteepClimb,
        };
    }

    /** Inclusive lower and exclusive upper bound in percent, null where unbounded. */
    public function range(): array
    {
        return match ($this) {
            self::SteepDescent => [null, -15.0],
            self::Descent      => [-15.0, -8.0],
            self::EasyDescent  => [-8.0, -3.0],
            self::Flat         => [-3.0, 3.0],
            self::EasyClimb    => [3.0, 8.0],
            self::Climb        => [8.0, 15.0],
            self::SteepClimb   => [15.0, null],
        };
    }

    public function isClimb(): bool
    {
        return $this === self::EasyClimb || $this === self::Climb || $this === self::SteepClimb;
    }

    public function isDescent(): bool
    {
        return $this === self::EasyDescent || $this === self::Descent || $this === self::SteepDescent;
    }

    /** Bands from steepest descent to steepest climb, for stable chart ordering. */
    public static function ordered(): array
    {
        return [
            self::SteepDescent,
            self::Descent,
            self::EasyDescent,
            self::Flat,
            self::EasyClimb,
            self::Climb,
            self::SteepClimb,
        ];
    }
}