<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Download;

/**
 * What a download fetched, and the evidence it is what was asked for.
 *
 * `sha256` is the digest of the bytes received, which matched the one MaxMind published when
 * `verified` is true. `lastModified` is MaxMind's build date for the release - the value to keep
 * and compare with Downloads::lastModified() before the next download.
 */
final class Download
{
    public function __construct(
        public readonly string $editionId,
        public readonly string $suffix,
        public readonly string $sha256,
        public readonly bool $verified,
        public readonly int $bytes,
        public readonly ?string $filename = null,
        public readonly ?\DateTimeImmutable $lastModified = null,
        public readonly ?string $path = null,
        public readonly ?string $member = null,
    ) {
    }

    /**
     * The release date in the filename MaxMind gave - `GeoLite2-City_20261017.tar.gz` is
     * `20261017` - or null when no filename came with the download.
     */
    public function releaseDate(): ?string
    {
        return $this->filename !== null && preg_match('/_(\d{8})\./', $this->filename, $match) === 1 ? $match[1] : null;
    }
}
