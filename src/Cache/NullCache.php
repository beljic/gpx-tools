<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Cache;

class NullCache implements CacheInterface
{
    #[\Override]
    public function get(string $key): ?string { return null; }

    #[\Override]
    public function set(string $key, string $value): void {}

    #[\Override]
    public function has(string $key): bool { return false; }
}
