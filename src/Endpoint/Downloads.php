<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Endpoint;

use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Download\Download;
use Hampel\MaxMind\Api\Download\TarGz;
use Hampel\MaxMind\Api\Exception\ChecksumMismatchException;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Hampel\MaxMind\Api\Exception\MalformedResponseException;
use Hampel\MaxMind\Api\Exception\RuntimeException;
use Psr\Http\Message\ResponseInterface;

/**
 * Database downloads: `GET https://download.maxmind.com/geoip/databases/{edition}/download`.
 *
 *     $downloads = $maxmind->downloads();
 *
 *     $downloads->lastModified('GeoLite2-City');                        // free: a HEAD
 *     $downloads->database('GeoLite2-City', '/data/GeoLite2-City.mmdb'); // fetch, verify, extract
 *
 * THE DAILY LIMIT IS WHY lastModified() EXISTS. A download counts against 1,000 a day - 30 for a
 * GeoLite account - and MaxMind releases most databases twice a week. A HEAD is not counted, so
 * a scheduled job should compare lastModified() with the date it kept from the last Download and
 * fetch only when they differ.
 *
 * THE DOWNLOAD IS A REDIRECT. MaxMind answers with a 302 to a presigned Cloudflare R2 URL;
 * Connection::fetch() follows it without the credential, to MaxMind's bucket only. The
 * credential-in-the-query-string endpoint, `/app/geoip_download?license_key=`, is not used: it
 * puts the licence key in every access log between here and MaxMind.
 *
 * NOTHING IS BUFFERED IN A STRING. A City database is tens of megabytes; it is streamed to the
 * target in chunks, hashed as it goes, and checked against the `.sha256` MaxMind publishes.
 *
 * THE SANDBOX HAS NO DOWNLOAD SERVICE, and every method here refuses a Config without one.
 */
final class Downloads extends Endpoint
{
    public const SUFFIXES = ['tar.gz', 'zip'];

    private const CHUNK = 1048576;

    /**
     * When the current release of an edition was built - or of the release on $date - without
     * spending a download. Null if MaxMind sent no `Last-Modified`.
     */
    public function lastModified(string $editionId, \DateTimeInterface|string|null $date = null): ?\DateTimeImmutable
    {
        $response = $this->connection->head(
            $this->connection->request('HEAD', $this->uri($editionId, 'tar.gz', $date))
        );

        return self::lastModifiedOf($response);
    }

    /**
     * The SHA-256 MaxMind publishes for a release, as 64 lowercase hex digits.
     *
     * The file is `sha256sum` output - the digest, then the filename - and only the digest is
     * read. COUNTS AS A DOWNLOAD, as far as MaxMind documents; download() and database() fetch it
     * only after the archive, for the release the archive turned out to be.
     */
    public function checksum(string $editionId, string $suffix = 'tar.gz', \DateTimeInterface|string|null $date = null): string
    {
        $uri = $this->uri($editionId, self::suffix($suffix) . '.sha256', $date);
        $request = $this->connection->request('GET', $uri);
        $response = $this->connection->fetch($request);
        $body = trim((string) $response->getBody());

        if (preg_match('/^([0-9a-f]{64})\b/i', $body, $match) !== 1) {
            throw MalformedResponseException::forResponse('GET', $uri, $response, $body, 'a SHA-256 checksum');
        }

        return strtolower($match[1]);
    }

