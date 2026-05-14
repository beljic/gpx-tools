<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Cache;

interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value): void;
    public function has(string $key): bool;
}
