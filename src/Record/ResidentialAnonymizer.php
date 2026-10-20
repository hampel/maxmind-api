<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `anonymizer.residential` - the residential proxy feed: an address seen relaying traffic for a
 * residential proxy service, with how sure MaxMind is, which service, and when.
 *
 * Added to Insights on 2026-07-17. Its own database, Residential Proxy, holds the same three
 * fields under `anonymizer_confidence`, `provider_name` and `network_last_seen`.
 */
final class ResidentialAnonymizer extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $confidence = null,
        public readonly ?\DateTimeImmutable $networkLastSeen = null,
        public readonly ?string $providerName = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['confidence'] ?? null),
            Cast::date($row['network_last_seen'] ?? null),
            Cast::string($row['provider_name'] ?? null),
            $row,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            Cast::int($row['anonymizer_confidence'] ?? null),
            Cast::date($row['network_last_seen'] ?? null),
            Cast::string($row['provider_name'] ?? null),
            $row,
        );
    }
}
