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
 * NULL IS "NOT KNOWN", FALSE IS "CHECKED, NO". MaxMind sends each flag only when it is true, so
 * from a source that covers a flag - Insights, Anonymous IP, Anonymous Plus - an absent flag is
 * false. The Residential Proxy database covers none of them, so from it every flag is null; and
 * only Insights and Residential Proxy carry the residential feed, so from the other two
 * `residential` is null rather than an empty record. A rule should treat null as "this source
 * cannot say", which is not the same as "clean".
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
        public readonly ?bool $isAnonymous = null,
        public readonly ?bool $isAnonymousVpn = null,
        public readonly ?bool $isHostingProvider = null,
        public readonly ?bool $isPublicProxy = null,
        public readonly ?bool $isResidentialProxy = null,
        public readonly ?bool $isTorExitNode = null,
        public readonly ?\DateTimeImmutable $networkLastSeen = null,
        public readonly ?string $providerName = null,
        public readonly ?ResidentialAnonymizer $residential = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * The Insights `anonymizer` section - or its absence, which from Insights is an answer: every
     * flag false and nothing seen by the residential feed.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return self::read($row, 'confidence', ResidentialAnonymizer::fromArray(Cast::object($row['residential'] ?? null)));
    }

    /**
     * A record from the Anonymous IP or Anonymous Plus database, whose confidence key is
     * `anonymizer_confidence`. Neither carries the residential feed, so `residential` is null.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromDatabase(array $row): self
    {
        return self::read($row, 'anonymizer_confidence', null);
    }

    /**
     * A record from the Residential Proxy database, which describes only the residential feed -
     * provider, confidence and last-seen date - and so fills only `residential`. Every flag is
     * null: this database cannot say whether an address is a VPN or a Tor exit.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromResidentialDatabase(array $row): self
    {
        return new self(residential: ResidentialAnonymizer::fromDatabase($row), raw: $row);
    }

    /**
     * Whether any anonymity flag is true, or the residential feed has seen the address.
     *
     * False does not mean clean when the source could not answer every flag - check
     * isComplete() for that.
     */
    public function isFlagged(): bool
    {
        return $this->isAnonymous === true
            || $this->isAnonymousVpn === true
            || $this->isHostingProvider === true
            || $this->isPublicProxy === true
            || $this->isResidentialProxy === true
            || $this->isTorExitNode === true
            || $this->hasResidential();
    }

    /**
     * Whether the residential feed has seen the address. False both when it has not and when this
     * source does not carry the feed; `residential === null` is the second.
     */
    public function hasResidential(): bool
    {
        return $this->residential !== null && !$this->residential->isEmpty();
    }

    /**
     * Whether the source answered every flag and the residential feed - only Insights does. When
     * this is true and isFlagged() is false, the address was checked and is clean.
     */
    public function isComplete(): bool
    {
        return $this->isAnonymous !== null
            && $this->isAnonymousVpn !== null
            && $this->isHostingProvider !== null
            && $this->isPublicProxy !== null
            && $this->isResidentialProxy !== null
            && $this->isTorExitNode !== null
            && $this->residential !== null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function read(array $row, string $confidenceKey, ?ResidentialAnonymizer $residential): self
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
