<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Http;

interface HttpClientInterface
{
    public function get(string $url): ?string;
    public function post(string $url, string $body): ?string;
}
