<?php

declare(strict_types=1);

namespace Beljic\GpxTools\Http;

class CurlHttpClient implements HttpClientInterface
{
    /**
     * Must stay above OverpassClient::QUERY_TIMEOUT_SECONDS. At 30 s it sat
     * below it, so a query the server was still allowed to run for another
     * half minute was hung up on here and recorded as a failure - which is how
     * routes came back with no summits on them and no error to show for it.
     */
    public const DEFAULT_TIMEOUT = 90;

    /**
     * Statuses worth trying again. 504 belongs here: it is what an overloaded
     * Overpass instance answers ("the server is probably too busy"), and it is
     * transient by nature, unlike a 400 for a malformed query.
     */
    private const RETRYABLE_STATUSES = [429, 502, 503, 504];

    public function __construct(
        private readonly string $userAgent = 'beljic/gpx-tools/1.0 (https://github.com/beljic/gpx-tools)',
        private readonly int $timeout      = self::DEFAULT_TIMEOUT,
        private readonly int $retries      = 2,
    ) {}

    public static function isRetryable(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUSES, true);
    }

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

        if (self::isRetryable((int) $code) && $attempt < $this->retries) {
            // Backs off further each time: a busy instance that refused once is
            // no more ready a moment later.
            sleep(10 * ($attempt + 1));
            return $this->execute($url, $extra, $attempt + 1);
        }

        if ($code !== 200 || $raw === false || $raw === '') {
            return null;
        }

        return (string) $raw;
    }
}
