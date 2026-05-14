<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Cache;

class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    #[\Override]
    public function get(string $key): ?string
    {
        $path = $this->path($key);
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    #[\Override]
    public function set(string $key, string $value): void
    {
        file_put_contents($this->path($key), $value);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . $key . '.json';
    }
}