    /**
     * Download an archive to a path or an open stream, verified against MaxMind's checksum.
     *
     * TO A PATH, it is written beside the target under a temporary name and renamed into place
     * only once verified; a mismatch removes it and raises ChecksumMismatchException. TO A STREAM,
     * the bytes are written as they arrive - a caller that passes its own stream owns what is in
     * it if the check then fails.
     *
     * The checksum is fetched AFTER the archive, for the release date in the filename MaxMind
     * gave it, so a release published between the two requests cannot cause a false mismatch.
     *
     * @param  string|resource  $target
     */
    public function download(
        string $editionId,
        mixed $target,
        string $suffix = 'tar.gz',
        \DateTimeInterface|string|null $date = null,
        bool $verify = true,
    ): Download {
        $suffix = self::suffix($suffix);
        $request = $this->connection->request('GET', $this->uri($editionId, $suffix, $date));
        $response = $this->connection->fetch($request);

        $filename = self::filenameOf($response) ?? self::filenameOf($request->getUri()->getPath());
        $lastModified = self::lastModifiedOf($response);

        if (is_string($target)) {
            $temporary = sprintf('%s.%s.part', $target, bin2hex(random_bytes(6)));
            $out = @fopen($temporary, 'xb');

            if ($out === false) {
                throw new RuntimeException(sprintf('Could not create "%s" to download into.', $temporary));
            }

            try {
                [$sha256, $bytes] = self::copy($response, $out, $temporary);
                fclose($out);
                $out = null;

                $verified = $verify && $this->verify($editionId, $suffix, $date, $filename, $sha256);

                if (!@rename($temporary, $target)) {
                    throw new RuntimeException(sprintf('Could not move the download into place at "%s".', $target));
                }
            } finally {
                if ($out !== null) {
                    fclose($out);
                }

                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return new Download($editionId, $suffix, $sha256, $verified, $bytes, $filename, $lastModified, $target);
        }

        if (!is_resource($target)) {
            throw new InvalidArgumentException('A download target is a path or an open stream.');
        }

        [$sha256, $bytes] = self::copy($response, $target, 'the stream given');
        $verified = $verify && $this->verify($editionId, $suffix, $date, $filename, $sha256);

        return new Download($editionId, $suffix, $sha256, $verified, $bytes, $filename, $lastModified);
    }

    /**
     * Download an edition's `.tar.gz`, verify it, and put the `.mmdb` inside it at $path -
     * replacing what is there atomically, so a Reader open on the old file keeps working until it
     * is reopened.
     *
     * The archive goes to $workDirectory - the system temporary directory unless given - and is
     * removed afterwards whatever happens. A host application with its own temporary directory
     * should pass it.
     */
    public function database(
        string $editionId,
        string $path,
        \DateTimeInterface|string|null $date = null,
        ?string $workDirectory = null,
    ): Download {
        $directory = rtrim($workDirectory ?? sys_get_temp_dir(), '/\\');
        $archive = sprintf('%s/%s-%s.tar.gz', $directory, self::edition($editionId), bin2hex(random_bytes(6)));

        try {
            $download = $this->download($editionId, $archive, 'tar.gz', $date);
            $member = TarGz::extract($archive, $path);
        } finally {
            if (is_file($archive)) {
                @unlink($archive);
            }
        }

        return new Download(
            $download->editionId,
            $download->suffix,
            $download->sha256,
            $download->verified,
            $download->bytes,
            $download->filename,
            $download->lastModified,
            $path,
            $member
        );
    }

    /**
     * @return true
     */
    private function verify(string $editionId, string $suffix, \DateTimeInterface|string|null $date, ?string $filename, string $sha256): bool
    {
        $released = $filename !== null && preg_match('/_(\d{8})\./', $filename, $match) === 1 ? $match[1] : null;
        $expected = $this->checksum($editionId, $suffix, $date ?? $released);

        if (!hash_equals($expected, $sha256)) {
            throw new ChecksumMismatchException(sprintf(
                'The %s download%s does not match the SHA-256 MaxMind published for it: received %s, expected %s.',
                $editionId,
                $filename === null ? '' : ' (' . $filename . ')',
                $sha256,
                $expected
            ));
        }

        return true;
    }

    /**
     * @param  resource  $out
     * @return array{string, int}  the SHA-256 of what was written, and its length
     */
    private static function copy(ResponseInterface $response, $out, string $where): array
    {
        $body = $response->getBody();
        $hash = hash_init('sha256');
        $bytes = 0;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK);

            if ($chunk === '') {
                break;
            }

            hash_update($hash, $chunk);

            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new RuntimeException(sprintf('Could not write to %s - is the disk full?', $where));
            }

            $bytes += strlen($chunk);
        }

        return [hash_final($hash), $bytes];
    }

    private function uri(string $editionId, string $suffix, \DateTimeInterface|string|null $date): string
    {
        $base = $this->connection->config()->downloadUri;

        if ($base === null) {
            throw new RuntimeException('This configuration has no download service - the sandbox has none. Database downloads need Config::live() and a production licence key.');
        }

        return Config::resolve(
            $base,
            'geoip/databases/' . self::edition($editionId) . '/download',
            ['suffix' => $suffix, 'date' => self::date($date)]
        );
    }

    /**
     * An edition id as MaxMind writes it - `GeoLite2-City`, `GeoIP-Anonymous-Plus`. Case matters
     * to MaxMind, and the wrong case is a 404, so it is not normalised.
     */
    private static function edition(string $editionId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $editionId) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a MaxMind edition id, such as GeoLite2-City.', $editionId));
        }

        return $editionId;
    }

    private static function suffix(string $suffix): string
    {
        if (!in_array($suffix, self::SUFFIXES, true)) {
            throw new InvalidArgumentException(sprintf('A download suffix is one of %s; "%s" is not.', implode(', ', self::SUFFIXES), $suffix));
        }

        return $suffix;
    }

    /**
     * A release date as MaxMind takes it, `YYYYMMDD`, from a DateTime or from `YYYYMMDD` or
     * `YYYY-MM-DD` - read off the value's own calendar, without converting its timezone.
     */
    private static function date(\DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Ymd');
        }

        $compact = str_replace('-', '', trim($date));
        $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $compact);

        if (preg_match('/^\d{8}$/', $compact) !== 1 || $parsed === false || $parsed->format('Ymd') !== $compact) {
            throw new InvalidArgumentException(sprintf('A release date is YYYYMMDD or YYYY-MM-DD; "%s" is neither.', $date));
        }

        return $compact;
    }

    private static function lastModifiedOf(ResponseInterface $response): ?\DateTimeImmutable
    {
        $header = $response->getHeaderLine('Last-Modified');

        if ($header === '') {
            return null;
        }

        // The weekday is read past, not parsed: PHP resolves a weekday that disagrees with the
        // date by moving the date to match it, which turns a sender's slip into a wrong day.
        if (preg_match('/(\d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2}) GMT$/', $header, $match) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!d M Y H:i:s', $match[1], new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /**
     * The filename from `Content-Disposition`, or from the last segment of a path.
     */
    private static function filenameOf(ResponseInterface|string $source): ?string
    {
        if ($source instanceof ResponseInterface) {
            $header = $source->getHeaderLine('Content-Disposition');

            return preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $header, $match) === 1 ? basename(rawurldecode($match[1])) : null;
        }

        $name = basename($source);

        return preg_match('/_\d{8}\./', $name) === 1 ? $name : null;
    }
}
