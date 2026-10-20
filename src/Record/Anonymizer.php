<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * Whether an address hides who is behind it - a VPN, a proxy, a Tor exit, a hosting provider.
 *
 * ONE RECORD, THREE SOURCES, so a rule written against it does not care which answered:
 *
 * - the Insights `anonymizer` section;
 * - the Anonymous IP database, which has the flags and nothing else;
 * - the Anonymous Plus database, which adds confidence, provider and last-seen date - under the
 *   key `anonymizer_confidence` where Insights says `confidence`, which fromDatabase() absorbs;
 * - and the Residential Proxy database, which fills only `residential`.
 *
 * FLAGS ARE FALSE WHEN ABSENT, because MaxMind sends each one only when it is true.
 *
 * `confidence` IS ANY INTEGER FROM 1 TO 99, not an enumeration. MaxMind documents 30 and 99, and
 * a release note of 2026-06-24 says some networks now answer 50; a rule should compare with a
 * threshold, never test for a value.
 *
 * `residential` MAY BE THE ONLY THING HERE. MaxMind fills it for an address seen behind a
 * residential proxy whether or not any flag above is set, so "no flag is true" is not "nothing to
 * see" - check hasResidential() too, or isFlagged(), which does.
 */
final class Anonymizer extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $confidence = null,
        public readonly bool $isAnonymous = false,
        public readonly bool $isAnonymousVpn = false,
        public readonly bool $isHostingProvider = false,
        public readonly bool $isPublicProxy = false,
        public readonly bool $isResidentialProxy = false,
        public readonly bool $isTorExitNode = false,
        public readonly ?\DateTimeImmutable $networkLastSeen = null,
        public readonly ?string $providerName = null,
        public readonly ResidentialAnonymizer $residential = new ResidentialAnonymizer(),
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * The Insights `anonymizer` section.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return self::read($row, 'confidence', ResidentialAnonymizer::fromArray(Cast::object($row['residential'] ?? null)));
    }

    /**
     * A record from the Anonymous IP or Anonymous Plus database, whose confidence key is
     * `anonymizer_confidence`.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromDatabase(array $row): self
    {
        return self::read($row, 'anonymizer_confidence', new ResidentialAnonymizer());
    }

    /**
     * A record from the Residential Proxy database, which describes only the residential feed -
     * provider, confidence and last-seen date - and so fills only `residential`.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromResidentialDatabase(array $row): self
    {
        return new self(residential: ResidentialAnonymizer::fromDatabase($row), raw: $row);
    }

    /**
     * Whether any anonymity flag is set, or the residential feed has seen the address.
     */
    public function isFlagged(): bool
    {
        return $this->isAnonymous
            || $this->isAnonymousVpn
            || $this->isHostingProvider
            || $this->isPublicProxy
            || $this->isResidentialProxy
            || $this->isTorExitNode
            || $this->hasResidential();
    }

    public function hasResidential(): bool
    {
        return !$this->residential->isEmpty();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function read(array $row, string $confidenceKey, ResidentialAnonymizer $residential): self
    {
        return new self(
            Cast::int($row[$confidenceKey] ?? null),
            Cast::flag($row['is_anonymous'] ?? null),
            Cast::flag($row['is_anonymous_vpn'] ?? null),
            Cast::flag($row['is_hosting_provider'] ?? null),
            Cast::flag($row['is_public_proxy'] ?? null),
            Cast::flag($row['is_residential_proxy'] ?? null),
            Cast::flag($row['is_tor_exit_node'] ?? null),
            Cast::date($row['network_last_seen'] ?? null),
            Cast::string($row['provider_name'] ?? null),
            $residential,
            $row,
        );
    }
}
