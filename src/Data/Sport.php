<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Data;

enum Sport: string
{
    case Running        = 'running';
    case TrailRunning   = 'trail_running';
    case Cycling        = 'cycling';
    case MountainBiking = 'mountain_biking';
    case Hiking         = 'hiking';
    case Skiing         = 'skiing';
    case Unknown        = 'unknown';

    public static function fromGpxType(?string $type): self
    {
        return match (strtolower((string) $type)) {
            'running', 'run'                             => self::Running,
            'trail_running', 'trail run', 'trail'        => self::TrailRunning,
            'cycling', 'ride', 'biking', 'bike'          => self::Cycling,
            'mountain_biking', 'mtb', 'mountain_bike'    => self::MountainBiking,
            'hiking', 'hike', 'walk', 'walking'          => self::Hiking,
            'skiing', 'ski', 'cross_country_skiing'      => self::Skiing,
            default                                      => self::Unknown,
        };
    }

    public function isPaceBased(): bool
    {
        return in_array($this, [self::Running, self::TrailRunning, self::Hiking], true);
    }

    public function isSpeedBased(): bool
    {
        return in_array($this, [self::Cycling, self::MountainBiking, self::Skiing], true);
    }
}
