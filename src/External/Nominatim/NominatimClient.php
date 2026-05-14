<?php

declare(strict_types=1);

namespace Beljic\GpxTools\External\Nominatim;

use Beljic\GpxTools\Cache\CacheInterface;
use Beljic\GpxTools\Cache\NullCache;
use Beljic\GpxTools\Http\HttpClientInterface;

class NominatimClient
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache    = new NullCache(),
        private readonly int $rateLimitUs         = 1_100_000,
    ) {}

    public function reverse(float $lat, float $lon): ?array
    {
        $key = sprintf('%.4f_%.4f', $lat, $lon);

        if ($this->cache->has($key)) {
            $cached = $this->cache->get($key);
            return ($cached !== null && json_validate($cached)) ? json_decode($cached, true) : null;
        }

        $url = sprintf(
            '%s?lat=%F&lon=%F&format=json&zoom=14&addressdetails=1',
            self::ENDPOINT,
            $lat,
            $lon
        );

        $raw = $this->http->get($url);
        usleep($this->rateLimitUs);

        if ($raw === null) {
            return null;
        }

        $this->cache->set($key, $raw);

        return json_validate($raw) ? json_decode($raw, true) : null;
    }
}
