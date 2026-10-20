<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `traits` - about the address and its network rather than where it is.
 *
 * WHICH SERVICE FILLS WHAT:
 *
 * - every answer: `ipAddress`, `network`, `isAnycast`;
 * - City Plus, Insights and the Enterprise database add the network owner - `autonomousSystem*`,
 *   `isp`, `organization`, `domain`, `connectionType`, `mobile*`;
 * - Insights alone adds `ipRiskSnapshot`, `staticIpScore`, `userCount` and `userType`.
 *
 * `network` is the CIDR block MaxMind's answer covers, which is the right key for a cache: every
 * address in it gets the same answer. From a database file it is computed from the prefix length
 * the lookup returned.
 *
 * THE `is_*` ANONYMITY FLAGS HERE ARE DEPRECATED. MaxMind still returns them from Insights, and
 * they are modelled so nothing is lost, but the `anonymizer` section is what to read - it has the
 * same flags, plus confidence, a provider and when the network was last seen.
 */
final class Traits extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $network = null,
        public readonly bool $isAnycast = false,
        public readonly ?int $autonomousSystemNumber = null,
        public readonly ?string $autonomousSystemOrganization = null,
        public readonly ?string $connectionType = null,
        public readonly ?string $domain = null,
        public readonly ?string $isp = null,
        public readonly ?string $organization = null,
        public readonly ?string $mobileCountryCode = null,
        public readonly ?string $mobileNetworkCode = null,
        public readonly ?float $ipRiskSnapshot = null,
        public readonly ?float $staticIpScore = null,
        public readonly ?int $userCount = null,
        public readonly ?string $userType = null,
        /** @deprecated read Anonymizer::$isAnonymous */
        public readonly bool $isAnonymous = false,
        /** @deprecated read Anonymizer::$isAnonymousVpn */
        public readonly bool $isAnonymousVpn = false,
        /** @deprecated read Anonymizer::$isHostingProvider */
        public readonly bool $isHostingProvider = false,
        /** @deprecated read Anonymizer::$isPublicProxy */
        public readonly bool $isPublicProxy = false,
        /** @deprecated read Anonymizer::$isResidentialProxy */
        public readonly bool $isResidentialProxy = false,
        /** @deprecated read Anonymizer::$isTorExitNode */
        public readonly bool $isTorExitNode = false,
        /** Enterprise database only: a proxy MaxMind believes is a corporate or institutional one */
        public readonly bool $isLegitimateProxy = false,
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
            Cast::string($row['ip_address'] ?? null),
            Cast::string($row['network'] ?? null),
            Cast::flag($row['is_anycast'] ?? null),
            Cast::int($row['autonomous_system_number'] ?? null),
            Cast::string($row['autonomous_system_organization'] ?? null),
            Cast::string($row['connection_type'] ?? null),
            Cast::string($row['domain'] ?? null),
            Cast::string($row['isp'] ?? null),
            Cast::string($row['organization'] ?? null),
            Cast::string($row['mobile_country_code'] ?? null),
            Cast::string($row['mobile_network_code'] ?? null),
            Cast::float($row['ip_risk_snapshot'] ?? null),
            Cast::float($row['static_ip_score'] ?? null),
            Cast::int($row['user_count'] ?? null),
            Cast::string($row['user_type'] ?? null),
            Cast::flag($row['is_anonymous'] ?? null),
            Cast::flag($row['is_anonymous_vpn'] ?? null),
            Cast::flag($row['is_hosting_provider'] ?? null),
            Cast::flag($row['is_public_proxy'] ?? null),
            Cast::flag($row['is_residential_proxy'] ?? null),
            Cast::flag($row['is_tor_exit_node'] ?? null),
            Cast::flag($row['is_legitimate_proxy'] ?? null),
            $row,
        );
    }
}
