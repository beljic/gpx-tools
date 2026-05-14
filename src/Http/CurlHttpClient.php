<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Http;

class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly string $userAgent = 'beljic/gpx-tools/1.0 (https://github.com/beljic/gpx-tools)',
        private readonly int $timeout      = 30,
        private readonly int $retries      = 1,
    ) {}

    #[\Override]
    public function get(string $url): ?string
    {
        return $this->execute($url, []);
    }

    #[\Override]
    public function post(string $url, string $body): ?string
    {
        return $this->execute($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $body,
        ]);
    }

    private function execute(string $url, array $extra, int $attempt = 0): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $extra + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (in_array($code, [429, 503], true) && $attempt < $this->retries) {
            sleep(10);
            return $this->execute($url, $extra, $attempt + 1);
        }

        if ($code !== 200 || $raw === false || $raw === '') {
            return null;
        }

        return (string) $raw;
    }
}
