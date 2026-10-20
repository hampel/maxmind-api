<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api;

use Hampel\MaxMind\Api\Exception\InvalidArgumentException;

/**
 * Which MaxMind hosts to talk to.
 *
 *     Config::live()        // geoip.maxmind.com, minfraud.maxmind.com, download.maxmind.com
 *     Config::sandbox()     // sandbox.maxmind.com for both services, and no downloads
 *
 * ONE CONFIG COVERS EVERY SERVICE, because one credential does. The GeoIP web services, minFraud
 * and the database downloads live on three hosts in production; the sandbox serves the first two
 * from one host and has no download service at all, which is why `downloadUri` is nullable.
 *
 * THE SANDBOX IS A SEPARATE ACCOUNT, not a flag on yours. Its id and key are its own, it answers
 * with fixed data for MaxMind's test addresses only, and every other address is
 * `IP_ADDRESS_NOT_FOUND`. It is a first-class environment here - a settings page with a sandbox
 * switch should be able to build one of these and nothing else.
 *
 * Every base URI is settable, for anything else: GeoLite's free web service (GEOLITE_URI, Country
 * and City only), a recorded fixture served locally, an outbound proxy that terminates TLS.
 */
final class Config
{
    public const GEOIP_URI = 'https://geoip.maxmind.com';

    public const MINFRAUD_URI = 'https://minfraud.maxmind.com';

    public const DOWNLOAD_URI = 'https://download.maxmind.com';

    public const SANDBOX_URI = 'https://sandbox.maxmind.com';

    public const GEOLITE_URI = 'https://geolite.info';

    /**
     * Where the download service redirects to: a presigned URL on Cloudflare R2, at the host
     * MaxMind publishes for firewall allow-lists.
     *
     * A redirect is followed only to an https URI on one of these hosts, and WITHOUT the
     * credential - the URL carries its own signature. An entry starting with a dot matches any
     * host under it; `.r2.cloudflarestorage.com` would accept any R2 bucket at all, which is why
     * the default names MaxMind's. If MaxMind moves its bucket, downloads fail with a message
     * naming the new host, and a Config naming it is the fix.
     */
    public const DOWNLOAD_REDIRECT_HOSTS = ['mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com'];

    public readonly string $geoipUri;

    public readonly string $minfraudUri;

    public readonly ?string $downloadUri;

    /** @var list<string> */
    public readonly array $downloadRedirectHosts;

    /**
     * @param  string|null  $downloadUri  null where there is no download service - the sandbox
     * @param  list<string>  $downloadRedirectHosts  hosts a download may be redirected to; one
     *                                               starting with a dot matches any host under it
     */
    public function __construct(
        string $geoipUri = self::GEOIP_URI,
        string $minfraudUri = self::MINFRAUD_URI,
        ?string $downloadUri = self::DOWNLOAD_URI,
        array $downloadRedirectHosts = self::DOWNLOAD_REDIRECT_HOSTS,
    ) {
        $this->geoipUri = self::base($geoipUri);
        $this->minfraudUri = self::base($minfraudUri);
        $this->downloadUri = $downloadUri === null ? null : self::base($downloadUri);

        foreach ($downloadRedirectHosts as $suffix) {
            if (preg_match('/^\.?[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $suffix) !== 1) {
                throw new InvalidArgumentException(sprintf('A download redirect host is a host name, or a suffix starting with a dot such as ".example.com"; "%s" is neither.', $suffix));
            }
        }

        $this->downloadRedirectHosts = $downloadRedirectHosts;
    }

    public static function live(): self
    {
        return new self();
    }

    public static function sandbox(): self
    {
        return new self(self::SANDBOX_URI, self::SANDBOX_URI, null);
    }

    /**
     * Whether the GeoIP web services point at the sandbox.
     */
    public function isSandbox(): bool
    {
        return strcasecmp(self::host($this->geoipUri), self::host(self::SANDBOX_URI)) === 0;
    }

    /**
     * Turn a path on one of the services into an absolute URI. The path's segments are the
     * caller's to encode.
     *
     * @param  array<string, scalar|null>  $query
     */
    public static function resolve(string $baseUri, string $path, array $query = []): string
    {
        $uri = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $baseUri . '/' . ltrim($path, '/');

        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        if ($query !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * Whether a URI points at one of the services this is configured for - scheme and host.
     *
     * Every request carries the account id and licence key, so Connection refuses to build one
     * for any other host: an absolute URI passed where a path was meant would otherwise send them
     * there.
     */
    public function ownsUri(string $uri): bool
    {
        foreach ([$this->geoipUri, $this->minfraudUri, $this->downloadUri] as $base) {
            if ($base !== null && self::sameOrigin($uri, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a download may be redirected to this URI: https, and a host in
     * `downloadRedirectHosts`.
     */
    public function allowsDownloadRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ($this->downloadRedirectHosts as $allowed) {
            $allowed = strtolower($allowed);

            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    private static function base(string $uri): string
    {
        $uri = rtrim(trim($uri), '/');

        if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
            throw new InvalidArgumentException(sprintf('A MaxMind base URI must be absolute, with a scheme: "%s" is not.', $uri));
        }

        return $uri;
    }

    private static function host(string $uri): string
    {
        return (string) parse_url($uri, PHP_URL_HOST);
    }

    private static function sameOrigin(string $uri, string $base): bool
    {
        $parts = parse_url($uri);
        $owner = parse_url($base);

        if (!is_array($parts) || !is_array($owner)) {
            return false;
        }

        return isset($parts['host'], $owner['host'])
            && strcasecmp($parts['host'], $owner['host']) === 0
            && strcasecmp($parts['scheme'] ?? '', $owner['scheme'] ?? '') === 0
            && ($parts['port'] ?? null) === ($owner['port'] ?? null)
            && !isset($parts['user']);
    }
}
